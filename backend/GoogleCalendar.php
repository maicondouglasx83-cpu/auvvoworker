<?php
// backend/GoogleCalendar.php

class GoogleCalendar
{
    private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE  = 'https://www.googleapis.com/calendar/v3';

    /**
     * Credenciais OAuth da aplicação (uma só por deploy) — .env / db.php.
     * Cada cliente autoriza a própria conta Google; tokens ficam em google_calendar_tokens.
     *
     * @return array{client_id: string, client_secret: string, redirect_uri: string}
     */
    public static function resolveOAuthConfig(): array
    {
        return [
            'client_id'     => GOOGLE_OAUTH_CLIENT_ID,
            'client_secret' => GOOGLE_OAUTH_CLIENT_SECRET,
            'redirect_uri'  => GOOGLE_OAUTH_REDIRECT_URI,
        ];
    }

    /** App OAuth registada (admin .env). Não depende do user_id. */
    public static function isOAuthAppConfigured(): bool
    {
        $c = self::resolveOAuthConfig();

        return $c['client_id'] !== '' && $c['client_secret'] !== '' && $c['redirect_uri'] !== '';
    }

    /**
     * @deprecated Mantido para assinatura antiga; equival a isOAuthAppConfigured().
     */
    public static function isConfigured(PDO $pdo, int $userId): bool
    {
        return self::isOAuthAppConfigured();
    }

    public static function buildAuthUrl(string $state): string
    {
        $cfg    = self::resolveOAuthConfig();
        $params = [
            'client_id'              => $cfg['client_id'],
            'redirect_uri'           => $cfg['redirect_uri'],
            'response_type'          => 'code',
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'scope'                  => implode(' ', [
                'https://www.googleapis.com/auth/calendar.events',
                'https://www.googleapis.com/auth/calendar.readonly',
            ]),
            'state'                  => $state,
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    public static function exchangeCodeForToken(string $code): array
    {
        $cfg     = self::resolveOAuthConfig();
        $payload = [
            'code'          => $code,
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri'  => $cfg['redirect_uri'],
            'grant_type'    => 'authorization_code',
        ];

        return self::httpForm(self::TOKEN_URL, $payload);
    }

    public static function refreshAccessToken(string $refreshToken): array
    {
        $cfg     = self::resolveOAuthConfig();
        $payload = [
            'refresh_token' => $refreshToken,
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'grant_type'    => 'refresh_token',
        ];

        return self::httpForm(self::TOKEN_URL, $payload);
    }

    public static function loadToken(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM google_calendar_tokens WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function upsertToken(PDO $pdo, int $userId, array $token, ?string $calendarId = null): void
    {
        $existing     = self::loadToken($pdo, $userId);
        $accessToken  = (string) ($token['access_token'] ?? ($existing['access_token'] ?? ''));
        $refreshToken = (string) ($token['refresh_token'] ?? ($existing['refresh_token'] ?? ''));
        $tokenType    = (string) ($token['token_type'] ?? ($existing['token_type'] ?? 'Bearer'));
        $scope        = (string) ($token['scope'] ?? ($existing['scope'] ?? ''));
        $expiresIn    = isset($token['expires_in']) ? (int) $token['expires_in'] : null;

        if ($accessToken === '') {
            throw new RuntimeException('Google token inválido (sem access_token).');
        }

        $expiresAt = null;
        if ($expiresIn !== null && $expiresIn > 0) {
            $expiresAt = date('Y-m-d H:i:s', time() + max(0, $expiresIn - 30));
        } elseif (!empty($existing['expires_at'])) {
            $expiresAt = $existing['expires_at'];
        }

        $calendarId = $calendarId ?: ($existing['calendar_id'] ?? 'primary');

        if ($existing) {
            $pdo->prepare(
                'UPDATE google_calendar_tokens
                 SET calendar_id=?, access_token=?, refresh_token=?, token_type=?, scope=?, expires_at=?
                 WHERE user_id=?'
            )->execute([$calendarId, $accessToken, $refreshToken ?: null, $tokenType, $scope ?: null, $expiresAt, $userId]);
        } else {
            $pdo->prepare(
                'INSERT INTO google_calendar_tokens (user_id, calendar_id, access_token, refresh_token, token_type, scope, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$userId, $calendarId, $accessToken, $refreshToken ?: null, $tokenType, $scope ?: null, $expiresAt]);
        }
    }

    public static function deleteToken(PDO $pdo, int $userId): void
    {
        $pdo->prepare('DELETE FROM google_calendar_tokens WHERE user_id=?')->execute([$userId]);
    }

    public static function ensureValidAccessToken(PDO $pdo, int $userId): array
    {
        $t = self::loadToken($pdo, $userId);
        if (!$t) {
            throw new RuntimeException('Google Calendar não conectado.');
        }

        $isExpired = false;
        if (!empty($t['expires_at'])) {
            $isExpired = strtotime($t['expires_at']) <= time();
        }

        if (!$isExpired) {
            return $t;
        }

        if (empty($t['refresh_token'])) {
            throw new RuntimeException('Token expirado e sem refresh_token. Reconecte o Google Calendar.');
        }

        $ref = self::refreshAccessToken($t['refresh_token']);
        if (!empty($ref['error'])) {
            throw new RuntimeException('Falha ao renovar token do Google: ' . (is_string($ref['error']) ? $ref['error'] : 'erro'));
        }

        self::upsertToken($pdo, $userId, $ref, $t['calendar_id'] ?? 'primary');
        $t2 = self::loadToken($pdo, $userId);
        if (!$t2) {
            throw new RuntimeException('Falha ao atualizar token do Google.');
        }

        return $t2;
    }

    /**
     * Calendário alvo por utilizador: settings.google_calendar_calendar_id;
     * se vazio, usa o gravado em google_calendar_tokens; senão "primary".
     */
    public static function getEffectiveCalendarId(PDO $pdo, int $userId): string
    {
        $stmt = $pdo->prepare('SELECT google_calendar_calendar_id FROM settings WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row          = $stmt->fetch(PDO::FETCH_ASSOC);
        $fromSettings = trim((string) ($row['google_calendar_calendar_id'] ?? ''));

        $t         = self::loadToken($pdo, $userId);
        $fromToken = $t ? trim((string) ($t['calendar_id'] ?? '')) : '';

        if ($fromSettings !== '') {
            return $fromSettings;
        }
        if ($fromToken !== '') {
            return $fromToken;
        }

        return 'primary';
    }

    /**
     * Alinha google_calendar_tokens.calendar_id com o ID efetivo (para a API usar o mesmo calendário por user_id).
     *
     * @return string o calendar_id usado nas próximas chamadas à API
     */
    public static function syncEffectiveCalendarIdToToken(PDO $pdo, int $userId): string
    {
        $effective = self::getEffectiveCalendarId($pdo, $userId);
        $t         = self::loadToken($pdo, $userId);
        if ($t && trim((string) ($t['calendar_id'] ?? '')) !== $effective) {
            $pdo->prepare('UPDATE google_calendar_tokens SET calendar_id = ? WHERE user_id = ?')->execute([$effective, $userId]);
        }

        return $effective;
    }

    /** @deprecated usar syncEffectiveCalendarIdToToken */
    public static function syncCalendarIdFromSettings(PDO $pdo, int $userId): void
    {
        self::syncEffectiveCalendarIdToToken($pdo, $userId);
    }

    /**
     * ID determinístico para events.insert (idempotência em retries).
     * A API exige base32hex: apenas 0-9 e a-v minúsculas; comprimento 5–1024.
     * Prefixos tipo "Auvvo_" falham com Invalid resource id value.
     *
     * @see https://developers.google.com/calendar/api/v3/reference/events
     */
    public static function deterministicEventId(int $agentId, string $contactJid, string $start, string $end, string $summary): string
    {
        $raw = $agentId . '|' . $contactJid . '|' . $start . '|' . $end . '|' . $summary;
        // Hex SHA-256 ⊆ base32hex (0-9 e a-f ⊂ a-v)
        return substr(hash('sha256', $raw), 0, 48);
    }

    public static function createEvent(PDO $pdo, int $userId, array $event): array
    {
        $calendarId = trim(self::syncEffectiveCalendarIdToToken($pdo, $userId));
        if ($calendarId === '') {
            $calendarId = 'primary';
        }
        $t = self::ensureValidAccessToken($pdo, $userId);

        $path = '/calendars/' . rawurlencode($calendarId) . '/events';

        return self::apiJson('POST', $path, $t['access_token'], $event);
    }

    private static function apiJson(string $method, string $path, string $accessToken, ?array $body = null): array
    {
        $url     = self::API_BASE . $path;
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ];

        $payload = null;
        if ($body !== null) {
            $payload   = json_encode($body, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw    = curl_exec($ch);
        $err    = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['error' => true, 'message' => $err ?: 'Falha HTTP', 'status' => 0];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = ['raw' => $raw];
        }

        if ($status < 200 || $status >= 300) {
            return [
                'error'   => true,
                'status'  => $status,
                'message' => $data['error']['message'] ?? $data['error_description'] ?? 'Erro Google Calendar',
                'raw'     => $data,
            ];
        }

        return $data;
    }

    private static function httpForm(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);

        $raw    = curl_exec($ch);
        $err    = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['error' => true, 'message' => $err ?: 'Falha HTTP', 'status' => 0];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = ['raw' => $raw];
        }

        if ($status < 200 || $status >= 300) {
            return [
                'error'               => true,
                'status'              => $status,
                'error'               => $data['error'] ?? 'http_error',
                'error_description'   => $data['error_description'] ?? ($data['message'] ?? 'Erro OAuth'),
                'raw'                 => $data,
            ];
        }

        return $data;
    }
}
