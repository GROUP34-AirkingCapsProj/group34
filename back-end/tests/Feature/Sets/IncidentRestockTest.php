<?php

namespace Tests\Feature\Sets;

use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\StockHold;
use App\Services\IncidentService;
use App\Services\StockHoldService;

/**
 * REVERSAL (2026-08-11): resolving a customer return used to increment the
 * batch and the location on the spot, which made an uninspected unit
 * immediately pickable. Approval now opens a `returned` hold; the increment
 * moved to the `restocked` disposition, where a person confirms the unit is
 * sellable.
 *
 * These tests were NOT deleted when that reversal landed — they were moved one
 * step later in the flow (file -> resolve -> dispose as restocked), so the
 * restock behaviour they guard is still guarded, just at its new home.
 */
class IncidentRestockTest extends SetTestCase
{
    private function openReturn($loc, $product, $batch, int $qty = 3, ?int $serialId = null): IncidentReport
    {
        $wp = $this->makeUserAt($loc, 'warehouse_personnel');

        return app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $product->product_id,
            'batch_id'    => $batch?->batch_id,
            'serial_id'   => $serialId,
            // `customer_return` collapsed into `return`, with the direction
            // carried as a field rather than baked into the reason.
            'reason'      => IncidentReport::REASON_RETURN,
            'quantity'    => $qty,
            'narrative'   => 'Wrong model delivered, customer sent it back.',
        ], (int) $wp->user_id);
    }

    /** The hold a resolved return opened. */
    private function heldFor(IncidentReport $incident): StockHold
    {
        return StockHold::where('incident_id', $incident->incident_id)
            ->where('status', StockHold::STATUS_HELD)
            ->firstOrFail();
    }

    /**
     * A real user id: disposed_by is a foreign key to users and the seeded ids
     * differ between the dev and test databases, so this cannot be a literal.
     */
    private function inspectorId($loc): int
    {
        return (int) $this->makeUserAt($loc, 'inventory_analyst')->user_id;
    }

    public function test_a_restocked_return_puts_stock_back_on_its_original_batch(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();

        $this->setStock($loc->location_id, $p->product_id, 20);
        $b = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(30), 'zone_label' => 'Zone 1']);

        $incident = $this->openReturn($loc, $p, $b, 3);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", [
                 'action' => 'confirm', 'resolution_note' => 'Sent to inspection.',
             ])->assertOk();

        $fresh = $incident->fresh();
        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $fresh->status_id);
        // Reversal: a return posts no Adjustment — nothing was lost or damaged,
        // so the hold row, not an adjustment, is the record.
        $this->assertNull($fresh->adjustment_id);

        // Reversal: approval holds rather than restocks, so the quantity leaves
        // active stock and sits in the hold instead of arriving on the shelf.
        $hold = $this->heldFor($incident);
        $this->assertSame(StockHold::CONDITION_RETURNED, $hold->condition);
        $this->assertSame(3, (int) $hold->quantity);
        $this->assertSame(2, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);

        app(StockHoldService::class)->dispose(
            $hold, StockHold::DISPOSITION_RESTOCKED, 'Inspected, back on the shelf.', $this->inspectorId($loc)
        );

        // The increment moved here, and still lands on the batch the stock left,
        // so its true received_at — and its place in FIFO order — is preserved.
        $this->assertSame(5, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
        $this->assertSame(1, (int) InventoryBatch::where('product_id', $p->product_id)->count());

        $inv = Inventory::where('location_id', $loc->location_id)->where('product_id', $p->product_id)->first();
        $this->assertSame(20, (int) $inv->quantity_on_hand);
        $this->assertSame(20, (int) $inv->available_quantity);

        $this->assertSame(StockHold::STATUS_RELEASED, $hold->fresh()->status);
        $this->assertSame(StockHold::DISPOSITION_RESTOCKED, $hold->fresh()->disposition);
    }

    /**
     * Was `test_the_adjustment_records_an_addition`. Reversal: a return no
     * longer posts an Adjustment at all, so the assertion that used to pin the
     * adjustment's shape now pins that there is none — and pins the hold that
     * replaced it, at the same quantity the adjustment used to carry.
     */
    public function test_a_return_posts_no_adjustment_and_records_the_quantity_on_the_hold(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openReturn($loc, $p, $b, 4);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $this->assertNull($incident->fresh()->adjustment_id, 'a return is not a stock loss or gain to adjust');
        $this->assertNull($incident->fresh()->correction_id);

        $hold = $this->heldFor($incident);
        $this->assertSame(4, (int) $hold->quantity);
        $this->assertSame((int) $b->batch_id, (int) $hold->batch_id);
        $this->assertSame((int) $p->product_id, (int) $hold->product_id);
        $this->assertSame((int) $loc->location_id, (int) $hold->location_id);
    }

    /**
     * Was `test_a_return_with_no_batch_lands_in_the_legacy_batch`.
     *
     * The legacy-batch landing is gone: StockHoldService::returnToStock only
     * touches a batch when the hold carries one, so a batchless return restocks
     * the location and nothing else. The batch-count assertion below is what
     * makes that visible — if a legacy batch is reinstated later, this test
     * fails loudly rather than silently passing either way.
     */
    public function test_a_batchless_return_restocks_the_location_only(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();

        $incident = $this->openReturn($loc, $p, null, 2);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $hold = $this->heldFor($incident);
        $this->assertNull($hold->batch_id);
        $this->assertSame(2, (int) $hold->quantity);

        app(StockHoldService::class)->dispose(
            $hold, StockHold::DISPOSITION_RESTOCKED, null, $this->inspectorId($loc)
        );

        $inv = Inventory::where('location_id', $loc->location_id)->where('product_id', $p->product_id)->first();
        $this->assertNotNull($inv, 'a restock into a product with no stock row must create one');
        $this->assertSame(2, (int) $inv->quantity_on_hand);
        $this->assertSame(2, (int) $inv->available_quantity);

        // KNOWN GAP, pinned deliberately: the quantity is on the location but on
        // no batch, so FIFO picking cannot reach it. The old code parked it in
        // the legacy (null-receiving_id) catch-all batch; returnToStock does not.
        $this->assertSame(
            0,
            (int) InventoryBatch::where('product_id', $p->product_id)->where('location_id', $loc->location_id)->count(),
            'no batch is created for a batchless restock — see the note above'
        );
    }

    public function test_a_returned_serial_is_held_before_it_goes_back_in_stock(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeAppliance();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        // A serial out with a customer has no status — nothing in the codebase
        // writes a non-26 status — so null is the real pre-state the flip rescues.
        $serial = $this->makeSerial($p, $loc, 'SN-RETURN-001', $b->receiving_id);
        $this->assertNull($serial->status_id);

        $incident = $this->openReturn($loc, $p, $b, 1, (int) $serial->serial_id);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        // Reversal: approval no longer flips the serial to In Stock. It carries
        // the Returned status, so a scan tells the truth about a held unit.
        $this->assertSame(
            StockHold::inventoryStatusId('Returned'),
            (int) ItemSerial::find($serial->serial_id)->status_id
        );

        app(StockHoldService::class)->dispose(
            $this->heldFor($incident), StockHold::DISPOSITION_RESTOCKED, null, $this->inspectorId($loc)
        );

        // The In Stock flip moved here, to the point a person says it is sellable.
        $returned = ItemSerial::find($serial->serial_id);
        $this->assertSame(ItemSerial::IN_STOCK_STATUS_ID, (int) $returned->status_id);

        // In Stock without serial_type 'received' is still unpickable —
        // scopeIssuableAt and FifoPickingService both require it.
        $this->assertSame('received', $returned->serial_type);
    }

    public function test_resolving_a_return_twice_is_rejected(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openReturn($loc, $p, $b, 3);
        $url = "/api/incident-reports/{$incident->incident_id}/resolve";

        $this->actingAs($ia, 'sanctum')->postJson($url, ['action' => 'confirm'])->assertOk();
        $this->actingAs($ia, 'sanctum')->postJson($url, ['action' => 'confirm'])->assertStatus(422);

        // The hold was opened exactly once, and the batch moved exactly once
        // (5 - 3). Under the old behaviour this pinned the increment; it now
        // pins the deduction, which is the same double-booking guard.
        $this->assertSame(1, StockHold::where('incident_id', $incident->incident_id)->count());
        $this->assertSame(2, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
    }

    /** A hold cannot be disposed twice, so the stock cannot come back twice either. */
    public function test_disposing_a_hold_twice_is_rejected(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openReturn($loc, $p, $b, 3);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $hold      = $this->heldFor($incident);
        $inspector = $this->inspectorId($loc);
        $service   = app(StockHoldService::class);

        $service->dispose($hold, StockHold::DISPOSITION_RESTOCKED, null, $inspector);

        $this->expectException(\RuntimeException::class);
        $service->dispose($hold, StockHold::DISPOSITION_RESTOCKED, null, $inspector);
    }
}
