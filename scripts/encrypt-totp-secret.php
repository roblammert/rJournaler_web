<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';

use App\Security\Crypto;

$secret = $argv[1] ?? null;
if (!is_string($secret) || trim($secret) === '') {
    fwrite(STDERR, "Usage: php scripts/encrypt-totp-secret.php <BASE32_SECRET>\n");
    exit(1);
}

$secret = strtoupper(trim($secret));
if (!preg_match('/^[A-Z2-7]{16,128}$/', $secret)) {
    fwrite(STDERR, "BASE32_SECRET must contain only A-Z and 2-7, length 16-128.\n");
    exit(1);
}

$appKey = (string) env('APP_KEY', '');
if (trim($appKey) === '') {
    fwrite(STDERR, "APP_KEY is required in .env\n");
    exit(1);
}

$encrypted = Crypto::encrypt($secret, $appKey);
fwrite(STDOUT, $encrypted . PHP_EOL);
