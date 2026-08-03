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

    public function test_a_return_with_no_batch_lands_in_the_legacy_batch(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();

        $incident = $this->openReturn($loc, $p, null, 2);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $legacy = InventoryBatch::where('product_id', $p->product_id)
            ->where('location_id', $loc->location_id)
            ->whereNull('receiving_id')
            ->get();

        // receiving_id null is an identity in this codebase, not an absence:
        // InventoryBatchService::legacyBatch() looks the catch-all up by that
        // key. A second null-receiving row would make which batch it finds
        // arbitrary, so the count is the assertion that matters.
        $this->assertCount(1, $legacy, 'the legacy batch must stay a singleton');
        $this->assertSame(2, (int) $legacy->first()->quantity_remaining);
        $this->assertNull($legacy->first()->receiving_id);

        $inv = Inventory::where('location_id', $loc->location_id)->where('product_id', $p->product_id)->first();
        $this->assertNotNull($inv, 'a return into a product with no stock row must create one');
        $this->assertSame(2, (int) $inv->quantity_on_hand);
        $this->assertSame(2, (int) $inv->available_quantity);
    }

    public function test_a_returned_serial_goes_back_in_stock(): void
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

        // Stock came back exactly once.
        $this->assertSame(8, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
    }
}
