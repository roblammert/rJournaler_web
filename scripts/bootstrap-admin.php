<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';

use App\Core\Database;


if ($argc < 4) {
    fwrite(STDERR, "Usage: php scripts/bootstrap-admin.php <username> <email> <password> [--print-only]\n");
    exit(1);
}

$username = trim((string) $argv[1]);
$email = trim((string) $argv[2]);
$password = (string) $argv[3];
$printOnly = false;

for ($i = 4; $i < $argc; $i++) {
    $arg = (string) $argv[$i];
    if ($arg === '--print-only') {
        $printOnly = true;
    }
}

if ($username === '' || $email === '' || $password === '') {
    fwrite(STDERR, "Username, email, and password are required.\n");
    exit(1);
}

$passwordHash = password_hash($password, PASSWORD_ARGON2ID);
if (!is_string($passwordHash) || $passwordHash === '') {
    fwrite(STDERR, "Unable to hash password.\n");
    exit(1);
}


if ($printOnly) {
    fwrite(STDOUT, "--print-only specified. User record was not created.\n");
    exit(0);
}

try {
    $pdo = Database::connection($config['database']);
    $sql = <<<'SQL'
        INSERT INTO users (username, email, password_hash, is_active, is_admin)
        VALUES (:username, :email, :password_hash, 1, 1)
    SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'username' => $username,
        'email' => $email,
        'password_hash' => $passwordHash,
    ]);
        fwrite(STDOUT, "Admin user created successfully.\n");
    } catch (Throwable $throwable) {
        fwrite(STDERR, "Failed to create admin user: " . $throwable->getMessage() . "\n");
        exit(1);
    }
