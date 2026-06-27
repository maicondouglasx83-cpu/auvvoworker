<?php
// backend/db.php
// Arquivo de Conexão Segura com o Banco de Dados MySQL usando PDO

// ==========================================================
// CARREGAMENTO DE VARIÁVEIS DE AMBIENTE (.env)
// ==========================================================
$_env_file = __DIR__ . '/../.env';
if (file_exists($_env_file)) {
    static $auvvo_env_loaded_mtime = null;
    $mtime = (int) filemtime($_env_file);
    if ($auvvo_env_loaded_mtime !== $mtime) {
        $auvvo_env_loaded_mtime = $mtime;
        $lines = file($_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            if (strpos($line, '=') === false) {
                continue;
            }
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim(trim($val), "\"'");
            $_ENV[$key] = $val;
        }
    }
}

// ==========================================================
// MODO DE AMBIENTE (development | production)
// ==========================================================
define('APP_ENV', $_ENV['APP_ENV'] ?? 'production');
define('IS_DEV',  APP_ENV === 'development');
$_webhook_trace = strtolower(trim((string) ($_ENV['WEBHOOK_TRACE_LOG'] ?? '')));
define('WEBHOOK_TRACE_LOG', in_array($_webhook_trace, ['1', 'true', 'yes', 'on'], true));

// ==========================================================
// CONFIGURAÇÃO DO BANCO DE DADOS
// ==========================================================
$host    = $_ENV['DB_HOST'] ?? '127.0.0.1';
$db      = $_ENV['DB_NAME'] ?? 'Auvvo_saas';
$user    = $_ENV['DB_USER'] ?? 'root';
$pass    = $_ENV['DB_PASS'] ?? '';
$charset = 'utf8mb4';

// ==========================================================
// CONFIGURAÇÃO GLOBAL: EVOLUTION API (WhatsApp)
// ==========================================================
define('EVOLUTION_API_URL',   $_ENV['EVOLUTION_API_URL']   ?? 'http://localhost:8080');
define('EVOLUTION_API_KEY',   $_ENV['EVOLUTION_API_KEY']   ?? '');
define('APP_BASE_URL', rtrim(trim($_ENV['APP_BASE_URL'] ?? ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'))), '/'));

/**
 * Prefixo público da app no host, sem barras (ex.: agentes → .../agentes/checkout.php).
 * Em produção na raiz (ex.: https://auvvo.com/backend/...), deixe APP_HTTP_PREFIX vazio no .env
 * ou omita — o sistema detecta quando SCRIPT_NAME é /backend/... sem segmento antes de "backend".
 */
function auvvo_resolve_app_http_prefix(): string {
    $raw = trim(trim((string)($_ENV['APP_HTTP_PREFIX'] ?? $_ENV['APP_HTTP_PATH'] ?? ''), "\"'"));
    if ($raw !== '') {
        return trim($raw, '/');
    }
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    // Ex.: /agentes/backend/foo.php → "agentes"; /backend/foo.php na raiz → sem prefixo
    if ($script !== '' && preg_match('#^/([^/]+)/backend/#', $script, $m)) {
        $seg = $m[1] ?? '';
        if ($seg !== '' && $seg !== 'backend') {
            return $seg;
        }
    }
    return '';
}

define('APP_HTTP_PREFIX', auvvo_resolve_app_http_prefix());

/**
 * Chave HMAC para POSTs internos (extensões futuras). Não é usada pelo Evolution. Derivada de DB_* + APP_BASE_URL.
 */
function auvvo_worker_hmac_secret(): string {
    $explicit = trim((string) ($_ENV['WORKER_HMAC_SECRET'] ?? ''));
    if ($explicit !== '') {
        return $explicit;
    }
    if (defined('IS_DEV') && IS_DEV) {
        $material = implode("\x1e", [
            'auvvo-internal-worker-v1',
            $_ENV['DB_PASS'] ?? '',
            $_ENV['DB_USER'] ?? '',
            $_ENV['DB_NAME'] ?? '',
            $_ENV['DB_HOST'] ?? '',
            APP_BASE_URL,
        ]);

        return hash('sha256', $material);
    }
    error_log('[Auvvo] WORKER_HMAC_SECRET não configurado em produção.');

    return '';
}

/** URL absoluta (APP_BASE_URL + prefixo opcional + path). Ex.: app_http_url('checkout.php?plan=anual') */
function app_http_url(string $path): string {
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $base = rtrim(APP_BASE_URL, '/');
    $pre  = APP_HTTP_PREFIX;
    if ($pre !== '') {
        return $base . '/' . $pre . '/' . $path;
    }
    return $base . '/' . $path;
}

/**
 * Dígitos do peer WhatsApp (PN), ou string vazia para @lid sem número embutido.
 * Evolution Go: ex. 5511999999999:38@s.whatsapp.net.
 */
if (!function_exists('auvvo_whatsapp_peer_digits')) {
    function auvvo_whatsapp_peer_digits(string $jid): string {
        $jid = trim($jid);
        if ($jid === '') {
            return '';
        }
        if (preg_match('/(\d{10,15})(?::\d+)?@s\.whatsapp\.net/i', $jid, $m)) {
            return $m[1];
        }
        if (preg_match('/(\d{10,15})(?::\d+)?@c\.us/i', $jid, $m)) {
            return $m[1];
        }
        if (preg_match('/^(\d{10,15})(?::\d+)?@/i', $jid, $m)) {
            return $m[1];
        }
        if (preg_match('/^(\d{10,15})$/', $jid, $m)) {
            return $m[1];
        }
        return '';
    }

    function auvvo_canonical_whatsapp_jid(string $jid): string {
        $d = auvvo_whatsapp_peer_digits($jid);
        return $d !== '' ? ($d . '@s.whatsapp.net') : trim($jid);
    }

    /**
     * JID @lid no painel: tenta achar PN@s.whatsapp.net da mesma conversa nos logs
     * (mesmo texto recebido em contact_jid numérico).
     */
    function auvvo_resolve_pn_jid_from_thread(PDO $pdo, int $agentId, string $ambiguousJid): string {
        if (auvvo_whatsapp_peer_digits($ambiguousJid) !== '') {
            return auvvo_canonical_whatsapp_jid($ambiguousJid);
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT incoming_msg FROM conversation_logs
                 WHERE agent_id = ? AND contact_jid = ?
                   AND incoming_msg IS NOT NULL AND TRIM(incoming_msg) != ''
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$agentId, $ambiguousJid]);
            $msg = $stmt->fetchColumn();
            if ($msg === false || trim((string) $msg) === '') {
                return '';
            }
            $msgTrim = trim((string) $msg);
            $stmt2 = $pdo->prepare(
                "SELECT contact_jid FROM conversation_logs
                 WHERE agent_id = ? AND incoming_msg = ?
                   AND contact_jid REGEXP '^[0-9]{10,15}@s\\\\.whatsapp\\\\.net\$'
                 ORDER BY (contact_jid REGEXP '^55[0-9]{10,11}@s\\\\.whatsapp\\\\.net\$') DESC, id DESC
                 LIMIT 5"
            );
            $stmt2->execute([$agentId, $msgTrim]);
            while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                $cj = trim((string) ($row['contact_jid'] ?? ''));
                if ($cj !== '' && strcasecmp($cj, $ambiguousJid) !== 0) {
                    return auvvo_canonical_whatsapp_jid($cj);
                }
            }
        } catch (Throwable $e) {
            return '';
        }
        return '';
    }

    /** JIDs equivalentes para bater em conversation_states (LID vs número). */
    function auvvo_conversation_contact_jid_variants(PDO $pdo, int $agentId, string $contactJid): array {
        $contactJid = trim($contactJid);
        $out = [$contactJid];
        $r = auvvo_resolve_pn_jid_from_thread($pdo, $agentId, $contactJid);
        if ($r !== '') {
            $out[] = $r;
        }
        $d = auvvo_whatsapp_peer_digits($contactJid);
        if ($d !== '') {
            $out[] = $d . '@s.whatsapp.net';
            $out[] = $d . '@c.us';
        }
        return array_values(array_unique(array_filter($out)));
    }

    function auvvo_is_whatsapp_group_jid(string $jid): bool
    {
        return str_contains(trim($jid), '@g.us');
    }

    /** Dígitos válidos de telefone (10–15), ou string vazia. */
    function auvvo_contact_phone_digits(?string $phone, ?string $jid = null): string
    {
        $candidates = [];
        if ($phone !== null && $phone !== '') {
            $candidates[] = $phone;
        }
        if ($jid !== null && $jid !== '') {
            $candidates[] = $jid;
        }
        foreach ($candidates as $raw) {
            if (str_contains($raw, '@g.us')) {
                continue;
            }
            $d = auvvo_whatsapp_peer_digits($raw);
            if ($d !== '' && strlen($d) >= 10 && strlen($d) <= 15) {
                return $d;
            }
            $digits = preg_replace('/\D/', '', $raw);
            if ($digits !== '' && strlen($digits) >= 10 && strlen($digits) <= 15) {
                return $digits;
            }
        }

        return '';
    }

    function auvvo_format_phone_display(?string $phone, ?string $jid = null, ?string $name = null): string
    {
        if ($jid !== null && auvvo_is_whatsapp_group_jid($jid)) {
            return 'Grupo WhatsApp';
        }
        $digits = auvvo_contact_phone_digits($phone, $jid);
        if ($digits !== '') {
            return '+' . $digits;
        }
        if ($jid !== null && str_contains($jid, '@lid')) {
            return $name !== null && trim($name) !== '' ? trim($name) : 'WhatsApp';
        }

        return $name !== null && trim($name) !== '' ? trim($name) : '—';
    }
}

define('GEMINI_API_KEY',      $_ENV['GEMINI_API_KEY']      ?? '');
/** Chave OpenRouter da plataforma (servidor). Modelos agents.model com prefixo openrouter/ — usuários não configuram. */
define('OPENROUTER_API_KEY',  $_ENV['OPENROUTER_API_KEY']  ?? '');
/** Modelo padrao para agentes sem modelo configurado. 'auvvo-ai' vai pro DeepSeek. */
define('OPENROUTER_DEFAULT_MODEL', trim((string)($_ENV['OPENROUTER_DEFAULT_MODEL'] ?? 'openrouter/openai/gpt-4o-mini')));

/** Chave DeepSeek API — https://platform.deepseek.com/api_keys */
define('DEEPSEEK_API_KEY', $_ENV['DEEPSEEK_API_KEY'] ?? '');
/** Base URL da API DeepSeek (OpenAI-compatible) */
define('DEEPSEEK_BASE_URL', trim((string)($_ENV['DEEPSEEK_BASE_URL'] ?? 'https://api.deepseek.com/v1')));

/** Verifica se DeepSeek esta configurado (tem API key). */
function auvvo_deepseek_configured(): bool {
    return defined('DEEPSEEK_API_KEY') && DEEPSEEK_API_KEY !== '';
}

/** Detecta se o modelo e do DeepSeek. Retorna o ID real a enviar pra API (deepseek-chat, deepseek-reasoner). */
function auvvo_is_deepseek_model(string $model): string {
    $m = trim($model);
    if (str_starts_with($m, 'deepseek/')) {
        $name = substr($m, strlen('deepseek/'));
        if ($name === 'chat') return 'deepseek-chat';
        if ($name === 'reasoner') return 'deepseek-reasoner';
        return $name;
    }
    if ($m === 'deepseek-chat' || $m === 'deepseek-reasoner') {
        return $m;
    }
    return '';
}

/**
 * Chamada simples a API DeepSeek (usada para gerar prompts, nao para conversas).
 * Retorna o texto da resposta ou null em caso de falha.
 */
function auvvo_deepseek_simple_call(string $userMessage, float $temperature = 0.8, int $maxTokens = 2000): ?string {
    if (!auvvo_deepseek_configured()) return null;

    $url = DEEPSEEK_BASE_URL . '/chat/completions';
    $payload = [
        'model' => 'deepseek-chat',
        'messages' => [
            ['role' => 'user', 'content' => $userMessage],
        ],
        'max_tokens' => max(1, $maxTokens),
        'temperature' => max(0.0, min(2.0, $temperature)),
        'stream' => false,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . DEEPSEEK_API_KEY,
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || $response === false) {
        error_log('[Auvvo] DeepSeek simple call failed: HTTP ' . $httpCode);
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) return null;

    $content = $data['choices'][0]['message']['content'] ?? null;
    return is_string($content) ? trim($content) : null;
}

/** ID enviado à API OpenRouter (remove alias interno openrouter/ e auvvo-ai). */
function auvvo_openrouter_model_id(string $model): string
{
    $model = trim($model);
    if ($model === '' || $model === 'auvvo-ai') {
        $model = OPENROUTER_DEFAULT_MODEL;
    }
    if (str_starts_with($model, 'openrouter/')) {
        return substr($model, strlen('openrouter/'));
    }

    return $model;
}

// ==========================================================
// ABACATEPAY — Assinaturas (Brasil, API v2)
// https://docs.abacatepay.com/pages/reference/introduction
// ==========================================================
define('ABACATEPAY_API_KEY', trim((string)($_ENV['ABACATEPAY_API_KEY'] ?? '')));
/** IDs dos produtos com ciclo MONTHLY / ANNUALLY criados no painel AbacatePay */
define('ABACATEPAY_PRODUCT_MENSAL', trim((string)($_ENV['ABACATEPAY_PRODUCT_MENSAL'] ?? '')));
define('ABACATEPAY_PRODUCT_TRIMESTRAL', trim((string)($_ENV['ABACATEPAY_PRODUCT_TRIMESTRAL'] ?? '')));
define('ABACATEPAY_PRODUCT_ANUAL', trim((string)($_ENV['ABACATEPAY_PRODUCT_ANUAL'] ?? '')));
/** Mesmo valor configurado como ?webhookSecret= no endpoint cadastrado no dashboard */
define('ABACATEPAY_WEBHOOK_QUERY_SECRET', $_ENV['ABACATEPAY_WEBHOOK_QUERY_SECRET'] ?? '');
/**
 * Chave pública HMAC para header X-Webhook-Signature (doc webhooks/security).
 * Sobrescreva via .env se a AbacatePay publicar outro valor.
 */
define(
    'ABACATEPAY_WEBHOOK_HMAC_KEY',
    $_ENV['ABACATEPAY_WEBHOOK_HMAC_KEY'] ?? 't9dXRhHHo3yDEj5pVDYz0frf7q6bMKyMRmxxCPIPp3RCplBfXRxqlC6ZpiWmOqj4L63qEaeUOtrCI8P0VMUgo6iIga2ri9ogaHFs0WIIywSMg0q7RmBfybe1E5XJcfC4IW3alNqym0tXoAKkzvfEjZxV6bE0oG2zJrNNYmUCKZyV0KZ3JS8Votf9EAWWYdiDkMkpbMdPggfh1EqHlVkMiTady6jOR3hyzGEHrIz2Ret0xHKMbiqkr9HS1JhNHDX9'
);
/** Se true, o checkout mostra a mensagem de erro retornada pela API (use só para diagnosticar). */
define('ABACATEPAY_DEBUG', filter_var($_ENV['ABACATEPAY_DEBUG'] ?? '0', FILTER_VALIDATE_BOOLEAN));

// ==========================================================
// E-mail transacional — SMTP (Hostinger, etc.) ou mail()
// ==========================================================
define('MAIL_FROM_EMAIL', trim((string)($_ENV['MAIL_FROM_EMAIL'] ?? '')));
define('MAIL_FROM_NAME', trim((string)($_ENV['MAIL_FROM_NAME'] ?? 'Auvvo')));
define('SMTP_HOST', trim((string)($_ENV['SMTP_HOST'] ?? '')));
define('SMTP_PORT', (int)($_ENV['SMTP_PORT'] ?? 465));
define('SMTP_USER', trim((string)($_ENV['SMTP_USER'] ?? '')));
define('SMTP_PASS', (string)($_ENV['SMTP_PASS'] ?? ''));
/** ssl|smtps = porta 465 (SSL implícito); tls|starttls = porta 587 */
define('SMTP_ENCRYPTION', strtolower(trim((string)($_ENV['SMTP_ENCRYPTION'] ?? 'ssl'))));
/** E-mail exibido nos templates como suporte; padrão = MAIL_FROM_EMAIL */
define(
    'SUPPORT_EMAIL',
    trim((string)($_ENV['SUPPORT_EMAIL'] ?? '')) !== ''
        ? trim((string)($_ENV['SUPPORT_EMAIL'] ?? ''))
        : MAIL_FROM_EMAIL
);
// ==========================================================
// GOOGLE CALENDAR — OAuth da aplicação (admin .env)
// Uma app por servidor: todos os clientes usam estas chaves e autorizam a própria conta em «Conectar».
// ==========================================================
define('GOOGLE_OAUTH_CLIENT_ID',     $_ENV['GOOGLE_OAUTH_CLIENT_ID']     ?? '');
define('GOOGLE_OAUTH_CLIENT_SECRET', $_ENV['GOOGLE_OAUTH_CLIENT_SECRET'] ?? '');
define(
    'GOOGLE_OAUTH_REDIRECT_URI',
    $_ENV['GOOGLE_OAUTH_REDIRECT_URI'] ?? app_http_url('backend/google_calendar_callback.php')
);

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

global $pdo;
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // NUNCA expor mensagem de erro de banco em produção
    error_log('[Auvvo DB] Falha de conexão: ' . $e->getMessage());
    if (IS_DEV) {
        die('Erro de Conexão: ' . $e->getMessage());
    }
    http_response_code(503);
    die('Serviço temporariamente indisponível. Tente novamente em instantes.');
}

/**
 * Helper para verificar se a conexao PDO esta ativa.
 */
function auvvo_pdo_ping(PDO $pdo): bool {
    $q = @$pdo->query('SELECT 1');
    return $q !== false;
}

/**
 * Garante colunas de Google Calendar em `settings` (evita UPDATE falhar em silêncio em BD antigos).
 */
function auvvo_ensure_settings_calendar_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $q = $pdo->query('SELECT DATABASE()');
        $db = $q ? $q->fetchColumn() : '';
        if (!is_string($db) || $db === '') {
            return;
        }
        $chk = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $ensure = static function (string $col, string $ddl) use ($pdo, $chk, $db): void {
            $chk->execute([$db, 'settings', $col]);
            if ((int) $chk->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE settings ADD COLUMN `{$col}` {$ddl}");
            }
        };
        $ensure('google_calendar_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
        $ensure('google_calendar_calendar_id', "VARCHAR(191) NOT NULL DEFAULT 'primary'");
    } catch (Throwable $e) {
        error_log('[Auvvo] auvvo_ensure_settings_calendar_columns: ' . $e->getMessage());
    }
}

/**
 * Grava google_calendar_enabled = 1 (após OAuth ou botão rápido nas Configurações).
 */
function auvvo_settings_enable_gcal_scheduling(PDO $pdo, int $userId): void
{
    auvvo_ensure_settings_calendar_columns($pdo);
    $userId = (int) $userId;
    if ($userId <= 0) {
        return;
    }
    try {
        $stmt = $pdo->prepare('SELECT id FROM settings WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->prepare('UPDATE settings SET google_calendar_enabled = 1 WHERE user_id = ?')->execute([$userId]);

            return;
        }
        $pdo->prepare(
            'INSERT INTO settings (user_id, google_calendar_enabled, google_calendar_calendar_id) VALUES (?, 1, ?)'
        )->execute([$userId, 'primary']);
    } catch (Throwable $e) {
        error_log('[Auvvo] auvvo_settings_enable_gcal_scheduling: ' . $e->getMessage());
    }
}

/** Timestamp Unix seguro para date() — PHP 8.3+ não aceita float. */
function auvvo_unix_ts(int|float|string|null $timestamp = null): int
{
    if ($timestamp === null || $timestamp === '') {
        return time();
    }

    return (int) $timestamp;
}
?>
