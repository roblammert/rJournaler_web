<?php

declare(strict_types=1);

$length = 32;
if (isset($argv[1]) && ctype_digit($argv[1])) {
    $length = max(16, min(128, (int) $argv[1]));
}

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

fwrite(STDOUT, $secret . PHP_EOL);
