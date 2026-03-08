<?php

declare(strict_types=1);

namespace App\Security;

final class TotpProvisioning
{
    public static function generateSecret(int $length = 32): string
    {
        $length = max(16, min(128, $length));
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
    }

    public static function buildUri(string $accountName, string $secret, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $accountName);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ]);

        return 'otpauth://totp/' . $label . '?' . $query;
    }

    public static function buildQrUrl(string $otpauthUri): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . rawurlencode($otpauthUri);
    }
}
