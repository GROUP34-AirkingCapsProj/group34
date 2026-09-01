<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
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
 * A barcode proves a carton turned up. It says nothing about what was inside
 * it — which is why the clerks check the PO against the boxes even on a
 * scanned delivery. `contents_verified_at` records whether that check has
 * actually happened, so the screen can tell a trusted receipt from a verified
 * one instead of treating every scan as gospel.
 */
class ContentsVerificationTest extends TestCase
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
    private function scaffold(int $ppp = 82, int $ordered = 3): array
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'L', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Tube',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => $ppp,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
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

    private function scanOnePackage(User $user, Location $l, PurchaseOrder $po, PurchaseOrderDetail $d): void
    {
        $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => 'SUP-' . strtoupper(uniqid()),
            'transaction_type' => 'receiving',
            'location_id'      => $l->location_id,
            'po_id'            => $po->po_id,
            'po_detail_id'     => $d->po_detail_id,
        ])->assertOk()->assertJsonPath('data.action', 'applied');
    }

    private function lineFlag(User $user, PurchaseOrder $po, PurchaseOrderDetail $d): bool
    {
        $res = $this->actingAs($user)->getJson('/api/receivings/po-scan-status/' . $po->po_id);
        $res->assertOk();

        $line = collect($res->json('data.lines'))
            ->firstWhere('po_detail_id', $d->po_detail_id);

        $this->assertIsArray($line, 'The PO line must be reported.');
        $this->assertArrayHasKey('contents_verified', $line);

        return (bool) $line['contents_verified'];
    }

    public function test_a_counted_row_is_verified_at_entry(): void
    {
        [$location, , $user, , $d] = $this->scaffold(82, 3);

        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'quantity'      => 2,
            'actual_pieces' => 150,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $row = ReceivingDetail::where('po_detail_id', $d->po_detail_id)->firstOrFail();
        $this->assertNotNull(
            $row->contents_verified_at,
            'Counting IS the verification — the clerk typed the pieces they physically had.'
        );
    }

    public function test_a_scanned_row_starts_unverified(): void
    {
        [$location, , $user, $po, $d] = $this->scaffold(82, 3);

        $this->scanOnePackage($user, $location, $po, $d);

        $row = ReceivingDetail::where('po_detail_id', $d->po_detail_id)->firstOrFail();
        $this->assertNull(
            $row->contents_verified_at,
            'A barcode proves a carton arrived and nothing more — its contents are trusted, not verified.'
        );
    }

    public function test_verification_stamps_every_row_of_the_line(): void
    {
        [$location, , $user, $po, $d] = $this->scaffold(82, 3);

        $this->scanOnePackage($user, $location, $po, $d);
        $this->scanOnePackage($user, $location, $po, $d);

        $this->assertSame(
            2,
            ReceivingDetail::where('po_detail_id', $d->po_detail_id)
                ->whereNull('contents_verified_at')
                ->count()
        );

        $this->actingAs($user)->postJson('/api/inventory/receive-verify', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'packages'      => 2,
            'actual_pieces' => 150,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $this->assertSame(
            0,
            ReceivingDetail::where('po_detail_id', $d->po_detail_id)
                ->whereNull('contents_verified_at')
                ->count(),
            'Verification is per LINE, so every row it reconciled is stamped — including a row the delta drained to zero.'
        );
    }

    public function test_po_scan_status_reports_contents_verification_per_line(): void
    {
        [$location, , $user, $po, $d] = $this->scaffold(82, 3);

        $this->scanOnePackage($user, $location, $po, $d);
        $this->assertFalse(
            $this->lineFlag($user, $po, $d),
            'One unopened carton on the line leaves the whole line unverified.'
        );

        // A hand count alongside the scan is verified in itself, but the
        // scanned carton is still shut — the line is only as verified as its
        // least-checked row.
        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'quantity'      => 1,
            'actual_pieces' => 80,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $this->assertFalse($this->lineFlag($user, $po, $d));

        $this->actingAs($user)->postJson('/api/inventory/receive-verify', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'packages'      => 2,
            'actual_pieces' => 160,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $this->assertTrue(
            $this->lineFlag($user, $po, $d),
            'Once every row is stamped the line reads verified.'
        );
    }

    public function test_a_top_up_does_not_reset_verification(): void
    {
        [$location, , $user, $po, $d] = $this->scaffold(82, 3);

        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'quantity'      => 3,
            'actual_pieces' => 232,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $this->assertTrue($this->lineFlag($user, $po, $d));

        $this->actingAs($user)->postJson('/api/inventory/receive-topup', [
            'po_detail_id' => $d->po_detail_id,
            'location_id'  => $location->location_id,
            'pieces'       => 10,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $topUpRow = ReceivingDetail::where('po_detail_id', $d->po_detail_id)
            ->orderByDesc('receiving_detail_id')
            ->firstOrFail();
        $this->assertNotNull(
            $topUpRow->contents_verified_at,
            'Loose pieces are counted as they are booked, so the top-up row is verified at entry.'
        );

        $this->assertTrue(
            $this->lineFlag($user, $po, $d),
            'Completing a shortfall must not re-open a question that was already answered.'
        );
    }

    /**
     * A line whose rows all predate the column reads VERIFIED, and must: the
     * boxes were put away weeks ago, so "unverified" would be a complaint with
     * no action that could ever clear it and the screen would nag forever.
     *
     * The grandfathering lives in the data — the migration stamps every
     * pre-existing row with its own created_at — so NULL keeps exactly one
     * meaning for readers: a carton nobody has opened yet. This pins both
     * halves of that: a backfilled legacy row is quiet, and a fresh unopened
     * scan alongside it still speaks up.
     */
    public function test_a_line_whose_rows_all_predate_the_column_reads_verified(): void
    {
        [$location, , $user, $po, $d] = $this->scaffold(82, 3);

        $this->scanOnePackage($user, $location, $po, $d);

        // Exactly what the migration leaves behind for a row from months ago.
        ReceivingDetail::where('po_detail_id', $d->po_detail_id)->update([
            'created_at'           => '2026-01-15 09:00:00',
            'contents_verified_at' => '2026-01-15 09:00:00',
        ]);

        $this->assertTrue(
            $this->lineFlag($user, $po, $d),
            'A receipt nobody can now check must not nag forever.'
        );

        // A carton received today and not yet opened is a genuine "not checked
        // yet", and drags the line back to unverified.
        $this->scanOnePackage($user, $location, $po, $d);

        $this->assertFalse($this->lineFlag($user, $po, $d));
    }
}
