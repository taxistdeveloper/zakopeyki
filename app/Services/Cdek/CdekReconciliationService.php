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
        $rows = method_exists($this->orders, 'findCdekDueForTracking')
            ? $this->orders->findCdekDueForTracking($limit)
            : $this->orders->findCdekOpenForReconciliation($limit);
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
        $tracking = new CdekTrackingService($this->orderService, $this->webhookService, $this->orders);
        return $tracking->refreshOne($row);
    }
}
