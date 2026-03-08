<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Auth/require_auth.php';

use App\Auth\Auth;
use App\Core\Database;
use App\Entry\EntryUid;
use App\Entry\EntryRepository;
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
if ($entryUid !== '' && !EntryUid::isValid($entryUid)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid entry UID']);
    exit;
}
$title = trim((string) ($data['title'] ?? ''));
$content = (string) ($data['content'] ?? '');
$entryDate = trim((string) ($data['entry_date'] ?? ''));

if ($entryUid === '' && trim($title) === '' && trim($content) === '') {
    echo json_encode([
        'ok' => true,
        'entry_uid' => '',
        'saved_at' => gmdate('c'),
        'skipped' => true,
    ]);
    exit;
}

if ($title === '') {
    $title = 'Untitled Entry';
}

/**
 * @param array<string,mixed> $location
 * @return array<string,string>
 */
function normalizeEntryLocation(array $location, string $key): array
{
    $normalized = [
        'key' => trim($key) !== '' ? trim($key) : trim((string) ($location['key'] ?? 'new_richmond_wi')),
        'label' => trim((string) ($location['label'] ?? '')),
        'city' => trim((string) ($location['city'] ?? '')),
        'state' => trim((string) ($location['state'] ?? '')),
        'zip' => trim((string) ($location['zip'] ?? '')),
        'country' => strtoupper(trim((string) ($location['country'] ?? 'US'))),
    ];

    if ($normalized['country'] === '') {
        $normalized['country'] = 'US';
    }
    if ($normalized['city'] === '') {
        $normalized['city'] = 'New Richmond';
    }
    if ($normalized['state'] === '') {
        $normalized['state'] = 'WI';
    }
    if ($normalized['zip'] === '') {
        $normalized['zip'] = '54017';
    }
    if ($normalized['label'] === '') {
        $normalized['label'] = $normalized['city'] . ', ' . $normalized['state'] . ', ' . $normalized['country'];
    }

    return $normalized;
}

$entryLocationKey = trim((string) ($data['entry_location_key'] ?? ''));
$entryLocationRaw = $data['entry_location'] ?? null;
$entryLocation = normalizeEntryLocation(is_array($entryLocationRaw) ? $entryLocationRaw : [], $entryLocationKey);
$entryLocationKey = $entryLocation['key'];

$date = DateTimeImmutable::createFromFormat('Y-m-d', $entryDate);
if (!$date || $date->format('Y-m-d') !== $entryDate) {
    $entryDate = (new DateTimeImmutable('now'))->format('Y-m-d');
}

try {
    $pdo = Database::connection($config['database']);
    $repo = new EntryRepository($pdo, (string) ($config['entry_uid']['app_version_code'] ?? 'W010000'));
    $stage = 'AUTOSAVE';

    if ($entryUid !== '') {
        $existing = $repo->findByUidForUser($entryUid, $userId);
        if (is_array($existing)) {
            if ((int) ($existing['body_locked'] ?? 0) === 1) {
                echo json_encode([
                    'ok' => true,
                    'entry_uid' => $entryUid,
                    'saved_at' => gmdate('c'),
                    'skipped' => true,
                    'locked' => true,
                    'stage' => (string) ($existing['workflow_stage'] ?? 'FINISHED'),
                ]);
                exit;
            }
            $repo->updateByUid($entryUid, $userId, $entryDate, $title, $content, $entryLocation, $entryLocationKey);
        } else {
            $sameDay = $repo->findLatestByDateForUser($userId, $entryDate);
            if (is_array($sameDay)) {
                $entryUid = (string) ($sameDay['entry_uid'] ?? '');
                if ((int) ($sameDay['body_locked'] ?? 0) === 1) {
                    echo json_encode([
                        'ok' => true,
                        'entry_uid' => $entryUid,
                        'saved_at' => gmdate('c'),
                        'skipped' => true,
                        'locked' => true,
                        'stage' => (string) ($sameDay['workflow_stage'] ?? 'FINISHED'),
                    ]);
                    exit;
                }
                $repo->updateByUid($entryUid, $userId, $entryDate, $title, $content, $entryLocation, $entryLocationKey);
            } else {
                $entryUid = $repo->create($userId, $entryDate, $title, $content, $entryLocation, $entryLocationKey);
            }
        }
    } else {
        $sameDay = $repo->findLatestByDateForUser($userId, $entryDate);
        if (is_array($sameDay)) {
            $entryUid = (string) ($sameDay['entry_uid'] ?? '');
            if ((int) ($sameDay['body_locked'] ?? 0) === 1) {
                echo json_encode([
                    'ok' => true,
                    'entry_uid' => $entryUid,
                    'saved_at' => gmdate('c'),
                    'skipped' => true,
                    'locked' => true,
                    'stage' => (string) ($sameDay['workflow_stage'] ?? 'FINISHED'),
                ]);
                exit;
            }
            $repo->updateByUid($entryUid, $userId, $entryDate, $title, $content, $entryLocation, $entryLocationKey);
        } else {
            $entryUid = $repo->create($userId, $entryDate, $title, $content, $entryLocation, $entryLocationKey);
        }
    }
    $repo->setStageForUser($entryUid, $userId, $stage);

    echo json_encode([
        'ok' => true,
        'entry_uid' => $entryUid,
        'saved_at' => gmdate('c'),
        'word_count' => EntryRepository::wordCount($content),
        'stage' => $stage,
    ]);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Autosave failed']);
}
