<?php
declare(strict_types=1);

require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/crm_automation_runs.inc.php';
require_once __DIR__ . '/crm_automation_motor.inc.php';
require_once __DIR__ . '/whatsapp_connections.inc.php';

function auvvo_automation_mark_ai_handled(): void
{
    $GLOBALS['auvvo_automation_ai_handled'] = true;
}

function auvvo_automation_ai_was_handled(): bool
{
    return !empty($GLOBALS['auvvo_automation_ai_handled']);
}

/** Fluxo publicado executou ao menos um passo (mensagem, IA, pausa, etc.). */
function auvvo_automation_mark_flow_handled(): void
{
    $GLOBALS['auvvo_automation_flow_handled'] = true;
    auvvo_automation_mark_ai_handled();
}

function auvvo_automation_flow_was_handled(): bool
{
    return !empty($GLOBALS['auvvo_automation_flow_handled']);
}

const AUVVO_FLOW_SESSION_MEM_KEY = '_flow_automation_session';

/**
 * Sessão ativa de fluxo (converse, wait ou think em andamento).
 *
 * @return array<string,mixed>|null
 */
function auvvo_flow_session_get(PDO $pdo, int $userId, array $contact): ?array
{
    if ($userId <= 0) {
        return null;
    }
    require_once __DIR__ . '/context_memory.inc.php';
    $mem = auvvo_flow_contact_memory($pdo, $userId, $contact);
    $sess = $mem[AUVVO_FLOW_SESSION_MEM_KEY] ?? null;

    return is_array($sess) && !empty($sess['active']) ? $sess : null;
}

/**
 * @param array<string,mixed> $session
 */
function auvvo_flow_session_save(PDO $pdo, int $userId, string $jid, array $session): void
{
    require_once __DIR__ . '/context_memory.inc.php';
    $jid = auvvo_canonical_whatsapp_jid($jid);
    if ($userId <= 0 || $jid === '') {
        return;
    }
    auvvo_contact_memory_merge($pdo, $userId, $jid, [AUVVO_FLOW_SESSION_MEM_KEY => $session]);
}

function auvvo_flow_session_end(PDO $pdo, int $userId, array &$contact, string $reason = ''): void
{
    require_once __DIR__ . '/context_memory.inc.php';
    require_once __DIR__ . '/crm_flow_converse.inc.php';
    $jid = auvvo_flow_contact_memory_jid($contact);
    if ($jid === '') {
        return;
    }
    require_once __DIR__ . '/context_memory.inc.php';
    $merge = [
        AUVVO_FLOW_SESSION_MEM_KEY => ['active' => false, 'ended_at' => date('c'), 'reason' => $reason],
    ];
    $converse = auvvo_flow_converse_get($pdo, $userId, $contact);
    if ($converse === null) {
        $merge['_brain_mission'] = null;
    }
    auvvo_contact_memory_merge($pdo, $userId, $jid, $merge);
    require_once __DIR__ . '/crm_automation_motor.inc.php';
    $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);
}

/**
 * @param array<string,mixed> $contact
 * @param array<string,mixed>|null $connection
 */
function auvvo_automation_has_active_flow_binding(PDO $pdo, int $userId, array $contact): bool
{
    if ($userId <= 0) {
        return false;
    }
    require_once __DIR__ . '/crm_flow_converse.inc.php';
    if (auvvo_flow_converse_get($pdo, $userId, $contact) !== null) {
        return true;
    }
    if (auvvo_flow_session_get($pdo, $userId, $contact) !== null) {
        return true;
    }
    $contactId = (int) ($contact['id'] ?? 0);
    if ($contactId <= 0) {
        return false;
    }
    try {
        auvvo_run_migrations($pdo);
        $st = $pdo->prepare(
            'SELECT id FROM crm_automation_wait_states
             WHERE user_id = ? AND contact_id = ? AND status = ? LIMIT 1'
        );
        $st->execute([$userId, $contactId, 'waiting']);

        return (bool) $st->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Modo da conexão WhatsApp: standalone (só agente), flows_first (fluxo > agente), flows_only (sem agente livre).
 */
function auvvo_whatsapp_connection_ai_mode(?array $connection): string
{
    $mode = strtolower(trim((string) ($connection['ai_mode'] ?? 'flows_first')));
    if (!in_array($mode, ['standalone', 'flows_first', 'flows_only'], true)) {
        return 'flows_first';
    }

    return $mode;
}

/**
 * Há fluxo publicado cujo gatilho WhatsApp bate com esta conexão?
 */
function auvvo_flow_has_published_for_connection(PDO $pdo, int $userId, int $connectionId): bool
{
    if ($userId <= 0 || $connectionId <= 0) {
        return false;
    }
    try {
        auvvo_run_migrations($pdo);
        $st = $pdo->prepare(
            'SELECT id, flow_data FROM crm_automation_flows
             WHERE user_id = ? AND is_active = 1'
        );
        $st->execute([$userId]);
        require_once __DIR__ . '/crm_flow_engine.inc.php';
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $nodes = auvvo_flow_parse_nodes((string) ($row['flow_data'] ?? ''));
            foreach ($nodes as $node) {
                if (auvvo_flow_node_type($node) !== 'flow_trigger') {
                    continue;
                }
                $data = is_array($node['data'] ?? null) ? $node['data'] : [];
                $tt = (string) ($data['trigger_type'] ?? '');
                if (!in_array($tt, ['whatsapp_first', 'whatsapp_message'], true)) {
                    continue;
                }
                if (auvvo_flow_trigger_matches($data, $tt, (string) $connectionId, $pdo, $userId)) {
                    return true;
                }
            }
        }
    } catch (PDOException $e) {
        return false;
    }

    return false;
}

/**
 * Impede agente standalone quando automação/fluxo já tratou ou deve ter prioridade.
 *
 * @param array<string,mixed> $contact
 * @param array<string,mixed>|null $connection
 */
function auvvo_automation_should_block_standalone(
    PDO $pdo,
    int $userId,
    array $contact,
    ?array $connection = null
): bool {
    $mode = auvvo_whatsapp_connection_ai_mode($connection);

    if ($mode === 'standalone') {
        return false;
    }

    if (auvvo_automation_flow_was_handled() || auvvo_automation_ai_was_handled()) {
        return true;
    }

    if (auvvo_automation_has_active_flow_binding($pdo, $userId, $contact)) {
        return true;
    }

    $contactId = (int) ($contact['id'] ?? 0);
    if ($contactId > 0) {
        require_once __DIR__ . '/crm_flow_wait_reply.inc.php';
        if (auvvo_flow_contact_has_active_wait($pdo, $userId, $contactId)) {
            return true;
        }
        require_once __DIR__ . '/crm_flow_converse.inc.php';
        if (auvvo_flow_converse_get($pdo, $userId, $contact) !== null) {
            return true;
        }
    }

    if ($mode === 'flows_only') {
        return true;
    }

    // flows_first: fluxo/automação tem prioridade sobre agente livre
    $connectionId = (int) ($connection['id'] ?? 0);
    if ($connectionId > 0 && auvvo_automation_contact_in_flow_scope($pdo, $userId, $contact, $connectionId)) {
        return true;
    }

    return false;
}

/**
 * Lead já entrou em fluxo publicado nesta linha (dedupe, missão ou sessão).
 *
 * @param array<string,mixed> $contact
 */
function auvvo_automation_contact_in_flow_scope(
    PDO $pdo,
    int $userId,
    array $contact,
    int $connectionId
): bool {
    if ($userId <= 0 || $connectionId <= 0) {
        return false;
    }
    $jid = trim((string) ($contact['jid'] ?? ''));
    if ($jid !== '') {
        require_once __DIR__ . '/context_memory.inc.php';
        $mem = auvvo_contact_memory_get($pdo, $userId, $jid);
        if (trim((string) ($mem['_brain_mission'] ?? '')) !== '') {
            return true;
        }
    }
    $contactId = (int) ($contact['id'] ?? 0);
    if ($contactId <= 0) {
        return false;
    }

    return auvvo_automation_contact_has_flow_dedupe_for_connection($pdo, $userId, $contactId, $connectionId);
}

function auvvo_automation_contact_has_flow_dedupe_for_connection(
    PDO $pdo,
    int $userId,
    int $contactId,
    int $connectionId
): bool {
    if ($userId <= 0 || $contactId <= 0 || $connectionId <= 0) {
        return false;
    }
    try {
        auvvo_run_migrations($pdo);
        $st = $pdo->prepare(
            'SELECT 1 FROM crm_automation_dedupe
             WHERE user_id = ? AND contact_id = ?
               AND (
                 dedupe_key LIKE ?
                 OR dedupe_key LIKE ?
                 OR dedupe_key = ?
               )
             LIMIT 1'
        );
        $suffix = (string) $connectionId;
        $st->execute([
            $userId,
            $contactId,
            'flow:%:whatsapp_first:' . $suffix,
            'flow:%:whatsapp_message:' . $suffix,
            'flow:%:wa_inbound:conn:' . $suffix,
        ]);

        return (bool) $st->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * @return array{flow_id:int,node_id:string,agent_id:int,connection_id:int,instructions:string}|null
 */
function auvvo_flow_find_think_scope_for_connection(PDO $pdo, int $userId, int $connectionId): ?array
{
    if ($userId <= 0 || $connectionId <= 0) {
        return null;
    }
    require_once __DIR__ . '/crm_flow_engine.inc.php';
    try {
        auvvo_run_migrations($pdo);
        $st = $pdo->prepare(
            'SELECT id, flow_data FROM crm_automation_flows WHERE user_id = ? AND is_active = 1'
        );
        $st->execute([$userId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $flowId = (int) ($row['id'] ?? 0);
            $nodes = auvvo_flow_parse_nodes((string) ($row['flow_data'] ?? ''));
            $triggerOk = false;
            foreach ($nodes as $node) {
                if (auvvo_flow_node_type($node) !== 'flow_trigger') {
                    continue;
                }
                $data = is_array($node['data'] ?? null) ? $node['data'] : [];
                foreach (['whatsapp_first', 'whatsapp_message'] as $tt) {
                    if (auvvo_flow_trigger_matches($data, $tt, (string) $connectionId, $pdo, $userId)) {
                        $triggerOk = true;
                        break 2;
                    }
                }
            }
            if (!$triggerOk) {
                continue;
            }
            foreach ($nodes as $nodeId => $node) {
                $class = auvvo_flow_node_type($node);
                if (!in_array($class, ['flow_think', 'flow_converse'], true)) {
                    continue;
                }
                $data = is_array($node['data'] ?? null) ? $node['data'] : [];
                $instructions = trim((string) ($data['instructions'] ?? $data['mission'] ?? ''));
                if ($instructions === '') {
                    continue;
                }

                return [
                    'flow_id'       => $flowId,
                    'node_id'       => (string) $nodeId,
                    'agent_id'      => (int) ($data['agent_id'] ?? 0),
                    'connection_id' => (int) ($data['connection_id'] ?? 0),
                    'instructions'  => $instructions,
                ];
            }
        }
    } catch (PDOException $e) {
        return null;
    }

    return null;
}

/**
 * Recupera atendimento via fluxo quando dedupe/sessão impediram reentrada no motor visual.
 *
 * @param array<string,mixed> $contact
 * @param array<string,mixed>|null $connection
 * @return array{handled:bool,ai_handled:bool}
 */
function auvvo_flow_recover_inbound(
    PDO $pdo,
    int $userId,
    array &$contact,
    string $messageBody,
    int $connectionId,
    ?array $connection = null
): array {
    $mode = auvvo_whatsapp_connection_ai_mode($connection);
    if ($mode === 'standalone') {
        return ['handled' => false, 'ai_handled' => false];
    }

    require_once __DIR__ . '/context_memory.inc.php';
    require_once __DIR__ . '/crm_flow_converse.inc.php';

    $jid = trim((string) ($contact['jid'] ?? ''));
    if ($jid === '') {
        return ['handled' => false, 'ai_handled' => false];
    }

    $mem = auvvo_contact_memory_get($pdo, $userId, $jid);
    $mission = trim((string) ($mem['_brain_mission'] ?? ''));
    $agentId = 0;
    $connId = $connectionId;

    if ($mission === '' && $connectionId > 0) {
        $contactId = (int) ($contact['id'] ?? 0);
        if ($contactId <= 0 || !auvvo_automation_contact_has_flow_dedupe_for_connection($pdo, $userId, $contactId, $connectionId)) {
            return ['handled' => false, 'ai_handled' => false];
        }
        $scope = auvvo_flow_find_think_scope_for_connection($pdo, $userId, $connectionId);
        if ($scope) {
            $mission = auvvo_crm_render_message($pdo, (string) $scope['instructions'], $contact, [
                'whatsapp_connection_id' => $connectionId,
            ]);
            $agentId = (int) ($scope['agent_id'] ?? 0);
            $connId = (int) ($scope['connection_id'] ?? 0) ?: $connectionId;
            auvvo_contact_memory_merge($pdo, $userId, $jid, ['_brain_mission' => $mission]);
            auvvo_flow_converse_save($pdo, $userId, $jid, [
                'active'        => true,
                'flow_id'       => (int) ($scope['flow_id'] ?? 0),
                'node_id'       => (string) ($scope['node_id'] ?? ''),
                'agent_id'      => $agentId,
                'connection_id' => $connId,
                'instructions'  => $mission,
                'max_turns'     => 30,
                'turns'         => 0,
                'end_keywords'  => 'tchau,obrigado,encerrar,finalizar',
                'end_tag'       => '',
                'started_at'    => date('c'),
            ]);
            $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);
        }
    }

    if ($mission === '') {
        return ['handled' => false, 'ai_handled' => false];
    }

    $sess = auvvo_flow_converse_get($pdo, $userId, $contact);
    if ($sess) {
        $agentId = (int) ($sess['agent_id'] ?? $agentId);
        $connId = (int) ($sess['connection_id'] ?? $connId);
        $mission = (string) ($sess['instructions'] ?? $mission);
    } elseif ($agentId <= 0) {
        $agentId = (int) ($connection['default_agent_id'] ?? $contact['agent_id'] ?? 0);
    }

    if ($agentId <= 0) {
        return ['handled' => false, 'ai_handled' => false];
    }

    $reply = auvvo_flow_converse_reply(
        $pdo,
        $userId,
        $agentId,
        $connId > 0 ? $connId : $connectionId,
        $contact,
        $messageBody,
        $mission,
        false,
        true
    );

    if (!$reply['ok']) {
        return ['handled' => true, 'ai_handled' => false];
    }

    if ($sess) {
        $sess['turns'] = (int) ($sess['turns'] ?? 0) + 1;
        auvvo_flow_converse_save($pdo, $userId, $jid, $sess);
    } elseif ($mission !== '') {
        auvvo_flow_converse_save($pdo, $userId, $jid, [
            'active'        => true,
            'flow_id'       => 0,
            'node_id'       => '',
            'agent_id'      => $agentId,
            'connection_id' => $connId > 0 ? $connId : $connectionId,
            'instructions'  => $mission,
            'max_turns'     => 30,
            'turns'         => 1,
            'end_keywords'  => 'tchau,obrigado,encerrar,finalizar',
            'end_tag'       => '',
            'started_at'    => date('c'),
        ]);
    }

    auvvo_automation_mark_flow_handled();
    auvvo_automation_mark_ai_handled();

    return ['handled' => true, 'ai_handled' => true];
}

/**
 * @return array{openai:bool,gemini:bool,openrouter:bool,model:string,key:string}
 */
function auvvo_flow_agent_resolve_llm(PDO $pdo, array $agent, array $settings): array
{
    $modelStr = trim((string) ($agent['model'] ?? ''));
    if ($modelStr === '') {
        $modelStr = defined('OPENROUTER_DEFAULT_MODEL') ? OPENROUTER_DEFAULT_MODEL : 'openrouter/openai/gpt-4o-mini';
    }
    $isGemini = strpos($modelStr, 'gemini') === 0;
    $isAuvvoAI = $modelStr === 'auvvo-ai';
    // auvvo-ai → DeepSeek V3 se configurado, senao OpenRouter
    if ($isAuvvoAI && auvvo_deepseek_configured()) {
        $isDeepSeek = true;
        $isOpenRouter = false;
        $modelStr = 'deepseek/chat';
    } else {
        $isDeepSeek = auvvo_is_deepseek_model($modelStr) !== '';
        $isOpenRouter = !$isGemini && !$isDeepSeek && (
            $isAuvvoAI
            || strpos($modelStr, 'openrouter/') === 0
            || strpos($modelStr, '/') !== false
        );
    }
    $geminiUserKey = trim($settings['gemini_key'] ?? '');
    $geminiEnvKey = defined('GEMINI_API_KEY') ? trim((string) GEMINI_API_KEY) : '';
    $effectiveGeminiKey = $geminiUserKey !== '' ? $geminiUserKey : $geminiEnvKey;
    $openRouterPlatformKey = defined('OPENROUTER_API_KEY') ? trim((string) OPENROUTER_API_KEY) : '';
    $deepSeekPlatformKey = auvvo_deepseek_configured() ? trim((string) DEEPSEEK_API_KEY) : '';

    if ($isGemini) {
        return ['openai' => false, 'gemini' => true, 'openrouter' => false, 'deepseek' => false, 'model' => $modelStr, 'key' => $effectiveGeminiKey];
    }
    if ($isDeepSeek) {
        return ['openai' => false, 'gemini' => false, 'openrouter' => false, 'deepseek' => true, 'model' => $modelStr, 'key' => $deepSeekPlatformKey];
    }
    if ($isOpenRouter) {
        return ['openai' => false, 'gemini' => false, 'openrouter' => true, 'deepseek' => false, 'model' => $modelStr, 'key' => $openRouterPlatformKey];
    }

    return ['openai' => true, 'gemini' => false, 'openrouter' => false, 'deepseek' => false, 'model' => $modelStr, 'key' => trim($settings['openai_key'] ?? '')];
}

/**
 * Preview LLM (simulador) — não envia WhatsApp.
 */
function auvvo_flow_agent_preview_llm(
    PDO $pdo,
    array $agent,
    array $settings,
    string $body,
    string $canonicalJid,
    string $mission = ''
): string {
    if (!defined('AUVVO_WEBHOOK_SKIP_HTTP_ROUTER')) {
        define('AUVVO_WEBHOOK_SKIP_HTTP_ROUTER', true);
    }
    require_once __DIR__ . '/webhook_evolution.php';

    $llm = auvvo_flow_agent_resolve_llm($pdo, $agent, $settings);
    if ($llm['key'] === '') {
        return '[Simulação] Chave de IA não configurada para este agente.';
    }

    if ($mission !== '') {
        require_once __DIR__ . '/context_memory.inc.php';
        auvvo_contact_memory_merge($pdo, (int) $agent['user_id'], $canonicalJid, ['_brain_mission' => $mission]);
    }

    $agentForPrompt = $agent;
    $agentForPrompt['_contact_jid'] = $canonicalJid;
    $builder = new MasterPromptBuilder($pdo);
    $systemPrompt = $builder->build($agentForPrompt, $settings);
    $history = getConversationHistory($pdo, (int) $agent['id'], $canonicalJid, 6);

    $response = callOpenAI(
        $llm['key'],
        $llm['model'],
        $systemPrompt,
        $body,
        $history,
        (int) ($agent['max_tokens'] ?? 800),
        (float) ($agent['temperature'] ?? 0.7),
        'auvvo-sim-' . (int) $agent['id']
    );

    if ($response === null || trim((string) $response) === '') {
        return '[Simulação] IA não retornou texto.';
    }

    require_once __DIR__ . '/auvvo_brain_tools.inc.php';
    $processed = auvvo_brain_process_llm_response(
        $pdo,
        (int) $agent['user_id'],
        $agent,
        $settings,
        (string) $response,
        $canonicalJid,
        null,
        null
    );

    return trim((string) $processed) !== '' ? (string) $processed : (string) $response;
}

/**
 * Executa nó Agente IA no fluxo.
 *
 * @param array<string,mixed> $data
 * @param array<string,mixed> $contact
 * @param array<string,mixed> $context
 * @return array{ok:bool,detail:string,response?:string}
 */
function auvvo_flow_run_agent_node(
    PDO $pdo,
    int $userId,
    array $data,
    array &$contact,
    array $context,
    string $nodeId,
    string $nodeLabel
): array {
    $agentId = auvvo_crm_resolve_agent_id((int) ($data['agent_id'] ?? 0), $contact, $context);
    $connectionId = auvvo_crm_resolve_whatsapp_connection_id(
        $pdo,
        $userId,
        (int) ($data['connection_id'] ?? 0),
        $agentId,
        $context,
        $contact
    );
    $mission = trim((string) ($data['mission'] ?? ''));
    $mode = (string) ($data['mode'] ?? 'respond');
    $body = trim((string) ($context['message_body'] ?? ''));
    $simulate = auvvo_automation_is_simulate($context);
    $useLlm = !empty($context['simulate_use_llm']);

    if ($agentId <= 0) {
        return ['ok' => false, 'detail' => 'Agente IA não configurado'];
    }

    $brain = auvvo_whatsapp_load_agent_brain($pdo, $userId, $agentId);
    if (!$brain) {
        return ['ok' => false, 'detail' => 'Agente #' . $agentId . ' não encontrado'];
    }

    if ($connectionId > 0) {
        $conn = auvvo_whatsapp_connection_get($pdo, $userId, $connectionId);
        if ($conn) {
            $brain = auvvo_whatsapp_attach_connection_to_agent($brain, $conn);
        }
    }

    $canonicalJid = (string) ($contact['jid'] ?? '');
    if ($canonicalJid === '') {
        $phone = preg_replace('/\D/', '', (string) ($contact['phone'] ?? ''));
        $canonicalJid = $phone !== '' ? ($phone . '@s.whatsapp.net') : '';
    }
    if ($canonicalJid === '') {
        error_log('[Auvvo] flow_agent: sem JID/telefone para o contato, pulando envio');
        return ['ok' => false, 'error' => 'no_contact_jid'];
    }

    $agentName = (string) ($brain['name'] ?? 'Agente');

    if ($simulate && !$useLlm) {
        $detail = 'Agente IA (simulado): ' . $agentName;
        if ($mission !== '') {
            $detail .= ' — missão: ' . mb_substr($mission, 0, 200);
        }
        if ($body !== '') {
            $detail .= ' — receberia: «' . mb_substr($body, 0, 120) . '»';
        }
        if ($mode === 'tools_only') {
            $detail .= ' [modo: só ferramentas]';
        }

        return ['ok' => true, 'detail' => $detail, 'response' => '[Resposta IA simulada — ative «Usar IA real» no teste]'];
    }

    $stmt = $pdo->prepare(
        'SELECT openai_key, gemini_key, elevenlabs_key, company_name, company_niche, company_site,
                google_calendar_enabled, google_calendar_calendar_id
         FROM settings WHERE user_id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    if ($mission !== '' && !empty($contact['jid'])) {
        require_once __DIR__ . '/context_memory.inc.php';
        auvvo_contact_memory_merge($pdo, $userId, (string) $contact['jid'], ['_brain_mission' => $mission]);
        $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);
    }

    if ($simulate && $useLlm) {
        try {
            $text = auvvo_flow_agent_preview_llm($pdo, $brain, $settings, $body !== '' ? $body : 'Olá', $canonicalJid, $mission);
            return ['ok' => true, 'detail' => 'Agente IA (preview LLM): ' . $agentName, 'response' => $text];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'Erro IA simulada: ' . $e->getMessage()];
        }
    }

    if (empty($brain['evolution_token'])) {
        return ['ok' => false, 'detail' => 'Conexão WhatsApp sem token para enviar resposta'];
    }

    $llm = auvvo_flow_agent_resolve_llm($pdo, $brain, $settings);
    if ($llm['key'] === '') {
        return ['ok' => false, 'detail' => 'Chave de IA não configurada'];
    }

    if ($body === '') {
        if ($mission !== '') {
            $body = $mission;
        } elseif ($mode === 'proactive') {
            $body = 'Olá';
        } else {
            return ['ok' => false, 'detail' => 'Sem mensagem de gatilho — use missão ou gatilho WhatsApp'];
        }
    }

    require_once __DIR__ . '/ai_reply.inc.php';

    $peerDigits = auvvo_whatsapp_peer_digits($canonicalJid);
    $instanceLabel = (string) ($brain['evolution_instance'] ?? '');
    $GLOBALS['auvvo_worker_start_time'] = time();

    try {
        auvvo_run_ai_reply(
            $pdo,
            $brain,
            $settings,
            $llm['key'],
            $canonicalJid,
            $canonicalJid,
            $peerDigits,
            $body,
            null,
            $instanceLabel
        );

        return [
            'ok' => true,
            'detail' => 'Agente IA respondeu via WhatsApp: ' . $agentName . ($mission !== '' ? ' (missão ativa)' : ''),
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'detail' => 'Falha agente IA: ' . $e->getMessage()];
    }
}

/**
 * Nó Pensar & Responder — IA gera N mensagens com instruções e envia no WhatsApp.
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,detail:string,response?:string}
 */
function auvvo_flow_run_think_node(
    PDO $pdo,
    int $userId,
    array $data,
    array &$contact,
    array $context,
    string $nodeId,
    string $nodeLabel
): array {
    $agentId = auvvo_crm_resolve_agent_id((int) ($data['agent_id'] ?? 0), $contact, $context);
    $connectionId = auvvo_crm_resolve_whatsapp_connection_id(
        $pdo,
        $userId,
        (int) ($data['connection_id'] ?? 0),
        $agentId,
        $context,
        $contact
    );
    $instructions = trim((string) ($data['instructions'] ?? ''));
    $messageCount = max(1, min(5, (int) ($data['message_count'] ?? 1)));
    $includeContext = !isset($data['include_context']) || !empty($data['include_context']);
    $memoryKey = trim((string) ($data['memory_key'] ?? ''));
    $sendWa = !isset($data['send_whatsapp']) || !empty($data['send_whatsapp']);
    $body = trim((string) ($context['message_body'] ?? ''));
    $simulate = auvvo_automation_is_simulate($context);
    $useLlm = !empty($context['simulate_use_llm']);

    if ($agentId <= 0) {
        return ['ok' => false, 'detail' => 'Agente IA não configurado'];
    }
    if ($instructions === '') {
        return ['ok' => false, 'detail' => 'Instruções vazias — descreva o que o agente deve pensar/responder'];
    }

    $brain = auvvo_whatsapp_load_agent_brain($pdo, $userId, $agentId);
    if (!$brain) {
        return ['ok' => false, 'detail' => 'Agente #' . $agentId . ' não encontrado'];
    }
    if ($connectionId > 0) {
        $conn = auvvo_whatsapp_connection_get($pdo, $userId, $connectionId);
        if ($conn) {
            $brain = auvvo_whatsapp_attach_connection_to_agent($brain, $conn);
        }
    }

    $agentName = (string) ($brain['name'] ?? 'Agente');
    $instructionsRendered = auvvo_crm_render_message($pdo, $instructions, $contact, $context);

    if ($simulate && !$useLlm) {
        $preview = '[Simulação] ' . $messageCount . ' msg(s) com instruções: «' . mb_substr($instructionsRendered, 0, 180) . '»';
        if ($body !== '' && $includeContext) {
            $preview .= ' — contexto: «' . mb_substr($body, 0, 80) . '»';
        }

        return ['ok' => true, 'detail' => 'Pensar & Responder (simulado): ' . $agentName, 'response' => $preview];
    }

    $stmt = $pdo->prepare(
        'SELECT openai_key, gemini_key, company_name, company_niche, company_site FROM settings WHERE user_id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $llm = auvvo_flow_agent_resolve_llm($pdo, $brain, $settings);
    if ($llm['key'] === '') {
        return ['ok' => false, 'detail' => 'Chave de IA não configurada'];
    }

    $contextBlock = '';
    if ($includeContext) {
        $vars = auvvo_crm_message_vars($pdo, $contact, $context);
        $parts = [];
        if ($body !== '') {
            $parts[] = 'Mensagem do lead: ' . $body;
        }
        if (!empty($vars['nome'])) {
            $parts[] = 'Nome: ' . $vars['nome'];
        }
        if (!empty($vars['estagio'])) {
            $parts[] = 'Estágio: ' . $vars['estagio'];
        }
        if (!empty($vars['tags'])) {
            $parts[] = 'Tags: ' . $vars['tags'];
        }
        $contextBlock = $parts !== [] ? implode("\n", $parts) : 'Sem contexto adicional.';
    }

    $prompt = "Você é o agente \"{$agentName}\" em um fluxo automatizado de WhatsApp.\n\n"
        . "INSTRUÇÕES DO FLUXO:\n{$instructionsRendered}\n\n";
    if ($includeContext) {
        $prompt .= "CONTEXTO DO LEAD:\n{$contextBlock}\n\n";
    }
    $prompt .= "Gere exatamente {$messageCount} mensagem(ns) separada(s) para enviar ao lead no WhatsApp.\n"
        . "Responda SOMENTE com JSON válido neste formato:\n"
        . "{\"messages\":[\"texto1\",\"texto2\"],\"reasoning\":\"breve nota interna\"}\n"
        . "Cada mensagem deve ser curta, natural e pronta para WhatsApp (sem markdown).";

    if (!defined('AUVVO_WEBHOOK_SKIP_HTTP_ROUTER')) {
        define('AUVVO_WEBHOOK_SKIP_HTTP_ROUTER', true);
    }
    require_once __DIR__ . '/webhook_evolution.php';

    $rawLlm = callOpenAI(
        $llm['key'],
        $llm['model'],
        'Você gera respostas estruturadas para automação WhatsApp. Sempre retorne JSON válido.',
        $prompt,
        [],
        1200,
        0.6,
        'auvvo-think-' . $agentId
    );

    if ($rawLlm === null || trim((string) $rawLlm) === '') {
        return ['ok' => false, 'detail' => 'IA não retornou resposta'];
    }

    $parsed = auvvo_flow_think_parse_llm_json((string) $rawLlm, $messageCount);
    if ($parsed['messages'] === []) {
        return ['ok' => false, 'detail' => 'IA retornou formato inválido: ' . mb_substr((string) $rawLlm, 0, 200)];
    }

    $messages = $parsed['messages'];
    $reasoning = $parsed['reasoning'];

    if ($memoryKey !== '' && !empty($contact['jid'])) {
        require_once __DIR__ . '/context_memory.inc.php';
        auvvo_contact_memory_merge($pdo, $userId, (string) $contact['jid'], [
            $memoryKey => json_encode(['messages' => $messages, 'reasoning' => $reasoning], JSON_UNESCAPED_UNICODE),
        ]);
        $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);
    }

    if ($simulate) {
        $joined = implode("\n---\n", $messages);
        $detail = 'Pensar & Responder (LLM): ' . $agentName;
        if ($reasoning !== '') {
            $detail .= ' — ' . mb_substr($reasoning, 0, 120);
        }

        return ['ok' => true, 'detail' => $detail, 'response' => $joined];
    }

    if (!$sendWa) {
        return [
            'ok' => true,
            'detail' => 'Pensamento gravado (' . count($messages) . ' msg) — envio WhatsApp desativado',
            'response' => implode("\n---\n", $messages),
        ];
    }

    if (empty($contact['jid'])) {
        return ['ok' => false, 'detail' => 'Contato sem JID para enviar mensagens'];
    }
    if (empty($brain['evolution_token'])) {
        return ['ok' => false, 'detail' => 'Conexão WhatsApp sem token'];
    }

    $sentLines = [];
    $failures = [];
    foreach ($messages as $i => $msgText) {
        $msgText = trim((string) $msgText);
        if ($msgText === '') {
            continue;
        }
        $send = auvvo_crm_send_whatsapp($pdo, $userId, [
            'connection_id' => $connectionId,
            'agent_id'      => $agentId,
            'message'       => $msgText,
        ], $contact, $context);
        if ($send['ok']) {
            $sentLines[] = $send['sent'];
            if ($i < count($messages) - 1) {
                usleep(800000);
            }
        } else {
            $failures[] = $send['error'];
        }
    }

    if ($sentLines === []) {
        return ['ok' => false, 'detail' => 'Nenhuma mensagem enviada: ' . implode('; ', $failures)];
    }

    $detail = count($sentLines) . ' mensagem(ns) enviada(s)';
    if ($reasoning !== '') {
        $detail .= ' — ' . mb_substr($reasoning, 0, 120);
    }
    if ($failures !== []) {
        $detail .= ' (parcial: ' . implode('; ', $failures) . ')';
    }

    return ['ok' => true, 'detail' => $detail, 'response' => implode("\n---\n", $sentLines)];
}

/**
 * @return array{messages:list<string>,reasoning:string}
 */
function auvvo_flow_think_parse_llm_json(string $raw, int $expectedCount): array
{
    $raw = trim($raw);
    if (preg_match('/\{[\s\S]*\}/', $raw, $m)) {
        $raw = $m[0];
    }
    $data = json_decode($raw, true);
    if (is_array($data) && !empty($data['messages']) && is_array($data['messages'])) {
        $msgs = [];
        foreach ($data['messages'] as $item) {
            $t = trim((string) $item);
            if ($t !== '') {
                $msgs[] = $t;
            }
            if (count($msgs) >= $expectedCount) {
                break;
            }
        }

        return [
            'messages'  => $msgs,
            'reasoning' => trim((string) ($data['reasoning'] ?? '')),
        ];
    }

    $lines = preg_split('/\r?\n+/', $raw) ?: [];
    $msgs = [];
    foreach ($lines as $line) {
        $line = trim(preg_replace('/^\d+[\.\)]\s*/', '', trim($line)) ?? '');
        if ($line !== '' && !str_starts_with($line, '{')) {
            $msgs[] = $line;
        }
        if (count($msgs) >= $expectedCount) {
            break;
        }
    }

    return ['messages' => $msgs, 'reasoning' => ''];
}

/**
 * Retoma fluxo pausado após mensagem de abertura (pending_think).
 *
 * @param array<string,mixed> $contact
 * @return array{handled:bool,ai_handled:bool,ended:bool}
 */
function auvvo_flow_session_inbound(
    PDO $pdo,
    int $userId,
    array &$contact,
    string $messageBody,
    int $connectionId = 0
): array {
    $sess = auvvo_flow_session_get($pdo, $userId, $contact);
    if (!$sess || ($sess['mode'] ?? '') !== 'pending_think') {
        return ['handled' => false, 'ai_handled' => false, 'ended' => false];
    }

    $flowId = (int) ($sess['flow_id'] ?? 0);
    $nodeId = (string) ($sess['node_id'] ?? '');
    $nodeData = is_array($sess['node_data'] ?? null) ? $sess['node_data'] : [];
    $nodeLabel = 'Pensar & Responder';

    if ($flowId <= 0 || $nodeId === '') {
        auvvo_flow_session_end($pdo, $userId, $contact, 'invalid_session');
        return ['handled' => true, 'ai_handled' => false, 'ended' => true];
    }

    require_once __DIR__ . '/crm_flow_engine.inc.php';
    $st = $pdo->prepare('SELECT flow_data FROM crm_automation_flows WHERE id = ? AND user_id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$flowId, $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        auvvo_flow_session_end($pdo, $userId, $contact, 'flow_inactive');
        return ['handled' => true, 'ai_handled' => false, 'ended' => true];
    }

    $nodes = auvvo_flow_parse_nodes((string) ($row['flow_data'] ?? ''));
    $context = [
        'message_body'           => $messageBody,
        'whatsapp_connection_id' => $connectionId > 0 ? $connectionId : (int) ($sess['connection_id'] ?? 0),
        'trigger_agent_id'       => (int) ($sess['agent_id'] ?? 0),
    ];

    auvvo_automation_mark_flow_handled();
    auvvo_automation_mark_ai_handled();

    $result = auvvo_flow_run_think_node($pdo, $userId, $nodeData, $contact, $context, $nodeId, $nodeLabel);
    $ok = (bool) ($result['ok'] ?? false);

    if ($ok) {
        $node = $nodes[$nodeId] ?? null;
        $next = is_array($node) ? auvvo_flow_next_node_ids($node, 'output_1') : [];
        if ($next !== []) {
            $triggerType = 'whatsapp_message';
            $triggerValue = $connectionId > 0 ? (string) $connectionId : '*';
            auvvo_flow_walk_many($pdo, $userId, $flowId, $nodes, $next, $contact, $triggerType, $triggerValue, $context, 0);
        }
        // Mantém missão ativa para respostas seguintes dentro do escopo do fluxo.
        require_once __DIR__ . '/crm_flow_converse.inc.php';
        $instructions = (string) ($sess['instructions'] ?? '');
        if ($instructions !== '' && !empty($contact['jid'])) {
            $saveJid = auvvo_flow_contact_memory_jid($contact);
            if ($saveJid !== '') {
                auvvo_flow_converse_save($pdo, $userId, $saveJid, [
                    'active'        => true,
                    'flow_id'       => $flowId,
                    'node_id'       => $nodeId,
                    'agent_id'      => (int) ($sess['agent_id'] ?? 0),
                    'connection_id' => (int) ($sess['connection_id'] ?? $connectionId),
                    'instructions'  => $instructions,
                    'max_turns'     => 30,
                    'turns'         => 1,
                    'end_keywords'  => 'tchau,obrigado,encerrar,finalizar',
                    'end_tag'       => '',
                    'started_at'    => date('c'),
                ]);
            }
        }
    }

    auvvo_flow_session_end($pdo, $userId, $contact, $ok ? 'think_done' : 'think_failed');

    return ['handled' => true, 'ai_handled' => true];
}

/**
 * Tenta retomar atendimento de fluxo (sessão pending_think, converse ou recover).
 *
 * @param array<string,mixed> $contact
 * @param array<string,mixed>|null $connection
 * @return array{handled:bool,ai_handled:bool,reason:string}
 */
function auvvo_flow_dispatch_active_inbound(
    PDO $pdo,
    int $userId,
    array &$contact,
    string $messageBody,
    int $connectionId,
    ?array $connection = null
): array {
    $sessionHit = auvvo_flow_session_inbound($pdo, $userId, $contact, $messageBody, $connectionId);
    if (!empty($sessionHit['handled'])) {
        return [
            'handled'    => true,
            'ai_handled' => !empty($sessionHit['ai_handled']),
            'reason'     => 'flow_session',
        ];
    }

    require_once __DIR__ . '/crm_flow_converse.inc.php';
    $converseHit = auvvo_flow_converse_inbound($pdo, $userId, $contact, $messageBody, $connectionId);
    if (!empty($converseHit['handled'])) {
        return [
            'handled'    => true,
            'ai_handled' => !empty($converseHit['ai_handled']),
            'reason'     => 'flow_converse',
        ];
    }

    $recoverHit = auvvo_flow_recover_inbound($pdo, $userId, $contact, $messageBody, $connectionId, $connection);
    if (!empty($recoverHit['handled'])) {
        return [
            'handled'    => true,
            'ai_handled' => !empty($recoverHit['ai_handled']),
            'reason'     => 'flow_recover',
        ];
    }

    return ['handled' => false, 'ai_handled' => false, 'reason' => ''];
}

/** @return array<string, array{in:int,ok:int,err:int}> */
function auvvo_automation_node_stats(PDO $pdo, int $userId, int $flowId): array
{
    if ($userId <= 0 || $flowId <= 0) {
        return [];
    }
    auvvo_run_migrations($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT s.node_id,
                    COUNT(*) AS cnt_in,
                    SUM(CASE WHEN s.status IN (\'ok\',\'simulated\') THEN 1 ELSE 0 END) AS cnt_ok,
                    SUM(CASE WHEN s.status = \'error\' THEN 1 ELSE 0 END) AS cnt_err
             FROM crm_automation_run_steps s
             INNER JOIN crm_automation_runs r ON r.id = s.run_id
             WHERE r.user_id = ? AND r.flow_id = ?
             GROUP BY s.node_id'
        );
        $st->execute([$userId, $flowId]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $nid = (string) ($row['node_id'] ?? '');
            if ($nid === '') {
                continue;
            }
            $out[$nid] = [
                'in' => (int) ($row['cnt_in'] ?? 0),
                'ok' => (int) ($row['cnt_ok'] ?? 0),
                'err' => (int) ($row['cnt_err'] ?? 0),
            ];
        }

        return $out;
    } catch (PDOException $e) {
        return [];
    }
}
