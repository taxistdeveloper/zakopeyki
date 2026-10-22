<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Helpers\ProductHelper;

/**
 * Реклама Zakopeyki.kz: четыре формата, у каждого свой тариф и переключатель.
 *
 * Финансовая модель совпадает с уже принятой для Boost, Highlight, Top Fixed и VIP:
 * оплата заранее через платёжный шлюз, сумму считает сервер, возврат этим правилом не создаётся.
 * CPM покупается целыми тысячами показов: сумма = тысячи × тариф, без округления.
 * Эксклюзив — 19 900 ₸ за неделю от момента активации на сервере (604 800 секунд, как у VIP).
 * Засчитанный показ — одноразовый серверный токен, который браузер подтверждает,
 * когда материал виден хотя бы наполовину. Повтор того же токена не увеличивает счётчик.
 * Правила отсечения ботов в источниках не заданы и здесь не вводятся.
 */
final class AdPolicy
{
    public const FORMAT_HERO = 'hero';
    public const FORMAT_CATEGORY = 'category';
    public const FORMAT_SIDEBAR = 'sidebar';
    public const FORMAT_EXCLUSIVE = 'exclusive';

    /** @var array<string, int> */
    public const CPM_KZT = [
        self::FORMAT_HERO => 1200,
        self::FORMAT_CATEGORY => 800,
        self::FORMAT_SIDEBAR => 600,
    ];

    public const EXCLUSIVE_WEEK_KZT = 19900;

    public const WEEK_SECONDS = 604800;

    public const IMPRESSIONS_PER_UNIT = 1000;

    /** Технический потолок заказа, не коммерческий минимум. */
    public const MAX_THOUSANDS = 1000;

    public const MAX_WEEKS = 52;

    public const MAX_HERO_SLIDES = 5;

    public const VISIBLE_RATIO = 0.5;

    /** @var array<string, string> */
    public const SETTING_KEYS = [
        self::FORMAT_HERO => 'ads_hero_enabled',
        self::FORMAT_CATEGORY => 'ads_category_enabled',
        self::FORMAT_SIDEBAR => 'ads_sidebar_enabled',
        self::FORMAT_EXCLUSIVE => 'ads_exclusive_enabled',
    ];

    public const ACTIVE_SURVIVES_DISABLE = true;

    public const PAYMENT_AFTER_DISABLE = 'no_apply';

    public const REENABLE_REACTIVATES_COMPLETED = false;

    /** @var list<string> */
    public const UNRELATED_CONTROLS = [
        'boost_enabled',
        'three_lifts_enabled',
        'highlight_enabled',
        'top_fixed_enabled',
        'top_fixed_xl_enabled',
        'vip_enabled',
        'target_push_enabled',
        'stub_mode',
    ];

    public const PG_PREFIX = 'zk-ad-';

    public const STATUS_MODERATION = 'pending_moderation';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PENDING = 'pending_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const IMPRESSION_ISSUED = 'issued';
    public const IMPRESSION_COUNTED = 'counted';
    public const IMPRESSION_SKIPPED = 'skipped';

    /** @var list<string> */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const IMAGE_MAX_BYTES = 2000000;

    public static function isFormat(string $format): bool
    {
        return isset(self::SETTING_KEYS[$format]);
    }

    public static function isCpm(string $format): bool
    {
        return isset(self::CPM_KZT[$format]);
    }

    public static function settingKey(string $format): ?string
    {
        return self::SETTING_KEYS[$format] ?? null;
    }

    /**
     * Клиентские сумма, тариф и объём не читаются.
     *
     * @param array<string, mixed> $input
     * @return array{amount: int, impressions: int, seconds: int}|null
     */
    public static function serverQuote(string $format, int $units, array $input = []): ?array
    {
        unset($input['amount'], $input['price'], $input['total'], $input['sum'], $input['cpm'], $input['enabled']);

        return self::quote($format, $units);
    }

    /**
     * @return array{amount: int, impressions: int, seconds: int}|null
     */
    public static function quote(string $format, int $units): ?array
    {
        if (!self::isFormat($format) || $units < 1) {
            return null;
        }
        if ($format === self::FORMAT_EXCLUSIVE) {
            if ($units > self::MAX_WEEKS) {
                return null;
            }

            return [
                'amount' => $units * self::EXCLUSIVE_WEEK_KZT,
                'impressions' => 0,
                'seconds' => $units * self::WEEK_SECONDS,
            ];
        }
        if ($units > self::MAX_THOUSANDS) {
            return null;
        }
        $rate = self::CPM_KZT[$format];

        return [
            'amount' => $units * $rate,
            'impressions' => $units * self::IMPRESSIONS_PER_UNIT,
            'seconds' => 0,
        ];
    }

    /**
     * @return array{starts_at: int, ends_at: int}|null
     */
    public static function exclusivePeriod(int $activatedAt, int $weeks): ?array
    {
        $quote = self::quote(self::FORMAT_EXCLUSIVE, $weeks);
        if ($quote === null) {
            return null;
        }

        return [
            'starts_at' => $activatedAt,
            'ends_at' => $activatedAt + $quote['seconds'],
        ];
    }

    public static function periodsOverlap(int $startA, int $endA, int $startB, int $endB): bool
    {
        return $startA < $endB && $startB < $endA;
    }

    /**
     * @return list<string>
     */
    public static function categoryKeys(): array
    {
        $keys = [];
        foreach (ProductHelper::PRODUCT_CATEGORY_TREE as $parent => $children) {
            $keys[] = $parent;
            foreach ($children as $child) {
                $keys[] = ProductHelper::formatCategory($parent, $child);
            }
        }

        return $keys;
    }

    public static function isCategoryKey(string $key): bool
    {
        return in_array($key, self::categoryKeys(), true);
    }

    public static function parentKey(string $category): string
    {
        $category = trim($category);
        if (str_contains($category, ' / ')) {
            return explode(' / ', $category, 2)[0];
        }

        return $category;
    }

    /**
     * Пустая категория у бокового баннера — все карточки.
     * Раздел покрывает свои подкатегории. Чужая категория не подходит.
     */
    public static function sidebarMatches(?string $campaignKey, ?string $productCategory): bool
    {
        $campaignKey = trim((string) $campaignKey);
        if ($campaignKey === '') {
            return true;
        }
        $productCategory = trim((string) $productCategory);
        if ($productCategory === '' || $productCategory === 'Разное') {
            return false;
        }
        if ($campaignKey === $productCategory) {
            return true;
        }

        return !str_contains($campaignKey, ' / ')
            && str_starts_with($productCategory, $campaignKey . ' / ');
    }

    public static function categoryPageMatches(?string $campaignKey, ?string $pageKey): bool
    {
        $campaignKey = trim((string) $campaignKey);
        $pageKey = trim((string) $pageKey);

        return $campaignKey !== '' && $campaignKey === $pageKey;
    }

    /**
     * @return array{ok: bool, key: ?string, error: ?string}
     */
    public static function normalizeCategory(string $format, ?string $raw): array
    {
        $key = trim((string) $raw);
        if ($format === self::FORMAT_HERO) {
            return $key === ''
                ? ['ok' => true, 'key' => null, 'error' => null]
                : ['ok' => false, 'key' => null, 'error' => 'bad_category'];
        }
        if ($format === self::FORMAT_SIDEBAR && $key === '') {
            return ['ok' => true, 'key' => null, 'error' => null];
        }
        if ($key === '' || !self::isCategoryKey($key)) {
            return ['ok' => false, 'key' => null, 'error' => 'bad_category'];
        }

        return ['ok' => true, 'key' => $key, 'error' => null];
    }

    public static function titleError(string $title): ?string
    {
        $title = trim($title);
        $len = mb_strlen($title);
        if ($len < 2 || $len > 120) {
            return 'bad_title';
        }

        return null;
    }

    public static function targetUrlError(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500 || preg_match('/[\s<>"\']/', $url) === 1) {
            return 'bad_url';
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return 'bad_url';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return 'bad_url';
        }

        return null;
    }

    public static function imageError(int $size, ?string $mime, ?int $width, ?int $height): ?string
    {
        if ($size <= 0 || $size > self::IMAGE_MAX_BYTES) {
            return 'bad_image';
        }
        if ($mime === null || !in_array($mime, self::IMAGE_MIMES, true)) {
            return 'bad_image';
        }
        if ($width === null || $height === null || $width < 320 || $height < 100 || $width > 4000 || $height > 4000) {
            return 'bad_image';
        }

        return null;
    }

    public static function mayIssue(
        string $status,
        int $used,
        int $paid,
        ?int $endsAt,
        int $now,
        bool $placementOk
    ): bool {
        if ($status !== self::STATUS_ACTIVE || !$placementOk) {
            return false;
        }
        if ($endsAt !== null && $now >= $endsAt) {
            return false;
        }
        if ($paid > 0 && $used >= $paid) {
            return false;
        }

        return true;
    }

    /**
     * Повтор токена не считается. Лимит и срок проверяются по уже заблокированной строке.
     *
     * @return array{count: bool, next_impression: string, next_status: string, idempotent: bool, reason: ?string}
     */
    public static function decideCount(
        string $impressionStatus,
        string $campaignStatus,
        int $used,
        int $paid,
        ?int $endsAt,
        int $now
    ): array {
        if ($impressionStatus === self::IMPRESSION_COUNTED) {
            return [
                'count' => false,
                'next_impression' => self::IMPRESSION_COUNTED,
                'next_status' => $campaignStatus,
                'idempotent' => true,
                'reason' => null,
            ];
        }
        if ($impressionStatus !== self::IMPRESSION_ISSUED) {
            return [
                'count' => false,
                'next_impression' => $impressionStatus,
                'next_status' => $campaignStatus,
                'idempotent' => true,
                'reason' => 'closed',
            ];
        }

        $expired = $endsAt !== null && $now >= $endsAt;
        $exhausted = $paid > 0 && $used >= $paid;
        if ($campaignStatus !== self::STATUS_ACTIVE || $expired || $exhausted) {
            $next = $campaignStatus;
            if ($campaignStatus === self::STATUS_ACTIVE && ($expired || $exhausted)) {
                $next = self::STATUS_COMPLETED;
            }

            return [
                'count' => false,
                'next_impression' => self::IMPRESSION_SKIPPED,
                'next_status' => $next,
                'idempotent' => false,
                'reason' => $expired ? 'expired' : ($exhausted ? 'exhausted' : 'not_active'),
            ];
        }

        $nextUsed = $used + 1;
        $nextStatus = ($paid > 0 && $nextUsed >= $paid) ? self::STATUS_COMPLETED : self::STATUS_ACTIVE;

        return [
            'count' => true,
            'next_impression' => self::IMPRESSION_COUNTED,
            'next_status' => $nextStatus,
            'idempotent' => false,
            'reason' => null,
        ];
    }

    public static function purchaseBlock(bool $enabled, bool $owner, bool $approved, bool $slotFree): ?string
    {
        if (!$enabled) {
            return 'disabled';
        }
        if (!$owner) {
            return 'forbidden';
        }
        if (!$approved) {
            return 'bad_status';
        }
        if (!$slotFree) {
            return 'slot_taken';
        }

        return null;
    }

    public static function isAdPgOrderId(string $pgOrderId): bool
    {
        return str_starts_with($pgOrderId, self::PG_PREFIX);
    }

    public static function exclusiveOpenKey(string $categoryKey): string
    {
        return 'ex-' . sha1($categoryKey);
    }

    public static function keepsOpenLock(string $status): bool
    {
        return in_array($status, [self::STATUS_PENDING, self::STATUS_PAID, self::STATUS_ACTIVE, self::STATUS_PAUSED], true);
    }

    public static function canShowCreative(string $status): bool
    {
        return $status === self::STATUS_ACTIVE;
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
     * @return array{apply: bool, next_status: string, gateway: string, idempotent: bool, failure_reason: ?string}
     */
    public static function decideCallback(
        string $status,
        string $format,
        int $storedAmount,
        int $storedUnits,
        ?int $paidAmount,
        bool $gatewaySuccess,
        bool $featureEnabled,
        bool $slotFree,
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

        if (in_array($status, [self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_COMPLETED], true)) {
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

        if (in_array($status, [self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_REJECTED], true)) {
            $decision['gateway'] = $canReject ? 'rejected' : 'error';
            $decision['failure_reason'] = 'terminal';

            return $decision;
        }

        $quote = self::quote($format, $storedUnits);
        $amountOk = $currencyOk
            && $quote !== null
            && $storedAmount === $quote['amount']
            && $paidAmount === $quote['amount'];

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
            if ($status === self::STATUS_PENDING || $status === self::STATUS_PAID) {
                $decision['next_status'] = self::STATUS_PAID;
                $decision['failure_reason'] = 'disabled';

                return $decision;
            }

            $decision['failure_reason'] = 'disabled';

            return $decision;
        }

        if (!$slotFree) {
            if ($status === self::STATUS_PENDING && $canReject) {
                $decision['next_status'] = self::STATUS_FAILED;
                $decision['gateway'] = 'rejected';
                $decision['failure_reason'] = 'slot_taken';

                return $decision;
            }
            $decision['next_status'] = self::STATUS_PAID;
            $decision['failure_reason'] = 'slot_taken';

            return $decision;
        }

        if (in_array($status, [self::STATUS_PENDING, self::STATUS_PAID], true)) {
            $decision['apply'] = true;
            $decision['next_status'] = self::STATUS_ACTIVE;

            return $decision;
        }

        $decision['gateway'] = 'error';
        $decision['failure_reason'] = 'invalid_status';

        return $decision;
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
     * @return array{action: string, context: array<string, mixed>}
     */
    public static function toggleAudit(string $format, int $actorId, bool $before, bool $after, ?string $at = null): array
    {
        return [
            'action' => $after ? 'ads.feature_on' : 'ads.feature_off',
            'context' => [
                'actor_id' => $actorId,
                'at' => $at ?? date('c'),
                'format' => $format,
                'setting' => self::settingKey($format),
                'before' => $before,
                'after' => $after,
                'keeps_history' => true,
                'keeps_active' => self::ACTIVE_SURVIVES_DISABLE,
                'restarts_completed' => self::REENABLE_REACTIVATES_COMPLETED,
                'refunds' => false,
                'payment_after_disable' => self::PAYMENT_AFTER_DISABLE,
                'other_settings' => [],
            ],
        ];
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
}
