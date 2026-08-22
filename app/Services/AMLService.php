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

    public static function isBusinessUser(?array $user): bool
    {
        if ($user === null || $user === []) {
            return false;
        }
        if (($user['account_type'] ?? '') === 'business') {
            return true;
        }

        return in_array((string) ($user['business_status'] ?? ''), ['pending', 'verified'], true);
    }

    public static function userListingStatus(?array $user): string
    {
        if ($user === null || $user === []) {
            return 'guest';
        }
        if (($user['aml_status'] ?? '') === self::STATUS_BLOCKED) {
            return 'blocked';
        }
        if (self::isBusinessUser($user)) {
            $bin = preg_replace('/\D/', '', (string) ($user['bin'] ?? '')) ?? '';
            if (strlen($bin) === 12 && ($user['aml_status'] ?? '') === self::STATUS_CLEAR) {
                return 'ok';
            }

            return 'needs_iin';
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
     * @return array{ok: bool, error?: string, iin?: string, bin?: string, blocked?: bool}
     */
    public function screenUser(int $userId, string $idInput, string $context = 'listing', ?string $entityType = null): array
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

        $useBin = self::isBusinessUser($user) || $context === 'business_upgrade' || $context === 'business_approve';
        $entity = strtolower((string) ($entityType ?: ($user['business_entity_type'] ?? '')));

        if ($useBin) {
            $bin = $this->normalizeIin($idInput);
            if ($bin === '') {
                $bin = $this->normalizeIin((string) ($user['bin'] ?? ''));
            }
            if (!$this->validateBusinessTaxId($bin, $entity)) {
                return ['ok' => false, 'error' => t('flash.aml_bin_invalid')];
            }
            if ($this->isBlacklisted($bin)) {
                $this->blockUser($userId, $bin, $context, false);
                return ['ok' => false, 'blocked' => true, 'bin' => $bin, 'error' => t('flash.aml_blocked')];
            }

            $saveIin = $this->validateIinFormat($bin) ? $bin : null;
            $users->saveAmlClear($userId, $saveIin, $bin);

            $prev = (string) ($user['aml_status'] ?? '');
            if ($prev !== self::STATUS_CLEAR) {
                ActivityLogger::info(
                    'aml.verified',
                    'AML: БИН/ИИН прошёл сверку с перечнем АФМ РК',
                    'user',
                    $userId,
                    ['id_tail' => substr($bin, -4), 'context' => $context, 'kind' => 'bin']
                );
            }

            return ['ok' => true, 'bin' => $bin];
        }

        $iin = $this->normalizeIin($idInput);
        if ($iin === '') {
            $iin = $this->normalizeIin((string) ($user['iin'] ?? ''));
        }

        if (!$this->validateIinFormat($iin)) {
            return ['ok' => false, 'error' => t('flash.aml_iin_invalid')];
        }

        if ($this->isBlacklisted($iin)) {
            $this->blockUser($userId, $iin, $context, true);
            return ['ok' => false, 'blocked' => true, 'iin' => $iin, 'error' => t('flash.aml_blocked')];
        }

        $users->saveAmlClear($userId, $iin);

        $prev = (string) ($user['aml_status'] ?? '');
        if ($prev !== self::STATUS_CLEAR) {
            ActivityLogger::info(
                'aml.verified',
                'AML: ИИН прошёл сверку с перечнем АФМ РК',
                'user',
                $userId,
                ['iin_tail' => substr($iin, -4), 'context' => $context]
            );
        }

        return ['ok' => true, 'iin' => $iin];
    }

    public function isBlacklisted(string $iin): bool
    {
        $clean = $this->normalizeIin($iin);
        if (!$this->hasValidChecksum($clean)) {
            throw new InvalidArgumentException('Некорректный формат ИИН/БИН: ' . $iin);
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

    public function hasValidChecksum(string $id): bool
    {
        $id = $this->normalizeIin($id);
        if (strlen($id) !== 12 || !ctype_digit($id)) {
            return false;
        }

        $weights1 = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];
        $controlSum = 0;
        for ($i = 0; $i < 11; $i++) {
            $controlSum += (int) $id[$i] * $weights1[$i];
        }
        $controlDigit = $controlSum % 11;

        if ($controlDigit === 10) {
            $weights2 = [3, 4, 5, 6, 7, 8, 9, 10, 11, 1, 2];
            $controlSum = 0;
            for ($i = 0; $i < 11; $i++) {
                $controlSum += (int) $id[$i] * $weights2[$i];
            }
            $controlDigit = $controlSum % 11;
        }

        return $controlDigit < 10 && $controlDigit === (int) $id[11];
    }

    public function validateIinFormat(string $iin): bool
    {
        $iin = $this->normalizeIin($iin);
        if (!$this->hasValidChecksum($iin)) {
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

        return checkdate($month, $day, $year);
    }

    public function validateBinFormat(string $bin): bool
    {
        $bin = $this->normalizeIin($bin);
        if (!$this->hasValidChecksum($bin)) {
            return false;
        }

        $month = (int) substr($bin, 2, 2);
        if ($month < 1 || $month > 12) {
            return false;
        }

        return in_array($bin[4], ['4', '5', '6'], true);
    }

    public function validateBusinessTaxId(string $id, string $entityType = ''): bool
    {
        $entityType = strtolower($entityType);
        if ($entityType === 'too') {
            return $this->validateBinFormat($id);
        }

        return $this->validateIinFormat($id) || $this->validateBinFormat($id);
    }

    public static function maskIin(?string $iin): string
    {
        $clean = preg_replace('/\D/', '', (string) $iin) ?? '';
        if (strlen($clean) !== 12) {
            return '—';
        }

        return substr($clean, 0, 4) . '****' . substr($clean, -4);
    }

    /**
     * @return array{list_count: int, list_updated: ?string}
     */
    public function listStats(): array
    {
        $this->ensureSchema();
        $count = (int) $this->db->query('SELECT COUNT(*) FROM `aml_blacklisted_persons`')->fetchColumn();
        $updated = $this->db->query('SELECT MAX(`updated_at`) FROM `aml_blacklisted_persons`')->fetchColumn();

        return [
            'list_count' => $count,
            'list_updated' => $updated ? (string) $updated : null,
        ];
    }

    private function blockUser(int $userId, string $id, string $context, bool $storeAsIin): void
    {
        (new User())->setAmlStatus(
            $userId,
            self::STATUS_BLOCKED,
            $storeAsIin ? $id : null,
            $storeAsIin ? null : $id
        );
        ActivityLogger::error(
            'aml.blocked',
            'AML_BLOCKED: совпадение с перечнем АФМ РК',
            'user',
            $userId,
            ['id_tail' => substr($id, -4), 'context' => $context, 'kind' => $storeAsIin ? 'iin' : 'bin']
        );
    }
}
