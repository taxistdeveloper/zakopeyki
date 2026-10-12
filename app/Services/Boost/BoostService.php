<?php

declare(strict_types=1);

namespace App\Services\Boost;

use App\Core\Auth;
use App\Core\Database;
use App\Helpers\ActivityLogger;
use App\Models\BoostOrder;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Payment\FreedomPayGateway;
use App\Services\Payment\PaymentGatewayInterface;
use PDO;
use PDOException;
use Throwable;

/**
 * Заказ Boost, подтверждение оплаты FreedomPay и одно применение sort_at.
 * Симуляция оплаты не подтверждает услугу.
 */
final class BoostService
{
    private BoostOrder $orders;
    private Product $products;
    private Setting $settings;
    private PaymentGatewayInterface $gateway;
    private PDO $db;

    public function __construct(
        ?BoostOrder $orders = null,
        ?Product $products = null,
        ?Setting $settings = null,
        ?PaymentGatewayInterface $gateway = null
    ) {
        $this->orders = $orders ?? new BoostOrder();
        $this->products = $products ?? new Product();
        $this->settings = $settings ?? new Setting();
        $this->gateway = $gateway ?? new FreedomPayGateway();
        $this->db = Database::connect();
    }

    /**
     * Состояния карточек без создания заказа.
     *
     * @param list<array<string, mixed>> $products
     * @return array<int, array{code: string, can_purchase: bool, continue_payment: bool, notice: ?string}>
     */
    public function statesForProducts(int $userId, array $products): array
    {
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
            $states[$id] = BoostPolicy::uiState(
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
     * @return array<int, array{code: string, can_purchase: bool, continue_payment: bool, notice: ?string}>
     */
    public function statesForUser(int $userId): array
    {
        return $this->statesForProducts($userId, $this->products->byUser($userId));
    }

    /**
     * Состояние одной карточки. Повторные карточки того же владельца не читают базу заново.
     *
     * @param array<string, mixed> $product
     * @return array{code: string, can_purchase: bool, continue_payment: bool, notice: ?string}
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

        return BoostPolicy::uiState(false, $product, $userId, null, false);
    }

    public function isEnabled(): bool
    {
        try {
            $this->settings->forget(BoostPolicy::SETTING_KEY);

            return BoostPolicy::resolveEnabled($this->settings->getBool(BoostPolicy::SETTING_KEY, false));
        } catch (Throwable) {
            return BoostPolicy::resolveEnabled(null, true);
        }
    }

    public function setEnabled(bool $enabled): bool
    {
        $role = Auth::check() ? (string) (Auth::user()['role'] ?? '') : '';
        $actorId = Auth::check() ? (int) Auth::id() : 0;
        if (!BoostPolicy::canToggle($role) || $actorId <= 0) {
            ActivityLogger::warning(
                'boost.feature_toggle_denied',
                'Boost не переключён: нет права администратора',
                'settings',
                null,
                ['actor_id' => $actorId, 'role' => $role]
            );

            return false;
        }

        try {
            $before = $this->isEnabled();
            $this->settings->setBool(BoostPolicy::SETTING_KEY, $enabled);
            $audit = BoostPolicy::toggleAudit($actorId, $before, $enabled);
            ActivityLogger::info(
                $audit['action'],
                $enabled ? 'Boost включён' : 'Boost выключен',
                'settings',
                null,
                $audit['context']
            );

            return true;
        } catch (Throwable) {
            ActivityLogger::warning('boost.feature_toggle_failed', 'Не удалось переключить Boost', 'settings', null);

            return false;
        }
    }

    /**
     * Создать заказ и отправить покупателя на hosted checkout.
     * Сумма из $input не используется.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function beginPurchase(int $userId, int $productId, array $input = []): array
    {
        $posted = $input['amount'] ?? $input['price'] ?? null;
        if ($posted !== null && $posted !== '' && (int) preg_replace('/\D/', '', (string) $posted) !== BoostPolicy::PRICE_KZT) {
            ActivityLogger::warning('boost.price_ignored', 'Клиентская цена Boost проигнорирована', 'product', $productId, [
                'posted' => (string) $posted,
                'server_amount' => BoostPolicy::PRICE_KZT,
            ]);
        }

        $amount = BoostPolicy::serverAmount($input);
        $enabled = $this->isEnabled();
        $product = $productId > 0 ? $this->products->find($productId) : null;
        $block = BoostPolicy::purchaseBlock($product, $userId, $enabled);
        if ($block !== null) {
            return ['ok' => false, 'error_code' => $block];
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

        $pgOrderId = BoostPolicy::PG_PREFIX . $productId . '-' . bin2hex(random_bytes(6));
        $publicId = bin2hex(random_bytes(16));

        try {
            $this->db->beginTransaction();
            $locked = $this->products->findAndLock($productId);
            $block = BoostPolicy::purchaseBlock($locked, $userId, $this->isEnabled());
            if ($block !== null) {
                $this->db->rollBack();

                return ['ok' => false, 'error_code' => $block];
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
                'open_key' => BoostPolicy::openKey($productId),
                'idempotency_key' => $idempotencyKey,
                'pg_order_id' => $pgOrderId,
            ]);
            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (BoostOrder::isDuplicate($e)) {
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
            ActivityLogger::warning('boost.order_create_failed', 'Не удалось создать заказ Boost', 'product', $productId);

            return ['ok' => false, 'error_code' => 'generic'];
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            ActivityLogger::warning('boost.order_create_failed', 'Не удалось создать заказ Boost', 'product', $productId);

            return ['ok' => false, 'error_code' => 'generic'];
        }

        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error_code' => 'generic'];
        }

        ActivityLogger::info('boost.order_created', 'Создан заказ Boost', 'boost_order', $orderId, [
            'product_id' => $productId,
            'amount' => $amount,
            'pg_order_id' => $pgOrderId,
        ]);

        return $this->initGateway($order);
    }

    /**
     * Подтверждение или отказ FreedomPay. Подпись проверяет контроллер до вызова.
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
                && BoostPolicy::isEligibleListing($product);

            $decision = BoostPolicy::decideCallback(
                (string) $locked['status'],
                (int) $locked['amount'],
                BoostPolicy::parseExactTenge($pgAmount),
                $pgResult === 1,
                $this->isEnabled(),
                $eligible,
                $canReject,
                BoostPolicy::currencyOk($pgCurrency)
            );

            $applied = false;
            $status = (string) $decision['next_status'];
            $reason = $decision['failure_reason'];

            if ($decision['idempotent']) {
                $this->db->commit();
                $this->logTransition('boost.callback_duplicate', $locked, (string) $locked['status'], false);

                return [
                    'ok' => true,
                    'gateway' => 'ok',
                    'status' => BoostPolicy::STATUS_APPLIED,
                    'order_id' => (int) $locked['id'],
                    'applied' => false,
                ];
            }

            if ($decision['apply']) {
                $sortAt = date('Y-m-d H:i:s');
                $applied = $this->products->applyBoostSort((int) $locked['product_id'], (int) $locked['user_id'], $sortAt);
                if (!$applied) {
                    $status = BoostPolicy::STATUS_APPLY_FAILED;
                    $reason = 'sort_update_failed';
                }
            }

            $now = date('Y-m-d H:i:s');
            $paidAt = in_array($status, [BoostPolicy::STATUS_PAID, BoostPolicy::STATUS_APPLIED, BoostPolicy::STATUS_APPLY_FAILED], true)
                ? $now
                : null;
            $appliedAt = $status === BoostPolicy::STATUS_APPLIED ? $now : null;
            $openKey = BoostPolicy::keepsOpenLock($status)
                ? ((string) ($locked['open_key'] ?? '') !== '' ? (string) $locked['open_key'] : BoostPolicy::openKey((int) $locked['product_id']))
                : null;

            $this->orders->saveState(
                (int) $locked['id'],
                $status,
                $openKey,
                $pgPaymentId,
                $reason,
                $paidAt,
                $appliedAt
            );
            $this->db->commit();
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            ActivityLogger::warning('boost.persist_failed', 'Сбой записи результата Boost', 'boost_order', (int) $order['id'], [
                'pg_order_id' => $pgOrderId,
            ]);

            return ['ok' => false, 'gateway' => 'error', 'error' => 'persist_failed', 'order_id' => (int) $order['id']];
        }

        $this->logTransition(
            $applied ? 'boost.applied' : ($status === BoostPolicy::STATUS_PAID ? 'boost.payment_confirmed' : 'boost.callback'),
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
     * Повторно применить уже оплаченные, но не применённые заказы.
     * При выключенном Boost ничего не применяет.
     *
     * @return array{ok: bool, applied: int, error_code?: string}
     */
    public function recoverUnapplied(): array
    {
        $role = Auth::check() ? (string) (Auth::user()['role'] ?? '') : '';
        if (!BoostPolicy::canToggle($role)) {
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
                (string) BoostPolicy::PRICE_KZT,
                1,
                false,
                'KZT'
            );
            if (!empty($result['applied'])) {
                $applied++;
            }
        }

        ActivityLogger::info('boost.recover', 'Восстановление неприменённых Boost', 'boost_order', null, [
            'applied' => $applied,
        ]);

        return ['ok' => true, 'applied' => $applied];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function presentExisting(array $order): array
    {
        $status = (string) ($order['status'] ?? '');
        $orderId = (int) ($order['id'] ?? 0);

        if ($status === BoostPolicy::STATUS_APPLIED) {
            return [
                'ok' => true,
                'already_applied' => true,
                'order_id' => $orderId,
                'amount' => BoostPolicy::PRICE_KZT,
                'status' => $status,
            ];
        }

        if ($status === BoostPolicy::STATUS_PENDING) {
            if (!empty($order['redirect_url'])) {
                return [
                    'ok' => true,
                    'reused' => true,
                    'order_id' => $orderId,
                    'redirect_url' => (string) $order['redirect_url'],
                    'amount' => BoostPolicy::PRICE_KZT,
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

        if ($status === BoostPolicy::STATUS_PAID || $status === BoostPolicy::STATUS_APPLY_FAILED) {
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
        if ($amount !== BoostPolicy::PRICE_KZT || !$this->isEnabled()) {
            $this->failPending($orderId, $amount !== BoostPolicy::PRICE_KZT ? 'amount_mismatch' : 'disabled');

            return ['ok' => false, 'error_code' => $amount !== BoostPolicy::PRICE_KZT ? 'generic' : 'disabled', 'order_id' => $orderId];
        }

        $intent = $this->gateway->createPaymentIntent([
            'order_id' => (string) $order['pg_order_id'],
            'amount' => BoostPolicy::PRICE_KZT,
            'description' => 'Поднятие объявления в Топ',
            'param1' => (string) $order['public_id'],
        ]);

        if (empty($intent['ok']) || empty($intent['redirect_url'])) {
            $this->failPending($orderId, 'gateway_init_failed');
            ActivityLogger::warning('boost.gateway_init_failed', 'FreedomPay не открыл оплату Boost', 'boost_order', $orderId);

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
            'amount' => BoostPolicy::PRICE_KZT,
            'status' => BoostPolicy::STATUS_PENDING,
        ];
    }

    private function failPending(int $orderId, string $reason): void
    {
        try {
            $this->db->beginTransaction();
            $locked = $this->orders->lockById($orderId);
            if ($locked
                && (string) $locked['status'] === BoostPolicy::STATUS_PENDING
                && empty($locked['redirect_url'])
            ) {
                $this->orders->saveState($orderId, BoostPolicy::STATUS_FAILED, null, null, $reason, null, null);
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
        ActivityLogger::info($action, 'Переход заказа Boost в ' . $status, 'boost_order', (int) ($order['id'] ?? 0), [
            'product_id' => (int) ($order['product_id'] ?? 0),
            'status' => $status,
            'applied' => $applied,
            'amount' => (int) ($order['amount'] ?? 0),
        ]);
    }
}
