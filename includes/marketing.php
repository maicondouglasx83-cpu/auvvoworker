<?php
/**
 * Configuração pública de marketing: WhatsApp, pixels, URL base.
 * Variáveis opcionais no .env:
 *   APP_BASE_URL=https://seu-dominio.com
 *   PUBLIC_SUPPORT_WHATSAPP=5511999999999  (somente dígitos, DDI 55)
 *   GTM_ID=GTM-XXXXXXX
 *   META_PIXEL_ID=123456789012345
 */

declare(strict_types=1);

function mkt_load_dotenv(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    if (!is_readable($file)) {
        return;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim(trim($val), "\"'");
        if ($key !== '') {
            $_ENV[$key] = $val;
        }
    }
}

function mkt_env(string $key, string $default = ''): string
{
    mkt_load_dotenv();
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === false || $v === null) {
        return $default;
    }
    return trim((string) $v);
}

function mkt_base_url(): string
{
    $from_env = rtrim(mkt_env('APP_BASE_URL'), '/');
    if ($from_env !== '') {
        return $from_env;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '' || $dir === '.' || $dir === '/') {
        $path = '';
    } else {
        $path = $dir;
    }
    return $scheme . '://' . $host . $path;
}

function mkt_whatsapp_digits(): string
{
    return preg_replace('/\D/', '', mkt_env('PUBLIC_SUPPORT_WHATSAPP', '')) ?? '';
}

function mkt_whatsapp_href(): string
{
    $d = mkt_whatsapp_digits();
    return $d !== '' ? 'https://wa.me/' . $d : '';
}

/** Rótulo amigável para o rodapé; vazio se não houver número. */
function mkt_whatsapp_footer_label(): string
{
    $d = mkt_whatsapp_digits();
    if ($d === '' || strlen($d) < 10) {
        return '';
    }
    // 5511999999999 -> exibição aproximada
    $local = $d;
    if (str_starts_with($d, '55') && strlen($d) >= 12) {
        $local = substr($d, 2);
    }
    if (strlen($local) === 11) {
        return sprintf('(%s) %s-%s', substr($local, 0, 2), substr($local, 2, 5), substr($local, 7, 4));
    }
    if (strlen($local) === 10) {
        return sprintf('(%s) %s-%s', substr($local, 0, 2), substr($local, 2, 4), substr($local, 6, 4));
    }
    return 'WhatsApp';
}

function mkt_og_image_url(): string
{
    return mkt_base_url() . '/og-auvvo.png';
}

function mkt_render_tracking_head(): void
{
    $gtm = mkt_env('GTM_ID');
    if ($gtm !== '') {
        $gtm_esc = htmlspecialchars($gtm, ENT_QUOTES, 'UTF-8');
        echo '<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({\'gtm.start\':new Date().getTime(),event:\'gtm.js\'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!=\'dataLayer\'?\'&l=\'+l:\'\';j.async=true;j.src=\'https://www.googletagmanager.com/gtm.js?id=\'+i+dl;f.parentNode.insertBefore(j,f);})(window,document,\'script\',\'dataLayer\',\'' . $gtm_esc . '\');</script>' . "\n";
    }
    $pixel = mkt_env('META_PIXEL_ID');
    if ($pixel !== '') {
        $p = htmlspecialchars($pixel, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '{$p}');
fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id={$p}&ev=PageView&noscript=1" alt="" /></noscript>

HTML;
    }
}

function mkt_render_tracking_body_open(): void
{
    $gtm = mkt_env('GTM_ID');
    if ($gtm === '') {
        return;
    }
    $id = htmlspecialchars($gtm, ENT_QUOTES, 'UTF-8');
    echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . $id . '" height="0" width="0" style="display:none;visibility:hidden" title="Google Tag Manager"></iframe></noscript>' . "\n";
}

function mkt_render_floating_whatsapp(): void
{
    $href = mkt_whatsapp_href();
    if ($href === '') {
        return;
    }
    $h = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
    echo '<a class="auvvo-wa-float" href="' . $h . '" target="_blank" rel="noopener noreferrer" aria-label="Falar com a Auvvo no WhatsApp"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i> WhatsApp</a>' . "\n";
}

function mkt_support_email(): string
{
    $e = mkt_env('SUPPORT_EMAIL');
    return $e !== '' ? $e : 'contato@Auvvo.com';
}
