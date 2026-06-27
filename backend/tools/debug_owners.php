<?php
require __DIR__ . '/../db.php';
$st = $pdo->query('SELECT id,user_id,name FROM crm_automation_flows');
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo 'flow '.json_encode($r)."\n";
$st = $pdo->query('SELECT id,user_id,name,default_agent_id FROM whatsapp_connections');
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo 'conn '.json_encode($r)."\n";
