<?php
/**
 * includes/i18n.php
 * Sistema de internacionalização (i18n) — suporte a pt-BR, pt, es, en.
 *
 * Uso:  t('key')           → retorna string traduzida
 *        t('key', ['x'=>1]) → substitui {x} na string
 *        lang_html()        → atributo lang= para o <html>
 *        current_lang()     → código atual (ex: 'pt_BR')
 */

// ── Mapeamento slug → arquivo ───────────────────────────────────────────────
const SUPPORTED_LANGS = [
    'pt_br' => 'pt_BR',   // ?set_lang=pt_BR  (após strtolower)
    'pt_BR' => 'pt_BR',   // caso venha sem lowercase
    'pt-br' => 'pt_BR',
    'pt'    => 'pt_BR',
    'es'    => 'es',
    'en'    => 'en',
];

// ── Detectar e persistir idioma ──────────────────────────────────────────────
function _i18n_detect(): string {
    // 1. Troca explícita via URL: ?set_lang=en
    if (!empty($_GET['set_lang'])) {
        $slug = strtolower(trim($_GET['set_lang']));
        $code = SUPPORTED_LANGS[$slug] ?? null;
        if ($code) {
            $_SESSION['lang'] = $code;
            setcookie('Auvvo_lang', $code, time() + 60 * 60 * 24 * 365, '/', '', false, true);
        }
    }

    // 2. Sessão
    if (!empty($_SESSION['lang']) && in_array($_SESSION['lang'], array_values(SUPPORTED_LANGS))) {
        return $_SESSION['lang'];
    }

    // 3. Cookie
    if (!empty($_COOKIE['Auvvo_lang']) && in_array($_COOKIE['Auvvo_lang'], array_values(SUPPORTED_LANGS))) {
        $_SESSION['lang'] = $_COOKIE['Auvvo_lang'];
        return $_COOKIE['Auvvo_lang'];
    }

    // 4. Accept-Language do navegador
    $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    foreach (explode(',', $accept) as $part) {
        $tag = strtolower(trim(explode(';', $part)[0]));
        // Mapeia tags comuns
        $map = [
            'pt-br' => 'pt_BR', 'pt_br' => 'pt_BR', 'pt' => 'pt_BR', 'pt-pt' => 'pt_BR',
            'es'    => 'es', 'es-es' => 'es', 'es-mx' => 'es', 'es-ar' => 'es',
            'en'    => 'en', 'en-us' => 'en', 'en-gb' => 'en',
        ];
        if (isset($map[$tag])) {
            $_SESSION['lang'] = $map[$tag];
            return $map[$tag];
        }
    }

    // 5. Padrão
    $_SESSION['lang'] = 'pt_BR';
    return 'pt_BR';
}

// ── Carregar strings ─────────────────────────────────────────────────────────
$_i18n_lang    = _i18n_detect();
$_i18n_strings = [];

$_i18n_file = __DIR__ . '/../lang/' . $_i18n_lang . '.php';
if (file_exists($_i18n_file)) {
    $_i18n_strings = require $_i18n_file;
}

// Fallback para pt_BR se idioma pedido não tiver todos os strings
if ($_i18n_lang !== 'pt_BR') {
    $_i18n_fallback = require __DIR__ . '/../lang/pt_BR.php';
    $_i18n_strings  = array_merge($_i18n_fallback, $_i18n_strings);
}

// ── Funções públicas ─────────────────────────────────────────────────────────

/**
 * Retorna a string traduzida para a chave $key.
 * Suporta parâmetros: t('hello_name', ['name' => 'João']) → "Olá, João!"
 */
function t(string $key, array $params = []): string {
    global $_i18n_strings;
    $val = $_i18n_strings[$key] ?? $key;
    // Garante retorno string mesmo se a chave for um array por engano
    $str = is_array($val) ? $key : (string)$val;
    foreach ($params as $k => $v) {
        $str = str_replace('{' . $k . '}', htmlspecialchars((string)$v, ENT_QUOTES), $str);
    }
    return $str;
}

/**
 * Retorna o valor bruto da chave (pode ser array ou string).
 * Use para chaves que armazenam arrays, ex: ta('dash_days_map').
 */
function ta(string $key): mixed {
    global $_i18n_strings;
    return $_i18n_strings[$key] ?? [];
}

/**
 * Igual ao t(), mas faz echo direto.
 */
function _t(string $key, array $params = []): void {
    echo t($key, $params);
}

/** Código do idioma atual (ex: 'pt_BR', 'en', 'es', 'pt') */
function current_lang(): string {
    global $_i18n_lang;
    return $_i18n_lang;
}

/** Atributo lang= para o <html> (ex: 'pt-BR', 'en', 'es', 'pt') */
function lang_html(): string {
    $map = ['pt_BR' => 'pt-BR', 'pt' => 'pt', 'es' => 'es', 'en' => 'en'];
    return $map[current_lang()] ?? 'pt-BR';
}

/** Nome legível do idioma atual */
function lang_label(): string {
    $map = ['pt_BR' => 'PT-BR', 'pt' => 'PT', 'es' => 'ES', 'en' => 'EN'];
    return $map[current_lang()] ?? 'PT-BR';
}

/** Retorna URL atual com ?set_lang=X sem duplicar parâmetro */
function lang_url(string $lang): string {
    $params = $_GET;
    unset($params['set_lang']);
    $params['set_lang'] = $lang;
    $base = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    return $base . '?' . http_build_query($params);
}
