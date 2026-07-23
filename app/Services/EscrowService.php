<?php

namespace App\Services;

use App\Core\Database;
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

    public const DELIVERY_METHODS = ['kazpost', 'cdek', 'courier', 'other'];

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

    /** Покупатель (или продавец) отмечает «доставлено». */
    public function markDelivered(int $orderId, int $actorId): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }
        if (!$this->isParty($order, $actorId)) {
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

            $db->commit();
        } catch (\Throwable $e) {
            $db = Database::connect();
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'error' => t('wallet.op_failed')];
        }

        $msg = $auto
            ? t('escrow.notify_auto_released', ['id' => $orderId, 'amount' => number_format($amount, 0, '', ' ')])
            : t('escrow.notify_released', ['id' => $orderId, 'amount' => number_format($amount, 0, '', ' ')]);

        (new Notification())->createFor($sellerId, $msg);
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
