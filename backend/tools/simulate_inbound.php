<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../whatsapp_connections.inc.php';
require __DIR__ . '/../Contacts.php';
require __DIR__ . '/../crm_automation_triggers.inc.php';
require __DIR__ . '/../crm_flow_agent.inc.php';

$st = $pdo->query('SELECT * FROM whatsapp_connections');
$conns = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($conns as $c) {
    $uid = (int)$c['user_id'];
    $cid = (int)$c['id'];
    $rid = (int)($c['default_agent_id'] ?? 0);
    if ($rid <= 0) {
        $rid = auvvo_whatsapp_resolve_routing_agent_id($pdo, $uid, $cid, $c);
    }
    echo "conn $cid user=$uid name={$c['name']} routing=$rid default=".($c['default_agent_id']??'null')." ai_mode={$c['ai_mode']}\n";
}

// Simulate inbound on conn 2
$conn = null;
foreach ($conns as $c) {
    if ((int)$c['id'] === 2) $conn = $c;
}
if (!$conn) { echo "no conn 2\n"; exit(1); }

$userId = (int)$conn['user_id'];
$crm = new Contacts($pdo);
$testJid = '5541999990001@s.whatsapp.net';
$upsert = $crm->upsertFromWebhook($userId, (int)($conn['default_agent_id'] ?: 38), $testJid, 'Teste', 2);
echo "upsert: " . json_encode($upsert) . "\n";
$contact = $crm->get($userId, (int)$upsert['id']);
require_once __DIR__ . '/../crm_automation_motor.inc.php';
$contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);

$GLOBALS['auvvo_automation_flow_handled'] = false;
$GLOBALS['auvvo_automation_ai_handled'] = false;

auvvo_crm_fire_whatsapp_triggers($pdo, $userId, 38, $contact, true, 'boa tarde teste', 2);

echo 'flow_handled=' . (auvvo_automation_flow_was_handled() ? 'yes' : 'no') . "\n";
echo 'ai_handled=' . (auvvo_automation_ai_was_handled() ? 'yes' : 'no') . "\n";
echo 'block_standalone=' . (auvvo_automation_should_block_standalone($pdo, $userId, $contact, $conn) ? 'yes' : 'no') . "\n";

$st = $pdo->query('SELECT id,incoming_msg,response_msg,type FROM conversation_logs ORDER BY id DESC LIMIT 3');
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo json_encode($r) . "\n";
