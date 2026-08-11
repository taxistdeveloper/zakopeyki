<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\AvatarHelper;
use App\Helpers\ProductHelper;
use App\Models\Favorite;
use App\Models\Follow;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\Stream;
use App\Models\User;

class UserController extends Controller
{
    public function show(string $id): void
    {
        $payload = $this->buildProfile((int) $id);
        if ($payload === null) {
            if ($this->wantsJson()) {
                $this->json(['ok' => false, 'error' => 'not_found'], 404);
            }
            http_response_code(404);
            $this->view('errors/404', ['title' => t('seller.not_found')]);
            return;
        }

        if ($this->wantsJson()) {
            $this->json($payload);
        }

        $this->redirect('/?seller=' . (int) $id);
    }

    public function followToggle(string $id): void
    {
        if (!Auth::check()) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                $this->json(['ok' => false, 'error' => 'login', 'following' => false], 401);
            }
            $this->redirect('/login');
        }

        $result = (new Follow())->toggle((int) Auth::id(), (int) $id);

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            $this->json($result, $result['ok'] ? 200 : 422);
        }

        $_SESSION['flash'] = $result['ok']
            ? ($result['following'] ? t('seller.subscribed') : t('seller.unsubscribed'))
            : t('seller.follow_error');

        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer !== '' && preg_match('#^https?://#i', $referer)) {
            $this->redirect($referer);
        }
        $this->redirect('/');
    }

    private function wantsJson(): bool
    {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            return true;
        }
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        return str_contains($accept, 'application/json');
    }

    /** @return array<string, mixed>|null */
    private function buildProfile(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $user = (new User())->find($userId);
        if (!$user) {
            return null;
        }

        $follow = new Follow();
        $isOwn = Auth::check() && (int) Auth::id() === $userId;
        $isFollowing = Auth::check() && !$isOwn && $follow->isFollowing((int) Auth::id(), $userId);
        $rating = (new Review())->statsFor($userId);
        $followersCount = $follow->countFollowers($userId);
        $followingCount = $follow->countFollowing($userId);
        $salesCount = (new Order())->countCompletedSales($userId);

        $login = (string) ($user['login'] ?? '');
        if ($login === '' && !empty($user['email'])) {
            $login = (string) (strstr((string) $user['email'], '@', true) ?: '');
        }

        $favoriteIds = [];
        if (Auth::check()) {
            $favoriteIds = (new Favorite())->idsForUser((int) Auth::id());
        }

        $products = [];
        foreach ((new Product())->activeShopByUser($userId, 48) as $p) {
            $pid = (int) $p['id'];
            $products[] = [
                'id' => $pid,
                'title' => (string) ($p['title'] ?? ''),
                'price_label' => ProductHelper::formatPrice($p),
                'image' => ProductHelper::imageUrl($p),
                'url' => ProductHelper::url('/product/' . $pid),
                'can_cart' => ProductHelper::isPurchasable($p) && !$isOwn
                    && (!Auth::check() || (int) Auth::id() !== $userId),
                'favorited' => in_array($pid, $favoriteIds, true),
            ];
        }

        $reviews = [];
        foreach ((new Review())->forSubject($userId, 20) as $r) {
            $author = [
                'name' => $r['author_name'] ?? '',
                'avatar' => $r['author_avatar'] ?? null,
                'avatar_file' => $r['author_avatar_file'] ?? null,
            ];
            $productId = (int) ($r['product_id'] ?? 0);
            $productStub = [
                'type' => $r['product_type'] ?? 'used',
                'price' => $r['product_price'] ?? 0,
                'price_label' => $r['product_price_label'] ?? null,
                'image' => $r['product_image_file'] ?? null,
                'images' => $r['product_images'] ?? null,
            ];
            $productTitle = trim((string) ($r['product_title'] ?? ''));
            if ($productTitle === '' && $productId > 0) {
                $productTitle = t('seller.review_product_fallback');
            }
            $reviews[] = [
                'id' => (int) ($r['id'] ?? 0),
                'rating' => (int) ($r['rating'] ?? 0),
                'comment' => (string) ($r['body'] ?? ''),
                'created_at' => (string) ($r['created_at'] ?? ''),
                'author_name' => (string) ($r['author_name'] ?? ''),
                'author_avatar_url' => AvatarHelper::url($author),
                'author_initial' => AvatarHelper::initial($author),
                'product_id' => $productId,
                'product_title' => $productTitle,
                'product_image' => $productId > 0 ? ProductHelper::imageUrl($productStub) : null,
                'product_price_label' => $productId > 0
                    ? ProductHelper::formatPrice($productStub)
                    : '',
                'product_url' => $productId > 0
                    ? ProductHelper::url('/product/' . $productId)
                    : '',
            ];
        }

        $isLive = false;
        foreach ((new Stream())->allActive(50) as $stream) {
            if ((int) ($stream['user_id'] ?? 0) === $userId) {
                $isLive = true;
                break;
            }
        }

        return [
            'ok' => true,
            'id' => $userId,
            'name' => (string) ($user['name'] ?? ''),
            'login' => $login,
            'bio' => (string) ($user['bio'] ?? ''),
            'avatar_url' => AvatarHelper::url($user),
            'avatar_initial' => AvatarHelper::initial($user),
            'member_since' => $this->formatMemberSince($user['created_at'] ?? null),
            'is_online' => $isLive,
            'is_live' => $isLive,
            'followers_count' => $followersCount,
            'following_count' => $followingCount,
            'sales_count' => $salesCount,
            'rating_avg' => (float) ($rating['avg'] ?? 0),
            'rating_count' => (int) ($rating['count'] ?? 0),
            'response_time' => null,
            'is_following' => $isFollowing,
            'is_own' => $isOwn,
            'profile_url' => ProductHelper::url('/profile'),
            'chat_url' => ProductHelper::url('/chat/start?user_id=' . $userId),
            'products' => $products,
            'reviews' => $reviews,
        ];
    }

    private function formatMemberSince(mixed $createdAt): string
    {
        if (!$createdAt) {
            return '';
        }
        $ts = strtotime((string) $createdAt);
        if ($ts === false) {
            return '';
        }
        $months = [
            1 => t('seller.month_1'),
            2 => t('seller.month_2'),
            3 => t('seller.month_3'),
            4 => t('seller.month_4'),
            5 => t('seller.month_5'),
            6 => t('seller.month_6'),
            7 => t('seller.month_7'),
            8 => t('seller.month_8'),
            9 => t('seller.month_9'),
            10 => t('seller.month_10'),
            11 => t('seller.month_11'),
            12 => t('seller.month_12'),
        ];
        $month = $months[(int) date('n', $ts)] ?? date('m', $ts);
        $year = date('Y', $ts);
        return t('seller.member_since', ['month' => $month, 'year' => $year]);
    }
}
