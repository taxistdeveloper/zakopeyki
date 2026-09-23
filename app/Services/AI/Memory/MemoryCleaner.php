<?php

declare(strict_types=1);

namespace App\Services\AI\Memory;

final class MemoryCleaner
{
    public function __construct(private readonly MemoryRepository $repo = new MemoryRepository())
    {
    }

    public function run(): array
    {
        $expired = $this->repo->deleteExpired();
        return ['expired_deleted' => $expired];
    }
}
