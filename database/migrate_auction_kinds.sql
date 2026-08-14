USE zakapeiku;

ALTER TABLE `products` ADD COLUMN `auction_kind` VARCHAR(20) NOT NULL DEFAULT 'english' AFTER `bid_step`;
ALTER TABLE `products` ADD COLUMN `auction_reserve` INT UNSIGNED DEFAULT NULL AFTER `auction_kind`;
ALTER TABLE `products` ADD COLUMN `auction_buy_now` INT UNSIGNED DEFAULT NULL AFTER `auction_reserve`;
ALTER TABLE `products` ADD COLUMN `auction_min_price` INT UNSIGNED DEFAULT NULL AFTER `auction_reserve`;
ALTER TABLE `products` ADD COLUMN `auction_step_interval` INT UNSIGNED DEFAULT NULL AFTER `auction_min_price`;
ALTER TABLE `products` ADD COLUMN `auction_start_at` DATETIME DEFAULT NULL AFTER `auction_step_interval`;
ALTER TABLE `products` ADD COLUMN `auction_end_at` DATETIME DEFAULT NULL AFTER `auction_start_at`;
ALTER TABLE `products` ADD COLUMN `anti_snipe_seconds` INT UNSIGNED NOT NULL DEFAULT 30 AFTER `auction_end_at`;
ALTER TABLE `products` ADD COLUMN `auto_extend_seconds` INT UNSIGNED NOT NULL DEFAULT 120 AFTER `anti_snipe_seconds`;
ALTER TABLE `products` ADD COLUMN `inactivity_timeout_seconds` INT UNSIGNED DEFAULT NULL AFTER `auto_extend_seconds`;
ALTER TABLE `products` ADD COLUMN `last_bid_at` DATETIME DEFAULT NULL AFTER `inactivity_timeout_seconds`;
ALTER TABLE `products` ADD COLUMN `winner_user_id` INT UNSIGNED DEFAULT NULL AFTER `last_bid_at`;
ALTER TABLE `products` ADD COLUMN `winning_bid_id` INT UNSIGNED DEFAULT NULL AFTER `winner_user_id`;
