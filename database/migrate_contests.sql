-- Модуль «Конкурсы и розыгрыши» (MASTERING-02)
-- Сгенерировано из App\Services\Contest\ContestSchema::statements(). Не редактировать вручную:
-- php bin/contests_schema_sql.php > database/migrate_contests.sql

-- contests
CREATE TABLE IF NOT EXISTS contests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(80) NOT NULL,
    organizer_id INT UNSIGNED NOT NULL,
    mechanic ENUM('jury','random') NOT NULL,
    status ENUM('draft','pending_moderation','rejected','scheduled','active','closed','results_published','completed','suspended','cancelled','disputed') NOT NULL DEFAULT 'draft',
    title VARCHAR(160) NOT NULL,
    summary VARCHAR(300) NOT NULL DEFAULT '',
    description TEXT NULL,
    prize_summary VARCHAR(255) NOT NULL DEFAULT '',
    category VARCHAR(120) NULL,
    region VARCHAR(40) NULL,
    cover_image VARCHAR(255) NULL,
    winners_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    fulfilment_days SMALLINT UNSIGNED NOT NULL DEFAULT 14,
    current_rule_version_id INT UNSIGNED NULL,
    random_commit CHAR(64) NULL COMMENT 'SHA-256 секретного seed, публикуется до старта',
    random_seed CHAR(64) NULL COMMENT 'seed (hex), раскрывается после подведения итогов',
    entries_count INT UNSIGNED NOT NULL DEFAULT 0,
    views_count INT UNSIGNED NOT NULL DEFAULT 0,
    moderation_note VARCHAR(500) NULL,
    moderated_by INT UNSIGNED NULL,
    moderated_at DATETIME NULL,
    suspended_reason VARCHAR(500) NULL,
    reminder_sent_at DATETIME NULL,
    published_at DATETIME NULL,
    closed_at DATETIME NULL,
    results_at DATETIME NULL,
    completed_at DATETIME NULL,
    idempotency_key VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_contest_slug (slug),
    UNIQUE KEY uq_contest_idem (organizer_id, idempotency_key),
    INDEX idx_contest_status_dates (status, starts_at, ends_at),
    INDEX idx_contest_organizer (organizer_id, status),
    INDEX idx_contest_region_cat (region, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- contest_rule_versions
CREATE TABLE IF NOT EXISTS contest_rule_versions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contest_id INT UNSIGNED NOT NULL,
    version SMALLINT UNSIGNED NOT NULL,
    mechanic ENUM('jury','random') NOT NULL,
    winners_count SMALLINT UNSIGNED NOT NULL,
    rules_text TEXT NOT NULL,
    criteria_text TEXT NULL,
    conditions_json JSON NOT NULL,
    prizes_json JSON NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    content_hash CHAR(64) NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rule_version (contest_id, version),
    CONSTRAINT fk_rule_contest FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- contest_entries
CREATE TABLE IF NOT EXISTS contest_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contest_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL COMMENT 'NULL после удаления аккаунта (участие обезличено)',
    rule_version_id INT UNSIGNED NOT NULL,
    status ENUM('pending','confirmed','disqualified','withdrawn') NOT NULL DEFAULT 'pending',
    submission_text TEXT NULL,
    ref_user_id INT UNSIGNED NULL,
    ip_hash CHAR(64) NULL,
    visitor_key CHAR(32) NULL,
    fraud_flags VARCHAR(255) NULL,
    status_reason VARCHAR(500) NULL,
    status_changed_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_entry_user (contest_id, user_id),
    INDEX idx_entry_status (contest_id, status),
    INDEX idx_entry_user (user_id, created_at),
    INDEX idx_entry_ip (contest_id, ip_hash),
    CONSTRAINT fk_entry_contest FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- contest_entry_conditions
CREATE TABLE IF NOT EXISTS contest_entry_conditions (
    entry_id INT UNSIGNED NOT NULL,
    condition_key VARCHAR(40) NOT NULL,
    status ENUM('met','not_met','pending') NOT NULL DEFAULT 'pending',
    detail VARCHAR(255) NULL,
    checked_at DATETIME NOT NULL,
    PRIMARY KEY (entry_id, condition_key),
    CONSTRAINT fk_cond_entry FOREIGN KEY (entry_id) REFERENCES contest_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- contest_platform_events
CREATE TABLE IF NOT EXISTS contest_platform_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(32) NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    target_type VARCHAR(32) NOT NULL,
    target_id INT UNSIGNED NOT NULL,
    occurred_at DATETIME(3) NOT NULL,
    INDEX idx_event_target (target_type, target_id, actor_id, occurred_at),
    INDEX idx_event_time (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Append-only журнал действий для проверки условий';

-- contest_views
CREATE TABLE IF NOT EXISTS contest_views (
    contest_id INT UNSIGNED NOT NULL,
    visitor_key CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (contest_id, visitor_key),
    INDEX idx_view_time (contest_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- contest_ref_visits
CREATE TABLE IF NOT EXISTS contest_ref_visits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contest_id INT UNSIGNED NOT NULL,
    ref_user_id INT UNSIGNED NOT NULL,
    visitor_key CHAR(32) NOT NULL,
    registered_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    registered_at DATETIME NULL,
    UNIQUE KEY uq_ref_visit (contest_id, ref_user_id, visitor_key),
    UNIQUE KEY uq_ref_registered (registered_user_id),
    INDEX idx_ref_contest (contest_id, ref_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- contest_result_executions
CREATE TABLE IF NOT EXISTS contest_result_executions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contest_id INT UNSIGNED NOT NULL,
    rule_version_id INT UNSIGNED NOT NULL,
    mechanic ENUM('jury','random') NOT NULL,
    algorithm VARCHAR(64) NOT NULL,
    eligible_count INT UNSIGNED NOT NULL,
    eligible_hash CHAR(64) NOT NULL COMMENT 'SHA-256 упорядоченного списка ID допущенных участий',
    commit_hash CHAR(64) NULL,
    seed CHAR(64) NULL,
    executed_by INT UNSIGNED NOT NULL,
    executed_at DATETIME NOT NULL,
    details_json JSON NULL,
    UNIQUE KEY uq_result_contest (contest_id),
    CONSTRAINT fk_result_contest FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- contest_winners
CREATE TABLE IF NOT EXISTS contest_winners (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    execution_id INT UNSIGNED NOT NULL,
    contest_id INT UNSIGNED NOT NULL,
    entry_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    place SMALLINT UNSIGNED NOT NULL,
    prize_title VARCHAR(255) NOT NULL,
    jury_note VARCHAR(500) NULL,
    fulfilment_status ENUM('pending','handed_over','confirmed','disputed','expired') NOT NULL DEFAULT 'pending',
    fulfilment_note VARCHAR(500) NULL,
    handed_over_at DATETIME NULL,
    confirmed_at DATETIME NULL,
    fulfilment_due_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_winner_place (contest_id, place),
    UNIQUE KEY uq_winner_entry (contest_id, entry_id),
    INDEX idx_winner_user (user_id),
    INDEX idx_winner_fulfil (fulfilment_status, fulfilment_due_at),
    CONSTRAINT fk_winner_exec FOREIGN KEY (execution_id) REFERENCES contest_result_executions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- contest_reports
CREATE TABLE IF NOT EXISTS contest_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contest_id INT UNSIGNED NOT NULL,
    reporter_id INT UNSIGNED NULL,
    reason VARCHAR(32) NOT NULL,
    details VARCHAR(1000) NULL,
    status ENUM('open','resolved','rejected') NOT NULL DEFAULT 'open',
    resolution_note VARCHAR(500) NULL,
    resolved_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    INDEX idx_report_status (status, created_at),
    INDEX idx_report_contest (contest_id, reporter_id, status),
    CONSTRAINT fk_report_contest FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

