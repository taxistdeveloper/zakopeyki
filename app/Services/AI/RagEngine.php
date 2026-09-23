<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Core\Database;
use App\Models\AiKnowledge;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Contracts\VectorStoreInterface;
use App\Services\AI\Core\AiConfig;
use App\Services\AI\Providers\MysqlVectorStore;
use App\Services\AI\Providers\OllamaProvider;
use App\Services\AI\Support\AiCache;
use PDO;

/**
 * Hybrid RAG: FULLTEXT + optional vector re-rank.
 */
class RagEngine
{
    private AiKnowledge $knowledge;
    private ?LLMProviderInterface $llm;
    private VectorStoreInterface $vectors;
    private PDO $pdo;
    private AiCache $cache;

    public function __construct(
        ?AiKnowledge $knowledge = null,
        ?LLMProviderInterface $llm = null,
        ?VectorStoreInterface $vectors = null,
        ?PDO $pdo = null,
        ?AiCache $cache = null,
    ) {
        $this->knowledge = $knowledge ?? new AiKnowledge();
        $this->llm = $llm;
        $this->vectors = $vectors ?? new MysqlVectorStore();
        $this->pdo = $pdo ?? Database::connect();
        $this->cache = $cache ?? new AiCache();
    }

    /** @return list<array> */
    public function searchContext(string $userQuery, ?int $limit = null): array
    {
        $limit = $limit ?? (int) (AiConfig::get('rag_limit', 3));
        $ttl = (int) AiConfig::get('performance.cache_kb_ttl', 120);
        $cacheKey = 'kb:' . md5(mb_strtolower(trim($userQuery), 'UTF-8') . '|' . $limit);

        if ($ttl > 0) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $fulltext = $this->knowledge->search($userQuery, max($limit, 5));

        if (!AiConfig::get('vector.enabled', true)) {
            $result = array_slice($fulltext, 0, $limit);
        } else {
            $vectorHits = $this->vectorSearch($userQuery, $limit);
            $result = $vectorHits === []
                ? array_slice($fulltext, 0, $limit)
                : $this->mergeResults($fulltext, $vectorHits, $limit);
        }

        if ($ttl > 0) {
            $this->cache->set($cacheKey, $result, $ttl);
        }

        return $result;
    }

    public function formatContextForPrompt(array $articles): string
    {
        if ($articles === []) {
            return 'Справочная информация не найдена.';
        }

        $maxChunk = max(200, (int) AiConfig::get('performance.max_rag_chars_per_chunk', 1200));
        $formatted = [];
        foreach ($articles as $index => $article) {
            $num = $index + 1;
            $title = (string) ($article['title'] ?? '');
            $content = (string) ($article['content'] ?? '');
            if (mb_strlen($content, 'UTF-8') > $maxChunk) {
                $content = mb_substr($content, 0, $maxChunk - 1, 'UTF-8') . '…';
            }
            $formatted[] = "[Статья #{$num}: {$title}]\n{$content}";
        }

        return implode("\n\n---\n\n", $formatted);
    }

    /**
     * Индексация всех активных документов: chunk → embed → store.
     * @return array{documents:int,chunks:int,embedded:int,errors:int}
     */
    public function reindexAll(?callable $onProgress = null): array
    {
        $llm = $this->llm ?? new OllamaProvider();
        $model = (string) AiConfig::get('embedding_model', 'nomic-embed-text');
        $docs = $this->pdo->query(
            'SELECT id, title, content FROM ai_knowledge_base WHERE is_active = 1'
        )->fetchAll() ?: [];

        $stats = ['documents' => 0, 'chunks' => 0, 'embedded' => 0, 'errors' => 0];

        foreach ($docs as $doc) {
            $docId = (int) $doc['id'];
            $stats['documents']++;

            // invalidate old chunks
            $old = $this->pdo->prepare('SELECT id FROM ai_knowledge_chunks WHERE document_id = ?');
            $old->execute([$docId]);
            foreach ($old->fetchAll(PDO::FETCH_COLUMN) ?: [] as $chunkId) {
                $this->vectors->delete('knowledge', (string) $chunkId);
            }
            $this->pdo->prepare('DELETE FROM ai_knowledge_chunks WHERE document_id = ?')->execute([$docId]);

            $text = trim((string) $doc['title'] . "\n\n" . (string) $doc['content']);
            $chunks = $this->chunkText($text);
            $ins = $this->pdo->prepare(
                'INSERT INTO ai_knowledge_chunks (document_id, chunk_index, content, token_estimate)
                 VALUES (?, ?, ?, ?)'
            );

            foreach ($chunks as $idx => $chunk) {
                $stats['chunks']++;
                $estimate = (int) ceil(mb_strlen($chunk, 'UTF-8') / 4);
                $ins->execute([$docId, $idx, $chunk, $estimate]);
                $chunkId = (int) $this->pdo->lastInsertId();

                try {
                    if ($llm->isAvailable()) {
                        $embedding = $llm->embed($chunk, $model);
                        $this->vectors->upsert('knowledge', (string) $chunkId, $embedding, ['model' => $model]);
                        $stats['embedded']++;
                    }
                } catch (\Throwable) {
                    $stats['errors']++;
                }

                if ($onProgress) {
                    $onProgress($docId, $idx);
                }
            }
        }

        $this->bustSearchCache();

        return $stats;
    }

    /** Сброс файлового KB-кэша после reindex (best effort). */
    public function bustSearchCache(): void
    {
        $dir = dirname(__DIR__, 3) . '/storage/cache/ai';
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            // Не трогаем embed-кэш жёстко: только короткие kb:* живут в тех же файлах по hash
            // Полная очистка безопасна — TTL восстановит.
            @unlink($file);
        }
    }

    /** @return list<array> */
    private function vectorSearch(string $query, int $limit): array
    {
        try {
            $llm = $this->llm ?? new OllamaProvider();
            if (!$llm->isAvailable()) {
                return [];
            }
            $model = (string) AiConfig::get('embedding_model', 'nomic-embed-text');
            $embedding = $llm->embed($query, $model);
            $minScore = (float) AiConfig::get('vector.min_score', 0.35);
            $topK = (int) AiConfig::get('vector.top_k', max(5, $limit));
            $hits = $this->vectors->search('knowledge', $embedding, $topK, $minScore);
            if ($hits === []) {
                return [];
            }

            $ids = array_map(static fn ($h) => (int) $h['id'], $hits);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT c.id AS chunk_id, c.content, c.document_id, k.title, k.category
                 FROM ai_knowledge_chunks c
                 JOIN ai_knowledge_base k ON k.id = c.document_id
                 WHERE c.id IN ({$placeholders}) AND k.is_active = 1"
            );
            $stmt->execute($ids);
            $rows = $stmt->fetchAll() ?: [];
            $byId = [];
            foreach ($rows as $row) {
                $byId[(int) $row['chunk_id']] = $row;
            }

            $out = [];
            foreach ($hits as $hit) {
                $id = (int) $hit['id'];
                if (!isset($byId[$id])) {
                    continue;
                }
                $row = $byId[$id];
                $out[] = [
                    'id' => (int) $row['document_id'],
                    'title' => (string) $row['title'],
                    'content' => (string) $row['content'],
                    'category' => (string) ($row['category'] ?? ''),
                    'score' => $hit['score'],
                    'source' => 'vector',
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<array> $fulltext
     * @param list<array> $vector
     * @return list<array>
     */
    private function mergeResults(array $fulltext, array $vector, int $limit): array
    {
        $seen = [];
        $merged = [];
        foreach (array_merge($vector, $fulltext) as $item) {
            $key = md5(mb_substr((string) ($item['title'] ?? '') . '|' . (string) ($item['content'] ?? ''), 0, 200));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $item;
            if (count($merged) >= $limit) {
                break;
            }
        }
        return $merged;
    }

    /** @return list<string> */
    private function chunkText(string $text, int $maxChars = 800): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text, 'UTF-8') <= $maxChars) {
            return [$text];
        }

        $chunks = [];
        $len = mb_strlen($text, 'UTF-8');
        $offset = 0;
        while ($offset < $len) {
            $slice = mb_substr($text, $offset, $maxChars, 'UTF-8');
            $chunks[] = $slice;
            $offset += max(1, $maxChars - 100); // overlap
        }
        return $chunks;
    }
}
