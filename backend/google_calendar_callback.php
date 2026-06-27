<?php
require_once '../includes/auth.php';
require_once 'db.php';
require_once 'GoogleCalendar.php';

$user_id = (int) ($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    header('Location: ../login');
    exit;
}

if (!GoogleCalendar::isOAuthAppConfigured()) {
    header('Location: ../configuracoes?gcal_error=not_configured');
    exit;
}

$state = (string)($_GET['state'] ?? '');
$code  = (string)($_GET['code'] ?? '');
$err   = (string)($_GET['error'] ?? '');

if ($err !== '') {
    header('Location: ../configuracoes?gcal_error=' . urlencode($err));
    exit;
}

if ($code === '' || $state === '' || empty($_SESSION['gcal_oauth_state']) || !hash_equals($_SESSION['gcal_oauth_state'], $state)) {
    header('Location: ../configuracoes?gcal_error=invalid_state');
    exit;
}

unset($_SESSION['gcal_oauth_state']);

$token = GoogleCalendar::exchangeCodeForToken($code);
if (!empty($token['error'])) {
    $msg = $token['error_description'] ?? (is_string($token['error']) ? $token['error'] : 'token_error');
    header('Location: ../configuracoes?gcal_error=' . urlencode($msg));
    exit;
}

try {
    GoogleCalendar::upsertToken($pdo, $user_id, $token, 'primary');
    // Se o utilizador já tinha calendar_id em Configurações, aplica ao token deste user_id.
    GoogleCalendar::syncEffectiveCalendarIdToToken($pdo, $user_id);
    auvvo_settings_enable_gcal_scheduling($pdo, $user_id);
} catch (Throwable $e) {
    error_log('[Auvvo GCal] callback save token error: ' . $e->getMessage());
    header('Location: ../configuracoes?gcal_error=save_failed');
    exit;
}

header('Location: ../configuracoes?gcal_success=1');
exit;

