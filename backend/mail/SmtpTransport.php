<?php
/**
 * Envio SMTP mínimo: SMTPS (SSL implícito, porta 465) ou STARTTLS (porta 587).
 * Sem dependências externas.
 */
declare(strict_types=1);

final class SmtpTransport
{
    /**
     * @return array{ok:bool,error?:string}
     */
    public function send(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        string $fromEmail,
        string $fromHeader,
        string $toEmail,
        string $subject,
        string $htmlBody
    ): array {
        $enc = strtolower(trim($encryption));
        if ($enc === '' || $enc === 'smtps' || $enc === 'ssl') {
            return $this->sendSmtps($host, $port, $username, $password, $fromEmail, $fromHeader, $toEmail, $subject, $htmlBody);
        }
        if ($enc === 'tls' || $enc === 'starttls') {
            return $this->sendStartTls($host, $port, $username, $password, $fromEmail, $fromHeader, $toEmail, $subject, $htmlBody);
        }
        return ['ok' => false, 'error' => 'SMTP_ENCRYPTION inválido (use ssl, smtps, ou tls).'];
    }

    private function sendSmtps(
        string $host,
        int $port,
        string $username,
        string $password,
        string $fromEmail,
        string $fromHeader,
        string $toEmail,
        string $subject,
        string $htmlBody
    ): array {
        $remote = 'ssl://' . $host . ':' . $port;
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'allow_self_signed'=> false,
            ],
        ]);
        $err = '';
        $errno = 0;
        $sock = @stream_socket_client(
            $remote,
            $errno,
            $err,
            30,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if ($sock === false) {
            return ['ok' => false, 'error' => 'Falha ao conectar SMTPS: ' . $err . ' (' . $errno . ')'];
        }
        stream_set_timeout($sock, 30);

        try {
            $r = $this->expect($sock, [220]);
            if ($r !== null) {
                return $r;
            }
            $e = $this->smtpTransaction($sock, $username, $password, $fromEmail, $fromHeader, $toEmail, $subject, $htmlBody, false);
            if ($e !== null) {
                return $e;
            }
        } finally {
            $this->closeSmtp($sock);
        }

        return ['ok' => true];
    }

    private function sendStartTls(
        string $host,
        int $port,
        string $username,
        string $password,
        string $fromEmail,
        string $fromHeader,
        string $toEmail,
        string $subject,
        string $htmlBody
    ): array {
        $remote = 'tcp://' . $host . ':' . $port;
        $sock = @stream_socket_client($remote, $errno, $err, 30, STREAM_CLIENT_CONNECT);
        if ($sock === false) {
            return ['ok' => false, 'error' => 'Falha ao conectar SMTP: ' . $err . ' (' . $errno . ')'];
        }
        stream_set_timeout($sock, 30);

        try {
            $r = $this->expect($sock, [220]);
            if ($r !== null) {
                return $r;
            }
            $this->write($sock, 'EHLO ' . $this->ehloHost() . "\r\n");
            $r = $this->expect($sock, [250]);
            if ($r !== null) {
                return $r;
            }
            $this->write($sock, "STARTTLS\r\n");
            $r = $this->expect($sock, [220]);
            if ($r !== null) {
                return $r;
            }
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (!@stream_socket_enable_crypto($sock, true, $crypto)) {
                return ['ok' => false, 'error' => 'Falha ao negociar STARTTLS.'];
            }
            $this->write($sock, 'EHLO ' . $this->ehloHost() . "\r\n");
            $r = $this->expect($sock, [250]);
            if ($r !== null) {
                return $r;
            }
            $e = $this->smtpTransaction($sock, $username, $password, $fromEmail, $fromHeader, $toEmail, $subject, $htmlBody, true);
            if ($e !== null) {
                return $e;
            }
        } finally {
            $this->closeSmtp($sock);
        }

        return ['ok' => true];
    }

    /** @param resource $sock */
    private function closeSmtp($sock): void
    {
        if (!is_resource($sock)) {
            return;
        }
        @fwrite($sock, "QUIT\r\n");
        @stream_get_contents($sock);
        fclose($sock);
    }

    /**
     * @param resource $sock
     * @return array{ok:false,error:string}|null
     */
    private function smtpTransaction(
        $sock,
        string $username,
        string $password,
        string $fromEmail,
        string $fromHeader,
        string $toEmail,
        string $subject,
        string $htmlBody,
        bool $ehloDone
    ): ?array {
        if (!$ehloDone) {
            $this->write($sock, 'EHLO ' . $this->ehloHost() . "\r\n");
            $r = $this->expect($sock, [250]);
            if ($r !== null) {
                return $r;
            }
        }

        $this->write($sock, "AUTH LOGIN\r\n");
        $r = $this->expect($sock, [334]);
        if ($r !== null) {
            return $r;
        }
        $this->write($sock, base64_encode($username) . "\r\n");
        $r = $this->expect($sock, [334]);
        if ($r !== null) {
            return $r;
        }
        $this->write($sock, base64_encode($password) . "\r\n");
        $r = $this->expect($sock, [235]);
        if ($r !== null) {
            return $r;
        }

        $this->write($sock, 'MAIL FROM:<' . $this->sanitizeAddr($fromEmail) . ">\r\n");
        $r = $this->expect($sock, [250]);
        if ($r !== null) {
            return $r;
        }

        $this->write($sock, 'RCPT TO:<' . $this->sanitizeAddr($toEmail) . ">\r\n");
        $r = $this->expect($sock, [250, 251]);
        if ($r !== null) {
            return $r;
        }

        $this->write($sock, "DATA\r\n");
        $r = $this->expect($sock, [354]);
        if ($r !== null) {
            return $r;
        }

        $subjectHdr = $this->encodeSubjectHeader($subject);
        $b64 = chunk_split(base64_encode($htmlBody), 76, "\r\n");
        $data =
            "MIME-Version: 1.0\r\n"
            . 'From: ' . $fromHeader . "\r\n"
            . 'To: <' . $this->sanitizeAddr($toEmail) . ">\r\n"
            . 'Subject: ' . $subjectHdr . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "\r\n"
            . rtrim($b64, "\r\n") . "\r\n"
            . ".\r\n";

        $this->write($sock, $data);
        $r = $this->expect($sock, [250]);
        if ($r !== null) {
            return $r;
        }

        return null;
    }

    private function ehloHost(): string
    {
        $h = $_SERVER['SERVER_NAME'] ?? 'localhost';
        return preg_match('/^[a-zA-Z0-9.-]+$/', $h) ? $h : 'localhost';
    }

    private function sanitizeAddr(string $email): string
    {
        return trim($email, '<> ');
    }

    private function encodeSubjectHeader(string $subject): string
    {
        if (!preg_match('/[^\x20-\x7E]/', $subject)) {
            return $subject;
        }
        return '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }

    /**
     * @param resource $sock
     */
    private function write($sock, string $data): void
    {
        $len = strlen($data);
        $off = 0;
        while ($off < $len) {
            $n = @fwrite($sock, substr($data, $off));
            if ($n === false || $n === 0) {
                break;
            }
            $off += $n;
        }
    }

    /**
     * @param resource $sock
     * @param int[] $okCodes
     * @return array{ok:false,error:string}|null
     */
    private function expect($sock, array $okCodes): ?array
    {
        $lines = $this->readMultiline($sock);
        if ($lines === []) {
            return ['ok' => false, 'error' => 'Servidor SMTP fechou a conexão ou não respondeu.'];
        }
        $last = $lines[count($lines) - 1];
        $code = (int) substr($last, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            $msg = trim(implode(' ', $lines));
            return ['ok' => false, 'error' => 'SMTP ' . $code . ': ' . $msg];
        }
        return null;
    }

    /**
     * @param resource $sock
     * @return string[]
     */
    private function readMultiline($sock): array
    {
        $lines = [];
        while (!feof($sock)) {
            $line = fgets($sock, 8192);
            if ($line === false) {
                break;
            }
            $lines[] = rtrim($line, "\r\n");
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        return $lines;
    }
}
