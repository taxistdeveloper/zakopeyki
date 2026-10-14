<?php

declare(strict_types=1);

namespace App\Services\Vip;

use App\Core\Auth;
use App\Core\Database;
use App\Helpers\ActivityLogger;
use App\Models\HighlightOrder;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TopFixedOrder;
use App\Models\VipLift;
use App\Models\VipOrder;
use App\Services\Payment\FreedomPayGateway;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\TopFixed\TopFixedPolicy;
use PDO;
use PDOException;
use Throwable;

/**
 * Одна покупка VIP. Компоненты включаются без отдельной оплаты:
 * закрепление и выделение пишутся в свои таблицы с нулевой ценой,
 * два поднятия двигают sort_at тем же способом, что и обычное поднятие.
 */
final class VipService
{
    private VipOrder $orders;
    private VipLift $lifts;
    private TopFixedOrder $pins;
    private HighlightOrder $highlights;
    private Product $products;
    private Setting $settings;
    private PaymentGatewayInterface $gateway;
    private PDO $db;

    /** @var array<int, string>|null */
    private static ?array $activeMap = null;

    public function __construct(
        ?VipOrder $orders = null,
        ?VipLift $lifts = null,
        ?TopFixedOrder $pins = null,
        ?HighlightOrder $highlights = null,
        ?Product $products = null,
        ?Setting $settings = null,
        ?PaymentGatewayInterface $gateway = null
    ) {
        $this->orders = $orders ?? new VipOrder();
        $this->lifts = $lifts ?? new VipLift();
        $this->pins = $pins ?? new TopFixedOrder();
        $this->highlights = $highlights ?? new HighlightOrder();
        $this->products = $products ?? new Product();
        $this->settings = $settings ?? new Setting();
        $this->gateway = $gateway ?? new FreedomPayGateway();
        $this->db = Database::connect();
    }

    /**
     * @param array<string, mixed> $product
     * @return array{active: bool, ends_at: ?string}
     */
    public static function presentation(array $product): array
    {
        try {
            return self::shared()->presentationFor($product);
        } catch (Throwable) {
            return ['active' => false, 'ends_at' => null];
        }
    }

    public static function shared(): self
    {
        static $instance = null;

        return $instance ??= new self();
    }

    /**
     * @param array<string, mixed> $product
     * @return array{active: bool, ends_at: ?string}
     */
    public function presentationFor(array $product): array
    {
        if (!TopFixedPolicy::isEligibleListing($product)) {
            return ['active' => false, 'ends_at' => null];
        }
        $id = (int) ($product['id'] ?? 0);
        $ends = $this->activeMap()[$id] ?? null;
        $started = date('Y-m-d H:i:s', time() - 60);
        if ($ends === null || !VipPolicy::displayActive(true, $started, $ends)) {
            return ['active' => false, 'ends_at' => null];
        }

        return ['active' => true, 'ends_at' => $ends];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return array<int, array{code: string, can_purchase: bool, continue_payment: bool, notice: ?string, ends_at: ?string, category: ?string, lifts_remaining: ?int}>
     */
    public function statesForProducts(int $userId, array $products): array
    {
        $this->expireDue();
        $this->runDueLifts(10);
        $enabled = $this->isEnabled();
        $gatewayReady = $this->gateway->isConfigured();
        $orders = $this->orders->latestByUser($userId);
        $ids = [];
        foreach ($orders as $order) {
            if ((string) ($order['status'] ?? '') === VipPolicy::STATUS_APPLIED) {
                $ids[] = (int) $order['id'];
            }
        }
        $pending = $this->lifts->pendingCounts($ids);
        $states = [];
        foreach ($products as $product) {
            $id = (int) ($product['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $order = $orders[$id] ?? null;
            if (is_array($order) && (string) ($order['status'] ?? '') === VipPolicy::STATUS_APPLIED) {
                $order['lifts_remaining'] = $pending[(int) $order['id']] ?? 0;
            }
            $states[$id] = VipPolicy::uiState($enabled, $product, $userId, $order, $gatewayReady);
        }

        return $states;
    }

    /**
     * @return array<int, array{code: string, can_purchase: bool, continue_payment: bool, notice: ?string, ends_at: ?string, category: ?string, lifts_remaining: ?int}>
     */
    public function statesForUser(int $userId): array
    {
        return $this->statesForProducts($userId, $this->products->byUser($userId));
    }

    /**
     * @param array<string, mixed> $product
     * @return array{code: string, can_purchase: bool, continue_payment: bool, notice: ?string, ends_at: ?string, category: ?string, lifts_remaining: ?int}
     */
    public function cardState(int $userId, array $product): array
    {
        static $cache = [];
        if (!isset($cache[$userId])) {
            try {
                $cache[$userId] = $this->statesForUser($userId);
            } catch (Throwable) {
                $cache[$userId] = [];
            }
        }
        $id = (int) ($product['id'] ?? 0);
        if (isset($cache[$userId][$id])) {
            return $cache[$userId][$id];
        }

        return VipPolicy::uiState(false, $product, $userId, null, false);
    }

    public function isEnabled(): bool
    {
        try {
            $this->settings->forget(VipPolicy::SETTING_KEY);

            return VipPolicy::resolveEnabled($this->settings->getBool(VipPolicy::SETTING_KEY, false));
        } catch (Throwable) {
            return VipPolicy::resolveEnabled(null, true);
        }
    }

    public function setEnabled(bool $enabled): bool
    {
        $role = Auth::check() ? (string) (Auth::user()['role'] ?? '') : '';
        $actorId = Auth::check() ? (int) Auth::id() : 0;
        if (!VipPolicy::canToggle($role) || $actorId <= 0) {
            ActivityLogger::warning(
                'vip.feature_toggle_denied',
                'VIP не переключён: нет права администратора',
                'settings',
                null,
                ['actor_id' => $actorId, 'role' => $role]
            );

            return false;
        }

        try {
            $before = $this->isEnabled();
            $this->settings->setBool(VipPolicy::SETTING_KEY, $enabled);
            $audit = VipPolicy::toggleAudit($actorId, $before, $enabled);
            ActivityLogger::info(
                $audit['action'],
                $enabled ? 'VIP включён' : 'VIP выключен',
                'settings',
                null,
                $audit['context']
            );

            return true;
        } catch (Throwable) {
            ActivityLogger::warning('vip.feature_toggle_failed', 'Не удалось переключить VIP', 'settings', null);

            return false;
        }
    }

    public function expireDue(): int
    {
        try {
            $count = $this->orders->markExpired(date('Y-m-d H:i:s'));
            $this->lifts->skipOutsidePackage(date('Y-m-d H:i:s'));
            if ($count > 0) {
                self::$activeMap = null;
            }

            return $count;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Выполнить подошедшие слоты. Повторный запуск не поднимает объявление второй раз.
     */
    public function runDueLifts(int $limit = 20): int
    {
        $done = 0;
        try {
            $this->lifts->skipOutsidePackage(date('Y-m-d H:i:s'));
            $ids = $this->lifts->dueIds(date('Y-m-d H:i:s'), $limit);
        } catch (Throwable) {
            return 0;
        }

        foreach ($ids as $liftId) {
            if ($this->runOneLift($liftId)) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function beginPurchase(int $userId, int $productId, array $input = []): array
    {
        $posted = $input['amount'] ?? $input['price'] ?? null;
        if ($posted !== null && $posted !== '' && (int) preg_replace('/\D/', '', (string) $posted) !== VipPolicy::PRICE_KZT) {
            ActivityLogger::warning('vip.price_ignored', 'Клиентская цена VIP проигнорирована', 'product', $productId, [
                'posted' => (string) $posted,
                'server_amount' => VipPolicy::PRICE_KZT,
            ]);
        }

        $amount = VipPolicy::serverAmount($input);
        $enabled = $this->isEnabled();
        $product = $productId > 0 ? $this->products->find($productId) : null;
        $now = date('Y-m-d H:i:s');
        $active = $productId > 0 ? $this->orders->findActiveByProduct($productId, false, $now) : null;
        $block = VipPolicy::purchaseBlock($product, $userId, $enabled, $active !== null);
        if ($block !== null) {
            return ['ok' => false, 'error_code' => $block, 'ends_at' => $active['ends_at'] ?? null];
        }
        if (!$this->gateway->isConfigured()) {
            return ['ok' => false, 'error_code' => 'gateway_not_configured'];
        }

        $idempotencyKey = $this->normalizeIdempotencyKey($input['idempotency_key'] ?? null);
        if ($idempotencyKey === false) {
            return ['ok' => false, 'error_code' => 'generic'];
        }
        if (is_string($idempotencyKey)) {
            $existing = $this->orders->findByIdempotencyKey($idempotencyKey);
            if ($existing) {
                if ((int) $existing['user_id'] !== $userId || (int) $existing['product_id'] !== $productId) {
                    return ['ok' => false, 'error_code' => 'idempotency_conflict'];
                }

                return $this->presentExisting($existing);
            }
        }

        $pgOrderId = VipPolicy::PG_PREFIX . $productId . '-' . bin2hex(random_bytes(6));
        $publicId = bin2hex(random_bytes(16));

        try {
            $this->db->beginTransaction();
            $locked = $this->products->findAndLock($productId);
            $now = date('Y-m-d H:i:s');
            $active = $this->orders->findActiveByProduct($productId, true, $now);
            $block = VipPolicy::purchaseBlock($locked, $userId, $this->isEnabled(), $active !== null);
            if ($block !== null) {
                $this->db->rollBack();

                return ['ok' => false, 'error_code' => $block, 'ends_at' => $active['ends_at'] ?? null];
            }

            $open = $this->orders->findOpenByProduct($productId, true);
            if ($open) {
                $this->db->commit();
                if ((int) $open['user_id'] !== $userId) {
                    return ['ok' => false, 'error_code' => 'forbidden'];
                }

                return $this->presentExisting($open);
            }

            $orderId = $this->orders->insertPending([
                'public_id' => $publicId,
                'user_id' => $userId,
                'product_id' => $productId,
                'amount' => $amount,
                'open_key' => VipPolicy::openKey($productId),
                'idempotency_key' => $idempotencyKey,
                'pg_order_id' => $pgOrderId,
            ]);
            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (VipOrder::isDuplicate($e)) {
                if (is_string($idempotencyKey)) {
                    $byKey = $this->orders->findByIdempotencyKey($idempotencyKey);
                    if ($byKey && (int) $byKey['user_id'] === $userId && (int) $byKey['product_id'] === $productId) {
                        return $this->presentExisting($byKey);
                    }
                }
                $open = $this->orders->findOpenByProduct($productId, false);
                if ($open && (int) $open['user_id'] === $userId) {
                    return $this->presentExisting($open);
                }
            }
            ActivityLogger::warning('vip.order_create_failed', 'Не удалось создать заказ VIP', 'product', $productId);

            return ['ok' => false, 'error_code' => 'generic'];
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            ActivityLogger::warning('vip.order_create_failed', 'Не удалось создать заказ VIP', 'product', $productId);

            return ['ok' => false, 'error_code' => 'generic'];
        }

        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error_code' => 'generic'];
        }

        ActivityLogger::info('vip.order_created', 'Создан заказ VIP', 'vip_order', $orderId, [
            'product_id' => $productId,
            'amount' => $amount,
            'pg_order_id' => $pgOrderId,
        ]);

        return $this->initGateway($order);
    }

    /**
     * @return array{ok: bool, gateway: string, status?: string, order_id?: int, applied?: bool, error?: string}
     */
    public function handleGatewayResult(
        string $pgOrderId,
        string $pgPaymentId,
        string $pgAmount,
        int $pgResult,
        bool $canReject,
        ?string $pgCurrency = null
    ): array {
        $order = $this->orders->findByPgOrderId($pgOrderId);
        if (!$order) {
            return ['ok' => false, 'gateway' => 'error', 'error' => 'payment_not_found'];
        }

        try {
            $this->db->beginTransaction();
            $locked = $this->orders->lockById((int) $order['id']);
            if (!$locked) {
                $this->db->rollBack();

                return ['ok' => false, 'gateway' => 'error', 'error' => 'payment_not_found'];
            }

            $product = $this->products->findAndLock((int) $locked['product_id']);
            $eligible = is_array($product)
                && (int) ($product['user_id'] ?? 0) === (int) $locked['user_id']
                && TopFixedPolicy::isEligibleListing($product);

            $decision = VipPolicy::decideCallback(
                (string) $locked['status'],
                (int) $locked['amount'],
                VipPolicy::parseExactTenge($pgAmount),
                $pgResult === 1,
                $this->isEnabled(),
                $eligible,
                $canReject,
                VipPolicy::currencyOk($pgCurrency)
            );

            $applied = false;
            $status = (string) $decision['next_status'];
            $reason = $decision['failure_reason'];
            $startsAt = null;
            $endsAt = null;
            $category = null;

            if ($decision['idempotent']) {
                $this->db->commit();
                $this->logTransition('vip.callback_duplicate', $locked, (string) $locked['status'], false);

                return [
                    'ok' => true,
                    'gateway' => 'ok',
                    'status' => (string) $locked['status'],
                    'order_id' => (int) $locked['id'],
                    'applied' => false,
                ];
            }

            if ($decision['apply']) {
                $period = VipPolicy::componentPeriod(time());
                $startsAt = VipPolicy::sqlDateTime($period['starts_at']);
                $endsAt = VipPolicy::sqlDateTime($period['ends_at']);
                $category = trim((string) ($product['category'] ?? ''));
                $applied = $this->writeBundle($locked, $product, $period['starts_at'], $period['ends_at'], $category);
                if (!$applied) {
                    $status = VipPolicy::STATUS_APPLY_FAILED;
                    $reason = 'listing_ineligible';
                    $startsAt = null;
                    $endsAt = null;
                    $category = null;
                }
            }

            $now = date('Y-m-d H:i:s');
            $paidAt = in_array($status, [VipPolicy::STATUS_PAID, VipPolicy::STATUS_APPLIED, VipPolicy::STATUS_APPLY_FAILED], true)
                ? $now
                : null;
            $appliedAt = $status === VipPolicy::STATUS_APPLIED ? $now : null;
            $openKey = VipPolicy::keepsOpenLock($status)
                ? ((string) ($locked['open_key'] ?? '') !== '' ? (string) $locked['open_key'] : VipPolicy::openKey((int) $locked['product_id']))
                : null;

            $this->orders->saveState(
                (int) $locked['id'],
                $status,
                $openKey,
                $pgPaymentId,
                $reason,
                $paidAt,
                $appliedAt,
                $startsAt,
                $endsAt,
                $category
            );
            $this->db->commit();
            self::$activeMap = null;
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            ActivityLogger::warning('vip.persist_failed', 'Сбой записи результата VIP', 'vip_order', (int) $order['id'], [
                'pg_order_id' => $pgOrderId,
            ]);

            return ['ok' => false, 'gateway' => 'error', 'error' => 'persist_failed', 'order_id' => (int) $order['id']];
        }

        $this->logTransition(
            $applied ? 'vip.applied' : ($status === VipPolicy::STATUS_PAID ? 'vip.payment_confirmed' : 'vip.callback'),
            $locked,
            $status,
            $applied
        );

        $gateway = (string) $decision['gateway'];
        if ($gateway === 'rejected' && !$canReject) {
            $gateway = 'error';
        }

        return [
            'ok' => $gateway === 'ok',
            'gateway' => $gateway,
            'status' => $status,
            'order_id' => (int) $locked['id'],
            'applied' => $applied,
            'error' => $reason,
        ];
    }

    /**
     * @return array{ok: bool, applied: int, error_code?: string}
     */
    public function recoverUnapplied(): array
    {
        $role = Auth::check() ? (string) (Auth::user()['role'] ?? '') : '';
        if (!VipPolicy::canToggle($role)) {
            return ['ok' => false, 'applied' => 0, 'error_code' => 'forbidden'];
        }
        if (!$this->isEnabled()) {
            return ['ok' => false, 'applied' => 0, 'error_code' => 'disabled'];
        }

        $applied = 0;
        foreach ($this->orders->findUnapplied() as $row) {
            $result = $this->handleGatewayResult(
                (string) $row['pg_order_id'],
                (string) ($row['pg_payment_id'] ?? ''),
                (string) VipPolicy::PRICE_KZT,
                1,
                false,
                'KZT'
            );
            if (!empty($result['applied'])) {
                $applied++;
            }
        }

        ActivityLogger::info('vip.recover', 'Восстановление неприменённых VIP', 'vip_order', null, [
            'applied' => $applied,
        ]);

        return ['ok' => true, 'applied' => $applied];
    }

    /**
     * Дописать компоненты, если оплаченный пакет уже applied, а связь оборвалась.
     * Срок берётся из пакета, а не заново.
     */
    public function repairApplied(): int
    {
        $repaired = 0;
        foreach ($this->orders->findApplied() as $order) {
            $starts = isset($order['starts_at']) ? strtotime((string) $order['starts_at']) : false;
            $ends = isset($order['ends_at']) ? strtotime((string) $order['ends_at']) : false;
            $category = trim((string) ($order['category'] ?? ''));
            if ($starts === false || $ends === false || $ends <= $starts || $category === '') {
                continue;
            }
            $product = $this->products->find((int) $order['product_id']);
            if (!is_array($product)) {
                continue;
            }
            try {
                $this->db->beginTransaction();
                $locked = $this->orders->lockById((int) $order['id']);
                if (!$locked || (string) ($locked['status'] ?? '') !== VipPolicy::STATUS_APPLIED) {
                    $this->db->commit();
                    continue;
                }
                $this->writeBundle($locked, $product, $starts, $ends, $category);
                $this->db->commit();
                $repaired++;
            } catch (Throwable) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            }
        }

        return $repaired;
    }

    /**
     * @return array<int, string>
     */
    private function activeMap(): array
    {
        if (self::$activeMap !== null) {
            return self::$activeMap;
        }
        $this->expireDue();
        self::$activeMap = $this->orders->activeEnds(date('Y-m-d H:i:s'));

        return self::$activeMap;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $product
     */
    private function writeBundle(array $order, array $product, int $startsAt, int $endsAt, string $category): bool
    {
        if ($category === '' || $endsAt <= $startsAt || ($endsAt - $startsAt) > VipPolicy::DURATION_SECONDS) {
            return false;
        }
        if ((int) ($product['user_id'] ?? 0) !== (int) ($order['user_id'] ?? 0)) {
            return false;
        }
        $slots = VipPolicy::liftSchedule($startsAt);
        if (count($slots) !== VipPolicy::LIFT_COUNT) {
            return false;
        }
        foreach ($slots as $slot) {
            if ((int) $slot['at'] >= $endsAt) {
                return false;
            }
        }
        $startSql = VipPolicy::sqlDateTime($startsAt);
        $endSql = VipPolicy::sqlDateTime($endsAt);
        $vipOrderId = (int) $order['id'];
        $userId = (int) $order['user_id'];
        $productId = (int) $order['product_id'];
        $this->pins->insertVipGrant($vipOrderId, $userId, $productId, $category, $startSql, $endSql);
        $this->highlights->insertVipGrant($vipOrderId, $userId, $productId, $startSql, $endSql);
        $this->lifts->ensureSlots($vipOrderId, $slots);

        return true;
    }

    private function runOneLift(int $liftId): bool
    {
        try {
            $this->db->beginTransaction();
            $lift = $this->lifts->lockById($liftId);
            if (!$lift) {
                $this->db->rollBack();

                return false;
            }
            $order = $this->orders->lockById((int) $lift['vip_order_id']);
            if (!$order) {
                $this->db->rollBack();

                return false;
            }
            $scheduled = strtotime((string) ($lift['scheduled_at'] ?? ''));
            $packageEnds = strtotime((string) ($order['ends_at'] ?? ''));
            $product = $this->products->findAndLock((int) $order['product_id']);
            $eligible = $scheduled !== false
                && $packageEnds !== false
                && is_array($product)
                && (int) ($product['user_id'] ?? 0) === (int) $order['user_id']
                && TopFixedPolicy::isEligibleListing($product);
            $decision = VipPolicy::decideLift(
                (string) ($lift['status'] ?? ''),
                $scheduled === false ? 0 : $scheduled,
                $packageEnds === false ? 0 : $packageEnds,
                time(),
                $eligible
            );
            if (!$decision['run']) {
                if (!$decision['idempotent'] && (string) $decision['next'] === VipPolicy::LIFT_SKIPPED) {
                    $this->lifts->markStatus($liftId, VipPolicy::LIFT_SKIPPED, null);
                }
                $this->db->commit();

                return false;
            }

            $sortAt = date('Y-m-d H:i:s');
            $bumped = $this->products->applyBoostSort((int) $order['product_id'], (int) $order['user_id'], $sortAt);
            if (!$bumped) {
                $this->db->commit();

                return false;
            }
            $marked = $this->lifts->markStatus($liftId, VipPolicy::LIFT_DONE, $sortAt);
            if (!$marked) {
                $this->db->rollBack();

                return false;
            }
            $this->db->commit();
            ActivityLogger::info('vip.lift_done', 'Автоматическое поднятие VIP', 'vip_lift', $liftId, [
                'vip_order_id' => (int) $order['id'],
                'product_id' => (int) $order['product_id'],
                'slot' => (int) ($lift['slot'] ?? 0),
            ]);

            return true;
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return false;
        }
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function presentExisting(array $order): array
    {
        $status = (string) ($order['status'] ?? '');
        $orderId = (int) ($order['id'] ?? 0);
        $endsAt = isset($order['ends_at']) ? (string) $order['ends_at'] : null;
        $startsAt = isset($order['starts_at']) ? (string) $order['starts_at'] : null;

        if ($status === VipPolicy::STATUS_APPLIED || $status === VipPolicy::STATUS_EXPIRED) {
            return [
                'ok' => true,
                'already_applied' => true,
                'active' => VipPolicy::windowActive($startsAt, $endsAt),
                'order_id' => $orderId,
                'amount' => VipPolicy::PRICE_KZT,
                'status' => $status,
                'ends_at' => $endsAt,
                'category' => isset($order['category']) ? (string) $order['category'] : null,
                'lifts_remaining' => $this->lifts->pendingCount($orderId),
            ];
        }

        if ($status === VipPolicy::STATUS_PENDING) {
            if (!empty($order['redirect_url'])) {
                return [
                    'ok' => true,
                    'reused' => true,
                    'order_id' => $orderId,
                    'redirect_url' => (string) $order['redirect_url'],
                    'amount' => VipPolicy::PRICE_KZT,
                    'status' => $status,
                ];
            }

            return [
                'ok' => false,
                'error_code' => 'payment_pending',
                'order_id' => $orderId,
                'status' => $status,
            ];
        }

        if ($status === VipPolicy::STATUS_PAID || $status === VipPolicy::STATUS_APPLY_FAILED) {
            return [
                'ok' => false,
                'error_code' => 'awaiting_apply',
                'order_id' => $orderId,
                'status' => $status,
            ];
        }

        return [
            'ok' => false,
            'error_code' => 'payment_failed',
            'order_id' => $orderId,
            'status' => $status,
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function initGateway(array $order): array
    {
        $orderId = (int) $order['id'];
        $amount = (int) $order['amount'];
        if ($amount !== VipPolicy::PRICE_KZT || !$this->isEnabled()) {
            $this->failPending($orderId, $amount !== VipPolicy::PRICE_KZT ? 'amount_mismatch' : 'disabled');

            return ['ok' => false, 'error_code' => $amount !== VipPolicy::PRICE_KZT ? 'generic' : 'disabled', 'order_id' => $orderId];
        }

        $intent = $this->gateway->createPaymentIntent([
            'order_id' => (string) $order['pg_order_id'],
            'amount' => VipPolicy::PRICE_KZT,
            'description' => 'VIP «Всё включено» на 7 дней',
            'param1' => (string) $order['public_id'],
        ]);

        if (empty($intent['ok']) || empty($intent['redirect_url'])) {
            $this->failPending($orderId, 'gateway_init_failed');
            ActivityLogger::warning('vip.gateway_init_failed', 'FreedomPay не открыл оплату VIP', 'vip_order', $orderId);

            return ['ok' => false, 'error_code' => 'gateway_init_failed', 'order_id' => $orderId];
        }

        $this->orders->saveRedirect(
            $orderId,
            (string) $intent['redirect_url'],
            isset($intent['external_id']) ? (string) $intent['external_id'] : null
        );

        return [
            'ok' => true,
            'order_id' => $orderId,
            'redirect_url' => (string) $intent['redirect_url'],
            'amount' => VipPolicy::PRICE_KZT,
            'status' => VipPolicy::STATUS_PENDING,
        ];
    }

    private function failPending(int $orderId, string $reason): void
    {
        try {
            $this->db->beginTransaction();
            $locked = $this->orders->lockById($orderId);
            if ($locked
                && (string) $locked['status'] === VipPolicy::STATUS_PENDING
                && empty($locked['redirect_url'])
            ) {
                $this->orders->saveState($orderId, VipPolicy::STATUS_FAILED, null, null, $reason, null, null, null, null, null);
            }
            $this->db->commit();
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
        }
    }

    /** @return string|null|false */
    private function normalizeIdempotencyKey(mixed $raw): string|null|false
    {
        if ($raw === null) {
            return null;
        }
        $key = trim((string) $raw);
        if ($key === '') {
            return null;
        }
        if (strlen($key) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
            return false;
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $order
     */
    private function logTransition(string $action, array $order, string $status, bool $applied): void
    {
        ActivityLogger::info($action, 'Переход заказа VIP в ' . $status, 'vip_order', (int) ($order['id'] ?? 0), [
            'product_id' => (int) ($order['product_id'] ?? 0),
            'status' => $status,
            'applied' => $applied,
            'amount' => (int) ($order['amount'] ?? 0),
            'component_amount' => VipPolicy::COMPONENT_AMOUNT_KZT,
        ]);
    }
}
