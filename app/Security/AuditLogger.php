<?php

declare(strict_types=1);

namespace App\Security;

use PDO;

final class AuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function log(?int $userId, string $eventType, array $eventData = []): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (user_id, event_type, event_data_json, ip_address, user_agent) VALUES (:user_id, :event_type, :event_data_json, :ip_address, :user_agent)'
        );

        $payload = json_encode($eventData, JSON_UNESCAPED_SLASHES);
        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        $stmt->execute([
            'user_id' => $userId,
            'event_type' => $eventType,
            'event_data_json' => is_string($payload) ? $payload : '{}',
            'ip_address' => $ipAddress !== '' ? $ipAddress : null,
            'user_agent' => $userAgent !== '' ? substr($userAgent, 0, 255) : null,
        ]);
    }
}
