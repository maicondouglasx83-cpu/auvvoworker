<?php
require_once '../includes/auth.php';
require_once 'db.php';
require_once 'GoogleSheets.php';
require_once 'migrations.php';

auvvo_run_migrations($pdo);

if (!GoogleSheets::isOAuthAppConfigured()) {
    header('Location: ../integracoes?error=sheets_not_configured');
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['gsheets_oauth_state'] = $state;
header('Location: ' . GoogleSheets::buildAuthUrl($state));
exit;
