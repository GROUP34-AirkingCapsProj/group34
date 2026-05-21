<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_records', function (Blueprint $table) {
            if (! Schema::hasColumn('warranty_records', 'warranty_duration_label')) {
                $table->string('warranty_duration_label', 30)
                      ->nullable()
                      ->after('warranty_period_months')
                      ->comment('e.g. 2 Years, 6 Months, No Warranty');
            }
            if (! Schema::hasColumn('warranty_records', 'claim_date')) {
                $table->date('claim_date')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('warranty_records', 'claimed_by')) {
                $table->unsignedBigInteger('claimed_by')->nullable()->after('claim_date');
                $table->foreign('claimed_by')
                      ->references('user_id')->on('users')
                      ->onDelete('set null');
            }
            if (! Schema::hasColumn('warranty_records', 'claim_notes')) {
                $table->text('claim_notes')->nullable()->after('claimed_by');
            }
        });

        // Add serial_number index for fast lookup if not already present
        $indexes = collect(DB::select("SHOW INDEX FROM warranty_records"))
                      ->pluck('Key_name')->toArray();
        if (! in_array('warranty_records_serial_number_index', $indexes)) {
            Schema::table('warranty_records', function (Blueprint $table) {
                $table->index('serial_number');
            });
        }
    }

    public function down(): void
    {
        Schema::table('warranty_records', function (Blueprint $table) {
            $table->dropForeign(['claimed_by']);
            $table->dropIndex('warranty_records_serial_number_index');
            $table->dropColumn([
                'warranty_duration_label',
                'claim_date',
                'claimed_by',
                'claim_notes',
            ]);
        });
    }
};
