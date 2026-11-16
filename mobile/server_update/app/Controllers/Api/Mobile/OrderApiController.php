<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Helpers\ProductHelper;
use App\Helpers\ActivityLogger;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Review;
use App\Services\HalykEpay\IntentStore;
use App\Services\EscrowService;
use App\Services\MobileApi\Presenter;
use App\Services\ReturnService;

/**
 * Сделки в мобильном приложении (M-07): список, карточка, действия сторон.
 * Все переходы статусов — EscrowService / ReturnService (тот же код, что на сайте);
 * поле `actions` — подсказка для интерфейса, окончательную проверку делает сервис.
 * Переговоры по возврату (частичный возврат, отправка возврата) и арбитраж — на сайте.
 */
final class OrderApiController extends BaseMobileController
{
    private const FINAL = ['completed', 'refunded', 'partial_refunded', 'cancelled'];

    /** GET /orders?role=buying|selling */
    public function index(): void
    {
        $uid = $this->requireUserId();
        $role = $this->queryString('role', 10) === 'selling' ? 'selling' : 'buying';
        $rows = array_filter(
            (new Order())->forUser($uid),
            static fn (array $o): bool => $role === 'selling' ? (int) $o['seller_id'] === $uid : (int) $o['buyer_id'] === $uid
        );
        $this->ok(['items' => array_values(array_map(fn (array $o): array => $this->present($o, $uid, false), $rows))]);
    }

    /** GET /orders/{id} */
    public function show(string $id): void
    {
        $uid = $this->requireUserId();
        $order = $this->ownOrder((int) $id, $uid);
        // Ленивая обработка сроков (24 ч на проверку и т.п.) — после проверки доступа, как на сайте
        (new EscrowService())->processDeadlines((int) $id);
        $order = (new Order())->findWithDetails((int) $id) ?? $order;
        $this->ok(['order' => $this->present($order, $uid, true)]);
    }

    /** POST /orders/{id}/ship {tracking, carrier?} — продавец */
    public function ship(string $id): void
    {
        $uid = $this->requireUserId();
        $this->ownOrder((int) $id, $uid);
        $this->result((new EscrowService())->addTracking((int) $id, $uid, $this->input('tracking'), $this->input('carrier')), (int) $id, $uid);
    }

    /** POST /orders/{id}/delivered — покупатель получил товар (запускает 24 ч на проверку) */
    public function delivered(string $id): void
    {
        $uid = $this->requireUserId();
        $this->ownOrder((int) $id, $uid);
        $this->result((new EscrowService())->markDelivered((int) $id, $uid), (int) $id, $uid);
    }

    /** POST /orders/{id}/confirm — покупатель подтверждает, деньги уходят продавцу */
    public function confirm(string $id): void
    {
        $uid = $this->requireUserId();
        $this->ownOrder((int) $id, $uid);
        $this->result((new EscrowService())->confirmReceived((int) $id, $uid), (int) $id, $uid);
    }

    /** POST /orders/{id}/cancel — покупатель до отправки */
    public function cancel(string $id): void
    {
        $uid = $this->requireUserId();
        $this->ownOrder((int) $id, $uid);
        $this->result((new EscrowService())->cancelByBuyer((int) $id, $uid), (int) $id, $uid);
    }

    /** POST /orders/{id}/dispute {reason, comment} — открыть возврат/спор (без фото-доказательств в v1) */
    public function dispute(string $id): void
    {
        $uid = $this->requireUserId();
        $this->ownOrder((int) $id, $uid);
        $this->result(
            (new ReturnService())->openCase((int) $id, $uid, $this->input('reason'), mb_substr($this->input('comment'), 0, 2000)),
            (int) $id,
            $uid
        );
    }

    /**
     * POST /orders/{id}/pay — вернуться к оплате картой заказа в статусе awaiting_payment.
     * Ссылка на форму банка живёт 24 ч (IntentStore); после этого заказ остаётся только отменить.
     */
    public function pay(string $id): void
    {
        $uid = $this->requireUserId();
        $order = $this->ownOrder((int) $id, $uid);
        if ((int) $order['buyer_id'] !== $uid || ($order['status'] ?? '') !== 'awaiting_payment') {
            $this->fail('order_action_failed', 422, self::message('order_not_payable'));
        }
        $payment = (new Payment())->findByOrderId((int) $id);
        $pgPaymentId = (string) ($payment['pg_payment_id'] ?? '');
        $intent = str_starts_with($pgPaymentId, 'halyk-')
            ? IntentStore::getByInvoice(substr($pgPaymentId, 6))
            : null;
        if (!$intent || empty($intent['token'])) {
            $this->fail('payment_expired', 410, self::message('payment_expired'));
        }
        $this->ok(['payment_url' => ProductHelper::absoluteUrl('/payments/halyk/app/' . $intent['token'])]);
    }

    /** POST /orders/{id}/review {rating: 1..5, body?} — после завершённой сделки, один раз. */
    public function review(string $id): void
    {
        $uid = $this->requireUserId();
        $this->ownOrder((int) $id, $uid);
        $res = (new Review())->createForOrder((int) $id, $uid, (int) $this->input('rating', '0'), (string) ($this->body()['body'] ?? ''));
        if (empty($res['ok'])) {
            $this->fail('review_failed', 422, (string) ($res['error'] ?? t('reviews.save_fail')));
        }
        ActivityLogger::info('order.review', 'Отзыв по сделке (mobile) #' . (int) $id, 'order', (int) $id);
        $order = (new Order())->findWithDetails((int) $id) ?? [];
        $this->ok(['order' => $this->present($order, $uid, true)]);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function ownOrder(int $id, int $uid): array
    {
        $order = (new Order())->findWithDetails($id);
        if (!$order || ((int) $order['buyer_id'] !== $uid && (int) $order['seller_id'] !== $uid)) {
            $this->fail('not_found', 404);
        }

        return $order;
    }

    /** @param array<string, mixed> $res */
    private function result(array $res, int $id, int $uid): never
    {
        if (empty($res['ok'])) {
            $this->fail('order_action_failed', 422, (string) ($res['error'] ?? self::message('validation_failed')));
        }
        $order = (new Order())->findWithDetails($id) ?? [];
        $this->ok(['order' => $this->present($order, $uid, true)]);
    }

    /**
     * @param array<string, mixed> $o
     * @return array<string, mixed>
     */
    private function present(array $o, int $uid, bool $detail): array
    {
        $isBuyer = (int) $o['buyer_id'] === $uid;
        $status = (string) ($o['status'] ?? '');
        $amount = (int) ($o['amount'] ?? 0);
        $fee = (int) ($o['arbitration_fee'] ?? 0);
        $image = ProductHelper::imageUrl([
            'image' => $o['product_image'] ?? ($o['image'] ?? null),
            'images' => $o['product_images'] ?? ($o['images'] ?? null),
        ]);

        $out = [
            'id' => (int) $o['id'],
            'role' => $isBuyer ? 'buyer' : 'seller',
            'status' => $status,
            'status_label' => t('escrow.status_' . $status),
            'deal_mode' => (string) ($o['deal_mode'] ?? 'escrow'),
            'product' => [
                'id' => (int) $o['product_id'],
                'title' => (string) ($o['product_title'] ?? $o['title'] ?? ''),
                'image_url' => Presenter::media($image),
            ],
            'quantity' => (int) ($o['quantity'] ?? 1),
            'amount' => ['amount' => $amount, 'currency' => 'KZT'],
            'arbitration_fee' => $fee > 0 ? ['amount' => $fee, 'currency' => 'KZT'] : null,
            'total' => ['amount' => $amount + $fee, 'currency' => 'KZT'],
            'counterparty' => $isBuyer ? (string) ($o['seller_name'] ?? '') : (string) ($o['buyer_name'] ?? ''),
            'created_at' => Presenter::date($o['created_at'] ?? null),
            'is_final' => in_array($status, self::FINAL, true),
            'actions' => $this->actions($o, $isBuyer),
        ];
        if (!$detail) {
            return $out;
        }

        return $out + [
            'delivery_method' => (string) ($o['delivery_method'] ?? ''),
            'tracking_number' => ($o['tracking_number'] ?? '') !== '' ? (string) $o['tracking_number'] : null,
            'carrier' => ($o['carrier'] ?? '') !== '' ? (string) $o['carrier'] : null,
            'inspect_until' => Presenter::date($o['inspect_until'] ?? null),
            'seller_payout' => !$isBuyer && ($o['deal_mode'] ?? 'escrow') === 'escrow'
                ? ['amount' => $amount - EscrowService::sellerCommission($amount), 'currency' => 'KZT']
                : null,
            'dispute_reasons' => $isBuyer ? ReturnService::REASONS : [],
            'my_review' => $this->myReview((int) $o['id'], $uid),
            'can_review' => $status === 'completed' && $this->myReview((int) $o['id'], $uid) === null,
            'web_url' => ProductHelper::absoluteUrl('/orders/' . (int) $o['id']),
        ];
    }

    /** @return array{rating: int, body: ?string}|null */
    private function myReview(int $orderId, int $uid): ?array
    {
        $r = (new Review())->findByOrderAndAuthor($orderId, $uid);

        return $r ? ['rating' => (int) $r['rating'], 'body' => ($r['body'] ?? '') !== '' ? (string) $r['body'] : null] : null;
    }

    /**
     * Подсказка для интерфейса, повторяет предусловия EscrowService/ReturnService.
     *
     * @param array<string, mixed> $o
     * @return list<string>
     */
    private function actions(array $o, bool $isBuyer): array
    {
        $status = (string) ($o['status'] ?? '');
        $escrow = ($o['deal_mode'] ?? 'escrow') === 'escrow';
        if (!$escrow) {
            return [];
        }
        if (!$isBuyer) {
            return $status === 'escrowed' ? ['ship'] : [];
        }

        return match ($status) {
            'awaiting_payment' => ['pay', 'cancel'],
            'escrowed' => ['cancel'],
            'shipped' => ['delivered', 'confirm', 'dispute'],
            'delivered' => (strtotime((string) ($o['inspect_until'] ?? '')) ?: 0) > time() ? ['confirm', 'dispute'] : ['confirm'],
            default => [],
        };
    }
}
