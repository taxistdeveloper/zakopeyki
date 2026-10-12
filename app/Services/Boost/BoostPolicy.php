<?php

declare(strict_types=1);

namespace App\Services\Boost;

/**
 * Правила Boost, которые не зависят от базы.
 * Цена и решение по callback живут здесь, чтобы их нельзя было обойти из HTTP.
 */
final class BoostPolicy
{
    public const PRICE_KZT = 390;

    public const SETTING_KEY = 'boost_enabled';

    /** Соседние контуры, которые этот переключатель не меняет. */
    public const UNRELATED_CONTROLS = [
        'highlight_enabled',
        'stub_mode',
        'business_packages.priority_boost',
        'business_usage.boosts',
        'business.lim_boosts',
    ];

    public const PG_PREFIX = 'zk-boost-';

    public const STATUS_PENDING = 'pending_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_APPLY_FAILED = 'apply_failed';

    /** Типы, которые уже попадают в каталог и поиск (не курс и не биржа поручений). */
    public const ELIGIBLE_TYPES = ['used', 'new', 'auction', 'free', 'exchange', 'service'];

    /**
     * @param array<string, mixed> $input
     */
    public static function serverAmount(array $input): int
    {
        unset($input['amount'], $input['price'], $input['total'], $input['sum']);

        return self::PRICE_KZT;
    }

    /**
     * @param array<string, mixed>|null $product
     */
    public static function purchaseBlock(?array $product, int $userId, bool $enabled): ?string
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
        if (!self::isEligibleListing($product)) {
            return 'bad_status';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $product
     */
    public static function isEligibleListing(array $product): bool
    {
        if ((string) ($product['status'] ?? '') !== 'active') {
            return false;
        }

        return in_array((string) ($product['type'] ?? ''), self::ELIGIBLE_TYPES, true);
    }

    public static function isBoostPgOrderId(string $pgOrderId): bool
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

    public static function catalogOrderSql(): string
    {
        return 'ORDER BY COALESCE(p.sort_at, p.created_at) DESC, p.id DESC';
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
     * Решение по уже заблокированной строке заказа. Деньги и сортировку пишет сервис.
     *
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

        if ($status === self::STATUS_APPLIED) {
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
     * Состояние карточки в профиле. Заказ не создаёт.
     *
     * @param array<string, mixed>|null $product
     * @param array<string, mixed>|null $order
     * @return array{code: string, can_purchase: bool, continue_payment: bool, notice: ?string}
     */
    public static function uiState(bool $enabled, ?array $product, int $userId, ?array $order, bool $gatewayReady = true): array
    {
        if ($product === null || $userId <= 0 || (int) ($product['user_id'] ?? 0) !== $userId) {
            return self::ui('hidden');
        }

        $eligible = self::isEligibleListing($product);
        $hasOrder = is_array($order) && $order !== [];
        $status = $hasOrder ? (string) ($order['status'] ?? '') : '';
        $hasRedirect = $hasOrder && trim((string) ($order['redirect_url'] ?? '')) !== '';

        if ($hasOrder && !in_array($status, [
            self::STATUS_PENDING,
            self::STATUS_PAID,
            self::STATUS_APPLY_FAILED,
            self::STATUS_APPLIED,
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

        if ($status === self::STATUS_APPLIED) {
            if (!$enabled) {
                return self::ui('applied_off');
            }
            if (!$eligible) {
                return self::ui('applied');
            }
            if (!$gatewayReady) {
                return self::ui('applied', false, false, 'gateway');
            }

            return self::ui('applied', true);
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

        return self::ui('ready', true);
    }

    public static function uiMessageKey(string $code): ?string
    {
        return match ($code) {
            'ready' => 'boost.ui_available',
            'gateway' => 'boost.err_gateway',
            'pending_wait', 'pending_pay' => 'boost.payment_pending',
            'processing' => 'boost.paid_waiting',
            'processing_off' => 'boost.paid_waiting_off',
            'applied', 'applied_off' => 'boost.applied',
            'failed' => 'boost.payment_failed',
            'failed_off' => 'boost.failed_off',
            'disabled_pending' => 'boost.disabled_pending',
            'ineligible' => 'boost.ineligible_open',
            default => null,
        };
    }

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
     * Запись в существующий журнал: кто, когда и какое состояние поставлено.
     * Выключение не описывает удаление объявлений, истории или возврат платежа.
     *
     * @return array{action: string, context: array<string, mixed>}
     */
    public static function toggleAudit(int $actorId, bool $before, bool $after, ?string $at = null): array
    {
        return [
            'action' => $after ? 'boost.feature_on' : 'boost.feature_off',
            'context' => [
                'actor_id' => $actorId,
                'at' => $at ?? date('c'),
                'setting' => self::SETTING_KEY,
                'before' => $before,
                'after' => $after,
                'keeps_listings' => true,
                'keeps_orders' => true,
                'keeps_created_at' => true,
                'keeps_sort_at' => true,
                'refunds' => false,
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
        ], true);
    }

    /**
     * @return array{code: string, can_purchase: bool, continue_payment: bool, notice: ?string}
     */
    private static function ui(string $code, bool $canPurchase = false, bool $continue = false, ?string $notice = null): array
    {
        return [
            'code' => $code,
            'can_purchase' => $canPurchase,
            'continue_payment' => $continue,
            'notice' => $notice,
        ];
    }
}
