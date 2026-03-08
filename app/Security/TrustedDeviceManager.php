<?php

declare(strict_types=1);

namespace App\Security;

use PDO;

final class TrustedDeviceManager
{
    public function __construct(private readonly PDO $pdo, private readonly array $config)
    {
    }

    public function hasValidToken(int $userId): bool
    {
        $cookieValue = $_COOKIE['rj_trusted_device'] ?? null;
        if (!is_string($cookieValue) || $cookieValue === '') {
            return false;
        }

        $parts = explode(':', $cookieValue, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$cookieUserId, $token] = $parts;
        if ((int) $cookieUserId !== $userId || $token === '') {
            return false;
        }

        $tokenHash = hash('sha256', $token);
        $userAgentHash = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));

        $sql = <<<'SQL'
            SELECT id
            FROM trusted_devices
            WHERE user_id = :user_id
              AND token_hash = :token_hash
              AND user_agent_hash = :ua_hash
              AND expires_at > UTC_TIMESTAMP()
            LIMIT 1
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'ua_hash' => $userAgentHash,
        ]);

        $row = $stmt->fetch();
        if (!is_array($row)) {
            return false;
        }

        $touch = $this->pdo->prepare('UPDATE trusted_devices SET last_used_at = UTC_TIMESTAMP() WHERE id = :id');
        $touch->execute(['id' => $row['id']]);

        return true;
    }

    public function issueToken(int $userId, int $days): void
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $userAgentHash = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));

        $sql = <<<'SQL'
            INSERT INTO trusted_devices (user_id, token_hash, device_name, user_agent_hash, expires_at, last_used_at)
            VALUES (:user_id, :token_hash, :device_name, :ua_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL :days DAY), UTC_TIMESTAMP())
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':token_hash', $tokenHash);
        $stmt->bindValue(':device_name', 'Trusted Browser');
        $stmt->bindValue(':ua_hash', $userAgentHash);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();

        $cookieValue = $userId . ':' . $token;
        $expiresAt = time() + ($days * 86400);

        setcookie('rj_trusted_device', $cookieValue, [
            'expires' => $expiresAt,
            'path' => '/',
            'secure' => (bool) ($this->config['session']['secure_cookie'] ?? false),
            'httponly' => true,
            'samesite' => (string) ($this->config['session']['samesite'] ?? 'Strict'),
        ]);
    }
}
