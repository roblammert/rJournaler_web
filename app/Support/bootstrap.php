<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = dirname(__DIR__) . DIRECTORY_SEPARATOR;

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

$config = require dirname(__DIR__, 2) . '/config/app.php';

try {
    $pdo = \App\Core\Database::connection($config['database']);
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM app_settings');
    $settings = $stmt->fetchAll();
    foreach ($settings as $setting) {
        if (!is_array($setting)) {
            continue;
        }
        $key = (string) ($setting['setting_key'] ?? '');
        $value = (string) ($setting['setting_value'] ?? '');
        if ($key === '') {
            continue;
        }

        switch ($key) {
            case 'security.trusted_device_days':
                $config['security']['trusted_device_days'] = max(1, (int) $value);
                break;
            case 'processing.queue_complete_retention_hours':
                $config['processing']['queue_complete_retention_hours'] = max(1.0, (float) $value);
                break;
            case 'processing.queue_failed_retention_hours':
                $config['processing']['queue_failed_retention_hours'] = max(1.0, (float) $value);
                break;
            case 'processing.orchestrator_log_retention_hours':
                $config['processing']['orchestrator_log_retention_hours'] = max(0.25, (float) $value);
                break;
            case 'processing.audit_log_retention_days':
                $config['processing']['audit_log_retention_days'] = max(1, (int) $value);
                break;
            case 'processing.auto_finish_hour_local':
                $parsed = (int) $value;
                $config['processing']['auto_finish_hour_local'] = max(0, min(23, $parsed));
                break;
            case 'processing.ollama_url':
                if (trim($value) !== '') {
                    $config['processing']['ollama_url'] = trim($value);
                }
                break;
            case 'processing.ollama_model':
                if (trim($value) !== '') {
                    $config['processing']['ollama_model'] = trim($value);
                }
                break;
            case 'processing.ollama_retry_seconds':
                $config['processing']['ollama_retry_seconds'] = max(10, (int) $value);
                break;
            case 'processing.ollama_timeout_seconds':
                $config['processing']['ollama_timeout_seconds'] = max(1.0, (float) $value);
                break;
            case 'processing.optimus_max_autobots':
                $config['processing']['optimus_max_autobots'] = max(1, (int) $value);
                break;
            case 'processing.autobot_max_per_type_default':
                $config['processing']['autobot_max_per_type_default'] = max(1, (int) $value);
                break;
            case 'processing.autobot_idle_lifetime_minutes':
                $config['processing']['autobot_idle_lifetime_minutes'] = max(0.5, (float) $value);
                break;
        }

        if (str_starts_with($key, 'processing.autobot_limit.')) {
            $stageId = trim(substr($key, strlen('processing.autobot_limit.')));
            if ($stageId !== '') {
                if (!is_array($config['processing']['autobot_limits'] ?? null)) {
                    $config['processing']['autobot_limits'] = [];
                }
                $config['processing']['autobot_limits'][$stageId] = max(1, (int) $value);
            }
        }
    }
} catch (\Throwable $throwable) {
    // App settings table is optional; ignore bootstrap override failures.
}

date_default_timezone_set($config['timezone']);

if (PHP_SAPI === 'cli') {
    return;
}

session_name($config['session']['name']);
session_set_cookie_params([
    'lifetime' => $config['session']['lifetime_minutes'] * 60,
    'path' => '/',
    'httponly' => true,
    'secure' => $config['session']['secure_cookie'],
    'samesite' => $config['session']['samesite'],
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Keep all interface time rendering pinned to a single timezone.
date_default_timezone_set('America/Chicago');
