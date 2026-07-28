<?php

namespace App\Models;

use App\Core\Model;
use DateTime;
use PDO;

/**
 * Очередь фоновых задач AI на MySQL (без Redis).
 */
class AiQueue extends Model
{
    protected string $table = 'ai_queue_jobs';
    private static bool $ensured = false;

    public function __construct()
    {
        parent::__construct();
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS ai_queue_jobs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                queue VARCHAR(64) NOT NULL DEFAULT 'default',
                payload LONGTEXT NOT NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                reserved_at DATETIME NULL,
                available_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ai_queue_ready (queue, reserved_at, available_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$ensured = true;
    }

    public function push(string $jobClass, array $payload, string $queue = 'default', int $delaySeconds = 0): int
    {
        $availableAt = new DateTime();
        if ($delaySeconds > 0) {
            $availableAt->modify("+{$delaySeconds} seconds");
        }

        $data = [
            'job_class' => $jobClass,
            'payload' => $payload,
        ];

        $stmt = $this->db->prepare(
            'INSERT INTO ai_queue_jobs (queue, payload, available_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([
            $queue,
            json_encode($data, JSON_UNESCAPED_UNICODE),
            $availableAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function pop(string $queue = 'default'): ?array
    {
        try {
            $this->db->beginTransaction();

            $now = (new DateTime())->format('Y-m-d H:i:s');
            $stmt = $this->db->prepare(
                'SELECT * FROM ai_queue_jobs
                 WHERE queue = ?
                   AND reserved_at IS NULL
                   AND available_at <= ?
                 ORDER BY id ASC
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([$queue, $now]);
            $job = $stmt->fetch();

            if (!$job) {
                $this->db->commit();
                return null;
            }

            $upd = $this->db->prepare(
                'UPDATE ai_queue_jobs SET reserved_at = ?, attempts = attempts + 1 WHERE id = ?'
            );
            $upd->execute([$now, $job['id']]);

            $this->db->commit();

            $decoded = json_decode((string) $job['payload'], true);
            $job['payload'] = is_array($decoded) ? $decoded : [];
            return $job;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function delete(int $jobId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM ai_queue_jobs WHERE id = ?');
        return $stmt->execute([$jobId]);
    }

    public function release(int $jobId, int $delaySeconds = 10): bool
    {
        $availableAt = (new DateTime())->modify("+{$delaySeconds} seconds")->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE ai_queue_jobs SET reserved_at = NULL, available_at = ? WHERE id = ?'
        );
        return $stmt->execute([$availableAt, $jobId]);
    }

    public function pendingCount(string $queue = 'default'): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM ai_queue_jobs WHERE queue = ? AND reserved_at IS NULL'
        );
        $stmt->execute([$queue]);
        return (int) $stmt->fetchColumn();
    }

    public function pdo(): PDO
    {
        return $this->db;
    }
}
