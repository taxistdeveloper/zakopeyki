<?php

namespace App\Models;

use App\Core\Model;

class Stream extends Model
{
    protected string $table = 'streams';
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
            "CREATE TABLE IF NOT EXISTS streams (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                title VARCHAR(200) NOT NULL,
                description VARCHAR(500) DEFAULT NULL,
                video_url VARCHAR(500) DEFAULT NULL,
                video_file VARCHAR(255) DEFAULT NULL,
                cover VARCHAR(255) DEFAULT NULL,
                is_live TINYINT(1) NOT NULL DEFAULT 0,
                last_heartbeat DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_live (is_live),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Миграция для уже существующей таблицы
        $cols = $this->db->query('SHOW COLUMNS FROM streams LIKE "last_heartbeat"')->fetch();
        if (!$cols) {
            $this->db->exec('ALTER TABLE streams ADD COLUMN last_heartbeat DATETIME DEFAULT NULL AFTER is_live');
        }

        self::$ensured = true;
    }

    /** Только живые стримы (без сохранённых видосов) */
    public function allActive(int $limit = 24): array
    {
        $this->purgeStaleLive();

        $stmt = $this->db->prepare(
            "SELECT s.*, u.name AS author_name, u.avatar AS author_avatar
             FROM streams s
             JOIN users u ON u.id = s.user_id
             WHERE s.is_live = 1
               AND (s.video_file IS NULL OR s.video_file = '')
               AND s.last_heartbeat >= (NOW() - INTERVAL 45 SECOND)
             ORDER BY s.created_at DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO streams (user_id, title, description, video_url, video_file, cover, is_live, last_heartbeat)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $isLive = !empty($data['is_live']);
        $stmt->execute([
            $data['user_id'],
            $data['title'],
            $data['description'] ?? null,
            $data['video_url'] ?? null,
            $data['video_file'] ?? null,
            $data['cover'] ?? null,
            $isLive ? 1 : 0,
            $isLive ? date('Y-m-d H:i:s') : null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function findActiveLiveByUser(int $userId): ?array
    {
        $this->purgeStaleLive();
        $stmt = $this->db->prepare(
            'SELECT * FROM streams
             WHERE user_id = ? AND is_live = 1 AND video_file IS NULL
               AND last_heartbeat >= (NOW() - INTERVAL 45 SECOND)
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function startLive(int $userId, string $title): int
    {
        // Один эфир на пользователя — закрываем старые
        $this->endAllLiveForUser($userId);

        return $this->create([
            'user_id' => $userId,
            'title' => $title,
            'description' => 'Прямой эфир — не сохраняется',
            'video_url' => null,
            'video_file' => null,
            'cover' => null,
            'is_live' => true,
        ]);
    }

    public function heartbeat(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE streams SET last_heartbeat = NOW()
             WHERE id = ? AND user_id = ? AND is_live = 1 AND video_file IS NULL'
        );
        return $stmt->execute([$id, $userId]);
    }

    public function endLive(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM streams WHERE id = ? AND user_id = ? AND is_live = 1 AND video_file IS NULL'
        );
        return $stmt->execute([$id, $userId]);
    }

    public function endAllLiveForUser(int $userId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM streams WHERE user_id = ? AND is_live = 1 AND video_file IS NULL'
        );
        $stmt->execute([$userId]);
    }

    /** Мёртвые эфиры (нет heartbeat) — удаляем, ничего не храним */
    public function purgeStaleLive(): void
    {
        $this->db->exec(
            'DELETE FROM streams
             WHERE is_live = 1
               AND video_file IS NULL
               AND (last_heartbeat IS NULL OR last_heartbeat < (NOW() - INTERVAL 45 SECOND))'
        );
    }
}
