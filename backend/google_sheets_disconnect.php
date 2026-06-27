<?php
require_once '../includes/auth.php';
require_once 'db.php';
require_once 'GoogleSheets.php';
csrf_verify();

GoogleSheets::deleteToken($pdo, (int) $_SESSION['user_id']);
header('Location: ../integracoes?panel=sheets&disconnected=1');
exit;
