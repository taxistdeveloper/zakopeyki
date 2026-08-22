-- Personal vs Business accounts, annual turnover limit, business package
-- MySQL не поддерживает ADD COLUMN IF NOT EXISTS (это синтаксис MariaDB).
-- Если колонки уже есть, этот ALTER выдаст Duplicate column name — тогда пропустите его.

ALTER TABLE `users`
    ADD COLUMN `account_type` ENUM('personal','business') NOT NULL DEFAULT 'personal' AFTER `aml_checked_at`,
    ADD COLUMN `business_entity_type` ENUM('ip','too') DEFAULT NULL AFTER `account_type`,
    ADD COLUMN `business_name` VARCHAR(255) DEFAULT NULL AFTER `business_entity_type`,
    ADD COLUMN `bin` VARCHAR(12) DEFAULT NULL AFTER `business_name`,
    ADD COLUMN `business_status` ENUM('none','pending','verified','rejected') NOT NULL DEFAULT 'none' AFTER `bin`,
    ADD COLUMN `business_verified_at` DATETIME DEFAULT NULL AFTER `business_status`,
    ADD COLUMN `business_rejected_reason` VARCHAR(500) DEFAULT NULL AFTER `business_verified_at`,
    ADD COLUMN `personal_limit_year` SMALLINT UNSIGNED DEFAULT NULL AFTER `business_rejected_reason`,
    ADD COLUMN `personal_turnover_kzt` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `personal_limit_year`,
    ADD COLUMN `limit_warning_sent_at` DATETIME DEFAULT NULL AFTER `personal_turnover_kzt`,
    ADD COLUMN `limit_blocked_at` DATETIME DEFAULT NULL AFTER `limit_warning_sent_at`;

CREATE TABLE IF NOT EXISTS `business_upgrade_requests` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `entity_type` ENUM('ip','too') NOT NULL,
    `business_name` VARCHAR(255) NOT NULL,
    `bin` VARCHAR(12) NOT NULL,
    `phone` VARCHAR(32) DEFAULT NULL,
    `address` VARCHAR(500) DEFAULT NULL,
    `doc_files` TEXT DEFAULT NULL COMMENT 'JSON list of uploaded filenames',
    `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `admin_note` VARCHAR(500) DEFAULT NULL,
    `reviewed_by` INT UNSIGNED DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_bur_user` (`user_id`),
    INDEX `idx_bur_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `business_packages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(64) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `price_kzt` INT UNSIGNED NOT NULL,
    `duration_days` INT UNSIGNED NOT NULL DEFAULT 30,
    `benefits_json` TEXT DEFAULT NULL,
    `max_photos` TINYINT UNSIGNED NOT NULL DEFAULT 10,
    `free_service_listing` TINYINT(1) NOT NULL DEFAULT 1,
    `priority_boost` TINYINT(1) NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_bp_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `business_subscriptions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `package_id` INT UNSIGNED NOT NULL,
    `status` ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
    `starts_at` DATETIME NOT NULL,
    `ends_at` DATETIME NOT NULL,
    `price_paid_kzt` INT UNSIGNED NOT NULL DEFAULT 0,
    `payment_meta` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_bs_user` (`user_id`),
    INDEX `idx_bs_status_ends` (`status`, `ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `personal_turnover_ledger` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `order_id` INT UNSIGNED DEFAULT NULL,
    `amount_kzt` INT NOT NULL,
    `year` SMALLINT UNSIGNED NOT NULL,
    `meta` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_ptl_order` (`order_id`),
    INDEX `idx_ptl_user_year` (`user_id`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key`, `value`) VALUES
    ('mrp_kzt', '3932'),
    ('personal_limit_mrp', '360'),
    ('personal_warning_kzt', '1100000')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

INSERT INTO `business_packages` (`slug`, `name`, `description`, `price_kzt`, `duration_days`, `kind`, `billing`, `benefits_json`, `max_photos`, `free_service_listing`, `priority_boost`, `is_active`, `sort_order`)
VALUES
('business-month', 'Бизнес-пакет · 1 месяц', 'Загрузите каталог один раз — остальное Zakopeyki сделает автоматически.', 29900, 30, 'plan', 'period', '[]', 10, 1, 1, 1, 10),
('business-3m', 'Бизнес-пакет · 3 месяца', 'Выгоднее месяца: профессиональный тариф на квартал.', 79900, 90, 'plan', 'period', '[]', 10, 1, 1, 1, 20),
('business-6m', 'Бизнес-пакет · 6 месяцев', 'Полугодовая подписка для стабильной работы каталога.', 149900, 180, 'plan', 'period', '[]', 10, 1, 1, 1, 30),
('business-12m', 'Бизнес-пакет · 12 месяцев', 'Годовая подписка — максимальная экономия.', 279900, 365, 'plan', 'period', '[]', 10, 1, 1, 1, 40)
ON DUPLICATE KEY UPDATE
    `price_kzt` = VALUES(`price_kzt`),
    `duration_days` = VALUES(`duration_days`),
    `is_active` = VALUES(`is_active`);

UPDATE `business_packages` SET `is_active` = 0 WHERE `slug` = 'business-pro';
