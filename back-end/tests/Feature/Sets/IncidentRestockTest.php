<?php

namespace Tests\Feature\Sets;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Services\IncidentService;
use Illuminate\Support\Facades\DB;

/**
 * REVERSAL, second pass (2026-08-12).
 *
 * First pass: resolving a customer return incremented the batch and the
 * location on the spot, which made an uninspected unit immediately pickable.
 * That was moved to a `restocked` disposition on a `stock_holds` row.
 *
 * This pass removes the arithmetic entirely. A return's goods are physically
 * present the moment the report is filed, so filing QUARANTINES them — the
 * count never changes, and `quantity_on_hand = pickable + quarantined` holds
 * from filing to confirmation. Confirming a `restock` outcome therefore moves
 * no numbers at all; it only makes the units pickable again.
 *
 * These tests were not deleted when that landed — each one was re-aimed at the
 * step that now carries the behaviour it used to guard.
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

    /** The quarantined item a filed return created — the row that replaced the hold. */
    private function itemFor(IncidentReport $incident): IncidentItem
    {
        return IncidentItem::where('incident_id', $incident->incident_id)->firstOrFail();
    }

    /** Resolved by NAME — inventory status ids differ between the two databases. */
    private function inventoryStatusId(string $name): int
    {
        return (int) DB::table('status_lookup')
            ->where('status_category', 'inventory')
            ->where('status_name', $name)
            ->value('status_id');
    }

    /**
     * Record the outcome, submit the inspection, then confirm as a second
     * person — the client's two signature lines are enforced, so the inspector
     * cannot confirm their own report while another resolver can reach the
     * location. branch_manager holds `inspect`, inventory_analyst `resolve`.
     */
    private function inspectAndConfirm(IncidentReport $incident, $loc, string $outcome): void
    {
        $bm = $this->makeUserAt($loc, 'branch_manager');
        $ia = $this->makeUserAt($loc, 'inventory_analyst');
        $id = $incident->incident_id;

        $this->actingAs($bm, 'sanctum')
             ->postJson("/api/incident-reports/{$id}/items/{$this->itemFor($incident)->item_id}/outcome",
                 ['outcome' => $outcome])
             ->assertOk();

        $this->actingAs($bm, 'sanctum')->postJson("/api/incident-reports/{$id}/inspection", [])->assertOk();

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$id}/decide", ['action' => 'confirm'])
             ->assertOk();
    }

    /**
     * Was `test_a_restocked_return_puts_stock_back_on_its_original_batch`.
     *
     * The guarantee it protected — a restocked return must not be conjured onto
     * a NEW batch, which would give it a fresh received_at and jump it to the
     * back of FIFO — survives, and is now structural: the units never left the
     * batch, so there is nothing to put back and no second batch can appear.
     */
    public function test_a_restocked_return_stays_on_its_original_batch(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeInstallationMaterial();

        $this->setStock($loc->location_id, $p->product_id, 20);
        $b = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(30), 'zone_label' => 'Zone 1']);

        $incident = $this->openReturn($loc, $p, $b, 3);

        // Inverted: filing used to deduct 3 into a hold (5 -> 2). It now
        // quarantines in place, so the batch is untouched...
        $this->assertSame(5, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
        $this->assertSame(IncidentItem::STATUS_QUARANTINED, $this->itemFor($incident)->status);
        // ...but unpickable, which is the part that actually mattered: an
        // uninspected return must not be sellable.
        $this->assertContains(
            (int) $b->batch_id,
            app(IncidentService::class)->excludedBatchIds($loc->location_id)
        );

        $this->inspectAndConfirm($incident, $loc, IncidentItem::OUTCOME_RESTOCK);

        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $incident->fresh()->status_id);
        // Inverted: was an increment back to 5 from 2. Restocking is now a no-op
        // on the numbers, because nothing was ever deducted.
        $this->assertSame(5, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
        // Unchanged and still load-bearing: no second batch was invented, so the
        // stock keeps its true received_at and its place in FIFO order.
        $this->assertSame(1, (int) InventoryBatch::where('product_id', $p->product_id)->count());

        $inv = Inventory::where('location_id', $loc->location_id)->where('product_id', $p->product_id)->first();
        $this->assertSame(20, (int) $inv->quantity_on_hand);
        $this->assertSame(20, (int) $inv->available_quantity);

        // Inverted: was STATUS_RELEASED / DISPOSITION_RESTOCKED on the hold.
        $this->assertSame(IncidentItem::STATUS_AVAILABLE, $this->itemFor($incident)->status);
        $this->assertSame(IncidentItem::OUTCOME_RESTOCK, $this->itemFor($incident)->outcome);
        // …and the batch is back in FIFO rotation with no cleanup step.
        $this->assertNotContains(
            (int) $b->batch_id,
            app(IncidentService::class)->excludedBatchIds($loc->location_id)
        );
    }

    /**
     * Was `test_a_return_posts_no_adjustment_and_records_the_quantity_on_the_hold`,
     * itself once `test_the_adjustment_records_an_addition`.
     *
     * Same job, third home: the assertion that used to pin the Adjustment's
     * shape, then the hold's, now pins the incident_item's — at the same
     * quantity, batch, product and location the earlier rows carried.
     */
    public function test_a_return_posts_no_adjustment_and_records_the_quantity_on_the_item(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeInstallationMaterial();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openReturn($loc, $p, $b, 4);

        $this->assertNull($incident->fresh()->adjustment_id, 'a return is not a stock loss or gain to adjust');
        $this->assertNull($incident->fresh()->correction_id);

        $item = $this->itemFor($incident);
        $this->assertSame(4, (int) $item->quantity);
        $this->assertSame((int) $b->batch_id, (int) $item->batch_id);
        $this->assertSame((int) $p->product_id, (int) $item->product_id);
        // An item has no location_id column — location lives on the parent
        // report, because an item cannot be somewhere its incident isn't.
        $this->assertSame((int) $loc->location_id, (int) $item->incident->location_id);
    }

    /**
     * Was `test_a_batchless_return_lands_in_the_legacy_batch`.
     *
     * INVERTED, and the substantive change on this file. A restock no longer
     * creates stock anywhere: filing quarantines goods that are already on the
     * shelf, so a return filed without a batch has no batch to quarantine and
     * confirming it invents neither a legacy batch nor a location row. The old
     * legacy-batch landing existed only because approval used to INCREMENT.
     */
    public function test_a_batchless_return_quarantines_nothing_and_creates_no_batch(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeInstallationMaterial();

        $incident = $this->openReturn($loc, $p, null, 2);

        $item = $this->itemFor($incident);
        $this->assertNull($item->batch_id);
        $this->assertSame(2, (int) $item->quantity);
        // A batchless report pulls no batch out of rotation — there is nothing
        // to name — so picking at this location is unaffected.
        $this->assertSame([], app(IncidentService::class)->excludedBatchIds($loc->location_id));

        $this->inspectAndConfirm($incident, $loc, IncidentItem::OUTCOME_RESTOCK);

        $this->assertSame(IncidentItem::STATUS_AVAILABLE, $this->itemFor($incident)->status);

        // Inverted: was a legacy catch-all batch holding 2, and a location row
        // created at 2. Confirming a restock adds nothing, so neither appears.
        $this->assertSame(0, (int) InventoryBatch::where('product_id', $p->product_id)
            ->where('location_id', $loc->location_id)->count());
        $this->assertNull(
            Inventory::where('location_id', $loc->location_id)->where('product_id', $p->product_id)->first(),
            'confirming a restock must not conjure a stock row for goods nothing counted in'
        );
    }

    /**
     * Was `test_a_returned_serial_is_held_before_it_goes_back_in_stock`.
     *
     * Same guarantee, one step earlier: the serial must not read as sellable
     * while nobody has looked at it. The Quarantined stamp moved from approval
     * to filing, which is the point the unit actually stops being pickable.
     */
    public function test_a_returned_serial_is_quarantined_before_it_goes_back_in_stock(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeAppliance();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        // A serial out with a customer has no status — nothing in the codebase
        // writes a non-26 status — so null is the real pre-state the flip rescues.
        $serial = $this->makeSerial($p, $loc, 'SN-RETURN-001', $b->receiving_id);
        $this->assertNull($serial->status_id);

        $incident = $this->openReturn($loc, $p, $b, 1, (int) $serial->serial_id);

        // Inverted: was `Returned` set at approval. Filing is now what stamps
        // it, and the stamp is `Quarantined` — a scan tells the truth about a
        // unit under investigation from the moment it is reported.
        $this->assertSame(
            $this->inventoryStatusId('Quarantined'),
            (int) ItemSerial::find($serial->serial_id)->status_id
        );

        $this->inspectAndConfirm($incident, $loc, IncidentItem::OUTCOME_RESTOCK);

        // Unchanged: the In Stock flip still happens at the point a person says
        // the unit is sellable — that point is now the admin's confirmation.
        $returned = ItemSerial::find($serial->serial_id);
        $this->assertSame(ItemSerial::IN_STOCK_STATUS_ID, (int) $returned->status_id);

        // In Stock without serial_type 'received' is still unpickable —
        // scopeIssuableAt and FifoPickingService both require it.
        $this->assertSame('received', $returned->serial_type);
    }

    /**
     * Was `test_resolving_a_return_twice_is_rejected`, hitting POST /resolve.
     * Same double-booking guard at the endpoint that replaced it.
     */
    public function test_confirming_a_return_twice_is_rejected(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeInstallationMaterial();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openReturn($loc, $p, $b, 3);

        // Scrap, not restock: an outcome that MOVES stock is what a second
        // confirmation would double-book, so it is the one worth guarding.
        $this->inspectAndConfirm($incident, $loc, IncidentItem::OUTCOME_SCRAP);

        $ia = $this->makeUserAt($loc, 'inventory_analyst');
        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/decide", ['action' => 'confirm'])
             ->assertStatus(422);

        // The batch moved exactly once (5 - 3), and the item is settled once.
        $this->assertSame(2, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
        $this->assertSame(1, IncidentItem::where('incident_id', $incident->incident_id)->count());
        $this->assertSame(IncidentItem::STATUS_SCRAPPED, $this->itemFor($incident)->status);
    }

    /**
     * Was `test_disposing_a_hold_twice_is_rejected` — "a hold cannot be disposed
     * twice, so the stock cannot come back twice either".
     *
     * The disposal step is gone; its guard moved onto the outcome. A settled
     * item refuses a new outcome, so nobody can re-decide a unit that has
     * already been scrapped and have it applied a second time.
     */
    public function test_a_settled_item_refuses_a_new_outcome(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeInstallationMaterial();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openReturn($loc, $p, $b, 3);
        $itemId   = $this->itemFor($incident)->item_id;

        $this->inspectAndConfirm($incident, $loc, IncidentItem::OUTCOME_SCRAP);

        $bm = $this->makeUserAt($loc, 'branch_manager');
        $this->actingAs($bm, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/items/{$itemId}/outcome",
                 ['outcome' => IncidentItem::OUTCOME_RESTOCK])
             ->assertStatus(422);

        // The recorded ending stands, and the stock did not come back.
        $this->assertSame(IncidentItem::STATUS_SCRAPPED, $this->itemFor($incident)->status);
        $this->assertSame(IncidentItem::OUTCOME_SCRAP, $this->itemFor($incident)->outcome);
        $this->assertSame(2, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
    }
}
