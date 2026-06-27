<?php
require_once '../includes/auth.php';
require_once 'db.php';
require_once 'GoogleCalendar.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../configuracoes');
    exit;
}

csrf_verify();

$user_id = (int)$_SESSION['user_id'];
GoogleCalendar::deleteToken($pdo, $user_id);

header('Location: ../configuracoes?gcal_disconnected=1');
exit;

