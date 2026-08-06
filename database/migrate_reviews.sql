-- Рейтинг и отзывы по завершённым сделкам (покупатель ↔ продавец)

CREATE TABLE IF NOT EXISTS `reviews` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT UNSIGNED NOT NULL,
  `author_id` INT UNSIGNED NOT NULL,
  `subject_id` INT UNSIGNED NOT NULL,
  `role` ENUM('as_seller', 'as_buyer') NOT NULL,
  `rating` TINYINT UNSIGNED NOT NULL,
  `body` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_order_author` (`order_id`, `author_id`),
  INDEX `idx_subject` (`subject_id`),
  INDEX `idx_author` (`author_id`),
  INDEX `idx_rating` (`rating`),
  CONSTRAINT `chk_rating` CHECK (`rating` BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
