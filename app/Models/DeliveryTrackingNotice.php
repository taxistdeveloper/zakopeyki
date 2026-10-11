<?php

namespace App\Models;

use App\Core\Model;

/**
 * Идемпотентность уведомлений о статусе перевозки.
 * UNIQUE (delivery_order_id, notice_key) — повтор webhook/poll не шлёт второе письмо.
 */
class DeliveryTrackingNotice extends Model
{
    protected string $table = 'delivery_tracking_notices';
    private static bool $ensured = false;

    public function __construct()
    {
        parent::__construct();
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS delivery_tracking_notices (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                delivery_order_id INT UNSIGNED NOT NULL,
                notice_key VARCHAR(64) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tracking_notice (delivery_order_id, notice_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::$ensured = true;
    }

    public function claim(int $deliveryOrderId, string $noticeKey): bool
    {
        $noticeKey = trim($noticeKey);
        if ($deliveryOrderId <= 0 || $noticeKey === '') {
            return false;
        }
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO delivery_tracking_notices (delivery_order_id, notice_key) VALUES (?, ?)'
        );
        $stmt->execute([$deliveryOrderId, $noticeKey]);
        return $stmt->rowCount() > 0;
    }
}
