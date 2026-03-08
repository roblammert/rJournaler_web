<?php

declare(strict_types=1);

namespace App\Auth;

final class Auth
{
    private const INTERFACE_TIMEZONE = 'America/Chicago';
    private const DEFAULT_INTERFACE_THEME = 'neutral';
    private const ALLOWED_INTERFACE_THEMES = ['light', 'neutral', 'dark'];

    public static function userId(): ?int
    {
        $userId = $_SESSION['user_id'] ?? null;
        return is_int($userId) ? $userId : null;
    }

    public static function check(): bool
    {
        return self::userId() !== null;
    }

    public static function loginAs(
        int $userId,
        bool $isAdmin = false,
        ?string $displayName = null,
        ?string $timezonePreference = null,
        ?string $interfaceTheme = null
    ): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['is_admin'] = $isAdmin;
        $_SESSION['display_name'] = $displayName;
        $_SESSION['timezone_preference'] = $timezonePreference;
        $normalizedTheme = strtolower(trim((string) $interfaceTheme));
        if (!in_array($normalizedTheme, self::ALLOWED_INTERFACE_THEMES, true)) {
            $normalizedTheme = self::DEFAULT_INTERFACE_THEME;
        }
        $_SESSION['interface_theme'] = $normalizedTheme;
        $_SESSION['logged_in_at'] = time();
    }

    public static function isAdmin(): bool
    {
        return (bool) ($_SESSION['is_admin'] ?? false);
    }

    public static function displayName(): ?string
    {
        $value = $_SESSION['display_name'] ?? null;
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    public static function timezonePreference(): ?string
    {
        return self::INTERFACE_TIMEZONE;
    }

    public static function interfaceTheme(): string
    {
        $value = strtolower(trim((string) ($_SESSION['interface_theme'] ?? self::DEFAULT_INTERFACE_THEME)));
        if (!in_array($value, self::ALLOWED_INTERFACE_THEMES, true)) {
            return self::DEFAULT_INTERFACE_THEME;
        }

        return $value;
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }

        session_destroy();
    }
}
