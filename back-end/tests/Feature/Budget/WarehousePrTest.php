<?php

namespace Tests\Feature\Budget;

use Illuminate\Support\Facades\DB;

class WarehousePrTest extends BudgetTestCase
{
    private int $supplierId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplierId = (int) DB::table('suppliers')->insertGetId([
            'supplier_name' => 'Supplier A ' . uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ], 'supplier_id');
    }

    private function warehouse(string $code = 'WH'): int
    {
        return (int) DB::table('locations')->insertGetId([
            'location_code' => $code . '-' . uniqid(),
            'location_name' => 'WH ' . $code,
            'location_type' => 'warehouse',
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], 'location_id');
    }

    private function showroom(): int
    {
        return (int) DB::table('locations')->insertGetId([
            'location_code' => 'SR-' . uniqid(),
            'location_name' => 'Showroom',
            'location_type' => 'showroom',
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], 'location_id');
    }

    private function product(bool $mapToSupplier = true): int
    {
        $pid = (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'Prod',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
        if ($mapToSupplier) {
            DB::table('supplier_product')->insert([
                'supplier_id' => $this->supplierId, 'product_id' => $pid,
                'product_price' => 80, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $pid;
    }

    private function payload(array $details, array $overrides = []): array
    {
        return array_merge([
            'supplier_id'    => $this->supplierId,
            'needed_by_date' => today()->addDays(7)->toDateString(),
            'details'        => $details,
        ], $overrides);
    }

    public function test_rejects_showroom_destination(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $this->makeBudget(10000);
        $sr = $this->showroom();
        $pid = $this->product();

        $res = $this->actingAs($ia, 'sanctum')->postJson('/api/purchase-requests', $this->payload([
            ['product_id' => $pid, 'location_id' => $sr, 'quantity' => 1, 'estimated_price' => 100],
        ]));

        $res->assertStatus(422);
        $this->assertStringContainsString('warehouse', strtolower($res->json('message')));
    }

    public function test_rejects_product_mapped_to_other_supplier(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $wh = $this->locationId;
        $this->makeBudget(10000);
        $otherSupplier = (int) DB::table('suppliers')->insertGetId([
            'supplier_name' => 'Other', 'created_at' => now(), 'updated_at' => now(),
        ], 'supplier_id');
        $pid = (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'Prod',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
        DB::table('supplier_product')->insert([
            'supplier_id' => $otherSupplier, 'product_id' => $pid,
            'product_price' => 80, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->actingAs($ia, 'sanctum')->postJson('/api/purchase-requests', $this->payload([
            ['product_id' => $pid, 'location_id' => $wh, 'quantity' => 1, 'estimated_price' => 100],
        ]));

        $res->assertStatus(422);
    }

    public function test_allows_unmapped_product(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $wh = $this->locationId;
        $this->makeBudget(10000, ['location_id' => $wh]);
        $pid = $this->product(false);

        $this->actingAs($ia, 'sanctum')->postJson('/api/purchase-requests', $this->payload([
            ['product_id' => $pid, 'location_id' => $wh, 'quantity' => 2, 'estimated_price' => 100],
        ]))->assertStatus(201);
    }

    public function test_accepts_multi_warehouse_pr_with_budgets(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $whA = $this->locationId;
        $whB = $this->warehouse('B');
        $this->makeBudget(10000, ['location_id' => $whA]);
        $this->makeBudget(10000, ['location_id' => $whB]);
        $pA = $this->product(); $pB = $this->product();

        $res = $this->actingAs($ia, 'sanctum')->postJson('/api/purchase-requests', $this->payload([
            ['product_id' => $pA, 'location_id' => $whA, 'quantity' => 2, 'estimated_price' => 100],
            ['product_id' => $pB, 'location_id' => $whB, 'quantity' => 3, 'estimated_price' => 100],
        ]));

        $res->assertStatus(201);
        $prId = $res->json('data.pr_id');
        $this->assertSame($this->supplierId, (int) \App\Models\PurchaseRequest::find($prId)->supplier_id);
        $this->assertSame(2, \App\Models\PurchaseRequestDetail::where('pr_id', $prId)->whereNotNull('location_id')->count());
    }

    public function test_blocks_when_a_warehouse_group_exceeds_its_budget(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $whA = $this->locationId;
        $whB = $this->warehouse('B');
        $this->makeBudget(10000, ['location_id' => $whA]);
        $this->makeBudget(100, ['location_id' => $whB]);
        $pA = $this->product(); $pB = $this->product();

        $res = $this->actingAs($ia, 'sanctum')->postJson('/api/purchase-requests', $this->payload([
            ['product_id' => $pA, 'location_id' => $whA, 'quantity' => 2, 'estimated_price' => 100],
            ['product_id' => $pB, 'location_id' => $whB, 'quantity' => 3, 'estimated_price' => 100],
        ]));

        $res->assertStatus(422);
        $this->assertStringContainsString('WH B', $res->json('message'));
    }
}
