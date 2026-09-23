<?php

declare(strict_types=1);

namespace App\Services\AI\Memory;

final class MemoryRetriever
{
    public function __construct(private readonly MemoryRepository $repo = new MemoryRepository())
    {
    }

    /**
     * @return list<string>
     */
    public function retrieveForPrompt(int $userId, int $limit = 8): array
    {
        $rows = $this->repo->forUser($userId, $limit);
        $out = [];
        foreach ($rows as $row) {
            $out[] = (string) $row['content'];
        }
        return $out;
    }

    /**
     * @return list<array>
     */
    public function retrieveRaw(int $userId, int $limit = 8): array
    {
        return $this->repo->forUser($userId, $limit);
    }
}
