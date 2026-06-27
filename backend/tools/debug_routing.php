<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../whatsapp_connections.inc.php';

foreach ([1, 2] as $cid) {
    $st = $pdo->prepare('SELECT * FROM whatsapp_connections WHERE id = ?');
    $st->execute([$cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) continue;
    $uid = (int)$c['user_id'];
    $rid = auvvo_whatsapp_resolve_routing_agent_id($pdo, $uid, $cid, $c);
    echo "conn $cid user=$uid routing=$rid\n";
}
