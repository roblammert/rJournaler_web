<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';

use App\Auth\Auth;
use App\Core\Database;
use App\Security\AuditLogger;

$userId = Auth::userId();


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
