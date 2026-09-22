-- Локальный справочник ПВЗ / постаматов CDEK (OfficeDto).
-- Runtime ensure также в App\Models\CdekDeliveryPoint.

USE zakapeiku;

CREATE TABLE IF NOT EXISTS cdek_delivery_points (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL,
    uuid VARCHAR(64) DEFAULT NULL,
    type VARCHAR(32) NOT NULL DEFAULT 'PVZ',
    name VARCHAR(255) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    address_full VARCHAR(255) DEFAULT NULL,
    city VARCHAR(120) DEFAULT NULL,
    city_code INT UNSIGNED DEFAULT NULL,
    region VARCHAR(120) DEFAULT NULL,
    region_code INT DEFAULT NULL,
    country_code CHAR(2) NOT NULL DEFAULT 'KZ',
    postal_code VARCHAR(32) DEFAULT NULL,
    longitude DECIMAL(12,8) DEFAULT NULL,
    latitude DECIMAL(12,8) DEFAULT NULL,
    work_time VARCHAR(255) DEFAULT NULL,
    is_handout TINYINT(1) NOT NULL DEFAULT 1,
    is_reception TINYINT(1) NOT NULL DEFAULT 0,
    take_only TINYINT(1) NOT NULL DEFAULT 0,
    have_cashless TINYINT(1) NOT NULL DEFAULT 0,
    have_cash TINYINT(1) NOT NULL DEFAULT 0,
    allowed_cod TINYINT(1) NOT NULL DEFAULT 0,
    weight_min DECIMAL(10,3) DEFAULT NULL,
    weight_max DECIMAL(10,3) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    raw_hash CHAR(64) DEFAULT NULL,
    synced_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cdek_point_code (code),
    INDEX idx_cdek_point_city_code (city_code),
    INDEX idx_cdek_point_city (city),
    INDEX idx_cdek_point_country_active (country_code, is_active),
    INDEX idx_cdek_point_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
