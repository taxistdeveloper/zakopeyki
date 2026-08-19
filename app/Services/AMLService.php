<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\ActivityLogger;
use App\Models\User;
use InvalidArgumentException;
use PDO;
use Throwable;

class AMLService
{
    public const STATUS_BLOCKED = 'AML_BLOCKED';
    public const STATUS_CLEAR = 'clear';

    public static function userListingStatus(?array $user): string
    {
        if ($user === null || $user === []) {
            return 'guest';
        }
        if (($user['aml_status'] ?? '') === self::STATUS_BLOCKED) {
            return 'blocked';
        }
        $iin = preg_replace('/\D/', '', (string) ($user['iin'] ?? '')) ?? '';
        if (strlen($iin) === 12 && ($user['aml_status'] ?? '') === self::STATUS_CLEAR) {
            return 'ok';
        }

        return 'needs_iin';
    }

    private PDO $db;
    private ?object $redis;
    private string $redisSetKey;

    public function __construct(PDO $db, ?object $redis = null, string $redisSetKey = 'aml:blacklisted_iins')
    {
        $this->db = $db;
        $this->redis = $redis;
        $this->redisSetKey = $redisSetKey;
    }

    public static function make(): self
    {
        $cfg = is_file(dirname(__DIR__, 2) . '/config/aml.php')
            ? require dirname(__DIR__, 2) . '/config/aml.php'
            : [];
        $key = (string) (($cfg['redis']['main_key'] ?? 'aml:blacklisted_iins'));

        return new self(Database::connect(), self::makeRedis(), $key);
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
                return $redis;
            }
        } catch (Throwable) {
        }

        return null;
    }

    public function ensureSchema(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS `aml_blacklisted_persons` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `iin` VARCHAR(12) NOT NULL,
                `full_name` VARCHAR(255) NULL,
                `list_type` ENUM('person', 'organization') NOT NULL DEFAULT 'person',
                `source_name` VARCHAR(100) DEFAULT 'AFM_RK',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_iin` (`iin`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * @return array{ok: bool, error?: string, iin?: string, blocked?: bool}
     */
    public function screenUser(int $userId, string $iinInput, string $context = 'listing'): array
    {
        $this->ensureSchema();
        $users = new User();
        $user = $users->find($userId);
        if (!$user) {
            return ['ok' => false, 'error' => t('flash.aml_user_missing')];
        }

        if (($user['aml_status'] ?? '') === self::STATUS_BLOCKED) {
            return ['ok' => false, 'blocked' => true, 'error' => t('flash.aml_blocked')];
        }

        $iin = $this->normalizeIin($iinInput);
        if ($iin === '') {
            $iin = $this->normalizeIin((string) ($user['iin'] ?? ''));
        }

        if (!$this->validateIinFormat($iin)) {
            return ['ok' => false, 'error' => t('flash.aml_iin_invalid')];
        }

        if ($this->isBlacklisted($iin)) {
            $this->blockUser($userId, $iin, $context);
            return ['ok' => false, 'blocked' => true, 'iin' => $iin, 'error' => t('flash.aml_blocked')];
        }

        $users->saveAmlClear($userId, $iin);

        return ['ok' => true, 'iin' => $iin];
    }

    public function isBlacklisted(string $iin): bool
    {
        $clean = $this->normalizeIin($iin);
        if (!$this->validateIinFormat($clean)) {
            throw new InvalidArgumentException('Некорректный формат ИИН: ' . $iin);
        }

        if ($this->redis !== null && method_exists($this->redis, 'sIsMember')) {
            try {
                $ready = !method_exists($this->redis, 'exists') || $this->redis->exists($this->redisSetKey);
                if ($ready) {
                    return (bool) $this->redis->sIsMember($this->redisSetKey, $clean);
                }
            } catch (Throwable $e) {
                error_log('AMLService Redis: ' . $e->getMessage());
            }
        }

        return $this->checkIinInDatabase($clean);
    }

    public function checkIin(string $iin): bool
    {
        return $this->isBlacklisted($iin);
    }

    private function checkIinInDatabase(string $iin): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM `aml_blacklisted_persons` WHERE `iin` = :iin LIMIT 1');
        $stmt->execute(['iin' => $iin]);

        return (bool) $stmt->fetchColumn();
    }

    public function normalizeIin(string $iin): string
    {
        return preg_replace('/\D/', '', $iin) ?? '';
    }

    public function validateIinFormat(string $iin): bool
    {
        $iin = $this->normalizeIin($iin);
        if (strlen($iin) !== 12 || !ctype_digit($iin)) {
            return false;
        }

        $centuryDigit = (int) $iin[6];
        $yearBase = match ($centuryDigit) {
            1, 2 => 1800,
            3, 4 => 1900,
            5, 6 => 2000,
            default => null,
        };
        if ($yearBase === null) {
            return false;
        }

        $year = $yearBase + (int) substr($iin, 0, 2);
        $month = (int) substr($iin, 2, 2);
        $day = (int) substr($iin, 4, 2);
        if (!checkdate($month, $day, $year)) {
            return false;
        }

        $weights1 = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];
        $controlSum = 0;
        for ($i = 0; $i < 11; $i++) {
            $controlSum += (int) $iin[$i] * $weights1[$i];
        }
        $controlDigit = $controlSum % 11;

        if ($controlDigit === 10) {
            $weights2 = [3, 4, 5, 6, 7, 8, 9, 10, 11, 1, 2];
            $controlSum = 0;
            for ($i = 0; $i < 11; $i++) {
                $controlSum += (int) $iin[$i] * $weights2[$i];
            }
            $controlDigit = $controlSum % 11;
        }

        return $controlDigit < 10 && $controlDigit === (int) $iin[11];
    }

    private function blockUser(int $userId, string $iin, string $context): void
    {
        (new User())->setAmlStatus($userId, self::STATUS_BLOCKED, $iin);
        ActivityLogger::error(
            'aml.blocked',
            'AML_BLOCKED: совпадение с перечнем АФМ РК',
            'user',
            $userId,
            ['iin_tail' => substr($iin, -4), 'context' => $context]
        );
    }
}
