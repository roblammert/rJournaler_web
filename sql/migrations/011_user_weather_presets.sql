-- Persist per-user weather presets and selected weather source.

ALTER TABLE users
    ADD COLUMN weather_presets_json JSON NULL AFTER timezone_preference,
    ADD COLUMN weather_selected_key VARCHAR(128) NULL AFTER weather_presets_json;
