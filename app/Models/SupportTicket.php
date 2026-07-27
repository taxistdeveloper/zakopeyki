<?php

namespace App\Models;

use App\Core\Model;

class SupportTicket extends Model
{
    protected string $table = 'support_tickets';
    private static bool $ensured = false;

    public const CATEGORIES = ['general', 'idea', 'payment', 'deal', 'technical', 'other'];
    public const STATUSES = ['open', 'answered', 'closed'];

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
            "CREATE TABLE IF NOT EXISTS support_tickets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ticket_number VARCHAR(32) NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                subject VARCHAR(200) NOT NULL,
                category VARCHAR(32) NOT NULL DEFAULT 'general',
                status ENUM('open', 'answered', 'closed') NOT NULL DEFAULT 'open',
                last_message_at DATETIME DEFAULT NULL,
                last_preview VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_ticket_number (ticket_number),
                INDEX idx_user (user_id),
                INDEX idx_status (status),
                INDEX idx_last (last_message_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS support_messages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT UNSIGNED NOT NULL,
                sender_type ENUM('user', 'admin', 'system') NOT NULL,
                sender_id INT UNSIGNED DEFAULT NULL,
                body TEXT NOT NULL,
                is_read_by_user TINYINT(1) NOT NULL DEFAULT 0,
                is_read_by_admin TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ticket (ticket_id),
                INDEX idx_unread_user (ticket_id, is_read_by_user, sender_type),
                INDEX idx_unread_admin (ticket_id, is_read_by_admin, sender_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        self::$ensured = true;
    }

    public function generateNumber(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $number = 'ZK-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
            $stmt = $this->db->prepare('SELECT id FROM support_tickets WHERE ticket_number = ? LIMIT 1');
            $stmt->execute([$number]);
            if (!$stmt->fetch()) {
                return $number;
            }
        }

        return 'ZK-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 4));
    }

    /** @return array{ok: bool, ticket_id?: int, ticket_number?: string, error?: string} */
    public function createTicket(int $userId, string $subject, string $body, string $category = 'general'): array
    {
        $subject = trim($subject);
        $body = trim($body);
        $category = strtolower(trim($category));

        if ($subject === '') {
            return ['ok' => false, 'error' => t('support.subject_required')];
        }
        if (mb_strlen($subject) > 200) {
            return ['ok' => false, 'error' => t('support.subject_too_long')];
        }
        if ($body === '') {
            return ['ok' => false, 'error' => t('support.empty')];
        }
        if (mb_strlen($body) > 4000) {
            return ['ok' => false, 'error' => t('support.too_long')];
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = 'general';
        }

        $number = $this->generateNumber();
        $preview = mb_substr($body, 0, 120);

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare(
                'INSERT INTO support_tickets
                    (ticket_number, user_id, subject, category, status, last_message_at, last_preview)
                 VALUES (?, ?, ?, ?, \'open\', NOW(), ?)'
            );
            $stmt->execute([$number, $userId, $subject, $category, $preview]);
            $ticketId = (int) $this->db->lastInsertId();

            $this->insertMessage($ticketId, 'user', $userId, $body, true, true);

            $autoReply = t('support.auto_reply', ['number' => $number]);
            $this->insertMessage($ticketId, 'system', null, $autoReply, true, true);

            $upd = $this->db->prepare(
                'UPDATE support_tickets SET last_message_at = NOW(), last_preview = ? WHERE id = ?'
            );
            $upd->execute([mb_substr($autoReply, 0, 120), $ticketId]);

            $this->db->commit();

            return ['ok' => true, 'ticket_id' => $ticketId, 'ticket_number' => $number];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['ok' => false, 'error' => t('support.create_failed')];
        }
    }

    private function insertMessage(
        int $ticketId,
        string $senderType,
        ?int $senderId,
        string $body,
        bool $readByUser,
        bool $readByAdmin
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO support_messages
                (ticket_id, sender_type, sender_id, body, is_read_by_user, is_read_by_admin)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $ticketId,
            $senderType,
            $senderId,
            $body,
            $readByUser ? 1 : 0,
            $readByAdmin ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findForUser(int $ticketId, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*, u.name AS user_name, u.email AS user_email, u.avatar, u.avatar_file
             FROM support_tickets t
             JOIN users u ON u.id = t.user_id
             WHERE t.id = ? AND t.user_id = ?'
        );
        $stmt->execute([$ticketId, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findForAdmin(int $ticketId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*, u.name AS user_name, u.email AS user_email, u.avatar, u.avatar_file
             FROM support_tickets t
             JOIN users u ON u.id = t.user_id
             WHERE t.id = ?'
        );
        $stmt->execute([$ticketId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*,
                    (SELECT COUNT(*) FROM support_messages m
                     WHERE m.ticket_id = t.id
                       AND m.is_read_by_user = 0
                       AND m.sender_type IN (\'admin\', \'system\')) AS unread_count
             FROM support_tickets t
             WHERE t.user_id = ?
             ORDER BY COALESCE(t.last_message_at, t.created_at) DESC, t.id DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function listAll(?string $status = null): array
    {
        $sql = 'SELECT t.*, u.name AS user_name, u.email AS user_email,
                       (SELECT COUNT(*) FROM support_messages m
                        WHERE m.ticket_id = t.id
                          AND m.is_read_by_admin = 0
                          AND m.sender_type = \'user\') AS unread_count
                FROM support_tickets t
                JOIN users u ON u.id = t.user_id';
        $params = [];

        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $sql .= ' WHERE t.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY COALESCE(t.last_message_at, t.created_at) DESC, t.id DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function openCount(): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) FROM support_tickets WHERE status IN ('open', 'answered')"
        );
        return (int) $stmt->fetchColumn();
    }

    public function messages(int $ticketId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll();
    }

    public function markReadByUser(int $ticketId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE support_messages SET is_read_by_user = 1
             WHERE ticket_id = ? AND is_read_by_user = 0 AND sender_type IN (\'admin\', \'system\')'
        );
        $stmt->execute([$ticketId]);
    }

    public function markReadByAdmin(int $ticketId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE support_messages SET is_read_by_admin = 1
             WHERE ticket_id = ? AND is_read_by_admin = 0 AND sender_type = \'user\''
        );
        $stmt->execute([$ticketId]);
    }

    public function unreadCountForUser(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM support_messages m
             JOIN support_tickets t ON t.id = m.ticket_id
             WHERE t.user_id = ?
               AND m.is_read_by_user = 0
               AND m.sender_type IN (\'admin\', \'system\')'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function unreadCountForAdmin(): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) FROM support_messages m
             WHERE m.is_read_by_admin = 0 AND m.sender_type = 'user'"
        );
        return (int) $stmt->fetchColumn();
    }

    /** @return array{ok: bool, message?: array, error?: string} */
    public function replyAsUser(int $ticketId, int $userId, string $body): array
    {
        $ticket = $this->findForUser($ticketId, $userId);
        if (!$ticket) {
            return ['ok' => false, 'error' => t('support.not_found')];
        }
        if ($ticket['status'] === 'closed') {
            return ['ok' => false, 'error' => t('support.closed')];
        }

        $body = trim($body);
        if ($body === '') {
            return ['ok' => false, 'error' => t('support.empty')];
        }
        if (mb_strlen($body) > 4000) {
            return ['ok' => false, 'error' => t('support.too_long')];
        }

        $msgId = $this->insertMessage($ticketId, 'user', $userId, $body, true, false);
        $upd = $this->db->prepare(
            'UPDATE support_tickets
             SET status = \'open\', last_message_at = NOW(), last_preview = ?
             WHERE id = ?'
        );
        $upd->execute([mb_substr($body, 0, 120), $ticketId]);

        return ['ok' => true, 'message' => $this->messageById($msgId)];
    }

    /** @return array{ok: bool, message?: array, error?: string} */
    public function replyAsAdmin(int $ticketId, int $adminId, string $body): array
    {
        $ticket = $this->findForAdmin($ticketId);
        if (!$ticket) {
            return ['ok' => false, 'error' => t('support.not_found')];
        }
        if ($ticket['status'] === 'closed') {
            return ['ok' => false, 'error' => t('support.closed')];
        }

        $body = trim($body);
        if ($body === '') {
            return ['ok' => false, 'error' => t('support.empty')];
        }
        if (mb_strlen($body) > 4000) {
            return ['ok' => false, 'error' => t('support.too_long')];
        }

        $msgId = $this->insertMessage($ticketId, 'admin', $adminId, $body, false, true);
        $upd = $this->db->prepare(
            'UPDATE support_tickets
             SET status = \'answered\', last_message_at = NOW(), last_preview = ?
             WHERE id = ?'
        );
        $upd->execute([mb_substr($body, 0, 120), $ticketId]);

        return ['ok' => true, 'message' => $this->messageById($msgId)];
    }

    public function close(int $ticketId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE support_tickets SET status = \'closed\', updated_at = NOW() WHERE id = ?'
        );
        return $stmt->execute([$ticketId]);
    }

    public function reopen(int $ticketId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE support_tickets SET status = \'open\', updated_at = NOW() WHERE id = ? AND status = \'closed\''
        );
        return $stmt->execute([$ticketId]);
    }

    /** @return list<array{id:int,name:string,email:string}> */
    public function adminUsers(): array
    {
        $rows = $this->db->query(
            "SELECT id, name, email FROM users WHERE role = 'admin' ORDER BY id ASC"
        )->fetchAll();

        return array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'email' => (string) $r['email'],
            ];
        }, $rows);
    }

    private function messageById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM support_messages WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
