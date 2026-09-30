<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryLedgerEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferService;
use Tests\Feature\Transfer\TransferTestCase;

/**
 * A transfer moves stock without creating or destroying any, so it owes the ledger
 * two rows that net to zero — an out leg at dispatch and an in leg at receipt. The
 * gap between them is the point: stock in transit shows as an out with no matching
 * in, which is exactly what an auditor wants to be able to see.
 *
 * These assert on quantity and direction, not value: makeBatch() does not set a
 * unit_cost, so these rows correctly carry cost_basis 'unavailable' and a null
 * extended_value.
 */
class TransferPostsTwoLegsTest extends TransferTestCase
{
    /** @return array{0: Transfer, 1: Product, 2: Location, 3: Location, 4: User} */
    private function dispatchableIst(int $qty = 10): array
    {
        $from    = $this->makeLocation('Source WH');
        $to      = $this->makeLocation('Dest WH');
        $product = $this->makeInstallationMaterial();
        $user    = $this->makeUser();

        $this->setStock($from->location_id, $product->product_id, $qty);
        $this->makeBatch($product, $from, [
            'quantity_remaining' => $qty,
            'received_at'        => now()->subMonth(),
        ]);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $product->product_id, 'quantity_requested' => $qty, 'quantity_scanned' => $qty],
        ]);

        return [$ist, $product, $from, $to, $user];
    }

    private function legs(Transfer $ist)
    {
        return InventoryLedgerEntry::where('correlation_id', 'transfers:' . $ist->transfer_id)
            ->orderBy('ledger_id')
            ->get();
    }

    public function test_dispatch_posts_an_out_leg_carrying_both_ends_of_the_route(): void
    {
        [$ist, , $from, $to, $user] = $this->dispatchableIst(10);

        app(TransferService::class)->dispatch($ist, $user->user_id);

        $rows = $this->legs($ist);

        $this->assertCount(1, $rows);
        $this->assertSame('transfer_out', $rows->first()->movement_type);
        $this->assertSame('out', $rows->first()->direction);
        $this->assertSame((int) $from->location_id, (int) $rows->first()->from_location_id);
        $this->assertSame((int) $to->location_id, (int) $rows->first()->to_location_id);
    }

    public function test_an_in_transit_transfer_shows_an_out_leg_with_no_matching_in(): void
    {
        [$ist, , , , $user] = $this->dispatchableIst(10);

        app(TransferService::class)->dispatch($ist, $user->user_id);

        $rows = $this->legs($ist);
        $this->assertSame(1, $rows->where('direction', 'out')->count());
        $this->assertSame(0, $rows->where('direction', 'in')->count());
    }

    public function test_a_completed_transfer_nets_to_zero_quantity(): void
    {
        [$ist, , , , $user] = $this->dispatchableIst(10);

        $service = app(TransferService::class);
        $service->dispatch($ist, $user->user_id);

        // receive() credits whatever the destination counted in, which the real flow
        // records during the receiving scan. Stand that step in here.
        $fresh = $ist->fresh('details');
        foreach ($fresh->details as $detail) {
            $detail->quantity_received = $detail->quantity_transferred;
            $detail->save();
        }

        $service->receive($fresh->fresh(), $user->user_id);

        $rows = $this->legs($ist);

        $this->assertGreaterThanOrEqual(2, $rows->count(), 'Dispatch and receipt each owe a leg.');
        $this->assertSame(1, $rows->where('movement_type', 'transfer_in')->count());

        $net = $rows->sum(fn ($r) => $r->direction === 'in' ? $r->quantity : -$r->quantity);
        $this->assertSame(0, (int) $net, 'A transfer moves stock without creating or destroying any.');
    }

    public function test_reversing_a_dispatched_transfer_posts_mirrors_rather_than_deleting(): void
    {
        [$ist, , , , $user] = $this->dispatchableIst(10);

        $service = app(TransferService::class);
        $service->dispatch($ist, $user->user_id);

        $before = $this->legs($ist);
        $this->assertCount(1, $before);

        $service->reverse($ist->fresh());

        $after = $this->legs($ist);

        $this->assertCount(2, $after, 'The original row must survive its own reversal.');
        $this->assertNull($after->first()->reverses_ledger_id);
        $this->assertSame((int) $after->first()->ledger_id, (int) $after->last()->reverses_ledger_id);
        $this->assertSame('in', $after->last()->direction);
        $this->assertSame('Transfer reversed', $after->last()->notes);
    }
}
