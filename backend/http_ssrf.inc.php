<?php
declare(strict_types=1);

/**
 * Validação de URLs outbound (webhooks / presets HTTP) — mitiga SSRF.
 *
 * @return array{ok:bool, error?:string, url?:string}
 */
function auvvo_http_url_validate(string $url): array
{
    $url = trim($url);
    if ($url === '') {
        return ['ok' => false, 'error' => 'url_empty'];
    }
    if (strlen($url) > 2048) {
        return ['ok' => false, 'error' => 'url_too_long'];
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return ['ok' => false, 'error' => 'url_invalid'];
    }

    $scheme = strtolower((string) $parts['scheme']);
    if (!in_array($scheme, ['https', 'http'], true)) {
        return ['ok' => false, 'error' => 'scheme_not_allowed'];
    }
    if (!IS_DEV && $scheme !== 'https') {
        return ['ok' => false, 'error' => 'https_required'];
    }

    $host = strtolower((string) $parts['host']);
    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
        return ['ok' => false, 'error' => 'host_blocked'];
    }

    $blockedHosts = ['127.0.0.1', '0.0.0.0', '::1', 'metadata.google.internal'];
    if (in_array($host, $blockedHosts, true)) {
        return ['ok' => false, 'error' => 'host_blocked'];
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!auvvo_http_ip_is_public($host)) {
            return ['ok' => false, 'error' => 'private_ip'];
        }
    } else {
        $resolved = @gethostbyname($host);
        if ($resolved !== $host && $resolved !== '' && filter_var($resolved, FILTER_VALIDATE_IP)) {
            if (!auvvo_http_ip_is_public($resolved)) {
                return ['ok' => false, 'error' => 'private_ip'];
            }
        }
    }

    return ['ok' => true, 'url' => $url];
}

function auvvo_http_ip_is_public(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return false;
    }

    return true;
}
