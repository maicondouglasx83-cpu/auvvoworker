<?php
// backend/process_payment.php — checkout AbacatePay apenas
require_once __DIR__ . '/../includes/session_bootstrap.inc.php';
require_once 'db.php';
require_once 'PaymentGateway.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/checkout_pending.inc.php';

auvvo_run_migrations($pdo);

// ── Verificação CSRF ────────────────────────────────────────────────────────
$csrf_ok = !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '');
if (!$csrf_ok) {
    http_response_code(403);
    die('Sessão inválida. Volte e tente novamente.');
}
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$_SESSION['csrf_token_created'] = time();

// ── Configuração dos planos (AbacatePay: IDs de produto no painel) ──────────
$plans = [
    'mensal' => [
        'id'                    => 'mensal',
        'name'                  => 'Plano Mensal',
        'abacatepay_product_id' => ABACATEPAY_PRODUCT_MENSAL,
    ],
    'trimestral' => [
        'id'                    => 'trimestral',
        'name'                  => 'Plano Trimestral',
        'abacatepay_product_id' => ABACATEPAY_PRODUCT_TRIMESTRAL,
    ],
    'anual' => [
        'id'                    => 'anual',
        'name'                  => 'Plano Anual',
        'abacatepay_product_id' => ABACATEPAY_PRODUCT_ANUAL,
    ],
];

$plan_id = array_key_exists($_POST['plan'] ?? '', $plans) ? $_POST['plan'] : 'anual';
$plan    = $plans[$plan_id];

// ── Validação dos dados do formulário ────────────────────────────────────────
$name     = trim($_POST['name']     ?? '');
$email    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$password = $_POST['password'] ?? '';

if (!$name || !$email || !$password) {
    http_response_code(400);
    die('Preencha todos os campos obrigatórios.');
}
if (strlen($password) < 8) {
    http_response_code(400);
    die('Sua senha deve ter no mínimo 8 caracteres.');
}

// ── Criar ou recuperar usuário no banco ──────────────────────────────────────
try {
    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

    $isNewUser = false;
    $pendingToken = null;
    $user_id = 0;

    if ($existingUser) {
        if (!password_verify($password, (string) ($existingUser['password_hash'] ?? ''))) {
            http_response_code(401);
            die('E-mail já cadastrado. Use sua senha atual ou faça login antes de assinar.');
        }
        $user_id = (int) $existingUser['id'];

        $gateway = 'abacatepay';
        $subStmt = $pdo->prepare(
            "SELECT id FROM subscriptions WHERE user_id = ? AND gateway = ? ORDER BY id DESC LIMIT 1"
        );
        $subStmt->execute([$user_id, $gateway]);
        if (!$subStmt->fetch()) {
            $pdo->prepare(
                "INSERT INTO subscriptions (user_id, plan_id, gateway, status) VALUES (?, ?, ?, 'incomplete')"
            )->execute([$user_id, $plan_id, $gateway]);
        }
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pendingToken = auvvo_checkout_pending_create($pdo, $name, (string) $email, $hash, $plan_id);
        $isNewUser = true;
    }
} catch (PDOException $e) {
    error_log('[Auvvo] process_payment DB error: ' . $e->getMessage());
    die('Ocorreu um erro interno. Por favor, tente novamente.');
}

// ── Salvar dados do usuário na sessão (para o webhook / success identificarem)
$_SESSION['pending_payment'] = [
    'user_id'       => $user_id > 0 ? $user_id : null,
    'pending_token' => $pendingToken,
    'plan_id'       => $plan_id,
    'email'         => $email,
    'is_new_user'   => $isNewUser,
];

// ── Checkout AbacatePay ──────────────────────────────────────────────────────
try {
    $gw = new PaymentGateway('abacatepay');

    $result = $gw->processPayment(
        [
            'user_id'       => $user_id,
            'email'         => $email,
            'name'          => $name,
            'pending_token' => $pendingToken,
        ],
        $plan,
        'card'
    );

    if ($result['status'] === 'redirect' && !empty($result['redirect_url'])) {
        if (!empty($result['checkout_id'])) {
            $_SESSION['abacatepay_checkout_id'] = $result['checkout_id'];
        }
        header('Location: ' . $result['redirect_url']);
        exit;
    }

    throw new Exception('Resposta inesperada do gateway.');

} catch (Exception $e) {
    $raw = $e->getMessage();
    error_log('[Auvvo] checkout error (abacatepay): ' . $raw);
    $friendly = $raw;
    if (stripos($raw, 'curl') !== false || stripos($raw, 'Could not resolve') !== false) {
        $friendly = 'Não foi possível conectar ao gateway de pagamento. Tente novamente em instantes.';
    }
    http_response_code(502);
    die('Erro no checkout: ' . htmlspecialchars($friendly));
}
