<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Auth/require_auth.php';

use App\Auth\Auth;
use App\Security\Csrf;

$userTimeZone = Auth::timezonePreference() ?? date_default_timezone_get();
$interfaceTheme = Auth::interfaceTheme();
$appVersion = (string) ($config['version'] ?? '1.0.0');
$csrfToken = Csrf::token();
$isAdmin = Auth::isAdmin();
$serverOsFamily = strtoupper((string) PHP_OS_FAMILY);
$projectRoot = dirname(__DIR__, 2);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Status Dashboard</title>
    <style>
        :root {
            --font-ui: "Segoe UI", "Aptos", "Trebuchet MS", sans-serif;
            --radius-lg: 12px;
            --radius-md: 9px;
            --shadow-md: 0 12px 28px rgba(17, 24, 39, 0.14);
            --shadow-sm: 0 4px 12px rgba(17, 24, 39, 0.08);
            --transition-fast: 150ms ease;
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
            --chip-bg: #eef3ff;
            --chip-border: #c9d8ff;
            --chip-text: #274768;
            --input-bg: #ffffff;
            --danger-text: #842533;
            --overlay: rgba(5, 13, 24, 0.5);
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
            --chip-bg: #eef0ef;
            --chip-border: #c6ccc8;
            --chip-text: #46524c;
            --input-bg: #fffefb;
            --danger-text: #6f3232;
            --overlay: rgba(25, 22, 16, 0.45);
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
            --chip-bg: #2c3844;
            --chip-border: #4d6376;
            --chip-text: #c4d5e4;
            --input-bg: #1e2832;
            --danger-text: #f0b5bf;
            --overlay: rgba(0, 0, 0, 0.6);
            --shadow-md: 0 14px 32px rgba(0, 0, 0, 0.35);
            --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.28);
            --control-btn-bg: #3a4754;
            --control-btn-border: #6f8193;
            --control-btn-text: #dde6ef;
            --table-row-odd: #222d38;
            --table-row-even: #263340;
            --table-row-hover: #314250;
        }

        body {
            margin: 0;
            padding: 1rem;
            font-family: var(--font-ui);
            background: radial-gradient(circle at 18% 0%, var(--bg-accent), var(--bg) 38%);
            color: var(--text);
        }

        main {
            max-width: 1320px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 0.8rem;
            flex-wrap: wrap;
            margin-bottom: 0.5rem;
        }

        .page-header h1 {
            margin: 0;
            color: var(--heading);
        }

        .header-links {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .muted { color: var(--text-muted); }
        .error { color: var(--danger-text); }

        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid var(--border); padding: 0.5rem; text-align: left; vertical-align: top; }
        th { background: var(--surface-soft); color: var(--heading); }
        tbody tr:nth-child(odd) { background: var(--table-row-odd); }
        tbody tr:nth-child(even) { background: var(--table-row-even); }
        tbody tr:hover { background: var(--table-row-hover); }
        code { white-space: pre-wrap; }
        .pill { display: inline-block; padding: 0.12rem 0.52rem; border-radius: 999px; font-size: 0.82rem; border: 1px solid var(--border); background: var(--surface-soft); color: var(--text); box-shadow: var(--shadow-sm); }
        .pill-queued { background: var(--surface-soft); }
        .pill-processing { background: var(--warn-bg); border-color: var(--warn-border); }
        .pill-completed { background: var(--ok-bg); border-color: var(--ok-border); }
        .pill-failed { background: var(--fail-bg); border-color: var(--fail-border); }
        .chip { display: inline-block; margin: 0.1rem 0.2rem 0.1rem 0; padding: 0.12rem 0.45rem; border-radius: 999px; font-size: 0.78rem; background: var(--chip-bg); border: 1px solid var(--chip-border); color: var(--chip-text); }
        .chip-stage-processing { background: var(--warn-bg); border-color: var(--warn-border); color: var(--text); }
        .health-chip { display: inline-block; margin-right: 0.4rem; padding: 0.2rem 0.6rem; border-radius: 999px; border: 1px solid var(--border); font-size: 0.85rem; }
        .health-pass { background: var(--ok-bg); border-color: var(--ok-border); }
        .health-warn { background: var(--warn-bg); border-color: var(--warn-border); }
        .health-fail { background: var(--fail-bg); border-color: var(--fail-border); }
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.75rem; margin: 0.8rem 0 1rem; }
        .card { border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0.7rem; background: var(--surface-soft); box-shadow: var(--shadow-sm); transition: transform var(--transition-fast); }
        .card:hover { transform: translateY(-1px); }
        .card .label { font-size: 0.82rem; color: var(--text-muted); }
        .card .value { font-size: 1.25rem; font-weight: 700; color: var(--heading); }
        .card .subtext { margin-top: 0.3rem; font-size: 0.8rem; color: var(--text-muted); line-height: 1.35; }
        .card .subtext a { color: var(--link); text-decoration: underline; }
        .card .subtext ul { margin: 0.35rem 0 0 1rem; padding: 0; }
        .card .subtext li { margin: 0.12rem 0; }
        .autobot-chip-list { margin-top: 0.4rem; display: flex; flex-wrap: wrap; gap: 0.35rem; }
        .autobot-chip { border: 1px solid var(--chip-border); background: var(--chip-bg); color: var(--chip-text); border-radius: 999px; padding: 0.14rem 0.48rem; font-size: 0.74rem; cursor: default; }
        .autobot-chip.clickable { cursor: pointer; }
        .autobot-chip.clickable:hover { filter: brightness(0.96); }
        .autobot-chip.pending { background: var(--warn-bg); border-color: var(--warn-border); color: var(--text); }
        .row-actions { margin-top: 0.3rem; }
        .row-actions button { padding: 0.2rem 0.5rem; font-size: 0.78rem; }
        .row-actions button {
            background: var(--control-btn-bg);
            border-color: var(--control-btn-border);
            color: var(--control-btn-text);
            font-weight: 600;
        }
        .pager { display: flex; align-items: center; gap: 0.6rem; margin: 0.6rem 0; }
        .pager button { padding: 0.24rem 0.55rem; }
        .pager input[type="number"] { width: 5.2rem; padding: 0.24rem 0.3rem; }
        .modal-overlay { position: fixed; inset: 0; background: var(--overlay); display: flex; align-items: center; justify-content: center; padding: 1rem; z-index: 1200; }
        .modal-overlay[hidden] { display: none; }
        .modal-card { width: min(520px, 100%); background: var(--surface); border: 1px solid var(--border); border-radius: 10px; box-shadow: var(--shadow-md); padding: 0.9rem; }
        .modal-card h3 { margin: 0 0 0.35rem; }
        .modal-card p { margin: 0.3rem 0 0.7rem; }
        .modal-option-list { display: grid; gap: 0.45rem; margin: 0 0 0.8rem; }
        .modal-option { display: flex; align-items: center; gap: 0.45rem; }
        .modal-bulk-actions { display: flex; gap: 0.45rem; margin: 0 0 0.65rem; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 0.45rem; }
        .modal-error { min-height: 1.2rem; margin: 0 0 0.55rem; }
        .control-sections { display: flex; flex-wrap: wrap; gap: 0.65rem; margin: 0.45rem 0 0.85rem; align-items: flex-start; }
        .control-section { border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--surface); box-shadow: var(--shadow-sm); padding: 0.65rem; flex: 1 1 300px; max-width: 520px; }
        .control-section h2 { font-size: 0.95rem; margin: 0 0 0.45rem; color: var(--heading); }
        .control-buttons { display: flex; flex-wrap: wrap; gap: 0.45rem; }
        .control-buttons button {
            background: var(--control-btn-bg);
            border-color: var(--control-btn-border);
            color: var(--control-btn-text);
            font-weight: 600;
        }
        .control-buttons button:hover {
            filter: brightness(1.06);
        }
        .control-note { margin-top: 0.4rem; }
        #worker-fix-note { flex: 1 1 100%; }

        .status-health-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(220px, 1fr));
            gap: 0.5rem;
            margin: 0.5rem 0 0.75rem;
        }

        .status-health-card {
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface);
            box-shadow: var(--shadow-sm);
            padding: 0.52rem;
        }

        .status-health-card h2 {
            margin: 0 0 0.36rem;
            color: var(--heading);
            font-size: 0.88rem;
        }

        .status-pill-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.3rem;
        }

        .status-pill-list > .health-chip {
            max-width: 100%;
        }

        .status-pill-list.three-column {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.3rem;
        }

        .health-chip {
            display: inline-flex;
            align-items: center;
            justify-content: flex-start;
            margin: 0;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            border: 1px solid var(--border);
            font-size: 0.74rem;
            line-height: 1.2;
            text-align: left;
            white-space: normal;
            overflow: visible;
            text-overflow: clip;
            overflow-wrap: anywhere;
        }

        button,
        input {
            font: inherit;
        }

        button {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface-soft);
            color: var(--text);
            cursor: pointer;
            transition: filter var(--transition-fast);
        }

        button:hover {
            filter: brightness(0.97);
        }

        input {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--input-bg);
            color: var(--text);
        }

        a { color: var(--link); text-decoration: none; }
        a:hover { text-decoration: underline; }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface);
            box-shadow: var(--shadow-sm);
        }

        @media (max-width: 700px) {
            body { padding: 0.72rem; }
            .pager { flex-wrap: wrap; }
            .status-health-grid { grid-template-columns: 1fr; }
            .status-pill-list.three-column { grid-template-columns: 1fr; }
        }

        @media (max-width: 1120px) {
            .status-pill-list.three-column { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars($interfaceTheme, ENT_QUOTES, 'UTF-8'); ?>">
<main>
<header class="page-header">
    <h1>Queue Status</h1>
    <div class="header-links">
        <a class="pill" href="/index.php">Back to Dashboard</a>
        <span class="pill">Theme: <?php echo htmlspecialchars(ucfirst($interfaceTheme), ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="pill">rJournaler_Web: v<?php echo htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
</header>
<p class="muted">Refreshes every 5 seconds. Completed and failed rows are retained using separate hour-based windows.</p>
<section class="status-health-grid" aria-label="System health summary">
    <article class="status-health-card">
        <h2>Database</h2>
        <div id="health-db" class="status-pill-list">
            <span class="health-chip">Checking...</span>
        </div>
    </article>
    <article class="status-health-card">
        <h2>Ollama AI LLM</h2>
        <div id="health-ollama" class="status-pill-list">
            <span class="health-chip">Checking...</span>
        </div>
    </article>
    <article class="status-health-card">
        <h2>Optimus Orchestrator</h2>
        <div id="health-optimus" class="status-pill-list">
            <span class="health-chip">Checking...</span>
        </div>
    </article>
    <article class="status-health-card">
        <h2>Web Engine</h2>
        <div id="health-web-engine" class="status-pill-list three-column">
            <span class="health-chip">Checking...</span>
        </div>
    </article>
</section>
<section class="control-sections">
    <div class="control-section">
        <h2>Worker Commands</h2>
        <div class="control-buttons">
            <button id="worker-fix-copy" type="button">Copy stop workers command</button>
            <button id="worker-start-copy" type="button">Copy start command</button>
            <button id="worker-reset-copy" type="button">Copy full reset command</button>
        </div>
        <div class="muted control-note">Runbook: if worker behavior is unstable, use full reset first. Use start only when no worker is running.</div>
    </div>
    <?php if ($isAdmin): ?>
        <div class="control-section">
            <h2>Queue Recovery</h2>
            <div class="control-buttons">
                <button id="worker-reconcile" type="button">Reconcile Stale Locks</button>
            </div>
        </div>
        <div class="control-section">
            <h2>Process Control</h2>
            <div class="control-buttons">
                <button id="worker-kill-autobots" type="button">Kill All Autobots</button>
                <button id="worker-kill-stale-autobots" type="button">Kill Stale Autobots</button>
                <button id="worker-kill-orphaned-autobots" type="button">Kill Orphaned Autobots</button>
                <button id="worker-kill-optimus" type="button">Force Kill Optimus</button>
                <button id="worker-restart-optimus" type="button">Force Restart Optimus</button>
            </div>
        </div>
        <div class="control-section">
            <h2>Failed Jobs</h2>
            <div class="control-buttons">
                <button id="worker-reset-failed-jobs" type="button">Reset Failed Jobs</button>
            </div>
        </div>
    <?php endif; ?>
    <div id="worker-fix-note" class="muted"></div>
</section>
<section class="cards" id="summary-cards-row-1">
    <article class="card"><div class="label">Queued Jobs</div><div class="value" id="card-queued">-</div></article>
    <article class="card"><div class="label">Failures</div><div class="value" id="card-failed">-</div></article>
    <article class="card"><div class="label">Awaiting LLM</div><div class="value" id="card-llm">-</div></article>
    <article class="card"><div class="label">IN_PROCESS Missing Metadata</div><div class="value" id="card-in-process-missing">-</div></article>
    <article class="card"><div class="label">Avg Active Age</div><div class="value" id="card-age">-</div></article>
    <article class="card">
        <div class="label">Last Reconcile</div>
        <div class="value" id="card-reconcile-at">-</div>
        <div class="muted" id="card-reconcile-by">-</div>
    </article>
</section>
<section class="cards" id="summary-cards-row-2">
    <article class="card">
        <div class="label">Total Tasks Remaining</div>
        <div class="value" id="card-tasks-remaining">-</div>
        <div class="subtext" id="card-tasks-remaining-breakdown">-</div>
    </article>
    <article class="card">
        <div class="label">COMPLETE/FINAL LLM Coverage</div>
        <div class="value" id="card-complete-final-total">-</div>
        <div class="subtext" id="card-llm-model-breakdown">-</div>
    </article>
    <article class="card">
        <div class="label">Active Autobots</div>
        <div class="value" id="card-active-autobots">-</div>
        <div id="card-active-autobot-chip-list" class="autobot-chip-list"></div>
    </article>
    <article class="card">
        <div class="label">Stale Autobots</div>
        <div class="value" id="card-stale-autobots">-</div>
        <div id="card-stale-autobot-chip-list" class="autobot-chip-list"></div>
        <div class="subtext">Click a stale worker chip to kill one worker. Use <strong>Kill Stale Autobots</strong> in Process Control to kill all stale workers.</div>
    </article>
</section>
<p id="meta" class="muted">Loading...</p>
<div class="pager" id="queue-pager-top">
    <button id="page-prev-top" type="button">Previous</button>
    <span id="page-label-top" class="muted">Page 1 / 1</span>
    <button id="page-next-top" type="button">Next</button>
    <label for="page-jump-top" class="muted">Go to page</label>
    <input id="page-jump-top" type="number" min="1" step="1" value="1">
    <button id="page-jump-top-btn" type="button">Go</button>
</div>

<div id="failed-reset-modal" class="modal-overlay" hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="failed-reset-modal-title">
        <h3 id="failed-reset-modal-title">Reset Failed Jobs</h3>
        <p class="muted">Choose how failed jobs should be queued for reprocessing.</p>
        <form id="failed-reset-modal-form">
            <div class="modal-option-list">
                <label class="modal-option"><input type="radio" name="failed_reset_mode" value="all_jobs" checked> Reprocess all pipeline stages for each failed job</label>
                <label class="modal-option"><input type="radio" name="failed_reset_mode" value="remaining_failed_jobs"> Reprocess only remaining/failed stages</label>
                <label class="modal-option"><input type="radio" name="failed_reset_mode" value="remaining_unresolved_failed_jobs"> Reprocess only unresolved failed jobs (skip entries already queued/processing)</label>
            </div>
            <p id="failed-reset-modal-error" class="error modal-error" aria-live="polite"></p>
            <div class="modal-actions">
                <button id="failed-reset-modal-cancel" type="button">Cancel</button>
                <button id="failed-reset-modal-confirm" type="submit">Reset Failed Jobs</button>
            </div>
        </form>
    </div>
</div>

<div class="table-wrap">
<table>
    <thead>
        <tr>
            <th>Submitted</th>
            <th>Entry UID</th>
            <th>Submitter</th>
            <th>Stage</th>
            <th>Remaining Stages</th>
            <th>Age</th>
            <th>Comment / Status</th>
        </tr>
    </thead>
    <tbody id="queue-body">
        <tr><td colspan="7" class="muted">Loading...</td></tr>
    </tbody>
</table>
</div>
<div class="pager" id="queue-pager-bottom">
    <button id="page-prev-bottom" type="button">Previous</button>
    <span id="page-label-bottom" class="muted">Page 1 / 1</span>
    <button id="page-next-bottom" type="button">Next</button>
    <label for="page-jump-bottom" class="muted">Go to page</label>
    <input id="page-jump-bottom" type="number" min="1" step="1" value="1">
    <button id="page-jump-bottom-btn" type="button">Go</button>
</div>

<div id="reprocess-modal" class="modal-overlay" hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="reprocess-modal-title">
        <h3 id="reprocess-modal-title">Select Meta Groups To Reprocess</h3>
        <p class="muted">All groups are selected by default. Choose one or more groups before continuing.</p>
        <form id="reprocess-modal-form">
            <div class="modal-option-list">
                <label class="modal-option"><input type="checkbox" name="pipeline_stage_ids" value="meta_group_0" checked> Meta Group 0</label>
                <label class="modal-option"><input type="checkbox" name="pipeline_stage_ids" value="meta_group_1" checked> Meta Group 1</label>
                <label class="modal-option"><input type="checkbox" name="pipeline_stage_ids" value="meta_group_2_llm" checked> Meta Group 2 (LLM)</label>
                <label class="modal-option"><input type="checkbox" name="pipeline_stage_ids" value="meta_group_3_weather" checked> Meta Group 3 (Weather)</label>
                <label class="modal-option"><input type="checkbox" name="pipeline_stage_ids" value="metrics_finalize" checked> Metrics Finalize</label>
            </div>
            <div class="modal-bulk-actions">
                <button id="reprocess-modal-select-all" type="button">Select all</button>
                <button id="reprocess-modal-clear-all" type="button">Clear all</button>
            </div>
            <p id="reprocess-modal-error" class="error modal-error" aria-live="polite"></p>
            <div class="modal-actions">
                <button id="reprocess-modal-cancel" type="button">Cancel</button>
                <button id="reprocess-modal-confirm" type="submit">Queue Reprocess</button>
            </div>
        </form>
    </div>
</div>

<script>
    const userTimeZone = <?php echo json_encode($userTimeZone, JSON_UNESCAPED_SLASHES); ?>;
    const csrfToken = <?php echo json_encode($csrfToken, JSON_UNESCAPED_SLASHES); ?>;
    const isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
    const serverOsFamily = <?php echo json_encode($serverOsFamily, JSON_UNESCAPED_SLASHES); ?>;
    const projectRoot = <?php echo json_encode($projectRoot, JSON_UNESCAPED_SLASHES); ?>;
    const body = document.getElementById('queue-body');
    const meta = document.getElementById('meta');
    const healthDb = document.getElementById('health-db');
    const healthOllama = document.getElementById('health-ollama');
    const healthOptimus = document.getElementById('health-optimus');
    const healthWebEngine = document.getElementById('health-web-engine');
    const cardQueued = document.getElementById('card-queued');
    const cardFailed = document.getElementById('card-failed');
    const cardLlm = document.getElementById('card-llm');
    const cardTasksRemaining = document.getElementById('card-tasks-remaining');
    const cardTasksRemainingBreakdown = document.getElementById('card-tasks-remaining-breakdown');
    const cardActiveAutobots = document.getElementById('card-active-autobots');
    const cardActiveAutobotChipList = document.getElementById('card-active-autobot-chip-list');
    const cardStaleAutobots = document.getElementById('card-stale-autobots');
    const cardStaleAutobotChipList = document.getElementById('card-stale-autobot-chip-list');
    const cardInProcessMissing = document.getElementById('card-in-process-missing');
    const cardAge = document.getElementById('card-age');
    const cardReconcileAt = document.getElementById('card-reconcile-at');
    const cardReconcileBy = document.getElementById('card-reconcile-by');
    const cardCompleteFinalTotal = document.getElementById('card-complete-final-total');
    const cardLlmModelBreakdown = document.getElementById('card-llm-model-breakdown');
    const pagePrevTop = document.getElementById('page-prev-top');
    const pageNextTop = document.getElementById('page-next-top');
    const pageLabelTop = document.getElementById('page-label-top');
    const pagePrevBottom = document.getElementById('page-prev-bottom');
    const pageNextBottom = document.getElementById('page-next-bottom');
    const pageLabelBottom = document.getElementById('page-label-bottom');
    const pageJumpTop = document.getElementById('page-jump-top');
    const pageJumpTopButton = document.getElementById('page-jump-top-btn');
    const pageJumpBottom = document.getElementById('page-jump-bottom');
    const pageJumpBottomButton = document.getElementById('page-jump-bottom-btn');
    const workerFixCopyButton = document.getElementById('worker-fix-copy');
    const workerStartCopyButton = document.getElementById('worker-start-copy');
    const workerResetCopyButton = document.getElementById('worker-reset-copy');
    const workerReconcileButton = document.getElementById('worker-reconcile');
    const workerKillAutobotsButton = document.getElementById('worker-kill-autobots');
    const workerKillStaleAutobotsButton = document.getElementById('worker-kill-stale-autobots');
    const workerKillOrphanedAutobotsButton = document.getElementById('worker-kill-orphaned-autobots');
    const workerKillOptimusButton = document.getElementById('worker-kill-optimus');
    const workerRestartOptimusButton = document.getElementById('worker-restart-optimus');
    const workerResetFailedJobsButton = document.getElementById('worker-reset-failed-jobs');
    const workerFixNote = document.getElementById('worker-fix-note');
    const reprocessModal = document.getElementById('reprocess-modal');
    const reprocessModalForm = document.getElementById('reprocess-modal-form');
    const reprocessModalCancel = document.getElementById('reprocess-modal-cancel');
    const reprocessModalSelectAll = document.getElementById('reprocess-modal-select-all');
    const reprocessModalClearAll = document.getElementById('reprocess-modal-clear-all');
    const reprocessModalError = document.getElementById('reprocess-modal-error');
    const failedResetModal = document.getElementById('failed-reset-modal');
    const failedResetModalForm = document.getElementById('failed-reset-modal-form');
    const failedResetModalCancel = document.getElementById('failed-reset-modal-cancel');
    const failedResetModalError = document.getElementById('failed-reset-modal-error');
    const reprocessStageDefaults = ['meta_group_0', 'meta_group_1', 'meta_group_2_llm', 'meta_group_3_weather', 'metrics_finalize'];
    const projectRootPosix = projectRoot.replace(/\\/g, '/');
    const workerFixCommand = serverOsFamily === 'WINDOWS'
        ? `Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -like '*worker/main.py*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }`
        : `pkill -f 'python(.*/)?worker/main.py'`;
    const workerStartCommand = serverOsFamily === 'WINDOWS'
        ? `"${projectRootPosix}/.venv/Scripts/python.exe" "${projectRootPosix}/python/worker/main.py"`
        : `cd "${projectRoot}" && ("${projectRoot}/.venv/bin/python" "${projectRoot}/python/worker/main.py" || python3 "${projectRoot}/python/worker/main.py")`;
    const workerResetCommand = `${workerFixCommand}; ${workerStartCommand}`;
    const urlParams = new URLSearchParams(window.location.search);
    let currentPage = Math.max(1, Number.parseInt(urlParams.get('page') || '1', 10) || 1);
    let totalPages = 1;
    let optimusKillCooldownUntilMs = 0;
    let optimusRestartCooldownUntilMs = 0;

    function formatAge(seconds) {
        const s = Number(seconds);
        if (!Number.isFinite(s) || s < 0) {
            return 'n/a';
        }
        const days = Math.floor(s / 86400);
        const hours = Math.floor((s % 86400) / 3600);
        const minutes = Math.floor((s % 3600) / 60);
        const secs = Math.floor(s % 60);
        const parts = [];
        if (days > 0) parts.push(days + 'd');
        if (hours > 0 || days > 0) parts.push(hours + 'h');
        if (minutes > 0 || hours > 0 || days > 0) parts.push(minutes + 'm');
        parts.push(secs + 's');
        return parts.join(' ');
    }

    function parseUtcDateTime(value) {
        const raw = String(value || '').trim();
        if (raw === '') {
            return null;
        }
        const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T');
        const iso = /Z$|[+-]\d{2}:\d{2}$/.test(normalized) ? normalized : normalized + 'Z';
        const parsed = new Date(iso);
        if (Number.isNaN(parsed.getTime())) {
            return null;
        }
        return parsed;
    }

    function formatDateTime(value) {
        const parsed = parseUtcDateTime(value);
        if (!parsed) {
            return String(value || '');
        }
        return parsed.toLocaleString(undefined, {
            dateStyle: 'short',
            timeStyle: 'medium',
            timeZone: userTimeZone || undefined
        });
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function healthClass(status) {
        const value = String(status || '').toLowerCase();
        if (value === 'pass' || value === 'ok') {
            return 'health-pass';
        }
        if (value === 'warn') {
            return 'health-warn';
        }
        return 'health-fail';
    }

    function renderPill(status, label, detail) {
        const safeLabel = escapeHtml(String(label || '').trim());
        const detailText = String(detail || '').trim();
        const safeDetail = detailText === '' ? '' : ' (' + escapeHtml(detailText) + ')';
        return '<span class="health-chip ' + healthClass(status) + '">' + safeLabel + safeDetail + '</span>';
    }

    function renderMutedPill(label, detail) {
        const safeLabel = escapeHtml(String(label || '').trim());
        const detailText = String(detail || '').trim();
        const safeDetail = detailText === '' ? '' : ': ' + escapeHtml(detailText);
        return '<span class="health-chip">' + safeLabel + safeDetail + '</span>';
    }

    function normalizePhpExtensionChecks(checks) {
        const rows = Array.isArray(checks) ? checks : [];
        const mapped = rows
            .map((check) => ({
                name: String((check && check.name) || '').trim().toLowerCase(),
                status: String((check && check.status) || 'fail').trim() || 'fail',
                detail: String((check && check.detail) || '').trim(),
            }))
            .filter((row) => row.name !== '');

        const byName = new Map();
        mapped.forEach((row) => byName.set(row.name, row));

        const orderedNames = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'zip', 'json'];
        const orderedRows = [];
        orderedNames.forEach((name) => {
            if (byName.has(name)) {
                orderedRows.push(byName.get(name));
                byName.delete(name);
            }
        });

        Array.from(byName.values())
            .sort((a, b) => a.name.localeCompare(b.name))
            .forEach((row) => orderedRows.push(row));

        return orderedRows;
    }

    function openReprocessModal() {
        if (!reprocessModal || !reprocessModalForm) {
            return Promise.resolve(reprocessStageDefaults.slice());
        }

        const checkboxes = reprocessModalForm.querySelectorAll('input[name="pipeline_stage_ids"]');
        checkboxes.forEach((box) => {
            if (box instanceof HTMLInputElement) {
                box.checked = true;
            }
        });
        if (reprocessModalError) {
            reprocessModalError.textContent = '';
        }
        reprocessModal.hidden = false;

        const first = checkboxes[0];
        if (first instanceof HTMLInputElement) {
            first.focus();
        }

        return new Promise((resolve) => {
            const close = (selection) => {
                reprocessModal.hidden = true;
                reprocessModal.removeEventListener('click', onOverlayClick);
                document.removeEventListener('keydown', onKeydown);
                reprocessModalForm.removeEventListener('submit', onSubmit);
                reprocessModalCancel.removeEventListener('click', onCancel);
                resolve(selection);
            };

            const onCancel = () => close(null);
            const onOverlayClick = (event) => {
                if (event.target === reprocessModal) {
                    close(null);
                }
            };
            const onKeydown = (event) => {
                if (event.key === 'Escape') {
                    close(null);
                }
            };
            const onSubmit = (event) => {
                event.preventDefault();
                const selected = Array.from(reprocessModalForm.querySelectorAll('input[name="pipeline_stage_ids"]:checked'))
                    .map((input) => String(input.value || '').trim())
                    .filter((value) => value !== '');
                if (selected.length === 0) {
                    if (reprocessModalError) {
                        reprocessModalError.textContent = 'Select at least one meta group.';
                    }
                    return;
                }
                close(selected);
            };

            reprocessModal.addEventListener('click', onOverlayClick);
            document.addEventListener('keydown', onKeydown);
            reprocessModalForm.addEventListener('submit', onSubmit);
            reprocessModalCancel.addEventListener('click', onCancel);
        });
    }

    function setAllReprocessStageSelections(checked) {
        if (!reprocessModalForm) {
            return;
        }
        const checkboxes = reprocessModalForm.querySelectorAll('input[name="pipeline_stage_ids"]');
        checkboxes.forEach((box) => {
            if (box instanceof HTMLInputElement) {
                box.checked = checked;
            }
        });
        if (reprocessModalError) {
            reprocessModalError.textContent = '';
        }
    }

    async function requestReprocess(entryUid, button) {
        const uid = String(entryUid || '').trim();
        if (uid === '') {
            return;
        }

        const selectedStageIds = await openReprocessModal();
        if (!Array.isArray(selectedStageIds) || selectedStageIds.length === 0) {
            return;
        }

        const originalText = button ? button.textContent : '';
        if (button) {
            button.disabled = true;
            button.textContent = 'Queuing...';
        }

        try {
            const response = await fetch('/api/entry-reprocess.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    _csrf: csrfToken,
                    entry_uid: uid,
                    pipeline_stage_ids: selectedStageIds,
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error((data && data.error) ? data.error : 'Reprocess failed');
            }

            meta.className = 'muted';
            meta.textContent = 'Reprocess queued for ' + uid;
            await loadQueue();
        } catch (error) {
            meta.className = 'error';
            meta.textContent = error instanceof Error ? error.message : 'Unable to queue reprocess';
        } finally {
            if (button) {
                button.disabled = false;
                button.textContent = originalText;
            }
        }
    }

    async function requestAutobotDrain(workerName, chip) {
        const name = String(workerName || '').trim();
        if (!isAdmin || name === '') {
            return;
        }

        const confirmed = window.confirm('Request graceful stop for ' + name + '? It will finish its current task first.');
        if (!confirmed) {
            return;
        }

        chip.disabled = true;
        const originalText = chip.textContent;
        chip.textContent = 'Stopping...';

        try {
            const response = await fetch('/api/autobot-drain.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    _csrf: csrfToken,
                    worker_name: name,
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error((data && data.error) ? data.error : 'Unable to request autobot drain');
            }
            meta.className = 'muted';
            meta.textContent = 'Drain requested for ' + name;
            await loadQueue();
        } catch (error) {
            chip.disabled = false;
            chip.textContent = originalText;
            meta.className = 'error';
            meta.textContent = error instanceof Error ? error.message : 'Unable to request autobot drain';
        }
    }

    async function copyCommand(commandText) {
        const tip = serverOsFamily === 'WINDOWS'
            ? 'Copied. Run in an elevated Command Prompt/PowerShell (Run as Administrator).'
            : 'Copied. Run in a shell inside the app container/host with appropriate permissions.';
        try {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                await navigator.clipboard.writeText(commandText);
                workerFixNote.textContent = tip;
                return;
            }
        } catch (_error) {
            // Continue to fallback.
        }

        // Fallback for older browser contexts.
        const temp = document.createElement('textarea');
        temp.value = commandText;
        document.body.appendChild(temp);
        temp.select();
        document.execCommand('copy');
        document.body.removeChild(temp);
        workerFixNote.textContent = tip;
    }

    async function requestWorkerReconcile(button) {
        if (!isAdmin) {
            return;
        }

        const confirmed = window.confirm('Reconcile stale/orphaned processing locks now?');
        if (!confirmed) {
            return;
        }

        const originalText = button ? button.textContent : 'Reconcile Stale Locks';
        if (button) {
            button.disabled = true;
            button.textContent = 'Reconciling...';
        }

        try {
            const response = await fetch('/api/worker-reconcile.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ _csrf: csrfToken }),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error((data && data.error) ? data.error : 'Unable to reconcile worker locks');
            }

            const staleCount = Number(data.requeued_stale_locks || 0);
            const orphanCount = Number(data.requeued_orphan_locks || 0);
            const stopCount = Number(data.stopped_stale_workers || 0);
            meta.className = 'muted';
            meta.textContent = 'Reconcile complete: stale=' + staleCount + ', orphan=' + orphanCount + ', stopped_workers=' + stopCount;
            await loadQueue();
        } catch (error) {
            meta.className = 'error';
            meta.textContent = error instanceof Error ? error.message : 'Unable to reconcile worker locks';
        } finally {
            if (button) {
                button.disabled = false;
                button.textContent = originalText;
            }
        }
    }

    async function requestWorkerForceKill(target, button) {
        if (!isAdmin) {
            return;
        }

        const normalizedTarget = String(target || '').trim();
        if (normalizedTarget !== 'autobots' && normalizedTarget !== 'stale_autobots' && normalizedTarget !== 'orphaned_autobots' && normalizedTarget !== 'optimus') {
            return;
        }

        if (normalizedTarget === 'optimus') {
            const nowMs = Date.now();
            if (nowMs < optimusKillCooldownUntilMs) {
                const secondsLeft = Math.max(1, Math.ceil((optimusKillCooldownUntilMs - nowMs) / 1000));
                meta.className = 'muted';
                meta.textContent = 'Force Kill Optimus is cooling down. Try again in ' + secondsLeft + 's.';
                return;
            }
        }

        let confirmText = 'Force kill Optimus now? It will stop spawning/coordination immediately.';
        if (normalizedTarget === 'autobots') {
            confirmText = 'Force kill ALL active autobots now? Active processing locks will be requeued.';
        } else if (normalizedTarget === 'stale_autobots') {
            confirmText = 'Force kill stale autobots now? Any processing locks they still hold will be requeued.';
        } else if (normalizedTarget === 'orphaned_autobots') {
            confirmText = 'Force kill orphaned autobots now? Any orphaned processing locks will be requeued.';
        }
        if (!window.confirm(confirmText)) {
            return;
        }

        const originalText = button ? button.textContent : '';
        const cooldownSeconds = 8;
        if (button) {
            button.disabled = true;
            button.textContent = normalizedTarget === 'optimus'
                ? 'Killing Optimus...'
                : (normalizedTarget === 'stale_autobots'
                    ? 'Killing Stale Autobots...'
                    : (normalizedTarget === 'orphaned_autobots' ? 'Killing Orphaned Autobots...' : 'Killing Autobots...'));
        }

        try {
            const response = await fetch('/api/worker-force-kill.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    _csrf: csrfToken,
                    target: normalizedTarget,
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error((data && data.error) ? data.error : 'Unable to force-kill worker process(es)');
            }

            const kill = (data && data.kill && typeof data.kill === 'object') ? data.kill : {};
            const requested = Number(kill.requested || 0);
            const killed = Number(kill.killed || 0);
            const requeued = Number(data.requeued_processing_jobs || 0);
            const summary = normalizedTarget === 'optimus'
                ? ('Optimus kill complete: requested=' + requested + ', killed=' + killed)
                : (normalizedTarget === 'stale_autobots'
                    ? ('Stale autobot kill complete: requested=' + requested + ', killed=' + killed + ', requeued=' + requeued)
                    : (normalizedTarget === 'orphaned_autobots'
                        ? ('Orphaned autobot kill complete: requested=' + requested + ', killed=' + killed + ', requeued=' + requeued)
                        : ('Autobot kill complete: requested=' + requested + ', killed=' + killed + ', requeued=' + requeued)));
            meta.className = 'muted';
            meta.textContent = summary;
            await loadQueue();
        } catch (error) {
            meta.className = 'error';
            meta.textContent = error instanceof Error ? error.message : 'Unable to force-kill worker process(es)';
        } finally {
            if (button) {
                if (normalizedTarget === 'optimus') {
                    optimusKillCooldownUntilMs = Date.now() + (cooldownSeconds * 1000);
                    const cooldownEndMs = optimusKillCooldownUntilMs;

                    const tick = () => {
                        const remaining = Math.ceil((cooldownEndMs - Date.now()) / 1000);
                        if (remaining <= 0) {
                            if (optimusKillCooldownUntilMs <= Date.now()) {
                                button.disabled = false;
                                button.textContent = originalText;
                            }
                            return;
                        }

                        button.disabled = true;
                        button.textContent = 'Force Kill Optimus (' + remaining + 's)';
                        window.setTimeout(tick, 250);
                    };

                    tick();
                } else {
                    button.disabled = false;
                    button.textContent = originalText;
                }
            }
        }
    }

    async function requestStaleAutobotForceKill(workerName, chip) {
        if (!isAdmin) {
            return;
        }

        const name = String(workerName || '').trim();
        if (name === '') {
            return;
        }

        if (!window.confirm('Force kill stale autobot ' + name + '?')) {
            return;
        }

        const originalText = chip ? chip.textContent : '';
        if (chip) {
            chip.disabled = true;
            chip.textContent = 'Killing...';
        }

        try {
            const response = await fetch('/api/worker-force-kill.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    _csrf: csrfToken,
                    target: 'stale_autobot_worker',
                    worker_name: name,
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error((data && data.error) ? data.error : 'Unable to force-kill stale autobot');
            }

            const kill = (data && data.kill && typeof data.kill === 'object') ? data.kill : {};
            const requested = Number(kill.requested || 0);
            const killed = Number(kill.killed || 0);
            const requeued = Number(data.requeued_processing_jobs || 0);
            meta.className = 'muted';
            meta.textContent = 'Stale autobot kill complete for ' + name + ': requested=' + requested + ', killed=' + killed + ', requeued=' + requeued;
            await loadQueue();
        } catch (error) {
            if (chip) {
                chip.disabled = false;
                chip.textContent = originalText;
            }
            meta.className = 'error';
            meta.textContent = error instanceof Error ? error.message : 'Unable to force-kill stale autobot';
        }
    }

    async function requestWorkerForceRestart(button) {
        if (!isAdmin) {
            return;
        }

        const nowMs = Date.now();
        if (nowMs < optimusRestartCooldownUntilMs) {
            const secondsLeft = Math.max(1, Math.ceil((optimusRestartCooldownUntilMs - nowMs) / 1000));
            meta.className = 'muted';
            meta.textContent = 'Force Restart Optimus is cooling down. Try again in ' + secondsLeft + 's.';
            return;
        }

        const confirmed = window.confirm('Force restart Optimus now? This will kill the current process and immediately start a new one.');
        if (!confirmed) {
            return;
        }

        const originalText = button ? button.textContent : 'Force Restart Optimus';
        const cooldownSeconds = 8;
        if (button) {
            button.disabled = true;
            button.textContent = 'Restarting Optimus...';
        }

        try {
            const response = await fetch('/api/worker-force-restart.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    _csrf: csrfToken,
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error((data && data.error) ? data.error : 'Unable to force restart Optimus');
            }

            const kill = (data && data.kill && typeof data.kill === 'object') ? data.kill : {};
            const requested = Number(kill.requested || 0);
            const killed = Number(kill.killed || 0);
            const startRequested = data.start_requested === true;
            meta.className = 'muted';
            meta.textContent = 'Optimus restart requested: killed=' + killed + '/' + requested + ', start_requested=' + (startRequested ? 'yes' : 'no');
            await loadQueue();
        } catch (error) {
            meta.className = 'error';
            meta.textContent = error instanceof Error ? error.message : 'Unable to force restart Optimus';
        } finally {
            if (button) {
                optimusRestartCooldownUntilMs = Date.now() + (cooldownSeconds * 1000);
                const cooldownEndMs = optimusRestartCooldownUntilMs;

                const tick = () => {
                    const remaining = Math.ceil((cooldownEndMs - Date.now()) / 1000);
                    if (remaining <= 0) {
                        if (optimusRestartCooldownUntilMs <= Date.now()) {
                            button.disabled = false;
                            button.textContent = originalText;
                        }
                        return;
                    }

                    button.disabled = true;
                    button.textContent = 'Force Restart Optimus (' + remaining + 's)';
                    window.setTimeout(tick, 250);
                };

                tick();
            }
        }
    }

    function openFailedResetModal() {
        if (!failedResetModal || !failedResetModalForm) {
            return Promise.resolve(null);
        }

        const firstMode = failedResetModalForm.querySelector('input[name="failed_reset_mode"][value="all_jobs"]');
        if (firstMode instanceof HTMLInputElement) {
            firstMode.checked = true;
            firstMode.focus();
        }
        if (failedResetModalError) {
            failedResetModalError.textContent = '';
        }
        failedResetModal.hidden = false;

        return new Promise((resolve) => {
            const close = (selection) => {
                failedResetModal.hidden = true;
                failedResetModal.removeEventListener('click', onOverlayClick);
                document.removeEventListener('keydown', onKeydown);
                failedResetModalForm.removeEventListener('submit', onSubmit);
                failedResetModalCancel.removeEventListener('click', onCancel);
                resolve(selection);
            };

            const onCancel = () => close(null);
            const onOverlayClick = (event) => {
                if (event.target === failedResetModal) {
                    close(null);
                }
            };
            const onKeydown = (event) => {
                if (event.key === 'Escape') {
                    close(null);
                }
            };
            const onSubmit = (event) => {
                event.preventDefault();
                const selected = failedResetModalForm.querySelector('input[name="failed_reset_mode"]:checked');
                const mode = selected instanceof HTMLInputElement ? String(selected.value || '').trim() : '';
                if (mode !== 'all_jobs' && mode !== 'remaining_failed_jobs' && mode !== 'remaining_unresolved_failed_jobs') {
                    if (failedResetModalError) {
                        failedResetModalError.textContent = 'Choose a reset mode.';
                    }
                    return;
                }
                close(mode);
            };

            failedResetModal.addEventListener('click', onOverlayClick);
            document.addEventListener('keydown', onKeydown);
            failedResetModalForm.addEventListener('submit', onSubmit);
            failedResetModalCancel.addEventListener('click', onCancel);
        });
    }

    async function requestResetFailedJobs(button) {
        if (!isAdmin) {
            return;
        }

        const resetMode = await openFailedResetModal();
        if (resetMode !== 'all_jobs' && resetMode !== 'remaining_failed_jobs' && resetMode !== 'remaining_unresolved_failed_jobs') {
            return;
        }

        const confirmText = resetMode === 'all_jobs'
            ? 'Reset FAILED jobs to queued and reprocess ALL stages for each job?'
            : (resetMode === 'remaining_unresolved_failed_jobs'
                ? 'Reset only unresolved FAILED jobs to queued and reprocess remaining/failed stages?'
                : 'Reset FAILED jobs to queued and reprocess only remaining/failed stages?');
        if (!window.confirm(confirmText)) {
            return;
        }

        const originalText = button ? button.textContent : 'Reset Failed Jobs';
        if (button) {
            button.disabled = true;
            button.textContent = 'Resetting Failed Jobs...';
        }

        try {
            const response = await fetch('/api/worker-reset-failed.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    _csrf: csrfToken,
                    reset_mode: resetMode,
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error((data && data.error) ? data.error : 'Unable to reset failed jobs');
            }

            const resetCount = Number(data.reset_jobs || 0);
            const stageUpdateCount = Number(data.entries_marked_in_process || 0);
            const skippedCount = Number(data.skipped_superseded_failed_jobs || 0);
            meta.className = 'muted';
            meta.textContent = 'Failed-job reset complete: jobs=' + resetCount + ', entries updated=' + stageUpdateCount + ', skipped_superseded=' + skippedCount;
            await loadQueue();
        } catch (error) {
            meta.className = 'error';
            meta.textContent = error instanceof Error ? error.message : 'Unable to reset failed jobs';
        } finally {
            if (button) {
                button.disabled = false;
                button.textContent = originalText;
            }
        }
    }

    async function loadQueue() {
        try {
            const response = await fetch('/api/status-queue.php?page=' + encodeURIComponent(String(currentPage)), {
                method: 'GET',
                headers: { 'Accept': 'application/json' }
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error((data && data.error) ? data.error : 'Unable to load status');
            }

            const rows = Array.isArray(data.rows) ? data.rows : [];
            const retention = data.retention_hours || {};
            const summary = data.summary || {};
            const health = data.health || {};
            const statusCards = data.status_cards || {};
            const pagination = data.pagination || {};
            currentPage = Math.max(1, Number(pagination.page || currentPage));
            totalPages = Math.max(1, Number(pagination.total_pages || 1));
            const totalRows = Math.max(0, Number(pagination.total_rows || rows.length));

            pageLabelTop.textContent = 'Page ' + currentPage + ' / ' + totalPages + ' (30 per page)';
            pageLabelBottom.textContent = pageLabelTop.textContent;
            pagePrevTop.disabled = currentPage <= 1;
            pagePrevBottom.disabled = currentPage <= 1;
            pageNextTop.disabled = currentPage >= totalPages;
            pageNextBottom.disabled = currentPage >= totalPages;
            pageJumpTop.max = String(totalPages);
            pageJumpBottom.max = String(totalPages);
            pageJumpTop.value = String(currentPage);
            pageJumpBottom.value = String(currentPage);

            const pageParams = new URLSearchParams(window.location.search);
            pageParams.set('page', String(currentPage));
            const nextUrl = window.location.pathname + '?' + pageParams.toString();
            window.history.replaceState(null, '', nextUrl);

            meta.textContent = 'Rows on page: ' + rows.length +
                ' | total rows: ' + totalRows +
                ' | retention (completed/failed/log hours): ' + String(retention.completed || '?') + '/' + String(retention.failed || '?') + '/' + String(retention.orchestrator_logs || '?') +
                ' | updated: ' + formatDateTime(data.generated_at || '');
            meta.className = 'muted';

            cardQueued.textContent = String(summary.queued_jobs ?? 0);
            cardFailed.textContent = String(summary.failed_jobs ?? 0);
            cardLlm.textContent = String(summary.awaiting_llm ?? 0);
            cardTasksRemaining.textContent = String(summary.total_tasks_remaining ?? 0);
            const remainingByStageRaw = (summary.remaining_by_stage && typeof summary.remaining_by_stage === 'object')
                ? summary.remaining_by_stage
                : {};
            const remainingStageOrder = [
                ['meta_group_0', 'Meta Group 0'],
                ['meta_group_1', 'Meta Group 1'],
                ['meta_group_2_llm', 'Meta Group 2 (LLM)'],
                ['meta_group_3_weather', 'Meta Group 3 (Weather)'],
            ];
            const remainingBreakdown = remainingStageOrder
                .map(([stageId, label]) => {
                    const count = Math.max(0, Number(remainingByStageRaw[stageId] || 0));
                    return '<li>' + escapeHtml(label) + ': ' + count + '</li>';
                })
                .join('');
            cardTasksRemainingBreakdown.innerHTML = '<ul>' + remainingBreakdown + '</ul>';
            cardActiveAutobots.textContent = String(summary.active_autobots ?? 0);
            cardStaleAutobots.textContent = String(summary.stale_autobots ?? 0);
            const completeFinalTotal = Math.max(0, Number(summary.complete_final_total ?? 0));
            const completeFinalWithoutLlm = Math.max(0, Number(summary.complete_final_without_llm ?? 0));
            const modelCountsRaw = (summary.complete_final_llm_models && typeof summary.complete_final_llm_models === 'object')
                ? summary.complete_final_llm_models
                : {};
            const modelCounts = Object.entries(modelCountsRaw)
                .map(([model, count]) => ({
                    model: String(model || '').trim(),
                    count: Math.max(0, Number(count || 0)),
                }))
                .filter((item) => item.model !== '' && item.count > 0)
                .sort((a, b) => (b.count - a.count) || a.model.localeCompare(b.model));

            cardCompleteFinalTotal.textContent = String(completeFinalTotal);
            if (completeFinalTotal <= 0) {
                cardLlmModelBreakdown.innerHTML = '<ul><li>No COMPLETE/FINAL entries yet.</li></ul>';
            } else {
                const items = [];
                if (modelCounts.length === 0) {
                    items.push('<li>Models: none</li>');
                } else {
                    modelCounts.forEach((item) => {
                        const href = '/dashboards/llm-model-entries.php?model=' + encodeURIComponent(item.model);
                        items.push('<li><a href="' + href + '" target="_blank" rel="noopener">' + escapeHtml(item.model) + ' (' + item.count + ')</a></li>');
                    });
                }
                cardLlmModelBreakdown.innerHTML = '<ul>' + items.join('') + '</ul>';
            }

            const activeAutobotWorkers = Array.isArray(summary.active_autobot_workers) ? summary.active_autobot_workers : [];
            if (activeAutobotWorkers.length === 0) {
                cardActiveAutobotChipList.innerHTML = '<span class="muted">none</span>';
            } else {
                cardActiveAutobotChipList.innerHTML = activeAutobotWorkers.map((worker) => {
                    const workerName = String(worker.worker_name || '').trim();
                    const stageId = String(worker.stage_id || '').trim();
                    const ageText = formatAge(worker.age_seconds);
                    const drainRequested = worker.drain_requested === true;
                    const labelParts = [workerName];
                    if (stageId !== '') {
                        labelParts.push('[' + stageId + ']');
                    }
                    labelParts.push(ageText);
                    const classes = ['autobot-chip'];
                    if (isAdmin && !drainRequested) {
                        classes.push('clickable');
                    }
                    if (drainRequested) {
                        classes.push('pending');
                    }
                    const attrs = [
                        'class="' + classes.join(' ') + '"',
                        'title="' + escapeHtml(drainRequested ? 'Stop requested; waiting for task completion' : (isAdmin ? 'Click to stop after current task' : 'Active autobot')) + '"',
                        'data-worker-name="' + escapeHtml(workerName) + '"',
                    ];
                    if (drainRequested || !isAdmin) {
                        attrs.push('data-drain-locked="1"');
                    }
                    return '<button type="button" ' + attrs.join(' ') + '>' + escapeHtml(labelParts.join(' ')) + '</button>';
                }).join('');
            }
            const staleAutobotWorkers = Array.isArray(summary.stale_autobot_workers) ? summary.stale_autobot_workers : [];
            if (staleAutobotWorkers.length === 0) {
                cardStaleAutobotChipList.innerHTML = '<span class="muted">none</span>';
            } else {
                cardStaleAutobotChipList.innerHTML = staleAutobotWorkers.map((worker) => {
                    const workerName = String(worker.worker_name || '').trim();
                    const stageId = String(worker.stage_id || '').trim();
                    const ageText = formatAge(worker.age_seconds);
                    const labelParts = [workerName];
                    if (stageId !== '') {
                        labelParts.push('[' + stageId + ']');
                    }
                    labelParts.push(ageText);
                    const classes = ['autobot-chip'];
                    const attrs = [
                        'class="' + classes.join(' ') + (isAdmin ? ' clickable' : '') + '"',
                        'title="' + escapeHtml(isAdmin ? 'Click to force-kill this stale autobot' : 'Stale autobot candidate') + '"',
                        'data-stale-worker-name="' + escapeHtml(workerName) + '"',
                    ];
                    return '<button type="button" ' + attrs.join(' ') + '>' + escapeHtml(labelParts.join(' ')) + '</button>';
                }).join('');
            }
            cardInProcessMissing.textContent = String(summary.in_process_missing_metadata ?? 0);
            cardAge.textContent = formatAge(summary.avg_active_age_seconds ?? 0);
            const reconcileLastAt = String(summary.worker_reconcile_last_at || '').trim();
            const reconcileLastBy = String(summary.worker_reconcile_last_by || '').trim();
            cardReconcileAt.textContent = reconcileLastAt !== '' ? formatDateTime(reconcileLastAt) : 'Never';
            cardReconcileBy.textContent = reconcileLastBy !== '' ? ('By: ' + reconcileLastBy) : 'By: -';

            const db = health.database || { status: 'fail', detail: 'unknown' };
            const ollama = health.ollama || { status: 'fail', detail: 'unknown' };
            const worker = health.worker || { status: 'fail', detail: 'unknown' };
            const phpExtensions = health.php_extensions || { status: 'fail', detail: 'unknown', checks: [] };
            const phpExtensionChecks = Array.isArray(phpExtensions.checks) ? phpExtensions.checks : [];

            const dbCard = (statusCards.database && typeof statusCards.database === 'object') ? statusCards.database : {};
            const ollamaCard = (statusCards.ollama && typeof statusCards.ollama === 'object') ? statusCards.ollama : {};
            const optimusCard = (statusCards.optimus && typeof statusCards.optimus === 'object') ? statusCards.optimus : {};
            const webEngineCard = (statusCards.web_engine && typeof statusCards.web_engine === 'object') ? statusCards.web_engine : {};

            if (healthDb) {
                const databasePills = [
                    renderPill(db.status, 'DB', String(db.detail || '')),
                    renderMutedPill('MySQL DB Version', String(dbCard.mysql_version || 'unknown')),
                    renderMutedPill('App DB Version', String(dbCard.application_db_version || 'unknown')),
                    renderMutedPill('Schema Migrations', String(dbCard.schema_migrations_applied || '0')),
                    renderMutedPill('Database Name', String(dbCard.database_name || 'unknown')),
                ];
                healthDb.innerHTML = databasePills.join('');
            }

            if (healthOllama) {
                const ollamaPills = [
                    renderPill(ollama.status, 'Ollama', String(ollama.detail || '')),
                    renderMutedPill('Model', String(ollamaCard.selected_model || 'not configured')),
                    renderMutedPill('Timeout', String(ollamaCard.timeout_seconds || '?') + 's'),
                    renderMutedPill('Retry', String(ollamaCard.retry_seconds || '?') + 's'),
                ];
                healthOllama.innerHTML = ollamaPills.join('');
            }

            if (healthOptimus) {
                const autobotHealth = (optimusCard.autobot_health && typeof optimusCard.autobot_health === 'object')
                    ? optimusCard.autobot_health
                    : { status: 'fail', running: 0, stale: 0, orphaned: 0 };
                const autobotHealthText = 'running=' + String(autobotHealth.running || 0) +
                    ' | stale=' + String(autobotHealth.stale || 0) +
                    ' | orphaned=' + String(autobotHealth.orphaned || 0);
                const optimusPills = [
                    renderPill(worker.status, 'Optimus', String(worker.detail || '')),
                    renderPill(String(autobotHealth.status || 'fail'), 'Autobot Health', autobotHealthText),
                    renderMutedPill('Heartbeat Threshold', String(optimusCard.heartbeat_threshold_seconds || '?') + 's'),
                ];
                healthOptimus.innerHTML = optimusPills.join('');
            }

            if (healthWebEngine) {
                const orderedExtensions = normalizePhpExtensionChecks(phpExtensionChecks);
                const webEnginePills = [
                    renderMutedPill('PHP Version', String(webEngineCard.php_version || 'unknown')),
                    renderMutedPill('PHP SAPI', String(webEngineCard.php_sapi || 'unknown')),
                    renderMutedPill('Web Server', String(webEngineCard.server_software_name || 'unknown')),
                    renderMutedPill('Web Server Version', String(webEngineCard.server_software_version || 'n/a')),
                    renderMutedPill('Memory Limit', String(webEngineCard.memory_limit || 'n/a')),
                    renderMutedPill('Upload Max', String(webEngineCard.upload_max_filesize || 'n/a')),
                    renderMutedPill('POST Max', String(webEngineCard.post_max_size || 'n/a')),
                ];
                orderedExtensions.forEach((check) => {
                    webEnginePills.push(renderPill(check.status, String(check.name || '').toUpperCase(), ''));
                });
                healthWebEngine.innerHTML = webEnginePills.join('');
            }

            if (rows.length === 0) {
                body.innerHTML = '<tr><td colspan="7" class="muted">No queue rows to show.</td></tr>';
                return;
            }

            const html = rows.map((row) => {
                const status = String(row.status || '').toLowerCase();
                const statusClass = 'pill pill-' + (status === 'queued' || status === 'processing' || status === 'completed' || status === 'failed' ? status : 'queued');
                const comment = String(row.queue_comment || '').trim();
                const stage = String(row.stage_label || '').trim();
                const label = stage === '' ? '(n/a)' : stage;
                const entryUid = String(row.entry_uid || '').trim();
                const remaining = Array.isArray(row.remaining_stages) ? row.remaining_stages : [];
                const chips = remaining.length === 0
                    ? '<span class="muted">none</span>'
                    : remaining.map((value) => '<span class="chip">' + escapeHtml(String(value)) + '</span>').join('');
                const isQueuedRow = status === 'queued' || label.toLowerCase() === 'queued';
                const processingMetric = (() => {
                    if (status !== 'processing') {
                        return '';
                    }
                    const cleanedStage = label.replace(/^processing\s+/i, '').trim();
                    if (cleanedStage !== '' && cleanedStage.toLowerCase() !== '(n/a)') {
                        return cleanedStage;
                    }
                    if (remaining.length > 0) {
                        return String(remaining[0]);
                    }
                    return 'working';
                })();
                const processingChip = status === 'processing'
                    ? '<span class="chip chip-stage-processing">IN PROCESS: ' + escapeHtml(processingMetric) + '</span>'
                    : '';
                const reprocessAction = entryUid === '' || isQueuedRow
                    ? ''
                    : '<div class="row-actions"><button type="button" class="reprocess-entry-btn" data-entry-uid="' + escapeHtml(entryUid) + '">Set Reprocess</button></div>';
                return '<tr>' +
                    '<td>' + escapeHtml(formatDateTime(row.submitted_at || '')) + '</td>' +
                    '<td><code>' + escapeHtml(entryUid) + '</code></td>' +
                    '<td>' + escapeHtml(String(row.submitter || '')) + '</td>' +
                    '<td>' + escapeHtml(label) + '</td>' +
                    '<td>' + chips + '</td>' +
                    '<td>' + escapeHtml(formatAge(row.age_seconds)) + '</td>' +
                    '<td><span class="' + statusClass + '">' + escapeHtml(status || 'unknown') + '</span> ' + processingChip + escapeHtml(comment) + reprocessAction + '</td>' +
                '</tr>';
            }).join('');
            body.innerHTML = html;
        } catch (error) {
            meta.textContent = error instanceof Error ? error.message : 'Failed to load queue status';
            meta.className = 'error';
            if (healthDb) {
                healthDb.innerHTML = '<span class="health-chip">Health checks unavailable</span>';
            }
            if (healthOllama) {
                healthOllama.innerHTML = '<span class="health-chip">Health checks unavailable</span>';
            }
            if (healthOptimus) {
                healthOptimus.innerHTML = '<span class="health-chip">Health checks unavailable</span>';
            }
            if (healthWebEngine) {
                healthWebEngine.innerHTML = '<span class="health-chip">Health checks unavailable</span>';
            }
            cardQueued.textContent = '-';
            cardFailed.textContent = '-';
            cardLlm.textContent = '-';
            cardTasksRemaining.textContent = '-';
            cardTasksRemainingBreakdown.textContent = '-';
            cardActiveAutobots.textContent = '-';
            cardActiveAutobotChipList.innerHTML = '<span class="muted">n/a</span>';
            cardStaleAutobots.textContent = '-';
            cardStaleAutobotChipList.innerHTML = '<span class="muted">n/a</span>';
            cardCompleteFinalTotal.textContent = '-';
            cardLlmModelBreakdown.textContent = '-';
            cardInProcessMissing.textContent = '-';
            cardAge.textContent = '-';
            cardReconcileAt.textContent = '-';
            cardReconcileBy.textContent = '-';
            body.innerHTML = '<tr><td colspan="7" class="error">Status load failed.</td></tr>';
        }
    }

    function goToPage(nextPage) {
        const target = Math.max(1, Math.min(totalPages, nextPage));
        if (target === currentPage) {
            return;
        }
        currentPage = target;
        loadQueue();
    }

    function jumpFromInput(input) {
        const raw = Number.parseInt(String(input.value || '').trim(), 10);
        if (!Number.isFinite(raw)) {
            input.value = String(currentPage);
            return;
        }
        goToPage(raw);
    }

    loadQueue();
    setInterval(loadQueue, 5000);
    pagePrevTop.addEventListener('click', () => goToPage(currentPage - 1));
    pagePrevBottom.addEventListener('click', () => goToPage(currentPage - 1));
    pageNextTop.addEventListener('click', () => goToPage(currentPage + 1));
    pageNextBottom.addEventListener('click', () => goToPage(currentPage + 1));
    pageJumpTopButton.addEventListener('click', () => jumpFromInput(pageJumpTop));
    pageJumpBottomButton.addEventListener('click', () => jumpFromInput(pageJumpBottom));
    pageJumpTop.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            jumpFromInput(pageJumpTop);
        }
    });
    pageJumpBottom.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            jumpFromInput(pageJumpBottom);
        }
    });
    workerFixCopyButton.addEventListener('click', () => copyCommand(workerFixCommand));
    workerStartCopyButton.addEventListener('click', () => copyCommand(workerStartCommand));
    workerResetCopyButton.addEventListener('click', () => copyCommand(workerResetCommand));
    if (reprocessModalSelectAll) {
        reprocessModalSelectAll.addEventListener('click', () => setAllReprocessStageSelections(true));
    }
    if (reprocessModalClearAll) {
        reprocessModalClearAll.addEventListener('click', () => setAllReprocessStageSelections(false));
    }
    if (workerReconcileButton) {
        workerReconcileButton.addEventListener('click', () => requestWorkerReconcile(workerReconcileButton));
    }
    if (workerKillAutobotsButton) {
        workerKillAutobotsButton.addEventListener('click', () => requestWorkerForceKill('autobots', workerKillAutobotsButton));
    }
    if (workerKillStaleAutobotsButton) {
        workerKillStaleAutobotsButton.addEventListener('click', () => requestWorkerForceKill('stale_autobots', workerKillStaleAutobotsButton));
    }
    if (workerKillOrphanedAutobotsButton) {
        workerKillOrphanedAutobotsButton.addEventListener('click', () => requestWorkerForceKill('orphaned_autobots', workerKillOrphanedAutobotsButton));
    }
    if (workerKillOptimusButton) {
        workerKillOptimusButton.addEventListener('click', () => requestWorkerForceKill('optimus', workerKillOptimusButton));
    }
    if (workerRestartOptimusButton) {
        workerRestartOptimusButton.addEventListener('click', () => requestWorkerForceRestart(workerRestartOptimusButton));
    }
    if (workerResetFailedJobsButton) {
        workerResetFailedJobsButton.addEventListener('click', () => requestResetFailedJobs(workerResetFailedJobsButton));
    }
    cardActiveAutobotChipList.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }
        const chip = target.closest('button.autobot-chip');
        if (!(chip instanceof HTMLButtonElement)) {
            return;
        }
        if (!isAdmin || chip.getAttribute('data-drain-locked') === '1') {
            return;
        }
        const workerName = chip.getAttribute('data-worker-name');
        requestAutobotDrain(workerName, chip);
    });
    cardStaleAutobotChipList.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }
        const chip = target.closest('button.autobot-chip');
        if (!(chip instanceof HTMLButtonElement) || !isAdmin) {
            return;
        }
        const workerName = chip.getAttribute('data-stale-worker-name');
        requestStaleAutobotForceKill(workerName, chip);
    });
    body.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }
        const button = target.closest('button.reprocess-entry-btn');
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        const entryUid = button.getAttribute('data-entry-uid');
        requestReprocess(entryUid, button);
    });
</script>
</main>
</body>
</html>
