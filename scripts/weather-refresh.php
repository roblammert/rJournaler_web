<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';

function weatherPublicAssetDir(): string
{
    return dirname(__DIR__) . '/public/assets/weather';
}

function ensureWeatherAssetDir(): void
{
    $dir = weatherPublicAssetDir();
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

function weatherIconExtensionFromUrl(string $url): string
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    $candidate = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($candidate, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)) {
        return $candidate;
    }
    return 'png';
}

function mirrorWeatherIcon(string $url): string
{
    $normalized = trim($url);
    if ($normalized === '') {
        return '';
    }

    if (preg_match('/^\/assets\/weather\//', $normalized) === 1) {
        return $normalized;
    }

    if (!preg_match('/^https?:\/\//i', $normalized)) {
        return $normalized;
    }

    ensureWeatherAssetDir();

    $extension = weatherIconExtensionFromUrl($normalized);
    $filename = sha1($normalized) . '.' . $extension;
    $fullPath = weatherPublicAssetDir() . '/' . $filename;
    $publicPath = '/assets/weather/' . $filename;

    if (is_file($fullPath) && filesize($fullPath) > 0) {
        return $publicPath;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 8,
            'header' => "User-Agent: rJournalerWeb/1.0 (weather icon mirror)\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $bytes = @file_get_contents($normalized, false, $context);
    if (!is_string($bytes) || $bytes === '') {
        return $normalized;
    }

    $written = @file_put_contents($fullPath, $bytes, LOCK_EX);
    if (!is_int($written) || $written <= 0) {
        return $normalized;
    }

    return $publicPath;
}

/** @param array<string, mixed> $weather */
function mirrorWeatherIcons(array $weather): array
{
    if (isset($weather['forecast']) && is_array($weather['forecast'])) {
        $iconUrl = trim((string) ($weather['forecast']['icon_url'] ?? ''));
        if ($iconUrl !== '') {
            $weather['forecast']['icon_url'] = mirrorWeatherIcon($iconUrl);
        }
    }

    if (isset($weather['forecast_days']) && is_array($weather['forecast_days'])) {
        foreach ($weather['forecast_days'] as $index => $day) {
            if (!is_array($day)) {
                continue;
            }
            $iconUrl = trim((string) ($day['icon_url'] ?? ''));
            if ($iconUrl === '') {
                continue;
            }
            $weather['forecast_days'][$index]['icon_url'] = mirrorWeatherIcon($iconUrl);
        }
    }

    return $weather;
}

function weatherCacheDir(): string
{
    return dirname(__DIR__) . '/storage/cache/weather';
}

function weatherCachePath(int $userId): string
{
    return weatherCacheDir() . '/user_' . $userId . '.json';
}

function weatherRefreshLockPath(int $userId, string $selectedKey): string
{
    $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $selectedKey);
    if (!is_string($safeKey) || $safeKey === '') {
        $safeKey = 'default';
    }
    return weatherCacheDir() . '/user_' . $userId . '_' . $safeKey . '.lock';
}

function ensureWeatherCacheDir(): void
{
    $dir = weatherCacheDir();
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

/** @return array<string, mixed> */
function readWeatherCache(int $userId): array
{
    $path = weatherCachePath($userId);
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/** @param array<string, mixed> $cache */
function writeWeatherCache(int $userId, array $cache): void
{
    ensureWeatherCacheDir();
    $path = weatherCachePath($userId);
    $encoded = json_encode($cache, JSON_UNESCAPED_SLASHES);
    if (is_string($encoded)) {
        file_put_contents($path, $encoded, LOCK_EX);
    }
}

/** @return array<string, mixed> */
function fetchWeatherForLocation(array $location): array
{
    $projectRoot = dirname(__DIR__);
    $scriptPath = $projectRoot . '/python/weather/noaa_weather.py';
    $venvPythonWindows = $projectRoot . '/.venv/Scripts/python.exe';
    $venvPythonLinux = $projectRoot . '/.venv/bin/python';
    $pythonBin = is_file($venvPythonWindows)
        ? $venvPythonWindows
        : (is_file($venvPythonLinux) ? $venvPythonLinux : (strtoupper((string) PHP_OS_FAMILY) === 'WINDOWS' ? 'python' : 'python3'));

    $arg = json_encode($location, JSON_UNESCAPED_SLASHES);
    if (!is_string($arg) || $arg === '') {
        return ['ok' => false, 'error' => 'Invalid weather location payload'];
    }

    $payloadB64 = base64_encode($arg);
    if (!is_string($payloadB64) || $payloadB64 === '') {
        return ['ok' => false, 'error' => 'Unable to encode weather location payload'];
    }

    $command = escapeshellarg($pythonBin)
        . ' ' . escapeshellarg($scriptPath)
        . ' --location-b64 ' . escapeshellarg($payloadB64)
        . ' 2>&1';

    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    $raw = trim(implode("\n", $output));
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'error' => 'Weather command returned invalid JSON',
            'raw' => $raw,
            'exit_code' => $exitCode,
        ];
    }

    if (($decoded['ok'] ?? false) !== true) {
        $decoded['exit_code'] = $exitCode;
        return $decoded;
    }

    return $decoded;
}

try {
    if ($argc < 5) {
        throw new RuntimeException('Missing weather refresh args');
    }

    $userId = (int) ($argv[1] ?? 0);
    $selectedKey = (string) ($argv[2] ?? '');
    $locationB64 = (string) ($argv[3] ?? '');
    $locationSignature = (string) ($argv[4] ?? '');

    if ($userId <= 0 || $selectedKey === '' || $locationB64 === '' || $locationSignature === '') {
        throw new RuntimeException('Invalid weather refresh args');
    }

    $decoded = base64_decode($locationB64, true);
    if (!is_string($decoded) || $decoded === '') {
        throw new RuntimeException('Unable to decode location payload');
    }

    $location = json_decode($decoded, true);
    if (!is_array($location)) {
        throw new RuntimeException('Location payload is invalid');
    }

    $weather = fetchWeatherForLocation($location);
    if (($weather['ok'] ?? false) === true) {
        $weather = mirrorWeatherIcons($weather);
        $cache = readWeatherCache($userId);
        $cache[$selectedKey] = [
            'location_signature' => $locationSignature,
            'fetched_at' => time(),
            'weather' => $weather,
        ];
        writeWeatherCache($userId, $cache);
    }
} catch (Throwable $throwable) {
    // Best effort background refresh.
} finally {
    $userIdFinal = isset($userId) ? (int) $userId : 0;
    $selectedKeyFinal = isset($selectedKey) ? (string) $selectedKey : '';
    if ($userIdFinal > 0 && $selectedKeyFinal !== '') {
        @unlink(weatherRefreshLockPath($userIdFinal, $selectedKeyFinal));
    }
}
