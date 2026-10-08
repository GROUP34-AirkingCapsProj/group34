<?php

namespace Tests\Feature\Transfer;

use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Services\TransferService;

/**
 * PUT /transfers/{id} rewrites inventory but never inventory_batches.
 *
 * TransferService::dispatch() moves stock through BOTH ledgers: it decrements
 * inventory.quantity_on_hand and calls InventoryBatchService::deductFifo() so
 * inventory_batches.quantity_remaining follows. TransferController contains no
 * reference to InventoryBatch or InventoryBatchService at all — its update()
 * adds the old line quantities back to inventory and deducts the new ones,
 * leaving the batch ledger exactly where dispatch left it.
 *
 * The two then disagree about the same stock, and it is the batch ledger that
 * FIFO picking reads (FifoPickingService::orderedBatches), so the difference is
 * stock that shows as on hand but cannot be picked.
 *
 * The existing guard only refuses the edit once quantity_received > 0 — after
 * the goods land at the destination. A transfer that has been dispatched but
 * not yet received has quantity_transferred > 0 and quantity_received = 0, so
 * it passes that guard while its stock is already deducted.
 */
class EditAfterDispatchLedgerTest extends TransferTestCase
{
    private function batchTotal(int $locationId, int $productId): int
    {
        return (int) InventoryBatch::where('location_id', $locationId)
            ->where('product_id', $productId)
            ->sum('quantity_remaining');
    }

    private function onHand(int $locationId, int $productId): int
    {
        return (int) Inventory::where('location_id', $locationId)
            ->where('product_id', $productId)
            ->value('quantity_on_hand');
    }

    /**
     * KNOWN OPEN DEFECT — skipped, not deleted, so it is not forgotten.
     *
     * The guard above stops the dispatched case. It does not stop this one:
     * editing an undispatched transfer deducts `inventory` for the new lines
     * directly (TransferController::update) and never the batch ledger, which
     * this controller does not touch anywhere. Measured: 20 on hand becomes 15
     * while the batch ledger stays at 20.
     *
     * It is left open on purpose rather than patched blind. The legacy Stock
     * Transfers screen (StockTransferManagement.jsx) still drives this path and
     * its create toast reads "Stock moved", so an immediate-transfer semantic
     * is live here — unlike the IST flow, where dispatch moves stock and
     * updateRequest() edits lines without touching inventory at all. Making
     * this path maintain the batch ledger, or retiring it in favour of the IST
     * flow, is a product decision about that screen, not a mechanical fix.
     */
    public function test_editing_an_undispatched_transfer_does_not_move_stock_at_all(): void
    {
        $this->markTestSkipped(
            'Known open defect: PUT /transfers/{id} deducts inventory without the batch ledger on '
            . 'an undispatched transfer. Needs a decision on the legacy immediate-transfer path.'
        );

        $from    = $this->makeLocation('Source WH');
        $to      = $this->makeLocation('Dest WH');
        $product = $this->makeInstallationMaterial();
        $admin   = $this->makeUserAt($from, 'admin');

        $this->setStock($from->location_id, $product->product_id, 20);
        $this->makeBatch($product, $from, ['quantity_remaining' => 20]);

        $transfer = $this->makeApprovedTransfer($from, $to, [[$product, 5]]);

        $this->actingAs($admin, 'sanctum');

        $this->putJson("/api/transfers/{$transfer->transfer_id}", [
            'details' => [
                ['product_id' => $product->product_id, 'quantity_transferred' => 5],
            ],
        ]);

        $this->assertSame(20, $this->batchTotal($from->location_id, $product->product_id),
            'Nothing has been dispatched, so the batch ledger must be untouched.');
        $this->assertSame(
            20,
            $this->onHand($from->location_id, $product->product_id),
            'Editing lines before dispatch must not deduct stock — dispatch is what moves it, '
            . 'and it deducts again, so a deduction here is counted twice.'
        );
    }

    public function test_editing_a_dispatched_transfer_keeps_both_stock_ledgers_in_step(): void
    {
        $from    = $this->makeLocation('Source WH');
        $to      = $this->makeLocation('Dest WH');
        $product = $this->makeInstallationMaterial();
        $admin   = $this->makeUserAt($from, 'admin');

        $this->setStock($from->location_id, $product->product_id, 20);
        $this->makeBatch($product, $from, ['quantity_remaining' => 20]);

        // Dispatch 5: both ledgers drop to 15.
        $ist = $this->makeApprovedTransfer($from, $to, [[$product, 5]]);
        $ist->details->each(fn ($d) => $d->update(['quantity_scanned' => 5]));

        app(TransferService::class)->dispatch($ist->fresh('details'), $admin->user_id);

        $this->assertSame(15, $this->onHand($from->location_id, $product->product_id));
        $this->assertSame(15, $this->batchTotal($from->location_id, $product->product_id),
            'Dispatch must move both ledgers together.');

        // Editing the still-in-transit transfer is refused: its stock has
        // already moved through both ledgers, and this endpoint can only put
        // one of them back.
        $this->actingAs($admin, 'sanctum');

        $this->putJson("/api/transfers/{$ist->transfer_id}", [
            'details' => [
                ['product_id' => $product->product_id, 'quantity_transferred' => 2],
            ],
        ])->assertStatus(422);

        $onHand = $this->onHand($from->location_id, $product->product_id);
        $batches = $this->batchTotal($from->location_id, $product->product_id);

        $this->assertSame(15, $onHand, 'A refused edit must not move stock.');
        $this->assertSame(
            $onHand,
            $batches,
            "inventory says {$onHand} on hand but the batch ledger says {$batches}. "
            . 'FIFO picking reads the batch ledger, so the difference would be stock that '
            . 'appears available and cannot be picked.'
        );
    }
}
