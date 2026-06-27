<?php
declare(strict_types=1);

/**
 * Verificação de assinatura ativa para páginas protegidas.
 */
function auvvo_user_subscription_active(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    try {
        $st = $pdo->prepare(
            'SELECT status, current_period_end FROM subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($row['status'] ?? '') !== 'active') {
            return false;
        }
        $end = $row['current_period_end'] ?? null;
        if ($end !== null && $end !== '' && strtotime((string) $end) < time()) {
            return false;
        }

        return true;
    } catch (PDOException $e) {
        error_log('[Auvvo] subscription check: ' . $e->getMessage());

        return false;
    }
}

function auvvo_auth_billing_exempt_script(): bool
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php');

    return in_array($script, ['configuracoes', 'checkout'], true);
}

function auvvo_auth_require_subscription(): void
{
    if (defined('AUVVO_SKIP_SUBSCRIPTION_CHECK') && AUVVO_SKIP_SUBSCRIPTION_CHECK) {
        return;
    }
    if (defined('IS_DEV') && IS_DEV) {
        return;
    }
    if (auvvo_auth_billing_exempt_script()) {
        return;
    }
    if (empty($_SESSION['user_id'])) {
        return;
    }

    global $pdo;
    require_once __DIR__ . '/../backend/db.php';
    if (!$pdo instanceof PDO) {
        return;
    }

    if (!auvvo_user_subscription_active($pdo, (int) $_SESSION['user_id'])) {
        header('Location: ' . app_http_url('checkout.php?renew=1'));
        exit;
    }
}
