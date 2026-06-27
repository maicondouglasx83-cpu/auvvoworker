<?php
/**
 * Catálogo de e-mails transacionais (assinatura / Auvvo).
 *
 * Ao criar o HTML final, mantenha os mesmos {{chaves}} ou atualize
 * `required` + este arquivo junto com os .html em templates/.
 */
declare(strict_types=1);

final class EmailDefinitions
{
    public const TEMPLATE_SUBSCRIPTION_WELCOME           = 'subscription_welcome';
    public const TEMPLATE_SUBSCRIPTION_PAYMENT_RECEIPT   = 'subscription_payment_receipt';
    public const TEMPLATE_SUBSCRIPTION_PAYMENT_FAILED    = 'subscription_payment_failed';
    public const TEMPLATE_SUBSCRIPTION_RENEWED           = 'subscription_renewed';
    public const TEMPLATE_SUBSCRIPTION_CANCELLED         = 'subscription_cancelled';
    public const TEMPLATE_SUBSCRIPTION_EXPIRING_REMINDER = 'subscription_expiring_reminder';

    /**
     * @return array<string, array{subject:string,file:string,required:list<string>,description:string}>
     */
    public static function all(): array
    {
        return [
            self::TEMPLATE_SUBSCRIPTION_WELCOME => [
                'subject'     => 'Sua assinatura Auvvo está ativa',
                'file'        => 'subscription_welcome.html',
                'description' => 'Envio após confirmação do primeiro pagamento / assinatura ativa.',
                'required'    => [
                    'user_name', 'user_email', 'plan_label', 'dashboard_url',
                    'subscription_reference', 'subscription_date', 'payment_method_label',
                    'app_name', 'support_email',
                ],
            ],
            self::TEMPLATE_SUBSCRIPTION_PAYMENT_RECEIPT => [
                'subject'     => 'Comprovante — pagamento Auvvo confirmado',
                'file'        => 'subscription_payment_receipt.html',
                'description' => 'Confirmação de pagamento (fatura paga / primeira cobrança).',
                'required'    => [
                    'user_name', 'user_email', 'plan_label', 'amount_label', 'paid_at',
                    'subscription_reference', 'payment_method_label', 'dashboard_url',
                    'app_name', 'support_email',
                ],
            ],
            self::TEMPLATE_SUBSCRIPTION_PAYMENT_FAILED => [
                'subject'     => 'Ação necessária — não conseguimos processar o pagamento',
                'file'        => 'subscription_payment_failed.html',
                'description' => 'Cartão/PIX falhou ou renovação recusada; pedir atualização.',
                'required'    => ['user_name', 'plan_label', 'update_payment_url', 'app_name', 'support_email'],
            ],
            self::TEMPLATE_SUBSCRIPTION_RENEWED => [
                'subject'     => 'Renovação confirmada — Auvvo',
                'file'        => 'subscription_renewed.html',
                'description' => 'Cobrança recorrente bem-sucedida (nova fatura paga).',
                'required'    => [
                    'user_name', 'plan_label', 'next_billing_at', 'amount_label',
                    'dashboard_url', 'app_name', 'support_email',
                ],
            ],
            self::TEMPLATE_SUBSCRIPTION_CANCELLED => [
                'subject'     => 'Sua assinatura Auvvo foi cancelada',
                'file'        => 'subscription_cancelled.html',
                'description' => 'Cancelamento registrado (painel ou gateway).',
                'required'    => [
                    'user_name', 'plan_label', 'effective_until',
                    'dashboard_url', 'app_name', 'support_email',
                ],
            ],
            self::TEMPLATE_SUBSCRIPTION_EXPIRING_REMINDER => [
                'subject'     => 'Lembrete — sua assinatura Auvvo renova em breve',
                'file'        => 'subscription_expiring_reminder.html',
                'description' => 'Lembrete antes da próxima cobrança (cron / job).',
                'required'    => ['user_name', 'plan_label', 'next_billing_at', 'dashboard_url', 'app_name', 'support_email'],
            ],
        ];
    }

    /** @return array{subject:string,file:string,required:list<string>,description:string}|null */
    public static function get(string $templateId): ?array
    {
        return self::all()[$templateId] ?? null;
    }
}
