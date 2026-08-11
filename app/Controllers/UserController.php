<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\AvatarHelper;
use App\Helpers\ProductHelper;
use App\Models\Follow;
use App\Models\Product;
use App\Models\Review;
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

        // Deep link: открываем главную с модалкой продавца
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

        $login = (string) ($user['login'] ?? '');
        if ($login === '' && !empty($user['email'])) {
            $login = (string) (strstr((string) $user['email'], '@', true) ?: '');
        }

        $products = [];
        foreach ((new Product())->activeShopByUser($userId, 24) as $p) {
            $products[] = [
                'id' => (int) $p['id'],
                'title' => (string) ($p['title'] ?? ''),
                'price_label' => ProductHelper::formatPrice($p),
                'image' => ProductHelper::imageUrl($p),
                'url' => ProductHelper::url('/product/' . (int) $p['id']),
            ];
        }

        return [
            'ok' => true,
            'id' => $userId,
            'name' => (string) ($user['name'] ?? ''),
            'login' => $login,
            'bio' => (string) ($user['bio'] ?? ''),
            'avatar_url' => AvatarHelper::url($user),
            'avatar_initial' => AvatarHelper::initial($user),
            'followers_count' => $followersCount,
            'rating_avg' => (float) ($rating['avg'] ?? 0),
            'rating_count' => (int) ($rating['count'] ?? 0),
            'is_following' => $isFollowing,
            'is_own' => $isOwn,
            'profile_url' => ProductHelper::url('/profile'),
            'products' => $products,
        ];
    }
}
