-- Top Fixed XL: приоритет на главной и в верхней части выдачи на 14 дней.
-- Таблицу также создаёт приложение при первом обращении (TopFixedXlOrder::ensureTable).

CREATE TABLE IF NOT EXISTS top_fixed_xl_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    amount INT UNSIGNED NOT NULL,
    category VARCHAR(255) DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending_payment',
    open_key VARCHAR(32) DEFAULT NULL,
    idempotency_key VARCHAR(64) DEFAULT NULL,
    pg_order_id VARCHAR(64) NOT NULL,
    pg_payment_id VARCHAR(64) DEFAULT NULL,
    redirect_url TEXT DEFAULT NULL,
    failure_reason VARCHAR(255) DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    applied_at DATETIME DEFAULT NULL,
    starts_at DATETIME DEFAULT NULL,
    ends_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_top_fixed_xl_public (public_id),
    UNIQUE KEY uq_top_fixed_xl_pg (pg_order_id),
    UNIQUE KEY uq_top_fixed_xl_open (open_key),
    UNIQUE KEY uq_top_fixed_xl_idem (idempotency_key),
    INDEX idx_top_fixed_xl_product (product_id),
    INDEX idx_top_fixed_xl_user (user_id),
    INDEX idx_top_fixed_xl_status (status),
    INDEX idx_top_fixed_xl_window (status, ends_at),
    INDEX idx_top_fixed_xl_pin (product_id, status, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
