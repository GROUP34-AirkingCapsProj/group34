<?php

namespace Tests\Feature\Transfer;

use App\Models\ConsumableDetail;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TransferMaintainsPackagesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_setPieces_keeps_packages_in_step_when_stock_leaves(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Src', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Tube',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Roll', 'is_serialized' => 0,
        ]);

        $inv = Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 500, 'packages_on_hand' => 10,
            'available_quantity' => 500, 'reorder_level' => 0,
        ]);

        $inv->setPieces((int) $inv->quantity_on_hand - 120, 50);
        $inv->save();

        $fresh = $inv->fresh();
        $this->assertSame(380, $fresh->quantity_on_hand);
        $this->assertSame(7, $fresh->packages_on_hand, 'packages must follow stock out, which transfers never did before');
        $this->assertSame(380, $fresh->available_quantity);
    }
}
