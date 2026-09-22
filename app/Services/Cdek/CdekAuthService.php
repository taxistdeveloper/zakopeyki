<?php

namespace App\Services\Cdek;

/**
 * OAuth2 client_credentials для CDEK API v2.
 *
 * Ответственность:
 * - получение access_token;
 * - кэш в Redis (предпочтительно) или файле (fallback, как раньше);
 * - refresh при истечении / force.
 *
 * НЕ логирует client_secret и access_token.
 *
 * @see openapi_api_v2_integration.json — OAuth token endpoint
 */
class CdekAuthService
{
    private const REDIS_KEY_DEFAULT = 'cdek:oauth:access_token';

    private array $config;
    private ?object $redis;
    private ?string $memoryToken = null;
    private int $memoryExpiresAt = 0;
    private ?string $lastError = null;
    private ?string $lastCurlError = null;

    /**
     * @param array<string, mixed> $config config/cdek.php
     * @param object|null $redis phpredis \Redis или совместимый stub (set/get)
     * @param callable|null $httpPoster fn(string $url, string $body, array $headers): ?array{0:int,1:string}
     */
    public function __construct(
        array $config,
        ?object $redis = null,
        private readonly mixed $httpPoster = null
    ) {
        $this->config = $config;
        $this->redis = $redis;
    }

    public static function fromConfig(?array $config = null): self
    {
        if ($config === null) {
            $path = dirname(__DIR__, 3) . '/config/cdek.php';
            $config = is_file($path) ? (require $path) : [];
        }
        return new self($config, self::makeRedis());
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function lastCurlError(): ?string
    {
        return $this->lastCurlError;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['account'] ?? '')) !== ''
            && trim((string) ($this->config['secure_password'] ?? '')) !== '';
    }

    public function isTestMode(): bool
    {
        return (int) ($this->config['test_mode'] ?? 1) === 1;
    }

    /**
     * @return array{ok: bool, token?: string, error?: string, source?: string}
     */
    public function getAccessToken(bool $force = false): array
    {
        $this->lastError = null;

        if (!$this->isConfigured()) {
            $this->lastError = 'CDEK is not configured';
            return ['ok' => false, 'error' => $this->lastError];
        }

        if (!$force && $this->memoryToken !== null && time() < $this->memoryExpiresAt) {
            return ['ok' => true, 'token' => $this->memoryToken, 'source' => 'memory'];
        }

        if (!$force) {
            $cached = $this->readCache();
            if ($cached !== null) {
                $this->memoryToken = $cached['access_token'];
                $this->memoryExpiresAt = $cached['expires_at'];
                return ['ok' => true, 'token' => $this->memoryToken, 'source' => $cached['source']];
            }
        }

        return $this->requestNewToken();
    }

    /**
     * @return array{ok: bool, token?: string, error?: string, source?: string, raw?: array}
     */
    private function requestNewToken(): array
    {
        $base = rtrim((string) ($this->config['api_url'] ?? 'https://api.edu.cdek.ru/v2'), '/');
        $fields = [
            'grant_type' => 'client_credentials',
            'client_id' => (string) $this->config['account'],
            'client_secret' => (string) $this->config['secure_password'],
        ];
        $encoded = http_build_query($fields);

        // CDEK historically accepts /oauth/token?parameters + form body; keep fallbacks.
        $endpoints = [
            $base . '/oauth/token?parameters',
            $base . '/oauth/token',
        ];

        $http = null;
        foreach ($endpoints as $endpoint) {
            $attempt = $this->postForm($endpoint, $encoded);
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
            $curlHint = $this->lastCurlError ? (' (' . $this->lastCurlError . ')') : '';
            $this->lastError = 'CDEK oauth request failed' . $curlHint;
            return ['ok' => false, 'error' => $this->lastError];
        }

        [$code, $body] = $http;
        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['access_token'])) {
            $hint = '';
            if (is_array($json) && ($json['error'] ?? '') === 'invalid_client') {
                $hint = ' — проверьте Account/Secure в config/cdek.php';
            }
            // Не кладём raw body с возможными секретами в lastError.
            $this->lastError = 'CDEK oauth failed HTTP ' . $code . $hint;
            return ['ok' => false, 'error' => $this->lastError];
        }

        $ttl = max(60, (int) ($json['expires_in'] ?? 3600));
        $token = (string) $json['access_token'];
        $expiresAt = time() + $ttl - 60;

        $this->memoryToken = $token;
        $this->memoryExpiresAt = $expiresAt;
        $this->writeCache($token, $expiresAt);

        return ['ok' => true, 'token' => $token, 'source' => 'network'];
    }

    /** @return array{access_token: string, expires_at: int, source: string}|null */
    private function readCache(): ?array
    {
        $fromRedis = $this->readRedis();
        if ($fromRedis !== null) {
            return $fromRedis + ['source' => 'redis'];
        }
        $fromFile = $this->readFileCache();
        if ($fromFile !== null) {
            return $fromFile + ['source' => 'file'];
        }
        return null;
    }

    private function writeCache(string $token, int $expiresAt): void
    {
        $this->writeRedis($token, $expiresAt);
        $this->writeFileCache($token, $expiresAt);
    }

    /** @return array{access_token: string, expires_at: int}|null */
    private function readRedis(): ?array
    {
        if ($this->redis === null || !method_exists($this->redis, 'get')) {
            return null;
        }
        try {
            $raw = $this->redis->get($this->redisKey());
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
        } catch (\Throwable) {
            return null;
        }
    }

    private function writeRedis(string $token, int $expiresAt): void
    {
        if ($this->redis === null || !method_exists($this->redis, 'set')) {
            return;
        }
        $ttl = max(30, $expiresAt - time());
        $payload = json_encode([
            'access_token' => $token,
            'expires_at' => $expiresAt,
        ], JSON_UNESCAPED_UNICODE);
        try {
            // SET NX не нужен: overwrite ок; EX — TTL ключа.
            if (method_exists($this->redis, 'setex')) {
                $this->redis->setex($this->redisKey(), $ttl, $payload);
            } else {
                $this->redis->set($this->redisKey(), $payload);
            }
        } catch (\Throwable) {
            // Redis optional — file cache remains.
        }
    }

    private function redisKey(): string
    {
        $custom = trim((string) ($this->config['redis_token_key'] ?? ''));
        return $custom !== '' ? $custom : self::REDIS_KEY_DEFAULT;
    }

    /** @return array{access_token: string, expires_at: int}|null */
    private function readFileCache(): ?array
    {
        $path = $this->tokenCachePath();
        if (!is_file($path)) {
            return null;
        }
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            return null;
        }
        try {
            flock($fp, LOCK_SH);
            $raw = stream_get_contents($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
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

    private function writeFileCache(string $token, int $expiresAt): void
    {
        $path = $this->tokenCachePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $payload = json_encode([
            'access_token' => $token,
            'expires_at' => $expiresAt,
        ], JSON_UNESCAPED_UNICODE);

        $fp = @fopen($path, 'c+b');
        if ($fp === false) {
            return;
        }
        try {
            // File lock снижает race: два PHP-FPM воркера не перетрут файл частично.
            flock($fp, LOCK_EX);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) $payload);
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
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

    /**
     * @return array{0: int, 1: string}|null
     */
    private function postForm(string $url, string $body): ?array
    {
        if (is_callable($this->httpPoster)) {
            return ($this->httpPoster)(
                $url,
                $body,
                ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json']
            );
        }

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
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

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

    private static function makeRedis(): ?object
    {
        if (!class_exists(\Redis::class)) {
            return null;
        }
        try {
            $redis = new \Redis();
            $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
            $port = (int) (getenv('REDIS_PORT') ?: 6379);
            if ($redis->connect($host, $port, 0.15)) {
                $password = getenv('REDIS_PASSWORD');
                if (is_string($password) && $password !== '') {
                    $redis->auth($password);
                }
                return $redis;
            }
        } catch (\Throwable) {
        }
        return null;
    }
}
