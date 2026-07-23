-- Zakopeyki MySQL schema
CREATE DATABASE IF NOT EXISTS zakapeiku CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE zakapeiku;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    google_id VARCHAR(64) DEFAULT NULL UNIQUE,
    password VARCHAR(255) NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    avatar VARCHAR(10) DEFAULT 'U',
    avatar_file VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type ENUM('used','new','auction','free','exchange','service','course') NOT NULL,
    category VARCHAR(100) NOT NULL DEFAULT 'Разное',
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    price INT UNSIGNED NOT NULL DEFAULT 0,
    exchange_for VARCHAR(255) DEFAULT NULL,
    price_label VARCHAR(100) DEFAULT NULL,
    current_bid INT UNSIGNED DEFAULT NULL,
    bid_step INT UNSIGNED DEFAULT 1000,
    location VARCHAR(150) NOT NULL DEFAULT 'Караганда',
    image VARCHAR(255) DEFAULT NULL,
    images TEXT DEFAULT NULL,
    status ENUM('active','sold','archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_type (type),
    INDEX idx_status (status),
    FULLTEXT INDEX ft_search (title, description)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bids (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    amount INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    message VARCHAR(500) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS favorites (
    user_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, product_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product (product_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    caption VARCHAR(280) DEFAULT NULL,
    image VARCHAR(255) DEFAULT NULL,
    bg_color VARCHAR(20) NOT NULL DEFAULT '#f59e0b',
    emoji VARCHAR(16) DEFAULT '✨',
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS streams (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    video_url VARCHAR(500) DEFAULT NULL,
    video_file VARCHAR(255) DEFAULT NULL,
    cover VARCHAR(255) DEFAULT NULL,
                is_live TINYINT(1) NOT NULL DEFAULT 0,
                last_heartbeat DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_live (is_live),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;
