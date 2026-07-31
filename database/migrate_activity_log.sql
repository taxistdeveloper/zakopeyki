-- Activity / error logs for admin audit — zakopeyki.kz

CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL,
  `user_name` VARCHAR(120) NULL,
  `action` VARCHAR(64) NOT NULL,
  `level` ENUM('info', 'warning', 'error') NOT NULL DEFAULT 'info',
  `entity_type` VARCHAR(32) NULL,
  `entity_id` INT UNSIGNED NULL,
  `message` VARCHAR(500) NOT NULL,
  `context_json` JSON NULL,
  `ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_activity_created` (`created_at`),
  INDEX `idx_activity_level` (`level`),
  INDEX `idx_activity_action` (`action`),
  INDEX `idx_activity_user` (`user_id`),
  INDEX `idx_activity_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
