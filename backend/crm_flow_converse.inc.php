<?php
declare(strict_types=1);

require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/crm_automation_runs.inc.php';
require_once __DIR__ . '/crm_automation_motor.inc.php';
require_once __DIR__ . '/crm_flow_agent.inc.php';
require_once __DIR__ . '/context_memory.inc.php';
require_once __DIR__ . '/whatsapp_connections.inc.php';

const AUVVO_FLOW_CONVERSE_MEM_KEY = '_flow_converse';

/** Helpers LLM/log (getConversationHistory, callOpenAI, …) — webhook carrega ai_reply; fluxo precisa do inverso. */
function auvvo_flow_ai_bootstrap(): void
{
    if (function_exists('getConversationHistory')) {
        return;
    }
    if (!defined('AUVVO_WEBHOOK_SKIP_HTTP_ROUTER')) {
        define('AUVVO_WEBHOOK_SKIP_HTTP_ROUTER', true);
    }
    require_once __DIR__ . '/webhook_evolution.php';
}

/**
 * @return array<string,mixed>|null
 */
function auvvo_flow_converse_get(PDO $pdo, int $userId, array $contact): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $mem = auvvo_flow_contact_memory($pdo, $userId, $contact);
    $sess = $mem[AUVVO_FLOW_CONVERSE_MEM_KEY] ?? null;
    if (!is_array($sess) || empty($sess['active'])) {
        return null;
    }

    return $sess;
}

/**
 * @param array<string,mixed> $session
 */
function auvvo_flow_converse_save(PDO $pdo, int $userId, string $jid, array $session): void
{
    $jid = auvvo_canonical_whatsapp_jid($jid);
    if ($userId <= 0 || $jid === '') {
        return;
    }
    auvvo_contact_memory_merge($pdo, $userId, $jid, [AUVVO_FLOW_CONVERSE_MEM_KEY => $session]);
}

function auvvo_flow_converse_end(PDO $pdo, int $userId, array &$contact, string $reason = ''): void
{
    $jid = auvvo_flow_contact_memory_jid($contact);
    if ($jid === '') {
        return;
    }
    auvvo_contact_memory_merge($pdo, $userId, $jid, [
        AUVVO_FLOW_CONVERSE_MEM_KEY => ['active' => false, 'ended_at' => date('c'), 'reason' => $reason],
        '_brain_mission' => null,
    ]);
    require_once __DIR__ . '/crm_automation_motor.inc.php';
    $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);
}

function auvvo_flow_converse_should_end(array $session, string $messageBody): ?string
{
    $maxTurns = (int) ($session['max_turns'] ?? 0);
    $turns = (int) ($session['turns'] ?? 0);
    if ($maxTurns > 0 && $turns >= $maxTurns) {
        return 'max_turns';
    }
    $endKw = trim((string) ($session['end_keywords'] ?? ''));
    if ($endKw !== '' && $messageBody !== '') {
        foreach (preg_split('/\s*,\s*/', $endKw) as $kw) {
            $kw = trim($kw);
            if ($kw !== '' && stripos($messageBody, $kw) !== false) {
                return 'keyword:' . $kw;
            }
        }
    }

    return null;
}

/**
 * @param array<string,mixed> $data
 * @return array{ok:bool,detail:string,response?:string}
 */
function auvvo_flow_run_converse_node(
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
    $instructions = trim((string) ($data['instructions'] ?? $data['mission'] ?? ''));
    $maxTurns = max(0, min(100, (int) ($data['max_turns'] ?? 30)));
    $endKeywords = trim((string) ($data['end_keywords'] ?? 'tchau,obrigado,encerrar,finalizar'));
    $endTag = trim((string) ($data['end_tag'] ?? ''));
    $body = trim((string) ($context['message_body'] ?? ''));
    $simulate = auvvo_automation_is_simulate($context);
    $useLlm = !empty($context['simulate_use_llm']);

    if ($agentId <= 0) {
        return ['ok' => false, 'detail' => 'Agente IA não configurado'];
    }
    if ($instructions === '') {
        return ['ok' => false, 'detail' => 'Instruções vazias — descreva como o atendente deve conduzir a conversa'];
    }
    if (empty($contact['jid'])) {
        return ['ok' => false, 'detail' => 'Contato sem JID WhatsApp'];
    }

    $instructionsRendered = auvvo_crm_render_message($pdo, $instructions, $contact, $context);
    $session = [
        'active'        => true,
        'flow_id'       => (int) ($context['flow_id'] ?? 0),
        'node_id'       => $nodeId,
        'agent_id'      => $agentId,
        'connection_id' => $connectionId,
        'instructions'  => $instructionsRendered,
        'max_turns'     => $maxTurns,
        'turns'         => 0,
        'end_keywords'  => $endKeywords,
        'end_tag'       => $endTag,
        'started_at'    => date('c'),
    ];

    if ($simulate) {
        $run = auvvo_automation_run_ctx($context);
        if ($run && !empty($run['id'])) {
            auvvo_flow_converse_save_sim($pdo, (int) $run['id'], $session);
        }
        if (!$useLlm) {
            return [
                'ok' => true,
                'detail' => 'Atendimento fluido iniciado (simulado) — envie mais mensagens no teste',
                'response' => '[Simulação] IA responderia com contexto das próximas mensagens. Ative «Usar IA real».',
            ];
        }
    } else {
        $saveJid = auvvo_flow_contact_memory_jid($contact);
        if ($saveJid === '') {
            return ['ok' => false, 'detail' => 'Contato sem JID WhatsApp'];
        }
        auvvo_flow_converse_save($pdo, $userId, $saveJid, $session);
        auvvo_contact_memory_merge($pdo, $userId, $saveJid, ['_brain_mission' => $instructionsRendered]);
        $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);
    }

    $deferReply = !empty($context['_converse_defer_reply']);
    unset($context['_converse_defer_reply']);

    if ($deferReply && !$simulate) {
        return [
            'ok' => true,
            'detail' => 'Atendimento IA armado — responde na próxima mensagem do lead',
        ];
    }

    $reply = auvvo_flow_converse_reply($pdo, $userId, $agentId, $connectionId, $contact, $body !== '' ? $body : 'Olá', $instructionsRendered, $simulate, $useLlm);
    if (!$reply['ok']) {
        return ['ok' => false, 'detail' => $reply['error'] ?? 'Falha na resposta IA'];
    }

    if (!$simulate) {
        $session['turns'] = 1;
        $saveJid = auvvo_flow_contact_memory_jid($contact);
        if ($saveJid !== '') {
            auvvo_flow_converse_save($pdo, $userId, $saveJid, $session);
        }
        auvvo_automation_mark_ai_handled();
    } else {
        $run = auvvo_automation_run_ctx($context);
        if ($run && !empty($run['id'])) {
            $session['turns'] = 1;
            auvvo_flow_converse_save_sim($pdo, (int) $run['id'], $session);
        }
    }

    return [
        'ok' => true,
        'detail' => 'Atendimento fluido ativo — próximas mensagens usam histórico completo',
        'response' => (string) ($reply['text'] ?? ''),
    ];
}

/**
 * @return array{handled:bool,ai_handled:bool,ended:bool,reason?:string}
 */
function auvvo_flow_converse_inbound(
    PDO $pdo,
    int $userId,
    array &$contact,
    string $messageBody,
    int $connectionId = 0
): array {
    $sess = auvvo_flow_converse_get($pdo, $userId, $contact);
    if (!$sess) {
        return ['handled' => false, 'ai_handled' => false, 'ended' => false];
    }

    $endReason = auvvo_flow_converse_should_end($sess, $messageBody);
    if ($endReason !== null) {
        auvvo_flow_converse_end($pdo, $userId, $contact, $endReason);
        $endTag = trim((string) ($sess['end_tag'] ?? ''));
        if ($endTag !== '' && !empty($contact['id'])) {
            require_once __DIR__ . '/Contacts.php';
            (new Contacts($pdo))->addTag($userId, (int) $contact['id'], $endTag, false);
            $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);
        }

        return ['handled' => true, 'ai_handled' => false, 'ended' => true, 'reason' => $endReason];
    }

    $agentId = (int) ($sess['agent_id'] ?? 0);
    $connId = (int) ($sess['connection_id'] ?? $connectionId);
    $instructions = (string) ($sess['instructions'] ?? '');

    $reply = auvvo_flow_converse_reply($pdo, $userId, $agentId, $connId, $contact, $messageBody, $instructions, false, true);
    if (!$reply['ok']) {
        $err = trim((string) ($reply['error'] ?? ''));
        error_log('[Auvvo] converse_inbound failed: ' . ($err !== '' ? $err : 'unknown'));
        auvvo_flow_ai_bootstrap();
        $brain = auvvo_whatsapp_load_agent_brain($pdo, $userId, $agentId);
        if ($brain && $connId > 0) {
            $conn = auvvo_whatsapp_connection_get($pdo, $userId, $connId);
            if ($conn) {
                $brain = auvvo_whatsapp_attach_connection_to_agent($brain, $conn);
            }
        }
        if (is_array($brain) && !empty($brain['evolution_token']) && !empty($contact['jid'])) {
            $fallback = 'Recebi sua mensagem! Só um instante — qual horário amanhã funciona melhor para você?';
            sendEvolutionMessage(
                (string) $brain['evolution_token'],
                (string) ($brain['evolution_instance'] ?? ''),
                (string) $contact['jid'],
                $fallback
            );
            logConversation($pdo, $agentId, (string) $contact['jid'], $messageBody, $fallback, 'fallback');
        }

        return ['handled' => true, 'ai_handled' => true, 'ended' => false];
    }

    $sess['turns'] = (int) ($sess['turns'] ?? 0) + 1;
    $saveJid = auvvo_flow_contact_memory_jid($contact);
    if ($saveJid !== '') {
        auvvo_flow_converse_save($pdo, $userId, $saveJid, $sess);
    }
    auvvo_automation_mark_flow_handled();
    auvvo_automation_mark_ai_handled();

    return ['handled' => true, 'ai_handled' => true, 'ended' => false];
}

/**
 * @return array{ok:bool,text?:string,error?:string}
 */
function auvvo_flow_converse_reply(
    PDO $pdo,
    int $userId,
    int $agentId,
    int $connectionId,
    array $contact,
    string $body,
    string $mission,
    bool $simulate,
    bool $useLlm
): array {
    $brain = auvvo_whatsapp_load_agent_brain($pdo, $userId, $agentId);
    if (!$brain) {
        return ['ok' => false, 'error' => 'Agente não encontrado'];
    }
    if ($connectionId > 0) {
        $conn = auvvo_whatsapp_connection_get($pdo, $userId, $connectionId);
        if ($conn) {
            $brain = auvvo_whatsapp_attach_connection_to_agent($brain, $conn);
        }
    }

    if ($simulate && !$useLlm) {
        return ['ok' => true, 'text' => '[Resposta simulada com contexto da sessão]'];
    }

    if ($simulate && $useLlm) {
        $stmt = $pdo->prepare('SELECT openai_key, gemini_key, company_name, company_niche, company_site FROM settings WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $jid = (string) ($contact['jid'] ?? '5511999998888@s.whatsapp.net');
        $text = auvvo_flow_agent_preview_llm($pdo, $brain, $settings, $body, $jid, $mission);

        return ['ok' => true, 'text' => $text];
    }

    if (empty($brain['evolution_token'])) {
        return ['ok' => false, 'error' => 'WhatsApp sem token'];
    }

    $stmt = $pdo->prepare(
        'SELECT openai_key, gemini_key, elevenlabs_key, company_name, company_niche, company_site,
                google_calendar_enabled, google_calendar_calendar_id
         FROM settings WHERE user_id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $llm = auvvo_flow_agent_resolve_llm($pdo, $brain, $settings);
    if ($llm['key'] === '') {
        return ['ok' => false, 'error' => 'Chave IA não configurada'];
    }

    $jid = (string) ($contact['jid'] ?? '');
    $canonicalJid = function_exists('auvvo_canonical_whatsapp_jid')
        ? auvvo_canonical_whatsapp_jid($jid)
        : $jid;
    if ($mission !== '') {
        auvvo_contact_memory_merge($pdo, $userId, $canonicalJid, ['_brain_mission' => $mission]);
    }

    auvvo_flow_ai_bootstrap();
    require_once __DIR__ . '/ai_reply.inc.php';
    require_once __DIR__ . '/auvvo_scheduling.inc.php';
    auvvo_scheduling_process_inbound($pdo, $userId, $canonicalJid, $body);
    $peerDigits = auvvo_whatsapp_peer_digits($canonicalJid);
    $GLOBALS['auvvo_worker_start_time'] = time();

    try {
        auvvo_run_ai_reply(
            $pdo,
            $brain,
            $settings,
            $llm['key'],
            $canonicalJid,
            $jid !== '' ? $jid : $canonicalJid,
            $peerDigits,
            $body,
            null,
            (string) ($brain['evolution_instance'] ?? '')
        );

        return ['ok' => true, 'text' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** @param array<string,mixed> $session */
function auvvo_flow_converse_save_sim(PDO $pdo, int $runId, array $session): void
{
    try {
        $st = $pdo->prepare('SELECT meta_json FROM crm_automation_runs WHERE id = ? LIMIT 1');
        $st->execute([$runId]);
        $meta = json_decode((string) ($st->fetchColumn() ?: '{}'), true);
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta['converse'] = $session;
        $pdo->prepare('UPDATE crm_automation_runs SET meta_json = ?, status = ? WHERE id = ?')
            ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), 'paused', $runId]);
    } catch (PDOException $e) {
        error_log('[Auvvo] converse_save_sim run=' . $runId . ': ' . $e->getMessage());
    }
}

/**
 * @return array{handled:bool,resumed?:bool,run_id?:int,ended?:bool}
 */
function auvvo_flow_converse_simulate_continue(
    PDO $pdo,
    int $userId,
    int $runId,
    string $messageBody,
    array &$contact,
    array $context
): array {
    $st = $pdo->prepare('SELECT meta_json FROM crm_automation_runs WHERE id = ? AND user_id = ? AND mode = ? LIMIT 1');
    $st->execute([$runId, $userId, 'simulate']);
    $run = $st->fetch(PDO::FETCH_ASSOC);
    if (!$run) {
        return ['handled' => false];
    }
    $meta = json_decode((string) ($run['meta_json'] ?? '{}'), true);
    $sess = is_array($meta['converse'] ?? null) ? $meta['converse'] : null;
    if (!$sess || empty($sess['active'])) {
        return ['handled' => false];
    }

    $endReason = auvvo_flow_converse_should_end($sess, $messageBody);
    if ($endReason !== null) {
        $meta['converse']['active'] = false;
        $pdo->prepare('UPDATE crm_automation_runs SET meta_json = ?, status = ? WHERE id = ?')
            ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), 'done', $runId]);
        auvvo_automation_run_log_step($pdo, $context, (string) ($sess['node_id'] ?? ''), 'flow_converse', 'Atendimento fluido', 'ok', 'Sessão encerrada: ' . $endReason);

        return ['handled' => true, 'resumed' => true, 'run_id' => $runId, 'ended' => true];
    }

    $useLlm = !empty($context['simulate_use_llm']);
    $reply = auvvo_flow_converse_reply(
        $pdo,
        $userId,
        (int) ($sess['agent_id'] ?? 0),
        (int) ($sess['connection_id'] ?? 0),
        $contact,
        $messageBody,
        (string) ($sess['instructions'] ?? ''),
        true,
        $useLlm
    );

    $context['automation_run'] = ['id' => $runId, 'simulate' => true, 'step_order' => 999];
    $detail = 'Atendimento fluido (turno ' . ((int) ($sess['turns'] ?? 0) + 1) . ')';
    $stepDetail = $detail . (($reply['text'] ?? '') !== '' ? "\n" . $reply['text'] : '');

    auvvo_automation_run_log_step($pdo, $context, (string) ($sess['node_id'] ?? ''), 'flow_converse', 'Atendimento fluido', 'simulated', $stepDetail);

    $sess['turns'] = (int) ($sess['turns'] ?? 0) + 1;
    $meta['converse'] = $sess;
    $pdo->prepare('UPDATE crm_automation_runs SET meta_json = ?, status = ? WHERE id = ?')
        ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), 'paused', $runId]);

    return ['handled' => true, 'resumed' => true, 'run_id' => $runId];
}
