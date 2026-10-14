-- VIP «Всё включено»: один пакет на 7 дней.
-- Таблицы также создаёт приложение при первом обращении.

CREATE TABLE IF NOT EXISTS vip_orders (
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
    UNIQUE KEY uq_vip_public (public_id),
    UNIQUE KEY uq_vip_pg (pg_order_id),
    UNIQUE KEY uq_vip_open (open_key),
    UNIQUE KEY uq_vip_idem (idempotency_key),
    INDEX idx_vip_product (product_id),
    INDEX idx_vip_user (user_id),
    INDEX idx_vip_status (status),
    INDEX idx_vip_window (status, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vip_lifts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vip_order_id INT UNSIGNED NOT NULL,
    slot TINYINT UNSIGNED NOT NULL,
    scheduled_at DATETIME NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    executed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vip_lift_slot (vip_order_id, slot),
    INDEX idx_vip_lift_due (status, scheduled_at),
    INDEX idx_vip_lift_order (vip_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Связь эффекта с пакетом. NULL остаётся у самостоятельных покупок.
-- Повторный запуск безопасен только если колонки ещё нет: приложение добавляет их само.
