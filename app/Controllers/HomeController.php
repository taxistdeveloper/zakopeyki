<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Favorite;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Story;
use App\Models\Stream;
use App\Models\User;

class HomeController extends Controller
{
    public function index(): void
    {
        new User(); // ensure avatar_file column exists

        $productModel = new Product();
        $search = trim($_GET['q'] ?? '');

        $items = $productModel->allActive(null, $search ?: null);
        $storyGroups = (new Story())->activeGrouped();
        $streams = (new Stream())->allActive();

        $notifications = [];
        $unread = 0;
        $favoriteIds = [];
        if (Auth::check()) {
            $n = new Notification();
            $notifications = $n->forUser(Auth::id());
            $unread = $n->unreadCount(Auth::id());
            $favoriteIds = (new Favorite())->idsForUser(Auth::id());
        }

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $this->view('home/index', [
            'title' => t('home.title'),
            'currentNav' => 'home',
            'items' => $items,
            'storyGroups' => $storyGroups,
            'streams' => $streams,
            'search' => $search,
            'notifications' => $notifications,
            'unread' => $unread,
            'favoriteIds' => $favoriteIds,
            'flash' => $flash,
        ]);
    }
}
