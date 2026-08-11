<?php

namespace Tests\Feature\Sets;

use App\Models\Adjustment;
use App\Models\IncidentReport;
use App\Models\InventoryBatch;
use App\Models\ScanCorrection;
use App\Models\StockHold;
use App\Services\IncidentService;

class IncidentResolutionTest extends SetTestCase
{
    private function openIncident($loc, $product, $batch, string $reason, int $qty = 3, ?int $reportedBy = null): IncidentReport
    {
        $user = $reportedBy ?? (int) $this->makeUserAt($loc, 'warehouse_personnel')->user_id;

        return app(IncidentService::class)->file([
            'source_type'   => 'issuance',
            'source_id'     => 1,
            'location_id'   => $loc->location_id,
            'product_id'    => $product->product_id,
            'batch_id'      => $batch?->batch_id,
            'expected_zone' => 'Zone 1',
            'actual_zone'   => 'Zone 2',
            'reason'        => $reason,
            'quantity'      => $qty,
            'narrative'     => 'Test narrative.',
        ], $user);
    }

    public function test_picker_can_file_through_the_endpoint(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable();
        $b    = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'issuance', 'source_id' => 1,
            'location_id' => $loc->location_id, 'product_id' => $p->product_id,
            'batch_id'    => $b->batch_id, 'reason' => 'missing',
            'quantity'    => 2, 'narrative' => 'Shelf is empty.',
        ]);

        $res->assertCreated();
        $this->assertStringStartsWith('INC-', $res->json('data.incident_number'));
    }

    public function test_picker_cannot_resolve(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable();
        $b    = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_DEFECTIVE);

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertForbidden();
    }

    public function test_resolving_missing_posts_a_deduction_adjustment_and_a_hold(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_MISSING, 3);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", [
                 'action' => 'confirm', 'resolution_note' => 'Searched the aisle, stock is gone.',
             ])->assertOk();

        $fresh = $incident->fresh();
        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $fresh->status_id);
        $this->assertNotNull($fresh->adjustment_id);

        $adjustment = Adjustment::with('details')->find($fresh->adjustment_id);
        $this->assertSame(3, (int) $adjustment->details->first()->deduct_quantity);
        $this->assertSame(2, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);

        // New: the deduction is mirrored by a `missing` hold for the same
        // quantity, so physical = active + held survives the write-off.
        $this->assertDatabaseHas('stock_holds', [
            'incident_id' => $incident->incident_id,
            'condition'   => StockHold::CONDITION_MISSING,
            'quantity'    => 3,
            'status'      => StockHold::STATUS_HELD,
        ]);
    }

    public function test_dismissing_missing_returns_the_stock_to_fifo(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_MISSING, 3);
        $this->assertContains((int) $b->batch_id, app(IncidentService::class)->excludedBatchIds($loc->location_id));

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", [
                 'action' => 'dismiss', 'resolution_note' => 'Found it behind the pallet.',
             ])->assertOk();

        $this->assertSame(IncidentReport::statusId('Dismissed'), (int) $incident->fresh()->status_id);
        $this->assertNull($incident->fresh()->adjustment_id);
        $this->assertSame([], app(IncidentService::class)->excludedBatchIds($loc->location_id));
        $this->assertSame(5, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
        // Dismissing posts nothing, and that now includes opening no hold.
        $this->assertSame(0, StockHold::where('incident_id', $incident->incident_id)->count());
    }

    public function test_resolving_defective_posts_a_deduction_adjustment_and_a_hold(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 8, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_DEFECTIVE, 2);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $this->assertNotNull($incident->fresh()->adjustment_id);
        $this->assertSame(6, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);

        // New: damaged stock is still in the building, so the deduction leaves a
        // `defective` hold that a later inspection returns to the supplier or scraps.
        $this->assertDatabaseHas('stock_holds', [
            'incident_id' => $incident->incident_id,
            'condition'   => StockHold::CONDITION_DEFECTIVE,
            'quantity'    => 2,
            'status'      => StockHold::STATUS_HELD,
        ]);
    }

    public function test_resolving_wrong_type_creates_a_scan_correction(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $wrong   = $this->makeAppliance();
        $correct = $this->makeConsumable();
        $b = $this->makeBatch($wrong, $loc, ['quantity_remaining' => 4, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $wrong, $b, IncidentReport::REASON_WRONG_TYPE, 4);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", [
                 'action'               => 'confirm',
                 'corrected_product_id' => $correct->product_id,
                 'resolution_note'      => 'Labelled as aircon, physically installation material.',
             ])->assertOk();

        $fresh = $incident->fresh();
        $this->assertNotNull($fresh->correction_id);

        $correction = ScanCorrection::find($fresh->correction_id);
        $this->assertSame((int) $wrong->product_id, (int) $correction->original_product_id);
        $this->assertSame((int) $correct->product_id, (int) $correction->corrected_product_id);
        $this->assertSame((int) $correct->product_id, (int) InventoryBatch::find($b->batch_id)->product_id);
    }

    public function test_wrong_type_without_a_corrected_product_is_rejected(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 4, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_WRONG_TYPE);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertStatus(422);
    }

    /**
     * REVERSAL: this test used to assert that resolving rewrote the batch's
     * zone_label from actual_zone ('Zone 1' -> 'Zone 2').
     *
     * That was backwards. The reporter sees the stock physically in Zone 2
     * while the system says Zone 1, and the SYSTEM is right — someone misplaced
     * the box. Rewriting zone_label overwrote a correct record with the wrong
     * physical location; the fix is to walk over and move the box back. The
     * incident's expected_zone/actual_zone pair is the whole artifact.
     */
    public function test_resolving_zone_mismatch_changes_nothing_at_all(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 7, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_ZONE_MISMATCH, 7);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $fresh = $incident->fresh();
        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $fresh->status_id);
        $this->assertNull($fresh->adjustment_id, 'a zone mismatch is an observation, not a loss');
        $this->assertNull($fresh->correction_id);
        // The observation itself is preserved on the incident, which is what a
        // reviewer acts on when they send someone to move the box.
        $this->assertSame('Zone 1', $fresh->expected_zone);
        $this->assertSame('Zone 2', $fresh->actual_zone);

        $batch = InventoryBatch::find($b->batch_id);
        // Reversal: was assertSame('Zone 2', ...). The system's zone is correct
        // and must NOT be overwritten with where the box was wrongly found.
        $this->assertSame('Zone 1', $batch->zone_label, 'the system zone must survive the review untouched');
        $this->assertSame(7, (int) $batch->quantity_remaining);
        // Nothing left active stock either, so no hold is opened.
        $this->assertSame(0, StockHold::where('incident_id', $incident->incident_id)->count());
    }

    public function test_resolving_twice_is_rejected(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_DEFECTIVE, 1);
        $url = "/api/incident-reports/{$incident->incident_id}/resolve";

        $this->actingAs($ia, 'sanctum')->postJson($url, ['action' => 'confirm'])->assertOk();
        $this->actingAs($ia, 'sanctum')->postJson($url, ['action' => 'confirm'])->assertStatus(422);

        $this->assertSame(4, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
    }

    public function test_listing_shows_the_reporters_incident_count(): void
    {
        $loc    = $this->makeLocation();
        $ia     = $this->makeUserAt($loc, 'inventory_analyst');
        $picker = $this->makeUserAt($loc, 'warehouse_personnel');
        $p      = $this->makeConsumable();
        $b      = $this->makeBatch($p, $loc, ['quantity_remaining' => 9, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $this->openIncident($loc, $p, $b, IncidentReport::REASON_MISSING, 1, (int) $picker->user_id);
        $this->openIncident($loc, $p, $b, IncidentReport::REASON_MISSING, 1, (int) $picker->user_id);

        $res = $this->actingAs($ia, 'sanctum')->getJson('/api/incident-reports?status=open');

        $res->assertOk();
        $rows = collect($res->json('data'))->where('reported_by', (int) $picker->user_id);
        $this->assertSame(2, (int) $rows->first()['reporter_incident_count']);
    }

    /**
     * When the reported quantity exceeds what the batch actually holds, the
     * write-off must be clamped to the batch AND recorded at the clamped amount,
     * so the batch, the location total, and the ledger never disagree. The old
     * code recorded the full 10 while only the batch's 3 could physically leave,
     * silently vanishing 7 units from the location total.
     */
    public function test_deduction_over_the_batch_does_not_diverge_batch_and_location(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();

        // Location holds 50 (a bigger pool), but the incident's batch has only 3.
        $this->setStock($loc->location_id, $p->product_id, 50);
        $b = $this->makeBatch($p, $loc, ['quantity_remaining' => 3, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_MISSING, 10);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        // Only 3 could leave the batch, so the ledger records 3, not 10...
        $adjustment = \App\Models\Adjustment::with('details')->find($incident->fresh()->adjustment_id);
        $this->assertSame(3, (int) $adjustment->total_variance_negative);
        $this->assertSame(3, (int) $adjustment->details->first()->deduct_quantity);

        // ...the batch is emptied, and the location total drops by exactly 3 (50 -> 47),
        // not by 10 — no units vanish that no batch lost.
        $this->assertSame(0, (int) \App\Models\InventoryBatch::find($b->batch_id)->quantity_remaining);
        $inv = \App\Models\Inventory::where('location_id', $loc->location_id)->where('product_id', $p->product_id)->first();
        $this->assertSame(47, (int) $inv->quantity_on_hand);

        // ...and the hold is clamped to the same 3, so physical = active + held
        // (47 + 3 = 50). A hold of 10 would manufacture 7 units no batch lost —
        // the same divergence in the other direction.
        $hold = StockHold::where('incident_id', $incident->incident_id)->firstOrFail();
        $this->assertSame(3, (int) $hold->quantity);
        $this->assertSame(50, (int) $inv->quantity_on_hand + (int) $hold->quantity);
    }

    public function test_wrong_type_without_a_batch_is_rejected(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $correct = $this->makeConsumable();

        // A wrong_type incident with no batch to re-type.
        $incident = $this->openIncident($loc, $p, null, IncidentReport::REASON_WRONG_TYPE, 2);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", [
                 'action' => 'confirm', 'corrected_product_id' => $correct->product_id,
             ])
             ->assertStatus(422);

        // It stays Open — nothing was closed as "fixed" while the stock is untouched.
        $this->assertSame(IncidentReport::statusId('Open'), (int) $incident->fresh()->status_id);
    }
}
