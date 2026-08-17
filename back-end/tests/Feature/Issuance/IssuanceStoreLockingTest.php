<?php

namespace Tests\Feature\Issuance;

use App\Models\Inventory;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sets\SetTestCase;

/**
 * POST /issuances must not oversell.
 *
 * The endpoint read the inventory row unlocked, checked availability, then
 * deducted — a check-then-act race. Two requests for the last 5 pieces both
 * read 5, both passed the check, and both deducted: ten pieces issued against
 * five in stock. Because the write is max(0, ...), the row landed on 0 rather
 * than going negative, so nothing ever surfaced it.
 *
 * It also calls InventoryBatchService::deductFifo(), whose class contract
 * requires the caller to already hold the Inventory row lock — its own
 * lockForUpdate() calls rely on that outer lock to serialize.
 * IssuanceScanController does all of this correctly; this endpoint did not.
 *
 * A real two-connection race cannot be reproduced here: DatabaseTransactions
 * keeps these fixtures in an uncommitted transaction that a second connection
 * cannot see. So the lock is asserted directly from the query log, which fails
 * just as loudly if someone removes it.
 */
class IssuanceStoreLockingTest extends SetTestCase
{
    /** @return array<int,string> every statement run while $callback executed */
    private function captureQueries(callable $callback): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $callback();

        return $queries;
    }

    private function payload(int $locationId, int $productId, int $qty): array
    {
        return [
            'location_id'    => $locationId,
            'issuance_date'  => now()->toDateString(),
            'issuance_type'  => 'internal',
            'details'        => [
                ['product_id' => $productId, 'quantity_issued' => $qty],
            ],
        ];
    }

    public function test_it_locks_the_inventory_row_before_deducting(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $admin    = $this->makeUserAt($location, 'admin');
        $this->setStock($location->location_id, $product->product_id, 10);

        $this->actingAs($admin, 'sanctum');

        $queries = $this->captureQueries(function () use ($location, $product) {
            $this->postJson('/api/issuances', $this->payload(
                $location->location_id, $product->product_id, 3
            ))->assertCreated();
        });

        $lockedInventoryReads = array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'from `inventory`') && str_contains($sql, 'for update')
        );

        $this->assertNotEmpty(
            $lockedInventoryReads,
            'The inventory row must be read with lockForUpdate() before it is checked and deducted, '
            . 'or two concurrent issuances can both pass the availability check.'
        );
    }

    /** The lock must not change what the endpoint actually does. */
    public function test_it_still_deducts_the_issued_quantity(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $admin    = $this->makeUserAt($location, 'admin');
        $this->setStock($location->location_id, $product->product_id, 10);

        $this->actingAs($admin, 'sanctum');

        $this->postJson('/api/issuances', $this->payload(
            $location->location_id, $product->product_id, 4
        ))->assertCreated();

        $inventory = Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)
            ->first();

        $this->assertSame(6, (int) $inventory->quantity_on_hand);
    }

    public function test_it_still_refuses_to_issue_more_than_is_on_hand(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $admin    = $this->makeUserAt($location, 'admin');
        $this->setStock($location->location_id, $product->product_id, 2);

        $this->actingAs($admin, 'sanctum');

        $this->postJson('/api/issuances', $this->payload(
            $location->location_id, $product->product_id, 5
        ))->assertStatus(500);

        $inventory = Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)
            ->first();

        $this->assertSame(2, (int) $inventory->quantity_on_hand, 'A rejected issuance must not move stock.');
    }
}
