<?php

declare(strict_types=1);

namespace App\Services\ThreeLifts;

/**
 * Доступность «3 Поднятия». Цена и расписание здесь не задаются:
 * момент первого поднятия ещё не утверждён.
 */
final class ThreeLiftsPolicy
{
    public const SETTING_KEY = 'three_lifts_enabled';

    public const PRICE_KZT = 990;

    /**
     * Уже созданное расписание при выключении сохраняется.
     * Оплаченный заказ без слотов этим переключателем не активируется и не отменяется:
     * это решение в спецификации не утверждено.
     */
    public const PAID_SCHEDULE_ON_DISABLE = 'keep';

    public const UNSCHEDULED_PAID_ON_DISABLE = 'undecided';

    /** Ключи, которые этот переключатель не записывает. */
    public const UNRELATED_CONTROLS = [
        'boost_enabled',
        'highlight_enabled',
        'top_fixed_enabled',
        'top_fixed_xl_enabled',
        'stub_mode',
        'business_packages.priority_boost',
        'business_usage.boosts',
        'business.lim_boosts',
    ];

    public static function canToggle(?string $role): bool
    {
        return $role === 'admin';
    }

    /**
     * Неясное или недоступное значение не разрешает новую платную операцию.
     */
    public static function resolveEnabled(mixed $stored, bool $readFailed = false): bool
    {
        if ($readFailed || $stored === null) {
            return false;
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
     * Новая покупка. Клиентское поле доступности не читается.
     *
     * @param array<string, mixed> $input
     */
    public static function newPurchaseBlock(bool $serverEnabled, array $input = []): ?string
    {
        unset($input['enabled'], $input['three_lifts_enabled'], $input['amount'], $input['price']);

        return $serverEnabled ? null : 'disabled';
    }

    public static function scheduledLiftsSurviveDisable(): bool
    {
        return self::PAID_SCHEDULE_ON_DISABLE === 'keep';
    }

    /**
     * @return array{write: bool, unchanged: bool, error_code: ?string}
     */
    public static function decideToggle(bool $current, bool $requested, ?bool $expected): array
    {
        if ($expected === null || $expected !== $current) {
            return ['write' => false, 'unchanged' => false, 'error_code' => 'conflict'];
        }
        if ($current === $requested) {
            return ['write' => false, 'unchanged' => true, 'error_code' => null];
        }

        return ['write' => true, 'unchanged' => false, 'error_code' => null];
    }

    /**
     * Выключение не удаляет заказы, слоты и позицию и не описывает возврат.
     *
     * @return array{action: string, context: array<string, mixed>}
     */
    public static function toggleAudit(int $actorId, bool $before, bool $after, ?string $at = null): array
    {
        return [
            'action' => $after ? 'three_lifts.feature_on' : 'three_lifts.feature_off',
            'context' => [
                'actor_id' => $actorId,
                'at' => $at ?? date('c'),
                'setting' => self::SETTING_KEY,
                'before' => $before,
                'after' => $after,
                'keeps_listings' => true,
                'keeps_orders' => true,
                'keeps_schedules' => true,
                'keeps_created_at' => true,
                'keeps_sort_at' => true,
                'cancels_paid' => false,
                'refunds' => false,
                'other_settings' => [],
                'unscheduled_paid' => self::UNSCHEDULED_PAID_ON_DISABLE,
            ],
        ];
    }
}
