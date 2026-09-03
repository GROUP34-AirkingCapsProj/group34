<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetTransaction;
use App\Models\PurchaseOrder;
use App\Services\BudgetService;
use Illuminate\Support\Facades\DB;

class PoSettleTest extends BudgetTestCase
{
    private BudgetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BudgetService::class);
    }

    private function anyProductId(): int
    {
        return (int) (DB::table('products')->value('product_id')
            ?? DB::table('products')->insertGetId([
                'product_name' => 'Budget Test Product ' . uniqid(),
                'product_code' => 'BTP-' . strtoupper(substr(uniqid(), -6)),
                'product_type' => 'installation_material',
                'created_at'   => now(),
                'updated_at'   => now(),
            ], 'product_id'));
    }

    private function addPoLine(PurchaseOrder $po, float $subtotal, ?int $locationId = null): int
    {
        return (int) DB::table('purchase_order_details')->insertGetId([
            'po_id'            => $po->po_id,
            'product_id'       => $this->anyProductId(),
            'quantity_ordered' => 1,
            'unit_price'       => $subtotal,
            'subtotal'         => $subtotal,
            'location_id'      => $locationId,
            'created_at'       => now(),
            'updated_at'       => now(),
        ], 'po_detail_id');
    }

    private function receive(int $poDetailId, int $qty): void
    {
        $receivingId = (int) DB::table('receivings')->insertGetId([
            'pc_id'      => DB::table('purchase_order_details')->where('po_detail_id', $poDetailId)->value('po_id'),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'receiving_id');
        DB::table('receiving_details')->insert([
            'receiving_id'    => $receivingId,
            'po_detail_id'    => $poDetailId,
            'quantity_amount' => $qty,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public function test_spend_settles_po_reservation_and_releases_it(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, 4000, 8);
        $this->addPoLine($po, 4000);
        $this->service->commitPo($po->fresh()->load('details'));

        $budget->refresh();
        $this->assertSame('4000.00', (string) $budget->committed);

        $po->update(['status_id' => 11]);
        $this->service->spend($po->fresh()->load('details'));

        $budget->refresh();
        $this->assertSame('0.00', (string) $budget->committed, 'reservation released');
        $this->assertSame('4000.00', (string) $budget->spent, 'actual recorded');
    }

    public function test_spend_is_idempotent_per_po(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, 4000, 8);
        $this->addPoLine($po, 4000);
        $this->service->commitPo($po->fresh()->load('details'));
        $po->update(['status_id' => 11]);

        $this->service->spend($po->fresh()->load('details'));
        $this->service->spend($po->fresh()->load('details'));

        $budget->refresh();
        $this->assertSame('4000.00', (string) $budget->spent);
        $this->assertSame(1, BudgetTransaction::where('po_id', $po->po_id)->where('txn_type', 'spend')->count());
    }

    public function test_release_commitment_returns_reservation(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, 4000, 8);
        $this->addPoLine($po, 4000);
        $this->service->commitPo($po->fresh()->load('details'));

        $released = $this->service->releaseCommitment($po->fresh(), 'PO rejected');

        $budget->refresh();
        $this->assertSame('4000.00', number_format($released, 2, '.', ''));
        $this->assertSame('0.00', (string) $budget->committed);
        $this->assertSame('0.00', (string) $budget->spent);
    }
}
