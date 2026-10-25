<?php

declare(strict_types=1);

namespace App\Services\TopFixedXl;

use App\Core\Auth;
use App\Core\Database;
use App\Helpers\ActivityLogger;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TopFixedXlOrder;
use App\Services\Payment\HalykEpayGateway;
use App\Services\Payment\PaymentGatewayInterface;
use PDO;
use PDOException;
use Throwable;

/**
 * Заказ Top Fixed XL, подтверждение оплаты FreedomPay и приоритет в выдаче.
 * Симуляция оплаты и возврат со страницы оплаты услугу не включают.
 * Порядок выдачи считает SQL каталога, а не эта страница.
 */
final class TopFixedXlService
{
    private TopFixedXlOrder $orders;
    private Product $products;
    private Setting $settings;
    private PaymentGatewayInterface $gateway;
    private PDO $db;

    /** @var array<int, array{ends_at: string, category: string}>|null */
    private static ?array $activeMap = null;

    public function __construct(
        ?TopFixedXlOrder $orders = null,
        ?Product $products = null,
        ?Setting $settings = null,
        ?PaymentGatewayInterface $gateway = null
    ) {
        $this->orders = $orders ?? new TopFixedXlOrder();
        $this->products = $products ?? new Product();
        $this->settings = $settings ?? new Setting();
        $this->gateway = $gateway ?? new HalykEpayGateway();
        $this->db = Database::connect();
    }

    /**
     * @param array<string, mixed> $product
     * @return array{active: bool, ends_at: ?string, category: ?string}
     */
    public static function presentation(array $product): array
    {
        try {
            return self::shared()->presentationFor($product);
        } catch (Throwable) {
            return ['active' => false, 'ends_at' => null, 'category' => null];
        }
    }

    public static function shared(): self
    {
        static $instance = null;

        return $instance ??= new self();
    }

    /**
     * @param array<string, mixed> $product
     * @return array{active: bool, ends_at: ?string, category: ?string}
     */
    public function presentationFor(array $product): array
    {
        $id = (int) ($product['id'] ?? 0);
        $pin = $this->activeMap()[$id] ?? null;
        if ($pin === null) {
            return ['active' => false, 'ends_at' => null, 'category' => null];
        }
        $current = trim((string) ($product['category'] ?? ''));
        $started = date('Y-m-d H:i:s', time() - 60);
        if (!TopFixedXlPolicy::categoryMatches($pin['category'], $current)
            || !TopFixedXlPolicy::displayActive(true, $started, $pin['ends_at'])
        ) {
            return ['active' => false, 'ends_at' => null, 'category' => null];
        }

        return ['active' => true, 'ends_at' => $pin['ends_at'], 'category' => $pin['category']];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return array<int, array{code: string, can_purchase: bool, continue_payment: bool, notice: ?string, ends_at: ?string, category: ?string}>
     */
    public function statesForProducts(int $userId, array $products): array
    {
        $this->expireDue();
        $enabled = $this->isEnabled();
        $gatewayReady = false;
        try {
            $gatewayReady = $this->gateway->isConfigured();
        } catch (Throwable) {
            $gatewayReady = false;
        }
        $orders = $this->orders->latestByUser($userId);
        $states = [];
        foreach ($products as $product) {
            $id = (int) ($product['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $states[$id] = TopFixedXlPolicy::uiState(
                $enabled,
                $product,
                $userId,
                $orders[$id] ?? null,
                $gatewayReady
            );
        }

        return $states;
    }

    /**
     * @return array<int, array{code: string, can_purchase: bool, continue_payment: bool, notice: ?string, ends_at: ?string, category: ?string}>
     */
    public function statesForUser(int $userId): array
    {
        return $this->statesForProducts($userId, $this->products->byUser($userId));
    }

    /**
     * @param array<string, mixed> $product
     * @return array{code: string, can_purchase: bool, continue_payment: bool, notice: ?string, ends_at: ?string, category: ?string}
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

        return TopFixedXlPolicy::uiState(false, $product, $userId, null, false);
    }

    public function isEnabled(): bool
    {
        try {
            $this->settings->forget(TopFixedXlPolicy::SETTING_KEY);

            return TopFixedXlPolicy::resolveEnabled($this->settings->getBool(TopFixedXlPolicy::SETTING_KEY, false));
        } catch (Throwable) {
            return TopFixedXlPolicy::resolveEnabled(null, true);
        }
    }

    public function setEnabled(bool $enabled): bool
    {
        $role = Auth::check() ? (string) (Auth::user()['role'] ?? '') : '';
        $actorId = Auth::check() ? (int) Auth::id() : 0;
        if (!TopFixedXlPolicy::canToggle($role) || $actorId <= 0) {
            ActivityLogger::warning(
                'top_fixed_xl.feature_toggle_denied',
                'Top Fixed XL не переключён: нет права администратора',
                'settings',
                null,
                ['actor_id' => $actorId, 'role' => $role]
            );

            return false;
        }

        try {
            $before = $this->isEnabled();
            $this->settings->setBool(TopFixedXlPolicy::SETTING_KEY, $enabled);
            $audit = TopFixedXlPolicy::toggleAudit($actorId, $before, $enabled);
            ActivityLogger::info(
                $audit['action'],
                $enabled ? 'Top Fixed XL включён' : 'Top Fixed XL выключен',
                'settings',
                null,
                $audit['context']
            );

            return true;
        } catch (Throwable) {
            ActivityLogger::warning('top_fixed_xl.feature_toggle_failed', 'Не удалось переключить Top Fixed XL', 'settings', null);

            return false;
        }
    }

    public function expireDue(): int
    {
        try {
            $count = $this->orders->markExpired(date('Y-m-d H:i:s'));
            if ($count > 0) {
                self::$activeMap = null;
            }

            return $count;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Создать заказ и отправить покупателя на hosted checkout.
     * Сумма, срок, категория и флаг доступности из $input не используются.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function beginPurchase(int $userId, int $productId, array $input = []): array
    {
        $posted = $input['amount'] ?? $input['price'] ?? null;
        if ($posted !== null && $posted !== '' && (int) preg_replace('/\D/', '', (string) $posted) !== TopFixedXlPolicy::PRICE_KZT) {
            ActivityLogger::warning('top_fixed_xl.price_ignored', 'Клиентская цена Top Fixed XL проигнорирована', 'product', $productId, [
                'posted' => (string) $posted,
                'server_amount' => TopFixedXlPolicy::PRICE_KZT,
            ]);
        }

        $amount = TopFixedXlPolicy::serverAmount($input);
        $enabled = $this->isEnabled();
        $product = $productId > 0 ? $this->products->find($productId) : null;
        $now = date('Y-m-d H:i:s');
        $active = $productId > 0 ? $this->orders->findActiveByProduct($productId, false, $now) : null;
        $block = TopFixedXlPolicy::purchaseBlock($product, $userId, $enabled, $active !== null);
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

        $pgOrderId = TopFixedXlPolicy::PG_PREFIX . $productId . '-' . bin2hex(random_bytes(6));
        $publicId = bin2hex(random_bytes(16));

        try {
            $this->db->beginTransaction();
            $locked = $this->products->findAndLock($productId);
            $now = date('Y-m-d H:i:s');
            $active = $this->orders->findActiveByProduct($productId, true, $now);
            $block = TopFixedXlPolicy::purchaseBlock($locked, $userId, $this->isEnabled(), $active !== null);
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
                'open_key' => TopFixedXlPolicy::openKey($productId),
                'idempotency_key' => $idempotencyKey,
                'pg_order_id' => $pgOrderId,
            ]);
            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (TopFixedXlOrder::isDuplicate($e)) {
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
            ActivityLogger::warning('top_fixed_xl.order_create_failed', 'Не удалось создать заказ Top Fixed XL', 'product', $productId);

            return ['ok' => false, 'error_code' => 'generic'];
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            ActivityLogger::warning('top_fixed_xl.order_create_failed', 'Не удалось создать заказ Top Fixed XL', 'product', $productId);

            return ['ok' => false, 'error_code' => 'generic'];
        }

        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error_code' => 'generic'];
        }

        ActivityLogger::info('top_fixed_xl.order_created', 'Создан заказ Top Fixed XL', 'top_fixed_xl_order', $orderId, [
            'product_id' => $productId,
            'amount' => $amount,
            'pg_order_id' => $pgOrderId,
        ]);

        return $this->initGateway($order);
    }

    /**
     * Подтверждение или отказ FreedomPay. Подпись проверяет контроллер до вызова.
     * Возврат покупателя со страницы оплаты сюда не попадает.
     *
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
                && TopFixedXlPolicy::isEligibleListing($product);

            $decision = TopFixedXlPolicy::decideCallback(
                (string) $locked['status'],
                (int) $locked['amount'],
                TopFixedXlPolicy::parseExactTenge($pgAmount),
                $pgResult === 1,
                $this->isEnabled(),
                $eligible,
                $canReject,
                TopFixedXlPolicy::currencyOk($pgCurrency)
            );

            $applied = false;
            $status = (string) $decision['next_status'];
            $reason = $decision['failure_reason'];
            $startsAt = null;
            $endsAt = null;
            $category = null;

            if ($decision['idempotent']) {
                $this->db->commit();
                $this->logTransition('top_fixed_xl.callback_duplicate', $locked, (string) $locked['status'], false);

                return [
                    'ok' => true,
                    'gateway' => 'ok',
                    'status' => (string) $locked['status'],
                    'order_id' => (int) $locked['id'],
                    'applied' => false,
                ];
            }

            if ($decision['apply']) {
                $period = TopFixedXlPolicy::period(time());
                $startsAt = TopFixedXlPolicy::sqlDateTime($period['starts_at']);
                $endsAt = TopFixedXlPolicy::sqlDateTime($period['ends_at']);
                $category = trim((string) ($product['category'] ?? ''));
                $applied = $category !== '';
                if (!$applied) {
                    $status = TopFixedXlPolicy::STATUS_APPLY_FAILED;
                    $reason = 'listing_ineligible';
                    $startsAt = null;
                    $endsAt = null;
                }
            }

            $now = date('Y-m-d H:i:s');
            $paidAt = in_array($status, [TopFixedXlPolicy::STATUS_PAID, TopFixedXlPolicy::STATUS_APPLIED, TopFixedXlPolicy::STATUS_APPLY_FAILED], true)
                ? $now
                : null;
            $appliedAt = $status === TopFixedXlPolicy::STATUS_APPLIED ? $now : null;
            $openKey = TopFixedXlPolicy::keepsOpenLock($status)
                ? ((string) ($locked['open_key'] ?? '') !== '' ? (string) $locked['open_key'] : TopFixedXlPolicy::openKey((int) $locked['product_id']))
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
            ActivityLogger::warning('top_fixed_xl.persist_failed', 'Сбой записи результата Top Fixed XL', 'top_fixed_xl_order', (int) $order['id'], [
                'pg_order_id' => $pgOrderId,
            ]);

            return ['ok' => false, 'gateway' => 'error', 'error' => 'persist_failed', 'order_id' => (int) $order['id']];
        }

        $this->logTransition(
            $applied ? 'top_fixed_xl.applied' : ($status === TopFixedXlPolicy::STATUS_PAID ? 'top_fixed_xl.payment_confirmed' : 'top_fixed_xl.callback'),
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
     * Включить уже оплаченные, но ещё не закреплённые заказы.
     * Завершённые окна не перезапускает. При выключенном Top Fixed XL ничего не включает.
     *
     * @return array{ok: bool, applied: int, error_code?: string}
     */
    public function recoverUnapplied(): array
    {
        $role = Auth::check() ? (string) (Auth::user()['role'] ?? '') : '';
        if (!TopFixedXlPolicy::canToggle($role)) {
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
                (string) TopFixedXlPolicy::PRICE_KZT,
                1,
                false,
                'KZT'
            );
            if (!empty($result['applied'])) {
                $applied++;
            }
        }

        ActivityLogger::info('top_fixed_xl.recover', 'Восстановление неприменённых Top Fixed XL', 'top_fixed_xl_order', null, [
            'applied' => $applied,
        ]);

        return ['ok' => true, 'applied' => $applied];
    }

    /**
     * @return array<int, array{ends_at: string, category: string}>
     */
    private function activeMap(): array
    {
        if (self::$activeMap !== null) {
            return self::$activeMap;
        }
        $this->expireDue();
        self::$activeMap = $this->orders->activePins(date('Y-m-d H:i:s'));

        return self::$activeMap;
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

        if ($status === TopFixedXlPolicy::STATUS_APPLIED || $status === TopFixedXlPolicy::STATUS_EXPIRED) {
            return [
                'ok' => true,
                'already_applied' => true,
                'active' => TopFixedXlPolicy::windowActive($startsAt, $endsAt),
                'order_id' => $orderId,
                'amount' => TopFixedXlPolicy::PRICE_KZT,
                'status' => $status,
                'ends_at' => $endsAt,
                'category' => isset($order['category']) ? (string) $order['category'] : null,
            ];
        }

        if ($status === TopFixedXlPolicy::STATUS_PENDING) {
            if (!empty($order['redirect_url'])) {
                return [
                    'ok' => true,
                    'reused' => true,
                    'order_id' => $orderId,
                    'redirect_url' => (string) $order['redirect_url'],
                    'amount' => TopFixedXlPolicy::PRICE_KZT,
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

        if ($status === TopFixedXlPolicy::STATUS_PAID || $status === TopFixedXlPolicy::STATUS_APPLY_FAILED) {
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
        if ($amount !== TopFixedXlPolicy::PRICE_KZT || !$this->isEnabled()) {
            $this->failPending($orderId, $amount !== TopFixedXlPolicy::PRICE_KZT ? 'amount_mismatch' : 'disabled');

            return ['ok' => false, 'error_code' => $amount !== TopFixedXlPolicy::PRICE_KZT ? 'generic' : 'disabled', 'order_id' => $orderId];
        }

        $intent = $this->gateway->createPaymentIntent([
            'order_id' => (string) $order['pg_order_id'],
            'amount' => TopFixedXlPolicy::PRICE_KZT,
            'description' => 'Top Fixed XL: приоритет на главной и в выдаче на 14 дней',
            'param1' => (string) $order['public_id'],
        ]);

        if (empty($intent['ok']) || empty($intent['redirect_url'])) {
            $this->failPending($orderId, 'gateway_init_failed');
            ActivityLogger::warning('top_fixed_xl.gateway_init_failed', 'FreedomPay не открыл оплату Top Fixed XL', 'top_fixed_xl_order', $orderId);

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
            'amount' => TopFixedXlPolicy::PRICE_KZT,
            'status' => TopFixedXlPolicy::STATUS_PENDING,
        ];
    }

    private function failPending(int $orderId, string $reason): void
    {
        try {
            $this->db->beginTransaction();
            $locked = $this->orders->lockById($orderId);
            if ($locked
                && (string) $locked['status'] === TopFixedXlPolicy::STATUS_PENDING
                && empty($locked['redirect_url'])
            ) {
                $this->orders->saveState($orderId, TopFixedXlPolicy::STATUS_FAILED, null, null, $reason, null, null, null, null, null);
            }
            $this->db->commit();
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
        }
    }

    /** @return string|null|false null = не задан, false = некорректный */
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
        ActivityLogger::info($action, 'Переход заказа Top Fixed XL в ' . $status, 'top_fixed_xl_order', (int) ($order['id'] ?? 0), [
            'product_id' => (int) ($order['product_id'] ?? 0),
            'status' => $status,
            'applied' => $applied,
            'amount' => (int) ($order['amount'] ?? 0),
        ]);
    }
}
