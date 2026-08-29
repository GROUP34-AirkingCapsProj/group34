<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ItemSerialTagColumnsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_tag_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('item_serial', 'batch_id'));
        $this->assertTrue(Schema::hasColumn('item_serial', 'supplier_barcode'));
    }

    public function test_one_row_carries_both_identifiers_and_resolves_to_its_batch(): void
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
            'quantity_received' => 100, 'quantity_remaining' => 100, 'received_at' => now(),
        ]);

        $tag = ItemSerial::create([
            'product_id'       => $product->product_id,
            'location_id'      => $location->location_id,
            'batch_id'         => $batch->batch_id,
            'serial_number'    => 'INT-TEST-' . uniqid(),
            'supplier_barcode' => 'SUP-' . uniqid(),
            'serial_type'      => 'received',
        ]);

        $this->assertSame($batch->batch_id, $tag->fresh()->batch->batch_id);
        $this->assertNotNull($tag->fresh()->supplier_barcode);
    }

    public function test_batch_id_is_nullable_and_foreign_key_nulls_on_batch_delete(): void
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
            'quantity_received' => 100, 'quantity_remaining' => 100, 'received_at' => now(),
        ]);

        $tag = ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $location->location_id,
            'batch_id'      => $batch->batch_id,
            'serial_number' => 'INT-TEST-' . uniqid(),
            'serial_type'   => 'received',
        ]);

        $batch->delete();

        $tag->refresh();
        $this->assertNotNull($tag);
        $this->assertNull($tag->batch_id);
    }
}
