-- Escrow orders upgrade
USE zakapeiku;

CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    buyer_id INT UNSIGNED NOT NULL,
    seller_id INT UNSIGNED NOT NULL,
    amount INT UNSIGNED NOT NULL,
    payment_method VARCHAR(50) NOT NULL DEFAULT 'card',
    delivery_method VARCHAR(50) NOT NULL DEFAULT 'kazpost',
    status VARCHAR(32) NOT NULL DEFAULT 'escrowed',
    escrow_hold VARCHAR(32) NOT NULL DEFAULT 'holding',
    tracking_number VARCHAR(120) DEFAULT NULL,
    carrier VARCHAR(80) DEFAULT NULL,
    shipped_at DATETIME DEFAULT NULL,
    delivered_at DATETIME DEFAULT NULL,
    inspect_until DATETIME DEFAULT NULL,
    confirmed_at DATETIME DEFAULT NULL,
    released_at DATETIME DEFAULT NULL,
    dispute_reason TEXT DEFAULT NULL,
    dispute_evidence TEXT DEFAULT NULL,
    disputed_at DATETIME DEFAULT NULL,
    arbiter_id INT UNSIGNED DEFAULT NULL,
    arbiter_decision VARCHAR(40) DEFAULT NULL,
    arbiter_at DATETIME DEFAULT NULL,
    return_tracking VARCHAR(120) DEFAULT NULL,
    return_shipped_at DATETIME DEFAULT NULL,
    return_delivered_at DATETIME DEFAULT NULL,
    refunded_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_buyer (buyer_id),
    INDEX idx_seller (seller_id),
    INDEX idx_product (product_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Если таблица уже была — колонки добавятся автоматически из Order::ensureTable()
