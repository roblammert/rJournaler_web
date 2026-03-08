<?php

declare(strict_types=1);

function env(string $key, mixed $default = null): mixed
{
    static $envLoaded = false;

    if (!$envLoaded) {
        $envPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                        continue;
                    }

                    [$name, $value] = array_pad(explode('=', $trimmed, 2), 2, '');
                    $name = trim($name);
                    $value = trim($value);

                    if ($name !== '' && getenv($name) === false) {
                        putenv($name . '=' . $value);
                        $_ENV[$name] = $value;
                        $_SERVER[$name] = $value;
                    }
                }
            }
        }
        $envLoaded = true;
    }

    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    return match (strtolower($value)) {
        'true', '(true)' => true,
        'false', '(false)' => false,
        'null', '(null)' => null,
        'empty', '(empty)' => '',
        default => $value,
    };
}
