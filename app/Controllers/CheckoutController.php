<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
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

        $product = (new Product())->findWithSeller((int) $id);
        if (!$product || ($product['status'] ?? '') !== 'active') {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('product.not_found')]);
            return;
        }

        if (!ProductHelper::isPurchasable($product)) {
            $_SESSION['flash'] = t('checkout.not_for_sale');
            $this->redirect('/product/' . (int) $id);
            return;
        }

        if ((int) $product['user_id'] === Auth::id()) {
            $_SESSION['flash'] = t('checkout.own_product');
            $this->redirect('/product/' . (int) $id);
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
            'isFavorite' => (new Favorite())->isFavorite(Auth::id(), (int) $id),
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
            $_SESSION['checkout_error'] = $result['error'] ?? t('checkout.payment_failed');
            $this->redirect('/checkout/' . $productId);
            return;
        }

        $this->redirect('/orders/' . (int) $result['order_id']);
    }

    public function success(string $id): void
    {
        Auth::requireLogin();
        $this->redirect('/orders/' . (int) $id);
    }
}
