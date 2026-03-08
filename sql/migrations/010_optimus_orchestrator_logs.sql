-- Orchestrator/Autobot observability logs for admin log viewer.

CREATE TABLE IF NOT EXISTS orchestrator_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level VARCHAR(16) NOT NULL,
    source VARCHAR(64) NOT NULL,
    message VARCHAR(500) NOT NULL,
    context_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_orchestrator_logs_created_at (created_at),
    INDEX idx_orchestrator_logs_level_created_at (level, created_at),
    INDEX idx_orchestrator_logs_source_created_at (source, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
