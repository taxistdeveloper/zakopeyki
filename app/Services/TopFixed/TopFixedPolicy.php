<?php

declare(strict_types=1);

namespace App\Services\TopFixed;

/**
 * Правила Top Fixed, которые не зависят от базы.
 * Цена, срок и решение по callback живут здесь, чтобы их нельзя было обойти из HTTP.
 *
 * Закрепление поднимает объявление выше обычных только в выдаче целевой категории.
 * Число мест и конкретная позиция не заданы: внутри группы закреплённых сохраняется
 * уже действующий порядок каталога (sort_at, затем id).
 */
final class TopFixedPolicy
{
    public const PRICE_KZT = 1490;

    public const DURATION_DAYS = 7;

    public const DURATION_SECONDS = 604800;

    public const SETTING_KEY = 'top_fixed_enabled';

    /** Уже оплаченное закрепление остаётся до своей даты. Новые покупки при выключении не стартуют. */
    public const ACTIVE_SURVIVES_DISABLE = true;

    /** Повторное включение не возвращает завершённый срок и не сдвигает уже записанные даты. */
    public const REENABLE_REACTIVATES_EXPIRED = false;

    /** Соседние контуры, которые этот переключатель не меняет. */
    public const UNRELATED_CONTROLS = [
        'boost_enabled',
        'three_lifts_enabled',
        'highlight_enabled',
        'top_fixed_xl_enabled',
        'stub_mode',
        'business_packages.priority_boost',
        'business_usage.boosts',
        'business.lim_boosts',
    ];

    public const PG_PREFIX = 'zk-topfixed-';

    public const STATUS_PENDING = 'pending_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_APPLY_FAILED = 'apply_failed';

    /** Типы, которые уже попадают в каталог (не курс и не биржа поручений). */
    public const ELIGIBLE_TYPES = ['used', 'new', 'auction', 'free', 'exchange', 'service'];

    public static function affectsRanking(): bool
    {
        return true;
    }

    /**
     * Без выбранной категории (главная, поиск, весь раздел) закрепление выдачу не двигает.
     */
    public static function ranksInCategory(?string $categoryFilter): bool
    {
        return trim((string) $categoryFilter) !== '';
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function serverAmount(array $input): int
    {
        unset($input['amount'], $input['price'], $input['total'], $input['sum'], $input['enabled'], $input['days'], $input['category']);

        return self::PRICE_KZT;
    }

    /**
     * Семь суток от момента активации на сервере. Клиентский срок не читается.
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

    /**
     * Устаревший кеш карточки не оставляет метку закрепления после ends_at.
     */
    public static function displayActive(bool $cachedFlag, ?string $startsAt, ?string $endsAt, ?int $now = null): bool
    {
        unset($cachedFlag);

        return self::windowActive($startsAt, $endsAt, $now);
    }

    public static function categoryMatches(string $storedCategory, string $currentCategory): bool
    {
        $storedCategory = trim($storedCategory);
        $currentCategory = trim($currentCategory);

        return $storedCategory !== '' && $storedCategory === $currentCategory;
    }

    /**
     * Закрепление видно в точной категории и в родительском фильтре, который её включает.
     */
    public static function visibleInFilter(string $storedCategory, string $categoryFilter): bool
    {
        $storedCategory = trim($storedCategory);
        $categoryFilter = trim($categoryFilter);
        if ($storedCategory === '' || $categoryFilter === '') {
            return false;
        }
        if ($storedCategory === $categoryFilter) {
            return true;
        }

        return str_starts_with($storedCategory, $categoryFilter . ' / ');
    }

    /**
     * Шаблон LIKE для дочерних категорий. Символы шаблона в названии экранируются.
     */
    public static function filterLike(string $categoryFilter): string
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $categoryFilter);

        return $escaped . ' / %';
    }

    /**
     * Закреплённые выше обычных. Внутри каждой группы остаётся порядок каталога.
     * Отдельного списка и второй выборки нет, поэтому строка не дублируется.
     */
    public static function catalogOrderSql(): string
    {
        return "ORDER BY (EXISTS (
            SELECT 1 FROM top_fixed_orders tf
            WHERE tf.product_id = p.id
              AND tf.status = 'applied'
              AND tf.starts_at <= NOW()
              AND tf.ends_at > NOW()
              AND tf.category = p.category
              AND (tf.category = ? OR tf.category LIKE ? ESCAPE '!')
        )) DESC, COALESCE(p.sort_at, p.created_at) DESC, p.id DESC";
    }

    /**
     * Тот же порядок, что у catalogOrderSql, для проверок без базы.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    public static function orderedIds(array $rows, ?string $categoryFilter, ?int $now = null): array
    {
        $now ??= time();
        $filter = trim((string) $categoryFilter);
        $scoped = self::ranksInCategory($filter);
        $decorated = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $current = trim((string) ($row['category'] ?? ''));
            $pinCategory = trim((string) ($row['pin_category'] ?? ''));
            $pinned = $scoped
                && self::categoryMatches($pinCategory, $current)
                && self::visibleInFilter($pinCategory, $filter)
                && self::windowActive(
                    isset($row['pin_starts_at']) ? (string) $row['pin_starts_at'] : null,
                    isset($row['pin_ends_at']) ? (string) $row['pin_ends_at'] : null,
                    $now
                );
            $decorated[] = [
                'id' => $id,
                'pinned' => $pinned ? 1 : 0,
                'sort' => (string) ($row['sort_at'] ?? $row['created_at'] ?? ''),
            ];
        }
        usort($decorated, static function (array $a, array $b): int {
            if ($a['pinned'] !== $b['pinned']) {
                return $b['pinned'] <=> $a['pinned'];
            }
            if ($a['sort'] !== $b['sort']) {
                return $b['sort'] <=> $a['sort'];
            }

            return $b['id'] <=> $a['id'];
        });
        $ids = [];
        foreach ($decorated as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
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
        if (!self::isEligibleListing($product)) {
            return 'bad_status';
        }
        if ($hasActiveWindow) {
            return 'already_active';
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
        if (trim((string) ($product['category'] ?? '')) === '') {
            return false;
        }

        return in_array((string) ($product['type'] ?? ''), self::ELIGIBLE_TYPES, true);
    }

    public static function isTopFixedPgOrderId(string $pgOrderId): bool
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
     * Решение по уже заблокированной строке заказа. Даты и категорию пишет сервис.
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
     * Состояние карточки в профиле. Заказ не создаёт.
     *
     * @param array<string, mixed>|null $product
     * @param array<string, mixed>|null $order
     * @return array{code: string, can_purchase: bool, continue_payment: bool, notice: ?string, ends_at: ?string, category: ?string}
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
        $endsAt = $hasOrder && isset($order['ends_at']) ? (string) $order['ends_at'] : null;
        $startsAt = $hasOrder && isset($order['starts_at']) ? (string) $order['starts_at'] : null;
        $storedCategory = $hasOrder ? trim((string) ($order['category'] ?? '')) : '';
        $currentCategory = trim((string) ($product['category'] ?? ''));
        $running = self::windowActive($startsAt, $endsAt);

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
            if ($running && $storedCategory !== '' && !self::categoryMatches($storedCategory, $currentCategory)) {
                return self::ui('moved', false, false, null, $endsAt, $storedCategory);
            }
            if ($running) {
                return self::ui($enabled ? 'active' : 'active_off', false, false, null, $endsAt, $storedCategory !== '' ? $storedCategory : $currentCategory);
            }
            if (!$enabled) {
                return self::ui('expired_off', false, false, null, $endsAt, $storedCategory);
            }
            if (!$eligible) {
                return self::ui('expired', false, false, null, $endsAt, $storedCategory);
            }
            if (!$gatewayReady) {
                return self::ui('expired', false, false, 'gateway', $endsAt, $storedCategory);
            }

            return self::ui('expired', true, false, null, $endsAt, $storedCategory);
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
            'ready' => 'top_fixed.ui_available',
            'gateway' => 'top_fixed.err_gateway',
            'pending_wait', 'pending_pay' => 'top_fixed.payment_pending',
            'processing' => 'top_fixed.paid_waiting',
            'processing_off' => 'top_fixed.paid_waiting_off',
            'active' => 'top_fixed.active',
            'active_off' => 'top_fixed.active_off',
            'moved' => 'top_fixed.moved',
            'expired' => 'top_fixed.expired',
            'expired_off' => 'top_fixed.expired_off',
            'failed' => 'top_fixed.payment_failed',
            'failed_off' => 'top_fixed.failed_off',
            'disabled_pending' => 'top_fixed.disabled_pending',
            'ineligible' => 'top_fixed.ineligible_open',
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
     * Выключение не снимает уже идущее закрепление, не удаляет заказы и не описывает возврат.
     *
     * @return array{action: string, context: array<string, mixed>}
     */
    public static function toggleAudit(int $actorId, bool $before, bool $after, ?string $at = null): array
    {
        return [
            'action' => $after ? 'top_fixed.feature_on' : 'top_fixed.feature_off',
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
     * @return array{code: string, can_purchase: bool, continue_payment: bool, notice: ?string, ends_at: ?string, category: ?string}
     */
    private static function ui(
        string $code,
        bool $canPurchase = false,
        bool $continue = false,
        ?string $notice = null,
        ?string $endsAt = null,
        ?string $category = null
    ): array {
        return [
            'code' => $code,
            'can_purchase' => $canPurchase,
            'continue_payment' => $continue,
            'notice' => $notice,
            'ends_at' => $endsAt !== null && $endsAt !== '' ? $endsAt : null,
            'category' => $category !== null && $category !== '' ? $category : null,
        ];
    }
}
