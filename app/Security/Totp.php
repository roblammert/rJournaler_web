<?php

declare(strict_types=1);

namespace App\Security;

final class Totp
{
    public static function verify(string $base32Secret, string $inputCode, int $period = 30, int $digits = 6, int $window = 1): bool
    {
        $normalizedCode = preg_replace('/\s+/', '', $inputCode);
        if (!is_string($normalizedCode) || !preg_match('/^\d{' . $digits . '}$/', $normalizedCode)) {
            return false;
        }

        $secret = self::base32Decode($base32Secret);
        if ($secret === '') {
            return false;
        }

        $counter = (int) floor(time() / $period);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $candidate = self::hotp($secret, $counter + $offset, $digits);
            if (hash_equals($candidate, $normalizedCode)) {
                return true;
            }
        }

        return false;
    }

    private static function hotp(string $secret, int $counter, int $digits): string
    {
        $counterBytes = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $counterBytes, $secret, true);

        $offset = ord($hash[19]) & 0x0f;
        $binary =
            ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        $otp = (string) ($binary % (10 ** $digits));
        return str_pad($otp, $digits, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $encoded): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $clean = strtoupper(trim($encoded));
        $clean = preg_replace('/[^A-Z2-7]/', '', $clean);

        if (!is_string($clean) || $clean === '') {
            return '';
        }

        $bits = '';
        $length = strlen($clean);
        for ($i = 0; $i < $length; $i++) {
            $char = $clean[$i];
            $value = strpos($alphabet, $char);
            if ($value === false) {
                return '';
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        $bitLength = strlen($bits);
        for ($i = 0; $i + 8 <= $bitLength; $i += 8) {
            $decoded .= chr(bindec(substr($bits, $i, 8)));
        }

        return $decoded;
    }
}
