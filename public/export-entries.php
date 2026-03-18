<?php
declare(strict_types=1);


require_once dirname(__DIR__) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__) . '/app/Auth/require_auth.php';

use App\Auth\Auth;
use App\Core\Database;
use App\Entry\EntryRepository;

$error = null;
$userId = Auth::userId();
if (!is_int($userId)) {
    header('Location: /login.php');
    exit;
}

function getEntriesForPeriod(PDO $pdo, int $userId, string $startDate, string $endDate): array {
    $stmt = $pdo->prepare('SELECT entry_date, title, content_raw, created_at FROM journal_entries WHERE user_id = :user_id AND entry_date >= :start_date AND entry_date <= :end_date ORDER BY entry_date ASC, created_at ASC');
    $stmt->execute([
        'user_id' => $userId,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);
    return $stmt->fetchAll() ?: [];
}

function formatMarkdownFile(string $year, string $username, string $month, array $entries): string {
    $monthName = date('F', strtotime($year . '-' . $month . '-01'));
    $lines = [];
    $lines[] = "# $year - $username Entries";
    $lines[] = "## $monthName";
    $currentDay = '';
    foreach ($entries as $entry) {
        $date = $entry['entry_date'];
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        $dayStr = $dt ? $dt->format('Y-m-d | l') : $date;
        if ($currentDay !== $date) {
            $lines[] = "### $dayStr";
            $currentDay = $date;
        }
        $time = '';
        if (isset($entry['created_at'])) {
            $dtTime = DateTime::createFromFormat('Y-m-d H:i:s', $entry['created_at']);
            if ($dtTime) {
                $time = $dtTime->format('g:i A');
            }
        }
 //       if ($time) {
 //           $lines[] = "#### $time";
 //       }
        $lines[] = trim((string)($entry['content_raw'] ?? ''));
        $lines[] = '';
    }
    return implode("\n", $lines);
}

function sendFileDownload(string $filename, string $content, string $mime = 'text/markdown') {
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

function sendZipDownload(string $filename, array $files) {
    $tmp = tempnam(sys_get_temp_dir(), 'exportzip');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE);
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    if (!isset($username) || $username === null || $username === '') {
        $username = 'User';
    }
    $exportType = $_POST['export_type'] ?? 'month';
    $now = new DateTimeImmutable('now');
    $year = $now->format('Y');
    $month = $now->format('m');
    $startDate = $endDate = '';
    $files = [];
    $filename = '';
    $valid = true;
    // Validate and determine date range(s)
    switch ($exportType) {
        case 'day':
            $date = $_POST['date'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $error = 'Invalid date.'; $valid = false; break; }
            $startDate = $endDate = $date;
            $filename = $date . '.md';
            break;
        case 'week':
            $week = $_POST['week'] ?? '';
            if (!preg_match('/^\d{4}-W\d{2}$/', $week)) { $error = 'Invalid week.'; $valid = false; break; }
            $dt = DateTime::createFromFormat('o-\WW', $week);
            if (!$dt) { $error = 'Invalid week.'; $valid = false; break; }
            $startDate = $dt->format('Y-m-d');
            $endDate = $dt->modify('+6 days')->format('Y-m-d');
            $filename = $week . '.md';
            break;
        case 'month':
            $monthVal = $_POST['month'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}$/', $monthVal)) { $error = 'Invalid month.'; $valid = false; break; }
            [$year, $month] = explode('-', $monthVal);
            $startDate = $year . '-' . $month . '-01';
            $endDate = (new DateTime($startDate))->modify('last day of this month')->format('Y-m-d');
            $filename = $year . '-' . $month . '.md';
            break;
        case 'months':
            $startMonth = $_POST['start_month'] ?? '';
            $endMonth = $_POST['end_month'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}$/', $startMonth) || !preg_match('/^\d{4}-\d{2}$/', $endMonth)) { $error = 'Invalid month range.'; $valid = false; break; }
            $start = DateTime::createFromFormat('Y-m', $startMonth);
            $end = DateTime::createFromFormat('Y-m', $endMonth);
            if (!$start || !$end || $start > $end) { $error = 'Invalid month range.'; $valid = false; break; }
            $period = new DatePeriod($start, new DateInterval('P1M'), $end->modify('+1 month'));
            foreach ($period as $dt) {
                $y = $dt->format('Y');
                $m = $dt->format('m');
                $s = $y . '-' . $m . '-01';
                $e = (new DateTime($s))->modify('last day of this month')->format('Y-m-d');
                $entries = getEntriesForPeriod($pdo, $userId, $s, $e);
                if (!empty($entries)) {
                    $md = formatMarkdownFile($y, $username, $m, $entries);
                    $files[$y . '-' . $m . '.md'] = $md;
                }
            }
            $filename = 'entries_months_' . $startMonth . '_to_' . $endMonth . '.zip';
            break;
        case 'year':
            $yearVal = $_POST['year'] ?? '';
            if (!preg_match('/^\d{4}$/', $yearVal)) { $error = 'Invalid year.'; $valid = false; break; }
            for ($m = 1; $m <= 12; $m++) {
                $s = $yearVal . '-' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . '-01';
                $e = (new DateTime($s))->modify('last day of this month')->format('Y-m-d');
                $entries = getEntriesForPeriod($pdo, $userId, $s, $e);
                if (!empty($entries)) {
                    $md = formatMarkdownFile($yearVal, $username, str_pad((string)$m, 2, '0', STR_PAD_LEFT), $entries);
                    $files[$yearVal . '-' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . '.md'] = $md;
                }
            }
            $filename = 'entries_year_' . $yearVal . '.zip';
            break;
        case 'years':
            $startYear = $_POST['start_year'] ?? '';
            $endYear = $_POST['end_year'] ?? '';
            if (!preg_match('/^\d{4}$/', $startYear) || !preg_match('/^\d{4}$/', $endYear) || $startYear > $endYear) { $error = 'Invalid year range.'; $valid = false; break; }
            for ($y = (int)$startYear; $y <= (int)$endYear; $y++) {
                for ($m = 1; $m <= 12; $m++) {
                    $s = $y . '-' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . '-01';
                    $e = (new DateTime($s))->modify('last day of this month')->format('Y-m-d');
                    $entries = getEntriesForPeriod($pdo, $userId, $s, $e);
                    if (!empty($entries)) {
                        $md = formatMarkdownFile((string)$y, $username, str_pad((string)$m, 2, '0', STR_PAD_LEFT), $entries);
                        $files[$y . '-' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . '.md'] = $md;
                    }
                }
            }
            $filename = 'entries_years_' . $startYear . '_to_' . $endYear . '.zip';
            break;
        default:
            $error = 'Invalid export type.';
            $valid = false;
    }
    if ($valid && !$error) {
        if (in_array($exportType, ['day', 'week', 'month'])) {
            $entries = getEntriesForPeriod($pdo, $userId, $startDate, $endDate);
            $md = formatMarkdownFile($year, $username, $month, $entries);
            sendFileDownload($filename, $md);
        } elseif (in_array($exportType, ['months', 'year', 'years'])) {
            sendZipDownload($filename, $files);
        }
        exit;
    }
}



$userId = Auth::userId();
if (!is_int($userId)) {
    header('Location: /login.php');
    exit;
}



$username = '';
$success = null;
$exportType = $_POST['export_type'] ?? 'month';
$interfaceTheme = Auth::interfaceTheme();
$appVersion = $appVersion ?? (string)($config['version'] ?? '1.0.6');

try {
    $pdo = Database::connection($config['database']);
    $stmt = $pdo->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    if (is_array($row) && isset($row['username']) && $row['username'] !== null && $row['username'] !== '') {
        $username = (string)$row['username'];
    } else {
        $username = 'User';
    }
} catch (Throwable $e) {
    $username = 'User';
    $error = 'Could not load user info.';
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Export Entries - rJournaler Web</title>
    <style>
        :root {
            --font-ui: "Segoe UI", "Aptos", "Trebuchet MS", sans-serif;
            --radius-md: 12px;
            --radius-sm: 8px;
            --shadow-md: 0 12px 28px rgba(17, 24, 39, 0.14);
            --shadow-sm: 0 3px 10px rgba(17, 24, 39, 0.08);
            --transition-fast: 150ms ease;
        }

        body[data-theme="light"] {
            --bg: #f7f9fc;
            --bg-accent: #eef3fb;
            --surface: #ffffff;
            --surface-soft: #f6f9ff;
            --border: #d6e0ef;
            --text: #1f2f43;
            --text-muted: #5b6f87;
            --heading: #152334;
            --link: #1f5f9a;
            --button-bg: #2d5f88;
            --button-bg-hover: #224b6d;
            --button-text: #f8fbff;
            --ok-bg: #e9f8ef;
            --ok-border: #73b990;
            --ok-text: #1f5d39;
            --err-bg: #fdeef0;
            --err-border: #d78a95;
            --err-text: #842533;
        }

        body[data-theme="neutral"] {
            --bg: #f4f3f1;
            --bg-accent: #eceae6;
            --surface: #fffdf8;
            --surface-soft: #f4f1ea;
            --border: #d8d1c5;
            --text: #2f3432;
            --text-muted: #676d69;
            --heading: #222827;
            --link: #3c5f72;
            --button-bg: #4d6470;
            --button-bg-hover: #3f525b;
            --button-text: #f6f8f7;
            --ok-bg: #edf4ea;
            --ok-border: #87ad87;
            --ok-text: #2b5230;
            --err-bg: #f8ecec;
            --err-border: #b88c8c;
            --err-text: #6f3232;
        }

        body[data-theme="dark"] {
            --bg: #171d23;
            --bg-accent: #1e2730;
            --surface: #222d38;
            --surface-soft: #263340;
            --border: #364757;
            --text: #dbe4ec;
            --text-muted: #98a9b9;
            --heading: #f1f5f8;
            --link: #8fc3f3;
            --button-bg: #4f84ae;
            --button-bg-hover: #3f729a;
            --button-text: #f2f8ff;
            --ok-bg: #193427;
            --ok-border: #3f8d63;
            --ok-text: #98deb5;
            --err-bg: #41262b;
            --err-border: #91535f;
            --err-text: #f0b5bf;
            --shadow-md: 0 14px 32px rgba(0, 0, 0, 0.35);
            --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.28);
        }

        body {
            margin: 0;
            padding: 1.25rem;
            font-family: var(--font-ui);
            background: radial-gradient(circle at 20% 0%, var(--bg-accent), var(--bg) 38%);
            color: var(--text);
        }

        main {
            max-width: 1200px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.8rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .header-links {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        h1, h2, h3 {
            color: var(--heading);
            margin-top: 0;
        }

        h1 {
            margin-bottom: 0.35rem;
        }

        p {
            color: var(--text-muted);
        }

        a {
            color: var(--link);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .pill {
            display: inline-block;
            border: 1px solid var(--border);
            background: var(--surface-soft);
            color: var(--text);
            padding: 0.12rem 0.52rem;
            border-radius: 999px;
            font-size: 0.82rem;
            box-shadow: var(--shadow-sm);
        }

        .alert {
            border-radius: var(--radius-sm);
            padding: 0.65rem 0.8rem;
            border: 1px solid transparent;
            margin: 0.45rem 0;
        }

        .alert-error {
            background: var(--err-bg);
            border-color: var(--err-border);
            color: var(--err-text);
        }

        .alert-success {
            background: var(--ok-bg);
            border-color: var(--ok-border);
            color: var(--ok-text);
        }

        .alert-warning {
            background: #fff6dd;
            border-color: #d7b35a;
            color: #7a5514;
        }

        body[data-theme="dark"] .alert-warning {
            background: #3a2f17;
            border-color: #b48d3e;
            color: #f2d899;
        }

        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            padding: 1rem;
            margin: 0 0 1rem;
        }

        .panel h2 {
            border-bottom: 1px solid var(--border);
            padding-bottom: 0.5rem;
            margin-bottom: 0.9rem;
        }

        form {
            margin: 0;
        }

        .settings-form {
            display: grid;
            gap: 0.7rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(250px, 1fr));
            gap: 0.7rem 1rem;
        }

        .form-grid-full {
            grid-column: 1 / -1;
        }

        .form-group-title {
            margin: 0.2rem 0 0.15rem;
            padding-top: 0.2rem;
            font-size: 0.79rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            color: var(--text-muted);
        }

        .form-field {
            display: grid;
            gap: 0.35rem;
            align-content: start;
        }

        .form-field-check {
            align-items: center;
            padding-top: 0.2rem;
        }

        .form-field-check label {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            font-weight: 500;
        }

        .form-field-check input[type="checkbox"] {
            width: 1rem;
            height: 1rem;
            margin: 0;
            accent-color: var(--button-bg);
        }

        .form-actions {
            margin-top: 0.15rem;
        }

        label {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 600;
        }

        input,
        select,
        button,
        textarea {
            font: inherit;
        }

        input,
        select,
        textarea {
            background: var(--surface-soft);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            padding: 0.55rem 0.62rem;
            transition: border-color var(--transition-fast), box-shadow var(--transition-fast), background var(--transition-fast);
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: color-mix(in srgb, var(--button-bg) 55%, var(--border));
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--button-bg) 22%, transparent);
        }

        button {
            justify-self: start;
            background: var(--button-bg);
            color: var(--button-text);
            border: 1px solid color-mix(in srgb, var(--button-bg) 70%, #0000);
            border-radius: 9px;
            padding: 0.5rem 0.82rem;
            cursor: pointer;
            transition: background var(--transition-fast), transform var(--transition-fast);
        }

        button:hover {
            background: var(--button-bg-hover);
        }

        button:active {
            transform: translateY(1px);
        }

        pre,
        code {
            font-family: Consolas, "Cascadia Code", monospace;
        }

        pre {
            background: var(--surface-soft);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.7rem;
            white-space: pre-wrap;
            color: var(--text);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid var(--border);
            background: var(--surface);
            border-radius: 10px;
            overflow: hidden;
        }

        th,
        td {
            padding: 0.55rem 0.6rem;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: top;
        }

        thead th {
            background: var(--surface-soft);
            color: var(--heading);
            font-size: 0.82rem;
            letter-spacing: 0.01em;
        }

        tbody tr:hover {
            background: color-mix(in srgb, var(--surface-soft) 68%, transparent);
        }

        @media (max-width: 900px) {
            body {
                padding: 0.8rem;
            }

            .panel {
                padding: 0.75rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars($interfaceTheme, ENT_QUOTES, 'UTF-8'); ?>">
    <main>
        <section class="page-header">
            <h1>Export Entries</h1>
            <div class="header-links">
                <a class="pill" href="/index.php">Back to Dashboard</a>
                <div class="pill">Theme: <?php echo htmlspecialchars(ucfirst($interfaceTheme), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="pill">rJournaler_Web: v<?php echo htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </section>
        <section class="panel">
            <h2>Export Your Entries</h2>
            <p>Export your journal entries in a standardized markdown format for import elsewhere or backup. Choose the period and export type below.</p>
        <?php if ($error): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <form class="settings-form" method="post" action="/export-entries.php">
            <div class="form-group">
                <label for="export_type">Export Type</label>
                <select id="export_type" name="export_type" required>
                    <option value="day" <?php if ($exportType==='day') echo 'selected'; ?>>Day (single file)</option>
                    <option value="week" <?php if ($exportType==='week') echo 'selected'; ?>>Week (single file)</option>
                    <option value="month" <?php if ($exportType==='month') echo 'selected'; ?>>Month (single file)</option>
                    <option value="months" <?php if ($exportType==='months') echo 'selected'; ?>>Months (multiple files, zipped)</option>
                    <option value="year" <?php if ($exportType==='year') echo 'selected'; ?>>Year (multiple files, zipped)</option>
                    <option value="years" <?php if ($exportType==='years') echo 'selected'; ?>>Years (multiple files, zipped)</option>
                </select>
            </div>
            <div class="form-group" id="date-controls">
                <!-- Date pickers will be inserted here by JS or PHP as needed -->
            </div>
            <button type="submit">Export</button>
        </form>
        </section>
    </main>
<script>
// JS for dynamic date controls (optional, can be replaced with PHP if needed)
const exportType = document.getElementById('export_type');
const dateControls = document.getElementById('date-controls');
function renderDateControls() {
    let html = '';
    switch(exportType.value) {
        case 'day':
            html = '<label for="date">Date</label> <input type="date" id="date" name="date" required>';
            break;
        case 'week':
            html = '<label for="week">Week</label> <input type="week" id="week" name="week" required>';
            break;
        case 'month':
            html = '<label for="month">Month</label> <input type="month" id="month" name="month" required>';
            break;
        case 'months':
            html = '<label for="start_month">Start Month</label> <input type="month" id="start_month" name="start_month" required> ' +
                   '<label for="end_month" style="margin-left:1em;">End Month</label> <input type="month" id="end_month" name="end_month" required>';
            break;
        case 'year':
            html = '<label for="year">Year</label> <input type="number" id="year" name="year" min="2000" max="2100" required>';
            break;
        case 'years':
            html = '<label for="start_year">Start Year</label> <input type="number" id="start_year" name="start_year" min="2000" max="2100" required> ' +
                   '<label for="end_year" style="margin-left:1em;">End Year</label> <input type="number" id="end_year" name="end_year" min="2000" max="2100" required>';
            break;
    }
    dateControls.innerHTML = html;
}
exportType.addEventListener('change', renderDateControls);
document.addEventListener('DOMContentLoaded', renderDateControls);
</script>
</body>
</html>
