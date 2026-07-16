# PO-Anchored Budget Encumbrance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reserve budget at PO approval (Pending→Approved) instead of PR approval; a PR only validates, never reserves.

**Architecture:** Re-anchor the `budget_transactions` ledger from `pr_id` to `po_id`. `BudgetService` gains `commitPo()` (reserve at PO approval) and `releaseCommitment()` (release on PO reject/cancel); `spend()` settles a PO against its own reservation. `PurchaseRequestController::approve()` becomes validate-but-don't-reserve. `PurchaseOrderController` reserves on the transition into Approved(8)/Authorized(10) and releases on Rejected(9). A one-time `budget:reanchor-commitments` command moves existing holds, and `budget:rebuild-totals` reconciles cached columns.

**Tech Stack:** Laravel 11, PHPUnit (feature tests over `airkingV5_test` with `DatabaseTransactions`), MariaDB.

---

## Background (read before starting)

Run backend commands from `back-end/`. Test DB is `airkingV5_test`; run a single test file with:
`cd back-end && php artisan test --filter=<ClassName>` (uses `.env.testing` / phpunit config already set up).

**Current budget flow (verified):**
- `PurchaseRequestController::approve()` (`back-end/app/Http/Controllers/Api/PurchaseRequestController.php:442`) sets PR status 14 and calls `BudgetService::commitGroup()` per warehouse group (or legacy `commit()`). It also, on PR archive (~line 621), calls `releaseOutstanding()`.
- `PurchaseOrderController::update()` (`:202`) gates transitions into `[8,10,11,12]` on `verification_status==='verified'`; when status becomes `11` and `$po->pr_id` is set, calls `BudgetService::spend()`. `reject()` (~`:342`) sets status to Rejected(9).
- `BudgetService` (`back-end/app/Services/BudgetService.php`):
  - `commit(pr)` / `commitGroup(pr,budget,amount)` — pr-keyed reserve.
  - `spend(po)` — settles against the PR's commitment (pr-keyed); returns early if no PR.
  - `releaseOutstanding(pr,note)` — pr-keyed release.
  - `outstanding(budgetId,prId)` — pr-keyed Σcommit−Σrelease.
  - private helpers: `resolveBudgetForLocation(pr,loc)`, `poCoversLocationGroup(pr,po,loc)`, `groupFullyReceived(lines)`, `txn(...)`, `utilization()`, `maybeAlert()`, `resolveActiveBudget(loc)`, `hasAvailable()`.
  - `BudgetTransaction::TYPE_COMMIT|TYPE_RELEASE|TYPE_SPEND|TYPE_ADJUSTMENT`; `txn()` signature: `txn(BudgetAllocation $budget, string $type, float $amount, ?int $prId, ?int $poId, ?int $userId, ?string $note)`.
- `budget:rebuild-totals` recomputes `committed = Σcommit − Σrelease` and `spent = Σspend` from the ledger (per budget). So writing correct ledger rows + running rebuild-totals fixes cached columns.

PO status_lookup (category `purchase_order`): `7 Pending, 8 Approved, 9 Rejected, 10 Authorized, 11 Completed, 12 Partially Received`.

Test helpers (`back-end/tests/Feature/Budget/BudgetTestCase.php`): `makeBudget(total, overrides)` (verified by default), `makePr(budget, estTotal, statusId=13, overrides)`, `makePo(pr, totalAmount, statusId=7)`. Note `makePo` creates a PO **without** detail lines — for group tests you must add `purchase_order_details` rows manually (see Task 1 helper).

Spec: `docs/superpowers/specs/2026-07-16-po-anchored-budget-encumbrance-design.md`.

---

## File Structure

- **Modify** `back-end/app/Services/BudgetService.php` — add `commitPo`, `commitPoGroup`, `releaseCommitment`, `outstandingForPo`, `resolveBudgetForPo`; rewrite `spend`/`spendWhole`/`spendGroup` to be po-anchored; remove now-unused `commit`, `commitGroup`, `releaseOutstanding`, `outstanding(prId)`, `resolveBudgetForLocation`, `poCoversLocationGroup`.
- **Modify** `back-end/app/Http/Controllers/Api/PurchaseRequestController.php` — `approve()` validate-not-reserve; drop the PR-archive `releaseOutstanding` call.
- **Modify** `back-end/app/Http/Controllers/Api/PurchaseOrderController.php` — reserve on →8/10, reserve-before-spend on →11/12, release on →9 (`update()` + `reject()`).
- **Create** `back-end/app/Console/Commands/ReanchorBudgetCommitments.php` — one-time migration.
- **Create/Modify** tests under `back-end/tests/Feature/Budget/`.

---

### Task 1: BudgetService — PO reservation primitives (`commitPo`, `outstandingForPo`, `resolveBudgetForPo`)

**Files:**
- Modify: `back-end/app/Services/BudgetService.php`
- Test: `back-end/tests/Feature/Budget/PoCommitTest.php` (create)

- [ ] **Step 1: Write the failing test** — create `back-end/tests/Feature/Budget/PoCommitTest.php`:

```php
<?php

namespace Tests\Feature\Budget;

use App\Exceptions\InsufficientBudgetException;
use App\Exceptions\UnverifiedBudgetException;
use App\Models\BudgetTransaction;
use App\Models\PurchaseOrder;
use App\Services\BudgetService;
use Illuminate\Support\Facades\DB;

class PoCommitTest extends BudgetTestCase
{
    private BudgetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BudgetService::class);
    }

    /** Add a PO detail line for a warehouse (location null → PO header location). */
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

    public function test_commit_po_reserves_from_line_subtotals(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, 4000, 7);
        $this->addPoLine($po, 2500);
        $this->addPoLine($po, 1500);

        $this->service->commitPo($po->fresh()->load('details'));

        $budget->refresh();
        $this->assertSame('4000.00', (string) $budget->committed);
        $txn = BudgetTransaction::where('po_id', $po->po_id)->where('txn_type', 'commit')->first();
        $this->assertNotNull($txn);
        $this->assertSame('4000.00', (string) $txn->amount);
        $this->assertEquals($budget->budget_id, $txn->budget_id);
    }

    public function test_commit_po_falls_back_to_total_amount_when_no_lines(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, 3200, 7);

        $this->service->commitPo($po->fresh()->load('details'));

        $budget->refresh();
        $this->assertSame('3200.00', (string) $budget->committed);
    }

    public function test_commit_po_is_idempotent_per_po(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, 4000, 7);
        $this->addPoLine($po, 4000);

        $this->service->commitPo($po->fresh()->load('details'));
        $this->service->commitPo($po->fresh()->load('details')); // retry no-op

        $budget->refresh();
        $this->assertSame('4000.00', (string) $budget->committed);
        $this->assertSame(1, BudgetTransaction::where('po_id', $po->po_id)->where('txn_type', 'commit')->count());
    }

    public function test_commit_po_throws_when_insufficient(): void
    {
        $budget = $this->makeBudget(10000, ['committed' => 7000]);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, 4000, 7);
        $this->addPoLine($po, 4000);

        $this->expectException(InsufficientBudgetException::class);
        $this->service->commitPo($po->fresh()->load('details'));
    }

    public function test_commit_po_throws_when_no_verified_budget(): void
    {
        $budget = $this->makeBudget(10000, ['verification_status' => 'pending']);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, 4000, 7);
        $this->addPoLine($po, 4000);

        $this->expectException(UnverifiedBudgetException::class);
        $this->service->commitPo($po->fresh()->load('details'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=PoCommitTest`
Expected: FAIL — `Call to undefined method App\Services\BudgetService::commitPo()`.

- [ ] **Step 3: Implement** — in `back-end/app/Services/BudgetService.php`, add these methods (place `commitPo`/`commitPoGroup` just after `resolveActiveBudget()`, and the two private read helpers near the existing `outstanding()`):

```php
/**
 * Reserve budget for an approved PO, per destination warehouse. Row-locked,
 * idempotent per (budget_id, po_id); requires a verified active budget with
 * enough available. Throws Unverified/Insufficient. PR-agnostic (direct POs too).
 */
public function commitPo(PurchaseOrder $po, ?int $userId = null): void
{
    $po->loadMissing('details');
    $groups = $po->details->isNotEmpty()
        ? $po->details->groupBy(fn ($d) => (int) ($d->location_id ?? $po->location_id))
        : collect([(int) $po->location_id => collect()]);

    foreach ($groups as $locationId => $lines) {
        $amount = $lines->isNotEmpty()
            ? round((float) $lines->sum('subtotal'), 2)
            : round((float) $po->total_amount, 2);
        $this->commitPoGroup($po, (int) $locationId, $amount, $userId);
    }
}

/** One warehouse group of a PO reservation. */
private function commitPoGroup(PurchaseOrder $po, int $locationId, float $amount, ?int $userId): void
{
    $budget = $this->resolveActiveBudget($locationId);
    if (!$budget) {
        $whName = \App\Models\Location::where('location_id', $locationId)->value('location_name');
        throw new UnverifiedBudgetException($whName ?? "warehouse #{$locationId}", 'pending');
    }

    DB::transaction(function () use ($po, $budget, $amount, $userId) {
        $locked = BudgetAllocation::whereKey($budget->budget_id)->lockForUpdate()->firstOrFail();

        if (BudgetTransaction::where('po_id', $po->po_id)
            ->where('budget_id', $locked->budget_id)
            ->where('txn_type', BudgetTransaction::TYPE_COMMIT)->exists()) {
            return; // already reserved this group (re-approval / retry)
        }
        if ($locked->verification_status !== 'verified') {
            throw new UnverifiedBudgetException($locked->period_label, $locked->verification_status);
        }

        $utilBefore = $this->utilization($locked);
        $available = (float) $locked->total_budget - (float) $locked->committed - (float) $locked->spent;
        if ($amount > $available) {
            throw new InsufficientBudgetException($locked->period_label, $amount, $available);
        }

        $this->txn($locked, BudgetTransaction::TYPE_COMMIT, $amount, $po->pr_id, $po->po_id, $userId,
            'PO ' . $po->po_number . ' approved');
        $locked->committed = round((float) $locked->committed + $amount, 2);
        $locked->save();

        $this->maybeAlert($locked, $utilBefore);
    });
}

/** Net reservation still held by a PO on a budget: Σcommit − Σrelease (po_id-keyed). */
private function outstandingForPo(int $budgetId, int $poId): float
{
    $commits = (float) BudgetTransaction::where('budget_id', $budgetId)
        ->where('po_id', $poId)->where('txn_type', BudgetTransaction::TYPE_COMMIT)->sum('amount');
    $releases = (float) BudgetTransaction::where('budget_id', $budgetId)
        ->where('po_id', $poId)->where('txn_type', BudgetTransaction::TYPE_RELEASE)->sum('amount');

    return round($commits - $releases, 2);
}

/** The budget a PO reserved against for a warehouse: the one with a COMMIT txn for this po_id at that location. */
private function resolveBudgetForPo(int $poId, int $locationId): ?int
{
    $committedBudgetIds = BudgetTransaction::where('po_id', $poId)
        ->where('txn_type', BudgetTransaction::TYPE_COMMIT)
        ->distinct()->pluck('budget_id');
    if ($committedBudgetIds->isEmpty()) {
        return null;
    }
    $match = BudgetAllocation::whereIn('budget_id', $committedBudgetIds)
        ->where('location_id', $locationId)->value('budget_id');

    return $match ? (int) $match : null;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=PoCommitTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/BudgetService.php back-end/tests/Feature/Budget/PoCommitTest.php
git commit -m "feat(budget): PO-anchored reservation primitives (commitPo)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: BudgetService — po-anchored `spend` + `releaseCommitment`

**Files:**
- Modify: `back-end/app/Services/BudgetService.php`
- Test: `back-end/tests/Feature/Budget/PoSettleTest.php` (create)

- [ ] **Step 1: Write the failing test** — create `back-end/tests/Feature/Budget/PoSettleTest.php`:

```php
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
                'product_type' => 'consumable',
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
        $this->service->spend($po->fresh()->load('details')); // no-op

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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=PoSettleTest`
Expected: FAIL — `releaseCommitment()` undefined, and spend still pr-keyed (committed won't reach 0 because reservation is po-keyed but spend looks at pr outstanding).

- [ ] **Step 3: Implement** — in `back-end/app/Services/BudgetService.php`:

Replace the existing `spend()`, `spendWhole()`, and `spendGroup()` method bodies with the po-anchored versions below, and add `releaseCommitment()`:

```php
/**
 * Record actual spend for a received PO and release the PO's own reservation.
 * Idempotent per (po_id[, budget_id]). PR-agnostic — direct POs settle too.
 */
public function spend(PurchaseOrder $po, ?int $userId = null): void
{
    $po->loadMissing('details');
    $groups = $po->details->isNotEmpty()
        ? $po->details->groupBy(fn ($d) => (int) ($d->location_id ?? $po->location_id))
        : collect([(int) $po->location_id => collect()]);

    if ($groups->count() <= 1) {
        $this->spendWhole($po, $userId);
        return;
    }

    foreach ($groups as $locationId => $lines) {
        if (!$this->groupFullyReceived($lines)) {
            continue;
        }
        $this->spendGroup($po, (int) $locationId, $lines, $userId);
    }
}

/** Single-warehouse / legacy settlement against the PO's reservation. */
private function spendWhole(PurchaseOrder $po, ?int $userId): void
{
    $budgetId = $this->resolveBudgetForPo($po->po_id, (int) $po->location_id)
        ?? optional($this->resolveActiveBudget((int) $po->location_id))->budget_id;
    if (!$budgetId) {
        return;
    }

    DB::transaction(function () use ($po, $budgetId, $userId) {
        $budget = BudgetAllocation::whereKey($budgetId)->lockForUpdate()->first();
        if (!$budget) {
            return;
        }
        if (BudgetTransaction::where('po_id', $po->po_id)
            ->where('txn_type', BudgetTransaction::TYPE_SPEND)->exists()) {
            return; // already recorded
        }

        $utilBefore = $this->utilization($budget);

        $amount = (float) $po->total_amount;
        $this->txn($budget, BudgetTransaction::TYPE_SPEND, $amount, $po->pr_id, $po->po_id, $userId,
            'PO ' . $po->po_number . ' completed');
        $budget->spent = round((float) $budget->spent + $amount, 2);

        $this->releaseReservationInLock($budget, $po, $userId, 'Reservation settled by PO ' . $po->po_number);

        $this->maybeAlert($budget, $utilBefore);
    });
}

/** One warehouse group of a multi-warehouse PO, keyed idempotently per (po, budget). */
private function spendGroup(PurchaseOrder $po, int $locationId, $lines, ?int $userId): void
{
    $budgetId = $this->resolveBudgetForPo($po->po_id, $locationId)
        ?? optional($this->resolveActiveBudget($locationId))->budget_id;
    if (!$budgetId) {
        return;
    }
    if (BudgetTransaction::where('po_id', $po->po_id)
        ->where('budget_id', $budgetId)
        ->where('txn_type', BudgetTransaction::TYPE_SPEND)->exists()) {
        return;
    }
    $groupAmount = round((float) collect($lines)->sum('subtotal'), 2);

    DB::transaction(function () use ($po, $budgetId, $groupAmount, $userId) {
        $budget = BudgetAllocation::whereKey($budgetId)->lockForUpdate()->first();
        if (!$budget) {
            return;
        }
        if (BudgetTransaction::where('po_id', $po->po_id)
            ->where('budget_id', $budget->budget_id)
            ->where('txn_type', BudgetTransaction::TYPE_SPEND)->exists()) {
            return;
        }

        $utilBefore = $this->utilization($budget);

        $this->txn($budget, BudgetTransaction::TYPE_SPEND, $groupAmount, $po->pr_id, $po->po_id, $userId,
            'PO ' . $po->po_number . ' warehouse received');
        $budget->spent = round((float) $budget->spent + $groupAmount, 2);

        $this->releaseReservationInLock($budget, $po, $userId, 'Reservation settled by PO ' . $po->po_number . ' (warehouse)');

        $this->maybeAlert($budget, $utilBefore);
    });
}

/** Release this PO's remaining reservation on an already-locked budget row, then persist. */
private function releaseReservationInLock(BudgetAllocation $budget, PurchaseOrder $po, ?int $userId, string $note): void
{
    $outstanding = $this->outstandingForPo($budget->budget_id, $po->po_id);
    if ($outstanding < 0) {
        Log::warning("Budget ledger anomaly: negative outstanding {$outstanding} for PO {$po->po_id} on budget {$budget->budget_id}");
    }
    if ($outstanding > 0) {
        $this->txn($budget, BudgetTransaction::TYPE_RELEASE, $outstanding, $po->pr_id, $po->po_id, $userId, $note);
        $newCommitted = round((float) $budget->committed - $outstanding, 2);
        if ($newCommitted < 0) {
            Log::warning("Budget ledger anomaly: committed clamped to 0 on budget {$budget->budget_id} (run budget:rebuild-totals)");
            $newCommitted = 0.0;
        }
        $budget->committed = $newCommitted;
    }
    $budget->save();
}

/** Release a PO's outstanding reservation back to available (on reject/cancel). Returns released total. */
public function releaseCommitment(PurchaseOrder $po, string $note, ?int $userId = null): float
{
    $budgetIds = BudgetTransaction::where('po_id', $po->po_id)
        ->where('txn_type', BudgetTransaction::TYPE_COMMIT)
        ->distinct()->pluck('budget_id');

    $total = 0.0;
    foreach ($budgetIds as $budgetId) {
        $total += DB::transaction(function () use ($budgetId, $po, $note, $userId) {
            $budget = BudgetAllocation::whereKey($budgetId)->lockForUpdate()->first();
            if (!$budget) {
                return 0.0;
            }
            $outstanding = $this->outstandingForPo($budget->budget_id, $po->po_id);
            if ($outstanding <= 0) {
                return 0.0;
            }
            $this->txn($budget, BudgetTransaction::TYPE_RELEASE, $outstanding, $po->pr_id, $po->po_id, $userId, $note);
            $newCommitted = round((float) $budget->committed - $outstanding, 2);
            if ($newCommitted < 0) {
                $newCommitted = 0.0;
            }
            $budget->committed = $newCommitted;
            $budget->save();
            return $outstanding;
        });
    }
    return round($total, 2);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=PoSettleTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/BudgetService.php back-end/tests/Feature/Budget/PoSettleTest.php
git commit -m "feat(budget): po-anchored spend + releaseCommitment

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: PR approval validates but does not reserve

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseRequestController.php`
- Test: `back-end/tests/Feature/Budget/PrApproveNoReserveTest.php` (create)

- [ ] **Step 1: Write the failing test** — create `back-end/tests/Feature/Budget/PrApproveNoReserveTest.php`:

```php
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
                    'product_type' => 'consumable', 'created_at' => now(), 'updated_at' => now(),
                ], 'product_id')),
            'quantity'    => 1,
            'estimated_unit_price' => $subtotal,
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=PrApproveNoReserveTest`
Expected: FAIL — `test_pr_approval_writes_no_commit_rows` fails because `approve()` still commits (1 commit row).

- [ ] **Step 3: Implement** — in `back-end/app/Http/Controllers/Api/PurchaseRequestController.php`, replace the reservation logic inside `approve()`'s `DB::transaction` (the block that sets status 14 then calls `commitGroup`/`commit`) with **validate-only**:

```php
            DB::transaction(function () use ($pr, $request, $hasLineWarehouses) {
                // Validate availability WITHOUT reserving — reservation now happens
                // at PO approval. A PR is only a request.
                if ($hasLineWarehouses) {
                    $groups = $pr->details->groupBy('location_id');
                    foreach ($groups as $locId => $lines) {
                        $subtotal = (float) $lines->sum('subtotal');
                        $budget = $this->budgetService->resolveActiveBudget((int) $locId);
                        if (!$budget) {
                            $whName = \App\Models\Location::where('location_id', $locId)->value('location_name');
                            throw new UnverifiedBudgetException($whName ?? "warehouse #{$locId}", 'pending');
                        }
                        if (!$this->budgetService->hasAvailable($budget, $subtotal)) {
                            $available = (float) $budget->total_budget - (float) $budget->committed - (float) $budget->spent;
                            throw new InsufficientBudgetException($budget->period_label, $subtotal, $available);
                        }
                    }
                } else {
                    $budget = \App\Models\BudgetAllocation::find($pr->budget_id);
                    if (!$budget || $budget->verification_status !== 'verified') {
                        throw new UnverifiedBudgetException($budget?->period_label ?? 'budget', $budget?->verification_status ?? 'pending');
                    }
                    if (!$this->budgetService->hasAvailable($budget, (float) $pr->estimated_total)) {
                        $available = (float) $budget->total_budget - (float) $budget->committed - (float) $budget->spent;
                        throw new InsufficientBudgetException($budget->period_label, (float) $pr->estimated_total, $available);
                    }
                }

                $pr->update([
                    'status_id'    => 14,
                    'approved_by'  => $request->user()->user_id,
                    'approval_date'=> today(),
                ]);
            });
```

Keep the existing `catch (UnverifiedBudgetException ...)` / `catch (InsufficientBudgetException ...)` → 422 handlers and the "no budget attached" guard above the transaction. Confirm `use App\Models\BudgetAllocation;` (or use the FQCN as shown). Confirm `InsufficientBudgetException` is imported at the top of the controller (it is used elsewhere; if not, add `use App\Exceptions\InsufficientBudgetException;`).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=PrApproveNoReserveTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/PurchaseRequestController.php back-end/tests/Feature/Budget/PrApproveNoReserveTest.php
git commit -m "feat(budget): PR approval validates availability but no longer reserves

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: PO approval reserves; rejection releases

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseOrderController.php`
- Test: `back-end/tests/Feature/Budget/PoApproveReserveTest.php` (create)

- [ ] **Step 1: Write the failing test** — create `back-end/tests/Feature/Budget/PoApproveReserveTest.php`:

```php
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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=PoApproveReserveTest`
Expected: FAIL — approval does not reserve (committed stays 0).

- [ ] **Step 3: Implement** — in `back-end/app/Http/Controllers/Api/PurchaseOrderController.php` `update()`, inside the `if (!empty($header))` block. Replace the current spend-only logic:

```php
                $wasCompleted = $po->status_id !== 11
                    && isset($validated['status_id'])
                    && (int) $validated['status_id'] === 11;

                $po->update($header);

                if ($wasCompleted && $po->pr_id) {
                    app(BudgetService::class)->spend(
                        $po->fresh()->load('purchaseRequest'),
                        $request->user()?->user_id
                    );
                }
```

with reservation/settlement/release logic keyed on the transition:

```php
                $prevStatus  = (int) $po->status_id;
                $nextStatus  = isset($validated['status_id']) ? (int) $validated['status_id'] : $prevStatus;
                $wasReserved = in_array($prevStatus, [8, 10, 11, 12], true);
                $entersReserve   = !$wasReserved && in_array($nextStatus, [8, 10, 11, 12], true);
                $entersComplete  = $prevStatus !== 11 && $nextStatus === 11;
                $entersRejected  = $prevStatus !== 9 && $nextStatus === 9;

                $po->update($header);

                $budgetService = app(BudgetService::class);

                // Reserve on the transition into a committed state (Approved/Authorized),
                // or defensively just before spend if the PO jumped straight to Completed
                // without ever reserving. commitPo is idempotent per (budget, po).
                if ($entersReserve || ($entersComplete && !$wasReserved)) {
                    $budgetService->commitPo($po->fresh()->load('details'), $request->user()?->user_id);
                }

                if ($entersComplete) {
                    $budgetService->spend($po->fresh()->load('details'), $request->user()?->user_id);
                }

                if ($entersRejected && $wasReserved) {
                    $budgetService->releaseCommitment($po->fresh(), 'PO ' . $po->po_number . ' rejected', $request->user()?->user_id);
                }
```

Notes for the implementer:
- `commitPo`/`spend`/`releaseCommitment` throw `UnverifiedBudgetException`/`InsufficientBudgetException` on failure. The surrounding `try { ... } catch (\Exception $e)` in `update()` already rolls back (`DB::rollBack()`) and returns an error. To return **422** (not 500) for these, add `catch (UnverifiedBudgetException $e) { DB::rollBack(); return $this->error($e->getMessage(), 422); }` and the same for `InsufficientBudgetException`, **before** the generic `catch (\Exception $e)`. Add `use App\Exceptions\InsufficientBudgetException;` and `use App\Exceptions\UnverifiedBudgetException;` at the top if not present.
- Keep the existing verification gate (`in_array($nextStatus,[8,10,11,12]) && verification_status!=='verified' → 422`) exactly as-is; it runs before the transaction.
- Also update `reject()` (~line 342): after it sets status to 9, if the PO was previously reserved (`in_array($originalStatus,[8,10,11,12])`) call `app(BudgetService::class)->releaseCommitment($po->fresh(), 'PO ' . $po->po_number . ' rejected', $request->user()?->user_id);`. Capture the original status before updating.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=PoApproveReserveTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/PurchaseOrderController.php back-end/tests/Feature/Budget/PoApproveReserveTest.php
git commit -m "feat(budget): reserve at PO approval, release on rejection

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: Remove dead PR-anchored ledger methods + PR-archive release

**Files:**
- Modify: `back-end/app/Services/BudgetService.php`
- Modify: `back-end/app/Http/Controllers/Api/PurchaseRequestController.php`

- [ ] **Step 1: Confirm there are no remaining callers**

Run:
```bash
cd back-end && grep -rnE '->commit\(|->commitGroup\(|->releaseOutstanding\(' app
```
Expected: no matches in `app/` except the definitions in `BudgetService.php`. (Task 3 removed the `approve()` callers.) If `releaseOutstanding` is still called in `PurchaseRequestController` (PR archive, ~line 621), proceed to Step 2 to remove it.

- [ ] **Step 2: Remove the PR-archive release call**

In `PurchaseRequestController.php`, delete the line calling `$this->budgetService->releaseOutstanding($pr, 'PR archived', ...)` (PRs no longer hold reservations). Leave the rest of the archive/destroy logic intact.

- [ ] **Step 3: Delete the dead methods**

In `back-end/app/Services/BudgetService.php`, delete these now-unused methods entirely:
- `public function commit(PurchaseRequest $pr, ...)`
- `public function commitGroup(PurchaseRequest $pr, ...)`
- `public function releaseOutstanding(PurchaseRequest $pr, ...)`
- `private function outstanding(int $budgetId, int $prId)` (replaced by `outstandingForPo`)
- `private function resolveBudgetForLocation(PurchaseRequest $pr, int $locationId)`
- `private function poCoversLocationGroup(PurchaseRequest $pr, PurchaseOrder $po, int $locationId)`

Update the class-doc comment block at the top of `BudgetService` so the ledger legend reads: `commit → committed += amount (PO approved)` and `release → committed -= amount (PO settled/rejected)`.

- [ ] **Step 4: Verify nothing references the removed symbols**

Run:
```bash
cd back-end && grep -rnE 'outstanding\(|resolveBudgetForLocation|poCoversLocationGroup|->commitGroup|->releaseOutstanding|->commit\(' app
```
Expected: no matches in `app/` (all gone). Then run PHP lint:
```bash
cd back-end && php -l app/Services/BudgetService.php && php -l app/Http/Controllers/Api/PurchaseRequestController.php
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/BudgetService.php back-end/app/Http/Controllers/Api/PurchaseRequestController.php
git commit -m "refactor(budget): remove dead PR-anchored ledger methods

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 6: One-time migration command `budget:reanchor-commitments`

**Files:**
- Create: `back-end/app/Console/Commands/ReanchorBudgetCommitments.php`
- Test: `back-end/tests/Feature/Budget/ReanchorCommitmentsTest.php` (create)

- [ ] **Step 1: Write the failing test** — create `back-end/tests/Feature/Budget/ReanchorCommitmentsTest.php`:

```php
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
                'product_type' => 'consumable', 'created_at' => now(), 'updated_at' => now(),
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=ReanchorCommitmentsTest`
Expected: FAIL — `Command "budget:reanchor-commitments" is not defined.`

- [ ] **Step 3: Implement** — create `back-end/app/Console/Commands/ReanchorBudgetCommitments.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\BudgetTransaction;
use App\Models\PurchaseOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time cutover: move budget reservations from PR-anchored holds to
 * PO-anchored reservations. Writes ledger rows only; run budget:rebuild-totals
 * afterwards to recompute cached committed/spent columns.
 *
 * Scope: releases every PR-anchored COMMIT hold (po_id IS NULL) that still has
 * outstanding balance, and reserves every PO currently Approved(8)/Authorized(10)
 * that has no PO-anchored commit yet. Completed(11)/Partially-Received(12) POs keep
 * their already-recorded spend and are not re-reserved.
 */
class ReanchorBudgetCommitments extends Command
{
    protected $signature = 'budget:reanchor-commitments';
    protected $description = 'Move budget reservations from PR-anchored holds to PO-anchored reservations';

    public function handle(): int
    {
        $this->releasePrHolds();
        $this->reserveApprovedPos();
        $this->info('Reanchor complete. Now run: php artisan budget:rebuild-totals');
        return self::SUCCESS;
    }

    /** Release all outstanding PR-anchored commit holds (po_id IS NULL). */
    private function releasePrHolds(): void
    {
        $groups = BudgetTransaction::whereNull('po_id')
            ->where('txn_type', BudgetTransaction::TYPE_COMMIT)
            ->select('budget_id', 'pr_id')->distinct()->get();

        foreach ($groups as $g) {
            $commits = (float) BudgetTransaction::where('budget_id', $g->budget_id)
                ->where('pr_id', $g->pr_id)->whereNull('po_id')
                ->where('txn_type', BudgetTransaction::TYPE_COMMIT)->sum('amount');
            $releases = (float) BudgetTransaction::where('budget_id', $g->budget_id)
                ->where('pr_id', $g->pr_id)->whereNull('po_id')
                ->where('txn_type', BudgetTransaction::TYPE_RELEASE)->sum('amount');
            $outstanding = round($commits - $releases, 2);
            if ($outstanding <= 0) {
                continue;
            }
            BudgetTransaction::create([
                'budget_id' => $g->budget_id,
                'txn_type'  => BudgetTransaction::TYPE_RELEASE,
                'amount'    => $outstanding,
                'pr_id'     => $g->pr_id,
                'po_id'     => null,
                'notes'     => 'Reanchor: released PR-anchored hold',
            ]);
            $this->line("Released PR hold pr={$g->pr_id} budget={$g->budget_id} amount={$outstanding}");
        }
    }

    /** Reserve each Approved/Authorized PO that has no PO-anchored commit yet. */
    private function reserveApprovedPos(): void
    {
        $pos = PurchaseOrder::with('details')->whereIn('status_id', [8, 10])->get();
        foreach ($pos as $po) {
            $already = BudgetTransaction::where('po_id', $po->po_id)
                ->where('txn_type', BudgetTransaction::TYPE_COMMIT)->exists();
            if ($already) {
                continue;
            }
            $groups = $po->details->isNotEmpty()
                ? $po->details->groupBy(fn ($d) => (int) ($d->location_id ?? $po->location_id))
                : collect([(int) $po->location_id => collect()]);

            foreach ($groups as $locationId => $lines) {
                $amount = $lines->isNotEmpty()
                    ? round((float) $lines->sum('subtotal'), 2)
                    : round((float) $po->total_amount, 2);
                $budgetId = DB::table('budget_allocations')
                    ->where('location_id', $locationId)
                    ->where('verification_status', 'verified')
                    ->whereDate('period_start', '<=', today())
                    ->whereDate('period_end', '>=', today())
                    ->orderByDesc('period_start')
                    ->value('budget_id');
                if (!$budgetId) {
                    $this->warn("No verified active budget for location {$locationId} (PO {$po->po_id}) — skipped");
                    continue;
                }
                BudgetTransaction::create([
                    'budget_id' => $budgetId,
                    'txn_type'  => BudgetTransaction::TYPE_COMMIT,
                    'amount'    => $amount,
                    'pr_id'     => $po->pr_id,
                    'po_id'     => $po->po_id,
                    'notes'     => 'Reanchor: PO ' . $po->po_number . ' reserved',
                ]);
                $this->line("Reserved PO po={$po->po_id} budget={$budgetId} amount={$amount}");
            }
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=ReanchorCommitmentsTest`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Console/Commands/ReanchorBudgetCommitments.php back-end/tests/Feature/Budget/ReanchorCommitmentsTest.php
git commit -m "feat(budget): budget:reanchor-commitments cutover command

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 7: Reconcile the existing Budget test suite

Several existing tests encode the OLD "PR approval reserves" behavior and will now fail. Update them to the new model, then confirm the whole suite is green.

**Files:**
- Modify: existing tests under `back-end/tests/Feature/Budget/` (enumerated below).

- [ ] **Step 1: Run the whole Budget suite to enumerate real failures**

Run: `cd back-end && php artisan test tests/Feature/Budget`
Expected: several failures. Record the failing test methods. Likely culprits:
- `BudgetServiceTest.php` — `test_commit_*` and `test_release_outstanding_*` call the removed `commit()`/`releaseOutstanding()`.
- `BudgetEnforcementTest.php`, `BudgetGroupTest.php`, `BudgetLifecycleTest.php`, `WarehousePrApproveTest.php`, `PoPerWarehouseSpendTest.php`, `WarehouseFlowE2ETest.php`, `PrConvertSplitTest.php` — assert commit rows / committed increases at PR approval, or spend settling against PR outstanding.

- [ ] **Step 2: For each failing test, apply the new-model rule**

Apply these transformations (only to tests that fail):
- **Reservation now happens at PO approval, not PR approval.** Where a test approved a PR and asserted `committed` increased or a `commit` txn exists keyed by `pr_id`, change it so: (a) PR approval asserts **no** commit rows and status 14; (b) create/approve a PO (set `status_id` 8 via the service `commitPo()` or the PUT endpoint) and assert the `committed` increase + a `commit` txn keyed by `po_id`.
- **Removed methods:** replace `$service->commit($pr)` with building a PO and `$service->commitPo($po->load('details'))`; replace `$service->releaseOutstanding($pr, ...)` with `$service->releaseCommitment($po, ...)`.
- **Spend assertions:** spend is unchanged in outcome (spent rises, committed falls to 0 for a fully-received PO) but is now settled without a PR. Tests that built a PR-only commitment then called `spend` must first `commitPo` the PO.
- Delete tests that assert PR-archive releases a commitment (`releaseOutstanding` on archive) — that behavior is intentionally gone. If a whole test method only exists to check PR-archive release, remove that method.

For each edited test, keep the file's existing helper style (`makeBudget`/`makePr`/`makePo`, raw `DB::table` inserts for detail lines as in Tasks 1–6).

- [ ] **Step 3: Re-run the whole Budget suite**

Run: `cd back-end && php artisan test tests/Feature/Budget`
Expected: all green. (Pre-existing unrelated failures noted in project memory — 2 `NavBundleTest` bundle-count failures — live outside `tests/Feature/Budget` and are out of scope.)

- [ ] **Step 4: Run the full backend suite for regressions**

Run: `cd back-end && php artisan test`
Expected: green except the 2 known pre-existing `NavBundleTest` failures and any pre-existing frontend/unrelated skips documented in memory. If a NON-budget test newly fails, investigate — it may be a real regression from the spend/commit change (e.g. a receiving flow test).

- [ ] **Step 5: Commit**

```bash
git add back-end/tests
git commit -m "test(budget): reconcile suite to PO-anchored reservations

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Manual verification (record results before claiming done)

Run against the dev DB (`airkingV5`) after applying the code change:

```bash
cd back-end && php artisan budget:reanchor-commitments && php artisan budget:rebuild-totals
```

Then walk the flow in the app:
1. Create/approve a PR within budget → confirm **no** budget deduction (Budget Management `committed` unchanged; ledger shows no new commit row).
2. Convert the PR to a PO, have the auditor verify it, then **admin approves the PO** (status → Approved) → confirm `committed` increases by the PO amount and a ledger `commit` row keyed to the PO appears.
3. Reject/cancel the approved PO → confirm `committed` returns to available.
4. Receive the PO fully → confirm `spent` rises and `committed` returns to 0 for that PO.

## Notes

- No frontend changes are required for correctness; Budget Management utilization bars + ledger drill-down read the same cached columns and ledger rows.
- `pr_id` stays on ledger rows for traceability; only the idempotency/`outstanding` keying moved to `po_id`.
