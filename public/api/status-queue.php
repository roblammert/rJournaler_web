<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Auth/require_auth.php';

use App\Core\Database;

header('Content-Type: application/json; charset=utf-8');

$defaultPerPage = 30;
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = $defaultPerPage;
$offset = ($page - 1) * $perPage;

$completeRetentionHours = max(0.25, (float) ($config['processing']['queue_complete_retention_hours'] ?? 168));
$failedRetentionHours = max(0.25, (float) ($config['processing']['queue_failed_retention_hours'] ?? 168));
$orchestratorLogRetentionHours = max(0.25, (float) ($config['processing']['orchestrator_log_retention_hours'] ?? 8));
$auditLogRetentionDays = max(1, (int) ($config['processing']['audit_log_retention_days'] ?? 90));
$completeRetentionMinutes = max(1, (int) round($completeRetentionHours * 60));
$failedRetentionMinutes = max(1, (int) round($failedRetentionHours * 60));

$resolveOllamaTagsUrl = static function (string $configuredUrl): string {
    $trimmed = trim($configuredUrl);
    if ($trimmed === '') {
        return 'http://127.0.0.1:11434/api/tags';
    }

    $parts = parse_url($trimmed);
    if (!is_array($parts)) {
        return 'http://127.0.0.1:11434/api/tags';
    }

    $scheme = (string) ($parts['scheme'] ?? 'http');
    $host = (string) ($parts['host'] ?? '127.0.0.1');
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

    return sprintf('%s://%s%s/api/tags', $scheme, $host, $port);
};

$checkOllama = static function (string $configuredUrl, float $timeoutSeconds) use ($resolveOllamaTagsUrl): array {
    $url = $resolveOllamaTagsUrl($configuredUrl);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => max(1, (int) ceil($timeoutSeconds)),
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    $httpOk = is_string($statusLine) && preg_match('/\s200\s/', $statusLine) === 1;
    if ($body === false || !$httpOk) {
        return ['status' => 'fail', 'detail' => 'unreachable'];
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['status' => 'warn', 'detail' => 'invalid response'];
    }
    return ['status' => 'pass', 'detail' => 'reachable'];
};

$checkPhpExtensions = static function (array $requiredExtensions): array {
    $checks = [];
    $missing = [];

    foreach ($requiredExtensions as $extensionNameRaw) {
        $extensionName = trim((string) $extensionNameRaw);
        if ($extensionName === '') {
            continue;
        }

        $loaded = extension_loaded($extensionName);
        $checks[] = [
            'name' => $extensionName,
            'status' => $loaded ? 'pass' : 'fail',
            'detail' => $loaded ? 'loaded' : 'missing',
        ];

        if (!$loaded) {
            $missing[] = $extensionName;
        }
    }

    if (count($missing) > 0) {
        return [
            'status' => 'fail',
            'detail' => 'missing: ' . implode(', ', $missing),
            'checks' => $checks,
        ];
    }

    return [
        'status' => 'pass',
        'detail' => 'all required extensions loaded',
        'checks' => $checks,
    ];
};

try {
    $pdo = Database::connection($config['database']);

    $summaryStmt = $pdo->query(
        "
        SELECT
            SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued_jobs,
            SUM(
                CASE
                    WHEN status = 'failed'
                         AND NOT EXISTS (
                             SELECT 1
                             FROM worker_jobs retry
                             WHERE retry.job_type = worker_jobs.job_type
                               AND retry.entry_uid = worker_jobs.entry_uid
                               AND retry.status IN ('queued', 'processing')
                         )
                    THEN 1
                    ELSE 0
                END
            ) AS failed_jobs,
            SUM(CASE WHEN status IN ('queued', 'processing')
                      AND (stage_label LIKE '%LLM%' OR stage_label = 'Retry Scheduled' OR queue_comment LIKE 'Waiting \"Ollama unavailable%')
                THEN 1 ELSE 0 END) AS awaiting_llm,
            AVG(CASE WHEN status IN ('queued', 'processing') THEN TIMESTAMPDIFF(SECOND, submitted_at, UTC_TIMESTAMP()) ELSE NULL END) AS avg_active_age_seconds,
            SUM(CASE WHEN job_type = 'entry_process_pipeline' AND status IN ('queued', 'processing')
                THEN COALESCE(JSON_LENGTH(payload_json, '$.pipeline.remaining'), 0)
                ELSE 0 END) AS total_tasks_remaining
        FROM worker_jobs
        "
    );
    $summaryRow = $summaryStmt->fetch() ?: [];

    $remainingByStage = [
        'meta_group_0' => 0,
        'meta_group_1' => 0,
        'meta_group_2_llm' => 0,
        'meta_group_3_weather' => 0,
    ];
    $legacyLabelToStageId = [
        'Meta Group 0' => 'meta_group_0',
        'Meta Group 1' => 'meta_group_1',
        'Meta Group 2 (LLM)' => 'meta_group_2_llm',
        'Meta Group 3 (Weather)' => 'meta_group_3_weather',
    ];

    $remainingStageStmt = $pdo->query(
        "
        SELECT payload_json
        FROM worker_jobs
        WHERE job_type = 'entry_process_pipeline'
          AND status IN ('queued', 'processing')
        "
    );
    $remainingStageRows = $remainingStageStmt->fetchAll() ?: [];
    foreach ($remainingStageRows as $remainingStageRow) {
        if (!is_array($remainingStageRow)) {
            continue;
        }

        $payloadRaw = $remainingStageRow['payload_json'] ?? null;
        if (!is_string($payloadRaw) || trim($payloadRaw) === '') {
            continue;
        }

        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) {
            continue;
        }

        $pipeline = $payload['pipeline'] ?? null;
        if (!is_array($pipeline)) {
            continue;
        }

        $remainingIds = $pipeline['remaining'] ?? null;
        if (is_array($remainingIds)) {
            foreach ($remainingIds as $stageId) {
                $normalizedStageId = is_string($stageId) ? trim($stageId) : '';
                if ($normalizedStageId !== '' && array_key_exists($normalizedStageId, $remainingByStage)) {
                    $remainingByStage[$normalizedStageId] += 1;
                }
            }
            continue;
        }

        $remainingLabels = $pipeline['remaining_labels'] ?? null;
        if (!is_array($remainingLabels)) {
            continue;
        }

        foreach ($remainingLabels as $label) {
            $normalizedLabel = is_string($label) ? trim($label) : '';
            if ($normalizedLabel === '') {
                continue;
            }
            $stageId = $legacyLabelToStageId[$normalizedLabel] ?? null;
            if (is_string($stageId) && array_key_exists($stageId, $remainingByStage)) {
                $remainingByStage[$stageId] += 1;
            }
        }
    }

    $inProcessMetadataStmt = $pdo->query(
        "
        SELECT COUNT(*) AS in_process_missing_metadata
        FROM journal_entries je
        LEFT JOIN entry_meta_group_0 g0 ON g0.entry_uid = je.entry_uid
        LEFT JOIN entry_meta_group_1 g1 ON g1.entry_uid = je.entry_uid
        LEFT JOIN entry_meta_group_2 g2 ON g2.entry_uid = je.entry_uid
                LEFT JOIN entry_meta_group_3 g3 ON g3.entry_uid = je.entry_uid
        WHERE je.workflow_stage = 'IN_PROCESS'
                    AND (g0.entry_uid IS NULL OR g1.entry_uid IS NULL OR g2.entry_uid IS NULL OR g3.entry_uid IS NULL)
        "
    );
    $inProcessMetadataRow = $inProcessMetadataStmt->fetch() ?: [];

    $llmCoverageStmt = $pdo->query(
        "
        SELECT
            COALESCE(NULLIF(TRIM(g2.llm_model), ''), '__missing__') AS llm_model_bucket,
            COUNT(*) AS entry_count
        FROM journal_entries je
        LEFT JOIN entry_meta_group_2 g2 ON g2.entry_uid = je.entry_uid
        WHERE je.workflow_stage IN ('COMPLETE', 'FINAL')
        GROUP BY llm_model_bucket
        ORDER BY entry_count DESC, llm_model_bucket ASC
        "
    );
    $llmCoverageRows = $llmCoverageStmt->fetchAll() ?: [];
    $completeFinalTotal = 0;
    $completeFinalWithoutLlm = 0;
    $completeFinalLlmModels = [];
    foreach ($llmCoverageRows as $llmCoverageRow) {
        if (!is_array($llmCoverageRow)) {
            continue;
        }

        $bucket = trim((string) ($llmCoverageRow['llm_model_bucket'] ?? ''));
        $count = max(0, (int) ($llmCoverageRow['entry_count'] ?? 0));
        if ($count <= 0) {
            continue;
        }

        $completeFinalTotal += $count;
        if ($bucket === '__missing__') {
            $completeFinalWithoutLlm += $count;
            continue;
        }

        $completeFinalLlmModels[$bucket] = $count;
    }

    $workerStmt = $pdo->query(
        "
        SELECT worker_name, heartbeat_at, status,
               TIMESTAMPDIFF(SECOND, heartbeat_at, UTC_TIMESTAMP()) AS age_seconds
        FROM worker_runs
        WHERE worker_name = 'Optimus'
        ORDER BY heartbeat_at DESC, id DESC
        LIMIT 1
        "
    );
    $workerRow = $workerStmt->fetch() ?: null;
    $workerStatus = 'fail';
    $workerDetail = 'no heartbeat';
    $workerPollSeconds = max(1.0, (float) env('WORKER_POLL_SECONDS', 3));
    $workerHeartbeatThreshold = max(8, (int) ceil($workerPollSeconds * 3));
    $ollamaTimeoutSeconds = max(5.0, (float) ($config['processing']['ollama_timeout_seconds'] ?? 45));
    $autobotHeartbeatThreshold = $workerHeartbeatThreshold;
    $autobotProcessingLockFreshnessSeconds = max((int) ceil($ollamaTimeoutSeconds * 12.0), (int) ceil($workerPollSeconds * 10.0), 180);
    if (is_array($workerRow)) {
        $statusValue = strtolower((string) ($workerRow['status'] ?? ''));
        $ageSeconds = (int) ($workerRow['age_seconds'] ?? 9999);
        if ($statusValue === 'running') {
            if ($ageSeconds <= $workerHeartbeatThreshold) {
                $workerStatus = 'pass';
                $workerDetail = 'active';
            } else {
                $workerStatus = 'fail';
                $workerDetail = 'inactive';
            }
        }
    }

    $activeAutobotsRowsStmt = $pdo->prepare(
        "
        SELECT wr.worker_name, wr.notes, wr.heartbeat_at,
               TIMESTAMPDIFF(SECOND, wr.heartbeat_at, UTC_TIMESTAMP()) AS age_seconds,
               EXISTS(
                   SELECT 1
                   FROM worker_jobs wj
                   WHERE wj.status = 'processing'
                     AND wj.locked_by = wr.worker_name
                                         AND (
                                                wj.locked_at IS NULL
                                                OR wj.locked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :processing_lock_freshness_seconds_1 SECOND)
                                         )
               ) AS has_processing_job
        FROM worker_runs wr
        INNER JOIN (
            SELECT worker_name, MAX(id) AS max_id
            FROM worker_runs
            WHERE worker_name LIKE 'Autobot-%'
            GROUP BY worker_name
        ) latest ON latest.max_id = wr.id
        WHERE wr.worker_name LIKE 'Autobot-%'
          AND (
                EXISTS(
                    SELECT 1
                    FROM worker_jobs wj
                    WHERE wj.status = 'processing'
                      AND wj.locked_by = wr.worker_name
                      AND (
                                                wj.locked_at IS NULL
                                                OR wj.locked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :processing_lock_freshness_seconds_2 SECOND)
                                            )
                )
                OR (wr.status = 'running' AND wr.heartbeat_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :heartbeat_threshold SECOND))
          )
        ORDER BY wr.heartbeat_at DESC, wr.id DESC
        "
    );
    $activeAutobotsRowsStmt->bindValue(':heartbeat_threshold', $autobotHeartbeatThreshold, PDO::PARAM_INT);
    $activeAutobotsRowsStmt->bindValue(':processing_lock_freshness_seconds_1', $autobotProcessingLockFreshnessSeconds, PDO::PARAM_INT);
    $activeAutobotsRowsStmt->bindValue(':processing_lock_freshness_seconds_2', $autobotProcessingLockFreshnessSeconds, PDO::PARAM_INT);
    $activeAutobotsRowsStmt->execute();
    $activeAutobotsRowsRaw = $activeAutobotsRowsStmt->fetchAll() ?: [];

    $drainRequestedStmt = $pdo->query(
        "
        SELECT setting_key
        FROM app_settings
        WHERE setting_key LIKE 'processing.autobot_drain.%'
          AND LOWER(TRIM(setting_value)) IN ('1','true','yes','on')
        "
    );
    $drainRequestedRows = $drainRequestedStmt->fetchAll() ?: [];
    $drainRequested = [];
    foreach ($drainRequestedRows as $drainRow) {
        if (!is_array($drainRow)) {
            continue;
        }
        $key = trim((string) ($drainRow['setting_key'] ?? ''));
        $prefix = 'processing.autobot_drain.';
        if ($key !== '' && str_starts_with($key, $prefix)) {
            $drainRequested[substr($key, strlen($prefix))] = true;
        }
    }

    $extractAutobotStageId = static function (string $workerName, string $notes): string {
        if ($notes !== '' && preg_match('/stage=([a-z0-9_\-]+)/i', $notes, $matches) === 1) {
            return (string) ($matches[1] ?? '');
        }

        if (preg_match('/^Autobot\-(.+)-\d+$/', $workerName, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        if (preg_match('/^Autobot\-(.+)$/', $workerName, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    };

    $activeAutobotsRows = [];
    foreach ($activeAutobotsRowsRaw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $workerName = trim((string) ($row['worker_name'] ?? ''));
        if ($workerName === '') {
            continue;
        }
        $notes = trim((string) ($row['notes'] ?? ''));
        $stageId = $extractAutobotStageId($workerName, $notes);
        $activeAutobotsRows[] = [
            'worker_name' => $workerName,
            'stage_id' => $stageId,
            'age_seconds' => (int) ($row['age_seconds'] ?? 0),
            'heartbeat_at' => (string) ($row['heartbeat_at'] ?? ''),
            'has_processing_job' => ((int) ($row['has_processing_job'] ?? 0)) === 1,
            'drain_requested' => isset($drainRequested[$workerName]),
        ];
    }
    $activeAutobots = count($activeAutobotsRows);

    $staleAutobotsStmt = $pdo->prepare(
        "
        SELECT wr.worker_name, wr.notes, wr.heartbeat_at,
               TIMESTAMPDIFF(SECOND, wr.heartbeat_at, UTC_TIMESTAMP()) AS age_seconds,
               wr.status,
               EXISTS(
                   SELECT 1
                   FROM worker_jobs wj
                   WHERE wj.status = 'processing'
                     AND wj.locked_by = wr.worker_name
                     AND (
                        wj.locked_at IS NULL
                        OR wj.locked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :processing_lock_freshness_seconds_3 SECOND)
                     )
               ) AS has_fresh_processing_job
        FROM worker_runs wr
        INNER JOIN (
            SELECT worker_name, MAX(id) AS max_id
            FROM worker_runs
            WHERE worker_name LIKE 'Autobot-%'
            GROUP BY worker_name
        ) latest ON latest.max_id = wr.id
        WHERE wr.worker_name LIKE 'Autobot-%'
            AND wr.status = 'running'
            AND wr.heartbeat_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :heartbeat_threshold_stale SECOND)
          AND NOT EXISTS(
                SELECT 1
                FROM worker_jobs wj
                WHERE wj.status = 'processing'
                  AND wj.locked_by = wr.worker_name
                  AND (
                    wj.locked_at IS NULL
                    OR wj.locked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :processing_lock_freshness_seconds_4 SECOND)
                  )
          )
        ORDER BY wr.heartbeat_at ASC, wr.id DESC
        "
    );
    $staleAutobotsStmt->bindValue(':heartbeat_threshold_stale', $autobotHeartbeatThreshold, PDO::PARAM_INT);
    $staleAutobotsStmt->bindValue(':processing_lock_freshness_seconds_3', $autobotProcessingLockFreshnessSeconds, PDO::PARAM_INT);
    $staleAutobotsStmt->bindValue(':processing_lock_freshness_seconds_4', $autobotProcessingLockFreshnessSeconds, PDO::PARAM_INT);
    $staleAutobotsStmt->execute();
    $staleAutobotsRowsRaw = $staleAutobotsStmt->fetchAll() ?: [];

    $staleAutobotsRows = [];
    foreach ($staleAutobotsRowsRaw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $workerName = trim((string) ($row['worker_name'] ?? ''));
        if ($workerName === '') {
            continue;
        }
        $notes = trim((string) ($row['notes'] ?? ''));
        $stageId = $extractAutobotStageId($workerName, $notes);
        $staleAutobotsRows[] = [
            'worker_name' => $workerName,
            'stage_id' => $stageId,
            'age_seconds' => (int) ($row['age_seconds'] ?? 0),
            'heartbeat_at' => (string) ($row['heartbeat_at'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
        ];
    }
    $staleAutobots = count($staleAutobotsRows);

    $orphanedAutobotsStmt = $pdo->prepare(
        "
        SELECT COUNT(DISTINCT wj.locked_by) AS orphaned_autobots
        FROM worker_jobs wj
        LEFT JOIN (
            SELECT wr.worker_name, wr.status, wr.heartbeat_at
            FROM worker_runs wr
            INNER JOIN (
                SELECT worker_name, MAX(id) AS max_id
                FROM worker_runs
                WHERE worker_name LIKE 'Autobot-%'
                GROUP BY worker_name
            ) latest ON latest.max_id = wr.id
        ) latest_worker ON latest_worker.worker_name = wj.locked_by
        WHERE wj.status = 'processing'
          AND wj.locked_by LIKE 'Autobot-%'
          AND (
                latest_worker.worker_name IS NULL
                OR latest_worker.status <> 'running'
                OR latest_worker.heartbeat_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :heartbeat_threshold_orphaned SECOND)
          )
        "
    );
    $orphanedAutobotsStmt->bindValue(':heartbeat_threshold_orphaned', $autobotHeartbeatThreshold, PDO::PARAM_INT);
    $orphanedAutobotsStmt->execute();
    $orphanedAutobots = max(0, (int) $orphanedAutobotsStmt->fetchColumn());

    $reconcileMetaStmt = $pdo->query(
        "
        SELECT setting_key, setting_value
        FROM app_settings
        WHERE setting_key IN ('processing.worker_reconcile.last_at', 'processing.worker_reconcile.last_by')
        "
    );
    $reconcileMetaRows = $reconcileMetaStmt->fetchAll() ?: [];
    $reconcileLastAt = null;
    $reconcileLastBy = null;
    foreach ($reconcileMetaRows as $metaRow) {
        if (!is_array($metaRow)) {
            continue;
        }
        $metaKey = trim((string) ($metaRow['setting_key'] ?? ''));
        $metaValue = trim((string) ($metaRow['setting_value'] ?? ''));
        if ($metaKey === 'processing.worker_reconcile.last_at' && $metaValue !== '') {
            $reconcileLastAt = $metaValue;
            continue;
        }
        if ($metaKey === 'processing.worker_reconcile.last_by' && $metaValue !== '') {
            $reconcileLastBy = $metaValue;
        }
    }

    $ollamaHealth = $checkOllama(
        (string) ($config['processing']['ollama_url'] ?? ''),
        (float) ($config['processing']['ollama_timeout_seconds'] ?? 45)
    );
    $ollamaModel = trim((string) ($config['processing']['ollama_model'] ?? ''));
    if ($ollamaModel === '') {
        $ollamaModel = 'not configured';
    }

    $databaseInfoStmt = $pdo->query('SELECT VERSION() AS mysql_version, @@version_comment AS version_comment, DATABASE() AS database_name');
    $databaseInfoRow = $databaseInfoStmt ? ($databaseInfoStmt->fetch() ?: []) : [];
    $mysqlVersion = trim((string) ($databaseInfoRow['mysql_version'] ?? 'unknown'));
    $mysqlVersionComment = trim((string) ($databaseInfoRow['version_comment'] ?? ''));
    $databaseName = trim((string) ($databaseInfoRow['database_name'] ?? 'unknown'));

    $latestMigrationStmt = $pdo->query('SELECT migration_name, applied_at FROM schema_migrations ORDER BY id DESC LIMIT 1');
    $latestMigrationRow = $latestMigrationStmt ? ($latestMigrationStmt->fetch() ?: []) : [];
    $latestMigrationName = trim((string) ($latestMigrationRow['migration_name'] ?? 'none'));
    $latestMigrationAppliedAt = trim((string) ($latestMigrationRow['applied_at'] ?? ''));
    $migrationCountStmt = $pdo->query('SELECT COUNT(*) FROM schema_migrations');
    $migrationCount = $migrationCountStmt ? max(0, (int) $migrationCountStmt->fetchColumn()) : 0;

    $serverSoftwareRaw = trim((string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'));
    $serverSoftwareName = $serverSoftwareRaw;
    $serverSoftwareVersion = 'n/a';
    if (preg_match('/^([^\/\s]+)\/([^\s]+)/', $serverSoftwareRaw, $serverMatches) === 1) {
        $serverSoftwareName = (string) ($serverMatches[1] ?? $serverSoftwareRaw);
        $serverSoftwareVersion = (string) ($serverMatches[2] ?? 'n/a');
    }

    $autobotHealthStatus = 'pass';
    if ($orphanedAutobots > 0) {
        $autobotHealthStatus = 'fail';
    } elseif ($staleAutobots > 0) {
        $autobotHealthStatus = 'warn';
    }
    $phpExtensionHealth = $checkPhpExtensions([
        'pdo',
        'pdo_mysql',
        'mbstring',
        'openssl',
        'zip',
        'json',
    ]);

    $countStmt = $pdo->prepare(
        "
        SELECT COUNT(*)
        FROM worker_jobs
        WHERE status IN ('queued', 'processing')
            OR (status = 'completed' AND completed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :complete_retention_minutes MINUTE))
            OR (status = 'failed' AND completed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :failed_retention_minutes MINUTE))
        "
    );
    $countStmt->bindValue(':complete_retention_minutes', $completeRetentionMinutes, PDO::PARAM_INT);
    $countStmt->bindValue(':failed_retention_minutes', $failedRetentionMinutes, PDO::PARAM_INT);
    $countStmt->execute();
    $totalRows = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $stmt = $pdo->prepare(
        "
        SELECT
            id,
            entry_uid,
            submitter,
            stage_label,
            status,
            priority,
            run_after,
            COALESCE(queue_comment, '') AS queue_comment,
            payload_json,
            submitted_at,
            completed_at,
            TIMESTAMPDIFF(SECOND, submitted_at, COALESCE(completed_at, UTC_TIMESTAMP())) AS age_seconds
        FROM worker_jobs
        WHERE status IN ('queued', 'processing')
            OR (status = 'completed' AND completed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :complete_retention_minutes MINUTE))
            OR (status = 'failed' AND completed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :failed_retention_minutes MINUTE))
        ORDER BY
            CASE
                WHEN status = 'processing' THEN 0
                WHEN status = 'queued' THEN 1
                WHEN status = 'failed' THEN 2
                WHEN status = 'completed' THEN 3
                ELSE 4
            END ASC,
            CASE WHEN status = 'queued' AND run_after <= UTC_TIMESTAMP() THEN 0 ELSE 1 END ASC,
            CASE WHEN status IN ('queued', 'processing') THEN priority ELSE 0 END ASC,
            CASE WHEN status IN ('queued', 'processing') THEN id ELSE 0 END ASC,
            CASE WHEN status IN ('failed', 'completed') THEN COALESCE(completed_at, submitted_at) ELSE submitted_at END DESC,
            id DESC
        LIMIT :limit OFFSET :offset
        "
    );
    $stmt->bindValue(':complete_retention_minutes', $completeRetentionMinutes, PDO::PARAM_INT);
    $stmt->bindValue(':failed_retention_minutes', $failedRetentionMinutes, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $remaining = [];
        $payload = $row['payload_json'] ?? null;
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                $pipeline = $decoded['pipeline'] ?? null;
                if (is_array($pipeline) && isset($pipeline['remaining_labels']) && is_array($pipeline['remaining_labels'])) {
                    foreach ($pipeline['remaining_labels'] as $label) {
                        if (is_string($label) && trim($label) !== '') {
                            $remaining[] = trim($label);
                        }
                    }
                }
            }
        }
        $row['remaining_stages'] = $remaining;
        unset($row['payload_json']);
    }
    unset($row);

    echo json_encode([
        'ok' => true,
        'retention_hours' => [
            'completed' => $completeRetentionHours,
            'failed' => $failedRetentionHours,
            'orchestrator_logs' => $orchestratorLogRetentionHours,
            'audit_log_days' => $auditLogRetentionDays,
        ],
        'summary' => [
            'queued_jobs' => (int) ($summaryRow['queued_jobs'] ?? 0),
            'failed_jobs' => (int) ($summaryRow['failed_jobs'] ?? 0),
            'awaiting_llm' => (int) ($summaryRow['awaiting_llm'] ?? 0),
            'avg_active_age_seconds' => isset($summaryRow['avg_active_age_seconds']) ? (float) $summaryRow['avg_active_age_seconds'] : 0,
            'total_tasks_remaining' => (int) ($summaryRow['total_tasks_remaining'] ?? 0),
            'remaining_by_stage' => $remainingByStage,
            'active_autobots' => $activeAutobots,
            'active_autobot_workers' => $activeAutobotsRows,
            'stale_autobots' => $staleAutobots,
            'orphaned_autobots' => $orphanedAutobots,
            'stale_autobot_workers' => $staleAutobotsRows,
            'in_process_missing_metadata' => (int) ($inProcessMetadataRow['in_process_missing_metadata'] ?? 0),
            'complete_final_total' => $completeFinalTotal,
            'complete_final_without_llm' => $completeFinalWithoutLlm,
            'complete_final_llm_models' => $completeFinalLlmModels,
            'worker_reconcile_last_at' => $reconcileLastAt,
            'worker_reconcile_last_by' => $reconcileLastBy,
        ],
        'health' => [
            'database' => ['status' => 'pass', 'detail' => 'connected'],
            'ollama' => $ollamaHealth,
            'worker' => ['status' => $workerStatus, 'detail' => $workerDetail],
            'php_extensions' => $phpExtensionHealth,
        ],
        'status_cards' => [
            'database' => [
                'connection' => ['status' => 'pass', 'detail' => 'connected'],
                'mysql_version' => $mysqlVersion,
                'mysql_version_comment' => $mysqlVersionComment,
                'application_db_version' => $latestMigrationName,
                'application_db_version_applied_at' => $latestMigrationAppliedAt,
                'schema_migrations_applied' => $migrationCount,
                'database_name' => $databaseName,
            ],
            'ollama' => [
                'service' => $ollamaHealth,
                'selected_model' => $ollamaModel,
                'configured_url' => (string) ($config['processing']['ollama_url'] ?? ''),
                'timeout_seconds' => (float) ($config['processing']['ollama_timeout_seconds'] ?? 45),
                'retry_seconds' => (int) ($config['processing']['ollama_retry_seconds'] ?? 120),
            ],
            'optimus' => [
                'service' => ['status' => $workerStatus, 'detail' => $workerDetail],
                'autobot_health' => [
                    'status' => $autobotHealthStatus,
                    'running' => $activeAutobots,
                    'stale' => $staleAutobots,
                    'orphaned' => $orphanedAutobots,
                ],
                'heartbeat_threshold_seconds' => $workerHeartbeatThreshold,
            ],
            'web_engine' => [
                'php_version' => PHP_VERSION,
                'php_sapi' => PHP_SAPI,
                'server_software_name' => $serverSoftwareName,
                'server_software_version' => $serverSoftwareVersion,
                'server_software_raw' => $serverSoftwareRaw,
                'memory_limit' => (string) ini_get('memory_limit'),
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'post_max_size' => (string) ini_get('post_max_size'),
            ],
        ],
        'rows' => $rows,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
        ],
        'generated_at' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to load queue status',
    ]);
}
