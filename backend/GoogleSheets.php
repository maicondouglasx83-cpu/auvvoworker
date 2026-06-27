<?php
declare(strict_types=1);

/**
 * Google Sheets — OAuth e append de linhas (leads / eventos CRM).
 */
class GoogleSheets
{
    private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SHEETS_API = 'https://sheets.googleapis.com/v4';
    private const DRIVE_API  = 'https://www.googleapis.com/drive/v3';

    public static function resolveOAuthConfig(): array
    {
        $redirect = trim((string) ($_ENV['GOOGLE_SHEETS_REDIRECT_URI'] ?? ''));
        if ($redirect === '') {
            $redirect = app_http_url('backend/google_sheets_callback.php');
        }

        return [
            'client_id'     => defined('GOOGLE_OAUTH_CLIENT_ID') ? GOOGLE_OAUTH_CLIENT_ID : ($_ENV['GOOGLE_OAUTH_CLIENT_ID'] ?? ''),
            'client_secret' => defined('GOOGLE_OAUTH_CLIENT_SECRET') ? GOOGLE_OAUTH_CLIENT_SECRET : ($_ENV['GOOGLE_OAUTH_CLIENT_SECRET'] ?? ''),
            'redirect_uri'  => $redirect,
        ];
    }

    public static function isOAuthAppConfigured(): bool
    {
        $c = self::resolveOAuthConfig();

        return $c['client_id'] !== '' && $c['client_secret'] !== '' && $c['redirect_uri'] !== '';
    }

    public static function buildAuthUrl(string $state): string
    {
        $cfg = self::resolveOAuthConfig();
        $params = [
            'client_id'     => $cfg['client_id'],
            'redirect_uri'  => $cfg['redirect_uri'],
            'response_type' => 'code',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'scope'         => implode(' ', [
                'https://www.googleapis.com/auth/spreadsheets',
                'https://www.googleapis.com/auth/drive.readonly',
            ]),
            'state' => $state,
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    public static function exchangeCodeForToken(string $code): array
    {
        $cfg = self::resolveOAuthConfig();

        return self::httpForm(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri'  => $cfg['redirect_uri'],
            'grant_type'    => 'authorization_code',
        ]);
    }

    public static function refreshAccessToken(string $refreshToken): array
    {
        $cfg = self::resolveOAuthConfig();

        return self::httpForm(self::TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'grant_type'    => 'refresh_token',
        ]);
    }

    public static function loadToken(PDO $pdo, int $userId): ?array
    {
        try {
            $stmt = $pdo->prepare('SELECT * FROM google_sheets_tokens WHERE user_id = ? LIMIT 1');
            $stmt->execute([$userId]);

            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }

    public static function upsertToken(PDO $pdo, int $userId, array $token): void
    {
        $existing = self::loadToken($pdo, $userId);
        $access  = (string) ($token['access_token'] ?? ($existing['access_token'] ?? ''));
        $refresh = (string) ($token['refresh_token'] ?? ($existing['refresh_token'] ?? ''));
        if ($access === '') {
            throw new RuntimeException('Token Google Sheets inválido.');
        }
        $expiresAt = null;
        if (!empty($token['expires_in'])) {
            $expiresAt = date('Y-m-d H:i:s', time() + max(0, (int) $token['expires_in'] - 30));
        } elseif (!empty($existing['expires_at'])) {
            $expiresAt = $existing['expires_at'];
        }

        if ($existing) {
            $pdo->prepare(
                'UPDATE google_sheets_tokens SET access_token=?, refresh_token=?, token_type=?, scope=?, expires_at=? WHERE user_id=?'
            )->execute([
                $access,
                $refresh ?: null,
                (string) ($token['token_type'] ?? 'Bearer'),
                (string) ($token['scope'] ?? null),
                $expiresAt,
                $userId,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO google_sheets_tokens (user_id, access_token, refresh_token, token_type, scope, expires_at)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                $userId,
                $access,
                $refresh ?: null,
                (string) ($token['token_type'] ?? 'Bearer'),
                (string) ($token['scope'] ?? null),
                $expiresAt,
            ]);
        }
    }

    public static function deleteToken(PDO $pdo, int $userId): void
    {
        $pdo->prepare('DELETE FROM google_sheets_tokens WHERE user_id = ?')->execute([$userId]);
    }

    public static function ensureValidAccessToken(PDO $pdo, int $userId): array
    {
        $t = self::loadToken($pdo, $userId);
        if (!$t) {
            throw new RuntimeException('Google Sheets não conectado.');
        }
        $expired = !empty($t['expires_at']) && strtotime($t['expires_at']) <= time();
        if (!$expired) {
            return $t;
        }
        if (empty($t['refresh_token'])) {
            throw new RuntimeException('Reconecte o Google Sheets.');
        }
        $ref = self::refreshAccessToken($t['refresh_token']);
        if (!empty($ref['error'])) {
            throw new RuntimeException('Falha ao renovar token Google Sheets.');
        }
        self::upsertToken($pdo, $userId, $ref);

        return self::loadToken($pdo, $userId) ?: $t;
    }

    public static function saveSheetConfig(PDO $pdo, int $userId, string $spreadsheetId, string $sheetName): void
    {
        self::ensureValidAccessToken($pdo, $userId);
        $pdo->prepare('UPDATE google_sheets_tokens SET spreadsheet_id = ?, sheet_name = ? WHERE user_id = ?')
            ->execute([$spreadsheetId, $sheetName ?: 'Leads', $userId]);
        try {
            $pdo->prepare('UPDATE settings SET google_sheets_enabled = 1 WHERE user_id = ?')->execute([$userId]);
        } catch (PDOException $e) {
        }
    }

    /**
     * @param list<string|int|float|null> $values
     */
    public static function appendRow(PDO $pdo, int $userId, array $values, ?string $spreadsheetId = null, ?string $sheetName = null): array
    {
        $t = self::ensureValidAccessToken($pdo, $userId);
        $sid = $spreadsheetId ?: (string) ($t['spreadsheet_id'] ?? '');
        $sheet = $sheetName ?: (string) ($t['sheet_name'] ?? 'Leads');
        if ($sid === '') {
            throw new RuntimeException('Configure o ID da planilha em Integrações → Google Sheets.');
        }

        $range = rawurlencode($sheet) . '!A:Z';
        $url = self::SHEETS_API . '/spreadsheets/' . rawurlencode($sid) . '/values/' . $range . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';

        $row = array_map(static fn($v) => $v === null ? '' : (string) $v, $values);

        return self::httpJson(
            'POST',
            $url,
            ['values' => [$row]],
            (string) $t['access_token']
        );
    }

    public static function listSpreadsheets(PDO $pdo, int $userId, int $limit = 20): array
    {
        $t = self::ensureValidAccessToken($pdo, $userId);
        $q = rawurlencode("mimeType='application/vnd.google-apps.spreadsheet'");
        $url = self::DRIVE_API . "/files?q={$q}&pageSize={$limit}&fields=files(id,name)&orderBy=modifiedTime desc";

        $res = self::httpJson('GET', $url, null, (string) $t['access_token']);

        return $res['files'] ?? [];
    }

    private static function httpForm(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $body, true);

        return is_array($data) ? $data : ['error' => 'invalid_response'];
    }

    private static function httpJson(string $method, string $url, ?array $body, string $accessToken): array
    {
        $ch = curl_init($url);
        $headers = ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);
        if ($body !== null && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $data = ['raw' => substr((string) $raw, 0, 500)];
        }
        $data['_http_code'] = $code;

        return $data;
    }
}
