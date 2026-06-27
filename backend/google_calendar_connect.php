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

$state = bin2hex(random_bytes(16));
$_SESSION['gcal_oauth_state'] = $state;

$url = GoogleCalendar::buildAuthUrl($state);
header('Location: ' . $url);
exit;

