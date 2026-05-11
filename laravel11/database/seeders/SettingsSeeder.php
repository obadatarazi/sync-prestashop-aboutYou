<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Persisted integration, safety, and feature toggles for the sync bridge.
 *
 * @see \App\Support\SyncFlags Safety flags use settings keys `dry_run` and `test_mode`.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['key' => 'dry_run', 'value' => 'true', 'type' => 'boolean', 'label' => 'Dry run (no DB / PrestaShop / AY writes)', 'group_name' => 'safety'],
            ['key' => 'test_mode', 'value' => 'true', 'type' => 'boolean', 'label' => 'Test mode (skip About You mutating API calls)', 'group_name' => 'safety'],
            ['key' => 'ps_base_url', 'value' => '', 'type' => 'string', 'label' => 'PrestaShop Base URL', 'group_name' => 'prestashop'],
            ['key' => 'ps_api_key', 'value' => '', 'type' => 'password', 'label' => 'PrestaShop API Key', 'group_name' => 'prestashop'],
            ['key' => 'ay_base_url', 'value' => 'https://partner.aboutyou.com/api/v1', 'type' => 'string', 'label' => 'AY Base URL', 'group_name' => 'aboutyou'],
            ['key' => 'ay_api_key', 'value' => '', 'type' => 'password', 'label' => 'AboutYou API Key', 'group_name' => 'aboutyou'],
            ['key' => 'ay_brand_id', 'value' => '0', 'type' => 'integer', 'label' => 'Brand ID (About You numeric brand id)', 'group_name' => 'aboutyou'],
            ['key' => 'sync_batch_size', 'value' => '50', 'type' => 'integer', 'label' => 'Batch Size', 'group_name' => 'sync'],
            ['key' => 'sync_incremental', 'value' => 'true', 'type' => 'boolean', 'label' => 'Incremental Sync', 'group_name' => 'sync'],
            ['key' => 'image_normalize_enabled', 'value' => 'true', 'type' => 'boolean', 'label' => 'Image Normalization', 'group_name' => 'images'],
            ['key' => 'feature_sync_metrics', 'value' => 'true', 'type' => 'boolean', 'label' => 'Feature: sync metrics storage', 'group_name' => 'features'],
            ['key' => 'feature_idempotent_status_push', 'value' => 'true', 'type' => 'boolean', 'label' => 'Feature: idempotent status push', 'group_name' => 'features'],
        ];

        foreach ($rows as $row) {
            Setting::query()->updateOrCreate(['key' => $row['key']], $row);
        }
    }
}
