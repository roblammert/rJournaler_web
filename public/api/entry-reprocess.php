<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Auth/require_auth.php';

use App\Auth\Auth;
use App\Core\Database;
use App\Entry\EntryRepository;
use App\Entry\EntryUid;
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

$entryUid = isset($data['entry_uid']) && is_string($data['entry_uid']) ? trim($data['entry_uid']) : '';
if (!EntryUid::isValid($entryUid)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid entry UID']);
    exit;
}

$allowedStageMap = [
    'meta_group_0' => 'Meta Group 0',
    'meta_group_1' => 'Meta Group 1',
    'meta_group_2_llm' => 'Meta Group 2 (LLM)',
    'meta_group_3_weather' => 'Meta Group 3 (Weather)',
    'metrics_finalize' => 'Metrics Finalize',
];
$defaultStageIds = array_keys($allowedStageMap);

$requestedStageIds = [];
if (isset($data['pipeline_stage_ids'])) {
    if (!is_array($data['pipeline_stage_ids'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid stage selection']);
        exit;
    }

    foreach ($data['pipeline_stage_ids'] as $value) {
        if (!is_string($value)) {
            continue;
        }
        $stageId = trim($value);
        if ($stageId === '' || !array_key_exists($stageId, $allowedStageMap)) {
            continue;
        }
        if (!in_array($stageId, $requestedStageIds, true)) {
            $requestedStageIds[] = $stageId;
        }
    }
}

$selectedStageIds = count($requestedStageIds) > 0 ? $requestedStageIds : $defaultStageIds;
$skippedStageIds = array_values(array_diff($defaultStageIds, $selectedStageIds));
$selectedStageLabels = [];
foreach ($selectedStageIds as $stageId) {
    $selectedStageLabels[] = $allowedStageMap[$stageId];
}

try {
    $pdo = Database::connection($config['database']);
    $repo = new EntryRepository($pdo, (string) ($config['entry_uid']['app_version_code'] ?? 'W010000'));

    $entry = $repo->findByUidForUser($entryUid, $userId);
    if (!is_array($entry)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Entry not found']);
        exit;
    }

    if (in_array('meta_group_0', $selectedStageIds, true)) {
        $pdo->prepare('DELETE FROM entry_meta_group_0 WHERE entry_uid = :entry_uid')->execute(['entry_uid' => $entryUid]);
    }
    if (in_array('meta_group_1', $selectedStageIds, true)) {
        $pdo->prepare('DELETE FROM entry_meta_group_1 WHERE entry_uid = :entry_uid')->execute(['entry_uid' => $entryUid]);
    }
    if (in_array('meta_group_2_llm', $selectedStageIds, true)) {
        $pdo->prepare('DELETE FROM entry_meta_group_2 WHERE entry_uid = :entry_uid')->execute(['entry_uid' => $entryUid]);
    }
    if (in_array('meta_group_3_weather', $selectedStageIds, true)) {
        $pdo->prepare('DELETE FROM entry_meta_group_3 WHERE entry_uid = :entry_uid')->execute(['entry_uid' => $entryUid]);
    }
    if (in_array('metrics_finalize', $selectedStageIds, true)) {
        $pdo->prepare('DELETE FROM entry_metrics WHERE entry_uid = :entry_uid')->execute(['entry_uid' => $entryUid]);
    }

    $repo->unlockBodyForUser($entryUid, $userId);
    $repo->setStageForUser($entryUid, $userId, 'IN_PROCESS');

    $queueStmt = $pdo->prepare(
        'INSERT INTO worker_jobs (job_type, entry_uid, submitter, stage_label, payload_json, status, priority, attempt_count, run_after, submitted_at) VALUES (:job_type, :entry_uid, :submitter, :stage_label, :payload_json, :status, :priority, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $queueStmt->execute([
        'job_type' => 'entry_process_pipeline',
        'entry_uid' => $entryUid,
        'submitter' => 'REPROC',
        'stage_label' => 'Queued',
        'payload_json' => json_encode([
            'entry_uid' => $entryUid,
            'user_id' => $userId,
            'source' => 'reprocess',
            'pipeline' => [
                'completed' => $skippedStageIds,
                'remaining' => $selectedStageIds,
                'remaining_labels' => $selectedStageLabels,
            ],
        ], JSON_UNESCAPED_SLASHES),
        'status' => 'queued',
        'priority' => 35,
    ]);

    echo json_encode([
        'ok' => true,
        'entry_uid' => $entryUid,
        'stage' => 'IN_PROCESS',
        'pipeline_stage_ids' => $selectedStageIds,
    ]);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to reprocess entry']);
}
