<?php
/**
 * SSE — eventos de conversa para o painel (conversas.php).
 */
declare(strict_types=1);

define('AUVVO_SESSION_API', true);
require_once __DIR__ . '/../includes/session_bootstrap.inc.php';
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$since  = (int) ($_GET['since'] ?? 0);

// Libera lock da sessão — sem isso, TODAS as outras páginas ficam travadas enquanto o SSE estiver aberto
session_write_close();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_flush();
}

$iterations = 0;
$maxIter    = 15;

while ($iterations < $maxIter && !connection_aborted()) {
    $stmt = $pdo->prepare(
        'SELECT id, agent_id, contact_jid, event_type, payload, created_at
         FROM conversation_events WHERE user_id = ? AND id > ? ORDER BY id ASC LIMIT 50'
    );
    $stmt->execute([$userId, $since]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
        $since = (int) $row['id'];
        echo 'id: ' . $since . "\n";
        echo 'data: ' . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    if ($rows === []) {
        echo ": keepalive\n\n";
        flush();
    }

    $iterations++;
    sleep(2);
}
