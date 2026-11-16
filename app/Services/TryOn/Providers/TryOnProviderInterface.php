<?php

declare(strict_types=1);

namespace App\Services\TryOn\Providers;

/**
 * Единый контракт AI-провайдера примерки (docs/tryon/05-providers.md §1).
 */
interface TryOnProviderInterface
{
    public function id(): string;

    /** true — результаты не являются AI-генерацией (Fake, mock-режим). */
    public function isTest(): bool;

    public function isConfigured(): bool;

    public function supports(string $tryonType): bool;

    public function estimateCostMicros(string $tryonType): int;

    /** @throws ProviderException */
    public function submit(TryOnSubmission $submission): ProviderJobState;

    /** @throws ProviderException */
    public function status(string $externalId): ProviderJobState;

    /** Удалить все данные пользователя у провайдера. false — не поддерживается. */
    public function purgeUser(string $externalUserId): bool;

    /** false — отмена не поддерживается. */
    public function cancel(string $externalId): bool;
}
