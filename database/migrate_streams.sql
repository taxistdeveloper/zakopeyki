USE zakapeiku;

CREATE TABLE IF NOT EXISTS streams (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    video_url VARCHAR(500) DEFAULT NULL,
    video_file VARCHAR(255) DEFAULT NULL,
    cover VARCHAR(255) DEFAULT NULL,
    is_live TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_live (is_live),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;
