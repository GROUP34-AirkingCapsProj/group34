<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Models\PurchaseOrder;

class PoInventoryGroupsTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeUser(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        return User::create([
            'username'      => 'tester_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'User',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]);
    }

    protected function makePo(int $locationId): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_number'           => 'PO-' . uniqid(),
            'location_id'         => $locationId,
            'verification_status' => 'verified',
            'status_id'           => 1,
            'order_date'          => now()->toDateString(),
        ]);
    }

    protected function receive(User $u, PurchaseOrder $po, int $loc, int $prod, int $qty, string $bc, ?string $zone = 'Location 1'): void
    {
        $this->actingAs($u)->postJson('/api/receivings', [
            'pc_id'            => $po->po_id,
            'location_id'      => $loc,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now()->toDateString(),
            'details'          => [[
                'product_id'      => $prod,
                'quantity_amount' => $qty,
                'condition'       => 'Good',
                'scanned_barcode' => $bc,
                'zone_label'      => $zone,
            ]],
        ])->assertStatus(201);
    }

    public function test_lists_one_group_per_po_and_warehouse_with_nested_batches(): void
    {
        $wh = Location::create(['location_code' => 'WH-' . uniqid(), 'location_name' => 'Main WH', 'location_type' => 'warehouse']);
        $p1 = Product::create(['product_code' => 'C1-' . uniqid(), 'product_name' => 'Cons One', 'product_type' => 'installation_material']);
        $p2 = Product::create(['product_code' => 'C2-' . uniqid(), 'product_name' => 'Cons Two', 'product_type' => 'installation_material']);
        $user = $this->makeUser();

        $po = $this->makePo($wh->location_id);
        $this->receive($user, $po, $wh->location_id, $p1->product_id, 12, 'BC-1-' . uniqid());
        $this->receive($user, $po, $wh->location_id, $p2->product_id, 8, 'BC-2-' . uniqid());

        $groups = $this->actingAs($user)
            ->getJson('/api/po-inventory-groups')
            ->assertStatus(200)
            ->json('data');

        $mine = collect($groups)->firstWhere('po_id', $po->po_id);
        $this->assertNotNull($mine);
        $this->assertSame($po->po_number, $mine['po_number']);
        $this->assertSame($wh->location_id, $mine['warehouse_id']);
        $this->assertSame(2, $mine['item_count']);
        $this->assertSame(20, $mine['total_received']);
        $this->assertCount(2, $mine['batches']);
    }
}
