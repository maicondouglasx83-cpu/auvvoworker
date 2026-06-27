<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../crm_flow_agent.inc.php';
require __DIR__ . '/../Contacts.php';

$userId = 1;
$crm = new Contacts($pdo);
$contact = $crm->get($userId, 1);
echo "contact: " . json_encode(['id'=>$contact['id']??null,'jid'=>$contact['jid']??null]) . "\n";

$st = $pdo->prepare('SELECT * FROM crm_automation_dedupe WHERE user_id=? AND contact_id=?');
$st->execute([$userId, 1]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo 'dedupe: '.json_encode($r)."\n";

require_once __DIR__ . '/../context_memory.inc.php';
$mem = auvvo_contact_memory_get($pdo, $userId, (string)($contact['jid']??''));
echo 'memory keys: '.implode(',', array_keys($mem))."\n";
echo '_flow_converse: '.json_encode($mem['_flow_converse']??null)."\n";
echo '_brain_mission: '.mb_substr((string)($mem['_brain_mission']??''),0,80)."\n";

$conn = ['id'=>2,'ai_mode'=>'flows_first','default_agent_id'=>null];
echo 'in_flow_scope: '.(auvvo_automation_contact_in_flow_scope($pdo,$userId,$contact,2)?'yes':'no')."\n";
echo 'active_binding: '.(auvvo_automation_has_active_flow_binding($pdo,$userId,$contact)?'yes':'no')."\n";

$st = $pdo->query('SELECT id,status,flow_id FROM crm_automation_runs ORDER BY id DESC LIMIT 3');
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo 'run: '.json_encode($r)."\n";

$st = $pdo->query('SELECT id,node_id,status,detail FROM crm_automation_run_steps ORDER BY id DESC LIMIT 5');
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo 'step: '.json_encode($r)."\n";
