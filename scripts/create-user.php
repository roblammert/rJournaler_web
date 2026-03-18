<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';

use App\Core\Database;
use App\Security\Crypto;

if ($argc < 5) {
    fwrite(STDERR, "Usage: php scripts/create-user.php <username> <email> <password> <BASE32_TOTP_SECRET>\n");
    exit(1);
}

[$script, $username, $email, $password, $totpSecret] = $argv;

$username = trim($username);
$email = trim($email);
$password = (string) $password;
if ($username === '' || $email === '' || $password === '') {
    fwrite(STDERR, "All arguments are required.\n");
    exit(1);
}

$pdo = Database::connection($config['database']);

$passwordHash = password_hash($password, PASSWORD_ARGON2ID);
if (!is_string($passwordHash) || $passwordHash === '') {
    fwrite(STDERR, "Unable to hash password.\n");
    exit(1);
}

$sql = <<<'SQL'
    INSERT INTO users (username, email, password_hash, is_active, is_admin)
    VALUES (:username, :email, :password_hash, 1, 0)
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([
    'username' => $username,
    'email' => $email,
    'password_hash' => $passwordHash,
]);

fwrite(STDOUT, "User created successfully with id " . (string) $pdo->lastInsertId() . PHP_EOL);
