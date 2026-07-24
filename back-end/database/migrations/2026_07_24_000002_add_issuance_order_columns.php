<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Note: the FK on issuance_details.issuance_set_id cannot be declared
        // here because issuance_sets does not exist yet. It is added in
        // 2026_07_24_000003_create_issuance_sets_and_reservations.php after
        // that table is created.
        Schema::table('issuance_details', function (Blueprint $table) {
            if (! Schema::hasColumn('issuance_details', 'issuance_set_id')) {
                $table->unsignedBigInteger('issuance_set_id')->nullable()->after('issuance_id');
            }
            if (! Schema::hasColumn('issuance_details', 'cost_price_at_issue')) {
                $table->decimal('cost_price_at_issue', 12, 2)->nullable()->after('unit_price');
            }
            if (! Schema::hasColumn('issuance_details', 'batch_id')) {
                $table->unsignedBigInteger('batch_id')->nullable()->after('receiving_id');
            }
            if (! Schema::hasColumn('issuance_details', 'zone_label')) {
                $table->string('zone_label', 100)->nullable()->after('batch_id');
            }
            if (! Schema::hasColumn('issuance_details', 'quantity_requested')) {
                $table->integer('quantity_requested')->default(0)->after('quantity_issued');
            }
            if (! Schema::hasColumn('issuance_details', 'quantity_short')) {
                $table->integer('quantity_short')->default(0)->after('quantity_requested');
            }
        });

        if (Schema::hasColumn('issuance_details', 'issuance_set_id')) {
            $indexExists = collect(DB::select("SHOW INDEX FROM issuance_details WHERE Key_name = 'issuance_details_issuance_set_id_index'"))->isNotEmpty();
            if (! $indexExists) {
                Schema::table('issuance_details', function (Blueprint $table) {
                    $table->index('issuance_set_id');
                });
            }
        }

        if (Schema::hasColumn('issuance_details', 'batch_id')) {
            $indexExists = collect(DB::select("SHOW INDEX FROM issuance_details WHERE Key_name = 'issuance_details_batch_id_index'"))->isNotEmpty();
            if (! $indexExists) {
                Schema::table('issuance_details', function (Blueprint $table) {
                    $table->index('batch_id');
                });
            }
        }

        $exists = DB::table('status_lookup')
            ->where('status_category', 'issuance')
            ->where('status_name', 'Draft')
            ->exists();

        if (! $exists) {
            DB::table('status_lookup')->insert([
                'status_name'     => 'Draft',
                'status_category' => 'issuance',
                'is_active'       => 1,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('issuance_details', function (Blueprint $table) {
            foreach (['issuance_set_id', 'cost_price_at_issue', 'batch_id', 'zone_label', 'quantity_requested', 'quantity_short'] as $col) {
                if (Schema::hasColumn('issuance_details', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        DB::table('status_lookup')
            ->where('status_category', 'issuance')
            ->where('status_name', 'Draft')
            ->delete();
    }
};
