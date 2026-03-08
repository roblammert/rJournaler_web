-- Persist per-user UI theme preference for interface styling.

ALTER TABLE users
    ADD COLUMN interface_theme VARCHAR(16) NOT NULL DEFAULT 'neutral' AFTER timezone_preference;
