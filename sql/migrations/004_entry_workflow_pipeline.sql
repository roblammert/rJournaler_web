-- Add workflow stages, queue metadata, and processing meta groups.

ALTER TABLE journal_entries
    ADD COLUMN workflow_stage ENUM('AUTOSAVE','WRITTEN','FINISHED','IN_PROCESS','COMPLETE','REPROCESS','FINAL','ERROR') NOT NULL DEFAULT 'AUTOSAVE' AFTER word_count,
    ADD COLUMN body_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER workflow_stage,
    ADD COLUMN stage_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER body_locked;

UPDATE journal_entries
SET workflow_stage = 'AUTOSAVE', body_locked = 0, stage_updated_at = COALESCE(updated_at, created_at, UTC_TIMESTAMP())
WHERE workflow_stage IS NULL OR workflow_stage = '';

ALTER TABLE worker_jobs
    ADD COLUMN entry_uid VARCHAR(64) NULL AFTER job_type,
    ADD COLUMN submitter VARCHAR(32) NOT NULL DEFAULT 'USER' AFTER entry_uid,
    ADD COLUMN stage_label VARCHAR(64) NOT NULL DEFAULT 'Queued' AFTER submitter,
    ADD COLUMN queue_comment TEXT NULL AFTER stage_label,
    ADD COLUMN submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER queue_comment,
    ADD COLUMN completed_at DATETIME NULL AFTER submitted_at;

UPDATE worker_jobs
SET submitted_at = COALESCE(created_at, UTC_TIMESTAMP())
WHERE submitted_at IS NULL;

ALTER TABLE worker_jobs
    ADD INDEX idx_worker_jobs_entry_uid (entry_uid),
    ADD INDEX idx_worker_jobs_submitted (submitted_at);

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
