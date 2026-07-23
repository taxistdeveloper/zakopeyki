<?php

namespace App\Models;

use App\Core\Model;

class Chat extends Model
{
    protected string $table = 'chat_conversations';
    private static bool $ensured = false;

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
            "CREATE TABLE IF NOT EXISTS chat_conversations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_low_id INT UNSIGNED NOT NULL,
                user_high_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL DEFAULT 0,
                order_id INT UNSIGNED NOT NULL DEFAULT 0,
                last_message_at DATETIME DEFAULT NULL,
                last_preview VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_pair_product (user_low_id, user_high_id, product_id),
                INDEX idx_low (user_low_id),
                INDEX idx_high (user_high_id),
                INDEX idx_last (last_message_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS chat_messages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT UNSIGNED NOT NULL,
                sender_id INT UNSIGNED NOT NULL,
                body TEXT NOT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_conv (conversation_id),
                INDEX idx_sender (sender_id),
                INDEX idx_unread (conversation_id, is_read, sender_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        self::$ensured = true;
    }

    /** @return array{ok: bool, conversation_id?: int, error?: string} */
    public function start(int $meId, int $otherId, int $productId = 0, int $orderId = 0): array
    {
        if ($meId === $otherId) {
            return ['ok' => false, 'error' => t('chat.self')];
        }
        if ($otherId <= 0) {
            return ['ok' => false, 'error' => t('chat.user_not_found')];
        }

        $low = min($meId, $otherId);
        $high = max($meId, $otherId);
        $productId = max(0, $productId);
        $orderId = max(0, $orderId);

        $existing = $this->findPair($low, $high, $productId);
        if ($existing) {
            if ($orderId > 0 && (int) ($existing['order_id'] ?? 0) === 0) {
                $upd = $this->db->prepare('UPDATE chat_conversations SET order_id = ? WHERE id = ?');
                $upd->execute([$orderId, $existing['id']]);
            }
            return ['ok' => true, 'conversation_id' => (int) $existing['id']];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO chat_conversations (user_low_id, user_high_id, product_id, order_id, last_message_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        try {
            $stmt->execute([$low, $high, $productId, $orderId]);
            return ['ok' => true, 'conversation_id' => (int) $this->db->lastInsertId()];
        } catch (\Throwable $e) {
            $existing = $this->findPair($low, $high, $productId);
            if ($existing) {
                return ['ok' => true, 'conversation_id' => (int) $existing['id']];
            }
            return ['ok' => false, 'error' => t('chat.start_failed')];
        }
    }

    private function findPair(int $low, int $high, int $productId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM chat_conversations
             WHERE user_low_id = ? AND user_high_id = ? AND product_id = ?'
        );
        $stmt->execute([$low, $high, $productId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findForUser(int $conversationId, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*,
                    p.title AS product_title, p.image AS product_image, p.images AS product_images,
                    u_low.name AS low_name, u_low.avatar AS low_avatar, u_low.avatar_file AS low_avatar_file,
                    u_high.name AS high_name, u_high.avatar AS high_avatar, u_high.avatar_file AS high_avatar_file
             FROM chat_conversations c
             LEFT JOIN products p ON p.id = c.product_id AND c.product_id > 0
             JOIN users u_low ON u_low.id = c.user_low_id
             JOIN users u_high ON u_high.id = c.user_high_id
             WHERE c.id = ? AND (c.user_low_id = ? OR c.user_high_id = ?)'
        );
        $stmt->execute([$conversationId, $userId, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*,
                    p.title AS product_title, p.image AS product_image, p.images AS product_images,
                    CASE WHEN c.user_low_id = ? THEN u_high.name ELSE u_low.name END AS peer_name,
                    CASE WHEN c.user_low_id = ? THEN u_high.avatar ELSE u_low.avatar END AS peer_avatar,
                    CASE WHEN c.user_low_id = ? THEN u_high.avatar_file ELSE u_low.avatar_file END AS peer_avatar_file,
                    CASE WHEN c.user_low_id = ? THEN c.user_high_id ELSE c.user_low_id END AS peer_id,
                    (SELECT COUNT(*) FROM chat_messages m
                      WHERE m.conversation_id = c.id AND m.is_read = 0 AND m.sender_id <> ?) AS unread_count
             FROM chat_conversations c
             LEFT JOIN products p ON p.id = c.product_id AND c.product_id > 0
             JOIN users u_low ON u_low.id = c.user_low_id
             JOIN users u_high ON u_high.id = c.user_high_id
             WHERE c.user_low_id = ? OR c.user_high_id = ?
             ORDER BY COALESCE(c.last_message_at, c.created_at) DESC'
        );
        $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId]);
        return $stmt->fetchAll();
    }

    public function unreadCount(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM chat_messages m
             JOIN chat_conversations c ON c.id = m.conversation_id
             WHERE m.is_read = 0 AND m.sender_id <> ?
               AND (c.user_low_id = ? OR c.user_high_id = ?)'
        );
        $stmt->execute([$userId, $userId, $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array> */
    public function messages(int $conversationId, int $afterId = 0, int $limit = 100): array
    {
        if ($afterId > 0) {
            $stmt = $this->db->prepare(
                'SELECT m.*, u.name AS sender_name, u.avatar AS sender_avatar, u.avatar_file AS sender_avatar_file
                 FROM chat_messages m
                 JOIN users u ON u.id = m.sender_id
                 WHERE m.conversation_id = ? AND m.id > ?
                 ORDER BY m.id ASC
                 LIMIT ?'
            );
            $stmt->bindValue(1, $conversationId, \PDO::PARAM_INT);
            $stmt->bindValue(2, $afterId, \PDO::PARAM_INT);
            $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }

        $stmt = $this->db->prepare(
            'SELECT m.*, u.name AS sender_name, u.avatar AS sender_avatar, u.avatar_file AS sender_avatar_file
             FROM chat_messages m
             JOIN users u ON u.id = m.sender_id
             WHERE m.conversation_id = ?
             ORDER BY m.id DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $conversationId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        return array_reverse($rows);
    }

    /** @return array{ok: bool, message?: array, error?: string} */
    public function send(int $conversationId, int $senderId, string $body): array
    {
        $conv = $this->findForUser($conversationId, $senderId);
        if (!$conv) {
            return ['ok' => false, 'error' => t('chat.forbidden')];
        }

        $body = trim($body);
        if ($body === '') {
            return ['ok' => false, 'error' => t('chat.empty')];
        }
        if (mb_strlen($body) > 2000) {
            return ['ok' => false, 'error' => t('chat.too_long')];
        }

        $preview = mb_substr($body, 0, 120);

        $stmt = $this->db->prepare(
            'INSERT INTO chat_messages (conversation_id, sender_id, body, is_read) VALUES (?, ?, ?, 0)'
        );
        $stmt->execute([$conversationId, $senderId, $body]);
        $messageId = (int) $this->db->lastInsertId();

        $upd = $this->db->prepare(
            'UPDATE chat_conversations SET last_message_at = NOW(), last_preview = ? WHERE id = ?'
        );
        $upd->execute([$preview, $conversationId]);

        $peerId = (int) $conv['user_low_id'] === $senderId
            ? (int) $conv['user_high_id']
            : (int) $conv['user_low_id'];

        $senderName = (int) $conv['user_low_id'] === $senderId
            ? (string) $conv['low_name']
            : (string) $conv['high_name'];
        $label = (string) ($conv['product_title'] ?? '');
        $notice = $label !== ''
            ? t('chat.notify_about', ['name' => $senderName, 'title' => $label])
            : t('chat.notify', ['name' => $senderName]);

        (new Notification())->createFor($peerId, $notice);

        $msgStmt = $this->db->prepare(
            'SELECT m.*, u.name AS sender_name, u.avatar AS sender_avatar, u.avatar_file AS sender_avatar_file
             FROM chat_messages m
             JOIN users u ON u.id = m.sender_id
             WHERE m.id = ?'
        );
        $msgStmt->execute([$messageId]);
        $message = $msgStmt->fetch() ?: null;

        return ['ok' => true, 'message' => $message];
    }

    public function markRead(int $conversationId, int $readerId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE chat_messages SET is_read = 1
             WHERE conversation_id = ? AND sender_id <> ? AND is_read = 0'
        );
        $stmt->execute([$conversationId, $readerId]);
    }
}
