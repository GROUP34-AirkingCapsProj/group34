<?php

namespace Tests\Feature\History;

use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Receiving;
use App\Models\ReceivingDetail;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReceivingPoLogTest extends TestCase
{
    use DatabaseTransactions;

    /** role_id 1/2/5 are centralized (isCentralized()); 4 is warehouse_personnel (location-scoped). */
    private function makeUser(int $roleId, ?int $locationId = null): User
    {
        return User::forceCreate([
            'first_name' => 'Log', 'last_name' => 'User',
            'email' => 'rlog_' . uniqid() . '@test.local',
            'username' => 'rlog_' . uniqid(),
            'password_hash' => Hash::make('password'),
            'role_id' => $roleId,
            'location_id' => $locationId,
        ]);
    }

    private function makeLocation(string $name): Location
    {
        return Location::forceCreate([
            'location_code' => 'LOG-' . uniqid(), 'location_name' => $name,
            'location_type' => 'warehouse', 'is_active' => 1,
        ]);
    }

    private function makePoWithReceivings(User $u, Location $loc, Supplier $sup, int $count): PurchaseOrder
    {
        $prod = Product::forceCreate([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'Log Widget',
            'product_type' => 'installation_material',
        ]);
        $po = PurchaseOrder::create([
            'supplier_id' => $sup->supplier_id, 'location_id' => $loc->location_id,
            'po_number' => 'PO-LOG-' . uniqid(), 'order_date' => today()->toDateString(),
            'verification_status' => 'verified', 'total_amount' => 100, 'grand_total' => 100,
            'created_by' => $u->user_id,
        ]);
        for ($i = 0; $i < $count; $i++) {
            $r = Receiving::create([
                'po_id' => $po->po_id, 'supplier_id' => $sup->supplier_id,
                'location_id' => $loc->location_id, 'receiving_number' => 'RCV-' . uniqid(),
                'receiving_date' => today()->subDays($count - $i)->toDateString(),
                'received_by' => $u->user_id, 'total_quantity_received' => 5,
            ]);
            ReceivingDetail::create([
                'receiving_id' => $r->receiving_id, 'product_id' => $prod->product_id,
                'quantity_amount' => 5, 'prod_price' => 10, 'condition' => 'Good',
            ]);
        }
        return $po;
    }

    public function test_groups_multiple_receivings_into_one_log_entry(): void
    {
        $u = $this->makeUser(1);
        $loc = $this->makeLocation('Log WH A');
        $sup = Supplier::forceCreate(['supplier_name' => 'Log Supplier ' . uniqid()]);
        $po = $this->makePoWithReceivings($u, $loc, $sup, 3);

        $res = $this->actingAs($u, 'sanctum')->getJson('/api/receivings/po-log');
        $res->assertOk();
        $row = collect($res->json('data'))->firstWhere('po_id', $po->po_id);
        $this->assertNotNull($row);
        $this->assertSame(3, $row['events_count']);
        $this->assertSame(15, $row['total_items']);

        // Contract shape the frontend actually renders (ReceivingHistoryLog.jsx):
        // po.supplier?.supplier_name, po.warehouses.join(...), and the pagination envelope.
        $this->assertSame($sup->supplier_name, $row['supplier']['supplier_name']);
        $this->assertContains($loc->location_name, $row['warehouses']);
        $this->assertArrayHasKey('current_page', $res->json('pagination'));
        $this->assertArrayHasKey('last_page', $res->json('pagination'));
        $this->assertArrayHasKey('per_page', $res->json('pagination'));
        $this->assertArrayHasKey('total', $res->json('pagination'));
    }

    public function test_location_id_filter_narrows_results(): void
    {
        $u = $this->makeUser(1);
        $locA = $this->makeLocation('Log WH A');
        $locB = $this->makeLocation('Log WH B');
        $sup = Supplier::forceCreate(['supplier_name' => 'Log Supplier ' . uniqid()]);
        $poA = $this->makePoWithReceivings($u, $locA, $sup, 1);
        $poB = $this->makePoWithReceivings($u, $locB, $sup, 1);

        $res = $this->actingAs($u, 'sanctum')->getJson('/api/receivings/po-log?location_id=' . $locA->location_id);
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('po_id');
        $this->assertTrue($ids->contains($poA->po_id));
        $this->assertFalse($ids->contains($poB->po_id));
    }

    public function test_date_range_filter_narrows_results(): void
    {
        $u = $this->makeUser(1);
        $loc = $this->makeLocation('Log WH C');
        $sup = Supplier::forceCreate(['supplier_name' => 'Log Supplier ' . uniqid()]);
        $prod = Product::forceCreate([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'Log Widget',
            'product_type' => 'installation_material',
        ]);
        $po = PurchaseOrder::create([
            'supplier_id' => $sup->supplier_id, 'location_id' => $loc->location_id,
            'po_number' => 'PO-LOG-' . uniqid(), 'order_date' => today()->toDateString(),
            'verification_status' => 'verified', 'total_amount' => 100, 'grand_total' => 100,
            'created_by' => $u->user_id,
        ]);
        $old = Receiving::create([
            'po_id' => $po->po_id, 'supplier_id' => $sup->supplier_id,
            'location_id' => $loc->location_id, 'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date' => today()->subDays(30)->toDateString(),
            'received_by' => $u->user_id, 'total_quantity_received' => 5,
        ]);
        ReceivingDetail::create([
            'receiving_id' => $old->receiving_id, 'product_id' => $prod->product_id,
            'quantity_amount' => 5, 'prod_price' => 10, 'condition' => 'Good',
        ]);

        $res = $this->actingAs($u, 'sanctum')
            ->getJson('/api/receivings/po-log?from_date=' . today()->subDays(2)->toDateString());
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('po_id');
        $this->assertFalse($ids->contains($po->po_id));
    }

    public function test_search_matches_po_number(): void
    {
        $u = $this->makeUser(1);
        $loc = $this->makeLocation('Log WH D');
        $sup = Supplier::forceCreate(['supplier_name' => 'UniqueSupplierName' . uniqid()]);
        $po = $this->makePoWithReceivings($u, $loc, $sup, 1);

        $res = $this->actingAs($u, 'sanctum')
            ->getJson('/api/receivings/po-log?search=' . urlencode($po->po_number));
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('po_id');
        $this->assertTrue($ids->contains($po->po_id));
    }

    public function test_location_scoped_user_only_sees_their_locations_pos(): void
    {
        $locA = $this->makeLocation('Log WH Scoped A');
        $locB = $this->makeLocation('Log WH Scoped B');
        $sup = Supplier::forceCreate(['supplier_name' => 'Log Supplier ' . uniqid()]);
        $admin = $this->makeUser(1);
        $poA = $this->makePoWithReceivings($admin, $locA, $sup, 1);
        $poB = $this->makePoWithReceivings($admin, $locB, $sup, 1);

        $scopedUser = $this->makeUser(4, $locA->location_id);

        $res = $this->actingAs($scopedUser, 'sanctum')->getJson('/api/receivings/po-log');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('po_id');
        $this->assertTrue($ids->contains($poA->po_id));
        $this->assertFalse($ids->contains($poB->po_id));
    }
}
