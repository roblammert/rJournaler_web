<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Auth/require_auth.php';
require_once dirname(__DIR__, 2) . '/app/Auth/require_admin.php';

use App\Auth\Auth;
use App\Core\Database;
use App\Security\Csrf;

header('Content-Type: application/json; charset=utf-8');

$userId = Auth::userId();
if (!is_int($userId)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$raw = file_get_contents('php://input');
$data = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

$csrfToken = isset($data['_csrf']) && is_string($data['_csrf']) ? $data['_csrf'] : null;
if (!Csrf::validate($csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

try {
    $pdo = Database::connection($config['database']);

    $actorStmt = $pdo->prepare('SELECT username, display_name FROM users WHERE id = :id LIMIT 1');
    $actorStmt->execute(['id' => $userId]);
    $actorRow = $actorStmt->fetch();
    $actorName = '';
    if (is_array($actorRow)) {
        $displayName = trim((string) ($actorRow['display_name'] ?? ''));
        $username = trim((string) ($actorRow['username'] ?? ''));
        $actorName = $displayName !== '' ? $displayName : $username;
    }
    if ($actorName === '') {
        $actorName = 'admin#' . $userId;
    }

    $workerPollSeconds = max(1.0, (float) env('WORKER_POLL_SECONDS', 3));
    $workerHeartbeatThreshold = max(8, (int) ceil($workerPollSeconds * 3));
    $ollamaTimeoutSeconds = max(1.0, (float) ($config['processing']['ollama_timeout_seconds'] ?? 45));
    $autobotHeartbeatThreshold = max($workerHeartbeatThreshold, (int) ceil($ollamaTimeoutSeconds) + 30, 120);

    $staleLockMinutes = 10;

    $staleStmt = $pdo->prepare(
        "
        UPDATE worker_jobs
        SET status = 'queued',
            stage_label = 'Queued',
            queue_comment = 'Reconciled stale processing lock',
            run_after = UTC_TIMESTAMP(),
            locked_at = NULL,
            locked_by = NULL,
            error_message = COALESCE(error_message, 'Reconciled stale processing lock')
        WHERE status = 'processing'
          AND locked_at IS NOT NULL
          AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :stale_lock_minutes MINUTE)
        "
    );
    $staleStmt->bindValue(':stale_lock_minutes', $staleLockMinutes, PDO::PARAM_INT);
    $staleStmt->execute();
    $requeuedStale = $staleStmt->rowCount();

    $orphanStmt = $pdo->prepare(
        "
        UPDATE worker_jobs wj
        SET wj.status = 'queued',
            wj.stage_label = 'Queued',
            wj.queue_comment = 'Reconciled orphaned processing lock',
            wj.run_after = UTC_TIMESTAMP(),
            wj.locked_at = NULL,
            wj.locked_by = NULL,
            wj.error_message = COALESCE(wj.error_message, 'Reconciled orphaned processing lock')
        WHERE wj.status = 'processing'
          AND wj.locked_by IS NOT NULL
          AND wj.locked_by LIKE 'Autobot-%'
          AND NOT EXISTS (
                SELECT 1
                FROM worker_runs wr
                INNER JOIN (
                    SELECT worker_name, MAX(id) AS max_id
                    FROM worker_runs
                    WHERE worker_name LIKE 'Autobot-%'
                    GROUP BY worker_name
                ) latest ON latest.max_id = wr.id
                WHERE wr.worker_name = wj.locked_by
                  AND wr.status = 'running'
                  AND wr.heartbeat_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :heartbeat_threshold SECOND)
          )
        "
    );
    $orphanStmt->bindValue(':heartbeat_threshold', $autobotHeartbeatThreshold, PDO::PARAM_INT);
    $orphanStmt->execute();
    $requeuedOrphan = $orphanStmt->rowCount();

    $stopRunsStmt = $pdo->prepare(
        "
        UPDATE worker_runs wr
        INNER JOIN (
            SELECT worker_name, MAX(id) AS max_id
            FROM worker_runs
            WHERE worker_name LIKE 'Autobot-%'
            GROUP BY worker_name
        ) latest ON latest.max_id = wr.id
        SET wr.status = 'stopped',
            wr.notes = 'reconciled stale heartbeat'
        WHERE wr.status = 'running'
          AND wr.heartbeat_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :heartbeat_threshold SECOND)
        "
    );
    $stopRunsStmt->bindValue(':heartbeat_threshold', $autobotHeartbeatThreshold, PDO::PARAM_INT);
    $stopRunsStmt->execute();
    $stoppedWorkers = $stopRunsStmt->rowCount();

    $auditStmt = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, updated_by_user_id, updated_at)
         VALUES (:setting_key, :setting_value, :updated_by_user_id, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_by_user_id = VALUES(updated_by_user_id),
            updated_at = UTC_TIMESTAMP()'
    );
    $auditStmt->execute([
        'setting_key' => 'processing.worker_reconcile.last_at',
        'setting_value' => gmdate('c'),
        'updated_by_user_id' => $userId,
    ]);
    $auditStmt->execute([
        'setting_key' => 'processing.worker_reconcile.last_by',
        'setting_value' => $actorName,
        'updated_by_user_id' => $userId,
    ]);

    echo json_encode([
        'ok' => true,
        'requeued_stale_locks' => $requeuedStale,
        'requeued_orphan_locks' => $requeuedOrphan,
        'stopped_stale_workers' => $stoppedWorkers,
        'heartbeat_threshold_seconds' => $autobotHeartbeatThreshold,
        'last_reconcile_at' => gmdate('c'),
        'last_reconcile_by' => $actorName,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to reconcile worker locks',
    ]);
}
