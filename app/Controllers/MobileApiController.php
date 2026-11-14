<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\MobileAuth;
use App\Helpers\ActivityLogger;
use App\Helpers\ProductHelper;
use App\Helpers\Totp;
use App\Models\Favorite;
use App\Models\MobileToken;
use App\Models\Product;
use App\Models\User;

/**
 * JSON API для Flutter-приложения: /api/mobile/v1/*
 *
 * Нативно: вход (+2FA), профиль, каталог, карточка, избранное.
 * Остальное (корзина, оплата, чаты, заказы, подача объявлений) приложение открывает
 * во встроенном WebView — сессия поднимается по cookie zk_mt (см. App\Core\MobileAuth).
 */
class MobileApiController extends Controller
{
    private const PER_PAGE_DEFAULT = 20;
    private const PER_PAGE_MAX = 50;

    public function config(): void
    {
        $app = $GLOBALS['appConfig'] ?? [];
        $categories = [];
        foreach (ProductHelper::PRODUCT_CATEGORY_TREE as $parent => $children) {
            $categories[] = [
                'name' => $parent,
                'label' => ProductHelper::categoryLabel($parent),
                'children' => array_map(static fn (string $child) => [
                    'name' => ProductHelper::formatCategory($parent, $child),
                    'label' => ProductHelper::categoryLabel($child),
                ], $children),
            ];
        }

        $types = [];
        foreach (ProductHelper::marketplaceTypes() as $key => $_) {
            $types[] = ['key' => $key, 'label' => ProductHelper::label($key)];
        }

        $this->json([
            'ok' => true,
            'stub_mode' => !empty($app['stub_mode']),
            'opens_at' => (string) ($app['stub_opens_at'] ?? ''),
            'site_access' => Auth::hasSiteAccess(),
            'user' => Auth::check() ? $this->userPayload(Auth::user()) : null,
            'types' => $types,
            'categories' => $categories,
            'min_app_build' => 1,
        ]);
    }

    public function login(): void
    {
        $this->requireAppHeader();
        $in = $this->input();
        $email = trim((string) ($in['email'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $device = (string) ($in['device'] ?? '');

        $users = new User();
        $user = $email !== '' ? $users->findByEmail($email) : null;
        if (!$user || empty($user['password']) || !password_verify($password, $user['password'])) {
            ActivityLogger::warning('auth.login_failed', 'Неудачная попытка входа (приложение): ' . $email, null, null, [
                'email' => $email,
                'channel' => 'mobile',
            ]);
            $this->json(['ok' => false, 'error' => t('auth.bad_credentials')], 401);
        }

        if ($users->hasTwoFactor($user)) {
            $challenge = (new MobileToken())->issueChallenge((int) $user['id'], $device);
            $this->json(['ok' => true, 'two_factor_required' => true, 'challenge' => $challenge]);
        }

        $this->finishLogin($user, $device);
    }

    public function twoFactor(): void
    {
        $this->requireAppHeader();
        $in = $this->input();
        $challenge = (string) ($in['challenge'] ?? '');
        $code = trim((string) ($in['code'] ?? ''));
        $device = (string) ($in['device'] ?? '');

        $tokens = new MobileToken();
        $userId = $tokens->challengeUser($challenge);
        if (!$userId) {
            $this->json(['ok' => false, 'error' => t('auth.two_factor_expired'), 'expired' => true], 401);
        }

        $users = new User();
        $user = $users->find($userId);
        if (!$user || !$users->hasTwoFactor($user)) {
            $tokens->consumeChallenge($challenge);
            $this->json(['ok' => false, 'error' => t('auth.two_factor_expired'), 'expired' => true], 401);
        }

        $ok = Totp::verify((string) $user['two_factor_secret'], $code)
            || $users->consumeRecoveryCode($userId, $code);
        if (!$ok) {
            $this->json(['ok' => false, 'error' => t('auth.two_factor_invalid')], 401);
        }

        $tokens->consumeChallenge($challenge);
        $this->finishLogin($user, $device);
    }

    /**
     * Обмен веб-сессии на токен устройства: после регистрации / входа через Google в WebView.
     */
    public function sessionToken(): void
    {
        $this->requireAppHeader();
        if (!Auth::check()) {
            $this->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $user = (new User())->find((int) Auth::id());
        if (!$user) {
            $this->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $this->finishLogin($user, (string) ($this->input()['device'] ?? ''));
    }

    public function logout(): void
    {
        $this->requireAppHeader();
        MobileAuth::revokeCurrent();
        if (Auth::check()) {
            ActivityLogger::info('auth.logout', 'Выход (приложение)', 'user', (int) Auth::id());
        }
        Auth::logout();
        $this->json(['ok' => true]);
    }

    public function me(): void
    {
        $this->requireUser();
        $fresh = (new User())->find((int) Auth::id());
        if ($fresh) {
            Auth::refresh($fresh);
        }
        $this->json(['ok' => true, 'user' => $this->userPayload(Auth::user())]);
    }

    public function products(): void
    {
        $this->requireSiteAccess();

        $type = $this->str($_GET['type'] ?? null);
        if ($type !== null && !array_key_exists($type, ProductHelper::marketplaceTypes())) {
            $type = null;
        }
        $q = $this->str($_GET['q'] ?? null);
        if ($q !== null) {
            $q = mb_substr($q, 0, 100);
        }
        $category = $this->str($_GET['category'] ?? null);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, min(self::PER_PAGE_MAX, (int) ($_GET['per_page'] ?? self::PER_PAGE_DEFAULT)));

        // Тот же порядок, что и в веб-каталоге (топ/XL/буст)
        $all = (new Product())->allActive($type, $q, $category);
        $total = count($all);
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);

        $favIds = Auth::check() ? array_flip((new Favorite())->idsForUser((int) Auth::id())) : [];
        $items = array_map(fn (array $p) => $this->productCard($p, isset($favIds[(int) $p['id']])), $slice);

        $this->json([
            'ok' => true,
            'items' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'has_more' => $page * $perPage < $total,
        ]);
    }

    public function product(string $id): void
    {
        $this->requireSiteAccess();

        $products = new Product();
        $item = $products->findWithSeller((int) $id);
        $isOwner = $item && Auth::check() && (int) $item['user_id'] === (int) Auth::id();
        if (!$item || ($item['type'] ?? '') === 'course'
            || (!in_array($item['status'] ?? '', ['active', 'sold', 'reserved', 'out_of_stock'], true) && !$isOwner)) {
            $this->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $favorited = Auth::check() && (new Favorite())->isFavorite((int) Auth::id(), (int) $item['id']);
        $card = $this->productCard($item, $favorited);

        $characteristics = array_map(
            static fn (array $row) => ['name' => $row[0], 'value' => $row[1]],
            ProductHelper::parseCharacteristics($item['characteristics'] ?? null)
        );

        $similar = [];
        try {
            $similar = array_map(fn (array $p) => $this->productCard($p, false), $products->similar($item, 8));
        } catch (\Throwable) {
        }

        $auction = null;
        if (($item['type'] ?? '') === 'auction') {
            $auction = [
                'current_price' => (int) (($item['current_bid'] ?? 0) ?: ($item['price'] ?? 0)),
                'bid_step' => (int) ($item['bid_step'] ?? 0),
                'end_at' => $item['auction_end_at'] ?? null,
                'buy_now' => isset($item['auction_buy_now']) ? (int) $item['auction_buy_now'] : null,
                'bids' => $products->countBids((int) $item['id']),
            ];
        }

        $whatsapp = ProductHelper::whatsappDigits($item['whatsapp'] ?? null);

        $this->json([
            'ok' => true,
            'item' => $card + [
                'description' => (string) ($item['description'] ?? ''),
                'characteristics' => $characteristics,
                'images' => array_map([$this, 'absolute'], ProductHelper::imageUrls($item)),
                'quantity' => ProductHelper::availableQuantity($item),
                'purchasable' => ProductHelper::isPurchasable($item) && !$isOwner,
                'is_owner' => $isOwner,
                'whatsapp' => $whatsapp,
                'views' => (int) ($item['view_count'] ?? 0),
                'auction' => $auction,
                'seller' => $card['seller'] + [
                    'since' => $item['seller_created_at'] ?? null,
                    'avatar' => !empty($item['seller_avatar_file'])
                        ? ProductHelper::absoluteUrl('public/uploads/avatars/' . basename((string) $item['seller_avatar_file']))
                        : null,
                ],
            ],
            'similar' => $similar,
        ]);
    }

    public function favorites(): void
    {
        $this->requireUser();
        $items = array_map(
            fn (array $p) => $this->productCard($p, true),
            (new Favorite())->forUser((int) Auth::id())
        );
        $this->json(['ok' => true, 'items' => $items]);
    }

    public function toggleFavorite(string $id): void
    {
        $this->requireAppHeader();
        $this->requireUser();
        $result = (new Favorite())->toggle((int) Auth::id(), (int) $id);
        $this->json($result, $result['ok'] ? 200 : 404);
    }

    // ---------------------------------------------------------------------

    private function finishLogin(array $user, string $device): void
    {
        $token = (new MobileToken())->issueAccess((int) $user['id'], $device);
        Auth::login($user, true);
        ActivityLogger::info('auth.login', 'Вход в аккаунт (приложение)', 'user', (int) $user['id']);

        $this->json([
            'ok' => true,
            'token' => $token,
            'user' => $this->userPayload(Auth::user()),
            'site_access' => Auth::hasSiteAccess(),
        ]);
    }

    private function productCard(array $p, bool $favorited): array
    {
        $type = (string) ($p['type'] ?? '');
        $isBusiness = ProductHelper::sellerIsBusiness($p);

        return [
            'id' => (int) $p['id'],
            'title' => (string) ($p['title'] ?? ''),
            'type' => $type,
            'type_label' => ProductHelper::label($type),
            'price' => (int) ($p['price'] ?? 0),
            'price_text' => ProductHelper::formatPrice($p),
            'category' => (string) ($p['category'] ?? ''),
            'location' => (string) ($p['location'] ?? ''),
            'status' => (string) ($p['status'] ?? 'active'),
            'image' => ($img = ProductHelper::imageUrl($p)) ? $this->absolute($img) : null,
            'created_at' => $p['created_at'] ?? null,
            'favorited' => $favorited,
            'url' => ProductHelper::absoluteUrl('/product/' . (int) $p['id']),
            'seller' => [
                'id' => (int) ($p['user_id'] ?? 0),
                'name' => $isBusiness ? ProductHelper::sellerBusinessLabel($p) : (string) ($p['seller_name'] ?? ''),
                'is_business' => $isBusiness,
            ],
        ];
    }

    private function userPayload(?array $u): ?array
    {
        if (!$u) {
            return null;
        }

        return [
            'id' => (int) $u['id'],
            'name' => (string) ($u['name'] ?? ''),
            'email' => (string) ($u['email'] ?? ''),
            'phone' => $u['phone'] ?? null,
            'role' => (string) ($u['role'] ?? 'user'),
            'account_type' => (string) ($u['account_type'] ?? 'personal'),
            'business_status' => (string) ($u['business_status'] ?? 'none'),
            'avatar' => !empty($u['avatar_file'])
                ? ProductHelper::absoluteUrl('public/uploads/avatars/' . basename((string) $u['avatar_file']))
                : null,
            'avatar_letter' => (string) ($u['avatar'] ?? 'U'),
        ];
    }

    private function requireUser(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
    }

    /** До открытия сайта (stub_mode) каталог доступен только пользователям с доступом. */
    private function requireSiteAccess(): void
    {
        $app = $GLOBALS['appConfig'] ?? [];
        if (!empty($app['stub_mode']) && !Auth::hasSiteAccess()) {
            $this->json([
                'ok' => false,
                'error' => 'stub',
                'opens_at' => (string) ($app['stub_opens_at'] ?? ''),
            ], 403);
        }
    }

    /** POST из приложения: кастомный заголовок вместо CSRF-токена. */
    private function requireAppHeader(): void
    {
        if (!MobileAuth::isAppRequest()) {
            $this->json(['ok' => false, 'error' => 'app_header_required'], 400);
        }
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        $ct = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_contains($ct, 'application/json')) {
            $data = json_decode((string) file_get_contents('php://input'), true);
            return is_array($data) ? $data : [];
        }

        return $_POST;
    }

    /** URL от ProductHelper::url() уже содержит базовый путь — добавляем только схему и хост. */
    private function absolute(string $rootRelative): string
    {
        if (preg_match('#^https?://#i', $rootRelative)) {
            return $rootRelative;
        }
        $origin = preg_replace('#^(https?://[^/]+).*$#i', '$1', ProductHelper::absoluteUrl('/'));

        return $origin . '/' . ltrim($rootRelative, '/');
    }

    private function str(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : $v;
    }
}
