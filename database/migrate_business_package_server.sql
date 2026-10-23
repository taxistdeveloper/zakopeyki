-- VAS-14.3: серверная часть бизнес-пакета (история платежей, импорт, API-ключи).
-- Обратимо: только CREATE TABLE IF NOT EXISTS / INSERT settings.
-- Не запускать на production без отдельного согласования деплоя.

CREATE TABLE IF NOT EXISTS `business_package_payments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `package_id` INT UNSIGNED NOT NULL,
    `subscription_id` INT UNSIGNED DEFAULT NULL,
    `amount_kzt` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
    `channel` VARCHAR(32) NOT NULL DEFAULT 'wallet',
    `idempotency_key` VARCHAR(64) NOT NULL,
    `meta` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_bpp_idempotency` (`idempotency_key`),
    INDEX `idx_bpp_user` (`user_id`),
    INDEX `idx_bpp_status` (`status`),
    INDEX `idx_bpp_subscription` (`subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `catalog_import_jobs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `source` VARCHAR(16) NOT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'queued',
    `filename` VARCHAR(255) DEFAULT NULL,
    `total_rows` INT UNSIGNED NOT NULL DEFAULT 0,
    `ok_rows` INT UNSIGNED NOT NULL DEFAULT 0,
    `error_rows` INT UNSIGNED NOT NULL DEFAULT 0,
    `report_json` MEDIUMTEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_cij_user` (`user_id`),
    INDEX `idx_cij_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `catalog_import_keys` (
    `user_id` INT UNSIGNED NOT NULL,
    `external_key` VARCHAR(128) NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `external_key`),
    INDEX `idx_cik_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seller_api_keys` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(120) NOT NULL DEFAULT 'default',
    `key_prefix` VARCHAR(16) NOT NULL,
    `key_hash` CHAR(64) NOT NULL,
    `last_used_at` DATETIME DEFAULT NULL,
    `revoked_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_sak_hash` (`key_hash`),
    INDEX `idx_sak_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Флаги: по умолчанию включены (совместимость с уже работавшей продажей).
-- Явное выключение — через админ POST /admin/business/package-sales|package-features.
INSERT INTO `settings` (`key`, `value`) VALUES
    ('business_package_sales_enabled', '1'),
    ('business_package_features_enabled', '1')
ON DUPLICATE KEY UPDATE `value` = `value`;
