<?php

declare(strict_types=1);

namespace App\Services\AI\Support;

/**
 * Лёгкий кэш: APCu → Redis (если AI_REDIS) → файл storage/cache/ai.
 */
final class AiCache
{
    private string $dir;
    private string $prefix;

    public function __construct(?string $dir = null, string $prefix = 'ai:')
    {
        $root = dirname(__DIR__, 4);
        $this->dir = $dir ?? ($root . '/storage/cache/ai');
        $this->prefix = $prefix;
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
    }

    public function get(string $key): mixed
    {
        $key = $this->normalize($key);

        if (function_exists('apcu_fetch')) {
            $ok = false;
            $val = apcu_fetch($this->prefix . $key, $ok);
            if ($ok) {
                return $val;
            }
        }

        if ($this->redisEnabled()) {
            $r = $this->redisGet($key);
            if ($r !== null) {
                return $r;
            }
        }

        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !array_key_exists('v', $data) || !isset($data['e'])) {
            return null;
        }
        if ((int) $data['e'] > 0 && time() > (int) $data['e']) {
            @unlink($file);
            return null;
        }
        return $data['v'];
    }

    public function set(string $key, mixed $value, int $ttlSeconds = 300): void
    {
        $key = $this->normalize($key);
        $ttlSeconds = max(1, $ttlSeconds);

        if (function_exists('apcu_store')) {
            apcu_store($this->prefix . $key, $value, $ttlSeconds);
        }

        if ($this->redisEnabled()) {
            $this->redisSet($key, $value, $ttlSeconds);
        }

        $payload = json_encode([
            'e' => time() + $ttlSeconds,
            'v' => $value,
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }
        @file_put_contents($this->path($key), $payload, LOCK_EX);
    }

    public function remember(string $key, int $ttlSeconds, callable $producer): mixed
    {
        $hit = $this->get($key);
        if ($hit !== null) {
            return $hit;
        }
        $value = $producer();
        if ($value !== null) {
            $this->set($key, $value, $ttlSeconds);
        }
        return $value;
    }

    public function delete(string $key): void
    {
        $key = $this->normalize($key);
        if (function_exists('apcu_delete')) {
            apcu_delete($this->prefix . $key);
        }
        @unlink($this->path($key));
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }

    private function normalize(string $key): string
    {
        return mb_substr(preg_replace('/\s+/', '_', trim($key)) ?? '', 0, 180, 'UTF-8');
    }

    private function redisEnabled(): bool
    {
        return (bool) (\App\Services\AI\Core\AiConfig::get('redis.enabled', false));
    }

    private function redisGet(string $key): mixed
    {
        try {
            $redis = $this->redis();
            if ($redis === null) {
                return null;
            }
            $raw = $redis->get($this->redisKey($key));
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            return json_decode($raw, true);
        } catch (\Throwable) {
            return null;
        }
    }

    private function redisSet(string $key, mixed $value, int $ttl): void
    {
        try {
            $redis = $this->redis();
            if ($redis === null) {
                return;
            }
            $redis->setex($this->redisKey($key), $ttl, json_encode($value, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) {
            // ignore
        }
    }

    private function redisKey(string $key): string
    {
        $prefix = (string) \App\Services\AI\Core\AiConfig::get('redis.prefix', 'zakopeyki:ai:');
        return $prefix . 'cache:' . $key;
    }

    private function redis(): ?\Redis
    {
        if (!class_exists(\Redis::class)) {
            return null;
        }
        static $client = null;
        static $failed = false;
        if ($failed) {
            return null;
        }
        if ($client instanceof \Redis) {
            return $client;
        }
        try {
            $r = new \Redis();
            $host = (string) \App\Services\AI\Core\AiConfig::get('redis.host', '127.0.0.1');
            $port = (int) \App\Services\AI\Core\AiConfig::get('redis.port', 6379);
            if (!$r->connect($host, $port, 0.3)) {
                $failed = true;
                return null;
            }
            $pass = \App\Services\AI\Core\AiConfig::get('redis.password');
            if (is_string($pass) && $pass !== '') {
                $r->auth($pass);
            }
            $client = $r;
            return $client;
        } catch (\Throwable) {
            $failed = true;
            return null;
        }
    }
}
