<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class Database
{
    public static function insert(string $table, array $data): int
    {
        DB::table($table)->insert($data);
        return (int) DB::getPdo()->lastInsertId();
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return array_map(static fn (object $row): array => (array) $row, DB::select($sql, $params));
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = DB::selectOne($sql, $params);
        return $row ? (array) $row : null;
    }

    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $row = self::fetchOne($sql, $params);
        return $row ? reset($row) : null;
    }

    public static function execute(string $sql, array $params = []): int
    {
        return DB::affectingStatement($sql, $params);
    }

    public static function lastInsertId(): int
    {
        return (int) DB::getPdo()->lastInsertId();
    }
}
