-- Add user preferences/admin role and admin-manageable app settings.

ALTER TABLE users
    ADD COLUMN display_name VARCHAR(128) NULL AFTER email,
    ADD COLUMN timezone_preference VARCHAR(64) NULL AFTER display_name,
    ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

CREATE TABLE IF NOT EXISTS app_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(128) NOT NULL,
    setting_value TEXT NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_app_settings_key (setting_key),
    CONSTRAINT fk_app_settings_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
