<?php

namespace App\Models;

use App\Core\Model;
use PDO;

/**
 * Диалоги и сообщения локального AI-саппорта.
 * Таблицы: ai_conversations, ai_messages (+ ensure связанных таблиц).
 */
class AiSupport extends Model
{
    protected string $table = 'ai_conversations';
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
            "CREATE TABLE IF NOT EXISTS ai_conversations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                guest_token VARCHAR(64) NULL,
                status ENUM('ai_active', 'human_escalated', 'closed') NOT NULL DEFAULT 'ai_active',
                assigned_agent_id INT UNSIGNED NULL,
                last_message_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_ai_conv_user (user_id),
                INDEX idx_ai_conv_guest (guest_token),
                INDEX idx_ai_conv_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS ai_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                conversation_id BIGINT UNSIGNED NOT NULL,
                sender_type ENUM('user', 'ai', 'agent', 'system') NOT NULL,
                sender_id INT UNSIGNED NULL,
                message TEXT NOT NULL,
                confidence_score DECIMAL(4,3) NULL,
                meta_json JSON NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ai_msg_conv (conversation_id),
                INDEX idx_ai_msg_created (created_at),
                CONSTRAINT fk_ai_messages_conversation
                  FOREIGN KEY (conversation_id) REFERENCES ai_conversations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS ai_intent_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                message_id BIGINT UNSIGNED NOT NULL,
                detected_intent VARCHAR(64) NOT NULL,
                confidence DECIMAL(4,3) NOT NULL DEFAULT 0.000,
                method VARCHAR(32) NULL,
                raw_prompt TEXT NULL,
                raw_response TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ai_intent_msg (message_id),
                CONSTRAINT fk_ai_intent_message
                  FOREIGN KEY (message_id) REFERENCES ai_messages (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS ai_feedback (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                message_id BIGINT UNSIGNED NOT NULL,
                rating TINYINT NOT NULL,
                comment TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ai_feedback_rating (rating),
                CONSTRAINT fk_ai_feedback_message
                  FOREIGN KEY (message_id) REFERENCES ai_messages (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS ai_few_shots (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                category VARCHAR(64) NOT NULL DEFAULT 'general',
                user_query TEXT NOT NULL,
                operator_response TEXT NOT NULL,
                quality_score DECIMAL(3,2) NOT NULL DEFAULT 1.00,
                is_approved TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FULLTEXT KEY ft_ai_few_shot_search (user_query, operator_response)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS ai_training_datasets (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                conversation_id BIGINT UNSIGNED NOT NULL,
                system_prompt TEXT NOT NULL,
                user_input TEXT NOT NULL,
                ideal_output TEXT NOT NULL,
                source ENUM('operator_resolution', 'high_csat_ai') NOT NULL,
                is_exported TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ai_dataset_exported (is_exported)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$ensured = true;
    }

    public function getOrCreateConversation(?int $userId, ?string $guestToken): array
    {
        if ($userId === null && ($guestToken === null || $guestToken === '')) {
            throw new \InvalidArgumentException('Нужен user_id или guest_token');
        }

        if ($userId !== null) {
            $stmt = $this->db->prepare(
                "SELECT * FROM ai_conversations
                 WHERE user_id = ? AND status != 'closed'
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$userId]);
        } else {
            $stmt = $this->db->prepare(
                "SELECT * FROM ai_conversations
                 WHERE guest_token = ? AND status != 'closed'
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$guestToken]);
        }

        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }

        $ins = $this->db->prepare(
            'INSERT INTO ai_conversations (user_id, guest_token, status, last_message_at)
             VALUES (?, ?, \'ai_active\', NOW())'
        );
        $ins->execute([$userId, $guestToken]);

        return $this->getConversationById((int) $this->db->lastInsertId());
    }

    public function getConversationById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ai_conversations WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function addMessage(
        int $conversationId,
        string $senderType,
        string $message,
        ?float $confidenceScore = null,
        ?int $senderId = null,
        ?array $meta = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO ai_messages
             (conversation_id, sender_type, sender_id, message, confidence_score, meta_json)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $conversationId,
            $senderType,
            $senderId,
            $message,
            $confidenceScore,
            $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);

        $id = (int) $this->db->lastInsertId();

        $upd = $this->db->prepare(
            'UPDATE ai_conversations SET last_message_at = NOW() WHERE id = ?'
        );
        $upd->execute([$conversationId]);

        return $id;
    }

    public function updateStatus(int $conversationId, string $status, ?int $agentId = null): bool
    {
        if ($agentId !== null) {
            $stmt = $this->db->prepare(
                'UPDATE ai_conversations SET status = ?, assigned_agent_id = ? WHERE id = ?'
            );
            return $stmt->execute([$status, $agentId, $conversationId]);
        }

        $stmt = $this->db->prepare('UPDATE ai_conversations SET status = ? WHERE id = ?');
        return $stmt->execute([$status, $conversationId]);
    }

    public function assignAgent(int $conversationId, int $agentId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE ai_conversations
             SET status = 'human_escalated', assigned_agent_id = ?
             WHERE id = ?"
        );
        return $stmt->execute([$agentId, $conversationId]);
    }

    /** @return list<array> */
    public function getMessages(int $conversationId, int $limit = 100, int $afterId = 0): array
    {
        $limit = max(1, min(200, $limit));
        if ($afterId > 0) {
            $stmt = $this->db->prepare(
                "SELECT * FROM ai_messages
                 WHERE conversation_id = ? AND id > ?
                 ORDER BY id ASC LIMIT {$limit}"
            );
            $stmt->execute([$conversationId, $afterId]);
        } else {
            $stmt = $this->db->prepare(
                "SELECT * FROM ai_messages
                 WHERE conversation_id = ?
                 ORDER BY id ASC LIMIT {$limit}"
            );
            $stmt->execute([$conversationId]);
        }

        return $stmt->fetchAll() ?: [];
    }

    public function getMessageById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ai_messages WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function logIntent(
        int $messageId,
        string $intent,
        float $confidence,
        ?string $method = null,
        ?string $rawPrompt = null,
        ?string $rawResponse = null
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO ai_intent_logs
             (message_id, detected_intent, confidence, method, raw_prompt, raw_response)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $messageId,
            $intent,
            $confidence,
            $method,
            $rawPrompt,
            $rawResponse,
        ]);
    }

    /** @return list<array> */
    public function listForAdmin(?string $status = null, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT c.*,
                       (SELECT message FROM ai_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_preview,
                       u.name AS user_name, u.email AS user_email
                FROM ai_conversations c
                LEFT JOIN users u ON u.id = c.user_id';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE c.status = ?';
            $params[] = $status;
        }
        $sql .= " ORDER BY COALESCE(c.last_message_at, c.updated_at) DESC LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    public function countByStatus(string $status): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM ai_conversations WHERE status = ?');
        $stmt->execute([$status]);
        return (int) $stmt->fetchColumn();
    }

    public function countEscalated(): int
    {
        return $this->countByStatus('human_escalated');
    }

    public function pdo(): PDO
    {
        return $this->db;
    }
}
