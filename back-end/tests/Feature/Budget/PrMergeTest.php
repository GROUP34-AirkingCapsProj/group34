<?php

namespace Tests\Feature\Budget;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestDetail;
use Illuminate\Support\Facades\DB;

class PrMergeTest extends BudgetTestCase
{
    private int $supplierId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplierId = (int) DB::table('suppliers')->insertGetId([
            'supplier_name' => 'Sup ' . uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ], 'supplier_id');
    }

    private function warehouse(): int
    {
        return (int) DB::table('locations')->insertGetId([
            'location_code' => 'WH-' . uniqid(), 'location_name' => 'WH',
            'location_type' => 'warehouse', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], 'location_id');
    }

    private function product(): int
    {
        return (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
    }

    private function payload(int $productId, int $whId, int $qty, float $price, ?int $supplierId = null): array
    {
        return [
            'supplier_id' => $supplierId ?? $this->supplierId,
            'needed_by_date' => today()->addDays(7)->toDateString(),
            'details' => [
                ['product_id' => $productId, 'location_id' => $whId, 'quantity' => $qty, 'estimated_price' => $price],
            ],
        ];
    }

    public function test_second_submission_merges_into_pending_pr(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $wh = $this->locationId; // warehouse
        $this->makeBudget(100000, ['location_id' => $wh]);
        $pA = $this->product(); $pB = $this->product();

        $first = $this->actingAs($ia, 'sanctum')->postJson('/api/purchase-requests', $this->payload($pA, $wh, 2, 100))
            ->assertStatus(201)->json('data.pr_id');

        // Second submission, same supplier + requester, different product → merges.
        $res = $this->actingAs($ia, 'sanctum')->postJson('/api/purchase-requests', $this->payload($pB, $wh, 3, 100));
        $res->assertSuccessful();
        $this->assertSame($first, (int) $res->json('data.pr_id')); // SAME pr id
        $this->assertStringContainsString('pending request', strtolower($res->json('message')));

        // Only ONE PR for this supplier+requester.
        $this->assertSame(1, PurchaseRequest::where('requested_by', $ia->user_id)->where('supplier_id', $this->supplierId)->count());
        // Two detail lines (pA, pB), estimated_total = 200 + 300 = 500.
        $this->assertSame(2, PurchaseRequestDetail::where('pr_id', $first)->count());
        $this->assertSame('500.00', (string) PurchaseRequest::find($first)->estimated_total);
    }

    public function test_same_product_and_warehouse_sums_quantities(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $wh = $this->locationId;
        $this->makeBudget(100000, ['location_id' => $wh]);
        $p = $this->product();

        $first = $this->actingAs($ia, 'sanctum')->postJson('/api/purchase-requests', $this->payload($p, $wh, 2, 100))
            ->assertStatus(201)->json('data.pr_id');
        $this->actingAs($ia, 'sanctum')->postJson('/api/purchase-requests', $this->payload($p, $wh, 3, 999))
            ->assertSuccessful();

        // One line, qty 5, price kept as the FIRST line's 100 → subtotal 500.
        $this->assertSame(1, PurchaseRequestDetail::where('pr_id', $first)->count());
        $line = PurchaseRequestDetail::where('pr_id', $first)->first();
        $this->assertSame(5, (int) $line->quantity);
        $this->assertSame('100.00', (string) $line->estimated_price);
        $this->assertSame('500.00', (string) $line->subtotal);
        $this->assertSame('500.00', (string) PurchaseRequest::find($first)->estimated_total);
    }

    public function test_different_supplier_creates_new_pr(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $wh = $this->locationId;
        $this->makeBudget(100000, ['location_id' => $wh]);
        $p = $this->product();
        $this->actingAs($ia, 'sanctum')->postJson('/api/purchase-requests', $this->payload($p, $wh, 2, 100))->assertStatus(201);

        $otherSupplier = (int) DB::table('suppliers')->insertGetId(['supplier_name' => 'Other', 'created_at' => now(), 'updated_at' => now()], 'supplier_id');
        $this->actingAs($ia, 'sanctum')->postJson('/api/purchase-requests', $this->payload($p, $wh, 1, 100, $otherSupplier))
            ->assertStatus(201);

        $this->assertSame(2, PurchaseRequest::where('requested_by', $ia->user_id)->count());
    }

    public function test_different_requester_creates_new_pr(): void
    {
        $ia1 = $this->makeUser('inventory_analyst');
        $ia2 = $this->makeUser('inventory_analyst');
        $wh = $this->locationId;
        $this->makeBudget(100000, ['location_id' => $wh]);
        $p = $this->product();
        $this->actingAs($ia1, 'sanctum')->postJson('/api/purchase-requests', $this->payload($p, $wh, 2, 100))->assertStatus(201);
        $this->actingAs($ia2, 'sanctum')->postJson('/api/purchase-requests', $this->payload($p, $wh, 2, 100))->assertStatus(201);

        $this->assertSame(1, PurchaseRequest::where('requested_by', $ia1->user_id)->count());
        $this->assertSame(1, PurchaseRequest::where('requested_by', $ia2->user_id)->count());
    }

    public function test_combined_budget_advisory_blocks_merge_over_budget(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $wh = $this->locationId;
        $this->makeBudget(500, ['location_id' => $wh]); // small
        $p = $this->product();

        // First fits (400).
        $first = $this->actingAs($ia, 'sanctum')->postJson('/api/purchase-requests', $this->payload($p, $wh, 4, 100))
            ->assertStatus(201)->json('data.pr_id');
        // Second alone (200) would fit, but combined (400+200=600) exceeds 500 → blocked.
        $res = $this->actingAs($ia, 'sanctum')->postJson('/api/purchase-requests', $this->payload($p, $wh, 2, 100));
        $res->assertStatus(422);

        // The first PR is unchanged (still qty 4 / 400).
        $this->assertSame('400.00', (string) PurchaseRequest::find($first)->estimated_total);
    }
}
