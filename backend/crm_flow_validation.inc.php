<?php
declare(strict_types=1);

require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/crm_flow_engine.inc.php';
require_once __DIR__ . '/whatsapp_connections.inc.php';

/**
 * @return array{valid:bool,errors:list<array{node_id:string,message:string,severity:string}>,warnings:list<array{node_id:string,message:string}>}
 */
function auvvo_flow_validate_graph(PDO $pdo, int $userId, string $flowDataJson, bool $forPublish = false): array
{
    $errors = [];
    $warnings = [];
    $nodes = auvvo_flow_parse_nodes($flowDataJson);

    if ($nodes === []) {
        return [
            'valid' => false,
            'errors' => [['node_id' => '', 'message' => 'Fluxo vazio ou JSON inválido', 'severity' => 'block']],
            'warnings' => [],
        ];
    }

    $triggers = [];
    $reachable = [];
    foreach ($nodes as $nodeId => $node) {
        if (auvvo_flow_node_type($node) === 'flow_trigger') {
            $triggers[] = (string) $nodeId;
        }
    }

    if ($triggers === []) {
        $errors[] = ['node_id' => '', 'message' => 'Adicione pelo menos um nó Início (gatilho)', 'severity' => 'block'];
    }

    foreach ($triggers as $tid) {
        auvvo_flow_validation_reachable($nodes, $tid, $reachable);
    }

    foreach ($nodes as $nodeId => $node) {
        $nid = (string) $nodeId;
        $class = auvvo_flow_node_type($node);
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];

        if ($class !== 'flow_trigger' && !isset($reachable[$nid])) {
            $warnings[] = ['node_id' => $nid, 'message' => 'Nó desconectado do Início — não será executado'];
        }

        if ($class === 'flow_trigger') {
            $tt = (string) ($data['trigger_type'] ?? '');
            $tv = trim((string) ($data['trigger_value'] ?? '*')) ?: '*';
            if ($forPublish && in_array($tt, ['whatsapp_first', 'whatsapp_message'], true) && ($tv === '*' || $tv === '0')) {
                $warnings[] = [
                    'node_id' => $nid,
                    'message' => 'Gatilho WhatsApp em «Qualquer linha» — pode conflitar com outros fluxos na mesma conta',
                ];
            }
        }

        if ($class === 'flow_message') {
            if (trim((string) ($data['message'] ?? '')) === '') {
                $msg = 'Mensagem WhatsApp vazia';
                if ($forPublish) {
                    $errors[] = ['node_id' => $nid, 'message' => $msg, 'severity' => 'block'];
                } else {
                    $warnings[] = ['node_id' => $nid, 'message' => $msg];
                }
            }
            if ($forPublish && (int) ($data['connection_id'] ?? 0) <= 0) {
                $warnings[] = ['node_id' => $nid, 'message' => 'Mensagem sem conexão WhatsApp selecionada'];
            }
        }

        if ($class === 'flow_agent') {
            if ((int) ($data['agent_id'] ?? 0) <= 0) {
                $errors[] = ['node_id' => $nid, 'message' => 'Agente IA não selecionado', 'severity' => 'block'];
            }
        }

        if ($class === 'flow_think') {
            if ((int) ($data['agent_id'] ?? 0) <= 0) {
                $errors[] = ['node_id' => $nid, 'message' => 'Pensar & Responder sem agente IA', 'severity' => 'block'];
            }
            if (trim((string) ($data['instructions'] ?? '')) === '') {
                $msg = 'Instruções vazias no nó Pensar & Responder';
                if ($forPublish) {
                    $errors[] = ['node_id' => $nid, 'message' => $msg, 'severity' => 'block'];
                } else {
                    $warnings[] = ['node_id' => $nid, 'message' => $msg];
                }
            }
        }

        if ($class === 'flow_converse') {
            if ((int) ($data['agent_id'] ?? 0) <= 0) {
                $errors[] = ['node_id' => $nid, 'message' => 'Atendimento fluido sem agente IA', 'severity' => 'block'];
            }
            if (trim((string) ($data['instructions'] ?? $data['mission'] ?? '')) === '') {
                $msg = 'Instruções vazias no Atendimento fluido';
                if ($forPublish) {
                    $errors[] = ['node_id' => $nid, 'message' => $msg, 'severity' => 'block'];
                } else {
                    $warnings[] = ['node_id' => $nid, 'message' => $msg];
                }
            }
        }

        if ($class === 'flow_wait_reply') {
            $replyNext = auvvo_flow_next_node_ids($node, 'output_1');
            if ($replyNext === []) {
                $warnings[] = ['node_id' => $nid, 'message' => 'Aguardar resposta sem ramo «Respondeu» conectado'];
            }
        }

        if ($class === 'flow_action') {
            $at = (string) ($data['action_type'] ?? '');
            if ($at === 'add_tag' && trim((string) ($data['tag'] ?? '')) === '') {
                $warnings[] = ['node_id' => $nid, 'message' => 'Ação CRM sem tag definida'];
            }
            if ($at === 'http_preset' && (int) ($data['preset_id'] ?? 0) <= 0) {
                $warnings[] = ['node_id' => $nid, 'message' => 'Integração HTTP sem preset selecionado'];
            }
            if ($at === 'move_stage' && trim((string) ($data['stage'] ?? '')) === '') {
                $warnings[] = ['node_id' => $nid, 'message' => 'Mover estágio sem coluna destino selecionada'];
            }
        }
    }

    $hasOutcome = false;
    foreach ($reachable as $nid => $_seen) {
        $class = auvvo_flow_node_type($nodes[$nid] ?? []);
        if (in_array($class, ['flow_message', 'flow_agent', 'flow_think', 'flow_converse', 'flow_action'], true)) {
            $hasOutcome = true;
            break;
        }
    }
    if (!$hasOutcome && $triggers !== []) {
        $errors[] = [
            'node_id' => $triggers[0],
            'message' => 'Nenhuma ação, mensagem ou agente conectado ao Início — arraste do ponto de saída do gatilho',
            'severity' => 'block',
        ];
    }

    $blocks = array_filter($errors, static fn ($e) => ($e['severity'] ?? '') === 'block');

    return [
        'valid' => $blocks === [],
        'errors' => $errors,
        'warnings' => $warnings,
    ];
}

/**
 * @param array<string, array> $nodes
 * @param array<string, bool> $reachable
 */
function auvvo_flow_validation_reachable(array $nodes, string $startId, array &$reachable): void
{
    if ($startId === '' || isset($reachable[$startId]) || !isset($nodes[$startId])) {
        return;
    }
    $reachable[$startId] = true;
    $node = $nodes[$startId];
    foreach ($node['outputs'] ?? [] as $outKey => $_out) {
        if (!is_string($outKey) || !str_starts_with($outKey, 'output_')) {
            continue;
        }
        foreach (auvvo_flow_next_node_ids($node, $outKey) as $next) {
            auvvo_flow_validation_reachable($nodes, $next, $reachable);
        }
    }
}

/**
 * Checklist para publicar fluxo.
 *
 * @return array{ready:bool,items:list<array{id:string,label:string,status:string,detail?:string}>}
 */
function auvvo_flow_publish_checklist(PDO $pdo, int $userId, string $flowDataJson, int $flowId = 0): array
{
    $items = [];
    $nodes = auvvo_flow_parse_nodes($flowDataJson);
    $validation = auvvo_flow_validate_graph($pdo, $userId, $flowDataJson, true);

    $items[] = [
        'id' => 'graph',
        'label' => 'Grafo válido (Início → ação)',
        'status' => $validation['valid'] ? 'ok' : 'fail',
        'detail' => $validation['valid'] ? '' : ($validation['errors'][0]['message'] ?? 'Erros no fluxo'),
    ];

    $hasDelay = false;
    $hasWait = false;
    $needsWa = false;
    $agentIds = [];
    foreach ($nodes as $node) {
        $class = auvvo_flow_node_type($node);
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        if ($class === 'flow_delay') {
            $hasDelay = true;
        }
        if ($class === 'flow_wait_reply') {
            $hasWait = true;
        }
        if (in_array($class, ['flow_message', 'flow_agent', 'flow_think', 'flow_converse'], true)) {
            $needsWa = true;
            if ((int) ($data['agent_id'] ?? 0) > 0) {
                $agentIds[(int) $data['agent_id']] = true;
            }
        }
    }

    $conns = auvvo_whatsapp_connections_list($pdo, $userId);
    $waOk = $conns !== [] && array_reduce(
        $conns,
        static fn ($c, $x) => $c || auvvo_whatsapp_connection_is_online($x),
        false
    );
    if ($needsWa) {
        $items[] = [
            'id' => 'whatsapp',
            'label' => 'Linha WhatsApp conectada',
            'status' => $waOk ? 'ok' : 'fail',
            'detail' => $waOk ? '' : 'Conecte em Conexões antes de publicar',
        ];
    }

    $aiOk = true;
    $aiDetail = '';
    if ($agentIds !== []) {
        $st = $pdo->prepare('SELECT id, name, model FROM agents WHERE user_id = ? AND id IN (' . implode(',', array_map('intval', array_keys($agentIds))) . ')');
        $st->execute([$userId]);
        $found = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($found) < count($agentIds)) {
            $aiOk = false;
            $aiDetail = 'Agente configurado no fluxo não encontrado';
        } else {
            $stmtSet = $pdo->prepare('SELECT openai_key, gemini_key FROM settings WHERE user_id = ? LIMIT 1');
            $stmtSet->execute([$userId]);
            $settings = $stmtSet->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach ($found as $ag) {
                $model = trim((string) ($ag['model'] ?? ''));
                $isGemini = strpos($model, 'gemini') === 0;
                $isOr = $model === 'auvvo-ai' || strpos($model, 'openrouter/') === 0 || strpos($model, '/') !== false;
                if ($isGemini && trim($settings['gemini_key'] ?? '') === '' && !(defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '')) {
                    $aiOk = false;
                    $aiDetail = 'Chave Gemini não configurada para ' . ($ag['name'] ?? 'agente');
                    break;
                }
                if (!$isGemini && !$isOr && trim($settings['openai_key'] ?? '') === '') {
                    $aiOk = false;
                    $aiDetail = 'Chave OpenAI não configurada para ' . ($ag['name'] ?? 'agente');
                    break;
                }
            }
        }
        $items[] = [
            'id' => 'ai_keys',
            'label' => 'Chaves de IA dos agentes',
            'status' => $aiOk ? 'ok' : 'warn',
            'detail' => $aiDetail,
        ];
    }

    if ($hasDelay || $hasWait) {
        $workerOk = false;
        try {
            $hb = $pdo->query("SELECT meta_value FROM auvvo_app_meta WHERE meta_key = 'worker_heartbeat' LIMIT 1");
            $ts = $hb ? (int) $hb->fetchColumn() : 0;
            $workerOk = $ts > 0 && (time() - $ts) < 120;
        } catch (PDOException $e) {
            $workerOk = false;
        }
        $items[] = [
            'id' => 'worker',
            'label' => 'Worker ativo (filas e timeouts)',
            'status' => $workerOk ? 'ok' : 'warn',
            'detail' => $workerOk ? '' : 'Inicie auvvo-worker para delays e aguardar resposta',
        ];
    }

    if ($flowId > 0) {
        require_once __DIR__ . '/crm_automation_conflicts.inc.php';
        $warnings = auvvo_crm_automation_dedupe_warnings($pdo, $userId);
        $hasConflict = false;
        foreach ($warnings as $w) {
            foreach ($w['items'] ?? [] as $f) {
                if (($f['kind'] ?? '') === 'flow' && (int) ($f['id'] ?? 0) === $flowId) {
                    $hasConflict = true;
                    break 2;
                }
            }
        }
        $items[] = [
            'id' => 'dedupe',
            'label' => 'Sem conflito de gatilho duplicado',
            'status' => $hasConflict ? 'warn' : 'ok',
            'detail' => $hasConflict ? 'Outro fluxo ativo usa o mesmo gatilho global' : '',
        ];
    }

    $items[] = [
        'id' => 'test',
        'label' => 'Testado no simulador (recomendado)',
        'status' => 'info',
        'detail' => 'Use a aba Testar antes de ir ao ar',
    ];

    $ready = true;
    foreach ($items as $it) {
        if (($it['status'] ?? '') === 'fail') {
            $ready = false;
        }
    }

    return ['ready' => $ready, 'items' => $items, 'validation' => $validation];
}

/** @return array<string, array{message:string,at:string}> */
function auvvo_flow_node_last_errors(PDO $pdo, int $userId, int $flowId): array
{
    if ($userId <= 0 || $flowId <= 0) {
        return [];
    }
    auvvo_run_migrations($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT s.node_id, s.detail, s.created_at
             FROM crm_automation_run_steps s
             INNER JOIN crm_automation_runs r ON r.id = s.run_id
             INNER JOIN (
               SELECT s2.node_id, MAX(s2.id) AS max_id
               FROM crm_automation_run_steps s2
               INNER JOIN crm_automation_runs r2 ON r2.id = s2.run_id
               WHERE r2.user_id = ? AND r2.flow_id = ? AND s2.status = ?
               GROUP BY s2.node_id
             ) latest ON latest.max_id = s.id
             WHERE r.user_id = ? AND r.flow_id = ? AND s.status = ?'
        );
        $st->execute([$userId, $flowId, 'error', $userId, $flowId, 'error']);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $nid = (string) ($row['node_id'] ?? '');
            if ($nid === '') {
                continue;
            }
            $out[$nid] = [
                'message' => (string) ($row['detail'] ?? 'Erro'),
                'at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $out;
    } catch (PDOException $e) {
        return [];
    }
}
