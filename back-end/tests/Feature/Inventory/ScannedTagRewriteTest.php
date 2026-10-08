<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ScannedTagRewriteTest extends TestCase
{
    use DatabaseTransactions;

    private function makeTag(string $serial, ?string $supplier): ItemSerial
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'L', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        $batch = InventoryBatch::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'quantity_received' => 50, 'quantity_remaining' => 50, 'received_at' => now(),
        ]);

        return ItemSerial::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'batch_id' => $batch->batch_id, 'serial_number' => $serial,
            'supplier_barcode' => $supplier, 'serial_type' => 'received',
            'status_id' => ItemSerial::IN_STOCK_STATUS_ID,
        ]);
    }

    /** Runs the migration's logic directly, so the test does not depend on migrate state. */
    private function runRewrite(): void
    {
        $migration = require base_path('database/migrations/2026_08_31_000002_rewrite_scanned_material_tags.php');
        $migration->up();
    }

    public function test_a_scanned_tag_is_rewritten_to_its_supplier_code(): void
    {
        $code = 'SUP-' . strtoupper(uniqid());
        $tag  = $this->makeTag('INT-PO-X-1-01', $code);

        $this->runRewrite();

        $fresh = $tag->fresh();
        $this->assertSame($code, $fresh->serial_number);
        $this->assertSame($code, $fresh->supplier_barcode, 'supplier_barcode stays populated after the rewrite.');
        $this->assertNotNull($fresh->batch_id, 'The batch link must survive the rewrite.');
    }

    public function test_a_counted_tag_is_left_alone(): void
    {
        $tag = $this->makeTag('INT-PO-Y-2-01', null);

        $this->runRewrite();

        $this->assertSame('INT-PO-Y-2-01', $tag->fresh()->serial_number);
    }

    public function test_a_collision_is_skipped_not_fatal(): void
    {
        $code = 'SUP-' . strtoupper(uniqid());
        $this->makeTag($code, null);                     // the code already exists as a serial
        $tag = $this->makeTag('INT-PO-Z-3-01', $code);   // this one would collide

        $this->runRewrite();   // must not throw

        $this->assertSame('INT-PO-Z-3-01', $tag->fresh()->serial_number,
            'A colliding row keeps its INT- serial rather than failing the migration halfway.');
    }
}
