<?php

declare(strict_types=1);

namespace App\Services\TryOn\Providers;

final class ProviderJobState
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $state,
        public readonly string $externalId,
        public readonly ?string $resultUrl = null,
        public readonly ?string $errorCode = null,
        public readonly ?int $costMicros = null,
        /** Готовые байты результата (Fake) — вместо скачивания по URL. */
        public readonly ?string $resultBytes = null,
    ) {
    }
}
