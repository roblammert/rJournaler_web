-- Convert entry relational tables to use entry_uid as primary entry reference.

ALTER TABLE entry_tags
    ADD COLUMN entry_uid VARCHAR(64) NULL AFTER id;

UPDATE entry_tags et
INNER JOIN journal_entries je ON je.id = et.entry_id
SET et.entry_uid = je.entry_uid
WHERE et.entry_uid IS NULL OR et.entry_uid = '';

ALTER TABLE entry_tags
    MODIFY COLUMN entry_uid VARCHAR(64) NOT NULL;

ALTER TABLE entry_tags
    ADD CONSTRAINT fk_entry_tags_entry_uid FOREIGN KEY (entry_uid) REFERENCES journal_entries(entry_uid) ON DELETE CASCADE;

ALTER TABLE entry_tags
    ADD CONSTRAINT uq_entry_tag_uid UNIQUE (entry_uid, tag_name);

ALTER TABLE entry_tags
    DROP FOREIGN KEY fk_entry_tags_entry;

ALTER TABLE entry_tags
    DROP INDEX uq_entry_tag;

ALTER TABLE entry_tags
    DROP COLUMN entry_id;

ALTER TABLE entry_metrics
    ADD COLUMN entry_uid VARCHAR(64) NULL AFTER id;

UPDATE entry_metrics em
INNER JOIN journal_entries je ON je.id = em.entry_id
SET em.entry_uid = je.entry_uid
WHERE em.entry_uid IS NULL OR em.entry_uid = '';

ALTER TABLE entry_metrics
    MODIFY COLUMN entry_uid VARCHAR(64) NOT NULL;

ALTER TABLE entry_metrics
    ADD CONSTRAINT fk_entry_metrics_entry_uid FOREIGN KEY (entry_uid) REFERENCES journal_entries(entry_uid) ON DELETE CASCADE;

ALTER TABLE entry_metrics
    ADD CONSTRAINT uq_entry_metrics_entry_uid UNIQUE (entry_uid);

ALTER TABLE entry_metrics
    DROP FOREIGN KEY fk_entry_metrics_entry;

ALTER TABLE entry_metrics
    DROP INDEX uq_entry_metrics_entry;

ALTER TABLE entry_metrics
    DROP COLUMN entry_id;
