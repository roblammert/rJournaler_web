<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';

use App\Core\Database;

$columnExists = static function (PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
};

$columnLength = static function (PDO $pdo, string $table, string $column): ?int {
    $stmt = $pdo->prepare(
        'SELECT CHARACTER_MAXIMUM_LENGTH
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name
         LIMIT 1'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    $value = $stmt->fetchColumn();
    if ($value === false || $value === null) {
        return null;
    }

    return (int) $value;
};

$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => $table]);

    return (int) $stmt->fetchColumn() > 0;
};

$shouldAutoMarkApplied = static function (PDO $pdo, string $migrationName) use ($columnExists, $tableExists, $columnLength): bool {
    if ($migrationName === '002_add_entry_uid.sql') {
        return $columnExists($pdo, 'journal_entries', 'entry_uid');
    }

    if ($migrationName === '003_entry_relations_use_uid.sql') {
        return $columnExists($pdo, 'entry_tags', 'entry_uid')
            && !$columnExists($pdo, 'entry_tags', 'entry_id')
            && $columnExists($pdo, 'entry_metrics', 'entry_uid')
            && !$columnExists($pdo, 'entry_metrics', 'entry_id');
    }

    if ($migrationName === '004_entry_workflow_pipeline.sql') {
        return $columnExists($pdo, 'journal_entries', 'workflow_stage')
            && $columnExists($pdo, 'journal_entries', 'body_locked')
            && $columnExists($pdo, 'worker_jobs', 'entry_uid')
            && $tableExists($pdo, 'entry_meta_group_0')
            && $tableExists($pdo, 'entry_meta_group_1')
            && $tableExists($pdo, 'entry_meta_group_2');
    }

    if ($migrationName === '005_user_admin_and_preferences.sql') {
        return $columnExists($pdo, 'users', 'display_name')
            && $columnExists($pdo, 'users', 'timezone_preference')
            && $columnExists($pdo, 'users', 'is_admin')
            && $tableExists($pdo, 'app_settings');
    }

    if ($migrationName === '008_import_uid_version_digits.sql') {
        return $columnExists($pdo, 'import_batches', 'uid_version_digits');
    }

    if ($migrationName === '009_import_uid_version_code_char7.sql') {
        $length = $columnLength($pdo, 'import_batches', 'uid_version_digits');
        return is_int($length) && $length >= 7;
    }

    if ($migrationName === '013_meta_group_3_weather.sql') {
        return $tableExists($pdo, 'entry_meta_group_3')
            && $columnExists($pdo, 'journal_entries', 'weather_location_key')
            && $columnExists($pdo, 'journal_entries', 'weather_location_json');
    }

    if ($migrationName === '014_user_interface_theme.sql') {
        return $columnExists($pdo, 'users', 'interface_theme');
    }

    return false;
};

$dryRun = in_array('--dry-run', $argv, true);
$target = null;
foreach ($argv as $arg) {
    if (str_starts_with((string) $arg, '--to=')) {
        $target = basename(substr((string) $arg, 5));
    }
}

$pdo = Database::connection($config['database']);
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        migration_name VARCHAR(255) NOT NULL UNIQUE,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$migrationDir = dirname(__DIR__) . '/sql/migrations';
$files = glob($migrationDir . '/*.sql');
if (!is_array($files)) {
    fwrite(STDERR, "Unable to read migration directory.\n");
    exit(1);
}

sort($files, SORT_STRING);

$appliedStmt = $pdo->query('SELECT migration_name FROM schema_migrations');
$appliedRows = $appliedStmt ? $appliedStmt->fetchAll(PDO::FETCH_COLUMN) : [];
$applied = array_fill_keys(array_map('strval', is_array($appliedRows) ? $appliedRows : []), true);

$pending = [];
foreach ($files as $filePath) {
    $name = basename($filePath);
    if (!isset($applied[$name])) {
        $pending[] = $filePath;
    }
    if ($target !== null && $name === $target) {
        break;
    }
}

if (count($pending) === 0) {
    fwrite(STDOUT, "No pending migrations.\n");
    exit(0);
}

fwrite(STDOUT, "Pending migrations:\n");
foreach ($pending as $filePath) {
    fwrite(STDOUT, ' - ' . basename($filePath) . "\n");
}

if ($dryRun) {
    fwrite(STDOUT, "Dry run complete.\n");
    exit(0);
}

foreach ($pending as $filePath) {
    $name = basename($filePath);

    if ($shouldAutoMarkApplied($pdo, $name)) {
        fwrite(STDOUT, "Marking already-applied migration: {$name}\n");
        $insert = $pdo->prepare('INSERT INTO schema_migrations (migration_name) VALUES (:name)');
        $insert->execute(['name' => $name]);
        continue;
    }

    $sql = file_get_contents($filePath);
    if (!is_string($sql)) {
        throw new RuntimeException('Unable to read migration file: ' . $name);
    }

    fwrite(STDOUT, "Applying: {$name}\n");
    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $insert = $pdo->prepare('INSERT INTO schema_migrations (migration_name) VALUES (:name)');
        $insert->execute(['name' => $name]);

        // Some DDL statements (ALTER TABLE, CREATE INDEX, etc.) can cause implicit commits.
        // Only commit when a transaction is still active.
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "Failed migration {$name}: " . $throwable->getMessage() . "\n");
        exit(1);
    }
}

fwrite(STDOUT, "Migrations applied successfully.\n");
