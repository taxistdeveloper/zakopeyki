-- Key/value settings (site open/close via stub_mode) — zakopeyki.kz

CREATE TABLE IF NOT EXISTS `settings` (
  `key` VARCHAR(64) NOT NULL PRIMARY KEY,
  `value` TEXT NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional seed: keep stub closed until admin opens the site from the panel.
-- INSERT INTO `settings` (`key`, `value`) VALUES ('stub_mode', '1')
-- ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
