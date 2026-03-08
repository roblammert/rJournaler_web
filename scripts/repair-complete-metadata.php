<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';

use App\Core\Database;

$apply = in_array('--apply', $argv, true);

$pipelineStages = ['Meta Group 0', 'Meta Group 1', 'Meta Group 2 (LLM)', 'Metrics Finalize'];

$pdo = Database::connection($config['database']);

$mismatchStmt = $pdo->query(
    "SELECT je.entry_uid, je.user_id,
            EXISTS(
                SELECT 1
                FROM worker_jobs wj
                WHERE wj.entry_uid = je.entry_uid
                  AND wj.job_type = 'entry_process_pipeline'
                  AND wj.status IN ('queued', 'processing')
            ) AS has_active_job
     FROM journal_entries je
     LEFT JOIN entry_meta_group_0 g0 ON g0.entry_uid = je.entry_uid
     LEFT JOIN entry_meta_group_1 g1 ON g1.entry_uid = je.entry_uid
     LEFT JOIN entry_meta_group_2 g2 ON g2.entry_uid = je.entry_uid
     WHERE je.workflow_stage = 'COMPLETE'
       AND (g0.entry_uid IS NULL OR g1.entry_uid IS NULL OR g2.entry_uid IS NULL)
     ORDER BY je.id ASC"
);
$rows = $mismatchStmt->fetchAll();
if (!is_array($rows)) {
    $rows = [];
}

$total = count($rows);
echo 'COMPLETE entries missing metadata: ' . $total . PHP_EOL;

if ($total > 0) {
    echo 'Sample UIDs:' . PHP_EOL;
    for ($index = 0; $index < min(5, $total); $index++) {
        $sampleUid = (string) ($rows[$index]['entry_uid'] ?? '');
        if ($sampleUid !== '') {
            echo '  - ' . $sampleUid . PHP_EOL;
        }
    }
}

if (!$apply) {
    echo 'Dry run only. Re-run with --apply to update stages and queue missing jobs.' . PHP_EOL;
    exit(0);
}

$updateStageStmt = $pdo->prepare(
    "UPDATE journal_entries
     SET workflow_stage = 'IN_PROCESS',
         stage_updated_at = UTC_TIMESTAMP(),
         updated_at = UTC_TIMESTAMP()
     WHERE entry_uid = :entry_uid
       AND workflow_stage = 'COMPLETE'"
);

$insertJobStmt = $pdo->prepare(
    "INSERT INTO worker_jobs (
        job_type,
        entry_uid,
        submitter,
        stage_label,
        payload_json,
        status,
        priority,
        attempt_count,
        run_after,
        submitted_at
     ) VALUES (
        :job_type,
        :entry_uid,
        :submitter,
        :stage_label,
        :payload_json,
        :status,
        :priority,
        0,
        UTC_TIMESTAMP(),
        UTC_TIMESTAMP()
     )"
);

$updatedStages = 0;
$queuedJobs = 0;

$pdo->beginTransaction();
try {
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $entryUid = (string) ($row['entry_uid'] ?? '');
        $userId = (int) ($row['user_id'] ?? 0);
        $hasActiveJob = (int) ($row['has_active_job'] ?? 0) === 1;

        if ($entryUid === '' || $userId <= 0) {
            continue;
        }

        $updateStageStmt->execute(['entry_uid' => $entryUid]);
        if ($updateStageStmt->rowCount() > 0) {
            $updatedStages++;
        }

        if ($hasActiveJob) {
            continue;
        }

        $payload = [
            'entry_uid' => $entryUid,
            'user_id' => $userId,
            'source' => 'repair',
            'pipeline' => [
                'completed' => [],
                'remaining_labels' => $pipelineStages,
            ],
        ];

        $insertJobStmt->execute([
            'job_type' => 'entry_process_pipeline',
            'entry_uid' => $entryUid,
            'submitter' => 'SYSTEM',
            'stage_label' => 'Queued',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'status' => 'queued',
            'priority' => 45,
        ]);
        $queuedJobs++;
    }

    $pdo->commit();
} catch (Throwable $throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Repair failed: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Stages moved to IN_PROCESS: ' . $updatedStages . PHP_EOL;
echo 'Pipeline jobs queued: ' . $queuedJobs . PHP_EOL;
