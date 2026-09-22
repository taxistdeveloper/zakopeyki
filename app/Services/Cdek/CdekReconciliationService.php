<?php

namespace App\Services\Cdek;

use App\Models\DeliveryOrder;

/**
 * Reconciliation: webhook не единственный источник истины.
 *
 * local shipment → CDEK GET /orders/{uuid} → compare status → repair divergence.
 *
 * @see openapi_api_v2_integration.json GET /v2/orders/{uuid}
 */
class CdekReconciliationService
{
    private CdekOrderService $orderService;
    private CdekWebhookService $webhookService;
    /** @var DeliveryOrder|object */
    private object $orders;

    public function __construct(
        ?CdekOrderService $orderService = null,
        ?CdekWebhookService $webhookService = null,
        ?object $orders = null
    ) {
        $this->orders = $orders ?? new DeliveryOrder();
        $this->orderService = $orderService ?? new CdekOrderService();
        $this->webhookService = $webhookService ?? new CdekWebhookService(null, null, $this->orders);
    }

    /**
     * Статусы, которые ещё «в пути» и нуждаются в сверке.
     *
     * @return list<string>
     */
    public static function openStatuses(): array
    {
        return [
            DeliveryOrder::STATUS_PAID,
            DeliveryOrder::STATUS_ORDER_CREATED,
            DeliveryOrder::STATUS_ACCEPTED,
            DeliveryOrder::STATUS_SHIPMENT_RECEIVED,
            DeliveryOrder::STATUS_IN_TRANSIT,
            DeliveryOrder::STATUS_EXCEPTION,
        ];
    }

    /**
     * @return array{ok: bool, checked: int, updated: int, errors: list<string>}
     */
    public function reconcileOpen(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $this->orders->findCdekOpenForReconciliation($limit);
        $checked = 0;
        $updated = 0;
        $errors = [];

        foreach ($rows as $row) {
            $checked++;
            $result = $this->reconcileOne($row);
            if (!$result['ok']) {
                $errors[] = 'id=' . ($row['id'] ?? '?') . ': ' . ($result['error'] ?? 'fail');
                continue;
            }
            if (!empty($result['changed'])) {
                $updated++;
            }
        }

        return [
            'ok' => $errors === [],
            'checked' => $checked,
            'updated' => $updated,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $row delivery_orders row (+ logistics_order_id)
     * @return array{ok: bool, changed?: bool, error?: string, status?: string|null}
     */
    public function reconcileOne(array $row): array
    {
        $uuid = trim((string) ($row['logistics_order_id'] ?? ''));
        $deliveryOrderId = (int) ($row['id'] ?? 0);
        if ($uuid === '' || $deliveryOrderId <= 0) {
            return ['ok' => false, 'error' => 'missing_uuid'];
        }

        $remote = $this->orderService->getByUuid($uuid);
        if (!$remote['ok']) {
            return ['ok' => false, 'error' => $remote['error'] ?? 'get_failed'];
        }

        $statuses = is_array($remote['statuses'] ?? null) ? $remote['statuses'] : [];
        $latestCode = '';
        if ($statuses !== []) {
            $latest = $this->pickLatestStatus($statuses);
            $latestCode = (string) ($latest['code'] ?? '');
        }

        $cdekNumber = $remote['cdek_number'] ?? null;
        $mapped = $this->mapRemoteStatus($latestCode);

        // Если локально ещё ORDER_CREATED / PAID, а uuid есть — хотя бы ACCEPTED.
        if ($mapped === null && in_array((string) ($row['status'] ?? ''), [
            DeliveryOrder::STATUS_PAID,
            DeliveryOrder::STATUS_ORDER_CREATED,
        ], true) && $uuid !== '') {
            $mapped = 'ACCEPTED';
        }

        if ($mapped === null && ($cdekNumber === null || $cdekNumber === '')) {
            return ['ok' => true, 'changed' => false, 'status' => null];
        }

        $parsed = [
            'delivery_order_id' => $deliveryOrderId,
            'status' => $mapped ?? 'ACCEPTED',
            'tracking_number' => $cdekNumber,
            'message' => 'reconciliation:' . ($latestCode !== '' ? $latestCode : 'poll'),
            'location' => null,
        ];

        $apply = $this->webhookService->applyParsed($parsed);
        if (!$apply['ok']) {
            return ['ok' => false, 'error' => $apply['error'] ?? 'apply_failed'];
        }

        $this->orders->logEvent(
            $deliveryOrderId,
            null,
            'system',
            'cdek_reconciliation',
            null,
            $apply['status'] ?? null,
            [
                'cdek_uuid' => $uuid,
                'remote_status' => $latestCode,
                'cdek_number' => $cdekNumber,
                'changed' => !empty($apply['changed']),
            ]
        );

        return [
            'ok' => true,
            'changed' => !empty($apply['changed']),
            'status' => $apply['status'] ?? null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $statuses
     * @return array<string, mixed>
     */
    private function pickLatestStatus(array $statuses): array
    {
        $best = $statuses[0] ?? [];
        $bestTs = 0;
        foreach ($statuses as $s) {
            if (!is_array($s)) {
                continue;
            }
            $ts = strtotime((string) ($s['date_time'] ?? $s['date'] ?? '')) ?: 0;
            if ($ts >= $bestTs) {
                $bestTs = $ts;
                $best = $s;
            }
        }
        return is_array($best) ? $best : [];
    }

    private function mapRemoteStatus(string $code): ?string
    {
        $code = strtoupper(trim($code));
        return match ($code) {
            'ACCEPTED', 'CREATED', 'RECEIVED_AT_SHIPMENT_WAREHOUSE',
            'READY_FOR_SHIPMENT_IN_SENDER_CITY', 'READY_FOR_SHIPMENT_IN_TRANSIT_CITY',
            'PASSED_TO_CARRIER_AT_SENDER_CITY' => 'ACCEPTED',
            'TAKEN_BY_TRANSPORTER_FROM_SENDER_CITY', 'SENT_TO_RECIPIENT_CITY',
            'ACCEPTED_AT_RECIPIENT_CITY_WAREHOUSE', 'ACCEPTED_AT_TRANSIT_WAREHOUSE',
            'TAKEN_BY_COURIER', 'RECEIVED_AT_SENDER_WAREHOUSE',
            'RETURNED_TO_SENDER_CITY' => 'IN_TRANSIT',
            'READY_FOR_PICKUP', 'ACCEPTED_AT_PICK_UP_POINT',
            'POSTOMAT_POSTED', 'POSTOMAT_SEIZED' => 'READY_FOR_PICKUP',
            'DELIVERED', 'POSTOMAT_RECEIVED' => 'DELIVERED',
            'NOT_DELIVERED', 'INVALID', 'REMOVED_FROM_PICKUP_POINT' => 'EXCEPTION',
            default => $code !== '' ? null : null,
        };
    }
}
