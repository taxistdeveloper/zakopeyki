<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Файлы примерки (фото покупателя и результат). Хранятся в storage/tryon, вне webroot.
 */
class TryOnAsset extends Model
{
    public const KIND_PERSON = 'person';
    public const KIND_RESULT = 'result';

    protected string $table = 'tryon_assets';

    public function __construct()
    {
        parent::__construct();
        (new TryOnJob())->ensureSchema();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO tryon_assets (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $this->db->prepare($sql)->execute(array_values($data));

        return (int) $this->db->lastInsertId();
    }

    public function findForJob(int $jobId, string $kind): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM tryon_assets WHERE job_id = ? AND kind = ? LIMIT 1');
        $stmt->execute([$jobId, $kind]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function listForJob(int $jobId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM tryon_assets WHERE job_id = ?');
        $stmt->execute([$jobId]);

        return $stmt->fetchAll();
    }

    public function markDeleted(int $id, string $now): void
    {
        $this->db->prepare('UPDATE tryon_assets SET storage_path = NULL, deleted_at = ? WHERE id = ?')
            ->execute([$now, $id]);
    }

    public function setExpiry(int $jobId, string $expiresAt): void
    {
        $this->db->prepare('UPDATE tryon_assets SET expires_at = ? WHERE job_id = ? AND deleted_at IS NULL')
            ->execute([$expiresAt, $jobId]);
    }

    /** @return list<array<string, mixed>> */
    public function listExpired(string $now, int $limit = 200): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM tryon_assets WHERE deleted_at IS NULL AND expires_at < ? ORDER BY id ASC LIMIT ' . (int) $limit
        );
        $stmt->execute([$now]);

        return $stmt->fetchAll();
    }
}
