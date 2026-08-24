<?php

namespace App\Models;

use App\Core\Model;
use App\Helpers\ProductHelper;

class DigitalProduct extends Model
{
    protected string $table = 'digital_products';
    private static bool $ensured = false;

    public const KINDS = ['vod', 'live_open', 'live_closed', 'webinar', 'course', 'event', 'bundle'];

    public function __construct()
    {
        parent::__construct();
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        if (self::$ensured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS digital_products (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id INT UNSIGNED NOT NULL,
                author_id INT UNSIGNED NOT NULL,
                kind ENUM('vod','live_open','live_closed','webinar','course','event','bundle') NOT NULL DEFAULT 'live_closed',
                access_mode ENUM('paid','free_enrolled') NOT NULL DEFAULT 'paid',
                record_enabled TINYINT(1) NOT NULL DEFAULT 1,
                duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 120,
                starts_at DATETIME DEFAULT NULL,
                ends_at DATETIME DEFAULT NULL,
                access_days SMALLINT UNSIGNED NOT NULL DEFAULT 365,
                live_status ENUM('idle','ready','live','ended') NOT NULL DEFAULT 'idle',
                cf_live_input_uid VARCHAR(64) DEFAULT NULL,
                cf_playback_uid VARCHAR(64) DEFAULT NULL,
                cf_recording_uid VARCHAR(64) DEFAULT NULL,
                rtmps_url VARCHAR(500) DEFAULT NULL,
                stream_key VARCHAR(255) DEFAULT NULL,
                srt_url VARCHAR(500) DEFAULT NULL,
                watermark_mode ENUM('none','name','order','email') NOT NULL DEFAULT 'order',
                meta_json TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_dp_product (product_id),
                INDEX idx_dp_author (author_id),
                INDEX idx_dp_kind_status (kind, live_status),
                INDEX idx_dp_starts (starts_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS digital_lessons (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                digital_product_id INT UNSIGNED NOT NULL,
                sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                kind ENUM('video','pdf','text','live_session') NOT NULL DEFAULT 'video',
                title VARCHAR(255) NOT NULL,
                body MEDIUMTEXT DEFAULT NULL,
                file_path VARCHAR(255) DEFAULT NULL,
                cf_video_uid VARCHAR(64) DEFAULT NULL,
                live_session_id INT UNSIGNED DEFAULT NULL,
                duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                is_preview TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_dl_product (digital_product_id, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS digital_live_sessions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                digital_product_id INT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                starts_at DATETIME NOT NULL,
                duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 90,
                live_status ENUM('idle','ready','live','ended') NOT NULL DEFAULT 'idle',
                cf_live_input_uid VARCHAR(64) DEFAULT NULL,
                cf_playback_uid VARCHAR(64) DEFAULT NULL,
                cf_recording_uid VARCHAR(64) DEFAULT NULL,
                rtmps_url VARCHAR(500) DEFAULT NULL,
                stream_key VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_dls_product (digital_product_id, starts_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS digital_access (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                digital_product_id INT UNSIGNED NOT NULL,
                order_id INT UNSIGNED DEFAULT NULL,
                status ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
                access_from DATETIME NOT NULL,
                access_until DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_da_user_product (user_id, digital_product_id),
                INDEX idx_da_order (order_id),
                INDEX idx_da_until (status, access_until)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS digital_playback_tickets (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                access_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                digital_product_id INT UNSIGNED NOT NULL,
                lesson_id INT UNSIGNED DEFAULT NULL,
                live_session_id INT UNSIGNED DEFAULT NULL,
                token_hash CHAR(64) NOT NULL,
                video_uid VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                ip VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_dpt_hash (token_hash),
                INDEX idx_dpt_user (user_id, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS digital_watch_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                digital_product_id INT UNSIGNED NOT NULL,
                lesson_id INT UNSIGNED DEFAULT NULL,
                seconds_watched INT UNSIGNED NOT NULL DEFAULT 0,
                ip VARCHAR(45) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_dwl_user_product (user_id, digital_product_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS digital_provider_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                provider VARCHAR(32) NOT NULL DEFAULT 'cloudflare',
                event_uid VARCHAR(80) DEFAULT NULL,
                event_type VARCHAR(80) NOT NULL,
                payload_json MEDIUMTEXT DEFAULT NULL,
                processed_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_dpe_type (event_type, created_at),
                INDEX idx_dpe_uid (event_uid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$ensured = true;
    }

    public function findByProductId(int $productId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM digital_products WHERE product_id = ? LIMIT 1');
        $stmt->execute([$productId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByLiveInputUid(string $uid): ?array
    {
        if ($uid === '') {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM digital_products WHERE cf_live_input_uid = ? LIMIT 1');
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByVideoUid(string $uid): ?array
    {
        if ($uid === '') {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM digital_products
             WHERE cf_playback_uid = ? OR cf_recording_uid = ? OR cf_live_input_uid = ?
             LIMIT 1'
        );
        $stmt->execute([$uid, $uid, $uid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function forAuthor(int $authorId): array
    {
        $stmt = $this->db->prepare(
            'SELECT d.*, p.title, p.price, p.status AS listing_status, p.category
             FROM digital_products d
             INNER JOIN products p ON p.id = d.product_id
             WHERE d.author_id = ?
             ORDER BY d.updated_at DESC'
        );
        $stmt->execute([$authorId]);
        return $stmt->fetchAll();
    }

    public function ensureForListing(array $product): array
    {
        return $this->syncFromCourseProduct($product);
    }

    public function kindFromCourseProduct(array $product): string
    {
        $fmt = ProductHelper::courseFormatFromCategory($product['category'] ?? null);
        return match ($fmt) {
            'recording' => 'vod',
            'live' => 'live_closed',
            default => 'course',
        };
    }

    /** @param array<string, mixed> $product */
    public function syncFromCourseProduct(array $product): array
    {
        $productId = (int) ($product['id'] ?? 0);
        $authorId = (int) ($product['user_id'] ?? 0);
        if ($productId < 1 || $authorId < 1) {
            throw new \InvalidArgumentException('product');
        }

        $existing = $this->findByProductId($productId);
        $kind = $this->kindFromCourseProduct($product);
        if ($existing) {
            $stmt = $this->db->prepare(
                'UPDATE digital_products SET author_id = ?, kind = ? WHERE id = ?'
            );
            $stmt->execute([$authorId, $kind, (int) $existing['id']]);
            return $this->find((int) $existing['id']) ?? $existing;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO digital_products (product_id, author_id, kind, access_mode, record_enabled, live_status)
             VALUES (?, ?, ?, \'paid\', 1, \'idle\')'
        );
        $stmt->execute([$productId, $authorId, $kind]);
        return $this->find((int) $this->db->lastInsertId());
    }

    public function updateFields(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $allowed = [
            'kind', 'access_mode', 'record_enabled', 'duration_minutes', 'starts_at', 'ends_at',
            'access_days', 'live_status', 'cf_live_input_uid', 'cf_playback_uid', 'cf_recording_uid',
            'rtmps_url', 'stream_key', 'srt_url', 'watermark_mode', 'meta_json',
        ];
        $set = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            $set[] = "`{$k}` = ?";
            $vals[] = $v;
        }
        if ($set === []) {
            return;
        }
        $vals[] = $id;
        $this->db->prepare('UPDATE digital_products SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    }

    public function lessons(int $digitalProductId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM digital_lessons WHERE digital_product_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$digitalProductId]);
        return $stmt->fetchAll();
    }

    public function sessions(int $digitalProductId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM digital_live_sessions WHERE digital_product_id = ? ORDER BY starts_at ASC'
        );
        $stmt->execute([$digitalProductId]);
        return $stmt->fetchAll();
    }

    public function findAccess(int $userId, int $digitalProductId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM digital_access WHERE user_id = ? AND digital_product_id = ? LIMIT 1'
        );
        $stmt->execute([$userId, $digitalProductId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function accessIsValid(?array $access): bool
    {
        if (!$access || ($access['status'] ?? '') !== 'active') {
            return false;
        }
        $until = $access['access_until'] ?? null;
        if ($until && strtotime((string) $until) < time()) {
            return false;
        }
        return true;
    }

    public function libraryForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.*, d.kind, d.live_status, d.starts_at, d.duration_minutes, d.watermark_mode,
                    p.id AS listing_id, p.title, p.image, p.price
             FROM digital_access a
             INNER JOIN digital_products d ON d.id = a.digital_product_id
             INNER JOIN products p ON p.id = d.product_id
             WHERE a.user_id = ?
             ORDER BY a.created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function grantAccess(int $userId, int $digitalProductId, int $orderId, int $accessDays): array
    {
        $from = date('Y-m-d H:i:s');
        $until = $accessDays > 0
            ? date('Y-m-d H:i:s', time() + ($accessDays * 86400))
            : null;

        $existing = $this->findAccess($userId, $digitalProductId);
        if ($existing) {
            $stmt = $this->db->prepare(
                'UPDATE digital_access
                 SET status = \'active\', order_id = ?, access_from = ?, access_until = ?
                 WHERE id = ?'
            );
            $stmt->execute([$orderId, $from, $until, (int) $existing['id']]);
            return $this->findAccess($userId, $digitalProductId) ?? $existing;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO digital_access (user_id, digital_product_id, order_id, status, access_from, access_until)
             VALUES (?, ?, ?, \'active\', ?, ?)'
        );
        $stmt->execute([$userId, $digitalProductId, $orderId, $from, $until]);
        return $this->findAccess($userId, $digitalProductId) ?? [];
    }

    public function storePlaybackTicket(
        int $accessId,
        int $userId,
        int $digitalProductId,
        string $videoUid,
        string $token,
        int $exp,
        ?int $lessonId = null
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO digital_playback_tickets
             (access_id, user_id, digital_product_id, lesson_id, token_hash, video_uid, expires_at, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $accessId,
            $userId,
            $digitalProductId,
            $lessonId,
            hash('sha256', $token),
            $videoUid,
            date('Y-m-d H:i:s', $exp),
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    }

    public function logWatch(int $userId, int $digitalProductId, int $seconds, ?int $lessonId = null): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO digital_watch_log (user_id, digital_product_id, lesson_id, seconds_watched, ip)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $digitalProductId,
            $lessonId,
            max(0, $seconds),
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
    }

    public function storeProviderEvent(string $type, ?string $uid, array $payload): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO digital_provider_events (provider, event_uid, event_type, payload_json)
             VALUES (\'cloudflare\', ?, ?, ?)'
        );
        $stmt->execute([
            $uid,
            mb_substr($type, 0, 80),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function markProviderEventProcessed(int $id): void
    {
        $this->db->prepare('UPDATE digital_provider_events SET processed_at = NOW() WHERE id = ?')->execute([$id]);
    }

    /** @return list<int> */
    public function activeBuyerIds(int $digitalProductId): array
    {
        $stmt = $this->db->prepare(
            "SELECT user_id FROM digital_access
             WHERE digital_product_id = ? AND status = 'active'
               AND (access_until IS NULL OR access_until > NOW())"
        );
        $stmt->execute([$digitalProductId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
