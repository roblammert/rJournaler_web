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

try {
    $pdo = Database::connection($config['database']);
    $repo = new EntryRepository($pdo, (string) ($config['entry_uid']['app_version_code'] ?? 'W010000'));

    $entry = $repo->findByUidForUser($entryUid, $userId);
    if (!is_array($entry)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Entry not found']);
        exit;
    }

    $repo->lockBodyForUser($entryUid, $userId);
    $repo->setStageForUser($entryUid, $userId, 'IN_PROCESS');

    $queueStmt = $pdo->prepare(
        'INSERT INTO worker_jobs (job_type, entry_uid, submitter, stage_label, payload_json, status, priority, attempt_count, run_after, submitted_at) VALUES (:job_type, :entry_uid, :submitter, :stage_label, :payload_json, :status, :priority, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $pipelineStages = ['Meta Group 0', 'Meta Group 1', 'Meta Group 2 (LLM)', 'Meta Group 3 (Weather)', 'Metrics Finalize'];
    $queueStmt->execute([
        'job_type' => 'entry_process_pipeline',
        'entry_uid' => $entryUid,
        'submitter' => 'USER',
        'stage_label' => 'Queued',
        'payload_json' => json_encode([
            'entry_uid' => $entryUid,
            'user_id' => $userId,
            'source' => 'finish',
            'pipeline' => [
                'completed' => [],
                'remaining_labels' => $pipelineStages,
            ],
        ], JSON_UNESCAPED_SLASHES),
        'status' => 'queued',
        'priority' => 40,
    ]);

    echo json_encode(['ok' => true, 'entry_uid' => $entryUid, 'stage' => 'IN_PROCESS']);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to finish entry']);
}
