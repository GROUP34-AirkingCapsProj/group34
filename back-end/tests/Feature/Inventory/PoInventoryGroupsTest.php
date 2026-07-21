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

    public function test_group_detail_returns_scoped_serials_and_overview(): void
    {
        $wh = Location::create(['location_code' => 'WH-' . uniqid(), 'location_name' => 'Main WH', 'location_type' => 'warehouse']);
        $appliance = Product::create(['product_code' => 'A-' . uniqid(), 'product_name' => 'Aircon', 'product_type' => 'appliance']);
        $user = $this->makeUser();

        $poA = $this->makePo($wh->location_id);
        $poB = $this->makePo($wh->location_id);
        $snA = 'SNA-' . uniqid();
        $snB = 'SNB-' . uniqid();
        $this->receive($user, $poA, $wh->location_id, $appliance->product_id, 1, $snA);
        $this->receive($user, $poB, $wh->location_id, $appliance->product_id, 1, $snB);

        $detail = $this->actingAs($user)
            ->getJson('/api/po-inventory-groups/' . $poA->po_id . '?location_id=' . $wh->location_id)
            ->assertStatus(200)
            ->json('data');

        $this->assertSame($poA->po_number, $detail['overview']['po_number']);
        $this->assertSame(1, $detail['overview']['totals']['item_count']);

        $allSerials = collect($detail['serials'])->flatMap(fn ($p) => $p['serials'])->all();
        $this->assertContains($snA, $allSerials);
        $this->assertNotContains($snB, $allSerials); // isolation: other PO's serial excluded
    }

    public function test_group_detail_consumable_lists_barcode(): void
    {
        $wh = Location::create(['location_code' => 'WH-' . uniqid(), 'location_name' => 'Main WH', 'location_type' => 'warehouse']);
        $cons = Product::create(['product_code' => 'C-' . uniqid(), 'product_name' => 'Wire', 'product_type' => 'installation_material']);
        $user = $this->makeUser();

        $po = $this->makePo($wh->location_id);
        $bc = 'BC-' . uniqid();
        $this->receive($user, $po, $wh->location_id, $cons->product_id, 10, $bc);

        $detail = $this->actingAs($user)
            ->getJson('/api/po-inventory-groups/' . $po->po_id . '?location_id=' . $wh->location_id)
            ->assertStatus(200)
            ->json('data');

        $allSerials = collect($detail['serials'])->flatMap(fn ($p) => $p['serials'])->all();
        $this->assertContains($bc, $allSerials);
        $this->assertSame(10, $detail['overview']['totals']['total_received']);
    }
}
