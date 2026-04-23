<?php

declare(strict_types=1);

use SyncBridge\Database\Database;

if (!defined('SYNCBRIDGE_BOOTSTRAPPED')) {
    define('SYNCBRIDGE_BOOTSTRAPPED', true);

    require_once __DIR__ . '/../vendor/autoload.php';

    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->safeLoad();

    if (!empty($_ENV['APP_TIMEZONE'])) {
        @date_default_timezone_set((string) $_ENV['APP_TIMEZONE']);
    }

    try {
        $rows = Database::fetchAll('SELECT `key`, `value` FROM settings');
        foreach ($rows as $row) {
            $key = strtoupper((string) ($row['key'] ?? ''));
            $value = $row['value'] ?? null;
            if ($key === '' || $value === null || $value === '') {
                continue;
            }

            $_ENV[$key] = (string) $value;
            $_SERVER[$key] = (string) $value;
        }
    } catch (\Throwable) {
        // Database may not be available yet during install or first bootstrap.
    }
}
