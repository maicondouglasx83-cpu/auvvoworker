<?php
declare(strict_types=1);

/**
 * Cadastro pendente de checkout — usuário só é criado após pagamento confirmado.
 */

function auvvo_checkout_pending_create(
    PDO $pdo,
    string $name,
    string $email,
    string $passwordHash,
    string $planId
): string {
    $token = bin2hex(random_bytes(24));
    $expires = date('Y-m-d H:i:s', strtotime('+48 hours'));
    $pdo->prepare(
        'INSERT INTO checkout_pending (token, name, email, password_hash, plan_id, expires_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$token, $name, $email, $passwordHash, $planId, $expires]);

    return $token;
}

/**
 * Cria usuário + assinatura incomplete a partir de token pendente.
 *
 * @return array{user_id:int,plan_id:string,is_new:true}|null
 */
function auvvo_checkout_pending_finalize(PDO $pdo, string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }

    $st = $pdo->prepare(
        'SELECT * FROM checkout_pending
         WHERE token = ? AND consumed_at IS NULL AND expires_at > NOW()
         LIMIT 1'
    );
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $email = (string) ($row['email'] ?? '');
    $planId = (string) ($row['plan_id'] ?? 'anual');
    if (!in_array($planId, ['mensal', 'trimestral', 'anual'], true)) {
        $planId = 'anual';
    }

    $existing = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $existing->execute([$email]);
    $userId = (int) ($existing->fetchColumn() ?: 0);

    if ($userId <= 0) {
        $pdo->prepare(
            'INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)'
        )->execute([
            (string) ($row['name'] ?? ''),
            $email,
            (string) ($row['password_hash'] ?? ''),
        ]);
        $userId = (int) $pdo->lastInsertId();
    }

    $pdo->prepare(
        'UPDATE checkout_pending SET consumed_at = NOW(), user_id = ? WHERE token = ?'
    )->execute([$userId, $token]);

    $subStmt = $pdo->prepare(
        'SELECT id FROM subscriptions WHERE user_id = ? AND gateway = ? ORDER BY id DESC LIMIT 1'
    );
    $subStmt->execute([$userId, 'abacatepay']);
    if (!$subStmt->fetchColumn()) {
        $pdo->prepare(
            "INSERT INTO subscriptions (user_id, plan_id, gateway, status) VALUES (?, ?, 'abacatepay', 'incomplete')"
        )->execute([$userId, $planId]);
    }

    return ['user_id' => $userId, 'plan_id' => $planId, 'is_new' => true];
}
