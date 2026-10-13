<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->json('requested_products')->nullable();
            $table->unsignedBigInteger('continuation_of_transfer_id')->nullable()->index();
        });
        Schema::table('transfer_details', function (Blueprint $table) {
            $table->unsignedInteger('quantity_receipt_posted')->default(0);
        });
        Schema::table('barcode_scans', fn (Blueprint $table) => $table->boolean('receipt_posted')->default(false));
        // Existing receipts have already credited inventory and must not be posted again.
        DB::table('transfer_details')->whereIn('transfer_id', DB::table('transfers')
            ->whereIn('status_id', DB::table('status_lookup')->where('status_category', 'transfer')
                ->where('status_name', 'Received')->select('status_id'))->select('transfer_id'))
            ->update(['quantity_receipt_posted' => DB::raw('quantity_received')]);
        foreach (['Draft', 'Partial'] as $name) {
            DB::table('status_lookup')->updateOrInsert(
                ['status_category' => 'transfer', 'status_name' => $name],
                ['is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
            );
        }
        $receivedId = DB::table('status_lookup')->where('status_category', 'transfer')->where('status_name', 'Received')->value('status_id');
        $partialId = DB::table('status_lookup')->where('status_category', 'transfer')->where('status_name', 'Partial')->value('status_id');
        $receivedTransfers = DB::table('transfers')->where('status_id', $receivedId)->pluck('transfer_id');
        DB::table('barcode_scans')->whereIn('transfer_id', $receivedTransfers)->where('scan_mode', 'receive')->update(['receipt_posted' => true]);
        $shortReceipts = DB::table('transfer_details')->whereIn('transfer_id', $receivedTransfers)
            ->whereColumn('quantity_received', '<', 'quantity_requested')->pluck('transfer_id')->unique();
        DB::table('transfers')->whereIn('transfer_id', $shortReceipts)->update(['status_id' => $partialId]);
    }

    public function down(): void
    {
        Schema::table('barcode_scans', fn (Blueprint $table) => $table->dropColumn('receipt_posted'));
        Schema::table('transfer_details', fn (Blueprint $table) => $table->dropColumn('quantity_receipt_posted'));
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropIndex(['continuation_of_transfer_id']);
            $table->dropColumn(['requested_products', 'continuation_of_transfer_id']);
        });
    }
};
