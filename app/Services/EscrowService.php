<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Bonus;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Wallet;

/**
 * Эскроу-арбитр: деньги на «сейфе» до выполнения условий сделки.
 */
class EscrowService
{
    /** Дней на проверку товара после доставки */
    public const INSPECT_DAYS = 3;

    public const STATUSES = [
        'escrowed',
        'shipped',
        'delivered',
        'completed',
        'dispute',
        'return_approved',
        'return_shipped',
        'return_delivered',
        'refunded',
        'cancelled',
    ];

    public const DELIVERY_METHODS = ['kazpost', 'cdek', 'courier', 'other', 'digital'];

    public function __construct(private ?Order $orders = null)
    {
        $this->orders = $orders ?? new Order();
    }

    /** Авто: если срок проверки истёк — разморозить продавцу. */
    public function processDeadlines(?int $orderId = null): void
    {
        $list = $orderId
            ? array_filter([$this->orders->find($orderId)])
            : $this->orders->findDeliveredPastInspect();

        foreach ($list as $order) {
            if (($order['status'] ?? '') !== 'delivered') {
                continue;
            }
            $until = $order['inspect_until'] ?? null;
            if (!$until || strtotime((string) $until) > time()) {
                continue;
            }
            $this->releaseToSeller((int) $order['id'], null, true);
        }
    }

    /** @return array{ok: bool, error?: string} */
    public function addTracking(int $orderId, int $actorId, string $tracking, string $carrier = ''): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }
        if ((int) $order['seller_id'] !== $actorId) {
            return ['ok' => false, 'error' => t('escrow.forbidden')];
        }
        if (($order['status'] ?? '') !== 'escrowed') {
            return ['ok' => false, 'error' => t('escrow.bad_status')];
        }

        $tracking = trim($tracking);
        if ($tracking === '' || mb_strlen($tracking) < 5) {
            return ['ok' => false, 'error' => t('escrow.track_required')];
        }

        $this->orders->updateFields($orderId, [
            'status' => 'shipped',
            'tracking_number' => $tracking,
            'carrier' => $carrier !== '' ? $carrier : ($order['delivery_method'] ?? ''),
            'shipped_at' => date('Y-m-d H:i:s'),
        ]);

        (new Notification())->createFor(
            (int) $order['buyer_id'],
            t('escrow.notify_shipped', ['id' => $orderId, 'track' => $tracking])
        );

        return ['ok' => true];
    }

    /** Только покупатель отмечает «доставлено». */
    public function markDelivered(int $orderId, int $actorId): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }
        if ((int) $order['buyer_id'] !== $actorId) {
            return ['ok' => false, 'error' => t('escrow.forbidden')];
        }
        if (($order['status'] ?? '') !== 'shipped') {
            return ['ok' => false, 'error' => t('escrow.bad_status')];
        }

        $deliveredAt = date('Y-m-d H:i:s');
        $inspectUntil = date('Y-m-d H:i:s', strtotime('+' . self::INSPECT_DAYS . ' days'));

        $this->orders->updateFields($orderId, [
            'status' => 'delivered',
            'delivered_at' => $deliveredAt,
            'inspect_until' => $inspectUntil,
        ]);

        $other = (int) $order['buyer_id'] === $actorId
            ? (int) $order['seller_id']
            : (int) $order['buyer_id'];

        (new Notification())->createFor(
            $other,
            t('escrow.notify_delivered', ['id' => $orderId, 'days' => self::INSPECT_DAYS])
        );

        return ['ok' => true];
    }

    /**
     * Покупатель отменяет сделку, пока продавец ещё не отправил товар.
     * escrowed → возврат денег с эскроу + товар снова в продаже.
     * awaiting_payment → отмена неоплаченного резерва.
     * @return array{ok: bool, error?: string}
     */
    public function cancelByBuyer(int $orderId, int $buyerId): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }
        if ((int) $order['buyer_id'] !== $buyerId) {
            return ['ok' => false, 'error' => t('escrow.forbidden')];
        }

        $status = (string) ($order['status'] ?? '');
        if (!in_array($status, ['escrowed', 'awaiting_payment'], true)) {
            return ['ok' => false, 'error' => t('escrow.cancel_too_late')];
        }

        $amount = (int) $order['amount'];
        $productId = (int) $order['product_id'];
        $needsRefund = $status === 'escrowed' && ($order['escrow_hold'] ?? '') === 'holding';
        $cancelledOrderIds = [$orderId];

        try {
            $db = Database::connect();
            $db->beginTransaction();

            $lock = $db->prepare('SELECT id, status, escrow_hold FROM orders WHERE id = ? FOR UPDATE');
            $lock->execute([$orderId]);
            $locked = $lock->fetch();
            if (!$locked || !in_array($locked['status'] ?? '', ['escrowed', 'awaiting_payment'], true)) {
                $db->rollBack();
                return ['ok' => false, 'error' => t('escrow.cancel_too_late')];
            }

            $needsRefund = ($locked['status'] ?? '') === 'escrowed'
                && ($locked['escrow_hold'] ?? '') === 'holding';

            $fields = [
                'status' => 'cancelled',
                'escrow_hold' => $needsRefund ? 'refunded_buyer' : 'none',
            ];
            if ($needsRefund) {
                $fields['refunded_at'] = date('Y-m-d H:i:s');
            }
            $this->orders->updateFields($orderId, $fields);

            if ($needsRefund) {
                (new Wallet())->refundFromEscrow($buyerId, $amount, $orderId);
            }

            $cancelledOrderIds = [$orderId];
            $productIdsToRestore = [$productId];

            if (($locked['status'] ?? '') === 'awaiting_payment') {
                $paymentModel = new \App\Models\Payment();
                $payment = $paymentModel->findByOrderId($orderId);
                if (
                    !$payment
                    || ($payment['status'] ?? '') !== \App\Models\Payment::STATUS_PENDING
                ) {
                    $stmtPay = $db->prepare(
                        "SELECT * FROM payments
                         WHERE buyer_id = ? AND status = ?
                         ORDER BY id DESC LIMIT 20"
                    );
                    $stmtPay->execute([$buyerId, \App\Models\Payment::STATUS_PENDING]);
                    foreach ($stmtPay->fetchAll() as $candidate) {
                        $items = $this->cartItemsFromPaymentMeta($candidate['meta'] ?? null);
                        foreach ($items as $item) {
                            if ((int) ($item['order_id'] ?? 0) === $orderId) {
                                $payment = $candidate;
                                break 2;
                            }
                        }
                    }
                }

                if ($payment && ($payment['status'] ?? '') === \App\Models\Payment::STATUS_PENDING) {
                    $cartItems = $this->cartItemsFromPaymentMeta($payment['meta'] ?? null);
                    if ($cartItems === []) {
                        $cartItems = [[
                            'order_id' => (int) $payment['order_id'],
                            'product_id' => (int) $payment['product_id'],
                        ]];
                    }

                    $updPay = $db->prepare(
                        "UPDATE payments SET status = ? WHERE id = ? AND status = ?"
                    );
                    $updPay->execute([
                        \App\Models\Payment::STATUS_CANCELLED,
                        (int) $payment['id'],
                        \App\Models\Payment::STATUS_PENDING,
                    ]);

                    $cancelledOrderIds = [];
                    $productIdsToRestore = [];
                    foreach ($cartItems as $item) {
                        $oid = (int) ($item['order_id'] ?? 0);
                        $pid = (int) ($item['product_id'] ?? 0);
                        if ($oid <= 0) {
                            continue;
                        }
                        $cancelledOrderIds[] = $oid;
                        if ($pid > 0) {
                            $productIdsToRestore[] = $pid;
                        }
                        $this->orders->updateFields($oid, [
                            'status' => 'cancelled',
                            'escrow_hold' => 'none',
                        ]);
                    }
                }
            }

            foreach (array_unique($productIdsToRestore) as $pid) {
                $this->reactivateProduct($db, (int) $pid);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db = Database::connect();
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'error' => t('wallet.op_failed')];
        }

        foreach (array_unique($cancelledOrderIds) as $oid) {
            $row = $this->orders->find((int) $oid);
            if (!$row) {
                continue;
            }
            (new Notification())->createFor(
                (int) $row['seller_id'],
                t('escrow.notify_cancelled_seller', ['id' => (int) $oid])
            );
        }
        if ($needsRefund) {
            (new Notification())->createFor(
                $buyerId,
                t('escrow.notify_cancelled_buyer', [
                    'id' => $orderId,
                    'amount' => number_format($amount, 0, '', ' '),
                ])
            );
        }

        return ['ok' => true];
    }

    private function reactivateProduct(\PDO $db, int $productId): void
    {
        if ($productId <= 0) {
            return;
        }

        // Не трогаем архив; sold/reserved/пустой ENUM — возвращаем в продажу.
        $stmt = $db->prepare(
            "UPDATE products
             SET status = 'active'
             WHERE id = ?
               AND status <> 'active'
               AND status <> 'archived'"
        );
        $stmt->execute([$productId]);

        // На случай если статус уже active, но updated_at полезен для отладки — no-op ok.
        if ($stmt->rowCount() === 0) {
            $check = $db->prepare('SELECT status FROM products WHERE id = ? LIMIT 1');
            $check->execute([$productId]);
            $current = (string) ($check->fetchColumn() ?: '');
            if ($current !== '' && $current !== 'active' && $current !== 'archived') {
                $force = $db->prepare('UPDATE products SET status = \'active\' WHERE id = ?');
                $force->execute([$productId]);
            }
        }
    }

    /** @return list<array{order_id?: int, product_id?: int}> */
    private function cartItemsFromPaymentMeta(mixed $meta): array
    {
        if (!is_string($meta) || $meta === '') {
            return [];
        }
        $decoded = json_decode($meta, true);
        if (!is_array($decoded) || empty($decoded['cart_items']) || !is_array($decoded['cart_items'])) {
            return [];
        }
        return $decoded['cart_items'];
    }

    /** Покупатель: «товар получил, всё ок» → разморозка продавцу. */
    public function confirmReceived(int $orderId, int $buyerId): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }
        if ((int) $order['buyer_id'] !== $buyerId) {
            return ['ok' => false, 'error' => t('escrow.forbidden')];
        }
        if (!in_array($order['status'] ?? '', ['delivered', 'shipped'], true)) {
            return ['ok' => false, 'error' => t('escrow.bad_status')];
        }

        if (($order['status'] ?? '') === 'shipped') {
            $this->orders->updateFields($orderId, [
                'status' => 'delivered',
                'delivered_at' => date('Y-m-d H:i:s'),
                'inspect_until' => date('Y-m-d H:i:s'),
            ]);
        }

        return $this->releaseToSeller($orderId, $buyerId, false);
    }

    /** @return array{ok: bool, error?: string} */
    public function openDispute(int $orderId, int $buyerId, string $reason, array $evidenceFiles = []): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }
        if ((int) $order['buyer_id'] !== $buyerId) {
            return ['ok' => false, 'error' => t('escrow.forbidden')];
        }
        if (($order['status'] ?? '') !== 'delivered') {
            return ['ok' => false, 'error' => t('escrow.dispute_only_delivered')];
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            return ['ok' => false, 'error' => t('escrow.dispute_reason_short')];
        }

        $this->orders->updateFields($orderId, [
            'status' => 'dispute',
            'dispute_reason' => $reason,
            'dispute_evidence' => $evidenceFiles ? json_encode($evidenceFiles, JSON_UNESCAPED_UNICODE) : null,
            'disputed_at' => date('Y-m-d H:i:s'),
        ]);

        (new Notification())->createFor(
            (int) $order['seller_id'],
            t('escrow.notify_dispute', ['id' => $orderId])
        );

        return ['ok' => true];
    }

    /** Арбитр (админ): одобрить возврат. */
    public function approveReturn(int $orderId, int $adminId): array
    {
        $order = $this->orders->find($orderId);
        if (!$order || ($order['status'] ?? '') !== 'dispute') {
            return ['ok' => false, 'error' => t('escrow.bad_status')];
        }

        $this->orders->updateFields($orderId, [
            'status' => 'return_approved',
            'arbiter_id' => $adminId,
            'arbiter_decision' => 'approve_return',
            'arbiter_at' => date('Y-m-d H:i:s'),
        ]);

        (new Notification())->createFor(
            (int) $order['buyer_id'],
            t('escrow.notify_return_approved', ['id' => $orderId])
        );
        (new Notification())->createFor(
            (int) $order['seller_id'],
            t('escrow.notify_return_approved_seller', ['id' => $orderId])
        );

        return ['ok' => true];
    }

    /** Арбитр: отклонить спор → деньги продавцу. */
    public function rejectDispute(int $orderId, int $adminId): array
    {
        $order = $this->orders->find($orderId);
        if (!$order || ($order['status'] ?? '') !== 'dispute') {
            return ['ok' => false, 'error' => t('escrow.bad_status')];
        }

        $this->orders->updateFields($orderId, [
            'arbiter_id' => $adminId,
            'arbiter_decision' => 'reject_dispute',
            'arbiter_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->releaseToSeller($orderId, null, false);
    }

    /** Покупатель отправляет товар обратно. */
    public function addReturnTracking(int $orderId, int $buyerId, string $tracking): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }
        if ((int) $order['buyer_id'] !== $buyerId) {
            return ['ok' => false, 'error' => t('escrow.forbidden')];
        }
        if (($order['status'] ?? '') !== 'return_approved') {
            return ['ok' => false, 'error' => t('escrow.bad_status')];
        }

        $tracking = trim($tracking);
        if ($tracking === '' || mb_strlen($tracking) < 5) {
            return ['ok' => false, 'error' => t('escrow.track_required')];
        }

        $this->orders->updateFields($orderId, [
            'status' => 'return_shipped',
            'return_tracking' => $tracking,
            'return_shipped_at' => date('Y-m-d H:i:s'),
        ]);

        (new Notification())->createFor(
            (int) $order['seller_id'],
            t('escrow.notify_return_shipped', ['id' => $orderId, 'track' => $tracking])
        );

        return ['ok' => true];
    }

    /** Продавец подтвердил получение возврата → деньги покупателю. */
    public function confirmReturnReceived(int $orderId, int $sellerId): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }
        if ((int) $order['seller_id'] !== $sellerId) {
            return ['ok' => false, 'error' => t('escrow.forbidden')];
        }
        if (($order['status'] ?? '') !== 'return_shipped') {
            return ['ok' => false, 'error' => t('escrow.bad_status')];
        }

        $this->orders->updateFields($orderId, [
            'status' => 'return_delivered',
            'return_delivered_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->refundToBuyer($orderId);
    }

    /** @return array{ok: bool, error?: string} */
    private function releaseToSeller(int $orderId, ?int $actorId, bool $auto): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }
        $status = $order['status'] ?? '';
        if ($status === 'completed') {
            return ['ok' => true];
        }
        if (!in_array($status, ['delivered', 'dispute', 'shipped'], true)) {
            return ['ok' => false, 'error' => t('escrow.bad_status')];
        }
        if (($order['escrow_hold'] ?? '') === 'released_seller') {
            return ['ok' => true];
        }

        $amount = (int) $order['amount'];
        $sellerId = (int) $order['seller_id'];

        try {
            $db = Database::connect();
            $db->beginTransaction();

            $this->orders->updateFields($orderId, [
                'status' => 'completed',
                'escrow_hold' => 'released_seller',
                'confirmed_at' => date('Y-m-d H:i:s'),
                'released_at' => date('Y-m-d H:i:s'),
            ]);

            (new Wallet())->releaseFromEscrow($sellerId, $amount, $orderId);
            (new Bonus())->awardSale($sellerId, $orderId);

            $db->commit();
        } catch (\Throwable $e) {
            $db = Database::connect();
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'error' => t('wallet.op_failed')];
        }

        try {
            (new PersonalLimitService())->addTurnoverFromOrder($sellerId, $orderId, $amount);
        } catch (\Throwable $e) {
            // лимит не должен ломать выплату
        }

        $msg = $auto
            ? t('escrow.notify_auto_released', ['id' => $orderId, 'amount' => number_format($amount, 0, '', ' ')])
            : t('escrow.notify_released', ['id' => $orderId, 'amount' => number_format($amount, 0, '', ' ')]);

        (new Notification())->createFor($sellerId, $msg);
        (new Notification())->createFor(
            $sellerId,
            t('bonuses.notify_sale', ['amount' => Bonus::format(Bonus::AMOUNT_SALE)])
        );
        if ($actorId === null || $actorId !== (int) $order['buyer_id']) {
            (new Notification())->createFor(
                (int) $order['buyer_id'],
                t('escrow.notify_completed_buyer', ['id' => $orderId])
            );
        }

        return ['ok' => true];
    }

    /** @return array{ok: bool, error?: string} */
    private function refundToBuyer(int $orderId): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }
        if (($order['escrow_hold'] ?? '') === 'refunded_buyer' || ($order['status'] ?? '') === 'refunded') {
            return ['ok' => true];
        }

        $amount = (int) $order['amount'];
        $buyerId = (int) $order['buyer_id'];

        try {
            $db = Database::connect();
            $db->beginTransaction();

            $this->orders->updateFields($orderId, [
                'status' => 'refunded',
                'escrow_hold' => 'refunded_buyer',
                'refunded_at' => date('Y-m-d H:i:s'),
            ]);

            (new Wallet())->refundFromEscrow($buyerId, $amount, $orderId);
            $this->reactivateProduct($db, (int) ($order['product_id'] ?? 0));

            $db->commit();
        } catch (\Throwable $e) {
            $db = Database::connect();
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'error' => t('wallet.op_failed')];
        }

        (new Notification())->createFor(
            $buyerId,
            t('escrow.notify_refunded', [
                'id' => $orderId,
                'amount' => number_format($amount, 0, '', ' '),
            ])
        );
        (new Notification())->createFor(
            (int) $order['seller_id'],
            t('escrow.notify_refunded_seller', ['id' => $orderId])
        );

        return ['ok' => true];
    }

    private function isParty(array $order, int $userId): bool
    {
        return (int) $order['buyer_id'] === $userId || (int) $order['seller_id'] === $userId;
    }

    public static function statusLabel(string $status): string
    {
        $key = 'escrow.status_' . $status;
        $label = t($key);
        return $label === $key ? $status : $label;
    }

    public static function deliveryLabel(string $method): string
    {
        $key = 'escrow.delivery_' . $method;
        $label = t($key);
        return $label === $key ? $method : $label;
    }
}
