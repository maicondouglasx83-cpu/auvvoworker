<?php
declare(strict_types=1);

require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/webhook_engine.inc.php';

/**
 * Cria webhook inbound com fallback se migração parcial no servidor.
 *
 * @return array{ok:bool, id?:int, url?:string, secret_token?:string, message?:string}
 */
function auvvo_inbound_webhook_create(PDO $pdo, int $userId, array $data): array
{
    auvvo_run_migrations($pdo);

    $name = trim((string) ($data['name'] ?? 'Integração'));
    if ($name === '') {
        $name = 'Integração';
    }

    $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string) ($data['url_slug'] ?? ''))));
    if ($slug === '') {
        $slug = 'wh' . bin2hex(random_bytes(4));
    }

    $token = bin2hex(random_bytes(16));
    $responseTpl = trim((string) ($data['response_template'] ?? ''));
    if ($responseTpl === '') {
        $responseTpl = auvvo_inbound_default_response_template();
    }
    $defaultAgent = (int) ($data['default_agent_id'] ?? 0);
    $varMaps = $data['variable_maps'] ?? '[]';
    if (is_array($varMaps)) {
        $varMaps = json_encode($varMaps, JSON_UNESCAPED_UNICODE);
    }
    $phonePrefix = preg_replace('/\D/', '', (string) ($data['phone_country_prefix'] ?? '55'));
    if ($phonePrefix === '') {
        $phonePrefix = '55';
    }

    $chk = $pdo->prepare('SELECT id FROM inbound_webhooks WHERE url_slug = ? LIMIT 1');
    $chk->execute([$slug]);
    if ($chk->fetchColumn()) {
        return ['ok' => false, 'message' => 'Este slug já está em uso. Escolha outro identificador na URL.'];
    }

    $wid = 0;
    $errMsg = '';

    try {
        $pdo->prepare(
            'INSERT INTO inbound_webhooks (user_id, name, secret_token, url_slug, response_template, default_agent_id, variable_maps, phone_country_prefix)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            $userId,
            $name,
            $token,
            $slug,
            $responseTpl,
            $defaultAgent > 0 ? $defaultAgent : null,
            $varMaps,
            $phonePrefix,
        ]);
        $wid = (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        $errMsg = $e->getMessage();
        try {
            $pdo->prepare(
                'INSERT INTO inbound_webhooks (user_id, name, secret_token, url_slug) VALUES (?,?,?,?)'
            )->execute([$userId, $name, $token, $slug]);
            $wid = (int) $pdo->lastInsertId();
            if ($wid > 0 && auvvo_migration_column_exists($pdo, 'inbound_webhooks', 'response_template')) {
                try {
                    $pdo->prepare(
                        'UPDATE inbound_webhooks SET response_template=?, phone_country_prefix=? WHERE id=?'
                    )->execute([$responseTpl, $phonePrefix, $wid]);
                } catch (PDOException $e3) {
                }
            }
        } catch (PDOException $e2) {
            return ['ok' => false, 'message' => 'Não foi possível criar o webhook. Rode as migrações ou contate o suporte.'];
        }
    }

    if ($wid <= 0) {
        $st = $pdo->prepare('SELECT id FROM inbound_webhooks WHERE user_id = ? AND url_slug = ? LIMIT 1');
        $st->execute([$userId, $slug]);
        $wid = (int) ($st->fetchColumn() ?: 0);
    }

    if ($wid <= 0) {
        return ['ok' => false, 'message' => 'Webhook não foi gravado no banco.' . (defined('IS_DEV') && IS_DEV ? ' ' . $errMsg : '')];
    }

    $maps = $data['field_maps'] ?? [];
    if (is_string($maps)) {
        $maps = json_decode($maps, true);
    }
    if (is_array($maps)) {
        auvvo_inbound_webhook_save_maps($pdo, $userId, $wid, $maps);
    }

    return [
        'ok'           => true,
        'id'           => $wid,
        'url'          => app_http_url('backend/webhook_inbound.php?slug=' . $slug),
        'secret_token' => $token,
        'url_slug'     => $slug,
    ];
}

function auvvo_inbound_webhook_save_maps(PDO $pdo, int $userId, int $webhookId, array $maps): void
{
    $own = $pdo->prepare('SELECT id FROM inbound_webhooks WHERE id = ? AND user_id = ? LIMIT 1');
    $own->execute([$webhookId, $userId]);
    if (!$own->fetchColumn()) {
        return;
    }
    $pdo->prepare('DELETE FROM inbound_webhook_field_maps WHERE webhook_id = ?')->execute([$webhookId]);
    $ins = $pdo->prepare('INSERT INTO inbound_webhook_field_maps (webhook_id, json_path, crm_field) VALUES (?,?,?)');
    foreach ($maps as $m) {
        $path = trim((string) ($m['json_path'] ?? ''));
        $field = trim((string) ($m['crm_field'] ?? ''));
        if ($path !== '' && $field !== '') {
            $ins->execute([$webhookId, $path, $field]);
        }
    }
}

function auvvo_inbound_webhook_get_detail(PDO $pdo, int $userId, int $webhookId): ?array
{
    auvvo_run_migrations($pdo);
    $stmt = $pdo->prepare('SELECT * FROM inbound_webhooks WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$webhookId, $userId]);
    $hook = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hook) {
        return null;
    }
    $hook['url'] = app_http_url('backend/webhook_inbound.php?slug=' . $hook['url_slug']);

    $m = $pdo->prepare('SELECT json_path, crm_field FROM inbound_webhook_field_maps WHERE webhook_id = ?');
    $m->execute([$webhookId]);
    $hook['field_maps'] = $m->fetchAll(PDO::FETCH_ASSOC);

    $hook['last_payload'] = null;
    try {
        $lg = $pdo->prepare(
            'SELECT payload_json FROM inbound_webhook_log WHERE webhook_id = ? AND payload_json IS NOT NULL ORDER BY id DESC LIMIT 1'
        );
        $lg->execute([$webhookId]);
        $raw = $lg->fetchColumn();
        if ($raw) {
            $hook['last_payload'] = json_decode((string) $raw, true);
        }
    } catch (PDOException $e) {
    }

    if ($hook['last_payload'] === null && !empty($hook['sample_payload'])) {
        $hook['last_payload'] = json_decode((string) $hook['sample_payload'], true);
    }

    return $hook;
}

/**
 * Achata JSON para mapeamento visual { path, label, sample }.
 */
function auvvo_flatten_json_paths($data, string $prefix = ''): array
{
    $out = [];
    if (!is_array($data)) {
        if ($prefix !== '') {
            $out[] = ['path' => $prefix, 'sample' => is_scalar($data) ? (string) $data : json_encode($data)];
        }

        return $out;
    }
    foreach ($data as $k => $v) {
        $path = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v) && auvvo_is_assoc_array($v)) {
            $out = array_merge($out, auvvo_flatten_json_paths($v, $path));
        } else {
            $sample = is_scalar($v) ? (string) $v : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : '');
            if (strlen($sample) > 120) {
                $sample = substr($sample, 0, 120) . '…';
            }
            $out[] = ['path' => $path, 'sample' => $sample];
        }
    }

    return $out;
}

function auvvo_is_assoc_array(array $arr): bool
{
    if ($arr === []) {
        return false;
    }

    return array_keys($arr) !== range(0, count($arr) - 1);
}
