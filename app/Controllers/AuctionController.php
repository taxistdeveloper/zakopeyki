<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Favorite;
use App\Models\Notification;
use App\Models\Product;

class AuctionController extends Controller
{
    public function index(): void
    {
        $items = (new Product())->allActive('auction');

        $notifications = [];
        $unread = 0;
        $favoriteIds = [];
        if (Auth::check()) {
            $n = new Notification();
            $notifications = $n->forUser(Auth::id());
            $unread = $n->unreadCount(Auth::id());
            $favoriteIds = (new Favorite())->idsForUser(Auth::id());
        }

        $this->view('auctions/index', [
            'title' => t('auctions.title'),
            'currentNav' => 'auctions',
            'items' => $items,
            'notifications' => $notifications,
            'unread' => $unread,
            'favoriteIds' => $favoriteIds,
            'search' => '',
        ]);
    }

    public function bid(string $id): void
    {
        Auth::requireLogin();
        $productId = (int) $id;
        $amount = (int) preg_replace('/\D/', '', $_POST['amount'] ?? '0');

        $result = (new Product())->placeBid($productId, Auth::id(), $amount);

        if ($result['ok']) {
            $product = (new Product())->find($productId);
            if ($product && (int) $product['user_id'] !== Auth::id()) {
                (new Notification())->createFor(
                    (int) $product['user_id'],
                    'Новая ставка ' . number_format($amount, 0, '', ' ') . ' ₸ на ваш лот «' . $product['title'] . '»'
                );
            }
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            $this->json($result, $result['ok'] ? 200 : 422);
        }

        $flash = $result['ok'] ? 'Ставка принята!' : ($result['error'] ?? 'Ошибка');
        $_SESSION['flash'] = $flash;
        $this->redirect('/product/' . $productId);
    }
}
