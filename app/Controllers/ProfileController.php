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
use App\Models\Product;
use App\Models\Review;
use App\Models\User;

class ProfileController extends Controller
{
    private const AVATAR_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const MAX_AVATAR = 3 * 1024 * 1024;
    private const PRODUCT_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const MAX_PRODUCT_IMAGE = 5 * 1024 * 1024;

    public function index(): void
    {
        Auth::requireLogin();

        $dbUser = (new User())->find(Auth::id());
        if ($dbUser) {
            Auth::refresh($dbUser);
        }

        $tab = $_GET['tab'] ?? 'personal';
        $allowed = ['personal', 'photo', 'bio', 'reviews', 'notifications', 'password', 'lots', 'favorites', 'subscriptions'];
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
        if ($tab === 'lots' && !empty($_GET['edit'])) {
            $candidate = (new Product())->find((int) $_GET['edit']);
            if ($candidate && (int) $candidate['user_id'] === Auth::id()) {
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

        $this->view('profile/index', [
            'title' => t('profile.title'),
            'currentNav' => 'profile',
            'tab' => $tab,
            'user' => $dbUser ?: Auth::user(),
            'products' => (new Product())->byUser(Auth::id()),
            'favorites' => $favorites,
            'favoriteIds' => $favoriteIds,
            'followingUsers' => $followingUsers,
            'followerUsers' => $followerUsers,
            'followingIds' => $followingIds,
            'editProduct' => $editProduct,
            'types' => array_combine(
                array_keys(ProductHelper::TYPES),
                array_map(static fn (string $type) => ProductHelper::label($type), array_keys(ProductHelper::TYPES))
            ),
            'productCategoryTree' => ProductHelper::PRODUCT_CATEGORY_TREE,
            'notifications' => $notifications,
            'unread' => $unread,
            'reviews' => $reviews,
            'reviewStats' => $reviewStats,
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
            'twoFactorSetup' => $twoFactorSetup,
            'recoveryCodes' => is_array($recoveryCodes) ? $recoveryCodes : null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
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

    public function store(): void
    {
        Auth::requireLogin();

        $type = $_POST['type'] ?? 'used';
        if (!isset(ProductHelper::TYPES[$type])) {
            $type = 'used';
        }

        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($title === '' || $description === '') {
            $_SESSION['error'] = t('flash.title_desc_required');
            $this->redirect('/profile?tab=lots');
        }

        $exchangeFor = trim($_POST['exchange_for'] ?? '');
        if ($type === 'exchange' && $exchangeFor === '') {
            $_SESSION['error'] = t('flash.exchange_for_required');
            $this->redirect('/profile?tab=lots');
        }
        if ($type !== 'exchange') {
            $exchangeFor = '';
        }

        $resolved = $this->resolveProductImages();
        if (!empty($resolved['error'])) {
            $_SESSION['error'] = $resolved['error'];
            $this->redirect('/profile?tab=lots');
        }

        $price = in_array($type, ['free', 'exchange'], true) ? 0 : ($_POST['price'] ?? 0);
        $priceLabel = match ($type) {
            'free' => 'Бесплатно',
            'exchange' => 'Обмен',
            default => null,
        };

        $productId = (new Product())->create([
            'user_id' => Auth::id(),
            'type' => $type,
            'category' => ProductHelper::normalizeCategory($_POST['category'] ?? null, $type),
            'title' => $title,
            'description' => $description,
            'price' => $price,
            'exchange_for' => $exchangeFor !== '' ? mb_substr($exchangeFor, 0, 255) : null,
            'price_label' => $priceLabel,
            'location' => trim($_POST['location'] ?? 'Караганда'),
            'image' => $resolved['cover'],
            'images' => $resolved['images'],
        ]);

        ActivityLogger::info('product.create', 'Добавлено объявление «' . $title . '»', 'product', $productId, [
            'type' => $type,
            'price' => $price,
        ]);

        $bonusResult = (new \App\Models\Bonus())->awardListing((int) Auth::id(), (int) $productId);

        $sellerName = (string) (Auth::user()['name'] ?? 'Продавец');
        $shortTitle = mb_strlen($title) > 80 ? mb_substr($title, 0, 77) . '…' : $title;
        (new Follow())->notifyFollowers(
            Auth::id(),
            t('seller.notify_product', ['name' => $sellerName, 'title' => $shortTitle])
        );

        $_SESSION['flash'] = t('flash.lot_published');
        if (!empty($bonusResult['ok']) && empty($bonusResult['skipped']) && ($bonusResult['amount'] ?? 0) > 0) {
            $_SESSION['flash'] .= ' ' . t('bonuses.flash_listing', [
                'amount' => \App\Models\Bonus::format((int) $bonusResult['amount']),
            ]);
        }
        $this->redirect('/profile?tab=lots');
    }

    public function updateLot(string $id): void
    {
        Auth::requireLogin();

        $products = new Product();
        $product = $products->find((int) $id);
        if (!$product || (int) $product['user_id'] !== Auth::id()) {
            $_SESSION['error'] = t('flash.lot_not_found');
            $this->redirect('/profile?tab=lots');
        }

        $type = $_POST['type'] ?? $product['type'];
        if (!isset(ProductHelper::TYPES[$type])) {
            $type = $product['type'];
        }

        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($title === '' || $description === '') {
            $_SESSION['error'] = t('flash.title_desc_required');
            $this->redirect('/profile?tab=lots&edit=' . (int) $id);
        }

        $exchangeFor = trim($_POST['exchange_for'] ?? '');
        if ($type === 'exchange' && $exchangeFor === '') {
            $_SESSION['error'] = t('flash.exchange_for_required');
            $this->redirect('/profile?tab=lots&edit=' . (int) $id);
        }
        if ($type !== 'exchange') {
            $exchangeFor = '';
        }

        $resolved = $this->resolveProductImages($product);
        if (!empty($resolved['error'])) {
            $_SESSION['error'] = $resolved['error'];
            $this->redirect('/profile?tab=lots&edit=' . (int) $id);
        }

        $noPrice = in_array($type, ['free', 'exchange'], true);
        $price = $noPrice ? 0 : ($_POST['price'] ?? $product['price']);
        $priceLabel = match ($type) {
            'free' => 'Бесплатно',
            'exchange' => 'Обмен',
            default => null,
        };

        $products->updateProduct((int) $id, [
            'type' => $type,
            'category' => ProductHelper::normalizeCategory($_POST['category'] ?? ($product['category'] ?? null), $type),
            'title' => $title,
            'description' => $description,
            'price' => $price,
            'exchange_for' => $exchangeFor !== '' ? mb_substr($exchangeFor, 0, 255) : null,
            'price_label' => $priceLabel,
            'location' => trim($_POST['location'] ?? ($product['location'] ?? 'Караганда')),
            'image' => $resolved['cover'],
            'images' => $resolved['images'],
            'status' => $product['status'] ?? 'active',
        ]);

        ActivityLogger::info('product.update', 'Обновлено объявление «' . $title . '»', 'product', (int) $id, [
            'type' => $type,
        ]);

        $_SESSION['flash'] = t('flash.lot_updated');
        $this->redirect('/profile?tab=lots');
    }

    public function deleteLot(string $id): void
    {
        Auth::requireLogin();

        $products = new Product();
        $product = $products->find((int) $id);
        if (!$product || (int) $product['user_id'] !== Auth::id()) {
            $_SESSION['error'] = t('flash.lot_not_found');
            $this->redirect('/profile?tab=lots');
        }

        $products->deleteProductFiles(ProductHelper::decodeImages($product));
        $products->delete((int) $id);
        ActivityLogger::info(
            'product.delete',
            'Удалено объявление «' . ($product['title'] ?? '') . '»',
            'product',
            (int) $id
        );
        $_SESSION['flash'] = t('flash.lot_deleted');
        $this->redirect('/profile?tab=lots');
    }

    /**
     * @return array{images?: list<string>, cover?: string, error?: string}
     */
    private function resolveProductImages(?array $existingProduct = null): array
    {
        $products = new Product();
        $oldFiles = $existingProduct ? ProductHelper::decodeImages($existingProduct) : [];

        $keep = $_POST['keep_images'] ?? [];
        if (!is_array($keep)) {
            $keep = [];
        }
        $kept = [];
        foreach ($keep as $file) {
            $name = basename((string) $file);
            if ($name !== '' && in_array($name, $oldFiles, true) && !in_array($name, $kept, true)) {
                $kept[] = $name;
            }
        }

        $removed = array_diff($oldFiles, $kept);
        if ($removed) {
            $products->deleteProductFiles(array_values($removed));
        }

        $slotsLeft = 3 - count($kept);
        $uploaded = $slotsLeft > 0 ? $this->uploadProductImages($slotsLeft) : [];
        if (!empty($uploaded['error'])) {
            return ['error' => $uploaded['error']];
        }

        $images = array_values(array_slice(array_merge($kept, $uploaded['files'] ?? []), 0, 3));
        if (!$images) {
            return ['error' => t('flash.need_photo')];
        }

        $coverRaw = trim((string) ($_POST['cover'] ?? ''));
        $cover = null;
        if (strpos($coverRaw, '__new__') === 0) {
            $idx = (int) substr($coverRaw, 7);
            $newFiles = $uploaded['files'] ?? [];
            $cover = $newFiles[$idx] ?? null;
        } elseif ($coverRaw !== '' && in_array(basename($coverRaw), $images, true)) {
            $cover = basename($coverRaw);
        }

        if (!$cover || !in_array($cover, $images, true)) {
            $cover = $images[0];
        }

        return ['images' => $images, 'cover' => $cover];
    }

    /**
     * @return array{files?: list<string>, error?: string}
     */
    private function uploadProductImages(int $max): array
    {
        if ($max < 1) {
            return ['files' => []];
        }

        if (empty($_FILES['images']) || !is_array($_FILES['images']['name'] ?? null)) {
            return ['files' => []];
        }

        $names = $_FILES['images']['name'];
        $tmps = $_FILES['images']['tmp_name'];
        $errors = $_FILES['images']['error'];
        $sizes = $_FILES['images']['size'];

        $dir = __DIR__ . '/../../public/uploads/products';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['error' => t('flash.photo_save_fail')];
        }
        if (!is_writable($dir)) {
            return ['error' => t('flash.photo_save_fail')];
        }

        $saved = [];
        $count = is_array($names) ? count($names) : 0;
        for ($i = 0; $i < $count; $i++) {
            if (count($saved) >= $max) {
                break;
            }
            $name = $names[$i] ?? '';
            if ($name === '' || ($errors[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (($errors[$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                return ['error' => t('flash.upload_error')];
            }
            if (($sizes[$i] ?? 0) > self::MAX_PRODUCT_IMAGE) {
                return ['error' => t('flash.photo_too_big')];
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, self::PRODUCT_EXT, true)) {
                return ['error' => t('flash.photo_formats')];
            }
            if (!\App\Helpers\UploadHelper::isAllowedUpload((string) $tmps[$i], (string) $name, self::PRODUCT_EXT)) {
                return ['error' => t('flash.photo_formats')];
            }

            $ext = \App\Helpers\UploadHelper::normalizeExt((string) $name);
            $filename = 'product_' . Auth::id() . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest = $dir . '/' . $filename;
            if (!@move_uploaded_file($tmps[$i], $dest)) {
                return ['error' => t('flash.photo_save_fail')];
            }
            \App\Helpers\UploadHelper::applyWatermark($dest);
            $saved[] = $filename;
        }

        return ['files' => $saved];
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
        (new Notification())->markAllRead(Auth::id());
        $this->redirect('/profile?tab=notifications');
    }

    public function deleteAccount(): void
    {
        Auth::requireLogin();

        $password = $_POST['password'] ?? '';
        $confirm = trim($_POST['confirm_text'] ?? '');
        $users = new User();
        $userId = Auth::id();

        if (!in_array($confirm, ['УДАЛИТЬ', t('profile.delete_word')], true)) {
            $_SESSION['error'] = t('flash.delete_confirm_word');
            $this->redirect('/profile?tab=password');
        }

        if ($password === '' || !$users->verifyPassword($userId, $password)) {
            $_SESSION['error'] = t('flash.wrong_password');
            $this->redirect('/profile?tab=password');
        }

        if (!$users->deleteAccount($userId)) {
            $_SESSION['error'] = t('flash.delete_fail');
            $this->redirect('/profile?tab=password');
        }

        Auth::logout();
        $_SESSION['flash'] = t('flash.account_deleted');
        $this->redirect('/');
    }
}
