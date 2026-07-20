<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ProductHelper;
use App\Models\Favorite;
use App\Models\Notification;
use App\Models\Product;

class CatalogController extends Controller
{
    private array $pages = [
        'new' => ['titleKey' => 'catalog.heading_new', 'type' => 'new', 'nav' => 'new'],
        'used' => ['titleKey' => 'catalog.heading_used', 'type' => 'used', 'nav' => 'used'],
        'free' => ['titleKey' => 'catalog.heading_free', 'type' => 'free', 'nav' => 'free'],
        'exchange' => ['titleKey' => 'catalog.heading_exchange', 'type' => 'exchange', 'nav' => 'exchange'],
        'services' => ['titleKey' => 'catalog.heading_services', 'type' => 'service', 'nav' => 'services'],
        'courses' => ['titleKey' => 'catalog.heading_courses', 'type' => 'course', 'nav' => 'courses'],
    ];

    public function show(string $section): void
    {
        if (!isset($this->pages[$section])) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('catalog.not_found')]);
            return;
        }

        $page = $this->pages[$section];
        $type = $page['type'];
        $hasCategoryFilters = in_array($type, ProductHelper::PRODUCT_TYPES_WITH_CATEGORY, true);

        $categoryFilter = null;
        $selectedParent = '';
        $selectedChild = '';

        if ($hasCategoryFilters) {
            $selectedParent = trim((string) ($_GET['parent'] ?? ''));
            $selectedChild = trim((string) ($_GET['sub'] ?? ''));
            $tree = ProductHelper::PRODUCT_CATEGORY_TREE;

            if ($selectedParent !== '' && !isset($tree[$selectedParent])) {
                $selectedParent = '';
                $selectedChild = '';
            }
            if ($selectedParent !== '' && $selectedChild !== '') {
                if (!in_array($selectedChild, $tree[$selectedParent], true)) {
                    $selectedChild = '';
                } else {
                    $categoryFilter = ProductHelper::formatCategory($selectedParent, $selectedChild);
                }
            } elseif ($selectedParent !== '') {
                $categoryFilter = $selectedParent;
            }
        }

        $items = (new Product())->allActive($type, null, $categoryFilter);

        $notifications = [];
        $unread = 0;
        $favoriteIds = [];
        if (Auth::check()) {
            $n = new Notification();
            $notifications = $n->forUser(Auth::id());
            $unread = $n->unreadCount(Auth::id());
            $favoriteIds = (new Favorite())->idsForUser(Auth::id());
        }

        $this->view('catalog/index', [
            'title' => ProductHelper::label($type),
            'heading' => t($page['titleKey']),
            'currentNav' => $page['nav'],
            'section' => $section,
            'items' => $items,
            'hasCategoryFilters' => $hasCategoryFilters,
            'categoryTree' => ProductHelper::PRODUCT_CATEGORY_TREE,
            'selectedParent' => $selectedParent,
            'selectedChild' => $selectedChild,
            'notifications' => $notifications,
            'unread' => $unread,
            'favoriteIds' => $favoriteIds,
            'search' => '',
        ]);
    }
}
