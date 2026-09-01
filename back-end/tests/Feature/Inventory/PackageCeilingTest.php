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
 * Receiving is bounded by two ceilings, not one.
 *
 * Before piece verification every package posted exactly pieces_per_package
 * pieces, so bounding pieces implicitly bounded packages. Short-filled boxes
 * decoupled them: three half-full cartons spend half the piece budget and
 * leave room for a fourth carton the order never asked for.
 */
class PackageCeilingTest extends TestCase
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
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => $ppp,
            'package_unit' => 'Pack', 'is_serialized' => 0, 'count_unit' => $countUnit,
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

    private function scanOnePackage(User $user, Location $location, PurchaseOrder $po, PurchaseOrderDetail $d): void
    {
        $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => 'SUP-' . strtoupper(uniqid()),
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'po_id'            => $po->po_id,
            'po_detail_id'     => $d->po_detail_id,
        ])->assertOk()->assertJsonPath('data.action', 'applied');
    }

    /**
     * The reported bug, exactly as it happened: 3 packages ordered at 82
     * pieces each, two counted short (150 against an expected 164) and one
     * scanned (82). That is 232 pieces across all 3 packages, leaving 14
     * pieces of budget — which the piece ceiling alone happily spent on a
     * fourth carton.
     */
    public function test_a_fourth_package_is_refused_even_when_the_pieces_still_fit(): void
    {
        [$location, , $user, $po, $d] = $this->scaffold(82, 3);

        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'quantity'      => 2,
            'actual_pieces' => 150,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $this->scanOnePackage($user, $location, $po, $d);

        $rowsBefore = ReceivingDetail::where('po_detail_id', $d->po_detail_id)->count();
        $this->assertSame(2, $rowsBefore, 'Two counted packages plus one scanned package is two rows.');

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'quantity'      => 1,
            'actual_pieces' => 10, // fits inside the 14-piece remainder
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'over_receipt');
        $res->assertJsonPath('data.limit', 'packages');
        $res->assertJsonPath('data.ordered_packages', 3);
        $res->assertJsonPath('data.received_packages', 3);
        $res->assertJsonPath('data.remaining_packages', 0);

        $this->assertSame(
            $rowsBefore,
            ReceivingDetail::where('po_detail_id', $d->po_detail_id)->count(),
            'A refused package must not post a receiving row.'
        );
    }

    public function test_confirming_the_extra_package_posts_it(): void
    {
        [$location, , $user, $po, $d] = $this->scaffold(82, 3);

        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'quantity'      => 2,
            'actual_pieces' => 150,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $this->scanOnePackage($user, $location, $po, $d);

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'   => $d->po_detail_id,
            'location_id'    => $location->location_id,
            'quantity'       => 1,
            'actual_pieces'  => 10,
            'accept_overage' => true,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'applied');
        $res->assertJsonPath('data.quantity_received', 10);

        $this->assertSame(
            3,
            ReceivingDetail::where('po_detail_id', $d->po_detail_id)->count(),
            'A confirmed over-receipt still has to post.'
        );
        $this->assertSame(
            4,
            (int) ReceivingDetail::where('po_detail_id', $d->po_detail_id)
                ->sum(DB::raw('COALESCE(packages_received, 0)')),
            'The confirmed fourth carton is now on the record as a fourth package.'
        );
    }

    /**
     * The piece ceiling has to keep firing on its own account. Two cases:
     * one where only pieces overflow (packages still fit), and one where
     * both ceilings would fire — the package ceiling is checked first, so
     * that is the refusal the clerk sees.
     */
    public function test_the_piece_ceiling_still_fires_independently(): void
    {
        // Packages fit (1 of 3), pieces do not (900 against 246).
        [$location, , $user, , $d] = $this->scaffold(82, 3);

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'quantity'      => 1,
            'actual_pieces' => 900,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'over_receipt');
        $res->assertJsonPath('data.limit', 'pieces');
        $res->assertJsonPath('data.remaining', 246);
        $res->assertJsonPath('data.overage', 654);

        // A 2-package order with one package already scanned, then two more
        // packages counted with pieces that also overflow. Both ceilings
        // would fire; the package one is reported because "the order has no
        // room for another carton" is the actionable fact.
        [$location2, , $user2, $po2, $d2] = $this->scaffold(82, 2);
        $this->scanOnePackage($user2, $location2, $po2, $d2);

        $res = $this->actingAs($user2)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $d2->po_detail_id,
            'location_id'   => $location2->location_id,
            'quantity'      => 2,
            'actual_pieces' => 500,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'over_receipt');
        $res->assertJsonPath('data.limit', 'packages');
        $res->assertJsonPath('data.overage_packages', 1);
    }

    /**
     * The scan path needs the same ceiling — it is the higher-volume door
     * onto the same bug.
     *
     * Note which guard this actually exercises. The scan path refuses early
     * with "All N package(s) already received" once no pieces remain, so on
     * a line where every carton arrived FULL that guard fires first and the
     * package ceiling is never consulted. The ceiling only becomes reachable
     * when the boxes arrived SHORT: three cartons in, pieces still on the
     * line. So the three packages here are counted short (150 of an expected
     * 246) and the fourth arrives by scan, with 96 pieces of budget left
     * that the piece ceiling would happily spend on it.
     */
    public function test_a_fourth_scanned_package_is_refused_when_short_boxes_left_piece_budget(): void
    {
        [$location, , $user, $po, $d] = $this->scaffold(82, 3);

        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'quantity'      => 3,
            'actual_pieces' => 150, // all three cartons in, but short
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $rowsBefore = ReceivingDetail::where('po_detail_id', $d->po_detail_id)->count();

        $res = $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => 'SUP-' . strtoupper(uniqid()),
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'po_id'            => $po->po_id,
            'po_detail_id'     => $d->po_detail_id,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'over_receipt');
        $res->assertJsonPath('data.limit', 'packages');
        $res->assertJsonPath('data.ordered_packages', 3);
        $res->assertJsonPath('data.received_packages', 3);
        $res->assertJsonPath('data.counted_packages', 1);

        $this->assertSame(
            $rowsBefore,
            ReceivingDetail::where('po_detail_id', $d->po_detail_id)->count(),
            'A refused scan must not post a receiving row.'
        );
    }

    /**
     * Rows written before `packages_received` existed hold NULL. They carry
     * no package information, so they must count as zero against the package
     * ceiling rather than making the whole sum unusable.
     */
    public function test_legacy_rows_with_a_null_package_count_do_not_poison_the_sum(): void
    {
        [$location, $product, $user, , $d] = $this->scaffold(82, 3);

        // Post one package normally, then forge a legacy row alongside it.
        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'quantity'      => 1,
            'actual_pieces' => 80,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $legacy = ReceivingDetail::where('po_detail_id', $d->po_detail_id)->firstOrFail()->replicate();
        $legacy->quantity_amount   = 20;
        $legacy->packages_received = null;
        $legacy->pieces_expected   = null;
        $legacy->save();

        // The NULL row contributes nothing, so 2 more packages still fit
        // under the 3-package ceiling.
        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'quantity'      => 2,
            'actual_pieces' => 100,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        // A fourth is still refused, and the counted total is right — not
        // NULL-collapsed and not inflated.
        $res = $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'quantity'      => 1,
            'actual_pieces' => 5,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'over_receipt');
        $res->assertJsonPath('data.limit', 'packages');
        $res->assertJsonPath('data.received_packages', 3);
    }

    /**
     * A piece-counted line has no package concept at all — the clerk counts
     * loose pieces, the row records no package figure, and no package
     * refusal may ever be raised against it.
     */
    public function test_a_piece_counted_line_is_unaffected(): void
    {
        [$location, , $user, , $d] = $this->scaffold(50, 3, 'piece'); // 150 pieces ordered

        // Four separate postings against a 3-package order: under a package
        // ceiling this would be refused, but pieces are all that bound it.
        // Stops at 120 of 150 so the PO stays receivable for the last leg.
        foreach ([40, 40, 20, 20] as $qty) {
            $this->actingAs($user)->postJson('/api/inventory/receive-count', [
                'po_detail_id' => $d->po_detail_id,
                'location_id'  => $location->location_id,
                'quantity'     => $qty,
            ])->assertOk()->assertJsonPath('data.action', 'applied');
        }

        $this->assertSame(4, ReceivingDetail::where('po_detail_id', $d->po_detail_id)->count());
        $this->assertNull(
            ReceivingDetail::where('po_detail_id', $d->po_detail_id)->first()->packages_received,
            'A piece-counted row records no package figure.'
        );

        // And the piece ceiling still bounds it, exactly as before.
        $res = $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $d->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 40,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'over_receipt');
        $res->assertJsonPath('data.limit', 'pieces');
        $res->assertJsonPath('data.remaining', 30);
        $res->assertJsonPath('data.overage', 10);
    }
}
