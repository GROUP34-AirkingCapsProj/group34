<?php

namespace Tests\Feature\Inventory;

use App\Models\ApplianceDetail;
use App\Models\ConsumableDetail;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reconciling a scanned receiving line to the piece count a clerk verified.
 *
 * The scan path posts each box the moment it is scanned, so by the time the
 * boxes are opened and counted the goods are already booked. Verification here
 * is therefore a delta applied on top of what was posted, not a fresh posting.
 */
class ReceiveVerifyTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:Location,1:Product,2:User,3:PurchaseOrder,4:PurchaseOrderDetail} */
    private function scaffold(int $orderedPackages = 10): array
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'L', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Tube',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 't_' . uniqid(), 'first_name' => 'T', 'last_name' => 'U',
            'email' => uniqid() . '@e.test', 'password_hash' => bcrypt('x'), 'role_id' => $roleId,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => $orderedPackages,
            'unit_price' => 1, 'subtotal' => 10,
        ]);

        return [$location, Product::with('consumableDetail')->find($product->product_id), $user, $po, $poDetail];
    }

    /** @return array{0:Location,1:Product,2:User,3:PurchaseOrder,4:PurchaseOrderDetail} */
    private function scaffoldAppliance(): array
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'L', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Split Type AC',
            'product_type' => 'appliance',
        ]);
        ApplianceDetail::create(['product_id' => $product->product_id, 'is_serialized' => 1]);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 't_' . uniqid(), 'first_name' => 'T', 'last_name' => 'U',
            'email' => uniqid() . '@e.test', 'password_hash' => bcrypt('x'), 'role_id' => $roleId,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 5,
            'unit_price' => 1, 'subtotal' => 5,
        ]);

        return [$location, Product::with('applianceDetail')->find($product->product_id), $user, $po, $poDetail];
    }

    /** Scan $boxes boxes onto the line, leaving $boxes * 50 pieces posted. */
    private function scanBoxes(User $user, Location $l, PurchaseOrder $po, PurchaseOrderDetail $d, int $boxes): void
    {
        for ($i = 0; $i < $boxes; $i++) {
            $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
                'barcode'          => 'BOX-' . uniqid(),
                'transaction_type' => 'receiving',
                'location_id'      => $l->location_id,
                'po_id'            => $po->po_id,
                'po_detail_id'     => $d->po_detail_id,
            ])->assertOk();
        }
    }

    public function test_it_reconciles_a_short_filled_line_down(): void
    {
        [$location, $product, $user, $po, $d] = $this->scaffold();
        $this->scanBoxes($user, $location, $po, $d, 2);          // 100 pieces posted

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-verify', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'packages'      => 2,
            'actual_pieces' => 97,
        ]);

        $res->assertOk()->assertJsonPath('data.action', 'applied');

        $inv = Inventory::where('product_id', $product->product_id)->first();
        $this->assertSame(97, $inv->quantity_on_hand, 'the ledger must follow the goods, not the label');

        $batch = InventoryBatch::where('product_id', $product->product_id)->first();
        $this->assertSame(97, (int) $batch->quantity_remaining);
        $this->assertSame(
            97,
            (int) $batch->quantity_received,
            'Pieces that were never in the box were never received either. A batch still claiming '
            . '100 received would make the PO group overstate the delivery, and would read as three '
            . 'pieces having been consumed rather than never arriving.'
        );

        $row = DB::table('receiving_details')->where('po_detail_id', $d->po_detail_id)
            ->orderByDesc('receiving_detail_id')->first();
        $this->assertSame(2, (int) $row->packages_received);
        $this->assertSame(100, (int) $row->pieces_expected);
    }

    public function test_a_matching_count_leaves_stock_alone(): void
    {
        [$location, $product, $user, $po, $d] = $this->scaffold();
        $this->scanBoxes($user, $location, $po, $d, 2);          // 100 pieces posted

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-verify', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'packages'      => 2,
            'actual_pieces' => 100,
        ]);

        $res->assertOk()->assertJsonPath('data.action', 'applied');

        $inv = Inventory::where('product_id', $product->product_id)->first();
        $this->assertSame(100, $inv->quantity_on_hand);

        $batch = InventoryBatch::where('product_id', $product->product_id)->first();
        $this->assertSame(100, (int) $batch->quantity_remaining);

        // A clean count is still a verification event — the expectation and the
        // package count must be recorded so the line reads as checked, not
        // merely untouched.
        $row = DB::table('receiving_details')->where('po_detail_id', $d->po_detail_id)
            ->orderByDesc('receiving_detail_id')->first();
        $this->assertSame(2, (int) $row->packages_received);
        $this->assertSame(100, (int) $row->pieces_expected);
    }

    public function test_it_refuses_a_line_with_nothing_posted(): void
    {
        [$location, $product, $user, , $d] = $this->scaffold();

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-verify', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'packages'      => 2,
            'actual_pieces' => 97,
        ]);

        $res->assertOk()->assertJsonPath('data.action', 'rejected');

        $this->assertNull(
            Inventory::where('product_id', $product->product_id)->first(),
            'Nothing was posted, so there is nothing to reconcile — verification must never create stock.'
        );
    }

    public function test_it_refuses_a_serialized_product(): void
    {
        [$location, $product, $user, $po, $d] = $this->scaffoldAppliance();

        $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => 'SN-' . strtoupper(uniqid()),
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'po_id'            => $po->po_id,
            'po_detail_id'     => $d->po_detail_id,
        ])->assertOk();

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-verify', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'packages'      => 1,
            'actual_pieces' => 0,
        ]);

        $res->assertOk()->assertJsonPath('data.action', 'rejected');

        $inv = Inventory::where('product_id', $product->product_id)->first();
        $this->assertSame(1, $inv->quantity_on_hand, 'An appliance has no package contents to verify.');
    }

    public function test_it_refuses_reconciling_below_zero(): void
    {
        [$location, $product, $user, $po, $d] = $this->scaffold();
        $this->scanBoxes($user, $location, $po, $d, 2);          // 100 pieces posted

        // Simulate an issuance of 60 pieces having already drawn on this batch.
        $inv = Inventory::where('product_id', $product->product_id)->first();
        $inv->setPieces(40, 50);
        $inv->save();
        $batch = InventoryBatch::where('product_id', $product->product_id)->first();
        $batch->quantity_remaining = 40;
        $batch->save();

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-verify', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'packages'      => 2,
            'actual_pieces' => 20,
        ]);

        $res->assertOk()->assertJsonPath('data.action', 'rejected');

        $this->assertSame(
            40,
            Inventory::where('product_id', $product->product_id)->first()->quantity_on_hand,
            'Stock already issued cannot be clawed back by a verification — that needs an adjustment.'
        );
    }

    public function test_over_filling_past_the_po_needs_confirmation(): void
    {
        [$location, $product, $user, $po, $d] = $this->scaffold(2); // only 100 pieces ordered
        $this->scanBoxes($user, $location, $po, $d, 2);             // 100 pieces posted

        $payload = [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'packages'      => 2,
            'actual_pieces' => 110,
        ];

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-verify', $payload);
        $res->assertOk()->assertJsonPath('data.action', 'over_receipt');

        $this->assertSame(
            100,
            Inventory::where('product_id', $product->product_id)->first()->quantity_on_hand,
            'An unconfirmed over-receipt must leave everything exactly as it was.'
        );

        $res = $this->actingAs($user)->postJson(
            '/api/inventory/receive-verify',
            $payload + ['accept_overage' => true]
        );
        $res->assertOk()->assertJsonPath('data.action', 'applied');

        $this->assertSame(
            110,
            Inventory::where('product_id', $product->product_id)->first()->quantity_on_hand
        );
        $batch = InventoryBatch::where('product_id', $product->product_id)->first();
        $this->assertSame(110, (int) $batch->quantity_remaining);
        $this->assertSame(
            110,
            (int) $batch->quantity_received,
            'Extra pieces found in the boxes did arrive, so the batch must record them as received.'
        );
    }
}
