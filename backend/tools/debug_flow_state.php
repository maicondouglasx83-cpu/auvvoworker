<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../migrations.php';
auvvo_run_migrations($pdo);

$flowId = 9;
$st = $pdo->prepare('SELECT flow_data FROM crm_automation_flows WHERE id = ?');
$st->execute([$flowId]);
$fd = json_decode((string)$st->fetchColumn(), true);
echo "schema=" . ($fd['_flow_schema'] ?? 0) . "\n";
foreach (($fd['drawflow']['Home']['data'] ?? []) as $nid => $node) {
    echo "node $nid: " . ($node['class'] ?? '?') . " label=" . ($node['data']['label'] ?? '') . " trigger=" . ($node['data']['trigger_type'] ?? '') . " trigger_val=" . ($node['data']['trigger_value'] ?? '') . " agent=" . ($node['data']['agent_id'] ?? '') . "\n";
}

echo "\n--- dedupe ---\n";
$st = $pdo->query('SELECT * FROM crm_automation_dedupe ORDER BY id DESC LIMIT 10');
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo json_encode($r) . "\n";

echo "\n--- contacts recent ---\n";
$st = $pdo->query('SELECT id,phone,jid,agent_id,memory_json FROM crm_contacts ORDER BY id DESC LIMIT 3');
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo 'id='.$r['id'].' phone='.$r['phone'].' agent='.$r['agent_id'].' mem='.mb_substr((string)$r['memory_json'],0,200)."\n";
}

echo "\n--- automation runs ---\n";
$st = $pdo->query('SELECT id,status,mode,flow_id,started_at FROM crm_automation_runs ORDER BY id DESC LIMIT 5');
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo json_encode($r) . "\n";
