<?php
declare(strict_types=1);

/**
 * Motor de webhooks: templates, variáveis, chamadas outbound e logs.
 */
function auvvo_json_path_get($data, string $path): string
{
    if (!is_array($data) || $path === '') {
        return '';
    }
    $parts = explode('.', $path);
    $cur   = $data;
    foreach ($parts as $p) {
        if (!is_array($cur) || !array_key_exists($p, $cur)) {
            return '';
        }
        $cur = $cur[$p];
    }

    return is_scalar($cur) ? trim((string) $cur) : (is_array($cur) ? json_encode($cur, JSON_UNESCAPED_UNICODE) : '');
}

function auvvo_render_template(string $template, array $vars): string
{
    return preg_replace_callback('/\{\{([a-zA-Z0-9_.]+)\}\}/', static function ($m) use ($vars) {
        $key = $m[1];
        if (array_key_exists($key, $vars)) {
            return (string) $vars[$key];
        }
        $parts = explode('.', $key);
        $cur   = $vars;
        foreach ($parts as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) {
                return '';
            }
            $cur = $cur[$p];
        }

        return is_scalar($cur) ? (string) $cur : '';
    }, $template);
}

function auvvo_webhook_log_call(
    PDO $pdo,
    int $userId,
    string $kind,
    int $webhookId,
    ?int $httpStatus,
    $request,
    $response,
    string $status = 'ok'
): void {
    auvvo_run_migrations($pdo);
    $req = is_string($request) ? $request : json_encode($request, JSON_UNESCAPED_UNICODE);
    $res = is_string($response) ? $response : json_encode($response, JSON_UNESCAPED_UNICODE);
    try {
        $pdo->prepare(
            'INSERT INTO webhook_call_logs (user_id, webhook_kind, webhook_id, http_status, request_json, response_json, status)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$userId, $kind, $webhookId, $httpStatus, $req, $res, $status]);
    } catch (PDOException $e) {
        error_log('[Auvvo] webhook_call_logs: ' . $e->getMessage());
    }
}

function auvvo_webhook_store_variables(
    PDO $pdo,
    int $userId,
    string $kind,
    int $webhookId,
    array $maps,
    array $sourceData
): void {
    if ($maps === []) {
        return;
    }
    auvvo_run_migrations($pdo);
    $upsert = $pdo->prepare(
        'INSERT INTO webhook_stored_variables (user_id, webhook_kind, webhook_id, var_key, var_value)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE var_value = VALUES(var_value), updated_at = NOW()'
    );
    foreach ($maps as $m) {
        $varKey = trim((string) ($m['var_key'] ?? $m['key'] ?? ''));
        $path   = trim((string) ($m['json_path'] ?? $m['path'] ?? ''));
        if ($varKey === '' || $path === '') {
            continue;
        }
        $val = auvvo_json_path_get($sourceData, $path);
        $upsert->execute([$userId, $kind, $webhookId, $varKey, $val]);
    }
}

function auvvo_webhook_get_variables(PDO $pdo, int $userId, string $kind, int $webhookId): array
{
    auvvo_run_migrations($pdo);
    try {
        $stmt = $pdo->prepare(
            'SELECT var_key, var_value, updated_at FROM webhook_stored_variables
             WHERE user_id = ? AND webhook_kind = ? AND webhook_id = ? ORDER BY var_key'
        );
        $stmt->execute([$userId, $kind, $webhookId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Dispara webhook outbound (automação ou agente).
 *
 * @return array{ok:bool, http_status?:int, response?:mixed, error?:string}
 */
function auvvo_webhook_call_outbound(
    PDO $pdo,
    int $userId,
    int $webhookId,
    array $context = []
): array {
    auvvo_run_migrations($pdo);
    $stmt = $pdo->prepare('SELECT * FROM outbound_webhooks WHERE id = ? AND user_id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$webhookId, $userId]);
    $hook = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hook) {
        return ['ok' => false, 'error' => 'webhook_not_found'];
    }

    $bodyTpl = (string) ($hook['body_template'] ?? '{}');
    $bodyStr = auvvo_render_template($bodyTpl, $context);
    $body    = json_decode($bodyStr, true);
    if (!is_array($body)) {
        $body = ['payload' => $context];
    }

    $headers = ['Content-Type: application/json'];
    $hdrJson = json_decode((string) ($hook['headers_json'] ?? '{}'), true);
    if (is_array($hdrJson)) {
        foreach ($hdrJson as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $headers[] = $k . ': ' . $v;
            }
        }
    }

require_once __DIR__ . '/http_ssrf.inc.php';

    $method = strtoupper((string) ($hook['http_method'] ?? 'POST'));
    $url    = (string) $hook['target_url'];
    $urlCheck = auvvo_http_url_validate($url);
    if (!$urlCheck['ok']) {
        return ['ok' => false, 'error' => 'url_blocked', 'detail' => $urlCheck['error'] ?? 'invalid'];
    }
    $url = (string) $urlCheck['url'];
    $ch     = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $respBody = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $respData = [];
    if (is_string($respBody) && $respBody !== '') {
        $decoded = json_decode($respBody, true);
        $respData = is_array($decoded) ? $decoded : ['raw' => $respBody];
    }

    $status = ($httpCode >= 200 && $httpCode < 300) ? 'ok' : 'error';
    auvvo_webhook_log_call($pdo, $userId, 'outbound', $webhookId, $httpCode, $body, $respData, $status);

    $varMaps = json_decode((string) ($hook['response_var_maps'] ?? '[]'), true);
    if (is_array($varMaps) && $varMaps !== []) {
        auvvo_webhook_store_variables($pdo, $userId, 'outbound', $webhookId, $varMaps, $respData);
    }

    if ($curlErr !== '') {
        return ['ok' => false, 'error' => $curlErr, 'http_status' => $httpCode, 'response' => $respData];
    }

    return ['ok' => $httpCode >= 200 && $httpCode < 300, 'http_status' => $httpCode, 'response' => $respData];
}

function auvvo_inbound_default_response_template(): string
{
    return '{"ok":true,"contact_id":"{{contact.id}}","message":"Lead recebido"}';
}
