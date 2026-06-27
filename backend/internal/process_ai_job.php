<?php
/**
 * Processamento interno de jobs da fila — apenas worker Node (HMAC).
 */
declare(strict_types=1);

define('AUVVO_WEBHOOK_SKIP_HTTP_ROUTER', true);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../migrations.php';
require_once __DIR__ . '/../rate_limit.inc.php';
require_once __DIR__ . '/../context_memory.inc.php';
require_once __DIR__ . '/../conversation_events.inc.php';
require_once __DIR__ . '/../MasterPromptBuilder.php';
require_once __DIR__ . '/../EvolutionAPI.php';
require_once __DIR__ . '/../GoogleCalendar.php';
require_once __DIR__ . '/../ai_reply.inc.php';
require_once __DIR__ . '/../whatsapp_connections.inc.php';
require_once __DIR__ . '/../webhook_evolution.php';

header('Content-Type: application/json; charset=utf-8');

function internal_json(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function internal_verify_hmac(string $rawBody): bool
{
    $sig = (string) ($_SERVER['HTTP_X_AUVVO_SIGNATURE'] ?? '');
    $ts  = (string) ($_SERVER['HTTP_X_AUVVO_TIMESTAMP'] ?? '');
    if ($sig === '' || $ts === '' || !ctype_digit($ts)) {
        return false;
    }
    if (abs(time() - (int) $ts) > 300) {
        return false;
    }
    $expected = hash_hmac('sha256', $ts . '.' . $rawBody, auvvo_worker_hmac_secret());

    return hash_equals($expected, $sig);
}

$raw = file_get_contents('php://input') ?: '';
if (!internal_verify_hmac($raw)) {
    error_log('[Auvvo internal] HMAC inválido — confira WORKER_HMAC_SECRET ou DB_*/APP_BASE_URL iguais no PHP e no worker Node');
    internal_json(403, ['ok' => false, 'error' => 'invalid_signature']);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    internal_json(400, ['ok' => false, 'error' => 'invalid_json']);
}

$jobId = (int) ($payload['job_id'] ?? 0);
if ($jobId <= 0) {
    internal_json(400, ['ok' => false, 'error' => 'missing_job_id']);
}

auvvo_run_migrations($pdo);

$stmt = $pdo->prepare('SELECT * FROM auvvo_ai_jobs WHERE id = ? LIMIT 1');
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$job) {
    internal_json(404, ['ok' => false, 'error' => 'job_not_found']);
}

$bodyRaw = (string) ($job['body'] ?? '');
$meta    = json_decode($bodyRaw, true);
if (is_array($meta) && ($meta['action'] ?? '') === 'summarize') {
    internal_process_summarize($pdo, $job, $meta);
}

$agentId = (int) ($job['agent_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM agents WHERE id = ? LIMIT 1');
$stmt->execute([$agentId]);
$agent = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$agent) {
    internal_json(404, ['ok' => false, 'error' => 'agent_not_found']);
}

$connectionId = (int) ($job['whatsapp_connection_id'] ?? 0);
$agent = auvvo_whatsapp_attach_connection_for_agent($pdo, (int) $agent['user_id'], $agent, $connectionId > 0 ? $connectionId : null);
if (empty($agent['evolution_token'])) {
    internal_json(503, ['ok' => false, 'error' => 'no_whatsapp_connection']);
}

require_once __DIR__ . '/crm_automation_motor.inc.php';
require_once __DIR__ . '/crm_flow_agent.inc.php';
require_once __DIR__ . '/auvvo_brain_tools.inc.php';

$userId = (int) $agent['user_id'];
$canonicalJid = (string) ($job['canonical_jid'] ?? '');
$remoteJid = (string) ($job['remote_jid'] ?? '');
$peerDigits = (string) ($job['peer_digits'] ?? '');
$connection = $connectionId > 0 ? auvvo_whatsapp_connection_get($pdo, $userId, $connectionId) : null;
$contactRow = auvvo_brain_contact_for_jid($pdo, $userId, $canonicalJid) ?: [];
$resolved = auvvo_crm_resolve_whatsapp_agent_row($pdo, $userId, $agent, $contactRow, $connection);
if (is_array($resolved)) {
    $agent = $resolved;
}
if (auvvo_automation_should_block_standalone($pdo, $userId, $contactRow, $connection)) {
    $pdo->prepare("UPDATE auvvo_ai_jobs SET status = 'done', last_error = 'flow_blocked', updated_at = NOW() WHERE id = ?")
        ->execute([$jobId]);
    internal_json(200, ['ok' => true, 'skipped' => 'flow_blocked']);
}

$precheck = auvvo_ai_reply_prechecks($pdo, $agentId, $canonicalJid, $remoteJid, $peerDigits);
if (!$precheck['allowed']) {
    if ($precheck['reason'] === 'ia_paused' || $precheck['reason'] === 'anti_bot_loop') {
        $pdo->prepare("UPDATE auvvo_ai_jobs SET status = 'done', last_error = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$precheck['reason'], $jobId]);
        internal_json(200, ['ok' => true, 'skipped' => $precheck['reason']]);
    }
    if (str_starts_with($precheck['reason'], 'rate_') || $precheck['reason'] === 'min_interval') {
        $pdo->prepare(
            "UPDATE auvvo_ai_jobs SET status = 'pending', next_retry_at = DATE_ADD(NOW(), INTERVAL 30 SECOND),
             last_error = ?, updated_at = NOW() WHERE id = ?"
        )->execute(['rate:' . $precheck['reason'], $jobId]);
        internal_json(429, ['ok' => false, 'error' => $precheck['reason'], 'retry' => true]);
    }
}

$stmt = $pdo->prepare(
    'SELECT openai_key, gemini_key, elevenlabs_key, company_name, company_niche, company_site,
            google_calendar_enabled, google_calendar_calendar_id
     FROM settings WHERE user_id = ?'
);
$stmt->execute([(int) $agent['user_id']]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$modelStr = trim((string) ($agent['model'] ?? ''));
if ($modelStr === '') {
    $modelStr = defined('OPENROUTER_DEFAULT_MODEL') ? OPENROUTER_DEFAULT_MODEL : 'openrouter/openai/gpt-4o-mini';
}
$isAuvvoAI = $modelStr === 'auvvo-ai';
// auvvo-ai → DeepSeek V3 se configurado, senao OpenRouter
if ($isAuvvoAI && auvvo_deepseek_configured()) {
    $isDeepSeek = true;
    $isGemini = false;
    $isOpenRouter = false;
    $modelStr = 'deepseek/chat';
} else {
    $isGemini = strpos($modelStr, 'gemini') === 0;
    $isDeepSeek = auvvo_is_deepseek_model($modelStr) !== '';
    $isOpenRouter = !$isGemini && !$isDeepSeek && (
        $isAuvvoAI
        || strpos($modelStr, 'openrouter/') === 0
        || strpos($modelStr, '/') !== false
    );
}
$geminiUserKey = trim($settings['gemini_key'] ?? '');
$geminiEnvKey  = defined('GEMINI_API_KEY') ? trim((string) GEMINI_API_KEY) : '';
$effectiveGeminiKey = $geminiUserKey !== '' ? $geminiUserKey : $geminiEnvKey;
$openRouterPlatformKey = defined('OPENROUTER_API_KEY') ? trim((string) OPENROUTER_API_KEY) : '';
$deepSeekPlatformKey  = auvvo_deepseek_configured() ? trim((string) DEEPSEEK_API_KEY) : '';

if ($isGemini && $effectiveGeminiKey === '') {
    internal_json(503, ['ok' => false, 'error' => 'no_gemini_key']);
}
if ($isDeepSeek && $deepSeekPlatformKey === '') {
    internal_json(503, ['ok' => false, 'error' => 'no_deepseek_key']);
}
if ($isOpenRouter && $openRouterPlatformKey === '') {
    internal_json(503, ['ok' => false, 'error' => 'no_openrouter_key']);
}
if (!$isGemini && !$isOpenRouter && !$isDeepSeek && trim($settings['openai_key'] ?? '') === '') {
    internal_json(503, ['ok' => false, 'error' => 'no_openai_key']);
}

$llmApiKey = $isGemini
    ? $effectiveGeminiKey
    : ($isDeepSeek
        ? $deepSeekPlatformKey
        : ($isOpenRouter
            ? $openRouterPlatformKey
            : ($settings['openai_key'] ?? '')));

$GLOBALS['auvvo_worker_start_time'] = time();
$GLOBALS['auvvo_webhook_trace_id']  = (string) ($job['trace_id'] ?? bin2hex(random_bytes(4)));

try {
    auvvo_run_ai_reply(
        $pdo,
        $agent,
        $settings,
        $llmApiKey,
        $canonicalJid,
        $remoteJid,
        $peerDigits,
        $bodyRaw,
        !empty($job['pending_log_id']) ? (int) $job['pending_log_id'] : null,
        (string) ($job['evolution_instance_label'] ?? '')
    );

    $outPreview = $bodyRaw;
    if (!empty($job['pending_log_id'])) {
        try {
            $stOut = $pdo->prepare(
                'SELECT response_msg FROM conversation_logs WHERE id = ? AND agent_id = ? LIMIT 1'
            );
            $stOut->execute([(int) $job['pending_log_id'], $agentId]);
            $resp = trim((string) ($stOut->fetchColumn() ?: ''));
            if ($resp !== '') {
                $outPreview = $resp;
            }
        } catch (PDOException $e) {
        }
    }

    auvvo_emit_conversation_event($pdo, $userId, $agentId, $canonicalJid, 'message_out', [
        'preview' => mb_substr($outPreview, 0, 80),
    ]);
    auvvo_maybe_schedule_summarization($pdo, $agentId, (string) $job['canonical_jid'], $userId);

    $pdo->prepare("UPDATE auvvo_ai_jobs SET status = 'done', updated_at = NOW() WHERE id = ?")->execute([$jobId]);
    internal_json(200, ['ok' => true]);
} catch (Throwable $e) {
    error_log('[Auvvo internal] job ' . $jobId . ': ' . $e->getMessage());
    internal_json(500, ['ok' => false, 'error' => $e->getMessage(), 'retry' => true]);
}

function internal_process_summarize(PDO $pdo, array $job, array $meta): void
{
    $agentId    = (int) ($job['agent_id'] ?? 0);
    $contactJid = (string) ($meta['contact_jid'] ?? $job['canonical_jid'] ?? '');
    $history    = getConversationHistory($pdo, $agentId, $contactJid, 30);
    $lines      = [];
    foreach ($history as $m) {
        $lines[] = strtoupper((string) ($m['role'] ?? '')) . ': ' . (string) ($m['content'] ?? '');
    }
    if ($lines === []) {
        internal_json(200, ['ok' => true, 'summarize' => 'empty']);
    }

    $stmt = $pdo->prepare('SELECT user_id, model FROM agents WHERE id = ? LIMIT 1');
    $stmt->execute([$agentId]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$agent) {
        internal_json(404, ['ok' => false, 'error' => 'agent_not_found']);
    }

    $stmt = $pdo->prepare('SELECT openai_key, gemini_key FROM settings WHERE user_id = ? LIMIT 1');
    $stmt->execute([(int) $agent['user_id']]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $modelStr = trim((string) ($agent['model'] ?? OPENROUTER_DEFAULT_MODEL));
    $isGemini = strpos($modelStr, 'gemini') === 0;
    $key = $isGemini
        ? (trim($settings['gemini_key'] ?? '') ?: GEMINI_API_KEY)
        : (defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : ($settings['openai_key'] ?? ''));

    $prompt = "Resuma a conversa abaixo em português (máx 400 palavras). Extraia fatos estáveis do cliente (preferências, orçamento, restrições) em JSON no final no formato:\nFATOS_JSON:{\"chave\":\"valor\"}\n\n" . implode("\n", $lines);

    $summary = callOpenAI($key, $modelStr, 'Você resume conversas de atendimento.', $prompt, [], 800, 0.3, 'auvvo-sum-' . $agentId);
    if (!$summary) {
        internal_json(500, ['ok' => false, 'error' => 'summarize_llm_failed']);
    }

    $facts = [];
    if (preg_match('/FATOS_JSON:\s*(\{.*\})/s', $summary, $m)) {
        $decoded = json_decode($m[1], true);
        if (is_array($decoded)) {
            $facts = $decoded;
        }
        $summary = trim(preg_replace('/FATOS_JSON:\s*\{.*\}/s', '', $summary));
    }

    $pdo->prepare(
        'INSERT INTO conversation_summaries (agent_id, contact_jid, summary_text, turn_count)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE summary_text = VALUES(summary_text), turn_count = VALUES(turn_count), updated_at = NOW()'
    )->execute([$agentId, $contactJid, $summary, count($history)]);

    if ($facts !== []) {
        auvvo_contact_memory_merge($pdo, (int) $agent['user_id'], $contactJid, $facts);
    }

    $pdo->prepare("UPDATE auvvo_ai_jobs SET status = 'done', updated_at = NOW() WHERE id = ?")
        ->execute([(int) $job['id']]);

    internal_json(200, ['ok' => true, 'summarize' => true]);
}
