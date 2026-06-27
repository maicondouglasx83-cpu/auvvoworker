<?php
/**
 * scripts/test-deepseek.php
 * Testa a conexao com a API DeepSeek e verifica se a chave funciona.
 * Execute: php scripts/test-deepseek.php
 */
require_once __DIR__ . '/../backend/db.php';

$apiKey = defined('DEEPSEEK_API_KEY') ? DEEPSEEK_API_KEY : '';
if ($apiKey === '') {
    die("ERRO: DEEPSEEK_API_KEY nao configurada no .env\n");
}

echo "DEEPSEEK_API_KEY: " . substr($apiKey, 0, 8) . "...\n";
echo "DEEPSEEK_BASE_URL: " . DEEPSEEK_BASE_URL . "\n\n";

echo "Testando DeepSeek API...\n";

$ch = curl_init(DEEPSEEK_BASE_URL . '/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'model' => 'deepseek-chat',
        'messages' => [
            ['role' => 'user', 'content' => 'Responda apenas com: OK'],
        ],
        'max_tokens' => 10,
        'stream' => false,
    ]),
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "ERRO cURL: {$error}\n";
    exit(1);
}

echo "HTTP {$httpCode}\n";

if ($httpCode === 200) {
    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? 'SEM CONTEUDO';
    echo "OK! Resposta: {$content}\n";
    echo "Modelo usado: " . ($data['model'] ?? '?') . "\n";
    exit(0);
}

echo "FALHA: {$response}\n";
exit(1);
