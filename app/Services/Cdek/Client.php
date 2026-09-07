<?php

namespace App\Services\Cdek;

/**
 * HTTP-клиент СДЭК API v2 (OAuth2 client_credentials).
 * @see https://apidoc.cdek.ru/#tag/common/Vvedenie
 */
class Client
{
    private array $config;
    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;
    private ?string $lastError = null;
    private ?string $lastCurlError = null;

    public function __construct(?array $config = null)
    {
        if ($config !== null) {
            $this->config = $config;
            return;
        }

        $path = dirname(__DIR__, 3) . '/config/cdek.php';
        $this->config = is_file($path) ? (require $path) : [];
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['account'] ?? '')) !== ''
            && trim((string) ($this->config['secure_password'] ?? '')) !== '';
    }

    public function config(): array
    {
        return $this->config;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function lastCurlError(): ?string
    {
        return $this->lastCurlError;
    }

    public function isTestMode(): bool
    {
        return (int) ($this->config['test_mode'] ?? 1) === 1;
    }

    /**
     * @return array{ok: bool, token?: string, error?: string, raw?: array}
     */
    public function authorize(bool $force = false): array
    {
        $this->lastError = null;

        if (!$this->isConfigured()) {
            $this->lastError = 'CDEK is not configured';
            return ['ok' => false, 'error' => $this->lastError];
        }

        if (!$force && $this->accessToken !== null && time() < $this->tokenExpiresAt) {
            return ['ok' => true, 'token' => $this->accessToken];
        }

        $cached = $this->readTokenCache();
        if (!$force && $cached !== null) {
            $this->accessToken = $cached['access_token'];
            $this->tokenExpiresAt = $cached['expires_at'];
            return ['ok' => true, 'token' => $this->accessToken];
        }

        $base = $this->baseUrl();
        $fields = [
            'grant_type' => 'client_credentials',
            'client_id' => (string) $this->config['account'],
            'client_secret' => (string) $this->config['secure_password'],
        ];
        $encoded = http_build_query($fields);

        // Официальный путь: /oauth/token?parameters + body x-www-form-urlencoded
        $endpoints = [
            $base . '/oauth/token?parameters',
            $base . '/oauth/token',
            $base . '/oauth/token?' . $encoded,
        ];

        $http = null;
        foreach ($endpoints as $endpoint) {
            $attempt = $this->rawRequest(
                'POST',
                $endpoint,
                str_contains($endpoint, $encoded) ? '' : $encoded,
                ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
                false
            );
            if ($attempt === null) {
                continue;
            }
            $decoded = json_decode($attempt[1], true);
            $http = $attempt;
            if (is_array($decoded) && !empty($decoded['access_token'])) {
                break;
            }
        }

        if ($http === null) {
            $curlHint = '';
            if ($this->lastCurlError) {
                $curlHint = ' (' . $this->lastCurlError . ')';
            }
            $this->lastError = 'CDEK oauth request failed' . $curlHint;
            return ['ok' => false, 'error' => $this->lastError];
        }

        [$code, $body] = $http;
        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['access_token'])) {
            $hint = '';
            if (is_array($json) && ($json['error'] ?? '') === 'invalid_client') {
                $hint = ' — проверьте Account/Secure в config/cdek.php (тестовые ключи на apidoc.cdek.ru обновляются)';
            }
            $this->lastError = 'CDEK oauth failed HTTP ' . $code . $hint;
            return ['ok' => false, 'error' => $this->lastError, 'raw' => is_array($json) ? $json : ['body' => mb_substr($body, 0, 400)]];
        }

        $ttl = max(60, (int) ($json['expires_in'] ?? 3600));
        $this->accessToken = (string) $json['access_token'];
        $this->tokenExpiresAt = time() + $ttl - 60;
        $this->writeTokenCache($this->accessToken, $this->tokenExpiresAt);

        return ['ok' => true, 'token' => $this->accessToken, 'raw' => $json];
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     * @param array<string, scalar|null>|null $query
     * @return array{ok: bool, code: int, data?: mixed, error?: string, body?: string}
     */
    public function request(string $method, string $path, ?array $jsonBody = null, ?array $query = null): array
    {
        $this->lastError = null;
        $auth = $this->authorize();
        if (!$auth['ok']) {
            return ['ok' => false, 'code' => 0, 'error' => $auth['error'] ?? 'auth failed'];
        }

        $url = $this->baseUrl() . '/' . ltrim($path, '/');
        if ($query) {
            $parts = [];
            foreach ($query as $k => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
            }
            if ($parts !== []) {
                $url .= (str_contains($url, '?') ? '&' : '?') . implode('&', $parts);
            }
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->accessToken,
        ];
        $payload = null;
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        }

        $http = $this->rawRequest(strtoupper($method), $url, $payload, $headers, true);
        if ($http === null) {
            $this->lastError = 'CDEK request failed: ' . $path;
            return ['ok' => false, 'code' => 0, 'error' => $this->lastError];
        }

        [$code, $body] = $http;

        // Один повтор при протухшем токене
        if ($code === 401) {
            $auth = $this->authorize(true);
            if ($auth['ok']) {
                $headers = [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $this->accessToken,
                ];
                if ($jsonBody !== null) {
                    $headers[] = 'Content-Type: application/json';
                }
                $http = $this->rawRequest(strtoupper($method), $url, $payload, $headers, true);
                if ($http !== null) {
                    [$code, $body] = $http;
                }
            }
        }

        $data = json_decode($body, true);
        if ($code >= 400) {
            $msg = $this->extractErrorMessage(is_array($data) ? $data : [], $code);
            $this->lastError = $msg;
            return [
                'ok' => false,
                'code' => $code,
                'error' => $msg,
                'data' => is_array($data) ? $data : null,
                'body' => mb_substr($body, 0, 800),
            ];
        }

        return [
            'ok' => true,
            'code' => $code,
            'data' => $data,
            'body' => $body,
        ];
    }

    /**
     * @return array{ok: bool, code: int, data?: mixed, error?: string}
     */
    public function get(string $path, ?array $query = null): array
    {
        return $this->request('GET', $path, null, $query);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{ok: bool, code: int, data?: mixed, error?: string}
     */
    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    /**
     * Поиск кода города СДЭК по названию.
     * @return array{code: int, city: string, country_code: string}|null
     */
    public function findCityCode(string $city, ?string $countryCode = null): ?array
    {
        $city = trim($city);
        if ($city === '') {
            return null;
        }

        $country = strtoupper(trim((string) ($countryCode ?: ($this->config['default_country'] ?? 'KZ'))));
        $aliases = $this->cityAliases($city);

        foreach ($aliases as $candidate) {
            $found = $this->lookupCityOnce($candidate, $country);
            if ($found !== null) {
                return $found;
            }
        }

        // Fallback: известный код (edu API не находит «Астана» по короткому имени)
        $known = $this->knownCityCodes();
        $key = mb_strtolower($city);
        if (isset($known[$key])) {
            $byCode = $this->lookupCityByCode($known[$key]);
            if ($byCode !== null) {
                return $byCode;
            }
            return [
                'code' => $known[$key],
                'city' => $city,
                'country_code' => $country,
            ];
        }

        return null;
    }

    /** @return list<string> */
    private function cityAliases(string $city): array
    {
        $key = mb_strtolower(trim($city));
        $map = [
            'астана' => ['Астана (Нур-Султан)', 'Астана', 'Нур-Султан', 'Нурсултан', 'Astana'],
            'нур-султан' => ['Астана (Нур-Султан)', 'Нур-Султан', 'Астана', 'Нурсултан'],
            'нурсултан' => ['Астана (Нур-Султан)', 'Нурсултан', 'Нур-Султан', 'Астана'],
            'алматы' => ['Алматы', 'Алма-Ата', 'Almaty'],
            'алма-ата' => ['Алматы', 'Алма-Ата'],
            'шымкент' => ['Шымкент', 'Чимкент'],
            'чимкент' => ['Шымкент', 'Чимкент'],
        ];

        $list = $map[$key] ?? [$city];
        if (!in_array($city, $list, true)) {
            array_unshift($list, $city);
        }
        return array_values(array_unique($list));
    }

    /** @return array<string, int> */
    private function knownCityCodes(): array
    {
        return [
            'астана' => 4961,
            'нур-султан' => 4961,
            'нурсултан' => 4961,
            'астана (нур-султан)' => 4961,
            'алматы' => 4756,
            'алма-ата' => 4756,
            'шымкент' => 12787,
            'чимкент' => 12787,
            'караганда' => 7669,
        ];
    }

    /** @return array{code: int, city: string, country_code: string}|null */
    private function lookupCityByCode(int $code): ?array
    {
        $res = $this->get('/location/cities', ['code' => $code]);
        if (!$res['ok'] || !is_array($res['data'] ?? null)) {
            return null;
        }
        $list = $res['data'];
        $row = isset($list['code']) ? $list : ($list[0] ?? null);
        if (!is_array($row) || empty($row['code'])) {
            return null;
        }
        return [
            'code' => (int) $row['code'],
            'city' => (string) ($row['city'] ?? ''),
            'country_code' => (string) ($row['country_code'] ?? ''),
        ];
    }

    /** @return array{code: int, city: string, country_code: string}|null */
    private function lookupCityOnce(string $city, string $country): ?array
    {
        $res = $this->get('/location/cities', [
            'city' => $city,
            'country_codes' => $country,
            'size' => 10,
        ]);

        if (!$res['ok'] || !is_array($res['data'] ?? null)) {
            return null;
        }

        $list = $res['data'];
        if ($list === []) {
            return null;
        }
        if (isset($list['code'])) {
            $list = [$list];
        }

        $needle = mb_strtolower($city);
        $best = null;
        foreach ($list as $row) {
            if (!is_array($row) || empty($row['code'])) {
                continue;
            }
            $name = mb_strtolower((string) ($row['city'] ?? ''));
            if ($name === $needle) {
                $best = $row;
                break;
            }
            if ($best === null && (str_contains($name, $needle) || str_contains($needle, $name))) {
                $best = $row;
            }
            if ($best === null) {
                $best = $row;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'code' => (int) $best['code'],
            'city' => (string) ($best['city'] ?? $city),
            'country_code' => (string) ($best['country_code'] ?? $country),
        ];
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->config['api_url'] ?? 'https://api.edu.cdek.ru/v2'), '/');
    }

    private function tokenCachePath(): string
    {
        $custom = trim((string) ($this->config['token_cache_path'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
        $dir = dirname(__DIR__, 3) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/cdek_token.json';
    }

    /** @return array{access_token: string, expires_at: int}|null */
    private function readTokenCache(): ?array
    {
        $path = $this->tokenCachePath();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['access_token']) || empty($data['expires_at'])) {
            return null;
        }
        if (time() >= (int) $data['expires_at']) {
            return null;
        }
        return [
            'access_token' => (string) $data['access_token'],
            'expires_at' => (int) $data['expires_at'],
        ];
    }

    private function writeTokenCache(string $token, int $expiresAt): void
    {
        $path = $this->tokenCachePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, json_encode([
            'access_token' => $token,
            'expires_at' => $expiresAt,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $data */
    private function extractErrorMessage(array $data, int $httpCode): string
    {
        if (!empty($data['error_description'])) {
            return (string) $data['error_description'];
        }
        if (!empty($data['message'])) {
            return (string) $data['message'];
        }
        if (!empty($data['errors']) && is_array($data['errors'])) {
            $first = $data['errors'][0] ?? null;
            if (is_array($first) && !empty($first['message'])) {
                return (string) $first['message'];
            }
        }
        if (!empty($data['requests'][0]['errors'][0]['message'])) {
            return (string) $data['requests'][0]['errors'][0]['message'];
        }
        return 'CDEK HTTP ' . $httpCode;
    }

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: string}|null
     */
    private function rawRequest(string $method, string $url, ?string $body, array $headers, bool $allowEmptyBody): ?array
    {
        $this->lastCurlError = null;
        $ch = curl_init($url);
        if ($ch === false) {
            $this->lastCurlError = 'curl_init failed';
            return null;
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null || !$allowEmptyBody) {
            $opts[CURLOPT_POSTFIELDS] = $body ?? '';
        }

        // MAMP/Windows иногда без CA bundle — пробуем системный, иначе ослабляем только в test_mode
        $ca = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
        if (is_string($ca) && $ca !== '' && is_file($ca)) {
            $opts[CURLOPT_CAINFO] = $ca;
        } elseif ($this->isTestMode()) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || !is_string($response)) {
            $this->lastCurlError = $error !== '' ? $error : ('errno ' . $errno);
            return null;
        }
        return [$code, $response];
    }
}
