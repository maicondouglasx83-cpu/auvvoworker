<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../whatsapp_connections.inc.php';
require __DIR__ . '/../Contacts.php';
require __DIR__ . '/../crm_flow_agent.inc.php';
require __DIR__ . '/../crm_automation_triggers.inc.php';
require_once __DIR__ . '/../crm_automation_motor.inc.php';

$st = $pdo->query('SELECT * FROM whatsapp_connections WHERE id = 2');
$conn = $st->fetch(PDO::FETCH_ASSOC);
$userId = (int)$conn['user_id'];
$routing = auvvo_whatsapp_resolve_routing_agent_id($pdo, $userId, 2, $conn);
echo "routing_agent=$routing\n";

// Second message on existing contact (converse session armed, turns=0)
$crm = new Contacts($pdo);
$contact = $crm->get($userId, 1);
$contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);

$GLOBALS['auvvo_automation_flow_handled'] = false;
$GLOBALS['auvvo_automation_ai_handled'] = false;

$hit = auvvo_flow_dispatch_active_inbound($pdo, $userId, $contact, 'boa tarde', 2, $conn);
echo 'dispatch: '.json_encode($hit)."\n";

$st = $pdo->query('SELECT id,incoming_msg,LEFT(response_msg,60) resp,type FROM conversation_logs ORDER BY id DESC LIMIT 3');
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo 'log: '.json_encode($r)."\n";
