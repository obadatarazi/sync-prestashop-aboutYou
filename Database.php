<?php

namespace SyncBridge\Database;

use PDO;
use PDOException;

/**
 * Database – thin PDO wrapper.
 * Connection is lazy: only opened on first use.
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function connect(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $host   = $_ENV['DB_HOST']     ?? '127.0.0.1';
        $port   = $_ENV['DB_PORT']     ?? '3306';
        $name   = $_ENV['DB_NAME']     ?? 'syncbridge';
        $user   = $_ENV['DB_USER']     ?? 'root';
        $pass   = $_ENV['DB_PASSWORD'] ?? '';
        $charset = 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // Set charset
            self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (PDOException $e) {
            throw new \RuntimeException('DB connection failed: ' . $e->getMessage());
        }

        return self::$pdo;
    }

    public static function pdo(): PDO
    {
        return self::connect();
    }

    /** Run a query and return all rows. */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Run a query and return the first row or null. */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Run a query and return a single scalar value. */
    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $val = $stmt->fetchColumn();
        return $val === false ? null : $val;
    }

    /** Execute INSERT/UPDATE/DELETE, return affected rows. */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** INSERT a row, return last insert ID. */
    public static function insert(string $table, array $data): int
    {
        $cols   = implode(', ', array_keys($data));
        $places = implode(', ', array_fill(0, count($data), '?'));
        $sql    = "INSERT INTO `{$table}` ({$cols}) VALUES ({$places})";
        self::execute($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** INSERT … ON DUPLICATE KEY UPDATE */
    public static function upsert(string $table, array $data, array $updateKeys): int
    {
        $cols    = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($data)));
        $places  = implode(', ', array_fill(0, count($data), '?'));
        $updates = implode(', ', array_map(fn($k) => "`{$k}` = VALUES(`{$k}`)", $updateKeys));
        $sql     = "INSERT INTO `{$table}` ({$cols}) VALUES ({$places}) ON DUPLICATE KEY UPDATE {$updates}";
        self::execute($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function beginTransaction(): void  { self::pdo()->beginTransaction(); }
    public static function commit(): void            { self::pdo()->commit(); }
    public static function rollback(): void          { self::pdo()->rollBack(); }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }
}
