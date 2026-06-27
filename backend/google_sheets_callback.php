<?php
require_once '../includes/auth.php';
require_once 'db.php';
require_once 'GoogleSheets.php';
require_once 'migrations.php';

auvvo_run_migrations($pdo);

$user_id = (int) ($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    header('Location: ../login');
    exit;
}

if (!GoogleSheets::isOAuthAppConfigured()) {
    header('Location: ../integracoes?error=sheets_not_configured');
    exit;
}

$state = (string) ($_GET['state'] ?? '');
$code  = (string) ($_GET['code'] ?? '');
$err   = (string) ($_GET['error'] ?? '');

if ($err !== '') {
    header('Location: ../integracoes?panel=sheets&error=' . urlencode($err));
    exit;
}

if ($code === '' || $state === '' || empty($_SESSION['gsheets_oauth_state']) || !hash_equals($_SESSION['gsheets_oauth_state'], $state)) {
    header('Location: ../integracoes?panel=sheets&error=invalid_state');
    exit;
}

unset($_SESSION['gsheets_oauth_state']);

$token = GoogleSheets::exchangeCodeForToken($code);
if (!empty($token['error'])) {
    $msg = $token['error_description'] ?? (is_string($token['error']) ? $token['error'] : 'token_error');
    header('Location: ../integracoes?panel=sheets&error=' . urlencode($msg));
    exit;
}

try {
    GoogleSheets::upsertToken($pdo, $user_id, $token);
} catch (Throwable $e) {
    error_log('[Auvvo GSheets] ' . $e->getMessage());
    header('Location: ../integracoes?panel=sheets&error=save_failed');
    exit;
}

header('Location: ../integracoes?panel=sheets&success=1');
exit;
