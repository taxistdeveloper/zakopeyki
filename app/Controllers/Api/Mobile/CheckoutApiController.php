<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Helpers\ActivityLogger;
use App\Helpers\ProductHelper;
use App\Models\Product;
use App\Models\Wallet;
use App\Services\Cart;
use App\Services\Delivery\BuyerPointBService;
use App\Services\EscrowService;
use App\Services\HalykEpay\Client as HalykClient;
use App\Services\MobileApi\Presenter;

/**
 * Корзина и оформление заказа в мобильном приложении (M-07).
 *
 * Корзина — та же App\Services\Cart, что на сайте (для вошедшего пользователя хранится в БД,
 * поэтому общая для сайта и приложения). Оформление — Order::createEscrow/createEscrowCart:
 * все суммы, сбор Safe Deal 3%, остатки, оплата кошельком или картой считаются только на сервере.
 * Доставка CDEK (выбор ПВЗ получателя) пока только на сайте.
 */
final class CheckoutApiController extends BaseMobileController
{
    // ---------------------------------------------------------------- корзина

    public function cart(): void
    {
        $this->useCart();
        $this->ok($this->cartPayload());
    }

    public function addToCart(): void
    {
        $this->useCart();
        $res = Cart::add((int) $this->input('product_id'), max(1, (int) ($this->input('qty', '1'))));
        if (empty($res['ok'])) {
            $this->fail('cart_error', 422, (string) ($res['error'] ?? self::message('validation_failed')));
        }
        $this->ok($this->cartPayload() + ['notice' => $res['notice'] ?? null]);
    }

    public function setQty(string $id): void
    {
        $this->useCart();
        $res = Cart::setQty((int) $id, (int) $this->input('qty', '1'));
        if (empty($res['ok'])) {
            $this->fail('cart_error', 422, (string) ($res['error'] ?? self::message('validation_failed')));
        }
        $this->ok($this->cartPayload() + ['notice' => $res['notice'] ?? null]);
    }

    public function removeFromCart(string $id): void
    {
        $this->useCart();
        Cart::remove((int) $id);
        $this->ok($this->cartPayload());
    }

    public function clearCart(): void
    {
        $this->useCart();
        Cart::clear();
        $this->ok($this->cartPayload());
    }

    // ---------------------------------------------------------------- оформление

    /**
     * GET /checkout/options?product_id=&qty=  (один товар)  или  ?source=cart
     * Доступные способы доставки, режимы сделки, способы оплаты и суммы (считает сервер).
     */
    public function options(): void
    {
        $uid = $this->useCart();
        $lines = $this->resolveLines();
        if ($lines === []) {
            $this->fail('checkout_empty', 422);
        }

        $single = count($lines) === 1 && $this->queryString('source') !== 'cart';
        $first = $lines[0]['product'];
        $directAllowed = $single && ProductHelper::supportsDirectBuy($first);

        $pointB = new BuyerPointBService();
        $methods = null;
        foreach ($lines as $line) {
            $m = $pointB->availableDeliveryMethods((int) $line['product']['id'], ProductHelper::isDigitalListing($line['product']));
            $methods = $methods === null ? $m : array_values(array_intersect($methods, $m));
        }
        $methods = $methods ?: ['kazpost'];

        $subtotal = array_sum(array_column($lines, 'amount'));
        $modes = [[
            'code' => 'escrow',
            'fee' => EscrowService::arbitrationFee($subtotal),
            'total' => EscrowService::buyerChargeTotal($subtotal),
        ]];
        if ($directAllowed) {
            $modes[] = ['code' => 'direct', 'fee' => 0, 'total' => $subtotal];
        }

        $halyk = new HalykClient();
        $simulated = (bool) ($GLOBALS['appConfig']['allow_simulated_payments'] ?? false);
        $this->ok([
            'items' => array_map(static fn (array $l): array => [
                'product' => Presenter::card($l['product']),
                'qty' => $l['qty'],
                'amount' => $l['amount'],
            ], $lines),
            'subtotal' => ['amount' => $subtotal, 'currency' => 'KZT'],
            'deal_modes' => $modes,
            'delivery_methods' => array_map(static fn (string $m): array => [
                'code' => $m,
                // ПВЗ получателя CDEK выбирается на сайте (B1.18)
                'available_in_app' => $m !== 'cdek',
            ], $methods),
            'payment_methods' => [
                ['code' => 'card', 'available' => $halyk->isConfigured() || $simulated, 'simulated' => !$halyk->isConfigured() && $simulated],
                ['code' => 'wallet', 'available' => true, 'balance' => (new Wallet())->balance($uid)],
            ],
            'inspection_hours' => EscrowService::INSPECT_HOURS,
        ]);
    }

    /**
     * POST /checkout {product_id, qty, deal_mode, delivery_method, payment_method}  или  {source: "cart", ...}
     * Ответ: order_ids + payment {status: paid|pending, payment_url?}.
     */
    public function checkout(): void
    {
        $uid = $this->useCart();
        $this->guardSiteOpen();
        $delivery = $this->input('delivery_method', 'kazpost');
        if ($delivery === 'cdek') {
            $this->fail('cdek_on_website', 422, null, ['delivery_method' => self::message('cdek_on_website')]);
        }
        if (!in_array($delivery, EscrowService::DELIVERY_METHODS, true)) {
            $this->fail('validation_failed', 422, null, ['delivery_method' => self::message('invalid_value')]);
        }
        $method = $this->input('payment_method', 'card');
        if (!in_array($method, ['card', 'wallet'], true)) {
            $this->fail('validation_failed', 422, null, ['payment_method' => self::message('invalid_value')]);
        }

        $orders = new \App\Models\Order();
        $fromCart = $this->input('source') === 'cart';
        if ($fromCart) {
            $items = Cart::items();
            if ($items === []) {
                $this->fail('checkout_empty', 422);
            }
            $result = $orders->createEscrowCart($items, $uid, $method, $delivery, null);
        } else {
            $productId = (int) $this->input('product_id');
            $dealMode = $this->input('deal_mode', 'escrow') === 'direct' ? 'direct' : 'escrow';
            $product = (new Product())->find($productId);
            if ($dealMode === 'direct' && (!$product || !ProductHelper::supportsDirectBuy($product))) {
                $dealMode = 'escrow';
            }
            $result = $orders->createEscrow($productId, $uid, $method, $delivery, $dealMode, max(1, (int) $this->input('qty', '1')), null);
        }

        if (empty($result['ok'])) {
            $this->fail('checkout_failed', 422, (string) ($result['error'] ?? self::message('checkout_failed')));
        }

        $orderIds = array_map('intval', $result['order_ids'] ?? [(int) $result['order_id']]);
        if ($fromCart) {
            foreach (Cart::items() as $item) {
                Cart::remove((int) $item['id']);
            }
        } else {
            Cart::remove((int) $this->input('product_id'));
        }

        $payment = ['status' => 'paid'];
        if (!empty($result['redirect_url'])) {
            $payment = ['status' => 'pending', 'payment_url' => $this->appPaymentUrl((string) $result['redirect_url'])];
        }
        ActivityLogger::info('order.pay', 'Оформление из приложения: ' . implode(',', $orderIds), 'order', $orderIds[0], [
            'method' => $method,
            'delivery' => $delivery,
            'channel' => 'mobile',
        ]);

        $this->ok(['order_ids' => $orderIds, 'payment' => $payment], [], 201);
    }

    // ---------------------------------------------------------------- helpers

    private function useCart(): int
    {
        $uid = $this->requireUserId();
        $this->guardSiteOpen();
        Cart::forUser($uid);

        return $uid;
    }

    /** @return array<string, mixed> */
    private function cartPayload(): array
    {
        $items = Cart::items();
        $total = 0;
        $out = [];
        foreach ($items as $row) {
            $total += (int) $row['line_total'];
            $out[] = [
                'product' => Presenter::card($row),
                'qty' => (int) $row['cart_qty'],
                'available' => ProductHelper::availableQuantity($row),
                'line_total' => ['amount' => (int) $row['line_total'], 'currency' => 'KZT'],
            ];
        }

        return [
            'items' => $out,
            'count' => array_sum(array_column($out, 'qty')),
            'total' => ['amount' => $total, 'currency' => 'KZT'],
        ];
    }

    /** @return list<array{product: array<string, mixed>, qty: int, amount: int}> */
    private function resolveLines(): array
    {
        if ($this->queryString('source') === 'cart') {
            return array_map(static fn (array $r): array => [
                'product' => $r,
                'qty' => (int) $r['cart_qty'],
                'amount' => (int) $r['line_total'],
            ], Cart::items());
        }
        $product = (new Product())->find($this->queryInt('product_id', 0, 0, PHP_INT_MAX));
        if (!$product || !ProductHelper::isPurchasable($product)) {
            $this->fail('checkout_failed', 422, t('checkout.not_for_sale'));
        }
        $qty = max(1, min($this->queryInt('qty', 1, 1, 999), ProductHelper::availableQuantity($product)));
        $seller = (new \App\Models\User())->find((int) $product['user_id']) ?? [];

        return [[
            'product' => $product + [
                'seller_name' => $seller['name'] ?? '',
                'seller_account_type' => $seller['account_type'] ?? null,
                'seller_business_status' => $seller['business_status'] ?? null,
                'seller_business_name' => $seller['business_name'] ?? null,
            ],
            'qty' => $qty,
            'amount' => ProductHelper::lineAmount($product, $qty),
        ]];
    }

    /** /payments/halyk/sim/{token} (web, нужна сессия) → /payments/halyk/app/{token} (приложение). */
    private function appPaymentUrl(string $redirect): string
    {
        if (preg_match('#/payments/halyk/sim/([a-f0-9]{32})#', $redirect, $m)) {
            return ProductHelper::absoluteUrl('/payments/halyk/app/' . $m[1]);
        }

        return ProductHelper::absoluteUrl($redirect);
    }
}
