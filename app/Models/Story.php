<?php

namespace App\Models;

use App\Core\Model;

class Story extends Model
{
    protected string $table = 'stories';
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
            "CREATE TABLE IF NOT EXISTS stories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                caption VARCHAR(280) DEFAULT NULL,
                image VARCHAR(255) DEFAULT NULL,
                bg_color VARCHAR(20) NOT NULL DEFAULT '#f59e0b',
                emoji VARCHAR(16) DEFAULT '✨',
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        foreach ([
            'audience' => "VARCHAR(20) NOT NULL DEFAULT 'all'",
            'comments_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'visibility' => "VARCHAR(20) NOT NULL DEFAULT 'public'",
            'attach_product' => 'TINYINT(1) NOT NULL DEFAULT 1',
        ] as $col => $def) {
            $exists = $this->db->query('SHOW COLUMNS FROM stories LIKE ' . $this->db->quote($col))->fetch();
            if (!$exists) {
                $this->db->exec("ALTER TABLE stories ADD COLUMN {$col} {$def}");
            }
        }
        self::$ensured = true;
    }

    /** Активные истории, сгруппированные по пользователю (последние 24ч) */
    public function activeGrouped(?int $viewerId = null): array
    {
        $stmt = $this->db->query(
            "SELECT s.*, u.name AS user_name, u.avatar AS user_avatar, u.avatar_file AS user_avatar_file
             FROM stories s
             JOIN users u ON u.id = s.user_id
             WHERE s.expires_at > NOW()
             ORDER BY s.created_at DESC"
        );
        $rows = $stmt->fetchAll();

        $followingIds = [];
        if ($viewerId) {
            new Follow();
            $followStmt = $this->db->prepare('SELECT following_id FROM user_follows WHERE follower_id = ?');
            $followStmt->execute([$viewerId]);
            $followingIds = array_fill_keys(array_map('intval', $followStmt->fetchAll(\PDO::FETCH_COLUMN)), true);
        }

        $groups = [];
        foreach ($rows as $row) {
            if (!$this->viewerCanSee($row, $viewerId, $followingIds)) {
                continue;
            }
            $uid = (int) $row['user_id'];
            if (!isset($groups[$uid])) {
                $groups[$uid] = [
                    'user_id' => $uid,
                    'user_name' => $row['user_name'],
                    'user_avatar' => $row['user_avatar'] ?: mb_strtoupper(mb_substr($row['user_name'], 0, 1)),
                    'user_avatar_file' => $row['user_avatar_file'] ?? null,
                    'stories' => [],
                ];
            }
            $groups[$uid]['stories'][] = $row;
        }

        // В ленте группы — по свежести; внутри группы — от старой к новой
        foreach ($groups as &$group) {
            $group['stories'] = array_reverse($group['stories']);
            $group['product'] = $this->latestProductForUser((int) $group['user_id']);
        }
        unset($group);

        return array_values($groups);
    }

    /** Последнее активное объявление автора — для стикера в сторис */
    private function latestProductForUser(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, title, price, type, price_label, current_bid, image, images, exchange_for
             FROM products
             WHERE user_id = ? AND status = 'active'
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array<string, mixed> $row @param array<int, true> $followingIds */
    private function viewerCanSee(array $row, ?int $viewerId, array $followingIds): bool
    {
        $authorId = (int) ($row['user_id'] ?? 0);
        if ($viewerId !== null && $viewerId > 0 && $viewerId === $authorId) {
            return true;
        }
        if ((string) ($row['visibility'] ?? 'public') === 'private') {
            return false;
        }
        if ((string) ($row['audience'] ?? 'all') === 'followers') {
            return $viewerId !== null && isset($followingIds[$authorId]);
        }
        return true;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO stories (user_id, caption, image, bg_color, emoji, audience, comments_enabled, visibility, attach_product, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
        );
        $stmt->execute([
            $data['user_id'],
            $data['caption'] ?? null,
            $data['image'] ?? null,
            $data['bg_color'] ?? '#f59e0b',
            $data['emoji'] ?? '✨',
            $data['audience'] ?? 'all',
            array_key_exists('comments_enabled', $data) ? (!empty($data['comments_enabled']) ? 1 : 0) : 1,
            $data['visibility'] ?? 'public',
            array_key_exists('attach_product', $data) ? (!empty($data['attach_product']) ? 1 : 0) : 1,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function byUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM stories WHERE user_id = ? AND expires_at > NOW() ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
}
