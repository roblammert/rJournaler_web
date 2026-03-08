<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';

use App\Auth\Auth;
use App\Core\Database;
use App\Security\AuditLogger;

$userId = Auth::userId();

if (isset($_COOKIE['rj_trusted_device'])) {
    $cookieValue = (string) $_COOKIE['rj_trusted_device'];
    $parts = explode(':', $cookieValue, 2);
    if (count($parts) === 2) {
        [$cookieUserId, $token] = $parts;
        if ((int) $cookieUserId > 0 && $token !== '') {
            try {
                $pdo = Database::connection($config['database']);
                $auditLogger = new AuditLogger($pdo);
                $sql = 'DELETE FROM trusted_devices WHERE user_id = :user_id AND token_hash = :token_hash';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'user_id' => (int) $cookieUserId,
                    'token_hash' => hash('sha256', $token),
                ]);
                $auditLogger->log((int) $cookieUserId, 'auth.trusted_device.revoked_on_logout');
            } catch (Throwable $throwable) {
                // Best-effort cleanup only; continue logout flow.
            }
        }
    }

    setcookie('rj_trusted_device', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => (bool) ($config['session']['secure_cookie'] ?? false),
        'httponly' => true,
        'samesite' => (string) ($config['session']['samesite'] ?? 'Strict'),
    ]);
}

try {
    $pdo = Database::connection($config['database']);
    $auditLogger = new AuditLogger($pdo);
    $auditLogger->log($userId, 'auth.logout');
} catch (Throwable $throwable) {
    // Best-effort audit logging only.
}

Auth::logout();
header('Location: /login.php');
exit;
