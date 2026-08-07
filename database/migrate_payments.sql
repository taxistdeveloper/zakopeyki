-- FreedomPay payments ledger (also auto-created by App\Models\Payment)
CREATE TABLE IF NOT EXISTS payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pg_order_id VARCHAR(64) NOT NULL,
    pg_payment_id VARCHAR(64) DEFAULT NULL,
    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    buyer_id INT UNSIGNED NOT NULL,
    amount INT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    delivery_method VARCHAR(50) NOT NULL DEFAULT 'kazpost',
    payment_method VARCHAR(50) NOT NULL DEFAULT 'card',
    meta TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_pg_order (pg_order_id),
    INDEX idx_order (order_id),
    INDEX idx_buyer (buyer_id),
    INDEX idx_status (status),
    INDEX idx_pg_payment (pg_payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
