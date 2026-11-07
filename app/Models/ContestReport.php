<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Services\Contest\ContestSchema;

/**
 * Жалобы на акции и споры по результатам.
 */
class ContestReport extends Model
{
    protected string $table = 'contest_reports';

    public function __construct()
    {
        parent::__construct();
        ContestSchema::ensure($this->db);
    }

    public function hasOpen(int $contestId, int $reporterId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM contest_reports WHERE contest_id = ? AND reporter_id = ? AND status = 'open' LIMIT 1"
        );
        $stmt->execute([$contestId, $reporterId]);
        return (bool) $stmt->fetchColumn();
    }

    public function create(int $contestId, int $reporterId, string $reason, string $details): int
    {
        $this->db->prepare(
            'INSERT INTO contest_reports (contest_id, reporter_id, reason, details) VALUES (?, ?, ?, ?)'
        )->execute([$contestId, $reporterId, $reason, $details !== '' ? mb_substr($details, 0, 1000) : null]);
        return (int) $this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM contest_reports WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function resolve(int $id, string $status, string $note, int $byUserId): bool
    {
        if (!in_array($status, ['resolved', 'rejected'], true)) {
            return false;
        }
        $stmt = $this->db->prepare(
            "UPDATE contest_reports SET status = ?, resolution_note = ?, resolved_by = ?, resolved_at = NOW()
             WHERE id = ? AND status = 'open'"
        );
        $stmt->execute([$status, mb_substr($note, 0, 500), $byUserId, $id]);
        return $stmt->rowCount() === 1;
    }

    /** @return list<array> */
    public function forContest(int $contestId): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*, u.name AS reporter_name FROM contest_reports r LEFT JOIN users u ON u.id = r.reporter_id
             WHERE r.contest_id = ? ORDER BY r.id DESC LIMIT 200'
        );
        $stmt->execute([$contestId]);
        return $stmt->fetchAll();
    }

    /** @return list<array> */
    public function openQueue(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->query(
            "SELECT r.*, c.title AS contest_title, c.slug AS contest_slug, u.name AS reporter_name
             FROM contest_reports r JOIN contests c ON c.id = r.contest_id LEFT JOIN users u ON u.id = r.reporter_id
             WHERE r.status = 'open' ORDER BY r.id ASC LIMIT {$limit}"
        )->fetchAll();
    }
}
