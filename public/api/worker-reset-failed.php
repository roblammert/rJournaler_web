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

$resetMode = isset($data['reset_mode']) && is_string($data['reset_mode']) ? trim($data['reset_mode']) : '';
if (!in_array($resetMode, ['all_jobs', 'remaining_failed_jobs', 'remaining_unresolved_failed_jobs'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid reset mode']);
    exit;
}

$defaultStages = [
    'meta_group_0' => 'Meta Group 0',
    'meta_group_1' => 'Meta Group 1',
    'meta_group_2_llm' => 'Meta Group 2 (LLM)',
    'meta_group_3_weather' => 'Meta Group 3 (Weather)',
    'metrics_finalize' => 'Metrics Finalize',
];
$defaultStageIds = array_keys($defaultStages);
$defaultStageLabels = array_values($defaultStages);

/**
 * @return array<string,mixed>
 */
function normalize_pipeline_payload(mixed $payloadRaw, string $resetMode, array $defaultStageIds, array $defaultStageLabels): array
{
    $payload = [];
    if (is_array($payloadRaw)) {
        $payload = $payloadRaw;
    } elseif (is_string($payloadRaw) && trim($payloadRaw) !== '') {
        $decoded = json_decode($payloadRaw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    if (!isset($payload['pipeline']) || !is_array($payload['pipeline'])) {
        $payload['pipeline'] = [];
    }

    if ($resetMode === 'all_jobs') {
        $payload['pipeline']['completed'] = [];
        $payload['pipeline']['remaining'] = $defaultStageIds;
        $payload['pipeline']['remaining_labels'] = $defaultStageLabels;
        $payload['pipeline']['stages'] = [
            ['id' => 'meta_group_0', 'label' => 'Meta Group 0'],
            ['id' => 'meta_group_1', 'label' => 'Meta Group 1'],
            ['id' => 'meta_group_2_llm', 'label' => 'Meta Group 2 (LLM)'],
            ['id' => 'meta_group_3_weather', 'label' => 'Meta Group 3 (Weather)'],
            ['id' => 'metrics_finalize', 'label' => 'Metrics Finalize'],
        ];
        return $payload;
    }

    $completed = $payload['pipeline']['completed'] ?? [];
    if (!is_array($completed)) {
        $completed = [];
    }
    $completedIds = [];
    foreach ($completed as $value) {
        $stageId = is_string($value) ? trim($value) : '';
        if ($stageId !== '' && in_array($stageId, $defaultStageIds, true) && !in_array($stageId, $completedIds, true)) {
            $completedIds[] = $stageId;
        }
    }

    $remaining = $payload['pipeline']['remaining'] ?? [];
    if (!is_array($remaining)) {
        $remaining = [];
    }
    $remainingIds = [];
    foreach ($remaining as $value) {
        $stageId = is_string($value) ? trim($value) : '';
        if ($stageId !== '' && in_array($stageId, $defaultStageIds, true) && !in_array($stageId, $remainingIds, true)) {
            $remainingIds[] = $stageId;
        }
    }

    if (count($remainingIds) === 0) {
        $remainingIds = [];
        foreach ($defaultStageIds as $stageId) {
            if (!in_array($stageId, $completedIds, true)) {
                $remainingIds[] = $stageId;
            }
        }
    }

    if (count($remainingIds) === 0) {
        $remainingIds = $defaultStageIds;
        $completedIds = [];
    }

    $labelsById = [
        'meta_group_0' => 'Meta Group 0',
        'meta_group_1' => 'Meta Group 1',
        'meta_group_2_llm' => 'Meta Group 2 (LLM)',
        'meta_group_3_weather' => 'Meta Group 3 (Weather)',
        'metrics_finalize' => 'Metrics Finalize',
    ];

    $payload['pipeline']['completed'] = $completedIds;
    $payload['pipeline']['remaining'] = $remainingIds;
    $payload['pipeline']['remaining_labels'] = array_values(array_map(
        static fn(string $stageId): string => $labelsById[$stageId] ?? $stageId,
        $remainingIds
    ));
    $payload['pipeline']['stages'] = [
        ['id' => 'meta_group_0', 'label' => 'Meta Group 0'],
        ['id' => 'meta_group_1', 'label' => 'Meta Group 1'],
        ['id' => 'meta_group_2_llm', 'label' => 'Meta Group 2 (LLM)'],
        ['id' => 'meta_group_3_weather', 'label' => 'Meta Group 3 (Weather)'],
        ['id' => 'metrics_finalize', 'label' => 'Metrics Finalize'],
    ];

    return $payload;
}

try {
    $pdo = Database::connection($config['database']);

        if ($resetMode === 'remaining_unresolved_failed_jobs') {
                $failedStmt = $pdo->query(
                        "
                        SELECT f.id, f.entry_uid, f.payload_json
                        FROM worker_jobs f
                INNER JOIN journal_entries je ON je.entry_uid = f.entry_uid
                        WHERE f.status = 'failed'
                            AND f.job_type = 'entry_process_pipeline'
                            AND NOT EXISTS (
                                        SELECT 1
                                        FROM worker_jobs active
                                        WHERE active.job_type = f.job_type
                                            AND active.entry_uid = f.entry_uid
                                            AND active.status IN ('queued', 'processing')
                            )
                        ORDER BY f.id ASC
                        "
                );
        } else {
                $failedStmt = $pdo->query(
                        "
                        SELECT id, entry_uid, payload_json
                        FROM worker_jobs f
                        INNER JOIN journal_entries je ON je.entry_uid = f.entry_uid
                        WHERE status = 'failed'
                            AND job_type = 'entry_process_pipeline'
                        ORDER BY id ASC
                        "
                );
        }
        $failedRows = $failedStmt->fetchAll() ?: [];

        $allFailedCountStmt = $pdo->query(
                "
                SELECT COUNT(*)
                FROM worker_jobs
                WHERE status = 'failed'
                    AND job_type = 'entry_process_pipeline'
                "
        );
        $allFailedCount = (int) $allFailedCountStmt->fetchColumn();

        $missingEntryFailedCountStmt = $pdo->query(
                "
                SELECT COUNT(*)
                FROM worker_jobs f
                LEFT JOIN journal_entries je ON je.entry_uid = f.entry_uid
                WHERE f.status = 'failed'
                    AND f.job_type = 'entry_process_pipeline'
                    AND je.entry_uid IS NULL
                "
        );
        $missingEntryFailedCount = (int) $missingEntryFailedCountStmt->fetchColumn();

    if (count($failedRows) === 0) {
        echo json_encode([
            'ok' => true,
            'reset_mode' => $resetMode,
            'reset_jobs' => 0,
            'skipped_superseded_failed_jobs' => max(0, $allFailedCount),
            'skipped_missing_entry_failed_jobs' => max(0, $missingEntryFailedCount),
            'entries_marked_in_process' => 0,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $updateStmt = $pdo->prepare(
        "
        UPDATE worker_jobs
        SET status = 'queued',
            stage_label = 'Queued',
            queue_comment = :queue_comment,
            payload_json = :payload_json,
            run_after = UTC_TIMESTAMP(),
            locked_at = NULL,
            locked_by = NULL,
            error_message = NULL,
            completed_at = NULL,
            attempt_count = 0
        WHERE id = :id
          AND status = 'failed'
        "
    );

    $entryStageStmt = $pdo->prepare(
        "
        UPDATE journal_entries
        SET workflow_stage = 'IN_PROCESS',
            stage_updated_at = UTC_TIMESTAMP(),
            updated_at = UTC_TIMESTAMP()
        WHERE entry_uid = :entry_uid
        "
    );

    $resetJobs = 0;
    $entryUidSet = [];
    $queueComment = $resetMode === 'all_jobs'
        ? 'Admin reset failed job (all stages)'
        : ($resetMode === 'remaining_unresolved_failed_jobs'
            ? 'Admin reset failed job (remaining unresolved stages)'
            : 'Admin reset failed job (remaining stages)');

    foreach ($failedRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $jobId = (int) ($row['id'] ?? 0);
        if ($jobId <= 0) {
            continue;
        }

        $payload = normalize_pipeline_payload(
            $row['payload_json'] ?? null,
            $resetMode,
            $defaultStageIds,
            $defaultStageLabels
        );

        $updateStmt->execute([
            'queue_comment' => $queueComment,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'id' => $jobId,
        ]);

        if ($updateStmt->rowCount() > 0) {
            $resetJobs += 1;
        }

        $entryUid = trim((string) ($row['entry_uid'] ?? ''));
        if ($entryUid !== '') {
            $entryUidSet[$entryUid] = true;
        }
    }

    $entriesMarked = 0;
    foreach (array_keys($entryUidSet) as $entryUid) {
        $entryStageStmt->execute(['entry_uid' => $entryUid]);
        if ($entryStageStmt->rowCount() > 0) {
            $entriesMarked += 1;
        }
    }

    echo json_encode([
        'ok' => true,
        'reset_mode' => $resetMode,
        'reset_jobs' => $resetJobs,
        'skipped_superseded_failed_jobs' => max(0, $allFailedCount - $resetJobs),
        'skipped_missing_entry_failed_jobs' => max(0, $missingEntryFailedCount),
        'entries_marked_in_process' => $entriesMarked,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to reset failed jobs',
    ]);
}
