-- Ensure uid_version_digits supports full app version code values like T032602.

ALTER TABLE import_batches
    MODIFY COLUMN uid_version_digits CHAR(7) NULL;
