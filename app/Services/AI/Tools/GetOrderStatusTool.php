<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Models\Order;
use App\Services\AI\Core\PermissionChecker;

final class GetOrderStatusTool extends AbstractTool
{
    public function __construct(
        private readonly Order $orders = new Order(),
        private readonly PermissionChecker $permissions = new PermissionChecker(),
    ) {
    }

    public function name(): string
    {
        return 'get_order_status';
    }

    public function description(): string
    {
        return 'Получить статус заказа пользователя (только свой заказ).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer'],
            ],
            'required' => ['order_id'],
        ];
    }

    public function requiredPermission(): string
    {
        return 'user';
    }

    public function execute(array $input, array $ctx): array
    {
        $orderId = (int) ($input['order_id'] ?? 0);
        $userId = $ctx['user_id'] ?? null;

        if ($orderId <= 0) {
            // Активный заказ пользователя
            if ($userId) {
                $mine = $this->orders->findActiveForUser((int) $userId);
                if ($mine) {
                    return $this->ok($this->serialize($mine));
                }
            }
            return $this->fail('Укажите номер заказа или оформите заказ.');
        }

        $order = $this->orders->find($orderId);
        if (!$order) {
            return $this->fail('Заказ не найден в системе.');
        }

        if (!$this->permissions->ownsOrder($order, $userId)) {
            return $this->fail('Нет доступа к этому заказу.');
        }

        return $this->ok($this->serialize($order));
    }

    private function serialize(array $order): array
    {
        $status = (string) ($order['status'] ?? '');
        return [
            'order_id' => (int) $order['id'],
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'amount' => (int) ($order['amount'] ?? 0),
            'escrow_hold' => $order['escrow_hold'] ?? null,
            'tracking_number' => $order['tracking_number'] ?? null,
            'carrier' => $order['carrier'] ?? null,
            'shipped_at' => $order['shipped_at'] ?? null,
            'delivered_at' => $order['delivered_at'] ?? null,
            'next_step' => $this->nextStep($status),
            'source' => 'mysql.orders',
            'data_timestamp' => date('c'),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'awaiting_payment' => 'Ожидает оплаты',
            'escrowed' => 'Оплачен, средства в эскроу — продавец готовит отправку',
            'shipped' => 'Отправлен',
            'delivered' => 'Доставлен — подтвердите получение',
            'completed' => 'Завершён',
            'cancelled' => 'Отменён',
            'refunded' => 'Возврат средств',
            'disputed' => 'Открыт спор',
            default => $status !== '' ? $status : 'Неизвестно',
        };
    }

    private function nextStep(string $status): string
    {
        return match ($status) {
            'awaiting_payment' => 'Оплатите заказ.',
            'escrowed' => 'Дождитесь отправки продавцом.',
            'shipped' => 'Дождитесь доставки и подтвердите получение.',
            'delivered' => 'Проверьте товар и подтвердите получение в разделе «Мои заказы».',
            'completed' => 'Сделка завершена. Можно оставить отзыв.',
            default => 'Откройте заказ в личном кабинете для деталей.',
        };
    }
}
