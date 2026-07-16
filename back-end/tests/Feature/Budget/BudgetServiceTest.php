<?php

namespace Tests\Feature\Budget;

use App\Events\BudgetChanged;
use App\Exceptions\InsufficientBudgetException;
use App\Exceptions\UnverifiedBudgetException;
use App\Models\BudgetTransaction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Services\BudgetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class BudgetServiceTest extends BudgetTestCase
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
                'product_type' => 'consumable',
                'created_at'   => now(),
                'updated_at'   => now(),
            ], 'product_id'));
    }

    private function addPoLine(PurchaseOrder $po, float $subtotal, ?int $locationId = null): void
    {
        DB::table('purchase_order_details')->insert([
            'po_id'            => $po->po_id,
            'product_id'       => $this->anyProductId(),
            'quantity_ordered' => 1,
            'unit_price'       => $subtotal,
            'subtotal'         => $subtotal,
            'location_id'      => $locationId,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    // NOTE: basic commitPo/spend/releaseCommitment happy-path coverage now lives in
    // PoCommitTest and PoSettleTest (po-anchored). This file keeps the scenarios that
    // aren't duplicated there: rollback-on-failure, no-budget no-ops, rejected-budget
    // guard, alert crossings, and adjustTotal (unchanged by the po-anchoring move).

    public function test_failed_commit_leaves_no_ledger_row_and_no_column_change(): void
    {
        $budget = $this->makeBudget(10000, ['committed' => 7000]);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, 4000, 7);
        $this->addPoLine($po, 4000);

        try {
            $this->service->commitPo($po->fresh()->load('details'));
        } catch (InsufficientBudgetException $e) {
            // expected
        }

        $budget->refresh();
        $this->assertSame('7000.00', (string) $budget->committed);
        $this->assertSame(0, BudgetTransaction::where('po_id', $po->po_id)->count());
    }

    public function test_has_available_and_resolve_active_budget(): void
    {
        $budget = $this->makeBudget(10000, ['committed' => 2000, 'spent' => 3000]);

        $this->assertTrue($this->service->hasAvailable($budget->fresh(), 5000.0));
        $this->assertFalse($this->service->hasAvailable($budget->fresh(), 5000.01));

        $resolved = $this->service->resolveActiveBudget($this->locationId);
        $this->assertNotNull($resolved);
        $this->assertSame($budget->budget_id, $resolved->budget_id);

        // Expired budget is not resolved
        $this->makeBudget(500, [
            'period_start' => today()->subDays(40)->toDateString(),
            'period_end'   => today()->subDays(20)->toDateString(),
        ]);
        $this->assertSame($budget->budget_id, $this->service->resolveActiveBudget($this->locationId)->budget_id);
    }

    public function test_release_commitment_is_noop_without_commitment_and_never_double_releases(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, 4000, 7);
        $this->addPoLine($po, 4000);

        // Never committed — releasing must be a no-op.
        $this->assertSame(0.0, $this->service->releaseCommitment($po->fresh(), 'PO rejected'));
        $this->assertSame(0, BudgetTransaction::where('po_id', $po->po_id)->count());

        // Commit, release twice — second release must be a no-op.
        $this->service->commitPo($po->fresh()->load('details'));
        $this->service->releaseCommitment($po->fresh(), 'PO rejected');
        $this->assertSame(0.0, $this->service->releaseCommitment($po->fresh(), 'PO rejected'));

        $budget->refresh();
        $this->assertSame('0.00', (string) $budget->committed);
        $this->assertSame(1, BudgetTransaction::where('po_id', $po->po_id)->where('txn_type', 'release')->count());
    }

    public function test_spend_is_noop_for_po_in_location_without_active_budget(): void
    {
        // A location with no budget allocation at all.
        $bareLocationId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'BARE-' . uniqid(),
            'location_name' => 'No Budget Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');

        $pr = PurchaseRequest::create([
            'location_id'     => $bareLocationId,
            'budget_id'       => null,
            'pr_number'       => 'PR-TEST-' . strtoupper(\Illuminate\Support\Str::random(6)),
            'request_date'    => today(),
            'needed_by_date'  => today()->addDays(7),
            'estimated_total' => 0,
            'status_id'       => 14,
            'requested_by'    => $this->makeUser('inventory_analyst')->user_id,
        ]);
        $po = $this->makePo($pr, 4000, 11);
        $this->addPoLine($po, 4000, $bareLocationId);

        $this->service->spend($po->fresh()->load('details'));

        $this->assertSame(0, BudgetTransaction::count());
    }

    public function test_commit_po_fires_alert_when_crossing_80_percent(): void
    {
        Event::fake([BudgetChanged::class]);

        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 0, 14);

        $po1 = $this->makePo($pr, 7000, 7);
        $this->addPoLine($po1, 7000);
        $this->service->commitPo($po1->fresh()->load('details')); // 70% — below threshold
        Event::assertNotDispatched(BudgetChanged::class);

        $po2 = $this->makePo($pr, 1500, 7);
        $this->addPoLine($po2, 1500);
        $this->service->commitPo($po2->fresh()->load('details')); // 85% — crosses 80%
        Event::assertDispatched(BudgetChanged::class, function (BudgetChanged $e) use ($budget) {
            return $e->locationId === (int) $budget->location_id
                && $e->type === 'budget_threshold'
                && $e->extra['utilization_pct'] === 85;
        });
    }

    public function test_alert_fires_once_per_crossing_not_on_every_commit_above(): void
    {
        Event::fake([BudgetChanged::class]);

        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 0, 14);

        $po1 = $this->makePo($pr, 8500, 7);
        $this->addPoLine($po1, 8500);
        $this->service->commitPo($po1->fresh()->load('details')); // crosses 80%

        $po2 = $this->makePo($pr, 500, 7);
        $this->addPoLine($po2, 500);
        $this->service->commitPo($po2->fresh()->load('details')); // 90% — already above, no new crossing

        Event::assertDispatchedTimes(BudgetChanged::class, 1);
    }

    public function test_spend_can_cross_100_percent(): void
    {
        Event::fake([BudgetChanged::class]);

        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 0, 14);

        $po1 = $this->makePo($pr, 9000, 7);
        $this->addPoLine($po1, 9000);
        $this->service->commitPo($po1->fresh()->load('details')); // crosses 80% (1st dispatch)

        // Direct spend without a prior reservation pushes utilization past 100%.
        $po2 = $this->makePo($pr, 2000, 11);
        $this->addPoLine($po2, 2000);
        $this->service->spend($po2->fresh()->load('details')); // crosses 100% (2nd dispatch)

        Event::assertDispatchedTimes(BudgetChanged::class, 2);
    }

    public function test_adjust_total_writes_adjustment_txn_and_updates_total(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);

        $this->service->adjustTotal($budget, 15000, $admin->user_id);

        $budget->refresh();
        $this->assertSame('15000.00', (string) $budget->total_budget);

        $txn = BudgetTransaction::where('budget_id', $budget->budget_id)->where('txn_type', 'adjustment')->first();
        $this->assertNotNull($txn);
        $this->assertSame('5000.00', (string) $txn->amount);
        $this->assertStringContainsString('increased', $txn->notes);
    }

    public function test_adjust_total_rejects_lowering_below_committed_plus_spent(): void
    {
        $budget = $this->makeBudget(10000, ['committed' => 4000, 'spent' => 3000]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->adjustTotal($budget, 6000);
    }

    public function test_adjust_total_noop_when_unchanged(): void
    {
        $budget = $this->makeBudget(10000);
        $this->service->adjustTotal($budget, 10000);

        $this->assertSame(0, BudgetTransaction::where('txn_type', 'adjustment')->where('budget_id', $budget->budget_id)->count());
    }

    public function test_commit_po_throws_when_budget_rejected(): void
    {
        $budget = $this->makeBudget(10000, ['verification_status' => 'rejected']);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, 4000, 7);
        $this->addPoLine($po, 4000);

        $this->expectException(UnverifiedBudgetException::class);
        $this->service->commitPo($po->fresh()->load('details'));
    }

    public function test_resolve_active_budget_skips_unverified(): void
    {
        $this->makeBudget(10000, ['verification_status' => 'pending']);
        $this->assertNull($this->service->resolveActiveBudget($this->locationId));

        $verified = $this->makeBudget(20000);
        $this->assertSame($verified->budget_id, $this->service->resolveActiveBudget($this->locationId)->budget_id);
    }

    public function test_adjust_total_change_resets_verification(): void
    {
        $budget = $this->makeBudget(10000); // verified by default
        $this->service->adjustTotal($budget, 15000);

        $budget->refresh();
        $this->assertSame('pending', $budget->verification_status);
        $this->assertNull($budget->verified_by);
        $this->assertNull($budget->verified_at);
    }

    public function test_adjust_total_noop_does_not_reset_verification(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000, ['verified_by' => $admin->user_id, 'verified_at' => now()]);
        $this->service->adjustTotal($budget, 10000); // unchanged → no-op

        $budget->refresh();
        $this->assertSame('verified', $budget->verification_status);
        $this->assertSame($admin->user_id, (int) $budget->verified_by);
    }
}
