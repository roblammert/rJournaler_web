<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__) . '/app/Auth/require_auth.php';
require_once dirname(__DIR__) . '/app/Auth/require_admin.php';

use App\Auth\Auth;
use App\Core\Database;
use App\Security\Csrf;

$error = null;
$success = null;
$ollamaTestResult = null;
$weatherSchemaWarning = null;
$weatherSchemaMigrationCommand = 'php scripts/migrate.php --to=013_meta_group_3_weather.sql';

$settingSpecs = [
    'security.trusted_device_days' => ['label' => 'Trusted Device Days', 'type' => 'int', 'min' => 1],
    'processing.queue_complete_retention_hours' => ['label' => 'Queue Complete Retention Hours', 'type' => 'float', 'min' => 0.25],
    'processing.queue_failed_retention_hours' => ['label' => 'Queue Failed Retention Hours', 'type' => 'float', 'min' => 0.25],
    'processing.orchestrator_log_retention_hours' => ['label' => 'Orchestrator Log Retention Hours', 'type' => 'float', 'min' => 0.25],
    'processing.audit_log_retention_days' => ['label' => 'Audit Log Retention Days', 'type' => 'int', 'min' => 1],
    'processing.auto_finish_hour_local' => ['label' => 'Auto Finish Hour (0-23)', 'type' => 'int', 'min' => 0, 'max' => 23],
    'processing.ollama_url' => ['label' => 'Ollama URL', 'type' => 'string'],
    'processing.ollama_model' => ['label' => 'Ollama Model', 'type' => 'string'],
    'processing.ollama_retry_seconds' => ['label' => 'Ollama Retry Seconds', 'type' => 'int', 'min' => 10],
    'processing.ollama_timeout_seconds' => ['label' => 'Ollama Timeout Seconds', 'type' => 'float', 'min' => 1],
    'processing.optimus_max_autobots' => ['label' => 'Max Active Autobots', 'type' => 'int', 'min' => 1],
    'processing.autobot_max_per_type_default' => ['label' => 'Default Max Autobots Per Task Type', 'type' => 'int', 'min' => 1],
    'processing.autobot_idle_lifetime_minutes' => ['label' => 'Autobot Idle Lifetime (Minutes)', 'type' => 'float', 'min' => 0.5],
];

$pipelineStageIds = [];
$pipelineConfigPath = (string) ($config['processing']['pipeline_config_path'] ?? '');
if ($pipelineConfigPath === '') {
    $pipelineConfigPath = dirname(__DIR__) . '/python/worker/pipeline_stages.json';
}
if (is_file($pipelineConfigPath)) {
    $pipelineJson = file_get_contents($pipelineConfigPath);
    if (is_string($pipelineJson) && trim($pipelineJson) !== '') {
        $decodedPipeline = json_decode($pipelineJson, true);
        if (is_array($decodedPipeline)) {
            foreach ($decodedPipeline as $stage) {
                if (!is_array($stage)) {
                    continue;
                }
                $stageId = trim((string) ($stage['id'] ?? ''));
                if ($stageId === '') {
                    continue;
                }
                $pipelineStageIds[] = $stageId;
                $settingSpecs['processing.autobot_limit.' . $stageId] = [
                    'label' => 'Max Autobots for ' . $stageId,
                    'type' => 'int',
                    'min' => 1,
                ];
            }
        }
    }
}

$settingInputName = static function (string $settingKey): string {
    return 'setting_' . str_replace('.', '__', $settingKey);
};

$adminOllamaModelsTimeoutCapSeconds = 8.0;
$adminOllamaTestTimeoutCapSeconds = 15.0;

$clampAdminOllamaModelsTimeout = static function (float $timeoutSeconds) use ($adminOllamaModelsTimeoutCapSeconds): int {
    // Keep model list lookups short so page loads remain snappy.
    $cappedSeconds = min($adminOllamaModelsTimeoutCapSeconds, max(1.0, $timeoutSeconds));
    return max(1, (int) ceil($cappedSeconds));
};

$clampAdminOllamaTestTimeout = static function (float $timeoutSeconds) use ($adminOllamaTestTimeoutCapSeconds): int {
    // Allow a longer window for explicit user-triggered test calls.
    $cappedSeconds = min($adminOllamaTestTimeoutCapSeconds, max(1.0, $timeoutSeconds));
    return max(1, (int) ceil($cappedSeconds));
};

$resolveOllamaApiBase = static function (string $configuredUrl): string {
    $trimmed = trim($configuredUrl);
    if ($trimmed === '') {
        return 'http://127.0.0.1:11434';
    }

    // Allow host:port entries by assuming http when scheme is omitted.
    if (strpos($trimmed, '://') === false) {
        $trimmed = 'http://' . $trimmed;
    }

    $parts = parse_url($trimmed);
    if (!is_array($parts)) {
        return 'http://127.0.0.1:11434';
    }

    $scheme = (string) ($parts['scheme'] ?? 'http');
    $host = (string) ($parts['host'] ?? '');
    if ($host === '') {
        return 'http://127.0.0.1:11434';
    }
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

    return sprintf('%s://%s%s', $scheme, $host, $port);
};

$fetchOllamaModels = static function (string $configuredUrl, float $timeoutSeconds) use ($resolveOllamaApiBase, $clampAdminOllamaModelsTimeout): array {
    $url = $resolveOllamaApiBase($configuredUrl) . '/api/tags';
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $clampAdminOllamaModelsTimeout($timeoutSeconds),
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return [];
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return [];
    }
    $models = $decoded['models'] ?? [];
    if (!is_array($models)) {
        return [];
    }

    $names = [];
    foreach ($models as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = (string) ($row['name'] ?? '');
        if ($name !== '') {
            $names[] = $name;
        }
    }

    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values(array_unique($names));
};

$runOllamaTest = static function (string $configuredUrl, string $model, float $timeoutSeconds, string $question) use ($resolveOllamaApiBase, $clampAdminOllamaTestTimeout): array {
    $url = $resolveOllamaApiBase($configuredUrl) . '/api/generate';
    $effectiveTimeout = $clampAdminOllamaTestTimeout($timeoutSeconds);
    $payload = [
        'model' => $model,
        'prompt' => $question,
        'stream' => false,
    ];

    error_log('[admin-settings][test_ollama] url=' . $url . ' model=' . $model . ' timeout=' . (string) $effectiveTimeout);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'timeout' => $effectiveTimeout,
            'ignore_errors' => true,
        ],
    ]);

    $started = microtime(true);
    $body = @file_get_contents($url, false, $context);
    $elapsed = round((microtime(true) - $started), 3);

    if ($body === false) {
        return ['ok' => false, 'message' => 'Connection failed or timed out.', 'elapsed' => $elapsed, 'url' => $url];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'message' => 'Server response was not valid JSON.', 'elapsed' => $elapsed, 'url' => $url];
    }

    $responseText = trim((string) ($decoded['response'] ?? ''));
    if ($responseText === '') {
        return ['ok' => false, 'message' => 'No response text returned by Ollama.', 'elapsed' => $elapsed, 'url' => $url];
    }

    return [
        'ok' => true,
        'message' => $responseText,
        'elapsed' => $elapsed,
        'url' => $url,
    ];
};

try {
    $pdo = Database::connection($config['database']);

    $requiredWeatherColumns = ['weather_location_key', 'weather_location_json'];
    $missingWeatherColumns = [];
    foreach ($requiredWeatherColumns as $columnName) {
        $columnStmt = $pdo->prepare(
            'SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name
             LIMIT 1'
        );
        $columnStmt->execute([
            'table_name' => 'journal_entries',
            'column_name' => $columnName,
        ]);
        $columnExists = (bool) $columnStmt->fetchColumn();
        if (!$columnExists) {
            $missingWeatherColumns[] = $columnName;
        }
    }

    $groupTableStmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
         LIMIT 1'
    );
    $groupTableStmt->execute(['table_name' => 'entry_meta_group_3']);
    $hasEntryMetaGroup3 = (bool) $groupTableStmt->fetchColumn();

    if ($missingWeatherColumns !== [] || !$hasEntryMetaGroup3) {
        $warningParts = [];
        if ($missingWeatherColumns !== []) {
            $warningParts[] = 'journal_entries missing columns: ' . implode(', ', $missingWeatherColumns);
        }
        if (!$hasEntryMetaGroup3) {
            $warningParts[] = 'entry_meta_group_3 table missing';
        }
        $weatherSchemaWarning = 'Weather schema update appears incomplete (' . implode('; ', $warningParts) . '). Run migration 013 to restore full weather processing support.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['_csrf'] ?? null;
        if (!Csrf::validate(is_string($csrf) ? $csrf : null)) {
            $error = 'Invalid request token.';
        } else {
            $action = (string) ($_POST['action'] ?? '');
            $currentUserId = Auth::userId();

            if ($action === 'save_settings') {
                $savedSettingCount = 0;
                foreach ($settingSpecs as $settingKey => $spec) {
                    $inputName = $settingInputName($settingKey);
                    if (!array_key_exists($inputName, $_POST)) {
                        continue;
                    }
                    $raw = trim((string) ($_POST[$inputName] ?? ''));

                    if ($spec['type'] === 'int') {
                        $value = (string) max((int) $spec['min'], (int) $raw);
                        if (isset($spec['max'])) {
                            $value = (string) min((int) $spec['max'], (int) $value);
                        }
                    } elseif ($spec['type'] === 'float') {
                        $value = (string) max((float) $spec['min'], (float) $raw);
                    } else {
                        $value = $raw;
                    }

                    $stmt = $pdo->prepare(
                        'INSERT INTO app_settings (setting_key, setting_value, updated_by_user_id, updated_at)
                         VALUES (:setting_key, :setting_value, :updated_by_user_id, UTC_TIMESTAMP())
                         ON DUPLICATE KEY UPDATE
                           setting_value = VALUES(setting_value),
                           updated_by_user_id = VALUES(updated_by_user_id),
                           updated_at = UTC_TIMESTAMP()'
                    );
                    $stmt->execute([
                        'setting_key' => $settingKey,
                        'setting_value' => $value,
                        'updated_by_user_id' => $currentUserId,
                    ]);
                    $savedSettingCount++;
                }
                error_log('[admin-settings][save_settings] user_id=' . (string) $currentUserId . ' saved_settings=' . (string) $savedSettingCount);
                $success = 'Settings saved.';
            } elseif ($action === 'create_user') {
                $username = trim((string) ($_POST['username'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');
                $displayName = trim((string) ($_POST['display_name'] ?? ''));
                $timezonePreference = trim((string) ($_POST['timezone_preference'] ?? ''));
                $isAdmin = ((string) ($_POST['is_admin'] ?? '0')) === '1' ? 1 : 0;

                if ($username === '' || $email === '' || strlen($password) < 8) {
                    $error = 'Username, email, and password (min 8 chars) are required.';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Email is invalid.';
                } elseif ($timezonePreference !== '' && !in_array($timezonePreference, timezone_identifiers_list(), true)) {
                    $error = 'Timezone is invalid.';
                } else {
                    $hash = password_hash($password, PASSWORD_ARGON2ID);
                    if (!is_string($hash) || $hash === '') {
                        $error = 'Failed to hash password.';
                    } else {
                        $stmt = $pdo->prepare(
                            'INSERT INTO users (username, email, password_hash, display_name, timezone_preference, is_admin, is_active, created_at, updated_at)
                             VALUES (:username, :email, :password_hash, :display_name, :timezone_preference, :is_admin, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
                        );
                        $stmt->execute([
                            'username' => $username,
                            'email' => $email,
                            'password_hash' => $hash,
                            'display_name' => $displayName !== '' ? $displayName : null,
                            'timezone_preference' => $timezonePreference !== '' ? $timezonePreference : null,
                            'is_admin' => $isAdmin,
                        ]);
                        $success = 'User created.';
                    }
                }
            } elseif ($action === 'delete_user') {
                $targetId = (int) ($_POST['target_user_id'] ?? 0);
                if ($targetId <= 0) {
                    $error = 'Invalid user.';
                } elseif ($currentUserId === $targetId) {
                    $error = 'You cannot delete your own account.';
                } else {
                    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $targetId]);
                    $success = 'User deleted.';
                }
            } elseif ($action === 'reset_password') {
                $targetId = (int) ($_POST['target_user_id'] ?? 0);
                $newPassword = (string) ($_POST['new_password'] ?? '');
                if ($targetId <= 0 || strlen($newPassword) < 8) {
                    $error = 'Select a user and provide a password of at least 8 characters.';
                } else {
                    $hash = password_hash($newPassword, PASSWORD_ARGON2ID);
                    if (!is_string($hash) || $hash === '') {
                        $error = 'Failed to hash password.';
                    } else {
                        $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = UTC_TIMESTAMP() WHERE id = :id')->execute([
                            'hash' => $hash,
                            'id' => $targetId,
                        ]);
                        $success = 'Password reset.';
                    }
                }
            } elseif ($action === 'clear_totp') {
                $targetId = (int) ($_POST['target_user_id'] ?? 0);
                if ($targetId <= 0) {
                    $error = 'Invalid user.';
                } else {
                    $pdo->prepare('UPDATE users SET totp_secret_encrypted = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['id' => $targetId]);
                    $success = 'TOTP cleared for user.';
                }
            } elseif ($action === 'toggle_admin') {
                $targetId = (int) ($_POST['target_user_id'] ?? 0);
                $newValue = ((string) ($_POST['new_is_admin'] ?? '0')) === '1' ? 1 : 0;
                if ($targetId <= 0) {
                    $error = 'Invalid user.';
                } elseif ($currentUserId === $targetId && $newValue === 0) {
                    $error = 'You cannot remove your own admin access.';
                } else {
                    $pdo->prepare('UPDATE users SET is_admin = :is_admin, updated_at = UTC_TIMESTAMP() WHERE id = :id')->execute([
                        'is_admin' => $newValue,
                        'id' => $targetId,
                    ]);
                    $success = 'User role updated.';
                }
            } elseif ($action === 'revoke_user_devices') {
                $targetId = (int) ($_POST['target_user_id'] ?? 0);
                if ($targetId <= 0) {
                    $error = 'Invalid user.';
                } else {
                    $pdo->prepare('DELETE FROM trusted_devices WHERE user_id = :user_id')->execute(['user_id' => $targetId]);
                    $success = 'Trusted devices revoked for user.';
                }
            }
        }
    }

    $settingsRows = $pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll();
    $settingValues = [];
    foreach ($settingsRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = (string) ($row['setting_key'] ?? '');
        if ($key !== '') {
            $settingValues[$key] = (string) ($row['setting_value'] ?? '');
        }
    }

    $getEffective = static function (string $key, string $configPath) use ($settingValues, $config): string {
        if (array_key_exists($key, $settingValues)) {
            return (string) $settingValues[$key];
        }

        $parts = explode('.', $configPath);
        $value = $config;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return '';
            }
            $value = $value[$part];
        }

        return is_scalar($value) ? (string) $value : '';
    };

    $effectiveOllamaUrl = $getEffective('processing.ollama_url', 'processing.ollama_url');
    $effectiveOllamaModel = $getEffective('processing.ollama_model', 'processing.ollama_model');
    $effectiveTimeoutSeconds = (float) $getEffective('processing.ollama_timeout_seconds', 'processing.ollama_timeout_seconds');
    if ($effectiveTimeoutSeconds <= 0) {
        $effectiveTimeoutSeconds = 45;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null) {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'test_ollama') {
            $testPrompt = trim((string) ($_POST['test_prompt'] ?? 'Say hello in one short sentence.'));
            $testModel = trim((string) ($_POST['test_model'] ?? $effectiveOllamaModel));
            $testTimeout = (float) ($_POST['test_timeout_seconds'] ?? $effectiveTimeoutSeconds);
            $testTimeout = max(1.0, $testTimeout);

            if ($testModel === '') {
                $error = 'Select or enter an Ollama model before running test.';
            } else {
                $ollamaTestResult = $runOllamaTest($effectiveOllamaUrl, $testModel, $testTimeout, $testPrompt);
                if (!($ollamaTestResult['ok'] ?? false)) {
                    $error = 'Ollama test failed.';
                } else {
                    $success = 'Ollama test succeeded.';
                }
            }
        }
    }

    $ollamaModels = $fetchOllamaModels($effectiveOllamaUrl, $effectiveTimeoutSeconds);
    if ($effectiveOllamaModel !== '' && !in_array($effectiveOllamaModel, $ollamaModels, true)) {
        $ollamaModels[] = $effectiveOllamaModel;
        sort($ollamaModels, SORT_NATURAL | SORT_FLAG_CASE);
    }

    $users = $pdo->query('SELECT id, username, email, display_name, timezone_preference, is_admin, is_active, created_at, updated_at FROM users ORDER BY id ASC')->fetchAll();
} catch (Throwable $throwable) {
    http_response_code(500);
    $error = 'Admin settings are unavailable right now.';
    $settingValues = [];
    $users = [];
    $ollamaModels = [];
    $getEffective = static function (string $key = '', string $configPath = ''): string {
        return '';
    };
    $effectiveOllamaUrl = '';
    $effectiveOllamaModel = '';
    $effectiveTimeoutSeconds = 45.0;
    $weatherSchemaWarning = null;
}

$adminTimeZone = Auth::timezonePreference() ?? date_default_timezone_get();
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

$token = Csrf::token();
$interfaceTheme = Auth::interfaceTheme();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Settings</title>
    <style>
        :root {
            --font-ui: "Segoe UI", "Aptos", "Trebuchet MS", sans-serif;
            --radius-md: 12px;
            --radius-sm: 8px;
            --shadow-md: 0 12px 28px rgba(17, 24, 39, 0.14);
            --shadow-sm: 0 3px 10px rgba(17, 24, 39, 0.08);
            --transition-fast: 150ms ease;
        }

        body[data-theme="light"] {
            --bg: #f7f9fc;
            --bg-accent: #eef3fb;
            --surface: #ffffff;
            --surface-soft: #f6f9ff;
            --border: #d6e0ef;
            --text: #1f2f43;
            --text-muted: #5b6f87;
            --heading: #152334;
            --link: #1f5f9a;
            --button-bg: #2d5f88;
            --button-bg-hover: #224b6d;
            --button-text: #f8fbff;
            --ok-bg: #e9f8ef;
            --ok-border: #73b990;
            --ok-text: #1f5d39;
            --err-bg: #fdeef0;
            --err-border: #d78a95;
            --err-text: #842533;
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
            --button-bg: #4d6470;
            --button-bg-hover: #3f525b;
            --button-text: #f6f8f7;
            --ok-bg: #edf4ea;
            --ok-border: #87ad87;
            --ok-text: #2b5230;
            --err-bg: #f8ecec;
            --err-border: #b88c8c;
            --err-text: #6f3232;
        }

        body[data-theme="dark"] {
            --bg: #171d23;
            --bg-accent: #1e2730;
            --surface: #222d38;
            --surface-soft: #263340;
            --border: #364757;
            --text: #dbe4ec;
            --text-muted: #98a9b9;
            --heading: #f1f5f8;
            --link: #8fc3f3;
            --button-bg: #4f84ae;
            --button-bg-hover: #3f729a;
            --button-text: #f2f8ff;
            --ok-bg: #193427;
            --ok-border: #3f8d63;
            --ok-text: #98deb5;
            --err-bg: #41262b;
            --err-border: #91535f;
            --err-text: #f0b5bf;
            --shadow-md: 0 14px 32px rgba(0, 0, 0, 0.35);
            --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.28);
        }

        body {
            margin: 0;
            padding: 1.25rem;
            font-family: var(--font-ui);
            background: radial-gradient(circle at 20% 0%, var(--bg-accent), var(--bg) 38%);
            color: var(--text);
        }

        main {
            max-width: 1200px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.8rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .header-links {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        h1, h2, h3 {
            color: var(--heading);
            margin-top: 0;
        }

        h1 {
            margin-bottom: 0.35rem;
        }

        p {
            color: var(--text-muted);
        }

        a {
            color: var(--link);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .pill {
            display: inline-block;
            border: 1px solid var(--border);
            background: var(--surface-soft);
            color: var(--text);
            padding: 0.12rem 0.52rem;
            border-radius: 999px;
            font-size: 0.82rem;
            box-shadow: var(--shadow-sm);
        }

        .alert {
            border-radius: var(--radius-sm);
            padding: 0.65rem 0.8rem;
            border: 1px solid transparent;
            margin: 0.45rem 0;
        }

        .alert-error {
            background: var(--err-bg);
            border-color: var(--err-border);
            color: var(--err-text);
        }

        .alert-success {
            background: var(--ok-bg);
            border-color: var(--ok-border);
            color: var(--ok-text);
        }

        .alert-warning {
            background: #fff6dd;
            border-color: #d7b35a;
            color: #7a5514;
        }

        body[data-theme="dark"] .alert-warning {
            background: #3a2f17;
            border-color: #b48d3e;
            color: #f2d899;
        }

        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            padding: 1rem;
            margin: 0 0 1rem;
        }

        .panel h2 {
            border-bottom: 1px solid var(--border);
            padding-bottom: 0.5rem;
            margin-bottom: 0.9rem;
        }

        form {
            margin: 0;
        }

        .settings-form {
            display: grid;
            gap: 0.7rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(250px, 1fr));
            gap: 0.7rem 1rem;
        }

        .form-grid-full {
            grid-column: 1 / -1;
        }

        .form-group-title {
            margin: 0.2rem 0 0.15rem;
            padding-top: 0.2rem;
            font-size: 0.79rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            color: var(--text-muted);
        }

        .form-field {
            display: grid;
            gap: 0.35rem;
            align-content: start;
        }

        .form-field-check {
            align-items: center;
            padding-top: 0.2rem;
        }

        .form-field-check label {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            font-weight: 500;
        }

        .form-field-check input[type="checkbox"] {
            width: 1rem;
            height: 1rem;
            margin: 0;
            accent-color: var(--button-bg);
        }

        .form-actions {
            margin-top: 0.15rem;
        }

        label {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 600;
        }

        input,
        select,
        button,
        textarea {
            font: inherit;
        }

        input,
        select,
        textarea {
            background: var(--surface-soft);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            padding: 0.55rem 0.62rem;
            transition: border-color var(--transition-fast), box-shadow var(--transition-fast), background var(--transition-fast);
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: color-mix(in srgb, var(--button-bg) 55%, var(--border));
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--button-bg) 22%, transparent);
        }

        button {
            justify-self: start;
            background: var(--button-bg);
            color: var(--button-text);
            border: 1px solid color-mix(in srgb, var(--button-bg) 70%, #0000);
            border-radius: 9px;
            padding: 0.5rem 0.82rem;
            cursor: pointer;
            transition: background var(--transition-fast), transform var(--transition-fast);
        }

        button:hover {
            background: var(--button-bg-hover);
        }

        button:active {
            transform: translateY(1px);
        }

        pre,
        code {
            font-family: Consolas, "Cascadia Code", monospace;
        }

        pre {
            background: var(--surface-soft);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.7rem;
            white-space: pre-wrap;
            color: var(--text);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid var(--border);
            background: var(--surface);
            border-radius: 10px;
            overflow: hidden;
        }

        th,
        td {
            padding: 0.55rem 0.6rem;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: top;
        }

        thead th {
            background: var(--surface-soft);
            color: var(--heading);
            font-size: 0.82rem;
            letter-spacing: 0.01em;
        }

        tbody tr:hover {
            background: color-mix(in srgb, var(--surface-soft) 68%, transparent);
        }

        @media (max-width: 900px) {
            body {
                padding: 0.8rem;
            }

            .panel {
                padding: 0.75rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars($interfaceTheme, ENT_QUOTES, 'UTF-8'); ?>">
<main>
    <div class="page-header">
        <h1>Admin Settings</h1>
        <div class="header-links">
            <a class="pill" href="/index.php">Back to Dashboard</a>
            <a class="pill" href="/admin-reprocess.php">Targeted Reprocess</a>
            <div class="pill">Theme: <?php echo htmlspecialchars(ucfirst($interfaceTheme), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="pill">rJournaler_Web: v<?php echo htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </div>

    <?php if ($error !== null): ?><p class="alert alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($success !== null): ?><p class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($weatherSchemaWarning !== null): ?>
        <div class="alert alert-warning">
            <p><?php echo htmlspecialchars($weatherSchemaWarning, ENT_QUOTES, 'UTF-8'); ?></p>
            <p>Suggested command: <code><?php echo htmlspecialchars($weatherSchemaMigrationCommand, ENT_QUOTES, 'UTF-8'); ?></code></p>
        </div>
    <?php endif; ?>

    <section class="panel">
        <h2>Time Settings</h2>
        <form class="settings-form" method="post" action="/admin-settings.php">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save_settings">

            <div class="form-grid">
                <p class="form-grid-full form-group-title">Retention and Security</p>
                <div class="form-field">
                    <label for="setting_security_trusted_device_days">Trusted Device Days</label>
                    <input id="setting_security_trusted_device_days" name="<?php echo htmlspecialchars($settingInputName('security.trusted_device_days'), ENT_QUOTES, 'UTF-8'); ?>" type="number" min="1" step="1" value="<?php echo htmlspecialchars($getEffective('security.trusted_device_days', 'security.trusted_device_days'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="form-field">
                    <label for="setting_processing_queue_complete_retention_hours">Queue Complete Retention Hours</label>
                    <input id="setting_processing_queue_complete_retention_hours" name="<?php echo htmlspecialchars($settingInputName('processing.queue_complete_retention_hours'), ENT_QUOTES, 'UTF-8'); ?>" type="number" min="0.25" step="0.25" value="<?php echo htmlspecialchars($getEffective('processing.queue_complete_retention_hours', 'processing.queue_complete_retention_hours'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="form-field">
                    <label for="setting_processing_queue_failed_retention_hours">Queue Failed Retention Hours</label>
                    <input id="setting_processing_queue_failed_retention_hours" name="<?php echo htmlspecialchars($settingInputName('processing.queue_failed_retention_hours'), ENT_QUOTES, 'UTF-8'); ?>" type="number" min="0.25" step="0.25" value="<?php echo htmlspecialchars($getEffective('processing.queue_failed_retention_hours', 'processing.queue_failed_retention_hours'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="form-field">
                    <label for="setting_processing_orchestrator_log_retention_hours">Orchestrator Log Retention Hours</label>
                    <input id="setting_processing_orchestrator_log_retention_hours" name="<?php echo htmlspecialchars($settingInputName('processing.orchestrator_log_retention_hours'), ENT_QUOTES, 'UTF-8'); ?>" type="number" min="0.25" step="0.25" value="<?php echo htmlspecialchars($getEffective('processing.orchestrator_log_retention_hours', 'processing.orchestrator_log_retention_hours'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="form-field">
                    <label for="setting_processing_audit_log_retention_days">Audit Log Retention Days</label>
                    <input id="setting_processing_audit_log_retention_days" name="<?php echo htmlspecialchars($settingInputName('processing.audit_log_retention_days'), ENT_QUOTES, 'UTF-8'); ?>" type="number" min="1" step="1" value="<?php echo htmlspecialchars($getEffective('processing.audit_log_retention_days', 'processing.audit_log_retention_days'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <p class="form-grid-full form-group-title">Scheduling</p>
                <div class="form-field">
                    <label for="setting_processing_auto_finish_hour_local">Auto Finish Hour Local</label>
                    <input id="setting_processing_auto_finish_hour_local" name="<?php echo htmlspecialchars($settingInputName('processing.auto_finish_hour_local'), ENT_QUOTES, 'UTF-8'); ?>" type="number" min="0" max="23" step="1" value="<?php echo htmlspecialchars($getEffective('processing.auto_finish_hour_local', 'processing.auto_finish_hour_local'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>

            <div class="form-actions"><button type="submit">Save Time Settings</button></div>
        </form>
    </section>

    <section class="panel">
        <h2>Optimus the Orchestrator</h2>
        <form class="settings-form" method="post" action="/admin-settings.php">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save_settings">

            <div class="form-grid">
                <p class="form-grid-full form-group-title">Global Capacity</p>
                <div class="form-field">
                    <label for="setting_processing_optimus_max_autobots">Max Active Autobots</label>
                    <input id="setting_processing_optimus_max_autobots" name="<?php echo htmlspecialchars($settingInputName('processing.optimus_max_autobots'), ENT_QUOTES, 'UTF-8'); ?>" type="number" min="1" step="1" value="<?php echo htmlspecialchars($getEffective('processing.optimus_max_autobots', 'processing.optimus_max_autobots'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="form-field">
                    <label for="setting_processing_autobot_max_per_type_default">Default Max Autobots Per Task Type</label>
                    <input id="setting_processing_autobot_max_per_type_default" name="<?php echo htmlspecialchars($settingInputName('processing.autobot_max_per_type_default'), ENT_QUOTES, 'UTF-8'); ?>" type="number" min="1" step="1" value="<?php echo htmlspecialchars($getEffective('processing.autobot_max_per_type_default', 'processing.autobot_max_per_type_default'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="form-field">
                    <label for="setting_processing_autobot_idle_lifetime_minutes">Autobot Idle Lifetime (Minutes)</label>
                    <input id="setting_processing_autobot_idle_lifetime_minutes" name="<?php echo htmlspecialchars($settingInputName('processing.autobot_idle_lifetime_minutes'), ENT_QUOTES, 'UTF-8'); ?>" type="number" min="0.5" step="0.5" value="<?php echo htmlspecialchars($getEffective('processing.autobot_idle_lifetime_minutes', 'processing.autobot_idle_lifetime_minutes'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <p class="form-grid-full form-group-title">Per Task Type Limits</p>
                <?php foreach ($pipelineStageIds as $stageId): ?>
                    <?php $stageSettingKey = 'processing.autobot_limit.' . $stageId; ?>
                    <?php $stageSettingValue = $getEffective($stageSettingKey, $stageSettingKey); ?>
                    <?php if (trim($stageSettingValue) === '') { $stageSettingValue = '1'; } ?>
                    <div class="form-field">
                        <label for="setting_<?php echo htmlspecialchars(str_replace('.', '_', $stageSettingKey), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars('Max Autobots for ' . $stageId, ENT_QUOTES, 'UTF-8'); ?></label>
                        <input id="setting_<?php echo htmlspecialchars(str_replace('.', '_', $stageSettingKey), ENT_QUOTES, 'UTF-8'); ?>" name="<?php echo htmlspecialchars($settingInputName($stageSettingKey), ENT_QUOTES, 'UTF-8'); ?>" type="number" min="1" step="1" value="<?php echo htmlspecialchars($stageSettingValue, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="form-actions"><button type="submit">Save Optimus Settings</button></div>
        </form>
    </section>

    <section class="panel">
        <h2>Ollama Settings</h2>
        <form class="settings-form" method="post" action="/admin-settings.php">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save_settings">

            <div class="form-grid">
                <p class="form-grid-full form-group-title">Endpoint and Model</p>
                <div class="form-field">
                    <label for="setting_processing_ollama_url">Ollama URL</label>
                    <input id="setting_processing_ollama_url" name="<?php echo htmlspecialchars($settingInputName('processing.ollama_url'), ENT_QUOTES, 'UTF-8'); ?>" type="text" value="<?php echo htmlspecialchars($effectiveOllamaUrl, ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="form-field">
                    <label for="setting_processing_ollama_model">Ollama Model</label>
                    <select id="setting_processing_ollama_model" name="<?php echo htmlspecialchars($settingInputName('processing.ollama_model'), ENT_QUOTES, 'UTF-8'); ?>">
                        <option value="">Select model</option>
                        <?php foreach ($ollamaModels as $modelName): ?>
                            <option value="<?php echo htmlspecialchars($modelName, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $effectiveOllamaModel === $modelName ? 'selected' : ''; ?>><?php echo htmlspecialchars($modelName, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <p class="form-grid-full form-group-title">Retry and Timeout</p>
                <div class="form-field">
                    <label for="setting_processing_ollama_retry_seconds">OLLAMA_RETRY_SECONDS</label>
                    <input id="setting_processing_ollama_retry_seconds" name="<?php echo htmlspecialchars($settingInputName('processing.ollama_retry_seconds'), ENT_QUOTES, 'UTF-8'); ?>" type="number" min="10" step="1" value="<?php echo htmlspecialchars($getEffective('processing.ollama_retry_seconds', 'processing.ollama_retry_seconds'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="form-field">
                    <label for="setting_processing_ollama_timeout_seconds">OLLAMA_TIMEOUT_SECONDS</label>
                    <input id="setting_processing_ollama_timeout_seconds" name="<?php echo htmlspecialchars($settingInputName('processing.ollama_timeout_seconds'), ENT_QUOTES, 'UTF-8'); ?>" type="number" min="1" step="0.5" value="<?php echo htmlspecialchars($getEffective('processing.ollama_timeout_seconds', 'processing.ollama_timeout_seconds'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>

            <div class="form-actions"><button type="submit">Save Ollama Settings</button></div>
        </form>

        <h3>Test Ollama Connection</h3>
        <p>
            Admin model list lookups are capped to <?php echo htmlspecialchars((string) $adminOllamaModelsTimeoutCapSeconds, ENT_QUOTES, 'UTF-8'); ?> seconds.
            Test calls are capped to <?php echo htmlspecialchars((string) $adminOllamaTestTimeoutCapSeconds, ENT_QUOTES, 'UTF-8'); ?> seconds.
            Worker processing still uses <code>OLLAMA_TIMEOUT_SECONDS</code>.
        </p>
        <form class="settings-form" method="post" action="/admin-settings.php">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="test_ollama">

            <div class="form-grid">
                <p class="form-grid-full form-group-title">Execution</p>
                <div class="form-field">
                    <label for="test_model">Model</label>
                    <select id="test_model" name="test_model">
                        <?php foreach ($ollamaModels as $modelName): ?>
                            <option value="<?php echo htmlspecialchars($modelName, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $effectiveOllamaModel === $modelName ? 'selected' : ''; ?>><?php echo htmlspecialchars($modelName, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="test_timeout_seconds">Timeout (seconds)</label>
                    <input id="test_timeout_seconds" name="test_timeout_seconds" type="number" min="1" step="0.5" value="<?php echo htmlspecialchars((string) $effectiveTimeoutSeconds, ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <p class="form-grid-full form-group-title">Prompt</p>
                <div class="form-field form-grid-full">
                    <label for="test_prompt">Question</label>
                    <input id="test_prompt" name="test_prompt" type="text" value="Say hello in one short sentence.">
                </div>
            </div>

            <div class="form-actions"><button type="submit">Run Ollama Test</button></div>
        </form>

        <?php if (is_array($ollamaTestResult)): ?>
            <p>
                Called URL: <?php echo htmlspecialchars((string) ($ollamaTestResult['url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <p>
                Test elapsed: <?php echo htmlspecialchars((string) ($ollamaTestResult['elapsed'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>s
            </p>
            <pre><?php echo htmlspecialchars((string) ($ollamaTestResult['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></pre>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Create User</h2>
        <form class="settings-form" method="post" action="/admin-settings.php">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="create_user">

            <div class="form-grid">
                <p class="form-grid-full form-group-title">Account Basics</p>
                <div class="form-field">
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" required>
                </div>

                <div class="form-field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" required>
                </div>

                <div class="form-field">
                    <label for="password">Initial Password</label>
                    <input id="password" name="password" type="password" minlength="8" required>
                </div>

                <p class="form-grid-full form-group-title">Profile and Access</p>
                <div class="form-field">
                    <label for="display_name">Display Name</label>
                    <input id="display_name" name="display_name" type="text">
                </div>

                <div class="form-field">
                    <label for="timezone_preference">Timezone</label>
                    <select id="timezone_preference" name="timezone_preference">
                        <option value="">System default</option>
                        <?php foreach (timezone_identifiers_list() as $tz): ?>
                            <option value="<?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field form-field-check">
                    <label><input type="checkbox" name="is_admin" value="1"> Admin user</label>
                </div>
            </div>

            <div class="form-actions"><button type="submit">Create User</button></div>
        </form>
    </section>

    <section class="panel">
        <h2>User Administration</h2>
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Email</th>
                <th>Display Name</th>
                <th>Timezone</th>
                <th>Admin</th>
                <th>Active</th>
                <th>Created</th>
                <th>Updated</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (count($users) === 0): ?>
                <tr><td colspan="10">No users found.</td></tr>
            <?php else: ?>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo (int) ($user['id'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars((string) ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($user['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($user['timezone_preference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo ((int) ($user['is_admin'] ?? 0)) === 1 ? 'yes' : 'no'; ?></td>
                        <td><?php echo ((int) ($user['is_active'] ?? 0)) === 1 ? 'yes' : 'no'; ?></td>
                        <td><?php echo htmlspecialchars($formatUtcDateTime((string) ($user['created_at'] ?? ''), $adminTimeZone), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($formatUtcDateTime((string) ($user['updated_at'] ?? ''), $adminTimeZone), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <form method="post" action="/admin-settings.php" style="display:inline-block; margin-right:0.35rem;">
                                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="toggle_admin">
                                <input type="hidden" name="target_user_id" value="<?php echo (int) ($user['id'] ?? 0); ?>">
                                <input type="hidden" name="new_is_admin" value="<?php echo ((int) ($user['is_admin'] ?? 0)) === 1 ? '0' : '1'; ?>">
                                <button type="submit"><?php echo ((int) ($user['is_admin'] ?? 0)) === 1 ? 'Remove Admin' : 'Make Admin'; ?></button>
                            </form>

                            <form method="post" action="/admin-settings.php" style="display:inline-block; margin-right:0.35rem;">
                                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="clear_totp">
                                <input type="hidden" name="target_user_id" value="<?php echo (int) ($user['id'] ?? 0); ?>">
                                <button type="submit">Clear TOTP</button>
                            </form>

                            <form method="post" action="/admin-settings.php" style="display:inline-block; margin-right:0.35rem;">
                                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="revoke_user_devices">
                                <input type="hidden" name="target_user_id" value="<?php echo (int) ($user['id'] ?? 0); ?>">
                                <button type="submit">Revoke Devices</button>
                            </form>

                            <form method="post" action="/admin-settings.php" style="display:inline-block; margin-right:0.35rem;">
                                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="target_user_id" value="<?php echo (int) ($user['id'] ?? 0); ?>">
                                <input type="password" name="new_password" minlength="8" placeholder="new password" required>
                                <button type="submit">Reset Password</button>
                            </form>

                            <form method="post" action="/admin-settings.php" style="display:inline-block;">
                                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="target_user_id" value="<?php echo (int) ($user['id'] ?? 0); ?>">
                                <button type="submit">Delete User</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
