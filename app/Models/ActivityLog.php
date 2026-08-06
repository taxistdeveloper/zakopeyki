<?php

namespace App\Models;

use App\Core\Model;
use PDO;

class ActivityLog extends Model
{
    protected string $table = 'activity_logs';
    private static bool $ensured = false;

    public const LEVELS = ['info', 'warning', 'error'];

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
            "CREATE TABLE IF NOT EXISTS activity_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                user_name VARCHAR(120) NULL,
                action VARCHAR(64) NOT NULL,
                level ENUM('info', 'warning', 'error') NOT NULL DEFAULT 'info',
                entity_type VARCHAR(32) NULL,
                entity_id INT UNSIGNED NULL,
                message VARCHAR(500) NOT NULL,
                context_json JSON NULL,
                ip VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_activity_created (created_at),
                INDEX idx_activity_level (level),
                INDEX idx_activity_action (action),
                INDEX idx_activity_user (user_id),
                INDEX idx_activity_entity (entity_type, entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$ensured = true;
    }

    /**
     * @param array{
     *   user_id?: ?int,
     *   user_name?: ?string,
     *   action: string,
     *   level?: string,
     *   entity_type?: ?string,
     *   entity_id?: ?int,
     *   message: string,
     *   context?: mixed,
     *   ip?: ?string,
     *   user_agent?: ?string
     * } $data
     */
    public function write(array $data): int
    {
        $level = (string) ($data['level'] ?? 'info');
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'info';
        }

        $contextJson = null;
        if (array_key_exists('context', $data) && $data['context'] !== null) {
            $encoded = json_encode($data['context'], JSON_UNESCAPED_UNICODE);
            $contextJson = $encoded === false ? null : $encoded;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO activity_logs
                (user_id, user_name, action, level, entity_type, entity_id, message, context_json, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $data['user_id'] ?? null,
            $data['user_name'] !== null && $data['user_name'] !== ''
                ? mb_substr((string) $data['user_name'], 0, 120)
                : null,
            mb_substr((string) $data['action'], 0, 64),
            $level,
            isset($data['entity_type']) && $data['entity_type'] !== ''
                ? mb_substr((string) $data['entity_type'], 0, 32)
                : null,
            $data['entity_id'] ?? null,
            mb_substr((string) $data['message'], 0, 500),
            $contextJson,
            isset($data['ip']) && $data['ip'] !== ''
                ? mb_substr((string) $data['ip'], 0, 45)
                : null,
            isset($data['user_agent']) && $data['user_agent'] !== ''
                ? mb_substr((string) $data['user_agent'], 0, 255)
                : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @return array{items: list<array>, total: int, page: int, per_page: int, pages: int}
     */
    public function search(array $filters = [], int $page = 1, int $perPage = 40): array
    {
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $where = [];
        $params = [];

        $level = isset($filters['level']) ? strtolower(trim((string) $filters['level'])) : '';
        if ($level !== '' && in_array($level, self::LEVELS, true)) {
            $where[] = 'level = ?';
            $params[] = $level;
        }

        $action = isset($filters['action']) ? trim((string) $filters['action']) : '';
        if ($action !== '') {
            if (str_contains($action, '.')) {
                $where[] = 'action = ?';
                $params[] = $action;
            } else {
                $where[] = 'action LIKE ?';
                $params[] = $action . '.%';
            }
        }

        $userId = isset($filters['user_id']) ? (int) $filters['user_id'] : 0;
        if ($userId > 0) {
            $where[] = 'user_id = ?';
            $params[] = $userId;
        }

        $q = isset($filters['q']) ? trim((string) $filters['q']) : '';
        if ($q !== '') {
            $where[] = '(message LIKE ? OR user_name LIKE ? OR action LIKE ? OR CAST(entity_id AS CHAR) LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM activity_logs {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $pages = max(1, (int) ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            "SELECT * FROM activity_logs {$sqlWhere} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /** @return array<string, int> */
    public function countByLevel(): array
    {
        $rows = $this->db->query(
            "SELECT level, COUNT(*) AS cnt FROM activity_logs GROUP BY level"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = ['info' => 0, 'warning' => 0, 'error' => 0];
        foreach ($rows as $row) {
            $lvl = (string) ($row['level'] ?? '');
            if (isset($out[$lvl])) {
                $out[$lvl] = (int) $row['cnt'];
            }
        }
        return $out;
    }

    public function recentErrorCount(int $hours = 24): int
    {
        $hours = max(1, min(168, $hours));
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM activity_logs
             WHERE level = 'error' AND created_at >= (NOW() - INTERVAL {$hours} HOUR)"
        );
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /** Unique users who logged in since a safe SQL boundary (default: today). */
    public function countUniqueLoginsSince(string $sinceSql = 'CURDATE()'): int
    {
        $allowed = [
            'CURDATE()' => true,
            '(CURDATE() - INTERVAL 7 DAY)' => true,
            '(NOW() - INTERVAL 24 HOUR)' => true,
            '(NOW() - INTERVAL 7 DAY)' => true,
        ];
        if (!isset($allowed[$sinceSql])) {
            $sinceSql = 'CURDATE()';
        }
        try {
            return (int) $this->db->query(
                "SELECT COUNT(DISTINCT user_id) FROM activity_logs
                 WHERE action = 'auth.login'
                   AND user_id IS NOT NULL
                   AND created_at >= {$sinceSql}"
            )->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return list<string> */
    public function distinctActionPrefixes(): array
    {
        $rows = $this->db->query(
            "SELECT DISTINCT SUBSTRING_INDEX(action, '.', 1) AS prefix
             FROM activity_logs
             ORDER BY prefix ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $p = trim((string) ($row['prefix'] ?? ''));
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }
}
