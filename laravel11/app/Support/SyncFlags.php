<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Sync safety flags stored in the `settings` table (`dry_run`, `test_mode`).
 */
final class SyncFlags
{
    public const KEY_DRY_RUN = 'dry_run';

    public const KEY_TEST_MODE = 'test_mode';

    public static function dryRun(): bool
    {
        return self::readBooleanSetting(self::KEY_DRY_RUN, default: true);
    }

    public static function testMode(): bool
    {
        return self::readBooleanSetting(self::KEY_TEST_MODE, default: true);
    }

    /**
     * When the row is missing or unreadable, returns {@see $default} (conservative defaults are {@see true}).
     */
    private static function readBooleanSetting(string $key, bool $default): bool
    {
        try {
            $value = Setting::query()->find($key)?->value;
        } catch (\Throwable) {
            return $default;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
