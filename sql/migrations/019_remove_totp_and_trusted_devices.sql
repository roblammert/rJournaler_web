-- Migration to remove TOTP and trusted device features
-- Removes trusted_devices table and totp_secret_encrypted column from users

ALTER TABLE users
  DROP COLUMN totp_secret_encrypted;

DROP TABLE IF EXISTS trusted_devices;
