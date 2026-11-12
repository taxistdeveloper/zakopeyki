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

    /**
     * Строгая проверка postLink от ePay.
     * Принимается только callback, для которого есть серверный intent (создан initPayment),
     * secret_hash совпадает, терминал/валюта/сумма (для успешной оплаты) соответствуют intent.
     *
     * @param array<string, mixed> $params
     * @return array{ok: bool, intent?: array<string, mixed>, error?: string}
     */
    public static function validatePostLink(array $params, string $expectedTerminal = ''): array
    {
        $invoiceId = (string) ($params['invoiceId'] ?? $params['invoiceID'] ?? '');
        $secret = (string) ($params['secret_hash'] ?? '');
        if ($invoiceId === '') {
            return ['ok' => false, 'error' => 'invoice_missing'];
        }
        if ($secret === '') {
            return ['ok' => false, 'error' => 'secret_hash_missing'];
        }
        $intent = \App\Services\HalykEpay\IntentStore::getByInvoice($invoiceId);
        if (!is_array($intent)) {
            return ['ok' => false, 'error' => 'intent_not_found'];
        }
        $expectedSecret = (string) ($intent['secret_hash'] ?? '');
        if ($expectedSecret === '' || !hash_equals($expectedSecret, $secret)) {
            return ['ok' => false, 'error' => 'secret_hash_mismatch'];
        }
        if ((string) ($intent['order_id'] ?? '') === '') {
            return ['ok' => false, 'error' => 'intent_invalid'];
        }

        $terminal = (string) ($params['terminal'] ?? $params['terminalId'] ?? '');
        if ($terminal !== '' && $expectedTerminal !== '' && !hash_equals($expectedTerminal, $terminal)) {
            return ['ok' => false, 'error' => 'terminal_mismatch'];
        }

        $currency = strtoupper((string) ($params['currency'] ?? ''));
        $intentCurrency = strtoupper((string) ($intent['currency'] ?? 'KZT'));
        if ($currency !== '' && $currency !== $intentCurrency) {
            return ['ok' => false, 'error' => 'currency_mismatch'];
        }

        $code = strtolower((string) ($params['code'] ?? ''));
        $reasonCode = (int) ($params['reasonCode'] ?? -1);
        $isSuccess = $code === 'ok' || $reasonCode === 0;
        if ($isSuccess && (string) ($intent['payment_type'] ?? 'payment') !== 'cardVerification') {
            $intentAmount = round((float) ($intent['amount'] ?? 0), 2);
            $paidAmount = round((float) ($params['amount'] ?? -1), 2);
            if ($intentAmount <= 0 || abs($intentAmount - $paidAmount) > 0.009) {
                return ['ok' => false, 'error' => 'amount_mismatch'];
            }
        }

        return ['ok' => true, 'intent' => $intent];
    }

    public function client(): HalykClient
    {
        return $this->client;
    }
}
