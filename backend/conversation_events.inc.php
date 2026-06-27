<?php
declare(strict_types=1);

require_once __DIR__ . '/migrations.php';

function auvvo_emit_conversation_event(
    PDO $pdo,
    int $userId,
    int $agentId,
    string $contactJid,
    string $eventType,
    ?array $payload = null
): void {
    auvvo_run_migrations($pdo);
    $allowed = ['message_in', 'message_out', 'handoff', 'ia_paused', 'ia_resumed'];
    if (!in_array($eventType, $allowed, true)) {
        return;
    }
    try {
        $pdo->prepare(
            'INSERT INTO conversation_events (user_id, agent_id, contact_jid, event_type, payload) VALUES (?,?,?,?,?)'
        )->execute([
            $userId,
            $agentId,
            $contactJid,
            $eventType,
            $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (PDOException $e) {
        error_log('[Auvvo] conversation_event: ' . $e->getMessage());
    }
}

function auvvo_agent_user_id(PDO $pdo, int $agentId): int
{
    try {
        $stmt = $pdo->prepare('SELECT user_id FROM agents WHERE id = ? LIMIT 1');
        $stmt->execute([$agentId]);

        return (int) ($stmt->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        return 0;
    }
}
