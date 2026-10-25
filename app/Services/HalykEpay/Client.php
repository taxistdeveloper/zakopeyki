<?php

declare(strict_types=1);

namespace App\Services\HalykEpay;

use App\Helpers\ProductHelper;
use App\Services\Payment\LocalCheckout;

/**
 * Halyk ePay: OAuth + платёжная форма (halyk.pay / cardVerification).
 *
 * @see https://epayment.kz/docs/platezhnaya-stranica
 * @see https://epayment.kz/docs/cardverificationpaymentForm
 */
class Client
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(?array $config = null)
    {
        if ($config !== null) {
            $this->config = $config;

            return;
        }
        $path = dirname(__DIR__, 3) . '/config/halyk.php';
        $this->config = is_file($path) ? (require $path) : [];
    }

    public function isConfigured(): bool
    {
        $ready = trim((string) ($this->config['client_id'] ?? '')) !== ''
            && trim((string) ($this->config['client_secret'] ?? '')) !== ''
            && trim((string) ($this->config['terminal'] ?? '')) !== '';
        if (!$ready) {
            return false;
        }
        // Тестовые ключи — только при allow_simulated_payments
        if ($this->isTest()) {
            return LocalCheckout::allowed();
        }

        return true;
    }

    public function isTest(): bool
    {
        return strtolower((string) ($this->config['env'] ?? 'test')) !== 'prod';
    }

    public function jsUrl(): string
    {
        return $this->isTest()
            ? (string) ($this->config['js_url_test'] ?? 'https://test-epay.epayment.kz/payform/payment-api.js')
            : (string) ($this->config['js_url_prod'] ?? 'https://epay.homebank.kz/payform/payment-api.js');
    }

    public function terminal(): string
    {
        return (string) ($this->config['terminal'] ?? '');
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
     *   payment_type?: string,
     *   card_save?: bool,
     * } $payload
     * @return array{ok: bool, redirect_url?: string, payment_id?: string, error?: string}
     */
    public function initPayment(array $payload): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'halyk_not_configured'];
        }

        $orderId = trim((string) ($payload['order_id'] ?? ''));
        $amount = (float) ($payload['amount'] ?? 0);
        $paymentType = (string) ($payload['payment_type'] ?? 'payment');
        $cardSave = !empty($payload['card_save']) || $paymentType === 'cardVerification';

        if ($orderId === '') {
            return ['ok' => false, 'error' => 'invalid_payload'];
        }
        if ($paymentType !== 'cardVerification' && $amount <= 0) {
            return ['ok' => false, 'error' => 'invalid_payload'];
        }
        if ($paymentType === 'cardVerification') {
            $amount = 0;
        }

        $invoiceId = $this->makeInvoiceId($orderId);
        $secretHash = bin2hex(random_bytes(12));
        $currency = $paymentType === 'cardVerification'
            ? 'USD'
            : (string) ($this->config['currency'] ?? 'KZT');

        $auth = $this->fetchAccessToken([
            'invoiceID' => $invoiceId,
            'amount' => $amount,
            'currency' => $currency,
            'secret_hash' => $secretHash,
            'scope' => $paymentType === 'cardVerification'
                ? 'webapi usermanagement email_send verification statement statistics payment'
                : 'payment',
        ]);

        if (empty($auth['ok'])) {
            return ['ok' => false, 'error' => (string) ($auth['error'] ?? 'oauth_failed')];
        }

        $token = bin2hex(random_bytes(16));
        $paymentId = 'halyk-' . $invoiceId;

        $intent = [
            'order_id' => $orderId,
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'currency' => $currency,
            'description' => mb_substr((string) ($payload['description'] ?? ''), 0, 120),
            'secret_hash' => $secretHash,
            'payment_id' => $paymentId,
            'payment_type' => $paymentType,
            'card_save' => $cardSave,
            'account_id' => (string) ($payload['user_id'] ?? ''),
            'email' => (string) ($payload['user_email'] ?? ''),
            'phone' => (string) ($payload['user_phone'] ?? ''),
            'auth' => $auth['auth'],
            'param1' => (string) ($payload['param1'] ?? ''),
            'param2' => (string) ($payload['param2'] ?? ''),
        ];
        IntentStore::put($token, $intent);
        SimStore::put($token, $intent);

        return [
            'ok' => true,
            'redirect_url' => ProductHelper::url('/payments/halyk/sim/' . $token),
            'payment_id' => $paymentId,
        ];
    }

    /**
     * OAuth client_credentials (на каждый платёж — новый токен).
     *
     * @param array{invoiceID: string, amount: float|int, currency: string, secret_hash: string, scope?: string} $fields
     * @return array{ok: bool, auth?: array<string, mixed>, error?: string}
     */
    public function fetchAccessToken(array $fields): array
    {
        $url = $this->isTest()
            ? (string) ($this->config['oauth_url_test'] ?? 'https://test-epay-oauth.epayment.kz/oauth2/token')
            : (string) ($this->config['oauth_url_prod'] ?? 'https://epay-oauth.homebank.kz/oauth2/token');

        $body = [
            'grant_type' => 'client_credentials',
            'scope' => (string) ($fields['scope'] ?? 'payment'),
            'client_id' => (string) ($this->config['client_id'] ?? ''),
            'client_secret' => (string) ($this->config['client_secret'] ?? ''),
            'invoiceID' => (string) ($fields['invoiceID'] ?? ''),
            'amount' => (string) ($fields['amount'] ?? '0'),
            'currency' => (string) ($fields['currency'] ?? 'KZT'),
            'terminal' => $this->terminal(),
            'secret_hash' => (string) ($fields['secret_hash'] ?? ''),
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init_failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => $err !== '' ? $err : ('oauth_http_' . $code)];
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['access_token'])) {
            return ['ok' => false, 'error' => 'oauth_bad_response'];
        }

        return ['ok' => true, 'auth' => $json];
    }

    /**
     * Собрать объект для halyk.pay() на фронте.
     *
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    public function buildPayObject(array $intent): array
    {
        $paymentType = (string) ($intent['payment_type'] ?? 'payment');
        $obj = [
            'invoiceId' => (string) ($intent['invoice_id'] ?? ''),
            'backLink' => ProductHelper::absoluteUrl('/payments/halyk/success'),
            'failureBackLink' => ProductHelper::absoluteUrl('/payments/halyk/failure'),
            'postLink' => ProductHelper::absoluteUrl('/payments/halyk/postlink'),
            'failurePostLink' => ProductHelper::absoluteUrl('/payments/halyk/postlink'),
            'language' => (string) ($this->config['language'] ?? 'rus'),
            'description' => (string) ($intent['description'] ?? 'Zakopeyki'),
            'terminal' => $this->terminal(),
            'amount' => (float) ($intent['amount'] ?? 0),
            'currency' => (string) ($intent['currency'] ?? 'KZT'),
            'auth' => $intent['auth'] ?? [],
        ];

        $accountId = trim((string) ($intent['account_id'] ?? ''));
        if ($accountId !== '') {
            $obj['accountId'] = $accountId;
        }
        $email = trim((string) ($intent['email'] ?? ''));
        if ($email !== '') {
            $obj['email'] = $email;
        }
        $phone = preg_replace('/\D+/', '', (string) ($intent['phone'] ?? ''));
        if (is_string($phone) && $phone !== '') {
            $obj['phone'] = $phone;
        }

        if ($paymentType === 'cardVerification' || !empty($intent['card_save'])) {
            $obj['cardSave'] = true;
            $obj['paymentType'] = 'cardVerification';
            $obj['amount'] = 0;
            $obj['currency'] = 'USD';
            if ($accountId === '') {
                $obj['accountId'] = 'user';
            }
        }

        // ePay ищет заказ по invoiceId в query при возврате
        $q = http_build_query([
            'pg_order_id' => (string) ($intent['order_id'] ?? ''),
            'pg_payment_id' => (string) ($intent['payment_id'] ?? ''),
            'invoiceId' => (string) ($intent['invoice_id'] ?? ''),
        ]);
        $obj['backLink'] .= (str_contains($obj['backLink'], '?') ? '&' : '?') . $q;
        $obj['failureBackLink'] .= (str_contains($obj['failureBackLink'], '?') ? '&' : '?') . $q;

        return $obj;
    }

    /**
     * invoiceId: 6–15 цифр (требование ePay).
     */
    public function makeInvoiceId(string $orderId): string
    {
        $digits = preg_replace('/\D+/', '', $orderId) ?? '';
        if (strlen($digits) >= 6 && strlen($digits) <= 15) {
            return $digits;
        }
        // time(10) + random(5) = 15 цифр
        $id = substr((string) time(), -10) . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);

        return substr($id, 0, 15);
    }
}
