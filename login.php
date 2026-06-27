<?php
// login.php
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;
$isProduction = (defined('APP_ENV') && APP_ENV === 'production');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $isProduction || $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();
require_once 'backend/db.php';
require_once 'includes/i18n.php';

// Redireciona se já autenticado
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard');
    exit;
}

// Gera token CSRF para o formulário de login (sem usar auth.php pois não está autenticado)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

// ── Rate Limiting por IP real ──────────────────────────────────────────────
$blocked = false;
try {
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $check = $pdo->prepare(
        "SELECT attempts, first_at FROM login_attempts WHERE ip = ? AND first_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );
    $check->execute([$client_ip]);
    $rate = $check->fetch();
    $attempts = (int) ($rate['attempts'] ?? 0);
    $first_try = (int) ($rate['first_at'] ?? time());
    $window = 15 * 60;
    $max_tries = 10;
    $blocked = $attempts >= $max_tries && (time() - $first_try) <= $window;

    if (random_int(1, 100) === 1) {
        $pdo->exec("DELETE FROM login_attempts WHERE first_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    }
} catch (PDOException $e) {
    // Cria a tabela se nao existir
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS login_attempts (
                ip VARCHAR(45) NOT NULL PRIMARY KEY,
                attempts INT UNSIGNED NOT NULL DEFAULT 1,
                first_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (PDOException $e2) {
        error_log('[Auvvo] login_attempts table: ' . $e2->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrf_ok = hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '');
    if (!$csrf_ok) {
        $error = t('login_err_csrf');
    } elseif ($blocked) {
        $remaining = ceil(($window - (time() - $first_try)) / 60);
        $error = t('login_err_blocked', ['remaining' => $remaining]);
    } else {
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']      ?? '';

        if ($email && $password) {
            $stmt = $pdo->prepare("SELECT id, password_hash, name FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                try { $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$client_ip]); } catch (PDOException $e) {}
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: dashboard');
                exit;
            } else {
                try {
                    $up = $pdo->prepare(
                        "INSERT INTO login_attempts (ip, attempts, first_at) VALUES (?, 1, NOW())
                         ON DUPLICATE KEY UPDATE attempts = attempts + 1"
                    );
                    $up->execute([$client_ip]);
                } catch (PDOException $e) {}
                $error = t('login_err_invalid');
            }
        } else {
            $error = t('login_err_empty');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= lang_html() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('login_title') ?></title>
    <link rel="stylesheet" href="app.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    
    <link rel="icon" type="image/png" href="icone.png">
    <style>
    .login-lang-bar {
        position: absolute;
        top: 20px;
        right: 20px;
        display: flex;
        align-items: center;
        gap: 2px;
        background: rgba(255,255,255,0.55);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        padding: 5px 8px;
        border-radius: 99px;
        border: 1px solid rgba(255,255,255,0.85);
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    }
    .login-lang-bar .lang-sep {
        width: 1px;
        height: 12px;
        background: rgba(0,0,0,0.12);
        margin: 0 2px;
        border-radius: 1px;
    }
    </style>
</head>
<body>

    <div class="auth-layout">
        <video class="auth-video-bg" src="202604302219.mp4" autoplay loop muted playsinline></video>

        <!-- Language switcher for login page -->
        <div class="login-lang-bar">
            <i class="ph-bold ph-globe" style="font-size:.8rem;color:#8E8E9A;margin-right:2px"></i>
            <?php
            $langs = ['pt_BR' => 'PT-BR', 'es' => 'ES', 'en' => 'EN'];
            $cur   = current_lang();
            $keys  = array_keys($langs);
            foreach ($langs as $code => $label):
                $active = ($code === $cur);
                $last   = ($code === end($keys));
            ?>
            <a href="<?= htmlspecialchars(lang_url($code)) ?>"
               style="font-size:.7rem;font-weight:<?= $active ? '800' : '500' ?>;
                      color:<?= $active ? '#1A1A1E' : '#8E8E9A' ?>;
                      text-decoration:none;padding:3px 6px;border-radius:99px;
                      letter-spacing:.04em;transition:all .15s;
                      <?= $active ? 'background:rgba(255,255,255,0.9);box-shadow:0 1px 4px rgba(0,0,0,0.08);' : '' ?>"
               onmouseover="if(!<?= $active ? 'true' : 'false' ?>)this.style.color='#1A1A1E'"
               onmouseout="if(!<?= $active ? 'true' : 'false' ?>)this.style.color='#8E8E9A'"
            ><?= $label ?></a>
            <?php if (!$last): ?><span class="lang-sep"></span><?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="auth-card">
            <div class="auth-header">
                <img src="favicon.png" alt="Auvvo Logo">
                <h2><?= t('login_heading') ?></h2>
                <p class="text-muted"><?= t('login_subtitle') ?></p>
            </div>

            <form action="login" method="POST" id="login-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <?php if ($error): ?>
                <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #EF4444; padding: 12px; border-radius: var(--radius-sm); font-size: 0.875rem; margin-bottom: 24px; text-align: center;">
                    <i class="ph-bold ph-warning"></i> <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <?php if ($blocked): ?>
                <div style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); color: #F59E0B; padding: 12px; border-radius: var(--radius-sm); font-size: 0.875rem; margin-bottom: 24px; text-align: center;">
                    <i class="ph-bold ph-lock"></i> <?= t('login_blocked_banner') ?>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label"><?= t('login_email_label') ?></label>
                    <input type="email" name="email" class="form-control" placeholder="<?= t('login_email_ph') ?>"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           <?= $blocked ? 'disabled' : '' ?> required>
                </div>

                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <label class="form-label" style="margin-bottom: 0;"><?= t('login_pass_label') ?></label>
                        <a href="esqueci-senha" style="font-size: 0.75rem; color: var(--text-muted); text-decoration: none;"><?= t('login_forgot') ?></a>
                    </div>
                    <input type="password" name="password" class="form-control" placeholder="<?= t('login_pass_ph') ?>"
                           <?= $blocked ? 'disabled' : '' ?> required>
                </div>

                <button type="submit" id="login-btn" class="btn btn-primary btn-block"
                        style="margin-top: 24px;"
                        <?= $blocked ? 'disabled' : '' ?>>
                    <?= t('login_btn') ?>
                </button>

                <div style="text-align: center; margin-top: 24px; font-size: 0.875rem;">
                    <span class="text-muted"><?= t('login_no_account') ?></span>
                    <a href="checkout" style="color: var(--text-primary); font-weight: 600; text-decoration: none;"> <?= t('login_subscribe') ?></a>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.getElementById('login-form').addEventListener('submit', function(e) {
        const btn = document.getElementById('login-btn');
        if (btn.disabled) { e.preventDefault(); return; }
        btn.disabled = true;
        btn.innerHTML = '<i class="ph-bold ph-circle-notch ph-spin"></i> <?= addslashes(t('login_btn_loading')) ?>';
    });
    </script>
</body>
</html>
