-- Add per-entry weather location snapshot and Meta Group 3 weather metadata.

ALTER TABLE journal_entries
    ADD COLUMN weather_location_key VARCHAR(128) NULL AFTER entry_date,
    ADD COLUMN weather_location_json JSON NULL AFTER weather_location_key;

UPDATE journal_entries
SET weather_location_key = 'new_richmond_wi',
    weather_location_json = JSON_OBJECT(
        'key', 'new_richmond_wi',
        'label', 'New Richmond, WI, US',
        'city', 'New Richmond',
        'state', 'WI',
        'zip', '54017',
        'country', 'US'
    )
WHERE weather_location_json IS NULL;

CREATE TABLE IF NOT EXISTS entry_meta_group_3 (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_uid VARCHAR(64) NOT NULL,
    source_provider VARCHAR(32) NOT NULL DEFAULT 'NOAA',
    location_label VARCHAR(255) NOT NULL,
    location_city VARCHAR(128) NOT NULL,
    location_state VARCHAR(64) NOT NULL,
    location_zip VARCHAR(32) NOT NULL,
    location_country VARCHAR(8) NOT NULL,
    latitude DECIMAL(9,6) NULL,
    longitude DECIMAL(9,6) NULL,
    observed_at VARCHAR(64) NULL,
    current_summary VARCHAR(255) NULL,
    current_temperature_f DECIMAL(6,2) NULL,
    current_feels_like_f DECIMAL(6,2) NULL,
    current_humidity_percent DECIMAL(6,2) NULL,
    current_wind_speed_mph DECIMAL(6,2) NULL,
    forecast_name VARCHAR(128) NULL,
    forecast_short VARCHAR(255) NULL,
    map_url VARCHAR(500) NULL,
    weather_json JSON NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_entry_meta_group_3_uid FOREIGN KEY (entry_uid) REFERENCES journal_entries(entry_uid) ON DELETE CASCADE,
    UNIQUE KEY uq_entry_meta_group_3_uid (entry_uid),
    INDEX idx_entry_meta_group_3_location (location_city, location_state, location_country)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
