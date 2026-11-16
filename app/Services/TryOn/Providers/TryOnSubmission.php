<?php

declare(strict_types=1);

namespace App\Services\TryOn\Providers;

final class TryOnSubmission
{
    public function __construct(
        public readonly string $jobPublicId,
        public readonly string $tryonType,
        public readonly string $personJpegPath,
        public readonly string $garmentImagePath,
        public readonly string $garmentTitle,
        public readonly string $externalUserId,
    ) {
    }
}
