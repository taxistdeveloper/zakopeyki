<?php

declare(strict_types=1);

namespace App\Services\AI\Core;

use App\Core\Database;
use PDO;

/**
 * Sliding fixed-window rate limit: Redis (optional) → MySQL → file fallback.
 */
final class RateLimiter
{
    private int $perMinute;
    private int $perHour;
    private string $storageDir;

    public function __construct(?array $config = null)
    {
        $cfg = $config ?? (array) AiConfig::get('rate_limit', []);
        $this->perMinute = max(1, (int) ($cfg['per_minute'] ?? 20));
        $this->perHour = max(1, (int) ($cfg['per_hour'] ?? 200));
        $root = dirname(__DIR__, 4);
        $this->storageDir = $root . '/storage/cache/ai_rate';
    }

    /**
     * @return array{allowed:bool, retry_after?:int, reason?:string, remaining_minute?:int}
     */
    public function attempt(string $key): array
    {
        $key = $this->normalizeKey($key);
        if ($key === '') {
            $key = 'anon';
        }

        $minute = $this->hit($key . ':m', 60, $this->perMinute);
        if (!$minute['allowed']) {
            return [
                'allowed' => false,
                'retry_after' => $minute['retry_after'],
                'reason' => 'per_minute',
                'remaining_minute' => 0,
            ];
        }

        $hour = $this->hit($key . ':h', 3600, $this->perHour);
        if (!$hour['allowed']) {
            return [
                'allowed' => false,
                'retry_after' => $hour['retry_after'],
                'reason' => 'per_hour',
                'remaining_minute' => max(0, $this->perMinute - $minute['hits']),
            ];
        }

        return [
            'allowed' => true,
            'remaining_minute' => max(0, $this->perMinute - $minute['hits']),
        ];
    }

    public function clientKey(?int $userId, ?string $guestToken = null, ?string $ip = null): string
    {
        if ($userId !== null && $userId > 0) {
            return 'u:' . $userId;
        }
        if ($guestToken !== null && $guestToken !== '') {
            return 'g:' . substr(hash('sha256', $guestToken), 0, 24);
        }
        $ip = $ip ?? (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return 'ip:' . substr(hash('sha256', $ip), 0, 24);
    }

    /**
     * @return array{allowed:bool, hits:int, retry_after:int}
     */
    private function hit(string $bucket, int $windowSeconds, int $limit): array
    {
        if ($this->redisEnabled()) {
            $r = $this->hitRedis($bucket, $windowSeconds, $limit);
            if ($r !== null) {
                return $r;
            }
        }

        try {
            return $this->hitMysql($bucket, $windowSeconds, $limit);
        } catch (\Throwable $e) {
            return $this->hitFile($bucket, $windowSeconds, $limit);
        }
    }

    /**
     * @return array{allowed:bool, hits:int, retry_after:int}|null
     */
    private function hitRedis(string $bucket, int $windowSeconds, int $limit): ?array
    {
        if (!class_exists(\Redis::class)) {
            return null;
        }
        try {
            /** @var \Redis $redis */
            $redis = new \Redis();
            $host = (string) AiConfig::get('redis.host', '127.0.0.1');
            $port = (int) AiConfig::get('redis.port', 6379);
            if (!$redis->connect($host, $port, 0.4)) {
                return null;
            }
            $pass = AiConfig::get('redis.password');
            if (is_string($pass) && $pass !== '') {
                $redis->auth($pass);
            }
            $prefix = (string) AiConfig::get('redis.prefix', 'zakopeyki:ai:');
            $rk = $prefix . 'rl:' . $bucket;
            $hits = (int) $redis->incr($rk);
            if ($hits === 1) {
                $redis->expire($rk, $windowSeconds);
            }
            $ttl = (int) $redis->ttl($rk);
            if ($ttl < 0) {
                $ttl = $windowSeconds;
            }
            return [
                'allowed' => $hits <= $limit,
                'hits' => $hits,
                'retry_after' => max(1, $ttl),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{allowed:bool, hits:int, retry_after:int}
     */
    private function hitMysql(string $bucket, int $windowSeconds, int $limit): array
    {
        $pdo = Database::connect();
        $this->ensureTable($pdo);

        $now = time();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT hits, UNIX_TIMESTAMP(window_start) AS ws FROM ai_rate_limits WHERE bucket_key = ? FOR UPDATE'
            );
            $stmt->execute([$bucket]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $ins = $pdo->prepare(
                    'INSERT INTO ai_rate_limits (bucket_key, hits, window_start) VALUES (?, 1, FROM_UNIXTIME(?))'
                );
                $ins->execute([$bucket, $now]);
                $pdo->commit();
                return ['allowed' => true, 'hits' => 1, 'retry_after' => $windowSeconds];
            }

            $ws = (int) ($row['ws'] ?? $now);
            $hits = (int) ($row['hits'] ?? 0);
            if ($now - $ws >= $windowSeconds) {
                $upd = $pdo->prepare(
                    'UPDATE ai_rate_limits SET hits = 1, window_start = FROM_UNIXTIME(?) WHERE bucket_key = ?'
                );
                $upd->execute([$now, $bucket]);
                $pdo->commit();
                return ['allowed' => true, 'hits' => 1, 'retry_after' => $windowSeconds];
            }

            $hits++;
            $upd = $pdo->prepare('UPDATE ai_rate_limits SET hits = ? WHERE bucket_key = ?');
            $upd->execute([$hits, $bucket]);
            $pdo->commit();
            $retry = max(1, $windowSeconds - ($now - $ws));
            return [
                'allowed' => $hits <= $limit,
                'hits' => $hits,
                'retry_after' => $retry,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{allowed:bool, hits:int, retry_after:int}
     */
    private function hitFile(string $bucket, int $windowSeconds, int $limit): array
    {
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }
        $file = $this->storageDir . '/' . hash('sha256', $bucket) . '.json';
        $now = time();
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return ['allowed' => true, 'hits' => 0, 'retry_after' => $windowSeconds];
        }
        try {
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($data)) {
                $data = ['ws' => $now, 'hits' => 0];
            }
            $ws = (int) ($data['ws'] ?? $now);
            $hits = (int) ($data['hits'] ?? 0);
            if ($now - $ws >= $windowSeconds) {
                $ws = $now;
                $hits = 0;
            }
            $hits++;
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, json_encode(['ws' => $ws, 'hits' => $hits]));
            fflush($fp);
            return [
                'allowed' => $hits <= $limit,
                'hits' => $hits,
                'retry_after' => max(1, $windowSeconds - ($now - $ws)),
            ];
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function ensureTable(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `ai_rate_limits` (
              `bucket_key` VARCHAR(191) NOT NULL PRIMARY KEY,
              `hits` INT UNSIGNED NOT NULL DEFAULT 0,
              `window_start` DATETIME NOT NULL,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function redisEnabled(): bool
    {
        return (bool) AiConfig::get('redis.enabled', false);
    }

    private function normalizeKey(string $key): string
    {
        $key = trim($key);
        $key = preg_replace('/[^a-zA-Z0-9:_-]/', '', $key) ?? '';
        return mb_substr($key, 0, 120, 'UTF-8');
    }
}
