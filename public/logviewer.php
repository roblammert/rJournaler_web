<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__) . '/app/Auth/require_auth.php';
require_once dirname(__DIR__) . '/app/Auth/require_admin.php';

use App\Auth\Auth;

$userTimeZone = Auth::timezonePreference() ?? date_default_timezone_get();
$interfaceTheme = Auth::interfaceTheme();

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Optimus Log Viewer</title>
    <style>
        :root {
            --font-ui: "Segoe UI", "Aptos", "Trebuchet MS", sans-serif;
            --font-mono: Consolas, "Cascadia Mono", "Courier New", monospace;
            --radius-md: 10px;
            --shadow-md: 0 12px 28px rgba(17, 24, 39, 0.14);
            --shadow-sm: 0 4px 12px rgba(17, 24, 39, 0.08);
        }

        body[data-theme="light"] {
            --bg: #f7f9fc;
            --bg-accent: #edf3fb;
            --surface: #ffffff;
            --surface-soft: #f7faff;
            --surface-log: #f3f8ff;
            --border: #d6e0ef;
            --text: #1f2f43;
            --text-muted: #5b6f87;
            --heading: #152334;
            --link: #1f5f9a;
            --line-sep: #d4e0f2;
            --lvl-debug: #66778d;
            --lvl-info: #1f5f9a;
            --lvl-warn: #946700;
            --lvl-error: #962d3f;
            --lvl-critical: #7f1631;
        }

        body[data-theme="neutral"] {
            --bg: #f4f3f1;
            --bg-accent: #eceae6;
            --surface: #fffdf8;
            --surface-soft: #f4f1ea;
            --surface-log: #f7f3ec;
            --border: #d8d1c5;
            --text: #2f3432;
            --text-muted: #676d69;
            --heading: #222827;
            --link: #3c5f72;
            --line-sep: #d8d1c5;
            --lvl-debug: #69716c;
            --lvl-info: #345769;
            --lvl-warn: #8a6f2c;
            --lvl-error: #874848;
            --lvl-critical: #6d3232;
        }

        body[data-theme="dark"] {
            --bg: #171d23;
            --bg-accent: #1e2730;
            --surface: #222d38;
            --surface-soft: #263340;
            --surface-log: #1a242f;
            --border: #364757;
            --text: #dbe4ec;
            --text-muted: #98a9b9;
            --heading: #f1f5f8;
            --link: #8fc3f3;
            --line-sep: #2e3f50;
            --lvl-debug: #a0b1c1;
            --lvl-info: #8fc3f3;
            --lvl-warn: #e2c56f;
            --lvl-error: #f0a6b3;
            --lvl-critical: #f392aa;
            --shadow-md: 0 14px 32px rgba(0, 0, 0, 0.35);
            --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.28);
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
            margin-bottom: 0.55rem;
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

        .muted { color: var(--text-muted); }

        .toolbar {
            display: flex;
            gap: 0.65rem;
            align-items: center;
            flex-wrap: wrap;
            margin: 0.45rem 0 0.7rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface);
            box-shadow: var(--shadow-sm);
            padding: 0.52rem;
        }

        button {
            font: inherit;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface-soft);
            color: var(--text);
            cursor: pointer;
            padding: 0.26rem 0.6rem;
        }

        button:hover {
            filter: brightness(0.98);
        }

        .log-shell {
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface-log);
            box-shadow: var(--shadow-md);
            height: calc(100vh - 215px);
            overflow: auto;
            padding: 0.45rem;
            font-family: var(--font-mono);
        }

        .line {
            white-space: pre-wrap;
            border-bottom: 1px dashed var(--line-sep);
            padding: 0.22rem 0.1rem;
            overflow-wrap: anywhere;
        }

        .lvl-debug { color: var(--lvl-debug); }
        .lvl-info { color: var(--lvl-info); }
        .lvl-warn { color: var(--lvl-warn); }
        .lvl-error { color: var(--lvl-error); }
        .lvl-critical { color: var(--lvl-critical); }

        a {
            color: var(--link);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        @media (max-width: 700px) {
            body { padding: 0.72rem; }
            .log-shell { height: calc(100vh - 235px); }
        }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars($interfaceTheme, ENT_QUOTES, 'UTF-8'); ?>">
    <main>
        <header class="page-header">
            <h1>Optimus Log Viewer</h1>
            <div class="header-links">
                <a class="pill" href="/index.php">Back to Dashboard</a>
                <a class="pill" href="/admin-settings.php">Admin Settings</a>
                <span class="pill">Theme: <?php echo htmlspecialchars(ucfirst($interfaceTheme), ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="pill">rJournaler_Web: v<?php echo htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        </header>

        <div class="toolbar">
            <label><input type="checkbox" id="auto-scroll" checked> Auto-scroll</label>
            <button id="clear" type="button">Clear View</button>
            <span id="status" class="muted">Connecting...</span>
            <span class="pill">Timezone: <strong><?php echo htmlspecialchars($userTimeZone, ENT_QUOTES, 'UTF-8'); ?></strong></span>
        </div>

        <section id="log-shell" class="log-shell" aria-live="polite"></section>
    </main>

    <script>
        const userTimeZone = <?php echo json_encode($userTimeZone, JSON_UNESCAPED_SLASHES); ?>;
        const shell = document.getElementById('log-shell');
        const status = document.getElementById('status');
        const autoScroll = document.getElementById('auto-scroll');
        const clearButton = document.getElementById('clear');
        let sinceId = 0;
        let polling = false;

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function levelClass(level) {
            const lower = String(level || '').toLowerCase();
            if (lower === 'debug') return 'lvl-debug';
            if (lower === 'warn' || lower === 'warning') return 'lvl-warn';
            if (lower === 'error') return 'lvl-error';
            if (lower === 'critical' || lower === 'fatal') return 'lvl-critical';
            return 'lvl-info';
        }

        function formatTimestamp(rawValue) {
            const raw = String(rawValue || '').trim();
            if (raw === '') {
                return '';
            }

            // DB timestamps are stored as UTC (YYYY-MM-DD HH:MM:SS).
            const iso = raw.includes('T') ? raw : raw.replace(' ', 'T');
            const utcIso = /[zZ]|[+-]\d{2}:?\d{2}$/.test(iso) ? iso : (iso + 'Z');
            const parsed = new Date(utcIso);
            if (Number.isNaN(parsed.getTime())) {
                return raw;
            }

            return parsed.toLocaleString(undefined, {
                timeZone: userTimeZone || undefined,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true,
            });
        }

        function formatLine(row) {
            const id = Number(row.id || 0);
            const timestamp = formatTimestamp(row.created_at || '');
            const level = String(row.level || 'INFO').toUpperCase();
            const source = String(row.source || 'optimus');
            const message = String(row.message || '');
            const context = row.context_json && typeof row.context_json === 'object'
                ? ' ' + JSON.stringify(row.context_json)
                : '';
            return '<div class="line ' + levelClass(level) + '">[' + id + '] ' + escapeHtml(timestamp) + ' [' + escapeHtml(level) + '] [' + escapeHtml(source) + '] ' + escapeHtml(message) + escapeHtml(context) + '</div>';
        }

        async function poll() {
            if (polling) {
                return;
            }
            polling = true;
            try {
                const response = await fetch('/api/optimus-logs.php?since_id=' + encodeURIComponent(String(sinceId)) + '&limit=250', {
                    headers: { 'Accept': 'application/json' },
                });
                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error((data && data.error) ? data.error : 'Unable to load logs');
                }

                const rows = Array.isArray(data.rows) ? data.rows : [];
                if (rows.length > 0) {
                    const html = rows.map(formatLine).join('');
                    shell.insertAdjacentHTML('beforeend', html);
                }
                sinceId = Number(data.last_id || sinceId) || sinceId;
                status.textContent = 'Live. Last id: ' + String(sinceId);

                if (autoScroll.checked) {
                    shell.scrollTop = shell.scrollHeight;
                }
            } catch (error) {
                status.textContent = error instanceof Error ? error.message : 'Log poll failed';
            } finally {
                polling = false;
            }
        }

        clearButton.addEventListener('click', () => {
            shell.innerHTML = '';
        });

        poll();
        setInterval(poll, 2000);
    </script>
</body>
</html>
