<?php

namespace App\Services\Cdek;

use App\Models\DeliveryOrder;
use App\Models\DeliveryWebhookEvent;
use App\Services\Delivery\CdekLogisticsProvider;

/**
 * Обработка входящих CDEK webhooks (ORDER_STATUS и др.).
 *
 * Auth: shared secret (OpenAPI WebhookDto не определяет HMAC-подпись).
 *   - query ?token=...
 *   - или заголовок X-Cdek-Webhook-Token
 *
 * Идемпотентность: UNIQUE event_hash в delivery_webhook_events.
 * Один и тот же webhook не должен дважды менять состояние.
 *
 * Не доверяем payload.delivery_order_id (IDOR) — только im_number / cdek uuid.
 *
 * @see openapi_api_v2_integration.json WebhookDto
 */
class CdekWebhookService
{
    private array $config;
    /** @var DeliveryWebhookEvent|object */
    private object $events;
    /** @var DeliveryOrder|object */
    private object $orders;
    /** @var CdekLogisticsProvider|object */
    private object $provider;
    /** @var CdekTrackingNotifier|object|null */
    private ?object $notifier;

    public function __construct(
        ?array $config = null,
        ?object $events = null,
        ?object $orders = null,
        ?object $provider = null,
        ?object $notifier = null
    ) {
        if ($config !== null) {
            $this->config = $config;
        } else {
            $path = dirname(__DIR__, 3) . '/config/cdek.php';
            $this->config = is_file($path) ? (require $path) : [];
        }
        $this->events = $events ?? new DeliveryWebhookEvent();
        $this->orders = $orders ?? new DeliveryOrder();
        $this->provider = $provider ?? new CdekLogisticsProvider(null, $this->orders instanceof DeliveryOrder ? $this->orders : null);
        $this->notifier = $notifier;
    }

    /**
     * Проверка shared webhook token.
     * В test_mode без настроенного token — допускаем (с audit-флагом), в prod — требуем.
     *
     * @param array<string, string> $headers normalized lower-case header map
     * @param array<string, mixed> $query
     * @return array{ok: bool, error?: string, mode?: string}
     */
    public function authorize(array $headers, array $query): array
    {
        $expected = trim((string) ($this->config['webhook_token'] ?? ''));
        $testMode = (int) ($this->config['test_mode'] ?? 1) === 1;

        if ($expected === '') {
            if ($testMode) {
                return ['ok' => true, 'mode' => 'test_open'];
            }
            return ['ok' => false, 'error' => 'webhook_token_not_configured'];
        }

        $provided = trim((string) ($query['token'] ?? ''));
        if ($provided === '') {
            $provided = trim((string) ($headers['x-cdek-webhook-token'] ?? $headers['x-webhook-token'] ?? ''));
        }

        if ($provided === '' || !hash_equals($expected, $provided)) {
            return ['ok' => false, 'error' => 'unauthorized'];
        }

        return ['ok' => true, 'mode' => 'token'];
    }

    /**
     * @param array<string, mixed> $payload
     * @param string $rawBody для hash (без логирования секретов)
     * @return array{
     *   ok: bool,
     *   duplicate?: bool,
     *   delivery_order_id?: int,
     *   status?: string|null,
     *   error?: string,
     *   event_id?: int
     * }
     */
    public function handle(array $payload, string $rawBody = ''): array
    {
        $type = (string) ($payload['type'] ?? '');
        $attrs = $payload['attributes'] ?? [];
        if (!is_array($attrs)) {
            $attrs = [];
        }

        $eventUuid = (string) ($payload['uuid'] ?? '');
        $orderUuid = (string) ($attrs['uuid'] ?? $payload['attributes']['order_uuid'] ?? '');
        // CDEK ORDER_STATUS: attributes часто содержат cdek uuid заказа иначе.
        if ($orderUuid === '' && !empty($attrs['cdek_number'])) {
            // оставим пустым — резолв по number
        }
        $statusCode = (string) ($attrs['code'] ?? $attrs['status_code'] ?? '');
        $statusAt = (string) ($attrs['status_date_time'] ?? $payload['date_time'] ?? '');

        $eventHash = $this->buildEventHash($type, $eventUuid, $orderUuid, $statusCode, $statusAt, $attrs);
        $payloadHash = $rawBody !== ''
            ? hash('sha256', $rawBody)
            : hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));

        $insert = $this->events->tryInsert([
            'provider' => 'cdek',
            'event_type' => $type !== '' ? $type : null,
            'event_uuid' => $eventUuid !== '' ? $eventUuid : null,
            'cdek_order_uuid' => $orderUuid !== '' ? $orderUuid : null,
            'status_code' => $statusCode !== '' ? $statusCode : null,
            'event_hash' => $eventHash,
            'payload_hash' => $payloadHash,
        ]);

        if (!$insert['inserted']) {
            return [
                'ok' => true,
                'duplicate' => true,
                'error' => null,
            ];
        }

        $eventId = (int) ($insert['id'] ?? 0);

        if ($type === 'PRINT_FORM') {
            try {
                $applied = (new CdekPrintFormService())->applyWebhook($payload);
                $this->events->markProcessed(
                    $eventId,
                    ($applied['ok'] ?? false) ? 'applied' : 'ignored',
                    isset($applied['delivery_order_id']) ? (int) $applied['delivery_order_id'] : null,
                    $applied['error'] ?? null
                );
            } catch (\Throwable $e) {
                $this->events->markProcessed($eventId, 'error', null, 'print_form_failed');
            }
            return ['ok' => true, 'event_id' => $eventId, 'status' => null];
        }

        if ($type === 'DELIV_PROBLEM') {
            try {
                $applied = (new CdekDeliveryProblemService(null, $this->orders))->applyWebhook($payload);
                $this->events->markProcessed(
                    $eventId,
                    ($applied['ok'] ?? false) ? 'applied' : 'ignored',
                    isset($applied['delivery_order_id']) ? (int) $applied['delivery_order_id'] : null,
                    $applied['error'] ?? null
                );
            } catch (\Throwable $e) {
                $this->events->markProcessed($eventId, 'error', null, 'delivery_problem_failed');
            }
            return ['ok' => true, 'event_id' => $eventId, 'status' => null];
        }

        // Неизвестные типы — фиксируем, но не падаем.
        if ($type !== '' && $type !== 'ORDER_STATUS') {
            $this->events->markProcessed($eventId, 'ignored');
            return ['ok' => true, 'event_id' => $eventId, 'status' => null];
        }

        $parsed = $this->provider->handleStatusWebhook($payload);
        if ($parsed === null) {
            $this->events->markProcessed($eventId, 'ignored', null, 'unresolved_order');
            return ['ok' => false, 'error' => 'unresolved_order', 'event_id' => $eventId];
        }

        $deliveryOrderId = (int) $parsed['delivery_order_id'];
        $apply = $this->applyParsed($parsed);
        if (!$apply['ok']) {
            $this->events->markProcessed($eventId, 'error', $deliveryOrderId, $apply['error'] ?? 'apply_failed');
            return $apply + ['event_id' => $eventId];
        }

        $this->events->markProcessed($eventId, 'applied', $deliveryOrderId);
        return $apply + ['event_id' => $eventId, 'duplicate' => false];
    }

    /**
     * Применить уже распарсенный статус (webhook или reconciliation).
     *
     * @param array{delivery_order_id: int, status: string, tracking_number?: string|null, message?: mixed, location?: mixed} $parsed
     * @return array{ok: bool, delivery_order_id?: int, status?: string|null, error?: string, changed?: bool}
     */
    public function applyParsed(array $parsed): array
    {
        $deliveryOrderId = (int) ($parsed['delivery_order_id'] ?? 0);
        $row = $this->orders->find($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => 'delivery_not_found'];
        }

        $mappedKey = (string) ($parsed['status'] ?? '');
        $newStatus = CdekStatusCatalog::fsmForGroup($mappedKey);
        $rawCode = strtoupper(trim((string) ($parsed['raw_code'] ?? '')));
        $eventAt = CdekTrackingPolicy::normalizeEventAt(
            isset($parsed['event_at']) ? (string) $parsed['event_at'] : null
        );
        $stale = CdekTrackingPolicy::isStaleEvent($eventAt, isset($row['last_carrier_event_at']) ? (string) $row['last_carrier_event_at'] : null);
        $carrierStatus = $rawCode !== '' ? $rawCode : $mappedKey;
        $changed = false;
        $statusApplied = false;

        if ($stale) {
            $this->rememberTracking($deliveryOrderId, $parsed, $carrierStatus, $eventAt);
            return [
                'ok' => true,
                'delivery_order_id' => $deliveryOrderId,
                'status' => (string) ($row['status'] ?? ''),
                'changed' => false,
                'stale' => true,
            ];
        }

        if ($newStatus && ($row['status'] ?? '') !== $newStatus && $this->isRegression((string) ($row['status'] ?? ''), $newStatus)) {
            return [
                'ok' => true,
                'delivery_order_id' => $deliveryOrderId,
                'status' => (string) ($row['status'] ?? ''),
                'changed' => false,
                'stale' => false,
            ];
        }

        if ($this->rememberTracking($deliveryOrderId, $parsed, $carrierStatus, $eventAt)) {
            $changed = true;
        }

        if ($newStatus && ($row['status'] ?? '') !== $newStatus) {
            $transitioned = $this->orders->transitionStatus($deliveryOrderId, $newStatus, null, 'logistics', 'webhook_status');
            $statusApplied = $transitioned !== false;
            if ($statusApplied) {
                $changed = true;
            }
        }

        if ($statusApplied || $newStatus === null || ($row['status'] ?? '') === $newStatus) {
            $fields = [];
            if ($rawCode !== '') {
                $fields['cdek_status_code'] = $rawCode;
            }
            if ($eventAt !== null) {
                $fields['last_carrier_event_at'] = $eventAt;
            }
            $planned = CdekTrackingPolicy::plannedDate(
                isset($parsed['planned_delivery_date']) ? (string) $parsed['planned_delivery_date'] : null
            );
            if ($planned !== null) {
                $fields['planned_delivery_date'] = $planned;
            }
            if ($statusApplied && $newStatus === DeliveryOrder::STATUS_DELIVERED) {
                $fields['delivered_at'] = date('Y-m-d H:i:s');
            }
            if ($fields !== []) {
                $this->orders->updateFields($deliveryOrderId, $fields);
            }
            if ($rawCode !== '' && !CdekStatusCatalog::isKnown($rawCode) && method_exists($this->orders, 'logEvent')) {
                $this->orders->logEvent(
                    $deliveryOrderId,
                    null,
                    'system',
                    'cdek_status_gap',
                    null,
                    null,
                    ['cdek_status_code' => $rawCode]
                );
            }
            if ($rawCode !== '' && CdekStatusCatalog::needsGet($rawCode) && method_exists($this->orders, 'scheduleTrackingPoll')) {
                $this->orders->scheduleTrackingPoll($deliveryOrderId, CdekTrackingPolicy::MIN_OPEN_SECONDS);
            }
            $kind = $rawCode !== '' ? CdekStatusCatalog::noticeKind($rawCode) : null;
            $buyerId = (int) ($row['buyer_user_id'] ?? 0);
            $sellerId = (int) ($row['seller_user_id'] ?? 0);
            if ($kind !== null && ($this->notifier !== null || $buyerId > 0 || $sellerId > 0)) {
                $notifier = $this->notifier ?? new CdekTrackingNotifier();
                $notifier->notify($row, $rawCode);
            }
        }

        return [
            'ok' => true,
            'delivery_order_id' => $deliveryOrderId,
            'status' => $statusApplied ? $newStatus : (string) ($row['status'] ?? ''),
            'changed' => $changed,
            'stale' => false,
        ];
    }

    /**
     * @param array<string, mixed> $attrs
     */
    public function buildEventHash(
        string $type,
        string $eventUuid,
        string $orderUuid,
        string $statusCode,
        string $statusAt,
        array $attrs = []
    ): string {
        $number = (string) ($attrs['number'] ?? '');
        $cdekNumber = (string) ($attrs['cdek_number'] ?? '');
        $material = implode('|', [
            $type,
            $eventUuid,
            $orderUuid,
            $statusCode,
            $statusAt,
            $number,
            $cdekNumber,
        ]);
        return hash('sha256', $material);
    }

    /** @param array<string, mixed> $parsed */
    private function rememberTracking(int $deliveryOrderId, array $parsed, string $carrierStatus, ?string $eventAt): bool
    {
        if (empty($parsed['tracking_number']) && empty($parsed['message']) && $carrierStatus === '') {
            return false;
        }
        if ($this->isDuplicateTracking($deliveryOrderId, $parsed, $carrierStatus)) {
            return false;
        }
        $this->orders->addTrackingEvent($deliveryOrderId, [
            'tracking_number' => $parsed['tracking_number'] ?? null,
            'carrier_status' => $carrierStatus !== '' ? $carrierStatus : null,
            'carrier_message' => is_scalar($parsed['message'] ?? null) ? (string) $parsed['message'] : null,
            'location' => is_scalar($parsed['location'] ?? null) ? (string) $parsed['location'] : null,
            'event_at' => $eventAt ?? date('Y-m-d H:i:s'),
            'source' => (string) ($parsed['source'] ?? 'logistics'),
        ]);
        return true;
    }

    /** @param array<string, mixed> $parsed */
    private function isDuplicateTracking(int $deliveryOrderId, array $parsed, string $carrierStatus): bool
    {
        $tracking = $this->orders->trackingFor($deliveryOrderId);
        if ($tracking === []) {
            return false;
        }
        $last = $tracking[0] ?? null;
        if (!is_array($last)) {
            return false;
        }
        return (string) ($last['carrier_status'] ?? '') === $carrierStatus
            && (string) ($last['tracking_number'] ?? '') === (string) ($parsed['tracking_number'] ?? '')
            && (string) ($last['carrier_message'] ?? '') === (string) (is_scalar($parsed['message'] ?? null) ? $parsed['message'] : '');
    }

    private function isRegression(string $from, string $to): bool
    {
        $rank = [
            DeliveryOrder::STATUS_DATA_COLLECTION => 0,
            DeliveryOrder::STATUS_ORDER_CREATED => 10,
            DeliveryOrder::STATUS_ACCEPTED => 20,
            DeliveryOrder::STATUS_SHIPMENT_RECEIVED => 30,
            DeliveryOrder::STATUS_IN_TRANSIT => 40,
            DeliveryOrder::STATUS_DELIVERED => 50,
            DeliveryOrder::STATUS_EXCEPTION => 45,
        ];
        $a = $rank[$from] ?? 0;
        $b = $rank[$to] ?? 0;
        // DELIVERED — терминальный; EXCEPTION можно ставить с IN_TRANSIT, но не откатывать DELIVERED.
        if ($from === DeliveryOrder::STATUS_DELIVERED && $to !== DeliveryOrder::STATUS_DELIVERED) {
            return true;
        }
        return $b < $a && $to !== DeliveryOrder::STATUS_EXCEPTION;
    }
}
