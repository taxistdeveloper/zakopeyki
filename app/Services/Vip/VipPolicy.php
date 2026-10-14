<?php

declare(strict_types=1);

namespace App\Services\Vip;

use App\Services\TopFixed\TopFixedPolicy;

/**
 * Правила VIP «Всё включено». Цена, срок, состав и решение по оплате
 * задаёт сервер. Клиентские сумма, срок и флаг доступности не читаются.
 *
 * Пакет — одна оплата. Top Fixed и Highlight в его составе пишутся отдельными
 * строками с нулевой ценой и не проходят через платную покупку этих услуг.
 * Самостоятельные заказы этих услуг не обновляются.
 *
 * Два автоматических поднятия стоят на +3 и +6 суток от активации.
 * Шаг в 3 суток — тот же интервал, который проект уже использует для «3 Поднятия».
 * Оба момента раньше конца семи суток и не совпадают с моментом покупки.
 */
final class VipPolicy
{
    public const PRICE_KZT = 2290;

    public const DURATION_DAYS = 7;

    public const DURATION_SECONDS = 604800;

    public const LIFT_COUNT = 2;

    /** @var list<int> */
    public const LIFT_OFFSETS_SECONDS = [259200, 518400];

    /** Компоненты пакета не тарифицируются. */
    public const COMPONENT_AMOUNT_KZT = 0;

    public const SETTING_KEY = 'vip_enabled';

    /** Уже оплаченный пакет остаётся до своей даты. Новые покупки при выключении не стартуют. */
    public const ACTIVE_SURVIVES_DISABLE = true;

    /**
     * Подтверждение оплаты после выключения не включает пакет.
     * Если шлюз позволяет отклонить платёж, он отклоняется. Возврат этим правилом не создаётся:
     * так же решено для Top Fixed, Highlight, Boost и Top Fixed XL.
     */
    public const PAYMENT_AFTER_DISABLE = 'no_apply';

    /** Повторное включение не возвращает завершённый срок. */
    public const REENABLE_REACTIVATES_EXPIRED = false;

    /** Соседние контуры, которые этот переключатель не меняет. */
    public const UNRELATED_CONTROLS = [
        'boost_enabled',
        'three_lifts_enabled',
        'highlight_enabled',
        'top_fixed_enabled',
        'top_fixed_xl_enabled',
        'stub_mode',
        'business_packages.priority_boost',
        'business_usage.boosts',
        'business.lim_boosts',
    ];

    public const PG_PREFIX = 'zk-vip-';

    public const STATUS_PENDING = 'pending_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_APPLY_FAILED = 'apply_failed';

    public const LIFT_PENDING = 'pending';
    public const LIFT_DONE = 'done';
    public const LIFT_SKIPPED = 'skipped';

    /**
     * @param array<string, mixed> $input
     */
    public static function serverAmount(array $input): int
    {
        unset($input['amount'], $input['price'], $input['total'], $input['sum'], $input['enabled'], $input['days'], $input['category']);

        return self::PRICE_KZT;
    }

    /**
     * Семь суток от активации на сервере.
     *
     * @return array{starts_at: int, ends_at: int}
     */
    public static function period(int $activatedAt): array
    {
        return [
            'starts_at' => $activatedAt,
            'ends_at' => $activatedAt + self::DURATION_SECONDS,
        ];
    }

    /**
     * Окно компонентов совпадает с окном пакета и не длиннее его.
     *
     * @return array{starts_at: int, ends_at: int}
     */
    public static function componentPeriod(int $activatedAt): array
    {
        return self::period($activatedAt);
    }

    /**
     * Ровно два слота внутри пакета. Слот за границей срока отбрасывается.
     *
     * @return list<array{slot: int, at: int}>
     */
    public static function liftSchedule(int $activatedAt): array
    {
        $end = $activatedAt + self::DURATION_SECONDS;
        $slots = [];
        foreach (self::LIFT_OFFSETS_SECONDS as $index => $offset) {
            $at = $activatedAt + $offset;
            if ($at <= $activatedAt || $at >= $end) {
                continue;
            }
            $slots[] = ['slot' => $index + 1, 'at' => $at];
        }

        return $slots;
    }

    /**
     * Повторный запуск не выполняет уже завершённый слот.
     * После конца пакета ожидающий слот пропускается и не двигает объявление.
     *
     * @return array{run: bool, next: string, idempotent: bool}
     */
    public static function decideLift(
        string $status,
        int $scheduledAt,
        int $packageEndsAt,
        int $now,
        bool $listingEligible
    ): array {
        if ($status === self::LIFT_DONE || $status === self::LIFT_SKIPPED) {
            return ['run' => false, 'next' => $status, 'idempotent' => true];
        }
        if ($now >= $packageEndsAt || $scheduledAt >= $packageEndsAt) {
            return ['run' => false, 'next' => self::LIFT_SKIPPED, 'idempotent' => false];
        }
        if ($scheduledAt > $now || !$listingEligible) {
            return ['run' => false, 'next' => self::LIFT_PENDING, 'idempotent' => true];
        }

        return ['run' => true, 'next' => self::LIFT_DONE, 'idempotent' => false];
    }

    public static function windowActive(?string $startsAt, ?string $endsAt, ?int $now = null): bool
    {
        if ($startsAt === null || $endsAt === null || trim($startsAt) === '' || trim($endsAt) === '') {
            return false;
        }
        $start = strtotime($startsAt);
        $end = strtotime($endsAt);
        if ($start === false || $end === false || $end <= $start) {
            return false;
        }
        $now ??= time();

        return $start <= $now && $end > $now;
    }

    public static function displayActive(bool $cachedFlag, ?string $startsAt, ?string $endsAt, ?int $now = null): bool
    {
        unset($cachedFlag);

        return self::windowActive($startsAt, $endsAt, $now);
    }

    public static function sqlDateTime(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    public static function formatUntil(?string $endsAt): string
    {
        if ($endsAt === null || trim($endsAt) === '') {
            return '';
        }
        $ts = strtotime($endsAt);

        return $ts === false ? '' : date('d.m.Y H:i', $ts);
    }

    public static function isoUntil(?string $endsAt): string
    {
        if ($endsAt === null || trim($endsAt) === '') {
            return '';
        }
        $ts = strtotime($endsAt);

        return $ts === false ? '' : date('c', $ts);
    }

    /**
     * @param array<string, mixed>|null $product
     */
    public static function purchaseBlock(?array $product, int $userId, bool $enabled, bool $hasActiveWindow = false): ?string
    {
        if (!$enabled) {
            return 'disabled';
        }
        if ($product === null) {
            return 'not_found';
        }
        if ((int) ($product['user_id'] ?? 0) !== $userId || $userId <= 0) {
            return 'forbidden';
        }
        if (!TopFixedPolicy::isEligibleListing($product)) {
            return 'bad_status';
        }
        if ($hasActiveWindow) {
            return 'already_active';
        }

        return null;
    }

    public static function isVipPgOrderId(string $pgOrderId): bool
    {
        return str_starts_with($pgOrderId, self::PG_PREFIX);
    }

    public static function openKey(int $productId): string
    {
        return 'p' . $productId;
    }

    public static function keepsOpenLock(string $status): bool
    {
        return in_array($status, [self::STATUS_PENDING, self::STATUS_PAID, self::STATUS_APPLY_FAILED], true);
    }

    public static function parseExactTenge(string $raw): ?int
    {
        $raw = trim($raw);
        if (!preg_match('/^\d+(?:\.(\d+))?$/', $raw, $m)) {
            return null;
        }
        if (isset($m[1]) && $m[1] !== '' && rtrim($m[1], '0') !== '') {
            return null;
        }

        return (int) $raw;
    }

    public static function currencyOk(?string $currency): bool
    {
        if ($currency === null || trim($currency) === '') {
            return true;
        }
        $code = strtoupper(trim($currency));

        return $code === 'KZT' || $code === '398';
    }

    /**
     * @return array{
     *   apply: bool,
     *   next_status: string,
     *   gateway: string,
     *   idempotent: bool,
     *   failure_reason: ?string
     * }
     */
    public static function decideCallback(
        string $status,
        int $storedAmount,
        ?int $paidAmount,
        bool $gatewaySuccess,
        bool $featureEnabled,
        bool $listingEligible,
        bool $canReject,
        bool $currencyOk = true
    ): array {
        $decision = [
            'apply' => false,
            'next_status' => $status,
            'gateway' => 'ok',
            'idempotent' => false,
            'failure_reason' => null,
        ];

        if ($status === self::STATUS_APPLIED || $status === self::STATUS_EXPIRED) {
            $decision['idempotent'] = true;

            return $decision;
        }

        if (!$gatewaySuccess) {
            if ($status === self::STATUS_PENDING) {
                $decision['next_status'] = self::STATUS_FAILED;
                $decision['failure_reason'] = 'gateway_failed';
            }

            return $decision;
        }

        if ($status === self::STATUS_FAILED || $status === self::STATUS_CANCELLED) {
            $decision['gateway'] = $canReject ? 'rejected' : 'error';
            $decision['failure_reason'] = 'terminal';

            return $decision;
        }

        $amountOk = $currencyOk
            && $storedAmount === self::PRICE_KZT
            && $paidAmount === self::PRICE_KZT;

        if (!$amountOk) {
            $decision['gateway'] = $canReject ? 'rejected' : 'error';
            $decision['failure_reason'] = 'amount_mismatch';

            return $decision;
        }

        if (!$featureEnabled) {
            if ($status === self::STATUS_PENDING && $canReject) {
                $decision['next_status'] = self::STATUS_FAILED;
                $decision['gateway'] = 'rejected';
                $decision['failure_reason'] = 'disabled';

                return $decision;
            }
            if ($status === self::STATUS_PENDING) {
                $decision['next_status'] = self::STATUS_PAID;
                $decision['failure_reason'] = 'disabled';

                return $decision;
            }

            $decision['failure_reason'] = 'disabled';

            return $decision;
        }

        if (!$listingEligible) {
            if ($status === self::STATUS_PENDING && $canReject) {
                $decision['next_status'] = self::STATUS_FAILED;
                $decision['gateway'] = 'rejected';
                $decision['failure_reason'] = 'listing_ineligible';

                return $decision;
            }
            $decision['next_status'] = self::STATUS_APPLY_FAILED;
            $decision['failure_reason'] = 'listing_ineligible';

            return $decision;
        }

        if (in_array($status, [self::STATUS_PENDING, self::STATUS_PAID, self::STATUS_APPLY_FAILED], true)) {
            $decision['apply'] = true;
            $decision['next_status'] = self::STATUS_APPLIED;

            return $decision;
        }

        $decision['gateway'] = 'error';
        $decision['failure_reason'] = 'invalid_status';

        return $decision;
    }

    /**
     * @param array<string, mixed>|null $product
     * @param array<string, mixed>|null $order
     * @return array{code: string, can_purchase: bool, continue_payment: bool, notice: ?string, ends_at: ?string, category: ?string, lifts_remaining: ?int}
     */
    public static function uiState(bool $enabled, ?array $product, int $userId, ?array $order, bool $gatewayReady = true): array
    {
        if ($product === null || $userId <= 0 || (int) ($product['user_id'] ?? 0) !== $userId) {
            return self::ui('hidden');
        }

        $eligible = TopFixedPolicy::isEligibleListing($product);
        $hasOrder = is_array($order) && $order !== [];
        $status = $hasOrder ? (string) ($order['status'] ?? '') : '';
        $hasRedirect = $hasOrder && trim((string) ($order['redirect_url'] ?? '')) !== '';
        $endsAt = $hasOrder && isset($order['ends_at']) ? (string) $order['ends_at'] : null;
        $startsAt = $hasOrder && isset($order['starts_at']) ? (string) $order['starts_at'] : null;
        $storedCategory = $hasOrder ? trim((string) ($order['category'] ?? '')) : '';
        $currentCategory = trim((string) ($product['category'] ?? ''));
        $running = self::windowActive($startsAt, $endsAt);
        $left = $hasOrder && isset($order['lifts_remaining']) && is_numeric($order['lifts_remaining'])
            ? (int) $order['lifts_remaining']
            : null;

        if ($hasOrder && !in_array($status, [
            self::STATUS_PENDING,
            self::STATUS_PAID,
            self::STATUS_APPLY_FAILED,
            self::STATUS_APPLIED,
            self::STATUS_EXPIRED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true)) {
            return self::ui('pending_wait');
        }

        if (!$hasOrder && (!$enabled || !$eligible)) {
            return self::ui('hidden');
        }

        if ($status === self::STATUS_PENDING) {
            if (!$enabled) {
                return self::ui('disabled_pending');
            }
            if (!$eligible) {
                return self::ui('ineligible');
            }
            if ($hasRedirect) {
                return self::ui('pending_pay', false, true);
            }

            return self::ui('pending_wait');
        }

        if ($status === self::STATUS_PAID || $status === self::STATUS_APPLY_FAILED) {
            return self::ui($enabled ? 'processing' : 'processing_off');
        }

        if ($status === self::STATUS_APPLIED || $status === self::STATUS_EXPIRED) {
            if ($running && $storedCategory !== '' && !TopFixedPolicy::categoryMatches($storedCategory, $currentCategory)) {
                return self::ui('moved', false, false, null, $endsAt, $storedCategory, $left);
            }
            if ($running) {
                return self::ui($enabled ? 'active' : 'active_off', false, false, null, $endsAt, $storedCategory !== '' ? $storedCategory : $currentCategory, $left);
            }
            if (!$enabled) {
                return self::ui('expired_off', false, false, null, $endsAt, $storedCategory, $left);
            }
            if (!$eligible) {
                return self::ui('expired', false, false, null, $endsAt, $storedCategory, $left);
            }
            if (!$gatewayReady) {
                return self::ui('expired', false, false, 'gateway', $endsAt, $storedCategory, $left);
            }

            return self::ui('expired', true, false, null, $endsAt, $storedCategory, $left);
        }

        if ($status === self::STATUS_FAILED || $status === self::STATUS_CANCELLED) {
            if (!$enabled) {
                return self::ui('failed_off');
            }
            if (!$eligible) {
                return self::ui('failed');
            }
            if (!$gatewayReady) {
                return self::ui('failed', false, false, 'gateway');
            }

            return self::ui('failed', true);
        }

        if (!$enabled || !$eligible) {
            return self::ui('hidden');
        }
        if (!$gatewayReady) {
            return self::ui('gateway');
        }

        return self::ui('ready', true, false, null, null, $currentCategory);
    }

    public static function uiMessageKey(string $code): ?string
    {
        return match ($code) {
            'ready' => 'vip.ui_available',
            'gateway' => 'vip.err_gateway',
            'pending_wait', 'pending_pay' => 'vip.payment_pending',
            'processing' => 'vip.paid_waiting',
            'processing_off' => 'vip.paid_waiting_off',
            'active' => 'vip.active',
            'active_off' => 'vip.active_off',
            'moved' => 'vip.moved',
            'expired' => 'vip.expired',
            'expired_off' => 'vip.expired_off',
            'failed' => 'vip.payment_failed',
            'failed_off' => 'vip.failed_off',
            'disabled_pending' => 'vip.disabled_pending',
            'ineligible' => 'vip.ineligible_open',
            default => null,
        };
    }

    public static function canToggle(?string $role): bool
    {
        return $role === 'admin';
    }

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
     * Выключение не снимает оплаченный пакет, не удаляет историю и не описывает возврат.
     *
     * @return array{action: string, context: array<string, mixed>}
     */
    public static function toggleAudit(int $actorId, bool $before, bool $after, ?string $at = null): array
    {
        return [
            'action' => $after ? 'vip.feature_on' : 'vip.feature_off',
            'context' => [
                'actor_id' => $actorId,
                'at' => $at ?? date('c'),
                'setting' => self::SETTING_KEY,
                'before' => $before,
                'after' => $after,
                'keeps_listings' => true,
                'keeps_orders' => true,
                'keeps_active_window' => self::ACTIVE_SURVIVES_DISABLE,
                'restarts_expired' => self::REENABLE_REACTIVATES_EXPIRED,
                'changes_existing_ends_at' => false,
                'refunds' => false,
                'payment_after_disable' => self::PAYMENT_AFTER_DISABLE,
                'other_settings' => [],
            ],
        ];
    }

    public static function uiWatches(string $code): bool
    {
        return in_array($code, [
            'pending_wait',
            'pending_pay',
            'processing',
            'processing_off',
            'disabled_pending',
            'active',
            'active_off',
            'moved',
        ], true);
    }

    /**
     * @return array{code: string, can_purchase: bool, continue_payment: bool, notice: ?string, ends_at: ?string, category: ?string, lifts_remaining: ?int}
     */
    private static function ui(
        string $code,
        bool $canPurchase = false,
        bool $continue = false,
        ?string $notice = null,
        ?string $endsAt = null,
        ?string $category = null,
        ?int $liftsRemaining = null
    ): array {
        return [
            'code' => $code,
            'can_purchase' => $canPurchase,
            'continue_payment' => $continue,
            'notice' => $notice,
            'ends_at' => $endsAt !== null && $endsAt !== '' ? $endsAt : null,
            'category' => $category !== null && $category !== '' ? $category : null,
            'lifts_remaining' => $liftsRemaining,
        ];
    }
}
