<?php
/**
 * Bootstrap de sessão compartilhado (api.php, events.php, auth.php, etc.).
 */
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
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
}

$sessionTimeout = 8 * 60 * 60;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $sessionTimeout)) {
    session_unset();
    session_destroy();
    if (defined('AUVVO_SESSION_API') && AUVVO_SESSION_API) {
        if (!headers_sent()) {
            http_response_code(401);
        }
        exit;
    }
    if (!headers_sent()) {
        header('Location: login?expired=1');
    }
    exit;
}
$_SESSION['last_activity'] = time();

if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_created']) || (time() - (int) $_SESSION['csrf_token_created'] > 1800)) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_created'] = time();
}
