<?php

namespace App\Services\Payment;

use App\Services\FreedomPay\Client as FreedomPayClient;

/**
 * Адаптер FreedomPay к PaymentGatewayInterface.
 */
final class FreedomPayGateway implements PaymentGatewayInterface
{
    private FreedomPayClient $client;

    public function __construct(?FreedomPayClient $client = null)
    {
        $this->client = $client ?? new FreedomPayClient();
    }

    public function code(): string
    {
        return 'freedompay';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function createPaymentIntent(array $intent): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'gateway_not_configured'];
        }

        $init = $this->client->initPayment([
            'order_id' => (string) ($intent['order_id'] ?? ''),
            'amount' => (int) ($intent['amount'] ?? 0),
            'description' => (string) ($intent['description'] ?? ''),
            'param1' => (string) ($intent['param1'] ?? ''),
        ]);

        if (empty($init['redirect_url'])) {
            return ['ok' => false, 'error' => (string) ($init['error'] ?? 'init_failed')];
        }

        return [
            'ok' => true,
            'redirect_url' => (string) $init['redirect_url'],
            'external_id' => isset($init['pg_payment_id']) ? (string) $init['pg_payment_id'] : null,
        ];
    }

    public function verifyCallback(string $scriptName, array $params): bool
    {
        return $this->client->verifySig($scriptName, $params);
    }

    public function client(): FreedomPayClient
    {
        return $this->client;
    }
}
