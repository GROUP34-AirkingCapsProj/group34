<?php

namespace Tests\Feature\Ledger;

use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\InventoryLedgerEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IssuancePostsToLedgerTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0: Location, 1: Product, 2: User} */
    private function stockedLocation(int $olderQty, float $olderCost, int $newerQty, float $newerCost): array
    {
        $location = Location::create([
            'location_code' => 'L-' . uniqid(), 'location_name' => 'WH', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'M-' . uniqid(), 'product_name' => 'Foam Tape',
            'product_type' => 'installation_material', 'cost_price' => 10.00,
        ]);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 'iss_' . uniqid(), 'first_name' => 'Iss', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);

        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => $olderQty + $newerQty, 'available_quantity' => $olderQty + $newerQty,
            'reorder_level' => 0,
        ]);

        // Two batches at two costs, so a FIFO split has something to prove.
        // Each needs a real receiving: inventory_batches.receiving_id is a foreign
        // key, and deductFifo() only considers batches whose receiving_id is set.
        foreach ([[$olderQty, $olderCost, '2026-01-01'], [$newerQty, $newerCost, '2026-06-01']] as [$qty, $cost, $date]) {
            $receiving = Receiving::create([
                'receiving_number' => 'R-' . uniqid(),
                'location_id'      => $location->location_id,
                'receiving_date'   => $date,
            ]);

            InventoryBatch::create([
                'product_id' => $product->product_id, 'location_id' => $location->location_id,
                'receiving_id' => $receiving->receiving_id, 'quantity_received' => $qty, 'quantity_remaining' => $qty,
                'received_at' => $date, 'unit_cost' => $cost, 'cost_basis' => 'actual',
            ]);
        }

        return [$location, $product, $user];
    }

    public function test_an_issuance_that_spans_two_batches_posts_one_row_per_slice_at_its_own_cost(): void
    {
        [$location, $product, $user] = $this->stockedLocation(4, 100.00, 6, 200.00);

        $res = $this->actingAs($user)->postJson('/api/issuances', [
            'location_id'   => $location->location_id,
            'issued_by'     => $user->user_id,
            'issuance_date' => '2026-09-15',
            // Required by IssuanceController::store validation; 'individual' is the
            // value the existing issuance tests use. Omit it and the request 422s.
            'issuance_type' => 'individual',
            'details'       => [
                ['product_id' => $product->product_id, 'quantity_issued' => 6],
            ],
        ]);

        $res->assertStatus(201);
        $issuanceId = $res->json('data.issuance_id');

        $rows = InventoryLedgerEntry::where('source_type', 'issuances')
            ->where('source_id', $issuanceId)
            ->orderBy('unit_cost')
            ->get();

        $this->assertCount(2, $rows, 'A 6-piece issue over a 4-piece oldest batch is two slices.');
        $this->assertSame(['out', 'out'], $rows->pluck('direction')->all());
        $this->assertSame([4, 2], $rows->pluck('quantity')->map(fn ($q) => (int) $q)->all());
        $this->assertSame(['100.00', '200.00'], $rows->pluck('unit_cost')->all());
        $this->assertSame(['400.00', '400.00'], $rows->pluck('extended_value')->all());
        $this->assertSame('issuances:' . $issuanceId, $rows->first()->correlation_id);
        $this->assertSame((int) $location->location_id, (int) $rows->first()->from_location_id);
    }

    /**
     * Nothing in store()'s validation stops a request listing the same product on
     * two detail lines, and the ledger's posting_key is built from the detail row's
     * id. If the controller resolved that id by re-querying the issuance's details
     * for the product instead of keeping the row create() just returned, both lines
     * could land on one id, the two posting_keys would be identical, and
     * LedgerPoster would silently hand back the first row for the second line —
     * four pieces off the shelf with two pieces of evidence.
     */
    public function test_the_same_product_on_two_lines_posts_a_row_for_each_line(): void
    {
        [$location, $product, $user] = $this->stockedLocation(10, 100.00, 5, 200.00);

        $res = $this->actingAs($user)->postJson('/api/issuances', [
            'location_id'   => $location->location_id,
            'issued_by'     => $user->user_id,
            'issuance_date' => '2026-09-15',
            'issuance_type' => 'individual',
            'details'       => [
                ['product_id' => $product->product_id, 'quantity_issued' => 2],
                ['product_id' => $product->product_id, 'quantity_issued' => 2],
            ],
        ]);

        $res->assertStatus(201);
        $issuanceId = $res->json('data.issuance_id');

        $rows = InventoryLedgerEntry::where('source_type', 'issuances')
            ->where('source_id', $issuanceId)
            ->get();

        // Both lines draw from the same oldest batch, so only the detail row id
        // distinguishes the two postings.
        $this->assertCount(2, $rows, 'Each detail line owes the ledger its own row.');
        $this->assertSame(4, (int) $rows->sum('quantity'), 'Four pieces left; four must be recorded.');
        $this->assertCount(2, $rows->pluck('source_line_id')->unique(), 'The two rows must name different detail lines.');
        $this->assertCount(2, $rows->pluck('posting_key')->unique());
    }

    public function test_a_batch_with_no_recorded_cost_still_posts_the_quantity(): void
    {
        [$location, $product, $user] = $this->stockedLocation(5, 100.00, 5, 100.00);
        InventoryBatch::where('product_id', $product->product_id)
            ->update(['unit_cost' => null, 'cost_basis' => null]);

        $res = $this->actingAs($user)->postJson('/api/issuances', [
            'location_id'   => $location->location_id,
            'issued_by'     => $user->user_id,
            'issuance_date' => '2026-09-15',
            // Required by IssuanceController::store validation; 'individual' is the
            // value the existing issuance tests use. Omit it and the request 422s.
            'issuance_type' => 'individual',
            'details'       => [
                ['product_id' => $product->product_id, 'quantity_issued' => 3],
            ],
        ]);

        $res->assertStatus(201);

        $row = InventoryLedgerEntry::where('source_type', 'issuances')
            ->where('source_id', $res->json('data.issuance_id'))
            ->firstOrFail();

        $this->assertSame(3, (int) $row->quantity);
        $this->assertSame('unavailable', $row->cost_basis);
        $this->assertNull($row->extended_value);
    }
}
