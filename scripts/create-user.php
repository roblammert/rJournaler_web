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
$totpSecret = strtoupper(trim($totpSecret));

if ($username === '' || $email === '' || $password === '' || $totpSecret === '') {
    fwrite(STDERR, "All arguments are required.\n");
    exit(1);
}

if (!preg_match('/^[A-Z2-7]{16,128}$/', $totpSecret)) {
    fwrite(STDERR, "TOTP secret must be base32 characters only (A-Z, 2-7), length 16-128.\n");
    exit(1);
}

$appKey = (string) env('APP_KEY', '');
if (trim($appKey) === '') {
    fwrite(STDERR, "APP_KEY is required in .env\n");
    exit(1);
}

$pdo = Database::connection($config['database']);

$passwordHash = password_hash($password, PASSWORD_ARGON2ID);
if (!is_string($passwordHash) || $passwordHash === '') {
    fwrite(STDERR, "Unable to hash password.\n");
    exit(1);
}

$encryptedSecret = Crypto::encrypt($totpSecret, $appKey);

$sql = <<<'SQL'
    INSERT INTO users (username, email, password_hash, totp_secret_encrypted, is_active, is_admin)
    VALUES (:username, :email, :password_hash, :totp_secret, 1, 0)
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([
    'username' => $username,
    'email' => $email,
    'password_hash' => $passwordHash,
    'totp_secret' => $encryptedSecret,
]);

fwrite(STDOUT, "User created successfully with id " . (string) $pdo->lastInsertId() . PHP_EOL);
