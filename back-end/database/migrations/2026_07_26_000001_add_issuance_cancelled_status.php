<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('status_lookup')
            ->where('status_category', 'issuance')
            ->where('status_name', 'Cancelled')
            ->exists();

        if (! $exists) {
            DB::table('status_lookup')->insert([
                'status_name'     => 'Cancelled',
                'status_category' => 'issuance',
                'is_active'       => 1,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('status_lookup')
            ->where('status_category', 'issuance')
            ->where('status_name', 'Cancelled')
            ->delete();
    }
};
