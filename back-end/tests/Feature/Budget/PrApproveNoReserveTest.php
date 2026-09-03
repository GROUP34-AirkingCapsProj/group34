<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetTransaction;
use App\Models\PurchaseRequest;
use Illuminate\Support\Facades\DB;

class PrApproveNoReserveTest extends BudgetTestCase
{
    private function makePrWithLine(float $subtotal, int $statusId = 13): PurchaseRequest
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, $subtotal, $statusId);
        DB::table('purchase_request_details')->insert([
            'pr_id'       => $pr->pr_id,
            'product_id'  => (int) (DB::table('products')->value('product_id')
                ?? DB::table('products')->insertGetId([
                    'product_name' => 'P ' . uniqid(), 'product_code' => 'P-' . strtoupper(substr(uniqid(), -6)),
                    'product_type' => 'installation_material', 'created_at' => now(), 'updated_at' => now(),
                ], 'product_id')),
            'quantity'    => 1,
            'estimated_price' => $subtotal,
            'subtotal'    => $subtotal,
            'location_id' => $this->locationId,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        return $pr;
    }

    public function test_pr_approval_writes_no_commit_rows(): void
    {
        $pr = $this->makePrWithLine(4000);
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->patchJson("/api/purchase-requests/{$pr->pr_id}/approve")
            ->assertOk();

        $this->assertSame(14, (int) $pr->fresh()->status_id);
        $this->assertSame(0, BudgetTransaction::where('pr_id', $pr->pr_id)->where('txn_type', 'commit')->count());
    }

    public function test_over_budget_pr_approval_is_still_blocked(): void
    {
        $pr = $this->makePrWithLine(20000); // budget is only 10000
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->patchJson("/api/purchase-requests/{$pr->pr_id}/approve")
            ->assertStatus(422);

        $this->assertSame(13, (int) $pr->fresh()->status_id, 'stays pending');
    }
}
