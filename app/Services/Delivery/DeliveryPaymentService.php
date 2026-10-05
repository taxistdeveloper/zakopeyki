<?php

namespace App\Services\Delivery;

use App\Helpers\ProductHelper;
use App\Models\DeliveryOrder;
use App\Models\DeliveryPayment;
use App\Models\Notification;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentGatewayInterface;

/**
 * Платёжный этап доставки CDEK (Phase 7).
 *
 * Quote → payment intent → gateway callback → DELIVERY_PAID.
 * CDEK /v2/orders НЕ вызывается здесь — только canCreateCdekOrder() gate.
 */
class DeliveryPaymentService
{
    private DeliveryOrder $orders;
    private DeliveryPayment $payments;
    private PaymentGatewayInterface $gateway;
    private ShippingQuoteGuard $guard;

    public function __construct(
        ?DeliveryOrder $orders = null,
        ?DeliveryPayment $payments = null,
        ?PaymentGatewayInterface $gateway = null,
        ?ShippingQuoteGuard $guard = null
    ) {
        $this->orders = $orders ?? new DeliveryOrder();
        $this->payments = $payments ?? new DeliveryPayment();
        $this->gateway = $gateway ?? PaymentGatewayFactory::forDelivery();
        $this->guard = $guard ?? new ShippingQuoteGuard($this->orders);
    }

    /**
     * Создать payment intent на 100% deliveryAmountToPay из active quote.
     * Игнорирует amount/currency из входных данных frontend.
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, redirect_url?: string, payment_id?: int, error?: string, error_code?: string}
     */
    public function createPaymentIntent(int $deliveryOrderId, int $actorId, array $input = []): array
    {
        // Security: never trust client money fields.
        unset($input['amount'], $input['total_amount'], $input['delivery_amount'], $input['deliveryAmountToPay'], $input['currency']);

        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return $this->fail('not_found', t('delivery.not_found'));
        }
        if ((int) ($row['buyer_user_id'] ?? 0) !== $actorId) {
            return $this->fail('forbidden', t('delivery.forbidden'));
        }

        if (!in_array((string) ($row['status'] ?? ''), [
            DeliveryOrder::STATUS_READY_FOR_PAYMENT,
            DeliveryOrder::STATUS_PAYMENT_PENDING, // allow retry path after careful checks
        ], true)) {
            return $this->fail('bad_status', t('delivery.bad_status'));
        }

        $quoteCheck = $this->assertQuotePayable($row);
        if (!$quoteCheck['ok']) {
            return $quoteCheck;
        }

        /** @var array<string, mixed> $quote */
        $quote = $quoteCheck['quote'];
        $amount = (int) $quoteCheck['amount'];
        $currency = (string) ($quoteCheck['currency'] ?? 'KZT');

        // Live revalidation vs CDEK (price drift).
        $deliveryService = new DeliveryService($this->orders);
        $revalidated = $this->guard->revalidateForPayment(
            $row,
            fn(int $id): array => $deliveryService->requestQuotes($id),
            $deliveryService->providerFor($row)
        );
        if (!$revalidated['ok']) {
            return [
                'ok' => false,
                'error' => $revalidated['error'] ?? t('delivery.payment_failed'),
                'error_code' => (string) ($revalidated['reason'] ?? 'quote_revalidate_failed'),
                'old_amount' => $revalidated['old_amount'] ?? null,
                'new_amount' => $revalidated['new_amount'] ?? null,
            ];
        }

        $amount = (int) ($revalidated['amount'] ?? $amount);
        if ($amount <= 0) {
            return $this->fail('invalid_amount', t('delivery.invalid_amount'));
        }

        $row = $this->orders->findWithDetails($deliveryOrderId);
        $quote = $row['selected_quote'] ?? $quote;
        $currency = strtoupper((string) ($quote['currency'] ?? $currency ?: 'KZT'));

        $idempotencyKey = 'del-pay-' . $deliveryOrderId . '-' . (int) $quote['id'] . '-' . $amount . '-' . $currency;

        // Idempotent: same key pending → reuse.
        $existing = $this->payments->findByIdempotencyKey($idempotencyKey);
        if ($existing) {
            if ($existing['status'] === DeliveryPayment::STATUS_PAID) {
                return [
                    'ok' => true,
                    'payment_id' => (int) $existing['id'],
                    'redirect_url' => ProductHelper::url('/delivery/' . $deliveryOrderId),
                    'already_paid' => true,
                ];
            }
            if ($existing['status'] === DeliveryPayment::STATUS_PENDING) {
                return $this->fail('payment_pending', t('delivery.payment_pending'));
            }
        }

        $pendingOther = $this->payments->findPendingForOrder($deliveryOrderId);
        if ($pendingOther && (string) ($pendingOther['idempotency_key'] ?? '') !== $idempotencyKey) {
            return $this->fail('payment_pending', t('delivery.payment_pending'));
        }

        $snapshot = $this->buildPaymentSnapshot($row, $quote, $amount, $currency);
        $pgOrderId = 'zk-del-' . $deliveryOrderId . '-' . bin2hex(random_bytes(4));

        $allowSim = (bool) ($GLOBALS['appConfig']['allow_simulated_payments'] ?? false);
        $useGateway = $this->gateway->isConfigured();

        if ($useGateway) {
            $intent = $this->gateway->createPaymentIntent([
                'order_id' => $pgOrderId,
                'amount' => $amount,
                'currency' => $currency,
                'description' => t('delivery.payment_description', [
                    'number' => $row['order_number'] ?? (string) $deliveryOrderId,
                ]),
                'param1' => (string) $deliveryOrderId,
            ]);
            if (!($intent['ok'] ?? false)) {
                return $this->fail('gateway_init_failed', t('delivery.payment_failed'));
            }

            $paymentId = $this->payments->createPending([
                'delivery_order_id' => $deliveryOrderId,
                'buyer_user_id' => $actorId,
                'pg_order_id' => $pgOrderId,
                'amount' => $amount,
                'currency' => $currency,
                'quote_id' => (int) $quote['id'],
                'acquirer_provider' => $this->gateway->code(),
                'idempotency_key' => $idempotencyKey,
                'meta' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            ]);

            $this->orders->transitionStatus(
                $deliveryOrderId,
                DeliveryOrder::STATUS_PAYMENT_PENDING,
                $actorId,
                'buyer',
                'payment_initiated',
                ['pg_order_id' => $pgOrderId, 'amount' => $amount, 'payment_id' => $paymentId]
            );
            $this->orders->updateFields($deliveryOrderId, [
                'payment_status' => 'pending',
                'payment_id' => $paymentId,
            ]);

            return [
                'ok' => true,
                'payment_id' => $paymentId,
                'redirect_url' => (string) $intent['redirect_url'],
            ];
        }

        if ($allowSim) {
            $paymentId = $this->payments->createPending([
                'delivery_order_id' => $deliveryOrderId,
                'buyer_user_id' => $actorId,
                'pg_order_id' => $pgOrderId,
                'amount' => $amount,
                'currency' => $currency,
                'quote_id' => (int) $quote['id'],
                'acquirer_provider' => 'simulated',
                'idempotency_key' => $idempotencyKey,
                'meta' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            ]);
            $this->orders->transitionStatus(
                $deliveryOrderId,
                DeliveryOrder::STATUS_PAYMENT_PENDING,
                $actorId,
                'buyer',
                'payment_initiated_sim',
                ['amount' => $amount, 'payment_id' => $paymentId]
            );
            $done = $this->payments->completeFromGateway($pgOrderId, 'sim-' . time(), (string) $amount, $currency);
            if (!($done['ok'] ?? false)) {
                return $this->fail('sim_complete_failed', t('delivery.payment_failed'));
            }
            return [
                'ok' => true,
                'payment_id' => $paymentId,
                'redirect_url' => ProductHelper::url('/delivery/' . $deliveryOrderId),
            ];
        }

        return $this->fail('payments_disabled', t('wallet.payments_disabled'));
    }

    /**
     * @return array{ok: bool, payment?: array<string, mixed>|null, delivery_status?: string, error?: string, error_code?: string}
     */
    public function getPaymentStatus(int $deliveryOrderId, int $actorId): array
    {
        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return $this->fail('not_found', t('delivery.not_found'));
        }
        $uid = $actorId;
        if ((int) ($row['buyer_user_id'] ?? 0) !== $uid && (int) ($row['seller_user_id'] ?? 0) !== $uid) {
            return $this->fail('forbidden', t('delivery.forbidden'));
        }

        $payment = $this->payments->findLatestForOrder($deliveryOrderId);
        $public = null;
        if ($payment) {
            $public = [
                'id' => (int) $payment['id'],
                'status' => $payment['status'],
                'amount' => (int) $payment['amount'],
                'currency' => $payment['currency'] ?? 'KZT',
                'quote_id' => isset($payment['quote_id']) ? (int) $payment['quote_id'] : null,
                'paid_at' => $payment['paid_at'] ?? null,
                'created_at' => $payment['created_at'] ?? null,
            ];
        }

        return [
            'ok' => true,
            'payment' => $public,
            'delivery_status' => (string) ($row['status'] ?? ''),
            'payment_status' => (string) ($row['payment_status'] ?? 'unpaid'),
            'ready_for_cdek' => ($this->canCreateCdekOrder($deliveryOrderId)['ok'] ?? false) === true,
        ];
    }

    /**
     * Критический барьер перед регистрацией CDEK-заказа (Phase 8 будет вызывать).
     *
     * @return array{ok: bool, reason?: string, checks?: array<string, bool>}
     */
    public function canCreateCdekOrder(int $deliveryOrderId): array
    {
        $row = $this->orders->findWithDetails($deliveryOrderId);
        $checks = [
            'delivery_exists' => false,
            'cdek_selected' => false,
            'point_a_valid' => false,
            'point_b_valid' => false,
            'shipment_valid' => false,
            'quote_active' => false,
            'quote_not_stale' => false,
            'payment_exists' => false,
            'payment_paid' => false,
            'amount_sufficient' => false,
            'currency_match' => false,
            'not_already_registered' => false,
            'not_in_flight' => false,
        ];

        if (!$row) {
            return ['ok' => false, 'reason' => 'delivery_not_found', 'checks' => $checks];
        }
        $checks['delivery_exists'] = true;

        $provider = (string) ($row['logistics_code'] ?? '');
        $checks['cdek_selected'] = $provider === 'cdek' || $provider === '' || $provider === 'stub';
        // Prefer explicit cdek; stub only for tests.
        if ($provider !== 'cdek' && $provider !== 'stub') {
            return ['ok' => false, 'reason' => 'cdek_not_selected', 'checks' => $checks];
        }
        $checks['cdek_selected'] = true;

        $sender = is_array($row['sender'] ?? null) ? $row['sender'] : null;
        $recipient = is_array($row['recipient'] ?? null) ? $row['recipient'] : null;
        $shipment = is_array($row['shipment'] ?? null) ? $row['shipment'] : null;
        $checks['point_a_valid'] = $sender !== null && trim((string) ($sender['city'] ?? $sender['shipment_point'] ?? '')) !== '';
        $checks['point_b_valid'] = $recipient !== null && trim((string) ($recipient['name'] ?? '')) !== ''
            && trim((string) ($recipient['phone'] ?? '')) !== '';
        $weight = (float) ($shipment['billed_gross_weight'] ?? $shipment['gross_weight'] ?? $shipment['weight_value'] ?? 0);
        $checks['shipment_valid'] = $shipment !== null && ($weight > 0 || !empty($shipment['packaging_id']));

        $quote = $row['selected_quote'] ?? null;
        $quoteStatus = (string) ($quote['quote_status'] ?? '');
        $checks['quote_active'] = is_array($quote) && in_array($quoteStatus, ['active', 'paid_snapshot'], true);
        $checks['quote_not_stale'] = is_array($quote)
            && (empty($quote['valid_until']) || strtotime((string) $quote['valid_until']) >= time() || $quoteStatus === 'paid_snapshot');

        $payment = $this->payments->findPaidForDeliveryOrder($deliveryOrderId);
        $checks['payment_exists'] = $payment !== null;
        $checks['payment_paid'] = $payment !== null && ($payment['status'] ?? '') === DeliveryPayment::STATUS_PAID;

        $required = (int) ($quote['total_amount'] ?? $row['paid_amount'] ?? 0);
        if ($required <= 0 && $payment) {
            $required = (int) ($payment['amount'] ?? 0);
        }
        $paid = (int) ($payment['amount'] ?? $row['paid_amount'] ?? 0);
        $checks['amount_sufficient'] = $payment !== null && $paid >= $required && $required > 0;

        $payCurrency = strtoupper((string) ($payment['currency'] ?? 'KZT'));
        $quoteCurrency = strtoupper((string) ($quote['currency'] ?? $payCurrency));
        $checks['currency_match'] = $payment !== null && $payCurrency === $quoteCurrency;

        $uuid = (string) ($row['cdek_uuid'] ?? $row['logistics_order_id'] ?? '');
        $checks['not_already_registered'] = $uuid === '';

        $apiStatus = (string) ($row['cdek_api_status'] ?? DeliveryStatusMachine::API_NONE);
        $checks['not_in_flight'] = !in_array($apiStatus, [
            DeliveryStatusMachine::API_PENDING,
            DeliveryStatusMachine::API_ACCEPTED,
            DeliveryStatusMachine::API_CREATED,
        ], true);

        // Delivery must be DELIVERY_PAID (or failed create retry).
        $status = (string) ($row['status'] ?? '');
        if (!in_array($status, [
            DeliveryOrder::STATUS_PAID,
            DeliveryOrder::STATUS_CDEK_ORDER_PENDING,
            DeliveryOrder::STATUS_CDEK_ORDER_FAILED,
        ], true)) {
            return ['ok' => false, 'reason' => 'status_not_paid', 'checks' => $checks];
        }

        if ($payment && ($payment['status'] ?? '') === DeliveryPayment::STATUS_REFUNDED) {
            return ['ok' => false, 'reason' => 'payment_refunded', 'checks' => $checks];
        }

        foreach ($checks as $name => $ok) {
            if (!$ok) {
                return ['ok' => false, 'reason' => $name, 'checks' => $checks];
            }
        }

        return ['ok' => true, 'checks' => $checks];
    }

    /**
     * После PAID — только FSM + уведомления. Без CDEK create.
     */
    public function onPaymentConfirmed(int $deliveryOrderId, int $amount, ?int $paymentId = null): void
    {
        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return;
        }

        if ((string) ($row['status'] ?? '') === DeliveryOrder::STATUS_PAID
            && (string) ($row['payment_status'] ?? '') === 'paid'
        ) {
            return; // idempotent
        }

        if ($paymentId === null) {
            $pay = $this->payments->findPaidForDeliveryOrder($deliveryOrderId);
            $paymentId = $pay ? (int) $pay['id'] : null;
        }

        $idempotencyKey = $this->orders->buildCreateIdempotencyKey($deliveryOrderId, $paymentId);

        $this->orders->updateFields($deliveryOrderId, [
            'status' => DeliveryOrder::STATUS_PAID,
            'payment_status' => 'paid',
            'paid_amount' => $amount,
            'paid_at' => date('Y-m-d H:i:s'),
            'payment_id' => $paymentId,
            'create_idempotency_key' => $idempotencyKey,
        ]);

        if (!empty($row['quote_id'])) {
            $this->orders->markQuotePaidSnapshot((int) $row['quote_id']);
        }

        $this->orders->logEvent(
            $deliveryOrderId,
            (int) $row['buyer_user_id'],
            'buyer',
            'payment_confirmed',
            null,
            DeliveryOrder::STATUS_PAID,
            DeliveryPii::redactPayload([
                'amount' => $amount,
                'payment_id' => $paymentId,
                'cdek_create_deferred' => true,
            ])
        );

        (new Notification())->createFor(
            (int) $row['seller_user_id'],
            t('delivery.notify_paid_seller', ['number' => $row['order_number']])
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{ok: true, quote: array, amount: int, currency: string}|array{ok: false, error: string, error_code: string}
     */
    public function assertQuotePayable(array $row): array
    {
        $quote = $row['selected_quote'] ?? null;
        if (!$quote || empty($quote['id'])) {
            return $this->fail('quote_missing', t('delivery.quote_not_found'));
        }

        $status = (string) ($quote['quote_status'] ?? 'active');
        if (!in_array($status, ['active', 'paid_snapshot'], true) && empty($quote['is_selected'])) {
            return $this->fail('quote_inactive', t('delivery.quote_not_found'));
        }

        if (strtotime((string) ($quote['valid_until'] ?? '1970-01-01')) < time()) {
            return $this->fail('quote_expired', t('delivery.quote_expired'));
        }

        $amount = (int) ($quote['total_amount'] ?? 0);
        // deliveryAmountToPay alias
        if (isset($quote['delivery_amount_to_pay'])) {
            $amount = (int) $quote['delivery_amount_to_pay'];
        }
        if ($amount <= 0) {
            return $this->fail('invalid_amount', t('delivery.invalid_amount'));
        }

        if (empty($row['sender'])) {
            return $this->fail('point_a_changed', t('delivery.missing_sender'));
        }
        if (empty($row['recipient'])) {
            return $this->fail('point_b_changed', t('delivery.missing_recipient'));
        }
        if (empty($row['shipment'])) {
            return $this->fail('shipment_changed', t('delivery.missing_shipment'));
        }

        $orderVersion = (int) ($row['shipping_version'] ?? 1);
        $quoteVersion = (int) ($quote['shipping_version'] ?? 0);
        if ($quoteVersion > 0 && $quoteVersion !== $orderVersion) {
            return $this->fail('shipment_changed', t('delivery.quote_stale_version'));
        }

        return [
            'ok' => true,
            'quote' => $quote,
            'amount' => $amount,
            'currency' => strtoupper((string) ($quote['currency'] ?? 'KZT')),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $quote
     * @return array<string, mixed>
     */
    private function buildPaymentSnapshot(array $row, array $quote, int $amount, string $currency): array
    {
        return [
            'version' => 1,
            'delivery_order_id' => (int) ($row['id'] ?? 0),
            'order_id' => (int) ($row['order_id'] ?? 0),
            'product_id' => (int) ($row['product_id'] ?? 0),
            'quote_id' => (int) ($quote['id'] ?? 0),
            'delivery_amount_to_pay' => $amount,
            'currency' => $currency,
            'tariff_code' => $quote['tariff_code'] ?? null,
            'service_code' => $quote['service_code'] ?? null,
            'point_a_fingerprint' => $this->fingerprintPoint($row['sender'] ?? null),
            'point_b_fingerprint' => $this->fingerprintPoint($row['recipient'] ?? null),
            'shipment_fingerprint' => $this->fingerprintShipment($row['shipment'] ?? null),
            'shipping_version' => (int) ($row['shipping_version'] ?? 1),
            'created_at' => date('c'),
        ];
    }

    /** @param array<string, mixed>|null $point */
    private function fingerprintPoint(?array $point): string
    {
        if (!$point) {
            return '';
        }
        return hash('sha256', implode('|', [
            $point['city'] ?? '',
            $point['street'] ?? '',
            $point['building'] ?? '',
            $point['pvz_code'] ?? '',
            $point['delivery_point'] ?? '',
            $point['shipment_point'] ?? '',
            $point['cdek_city_code'] ?? '',
            $point['delivery_mode'] ?? '',
            $point['origin_type'] ?? '',
        ]));
    }

    /** @param array<string, mixed>|null $shipment */
    private function fingerprintShipment(?array $shipment): string
    {
        if (!$shipment) {
            return '';
        }
        return hash('sha256', implode('|', [
            $shipment['billed_gross_weight'] ?? $shipment['gross_weight'] ?? '',
            $shipment['billed_length'] ?? $shipment['package_length'] ?? '',
            $shipment['billed_width'] ?? $shipment['package_width'] ?? '',
            $shipment['billed_height'] ?? $shipment['package_height'] ?? '',
            $shipment['packaging_id'] ?? '',
        ]));
    }

    /**
     * @return array{ok: false, error: string, error_code: string}
     */
    private function fail(string $code, string $message): array
    {
        return ['ok' => false, 'error' => $message, 'error_code' => $code];
    }
}
