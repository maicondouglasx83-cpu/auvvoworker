<?php
declare(strict_types=1);

require_once __DIR__ . '/migrations.php';

/**
 * @param list<array{tool:string, payload:array}> $actions
 * @param list<string> $executed
 * @param list<string> $warnings
 */
function auvvo_brain_log_execution(
    PDO $pdo,
    int $userId,
    ?array $contact,
    int $agentId,
    array $actions,
    array $executed,
    array $warnings
): void {
    if ($userId <= 0 || ($actions === [] && $executed === [])) {
        return;
    }
    auvvo_run_migrations($pdo);

    $contactId = (int) ($contact['id'] ?? 0);
    $jid       = trim((string) ($contact['jid'] ?? ''));
    if ($contactId <= 0 && $jid === '') {
        return;
    }

    try {
        $pdo->prepare(
            'INSERT INTO brain_action_log (user_id, contact_id, contact_jid, agent_id, tools_json, executed_json, warnings_json)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $userId,
            $contactId > 0 ? $contactId : null,
            $jid !== '' ? $jid : null,
            $agentId > 0 ? $agentId : null,
            json_encode($actions, JSON_UNESCAPED_UNICODE) ?: '[]',
            json_encode($executed, JSON_UNESCAPED_UNICODE) ?: '[]',
            $warnings !== [] ? (json_encode($warnings, JSON_UNESCAPED_UNICODE) ?: '[]') : null,
        ]);
    } catch (PDOException $e) {
        error_log('[Auvvo] brain_log: ' . $e->getMessage());
    }
}

/**
 * @return list<array<string, mixed>>
 */
function auvvo_brain_list_actions(
    PDO $pdo,
    int $userId,
    ?int $contactId = null,
    ?string $contactJid = null,
    int $limit = 25
): array {
    if ($userId <= 0) {
        return [];
    }
    auvvo_run_migrations($pdo);
    $limit = max(1, min(50, $limit));

    $where = ['user_id = ?'];
    $params = [$userId];

    if ($contactId > 0) {
        $where[] = 'contact_id = ?';
        $params[] = $contactId;
    } elseif ($contactJid !== null && trim($contactJid) !== '') {
        $where[] = 'contact_jid = ?';
        $params[] = trim($contactJid);
    } else {
        return [];
    }

    try {
        $sql = 'SELECT id, contact_id, contact_jid, agent_id, tools_json, executed_json, warnings_json, created_at
                FROM brain_action_log WHERE ' . implode(' AND ', $where)
            . ' ORDER BY id DESC LIMIT ' . $limit;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['tools'] = json_decode((string) ($row['tools_json'] ?? '[]'), true) ?: [];
            $row['executed'] = json_decode((string) ($row['executed_json'] ?? '[]'), true) ?: [];
            $row['warnings'] = json_decode((string) ($row['warnings_json'] ?? '[]'), true) ?: [];
            unset($row['tools_json'], $row['executed_json'], $row['warnings_json']);
        }
        unset($row);

        return $rows;
    } catch (PDOException $e) {
        error_log('[Auvvo] brain_list: ' . $e->getMessage());

        return [];
    }
}
