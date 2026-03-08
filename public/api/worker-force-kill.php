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

$target = isset($data['target']) && is_string($data['target']) ? trim($data['target']) : '';
if (!in_array($target, ['autobots', 'stale_autobots', 'orphaned_autobots', 'stale_autobot_worker', 'optimus'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid force-kill target']);
    exit;
}

$requestedWorkerName = isset($data['worker_name']) && is_string($data['worker_name']) ? trim($data['worker_name']) : '';

/**
 * Run a shell command and return output plus exit code.
 *
 * @return array{exit_code:int,output:string}
 */
function run_command(string $command): array
{
    $outputLines = [];
    $exitCode = 1;
    @exec($command . ' 2>&1', $outputLines, $exitCode);
    return [
        'exit_code' => (int) $exitCode,
        'output' => trim(implode("\n", $outputLines)),
    ];
}

function is_windows_os(): bool
{
    return strtoupper((string) PHP_OS_FAMILY) === 'WINDOWS';
}

/**
 * @return list<int>
 */
function parse_pid_lines(string $rawOutput): array
{
    $lines = preg_split('/\R+/', $rawOutput) ?: [];
    $pids = [];
    foreach ($lines as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed !== '' && preg_match('/^\d+$/', $trimmed) === 1) {
            $pid = (int) $trimmed;
            if ($pid > 0) {
                $pids[$pid] = true;
            }
        }
    }
    $values = array_keys($pids);
    sort($values, SORT_NUMERIC);
    return $values;
}

/**
 * @param list<int> $pids
 * @return array{requested:int,killed:int,results:list<array{pid:int,ok:bool,output:string}>}
 */
function force_kill_pids(array $pids): array
{
    $results = [];
    $killed = 0;

    foreach ($pids as $pid) {
        $cmd = is_windows_os()
            ? ('taskkill /PID ' . (int) $pid . ' /F')
            : ('kill -9 ' . (int) $pid);
        $run = run_command($cmd);
        $ok = $run['exit_code'] === 0;
        if ($ok) {
            $killed += 1;
        }

        $results[] = [
            'pid' => (int) $pid,
            'ok' => $ok,
            'output' => $run['output'],
        ];
    }

    return [
        'requested' => count($pids),
        'killed' => $killed,
        'results' => $results,
    ];
}

/**
 * @return list<int>
 */
function fetch_optimus_pids(): array
{
    if (is_windows_os()) {
        $psScript = <<<'PS'
Get-CimInstance Win32_Process |
    Where-Object {
        ($_.CommandLine -match 'python[\\/]+worker[\\/]+main\.py') -and
        ($_.CommandLine -notmatch '--multiprocessing-fork')
    } |
    Select-Object -ExpandProperty ProcessId
PS;

        $quoted = escapeshellarg($psScript);
        $lookup = run_command('powershell -NoProfile -ExecutionPolicy Bypass -Command ' . $quoted);
        if ($lookup['exit_code'] !== 0) {
            throw new RuntimeException('Unable to inspect running Optimus process');
        }

        return parse_pid_lines($lookup['output']);
    }

    $lookup = run_command("pgrep -f 'python(.*/)?worker/main.py'");
    if ($lookup['exit_code'] !== 0) {
        return [];
    }

    return parse_pid_lines($lookup['output']);
}

/**
 * @return list<string>
 */
function get_target_autobot_workers(PDO $pdo, string $target, int $heartbeatThreshold, int $processingLockFreshnessSeconds, string $requestedWorkerName): array
{
    if ($target === 'stale_autobot_worker') {
        if ($requestedWorkerName === '' || preg_match('/^Autobot\-[A-Za-z0-9_\-]+\-\d+$/', $requestedWorkerName) !== 1) {
            return [];
        }

        $stmt = $pdo->prepare(
            "
            SELECT wr.worker_name
            FROM worker_runs wr
            INNER JOIN (
                SELECT worker_name, MAX(id) AS max_id
                FROM worker_runs
                WHERE worker_name LIKE 'Autobot-%'
                GROUP BY worker_name
            ) latest ON latest.max_id = wr.id
            WHERE wr.worker_name = :worker_name
              AND wr.status = 'running'
              AND wr.heartbeat_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :heartbeat_threshold SECOND)
              AND NOT EXISTS(
                    SELECT 1
                    FROM worker_jobs wj
                    WHERE wj.status = 'processing'
                      AND wj.locked_by = wr.worker_name
                      AND (
                            wj.locked_at IS NULL
                            OR wj.locked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :processing_lock_freshness_seconds SECOND)
                      )
              )
            "
        );
        $stmt->bindValue(':worker_name', $requestedWorkerName, PDO::PARAM_STR);
        $stmt->bindValue(':heartbeat_threshold', $heartbeatThreshold, PDO::PARAM_INT);
        $stmt->bindValue(':processing_lock_freshness_seconds', $processingLockFreshnessSeconds, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        return array_values(array_filter(array_map(static function ($row): string {
            return is_array($row) ? trim((string) ($row['worker_name'] ?? '')) : '';
        }, $rows), static fn(string $name): bool => $name !== ''));
    }

    if ($target === 'autobots') {
        $stmt = $pdo->prepare(
            "
            SELECT wr.worker_name
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
                                OR wj.locked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :processing_lock_freshness_seconds_active SECOND)
                          )
                    )
                    OR (wr.status = 'running' AND wr.heartbeat_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :heartbeat_threshold_active SECOND))
              )
            "
        );
        $stmt->bindValue(':heartbeat_threshold_active', $heartbeatThreshold, PDO::PARAM_INT);
        $stmt->bindValue(':processing_lock_freshness_seconds_active', $processingLockFreshnessSeconds, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        return array_values(array_filter(array_map(static function ($row): string {
            return is_array($row) ? trim((string) ($row['worker_name'] ?? '')) : '';
        }, $rows), static fn(string $name): bool => $name !== ''));
    }

    if ($target === 'orphaned_autobots') {
        $stmt = $pdo->prepare(
            "
            SELECT DISTINCT wj.locked_by AS worker_name
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
        $stmt->bindValue(':heartbeat_threshold_orphaned', $heartbeatThreshold, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        return array_values(array_filter(array_map(static function ($row): string {
            return is_array($row) ? trim((string) ($row['worker_name'] ?? '')) : '';
        }, $rows), static fn(string $name): bool => $name !== ''));
    }

    $stmt = $pdo->prepare(
        "
        SELECT wr.worker_name
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
                        OR wj.locked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :processing_lock_freshness_seconds_stale SECOND)
                  )
          )
        "
    );
    $stmt->bindValue(':heartbeat_threshold_stale', $heartbeatThreshold, PDO::PARAM_INT);
    $stmt->bindValue(':processing_lock_freshness_seconds_stale', $processingLockFreshnessSeconds, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];
    return array_values(array_filter(array_map(static function ($row): string {
        return is_array($row) ? trim((string) ($row['worker_name'] ?? '')) : '';
    }, $rows), static fn(string $name): bool => $name !== ''));
}

try {
    $pdo = Database::connection($config['database']);
    $workerPollSeconds = max(1.0, (float) env('WORKER_POLL_SECONDS', 3));
    $workerHeartbeatThreshold = max(8, (int) ceil($workerPollSeconds * 3));
    $ollamaTimeoutSeconds = max(5.0, (float) ($config['processing']['ollama_timeout_seconds'] ?? 45));
    $autobotProcessingLockFreshnessSeconds = max((int) ceil($ollamaTimeoutSeconds + 30.0), (int) ceil($workerPollSeconds * 10.0), 20);

    if ($target === 'autobots' || $target === 'stale_autobots' || $target === 'orphaned_autobots' || $target === 'stale_autobot_worker') {
        $workerNames = get_target_autobot_workers(
            $pdo,
            $target,
            $workerHeartbeatThreshold,
            $autobotProcessingLockFreshnessSeconds,
            $requestedWorkerName
        );
        if ($target === 'stale_autobot_worker' && count($workerNames) === 0) {
            http_response_code(404);
            echo json_encode([
                'ok' => false,
                'error' => 'Requested stale autobot was not found or is no longer stale',
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $pids = [];
        foreach ($workerNames as $name) {
            if (preg_match('/-(\d+)$/', $name, $matches) === 1) {
                $pid = (int) ($matches[1] ?? 0);
                if ($pid > 0) {
                    $pids[$pid] = true;
                }
            }
        }

        $pidList = array_keys($pids);
        sort($pidList, SORT_NUMERIC);
        $killReport = force_kill_pids($pidList);

        $requeued = 0;
        $stoppedRows = 0;
        if (count($workerNames) > 0) {
            $placeholders = implode(',', array_fill(0, count($workerNames), '?'));

            $requeueSql =
                "UPDATE worker_jobs
                 SET status = 'queued',
                     stage_label = 'Queued',
                     queue_comment = 'Force-killed autobot; requeued',
                     run_after = UTC_TIMESTAMP(),
                     locked_at = NULL,
                     locked_by = NULL,
                     error_message = COALESCE(error_message, 'Force-killed autobot; requeued')
                 WHERE status = 'processing'
                   AND locked_by IN ($placeholders)";
            $requeueStmt = $pdo->prepare($requeueSql);
            $requeueStmt->execute($workerNames);
            $requeued = (int) $requeueStmt->rowCount();

            $stopSql =
                "UPDATE worker_runs
                 SET status = 'stopped',
                     notes = LEFT(CONCAT('force-killed by admin user ', ?, ' | ', COALESCE(notes, '')), 240)
                 WHERE worker_name IN ($placeholders)
                   AND status = 'running'";
            $stopStmt = $pdo->prepare($stopSql);
            $stopParams = array_merge([(string) $userId], $workerNames);
            $stopStmt->execute($stopParams);
            $stoppedRows = (int) $stopStmt->rowCount();
        }

        echo json_encode([
            'ok' => true,
            'target' => $target,
            'worker_names' => $workerNames,
            'kill' => $killReport,
            'requeued_processing_jobs' => $requeued,
            'worker_rows_marked_stopped' => $stoppedRows,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $optimusPids = fetch_optimus_pids();
    $killReport = force_kill_pids($optimusPids);

    $optimusRowUpdate = $pdo->prepare(
        "
        UPDATE worker_runs
        SET status = 'stopped',
            notes = LEFT(CONCAT('force-killed by admin user ', :user_id, ' | ', COALESCE(notes, '')), 240)
        WHERE worker_name = 'Optimus'
          AND status = 'running'
        "
    );
    $optimusRowUpdate->execute(['user_id' => (string) $userId]);

    echo json_encode([
        'ok' => true,
        'target' => 'optimus',
        'kill' => $killReport,
        'worker_rows_marked_stopped' => (int) $optimusRowUpdate->rowCount(),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to force-kill worker process(es)',
    ]);
}
