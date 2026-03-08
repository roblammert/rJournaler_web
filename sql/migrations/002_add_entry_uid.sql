-- Add custom cross-system entry UID to journal_entries.
-- Format: YYYYMMDDHHMMSS-rjournaler-W010000-xxxxxx

ALTER TABLE journal_entries
    ADD COLUMN entry_uid VARCHAR(64) NULL AFTER id;

UPDATE journal_entries
SET entry_uid = CONCAT(
    DATE_FORMAT(COALESCE(updated_at, created_at, UTC_TIMESTAMP()), '%Y%m%d%H%i%s'),
    '-rjournaler-W010000-',
    LOWER(SUBSTRING(REPLACE(UUID(), '-', ''), 1, 6))
)
WHERE entry_uid IS NULL OR entry_uid = '';

ALTER TABLE journal_entries
    MODIFY COLUMN entry_uid VARCHAR(64) NOT NULL;

ALTER TABLE journal_entries
    ADD CONSTRAINT uq_journal_entries_entry_uid UNIQUE (entry_uid);
