<?php

namespace Tests\Feature\Budget;

use App\Events\BudgetChanged;
use App\Exceptions\InsufficientBudgetException;
use App\Exceptions\UnverifiedBudgetException;
use App\Models\BudgetTransaction;
use App\Services\BudgetService;
use Illuminate\Support\Facades\Event;

class BudgetServiceTest extends BudgetTestCase
{
    private BudgetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BudgetService::class);
    }

    public function test_commit_writes_ledger_row_and_increments_committed(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);

        $this->service->commit($pr);

        $budget->refresh();
        $this->assertSame('4000.00', (string) $budget->committed);
        $this->assertSame('0.00', (string) $budget->spent);

        $txn = BudgetTransaction::where('pr_id', $pr->pr_id)->first();
        $this->assertNotNull($txn);
        $this->assertSame('commit', $txn->txn_type);
        $this->assertSame('4000.00', (string) $txn->amount);
        $this->assertEquals($budget->budget_id, $txn->budget_id);
    }

    public function test_commit_is_idempotent_per_pr(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);

        $this->service->commit($pr);
        $this->service->commit($pr); // retry must be a no-op

        $budget->refresh();
        $this->assertSame('4000.00', (string) $budget->committed);
        $this->assertSame(1, BudgetTransaction::where('pr_id', $pr->pr_id)->where('txn_type', 'commit')->count());
    }

    public function test_commit_throws_when_insufficient_available(): void
    {
        $budget = $this->makeBudget(10000, ['committed' => 7000]);
        $pr = $this->makePr($budget, 4000, 14);

        $this->expectException(InsufficientBudgetException::class);
        $this->service->commit($pr);
    }

    public function test_failed_commit_leaves_no_ledger_row_and_no_column_change(): void
    {
        $budget = $this->makeBudget(10000, ['committed' => 7000]);
        $pr = $this->makePr($budget, 4000, 14);

        try {
            $this->service->commit($pr);
        } catch (InsufficientBudgetException $e) {
            // expected
        }

        $budget->refresh();
        $this->assertSame('7000.00', (string) $budget->committed);
        $this->assertSame(0, BudgetTransaction::where('pr_id', $pr->pr_id)->count());
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

    public function test_release_outstanding_returns_exact_committed_amount(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $this->service->commit($pr);

        $released = $this->service->releaseOutstanding($pr, 'PR archived');

        $this->assertSame(4000.0, $released);
        $budget->refresh();
        $this->assertSame('0.00', (string) $budget->committed);

        $release = BudgetTransaction::where('pr_id', $pr->pr_id)->where('txn_type', 'release')->first();
        $this->assertNotNull($release);
        $this->assertSame('4000.00', (string) $release->amount);
        $this->assertSame('PR archived', $release->notes);
    }

    public function test_release_outstanding_is_noop_without_commitment_and_never_double_releases(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 13); // pending — never committed

        $this->assertSame(0.0, $this->service->releaseOutstanding($pr, 'PR archived'));
        $this->assertSame(0, BudgetTransaction::where('pr_id', $pr->pr_id)->count());

        // Commit, release twice — second release must be a no-op.
        $pr->update(['status_id' => 14]);
        $this->service->commit($pr);
        $this->service->releaseOutstanding($pr, 'PR archived');
        $this->assertSame(0.0, $this->service->releaseOutstanding($pr, 'PR archived'));

        $budget->refresh();
        $this->assertSame('0.00', (string) $budget->committed);
        $this->assertSame(1, BudgetTransaction::where('pr_id', $pr->pr_id)->where('txn_type', 'release')->count());
    }

    public function test_release_outstanding_is_noop_for_pr_without_budget(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14, ['budget_id' => null]);

        $this->assertSame(0.0, $this->service->releaseOutstanding($pr, 'PR archived'));
    }

    public function test_spend_records_actual_and_consumes_commitment_with_surplus_return(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $this->service->commit($pr);

        // Fully-converted PR: actual PO total (3500) below estimate (4000)
        $po = $this->makePo($pr, 3500, 11);
        $pr->update(['converted_to_po_id' => $po->po_id]);

        $this->service->spend($po->fresh());

        $budget->refresh();
        $this->assertSame('3500.00', (string) $budget->spent);
        $this->assertSame('0.00', (string) $budget->committed); // 3500 consumed + 500 surplus returned

        $releases = BudgetTransaction::where('pr_id', $pr->pr_id)->where('txn_type', 'release')
            ->orderBy('txn_id')->get();
        $this->assertCount(2, $releases);
        $this->assertSame('3500.00', (string) $releases[0]->amount);
        $this->assertSame('500.00', (string) $releases[1]->amount);
        $this->assertSame('Estimate surplus returned', $releases[1]->notes);
    }

    public function test_spend_is_idempotent_per_po(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $this->service->commit($pr);
        $po = $this->makePo($pr, 4000, 11);
        $pr->update(['converted_to_po_id' => $po->po_id]);

        $this->service->spend($po->fresh());
        $this->service->spend($po->fresh()); // second call must be a no-op

        $budget->refresh();
        $this->assertSame('4000.00', (string) $budget->spent);
        $this->assertSame('0.00', (string) $budget->committed);
        $this->assertSame(1, BudgetTransaction::where('po_id', $po->po_id)->where('txn_type', 'spend')->count());
    }

    public function test_spend_on_partial_conversion_keeps_remaining_commitment(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 16); // Partially Converted
        // commit happened while approved
        $pr->update(['status_id' => 14]);
        $this->service->commit($pr);
        $pr->update(['status_id' => 16]);

        $po = $this->makePo($pr, 1500, 11);
        $pr->update(['converted_to_po_id' => $po->po_id]);

        $this->service->spend($po->fresh());

        $budget->refresh();
        $this->assertSame('1500.00', (string) $budget->spent);
        $this->assertSame('2500.00', (string) $budget->committed); // no surplus return on partial
    }

    public function test_spend_records_overrun_when_actual_exceeds_estimate(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $this->service->commit($pr);
        $po = $this->makePo($pr, 4600, 11); // actual above estimate
        $pr->update(['converted_to_po_id' => $po->po_id]);

        $this->service->spend($po->fresh());

        $budget->refresh();
        $this->assertSame('4600.00', (string) $budget->spent); // truth recorded
        $this->assertSame('0.00', (string) $budget->committed); // only 4000 was held; consume is capped
    }

    public function test_spend_is_noop_for_po_without_budgeted_pr(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14, ['budget_id' => null]);
        $po = $this->makePo($pr, 4000, 11);

        $this->service->spend($po->fresh());

        $budget->refresh();
        $this->assertSame('0.00', (string) $budget->spent);
        $this->assertSame(0, BudgetTransaction::count());
    }

    public function test_release_after_partial_spend_releases_only_remainder(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 16);
        $pr->update(['status_id' => 14]);
        $this->service->commit($pr);
        $pr->update(['status_id' => 16]);
        $po = $this->makePo($pr, 1500, 11);
        $pr->update(['converted_to_po_id' => $po->po_id]);
        $this->service->spend($po->fresh());

        // Archiving the partially-converted PR now returns only the un-consumed 2500.
        $released = $this->service->releaseOutstanding($pr->fresh(), 'PR archived');

        $this->assertSame(2500.0, $released);
        $budget->refresh();
        $this->assertSame('0.00', (string) $budget->committed);
        $this->assertSame('1500.00', (string) $budget->spent);
    }

    public function test_two_sequential_pos_consume_commitment_cumulatively_with_final_surplus(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 16); // partial conversion first
        $pr->update(['status_id' => 14]);
        $this->service->commit($pr);
        $pr->update(['status_id' => 16]);

        // PO1: partial, 1500 actual
        $po1 = $this->makePo($pr, 1500, 11);
        $pr->update(['converted_to_po_id' => $po1->po_id]);
        $this->service->spend($po1->fresh());

        // PO2 completes the PR: fully converted now (status 14), 2000 actual
        $po2 = $this->makePo($pr, 2000, 11);
        $pr->update(['status_id' => 14, 'converted_to_po_id' => $po2->po_id]);
        $this->service->spend($po2->fresh());

        $budget->refresh();
        $this->assertSame('3500.00', (string) $budget->spent);     // 1500 + 2000
        $this->assertSame('0.00', (string) $budget->committed);    // 1500 + 2000 consumed + 500 surplus returned
        $this->assertSame('500.00', (string) BudgetTransaction::where('pr_id', $pr->pr_id)
            ->where('txn_type', 'release')->where('notes', 'Estimate surplus returned')->value('amount'));
    }

    public function test_commit_fires_alert_when_crossing_80_percent(): void
    {
        Event::fake([BudgetChanged::class]);

        $budget = $this->makeBudget(10000);
        $pr1 = $this->makePr($budget, 7000, 14);
        $this->service->commit($pr1); // 70% — below threshold
        Event::assertNotDispatched(BudgetChanged::class);

        $pr2 = $this->makePr($budget, 1500, 14);
        $this->service->commit($pr2); // 85% — crosses 80%
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
        $this->service->commit($this->makePr($budget, 8500, 14)); // crosses 80%
        $this->service->commit($this->makePr($budget, 500, 14));  // 90% — already above, no new crossing

        Event::assertDispatchedTimes(BudgetChanged::class, 1);
    }

    public function test_spend_can_cross_100_percent(): void
    {
        Event::fake([BudgetChanged::class]);

        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 9000, 14);
        $this->service->commit($pr); // crosses 80% (1st dispatch)
        $po = $this->makePo($pr, 10500, 11); // overrun pushes past 100%
        $pr->update(['converted_to_po_id' => $po->po_id]);
        $this->service->spend($po->fresh()); // crosses 100% (2nd dispatch)

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

    public function test_commit_throws_when_budget_unverified(): void
    {
        $budget = $this->makeBudget(10000, ['verification_status' => 'pending']);
        $pr = $this->makePr($budget, 4000, 14);

        $this->expectException(UnverifiedBudgetException::class);
        $this->service->commit($pr);
    }

    public function test_commit_throws_when_budget_rejected(): void
    {
        $budget = $this->makeBudget(10000, ['verification_status' => 'rejected']);
        $pr = $this->makePr($budget, 4000, 14);

        $this->expectException(UnverifiedBudgetException::class);
        $this->service->commit($pr);
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
