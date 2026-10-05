<?php

namespace App\Services\Delivery;

use App\Models\DeliveryOrder;

/**
 * Разрешённые переходы внутреннего delivery FSM + cdek_api_status.
 * Не смешивает P2P orders.status и delivery_orders.status.
 */
final class DeliveryStatusMachine
{
    public const API_NONE = 'none';
    public const API_PENDING = 'pending';
    public const API_ACCEPTED = 'accepted';
    public const API_CREATED = 'created';
    public const API_FAILED = 'failed';

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        DeliveryOrder::STATUS_DATA_COLLECTION => [
            DeliveryOrder::STATUS_DATA_COMPLETE,
            DeliveryOrder::STATUS_CANCELLED,
        ],
        DeliveryOrder::STATUS_DATA_COMPLETE => [
            DeliveryOrder::STATUS_QUOTE_REQUESTED,
            DeliveryOrder::STATUS_DATA_COLLECTION,
            DeliveryOrder::STATUS_CANCELLED,
        ],
        DeliveryOrder::STATUS_QUOTE_REQUESTED => [
            DeliveryOrder::STATUS_QUOTE_RECEIVED,
            DeliveryOrder::STATUS_DATA_COMPLETE,
            DeliveryOrder::STATUS_EXCEPTION,
            DeliveryOrder::STATUS_CANCELLED,
        ],
        DeliveryOrder::STATUS_QUOTE_RECEIVED => [
            DeliveryOrder::STATUS_READY_FOR_PAYMENT,
            DeliveryOrder::STATUS_QUOTE_REQUESTED,
            DeliveryOrder::STATUS_DATA_COMPLETE,
            DeliveryOrder::STATUS_CANCELLED,
        ],
        DeliveryOrder::STATUS_READY_FOR_PAYMENT => [
            DeliveryOrder::STATUS_PAYMENT_PENDING,
            DeliveryOrder::STATUS_QUOTE_RECEIVED,
            DeliveryOrder::STATUS_DATA_COMPLETE,
            DeliveryOrder::STATUS_CANCELLED,
        ],
        DeliveryOrder::STATUS_PAYMENT_PENDING => [
            DeliveryOrder::STATUS_PAID,
            DeliveryOrder::STATUS_READY_FOR_PAYMENT,
            DeliveryOrder::STATUS_CANCELLED,
        ],
        DeliveryOrder::STATUS_PAID => [
            DeliveryOrder::STATUS_CDEK_ORDER_PENDING,
            DeliveryOrder::STATUS_ORDER_CREATED,
            DeliveryOrder::STATUS_CDEK_ORDER_FAILED,
            DeliveryOrder::STATUS_REFUND_REQUIRED,
            DeliveryOrder::STATUS_EXCEPTION,
        ],
        DeliveryOrder::STATUS_CDEK_ORDER_PENDING => [
            DeliveryOrder::STATUS_ORDER_CREATED,
            DeliveryOrder::STATUS_ACCEPTED,
            DeliveryOrder::STATUS_CDEK_ORDER_FAILED,
            DeliveryOrder::STATUS_REFUND_REQUIRED,
            DeliveryOrder::STATUS_EXCEPTION,
        ],
        DeliveryOrder::STATUS_ORDER_CREATED => [
            DeliveryOrder::STATUS_ACCEPTED,
            DeliveryOrder::STATUS_SHIPMENT_RECEIVED,
            DeliveryOrder::STATUS_IN_TRANSIT,
            DeliveryOrder::STATUS_EXCEPTION,
            DeliveryOrder::STATUS_CANCELLED,
        ],
        DeliveryOrder::STATUS_ACCEPTED => [
            DeliveryOrder::STATUS_SHIPMENT_RECEIVED,
            DeliveryOrder::STATUS_IN_TRANSIT,
            DeliveryOrder::STATUS_DELIVERED,
            DeliveryOrder::STATUS_EXCEPTION,
            DeliveryOrder::STATUS_CANCELLED,
        ],
        DeliveryOrder::STATUS_SHIPMENT_RECEIVED => [
            DeliveryOrder::STATUS_IN_TRANSIT,
            DeliveryOrder::STATUS_DELIVERED,
            DeliveryOrder::STATUS_EXCEPTION,
            DeliveryOrder::STATUS_CANCELLED,
        ],
        DeliveryOrder::STATUS_IN_TRANSIT => [
            DeliveryOrder::STATUS_DELIVERED,
            DeliveryOrder::STATUS_EXCEPTION,
            DeliveryOrder::STATUS_CANCELLED,
        ],
        DeliveryOrder::STATUS_DELIVERED => [
            // terminal happy path
        ],
        DeliveryOrder::STATUS_CDEK_ORDER_FAILED => [
            DeliveryOrder::STATUS_CDEK_ORDER_PENDING,
            DeliveryOrder::STATUS_REFUND_REQUIRED,
            DeliveryOrder::STATUS_EXCEPTION,
            DeliveryOrder::STATUS_CANCELLED,
        ],
        DeliveryOrder::STATUS_REFUND_REQUIRED => [
            DeliveryOrder::STATUS_CANCELLED,
            DeliveryOrder::STATUS_EXCEPTION,
        ],
        DeliveryOrder::STATUS_EXCEPTION => [
            DeliveryOrder::STATUS_CDEK_ORDER_PENDING,
            DeliveryOrder::STATUS_IN_TRANSIT,
            DeliveryOrder::STATUS_REFUND_REQUIRED,
            DeliveryOrder::STATUS_CANCELLED,
        ],
        DeliveryOrder::STATUS_CANCELLED => [
            // terminal
        ],
    ];

    /** @var array<string, int> */
    private const RANK = [
        DeliveryOrder::STATUS_DATA_COLLECTION => 10,
        DeliveryOrder::STATUS_DATA_COMPLETE => 20,
        DeliveryOrder::STATUS_QUOTE_REQUESTED => 30,
        DeliveryOrder::STATUS_QUOTE_RECEIVED => 40,
        DeliveryOrder::STATUS_READY_FOR_PAYMENT => 50,
        DeliveryOrder::STATUS_PAYMENT_PENDING => 60,
        DeliveryOrder::STATUS_PAID => 70,
        DeliveryOrder::STATUS_CDEK_ORDER_PENDING => 75,
        DeliveryOrder::STATUS_ORDER_CREATED => 80,
        DeliveryOrder::STATUS_ACCEPTED => 90,
        DeliveryOrder::STATUS_SHIPMENT_RECEIVED => 100,
        DeliveryOrder::STATUS_IN_TRANSIT => 110,
        DeliveryOrder::STATUS_DELIVERED => 200,
        DeliveryOrder::STATUS_CDEK_ORDER_FAILED => 65,
        DeliveryOrder::STATUS_REFUND_REQUIRED => 66,
        DeliveryOrder::STATUS_EXCEPTION => 150,
        DeliveryOrder::STATUS_CANCELLED => 210,
    ];

    public function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }
        if ($from === DeliveryOrder::STATUS_DELIVERED) {
            return false;
        }
        $allowed = self::TRANSITIONS[$from] ?? null;
        if ($allowed === null) {
            return false;
        }
        return in_array($to, $allowed, true);
    }

    /**
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public function assertTransition(string $from, string $to): array
    {
        if ($this->canTransition($from, $to)) {
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => 'invalid_status_transition'];
    }

    public function rank(string $status): int
    {
        return self::RANK[$status] ?? 0;
    }

    /**
     * Монотонный прогресс логистики (webhook): не откатываем DELIVERED и не идём назад по rank
     * для «happy» статусов. EXCEPTION/CANCELLED обрабатываются отдельно.
     */
    public function canApplyCarrierProgress(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }
        if ($from === DeliveryOrder::STATUS_DELIVERED) {
            return false;
        }
        if (in_array($to, [DeliveryOrder::STATUS_EXCEPTION, DeliveryOrder::STATUS_CANCELLED], true)) {
            return $from !== DeliveryOrder::STATUS_DELIVERED;
        }
        if (!$this->canTransition($from, $to) && $this->rank($to) <= $this->rank($from)) {
            return false;
        }
        // Allow skip-ahead if rank increases and destination is a known logistics status.
        if ($this->rank($to) > $this->rank($from) && isset(self::RANK[$to])) {
            return true;
        }
        return $this->canTransition($from, $to);
    }

    public function isCreateAllowed(string $status, string $cdekApiStatus, ?string $cdekUuid): bool
    {
        if ($cdekUuid !== null && $cdekUuid !== '') {
            return false; // already created — idempotent skip
        }
        if (!in_array($status, [
            DeliveryOrder::STATUS_PAID,
            DeliveryOrder::STATUS_CDEK_ORDER_PENDING,
            DeliveryOrder::STATUS_CDEK_ORDER_FAILED,
            DeliveryOrder::STATUS_ORDER_CREATED,
        ], true)) {
            return false;
        }
        if ($cdekApiStatus === self::API_CREATED || $cdekApiStatus === self::API_ACCEPTED) {
            return false;
        }
        return true;
    }

    /** @return list<string> */
    public function allStatuses(): array
    {
        return array_keys(self::RANK);
    }
}
