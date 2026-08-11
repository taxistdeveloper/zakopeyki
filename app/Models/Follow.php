<?php

namespace App\Models;

use App\Core\Model;

class Follow extends Model
{
    protected string $table = 'user_follows';
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
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS user_follows (
                    follower_id INT UNSIGNED NOT NULL,
                    following_id INT UNSIGNED NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (follower_id, following_id),
                    INDEX idx_following (following_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable $e) {
            // таблица может уже существовать / нет прав CREATE — пробуем работать дальше
        }
        self::$ensured = true;
    }

    public function isFollowing(int $followerId, int $followingId): bool
    {
        if ($followerId <= 0 || $followingId <= 0 || $followerId === $followingId) {
            return false;
        }
        $stmt = $this->db->prepare(
            'SELECT 1 FROM user_follows WHERE follower_id = ? AND following_id = ? LIMIT 1'
        );
        $stmt->execute([$followerId, $followingId]);
        return (bool) $stmt->fetchColumn();
    }

    public function countFollowers(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM user_follows WHERE following_id = ?'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function countFollowing(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM user_follows WHERE follower_id = ?'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<int> */
    public function followerIds(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT follower_id FROM user_follows WHERE following_id = ?'
        );
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * На кого подписан пользователь.
     * @return list<array<string, mixed>>
     */
    public function followingUsers(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT u.id, u.name, u.login, u.email, u.avatar, u.avatar_file, u.bio, f.created_at AS followed_at
             FROM user_follows f
             JOIN users u ON u.id = f.following_id
             WHERE f.follower_id = ?
             ORDER BY f.created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Кто подписан на пользователя.
     * @return list<array<string, mixed>>
     */
    public function followerUsers(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT u.id, u.name, u.login, u.email, u.avatar, u.avatar_file, u.bio, f.created_at AS followed_at
             FROM user_follows f
             JOIN users u ON u.id = f.follower_id
             WHERE f.following_id = ?
             ORDER BY f.created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll() ?: [];
    }

    /** @return array{ok: bool, following: bool, followers_count: int, error?: string} */
    public function toggle(int $followerId, int $followingId): array
    {
        if ($followerId <= 0 || $followingId <= 0) {
            return ['ok' => false, 'following' => false, 'followers_count' => 0, 'error' => 'bad_user'];
        }
        if ($followerId === $followingId) {
            return [
                'ok' => false,
                'following' => false,
                'followers_count' => $this->countFollowers($followingId),
                'error' => 'self',
            ];
        }

        $user = (new User())->find($followingId);
        if (!$user) {
            return ['ok' => false, 'following' => false, 'followers_count' => 0, 'error' => 'not_found'];
        }

        if ($this->isFollowing($followerId, $followingId)) {
            $stmt = $this->db->prepare(
                'DELETE FROM user_follows WHERE follower_id = ? AND following_id = ?'
            );
            $stmt->execute([$followerId, $followingId]);
            return [
                'ok' => true,
                'following' => false,
                'followers_count' => $this->countFollowers($followingId),
            ];
        }

        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO user_follows (follower_id, following_id) VALUES (?, ?)'
        );
        $stmt->execute([$followerId, $followingId]);
        return [
            'ok' => true,
            'following' => true,
            'followers_count' => $this->countFollowers($followingId),
        ];
    }

    public function notifyFollowers(int $userId, string $message): int
    {
        $ids = $this->followerIds($userId);
        if ($ids === []) {
            return 0;
        }
        $notifications = new Notification();
        $count = 0;
        foreach ($ids as $followerId) {
            $notifications->createFor($followerId, $message);
            $count++;
        }
        return $count;
    }
}
