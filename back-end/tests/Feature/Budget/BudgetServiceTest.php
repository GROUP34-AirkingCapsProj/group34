<?php

namespace Tests\Feature\Budget;

use App\Exceptions\InsufficientBudgetException;
use App\Models\BudgetTransaction;
use App\Services\BudgetService;

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
}
