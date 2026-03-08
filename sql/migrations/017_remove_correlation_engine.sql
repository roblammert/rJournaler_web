-- Remove correlation engine schema objects.

DROP TABLE IF EXISTS correlation_summaries;
DROP TABLE IF EXISTS correlation_graph_panels;
DROP TABLE IF EXISTS correlation_custom_definitions;
DROP TABLE IF EXISTS correlation_period_runs;

SET @has_idx_worker_jobs_correlation_queue := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'worker_jobs'
      AND INDEX_NAME = 'idx_worker_jobs_correlation_queue'
);
SET @drop_idx_worker_jobs_correlation_queue_sql := IF(
    @has_idx_worker_jobs_correlation_queue > 0,
    'DROP INDEX idx_worker_jobs_correlation_queue ON worker_jobs',
    'SELECT 1'
);
PREPARE stmt_drop_correlation_idx FROM @drop_idx_worker_jobs_correlation_queue_sql;
EXECUTE stmt_drop_correlation_idx;
DEALLOCATE PREPARE stmt_drop_correlation_idx;
