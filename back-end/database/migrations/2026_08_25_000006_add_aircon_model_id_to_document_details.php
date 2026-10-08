<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tags a document line with the aircon model it belongs to, so a purchase
     * order, issuance or receipt for three split aircons can render as one
     * header line — "TCL 2HP Split x 3" — that opens into its indoor and
     * outdoor sub-lines, each with its own quantity and serials.
     *
     * WHY A PLAIN NULLABLE FK AND NOT A PARENT TABLE WITH A STORED QUANTITY
     *
     * The header count is derived, never stored. Three aircons is an indoor
     * line of 3 and an outdoor line of 3; the header reads 3 because
     * 3 / 1-indoor-per-model = 3. Short deliveries are normal here: when the
     * outdoor line is received at 2, the group is not invalid — it reports 2
     * complete aircons and 1 unpaired indoor. A stored parent quantity would
     * have to either misreport that state or refuse to record it, so there is
     * no parent row to store it on. The grouping is derived from these tagged
     * lines at read time (Task 13).
     *
     * WHY receiving_details CARRIES ITS OWN COLUMN INSTEAD OF READING THROUGH
     * po_detail_id
     *
     * Because a receiving line does not always have a purchase-order line
     * behind it. receiving_details.po_detail_id is nullable, and both writers
     * leave it null on the scan-driven path:
     *
     *   - Api\InventoryScanTransactionController::store() resolves $poDetail only
     *     inside `if ($poId)`. A scan with no PO selected writes
     *     'po_detail_id' => $poDetail?->po_detail_id, i.e. null.
     *   - Api\ReceivingController::store() validates 'details.*.po_detail_id' as
     *     nullable and writes `$detail['po_detail_id'] ?? null`.
     *
     * Those PO-less receipts still need to group into aircon models, so the
     * tag has to live on the receiving line itself.
     *
     * All three columns are nullable with no backfill: every existing purchase
     * order, issuance and receipt line stays exactly as it is, untagged, and
     * reads as an ordinary standalone line.
     */
    private const TABLES = [
        'purchase_order_details',
        'issuance_details',
        'receiving_details',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'aircon_model_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                // All three tables have a product_id; the tag reads naturally
                // next to the product it qualifies.
                $t->unsignedBigInteger('aircon_model_id')->nullable()->after('product_id');

                // nullOnDelete, never cascade: archiving or deleting an aircon
                // model must not take purchase-order, issuance or receiving
                // history with it. The line survives, untagged.
                $t->foreign('aircon_model_id')
                    ->references('aircon_model_id')
                    ->on('aircon_models')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'aircon_model_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropForeign("{$table}_aircon_model_id_foreign");
                $t->dropColumn('aircon_model_id');
            });
        }
    }
};
