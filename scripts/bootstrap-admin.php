<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';

use App\Core\Database;
use App\Security\Crypto;

if ($argc < 4) {
    fwrite(STDERR, "Usage: php scripts/bootstrap-admin.php <username> <email> <password> [issuer] [--print-only]\n");
    exit(1);
}

$username = trim((string) $argv[1]);
$email = trim((string) $argv[2]);
$password = (string) $argv[3];
$issuer = (string) ($config['security']['totp_issuer'] ?? 'rJournaler Web');
$printOnly = false;

for ($i = 4; $i < $argc; $i++) {
    $arg = (string) $argv[$i];
    if ($arg === '--print-only') {
        $printOnly = true;
        continue;
    }

    if ($issuer === (string) ($config['security']['totp_issuer'] ?? 'rJournaler Web')) {
        $issuer = trim($arg);
    }
}

if ($username === '' || $email === '' || $password === '') {
    fwrite(STDERR, "Username, email, and password are required.\n");
    exit(1);
}

if ($issuer === '') {
    fwrite(STDERR, "Issuer cannot be empty.\n");
    exit(1);
}

$generateBase32Secret = static function (int $length = 32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bytesNeeded = (int) ceil(($length * 5) / 8);
    $random = random_bytes($bytesNeeded);

    $bits = '';
    for ($i = 0, $max = strlen($random); $i < $max; $i++) {
        $bits .= str_pad(decbin(ord($random[$i])), 8, '0', STR_PAD_LEFT);
    }

    $secret = '';
    for ($i = 0; $i + 5 <= strlen($bits) && strlen($secret) < $length; $i += 5) {
        $index = bindec(substr($bits, $i, 5));
        $secret .= $alphabet[$index];
    }

    return $secret;
};

$totpSecret = $generateBase32Secret();
$label = rawurlencode($issuer . ':' . $email);
$query = http_build_query([
    'secret' => $totpSecret,
    'issuer' => $issuer,
    'algorithm' => 'SHA1',
    'digits' => 6,
    'period' => 30,
]);
$otpauthUri = 'otpauth://totp/' . $label . '?' . $query;
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . rawurlencode($otpauthUri);

fwrite(STDOUT, "Generated TOTP secret:\n" . $totpSecret . "\n\n");
fwrite(STDOUT, "otpauth URI:\n" . $otpauthUri . "\n\n");
fwrite(STDOUT, "QR URL:\n" . $qrUrl . "\n\n");

if ($printOnly) {
    fwrite(STDOUT, "--print-only specified. User record was not created.\n");
    exit(0);
}

$appKey = (string) env('APP_KEY', '');
if (trim($appKey) === '') {
    fwrite(STDERR, "APP_KEY is required in .env\n");
    exit(1);
}

$passwordHash = password_hash($password, PASSWORD_ARGON2ID);
if (!is_string($passwordHash) || $passwordHash === '') {
    fwrite(STDERR, "Unable to hash password.\n");
    exit(1);
}

$encryptedSecret = Crypto::encrypt($totpSecret, $appKey);

try {
    $pdo = Database::connection($config['database']);
    $sql = <<<'SQL'
        INSERT INTO users (username, email, password_hash, totp_secret_encrypted, is_active, is_admin)
        VALUES (:username, :email, :password_hash, :totp_secret, 1, 1)
    SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'username' => $username,
        'email' => $email,
        'password_hash' => $passwordHash,
        'totp_secret' => $encryptedSecret,
    ]);

    fwrite(STDOUT, "User created successfully with id " . (string) $pdo->lastInsertId() . "\n");
} catch (\PDOException $exception) {
    fwrite(STDERR, "Unable to create user: " . $exception->getMessage() . "\n");
    exit(1);
}
