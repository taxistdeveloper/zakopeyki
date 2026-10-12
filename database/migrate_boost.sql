-- Boost: разовая услуга поднятия. Дата создания объявления не меняется.
-- Таблицу и колонку sort_at также создаёт приложение при первом обращении
-- (BoostOrder::ensureTable, Product::ensureSchema). Повторный ALTER упадёт,
-- если колонка уже есть — тогда этот фрагмент пропускают.

CREATE TABLE IF NOT EXISTS boost_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    amount INT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending_payment',
    open_key VARCHAR(32) DEFAULT NULL,
    idempotency_key VARCHAR(64) DEFAULT NULL,
    pg_order_id VARCHAR(64) NOT NULL,
    pg_payment_id VARCHAR(64) DEFAULT NULL,
    redirect_url TEXT DEFAULT NULL,
    failure_reason VARCHAR(255) DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    applied_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_boost_public (public_id),
    UNIQUE KEY uq_boost_pg (pg_order_id),
    UNIQUE KEY uq_boost_open (open_key),
    UNIQUE KEY uq_boost_idem (idempotency_key),
    INDEX idx_boost_product (product_id),
    INDEX idx_boost_user (user_id),
    INDEX idx_boost_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ALTER TABLE products ADD COLUMN sort_at DATETIME NULL DEFAULT NULL AFTER created_at;
-- UPDATE products SET sort_at = created_at, updated_at = updated_at WHERE sort_at IS NULL;
-- ALTER TABLE products ADD INDEX idx_products_sort (sort_at, id);
