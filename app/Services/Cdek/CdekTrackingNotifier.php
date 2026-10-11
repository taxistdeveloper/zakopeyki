<?php

namespace App\Services\Cdek;

use App\Models\DeliveryTrackingNotice;
use App\Models\Notification;

/**
 * Уведомления только о значимых группах каталога.
 * Технические ошибки CDEK сюда не попадают.
 * В тексте нет телефона и адреса второй стороны.
 */
class CdekTrackingNotifier
{
    /** @var DeliveryTrackingNotice|object */
    private object $notices;
    /** @var Notification|object|null */
    private ?object $notifications;

    public function __construct(?object $notices = null, ?object $notifications = null)
    {
        $this->notices = $notices ?? new DeliveryTrackingNotice();
        $this->notifications = $notifications;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function notify(array $row, string $rawCode): bool
    {
        $kind = CdekStatusCatalog::noticeKind($rawCode);
        if ($kind === null) {
            return false;
        }
        $deliveryId = (int) ($row['id'] ?? 0);
        if ($deliveryId <= 0) {
            return false;
        }

        $buyerId = (int) ($row['buyer_user_id'] ?? 0);
        $sellerId = (int) ($row['seller_user_id'] ?? 0);
        if ($buyerId <= 0 && $sellerId <= 0) {
            return false;
        }

        $sent = false;
        $number = trim((string) ($row['order_number'] ?? ''));
        $prefix = $number !== '' ? ('Доставка ' . $number . ': ') : '';
        $link = '/delivery/' . $deliveryId;

        if ($buyerId > 0 && $this->notices->claim($deliveryId, $kind . ':' . $buyerId)) {
            $this->notifications()->createFor($buyerId, $prefix . CdekStatusCatalog::text($rawCode, 'buyer'), $link);
            $sent = true;
        }
        if ($sellerId > 0 && $sellerId !== $buyerId && $this->notices->claim($deliveryId, $kind . ':' . $sellerId)) {
            $this->notifications()->createFor($sellerId, $prefix . CdekStatusCatalog::text($rawCode, 'seller'), $link);
            $sent = true;
        }
        return $sent;
    }

    private function notifications(): object
    {
        return $this->notifications ?? new Notification();
    }
}
