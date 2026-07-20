<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Favorite;
use App\Models\Notification;
use App\Models\Product;

class ProductController extends Controller
{
    public function show(string $id): void
    {
        $product = (new Product())->findWithSeller((int) $id);
        if (!$product) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('product.not_found')]);
            return;
        }

        $bids = [];
        if ($product['type'] === 'auction') {
            $bids = (new Product())->recentBids((int) $id);
        }

        $notifications = [];
        $unread = 0;
        $isFavorite = false;
        if (Auth::check()) {
            $n = new Notification();
            $notifications = $n->forUser(Auth::id());
            $unread = $n->unreadCount(Auth::id());
            $isFavorite = (new Favorite())->isFavorite(Auth::id(), (int) $id);
        }

        $this->view('products/show', [
            'title' => $product['title'],
            'currentNav' => '',
            'item' => $product,
            'bids' => $bids,
            'notifications' => $notifications,
            'unread' => $unread,
            'isFavorite' => $isFavorite,
            'search' => '',
        ]);
    }
}
