<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $row = DB::table('settings')->where('key', 'ay_brand_id')->first();
        if ($row === null) {
            return;
        }

        $value = trim((string) ($row->value ?? ''));
        if ($value === '' || !ctype_digit($value)) {
            $value = '0';
        }

        DB::table('settings')->where('key', 'ay_brand_id')->update([
            'type' => 'integer',
            'value' => $value,
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'ay_brand_id')->update([
            'type' => 'string',
        ]);
    }
};
