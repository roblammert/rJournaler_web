<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';

use App\Core\Database;

$dryRun = in_array('--dry-run', $argv, true);

$defaultLocation = [
    'key' => 'new_richmond_wi',
    'label' => 'New Richmond, WI, US',
    'city' => 'New Richmond',
    'state' => 'WI',
    'zip' => '54017',
    'country' => 'US',
];

$defaultStages = [
    'meta_group_0' => 'Meta Group 0',
    'meta_group_1' => 'Meta Group 1',
    'meta_group_2_llm' => 'Meta Group 2 (LLM)',
    'meta_group_3_weather' => 'Meta Group 3 (Weather)',
    'metrics_finalize' => 'Metrics Finalize',
];

$resolvePipelineStages = static function (array $defaultStages): array {
    $path = dirname(__DIR__) . '/python/worker/pipeline_stages.json';
    if (!is_file($path)) {
        return $defaultStages;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $defaultStages;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaultStages;
    }

    $resolved = [];
    foreach ($decoded as $stage) {
        if (!is_array($stage)) {
            continue;
        }
        $id = trim((string) ($stage['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $label = trim((string) ($stage['label'] ?? $id));
        if ($label === '') {
            $label = $id;
        }
        $resolved[$id] = $label;
    }

    if (!isset($resolved['meta_group_3_weather'])) {
        $resolved['meta_group_3_weather'] = 'Meta Group 3 (Weather)';
    }

    return count($resolved) > 0 ? $resolved : $defaultStages;
};

try {
    $pdo = Database::connection($config['database']);
    $stageMap = $resolvePipelineStages($defaultStages);
    $stageIds = array_keys($stageMap);

    $locationJson = json_encode($defaultLocation, JSON_UNESCAPED_SLASHES);
    if (!is_string($locationJson) || $locationJson === '') {
        throw new RuntimeException('Unable to encode default location payload');
    }

    $locationUpdateStmt = $pdo->prepare(
        "
        UPDATE journal_entries
        SET weather_location_key = COALESCE(NULLIF(weather_location_key, ''), :location_key),
            weather_location_json = COALESCE(weather_location_json, CAST(:location_json AS JSON)),
            updated_at = UTC_TIMESTAMP()
        WHERE weather_location_key IS NULL
           OR weather_location_key = ''
           OR weather_location_json IS NULL
        "
    );

    $entryStmt = $pdo->query(
        "
        SELECT
            je.entry_uid,
            je.user_id,
            je.workflow_stage,
            EXISTS(SELECT 1 FROM entry_meta_group_0 g0 WHERE g0.entry_uid = je.entry_uid) AS has_g0,
            EXISTS(SELECT 1 FROM entry_meta_group_1 g1 WHERE g1.entry_uid = je.entry_uid) AS has_g1,
            EXISTS(SELECT 1 FROM entry_meta_group_2 g2 WHERE g2.entry_uid = je.entry_uid) AS has_g2,
            EXISTS(SELECT 1 FROM entry_meta_group_3 g3 WHERE g3.entry_uid = je.entry_uid) AS has_g3,
            EXISTS(SELECT 1 FROM entry_metrics em WHERE em.entry_uid = je.entry_uid) AS has_metrics,
            EXISTS(
                SELECT 1
                FROM worker_jobs wj
                WHERE wj.job_type = 'entry_process_pipeline'
                  AND wj.entry_uid = je.entry_uid
                  AND wj.status IN ('queued', 'processing')
            ) AS has_active_job
        FROM journal_entries je
        ORDER BY je.id ASC
        "
    );

    $rows = $entryStmt->fetchAll() ?: [];

    $insertStmt = $pdo->prepare(
        "
        INSERT INTO worker_jobs (
            job_type,
            entry_uid,
            submitter,
            stage_label,
            queue_comment,
            payload_json,
            status,
            priority,
            attempt_count,
            run_after,
            submitted_at
        )
        VALUES (
            'entry_process_pipeline',
            :entry_uid,
            'BACKFILL',
            'Queued',
            :queue_comment,
            :payload_json,
            'queued',
            45,
            0,
            UTC_TIMESTAMP(),
            UTC_TIMESTAMP()
        )
        "
    );

    $totalEntries = 0;
    $alreadyQueued = 0;
    $alreadyComplete = 0;
    $queued = 0;

    if (!$dryRun) {
        $locationUpdateStmt->execute([
            'location_key' => (string) $defaultLocation['key'],
            'location_json' => $locationJson,
        ]);
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $totalEntries += 1;

        $entryUid = trim((string) ($row['entry_uid'] ?? ''));
        if ($entryUid === '') {
            continue;
        }

        if ((int) ($row['has_active_job'] ?? 0) === 1) {
            $alreadyQueued += 1;
            continue;
        }

        $presence = [
            'meta_group_0' => (int) ($row['has_g0'] ?? 0) === 1,
            'meta_group_1' => (int) ($row['has_g1'] ?? 0) === 1,
            'meta_group_2_llm' => (int) ($row['has_g2'] ?? 0) === 1,
            'meta_group_3_weather' => (int) ($row['has_g3'] ?? 0) === 1,
            'metrics_finalize' => (int) ($row['has_metrics'] ?? 0) === 1,
        ];

        $completed = [];
        $remaining = [];
        foreach ($stageIds as $stageId) {
            if (($presence[$stageId] ?? false) === true) {
                $completed[] = $stageId;
            } else {
                $remaining[] = $stageId;
            }
        }

        if (count($remaining) === 0) {
            $alreadyComplete += 1;
            continue;
        }

        $remainingLabels = array_values(array_map(
            static fn(string $stageId): string => $stageMap[$stageId] ?? $stageId,
            $remaining
        ));

        $payload = [
            'entry_uid' => $entryUid,
            'user_id' => (int) ($row['user_id'] ?? 0),
            'source' => 'meta_group_3_backfill',
            'pipeline' => [
                'completed' => $completed,
                'remaining' => $remaining,
                'remaining_labels' => $remainingLabels,
                'stages' => array_map(
                    static fn(string $stageId): array => ['id' => $stageId, 'label' => $stageMap[$stageId] ?? $stageId],
                    $stageIds
                ),
            ],
        ];

        if ($dryRun) {
            $queued += 1;
            continue;
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson) || $payloadJson === '') {
            continue;
        }

        $insertStmt->execute([
            'entry_uid' => $entryUid,
            'queue_comment' => 'Backfill queue for Meta Group 3 catch-up',
            'payload_json' => $payloadJson,
        ]);
        if ($insertStmt->rowCount() > 0) {
            $queued += 1;
        }
    }

    fwrite(STDOUT, "Meta Group 3 backfill summary\n");
    fwrite(STDOUT, "- dry_run: " . ($dryRun ? 'yes' : 'no') . "\n");
    fwrite(STDOUT, "- total_entries: {$totalEntries}\n");
    fwrite(STDOUT, "- already_queued_or_processing: {$alreadyQueued}\n");
    fwrite(STDOUT, "- already_complete: {$alreadyComplete}\n");
    fwrite(STDOUT, "- queued_for_backfill: {$queued}\n");

    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Backfill queue script failed: ' . $throwable->getMessage() . "\n");
    exit(1);
}
