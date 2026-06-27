<?php
/**
 * backend/webhook_async_worker.php
 *
 * Fallback HTTP quando LiteSpeed não desanexa o script com fastcgi_finish_request:
 * segundo request PHP só para IA + Evolution, com assinatura HMAC (chave interna derivada em `db.php`).
 * Não há fila MySQL — o job via JSON expira em minutos.
 *
 * POST com headers X-Auvvo-Worker: 1 e corpo JSON { _sig, exp, data }.
 */
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SERVER['HTTP_X_AUVVO_WORKER'])) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/db.php';

$hmacSecret = auvvo_worker_hmac_secret();
if ($hmacSecret === '') {
    http_response_code(503);
    exit('Worker misconfiguration');
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    http_response_code(400);
    exit('Bad request');
}

$job = json_decode($raw, true);
if (!is_array($job)) {
    http_response_code(400);
    exit('Invalid JSON');
}

$sig     = (string) ($job['_sig'] ?? '');
$exp     = (int) ($job['exp'] ?? 0);
$jobData = $job['data'] ?? [];

$payloadForSig = json_encode(
    ['data' => $jobData, 'exp' => $exp],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
if ($payloadForSig === false || $exp < time()) {
    http_response_code(403);
    exit('Invalid job');
}

$expected = hash_hmac('sha256', $payloadForSig, $hmacSecret);
if (!hash_equals($expected, $sig)) {
    error_log('[AsyncWorker] Assinatura HMAC inválida.');
    http_response_code(403);
    exit('Invalid signature');
}

$headerSig = (string) ($_SERVER['HTTP_X_AUVVO_SIG'] ?? '');
if ($headerSig !== '' && !hash_equals($sig, $headerSig)) {
    http_response_code(403);
    exit('Header sig mismatch');
}

$GLOBALS['auvvo_worker_start_time'] = time();

http_response_code(202);
header('Content-Type: application/json');
header('Content-Length: 4');
header('Connection: close');
header('X-LiteSpeed-NoAbort: true');
echo 'true';

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

ignore_user_abort(true);
set_time_limit(300);

define('AUVVO_WEBHOOK_SKIP_HTTP_ROUTER', true);
require_once __DIR__ . '/webhook_evolution.php';

$GLOBALS['auvvo_webhook_trace_id'] = (string) ($jobData['rid'] ?? '');
$agentId       = (int) ($jobData['agent_id'] ?? 0);
$pendingLogId  = isset($jobData['pending_log_id']) ? (int) $jobData['pending_log_id'] : null;
$canonicalJid  = (string) ($jobData['canonical_jid'] ?? '');
$remoteJid     = (string) ($jobData['remote_jid'] ?? '');
$peerDigits    = (string) ($jobData['peer_digits'] ?? '');
$body          = (string) ($jobData['body'] ?? '');
$instanceLabel = (string) ($jobData['evolution_instance_label'] ?? '');
$lockPeerRaw   = trim((string) ($jobData['lock_peer'] ?? ''));

if ($agentId <= 0 || $canonicalJid === '' || $body === '') {
    error_log('[AsyncWorker] Job incompleto.');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT id, user_id, agent_type, name, prompt_base, type_config, model, max_tokens, temperature, response_delay,
            audio_enabled, audio_voice, handoff_rules, handoff_enabled, handoff_message, evolution_token, evolution_instance
     FROM agents WHERE id = ? LIMIT 1"
);
$stmt->execute([$agentId]);
$agent = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$agent) {
    error_log('[AsyncWorker] Agente #' . $agentId . ' não encontrado.');
    exit;
}

require_once __DIR__ . '/whatsapp_connections.inc.php';
$connectionId = (int) ($jobData['whatsapp_connection_id'] ?? $jobData['connection_id'] ?? 0);
$agent = auvvo_whatsapp_attach_connection_for_agent($pdo, (int) $agent['user_id'], $agent, $connectionId > 0 ? $connectionId : null);
if (empty($agent['evolution_token'])) {
    error_log('[AsyncWorker] Sem conexão WhatsApp para agente #' . $agentId . '.');
    exit;
}

$stmt2 = $pdo->prepare(
    "SELECT openai_key, gemini_key, elevenlabs_key, company_name, company_niche, company_site,
            google_calendar_enabled, google_calendar_calendar_id
     FROM settings WHERE user_id = ?"
);
$stmt2->execute([(int) $agent['user_id']]);
$settings = $stmt2->fetch(PDO::FETCH_ASSOC) ?: [];

$modelStr     = trim((string) ($agent['model'] ?? ''));
$isGemini     = strpos($modelStr, 'gemini') === 0;
$isOpenRouter = !$isGemini && (
    $modelStr === 'auvvo-ai'
    || strpos($modelStr, 'openrouter/') === 0
    || strpos($modelStr, '/') !== false
);

$geminiUserKey      = trim($settings['gemini_key'] ?? '');
$geminiEnvKey       = (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') ? trim((string) GEMINI_API_KEY) : '';
$effectiveGeminiKey = $geminiUserKey !== '' ? $geminiUserKey : $geminiEnvKey;
$openRouterKey      = (defined('OPENROUTER_API_KEY') && OPENROUTER_API_KEY !== '') ? trim((string) OPENROUTER_API_KEY) : '';

if ($isGemini) {
    $llmApiKey = $effectiveGeminiKey;
} elseif ($isOpenRouter) {
    $llmApiKey = $openRouterKey;
} else {
    $llmApiKey = trim($settings['openai_key'] ?? '');
}

if ($llmApiKey === '') {
    error_log('[AsyncWorker] Chave LLM vazia para agente #' . $agentId . '.');
    if ($pendingLogId) {
        try {
            $pdo->prepare('DELETE FROM conversation_logs WHERE id = ? AND agent_id = ?')
                ->execute([$pendingLogId, $agentId]);
        } catch (PDOException $e) {
        }
    }
    exit;
}

$lkPeer           = $lockPeerRaw !== '' ? $lockPeerRaw : ('h' . substr(md5($remoteJid ?: $canonicalJid), 0, 12));
$evoMysqlLockKey  = 'auvvo_ev_' . $agentId . '_' . $lkPeer;
if (strlen($evoMysqlLockKey) > 64) {
    $evoMysqlLockKey = substr($evoMysqlLockKey, 0, 64);
}

$lkStmt = $pdo->prepare('SELECT GET_LOCK(?, 120)');
$lkStmt->execute([$evoMysqlLockKey]);
$lockGot = $lkStmt->fetchColumn();
if ($lockGot === false || (int) $lockGot !== 1) {
    error_log('[AsyncWorker] GET_LOCK timeout: ' . $evoMysqlLockKey);
    exit;
}

try {
    auvvo_webhook_run_ai_pipeline(
        $pdo,
        $agent,
        $settings,
        $llmApiKey,
        $canonicalJid,
        $remoteJid,
        $peerDigits,
        $body,
        $pendingLogId > 0 ? $pendingLogId : null,
        $instanceLabel
    );
} finally {
    auvvo_evolution_release_lock($pdo, $evoMysqlLockKey);
}
