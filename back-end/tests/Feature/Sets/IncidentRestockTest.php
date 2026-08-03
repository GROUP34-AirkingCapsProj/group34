<?php

namespace Tests\Feature\Sets;

use App\Models\Adjustment;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Services\IncidentService;

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
            'reason'      => IncidentReport::REASON_CUSTOMER_RETURN,
            'quantity'    => $qty,
            'narrative'   => 'Wrong model delivered, customer sent it back.',
        ], (int) $wp->user_id);
    }

    public function test_a_return_puts_stock_back_on_its_original_batch(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();

        $this->setStock($loc->location_id, $p->product_id, 20);
        $b = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(30), 'zone_label' => 'Zone 1']);

        $incident = $this->openReturn($loc, $p, $b, 3);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", [
                 'action' => 'confirm', 'resolution_note' => 'Inspected, back on the shelf.',
             ])->assertOk();

        $fresh = $incident->fresh();
        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $fresh->status_id);
        $this->assertNotNull($fresh->adjustment_id);

        // The stock returns to the batch it left, so its true received_at — and
        // therefore its place in FIFO order — is preserved.
        $this->assertSame(8, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
        $this->assertSame(1, (int) InventoryBatch::where('product_id', $p->product_id)->count());

        $inv = Inventory::where('location_id', $loc->location_id)->where('product_id', $p->product_id)->first();
        $this->assertSame(23, (int) $inv->quantity_on_hand);
        $this->assertSame(23, (int) $inv->available_quantity);
    }

    public function test_the_adjustment_records_an_addition(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openReturn($loc, $p, $b, 4);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $adjustment = Adjustment::with('details')->find($incident->fresh()->adjustment_id);
        $this->assertSame('return', $adjustment->adjustment_type);
        $this->assertSame(4, (int) $adjustment->total_variance_positive);
        $this->assertSame(0, (int) $adjustment->total_variance_negative);
        $this->assertSame(4, (int) $adjustment->details->first()->add_quantity);
        $this->assertSame(0, (int) $adjustment->details->first()->deduct_quantity);
    }

    public function test_a_return_with_no_batch_creates_one(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();

        $incident = $this->openReturn($loc, $p, null, 2);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $batch = InventoryBatch::where('product_id', $p->product_id)
            ->where('location_id', $loc->location_id)
            ->first();

        $this->assertNotNull($batch, 'returned stock with no batch must land in a new one');
        $this->assertNull($batch->receiving_id, 'a return did not come from a receiving');
        $this->assertSame(2, (int) $batch->quantity_remaining);
        $this->assertSame(2, (int) $batch->quantity_received);
    }

    public function test_a_returned_serial_goes_back_in_stock(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeAppliance();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $serial = $this->makeSerial($p, $loc, 'SN-RETURN-001', $b->receiving_id);
        $serial->update(['status_id' => 27]); // anything other than in-stock

        $incident = $this->openReturn($loc, $p, $b, 1, (int) $serial->serial_id);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $this->assertSame(
            ItemSerial::IN_STOCK_STATUS_ID,
            (int) ItemSerial::find($serial->serial_id)->status_id
        );
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

        // Stock came back exactly once.
        $this->assertSame(8, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
    }
}
