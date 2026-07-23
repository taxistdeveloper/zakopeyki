-- Google OAuth for existing installs (optional — User::ensureColumns() also applies this)
USE zakapeiku;

-- Run once; ignore errors if column/index already exists
ALTER TABLE users ADD COLUMN google_id VARCHAR(64) DEFAULT NULL AFTER email;
ALTER TABLE users MODIFY COLUMN password VARCHAR(255) NULL;
CREATE UNIQUE INDEX users_google_id_unique ON users (google_id);
