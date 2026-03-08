-- rJournaler_Web initial schema
-- Apply with: mysql -u <user> -p <database> < sql/migrations/001_initial_schema.sql

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    totp_secret_encrypted VARBINARY(512) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS trusted_devices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    device_name VARCHAR(128) NULL,
    user_agent_hash CHAR(64) NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_trusted_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_trusted_devices_user_expires (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NULL,
    ip_address VARCHAR(45) NOT NULL,
    was_successful TINYINT(1) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auth_attempts_ip_time (ip_address, attempted_at),
    INDEX idx_auth_attempts_user_time (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(64) NOT NULL,
    event_data_json JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_log_event_time (event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS journal_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_uid VARCHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    entry_date DATE NOT NULL,
    title VARCHAR(255) NOT NULL,
    content_raw LONGTEXT NOT NULL,
    content_html LONGTEXT NULL,
    word_count INT UNSIGNED NOT NULL DEFAULT 0,
    workflow_stage ENUM('AUTOSAVE','WRITTEN','FINISHED','IN_PROCESS','COMPLETE','REPROCESS','FINAL','ERROR') NOT NULL DEFAULT 'AUTOSAVE',
    body_locked TINYINT(1) NOT NULL DEFAULT 0,
    stage_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_journal_entries_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_journal_entries_entry_uid (entry_uid),
    INDEX idx_journal_entries_user_date (user_id, entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS entry_tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_uid VARCHAR(64) NOT NULL,
    tag_name VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_entry_tags_entry_uid FOREIGN KEY (entry_uid) REFERENCES journal_entries(entry_uid) ON DELETE CASCADE,
    UNIQUE KEY uq_entry_tag_uid (entry_uid, tag_name),
    INDEX idx_entry_tags_tag_name (tag_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS entry_metrics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_uid VARCHAR(64) NOT NULL,
    sentiment_compound DECIMAL(6,4) NULL,
    readability_grade DECIMAL(6,2) NULL,
    misspelled_count INT UNSIGNED NULL,
    metrics_json JSON NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_entry_metrics_entry_uid FOREIGN KEY (entry_uid) REFERENCES journal_entries(entry_uid) ON DELETE CASCADE,
    UNIQUE KEY uq_entry_metrics_entry_uid (entry_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS worker_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_type VARCHAR(64) NOT NULL,
    entry_uid VARCHAR(64) NULL,
    submitter VARCHAR(32) NOT NULL DEFAULT 'USER',
    stage_label VARCHAR(64) NOT NULL DEFAULT 'Queued',
    queue_comment TEXT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    payload_json JSON NOT NULL,
    status ENUM('queued', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'queued',
    priority INT NOT NULL DEFAULT 100,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    run_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    locked_by VARCHAR(128) NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_worker_jobs_status_run_after_priority (status, run_after, priority),
    INDEX idx_worker_jobs_locked (locked_at),
    INDEX idx_worker_jobs_entry_uid (entry_uid),
    INDEX idx_worker_jobs_submitted (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS entry_meta_group_0 (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_uid VARCHAR(64) NOT NULL,
    entry_title VARCHAR(255) NOT NULL,
    created_datetime DATETIME NOT NULL,
    modified_datetime DATETIME NOT NULL,
    entry_hash_sha256 CHAR(64) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_entry_meta_group_0_uid FOREIGN KEY (entry_uid) REFERENCES journal_entries(entry_uid) ON DELETE CASCADE,
    UNIQUE KEY uq_entry_meta_group_0_uid (entry_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS entry_meta_group_1 (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_uid VARCHAR(64) NOT NULL,
    word_count INT UNSIGNED NOT NULL DEFAULT 0,
    reading_time_minutes DECIMAL(5,1) NOT NULL DEFAULT 0.0,
    flesch_reading_ease DECIMAL(8,3) NULL,
    flesch_kincaid_grade DECIMAL(8,3) NULL,
    gunning_fog DECIMAL(8,3) NULL,
    smog_index DECIMAL(8,3) NULL,
    automated_readability_index DECIMAL(8,3) NULL,
    dale_chall DECIMAL(8,3) NULL,
    average_word_length DECIMAL(8,3) NULL,
    long_word_ratio DECIMAL(8,3) NULL,
    thought_fragmentation DECIMAL(8,3) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_entry_meta_group_1_uid FOREIGN KEY (entry_uid) REFERENCES journal_entries(entry_uid) ON DELETE CASCADE,
    UNIQUE KEY uq_entry_meta_group_1_uid (entry_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS entry_meta_group_2 (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_uid VARCHAR(64) NOT NULL,
    llm_model VARCHAR(128) NULL,
    analysis_json JSON NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_entry_meta_group_2_uid FOREIGN KEY (entry_uid) REFERENCES journal_entries(entry_uid) ON DELETE CASCADE,
    UNIQUE KEY uq_entry_meta_group_2_uid (entry_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS worker_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    worker_name VARCHAR(128) NOT NULL,
    started_at DATETIME NOT NULL,
    heartbeat_at DATETIME NOT NULL,
    status VARCHAR(32) NOT NULL,
    notes VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_worker_runs_worker_time (worker_name, heartbeat_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS service_health (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_name VARCHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL,
    details_json JSON NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_service_health_service (service_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
