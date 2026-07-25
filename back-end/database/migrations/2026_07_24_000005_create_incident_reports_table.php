<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('incident_reports')) {
            Schema::create('incident_reports', function (Blueprint $table) {
                $table->id('incident_id');
                $table->string('incident_number', 40)->unique();
                $table->string('source_type', 20);            // issuance | transfer
                $table->unsignedBigInteger('source_id');
                $table->unsignedBigInteger('location_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('batch_id')->nullable();
                $table->unsignedBigInteger('serial_id')->nullable();
                $table->string('expected_zone', 100)->nullable();
                $table->string('actual_zone', 100)->nullable();
                $table->string('reason', 30);                 // not_found | damaged | wrong_type | zone_mismatch
                $table->integer('quantity')->default(0);
                $table->text('narrative')->nullable();
                $table->string('photo_path', 255)->nullable();
                $table->unsignedBigInteger('reported_by');
                $table->unsignedBigInteger('status_id');
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->dateTime('reviewed_at')->nullable();
                $table->unsignedBigInteger('adjustment_id')->nullable();
                $table->unsignedBigInteger('correction_id')->nullable();
                $table->text('resolution_note')->nullable();
                $table->timestamps();

                $table->index(['source_type', 'source_id'], 'incident_source_idx');
                $table->index(['location_id', 'status_id'], 'incident_loc_status_idx');
                $table->index(['batch_id', 'status_id'], 'incident_batch_status_idx');
                $table->index('reported_by');

                $table->foreign('location_id')->references('location_id')->on('locations')->restrictOnDelete();
                $table->foreign('product_id')->references('product_id')->on('products')->restrictOnDelete();
                $table->foreign('batch_id')->references('batch_id')->on('inventory_batches')->nullOnDelete();
                $table->foreign('serial_id')->references('serial_id')->on('item_serial')->nullOnDelete();
                $table->foreign('reported_by')->references('user_id')->on('users')->restrictOnDelete();
                $table->foreign('status_id')->references('status_id')->on('status_lookup')->restrictOnDelete();
                $table->foreign('reviewed_by')->references('user_id')->on('users')->nullOnDelete();
                $table->foreign('adjustment_id')->references('adjustment_id')->on('adjustments')->nullOnDelete();
                $table->foreign('correction_id')->references('correction_id')->on('scan_corrections')->nullOnDelete();
            });
        }

        foreach (['Open', 'Resolved', 'Dismissed'] as $name) {
            $exists = DB::table('status_lookup')
                ->where('status_category', 'incident')
                ->where('status_name', $name)
                ->exists();

            if (! $exists) {
                DB::table('status_lookup')->insert([
                    'status_name'     => $name,
                    'status_category' => 'incident',
                    'is_active'       => 1,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_reports');
        DB::table('status_lookup')->where('status_category', 'incident')->delete();
    }
};
