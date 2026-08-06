-- Unique site visitors (cookie-based). Created automatically by SiteVisit model too.
CREATE TABLE IF NOT EXISTS site_visits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visitor_key CHAR(32) NOT NULL,
    user_id INT UNSIGNED NULL,
    user_name VARCHAR(120) NULL,
    path VARCHAR(255) NOT NULL DEFAULT '/',
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 1,
    visit_date DATE NOT NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_visitor_day (visitor_key, visit_date),
    INDEX idx_visit_date (visit_date),
    INDEX idx_visit_last (last_seen_at),
    INDEX idx_visit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
