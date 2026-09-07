<?php

namespace Tests\Feature\Inventory;

use App\Models\ApplianceDetail;
use App\Models\InstallationMaterialDetail;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Short-filled cartons leave a shortfall the supplier makes good later as
 * LOOSE stock — a bag of 14 pieces, not a fourth box. Before the top-up the
 * only way to record those pieces was to claim another package, which is what
 * pushed a 3-package order to 4 on the record.
 *
 * The top-up posts pieces and nothing else: it must never spend package
 * budget, and it must still respect the piece ceiling.
 */
class ReceiveTopUpTest extends TestCase
{
    use DatabaseTransactions;

    private function user(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username' => 't_' . uniqid(), 'first_name' => 'T', 'last_name' => 'U',
            'email' => uniqid() . '@e.test', 'password_hash' => bcrypt('x'), 'role_id' => $roleId,
        ]);
    }

    /** @return array{0:Location,1:Product,2:User,3:PurchaseOrder,4:PurchaseOrderDetail} */
    private function scaffold(int $ppp = 82, int $ordered = 3, string $countUnit = 'package'): array
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'L', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Tube',
            'product_type' => 'installation_material',
        ]);
        InstallationMaterialDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => $ppp,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => $countUnit,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => $ordered,
            'unit_price' => 1, 'subtotal' => $ordered,
        ]);

        return [$location, $product, $this->user(), $po, $poDetail];
    }

    private function countPackages(User $user, Location $l, PurchaseOrderDetail $d, int $packages, int $actual): void
    {
        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $l->location_id,
            'quantity'      => $packages,
            'actual_pieces' => $actual,
        ])->assertOk()->assertJsonPath('data.action', 'applied');
    }

    /**
     * The reported case: 3 boxes of 82 ordered (246 pieces), all 3 arrived but
     * holding 232 between them. The missing 14 turn up loose a week later.
     */
    public function test_a_top_up_adds_pieces_without_claiming_a_package(): void
    {
        [$location, $product, $user, $po, $d] = $this->scaffold(82, 3);

        $this->countPackages($user, $location, $d, 3, 232);

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-topup', [
            'po_detail_id' => $d->po_detail_id,
            'location_id'  => $location->location_id,
            'pieces'       => 14,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'applied');
        $res->assertJsonPath('data.quantity_received', 14);

        $inventory = Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)
            ->first();
        $this->assertNotNull($inventory);
        $this->assertSame(246, (int) $inventory->quantity_on_hand);

        $row = ReceivingDetail::where('po_detail_id', $d->po_detail_id)
            ->orderByDesc('receiving_detail_id')
            ->first();
        $this->assertSame(14, (int) $row->quantity_amount);
        $this->assertSame(
            0,
            (int) $row->packages_received,
            'A top-up records ZERO packages, never NULL: NULL means "this row predates the column", zero means "no carton arrived, only loose pieces".'
        );
        $this->assertNotNull($row->packages_received, 'packages_received must be 0, not null.');
        $this->assertNull($row->pieces_expected, 'Loose pieces have no package expectation to state.');

        $po->refresh();
        $this->assertSame(11, (int) $po->status_id, 'The shortfall is now made good, so the PO is Completed.');
    }

    /**
     * The guard that keeps the top-up and the package ceiling honest together.
     * The top-up is deliberately partial here so the PO stays receivable —
     * a Completed PO would be refused by the status guard instead, which
     * would prove nothing about package budget.
     */
    public function test_a_top_up_does_not_consume_the_package_budget(): void
    {
        [$location, , $user, , $d] = $this->scaffold(82, 3);

        $this->countPackages($user, $location, $d, 3, 232);

        $this->actingAs($user)->postJson('/api/inventory/receive-topup', [
            'po_detail_id' => $d->po_detail_id,
            'location_id'  => $location->location_id,
            'pieces'       => 10,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $this->assertSame(
            3,
            (int) ReceivingDetail::where('po_detail_id', $d->po_detail_id)
                ->sum(DB::raw('COALESCE(packages_received, 0)')),
            'Three cartons arrived and three are on the record — loose pieces are not a carton.'
        );

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'quantity'      => 1,
            'actual_pieces' => 4,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'over_receipt');
        $res->assertJsonPath('data.limit', 'packages');
        $res->assertJsonPath('data.received_packages', 3);
        $res->assertJsonPath('data.remaining_packages', 0);
    }

    public function test_the_piece_ceiling_still_bounds_a_top_up(): void
    {
        [$location, $product, $user, , $d] = $this->scaffold(82, 3);

        $this->countPackages($user, $location, $d, 3, 232);

        $rowsBefore = ReceivingDetail::where('po_detail_id', $d->po_detail_id)->count();

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-topup', [
            'po_detail_id' => $d->po_detail_id,
            'location_id'  => $location->location_id,
            'pieces'       => 20, // only 14 remain
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'over_receipt');
        $res->assertJsonPath('data.limit', 'pieces');
        $res->assertJsonPath('data.remaining', 14);
        $res->assertJsonPath('data.overage', 6);

        $this->assertSame(
            $rowsBefore,
            ReceivingDetail::where('po_detail_id', $d->po_detail_id)->count(),
            'A refused top-up must not post a receiving row.'
        );
        $this->assertSame(
            232,
            (int) Inventory::where('location_id', $location->location_id)
                ->where('product_id', $product->product_id)
                ->value('quantity_on_hand')
        );

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-topup', [
            'po_detail_id'   => $d->po_detail_id,
            'location_id'    => $location->location_id,
            'pieces'         => 20,
            'accept_overage' => true,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'applied');
        $this->assertSame(
            252,
            (int) Inventory::where('location_id', $location->location_id)
                ->where('product_id', $product->product_id)
                ->value('quantity_on_hand')
        );
    }

    public function test_a_serialized_appliance_cannot_be_topped_up(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'L', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'APL-' . uniqid(), 'product_name' => 'Split Type Aircon',
            'product_type' => 'appliance',
        ]);
        ApplianceDetail::create(['product_id' => $product->product_id, 'is_serialized' => 1]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $d = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 5,
            'unit_price' => 500, 'subtotal' => 2500,
        ]);

        $res = $this->actingAs($this->user())->postJson('/api/inventory/receive-topup', [
            'po_detail_id' => $d->po_detail_id,
            'location_id'  => $location->location_id,
            'pieces'       => 2,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'rejected');

        $this->assertFalse(
            Inventory::where('location_id', $location->location_id)
                ->where('product_id', $product->product_id)
                ->exists(),
            'An appliance has no package contents to top up, and must never enter stock without its own serial.'
        );
    }

    public function test_a_line_with_nothing_received_is_refused(): void
    {
        [$location, $product, $user, , $d] = $this->scaffold(82, 3);

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-topup', [
            'po_detail_id' => $d->po_detail_id,
            'location_id'  => $location->location_id,
            'pieces'       => 14,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'rejected');
        $this->assertStringContainsString(
            'count panel',
            (string) $res->json('data.reason'),
            'There is no shortfall to complete, so the clerk belongs in the count panel.'
        );

        $this->assertFalse(
            Inventory::where('location_id', $location->location_id)
                ->where('product_id', $product->product_id)
                ->exists()
        );
        $this->assertSame(0, ReceivingDetail::where('po_detail_id', $d->po_detail_id)->count());
    }
}
