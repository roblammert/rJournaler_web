-- Add an index that keeps audit_log retention deletes fast as the table grows.

SET @has_idx := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'audit_log'
      AND index_name = 'idx_audit_log_created_at'
);

SET @ddl := IF(
    @has_idx = 0,
    'ALTER TABLE audit_log ADD INDEX idx_audit_log_created_at (created_at)',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
