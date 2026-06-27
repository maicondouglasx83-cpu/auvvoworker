<?php
declare(strict_types=1);

require_once __DIR__ . '/migrations.php';

/**
 * Chaves de API para acesso programático (API interna v1).
 */
class ApiAuth
{
    public const PERM_CRM_READ  = 'crm.read';
    public const PERM_CRM_WRITE = 'crm.write';
    public const PERM_AGENTS_READ = 'agents.read';
    public const PERM_WEBHOOKS  = 'webhooks.manage';

    public static function allPermissions(): array
    {
        return [
            self::PERM_CRM_READ,
            self::PERM_CRM_WRITE,
            self::PERM_AGENTS_READ,
            self::PERM_WEBHOOKS,
        ];
    }

    public static function generateKey(): string
    {
        return 'auvvo_live_' . bin2hex(random_bytes(24));
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    public static function createKey(PDO $pdo, int $userId, string $name, array $permissions): array
    {
        auvvo_run_migrations($pdo);
        $plain = self::generateKey();
        $hash  = self::hashKey($plain);
        $prefix = substr($plain, 0, 16);
        $perms = json_encode(array_values(array_intersect($permissions, self::allPermissions())), JSON_UNESCAPED_UNICODE);
        $pdo->prepare(
            'INSERT INTO user_api_keys (user_id, name, api_key_prefix, api_key_hash, permissions) VALUES (?,?,?,?,?)'
        )->execute([$userId, $name ?: 'API Key', $prefix, $hash, $perms]);

        return ['id' => (int) $pdo->lastInsertId(), 'api_key' => $plain, 'prefix' => $prefix];
    }

    public static function listKeys(PDO $pdo, int $userId): array
    {
        auvvo_run_migrations($pdo);
        try {
            $stmt = $pdo->prepare(
                'SELECT id, name, api_key_prefix, permissions, last_used_at, is_active, created_at
                 FROM user_api_keys WHERE user_id = ? ORDER BY id DESC'
            );
            $stmt->execute([$userId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    public static function revokeKey(PDO $pdo, int $userId, int $keyId): void
    {
        $pdo->prepare('UPDATE user_api_keys SET is_active = 0 WHERE id = ? AND user_id = ?')->execute([$keyId, $userId]);
    }

    /**
     * @return array{user_id:int, key_id:int, permissions:array}|null
     */
    public static function authenticate(PDO $pdo, string $apiKey): ?array
    {
        if ($apiKey === '' || !str_starts_with($apiKey, 'auvvo_live_')) {
            return null;
        }
        auvvo_run_migrations($pdo);
        $hash = self::hashKey($apiKey);
        $stmt = $pdo->prepare(
            'SELECT id, user_id, permissions FROM user_api_keys WHERE api_key_hash = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $perms = json_decode((string) ($row['permissions'] ?? '[]'), true);

        $pdo->prepare('UPDATE user_api_keys SET last_used_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);

        return [
            'user_id'     => (int) $row['user_id'],
            'key_id'      => (int) $row['id'],
            'permissions' => is_array($perms) ? $perms : [],
        ];
    }

    public static function hasPermission(array $auth, string $perm): bool
    {
        return in_array($perm, $auth['permissions'] ?? [], true);
    }
}
