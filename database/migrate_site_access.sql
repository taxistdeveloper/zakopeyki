-- Per-user early access while stub_mode is on — zakopeyki.kz
-- Runtime ensureColumns() in User model also applies this.

-- If column already exists, ignore the error:
ALTER TABLE users
    ADD COLUMN site_access TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Early access while stub_mode'
    AFTER permissions;
