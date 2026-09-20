<?php

namespace App\Models;

use App\Core\Model;

class OpsTask extends Model
{
    protected string $table = 'ops_tasks';
    private static bool $ensured = false;

    public const PRIORITIES = ['high', 'medium', 'low'];
    public const STATUSES = ['new', 'in_progress', 'review', 'done', 'cancelled'];
    public const OPEN_STATUSES = ['new', 'in_progress', 'review'];

    public function __construct()
    {
        parent::__construct();
        $this->ensureTables();
    }

    private function ensureTables(): void
    {
        if (self::$ensured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS ops_tasks (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(240) NOT NULL,
                description MEDIUMTEXT DEFAULT NULL,
                priority ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
                status ENUM('new','in_progress','review','done','cancelled') NOT NULL DEFAULT 'new',
                assignee_id INT UNSIGNED DEFAULT NULL,
                created_by INT UNSIGNED NOT NULL,
                deadline DATETIME DEFAULT NULL,
                deadline_notified_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_ops_status (status),
                INDEX idx_ops_assignee (assignee_id),
                INDEX idx_ops_priority (priority),
                INDEX idx_ops_deadline (deadline)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS ops_task_comments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                task_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ops_comment_task (task_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS ops_task_events (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                task_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED DEFAULT NULL,
                event_type VARCHAR(32) NOT NULL,
                meta_json JSON DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ops_event_task (task_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS ops_task_files (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                task_id INT UNSIGNED NOT NULL,
                comment_id INT UNSIGNED DEFAULT NULL,
                original_name VARCHAR(255) NOT NULL,
                stored_name VARCHAR(120) NOT NULL,
                mime VARCHAR(120) DEFAULT NULL,
                size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_ops_file_stored (stored_name),
                INDEX idx_ops_file_task (task_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$ensured = true;
    }

    public function countOpen(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM ops_tasks WHERE status IN ('new','in_progress','review')"
        )->fetchColumn();
    }

    /**
     * @param array{
     *   status?:string,
     *   assignee_id?:int,
     *   priority?:string,
     *   deadline?:string,
     *   sort?:string
     * } $filters
     * @return list<array<string, mixed>>
     */
    public function search(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where[] = 't.status = ?';
            $params[] = $status;
        }

        $assignee = (int) ($filters['assignee_id'] ?? 0);
        if ($assignee > 0) {
            $where[] = 't.assignee_id = ?';
            $params[] = $assignee;
        } elseif (($filters['assignee_id'] ?? null) === -1) {
            $where[] = 't.assignee_id IS NULL';
        }

        $priority = (string) ($filters['priority'] ?? '');
        if ($priority !== '' && in_array($priority, self::PRIORITIES, true)) {
            $where[] = 't.priority = ?';
            $params[] = $priority;
        }

        $deadline = (string) ($filters['deadline'] ?? '');
        if ($deadline === 'overdue') {
            $where[] = "t.deadline IS NOT NULL AND t.deadline < NOW() AND t.status IN ('new','in_progress','review')";
        } elseif ($deadline === 'today') {
            $where[] = 't.deadline IS NOT NULL AND DATE(t.deadline) = CURDATE()';
        } elseif ($deadline === 'week') {
            $where[] = 't.deadline IS NOT NULL AND t.deadline >= NOW() AND t.deadline < DATE_ADD(NOW(), INTERVAL 7 DAY)';
        } elseif ($deadline === 'upcoming') {
            $where[] = 't.deadline IS NOT NULL AND t.deadline >= NOW()';
        }

        $order = match ((string) ($filters['sort'] ?? 'priority')) {
            'deadline' => 't.deadline IS NULL ASC, t.deadline ASC, t.id DESC',
            'created' => 't.created_at DESC, t.id DESC',
            default => "FIELD(t.priority,'high','medium','low'), t.deadline IS NULL ASC, t.deadline ASC, t.id DESC",
        };

        $sql = "SELECT t.*,
                       a.name AS assignee_name, a.email AS assignee_email,
                       c.name AS creator_name
                FROM ops_tasks t
                LEFT JOIN users a ON a.id = t.assignee_id
                LEFT JOIN users c ON c.id = t.created_by
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$order}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function groupedByStatus(array $filters): array
    {
        $filters['status'] = '';
        $items = $this->search($filters);
        $grouped = [];
        foreach (self::STATUSES as $status) {
            $grouped[$status] = [];
        }
        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? 'new');
            if (!isset($grouped[$status])) {
                $grouped[$status] = [];
            }
            $grouped[$status][] = $item;
        }
        return $grouped;
    }

    public function findWithPeople(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*,
                    a.name AS assignee_name, a.email AS assignee_email,
                    c.name AS creator_name, c.email AS creator_email
             FROM ops_tasks t
             LEFT JOIN users a ON a.id = t.assignee_id
             LEFT JOIN users c ON c.id = t.created_by
             WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function createTask(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ops_tasks (title, description, priority, status, assignee_id, created_by, deadline)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['title'],
            $data['description'] !== '' ? $data['description'] : null,
            $data['priority'],
            $data['status'],
            $data['assignee_id'],
            $data['created_by'],
            $data['deadline'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function updateTask(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE ops_tasks
             SET title = ?, description = ?, priority = ?, status = ?, assignee_id = ?, deadline = ?,
                 deadline_notified_at = ?
             WHERE id = ?'
        );
        return $stmt->execute([
            $data['title'],
            $data['description'] !== '' ? $data['description'] : null,
            $data['priority'],
            $data['status'],
            $data['assignee_id'],
            $data['deadline'],
            $data['deadline_notified_at'] ?? null,
            $id,
        ]);
    }

    public function updateStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $stmt = $this->db->prepare('UPDATE ops_tasks SET status = ? WHERE id = ?');
        return $stmt->execute([$status, $id]);
    }

    public function markDeadlineNotified(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE ops_tasks SET deadline_notified_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function deleteTask(int $id): bool
    {
        $files = $this->filesForTask($id);
        $this->db->prepare('DELETE FROM ops_task_files WHERE task_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM ops_task_comments WHERE task_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM ops_task_events WHERE task_id = ?')->execute([$id]);
        $ok = $this->db->prepare('DELETE FROM ops_tasks WHERE id = ?')->execute([$id]);
        foreach ($files as $file) {
            $this->unlinkStored((string) $file['stored_name']);
        }
        return $ok;
    }

    public function addComment(int $taskId, int $userId, string $body): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ops_task_comments (task_id, user_id, body) VALUES (?, ?, ?)'
        );
        $stmt->execute([$taskId, $userId, $body]);
        return (int) $this->db->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function comments(int $taskId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*, u.name AS user_name
             FROM ops_task_comments c
             LEFT JOIN users u ON u.id = c.user_id
             WHERE c.task_id = ?
             ORDER BY c.id ASC'
        );
        $stmt->execute([$taskId]);
        return $stmt->fetchAll();
    }

    public function addEvent(int $taskId, ?int $userId, string $type, ?array $meta = null): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ops_task_events (task_id, user_id, event_type, meta_json) VALUES (?, ?, ?, ?)'
        );
        $json = $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
        $stmt->execute([$taskId, $userId, $type, $json]);
    }

    /** @return list<array<string, mixed>> */
    public function events(int $taskId): array
    {
        $stmt = $this->db->prepare(
            'SELECT e.*, u.name AS user_name
             FROM ops_task_events e
             LEFT JOIN users u ON u.id = e.user_id
             WHERE e.task_id = ?
             ORDER BY e.id ASC'
        );
        $stmt->execute([$taskId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $meta = $row['meta_json'] ?? null;
            $row['meta'] = is_string($meta) && $meta !== '' ? json_decode($meta, true) : [];
            if (!is_array($row['meta'])) {
                $row['meta'] = [];
            }
        }
        unset($row);
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function filesForTask(int $taskId, ?int $commentId = null): array
    {
        if ($commentId === null) {
            $stmt = $this->db->prepare(
                'SELECT * FROM ops_task_files WHERE task_id = ? ORDER BY id ASC'
            );
            $stmt->execute([$taskId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM ops_task_files WHERE task_id = ? AND comment_id = ? ORDER BY id ASC'
            );
            $stmt->execute([$taskId, $commentId]);
        }
        return $stmt->fetchAll();
    }

    public function findFile(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ops_task_files WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function addFile(int $taskId, ?int $commentId, string $original, string $stored, ?string $mime, int $size): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ops_task_files (task_id, comment_id, original_name, stored_name, mime, size_bytes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$taskId, $commentId, $original, $stored, $mime, $size]);
        return (int) $this->db->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function dueSoonUnnotified(): array
    {
        return $this->db->query(
            "SELECT t.*, a.name AS assignee_name, a.email AS assignee_email
             FROM ops_tasks t
             LEFT JOIN users a ON a.id = t.assignee_id
             WHERE t.deadline IS NOT NULL
               AND t.deadline_notified_at IS NULL
               AND t.deadline > NOW()
               AND t.deadline <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
               AND t.status IN ('new','in_progress','review')"
        )->fetchAll();
    }

    public function storageDir(): string
    {
        return dirname(__DIR__, 2) . '/public/uploads/ops-tasks';
    }

    public function storedPath(string $storedName): ?string
    {
        $safe = basename($storedName);
        if ($safe === '' || $safe !== $storedName || !preg_match('/^f_\d{14}_[a-f0-9]+\.[a-z0-9]+$/i', $safe)) {
            return null;
        }
        $path = $this->storageDir() . DIRECTORY_SEPARATOR . $safe;
        return is_file($path) ? $path : null;
    }

    private function unlinkStored(string $storedName): void
    {
        $path = $this->storedPath($storedName);
        if ($path !== null) {
            @unlink($path);
        }
    }
}
