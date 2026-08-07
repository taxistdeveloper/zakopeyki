-- WebRTC signaling for live streams (also auto-created by Stream model)
CREATE TABLE IF NOT EXISTS stream_signals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stream_id INT UNSIGNED NOT NULL,
    peer_id VARCHAR(64) NOT NULL,
    direction ENUM('to_host','to_viewer') NOT NULL,
    type VARCHAR(16) NOT NULL,
    payload MEDIUMTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_host_poll (stream_id, direction, id),
    INDEX idx_viewer_poll (stream_id, peer_id, direction, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
