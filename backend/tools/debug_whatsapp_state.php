<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../migrations.php';
auvvo_run_migrations($pdo);

function q(PDO $pdo, string $sql): void {
    echo $sql . "\n";
    try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
        }
    } catch (Throwable $e) {
        echo 'ERR: ' . $e->getMessage() . "\n";
    }
    echo "\n";
}

q($pdo, 'SELECT COUNT(*) AS c FROM users');
q($pdo, 'SELECT COUNT(*) AS c FROM agents');
q($pdo, 'SELECT id,name,status,default_agent_id,ai_mode,evolution_instance FROM whatsapp_connections');
q($pdo, 'SELECT id,name,is_active,pipeline_id FROM crm_automation_flows');
q($pdo, 'SELECT id,agent_id,LEFT(incoming_msg,40) AS inc,LEFT(response_msg,40) AS resp,type,created_at FROM conversation_logs ORDER BY id DESC LIMIT 10');
q($pdo, 'SELECT id,status,mode,flow_id,created_at FROM crm_automation_runs ORDER BY id DESC LIMIT 5');
