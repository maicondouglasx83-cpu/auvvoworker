<?php
/**
 * Teste de envio dos templates transacionais (dados fictícios).
 *
 * CLI (recomendado no XAMPP):
 *   c:\xampp\php\php.exe backend\mail\test_send.php seu@email.com welcome
 *
 * Navegador (defina MAIL_TEST_SECRET no .env):
 *   .../backend/mail/test_send.php?key=SEU_SECRET&to=seu@email.com&template=welcome
 *
 * Templates: welcome | receipt | renewed | cancelled | failed | reminder
 */
declare(strict_types=1);

$cli = PHP_SAPI === 'cli';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/EmailDefinitions.php';
require_once __DIR__ . '/TransactionalEmail.php';

if (!$cli) {
    header('Content-Type: text/plain; charset=UTF-8');
    $secret = trim((string) ($_ENV['MAIL_TEST_SECRET'] ?? ''));
    if ($secret === '') {
        http_response_code(503);
        exit('Defina MAIL_TEST_SECRET no .env para usar este script pelo navegador.');
    }
    $key = (string) ($_GET['key'] ?? '');
    if (!hash_equals($secret, $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$to = $cli ? ($argv[1] ?? '') : (string) ($_GET['to'] ?? '');
$template = strtolower($cli ? ($argv[2] ?? 'welcome') : (string) ($_GET['template'] ?? 'welcome'));

if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $bin = 'php ' . basename(__FILE__);
    $msg = $cli
        ? "Uso: $bin email@exemplo.com [welcome|receipt|renewed|cancelled|failed|reminder]\n"
        : "Informe to= com um e-mail válido.\n";
    http_response_code(400);
    exit($msg);
}

$support = defined('SUPPORT_EMAIL') && SUPPORT_EMAIL !== ''
    ? SUPPORT_EMAIL
    : (defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : '');
if ($support === '') {
    http_response_code(503);
    exit($cli
        ? "Defina MAIL_FROM_EMAIL ou SUPPORT_EMAIL no .env.\n"
        : 'Defina MAIL_FROM_EMAIL no .env.');
}

$dash     = function_exists('app_http_url') ? app_http_url('dashboard.php') : '#';
$checkout = function_exists('app_http_url') ? app_http_url('checkout.php?plan=anual') : '#';

$common = [
    'app_name'      => 'Auvvo',
    'support_email' => $support,
    'preheader'     => '[TESTE] Mensagem de demonstração — pode ignorar.',
];

$map = [
    'welcome'   => EmailDefinitions::TEMPLATE_SUBSCRIPTION_WELCOME,
    'receipt'   => EmailDefinitions::TEMPLATE_SUBSCRIPTION_PAYMENT_RECEIPT,
    'renewed'   => EmailDefinitions::TEMPLATE_SUBSCRIPTION_RENEWED,
    'cancelled' => EmailDefinitions::TEMPLATE_SUBSCRIPTION_CANCELLED,
    'failed'    => EmailDefinitions::TEMPLATE_SUBSCRIPTION_PAYMENT_FAILED,
    'reminder'  => EmailDefinitions::TEMPLATE_SUBSCRIPTION_EXPIRING_REMINDER,
];

if (!isset($map[$template])) {
    http_response_code(400);
    exit($cli
        ? "Template inválido: {$template}\n"
        : "Parâmetro template inválido. Use: " . implode('|', array_keys($map)) . "\n");
}

$tplId = $map[$template];

$data = array_merge($common, [
    'user_name'     => 'Cliente Teste',
    'user_email'    => $to,
    'plan_label'    => 'Plano Anual',
    'dashboard_url' => $dash,
]);

if ($tplId === EmailDefinitions::TEMPLATE_SUBSCRIPTION_WELCOME) {
    $data = array_merge($data, [
        'subscription_reference' => 'TEST-SUB-001',
        'subscription_date'      => date('d/m/Y H:i'),
        'payment_method_label'   => 'Cartão',
    ]);
} elseif ($tplId === EmailDefinitions::TEMPLATE_SUBSCRIPTION_PAYMENT_RECEIPT) {
    $data = array_merge($data, [
        'amount_label'           => 'R$ 259,90',
        'paid_at'                => date('d/m/Y H:i'),
        'subscription_reference' => 'TEST-SUB-001',
        'payment_method_label'   => 'Cartão',
    ]);
} elseif ($tplId === EmailDefinitions::TEMPLATE_SUBSCRIPTION_RENEWED) {
    $data = array_merge($data, [
        'next_billing_at' => date('d/m/Y', strtotime('+1 year')),
        'amount_label'    => 'R$ 259,90',
    ]);
} elseif ($tplId === EmailDefinitions::TEMPLATE_SUBSCRIPTION_CANCELLED) {
    $data = array_merge($data, [
        'effective_until' => date('d/m/Y', strtotime('+30 days')),
    ]);
} elseif ($tplId === EmailDefinitions::TEMPLATE_SUBSCRIPTION_PAYMENT_FAILED) {
    $data = array_merge($data, [
        'update_payment_url' => $checkout,
    ]);
} elseif ($tplId === EmailDefinitions::TEMPLATE_SUBSCRIPTION_EXPIRING_REMINDER) {
    $data = array_merge($data, [
        'next_billing_at' => date('d/m/Y', strtotime('+3 days')),
    ]);
}

$result = TransactionalEmail::buildAndSend($tplId, $data, $to);

if (!empty($result['ok'])) {
    echo $cli
        ? "Enviado: template \"{$template}\" para {$to}\n"
        : "OK — enviado \"{$template}\" para {$to}.";
    exit(0);
}

$err = (string) ($result['error'] ?? 'erro desconhecido');
http_response_code(502);
echo $cli ? "Falha no envio: {$err}\n" : "Falha: {$err}";
exit(1);
