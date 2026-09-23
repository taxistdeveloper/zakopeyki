<?php

declare(strict_types=1);

namespace App\Services\AI\Core;

use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Providers\OllamaProvider;

final class ModelRouter
{
    public function __construct(private readonly LLMProviderInterface $provider = new OllamaProvider())
    {
    }

    public function provider(): LLMProviderInterface
    {
        return $this->provider;
    }

    public function modelFor(string $taskType): string
    {
        $cfg = AiConfig::all();
        return match ($taskType) {
            'vision' => (string) ($cfg['vision_model'] ?? 'llava'),
            'embedding' => (string) ($cfg['embedding_model'] ?? 'nomic-embed-text'),
            'intent', 'simple' => (string) ($cfg['model'] ?? 'qwen2.5:7b-instruct'),
            default => (string) ($cfg['model'] ?? 'qwen2.5:7b-instruct'),
        };
    }
}
