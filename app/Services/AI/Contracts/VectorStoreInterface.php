<?php

declare(strict_types=1);

namespace App\Services\AI\Contracts;

interface VectorStoreInterface
{
    /**
     * @param list<float> $embedding
     */
    public function upsert(string $collection, string $externalId, array $embedding, array $meta = []): void;

    /**
     * @param list<float> $query
     * @return list<array{id:string,score:float,meta:array}>
     */
    public function search(string $collection, array $query, int $topK = 5, float $minScore = 0.0): array;

    public function delete(string $collection, string $externalId): void;
}
