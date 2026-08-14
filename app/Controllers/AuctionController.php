<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ActivityLogger;
use App\Models\Favorite;
use App\Models\Notification;
use App\Services\AuctionService;

class AuctionController extends Controller
{
    public function index(): void
    {
        $service = new AuctionService();
        $items = $service->listActive();

        $notifications = [];
        $unread = 0;
        $favoriteIds = [];
        if (Auth::check()) {
            $n = new Notification();
            $notifications = $n->forUser(Auth::id());
            $unread = $n->unreadCount(Auth::id());
            $favoriteIds = (new Favorite())->idsForUser(Auth::id());
        }

        $this->view('auctions/index', [
            'title' => t('auctions.title'),
            'currentNav' => 'auctions',
            'items' => $items,
            'notifications' => $notifications,
            'unread' => $unread,
            'favoriteIds' => $favoriteIds,
            'search' => '',
        ]);
    }

    public function live(string $id): void
    {
        $payload = (new AuctionService())->livePayload((int) $id);
        if (!$payload) {
            $this->json(['ok' => false, 'error' => t('auctions.err_not_found')], 404);
        }
        $this->json(['ok' => true, 'data' => $payload]);
    }

    public function bid(string $id): void
    {
        Auth::requireLogin();
        $productId = (int) $id;
        $amount = (int) preg_replace('/\D/', '', (string) ($_POST['amount'] ?? '0'));

        $result = (new AuctionService())->placeBid($productId, Auth::id(), $amount);

        if ($result['ok']) {
            ActivityLogger::info('auction.bid', 'Ставка ' . number_format((int) ($result['amount'] ?? $amount), 0, '', ' ') . ' ₸', 'product', $productId, [
                'amount' => $result['amount'] ?? $amount,
            ]);
            $details = (new AuctionService())->details($productId);
            if ($details && (int) $details['user_id'] !== Auth::id()) {
                (new Notification())->createFor(
                    (int) $details['user_id'],
                    t('auctions.notify_bid', [
                        'amount' => number_format((int) ($result['amount'] ?? $amount), 0, '', ' '),
                        'title' => $details['title'],
                    ])
                );
            }
        } else {
            ActivityLogger::warning('auction.bid', $result['error'] ?? 'Ошибка ставки', 'product', $productId, [
                'amount' => $amount,
            ]);
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            $this->json($result, $result['ok'] ? 200 : 422);
        }

        $_SESSION[$result['ok'] ? 'flash' : 'error'] = $result['ok'] ? t('auctions.bid_ok') : ($result['error'] ?? t('auctions.err_bid_failed'));
        $this->redirect('/product/' . $productId);
    }

    public function buyNow(string $id): void
    {
        Auth::requireLogin();
        $productId = (int) $id;
        $result = (new AuctionService())->buyNow($productId, Auth::id());

        if ($result['ok']) {
            ActivityLogger::info('auction.buy_now', 'Купить сейчас ' . number_format((int) ($result['amount'] ?? 0), 0, '', ' ') . ' ₸', 'product', $productId, [
                'amount' => $result['amount'] ?? 0,
            ]);
            $details = (new AuctionService())->details($productId);
            if ($details && (int) $details['user_id'] !== Auth::id()) {
                (new Notification())->createFor(
                    (int) $details['user_id'],
                    t('auctions.notify_buy_now', [
                        'amount' => number_format((int) ($result['amount'] ?? 0), 0, '', ' '),
                        'title' => $details['title'],
                    ])
                );
            }
        } else {
            ActivityLogger::warning('auction.buy_now', $result['error'] ?? 'Ошибка выкупа', 'product', $productId);
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            $this->json($result, $result['ok'] ? 200 : 422);
        }

        $_SESSION[$result['ok'] ? 'flash' : 'error'] = $result['ok']
            ? t('auctions.buy_now_ok')
            : ($result['error'] ?? t('auctions.err_bid_failed'));
        $this->redirect('/product/' . $productId);
    }

    public function accept(string $id): void
    {
        Auth::requireLogin();
        $productId = (int) $id;
        $result = (new AuctionService())->acceptHighest($productId, Auth::id());

        if ($result['ok']) {
            ActivityLogger::info('auction.accept', 'Продавец принял высшую ставку', 'product', $productId);
            $_SESSION['flash'] = t('auctions.accept_ok');
        } else {
            $_SESSION['error'] = $result['error'] ?? t('auctions.err_bid_failed');
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            $this->json($result, $result['ok'] ? 200 : 422);
        }

        $this->redirect('/product/' . $productId);
    }
}
