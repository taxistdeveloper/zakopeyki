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

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS stream_signals (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                stream_id INT UNSIGNED NOT NULL,
                peer_id VARCHAR(64) NOT NULL,
                direction ENUM('to_host','to_viewer') NOT NULL,
                type VARCHAR(16) NOT NULL,
                payload MEDIUMTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_host_poll (stream_id, direction, id),
                INDEX idx_viewer_poll (stream_id, peer_id, direction, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

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
        $ok = $stmt->execute([$id, $userId]);
        if ($ok && $stmt->rowCount() > 0) {
            $this->clearSignals($id);
        }
        return $ok;
    }

    public function endAllLiveForUser(int $userId): void
    {
        $ids = $this->db->prepare(
            'SELECT id FROM streams WHERE user_id = ? AND is_live = 1 AND video_file IS NULL'
        );
        $ids->execute([$userId]);
        foreach ($ids->fetchAll(\PDO::FETCH_COLUMN) as $sid) {
            $this->clearSignals((int) $sid);
        }

        $stmt = $this->db->prepare(
            'DELETE FROM streams WHERE user_id = ? AND is_live = 1 AND video_file IS NULL'
        );
        $stmt->execute([$userId]);
    }

    /** Мёртвые эфиры (нет heartbeat) — удаляем, ничего не храним */
    public function purgeStaleLive(): void
    {
        $stale = $this->db->query(
            'SELECT id FROM streams
             WHERE is_live = 1
               AND video_file IS NULL
               AND (last_heartbeat IS NULL OR last_heartbeat < (NOW() - INTERVAL 45 SECOND))'
        )->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($stale as $sid) {
            $this->clearSignals((int) $sid);
        }

        $this->db->exec(
            'DELETE FROM streams
             WHERE is_live = 1
               AND video_file IS NULL
               AND (last_heartbeat IS NULL OR last_heartbeat < (NOW() - INTERVAL 45 SECOND))'
        );
    }

    public function isLiveOwned(int $streamId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM streams
             WHERE id = ? AND user_id = ? AND is_live = 1 AND video_file IS NULL
             LIMIT 1'
        );
        $stmt->execute([$streamId, $userId]);
        return (bool) $stmt->fetch();
    }

    public function isLiveActive(int $streamId): bool
    {
        $this->purgeStaleLive();
        $stmt = $this->db->prepare(
            'SELECT id FROM streams
             WHERE id = ? AND is_live = 1 AND video_file IS NULL
               AND last_heartbeat >= (NOW() - INTERVAL 45 SECOND)
             LIMIT 1'
        );
        $stmt->execute([$streamId]);
        return (bool) $stmt->fetch();
    }

    public function pushSignal(int $streamId, string $peerId, string $direction, string $type, ?array $payload = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO stream_signals (stream_id, peer_id, direction, type, payload)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $streamId,
            $peerId,
            $direction,
            $type,
            $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @return list<array{id:int,peer_id:string,type:string,payload:?array}> */
    public function pollSignals(int $streamId, string $direction, int $afterId, ?string $peerId = null): array
    {
        if ($direction === 'to_viewer' && $peerId !== null && $peerId !== '') {
            $stmt = $this->db->prepare(
                'SELECT id, peer_id, type, payload FROM stream_signals
                 WHERE stream_id = ? AND direction = ? AND peer_id = ? AND id > ?
                 ORDER BY id ASC LIMIT 100'
            );
            $stmt->execute([$streamId, $direction, $peerId, $afterId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT id, peer_id, type, payload FROM stream_signals
                 WHERE stream_id = ? AND direction = ? AND id > ?
                 ORDER BY id ASC LIMIT 100'
            );
            $stmt->execute([$streamId, $direction, $afterId]);
        }

        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $payload = null;
            if (!empty($row['payload'])) {
                $decoded = json_decode((string) $row['payload'], true);
                $payload = is_array($decoded) ? $decoded : null;
            }
            $out[] = [
                'id' => (int) $row['id'],
                'peer_id' => (string) $row['peer_id'],
                'type' => (string) $row['type'],
                'payload' => $payload,
            ];
        }
        return $out;
    }

    public function clearSignals(int $streamId): void
    {
        $stmt = $this->db->prepare('DELETE FROM stream_signals WHERE stream_id = ?');
        $stmt->execute([$streamId]);
    }

    public function clearPeerSignals(int $streamId, string $peerId): void
    {
        $stmt = $this->db->prepare('DELETE FROM stream_signals WHERE stream_id = ? AND peer_id = ?');
        $stmt->execute([$streamId, $peerId]);
    }
}
