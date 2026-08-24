<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ActivityLogger;
use App\Models\Order;
use App\Models\Payment;
use App\Services\FreedomPay\Client as FreedomPayClient;

class PaymentController extends Controller
{
    /**
     * FreedomPay result_url — публичный, без auth/CSRF.
     * Ответ всегда XML.
     */
    public function freedomPayResult(): void
    {
        $fp = new FreedomPayClient();
        $params = FreedomPayClient::requestParams();
        $scriptName = 'result';

        if (!$fp->isConfigured()) {
            $this->xmlOut($fp->xmlResponse($scriptName, [
                'pg_status' => 'error',
                'pg_description' => 'Gateway not configured',
            ]));
            return;
        }

        if (!$fp->verifySig($scriptName, $params)) {
            ActivityLogger::warning('payment.freedompay.result', 'Invalid signature', 'payment', null, [
                'pg_order_id' => $params['pg_order_id'] ?? null,
            ]);
            $this->xmlOut($fp->xmlResponse($scriptName, [
                'pg_status' => 'error',
                'pg_description' => 'Invalid signature',
            ]));
            return;
        }

        $pgOrderId = (string) ($params['pg_order_id'] ?? '');
        $pgPaymentId = (string) ($params['pg_payment_id'] ?? '');
        $pgResult = (int) ($params['pg_result'] ?? 0);
        $pgAmount = (string) ($params['pg_amount'] ?? '0');
        $canReject = (int) ($params['pg_can_reject'] ?? 0) === 1;

        if ($pgOrderId === '') {
            $this->xmlOut($fp->xmlResponse($scriptName, [
                'pg_status' => 'error',
                'pg_description' => 'Missing order id',
            ]));
            return;
        }

        $payments = new Payment();

        if ($pgResult === 1) {
            $done = $payments->completeFromGateway($pgOrderId, $pgPaymentId, $pgAmount);

            if ($done['ok']) {
                ActivityLogger::info(
                    'payment.freedompay.result',
                    'Payment OK #' . ($done['order_id'] ?? ''),
                    'order',
                    $done['order_id'] ?? null,
                    [
                        'pg_order_id' => $pgOrderId,
                        'pg_payment_id' => $pgPaymentId,
                    ]
                );
                $this->xmlOut($fp->xmlResponse($scriptName, [
                    'pg_status' => 'ok',
                    'pg_description' => 'Order paid',
                ]));
                return;
            }

            ActivityLogger::warning(
                'payment.freedompay.result',
                $done['error'] ?? 'complete failed',
                'payment',
                null,
                [
                    'pg_order_id' => $pgOrderId,
                    'error' => $done['error'] ?? null,
                ]
            );

            if ($canReject) {
                $this->xmlOut($fp->xmlResponse($scriptName, [
                    'pg_status' => 'rejected',
                    'pg_description' => (string) ($done['error'] ?? 'Cannot accept payment'),
                ]));
                return;
            }

            $this->xmlOut($fp->xmlResponse($scriptName, [
                'pg_status' => 'error',
                'pg_description' => (string) ($done['error'] ?? 'Processing error'),
            ]));
            return;
        }

        $fail = $payments->failFromGateway($pgOrderId, $pgPaymentId !== '' ? $pgPaymentId : null);
        ActivityLogger::info(
            'payment.freedompay.result',
            'Payment failed/cancelled',
            'order',
            $fail['order_id'] ?? null,
            [
                'pg_order_id' => $pgOrderId,
                'pg_result' => $pgResult,
            ]
        );

        $this->xmlOut($fp->xmlResponse($scriptName, [
            'pg_status' => 'ok',
            'pg_description' => 'Failure recorded',
        ]));
    }

    public function freedomPaySuccess(): void
    {
        Auth::requireLogin();
        $orderId = $this->resolveOrderIdFromReturn();
        if ($orderId > 0) {
            $order = (new Order())->find($orderId);
            if ($order && (int) ($order['buyer_id'] ?? 0) === Auth::id()) {
                $product = (new \App\Models\Product())->find((int) ($order['product_id'] ?? 0));
                if ($product && \App\Helpers\ProductHelper::isDigitalListing($product)
                    && in_array(($order['status'] ?? ''), ['escrowed', 'delivered', 'completed', 'confirmed'], true)) {
                    $_SESSION['flash'] = t('checkout.success_digital');
                    $this->redirect('/digital/' . (int) $product['id'] . '/watch');
                    return;
                }
                if (($order['status'] ?? '') === 'escrowed') {
                    $_SESSION['flash'] = t('checkout.success_text');
                    $this->redirect('/orders/' . $orderId);
                    return;
                }
                if (($order['status'] ?? '') === 'awaiting_payment') {
                    $_SESSION['flash'] = t('checkout.payment_pending');
                    $this->redirect('/orders/' . $orderId);
                    return;
                }
            }
        }

        $_SESSION['flash'] = t('checkout.success_text');
        $this->redirect('/orders');
    }

    public function freedomPayFailure(): void
    {
        Auth::requireLogin();
        $orderId = $this->resolveOrderIdFromReturn();
        $_SESSION['checkout_error'] = t('checkout.payment_failed');

        $params = FreedomPayClient::requestParams();
        $pgOrderId = (string) ($params['pg_order_id'] ?? '');
        if ($pgOrderId === '' && $orderId > 0) {
            $payment = (new Payment())->findByOrderId($orderId);
            $pgOrderId = (string) ($payment['pg_order_id'] ?? '');
        }
        if ($pgOrderId !== '') {
            (new Payment())->failFromGateway(
                $pgOrderId,
                !empty($params['pg_payment_id']) ? (string) $params['pg_payment_id'] : null
            );
        }

        if ($orderId > 0) {
            $order = (new Order())->find($orderId);
            if ($order && (int) ($order['buyer_id'] ?? 0) === Auth::id()) {
                $productId = (int) ($order['product_id'] ?? 0);
                if ($productId > 0) {
                    $this->redirect('/checkout/' . $productId);
                    return;
                }
            }
        }

        $this->redirect('/orders');
    }

    private function resolveOrderIdFromReturn(): int
    {
        $params = FreedomPayClient::requestParams();
        if (!empty($params['pg_order_id'])) {
            $payment = (new Payment())->findByPgOrderId((string) $params['pg_order_id']);
            if ($payment) {
                return (int) $payment['order_id'];
            }
        }
        if (!empty($params['pg_param1']) && ctype_digit((string) $params['pg_param1'])) {
            return (int) $params['pg_param1'];
        }
        return 0;
    }

    private function xmlOut(string $xml): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        echo $xml;
        exit;
    }
}
