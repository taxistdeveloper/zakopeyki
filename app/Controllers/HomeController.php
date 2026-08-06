<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ChangelogHelper;
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
            'changelog' => ChangelogHelper::load(),
        ]);
    }

    public function about(): void
    {
        $this->view('about/index', [
            'title' => t('about.title'),
            'currentNav' => 'about',
        ]);
    }

    public function offer(): void
    {
        $this->view('legal/offer', [
            'title' => t('offer.title'),
            'currentNav' => 'about',
        ]);
    }

    public function privacy(): void
    {
        $this->view('legal/document', [
            'title' => t('privacy.title'),
            'currentNav' => 'privacy',
            'docKey' => 'privacy',
            'sectionIds' => ['s1', 's2', 's3', 's4', 's5', 's6', 's7', 's8', 's9'],
        ]);
    }

    public function dataPolicy(): void
    {
        $this->view('legal/document', [
            'title' => t('data_policy.title'),
            'currentNav' => 'data_policy',
            'docKey' => 'data_policy',
            'sectionIds' => ['s1', 's2', 's3', 's4', 's5', 's6', 's7'],
        ]);
    }
}
