<?php
declare(strict_types=1);

/**
 * Histórico de conversation_logs para LLM — usado por webhook, fluxos e worker.
 */

if (!function_exists('getConversationHistory')) {

function getConversationHistory(PDO $pdo, int $agent_id, string $primary_jid, int $maxMessages = 10, string $alt_jid = '', string $peer_digits = ''): array
{
    require_once __DIR__ . '/context_memory.inc.php';
    $useCompact = ($_ENV['CONTEXT_USE_SUMMARY'] ?? '1') !== '0';
    if ($useCompact) {
        $summary = auvvo_conversation_summary_get($pdo, $agent_id, $primary_jid);
        $recent  = getConversationHistoryRaw($pdo, $agent_id, $primary_jid, 3, $alt_jid, $peer_digits);
        if ($summary !== '') {
            $messages = [['role' => 'user', 'content' => "[Resumo da conversa anterior]\n" . $summary]];
            foreach ($recent as $m) {
                $messages[] = $m;
            }

            return $messages;
        }
    }

    return getConversationHistoryRaw($pdo, $agent_id, $primary_jid, $maxMessages, $alt_jid, $peer_digits);
}

function getConversationHistoryRaw(PDO $pdo, int $agent_id, string $primary_jid, int $maxMessages = 10, string $alt_jid = '', string $peer_digits = ''): array
{
    try {
        $conds = ['contact_jid = ?'];
        $params = [$agent_id, $primary_jid];
        if ($alt_jid !== '' && $alt_jid !== $primary_jid) {
            $conds[] = 'contact_jid = ?';
            $params[] = $alt_jid;
        }
        if ($peer_digits !== '') {
            $conds[] = 'contact_jid = ?';
            $conds[] = 'contact_jid = ?';
            $params[] = $peer_digits . '@s.whatsapp.net';
            $params[] = $peer_digits . '@c.us';
        }
        $contactWhere = implode(' OR ', $conds);

        $stmt = $pdo->prepare(
            "SELECT incoming_msg, response_msg, type
             FROM conversation_logs
             WHERE agent_id = ? AND ($contactWhere)
               AND (
                 type = 'handoff'
                 OR (type = 'ai' AND CHAR_LENGTH(TRIM(COALESCE(response_msg,''))) > 0)
               )
             ORDER BY id DESC
             LIMIT ?"
        );
        $params[] = $maxMessages;
        if (!$stmt instanceof PDOStatement) {
            return [];
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        $rows = array_reverse($rows);
        $messages = [];
        require_once __DIR__ . '/auvvo_brain_tools.inc.php';
        foreach ($rows as $row) {
            if (!empty($row['incoming_msg'])) {
                $messages[] = ['role' => 'user', 'content' => $row['incoming_msg']];
            }
            if (!empty($row['response_msg'])) {
                $clean = auvvo_brain_strip_actions_block((string) $row['response_msg']);
                $clean = trim(preg_replace('/\[\[GCAL_EVENT\]\].*$/s', '', $clean));
                if ($clean !== '') {
                    $messages[] = ['role' => 'assistant', 'content' => $clean];
                }
            }
        }

        return $messages;
    } catch (PDOException $e) {
        return [];
    }
}

}
