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

function launch_optimus_process(string $projectRoot): bool
{
    $workerScript = $projectRoot . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'worker' . DIRECTORY_SEPARATOR . 'main.py';
    if (!is_file($workerScript)) {
        return false;
    }

    $venvPythonWindows = $projectRoot . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
    $venvPythonLinux = $projectRoot . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python';
    $pythonBinary = is_file($venvPythonWindows)
        ? $venvPythonWindows
        : (is_file($venvPythonLinux) ? $venvPythonLinux : (is_windows_os() ? 'python' : 'python3'));

    $args = [
        escapeshellarg($pythonBinary),
        escapeshellarg($workerScript),
    ];

    if (is_windows_os()) {
        $command = 'cmd /c start "" /B ' . implode(' ', $args) . ' >NUL 2>&1';
        $handle = @popen($command, 'r');
        if (is_resource($handle)) {
            pclose($handle);
            return true;
        }
        return false;
    }

    $command = implode(' ', $args) . ' >/dev/null 2>&1 &';
    $handle = @popen($command, 'r');
    if (is_resource($handle)) {
        pclose($handle);
        return true;
    }

    return false;
}

try {
    $pdo = Database::connection($config['database']);
    $projectRoot = dirname(__DIR__, 2);

    $optimusPids = fetch_optimus_pids();
    $killReport = force_kill_pids($optimusPids);

    $optimusRowUpdate = $pdo->prepare(
        "
        UPDATE worker_runs
        SET status = 'stopped',
            notes = LEFT(CONCAT('force-restarted by admin user ', :user_id, ' | ', COALESCE(notes, '')), 240)
        WHERE worker_name = 'Optimus'
          AND status = 'running'
        "
    );
    $optimusRowUpdate->execute(['user_id' => (string) $userId]);

    $startRequested = launch_optimus_process($projectRoot);

    echo json_encode([
        'ok' => true,
        'target' => 'optimus',
        'kill' => $killReport,
        'start_requested' => $startRequested,
        'worker_rows_marked_stopped' => (int) $optimusRowUpdate->rowCount(),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to force restart Optimus',
    ]);
}
