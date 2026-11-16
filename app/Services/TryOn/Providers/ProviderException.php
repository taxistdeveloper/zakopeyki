<?php

declare(strict_types=1);

namespace App\Services\TryOn\Providers;

/**
 * Ошибка провайдера с нормализованным кодом. Текст провайдера сюда не попадает (может содержать ПД).
 */
final class ProviderException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly bool $retryable,
        public readonly int $httpStatus = 0,
    ) {
        parent::__construct($errorCode . ($httpStatus ? ' (HTTP ' . $httpStatus . ')' : ''));
    }
}
