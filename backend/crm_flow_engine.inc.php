<?php
declare(strict_types=1);

require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/crm_automation.inc.php';
require_once __DIR__ . '/crm_automation_motor.inc.php';
require_once __DIR__ . '/crm_automation_dedupe.inc.php';
require_once __DIR__ . '/crm_automation_runs.inc.php';
require_once __DIR__ . '/crm_flow_agent.inc.php';
require_once __DIR__ . '/crm_flow_wait_reply.inc.php';
require_once __DIR__ . '/crm_flow_converse.inc.php';
require_once __DIR__ . '/conversation_history.inc.php';

/**
 * @return array<string, array>
 */
function auvvo_flow_parse_nodes(?string $flowDataJson): array
{
    if ($flowDataJson === null || $flowDataJson === '') {
        return [];
    }
    $data = json_decode($flowDataJson, true);
    if (!is_array($data)) {
        return [];
    }
    $home = $data['drawflow']['Home']['data'] ?? $data['Home']['data'] ?? null;
    if (!is_array($home)) {
        return [];
    }

    return $home;
}

/** Tipo lógico do nó (Drawflow guarda em class e/ou name). */
function auvvo_flow_node_type(array $node): string
{
    $class = trim((string) ($node['class'] ?? ''));
    if ($class !== '' && str_starts_with($class, 'flow_')) {
        return $class;
    }
    $name = trim((string) ($node['name'] ?? ''));

    return $name !== '' ? $name : $class;
}

function auvvo_flow_trigger_matches(array $nodeData, string $triggerType, string $triggerValue, ?PDO $pdo = null, int $userId = 0): bool
{
    $nt = trim((string) ($nodeData['trigger_type'] ?? ''));
    if ($nt === '' || $nt !== $triggerType) {
        return false;
    }
    $nv = trim((string) ($nodeData['trigger_value'] ?? '*'));
    if ($nv === '' || $nv === '*') {
        return true;
    }
    $tv = trim($triggerValue);
    if ($tv === '' || $tv === '*') {
        return true;
    }
    if ($nv === $tv) {
        return true;
    }
    if (in_array($nt, ['whatsapp_first', 'whatsapp_message'], true) && ctype_digit($nv) && ctype_digit($tv)) {
        if ((int) $nv === (int) $tv) {
            return true;
        }
        if ($pdo && $userId > 0) {
            require_once __DIR__ . '/whatsapp_connections.inc.php';
            $connForNodeAgent = auvvo_whatsapp_connection_id_for_agent($pdo, $userId, (int) $nv);
            if ($connForNodeAgent > 0 && $connForNodeAgent === (int) $tv) {
                return true;
            }
            $connForEventAgent = auvvo_whatsapp_connection_id_for_agent($pdo, $userId, (int) $tv);
            if ($connForEventAgent > 0 && $connForEventAgent === (int) $nv) {
                return true;
            }
            if (auvvo_whatsapp_connection_get($pdo, $userId, (int) $nv) && (int) $nv === (int) $tv) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Candidatos de gatilho para simulação (alinha com webhook: conexão, agente, *).
 *
 * @param array<string, array> $nodes
 * @return list<array{0:string,1:string}>
 */
function auvvo_flow_simulate_trigger_candidates(
    string $triggerType,
    string $triggerValue,
    int $connectionId,
    array $nodes,
    int $userId,
    PDO $pdo
): array {
    $seen = [];
    $add = static function (string $type, string $value) use (&$seen, &$out): void {
        $value = trim($value);
        if ($value === '') {
            $value = '*';
        }
        $key = $type . "\0" . $value;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $out[] = [$type, $value];
    };

    $out = [];
    $add($triggerType, $triggerValue);

    if (in_array($triggerType, ['whatsapp_first', 'whatsapp_message'], true)) {
        if ($connectionId > 0) {
            $add($triggerType, (string) $connectionId);
        }
        $add($triggerType, '*');
        foreach ($nodes as $node) {
            if (auvvo_flow_node_type($node) !== 'flow_trigger') {
                continue;
            }
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            if ((string) ($data['trigger_type'] ?? '') !== $triggerType) {
                continue;
            }
            $nv = trim((string) ($data['trigger_value'] ?? '*'));
            if ($nv !== '') {
                $add($triggerType, $nv);
                if ($pdo && $userId > 0 && ctype_digit($nv)) {
                    require_once __DIR__ . '/whatsapp_connections.inc.php';
                    $cid = auvvo_whatsapp_connection_id_for_agent($pdo, $userId, (int) $nv);
                    if ($cid > 0) {
                        $add($triggerType, (string) $cid);
                    }
                }
            }
        }
    } elseif ($triggerType === 'tag_added' && $triggerValue === '') {
        $add($triggerType, 'teste');
    }

    return $out;
}

/**
 * @return list<string>
 */
function auvvo_flow_next_node_ids(array $node, string $outputKey): array
{
    $ids = [];
    $conns = $node['outputs'][$outputKey]['connections'] ?? [];
    if (!is_array($conns)) {
        return $ids;
    }
    foreach ($conns as $conn) {
        if (!empty($conn['node'])) {
            $ids[] = (string) $conn['node'];
        }
    }

    return $ids;
}

function auvvo_flow_bump_stats(PDO $pdo, int $flowId, string $field): void
{
    $allowed = ['stats_entered' => 1, 'stats_success' => 1, 'stats_error' => 1];
    if (!isset($allowed[$field])) {
        return;
    }
    try {
        $pdo->prepare("UPDATE crm_automation_flows SET {$field} = {$field} + 1 WHERE id = ?")->execute([$flowId]);
    } catch (PDOException $e) {
        error_log('[Auvvo] flow_bump_stats flow=' . $flowId . ' field=' . $field . ': ' . $e->getMessage());
    }
}

/**
 * @return 'ok'|'paused'|'skip'
 */
function auvvo_flow_walk(
    PDO $pdo,
    int $userId,
    int $flowId,
    array $nodes,
    string $startNodeId,
    array &$contact,
    string $triggerType,
    string $triggerValue,
    array $context = [],
    int $depth = 0
): string {
    if ($depth > 64 || $startNodeId === '' || !isset($nodes[$startNodeId])) {
        return 'skip';
    }

    $node = $nodes[$startNodeId];
    $nodeType = auvvo_flow_node_type($node);
    $data = is_array($node['data'] ?? null) ? $node['data'] : [];
    $nodeLabel = auvvo_automation_node_label($node);

    switch ($nodeType) {
        case 'flow_trigger':
            auvvo_automation_run_log_step($pdo, $context, $startNodeId, $nodeType, $nodeLabel, 'ok', 'Gatilho: ' . ($data['trigger_type'] ?? ''));
            $next = auvvo_flow_next_node_ids($node, 'output_1');
            return auvvo_flow_walk_many($pdo, $userId, $flowId, $nodes, $next, $contact, $triggerType, $triggerValue, $context, $depth + 1);

        case 'flow_condition':
            $ok = auvvo_crm_contact_passes_conditions($data, $contact, $context, $pdo);
            $outKey = $ok ? 'output_1' : 'output_2';
            auvvo_automation_run_log_step(
                $pdo,
                $context,
                $startNodeId,
                $nodeType,
                $nodeLabel,
                $ok ? 'ok' : 'branch_no',
                $ok ? 'Condição passou' : 'Condição não passou',
                $outKey
            );
            $next = auvvo_flow_next_node_ids($node, $outKey);
            return auvvo_flow_walk_many($pdo, $userId, $flowId, $nodes, $next, $contact, $triggerType, $triggerValue, $context, $depth + 1);

        case 'flow_randomizer':
            $pctA = max(1, min(99, (int) ($data['pct_a'] ?? 50)));
            $outKey = random_int(1, 100) <= $pctA ? 'output_1' : 'output_2';
            auvvo_automation_run_log_step(
                $pdo,
                $context,
                $startNodeId,
                $nodeType,
                $nodeLabel,
                'ok',
                $outKey === 'output_1' ? 'Ramificação A' : 'Ramificação B',
                $outKey
            );
            $next = auvvo_flow_next_node_ids($node, $outKey);
            return auvvo_flow_walk_many($pdo, $userId, $flowId, $nodes, $next, $contact, $triggerType, $triggerValue, $context, $depth + 1);

        case 'flow_delay':
            $mins = max(1, (int) ($data['delay_minutes'] ?? 5));
            $next = auvvo_flow_next_node_ids($node, 'output_1');
            if ($next === []) {
                auvvo_automation_run_log_step($pdo, $context, $startNodeId, $nodeType, $nodeLabel, 'ok', "Espera {$mins} min (fim do fluxo)");
                return 'ok';
            }
            if (auvvo_automation_is_simulate($context)) {
                auvvo_automation_run_log_step(
                    $pdo,
                    $context,
                    $startNodeId,
                    $nodeType,
                    $nodeLabel,
                    'simulated',
                    "Espera {$mins} min (simulado — pulado)"
                );
                return auvvo_flow_walk_many($pdo, $userId, $flowId, $nodes, $next, $contact, $triggerType, $triggerValue, $context, $depth + 1);
            }
            auvvo_crm_enqueue_single(
                $pdo,
                $userId,
                $flowId,
                $contact,
                $triggerType,
                $triggerValue,
                'flow_resume',
                ['flow_id' => $flowId, 'node_ids' => $next],
                $mins,
                $context
            );
            return 'paused';

        case 'flow_message':
            $agentId = auvvo_crm_resolve_agent_id((int) ($data['agent_id'] ?? 0), $contact, $context);
            $connectionId = auvvo_crm_resolve_whatsapp_connection_id(
                $pdo,
                $userId,
                (int) ($data['connection_id'] ?? 0),
                $agentId,
                $context,
                $contact
            );
            $cfg = [
                'connection_id' => $connectionId,
                'agent_id'      => $agentId,
                'message'       => (string) ($data['message'] ?? ''),
                '_node_id'      => $startNodeId,
                '_node_label'   => $nodeLabel,
                '_node_class'   => 'flow_message',
            ];
            if ($cfg['message'] === '') {
                auvvo_automation_run_log_step($pdo, $context, $startNodeId, 'flow_message', $nodeLabel, 'error', 'Texto da mensagem vazio');
            } elseif (auvvo_automation_is_simulate($context)) {
                auvvo_crm_execute_action($pdo, $userId, 'send_whatsapp', $cfg, $contact, $triggerType, $triggerValue, $context);
            } else {
                $send = auvvo_crm_send_whatsapp($pdo, $userId, $cfg, $contact, $context);
                $st = $send['ok'] ? 'ok' : 'error';
                $detail = $send['ok']
                    ? ('WhatsApp: ' . mb_substr($send['sent'], 0, 500))
                    : ($send['error'] ?: 'Falha ao enviar');
                auvvo_automation_run_log_step($pdo, $context, $startNodeId, 'flow_message', $nodeLabel, $st, $detail);
                if (!$send['ok']) {
                    auvvo_flow_bump_stats($pdo, $flowId, 'stats_error');
                } else {
                    $context['_flow_welcome_sent'] = true;
                    if (!auvvo_automation_is_simulate($context)) {
                        auvvo_automation_mark_flow_handled();
                    }
                }
            }
            $next = auvvo_flow_next_node_ids($node, 'output_1');
            return auvvo_flow_walk_many($pdo, $userId, $flowId, $nodes, $next, $contact, $triggerType, $triggerValue, $context, $depth + 1);

        case 'flow_think':
            // Mensagem fixa antes + Pensar: envia boas-vindas agora; IA do fluxo só na próxima mensagem do lead.
            if (!empty($context['_flow_welcome_sent']) && !auvvo_automation_is_simulate($context)) {
                unset($context['_flow_welcome_sent']);
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
                if ($instructions !== '' && !empty($contact['jid'])) {
                    require_once __DIR__ . '/context_memory.inc.php';
                    $instructionsRendered = auvvo_crm_render_message($pdo, $instructions, $contact, $context);
                    auvvo_contact_memory_merge($pdo, $userId, (string) $contact['jid'], [
                        '_brain_mission' => $instructionsRendered,
                    ]);
                    auvvo_flow_session_save($pdo, $userId, (string) $contact['jid'], [
                        'active'        => true,
                        'mode'          => 'pending_think',
                        'flow_id'       => $flowId,
                        'node_id'       => $startNodeId,
                        'agent_id'      => $agentId,
                        'connection_id' => $connectionId,
                        'instructions'  => $instructionsRendered,
                        'node_data'     => $data,
                        'started_at'    => date('c'),
                    ]);
                    $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);
                    auvvo_automation_run_log_step(
                        $pdo,
                        $context,
                        $startNodeId,
                        'flow_think',
                        $nodeLabel,
                        'ok',
                        'Aguardando resposta do lead após mensagem de abertura'
                    );
                }
                auvvo_automation_mark_flow_handled();

                return 'paused';
            }
            if (!auvvo_automation_is_simulate($context)) {
                auvvo_automation_mark_ai_handled();
            }
            $result = auvvo_flow_run_think_node($pdo, $userId, $data, $contact, $context, $startNodeId, $nodeLabel);
            $st = ($result['ok'] ?? false) ? (auvvo_automation_is_simulate($context) ? 'simulated' : 'ok') : 'error';
            $detail = (string) ($result['detail'] ?? '');
            if (!empty($result['response'])) {
                $detail .= ($detail !== '' ? "\n" : '') . (string) $result['response'];
            }
            auvvo_automation_run_log_step($pdo, $context, $startNodeId, 'flow_think', $nodeLabel, $st, $detail);
            if (!($result['ok'] ?? false) && !auvvo_automation_is_simulate($context)) {
                auvvo_flow_bump_stats($pdo, $flowId, 'stats_error');
            }
            $next = auvvo_flow_next_node_ids($node, 'output_1');
            return auvvo_flow_walk_many($pdo, $userId, $flowId, $nodes, $next, $contact, $triggerType, $triggerValue, $context, $depth + 1);

        case 'flow_converse':
            // Após boas-vindas fixas: só arma a sessão IA — resposta vem na próxima msg do lead.
            if (!empty($context['_flow_welcome_sent']) && !auvvo_automation_is_simulate($context)) {
                unset($context['_flow_welcome_sent']);
                $context['flow_id'] = $flowId;
                $context['_converse_defer_reply'] = true;
                $result = auvvo_flow_run_converse_node($pdo, $userId, $data, $contact, $context, $startNodeId, $nodeLabel);
                $st = ($result['ok'] ?? false) ? 'ok' : 'error';
                auvvo_automation_run_log_step(
                    $pdo,
                    $context,
                    $startNodeId,
                    'flow_converse',
                    $nodeLabel,
                    $st,
                    (string) ($result['detail'] ?? 'Aguardando próxima mensagem do lead')
                );
                if ($result['ok'] ?? false) {
                    auvvo_automation_mark_flow_handled();

                    return 'paused';
                }
                if (!auvvo_automation_is_simulate($context)) {
                    auvvo_flow_bump_stats($pdo, $flowId, 'stats_error');
                }

                return 'paused';
            }
            if (!auvvo_automation_is_simulate($context)) {
                auvvo_automation_mark_ai_handled();
            }
            $context['flow_id'] = $flowId;
            $result = auvvo_flow_run_converse_node($pdo, $userId, $data, $contact, $context, $startNodeId, $nodeLabel);
            $st = ($result['ok'] ?? false) ? (auvvo_automation_is_simulate($context) ? 'simulated' : 'ok') : 'error';
            $detail = (string) ($result['detail'] ?? '');
            if (!empty($result['response'])) {
                $detail .= ($detail !== '' ? "\n" : '') . (string) $result['response'];
            }
            auvvo_automation_run_log_step($pdo, $context, $startNodeId, 'flow_converse', $nodeLabel, $st, $detail);
            if (!($result['ok'] ?? false) && !auvvo_automation_is_simulate($context)) {
                auvvo_flow_bump_stats($pdo, $flowId, 'stats_error');
            }
            // Atendimento contínuo: pausa o fluxo após iniciar sessão (próximas msgs via converse_inbound).
            if ($result['ok'] ?? false) {
                if (!auvvo_automation_is_simulate($context)) {
                    auvvo_automation_mark_flow_handled();
                }

                return 'paused';
            }
            $next = auvvo_flow_next_node_ids($node, 'output_1');
            return auvvo_flow_walk_many($pdo, $userId, $flowId, $nodes, $next, $contact, $triggerType, $triggerValue, $context, $depth + 1);

        case 'flow_memory':
            $memKey = trim((string) ($data['memory_key'] ?? ''));
            if ($memKey !== '') {
                $val = auvvo_crm_flow_memory_value($pdo, $userId, $data, $contact, $context);
                if ($val !== '') {
                    auvvo_crm_execute_action(
                        $pdo,
                        $userId,
                        'set_memory',
                        ['key' => $memKey, 'value' => $val, '_node_id' => $startNodeId, '_node_label' => $nodeLabel],
                        $contact,
                        $triggerType,
                        $triggerValue,
                        $context
                    );
                    $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);
                    if (!auvvo_automation_is_simulate($context) && auvvo_automation_run_ctx($context)) {
                        auvvo_automation_run_log_step(
                            $pdo,
                            $context,
                            $startNodeId,
                            'flow_memory',
                            $nodeLabel,
                            'ok',
                            'Memória «' . $memKey . '» = ' . mb_substr($val, 0, 200)
                        );
                    }
                } elseif (auvvo_automation_run_ctx($context)) {
                    auvvo_automation_run_log_step($pdo, $context, $startNodeId, 'flow_memory', $nodeLabel, 'branch_no', 'Nenhum valor para gravar');
                }
            }
            $next = auvvo_flow_next_node_ids($node, 'output_1');
            return auvvo_flow_walk_many($pdo, $userId, $flowId, $nodes, $next, $contact, $triggerType, $triggerValue, $context, $depth + 1);

        case 'flow_agent':
            if (!auvvo_automation_is_simulate($context)) {
                auvvo_automation_mark_ai_handled();
            }
            $result = auvvo_flow_run_agent_node($pdo, $userId, $data, $contact, $context, $startNodeId, $nodeLabel);
            $st = ($result['ok'] ?? false) ? (auvvo_automation_is_simulate($context) ? 'simulated' : 'ok') : 'error';
            $detail = (string) ($result['detail'] ?? '');
            if (!empty($result['response'])) {
                $detail .= ($detail !== '' ? "\n" : '') . (string) $result['response'];
            }
            auvvo_automation_run_log_step($pdo, $context, $startNodeId, 'flow_agent', $nodeLabel, $st, $detail);
            if (!($result['ok'] ?? false) && !auvvo_automation_is_simulate($context)) {
                auvvo_flow_bump_stats($pdo, $flowId, 'stats_error');
            }
            $next = auvvo_flow_next_node_ids($node, 'output_1');
            return auvvo_flow_walk_many($pdo, $userId, $flowId, $nodes, $next, $contact, $triggerType, $triggerValue, $context, $depth + 1);

        case 'flow_wait_reply':
            $context['_flow_nodes'] = $nodes;
            return auvvo_flow_wait_reply_pause(
                $pdo,
                $userId,
                $flowId,
                $startNodeId,
                $nodeLabel,
                $data,
                $contact,
                $triggerType,
                $triggerValue,
                $context
            );

        case 'flow_action':
            $actionType = trim((string) ($data['action_type'] ?? ''));
            if ($actionType !== '') {
                $exec = auvvo_crm_flow_action_config($data);
                if (in_array($actionType, ['send_whatsapp', 'invoke_agent', 'assign_agent', 'pause_ai', 'resume_ai'], true)) {
                    $exec['agent_id'] = auvvo_crm_resolve_agent_id((int) ($exec['agent_id'] ?? 0), $contact, $context);
                }
                if ($actionType === 'send_whatsapp') {
                    $exec['connection_id'] = auvvo_crm_resolve_whatsapp_connection_id(
                        $pdo,
                        $userId,
                        (int) ($exec['connection_id'] ?? 0),
                        (int) ($exec['agent_id'] ?? 0),
                        $context,
                        $contact
                    );
                    $exec['_node_class'] = 'flow_message';
                }
                $exec['_node_id'] = $startNodeId;
                $exec['_node_label'] = $nodeLabel;
                if (auvvo_automation_is_simulate($context)) {
                    auvvo_crm_execute_action($pdo, $userId, $actionType, $exec, $contact, $triggerType, $triggerValue, $context);
                } elseif ($actionType === 'send_whatsapp') {
                    $send = auvvo_crm_send_whatsapp($pdo, $userId, $exec, $contact, $context);
                    if (auvvo_automation_run_ctx($context)) {
                        auvvo_automation_run_log_step(
                            $pdo,
                            $context,
                            $startNodeId,
                            'flow_message',
                            $nodeLabel,
                            $send['ok'] ? 'ok' : 'error',
                            $send['ok'] ? ('WhatsApp: ' . mb_substr($send['sent'], 0, 500)) : ($send['error'] ?: 'Falha ao enviar')
                        );
                    }
                    if (!$send['ok']) {
                        auvvo_flow_bump_stats($pdo, $flowId, 'stats_error');
                    }
                } else {
                    auvvo_crm_execute_action($pdo, $userId, $actionType, $exec, $contact, $triggerType, $triggerValue, $context);
                    if (auvvo_automation_run_ctx($context)) {
                        auvvo_automation_run_log_step($pdo, $context, $startNodeId, 'flow_action', $nodeLabel, 'ok', $actionType . ' executado');
                    }
                }
                if (in_array($actionType, ['assign_agent', 'invoke_agent', 'set_memory', 'brain_mission', 'clear_brain_mission'], true)) {
                    $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);
                }
            }
            $next = auvvo_flow_next_node_ids($node, 'output_1');
            return auvvo_flow_walk_many($pdo, $userId, $flowId, $nodes, $next, $contact, $triggerType, $triggerValue, $context, $depth + 1);

        default:
            return 'skip';
    }
}

/**
 * @param list<string> $nodeIds
 * @return 'ok'|'paused'|'skip'
 */
function auvvo_flow_walk_many(
    PDO $pdo,
    int $userId,
    int $flowId,
    array $nodes,
    array $nodeIds,
    array &$contact,
    string $triggerType,
    string $triggerValue,
    array $context,
    int $depth
): string {
    if ($nodeIds === []) {
        return 'ok';
    }
    $paused = false;
    foreach ($nodeIds as $nid) {
        $r = auvvo_flow_walk($pdo, $userId, $flowId, $nodes, $nid, $contact, $triggerType, $triggerValue, $context, $depth);
        if ($r === 'paused') {
            return 'paused';
        }
    }

    return 'ok';
}

function auvvo_flow_resume_from_queue(
    PDO $pdo,
    int $userId,
    array $config,
    array $contact,
    string $triggerType,
    string $triggerValue
): void {
    $flowId = (int) ($config['flow_id'] ?? 0);
    $nodeIds = $config['node_ids'] ?? [];
    $ctx = is_array($config['_trigger_context'] ?? null) ? $config['_trigger_context'] : [];
    if ($flowId <= 0 || !is_array($nodeIds) || $nodeIds === []) {
        return;
    }

    try {
        $st = $pdo->prepare('SELECT flow_data FROM crm_automation_flows WHERE id = ? AND user_id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$flowId, $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $nodes = auvvo_flow_parse_nodes((string) ($row['flow_data'] ?? ''));
        if ($nodes === []) {
            return;
        }
        $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);
        $r = auvvo_flow_walk_many($pdo, $userId, $flowId, $nodes, array_map('strval', $nodeIds), $contact, $triggerType, $triggerValue, $ctx, 0);
        if ($r !== 'paused') {
            auvvo_flow_bump_stats($pdo, $flowId, 'stats_success');
        }
    } catch (Throwable $e) {
        auvvo_flow_bump_stats($pdo, $flowId, 'stats_error');
        throw $e;
    }
}

/**
 * Fluxos ativos do usuário para o pipeline do contato (NULL/0 = qualquer funil).
 *
 * @return list<array>
 */
function auvvo_flow_list_active_for_contact(PDO $pdo, int $userId, array $contact, string $triggerType = ''): array
{
    $contactPipelineId = (int) ($contact['pipeline_id'] ?? 0);
    try {
        if (auvvo_crm_trigger_skips_pipeline_filter($triggerType)) {
            $stmt = $pdo->prepare(
                'SELECT id, flow_data, pipeline_id FROM crm_automation_flows
                 WHERE user_id = ? AND is_active = 1'
            );
            $stmt->execute([$userId]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, flow_data, pipeline_id FROM crm_automation_flows
                 WHERE user_id = ? AND is_active = 1
                 AND (pipeline_id IS NULL OR pipeline_id = 0 OR pipeline_id = ?)'
            );
            $stmt->execute([$userId, $contactPipelineId]);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Fluxos visuais com gatilho LTV (worker). Dedupe 7 dias por fluxo+contato.
 */
function auvvo_crm_run_ltv_visual_flows(PDO $pdo, int $userId, array $contact): int
{
    $fired = 0;
    $contactId = (int) ($contact['id'] ?? 0);
    if ($userId <= 0 || $contactId <= 0) {
        return 0;
    }

    auvvo_run_migrations($pdo);
    $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);
    $flows = auvvo_flow_list_active_for_contact($pdo, $userId, $contact);
    if ($flows === []) {
        return 0;
    }

    foreach ($flows as $flow) {
        $flowId = (int) $flow['id'];
        $nodes = auvvo_flow_parse_nodes((string) ($flow['flow_data'] ?? ''));
        if ($nodes === []) {
            continue;
        }

        foreach ($nodes as $nodeId => $node) {
            if (auvvo_flow_node_type($node) !== 'flow_trigger') {
                continue;
            }
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            if (!auvvo_flow_trigger_matches($data, 'ltv_inactive', (string) ($data['trigger_value'] ?? 'default'))) {
                continue;
            }
            if (auvvo_crm_ltv_already_fired($pdo, $userId, $contactId, $flowId, 'flow')) {
                continue;
            }

            auvvo_flow_bump_stats($pdo, $flowId, 'stats_entered');
            try {
                $r = auvvo_flow_walk(
                    $pdo,
                    $userId,
                    $flowId,
                    $nodes,
                    (string) $nodeId,
                    $contact,
                    'ltv_inactive',
                    (string) ($data['trigger_value'] ?? 'default'),
                    [],
                    0
                );
                if ($r !== 'paused') {
                    auvvo_crm_ltv_mark_fired($pdo, $userId, $contactId, $flowId, 'flow');
                    auvvo_flow_bump_stats($pdo, $flowId, 'stats_success');
                }
                $fired++;
            } catch (Throwable $e) {
                auvvo_flow_bump_stats($pdo, $flowId, 'stats_error');
                error_log('[Auvvo] ltv flow ' . $flowId . ': ' . $e->getMessage());
            }
        }
    }

    return $fired;
}

function auvvo_crm_run_visual_flows(
    PDO $pdo,
    int $userId,
    string $triggerType,
    string $triggerValue,
    array $contact,
    array $context = []
): void {
    auvvo_run_migrations($pdo);
    $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);

    require_once __DIR__ . '/crm_flow_wait_reply.inc.php';
    $resume = auvvo_flow_wait_reply_try_resume($pdo, $userId, $contact, (string) ($context['message_body'] ?? ''));
    if (!empty($resume['handled'])) {
        return;
    }

    $flows = auvvo_flow_list_active_for_contact($pdo, $userId, $contact, $triggerType);
    if ($flows === []) {
        return;
    }

    foreach ($flows as $flow) {
        $flowId = (int) $flow['id'];
        $flowPipelineId = (int) ($flow['pipeline_id'] ?? 0);
        $nodes = auvvo_flow_parse_nodes((string) ($flow['flow_data'] ?? ''));
        if ($nodes === []) {
            continue;
        }

        $flowTriggered = false;
        foreach ($nodes as $nodeId => $node) {
            if ($flowTriggered) {
                break;
            }
            if (auvvo_flow_node_type($node) !== 'flow_trigger') {
                continue;
            }
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            $triggerAttempts = [$triggerType];
            if ($triggerType === 'whatsapp_message') {
                $triggerAttempts[] = 'whatsapp_first';
            }
            $matchedTrigger = null;
            foreach ($triggerAttempts as $tryTrigger) {
                if (auvvo_flow_trigger_matches($data, $tryTrigger, $triggerValue, $pdo, $userId)) {
                    $matchedTrigger = $tryTrigger;
                    break;
                }
            }
            if ($matchedTrigger === null) {
                continue;
            }
            $syncPipeline = !isset($data['sync_pipeline_on_enter']) || !empty($data['sync_pipeline_on_enter']);
            if ($flowPipelineId > 0 && auvvo_crm_trigger_skips_pipeline_filter($triggerType) && $syncPipeline) {
                auvvo_crm_sync_contact_to_pipeline($pdo, $userId, $contact, $flowPipelineId);
            }
            if (auvvo_crm_dedupe_should_skip_flow($pdo, $userId, $contact, $flowId, $matchedTrigger, $triggerValue, $context)) {
                continue;
            }
            if (!auvvo_crm_dedupe_claim_flow($pdo, $userId, $contact, $flowId, $matchedTrigger, $triggerValue, $context)) {
                continue;
            }
            if (auvvo_flow_trigger_cooldown_skip($pdo, $userId, $flowId, $nodes, (string) $nodeId, $contact, $triggerType)) {
                continue;
            }
            $runId = 0;
            if (!auvvo_automation_is_simulate($context)) {
                $runId = auvvo_automation_run_start(
                    $pdo,
                    $userId,
                    $flowId,
                    !empty($contact['id']) ? (int) $contact['id'] : null,
                    'live',
                    $matchedTrigger,
                    $triggerValue,
                    (string) ($context['message_body'] ?? ''),
                    ['connection_id' => (int) ($context['whatsapp_connection_id'] ?? 0)]
                );
                if ($runId > 0) {
                    $context['automation_run'] = [
                        'id' => $runId,
                        'simulate' => false,
                        'step_order' => 0,
                    ];
                } else {
                    error_log('[Auvvo] flow run start falhou para flow=' . $flowId . ' trigger=' . $matchedTrigger);
                }
            }
            auvvo_flow_bump_stats($pdo, $flowId, 'stats_entered');
            try {
                $context['_flow_nodes'] = $nodes;
                $r = auvvo_flow_walk($pdo, $userId, $flowId, $nodes, (string) $nodeId, $contact, $matchedTrigger, $triggerValue, $context, 0);
                if ($r !== 'paused') {
                    auvvo_flow_bump_stats($pdo, $flowId, 'stats_success');
                }
                if ($runId > 0) {
                    auvvo_automation_run_finish($pdo, $runId, $r === 'paused' ? 'paused' : 'done');
                }
                if ($r !== 'paused') {
                    auvvo_flow_trigger_cooldown_mark($pdo, $userId, $flowId, $nodes, (string) $nodeId, $contact, $triggerType);
                }
                $flowTriggered = true;
            } catch (Throwable $e) {
                auvvo_flow_bump_stats($pdo, $flowId, 'stats_error');
                if ($runId > 0) {
                    auvvo_automation_run_finish($pdo, $runId, 'failed', $e->getMessage());
                }
                error_log('[Auvvo] flow ' . $flowId . ': ' . $e->getMessage());
            }
        }
    }
}
