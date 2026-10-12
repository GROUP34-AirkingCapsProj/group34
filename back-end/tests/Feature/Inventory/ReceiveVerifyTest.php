<?php

namespace Tests\Feature\Inventory;

use App\Models\ApplianceDetail;
use App\Models\InstallationMaterialDetail;
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
        InstallationMaterialDetail::create([
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

        return [$location, Product::with('installationMaterialDetail')->find($product->product_id), $user, $po, $poDetail];
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

        // The stamp is row-scoped: one scan is one package, whatever the
        // clerk's line-wide figures were. See
        // test_row_expectations_are_row_scoped_not_line_scoped.
        $row = DB::table('receiving_details')->where('po_detail_id', $d->po_detail_id)
            ->orderByDesc('receiving_detail_id')->first();
        $this->assertSame(1, (int) $row->packages_received);
        $this->assertSame(50, (int) $row->pieces_expected);

        // The receiving header is incremented on every scan, so it too has to
        // follow the correction or it diverges from both the line sum and the
        // batch it funded — and it labels the receiving picker.
        $this->assertSame(
            97,
            (int) DB::table('receivings')->where('receiving_id', $row->receiving_id)
                ->value('total_quantity_received'),
            'The header must not keep reporting the pre-reconciliation figure.'
        );
    }

    /**
     * A shortfall larger than any single scan row still has to leave the line's
     * SUM(quantity_amount) exact, because that sum — not the inventory table —
     * is what checkOverage() reads as `already_received` and what
     * PoReceivingStatusService::sync() reads as `totalReceived`. A sum left
     * overstated lets a genuine over-receipt through undetected and can flip the
     * PO to Completed when
     * almost none of the order actually arrived.
     */
    public function test_a_shortfall_larger_than_one_scan_row_keeps_the_line_sum_exact(): void
    {
        [$location, $product, $user, $po, $d] = $this->scaffold();
        $this->scanBoxes($user, $location, $po, $d, 6);          // six rows, 300 pieces posted

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-verify', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'packages'      => 6,
            'actual_pieces' => 20,
        ]);

        $res->assertOk()->assertJsonPath('data.action', 'applied');

        $onHand = Inventory::where('product_id', $product->product_id)->first()->quantity_on_hand;
        $sum    = (int) DB::table('receiving_details')
            ->where('po_detail_id', $d->po_detail_id)->sum('quantity_amount');

        $this->assertSame(20, $onHand);
        $this->assertSame(
            $onHand,
            $sum,
            'The line total and the ledger must agree. A delta of -280 cannot fit inside the single '
            . 'most recent row of 50, and clamping it there would strand the difference across the '
            . 'other five rows — exactly the drift this project eliminated between quantity_on_hand '
            . 'and packages_on_hand, relocated into receiving_details.'
        );

        // No row may go negative: BarcodeAdjustmentController subtracts a single
        // row's quantity_amount from stock when voiding a scan, so a negative
        // row there would ADD stock rather than remove it.
        $this->assertSame(
            0,
            (int) DB::table('receiving_details')
                ->where('po_detail_id', $d->po_detail_id)
                ->where('quantity_amount', '<', 0)->count(),
            'A receiving_details row is a physical count and must never be negative.'
        );

        $batch = InventoryBatch::where('product_id', $product->product_id)->first();
        $this->assertSame(20, (int) $batch->quantity_received);
        $this->assertSame(20, (int) $batch->quantity_remaining);
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

        // A clean count is still a verification event — every row's expectation
        // and package count must be recorded so the line reads as checked, not
        // merely untouched. A row reading 50 expected against 50 actual is the
        // statement "this box was full"; a NULL is no statement at all.
        $rows = DB::table('receiving_details')->where('po_detail_id', $d->po_detail_id)->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(1, (int) $row->packages_received);
            $this->assertSame(50, (int) $row->pieces_expected);
            $this->assertSame(50, (int) $row->quantity_amount);
        }
    }

    /**
     * The expectation columns are ROW-scoped, exactly as the count path writes
     * them: each row states what its own entry should have held. On the scan
     * path one row is one scan is one package, so every row of the line expects
     * one package of 50 — including the rows the delta never touched.
     *
     * Stamping the line-wide figures on the newest row instead is a lie once
     * the shortfall has been distributed: the drained row would read "100
     * expected" against its 47 actual, and the Stock-In History sub-line that
     * compares those two columns on a single row would report 53 pieces short
     * on a delivery that was 3 short.
     */
    public function test_row_expectations_are_row_scoped_not_line_scoped(): void
    {
        [$location, , $user, $po, $d] = $this->scaffold();
        $this->scanBoxes($user, $location, $po, $d, 2);          // two rows, 100 pieces posted

        $this->actingAs($user)->postJson('/api/inventory/receive-verify', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'packages'      => 2,
            'actual_pieces' => 97,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $rows = DB::table('receiving_details')->where('po_detail_id', $d->po_detail_id)
            ->orderBy('receiving_detail_id')->get();

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame(
                1,
                (int) $row->packages_received,
                'One scan is one package, so a scan row can only ever represent one package.'
            );
            $this->assertSame(
                50,
                (int) $row->pieces_expected,
                'Every row of the line carries its own expectation — not only the ones the delta drained.'
            );
        }

        $short = $rows->filter(fn ($r) => (int) $r->pieces_expected !== (int) $r->quantity_amount)->values();
        $this->assertCount(1, $short, 'Only the row the shortfall was taken from may read short.');
        $this->assertSame(47, (int) $short[0]->quantity_amount);
        $this->assertSame(
            3,
            (int) $short[0]->pieces_expected - (int) $short[0]->quantity_amount,
            'The row must report the 3 pieces that were actually missing, not the whole line expectation.'
        );
    }

    /**
     * Attributing the shortfall to one row must not lose it at line level: the
     * two columns still have to sum to the label figure and the verified figure
     * respectively, so the line-wide shortfall stays recoverable by subtraction.
     */
    public function test_row_scoped_expectations_still_sum_to_the_line_figures(): void
    {
        [$location, , $user, $po, $d] = $this->scaffold();
        $this->scanBoxes($user, $location, $po, $d, 2);

        $this->actingAs($user)->postJson('/api/inventory/receive-verify', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'packages'      => 2,
            'actual_pieces' => 97,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $line = DB::table('receiving_details')->where('po_detail_id', $d->po_detail_id);

        $this->assertSame(100, (int) $line->sum('pieces_expected'), 'Two packages of 50 were expected.');
        $this->assertSame(
            97,
            (int) DB::table('receiving_details')->where('po_detail_id', $d->po_detail_id)->sum('quantity_amount'),
            'The line total must still equal what was verified.'
        );
    }

    /**
     * The response echoes back what the CLERK entered — a line-wide package
     * count and its derived expectation — which is a different question from
     * what each row records about its own scan. The two legitimately differ.
     */
    public function test_the_response_still_reports_the_line_wide_figures(): void
    {
        [$location, , $user, $po, $d] = $this->scaffold();
        $this->scanBoxes($user, $location, $po, $d, 2);

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-verify', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'packages'      => 2,
            'actual_pieces' => 97,
        ]);

        $res->assertOk()
            ->assertJsonPath('data.packages_received', 2)
            ->assertJsonPath('data.pieces_expected', 100);
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

        // The guard returns before beginTransaction(), so nothing can have
        // moved. Assert all three writable surfaces rather than just stock, so
        // a refactor that moves the check inside the transaction fails loudly
        // instead of leaving a half-applied over-receipt behind.
        $this->assertSame(
            100,
            Inventory::where('product_id', $product->product_id)->first()->quantity_on_hand,
            'An unconfirmed over-receipt must leave everything exactly as it was.'
        );

        $untouchedBatch = InventoryBatch::where('product_id', $product->product_id)->first();
        $this->assertSame(100, (int) $untouchedBatch->quantity_received);
        $this->assertSame(100, (int) $untouchedBatch->quantity_remaining);

        $untouchedRow = DB::table('receiving_details')->where('po_detail_id', $d->po_detail_id)
            ->orderByDesc('receiving_detail_id')->first();
        $this->assertSame(50, (int) $untouchedRow->quantity_amount);
        // The scan path now stamps these itself — one scan is one package of
        // pieces_per_package — so "still NULL" no longer distinguishes a row
        // verification skipped. What must hold is that the row still reads
        // exactly what the SCAN wrote, untouched by the refused verification.
        $this->assertSame(1, (int) $untouchedRow->packages_received,
            'An unconfirmed verification must leave the row as the scan wrote it.');
        $this->assertSame(50, (int) $untouchedRow->pieces_expected);

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
