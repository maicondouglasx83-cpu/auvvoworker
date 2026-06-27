<?php
/**
 * Diagnóstico Google Calendar (sem expor segredos).
 * Abra logado no painel: .../backend/gcal_status.php
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/GoogleCalendar.php';

auvvo_ensure_settings_calendar_columns($pdo);

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$oauthOk = GoogleCalendar::isOAuthAppConfigured();

$enabled = false;
try {
    $stmt = $pdo->prepare('SELECT google_calendar_enabled FROM settings WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row     = $stmt->fetch(PDO::FETCH_ASSOC);
    $enabled = (int) ($row['google_calendar_enabled'] ?? 0) === 1;
} catch (Throwable $e) {
    $enabled = false;
}

$tokenRow = null;
try {
    $tokenRow = GoogleCalendar::loadToken($pdo, $userId);
} catch (Throwable $e) {
    $tokenRow = null;
}

$hasToken        = $tokenRow !== null;
$hasRefresh      = $hasToken && !empty($tokenRow['refresh_token']);
$expiresAt       = $hasToken ? ($tokenRow['expires_at'] ?? '') : '';
$calendarInToken = $hasToken ? (string) ($tokenRow['calendar_id'] ?? '') : '';

$effectiveCal = '';
if ($hasToken && $oauthOk) {
    try {
        $effectiveCal = GoogleCalendar::getEffectiveCalendarId($pdo, $userId);
    } catch (Throwable $e) {
        $effectiveCal = '(erro ao resolver)';
    }
}

/** Igual ao que o webhook usa para montar o prompt (createEvent + [[GCAL_EVENT]]) */
$agentSeesConnected = $oauthOk && $enabled && $hasToken;

$report = [
    'user_id'                    => $userId,
    'oauth_app_configured_env' => $oauthOk,
    'google_calendar_enabled'  => $enabled,
    'token_saved_in_database'  => $hasToken,
    'has_refresh_token'        => $hasRefresh,
    'token_expires_at'         => $expiresAt,
    'calendar_id_in_token_row' => $calendarInToken ?: null,
    'effective_calendar_id'    => $effectiveCal ?: null,
    'agent_prompt_connected'   => $agentSeesConnected,
    'hint'                     => $agentSeesConnected
        ? 'OK: o bot deve receber instruções de calendário ligado. Se ainda recusar, atualize MasterPromptBuilder.php no servidor e limpe cache do modelo; revise prompt_base/FAQ que falem em "não tem agenda".'
        : (!$oauthOk
            ? 'Falta GOOGLE_OAUTH_CLIENT_ID / GOOGLE_OAUTH_CLIENT_SECRET (ou REDIRECT_URI inválido) no .env deste servidor.'
            : (!$enabled
                ? 'Ative "Agendamentos" em Configurações.'
                : (!$hasToken
                    ? 'Clique em "Conectar Google Calendar" até guardar token (login Google tem de concluir sem erro). Só autorizar no telefone no passado não grava nada se o callback falhou.'
                    : 'Estado incomum — verifique logs PHP no callback.'))),
];

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnóstico Google Calendar</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 640px; margin: 40px auto; padding: 0 20px; line-height: 1.5; }
        .ok { color: #0a0; }
        .bad { color: #c00; }
        pre { background: #f4f4f5; padding: 16px; border-radius: 8px; overflow: auto; font-size: 13px; }
        a { color: #2563eb; }
    </style>
</head>
<body>
    <h1>Diagnóstico Google Calendar</h1>
    <p>Conta (user_id): <strong><?= (int) $report['user_id'] ?></strong></p>
    <ul>
        <li>OAuth no .env (servidor): <span class="<?= $report['oauth_app_configured_env'] ? 'ok' : 'bad' ?>"><?= $report['oauth_app_configured_env'] ? 'OK' : 'FALTA / incompleto' ?></span></li>
        <li>Agendamentos ativos nas Configurações: <span class="<?= $report['google_calendar_enabled'] ? 'ok' : 'bad' ?>"><?= $report['google_calendar_enabled'] ? 'Sim' : 'Não' ?></span></li>
        <li>Token guardado na base (após «Conectar»): <span class="<?= $report['token_saved_in_database'] ? 'ok' : 'bad' ?>"><?= $report['token_saved_in_database'] ? 'Sim' : 'Não' ?></span></li>
        <li>refresh_token presente: <span class="<?= $report['has_refresh_token'] ? 'ok' : 'bad' ?>"><?= $report['has_refresh_token'] ? 'Sim' : 'Não' ?></span></li>
        <li>Calendar ID efetivo: <code><?= htmlspecialchars((string) ($report['effective_calendar_id'] ?? '')) ?></code></li>
        <li><strong>O agente WhatsApp vê calendário «ligado» no prompt:</strong>
            <span class="<?= $report['agent_prompt_connected'] ? 'ok' : 'bad' ?>"><?= $report['agent_prompt_connected'] ? 'SIM' : 'NÃO' ?></span></li>
    </ul>
    <p><strong>O que fazer:</strong> <?= htmlspecialchars($report['hint']) ?></p>
    <p><a href="../configuracoes">← Voltar às Configurações</a> · <a href="?format=json">JSON</a></p>
    <pre><?= htmlspecialchars(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
</body>
</html>
