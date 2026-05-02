<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::table('status_lookup')
            ->where('status_category', 'purchase_order')
            ->pluck('status_name')
            ->map(fn ($n) => strtolower(trim($n)))
            ->toArray();

        $toInsert = [
            ['status_name' => 'Completed',           'status_category' => 'purchase_order'],
            ['status_name' => 'Partially Received',  'status_category' => 'purchase_order'],
        ];

        foreach ($toInsert as $row) {
            if (! in_array(strtolower($row['status_name']), $existing, true)) {
                DB::table('status_lookup')->insert(array_merge($row, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }

    public function down(): void
    {
        DB::table('status_lookup')
            ->where('status_category', 'purchase_order')
            ->whereIn('status_name', ['Completed', 'Partially Received'])
            ->delete();
    }
};
