<?php

namespace App\Helpers;

class Mail
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (require dirname(__DIR__, 2) . '/config/mail.php');
    }

    public function send(string $to, string $subject, string $textBody, ?string $htmlBody = null): bool
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $driver = strtolower((string) ($this->config['driver'] ?? 'mail'));
        $ok = false;

        if ($driver === 'log') {
            $ok = $this->log($to, $subject, $textBody, $htmlBody);
        } elseif ($driver === 'smtp') {
            $ok = $this->sendViaSmtp($to, $subject, $textBody, $htmlBody);
            if (!$ok) {
                $this->log($to, $subject, $textBody, $htmlBody, 'smtp failed — saved to log');
            }
        } else {
            $ok = $this->sendViaMail($to, $subject, $textBody, $htmlBody);
            if (!$ok || !empty($GLOBALS['appConfig']['debug'])) {
                $this->log(
                    $to,
                    $subject,
                    $textBody,
                    $htmlBody,
                    $ok ? 'also logged (debug)' : 'mail() failed — saved to log'
                );
            }
        }

        return $ok;
    }

    public function absoluteUrl(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        $scheme = $https ? 'https' : 'http';
        $host = $this->trustedHost();

        if (in_array($host, ['localhost', '127.0.0.1'], true) && $scheme === 'https') {
            return 'https://zakopeyki.kz' . ProductHelper::url($path);
        }

        return $scheme . '://' . $host . ProductHelper::url($path);
    }

    private function buildMessage(string $to, string $subject, string $textBody, ?string $htmlBody): array
    {
        $fromAddress = (string) ($this->config['from_address'] ?? 'noreply@zakopeyki.kz');
        $fromName = (string) ($this->config['from_name'] ?? 'zakopeyki.kz');
        $encodedSubject = $this->encodeHeader($subject);
        $fromHeader = $this->encodeHeader($fromName) . ' <' . $fromAddress . '>';

        if ($htmlBody !== null && $htmlBody !== '') {
            $boundary = 'b_' . bin2hex(random_bytes(12));
            $headers = [
                'From: ' . $fromHeader,
                'To: ' . $to,
                'Subject: ' . $encodedSubject,
                'Reply-To: ' . $fromAddress,
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
                'X-Mailer: PHP/' . PHP_VERSION,
            ];
            $body = "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $textBody . "\r\n\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $htmlBody . "\r\n\r\n"
                . "--{$boundary}--\r\n";
        } else {
            $headers = [
                'From: ' . $fromHeader,
                'To: ' . $to,
                'Subject: ' . $encodedSubject,
                'Reply-To: ' . $fromAddress,
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                'X-Mailer: PHP/' . PHP_VERSION,
            ];
            $body = $textBody;
        }

        return [
            'from' => $fromAddress,
            'subject' => $encodedSubject,
            'headers' => $headers,
            'body' => $body,
            'raw' => implode("\r\n", $headers) . "\r\n\r\n" . $body,
        ];
    }

    private function sendViaMail(string $to, string $subject, string $textBody, ?string $htmlBody): bool
    {
        $msg = $this->buildMessage($to, $subject, $textBody, $htmlBody);
        // mail() сам ставит To/Subject — убираем их из заголовков
        $headers = array_values(array_filter(
            $msg['headers'],
            static fn (string $h): bool => !preg_match('/^(To|Subject):/i', $h)
        ));

        return (bool) @mail($to, $msg['subject'], $msg['body'], implode("\r\n", $headers));
    }

    private function sendViaSmtp(string $to, string $subject, string $textBody, ?string $htmlBody): bool
    {
        $smtp = $this->config['smtp'] ?? [];
        if (!is_array($smtp)) {
            return false;
        }

        $host = trim((string) ($smtp['host'] ?? ''));
        $port = (int) ($smtp['port'] ?? 587);
        $encryption = strtolower((string) ($smtp['encryption'] ?? 'tls'));
        $username = trim((string) ($smtp['username'] ?? ''));
        $password = (string) ($smtp['password'] ?? '');
        $timeout = max(5, (int) ($smtp['timeout'] ?? 20));

        if ($host === '' || $username === '' || $password === '') {
            return false;
        }

        $msg = $this->buildMessage($to, $subject, $textBody, $htmlBody);
        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            return false;
        }

        stream_set_timeout($fp, $timeout);

        try {
            if (!$this->smtpExpect($fp, [220])) {
                return false;
            }
            $ehloHost = 'zakopeyki.kz';
            if (!$this->smtpCommand($fp, 'EHLO ' . $ehloHost, [250])) {
                return false;
            }

            if ($encryption === 'tls') {
                if (!$this->smtpCommand($fp, 'STARTTLS', [220])) {
                    return false;
                }
                $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
                    return false;
                }
                if (!$this->smtpCommand($fp, 'EHLO ' . $ehloHost, [250])) {
                    return false;
                }
            }

            if (!$this->smtpCommand($fp, 'AUTH LOGIN', [334])) {
                return false;
            }
            if (!$this->smtpCommand($fp, base64_encode($username), [334])) {
                return false;
            }
            if (!$this->smtpCommand($fp, base64_encode($password), [235])) {
                return false;
            }

            if (!$this->smtpCommand($fp, 'MAIL FROM:<' . $msg['from'] . '>', [250])) {
                return false;
            }
            if (!$this->smtpCommand($fp, 'RCPT TO:<' . $to . '>', [250, 251])) {
                return false;
            }
            if (!$this->smtpCommand($fp, 'DATA', [354])) {
                return false;
            }

            $data = preg_replace('/^\./m', '..', $msg['raw']) . "\r\n.";
            if (!$this->smtpCommand($fp, $data, [250])) {
                return false;
            }

            $this->smtpCommand($fp, 'QUIT', [221]);
            return true;
        } finally {
            fclose($fp);
        }
    }

    /** @param resource $fp @param list<int> $okCodes */
    private function smtpCommand($fp, string $command, array $okCodes): bool
    {
        fwrite($fp, $command . "\r\n");
        return $this->smtpExpect($fp, $okCodes);
    }

    /** @param resource $fp @param list<int> $okCodes */
    private function smtpExpect($fp, array $okCodes): bool
    {
        $response = '';
        while (($line = fgets($fp, 515)) !== false) {
            $response .= $line;
            // Многострочный ответ SMTP: "250-..." продолжается, "250 " — конец
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
            if (strlen($line) < 4) {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        return in_array($code, $okCodes, true);
    }

    private function log(string $to, string $subject, string $textBody, ?string $htmlBody, string $note = ''): bool
    {
        $root = dirname(__DIR__, 2);
        $dir = $root . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/mail.log';
        $entry = str_repeat('=', 60) . "\n"
            . date('c') . ($note !== '' ? " [{$note}]" : '') . "\n"
            . "To: {$to}\n"
            . "Subject: {$subject}\n\n"
            . $textBody . "\n";
        if ($htmlBody) {
            $entry .= "\n--- HTML ---\n" . $htmlBody . "\n";
        }
        $entry .= "\n";

        return @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX) !== false;
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function trustedHost(): string
    {
        $allowed = $this->config['allowed_hosts'] ?? ['zakopeyki.kz', 'localhost', '127.0.0.1'];
        if (!is_array($allowed) || $allowed === []) {
            $allowed = ['zakopeyki.kz', 'localhost', '127.0.0.1'];
        }

        $candidates = array_filter([
            $_SERVER['HTTP_X_FORWARDED_HOST'] ?? null,
            $_SERVER['HTTP_HOST'] ?? null,
            $_SERVER['SERVER_NAME'] ?? null,
        ]);

        foreach ($candidates as $candidate) {
            $candidate = trim(explode(',', (string) $candidate)[0]);
            $host = strtolower(trim(preg_replace('/:\d+$/', '', $candidate)));
            if ($host !== '' && in_array($host, $allowed, true)) {
                return $host;
            }
        }

        return 'zakopeyki.kz';
    }
}
