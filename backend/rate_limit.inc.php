<?php
declare(strict_types=1);

require_once __DIR__ . '/migrations.php';

function auvvo_rate_limit_config(): array
{
    return [
        'min_interval_sec' => max(1, (int) ($_ENV['RATE_LIMIT_MIN_INTERVAL_SEC'] ?? 2)),
        'per_peer_min'     => max(5, (int) ($_ENV['RATE_LIMIT_PER_PEER_MIN'] ?? 30)),
        'per_agent_min'    => max(10, (int) ($_ENV['RATE_LIMIT_PER_AGENT_MIN'] ?? 60)),
    ];
}

function auvvo_rate_bucket_hit(PDO $pdo, string $bucketKey, int $maxHits, int $windowSeconds): bool
{
    auvvo_run_migrations($pdo);
    $windowStart = date('Y-m-d H:i:s', (int) (floor(time() / $windowSeconds) * $windowSeconds));

    try {
        $pdo->prepare(
            'INSERT INTO auvvo_rate_buckets (bucket_key, window_start, hit_count) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE hit_count = hit_count + 1'
        )->execute([$bucketKey, $windowStart]);

        $stmt = $pdo->prepare(
            'SELECT hit_count FROM auvvo_rate_buckets WHERE bucket_key = ? AND window_start = ?'
        );
        $stmt->execute([$bucketKey, $windowStart]);
        $count = (int) ($stmt->fetchColumn() ?: 0);

        return $count <= $maxHits;
    } catch (PDOException $e) {
        error_log('[Auvvo] rate_limit: ' . $e->getMessage());

        return true;
    }
}

/** @return array{allowed:bool, reason:string} */
function auvvo_rate_limit_allow_ai_reply(PDO $pdo, int $agentId, string $lockPeer): array
{
    $cfg = auvvo_rate_limit_config();
    $peerKey  = "ai_peer:{$agentId}:{$lockPeer}";
    $agentKey = "ai_agent:{$agentId}";

    if (!auvvo_rate_bucket_hit($pdo, $peerKey, $cfg['per_peer_min'], 60)) {
        return ['allowed' => false, 'reason' => 'rate_peer_minute'];
    }
    if (!auvvo_rate_bucket_hit($pdo, $agentKey, $cfg['per_agent_min'], 60)) {
        return ['allowed' => false, 'reason' => 'rate_agent_minute'];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT id FROM conversation_logs
             WHERE agent_id = ? AND type = 'ai' AND response_msg IS NOT NULL AND response_msg != ''
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$agentId]);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($last) {
            $stmt2 = $pdo->prepare(
                'SELECT created_at FROM conversation_logs WHERE id = ?'
            );
            $stmt2->execute([(int) $last['id']]);
            $ts = strtotime((string) ($stmt2->fetchColumn() ?: ''));
            if ($ts && (time() - $ts) < $cfg['min_interval_sec']) {
                return ['allowed' => false, 'reason' => 'min_interval'];
            }
        }
    } catch (PDOException $e) {
    }

    return ['allowed' => true, 'reason' => ''];
}

/**
 * Anti-loop: muitas respostas IA seguidas sem inbound humano distinto.
 */
function auvvo_anti_bot_loop_exceeded(PDO $pdo, int $agentId, string $canonicalJid, string $remoteJid, string $peerDigits): bool
{
    $maxAiStreak = max(3, (int) ($_ENV['ANTI_BOT_LOOP_MAX_AI_STREAK'] ?? 8));
    $conds = ['contact_jid = ?', 'contact_jid = ?'];
    $params = [$canonicalJid, $remoteJid];
    if ($peerDigits !== '') {
        $conds[] = 'contact_jid = ?';
        $params[] = $peerDigits . '@s.whatsapp.net';
    }
    $where = implode(' OR ', $conds);

    try {
        $stmt = $pdo->prepare(
            "SELECT type FROM conversation_logs
             WHERE agent_id = ? AND ($where)
             ORDER BY id DESC LIMIT ?"
        );
        $stmt->execute(array_merge([$agentId], $params, [$maxAiStreak + 2]));
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) < $maxAiStreak) {
            return false;
        }
        $aiOnly = 0;
        foreach ($rows as $type) {
            if ($type === 'ai' || $type === 'handoff') {
                $aiOnly++;
            } else {
                break;
            }
        }

        return $aiOnly >= $maxAiStreak;
    } catch (PDOException $e) {
        return false;
    }
}
