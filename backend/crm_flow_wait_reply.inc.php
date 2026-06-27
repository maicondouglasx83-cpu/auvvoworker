<?php
declare(strict_types=1);

require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/crm_flow_engine.inc.php';
require_once __DIR__ . '/crm_automation_runs.inc.php';

function auvvo_flow_contact_has_active_wait(PDO $pdo, int $userId, int $contactId): bool
{
    if ($userId <= 0 || $contactId <= 0) {
        return false;
    }
    auvvo_run_migrations($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT 1 FROM crm_automation_wait_states
             WHERE user_id = ? AND contact_id = ? AND status = ? LIMIT 1'
        );
        $st->execute([$userId, $contactId, 'waiting']);

        return (bool) $st->fetchColumn();
    } catch (PDOException $e) {
        error_log('[Auvvo] flow_contact_has_active_wait: ' . $e->getMessage());

        return false;
    }
}

function auvvo_flow_wait_reply_cancel_active(PDO $pdo, int $userId, int $contactId): void
{
    if ($userId <= 0 || $contactId <= 0) {
        return;
    }
    try {
        $pdo->prepare(
            'UPDATE crm_automation_wait_states SET status = ?, updated_at = NOW()
             WHERE user_id = ? AND contact_id = ? AND status = ?'
        )->execute(['cancelled', $userId, $contactId, 'waiting']);
    } catch (PDOException $e) {
        error_log('[Auvvo] wait_reply_cancel_active: ' . $e->getMessage());
    }
}

/**
 * @param array<string,mixed> $data
 * @param array<string,mixed> $contact
 * @param array<string,mixed> $context
 */
function auvvo_flow_wait_reply_pause(
    PDO $pdo,
    int $userId,
    int $flowId,
    string $nodeId,
    string $nodeLabel,
    array $data,
    array $contact,
    string $triggerType,
    string $triggerValue,
    array &$context
): string {
    $timeoutHours = max(1, min(168, (int) ($data['timeout_hours'] ?? 24)));
    $keyword = trim((string) ($data['keyword_contains'] ?? ''));
    $nodes = $context['_flow_nodes'] ?? [];
    $node = is_array($nodes[$nodeId] ?? null) ? $nodes[$nodeId] : [];
    $replyNodes = auvvo_flow_next_node_ids($node, 'output_1');
    $timeoutNodes = auvvo_flow_next_node_ids($node, 'output_2');

    $detail = "Aguardando resposta do lead (timeout {$timeoutHours}h)";
    if ($keyword !== '') {
        $detail .= " — filtro: «{$keyword}»";
    }

    if (auvvo_automation_is_simulate($context)) {
        auvvo_automation_run_log_step($pdo, $context, $nodeId, 'flow_wait_reply', $nodeLabel, 'simulated', $detail . ' (simulado — envie outra mensagem no teste)');
        $run = auvvo_automation_run_ctx($context);
        if ($run && !empty($run['id'])) {
            auvvo_flow_wait_reply_save_sim_pause($pdo, (int) $run['id'], $nodeId, $replyNodes, $timeoutNodes, $keyword, $flowId, $nodes, $context);
        }

        return 'paused';
    }

    $contactId = (int) ($contact['id'] ?? 0);
    if ($contactId <= 0) {
        auvvo_automation_run_log_step($pdo, $context, $nodeId, 'flow_wait_reply', $nodeLabel, 'error', 'Lead sem ID — impossível aguardar resposta');

        return 'ok';
    }

    $runId = (int) (($context['automation_run']['id'] ?? 0));
    auvvo_run_migrations($pdo);

    auvvo_flow_wait_reply_cancel_active($pdo, $userId, $contactId);

    $pausedOk = false;
    try {
        $pdo->prepare(
            'INSERT INTO crm_automation_wait_states
             (user_id, flow_id, run_id, contact_id, node_id, mode, keyword_filter, reply_node_ids, timeout_node_ids, timeout_at, status, meta_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $userId,
            $flowId,
            $runId > 0 ? $runId : null,
            $contactId,
            $nodeId,
            'live',
            $keyword !== '' ? $keyword : null,
            json_encode($replyNodes, JSON_UNESCAPED_UNICODE),
            json_encode($timeoutNodes, JSON_UNESCAPED_UNICODE),
            date('Y-m-d H:i:s', auvvo_unix_ts(time() + (int) ($timeoutHours * 3600))),
            'waiting',
            json_encode([
                'trigger_type' => $triggerType,
                'trigger_value' => $triggerValue,
                'context' => auvvo_flow_wait_reply_strip_context($context),
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $waitId = (int) $pdo->lastInsertId();

        require_once __DIR__ . '/crm_automation.inc.php';
        auvvo_crm_enqueue_single(
            $pdo,
            $userId,
            $flowId,
            $contact,
            $triggerType,
            $triggerValue,
            'flow_wait_timeout',
            [
                'wait_id' => $waitId,
                'flow_id' => $flowId,
            ],
            $timeoutHours * 60,
            $context
        );

        auvvo_automation_run_log_step($pdo, $context, $nodeId, 'flow_wait_reply', $nodeLabel, 'ok', $detail);
        $pausedOk = true;
    } catch (PDOException $e) {
        auvvo_automation_run_log_step($pdo, $context, $nodeId, 'flow_wait_reply', $nodeLabel, 'error', 'Falha ao pausar: ' . $e->getMessage());
    }

    return $pausedOk ? 'paused' : 'ok';
}

/**
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function auvvo_flow_wait_reply_strip_context(array $context): array
{
    $out = $context;
    unset($out['automation_run'], $out['_flow_nodes']);

    return $out;
}

/**
 * @param list<string> $replyNodes
 * @param list<string> $timeoutNodes
 * @param array<string, array> $nodes
 * @param array<string,mixed> $context
 */
function auvvo_flow_wait_reply_save_sim_pause(
    PDO $pdo,
    int $runId,
    string $nodeId,
    array $replyNodes,
    array $timeoutNodes,
    string $keyword,
    int $flowId,
    array $nodes,
    array $context
): void {
    $meta = [
        'simulate_use_llm' => !empty($context['simulate_use_llm']),
        'pause' => [
            'type' => 'wait_reply',
            'node_id' => $nodeId,
            'flow_id' => $flowId,
            'reply_nodes' => $replyNodes,
            'timeout_nodes' => $timeoutNodes,
            'keyword' => $keyword,
            'nodes_snapshot' => $nodes,
            'context' => auvvo_flow_wait_reply_strip_context($context),
        ],
    ];
    try {
        $pdo->prepare('UPDATE crm_automation_runs SET status = ?, meta_json = ? WHERE id = ?')
            ->execute(['paused', json_encode($meta, JSON_UNESCAPED_UNICODE), $runId]);
    } catch (PDOException $e) {
        error_log('[Auvvo] wait_reply sim pause: ' . $e->getMessage());
    }
}

/**
 * Tenta retomar fluxo pausado aguardando resposta (live ou simulate).
 *
 * @return array{handled:bool,resumed?:bool,run_id?:int,status?:string,steps?:list}
 */
function auvvo_flow_wait_reply_try_resume(
    PDO $pdo,
    int $userId,
    array $contact,
    string $messageBody,
    ?int $continueRunId = null
): array {
    if ($continueRunId > 0) {
        return auvvo_flow_wait_reply_resume_simulate($pdo, $userId, $continueRunId, $messageBody, $contact);
    }

    $contactId = (int) ($contact['id'] ?? 0);
    if ($userId <= 0 || $contactId <= 0 || trim($messageBody) === '') {
        return ['handled' => false];
    }

    auvvo_run_migrations($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT * FROM crm_automation_wait_states
             WHERE user_id = ? AND contact_id = ? AND status = ? ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$userId, $contactId, 'waiting']);
        $wait = $st->fetch(PDO::FETCH_ASSOC);
        if (!$wait) {
            return ['handled' => false];
        }

        $keyword = trim((string) ($wait['keyword_filter'] ?? ''));
        if ($keyword !== '' && stripos($messageBody, $keyword) === false) {
            return ['handled' => false, 'resumed' => false];
        }

        $flowId = (int) ($wait['flow_id'] ?? 0);
        $replyNodes = json_decode((string) ($wait['reply_node_ids'] ?? '[]'), true);
        if (!is_array($replyNodes) || $replyNodes === []) {
            auvvo_flow_wait_reply_close($pdo, (int) $wait['id'], 'done');

            return ['handled' => true, 'resumed' => false];
        }

        $meta = json_decode((string) ($wait['meta_json'] ?? '{}'), true);
        $ctx = is_array($meta['context'] ?? null) ? $meta['context'] : [];
        $ctx['message_body'] = $messageBody;

        $stFlow = $pdo->prepare('SELECT flow_data FROM crm_automation_flows WHERE id = ? AND user_id = ? AND is_active = 1 LIMIT 1');
        $stFlow->execute([$flowId, $userId]);
        $row = $stFlow->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            auvvo_flow_wait_reply_close($pdo, (int) $wait['id'], 'cancelled');

            return ['handled' => true, 'resumed' => false];
        }

        $nodes = auvvo_flow_parse_nodes((string) ($row['flow_data'] ?? ''));
        $runId = (int) ($wait['run_id'] ?? 0);
        if ($runId > 0) {
            $ctx['automation_run'] = ['id' => $runId, 'simulate' => false, 'step_order' => auvvo_flow_wait_reply_step_count($pdo, $runId)];
            auvvo_automation_run_log_step($pdo, $ctx, (string) ($wait['node_id'] ?? ''), 'flow_wait_reply', 'Aguardar resposta', 'ok', 'Lead respondeu: «' . mb_substr($messageBody, 0, 120) . '»', 'output_1');
        }

        require_once __DIR__ . '/crm_automation_motor.inc.php';
        $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);
        $ctx['_flow_nodes'] = $nodes;
        $triggerType = (string) ($meta['trigger_type'] ?? 'whatsapp_message');
        $triggerValue = (string) ($meta['trigger_value'] ?? '*');

        $r = auvvo_flow_walk_many($pdo, $userId, $flowId, $nodes, array_map('strval', $replyNodes), $contact, $triggerType, $triggerValue, $ctx, 0);
        auvvo_flow_wait_reply_close($pdo, (int) $wait['id'], 'done');
        if ($runId > 0) {
            auvvo_automation_run_finish($pdo, $runId, $r === 'paused' ? 'paused' : 'done');
        }

        return ['handled' => true, 'resumed' => true, 'run_id' => $runId, 'status' => $r];
    } catch (Throwable $e) {
        error_log('[Auvvo] wait_reply resume: ' . $e->getMessage());

        return ['handled' => true, 'resumed' => false];
    }
}

function auvvo_flow_wait_reply_step_count(PDO $pdo, int $runId): int
{
    try {
        $st = $pdo->prepare('SELECT COALESCE(MAX(step_order), 0) FROM crm_automation_run_steps WHERE run_id = ?');
        $st->execute([$runId]);

        return (int) $st->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function auvvo_flow_wait_reply_close(PDO $pdo, int $waitId, string $status): void
{
    if ($waitId <= 0) {
        return;
    }
    try {
        $pdo->prepare('UPDATE crm_automation_wait_states SET status = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$status, $waitId]);
    } catch (PDOException $e) {
        error_log('[Auvvo] wait_reply_close wait=' . $waitId . ': ' . $e->getMessage());
    }
}

/**
 * @return array{handled:bool,error?:bool,message?:string,run_id?:int,status?:string,steps?:list,matched?:bool}
 */
function auvvo_flow_wait_reply_resume_simulate(
    PDO $pdo,
    int $userId,
    int $runId,
    string $messageBody,
    array $contact
): array {
    require_once __DIR__ . '/crm_automation_motor.inc.php';

    $st = $pdo->prepare('SELECT * FROM crm_automation_runs WHERE id = ? AND user_id = ? AND mode = ? LIMIT 1');
    $st->execute([$runId, $userId, 'simulate']);
    $run = $st->fetch(PDO::FETCH_ASSOC);
    if (!$run || ($run['status'] ?? '') !== 'paused') {
        return ['handled' => false];
    }

    $meta = json_decode((string) ($run['meta_json'] ?? '{}'), true);
    $pause = is_array($meta['pause'] ?? null) ? $meta['pause'] : [];
    if (($pause['type'] ?? '') !== 'wait_reply') {
        return ['handled' => false];
    }

    $keyword = trim((string) ($pause['keyword'] ?? ''));
    if ($keyword !== '' && stripos($messageBody, $keyword) === false) {
        return [
            'handled' => true,
            'error' => false,
            'run_id' => $runId,
            'status' => 'paused',
            'matched' => true,
            'message' => 'Resposta não contém «' . $keyword . '» — fluxo continua aguardando',
            'steps' => auvvo_automation_run_fetch_steps($pdo, $runId),
        ];
    }

    $flowId = (int) ($pause['flow_id'] ?? $run['flow_id'] ?? 0);
    $replyNodes = is_array($pause['reply_nodes'] ?? null) ? $pause['reply_nodes'] : [];
    $nodes = is_array($pause['nodes_snapshot'] ?? null) ? $pause['nodes_snapshot'] : [];
    if ($nodes === [] && $flowId > 0) {
        $stF = $pdo->prepare('SELECT flow_data FROM crm_automation_flows WHERE id = ? AND user_id = ? LIMIT 1');
        $stF->execute([$flowId, $userId]);
        $fr = $stF->fetch(PDO::FETCH_ASSOC);
        $nodes = auvvo_flow_parse_nodes((string) ($fr['flow_data'] ?? ''));
    }

    $ctx = is_array($pause['context'] ?? null) ? $pause['context'] : [];
    $ctx['message_body'] = $messageBody;
    $ctx['simulate_use_llm'] = !empty($meta['simulate_use_llm']);
    $ctx['automation_run'] = [
        'id' => $runId,
        'simulate' => true,
        'step_order' => auvvo_flow_wait_reply_step_count($pdo, $runId),
    ];
    $ctx['_flow_nodes'] = $nodes;

    $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);
    auvvo_automation_run_log_step(
        $pdo,
        $ctx,
        (string) ($pause['node_id'] ?? ''),
        'flow_wait_reply',
        'Aguardar resposta',
        'ok',
        'Lead respondeu (sim): «' . mb_substr($messageBody, 0, 120) . '»',
        'output_1'
    );

    $triggerType = (string) ($run['trigger_type'] ?? 'whatsapp_message');
    $triggerValue = (string) ($run['trigger_value'] ?? '*');

    try {
        $r = auvvo_flow_walk_many($pdo, $userId, $flowId, $nodes, array_map('strval', $replyNodes), $contact, $triggerType, $triggerValue, $ctx, 0);
        $pdo->prepare('UPDATE crm_automation_runs SET status = ?, meta_json = NULL WHERE id = ?')
            ->execute([$r === 'paused' ? 'paused' : 'done', $runId]);
        auvvo_automation_run_finish($pdo, $runId, $r === 'paused' ? 'paused' : 'done');

        return [
            'handled' => true,
            'error' => false,
            'run_id' => $runId,
            'status' => $r === 'paused' ? 'paused' : 'done',
            'matched' => true,
            'steps' => auvvo_automation_run_fetch_steps($pdo, $runId),
            'waiting_reply' => $r === 'paused',
        ];
    } catch (Throwable $e) {
        auvvo_automation_run_finish($pdo, $runId, 'failed', $e->getMessage());

        return ['handled' => true, 'error' => true, 'message' => $e->getMessage(), 'run_id' => $runId];
    }
}

function auvvo_flow_wait_timeout_from_queue(PDO $pdo, int $userId, array $config, array $contact): void
{
    $waitId = (int) ($config['wait_id'] ?? 0);
    if ($waitId <= 0) {
        return;
    }
    auvvo_run_migrations($pdo);
    try {
        $st = $pdo->prepare('SELECT * FROM crm_automation_wait_states WHERE id = ? AND user_id = ? AND status = ? LIMIT 1');
        $st->execute([$waitId, $userId, 'waiting']);
        $wait = $st->fetch(PDO::FETCH_ASSOC);
        if (!$wait) {
            return;
        }

        $timeoutNodes = json_decode((string) ($wait['timeout_node_ids'] ?? '[]'), true);
        if (!is_array($timeoutNodes) || $timeoutNodes === []) {
            auvvo_flow_wait_reply_close($pdo, $waitId, 'timeout');

            return;
        }

        $flowId = (int) ($wait['flow_id'] ?? 0);
        $stFlow = $pdo->prepare('SELECT flow_data FROM crm_automation_flows WHERE id = ? AND user_id = ? AND is_active = 1 LIMIT 1');
        $stFlow->execute([$flowId, $userId]);
        $row = $stFlow->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            auvvo_flow_wait_reply_close($pdo, $waitId, 'timeout');

            return;
        }

        $nodes = auvvo_flow_parse_nodes((string) ($row['flow_data'] ?? ''));
        $meta = json_decode((string) ($wait['meta_json'] ?? '{}'), true);
        $ctx = is_array($meta['context'] ?? null) ? $meta['context'] : [];
        $ctx['_flow_nodes'] = $nodes;
        $runId = (int) ($wait['run_id'] ?? 0);
        if ($runId > 0) {
            $ctx['automation_run'] = ['id' => $runId, 'simulate' => false, 'step_order' => auvvo_flow_wait_reply_step_count($pdo, $runId)];
            auvvo_automation_run_log_step($pdo, $ctx, (string) ($wait['node_id'] ?? ''), 'flow_wait_reply', 'Aguardar resposta', 'ok', 'Timeout — sem resposta', 'output_2');
        }

        require_once __DIR__ . '/crm_automation_motor.inc.php';
        $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);
        $triggerType = (string) ($meta['trigger_type'] ?? 'whatsapp_message');
        $triggerValue = (string) ($meta['trigger_value'] ?? '*');
        $r = auvvo_flow_walk_many($pdo, $userId, $flowId, $nodes, array_map('strval', $timeoutNodes), $contact, $triggerType, $triggerValue, $ctx, 0);
        auvvo_flow_wait_reply_close($pdo, $waitId, 'timeout');
        if ($runId > 0) {
            auvvo_automation_run_finish($pdo, $runId, $r === 'paused' ? 'paused' : 'done');
        }
    } catch (Throwable $e) {
        error_log('[Auvvo] wait timeout: ' . $e->getMessage());
    }
}
