<?php

namespace App\Services\Payment;

/**
 * Абстракция эквайера: CDEK delivery payment не зависит от concrete FreedomPay.
 */
interface PaymentGatewayInterface
{
    public function code(): string;

    public function isConfigured(): bool;

    /**
     * @param array{
     *   order_id: string,
     *   amount: int,
     *   currency?: string,
     *   description?: string,
     *   param1?: string
     * } $intent
     * @return array{ok: bool, redirect_url?: string, external_id?: string, error?: string}
     */
    public function createPaymentIntent(array $intent): array;

    /**
     * @param array<string, scalar|null> $params
     */
    public function verifyCallback(string $scriptName, array $params): bool;
}
