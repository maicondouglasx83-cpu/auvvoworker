<?php
// esqueci-senha.php
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;
$isProduction = (defined('APP_ENV') && APP_ENV === 'production');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'domain' => '',
    'secure' => $isProduction || $isHttps, 'httponly' => true, 'samesite' => 'Strict',
]);
session_start();
require_once 'backend/db.php';
require_once 'includes/i18n.php';
require_once 'backend/mail/TransactionalEmail.php';

if (isset($_SESSION['user_id'])) { header('Location: dashboard'); exit; }

$error = '';
$success = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $csrf_ok = hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '');
    if (!$csrf_ok) {
        $error = t('login_err_csrf');
    } elseif ($email) {
        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare(
                "INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))"
            )->execute([$user['id'], $token]);

            $resetUrl = rtrim($_ENV['APP_BASE_URL'] ?? app_http_url(''), '/') . '/resetar-senha?token=' . $token;
            $subject = 'Recuperacao de senha — Auvvo';
            $html = "<h2>Ola {$user['name']}</h2>"
                  . "<p>Voce solicitou a recuperacao de senha.</p>"
                  . "<p>Clique no link abaixo para criar uma nova senha (valido por 1 hora):</p>"
                  . "<p><a href=\"{$resetUrl}\" style=\"display:inline-block;padding:12px 24px;background:#9EDCD9;color:#1A1A1E;border-radius:8px;text-decoration:none;font-weight:600\">Redefinir Senha</a></p>"
                  . "<p>Se voce nao solicitou, ignore este email.</p>";

            try {
                TransactionalEmail::send($email, $subject, $html);
            } catch (Throwable $e) {
                error_log('[Auvvo] password reset email: ' . $e->getMessage());
            }
        }
        // Always show success (don't reveal if email exists)
        $success = 'Se o email existir, um link de redefinicao foi enviado.';
    }
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?><!DOCTYPE html>
<html lang="<?= lang_html() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Senha — Auvvo</title>
    <link rel="stylesheet" href="app.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="icon" type="image/png" href="icone.png">
    <style>
    .rec-lang-bar{position:absolute;top:20px;right:20px;display:flex;align-items:center;gap:2px;background:rgba(255,255,255,0.55);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);padding:5px 8px;border-radius:99px;border:1px solid rgba(255,255,255,0.85);box-shadow:0 4px 20px rgba(0,0,0,0.08)}
    .rec-lang-bar .lang-sep{width:1px;height:12px;background:rgba(0,0,0,0.12);margin:0 2px;border-radius:1px}
    </style>
</head>
<body>
<div class="auth-layout">
    <video class="auth-video-bg" src="202604302219.mp4" autoplay loop muted playsinline></video>
    <div class="auth-card">
        <div class="auth-header">
            <img src="favicon.png" alt="Auvvo Logo">
            <h2>Recuperar Senha</h2>
            <p class="text-muted">Digite seu email para receber o link de redefinicao.</p>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <?php if ($error): ?>
            <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#EF4444;padding:12px;border-radius:var(--radius-sm);font-size:.875rem;margin-bottom:24px;text-align:center">
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            <?php if ($success): ?>
            <div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);color:#22C55E;padding:12px;border-radius:var(--radius-sm);font-size:.875rem;margin-bottom:24px;text-align:center">
                <?= htmlspecialchars($success) ?>
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" placeholder="seu@email.com"
                       value="<?= htmlspecialchars($email) ?>" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block" style="margin-top:24px">Enviar Link</button>
            <div style="text-align:center;margin-top:24px;font-size:.875rem">
                <a href="login" style="color:var(--text-primary);font-weight:600;text-decoration:none">Voltar ao login</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
