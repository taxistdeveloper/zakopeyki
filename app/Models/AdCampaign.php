<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Services\Ads\AdPolicy;

class AdCampaign extends Model
{
    protected string $table = 'ad_campaigns';
    private static bool $ensured = false;

    public function __construct()
    {
        parent::__construct();
        $this->ensureTables();
    }

    public static function schemaSql(): string
    {
        return "CREATE TABLE IF NOT EXISTS ad_campaigns (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(32) NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            format VARCHAR(16) NOT NULL,
            category_key VARCHAR(255) DEFAULT NULL,
            title VARCHAR(120) NOT NULL,
            target_url VARCHAR(500) NOT NULL,
            image_file VARCHAR(255) NOT NULL,
            units INT UNSIGNED NOT NULL,
            amount INT UNSIGNED NOT NULL,
            impressions_paid INT UNSIGNED NOT NULL DEFAULT 0,
            impressions_used INT UNSIGNED NOT NULL DEFAULT 0,
            clicks INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL,
            reject_reason VARCHAR(255) DEFAULT NULL,
            open_key VARCHAR(64) DEFAULT NULL,
            idempotency_key VARCHAR(64) DEFAULT NULL,
            pg_order_id VARCHAR(64) DEFAULT NULL,
            pg_payment_id VARCHAR(64) DEFAULT NULL,
            redirect_url TEXT DEFAULT NULL,
            failure_reason VARCHAR(255) DEFAULT NULL,
            paid_at DATETIME DEFAULT NULL,
            starts_at DATETIME DEFAULT NULL,
            ends_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ad_public (public_id),
            UNIQUE KEY uq_ad_pg (pg_order_id),
            UNIQUE KEY uq_ad_open (open_key),
            UNIQUE KEY uq_ad_idem (idempotency_key),
            INDEX idx_ad_user (user_id),
            INDEX idx_ad_serve (format, status, category_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    public static function impressionSchemaSql(): string
    {
        return "CREATE TABLE IF NOT EXISTS ad_impressions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT UNSIGNED NOT NULL,
            token CHAR(32) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'issued',
            counted_at DATETIME DEFAULT NULL,
            clicked_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ad_impr_token (token),
            INDEX idx_ad_impr_campaign (campaign_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private function ensureTables(): void
    {
        if (self::$ensured) {
            return;
        }
        $this->db->exec(self::schemaSql());
        $this->db->exec(self::impressionSchemaSql());
        self::$ensured = true;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ad_campaigns
            (public_id, user_id, format, category_key, title, target_url, image_file, units, amount, impressions_paid, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $row['public_id'],
            $row['user_id'],
            $row['format'],
            $row['category_key'],
            $row['title'],
            $row['target_url'],
            $row['image_file'],
            $row['units'],
            $row['amount'],
            $row['impressions_paid'],
            $row['status'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findByPgOrderId(string $pgOrderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ad_campaigns WHERE pg_order_id = ? LIMIT 1');
        $stmt->execute([$pgOrderId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ad_campaigns WHERE idempotency_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function lockById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ad_campaigns WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM ad_campaigns WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([$userId]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPendingModeration(): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM ad_campaigns WHERE status = 'pending_moderation' ORDER BY id ASC"
        );

        return $stmt->fetchAll() ?: [];
    }

    public function holdsExclusive(string $categoryKey, int $exceptId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM ad_campaigns
             WHERE format = ? AND category_key = ? AND open_key IS NOT NULL AND id <> ?
             LIMIT 1'
        );
        $stmt->execute([AdPolicy::FORMAT_EXCLUSIVE, $categoryKey, $exceptId]);

        return (bool) $stmt->fetch();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function candidates(string $format, int $limit): array
    {
        $limit = max(1, min(20, $limit));
        $stmt = $this->db->prepare(
            'SELECT * FROM ad_campaigns
             WHERE format = ? AND status = ?
               AND (ends_at IS NULL OR ends_at > NOW())
               AND (impressions_paid = 0 OR impressions_used < impressions_paid)
             ORDER BY impressions_used ASC, RAND()
             LIMIT ' . $limit
        );
        $stmt->execute([$format, AdPolicy::STATUS_ACTIVE]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findUnapplied(string $format): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ad_campaigns WHERE format = ? AND status = 'paid' ORDER BY id ASC"
        );
        $stmt->execute([$format]);

        return $stmt->fetchAll() ?: [];
    }

    public function savePaymentStart(int $id, string $pgOrderId, ?string $openKey, ?string $idempotencyKey): void
    {
        $stmt = $this->db->prepare(
            'UPDATE ad_campaigns
             SET status = ?, pg_order_id = ?, open_key = ?, idempotency_key = ?, failure_reason = NULL
             WHERE id = ?'
        );
        $stmt->execute([AdPolicy::STATUS_PENDING, $pgOrderId, $openKey, $idempotencyKey, $id]);
    }

    public function saveRedirect(int $id, string $redirectUrl, ?string $externalId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE ad_campaigns SET redirect_url = ?, pg_payment_id = COALESCE(?, pg_payment_id) WHERE id = ?'
        );
        $stmt->execute([$redirectUrl, $externalId, $id]);
    }

    public function saveState(
        int $id,
        string $status,
        ?string $openKey,
        ?string $pgPaymentId,
        ?string $reason,
        ?string $paidAt,
        ?string $startsAt,
        ?string $endsAt
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE ad_campaigns
             SET status = ?, open_key = ?, pg_payment_id = COALESCE(?, pg_payment_id),
                 failure_reason = ?, paid_at = COALESCE(?, paid_at), starts_at = COALESCE(?, starts_at), ends_at = COALESCE(?, ends_at)
             WHERE id = ?'
        );
        $stmt->execute([$status, $openKey, $pgPaymentId, $reason, $paidAt, $startsAt, $endsAt, $id]);
    }

    public function saveModeration(int $id, string $status, ?string $reason): void
    {
        $stmt = $this->db->prepare(
            'UPDATE ad_campaigns SET status = ?, reject_reason = ? WHERE id = ? AND status = ?'
        );
        $stmt->execute([$status, $reason, $id, AdPolicy::STATUS_MODERATION]);
    }

    public function saveCreative(int $id, string $title, string $url, ?string $imageFile): void
    {
        if ($imageFile !== null) {
            $stmt = $this->db->prepare(
                'UPDATE ad_campaigns SET title = ?, target_url = ?, image_file = ?, status = ?, reject_reason = NULL WHERE id = ?'
            );
            $stmt->execute([$title, $url, $imageFile, AdPolicy::STATUS_MODERATION, $id]);

            return;
        }
        $stmt = $this->db->prepare(
            'UPDATE ad_campaigns SET title = ?, target_url = ?, status = ?, reject_reason = NULL WHERE id = ?'
        );
        $stmt->execute([$title, $url, AdPolicy::STATUS_MODERATION, $id]);
    }

    public function saveRunStatus(int $id, string $status, ?string $openKey): void
    {
        $stmt = $this->db->prepare('UPDATE ad_campaigns SET status = ?, open_key = ? WHERE id = ?');
        $stmt->execute([$status, $openKey, $id]);
    }

    public function expireDue(): int
    {
        $stmt = $this->db->prepare(
            "UPDATE ad_campaigns
             SET status = ?, open_key = NULL
             WHERE status IN (?, ?) AND ends_at IS NOT NULL AND ends_at <= NOW()"
        );
        $stmt->execute([
            AdPolicy::STATUS_COMPLETED,
            AdPolicy::STATUS_ACTIVE,
            AdPolicy::STATUS_PAUSED,
        ]);

        return $stmt->rowCount();
    }

    public function issueImpression(int $campaignId, string $token): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ad_impressions (campaign_id, token, status) VALUES (?, ?, ?)'
        );
        $stmt->execute([$campaignId, $token, AdPolicy::IMPRESSION_ISSUED]);
    }

    public function lockImpression(string $token): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ad_impressions WHERE token = ? FOR UPDATE');
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findImpression(string $token): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ad_impressions WHERE token = ? LIMIT 1');
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function markImpression(int $id, string $status, bool $counted): void
    {
        $stmt = $this->db->prepare(
            'UPDATE ad_impressions SET status = ?, counted_at = IF(? = 1, NOW(), counted_at) WHERE id = ?'
        );
        $stmt->execute([$status, $counted ? 1 : 0, $id]);
    }

    public function applyCount(int $campaignId, string $nextStatus, ?string $openKey): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE ad_campaigns
             SET impressions_used = impressions_used + 1, status = ?, open_key = ?
             WHERE id = ? AND status = ? AND (impressions_paid = 0 OR impressions_used < impressions_paid)'
        );
        $stmt->execute([$nextStatus, $openKey, $campaignId, AdPolicy::STATUS_ACTIVE]);

        return $stmt->rowCount() === 1;
    }

    public function markClicked(string $token): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE ad_impressions SET clicked_at = NOW() WHERE token = ? AND clicked_at IS NULL'
        );
        $stmt->execute([$token]);

        return $stmt->rowCount() === 1;
    }

    public function addClick(int $campaignId): void
    {
        $stmt = $this->db->prepare('UPDATE ad_campaigns SET clicks = clicks + 1 WHERE id = ?');
        $stmt->execute([$campaignId]);
    }
}
