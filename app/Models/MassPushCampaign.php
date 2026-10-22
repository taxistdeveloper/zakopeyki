<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Services\MassPush\MassPushPolicy;

class MassPushCampaign extends Model
{
    protected string $table = 'mass_push_campaigns';

    private static bool $ensured = false;

    public function __construct()
    {
        parent::__construct();
        $this->ensureTables();
    }

    public static function schemaSql(): string
    {
        return "CREATE TABLE IF NOT EXISTS mass_push_campaigns (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(32) NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(80) NOT NULL,
            body VARCHAR(320) NOT NULL,
            target_url VARCHAR(255) DEFAULT NULL,
            amount INT UNSIGNED NOT NULL,
            audience_count INT UNSIGNED NOT NULL DEFAULT 0,
            accepted_count INT UNSIGNED NOT NULL DEFAULT 0,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL,
            reject_reason VARCHAR(255) DEFAULT NULL,
            idempotency_key VARCHAR(64) DEFAULT NULL,
            pg_order_id VARCHAR(64) DEFAULT NULL,
            pg_payment_id VARCHAR(64) DEFAULT NULL,
            redirect_url TEXT DEFAULT NULL,
            failure_reason VARCHAR(255) DEFAULT NULL,
            paid_at DATETIME DEFAULT NULL,
            started_at DATETIME DEFAULT NULL,
            finished_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mp_public (public_id),
            UNIQUE KEY uq_mp_pg (pg_order_id),
            UNIQUE KEY uq_mp_idem (idempotency_key),
            INDEX idx_mp_user (user_id),
            INDEX idx_mp_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    public static function recipientSchemaSql(): string
    {
        return "CREATE TABLE IF NOT EXISTS mass_push_recipients (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            accepted_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mp_recipient (campaign_id, user_id),
            INDEX idx_mp_recipient_status (campaign_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    public static function unsubscribeSchemaSql(): string
    {
        return "CREATE TABLE IF NOT EXISTS mass_push_unsubscribes (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private function ensureTables(): void
    {
        if (self::$ensured) {
            return;
        }
        $this->db->exec(self::schemaSql());
        $this->db->exec(self::recipientSchemaSql());
        $this->db->exec(self::unsubscribeSchemaSql());
        self::$ensured = true;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO mass_push_campaigns
            (public_id, user_id, title, body, target_url, amount, audience_count, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $row['public_id'],
            $row['user_id'],
            $row['title'],
            $row['body'],
            $row['target_url'],
            $row['amount'],
            $row['audience_count'],
            $row['status'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findByPublicId(string $publicId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM mass_push_campaigns WHERE public_id = ? LIMIT 1');
        $stmt->execute([$publicId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByPgOrderId(string $pgOrderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM mass_push_campaigns WHERE pg_order_id = ? LIMIT 1');
        $stmt->execute([$pgOrderId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM mass_push_campaigns WHERE idempotency_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function lockById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM mass_push_campaigns WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM mass_push_campaigns WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([$userId]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM mass_push_campaigns
             ORDER BY (status = 'pending_moderation') DESC, id DESC
             LIMIT 100"
        );

        return $stmt->fetchAll() ?: [];
    }

    public function saveCreative(int $id, string $title, string $body, ?string $url, string $status): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mass_push_campaigns
             SET title = ?, body = ?, target_url = ?, status = ?, reject_reason = NULL
             WHERE id = ?'
        );
        $stmt->execute([$title, $body, $url, $status, $id]);
    }

    public function saveModeration(int $id, string $status, ?string $reason): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mass_push_campaigns SET status = ?, reject_reason = ? WHERE id = ?'
        );
        $stmt->execute([$status, $reason, $id]);
    }

    public function savePaymentStart(int $id, string $pgOrderId, ?string $idempotencyKey): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mass_push_campaigns
             SET status = ?, pg_order_id = ?, idempotency_key = ?, failure_reason = NULL
             WHERE id = ?'
        );
        $stmt->execute([MassPushPolicy::STATUS_PENDING, $pgOrderId, $idempotencyKey, $id]);
    }

    public function saveRedirect(int $id, string $redirectUrl, ?string $pgPaymentId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mass_push_campaigns SET redirect_url = ?, pg_payment_id = COALESCE(?, pg_payment_id) WHERE id = ?'
        );
        $stmt->execute([$redirectUrl, $pgPaymentId, $id]);
    }

    public function savePaymentResult(int $id, string $status, ?string $pgPaymentId, ?string $reason, ?string $paidAt): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mass_push_campaigns
             SET status = ?, pg_payment_id = COALESCE(?, pg_payment_id), failure_reason = ?, paid_at = COALESCE(?, paid_at)
             WHERE id = ?'
        );
        $stmt->execute([$status, $pgPaymentId, $reason, $paidAt, $id]);
    }

    public function saveCancelled(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mass_push_campaigns SET status = ? WHERE id = ?'
        );
        $stmt->execute([MassPushPolicy::STATUS_CANCELLED, $id]);
    }

    public function setAudienceCount(int $id, int $audience): void
    {
        $stmt = $this->db->prepare('UPDATE mass_push_campaigns SET audience_count = ? WHERE id = ?');
        $stmt->execute([$audience, $id]);
    }

    public function markSending(int $id, int $audience): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE mass_push_campaigns
             SET status = ?, audience_count = ?, started_at = NOW(), finished_at = NULL, failure_reason = NULL
             WHERE id = ? AND status = ?'
        );
        $stmt->execute([
            MassPushPolicy::STATUS_SENDING,
            $audience,
            $id,
            MassPushPolicy::STATUS_PAID,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function countAudience(int $advertiserId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM users u
             LEFT JOIN mass_push_unsubscribes x ON x.user_id = u.id
             WHERE u.id <> ?
               AND x.user_id IS NULL'
        );
        $stmt->execute([$advertiserId]);

        return (int) $stmt->fetchColumn();
    }

    public function insertRecipients(int $campaignId, int $advertiserId): int
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO mass_push_recipients (campaign_id, user_id, status)
             SELECT ?, u.id, ?
             FROM users u
             LEFT JOIN mass_push_unsubscribes x ON x.user_id = u.id
             WHERE u.id <> ?
               AND x.user_id IS NULL
             ORDER BY u.id'
        );
        $stmt->execute([
            $campaignId,
            MassPushPolicy::RECIPIENT_PENDING,
            $advertiserId,
        ]);

        return $this->recipientCount($campaignId);
    }

    public function recipientCount(int $campaignId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM mass_push_recipients WHERE campaign_id = ?');
        $stmt->execute([$campaignId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lockPendingRecipients(int $campaignId, int $limit): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->db->prepare(
            'SELECT id, user_id, status FROM mass_push_recipients
             WHERE campaign_id = ? AND status = ?
             ORDER BY id
             LIMIT ' . $limit . ' FOR UPDATE'
        );
        $stmt->execute([$campaignId, MassPushPolicy::RECIPIENT_PENDING]);

        return $stmt->fetchAll() ?: [];
    }

    public function markRecipient(int $recipientId, string $status): void
    {
        $acceptedAt = $status === MassPushPolicy::RECIPIENT_ACCEPTED ? date('Y-m-d H:i:s') : null;
        $stmt = $this->db->prepare(
            'UPDATE mass_push_recipients SET status = ?, accepted_at = COALESCE(?, accepted_at) WHERE id = ? AND status = ?'
        );
        $stmt->execute([$status, $acceptedAt, $recipientId, MassPushPolicy::RECIPIENT_PENDING]);
    }

    public function requeueFailed(int $campaignId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE mass_push_recipients SET status = ? WHERE campaign_id = ? AND status = ?'
        );
        $stmt->execute([
            MassPushPolicy::RECIPIENT_PENDING,
            $campaignId,
            MassPushPolicy::RECIPIENT_FAILED,
        ]);

        return $stmt->rowCount();
    }

    public function markRetrying(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mass_push_campaigns SET status = ?, finished_at = NULL WHERE id = ? AND status = ?'
        );
        $stmt->execute([MassPushPolicy::STATUS_SENDING, $id, MassPushPolicy::STATUS_PARTIAL]);
    }

    /**
     * @return array{pending: int, accepted: int, failed: int}
     */
    public function recipientTallies(int $campaignId): array
    {
        $stmt = $this->db->prepare(
            'SELECT status, COUNT(*) AS n FROM mass_push_recipients WHERE campaign_id = ? GROUP BY status'
        );
        $stmt->execute([$campaignId]);
        $out = ['pending' => 0, 'accepted' => 0, 'failed' => 0];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $status = (string) $row['status'];
            if (isset($out[$status])) {
                $out[$status] = (int) $row['n'];
            }
        }

        return $out;
    }

    public function saveProgress(int $id, int $accepted, int $failed, string $status): void
    {
        $finished = in_array($status, [MassPushPolicy::STATUS_COMPLETED, MassPushPolicy::STATUS_PARTIAL], true)
            ? date('Y-m-d H:i:s')
            : null;
        $stmt = $this->db->prepare(
            'UPDATE mass_push_campaigns
             SET accepted_count = ?, failed_count = ?, status = ?, finished_at = ?
             WHERE id = ?'
        );
        $stmt->execute([$accepted, $failed, $status, $finished, $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listDispatchable(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM mass_push_campaigns WHERE status IN (?, ?) ORDER BY id ASC LIMIT 20'
        );
        $stmt->execute([MassPushPolicy::STATUS_SENDING, MassPushPolicy::STATUS_PARTIAL]);

        return $stmt->fetchAll() ?: [];
    }

    public function unsubscribe(int $userId): void
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO mass_push_unsubscribes (user_id) VALUES (?)'
        );
        $stmt->execute([$userId]);
    }

    public function isUnsubscribed(int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT user_id FROM mass_push_unsubscribes WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);

        return (bool) $stmt->fetch();
    }
}
