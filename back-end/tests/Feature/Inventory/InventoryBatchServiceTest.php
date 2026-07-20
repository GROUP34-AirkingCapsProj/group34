<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\InventoryBatch;
use App\Services\InventoryBatchService;

class InventoryBatchServiceTest extends TestCase
{
    use DatabaseTransactions;

    private InventoryBatchService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new InventoryBatchService();
    }

    private function makeLocation(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Test Loc',
            'location_type' => 'warehouse',
        ]);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'product_code' => 'PRD-' . uniqid(),
            'product_name' => 'Test Product',
            'product_type' => 'installation_material',
        ]);
    }

    private function makeReceiving(int $locationId): Receiving
    {
        return Receiving::create([
            'location_id'      => $locationId,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now(),
        ]);
    }

    public function test_deduct_fifo_consumes_oldest_batch_first(): void
    {
        $loc  = $this->makeLocation();
        $prod = $this->makeProduct();

        $old = $this->svc->createBatch($prod->product_id, $loc->location_id, $this->makeReceiving($loc->location_id)->receiving_id, 5, now()->subDays(10));
        $new = $this->svc->createBatch($prod->product_id, $loc->location_id, $this->makeReceiving($loc->location_id)->receiving_id, 5, now()->subDays(1));

        $touched = $this->svc->deductFifo($prod->product_id, $loc->location_id, 7);

        $this->assertSame(0, $old->fresh()->quantity_remaining);
        $this->assertSame(3, $new->fresh()->quantity_remaining);
        $this->assertSame([$old->batch_id => 5, $new->batch_id => 2], $touched);
    }

    public function test_deduct_fifo_spills_into_legacy_batch_when_batches_insufficient(): void
    {
        $loc  = $this->makeLocation();
        $prod = $this->makeProduct();

        $batch = $this->svc->createBatch($prod->product_id, $loc->location_id, $this->makeReceiving($loc->location_id)->receiving_id, 3, now());

        $this->svc->deductFifo($prod->product_id, $loc->location_id, 5);

        $this->assertSame(0, $batch->fresh()->quantity_remaining);

        $legacy = InventoryBatch::where('product_id', $prod->product_id)
            ->where('location_id', $loc->location_id)
            ->whereNull('receiving_id')
            ->first();

        $this->assertNotNull($legacy);
        $this->assertSame(0, $legacy->quantity_remaining); // floored at 0, never negative
    }

    public function test_restore_fifo_returns_quantity_to_preferred_batch_up_to_its_capacity(): void
    {
        $loc  = $this->makeLocation();
        $prod = $this->makeProduct();

        $batch = $this->svc->createBatch($prod->product_id, $loc->location_id, $this->makeReceiving($loc->location_id)->receiving_id, 5, now());
        $batch->quantity_remaining = 2;
        $batch->save();

        $this->svc->restoreFifo($prod->product_id, $loc->location_id, 4, $batch->receiving_id);

        // Batch had room for 3 (5 received - 2 remaining), so 3 goes back to it, 1 spills to legacy.
        $this->assertSame(5, $batch->fresh()->quantity_remaining);

        $legacy = InventoryBatch::where('product_id', $prod->product_id)
            ->where('location_id', $loc->location_id)
            ->whereNull('receiving_id')
            ->first();
        $this->assertSame(1, $legacy->quantity_remaining);
    }

    public function test_deduct_from_receiving_targets_exact_batch(): void
    {
        $loc  = $this->makeLocation();
        $prod = $this->makeProduct();
        $rcv  = $this->makeReceiving($loc->location_id);

        $batch = $this->svc->createBatch($prod->product_id, $loc->location_id, $rcv->receiving_id, 3, now());

        $this->svc->deductFromReceiving($rcv->receiving_id, $prod->product_id, $loc->location_id, 2);

        $this->assertSame(1, $batch->fresh()->quantity_remaining);
    }

    public function test_deduct_from_receiving_falls_back_to_legacy_when_receiving_id_is_null(): void
    {
        $loc  = $this->makeLocation();
        $prod = $this->makeProduct();

        $this->svc->deductFromReceiving(null, $prod->product_id, $loc->location_id, 5);

        $legacy = InventoryBatch::where('product_id', $prod->product_id)
            ->where('location_id', $loc->location_id)
            ->whereNull('receiving_id')
            ->first();

        $this->assertNotNull($legacy);
        $this->assertSame(0, $legacy->quantity_remaining); // floored at 0, never negative
    }

    public function test_restore_fifo_goes_straight_to_legacy_when_receiving_id_is_null(): void
    {
        $loc  = $this->makeLocation();
        $prod = $this->makeProduct();

        $this->svc->restoreFifo($prod->product_id, $loc->location_id, 4, null);

        $legacy = InventoryBatch::where('product_id', $prod->product_id)
            ->where('location_id', $loc->location_id)
            ->whereNull('receiving_id')
            ->first();

        $this->assertNotNull($legacy);
        $this->assertSame(4, $legacy->quantity_remaining);
    }
}
