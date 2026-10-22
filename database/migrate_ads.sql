-- Баннерная реклама: Hero, баннер категории, боковой баннер, эксклюзив категории.
-- Таблицы также создаёт приложение при первом обращении.

CREATE TABLE IF NOT EXISTS ad_campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    format VARCHAR(16) NOT NULL,
    category_key VARCHAR(255) DEFAULT NULL,
    title VARCHAR(120) NOT NULL,
    target_url VARCHAR(500) NOT NULL,
    image_file VARCHAR(255) NOT NULL,
    units INT UNSIGNED NOT NULL,
    amount INT UNSIGNED NOT NULL,
    impressions_paid INT UNSIGNED NOT NULL DEFAULT 0,
    impressions_used INT UNSIGNED NOT NULL DEFAULT 0,
    clicks INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL,
    reject_reason VARCHAR(255) DEFAULT NULL,
    open_key VARCHAR(64) DEFAULT NULL,
    idempotency_key VARCHAR(64) DEFAULT NULL,
    pg_order_id VARCHAR(64) DEFAULT NULL,
    pg_payment_id VARCHAR(64) DEFAULT NULL,
    redirect_url TEXT DEFAULT NULL,
    failure_reason VARCHAR(255) DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    starts_at DATETIME DEFAULT NULL,
    ends_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ad_public (public_id),
    UNIQUE KEY uq_ad_pg (pg_order_id),
    UNIQUE KEY uq_ad_open (open_key),
    UNIQUE KEY uq_ad_idem (idempotency_key),
    INDEX idx_ad_user (user_id),
    INDEX idx_ad_serve (format, status, category_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ad_impressions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    token CHAR(32) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'issued',
    counted_at DATETIME DEFAULT NULL,
    clicked_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ad_impr_token (token),
    INDEX idx_ad_impr_campaign (campaign_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
