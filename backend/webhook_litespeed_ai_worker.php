<?php
/**
 * backend/webhook_litespeed_ai_worker.php
 * Worker CLI acionado pelo webhook quando o SAPI é LiteSpeed sem fastcgi_finish_request.
 * O webhook envia o ACK HTTP, fecha a conexão e dispara este script em background (exec).
 * Este script carrega o pipeline de IA sem depender do ciclo de vida HTTP.
 *
 * Uso: php webhook_litespeed_ai_worker.php /tmp/auvvo_ai_<hex>.json
 */
declare(strict_types=1);

// Segurança: só aceita execução via CLI
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$jobFile = $argv[1] ?? '';
if ($jobFile === '' || !is_readable($jobFile)) {
    fwrite(STDERR, "[Worker] Arquivo de job inválido ou ilegível: {$jobFile}\n");
    exit(1);
}

$raw = file_get_contents($jobFile);
@unlink($jobFile); // Remove imediatamente para não acumular arquivos temporários

if ($raw === false || $raw === '') {
    fwrite(STDERR, "[Worker] Arquivo de job vazio.\n");
    exit(1);
}

$job = json_decode($raw, true);
if (!is_array($job)) {
    fwrite(STDERR, "[Worker] JSON inválido no job.\n");
    exit(1);
}

// Verifica assinatura HMAC (previne execução de jobs forjados)
$sig = $job['_sig'] ?? '';
$exp = (int) ($job['exp'] ?? 0);

require_once __DIR__ . '/db.php'; // APP_BASE_URL, credenciais e auvvo_worker_hmac_secret()

$hmacSecret = auvvo_worker_hmac_secret();
if ($hmacSecret === '') {
    fwrite(STDERR, "[Worker] Chave HMAC interna vazia — abortando.\n");
    exit(1);
}

$payloadForSig = json_encode(
    ['data' => $job['data'] ?? [], 'exp' => $exp],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
$expected = hash_hmac('sha256', (string) $payloadForSig, $hmacSecret);

if (!hash_equals($expected, (string) $sig)) {
    fwrite(STDERR, "[Worker] Assinatura inválida — job rejeitado.\n");
    exit(1);
}

if ($exp < time()) {
    fwrite(STDERR, "[Worker] Job expirado (exp={$exp}).\n");
    exit(1);
}

$data = $job['data'] ?? [];
if (!is_array($data)) {
    fwrite(STDERR, "[Worker] Dados do job inválidos.\n");
    exit(1);
}

// Restaura o trace ID do request original para logs coerentes
$GLOBALS['auvvo_webhook_trace_id'] = (string) ($data['rid'] ?? '');

$agentId            = (int) ($data['agent_id'] ?? 0);
$pendingLogId       = isset($data['pending_log_id']) ? (int) $data['pending_log_id'] : null;
$canonicalJid       = (string) ($data['canonical_jid'] ?? '');
$remoteJid          = (string) ($data['remote_jid'] ?? '');
$peerDigits         = (string) ($data['peer_digits'] ?? '');
$body               = (string) ($data['body'] ?? '');
$instanceLabel      = (string) ($data['evolution_instance_label'] ?? '');
$dedupeKey          = (string) ($data['dedupe_key'] ?? '');

if ($agentId <= 0 || $canonicalJid === '' || $body === '') {
    fwrite(STDERR, "[Worker] Dados insuficientes no job.\n");
    exit(1);
}

// Carrega o agente
$stmt = $pdo->prepare(
    "SELECT id, user_id, agent_type, name, prompt_base, type_config, model, max_tokens, temperature, response_delay,
            audio_enabled, audio_voice, handoff_rules, handoff_enabled, handoff_message, evolution_token, evolution_instance
     FROM agents WHERE id = ? LIMIT 1"
);
$stmt->execute([$agentId]);
$agent = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$agent) {
    fwrite(STDERR, "[Worker] Agente #{$agentId} não encontrado.\n");
    exit(1);
}

require_once __DIR__ . '/whatsapp_connections.inc.php';
$connectionId = (int) ($data['whatsapp_connection_id'] ?? $data['connection_id'] ?? 0);
$agent = auvvo_whatsapp_attach_connection_for_agent($pdo, (int) $agent['user_id'], $agent, $connectionId > 0 ? $connectionId : null);
if (empty($agent['evolution_token'])) {
    fwrite(STDERR, "[Worker] Sem conexão WhatsApp para agente #{$agentId}.\n");
    exit(1);
}

// Carrega settings do usuário
$stmt2 = $pdo->prepare(
    "SELECT openai_key, gemini_key, elevenlabs_key, company_name, company_niche, company_site,
            google_calendar_enabled, google_calendar_calendar_id
     FROM settings WHERE user_id = ?"
);
$stmt2->execute([(int) $agent['user_id']]);
$settings = $stmt2->fetch(PDO::FETCH_ASSOC) ?: [];

// Determina a chave da LLM
$modelStr     = $agent['model'] ?? '';
$isGemini     = strpos($modelStr, 'gemini') === 0;
$isOpenRouter = !$isGemini && (
    $modelStr === 'auvvo-ai'
    || strpos($modelStr, 'openrouter/') === 0
    || strpos($modelStr, '/') !== false  // deepseek/..., nvidia/..., etc.
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
    fwrite(STDERR, "[Worker] Chave de API LLM vazia para agente #{$agentId}.\n");
    // Remove pending log órfão
    if ($pendingLogId) {
        try {
            $pdo->prepare('DELETE FROM conversation_logs WHERE id = ? AND agent_id = ?')
                ->execute([$pendingLogId, $agentId]);
        } catch (PDOException $e) { error_log('[Auvvo] litespeed worker log prune: ' . $e->getMessage()); }
    }
    exit(1);
}

// Remove chave de dedup fingerprint (fp:*) — foi inserida pelo webhook antes do ACK
if ($dedupeKey !== '' && strpos($dedupeKey, 'fp:') === 0) {
    try {
        $pdo->prepare('DELETE FROM webhook_message_dedup WHERE agent_id = ? AND message_id = ?')
            ->execute([$agentId, $dedupeKey]);
    } catch (PDOException $e) { error_log('[Auvvo] litespeed worker dedup prune: ' . $e->getMessage()); }
}

// Carrega dependências do pipeline
require_once __DIR__ . '/MasterPromptBuilder.php';
require_once __DIR__ . '/EvolutionAPI.php';
require_once __DIR__ . '/GoogleCalendar.php';
require_once __DIR__ . '/webhook_ai_pipeline.inc.php';

// Executa o pipeline de IA
auvvo_webhook_run_ai_pipeline(
    $pdo,
    $agent,
    $settings,
    $llmApiKey,
    $canonicalJid,
    $remoteJid,
    $peerDigits,
    $body,
    $pendingLogId,
    $instanceLabel
);
