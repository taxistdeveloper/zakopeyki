<?php

namespace App\Models;

use App\Core\Model;
use App\Helpers\ProductHelper;

class DigitalProduct extends Model
{
    protected string $table = 'digital_products';
    private static bool $ensured = false;
    /** @var array<int, list<int>> */
    private static array $ownedListingCache = [];

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
            "CREATE TABLE IF NOT EXISTS digital_chat_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                digital_product_id INT UNSIGNED NOT NULL,
                live_session_id INT UNSIGNED DEFAULT NULL,
                user_id INT UNSIGNED NOT NULL,
                body VARCHAR(400) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_dcm_product (digital_product_id, id),
                INDEX idx_dcm_session (live_session_id, id)
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

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS digital_lesson_progress (
                user_id INT UNSIGNED NOT NULL,
                lesson_id INT UNSIGNED NOT NULL,
                digital_product_id INT UNSIGNED NOT NULL,
                seconds_watched INT UNSIGNED NOT NULL DEFAULT 0,
                completed_at DATETIME DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, lesson_id),
                INDEX idx_dlp_product (digital_product_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS digital_certificates (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                digital_product_id INT UNSIGNED NOT NULL,
                public_code CHAR(16) NOT NULL,
                holder_name VARCHAR(190) NOT NULL,
                product_title VARCHAR(255) NOT NULL,
                issued_at DATETIME NOT NULL,
                UNIQUE KEY uk_dc_code (public_code),
                UNIQUE KEY uk_dc_user_product (user_id, digital_product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        try {
            $this->db->exec('ALTER TABLE digital_chat_messages ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER body');
        } catch (\Throwable) {
        }
        try {
            $this->db->exec('ALTER TABLE digital_chat_messages ADD COLUMN hidden_by INT UNSIGNED DEFAULT NULL AFTER is_hidden');
        } catch (\Throwable) {
        }

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

    /** @return list<int> product_id лотов с действующим доступом */
    public function ownedListingIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        if (isset(self::$ownedListingCache[$userId])) {
            return self::$ownedListingCache[$userId];
        }
        $stmt = $this->db->prepare(
            "SELECT d.product_id
             FROM digital_access a
             INNER JOIN digital_products d ON d.id = a.digital_product_id
             WHERE a.user_id = ?
               AND a.status = 'active'
               AND (a.access_until IS NULL OR a.access_until > NOW())"
        );
        $stmt->execute([$userId]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        self::$ownedListingCache[$userId] = $ids;
        return $ids;
    }

    public function userOwnsListing(int $userId, int $productId): bool
    {
        return $productId > 0 && in_array($productId, $this->ownedListingIds($userId), true);
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

    public function findLesson(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM digital_lessons WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function saveLesson(int $digitalProductId, array $post, ?int $id = null): array
    {
        $kind = (string) ($post['kind'] ?? 'video');
        if (!in_array($kind, ['video', 'pdf', 'text', 'live_session'], true)) {
            $kind = 'video';
        }
        $title = mb_substr(trim((string) ($post['title'] ?? '')), 0, 255);
        if ($title === '') {
            return ['ok' => false, 'error' => t('digital.lesson_title_required')];
        }
        $sort = max(0, min(999, (int) ($post['sort_order'] ?? 0)));
        $body = (string) ($post['body'] ?? '');
        $uid = trim((string) ($post['cf_video_uid'] ?? ''));
        $sessionId = (int) ($post['live_session_id'] ?? 0);
        $preview = !empty($post['is_preview']) ? 1 : 0;
        $filePath = $post['file_path'] ?? null;

        if ($id) {
            $existing = $this->findLesson($id);
            if (!$existing || (int) $existing['digital_product_id'] !== $digitalProductId) {
                return ['ok' => false, 'error' => t('digital.not_found')];
            }
            $stmt = $this->db->prepare(
                'UPDATE digital_lessons
                 SET sort_order = ?, kind = ?, title = ?, body = ?, cf_video_uid = ?, live_session_id = ?, is_preview = ?,
                     file_path = COALESCE(?, file_path)
                 WHERE id = ?'
            );
            $stmt->execute([
                $sort,
                $kind,
                $title,
                $body !== '' ? $body : null,
                $uid !== '' ? $uid : null,
                $sessionId > 0 ? $sessionId : null,
                $preview,
                $filePath,
                $id,
            ]);
            return ['ok' => true, 'id' => $id];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO digital_lessons
             (digital_product_id, sort_order, kind, title, body, file_path, cf_video_uid, live_session_id, is_preview)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $digitalProductId,
            $sort,
            $kind,
            $title,
            $body !== '' ? $body : null,
            $filePath,
            $uid !== '' ? $uid : null,
            $sessionId > 0 ? $sessionId : null,
            $preview,
        ]);
        return ['ok' => true, 'id' => (int) $this->db->lastInsertId()];
    }

    public function deleteLesson(int $digitalProductId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM digital_lessons WHERE id = ? AND digital_product_id = ?');
        return $stmt->execute([$id, $digitalProductId]);
    }

    public function findSession(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM digital_live_sessions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findSessionByLiveInputUid(string $uid): ?array
    {
        if ($uid === '') {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM digital_live_sessions WHERE cf_live_input_uid = ? LIMIT 1');
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findSessionByVideoUid(string $uid): ?array
    {
        if ($uid === '') {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM digital_live_sessions
             WHERE cf_recording_uid = ? OR cf_playback_uid = ? OR cf_live_input_uid = ?
             LIMIT 1'
        );
        $stmt->execute([$uid, $uid, $uid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function saveSession(int $digitalProductId, array $post, ?int $id = null): array
    {
        $title = mb_substr(trim((string) ($post['title'] ?? '')), 0, 255);
        if ($title === '') {
            return ['ok' => false, 'error' => t('digital.session_title_required')];
        }
        $starts = trim((string) ($post['starts_at'] ?? ''));
        $startsAt = $starts !== '' ? date('Y-m-d H:i:s', strtotime($starts)) : date('Y-m-d H:i:s');
        $duration = max(15, min(720, (int) ($post['duration_minutes'] ?? 90)));

        if ($id) {
            $existing = $this->findSession($id);
            if (!$existing || (int) $existing['digital_product_id'] !== $digitalProductId) {
                return ['ok' => false, 'error' => t('digital.not_found')];
            }
            $stmt = $this->db->prepare(
                'UPDATE digital_live_sessions SET title = ?, starts_at = ?, duration_minutes = ? WHERE id = ?'
            );
            $stmt->execute([$title, $startsAt, $duration, $id]);
            return ['ok' => true, 'id' => $id];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO digital_live_sessions (digital_product_id, title, starts_at, duration_minutes)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$digitalProductId, $title, $startsAt, $duration]);
        return ['ok' => true, 'id' => (int) $this->db->lastInsertId()];
    }

    public function deleteSession(int $digitalProductId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM digital_live_sessions WHERE id = ? AND digital_product_id = ?');
        return $stmt->execute([$id, $digitalProductId]);
    }

    public function updateSessionFields(int $id, array $fields): void
    {
        $allowed = [
            'title', 'starts_at', 'duration_minutes', 'live_status',
            'cf_live_input_uid', 'cf_playback_uid', 'cf_recording_uid', 'rtmps_url', 'stream_key',
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
        $this->db->prepare('UPDATE digital_live_sessions SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    }

    public function updateLessonFields(int $id, array $fields): void
    {
        $allowed = ['cf_video_uid', 'file_path', 'duration_seconds', 'title', 'body'];
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
        $this->db->prepare('UPDATE digital_lessons SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    }

    /** @return list<array<string, mixed>> */
    public function chatAfter(int $digitalProductId, int $afterId = 0, ?int $sessionId = null, bool $includeHidden = false): array
    {
        $hiddenSql = $includeHidden ? '' : ' AND m.is_hidden = 0';
        if ($sessionId) {
            $stmt = $this->db->prepare(
                'SELECT m.*, u.name AS user_name
                 FROM digital_chat_messages m
                 INNER JOIN users u ON u.id = m.user_id
                 WHERE m.digital_product_id = ? AND m.live_session_id = ? AND m.id > ?' . $hiddenSql . '
                 ORDER BY m.id ASC
                 LIMIT 80'
            );
            $stmt->execute([$digitalProductId, $sessionId, $afterId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT m.*, u.name AS user_name
                 FROM digital_chat_messages m
                 INNER JOIN users u ON u.id = m.user_id
                 WHERE m.digital_product_id = ? AND m.id > ?' . $hiddenSql . '
                 ORDER BY m.id ASC
                 LIMIT 80'
            );
            $stmt->execute([$digitalProductId, $afterId]);
        }
        return $stmt->fetchAll();
    }

    public function lastChatAt(int $userId, int $digitalProductId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT created_at FROM digital_chat_messages
             WHERE user_id = ? AND digital_product_id = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId, $digitalProductId]);
        $row = $stmt->fetch();
        return $row ? strtotime((string) $row['created_at']) : null;
    }

    public function addChatMessage(int $digitalProductId, int $userId, string $body, ?int $sessionId = null): array
    {
        $text = trim($body);
        $text = mb_substr($text, 0, 400);
        if ($text === '') {
            return ['ok' => false, 'error' => t('digital.chat_empty')];
        }
        $last = $this->lastChatAt($userId, $digitalProductId);
        if ($last && (time() - $last) < 2) {
            return ['ok' => false, 'error' => t('digital.chat_slow')];
        }
        $stmt = $this->db->prepare(
            'INSERT INTO digital_chat_messages (digital_product_id, live_session_id, user_id, body)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$digitalProductId, $sessionId, $userId, $text]);
        return ['ok' => true, 'id' => (int) $this->db->lastInsertId()];
    }

    /** @return array{viewers: int, seconds: int, tickets: int, chat: int} */
    public function authorStats(int $digitalProductId): array
    {
        $q1 = $this->db->prepare('SELECT COUNT(DISTINCT user_id) FROM digital_watch_log WHERE digital_product_id = ?');
        $q1->execute([$digitalProductId]);
        $q2 = $this->db->prepare('SELECT COALESCE(SUM(seconds_watched),0) FROM digital_watch_log WHERE digital_product_id = ?');
        $q2->execute([$digitalProductId]);
        $q3 = $this->db->prepare('SELECT COUNT(*) FROM digital_playback_tickets WHERE digital_product_id = ?');
        $q3->execute([$digitalProductId]);
        $q4 = $this->db->prepare('SELECT COUNT(*) FROM digital_chat_messages WHERE digital_product_id = ?');
        $q4->execute([$digitalProductId]);
        return [
            'viewers' => (int) $q1->fetchColumn(),
            'seconds' => (int) $q2->fetchColumn(),
            'tickets' => (int) $q3->fetchColumn(),
            'chat' => (int) $q4->fetchColumn(),
        ];
    }

    /** @return list<int> */
    public function hiddenChatIds(int $digitalProductId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM digital_chat_messages
             WHERE digital_product_id = ? AND is_hidden = 1
             ORDER BY id DESC
             LIMIT ' . max(1, min(80, $limit))
        );
        $stmt->execute([$digitalProductId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function hideChatMessage(int $messageId, int $digitalProductId, int $moderatorId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE digital_chat_messages
             SET is_hidden = 1, hidden_by = ?
             WHERE id = ? AND digital_product_id = ?'
        );
        $stmt->execute([$moderatorId, $messageId, $digitalProductId]);
        return $stmt->rowCount() > 0;
    }

    /** @return list<int> */
    public function completedLessonIds(int $userId, int $digitalProductId): array
    {
        $stmt = $this->db->prepare(
            'SELECT lesson_id FROM digital_lesson_progress
             WHERE user_id = ? AND digital_product_id = ? AND completed_at IS NOT NULL'
        );
        $stmt->execute([$userId, $digitalProductId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function bumpLessonProgress(int $userId, int $digitalProductId, int $lessonId, int $seconds): array
    {
        $lesson = $this->findLesson($lessonId);
        if (!$lesson || (int) $lesson['digital_product_id'] !== $digitalProductId) {
            return ['ok' => false, 'completed' => false];
        }
        $add = max(0, min(120, $seconds));
        $this->db->prepare(
            'INSERT INTO digital_lesson_progress (user_id, lesson_id, digital_product_id, seconds_watched)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE seconds_watched = seconds_watched + VALUES(seconds_watched)'
        )->execute([$userId, $lessonId, $digitalProductId, $add]);

        $stmt = $this->db->prepare(
            'SELECT seconds_watched, completed_at FROM digital_lesson_progress
             WHERE user_id = ? AND lesson_id = ? LIMIT 1'
        );
        $stmt->execute([$userId, $lessonId]);
        $row = $stmt->fetch() ?: ['seconds_watched' => 0, 'completed_at' => null];
        $need = max(45, (int) ($lesson['duration_seconds'] ?? 0) > 0
            ? (int) ceil((int) $lesson['duration_seconds'] * 0.8)
            : 90);
        $kind = (string) ($lesson['kind'] ?? 'video');
        if (in_array($kind, ['pdf', 'text'], true)) {
            $need = 20;
        }
        $justCompleted = false;
        if (empty($row['completed_at']) && (int) $row['seconds_watched'] >= $need) {
            $this->db->prepare(
                'UPDATE digital_lesson_progress SET completed_at = NOW() WHERE user_id = ? AND lesson_id = ? AND completed_at IS NULL'
            )->execute([$userId, $lessonId]);
            $justCompleted = true;
        }
        $cert = $this->maybeIssueCertificate($userId, $digitalProductId);
        return [
            'ok' => true,
            'completed' => $justCompleted || !empty($row['completed_at']),
            'just_completed' => $justCompleted,
            'certificate' => $cert,
        ];
    }

    public function markLessonComplete(int $userId, int $digitalProductId, int $lessonId): array
    {
        $lesson = $this->findLesson($lessonId);
        if (!$lesson || (int) $lesson['digital_product_id'] !== $digitalProductId) {
            return ['ok' => false, 'error' => t('digital.not_found')];
        }
        $this->db->prepare(
            'INSERT INTO digital_lesson_progress (user_id, lesson_id, digital_product_id, seconds_watched, completed_at)
             VALUES (?, ?, ?, 0, NOW())
             ON DUPLICATE KEY UPDATE completed_at = COALESCE(completed_at, NOW())'
        )->execute([$userId, $lessonId, $digitalProductId]);
        $cert = $this->maybeIssueCertificate($userId, $digitalProductId);
        return ['ok' => true, 'certificate' => $cert];
    }

    public function progressSummary(int $userId, int $digitalProductId): array
    {
        $lessons = $this->lessons($digitalProductId);
        $required = array_values(array_filter($lessons, static fn (array $l) => empty($l['is_preview'])));
        if ($required === []) {
            $required = $lessons;
        }
        $done = $this->completedLessonIds($userId, $digitalProductId);
        $requiredIds = array_map(static fn (array $l) => (int) $l['id'], $required);
        $doneRequired = array_values(array_intersect($requiredIds, $done));
        $total = count($requiredIds);
        $finished = $total > 0 && count($doneRequired) >= $total;
        return [
            'done_ids' => $done,
            'required' => $total,
            'completed' => count($doneRequired),
            'finished' => $finished,
            'percent' => $total > 0 ? (int) round(100 * count($doneRequired) / $total) : 0,
        ];
    }

    public function findCertificate(int $userId, int $digitalProductId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM digital_certificates WHERE user_id = ? AND digital_product_id = ? LIMIT 1'
        );
        $stmt->execute([$userId, $digitalProductId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findCertificateByCode(string $code): ?array
    {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code) ?? '');
        if (strlen($code) < 8) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM digital_certificates WHERE public_code = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function maybeIssueCertificate(int $userId, int $digitalProductId): ?array
    {
        $summary = $this->progressSummary($userId, $digitalProductId);
        if (empty($summary['finished'])) {
            return $this->findCertificate($userId, $digitalProductId);
        }
        $existing = $this->findCertificate($userId, $digitalProductId);
        if ($existing) {
            return $existing;
        }
        $product = $this->find($digitalProductId);
        if (!$product) {
            return null;
        }
        $listing = (new Product())->find((int) $product['product_id']);
        $user = (new User())->find($userId);
        $name = trim((string) (($user['name'] ?? '') ?: trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))));
        if ($name === '') {
            $name = 'ID ' . $userId;
        }
        $code = strtoupper(bin2hex(random_bytes(8)));
        $this->db->prepare(
            'INSERT INTO digital_certificates (user_id, digital_product_id, public_code, holder_name, product_title, issued_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([
            $userId,
            $digitalProductId,
            $code,
            mb_substr($name, 0, 190),
            mb_substr((string) ($listing['title'] ?? ('#' . $digitalProductId)), 0, 255),
        ]);
        (new Notification())->createFor(
            $userId,
            t('digital.notify_certificate', ['title' => (string) ($listing['title'] ?? '')])
        );
        return $this->findCertificate($userId, $digitalProductId);
    }
}
