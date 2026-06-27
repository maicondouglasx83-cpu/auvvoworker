<?php
declare(strict_types=1);

/**
 * Credenciais Evolution: settings do tenant > .env global.
 *
 * @return array{url:string, key:string}
 */
function auvvo_evolution_credentials(PDO $pdo, int $userId): array
{
    $url = defined('EVOLUTION_API_URL') ? (string) EVOLUTION_API_URL : '';
    $key = defined('EVOLUTION_API_KEY') ? (string) EVOLUTION_API_KEY : '';

    if ($userId > 0) {
        try {
            $stmt = $pdo->prepare('SELECT evolution_url, evolution_key FROM settings WHERE user_id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $u = trim((string) ($row['evolution_url'] ?? ''));
                $k = trim((string) ($row['evolution_key'] ?? ''));
                if ($u !== '') {
                    $url = $u;
                }
                if ($k !== '') {
                    $key = $k;
                }
            }
        } catch (PDOException $e) {
        }
    }

    return ['url' => rtrim($url, '/'), 'key' => $key];
}

function auvvo_evolution_user_id_for_agent(PDO $pdo, int $agentId): int
{
    try {
        $stmt = $pdo->prepare('SELECT user_id FROM agents WHERE id = ? LIMIT 1');
        $stmt->execute([$agentId]);

        return (int) ($stmt->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        return 0;
    }
}
