-- AI Support / Local LLM (Ollama) — zakopeyki.kz
-- Префикс ai_* чтобы не конфликтовать с support_tickets / support_messages

CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL,
  `guest_token` VARCHAR(64) NULL,
  `status` ENUM('ai_active', 'human_escalated', 'closed') NOT NULL DEFAULT 'ai_active',
  `assigned_agent_id` INT UNSIGNED NULL,
  `last_message_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_ai_conv_user` (`user_id`),
  INDEX `idx_ai_conv_guest` (`guest_token`),
  INDEX `idx_ai_conv_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_messages` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `sender_type` ENUM('user', 'ai', 'agent', 'system') NOT NULL,
  `sender_id` INT UNSIGNED NULL,
  `message` TEXT NOT NULL,
  `confidence_score` DECIMAL(4,3) NULL,
  `meta_json` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ai_msg_conv` (`conversation_id`),
  INDEX `idx_ai_msg_created` (`created_at`),
  CONSTRAINT `fk_ai_messages_conversation`
    FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_knowledge_base` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `category` VARCHAR(64) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `keywords` VARCHAR(512) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `source` VARCHAR(32) NOT NULL DEFAULT 'seed',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_ai_kb_category` (`category`),
  INDEX `idx_ai_kb_active` (`is_active`),
  FULLTEXT KEY `ft_ai_knowledge_search` (`title`, `content`, `keywords`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_queue_jobs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `queue` VARCHAR(64) NOT NULL DEFAULT 'default',
  `payload` LONGTEXT NOT NULL,
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `reserved_at` DATETIME NULL,
  `available_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ai_queue_ready` (`queue`, `reserved_at`, `available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_intent_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `detected_intent` VARCHAR(64) NOT NULL,
  `confidence` DECIMAL(4,3) NOT NULL DEFAULT 0.000,
  `method` VARCHAR(32) NULL,
  `raw_prompt` TEXT NULL,
  `raw_response` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ai_intent_msg` (`message_id`),
  CONSTRAINT `fk_ai_intent_message`
    FOREIGN KEY (`message_id`) REFERENCES `ai_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_feedback` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `rating` TINYINT NOT NULL,
  `comment` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ai_feedback_rating` (`rating`),
  CONSTRAINT `fk_ai_feedback_message`
    FOREIGN KEY (`message_id`) REFERENCES `ai_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_few_shots` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `category` VARCHAR(64) NOT NULL DEFAULT 'general',
  `user_query` TEXT NOT NULL,
  `operator_response` TEXT NOT NULL,
  `quality_score` DECIMAL(3,2) NOT NULL DEFAULT 1.00,
  `is_approved` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FULLTEXT KEY `ft_ai_few_shot_search` (`user_query`, `operator_response`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_training_datasets` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `system_prompt` TEXT NOT NULL,
  `user_input` TEXT NOT NULL,
  `ideal_output` TEXT NOT NULL,
  `source` ENUM('operator_resolution', 'high_csat_ai') NOT NULL,
  `is_exported` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ai_dataset_exported` (`is_exported`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
