<?php

declare(strict_types=1);

namespace App\Services\AI\Contracts;

interface LLMProviderInterface
{
    /**
     * @param list<array{role:string,content:string|array}> $messages
     * @return array{content:string,total_duration:int,eval_count:int,model:string}
     */
    public function chat(array $messages, ?float $temperature = null, bool $jsonMode = false, ?string $model = null): array;

    /**
     * @param callable(string $delta): void $onDelta
     * @param list<array{role:string,content:string|array}> $messages
     */
    public function chatStream(array $messages, callable $onDelta, ?float $temperature = null, ?string $model = null): array;

    /** @return list<float> */
    public function embed(string $text, ?string $model = null): array;

    /**
     * Vision: image as base64 or absolute path.
     * @return array{content:string,model:string}
     */
    public function vision(string $prompt, string $imageBase64OrPath, ?string $model = null): array;

    public function isAvailable(): bool;

    public function providerName(): string;
}
