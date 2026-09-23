<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\Core\Database;
use App\Services\AI\Contracts\VectorStoreInterface;
use PDO;

/**
 * Vector store на MySQL (JSON embeddings + cosine). Без внешней vector DB.
 */
final class MysqlVectorStore implements VectorStoreInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    public function upsert(string $collection, string $externalId, array $embedding, array $meta = []): void
    {
        $table = $this->tableFor($collection);
        $model = (string) ($meta['model'] ?? 'default');
        $dims = count($embedding);
        $json = json_encode(array_values(array_map('floatval', $embedding)));

        if ($collection === 'knowledge') {
            $chunkId = (int) $externalId;
            $stmt = $this->pdo->prepare(
                "INSERT INTO {$table} (chunk_id, model, dims, embedding_json)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE dims = VALUES(dims), embedding_json = VALUES(embedding_json)"
            );
            $stmt->execute([$chunkId, $model, $dims, $json]);
            return;
        }

        if ($collection === 'memory') {
            $memoryId = (int) $externalId;
            $stmt = $this->pdo->prepare(
                "INSERT INTO {$table} (memory_id, model, dims, embedding_json)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE dims = VALUES(dims), embedding_json = VALUES(embedding_json)"
            );
            $stmt->execute([$memoryId, $model, $dims, $json]);
        }
    }

    public function search(string $collection, array $query, int $topK = 5, float $minScore = 0.0): array
    {
        $table = $this->tableFor($collection);
        $idCol = $collection === 'memory' ? 'memory_id' : 'chunk_id';

        try {
            $rows = $this->pdo->query("SELECT {$idCol} AS eid, embedding_json FROM {$table}")->fetchAll() ?: [];
        } catch (\Throwable) {
            return [];
        }

        $scored = [];
        foreach ($rows as $row) {
            $vec = json_decode((string) $row['embedding_json'], true);
            if (!is_array($vec) || $vec === []) {
                continue;
            }
            $score = $this->cosine($query, array_map('floatval', $vec));
            if ($score < $minScore) {
                continue;
            }
            $scored[] = [
                'id' => (string) $row['eid'],
                'score' => $score,
                'meta' => [],
            ];
        }

        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, max(1, $topK));
    }

    public function delete(string $collection, string $externalId): void
    {
        $table = $this->tableFor($collection);
        $idCol = $collection === 'memory' ? 'memory_id' : 'chunk_id';
        $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE {$idCol} = ?");
        $stmt->execute([(int) $externalId]);
    }

    private function tableFor(string $collection): string
    {
        return match ($collection) {
            'memory' => 'ai_memory_embeddings',
            default => 'ai_knowledge_embeddings',
        };
    }

    /** @param list<float> $a @param list<float> $b */
    private function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }
}
