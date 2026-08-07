<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Lang;
use App\Models\Notification;
use App\Services\Cart;

class CartController extends Controller
{
    public function index(): void
    {
        $n = new Notification();
        $notifications = [];
        $unread = 0;
        if (Auth::check()) {
            $notifications = $n->forUser(Auth::id());
            $unread = $n->unreadCount(Auth::id());
        }

        $items = Cart::items();
        $total = 0;
        foreach ($items as $item) {
            $total += (int) ($item['price'] ?? 0);
        }

        $this->view('cart/index', [
            'title' => Lang::get('cart.title'),
            'currentNav' => 'cart',
            'items' => $items,
            'total' => $total,
            'notifications' => $notifications,
            'unread' => $unread,
            'search' => '',
        ]);
    }

    public function toggle(string $id): void
    {
        $result = Cart::toggle((int) $id);

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            $this->json($result, $result['ok'] ? 200 : 422);
        }

        $_SESSION['flash'] = $result['ok']
            ? ($result['in_cart'] ? Lang::get('cart.added') : Lang::get('cart.removed'))
            : ($result['error'] ?? Lang::get('cart.error'));
        $this->redirect('/product/' . (int) $id);
    }

    public function remove(string $id): void
    {
        $result = Cart::remove((int) $id);

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            $this->json($result, 200);
        }

        $_SESSION['flash'] = Lang::get('cart.removed');
        $this->redirect('/cart');
    }

    public function clear(): void
    {
        Cart::clear();

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            $this->json(['ok' => true, 'in_cart' => false, 'count' => 0]);
        }

        $_SESSION['flash'] = Lang::get('cart.cleared');
        $this->redirect('/cart');
    }
}
