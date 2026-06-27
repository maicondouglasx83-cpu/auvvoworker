<?php
declare(strict_types=1);

/**
 * Conexões WhatsApp (Evolution) — entidade separada dos agentes (cérebro).
 * Vários agentes/automações podem usar a mesma conexão.
 */

function auvvo_whatsapp_connection_by_token(PDO $pdo, string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    try {
        $st = $pdo->prepare(
            'SELECT * FROM whatsapp_connections WHERE evolution_token = ? ORDER BY id ASC LIMIT 2'
        );
        $st->execute([$token]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return null;
        }
        if (count($rows) > 1) {
            error_log('[Auvvo] evolution_token duplicado globalmente — rejeitando webhook (ids: '
                . implode(',', array_map(static fn ($r) => (string) ($r['id'] ?? ''), $rows)) . ')');

            return null;
        }

        return $rows[0];
    } catch (PDOException $e) {
        return null;
    }
}

function auvvo_whatsapp_connection_by_instance(PDO $pdo, string $instance): ?array
{
    $instance = trim($instance);
    if ($instance === '') {
        return null;
    }
    try {
        $st = $pdo->prepare(
            'SELECT * FROM whatsapp_connections WHERE evolution_instance = ? ORDER BY id ASC LIMIT 2'
        );
        $st->execute([$instance]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return null;
        }
        if (count($rows) > 1) {
            error_log('[Auvvo] evolution_instance duplicado globalmente — rejeitando webhook');

            return null;
        }

        return $rows[0];
    } catch (PDOException $e) {
        return null;
    }
}

function auvvo_whatsapp_connection_get(PDO $pdo, int $userId, int $connectionId): ?array
{
    if ($userId <= 0 || $connectionId <= 0) {
        return null;
    }
    try {
        $st = $pdo->prepare(
            'SELECT * FROM whatsapp_connections WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $st->execute([$connectionId, $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/** @return list<array<string,mixed>> */
function auvvo_whatsapp_connections_list(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    try {
        $st = $pdo->prepare(
            'SELECT wc.*, a.name AS default_agent_name
             FROM whatsapp_connections wc
             LEFT JOIN agents a ON a.id = wc.default_agent_id AND a.user_id = wc.user_id
             WHERE wc.user_id = ?
             ORDER BY wc.name ASC, wc.id ASC'
        );
        $st->execute([$userId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

function auvvo_whatsapp_resolve_connection_from_webhook(PDO $pdo, string $token, string $instanceSlug): ?array
{
    $conn = null;
    if ($token !== '') {
        $conn = auvvo_whatsapp_connection_by_token($pdo, $token);
    }
    if (!$conn && $instanceSlug !== '') {
        $conn = auvvo_whatsapp_connection_by_instance($pdo, $instanceSlug);
    }
    if ($conn) {
        return $conn;
    }

    // Legado: token ainda em agents (pré-migração)
    try {
        $sql = 'SELECT id, user_id, name, evolution_instance, evolution_token,
                       CASE status WHEN \'online\' THEN \'online\' WHEN \'waiting_qr\' THEN \'waiting_qr\' ELSE \'offline\' END AS status,
                       id AS default_agent_id
                FROM agents WHERE %s LIMIT 1';
        $agent = null;
        if ($token !== '') {
            $st = $pdo->prepare(sprintf($sql, 'evolution_token = ?'));
            $st->execute([$token]);
            $agent = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$agent && $instanceSlug !== '') {
            $st = $pdo->prepare(sprintf($sql, 'evolution_instance = ?'));
            $st->execute([$instanceSlug]);
            $agent = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($agent && !empty($agent['evolution_token'])) {
            return [
                'id'                 => (int) $agent['id'],
                'user_id'            => (int) $agent['user_id'],
                'name'               => (string) ($agent['name'] ?? 'Legado'),
                'evolution_instance' => (string) $agent['evolution_instance'],
                'evolution_token'    => (string) $agent['evolution_token'],
                'status'             => (string) ($agent['status'] ?? 'offline'),
                'default_agent_id'   => (int) $agent['id'],
                '_legacy_agent_row'  => true,
            ];
        }
    } catch (PDOException $e) {
    }

    return null;
}

function auvvo_whatsapp_load_agent_brain(PDO $pdo, int $userId, int $agentId): ?array
{
    if ($userId <= 0 || $agentId <= 0) {
        return null;
    }
    try {
        $st = $pdo->prepare(
            'SELECT id, user_id, agent_type, name, role, prompt_base, type_config, model, max_tokens, temperature,
                    response_delay, audio_enabled, audio_voice, handoff_rules, handoff_enabled, handoff_message,
                    bot_language, flow_mode, flow_config, whatsapp_connection_id, status
             FROM agents WHERE id = ? AND user_id = ? AND status != \'draft\' LIMIT 1'
        );
        $st->execute([$agentId, $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/** Agente (cérebro) que atende nesta linha: contato → default da conexão → legado. */
function auvvo_whatsapp_pick_brain_agent(PDO $pdo, int $userId, array $connection, ?array $contact): ?array
{
    $assignedId = (int) ($contact['agent_id'] ?? 0);
    if ($assignedId > 0) {
        $brain = auvvo_whatsapp_load_agent_brain($pdo, $userId, $assignedId);
        if ($brain) {
            return $brain;
        }
    }

    $defaultId = (int) ($connection['default_agent_id'] ?? 0);
    if ($defaultId > 0) {
        $brain = auvvo_whatsapp_load_agent_brain($pdo, $userId, $defaultId);
        if ($brain) {
            return $brain;
        }
    }

    if (!empty($connection['_legacy_agent_row'])) {
        return auvvo_whatsapp_load_agent_brain($pdo, $userId, (int) $connection['id']);
    }

    return null;
}

/**
 * Agente para rotear webhook quando a conexão não tem default_agent_id
 * (usa agente dos fluxos ativos com gatilho/ação nesta linha).
 */
function auvvo_whatsapp_resolve_routing_agent_id(PDO $pdo, int $userId, int $connectionId, array $connection): int
{
    $defaultId = (int) ($connection['default_agent_id'] ?? 0);
    if ($defaultId > 0) {
        return $defaultId;
    }
    if (!empty($connection['_legacy_agent_row'])) {
        return (int) $connection['id'];
    }
    if ($userId <= 0 || $connectionId <= 0) {
        return 0;
    }

    require_once __DIR__ . '/crm_flow_engine.inc.php';

    try {
        $st = $pdo->prepare(
            'SELECT flow_data FROM crm_automation_flows WHERE user_id = ? AND is_active = 1'
        );
        $st->execute([$userId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $nodes = auvvo_flow_parse_nodes((string) ($row['flow_data'] ?? ''));
            $triggerOk = false;
            foreach ($nodes as $node) {
                $class = (string) ($node['class'] ?? '');
                $data = is_array($node['data'] ?? null) ? $node['data'] : [];
                if ($class === 'flow_trigger') {
                    $tt = (string) ($data['trigger_type'] ?? '');
                    if (!in_array($tt, ['whatsapp_first', 'whatsapp_message'], true)) {
                        continue;
                    }
                    $tv = trim((string) ($data['trigger_value'] ?? '*'));
                    if ($tv === '*' || $tv === (string) $connectionId || (int) $tv === $connectionId) {
                        $triggerOk = true;
                    }
                }
            }
            if (!$triggerOk) {
                continue;
            }
            foreach ($nodes as $node) {
                $class = (string) ($node['class'] ?? '');
                $data = is_array($node['data'] ?? null) ? $node['data'] : [];
                if (!in_array($class, ['flow_action', 'flow_message', 'flow_converse', 'flow_think', 'flow_agent'], true)) {
                    continue;
                }
                $nodeConn = (int) ($data['connection_id'] ?? 0);
                if ($nodeConn > 0 && $nodeConn !== $connectionId) {
                    continue;
                }
                $ag = (int) ($data['agent_id'] ?? 0);
                if ($ag > 0) {
                    return $ag;
                }
            }
        }
    } catch (PDOException $e) {
    }

    return 0;
}

/** Funil destino para lead novo via WhatsApp (fluxo ativo desta linha ou padrão). */
function auvvo_whatsapp_resolve_inbound_pipeline_id(PDO $pdo, int $userId, int $connectionId): int
{
    require_once __DIR__ . '/CrmPipelines.php';
    require_once __DIR__ . '/crm_flow_engine.inc.php';

    $pipes = new CrmPipelines($pdo);
    $defaultId = $pipes->defaultPipelineId($userId);
    if ($userId <= 0 || $connectionId <= 0) {
        return $defaultId;
    }

    try {
        $st = $pdo->prepare(
            'SELECT pipeline_id, flow_data FROM crm_automation_flows
             WHERE user_id = ? AND is_active = 1 AND pipeline_id IS NOT NULL AND pipeline_id > 0'
        );
        $st->execute([$userId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int) ($row['pipeline_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $nodes = auvvo_flow_parse_nodes((string) ($row['flow_data'] ?? ''));
            foreach ($nodes as $node) {
                if ((string) ($node['class'] ?? '') !== 'flow_trigger') {
                    continue;
                }
                $data = is_array($node['data'] ?? null) ? $node['data'] : [];
                $tt = (string) ($data['trigger_type'] ?? '');
                if (!in_array($tt, ['whatsapp_first', 'whatsapp_message'], true)) {
                    continue;
                }
                $tv = trim((string) ($data['trigger_value'] ?? '*'));
                if ($tv === '*' || $tv === (string) $connectionId || (int) $tv === $connectionId) {
                    return $pid;
                }
            }
        }
    } catch (PDOException $e) {
    }

    return $defaultId;
}

/** Injeta token/instance da conexão no row do agente (envio Evolution). */
function auvvo_whatsapp_attach_connection_to_agent(array $agent, array $connection): array
{
    $agent['evolution_token'] = (string) ($connection['evolution_token'] ?? '');
    $agent['evolution_instance'] = (string) ($connection['evolution_instance'] ?? '');
    $agent['whatsapp_connection_id'] = (int) ($connection['id'] ?? 0);

    return $agent;
}

/**
 * Garante evolution_token/instance no agente a partir da conexão WhatsApp.
 */
function auvvo_whatsapp_attach_connection_for_agent(
    PDO $pdo,
    int $userId,
    array $agent,
    ?int $connectionId = null
): array {
    if ($userId <= 0 || empty($agent['id'])) {
        return $agent;
    }

    if ($connectionId && $connectionId > 0) {
        $conn = auvvo_whatsapp_connection_get($pdo, $userId, $connectionId);
        if ($conn && !empty($conn['evolution_token'])) {
            return auvvo_whatsapp_attach_connection_to_agent($agent, $conn);
        }
    }

    $linked = (int) ($agent['whatsapp_connection_id'] ?? 0);
    if ($linked > 0) {
        $conn = auvvo_whatsapp_connection_get($pdo, $userId, $linked);
        if ($conn && !empty($conn['evolution_token'])) {
            return auvvo_whatsapp_attach_connection_to_agent($agent, $conn);
        }
    }

    try {
        $st = $pdo->prepare(
            'SELECT * FROM whatsapp_connections WHERE user_id = ? AND default_agent_id = ? LIMIT 1'
        );
        $st->execute([$userId, (int) $agent['id']]);
        $conn = $st->fetch(PDO::FETCH_ASSOC);
        if ($conn && !empty($conn['evolution_token'])) {
            return auvvo_whatsapp_attach_connection_to_agent($agent, $conn);
        }
    } catch (PDOException $e) {
    }

    return $agent;
}

function auvvo_whatsapp_resolve_evolution_token(
    PDO $pdo,
    int $userId,
    ?int $connectionId = null,
    ?int $agentId = null
): ?string {
    if ($connectionId && $connectionId > 0) {
        $conn = auvvo_whatsapp_connection_get($pdo, $userId, $connectionId);
        if ($conn && !empty($conn['evolution_token'])) {
            return (string) $conn['evolution_token'];
        }
    }

    if ($agentId && $agentId > 0) {
        try {
            $st = $pdo->prepare(
                'SELECT a.whatsapp_connection_id, a.evolution_token, wc.evolution_token AS conn_token
                 FROM agents a
                 LEFT JOIN whatsapp_connections wc ON wc.id = a.whatsapp_connection_id AND wc.user_id = a.user_id
                 WHERE a.id = ? AND a.user_id = ? LIMIT 1'
            );
            $st->execute([$agentId, $userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (!empty($row['conn_token'])) {
                    return (string) $row['conn_token'];
                }
                if (!empty($row['evolution_token'])) {
                    return (string) $row['evolution_token'];
                }
            }
        } catch (PDOException $e) {
        }
    }

    return null;
}

function auvvo_whatsapp_connection_id_for_agent(PDO $pdo, int $userId, int $agentId): int
{
    if ($userId <= 0 || $agentId <= 0) {
        return 0;
    }
    try {
        $st = $pdo->prepare(
            'SELECT wc.id FROM whatsapp_connections wc
             WHERE wc.user_id = ? AND (wc.default_agent_id = ? OR wc.id = (
                 SELECT a.whatsapp_connection_id FROM agents a WHERE a.id = ? AND a.user_id = ? LIMIT 1
             ))
             LIMIT 1'
        );
        $st->execute([$userId, $agentId, $agentId, $userId]);
        return (int) ($st->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        return 0;
    }
}

function auvvo_whatsapp_remap_trigger_value(PDO $pdo, int $userId, string $triggerType, string $triggerValue): string
{
    if (!in_array($triggerType, ['whatsapp_first', 'whatsapp_message'], true)) {
        return $triggerValue;
    }
    $v = trim($triggerValue);
    if ($v === '' || $v === '*' || !ctype_digit($v)) {
        return $triggerValue;
    }
    $connId = auvvo_whatsapp_connection_id_for_agent($pdo, $userId, (int) $v);
    if ($connId > 0 && $connId !== (int) $v) {
        return (string) $connId;
    }
    // Já é id de conexão válido?
    if ($connId === 0) {
        $chk = auvvo_whatsapp_connection_get($pdo, $userId, (int) $v);
        if ($chk) {
            return $triggerValue;
        }
    }

    return $triggerValue;
}

function auvvo_whatsapp_connection_status_label(string $status): string
{
    return match ($status) {
        'online'      => 'Conectado',
        'waiting_qr'  => 'Aguardando QR',
        default       => 'Desconectado',
    };
}

/** Linha WhatsApp pronta para enviar/receber (Evolution + UI). */
function auvvo_whatsapp_connection_is_online(array $conn): bool
{
    $st = strtolower(trim((string) ($conn['status'] ?? '')));

    return in_array($st, ['online', 'connected', 'open'], true);
}

function auvvo_whatsapp_update_connection_status(PDO $pdo, string $token, string $instanceSlug, string $dbStatus, ?int $connectionId = null): void
{
    if ($connectionId !== null && $connectionId > 0) {
        try {
            $pdo->prepare('UPDATE whatsapp_connections SET status = ? WHERE id = ?')
                ->execute([$dbStatus, $connectionId]);
        } catch (PDOException $e) {
        }

        return;
    }
    if ($token !== '') {
        try {
            $pdo->prepare('UPDATE whatsapp_connections SET status = ? WHERE evolution_token = ?')
                ->execute([$dbStatus, $token]);
        } catch (PDOException $e) {
        }
        // Legado
        try {
            $pdo->prepare('UPDATE agents SET status = ? WHERE evolution_token = ?')
                ->execute([$dbStatus, $token]);
        } catch (PDOException $e) {
        }
    } elseif ($instanceSlug !== '') {
        try {
            $pdo->prepare('UPDATE whatsapp_connections SET status = ? WHERE evolution_instance = ?')
                ->execute([$dbStatus, $instanceSlug]);
        } catch (PDOException $e) {
        }
        try {
            $pdo->prepare('UPDATE agents SET status = ? WHERE evolution_instance = ?')
                ->execute([$dbStatus, $instanceSlug]);
        } catch (PDOException $e) {
        }
    }
}
