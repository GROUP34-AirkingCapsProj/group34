<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'products',
            'locations',
            'users',
            'model_lookup',
            'unit_lookup',
            'purchase_orders',
            'purchase_requests',
            'transfers',
            'adjustments',
            'issuances',
            'budget_allocations',
        ];

        foreach ($tables as $table) {
            if (
                Schema::hasTable($table) &&
                ! Schema::hasColumn($table, 'deleted_at')
            ) {
                Schema::table($table, function (Blueprint $t) {
                    $t->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        $tables = [
            'products',
            'locations',
            'users',
            'model_lookup',
            'unit_lookup',
            'purchase_orders',
            'purchase_requests',
            'transfers',
            'adjustments',
            'issuances',
            'budget_allocations',
        ];

        foreach ($tables as $table) {
            if (
                Schema::hasTable($table) &&
                Schema::hasColumn($table, 'deleted_at')
            ) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropSoftDeletes();
                });
            }
        }
    }
};
