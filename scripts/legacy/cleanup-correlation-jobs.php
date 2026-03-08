<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';

use App\Core\Database;

$apply = in_array('--apply', $argv, true);

$where = "job_type = 'correlation_period_build'\n    OR LOWER(COALESCE(stage_label, '')) LIKE '%correlation%'\n    OR LOWER(COALESCE(queue_comment, '')) LIKE '%correlation%'";

try {
    $pdo = Database::connection($config['database']);

    $countStmt = $pdo->query(
        "SELECT\n            COUNT(*) AS total,\n            SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued_count,\n            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing_count,\n            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,\n            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count\n         FROM worker_jobs\n         WHERE {$where}"
    );

    $counts = $countStmt ? $countStmt->fetch(PDO::FETCH_ASSOC) : null;
    if (!is_array($counts)) {
        throw new RuntimeException('Unable to read correlation job counts.');
    }

    fwrite(STDOUT, "Correlation job matches in worker_jobs:\n");
    fwrite(STDOUT, '- total: ' . (int) ($counts['total'] ?? 0) . "\n");
    fwrite(STDOUT, '- queued: ' . (int) ($counts['queued_count'] ?? 0) . "\n");
    fwrite(STDOUT, '- processing: ' . (int) ($counts['processing_count'] ?? 0) . "\n");
    fwrite(STDOUT, '- failed: ' . (int) ($counts['failed_count'] ?? 0) . "\n");
    fwrite(STDOUT, '- completed: ' . (int) ($counts['completed_count'] ?? 0) . "\n");

    if (!$apply) {
        fwrite(STDOUT, "Dry run only. Re-run with --apply to delete these rows.\n");
        exit(0);
    }

    $deleteStmt = $pdo->exec("DELETE FROM worker_jobs WHERE {$where}");
    $deleted = is_int($deleteStmt) ? $deleteStmt : 0;
    fwrite(STDOUT, 'Deleted rows: ' . $deleted . "\n");
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Cleanup failed: ' . $throwable->getMessage() . "\n");
    exit(1);
}
