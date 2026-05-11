<?php

namespace App\Repositories;

use App\Support\Database as BaseDatabase;

final class Database
{
    public static function __callStatic(string $name, array $arguments): mixed
    {
        return BaseDatabase::{$name}(...$arguments);
    }
}
