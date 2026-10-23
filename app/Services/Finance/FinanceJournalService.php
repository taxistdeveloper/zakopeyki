<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Core\Auth;
use App\Core\Database;
use App\Helpers\ActivityLogger;
use App\Models\ActivityLog;
use App\Models\AdCampaign;
use App\Models\BoostOrder;
use App\Models\BusinessPackagePayment;
use App\Models\DeliveryPayment;
use App\Models\HighlightOrder;
use App\Models\MassPushCampaign;
use App\Models\OrderReturnEvent;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\TargetPushCampaign;
use App\Models\TopFixedOrder;
use App\Models\TopFixedXlOrder;
use App\Models\VipOrder;
use App\Models\Wallet;
use App\Services\PaidServices\PaidServiceCatalog;
use PDO;
use Throwable;

/**
 * VAS-18: read-only агрегация существующих финансовых таблиц.
 * Не вызывает платёжные операции и не меняет статусы.
 * Флаг модуля независим от VAS-17 и продаж VAS-01…16.
 */
final class FinanceJournalService
{
    private PDO $db;
    private Setting $settings;
    private static bool $ensured = false;

    public function __construct(?PDO $db = null, ?Setting $settings = null)
    {
        $this->db = $db ?? Database::connect();
        $this->settings = $settings ?? new Setting();
    }

    /**
     * Доступен ли административный журнал VAS-18 (не платежи и не VAS-17).
     */
    public function isModuleEnabled(): bool
    {
        try {
            $this->settings->forget(FinanceJournalPolicy::SETTING_KEY);

            return FinanceJournalPolicy::resolveEnabled(
                $this->settings->getBool(FinanceJournalPolicy::SETTING_KEY, true)
            );
        } catch (Throwable) {
            return FinanceJournalPolicy::resolveEnabled(null, true);
        }
    }

    /**
     * Вкл/выкл модуля журнала. Работает и при выключенном модуле (иначе нельзя включить обратно).
     * Не трогает заказы, платежи, VAS-17 и флаги продаж.
     *
     * @return array{ok: bool, error_code?: string, enabled?: bool, unchanged?: bool, before?: bool, after?: bool}
     */
    public function setModuleEnabled(bool $enabled): array
    {
        $role = Auth::check() ? (string) (Auth::user()['role'] ?? '') : '';
        $actorId = Auth::check() ? (int) Auth::id() : 0;
        if (!FinanceJournalPolicy::canToggle($role) || $actorId <= 0) {
            ActivityLogger::warning(
                'finance_journal.module_toggle_denied',
                'Модуль журнала финансов: отказано — не admin',
                'settings',
                null,
                ['actor_id' => $actorId, 'role' => $role]
            );

            return ['ok' => false, 'error_code' => 'forbidden'];
        }

        $before = $this->isModuleEnabled();
        if ($before === $enabled) {
            return [
                'ok' => true,
                'unchanged' => true,
                'enabled' => $enabled,
                'before' => $before,
                'after' => $enabled,
            ];
        }

        try {
            $this->settings->setBool(FinanceJournalPolicy::SETTING_KEY, $enabled);
            $after = $this->isModuleEnabled();
            if ($after !== $enabled) {
                return ['ok' => false, 'error_code' => 'toggle_failed'];
            }
            $audit = FinanceJournalPolicy::toggleAudit($actorId, $before, $after);
            ActivityLogger::info(
                $audit['action'],
                $after
                    ? 'Админ-модуль журнала заказов и платежей включён'
                    : 'Админ-модуль журнала заказов и платежей выключен',
                'settings',
                null,
                $audit['context']
            );

            return [
                'ok' => true,
                'enabled' => $after,
                'before' => $before,
                'after' => $after,
                'unchanged' => false,
            ];
        } catch (Throwable) {
            ActivityLogger::warning(
                'finance_journal.module_toggle_failed',
                'Не удалось переключить модуль журнала финансов',
                'settings',
                null
            );

            return ['ok' => false, 'error_code' => 'toggle_failed'];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok:bool,error_code?:string,items?:list<array>,page?:int,pages?:int,total?:int,per_page?:int,filters?:array}
     */
    public function list(array $input = []): array
    {
        if (!$this->actorIsAdmin()) {
            $this->deny('finance_journal.list_denied', 'Журнал финансов: отказано');

            return ['ok' => false, 'error_code' => 'forbidden'];
        }

        if (!$this->isModuleEnabled()) {
            $this->deny('finance_journal.list_denied', 'Журнал финансов: модуль VAS-18 выключен');

            return ['ok' => false, 'error_code' => 'module_disabled'];
        }

        $san = FinanceJournalPolicy::sanitizeListFilters($input);
        if (empty($san['ok'])) {
            return ['ok' => false, 'error_code' => $san['error_code'] ?? 'invalid_filters'];
        }
        /** @var array<string, mixed> $f */
        $f = $san['filters'];

        try {
            $this->ensureSources();
            $union = $this->buildUnionSql();
            if ($union === null) {
                return [
                    'ok' => true,
                    'items' => [],
                    'page' => 1,
                    'pages' => 1,
                    'total' => 0,
                    'per_page' => (int) $f['per_page'],
                    'filters' => $f,
                ];
            }

            $perPage = (int) $f['per_page'];

            // payment_confirmed — вычисляемое поле: отдельная пагинация после classify.
            if ($f['payment_confirmed'] !== null) {
                $pack = $this->listWithConfirmedFilter($union, $f);

                return [
                    'ok' => true,
                    'items' => $pack['items'],
                    'page' => $pack['page'],
                    'pages' => $pack['pages'],
                    'total' => $pack['total'],
                    'per_page' => $perPage,
                    'filters' => $f,
                ];
            }

            [$whereSql, $params] = $this->buildWhere($f);
            $orderSql = $this->buildOrder((string) $f['sort']);

            $countSql = "SELECT COUNT(*) FROM ({$union}) AS j WHERE {$whereSql}";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $page = (int) $f['page'];
            $pages = max(1, (int) ceil($total / $perPage));
            if ($page > $pages) {
                $page = $pages;
            }
            $offset = ($page - 1) * $perPage;

            $listSql = "SELECT * FROM ({$union}) AS j WHERE {$whereSql} {$orderSql} LIMIT {$perPage} OFFSET {$offset}";
            $listStmt = $this->db->prepare($listSql);
            $listStmt->execute($params);
            $rows = $listStmt->fetchAll() ?: [];

            $items = [];
            foreach ($rows as $row) {
                $items[] = $this->publicListItem($this->mapRow($row));
            }

            return [
                'ok' => true,
                'items' => $items,
                'page' => $page,
                'pages' => $pages,
                'total' => $total,
                'per_page' => $perPage,
                'filters' => $f,
            ];
        } catch (Throwable $e) {
            ActivityLogger::warning(
                'finance_journal.list_failed',
                'Не удалось сформировать журнал финансов',
                'settings',
                null,
                ['error' => 'query_failed']
            );

            return ['ok' => false, 'error_code' => 'query_failed'];
        }
    }

    /**
     * @return array{ok:bool,error_code?:string,entry?:array}
     */
    public function detail(string $entryId): array
    {
        if (!$this->actorIsAdmin()) {
            $this->deny('finance_journal.detail_denied', 'Детали журнала: отказано');

            return ['ok' => false, 'error_code' => 'forbidden'];
        }

        if (!$this->isModuleEnabled()) {
            $this->deny('finance_journal.detail_denied', 'Детали журнала: модуль VAS-18 выключен');

            return ['ok' => false, 'error_code' => 'module_disabled'];
        }

        $parsed = FinanceJournalPolicy::parseEntryId($entryId);
        if ($parsed === null) {
            return ['ok' => false, 'error_code' => 'not_found'];
        }

        try {
            $this->ensureSources();
            $union = $this->buildUnionSql();
            if ($union === null) {
                return ['ok' => false, 'error_code' => 'not_found'];
            }

            $sql = "SELECT * FROM ({$union}) AS j
                    WHERE j.source_table = ? AND j.source_id = ?
                    LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$parsed['source_table'], $parsed['source_id']]);
            $row = $stmt->fetch();
            if (!$row) {
                return ['ok' => false, 'error_code' => 'not_found'];
            }

            $entry = $this->mapRow($row);
            $entry['history'] = $this->buildHistory($entry);
            $entry['related_links'] = $this->relatedLinks($entry);

            return ['ok' => true, 'entry' => $this->publicDetailItem($entry)];
        } catch (Throwable) {
            return ['ok' => false, 'error_code' => 'query_failed'];
        }
    }

    private function actorIsAdmin(): bool
    {
        return Auth::check() && FinanceJournalPolicy::canView((string) (Auth::user()['role'] ?? ''));
    }

    private function deny(string $action, string $message): void
    {
        ActivityLogger::warning(
            $action,
            $message,
            'settings',
            null,
            ['actor_id' => Auth::check() ? (int) Auth::id() : 0]
        );
    }

    private function ensureSources(): void
    {
        if (self::$ensured) {
            return;
        }
        // ensureTable через конструкторы моделей
        new BoostOrder();
        new HighlightOrder();
        new TopFixedOrder();
        new TopFixedXlOrder();
        new VipOrder();
        new AdCampaign();
        new TargetPushCampaign();
        new MassPushCampaign();
        new BusinessPackagePayment();
        new Payment();
        new DeliveryPayment();
        new Wallet();
        new OrderReturnEvent();
        new ActivityLog();
        self::$ensured = true;
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->db->query('SHOW TABLES LIKE ' . $this->db->quote($table));

            return (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function buildUnionSql(): ?string
    {
        $parts = [];

        $vasOrder = static function (string $table, string $service) {
            return "SELECT
                '{$table}' AS source_table,
                id AS source_id,
                public_id AS internal_ref,
                user_id AS user_id,
                NULL AS counterparty_user_id,
                '{$service}' AS service_code,
                amount AS amount,
                'KZT' AS currency,
                created_at AS created_at,
                updated_at AS updated_at,
                paid_at AS paid_at,
                status AS order_status,
                status AS payment_status,
                pg_order_id AS pg_order_id,
                pg_payment_id AS pg_payment_id,
                'freedompay' AS channel,
                failure_reason AS failure_reason,
                NULL AS wallet_type,
                NULL AS related_order_id
            FROM `{$table}`";
        };

        foreach ([
            'boost_orders' => 'boost',
            'highlight_orders' => 'highlight',
            'top_fixed_orders' => 'top_fixed',
            'top_fixed_xl_orders' => 'top_fixed_xl',
            'vip_orders' => 'vip',
            'target_push_campaigns' => 'target_push',
            'mass_push_campaigns' => 'mass_push',
        ] as $table => $service) {
            if ($this->tableExists($table)) {
                $parts[] = $vasOrder($table, $service);
            }
        }

        if ($this->tableExists('ad_campaigns')) {
            $parts[] = "SELECT
                'ad_campaigns' AS source_table,
                id AS source_id,
                public_id AS internal_ref,
                user_id AS user_id,
                NULL AS counterparty_user_id,
                CONCAT('ads_', format) AS service_code,
                amount AS amount,
                'KZT' AS currency,
                created_at AS created_at,
                updated_at AS updated_at,
                paid_at AS paid_at,
                status AS order_status,
                status AS payment_status,
                pg_order_id AS pg_order_id,
                pg_payment_id AS pg_payment_id,
                'freedompay' AS channel,
                failure_reason AS failure_reason,
                NULL AS wallet_type,
                NULL AS related_order_id
            FROM ad_campaigns";
        }

        if ($this->tableExists('business_package_payments')) {
            $parts[] = "SELECT
                'business_package_payments' AS source_table,
                id AS source_id,
                CAST(id AS CHAR) AS internal_ref,
                user_id AS user_id,
                NULL AS counterparty_user_id,
                'business_package' AS service_code,
                amount_kzt AS amount,
                'KZT' AS currency,
                created_at AS created_at,
                updated_at AS updated_at,
                CASE WHEN status = 'paid' THEN updated_at ELSE NULL END AS paid_at,
                status AS order_status,
                status AS payment_status,
                NULL AS pg_order_id,
                NULL AS pg_payment_id,
                COALESCE(channel, 'wallet') AS channel,
                NULL AS failure_reason,
                NULL AS wallet_type,
                NULL AS related_order_id
            FROM business_package_payments";
        }

        if ($this->tableExists('payments')) {
            $joinOrders = $this->tableExists('orders');
            $seller = $joinOrders ? 'o.seller_id' : 'NULL';
            $ost = $joinOrders ? 'o.status' : 'NULL';
            $from = $joinOrders
                ? 'payments p LEFT JOIN orders o ON o.id = p.order_id'
                : 'payments p';
            $parts[] = "SELECT
                'payments' AS source_table,
                p.id AS source_id,
                CAST(p.order_id AS CHAR) AS internal_ref,
                p.buyer_id AS user_id,
                {$seller} AS counterparty_user_id,
                NULL AS service_code,
                p.amount AS amount,
                'KZT' AS currency,
                p.created_at AS created_at,
                NULL AS updated_at,
                p.paid_at AS paid_at,
                {$ost} AS order_status,
                p.status AS payment_status,
                p.pg_order_id AS pg_order_id,
                p.pg_payment_id AS pg_payment_id,
                'freedompay' AS channel,
                NULL AS failure_reason,
                NULL AS wallet_type,
                p.order_id AS related_order_id
            FROM {$from}";
        }

        if ($this->tableExists('delivery_payments')) {
            $parts[] = "SELECT
                'delivery_payments' AS source_table,
                id AS source_id,
                CAST(delivery_order_id AS CHAR) AS internal_ref,
                buyer_user_id AS user_id,
                NULL AS counterparty_user_id,
                'delivery' AS service_code,
                amount AS amount,
                COALESCE(currency, 'KZT') AS currency,
                created_at AS created_at,
                NULL AS updated_at,
                paid_at AS paid_at,
                status AS order_status,
                status AS payment_status,
                pg_order_id AS pg_order_id,
                pg_payment_id AS pg_payment_id,
                COALESCE(acquirer_provider, 'freedompay') AS channel,
                failure_reason AS failure_reason,
                NULL AS wallet_type,
                NULL AS related_order_id
            FROM delivery_payments";
        }

        if ($this->tableExists('wallet_transactions')) {
            $parts[] = "SELECT
                'wallet_transactions' AS source_table,
                id AS source_id,
                CAST(id AS CHAR) AS internal_ref,
                user_id AS user_id,
                NULL AS counterparty_user_id,
                CASE
                    WHEN type = 'listing_fee' THEN 'service_listing_publish'
                    WHEN type = 'business_package' THEN 'business_package'
                    ELSE NULL
                END AS service_code,
                ABS(amount) AS amount,
                'KZT' AS currency,
                created_at AS created_at,
                NULL AS updated_at,
                created_at AS paid_at,
                type AS order_status,
                type AS payment_status,
                NULL AS pg_order_id,
                acquiring_rrn AS pg_payment_id,
                CASE
                    WHEN type IN ('deposit','withdraw') THEN 'simulated_wallet'
                    ELSE 'wallet'
                END AS channel,
                NULL AS failure_reason,
                type AS wallet_type,
                order_id AS related_order_id
            FROM wallet_transactions
            WHERE type IN (
                'listing_fee','business_package','platform_commission',
                'escrow_hold','escrow_release','escrow_refund',
                'direct_payment','direct_receive',
                'deposit','withdraw'
            )";
        }

        if ($parts === []) {
            return null;
        }

        return implode("\nUNION ALL\n", $parts);
    }

    /**
     * @param array<string, mixed> $f
     * @return array{0:string,1:list<mixed>}
     */
    private function buildWhere(array $f): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($f['user_id'])) {
            $where[] = 'j.user_id = ?';
            $params[] = (int) $f['user_id'];
        }
        if ($f['date_from'] !== null) {
            $where[] = 'DATE(j.created_at) >= ?';
            $params[] = $f['date_from'];
        }
        if ($f['date_to'] !== null) {
            $where[] = 'DATE(j.created_at) <= ?';
            $params[] = $f['date_to'];
        }
        if ($f['service_code'] !== '') {
            $where[] = 'j.service_code = ?';
            $params[] = $f['service_code'];
        }
        if ($f['status'] !== '') {
            $where[] = '(j.order_status = ? OR j.payment_status = ?)';
            $params[] = $f['status'];
            $params[] = $f['status'];
        }
        if ($f['q'] !== '') {
            $q = $f['q'];
            $where[] = '(
                CAST(j.source_id AS CHAR) = ?
                OR j.internal_ref = ?
                OR j.pg_order_id = ?
                OR j.pg_payment_id = ?
                OR CAST(j.user_id AS CHAR) = ?
                OR CONCAT(j.source_table, \':\', j.source_id) = ?
            )';
            array_push($params, $q, $q, $q, $q, $q, $q);
        }

        // stream_type фильтруем после classify в SQL через CASE — добавим выражение
        if ($f['stream_type'] !== '') {
            $where[] = '(' . $this->streamCaseSql() . ') = ?';
            $params[] = $f['stream_type'];
        }

        return [implode(' AND ', $where), $params];
    }

    private function streamCaseSql(): string
    {
        // Зеркало FinanceJournalPolicy::classify для SQL (надёжные префиксы/типы)
        return "CASE
            WHEN j.source_table = 'delivery_payments' OR j.pg_order_id LIKE 'zk-del-%' THEN 'delivery_service'
            WHEN j.source_table IN (
                'boost_orders','highlight_orders','top_fixed_orders','top_fixed_xl_orders','vip_orders',
                'ad_campaigns','target_push_campaigns','mass_push_campaigns','business_package_payments'
            ) OR j.pg_order_id LIKE 'zk-boost-%'
              OR j.pg_order_id LIKE 'zk-highlight-%'
              OR j.pg_order_id LIKE 'zk-topfixed%'
              OR j.pg_order_id LIKE 'zk-vip-%'
              OR j.pg_order_id LIKE 'zk-ad-%'
              OR j.pg_order_id LIKE 'zk-tp-%'
              OR j.pg_order_id LIKE 'zk-mp-%'
              OR j.wallet_type IN ('listing_fee','business_package')
            THEN 'vas_purchase'
            WHEN j.source_table = 'payments'
              OR j.wallet_type IN ('escrow_hold','escrow_release','escrow_refund','direct_payment','direct_receive')
            THEN 'escrow_funds'
            WHEN j.wallet_type = 'platform_commission' THEN 'safe_deal_arbitration_fee'
            WHEN j.wallet_type IN ('deposit','withdraw') THEN 'wallet_ledger'
            ELSE 'other'
        END";
    }

    private function buildOrder(string $sort): string
    {
        return match ($sort) {
            'created_at_asc' => 'ORDER BY j.created_at ASC, j.source_table ASC, j.source_id ASC',
            'amount_desc' => 'ORDER BY j.amount DESC, j.created_at DESC, j.source_table ASC, j.source_id DESC',
            default => 'ORDER BY j.created_at DESC, j.source_table ASC, j.source_id DESC',
        };
    }

    /**
     * Точная пагинация при фильтре payment_confirmed (вычисляемое поле).
     *
     * @param array<string, mixed> $f
     * @return array{items:list<array>,total:int,page:int,pages:int}
     */
    private function listWithConfirmedFilter(string $union, array $f): array
    {
        [$whereSql, $params] = $this->buildWhere(array_merge($f, ['payment_confirmed' => null]));
        // убрать confirmed из where — его нет в SQL; buildWhere уже игнорирует confirmed
        $orderSql = $this->buildOrder((string) $f['sort']);
        $sql = "SELECT * FROM ({$union}) AS j WHERE {$whereSql} {$orderSql} LIMIT 5000";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        $want = (bool) $f['payment_confirmed'];
        $filtered = [];
        foreach ($rows as $row) {
            $entry = $this->mapRow($row);
            if ($entry['payment_confirmed'] === $want) {
                $filtered[] = $this->publicListItem($entry);
            }
        }
        $total = count($filtered);
        $perPage = (int) $f['per_page'];
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min((int) $f['page'], $pages);
        $slice = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        return ['items' => $slice, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $table = (string) ($row['source_table'] ?? '');
        $id = (int) ($row['source_id'] ?? 0);
        $stream = FinanceJournalPolicy::classify([
            'source_table' => $table,
            'pg_order_id' => $row['pg_order_id'] ?? null,
            'wallet_type' => $row['wallet_type'] ?? null,
        ]);

        $serviceCode = $row['service_code'] !== null && $row['service_code'] !== ''
            ? (string) $row['service_code']
            : (FinanceJournalPolicy::SERVICE_BY_TABLE[$table] ?? null);

        $title = null;
        $vasId = null;
        if ($serviceCode) {
            $def = PaidServiceCatalog::find($serviceCode);
            if ($def) {
                $title = (string) ($def['title'] ?? $serviceCode);
                $vasId = (string) ($def['vas_id'] ?? '');
            } elseif (str_starts_with($serviceCode, 'ads_')) {
                $title = 'Ads ' . substr($serviceCode, 4);
                $vasId = match (substr($serviceCode, 4)) {
                    'hero' => 'VAS-07',
                    'category' => 'VAS-08',
                    'sidebar' => 'VAS-09',
                    'exclusive' => 'VAS-10',
                    default => null,
                };
            } elseif ($serviceCode === 'delivery') {
                $title = 'Доставка';
            } elseif ($serviceCode === 'business_package') {
                $title = 'Business Package / AI addon';
                $vasId = 'VAS-14/15';
            }
        }

        $flags = [];
        if (($row['channel'] ?? '') === 'simulated_wallet') {
            $flags[] = 'simulated';
        }
        if (empty($row['pg_order_id']) && in_array($table, [
            'boost_orders', 'highlight_orders', 'top_fixed_orders', 'top_fixed_xl_orders', 'vip_orders',
            'ad_campaigns', 'payments', 'delivery_payments',
        ], true)) {
            // pg may be null for some pending — not always a flag
        }

        $confirmed = FinanceJournalPolicy::isPaymentConfirmed([
            'stream_type' => $stream,
            'source_table' => $table,
            'order_status' => $row['order_status'] ?? null,
            'payment_status' => $row['payment_status'] ?? null,
            'paid_at' => $row['paid_at'] ?? null,
            'pg_payment_id' => $row['pg_payment_id'] ?? null,
            'channel' => $row['channel'] ?? null,
            'wallet_type' => $row['wallet_type'] ?? null,
        ]);

        return [
            'entry_id' => FinanceJournalPolicy::makeEntryId($table, $id),
            'stream_type' => $stream,
            'source_table' => $table,
            'source_id' => $id,
            'internal_ref' => $row['internal_ref'] !== null ? (string) $row['internal_ref'] : null,
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'counterparty_user_id' => $row['counterparty_user_id'] !== null ? (int) $row['counterparty_user_id'] : null,
            'service_code' => $serviceCode,
            'service_title' => $title,
            'vas_id' => $vasId,
            'amount' => $row['amount'] !== null ? (int) $row['amount'] : null,
            'currency' => (string) ($row['currency'] ?? 'KZT'),
            'tariff_note' => null,
            'created_at' => $row['created_at'] !== null ? (string) $row['created_at'] : null,
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
            'paid_at' => $row['paid_at'] !== null ? (string) $row['paid_at'] : null,
            'order_status' => $row['order_status'] !== null ? (string) $row['order_status'] : null,
            'payment_status' => $row['payment_status'] !== null ? (string) $row['payment_status'] : null,
            'payment_confirmed' => $confirmed,
            'pg_order_id' => $row['pg_order_id'] !== null && $row['pg_order_id'] !== '' ? (string) $row['pg_order_id'] : null,
            'pg_payment_id' => $row['pg_payment_id'] !== null && $row['pg_payment_id'] !== '' ? (string) $row['pg_payment_id'] : null,
            'channel' => $row['channel'] !== null ? (string) $row['channel'] : null,
            'failure_reason' => $row['failure_reason'] !== null && $row['failure_reason'] !== ''
                ? mb_substr((string) $row['failure_reason'], 0, 255)
                : null,
            'wallet_type' => $row['wallet_type'] !== null ? (string) $row['wallet_type'] : null,
            'related_order_id' => $row['related_order_id'] !== null ? (int) $row['related_order_id'] : null,
            'flags' => $flags,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<array{at:?string,type:string,label:string}>
     */
    private function buildHistory(array $entry): array
    {
        $events = [];
        if (!empty($entry['created_at'])) {
            $events[] = ['at' => $entry['created_at'], 'type' => 'created', 'label' => 'Создание записи'];
        }
        if (!empty($entry['paid_at'])) {
            $events[] = ['at' => $entry['paid_at'], 'type' => 'paid_at', 'label' => 'Отметка paid_at'];
        }
        if (!empty($entry['updated_at']) && ($entry['updated_at'] !== $entry['created_at'])) {
            $events[] = ['at' => $entry['updated_at'], 'type' => 'updated', 'label' => 'Обновление записи'];
        }

        $table = (string) $entry['source_table'];
        if ($table === 'payments' && !empty($entry['related_order_id'])) {
            try {
                foreach ((new OrderReturnEvent())->forOrder((int) $entry['related_order_id']) as $ev) {
                    $events[] = [
                        'at' => (string) ($ev['created_at'] ?? ''),
                        'type' => 'return_' . (string) ($ev['event_type'] ?? 'event'),
                        'label' => 'Return: ' . (string) ($ev['event_type'] ?? ''),
                    ];
                }
            } catch (Throwable) {
                // история неполная — не выдумываем
            }
        }

        // ActivityLog по entity — только безопасные поля
        try {
            $entityType = match ($table) {
                'payments' => 'order',
                'boost_orders' => 'boost_order',
                'vip_orders' => 'vip_order',
                'delivery_payments' => 'delivery_payment',
                default => null,
            };
            if ($entityType !== null) {
                $stmt = $this->db->prepare(
                    'SELECT action, message, created_at FROM activity_logs
                     WHERE entity_type = ? AND entity_id = ?
                     ORDER BY id ASC LIMIT 50'
                );
                $entityId = $table === 'payments' && !empty($entry['related_order_id'])
                    ? (int) $entry['related_order_id']
                    : (int) $entry['source_id'];
                $stmt->execute([$entityType, $entityId]);
                foreach ($stmt->fetchAll() ?: [] as $log) {
                    $events[] = [
                        'at' => (string) ($log['created_at'] ?? ''),
                        'type' => 'audit:' . (string) ($log['action'] ?? ''),
                        'label' => mb_substr((string) ($log['message'] ?? ''), 0, 200),
                    ];
                }
            }
        } catch (Throwable) {
        }

        // дедуп + сортировка
        $seen = [];
        $out = [];
        foreach ($events as $e) {
            $key = ($e['at'] ?? '') . '|' . $e['type'] . '|' . $e['label'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $e;
        }
        usort($out, static function ($a, $b) {
            return strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? ''));
        });

        return $out;
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<array{label:string,path:string}>
     */
    private function relatedLinks(array $entry): array
    {
        $links = [];
        if (!empty($entry['user_id'])) {
            $links[] = ['label' => 'user', 'path' => '/admin/users/' . (int) $entry['user_id']];
        }
        if (($entry['source_table'] ?? '') === 'payments' && !empty($entry['related_order_id'])) {
            $links[] = ['label' => 'order', 'path' => '/orders/' . (int) $entry['related_order_id']];
        }
        if (($entry['source_table'] ?? '') === 'ad_campaigns') {
            $links[] = ['label' => 'ads_moderation', 'path' => '/admin/ads'];
        }

        return $links;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function publicListItem(array $entry): array
    {
        return $this->stripSecrets([
            'entry_id' => $entry['entry_id'],
            'stream_type' => $entry['stream_type'],
            'source_table' => $entry['source_table'],
            'source_id' => $entry['source_id'],
            'internal_ref' => $entry['internal_ref'],
            'user_id' => $entry['user_id'],
            'service_code' => $entry['service_code'],
            'service_title' => $entry['service_title'],
            'vas_id' => $entry['vas_id'],
            'amount' => $entry['amount'],
            'currency' => $entry['currency'],
            'created_at' => $entry['created_at'],
            'updated_at' => $entry['updated_at'],
            'paid_at' => $entry['paid_at'],
            'order_status' => $entry['order_status'],
            'payment_status' => $entry['payment_status'],
            'payment_confirmed' => $entry['payment_confirmed'],
            'pg_order_id' => $entry['pg_order_id'],
            'pg_payment_id' => $entry['pg_payment_id'],
            'channel' => $entry['channel'],
            'flags' => $entry['flags'],
        ]);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function publicDetailItem(array $entry): array
    {
        return $this->stripSecrets([
            'entry_id' => $entry['entry_id'],
            'stream_type' => $entry['stream_type'],
            'source_table' => $entry['source_table'],
            'source_id' => $entry['source_id'],
            'internal_ref' => $entry['internal_ref'],
            'user_id' => $entry['user_id'],
            'counterparty_user_id' => $entry['counterparty_user_id'],
            'service_code' => $entry['service_code'],
            'service_title' => $entry['service_title'],
            'vas_id' => $entry['vas_id'],
            'amount' => $entry['amount'],
            'currency' => $entry['currency'],
            'tariff_note' => $entry['tariff_note'],
            'created_at' => $entry['created_at'],
            'updated_at' => $entry['updated_at'],
            'paid_at' => $entry['paid_at'],
            'order_status' => $entry['order_status'],
            'payment_status' => $entry['payment_status'],
            'payment_confirmed' => $entry['payment_confirmed'],
            'pg_order_id' => $entry['pg_order_id'],
            'pg_payment_id' => $entry['pg_payment_id'],
            'channel' => $entry['channel'],
            'failure_reason' => $entry['failure_reason'],
            'flags' => $entry['flags'],
            'history' => $entry['history'] ?? [],
            'related_links' => $entry['related_links'] ?? [],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function stripSecrets(array $payload): array
    {
        foreach (FinanceJournalPolicy::secretKeysForbiddenInPayload() as $k) {
            unset($payload[$k]);
        }

        return $payload;
    }
}
