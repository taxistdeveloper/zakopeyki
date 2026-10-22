-- Mass Push: заказы, получатели и отписка.
-- Таблицы также создаёт приложение при первом обращении.

CREATE TABLE IF NOT EXISTS mass_push_campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(80) NOT NULL,
    body VARCHAR(320) NOT NULL,
    target_url VARCHAR(255) DEFAULT NULL,
    amount INT UNSIGNED NOT NULL,
    audience_count INT UNSIGNED NOT NULL DEFAULT 0,
    accepted_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL,
    reject_reason VARCHAR(255) DEFAULT NULL,
    idempotency_key VARCHAR(64) DEFAULT NULL,
    pg_order_id VARCHAR(64) DEFAULT NULL,
    pg_payment_id VARCHAR(64) DEFAULT NULL,
    redirect_url TEXT DEFAULT NULL,
    failure_reason VARCHAR(255) DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    started_at DATETIME DEFAULT NULL,
    finished_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mp_public (public_id),
    UNIQUE KEY uq_mp_pg (pg_order_id),
    UNIQUE KEY uq_mp_idem (idempotency_key),
    INDEX idx_mp_user (user_id),
    INDEX idx_mp_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mass_push_recipients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    accepted_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mp_recipient (campaign_id, user_id),
    INDEX idx_mp_recipient_status (campaign_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mass_push_unsubscribes (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
