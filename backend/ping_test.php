<?php
header('Content-Type: application/json; charset=utf-8');
$raw = file_get_contents('php://input');
file_put_contents(__DIR__ . '/ping_test.log', date('Y-m-d H:i:s') . ' | IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ' | Body: ' . $raw . "\n", FILE_APPEND);
echo json_encode(['ok' => true, 'time' => time(), 'secret_header' => $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? 'NONE']);
