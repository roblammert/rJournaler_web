-- Store Meta Group 1 read time as decimal minutes (one decimal place).

ALTER TABLE entry_meta_group_1
    MODIFY COLUMN reading_time_minutes DECIMAL(5,1) NOT NULL DEFAULT 0.0;
