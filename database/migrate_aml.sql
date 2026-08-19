-- AML/CFT: локальная копия перечней АФМ РК (терроризм/экстремизм, ФРОМУ)
CREATE TABLE IF NOT EXISTS `aml_blacklisted_persons` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `iin` VARCHAR(12) NOT NULL,
    `full_name` VARCHAR(255) NULL,
    `list_type` ENUM('person', 'organization') NOT NULL DEFAULT 'person',
    `source_name` VARCHAR(100) DEFAULT 'AFM_RK',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_iin` (`iin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
