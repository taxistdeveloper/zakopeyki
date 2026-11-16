<?php

declare(strict_types=1);

namespace App\Services\TryOn;

/**
 * Отказ API примерки со стабильным машинным кодом (docs/tryon/02-api-contracts.md).
 */
final class TryOnException extends \RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        public readonly array $extra = [],
    ) {
        parent::__construct($errorCode, $httpStatus);
    }
}
