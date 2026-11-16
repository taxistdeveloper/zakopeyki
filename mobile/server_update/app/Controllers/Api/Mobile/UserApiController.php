<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Helpers\ActivityLogger;
use App\Helpers\AvatarHelper;
use App\Helpers\ProductHelper;
use App\Models\Favorite;
use App\Models\Follow;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Services\AuctionService;
use App\Services\MobileApi\Presenter;

/**
 * Публичный профиль продавца (+ подписка) и ставки на аукционах.
 * Логика — та же, что у сайта (UserController::buildProfile, AuctionController::bid / buyNow).
 */
final class UserApiController extends BaseMobileController
{
    /** GET /users/{id} */
    public function show(string $id): void
    {
        $this->guardSiteOpen();
        $userId = (int) $id;
        $user = $userId > 0 ? (new User())->find($userId) : null;
        if (!$user) {
            $this->fail('not_found', 404);
        }
        $me = $this->optionalUserId();
        $follow = new Follow();
        $rating = (new Review())->statsFor($userId);
        $favorites = $me !== null ? array_fill_keys((new Favorite())->idsForUser($me), true) : [];

        $sellerFields = [
            'seller_name' => $user['name'] ?? '',
            'seller_account_type' => $user['account_type'] ?? null,
            'seller_business_status' => $user['business_status'] ?? null,
            'seller_business_name' => $user['business_name'] ?? null,
        ];
        $items = array_map(
            static fn (array $p): array => Presenter::card($p + $sellerFields, $favorites),
            (new Product())->activeShopByUser($userId, 60)
        );

        $reviews = [];
        foreach ((new Review())->forSubject($userId, 20) as $r) {
            $author = ['name' => $r['author_name'] ?? '', 'avatar' => $r['author_avatar'] ?? null, 'avatar_file' => $r['author_avatar_file'] ?? null];
            $title = trim((string) ($r['product_title'] ?? '')) ?: trim((string) ($r['task_title'] ?? ''));
            $reviews[] = [
                'id' => (int) ($r['id'] ?? 0),
                'rating' => (int) ($r['rating'] ?? 0),
                'comment' => (string) ($r['body'] ?? ''),
                'author_name' => (string) ($r['author_name'] ?? ''),
                'author_avatar_url' => Presenter::media(AvatarHelper::url($author)),
                'product_title' => $title !== '' ? $title : null,
                'created_at' => Presenter::date($r['created_at'] ?? null),
            ];
        }

        $isBusiness = ProductHelper::sellerIsBusiness($sellerFields);
        $this->ok(['user' => [
            'id' => $userId,
            'name' => $isBusiness && ($user['business_name'] ?? '') !== '' ? (string) $user['business_name'] : (string) ($user['name'] ?? ''),
            'avatar_url' => Presenter::media(AvatarHelper::url($user)),
            'is_business' => $isBusiness,
            'bio' => ($user['bio'] ?? '') !== '' ? (string) $user['bio'] : null,
            'city' => ($user['ship_city'] ?? '') !== '' ? (string) $user['ship_city'] : null,
            'member_since' => Presenter::date($user['created_at'] ?? null),
            'rating' => ['average' => (float) ($rating['avg'] ?? 0), 'count' => (int) ($rating['count'] ?? 0)],
            'followers_count' => $follow->countFollowers($userId),
            'sales_count' => (new Order())->countCompletedSales($userId),
            'is_me' => $me === $userId,
            'is_following' => $me !== null && $me !== $userId && $follow->isFollowing($me, $userId),
            'items' => $items,
            'reviews' => $reviews,
        ]]);
    }

    /** POST /users/{id}/follow — подписаться / отписаться */
    public function follow(string $id): void
    {
        $uid = $this->requireUserId();
        $res = (new Follow())->toggle($uid, (int) $id);
        if (empty($res['ok'])) {
            $this->fail('follow_failed', 422, (string) ($res['error'] ?? t('seller.follow_error')));
        }
        $this->ok(['is_following' => (bool) ($res['following'] ?? false), 'followers_count' => (int) ($res['followers_count'] ?? 0)]);
    }

    // ---------------------------------------------------------------- аукционы

    /** GET /auctions/{id} — текущая цена, шаг, ставки (для живого обновления) */
    public function auction(string $id): void
    {
        $this->guardSiteOpen();
        $payload = (new AuctionService())->livePayload((int) $id);
        if (!$payload) {
            $this->fail('not_found', 404);
        }
        $this->ok(['auction' => $this->presentAuction($payload)]);
    }

    /** POST /auctions/{id}/bid {amount} */
    public function bid(string $id): void
    {
        $uid = $this->requireUserId();
        $this->guardSiteOpen();
        $productId = (int) $id;
        $amount = (int) preg_replace('/\D/', '', $this->input('amount', '0'));
        $svc = new AuctionService();
        $res = $svc->placeBid($productId, $uid, $amount);
        if (empty($res['ok'])) {
            $this->fail('bid_failed', 422, (string) ($res['error'] ?? t('auctions.err_bid_failed')));
        }
        $final = (int) ($res['amount'] ?? $amount);
        ActivityLogger::info('auction.bid', 'Ставка (mobile) ' . number_format($final, 0, '', ' ') . ' ₸', 'product', $productId, ['amount' => $final]);
        $this->notifySeller($svc, $productId, $uid, 'auctions.notify_bid', $final);
        $this->ok(['auction' => $this->presentAuction($svc->livePayload($productId) ?? []), 'message' => t('auctions.bid_ok')]);
    }

    /** POST /auctions/{id}/buy-now */
    public function buyNow(string $id): void
    {
        $uid = $this->requireUserId();
        $this->guardSiteOpen();
        $productId = (int) $id;
        $svc = new AuctionService();
        $res = $svc->buyNow($productId, $uid);
        if (empty($res['ok'])) {
            $this->fail('bid_failed', 422, (string) ($res['error'] ?? t('auctions.err_bid_failed')));
        }
        $final = (int) ($res['amount'] ?? 0);
        ActivityLogger::info('auction.buy_now', 'Купить сейчас (mobile) ' . number_format($final, 0, '', ' ') . ' ₸', 'product', $productId, ['amount' => $final]);
        $this->notifySeller($svc, $productId, $uid, 'auctions.notify_buy_now', $final);
        $this->ok(['auction' => $this->presentAuction($svc->livePayload($productId) ?? []), 'message' => t('auctions.buy_now_ok')]);
    }

    private function notifySeller(AuctionService $svc, int $productId, int $uid, string $key, int $amount): void
    {
        $details = $svc->details($productId);
        if ($details && (int) $details['user_id'] !== $uid) {
            (new Notification())->createFor((int) $details['user_id'], t($key, [
                'amount' => number_format($amount, 0, '', ' '),
                'title' => $details['title'],
            ]));
        }
    }

    /** @param array<string, mixed> $p AuctionService::livePayload */
    private function presentAuction(array $p): array
    {
        $uid = $this->optionalUserId();

        return [
            'id' => (int) ($p['id'] ?? 0),
            'status' => (string) ($p['status'] ?? ''),
            'kind' => (string) ($p['kind'] ?? 'english'),
            'current_price' => (int) ($p['current_price'] ?? 0),
            'bid_step' => (int) ($p['bid_step'] ?? 0),
            'min_bid' => (int) ($p['current_price'] ?? 0) + max(1, (int) ($p['bid_step'] ?? 0)),
            'buy_now_price' => !empty($p['buy_now_price']) ? (int) $p['buy_now_price'] : null,
            'ends_at' => Presenter::date($p['end_at'] ?? null),
            'is_winner' => $uid !== null && (int) ($p['winner_user_id'] ?? 0) === $uid,
            'bids' => array_map(static fn (array $b): array => [
                'user' => (string) ($b['user'] ?? ''),
                'amount' => (int) ($b['amount'] ?? 0),
                'created_at' => Presenter::date($b['created_at'] ?? null),
            ], $p['bids'] ?? []),
        ];
    }
}
