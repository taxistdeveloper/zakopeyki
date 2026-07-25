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
        if ($driver === 'log') {
            return $this->log($to, $subject, $textBody, $htmlBody);
        }

        $ok = $this->sendViaMail($to, $subject, $textBody, $htmlBody);
        if (!empty($GLOBALS['appConfig']['debug'])) {
            $this->log($to, $subject, $textBody, $htmlBody, $ok ? 'also logged (debug)' : 'mail() failed — saved to log');
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

    private function sendViaMail(string $to, string $subject, string $textBody, ?string $htmlBody): bool
    {
        $fromAddress = (string) ($this->config['from_address'] ?? 'noreply@zakopeyki.kz');
        $fromName = (string) ($this->config['from_name'] ?? 'zakopeyki.kz');
        $encodedSubject = $this->encodeHeader($subject);
        $fromHeader = $this->encodeHeader($fromName) . ' <' . $fromAddress . '>';

        if ($htmlBody !== null && $htmlBody !== '') {
            $boundary = 'b_' . bin2hex(random_bytes(12));
            $headers = [
                'From: ' . $fromHeader,
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
                'Reply-To: ' . $fromAddress,
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                'X-Mailer: PHP/' . PHP_VERSION,
            ];
            $body = $textBody;
        }

        return (bool) @mail($to, $encodedSubject, $body, implode("\r\n", $headers));
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
