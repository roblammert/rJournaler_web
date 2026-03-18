<?php

declare(strict_types=1);

$requiredEnv = static function (string $key): string {
    $value = env($key);
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException('Missing required environment variable: ' . $key);
    }

    return $value;
};

return [
    'env' => env('APP_ENV', 'development'),
    'debug' => (bool) env('APP_DEBUG', true),
    'url' => (string) env('APP_URL', 'http://localhost:8080'),
    'version' => (string) env('APP_VERSION', '1.0.6'),
    'timezone' => (string) env('APP_TIMEZONE', 'UTC'),
    'session' => [
        'name' => (string) env('SESSION_NAME', 'rjournaler_session'),
        'lifetime_minutes' => (int) env('SESSION_LIFETIME_MINUTES', 120),
        'secure_cookie' => (bool) env('SESSION_SECURE_COOKIE', false),
        'samesite' => (string) env('SESSION_SAMESITE', 'Strict'),
    ],
    // 'security' config for TOTP and trusted device removed
    'database' => [
        'host' => $requiredEnv('DB_HOST'),
        'port' => (int) $requiredEnv('DB_PORT'),
        'name' => $requiredEnv('DB_NAME'),
        'user' => $requiredEnv('DB_USER'),
        'password' => $requiredEnv('DB_PASSWORD'),
        'charset' => $requiredEnv('DB_CHARSET'),
    ],
    'entry_uid' => [
        'app_version_code' => (string) env('ENTRY_UID_APP_VERSION_CODE', 'W010000'),
    ],
    'processing' => [
        'queue_complete_retention_hours' => (float) env('QUEUE_COMPLETE_RETENTION_HOURS', 168),
        'queue_failed_retention_hours' => (float) env('QUEUE_FAILED_RETENTION_HOURS', 168),
        'orchestrator_log_retention_hours' => (float) env('ORCHESTRATOR_LOG_RETENTION_HOURS', 8),
        'audit_log_retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 90),
        'auto_finish_hour_local' => (int) env('AUTO_FINISH_HOUR_LOCAL', 1),
        'ollama_url' => (string) env('OLLAMA_URL', 'http://10.0.0.65:11435'),
        'ollama_model' => (string) env('OLLAMA_MODEL', 'llama3.1:8b'),
        'ollama_retry_seconds' => (int) env('OLLAMA_RETRY_SECONDS', 120),
        'ollama_timeout_seconds' => (float) env('OLLAMA_TIMEOUT_SECONDS', 45),
        'optimus_max_autobots' => (int) env('OPTIMUS_MAX_AUTOBOTS', 4),
        'autobot_max_per_type_default' => (int) env('AUTOBOT_MAX_PER_TYPE_DEFAULT', 1),
        'autobot_idle_lifetime_minutes' => (float) env('AUTOBOT_IDLE_LIFETIME_MINUTES', 5),
        'autobot_limits' => [],
    ],
];
