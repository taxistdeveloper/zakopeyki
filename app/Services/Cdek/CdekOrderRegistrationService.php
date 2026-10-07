<?php

namespace App\Services\Cdek;

use App\Models\DeliveryOrder;
use App\Models\DeliveryPayment;
use App\Services\Delivery\DeliveryModelService;
use App\Services\Delivery\DeliveryPaymentService;
use App\Services\Delivery\DeliveryPii;
use App\Services\Delivery\DeliveryStatusMachine;

/**
 * Phase 8 — регистрация CDEK-заказа только после PAID.
 *
 * Схема: canCreateCdekOrder → POST /v2/orders → 202 ACCEPTED → UUID → poll GET → SUCCESSFUL|INVALID.
 * Webhooks — следующий этап.
 *
 * @see docs/cdek-order-registration.md
 */
class CdekOrderRegistrationService
{
    /** Рекомендация CDEK: первый GET через 2–3 с; cron может позже. */
    public const FIRST_POLL_DELAY_SEC = 3;
    public const MAX_POLL_ATTEMPTS = 20;

    private DeliveryOrder $orders;
    private DeliveryPaymentService $payments;
    private CdekOrderService $orderService;

    public function __construct(
        ?DeliveryOrder $orders = null,
        ?DeliveryPaymentService $payments = null,
        ?CdekOrderService $orderService = null
    ) {
        $this->orders = $orders ?? new DeliveryOrder();
        $this->payments = $payments ?? new DeliveryPaymentService($this->orders);
        $this->orderService = $orderService ?? new CdekOrderService();
    }

    /**
     * Явная регистрация (buyer). Frontend не передаёт payload CDEK.
     *
     * @return array{
     *   ok: bool,
     *   status?: string,
     *   cdek_uuid?: string|null,
     *   cdek_number?: string|null,
     *   async?: bool,
     *   already_existed?: bool,
     *   error?: string,
     *   error_code?: string,
     *   reason?: string,
     *   checks?: array<string, bool>
     * }
     */
    public function register(int $deliveryOrderId, ?int $actorId = null): array
    {
        if (!DeliveryModelService::isOrderCreateEnabled()) {
            return [
                'ok' => false,
                'error' => t('delivery.cdek_create_disabled'),
                'error_code' => 'order_create_disabled',
            ];
        }

        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => t('delivery.not_found'), 'error_code' => 'not_found'];
        }

        if ($actorId !== null && (int) ($row['buyer_user_id'] ?? 0) !== $actorId) {
            return ['ok' => false, 'error' => t('delivery.forbidden'), 'error_code' => 'forbidden'];
        }

        // Idempotency: UUID already stored → never second POST; poll for SUCCESSFUL/INVALID.
        $existingUuid = trim((string) ($row['cdek_uuid'] ?? $row['logistics_order_id'] ?? ''));
        if ($existingUuid !== '') {
            $polled = $this->pollOne($deliveryOrderId);
            $fresh = $this->orders->find($deliveryOrderId);

            return [
                'ok' => true,
                'status' => (string) ($fresh['status'] ?? $row['status'] ?? ''),
                'cdek_uuid' => $existingUuid,
                'cdek_number' => $polled['cdek_number'] ?? ($fresh['cdek_number'] ?? $row['cdek_number'] ?? null),
                'async' => empty($polled['cdek_number'] ?? $fresh['cdek_number'] ?? $row['cdek_number']),
                'already_existed' => true,
            ];
        }

        $gate = $this->payments->canCreateCdekOrder($deliveryOrderId);
        if (!($gate['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => t('delivery.cdek_not_ready'),
                'error_code' => 'can_create_false',
                'reason' => $gate['reason'] ?? null,
                'checks' => $gate['checks'] ?? [],
            ];
        }

        return $this->registerInternal($deliveryOrderId);
    }

    /**
     * @return array{ok: bool, status?: string, cdek_uuid?: string|null, cdek_number?: string|null, async?: bool, already_existed?: bool, error?: string, error_code?: string}
     */
    private function registerInternal(int $deliveryOrderId): array
    {
        try {
            $this->orders->getDb()->beginTransaction();

            $lock = $this->orders->getDb()->prepare(
                'SELECT * FROM delivery_orders WHERE id = ? FOR UPDATE'
            );
            $lock->execute([$deliveryOrderId]);
            $locked = $lock->fetch();
            if (!$locked) {
                $this->orders->getDb()->rollBack();
                return ['ok' => false, 'error' => t('delivery.not_found'), 'error_code' => 'not_found'];
            }

            $existingUuid = trim((string) ($locked['cdek_uuid'] ?? $locked['logistics_order_id'] ?? ''));
            if ($existingUuid !== '') {
                $this->orders->getDb()->commit();
                $polled = $this->pollOne($deliveryOrderId);

                return [
                    'ok' => true,
                    'status' => (string) (($polled['status'] ?? null) ?: ($locked['status'] ?? '')),
                    'cdek_uuid' => $existingUuid,
                    'cdek_number' => $polled['cdek_number'] ?? ($locked['cdek_number'] ?? null),
                    'async' => empty($polled['cdek_number'] ?? $locked['cdek_number']),
                    'already_existed' => true,
                ];
            }

            $apiStatus = (string) ($locked['cdek_api_status'] ?? DeliveryStatusMachine::API_NONE);
            // Soft in-flight: PENDING without UUID may retry after timeout; ACCEPTED/CREATED without UUID is anomalous retry.
            if ($apiStatus === DeliveryStatusMachine::API_CREATED) {
                $this->orders->getDb()->commit();
                return [
                    'ok' => false,
                    'error' => t('delivery.cdek_registration_in_progress'),
                    'error_code' => 'already_created',
                ];
            }

            $imNumber = trim((string) ($locked['order_number'] ?? ''));
            if ($imNumber === '') {
                $this->orders->getDb()->rollBack();
                return ['ok' => false, 'error' => 'missing_im_number', 'error_code' => 'im_number_missing'];
            }

            // Mark in-flight before HTTP (race barrier). Stable IM already on row.
            $this->orders->updateFields($deliveryOrderId, [
                'status' => DeliveryOrder::STATUS_CDEK_ORDER_PENDING,
                'cdek_api_status' => DeliveryStatusMachine::API_PENDING,
                'last_error_code' => null,
                'last_error_message' => null,
            ]);

            $this->orders->getDb()->commit();
        } catch (\Throwable $e) {
            if ($this->orders->getDb()->inTransaction()) {
                $this->orders->getDb()->rollBack();
            }
            return ['ok' => false, 'error' => 'lock_failed', 'error_code' => 'lock_failed'];
        }

        $row = $this->orders->findWithDetails($deliveryOrderId);
        $paidOk = $this->assertPaidBarrier($row);
        if (!$paidOk['ok']) {
            $this->markFailed(
                $deliveryOrderId,
                $paidOk['error_code'] ?? 'payment_barrier',
                (string) ($paidOk['error'] ?? 'payment barrier')
            );
            return $paidOk;
        }

        $avr = $this->buildAvr($row);
        if ($avr === null) {
            $this->markFailed($deliveryOrderId, 'avr_missing', 'Unable to build order payload');
            return ['ok' => false, 'error' => t('delivery.cdek_not_ready'), 'error_code' => 'avr_missing'];
        }

        $sender = is_array($avr['sender'] ?? null) ? $avr['sender'] : [];
        $recipient = is_array($avr['recipient'] ?? null) ? $avr['recipient'] : [];
        $fromCode = (int) ($sender['cdek_city_code'] ?? 0) ?: null;
        $toCode = (int) ($recipient['cdek_city_code'] ?? 0) ?: null;

        $result = $this->orderService->create($avr, [
            'from_city_code' => $fromCode,
            'to_city_code' => $toCode,
            'type' => (int) (($this->orderService->client()->config()['order_type'] ?? 1)),
            'existing_uuid' => null,
        ]);

        if (!($result['ok'] ?? false)) {
            $code = (string) (($result['error_mapped']['internal_code'] ?? null)
                ?: ($result['internal_status'] ?? 'create_failed'));
            $msg = (string) ($result['error'] ?? 'CDEK create failed');
            $this->markFailed($deliveryOrderId, $code, $msg, $result['error_mapped'] ?? null);

            return [
                'ok' => false,
                'error' => t('delivery.cdek_create_failed'),
                'error_code' => $code,
                'status' => DeliveryOrder::STATUS_CDEK_ORDER_FAILED,
            ];
        }

        $uuid = (string) ($result['logistics_order_id'] ?? '');
        $number = isset($result['tracking_number']) && $result['tracking_number'] !== ''
            ? (string) $result['tracking_number']
            : null;
        $async = !empty($result['async']) || $number === null;

        $this->orders->updateFields($deliveryOrderId, [
            'logistics_order_id' => $uuid !== '' ? $uuid : null,
            'cdek_uuid' => $uuid !== '' ? $uuid : null,
            'cdek_number' => $number,
            'cdek_request_uuid' => $result['request_uuid'] ?? null,
            'cdek_api_status' => $async
                ? DeliveryStatusMachine::API_ACCEPTED
                : DeliveryStatusMachine::API_CREATED,
            'status' => $async
                ? DeliveryOrder::STATUS_CDEK_ORDER_PENDING
                : DeliveryOrder::STATUS_ORDER_CREATED,
            'accepted_at' => $async ? null : date('Y-m-d H:i:s'),
            'last_synced_at' => date('Y-m-d H:i:s'),
            'last_error_code' => null,
            'last_error_message' => null,
        ]);

        $this->orders->logEvent(
            $deliveryOrderId,
            null,
            'system',
            $async ? 'cdek_order_accepted' : 'cdek_order_created',
            DeliveryOrder::STATUS_PAID,
            $async ? DeliveryOrder::STATUS_CDEK_ORDER_PENDING : DeliveryOrder::STATUS_ORDER_CREATED,
            DeliveryPii::redactPayload([
                'cdek_uuid' => $uuid,
                'async' => $async,
                'http_status' => $result['http_status'] ?? null,
                'internal_status' => $result['internal_status'] ?? null,
                'im_number' => $avr['order_number'] ?? null,
            ])
        );

        if (!$async && $number !== null) {
            $this->orders->addTrackingEvent($deliveryOrderId, [
                'tracking_number' => $number,
                'carrier_status' => 'created',
                'carrier_message' => t('delivery.tracking_created'),
                'event_at' => date('Y-m-d H:i:s'),
            ]);
            $this->orders->transitionStatus(
                $deliveryOrderId,
                DeliveryOrder::STATUS_ACCEPTED,
                null,
                'logistics',
                'cdek_successful'
            );
        }

        return [
            'ok' => true,
            'status' => $async
                ? DeliveryOrder::STATUS_CDEK_ORDER_PENDING
                : DeliveryOrder::STATUS_ORDER_CREATED,
            'cdek_uuid' => $uuid,
            'cdek_number' => $number,
            'async' => $async,
            'already_existed' => !empty($result['already_existed']),
        ];
    }

    /**
     * Ограниченный poll для ACCEPTED заказов (cron / background).
     *
     * @return array{processed: int, successful: int, invalid: int, waiting: int}
     */
    public function pollPending(int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->orders->findCdekPendingPoll($limit);
        $stats = ['processed' => 0, 'successful' => 0, 'invalid' => 0, 'waiting' => 0];

        foreach ($rows as $row) {
            $stats['processed']++;
            $result = $this->pollOne((int) $row['id']);
            $outcome = (string) ($result['outcome'] ?? 'waiting');
            if ($outcome === 'successful') {
                $stats['successful']++;
            } elseif ($outcome === 'invalid') {
                $stats['invalid']++;
            } else {
                $stats['waiting']++;
            }
        }

        return $stats;
    }

    /**
     * GET /v2/orders/{uuid} (fallback im_number) → SUCCESSFUL | INVALID | waiting.
     *
     * @return array{ok: bool, outcome?: string, status?: string, cdek_number?: string|null, error?: string}
     */
    public function pollOne(int $deliveryOrderId): array
    {
        $row = $this->orders->find($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        $uuid = trim((string) ($row['cdek_uuid'] ?? $row['logistics_order_id'] ?? ''));
        if ($uuid === '') {
            $im = trim((string) ($row['order_number'] ?? ''));
            if ($im !== '') {
                $byIm = $this->orderService->findByImNumber($im);
                if (($byIm['ok'] ?? false) && !empty($byIm['uuid'])) {
                    $uuid = (string) $byIm['uuid'];
                    $this->orders->updateFields($deliveryOrderId, [
                        'cdek_uuid' => $uuid,
                        'logistics_order_id' => $uuid,
                        'cdek_number' => $byIm['cdek_number'] ?? null,
                        'cdek_api_status' => DeliveryStatusMachine::API_ACCEPTED,
                    ]);
                }
            }
        }

        if ($uuid === '') {
            return ['ok' => false, 'outcome' => 'waiting', 'error' => 'no_uuid'];
        }

        $fetched = $this->orderService->getByUuid($uuid);
        if (!($fetched['ok'] ?? false)) {
            return ['ok' => false, 'outcome' => 'waiting', 'error' => $fetched['error'] ?? 'get_failed'];
        }

        $requestState = strtoupper((string) ($fetched['request_state'] ?? ''));
        $requests = is_array($fetched['requests'] ?? null) ? $fetched['requests'] : [];
        $hasInvalid = $requestState === 'INVALID';
        $hasSuccessful = $requestState === 'SUCCESSFUL';
        foreach ($requests as $req) {
            if (!is_array($req)) {
                continue;
            }
            $st = strtoupper((string) ($req['state'] ?? ''));
            if ($st === 'INVALID') {
                $hasInvalid = true;
            }
            if ($st === 'SUCCESSFUL') {
                $hasSuccessful = true;
            }
        }

        $cdekNumber = $fetched['cdek_number'] ?? null;

        if ($hasInvalid && !$hasSuccessful) {
            $errMsg = $this->extractRequestErrors($requests);
            $this->markFailed(
                $deliveryOrderId,
                'CDEK_INVALID',
                $errMsg !== '' ? $errMsg : 'CDEK request INVALID',
                ['request_state' => $requestState]
            );

            return [
                'ok' => false,
                'outcome' => 'invalid',
                'status' => DeliveryOrder::STATUS_CDEK_ORDER_FAILED,
                'cdek_number' => $cdekNumber,
            ];
        }

        if ($hasSuccessful || ($cdekNumber !== null && $cdekNumber !== '')) {
            $this->orders->updateFields($deliveryOrderId, [
                'cdek_uuid' => $uuid,
                'logistics_order_id' => $uuid,
                'cdek_number' => $cdekNumber,
                'cdek_api_status' => DeliveryStatusMachine::API_CREATED,
                'status' => DeliveryOrder::STATUS_ORDER_CREATED,
                'accepted_at' => date('Y-m-d H:i:s'),
                'last_synced_at' => date('Y-m-d H:i:s'),
                'last_error_code' => null,
                'last_error_message' => null,
            ]);
            if ($cdekNumber) {
                $this->orders->addTrackingEvent($deliveryOrderId, [
                    'tracking_number' => $cdekNumber,
                    'carrier_status' => 'created',
                    'carrier_message' => t('delivery.tracking_created'),
                    'event_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $this->orders->transitionStatus(
                $deliveryOrderId,
                DeliveryOrder::STATUS_ACCEPTED,
                null,
                'logistics',
                'cdek_poll_successful'
            );
            $this->orders->logEvent(
                $deliveryOrderId,
                null,
                'system',
                'cdek_order_successful',
                DeliveryOrder::STATUS_CDEK_ORDER_PENDING,
                DeliveryOrder::STATUS_ORDER_CREATED,
                DeliveryPii::redactPayload(['cdek_uuid' => $uuid, 'cdek_number' => $cdekNumber])
            );

            return [
                'ok' => true,
                'outcome' => 'successful',
                'status' => DeliveryOrder::STATUS_ORDER_CREATED,
                'cdek_number' => $cdekNumber,
            ];
        }

        $this->orders->updateFields($deliveryOrderId, [
            'last_synced_at' => date('Y-m-d H:i:s'),
            'cdek_api_status' => DeliveryStatusMachine::API_ACCEPTED,
        ]);

        return [
            'ok' => true,
            'outcome' => 'waiting',
            'status' => DeliveryOrder::STATUS_CDEK_ORDER_PENDING,
            'cdek_number' => $cdekNumber,
        ];
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array{ok: true}|array{ok: false, error: string, error_code: string}
     */
    private function assertPaidBarrier(?array $row): array
    {
        if (!$row) {
            return ['ok' => false, 'error' => t('delivery.not_found'), 'error_code' => 'not_found'];
        }

        $pay = (new DeliveryPayment())->findPaidForDeliveryOrder((int) $row['id']);
        if (!$pay || ($pay['status'] ?? '') !== DeliveryPayment::STATUS_PAID) {
            return ['ok' => false, 'error' => t('delivery.cdek_not_ready'), 'error_code' => 'payment_not_paid'];
        }

        $quote = $row['selected_quote'] ?? null;
        $required = (int) ($quote['total_amount'] ?? $row['paid_amount'] ?? 0);
        $paid = (int) ($pay['amount'] ?? 0);
        if ($required > 0 && $paid < $required) {
            return ['ok' => false, 'error' => t('delivery.cdek_not_ready'), 'error_code' => 'amount_insufficient'];
        }

        $payCurrency = strtoupper((string) ($pay['currency'] ?? 'KZT'));
        $quoteCurrency = strtoupper((string) ($quote['currency'] ?? $payCurrency));
        if ($payCurrency !== $quoteCurrency) {
            return ['ok' => false, 'error' => t('delivery.cdek_not_ready'), 'error_code' => 'currency_mismatch'];
        }

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private function buildAvr(?array $row): ?array
    {
        if (!$row || empty($row['sender']) || empty($row['recipient']) || empty($row['shipment'])) {
            return null;
        }
        $quote = $row['selected_quote'] ?? null;
        if (!$quote) {
            return null;
        }

        $deliveryOrderId = (int) $row['id'];

        return [
            'delivery_order_id' => $deliveryOrderId,
            'order_number' => $row['order_number'],
            'p2p_transaction_id' => (int) $row['order_id'],
            'customer' => [
                'user_id' => (int) $row['buyer_user_id'],
                'name' => $row['recipient']['name'] ?? '',
                'phone' => $row['recipient']['phone'] ?? '',
            ],
            'sender' => $row['sender'],
            'recipient' => $row['recipient'],
            'shipment' => $row['shipment'],
            'service' => [
                'service_code' => $quote['service_code'] ?? ('cdek_' . ($quote['tariff_code'] ?? '')),
                'service_name' => $quote['service_name'] ?? '',
                'tariff_code' => $quote['tariff_code'] ?? null,
                'logistics_provider' => $row['logistics_name'] ?? 'cdek',
            ],
            'finance' => [
                'base_amount' => (int) ($quote['base_amount'] ?? 0),
                'total_amount' => (int) ($quote['total_amount'] ?? 0),
                'currency' => $quote['currency'] ?? 'KZT',
            ],
            'identifiers' => [
                'delivery_order_id' => $deliveryOrderId,
                'p2p_transaction_id' => (int) $row['order_id'],
                'logistics_order_id' => $row['logistics_order_id'] ?? null,
                'im_number' => $row['order_number'],
            ],
            'seller' => [
                'user_id' => (int) $row['seller_user_id'],
            ],
        ];
    }

    /** @param array<string, mixed>|null $details */
    private function markFailed(int $deliveryOrderId, string $code, string $message, ?array $details = null): void
    {
        $this->orders->updateFields($deliveryOrderId, [
            'status' => DeliveryOrder::STATUS_CDEK_ORDER_FAILED,
            'cdek_api_status' => DeliveryStatusMachine::API_FAILED,
            'last_error_code' => mb_substr($code, 0, 64),
            'last_error_message' => mb_substr($message, 0, 255),
            'last_synced_at' => date('Y-m-d H:i:s'),
        ]);
        $this->orders->logEvent(
            $deliveryOrderId,
            null,
            'system',
            'cdek_order_invalid',
            null,
            DeliveryOrder::STATUS_CDEK_ORDER_FAILED,
            DeliveryPii::redactPayload([
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ])
        );
    }

    /** @param list<array<string, mixed>> $requests */
    private function extractRequestErrors(array $requests): string
    {
        $parts = [];
        foreach ($requests as $req) {
            if (!is_array($req)) {
                continue;
            }
            $errs = $req['errors'] ?? [];
            if (!is_array($errs)) {
                continue;
            }
            foreach ($errs as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $parts[] = trim(((string) ($e['code'] ?? '')) . ' ' . ((string) ($e['message'] ?? '')));
            }
        }

        return mb_substr(implode('; ', array_filter($parts)), 0, 255);
    }
}
