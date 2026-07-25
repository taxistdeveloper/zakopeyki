-- Password reset tokens for existing installs (optional — User::ensureColumns() also applies this)
USE zakapeiku;

ALTER TABLE users ADD COLUMN password_reset_token VARCHAR(64) DEFAULT NULL AFTER two_factor_recovery_codes;
ALTER TABLE users ADD COLUMN password_reset_expires DATETIME DEFAULT NULL AFTER password_reset_token;
