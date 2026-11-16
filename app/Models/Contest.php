<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Services\Contest\ContestPolicy;
use App\Services\Contest\ContestSchema;

/**
 * Акции модуля «Конкурсы и розыгрыши» и неизменяемые версии их правил.
 */
class Contest extends Model
{
    protected string $table = 'contests';

    public function __construct()
    {
        parent::__construct();
        ContestSchema::ensure($this->db);
    }

    public function db(): \PDO
    {
        return $this->db;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*, u.name AS organizer_name, u.login AS organizer_login, u.avatar AS organizer_avatar,
                    u.avatar_file AS organizer_avatar_file, u.business_name AS organizer_business_name,
                    u.business_status AS organizer_business_status
             FROM contests c JOIN users u ON u.id = c.organizer_id
             WHERE c.slug = ? LIMIT 1'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findWithOrganizer(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*, u.name AS organizer_name, u.login AS organizer_login, u.email AS organizer_email,
                    u.business_name AS organizer_business_name
             FROM contests c JOIN users u ON u.id = c.organizer_id
             WHERE c.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Блокировка строки акции внутри транзакции. */
    public function lockForUpdate(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM contests WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByIdempotencyKey(int $organizerId, string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM contests WHERE organizer_id = ? AND idempotency_key = ? LIMIT 1');
        $stmt->execute([$organizerId, $key]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function slugExists(string $slug): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM contests WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        return (bool) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO contests (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $this->db->prepare($sql)->execute(array_values($data));
        return (int) $this->db->lastInsertId();
    }

    /**
     * Обновление только разрешённых колонок (защита от подмены полей).
     * @param array<string, mixed> $data
     */
    public function updateFields(int $id, array $data): void
    {
        $allowed = [
            'title', 'summary', 'description', 'prize_summary', 'category', 'region', 'cover_image', 'mechanic',
            'winners_count', 'starts_at', 'ends_at', 'fulfilment_days', 'current_rule_version_id',
            'random_commit', 'random_seed', 'moderation_note', 'moderated_by', 'moderated_at', 'suspended_reason',
            'reminder_sent_at', 'published_at', 'closed_at', 'results_at', 'completed_at',
        ];
        $data = array_intersect_key($data, array_flip($allowed));
        if ($data === []) {
            return;
        }
        $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($data)));
        $this->db->prepare("UPDATE contests SET {$set} WHERE id = ?")->execute([...array_values($data), $id]);
    }

    /**
     * Атомарная смена статуса: срабатывает только если текущий статус = $from.
     * @param array<string, mixed> $extra
     */
    public function changeStatus(int $id, string $from, string $to, array $extra = []): bool
    {
        if (!in_array($to, ContestPolicy::STATUSES, true)) {
            return false;
        }
        $stmt = $this->db->prepare('UPDATE contests SET status = ? WHERE id = ? AND status = ?');
        $stmt->execute([$to, $id, $from]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        if ($extra !== []) {
            $this->updateFields($id, $extra);
        }
        return true;
    }

    // ── Версии правил ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function addRuleVersion(int $contestId, array $data, int $createdBy): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(version), 0) FROM contest_rule_versions WHERE contest_id = ?');
        $stmt->execute([$contestId]);
        $version = (int) $stmt->fetchColumn() + 1;

        $conditions = json_encode($data['conditions'], JSON_UNESCAPED_UNICODE);
        $prizes = json_encode($data['prizes'], JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', implode("\x1F", [
            $data['mechanic'], (string) $data['winners_count'], $data['rules_text'], (string) ($data['criteria_text'] ?? ''),
            $conditions, $prizes, $data['starts_at'], $data['ends_at'],
        ]));

        $this->db->prepare(
            'INSERT INTO contest_rule_versions
                (contest_id, version, mechanic, winners_count, rules_text, criteria_text, conditions_json, prizes_json,
                 starts_at, ends_at, content_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $contestId, $version, $data['mechanic'], (int) $data['winners_count'], $data['rules_text'],
            $data['criteria_text'] ?? null, $conditions, $prizes, $data['starts_at'], $data['ends_at'], $hash, $createdBy,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function ruleVersion(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM contest_rule_versions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['conditions'] = json_decode((string) $row['conditions_json'], true) ?: [];
        $row['prizes'] = json_decode((string) $row['prizes_json'], true) ?: [];
        return $row;
    }

    public function currentRules(array $contest): ?array
    {
        $id = (int) ($contest['current_rule_version_id'] ?? 0);
        return $id > 0 ? $this->ruleVersion($id) : null;
    }

    /** @return list<array> */
    public function ruleVersions(int $contestId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, version, content_hash, created_at, created_by FROM contest_rule_versions WHERE contest_id = ? ORDER BY version DESC'
        );
        $stmt->execute([$contestId]);
        return $stmt->fetchAll();
    }

    // ── Списки ────────────────────────────────────────────────────────────────

    /**
     * Публичный каталог.
     * @param array{status?:string, mechanic?:string, region?:string, category?:string, organizer?:int, q?:string} $f
     * @return array{items: list<array>, total: int}
     */
    public function catalog(array $f, int $page = 1, int $perPage = 12): array
    {
        $where = ['c.status IN (' . implode(',', array_fill(0, count(ContestPolicy::PUBLIC_STATUSES), '?')) . ')'];
        $params = ContestPolicy::PUBLIC_STATUSES;

        $status = (string) ($f['status'] ?? '');
        if ($status === 'active') {
            $where[] = "c.status = 'active'";
        } elseif ($status === 'upcoming') {
            $where[] = "c.status = 'scheduled'";
        } elseif ($status === 'finished') {
            $where[] = "c.status IN ('closed','results_published','completed','disputed')";
        }
        if (in_array($f['mechanic'] ?? '', ContestPolicy::MECHANICS, true)) {
            $where[] = 'c.mechanic = ?';
            $params[] = $f['mechanic'];
        }
        if (in_array($f['region'] ?? '', ContestPolicy::REGIONS, true)) {
            $where[] = '(c.region = ? OR c.region = \'kz\')';
            $params[] = $f['region'];
        }
        if (($f['category'] ?? '') !== '') {
            $where[] = 'c.category = ?';
            $params[] = (string) $f['category'];
        }
        if ((int) ($f['organizer'] ?? 0) > 0) {
            $where[] = 'c.organizer_id = ?';
            $params[] = (int) $f['organizer'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(c.title LIKE ? OR c.prize_summary LIKE ?)';
            $like = '%' . addcslashes(mb_substr($q, 0, 80), '%_\\') . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sqlWhere = implode(' AND ', $where);
        $count = $this->db->prepare("SELECT COUNT(*) FROM contests c WHERE {$sqlWhere}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $perPage = max(1, min(48, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->db->prepare(
            "SELECT c.*, u.name AS organizer_name, u.business_name AS organizer_business_name
             FROM contests c JOIN users u ON u.id = c.organizer_id
             WHERE {$sqlWhere}
             ORDER BY FIELD(c.status, 'active', 'scheduled', 'closed', 'results_published', 'disputed', 'completed'),
                      c.ends_at ASC, c.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /** @return list<array> */
    public function byOrganizer(int $organizerId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM contests WHERE organizer_id = ? ORDER BY id DESC LIMIT 200');
        $stmt->execute([$organizerId]);
        return $stmt->fetchAll();
    }

    /** @return list<array> */
    public function adminList(string $status = '', int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        if ($status !== '' && in_array($status, ContestPolicy::STATUSES, true)) {
            $stmt = $this->db->prepare(
                "SELECT c.*, u.name AS organizer_name, u.email AS organizer_email,
                        (SELECT COUNT(*) FROM contest_reports r WHERE r.contest_id = c.id AND r.status = 'open') AS open_reports
                 FROM contests c JOIN users u ON u.id = c.organizer_id
                 WHERE c.status = ? ORDER BY c.updated_at DESC LIMIT {$limit}"
            );
            $stmt->execute([$status]);
        } else {
            $stmt = $this->db->query(
                "SELECT c.*, u.name AS organizer_name, u.email AS organizer_email,
                        (SELECT COUNT(*) FROM contest_reports r WHERE r.contest_id = c.id AND r.status = 'open') AS open_reports
                 FROM contests c JOIN users u ON u.id = c.organizer_id
                 ORDER BY FIELD(c.status, 'pending_moderation', 'disputed', 'active', 'scheduled', 'closed') DESC, c.updated_at DESC
                 LIMIT {$limit}"
            );
        }
        return $stmt->fetchAll();
    }

    /** @return array<string, int> */
    public function countByStatus(): array
    {
        $rows = $this->db->query('SELECT status, COUNT(*) AS c FROM contests GROUP BY status')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['status']] = (int) $r['c'];
        }
        return $out;
    }

    /** Акции, у которых наступило время смены статуса (для cron). */
    public function dueForTransition(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        return $this->db->query(
            "SELECT id, status FROM contests
             WHERE (status = 'scheduled' AND starts_at <= NOW())
                OR (status = 'active' AND ends_at <= NOW())
             ORDER BY id ASC LIMIT {$limit}"
        )->fetchAll();
    }

    /** Активные акции, у которых до окончания меньше суток и напоминание не отправлялось. */
    public function dueForReminder(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->query(
            "SELECT * FROM contests
             WHERE status = 'active' AND reminder_sent_at IS NULL
               AND ends_at > NOW() AND ends_at <= (NOW() + INTERVAL 1 DAY)
             ORDER BY ends_at ASC LIMIT {$limit}"
        )->fetchAll();
    }

    public function incrementViews(int $id): void
    {
        $this->db->prepare('UPDATE contests SET views_count = views_count + 1 WHERE id = ?')->execute([$id]);
    }

    public function recountEntries(int $id): void
    {
        $this->db->prepare(
            "UPDATE contests SET entries_count = (
                SELECT COUNT(*) FROM contest_entries WHERE contest_id = ? AND status IN ('pending','confirmed')
             ) WHERE id = ?"
        )->execute([$id, $id]);
    }

    /** Организаторы с публичными акциями (для фильтра каталога). */
    public function publicOrganizers(): array
    {
        $in = implode(',', array_fill(0, count(ContestPolicy::PUBLIC_STATUSES), '?'));
        $stmt = $this->db->prepare(
            "SELECT DISTINCT u.id, COALESCE(NULLIF(u.business_name, ''), u.name) AS name
             FROM contests c JOIN users u ON u.id = c.organizer_id
             WHERE c.status IN ({$in}) ORDER BY name LIMIT 200"
        );
        $stmt->execute(ContestPolicy::PUBLIC_STATUSES);
        return $stmt->fetchAll();
    }

    /** Категории, встречающиеся в публичных акциях. */
    public function publicCategories(): array
    {
        $in = implode(',', array_fill(0, count(ContestPolicy::PUBLIC_STATUSES), '?'));
        $stmt = $this->db->prepare(
            "SELECT DISTINCT category FROM contests WHERE status IN ({$in}) AND category IS NOT NULL AND category <> '' ORDER BY category"
        );
        $stmt->execute(ContestPolicy::PUBLIC_STATUSES);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
