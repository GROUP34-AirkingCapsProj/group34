<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['Pending', 'Completed'] as $name) {
            DB::table('status_lookup')->updateOrInsert(
                ['status_category' => 'warranty_claim', 'status_name' => $name],
                ['is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('status_lookup')
            ->where('status_category', 'warranty_claim')
            ->whereIn('status_name', ['Pending', 'Completed'])
            ->delete();
    }
};
