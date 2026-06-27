<?php
declare(strict_types=1);

require_once __DIR__ . '/migrations.php';

/**
 * @param array<string, mixed> $context
 */
function auvvo_automation_run_ctx(array $context): ?array
{
    $run = $context['automation_run'] ?? null;

    return is_array($run) ? $run : null;
}

function auvvo_automation_is_simulate(array $context): bool
{
    $run = auvvo_automation_run_ctx($context);

    return $run !== null && !empty($run['simulate']);
}

/**
 * @param array<string, mixed> $meta
 */
function auvvo_automation_run_start(
    PDO $pdo,
    int $userId,
    int $flowId,
    ?int $contactId,
    string $mode,
    string $triggerType,
    string $triggerValue,
    string $messagePreview = '',
    array $meta = []
): int {
    if ($userId <= 0) {
        return 0;
    }
    auvvo_run_migrations($pdo);
    $mode = in_array($mode, ['simulate', 'live'], true) ? $mode : 'live';
    try {
        $st = $pdo->prepare(
            'INSERT INTO crm_automation_runs
             (user_id, flow_id, contact_id, mode, trigger_type, trigger_value, message_preview, status, meta_json)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $userId,
            $flowId > 0 ? $flowId : null,
            $contactId,
            $mode,
            mb_substr($triggerType, 0, 64),
            mb_substr($triggerValue, 0, 128),
            mb_substr($messagePreview, 0, 500),
            'running',
            $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);

        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('[Auvvo] automation_run_start: ' . $e->getMessage());

        return 0;
    }
}

function auvvo_automation_run_finish(
    PDO $pdo,
    int $runId,
    string $status,
    string $error = ''
): void {
    if ($runId <= 0) {
        return;
    }
    $allowed = ['done', 'paused', 'failed', 'skipped'];
    if (!in_array($status, $allowed, true)) {
        $status = 'done';
    }
    try {
        $pdo->prepare(
            'UPDATE crm_automation_runs SET status = ?, error_message = ?, finished_at = NOW(), updated_at = NOW() WHERE id = ?'
        )->execute([
            $status,
            $error !== '' ? mb_substr($error, 0, 500) : null,
            $runId,
        ]);
    } catch (PDOException $e) {
        error_log('[Auvvo] automation_run_finish: ' . $e->getMessage());
    }
}

/**
 * @param array<string, mixed> $extra
 */
function auvvo_automation_run_log_step(
    PDO $pdo,
    array &$context,
    string $nodeId,
    string $nodeClass,
    string $nodeLabel,
    string $status,
    string $detail = '',
    string $branch = '',
    array $extra = []
): void {
    $run = auvvo_automation_run_ctx($context);
    if (!$run || empty($run['id'])) {
        return;
    }
    $runId = (int) $run['id'];
    $order = (int) ($run['step_order'] ?? 0) + 1;
    $context['automation_run']['step_order'] = $order;

    $payload = $extra === [] ? null : json_encode($extra, JSON_UNESCAPED_UNICODE);

    try {
        $pdo->prepare(
            'INSERT INTO crm_automation_run_steps
             (run_id, step_order, node_id, node_class, node_label, status, detail, branch, payload_json)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $runId,
            $order,
            mb_substr($nodeId, 0, 32),
            mb_substr($nodeClass, 0, 64),
            mb_substr($nodeLabel, 0, 255),
            mb_substr($status, 0, 32),
            $detail !== '' ? mb_substr($detail, 0, 4000) : null,
            $branch !== '' ? mb_substr($branch, 0, 32) : null,
            $payload,
        ]);
    } catch (PDOException $e) {
        error_log('[Auvvo] automation_run_log_step: ' . $e->getMessage());
    }
}

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed> $contact
 */
function auvvo_automation_simulate_action_detail(
    string $actionType,
    array $config,
    array $contact,
    PDO $pdo,
    int $userId,
    array $context
): string {
    require_once __DIR__ . '/crm_automation.inc.php';

    switch ($actionType) {
        case 'send_whatsapp':
            $msg = (string) ($config['message'] ?? '');
            if ($msg === '') {
                return 'Mensagem vazia — nada enviado';
            }

            return 'WhatsApp (simulado): ' . auvvo_crm_render_message($pdo, $msg, $contact, $context);

        case 'add_tag':
            return 'Tag (simulado): +' . trim((string) ($config['tag'] ?? ''));

        case 'remove_tag':
            return 'Tag (simulado): -' . trim((string) ($config['tag'] ?? ''));

        case 'move_stage':
            $stage = trim((string) ($config['stage'] ?? ''));
            $pid = (int) ($config['pipeline_id'] ?? 0);
            $pipeLabel = $pid > 0 ? 'funil #' . $pid : 'funil do lead';
            return 'Estágio (simulado): ' . $pipeLabel . ' → ' . $stage;

        case 'assign_agent':
            return 'Agente (simulado): #' . (int) ($config['agent_id'] ?? 0);

        case 'invoke_agent':
            $intro = trim((string) ($config['message'] ?? ''));

            return 'Invocar agente #' . (int) ($config['agent_id'] ?? 0)
                . ($intro !== '' ? ' — ' . mb_substr($intro, 0, 200) : '');

        case 'pause_ai':
            return 'Pausar IA ' . (int) ($config['minutes'] ?? 60) . ' min (simulado)';

        case 'resume_ai':
            return 'Retomar IA (simulado)';

        case 'set_memory':
            return 'Memória (simulado): ' . ($config['key'] ?? '') . ' = ' . mb_substr((string) ($config['value'] ?? ''), 0, 120);

        case 'brain_mission':
            return 'Missão IA (simulado): ' . mb_substr((string) ($config['mission'] ?? $config['message'] ?? ''), 0, 300);

        case 'clear_brain_mission':
            return 'Limpar missão IA (simulado)';

        case 'call_webhook':
            return 'Webhook outbound #' . (int) ($config['webhook_id'] ?? 0) . ' (simulado)';

        case 'http_preset':
            return 'HTTP preset #' . (int) ($config['preset_id'] ?? 0) . ' (simulado)';

        case 'google_sheets_append':
            return 'Google Sheets append (simulado)';

        default:
            return 'Ação ' . $actionType . ' (simulado)';
    }
}

/**
 * Lead fictício ou contato real (hydrate) para simulação.
 *
 * @return array<string, mixed>|null
 */
function auvvo_automation_simulate_contact(
    PDO $pdo,
    int $userId,
    ?int $contactId,
    array $overrides = []
): ?array {
    require_once __DIR__ . '/crm_automation_motor.inc.php';

    if ($contactId > 0) {
        require_once __DIR__ . '/Contacts.php';
        $row = (new Contacts($pdo))->get($userId, $contactId);
        if ($row) {
            return auvvo_crm_hydrate_contact($pdo, $userId, array_merge($row, $overrides));
        }
    }

    $sample = [
        'id' => 0,
        'user_id' => $userId,
        'name' => (string) ($overrides['name'] ?? 'Lead Teste'),
        'phone' => (string) ($overrides['phone'] ?? '11999998888'),
        'email' => (string) ($overrides['email'] ?? 'teste@email.com'),
        'company' => (string) ($overrides['company'] ?? 'Empresa Teste'),
        'stage' => (string) ($overrides['stage'] ?? 'new'),
        'jid' => '5511999998888@s.whatsapp.net',
        'tags' => [],
        'custom_fields' => [],
        'memory_json' => [],
        'pipeline_id' => (int) ($overrides['pipeline_id'] ?? 0),
        'agent_id' => (int) ($overrides['agent_id'] ?? 0),
    ];

    return auvvo_crm_hydrate_contact($pdo, $userId, array_merge($sample, $overrides));
}

/**
 * Executa um fluxo em modo simulação (rascunho ou publicado).
 *
 * @return array{error:bool,message?:string,run_id?:int,status?:string,steps?:list<array>}
 */
function auvvo_automation_simulate_flow(
    PDO $pdo,
    int $userId,
    int $flowId,
    ?string $flowDataJson,
    string $triggerType,
    string $triggerValue,
    string $messageBody,
    array $contactOverrides = [],
    ?int $contactId = null,
    int $connectionId = 0,
    bool $useLlm = false,
    int $continueRunId = 0
): array {
    require_once __DIR__ . '/crm_flow_engine.inc.php';
    require_once __DIR__ . '/crm_automation_motor.inc.php';
    require_once __DIR__ . '/crm_flow_wait_reply.inc.php';
    require_once __DIR__ . '/crm_flow_converse.inc.php';

    auvvo_run_migrations($pdo);

    if ($continueRunId > 0) {
        $contact = auvvo_automation_simulate_contact($pdo, $userId, $contactId, $contactOverrides);
        if (!$contact) {
            return ['error' => true, 'message' => 'Contato inválido'];
        }
        $resume = auvvo_flow_wait_reply_try_resume($pdo, $userId, $contact, $messageBody, $continueRunId);
        if (!empty($resume['handled'])) {
            return array_merge(
                ['error' => (bool) ($resume['error'] ?? false)],
                $resume
            );
        }

        $metaUseLlm = $useLlm;
        try {
            $stMeta = $pdo->prepare('SELECT meta_json FROM crm_automation_runs WHERE id = ? AND user_id = ? LIMIT 1');
            $stMeta->execute([$continueRunId, $userId]);
            $metaRow = json_decode((string) ($stMeta->fetchColumn() ?: '{}'), true);
            if (is_array($metaRow) && !empty($metaRow['simulate_use_llm'])) {
                $metaUseLlm = true;
            }
        } catch (PDOException $e) {
        }

        $ctx = [
            'simulate_use_llm' => $metaUseLlm,
            'automation_run' => ['id' => $continueRunId, 'simulate' => true, 'step_order' => 0],
        ];
        $converse = auvvo_flow_converse_simulate_continue($pdo, $userId, $continueRunId, $messageBody, $contact, $ctx);
        if (!empty($converse['handled'])) {
            $status = !empty($converse['ended']) ? 'done' : 'paused';
            if (!empty($converse['ended'])) {
                auvvo_automation_run_finish($pdo, $continueRunId, 'done');
            }
            $steps = auvvo_automation_run_fetch_steps($pdo, $continueRunId);

            return [
                'error' => false,
                'handled' => true,
                'run_id' => $continueRunId,
                'status' => $status,
                'waiting_reply' => $status === 'paused',
                'steps' => $steps,
                'contact' => [
                    'name' => $contact['name'] ?? '',
                    'stage' => $contact['stage'] ?? '',
                    'tags' => $contact['tags'] ?? [],
                ],
            ];
        }

        return ['error' => true, 'message' => 'Execução não está aguardando resposta'];
    }

    $flowData = $flowDataJson;
    $flowName = 'Simulação';
    $pipelineId = 0;

    if ($flowId > 0) {
        $st = $pdo->prepare('SELECT id, name, flow_data, pipeline_id FROM crm_automation_flows WHERE id = ? AND user_id = ? LIMIT 1');
        $st->execute([$flowId, $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['error' => true, 'message' => 'Fluxo não encontrado'];
        }
        $flowData = (string) ($row['flow_data'] ?? '');
        $flowName = (string) ($row['name'] ?? 'Fluxo');
        $pipelineId = (int) ($row['pipeline_id'] ?? 0);
    }

    $nodes = auvvo_flow_parse_nodes($flowData);
    if ($nodes === []) {
        return ['error' => true, 'message' => 'Fluxo vazio ou JSON inválido'];
    }

    $contact = auvvo_automation_simulate_contact($pdo, $userId, $contactId, $contactOverrides);
    if (!$contact) {
        return ['error' => true, 'message' => 'Contato inválido'];
    }
    if ($pipelineId > 0) {
        $contact['pipeline_id'] = $pipelineId;
    }

    unset($contactOverrides['_simulate_use_llm']);

    $runId = auvvo_automation_run_start(
        $pdo,
        $userId,
        $flowId,
        $contactId,
        'simulate',
        $triggerType,
        $triggerValue,
        $messageBody,
        [
            'flow_name' => $flowName,
            'contact_name' => (string) ($contact['name'] ?? ''),
            'connection_id' => $connectionId,
        ]
    );

    $context = [
        'message_body' => $messageBody,
        'whatsapp_connection_id' => $connectionId,
        'trigger_agent_id' => (int) ($contact['agent_id'] ?? 0),
        'simulate_use_llm' => $useLlm,
        '_flow_nodes' => $nodes,
        'automation_run' => [
            'id' => $runId,
            'simulate' => true,
            'step_order' => 0,
        ],
    ];

    $matched = false;
    $finalStatus = 'skipped';
    $matchedPair = [$triggerType, $triggerValue];

    $triggerCandidates = auvvo_flow_simulate_trigger_candidates(
        $triggerType,
        $triggerValue,
        $connectionId,
        $nodes,
        $userId,
        $pdo
    );

    foreach ($triggerCandidates as [$tryType, $tryValue]) {
        foreach ($nodes as $nodeId => $node) {
            if (auvvo_flow_node_type($node) !== 'flow_trigger') {
                continue;
            }
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            if (!auvvo_flow_trigger_matches($data, $tryType, $tryValue, $pdo, $userId)) {
                continue;
            }
            $matched = true;
            $matchedPair = [$tryType, $tryValue];
            try {
                $r = auvvo_flow_walk(
                    $pdo,
                    $userId,
                    $flowId,
                    $nodes,
                    (string) $nodeId,
                    $contact,
                    $tryType,
                    $tryValue,
                    $context,
                    0
                );
                $finalStatus = $r === 'paused' ? 'paused' : 'done';
            } catch (Throwable $e) {
                auvvo_automation_run_finish($pdo, $runId, 'failed', $e->getMessage());
                $steps = auvvo_automation_run_fetch_steps($pdo, $runId);

                return [
                    'error' => true,
                    'message' => $e->getMessage(),
                    'run_id' => $runId,
                    'status' => 'failed',
                    'steps' => $steps,
                ];
            }
            break 2;
        }
    }

    if (!$matched) {
        auvvo_automation_run_log_step(
            $pdo,
            $context,
            '0',
            'flow_trigger',
            'Gatilho',
            'skip',
            'Nenhum nó Início corresponde a ' . $triggerType . ' / ' . $triggerValue
            . ' — confira o tipo de gatilho do fluxo ou use «Usar editor» com o canvas correto'
        );
        $finalStatus = 'skipped';
    }

    auvvo_automation_run_finish($pdo, $runId, $finalStatus);
    if ($finalStatus === 'paused' && $runId > 0) {
        try {
            $stMeta = $pdo->prepare('SELECT meta_json FROM crm_automation_runs WHERE id = ? LIMIT 1');
            $stMeta->execute([$runId]);
            $existing = json_decode((string) ($stMeta->fetchColumn() ?: '{}'), true);
            if (!is_array($existing)) {
                $existing = [];
            }
            if (empty($existing['pause'])) {
                $existing['simulate_use_llm'] = $useLlm;
                $pdo->prepare('UPDATE crm_automation_runs SET meta_json = ? WHERE id = ?')
                    ->execute([json_encode($existing, JSON_UNESCAPED_UNICODE), $runId]);
            }
        } catch (PDOException $e) {
        }
    }
    $steps = auvvo_automation_run_fetch_steps($pdo, $runId);

    return [
        'error' => false,
        'run_id' => $runId,
        'status' => $finalStatus,
        'matched' => $matched,
        'matched_trigger' => $matched ? ['type' => $matchedPair[0], 'value' => $matchedPair[1]] : null,
        'waiting_reply' => $finalStatus === 'paused',
        'steps' => $steps,
        'contact' => [
            'name' => $contact['name'] ?? '',
            'stage' => $contact['stage'] ?? '',
            'tags' => $contact['tags'] ?? [],
        ],
    ];
}

/** @return list<array<string,mixed>> */
function auvvo_automation_run_fetch_steps(PDO $pdo, int $runId): array
{
    if ($runId <= 0) {
        return [];
    }
    try {
        $st = $pdo->prepare(
            'SELECT step_order, node_id, node_class, node_label, status, detail, branch, payload_json, created_at
             FROM crm_automation_run_steps WHERE run_id = ? ORDER BY step_order ASC'
        );
        $st->execute([$runId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/** @return list<array<string,mixed>> */
function auvvo_automation_list_runs(PDO $pdo, int $userId, int $flowId = 0, int $limit = 40, string $modeFilter = ''): array
{
    auvvo_run_migrations($pdo);
    $limit = max(1, min(100, $limit));
    $sql = 'SELECT r.*, f.name AS flow_name
            FROM crm_automation_runs r
            LEFT JOIN crm_automation_flows f ON f.id = r.flow_id
            WHERE r.user_id = ?';
    $params = [$userId];
    if ($flowId > 0) {
        $sql .= ' AND r.flow_id = ?';
        $params[] = $flowId;
    }
    if ($modeFilter === 'live' || $modeFilter === 'simulate') {
        $sql .= ' AND r.mode = ?';
        $params[] = $modeFilter;
    }
    $sql .= ' ORDER BY r.id DESC LIMIT ' . $limit;
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/** @return array<string,mixed>|null */
function auvvo_automation_get_run(PDO $pdo, int $userId, int $runId): ?array
{
    if ($runId <= 0) {
        return null;
    }
    try {
        $st = $pdo->prepare(
            'SELECT r.*, f.name AS flow_name
             FROM crm_automation_runs r
             LEFT JOIN crm_automation_flows f ON f.id = r.flow_id
             WHERE r.id = ? AND r.user_id = ? LIMIT 1'
        );
        $st->execute([$runId, $userId]);
        $run = $st->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            return null;
        }
        $run['steps'] = auvvo_automation_run_fetch_steps($pdo, $runId);

        return $run;
    } catch (PDOException $e) {
        return null;
    }
}

function auvvo_automation_node_label(array $node): string
{
    $class = (string) ($node['class'] ?? '');
    $data = is_array($node['data'] ?? null) ? $node['data'] : [];
    if (!empty($data['label'])) {
        return (string) $data['label'];
    }

    return match ($class) {
        'flow_trigger' => 'Início',
        'flow_condition' => 'Condição',
        'flow_randomizer' => 'Random',
        'flow_delay' => 'Espera',
        'flow_message' => 'Mensagem',
        'flow_memory' => 'Memória',
        'flow_action' => (string) ($data['action_type'] ?? 'Ação'),
        'flow_agent' => 'Agente IA',
        'flow_wait_reply' => 'Aguardar resposta',
        default => $class,
    };
}
