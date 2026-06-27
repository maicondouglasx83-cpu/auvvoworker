<?php
/**
 * Varre contatos e dispara automações LTV (inatividade de compra).
 */
declare(strict_types=1);

define('AUVVO_WEBHOOK_SKIP_HTTP_ROUTER', true);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../migrations.php';
require_once __DIR__ . '/../crm_ltv.inc.php';

header('Content-Type: application/json; charset=utf-8');

function ltv_json(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function ltv_verify_hmac(string $rawBody): bool
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
if (!ltv_verify_hmac($raw)) {
    ltv_json(403, ['ok' => false, 'error' => 'invalid_signature']);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    ltv_json(400, ['ok' => false, 'error' => 'invalid_json']);
}

$limit = max(50, min(500, (int) ($payload['limit'] ?? 200)));
auvvo_run_migrations($pdo);
$fired = auvvo_crm_process_ltv_triggers($pdo, $limit);

ltv_json(200, ['ok' => true, 'fired' => $fired]);
