<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__) . '/app/Auth/require_auth.php';
require_once dirname(__DIR__) . '/app/Auth/require_admin.php';

use App\Auth\Auth;
use App\Core\Database;
use App\Entry\EntryUid;
use App\Security\Csrf;

$interfaceTheme = Auth::interfaceTheme();
$adminUserId = Auth::userId();
$token = Csrf::token();

$stageMap = [
    'meta_group_0' => 'Meta Group 0',
    'meta_group_1' => 'Meta Group 1',
    'meta_group_2_llm' => 'Meta Group 2 (LLM)',
    'meta_group_3_weather' => 'Meta Group 3 (Weather)',
    'metrics_finalize' => 'Metrics Finalize',
];
$defaultStageIds = array_keys($stageMap);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40;
$offset = ($page - 1) * $perPage;
$maxFilteredQueueSize = 1000;
$defaultMaxRows = 200;
$defaultBatchSize = 50;
$maxBatchSize = 200;

$rawQuery = trim((string) ($_GET['q'] ?? ''));
$rawWorkflowStage = trim((string) ($_GET['workflow_stage'] ?? ''));
$rawUserId = trim((string) ($_GET['user_id'] ?? ''));
$rawDateFrom = trim((string) ($_GET['date_from'] ?? ''));
$rawDateTo = trim((string) ($_GET['date_to'] ?? ''));
$rawMaxRows = trim((string) ($_GET['max_rows'] ?? (string) $defaultMaxRows));
$rawBatchSize = trim((string) ($_GET['batch_size'] ?? (string) $defaultBatchSize));
$rawPresetName = trim((string) ($_GET['preset_name'] ?? ''));

$effectiveMaxRows = ctype_digit($rawMaxRows) ? (int) $rawMaxRows : $defaultMaxRows;
$effectiveMaxRows = max(1, min($maxFilteredQueueSize, $effectiveMaxRows));

$effectiveBatchSize = ctype_digit($rawBatchSize) ? (int) $rawBatchSize : $defaultBatchSize;
$effectiveBatchSize = max(1, min($maxBatchSize, $effectiveBatchSize));

$currentQuery = $rawQuery;
$currentWorkflowStage = $rawWorkflowStage;
$currentUserId = $rawUserId;
$currentDateFrom = $rawDateFrom;
$currentDateTo = $rawDateTo;
$currentSelectedStageIds = $defaultStageIds;
$currentMaxRows = $effectiveMaxRows;
$currentBatchSize = $effectiveBatchSize;
$currentPresetName = $rawPresetName;

$flashError = null;
$flashSuccess = null;
$previewStats = null;
$savedPresets = [];

/**
 * @return array{joins:string, where:string, params:array<string,mixed>}
 */
$buildFilterSql = static function (
    string $queryText,
    string $workflowStage,
    string $userIdText,
    string $dateFrom,
    string $dateTo
): array {
    $joins = "\n        LEFT JOIN users u ON u.id = je.user_id\n        LEFT JOIN entry_meta_group_0 g0 ON g0.entry_uid = je.entry_uid\n        LEFT JOIN entry_meta_group_1 g1 ON g1.entry_uid = je.entry_uid\n        LEFT JOIN entry_meta_group_2 g2 ON g2.entry_uid = je.entry_uid\n        LEFT JOIN entry_meta_group_3 g3 ON g3.entry_uid = je.entry_uid\n        LEFT JOIN entry_metrics em ON em.entry_uid = je.entry_uid\n    ";

    $whereParts = [];
    $params = [];

    if ($queryText !== '') {
        $likeValue = '%' . $queryText . '%';
        $searchExpressions = [
            'je.entry_uid',
            'je.title',
            'je.content_raw',
            "COALESCE(g0.entry_title, '')",
            'CAST(COALESCE(g1.word_count, 0) AS CHAR)',
            'CAST(COALESCE(g1.reading_time_minutes, 0) AS CHAR)',
            "COALESCE(CAST(g2.analysis_json AS CHAR), '')",
            "COALESCE(g3.current_summary, '')",
            "COALESCE(g3.location_label, '')",
            "COALESCE(CAST(g3.weather_json AS CHAR), '')",
            "COALESCE(CAST(em.metrics_json AS CHAR), '')",
            "COALESCE(u.username, '')",
        ];

        $searchParts = [];
        foreach ($searchExpressions as $idx => $expression) {
            $paramKey = 'query_like_' . $idx;
            $searchParts[] = $expression . ' LIKE :' . $paramKey;
            $params[$paramKey] = $likeValue;
        }

        $whereParts[] = '(' . implode(' OR ', $searchParts) . ')';
    }

    if ($workflowStage !== '') {
        $whereParts[] = 'je.workflow_stage = :workflow_stage';
        $params['workflow_stage'] = $workflowStage;
    }

    if ($userIdText !== '' && ctype_digit($userIdText)) {
        $whereParts[] = 'je.user_id = :user_id';
        $params['user_id'] = (int) $userIdText;
    }

    if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) === 1) {
        $whereParts[] = 'je.entry_date >= :date_from';
        $params['date_from'] = $dateFrom;
    }

    if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) === 1) {
        $whereParts[] = 'je.entry_date <= :date_to';
        $params['date_to'] = $dateTo;
    }

    $whereSql = count($whereParts) > 0 ? ('WHERE ' . implode(' AND ', $whereParts)) : '';
    return ['joins' => $joins, 'where' => $whereSql, 'params' => $params];
};

/** @return string[] */
$parseSelectedStages = static function (array $input, array $allowedStageMap, array $fallback): array {
    $selected = [];
    foreach ($input as $value) {
        if (!is_string($value)) {
            continue;
        }
        $stageId = trim($value);
        if ($stageId === '' || !array_key_exists($stageId, $allowedStageMap)) {
            continue;
        }
        if (!in_array($stageId, $selected, true)) {
            $selected[] = $stageId;
        }
    }

    return count($selected) > 0 ? $selected : $fallback;
};

/**
 * @return array<int,array{name:string,filters:array<string,string>,stages:array<int,string>,max_rows:int,batch_size:int,updated_at:string}>
 */
$loadPresets = static function (PDO $pdo, int $userId) use ($defaultMaxRows, $defaultBatchSize, $maxFilteredQueueSize, $maxBatchSize): array {
    try {
        $settingKey = 'admin.reprocess.presets.user_' . $userId;
        $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :setting_key LIMIT 1');
        $stmt->execute(['setting_key' => $settingKey]);
        $rawValue = $stmt->fetchColumn();
    } catch (Throwable $throwable) {
        // Keep page functional even when app_settings schema is not available yet.
        return [];
    }

    if (!is_string($rawValue) || trim($rawValue) === '') {
        return [];
    }

    $decoded = json_decode($rawValue, true);
    if (!is_array($decoded)) {
        return [];
    }

    $out = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $filtersRaw = is_array($row['filters'] ?? null) ? $row['filters'] : [];
        $filters = [
            'q' => trim((string) ($filtersRaw['q'] ?? '')),
            'workflow_stage' => trim((string) ($filtersRaw['workflow_stage'] ?? '')),
            'user_id' => trim((string) ($filtersRaw['user_id'] ?? '')),
            'date_from' => trim((string) ($filtersRaw['date_from'] ?? '')),
            'date_to' => trim((string) ($filtersRaw['date_to'] ?? '')),
        ];

        $stagesRaw = is_array($row['stages'] ?? null) ? $row['stages'] : [];
        $stages = [];
        foreach ($stagesRaw as $stageValue) {
            if (!is_string($stageValue)) {
                continue;
            }
            $stageId = trim($stageValue);
            if ($stageId !== '' && !in_array($stageId, $stages, true)) {
                $stages[] = $stageId;
            }
        }

        $maxRows = (int) ($row['max_rows'] ?? $defaultMaxRows);
        $maxRows = max(1, min($maxFilteredQueueSize, $maxRows));

        $batchSize = (int) ($row['batch_size'] ?? $defaultBatchSize);
        $batchSize = max(1, min($maxBatchSize, $batchSize));

        $updatedAt = trim((string) ($row['updated_at'] ?? ''));

        $out[] = [
            'name' => $name,
            'filters' => $filters,
            'stages' => $stages,
            'max_rows' => $maxRows,
            'batch_size' => $batchSize,
            'updated_at' => $updatedAt,
        ];
    }

    usort($out, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
    return $out;
};

$savePresets = static function (PDO $pdo, int $userId, array $presets): void {
    $settingKey = 'admin.reprocess.presets.user_' . $userId;
    $encoded = json_encode($presets, JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode preset payload.');
    }

    // Support both legacy and current app_settings schemas.
    $columnStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $columnStmt->execute([
        'table_name' => 'app_settings',
        'column_name' => 'updated_by_user_id',
    ]);
    $hasUpdatedByUserId = (int) $columnStmt->fetchColumn() > 0;

    if ($hasUpdatedByUserId) {
        $stmt = $pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value, updated_by_user_id, updated_at)
             VALUES (:setting_key, :setting_value, :updated_by_user_id, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               setting_value = VALUES(setting_value),
               updated_by_user_id = VALUES(updated_by_user_id),
               updated_at = UTC_TIMESTAMP()'
        );
        $stmt->execute([
            'setting_key' => $settingKey,
            'setting_value' => $encoded,
            'updated_by_user_id' => $userId,
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, updated_at)
         VALUES (:setting_key, :setting_value, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
           setting_value = VALUES(setting_value),
           updated_at = UTC_TIMESTAMP()'
    );
    $stmt->execute([
        'setting_key' => $settingKey,
        'setting_value' => $encoded,
    ]);
};

/**
 * @param string[] $selectedStageIds
 * @param array<string,int> $entryUserMap
 * @return array<string,mixed>
 */
$queueEntriesForReprocess = static function (
    PDO $pdo,
    array $entryUids,
    array $selectedStageIds,
    array $stageMapLocal,
    array $entryUserMap,
    string $sourceTag,
    int $maxRows,
    int $batchSize,
    bool $dryRun = false
): array {
    $defaultStageIdsLocal = array_keys($stageMapLocal);
    $skippedStageIds = array_values(array_diff($defaultStageIdsLocal, $selectedStageIds));
    $selectedStageLabels = [];
    foreach ($selectedStageIds as $stageId) {
        $selectedStageLabels[] = $stageMapLocal[$stageId];
    }

    $checkActiveStmt = $pdo->prepare(
        "SELECT COUNT(*)\n         FROM worker_jobs\n         WHERE job_type = 'entry_process_pipeline'\n           AND entry_uid = :entry_uid\n           AND status IN ('queued', 'processing')"
    );

    $deleteG0Stmt = null;
    $deleteG1Stmt = null;
    $deleteG2Stmt = null;
    $deleteG3Stmt = null;
    $deleteMetricsStmt = null;
    $setStageStmt = null;
    $queueStmt = null;

    if (!$dryRun) {
        $deleteG0Stmt = $pdo->prepare('DELETE FROM entry_meta_group_0 WHERE entry_uid = :entry_uid');
        $deleteG1Stmt = $pdo->prepare('DELETE FROM entry_meta_group_1 WHERE entry_uid = :entry_uid');
        $deleteG2Stmt = $pdo->prepare('DELETE FROM entry_meta_group_2 WHERE entry_uid = :entry_uid');
        $deleteG3Stmt = $pdo->prepare('DELETE FROM entry_meta_group_3 WHERE entry_uid = :entry_uid');
        $deleteMetricsStmt = $pdo->prepare('DELETE FROM entry_metrics WHERE entry_uid = :entry_uid');

        $setStageStmt = $pdo->prepare(
            "UPDATE journal_entries\n             SET body_locked = 0,\n                 workflow_stage = 'IN_PROCESS',\n                 stage_updated_at = UTC_TIMESTAMP(),\n                 updated_at = UTC_TIMESTAMP()\n             WHERE entry_uid = :entry_uid"
        );

        $queueStmt = $pdo->prepare(
            "INSERT INTO worker_jobs (job_type, entry_uid, submitter, stage_label, queue_comment, payload_json, status, priority, attempt_count, run_after, submitted_at)\n             VALUES (:job_type, :entry_uid, :submitter, :stage_label, :queue_comment, :payload_json, :status, :priority, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
    }

    $queuedCount = 0;
    $skippedActiveCount = 0;
    $invalidCount = 0;
    $consideredCount = 0;
    $batchCount = 0;
    $previewQueueCandidates = [];

    $queuedInCurrentBatch = 0;

    if (!$dryRun) {
        $pdo->beginTransaction();
    }

    try {
        foreach ($entryUids as $entryUid) {
            if ($consideredCount >= $maxRows) {
                break;
            }

            if (!is_string($entryUid) || !EntryUid::isValid($entryUid)) {
                $invalidCount++;
                continue;
            }

            $consideredCount++;

            $checkActiveStmt->execute(['entry_uid' => $entryUid]);
            $activeCount = (int) $checkActiveStmt->fetchColumn();
            if ($activeCount > 0) {
                $skippedActiveCount++;
                continue;
            }

            if (count($previewQueueCandidates) < 25) {
                $previewQueueCandidates[] = $entryUid;
            }

            if ($dryRun) {
                $queuedCount++;
                continue;
            }

            if (in_array('meta_group_0', $selectedStageIds, true) && $deleteG0Stmt instanceof PDOStatement) {
                $deleteG0Stmt->execute(['entry_uid' => $entryUid]);
            }
            if (in_array('meta_group_1', $selectedStageIds, true) && $deleteG1Stmt instanceof PDOStatement) {
                $deleteG1Stmt->execute(['entry_uid' => $entryUid]);
            }
            if (in_array('meta_group_2_llm', $selectedStageIds, true) && $deleteG2Stmt instanceof PDOStatement) {
                $deleteG2Stmt->execute(['entry_uid' => $entryUid]);
            }
            if (in_array('meta_group_3_weather', $selectedStageIds, true) && $deleteG3Stmt instanceof PDOStatement) {
                $deleteG3Stmt->execute(['entry_uid' => $entryUid]);
            }
            if (in_array('metrics_finalize', $selectedStageIds, true) && $deleteMetricsStmt instanceof PDOStatement) {
                $deleteMetricsStmt->execute(['entry_uid' => $entryUid]);
            }

            if ($setStageStmt instanceof PDOStatement) {
                $setStageStmt->execute(['entry_uid' => $entryUid]);
            }

            $entryUserId = (int) ($entryUserMap[$entryUid] ?? 0);
            $payload = [
                'entry_uid' => $entryUid,
                'user_id' => $entryUserId,
                'source' => $sourceTag,
                'pipeline' => [
                    'completed' => $skippedStageIds,
                    'remaining' => $selectedStageIds,
                    'remaining_labels' => $selectedStageLabels,
                ],
            ];

            if ($queueStmt instanceof PDOStatement) {
                $queueStmt->execute([
                    'job_type' => 'entry_process_pipeline',
                    'entry_uid' => $entryUid,
                    'submitter' => 'ADMIN',
                    'stage_label' => 'Queued',
                    'queue_comment' => 'Queued by admin targeted reprocess',
                    'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                    'status' => 'queued',
                    'priority' => 35,
                ]);
            }

            if ($queueStmt instanceof PDOStatement && $queueStmt->rowCount() > 0) {
                $queuedCount++;
                $queuedInCurrentBatch++;
            }

            if ($queuedInCurrentBatch >= $batchSize) {
                $pdo->commit();
                $batchCount++;
                $queuedInCurrentBatch = 0;
                $pdo->beginTransaction();
            }
        }

        if (!$dryRun) {
            if ($pdo->inTransaction()) {
                $pdo->commit();
                if ($queuedInCurrentBatch > 0) {
                    $batchCount++;
                }
            }
        }
    } catch (Throwable $throwable) {
        if (!$dryRun && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $throwable;
    }

    return [
        'requested' => count($entryUids),
        'considered' => $consideredCount,
        'queued' => $queuedCount,
        'skipped_active' => $skippedActiveCount,
        'invalid' => $invalidCount,
        'batch_count' => $batchCount,
        'dry_run' => $dryRun,
        'preview_uids' => $previewQueueCandidates,
    ];
};

try {
    $pdo = Database::connection($config['database']);
    $savedPresets = $loadPresets($pdo, (int) $adminUserId);

    if ($currentPresetName !== '') {
        foreach ($savedPresets as $savedPreset) {
            if (!is_array($savedPreset) || (string) ($savedPreset['name'] ?? '') !== $currentPresetName) {
                continue;
            }
            $presetFilters = is_array($savedPreset['filters'] ?? null) ? $savedPreset['filters'] : [];
            $currentQuery = trim((string) ($presetFilters['q'] ?? $currentQuery));
            $currentWorkflowStage = trim((string) ($presetFilters['workflow_stage'] ?? $currentWorkflowStage));
            $currentUserId = trim((string) ($presetFilters['user_id'] ?? $currentUserId));
            $currentDateFrom = trim((string) ($presetFilters['date_from'] ?? $currentDateFrom));
            $currentDateTo = trim((string) ($presetFilters['date_to'] ?? $currentDateTo));
            $currentSelectedStageIds = $parseSelectedStages(
                is_array($savedPreset['stages'] ?? null) ? $savedPreset['stages'] : [],
                $stageMap,
                $defaultStageIds
            );
            $currentMaxRows = max(1, min($maxFilteredQueueSize, (int) ($savedPreset['max_rows'] ?? $currentMaxRows)));
            $currentBatchSize = max(1, min($maxBatchSize, (int) ($savedPreset['batch_size'] ?? $currentBatchSize)));
            break;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['_csrf'] ?? null;
        if (!Csrf::validate(is_string($csrf) ? $csrf : null)) {
            $flashError = 'Invalid request token.';
        } else {
            $postAction = trim((string) ($_POST['action'] ?? ''));
            try {
                $selectedStageIds = $parseSelectedStages(
                    isset($_POST['reprocess_stages']) && is_array($_POST['reprocess_stages']) ? $_POST['reprocess_stages'] : [],
                    $stageMap,
                    $defaultStageIds
                );

                $activeFilters = [
                    'q' => trim((string) ($_POST['filter_q'] ?? $currentQuery)),
                    'workflow_stage' => trim((string) ($_POST['filter_workflow_stage'] ?? $currentWorkflowStage)),
                    'user_id' => trim((string) ($_POST['filter_user_id'] ?? $currentUserId)),
                    'date_from' => trim((string) ($_POST['filter_date_from'] ?? $currentDateFrom)),
                    'date_to' => trim((string) ($_POST['filter_date_to'] ?? $currentDateTo)),
                ];

                $postedMaxRows = trim((string) ($_POST['max_rows'] ?? (string) $currentMaxRows));
                $postedBatchSize = trim((string) ($_POST['batch_size'] ?? (string) $currentBatchSize));
                $currentMaxRows = ctype_digit($postedMaxRows) ? (int) $postedMaxRows : $defaultMaxRows;
                $currentMaxRows = max(1, min($maxFilteredQueueSize, $currentMaxRows));
                $currentBatchSize = ctype_digit($postedBatchSize) ? (int) $postedBatchSize : $defaultBatchSize;
                $currentBatchSize = max(1, min($maxBatchSize, $currentBatchSize));

                $currentQuery = $activeFilters['q'];
                $currentWorkflowStage = $activeFilters['workflow_stage'];
                $currentUserId = $activeFilters['user_id'];
                $currentDateFrom = $activeFilters['date_from'];
                $currentDateTo = $activeFilters['date_to'];
                $currentSelectedStageIds = $selectedStageIds;

                if ($postAction === 'save_preset') {
                $presetName = trim((string) ($_POST['preset_name_new'] ?? ($_POST['preset_name'] ?? '')));
                if ($presetName === '') {
                    $flashError = 'Preset name is required.';
                } else {
                    $newPreset = [
                        'name' => $presetName,
                        'filters' => $activeFilters,
                        'stages' => $selectedStageIds,
                        'max_rows' => $currentMaxRows,
                        'batch_size' => $currentBatchSize,
                        'updated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
                    ];

                    $updated = [];
                    $replaced = false;
                    foreach ($savedPresets as $preset) {
                        if (!is_array($preset)) {
                            continue;
                        }
                        if ((string) ($preset['name'] ?? '') === $presetName) {
                            $updated[] = $newPreset;
                            $replaced = true;
                            continue;
                        }
                        $updated[] = $preset;
                    }
                    if (!$replaced) {
                        $updated[] = $newPreset;
                    }

                    usort($updated, static fn(array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
                    $savePresets($pdo, (int) $adminUserId, $updated);
                    $savedPresets = $loadPresets($pdo, (int) $adminUserId);
                    $currentPresetName = $presetName;
                    $flashSuccess = $replaced ? 'Preset updated.' : 'Preset saved.';
                }
                } elseif ($postAction === 'delete_preset') {
                $presetName = trim((string) ($_POST['preset_name'] ?? ''));
                if ($presetName === '') {
                    $flashError = 'Choose a preset to delete.';
                } else {
                    $updated = [];
                    $deleted = false;
                    foreach ($savedPresets as $preset) {
                        if (!is_array($preset)) {
                            continue;
                        }
                        if ((string) ($preset['name'] ?? '') === $presetName) {
                            $deleted = true;
                            continue;
                        }
                        $updated[] = $preset;
                    }

                    if ($deleted) {
                        $savePresets($pdo, (int) $adminUserId, $updated);
                        $savedPresets = $loadPresets($pdo, (int) $adminUserId);
                        if ($currentPresetName === $presetName) {
                            $currentPresetName = '';
                        }
                        $flashSuccess = 'Preset deleted.';
                    } else {
                        $flashError = 'Preset not found.';
                    }
                }
                } elseif ($postAction === 'load_preset') {
                $presetName = trim((string) ($_POST['preset_name'] ?? ''));
                if ($presetName === '') {
                    $flashError = 'Choose a preset to load.';
                } else {
                    $loaded = false;
                    foreach ($savedPresets as $savedPreset) {
                        if (!is_array($savedPreset) || (string) ($savedPreset['name'] ?? '') !== $presetName) {
                            continue;
                        }
                        $presetFilters = is_array($savedPreset['filters'] ?? null) ? $savedPreset['filters'] : [];
                        $currentQuery = trim((string) ($presetFilters['q'] ?? ''));
                        $currentWorkflowStage = trim((string) ($presetFilters['workflow_stage'] ?? ''));
                        $currentUserId = trim((string) ($presetFilters['user_id'] ?? ''));
                        $currentDateFrom = trim((string) ($presetFilters['date_from'] ?? ''));
                        $currentDateTo = trim((string) ($presetFilters['date_to'] ?? ''));
                        $currentSelectedStageIds = $parseSelectedStages(
                            is_array($savedPreset['stages'] ?? null) ? $savedPreset['stages'] : [],
                            $stageMap,
                            $defaultStageIds
                        );
                        $currentMaxRows = max(1, min($maxFilteredQueueSize, (int) ($savedPreset['max_rows'] ?? $defaultMaxRows)));
                        $currentBatchSize = max(1, min($maxBatchSize, (int) ($savedPreset['batch_size'] ?? $defaultBatchSize)));
                        $currentPresetName = $presetName;
                        $loaded = true;
                        break;
                    }
                    if ($loaded) {
                        $flashSuccess = 'Preset loaded.';
                    } else {
                        $flashError = 'Preset not found.';
                    }
                }
                }

                if (in_array($postAction, ['queue_selected', 'preview_selected'], true)) {
                $requestedUidsRaw = isset($_POST['entry_uids']) && is_array($_POST['entry_uids']) ? $_POST['entry_uids'] : [];
                $requestedUids = [];
                foreach ($requestedUidsRaw as $value) {
                    if (!is_string($value)) {
                        continue;
                    }
                    $uid = trim($value);
                    if ($uid !== '' && !in_array($uid, $requestedUids, true)) {
                        $requestedUids[] = $uid;
                    }
                }

                if (count($requestedUids) === 0) {
                    $flashError = 'No entries selected for reprocess.';
                } else {
                    $placeholders = implode(',', array_fill(0, count($requestedUids), '?'));
                    $ownerStmt = $pdo->prepare(
                        "SELECT entry_uid, user_id\n                         FROM journal_entries\n                         WHERE entry_uid IN ({$placeholders})"
                    );
                    $ownerStmt->execute($requestedUids);
                    $ownerRows = $ownerStmt->fetchAll() ?: [];
                    $entryUserMap = [];
                    foreach ($ownerRows as $ownerRow) {
                        if (!is_array($ownerRow)) {
                            continue;
                        }
                        $uid = trim((string) ($ownerRow['entry_uid'] ?? ''));
                        if ($uid === '') {
                            continue;
                        }
                        $entryUserMap[$uid] = (int) ($ownerRow['user_id'] ?? 0);
                    }

                    $stats = $queueEntriesForReprocess(
                        $pdo,
                        $requestedUids,
                        $selectedStageIds,
                        $stageMap,
                        $entryUserMap,
                        'admin_targeted_reprocess',
                        $currentMaxRows,
                        $currentBatchSize,
                        $postAction === 'preview_selected'
                    );

                    if ($postAction === 'preview_selected') {
                        $previewStats = $stats;
                        $flashSuccess = 'Preview generated for selected entries.';
                    } else {
                        $flashSuccess = 'Queued: ' . $stats['queued']
                            . ' | Considered: ' . $stats['considered']
                            . ' | Batches: ' . $stats['batch_count']
                            . ' | Skipped (active job): ' . $stats['skipped_active']
                            . ' | Invalid/missing: ' . $stats['invalid'];
                    }
                }
                } elseif (in_array($postAction, ['queue_filtered', 'preview_filtered'], true)) {
                $filterSql = $buildFilterSql(
                    $activeFilters['q'],
                    $activeFilters['workflow_stage'],
                    $activeFilters['user_id'],
                    $activeFilters['date_from'],
                    $activeFilters['date_to']
                );

                $uidsStmt = $pdo->prepare(
                    "SELECT je.entry_uid, je.user_id\n                     FROM journal_entries je\n                     {$filterSql['joins']}\n                     {$filterSql['where']}\n                     ORDER BY je.updated_at DESC\n                     LIMIT :max_limit"
                );
                foreach ($filterSql['params'] as $paramKey => $paramValue) {
                    if (is_int($paramValue)) {
                        $uidsStmt->bindValue(':' . $paramKey, $paramValue, PDO::PARAM_INT);
                    } else {
                        $uidsStmt->bindValue(':' . $paramKey, (string) $paramValue, PDO::PARAM_STR);
                    }
                }
                $uidsStmt->bindValue(':max_limit', $currentMaxRows, PDO::PARAM_INT);
                $uidsStmt->execute();
                $uidRows = $uidsStmt->fetchAll() ?: [];

                $entryUids = [];
                $entryUserMap = [];
                foreach ($uidRows as $uidRow) {
                    if (!is_array($uidRow)) {
                        continue;
                    }
                    $uid = trim((string) ($uidRow['entry_uid'] ?? ''));
                    if ($uid === '' || in_array($uid, $entryUids, true)) {
                        continue;
                    }
                    $entryUids[] = $uid;
                    $entryUserMap[$uid] = (int) ($uidRow['user_id'] ?? 0);
                }

                if (count($entryUids) === 0) {
                    $flashError = 'No matching entries found for current filters.';
                } else {
                    $stats = $queueEntriesForReprocess(
                        $pdo,
                        $entryUids,
                        $selectedStageIds,
                        $stageMap,
                        $entryUserMap,
                        'admin_filtered_reprocess',
                        $currentMaxRows,
                        $currentBatchSize,
                        $postAction === 'preview_filtered'
                    );

                    if ($postAction === 'preview_filtered') {
                        $previewStats = $stats;
                        $flashSuccess = 'Preview generated for filtered entries.';
                    } else {
                        $flashSuccess = 'Filtered queue completed. Matched: ' . count($entryUids)
                            . ' | Considered: ' . $stats['considered']
                            . ' | Queued: ' . $stats['queued']
                            . ' | Batches: ' . $stats['batch_count']
                            . ' | Skipped (active job): ' . $stats['skipped_active']
                            . ' | Invalid/missing: ' . $stats['invalid'];
                    }
                }
                }
            } catch (Throwable $actionThrowable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[admin-reprocess action:' . $postAction . '] ' . $actionThrowable->getMessage());
                $flashError = 'Action failed: ' . $actionThrowable->getMessage();
            }
        }
    }

    $filterSql = $buildFilterSql($currentQuery, $currentWorkflowStage, $currentUserId, $currentDateFrom, $currentDateTo);

    $countStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT je.entry_uid) AS total_rows\n         FROM journal_entries je\n         {$filterSql['joins']}\n         {$filterSql['where']}"
    );
    foreach ($filterSql['params'] as $paramKey => $paramValue) {
        if (is_int($paramValue)) {
            $countStmt->bindValue(':' . $paramKey, $paramValue, PDO::PARAM_INT);
        } else {
            $countStmt->bindValue(':' . $paramKey, (string) $paramValue, PDO::PARAM_STR);
        }
    }
    $countStmt->execute();
    $totalRows = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $listStmt = $pdo->prepare(
        "SELECT\n            je.entry_uid,\n            je.user_id,\n            COALESCE(u.username, CONCAT('user#', je.user_id)) AS username,\n            je.entry_date,\n            je.title,\n            je.workflow_stage,\n            je.updated_at,\n            LEFT(REPLACE(REPLACE(je.content_raw, '\\r', ' '), '\\n', ' '), 180) AS content_excerpt,\n            (g0.entry_uid IS NOT NULL) AS has_g0,\n            (g1.entry_uid IS NOT NULL) AS has_g1,\n            (g2.entry_uid IS NOT NULL) AS has_g2,\n            (g3.entry_uid IS NOT NULL) AS has_g3,\n            (em.entry_uid IS NOT NULL) AS has_metrics,\n            EXISTS(\n                SELECT 1\n                FROM worker_jobs wj\n                WHERE wj.job_type = 'entry_process_pipeline'\n                  AND wj.entry_uid = je.entry_uid\n                  AND wj.status IN ('queued', 'processing')\n            ) AS has_active_job\n         FROM journal_entries je\n         {$filterSql['joins']}\n         {$filterSql['where']}\n         GROUP BY je.entry_uid, je.user_id, u.username, je.entry_date, je.title, je.workflow_stage, je.updated_at, je.content_raw, g0.entry_uid, g1.entry_uid, g2.entry_uid, g3.entry_uid, em.entry_uid\n         ORDER BY je.updated_at DESC\n         LIMIT :limit_rows OFFSET :offset_rows"
    );
    foreach ($filterSql['params'] as $paramKey => $paramValue) {
        if (is_int($paramValue)) {
            $listStmt->bindValue(':' . $paramKey, $paramValue, PDO::PARAM_INT);
        } else {
            $listStmt->bindValue(':' . $paramKey, (string) $paramValue, PDO::PARAM_STR);
        }
    }
    $listStmt->bindValue(':limit_rows', $perPage, PDO::PARAM_INT);
    $listStmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
    $listStmt->execute();
    $rows = $listStmt->fetchAll() ?: [];

    $workflowStages = ['AUTOSAVE', 'WRITTEN', 'FINISHED', 'IN_PROCESS', 'COMPLETE', 'REPROCESS', 'FINAL', 'ERROR'];
} catch (Throwable $throwable) {
    error_log('[admin-reprocess] ' . $throwable->getMessage());
    http_response_code(500);
    $flashError = 'Unable to load admin reprocess page right now.';
    $rows = [];
    $workflowStages = ['AUTOSAVE', 'WRITTEN', 'FINISHED', 'IN_PROCESS', 'COMPLETE', 'REPROCESS', 'FINAL', 'ERROR'];
    $totalRows = 0;
    $totalPages = 1;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Targeted Reprocess</title>
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
            --err-bg: #fdeef0;
            --err-border: #d78a95;
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
            --err-bg: #f8ecec;
            --err-border: #b88c8c;
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
            --err-bg: #41262b;
            --err-border: #91535f;
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
            max-width: 1380px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.6rem;
            margin-bottom: 0.6rem;
        }

        .header-links {
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
            text-decoration: none;
        }

        .panel {
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface);
            box-shadow: var(--shadow-sm);
            padding: 0.8rem;
            margin-bottom: 0.8rem;
        }

        .alert {
            border: 1px solid transparent;
            border-radius: 8px;
            padding: 0.55rem 0.7rem;
            margin-bottom: 0.65rem;
        }

        .alert-ok {
            background: var(--ok-bg);
            border-color: var(--ok-border);
        }

        .alert-err {
            background: var(--err-bg);
            border-color: var(--err-border);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(180px, 1fr));
            gap: 0.55rem;
        }

        label {
            display: block;
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0.15rem;
            font-weight: 600;
        }

        input, select, button {
            font: inherit;
        }

        input, select {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface-soft);
            color: var(--text);
            padding: 0.42rem 0.5rem;
        }

        .form-actions {
            margin-top: 0.55rem;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        button {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface-soft);
            color: var(--text);
            padding: 0.35rem 0.68rem;
            cursor: pointer;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            border: 1px solid var(--border);
            padding: 0.42rem 0.45rem;
            vertical-align: top;
            text-align: left;
            font-size: 0.9rem;
        }

        th {
            background: var(--surface-soft);
            color: var(--heading);
        }

        .meta-chip {
            display: inline-block;
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 0.1rem 0.42rem;
            margin: 0 0.2rem 0.2rem 0;
            font-size: 0.76rem;
        }

        .meta-chip.on {
            background: var(--ok-bg);
            border-color: var(--ok-border);
        }

        .meta-chip.off {
            opacity: 0.75;
        }

        .muted {
            color: var(--text-muted);
        }

        .bulk-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 0.7rem;
        }

        .stage-list {
            display: grid;
            grid-template-columns: repeat(3, minmax(170px, 1fr));
            gap: 0.35rem;
        }

        .pager {
            margin-top: 0.55rem;
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }

        @media (max-width: 1100px) {
            .filters-grid { grid-template-columns: repeat(2, minmax(180px, 1fr)); }
            .bulk-grid { grid-template-columns: 1fr; }
            .stage-list { grid-template-columns: repeat(2, minmax(170px, 1fr)); }
        }

        @media (max-width: 680px) {
            body { padding: 0.72rem; }
            .filters-grid { grid-template-columns: 1fr; }
            .stage-list { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars($interfaceTheme, ENT_QUOTES, 'UTF-8'); ?>">
<main>
    <header class="page-header">
        <h1>Admin Targeted Reprocess</h1>
        <div class="header-links">
            <a class="pill" href="/index.php">Back to Dashboard</a>
            <a class="pill" href="/dashboards/status.php">Queue Status</a>
            <span class="pill">Theme: <?php echo htmlspecialchars(ucfirst($interfaceTheme), ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="pill">rJournaler_Web: v<?php echo htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </header>

    <?php if ($flashError !== null): ?><div class="alert alert-err"><?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($flashSuccess !== null): ?><div class="alert alert-ok"><?php echo htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <section class="panel">
        <form method="post" action="/admin-reprocess.php" class="form-actions">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="filter_q" value="<?php echo htmlspecialchars($currentQuery, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="filter_workflow_stage" value="<?php echo htmlspecialchars($currentWorkflowStage, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="filter_user_id" value="<?php echo htmlspecialchars($currentUserId, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="filter_date_from" value="<?php echo htmlspecialchars($currentDateFrom, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="filter_date_to" value="<?php echo htmlspecialchars($currentDateTo, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="max_rows" value="<?php echo (int) $currentMaxRows; ?>">
            <input type="hidden" name="batch_size" value="<?php echo (int) $currentBatchSize; ?>">
            <?php foreach ($currentSelectedStageIds as $stageId): ?>
                <input type="hidden" name="reprocess_stages[]" value="<?php echo htmlspecialchars($stageId, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endforeach; ?>

            <label for="preset_name">Saved Preset</label>
            <select id="preset_name" name="preset_name" style="max-width: 260px;">
                <option value="">Choose preset</option>
                <?php foreach ($savedPresets as $preset): ?>
                    <?php if (!is_array($preset)) { continue; } ?>
                    <?php $presetName = trim((string) ($preset['name'] ?? '')); ?>
                    <?php if ($presetName === '') { continue; } ?>
                    <?php $presetUpdatedAt = trim((string) ($preset['updated_at'] ?? '')); ?>
                    <?php $presetLabel = $presetName . ($presetUpdatedAt !== '' ? ' (updated ' . $presetUpdatedAt . ')' : ''); ?>
                    <option value="<?php echo htmlspecialchars($presetName, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $currentPresetName === $presetName ? 'selected' : ''; ?>><?php echo htmlspecialchars($presetLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="action" value="load_preset">Load</button>
            <button type="submit" name="action" value="delete_preset" onclick="return confirm('Delete selected preset?');">Delete</button>
            <input name="preset_name_new" type="text" value="<?php echo htmlspecialchars($currentPresetName, ENT_QUOTES, 'UTF-8'); ?>" placeholder="new preset name" style="max-width: 220px;">
            <button type="submit" name="action" value="save_preset">Save Current</button>
        </form>
    </section>

    <section class="panel">
        <form method="get" action="/admin-reprocess.php">
            <input type="hidden" name="preset_name" value="<?php echo htmlspecialchars($currentPresetName, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="max_rows" value="<?php echo (int) $currentMaxRows; ?>">
            <input type="hidden" name="batch_size" value="<?php echo (int) $currentBatchSize; ?>">
            <div class="filters-grid">
                <div>
                    <label for="q">Search (entry text + meta JSON/text)</label>
                    <input id="q" name="q" type="text" value="<?php echo htmlspecialchars($currentQuery, ENT_QUOTES, 'UTF-8'); ?>" placeholder="keywords, uid, username">
                </div>
                <div>
                    <label for="workflow_stage">Workflow Stage</label>
                    <select id="workflow_stage" name="workflow_stage">
                        <option value="">Any stage</option>
                        <?php foreach ($workflowStages as $workflowStage): ?>
                            <option value="<?php echo htmlspecialchars($workflowStage, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $currentWorkflowStage === $workflowStage ? 'selected' : ''; ?>><?php echo htmlspecialchars($workflowStage, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="user_id">User ID</label>
                    <input id="user_id" name="user_id" type="number" min="1" value="<?php echo htmlspecialchars($currentUserId, ENT_QUOTES, 'UTF-8'); ?>" placeholder="optional">
                </div>
                <div>
                    <label for="date_from">Entry Date From</label>
                    <input id="date_from" name="date_from" type="date" value="<?php echo htmlspecialchars($currentDateFrom, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div>
                    <label for="date_to">Entry Date To</label>
                    <input id="date_to" name="date_to" type="date" value="<?php echo htmlspecialchars($currentDateTo, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
            <div class="form-actions">
                <button type="submit">Apply Filters</button>
                <a class="pill" href="/admin-reprocess.php">Clear</a>
                <span class="muted">Matches: <?php echo (int) $totalRows; ?></span>
            </div>
        </form>
    </section>

    <form method="post" action="/admin-reprocess.php" id="bulk-reprocess-form">
        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="filter_q" value="<?php echo htmlspecialchars($currentQuery, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="filter_workflow_stage" value="<?php echo htmlspecialchars($currentWorkflowStage, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="filter_user_id" value="<?php echo htmlspecialchars($currentUserId, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="filter_date_from" value="<?php echo htmlspecialchars($currentDateFrom, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="filter_date_to" value="<?php echo htmlspecialchars($currentDateTo, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="preset_name" value="<?php echo htmlspecialchars($currentPresetName, ENT_QUOTES, 'UTF-8'); ?>">

        <section class="panel bulk-grid">
            <div>
                <label>What to reprocess</label>
                <div class="stage-list">
                    <?php foreach ($stageMap as $stageId => $stageLabel): ?>
                        <label><input type="checkbox" name="reprocess_stages[]" value="<?php echo htmlspecialchars($stageId, ENT_QUOTES, 'UTF-8'); ?>" <?php echo in_array($stageId, $currentSelectedStageIds, true) ? 'checked' : ''; ?>> <?php echo htmlspecialchars($stageLabel, ENT_QUOTES, 'UTF-8'); ?></label>
                    <?php endforeach; ?>
                </div>
                <p class="muted">Uncheck stages you want to skip. Checked stages will have metadata cleared and be re-queued.</p>
            </div>
            <div>
                <label>Queue Actions</label>
                <div class="filters-grid" style="grid-template-columns: repeat(2, minmax(100px, 1fr)); margin-bottom: 0.5rem;">
                    <div>
                        <label for="max_rows">Max Rows</label>
                        <input id="max_rows" name="max_rows" type="number" min="1" max="<?php echo (int) $maxFilteredQueueSize; ?>" value="<?php echo (int) $currentMaxRows; ?>">
                    </div>
                    <div>
                        <label for="batch_size">Batch Size</label>
                        <input id="batch_size" name="batch_size" type="number" min="1" max="<?php echo (int) $maxBatchSize; ?>" value="<?php echo (int) $currentBatchSize; ?>">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" name="action" value="queue_selected">Queue Selected Entries</button>
                    <button type="submit" name="action" value="preview_selected">Preview Selected</button>
                    <button type="submit" name="action" value="queue_filtered" onclick="return confirm('Queue filtered entries (up to max rows) now?');">Queue Filtered</button>
                    <button type="submit" name="action" value="preview_filtered">Preview Filtered</button>
                </div>
                <p class="muted">Queue filtered is capped by Max Rows (hard cap <?php echo (int) $maxFilteredQueueSize; ?>). Batch Size controls transaction chunking.</p>
            </div>
        </section>

        <?php if (is_array($previewStats)): ?>
            <section class="panel">
                <strong>Dry Run Preview</strong>
                <div class="muted" style="margin-top: 0.45rem;">
                    Requested: <?php echo (int) ($previewStats['requested'] ?? 0); ?> |
                    Considered: <?php echo (int) ($previewStats['considered'] ?? 0); ?> |
                    Would Queue: <?php echo (int) ($previewStats['queued'] ?? 0); ?> |
                    Skipped Active: <?php echo (int) ($previewStats['skipped_active'] ?? 0); ?> |
                    Invalid/Missing: <?php echo (int) ($previewStats['invalid'] ?? 0); ?>
                </div>
                <?php $previewUids = is_array($previewStats['preview_uids'] ?? null) ? $previewStats['preview_uids'] : []; ?>
                <?php if (count($previewUids) > 0): ?>
                    <p class="muted" style="margin-bottom: 0.35rem;">Sample queue candidates (up to 25):</p>
                    <div style="display:flex; flex-wrap:wrap; gap:0.4rem;">
                        <?php foreach ($previewUids as $previewUid): ?>
                            <span class="pill"><code><?php echo htmlspecialchars((string) $previewUid, ENT_QUOTES, 'UTF-8'); ?></code></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div class="form-actions">
                <label><input type="checkbox" id="select-visible"> Select all visible rows</label>
                <span class="muted">Only rows without an active pipeline job are selectable.</span>
            </div>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                    <tr>
                        <th style="width:40px;">Sel</th>
                        <th>Entry UID</th>
                        <th>User</th>
                        <th>Entry Date</th>
                        <th>Title / Excerpt</th>
                        <th>Workflow</th>
                        <th>Meta Presence</th>
                        <th>Queue</th>
                        <th>Updated</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (count($rows) === 0): ?>
                        <tr><td colspan="9" class="muted">No entries match current filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php if (!is_array($row)) { continue; } ?>
                            <?php $uid = (string) ($row['entry_uid'] ?? ''); ?>
                            <?php $activeJob = (int) ($row['has_active_job'] ?? 0) === 1; ?>
                            <tr>
                                <td>
                                    <?php if ($activeJob): ?>
                                        -
                                    <?php else: ?>
                                        <input class="entry-checkbox" type="checkbox" name="entry_uids[]" value="<?php echo htmlspecialchars($uid, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo htmlspecialchars($uid, ENT_QUOTES, 'UTF-8'); ?></code></td>
                                <td><?php echo htmlspecialchars((string) ($row['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> (<?php echo (int) ($row['user_id'] ?? 0); ?>)</td>
                                <td><?php echo htmlspecialchars((string) ($row['entry_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <div><?php echo htmlspecialchars((string) ($row['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="muted"><?php echo htmlspecialchars((string) ($row['content_excerpt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars((string) ($row['workflow_stage'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <span class="meta-chip <?php echo ((int) ($row['has_g0'] ?? 0) === 1) ? 'on' : 'off'; ?>">G0</span>
                                    <span class="meta-chip <?php echo ((int) ($row['has_g1'] ?? 0) === 1) ? 'on' : 'off'; ?>">G1</span>
                                    <span class="meta-chip <?php echo ((int) ($row['has_g2'] ?? 0) === 1) ? 'on' : 'off'; ?>">G2</span>
                                    <span class="meta-chip <?php echo ((int) ($row['has_g3'] ?? 0) === 1) ? 'on' : 'off'; ?>">G3</span>
                                    <span class="meta-chip <?php echo ((int) ($row['has_metrics'] ?? 0) === 1) ? 'on' : 'off'; ?>">Metrics</span>
                                </td>
                                <td><?php echo $activeJob ? '<span class="muted">active job exists</span>' : 'ready'; ?></td>
                                <td><?php echo htmlspecialchars((string) ($row['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pager">
                <span class="muted">Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?></span>
                <?php if ($page > 1): ?>
                    <a class="pill" href="/admin-reprocess.php?<?php echo htmlspecialchars(http_build_query(['q' => $currentQuery, 'workflow_stage' => $currentWorkflowStage, 'user_id' => $currentUserId, 'date_from' => $currentDateFrom, 'date_to' => $currentDateTo, 'max_rows' => $currentMaxRows, 'batch_size' => $currentBatchSize, 'preset_name' => $currentPresetName, 'page' => $page - 1]), ENT_QUOTES, 'UTF-8'); ?>">Prev</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a class="pill" href="/admin-reprocess.php?<?php echo htmlspecialchars(http_build_query(['q' => $currentQuery, 'workflow_stage' => $currentWorkflowStage, 'user_id' => $currentUserId, 'date_from' => $currentDateFrom, 'date_to' => $currentDateTo, 'max_rows' => $currentMaxRows, 'batch_size' => $currentBatchSize, 'preset_name' => $currentPresetName, 'page' => $page + 1]), ENT_QUOTES, 'UTF-8'); ?>">Next</a>
                <?php endif; ?>
            </div>
        </section>
    </form>
</main>
<script>
(() => {
    const selectVisible = document.getElementById('select-visible');
    const checkboxes = Array.from(document.querySelectorAll('.entry-checkbox'));
    if (selectVisible instanceof HTMLInputElement) {
        selectVisible.addEventListener('change', () => {
            const checked = !!selectVisible.checked;
            for (const checkbox of checkboxes) {
                if (checkbox instanceof HTMLInputElement) {
                    checkbox.checked = checked;
                }
            }
        });
    }
})();
</script>
</body>
</html>
