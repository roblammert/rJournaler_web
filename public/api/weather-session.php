<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Auth/require_auth.php';

use App\Auth\Auth;
use App\Core\Database;

header('Content-Type: application/json; charset=utf-8');

const WEATHER_CACHE_TTL_SECONDS = 3600;
const WEATHER_REFRESH_LOCK_SECONDS = 45;

$defaultPresets = [
    'new_york_us' => [
        'key' => 'new_york_us',
        'label' => 'New York, NY, US',
        'city' => 'New York',
        'state' => 'NY',
        'zip' => '10001',
        'country' => 'US',
        'is_preset' => true,
        'can_delete' => false,
    ],
    'chicago_us' => [
        'key' => 'chicago_us',
        'label' => 'Chicago, IL, US',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip' => '60601',
        'country' => 'US',
        'is_preset' => true,
        'can_delete' => false,
    ],
    'los_angeles_us' => [
        'key' => 'los_angeles_us',
        'label' => 'Los Angeles, CA, US',
        'city' => 'Los Angeles',
        'state' => 'CA',
        'zip' => '90001',
        'country' => 'US',
        'is_preset' => true,
        'can_delete' => false,
    ],
];

/** @return array<string, array<string, mixed>> */
function weatherLocations(array $defaultPresets, array $customPresets): array
{
    $merged = $defaultPresets;
    foreach ($customPresets as $key => $location) {
        if (!is_string($key) || !is_array($location)) {
            continue;
        }
        $merged[$key] = [
            'key' => $key,
            'label' => (string) ($location['label'] ?? ''),
            'city' => (string) ($location['city'] ?? ''),
            'state' => (string) ($location['state'] ?? ''),
            'zip' => (string) ($location['zip'] ?? ''),
            'country' => strtoupper((string) ($location['country'] ?? 'US')),
            'is_preset' => false,
            'can_delete' => true,
        ];
    }

    return $merged;
}

/** @return array<string, array<string, mixed>> */
function parseCustomPresets(string $raw): array
{
    if (trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $normalized = [];
    foreach ($decoded as $key => $location) {
        if (!is_string($key) || !is_array($location)) {
            continue;
        }
        $normalized[$key] = sanitizeLocationInput($location);
    }

    return $normalized;
}

function normalizeSelectedKey(array $locations, string $selectedKey): string
{
    if ($selectedKey !== '' && isset($locations[$selectedKey])) {
        return $selectedKey;
    }

    return 'new_york_us';
}

/** @return array<string, mixed> */
function loadUserWeatherState(PDO $pdo, int $userId, array $defaultPresets): array
{
    $stmt = $pdo->prepare('SELECT weather_presets_json, weather_selected_key FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('Unable to load user weather settings');
    }

    $customPresets = parseCustomPresets((string) ($row['weather_presets_json'] ?? ''));
    $locations = weatherLocations($defaultPresets, $customPresets);
    $selectedKey = normalizeSelectedKey($locations, trim((string) ($row['weather_selected_key'] ?? '')));

    if ((string) ($row['weather_selected_key'] ?? '') !== $selectedKey) {
        $update = $pdo->prepare('UPDATE users SET weather_selected_key = :selected_key, updated_at = UTC_TIMESTAMP() WHERE id = :id');
        $update->execute([
            'selected_key' => $selectedKey,
            'id' => $userId,
        ]);
    }

    return [
        'locations' => $locations,
        'selected_key' => $selectedKey,
    ];
}

function sanitizeLocationInput(array $input): array
{
    $label = trim((string) ($input['label'] ?? ''));
    $city = trim((string) ($input['city'] ?? ''));
    $state = trim((string) ($input['state'] ?? ''));
    $zip = trim((string) ($input['zip'] ?? ''));
    $country = strtoupper(trim((string) ($input['country'] ?? 'US')));

    if ($country === '') {
        $country = 'US';
    }

    if ($label === '') {
        $parts = [];
        if ($city !== '') {
            $parts[] = $city;
        }
        if ($state !== '') {
            $parts[] = $state;
        }
        if ($zip !== '') {
            $parts[] = $zip;
        }
        $parts[] = $country;
        $label = implode(', ', $parts);
    }

    return [
        'label' => $label,
        'city' => $city,
        'state' => $state,
        'zip' => $zip,
        'country' => $country,
    ];
}

/** @return array<string, mixed> */
function fetchWeatherForLocation(array $location): array
{
    $projectRoot = dirname(__DIR__, 2);
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

function weatherCacheDir(): string
{
    return dirname(__DIR__, 2) . '/storage/cache/weather';
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

function weatherLocationSignature(array $location): string
{
    $data = [
        'label' => (string) ($location['label'] ?? ''),
        'city' => (string) ($location['city'] ?? ''),
        'state' => (string) ($location['state'] ?? ''),
        'zip' => (string) ($location['zip'] ?? ''),
        'country' => strtoupper((string) ($location['country'] ?? 'US')),
    ];

    return sha1((string) json_encode($data, JSON_UNESCAPED_SLASHES));
}

/** @return array<string, mixed> */
function readCachedWeather(int $userId, string $selectedKey, string $locationSignature): array
{
    $cache = readWeatherCache($userId);
    $row = $cache[$selectedKey] ?? null;
    if (!is_array($row)) {
        return [
            'has_cache' => false,
            'cache_stale' => true,
            'cache_age_seconds' => null,
            'weather' => null,
        ];
    }

    $storedSignature = (string) ($row['location_signature'] ?? '');
    if ($storedSignature === '' || !hash_equals($storedSignature, $locationSignature)) {
        return [
            'has_cache' => false,
            'cache_stale' => true,
            'cache_age_seconds' => null,
            'weather' => null,
        ];
    }

    $fetchedAt = (int) ($row['fetched_at'] ?? 0);
    $weather = $row['weather'] ?? null;
    if ($fetchedAt <= 0 || !is_array($weather)) {
        return [
            'has_cache' => false,
            'cache_stale' => true,
            'cache_age_seconds' => null,
            'weather' => null,
        ];
    }

    $ageSeconds = max(0, time() - $fetchedAt);
    $isStale = $ageSeconds > WEATHER_CACHE_TTL_SECONDS;

    $forecast = $weather['forecast'] ?? null;
    $iconUrl = is_array($forecast) ? trim((string) ($forecast['icon_url'] ?? '')) : '';
    $forecastDays = $weather['forecast_days'] ?? null;
    $forecastDaysCount = is_array($forecastDays) ? count($forecastDays) : 0;
    if ($iconUrl === '') {
        // Force an early refresh for pre-icon cache rows so dashboard icons appear quickly.
        $isStale = true;
    }
    if ($forecastDaysCount < 5) {
        // Force an early refresh for older cache rows without 5-day forecast payload.
        $isStale = true;
    }

    return [
        'has_cache' => true,
        'cache_stale' => $isStale,
        'cache_age_seconds' => $ageSeconds,
        'weather' => $weather,
    ];
}

function isRefreshInProgress(int $userId, string $selectedKey): bool
{
    $lockPath = weatherRefreshLockPath($userId, $selectedKey);
    if (!is_file($lockPath)) {
        return false;
    }

    $modifiedAt = filemtime($lockPath);
    if (!is_int($modifiedAt)) {
        return false;
    }

    if ((time() - $modifiedAt) > WEATHER_REFRESH_LOCK_SECONDS) {
        @unlink($lockPath);
        return false;
    }

    return true;
}

function markRefreshInProgress(int $userId, string $selectedKey): void
{
    ensureWeatherCacheDir();
    $lockPath = weatherRefreshLockPath($userId, $selectedKey);
    @file_put_contents($lockPath, (string) time(), LOCK_EX);
}

function clearRefreshInProgress(int $userId, string $selectedKey): void
{
    $lockPath = weatherRefreshLockPath($userId, $selectedKey);
    @unlink($lockPath);
}

function launchWeatherRefreshJob(int $userId, string $selectedKey, array $selectedLocation, string $locationSignature): bool
{
    $locationJson = json_encode($selectedLocation, JSON_UNESCAPED_SLASHES);
    if (!is_string($locationJson) || $locationJson === '') {
        return false;
    }
    $locationB64 = base64_encode($locationJson);
    if (!is_string($locationB64) || $locationB64 === '') {
        return false;
    }

    $projectRoot = dirname(__DIR__, 2);
    $scriptPath = $projectRoot . '/scripts/weather-refresh.php';
    if (!is_file($scriptPath)) {
        return false;
    }

    $phpBin = defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $args = [
        escapeshellarg($phpBin),
        escapeshellarg($scriptPath),
        escapeshellarg((string) $userId),
        escapeshellarg($selectedKey),
        escapeshellarg($locationB64),
        escapeshellarg($locationSignature),
    ];

    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'cmd /c start "" /B ' . implode(' ', $args) . ' >NUL 2>&1';
        $handle = @popen($command, 'r');
        if (is_resource($handle)) {
            pclose($handle);
            return true;
        }
        return false;
    }

    $command = implode(' ', $args) . ' >/dev/null 2>&1 &';
    $handle = @popen($command, 'r');
    if (is_resource($handle)) {
        pclose($handle);
        return true;
    }

    return false;
}

try {
    $userId = Auth::userId();
    if (!is_int($userId)) {
        throw new RuntimeException('Not authenticated');
    }

    $pdo = Database::connection($config['database']);
    $state = loadUserWeatherState($pdo, $userId, $defaultPresets);
    $locations = $state['locations'];
    $selectedKey = (string) ($state['selected_key'] ?? 'new_york_us');

    $selectedLocation = $locations[$selectedKey];
    $locationSignature = weatherLocationSignature($selectedLocation);
    $cached = readCachedWeather($userId, $selectedKey, $locationSignature);
    $hasCache = ($cached['has_cache'] ?? false) === true;
    $cacheStale = ($cached['cache_stale'] ?? true) === true;
    $cacheAgeSeconds = isset($cached['cache_age_seconds']) ? (int) $cached['cache_age_seconds'] : null;
    $weather = is_array($cached['weather'] ?? null) ? $cached['weather'] : null;

    $refreshing = false;
    if (!$hasCache) {
        // First-time load should fetch immediately so dashboard does not get stuck in refresh loop.
        $direct = fetchWeatherForLocation($selectedLocation);
        if (($direct['ok'] ?? false) === true) {
            $cache = readWeatherCache($userId);
            $cache[$selectedKey] = [
                'location_signature' => $locationSignature,
                'fetched_at' => time(),
                'weather' => $direct,
            ];
            writeWeatherCache($userId, $cache);
            $hasCache = true;
            $cacheStale = false;
            $cacheAgeSeconds = 0;
            $weather = $direct;
        } else {
            $weather = is_array($direct) ? $direct : [
                'ok' => false,
                'error' => 'Unable to load weather data right now.',
            ];
        }
    } elseif ($cacheStale) {
        if (!isRefreshInProgress($userId, $selectedKey)) {
            markRefreshInProgress($userId, $selectedKey);
            $refreshing = launchWeatherRefreshJob($userId, $selectedKey, $selectedLocation, $locationSignature);
            if (!$refreshing) {
                // If background launch fails (for example popen disabled), clear lock and refresh inline.
                clearRefreshInProgress($userId, $selectedKey);
                $direct = fetchWeatherForLocation($selectedLocation);
                if (($direct['ok'] ?? false) === true) {
                    $cache = readWeatherCache($userId);
                    $cache[$selectedKey] = [
                        'location_signature' => $locationSignature,
                        'fetched_at' => time(),
                        'weather' => $direct,
                    ];
                    writeWeatherCache($userId, $cache);
                    $cacheStale = false;
                    $cacheAgeSeconds = 0;
                    $weather = $direct;
                }
            }
        } else {
            $refreshing = true;
        }
    }

    if (!is_array($weather)) {
        $weather = [
            'ok' => false,
            'error' => $refreshing
                ? 'Weather refresh in progress. Please wait a moment.'
                : 'Weather data is not available yet.',
        ];
    }

    echo json_encode([
        'ok' => true,
        'selected_key' => $selectedKey,
        'locations' => array_values($locations),
        'weather' => $weather,
        'refreshing' => $refreshing,
        'cache_hit' => $hasCache,
        'cache_stale' => $cacheStale,
        'cache_age_seconds' => $cacheAgeSeconds,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $throwable->getMessage(),
    ]);
}
