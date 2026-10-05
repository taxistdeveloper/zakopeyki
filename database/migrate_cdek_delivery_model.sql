-- CDEK delivery model hardening (Phase 2: data model only).
-- Safe additive migration for MySQL 5.7 / MariaDB.
-- Runtime mirror: DeliveryOrder::ensureUpgradeColumns() + ProductListingShipping::ensureTable().

USE zakapeiku;

DELIMITER $$

DROP PROCEDURE IF EXISTS zakapeiku_add_column_if_missing$$
CREATE PROCEDURE zakapeiku_add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
    ) THEN
        SET @ddl = CONCAT(
            'ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition
        );
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS zakapeiku_add_index_if_missing$$
CREATE PROCEDURE zakapeiku_add_index_if_missing(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND INDEX_NAME = p_index
    ) THEN
        SET @ddl = CONCAT(
            'ALTER TABLE `', p_table, '` ADD ', p_definition
        );
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- Listing Point A (seller ship-from bound to product)
CALL zakapeiku_add_column_if_missing('product_listing_shipping', 'origin_type', "VARCHAR(16) NOT NULL DEFAULT 'door' COMMENT 'door|pvz' AFTER ship_phone");
CALL zakapeiku_add_column_if_missing('product_listing_shipping', 'shipment_point', 'VARCHAR(64) DEFAULT NULL COMMENT ''CDEK shipment_point'' AFTER origin_type');
CALL zakapeiku_add_column_if_missing('product_listing_shipping', 'cdek_city_code', 'INT DEFAULT NULL AFTER shipment_point');
CALL zakapeiku_add_column_if_missing('product_listing_shipping', 'ship_latitude', 'DECIMAL(10,7) DEFAULT NULL AFTER cdek_city_code');
CALL zakapeiku_add_column_if_missing('product_listing_shipping', 'ship_longitude', 'DECIMAL(10,7) DEFAULT NULL AFTER ship_latitude');
CALL zakapeiku_add_column_if_missing('product_listing_shipping', 'cdek_ready', 'TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Point A + package ready for CDEK'' AFTER shipping_ready');
CALL zakapeiku_add_column_if_missing('product_listing_shipping', 'declared_value', 'INT UNSIGNED DEFAULT NULL COMMENT ''declared goods value INT KZT'' AFTER cdek_ready');
CALL zakapeiku_add_column_if_missing('product_listing_shipping', 'declared_currency', "CHAR(3) NOT NULL DEFAULT 'KZT' AFTER declared_value");

-- Sender Point A on delivery order
CALL zakapeiku_add_column_if_missing('delivery_senders', 'company', 'VARCHAR(255) DEFAULT NULL AFTER name');
CALL zakapeiku_add_column_if_missing('delivery_senders', 'origin_type', "VARCHAR(16) NOT NULL DEFAULT 'door' COMMENT 'door|pvz' AFTER postal_code");
CALL zakapeiku_add_column_if_missing('delivery_senders', 'shipment_point', 'VARCHAR(64) DEFAULT NULL AFTER origin_type');
CALL zakapeiku_add_column_if_missing('delivery_senders', 'cdek_city_code', 'INT DEFAULT NULL AFTER shipment_point');
CALL zakapeiku_add_column_if_missing('delivery_senders', 'latitude', 'DECIMAL(10,7) DEFAULT NULL AFTER cdek_city_code');
CALL zakapeiku_add_column_if_missing('delivery_senders', 'longitude', 'DECIMAL(10,7) DEFAULT NULL AFTER latitude');

-- Recipient Point B
CALL zakapeiku_add_column_if_missing('delivery_recipients', 'cdek_city_code', 'INT DEFAULT NULL AFTER postal_code');
CALL zakapeiku_add_column_if_missing('delivery_recipients', 'delivery_point', 'VARCHAR(64) DEFAULT NULL COMMENT ''CDEK delivery_point (usually = pvz_code)'' AFTER pvz_name');
CALL zakapeiku_add_column_if_missing('delivery_recipients', 'latitude', 'DECIMAL(10,7) DEFAULT NULL AFTER delivery_point');
CALL zakapeiku_add_column_if_missing('delivery_recipients', 'longitude', 'DECIMAL(10,7) DEFAULT NULL AFTER latitude');

-- Shipment / package declared cost
CALL zakapeiku_add_column_if_missing('delivery_shipments', 'description', 'VARCHAR(255) DEFAULT NULL AFTER product_title');
CALL zakapeiku_add_column_if_missing('delivery_shipments', 'declared_cost', 'INT UNSIGNED DEFAULT NULL COMMENT ''item cost for CDEK ItemRequestDto.cost'' AFTER description');
CALL zakapeiku_add_column_if_missing('delivery_shipments', 'declared_currency', "CHAR(3) NOT NULL DEFAULT 'KZT' AFTER declared_cost");

-- Quote: pure CDEK tariff snapshot fields
CALL zakapeiku_add_column_if_missing('delivery_quotes', 'tariff_code', 'INT DEFAULT NULL AFTER service_code');
CALL zakapeiku_add_column_if_missing('delivery_quotes', 'cdek_delivery_mode', 'INT DEFAULT NULL AFTER tariff_code');
CALL zakapeiku_add_column_if_missing('delivery_quotes', 'cdek_delivery_sum', 'INT UNSIGNED DEFAULT NULL COMMENT ''pure CDEK delivery_sum INT'' AFTER base_amount');
CALL zakapeiku_add_column_if_missing('delivery_quotes', 'services_json', 'TEXT DEFAULT NULL AFTER snapshot_json');

-- Delivery order: CDEK identifiers, async API status, idempotency, payment link
CALL zakapeiku_add_column_if_missing('delivery_orders', 'cdek_uuid', 'VARCHAR(64) DEFAULT NULL COMMENT ''CDEK entity.uuid (mirrors logistics_order_id when set)'' AFTER logistics_order_id');
CALL zakapeiku_add_column_if_missing('delivery_orders', 'cdek_number', 'VARCHAR(64) DEFAULT NULL COMMENT ''CDEK cdek_number / tracking'' AFTER cdek_uuid');
CALL zakapeiku_add_column_if_missing('delivery_orders', 'cdek_request_uuid', 'VARCHAR(64) DEFAULT NULL AFTER cdek_number');
CALL zakapeiku_add_column_if_missing('delivery_orders', 'cdek_api_status', "VARCHAR(32) NOT NULL DEFAULT 'none' COMMENT 'none|pending|accepted|created|failed' AFTER cdek_request_uuid");
CALL zakapeiku_add_column_if_missing('delivery_orders', 'cdek_status_code', 'VARCHAR(64) DEFAULT NULL COMMENT ''last CDEK OrderStatusDto.code'' AFTER cdek_api_status');
CALL zakapeiku_add_column_if_missing('delivery_orders', 'create_idempotency_key', 'VARCHAR(64) DEFAULT NULL AFTER cdek_status_code');
CALL zakapeiku_add_column_if_missing('delivery_orders', 'payment_id', 'INT UNSIGNED DEFAULT NULL COMMENT ''delivery_payments.id'' AFTER create_idempotency_key');
CALL zakapeiku_add_column_if_missing('delivery_orders', 'last_synced_at', 'DATETIME DEFAULT NULL AFTER payment_id');
CALL zakapeiku_add_column_if_missing('delivery_orders', 'last_error_code', 'VARCHAR(64) DEFAULT NULL AFTER last_synced_at');
CALL zakapeiku_add_column_if_missing('delivery_orders', 'last_error_message', 'VARCHAR(255) DEFAULT NULL COMMENT ''no PII'' AFTER last_error_code');

CALL zakapeiku_add_index_if_missing(
    'delivery_orders',
    'uq_delivery_create_idempotency',
    'UNIQUE KEY uq_delivery_create_idempotency (create_idempotency_key)'
);
CALL zakapeiku_add_index_if_missing(
    'delivery_orders',
    'uq_delivery_cdek_uuid',
    'UNIQUE KEY uq_delivery_cdek_uuid (cdek_uuid)'
);
CALL zakapeiku_add_index_if_missing(
    'delivery_orders',
    'idx_delivery_cdek_number',
    'INDEX idx_delivery_cdek_number (cdek_number)'
);
CALL zakapeiku_add_index_if_missing(
    'delivery_orders',
    'idx_delivery_payment_id',
    'INDEX idx_delivery_payment_id (payment_id)'
);
CALL zakapeiku_add_index_if_missing(
    'delivery_orders',
    'idx_delivery_cdek_api_status',
    'INDEX idx_delivery_cdek_api_status (cdek_api_status)'
);

DROP PROCEDURE IF EXISTS zakapeiku_add_column_if_missing;
DROP PROCEDURE IF EXISTS zakapeiku_add_index_if_missing;
