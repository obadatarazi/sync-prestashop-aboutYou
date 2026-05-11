<?php

namespace App\Services;

use App\Models\Setting;

class SettingsService
{
    public function get(string $key, mixed $default = null): mixed
    {
        $row = Setting::query()->find($key);
        return $row?->value ?? $default;
    }

    public function setMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => is_scalar($value) ? (string) $value : json_encode($value)]);
        }
    }
}
