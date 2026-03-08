<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Auth/require_auth.php';
require_once dirname(__DIR__, 2) . '/app/Auth/require_admin.php';

use App\Core\Database;

header('Content-Type: application/json; charset=utf-8');

$sinceId = max(0, (int) ($_GET['since_id'] ?? 0));
$limit = max(25, min(500, (int) ($_GET['limit'] ?? 200)));
$retentionHours = max(0.25, (float) ($config['processing']['orchestrator_log_retention_hours'] ?? 8));
$retentionSeconds = max(1, (int) round($retentionHours * 3600));
$cutoffUtc = gmdate('Y-m-d H:i:s', time() - $retentionSeconds);

try {
    $pdo = Database::connection($config['database']);

    $stmt = $pdo->prepare(
        'SELECT id, level, source, message, context_json, created_at
         FROM orchestrator_logs
                 WHERE is_old = 0
                     AND created_at >= :cutoff_utc
                     AND id > :since_id
         ORDER BY id ASC
         LIMIT :limit'
    );
    $stmt->bindValue(':cutoff_utc', $cutoffUtc, PDO::PARAM_STR);
    $stmt->bindValue(':since_id', $sinceId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as &$row) {
        if (!is_array($row)) {
            continue;
        }
        $contextRaw = $row['context_json'] ?? null;
        if (is_string($contextRaw) && trim($contextRaw) !== '') {
            $decoded = json_decode($contextRaw, true);
            $row['context_json'] = is_array($decoded) ? $decoded : null;
        } else {
            $row['context_json'] = null;
        }
    }
    unset($row);

    $lastId = $sinceId;
    if (count($rows) > 0) {
        $last = $rows[count($rows) - 1];
        if (is_array($last)) {
            $lastId = (int) ($last['id'] ?? $sinceId);
        }
    }

    echo json_encode([
        'ok' => true,
        'rows' => $rows,
        'last_id' => $lastId,
        'generated_at' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to load Optimus logs',
    ]);
}
