-- Staging tables for monthly markdown ZIP imports.

CREATE TABLE IF NOT EXISTS import_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    source_name VARCHAR(255) NOT NULL,
    uid_version_digits CHAR(7) NULL,
    status ENUM('parsed', 'accepted', 'denied') NOT NULL DEFAULT 'parsed',
    entry_count INT UNSIGNED NOT NULL DEFAULT 0,
    accepted_at DATETIME NULL,
    denied_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_import_batches_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_import_batches_user_status_created (user_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS import_entries_temp (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    source_path VARCHAR(512) NOT NULL,
    parsed_order INT UNSIGNED NOT NULL,
    entry_date DATE NOT NULL,
    entry_title VARCHAR(255) NOT NULL,
    entry_time_local VARCHAR(32) NOT NULL,
    entry_created_utc DATETIME NOT NULL,
    content_markdown LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_import_entries_temp_batch FOREIGN KEY (batch_id) REFERENCES import_batches(id) ON DELETE CASCADE,
    CONSTRAINT fk_import_entries_temp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_import_entries_temp_batch_order (batch_id, parsed_order),
    INDEX idx_import_entries_temp_user_batch (user_id, batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
