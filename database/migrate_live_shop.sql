-- Live shopping extras (also auto-created by Stream model on request)
CREATE TABLE IF NOT EXISTS stream_comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stream_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    user_name VARCHAR(100) NOT NULL,
    body VARCHAR(280) NOT NULL,
    is_host TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stream_comments (stream_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stream_viewers (
    stream_id INT UNSIGNED NOT NULL,
    viewer_key VARCHAR(64) NOT NULL,
    last_seen DATETIME NOT NULL,
    PRIMARY KEY (stream_id, viewer_key),
    INDEX idx_viewer_seen (stream_id, last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
