-- Allow import batches to store UID version code specified by user.

ALTER TABLE import_batches
    ADD COLUMN uid_version_digits CHAR(7) NULL AFTER source_name;
