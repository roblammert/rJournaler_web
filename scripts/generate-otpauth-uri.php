<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/generate-otpauth-uri.php <ACCOUNT_NAME> <BASE32_SECRET> [ISSUER]\n");
    exit(1);
}

$accountName = trim((string) $argv[1]);
$secret = strtoupper(trim((string) $argv[2]));
$issuer = isset($argv[3]) ? trim((string) $argv[3]) : (string) ($config['security']['totp_issuer'] ?? 'rJournaler Web');

if ($accountName === '' || $secret === '' || $issuer === '') {
    fwrite(STDERR, "ACCOUNT_NAME, BASE32_SECRET, and ISSUER must be non-empty.\n");
    exit(1);
}

if (!preg_match('/^[A-Z2-7]{16,128}$/', $secret)) {
    fwrite(STDERR, "BASE32_SECRET must contain only A-Z and 2-7, length 16-128.\n");
    exit(1);
}

$label = rawurlencode($issuer . ':' . $accountName);
$query = http_build_query([
    'secret' => $secret,
    'issuer' => $issuer,
    'algorithm' => 'SHA1',
    'digits' => 6,
    'period' => 30,
]);

$otpauthUri = 'otpauth://totp/' . $label . '?' . $query;
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . rawurlencode($otpauthUri);

fwrite(STDOUT, 'otpauth URI:' . PHP_EOL);
fwrite(STDOUT, $otpauthUri . PHP_EOL . PHP_EOL);
fwrite(STDOUT, 'QR URL:' . PHP_EOL);
fwrite(STDOUT, $qrUrl . PHP_EOL);
