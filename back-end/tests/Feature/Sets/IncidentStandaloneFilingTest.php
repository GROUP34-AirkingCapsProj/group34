<?php

namespace Tests\Feature\Sets;

use App\Models\Adjustment;
use App\Models\IncidentReport;
use App\Models\InventoryBatch;
use App\Services\IncidentService;

class IncidentStandaloneFilingTest extends SetTestCase
{
    public function test_theft_writes_off_as_a_loss(): void
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
            'reason'      => IncidentReport::REASON_THEFT,
            'quantity'    => 4,
            'narrative'   => 'Four units gone overnight, side door was unlatched.',
            'occurred_at' => now()->subDay(),
        ], (int) $wp->user_id);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $adjustment = Adjustment::find($incident->fresh()->adjustment_id);
        $this->assertSame('loss', $adjustment->adjustment_type);
        $this->assertSame(6, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
    }

    public function test_delivery_damage_writes_off_as_damage(): void
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
            'reason'      => IncidentReport::REASON_DELIVERY_DAMAGE,
            'quantity'    => 2,
            'narrative'   => 'Crushed in the van, customer refused it.',
        ], (int) $wp->user_id);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $this->assertSame('damage', Adjustment::find($incident->fresh()->adjustment_id)->adjustment_type);
        $this->assertSame(8, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
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
            'reason'      => IncidentReport::REASON_THEFT,
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
            'reason'      => IncidentReport::REASON_THEFT,
            'quantity'    => 2,
            'narrative'   => 'Something went missing but I did not say what.',
        ], (int) $wp->user_id);
    }
}
