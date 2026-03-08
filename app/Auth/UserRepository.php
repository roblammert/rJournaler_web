<?php

declare(strict_types=1);

namespace App\Auth;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findActiveByUsername(string $username): ?array
    {
        $themeColumnExists = false;
        try {
            $columnStmt = $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table_name
                   AND COLUMN_NAME = :column_name'
            );
            $columnStmt->execute([
                'table_name' => 'users',
                'column_name' => 'interface_theme',
            ]);
            $themeColumnExists = (int) $columnStmt->fetchColumn() > 0;
        } catch (\Throwable) {
            $themeColumnExists = false;
        }

        $themeSelect = $themeColumnExists
            ? 'interface_theme'
            : "'neutral' AS interface_theme";

        $sql = 'SELECT id, username, email, display_name, timezone_preference, ' . $themeSelect . ', password_hash, totp_secret_encrypted, is_active, is_admin FROM users WHERE username = :username LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();

        if (!is_array($row) || (int) ($row['is_active'] ?? 0) !== 1) {
            return null;
        }

        return $row;
    }
}
