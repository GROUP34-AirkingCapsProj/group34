<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PieceVerificationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_verification_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('receiving_details', 'packages_received'));
        $this->assertTrue(Schema::hasColumn('receiving_details', 'pieces_expected'));
    }

    private function makeUser(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    private function makeLocation(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
    }

    private function scaffold(): array
    {
        $user = $this->makeUser();
        $location = $this->makeLocation();

        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Tube',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 500,
            'unit_price' => 5, 'subtotal' => 2500,
        ]);

        return [$location, $product, $user, $po, $poDetail];
    }

    private function scaffoldPieceCounted(): array
    {
        $user = $this->makeUser();
        $location = $this->makeLocation();

        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Bolt M8',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 1,
            'package_unit' => 'Piece', 'is_serialized' => 0, 'count_unit' => 'piece',
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 500,
            'unit_price' => 5, 'subtotal' => 2500,
        ]);

        return [$location, $product, $user, $po, $poDetail];
    }

    public function test_a_package_counted_receipt_records_packages_and_expectation(): void
    {
        [$location, , $user, , $poDetail] = $this->scaffold();   // ppp 50, count_unit package

        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $poDetail->po_detail_id,
            'location_id'   => $location->location_id,
            'quantity'      => 6,
            'actual_pieces' => 297,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $row = DB::table('receiving_details')->where('po_detail_id', $poDetail->po_detail_id)->first();

        $this->assertSame(297, (int) $row->quantity_amount, 'the ACTUAL count posts, not the expectation');
        $this->assertSame(6, (int) $row->packages_received);
        $this->assertSame(300, (int) $row->pieces_expected);
    }

    public function test_omitting_the_actual_count_falls_back_to_the_expectation(): void
    {
        [$location, , $user, , $poDetail] = $this->scaffold();

        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 6,
        ])->assertOk();

        $row = DB::table('receiving_details')->where('po_detail_id', $poDetail->po_detail_id)->first();

        $this->assertSame(300, (int) $row->quantity_amount, 'existing callers that send no actual count must be unaffected');
        $this->assertSame(300, (int) $row->pieces_expected);
    }

    public function test_a_piece_counted_receipt_stores_nulls(): void
    {
        [$location, , $user, , $poDetail] = $this->scaffoldPieceCounted();

        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 473,
        ])->assertOk();

        $row = DB::table('receiving_details')->where('po_detail_id', $poDetail->po_detail_id)->first();

        $this->assertSame(473, (int) $row->quantity_amount);
        $this->assertNull($row->packages_received, 'a piece-counted line has no package to verify');
        $this->assertNull($row->pieces_expected);
    }
}
