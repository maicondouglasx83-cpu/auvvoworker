<?php
/**
 * Moderniza grafos Drawflow salvos — labels, flow_agent → flow_converse, remove boas-vindas duplicadas.
 */
declare(strict_types=1);

/**
 * @deprecated v4 — não inserir boas-vindas; IA responde na 1ª mensagem (trigger → converse).
 */
function auvvo_flow_insert_welcome_if_missing(array &$home): bool
{
    $hasMessage = false;
    $hasConverse = false;
    $hasWaFirst = false;
    $triggerId = null;
    $converseId = null;
    $connId = 0;
    $agentId = 0;

    foreach ($home as $nid => $node) {
        if (!is_array($node)) {
            continue;
        }
        $class = (string) ($node['class'] ?? $node['name'] ?? '');
        if ($class === 'flow_message') {
            $hasMessage = true;
        }
        if ($class === 'flow_converse') {
            $hasConverse = true;
            $converseId = (int) $nid;
            $connId = (int) (($node['data'] ?? [])['connection_id'] ?? 0);
            $agentId = (int) (($node['data'] ?? [])['agent_id'] ?? 0);
        }
        if ($class === 'flow_trigger') {
            $tt = (string) (($node['data'] ?? [])['trigger_type'] ?? '');
            if (in_array($tt, ['whatsapp_first', 'whatsapp_message'], true)) {
                $hasWaFirst = true;
                $triggerId = (int) $nid;
            }
        }
    }

    if (!$hasWaFirst || !$hasConverse || $hasMessage || !$triggerId || !$converseId) {
        return false;
    }

    $trig = $home[$triggerId] ?? null;
    if (!$trig) {
        return false;
    }

    // Remove ligação direta trigger → converse (se existir) antes de inserir mensagem.
    if (isset($trig['outputs']['output_1']['connections'])) {
        $trig['outputs']['output_1']['connections'] = array_values(array_filter(
            $trig['outputs']['output_1']['connections'],
            static fn ($c) => (int) ($c['node'] ?? 0) !== $converseId
        ));
    }
    if (isset($home[$converseId]['inputs']['input_1']['connections'])) {
        $home[$converseId]['inputs']['input_1']['connections'] = array_values(array_filter(
            $home[$converseId]['inputs']['input_1']['connections'],
            static fn ($c) => (int) ($c['node'] ?? 0) !== $triggerId
        ));
    }

    $maxId = 0;
    foreach (array_keys($home) as $k) {
        $maxId = max($maxId, (int) $k);
    }
    $msgId = $maxId + 1;
    $trigX = (float) ($trig['pos_x'] ?? 40);
    $conv = $home[$converseId];
    $convX = (float) ($conv['pos_x'] ?? 400);
    $y = (float) ($conv['pos_y'] ?? 120);

    $welcomeText = 'Olá {{nome}}! Como posso ajudar?';
    foreach ($home as $node) {
        if (!is_array($node)) {
            continue;
        }
        $instr = (string) (($node['data'] ?? [])['instructions'] ?? '');
        if (stripos($instr, 'secret') !== false || stripos($instr, 'clínica') !== false || stripos($instr, 'clinica') !== false) {
            $welcomeText = 'Olá {{nome}}! Sou a secretária virtual. Posso ajudar com agendamento, convênio ou dúvidas. O que você precisa?';
            break;
        }
    }

    $home[$msgId] = [
        'id' => $msgId,
        'name' => 'flow_message',
        'class' => 'flow_message',
        'data' => [
            'connection_id' => $connId,
            'agent_id' => $agentId,
            'message' => $welcomeText,
            'label' => 'Boas-vindas',
            '_preview' => 'Texto fixo',
        ],
        'html' => '',
        'typenode' => false,
        'inputs' => [
            'input_1' => ['connections' => [['node' => (string) $triggerId, 'input' => 'output_1']]],
        ],
        'outputs' => [
            'output_1' => ['connections' => [['node' => (string) $converseId, 'output' => 'input_1']]],
        ],
        'pos_x' => $trigX + ($convX - $trigX) / 2,
        'pos_y' => $y,
    ];

    $home[$triggerId]['outputs']['output_1']['connections'][] = [
        'node' => (string) $msgId,
        'output' => 'input_1',
    ];
    if (isset($home[$converseId]['inputs']['input_1'])) {
        $home[$converseId]['inputs']['input_1']['connections'][] = [
            'node' => (string) $msgId,
            'input' => 'output_1',
        ];
    }

    return true;
}

/**
 * Remove nó de boas-vindas fixo entre gatilho WhatsApp e Atendimento IA (evita resposta dupla).
 */
function auvvo_flow_strip_welcome_before_converse(array &$home): bool
{
    $triggerIds = [];
    $messageIds = [];
    $converseIds = [];

    foreach ($home as $nid => $node) {
        if (!is_array($node)) {
            continue;
        }
        $class = (string) ($node['class'] ?? $node['name'] ?? '');
        $id = (int) $nid;
        if ($class === 'flow_trigger') {
            $tt = (string) (($node['data'] ?? [])['trigger_type'] ?? '');
            if (in_array($tt, ['whatsapp_first', 'whatsapp_message'], true)) {
                $triggerIds[] = $id;
            }
        } elseif ($class === 'flow_message') {
            $messageIds[] = $id;
        } elseif ($class === 'flow_converse') {
            $converseIds[] = $id;
        }
    }

    if ($triggerIds === [] || $messageIds === [] || $converseIds === []) {
        return false;
    }

    $removed = false;
    foreach ($messageIds as $msgId) {
        $msg = $home[$msgId] ?? null;
        if (!$msg) {
            continue;
        }
        $msgText = trim((string) (($msg['data'] ?? [])['message'] ?? ''));
        $converseTarget = null;
        $triggerSource = null;

        foreach (($msg['outputs']['output_1']['connections'] ?? []) as $c) {
            $to = (int) ($c['node'] ?? 0);
            if (in_array($to, $converseIds, true)) {
                $converseTarget = $to;
                break;
            }
        }
        if (!$converseTarget) {
            continue;
        }

        foreach ($triggerIds as $trigId) {
            foreach (($home[$trigId]['outputs']['output_1']['connections'] ?? []) as $c) {
                if ((int) ($c['node'] ?? 0) === $msgId) {
                    $triggerSource = $trigId;
                    break 2;
                }
            }
        }
        if (!$triggerSource) {
            continue;
        }

        $conv = &$home[$converseTarget];
        if ($msgText !== '' && isset($conv['data']) && is_array($conv['data'])) {
            $instr = trim((string) ($conv['data']['instructions'] ?? ''));
            if ($instr !== '' && stripos($instr, 'primeira resposta') === false) {
                $conv['data']['instructions'] = 'Na primeira resposta, cumprimente o lead de forma natural (sem repetir texto fixo automático). ' . $instr;
            }
        }

        $home[$triggerSource]['outputs']['output_1']['connections'] = array_values(array_filter(
            $home[$triggerSource]['outputs']['output_1']['connections'] ?? [],
            static fn ($c) => (int) ($c['node'] ?? 0) !== $msgId
        ));
        $home[$triggerSource]['outputs']['output_1']['connections'][] = [
            'node' => (string) $converseTarget,
            'output' => 'input_1',
        ];

        if (isset($conv['inputs']['input_1']['connections'])) {
            $conv['inputs']['input_1']['connections'] = array_values(array_filter(
                $conv['inputs']['input_1']['connections'],
                static fn ($c) => (int) ($c['node'] ?? 0) !== $msgId
            ));
            $conv['inputs']['input_1']['connections'][] = [
                'node' => (string) $triggerSource,
                'input' => 'output_1',
            ];
        }

        unset($home[$msgId]);
        $removed = true;
    }

    return $removed;
}

function auvvo_flow_migrate_home_nodes(array &$data): bool
{
    $home = &$data['drawflow']['Home']['data'];
    if (!is_array($home ?? null)) {
        $home = &$data['Home']['data'];
    }
    if (!is_array($home ?? null)) {
        return false;
    }

    $schema = (int) ($data['_flow_schema'] ?? 0);
    if ($schema >= 4) {
        return false;
    }

    $changed = $schema < 3;
    $hasWaTrigger = false;

    foreach ($home as &$node) {
        if (!is_array($node)) {
            continue;
        }
        $class = (string) ($node['class'] ?? $node['name'] ?? '');
        if ($class === 'flow_trigger') {
            $tt = (string) (($node['data'] ?? [])['trigger_type'] ?? '');
            if (in_array($tt, ['whatsapp_first', 'whatsapp_message'], true)) {
                $hasWaTrigger = true;
            }
        }
    }
    unset($node);

    foreach ($home as &$node) {
        if (!is_array($node)) {
            continue;
        }
        $class = (string) ($node['class'] ?? $node['name'] ?? '');
        if ($class === '') {
            continue;
        }
        $node['class'] = $class;
        $node['name'] = $class;
        if (!isset($node['data']) || !is_array($node['data'])) {
            $node['data'] = [];
        }
        $nd = &$node['data'];

        if ($class === 'flow_trigger') {
            $tt = (string) ($nd['trigger_type'] ?? '');
            if (in_array($tt, ['whatsapp_first', 'whatsapp_message', 'contact_created'], true)) {
                if (!array_key_exists('sync_pipeline_on_enter', $nd) || $nd['sync_pipeline_on_enter'] === '') {
                    $nd['sync_pipeline_on_enter'] = 1;
                    $changed = true;
                }
            }
            if (($nd['label'] ?? '') === 'Primeira mensagem WhatsApp' || ($nd['label'] ?? '') === 'Início') {
                $nd['label'] = 'Quando começa';
                $changed = true;
            }
        }

        if ($class === 'flow_message') {
            if (($nd['label'] ?? '') === '' || ($nd['label'] ?? '') === 'Mensagem WhatsApp') {
                $nd['label'] = 'Boas-vindas';
                $changed = true;
            }
        }

        if ($class === 'flow_converse') {
            if (($nd['label'] ?? '') === '' || ($nd['label'] ?? '') === 'Atendimento fluido') {
                $nd['label'] = 'Atendimento IA';
                $changed = true;
            }
            if (empty($nd['end_keywords'])) {
                $nd['end_keywords'] = 'tchau,obrigado,encerrar,finalizar';
                $changed = true;
            }
            if (!isset($nd['max_turns']) || (int) $nd['max_turns'] <= 0) {
                $nd['max_turns'] = 30;
                $changed = true;
            }
        }

        if ($class === 'flow_think') {
            if (($nd['label'] ?? '') === '' || ($nd['label'] ?? '') === 'Pensar & Responder') {
                $nd['label'] = 'Resposta IA (turno)';
                $changed = true;
            }
        }

        if ($class === 'flow_agent') {
            if ($hasWaTrigger) {
                $instructions = trim((string) ($nd['instructions'] ?? ''));
                $mission = trim((string) ($nd['mission'] ?? ''));
                if ($instructions === '' && $mission !== '') {
                    $instructions = $mission;
                }
                if ($instructions === '') {
                    $instructions = 'Conduza o atendimento de forma natural: entenda a necessidade, faça perguntas curtas e indique o próximo passo.';
                }
                $node['class'] = 'flow_converse';
                $node['name'] = 'flow_converse';
                $nd['instructions'] = $instructions;
                $nd['label'] = 'Atendimento IA';
                $nd['max_turns'] = isset($nd['max_turns']) && (int) $nd['max_turns'] > 0 ? (int) $nd['max_turns'] : 30;
                $nd['end_keywords'] = (string) ($nd['end_keywords'] ?? 'tchau,obrigado,encerrar,finalizar');
                unset($nd['mission'], $nd['mode']);
                $changed = true;
            } elseif (($nd['label'] ?? '') === '' || ($nd['label'] ?? '') === 'Agente IA') {
                $nd['label'] = 'Agente IA (uma resposta)';
                $changed = true;
            }
        }

        if ($class === 'flow_condition' && (($nd['label'] ?? '') === '' || ($nd['label'] ?? '') === 'Condição')) {
            $nd['label'] = 'Filtro';
            $changed = true;
        }

        unset($nd);
    }
    unset($node);

    if ($schema < 4) {
        if (auvvo_flow_strip_welcome_before_converse($home)) {
            $changed = true;
        }
    }

    if ($changed) {
        $data['_flow_schema'] = 4;
    }

    return $changed;
}

function auvvo_flow_migrate_json(string $flowDataJson): array
{
    $data = json_decode($flowDataJson, true);
    if (!is_array($data)) {
        return ['json' => $flowDataJson, 'changed' => false];
    }
    if ((int) ($data['_flow_schema'] ?? 0) >= 4) {
        return ['json' => $flowDataJson, 'changed' => false];
    }
    $changed = auvvo_flow_migrate_home_nodes($data);
    if (!$changed) {
        return ['json' => $flowDataJson, 'changed' => false];
    }
    return [
        'json' => json_encode($data, JSON_UNESCAPED_UNICODE),
        'changed' => true,
    ];
}

function auvvo_migration_flow_graphs_modernize_repair(PDO $pdo): void
{
    try {
        $flows = $pdo->query(
            'SELECT id, flow_data FROM crm_automation_flows WHERE flow_data IS NOT NULL AND flow_data != \'\''
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($flows as $flow) {
            $result = auvvo_flow_migrate_json((string) ($flow['flow_data'] ?? ''));
            if (!$result['changed']) {
                continue;
            }
            $pdo->prepare('UPDATE crm_automation_flows SET flow_data = ? WHERE id = ?')
                ->execute([$result['json'], (int) $flow['id']]);
        }
    } catch (PDOException $e) {
        error_log('[Auvvo] migration flow graphs modernize: ' . $e->getMessage());
    }
}
