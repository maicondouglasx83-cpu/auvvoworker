<?php

// backend/abacatepay_success.php

// Retorno após pagamento no checkout AbacatePay (completionUrl).

require_once __DIR__ . '/../includes/session_bootstrap.inc.php';

require_once __DIR__ . '/db.php';

require_once __DIR__ . '/PaymentGateway.php';

require_once __DIR__ . '/migrations.php';

require_once __DIR__ . '/checkout_pending.inc.php';



auvvo_run_migrations($pdo);



$checkoutId = $_SESSION['abacatepay_checkout_id'] ?? '';

$pending    = $_SESSION['pending_payment'] ?? null;



$verified = false;

if ($checkoutId !== '' && defined('ABACATEPAY_API_KEY') && ABACATEPAY_API_KEY !== '') {

    try {

        $res  = PaymentGateway::abacatepayGetCheckout($checkoutId);

        if (is_array($res) && !empty($res['success'])) {

            $status = $res['data']['status'] ?? '';

            $verified = ($status === 'PAID');

        }

    } catch (Exception $e) {

        error_log('[abacatepay_success] Erro ao consultar checkout: ' . $e->getMessage());

    }

}



$pendingUserId = is_array($pending) ? (int) ($pending['user_id'] ?? 0) : 0;

$pendingToken  = is_array($pending) ? trim((string) ($pending['pending_token'] ?? '')) : '';

$isNewUser     = is_array($pending) && !empty($pending['is_new_user']);



if ($verified && $isNewUser && $pendingToken !== '') {

    $finalized = auvvo_checkout_pending_finalize($pdo, $pendingToken);

    if ($finalized) {

        $pendingUserId = (int) ($finalized['user_id'] ?? 0);

    }

}



if ($verified && $pendingUserId > 0 && $isNewUser) {

    $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = ? LIMIT 1');

    $stmt->execute([$pendingUserId]);

    $user = $stmt->fetch();



    if ($user) {

        session_regenerate_id(true);

        $_SESSION['user_id']    = $user['id'];

        $_SESSION['user_name']  = $user['name'];

        $_SESSION['user_email'] = $user['email'];

        unset($_SESSION['pending_payment'], $_SESSION['abacatepay_checkout_id']);



        header('Location: ../dashboard.php?welcome=1');

        exit;

    }

}



if ($verified) {

    unset($_SESSION['pending_payment'], $_SESSION['abacatepay_checkout_id']);

    header('Location: ../login.php?paid=1');

    exit;

}

?>

<!DOCTYPE html>

<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Pagamento | Auvvo</title>

    <link rel="stylesheet" href="../app.css">

    <script src="https://unpkg.com/@phosphor-icons/web"></script>

    <meta http-equiv="refresh" content="5;url=../login.php">

</head>

<body style="background: var(--bg-app); display: flex; align-items: center; justify-content: center; min-height: 100vh;">

    <div class="app-card" style="max-width: 480px; text-align: center; padding: 48px 40px;">

        <i class="ph-fill ph-check-circle" style="font-size: 4rem; color: #10B981; margin-bottom: 20px;"></i>

        <h1 style="font-size: 1.8rem; margin-bottom: 12px;">Obrigado!</h1>

        <p class="text-muted" style="margin-bottom: 24px; line-height: 1.7;">

            Se o pagamento foi confirmado, sua assinatura será ativada em instantes.<br>

            Caso o webhook ainda esteja processando, faça login em alguns segundos.

        </p>

        <a href="../login.php" class="btn btn-primary">

            Acessar Minha Conta <i class="ph-bold ph-arrow-right"></i>

        </a>

        <p class="text-muted" style="margin-top: 16px; font-size: 0.8rem;">Redirecionando para o login em 5 segundos...</p>

    </div>

</body>

</html>

