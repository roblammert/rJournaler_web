ALTER TABLE orchestrator_logs
    ADD COLUMN is_old TINYINT(1) NOT NULL DEFAULT 0 AFTER created_at,
    ADD INDEX idx_orchestrator_logs_is_old_id (is_old, id);
