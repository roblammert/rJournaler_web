<?php

declare(strict_types=1);

namespace App\Auth;

use PDO;

final class LoginService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isRateLimited(string $username, string $ipAddress): bool
    {
        $windowSql = <<<'SQL'
            SELECT COUNT(*) AS fail_count
            FROM auth_attempts
            WHERE was_successful = 0
              AND attempted_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)
              AND (ip_address = :ip OR username = :username)
        SQL;

        $stmt = $this->pdo->prepare($windowSql);
        $stmt->execute([
            'ip' => $ipAddress,
            'username' => $username,
        ]);

        $row = $stmt->fetch();
        $failCount = (int) ($row['fail_count'] ?? 0);

        return $failCount >= 10;
    }

    public function recordAttempt(?string $username, string $ipAddress, bool $wasSuccessful): void
    {
        $sql = 'INSERT INTO auth_attempts (username, ip_address, was_successful) VALUES (:username, :ip, :ok)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'username' => $username,
            'ip' => $ipAddress,
            'ok' => $wasSuccessful ? 1 : 0,
        ]);
    }
}
