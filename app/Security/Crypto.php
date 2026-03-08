<?php

declare(strict_types=1);

namespace App\Security;

final class Crypto
{
    public static function encrypt(string $plaintext, string $appKey): string
    {
        $key = self::deriveKey($appKey);
        $iv = random_bytes(16);

        $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if (!is_string($ciphertext) || $ciphertext === '') {
            throw new \RuntimeException('Unable to encrypt value.');
        }

        return 'enc:' . base64_encode($iv . $ciphertext);
    }

    public static function decrypt(string $ciphertext, string $appKey): ?string
    {
        if (!str_starts_with($ciphertext, 'enc:')) {
            return null;
        }

        $encoded = substr($ciphertext, 4);
        $raw = base64_decode($encoded, true);
        if (!is_string($raw) || strlen($raw) <= 16) {
            return null;
        }

        $iv = substr($raw, 0, 16);
        $payload = substr($raw, 16);
        $key = self::deriveKey($appKey);

        $plaintext = openssl_decrypt($payload, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if (!is_string($plaintext) || $plaintext === '') {
            return null;
        }

        return $plaintext;
    }

    private static function deriveKey(string $appKey): string
    {
        if (trim($appKey) === '') {
            throw new \RuntimeException('APP_KEY is required for encryption/decryption.');
        }

        return hash('sha256', $appKey, true);
    }
}
