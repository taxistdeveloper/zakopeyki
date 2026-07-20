<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ProductHelper;
use App\Models\Favorite;
use App\Models\Notification;
use App\Models\Product;
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
            Auth::login($dbUser);
        }

        $tab = $_GET['tab'] ?? 'personal';
        $allowed = ['personal', 'photo', 'bio', 'reviews', 'notifications', 'password', 'lots', 'favorites'];
        if (!in_array($tab, $allowed, true)) {
            $tab = 'personal';
        }

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

        $this->view('profile/index', [
            'title' => t('profile.title'),
            'currentNav' => 'profile',
            'tab' => $tab,
            'user' => $dbUser ?: Auth::user(),
            'products' => (new Product())->byUser(Auth::id()),
            'favorites' => $favorites,
            'favoriteIds' => $favoriteIds,
            'editProduct' => $editProduct,
            'types' => array_combine(
                array_keys(ProductHelper::TYPES),
                array_map(static fn (string $type) => ProductHelper::label($type), array_keys(ProductHelper::TYPES))
            ),
            'productCategoryTree' => ProductHelper::PRODUCT_CATEGORY_TREE,
            'notifications' => $notifications,
            'unread' => $unread,
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
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
            Auth::login($fresh);
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
            Auth::login($fresh);
        }
        $_SESSION['flash'] = t('flash.bio_saved');
        $this->redirect('/profile?tab=bio');
    }

    public function updatePassword(): void
    {
        Auth::requireLogin();
        $pass = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        if (strlen($pass) < 8) {
            $_SESSION['error'] = t('flash.password_min');
            $this->redirect('/profile?tab=password');
        }
        if ($pass !== $confirm) {
            $_SESSION['error'] = t('flash.password_mismatch');
            $this->redirect('/profile?tab=password');
        }

        (new User())->updatePassword(Auth::id(), $pass);
        $_SESSION['flash'] = t('flash.password_changed');
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

        (new Product())->create([
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

        $_SESSION['flash'] = t('flash.lot_published');
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
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
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

            $filename = 'product_' . Auth::id() . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($tmps[$i], $dir . '/' . $filename)) {
                return ['error' => t('flash.photo_save_fail')];
            }
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

        $name = 'avatar_' . Auth::id() . '_' . time() . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            $_SESSION['error'] = t('flash.avatar_save_fail');
            $this->redirect('/profile?tab=photo');
        }

        $users->updateAvatar(Auth::id(), $name);
        $fresh = $users->find(Auth::id());
        if ($fresh) {
            Auth::login($fresh);
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
