<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Chat;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;

class ChatController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $uid = Auth::id();
        $chat = new Chat();
        $n = new Notification();

        $this->view('chat/index', [
            'title' => t('chat.title'),
            'currentNav' => 'chat',
            'conversations' => $chat->listForUser($uid),
            'chatUnread' => $chat->unreadCount($uid),
            'notifications' => $n->forUser($uid),
            'unread' => $n->unreadCount($uid),
            'search' => '',
        ]);
    }

    public function show(string $id): void
    {
        Auth::requireLogin();
        $uid = Auth::id();
        $chat = new Chat();
        $conversationId = (int) $id;

        $conversation = $chat->findForUser($conversationId, $uid);
        if (!$conversation) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('chat.not_found')]);
            return;
        }

        $chat->markRead($conversationId, $uid);
        $messages = $chat->messages($conversationId);
        $n = new Notification();

        $peerId = (int) $conversation['user_low_id'] === $uid
            ? (int) $conversation['user_high_id']
            : (int) $conversation['user_low_id'];
        $peer = [
            'id' => $peerId,
            'name' => (int) $conversation['user_low_id'] === $uid
                ? $conversation['high_name']
                : $conversation['low_name'],
            'avatar' => (int) $conversation['user_low_id'] === $uid
                ? $conversation['high_avatar']
                : $conversation['low_avatar'],
            'avatar_file' => (int) $conversation['user_low_id'] === $uid
                ? $conversation['high_avatar_file']
                : $conversation['low_avatar_file'],
        ];

        $this->view('chat/show', [
            'title' => t('chat.with', ['name' => $peer['name']]),
            'currentNav' => 'chat',
            'conversation' => $conversation,
            'peer' => $peer,
            'messages' => $messages,
            'chatUnread' => $chat->unreadCount($uid),
            'notifications' => $n->forUser($uid),
            'unread' => $n->unreadCount($uid),
            'search' => '',
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['error']);
    }

    public function start(): void
    {
        Auth::requireLogin();
        $uid = Auth::id();
        $productId = (int) ($_POST['product_id'] ?? $_GET['product_id'] ?? 0);
        $orderId = (int) ($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
        $otherId = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);

        if ($orderId > 0) {
            $order = (new Order())->find($orderId);
            if (!$order || ((int) $order['buyer_id'] !== $uid && (int) $order['seller_id'] !== $uid)) {
                $_SESSION['error'] = t('chat.forbidden');
                $this->redirect('/chat');
                return;
            }
            $otherId = (int) $order['buyer_id'] === $uid
                ? (int) $order['seller_id']
                : (int) $order['buyer_id'];
            $productId = (int) $order['product_id'];
        } elseif ($productId > 0) {
            $product = (new Product())->find($productId);
            if (!$product) {
                $_SESSION['error'] = t('product.not_found');
                $this->redirect('/');
                return;
            }
            $otherId = (int) $product['user_id'];
        }

        $result = (new Chat())->start($uid, $otherId, $productId, $orderId);
        if (!$result['ok']) {
            $_SESSION['error'] = $result['error'] ?? t('chat.start_failed');
            $this->redirect('/chat');
            return;
        }

        $this->redirect('/chat/' . (int) $result['conversation_id']);
    }

    public function send(string $id): void
    {
        Auth::requireLogin();
        $result = (new Chat())->send((int) $id, Auth::id(), (string) ($_POST['body'] ?? ''));

        $wantsJson = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

        if ($wantsJson) {
            if (!$result['ok']) {
                $this->json(['ok' => false, 'error' => $result['error'] ?? t('chat.send_failed')], 422);
            }
            $this->json(['ok' => true, 'message' => $this->formatMessage($result['message'])]);
        }

        if (!$result['ok']) {
            $_SESSION['error'] = $result['error'] ?? t('chat.send_failed');
        }
        $this->redirect('/chat/' . (int) $id);
    }

    public function poll(string $id): void
    {
        Auth::requireLogin();
        $uid = Auth::id();
        $chat = new Chat();
        $conversationId = (int) $id;

        if (!$chat->findForUser($conversationId, $uid)) {
            $this->json(['ok' => false, 'error' => t('chat.forbidden')], 403);
        }

        $after = (int) ($_GET['after'] ?? 0);
        $rows = $chat->messages($conversationId, $after, 50);
        $chat->markRead($conversationId, $uid);

        $messages = array_map(fn ($m) => $this->formatMessage($m), $rows);
        $this->json([
            'ok' => true,
            'messages' => $messages,
            'unread' => $chat->unreadCount($uid),
        ]);
    }

    /** @param array|null $m */
    private function formatMessage(?array $m): ?array
    {
        if (!$m) {
            return null;
        }
        return [
            'id' => (int) $m['id'],
            'sender_id' => (int) $m['sender_id'],
            'sender_name' => (string) ($m['sender_name'] ?? ''),
            'body' => (string) $m['body'],
            'created_at' => (string) ($m['created_at'] ?? ''),
            'is_mine' => (int) $m['sender_id'] === Auth::id(),
        ];
    }
}
