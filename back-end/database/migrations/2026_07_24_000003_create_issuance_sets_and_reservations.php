<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('issuance_sets')) {
            Schema::create('issuance_sets', function (Blueprint $table) {
                $table->id('issuance_set_id');
                $table->unsignedBigInteger('issuance_id');
                $table->unsignedBigInteger('set_id');
                $table->integer('set_quantity')->default(1);
                $table->string('set_name_snapshot', 191);
                $table->boolean('was_modified')->default(false);
                $table->timestamps();

                $table->index('issuance_id');
                $table->index('set_id');

                // An order line group disappears with its order.
                $table->foreign('issuance_id')->references('issuance_id')->on('issuances')->cascadeOnDelete();
                // A set in use by an order must not vanish.
                $table->foreign('set_id')->references('set_id')->on('product_sets')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('pick_reservations')) {
            Schema::create('pick_reservations', function (Blueprint $table) {
                $table->id('reservation_id');
                $table->string('source_type', 20);
                $table->unsignedBigInteger('source_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('location_id');
                $table->unsignedBigInteger('batch_id')->nullable();
                $table->unsignedBigInteger('serial_id')->nullable();
                $table->integer('quantity');
                $table->dateTime('expires_at');
                $table->dateTime('released_at')->nullable();
                $table->timestamps();

                $table->index(['source_type', 'source_id'], 'pick_res_source_idx');
                $table->index(['product_id', 'location_id', 'released_at'], 'pick_res_avail_idx');

                $table->foreign('product_id')->references('product_id')->on('products')->restrictOnDelete();
                $table->foreign('location_id')->references('location_id')->on('locations')->restrictOnDelete();
                $table->foreign('batch_id')->references('batch_id')->on('inventory_batches')->nullOnDelete();
                $table->foreign('serial_id')->references('serial_id')->on('item_serial')->nullOnDelete();
            });
        }

        // Deferred FK from 2026_07_24_000002: issuance_details.issuance_set_id
        // needed issuance_sets to exist first. Removing a set group from an
        // order must delete its lines, not silently convert them into custom
        // lines, so this is cascade rather than set null.
        if (Schema::hasColumn('issuance_details', 'issuance_set_id')) {
            $fkExists = collect(DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'issuance_details'
                 AND COLUMN_NAME = 'issuance_set_id' AND REFERENCED_TABLE_NAME IS NOT NULL"
            ))->isNotEmpty();

            if (! $fkExists) {
                Schema::table('issuance_details', function (Blueprint $table) {
                    $table->foreign('issuance_set_id')->references('issuance_set_id')->on('issuance_sets')->cascadeOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('issuance_details', 'issuance_set_id')) {
            $fkExists = collect(DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'issuance_details'
                 AND COLUMN_NAME = 'issuance_set_id' AND REFERENCED_TABLE_NAME IS NOT NULL"
            ))->isNotEmpty();

            if ($fkExists) {
                Schema::table('issuance_details', function (Blueprint $table) {
                    $table->dropForeign(['issuance_set_id']);
                });
            }
        }

        Schema::dropIfExists('pick_reservations');
        Schema::dropIfExists('issuance_sets');
    }
};
