<?php

declare(strict_types=1);

namespace App\Entry;

use DateTimeImmutable;
use DateTimeZone;

final class EntryUid
{
    private const UID_PATTERN = '/^\d{14}-rjournaler-[A-Z][0-9]{6}-[a-z0-9]{6}$/';
    private const APP_VERSION_PATTERN = '/^[A-Z][0-9]{6}$/';

    public static function generate(
        string $applicationName = 'rjournaler',
        string $appVersionCode = 'W010000',
        string $timezone = 'America/Chicago'
    ): string {
        if (!self::isValidAppVersionCode($appVersionCode)) {
            throw new \InvalidArgumentException('Invalid app version code format. Expected [A-Z][0-9]{6}.');
        }

        $timestamp = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('YmdHis');
        $suffix = self::randomAlphaNumeric(6);

        return sprintf('%s-%s-%s-%s', $timestamp, strtolower($applicationName), strtoupper($appVersionCode), $suffix);
    }

    public static function isValid(string $value): bool
    {
        return (bool) preg_match(self::UID_PATTERN, $value);
    }

    public static function isValidAppVersionCode(string $value): bool
    {
        return (bool) preg_match(self::APP_VERSION_PATTERN, $value);
    }

    private static function randomAlphaNumeric(int $length): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $max = strlen($alphabet) - 1;
        $value = '';

        for ($i = 0; $i < $length; $i++) {
            $value .= $alphabet[random_int(0, $max)];
        }

        return $value;
    }
}
