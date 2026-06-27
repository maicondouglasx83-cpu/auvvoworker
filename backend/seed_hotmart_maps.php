<?php
require __DIR__ . '/db.php';
require __DIR__ . '/inbound_webhook_service.inc.php';
$st = $pdo->prepare('SELECT id, user_id FROM inbound_webhooks WHERE url_slug = ? LIMIT 1');
$st->execute(['hotmart-test']);
$w = $st->fetch(PDO::FETCH_ASSOC);
if (!$w) {
    echo "webhook not found\n";
    exit(1);
}
auvvo_inbound_webhook_save_maps($pdo, (int) $w['user_id'], (int) $w['id'], [
    ['json_path' => 'buyer.name', 'crm_field' => 'name'],
    ['json_path' => 'buyer.email', 'crm_field' => 'email'],
    ['json_path' => 'buyer.phone', 'crm_field' => 'phone'],
]);
echo "maps saved for webhook #" . $w['id'] . "\n";
