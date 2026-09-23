<?php

declare(strict_types=1);

namespace App\Services\AI\DTO;

use App\Services\AI\Enum\Intent;

readonly class IntentResult
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public Intent $intent,
        public float $confidence,
        public string $method,
        public array $parameters = [],
        public string $language = 'ru',
    ) {
    }
}
