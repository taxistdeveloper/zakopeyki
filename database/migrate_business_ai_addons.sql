-- VAS-15.3: доп. AI-пакеты — журнал операций и флаг продажи.
-- Обратимо: CREATE IF NOT EXISTS / INSERT settings без удаления данных.
-- Не запускать на production без отдельного согласования деплоя.

CREATE TABLE IF NOT EXISTS `business_ai_operations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `feature` VARCHAR(40) NOT NULL,
    `operation_key` VARCHAR(64) NOT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'reserved',
    `period_key` VARCHAR(16) NOT NULL,
    `error_code` VARCHAR(64) DEFAULT NULL,
    `release_reason` VARCHAR(64) DEFAULT NULL,
    `result_json` MEDIUMTEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_bao_operation_key` (`operation_key`),
    INDEX `idx_bao_user_feature` (`user_id`, `feature`),
    INDEX `idx_bao_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- По умолчанию продажа AI-аддонов выключена (генераторы ещё не подключены).
-- VAS-15.5: раздельные флаги на продукт; legacy общий ключ — только fallback.
INSERT INTO `settings` (`key`, `value`) VALUES
    ('business_ai_addon_sales_enabled', '0'),
    ('business_ai_infographic_addon_sales_enabled', '0'),
    ('business_ai_tryon_addon_sales_enabled', '0')
ON DUPLICATE KEY UPDATE `value` = `value`;
