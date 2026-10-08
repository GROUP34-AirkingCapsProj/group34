<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetTransaction;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class ReanchorCommitmentsTest extends BudgetTestCase
{
    private function anyProductId(): int
    {
        return (int) (DB::table('products')->value('product_id')
            ?? DB::table('products')->insertGetId([
                'product_name' => 'P ' . uniqid(), 'product_code' => 'P-' . strtoupper(substr(uniqid(), -6)),
                'product_type' => 'installation_material', 'created_at' => now(), 'updated_at' => now(),
            ], 'product_id'));
    }

    public function test_reanchor_releases_pr_holds_and_reserves_approved_pos(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);

        // Simulate the OLD world: a PR-anchored commit hold (po_id NULL).
        BudgetTransaction::create([
            'budget_id' => $budget->budget_id, 'txn_type' => 'commit', 'amount' => 4000,
            'pr_id' => $pr->pr_id, 'po_id' => null, 'notes' => 'legacy PR hold',
        ]);
        $budget->update(['committed' => 4000]);

        // An approved PO from that PR, not yet reserved under the new model.
        $po = $this->makePo($pr, 4000, 8);
        DB::table('purchase_order_details')->insert([
            'po_id' => $po->po_id, 'product_id' => $this->anyProductId(),
            'quantity_ordered' => 1, 'unit_price' => 4000, 'subtotal' => 4000,
            'location_id' => $this->locationId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Artisan::call('budget:reanchor-commitments');
        Artisan::call('budget:rebuild-totals');

        $budget->refresh();
        // Old PR hold released, new PO reservation in place → committed back to 4000 once.
        $this->assertSame('4000.00', (string) $budget->committed);
        $this->assertSame(1, BudgetTransaction::where('po_id', $po->po_id)->where('txn_type', 'commit')->count());
        // The legacy PR-anchored hold has an offsetting release.
        $prReleases = BudgetTransaction::where('pr_id', $pr->pr_id)->whereNull('po_id')->where('txn_type', 'release')->count();
        $this->assertSame(1, $prReleases);
    }
}
