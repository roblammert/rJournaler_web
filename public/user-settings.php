<?php
declare(strict_types=1);


function sanitizeWeatherLocationInput(array $input): array
{
    $label = trim((string) ($input['label'] ?? ''));
    $city = trim((string) ($input['city'] ?? ''));
    $state = trim((string) ($input['state'] ?? ''));
    $zip = trim((string) ($input['zip'] ?? ''));
    $country = strtoupper(trim((string) ($input['country'] ?? 'US')));
    if ($country === '') {
        $country = 'US';
    }
    if ($city === '') {
        $city = 'New Richmond';
    }
    if ($state === '') {
        $state = 'WI';
    }
    if ($zip === '') {
        $zip = '54017';
    }
    if ($label === '') {
        $label = $city . ', ' . $state . ', ' . $country;
    }
    return [
        'label' => $label,
        'city' => $city,
        'state' => $state,
        'zip' => $zip,
        'country' => $country,
    ];
}

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__) . '/app/Auth/require_auth.php';

use App\Auth\Auth;
use App\Core\Database;
use App\Import\ImportBatchService;
use App\Security\Crypto;
use App\Security\Csrf;
$userId = Auth::userId();
if (!is_int($userId)) {
    header('Location: /login.php');
    exit;
}
$editorToolbarOptions = [
    'bold' => 'Bold',
    'italic' => 'Italic',
    'underline' => 'Underline',
    'strikeThrough' => 'Strike',
    'heading' => 'Headings',
    'ul' => 'Bulleted List',
    'ol' => 'Numbered List',
];
// Non-configurable buttons: Full Screen, Time
$editorSettings = [
    'toolbar' => array_keys($editorToolbarOptions),
];
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->prepare('SELECT editor_settings_json FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (is_array($row) && isset($row['editor_settings_json']) && $row['editor_settings_json']) {
            $json = json_decode($row['editor_settings_json'], true);
            if (is_array($json) && isset($json['toolbar']) && is_array($json['toolbar'])) {
                // Only allow valid toolbar options
                $editorSettings['toolbar'] = array_values(array_intersect($json['toolbar'], array_keys($editorToolbarOptions)));
                if (count($editorSettings['toolbar']) === 0) {
                    $editorSettings['toolbar'] = array_keys($editorToolbarOptions);
                }
            }
        }
    }
} catch (Throwable $e) {
    // Ignore toolbar settings load errors, fallback to default
}

$userId = Auth::userId();
$userId = Auth::userId();
if (!is_int($userId)) {
    header('Location: /login.php');
    exit;
}


$allowedInterfaceThemes = ['light', 'neutral', 'dark'];
$defaultInterfaceTheme = 'neutral';
$defaultWeatherPresets = [
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
    'new_richmond_wi' => [
        'key' => 'new_richmond_wi',
        'label' => 'New Richmond, WI, US',
        'city' => 'New Richmond',
        'state' => 'WI',
        'zip' => '54017',
        'country' => 'US',
        'is_preset' => true,
        'can_delete' => false,
    ],
];
$error = null;

/**
 * Decrypt and normalize the TOTP secret from the database.
 * @param string $storedValue
 * @return string
 */
function resolveTotpSecret(string $storedValue): string
{
    $appKey = (string) env('APP_KEY', '');
    $decrypted = Crypto::decrypt($storedValue, $appKey);
    $secret = '';
    if (is_string($decrypted) && $decrypted !== '') {
        $secret = $decrypted;
    } else {
        $secret = $storedValue;
    }
    // Remove known prefixes
    if (str_starts_with($secret, 'plain:')) {
        $secret = substr($secret, 6);
    }
    if (str_starts_with($secret, 'base32:')) {
        $secret = substr($secret, 7);
    }
    return strtoupper(trim($secret));
}

/**
 * Parse custom weather presets JSON from DB.
 * @param string $raw
 * @return array<string, array<string, mixed>>
 */
function parseWeatherCustomPresets(string $raw): array
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
        $normalized[$key] = sanitizeWeatherLocationInput($location);
    }
    return $normalized;
}
$success = null;
$successTone = 'success';
$pendingSecret = null;
$currentQrUrl = null;
$currentOtpAuthUri = null;
$pendingQrUrl = null;
$pendingOtpAuthUri = null;
$importBatches = [];
// ...existing code...

/** @return array<string, array<string, mixed>> */
function buildWeatherLocations(array $defaultWeatherPresets, array $customPresets): array
{
    $merged = $defaultWeatherPresets;
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

function normalizeWeatherSelectedKey(array $locations, string $selectedKey): string
{
    if ($selectedKey !== '' && isset($locations[$selectedKey])) {
        return $selectedKey;
    }

    return 'new_york_us';
}

/** @return array<string, mixed> */
function loadUserWeatherState(PDO $pdo, int $userId, array $defaultWeatherPresets): array
{
    $stmt = $pdo->prepare('SELECT weather_presets_json, weather_selected_key FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('Unable to load weather settings.');
    }

    $custom = parseWeatherCustomPresets((string) ($row['weather_presets_json'] ?? ''));
    $locations = buildWeatherLocations($defaultWeatherPresets, $custom);
    $selectedKey = normalizeWeatherSelectedKey($locations, trim((string) ($row['weather_selected_key'] ?? '')));

    return [
        'custom' => $custom,
        'locations' => $locations,
        'selected_key' => $selectedKey,
    ];
}

function persistUserWeatherState(PDO $pdo, int $userId, array $customPresets, string $selectedKey): void
{
    $encoded = json_encode($customPresets, JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to save weather presets.');
    }

    $stmt = $pdo->prepare('UPDATE users SET weather_presets_json = :presets_json, weather_selected_key = :selected_key, updated_at = UTC_TIMESTAMP() WHERE id = :id');
    $stmt->execute([
        'presets_json' => $encoded,
        'selected_key' => $selectedKey,
        'id' => $userId,
    ]);
}

try {
    $pdo = Database::connection($config['database']);
} catch (Throwable $throwable) {
    http_response_code(500);
    $error = 'User settings are unavailable right now.';
}

$interfaceThemeEnabled = false;
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $columnCheckStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $columnCheckStmt->execute([
            'table_name' => 'users',
            'column_name' => 'interface_theme',
        ]);
        $interfaceThemeEnabled = (int) $columnCheckStmt->fetchColumn() > 0;
    } catch (Throwable $throwable) {
        $interfaceThemeEnabled = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null) {
    $csrf = $_POST['_csrf'] ?? null;
    if (!Csrf::validate(is_string($csrf) ? $csrf : null)) {
        $error = 'Invalid request token.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'update_editor_settings') {
            // Save editor toolbar settings for the user
            $toolbar = isset($_POST['toolbar']) && is_array($_POST['toolbar']) ? array_values(array_filter($_POST['toolbar'], 'is_string')) : [];
            // Only allow valid toolbar options
            $toolbar = array_values(array_intersect($toolbar, array_keys($editorToolbarOptions)));
            if (count($toolbar) === 0) {
                $error = 'Please select at least one toolbar button.';
            } else {
                $settings = [
                    'toolbar' => $toolbar,
                ];
                $json = json_encode($settings, JSON_UNESCAPED_SLASHES);
                if (!is_string($json)) {
                    $error = 'Failed to save editor settings.';
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET editor_settings_json = :json, updated_at = UTC_TIMESTAMP() WHERE id = :id');
                    $stmt->execute([
                        'json' => $json,
                        'id' => $userId,
                    ]);
                    $success = 'Editor settings updated.';
                    $successTone = 'success';
                    // Reload settings for display
                    $editorSettings['toolbar'] = $toolbar;
                }
            }
        } elseif ($action === 'update_profile') {
            $displayName = trim((string) ($_POST['display_name'] ?? ''));
            $timezonePreference = trim((string) ($_POST['timezone_preference'] ?? ''));
            $interfaceTheme = strtolower(trim((string) ($_POST['interface_theme'] ?? $defaultInterfaceTheme)));
            if ($timezonePreference !== '' && !in_array($timezonePreference, timezone_identifiers_list(), true)) {
                $error = 'Invalid timezone selected.';
            } elseif (!in_array($interfaceTheme, $allowedInterfaceThemes, true)) {
                $error = 'Invalid interface theme selected.';
            } else {
                if ($interfaceThemeEnabled) {
                    $stmt = $pdo->prepare('UPDATE users SET display_name = :display_name, timezone_preference = :timezone_preference, interface_theme = :interface_theme, updated_at = UTC_TIMESTAMP() WHERE id = :id');
                    $stmt->execute([
                        'display_name' => $displayName !== '' ? $displayName : null,
                        'timezone_preference' => $timezonePreference !== '' ? $timezonePreference : null,
                        'interface_theme' => $interfaceTheme,
                        'id' => $userId,
                    ]);
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET display_name = :display_name, timezone_preference = :timezone_preference, updated_at = UTC_TIMESTAMP() WHERE id = :id');
                    $stmt->execute([
                        'display_name' => $displayName !== '' ? $displayName : null,
                        'timezone_preference' => $timezonePreference !== '' ? $timezonePreference : null,
                        'id' => $userId,
                    ]);
                }
                $_SESSION['display_name'] = $displayName !== '' ? $displayName : null;
                $_SESSION['timezone_preference'] = $timezonePreference !== '' ? $timezonePreference : null;
                $_SESSION['interface_theme'] = $interfaceTheme;
                $success = 'Profile updated.';
                $successTone = 'success';
            }
        } elseif ($action === 'change_password') {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

            if ($newPassword === '' || strlen($newPassword) < 8) {
                $error = 'New password must be at least 8 characters.';
            } elseif (!hash_equals($newPassword, $confirmPassword)) {
                $error = 'Password confirmation does not match.';
            } else {
                $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $userId]);
                $row = $stmt->fetch();
                $existingHash = is_array($row) ? (string) ($row['password_hash'] ?? '') : '';
                if ($existingHash === '' || !password_verify($currentPassword, $existingHash)) {
                    $error = 'Current password is invalid.';
                } else {
                    $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
                    if (!is_string($newHash) || $newHash === '') {
                        $error = 'Unable to update password.';
                    } else {
                        $pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = UTC_TIMESTAMP() WHERE id = :id')->execute([
                            'password_hash' => $newHash,
                            'id' => $userId,
                        ]);
                        $success = 'Password updated.';
                        $successTone = 'success';
                    }
                }
            }
        } elseif ($action === 'weather_add_preset') {
            $input = sanitizeWeatherLocationInput($_POST);
            if ($input['zip'] === '' && $input['city'] === '') {
                $error = 'Weather preset needs at least a city or ZIP/postal code.';
            } else {
                $state = loadUserWeatherState($pdo, $userId, $defaultWeatherPresets);
                $custom = is_array($state['custom'] ?? null) ? $state['custom'] : [];

                $newKey = 'custom_' . bin2hex(random_bytes(6));
                $custom[$newKey] = $input;

                persistUserWeatherState($pdo, $userId, $custom, $newKey);
                $success = 'Weather preset added and selected.';
                $successTone = 'success';
            }
        } elseif ($action === 'weather_select_preset') {
            $selectedKey = trim((string) ($_POST['weather_selected_key'] ?? ''));
            $state = loadUserWeatherState($pdo, $userId, $defaultWeatherPresets);
            $locations = is_array($state['locations'] ?? null) ? $state['locations'] : [];

            if ($selectedKey === '' || !isset($locations[$selectedKey])) {
                $error = 'Selected weather preset was not found.';
            } else {
                $custom = is_array($state['custom'] ?? null) ? $state['custom'] : [];
                persistUserWeatherState($pdo, $userId, $custom, $selectedKey);
                $success = 'Weather source updated.';
                $successTone = 'info';
            }
        } elseif ($action === 'weather_delete_preset') {
            $deleteKey = trim((string) ($_POST['weather_delete_key'] ?? ''));
            $state = loadUserWeatherState($pdo, $userId, $defaultWeatherPresets);
            $custom = is_array($state['custom'] ?? null) ? $state['custom'] : [];

            if ($deleteKey === '' || !isset($custom[$deleteKey])) {
                $error = 'Only custom weather presets can be deleted.';
            } else {
                unset($custom[$deleteKey]);

                $locations = buildWeatherLocations($defaultWeatherPresets, $custom);
                $currentSelected = (string) ($state['selected_key'] ?? 'new_york_us');
                $nextSelected = normalizeWeatherSelectedKey($locations, $currentSelected === $deleteKey ? '' : $currentSelected);

                persistUserWeatherState($pdo, $userId, $custom, $nextSelected);
                $success = 'Weather preset deleted.';
                $successTone = 'warn';
            }
        } elseif ($action === 'import_upload') {
            $file = $_FILES['import_zip'] ?? null;
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'Please choose a ZIP file to upload.';
            } else {
                $originalName = (string) ($file['name'] ?? 'import.zip');
                if (preg_match('/\.zip$/i', $originalName) !== 1) {
                    $error = 'Uploaded file must be a .zip archive.';
                } else {
                    $tmpPath = (string) ($file['tmp_name'] ?? '');
                    if ($tmpPath === '' || !is_file($tmpPath)) {
                        $error = 'Uploaded file was not received.';
                    } else {
                        $uidVersionCode = strtoupper(trim((string) ($_POST['uid_version_code'] ?? '')));
                        if ($uidVersionCode === '') {
                            $uidVersionCode = $defaultUidVersionCode;
                        }
                        if (preg_match('/^[A-Z][0-9]{6}$/', $uidVersionCode) !== 1) {
                            $error = 'UID version code must be a letter followed by 6 numbers (example: T032602).';
                        } else {
                            try {
                                $service = new ImportBatchService();
                                $result = $service->stageZipImportWithUidVersionCode($pdo, $userId, $tmpPath, $originalName, $uidVersionCode);
                                $batchId = (int) ($result['batch_id'] ?? 0);
                                header('Location: /import-review.php?batch=' . $batchId);
                                exit;
                            } catch (Throwable $throwable) {
                                $error = 'Import upload failed: ' . $throwable->getMessage();
                            }
                        }
                    }
                }
            }
        }
    }
}

$user = null;
$trustedDevices = [];
if ($error === null) {
    $themeSelectSql = $interfaceThemeEnabled ? 'interface_theme' : "'neutral' AS interface_theme";
    $stmt = $pdo->prepare('SELECT id, username, email, display_name, timezone_preference, ' . $themeSelectSql . ', weather_presets_json, weather_selected_key FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (is_array($user)) {

        try {
            $importService = new ImportBatchService();
            $importBatches = $importService->listRecentBatches($pdo, $userId, 10);
        } catch (Throwable $throwable) {
            $importBatches = [];
        }
    }
}

$token = Csrf::token();
$displayNameValue = is_array($user) ? (string) ($user['display_name'] ?? '') : '';
$timezoneValue = is_array($user) ? (string) ($user['timezone_preference'] ?? '') : '';
$interfaceThemeValue = strtolower(trim(is_array($user) ? (string) ($user['interface_theme'] ?? $defaultInterfaceTheme) : $defaultInterfaceTheme));
if (!in_array($interfaceThemeValue, $allowedInterfaceThemes, true)) {
    $interfaceThemeValue = $defaultInterfaceTheme;
}
$weatherCustomPresets = is_array($user) ? parseWeatherCustomPresets((string) ($user['weather_presets_json'] ?? '')) : [];
$weatherPresetOptions = buildWeatherLocations($defaultWeatherPresets, $weatherCustomPresets);
$weatherSelectedKey = normalizeWeatherSelectedKey($weatherPresetOptions, trim(is_array($user) ? (string) ($user['weather_selected_key'] ?? '') : ''));
$weatherSelectedLabel = isset($weatherPresetOptions[$weatherSelectedKey])
    ? (string) ($weatherPresetOptions[$weatherSelectedKey]['label'] ?? $weatherSelectedKey)
    : 'New York, NY, US';
$weatherInputLabel = trim((string) ($_POST['label'] ?? ''));
$weatherInputCity = trim((string) ($_POST['city'] ?? ''));
$weatherInputState = trim((string) ($_POST['state'] ?? ''));
$weatherInputZip = trim((string) ($_POST['zip'] ?? ''));
$weatherInputCountry = strtoupper(trim((string) ($_POST['country'] ?? 'US')));
if ($weatherInputCountry === '') {
    $weatherInputCountry = 'US';
}
$displayTimezone = 'America/Chicago';
$formatUtcDateTime = static function (?string $value, string $timezone): string {
    if (!is_string($value) || trim($value) === '') {
        return '';
    }
    try {
        $utc = new DateTimeZone('UTC');
        $target = new DateTimeZone($timezone);
        $dt = new DateTimeImmutable($value, $utc);
        return $dt->setTimezone($target)->format('n/j/Y g:i:s A T');
    } catch (Throwable $throwable) {
        return $value;
    }
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Settings</title>
    <style>
        :root {
            --font-ui: "Segoe UI", "Aptos", "Trebuchet MS", sans-serif;
            --radius-lg: 14px;
            --radius-md: 10px;
            --shadow-md: 0 12px 28px rgba(17, 24, 39, 0.14);
            --shadow-sm: 0 4px 12px rgba(17, 24, 39, 0.08);
        }

        body[data-theme="light"] {
            --bg: #f7f9fc;
            --bg-accent: #edf3fb;
            --surface: #ffffff;
            --surface-soft: #f7faff;
            --border: #d6e0ef;
            --text: #1f2f43;
            --text-muted: #5b6f87;
            --heading: #152334;
            --link: #1f5f9a;
            --success-bg: #e9f8ef;
            --success-border: #73b990;
            --info-bg: #eaf2fc;
            --info-border: #7ea4d7;
            --warn-bg: #fff5e8;
            --warn-border: #d0a05e;
            --danger-bg: #fdeef0;
            --danger-border: #d78a95;
            --danger-text: #8b2335;
            --input-bg: #ffffff;
            --toast-text: #ffffff;
            --toast-success-bg: #0a7a32;
            --toast-info-bg: #1f5fbd;
            --toast-warn-bg: #a86b1c;
        }

        body[data-theme="neutral"] {
            --bg: #f4f3f1;
            --bg-accent: #eceae6;
            --surface: #fffdf8;
            --surface-soft: #f4f1ea;
            --border: #d8d1c5;
            --text: #2f3432;
            --text-muted: #676d69;
            --heading: #222827;
            --link: #3c5f72;
            --success-bg: #edf4ea;
            --success-border: #87ad87;
            --info-bg: #ecf1f5;
            --info-border: #93a9b7;
            --warn-bg: #f9f2e5;
            --warn-border: #c4a170;
            --danger-bg: #f8ecec;
            --danger-border: #b88c8c;
            --danger-text: #6f3232;
            --input-bg: #fffefb;
            --toast-text: #ffffff;
            --toast-success-bg: #2f6f42;
            --toast-info-bg: #3c5f72;
            --toast-warn-bg: #8a6f2c;
        }

        body[data-theme="dark"] {
            --bg: #171d23;
            --bg-accent: #1e2730;
            --surface: #222d38;
            --surface-soft: #263340;
            --border: #364757;
            --text: #dbe4ec;
            --text-muted: #9aabbb;
            --heading: #f1f5f8;
            --link: #8fc3f3;
            --success-bg: #193427;
            --success-border: #3f8d63;
            --info-bg: #203548;
            --info-border: #4679a5;
            --warn-bg: #3b3323;
            --warn-border: #a18a56;
            --danger-bg: #41262b;
            --danger-border: #91535f;
            --danger-text: #f3bcc6;
            --input-bg: #1e2832;
            --toast-text: #0f171f;
            --toast-success-bg: #7fc59a;
            --toast-info-bg: #93c7f5;
            --toast-warn-bg: #dfc27b;
            --shadow-md: 0 14px 32px rgba(0, 0, 0, 0.35);
            --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.28);
        }

        body {
            margin: 0;
            padding: 1rem;
            font-family: var(--font-ui);
            background: radial-gradient(circle at 18% 0%, var(--bg-accent), var(--bg) 40%);
            color: var(--text);
        }

        main {
            max-width: 1240px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 0.8rem;
            flex-wrap: wrap;
            margin-bottom: 0.8rem;
        }

        .page-header h1 {
            margin: 0;
            color: var(--heading);
        }

        .header-links {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            flex-wrap: wrap;
        }

        .pill {
            display: inline-block;
            border: 1px solid var(--border);
            background: var(--surface-soft);
            color: var(--text);
            border-radius: 999px;
            padding: 0.12rem 0.52rem;
            font-size: 0.82rem;
            box-shadow: var(--shadow-sm);
        }

        .muted {
            color: var(--text-muted);
        }

        a {
            color: var(--link);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .alert {
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 0.58rem 0.68rem;
            margin-bottom: 0.65rem;
            background: var(--surface-soft);
        }

        .alert.error {
            border-color: var(--danger-border);
            background: var(--danger-bg);
            color: var(--danger-text);
        }

        .alert.success {
            border-color: var(--success-border);
            background: var(--success-bg);
        }

        .settings-card {
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            background: var(--surface);
            box-shadow: var(--shadow-sm);
            padding: 0.86rem;
            margin-bottom: 0.82rem;
        }

        .settings-card h2 {
            margin: 0 0 0.6rem;
            color: var(--heading);
            font-size: 1.08rem;
        }

        .settings-card h3 {
            margin: 0.6rem 0 0.45rem;
            color: var(--heading);
            font-size: 0.95rem;
        }

        .settings-form {
            display: block;
            margin-top: 0.3rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(220px, 1fr));
            gap: 0.6rem 0.7rem;
        }

        .form-grid.single {
            grid-template-columns: 1fr;
        }

        .form-field {
            display: grid;
            gap: 0.24rem;
            min-width: 0;
        }

        .form-field.full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 600;
        }

        input,
        select,
        button {
            font: inherit;
        }

        input,
        select {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: var(--input-bg);
            color: var(--text);
            padding: 0.5rem 0.56rem;
        }

        button {
            border: 1px solid var(--border);
            border-radius: 9px;
            background: var(--surface-soft);
            color: var(--text);
            padding: 0.46rem 0.74rem;
            cursor: pointer;
        }

        button:hover {
            filter: brightness(0.98);
        }

        .form-actions {
            margin-top: 0.62rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .note {
            margin: 0.4rem 0 0;
            color: var(--text-muted);
            font-size: 0.82rem;
        }

        .table-wrap {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 640px;
            margin-top: 0.45rem;
        }

        .data-table th,
        .data-table td {
            border: 1px solid var(--border);
            padding: 0.45rem 0.5rem;
            text-align: left;
            vertical-align: top;
        }

        .data-table th {
            background: var(--surface-soft);
            color: var(--heading);
            font-size: 0.82rem;
        }

        .inline-form {
            display: inline;
        }

        .toast {
            position: fixed;
            top: 1rem;
            right: 1rem;
            color: var(--toast-text);
            border-radius: 8px;
            padding: 0.55rem 0.75rem;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.2);
            max-width: 360px;
            z-index: 9999;
            opacity: 1;
            transition: opacity 250ms ease;
        }

        .toast.success { background: var(--toast-success-bg); }
        .toast.info { background: var(--toast-info-bg); }
        .toast.warn { background: var(--toast-warn-bg); }
        .toast.hidden { opacity: 0; }

        @media (max-width: 900px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            body {
                padding: 0.72rem;
            }

            .settings-card {
                padding: 0.7rem;
            }
        }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars($interfaceThemeValue, ENT_QUOTES, 'UTF-8'); ?>">
<main>
    <header class="page-header">
        <h1>User Settings</h1>
        <div class="header-links">
            <a class="pill" href="/index.php">Back to Dashboard</a>
            <span class="pill">Theme: <?php echo htmlspecialchars(ucfirst($interfaceThemeValue), ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="pill">Active Weather Source: <?php echo htmlspecialchars($weatherSelectedLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="pill">rJournaler_Web: v<?php echo htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </header>

    <?php if ((string) ($_GET['import'] ?? '') === 'denied'): ?>
        <div class="alert success">Import was denied and staged entries were deleted.</div>
    <?php endif; ?>

    <?php if ($error !== null): ?><div class="alert error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($success !== null): ?><div class="alert success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($success !== null): ?>
        <div id="settings-toast" class="toast <?php echo htmlspecialchars($successTone, ENT_QUOTES, 'UTF-8'); ?>" role="status" aria-live="polite"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <section class="settings-card">
        <h2>Profile</h2>
        <form method="post" action="/user-settings.php" class="settings-form">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="update_profile">
            <div class="form-grid">
                <div class="form-field">
                        
                    <label for="display_name">Display Name</label>
                    <input id="display_name" name="display_name" type="text" maxlength="128" value="<?php echo htmlspecialchars($displayNameValue, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                
                <div class="form-field">
                    <label for="timezone_preference">Timezone</label>
                    <select id="timezone_preference" name="timezone_preference">
                        <option value="">System default</option>
                        <?php foreach (timezone_identifiers_list() as $tz): ?>
                            <option value="<?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $timezoneValue === $tz ? 'selected' : ''; ?>><?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field full">
                    <label for="interface_theme">Theme</label>
                    <select id="interface_theme" name="interface_theme" <?php echo $interfaceThemeEnabled ? '' : 'disabled'; ?>>
                        <?php foreach ($allowedInterfaceThemes as $themeName): ?>
                            <option value="<?php echo htmlspecialchars($themeName, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $interfaceThemeValue === $themeName ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($themeName), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php if (!$interfaceThemeEnabled): ?>
                <p class="note">Theme selection will be enabled after running the latest database migration.</p>
            <?php endif; ?>
            <div class="form-actions"><button type="submit">Save Profile</button></div>
        </form>
    </section>
    <section class="settings-card">
        <h2>Editor Settings</h2>
        <form method="post" action="/user-settings.php" class="settings-form">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="update_editor_settings">
            <div class="form-grid">
                <div class="form-field full">
                    <label>Toolbar Buttons</label>
                    <div style="display:flex;flex-wrap:wrap;gap:1.2em;">
                        <?php foreach ($editorToolbarOptions as $key => $label): ?>
                            <label style="display:inline-flex;align-items:center;gap:0.4em;">
                                <input type="checkbox" name="toolbar[]" value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" <?php echo in_array($key, $editorSettings['toolbar'], true) ? 'checked' : ''; ?>>
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top:0.7em;font-size:0.92em;color:#888;">
                        <strong>Note:</strong> Full Screen and Time buttons are always shown.
                    </div>
                </div>
            </div>
            <div class="form-actions"><button type="submit">Save Editor Settings</button></div>
        </form>
    </section>
    <section class="settings-card">
        <h2>Weather Presets</h2>
        <p class="note">Current weather source: <strong><?php echo htmlspecialchars($weatherSelectedLabel, ENT_QUOTES, 'UTF-8'); ?></strong></p>

        <form method="post" action="/user-settings.php" class="settings-form">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="weather_select_preset">
            <div class="form-grid single">
                <div class="form-field">
                    <label for="weather_selected_key">Use this weather preset on dashboard</label>
                    <select id="weather_selected_key" name="weather_selected_key">
                        <?php foreach ($weatherPresetOptions as $presetKey => $preset): ?>
                            <option value="<?php echo htmlspecialchars((string) $presetKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $weatherSelectedKey === (string) $presetKey ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) ($preset['label'] ?? $presetKey), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-actions"><button type="submit">Set Active Weather Source</button></div>
        </form>

        <h3>Add Preset</h3>
        <form method="post" action="/user-settings.php" class="settings-form">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="weather_add_preset">
            <div class="form-grid">
                <div class="form-field full">
                    <label for="weather_label">Label</label>
                    <input id="weather_label" name="label" type="text" maxlength="120" value="<?php echo htmlspecialchars($weatherInputLabel, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Home / Office / Family">
                </div>
                <div class="form-field">
                    <label for="weather_city">City</label>
                    <input id="weather_city" name="city" type="text" maxlength="120" value="<?php echo htmlspecialchars($weatherInputCity, ENT_QUOTES, 'UTF-8'); ?>" placeholder="City">
                </div>
                <div class="form-field">
                    <label for="weather_state">State/Region</label>
                    <input id="weather_state" name="state" type="text" maxlength="120" value="<?php echo htmlspecialchars($weatherInputState, ENT_QUOTES, 'UTF-8'); ?>" placeholder="State or Region">
                </div>
                <div class="form-field">
                    <label for="weather_zip">ZIP/Postal</label>
                    <input id="weather_zip" name="zip" type="text" maxlength="32" value="<?php echo htmlspecialchars($weatherInputZip, ENT_QUOTES, 'UTF-8'); ?>" placeholder="ZIP or Postal">
                </div>
                <div class="form-field">
                    <label for="weather_country">Country</label>
                    <input id="weather_country" name="country" type="text" maxlength="2" value="<?php echo htmlspecialchars($weatherInputCountry, ENT_QUOTES, 'UTF-8'); ?>" placeholder="US">
                </div>
            </div>
            <div class="form-actions"><button type="submit">Add Weather Preset</button></div>
        </form>

        <h3>Delete Custom Preset</h3>
        <?php $customPresetRows = array_filter($weatherPresetOptions, static fn (array $row): bool => (bool) ($row['can_delete'] ?? false)); ?>
        <?php if (count($customPresetRows) === 0): ?>
            <p class="note">No custom weather presets yet.</p>
        <?php else: ?>
            <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Preset</th><th>Location</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($customPresetRows as $presetKey => $preset): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) $presetKey, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($preset['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <form method="post" action="/user-settings.php" class="inline-form">
                                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="weather_delete_preset">
                                <input type="hidden" name="weather_delete_key" value="<?php echo htmlspecialchars((string) $presetKey, ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="settings-card">
        <h2>Password</h2>
        <form method="post" action="/user-settings.php" class="settings-form">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="change_password">
            <div class="form-grid">
                <div class="form-field">
                    <label for="current_password">Current Password</label>
                    <input id="current_password" name="current_password" type="password" required>
                </div>
                <div class="form-field">
                    <label for="new_password">New Password</label>
                    <input id="new_password" name="new_password" type="password" minlength="8" required>
                </div>
                <div class="form-field full">
                    <label for="confirm_password">Confirm New Password</label>
                    <input id="confirm_password" name="confirm_password" type="password" minlength="8" required>
                </div>
            </div>
            <div class="form-actions"><button type="submit">Change Password</button></div>
        </form>
    </section>



    <section class="settings-card">
        <h2>Monthly Import</h2>
        <p class="note">Upload a ZIP containing monthly files named <code>YYYY-MM.txt</code>. Files are scanned recursively inside the archive.</p>
        <form method="post" action="/user-settings.php" enctype="multipart/form-data" class="settings-form">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="import_upload">
            <div class="form-grid single">
                <div class="form-field">
                    <label for="uid_version_code">UID Version Code (3rd UID section)</label>
                    <input id="uid_version_code" name="uid_version_code" type="text" inputmode="text" pattern="[A-Za-z][0-9]{6}" maxlength="7" value="<?php echo htmlspecialchars($defaultUidVersionCode, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="form-field note">Example: entering <code>T032602</code> creates UIDs like <code>20260305123045-rjournaler-T032602-abc123</code>.</div>
                <div class="form-field">
                    <label for="import_zip">ZIP File</label>
                    <input id="import_zip" name="import_zip" type="file" accept=".zip,application/zip" required>
                </div>
            </div>
            <div class="form-actions"><button type="submit">Upload and Parse</button></div>
        </form>

        <h3>Recent Import Batches</h3>
        <?php if (count($importBatches) === 0): ?>
            <p class="note">No import batches yet.</p>
        <?php else: ?>
            <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Created</th><th>Source</th><th>UID Version Code</th><th>Status</th><th>Entries</th><th>Open</th></tr></thead>
                <tbody>
                <?php foreach ($importBatches as $batch): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($formatUtcDateTime((string) ($batch['created_at'] ?? ''), $displayTimezone), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($batch['source_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) (($batch['uid_version_digits'] ?? '') !== '' ? strtoupper((string) $batch['uid_version_digits']) : $defaultUidVersionCode), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($batch['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo (int) ($batch['entry_count'] ?? 0); ?></td>
                        <td><a href="/import-review.php?batch=<?php echo (int) ($batch['id'] ?? 0); ?>">Review</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php if ($success !== null): ?>
<script>
    (function () {
        const toast = document.getElementById('settings-toast');
        if (!toast) {
            return;
        }
        window.setTimeout(() => {
            toast.classList.add('hidden');
            window.setTimeout(() => toast.remove(), 300);
        }, 2600);
    })();
</script>
<?php endif; ?>
</body>
</html>
