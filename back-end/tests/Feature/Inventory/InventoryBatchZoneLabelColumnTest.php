<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;

class InventoryBatchZoneLabelColumnTest extends TestCase
{
    use DatabaseTransactions;

    public function test_zone_label_column_exists_and_round_trips(): void
    {
        $this->assertTrue(Schema::hasColumn('inventory_batches', 'zone_label'));

        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Installation material', 'product_type' => 'installation_material',
        ]);

        $batch = InventoryBatch::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'receiving_id' => null, 'quantity_received' => 5, 'quantity_remaining' => 5,
            'received_at' => now(), 'zone_label' => 'Aisle 3, Shelf B',
        ]);

        $this->assertSame('Aisle 3, Shelf B', $batch->fresh()->zone_label);
    }
}
