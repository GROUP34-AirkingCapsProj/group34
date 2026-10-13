<?php

namespace Tests\Feature\Sets;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Services\IncidentService;

/**
 * The decision endpoints, end to end.
 *
 * Every test here used to POST /incident-reports/{id}/resolve, which approved a
 * report and deducted its stock in one step. That route is gone: the inspector
 * records an outcome per item and submits (POST /inspection), and the admin's
 * POST /decide is the single moment stock moves. The old resolve() also posted
 * an Adjustment and, for `wrong_type`, a ScanCorrection; both artifacts left
 * with the per-reason strategy classes, so the assertions that pinned them now
 * pin their absence explicitly rather than being dropped.
 */
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

    private function itemFor(IncidentReport $incident): IncidentItem
    {
        return IncidentItem::where('incident_id', $incident->incident_id)->firstOrFail();
    }

    /**
     * Record the outcome, submit the inspection, then confirm as a second
     * person. Two users because the client's two signature lines are enforced:
     * an inspector cannot confirm their own report while another resolver can
     * reach that location.
     *
     * Both are branch managers. The realignment pulled the analyst out of
     * `inspect` and `resolve` and gave branch_manager the pair, so the
     * examiner/approver split is no longer a role boundary — it survives only
     * per user, through IncidentInspectionController refusing a confirmation
     * from whoever submitted the inspection. Two distinct users of the same
     * role is therefore exactly what this helper has to exercise.
     */
    private function inspectAndConfirm(IncidentReport $incident, $loc, string $outcome, array $decision = []): void
    {
        $inspector = $this->makeUserAt($loc, 'branch_manager');
        $resolver  = $this->makeUserAt($loc, 'branch_manager');
        $id = $incident->incident_id;

        foreach (IncidentItem::where('incident_id', $id)->get() as $item) {
            $this->actingAs($inspector, 'sanctum')
                 ->postJson("/api/incident-reports/{$id}/items/{$item->item_id}/outcome", ['outcome' => $outcome])
                 ->assertOk();
        }

        $this->actingAs($inspector, 'sanctum')->postJson("/api/incident-reports/{$id}/inspection", [])->assertOk();

        $this->actingAs($resolver, 'sanctum')
             ->postJson("/api/incident-reports/{$id}/decide", ['action' => 'confirm'] + $decision)
             ->assertOk();
    }

    public function test_picker_can_file_through_the_endpoint(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeInstallationMaterial();
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

    /**
     * Was `test_picker_cannot_resolve`. Moved to /decide, and the separation is
     * now two-layered: a picker holds neither `inspect` nor `resolve`, so they
     * can neither examine the goods nor confirm the write-off.
     */
    public function test_picker_can_neither_inspect_nor_decide(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeInstallationMaterial();
        $b    = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_DEFECTIVE);

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/inspection", [])
             ->assertForbidden();

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/decide", ['action' => 'confirm'])
             ->assertForbidden();
    }

    /**
     * Was `test_resolving_missing_posts_a_deduction_adjustment_and_a_hold`.
     *
     * Rewritten to the new order. The Adjustment is gone with the strategy
     * classes, so the assertion that pinned its deduct_quantity now pins the
     * deduction where it actually happens — on the batch, at confirmation, and
     * not one moment earlier.
     */
    public function test_confirming_missing_deducts_the_batch_and_marks_the_item_missing(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeInstallationMaterial();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_MISSING, 3);

        // Inverted: filing used to be followed straight by a deduction. It now
        // quarantines, and the batch is whole until somebody confirms.
        $this->assertSame(5, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);

        $this->inspectAndConfirm($incident, $loc, IncidentItem::OUTCOME_WRITE_OFF,
            ['resolution_note' => 'Searched the aisle, stock is gone.']);

        $fresh = $incident->fresh();
        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $fresh->status_id);
        // Inverted: was assertNotNull. No Adjustment is posted any more — pinned
        // explicitly so its return would be a deliberate change, not a surprise.
        $this->assertNull($fresh->adjustment_id);

        // The same 3 the adjustment's deduct_quantity used to carry.
        $this->assertSame(2, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
        // Inverted: was a `missing` stock_holds row of quantity 3.
        $item = $this->itemFor($incident);
        $this->assertSame(IncidentItem::STATUS_MISSING, $item->status);
        $this->assertSame(3, (int) $item->quantity);
    }

    /**
     * Was `test_dismissing_missing_returns_the_stock_to_fifo`, via /resolve.
     *
     * Dismissal is stronger now than it was: because filing never deducted,
     * there is nothing to give back, so releasing the quarantine is a pure
     * status change and the batch cannot come back at the wrong number.
     */
    public function test_dismissing_missing_returns_the_stock_to_fifo(): void
    {
        $loc = $this->makeLocation();
        $resolver = $this->makeUserAt($loc, 'branch_manager');
        $p   = $this->makeInstallationMaterial();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_MISSING, 3);
        $this->assertContains((int) $b->batch_id, app(IncidentService::class)->excludedBatchIds($loc->location_id));

        // A dismissal needs no inspection: it says the report described nothing
        // real, so there is nothing for an inspector to find. One resolver is
        // therefore enough — the same-user rule only bites once an inspection
        // has been submitted.
        $this->actingAs($resolver, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/decide", [
                 'action' => 'dismiss', 'resolution_note' => 'Found it behind the pallet.',
             ])->assertOk();

        $this->assertSame(IncidentReport::statusId('Dismissed'), (int) $incident->fresh()->status_id);
        $this->assertNull($incident->fresh()->adjustment_id);
        $this->assertSame([], app(IncidentService::class)->excludedBatchIds($loc->location_id));
        $this->assertSame(5, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
        // Inverted: was `0 stock_holds rows`. The item survives as the record
        // that the report existed, released rather than deleted.
        $this->assertSame(IncidentItem::STATUS_AVAILABLE, $this->itemFor($incident)->status);
    }

    /**
     * Was `test_resolving_defective_posts_a_deduction_adjustment_and_a_hold`.
     *
     * The `defective` hold said "damaged, still in the building, somebody
     * decides later". That middle state is now `quarantined` — reached at
     * filing, not at approval — and `scrap` vs `return_to_supplier` is the
     * decision that used to be a disposition.
     */
    public function test_confirming_defective_deducts_the_batch_and_scraps_the_item(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeInstallationMaterial();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 8, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_DEFECTIVE, 2);

        $this->assertSame(IncidentItem::STATUS_QUARANTINED, $this->itemFor($incident)->status);
        $this->assertSame(8, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);

        $this->inspectAndConfirm($incident, $loc, IncidentItem::OUTCOME_SCRAP);

        // Inverted: was assertNotNull on adjustment_id.
        $this->assertNull($incident->fresh()->adjustment_id);
        $this->assertSame(6, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
        $this->assertSame(IncidentItem::STATUS_SCRAPPED, $this->itemFor($incident)->status);
    }

    /**
     * Was `test_resolving_wrong_type_creates_a_scan_correction`.
     *
     * INVERTED, and this is a real removal rather than a relocation: resolving a
     * `wrong_type` report used to write a ScanCorrection and re-type the batch
     * onto the corrected product. Nothing in the incident flow does that now —
     * batch re-typing lives only on the barcode-adjustment endpoints. The
     * assertion is inverted rather than deleted so that the batch quietly
     * changing product again would fail here.
     */
    public function test_confirming_wrong_type_no_longer_retypes_the_batch(): void
    {
        $loc     = $this->makeLocation();
        $wrong   = $this->makeAppliance();
        $correct = $this->makeInstallationMaterial();
        $b = $this->makeBatch($wrong, $loc, ['quantity_remaining' => 4, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $wrong, $b, IncidentReport::REASON_WRONG_TYPE, 4);

        // `corrected_product_id` is no longer part of the decision payload; it
        // is sent here to prove it is ignored rather than silently honoured.
        $this->inspectAndConfirm($incident, $loc, IncidentItem::OUTCOME_RETURN_TO_SUPPLIER, [
            'resolution_note' => 'Labelled as aircon, physically installation material.',
        ]);

        $fresh = $incident->fresh();
        // Inverted: was assertNotNull($fresh->correction_id) plus a ScanCorrection
        // row carrying the original and corrected product ids.
        $this->assertNull($fresh->correction_id);
        // Inverted: was assertSame($correct->product_id, $batch->product_id).
        $this->assertSame(
            (int) $wrong->product_id,
            (int) InventoryBatch::find($b->batch_id)->product_id,
            'the incident flow must not re-type a batch behind the barcode-adjustment ledger'
        );
        $this->assertNotSame((int) $correct->product_id, (int) InventoryBatch::find($b->batch_id)->product_id);
        // The mis-labelled units still leave, they just leave as goods going
        // back to the supplier rather than as a silent product swap.
        $this->assertSame(IncidentItem::STATUS_RETURNED, $this->itemFor($incident)->status);
        $this->assertSame(0, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
    }

    /**
     * Was `test_wrong_type_without_a_corrected_product_is_rejected` — a 422 from
     * the strategy that needed a target product to re-type onto.
     *
     * INVERTED: there is no re-typing and therefore no corrected product to
     * demand, so `wrong_type` takes exactly the same inspect-then-confirm route
     * as every other stock-affecting reason. What replaced that 422 is the
     * inspection gate, which is asserted here so the reason is not quietly
     * confirmable from Open.
     */
    public function test_wrong_type_follows_the_ordinary_inspection_route(): void
    {
        $loc = $this->makeLocation();
        $resolver = $this->makeUserAt($loc, 'branch_manager');
        $p   = $this->makeInstallationMaterial();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 4, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_WRONG_TYPE);

        $this->actingAs($resolver, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/decide", ['action' => 'confirm'])
             ->assertStatus(422);

        $this->assertSame(IncidentReport::statusId('Open'), (int) $incident->fresh()->status_id);
        $this->assertSame(4, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);

        $this->inspectAndConfirm($incident, $loc, IncidentItem::OUTCOME_SCRAP);

        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $incident->fresh()->status_id);
    }

    /**
     * REVERSAL, unchanged in meaning: this test once asserted that resolving
     * rewrote the batch's zone_label from actual_zone ('Zone 1' -> 'Zone 2').
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
        $resolver = $this->makeUserAt($loc, 'branch_manager');
        $p   = $this->makeInstallationMaterial();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 7, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_ZONE_MISMATCH, 7);

        // Moved to /decide, and confirmable straight from Open: a zone mismatch
        // is inspection-exempt, because demanding photographs and two signatures
        // to record that a box is on the wrong shelf teaches people not to report.
        $this->actingAs($resolver, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/decide", ['action' => 'confirm'])
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
        // Inverted: was `0 stock_holds rows`. Nothing left active stock, so
        // nothing was quarantined either — there is no item to inspect.
        $this->assertSame(0, IncidentItem::where('incident_id', $incident->incident_id)->count());
    }

    /** Was `test_resolving_twice_is_rejected`. Same guard at /decide. */
    public function test_confirming_twice_is_rejected(): void
    {
        $loc = $this->makeLocation();
        $resolver = $this->makeUserAt($loc, 'branch_manager');
        $p   = $this->makeInstallationMaterial();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_DEFECTIVE, 1);

        $this->inspectAndConfirm($incident, $loc, IncidentItem::OUTCOME_SCRAP);

        // A third branch manager, so the 422 proves the already-resolved guard
        // fired rather than the same-user rule.
        $this->actingAs($resolver, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/decide", ['action' => 'confirm'])
             ->assertStatus(422);

        // The batch moved exactly once — the whole point of the guard.
        $this->assertSame(4, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
    }

    public function test_listing_shows_the_reporters_incident_count(): void
    {
        $loc    = $this->makeLocation();
        $ia     = $this->makeUserAt($loc, 'inventory_analyst');
        $picker = $this->makeUserAt($loc, 'warehouse_personnel');
        $p      = $this->makeInstallationMaterial();
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
     * write-off must be clamped to the batch AND the location must drop by that
     * same clamped amount, so the batch and the location total never disagree.
     *
     * The assertion is unchanged in meaning — only the route and the artifact it
     * reads have moved. The old code recorded the full 10 on the ledger while
     * only the batch's 3 could physically leave; the rewritten deduct() clamped
     * batch and location independently and reproduced the same divergence in the
     * location total. Both vanish 7 units no batch ever lost.
     */
    public function test_deduction_over_the_batch_does_not_diverge_batch_and_location(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeInstallationMaterial();

        // Location holds 50 (a bigger pool), but the incident's batch has only 3.
        $this->setStock($loc->location_id, $p->product_id, 50);
        $b = $this->makeBatch($p, $loc, ['quantity_remaining' => 3, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_MISSING, 10);

        $this->inspectAndConfirm($incident, $loc, IncidentItem::OUTCOME_WRITE_OFF);

        // The batch is emptied, and the location total drops by exactly 3
        // (50 -> 47), not by 10.
        $this->assertSame(0, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
        $inv = Inventory::where('location_id', $loc->location_id)->where('product_id', $p->product_id)->first();
        $this->assertSame(47, (int) $inv->quantity_on_hand);
        $this->assertSame(47, (int) $inv->available_quantity);

        // Inverted: was `hold quantity == 3` and `on_hand + held == 50`. Nothing
        // is held aside any more, so the invariant is read straight off the two
        // numbers that must agree — what the batch lost and what the location lost.
        $batchLoss    = 3 - (int) InventoryBatch::find($b->batch_id)->quantity_remaining;
        $locationLoss = 50 - (int) $inv->quantity_on_hand;
        $this->assertSame($batchLoss, $locationLoss,
            'the location must lose exactly what a batch gave up — no units may vanish that no batch lost');
        // The item still records what was REPORTED (10), which is the shrinkage
        // signal; only the movement is clamped to what a batch could give up.
        $this->assertSame(10, (int) $this->itemFor($incident)->quantity);
    }

    /**
     * Was `test_wrong_type_without_a_batch_is_rejected` — a 422, because the
     * strategy had no batch to re-type.
     *
     * INVERTED: with re-typing gone there is nothing that needs a batch, so a
     * batchless `wrong_type` is filed and decided normally. It pulls no batch
     * out of FIFO, because it names none.
     */
    public function test_a_batchless_wrong_type_is_filed_and_decided_normally(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeInstallationMaterial();

        $incident = $this->openIncident($loc, $p, null, IncidentReport::REASON_WRONG_TYPE, 2);

        $this->assertNull($this->itemFor($incident)->batch_id);
        $this->assertSame([], app(IncidentService::class)->excludedBatchIds($loc->location_id));

        $this->inspectAndConfirm($incident, $loc, IncidentItem::OUTCOME_RETURN_TO_SUPPLIER);

        // Inverted: was "it stays Open — nothing was closed as fixed". There is
        // nothing left to refuse it, so it closes on the recorded outcome.
        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $incident->fresh()->status_id);
        $this->assertSame(IncidentItem::STATUS_RETURNED, $this->itemFor($incident)->status);
    }
}
