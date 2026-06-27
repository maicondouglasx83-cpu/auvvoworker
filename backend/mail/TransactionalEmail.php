<?php
/**
 * Monta subject + HTML a partir do catálogo e envia por SMTP ou mail().
 *
 * Uso:
 *   $msg = TransactionalEmail::build(EmailDefinitions::TEMPLATE_SUBSCRIPTION_WELCOME, [...]);
 *   TransactionalEmail::send($email, $msg['subject'], $msg['html']);
 *
 *   // ou
 *   TransactionalEmail::buildAndSend(EmailDefinitions::TEMPLATE_SUBSCRIPTION_WELCOME, [...], $email);
 */
declare(strict_types=1);

require_once __DIR__ . '/EmailDefinitions.php';
require_once __DIR__ . '/TemplateRenderer.php';
require_once __DIR__ . '/SmtpTransport.php';

final class TransactionalEmail
{
    private static function templatesDir(): string
    {
        return __DIR__ . '/templates';
    }

    /**
     * @param array<string, string|int|float> $data valores para {{chaves}}; extras são ignorados nas required
     * @return array{subject:string,html:string,template_id:string}|null
     */
    public static function build(string $templateId, array $data): ?array
    {
        $def = EmailDefinitions::get($templateId);
        if ($def === null) {
            return null;
        }

        $missing = TemplateRenderer::missingKeys($def['required'], $data);
        if ($missing !== []) {
            $msg = 'TransactionalEmail: faltam variáveis obrigatórias (' . implode(', ', $missing) . ') para ' . $templateId;
            error_log($msg);
            if (defined('IS_DEV') && IS_DEV) {
                throw new RuntimeException($msg);
            }
        }

        $path = self::templatesDir() . DIRECTORY_SEPARATOR . $def['file'];
        $defaults = [
            'app_name'        => 'Auvvo',
            'year'            => (string) date('Y'),
            'preheader'       => '',
            'email_title'     => 'Auvvo',
            'primary_color'   => '#333333',
            'secondary_text'  => '#6B7280',
            'view_online_url' => '#',
            'unsubscribe_url' => '#',
            'logo_url'        => 'https://eyvhxwk.stripocdn.email/content/guids/CABINET_2e223fbadec7f45abbed980ae25e8619229729f54c1a162f69330d6293113f9c/images/logopng.png',
            'hero_icon_url'   => 'https://eyvhxwk.stripocdn.email/content/guids/CABINET_2e223fbadec7f45abbed980ae25e8619229729f54c1a162f69330d6293113f9c/images/icone.png',
        ];
        $merged = array_merge($defaults, $data);

        $html = TemplateRenderer::renderFile($path, $merged);

        return [
            'template_id' => $templateId,
            'subject'     => $def['subject'],
            'html'        => $html,
        ];
    }

    /**
     * @param array<string, string|int|float> $data
     * @return array{ok:bool,error?:string}
     */
    public static function buildAndSend(string $templateId, array $data, string $toEmail): array
    {
        $built = self::build($templateId, $data);
        if ($built === null) {
            return ['ok' => false, 'error' => 'Template desconhecido: ' . $templateId];
        }
        return self::send($toEmail, $built['subject'], $built['html']);
    }

    /**
     * Envia HTML. Se SMTP_HOST + SMTP_USER + SMTP_PASS estiverem definidos no .env, usa SMTPS/STARTTLS.
     * Caso contrário, tenta mail() do PHP.
     *
     * @return array{ok:bool,error?:string}
     */
    public static function send(string $toEmail, string $subject, string $htmlBody): array
    {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Destinatário inválido.'];
        }

        $fromEmailRaw = (string) (defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : '');
        $smtpHost = (string) (defined('SMTP_HOST') ? SMTP_HOST : '');
        $smtpUser = (string) (defined('SMTP_USER') ? SMTP_USER : '');
        $smtpPass = (string) (defined('SMTP_PASS') ? SMTP_PASS : '');
        $useSmtp = $smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '';

        $fromEmail = $fromEmailRaw !== '' ? $fromEmailRaw : ($useSmtp ? $smtpUser : '');
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Defina MAIL_FROM_EMAIL ou SMTP_USER como e-mail válido do remetente.'];
        }

        $fromName = defined('MAIL_FROM_NAME') ? (string) MAIL_FROM_NAME : 'Auvvo';
        $fromHeader = self::formatFromHeader($fromName, $fromEmail);

        if ($useSmtp) {
            $port = defined('SMTP_PORT') ? (int) SMTP_PORT : 465;
            $enc =
                defined('SMTP_ENCRYPTION') && SMTP_ENCRYPTION !== ''
                    ? (string) SMTP_ENCRYPTION
                    : ($port === 587 ? 'tls' : 'ssl');
            $transport = new SmtpTransport();
            return $transport->send(
                $smtpHost,
                $port,
                $enc,
                $smtpUser,
                $smtpPass,
                $fromEmail,
                $fromHeader,
                $toEmail,
                $subject,
                $htmlBody
            );
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . $fromHeader,
        ];

        $ok = @mail($toEmail, self::encodeSubject($subject), $htmlBody, implode("\r\n", $headers));
        return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'mail() retornou false (configure SMTP no .env ou o sendmail do servidor).'];
    }

    private static function formatFromHeader(string $name, string $email): string
    {
        $name = trim($name);
        if ($name === '' || !preg_match('/[\x80-\xFF]|["\r\n]/', $name)) {
            return $name !== '' ? ($name . ' <' . $email . '>') : $email;
        }
        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
    }

    private static function encodeSubject(string $subject): string
    {
        if (preg_match('/[^\x20-\x7E]/', $subject)) {
            return '=?UTF-8?B?' . base64_encode($subject) . '?=';
        }
        return $subject;
    }
}
