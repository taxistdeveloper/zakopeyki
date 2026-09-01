-- Параметры отправления на объявлении (calculated shipping, ТЗ «Создание объявления»)
-- Колонки также добавляются из App\Models\ProductListingShipping при старте.

USE zakapeiku;

CREATE TABLE IF NOT EXISTS product_listing_shipping (
    product_id INT UNSIGNED NOT NULL PRIMARY KEY,
    fulfillment_mode VARCHAR(16) NOT NULL DEFAULT 'delivery' COMMENT 'delivery|pickup|both',
    param_mode VARCHAR(24) NOT NULL DEFAULT 'exact' COMMENT 'exact|standard_packaging|unknown',
    use_default_ship_from TINYINT(1) NOT NULL DEFAULT 1,
    ship_country VARCHAR(64) NOT NULL DEFAULT 'KZ',
    ship_region VARCHAR(120) DEFAULT NULL,
    ship_city VARCHAR(120) DEFAULT NULL,
    ship_street VARCHAR(200) DEFAULT NULL,
    ship_building VARCHAR(40) DEFAULT NULL,
    ship_apartment VARCHAR(40) DEFAULT NULL,
    ship_postal_code VARCHAR(20) DEFAULT NULL,
    ship_contact_name VARCHAR(160) DEFAULT NULL,
    ship_phone VARCHAR(32) DEFAULT NULL,
    packaging_id INT UNSIGNED DEFAULT NULL,
    recommended_packaging_id INT UNSIGNED DEFAULT NULL,
    packaging_name_snapshot VARCHAR(120) DEFAULT NULL,
    product_type_hint VARCHAR(32) DEFAULT NULL,
    item_weight DECIMAL(10,3) DEFAULT NULL,
    packaging_weight DECIMAL(10,3) DEFAULT NULL,
    gross_weight DECIMAL(10,3) DEFAULT NULL,
    item_length DECIMAL(10,2) DEFAULT NULL,
    item_width DECIMAL(10,2) DEFAULT NULL,
    item_height DECIMAL(10,2) DEFAULT NULL,
    package_length DECIMAL(10,2) DEFAULT NULL,
    package_width DECIMAL(10,2) DEFAULT NULL,
    package_height DECIMAL(10,2) DEFAULT NULL,
    is_irregular TINYINT(1) NOT NULL DEFAULT 0,
    irregular_reason VARCHAR(64) DEFAULT NULL,
    is_fragile TINYINT(1) NOT NULL DEFAULT 0,
    shipping_ready TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fulfillment (fulfillment_mode),
    INDEX idx_param_mode (param_mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
