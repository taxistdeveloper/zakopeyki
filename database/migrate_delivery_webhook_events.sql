-- Входящие webhook-события логистики (идемпотентность).
USE zakapeiku;

CREATE TABLE IF NOT EXISTS delivery_webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(32) NOT NULL DEFAULT 'cdek',
    event_type VARCHAR(64) DEFAULT NULL,
    event_uuid VARCHAR(64) DEFAULT NULL,
    cdek_order_uuid VARCHAR(64) DEFAULT NULL,
    status_code VARCHAR(64) DEFAULT NULL,
    event_hash CHAR(64) NOT NULL,
    delivery_order_id INT UNSIGNED DEFAULT NULL,
    payload_hash CHAR(64) DEFAULT NULL,
    process_result VARCHAR(32) NOT NULL DEFAULT 'received',
    error_message VARCHAR(255) DEFAULT NULL,
    processed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_del_webhook_hash (event_hash),
    INDEX idx_del_webhook_order (delivery_order_id),
    INDEX idx_del_webhook_cdek (cdek_order_uuid),
    INDEX idx_del_webhook_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
