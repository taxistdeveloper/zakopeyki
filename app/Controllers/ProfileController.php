<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ActivityLogger;
use App\Helpers\ProductHelper;
use App\Helpers\Totp;
use App\Models\Favorite;
use App\Models\Follow;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AMLService;
use App\Services\Boost\BoostService;
use App\Services\Highlight\HighlightService;
use App\Services\TopFixed\TopFixedService;
use App\Services\TopFixedXl\TopFixedXlService;
use App\Services\Vip\VipService;
use App\Services\BusinessPackageService;
use App\Services\Listing\ListingShippingService;
use App\Services\Listing\ServiceListingFeeService;
use App\Services\MicroTaskService;
use App\Services\Listing\ListingService;
use App\Services\PersonalLimitService;

class ProfileController extends Controller
{
    private const AVATAR_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const MAX_AVATAR = 3 * 1024 * 1024;

    public function index(): void
    {
        Auth::requireLogin();

        $dbUser = (new User())->find(Auth::id());
        if ($dbUser) {
            Auth::refresh($dbUser);
        }

        $tab = $_GET['tab'] ?? 'personal';
        if ($tab === 'author') {
            $this->redirect('/profile?tab=lots');
        }
        $allowed = ['personal', 'photo', 'bio', 'reviews', 'notifications', 'password', 'lots', 'my-listings', 'favorites', 'subscriptions', 'referral', 'business'];
        if (!in_array($tab, $allowed, true)) {
            $tab = 'personal';
        }

        $twoFactorSetup = null;
        if ($tab === 'password' && !empty($_SESSION['two_factor_setup'])) {
            $setup = $_SESSION['two_factor_setup'];
            if (is_array($setup) && (int) ($setup['user_id'] ?? 0) === Auth::id()) {
                $secret = (string) ($setup['secret'] ?? '');
                $issuer = (string) (($GLOBALS['appConfig']['name'] ?? 'zakopeyki.kz'));
                $account = (string) (($dbUser ?: Auth::user())['email'] ?? '');
                $uri = Totp::provisioningUri($secret, $account, $issuer);
                $twoFactorSetup = [
                    'secret' => $secret,
                    'qr' => Totp::qrImageUrl($uri),
                ];
            }
        }

        $recoveryCodes = $_SESSION['two_factor_recovery_codes'] ?? null;
        unset($_SESSION['two_factor_recovery_codes']);

        $editProduct = null;
        $editingGig = false;
        $editGigCategoryId = 0;
        $editGigCategoryPath = [];
        $microSvc = MicroTaskService::make();
        if ($tab === 'lots' && !empty($_GET['edit_gig']) && Auth::canAccessGigs()) {
            $gigRow = $microSvc->findOwned((int) $_GET['edit_gig'], Auth::id());
            if ($gigRow && (string) $gigRow['status'] === 'open') {
                $editProduct = $this->gigAsLotForm($gigRow);
                $editingGig = true;
                $editGigCategoryId = (int) $gigRow['category_id'];
                $editGigCategoryPath = $microSvc->categoryPath($editGigCategoryId);
            } elseif ($gigRow) {
                $_SESSION['error'] = t('gigs.err_edit_status');
            } else {
                $_SESSION['error'] = t('gigs.err_not_found');
            }
        } elseif ($tab === 'lots' && !empty($_GET['edit'])) {
            $candidate = (new Product())->find((int) $_GET['edit']);
            if ($candidate && (int) $candidate['user_id'] === Auth::id() && ($candidate['type'] ?? '') !== 'course') {
                $editProduct = $candidate;
            }
        }

        $n = new Notification();
        $notifications = $n->forUser(Auth::id());
        $unread = $n->unreadCount(Auth::id());

        $favorites = (new Favorite())->forUser(Auth::id());
        $favoriteIds = array_map(static fn ($p) => (int) $p['id'], $favorites);

        $followingUsers = [];
        $followerUsers = [];
        $followingIds = [];
        if ($tab === 'subscriptions') {
            $followModel = new Follow();
            $followingUsers = $followModel->followingUsers(Auth::id());
            $followerUsers = $followModel->followerUsers(Auth::id());
            $followingIds = array_map(static fn ($u) => (int) $u['id'], $followingUsers);
        }

        $reviewsModel = new Review();
        $reviews = $tab === 'reviews' ? $reviewsModel->forSubject(Auth::id()) : [];
        $reviewStats = $reviewsModel->statsFor(Auth::id());

        $profileUser = $dbUser ?: Auth::user();
        $usersModel = new User();
        $referralUrl = $usersModel->referralUrlFor($profileUser ?: ['id' => Auth::id()]);
        $referralCode = $usersModel->referralCodeFor($profileUser ?: ['id' => Auth::id()]);
        $referralCount = $tab === 'referral' ? $usersModel->countReferrals(Auth::id()) : 0;
        $referralUsers = $tab === 'referral' ? $usersModel->referrals(Auth::id()) : [];

        $limitService = new PersonalLimitService();
        $accountLimit = $limitService->snapshot($profileUser ?: Auth::user());
        $pkgService = new BusinessPackageService();
        $businessSubscription = $pkgService->activeSubscription(Auth::id());
        $packageMaxPhotos = $pkgService->maxPhotosForUser(Auth::id());
        $businessSalesEnabled = $pkgService->isSalesEnabled();
        $businessFeaturesEnabled = $pkgService->isFeaturesEnabled();
        $businessAiAddonSalesFlags = $pkgService->aiAddonSalesFlags();
        $businessAiAddonSalesEnabled = $pkgService->isAiAddonSalesEnabled();
        $businessPayments = $businessSubscription
            ? $pkgService->paymentHistory(Auth::id(), 5)
            : [];
        $businessAiQuotas = $businessSubscription
            ? $pkgService->aiQuotaSnapshot(Auth::id())
            : [];

        $listingPackagings = (new ListingShippingService())->packagings();
        $listingShipping = null;
        if ($editProduct && empty($editingGig)) {
            $listingShipping = (new ListingShippingService())->findForProduct((int) $editProduct['id']);
        }

        $products = (new Product())->byUser(Auth::id());
        $boostStates = $tab === 'my-listings'
            ? (new BoostService())->statesForProducts((int) Auth::id(), $products)
            : [];
        $highlightStates = $tab === 'my-listings'
            ? (new HighlightService())->statesForProducts((int) Auth::id(), $products)
            : [];
        $topFixedStates = $tab === 'my-listings'
            ? (new TopFixedService())->statesForProducts((int) Auth::id(), $products)
            : [];
        $topFixedXlStates = $tab === 'my-listings'
            ? (new TopFixedXlService())->statesForProducts((int) Auth::id(), $products)
            : [];
        $vipStates = $tab === 'my-listings'
            ? (new VipService())->statesForProducts((int) Auth::id(), $products)
            : [];

        $this->view('profile/index', [
            'title' => t('profile.title'),
            'currentNav' => $tab === 'my-listings' ? 'my_listings' : 'profile',
            'tab' => $tab,
            'user' => $profileUser,
            'products' => $products,
            'boostStates' => $boostStates,
            'highlightStates' => $highlightStates,
            'topFixedStates' => $topFixedStates,
            'topFixedXlStates' => $topFixedXlStates,
            'vipStates' => $vipStates,
            'favorites' => $favorites,
            'favoriteIds' => $favoriteIds,
            'followingUsers' => $followingUsers,
            'followerUsers' => $followerUsers,
            'followingIds' => $followingIds,
            'editProduct' => $editProduct,
            'editingGig' => $editingGig,
            'editGigCategoryId' => $editGigCategoryId,
            'editGigCategoryPath' => $editGigCategoryPath,
            'microTasks' => ($tab === 'my-listings' && Auth::canAccessGigs()) ? array_values(array_filter(
                $microSvc->listForUser(Auth::id()),
                static fn (array $row): bool => (int) $row['customer_id'] === (int) Auth::id()
            )) : [],
            'prefLotType' => isset(ProductHelper::marketplaceTypes()[(string) ($_GET['type'] ?? '')])
                ? (string) $_GET['type']
                : '',
            'types' => array_combine(
                array_keys(ProductHelper::marketplaceTypes()),
                array_map(static fn (string $type) => ProductHelper::label($type), array_keys(ProductHelper::marketplaceTypes()))
            ),
            'productCategoryTree' => ProductHelper::PRODUCT_CATEGORY_TREE,
            'microCategories' => $microSvc->categories(),
            'microGigTree' => $microSvc->categoryTree(),
            'notifications' => $notifications,
            'unread' => $unread,
            'reviews' => $reviews,
            'reviewStats' => $reviewStats,
            'referralUrl' => $referralUrl,
            'referralCode' => $referralCode,
            'referralCount' => $referralCount,
            'referralUsers' => $referralUsers,
            'accountLimit' => $accountLimit,
            'businessSubscription' => $businessSubscription,
            'businessPayments' => $businessPayments,
            'businessSalesEnabled' => $businessSalesEnabled,
            'businessFeaturesEnabled' => $businessFeaturesEnabled,
            'businessAiAddonSalesEnabled' => $businessAiAddonSalesEnabled,
            'businessAiAddonSalesFlags' => $businessAiAddonSalesFlags,
            'businessAiQuotas' => $businessAiQuotas,
            'packageMaxPhotos' => $packageMaxPhotos,
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
            'errorCta' => $_SESSION['error_cta'] ?? null,
            'walletBalance' => (new Wallet())->balance(Auth::id()),
            'serviceListingFee' => ProductHelper::serviceListingFee(),
            'serviceListingPublishEnabled' => (new ServiceListingFeeService())->isPublishEnabled(),
            'twoFactorSetup' => $twoFactorSetup,
            'recoveryCodes' => is_array($recoveryCodes) ? $recoveryCodes : null,
            'listingShipping' => $listingShipping,
            'listingPackagings' => $listingPackagings,
        ]);
        unset($_SESSION['flash'], $_SESSION['error'], $_SESSION['error_cta']);
    }

    public function verifyListingForm(): void
    {
        Auth::requireLogin();
        $users = new User();
        $user = $users->find(Auth::id());
        if ($user) {
            Auth::refresh($user);
        }
        $n = new Notification();
        $type = (string) ($_GET['type'] ?? '');
        if (!isset(ProductHelper::marketplaceTypes()[$type])) {
            $type = '';
        }

        if (AMLService::userListingStatus($user ?: Auth::user()) === 'ok') {
            $this->redirect('/profile?tab=lots' . ($type !== '' ? '&type=' . rawurlencode($type) : ''));
        }

        $this->view('profile/verify-listing', [
            'title' => t('verify.title'),
            'currentNav' => 'profile',
            'user' => $user ?: Auth::user(),
            'verifyType' => $type,
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'search' => '',
            'error' => $_SESSION['verify_listing_error'] ?? null,
        ]);
        unset($_SESSION['verify_listing_error']);
    }

    public function verifyListing(): void
    {
        Auth::requireLogin();
        $type = (string) ($_POST['type'] ?? '');
        if (!isset(ProductHelper::marketplaceTypes()[$type])) {
            $type = '';
        }

        $result = AMLService::make()->screenUser(
            Auth::id(),
            (string) ($_POST['bin'] ?? $_POST['iin'] ?? ''),
            'listing'
        );
        $fresh = (new User())->find(Auth::id());
        if ($fresh) {
            Auth::refresh($fresh);
        }

        if (empty($result['ok'])) {
            $_SESSION['verify_listing_error'] = $result['error'] ?? t('flash.aml_blocked');
            $this->redirect('/profile/verify-listing' . ($type !== '' ? '?type=' . rawurlencode($type) : ''));
        }

        $_SESSION['listing_kyc_ok'] = 1;
        $_SESSION['flash'] = t('verify.ok_flash');
        $this->redirect('/profile?tab=lots' . ($type !== '' ? '&type=' . rawurlencode($type) : ''));
    }

    public function updatePersonal(): void
    {
        Auth::requireLogin();
        $users = new User();
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $login = trim($_POST['login'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if ($first === '' || $login === '') {
            $_SESSION['error'] = t('flash.name_login_required');
            $this->redirect('/profile?tab=personal');
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $login)) {
            $_SESSION['error'] = t('flash.login_format');
            $this->redirect('/profile?tab=personal');
        }

        $other = $users->findByLogin($login);
        if ($other && (int) $other['id'] !== Auth::id()) {
            $_SESSION['error'] = t('flash.login_taken');
            $this->redirect('/profile?tab=personal');
        }

        $users->updateProfile(Auth::id(), [
            'first_name' => $first,
            'last_name' => $last,
            'login' => $login,
            'phone' => $phone,
        ]);

        $fresh = $users->find(Auth::id());
        if ($fresh) {
            Auth::refresh($fresh);
        }

        $_SESSION['flash'] = t('flash.personal_saved');
        $this->redirect('/profile?tab=personal');
    }

    public function updateBio(): void
    {
        Auth::requireLogin();
        (new User())->updateBio(Auth::id(), trim($_POST['bio'] ?? ''));
        $fresh = (new User())->find(Auth::id());
        if ($fresh) {
            Auth::refresh($fresh);
        }
        $_SESSION['flash'] = t('flash.bio_saved');
        $this->redirect('/profile?tab=bio');
    }

    public function updatePassword(): void
    {
        Auth::requireLogin();
        $current = $_POST['current_password'] ?? '';
        $pass = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';
        $users = new User();
        $userId = Auth::id();

        if (strlen($pass) < 8) {
            $_SESSION['error'] = t('flash.password_min');
            $this->redirect('/profile?tab=password');
        }
        if ($pass !== $confirm) {
            $_SESSION['error'] = t('flash.password_mismatch');
            $this->redirect('/profile?tab=password');
        }

        $dbUser = $users->find($userId);
        $hasPassword = !empty($dbUser['password']);
        if ($hasPassword && ($current === '' || !$users->verifyPassword($userId, $current))) {
            $_SESSION['error'] = t('flash.wrong_password');
            $this->redirect('/profile?tab=password');
        }

        $users->updatePassword($userId, $pass);
        $_SESSION['flash'] = t('flash.password_changed');
        $this->redirect('/profile?tab=password');
    }

    public function twoFactorSetup(): void
    {
        Auth::requireLogin();
        $users = new User();
        $user = $users->find(Auth::id());
        if (!$user) {
            $this->redirect('/profile?tab=password');
        }
        if ($users->hasTwoFactor($user)) {
            $_SESSION['error'] = t('flash.two_factor_already');
            $this->redirect('/profile?tab=password');
        }

        $_SESSION['two_factor_setup'] = [
            'user_id' => Auth::id(),
            'secret' => Totp::generateSecret(),
        ];
        $this->redirect('/profile?tab=password');
    }

    public function twoFactorConfirm(): void
    {
        Auth::requireLogin();
        $setup = $_SESSION['two_factor_setup'] ?? null;
        if (!is_array($setup) || (int) ($setup['user_id'] ?? 0) !== Auth::id() || empty($setup['secret'])) {
            $_SESSION['error'] = t('flash.two_factor_setup_expired');
            $this->redirect('/profile?tab=password');
        }

        $code = trim((string) ($_POST['code'] ?? ''));
        $secret = (string) $setup['secret'];
        if (!Totp::verify($secret, $code)) {
            $_SESSION['error'] = t('flash.two_factor_invalid');
            $this->redirect('/profile?tab=password');
        }

        $recovery = Totp::generateRecoveryCodes();
        (new User())->enableTwoFactor(Auth::id(), $secret, $recovery);
        unset($_SESSION['two_factor_setup']);
        $_SESSION['two_factor_recovery_codes'] = $recovery;
        $_SESSION['flash'] = t('flash.two_factor_enabled');
        $this->redirect('/profile?tab=password');
    }

    public function twoFactorDisable(): void
    {
        Auth::requireLogin();
        $users = new User();
        $user = $users->find(Auth::id());
        if (!$user || !$users->hasTwoFactor($user)) {
            $this->redirect('/profile?tab=password');
        }

        $password = (string) ($_POST['password'] ?? '');
        $code = trim((string) ($_POST['code'] ?? ''));
        $hasPassword = !empty($user['password']);

        if ($hasPassword && ($password === '' || !$users->verifyPassword(Auth::id(), $password))) {
            $_SESSION['error'] = t('flash.wrong_password');
            $this->redirect('/profile?tab=password');
        }

        $ok = Totp::verify((string) $user['two_factor_secret'], $code)
            || $users->consumeRecoveryCode(Auth::id(), $code);
        if (!$ok) {
            $_SESSION['error'] = t('flash.two_factor_invalid');
            $this->redirect('/profile?tab=password');
        }

        $users->disableTwoFactor(Auth::id());
        unset($_SESSION['two_factor_setup']);
        $_SESSION['flash'] = t('flash.two_factor_disabled');
        $this->redirect('/profile?tab=password');
    }

    public function acceptListingRules(): void
    {
        Auth::requireLogin();
        $users = new User();
        $users->acceptListingRules((int) Auth::id());
        $fresh = $users->find((int) Auth::id());
        if ($fresh) {
            Auth::refresh($fresh);
        }
        $this->json(['ok' => true]);
    }

    private function requireListingRules(string $failUrl): void
    {
        $users = new User();
        $user = $users->find(Auth::id());
        if ($user && !empty($user['listing_rules_accepted_at'])) {
            return;
        }
        if ((string) ($_POST['listing_rules_ack'] ?? '') === '1') {
            $users->acceptListingRules((int) Auth::id());
            return;
        }
        $_SESSION['error'] = t('flash.listing_rules_required');
        $this->redirect($failUrl);
    }

    public function store(): void
    {
        Auth::requireLogin();

        $type = $_POST['type'] ?? 'new';
        if ($type === 'course') {
            $this->redirect('/profile?tab=lots');
        }
        if (!isset(ProductHelper::marketplaceTypes()[$type])) {
            $type = 'new';
        }

        if ($type === 'gig') {
            // Биржа услуг — отдельный поток (не ListingService)
            $gigUrl = '/profile?tab=lots&type=gig';
            $this->requireListingRules($gigUrl);
            $amlFail = $this->guardAml($gigUrl);
            if ($amlFail !== null) {
                $this->redirect($amlFail);
            }
            $description = trim($_POST['description'] ?? '');
            if ($description === '') {
                $_SESSION['error'] = t('flash.title_desc_required');
                $this->redirect($gigUrl);
            }
            $this->storeGig($description);
            return;
        }

        // Общая логика с мобильным API — ListingService
        $result = (new ListingService())->create(
            (int) Auth::id(),
            array_merge($_POST, ['type' => $type]),
            $_FILES['images'] ?? null,
            Auth::isStaff()
        );
        $fresh = (new User())->find((int) Auth::id());
        if ($fresh) {
            Auth::refresh($fresh);
        }
        if (empty($result['ok'])) {
            $this->failListing($result, '/profile?tab=lots');
        }

        $fee = (int) ($result['fee'] ?? 0);
        $_SESSION['flash'] = $fee > 0
            ? t('flash.service_published', ['amount' => Wallet::formatMoney($fee)])
            : t('flash.lot_published');
        if (($result['bonus'] ?? 0) > 0) {
            $_SESSION['flash'] .= ' ' . t('bonuses.flash_listing', [
                'amount' => \App\Models\Bonus::format((int) $result['bonus']),
            ]);
        }
        $this->redirect('/profile?tab=my-listings');
    }

    /**
     * Ошибка ListingService → flash и redirect (как было до выноса логики в сервис).
     *
     * @param array<string, mixed> $result
     */
    private function failListing(array $result, string $failUrl): never
    {
        $_SESSION['error'] = (string) ($result['error'] ?? t('business.err_generic'));
        $code = (string) ($result['code'] ?? '');
        if (in_array($code, ['limit_reached', 'package_limit'], true)) {
            $this->redirect('/profile?tab=business');
        }
        if (in_array($code, ['service_publish_disabled', 'service_fee_unaffordable', 'service_fee_failed'], true)) {
            if ($code !== 'service_publish_disabled') {
                $_SESSION['error_cta'] = 'wallet';
            }
            $this->redirect('/profile?tab=lots&type=service');
        }
        if ($code === 'shipping_invalid' && !empty($result['missing_fields']) && is_array($result['missing_fields'])) {
            $_SESSION['listing_shipping_errors'] = [
                'field' => $result['field'] ?? null,
                'error_code' => $result['shipping_error_code'] ?? null,
                'missing_fields' => $result['missing_fields'],
            ];
        }
        $this->redirect($failUrl);
    }

    private function storeGig(string $description): void
    {
        if (!Auth::canAccessGigs()) {
            $this->redirect('/profile?tab=lots');
        }
        $photos = $this->resolveProductImages(null, false);
        if (!empty($photos['error'])) {
            $_SESSION['error'] = $photos['error'];
            $this->redirect('/profile?tab=lots&type=gig');
        }

        $result = MicroTaskService::make()->createTask(Auth::id(), [
            'description' => $description,
            'category_id' => (int) ($_POST['gig_category_id'] ?? 0),
            'address' => trim((string) ($_POST['location'] ?? '')),
            'initial_price' => (float) preg_replace('/[^\d.]/', '', (string) ($_POST['price'] ?? '0')),
            'image' => (string) ($photos['cover'] ?? ''),
            'images' => $photos['images'] ?? [],
        ]);

        if (!$result['success']) {
            $_SESSION['error'] = implode(' ', $result['errors'] ?? [t('gigs.err_create')]);
            $this->redirect('/profile?tab=lots&type=gig');
        }

        ActivityLogger::info('micro_task.create', 'Поручение биржи', 'micro_task', (int) $result['task_id']);

        $_SESSION['flash'] = t('gigs.created_profile', [
            'pin' => (string) ($result['pin'] ?? ''),
            'id' => (string) ($result['task_id'] ?? ''),
        ]);
        $this->redirect('/catalog/gigs');
    }

    public function updateGig(string $id): void
    {
        Auth::requireLogin();
        if (!Auth::canAccessGigs()) {
            $this->redirect('/profile?tab=lots');
        }

        $taskId = (int) $id;
        $editUrl = '/profile?tab=lots&edit_gig=' . $taskId;

        $description = trim($_POST['description'] ?? '');
        if ($description === '') {
            $_SESSION['error'] = t('flash.title_desc_required');
            $this->redirect($editUrl);
        }

        $owned = MicroTaskService::make()->findOwned($taskId, Auth::id());
        if (!$owned) {
            $_SESSION['error'] = t('gigs.err_not_found');
            $this->redirect('/profile?tab=lots&type=gig');
        }

        $photos = $this->resolveProductImages($owned, false);
        if (!empty($photos['error'])) {
            $_SESSION['error'] = $photos['error'];
            $this->redirect($editUrl);
        }

        $result = MicroTaskService::make()->updateTask(Auth::id(), $taskId, [
            'description' => $description,
            'category_id' => (int) ($_POST['gig_category_id'] ?? 0),
            'address' => trim((string) ($_POST['location'] ?? '')),
            'initial_price' => (float) preg_replace('/[^\d.]/', '', (string) ($_POST['price'] ?? '0')),
            'image' => (string) ($photos['cover'] ?? ''),
            'images' => $photos['images'] ?? [],
        ]);

        if (!$result['success']) {
            $_SESSION['error'] = implode(' ', $result['errors'] ?? [t('gigs.err_edit')]);
            $this->redirect($editUrl);
        }

        ActivityLogger::info('micro_task.update', 'Поручение биржи обновлено', 'micro_task', $taskId);
        $_SESSION['flash'] = t('gigs.updated');
        $this->redirect('/catalog/gigs');
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function gigAsLotForm(array $task): array
    {
        return [
            'id' => (int) $task['id'],
            'type' => 'gig',
            'title' => (string) ($task['title'] ?? ''),
            'description' => (string) ($task['description'] ?? ''),
            'price' => (int) ($task['initial_price'] ?? 0),
            'location' => (string) ($task['address'] ?? ''),
            'image' => $task['image'] ?? null,
            'images' => $task['images'] ?? null,
            'status' => $task['status'] ?? 'open',
        ];
    }

    public function updateLot(string $id): void
    {
        Auth::requireLogin();

        $product = (new Product())->find((int) $id);
        if ($product && (int) $product['user_id'] === Auth::id() && ($product['type'] ?? '') === 'gig') {
            $_SESSION['error'] = t('gigs.err_edit_as_product');
            $this->redirect('/profile?tab=lots');
        }

        $result = (new ListingService())->update((int) Auth::id(), (int) $id, $_POST, $_FILES['images'] ?? null);
        if (empty($result['ok'])) {
            $this->failListing($result, ($result['code'] ?? '') === 'not_found' ? '/profile?tab=lots' : '/profile?tab=lots&edit=' . (int) $id);
        }

        $_SESSION['flash'] = t('flash.lot_updated');
        $this->redirect('/profile?tab=my-listings');
    }

    public function deleteLot(string $id): void
    {
        Auth::requireLogin();

        $result = (new ListingService())->delete((int) Auth::id(), (int) $id);
        if (empty($result['ok'])) {
            $_SESSION['error'] = (string) ($result['error'] ?? t('flash.lot_not_found'));
            $this->redirect(($result['code'] ?? '') === 'not_found' ? '/profile?tab=lots' : '/profile?tab=my-listings#profile-published-lots');
        }
        $_SESSION['flash'] = t('flash.lot_deleted');
        $this->redirect('/profile?tab=my-listings#profile-published-lots');
    }

    public function enableCourseAuthor(): void
    {
        Auth::requireLogin();
        $this->redirect('/profile?tab=lots');
    }

    /**
     * Фото объявления (биржа услуг) — общая логика ListingService::resolveImages.
     *
     * @return array{images?: list<string>, cover?: string, error?: string}
     */
    private function resolveProductImages(?array $existingProduct = null, bool $required = true, ?int $maxPhotos = null): array
    {
        return (new ListingService())->resolveImages((int) Auth::id(), $existingProduct, $_POST, $_FILES['images'] ?? null, $required, $maxPhotos);
    }

    public function avatar(): void
    {
        Auth::requireLogin();

        if (empty($_FILES['avatar']['name']) || ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $_SESSION['error'] = t('flash.avatar_required');
            $this->redirect('/profile?tab=photo');
        }

        $file = $_FILES['avatar'];
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > self::MAX_AVATAR) {
            $_SESSION['error'] = t('flash.avatar_too_big');
            $this->redirect('/profile?tab=photo');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::AVATAR_EXT, true)) {
            $_SESSION['error'] = t('flash.avatar_formats');
            $this->redirect('/profile?tab=photo');
        }
        if (!\App\Helpers\UploadHelper::isAllowedUpload((string) $file['tmp_name'], (string) $file['name'], self::AVATAR_EXT)) {
            $_SESSION['error'] = t('flash.avatar_formats');
            $this->redirect('/profile?tab=photo');
        }

        $ext = \App\Helpers\UploadHelper::normalizeExt((string) $file['name']);

        $dir = __DIR__ . '/../../public/uploads/avatars';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $users = new User();
        $current = $users->find(Auth::id());
        if (!empty($current['avatar_file'])) {
            $old = $dir . '/' . basename($current['avatar_file']);
            if (is_file($old)) {
                unlink($old);
            }
        }

        $name = 'avatar_' . Auth::id() . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            $_SESSION['error'] = t('flash.avatar_save_fail');
            $this->redirect('/profile?tab=photo');
        }

        $users->updateAvatar(Auth::id(), $name);
        $fresh = $users->find(Auth::id());
        if ($fresh) {
            Auth::refresh($fresh);
        }

        $_SESSION['flash'] = t('flash.avatar_updated');
        $this->redirect('/profile?tab=photo');
    }

    public function clearNotifications(): void
    {
        Auth::requireLogin();
        (new Notification())->clearAll(Auth::id());
        $message = t('flash.notifications_cleared');

        $wantsJson = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        if ($wantsJson) {
            $this->json(['ok' => true, 'message' => $message]);
        }

        $_SESSION['flash'] = $message;
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer !== '' && preg_match('#^https?://#i', $referer)) {
            $this->redirect($referer);
        }
        $this->redirect('/');
    }

    public function deleteAccount(): void
    {
        Auth::requireLogin();

        $password = $_POST['password'] ?? '';
        $confirm = trim($_POST['confirm_text'] ?? '');
        $users = new User();
        $userId = Auth::id();
        $deletion = new \App\Services\Auth\AccountDeletionService();

        if (!in_array($confirm, ['УДАЛИТЬ', t('profile.delete_word')], true)) {
            $_SESSION['error'] = t('flash.delete_confirm_word');
            $this->redirect('/profile?tab=password');
        }

        // Аккаунт с паролем — подтверждение паролем; без пароля (вход через Google/Apple) —
        // требуется недавний вход (не старше 10 минут). Раньше такие аккаунты удалить было нельзя.
        if ($deletion->hasPassword($userId)) {
            if ($password === '' || !$users->verifyPassword($userId, $password)) {
                $_SESSION['error'] = t('flash.wrong_password');
                $this->redirect('/profile?tab=password');
            }
        } elseif (time() - (int) ($_SESSION['auth_time'] ?? 0) > \App\Services\Auth\AccountDeletionService::REAUTH_WINDOW) {
            $_SESSION['error'] = t('mobile_api.reauth_required');
            $this->redirect('/profile?tab=password');
        }

        $result = $deletion->delete($userId, 'web');
        if (!$result['ok']) {
            $_SESSION['error'] = $result['error'] === 'deletion_blocked'
                ? implode(' ', array_map(
                    static fn (array $b): string => t('mobile_api.blocker_' . $b['code'], [
                        'count' => (string) ($b['count'] ?? ''),
                        'amount' => \App\Models\Wallet::formatMoney((int) ($b['amount'] ?? 0)),
                    ]),
                    $result['blockers'] ?? []
                ))
                : t('flash.delete_fail');
            $this->redirect('/profile?tab=password');
        }

        Auth::logout();
        $_SESSION['flash'] = t('flash.account_deleted');
        $this->redirect('/');
    }

    private function guardAml(string $redirect): ?string
    {
        $result = AMLService::make()->screenUser(
            Auth::id(),
            (string) ($_POST['bin'] ?? $_POST['iin'] ?? ''),
            'listing'
        );
        if (!empty($result['ok'])) {
            $fresh = (new User())->find(Auth::id());
            if ($fresh) {
                Auth::refresh($fresh);
            }
            return null;
        }

        $_SESSION['error'] = $result['error'] ?? t('flash.aml_blocked');
        return $redirect;
    }
}
