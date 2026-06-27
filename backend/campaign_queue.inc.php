<?php
declare(strict_types=1);

require_once __DIR__ . '/migrations.php';

function auvvo_campaign_queue_populate(PDO $pdo, int $campaignId): int
{
    auvvo_run_migrations($pdo);
    $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE id = ? LIMIT 1');
    $stmt->execute([$campaignId]);
    $camp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$camp || empty($camp['csv_file'])) {
        return 0;
    }

    $path = __DIR__ . '/../uploads/campaigns/' . $camp['csv_file'];
    if (!is_file($path)) {
        return 0;
    }

    $pdo->prepare('DELETE FROM campaign_send_queue WHERE campaign_id = ? AND status = ?')
        ->execute([$campaignId, 'pending']);

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (count($lines) < 2) {
        return 0;
    }
    array_shift($lines);

    $message = (string) ($camp['message'] ?? '');
    $ins     = $pdo->prepare(
        'INSERT INTO campaign_send_queue (campaign_id, phone, name, message_rendered, status, scheduled_at)
         VALUES (?, ?, ?, ?, \'pending\', ?)'
    );
    $count = 0;
    foreach ($lines as $line) {
        $cols = str_getcsv($line);
        if (count($cols) < 1) {
            continue;
        }
        $phone = preg_replace('/\D/', '', (string) ($cols[0] ?? ''));
        if (strlen($phone) < 10) {
            continue;
        }
        $name = (string) ($cols[1] ?? '');
        $rendered = str_replace(
            ['{{nome}}', '{{telefone}}'],
            [$name, $phone],
            $message
        );
        $ins->execute([
            $campaignId,
            $phone,
            $name,
            $rendered,
            $camp['scheduled_at'] ?? null,
        ]);
        $count++;
    }

    $pdo->prepare('UPDATE campaigns SET total_contacts = ?, status = ? WHERE id = ?')
        ->execute([$count, 'running', $campaignId]);

    return $count;
}
