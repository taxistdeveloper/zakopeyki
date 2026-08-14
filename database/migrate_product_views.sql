USE zakapeiku;

ALTER TABLE `products` ADD COLUMN `view_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `winning_bid_id`;

CREATE TABLE IF NOT EXISTS `product_views` (
    `product_id` INT UNSIGNED NOT NULL,
    `visitor_key` CHAR(32) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`product_id`, `visitor_key`),
    INDEX `idx_views_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
