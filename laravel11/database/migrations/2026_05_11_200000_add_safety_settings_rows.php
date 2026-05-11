<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $rows = [
            [
                'key' => 'dry_run',
                'value' => 'true',
                'type' => 'boolean',
                'label' => 'Dry run (no DB / PrestaShop / AY writes)',
                'group_name' => 'safety',
                'updated_at' => $now,
            ],
            [
                'key' => 'test_mode',
                'value' => 'true',
                'type' => 'boolean',
                'label' => 'Test mode (skip About You mutating API calls)',
                'group_name' => 'safety',
                'updated_at' => $now,
            ],
        ];

        foreach ($rows as $row) {
            if (!DB::table('settings')->where('key', $row['key'])->exists()) {
                DB::table('settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['dry_run', 'test_mode'])->delete();
    }
};
