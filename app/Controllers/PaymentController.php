<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ActivityLogger;
use App\Models\BoostOrder;
use App\Models\DeliveryPayment;
use App\Models\HighlightOrder;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TopFixedOrder;
use App\Models\TopFixedXlOrder;
use App\Models\VipOrder;
use App\Services\Boost\BoostPolicy;
use App\Services\Boost\BoostService;
use App\Services\Highlight\HighlightPolicy;
use App\Services\Highlight\HighlightService;
use App\Services\TopFixed\TopFixedPolicy;
use App\Services\TopFixed\TopFixedService;
use App\Services\TopFixedXl\TopFixedXlPolicy;
use App\Services\TopFixedXl\TopFixedXlService;
use App\Services\Vip\VipPolicy;
use App\Services\Vip\VipService;
use App\Models\AdCampaign;
use App\Models\MassPushCampaign;
use App\Models\TargetPushCampaign;
use App\Services\Ads\AdPolicy;
use App\Services\Ads\AdService;
use App\Services\MassPush\MassPushPolicy;
use App\Services\MassPush\MassPushService;
use App\Services\TargetPush\TargetPushPolicy;
use App\Services\TargetPush\TargetPushService;
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

        if (DeliveryPayment::isDeliveryPgOrderId($pgOrderId)) {
            $this->handleDeliveryFreedomPayResult($fp, $scriptName, $pgOrderId, $pgPaymentId, $pgAmount, $pgResult, $canReject);
            return;
        }

        if (BoostPolicy::isBoostPgOrderId($pgOrderId)) {
            $this->handleBoostFreedomPayResult($fp, $scriptName, $pgOrderId, $pgPaymentId, $pgAmount, $pgResult, $canReject);
            return;
        }

        if (HighlightPolicy::isHighlightPgOrderId($pgOrderId)) {
            $this->handleHighlightFreedomPayResult($fp, $scriptName, $pgOrderId, $pgPaymentId, $pgAmount, $pgResult, $canReject);
            return;
        }

        if (TopFixedXlPolicy::isTopFixedXlPgOrderId($pgOrderId)) {
            $this->handleTopFixedXlFreedomPayResult($fp, $scriptName, $pgOrderId, $pgPaymentId, $pgAmount, $pgResult, $canReject);
            return;
        }

        if (TopFixedPolicy::isTopFixedPgOrderId($pgOrderId)) {
            $this->handleTopFixedFreedomPayResult($fp, $scriptName, $pgOrderId, $pgPaymentId, $pgAmount, $pgResult, $canReject);
            return;
        }

        if (VipPolicy::isVipPgOrderId($pgOrderId)) {
            $this->handleVipFreedomPayResult($fp, $scriptName, $pgOrderId, $pgPaymentId, $pgAmount, $pgResult, $canReject);
            return;
        }

        if (AdPolicy::isAdPgOrderId($pgOrderId)) {
            $this->handleAdFreedomPayResult($fp, $scriptName, $pgOrderId, $pgPaymentId, $pgAmount, $pgResult, $canReject);
            return;
        }

        if (TargetPushPolicy::isPgOrderId($pgOrderId)) {
            $this->handleTargetPushFreedomPayResult($fp, $scriptName, $pgOrderId, $pgPaymentId, $pgAmount, $pgResult, $canReject);
            return;
        }

        if (MassPushPolicy::isPgOrderId($pgOrderId)) {
            $this->handleMassPushFreedomPayResult($fp, $scriptName, $pgOrderId, $pgPaymentId, $pgAmount, $pgResult, $canReject);
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
        $params = FreedomPayClient::requestParams();
        $pgOrderId = (string) ($params['pg_order_id'] ?? '');
        if ($pgOrderId !== '' && BoostPolicy::isBoostPgOrderId($pgOrderId)) {
            $this->redirectBoostReturn($pgOrderId);
            return;
        }
        if ($pgOrderId !== '' && HighlightPolicy::isHighlightPgOrderId($pgOrderId)) {
            $this->redirectHighlightReturn($pgOrderId);
            return;
        }
        if ($pgOrderId !== '' && TopFixedXlPolicy::isTopFixedXlPgOrderId($pgOrderId)) {
            $this->redirectTopFixedXlReturn($pgOrderId);
            return;
        }
        if ($pgOrderId !== '' && TopFixedPolicy::isTopFixedPgOrderId($pgOrderId)) {
            $this->redirectTopFixedReturn($pgOrderId);
            return;
        }
        if ($pgOrderId !== '' && VipPolicy::isVipPgOrderId($pgOrderId)) {
            $this->redirectVipReturn($pgOrderId);
            return;
        }
        if ($pgOrderId !== '' && AdPolicy::isAdPgOrderId($pgOrderId)) {
            $this->redirectAdReturn($pgOrderId);
            return;
        }
        if ($pgOrderId !== '' && TargetPushPolicy::isPgOrderId($pgOrderId)) {
            $this->redirectTargetPushReturn($pgOrderId);
            return;
        }
        if ($pgOrderId !== '' && MassPushPolicy::isPgOrderId($pgOrderId)) {
            $this->redirectMassPushReturn($pgOrderId);
            return;
        }
        if ($pgOrderId !== '' && DeliveryPayment::isDeliveryPgOrderId($pgOrderId)) {
            $payment = (new DeliveryPayment())->findByPgOrderId($pgOrderId);
            if ($payment && (int) ($payment['buyer_user_id'] ?? 0) === Auth::id()) {
                $_SESSION['flash'] = t('delivery.payment_success');
                $this->redirect('/delivery/' . (int) $payment['delivery_order_id']);
                return;
            }
        }

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
        $params = FreedomPayClient::requestParams();
        $pgOrderId = (string) ($params['pg_order_id'] ?? '');
        if ($pgOrderId !== '' && BoostPolicy::isBoostPgOrderId($pgOrderId)) {
            $this->redirectBoostReturn($pgOrderId);
            return;
        }
        if ($pgOrderId !== '' && HighlightPolicy::isHighlightPgOrderId($pgOrderId)) {
            $this->redirectHighlightReturn($pgOrderId);
            return;
        }
        if ($pgOrderId !== '' && TopFixedXlPolicy::isTopFixedXlPgOrderId($pgOrderId)) {
            $this->redirectTopFixedXlReturn($pgOrderId);
            return;
        }
        if ($pgOrderId !== '' && TopFixedPolicy::isTopFixedPgOrderId($pgOrderId)) {
            $this->redirectTopFixedReturn($pgOrderId);
            return;
        }
        if ($pgOrderId !== '' && VipPolicy::isVipPgOrderId($pgOrderId)) {
            $this->redirectVipReturn($pgOrderId);
            return;
        }
        if ($pgOrderId !== '' && AdPolicy::isAdPgOrderId($pgOrderId)) {
            $this->redirectAdReturn($pgOrderId);
            return;
        }
        if ($pgOrderId !== '' && TargetPushPolicy::isPgOrderId($pgOrderId)) {
            $this->redirectTargetPushReturn($pgOrderId);
            return;
        }
        if ($pgOrderId !== '' && MassPushPolicy::isPgOrderId($pgOrderId)) {
            $this->redirectMassPushReturn($pgOrderId);
            return;
        }
        if ($pgOrderId !== '' && DeliveryPayment::isDeliveryPgOrderId($pgOrderId)) {
            $payment = (new DeliveryPayment())->findByPgOrderId($pgOrderId);
            if ($payment) {
                (new DeliveryPayment())->failFromGateway(
                    $pgOrderId,
                    !empty($params['pg_payment_id']) ? (string) $params['pg_payment_id'] : null
                );
                $_SESSION['error'] = t('delivery.payment_failed');
                $this->redirect('/delivery/' . (int) $payment['delivery_order_id']);
                return;
            }
        }

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
            $pgOrderId = (string) $params['pg_order_id'];
            if (DeliveryPayment::isDeliveryPgOrderId($pgOrderId) || BoostPolicy::isBoostPgOrderId($pgOrderId) || HighlightPolicy::isHighlightPgOrderId($pgOrderId) || TopFixedPolicy::isTopFixedPgOrderId($pgOrderId) || TopFixedXlPolicy::isTopFixedXlPgOrderId($pgOrderId) || VipPolicy::isVipPgOrderId($pgOrderId) || AdPolicy::isAdPgOrderId($pgOrderId) || TargetPushPolicy::isPgOrderId($pgOrderId) || MassPushPolicy::isPgOrderId($pgOrderId)) {
                return 0;
            }
            $payment = (new Payment())->findByPgOrderId($pgOrderId);
            if ($payment) {
                return (int) $payment['order_id'];
            }
        }
        if (!empty($params['pg_param1']) && ctype_digit((string) $params['pg_param1'])) {
            return (int) $params['pg_param1'];
        }
        return 0;
    }

    private function handleDeliveryFreedomPayResult(
        FreedomPayClient $fp,
        string $scriptName,
        string $pgOrderId,
        string $pgPaymentId,
        string $pgAmount,
        int $pgResult,
        bool $canReject
    ): void {
        $payments = new DeliveryPayment();
        $params = FreedomPayClient::requestParams();

        if ($pgResult === 1) {
            $pgCurrency = isset($params['pg_currency']) ? (string) $params['pg_currency'] : null;
            if ($pgCurrency === '398') {
                $pgCurrency = 'KZT';
            }
            $webhookHash = hash('sha256', json_encode([
                'pg_order_id' => $pgOrderId,
                'pg_payment_id' => $pgPaymentId,
                'pg_amount' => $pgAmount,
                'pg_result' => $pgResult,
                'pg_currency' => $pgCurrency,
            ], JSON_UNESCAPED_UNICODE));

            $done = $payments->completeFromGateway(
                $pgOrderId,
                $pgPaymentId,
                $pgAmount,
                $pgCurrency,
                $webhookHash
            );
            if ($done['ok']) {
                ActivityLogger::info(
                    'payment.freedompay.delivery',
                    'Delivery payment OK #' . ($done['delivery_order_id'] ?? ''),
                    'delivery_order',
                    $done['delivery_order_id'] ?? null,
                    ['pg_order_id' => $pgOrderId]
                );
                $this->xmlOut($fp->xmlResponse($scriptName, [
                    'pg_status' => 'ok',
                    'pg_description' => 'Delivery paid',
                ]));
                return;
            }

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

        $payments->failFromGateway($pgOrderId, $pgPaymentId !== '' ? $pgPaymentId : null);
        $this->xmlOut($fp->xmlResponse($scriptName, [
            'pg_status' => 'ok',
            'pg_description' => 'Failure recorded',
        ]));
    }

    private function handleBoostFreedomPayResult(
        FreedomPayClient $fp,
        string $scriptName,
        string $pgOrderId,
        string $pgPaymentId,
        string $pgAmount,
        int $pgResult,
        bool $canReject
    ): void {
        $params = FreedomPayClient::requestParams();
        $pgCurrency = isset($params['pg_currency']) ? (string) $params['pg_currency'] : null;
        $done = (new BoostService())->handleGatewayResult(
            $pgOrderId,
            $pgPaymentId,
            $pgAmount,
            $pgResult,
            $canReject,
            $pgCurrency
        );

        $gateway = (string) ($done['gateway'] ?? 'error');
        if ($gateway === 'ok') {
            $this->xmlOut($fp->xmlResponse($scriptName, [
                'pg_status' => 'ok',
                'pg_description' => !empty($done['applied']) ? 'Boost applied' : 'Boost recorded',
            ]));
            return;
        }
        if ($gateway === 'rejected' && $canReject) {
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
    }

    private function redirectBoostReturn(string $pgOrderId): void
    {
        $order = (new BoostOrder())->findByPgOrderId($pgOrderId);
        if (!$order || (int) ($order['user_id'] ?? 0) !== (int) Auth::id()) {
            $_SESSION['error'] = t('boost.err_not_found');
            $this->redirect('/profile?tab=lots');
            return;
        }

        $status = (string) ($order['status'] ?? '');
        if ($status === BoostPolicy::STATUS_APPLIED) {
            $_SESSION['flash'] = t('boost.applied');
        } elseif ($status === BoostPolicy::STATUS_PAID || $status === BoostPolicy::STATUS_APPLY_FAILED) {
            $_SESSION['flash'] = t('boost.paid_waiting');
        } elseif ($status === BoostPolicy::STATUS_PENDING) {
            $_SESSION['flash'] = t('boost.payment_pending');
        } else {
            $_SESSION['error'] = t('boost.payment_failed');
        }
        $this->redirect('/profile?tab=lots');
    }

    private function redirectHighlightReturn(string $pgOrderId): void
    {
        $order = (new HighlightOrder())->findByPgOrderId($pgOrderId);
        if (!$order || (int) ($order['user_id'] ?? 0) !== (int) Auth::id()) {
            $_SESSION['error'] = t('highlight.err_not_found');
            $this->redirect('/profile?tab=lots');
            return;
        }

        $status = (string) ($order['status'] ?? '');
        $until = HighlightPolicy::formatUntil(isset($order['ends_at']) ? (string) $order['ends_at'] : null);
        $running = HighlightPolicy::windowActive(
            isset($order['starts_at']) ? (string) $order['starts_at'] : null,
            isset($order['ends_at']) ? (string) $order['ends_at'] : null
        );
        if (($status === HighlightPolicy::STATUS_APPLIED || $status === HighlightPolicy::STATUS_EXPIRED) && $running) {
            $_SESSION['flash'] = t('highlight.active', ['until' => $until]);
        } elseif ($status === HighlightPolicy::STATUS_APPLIED || $status === HighlightPolicy::STATUS_EXPIRED) {
            $_SESSION['flash'] = t('highlight.expired');
        } elseif ($status === HighlightPolicy::STATUS_PAID || $status === HighlightPolicy::STATUS_APPLY_FAILED) {
            $_SESSION['flash'] = t('highlight.paid_waiting');
        } elseif ($status === HighlightPolicy::STATUS_PENDING) {
            $_SESSION['flash'] = t('highlight.payment_pending');
        } else {
            $_SESSION['error'] = t('highlight.payment_failed');
        }
        $this->redirect('/profile?tab=lots');
    }

    private function redirectTopFixedXlReturn(string $pgOrderId): void
    {
        $order = (new TopFixedXlOrder())->findByPgOrderId($pgOrderId);
        if (!$order || (int) ($order['user_id'] ?? 0) !== (int) Auth::id()) {
            $_SESSION['error'] = t('top_fixed_xl.err_not_found');
            $this->redirect('/profile?tab=lots');
            return;
        }

        $status = (string) ($order['status'] ?? '');
        $until = TopFixedXlPolicy::formatUntil(isset($order['ends_at']) ? (string) $order['ends_at'] : null);
        $category = (string) ($order['category'] ?? '');
        $running = TopFixedXlPolicy::windowActive(
            isset($order['starts_at']) ? (string) $order['starts_at'] : null,
            isset($order['ends_at']) ? (string) $order['ends_at'] : null
        );
        if (($status === TopFixedXlPolicy::STATUS_APPLIED || $status === TopFixedXlPolicy::STATUS_EXPIRED) && $running) {
            $_SESSION['flash'] = t('top_fixed_xl.active', ['until' => $until, 'category' => $category]);
        } elseif ($status === TopFixedXlPolicy::STATUS_APPLIED || $status === TopFixedXlPolicy::STATUS_EXPIRED) {
            $_SESSION['flash'] = t('top_fixed_xl.expired');
        } elseif ($status === TopFixedXlPolicy::STATUS_PAID || $status === TopFixedXlPolicy::STATUS_APPLY_FAILED) {
            $_SESSION['flash'] = t('top_fixed_xl.paid_waiting');
        } elseif ($status === TopFixedXlPolicy::STATUS_PENDING) {
            $_SESSION['flash'] = t('top_fixed_xl.payment_pending');
        } else {
            $_SESSION['error'] = t('top_fixed_xl.payment_failed');
        }
        $this->redirect('/profile?tab=lots');
    }

    private function redirectVipReturn(string $pgOrderId): void
    {
        $order = (new VipOrder())->findByPgOrderId($pgOrderId);
        if (!$order || (int) ($order['user_id'] ?? 0) !== (int) Auth::id()) {
            $_SESSION['error'] = t('vip.err_not_found');
            $this->redirect('/profile?tab=lots');
            return;
        }

        $status = (string) ($order['status'] ?? '');
        $until = VipPolicy::formatUntil(isset($order['ends_at']) ? (string) $order['ends_at'] : null);
        $left = (string) (new \App\Models\VipLift())->pendingCount((int) ($order['id'] ?? 0));
        $running = VipPolicy::windowActive(
            isset($order['starts_at']) ? (string) $order['starts_at'] : null,
            isset($order['ends_at']) ? (string) $order['ends_at'] : null
        );
        if (($status === VipPolicy::STATUS_APPLIED || $status === VipPolicy::STATUS_EXPIRED) && $running) {
            $_SESSION['flash'] = t('vip.active', ['until' => $until, 'left' => $left]);
        } elseif ($status === VipPolicy::STATUS_APPLIED || $status === VipPolicy::STATUS_EXPIRED) {
            $_SESSION['flash'] = t('vip.expired');
        } elseif ($status === VipPolicy::STATUS_PAID || $status === VipPolicy::STATUS_APPLY_FAILED) {
            $_SESSION['flash'] = t('vip.paid_waiting');
        } elseif ($status === VipPolicy::STATUS_PENDING) {
            $_SESSION['flash'] = t('vip.payment_pending');
        } else {
            $_SESSION['error'] = t('vip.payment_failed');
        }
        $this->redirect('/profile?tab=lots');
    }

    private function redirectAdReturn(string $pgOrderId): void
    {
        $order = (new AdCampaign())->findByPgOrderId($pgOrderId);
        if (!$order || (int) ($order['user_id'] ?? 0) !== (int) Auth::id()) {
            $_SESSION['error'] = t('ads.err_not_found');
            $this->redirect('/ads');
            return;
        }
        $status = (string) ($order['status'] ?? '');
        if ($status === AdPolicy::STATUS_ACTIVE) {
            $_SESSION['flash'] = t('ads.activated');
        } elseif ($status === AdPolicy::STATUS_PAID) {
            $_SESSION['flash'] = t('ads.paid_waiting');
        } elseif ($status === AdPolicy::STATUS_PENDING) {
            $_SESSION['flash'] = t('ads.payment_pending');
        } else {
            $_SESSION['error'] = t('ads.payment_failed');
        }
        $this->redirect('/ads');
    }

    private function handleAdFreedomPayResult(
        FreedomPayClient $fp,
        string $scriptName,
        string $pgOrderId,
        string $pgPaymentId,
        string $pgAmount,
        int $pgResult,
        bool $canReject
    ): void {
        $pgCurrency = (string) (FreedomPayClient::requestParams()['pg_currency'] ?? '');
        $done = (new AdService())->handleGatewayResult(
            $pgOrderId,
            $pgPaymentId,
            $pgAmount,
            $pgResult,
            $canReject,
            $pgCurrency !== '' ? $pgCurrency : null
        );
        $gateway = (string) ($done['gateway'] ?? 'error');
        if ($gateway === 'ok') {
            $this->xmlOut($fp->xmlResponse($scriptName, [
                'pg_status' => 'ok',
                'pg_description' => !empty($done['applied']) ? 'Ad activated' : 'Ad recorded',
            ]));
            return;
        }
        if ($gateway === 'rejected' && $canReject) {
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
    }

    private function redirectTargetPushReturn(string $pgOrderId): void
    {
        $order = (new TargetPushCampaign())->findByPgOrderId($pgOrderId);
        if (!$order || (int) ($order['user_id'] ?? 0) !== (int) Auth::id()) {
            $_SESSION['error'] = t('target_push.err_not_found');
            $this->redirect('/target-push');
            return;
        }
        $status = (string) ($order['status'] ?? '');
        if ($status === TargetPushPolicy::STATUS_PAID) {
            $_SESSION['flash'] = t('target_push.paid_waiting');
        } elseif ($status === TargetPushPolicy::STATUS_PENDING) {
            $_SESSION['flash'] = t('target_push.payment_pending');
        } elseif (in_array($status, [TargetPushPolicy::STATUS_SENDING, TargetPushPolicy::STATUS_COMPLETED, TargetPushPolicy::STATUS_PARTIAL], true)) {
            $_SESSION['flash'] = t('target_push.already_started');
        } else {
            $_SESSION['error'] = t('target_push.payment_failed');
        }
        $this->redirect('/target-push');
    }

    private function handleTargetPushFreedomPayResult(
        FreedomPayClient $fp,
        string $scriptName,
        string $pgOrderId,
        string $pgPaymentId,
        string $pgAmount,
        int $pgResult,
        bool $canReject
    ): void {
        $pgCurrency = (string) (FreedomPayClient::requestParams()['pg_currency'] ?? '');
        $done = (new TargetPushService())->handleGatewayResult(
            $pgOrderId,
            $pgPaymentId,
            $pgAmount,
            $pgResult,
            $canReject,
            $pgCurrency !== '' ? $pgCurrency : null
        );
        $gateway = (string) ($done['gateway'] ?? 'error');
        if ($gateway === 'ok') {
            $this->xmlOut($fp->xmlResponse($scriptName, [
                'pg_status' => 'ok',
                'pg_description' => !empty($done['recorded']) ? 'Target Push paid' : 'Target Push recorded',
            ]));
            return;
        }
        if ($gateway === 'rejected' && $canReject) {
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
    }

    private function redirectMassPushReturn(string $pgOrderId): void
    {
        $order = (new MassPushCampaign())->findByPgOrderId($pgOrderId);
        if (!$order || (int) ($order['user_id'] ?? 0) !== (int) Auth::id()) {
            $_SESSION['error'] = t('mass_push.err_not_found');
            $this->redirect('/mass-push');
            return;
        }
        $status = (string) ($order['status'] ?? '');
        if ($status === MassPushPolicy::STATUS_PAID) {
            $_SESSION['flash'] = t('mass_push.paid_waiting');
        } elseif ($status === MassPushPolicy::STATUS_PENDING) {
            $_SESSION['flash'] = t('mass_push.payment_pending');
        } elseif (in_array($status, [MassPushPolicy::STATUS_SENDING, MassPushPolicy::STATUS_COMPLETED, MassPushPolicy::STATUS_PARTIAL], true)) {
            $_SESSION['flash'] = t('mass_push.already_started');
        } else {
            $_SESSION['error'] = t('mass_push.payment_failed');
        }
        $this->redirect('/mass-push');
    }

    private function handleMassPushFreedomPayResult(
        FreedomPayClient $fp,
        string $scriptName,
        string $pgOrderId,
        string $pgPaymentId,
        string $pgAmount,
        int $pgResult,
        bool $canReject
    ): void {
        $pgCurrency = (string) (FreedomPayClient::requestParams()['pg_currency'] ?? '');
        $done = (new MassPushService())->handleGatewayResult(
            $pgOrderId,
            $pgPaymentId,
            $pgAmount,
            $pgResult,
            $canReject,
            $pgCurrency !== '' ? $pgCurrency : null
        );
        $gateway = (string) ($done['gateway'] ?? 'error');
        if ($gateway === 'ok') {
            $this->xmlOut($fp->xmlResponse($scriptName, [
                'pg_status' => 'ok',
                'pg_description' => !empty($done['recorded']) ? 'Mass Push paid' : 'Mass Push recorded',
            ]));
            return;
        }
        if ($gateway === 'rejected' && $canReject) {
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
    }

    private function handleVipFreedomPayResult(
        FreedomPayClient $fp,
        string $scriptName,
        string $pgOrderId,
        string $pgPaymentId,
        string $pgAmount,
        int $pgResult,
        bool $canReject
    ): void {
        $pgCurrency = (string) (FreedomPayClient::requestParams()['pg_currency'] ?? '');
        $done = (new VipService())->handleGatewayResult(
            $pgOrderId,
            $pgPaymentId,
            $pgAmount,
            $pgResult,
            $canReject,
            $pgCurrency !== '' ? $pgCurrency : null
        );
        $gateway = (string) ($done['gateway'] ?? 'error');
        if ($gateway === 'ok') {
            $this->xmlOut($fp->xmlResponse($scriptName, [
                'pg_status' => 'ok',
                'pg_description' => !empty($done['applied']) ? 'VIP applied' : 'VIP recorded',
            ]));
            return;
        }
        if ($gateway === 'rejected' && $canReject) {
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
    }

    private function handleTopFixedXlFreedomPayResult(
        FreedomPayClient $fp,
        string $scriptName,
        string $pgOrderId,
        string $pgPaymentId,
        string $pgAmount,
        int $pgResult,
        bool $canReject
    ): void {
        $params = FreedomPayClient::requestParams();
        $pgCurrency = isset($params['pg_currency']) ? (string) $params['pg_currency'] : null;
        $done = (new TopFixedXlService())->handleGatewayResult(
            $pgOrderId,
            $pgPaymentId,
            $pgAmount,
            $pgResult,
            $canReject,
            $pgCurrency
        );

        $gateway = (string) ($done['gateway'] ?? 'error');
        if ($gateway === 'ok') {
            $this->xmlOut($fp->xmlResponse($scriptName, [
                'pg_status' => 'ok',
                'pg_description' => !empty($done['applied']) ? 'Top Fixed XL applied' : 'Top Fixed XL recorded',
            ]));
            return;
        }
        if ($gateway === 'rejected' && $canReject) {
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
    }

    private function redirectTopFixedReturn(string $pgOrderId): void
    {
        $order = (new TopFixedOrder())->findByPgOrderId($pgOrderId);
        if (!$order || (int) ($order['user_id'] ?? 0) !== (int) Auth::id()) {
            $_SESSION['error'] = t('top_fixed.err_not_found');
            $this->redirect('/profile?tab=lots');
            return;
        }

        $status = (string) ($order['status'] ?? '');
        $until = TopFixedPolicy::formatUntil(isset($order['ends_at']) ? (string) $order['ends_at'] : null);
        $category = (string) ($order['category'] ?? '');
        $running = TopFixedPolicy::windowActive(
            isset($order['starts_at']) ? (string) $order['starts_at'] : null,
            isset($order['ends_at']) ? (string) $order['ends_at'] : null
        );
        if (($status === TopFixedPolicy::STATUS_APPLIED || $status === TopFixedPolicy::STATUS_EXPIRED) && $running) {
            $_SESSION['flash'] = t('top_fixed.active', ['until' => $until, 'category' => $category]);
        } elseif ($status === TopFixedPolicy::STATUS_APPLIED || $status === TopFixedPolicy::STATUS_EXPIRED) {
            $_SESSION['flash'] = t('top_fixed.expired');
        } elseif ($status === TopFixedPolicy::STATUS_PAID || $status === TopFixedPolicy::STATUS_APPLY_FAILED) {
            $_SESSION['flash'] = t('top_fixed.paid_waiting');
        } elseif ($status === TopFixedPolicy::STATUS_PENDING) {
            $_SESSION['flash'] = t('top_fixed.payment_pending');
        } else {
            $_SESSION['error'] = t('top_fixed.payment_failed');
        }
        $this->redirect('/profile?tab=lots');
    }

    private function handleTopFixedFreedomPayResult(
        FreedomPayClient $fp,
        string $scriptName,
        string $pgOrderId,
        string $pgPaymentId,
        string $pgAmount,
        int $pgResult,
        bool $canReject
    ): void {
        $params = FreedomPayClient::requestParams();
        $pgCurrency = isset($params['pg_currency']) ? (string) $params['pg_currency'] : null;
        $done = (new TopFixedService())->handleGatewayResult(
            $pgOrderId,
            $pgPaymentId,
            $pgAmount,
            $pgResult,
            $canReject,
            $pgCurrency
        );

        $gateway = (string) ($done['gateway'] ?? 'error');
        if ($gateway === 'ok') {
            $this->xmlOut($fp->xmlResponse($scriptName, [
                'pg_status' => 'ok',
                'pg_description' => !empty($done['applied']) ? 'Top Fixed applied' : 'Top Fixed recorded',
            ]));
            return;
        }
        if ($gateway === 'rejected' && $canReject) {
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
    }

    private function handleHighlightFreedomPayResult(
        FreedomPayClient $fp,
        string $scriptName,
        string $pgOrderId,
        string $pgPaymentId,
        string $pgAmount,
        int $pgResult,
        bool $canReject
    ): void {
        $params = FreedomPayClient::requestParams();
        $pgCurrency = isset($params['pg_currency']) ? (string) $params['pg_currency'] : null;
        $done = (new HighlightService())->handleGatewayResult(
            $pgOrderId,
            $pgPaymentId,
            $pgAmount,
            $pgResult,
            $canReject,
            $pgCurrency
        );

        $gateway = (string) ($done['gateway'] ?? 'error');
        if ($gateway === 'ok') {
            $this->xmlOut($fp->xmlResponse($scriptName, [
                'pg_status' => 'ok',
                'pg_description' => !empty($done['applied']) ? 'Highlight applied' : 'Highlight recorded',
            ]));
            return;
        }
        if ($gateway === 'rejected' && $canReject) {
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
    }

    private function xmlOut(string $xml): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        echo $xml;
        exit;
    }
}
