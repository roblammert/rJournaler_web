<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__) . '/app/Auth/require_auth.php';

use App\Auth\Auth;
use App\Core\Database;
use App\Import\ImportBatchService;
use App\Security\Csrf;

$userId = Auth::userId();
if (!is_int($userId)) {
    header('Location: /login.php');
    exit;
}
$interfaceTheme = Auth::interfaceTheme();
$appVersion = (string) ($config['version'] ?? '1.0.0');

$error = null;
$success = null;
$batch = null;
$entries = [];
$totalEntries = 0;
$perPage = 25;
$page = max(1, (int) ($_GET['page'] ?? 1));
$batchId = max(0, (int) ($_GET['batch'] ?? 0));

try {
    $pdo = Database::connection($config['database']);
    $service = new ImportBatchService();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['_csrf'] ?? null;
        if (!Csrf::validate(is_string($csrf) ? $csrf : null)) {
            $error = 'Invalid request token.';
        } else {
            $action = (string) ($_POST['action'] ?? '');
            $batchId = max(0, (int) ($_POST['batch_id'] ?? $batchId));

            if ($action === 'deny') {
                $service->denyBatch($pdo, $batchId, $userId);
                header('Location: /user-settings.php?import=denied');
                exit;
            }

            if ($action === 'accept') {
                $createdCount = $service->acceptBatch(
                    $pdo,
                    $batchId,
                    $userId,
                    (string) ($config['entry_uid']['app_version_code'] ?? 'W010000')
                );
                $success = 'Imported ' . $createdCount . ' entries and queued them for processing.';
            }
        }
    }

    if ($batchId > 0) {
        $batch = $service->getBatch($pdo, $batchId, $userId);
        if (!is_array($batch)) {
            $error = $error ?? 'Import batch not found.';
        } elseif (($batch['status'] ?? '') === 'parsed') {
            $totalEntries = $service->countBatchEntries($pdo, $batchId, $userId);
            $offset = ($page - 1) * $perPage;
            $entries = $service->listBatchEntries($pdo, $batchId, $userId, $offset, $perPage);
        }
    } else {
        $error = $error ?? 'No import batch selected.';
    }
} catch (Throwable $throwable) {
    $error = $error ?? 'Unable to load import review at this time.';
}

$totalPages = $totalEntries > 0 ? (int) ceil($totalEntries / $perPage) : 1;
$token = Csrf::token();
$displayTimeZone = 'America/Chicago';
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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Import Review</title>
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
            --ok-bg: #e9f8ef;
            --ok-border: #73b990;
            --warn-bg: #fff5e8;
            --warn-border: #d0a05e;
            --fail-bg: #fdeef0;
            --fail-border: #d78a95;
            --danger-text: #842533;
            --control-btn-bg: #e8eef6;
            --control-btn-border: #9aabc2;
            --control-btn-text: #334b63;
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
            --ok-bg: #edf4ea;
            --ok-border: #87ad87;
            --warn-bg: #f9f2e5;
            --warn-border: #c4a170;
            --fail-bg: #f8ecec;
            --fail-border: #b88c8c;
            --danger-text: #6f3232;
            --control-btn-bg: #ebeae6;
            --control-btn-border: #aeb1ac;
            --control-btn-text: #3f4944;
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
            --ok-bg: #193427;
            --ok-border: #3f8d63;
            --warn-bg: #3b3323;
            --warn-border: #a18a56;
            --fail-bg: #41262b;
            --fail-border: #91535f;
            --danger-text: #f0b5bf;
            --control-btn-bg: #3a4754;
            --control-btn-border: #6f8193;
            --control-btn-text: #dde6ef;
            --table-row-odd: #222d38;
            --table-row-even: #263340;
            --table-row-hover: #314250;
            --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.28);
        }

        body {
            font-family: var(--font-ui);
            margin: 0;
            padding: 1rem;
            background: radial-gradient(circle at 18% 0%, var(--bg-accent), var(--bg) 38%);
            color: var(--text);
        }

        main { max-width: 1320px; margin: 0 auto; }
        a { color: var(--link); text-decoration: none; }
        a:hover { text-decoration: underline; }

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
        .error {
            color: var(--danger-text);
            border: 1px solid var(--fail-border);
            background: var(--fail-bg);
            border-radius: 8px;
            padding: 0.45rem 0.55rem;
        }
        .ok {
            border: 1px solid var(--ok-border);
            background: var(--ok-bg);
            border-radius: 8px;
            padding: 0.45rem 0.55rem;
        }

        table { width: 100%; border-collapse: collapse; }
        th, td {
            border: 1px solid var(--border);
            padding: 0.45rem;
            text-align: left;
            vertical-align: top;
        }
        th { background: var(--surface-soft); color: var(--heading); }
        tbody tr:nth-child(odd) { background: var(--table-row-odd); }
        tbody tr:nth-child(even) { background: var(--table-row-even); }
        tbody tr:hover { background: var(--table-row-hover); }

        button {
            border: 1px solid var(--control-btn-border);
            border-radius: 999px;
            background: var(--control-btn-bg);
            color: var(--control-btn-text);
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            padding: 0.3rem 0.74rem;
        }
        button:hover { filter: brightness(1.05); }

        .actions { display: flex; gap: 0.55rem; margin: 0.8rem 0; flex-wrap: wrap; }
        .table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 8px; }
        .pager { margin-top: 0.7rem; }

        @media (max-width: 700px) {
            body { padding: 0.72rem; }
            .actions { flex-direction: column; }
        }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars($interfaceTheme, ENT_QUOTES, 'UTF-8'); ?>">
<main>
    <header class="page-header">
        <h1>Import Review</h1>
        <div class="header-links">
            <a class="pill" href="/index.php">Back to Dashboard</a>
            <a class="pill" href="/user-settings.php">User Settings</a>
            <span class="pill">Theme: <?php echo htmlspecialchars(ucfirst($interfaceTheme), ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="pill">rJournaler_Web: v<?php echo htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </header>

    <?php if ($error !== null): ?><p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($success !== null): ?><p class="ok"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

    <?php if (is_array($batch)): ?>
        <section class="panel">
        <p>
            Source: <strong><?php echo htmlspecialchars((string) ($batch['source_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
            | UID Version Code: <strong><?php echo htmlspecialchars((string) (($batch['uid_version_digits'] ?? '') !== '' ? strtoupper((string) $batch['uid_version_digits']) : 'default'), ENT_QUOTES, 'UTF-8'); ?></strong>
            | Status: <strong><?php echo htmlspecialchars((string) ($batch['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
            | Entries: <strong><?php echo (int) ($batch['entry_count'] ?? 0); ?></strong>
        </p>
        </section>
    <?php endif; ?>

    <?php if (is_array($batch) && (string) ($batch['status'] ?? '') === 'parsed'): ?>
        <section class="panel">
        <div class="actions">
            <form method="post" action="/import-review.php?batch=<?php echo (int) $batchId; ?>">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="accept">
                <input type="hidden" name="batch_id" value="<?php echo (int) $batchId; ?>">
                <button type="submit">Accept Import</button>
            </form>
            <form method="post" action="/import-review.php?batch=<?php echo (int) $batchId; ?>" onsubmit="return confirm('Deny import and delete staged entries?');">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="deny">
                <input type="hidden" name="batch_id" value="<?php echo (int) $batchId; ?>">
                <button type="submit">Deny Import</button>
            </form>
        </div>

        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Title</th>
                    <th>Time (Local)</th>
                    <th>Created (America/Chicago)</th>
                    <th>Content Preview</th>
                </tr>
            </thead>
            <tbody>
            <?php if (count($entries) === 0): ?>
                <tr><td colspan="6" class="muted">No staged entries found.</td></tr>
            <?php else: ?>
                <?php foreach ($entries as $row): ?>
                    <tr>
                        <td><?php echo (int) ($row['parsed_order'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['entry_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['entry_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['entry_time_local'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($formatUtcDateTime((string) ($row['entry_created_utc'] ?? ''), $displayTimeZone), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php
                            $preview = trim((string) ($row['content_markdown'] ?? ''));
                            if (mb_strlen($preview) > 180) {
                                $preview = mb_substr($preview, 0, 180) . '...';
                            }
                            echo htmlspecialchars($preview, ENT_QUOTES, 'UTF-8');
                        ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>

        <p class="pager">
            Page <?php echo $page; ?> / <?php echo $totalPages; ?>
            <?php if ($page > 1): ?>
                | <a href="/import-review.php?batch=<?php echo (int) $batchId; ?>&page=<?php echo $page - 1; ?>">Previous</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                | <a href="/import-review.php?batch=<?php echo (int) $batchId; ?>&page=<?php echo $page + 1; ?>">Next</a>
            <?php endif; ?>
        </p>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
