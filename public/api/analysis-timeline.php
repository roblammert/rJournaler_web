<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Auth/require_auth.php';

use App\Auth\Auth;
use App\Core\Database;

$userId = Auth::userId();
if (!is_int($userId)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$format = isset($_GET['format']) ? strtolower(trim((string) $_GET['format'])) : 'json';
if (!in_array($format, ['json', 'csv'], true)) {
    $format = 'json';
}

$rangeMode = isset($_GET['range']) ? strtolower(trim((string) $_GET['range'])) : 'days';
$rangeDays = isset($_GET['days']) ? (int) $_GET['days'] : 7;
$rangeDays = max(7, min(365, $rangeDays));
$startDate = trim((string) ($_GET['start_date'] ?? ''));
$endDate = trim((string) ($_GET['end_date'] ?? ''));

$isValidDate = static fn(string $value): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;

$dateFilterSql = ' AND je.entry_date >= DATE_SUB(CURDATE(), INTERVAL :range_days DAY)';
$dateFilterParams = ['range_days' => $rangeDays];

if ($rangeMode === 'all') {
    $dateFilterSql = '';
    $dateFilterParams = [];
} elseif ($rangeMode === 'custom') {
    if ($isValidDate($startDate) && $isValidDate($endDate) && strcmp($startDate, $endDate) <= 0) {
        $dateFilterSql = ' AND je.entry_date BETWEEN :start_date AND :end_date';
        $dateFilterParams = [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    } else {
        $rangeMode = 'days';
        $startDate = '';
        $endDate = '';
    }
}

try {
    $pdo = Database::connection($config['database']);

    $sql = "
        SELECT
            je.entry_uid,
            je.entry_date,
            je.title,
            mg1.word_count,
            mg1.reading_time_minutes,
            mg1.flesch_reading_ease,
            mg1.flesch_kincaid_grade,
            mg1.gunning_fog,
            mg1.smog_index,
            mg1.automated_readability_index,
            mg1.dale_chall,
            mg1.average_word_length,
            mg1.long_word_ratio,
            mg1.thought_fragmentation,
            mg2.analysis_json
        FROM journal_entries je
        LEFT JOIN entry_meta_group_1 mg1 ON mg1.entry_uid = je.entry_uid
        LEFT JOIN entry_meta_group_2 mg2 ON mg2.entry_uid = je.entry_uid
        WHERE je.user_id = :user_id
          AND je.workflow_stage IN ('COMPLETE', 'FINAL')
          {$dateFilterSql}
        ORDER BY je.entry_date ASC, je.created_at ASC
        ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    if ($rangeMode === 'days') {
        $stmt->bindValue(':range_days', $rangeDays, PDO::PARAM_INT);
    } elseif ($rangeMode === 'custom') {
        $stmt->bindValue(':start_date', $startDate, PDO::PARAM_STR);
        $stmt->bindValue(':end_date', $endDate, PDO::PARAM_STR);
    }
    $stmt->execute();

    $rows = $stmt->fetchAll() ?: [];
    $timeline = [];
    foreach ($rows as $row) {
        $analysis = [];
        if (is_string($row['analysis_json'] ?? null) && trim((string) $row['analysis_json']) !== '') {
            $decoded = json_decode((string) $row['analysis_json'], true);
            if (is_array($decoded)) {
                $analysis = $decoded;
            }
        }

        $sentiment = is_array($analysis['sentiment_analysis'] ?? null) ? $analysis['sentiment_analysis'] : [];
        $personality = is_array($analysis['personality_profile'] ?? null) ? $analysis['personality_profile'] : [];

        $timeline[] = [
            'entry_uid' => (string) ($row['entry_uid'] ?? ''),
            'entry_date' => (string) ($row['entry_date'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'word_count' => isset($row['word_count']) ? (int) $row['word_count'] : null,
            'reading_time_minutes' => isset($row['reading_time_minutes']) ? (float) $row['reading_time_minutes'] : null,
            'flesch_reading_ease' => isset($row['flesch_reading_ease']) ? (float) $row['flesch_reading_ease'] : null,
            'flesch_kincaid_grade' => isset($row['flesch_kincaid_grade']) ? (float) $row['flesch_kincaid_grade'] : null,
            'gunning_fog' => isset($row['gunning_fog']) ? (float) $row['gunning_fog'] : null,
            'smog_index' => isset($row['smog_index']) ? (float) $row['smog_index'] : null,
            'automated_readability_index' => isset($row['automated_readability_index']) ? (float) $row['automated_readability_index'] : null,
            'dale_chall' => isset($row['dale_chall']) ? (float) $row['dale_chall'] : null,
            'average_word_length' => isset($row['average_word_length']) ? (float) $row['average_word_length'] : null,
            'long_word_ratio' => isset($row['long_word_ratio']) ? (float) $row['long_word_ratio'] : null,
            'thought_fragmentation' => isset($row['thought_fragmentation']) ? (float) $row['thought_fragmentation'] : null,
            'sentiment_score' => isset($sentiment['score']) ? (float) $sentiment['score'] : null,
            'sentiment_label' => isset($sentiment['label']) ? (string) $sentiment['label'] : null,
            'personality' => [
                'emotionality' => isset($personality['emotionality']) ? (float) $personality['emotionality'] : null,
                'conscientiousness' => isset($personality['conscientiousness']) ? (float) $personality['conscientiousness'] : null,
                'openness_to_experience' => isset($personality['openness_to_experience']) ? (float) $personality['openness_to_experience'] : null,
            ],
        ];
    }

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="timeline-analytics-' . gmdate('Ymd-His') . '.csv"');
        $handle = fopen('php://output', 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open CSV output stream');
        }

        fputcsv($handle, [
            'entry_uid',
            'entry_date',
            'title',
            'word_count',
            'reading_time_minutes',
            'flesch_reading_ease',
            'flesch_kincaid_grade',
            'gunning_fog',
            'smog_index',
            'automated_readability_index',
            'dale_chall',
            'average_word_length',
            'long_word_ratio',
            'thought_fragmentation',
            'sentiment_score',
            'sentiment_label',
            'personality_emotionality',
            'personality_conscientiousness',
            'personality_openness_to_experience',
        ]);

        foreach ($timeline as $row) {
            fputcsv($handle, [
                (string) ($row['entry_uid'] ?? ''),
                (string) ($row['entry_date'] ?? ''),
                (string) ($row['title'] ?? ''),
                $row['word_count'],
                $row['reading_time_minutes'],
                $row['flesch_reading_ease'],
                $row['flesch_kincaid_grade'],
                $row['gunning_fog'],
                $row['smog_index'],
                $row['automated_readability_index'],
                $row['dale_chall'],
                $row['average_word_length'],
                $row['long_word_ratio'],
                $row['thought_fragmentation'],
                $row['sentiment_score'],
                (string) ($row['sentiment_label'] ?? ''),
                $row['personality']['emotionality'] ?? null,
                $row['personality']['conscientiousness'] ?? null,
                $row['personality']['openness_to_experience'] ?? null,
            ]);
        }

        fclose($handle);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'range_mode' => $rangeMode,
        'range_days' => $rangeDays,
        'start_date' => $rangeMode === 'custom' ? $startDate : null,
        'end_date' => $rangeMode === 'custom' ? $endDate : null,
        'timeline' => $timeline,
        'generated_at' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $throwable) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load timeline analytics']);
}
