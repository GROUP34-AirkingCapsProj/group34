<?php

namespace Tests\Feature\Transfer;

use App\Models\BarcodeScan;
use App\Models\ConsumableDetail;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Transfer;
use App\Services\TransferService;
use RuntimeException;

/**
 * Counted (unbarcoded) interbranch transfers: countDispatchScan / countReceiveScan.
 * Quantities on the count path are always PIECES.
 */
class CountedTransferFlowTest extends TransferTestCase
{
    private function makePackagedConsumable(int $piecesPerPackage = 50): Product
    {
        $product = $this->makeConsumable();
        ConsumableDetail::create([
            'product_id'         => $product->product_id,
            'pieces_per_package' => $piecesPerPackage,
            'package_unit'       => 'Roll',
            'is_serialized'      => 0,
        ]);
        return $product;
    }

    private function stock(int $locationId, int $productId, int $pieces, int $packages): Inventory
    {
        return Inventory::create([
            'location_id'        => $locationId,
            'product_id'         => $productId,
            'quantity_on_hand'   => $pieces,
            'packages_on_hand'   => $packages,
            'available_quantity' => $pieces,
            'reorder_level'      => 0,
        ]);
    }

    public function test_unbarcoded_material_ships_and_receives_by_count(): void
    {
        $from = $this->makeLocation('CNT-SRC');
        $to   = $this->makeLocation('CNT-DST');
        $user = $this->makeUser();

        $product = $this->makePackagedConsumable(50);
        $this->stock($from->location_id, $product->product_id, 500, 10);

        $svc = new TransferService();
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $product->product_id, 'quantity_requested' => 200],
        ]);
        $detailId = (int) $ist->details->first()->transfer_detail_id;

        $result = $svc->countDispatchScan($ist, $detailId, 200, $user->user_id);
        $this->assertSame(200, $result['quantity']);
        $this->assertSame(200, $result['total_scanned']);
        $this->assertSame(0, $result['remaining']);
        $this->assertSame(200, (int) $ist->details->first()->fresh()->quantity_scanned);

        // The row exists with a NULL barcode - the reason the migration is needed.
        $this->assertNull(BarcodeScan::find($result['scan_id'])->barcode);

        $svc->dispatch($ist->fresh('details.product'), $user->user_id);

        $src = Inventory::where('location_id', $from->location_id)
            ->where('product_id', $product->product_id)->first();
        $this->assertSame(300, (int) $src->quantity_on_hand, 'source pieces after dispatch');
        $this->assertSame(6, (int) $src->packages_on_hand, 'packages must follow pieces at the source');

        $ist = $ist->fresh('details.product');
        $rcv = $svc->countReceiveScan($ist, $detailId, 200, $user->user_id);
        $this->assertSame(200, $rcv['quantity_received']);
        $this->assertSame(0, $rcv['remaining']);

        $svc->receive($ist->fresh('details.product'), $user->user_id);

        $dst = Inventory::where('location_id', $to->location_id)
            ->where('product_id', $product->product_id)->first();
        $this->assertSame(200, (int) $dst->quantity_on_hand, 'destination pieces after receive');
        $this->assertSame(4, (int) $dst->packages_on_hand, 'packages must follow pieces at the destination');
    }

    public function test_serialized_lines_are_refused_at_both_ends(): void
    {
        $from = $this->makeLocation('CNT-SER-SRC');
        $to   = $this->makeLocation('CNT-SER-DST');
        $user = $this->makeUser();

        $product = $this->makeAppliance();
        $this->stock($from->location_id, $product->product_id, 3, 0);
        $this->makeSerials($from->location_id, $product->product_id, 3);

        $svc = new TransferService();
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $product->product_id, 'quantity_requested' => 3],
        ]);
        $detailId = (int) $ist->details->first()->transfer_detail_id;

        try {
            $svc->countDispatchScan($ist, $detailId, 2, $user->user_id);
            $this->fail('countDispatchScan must refuse a serialized line');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('scanned individually', $e->getMessage());
        }

        $this->assertSame(0, (int) $ist->details->first()->fresh()->quantity_scanned);

        // Force the header to In Transit so the receive-side guard is exercised.
        Transfer::where('transfer_id', $ist->transfer_id)
            ->update(['status_id' => $this->statusId('In Transit')]);
        $ist->details->first()->fresh()->update(['quantity_transferred' => 3]);

        try {
            $svc->countReceiveScan($ist->fresh('details.product'), $detailId, 2, $user->user_id);
            $this->fail('countReceiveScan must refuse a serialized line');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('scanned individually', $e->getMessage());
        }

        $this->assertSame(0, (int) $ist->details->first()->fresh()->quantity_received);

        $src = Inventory::where('location_id', $from->location_id)
            ->where('product_id', $product->product_id)->first();
        $this->assertSame(3, (int) $src->quantity_on_hand, 'no stock may move on a refused counted line');
        $this->assertNull(
            Inventory::where('location_id', $to->location_id)
                ->where('product_id', $product->product_id)->first(),
            'nothing may land at the destination'
        );
    }

    public function test_over_count_is_refused_at_both_ends(): void
    {
        $from = $this->makeLocation('CNT-OVR-SRC');
        $to   = $this->makeLocation('CNT-OVR-DST');
        $user = $this->makeUser();

        $product = $this->makePackagedConsumable(50);
        $this->stock($from->location_id, $product->product_id, 500, 10);

        $svc = new TransferService();
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $product->product_id, 'quantity_requested' => 100],
        ]);
        $detailId = (int) $ist->details->first()->transfer_detail_id;

        // Dispatch side: counting more than the line asks for is refused outright,
        // never quietly reduced to the remainder.
        try {
            $svc->countDispatchScan($ist, $detailId, 250, $user->user_id);
            $this->fail('countDispatchScan must refuse a count above the line remainder');
        } catch (RuntimeException $e) {
            $this->assertSame('Only 100 more unit(s) needed for this product.', $e->getMessage());
        }
        $this->assertSame(
            0,
            (int) $ist->details->first()->fresh()->quantity_scanned,
            'a refused count must leave quantity_scanned untouched'
        );

        $svc->countDispatchScan($ist, $detailId, 100, $user->user_id);
        $svc->dispatch($ist->fresh('details.product'), $user->user_id);

        // Receive side: same rule. The surplus is a real discrepancy and must surface
        // to the clerk rather than being swallowed by a clamp.
        $ist = $ist->fresh('details.product');
        try {
            $svc->countReceiveScan($ist, $detailId, 500, $user->user_id);
            $this->fail('countReceiveScan must refuse a count above what was shipped');
        } catch (RuntimeException $e) {
            $this->assertSame('Only 100 more unit(s) can be received on this line.', $e->getMessage());
        }

        $line = $ist->details->first()->fresh();
        $this->assertSame(0, (int) $line->quantity_received, 'a refused count must not move quantity_received');
        $this->assertLessThanOrEqual((int) $line->quantity_transferred, (int) $line->quantity_received);

        // The exact shipped amount still goes through, and a further count then refuses.
        $svc->countReceiveScan($ist->fresh('details.product'), $detailId, 100, $user->user_id);
        $this->assertSame(100, (int) $ist->details->first()->fresh()->quantity_received);

        $this->expectException(RuntimeException::class);
        $svc->countReceiveScan($ist->fresh('details.product'), $detailId, 1, $user->user_id);
    }

    public function test_counted_dispatch_move_can_be_undone(): void
    {
        $from = $this->makeLocation('CNT-UNDO-SRC');
        $to   = $this->makeLocation('CNT-UNDO-DST');
        $user = $this->makeUser();

        $product = $this->makePackagedConsumable(50);
        $this->stock($from->location_id, $product->product_id, 500, 10);

        $svc = new TransferService();
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $product->product_id, 'quantity_requested' => 200],
        ]);
        $detailId = (int) $ist->details->first()->transfer_detail_id;

        $res = $svc->countDispatchScan($ist, $detailId, 120, $user->user_id);
        $this->assertSame(120, (int) $ist->details->first()->fresh()->quantity_scanned);

        $svc->removeDispatchScan($ist, (int) $res['scan_id']);

        $this->assertSame(0, (int) $ist->details->first()->fresh()->quantity_scanned);
        $this->assertNull(BarcodeScan::find($res['scan_id']), 'the counted scan row is deleted on undo');
    }

    public function test_count_scan_endpoint_records_the_count(): void
    {
        $from = $this->makeLocation('CNT-HTTP-SRC');
        $to   = $this->makeLocation('CNT-HTTP-DST');
        $user = $this->makeUser();

        $product = $this->makePackagedConsumable(50);
        $this->stock($from->location_id, $product->product_id, 500, 10);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $product->product_id, 'quantity_requested' => 200],
        ]);
        $detailId = (int) $ist->details->first()->transfer_detail_id;

        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/transfers/{$ist->transfer_id}/count-scan", [
                'transfer_detail_id' => $detailId,
                'quantity'           => 150,
            ]);

        $res->assertOk();
        $res->assertJsonPath('data.total_scanned', 150);
        $res->assertJsonPath('data.quantity', 150);
        $res->assertJsonPath('data.ist_fully_scanned', false);

        $this->assertSame(150, (int) $ist->details->first()->fresh()->quantity_scanned);
    }
}
