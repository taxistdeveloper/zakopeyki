-- Bonus balances (loyalty points, separate from wallet)
CREATE TABLE IF NOT EXISTS bonus_balances (
    user_id INT UNSIGNED PRIMARY KEY,
    balance INT UNSIGNED NOT NULL DEFAULT 0,
    gym_code VARCHAR(20) DEFAULT NULL UNIQUE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bonus_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(40) NOT NULL,
    amount INT NOT NULL,
    balance_after INT UNSIGNED NOT NULL,
    ref_type VARCHAR(40) DEFAULT NULL,
    ref_id INT UNSIGNED DEFAULT NULL,
    meta VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_type (type),
    INDEX idx_ref (ref_type, ref_id),
    INDEX idx_created (created_at),
    UNIQUE KEY uq_award (user_id, type, ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_gyms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    address VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL DEFAULT 'Караганда',
    phone VARCHAR(40) DEFAULT NULL,
    hours VARCHAR(120) DEFAULT NULL,
    perk VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- For existing installs that already have bonus_balances without gym_code:
-- ALTER TABLE bonus_balances ADD COLUMN gym_code VARCHAR(20) DEFAULT NULL UNIQUE AFTER balance;
