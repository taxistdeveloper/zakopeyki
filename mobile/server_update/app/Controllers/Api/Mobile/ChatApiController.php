<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Helpers\AvatarHelper;
use App\Helpers\ProductHelper;
use App\Models\Chat;
use App\Models\Order;
use App\Models\Product;
use App\Services\MobileApi\Presenter;

/**
 * Сообщения в мобильном приложении: список диалогов, переписка, отправка, начало диалога.
 * Та же модель App\Models\Chat, что и у сайта (/chat): диалоги и уведомления общие.
 * Новые сообщения клиент забирает опросом GET /chats/{id}?after=<последний id>.
 */
final class ChatApiController extends BaseMobileController
{
    private const PAGE = 100;

    /** GET /chats */
    public function index(): void
    {
        $uid = $this->user();
        $chat = new Chat();
        $items = array_map(fn (array $c): array => $this->presentListItem($c), $chat->listForUser($uid));
        $this->ok(['items' => $items, 'unread_total' => $chat->unreadCount($uid)]);
    }

    /** GET /chats/unread — счётчик для бейджа. */
    public function unread(): void
    {
        $uid = $this->user();
        $this->ok(['unread_total' => (new Chat())->unreadCount($uid)]);
    }

    /**
     * GET /chats/{id}?after=<id>
     * Без after — последние 100 сообщений и шапка диалога; с after — только новые (опрос).
     * Входящие сообщения помечаются прочитанными.
     */
    public function show(string $id): void
    {
        $uid = $this->user();
        $chat = new Chat();
        $conv = $chat->findForUser((int) $id, $uid);
        if (!$conv) {
            $this->fail('not_found', 404);
        }
        $after = $this->queryInt('after', 0, 0, PHP_INT_MAX);
        $rows = $chat->messages((int) $id, $after, self::PAGE);
        $chat->markRead((int) $id, $uid);

        $data = [
            'messages' => array_map(fn (array $m): array => $this->presentMessage($m, $uid), $rows),
            'unread_total' => $chat->unreadCount($uid),
        ];
        if ($after === 0) {
            $data['conversation'] = $this->presentConversation($conv, $uid);
        }
        $this->ok($data);
    }

    /** POST /chats/{id}/messages {body} */
    public function sendMessage(string $id): void
    {
        $uid = $this->user();
        $result = (new Chat())->send((int) $id, $uid, (string) ($this->body()['body'] ?? ''));
        if (empty($result['ok'])) {
            $this->fail('chat_send_failed', 422, (string) ($result['error'] ?? t('chat.send_failed')));
        }
        $this->ok(['message' => $this->presentMessage((array) $result['message'], $uid)], [], 201);
    }

    /**
     * POST /chats/start {product_id} | {order_id} | {user_id}
     * Возвращает существующий диалог по паре пользователей и товару или создаёт новый.
     */
    public function start(): void
    {
        $uid = $this->user();
        $productId = (int) $this->input('product_id', '0');
        $orderId = (int) $this->input('order_id', '0');
        $otherId = (int) $this->input('user_id', '0');

        if ($orderId > 0) {
            $order = (new Order())->find($orderId);
            if (!$order || ((int) $order['buyer_id'] !== $uid && (int) $order['seller_id'] !== $uid)) {
                $this->fail('not_found', 404);
            }
            $otherId = (int) $order['buyer_id'] === $uid ? (int) $order['seller_id'] : (int) $order['buyer_id'];
            $productId = (int) $order['product_id'];
        } elseif ($productId > 0) {
            $product = (new Product())->find($productId);
            if (!$product) {
                $this->fail('not_found', 404);
            }
            $otherId = (int) $product['user_id'];
        }
        if ($otherId === $uid) {
            $this->fail('chat_self', 422, t('chat.self'));
        }

        $chat = new Chat();
        $res = $chat->start($uid, $otherId, $productId, $orderId);
        if (empty($res['ok'])) {
            $this->fail('chat_start_failed', 422, (string) ($res['error'] ?? t('chat.start_failed')));
        }
        $conv = $chat->findForUser((int) $res['conversation_id'], $uid);
        if (!$conv) {
            $this->fail('not_found', 404);
        }
        $this->ok(['conversation' => $this->presentConversation($conv, $uid)]);
    }

    // ---------------------------------------------------------------- helpers

    private function user(): int
    {
        $uid = $this->requireUserId();
        $this->guardSiteOpen();

        return $uid;
    }

    /** @param array<string, mixed> $c строка Chat::listForUser */
    private function presentListItem(array $c): array
    {
        return [
            'id' => (int) $c['id'],
            'peer' => [
                'id' => (int) $c['peer_id'],
                'name' => (string) ($c['peer_name'] ?? ''),
                'avatar_url' => Presenter::media(AvatarHelper::url([
                    'avatar' => $c['peer_avatar'] ?? null,
                    'avatar_file' => $c['peer_avatar_file'] ?? null,
                ])),
            ],
            'product' => $this->product($c),
            'order_id' => (int) ($c['order_id'] ?? 0) ?: null,
            'last_preview' => ($c['last_preview'] ?? '') !== '' ? (string) $c['last_preview'] : null,
            'last_message_at' => Presenter::date($c['last_message_at'] ?? $c['created_at'] ?? null),
            'unread_count' => (int) ($c['unread_count'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $c строка Chat::findForUser */
    private function presentConversation(array $c, int $uid): array
    {
        $low = (int) $c['user_low_id'] === $uid;
        $prefix = $low ? 'high' : 'low';

        return [
            'id' => (int) $c['id'],
            'peer' => [
                'id' => $low ? (int) $c['user_high_id'] : (int) $c['user_low_id'],
                'name' => (string) ($c[$prefix . '_name'] ?? ''),
                'avatar_url' => Presenter::media(AvatarHelper::url([
                    'avatar' => $c[$prefix . '_avatar'] ?? null,
                    'avatar_file' => $c[$prefix . '_avatar_file'] ?? null,
                ])),
            ],
            'product' => $this->product($c),
            'order_id' => (int) ($c['order_id'] ?? 0) ?: null,
        ];
    }

    /** @param array<string, mixed> $c */
    private function product(array $c): ?array
    {
        $pid = (int) ($c['product_id'] ?? 0);
        if ($pid <= 0 || ($c['product_title'] ?? null) === null) {
            return null;
        }

        return [
            'id' => $pid,
            'title' => (string) $c['product_title'],
            'image_url' => Presenter::media(ProductHelper::imageUrl([
                'image' => $c['product_image'] ?? null,
                'images' => $c['product_images'] ?? null,
            ])),
        ];
    }

    /** @param array<string, mixed> $m */
    private function presentMessage(array $m, int $uid): array
    {
        $storyId = (int) ($m['story_ref_id'] ?? $m['story_id'] ?? 0);

        return [
            'id' => (int) $m['id'],
            'sender_id' => (int) $m['sender_id'],
            'is_mine' => (int) $m['sender_id'] === $uid,
            'body' => (string) ($m['body'] ?? ''),
            'is_read' => !empty($m['is_read']),
            'story_reply' => $storyId > 0,
            'created_at' => Presenter::date($m['created_at'] ?? null),
        ];
    }
}
