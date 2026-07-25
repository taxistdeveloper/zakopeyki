<?php

namespace App\Helpers;

class GoogleOAuth
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (require dirname(__DIR__, 2) . '/config/google.php');
    }

    public function isConfigured(): bool
    {
        return ($this->config['client_id'] ?? '') !== ''
            && ($this->config['client_secret'] ?? '') !== '';
    }

    public function redirectUri(): string
    {
        $custom = trim((string) ($this->config['redirect_uri'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        $scheme = $https ? 'https' : 'http';
        $host = $this->trustedHost();

        // На проде за прокси SERVER_NAME часто localhost — не отдаём его в Google.
        if (in_array($host, ['localhost', '127.0.0.1'], true) && $scheme === 'https') {
            return 'https://zakopeyki.kz' . ProductHelper::url('/login');
        }

        return $scheme . '://' . $host . ProductHelper::url('/login');
    }

    /** Host only from allowlist / SERVER_NAME — not raw Host header. */
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
            // X-Forwarded-Host может быть списком: "a.com, b.com"
            $candidate = trim(explode(',', (string) $candidate)[0]);
            $host = strtolower(trim(preg_replace('/:\d+$/', '', $candidate)));
            if ($host === '') {
                continue;
            }
            foreach ($allowed as $pattern) {
                $pattern = strtolower(trim((string) $pattern));
                if ($pattern !== '' && ($host === $pattern || str_ends_with($host, '.' . $pattern))) {
                    return $host;
                }
            }
        }

        return 'zakopeyki.kz';
    }

    public function authorizationUrl(string $state): string
    {
        $params = [
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    public function exchangeCode(string $code): ?array
    {
        $response = $this->httpPost('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if (!$response || empty($response['access_token'])) {
            return null;
        }

        return $response;
    }

    public function fetchUserInfo(string $accessToken): ?array
    {
        $response = $this->httpGet(
            'https://www.googleapis.com/oauth2/v3/userinfo',
            ['Authorization: Bearer ' . $accessToken]
        );

        if (!$response || empty($response['sub']) || empty($response['email'])) {
            return null;
        }

        return $response;
    }

    private function httpPost(string $url, array $fields): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }

        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    private function httpGet(string $url, array $headers = []): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }

        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }
}
