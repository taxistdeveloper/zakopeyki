<?php

declare(strict_types=1);

namespace App\Services\AI\Memory;

use App\Services\AI\Core\AiConfig;

final class MemoryWriter
{
    public function __construct(
        private readonly MemoryRepository $repo = new MemoryRepository(),
        private readonly MemoryScorer $scorer = new MemoryScorer(),
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{stored:bool,id:?int,reason:string}
     */
    public function maybeStore(int $userId, string $message, string $intent, array $parameters = []): array
    {
        $minImportance = (float) AiConfig::get('memory.min_importance', 0.55);
        $decision = $this->scorer->score($message, $intent, $parameters);

        if (!$decision['should_store'] || $decision['content'] === null) {
            return ['stored' => false, 'id' => null, 'reason' => $decision['reason']];
        }
        if ($decision['importance'] < $minImportance) {
            return ['stored' => false, 'id' => null, 'reason' => 'below_threshold'];
        }

        $existing = $this->repo->findSimilar($userId, $decision['content']);
        if ($existing) {
            $newImp = max((float) $existing['importance'], $decision['importance']);
            $this->repo->bumpImportance((int) $existing['id'], min(1.0, $newImp + 0.05));
            return ['stored' => false, 'id' => (int) $existing['id'], 'reason' => 'dedup_boost'];
        }

        $ttlDays = (int) AiConfig::get('memory.ttl_days_episodic', 90);
        $expires = $decision['type'] === 'episodic'
            ? date('Y-m-d H:i:s', time() + $ttlDays * 86400)
            : null;

        $id = $this->repo->create($userId, [
            'type' => $decision['type'],
            'content' => $decision['content'],
            'importance' => $decision['importance'],
            'confidence' => 0.7,
            'source' => 'interaction',
            'meta' => ['intent' => $intent, 'parameters' => $parameters],
            'expires_at' => $expires,
        ]);

        $max = (int) AiConfig::get('memory.max_user_memories', 50);
        $this->repo->deleteOldestBeyondLimit($userId, $max);

        return ['stored' => true, 'id' => $id, 'reason' => $decision['reason']];
    }
}
