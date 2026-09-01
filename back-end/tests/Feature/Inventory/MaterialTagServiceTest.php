<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Services\MaterialTagService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MaterialTagServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function makeLocation(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
    }

    private function makeProduct(string $countUnit): Product
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Material',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 100,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => $countUnit,
        ]);

        // Force a fresh load so consumableDetail (and count_unit) is not stale/default.
        return Product::with('consumableDetail')->findOrFail($product->product_id);
    }

    private function makeBatch(Product $product, Location $location, int $qty = 600): InventoryBatch
    {
        return InventoryBatch::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'quantity_received' => $qty, 'quantity_remaining' => $qty, 'received_at' => now(),
        ]);
    }

    public function test_package_granularity_mints_one_tag_per_package(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeProduct('package');
        $this->assertSame('package', $product->consumableDetail->count_unit);
        $batch    = $this->makeBatch($product, $location);

        $service = app(MaterialTagService::class);
        $tags    = $service->mintForCount($product, $batch, $location->location_id, 6, 'PO-2401');

        $this->assertCount(6, $tags);
        $this->assertSame(6, ItemSerial::where('batch_id', $batch->batch_id)->count());

        foreach ($tags as $tag) {
            $this->assertStringStartsWith(
                "INT-PO-2401-{$product->product_id}-",
                $tag->serial_number
            );
        }
    }

    public function test_piece_granularity_mints_a_single_tag_for_the_whole_lot(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeProduct('piece');
        $this->assertSame('piece', $product->consumableDetail->count_unit);
        $batch    = $this->makeBatch($product, $location);

        $service = app(MaterialTagService::class);
        $tags    = $service->mintForCount($product, $batch, $location->location_id, 473, 'PO-2401');

        $this->assertCount(
            1,
            $tags,
            'Receiving 473 bolts by piece count must mint exactly one lot tag, not 473 individual tags.'
        );
    }

    public function test_scan_path_carries_both_identifiers(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeProduct('package');
        $batch    = $this->makeBatch($product, $location);

        $service = app(MaterialTagService::class);
        $tag = $service->mintForScan($product, $batch, $location->location_id, 'SUPPLIER-BC-123', 'RCV-001');

        $this->assertSame('SUPPLIER-BC-123', $tag->supplier_barcode);
        // Design change: a generated barcode exists only because an item
        // arrived without one. A scanned supplier code IS the identifier now,
        // so serial_number carries the scanned code verbatim instead of a
        // separately-minted INT- tag.
        $this->assertSame('SUPPLIER-BC-123', $tag->serial_number);
        $this->assertSame($batch->batch_id, $tag->batch_id);
    }

    public function test_scan_path_ignores_count_unit_and_mints_one_tag_per_call(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeProduct('piece');
        $this->assertSame('piece', $product->consumableDetail->count_unit);
        $batch    = $this->makeBatch($product, $location);

        $service = app(MaterialTagService::class);
        $tag1 = $service->mintForScan($product, $batch, $location->location_id, 'SUP-A', 'RCV-001');
        $tag2 = $service->mintForScan($product, $batch, $location->location_id, 'SUP-B', 'RCV-001');

        $this->assertNotSame($tag1->serial_number, $tag2->serial_number);
        $this->assertSame(2, ItemSerial::where('batch_id', $batch->batch_id)->count());
    }

    public function test_sequence_is_unique_across_multiple_receipts_on_same_batch(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeProduct('package');
        $batch    = $this->makeBatch($product, $location);

        $service = app(MaterialTagService::class);
        $first  = $service->mintForCount($product, $batch, $location->location_id, 3, 'PO-2401');
        $second = $service->mintForCount($product, $batch, $location->location_id, 3, 'PO-2401');

        $allSerials = array_merge(
            array_map(fn ($t) => $t->serial_number, $first),
            array_map(fn ($t) => $t->serial_number, $second)
        );

        $this->assertCount(6, array_unique($allSerials), 'All 6 serials across two receipts must be distinct.');
    }

    public function test_every_tag_resolves_to_its_batch(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeProduct('package');
        $batch    = $this->makeBatch($product, $location);

        $service = app(MaterialTagService::class);
        $tags = $service->mintForCount($product, $batch, $location->location_id, 2, 'PO-2401');

        foreach ($tags as $tag) {
            $this->assertSame($batch->batch_id, $tag->batch->batch_id);
        }
    }

    public function test_two_batches_for_the_same_product_and_reference_do_not_collide(): void
    {
        // One PO, one product, received into two locations: ReceivingPostingService
        // creates one batch per receiving+product+location, so two batches sharing
        // the same product_id and referenceLabel is a routine case, not exotic.
        $locationA = $this->makeLocation();
        $locationB = $this->makeLocation();
        $product   = $this->makeProduct('package');
        $batchA    = $this->makeBatch($product, $locationA);
        $batchB    = $this->makeBatch($product, $locationB);

        $service = app(MaterialTagService::class);

        $tagsA = $service->mintForCount($product, $batchA, $locationA->location_id, 1, 'PO-2401');
        $tagsB = $service->mintForCount($product, $batchB, $locationB->location_id, 1, 'PO-2401');

        $this->assertNotSame(
            $tagsA[0]->serial_number,
            $tagsB[0]->serial_number,
            'Two batches for the same product and reference label must not mint colliding serials.'
        );
    }

    public function test_sequence_gap_is_skipped_over_on_retry(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeProduct('package');
        $batch    = $this->makeBatch($product, $location);

        $service = app(MaterialTagService::class);

        $tags = $service->mintForCount($product, $batch, $location->location_id, 3, 'PO-2401');
        $this->assertCount(3, $tags);

        // Delete the middle tag, leaving a gap in the sequence (01 and 03 remain).
        $tags[1]->delete();

        $more = $service->mintForCount($product, $batch, $location->location_id, 1, 'PO-2401');

        $this->assertCount(1, $more);
        $remainingSerials = ItemSerial::where('batch_id', $batch->batch_id)->pluck('serial_number')->all();
        $this->assertSame(count($remainingSerials), count(array_unique($remainingSerials)));
    }

    public function test_reference_label_with_like_metacharacters_does_not_inflate_the_count(): void
    {
        // '_' is a single-character SQL LIKE wildcard. If nextSequence() ever
        // uses a raw LIKE against an unescaped prefix, a reference label of
        // 'PO_2401' would also match rows minted under 'POX2401' (or any
        // other single character in that position), inflating the count and
        // skipping sequence numbers. Prove it does not.
        $location = $this->makeLocation();
        $product  = $this->makeProduct('package');
        $batch    = $this->makeBatch($product, $location);

        $service = app(MaterialTagService::class);

        // Mint under a reference label the unescaped wildcard would wrongly
        // match against 'PO_2401' ('_' matching 'X').
        $service->mintForCount($product, $batch, $location->location_id, 5, 'POX2401');

        $tags = $service->mintForCount($product, $batch, $location->location_id, 1, 'PO_2401');

        $this->assertCount(1, $tags);
        $this->assertSame(
            "INT-PO_2401-{$product->product_id}-01",
            $tags[0]->serial_number,
            'The unrelated POX2401 rows must not inflate the PO_2401 sequence count.'
        );
    }
}
