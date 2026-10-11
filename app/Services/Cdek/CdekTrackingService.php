<?php

namespace App\Services\Cdek;

use App\Models\DeliveryOrder;
use App\Models\DeliveryPrintForm;

/**
 * Статус уже зарегистрированного отправления.
 * Источник: webhook ORDER_STATUS и GET /v2/orders/{uuid}.
 * Не создаёт CDEK order и не меняет оплату.
 */
class CdekTrackingService
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
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $printForms
     * @return array<string, mixed>
     */
    public static function summarize(array $row, string $audience, array $printForms = []): array
    {
        $audience = $audience === 'seller' ? 'seller' : 'buyer';
        $code = trim((string) ($row['cdek_status_code'] ?? ''));
        $known = $code !== '' && CdekStatusCatalog::isKnown($code);
        $deliveryStatus = (string) ($row['status'] ?? '');
        $carrierText = $known
            ? CdekStatusCatalog::text($code, $audience)
            : ($code !== ''
                ? CdekStatusCatalog::text($code, $audience)
                : CdekStatusCatalog::awaitingCarrierText($audience));
        $group = $known ? CdekStatusCatalog::group($code) : null;
        $completed = $group === CdekStatusCatalog::GROUP_DELIVERED
            || $deliveryStatus === DeliveryOrder::STATUS_DELIVERED;
        $eta = CdekTrackingPolicy::plannedDate(
            isset($row['planned_delivery_date']) ? (string) $row['planned_delivery_date'] : null
        );
        $readyPrints = [];
        foreach ($printForms as $form) {
            if (!is_array($form)) {
                continue;
            }
            if ((string) ($form['status'] ?? '') !== DeliveryPrintForm::STATUS_READY) {
                continue;
            }
            $readyPrints[] = CdekPrintFormService::toPublic($form, (int) ($row['id'] ?? 0));
        }

        $summary = [
            'delivery_order_id' => (int) ($row['id'] ?? 0),
            'purchase_status' => (string) ($row['purchase_status'] ?? ''),
            'delivery_status' => $deliveryStatus,
            'delivery_status_label' => $deliveryStatus !== '' ? DeliveryOrder::statusLabel($deliveryStatus) : '',
            'payment_status' => (string) ($row['payment_status'] ?? ''),
            'registration_status' => (string) ($row['cdek_api_status'] ?? ''),
            'carrier_status_label' => $carrierText,
            'carrier_known' => $known,
            'last_event_text' => $carrierText,
            'updated_at' => $row['last_carrier_event_at'] ?? ($row['updated_at'] ?? null),
            'eta' => $eta,
            'completed' => $completed,
            'print_forms_available' => $readyPrints !== [],
            'print_forms' => $readyPrints,
        ];
        if (!empty($row['_admin'])) {
            $summary['cdek_status_code'] = $code !== '' ? $code : null;
        }
        return $summary;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $printForms
     * @return array{ok: bool, summary?: array<string, mixed>, error?: string}
     */
    public static function summaryForUser(array $row, int $userId, bool $isAdmin, array $printForms = []): array
    {
        if (!CdekPrintFormService::canAccessDelivery($row, $userId, $isAdmin)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $audience = 'buyer';
        if (!$isAdmin && (int) ($row['seller_user_id'] ?? 0) === $userId && (int) ($row['buyer_user_id'] ?? 0) !== $userId) {
            $audience = 'seller';
        }
        if ($isAdmin) {
            $row['_admin'] = true;
        }
        return ['ok' => true, 'summary' => self::summarize($row, $audience, $printForms)];
    }

    /**
     * GET /v2/orders/{uuid} для уже сохранённого заказа. Не вызывает создание.
     *
     * @param array<string, mixed> $row
     * @return array{ok: bool, changed?: bool, error?: string, status?: string|null, skipped?: string}
     */
    public function refreshOne(array $row): array
    {
        $uuid = trim((string) ($row['logistics_order_id'] ?? $row['cdek_uuid'] ?? ''));
        $deliveryOrderId = (int) ($row['id'] ?? 0);
        if ($uuid === '' || $deliveryOrderId <= 0) {
            return ['ok' => false, 'error' => 'missing_uuid'];
        }

        if (method_exists($this->orders, 'claimTrackingPoll')) {
            if (!$this->orders->claimTrackingPoll($deliveryOrderId)) {
                return ['ok' => true, 'changed' => false, 'skipped' => 'locked'];
            }
        }

        $remote = $this->orderService->getByUuid($uuid);
        $decision = CdekTrackingPolicy::interpretGet($remote, $uuid);
        if (!$decision['ok']) {
            $this->recordFailure($row, (string) ($decision['error_class'] ?? 'error'), (string) ($decision['error'] ?? 'get_failed'));
            return ['ok' => false, 'error' => $decision['error'] ?? 'get_failed'];
        }

        $fields = [
            'last_synced_at' => date('Y-m-d H:i:s'),
            'last_error_code' => null,
            'last_error_message' => null,
            'tracking_poll_attempts' => 0,
        ];
        if (!empty($decision['cdek_number'])) {
            $fields['cdek_number'] = $decision['cdek_number'];
        }
        if (!empty($decision['planned_delivery_date'])) {
            $fields['planned_delivery_date'] = $decision['planned_delivery_date'];
        }
        if (!empty($decision['registration_only'])) {
            $currentApi = (string) ($row['cdek_api_status'] ?? '');
            if (in_array($currentApi, ['', 'none', 'pending'], true)) {
                $fields['cdek_api_status'] = 'accepted';
            }
        }
        $this->orders->updateFields($deliveryOrderId, $fields);

        $changed = false;
        $status = (string) ($row['status'] ?? '');
        if (!empty($decision['apply']) && is_array($decision['parsed'] ?? null)) {
            $parsed = $decision['parsed'];
            $parsed['delivery_order_id'] = $deliveryOrderId;
            $apply = $this->webhookService->applyParsed($parsed);
            if (!$apply['ok']) {
                return ['ok' => false, 'error' => $apply['error'] ?? 'apply_failed'];
            }
            $changed = !empty($apply['changed']);
            if (!empty($apply['status'])) {
                $status = (string) $apply['status'];
            }
            if (method_exists($this->orders, 'logEvent')) {
                $this->orders->logEvent(
                    $deliveryOrderId,
                    null,
                    'system',
                    'cdek_reconciliation',
                    null,
                    $status !== '' ? $status : null,
                    [
                        'cdek_uuid' => $uuid,
                        'remote_status' => $parsed['raw_code'] ?? null,
                        'changed' => $changed,
                    ]
                );
            }
        }

        $nextStatus = $status !== '' ? $status : (string) ($row['status'] ?? '');
        if (method_exists($this->orders, 'scheduleTrackingPoll')) {
            $this->orders->scheduleTrackingPoll(
                $deliveryOrderId,
                CdekTrackingPolicy::successInterval($nextStatus)
            );
        }

        if ($this->orders instanceof DeliveryOrder && is_array($remote['raw'] ?? null)) {
            try {
                (new CdekDeliveryProblemService(null, $this->orders))->ingestFromEntity($deliveryOrderId, $remote['raw']);
            } catch (\Throwable) {
                // Проблема доставки не должна ломать сверку статуса и не запускает возврат.
            }
        }

        return [
            'ok' => true,
            'changed' => $changed,
            'status' => $status !== '' ? $status : null,
        ];
    }

    /**
     * @return array{ok: bool, checked: int, updated: int, errors: list<string>}
     */
    public function pollDue(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $rows = method_exists($this->orders, 'findCdekDueForTracking')
            ? $this->orders->findCdekDueForTracking($limit)
            : [];
        $checked = 0;
        $updated = 0;
        $errors = [];
        foreach ($rows as $row) {
            $checked++;
            $result = $this->refreshOne($row);
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
     * @param array<string, mixed> $row
     */
    private function recordFailure(array $row, string $errorClass, string $error): void
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $attempts = (int) ($row['tracking_poll_attempts'] ?? 0) + 1;
        $this->orders->updateFields($id, [
            'last_synced_at' => date('Y-m-d H:i:s'),
            'last_error_code' => $errorClass,
            'last_error_message' => mb_substr($error, 0, 250),
            'tracking_poll_attempts' => $attempts,
        ]);
        if (method_exists($this->orders, 'scheduleTrackingPoll')) {
            $this->orders->scheduleTrackingPoll($id, CdekTrackingPolicy::backoffSeconds($errorClass, $attempts));
        }
    }
}
