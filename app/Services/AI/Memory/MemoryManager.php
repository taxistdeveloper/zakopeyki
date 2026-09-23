<?php

declare(strict_types=1);

namespace App\Services\AI\Memory;

/**
 * Фасад памяти: retrieve + maybe write + summarize.
 */
final class MemoryManager
{
    public function __construct(
        private readonly MemoryRetriever $retriever = new MemoryRetriever(),
        private readonly MemoryWriter $writer = new MemoryWriter(),
        private readonly MemorySummarizer $summarizer = new MemorySummarizer(),
        private readonly MemoryCleaner $cleaner = new MemoryCleaner(),
        private readonly MemoryRepository $repo = new MemoryRepository(),
    ) {
    }

    /** @return list<string> */
    public function loadUserMemories(?int $userId): array
    {
        if ($userId === null || $userId <= 0) {
            return [];
        }
        return $this->retriever->retrieveForPrompt($userId);
    }

    public function summarizeForPrompt(?int $userId): string
    {
        if ($userId === null || $userId <= 0) {
            return '';
        }
        return $this->summarizer->summarize($this->retriever->retrieveRaw($userId));
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{stored:bool,id:?int,reason:string}
     */
    public function observeInteraction(?int $userId, string $message, string $intent, array $parameters = []): array
    {
        if ($userId === null || $userId <= 0) {
            return ['stored' => false, 'id' => null, 'reason' => 'guest'];
        }
        return $this->writer->maybeStore($userId, $message, $intent, $parameters);
    }

    public function cleanup(): array
    {
        return $this->cleaner->run();
    }

    public function repository(): MemoryRepository
    {
        return $this->repo;
    }
}
