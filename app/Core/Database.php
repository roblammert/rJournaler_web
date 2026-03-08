<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(array $databaseConfig): PDO
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new PDOException('Database connection failed: pdo_mysql extension is not loaded. Enable extension=pdo_mysql in php.ini.');
        }

        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $databaseConfig['host'],
            $databaseConfig['port'],
            $databaseConfig['name'],
            $databaseConfig['charset']
        );

        try {
            self::$pdo = new PDO(
                $dsn,
                $databaseConfig['user'],
                $databaseConfig['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $exception) {
            throw new PDOException('Database connection failed: ' . $exception->getMessage(), (int) $exception->getCode());
        }

        return self::$pdo;
    }
}
