<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Auth/require_auth.php';

use App\Auth\Auth;
use App\Core\Database;

$userTimeZone = Auth::timezonePreference() ?? date_default_timezone_get();
$interfaceTheme = Auth::interfaceTheme();
$appVersion = (string) ($config['version'] ?? '1.0.0');
$appVersion = (string) ($config['version'] ?? '1.0.3');
$model = trim((string) ($_GET['model'] ?? ''));
$missingOnly = (string) ($_GET['missing'] ?? '0') === '1';

$titleSuffix = $missingOnly ? 'Entries Without LLM Data' : ($model !== '' ? ('Entries for Model: ' . $model) : 'LLM Model Entries');
$error = null;
$rows = [];

$formatUtcDateTime = static function (?string $value, string $timezone): string {
    if (!is_string($value) || trim($value) === '') {
        return '';
    }
    try {
        $utc = new DateTimeZone('UTC');
        $target = new DateTimeZone($timezone);
        $dt = new DateTimeImmutable($value, $utc);
        return $dt->setTimezone($target)->format('n/j/Y g:i:s A T');
    } catch (Throwable $throwable) {
        return $value;
    }
};

try {
    $pdo = Database::connection($config['database']);

    if ($missingOnly) {
        $stmt = $pdo->prepare(
            "
            SELECT je.entry_uid,
                   je.entry_date,
                   je.title,
                   je.workflow_stage,
                   je.updated_at,
                   COALESCE(NULLIF(TRIM(g2.llm_model), ''), '') AS llm_model
            FROM journal_entries je
            LEFT JOIN entry_meta_group_2 g2 ON g2.entry_uid = je.entry_uid
            WHERE je.workflow_stage IN ('COMPLETE', 'FINAL')
              AND (g2.entry_uid IS NULL OR g2.llm_model IS NULL OR TRIM(g2.llm_model) = '')
            ORDER BY je.entry_date DESC, je.updated_at DESC
            "
        );
        $stmt->execute();
    } else {
        if ($model === '') {
            throw new RuntimeException('Missing model parameter.');
        }

        $stmt = $pdo->prepare(
            "
            SELECT je.entry_uid,
                   je.entry_date,
                   je.title,
                   je.workflow_stage,
                   je.updated_at,
                   COALESCE(NULLIF(TRIM(g2.llm_model), ''), '') AS llm_model
            FROM journal_entries je
            LEFT JOIN entry_meta_group_2 g2 ON g2.entry_uid = je.entry_uid
            WHERE je.workflow_stage IN ('COMPLETE', 'FINAL')
              AND TRIM(COALESCE(g2.llm_model, '')) = :model
            ORDER BY je.entry_date DESC, je.updated_at DESC
            "
        );
        $stmt->execute(['model' => $model]);
    }

    $rows = $stmt->fetchAll() ?: [];
} catch (Throwable $throwable) {
    $error = 'Unable to load entry list.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($titleSuffix, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        :root {
            --font-ui: "Segoe UI", "Aptos", "Trebuchet MS", sans-serif;
            --radius-md: 10px;
            --shadow-sm: 0 4px 12px rgba(17, 24, 39, 0.08);
        }

        body[data-theme="light"] {
            --bg: #f7f9fc;
            --bg-accent: #edf3fb;
            --surface: #ffffff;
            --surface-soft: #f7faff;
            --border: #d6e0ef;
            --text: #1f2f43;
            --text-muted: #5b6f87;
            --heading: #152334;
            --link: #1f5f9a;
            --danger: #842533;
            --table-row-odd: #ffffff;
            --table-row-even: #f3f7fe;
            --table-row-hover: #e9f2ff;
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
            --danger: #6f3232;
            --table-row-odd: #fffdf8;
            --table-row-even: #f4f1ea;
            --table-row-hover: #ece7de;
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
            --danger: #f0b5bf;
            --table-row-odd: #222d38;
            --table-row-even: #263340;
            --table-row-hover: #314250;
            --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.28);
        }

        body {
            margin: 0;
            padding: 1rem;
            font-family: var(--font-ui);
            background: radial-gradient(circle at 18% 0%, var(--bg-accent), var(--bg) 38%);
            color: var(--text);
        }

        main { max-width: 1320px; margin: 0 auto; }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 0.8rem;
            flex-wrap: wrap;
            margin-bottom: 0.55rem;
        }
        .page-header h1 { margin: 0; color: var(--heading); }
        .header-links { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .pill {
            display: inline-block;
            padding: 0.12rem 0.52rem;
            border-radius: 999px;
            font-size: 0.82rem;
            border: 1px solid var(--border);
            background: var(--surface-soft);
            color: var(--text);
            box-shadow: var(--shadow-sm);
        }
        .panel {
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface);
            box-shadow: var(--shadow-sm);
            padding: 0.75rem;
            margin-top: 0.55rem;
        }
        .muted { color: var(--text-muted); }
        .error { color: var(--danger); }

        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid var(--border); padding: 0.45rem; text-align: left; vertical-align: top; }
        th { background: var(--surface-soft); color: var(--heading); }
        tbody tr:nth-child(odd) { background: var(--table-row-odd); }
        tbody tr:nth-child(even) { background: var(--table-row-even); }
        tbody tr:hover { background: var(--table-row-hover); }
        code { white-space: nowrap; }
        .table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 8px; }
        a { color: var(--link); text-decoration: none; }
        a:hover { text-decoration: underline; }

        @media (max-width: 700px) {
            body { padding: 0.72rem; }
        }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars($interfaceTheme, ENT_QUOTES, 'UTF-8'); ?>">
<main>
    <header class="page-header">
        <h1><?php echo htmlspecialchars($titleSuffix, ENT_QUOTES, 'UTF-8'); ?></h1>
        <div class="header-links">
            <a class="pill" href="/dashboards/status.php" target="_self">Back to Status</a>
            <a class="pill" href="/index.php" target="_self">Back to Dashboard</a>
            <span class="pill">Theme: <?php echo htmlspecialchars(ucfirst($interfaceTheme), ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="pill">rJournaler_Web: v<?php echo htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </header>

    <?php if ($error !== null): ?>
        <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <section class="panel">
        <p class="muted">Total entries: <?php echo count($rows); ?></p>

    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Entry Date</th>
                <th>Entry UID</th>
                <th>Title</th>
                <th>Stage</th>
                <th>LLM Model</th>
                <th>Updated</th>
            </tr>
        </thead>
        <tbody>
        <?php if (count($rows) === 0): ?>
            <tr><td colspan="6" class="muted">No matching entries found.</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) ($row['entry_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <?php $uid = (string) ($row['entry_uid'] ?? ''); ?>
                        <a href="/entry.php?uid=<?php echo urlencode($uid); ?>" target="_blank" rel="noopener"><code><?php echo htmlspecialchars($uid, ENT_QUOTES, 'UTF-8'); ?></code></a>
                    </td>
                    <td><?php echo htmlspecialchars((string) ($row['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($row['workflow_stage'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($row['llm_model'] !== '' ? $row['llm_model'] : 'none'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($formatUtcDateTime((string) ($row['updated_at'] ?? ''), $userTimeZone), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    </section>
</main>
</body>
</html>
