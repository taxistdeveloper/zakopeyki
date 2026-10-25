<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Product;
use App\Services\Boost\BoostService;
use App\Services\Highlight\HighlightService;
use App\Services\TopFixed\TopFixedService;
use App\Services\TopFixedXl\TopFixedXlService;
use App\Services\Vip\VipService;

class PromoteController extends Controller
{
    public function show(string $id): void
    {
        Auth::requireLogin();

        $productId = (int) $id;
        $userId = (int) Auth::id();
        $product = (new Product())->find($productId);

        if (!$product || (int) ($product['user_id'] ?? 0) !== $userId) {
            $_SESSION['error'] = t('profile.promote_forbidden');
            $this->redirect('/profile?tab=lots');
            return;
        }

        $boostUi = (new BoostService())->cardState($userId, $product);
        $highlightUi = (new HighlightService())->cardState($userId, $product);
        $topFixedUi = (new TopFixedService())->cardState($userId, $product);
        $topFixedXlUi = (new TopFixedXlService())->cardState($userId, $product);
        $vipUi = (new VipService())->cardState($userId, $product);

        $this->view('profile/promote', [
            'title' => t('profile.promote_page_title'),
            'product' => $product,
            'boostUi' => $boostUi,
            'highlightUi' => $highlightUi,
            'topFixedUi' => $topFixedUi,
            'topFixedXlUi' => $topFixedXlUi,
            'vipUi' => $vipUi,
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }
}
