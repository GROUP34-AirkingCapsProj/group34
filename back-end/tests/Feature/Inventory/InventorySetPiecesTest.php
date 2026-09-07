<?php

namespace Tests\Feature\Inventory;

use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InventorySetPiecesTest extends TestCase
{
    use DatabaseTransactions;

    private function makeInventory(int $qty, int $available): Inventory
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Installation material',
            'product_type' => 'installation_material',
        ]);

        return Inventory::create([
            'location_id'        => $location->location_id,
            'product_id'         => $product->product_id,
            'quantity_on_hand'   => $qty,
            'packages_on_hand'   => 0,
            'available_quantity' => $available,
            'reorder_level'      => 0,
        ]);
    }

    public function test_it_stores_pieces_and_derives_packages_by_floor_division(): void
    {
        $inv = $this->makeInventory(0, 0);

        $inv->setPieces(473, 100);

        $this->assertSame(473, $inv->quantity_on_hand);
        $this->assertSame(4, $inv->packages_on_hand, 'packages must floor, never round up');
    }

    public function test_it_moves_available_quantity_by_the_delta_not_by_assignment(): void
    {
        // 30 pieces reserved elsewhere: available sits below on-hand.
        $inv = $this->makeInventory(100, 70);

        $inv->setPieces(150, 100);

        $this->assertSame(150, $inv->quantity_on_hand);
        $this->assertSame(120, $inv->available_quantity, 'available must rise by the +50 delta, not reset to parity with on-hand');
    }

    public function test_it_never_drives_available_quantity_negative(): void
    {
        $inv = $this->makeInventory(100, 5);

        $inv->setPieces(0, 100);

        $this->assertSame(0, $inv->quantity_on_hand);
        $this->assertSame(0, $inv->available_quantity);
    }

    public function test_it_treats_zero_pieces_per_package_as_no_packages(): void
    {
        $inv = $this->makeInventory(0, 0);

        $inv->setPieces(50, 0);

        $this->assertSame(50, $inv->quantity_on_hand);
        $this->assertSame(0, $inv->packages_on_hand, 'must not divide by zero');
    }

    public function test_it_clamps_a_negative_piece_count_to_zero(): void
    {
        $inv = $this->makeInventory(100, 100);

        $inv->setPieces(-50, 100);

        $this->assertSame(0, $inv->quantity_on_hand);
        $this->assertSame(0, $inv->packages_on_hand);
        $this->assertSame(0, $inv->available_quantity);
    }

    public function test_it_clamps_negative_pieces_before_computing_the_delta(): void
    {
        // 30 pieces reserved elsewhere: available sits below on-hand.
        $inv = $this->makeInventory(100, 70);

        $inv->setPieces(-20, 100);

        $this->assertSame(0, $inv->quantity_on_hand);
        $this->assertSame(0, $inv->available_quantity, 'clamp must happen before the delta is computed, not after');
    }
}
