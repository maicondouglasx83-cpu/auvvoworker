<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ai_queue.inc.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input') ?: '';
$sig = (string) ($_SERVER['HTTP_X_AUVVO_SIGNATURE'] ?? '');
$ts  = (string) ($_SERVER['HTTP_X_AUVVO_TIMESTAMP'] ?? '');
if ($sig === '' || $ts === '' || abs(time() - (int) $ts) > 300) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}
$expected = hash_hmac('sha256', $ts . '.' . $raw, auvvo_worker_hmac_secret());
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

auvvo_ai_promote_debounced_jobs($pdo, 100);
echo json_encode([
    'ok'    => true,
    'stats' => auvvo_ai_queue_stats($pdo),
], JSON_UNESCAPED_UNICODE);
