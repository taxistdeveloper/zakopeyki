-- Manager role + per-section permissions (JSON)
-- Runtime ensureColumns() in User model also applies this.

ALTER TABLE users
    MODIFY COLUMN role ENUM('user', 'manager', 'admin') NOT NULL DEFAULT 'user';

-- If column already exists, ignore the error:
-- ALTER TABLE users ADD COLUMN permissions TEXT DEFAULT NULL COMMENT 'JSON permissions for manager' AFTER role;
