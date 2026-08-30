<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * FIFO enforcement asks a narrower question than availability does: is THIS
 * batch quarantined? quarantinedQuantities() sums that detail away per product.
 */
class QuarantinedByBatchTest extends TestCase
{
    use DatabaseTransactions;

    public function test_quarantined_pieces_are_reported_per_batch(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        $batch = InventoryBatch::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'receiving_id' => null, 'quantity_received' => 100, 'quantity_remaining' => 100,
            'received_at' => '2026-03-12', 'zone_label' => 'A-03',
        ]);

        $incident = IncidentReport::create([
            'incident_number' => 'INC-TEST-' . uniqid(), 'source_type' => 'standalone',
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'reason' => 'defective', 'quantity' => 40, 'narrative' => 'test',
            'reported_by' => (int) \App\Models\User::query()->firstOrFail()->getKey(),
            'status_id' => IncidentReport::statusId('Open'),
        ]);

        IncidentItem::create([
            'incident_id' => $incident->incident_id,
            'product_id'  => $product->product_id,
            'batch_id'    => $batch->batch_id,
            'is_inbound'  => false,
            'quantity'    => 40,
            'status'      => IncidentItem::STATUS_QUARANTINED,
        ]);

        $byBatch = IncidentItem::quarantinedByBatch((int) $location->location_id);

        $this->assertSame(40, $byBatch[$batch->batch_id] ?? null);
    }
}
