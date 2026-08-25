-- Возвраты по модели Money Back Guarantee (адаптация eBay → Zakopeyki)
USE zakapeiku;

-- Колонки также добавляются из Order::ensureTable() при старте приложения.

CREATE TABLE IF NOT EXISTS order_return_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED DEFAULT NULL,
    actor_role VARCHAR(20) NOT NULL DEFAULT 'system',
    event_type VARCHAR(40) NOT NULL,
    payload TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order_return_events_order (order_id),
    INDEX idx_order_return_events_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
