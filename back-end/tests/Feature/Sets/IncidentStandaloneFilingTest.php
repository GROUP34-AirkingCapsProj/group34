<?php

namespace Tests\Feature\Sets;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\InventoryBatch;
use App\Services\IncidentService;

class IncidentStandaloneFilingTest extends SetTestCase
{
    /**
     * Walk a filed report through the new order: the inspector records an
     * outcome per item and submits, then a second person confirms.
     *
     * Two users, because the client's two signature lines are enforced — the
     * inspector cannot confirm their own report while another resolver exists
     * at that location. The inspector is a branch_manager (inspect, no resolve)
     * and the approver an inventory_analyst (resolve).
     */
    private function inspectAndConfirm(IncidentReport $incident, $loc, string $outcome): void
    {
        $bm = $this->makeUserAt($loc, 'branch_manager');
        $ia = $this->makeUserAt($loc, 'inventory_analyst');

        foreach (IncidentItem::where('incident_id', $incident->incident_id)->get() as $item) {
            $this->actingAs($bm, 'sanctum')
                 ->postJson("/api/incident-reports/{$incident->incident_id}/items/{$item->item_id}/outcome",
                     ['outcome' => $outcome])
                 ->assertOk();
        }

        $this->actingAs($bm, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/inspection", [])
             ->assertOk();

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/decide", ['action' => 'confirm'])
             ->assertOk();
    }

    /**
     * `theft` stopped being a reason of its own: the type is now `missing` and
     * the theft nuance rides along as `suspected_cause`.
     *
     * Rewritten for the new order. Was
     * `test_missing_with_suspected_theft_writes_off_as_a_loss_and_holds_the_stock`,
     * which asserted an Adjustment of type `loss` plus a `missing` stock_holds
     * row. Both artifacts are gone: the per-reason resolution strategies that
     * posted the Adjustment were deleted, and the hold became an incident_item.
     * What the test still guards is the thing it was really about — the
     * suspected cause is recorded and does not change what happens to the stock.
     */
    public function test_missing_with_suspected_theft_deducts_only_once_it_is_confirmed(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 10, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'batch_id'    => $b->batch_id,
            'reason'          => IncidentReport::REASON_MISSING,
            'suspected_cause' => 'theft',
            'quantity'        => 4,
            'narrative'       => 'Four units gone overnight, side door was unlatched.',
            'occurred_at'     => now()->subDay(),
        ], (int) $wp->user_id);

        // Inverted: filing quarantines, it does not deduct. The batch is intact
        // until somebody has confirmed the write-off.
        $this->assertSame(10, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);

        $this->inspectAndConfirm($incident, $loc, IncidentItem::OUTCOME_WRITE_OFF);

        $this->assertSame(6, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
        // Inverted: was `stock_holds` with CONDITION_MISSING. The ending is now
        // an item status, and it is reached through the inspector's outcome.
        $this->assertSame(IncidentItem::STATUS_MISSING,
            IncidentItem::where('incident_id', $incident->incident_id)->firstOrFail()->status);
        // Inverted: was an Adjustment of type `loss`. The strategy classes that
        // posted it are gone, so the deduction has no ledger row of its own —
        // pinned explicitly so its later return is a deliberate change.
        $this->assertNull($incident->fresh()->adjustment_id);
        // Unchanged, and the point of the test: the cause is recorded verbatim.
        $this->assertSame('theft', $incident->fresh()->suspected_cause);
    }

    /**
     * `delivery_damage` collapsed into `defective` with `root_cause: delivery`.
     *
     * Rewritten for the new order, same way as the theft case above: was
     * `test_defective_from_delivery_writes_off_as_damage_and_holds_the_stock`,
     * asserting an Adjustment of type `damage` plus a `defective` hold.
     */
    public function test_defective_from_delivery_is_scrapped_only_once_it_is_confirmed(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 10, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'batch_id'    => $b->batch_id,
            'reason'      => IncidentReport::REASON_DEFECTIVE,
            'root_cause'  => 'delivery',
            'quantity'    => 2,
            'narrative'   => 'Crushed in the van, customer refused it.',
        ], (int) $wp->user_id);

        // Inverted: the damaged units stay on the count while they are examined.
        $this->assertSame(10, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);

        $this->inspectAndConfirm($incident, $loc, IncidentItem::OUTCOME_SCRAP);

        $this->assertSame(8, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
        // Inverted: was `stock_holds` with CONDITION_DEFECTIVE. `quarantined`
        // is now the state the goods sit in while a person decides, and `scrap`
        // vs `return_to_supplier` is that decision.
        $this->assertSame(IncidentItem::STATUS_SCRAPPED,
            IncidentItem::where('incident_id', $incident->incident_id)->firstOrFail()->status);
        // Inverted: was an Adjustment of type `damage`.
        $this->assertNull($incident->fresh()->adjustment_id);
        $this->assertSame('delivery', $incident->fresh()->root_cause);
    }

    public function test_a_standalone_report_still_holds_its_batch_out_of_fifo(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 10, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        // The FIFO hold is one rule with no special cases: how the incident was
        // discovered does not change whether the batch is trustworthy.
        $incident = app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'batch_id'    => $b->batch_id,
            'reason'      => IncidentReport::REASON_MISSING,
            'quantity'    => 1,
            'narrative'   => 'One unit missing from the shelf.',
        ], (int) $wp->user_id);

        $this->assertNull($incident->source_id);

        $this->assertContains(
            (int) $b->batch_id,
            app(IncidentService::class)->excludedBatchIds($loc->location_id)
        );
    }

    public function test_an_other_incident_needs_no_product_and_posts_no_artifact(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');

        $incident = app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'reason'      => IncidentReport::REASON_OTHER,
            'narrative'   => 'Cold-room door lock is broken, anyone can walk in.',
        ], (int) $wp->user_id);

        $this->assertNull($incident->product_id);
        // quantity stays NOT NULL — an `other` incident stores 0, never null.
        $this->assertSame(0, (int) $incident->quantity);

        // Acknowledge sends the existing 'confirm' action; only the label differs.
        // Moved from /resolve to /decide, and it still lands directly on
        // Resolved: `other` is inspection-exempt, so there is no intervening
        // Awaiting Inspection to walk through.
        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/decide", [
                 'action' => 'confirm', 'resolution_note' => 'Maintenance ticket raised.',
             ])->assertOk();

        $fresh = $incident->fresh();
        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $fresh->status_id);
        $this->assertNull($fresh->adjustment_id, 'acknowledging is not a stock movement');
        $this->assertNull($fresh->correction_id);
        // …and it quarantines nothing, because there is nothing physical to hold.
        $this->assertSame(0, IncidentItem::where('incident_id', $fresh->incident_id)->count());
    }

    public function test_a_stock_affecting_reason_still_requires_a_product(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');

        $this->expectException(\InvalidArgumentException::class);

        app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'reason'      => IncidentReport::REASON_MISSING,
            'quantity'    => 2,
            'narrative'   => 'Something went missing but I did not say what.',
        ], (int) $wp->user_id);
    }

    public function test_the_endpoint_accepts_a_standalone_report_with_no_source_id(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();

        $res = $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'reason'      => 'missing',
            'quantity'    => 2,
            'narrative'   => 'Two units gone from the aisle.',
            'occurred_at' => now()->subDay()->format('Y-m-d H:i:s'),
        ]);

        $res->assertCreated();
        $this->assertStringStartsWith('INC-', $res->json('data.incident_number'));
    }

    /**
     * The whole point of collapsing eight reasons into six is that the nuance
     * moved into columns. That only works if the columns survive the trip:
     * IncidentService::file() builds an EXPLICIT column list, so a field added
     * to $fillable and to the validator alone is accepted with a 201 and then
     * silently dropped — which looks exactly like success from the client.
     */
    public function test_a_suspected_cause_survives_the_round_trip_through_the_endpoint(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();

        $res = $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type'         => 'standalone',
            'location_id'         => $loc->location_id,
            'product_id'          => $p->product_id,
            'reason'              => 'missing',
            'quantity'            => 2,
            'narrative'           => 'Two units gone from the aisle, side door was unlatched.',
            'last_known_location' => 'Zone A-12',
            'suspected_cause'     => 'theft',
        ]);
        $res->assertCreated();

        $incidentId = (int) $res->json('data.incident_id');

        // The stored row, not the response echo — the response is built from the
        // model instance and would report the value even if the column did not
        // take it.
        $this->assertDatabaseHas('incident_reports', [
            'incident_id'         => $incidentId,
            'reason'              => 'missing',
            'suspected_cause'     => 'theft',
            'last_known_location' => 'Zone A-12',
        ]);

        // …and it is readable again, so the list and the resolve drawer can say
        // "Missing — suspected theft" rather than a bare "Missing".
        $row = collect($this->actingAs($ia, 'sanctum')->getJson('/api/incident-reports?status=open')->assertOk()->json('data'))
            ->firstWhere('incident_id', $incidentId);

        $this->assertNotNull($row);
        $this->assertSame('theft', $row['suspected_cause']);
        $this->assertSame('Zone A-12', $row['last_known_location']);
        $this->assertNull($row['root_cause'], 'a missing report carries no root cause');
    }

    public function test_a_return_carries_its_counterparty_and_reason_and_a_defective_its_root_cause(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();

        $defective = $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'reason'      => 'defective',
            'quantity'    => 1,
            'narrative'   => 'Compressor housing cracked in the rack.',
            'root_cause'  => 'internal',
        ])->assertCreated();

        $this->assertDatabaseHas('incident_reports', [
            'incident_id' => (int) $defective->json('data.incident_id'),
            'root_cause'  => 'internal',
        ]);

        $return = $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type'      => 'standalone',
            'location_id'      => $loc->location_id,
            'product_id'       => $p->product_id,
            'reason'           => 'return',
            'quantity'         => 3,
            'narrative'           => 'Customer sent three back, wrong model on the delivery.',
            'return_counterparty' => 'customer',
            'return_reason_code'  => 'wrong_product',
            'return_reason'       => 'wrong model delivered',
        ])->assertCreated();

        // Was asserted on return_direction, which conflated WHO the counterparty
        // was with WHICH WAY the goods travel and could not express an
        // interbranch return at all. The two facts are now stored separately and
        // the direction is derived from the counterparty.
        $this->assertDatabaseHas('incident_reports', [
            'incident_id'         => (int) $return->json('data.incident_id'),
            'return_counterparty' => 'customer',
            'return_reason_code'  => 'wrong_product',
            'return_reason'       => 'wrong model delivered',
        ]);

        // A customer return is INBOUND: the three units were issued out long ago
        // and are in no batch, so the item binds to none and nothing is
        // deducted. This used to quarantine a batch the units were not in.
        $item = IncidentItem::where('incident_id', (int) $return->json('data.incident_id'))->firstOrFail();
        $this->assertTrue((bool) $item->is_inbound);
        $this->assertNull($item->batch_id);
    }

    public function test_a_cause_outside_the_allowed_set_is_rejected(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();

        // The columns are plain strings, so the validator is the only thing
        // keeping a typo out of shrinkage reporting.
        $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type'      => 'standalone',
            'location_id'      => $loc->location_id,
            'product_id'       => $p->product_id,
            'reason'           => 'missing',
            'quantity'         => 1,
            'narrative'        => 'Filed with a cause nobody defined.',
            'suspected_cause'     => 'stolen',      // not one of SUSPECTED_CAUSES
            // Was `return_direction: sideways`. The vocabulary being guarded is
            // now the counterparty's, and the column is still a plain string, so
            // the validator remains the only thing keeping a typo out.
            'return_counterparty' => 'sideways',   // not one of RETURN_COUNTERPARTIES
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['suspected_cause', 'return_counterparty']);
    }

    public function test_the_endpoint_rejects_an_occurrence_in_the_future(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();

        $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'reason'      => 'missing',
            'quantity'    => 1,
            'narrative'   => 'It will be stolen tomorrow.',
            'occurred_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ])->assertStatus(422);
    }

    public function test_a_product_less_other_incident_serializes_product_id_as_null(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');

        $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'reason'      => 'other',
            'narrative'   => 'Cold-room door lock is broken.',
        ])->assertCreated();

        $res = $this->actingAs($ia, 'sanctum')->getJson('/api/incident-reports?status=open');
        $res->assertOk();

        $row = collect($res->json('data'))->firstWhere('reason', 'other');
        $this->assertNotNull($row);
        // (int) null would report product #0 — a real product id — as if it were
        // the subject of the incident.
        $this->assertNull($row['product_id'], 'a product-less incident must not report product 0');
        $this->assertNull($row['source_id']);
        $this->assertSame(0, $row['quantity']);
    }

    public function test_an_other_incident_accepts_an_explicit_quantity_of_zero(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');

        // A form holding quantity in state posts 0 rather than omitting the key.
        // `min:1` must be lifted for `other`, not merely bypassed when absent.
        $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'reason'      => 'other',
            'quantity'    => 0,
            'narrative'   => 'Fire exit is blocked by pallets.',
        ])->assertCreated();
    }

    public function test_required_unless_is_not_satisfied_by_an_explicit_null(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');

        // `nullable` must not disarm `required_unless`. Relaxing either rule to
        // `sometimes` or `exclude_if` would let a product-less theft through.
        $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'reason'      => 'missing',
            'product_id'  => null,
            'quantity'    => null,
            'narrative'   => 'Something went missing but I named neither what nor how many.',
        ])->assertStatus(422)->assertJsonValidationErrors(['product_id', 'quantity']);

        // A sourced report still has to say which transaction it came from.
        $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'issuance',
            'location_id' => $loc->location_id,
            'reason'      => 'other',
            'narrative'   => 'Filed against an issuance I did not identify.',
        ])->assertStatus(422)->assertJsonValidationErrors(['source_id']);
    }

    public function test_a_branch_manager_can_file_but_not_resolve(): void
    {
        $loc = $this->makeLocation();
        $bm  = $this->makeUserAt($loc, 'branch_manager');
        $p   = $this->makeConsumable();

        $res = $this->actingAs($bm, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'reason'      => 'missing',
            'quantity'    => 1,
            'narrative'   => 'Noticed a unit missing during the morning walk.',
        ]);
        $res->assertCreated();

        // Maker-checker: filing a write-off and approving it stay different people.
        // Moved from /resolve to /decide, and the separation is now sharper —
        // a branch_manager holds `inspect` but not `resolve`, so they can
        // examine the goods and still cannot confirm the write-off.
        $this->actingAs($bm, 'sanctum')
             ->postJson("/api/incident-reports/{$res->json('data.incident_id')}/decide", ['action' => 'confirm'])
             ->assertForbidden();
    }

    public function test_a_read_only_auditor_cannot_file(): void
    {
        $loc = $this->makeLocation();
        $au  = $this->makeUserAt($loc, 'auditor');
        $p   = $this->makeConsumable();

        $this->actingAs($au, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'reason'      => 'missing',
            'quantity'    => 1,
            'narrative'   => 'Writing rows would break the read-only guarantee.',
        ])->assertForbidden();
    }

    /**
     * The app runs in UTC but devices do not. A phone in UTC+8 sending its own
     * wall-clock time as a bare "Y-m-d H:i:s" looked eight hours in the future
     * and every filing 422'd on before_or_equal:now. The client now sends an
     * ISO 8601 instant WITH its offset, which compares correctly from any zone.
     */
    public function test_an_occurrence_from_a_non_utc_device_is_accepted(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();

        $deviceNow = now()->setTimezone('Asia/Manila')->toIso8601String();

        $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'reason'      => 'missing',
            'quantity'    => 1,
            'narrative'   => 'Filed from a phone in UTC+8.',
            'occurred_at' => $deviceNow,
        ])->assertCreated();
    }

    public function test_a_genuinely_future_occurrence_is_still_rejected_with_an_offset(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();

        // Same ISO-with-offset shape, but actually in the future — the guard
        // must survive the timezone fix rather than being loosened by it.
        $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'reason'      => 'missing',
            'quantity'    => 1,
            'narrative'   => 'Tomorrow, allegedly.',
            'occurred_at' => now()->addDay()->setTimezone('Asia/Manila')->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors(['occurred_at']);
    }
}
