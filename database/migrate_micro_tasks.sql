-- Биржа неквалифицированных микрозадач (интеграция с существующим кошельком)
USE zakapeiku;

CREATE TABLE IF NOT EXISTS `micro_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `is_unskilled_only` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `micro_tasks` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT UNSIGNED NOT NULL,
    `executor_id` INT UNSIGNED NULL DEFAULT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `address` VARCHAR(255) NOT NULL,
    `initial_price` INT UNSIGNED NOT NULL,
    `final_price` INT UNSIGNED NULL DEFAULT NULL,
    `platform_fee_percent` DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    `completion_pin` VARCHAR(4) NOT NULL,
    `acquiring_rrn` VARCHAR(64) NULL DEFAULT NULL,
    `status` ENUM('open', 'locked', 'in_progress', 'completed', 'cancelled', 'expired') NOT NULL DEFAULT 'open',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME NOT NULL,
    FOREIGN KEY (`category_id`) REFERENCES `micro_categories`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX `idx_customer` (`customer_id`),
    INDEX `idx_executor` (`executor_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `micro_task_offers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT UNSIGNED NOT NULL,
    `executor_id` INT UNSIGNED NOT NULL,
    `offer_type` ENUM('accept', 'discount_20', 'raise_20', 'custom') NOT NULL,
    `proposed_price` INT UNSIGNED NOT NULL,
    `response_fee_status` ENUM('held', 'charged', 'refunded') NOT NULL DEFAULT 'held',
    `status` ENUM('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`task_id`) REFERENCES `micro_tasks`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_task_executor` (`task_id`, `executor_id`),
    INDEX `idx_executor_status` (`executor_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `micro_categories` (`id`, `name`, `is_unskilled_only`) VALUES
(1, 'Разгрузка и погрузка', 1),
(2, 'Вынос мусора и мебели', 1),
(3, 'Раздача листовок и расклейка', 1),
(4, 'Курьерские поручения', 1),
(5, 'Уборка и клининг', 1),
(6, 'Помощь в саду и на участке', 1);
