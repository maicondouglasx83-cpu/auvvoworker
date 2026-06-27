<?php
/**
 * scripts/diagnostico.php
 * Diagnostico rapido do fluxo de mensagens Auvvo.
 * Execute: php scripts/diagnostico.php
 */
require_once __DIR__ . '/../backend/db.php';

echo str_repeat('=', 60) . "\n";
echo " DIAGNOSTICO AUVVO — Fluxo de Mensagens\n";
echo str_repeat('=', 60) . "\n\n";

// 1. Banco de dados
echo "[1] Conexao MySQL: ";
try {
    $pdo->query('SELECT 1');
    echo "OK\n";
} catch (Exception $e) {
    echo "FALHA: " . $e->getMessage() . "\n";
}

// 2. APIs configuradas
echo "[2] APIs configuradas:\n";
echo "    OpenRouter: " . (OPENROUTER_API_KEY !== '' ? 'SIM (' . substr(OPENROUTER_API_KEY, 0, 8) . '...)' : 'NAO') . "\n";
echo "    DeepSeek:   " . (defined('DEEPSEEK_API_KEY') && DEEPSEEK_API_KEY !== '' ? 'SIM (' . substr(DEEPSEEK_API_KEY, 0, 8) . '...)' : 'NAO') . "\n";
echo "    Gemini:     " . (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '' ? 'SIM' : 'NAO') . "\n";
echo "    Evolution:  " . (defined('EVOLUTION_API_KEY') && EVOLUTION_API_KEY !== '' ? 'SIM' : 'NAO') . "\n";

// 3. Fila de jobs
echo "[3] Fila de AI jobs:\n";
$stmt = $pdo->query("SELECT status, COUNT(*) as c FROM auvvo_ai_jobs GROUP BY status");
while ($row = $stmt->fetch()) {
    echo "    {$row['status']}: {$row['c']}\n";
}

// 4. Agentes com WhatsApp conectado
echo "[4] Agentes com WhatsApp:\n";
$stmt = $pdo->query(
    "SELECT a.id, a.name, a.model, a.status, wc.status as conn_status
     FROM agents a
     LEFT JOIN whatsapp_connections wc ON wc.id = a.whatsapp_connection_id
     WHERE a.status != 'draft'
     ORDER BY a.id DESC
     LIMIT 10"
);
while ($row = $stmt->fetch()) {
    $conn = $row['conn_status'] ?? 'sem_conexao';
    echo "    #{$row['id']} {$row['name']} | modelo: {$row['model']} | agente: {$row['status']} | whatsapp: {$conn}\n";
}

// 5. Worker HMAC
echo "[5] Worker HMAC: ";
$hmac = $_ENV['WORKER_HMAC_SECRET'] ?? '';
echo $hmac !== '' ? "CONFIGURADO\n" : "NAO CONFIGURADO (worker nao vai iniciar!)\n";

// 6. Evolution API test
echo "[6] Evolution API test: ";
$evoUrl = rtrim((string)($_ENV['EVOLUTION_API_URL'] ?? ''), '/');
$evoKey = (string)($_ENV['EVOLUTION_API_KEY'] ?? '');
if ($evoUrl && $evoKey) {
    $ch = curl_init($evoUrl . '/manager/settings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['apikey: ' . $evoKey],
        CURLOPT_TIMEOUT => 10,
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "HTTP {$code}\n";
} else {
    echo "NAO CONFIGURADO\n";
}

// 7. Webhook secret
echo "[7] Webhook secret: " . (($_ENV['WEBHOOK_SECRET'] ?? '') !== '' ? "CONFIGURADO" : "NAO CONFIGURADO") . "\n";

echo "\n" . str_repeat('=', 60) . "\n";
echo " Dica: Rode 'node auvvo-worker/src/index.js' para iniciar o worker.\n";
echo " Monitore com: tail -f backend/webhook_debug.log\n";
echo str_repeat('=', 60) . "\n";
