<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Payment;
use App\Models\Wallet;
use App\Services\Ads\AdPolicy;
use App\Services\Boost\BoostPolicy;
use App\Services\Business\BusinessPackagePolicy;
use App\Services\Highlight\HighlightPolicy;
use App\Services\Listing\ServiceListingFeePolicy;
use App\Services\MassPush\MassPushPolicy;
use App\Services\PaidServices\PaidServicesAdminPolicy;
use App\Services\TargetPush\TargetPushPolicy;
use App\Services\ThreeLifts\ThreeLiftsPolicy;
use App\Services\TopFixed\TopFixedPolicy;
use App\Services\TopFixedXl\TopFixedXlPolicy;
use App\Services\Vip\VipPolicy;

/**
 * VAS-18: правила read-only журнала финансов (без изменения платежей).
 *
 * Флаг модуля (finance_journal_admin_enabled) независим от VAS-17 и флагов продаж VAS-01…16.
 * OFF: недоступны HTML/API журнала. Не отключает платежи, заказы, уведомления провайдера и продажи услуг.
 */
final class FinanceJournalPolicy
{
    public const SETTING_KEY = 'finance_journal_admin_enabled';

    /** Соседние контуры, которые этот переключатель не меняет. */
    public const UNRELATED_CONTROLS = [
        PaidServicesAdminPolicy::SETTING_KEY,
        BoostPolicy::SETTING_KEY,
        ThreeLiftsPolicy::SETTING_KEY,
        HighlightPolicy::SETTING_KEY,
        TopFixedPolicy::SETTING_KEY,
        TopFixedXlPolicy::SETTING_KEY,
        VipPolicy::SETTING_KEY,
        AdPolicy::SETTING_KEYS[AdPolicy::FORMAT_HERO],
        AdPolicy::SETTING_KEYS[AdPolicy::FORMAT_CATEGORY],
        AdPolicy::SETTING_KEYS[AdPolicy::FORMAT_SIDEBAR],
        AdPolicy::SETTING_KEYS[AdPolicy::FORMAT_EXCLUSIVE],
        TargetPushPolicy::SETTING_KEY,
        MassPushPolicy::SETTING_KEY,
        BusinessPackagePolicy::SETTING_SALES,
        BusinessPackagePolicy::SETTING_FEATURES,
        BusinessPackagePolicy::SETTING_AI_INFOGRAPHIC_ADDON_SALES,
        BusinessPackagePolicy::SETTING_AI_TRYON_ADDON_SALES,
        ServiceListingFeePolicy::SETTING_KEY,
        'stub_mode',
        'freedompay',
        'halyk_epay',
    ];

    public const STREAM_VAS = 'vas_purchase';
    public const STREAM_ESCROW = 'escrow_funds';
    public const STREAM_ARBITRATION = 'safe_deal_arbitration_fee';
    public const STREAM_COMMISSION_OTHER = 'platform_commission_other';
    public const STREAM_DELIVERY = 'delivery_service';
    public const STREAM_WALLET = 'wallet_ledger';
    public const STREAM_OTHER = 'other';

    /** @var list<string> */
    public const STREAMS = [
        self::STREAM_VAS,
        self::STREAM_ESCROW,
        self::STREAM_ARBITRATION,
        self::STREAM_COMMISSION_OTHER,
        self::STREAM_DELIVERY,
        self::STREAM_WALLET,
        self::STREAM_OTHER,
    ];

    public const DEFAULT_PER_PAGE = 40;
    public const MAX_PER_PAGE = 100;

    /** @var list<string> */
    public const SORTS = ['created_at_desc', 'created_at_asc', 'amount_desc'];

    /** Таблицы, разрешённые в entry_id (whitelist). */
    public const TABLES = [
        'boost_orders' => self::STREAM_VAS,
        'highlight_orders' => self::STREAM_VAS,
        'top_fixed_orders' => self::STREAM_VAS,
        'top_fixed_xl_orders' => self::STREAM_VAS,
        'vip_orders' => self::STREAM_VAS,
        'ad_campaigns' => self::STREAM_VAS,
        'target_push_campaigns' => self::STREAM_VAS,
        'mass_push_campaigns' => self::STREAM_VAS,
        'business_package_payments' => self::STREAM_VAS,
        'payments' => self::STREAM_ESCROW,
        'delivery_payments' => self::STREAM_DELIVERY,
        'wallet_transactions' => self::STREAM_OTHER, // уточняется по type
    ];

    public const SERVICE_BY_TABLE = [
        'boost_orders' => 'boost',
        'highlight_orders' => 'highlight',
        'top_fixed_orders' => 'top_fixed',
        'top_fixed_xl_orders' => 'top_fixed_xl',
        'vip_orders' => 'vip',
        'target_push_campaigns' => 'target_push',
        'mass_push_campaigns' => 'mass_push',
        'business_package_payments' => 'business_package',
    ];

    public static function canView(?string $role): bool
    {
        return $role === 'admin';
    }

    public static function canToggle(?string $role): bool
    {
        return $role === 'admin';
    }

    /**
     * Нет ключа → журнал доступен (после внедрения VAS-18).
     * Сбой чтения → выключен (fail-closed для админ-просмотра).
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
     * @return array{action: string, context: array<string, mixed>}
     */
    public static function toggleAudit(int $actorId, bool $before, bool $after, ?string $at = null): array
    {
        return [
            'action' => $after ? 'finance_journal.module_on' : 'finance_journal.module_off',
            'context' => [
                'actor_id' => $actorId,
                'at' => $at ?? date('c'),
                'before' => $before,
                'after' => $after,
                'setting' => self::SETTING_KEY,
                'blocks_admin_journal' => !$after,
                'blocks_admin_journal_api' => !$after,
                'keeps_vas17_module' => true,
                'keeps_service_sales_flags' => true,
                'keeps_orders' => true,
                'keeps_payments' => true,
                'keeps_payment_webhooks' => true,
                'keeps_background_jobs' => true,
                'keeps_entitlements' => true,
                'no_mutation_of_finance_rows' => true,
                'no_refunds' => true,
                'unaffected' => self::UNRELATED_CONTROLS,
            ],
        ];
    }

    public static function parseEntryId(string $entryId): ?array
    {
        $entryId = trim($entryId);
        if ($entryId === '' || !preg_match('/^([a-z0-9_]+):([1-9][0-9]*)$/', $entryId, $m)) {
            return null;
        }
        $table = $m[1];
        if (!isset(self::TABLES[$table])) {
            return null;
        }

        return [
            'source_table' => $table,
            'source_id' => (int) $m[2],
            'stream_hint' => self::TABLES[$table],
        ];
    }

    public static function makeEntryId(string $table, int $id): string
    {
        return $table . ':' . $id;
    }

    /**
     * Классификация по надёжным идентификаторам (таблица / pg prefix / wallet type).
     *
     * @param array{source_table?:string,pg_order_id?:?string,wallet_type?:?string,order_id?:?int} $ctx
     */
    public static function classify(array $ctx): string
    {
        $table = (string) ($ctx['source_table'] ?? '');
        $pg = (string) ($ctx['pg_order_id'] ?? '');
        $walletType = (string) ($ctx['wallet_type'] ?? '');

        if ($pg !== '') {
            if (str_starts_with($pg, 'zk-del-')) {
                return self::STREAM_DELIVERY;
            }
            if (BoostPolicy::isBoostPgOrderId($pg)
                || HighlightPolicy::isHighlightPgOrderId($pg)
                || TopFixedPolicy::isTopFixedPgOrderId($pg)
                || TopFixedXlPolicy::isTopFixedXlPgOrderId($pg)
                || VipPolicy::isVipPgOrderId($pg)
                || AdPolicy::isAdPgOrderId($pg)
                || TargetPushPolicy::isPgOrderId($pg)
                || MassPushPolicy::isPgOrderId($pg)
            ) {
                return self::STREAM_VAS;
            }
        }

        if ($table === 'wallet_transactions') {
            return match ($walletType) {
                Wallet::TYPE_LISTING_FEE,
                Wallet::TYPE_BUSINESS_PACKAGE => self::STREAM_VAS,
                Wallet::TYPE_PLATFORM_COMMISSION => self::STREAM_ARBITRATION,
                Wallet::TYPE_ESCROW_HOLD,
                Wallet::TYPE_ESCROW_RELEASE,
                Wallet::TYPE_ESCROW_REFUND,
                Wallet::TYPE_DIRECT_PAYMENT,
                Wallet::TYPE_DIRECT_RECEIVE => self::STREAM_ESCROW,
                Wallet::TYPE_DEPOSIT,
                Wallet::TYPE_WITHDRAW => self::STREAM_WALLET,
                default => self::STREAM_OTHER,
            };
        }

        if ($table !== '' && isset(self::TABLES[$table])) {
            $stream = self::TABLES[$table];
            if ($stream !== self::STREAM_OTHER) {
                return $stream;
            }
        }

        return self::STREAM_OTHER;
    }

    /**
     * Строгое правило «подтверждённый платёж» (спека §4.2).
     * Не выставляет true только из-за создания заказа.
     *
     * @param array{
     *   stream_type: string,
     *   source_table: string,
     *   order_status?: ?string,
     *   payment_status?: ?string,
     *   paid_at?: ?string,
     *   pg_payment_id?: ?string,
     *   channel?: ?string,
     *   wallet_type?: ?string
     * } $row
     */
    public static function isPaymentConfirmed(array $row): bool
    {
        $table = (string) ($row['source_table'] ?? '');
        $orderStatus = strtolower((string) ($row['order_status'] ?? ''));
        $paymentStatus = strtolower((string) ($row['payment_status'] ?? ''));
        $paidAt = trim((string) ($row['paid_at'] ?? ''));
        $pgPaymentId = trim((string) ($row['pg_payment_id'] ?? ''));
        $channel = (string) ($row['channel'] ?? '');

        if (in_array($table, [
            'boost_orders', 'highlight_orders', 'top_fixed_orders', 'top_fixed_xl_orders', 'vip_orders',
            'target_push_campaigns', 'mass_push_campaigns',
        ], true)) {
            $okStatus = in_array($orderStatus, [
                BoostPolicy::STATUS_PAID,
                BoostPolicy::STATUS_APPLIED,
                BoostPolicy::STATUS_APPLY_FAILED,
                'active',
                'completed',
                'sending',
                'sent',
            ], true);
            // apply_failed / paid после result: деньги могли быть приняты
            if (in_array($orderStatus, [BoostPolicy::STATUS_PAID, BoostPolicy::STATUS_APPLIED, BoostPolicy::STATUS_APPLY_FAILED], true)) {
                return $paidAt !== '' || $pgPaymentId !== '';
            }
            if ($okStatus && ($paidAt !== '' || $pgPaymentId !== '')) {
                return true;
            }

            return false;
        }

        if ($table === 'ad_campaigns') {
            $paidLike = in_array($orderStatus, [
                AdPolicy::STATUS_PAID,
                AdPolicy::STATUS_MODERATION,
                AdPolicy::STATUS_APPROVED,
                AdPolicy::STATUS_ACTIVE,
                AdPolicy::STATUS_PAUSED,
                AdPolicy::STATUS_COMPLETED,
            ], true);

            return $paidLike && ($paidAt !== '' || $pgPaymentId !== '');
        }

        if ($table === 'payments') {
            return $paymentStatus === Payment::STATUS_PAID && ($paidAt !== '' || $pgPaymentId !== '');
        }

        if ($table === 'delivery_payments') {
            return $paymentStatus === 'paid' && ($paidAt !== '' || $pgPaymentId !== '');
        }

        if ($table === 'business_package_payments') {
            return $paymentStatus === BusinessPackagePolicy::PAYMENT_PAID || $orderStatus === BusinessPackagePolicy::PAYMENT_PAID;
        }

        if ($table === 'wallet_transactions') {
            $type = (string) ($row['wallet_type'] ?? '');
            // Ledger row exists = движение уже записано; deposit/withdraw помечены simulated в flags, но факт записи есть.
            return in_array($type, [
                Wallet::TYPE_LISTING_FEE,
                Wallet::TYPE_BUSINESS_PACKAGE,
                Wallet::TYPE_PLATFORM_COMMISSION,
                Wallet::TYPE_ESCROW_HOLD,
                Wallet::TYPE_ESCROW_RELEASE,
                Wallet::TYPE_ESCROW_REFUND,
                Wallet::TYPE_DEPOSIT,
                Wallet::TYPE_WITHDRAW,
                Wallet::TYPE_DIRECT_PAYMENT,
                Wallet::TYPE_DIRECT_RECEIVE,
            ], true);
        }

        unset($channel);

        return false;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok:bool,error_code?:string,filters?:array<string,mixed>}
     */
    public static function sanitizeListFilters(array $input): array
    {
        $q = isset($input['q']) ? trim((string) $input['q']) : '';
        if (mb_strlen($q) > 128) {
            return ['ok' => false, 'error_code' => 'invalid_query'];
        }

        $stream = isset($input['stream_type']) ? trim((string) $input['stream_type']) : '';
        if ($stream !== '' && !in_array($stream, self::STREAMS, true)) {
            return ['ok' => false, 'error_code' => 'invalid_stream'];
        }

        $service = isset($input['service_code']) ? trim((string) $input['service_code']) : '';
        if ($service !== '' && !preg_match('/^[a-z0-9_]{1,64}$/', $service)) {
            return ['ok' => false, 'error_code' => 'invalid_service'];
        }

        $confirmed = null;
        if (array_key_exists('payment_confirmed', $input) && $input['payment_confirmed'] !== '' && $input['payment_confirmed'] !== null) {
            $raw = $input['payment_confirmed'];
            if ($raw === true || $raw === 1 || $raw === '1') {
                $confirmed = true;
            } elseif ($raw === false || $raw === 0 || $raw === '0') {
                $confirmed = false;
            } else {
                return ['ok' => false, 'error_code' => 'invalid_confirmed'];
            }
        }

        $userId = isset($input['user_id']) ? (int) $input['user_id'] : 0;
        if ($userId < 0) {
            return ['ok' => false, 'error_code' => 'invalid_user'];
        }

        $dateFrom = self::parseDate($input['date_from'] ?? null);
        $dateTo = self::parseDate($input['date_to'] ?? null);
        if (($input['date_from'] ?? '') !== '' && $input['date_from'] !== null && $dateFrom === null) {
            return ['ok' => false, 'error_code' => 'invalid_date_from'];
        }
        if (($input['date_to'] ?? '') !== '' && $input['date_to'] !== null && $dateTo === null) {
            return ['ok' => false, 'error_code' => 'invalid_date_to'];
        }
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            return ['ok' => false, 'error_code' => 'invalid_date_range'];
        }

        $sort = isset($input['sort']) ? trim((string) $input['sort']) : 'created_at_desc';
        if ($sort === '') {
            $sort = 'created_at_desc';
        }
        if (!in_array($sort, self::SORTS, true)) {
            return ['ok' => false, 'error_code' => 'invalid_sort'];
        }

        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = (int) ($input['per_page'] ?? self::DEFAULT_PER_PAGE);
        if ($perPage < 1) {
            $perPage = self::DEFAULT_PER_PAGE;
        }
        if ($perPage > self::MAX_PER_PAGE) {
            $perPage = self::MAX_PER_PAGE;
        }

        $status = isset($input['status']) ? trim((string) $input['status']) : '';
        if ($status !== '' && !preg_match('/^[a-z0-9_]{1,40}$/', $status)) {
            return ['ok' => false, 'error_code' => 'invalid_status'];
        }

        return [
            'ok' => true,
            'filters' => [
                'q' => $q,
                'stream_type' => $stream,
                'service_code' => $service,
                'payment_confirmed' => $confirmed,
                'user_id' => $userId > 0 ? $userId : null,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'sort' => $sort,
                'page' => $page,
                'per_page' => $perPage,
                'status' => $status,
            ],
        ];
    }

    private static function parseDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $s = trim((string) $raw);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            return null;
        }
        $ts = strtotime(substr($s, 0, 10));
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d', $ts);
    }

    /** @return list<string> */
    public static function secretKeysForbiddenInPayload(): array
    {
        return [
            'password', 'secret', 'secret_key', 'cvv', 'cvc', 'pan', 'card_number',
            'pg_sig', 'authorization', 'api_key', 'token', 'access_token',
        ];
    }
}
