<?php

namespace Tests\Feature\Sets;

use App\Models\Adjustment;
use App\Models\IncidentReport;
use App\Models\InventoryBatch;
use App\Models\StockHold;
use App\Services\IncidentService;

class IncidentStandaloneFilingTest extends SetTestCase
{
    /**
     * `theft` stopped being a reason of its own: the type is now `missing` and
     * the theft nuance rides along as `suspected_cause`. The write-off itself
     * is unchanged — an Adjustment of type `loss` — but it now also leaves a
     * `missing` hold behind, because physical = active + held.
     */
    public function test_missing_with_suspected_theft_writes_off_as_a_loss_and_holds_the_stock(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 10, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'batch_id'    => $b->batch_id,
            'reason'      => IncidentReport::REASON_MISSING,
            'quantity'    => 4,
            'narrative'   => 'Four units gone overnight, side door was unlatched.',
            'occurred_at' => now()->subDay(),
        ], (int) $wp->user_id);

        // IncidentService::file() does not yet carry the cause fields through
        // from its payload, so the column is set directly here to model what a
        // real suspected-theft row looks like when resolving it.
        $incident->update(['suspected_cause' => 'theft']);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        // Unchanged: the suspected cause does not change the adjustment type.
        $adjustment = Adjustment::find($incident->fresh()->adjustment_id);
        $this->assertSame('loss', $adjustment->adjustment_type);
        $this->assertSame(6, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);

        // New: the deducted stock is accounted for as a hold rather than simply
        // vanishing, so physical = active + held still holds after a write-off.
        $this->assertDatabaseHas('stock_holds', [
            'incident_id' => $incident->incident_id,
            'condition'   => StockHold::CONDITION_MISSING,
            'quantity'    => 4,
            'status'      => StockHold::STATUS_HELD,
        ]);
    }

    /**
     * `delivery_damage` collapsed into `defective` with `root_cause: delivery`.
     * The adjustment type is still `damage`; the new part is the `defective`
     * hold, which is what a later inspection disposes of.
     */
    public function test_defective_from_delivery_writes_off_as_damage_and_holds_the_stock(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 10, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'batch_id'    => $b->batch_id,
            'reason'      => IncidentReport::REASON_DEFECTIVE,
            'quantity'    => 2,
            'narrative'   => 'Crushed in the van, customer refused it.',
        ], (int) $wp->user_id);

        // See the note above: file() does not persist the cause fields yet.
        $incident->update(['root_cause' => 'delivery']);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        // Unchanged: the root cause does not change the adjustment type.
        $this->assertSame('damage', Adjustment::find($incident->fresh()->adjustment_id)->adjustment_type);
        $this->assertSame(8, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);

        // New: the damaged units are held for inspection, not written out of
        // existence — a `defective` hold can still go back to the supplier.
        $this->assertDatabaseHas('stock_holds', [
            'incident_id' => $incident->incident_id,
            'condition'   => StockHold::CONDITION_DEFECTIVE,
            'quantity'    => 2,
            'status'      => StockHold::STATUS_HELD,
        ]);
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
        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", [
                 'action' => 'confirm', 'resolution_note' => 'Maintenance ticket raised.',
             ])->assertOk();

        $fresh = $incident->fresh();
        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $fresh->status_id);
        $this->assertNull($fresh->adjustment_id, 'acknowledging is not a stock movement');
        $this->assertNull($fresh->correction_id);
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
        $this->actingAs($bm, 'sanctum')
             ->postJson("/api/incident-reports/{$res->json('data.incident_id')}/resolve", ['action' => 'confirm'])
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
