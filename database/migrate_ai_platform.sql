-- AI Platform Layer — расширение существующего AI Support
-- Запускать после migrate_ai_support.sql
-- Предпочтительно: php bin/ai_migrate.php
-- Совместимо с MySQL 8 (без ADD COLUMN IF NOT EXISTS — это синтаксис MariaDB).

SET NAMES utf8mb4;

DELIMITER $$

DROP PROCEDURE IF EXISTS ai_add_column_if_missing$$

CREATE PROCEDURE ai_add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
    ) THEN
        SET @ddl = CONCAT(
            'ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition
        );
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS ai_add_index_if_missing$$

CREATE PROCEDURE ai_add_index_if_missing(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_columns TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND INDEX_NAME = p_index
    ) THEN
        SET @ddl = CONCAT(
            'CREATE INDEX `', p_index, '` ON `', p_table, '` ', p_columns
        );
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- Диалоги: контекст страницы
CALL ai_add_column_if_missing('ai_conversations', 'context_json', 'JSON NULL AFTER `guest_token`');
CALL ai_add_column_if_missing('ai_conversations', 'language', 'VARCHAR(8) NULL AFTER `context_json`');

-- Сообщения: structured response
CALL ai_add_column_if_missing('ai_messages', 'request_id', 'VARCHAR(64) NULL AFTER `id`');
CALL ai_add_column_if_missing('ai_messages', 'response_type', 'VARCHAR(64) NULL AFTER `message`');
CALL ai_add_column_if_missing('ai_messages', 'language', 'VARCHAR(8) NULL AFTER `response_type`');
CALL ai_add_index_if_missing('ai_messages', 'idx_ai_msg_request', '(`request_id`)');

-- Feedback reasons
CALL ai_add_column_if_missing('ai_feedback', 'reason', 'VARCHAR(64) NULL AFTER `comment`');
CALL ai_add_column_if_missing('ai_feedback', 'user_id', 'INT UNSIGNED NULL AFTER `reason`');

CREATE TABLE IF NOT EXISTS `ai_memories` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `type` ENUM('user','semantic','episodic','preference') NOT NULL DEFAULT 'user',
  `content` TEXT NOT NULL,
  `importance` DECIMAL(3,2) NOT NULL DEFAULT 0.50,
  `confidence` DECIMAL(3,2) NOT NULL DEFAULT 0.50,
  `source` VARCHAR(64) NOT NULL DEFAULT 'system',
  `meta_json` JSON NULL,
  `expires_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_ai_mem_user_type` (`user_id`, `type`),
  INDEX `idx_ai_mem_expires` (`expires_at`),
  INDEX `idx_ai_mem_importance` (`importance`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_memory_embeddings` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `memory_id` BIGINT UNSIGNED NOT NULL,
  `model` VARCHAR(128) NOT NULL,
  `dims` SMALLINT UNSIGNED NOT NULL,
  `embedding_json` LONGTEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_ai_mem_emb` (`memory_id`, `model`),
  CONSTRAINT `fk_ai_mem_emb_memory`
    FOREIGN KEY (`memory_id`) REFERENCES `ai_memories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_knowledge_chunks` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `document_id` INT UNSIGNED NOT NULL,
  `chunk_index` INT UNSIGNED NOT NULL DEFAULT 0,
  `content` TEXT NOT NULL,
  `token_estimate` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_ai_chunk_doc` (`document_id`),
  FULLTEXT KEY `ft_ai_chunk` (`content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_knowledge_embeddings` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `chunk_id` BIGINT UNSIGNED NOT NULL,
  `model` VARCHAR(128) NOT NULL,
  `dims` SMALLINT UNSIGNED NOT NULL,
  `embedding_json` LONGTEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_ai_chunk_emb` (`chunk_id`, `model`),
  CONSTRAINT `fk_ai_chunk_emb`
    FOREIGN KEY (`chunk_id`) REFERENCES `ai_knowledge_chunks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_tool_calls` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `request_id` VARCHAR(64) NOT NULL,
  `conversation_id` BIGINT UNSIGNED NULL,
  `user_id` INT UNSIGNED NULL,
  `tool_name` VARCHAR(128) NOT NULL,
  `input_json` JSON NULL,
  `output_json` JSON NULL,
  `status` ENUM('ok','error','denied','pending_confirm') NOT NULL DEFAULT 'ok',
  `error_message` VARCHAR(500) NULL,
  `latency_ms` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ai_tool_req` (`request_id`),
  INDEX `idx_ai_tool_name` (`tool_name`),
  INDEX `idx_ai_tool_conv` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_action_confirmations` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `token` VARCHAR(64) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `conversation_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(128) NOT NULL,
  `payload_json` JSON NOT NULL,
  `status` ENUM('pending','confirmed','cancelled','expired') NOT NULL DEFAULT 'pending',
  `expires_at` DATETIME NOT NULL,
  `confirmed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_ai_confirm_token` (`token`),
  INDEX `idx_ai_confirm_user` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_prompts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug` VARCHAR(64) NOT NULL,
  `description` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_ai_prompt_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_prompt_versions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `prompt_id` INT UNSIGNED NOT NULL,
  `version` VARCHAR(32) NOT NULL,
  `content` MEDIUMTEXT NOT NULL,
  `status` ENUM('draft','active','archived','candidate') NOT NULL DEFAULT 'draft',
  `evaluation_score` DECIMAL(5,2) NULL,
  `author` VARCHAR(64) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_ai_prompt_ver` (`prompt_id`, `version`),
  INDEX `idx_ai_prompt_status` (`prompt_id`, `status`),
  CONSTRAINT `fk_ai_prompt_ver`
    FOREIGN KEY (`prompt_id`) REFERENCES `ai_prompts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_model_configs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `task_type` VARCHAR(64) NOT NULL,
  `provider` VARCHAR(32) NOT NULL DEFAULT 'ollama',
  `model` VARCHAR(128) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `params_json` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_ai_model_task` (`task_type`, `provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_user_preferences` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `pref_key` VARCHAR(64) NOT NULL,
  `pref_value` TEXT NOT NULL,
  `confidence` DECIMAL(3,2) NOT NULL DEFAULT 0.50,
  `source` VARCHAR(64) NOT NULL DEFAULT 'inferred',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_ai_user_pref` (`user_id`, `pref_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_usage_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `request_id` VARCHAR(64) NOT NULL,
  `user_id` INT UNSIGNED NULL,
  `conversation_id` BIGINT UNSIGNED NULL,
  `provider` VARCHAR(32) NULL,
  `model` VARCHAR(128) NULL,
  `intent` VARCHAR(64) NULL,
  `tokens_in` INT UNSIGNED NULL,
  `tokens_out` INT UNSIGNED NULL,
  `latency_ms` INT UNSIGNED NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'ok',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ai_usage_req` (`request_id`),
  INDEX `idx_ai_usage_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_audit_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `request_id` VARCHAR(64) NOT NULL,
  `user_id` INT UNSIGNED NULL,
  `conversation_id` BIGINT UNSIGNED NULL,
  `event` VARCHAR(64) NOT NULL,
  `detail_json` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ai_audit_req` (`request_id`),
  INDEX `idx_ai_audit_event` (`event`),
  INDEX `idx_ai_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_learning_events` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_type` VARCHAR(64) NOT NULL,
  `payload_json` JSON NOT NULL,
  `status` ENUM('pending','processed','failed','skipped') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME NULL,
  INDEX `idx_ai_learn_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_evaluations` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `request_id` VARCHAR(64) NULL,
  `message_id` BIGINT UNSIGNED NULL,
  `metric` VARCHAR(64) NOT NULL,
  `score` DECIMAL(8,4) NOT NULL,
  `meta_json` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ai_eval_metric` (`metric`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_listing_drafts` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `conversation_id` BIGINT UNSIGNED NULL,
  `payload_json` JSON NOT NULL,
  `status` ENUM('draft','confirmed','published','cancelled') NOT NULL DEFAULT 'draft',
  `product_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_ai_draft_user` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_rate_limits` (
  `bucket_key` VARCHAR(191) NOT NULL PRIMARY KEY,
  `hits` INT UNSIGNED NOT NULL DEFAULT 0,
  `window_start` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ai_model_configs` (`task_type`, `provider`, `model`, `is_active`, `params_json`)
VALUES
  ('chat', 'ollama', 'qwen2.5:7b-instruct', 1, '{"temperature":0.1}'),
  ('intent', 'ollama', 'qwen2.5:7b-instruct', 1, '{"temperature":0.0,"format":"json"}'),
  ('vision', 'ollama', 'llava', 1, '{"temperature":0.1}'),
  ('embedding', 'ollama', 'nomic-embed-text', 1, NULL),
  ('simple', 'ollama', 'qwen2.5:7b-instruct', 1, '{"temperature":0.1}')
ON DUPLICATE KEY UPDATE `model` = VALUES(`model`);

INSERT INTO `ai_prompts` (`slug`, `description`)
VALUES ('assistant_system', 'Главный system prompt платформенного ассистента')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

INSERT INTO `ai_prompt_versions` (`prompt_id`, `version`, `content`, `status`, `author`)
SELECT p.id, 'v1',
'Вы — интеллектуальный ассистент маркетплейса zakopeyki.kz.
Отвечайте кратко на языке пользователя (ru/kk/en).
Используйте ТОЛЬКО данные из tools и базы знаний.
Запрещено придумывать товары, цены, статусы заказов, доставку и правила.
Если данных нет — честно скажите об этом.
Бренды не переводите без необходимости.
Критические действия выполняйте только после подтверждения пользователя.',
'active', 'system'
FROM `ai_prompts` p
WHERE p.slug = 'assistant_system'
  AND NOT EXISTS (
    SELECT 1 FROM `ai_prompt_versions` v WHERE v.prompt_id = p.id AND v.version = 'v1'
  );

DROP PROCEDURE IF EXISTS ai_add_column_if_missing;
DROP PROCEDURE IF EXISTS ai_add_index_if_missing;
