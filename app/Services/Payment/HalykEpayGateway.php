<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\HalykEpay\Client as HalykClient;

/**
 * Адаптер Halyk ePay (OAuth + payform) к PaymentGatewayInterface.
 * @see https://epayment.kz/docs/platezhnaya-stranica
 */
final class HalykEpayGateway implements PaymentGatewayInterface
{
    private HalykClient $client;

    public function __construct(?HalykClient $client = null)
    {
        $this->client = $client ?? new HalykClient();
    }

    public function code(): string
    {
        return 'halyk_epay';
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

        if (empty($init['ok']) || empty($init['redirect_url'])) {
            return ['ok' => false, 'error' => (string) ($init['error'] ?? 'init_failed')];
        }

        return [
            'ok' => true,
            'redirect_url' => (string) $init['redirect_url'],
            'external_id' => isset($init['payment_id']) ? (string) $init['payment_id'] : null,
        ];
    }

    public function verifyCallback(string $scriptName, array $params): bool
    {
        unset($scriptName);
        $invoiceId = (string) ($params['invoiceId'] ?? $params['invoiceID'] ?? '');
        $secret = (string) ($params['secret_hash'] ?? '');
        if ($invoiceId === '' || $secret === '') {
            return false;
        }
        $intent = \App\Services\HalykEpay\IntentStore::getByInvoice($invoiceId);
        if (!$intent) {
            return false;
        }

        return hash_equals((string) ($intent['secret_hash'] ?? ''), $secret);
    }

    public function client(): HalykClient
    {
        return $this->client;
    }
}
