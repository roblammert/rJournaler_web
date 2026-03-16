-- Migration: Add editor_settings_json to users table
ALTER TABLE users
    ADD COLUMN editor_settings_json JSON NULL AFTER interface_theme;