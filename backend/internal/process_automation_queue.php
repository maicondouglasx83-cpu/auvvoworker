<?php
/**
 * Processa fila de automações CRM — worker Node (HMAC).
 */
declare(strict_types=1);

define('AUVVO_WEBHOOK_SKIP_HTTP_ROUTER', true);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../migrations.php';
require_once __DIR__ . '/../crm_automation.inc.php';

header('Content-Type: application/json; charset=utf-8');

function automation_queue_json(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function automation_queue_verify_hmac(string $rawBody): bool
{
    $sig = (string) ($_SERVER['HTTP_X_AUVVO_SIGNATURE'] ?? '');
    $ts  = (string) ($_SERVER['HTTP_X_AUVVO_TIMESTAMP'] ?? '');
    if ($sig === '' || $ts === '' || !ctype_digit($ts)) {
        return false;
    }
    if (abs(time() - (int) $ts) > 300) {
        return false;
    }
    $expected = hash_hmac('sha256', $ts . '.' . $rawBody, auvvo_worker_hmac_secret());

    return hash_equals($expected, $sig);
}

$raw = file_get_contents('php://input') ?: '{}';
if (!automation_queue_verify_hmac($raw)) {
    automation_queue_json(403, ['ok' => false, 'error' => 'invalid_signature']);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    automation_queue_json(400, ['ok' => false, 'error' => 'invalid_json']);
}

$limit = max(1, min(50, (int) ($payload['limit'] ?? 25)));
auvvo_run_migrations($pdo);
$processed = auvvo_crm_process_automation_queue($pdo, $limit);
if ($processed > 0) {
    auvvo_worker_touch_heartbeat($pdo);
}

automation_queue_json(200, ['ok' => true, 'processed' => $processed]);
