<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ActivityLogger;
use App\Helpers\ProductHelper;
use App\Models\Favorite;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\Wallet;
use App\Services\Cart;

class CheckoutController extends Controller
{
    public function show(string $id): void
    {
        Auth::requireLogin();

        $productId = (int) $id;
        $product = (new Product())->findWithSeller($productId);
        if (!$product) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('product.not_found')]);
            return;
        }

        $status = (string) ($product['status'] ?? '');
        if ($status === 'reserved') {
            $pending = (new \App\Models\Payment())->findPendingByProductBuyer($productId, Auth::id());
            if ($pending && !empty($pending['order_id'])) {
                $_SESSION['flash'] = t('checkout.payment_pending');
                $this->redirect('/orders/' . (int) $pending['order_id']);
                return;
            }
            $_SESSION['flash'] = t('checkout.unavailable');
            $this->redirect('/product/' . $productId);
            return;
        }

        if ($status !== 'active') {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('product.not_found')]);
            return;
        }

        if (!ProductHelper::isPurchasable($product)) {
            $_SESSION['flash'] = t('checkout.not_for_sale');
            $this->redirect('/product/' . $productId);
            return;
        }

        if ((int) $product['user_id'] === Auth::id()) {
            $_SESSION['flash'] = t('checkout.own_product');
            $this->redirect('/product/' . $productId);
            return;
        }

        $n = new Notification();
        $walletBalance = (new Wallet())->balance(Auth::id());
        $this->view('checkout/index', [
            'title' => t('checkout.title'),
            'currentNav' => '',
            'items' => [$product],
            'item' => $product,
            'fromCart' => false,
            'total' => (int) ($product['price'] ?? 0),
            'walletBalance' => $walletBalance,
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'isFavorite' => (new Favorite())->isFavorite(Auth::id(), $productId),
            'search' => '',
            'error' => $_SESSION['checkout_error'] ?? null,
            'checkoutPayUrl' => ProductHelper::url('/checkout/' . $productId . '/pay'),
            'cancelUrl' => ProductHelper::url('/product/' . $productId),
        ]);
        unset($_SESSION['checkout_error']);
    }

    public function cartShow(): void
    {
        Auth::requireLogin();

        $items = Cart::items();
        if ($items === []) {
            $_SESSION['flash'] = t('checkout.cart_empty');
            $this->redirect('/cart');
            return;
        }

        $total = 0;
        foreach ($items as $item) {
            $total += (int) ($item['price'] ?? 0);
        }

        $n = new Notification();
        $walletBalance = (new Wallet())->balance(Auth::id());
        $this->view('checkout/index', [
            'title' => t('checkout.title'),
            'currentNav' => 'cart',
            'items' => $items,
            'item' => $items[0],
            'fromCart' => true,
            'total' => $total,
            'walletBalance' => $walletBalance,
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'isFavorite' => false,
            'search' => '',
            'error' => $_SESSION['checkout_error'] ?? null,
            'checkoutPayUrl' => ProductHelper::url('/checkout/cart/pay'),
            'cancelUrl' => ProductHelper::url('/cart'),
        ]);
        unset($_SESSION['checkout_error']);
    }

    public function pay(string $id): void
    {
        Auth::requireLogin();

        $productId = (int) $id;
        $method = (string) ($_POST['payment_method'] ?? $_POST['payment_method'] ?? 'card');
        $delivery = (string) ($_POST['delivery_method'] ?? $_POST['delivery_method'] ?? 'kazpost');

        $result = (new Order())->createEscrow($productId, Auth::id(), $method, $delivery);

        if (!$result['ok']) {
            ActivityLogger::warning('order.pay', $result['error'] ?? 'Ошибка оплаты', 'product', $productId, [
                'method' => $method,
            ]);
            $_SESSION['checkout_error'] = $result['error'] ?? t('checkout.payment_failed');
            $this->redirect('/checkout/' . $productId);
            return;
        }

        Cart::remove($productId);

        if (!empty($result['redirect_url'])) {
            ActivityLogger::info('order.pay', 'Редирект на FreedomPay, заказ #' . (int) $result['order_id'], 'order', (int) $result['order_id'], [
                'product_id' => $productId,
                'method' => $method,
                'delivery' => $delivery,
            ]);
            $this->redirect((string) $result['redirect_url']);
            return;
        }

        ActivityLogger::info('order.pay', 'Оплачена сделка #' . (int) $result['order_id'], 'order', (int) $result['order_id'], [
            'product_id' => $productId,
            'method' => $method,
            'delivery' => $delivery,
        ]);
        $this->redirectAfterPay($result, [$productId]);
    }

    public function cartPay(): void
    {
        Auth::requireLogin();

        $items = Cart::items();
        if ($items === []) {
            $_SESSION['flash'] = t('checkout.cart_empty');
            $this->redirect('/cart');
            return;
        }

        $method = (string) ($_POST['payment_method'] ?? $_POST['payment_method'] ?? 'card');
        $delivery = (string) ($_POST['delivery_method'] ?? $_POST['delivery_method'] ?? 'kazpost');

        $result = (new Order())->createEscrowCart($items, Auth::id(), $method, $delivery);

        if (!$result['ok']) {
            ActivityLogger::warning('order.pay_cart', $result['error'] ?? 'Ошибка оплаты корзины', 'cart', null, [
                'method' => $method,
                'count' => count($items),
            ]);
            $_SESSION['checkout_error'] = $result['error'] ?? t('checkout.payment_failed');
            $this->redirect('/checkout/cart');
            return;
        }

        foreach ($items as $item) {
            Cart::remove((int) $item['id']);
        }

        if (!empty($result['redirect_url'])) {
            ActivityLogger::info('order.pay_cart', 'Редирект на FreedomPay, заказ #' . (int) $result['order_id'], 'order', (int) $result['order_id'], [
                'method' => $method,
                'delivery' => $delivery,
                'count' => count($items),
            ]);
            $this->redirect((string) $result['redirect_url']);
            return;
        }

        $orderIds = $result['order_ids'] ?? [(int) $result['order_id']];
        ActivityLogger::info('order.pay_cart', 'Оплачено сделок: ' . count($orderIds), 'order', (int) $result['order_id'], [
            'method' => $method,
            'delivery' => $delivery,
            'order_ids' => $orderIds,
        ]);

        if (count($orderIds) > 1) {
            if ($this->allDigital($items)) {
                $_SESSION['flash'] = t('checkout.success_digital');
                $this->redirect('/digital');
                return;
            }
            $_SESSION['flash'] = t('checkout.cart_paid', ['count' => count($orderIds)]);
            $this->redirect('/orders');
            return;
        }

        $this->redirectAfterPay($result, array_map(static fn ($row) => (int) $row['id'], $items));
    }

    public function success(string $id): void
    {
        Auth::requireLogin();
        $this->redirectAfterPay(['ok' => true, 'order_id' => (int) $id], []);
    }

    /** @param list<int> $productIds */
    private function redirectAfterPay(array $result, array $productIds): void
    {
        $orderId = (int) ($result['order_id'] ?? 0);
        $order = $orderId > 0 ? (new Order())->find($orderId) : null;
        $productId = (int) ($order['product_id'] ?? ($productIds[0] ?? 0));
        $product = $productId > 0 ? (new Product())->find($productId) : null;
        if ($product && ProductHelper::isDigitalListing($product)) {
            $_SESSION['flash'] = t('checkout.success_digital');
            $this->redirect('/digital/' . $productId . '/watch');
            return;
        }
        if ($orderId > 0) {
            $this->redirect('/orders/' . $orderId);
            return;
        }
        $this->redirect('/orders');
    }

    /** @param list<array<string, mixed>> $items */
    private function allDigital(array $items): bool
    {
        if ($items === []) {
            return false;
        }
        foreach ($items as $item) {
            if (!ProductHelper::isDigitalListing($item)) {
                return false;
            }
        }
        return true;
    }
}
