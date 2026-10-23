<?php

namespace App\Models;

use App\Core\Model;

class BusinessUsage extends Model
{
    protected string $table = 'business_usage';
    private static bool $ensured = false;

    public function __construct()
    {
        parent::__construct();
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        if (self::$ensured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS business_usage (
                user_id INT UNSIGNED NOT NULL,
                period_key VARCHAR(16) NOT NULL,
                metric VARCHAR(40) NOT NULL,
                used INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, period_key, metric)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->ensureIndex('business_usage', 'PRIMARY', 'PRIMARY KEY (user_id, period_key, metric)');

        self::$ensured = true;
    }

    public function get(int $userId, string $metric, string $periodKey): int
    {
        $stmt = $this->db->prepare(
            'SELECT used FROM business_usage WHERE user_id = ? AND period_key = ? AND metric = ?'
        );
        $stmt->execute([$userId, $periodKey, $metric]);
        $row = $stmt->fetch();
        return $row ? (int) $row['used'] : 0;
    }

    public function increment(int $userId, string $metric, string $periodKey, int $by = 1): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO business_usage (user_id, period_key, metric, used)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE used = used + VALUES(used)'
        );
        $stmt->execute([$userId, $periodKey, $metric, max(1, $by)]);
        return $this->get($userId, $metric, $periodKey);
    }

    /**
     * Атомарный резерв: used увеличивается только если used + by <= cap.
     * Не допускает отрицательный «остаток» (used > cap) при гонках.
     */
    public function tryReserve(int $userId, string $metric, string $periodKey, int $cap, int $by = 1): bool
    {
        $by = max(1, $by);
        if ($cap < $by) {
            return false;
        }

        $ensure = $this->db->prepare(
            'INSERT INTO business_usage (user_id, period_key, metric, used)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE used = used'
        );
        $ensure->execute([$userId, $periodKey, $metric]);

        $stmt = $this->db->prepare(
            'UPDATE business_usage
             SET used = used + ?
             WHERE user_id = ? AND period_key = ? AND metric = ? AND used <= ?'
        );
        // used + by <= cap  ⇔  used <= cap - by
        $stmt->execute([$by, $userId, $periodKey, $metric, $cap - $by]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Освобождение ранее зарезервированной единицы (техошибка до commit).
     * Не опускает used ниже 0.
     */
    public function releaseReserve(int $userId, string $metric, string $periodKey, int $by = 1): bool
    {
        $by = max(1, $by);
        $stmt = $this->db->prepare(
            'UPDATE business_usage
             SET used = used - ?
             WHERE user_id = ? AND period_key = ? AND metric = ? AND used >= ?'
        );
        $stmt->execute([$by, $userId, $periodKey, $metric, $by]);

        return $stmt->rowCount() === 1;
    }

    /** @return array<string, int> */
    public function forPeriod(int $userId, string $periodKey): array
    {
        $stmt = $this->db->prepare(
            'SELECT metric, used FROM business_usage WHERE user_id = ? AND period_key = ?'
        );
        $stmt->execute([$userId, $periodKey]);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $out[(string) $row['metric']] = (int) $row['used'];
        }
        return $out;
    }
}
