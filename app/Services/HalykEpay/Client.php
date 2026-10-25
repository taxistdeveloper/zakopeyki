<?php

declare(strict_types=1);

namespace App\Services\HalykEpay;

use App\Helpers\ProductHelper;
use App\Services\Payment\LocalCheckout;

/**
 * Имитация Halyk ePay (hosted checkout) для локальной разработки.
 * Включается флагом allow_simulated_payments. Реальный API банка не вызывается.
 */
class Client
{
    public function isConfigured(): bool
    {
        return LocalCheckout::allowed();
    }

    /**
     * @param array{
     *   order_id: string,
     *   amount: int|float,
     *   description: string,
     *   user_id?: string|int,
     *   user_email?: string,
     *   user_phone?: string,
     *   user_ip?: string,
     *   param1?: string,
     *   param2?: string,
     *   param3?: string,
     * } $payload
     * @return array{ok: bool, redirect_url?: string, payment_id?: string, error?: string}
     */
    public function initPayment(array $payload): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'halyk_not_configured'];
        }

        $orderId = trim((string) ($payload['order_id'] ?? ''));
        $amount = (int) ($payload['amount'] ?? 0);
        if ($orderId === '' || $amount <= 0) {
            return ['ok' => false, 'error' => 'invalid_payload'];
        }

        $token = bin2hex(random_bytes(16));
        $paymentId = 'halyk-sim-' . substr($token, 0, 12);

        SimStore::put($token, [
            'order_id' => $orderId,
            'amount' => $amount,
            'description' => mb_substr((string) ($payload['description'] ?? ''), 0, 200),
            'currency' => 'KZT',
            'param1' => (string) ($payload['param1'] ?? ''),
            'param2' => (string) ($payload['param2'] ?? ''),
            'success_url' => ProductHelper::url('/payments/halyk/success'),
            'failure_url' => ProductHelper::url('/payments/halyk/failure'),
            'payment_id' => $paymentId,
        ]);

        return [
            'ok' => true,
            'redirect_url' => ProductHelper::url('/payments/halyk/sim/' . $token),
            'payment_id' => $paymentId,
        ];
    }
}
