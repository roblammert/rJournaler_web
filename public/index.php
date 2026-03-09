<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__) . '/app/Auth/require_auth.php';

use App\Auth\Auth;
use App\Core\Database;

$userId = Auth::userId();
if (!is_int($userId)) {
    header('Location: /login.php');
    exit;
}

$userTimeZone = Auth::timezonePreference() ?? date_default_timezone_get();
$interfaceTheme = Auth::interfaceTheme();
$appVersion = (string) ($config['version'] ?? '1.0.0');
$appVersion = (string) ($config['version'] ?? '1.0.4');
// Updated app version fallback to 1.0.4

$userNow = new DateTimeImmutable('now', new DateTimeZone($userTimeZone));
$monthStart = $userNow->modify('first day of this month')->format('Y-m-01');
$nextMonthStart = $userNow->modify('first day of next month')->format('Y-m-01');
$daysInMonth = (int) $userNow->format('t');

$displayName = '';
$stats = [
    'overall_entries' => 0,
    'overall_words' => 0,
    'streak_days' => 0,
    'jobs_in_queue' => 0,
    'queue_failures' => 0,
    'month_entries' => 0,
    'month_words' => 0,
    'month_avg_word_count' => 0.0,
    'month_avg_read_time' => 0.0,
    'month_avg_sentiment_score' => null,
    'month_avg_sentiment_label' => 'n/a',
];
$error = null;

try {
    $pdo = Database::connection($config['database']);

    $userStmt = $pdo->prepare('SELECT display_name FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute(['id' => $userId]);
    $userRow = $userStmt->fetch();
    if (is_array($userRow)) {
        $displayName = trim((string) ($userRow['display_name'] ?? ''));
    }

    $overallStmt = $pdo->prepare(
        'SELECT COUNT(*) AS total_entries, COALESCE(SUM(word_count), 0) AS total_words
         FROM journal_entries
         WHERE user_id = :user_id'
    );
    $overallStmt->execute(['user_id' => $userId]);
    $overallRow = $overallStmt->fetch() ?: [];
    $stats['overall_entries'] = (int) ($overallRow['total_entries'] ?? 0);
    $stats['overall_words'] = (int) ($overallRow['total_words'] ?? 0);

    $monthStmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS month_entries,
            COALESCE(SUM(je.word_count), 0) AS month_words,
            AVG(je.word_count) AS month_avg_word_count,
            AVG(m1.reading_time_minutes) AS month_avg_read_time,
            AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(m2.analysis_json, "$.sentiment_analysis.score")) AS DECIMAL(5,2))) AS month_avg_sentiment_score
         FROM journal_entries je
         LEFT JOIN entry_meta_group_1 m1 ON m1.entry_uid = je.entry_uid
         LEFT JOIN entry_meta_group_2 m2 ON m2.entry_uid = je.entry_uid
         WHERE je.user_id = :user_id
           AND je.entry_date >= :month_start
           AND je.entry_date < :next_month_start'
    );
    $monthStmt->execute([
        'user_id' => $userId,
        'month_start' => $monthStart,
        'next_month_start' => $nextMonthStart,
    ]);
    $monthRow = $monthStmt->fetch() ?: [];
    $stats['month_entries'] = (int) ($monthRow['month_entries'] ?? 0);
    $stats['month_words'] = (int) ($monthRow['month_words'] ?? 0);
    $stats['month_avg_word_count'] = (float) ($monthRow['month_avg_word_count'] ?? 0.0);
    $stats['month_avg_read_time'] = (float) ($monthRow['month_avg_read_time'] ?? 0.0);
    $avgSentimentScore = $monthRow['month_avg_sentiment_score'] ?? null;
    $stats['month_avg_sentiment_score'] = is_numeric($avgSentimentScore) ? (float) $avgSentimentScore : null;

    if (is_float($stats['month_avg_sentiment_score'])) {
        $rounded = (int) round($stats['month_avg_sentiment_score']);
        if ($rounded <= 1) {
            $stats['month_avg_sentiment_label'] = 'Very Negative';
        } elseif ($rounded === 2) {
            $stats['month_avg_sentiment_label'] = 'Negative';
        } elseif ($rounded === 3) {
            $stats['month_avg_sentiment_label'] = 'Neutral';
        } elseif ($rounded === 4) {
            $stats['month_avg_sentiment_label'] = 'Positive';
        } else {
            $stats['month_avg_sentiment_label'] = 'Very Positive';
        }
    }

    $queueStmt = $pdo->query(
        'SELECT
            SUM(CASE WHEN status = "queued" THEN 1 ELSE 0 END) AS jobs_in_queue,
            SUM(
                CASE
                    WHEN status = "failed"
                         AND NOT EXISTS (
                             SELECT 1
                             FROM worker_jobs retry
                             WHERE retry.job_type = worker_jobs.job_type
                               AND retry.entry_uid = worker_jobs.entry_uid
                               AND retry.status IN ("queued", "processing")
                         )
                    THEN 1
                    ELSE 0
                END
            ) AS queue_failures
         FROM worker_jobs'
    );
    $queueRow = $queueStmt->fetch() ?: [];
    $stats['jobs_in_queue'] = (int) ($queueRow['jobs_in_queue'] ?? 0);
    $stats['queue_failures'] = (int) ($queueRow['queue_failures'] ?? 0);

    $streakStmt = $pdo->prepare(
        'SELECT DISTINCT entry_date
         FROM journal_entries
         WHERE user_id = :user_id
         ORDER BY entry_date DESC'
    );
    $streakStmt->execute(['user_id' => $userId]);
    $streakRows = $streakStmt->fetchAll() ?: [];
    $dateSet = [];
    foreach ($streakRows as $streakRow) {
        if (!is_array($streakRow)) {
            continue;
        }
        $dateValue = trim((string) ($streakRow['entry_date'] ?? ''));
        if ($dateValue !== '') {
            $dateSet[$dateValue] = true;
        }
    }

    $streakDays = 0;
    $cursor = DateTimeImmutable::createFromFormat('Y-m-d', $userNow->format('Y-m-d'));
    while ($cursor instanceof DateTimeImmutable) {
        $key = $cursor->format('Y-m-d');
        if (!isset($dateSet[$key])) {
            break;
        }
        $streakDays++;
        $cursor = $cursor->modify('-1 day');
    }
    $stats['streak_days'] = $streakDays;
} catch (Throwable) {
    $error = 'Unable to load dashboard stats right now.';
}

function formatInteger(int $value): string
{
    return number_format($value, 0, '.', ',');
}

function formatDecimal(float $value, int $decimals = 1): string
{
    return number_format($value, $decimals, '.', ',');
}

function queueClass(int $value): string
{
    return $value > 0 ? 'card-warn' : 'card-ok';
}

function failureClass(int $value): string
{
    return $value > 0 ? 'card-fail' : 'card-ok';
}

function sentimentClass(?float $score): string
{
    if (!is_float($score)) {
        return 'card-neutral';
    }
    if ($score >= 4.0) {
        return 'card-ok';
    }
    if ($score >= 3.0) {
        return 'card-neutral';
    }
    return 'card-fail';
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>rJournaler Web - Dashboard</title>
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
            --ok-bg: #e9f8ef;
            --ok-border: #73b990;
            --warn-bg: #fff6e8;
            --warn-border: #d6b069;
            --fail-bg: #fdeef0;
            --fail-border: #d78a95;
            --neutral-bg: #eff4fb;
            --neutral-border: #a8bfdc;
            --err-text: #842533;
            --control-btn-bg: #e8eef6;
            --control-btn-border: #9aabc2;
            --control-btn-text: #334b63;
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
            --neutral-bg: #efefec;
            --neutral-border: #b6b6b2;
            --err-text: #6f3232;
            --control-btn-bg: #ebeae6;
            --control-btn-border: #aeb1ac;
            --control-btn-text: #3f4944;
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
            --neutral-bg: #2b3743;
            --neutral-border: #5d7286;
            --err-text: #f0b5bf;
            --control-btn-bg: #3a4754;
            --control-btn-border: #6f8193;
            --control-btn-text: #dde6ef;
            --shadow-md: 0 14px 32px rgba(0, 0, 0, 0.35);
            --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.28);
        }

        body {
            margin: 0;
            padding: 1.1rem;
            font-family: var(--font-ui);
            background: radial-gradient(circle at 20% 0%, var(--bg-accent), var(--bg) 38%);
            color: var(--text);
        }

        main {
            max-width: 1240px;
            margin: 0 auto;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.85rem;
            flex-wrap: wrap;
        }

        .topbar h1 {
            margin: 0;
            color: var(--heading);
        }

        .topbar-pills {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
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

        .muted { color: var(--text-muted); }
        .error { color: var(--err-text); }

        .refresh-status {
            margin: 0.1rem 0 0.95rem;
            font-size: 0.84rem;
        }

        .stats-group {
            margin-bottom: 1rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            padding: 0.82rem;
        }

        .stats-heading {
            margin: 0 0 0.6rem;
            font-size: 1.02rem;
            color: var(--heading);
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(195px, 1fr));
            gap: 0.68rem;
        }

        .card {
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface-soft);
            padding: 0.72rem 0.78rem;
            transition: border-color var(--transition-fast), transform var(--transition-fast);
        }

        .card:hover {
            transform: translateY(-1px);
        }

        .card.card-ok { border-color: var(--ok-border); background: var(--ok-bg); }
        .card.card-warn { border-color: var(--warn-border); background: var(--warn-bg); }
        .card.card-fail { border-color: var(--fail-border); background: var(--fail-bg); }
        .card.card-neutral { border-color: var(--neutral-border); background: var(--neutral-bg); }

        .card .label { font-size: 0.8rem; color: var(--text-muted); }
        .card .value { margin-top: 0.2rem; font-size: 1.28rem; font-weight: 700; color: var(--heading); }
        .card .sub { margin-top: 0.18rem; font-size: 0.79rem; color: var(--text-muted); }

        .aux-panels {
            margin-top: 1rem;
            display: grid;
            grid-template-columns: 1.25fr 1fr;
            gap: 0.75rem;
            align-items: stretch;
        }

        .weather-panel,
        .interface-controls {
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface);
            box-shadow: var(--shadow-sm);
            padding: 0.8rem 0.92rem;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .interface-controls-title {
            margin: 0;
            color: var(--heading);
            font-size: 1rem;
        }

        .control-groups {
            margin-top: 0.45rem;
            display: grid;
            gap: 0.62rem;
            flex: 1 1 auto;
            align-content: start;
        }

        .control-group {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface-soft);
            padding: 0.48rem;
        }

        .control-group-title {
            margin: 0 0 0.4rem;
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .control-pill-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.35rem;
        }

        .control-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            border: 1px solid var(--control-btn-border);
            border-radius: 999px;
            background: var(--control-btn-bg);
            color: var(--control-btn-text);
            font-weight: 600;
            padding: 0.28rem 0.52rem;
            font-size: 0.8rem;
            text-decoration: none;
            min-height: 2rem;
            transition: filter var(--transition-fast), transform var(--transition-fast);
        }

        .control-pill:hover {
            text-decoration: none;
            filter: brightness(1.05);
        }

        .control-pill:active {
            transform: translateY(1px);
        }

        .weather-panel a { color: var(--link); text-decoration: none; }
        .weather-panel a:hover { text-decoration: underline; }

        .weather-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(120px, 1fr));
            gap: 0.45rem;
            margin-top: 0;
        }

        .weather-intro {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 0.65rem;
            align-items: stretch;
            margin-bottom: 0.55rem;
        }

        .weather-intro-main {
            min-width: 0;
        }

        .weather-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.6rem;
        }

        .weather-title {
            color: var(--heading);
            font-size: 1rem;
        }

        .weather-icon {
            width: auto;
            height: 100%;
            max-height: 128px;
            aspect-ratio: 1 / 1;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface-soft);
            object-fit: contain;
            justify-self: end;
            align-self: start;
        }

        .weather-icon[hidden] {
            display: none;
        }

        .weather-stat {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface-soft);
            padding: 0.45rem;
        }

        .weather-stat .label { font-size: 0.75rem; color: var(--text-muted); }
        .weather-stat .value { margin-top: 0.12rem; font-size: 0.98rem; font-weight: 700; color: var(--heading); }
        .weather-meta { margin-top: 0.45rem; font-size: 0.8rem; color: var(--text-muted); }

        .weather-forecast {
            margin-top: 0.6rem;
        }

        .weather-forecast-title {
            margin: 0 0 0.38rem;
            font-size: 0.82rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.03em;
            font-weight: 700;
        }

        .weather-forecast-row {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 0.38rem;
        }

        .forecast-day {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface-soft);
            padding: 0.3rem 0.28rem;
            text-align: center;
            min-height: 108px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 0.08rem;
        }

        .forecast-day-label {
            font-size: 0.76rem;
            color: var(--text-muted);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }

        .forecast-day-icon-wrap {
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .forecast-day-icon {
            width: 42px;
            height: 42px;
            object-fit: contain;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--surface);
        }

        .forecast-day-temp {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--heading);
            line-height: 1.2;
        }

        @media (max-width: 980px) {
            .aux-panels { grid-template-columns: 1fr; }
        }

        @media (max-width: 1160px) {
            .control-pill-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 640px) {
            body { padding: 0.72rem; }
            .stats-group,
            .weather-panel,
            .interface-controls { padding: 0.7rem; }

            .weather-intro {
                grid-template-columns: 1fr;
                margin-bottom: 0.45rem;
            }

            .weather-icon {
                width: 84px;
                height: 84px;
                justify-self: end;
            }

            .weather-forecast-row {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .control-pill-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 460px) {
            .weather-forecast-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars($interfaceTheme, ENT_QUOTES, 'UTF-8'); ?>">
    <main>
        <section class="topbar">
            <h1><?php echo htmlspecialchars($displayName !== '' ? ($displayName . "'s Journal Dashboard") : 'rJournaler Web Dashboard', ENT_QUOTES, 'UTF-8'); ?></h1>
            <div class="topbar-pills">
                <div class="pill">Theme: <?php echo htmlspecialchars(ucfirst($interfaceTheme), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="pill">rJournaler_Web: v<?php echo htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </section>
        <p id="dashboard-refresh-status" class="muted refresh-status">Card refresh status: pending</p>

        <?php if ($error !== null): ?>
            <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <section class="stats-group" aria-label="Overall stats">
            <h2 class="stats-heading">Stats Overall</h2>
            <div class="cards">
                <article class="card">
                    <div class="label">Total Entries</div>
                    <div id="card-overall-entries-value" class="value"><?php echo formatInteger((int) $stats['overall_entries']); ?></div>
                </article>
                <article class="card">
                    <div class="label">Total Words</div>
                    <div id="card-overall-words-value" class="value"><?php echo formatInteger((int) $stats['overall_words']); ?></div>
                </article>
                <article class="card">
                    <div class="label">Current Streak (Days)</div>
                    <div id="card-streak-days-value" class="value"><?php echo formatInteger((int) $stats['streak_days']); ?></div>
                </article>
                <article id="card-queue" class="card <?php echo htmlspecialchars(queueClass((int) $stats['jobs_in_queue']), ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="label">Jobs in Queue</div>
                    <div id="card-queue-value" class="value"><?php echo formatInteger((int) $stats['jobs_in_queue']); ?></div>
                </article>
                <article id="card-failures" class="card <?php echo htmlspecialchars(failureClass((int) $stats['queue_failures']), ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="label">Job Queue Failures</div>
                    <div id="card-failures-value" class="value"><?php echo formatInteger((int) $stats['queue_failures']); ?></div>
                </article>
            </div>
        </section>

        <section class="stats-group" aria-label="This month stats">
            <h2 class="stats-heading">Stats This Month</h2>
            <div class="cards">
                <article class="card">
                    <div class="label">Total Entries / Days of Month</div>
                    <div id="card-month-entries-value" class="value"><?php echo formatInteger((int) $stats['month_entries']); ?> / <?php echo formatInteger($daysInMonth); ?></div>
                    <div class="sub">Month: <?php echo htmlspecialchars($userNow->format('F Y'), ENT_QUOTES, 'UTF-8'); ?></div>
                </article>
                <article class="card">
                    <div class="label">Total Words</div>
                    <div id="card-month-words-value" class="value"><?php echo formatInteger((int) $stats['month_words']); ?></div>
                </article>
                <article class="card">
                    <div class="label">Avg Word Count</div>
                    <div id="card-month-avg-word-count-value" class="value"><?php echo formatDecimal((float) $stats['month_avg_word_count'], 1); ?></div>
                </article>
                <article class="card">
                    <div class="label">Avg Reading Time</div>
                    <div id="card-month-avg-read-time-value" class="value"><?php echo formatDecimal((float) $stats['month_avg_read_time'], 1); ?> min</div>
                </article>
                <article id="card-sentiment" class="card <?php echo htmlspecialchars(sentimentClass(is_float($stats['month_avg_sentiment_score']) ? (float) $stats['month_avg_sentiment_score'] : null), ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="label">Avg Sentiment Score (With Label)</div>
                    <?php if (is_float($stats['month_avg_sentiment_score'])): ?>
                        <div id="card-sentiment-value" class="value"><?php echo formatDecimal((float) $stats['month_avg_sentiment_score'], 2); ?> / 5</div>
                        <div id="card-sentiment-sub" class="sub"><?php echo htmlspecialchars((string) $stats['month_avg_sentiment_label'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php else: ?>
                        <div id="card-sentiment-value" class="value">n/a</div>
                        <div id="card-sentiment-sub" class="sub">No scored entries yet this month</div>
                    <?php endif; ?>
                </article>
            </div>
        </section>

        <section class="aux-panels" aria-label="Weather and interface controls">
            <article class="weather-panel">
                <div class="weather-intro">
                    <div class="weather-intro-main">
                        <div class="weather-header">
                            <strong class="weather-title">Current Weather</strong>
                        </div>
                        <div class="weather-meta" id="weather-status">Loading weather...</div>
                        <div class="weather-meta" id="weather-updated">Last updated: -</div>
                        <div class="weather-meta">Manage weather presets in <a href="/user-settings.php">User Settings</a>.</div>
                    </div>
                    <img id="weather-icon" class="weather-icon" src="" alt="Current weather icon" hidden>
                </div>
                <div class="weather-grid" id="weather-grid">
                    <div class="weather-stat"><div class="label">Temp (F)</div><div class="value" id="weather-temp">-</div></div>
                    <div class="weather-stat"><div class="label">Feels Like (F)</div><div class="value" id="weather-feels-like">-</div></div>
                    <div class="weather-stat"><div class="label">Humidity</div><div class="value" id="weather-humidity">-</div></div>
                    <div class="weather-stat"><div class="label">Dew Point (F)</div><div class="value" id="weather-dew-point">-</div></div>
                    <div class="weather-stat"><div class="label">Wind</div><div class="value" id="weather-wind">-</div></div>
                    <div class="weather-stat"><div class="label">Conditions</div><div class="value" id="weather-summary">-</div></div>
                </div>
                <section class="weather-forecast" aria-label="Five day weather forecast">
                    <h3 class="weather-forecast-title">5-Day Forecast</h3>
                    <div id="weather-forecast-row" class="weather-forecast-row"></div>
                </section>
            </article>

            <section class="interface-controls" aria-label="Interface controls">
                <h2 class="interface-controls-title">Interface Controls</h2>
                <div class="control-groups">
                    <section class="control-group" aria-label="User actions">
                        <h3 class="control-group-title">User Actions</h3>
                        <div class="control-pill-grid">
                            <a class="control-pill" href="/entry.php">Entry Editor</a>
                            <a class="control-pill" href="/logout.php">Log Out</a>
                        </div>
                    </section>

                    <section class="control-group" aria-label="Analysis pages">
                        <h3 class="control-group-title">Analysis</h3>
                        <div class="control-pill-grid">
                            <a class="control-pill" href="/dashboards/analysis-simple.php">Simple Analysis</a>
                            <a class="control-pill" href="/dashboards/analysis-deep.php">Deep Analysis</a>
                            <a class="control-pill" href="/dashboards/analysis-timeline.php">Timeline Analytics</a>
                        </div>
                    </section>

                    <?php if (Auth::isAdmin()): ?>
                        <section class="control-group" aria-label="System monitoring pages">
                            <h3 class="control-group-title">System Monitoring & Processing</h3>
                            <div class="control-pill-grid">
                                <a class="control-pill" href="/dashboards/status.php">Queue Status</a>
                                
                                    <a class="control-pill" href="/admin-reprocess.php">Targeted Reprocess</a>
                                    <a class="control-pill" href="/logviewer.php">Optimus Log Viewer</a>
                            </div>
                        </section>
                    <?php endif; ?>

                    <section class="control-group" aria-label="Settings pages">
                        <h3 class="control-group-title">Settings</h3>
                        <div class="control-pill-grid">
                            <a class="control-pill" href="/user-settings.php">User Settings</a>
                            <?php if (Auth::isAdmin()): ?>
                                <a class="control-pill" href="/admin-settings.php">Admin Settings</a>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            </section>
        </section>
    </main>
    <script>
        const dashboardRefreshStatus = document.getElementById('dashboard-refresh-status');
        const dashboardCardQueue = document.getElementById('card-queue');
        const dashboardCardQueueValue = document.getElementById('card-queue-value');
        const dashboardCardFailures = document.getElementById('card-failures');
        const dashboardCardFailuresValue = document.getElementById('card-failures-value');
        const dashboardCardSentiment = document.getElementById('card-sentiment');
        const dashboardCardSentimentValue = document.getElementById('card-sentiment-value');
        const dashboardCardSentimentSub = document.getElementById('card-sentiment-sub');
        const dashboardCardOverallEntries = document.getElementById('card-overall-entries-value');
        const dashboardCardOverallWords = document.getElementById('card-overall-words-value');
        const dashboardCardStreakDays = document.getElementById('card-streak-days-value');
        const dashboardCardMonthEntries = document.getElementById('card-month-entries-value');
        const dashboardCardMonthWords = document.getElementById('card-month-words-value');
        const dashboardCardMonthAvgWordCount = document.getElementById('card-month-avg-word-count-value');
        const dashboardCardMonthAvgReadTime = document.getElementById('card-month-avg-read-time-value');

        function formatInteger(value) {
            const numeric = Number(value);
            if (!Number.isFinite(numeric)) {
                return '0';
            }
            return numeric.toLocaleString('en-US', { maximumFractionDigits: 0 });
        }

        function formatDecimal(value, decimals) {
            const numeric = Number(value);
            if (!Number.isFinite(numeric)) {
                return (0).toFixed(decimals || 1);
            }
            return numeric.toLocaleString('en-US', {
                minimumFractionDigits: decimals || 1,
                maximumFractionDigits: decimals || 1
            });
        }

        function setCardTone(cardNode, tone) {
            if (!(cardNode instanceof HTMLElement)) {
                return;
            }
            cardNode.classList.remove('card-ok', 'card-warn', 'card-fail', 'card-neutral');
            if (typeof tone === 'string' && tone !== '') {
                cardNode.classList.add(tone);
            }
        }

        function setRefreshStatus(message, isError) {
            if (!(dashboardRefreshStatus instanceof HTMLElement)) {
                return;
            }
            dashboardRefreshStatus.textContent = 'Card refresh status: ' + String(message || 'pending');
            dashboardRefreshStatus.className = isError ? 'error' : 'muted';
        }

        async function refreshDashboardCards() {
            try {
                const response = await fetch('/api/dashboard-cards.php', { headers: { Accept: 'application/json' } });
                const data = await response.json();
                if (!response.ok || !data || data.ok !== true) {
                    throw new Error((data && data.error) ? data.error : 'Unable to refresh dashboard cards');
                }

                const stats = data.stats || {};
                if (dashboardCardOverallEntries) {
                    dashboardCardOverallEntries.textContent = formatInteger(stats.overall_entries || 0);
                }
                if (dashboardCardOverallWords) {
                    dashboardCardOverallWords.textContent = formatInteger(stats.overall_words || 0);
                }
                if (dashboardCardStreakDays) {
                    dashboardCardStreakDays.textContent = formatInteger(stats.streak_days || 0);
                }
                if (dashboardCardQueueValue) {
                    dashboardCardQueueValue.textContent = formatInteger(stats.jobs_in_queue || 0);
                }
                if (dashboardCardFailuresValue) {
                    dashboardCardFailuresValue.textContent = formatInteger(stats.queue_failures || 0);
                }
                if (dashboardCardMonthEntries) {
                    dashboardCardMonthEntries.textContent = formatInteger(stats.month_entries || 0) + ' / ' + formatInteger(Number(data.days_in_month) || 0);
                }
                if (dashboardCardMonthWords) {
                    dashboardCardMonthWords.textContent = formatInteger(stats.month_words || 0);
                }
                if (dashboardCardMonthAvgWordCount) {
                    dashboardCardMonthAvgWordCount.textContent = formatDecimal(stats.month_avg_word_count || 0, 1);
                }
                if (dashboardCardMonthAvgReadTime) {
                    dashboardCardMonthAvgReadTime.textContent = formatDecimal(stats.month_avg_read_time || 0, 1) + ' min';
                }

                const sentimentScore = stats.month_avg_sentiment_score;
                const hasSentiment = Number.isFinite(Number(sentimentScore));
                if (dashboardCardSentimentValue) {
                    dashboardCardSentimentValue.textContent = hasSentiment
                        ? (formatDecimal(Number(sentimentScore), 2) + ' / 5')
                        : 'n/a';
                }
                if (dashboardCardSentimentSub) {
                    dashboardCardSentimentSub.textContent = hasSentiment
                        ? String(stats.month_avg_sentiment_label || 'n/a')
                        : 'No scored entries yet this month';
                }

                setCardTone(dashboardCardQueue, String(data.card_classes?.queue || 'card-neutral'));
                setCardTone(dashboardCardFailures, String(data.card_classes?.failures || 'card-neutral'));
                setCardTone(dashboardCardSentiment, String(data.card_classes?.sentiment || 'card-neutral'));

                setRefreshStatus('ok at ' + new Date().toLocaleTimeString(), false);
            } catch (error) {
                // Keep existing card values when refresh fails.
                const detail = error instanceof Error ? error.message : 'refresh failed';
                setRefreshStatus('failed at ' + new Date().toLocaleTimeString() + ' (' + detail + ')', true);
            }
        }

        const weatherStatus = document.getElementById('weather-status');
        const weatherTemp = document.getElementById('weather-temp');
        const weatherFeelsLike = document.getElementById('weather-feels-like');
        const weatherHumidity = document.getElementById('weather-humidity');
        const weatherDewPoint = document.getElementById('weather-dew-point');
        const weatherWind = document.getElementById('weather-wind');
        const weatherSummary = document.getElementById('weather-summary');
        const weatherUpdated = document.getElementById('weather-updated');
        const weatherIcon = document.getElementById('weather-icon');
        const weatherForecastRow = document.getElementById('weather-forecast-row');
        let weatherRetryTimer = null;

        function setWeatherIcon(iconUrl, summary) {
            if (!(weatherIcon instanceof HTMLImageElement)) {
                return;
            }
            const normalized = String(iconUrl || '').trim();
            if (normalized === '') {
                weatherIcon.hidden = true;
                weatherIcon.removeAttribute('src');
                weatherIcon.alt = 'Current weather icon unavailable';
                return;
            }
            weatherIcon.src = normalized;
            weatherIcon.alt = String(summary || 'Current weather');
            weatherIcon.hidden = false;
        }

        function formatMaybeNumber(value, suffix) {
            const number = Number(value);
            if (!Number.isFinite(number)) {
                return '-';
            }
            return number.toFixed(1) + (suffix || '');
        }

        function setWeatherText(node, value) {
            node.textContent = String(value || '-');
        }

        function formatForecastTemp(value) {
            const numeric = Number(value);
            if (!Number.isFinite(numeric)) {
                return '--';
            }
            return Math.round(numeric) + 'F';
        }

        function renderForecastRow(forecastDays) {
            if (!(weatherForecastRow instanceof HTMLElement)) {
                return;
            }

            const rows = Array.isArray(forecastDays) ? forecastDays.slice(0, 5) : [];
            while (rows.length < 5) {
                rows.push({});
            }

            weatherForecastRow.replaceChildren();
            rows.forEach((day, index) => {
                const card = document.createElement('article');
                card.className = 'forecast-day';

                const label = document.createElement('div');
                label.className = 'forecast-day-label';
                label.textContent = String(day && day.day_label ? day.day_label : ('Day ' + (index + 1)));

                const iconWrap = document.createElement('div');
                iconWrap.className = 'forecast-day-icon-wrap';
                const icon = document.createElement('img');
                icon.className = 'forecast-day-icon';
                const iconUrl = String(day && day.icon_url ? day.icon_url : '').trim();
                if (iconUrl === '') {
                    icon.hidden = true;
                } else {
                    icon.src = iconUrl;
                    icon.alt = String(day && day.summary_word ? day.summary_word : 'Forecast');
                    icon.hidden = false;
                }
                iconWrap.appendChild(icon);

                const temp = document.createElement('div');
                temp.className = 'forecast-day-temp';
                temp.textContent = formatForecastTemp(day && day.high_f) + ' / ' + formatForecastTemp(day && day.low_f);

                card.append(label, iconWrap, temp);
                weatherForecastRow.appendChild(card);
            });
        }

        function scheduleWeatherRetry(delayMs) {
            if (weatherRetryTimer !== null) {
                window.clearTimeout(weatherRetryTimer);
            }
            weatherRetryTimer = window.setTimeout(() => {
                weatherRetryTimer = null;
                loadWeather();
            }, Math.max(500, Number(delayMs) || 5000));
        }

        function renderWeather(data) {
            const weather = data && data.weather && data.weather.ok ? data.weather : null;
            const refreshing = data && data.refreshing === true;
            const cacheAgeSeconds = Number(data && data.cache_age_seconds);
            const cacheAgeMinutes = Number.isFinite(cacheAgeSeconds) ? Math.max(0, Math.round(cacheAgeSeconds / 60)) : null;
            if (!weather) {
                const message = data && data.weather && data.weather.error ? data.weather.error : 'Weather unavailable';
                weatherStatus.textContent = refreshing ? (message + ' Refreshing in background...') : message;
                setWeatherText(weatherTemp, '-');
                setWeatherText(weatherFeelsLike, '-');
                setWeatherText(weatherHumidity, '-');
                setWeatherText(weatherDewPoint, '-');
                setWeatherText(weatherWind, '-');
                setWeatherText(weatherSummary, '-');
                setWeatherIcon('', '');
                renderForecastRow([]);
                weatherUpdated.textContent = 'Last updated: -';
                if (refreshing) {
                    scheduleWeatherRetry(5000);
                }
                return;
            }

            const current = weather.current || {};
            const location = weather.location || {};
            let statusText = 'Weather pulled from: ' + String(location.label || location.display_name || 'n/a');
            if (refreshing && cacheAgeMinutes !== null) {
                statusText += ' (cached ' + cacheAgeMinutes + 'm old, refreshing...)';
                scheduleWeatherRetry(5000);
            }
            weatherStatus.textContent = statusText;
            if (cacheAgeMinutes !== null) {
                weatherUpdated.textContent = 'Last updated: ' + cacheAgeMinutes + ' minute' + (cacheAgeMinutes === 1 ? '' : 's') + ' ago';
            } else {
                weatherUpdated.textContent = refreshing ? 'Last updated: pending refresh...' : 'Last updated: just now';
            }
            setWeatherText(weatherTemp, formatMaybeNumber(current.temperature_f, ' F'));
            setWeatherText(weatherFeelsLike, formatMaybeNumber(current.feels_like_f, ' F'));
            setWeatherText(weatherHumidity, formatMaybeNumber(current.humidity_percent, '%'));
            setWeatherText(weatherDewPoint, formatMaybeNumber(current.dew_point_f, ' F'));
            const windSpeed = formatMaybeNumber(current.wind_speed_mph, ' mph');
            const gust = Number(current.wind_gust_mph);
            const gustPart = Number.isFinite(gust) ? (' (gust ' + gust.toFixed(1) + ' mph)') : '';
            const direction = Number(current.wind_direction_degrees);
            const dirPart = Number.isFinite(direction) ? (' @ ' + direction.toFixed(0) + ' deg') : '';
            setWeatherText(weatherWind, windSpeed + dirPart + gustPart);
            const summary = String(current.summary || '-');
            setWeatherText(weatherSummary, summary);
            setWeatherIcon((weather.forecast || {}).icon_url || '', summary);
            renderForecastRow(weather.forecast_days || []);
        }

        async function loadWeather() {
            weatherStatus.textContent = 'Loading weather...';
            try {
                const response = await fetch('/api/weather-session.php', { headers: { Accept: 'application/json' } });
                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error((data && data.error) ? data.error : 'Unable to load weather');
                }
                renderWeather(data);
            } catch (error) {
                weatherStatus.textContent = error instanceof Error ? error.message : 'Unable to load weather';
            }
        }

        refreshDashboardCards();
        loadWeather();
        setInterval(refreshDashboardCards, 5000);
    </script>
</body>
</html>
