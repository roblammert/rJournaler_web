<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Auth/require_auth.php';

use App\Auth\Auth;
use App\Core\Database;

header('Content-Type: application/json; charset=utf-8');

$userId = Auth::userId();
if (!is_int($userId)) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Authentication required',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$userTimeZone = Auth::timezonePreference() ?? date_default_timezone_get();
$userNow = new DateTimeImmutable('now', new DateTimeZone($userTimeZone));
$monthStart = $userNow->modify('first day of this month')->format('Y-m-01');
$nextMonthStart = $userNow->modify('first day of next month')->format('Y-m-01');
$daysInMonth = (int) $userNow->format('t');

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

$queueClass = static function (int $value): string {
    return $value > 0 ? 'card-warn' : 'card-ok';
};

$failureClass = static function (int $value): string {
    return $value > 0 ? 'card-fail' : 'card-ok';
};

$sentimentClass = static function (?float $score): string {
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
};

try {
    $pdo = Database::connection($config['database']);

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

    echo json_encode([
        'ok' => true,
        'stats' => $stats,
        'days_in_month' => $daysInMonth,
        'as_of_local' => $userNow->format('Y-m-d h:i A T'),
        'card_classes' => [
            'queue' => $queueClass((int) $stats['jobs_in_queue']),
            'failures' => $failureClass((int) $stats['queue_failures']),
            'sentiment' => $sentimentClass(is_float($stats['month_avg_sentiment_score']) ? (float) $stats['month_avg_sentiment_score'] : null),
        ],
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to load dashboard stats right now.',
    ], JSON_UNESCAPED_SLASHES);
}
