-- Support tickets / helpdesk (optional — SupportTicket::ensureTables() also applies this)
USE zakapeiku;

CREATE TABLE IF NOT EXISTS support_tickets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_number VARCHAR(32) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    subject VARCHAR(200) NOT NULL,
    category VARCHAR(32) NOT NULL DEFAULT 'general',
    status ENUM('open', 'answered', 'closed') NOT NULL DEFAULT 'open',
    last_message_at DATETIME DEFAULT NULL,
    last_preview VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_number (ticket_number),
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_last (last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS support_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    sender_type ENUM('user', 'admin', 'system') NOT NULL,
    sender_id INT UNSIGNED DEFAULT NULL,
    body TEXT NOT NULL,
    is_read_by_user TINYINT(1) NOT NULL DEFAULT 0,
    is_read_by_admin TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket (ticket_id),
    INDEX idx_unread_user (ticket_id, is_read_by_user, sender_type),
    INDEX idx_unread_admin (ticket_id, is_read_by_admin, sender_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
