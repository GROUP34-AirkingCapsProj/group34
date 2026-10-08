<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Services\Ledger\BatchCostResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BatchCostResolverTest extends TestCase
{
    use DatabaseTransactions;

    private function resolver(): BatchCostResolver
    {
        return app(BatchCostResolver::class);
    }

    /**
     * products.cost_price is NOT NULL DEFAULT 0.00, so "no list cost recorded"
     * is 0.00 in this schema, not null. Passing null here omits the column and
     * lets the default stand, which is what an unpriced product really looks like.
     */
    private function product(?float $costPrice): Product
    {
        return Product::create(array_filter([
            'product_code' => 'P-' . uniqid(),
            'product_name' => 'Test Product',
            'product_type' => 'installation_material',
            'cost_price'   => $costPrice,
        ], fn ($value) => $value !== null));
    }

    private function poDetail(Product $product, float $unitPrice): PurchaseOrderDetail
    {
        $location = Location::create([
            'location_code' => 'L-' . uniqid(), 'location_name' => 'Loc', 'location_type' => 'warehouse',
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);

        return PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 10,
            'unit_price' => $unitPrice, 'subtotal' => $unitPrice * 10,
        ]);
    }

    public function test_a_po_line_price_is_the_actual_cost(): void
    {
        $product = $this->product(99.00);

        $cost = $this->resolver()->costForReceivingLine($this->poDetail($product, 120.50), $product);

        $this->assertSame(120.50, $cost['unit_cost']);
        $this->assertSame('actual', $cost['cost_basis']);
    }

    public function test_without_a_po_line_it_falls_back_to_the_product_list_cost_and_says_so(): void
    {
        $cost = $this->resolver()->costForReceivingLine(null, $this->product(80.00));

        $this->assertSame(80.00, $cost['unit_cost']);
        $this->assertSame('product_default', $cost['cost_basis']);
    }

    public function test_with_no_price_anywhere_the_cost_is_unavailable(): void
    {
        $cost = $this->resolver()->costForReceivingLine(null, $this->product(null));

        $this->assertNull($cost['unit_cost']);
        $this->assertSame('unavailable', $cost['cost_basis']);
    }

    private function batch(?float $unitCost, ?string $basis, int $received): InventoryBatch
    {
        $product  = $this->product(10.00);
        $location = Location::create([
            'location_code' => 'L-' . uniqid(), 'location_name' => 'Loc', 'location_type' => 'warehouse',
        ]);

        return InventoryBatch::create([
            'product_id'         => $product->product_id,
            'location_id'        => $location->location_id,
            'receiving_id'       => null,
            'quantity_received'  => $received,
            'quantity_remaining' => $received,
            'received_at'        => '2026-09-01',
            'unit_cost'          => $unitCost,
            'cost_basis'         => $basis,
        ]);
    }

    public function test_an_empty_batch_simply_takes_the_line_cost(): void
    {
        $blend = $this->resolver()->blendIntoBatch($this->batch(null, null, 0), 10, 100.00, 'actual');

        $this->assertSame(100.00, $blend['unit_cost']);
        $this->assertSame('actual', $blend['cost_basis']);
    }

    public function test_the_same_price_arriving_again_does_not_downgrade_the_basis(): void
    {
        $blend = $this->resolver()->blendIntoBatch($this->batch(100.00, 'actual', 10), 5, 100.00, 'actual');

        $this->assertSame(100.00, $blend['unit_cost']);
        $this->assertSame('actual', $blend['cost_basis']);
    }

    public function test_a_different_price_produces_a_weighted_average_and_says_it_is_derived(): void
    {
        // 10 @ 100 plus 10 @ 200 = 20 @ 150
        $blend = $this->resolver()->blendIntoBatch($this->batch(100.00, 'actual', 10), 10, 200.00, 'actual');

        $this->assertSame(150.00, $blend['unit_cost']);
        $this->assertSame('derived_weighted_avg', $blend['cost_basis']);
    }

    public function test_an_unpriced_addition_leaves_the_existing_cost_but_marks_it_derived(): void
    {
        $blend = $this->resolver()->blendIntoBatch($this->batch(100.00, 'actual', 10), 5, null, 'unavailable');

        $this->assertSame(100.00, $blend['unit_cost']);
        $this->assertSame('derived_weighted_avg', $blend['cost_basis']);
    }
}
