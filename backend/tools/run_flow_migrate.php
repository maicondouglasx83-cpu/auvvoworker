<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../migrations.php';
auvvo_run_migrations($pdo);
$n = (int) $pdo->query('SELECT COUNT(*) FROM crm_automation_flows')->fetchColumn();
echo "flows={$n}\n";
$rows = $pdo->query('SELECT id, name, flow_data FROM crm_automation_flows ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $d = json_decode((string) $r['flow_data'], true);
    $schema = $d['_flow_schema'] ?? 0;
    $types = [];
    foreach (($d['drawflow']['Home']['data'] ?? []) as $node) {
        $types[] = ($node['class'] ?? '?') . ':' . (($node['data']['trigger_type'] ?? $node['data']['label'] ?? ''));
    }
    echo $r['id'] . ' ' . $r['name'] . ' schema=' . $schema . ' nodes=' . implode(',', $types) . "\n";
}
