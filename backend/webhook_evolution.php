<?php
/**
 * backend/webhook_evolution.php
 * Receptor de eventos do Evolution Go (WhatsApp — Go/whatsmeow).
 * Doc Evolution: https://docs.evolutionfoundation.com.br/evolution-go
 * OpenRouter (Chat API): https://openrouter.ai/docs/api/reference/overview
 * OpenAI: https://developers.openai.com/api/reference/overview
 * Gemini REST: https://ai.google.dev/api
 *
 * Configure no connectInstance() como webhookUrl.
 * URL: https://seu-dominio.com/agentes/backend/webhook_evolution.php
 */
require_once 'db.php';
require_once 'MasterPromptBuilder.php';
require_once 'EvolutionAPI.php';
require_once 'GoogleCalendar.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/ai_queue.inc.php';
require_once __DIR__ . '/conversation_history.inc.php';
require_once __DIR__ . '/ai_reply.inc.php';

$auvvoWebhookRunRouter = !defined('AUVVO_WEBHOOK_SKIP_HTTP_ROUTER') || !AUVVO_WEBHOOK_SKIP_HTTP_ROUTER;

if ($auvvoWebhookRunRouter) {
    global $pdo;
    if ($pdo instanceof PDO) {
        auvvo_run_migrations($pdo);
    }
}

// Autenticação do webhook Evolution
$webhookSecret = trim((string) ($_ENV['WEBHOOK_SECRET'] ?? ''));
if ($auvvoWebhookRunRouter) {
    if (!IS_DEV && $webhookSecret === '') {
        error_log('[Evolution Webhook] WEBHOOK_SECRET obrigatório em produção.');
        http_response_code(503);
        echo json_encode(['error' => 'webhook_secret_not_configured']);
        exit;
    }
    if ($webhookSecret !== '') {
        $providedSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET']
            ?? $_SERVER['HTTP_X_EVOLUTION_WEBHOOK_SECRET']
            ?? $_GET['secret']
            ?? null;
        if ($providedSecret === null || !hash_equals($webhookSecret, (string) $providedSecret)) {
            http_response_code(403);
            echo json_encode(['error' => 'unauthorized', 'message' => 'Invalid webhook secret']);
            exit;
        }
    }
}

if ($auvvoWebhookRunRouter) {
// Lê payload bruto
$raw   = file_get_contents('php://input');
$event = json_decode($raw, true);

// === DEBUG LOGGING (somente em DEV; com rotação simples) ===
if (defined('IS_DEV') && IS_DEV) {
    $logFile = __DIR__ . '/webhook_debug.log';
    if (file_exists($logFile) && filesize($logFile) > (5 * 1024 * 1024)) { // 5MB
        @rename($logFile, __DIR__ . '/webhook_debug_' . date('Ymd_His') . '.log');
    }
    file_put_contents($logFile, date('Y-m-d H:i:s') . " PAYLOAD:\n" . $raw . "\n\n", FILE_APPEND);
}

if (!$event) { http_response_code(400); echo '{"error":"invalid payload"}'; exit; }

// Evolution Go — ver Webhooks: https://docs.evolutionfoundation.com.br/evolution-go/webhooks
// Payload base: event, data, instanceId (UUID), instanceToken (bate com whatsapp_connections.evolution_token).
// Webhook global (WEBHOOK_URL no servidor) + webhook da instância na mesma URL = cada evento pode ser POST 2x.
$event_type = strtoupper(str_replace(['.', '-'], '_', (string)($event['event'] ?? '')));
$data       = $event['data'] ?? [];
$GLOBALS['auvvo_webhook_body_sent'] = false;
/** @var string Token da instância no corpo do webhook (forma recomendada de amarrar ao agente). */
$webhook_instance_token = trim((string)($event['instanceToken'] ?? ''));
/** @var string Nome salvo no Evolution, UUID instanceId ou vazio. */
$instance_slug = trim((string)($event['instanceName'] ?? $event['instance'] ?? $event['instanceId'] ?? ''));
} // $auvvoWebhookRunRouter (payload Evolution)

/** Extrai texto legível de payloads WhatsApp (texto, legenda, botões). */
function auvvo_evolution_extract_message_body(array $data): string
{
    $msg = $data['Message'] ?? [];
    if (!is_array($msg)) {
        return '';
    }
    $candidates = [
        $msg['conversation'] ?? null,
        $msg['extendedTextMessage']['text'] ?? null,
        $msg['imageMessage']['caption'] ?? null,
        $msg['videoMessage']['caption'] ?? null,
        $msg['documentMessage']['caption'] ?? null,
        $msg['buttonsResponseMessage']['selectedDisplayText'] ?? null,
        $msg['buttonsResponseMessage']['selectedButtonId'] ?? null,
        $msg['listResponseMessage']['title'] ?? null,
        $msg['listResponseMessage']['singleSelectReply']['selectedRowId'] ?? null,
        $msg['templateButtonReplyMessage']['selectedDisplayText'] ?? null,
    ];
    foreach ($candidates as $c) {
        $t = trim((string) $c);
        if ($t !== '') {
            return $t;
        }
    }

    return '';
}

/**
 * ID estável para deduplicação de webhooks (Evolution às vezes omite Info.ID).
 */
function evolutionInboundDedupeKey(int $agentId, array $data, string $remoteJid, string $body, string $lockPeer): string {
    $info = $data['Info'] ?? [];
    foreach (['ID', 'Id', 'id'] as $ik) {
        $v = trim((string)($info[$ik] ?? ''));
        if ($v !== '') {
            return mb_substr($v, 0, 120);
        }
    }
    foreach (['key', 'Key'] as $kb) {
        $keyBlock = $data[$kb] ?? [];
        if (!is_array($keyBlock)) {
            continue;
        }
        foreach (['ID', 'Id', 'id'] as $ik) {
            $v = trim((string)($keyBlock[$ik] ?? ''));
            if ($v !== '') {
                return mb_substr($v, 0, 120);
            }
        }
    }
    $info = $data['Info'] ?? [];
    $ts = '';
    if (is_array($info)) {
        foreach (['Timestamp', 'timestamp', 'ServerTimestamp', 'serverTimestamp'] as $tk) {
            if (isset($info[$tk]) && (string) $info[$tk] !== '') {
                $ts = (string) $info[$tk];
                break;
            }
        }
    }
    // Sem ID no payload: agente+peer+texto+timestamp (quando existir) evita fundir dois textos iguais seguidos.
    $bodyNorm = sha1(mb_strtolower(preg_replace('/\s+/u', ' ', trim($body))));
    $fpInput  = $agentId . "\x1e" . $lockPeer . "\x1e" . $bodyNorm . ($ts !== '' ? "\x1e" . $ts : '');
    return 'fp:' . substr(hash('sha256', $fpInput), 0, 56);
}

// peer / canonical: ver backend/db.php (auvvo_whatsapp_peer_digits) — db.php é carregado antes deste arquivo.

/**
 * JIDs candidatos no payload (Chat pode vir como @lid enquanto Sender/key trazem o PN).
 */
/**
 * JIDs candidatos — ordem importa no empate: Sender/SenderAlt e key antes de Chat
 * (o Chat às vezes vem como um PN “fantasma” maior, ex.: 364…@s.whatsapp.net, enquanto o real é 5541… no Sender).
 */
function auvvo_evolution_collect_jid_candidates(array $data, string $chatJid): array {
    $out = [];
    $add = function ($v) use (&$out): void {
        $v = trim((string) $v);
        if ($v !== '') {
            $out[] = $v;
        }
    };
    $info = $data['Info'] ?? [];
    if (is_array($info)) {
        foreach (['Sender', 'SenderAlt'] as $k) {
            if (!empty($info[$k])) {
                $add($info[$k]);
            }
        }
    }
    foreach (['key', 'Key'] as $kb) {
        $keyBlock = $data[$kb] ?? [];
        if (!is_array($keyBlock)) {
            continue;
        }
        foreach (['remoteJid', 'RemoteJid', 'participant', 'Participant'] as $k) {
            if (!empty($keyBlock[$k])) {
                $add($keyBlock[$k]);
            }
        }
    }
    if (is_array($info)) {
        foreach (['Chat', 'Recipient', 'RemoteJid'] as $k) {
            if (!empty($info[$k])) {
                $add($info[$k]);
            }
        }
    }
    $add($chatJid);
    return array_values(array_unique($out));
}

/** Prioriza mobile BR (55 + DDD + número). Evita perder 554195949694 para um “364…” mais longo no Chat. */
function auvvo_br_peer_candidate_score(string $digits): int {
    if ($digits === '') {
        return 0;
    }
    if (preg_match('/^55\d{10,11}$/', $digits)) {
        return 2000 + strlen($digits);
    }
    return strlen($digits);
}

function auvvo_evolution_resolve_peer_digits(array $data, string $chatJid): string {
    $best = '';
    $bestScore = -1;
    $bestIdx = PHP_INT_MAX;
    $i = 0;
    foreach (auvvo_evolution_collect_jid_candidates($data, $chatJid) as $cand) {
        $d = auvvo_whatsapp_peer_digits($cand);
        if ($d !== '') {
            $sc = auvvo_br_peer_candidate_score($d);
            if ($sc > $bestScore || ($sc === $bestScore && $i < $bestIdx)) {
                $bestScore = $sc;
                $best = $d;
                $bestIdx = $i;
            }
        }
        $i++;
    }
    return $best;
}

function auvvo_normalize_inbound_body(string $s): string {
    return mb_substr(preg_replace('/\s+/u', ' ', trim($s)), 0, 12000);
}

/**
 * Eco do assistente tratado como mensagem do cliente (IsFromMe errado ou relay).
 */
function auvvo_inbound_echoes_last_reply(PDO $pdo, int $agentId, string $canonicalJid, string $remoteJid, string $peerDigits, string $body): bool {
    $conds = ['contact_jid = ?', 'contact_jid = ?'];
    $params = [$canonicalJid, $remoteJid];
    if ($peerDigits !== '') {
        $conds[] = 'contact_jid = ?';
        $conds[] = 'contact_jid = ?';
        $params[] = $peerDigits . '@s.whatsapp.net';
        $params[] = $peerDigits . '@c.us';
    }
    $whereContact = implode(' OR ', $conds);
    try {
        $sql = "SELECT response_msg FROM conversation_logs
                WHERE agent_id = ? AND type IN ('ai','handoff') AND ($whereContact)
                ORDER BY id DESC LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$agentId], $params));
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }
    if (!$row || empty($row['response_msg'])) {
        return false;
    }
    $a = auvvo_normalize_inbound_body($body);
    $b = auvvo_normalize_inbound_body((string) $row['response_msg']);
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    if (mb_strlen($a) > 40 && mb_stripos($b, $a) !== false) {
        return true;
    }
    if (mb_strlen($a) > 80) {
        similar_text($a, $b, $pct);
        if ($pct > 90.0) {
            return true;
        }
    }
    return false;
}

function auvvo_evolution_release_lock(PDO $pdo, string $lockKey): void {
    try {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockKey]);
    } catch (PDOException $e) {
        error_log('[Evolution] RELEASE_LOCK: ' . $e->getMessage());
    }
}

/**
 * Reserva processamento único por mensagem inbound (Evolution pode enviar o mesmo evento 2x).
 * Retorna a chave dedupe ou null se já foi processada.
 */
function auvvo_webhook_claim_inbound(
    PDO $pdo,
    int $agentId,
    array $data,
    string $remoteJid,
    string $body,
    string $lockPeer
): ?string {
    if ($agentId <= 0) {
        return null;
    }
    $dedupeKey = evolutionInboundDedupeKey($agentId, $data, $remoteJid, $body, $lockPeer);
    try {
        $pdo->prepare(
            'INSERT INTO webhook_message_dedup (agent_id, message_id) VALUES (?, ?)'
        )->execute([$agentId, $dedupeKey]);
    } catch (PDOException $e) {
        return null;
    }
    if (random_int(1, 200) === 1) {
        try {
            $pdo->prepare(
                'DELETE FROM webhook_message_dedup WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
            )->execute();
        } catch (PDOException $e) {
        }
    }

    return $dedupeKey;
}

/**
 * Log em arquivo JSONL (uma linha por evento) — habilite WEBHOOK_TRACE_LOG=1 no .env ou APP_ENV=development.
 * Arquivo: backend/webhook_trace.log (já coberto por *.log no .gitignore)
 */
function auvvo_webhook_tracelog(string $phase, array $fields = []): void {
    if (!defined('WEBHOOK_TRACE_LOG') || (!WEBHOOK_TRACE_LOG && (!defined('IS_DEV') || !IS_DEV))) {
        return;
    }
    $logFile = __DIR__ . '/webhook_trace.log';
    if (file_exists($logFile) && filesize($logFile) > (2 * 1024 * 1024)) {
        @rename($logFile, __DIR__ . '/webhook_trace_' . date('Ymd_His') . '.log');
    }
    $rid = (string) ($GLOBALS['auvvo_webhook_trace_id'] ?? '');
    $row = array_merge(
        ['ts' => date('c'), 'phase' => $phase, 'rid' => $rid],
        $fields
    );
    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        @file_put_contents($logFile, $json . "\n", FILE_APPEND | LOCK_EX);
    }
}

/**
 * Fila opcional (requer auvvo-worker). Padrão: inline — IA no mesmo request.
 * Defina WEBHOOK_AI_MODE=queue apenas quando o worker Node estiver ativo.
 */
function auvvo_webhook_ai_use_queue(): bool {
    $m = strtolower(trim((string) ($_ENV['WEBHOOK_AI_MODE'] ?? 'inline')));

    return in_array($m, ['queue', 'mysql', 'cron'], true);
}

// IA inbound: ver WEBHOOK_AI_MODE (.env) — inline (padrão) ou queue.

if ($auvvoWebhookRunRouter) {
// ============================================================
// Inscrição no Evolution (connectInstance subscribe):
// a URL recebe vários eventos (Message, Connected, QRCode, LoggedOut, …).
// Só aqui, para MESSAGE com texto de cliente (IsFromMe = false), rodamos CRM + IA.
// Histórico para o modelo = sempre SQL (conversation_logs), não o chat do WhatsApp.
// ============================================================
// MENSAGEM RECEBIDA (Evolution Go: event = "MESSAGE")
// ============================================================
if ($event_type === 'MESSAGE' && !($data['Info']['IsFromMe'] ?? false)) {
    $GLOBALS['auvvo_webhook_trace_id'] = bin2hex(random_bytes(4));

    // Evolution Go: remoteJid está em data.Info.Chat (resposta deve ir para este JID).
    // PN estável: muitas vezes em Sender ou key enquanto Chat vem como @lid — sem isso, 2 webhooks = 2 locks = 2 respostas.
    $remote_jid = $data['Info']['Chat'] ?? '';
    $is_group_chat = str_contains($remote_jid, '@g.us');
    if (!$is_group_chat) {
        $peer_digits = auvvo_evolution_resolve_peer_digits($data, $remote_jid);
    } else {
        $peer_digits = auvvo_whatsapp_peer_digits($remote_jid);
    }
    if ($peer_digits !== '') {
        $canonical_jid = $peer_digits . '@s.whatsapp.net';
    } else {
        $canonical_jid = auvvo_canonical_whatsapp_jid($remote_jid);
        $peer_digits = auvvo_whatsapp_peer_digits($canonical_jid);
    }
    $lock_peer = $peer_digits !== '' ? $peer_digits : ('h' . substr(md5($canonical_jid !== '' ? $canonical_jid : $remote_jid), 0, 12));

    // Extrair texto da mensagem (Evolution Go usa data.Message)
    $body = auvvo_evolution_extract_message_body($data);

    if (empty($body) || ($webhook_instance_token === '' && $instance_slug === '')) {
        auvvo_webhook_tracelog('skip', [
            'reason' => empty($body) ? 'empty_body' : 'no_instance_token',
        ]);
        http_response_code(200); echo '{"ok":true}'; exit;
    }

    require_once __DIR__ . '/whatsapp_connections.inc.php';
    $connection = auvvo_whatsapp_resolve_connection_from_webhook($pdo, $webhook_instance_token, $instance_slug);

    if (!$connection || empty($connection['evolution_token'])) {
        auvvo_webhook_tracelog('skip', ['reason' => 'connection_or_token_missing']);
        http_response_code(200); echo '{"ok":true,"info":"connection not found or no token"}'; exit;
    }

    $user_id_conn = (int) ($connection['user_id'] ?? 0);
    $evolution_instance_label = (string) ($connection['evolution_instance'] ?? $instance_slug);
    $connection_id = (int) ($connection['id'] ?? 0);

    $routingAgentId = (int) ($connection['default_agent_id'] ?? 0);
    if ($routingAgentId <= 0 && !empty($connection['_legacy_agent_row'])) {
        $routingAgentId = (int) $connection['id'];
    }
    if ($routingAgentId <= 0) {
        $routingAgentId = auvvo_whatsapp_resolve_routing_agent_id($pdo, $user_id_conn, $connection_id, $connection);
    }
    $agent = $routingAgentId > 0
        ? auvvo_whatsapp_load_agent_brain($pdo, $user_id_conn, $routingAgentId)
        : null;
    if (!$agent) {
        auvvo_webhook_tracelog('skip', ['reason' => 'no_brain_agent', 'connection_id' => $connection_id]);
        http_response_code(200); echo '{"ok":true,"info":"no agent configured for this connection — defina agente padrão em Conexões ou publique um fluxo com agente"}'; exit;
    }

    $agent = auvvo_whatsapp_attach_connection_to_agent($agent, $connection);

    // Grupos WhatsApp (@g.us) — não criar CRM, não responder com IA
    if ($is_group_chat) {
        auvvo_webhook_tracelog('skip', ['reason' => 'group_chat', 'remote_jid' => $remote_jid]);
        http_response_code(200);
        echo '{"ok":true,"info":"group chat ignored"}';
        exit;
    }

    auvvo_webhook_tracelog('inbound', [
        'agent_id'       => (int) $agent['id'],
        'remote_jid'     => $remote_jid,
        'canonical_jid'  => $canonical_jid,
        'peer_digits'    => $peer_digits,
        'body_preview'   => mb_substr($body, 0, 120),
        'instance'       => $evolution_instance_label,
    ]);

    if (auvvo_inbound_echoes_last_reply($pdo, (int) $agent['id'], $canonical_jid, $remote_jid, $peer_digits, $body)) {
        auvvo_webhook_tracelog('exit', ['reason' => 'echo_suppressed_early']);
        http_response_code(200);
        echo json_encode(['ok' => true, 'info' => 'echo inbound suppressed']);
        exit;
    }

    // Lock por par agente+peer ANTES de CRM/fluxo/IA — evita corrida (fluxo + agente livre no mesmo inbound).
    $evoLock = 'auvvo_ev_' . (int) $agent['id'] . '_' . $lock_peer;
    if (strlen($evoLock) > 64) {
        $evoLock = substr($evoLock, 0, 64);
    }
    $lkTimeout = auvvo_webhook_ai_use_queue() ? 20 : 180;
    $lkStmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $lkStmt->execute([$evoLock, $lkTimeout]);
    $lockGot = $lkStmt->fetchColumn();
    if ($lockGot === false || (int) $lockGot !== 1) {
        auvvo_webhook_tracelog('exit', ['reason' => 'lock_busy_early', 'lock' => $evoLock]);
        http_response_code(503);
        echo json_encode(['ok' => false, 'info' => 'lock busy', 'retry' => true]);
        exit;
    }

    $inboundDedupeKey = auvvo_webhook_claim_inbound(
        $pdo,
        (int) $agent['id'],
        $data,
        $remote_jid,
        $body,
        $lock_peer
    );
    if ($inboundDedupeKey === null) {
        auvvo_evolution_release_lock($pdo, $evoLock);
        auvvo_webhook_tracelog('exit', ['reason' => 'dedup_duplicate_early']);
        http_response_code(200);
        echo json_encode(['ok' => true, 'info' => 'duplicate webhook (early dedup)']);
        exit;
    }

    try {

    // ============================================================
    // CRM: Auto-criar/atualizar contato ao receber mensagem
    // ============================================================
    $push_name = $data['Info']['PushName'] ?? $data['Info']['Pushname'] ?? '';
    $contactRow = null;
    try {
        require_once 'Contacts.php';
        $crm = new Contacts($pdo);
        $upsert = $crm->upsertFromWebhook((int) $agent['user_id'], (int) $agent['id'], $canonical_jid, (string) $push_name, $connection_id);
        if (is_array($upsert) && !empty($upsert['id'])) {
            $contactRow = $crm->get((int) $agent['user_id'], (int) $upsert['id']);
            if ($contactRow) {
                require_once __DIR__ . '/crm_automation_triggers.inc.php';
                $contactRow = auvvo_crm_hydrate_contact($pdo, (int) $agent['user_id'], $contactRow);
                require_once __DIR__ . '/crm_flow_agent.inc.php';
                $activeHit = auvvo_flow_dispatch_active_inbound(
                    $pdo,
                    (int) $agent['user_id'],
                    $contactRow,
                    (string) $body,
                    $connection_id,
                    $connection
                );
                if (!empty($activeHit['handled'])) {
                    auvvo_webhook_tracelog('exit', [
                        'reason' => $activeHit['reason'] ?? 'flow_active',
                        'ai'     => !empty($activeHit['ai_handled']),
                    ]);
                    http_response_code(200);
                    echo json_encode(['ok' => true, 'info' => $activeHit['reason'] ?? 'flow_active'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                auvvo_crm_fire_whatsapp_triggers(
                    $pdo,
                    (int) $agent['user_id'],
                    (int) $agent['id'],
                    $contactRow,
                    !empty($upsert['is_new']),
                    (string) $body,
                    $connection_id
                );
                if (auvvo_automation_flow_was_handled() || auvvo_automation_ai_was_handled()) {
                    auvvo_webhook_tracelog('exit', [
                        'reason' => 'automation_handled_after_triggers',
                        'flow'   => auvvo_automation_flow_was_handled(),
                        'ai'     => auvvo_automation_ai_was_handled(),
                    ]);
                    http_response_code(200);
                    echo json_encode(['ok' => true, 'info' => 'automation handled'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $contactRow = $crm->get((int) $agent['user_id'], (int) $upsert['id']);
                if ($contactRow) {
                    require_once __DIR__ . '/crm_automation_motor.inc.php';
                    $brain = auvvo_whatsapp_pick_brain_agent($pdo, (int) $agent['user_id'], $connection, $contactRow);
                    if ($brain) {
                        $agent = auvvo_whatsapp_attach_connection_to_agent($brain, $connection);
                    }
                    $resolved = auvvo_crm_resolve_whatsapp_agent_row($pdo, (int) $agent['user_id'], $agent, $contactRow, $connection);
                    if (is_array($resolved) && (int) ($resolved['id'] ?? 0) > 0) {
                        $agent = $resolved;
                    }
                }
            }
        }
    } catch (Throwable $_crmEx) {
        error_log('[Evolution] CRM/automation: ' . $_crmEx->getMessage());
        auvvo_webhook_tracelog('crm_error', ['message' => $_crmEx->getMessage()]);
    }

    require_once __DIR__ . '/crm_flow_agent.inc.php';
    if (auvvo_automation_should_block_standalone($pdo, (int) $agent['user_id'], $contactRow ?? [], $connection)) {
        $blockExit = false;
        if (!auvvo_automation_ai_was_handled() && !empty($contactRow['id'])) {
            $activeHit = auvvo_flow_dispatch_active_inbound(
                $pdo,
                (int) $agent['user_id'],
                $contactRow,
                (string) $body,
                $connection_id,
                $connection
            );
            if (!empty($activeHit['handled'])) {
                auvvo_webhook_tracelog('exit', [
                    'reason' => 'flow_active_blocked:' . ($activeHit['reason'] ?? ''),
                    'ai'     => !empty($activeHit['ai_handled']),
                ]);
                http_response_code(200);
                echo json_encode(['ok' => true, 'info' => $activeHit['reason'] ?? 'flow_active'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        if (auvvo_automation_flow_was_handled() || auvvo_automation_ai_was_handled()) {
            $blockExit = true;
        } elseif (!empty($contactRow['id'])) {
            require_once __DIR__ . '/crm_flow_wait_reply.inc.php';
            require_once __DIR__ . '/crm_flow_converse.inc.php';
            if (auvvo_flow_contact_has_active_wait($pdo, (int) $agent['user_id'], (int) $contactRow['id'])
                || auvvo_flow_converse_get($pdo, (int) $agent['user_id'], $contactRow) !== null) {
                $blockExit = true;
            }
        }
        if ($blockExit) {
            if (!auvvo_automation_ai_was_handled() && !empty($contactRow['id'])) {
                $forceHit = auvvo_flow_dispatch_active_inbound(
                    $pdo,
                    (int) $agent['user_id'],
                    $contactRow,
                    (string) $body,
                    $connection_id,
                    $connection
                );
                if (!empty($forceHit['handled'])) {
                    auvvo_webhook_tracelog('exit', [
                        'reason' => 'flow_active_forced:' . ($forceHit['reason'] ?? ''),
                        'ai'     => !empty($forceHit['ai_handled']),
                    ]);
                    http_response_code(200);
                    echo json_encode(['ok' => true, 'info' => $forceHit['reason'] ?? 'flow_active'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                // Sessão ativa mas dispatch falhou — não deixar conversa sem resposta.
                $blockExit = false;
                auvvo_webhook_tracelog('blockExit_overridden', ['reason' => 'active_session_dispatch_failed']);
            }
        }
        if ($blockExit) {
            auvvo_webhook_tracelog('exit', [
                'reason' => auvvo_automation_flow_was_handled() ? 'flow_handled' : 'flow_binding_active',
            ]);
            http_response_code(200);
            echo '{"ok":true,"info":"automation handled — standalone agent skipped"}';
            exit;
        }
    }

    // Chaves de LLM, ElevenLabs e contexto da empresa + preferências de agenda
    $stmt = $pdo->prepare("SELECT openai_key, gemini_key, elevenlabs_key, company_name, company_niche, company_site, google_calendar_enabled, google_calendar_calendar_id FROM settings WHERE user_id=?");
    $stmt->execute([$agent['user_id']]);
    $settings = $stmt->fetch();

    // Resolve modelo efetivo: usa o do agente ou o padrão do .env (OpenRouter)
    $modelStr = trim((string)($agent['model'] ?? ''));
    if ($modelStr === '') {
        $modelStr = defined('OPENROUTER_DEFAULT_MODEL') ? OPENROUTER_DEFAULT_MODEL : 'openrouter/openai/gpt-4o-mini';
    }
    $isAuvvoAI = $modelStr === 'auvvo-ai';
    // auvvo-ai → DeepSeek V3 se configurado
    if ($isAuvvoAI && auvvo_deepseek_configured()) {
        $isDeepSeek = true;
        $isGemini = false;
        $isOpenRouter = false;
        $dsModel = 'deepseek-chat';
        $modelStr = 'deepseek/chat';
    } else {
        $isGemini     = strpos($modelStr, 'gemini') === 0;
        $dsModel      = auvvo_is_deepseek_model($modelStr);
        $isDeepSeek   = $dsModel !== '';
        $isOpenRouter = !$isGemini && !$isDeepSeek && (
            $isAuvvoAI
            || strpos($modelStr, 'openrouter/') === 0
            || (strpos($modelStr, '/') !== false)
        );
    }

    $geminiUserKey      = trim($settings['gemini_key'] ?? '');
    $geminiEnvKey       = (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') ? trim((string) GEMINI_API_KEY) : '';
    $effectiveGeminiKey = $geminiUserKey !== '' ? $geminiUserKey : $geminiEnvKey;

    $openRouterPlatformKey = (defined('OPENROUTER_API_KEY') && OPENROUTER_API_KEY !== '') ? trim((string) OPENROUTER_API_KEY) : '';

    if ($isGemini && $effectiveGeminiKey === '') {
        auvvo_webhook_tracelog('exit', ['reason' => 'no_gemini_key', 'model' => $modelStr]);
        $fallback = "Olá! Estamos finalizando a chave da inteligência artificial (Google Gemini). Volte em instantes. 😊";
        sendEvolutionMessage($agent['evolution_token'], $evolution_instance_label, $remote_jid, $fallback);
        http_response_code(200); echo '{"ok":true,"info":"no gemini key"}'; exit;
    }

    if ($isOpenRouter && $openRouterPlatformKey === '') {
        auvvo_webhook_tracelog('exit', ['reason' => 'no_openrouter_key', 'model' => $modelStr]);
        $fallback = "Olá! O motor de IA (OpenRouter) não está configurado. Contate o suporte. 😊";
        sendEvolutionMessage($agent['evolution_token'], $evolution_instance_label, $remote_jid, $fallback);
        http_response_code(200); echo '{"ok":true,"info":"no openrouter platform key"}'; exit;
    }

    $deepSeekPlatformKey = auvvo_deepseek_configured() ? trim((string) DEEPSEEK_API_KEY) : '';
    if ($isDeepSeek && $deepSeekPlatformKey === '') {
        auvvo_webhook_tracelog('exit', ['reason' => 'no_deepseek_key', 'model' => $modelStr]);
        $fallback = "Olá! O motor de IA (DeepSeek) não está configurado. Contate o suporte. 😊";
        sendEvolutionMessage($agent['evolution_token'], $evolution_instance_label, $remote_jid, $fallback);
        http_response_code(200); echo '{"ok":true,"info":"no deepseek key"}'; exit;
    }

    if (!$isGemini && !$isOpenRouter && !$isDeepSeek && (!$settings || trim($settings['openai_key'] ?? '') === '')) {
        auvvo_webhook_tracelog('exit', ['reason' => 'no_openai_key', 'model' => $modelStr]);
        $fallback = "Olá! Nosso sistema está sendo configurado. Em breve retornaremos. 😊";
        sendEvolutionMessage($agent['evolution_token'], $evolution_instance_label, $remote_jid, $fallback);
        http_response_code(200); echo '{"ok":true,"info":"no openai key"}'; exit;
    }

    $llmApiKey = $isGemini
        ? $effectiveGeminiKey
        : ($isDeepSeek
            ? $deepSeekPlatformKey
            : ($isOpenRouter ? $openRouterPlatformKey : ($settings['openai_key'] ?? '')));

    // Verifica transbordo (handoff keywords)
    if ($agent['handoff_enabled'] ?? true) {
        $keywords = array_map('trim', explode(',', $agent['handoff_rules'] ?? 'humano'));
        foreach ($keywords as $kw) {
            if ($kw && mb_stripos($body, $kw) !== false) {
                auvvo_webhook_tracelog('exit', ['reason' => 'handoff_keyword', 'kw' => $kw]);
                $msg = $agent['handoff_message'] ?? 'Transferindo para um especialista. Aguarde! 😊';
                sendEvolutionMessage($agent['evolution_token'], $evolution_instance_label, $remote_jid, $msg);
                $summary_id = createHandoffSummary($pdo, $agent['id'], $canonical_jid, $body);
                $log_msg = $msg . ($summary_id ? " [handoff_summary_id={$summary_id}]" : '');
                logConversation($pdo, $agent['id'], $canonical_jid, $body, $log_msg, 'handoff');
                http_response_code(200); echo '{"ok":true,"info":"handoff triggered"}'; exit;
            }
        }
    }

    // ============================================================
    // HANDOFF INTELIGENTE (heurístico): risco/insatisfação/reembolso/ameaças
    // ============================================================
    if ($agent['handoff_enabled'] ?? true) {
        $risk_terms = [
            'procon','reclamação','reclamar','processo','advogado','judicial','denúncia',
            'reembolso','estorno','chargeback','golpe','fraude','enganado','não funciona','nao funciona',
            'cancelar','cancelamento','quero cancelar','quero estornar','quero reembolso',
            'puta','porra','lixo','ridículo','ridiculo','incompetente','vagabundo','roubo'
        ];
        foreach ($risk_terms as $t) {
            if ($t && mb_stripos($body, $t) !== false) {
                auvvo_webhook_tracelog('exit', ['reason' => 'handoff_risk', 'term' => $t]);
                $msg = $agent['handoff_message'] ?? 'Perfeito! Vou te conectar com um especialista agora. Em instantes alguém estará aqui para te atender.';
                sendEvolutionMessage($agent['evolution_token'], $evolution_instance_label, $remote_jid, $msg);
                $summary_id = createHandoffSummary($pdo, $agent['id'], $canonical_jid, $body);
                $log_msg = $msg . ($summary_id ? " [handoff_summary_id={$summary_id}]" : '');
                logConversation($pdo, $agent['id'], $canonical_jid, $body, $log_msg, 'handoff');
                http_response_code(200); echo '{"ok":true,"info":"handoff risk triggered"}'; exit;
            }
        }
    }

    // ============================================================
    // CONTROLE HUMANO: se a IA estiver pausada para este contato, não responder.
    // ============================================================
    $is_paused = false;
    try {
        if ($peer_digits !== '') {
            $stmt = $pdo->prepare(
                "SELECT ia_paused_until FROM conversation_states
                 WHERE agent_id=? AND (contact_jid=? OR contact_jid=? OR contact_jid=? OR contact_jid=?)
                 ORDER BY ia_paused_until DESC LIMIT 1"
            );
            $stmt->execute([
                $agent['id'],
                $canonical_jid,
                $remote_jid,
                $peer_digits . '@s.whatsapp.net',
                $peer_digits . '@c.us',
            ]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT ia_paused_until FROM conversation_states
                 WHERE agent_id=? AND (contact_jid=? OR contact_jid=?)
                 ORDER BY ia_paused_until DESC LIMIT 1"
            );
            $stmt->execute([$agent['id'], $canonical_jid, $remote_jid]);
        }
        $st = $stmt->fetch();
        if ($st && !empty($st['ia_paused_until'])) {
            $is_paused = (strtotime($st['ia_paused_until']) > time());
        }
    } catch (PDOException $e) {
        $is_paused = false;
    }

    if ($is_paused) {
        auvvo_webhook_tracelog('exit', ['reason' => 'ia_paused']);
        // Apenas loga a mensagem recebida para o humano ver; não responde com IA.
        logConversation($pdo, $agent['id'], $canonical_jid, $body, 'IA pausada — aguardando humano', 'fallback');
        http_response_code(200); echo '{"ok":true,"info":"ia paused"}'; exit;
    }

    // ============================================================
    // IA: inline (padrão) ou fila (WEBHOOK_AI_MODE=queue + worker Node ativo)
    // ============================================================
    $queueRequested = auvvo_webhook_ai_use_queue();
    $useAiQueue     = auvvo_webhook_ai_should_queue($pdo);
    if ($queueRequested && !$useAiQueue) {
        auvvo_webhook_tracelog('queue_fallback_inline', ['reason' => 'worker_offline']);
        error_log('[Evolution] WEBHOOK_AI_MODE=queue mas worker inativo — processando IA inline.');
    }
    if ($useAiQueue) {
        require_once __DIR__ . '/conversation_events.inc.php';

        $dedupeKey = $inboundDedupeKey;

        if (auvvo_inbound_echoes_last_reply($pdo, (int) $agent['id'], $canonical_jid, $remote_jid, $peer_digits, $body)) {
            auvvo_webhook_tracelog('exit', ['reason' => 'echo_suppressed']);
            http_response_code(200);
            echo json_encode(['ok' => true, 'info' => 'echo inbound suppressed']);
            exit;
        }

        $pending_log_id = logConversationPending($pdo, (int) $agent['id'], $canonical_jid, $body);
        $traceId        = (string) ($GLOBALS['auvvo_webhook_trace_id'] ?? '');

        auvvo_emit_conversation_event($pdo, (int) $agent['user_id'], (int) $agent['id'], $canonical_jid, 'message_in', [
            'preview' => mb_substr($body, 0, 120),
        ]);

        $enqueue = auvvo_ai_enqueue_inbound_message($pdo, [
            'agent_id'                 => (int) $agent['id'],
            'whatsapp_connection_id'   => $connection_id,
            'pending_log_id'           => $pending_log_id,
            'canonical_jid'            => $canonical_jid,
            'remote_jid'               => $remote_jid,
            'peer_digits'              => $peer_digits,
            'body'                     => $body,
            'evolution_instance_label' => $evolution_instance_label,
            'lock_peer'                => $lock_peer,
            'dedupe_key'               => $dedupeKey,
            'trace_id'                 => $traceId,
        ]);

        if (!$enqueue['ok']) {
            if (!empty($pending_log_id)) {
                deleteConversationLogById($pdo, (int) $pending_log_id, (int) $agent['id']);
            }
            error_log('[Evolution] enqueue failed: ' . ($enqueue['error'] ?? ''));
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'enqueue failed']);
            exit;
        }

        auvvo_webhook_tracelog('queued', [
            'pending_log_id' => $pending_log_id,
            'merged'         => !empty($enqueue['merged']),
            'job_id'         => $enqueue['job_id'] ?? null,
        ]);

        $GLOBALS['auvvo_webhook_body_sent'] = true;
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'queued' => true, 'merged' => !empty($enqueue['merged'])]);
        exit;
    }

    // ----- inline: sem fila -----
    ignore_user_abort(true);
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    $GLOBALS['auvvo_worker_start_time'] = time();

    $dedupeKey       = $inboundDedupeKey;
    $pending_log_id = null;

    try {
        if (auvvo_inbound_echoes_last_reply($pdo, (int) $agent['id'], $canonical_jid, $remote_jid, $peer_digits, $body)) {
            if ($dedupeKey !== null && is_string($dedupeKey) && strpos($dedupeKey, 'fp:') === 0) {
                try {
                    $pdo->prepare(
                        'DELETE FROM webhook_message_dedup WHERE agent_id = ? AND message_id = ?'
                    )->execute([(int) $agent['id'], $dedupeKey]);
                } catch (PDOException $e) {
                }
            }
            auvvo_webhook_tracelog('exit', ['reason' => 'echo_suppressed']);
            http_response_code(200);
            echo json_encode(['ok' => true, 'info' => 'echo inbound suppressed']);
            exit;
        }

        $pending_log_id = logConversationPending($pdo, (int) $agent['id'], $canonical_jid, $body);
        auvvo_webhook_tracelog('pending_inserted', ['pending_log_id' => $pending_log_id, 'mode' => 'inline']);

        $settingsForPipeline = is_array($settings) ? $settings : [];
        auvvo_run_ai_reply(
            $pdo,
            $agent,
            $settingsForPipeline,
            $llmApiKey,
            $canonical_jid,
            $remote_jid,
            $peer_digits,
            $body,
            $pending_log_id,
            $evolution_instance_label
        );
    } catch (Throwable $e) {
        error_log('[Auvvo Webhook] inline IA: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        auvvo_webhook_tracelog('handler_exception', [
            'class' => get_class($e),
            'msg'   => mb_substr($e->getMessage(), 0, 400),
            'mode'  => 'inline',
        ]);
        if (!empty($pending_log_id)) {
            deleteConversationLogById($pdo, (int) $pending_log_id, (int) $agent['id']);
        }
    }

    $GLOBALS['auvvo_webhook_body_sent'] = true;
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'inline' => true]);
    exit;
    } finally {
        auvvo_evolution_release_lock($pdo, $evoLock);
    }
}

// ============================================================
// CONEXÃO (Evolution Go: Connected, PairSuccess, LoggedOut, OfflineSyncCompleted — ver subscribe CONNECTION)
// ============================================================
if (in_array($event_type, ['CONNECTED', 'PAIRSUCCESS', 'LOGGEDOUT', 'OFFLINESYNCCOMPLETED', 'CONNECTION'], true)) {
    if ($event_type === 'OFFLINESYNCCOMPLETED') {
        // Apenas fim de sync offline; não forçar waiting_qr (data não traz status "open")
    } else {
        $is_online = (($data['status'] ?? '') === 'open')
            || (($data['Connected'] ?? false) && ($data['LoggedIn'] ?? false));
        if ($event_type === 'LOGGEDOUT') {
            $is_online = false;
        }
        $db_status = $is_online ? 'online' : 'waiting_qr';
        require_once __DIR__ . '/whatsapp_connections.inc.php';
        $connRow = auvvo_whatsapp_resolve_connection_from_webhook($pdo, $webhook_instance_token, $instance_slug);
        $connId = $connRow ? (int) ($connRow['id'] ?? 0) : null;
        auvvo_whatsapp_update_connection_status($pdo, $webhook_instance_token, $instance_slug, $db_status, $connId > 0 ? $connId : null);
    }
}

if (empty($GLOBALS['auvvo_webhook_body_sent'])) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'event' => $event_type]);
}

} // fim router HTTP (MESSAGE / CONNECTION / resposta padrão)

// ============================================================
// FUNÇÕES HELPER (histórico em conversation_history.inc.php)
// ============================================================

/**
 * Remove conversas antigas de um agente (lazy delete).
 * Executado com ~5% de probabilidade por requisição para não adicionar latência.
 *
 * @param PDO $pdo       Conexão PDO
 * @param int $agent_id  ID do agente
 * @param int $keepDays  Quantos dias manter (padrão: 30)
 */
function pruneOldConversations(PDO $pdo, int $agent_id, int $keepDays = 30): void {
    try {
        $pdo->prepare(
            "DELETE FROM conversation_logs
             WHERE agent_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        )->execute([$agent_id, $keepDays]);
    } catch (PDOException $e) {
        // Silencia erros — operação de manutenção opcional
    }
}

function auvvo_llm_extract_chat_content($content): ?string {
    if (is_string($content)) {
        $t = trim($content);
        return $t !== '' ? $t : null;
    }
    if (!is_array($content)) {
        return null;
    }
    $parts = [];
    foreach ($content as $p) {
        if (is_string($p)) {
            $parts[] = $p;
        } elseif (is_array($p)) {
            if (isset($p['text'])) {
                $parts[] = (string) $p['text'];
            }
        }
    }
    $t = trim(implode('', $parts));
    return $t !== '' ? $t : null;
}

/**
 * Quando o cURL fecha por timeout ou rede com JSON incompleto, tenta ler o texto do assistente já recebido.
 * (Útil quando a API vai devolvendo aos poucos ou o servidor corta aos N segundos.)
 */
function auvvo_llm_recover_truncated_chat_response(string $raw, bool $isGemini): ?string {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $tryDecode = json_decode($raw, true);
    if (is_array($tryDecode)) {
        if ($isGemini) {
            if (!empty($tryDecode['error'])) {
                return null;
            }
            $gemOut = $tryDecode['candidates'][0]['content']['parts'][0]['text'] ?? null;

            return (is_string($gemOut) && trim($gemOut) !== '') ? $gemOut : null;
        }
        if (isset($tryDecode['error'])) {
            return null;
        }
        $choice = $tryDecode['choices'][0] ?? null;
        if (!is_array($choice)) {
            return null;
        }
        $msg = $choice['message'] ?? null;
        if (!is_array($msg)) {
            return null;
        }
        $out = auvvo_llm_extract_chat_content($msg['content'] ?? null);
        if (($out === null || trim((string) $out) === '') && !empty($choice['text'])) {
            $out = auvvo_llm_extract_chat_content($choice['text']);
        }

        return ($out !== null && trim((string) $out) !== '') ? (string) $out : null;
    }

    if ($isGemini) {
        if (preg_match('/"parts"\s*:\s*\[\s*\{\s*"text"\s*:\s*"((?:[^"\\\\]|\\\\.)*)/s', $raw, $m)) {
            $j = '"' . $m[1] . '"';
            $decoded = json_decode($j);

            return (is_string($decoded) && trim($decoded) !== '') ? trim($decoded) : null;
        }

        return null;
    }

    if (!preg_match('/"role"\s*:\s*"assistant"/', $raw)) {
        return null;
    }

    $p = strpos($raw, '"content"');
    if ($p === false) {
        return null;
    }
    $colonPos = strpos($raw, ':', $p + 9);
    if ($colonPos === false) {
        return null;
    }
    $j = $colonPos + 1;
    $n = strlen($raw);
    while ($j < $n && ctype_space($raw[$j])) {
        ++$j;
    }
    if ($j >= $n || $raw[$j] !== '"') {
        return null;
    }
    ++$j;
    $buf = '';
    while ($j < $n) {
        $c = $raw[$j];
        if ($c === '\\') {
            if ($j + 1 >= $n) {
                break;
            }
            $nx = $raw[$j + 1];
            ++$j;
            if ($nx === 'n') {
                $buf .= "\n";
            } elseif ($nx === 'r') {
                $buf .= "\r";
            } elseif ($nx === 't') {
                $buf .= "\t";
            } elseif ($nx === '"' || $nx === '\\' || $nx === '/') {
                $buf .= $nx;
            } elseif ($nx === 'u' && $j + 5 < $n) {
                $hex = substr($raw, $j + 1, 4);
                if (preg_match('/^[0-9a-fA-F]{4}$/', $hex)) {
                    $uch = json_decode('"\\u' . $hex . '"');
                    $buf .= is_string($uch) ? $uch : '';
                    $j += 5;
                    continue;
                }
                $buf .= 'u';
            } else {
                $buf .= $nx;
            }
            ++$j;
            continue;
        }
        if ($c === '"') {
            break;
        }
        $buf .= $c;
        ++$j;
    }

    $buf = trim($buf);

    return $buf !== '' ? $buf : null;
}

/**
 * Extrai espera sugerida pelo provedor (Retry-After em segundos ou HTTP-date).
 *
 * @return int|null milissegundos a aguardar, ou null
 */
function auvvo_llm_retry_after_ms_from_header_lines(string $headerLines): ?int {
    if ($headerLines === '') {
        return null;
    }
    if (preg_match('/^Retry-After:\s*(\d+)\s*$/mi', $headerLines, $m)) {
        $sec = (int) $m[1];

        return min(300000, max(500, $sec * 1000));
    }
    if (preg_match('/^Retry-After:\s*([^\r\n]+)/mi', $headerLines, $m)) {
        $v = trim($m[1]);
        if ($v !== '' && !ctype_digit($v)) {
            $ts = strtotime($v);
            if ($ts !== false) {
                $w = $ts - time();
                if ($w > 0) {
                    return min(300000, max(500, $w * 1000));
                }
            }
        }
    }

    return null;
}

/**
 * Chama a API do modelo com histórico no contexto.
 *
 * Provedores: OpenAI Chat Completions, OpenRouter (mesmo esquema) ou Gemini generateContent.
 * Ref.: https://openrouter.ai/docs/api/reference/overview — https://ai.google.dev/api
 *
 * @param string      $openRouterStableUser ID opcional para o campo `user` da OpenRouter (abuso/routing)
 */
function callOpenAI(
    string $apiKey,
    string $model,
    string $systemPrompt,
    string $userMessage,
    array  $history,
    int    $maxTokens,
    float  $temperature,
    string $openRouterStableUser = '',
    ?array &$capturedToolCalls = null
): ?string {

    $isAuvvoAI   = $model === 'auvvo-ai';
    // auvvo-ai → DeepSeek V3 se configurado, senao OpenRouter
    if ($isAuvvoAI && auvvo_deepseek_configured()) {
        $isDeepSeek   = true;
        $isGemini     = false;
        $isOpenRouter = false;
        $dsModel      = 'deepseek-chat';
    } else {
        $isGemini     = !$isAuvvoAI && strpos($model, 'gemini') === 0;
        $dsModel      = auvvo_is_deepseek_model($model);
        $isDeepSeek   = $dsModel !== '';
        $isOpenRouter = !$isGemini && !$isDeepSeek && (
            $isAuvvoAI
            || strpos($model, 'openrouter/') === 0
            || strpos($model, '/') !== false
        );
    }

    // Resolve o model ID real a enviar à API OpenRouter:
    $orModelId = $isOpenRouter ? auvvo_openrouter_model_id($isAuvvoAI ? 'auvvo-ai' : $model) : $model;

    if ($isGemini) {
        
        // =============== NATIVE GEMINI API FORMAT ===============
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
        
        $contents = [];
        foreach ($history as $msg) {
            $contents[] = [
                'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $msg['content']]]
            ];
        }
        // Mensagem atual
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $userMessage]]
        ];

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]]
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature'     => max(0.0, min(2.0, (float) $temperature)),
                'maxOutputTokens' => max(1, (int) $maxTokens),
            ]
        ];

        $headers = [
            'Content-Type: application/json',
            'X-goog-api-key: ' . $apiKey,
        ];

    } elseif ($isDeepSeek) {
        // =============== DEEPSEEK API (OpenAI-compatible) ===============
        $url = DEEPSEEK_BASE_URL . '/chat/completions';

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $msg) { $messages[] = $msg; }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $payload = [
            'model'       => $dsModel,
            'messages'    => $messages,
            'max_tokens'  => max(1, (int) $maxTokens),
            'temperature' => max(0.0, min(2.0, (float) $temperature)),
            'stream'      => false,
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];

    } else {
        // =============== OPENAI-COMPATIBLE CHAT COMPLETIONS (OpenAI ou OpenRouter) ===============
        $url = $isOpenRouter
            ? 'https://openrouter.ai/api/v1/chat/completions'
            : 'https://api.openai.com/v1/chat/completions';

        // OpenRouter: envia o model sem o prefixo 'openrouter/' (ex: 'openai/gpt-4o-mini').
        // Ref.: https://openrouter.ai/docs/api/reference/parameters
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];
        foreach ($history as $msg) {
            $messages[] = $msg;
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        if ($isOpenRouter) {
            $payload = [
                'model'       => $orModelId, // sem prefixo 'openrouter/'
                'messages'    => $messages,
                'max_tokens'  => max(1, (int) $maxTokens),
                'temperature' => max(0.0, min(2.0, (float) $temperature)),
                'stream'      => false,
            ];
            $u = trim($openRouterStableUser);
            if ($u !== '') {
                $payload['user'] = $u;
            }
        } else {
            $payload = [
                'model'       => $model,
                'max_tokens'  => max(1, (int) $maxTokens),
                'temperature' => max(0.0, min(2.0, (float) $temperature)),
                'messages'    => $messages,
                'stream'      => false,
            ];
        }

        if ($capturedToolCalls !== null) {
            require_once __DIR__ . '/auvvo_brain_tools.inc.php';
            if (auvvo_brain_native_tools_enabled()) {
                $brainTools = auvvo_brain_openai_tools_for_api();
                if ($brainTools !== []) {
                    $payload['tools']       = $brainTools;
                    $payload['tool_choice'] = 'auto';
                }
            }
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
        if ($isOpenRouter) {
            // Atribuição de app: https://openrouter.ai/docs/api/reference/overview#headers
            $headers[] = 'HTTP-Referer: ' . APP_BASE_URL;
            $headers[] = 'X-OpenRouter-Title: Auvvo';
            $headers[] = 'X-Title: Auvvo';
        }
    }

    $jsonBody = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    if ($jsonBody === false) {
        $je = function_exists('json_last_error_msg') ? json_last_error_msg() : 'json_encode falhou';
        error_log('[Auvvo AI] Payload inválido para API: ' . $je);
        auvvo_webhook_tracelog('llm_json_error', ['err' => $je]);
        return null;
    }

    // Timeout total do cURL (não confundir com limite do PHP — ver worker set_time_limit).
    // Modelos lentos / free tier costumam precisar >45s; padrão 120, teto 300.
    $curlTotalSec = max(45, min(300, (int) (($_ENV['WEBHOOK_LLM_CURL_TIMEOUT_SEC'] ?? '120') ?: 120)));

    $providerTag = $isGemini ? 'gemini' : ($isDeepSeek ? 'deepseek' : ($isOpenRouter ? 'openrouter' : 'openai'));
    auvvo_webhook_tracelog('llm_request', [
        'provider'      => $providerTag,
        'model'         => $isOpenRouter ? $orModelId : ($isDeepSeek ? $dsModel : $model),
        'payload_bytes' => strlen($jsonBody),
        'timeout_sec'   => $curlTotalSec,
        'sapi'          => PHP_SAPI,
    ]);

    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_USERAGENT      => 'Auvvo-Webhook/1.0 (PHP ' . PHP_VERSION . ')',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => $curlTotalSec,
        CURLOPT_CONNECTTIMEOUT => min(30, max(10, (int) ($curlTotalSec / 6))),
    ];
    if (PHP_OS_FAMILY !== 'Windows') {
        $curlOpts[CURLOPT_NOSIGNAL] = true;
    }
    if (defined('CURL_IPRESOLVE_V4')) {
        $curlOpts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }
    $isFreeOpenRouterModel = $isOpenRouter && (
        str_contains(strtolower($orModelId), ':free')
        || str_contains(strtolower($model), ':free')
    );
    $rateLimitRetriesRaw = trim((string) ($_ENV['WEBHOOK_LLM_RATE_LIMIT_RETRIES'] ?? ''));
    $rateLimitRetries    = $rateLimitRetriesRaw !== ''
        ? max(0, min(15, (int) $rateLimitRetriesRaw))
        : ($isFreeOpenRouterModel ? 8 : 4);
    $retryHttpCodes   = [429, 502, 503];

    $responseStr = '';
    $httpCode    = 0;
    $curlError   = '';
    $elapsedMs   = 0;

    $providerTagHttp = $isGemini ? 'gemini' : ($isDeepSeek ? 'deepseek' : ($isOpenRouter ? 'openrouter' : 'openai'));

    for ($rateAttempt = 0; ; $rateAttempt++) {
        $rawHeaders = '';
        $ch = curl_init($url);
        if (!curl_setopt_array($ch, $curlOpts)) {
            $se = curl_error($ch) ?: 'curl_setopt_array falhou';
            curl_close($ch);
            error_log('[Auvvo AI] ' . $se);
            auvvo_webhook_tracelog('llm_curl_setopt_fail', ['err' => mb_substr($se, 0, 200)]);

            return null;
        }
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, $headerLine) use (&$rawHeaders): int {
            $rawHeaders .= $headerLine;

            return strlen($headerLine);
        });
        $t0       = microtime(true);
        $response = curl_exec($ch);
        $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
        $curlError = curl_error($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $responseStr = is_string($response) ? $response : '';

        auvvo_webhook_tracelog('llm_http', [
            'ms'           => $elapsedMs,
            'http'         => $httpCode,
            'err'          => $curlError !== '' ? mb_substr($curlError, 0, 120) : '',
            'rate_attempt' => $rateAttempt,
        ]);

        if ($curlError !== '') {
            error_log('[Auvvo AI] Erro cURL: ' . $curlError);
            auvvo_webhook_tracelog('llm_curl_fail', [
                'model' => $model,
                'err'   => mb_substr($curlError, 0, 300),
            ]);
            $recovered = auvvo_llm_recover_truncated_chat_response($responseStr, $isGemini);
            if ($recovered !== null && trim($recovered) !== '') {
                auvvo_webhook_tracelog('llm_recovered_partial_after_curl_error', [
                    'chars'    => mb_strlen($recovered),
                    'bytes_in' => strlen($responseStr),
                ]);

                return $recovered;
            }

            return null;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            break;
        }

        $rawPreviewErr = mb_substr(preg_replace('/\s+/', ' ', $responseStr), 0, 600);
        $decodedErr    = json_decode($responseStr, true);
        $errMsg        = '';
        if (is_array($decodedErr)) {
            if (isset($decodedErr['error']) && is_array($decodedErr['error'])) {
                $errMsg = (string) ($decodedErr['error']['message'] ?? '');
            } elseif (isset($decodedErr['error']) && is_string($decodedErr['error'])) {
                $errMsg = $decodedErr['error'];
            }
            if ($errMsg === '') {
                $errMsg = (string) ($decodedErr['message'] ?? '');
            }
        }
        $detail = $errMsg !== '' ? $errMsg : $rawPreviewErr;

        $mayRetry = $rateAttempt < $rateLimitRetries && in_array($httpCode, $retryHttpCodes, true);
        if (!$mayRetry) {
            auvvo_webhook_tracelog('llm_http_error', [
                'provider' => $providerTagHttp,
                'http'     => $httpCode,
                'detail'   => mb_substr($detail, 0, 500),
            ]);
            error_log('[Auvvo AI] HTTP ' . $httpCode . ' (' . $providerTagHttp . '): ' . mb_substr($detail, 0, 500));
            $GLOBALS['auvvo_last_llm_error'] = 'HTTP ' . $httpCode . ': ' . mb_substr($detail, 0, 300);

            return null;
        }

        $retryAfterMs = ($httpCode === 429) ? auvvo_llm_retry_after_ms_from_header_lines($rawHeaders) : null;

        // Back-off exponencial + jitter. Modelos OpenRouter `:free` costumam exigir esperas longas entre 429.
        $baseMs = (int) (2000 * (2 ** $rateAttempt)) + random_int(0, 1200);
        if ($isFreeOpenRouterModel && $httpCode === 429) {
            $baseMs = (int) min(120000, $baseMs * 2 + 8000);
        }
        $sleepMs = min(120000, max($baseMs, $retryAfterMs ?? 0));
        auvvo_webhook_tracelog('llm_rate_limit_backoff', [
            'http'              => $httpCode,
            'attempt'           => $rateAttempt + 1,
            'max_retries'       => $rateLimitRetries,
            'sleep_ms'          => $sleepMs,
            'retry_after_ms'    => $retryAfterMs,
            'free_openrouter'   => $isFreeOpenRouterModel,
            'detail_preview'    => mb_substr($detail, 0, 160),
        ]);
        usleep($sleepMs * 1000);
    }

    $rawPreview = mb_substr(preg_replace('/\s+/', ' ', $responseStr), 0, 600);

    $decoded = json_decode($responseStr, true);

    // Evitar logar payloads sensíveis em produção
    if (defined('IS_DEV') && IS_DEV) {
        error_log('[Auvvo AI] HTTP CODE: ' . $httpCode);
        error_log('[Auvvo AI] Resposta da API (truncada): ' . mb_substr($responseStr, 0, 2000));
    }

    if (!is_array($decoded)) {
        auvvo_webhook_tracelog('llm_bad_json', ['provider' => $providerTagHttp, 'preview' => $rawPreview]);
        $recoveredMalformed = auvvo_llm_recover_truncated_chat_response($responseStr, $isGemini);
        if ($recoveredMalformed !== null && trim($recoveredMalformed) !== '') {
            auvvo_webhook_tracelog('llm_recovered_malformed_json', ['chars' => mb_strlen($recoveredMalformed)]);

            return $recoveredMalformed;
        }

        return null;
    }

    // Tratamento de resposta com base na API
    if ($isGemini) {
        if (isset($decoded['error'])) {
            error_log('[Auvvo Gemini] Erro da API: ' . ($decoded['error']['message'] ?? json_encode($decoded['error'])));
            auvvo_webhook_tracelog('llm_api_error', [
                'provider' => 'gemini',
                'http'     => $httpCode,
                'msg'      => mb_substr((string) ($decoded['error']['message'] ?? json_encode($decoded['error'])), 0, 500),
            ]);
            return null;
        }
        $gemOut = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($gemOut === null || trim((string) $gemOut) === '') {
            auvvo_webhook_tracelog('llm_empty_body', ['provider' => 'gemini', 'http' => $httpCode]);
        }
        return $gemOut;
    }
    if (isset($decoded['error'])) {
        $tag = $isOpenRouter ? 'OpenRouter' : 'OpenAI';
        error_log('[Auvvo ' . $tag . '] Erro da API: ' . ($decoded['error']['message'] ?? json_encode($decoded['error'])));
        auvvo_webhook_tracelog('llm_api_error', [
            'provider' => $isOpenRouter ? 'openrouter' : 'openai',
            'http'     => $httpCode,
            'msg'      => mb_substr((string) ($decoded['error']['message'] ?? json_encode($decoded['error'])), 0, 500),
        ]);
        return null;
    }
    $choice0 = $decoded['choices'][0] ?? null;
    $msg0    = is_array($choice0) ? ($choice0['message'] ?? null) : null;
    $chatOut = is_array($msg0) ? auvvo_llm_extract_chat_content($msg0['content'] ?? null) : null;
    if (($chatOut === null || trim((string) $chatOut) === '') && is_array($choice0)) {
        $altText = $choice0['text'] ?? null;
        if ($altText !== null && $altText !== '') {
            $chatOut = auvvo_llm_extract_chat_content($altText);
        }
    }
    if (($chatOut === null || trim((string) $chatOut) === '') && is_array($msg0) && !empty($msg0['tool_calls'])) {
        $tcList = is_array($msg0['tool_calls']) ? $msg0['tool_calls'] : [];
        auvvo_webhook_tracelog('llm_tool_calls_only', [
            'provider' => $isOpenRouter ? 'openrouter' : 'openai',
            'n'        => count($tcList),
        ]);
        if ($capturedToolCalls !== null) {
            $capturedToolCalls = $tcList;
            auvvo_webhook_tracelog('llm_tool_calls_brain', ['captured' => count($tcList)]);

            return '';
        }
        // Fallback: segunda chamada sem ferramentas (legado).
        if (!$isGemini) {
            $retryPayload = $payload;
            $retryPayload['tool_choice'] = 'none';

            $jsonRetry = json_encode($retryPayload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
            if ($jsonRetry !== false && isset($url, $headers)) {
                $ch2 = curl_init($url);
                if ($ch2 !== false && curl_setopt_array($ch2, array_merge($curlOpts, [CURLOPT_POSTFIELDS => $jsonRetry]))) {
                    $tRetry = microtime(true);
                    auvvo_webhook_tracelog('llm_request', [
                        'provider'      => ($isOpenRouter ? 'openrouter' : 'openai') . '_retry_no_tools',
                        'model'         => $isOpenRouter ? $orModelId : $model,
                        'payload_bytes' => strlen($jsonRetry),
                        'timeout_sec'   => $curlTotalSec,
                        'sapi'          => PHP_SAPI,
                    ]);
                    $responseRetry = curl_exec($ch2);
                    $elapsedRetry  = (int) round((microtime(true) - $tRetry) * 1000);
                    $httpRetry     = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                    $curlErrRetry  = curl_error($ch2);
                    curl_close($ch2);

                    auvvo_webhook_tracelog('llm_http', [
                        'phase' => 'retry_no_tools',
                        'ms'    => $elapsedRetry,
                        'http'  => $httpRetry,
                        'err'   => $curlErrRetry !== '' ? mb_substr($curlErrRetry, 0, 120) : '',
                    ]);

                    $responseRetryStr = is_string($responseRetry) ? $responseRetry : '';
                    if ($curlErrRetry === '' && $httpRetry >= 200 && $httpRetry < 300) {
                        $decodedRetry = json_decode($responseRetryStr, true);
                        if (is_array($decodedRetry) && !isset($decodedRetry['error'])) {
                            $choiceR = $decodedRetry['choices'][0] ?? null;
                            $msgR    = is_array($choiceR) ? ($choiceR['message'] ?? null) : null;
                            $outR    = is_array($msgR) ? auvvo_llm_extract_chat_content($msgR['content'] ?? null) : null;
                            if (($outR === null || trim((string) $outR) === '') && is_array($choiceR)) {
                                $altR = $choiceR['text'] ?? null;
                                if ($altR !== null && $altR !== '') {
                                    $outR = auvvo_llm_extract_chat_content($altR);
                                }
                            }
                            if ($outR !== null && trim((string) $outR) !== '') {
                                $chatOut      = $outR;
                                $decoded      = $decodedRetry;
                                $choice0      = $choiceR;
                                $responseStr  = $responseRetryStr;
                                $msg0         = $msgR;
                            }
                        }
                    }
                } elseif ($ch2 !== false) {
                    curl_close($ch2);
                }
            }
        }
    }

    if ($chatOut === null || trim((string) $chatOut) === '') {
        auvvo_webhook_tracelog('llm_empty_body', ['provider' => $isOpenRouter ? 'openrouter' : 'openai', 'http' => $httpCode]);
    }
    return $chatOut;
}

/**
 * Envia mensagem via Evolution Go usando o token da instância.
 */
function sendEvolutionMessage($token, $instance, $jid, $text) {
    $api = new EvolutionAPI(EVOLUTION_API_URL, EVOLUTION_API_KEY);
    $number = auvvo_whatsapp_peer_digits($jid);
    if ($number === '') {
        $number = preg_replace('/\D/', '', str_replace(['@s.whatsapp.net', '@c.us'], '', $jid));
    }
    auvvo_webhook_tracelog('evolution_send', [
        'instance' => $instance,
        'jid'      => $jid,
        'number'   => $number,
        'text_len' => mb_strlen($text),
    ]);
    $res = $api->sendText($token, $number, $text);
    auvvo_webhook_tracelog('evolution_send_result', [
        'code' => $res['code'] ?? null,
        'ok'   => empty($res['error']),
        'body' => mb_substr((string) json_encode($res), 0, 300),
    ]);
    return $res;
}

/**
 * Envia mensagem de áudio (Base64) de volta para o cliente no WhatsApp via Evolution API.
 */
function sendEvolutionAudio($token, $instance, $jid, $base64) {
    $api = new EvolutionAPI(EVOLUTION_API_URL, EVOLUTION_API_KEY);
    $number = auvvo_whatsapp_peer_digits($jid);
    if ($number === '') {
        $number = preg_replace('/\D/', '', str_replace(['@s.whatsapp.net', '@c.us'], '', $jid));
    }
    auvvo_webhook_tracelog('evolution_send_audio', [
        'instance' => $instance,
        'jid'      => $jid,
        'number'   => $number,
        'base64_len' => strlen($base64),
    ]);
    $res = $api->sendWhatsAppAudioBase64($token, $instance, $number, $base64);
    auvvo_webhook_tracelog('evolution_send_audio_result', [
        'code' => $res['code'] ?? null,
        'ok'   => empty($res['error']),
        'body' => mb_substr((string) json_encode($res), 0, 300),
    ]);
    return $res;
}

/**
 * Garante colunas anti-duplicação de resposta IA em conversation_logs.
 */
function auvvo_conversation_logs_ensure_ai_reply_columns(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec('ALTER TABLE conversation_logs ADD COLUMN ai_reply_claimed_at DATETIME NULL DEFAULT NULL');
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec('ALTER TABLE conversation_logs ADD COLUMN ai_reply_completed_at DATETIME NULL DEFAULT NULL');
    } catch (PDOException $e) {
    }
    $done = true;
}

/**
 * Apenas um processo pode gerar/enviar resposta para esta linha pendente.
 *
 * @return bool true se este request ganhou o claim (deve chamar LLM)
 */
function auvvo_conversation_logs_claim_ai_reply(PDO $pdo, int $logId, int $agentId): bool {
    if ($logId <= 0) {
        return true;
    }
    auvvo_conversation_logs_ensure_ai_reply_columns($pdo);
    $staleMin = max(5, min(45, (int) (($_ENV['AI_REPLY_CLAIM_STALE_MINUTES'] ?? '12') ?: 12)));

    $sql = "UPDATE conversation_logs SET ai_reply_claimed_at = NOW()
            WHERE id = ? AND agent_id = ?
              AND (response_msg IS NULL OR TRIM(COALESCE(response_msg, '')) = '')
              AND ai_reply_completed_at IS NULL
              AND (
                    ai_reply_claimed_at IS NULL
                 OR ai_reply_claimed_at < DATE_SUB(NOW(), INTERVAL {$staleMin} MINUTE)
              )";
    try {
        $st = $pdo->prepare($sql);
        if (!$st instanceof PDOStatement) {
            return false;
        }
        $st->execute([$logId, $agentId]);

        return $st->rowCount() === 1;
    } catch (PDOException $e) {
        error_log('[Auvvo] claim_ai_reply: ' . $e->getMessage());

        return false;
    }
}

/**
 * Insere linha só com a mensagem recebida (response vazio) para o MySQL refletir o inbound antes da LLM.
 * Reutiliza pendente recente com o mesmo texto (Evolution: webhook duplo instância+global ou retentativas).
 */
function logConversationPending(PDO $pdo, int $agent_id, string $contact, string $incoming): ?int {
    auvvo_conversation_logs_ensure_ai_reply_columns($pdo);
    $windowSec = max(60, min(600, (int) (($_ENV['WEBHOOK_PENDING_REUSE_WINDOW_SEC'] ?? '180') ?: 180)));
    try {
        $stFind = $pdo->prepare(
            "SELECT id FROM conversation_logs WHERE agent_id = ? AND type = 'ai'
             AND (response_msg IS NULL OR TRIM(COALESCE(response_msg, '')) = '')
             AND ai_reply_completed_at IS NULL
             AND contact_jid = ?
             AND incoming_msg = ?
             AND created_at > DATE_SUB(NOW(), INTERVAL {$windowSec} SECOND)
             ORDER BY id DESC LIMIT 1"
        );
        if ($stFind instanceof PDOStatement) {
            $stFind->execute([$agent_id, $contact, $incoming]);
            $existing = $stFind->fetch(PDO::FETCH_ASSOC);
            if ($existing && !empty($existing['id'])) {
                return (int) $existing['id'];
            }
        }
    } catch (PDOException $e) {
    }

    try {
        $st = $pdo->prepare(
            "INSERT INTO conversation_logs (agent_id, contact_jid, incoming_msg, response_msg, type)
             VALUES (?, ?, ?, '', 'ai')"
        );
        if (!$st instanceof PDOStatement) {
            return null;
        }
        $st->execute([$agent_id, $contact, $incoming]);
        $id = (int) $pdo->lastInsertId();

        return $id > 0 ? $id : null;
    } catch (PDOException $e) {
        return null;
    }
}

function finalizeConversationLog(PDO $pdo, int $logId, int $agentId, string $response, string $type): void {
    if ($logId <= 0) {
        return;
    }
    try {
        auvvo_conversation_logs_ensure_ai_reply_columns($pdo);
        $st = $pdo->prepare(
            'UPDATE conversation_logs SET response_msg = ?, type = ?, ai_reply_completed_at = NOW() WHERE id = ? AND agent_id = ?'
        );
        if (!$st instanceof PDOStatement) {
            $info = $pdo->errorInfo();
            error_log('[Auvvo] finalizeConversationLog prepare failed: ' . ($info[2] ?? ''));

            return;
        }
        $st->execute([$response, $type, $logId, $agentId]);
    } catch (PDOException $e) {
        // empty
    }
}

function deleteConversationLogById(PDO $pdo, int $logId, int $agentId): void {
    if ($logId <= 0) {
        return;
    }
    try {
        $st = $pdo->prepare('DELETE FROM conversation_logs WHERE id = ? AND agent_id = ?');
        if (!$st instanceof PDOStatement) {
            return;
        }
        $st->execute([$logId, $agentId]);
    } catch (PDOException $e) {
        // empty
    }
}

/**
 * Registra a conversa na tabela conversation_logs.
 * Silencia erros — logging não deve quebrar o fluxo principal.
 */
function logConversation(PDO $pdo, int $agent_id, string $contact, string $incoming, string $response, string $type): void {
    try {
        $st = $pdo->prepare(
            "INSERT INTO conversation_logs (agent_id, contact_jid, incoming_msg, response_msg, type)
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$st instanceof PDOStatement) {
            $info = $pdo->errorInfo();
            error_log('[Auvvo] logConversation prepare failed: ' . ($info[2] ?? ''));
            return;
        }
        $st->execute([$agent_id, $contact, $incoming, $response, $type]);
    } catch (PDOException $e) {
        // Silencia se a tabela não existir ainda
    }
}

/**
 * Cria um resumo interno para o humano assumir (sem mostrar ao cliente).
 * Retorna ID do resumo ou null.
 */
function createHandoffSummary(PDO $pdo, int $agent_id, string $contact_jid, string $last_user_msg): ?int {
    try {
        // Tabela pode não existir (migração futura). Falha silenciosa.
        $peerForHistory = auvvo_whatsapp_peer_digits($contact_jid);
        $history = getConversationHistory($pdo, $agent_id, $contact_jid, 6, '', $peerForHistory);
        $last_msgs = [];
        foreach (array_slice($history, -6) as $m) {
            $role = ($m['role'] === 'assistant') ? 'AGENTE' : 'CLIENTE';
            $txt = trim((string)($m['content'] ?? ''));
            if ($txt !== '') $last_msgs[] = "{$role}: " . mb_substr($txt, 0, 400);
        }
        $last_msgs[] = "CLIENTE: " . mb_substr($last_user_msg, 0, 400);
        $compact = implode("\n", $last_msgs);

        // Heurística simples: intenção / urgência / sentimento
        $intent = 'outro';
        $s = mb_strtolower($last_user_msg);
        if (preg_match('/\\b(preço|preco|valor|quanto|orçamento|orcamento|plano)\\b/u', $s)) $intent = 'preço';
        elseif (preg_match('/\\b(agendar|agenda|horário|horario|reunião|reuniao|call)\\b/u', $s)) $intent = 'agendamento';
        elseif (preg_match('/\\b(problema|erro|não funciona|nao funciona|bug|suporte)\\b/u', $s)) $intent = 'suporte';
        elseif (preg_match('/\\b(cancelar|reembolso|estorno)\\b/u', $s)) $intent = 'cancelamento/reembolso';

        $urgency = preg_match('/\\b(agora|urgente|hoje|imediato)\\b/u', $s) ? 'alta' : 'normal';
        $sentiment = preg_match('/\\b(lixo|ridículo|ridiculo|golpe|roubo|raiva|irritado)\\b/u', $s) ? 'negativo' : 'neutro';

        $summary = "intent={$intent}\nurgency={$urgency}\nsentiment={$sentiment}\n\núltimas_mensagens:\n{$compact}\n";

        $pdo->prepare(
            "INSERT INTO handoff_summaries (agent_id, contact_jid, intent, urgency, sentiment, summary_text)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$agent_id, $contact_jid, $intent, $urgency, $sentiment, $summary]);

        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Throttle de logs/ações de fallback: limpeza lazy.
 */
function pruneFallbackThrottle(PDO $pdo, int $keepDays = 3): void {
    try {
        $pdo->prepare("DELETE FROM webhook_fallback_throttle WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)")
            ->execute([$keepDays]);
    } catch (PDOException $e) { error_log('[Auvvo] webhook fallback cleanup: ' . $e->getMessage()); }
}

/**
 * Extrai o marcador de agendamento da resposta da IA.
 * Retorna ['clean_text'=>string, 'payload'=>array] ou null.
 */
function extractGcalDirective(string $aiText): ?array {
    $pos = strrpos($aiText, '[[GCAL_EVENT]]');
    if ($pos === false) {
        return null;
    }
    $tail = substr($aiText, $pos);
    if (!preg_match('/\\[\\[GCAL_EVENT\\]\\]\\s*(\\{[\\s\\S]*\\})/u', $tail, $m)) {
        return null;
    }

    $json = trim($m[1] ?? '');
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        return null;
    }

    $clean = trim(substr($aiText, 0, $pos) . preg_replace('/\\[\\[GCAL_EVENT\\]\\]\\s*\\{[\\s\\S]*\\}/u', '', $tail));

    return ['clean_text' => $clean, 'payload' => $payload];
}
?>
