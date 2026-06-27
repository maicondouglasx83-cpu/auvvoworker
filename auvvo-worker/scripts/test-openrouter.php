<?php
require __DIR__ . '/../../backend/db.php';

$key = OPENROUTER_API_KEY;
$model = defined('OPENROUTER_DEFAULT_MODEL') ? OPENROUTER_DEFAULT_MODEL : 'openai/gpt-4o-mini';
if (strpos($model, 'openrouter/') === 0) {
    $after = substr($model, strlen('openrouter/'));
    $model = str_contains($after, '/') ? $after : $model;
}

echo 'key_len=' . strlen($key) . ' model=' . $model . PHP_EOL;

$payload = json_encode([
    'model' => $model,
    'messages' => [['role' => 'user', 'content' => 'Responda só: ok']],
    'max_tokens' => 32,
]);

$ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
        'HTTP-Referer: https://auvvo.com',
        'X-Title: Auvvo',
    ],
    CURLOPT_TIMEOUT => 45,
]);
$r = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo 'HTTP ' . $code . PHP_EOL;
if ($err !== '') {
    echo 'curl: ' . $err . PHP_EOL;
}
echo substr((string) $r, 0, 500) . PHP_EOL;
