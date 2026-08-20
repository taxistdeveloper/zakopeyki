<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BusinessPackageService;
use App\Services\BusinessUpgradeService;
use App\Services\PersonalLimitService;

class BusinessController extends Controller
{
    public function upgradeForm(): void
    {
        Auth::requireLogin();
        $users = new User();
        $user = $users->find(Auth::id());
        if ($user) {
            Auth::refresh($user);
        }

        $limit = (new PersonalLimitService())->snapshot($user ?: Auth::user());
        $upgrade = new BusinessUpgradeService();
        $pending = $upgrade->pendingForUser(Auth::id());
        $latest = $upgrade->latestForUser(Auth::id());
        $n = new Notification();

        $this->view('business/upgrade', [
            'title' => t('business.upgrade_title'),
            'currentNav' => 'profile',
            'user' => $user ?: Auth::user(),
            'limit' => $limit,
            'pending' => $pending,
            'latest' => $latest,
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function upgradeSubmit(): void
    {
        Auth::requireLogin();
        $result = (new BusinessUpgradeService())->submit(Auth::id(), [
            'entity_type' => (string) ($_POST['entity_type'] ?? ''),
            'business_name' => (string) ($_POST['business_name'] ?? ''),
            'bin' => (string) ($_POST['bin'] ?? ''),
            'phone' => (string) ($_POST['phone'] ?? ''),
            'address' => (string) ($_POST['address'] ?? ''),
        ], $_FILES['docs'] ?? []);

        if (!$result['ok']) {
            $_SESSION['error'] = $result['error'] ?? t('business.err_generic');
            $this->redirect('/business/upgrade');
        }

        $fresh = (new User())->find(Auth::id());
        if ($fresh) {
            Auth::refresh($fresh);
        }

        $_SESSION['flash'] = t('business.flash_upgrade_submitted');
        $this->redirect('/business/upgrade');
    }

    public function packageIndex(): void
    {
        Auth::requireLogin();
        $users = new User();
        $user = $users->find(Auth::id());
        if ($user) {
            Auth::refresh($user);
        }

        $pkgService = new BusinessPackageService();
        $limit = (new PersonalLimitService())->snapshot($user ?: Auth::user());
        $n = new Notification();

        $this->view('business/package', [
            'title' => t('business.package_title'),
            'currentNav' => 'profile',
            'user' => $user ?: Auth::user(),
            'limit' => $limit,
            'packages' => $pkgService->catalog(),
            'subscription' => $pkgService->activeSubscription(Auth::id()),
            'isBusiness' => $pkgService->isBusinessVerified($user ?: Auth::user()),
            'walletBalance' => (new Wallet())->balance(Auth::id()),
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function packagePurchase(string $id): void
    {
        Auth::requireLogin();
        $result = (new BusinessPackageService())->purchase(Auth::id(), (int) $id);
        if (!$result['ok']) {
            $_SESSION['error'] = $result['error'] ?? t('business.err_generic');
            $this->redirect('/business/package');
        }
        $_SESSION['flash'] = t('business.flash_package_activated');
        $this->redirect('/business/package');
    }
}
