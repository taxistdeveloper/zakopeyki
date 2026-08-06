<?php

namespace App\Models;

use App\Core\Auth;
use App\Core\Model;
use App\Helpers\ActivityLogger;
use PDO;
use Throwable;

class SiteVisit extends Model
{
    protected string $table = 'site_visits';
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
            "CREATE TABLE IF NOT EXISTS site_visits (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                visitor_key CHAR(32) NOT NULL,
                user_id INT UNSIGNED NULL,
                user_name VARCHAR(120) NULL,
                path VARCHAR(255) NOT NULL DEFAULT '/',
                ip VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                hits INT UNSIGNED NOT NULL DEFAULT 1,
                visit_date DATE NOT NULL,
                first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_visitor_day (visitor_key, visit_date),
                INDEX idx_visit_date (visit_date),
                INDEX idx_visit_last (last_seen_at),
                INDEX idx_visit_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$ensured = true;
    }

    /** Record or bump today's visit for the current browser. Safe to call on every GET. */
    public static function trackCurrentRequest(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return;
        }

        // Skip AJAX / asset-like probes
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        if ($accept !== '' && !str_contains($accept, 'text/html') && !str_contains($accept, '*/*')) {
            return;
        }

        $ua = ActivityLogger::userAgent() ?? '';
        if (self::looksLikeBot($ua)) {
            return;
        }

        try {
            $model = new self();
            $key = $model->resolveVisitorKey();
            $path = self::currentPath();
            $userId = Auth::check() ? Auth::id() : null;
            $userName = null;
            if ($userId) {
                $user = Auth::user();
                $userName = trim((string) ($user['name'] ?? '')) ?: ((string) ($user['login'] ?? '') ?: null);
            }

            $model->upsertToday(
                $key,
                $userId,
                $userName,
                $path,
                ActivityLogger::clientIp(),
                $ua !== '' ? mb_substr($ua, 0, 255) : null
            );
        } catch (Throwable) {
            // Never break the page because of analytics.
        }
    }

    private function resolveVisitorKey(): string
    {
        $cookie = 'zk_vid';
        $existing = isset($_COOKIE[$cookie]) ? preg_replace('/[^a-f0-9]/', '', (string) $_COOKIE[$cookie]) : '';
        if (is_string($existing) && strlen($existing) === 32) {
            return $existing;
        }

        $key = bin2hex(random_bytes(16));
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        setcookie($cookie, $key, [
            'expires' => time() + 60 * 60 * 24 * 365,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$cookie] = $key;

        return $key;
    }

    private function upsertToday(
        string $visitorKey,
        ?int $userId,
        ?string $userName,
        string $path,
        ?string $ip,
        ?string $userAgent
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO site_visits
                (visitor_key, user_id, user_name, path, ip, user_agent, hits, visit_date)
             VALUES (?, ?, ?, ?, ?, ?, 1, CURDATE())
             ON DUPLICATE KEY UPDATE
                hits = hits + 1,
                path = VALUES(path),
                ip = VALUES(ip),
                user_agent = VALUES(user_agent),
                user_id = COALESCE(VALUES(user_id), user_id),
                user_name = COALESCE(VALUES(user_name), user_name),
                last_seen_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            $visitorKey,
            $userId,
            $userName !== null && $userName !== '' ? mb_substr($userName, 0, 120) : null,
            mb_substr($path, 0, 255),
            $ip !== null && $ip !== '' ? mb_substr($ip, 0, 45) : null,
            $userAgent,
        ]);
    }

    private static function currentPath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = rtrim((string) ($GLOBALS['appConfig']['url'] ?? ''), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        return $path === '' ? '/' : $path;
    }

    private static function looksLikeBot(string $ua): bool
    {
        if ($ua === '') {
            return false;
        }
        return (bool) preg_match(
            '/bot|crawl|spider|slurp|facebookexternalhit|preview|wget|curl|python-requests|httpclient/i',
            $ua
        );
    }

    /** @return array{visitors_today:int, visitors_week:int, visitors_total:int, hits_today:int, hits_week:int} */
    public function stats(): array
    {
        $visitorsToday = (int) $this->db->query(
            'SELECT COUNT(*) FROM site_visits WHERE visit_date = CURDATE()'
        )->fetchColumn();

        $visitorsWeek = (int) $this->db->query(
            'SELECT COUNT(DISTINCT visitor_key) FROM site_visits
             WHERE visit_date >= (CURDATE() - INTERVAL 7 DAY)'
        )->fetchColumn();

        $visitorsTotal = (int) $this->db->query(
            'SELECT COUNT(DISTINCT visitor_key) FROM site_visits'
        )->fetchColumn();

        $hitsToday = (int) $this->db->query(
            'SELECT COALESCE(SUM(hits), 0) FROM site_visits WHERE visit_date = CURDATE()'
        )->fetchColumn();

        $hitsWeek = (int) $this->db->query(
            'SELECT COALESCE(SUM(hits), 0) FROM site_visits
             WHERE visit_date >= (CURDATE() - INTERVAL 7 DAY)'
        )->fetchColumn();

        return [
            'visitors_today' => $visitorsToday,
            'visitors_week' => $visitorsWeek,
            'visitors_total' => $visitorsTotal,
            'hits_today' => $hitsToday,
            'hits_week' => $hitsWeek,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->query(
            "SELECT id, visitor_key, user_id, user_name, path, ip, hits, visit_date, first_seen_at, last_seen_at
             FROM site_visits
             ORDER BY last_seen_at DESC
             LIMIT {$limit}"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
