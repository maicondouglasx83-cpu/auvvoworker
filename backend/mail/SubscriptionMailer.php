<?php
/**
 * Dispara e-mails de assinatura (boas-vindas, renovação, cancelamento, etc.).
 * Requer db.php já carregado (constantes + app_http_url).
 */
declare(strict_types=1);

require_once __DIR__ . '/TransactionalEmail.php';

final class SubscriptionMailer
{
    private const PLAN_LABELS = [
        'mensal'     => 'Plano Mensal',
        'trimestral' => 'Plano Trimestral',
        'anual'      => 'Plano Anual',
    ];

    private static function planLabel(string $planId): string
    {
        $k = strtolower($planId);
        return self::PLAN_LABELS[$k] ?? ucfirst($planId);
    }

    private static function supportEmail(): string
    {
        return defined('SUPPORT_EMAIL') ? (string) SUPPORT_EMAIL : '';
    }

    private static function dashboardUrl(): string
    {
        return function_exists('app_http_url') ? app_http_url('dashboard.php') : '';
    }

    private static function formatDatePt(?string $mysqlDatetime, bool $withTime = false): string
    {
        if ($mysqlDatetime === null || $mysqlDatetime === '') {
            return '—';
        }
        $t = strtotime($mysqlDatetime);
        if ($t === false) {
            return '—';
        }
        return $withTime ? date('d/m/Y H:i', $t) : date('d/m/Y', $t);
    }

    /**
     * Tenta reservar envio; false = já enviado para esta chave.
     */
    private static function claimDedupe(PDO $pdo, string $dedupeKey): bool
    {
        if (strlen($dedupeKey) > 191) {
            $dedupeKey = md5($dedupeKey);
        }
        try {
            $pdo->prepare('INSERT INTO transactional_email_sent (dedupe_key) VALUES (?)')->execute([$dedupeKey]);
            return true;
        } catch (PDOException $e) {
            $code = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
            if ($code === 1062) {
                return false;
            }
            if ($code === 1146) {
                return true;
            }
            error_log('[SubscriptionMailer] dedupe: ' . $e->getMessage());
            return true;
        }
    }

    private static function paymentMethodLabel(array $webhookData): string
    {
        $p = $webhookData['payment'] ?? [];
        if (!empty($p['method']) && is_string($p['method'])) {
            $m = strtoupper($p['method']);
            if ($m === 'CARD' || $m === 'CREDIT_CARD') {
                return 'Cartão';
            }
            if ($m === 'PIX') {
                return 'PIX';
            }
            return $p['method'];
        }
        return 'Cartão';
    }

    /** Valor legível a partir do payload do webhook (heurística). */
    private static function moneyLabelFromPayload(array $data): string
    {
        $sub = $data['subscription'] ?? [];
        if (!is_array($sub)) {
            return '—';
        }
        foreach (['amountInCents', 'amount_in_cents'] as $k) {
            if (isset($sub[$k]) && is_numeric($sub[$k])) {
                return 'R$ ' . number_format(((float) $sub[$k]) / 100, 2, ',', '.');
            }
        }
        foreach (['amount', 'price'] as $k) {
            if (isset($sub[$k]) && is_numeric($sub[$k])) {
                $n = (float) $sub[$k];
                if ($n >= 100 && $n == floor($n)) {
                    return 'R$ ' . number_format($n / 100, 2, ',', '.');
                }
                return 'R$ ' . number_format($n, 2, ',', '.');
            }
        }
        return '—';
    }

    private static function logSend(string $ctx, string $to, array $result): void
    {
        if (!empty($result['ok'])) {
            return;
        }
        $err = (string) ($result['error'] ?? 'erro desconhecido');
        error_log('[SubscriptionMailer] ' . $ctx . ' → ' . $to . ': ' . $err);
    }

    /**
     * subscription.completed (AbacatePay) — boas-vindas.
     */
    public static function onSubscriptionCompleted(
        PDO $pdo,
        int $userId,
        string $planId,
        string $subscriptionGatewayId,
        array $webhookData
    ): void {
        $st = $pdo->prepare('SELECT name, email FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user || empty($user['email']) || !filter_var((string) $user['email'], FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $dedupe = 'welcome:abacatepay:' . $subscriptionGatewayId;
        if (!self::claimDedupe($pdo, $dedupe)) {
            return;
        }

        $email = (string) $user['email'];

        $support = self::supportEmail();
        if ($support === '') {
            error_log('[SubscriptionMailer] SUPPORT_EMAIL/MAIL_FROM_EMAIL vazio; e-mail de boas-vindas não enviado.');
            return;
        }

        $nowPt = self::formatDatePt(date('Y-m-d H:i:s'), true);
        $data = [
            'user_name'              => (string) ($user['name'] ?? 'Cliente'),
            'user_email'             => $email,
            'plan_label'             => self::planLabel($planId),
            'dashboard_url'          => self::dashboardUrl(),
            'subscription_reference' => $subscriptionGatewayId,
            'subscription_date'      => $nowPt,
            'payment_method_label'   => self::paymentMethodLabel($webhookData),
            'app_name'               => 'Auvvo',
            'support_email'          => $support,
            'preheader'              => 'Sua assinatura Auvvo está ativa.',
        ];

        $res = TransactionalEmail::buildAndSend(EmailDefinitions::TEMPLATE_SUBSCRIPTION_WELCOME, $data, $email);
        self::logSend('welcome', $email, $res);
    }

    /**
     * subscription.renewed
     *
     * @param array{name:string,email:string,plan_id:string} $subRow
     */
    public static function onSubscriptionRenewed(
        PDO $pdo,
        array $subRow,
        string $periodEndMysql,
        array $webhookData,
        string $eventId,
        string $subscriptionGatewayId
    ): void {
        $dedupe = $eventId !== ''
            ? 'renewed:abacatepay:' . $eventId
            : 'renewed:abacatepay:' . md5($subscriptionGatewayId . '|' . $periodEndMysql);
        if (!self::claimDedupe($pdo, $dedupe)) {
            return;
        }

        $email = (string) ($subRow['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $support = self::supportEmail();
        if ($support === '') {
            error_log('[SubscriptionMailer] SUPPORT_EMAIL vazio; e-mail de renovação não enviado.');
            return;
        }

        $planId = (string) ($subRow['plan_id'] ?? 'anual');

        $data = [
            'user_name'       => (string) ($subRow['name'] ?? 'Cliente'),
            'plan_label'      => self::planLabel($planId),
            'next_billing_at' => self::formatDatePt($periodEndMysql, false),
            'amount_label'    => self::moneyLabelFromPayload($webhookData),
            'dashboard_url'   => self::dashboardUrl(),
            'app_name'        => 'Auvvo',
            'support_email'   => $support,
            'preheader'       => 'Renovação da sua assinatura Auvvo confirmada.',
        ];

        $res = TransactionalEmail::buildAndSend(EmailDefinitions::TEMPLATE_SUBSCRIPTION_RENEWED, $data, $email);
        self::logSend('renewed', $email, $res);
    }

    /**
     * subscription.cancelled
     *
     * @param array{name:string,email:string,plan_id:string,current_period_end:?string} $subUserRow
     */
    public static function onSubscriptionCancelled(
        PDO $pdo,
        array $subUserRow,
        string $subscriptionGatewayId,
        string $eventId
    ): void {
        $dedupe = $eventId !== ''
            ? 'cancelled:abacatepay:' . $eventId
            : 'cancelled:abacatepay:sub:' . $subscriptionGatewayId;
        if (!self::claimDedupe($pdo, $dedupe)) {
            return;
        }

        $email = (string) ($subUserRow['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $support = self::supportEmail();
        if ($support === '') {
            error_log('[SubscriptionMailer] SUPPORT_EMAIL vazio; e-mail de cancelamento não enviado.');
            return;
        }

        $planId = (string) ($subUserRow['plan_id'] ?? 'anual');
        $until  = (string) ($subUserRow['current_period_end'] ?? '');
        $data   = [
            'user_name'       => (string) ($subUserRow['name'] ?? 'Cliente'),
            'plan_label'      => self::planLabel($planId),
            'effective_until' => $until !== '' ? self::formatDatePt($until, false) : '—',
            'dashboard_url'   => self::dashboardUrl(),
            'app_name'        => 'Auvvo',
            'support_email'   => $support,
            'preheader'       => 'Cancelamento de assinatura registrado.',
        ];

        $res = TransactionalEmail::buildAndSend(EmailDefinitions::TEMPLATE_SUBSCRIPTION_CANCELLED, $data, $email);
        self::logSend('cancelled', $email, $res);
    }

    /**
     * Comprovante pontual (ex.: após pagamento confirmado por outro fluxo).
     *
     * @param array{name:string,email:string} $userRow
     */
    public static function sendPaymentReceipt(
        PDO $pdo,
        array $userRow,
        string $planId,
        string $amountLabel,
        string $paidAtPt,
        string $subscriptionReference,
        string $paymentMethodLabel,
        ?string $dedupeKey = null
    ): void {
        if ($dedupeKey !== null && $dedupeKey !== '' && !self::claimDedupe($pdo, $dedupeKey)) {
            return;
        }

        $email = (string) ($userRow['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $support = self::supportEmail();
        if ($support === '') {
            return;
        }

        $data = [
            'user_name'              => (string) ($userRow['name'] ?? 'Cliente'),
            'user_email'             => $email,
            'plan_label'             => self::planLabel($planId),
            'amount_label'           => $amountLabel,
            'paid_at'                => $paidAtPt,
            'subscription_reference' => $subscriptionReference,
            'payment_method_label'   => $paymentMethodLabel,
            'dashboard_url'          => self::dashboardUrl(),
            'app_name'               => 'Auvvo',
            'support_email'          => $support,
            'preheader'              => 'Pagamento confirmado.',
        ];

        $res = TransactionalEmail::buildAndSend(EmailDefinitions::TEMPLATE_SUBSCRIPTION_PAYMENT_RECEIPT, $data, $email);
        self::logSend('receipt', $email, $res);
    }

    /**
     * Falha de cobrança — use quando o gateway notificar pagamento recusado.
     *
     * @param array{name:string,email:string} $userRow
     */
    public static function sendPaymentFailed(PDO $pdo, array $userRow, string $planId, ?string $dedupeKey = null): void
    {
        if ($dedupeKey !== null && $dedupeKey !== '' && !self::claimDedupe($pdo, $dedupeKey)) {
            return;
        }

        $email = (string) ($userRow['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $support = self::supportEmail();
        if ($support === '') {
            return;
        }

        $checkoutUrl = function_exists('app_http_url')
            ? app_http_url('checkout.php?plan=' . rawurlencode($planId))
            : '';

        $data = [
            'user_name'           => (string) ($userRow['name'] ?? 'Cliente'),
            'plan_label'          => self::planLabel($planId),
            'update_payment_url'  => $checkoutUrl !== '' ? $checkoutUrl : self::dashboardUrl(),
            'app_name'            => 'Auvvo',
            'support_email'       => $support,
            'preheader'           => 'Atualize seu pagamento para manter o acesso.',
        ];

        $res = TransactionalEmail::buildAndSend(EmailDefinitions::TEMPLATE_SUBSCRIPTION_PAYMENT_FAILED, $data, $email);
        self::logSend('payment_failed', $email, $res);
    }

    /**
     * Lembrete antes da renovação — típico de cron (ex.: 3 dias antes).
     *
     * @param array{name:string,email:string} $userRow
     */
    public static function sendExpiringReminder(
        PDO $pdo,
        array $userRow,
        string $planId,
        string $nextBillingAtPt,
        ?string $dedupeKey = null
    ): void {
        if ($dedupeKey !== null && $dedupeKey !== '' && !self::claimDedupe($pdo, $dedupeKey)) {
            return;
        }

        $email = (string) ($userRow['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $support = self::supportEmail();
        if ($support === '') {
            return;
        }

        $data = [
            'user_name'       => (string) ($userRow['name'] ?? 'Cliente'),
            'plan_label'      => self::planLabel($planId),
            'next_billing_at' => $nextBillingAtPt,
            'dashboard_url'   => self::dashboardUrl(),
            'app_name'        => 'Auvvo',
            'support_email'   => $support,
            'preheader'       => 'Sua assinatura renova em breve.',
        ];

        $res = TransactionalEmail::buildAndSend(EmailDefinitions::TEMPLATE_SUBSCRIPTION_EXPIRING_REMINDER, $data, $email);
        self::logSend('expiring_reminder', $email, $res);
    }
}
