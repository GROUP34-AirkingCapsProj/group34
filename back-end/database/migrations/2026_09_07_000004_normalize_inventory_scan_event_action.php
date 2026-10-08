<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settle inventory_scan_events.action on one vocabulary.
 *
 * Both readers of the table filter on action = 'applied':
 * InventoryScanEventController::index (the /api/scan-events feed) and the
 * barcode-history query in InventoryScanTransactionController. Every writer
 * used 'applied' too — except IssuanceScanController, which wrote 'accepted',
 * so the events it logged were invisible to both readers while the scan
 * response itself reported "action": "applied". The writer is fixed; this
 * migration repairs the rows it already wrote and corrects the column comment,
 * which still advertised the value that never worked.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_scan_events')) {
            return;
        }

        DB::table('inventory_scan_events')
            ->where('action', 'accepted')
            ->update(['action' => 'applied']);

        DB::statement(
            "ALTER TABLE `inventory_scan_events`
             MODIFY `action` VARCHAR(16) NOT NULL COMMENT 'applied | rejected'"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('inventory_scan_events')) {
            return;
        }

        // Only the comment is restored. The rows are deliberately left alone:
        // 'applied' was already the correct value for every writer other than
        // IssuanceScanController, and nothing in the row distinguishes an event
        // this migration rewrote from one that was always 'applied'. Mapping
        // them back by transaction_type would corrupt the issuance events that
        // InventoryScanTransactionController logged correctly all along.
        DB::statement(
            "ALTER TABLE `inventory_scan_events`
             MODIFY `action` VARCHAR(16) NOT NULL COMMENT 'accepted | rejected'"
        );
    }
};
