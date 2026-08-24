-- Цифровой контент / Live (Cloudflare Stream). Видео не хранится на сервере Zakopeyki.

CREATE TABLE IF NOT EXISTS digital_products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    author_id INT UNSIGNED NOT NULL,
    kind ENUM('vod','live_open','live_closed','webinar','course','event','bundle') NOT NULL DEFAULT 'live_closed',
    access_mode ENUM('paid','free_enrolled') NOT NULL DEFAULT 'paid',
    record_enabled TINYINT(1) NOT NULL DEFAULT 1,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 120,
    starts_at DATETIME DEFAULT NULL,
    ends_at DATETIME DEFAULT NULL,
    access_days SMALLINT UNSIGNED NOT NULL DEFAULT 365,
    live_status ENUM('idle','ready','live','ended') NOT NULL DEFAULT 'idle',
    cf_live_input_uid VARCHAR(64) DEFAULT NULL,
    cf_playback_uid VARCHAR(64) DEFAULT NULL,
    cf_recording_uid VARCHAR(64) DEFAULT NULL,
    rtmps_url VARCHAR(500) DEFAULT NULL,
    stream_key VARCHAR(255) DEFAULT NULL,
    srt_url VARCHAR(500) DEFAULT NULL,
    watermark_mode ENUM('none','name','order','email') NOT NULL DEFAULT 'order',
    meta_json TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_dp_product (product_id),
    INDEX idx_dp_author (author_id),
    INDEX idx_dp_kind_status (kind, live_status),
    INDEX idx_dp_starts (starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS digital_lessons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    digital_product_id INT UNSIGNED NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    kind ENUM('video','pdf','text','live_session') NOT NULL DEFAULT 'video',
    title VARCHAR(255) NOT NULL,
    body MEDIUMTEXT DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    cf_video_uid VARCHAR(64) DEFAULT NULL,
    live_session_id INT UNSIGNED DEFAULT NULL,
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    is_preview TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dl_product (digital_product_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS digital_live_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    digital_product_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    starts_at DATETIME NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 90,
    live_status ENUM('idle','ready','live','ended') NOT NULL DEFAULT 'idle',
    cf_live_input_uid VARCHAR(64) DEFAULT NULL,
    cf_playback_uid VARCHAR(64) DEFAULT NULL,
    cf_recording_uid VARCHAR(64) DEFAULT NULL,
    rtmps_url VARCHAR(500) DEFAULT NULL,
    stream_key VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dls_product (digital_product_id, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS digital_access (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    digital_product_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED DEFAULT NULL,
    status ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
    access_from DATETIME NOT NULL,
    access_until DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_da_user_product (user_id, digital_product_id),
    INDEX idx_da_order (order_id),
    INDEX idx_da_until (status, access_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS digital_playback_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    access_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    digital_product_id INT UNSIGNED NOT NULL,
    lesson_id INT UNSIGNED DEFAULT NULL,
    live_session_id INT UNSIGNED DEFAULT NULL,
    token_hash CHAR(64) NOT NULL,
    video_uid VARCHAR(64) DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dpt_hash (token_hash),
    INDEX idx_dpt_user (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS digital_watch_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    digital_product_id INT UNSIGNED NOT NULL,
    lesson_id INT UNSIGNED DEFAULT NULL,
    seconds_watched INT UNSIGNED NOT NULL DEFAULT 0,
    ip VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dwl_user_product (user_id, digital_product_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS digital_provider_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(32) NOT NULL DEFAULT 'cloudflare',
    event_uid VARCHAR(80) DEFAULT NULL,
    event_type VARCHAR(80) NOT NULL,
    payload_json MEDIUMTEXT DEFAULT NULL,
    processed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dpe_type (event_type, created_at),
    INDEX idx_dpe_uid (event_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
