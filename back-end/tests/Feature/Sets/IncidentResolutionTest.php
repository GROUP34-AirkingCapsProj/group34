<?php

namespace Tests\Feature\Sets;

use App\Models\Adjustment;
use App\Models\IncidentReport;
use App\Models\InventoryBatch;
use App\Models\ScanCorrection;
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
            'batch_id'    => $b->batch_id, 'reason' => 'not_found',
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

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_DAMAGED);

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertForbidden();
    }

    public function test_resolving_not_found_posts_a_deduction_adjustment(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_NOT_FOUND, 3);

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
    }

    public function test_dismissing_not_found_returns_the_stock_to_fifo(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_NOT_FOUND, 3);
        $this->assertContains((int) $b->batch_id, app(IncidentService::class)->excludedBatchIds($loc->location_id));

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", [
                 'action' => 'dismiss', 'resolution_note' => 'Found it behind the pallet.',
             ])->assertOk();

        $this->assertSame(IncidentReport::statusId('Dismissed'), (int) $incident->fresh()->status_id);
        $this->assertNull($incident->fresh()->adjustment_id);
        $this->assertSame([], app(IncidentService::class)->excludedBatchIds($loc->location_id));
        $this->assertSame(5, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
    }

    public function test_resolving_damaged_posts_a_deduction_adjustment(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 8, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_DAMAGED, 2);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $this->assertNotNull($incident->fresh()->adjustment_id);
        $this->assertSame(6, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
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

    public function test_resolving_zone_mismatch_only_moves_the_zone_label(): void
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
        $this->assertNull($fresh->adjustment_id, 'a relocation is bookkeeping, not a loss');
        $this->assertNull($fresh->correction_id);

        $batch = InventoryBatch::find($b->batch_id);
        $this->assertSame('Zone 2', $batch->zone_label);
        $this->assertSame(7, (int) $batch->quantity_remaining);
    }

    public function test_resolving_twice_is_rejected(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_DAMAGED, 1);
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

        $this->openIncident($loc, $p, $b, IncidentReport::REASON_NOT_FOUND, 1, (int) $picker->user_id);
        $this->openIncident($loc, $p, $b, IncidentReport::REASON_NOT_FOUND, 1, (int) $picker->user_id);

        $res = $this->actingAs($ia, 'sanctum')->getJson('/api/incident-reports?status=open');

        $res->assertOk();
        $rows = collect($res->json('data'))->where('reported_by', (int) $picker->user_id);
        $this->assertSame(2, (int) $rows->first()['reporter_incident_count']);
    }
}
