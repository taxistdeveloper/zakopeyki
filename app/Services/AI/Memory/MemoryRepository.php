<?php

declare(strict_types=1);

namespace App\Services\AI\Memory;

use App\Core\Database;
use PDO;

final class MemoryRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    /**
     * @param array{type?:string,content:string,importance?:float,confidence?:float,source?:string,meta?:array,expires_at?:?string} $data
     */
    public function create(int $userId, array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_memories
             (user_id, type, content, importance, confidence, source, meta_json, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $data['type'] ?? 'user',
            $data['content'],
            $data['importance'] ?? 0.5,
            $data['confidence'] ?? 0.5,
            $data['source'] ?? 'system',
            isset($data['meta']) ? json_encode($data['meta'], JSON_UNESCAPED_UNICODE) : null,
            $data['expires_at'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array> */
    public function forUser(int $userId, int $limit = 20, ?string $type = null): array
    {
        $limit = max(1, min(100, $limit));
        if ($type) {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM ai_memories
                 WHERE user_id = ? AND type = ?
                   AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY importance DESC, updated_at DESC
                 LIMIT {$limit}"
            );
            $stmt->execute([$userId, $type]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM ai_memories
                 WHERE user_id = ?
                   AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY importance DESC, updated_at DESC
                 LIMIT {$limit}"
            );
            $stmt->execute([$userId]);
        }
        return $stmt->fetchAll() ?: [];
    }

    public function findSimilar(int $userId, string $content, float $threshold = 0.85): ?array
    {
        $rows = $this->forUser($userId, 50);
        $needle = mb_strtolower(trim($content), 'UTF-8');
        foreach ($rows as $row) {
            $hay = mb_strtolower((string) $row['content'], 'UTF-8');
            similar_text($needle, $hay, $pct);
            if (($pct / 100) >= $threshold) {
                return $row;
            }
        }
        return null;
    }

    public function bumpImportance(int $id, float $importance): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ai_memories SET importance = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([max(0, min(1, $importance)), $id]);
    }

    public function deleteExpired(): int
    {
        return (int) $this->pdo->exec(
            'DELETE FROM ai_memories WHERE expires_at IS NOT NULL AND expires_at < NOW()'
        );
    }

    public function deleteOldestBeyondLimit(int $userId, int $maxKeep): int
    {
        $maxKeep = max(1, $maxKeep);
        $ids = $this->pdo->prepare(
            'SELECT id FROM ai_memories WHERE user_id = ? ORDER BY importance DESC, updated_at DESC'
        );
        $ids->execute([$userId]);
        $all = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (count($all) <= $maxKeep) {
            return 0;
        }
        $drop = array_slice($all, $maxKeep);
        $in = implode(',', array_fill(0, count($drop), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM ai_memories WHERE id IN ({$in})");
        $stmt->execute($drop);
        return $stmt->rowCount();
    }
}
