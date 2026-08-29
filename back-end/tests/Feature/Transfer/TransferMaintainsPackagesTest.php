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
