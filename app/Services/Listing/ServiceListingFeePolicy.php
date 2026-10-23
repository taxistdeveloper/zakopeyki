<?php

declare(strict_types=1);

namespace App\Services\Listing;

/**
 * VAS-16: тариф и флаг новых публикаций типа service (доска «Услуги»).
 * Сумма только с сервера; клиентские amount/price для fee игнорируются.
 * Бесплатная квота / промо / waiver пакета не применяются.
 *
 * Переключатель service_listing_publish_enabled:
 * — OFF: запрет новых публикаций type=service (store / AI draft / import create);
 * — не трогает каталог/просмотр, edit/delete существующих, другие типы, Safe Deal, VAS, историю платежей.
 * Асинхронных «висящих» оплат listing fee нет (синхронный кошелёк).
 */
final class ServiceListingFeePolicy
{
    public const TYPE = 'service';

    /** Утверждённый тариф публикации одного объявления «Услуги» (KZT). */
    public const FEE_KZT = 500;

    public const SETTING_KEY = 'service_listing_publish_enabled';

    /** Соседние контуры, которые этот переключатель не меняет. */
    public const UNRELATED_CONTROLS = [
        'boost_enabled',
        'highlight_enabled',
        'top_fixed_enabled',
        'top_fixed_xl_enabled',
        'vip_enabled',
        'business_package_sales_enabled',
        'business_package_features_enabled',
    ];

    public static function isServiceType(string $type): bool
    {
        return $type === self::TYPE;
    }

    /**
     * Стоимость публикации для типа. Для не-service — 0 (другие типы не тарифицируются здесь).
     * Клиентская сумма не принимается.
     */
    public static function feeKztForType(string $type, ?int $clientAmount = null): int
    {
        unset($clientAmount);

        return self::isServiceType($type) ? self::FEE_KZT : 0;
    }

    /** Meta ключ wallet_transactions для связи с объявлением. */
    public static function walletMeta(int $productId): string
    {
        return 'listing:' . $productId;
    }

    public static function canToggle(?string $role): bool
    {
        return $role === 'admin';
    }

    /**
     * Нет ключа → включено (поведение после VAS-16.3). Сбой чтения → выключено (fail-closed).
     */
    public static function resolveEnabled(mixed $stored, bool $readFailed = false): bool
    {
        if ($readFailed) {
            return false;
        }
        if ($stored === null) {
            return true;
        }
        if (is_bool($stored)) {
            return $stored;
        }
        if (is_int($stored)) {
            return $stored === 1;
        }
        if (is_string($stored)) {
            return in_array(strtolower(trim($stored)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @return array{ok: bool, code?: string}
     */
    public static function assertNewPublishAllowed(string $type, bool $enabled): array
    {
        if (!self::isServiceType($type)) {
            return ['ok' => true];
        }
        if (!$enabled) {
            return ['ok' => false, 'code' => 'publish_disabled'];
        }

        return ['ok' => true];
    }

    /**
     * @return array{action: string, context: array<string, mixed>}
     */
    public static function toggleAudit(int $actorId, bool $before, bool $after, ?string $at = null): array
    {
        return [
            'action' => $after ? 'service_listing.feature_on' : 'service_listing.feature_off',
            'context' => [
                'actor_id' => $actorId,
                'at' => $at ?? date('c'),
                'before' => $before,
                'after' => $after,
                'setting' => self::SETTING_KEY,
                'blocks_new_service_publish' => !$after,
                'keeps_existing_listings' => true,
                'keeps_payment_history' => true,
                'no_refunds' => true,
                'unaffected' => self::UNRELATED_CONTROLS,
            ],
        ];
    }
}
