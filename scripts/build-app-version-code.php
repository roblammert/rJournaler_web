<?php

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/build-app-version-code.php <APP_LETTER_OR_NAME> <VERSION>\n");
    fwrite(STDERR, "Examples:\n");
    fwrite(STDERR, "  php scripts/build-app-version-code.php web 1.0.0\n");
    fwrite(STDERR, "  php scripts/build-app-version-code.php T 3.15.2\n");
    exit(1);
}

$appInput = trim((string) $argv[1]);
$versionInput = trim((string) $argv[2]);

if ($appInput === '' || $versionInput === '') {
    fwrite(STDERR, "APP_LETTER_OR_NAME and VERSION are required.\n");
    exit(1);
}

$appLetter = strtoupper($appInput[0]);
if (!preg_match('/^[A-Z]$/', $appLetter)) {
    fwrite(STDERR, "App designator must start with a letter (A-Z).\n");
    exit(1);
}

if (!preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{1,2})$/', $versionInput, $matches)) {
    fwrite(STDERR, "VERSION must be semantic version format <major.minor.patch>, each 0-99.\n");
    exit(1);
}

$major = (int) $matches[1];
$minor = (int) $matches[2];
$patch = (int) $matches[3];

if ($major > 99 || $minor > 99 || $patch > 99) {
    fwrite(STDERR, "Version components must each be in range 0-99.\n");
    exit(1);
}

$code = sprintf('%s%02d%02d%02d', $appLetter, $major, $minor, $patch);
fwrite(STDOUT, $code . PHP_EOL);
