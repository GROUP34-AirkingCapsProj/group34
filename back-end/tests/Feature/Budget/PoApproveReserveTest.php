<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetTransaction;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

class PoApproveReserveTest extends BudgetTestCase
{
    private function anyProductId(): int
    {
        return (int) (DB::table('products')->value('product_id')
            ?? DB::table('products')->insertGetId([
                'product_name' => 'P ' . uniqid(), 'product_code' => 'P-' . strtoupper(substr(uniqid(), -6)),
                'product_type' => 'consumable', 'created_at' => now(), 'updated_at' => now(),
            ], 'product_id'));
    }

    private function makeVerifiedPoWithLine(float $subtotal, int $statusId = 7): PurchaseOrder
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, $subtotal, $statusId);
        $po->update(['verification_status' => 'verified']);
        DB::table('purchase_order_details')->insert([
            'po_id' => $po->po_id, 'product_id' => $this->anyProductId(),
            'quantity_ordered' => 1, 'unit_price' => $subtotal, 'subtotal' => $subtotal,
            'location_id' => $this->locationId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $po->fresh();
    }

    public function test_po_approval_reserves_budget(): void
    {
        $po = $this->makeVerifiedPoWithLine(4000);
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 8])
            ->assertOk();

        $budget = DB::table('budget_allocations')->where('location_id', $this->locationId)->first();
        $this->assertSame('4000.00', number_format((float) $budget->committed, 2, '.', ''));
        $this->assertSame(1, BudgetTransaction::where('po_id', $po->po_id)->where('txn_type', 'commit')->count());
    }

    public function test_over_budget_po_approval_blocked_and_rolls_back(): void
    {
        $po = $this->makeVerifiedPoWithLine(20000); // budget only 10000
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 8])
            ->assertStatus(422);

        $this->assertSame(7, (int) $po->fresh()->status_id, 'stays pending');
        $this->assertSame(0, BudgetTransaction::where('po_id', $po->po_id)->count(), 'no partial ledger rows');
    }

    public function test_reject_after_approval_releases_reservation(): void
    {
        $po = $this->makeVerifiedPoWithLine(4000);
        $admin = $this->makeUser('admin');
        $this->actingAs($admin)->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 8])->assertOk();

        $this->actingAs($admin)->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 9])->assertOk();

        $budget = DB::table('budget_allocations')->where('location_id', $this->locationId)->first();
        $this->assertSame('0.00', number_format((float) $budget->committed, 2, '.', ''));
    }

    public function test_reapprove_after_reject_reserves_again(): void
    {
        $po = $this->makeVerifiedPoWithLine(4000);
        $admin = $this->makeUser('admin');
        $this->actingAs($admin)->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 8])->assertOk();
        $this->actingAs($admin)->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 9])->assertOk();
        $this->actingAs($admin)->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 8])->assertOk();

        $budget = \Illuminate\Support\Facades\DB::table('budget_allocations')->where('location_id', $this->locationId)->first();
        $this->assertSame('4000.00', number_format((float) $budget->committed, 2, '.', ''), 're-approval re-reserves');
    }
}
