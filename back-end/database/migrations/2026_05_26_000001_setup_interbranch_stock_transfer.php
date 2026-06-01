<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // STEP 1 — Transfer-specific status rows (idempotent)
        $statuses = ['Pending', 'Approved', 'Rejected', 'Partially Received'];
        foreach ($statuses as $name) {
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

        // STEP 2 — Extend transfers
        Schema::table('transfers', function (Blueprint $table) {
            $table->unsignedBigInteger('transfer_request_id')->nullable()
                ->after('transfer_id')
                ->comment('NULL = this is a request. Non-null = this is the IST linked to the request.');
            $table->unsignedBigInteger('prepared_by')->nullable()->after('received_by');
            $table->text('notes')->nullable()->after('total_quantity_received');

            $table->foreign('transfer_request_id')
                ->references('transfer_id')->on('transfers')
                ->onDelete('set null');
        });

        // STEP 3 — Extend transfer_details
        Schema::table('transfer_details', function (Blueprint $table) {
            $table->integer('quantity_requested')->notNull()->default(0)->after('product_id');
            $table->integer('quantity_scanned')->notNull()->default(0)->after('quantity_transferred');
        });

        // STEP 4 — Link item_serial to transfers
        Schema::table('item_serial', function (Blueprint $table) {
            $table->unsignedBigInteger('transfer_id')->nullable()->after('receiving_id');
            $table->foreign('transfer_id', 'fk_item_serial_transfer')
                ->references('transfer_id')->on('transfers')
                ->onDelete('set null');
        });

        // STEP 5 — Link barcode_scans to transfers
        Schema::table('barcode_scans', function (Blueprint $table) {
            $table->unsignedBigInteger('transfer_id')->nullable()->after('product_id');
            $table->foreign('transfer_id', 'fk_barcode_scans_transfer')
                ->references('transfer_id')->on('transfers')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('barcode_scans', function (Blueprint $table) {
            $table->dropForeign('fk_barcode_scans_transfer');
            $table->dropColumn('transfer_id');
        });

        Schema::table('item_serial', function (Blueprint $table) {
            $table->dropForeign('fk_item_serial_transfer');
            $table->dropColumn('transfer_id');
        });

        Schema::table('transfer_details', function (Blueprint $table) {
            $table->dropColumn(['quantity_requested', 'quantity_scanned']);
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropForeign(['transfer_request_id']);
            $table->dropColumn(['transfer_request_id', 'prepared_by', 'notes']);
        });

        DB::table('status_lookup')
            ->where('status_category', 'transfer')
            ->whereIn('status_name', ['Pending', 'Approved', 'Rejected', 'Partially Received'])
            ->delete();
    }
};
