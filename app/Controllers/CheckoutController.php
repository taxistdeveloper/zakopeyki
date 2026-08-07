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
            'item' => $product,
            'walletBalance' => $walletBalance,
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'isFavorite' => (new Favorite())->isFavorite(Auth::id(), $productId),
            'search' => '',
            'error' => $_SESSION['checkout_error'] ?? null,
        ]);
        unset($_SESSION['checkout_error']);
    }

    public function pay(string $id): void
    {
        Auth::requireLogin();

        $productId = (int) $id;
        $method = (string) ($_POST['payment_method'] ?? 'card');
        $delivery = (string) ($_POST['delivery_method'] ?? 'kazpost');

        $result = (new Order())->createEscrow($productId, Auth::id(), $method, $delivery);

        if (!$result['ok']) {
            ActivityLogger::warning('order.pay', $result['error'] ?? 'Ошибка оплаты', 'product', $productId, [
                'method' => $method,
            ]);
            $_SESSION['checkout_error'] = $result['error'] ?? t('checkout.payment_failed');
            $this->redirect('/checkout/' . $productId);
            return;
        }

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
        $this->redirect('/orders/' . (int) $result['order_id']);
    }

    public function success(string $id): void
    {
        Auth::requireLogin();
        $this->redirect('/orders/' . (int) $id);
    }
}
