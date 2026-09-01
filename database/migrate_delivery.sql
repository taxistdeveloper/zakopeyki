-- Модуль доставки Zakopeyki.kz (P2P: услуга доставки отдельно от сделки)
-- См. ТЗ v1.0 от 01.09.2026. Таблицы также создаются из App\Models\DeliveryOrder при старте.

USE zakapeiku;

CREATE TABLE IF NOT EXISTS logistics_providers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL,
    name VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    api_config TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_logistics_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(32) NOT NULL,
    order_id INT UNSIGNED NOT NULL COMMENT 'P2P-сделка (orders.id)',
    buyer_user_id INT UNSIGNED NOT NULL,
    seller_user_id INT UNSIGNED NOT NULL,
    logistics_provider_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED DEFAULT NULL,
    sender_id INT UNSIGNED DEFAULT NULL,
    recipient_id INT UNSIGNED DEFAULT NULL,
    origin_address_id INT UNSIGNED DEFAULT NULL,
    destination_address_id INT UNSIGNED DEFAULT NULL,
    quote_id INT UNSIGNED DEFAULT NULL,
    logistics_order_id VARCHAR(64) DEFAULT NULL,
    status VARCHAR(48) NOT NULL DEFAULT 'DELIVERY_DATA_COLLECTION',
    currency CHAR(3) NOT NULL DEFAULT 'KZT',
    total_amount INT UNSIGNED DEFAULT NULL,
    paid_amount INT UNSIGNED NOT NULL DEFAULT 0,
    payment_status VARCHAR(32) NOT NULL DEFAULT 'unpaid',
    data_completeness_status VARCHAR(32) NOT NULL DEFAULT 'pending',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    paid_at DATETIME DEFAULT NULL,
    accepted_at DATETIME DEFAULT NULL,
    delivered_at DATETIME DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    cancel_reason VARCHAR(64) DEFAULT NULL,
    UNIQUE KEY uq_order_number (order_number),
    UNIQUE KEY uq_p2p_order (order_id),
    INDEX idx_buyer (buyer_user_id),
    INDEX idx_seller (seller_user_id),
    INDEX idx_status (status),
    INDEX idx_logistics (logistics_provider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_senders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_order_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    email VARCHAR(120) DEFAULT NULL,
    country VARCHAR(64) NOT NULL DEFAULT 'KZ',
    region VARCHAR(120) DEFAULT NULL,
    city VARCHAR(120) NOT NULL,
    street VARCHAR(200) DEFAULT NULL,
    building VARCHAR(40) DEFAULT NULL,
    apartment VARCHAR(40) DEFAULT NULL,
    postal_code VARCHAR(20) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_delivery_sender (delivery_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_recipients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_order_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    email VARCHAR(120) DEFAULT NULL,
    delivery_mode VARCHAR(24) NOT NULL DEFAULT 'courier' COMMENT 'courier|pvz|pickup_point',
    country VARCHAR(64) NOT NULL DEFAULT 'KZ',
    region VARCHAR(120) DEFAULT NULL,
    city VARCHAR(120) NOT NULL,
    street VARCHAR(200) DEFAULT NULL,
    building VARCHAR(40) DEFAULT NULL,
    apartment VARCHAR(40) DEFAULT NULL,
    postal_code VARCHAR(20) DEFAULT NULL,
    pvz_code VARCHAR(64) DEFAULT NULL,
    pvz_name VARCHAR(200) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_delivery_recipient (delivery_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_shipments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_order_id INT UNSIGNED NOT NULL,
    product_title VARCHAR(255) DEFAULT NULL,
    product_qty INT UNSIGNED NOT NULL DEFAULT 1,
    package_count INT UNSIGNED NOT NULL DEFAULT 1,
    weight_value DECIMAL(10,3) DEFAULT NULL,
    weight_unit VARCHAR(8) NOT NULL DEFAULT 'kg',
    length_value DECIMAL(10,2) DEFAULT NULL,
    width_value DECIMAL(10,2) DEFAULT NULL,
    height_value DECIMAL(10,2) DEFAULT NULL,
    dimension_unit VARCHAR(8) NOT NULL DEFAULT 'cm',
    weight_source VARCHAR(16) NOT NULL DEFAULT 'seller',
    dimension_source VARCHAR(16) NOT NULL DEFAULT 'seller',
    measurement_status VARCHAR(16) NOT NULL DEFAULT 'preliminary',
    packaging_id INT UNSIGNED DEFAULT NULL,
    packaging_name_snapshot VARCHAR(120) DEFAULT NULL,
    dimensions_unknown TINYINT(1) NOT NULL DEFAULT 0,
    is_fragile TINYINT(1) NOT NULL DEFAULT 0,
    special_handling TEXT DEFAULT NULL,
    actual_weight DECIMAL(10,3) DEFAULT NULL,
    actual_length DECIMAL(10,2) DEFAULT NULL,
    actual_width DECIMAL(10,2) DEFAULT NULL,
    actual_height DECIMAL(10,2) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_delivery_shipment (delivery_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_packagings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    logistics_provider_id INT UNSIGNED NOT NULL,
    code VARCHAR(32) NOT NULL,
    name VARCHAR(120) NOT NULL,
    max_weight_kg DECIMAL(10,2) DEFAULT NULL,
    length_cm DECIMAL(10,2) DEFAULT NULL,
    width_cm DECIMAL(10,2) DEFAULT NULL,
    height_cm DECIMAL(10,2) DEFAULT NULL,
    price_amount INT UNSIGNED NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'KZT',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_packaging (logistics_provider_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_services (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    logistics_provider_id INT UNSIGNED NOT NULL,
    service_code VARCHAR(32) NOT NULL,
    service_name VARCHAR(120) NOT NULL,
    delivery_mode VARCHAR(24) NOT NULL DEFAULT 'courier',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    meta TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_service (logistics_provider_id, service_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_tariffs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    logistics_provider_id INT UNSIGNED NOT NULL,
    tariff_code VARCHAR(32) NOT NULL,
    version VARCHAR(16) NOT NULL DEFAULT '1',
    rules_json TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tariff (logistics_provider_id, tariff_code, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_quotes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_order_id INT UNSIGNED NOT NULL,
    logistics_provider_id INT UNSIGNED NOT NULL,
    request_id VARCHAR(64) NOT NULL,
    tariff_id INT UNSIGNED DEFAULT NULL,
    tariff_version VARCHAR(16) DEFAULT NULL,
    service_code VARCHAR(32) NOT NULL,
    service_name VARCHAR(120) NOT NULL,
    base_amount INT UNSIGNED NOT NULL DEFAULT 0,
    packaging_amount INT UNSIGNED NOT NULL DEFAULT 0,
    extra_services_amount INT UNSIGNED NOT NULL DEFAULT 0,
    discount_amount INT UNSIGNED NOT NULL DEFAULT 0,
    total_amount INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KZT',
    billable_weight DECIMAL(10,3) DEFAULT NULL,
    billable_weight_method VARCHAR(64) DEFAULT NULL,
    eta_days_min INT UNSIGNED DEFAULT NULL,
    eta_days_max INT UNSIGNED DEFAULT NULL,
    valid_until DATETIME NOT NULL,
    request_payload_hash VARCHAR(64) DEFAULT NULL,
    response_hash VARCHAR(64) DEFAULT NULL,
    is_selected TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_delivery_quote_order (delivery_order_id),
    INDEX idx_delivery_quote_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_order_id INT UNSIGNED NOT NULL,
    buyer_user_id INT UNSIGNED NOT NULL,
    pg_order_id VARCHAR(64) NOT NULL,
    pg_payment_id VARCHAR(64) DEFAULT NULL,
    acquirer_provider VARCHAR(32) NOT NULL DEFAULT 'freedompay',
    external_payment_id VARCHAR(64) DEFAULT NULL,
    amount INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KZT',
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    purpose VARCHAR(32) NOT NULL DEFAULT 'DELIVERY_SERVICE',
    idempotency_key VARCHAR(64) NOT NULL,
    failure_reason VARCHAR(255) DEFAULT NULL,
    webhook_payload_hash VARCHAR(64) DEFAULT NULL,
    meta TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_del_pg_order (pg_order_id),
    UNIQUE KEY uq_del_idempotency (idempotency_key),
    INDEX idx_del_payment_order (delivery_order_id),
    INDEX idx_del_payment_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_order_id INT UNSIGNED NOT NULL,
    document_type VARCHAR(32) NOT NULL DEFAULT 'avr_data',
    payload_json LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_del_doc (delivery_order_id, document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_tracking (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_order_id INT UNSIGNED NOT NULL,
    tracking_number VARCHAR(120) DEFAULT NULL,
    carrier_status VARCHAR(64) DEFAULT NULL,
    carrier_message VARCHAR(255) DEFAULT NULL,
    location VARCHAR(200) DEFAULT NULL,
    event_at DATETIME NOT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'logistics',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_del_track_order (delivery_order_id),
    INDEX idx_del_track_event (event_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_api_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_order_id INT UNSIGNED DEFAULT NULL,
    logistics_provider_id INT UNSIGNED DEFAULT NULL,
    direction VARCHAR(8) NOT NULL DEFAULT 'out',
    endpoint VARCHAR(200) NOT NULL,
    request_hash VARCHAR(64) DEFAULT NULL,
    response_code INT DEFAULT NULL,
    response_hash VARCHAR(64) DEFAULT NULL,
    error_message VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_del_api_order (delivery_order_id),
    INDEX idx_del_api_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_order_id INT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED DEFAULT NULL,
    actor_role VARCHAR(20) NOT NULL DEFAULT 'system',
    event_type VARCHAR(48) NOT NULL,
    from_status VARCHAR(48) DEFAULT NULL,
    to_status VARCHAR(48) DEFAULT NULL,
    payload TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_del_events_order (delivery_order_id),
    INDEX idx_del_events_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
