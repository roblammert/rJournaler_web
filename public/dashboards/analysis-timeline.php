<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Auth/require_auth.php';

use App\Auth\Auth;

$userTimeZone = Auth::timezonePreference() ?? date_default_timezone_get();
$interfaceTheme = Auth::interfaceTheme();
$appVersion = (string) ($config['version'] ?? '1.0.0');
$appVersion = (string) ($config['version'] ?? '1.0.4');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Timeline Analytics</title>
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
            --control-btn-bg: #e8eef6;
            --control-btn-border: #9aabc2;
            --control-btn-text: #334b63;
            --chart-1: #2b77c4;
            --chart-1-fill: rgba(43, 119, 196, 0.2);
            --chart-2: #248f6c;
            --chart-2-fill: rgba(36, 143, 108, 0.2);
            --chart-3: #9a5fd1;
            --chart-4: #d16a5f;
            --chart-5: #2f6ea8;
            --chart-6: #b0762f;
            --chart-7: #6f6f6f;
            --chart-8: #317e42;
            --chart-9: #5f74c9;
            --chart-10: #cc8a2d;
            --chart-11: #a34d62;
            --chart-11-fill: rgba(163, 77, 98, 0.2);
            --chart-12: #4f7f2d;
            --chart-12-fill: rgba(79, 127, 45, 0.2);
            --chart-13: #7d4eb3;
            --chart-14: #297f4a;
            --chart-15: #2d6eb0;
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
            --control-btn-bg: #ebeae6;
            --control-btn-border: #aeb1ac;
            --control-btn-text: #3f4944;
            --chart-1: #3c6f95;
            --chart-1-fill: rgba(60, 111, 149, 0.2);
            --chart-2: #4f7b63;
            --chart-2-fill: rgba(79, 123, 99, 0.2);
            --chart-3: #8664a8;
            --chart-4: #b07467;
            --chart-5: #4f6f87;
            --chart-6: #9a7b4e;
            --chart-7: #7a7770;
            --chart-8: #597d62;
            --chart-9: #6d7db1;
            --chart-10: #b0865a;
            --chart-11: #976072;
            --chart-11-fill: rgba(151, 96, 114, 0.2);
            --chart-12: #5f7b4c;
            --chart-12-fill: rgba(95, 123, 76, 0.2);
            --chart-13: #7f5ea2;
            --chart-14: #4f7c61;
            --chart-15: #4a739d;
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
            --control-btn-bg: #3a4754;
            --control-btn-border: #6f8193;
            --control-btn-text: #dde6ef;
            --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.28);
            --chart-1: #6da9df;
            --chart-1-fill: rgba(109, 169, 223, 0.24);
            --chart-2: #68b89b;
            --chart-2-fill: rgba(104, 184, 155, 0.24);
            --chart-3: #b597de;
            --chart-4: #df9d92;
            --chart-5: #7fb0da;
            --chart-6: #d2a86e;
            --chart-7: #aeb8c1;
            --chart-8: #84c08c;
            --chart-9: #95a8e2;
            --chart-10: #d9b178;
            --chart-11: #d28ca0;
            --chart-11-fill: rgba(210, 140, 160, 0.22);
            --chart-12: #96be73;
            --chart-12-fill: rgba(150, 190, 115, 0.24);
            --chart-13: #ba9ae3;
            --chart-14: #88be9a;
            --chart-15: #84b1dc;
        }

        body {
            font-family: var(--font-ui);
            margin: 0;
            padding: 1rem;
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
            margin-bottom: 0.65rem;
        }

        .page-header h1 { margin: 0; color: var(--heading); }

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
        .error { color: var(--danger); }

        .controls { border: 1px solid var(--border); border-radius: 10px; background: var(--surface); box-shadow: var(--shadow-sm); padding: 0.7rem; margin-bottom: 0.9rem; }
        .control-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.55rem; align-items: end; }
        label { display: block; font-size: 0.78rem; color: var(--text-muted); margin-bottom: 0.16rem; }
        select, input, button {
            width: 100%;
            box-sizing: border-box;
            padding: 0.35rem 0.45rem;
            border: 1px solid var(--border);
            border-radius: 7px;
            background: var(--surface-soft);
            color: var(--text);
            font: inherit;
        }
        button {
            cursor: pointer;
            font-weight: 600;
            background: var(--control-btn-bg);
            border-color: var(--control-btn-border);
            color: var(--control-btn-text);
            border-radius: 999px;
        }
        button:hover { filter: brightness(1.05); }

        .actions { display: flex; gap: 0.45rem; }
        .actions button { width: auto; }
        .charts-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.7rem; }
        .chart-card { border: 1px solid var(--border); border-radius: 10px; background: var(--surface); box-shadow: var(--shadow-sm); padding: 0.62rem; min-height: 280px; }
        .chart-title { margin: 0 0 0.45rem; font-size: 0.95rem; }
        .chart-sub { margin: 0 0 0.5rem; font-size: 0.78rem; color: var(--text-muted); }
        .chart-wrap { position: relative; height: 200px; }
        @media (max-width: 1300px) { .charts-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 760px) {
            .charts-grid { grid-template-columns: 1fr; }
            .actions { flex-direction: column; }
            .actions button { width: 100%; }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
</head>
<body data-theme="<?php echo htmlspecialchars($interfaceTheme, ENT_QUOTES, 'UTF-8'); ?>">
    <main>
        <header class="page-header">
            <h1>Timeline Analytics</h1>
            <div class="header-links">
                <a class="pill" href="/index.php">Back to Dashboard</a>
                <span class="pill">Theme: <?php echo htmlspecialchars(ucfirst($interfaceTheme), ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="pill">rJournaler_Web: v<?php echo htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        </header>
        <p class="muted" id="meta">Loading timeline data...</p>

        <section class="controls" aria-label="Timeline filters">
            <div class="control-grid">
                <div>
                    <label for="range-mode">Range Mode</label>
                    <select id="range-mode">
                        <option value="days">Last N Days</option>
                        <option value="all">All Time</option>
                        <option value="custom">Custom Date Range</option>
                    </select>
                </div>
                <div id="days-group">
                    <label for="range-days">Days</label>
                    <select id="range-days">
                        <option value="30">30</option>
                        <option value="60">60</option>
                        <option value="90" selected>90</option>
                        <option value="180">180</option>
                        <option value="365">365</option>
                    </select>
                </div>
                <div id="start-group" style="display:none;">
                    <label for="start-date">Start Date</label>
                    <input id="start-date" type="date">
                </div>
                <div id="end-group" style="display:none;">
                    <label for="end-date">End Date</label>
                    <input id="end-date" type="date">
                </div>
                <div class="actions">
                    <button id="apply-filters" type="button">Apply</button>
                    <button id="export-csv" type="button">Export CSV</button>
                </div>
            </div>
        </section>

        <section class="charts-grid" aria-label="Timeline charts">
            <article class="chart-card"><h2 class="chart-title">Word Count Trend</h2><p class="chart-sub">Writing volume over time</p><div class="chart-wrap"><canvas id="chart-words"></canvas></div></article>
            <article class="chart-card"><h2 class="chart-title">Reading Time Trend</h2><p class="chart-sub">Estimated minutes per entry</p><div class="chart-wrap"><canvas id="chart-reading-time"></canvas></div></article>
            <article class="chart-card"><h2 class="chart-title">Core Readability</h2><p class="chart-sub">Flesch Ease and Flesch-Kincaid Grade</p><div class="chart-wrap"><canvas id="chart-core-readability"></canvas></div></article>
            <article class="chart-card"><h2 class="chart-title">Complexity Indices</h2><p class="chart-sub">Gunning Fog, SMOG, ARI, Dale-Chall</p><div class="chart-wrap"><canvas id="chart-complexity"></canvas></div></article>
            <article class="chart-card"><h2 class="chart-title">Lexical Density Signals</h2><p class="chart-sub">Average word length and long-word ratio</p><div class="chart-wrap"><canvas id="chart-lexical"></canvas></div></article>
            <article class="chart-card"><h2 class="chart-title">Thought Fragmentation</h2><p class="chart-sub">Narrative coherence trend</p><div class="chart-wrap"><canvas id="chart-fragmentation"></canvas></div></article>
            <article class="chart-card"><h2 class="chart-title">Sentiment Over Time</h2><p class="chart-sub">Sentiment score trajectory</p><div class="chart-wrap"><canvas id="chart-sentiment"></canvas></div></article>
            <article class="chart-card"><h2 class="chart-title">Personality Profile Trend</h2><p class="chart-sub">Emotionality, Conscientiousness, Openness</p><div class="chart-wrap"><canvas id="chart-personality"></canvas></div></article>
        </section>
    </main>

    <script>
        const userTimeZone = <?php echo json_encode($userTimeZone, JSON_UNESCAPED_SLASHES); ?>;
        const meta = document.getElementById('meta');
        const rangeMode = document.getElementById('range-mode');
        const rangeDays = document.getElementById('range-days');
        const startDate = document.getElementById('start-date');
        const endDate = document.getElementById('end-date');
        const applyFilters = document.getElementById('apply-filters');
        const exportCsv = document.getElementById('export-csv');
        const daysGroup = document.getElementById('days-group');
        const startGroup = document.getElementById('start-group');
        const endGroup = document.getElementById('end-group');

        const chartRefs = {};

        function cssVar(name, fallback) {
            const value = String(getComputedStyle(document.body).getPropertyValue(name) || '').trim();
            return value !== '' ? value : fallback;
        }

        function toDateLabel(rawValue) {
            const value = String(rawValue || '').trim();
            if (value === '') {
                return '';
            }
            const date = new Date(value + 'T00:00:00');
            if (Number.isNaN(date.getTime())) {
                return value;
            }
            return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', timeZone: userTimeZone || undefined });
        }

        function formatNowTimestamp() {
            return new Date().toLocaleString(undefined, {
                timeZone: userTimeZone || undefined,
                dateStyle: 'short',
                timeStyle: 'medium',
            });
        }

        function asNumber(value) {
            const parsed = Number(value);
            return Number.isFinite(parsed) ? parsed : null;
        }

        function toggleFilterVisibility() {
            const mode = rangeMode.value;
            daysGroup.style.display = mode === 'days' ? '' : 'none';
            startGroup.style.display = mode === 'custom' ? '' : 'none';
            endGroup.style.display = mode === 'custom' ? '' : 'none';
        }

        function chartConfig(labels, datasets, yLabel) {
            return {
                type: 'line',
                data: { labels, datasets },
                options: {
                    maintainAspectRatio: false,
                    responsive: true,
                    interaction: { mode: 'nearest', intersect: false },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: cssVar('--text-muted', '#5b6f87') }
                        }
                    },
                    scales: {
                        x: {
                            ticks: { maxRotation: 0, autoSkip: true, color: cssVar('--text-muted', '#5b6f87') },
                            grid: { color: cssVar('--border', '#d6e0ef') }
                        },
                        y: {
                            ticks: { color: cssVar('--text-muted', '#5b6f87') },
                            title: { display: yLabel !== '', text: yLabel, color: cssVar('--text-muted', '#5b6f87') },
                            grid: { color: cssVar('--border', '#d6e0ef') }
                        },
                    },
                },
            };
        }

        function setOrUpdateChart(chartKey, config) {
            const existing = chartRefs[chartKey];
            if (existing) {
                existing.data = config.data;
                existing.options = config.options;
                existing.update();
                return;
            }
            const ctx = document.getElementById(chartKey).getContext('2d');
            chartRefs[chartKey] = new Chart(ctx, config);
        }

        function renderCharts(timeline) {
            const labels = timeline.map((row) => toDateLabel(row.entry_date));

            setOrUpdateChart('chart-words', chartConfig(labels, [
                { label: 'Words', data: timeline.map((r) => asNumber(r.word_count)), borderColor: cssVar('--chart-1', '#2b77c4'), backgroundColor: cssVar('--chart-1-fill', 'rgba(43,119,196,0.2)'), spanGaps: true },
            ], 'Words'));

            setOrUpdateChart('chart-reading-time', chartConfig(labels, [
                { label: 'Reading Minutes', data: timeline.map((r) => asNumber(r.reading_time_minutes)), borderColor: cssVar('--chart-2', '#248f6c'), backgroundColor: cssVar('--chart-2-fill', 'rgba(36,143,108,0.2)'), spanGaps: true },
            ], 'Minutes'));

            setOrUpdateChart('chart-core-readability', chartConfig(labels, [
                { label: 'Flesch Reading Ease', data: timeline.map((r) => asNumber(r.flesch_reading_ease)), borderColor: cssVar('--chart-3', '#9a5fd1'), spanGaps: true },
                { label: 'Flesch-Kincaid Grade', data: timeline.map((r) => asNumber(r.flesch_kincaid_grade)), borderColor: cssVar('--chart-4', '#d16a5f'), spanGaps: true },
            ], 'Score'));

            setOrUpdateChart('chart-complexity', chartConfig(labels, [
                { label: 'Gunning Fog', data: timeline.map((r) => asNumber(r.gunning_fog)), borderColor: cssVar('--chart-5', '#2f6ea8'), spanGaps: true },
                { label: 'SMOG', data: timeline.map((r) => asNumber(r.smog_index)), borderColor: cssVar('--chart-6', '#b0762f'), spanGaps: true },
                { label: 'ARI', data: timeline.map((r) => asNumber(r.automated_readability_index)), borderColor: cssVar('--chart-7', '#6f6f6f'), spanGaps: true },
                { label: 'Dale-Chall', data: timeline.map((r) => asNumber(r.dale_chall)), borderColor: cssVar('--chart-8', '#317e42'), spanGaps: true },
            ], 'Index'));

            setOrUpdateChart('chart-lexical', chartConfig(labels, [
                { label: 'Avg Word Length', data: timeline.map((r) => asNumber(r.average_word_length)), borderColor: cssVar('--chart-9', '#5f74c9'), spanGaps: true },
                { label: 'Long Word Ratio', data: timeline.map((r) => asNumber(r.long_word_ratio)), borderColor: cssVar('--chart-10', '#cc8a2d'), spanGaps: true },
            ], 'Ratio / Length'));

            setOrUpdateChart('chart-fragmentation', chartConfig(labels, [
                { label: 'Thought Fragmentation', data: timeline.map((r) => asNumber(r.thought_fragmentation)), borderColor: cssVar('--chart-11', '#a34d62'), backgroundColor: cssVar('--chart-11-fill', 'rgba(163,77,98,0.2)'), spanGaps: true },
            ], 'Fragmentation'));

            setOrUpdateChart('chart-sentiment', chartConfig(labels, [
                { label: 'Sentiment Score', data: timeline.map((r) => asNumber(r.sentiment_score)), borderColor: cssVar('--chart-12', '#4f7f2d'), backgroundColor: cssVar('--chart-12-fill', 'rgba(79,127,45,0.2)'), spanGaps: true },
            ], 'Sentiment (1-5)'));

            setOrUpdateChart('chart-personality', chartConfig(labels, [
                { label: 'Emotionality', data: timeline.map((r) => asNumber(r.personality && r.personality.emotionality)), borderColor: cssVar('--chart-13', '#7d4eb3'), spanGaps: true },
                { label: 'Conscientiousness', data: timeline.map((r) => asNumber(r.personality && r.personality.conscientiousness)), borderColor: cssVar('--chart-14', '#297f4a'), spanGaps: true },
                { label: 'Openness', data: timeline.map((r) => asNumber(r.personality && r.personality.openness_to_experience)), borderColor: cssVar('--chart-15', '#2d6eb0'), spanGaps: true },
            ], 'Profile Score'));
        }

        function makeApiUrl(format) {
            const params = new URLSearchParams();
            params.set('format', format || 'json');
            params.set('range', rangeMode.value);
            if (rangeMode.value === 'days') {
                params.set('days', rangeDays.value);
            }
            if (rangeMode.value === 'custom') {
                params.set('start_date', startDate.value);
                params.set('end_date', endDate.value);
            }
            return '/api/analysis-timeline.php?' + params.toString();
        }

        async function loadTimeline() {
            meta.className = 'muted';
            meta.textContent = 'Loading timeline data...';
            try {
                const response = await fetch(makeApiUrl('json'), { headers: { Accept: 'application/json' } });
                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error((data && data.error) ? data.error : 'Unable to load timeline data');
                }

                const rows = Array.isArray(data.timeline) ? data.timeline : [];
                renderCharts(rows);
                meta.textContent = 'Entries: ' + rows.length + ' | Updated: ' + formatNowTimestamp();
            } catch (error) {
                meta.className = 'error';
                meta.textContent = error instanceof Error ? error.message : 'Unable to load timeline data';
            }
        }

        rangeMode.addEventListener('change', toggleFilterVisibility);
        applyFilters.addEventListener('click', loadTimeline);
        exportCsv.addEventListener('click', () => {
            window.location.href = makeApiUrl('csv');
        });

        toggleFilterVisibility();
        loadTimeline();
    </script>
</body>
</html>
