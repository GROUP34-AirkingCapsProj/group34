<?php

namespace Tests\Feature\Transfer;

use App\Models\ConsumableDetail;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use App\Services\TransferService;

class TransferMaintainsPackagesTest extends TransferTestCase
{
    public function test_setPieces_keeps_packages_in_step_when_stock_leaves(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Src', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Tube',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Roll', 'is_serialized' => 0,
        ]);

        $inv = Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 500, 'packages_on_hand' => 10,
            'available_quantity' => 500, 'reorder_level' => 0,
        ]);

        $inv->setPieces((int) $inv->quantity_on_hand - 120, 50);
        $inv->save();

        $fresh = $inv->fresh();
        $this->assertSame(380, $fresh->quantity_on_hand);
        $this->assertSame(7, $fresh->packages_on_hand, 'packages must follow stock out, which transfers never did before');
        $this->assertSame(380, $fresh->available_quantity);
    }

    /**
     * Drives the real service end to end — dispatch, receive, reverse — and asserts
     * packages_on_hand stays derived from pieces at BOTH ends after every step.
     * Before this work reverse() moved pieces without touching packages at all, so a
     * regression there leaves the source at the dispatched package count (7) instead
     * of the restored one (10), and the destination at 2 instead of 0.
     */
    public function test_reverse_keeps_packages_derived_at_both_ends(): void
    {
        $from = $this->makeLocation('PKG-REV-SRC');
        $to   = $this->makeLocation('PKG-REV-DST');
        $user = $this->makeUser();

        $product = $this->makeConsumable();
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Roll', 'is_serialized' => 0,
        ]);

        Inventory::create([
            'location_id' => $from->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 500, 'packages_on_hand' => 10,
            'available_quantity' => 500, 'reorder_level' => 0,
        ]);

        $svc = new TransferService();
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $product->product_id, 'quantity_requested' => 120, 'quantity_scanned' => 120],
        ]);

        // Dispatch: 120 of 500 pieces leave the source — not a whole number of packages.
        $svc->dispatch($ist);
        $ist->refresh()->load('details');
        $this->assertDerivedPackages($from->location_id, $product->product_id, 380, 50, 'after dispatch (source)');

        // Receive all 120 at the destination.
        $svc->recordReceiveScan($ist, 'REV-BOX', (int) $ist->details->first()->transfer_detail_id, $user->user_id, 120);
        $svc->receive($ist->fresh('details'), $user->user_id);
        $this->assertDerivedPackages($to->location_id, $product->product_id, 120, 50, 'after receive (destination)');

        // Reverse: destination gives the 120 back, source is made whole again.
        $svc->reverse($ist->fresh('details'));

        $this->assertDerivedPackages($from->location_id, $product->product_id, 500, 50, 'after reverse (source)');
        $this->assertDerivedPackages($to->location_id, $product->product_id, 0, 50, 'after reverse (destination)');
    }

    /**
     * DELIBERATE BEHAVIOUR -- do not "fix" this back to a double max(0, ...) clamp.
     *
     * When a reversal pulls back more pieces than the destination still holds,
     * setPieces() clamps the piece count at 0 and moves available_quantity by that
     * clamped delta -- i.e. by the pieces that actually existed to be reversed, not by
     * the amount the transfer claims. Here the destination received 120, then drifted to
     * 30 on hand with 50 available (an already-inconsistent state: availability exceeds
     * physical stock). Reversing 120 removes the 30 that were really there, leaving
     * available at 50 - 30 = 20.
     *
     * The old code deducted the two columns independently -- max(0, 50 - 120) -- and
     * would land on 0. That looks tidier only by accident: it charges availability for 120
     * pieces when 30 existed, double-counting a shortfall the destination already absorbed
     * when the stock was consumed. The divergence is pre-existing corruption; reverse()
     * preserves it rather than papering over it, and repairing it belongs in a stock
     * reconciliation, not in a transfer reversal.
     */
    public function test_reversing_more_than_remains_drops_availability_by_what_actually_existed(): void
    {
        $from = $this->makeLocation('PKG-UNDER-SRC');
        $to   = $this->makeLocation('PKG-UNDER-DST');
        $user = $this->makeUser();

        $product = $this->makeConsumable();
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Roll', 'is_serialized' => 0,
        ]);

        Inventory::create([
            'location_id' => $from->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 500, 'packages_on_hand' => 10,
            'available_quantity' => 500, 'reorder_level' => 0,
        ]);

        $svc = new TransferService();
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $product->product_id, 'quantity_requested' => 120, 'quantity_scanned' => 120],
        ]);

        $svc->dispatch($ist);
        $ist->refresh()->load('details');
        $svc->recordReceiveScan($ist, 'UNDER-BOX', (int) $ist->details->first()->transfer_detail_id, $user->user_id, 120);
        $svc->receive($ist->fresh('details'), $user->user_id);

        // Destination consumed most of the received stock before the reversal runs, and
        // its two columns have drifted apart: 30 physically on hand, 50 marked available.
        Inventory::where('location_id', $to->location_id)
            ->where('product_id', $product->product_id)
            ->update(['quantity_on_hand' => 30, 'available_quantity' => 50, 'packages_on_hand' => 0]);

        $svc->reverse($ist->fresh('details'));

        $dst = Inventory::where('location_id', $to->location_id)
            ->where('product_id', $product->product_id)
            ->first();

        $this->assertSame(0, (int) $dst->quantity_on_hand, 'pieces clamp at zero, never negative');
        $this->assertSame(
            20,
            (int) $dst->available_quantity,
            'availability must move by the 30 pieces that existed, not the 120 requested'
        );
        $this->assertSame(0, (int) $dst->packages_on_hand, 'packages stay derived from the clamped piece count');
    }

    /** Assert the row holds $expectedPieces and that packages_on_hand is exactly intdiv(pieces, ppp). */
    private function assertDerivedPackages(int $locationId, int $productId, int $expectedPieces, int $ppp, string $stage): void
    {
        $inv = Inventory::where('location_id', $locationId)
            ->where('product_id', $productId)
            ->first();

        $this->assertNotNull($inv, "inventory row missing {$stage}");
        $this->assertSame($expectedPieces, (int) $inv->quantity_on_hand, "quantity_on_hand {$stage}");
        $this->assertSame($expectedPieces, (int) $inv->available_quantity, "available_quantity {$stage}");
        $this->assertSame(
            intdiv($expectedPieces, $ppp),
            (int) $inv->packages_on_hand,
            "packages_on_hand must equal intdiv(quantity_on_hand, {$ppp}) {$stage}"
        );
    }
}
