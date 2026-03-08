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

$workerName = isset($data['worker_name']) && is_string($data['worker_name']) ? trim($data['worker_name']) : '';
if ($workerName === '' || preg_match('/^Autobot-[A-Za-z0-9_\-]+-\d+$/', $workerName) !== 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid worker name']);
    exit;
}

try {
    $pdo = Database::connection($config['database']);
    $settingKey = 'processing.autobot_drain.' . $workerName;

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
        'setting_value' => '1',
        'updated_by_user_id' => $userId,
    ]);

    echo json_encode([
        'ok' => true,
        'worker_name' => $workerName,
        'drain_requested' => true,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to request autobot drain',
    ]);
}
