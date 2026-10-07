<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\AvatarHelper;
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
        $wantsJson = $this->wantsJson();

        if ($orderId > 0) {
            $order = (new Order())->find($orderId);
            if (!$order || ((int) $order['buyer_id'] !== $uid && (int) $order['seller_id'] !== $uid)) {
                if ($wantsJson) {
                    $this->json(['ok' => false, 'error' => t('chat.forbidden')], 403);
                }
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
                if ($wantsJson) {
                    $this->json(['ok' => false, 'error' => t('product.not_found')], 404);
                }
                $_SESSION['error'] = t('product.not_found');
                $this->redirect('/');
                return;
            }
            $otherId = (int) $product['user_id'];
            if ($otherId === $uid) {
                if ($wantsJson) {
                    $this->json(['ok' => false, 'error' => t('chat.self')], 422);
                }
                $_SESSION['error'] = t('chat.self');
                $this->redirect('/product/' . $productId);
                return;
            }
        }

        $chat = new Chat();
        $result = $chat->start($uid, $otherId, $productId, $orderId);
        if (!$result['ok']) {
            if ($wantsJson) {
                $this->json(['ok' => false, 'error' => $result['error'] ?? t('chat.start_failed')], 422);
            }
            $_SESSION['error'] = $result['error'] ?? t('chat.start_failed');
            $this->redirect('/chat');
            return;
        }

        $conversationId = (int) $result['conversation_id'];

        if ($wantsJson) {
            $this->json($this->threadPayload($chat, $conversationId, $uid));
        }

        $this->redirect('/chat/' . $conversationId);
    }

    public function thread(string $id): void
    {
        Auth::requireLogin();
        $uid = Auth::id();
        $chat = new Chat();
        $conversationId = (int) $id;

        if (!$chat->findForUser($conversationId, $uid)) {
            $this->json(['ok' => false, 'error' => t('chat.forbidden')], 403);
        }

        $this->json($this->threadPayload($chat, $conversationId, $uid));
    }

    public function send(string $id): void
    {
        Auth::requireLogin();
        $wantsJson = $this->wantsJson();
        try {
            $storyId = (int) ($_POST['story_id'] ?? 0);
            $result = (new Chat())->send(
                (int) $id,
                Auth::id(),
                (string) ($_POST['body'] ?? ''),
                $storyId > 0 ? $storyId : null
            );
        } catch (\Throwable) {
            $result = ['ok' => false, 'error' => t('chat.send_failed')];
        }

        if ($wantsJson) {
            if (!$result['ok']) {
                $this->json(['ok' => false, 'error' => $result['error'] ?? t('chat.send_failed')], 422);
            }
            $this->json(['ok' => true, 'message' => $this->formatMessage($result['message'] ?? null)]);
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

    private function wantsJson(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /** @return array<string, mixed> */
    private function threadPayload(Chat $chat, int $conversationId, int $uid): array
    {
        $conversation = $chat->findForUser($conversationId, $uid);
        if (!$conversation) {
            return ['ok' => false, 'error' => t('chat.not_found')];
        }

        $chat->markRead($conversationId, $uid);
        $messages = array_map(fn ($m) => $this->formatMessage($m), $chat->messages($conversationId));

        $peerId = (int) $conversation['user_low_id'] === $uid
            ? (int) $conversation['user_high_id']
            : (int) $conversation['user_low_id'];
        $peerName = (int) $conversation['user_low_id'] === $uid
            ? (string) $conversation['high_name']
            : (string) $conversation['low_name'];

        return [
            'ok' => true,
            'conversation_id' => $conversationId,
            'peer' => [
                'id' => $peerId,
                'name' => $peerName,
            ],
            'product_title' => (string) ($conversation['product_title'] ?? ''),
            'product_id' => (int) ($conversation['product_id'] ?? 0),
            'order_id' => (int) ($conversation['order_id'] ?? 0),
            'messages' => $messages,
            'unread' => $chat->unreadCount($uid),
        ];
    }

    /** @param array|null $m */
    private function formatMessage(?array $m): ?array
    {
        if (!$m) {
            return null;
        }

        $story = null;
        if (!empty($m['story_ref_id']) || !empty($m['story_id'])) {
            $storyId = (int) ($m['story_ref_id'] ?? $m['story_id']);
            if ($storyId > 0 && !empty($m['story_user_id'])) {
                $storyUser = [
                    'name' => (string) ($m['story_user_name'] ?? ''),
                    'avatar' => (string) ($m['story_user_avatar'] ?? ''),
                    'avatar_file' => $m['story_user_avatar_file'] ?? null,
                ];
                $story = [
                    'id' => $storyId,
                    'user_id' => (int) $m['story_user_id'],
                    'user_name' => $storyUser['name'],
                    'user_avatar' => AvatarHelper::initial($storyUser),
                    'avatar_url' => AvatarHelper::url($storyUser),
                    'image' => $m['story_image'] ?? null,
                    'caption' => (string) ($m['story_caption'] ?? ''),
                    'bg_color' => (string) ($m['story_bg_color'] ?? '#2563EB'),
                    'emoji' => (string) ($m['story_emoji'] ?? '✨'),
                    'created_at' => (string) ($m['story_created_at'] ?? ''),
                    'comments_enabled' => (int) ($m['story_comments_enabled'] ?? 1),
                    'product' => null,
                ];
            }
        }

        return [
            'id' => (int) $m['id'],
            'sender_id' => (int) $m['sender_id'],
            'sender_name' => (string) ($m['sender_name'] ?? ''),
            'body' => (string) $m['body'],
            'story_id' => (int) ($m['story_id'] ?? 0),
            'story' => $story,
            'created_at' => (string) ($m['created_at'] ?? ''),
            'is_mine' => (int) $m['sender_id'] === Auth::id(),
        ];
    }
}
