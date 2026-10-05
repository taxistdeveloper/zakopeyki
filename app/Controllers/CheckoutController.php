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
use App\Services\Delivery\BuyerPointBService;

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

        $available = ProductHelper::availableQuantity($product);
        $qty = ProductHelper::clampBuyQuantity($_GET['qty'] ?? 1, $available);
        if ($qty < 1) {
            $_SESSION['flash'] = t('checkout.unavailable');
            $this->redirect('/product/' . $productId);
            return;
        }
        $product['buy_qty'] = $qty;

        $n = new Notification();
        $walletBalance = (new Wallet())->balance(Auth::id());
        $dealMode = $this->resolveDealMode((string) ($_GET['deal'] ?? 'escrow'), $product);
        $pointB = new BuyerPointBService();
        $deliveryMethods = $pointB->availableDeliveryMethods(
            $productId,
            ProductHelper::isDigitalListing($product)
        );
        $this->view('checkout/index', [
            'title' => t('checkout.title'),
            'currentNav' => '',
            'items' => [$product],
            'item' => $product,
            'fromCart' => false,
            'dealMode' => $dealMode,
            'total' => ProductHelper::lineAmount($product, $qty),
            'walletBalance' => $walletBalance,
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'isFavorite' => (new Favorite())->isFavorite(Auth::id(), $productId),
            'search' => '',
            'error' => $_SESSION['checkout_error'] ?? null,
            'checkoutPayUrl' => ProductHelper::url('/checkout/' . $productId . '/pay'),
            'cancelUrl' => ProductHelper::url('/product/' . $productId),
            'deliveryMethods' => $deliveryMethods,
            'cdekAvailable' => in_array('cdek', $deliveryMethods, true),
            'pointBErrors' => $_SESSION['checkout_point_b_errors'] ?? null,
            'pointBOld' => $_SESSION['checkout_point_b_old'] ?? null,
        ]);
        unset($_SESSION['checkout_error'], $_SESSION['checkout_point_b_errors'], $_SESSION['checkout_point_b_old']);
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
            $total += (int) ($item['line_total'] ?? ProductHelper::lineAmount($item, (int) ($item['cart_qty'] ?? 1)));
        }

        $n = new Notification();
        $walletBalance = (new Wallet())->balance(Auth::id());
        $pointB = new BuyerPointBService();
        // Cart: CDEK available if ALL physical items support it (or intersection).
        $deliveryMethods = ['kazpost', 'courier', 'other'];
        $cdekOk = true;
        $pickupOk = true;
        foreach ($items as $row) {
            if (ProductHelper::isDigitalListing($row)) {
                continue;
            }
            $m = $pointB->availableDeliveryMethods((int) $row['id']);
            if (!in_array('cdek', $m, true)) {
                $cdekOk = false;
            }
            if (!in_array('pickup', $m, true)) {
                $pickupOk = false;
            }
        }
        if ($cdekOk) {
            array_unshift($deliveryMethods, 'cdek');
        }
        if ($pickupOk) {
            array_unshift($deliveryMethods, 'pickup');
        }
        $deliveryMethods = array_values(array_unique($deliveryMethods));

        $this->view('checkout/index', [
            'title' => t('checkout.title'),
            'currentNav' => 'cart',
            'items' => $items,
            'item' => $items[0],
            'fromCart' => true,
            'dealMode' => 'escrow',
            'total' => $total,
            'walletBalance' => $walletBalance,
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'isFavorite' => false,
            'search' => '',
            'error' => $_SESSION['checkout_error'] ?? null,
            'checkoutPayUrl' => ProductHelper::url('/checkout/cart/pay'),
            'cancelUrl' => ProductHelper::url('/cart'),
            'deliveryMethods' => $deliveryMethods,
            'cdekAvailable' => in_array('cdek', $deliveryMethods, true),
            'pointBErrors' => $_SESSION['checkout_point_b_errors'] ?? null,
            'pointBOld' => $_SESSION['checkout_point_b_old'] ?? null,
        ]);
        unset($_SESSION['checkout_error'], $_SESSION['checkout_point_b_errors'], $_SESSION['checkout_point_b_old']);
    }

    public function pay(string $id): void
    {
        Auth::requireLogin();

        $productId = (int) $id;
        $method = (string) ($_POST['payment_method'] ?? $_POST['payment_method'] ?? 'card');
        $delivery = (string) ($_POST['delivery_method'] ?? $_POST['delivery_method'] ?? 'kazpost');
        $dealMode = (string) ($_POST['deal_mode'] ?? 'escrow');
        if (!in_array($dealMode, ['escrow', 'direct'], true)) {
            $dealMode = 'escrow';
        }

        $product = (new Product())->find($productId);
        if ($dealMode === 'direct' && (!$product || !ProductHelper::supportsDirectBuy($product))) {
            $dealMode = 'escrow';
        }

        $qty = max(1, (int) ($_POST['quantity'] ?? 1));

        $pointBSvc = new BuyerPointBService();
        $pointBCheck = $pointBSvc->validateForCheckout($productId, Auth::id(), $delivery, $_POST);
        if (!$pointBCheck['ok']) {
            $_SESSION['checkout_error'] = $pointBCheck['error'] ?? t('checkout.cdek_point_b_invalid');
            $_SESSION['checkout_point_b_errors'] = [
                'field' => $pointBCheck['field'] ?? null,
                'error_code' => $pointBCheck['error_code'] ?? null,
                'missing_fields' => $pointBCheck['missing_fields'] ?? [],
            ];
            $_SESSION['checkout_point_b_old'] = $this->pointBPostedFields($_POST);
            $qs = [];
            if ($qty > 1) {
                $qs['qty'] = $qty;
            }
            if ($dealMode === 'direct') {
                $qs['deal'] = 'direct';
            }
            $redirect = '/checkout/' . $productId . ($qs ? ('?' . http_build_query($qs)) : '');
            $this->redirect($redirect);
            return;
        }

        $result = (new Order())->createEscrow(
            $productId,
            Auth::id(),
            $method,
            $delivery,
            $dealMode,
            $qty,
            $pointBCheck['snapshot'] ?? null
        );

        if (!$result['ok']) {
            ActivityLogger::warning('order.pay', $result['error'] ?? 'Ошибка оплаты', 'product', $productId, [
                'method' => $method,
            ]);
            $_SESSION['checkout_error'] = $result['error'] ?? t('checkout.payment_failed');
            $qs = [];
            if ($qty > 1) {
                $qs['qty'] = $qty;
            }
            if ($dealMode === 'direct') {
                $qs['deal'] = 'direct';
            }
            $redirect = '/checkout/' . $productId . ($qs ? ('?' . http_build_query($qs)) : '');
            $this->redirect($redirect);
            return;
        }

        Cart::remove($productId);

        if (!empty($result['redirect_url'])) {
            ActivityLogger::info('order.pay', 'Редирект на оплату картой, заказ #' . (int) $result['order_id'], 'order', (int) $result['order_id'], [
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

        $pointBSvc = new BuyerPointBService();
        $snapshot = null;
        if ($delivery === 'cdek') {
            // Validate against first physical item that supports CDEK.
            $targetId = 0;
            foreach ($items as $row) {
                if (ProductHelper::isDigitalListing($row)) {
                    continue;
                }
                if ($pointBSvc->isCdekSelectable((int) $row['id'])) {
                    $targetId = (int) $row['id'];
                    break;
                }
            }
            if ($targetId <= 0) {
                $_SESSION['checkout_error'] = t('checkout.cdek_not_available');
                $this->redirect('/checkout/cart');
                return;
            }
            $pointBCheck = $pointBSvc->validateForCheckout($targetId, Auth::id(), $delivery, $_POST);
            if (!$pointBCheck['ok']) {
                $_SESSION['checkout_error'] = $pointBCheck['error'] ?? t('checkout.cdek_point_b_invalid');
                $_SESSION['checkout_point_b_errors'] = [
                    'field' => $pointBCheck['field'] ?? null,
                    'error_code' => $pointBCheck['error_code'] ?? null,
                    'missing_fields' => $pointBCheck['missing_fields'] ?? [],
                ];
                $_SESSION['checkout_point_b_old'] = $this->pointBPostedFields($_POST);
                $this->redirect('/checkout/cart');
                return;
            }
            $snapshot = $pointBCheck['snapshot'] ?? null;
        }

        $result = (new Order())->createEscrowCart($items, Auth::id(), $method, $delivery, $snapshot);

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
            ActivityLogger::info('order.pay_cart', 'Редирект на оплату картой, заказ #' . (int) $result['order_id'], 'order', (int) $result['order_id'], [
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
            if (($order['deal_mode'] ?? '') === 'direct') {
                $_SESSION['flash'] = t('checkout.success_direct');
            }
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

    /** @param array<string, mixed>|null $product */
    private function resolveDealMode(string $deal, ?array $product): string
    {
        if ($deal !== 'direct') {
            return 'escrow';
        }
        if (!$product || !ProductHelper::supportsDirectBuy($product)) {
            return 'escrow';
        }
        return 'direct';
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function pointBPostedFields(array $post): array
    {
        $keys = [
            'delivery_mode', 'name', 'phone', 'email', 'country', 'region', 'city',
            'street', 'building', 'apartment', 'postal_code', 'pvz_code', 'pvz_name',
            'cdek_city_code', 'notes', 'recipient_name', 'recipient_phone', 'recipient_city',
        ];
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $post)) {
                $out[$k] = $post[$k];
            }
        }
        return $out;
    }
}
