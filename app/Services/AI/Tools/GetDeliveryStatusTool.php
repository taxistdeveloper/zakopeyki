<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Models\DeliveryOrder;
use App\Models\Order;
use App\Services\AI\Core\PermissionChecker;

final class GetDeliveryStatusTool extends AbstractTool
{
    public function __construct(
        private readonly Order $orders = new Order(),
        private readonly DeliveryOrder $deliveries = new DeliveryOrder(),
        private readonly PermissionChecker $permissions = new PermissionChecker(),
    ) {
    }

    public function name(): string
    {
        return 'get_delivery_status';
    }

    public function description(): string
    {
        return 'Статус доставки по заказу пользователя (CDEK / логистика).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer'],
            ],
        ];
    }

    public function requiredPermission(): string
    {
        return 'user';
    }

    public function execute(array $input, array $ctx): array
    {
        $userId = $ctx['user_id'] ?? null;
        $orderId = (int) ($input['order_id'] ?? ($ctx['context']['order_id'] ?? 0));

        if ($orderId <= 0 && $userId) {
            $active = $this->orders->findActiveForUser((int) $userId);
            $orderId = $active ? (int) $active['id'] : 0;
        }

        if ($orderId <= 0) {
            return $this->fail('Не найден активный заказ для отслеживания доставки.');
        }

        $order = $this->orders->find($orderId);
        if (!$order || !$this->permissions->ownsOrder($order, $userId)) {
            return $this->fail('Нет доступа к доставке этого заказа.');
        }

        $delivery = null;
        try {
            $delivery = $this->deliveries->findByP2pOrderId($orderId);
        } catch (\Throwable) {
            $delivery = null;
        }

        $data = [
            'order_id' => $orderId,
            'order_status' => (string) ($order['status'] ?? ''),
            'tracking_number' => $order['tracking_number'] ?? null,
            'carrier' => $order['carrier'] ?? null,
            'source' => 'mysql.orders+delivery_orders',
            'data_timestamp' => date('c'),
        ];

        if ($delivery) {
            $data['delivery_status'] = $delivery['status'] ?? null;
            $data['delivery_status_label'] = $this->label((string) ($delivery['status'] ?? ''));
            $data['payment_status'] = $delivery['payment_status'] ?? null;
            $data['explanation'] = $this->explain((string) ($delivery['status'] ?? ''), (string) ($order['status'] ?? ''));
        } else {
            $data['delivery_status'] = null;
            $data['explanation'] = $this->explainFromOrder((string) ($order['status'] ?? ''));
        }

        return $this->ok($data);
    }

    private function label(string $status): string
    {
        return match ($status) {
            DeliveryOrder::STATUS_DATA_COLLECTION => 'Сбор данных для отправки',
            DeliveryOrder::STATUS_IN_TRANSIT => 'В пути',
            DeliveryOrder::STATUS_DELIVERED => 'Доставлено',
            DeliveryOrder::STATUS_PAID => 'Доставка оплачена, создаётся отправление',
            DeliveryOrder::STATUS_ORDER_CREATED => 'Заявка в службе доставки создана',
            DeliveryOrder::STATUS_ACCEPTED => 'Принято службой доставки',
            DeliveryOrder::STATUS_SHIPMENT_RECEIVED => 'Посылка принята на склад',
            DeliveryOrder::STATUS_CANCELLED => 'Отменено',
            DeliveryOrder::STATUS_EXCEPTION => 'Исключение — нужна проверка',
            default => $status !== '' ? $status : 'Нет данных службы доставки',
        };
    }

    private function explain(string $deliveryStatus, string $orderStatus): string
    {
        if ($deliveryStatus === DeliveryOrder::STATUS_IN_TRANSIT) {
            return 'Посылка в пути. Ожидайте уведомление о прибытии.';
        }
        if ($deliveryStatus === DeliveryOrder::STATUS_DELIVERED || $orderStatus === 'delivered') {
            return 'Посылка доставлена. Подтвердите получение в разделе заказов.';
        }
        if ($deliveryStatus === '' || $deliveryStatus === DeliveryOrder::STATUS_DATA_COLLECTION) {
            return 'Доставка ещё оформляется. Проверьте, заполнены ли данные отправителя/получателя.';
        }
        return 'Текущий этап доставки: ' . $this->label($deliveryStatus) . '.';
    }

    private function explainFromOrder(string $orderStatus): string
    {
        return match ($orderStatus) {
            'escrowed' => 'Заказ оплачен. Продавец ещё не отметил отправку — отдельной накладной CDEK пока нет.',
            'shipped' => 'Продавец отметил отправку. Детали трекинга смотрите в карточке заказа.',
            'delivered' => 'Заказ отмечен как доставленный.',
            default => 'По этому заказу пока нет отдельной записи службы доставки. Статус заказа: ' . $orderStatus . '.',
        };
    }
}
