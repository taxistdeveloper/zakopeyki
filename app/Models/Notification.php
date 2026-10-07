<?php

namespace App\Models;

use App\Core\Model;

class Notification extends Model
{
    protected string $table = 'notifications';

    private static bool $linkEnsured = false;

    public function __construct()
    {
        parent::__construct();
        $this->ensureLinkColumn();
    }

    private function ensureLinkColumn(): void
    {
        if (self::$linkEnsured) {
            return;
        }
        try {
            $exists = $this->db->query("SHOW COLUMNS FROM notifications LIKE 'link'")->fetch();
            if (!$exists) {
                $this->db->exec('ALTER TABLE notifications ADD COLUMN link VARCHAR(255) NULL DEFAULT NULL AFTER message');
            }
        } catch (\Throwable) {
            // ignore
        }
        self::$linkEnsured = true;
    }

    private function hasLinkColumn(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        try {
            $has = (bool) $this->db->query("SHOW COLUMNS FROM notifications LIKE 'link'")->fetch();
        } catch (\Throwable) {
            $has = false;
        }
        return $has;
    }

    public function forUser(int $userId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function unreadCount(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function markAllRead(int $userId): void
    {
        $stmt = $this->db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
        $stmt->execute([$userId]);
    }

    public function clearAll(int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM notifications WHERE user_id = ?');
        $stmt->execute([$userId]);
    }

    public function createFor(int $userId, string $message, ?string $link = null): void
    {
        if ($link !== null && $this->hasLinkColumn()) {
            $stmt = $this->db->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)');
            $stmt->execute([$userId, $message, $link]);
            return;
        }
        $stmt = $this->db->prepare('INSERT INTO notifications (user_id, message) VALUES (?, ?)');
        $stmt->execute([$userId, $message]);
    }
}
