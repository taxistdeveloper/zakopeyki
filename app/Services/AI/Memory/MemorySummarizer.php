<?php

declare(strict_types=1);

namespace App\Services\AI\Memory;

/**
 * Сжимает набор episodic/user memories в краткое preference-резюме.
 */
final class MemorySummarizer
{
    /**
     * @param list<array> $memories
     */
    public function summarize(array $memories): string
    {
        if ($memories === []) {
            return '';
        }
        $lines = [];
        foreach (array_slice($memories, 0, 10) as $m) {
            $lines[] = '- ' . trim((string) ($m['content'] ?? ''));
        }
        return "Известные предпочтения пользователя:\n" . implode("\n", $lines);
    }
}
