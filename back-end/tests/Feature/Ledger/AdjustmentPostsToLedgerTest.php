<?php

namespace Tests\Feature\Ledger;

use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\InventoryLedgerEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Adjustments are where stock quietly appears and disappears, so they are the
 * movements an auditor most wants evidence for. They post at creation because that
 * is when the stock actually moves — this system has no separate approval step.
 */
class AdjustmentPostsToLedgerTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0: Location, 1: Product, 2: User} */
    private function scaffold(int $onHand = 20, float $unitCost = 12.00): array
    {
        $location = Location::create([
            'location_code' => 'L-' . uniqid(), 'location_name' => 'WH', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'M-' . uniqid(), 'product_name' => 'Bolt',
            'product_type' => 'installation_material', 'cost_price' => 12.00,
        ]);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 'adj_' . uniqid(), 'first_name' => 'Adj', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);

        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => $onHand, 'available_quantity' => $onHand, 'reorder_level' => 0,
        ]);

        $rcv = Receiving::create([
            'location_id' => $location->location_id,
            'receiving_number' => 'R-' . uniqid(),
            'receiving_date' => '2026-02-01',
        ]);
        InventoryBatch::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'receiving_id' => $rcv->receiving_id, 'quantity_received' => $onHand,
            'quantity_remaining' => $onHand, 'received_at' => '2026-02-01',
            'unit_cost' => $unitCost, 'cost_basis' => 'actual',
        ]);

        return [$location, $product, $user];
    }

    private function submit(User $user, Location $location, Product $product, int $add, int $deduct)
    {
        return $this->actingAs($user)->postJson('/api/adjustments', [
            'location_id'       => $location->location_id,
            'created_by'        => $user->user_id,
            'adjustment_number' => 'ADJ-' . uniqid(),
            'adjustment_date'   => '2026-09-20',
            'adjustment_type'   => 'count_variance',
            'details'           => [[
                'product_id'      => $product->product_id,
                'add_quantity'    => $add,
                'deduct_quantity' => $deduct,
            ]],
        ]);
    }

    private function rowsFor($res)
    {
        return InventoryLedgerEntry::where('source_type', 'adjustments')
            ->where('source_id', $res->json('data.adjustment_id'))
            ->get();
    }

    public function test_a_positive_adjustment_posts_an_in_row_valued_at_the_batch_cost(): void
    {
        [$location, $product, $user] = $this->scaffold();

        $res = $this->submit($user, $location, $product, 5, 0);
        $res->assertStatus(201);

        $row = $this->rowsFor($res)->firstOrFail();

        $this->assertSame('in', $row->direction);
        $this->assertSame('adjustment', $row->movement_type);
        $this->assertSame(5, (int) $row->quantity);
        $this->assertSame((int) $location->location_id, (int) $row->to_location_id);
        $this->assertNull($row->from_location_id);
        $this->assertSame('12.00', $row->unit_cost);
        $this->assertSame('60.00', $row->extended_value);
        // The reason the adjustment was made is evidence in its own right.
        $this->assertSame('count_variance', $row->notes);
    }

    public function test_a_negative_adjustment_posts_an_out_row(): void
    {
        [$location, $product, $user] = $this->scaffold();

        $res = $this->submit($user, $location, $product, 0, 4);
        $res->assertStatus(201);

        $row = $this->rowsFor($res)->firstOrFail();

        $this->assertSame('out', $row->direction);
        $this->assertSame(4, (int) $row->quantity);
        $this->assertSame((int) $location->location_id, (int) $row->from_location_id);
        $this->assertNull($row->to_location_id);
    }

    public function test_a_net_zero_line_posts_nothing_because_no_stock_moved(): void
    {
        [$location, $product, $user] = $this->scaffold();

        $res = $this->submit($user, $location, $product, 3, 3);
        $res->assertStatus(201);

        $this->assertCount(0, $this->rowsFor($res));
    }

    public function test_a_failed_adjustment_leaves_no_ledger_row(): void
    {
        [$location, $product, $user] = $this->scaffold(2);

        $before = InventoryLedgerEntry::where('source_type', 'adjustments')->count();

        // Deducting more than is on hand throws inside the transaction, so the
        // ledger row must roll back with the adjustment. An unrecorded movement is
        // bad; a recorded movement that never happened is worse.
        $this->submit($user, $location, $product, 0, 50)->assertStatus(500);

        $this->assertSame($before, InventoryLedgerEntry::where('source_type', 'adjustments')->count());
    }
}
