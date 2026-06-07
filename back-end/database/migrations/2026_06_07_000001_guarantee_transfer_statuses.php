<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['In Transit', 'Received'] as $name) {
            $exists = DB::table('status_lookup')
                ->where('status_name', $name)
                ->where('status_category', 'transfer')
                ->exists();
            if (!$exists) {
                DB::table('status_lookup')->insert([
                    'status_name'     => $name,
                    'status_category' => 'transfer',
                    'is_active'       => 1,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('status_lookup')
            ->where('status_category', 'transfer')
            ->whereIn('status_name', ['In Transit', 'Received'])
            ->delete();
    }
};
