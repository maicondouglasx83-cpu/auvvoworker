<?php
// resetar-senha.php
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;
$isProduction = (defined('APP_ENV') && APP_ENV === 'production');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'domain' => '',
    'secure' => $isProduction || $isHttps, 'httponly' => true, 'samesite' => 'Strict',
]);
session_start();
require_once 'backend/db.php';
require_once 'includes/i18n.php';

if (isset($_SESSION['user_id'])) { header('Location: dashboard'); exit; }

$token = trim($_GET['token'] ?? '');
$error = '';
$success = '';

$stmt = $pdo->prepare(
    "SELECT id, user_id FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW() LIMIT 1"
);
$stmt->execute([$token]);
$reset = $stmt->fetch();

if (!$reset) {
    $error = 'Link invalido ou expirado. Solicite novamente.';
    $valid = false;
} else {
    $valid = true;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf_ok = hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '');
        $pass = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        if (!$csrf_ok) {
            $error = t('login_err_csrf');
        } elseif (strlen($pass) < 6) {
            $error = 'A senha deve ter no minimo 6 caracteres.';
        } elseif ($pass !== $confirm) {
            $error = 'As senhas nao conferem.';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $reset['user_id']]);
            $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")->execute([$reset['id']]);
            $success = 'Senha redefinida com sucesso!';
        }
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?><!DOCTYPE html>
<html lang="<?= lang_html() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinir Senha — Auvvo</title>
    <link rel="stylesheet" href="app.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="icon" type="image/png" href="icone.png">
</head>
<body>
<div class="auth-layout">
    <video class="auth-video-bg" src="202604302219.mp4" autoplay loop muted playsinline></video>
    <div class="auth-card">
        <div class="auth-header">
            <img src="favicon.png" alt="Auvvo Logo">
            <h2>Redefinir Senha</h2>
        </div>
        <?php if ($error): ?>
        <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#EF4444;padding:12px;border-radius:var(--radius-sm);font-size:.875rem;margin-bottom:24px;text-align:center">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);color:#22C55E;padding:12px;border-radius:var(--radius-sm);font-size:.875rem;margin-bottom:24px;text-align:center">
            <?= htmlspecialchars($success) ?>
        </div>
        <div style="text-align:center;margin-top:24px">
            <a href="login" class="btn btn-primary">Ir para Login</a>
        </div>
        <?php elseif ($valid): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="form-group">
                <label class="form-label">Nova Senha</label>
                <input type="password" name="password" class="form-control" placeholder="Minimo 6 caracteres" required minlength="6">
            </div>
            <div class="form-group">
                <label class="form-label">Confirmar Senha</label>
                <input type="password" name="password_confirm" class="form-control" placeholder="Repita a senha" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block" style="margin-top:24px">Redefinir Senha</button>
        </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
