<?php
declare(strict_types=1);

/**
 * Motor conciso de automações: contexto, memória, agente e eventos de gatilho.
 * Usado por crm_automation.inc.php, crm_flow_engine.inc.php e gatilhos.
 */

require_once __DIR__ . '/context_memory.inc.php';

/** Gatilhos inbound WhatsApp não filtram pelo pipeline atual do contato (lead novo entra no funil do fluxo). */
function auvvo_crm_trigger_skips_pipeline_filter(string $triggerType): bool
{
    return in_array($triggerType, ['whatsapp_first', 'whatsapp_message', 'contact_created'], true);
}

/**
 * Move o contato para o funil do fluxo/regra antes de executar ações.
 *
 * @param array<string, mixed> $contact
 */
function auvvo_crm_sync_contact_to_pipeline(PDO $pdo, int $userId, array &$contact, int $targetPipelineId): void
{
    if ($userId <= 0 || $targetPipelineId <= 0 || empty($contact['id'])) {
        return;
    }
    if ((int) ($contact['pipeline_id'] ?? 0) === $targetPipelineId) {
        return;
    }

    require_once __DIR__ . '/CrmPipelines.php';
    require_once __DIR__ . '/Contacts.php';

    $pipes = new CrmPipelines($pdo);
    $slug = $pipes->firstStageSlug($targetPipelineId);
    $pipes->syncContactStage((int) $contact['id'], $targetPipelineId, $slug);
    $fresh = (new Contacts($pdo))->get($userId, (int) $contact['id']);
    if ($fresh) {
        $contact = $fresh;
    }
}

/**
 * Garante tags, custom_fields e memory_json no contato (para condições e templates).
 *
 * @param array<string, mixed> $contact
 * @return array<string, mixed>
 */
function auvvo_crm_hydrate_contact(PDO $pdo, int $userId, array $contact): array
{
    if ($userId <= 0 || empty($contact['id'])) {
        return $contact;
    }

    if (!empty($contact['jid'])) {
        $contact['memory_json'] = auvvo_contact_memory_get($pdo, $userId, (string) $contact['jid']);
    } elseif (!isset($contact['memory_json'])) {
        $contact['memory_json'] = [];
    }

    if (is_string($contact['tags'] ?? null)) {
        $decoded = json_decode($contact['tags'], true);
        $contact['tags'] = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($contact['tags'] ?? null)) {
        $contact['tags'] = [];
    }

    if (is_string($contact['custom_fields'] ?? null)) {
        $decoded = json_decode($contact['custom_fields'], true);
        $contact['custom_fields'] = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($contact['custom_fields'] ?? null)) {
        $contact['custom_fields'] = [];
    }

    return $contact;
}

/**
 * Contexto padrão para um disparo (mensagem + agente da linha WhatsApp).
 *
 * @return array<string, mixed>
 */
function auvvo_crm_build_trigger_context(
    string $triggerType,
    string $triggerValue,
    array $extra = []
): array {
    $ctx = array_merge([
        'trigger_type'  => $triggerType,
        'trigger_value' => $triggerValue,
    ], $extra);

    if (!empty($extra['trigger_agent_id'])) {
        $ctx['trigger_agent_id'] = (int) $extra['trigger_agent_id'];
    }

    return $ctx;
}

/**
 * Agente efetivo do nó: config do fluxo → contato → linha que recebeu o gatilho.
 */
function auvvo_crm_resolve_agent_id(int $nodeAgentId, array $contact, array $context = []): int
{
    if ($nodeAgentId > 0) {
        return $nodeAgentId;
    }
    $fromContact = (int) ($contact['agent_id'] ?? 0);
    if ($fromContact > 0) {
        return $fromContact;
    }

    return (int) ($context['trigger_agent_id'] ?? 0);
}

/**
 * Linha WhatsApp efetiva: nó → gatilho → agente → primeira conexão online.
 */
function auvvo_crm_resolve_whatsapp_connection_id(
    PDO $pdo,
    int $userId,
    int $connectionId,
    int $agentId,
    array $context,
    array $contact
): int {
    require_once __DIR__ . '/whatsapp_connections.inc.php';

    if ($connectionId > 0) {
        return $connectionId;
    }
    $ctxConn = (int) ($context['whatsapp_connection_id'] ?? 0);
    if ($ctxConn > 0) {
        return $ctxConn;
    }
    if ($agentId > 0) {
        $cid = auvvo_whatsapp_connection_id_for_agent($pdo, $userId, $agentId);
        if ($cid > 0) {
            return $cid;
        }
    }
    $contactAgent = (int) ($contact['agent_id'] ?? 0);
    if ($contactAgent > 0) {
        $cid = auvvo_whatsapp_connection_id_for_agent($pdo, $userId, $contactAgent);
        if ($cid > 0) {
            return $cid;
        }
    }
    $conns = auvvo_whatsapp_connections_list($pdo, $userId);
    foreach ($conns as $c) {
        if (auvvo_whatsapp_connection_is_online($c)) {
            return (int) ($c['id'] ?? 0);
        }
    }

    return (int) ($conns[0]['id'] ?? 0);
}

/**
 * Após assign/invoke no fluxo, qual agente deve responder IA / pausas neste webhook.
 *
 * @param array<string, mixed> $receivingAgent linha do webhook (agents row)
 * @return array<string, mixed>|null agents row
 */
function auvvo_crm_resolve_whatsapp_agent_row(PDO $pdo, int $userId, array $receivingAgent, array $contact, ?array $connection = null): ?array
{
    require_once __DIR__ . '/whatsapp_connections.inc.php';

    $assignedId = (int) ($contact['agent_id'] ?? 0);
    $recvId = (int) ($receivingAgent['id'] ?? 0);
    $brain = null;

    if ($assignedId > 0 && $assignedId !== $recvId) {
        $brain = auvvo_whatsapp_load_agent_brain($pdo, $userId, $assignedId);
    }
    if (!$brain) {
        $brain = $receivingAgent;
    }

    if ($connection && !empty($connection['evolution_token'])) {
        return auvvo_whatsapp_attach_connection_to_agent($brain, $connection);
    }

    $connId = (int) ($brain['whatsapp_connection_id'] ?? $receivingAgent['whatsapp_connection_id'] ?? 0);
    if ($connId > 0) {
        $conn = auvvo_whatsapp_connection_get($pdo, $userId, $connId);
        if ($conn) {
            return auvvo_whatsapp_attach_connection_to_agent($brain, $conn);
        }
    }

    if (!empty($brain['evolution_token'])) {
        return $brain;
    }

    return $receivingAgent;
}

/**
 * Dispara regras + fluxos visuais para vários pares (tipo, valor) sem repetir o mesmo par.
 *
 * @param list<array{0:string,1:string}> $events [triggerType, triggerValue]
 * @param array<string, mixed> $contact
 * @param array<string, mixed> $context
 */
function auvvo_crm_run_automation_events(
    PDO $pdo,
    int $userId,
    array $events,
    array $contact,
    array $context = []
): void {
    if ($userId <= 0 || empty($contact['id'])) {
        return;
    }

    $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);
    $seen = [];

    foreach ($events as $ev) {
        if (!is_array($ev) || count($ev) < 2) {
            continue;
        }
        $type = trim((string) $ev[0]);
        $value = trim((string) $ev[1]);
        if ($type === '') {
            continue;
        }
        $key = $type . "\0" . $value;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $ctx = auvvo_crm_build_trigger_context($type, $value, $context);
        auvvo_crm_run_automations($pdo, $userId, $type, $value, $contact, $ctx);
    }
}

/**
 * Extrai config executável de um nó flow_action (sem chaves de condição/UI).
 *
 * @return array<string, mixed>
 */
function auvvo_crm_flow_action_config(array $nodeData): array
{
    $exec = $nodeData;
    unset($exec['action_type'], $exec['label'], $exec['_node_id'], $exec['_node_label']);
    foreach (auvvo_crm_condition_keys() as $ck) {
        unset($exec[$ck]);
    }

    return $exec;
}

/**
 * JIDs alternativos (mesmo padrão do webhook) para bater conversation_logs.
 *
 * @return list<string>
 */
function auvvo_crm_contact_jid_variants(string $jid): array
{
    $jid = trim($jid);
    if ($jid === '') {
        return [];
    }
    $variants = [$jid];
    $local = explode('@', $jid, 2)[0] ?? '';
    $digits = preg_replace('/\D/', '', $local);
    if ($digits !== '') {
        foreach ([$digits . '@s.whatsapp.net', $digits . '@c.us', $digits] as $v) {
            if ($v !== '' && !in_array($v, $variants, true)) {
                $variants[] = $v;
            }
        }
    }

    return $variants;
}

/**
 * Mensagens recebidas do lead na sessão (conversation_logs), ordem cronológica.
 *
 * @param string $scope last|today|recent
 * @return list<string>
 */
function auvvo_crm_session_inbound_messages(
    PDO $pdo,
    array $contact,
    array $context = [],
    string $scope = 'last',
    int $limit = 10
): array {
    $agentId = auvvo_crm_resolve_agent_id(0, $contact, $context);
    $jid = trim((string) ($contact['jid'] ?? ''));
    $contactId = (int) ($contact['id'] ?? 0);
    if ($agentId <= 0 && $contactId <= 0) {
        return [];
    }

    $scope = in_array($scope, ['last', 'today', 'recent'], true) ? $scope : 'last';
    $limit = max(1, min(50, $limit));
    if ($scope === 'last') {
        $limit = 1;
    } elseif ($scope === 'today') {
        $limit = min(50, max($limit, 30));
    }

    $jidVariants = auvvo_crm_contact_jid_variants($jid);
    $where = [];
    $params = [];

    if ($agentId > 0 && $jidVariants !== []) {
        $jPlace = implode(',', array_fill(0, count($jidVariants), '?'));
        $where[] = "(agent_id = ? AND contact_jid IN ({$jPlace}))";
        $params[] = $agentId;
        foreach ($jidVariants as $jv) {
            $params[] = $jv;
        }
    }
    if ($contactId > 0) {
        $where[] = 'contact_id = ?';
        $params[] = $contactId;
    }
    if ($where === []) {
        return [];
    }

    $sqlWhere = '(' . implode(' OR ', $where) . ')';
    $sqlWhere .= " AND CHAR_LENGTH(TRIM(COALESCE(incoming_msg,''))) > 0";
    if ($scope === 'today') {
        $sqlWhere .= ' AND DATE(created_at) = CURDATE()';
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT incoming_msg FROM conversation_logs
             WHERE {$sqlWhere}
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $raw) {
        $t = trim((string) $raw);
        if ($t !== '') {
            $out[] = $t;
        }
    }

    return array_reverse($out);
}

/**
 * Inclui a mensagem do gatilho atual (ainda não logada em conversation_logs).
 *
 * @param list<string> $messages
 * @return list<string>
 */
function auvvo_crm_session_append_trigger(array $messages, array $context): array
{
    $trigger = trim((string) ($context['message_body'] ?? ''));
    if ($trigger === '') {
        return $messages;
    }
    if ($messages === [] || $messages[array_key_last($messages)] !== $trigger) {
        if (!in_array($trigger, $messages, true)) {
            $messages[] = $trigger;
        }
    }

    return $messages;
}

/**
 * Texto único para condições “mensagem contém” (gatilho + mensagens de hoje na sessão).
 */
function auvvo_crm_condition_message_corpus(PDO $pdo, array $contact, array $context = []): string
{
    $today = auvvo_crm_session_append_trigger(
        auvvo_crm_session_inbound_messages($pdo, $contact, $context, 'today', 40),
        $context
    );

    return $today === [] ? '' : implode("\n", $today);
}

/**
 * Valor gravado no nó Memória conforme origem escolhida.
 *
 * @param array<string, mixed> $nodeData memory_key, value_mode, value, session_limit
 */
function auvvo_crm_flow_memory_value(
    PDO $pdo,
    int $userId,
    array $nodeData,
    array $contact,
    array $context = []
): string {
    $mode = (string) ($nodeData['value_mode'] ?? 'session_today');
    $sessionLimit = max(1, min(20, (int) ($nodeData['session_limit'] ?? 8)));

    switch ($mode) {
        case 'last_message':
            $val = trim((string) ($context['message_body'] ?? ''));
            if ($val === '') {
                $last = auvvo_crm_session_inbound_messages($pdo, $contact, $context, 'last', 1);
                $val = $last[0] ?? '';
            }
            return $val;

        case 'session_last':
            $last = auvvo_crm_session_append_trigger(
                auvvo_crm_session_inbound_messages($pdo, $contact, $context, 'last', 1),
                $context
            );
            return $last[array_key_last($last)] ?? '';

        case 'session_today':
            $today = auvvo_crm_session_append_trigger(
                auvvo_crm_session_inbound_messages($pdo, $contact, $context, 'today', 40),
                $context
            );
            return $today === [] ? '' : implode("\n", $today);

        case 'session_recent':
            $recent = auvvo_crm_session_append_trigger(
                auvvo_crm_session_inbound_messages($pdo, $contact, $context, 'recent', $sessionLimit),
                $context
            );
            if ($recent === []) {
                return '';
            }
            $lines = [];
            foreach ($recent as $i => $line) {
                $lines[] = ($i + 1) . '. ' . $line;
            }
            return implode("\n", $lines);

        case 'fixed':
            return trim((string) ($nodeData['value'] ?? ''));

        case 'template':
            return auvvo_crm_render_message($pdo, (string) ($nodeData['value'] ?? ''), $contact, $context);

        default:
            return '';
    }
}

/**
 * Variáveis extras de sessão para templates (mensagens_hoje, sessao, etc.).
 *
 * @return array<string, string>
 */
function auvvo_crm_session_message_vars(PDO $pdo, array $contact, array $context = []): array
{
    $last = auvvo_crm_session_append_trigger(
        auvvo_crm_session_inbound_messages($pdo, $contact, $context, 'last', 1),
        $context
    );
    $today = auvvo_crm_session_append_trigger(
        auvvo_crm_session_inbound_messages($pdo, $contact, $context, 'today', 40),
        $context
    );
    $recent = auvvo_crm_session_append_trigger(
        auvvo_crm_session_inbound_messages($pdo, $contact, $context, 'recent', 8),
        $context
    );

    $trigger = trim((string) ($context['message_body'] ?? ''));
    $lastText = $last[array_key_last($last)] ?? '';

    return [
        'mensagem'        => $trigger !== '' ? $trigger : $lastText,
        'mensagens_hoje'  => $today === [] ? '' : implode("\n", $today),
        'sessao'          => $recent === [] ? '' : implode("\n", $recent),
        'ultima_sessao'   => $lastText,
    ];
}
