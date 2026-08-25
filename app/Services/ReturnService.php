<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderReturnEvent;

/**
 * Гарантия возврата денег (адаптация eBay Money Back Guarantee под эскроу Zakopeyki).
 *
 * Покупатель открывает заявку → продавец отвечает в срок → при молчании заявка
 * принимается автоматически → физический возврат с треком либо выплата без посылки
 * (не получен / курьерский отказ / цифра) → иначе арбитраж площадки.
 */
class ReturnService
{
    public const SELLER_RESPOND_DAYS = 3;
    public const BUYER_SHIP_DAYS = 5;
    public const SELLER_CONFIRM_DAYS = 5;
    public const INR_WAIT_DAYS = 7;
    public const PARTIAL_OFFER_DAYS = 3;

    public const REASON_NOT_AS_DESCRIBED = 'not_as_described';
    public const REASON_NOT_RECEIVED = 'not_received';
    public const REASON_CHANGED_MIND = 'changed_mind';
    public const REASON_COURIER_VOID = 'courier_void';
    public const REASON_DIGITAL_DEFECT = 'digital_defect';

    public const REASONS = [
        self::REASON_NOT_AS_DESCRIBED,
        self::REASON_NOT_RECEIVED,
        self::REASON_CHANGED_MIND,
        self::REASON_COURIER_VOID,
        self::REASON_DIGITAL_DEFECT,
    ];

    public function __construct(
        private ?EscrowService $escrow = null,
        private ?Order $orders = null,
        private ?OrderReturnEvent $events = null
    ) {
        $this->orders = $orders ?? new Order();
        $this->escrow = $escrow ?? new EscrowService($this->orders);
        $this->events = $events ?? new OrderReturnEvent();
    }

    public static function reasonLabel(string $reason): string
    {
        $key = 'escrow.reason_' . $reason;
        $label = t($key);
        return $label === $key ? $reason : $label;
    }

    public static function shippingPayerLabel(string $payer): string
    {
        $key = 'escrow.ship_payer_' . $payer;
        $label = t($key);
        return $label === $key ? $payer : $label;
    }

    public function processTimedActions(): void
    {
        $now = time();

        foreach ($this->orders->findByStatuses(['return_requested']) as $order) {
            $id = (int) $order['id'];
            $offerStatus = (string) ($order['return_offer_status'] ?? 'none');
            if ($offerStatus === 'pending') {
                $until = $order['return_offer_until'] ?? null;
                if ($until && strtotime((string) $until) <= $now) {
                    $this->escalateToArbitration($id, null, 'system', true);
                }
                continue;
            }
            $until = $order['seller_response_until'] ?? null;
            if ($until && strtotime((string) $until) <= $now) {
                $this->acceptReturn($id, (int) $order['seller_id'], false, true);
            }
        }

        foreach ($this->orders->findByStatuses(['return_approved']) as $order) {
            if ((int) ($order['return_requires_shipment'] ?? 0) !== 1) {
                continue;
            }
            if (!empty($order['return_tracking'])) {
                continue;
            }
            $until = $order['return_ship_until'] ?? null;
            if ($until && strtotime((string) $until) <= $now) {
                $this->events->add((int) $order['id'], 'buyer_missed_ship_deadline', 'system');
                $this->escrow->settle((int) $order['id'], 0, [
                    'reactivate' => false,
                    'revoke_digital' => false,
                    'status' => 'completed',
                ]);
                (new Notification())->createFor(
                    (int) $order['buyer_id'],
                    t('escrow.notify_return_ship_missed', ['id' => (int) $order['id']])
                );
                (new Notification())->createFor(
                    (int) $order['seller_id'],
                    t('escrow.notify_return_ship_missed_seller', ['id' => (int) $order['id']])
                );
            }
        }

        foreach ($this->orders->findByStatuses(['return_shipped']) as $order) {
            $until = $order['return_confirm_until'] ?? null;
            if ($until && strtotime((string) $until) <= $now) {
                $this->events->add((int) $order['id'], 'seller_missed_return_confirm', 'system');
                $this->completePhysicalRefund((int) $order['id'], true);
            }
        }
    }

    /**
     * @param list<string> $evidenceFiles
     * @return array{ok: bool, error?: string}
     */
    public function openCase(int $orderId, int $buyerId, string $reason, string $comment, array $evidenceFiles = []): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }
        if ((int) $order['buyer_id'] !== $buyerId) {
            return ['ok' => false, 'error' => t('escrow.forbidden')];
        }

        $reason = trim($reason);
        if (!in_array($reason, self::REASONS, true)) {
            return ['ok' => false, 'error' => t('escrow.return_reason_invalid')];
        }

        $comment = trim($comment);
        if (mb_strlen($comment) < 10) {
            return ['ok' => false, 'error' => t('escrow.dispute_reason_short')];
        }

        $status = (string) ($order['status'] ?? '');
        $digital = $this->isDigitalOrder($order);

        if ($reason === self::REASON_COURIER_VOID) {
            if (($order['delivery_method'] ?? '') !== 'courier' || $status !== 'shipped') {
                return ['ok' => false, 'error' => t('escrow.courier_void_only')];
            }
            return $this->applyCourierVoid($order, $comment, $evidenceFiles);
        }

        if ($reason === self::REASON_NOT_RECEIVED) {
            if ($digital) {
                return ['ok' => false, 'error' => t('escrow.return_reason_invalid')];
            }
            if ($status !== 'shipped') {
                return ['ok' => false, 'error' => t('escrow.inr_only_shipped')];
            }
            $shippedAt = $order['shipped_at'] ?? null;
            if (!$shippedAt || strtotime((string) $shippedAt) > strtotime('-' . self::INR_WAIT_DAYS . ' days')) {
                return ['ok' => false, 'error' => t('escrow.inr_too_early', ['days' => self::INR_WAIT_DAYS])];
            }
        } elseif ($reason === self::REASON_DIGITAL_DEFECT) {
            if (!$digital || $status !== 'delivered') {
                return ['ok' => false, 'error' => t('escrow.digital_return_only')];
            }
            if (!$this->inspectWindowOpen($order)) {
                return ['ok' => false, 'error' => t('escrow.inspect_expired')];
            }
        } else {
            if ($digital) {
                return ['ok' => false, 'error' => t('escrow.return_reason_invalid')];
            }
            if ($status !== 'delivered') {
                return ['ok' => false, 'error' => t('escrow.dispute_only_delivered')];
            }
            if (!$this->inspectWindowOpen($order)) {
                return ['ok' => false, 'error' => t('escrow.inspect_expired')];
            }
        }

        $payer = $this->shippingPayerFor($reason, $digital);
        $requiresShipment = $this->requiresShipment($reason, $digital, false);

        $this->orders->updateFields($orderId, [
            'status' => 'return_requested',
            'return_reason' => $reason,
            'dispute_reason' => $comment,
            'dispute_evidence' => $evidenceFiles ? json_encode($evidenceFiles, JSON_UNESCAPED_UNICODE) : null,
            'disputed_at' => date('Y-m-d H:i:s'),
            'return_shipping_payer' => $payer,
            'return_requires_shipment' => $requiresShipment ? 1 : 0,
            'return_keep_item' => 0,
            'return_offer_status' => 'none',
            'return_offer_amount' => null,
            'return_offer_until' => null,
            'seller_response_until' => date('Y-m-d H:i:s', strtotime('+' . self::SELLER_RESPOND_DAYS . ' days')),
        ]);

        $this->events->add($orderId, 'case_opened', 'buyer', $buyerId, [
            'reason' => $reason,
            'comment' => $comment,
        ]);

        (new Notification())->createFor(
            (int) $order['seller_id'],
            t('escrow.notify_return_requested', [
                'id' => $orderId,
                'days' => self::SELLER_RESPOND_DAYS,
            ])
        );

        return ['ok' => true];
    }

    /** @return array{ok: bool, error?: string} */
    public function acceptReturn(int $orderId, int $sellerId, bool $keepItem, bool $auto = false): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }
        if (!$auto && (int) $order['seller_id'] !== $sellerId) {
            return ['ok' => false, 'error' => t('escrow.forbidden')];
        }
        if (($order['status'] ?? '') !== 'return_requested') {
            return ['ok' => false, 'error' => t('escrow.bad_status')];
        }
        if (($order['return_offer_status'] ?? 'none') === 'pending') {
            return ['ok' => false, 'error' => t('escrow.bad_status')];
        }

        $reason = (string) ($order['return_reason'] ?? self::REASON_NOT_AS_DESCRIBED);
        $digital = $this->isDigitalOrder($order);
        $requiresShipment = !$keepItem && $this->requiresShipment($reason, $digital, false);

        $this->events->add(
            $orderId,
            $auto ? 'auto_accepted' : 'seller_accepted',
            $auto ? 'system' : 'seller',
            $auto ? null : $sellerId,
            ['keep_item' => $keepItem]
        );

        if (!$requiresShipment) {
            $this->orders->updateFields($orderId, [
                'return_keep_item' => $keepItem ? 1 : 0,
                'return_requires_shipment' => 0,
                'arbiter_decision' => $auto ? 'auto_accept_return' : 'seller_accept_return',
                'arbiter_at' => date('Y-m-d H:i:s'),
            ]);
            return $this->escrow->settle($orderId, (int) $order['amount'], [
                'reactivate' => !$keepItem && !$digital && $reason !== self::REASON_NOT_RECEIVED,
                'revoke_digital' => $digital,
                'keep_item' => $keepItem,
                'status' => 'refunded',
            ]);
        }

        $this->orders->updateFields($orderId, [
            'status' => 'return_approved',
            'return_keep_item' => 0,
            'return_requires_shipment' => 1,
            'return_ship_until' => date('Y-m-d H:i:s', strtotime('+' . self::BUYER_SHIP_DAYS . ' days')),
            'arbiter_decision' => $auto ? 'auto_accept_return' : 'seller_accept_return',
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

    /** @return array{ok: bool, error?: string} */
    public function offerPartial(int $orderId, int $sellerId, int $amount): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }
        if ((int) $order['seller_id'] !== $sellerId) {
            return ['ok' => false, 'error' => t('escrow.forbidden')];
        }
        if (($order['status'] ?? '') !== 'return_requested') {
            return ['ok' => false, 'error' => t('escrow.bad_status')];
        }

        $total = (int) $order['amount'];
        if ($amount < 1 || $amount >= $total) {
            return ['ok' => false, 'error' => t('escrow.partial_amount_invalid')];
        }

        $this->orders->updateFields($orderId, [
            'return_offer_amount' => $amount,
            'return_offer_status' => 'pending',
            'return_offer_until' => date('Y-m-d H:i:s', strtotime('+' . self::PARTIAL_OFFER_DAYS . ' days')),
            'return_keep_item' => 1,
            'return_requires_shipment' => 0,
        ]);

        $this->events->add($orderId, 'partial_offered', 'seller', $sellerId, ['amount' => $amount]);

        (new Notification())->createFor(
            (int) $order['buyer_id'],
            t('escrow.notify_partial_offer', [
                'id' => $orderId,
                'amount' => number_format($amount, 0, '', ' '),
            ])
        );

        return ['ok' => true];
    }

    /** @return array{ok: bool, error?: string} */
    public function acceptPartial(int $orderId, int $buyerId): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }
        if ((int) $order['buyer_id'] !== $buyerId) {
            return ['ok' => false, 'error' => t('escrow.forbidden')];
        }
        if (($order['status'] ?? '') !== 'return_requested' || ($order['return_offer_status'] ?? '') !== 'pending') {
            return ['ok' => false, 'error' => t('escrow.bad_status')];
        }

        $amount = (int) ($order['return_offer_amount'] ?? 0);
        if ($amount < 1) {
            return ['ok' => false, 'error' => t('escrow.partial_amount_invalid')];
        }

        $this->orders->updateFields($orderId, [
            'return_offer_status' => 'accepted',
            'return_keep_item' => 1,
        ]);
        $this->events->add($orderId, 'partial_accepted', 'buyer', $buyerId, ['amount' => $amount]);

        return $this->escrow->settle($orderId, $amount, [
            'reactivate' => false,
            'revoke_digital' => false,
            'keep_item' => true,
            'status' => 'partial_refunded',
        ]);
    }

    /** @return array{ok: bool, error?: string} */
    public function declinePartial(int $orderId, int $buyerId): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }
        if ((int) $order['buyer_id'] !== $buyerId) {
            return ['ok' => false, 'error' => t('escrow.forbidden')];
        }
        if (($order['status'] ?? '') !== 'return_requested' || ($order['return_offer_status'] ?? '') !== 'pending') {
            return ['ok' => false, 'error' => t('escrow.bad_status')];
        }

        $this->orders->updateFields($orderId, [
            'return_offer_status' => 'declined',
            'return_offer_until' => null,
            'return_keep_item' => 0,
            'return_requires_shipment' => $this->requiresShipment(
                (string) ($order['return_reason'] ?? ''),
                $this->isDigitalOrder($order),
                false
            ) ? 1 : 0,
        ]);
        $this->events->add($orderId, 'partial_declined', 'buyer', $buyerId);

        return $this->escalateToArbitration($orderId, $buyerId, 'buyer', false);
    }

    /** @return array{ok: bool, error?: string} */
    public function declineReturn(int $orderId, int $sellerId, string $note): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }
        if ((int) $order['seller_id'] !== $sellerId) {
            return ['ok' => false, 'error' => t('escrow.forbidden')];
        }
        if (($order['status'] ?? '') !== 'return_requested') {
            return ['ok' => false, 'error' => t('escrow.bad_status')];
        }

        $note = trim($note);
        if (mb_strlen($note) < 10) {
            return ['ok' => false, 'error' => t('escrow.decline_note_short')];
        }

        $this->events->add($orderId, 'seller_declined', 'seller', $sellerId, ['note' => $note]);

        $existing = trim((string) ($order['dispute_reason'] ?? ''));
        $this->orders->updateFields($orderId, [
            'dispute_reason' => $existing . "\n\n" . t('escrow.seller_decline_prefix') . ' ' . $note,
        ]);

        return $this->escalateToArbitration($orderId, $sellerId, 'seller', false);
    }

    /** @return array{ok: bool, error?: string} */
    public function escalate(int $orderId, int $buyerId): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }
        if ((int) $order['buyer_id'] !== $buyerId) {
            return ['ok' => false, 'error' => t('escrow.forbidden')];
        }
        if (($order['status'] ?? '') !== 'return_requested') {
            return ['ok' => false, 'error' => t('escrow.bad_status')];
        }

        return $this->escalateToArbitration($orderId, $buyerId, 'buyer', false);
    }

    /** @return array{ok: bool, error?: string} */
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
        if ((int) ($order['return_requires_shipment'] ?? 0) !== 1) {
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
            'return_confirm_until' => date('Y-m-d H:i:s', strtotime('+' . self::SELLER_CONFIRM_DAYS . ' days')),
        ]);
        $this->events->add($orderId, 'return_shipped', 'buyer', $buyerId, ['tracking' => $tracking]);

        (new Notification())->createFor(
            (int) $order['seller_id'],
            t('escrow.notify_return_shipped', ['id' => $orderId, 'track' => $tracking])
        );

        return ['ok' => true];
    }

    /** @return array{ok: bool, error?: string} */
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
        $this->events->add($orderId, 'return_received', 'seller', $sellerId);

        return $this->completePhysicalRefund($orderId, false);
    }

    /** Арбитр: полный возврат без обратной посылки. */
    public function arbiterFullRefund(int $orderId, int $adminId): array
    {
        $order = $this->requireDispute($orderId);
        if (isset($order['error'])) {
            return $order;
        }

        $this->orders->updateFields($orderId, [
            'arbiter_id' => $adminId,
            'arbiter_decision' => 'full_refund',
            'arbiter_at' => date('Y-m-d H:i:s'),
            'return_requires_shipment' => 0,
        ]);
        $this->events->add($orderId, 'arbiter_full_refund', 'arbiter', $adminId);

        $digital = $this->isDigitalOrder($order);
        $reason = (string) ($order['return_reason'] ?? '');
        return $this->escrow->settle($orderId, (int) $order['amount'], [
            'reactivate' => !$digital && $reason !== self::REASON_NOT_RECEIVED,
            'revoke_digital' => $digital,
            'status' => 'refunded',
        ]);
    }

    /** Арбитр: частичный возврат, товар остаётся у покупателя. */
    public function arbiterPartialRefund(int $orderId, int $adminId, int $amount): array
    {
        $order = $this->requireDispute($orderId);
        if (isset($order['error'])) {
            return $order;
        }

        $total = (int) $order['amount'];
        if ($amount < 1 || $amount >= $total) {
            return ['ok' => false, 'error' => t('escrow.partial_amount_invalid')];
        }

        $this->orders->updateFields($orderId, [
            'arbiter_id' => $adminId,
            'arbiter_decision' => 'partial_refund',
            'arbiter_at' => date('Y-m-d H:i:s'),
            'return_offer_amount' => $amount,
            'return_keep_item' => 1,
        ]);
        $this->events->add($orderId, 'arbiter_partial_refund', 'arbiter', $adminId, ['amount' => $amount]);

        return $this->escrow->settle($orderId, $amount, [
            'reactivate' => false,
            'revoke_digital' => false,
            'keep_item' => true,
            'status' => 'partial_refunded',
        ]);
    }

    /** Арбитр: одобрить физический возврат товара. */
    public function arbiterApproveShipment(int $orderId, int $adminId): array
    {
        $order = $this->requireDispute($orderId);
        if (isset($order['error'])) {
            return $order;
        }
        if ($this->isDigitalOrder($order) || ($order['return_reason'] ?? '') === self::REASON_NOT_RECEIVED) {
            return $this->arbiterFullRefund($orderId, $adminId);
        }

        $this->orders->updateFields($orderId, [
            'status' => 'return_approved',
            'arbiter_id' => $adminId,
            'arbiter_decision' => 'approve_return',
            'arbiter_at' => date('Y-m-d H:i:s'),
            'return_requires_shipment' => 1,
            'return_ship_until' => date('Y-m-d H:i:s', strtotime('+' . self::BUYER_SHIP_DAYS . ' days')),
        ]);
        $this->events->add($orderId, 'arbiter_approve_shipment', 'arbiter', $adminId);

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

    /** @return array{ok: bool, error?: string} */
    public function arbiterSellerFavor(int $orderId, int $adminId): array
    {
        $order = $this->requireDispute($orderId);
        if (isset($order['error'])) {
            return $order;
        }

        $this->orders->updateFields($orderId, [
            'arbiter_id' => $adminId,
            'arbiter_decision' => 'reject_dispute',
            'arbiter_at' => date('Y-m-d H:i:s'),
        ]);
        $this->events->add($orderId, 'arbiter_seller_favor', 'arbiter', $adminId);

        return $this->escrow->settle($orderId, 0, [
            'reactivate' => false,
            'revoke_digital' => false,
            'status' => 'completed',
        ]);
    }

    /**
     * @param list<string> $evidenceFiles
     * @return array{ok: bool, error?: string}
     */
    private function applyCourierVoid(array $order, string $comment, array $evidenceFiles): array
    {
        $orderId = (int) $order['id'];
        $this->orders->updateFields($orderId, [
            'return_reason' => self::REASON_COURIER_VOID,
            'dispute_reason' => $comment,
            'dispute_evidence' => $evidenceFiles ? json_encode($evidenceFiles, JSON_UNESCAPED_UNICODE) : null,
            'disputed_at' => date('Y-m-d H:i:s'),
            'return_requires_shipment' => 0,
            'return_shipping_payer' => 'none',
            'arbiter_decision' => 'courier_void',
            'arbiter_at' => date('Y-m-d H:i:s'),
        ]);
        $this->events->add($orderId, 'courier_void', 'buyer', (int) $order['buyer_id']);

        return $this->escrow->settle($orderId, (int) $order['amount'], [
            'reactivate' => true,
            'revoke_digital' => false,
            'status' => 'refunded',
        ]);
    }

    private function completePhysicalRefund(int $orderId, bool $auto): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }

        $result = $this->escrow->settle($orderId, (int) $order['amount'], [
            'reactivate' => true,
            'revoke_digital' => false,
            'status' => 'refunded',
        ]);
        if ($result['ok'] && $auto) {
            (new Notification())->createFor(
                (int) $order['buyer_id'],
                t('escrow.notify_auto_refund_return', ['id' => $orderId])
            );
            (new Notification())->createFor(
                (int) $order['seller_id'],
                t('escrow.notify_auto_refund_return_seller', ['id' => $orderId])
            );
        }
        return $result;
    }

    /**
     * @return array<string, mixed>|array{ok: bool, error: string}
     */
    private function requireDispute(int $orderId): array
    {
        $order = $this->orders->find($orderId);
        if (!$order || ($order['status'] ?? '') !== 'dispute') {
            return ['ok' => false, 'error' => t('escrow.bad_status')];
        }
        return $order;
    }

    private function escalateToArbitration(int $orderId, ?int $actorId, string $role, bool $auto): array
    {
        $order = $this->orders->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('escrow.not_found')];
        }
        if (($order['status'] ?? '') === 'dispute') {
            return ['ok' => true];
        }

        $this->orders->updateFields($orderId, [
            'status' => 'dispute',
        ]);
        $this->events->add(
            $orderId,
            $auto ? 'auto_escalated' : 'escalated',
            $auto ? 'system' : $role,
            $actorId
        );

        (new Notification())->createFor(
            (int) $order['buyer_id'],
            t('escrow.notify_escalated', ['id' => $orderId])
        );
        (new Notification())->createFor(
            (int) $order['seller_id'],
            t('escrow.notify_escalated_seller', ['id' => $orderId])
        );

        return ['ok' => true];
    }

    private function inspectWindowOpen(array $order): bool
    {
        $until = $order['inspect_until'] ?? null;
        if (!$until) {
            return true;
        }
        return strtotime((string) $until) > time();
    }

    private function isDigitalOrder(array $order): bool
    {
        return (($order['delivery_method'] ?? '') === 'digital');
    }

    private function requiresShipment(string $reason, bool $digital, bool $keepItem): bool
    {
        if ($keepItem || $digital) {
            return false;
        }
        if (in_array($reason, [self::REASON_NOT_RECEIVED, self::REASON_COURIER_VOID, self::REASON_DIGITAL_DEFECT], true)) {
            return false;
        }
        return true;
    }

    private function shippingPayerFor(string $reason, bool $digital): string
    {
        if ($digital || $reason === self::REASON_NOT_RECEIVED || $reason === self::REASON_COURIER_VOID) {
            return 'none';
        }
        if ($reason === self::REASON_CHANGED_MIND) {
            return 'buyer';
        }
        return 'seller';
    }
}
