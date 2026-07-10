# Warehouse-Only Procurement (Core) Implementation Plan — Plan A

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make purchase requests supplier-scoped with per-line destination warehouses, commit each warehouse's share to its own verified budget, auto-split conversion into one PO per warehouse, and enforce warehouse-only receiving everywhere.

**Architecture:** Line-level warehouse (`supplier_id` on `purchase_requests`, `location_id` on `purchase_request_details`). `BudgetService` gains a per-budget commit path and multi-budget release/spend keyed off the ledger, while the old single-budget path stays intact for legacy PRs. Approval commits each warehouse group all-or-nothing; conversion produces one PO per warehouse.

**Tech Stack:** Laravel 10 API (`back-end/`), React CRA (`frontend/`), MariaDB via XAMPP. Tests: PHPUnit feature tests with `DatabaseTransactions` against `airkingV5_test`.

**Spec:** `docs/superpowers/specs/2026-07-10-warehouse-procurement-design.md` (the per-warehouse PO Recommendation engine — spec's "PO Recommendation" section — is deferred to a separate Plan B; this plan covers everything else.)

**Repo conventions that matter here:**
- Schema is SQL-dump based — NEVER run blanket `php artisan migrate`. Apply single migrations with `--path=` to BOTH `airkingV5` (dev) and `airkingV5_test`. APP_ENV is production → use `--force`.
- Backend commands run from `back-end/`, frontend from `frontend/`. PowerShell 5.1 — chain with `;`, not `&&`.
- `ApiResponse` trait: `$this->success/error/validationError/notFound/paginated`.
- Money messages: `'₱' . number_format($x, 2)`.

**Key facts (verified against the code):**
- PR statuses: 13 Pending, 14 Approved, 15 Rejected, 16 Partially Converted. PO statuses: 7 Pending, 8 Approved, 10 Authorized, 11 Completed, 12 Partially Received.
- `PurchaseRequestController` has `__construct(private BudgetService $budgetService)`, and imports `App\Exceptions\{InsufficientBudgetException,UnverifiedBudgetException}`, `App\Services\BudgetService`, `App\Support\RealtimeBroadcaster`, models, `DB`, `Str`, `ValidationException`.
- `store()` currently: validates `location_id`/`budget_id`/details, does budget location/period/verified/available checks, creates PR (header `location_id`,`budget_id`) + details, broadcasts on `pr->location_id`.
- `approve()` currently: guards pending + `budget_id` present, then in a transaction sets status 14 + calls `$this->budgetService->commit($pr, userId)`, catches Unverified/Insufficient → 422.
- `convertStore()` currently: validates a supplier + one flat details array, creates ONE PO (`location_id = pr->location_id`), sets `converted_to_po_id`, marks PR 16-or-14.
- `BudgetService`: `commit(PurchaseRequest $pr, ?int $userId=null)` (uses `$pr->budget_id`+`$pr->estimated_total`, idempotency: any commit txn for pr_id), `releaseOutstanding(PurchaseRequest $pr, string $note, ?int $userId=null): float` (uses `$pr->budget_id`), `spend(PurchaseOrder $po, ?int $userId=null)` (uses `$po->purchaseRequest->budget_id`, surplus when `pr->status_id===14 && pr->converted_to_po_id===po->po_id`), private `outstanding(int $budgetId, int $prId): float`, private `txn(...)`, `resolveActiveBudget(int $locationId): ?BudgetAllocation` (verified-only), `hasAvailable(BudgetAllocation,$amount): bool`.
- Budget ledger `budget_transactions` has `budget_id`, `txn_type`, `amount`, `pr_id`, `po_id`, `performed_by`, `notes`. `outstanding()` sums commit − release per (budget_id, pr_id).
- Supplier→product map: table `supplier_product`, model `SupplierProduct` (`supplier_id`,`product_id`,`product_price`). `GET /purchase-orders/products-by-supplier/{supplier_id}` returns that supplier's products.
- Budget layer is warehouse-only already (Task from prior plan): `BudgetAllocationController::store/update` reject non-warehouse locations; `Location` model has `location_type`.
- `BudgetTestCase` (`back-end/tests/Feature/Budget/BudgetTestCase.php`): `makeUser`, `makeBudget($total,$overrides)` (defaults `verification_status=>'verified'`, `location_id=>$this->locationId` which is a warehouse), `makePr($budget,$estTotal,$statusId,$overrides)` (writes header `location_id`,`budget_id`), `makePo($pr,$total,$statusId)`.

---

## Task 1: Schema + models (supplier on PR, warehouse on PR line)

**Files:**
- Create: `back-end/database/migrations/2026_07_10_000000_add_supplier_and_line_location_to_prs.php`
- Modify: `back-end/app/Models/PurchaseRequest.php`
- Modify: `back-end/app/Models/PurchaseRequestDetail.php`

- [ ] **Step 1: Write the migration**

`back-end/database/migrations/2026_07_10_000000_add_supplier_and_line_location_to_prs.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable()->after('location_id');
            $table->foreign('supplier_id')->references('supplier_id')->on('suppliers')->nullOnDelete();
        });

        Schema::table('purchase_request_details', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->after('product_id');
            $table->foreign('location_id')->references('location_id')->on('locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropColumn('supplier_id');
        });
        Schema::table('purchase_request_details', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
    }
};
```

- [ ] **Step 2: Apply to dev + test DBs**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\back-end
php artisan config:clear
php artisan migrate --path=database/migrations/2026_07_10_000000_add_supplier_and_line_location_to_prs.php --force
$env:DB_DATABASE = 'airkingV5_test'
php artisan migrate --path=database/migrations/2026_07_10_000000_add_supplier_and_line_location_to_prs.php --force
Remove-Item Env:DB_DATABASE
php artisan config:clear
```

Expected: `DONE` each. Verify: `C:\XAMPP1\mysql\bin\mysql.exe -u root airkingV5_test -e "DESCRIBE purchase_requests; DESCRIBE purchase_request_details;"` shows the new columns.

- [ ] **Step 3: Update PurchaseRequest model** (`back-end/app/Models/PurchaseRequest.php`)

Add `'supplier_id'` to `$fillable` (after `'location_id',`). Add a relation after the existing `location()` relation:

```php
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }
```

(The file already imports `BelongsTo`. If `Supplier` isn't imported, it's in the same `App\Models` namespace so no import is needed.)

- [ ] **Step 4: Update PurchaseRequestDetail model** (`back-end/app/Models/PurchaseRequestDetail.php`)

Add `'location_id'` to `$fillable` (after `'product_id',`). Add a relation:

```php
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }
```

- [ ] **Step 5: Smoke-check**

```powershell
php artisan tinker --execute="echo App\Models\PurchaseRequest::whereNotNull('supplier_id')->count(); echo '|'; echo App\Models\PurchaseRequestDetail::whereNotNull('location_id')->count();"
```

Expected: `0|0`, no error.

- [ ] **Step 6: Commit**

```powershell
git add database/migrations/2026_07_10_000000_add_supplier_and_line_location_to_prs.php app/Models/PurchaseRequest.php app/Models/PurchaseRequestDetail.php
git commit -m "feat(procurement): supplier on PR + destination warehouse on PR line

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 2: BudgetService — per-budget commit + multi-budget release/spend

This is the highest-risk task: it must add per-warehouse budget support WITHOUT breaking the 87 existing budget tests (which use the single-budget path). Strategy: add `commitGroup()`, keep `commit()` delegating to it; make `releaseOutstanding()` and `spend()` resolve budgets from the ledger so both legacy (one budget) and new (many budgets) PRs work.

**Files:**
- Modify: `back-end/app/Services/BudgetService.php`
- Test: `back-end/tests/Feature/Budget/BudgetGroupTest.php` (create)

- [ ] **Step 1: Write failing tests**

`back-end/tests/Feature/Budget/BudgetGroupTest.php`:

```php
<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetAllocation;
use App\Models\BudgetTransaction;
use App\Models\PurchaseRequest;
use App\Services\BudgetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BudgetGroupTest extends BudgetTestCase
{
    private BudgetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BudgetService::class);
    }

    /** A second warehouse (with its own verified budget) for multi-warehouse tests. */
    private function secondWarehouse(): int
    {
        return (int) DB::table('locations')->insertGetId([
            'location_code' => 'WH2-' . uniqid(),
            'location_name' => 'Warehouse Two',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');
    }

    /** A PR with NO header budget_id (new multi-warehouse shape). */
    private function newShapePr(float $estimatedTotal): PurchaseRequest
    {
        return PurchaseRequest::create([
            'location_id'     => null,
            'supplier_id'     => null,
            'budget_id'       => null,
            'pr_number'       => 'PR-G-' . strtoupper(Str::random(6)),
            'request_date'    => today(),
            'needed_by_date'  => today()->addDays(7),
            'estimated_total' => $estimatedTotal,
            'status_id'       => 13,
            'requested_by'    => $this->makeUser('inventory_analyst')->user_id,
        ]);
    }

    public function test_commit_group_writes_ledger_row_per_budget(): void
    {
        $whA = $this->makeBudget(10000); // $this->locationId
        $whBId = $this->secondWarehouse();
        $whB = $this->makeBudget(8000, ['location_id' => $whBId]);
        $pr = $this->newShapePr(6500);

        $this->service->commitGroup($pr, $whA, 4000, null);
        $this->service->commitGroup($pr, $whB, 2500, null);

        $whA->refresh(); $whB->refresh();
        $this->assertSame('4000.00', (string) $whA->committed);
        $this->assertSame('2500.00', (string) $whB->committed);
        $this->assertSame(1, BudgetTransaction::where('pr_id', $pr->pr_id)->where('budget_id', $whA->budget_id)->where('txn_type', 'commit')->count());
        $this->assertSame(1, BudgetTransaction::where('pr_id', $pr->pr_id)->where('budget_id', $whB->budget_id)->where('txn_type', 'commit')->count());
    }

    public function test_commit_group_idempotent_per_budget_and_pr(): void
    {
        $whA = $this->makeBudget(10000);
        $pr = $this->newShapePr(4000);

        $this->service->commitGroup($pr, $whA, 4000, null);
        $this->service->commitGroup($pr, $whA, 4000, null); // retry — no-op

        $this->assertSame('4000.00', (string) $whA->fresh()->committed);
        $this->assertSame(1, BudgetTransaction::where('pr_id', $pr->pr_id)->where('budget_id', $whA->budget_id)->where('txn_type', 'commit')->count());
    }

    public function test_release_outstanding_returns_each_budget_share(): void
    {
        $whA = $this->makeBudget(10000);
        $whBId = $this->secondWarehouse();
        $whB = $this->makeBudget(8000, ['location_id' => $whBId]);
        $pr = $this->newShapePr(6500);
        $this->service->commitGroup($pr, $whA, 4000, null);
        $this->service->commitGroup($pr, $whB, 2500, null);

        $released = $this->service->releaseOutstanding($pr, 'PR archived');

        $this->assertSame(6500.0, $released);
        $this->assertSame('0.00', (string) $whA->fresh()->committed);
        $this->assertSame('0.00', (string) $whB->fresh()->committed);
    }

    public function test_spend_resolves_budget_by_po_location(): void
    {
        $whA = $this->makeBudget(10000);              // location = $this->locationId
        $whBId = $this->secondWarehouse();
        $whB = $this->makeBudget(8000, ['location_id' => $whBId]);
        $pr = $this->newShapePr(6500);
        $this->service->commitGroup($pr, $whA, 4000, null);
        $this->service->commitGroup($pr, $whB, 2500, null);

        // PO for warehouse B, actual 2500, completed.
        $po = $this->makePo($pr, 2500, 11);
        $po->update(['location_id' => $whBId]);

        $this->service->spend($po->fresh(), null);

        $whA->refresh(); $whB->refresh();
        $this->assertSame('0.00', (string) $whA->spent);   // untouched
        $this->assertSame('2500.00', (string) $whB->spent); // spent against B only
        $this->assertSame('4000.00', (string) $whA->committed); // A still committed
        $this->assertSame('0.00', (string) $whB->committed);    // B consumed
    }
}
```

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=BudgetGroupTest
```

Expected: FAIL — `commitGroup` undefined; spend/release don't yet resolve per-location.

- [ ] **Step 3: Add `commitGroup()` and refactor `commit()` to delegate**

In `BudgetService.php`, replace the whole existing `commit()` method with a legacy-delegating version plus the new group method:

```php
    /**
     * Legacy single-budget commit — commits the PR's whole estimated_total to
     * its header budget_id. Kept for pre-existing single-warehouse PRs.
     */
    public function commit(PurchaseRequest $pr, ?int $userId = null): void
    {
        $budget = BudgetAllocation::find($pr->budget_id);
        if (!$budget) {
            throw new \RuntimeException('Purchase request has no budget to commit against.');
        }
        $this->commitGroup($pr, $budget, (float) $pr->estimated_total, $userId);
    }

    /**
     * Commit a specific amount for a PR against one budget (one warehouse group).
     * Row-locked; idempotent per (budget_id, pr_id); requires the budget verified
     * with enough available. Throws Unverified/Insufficient exceptions.
     */
    public function commitGroup(PurchaseRequest $pr, BudgetAllocation $budget, float $amount, ?int $userId = null): void
    {
        DB::transaction(function () use ($pr, $budget, $amount, $userId) {
            $locked = BudgetAllocation::whereKey($budget->budget_id)->lockForUpdate()->firstOrFail();

            if (BudgetTransaction::where('pr_id', $pr->pr_id)
                ->where('budget_id', $locked->budget_id)
                ->where('txn_type', BudgetTransaction::TYPE_COMMIT)->exists()) {
                return; // already committed this group (retry / double-submit)
            }

            if ($locked->verification_status !== 'verified') {
                throw new UnverifiedBudgetException($locked->period_label, $locked->verification_status);
            }

            $utilBefore = $this->utilization($locked);

            $available = (float) $locked->total_budget - (float) $locked->committed - (float) $locked->spent;
            if ($amount > $available) {
                throw new InsufficientBudgetException($locked->period_label, $amount, $available);
            }

            $this->txn($locked, BudgetTransaction::TYPE_COMMIT, $amount, $pr->pr_id, null, $userId,
                'PR ' . $pr->pr_number . ' approved');
            $locked->committed = round((float) $locked->committed + $amount, 2);
            $locked->save();

            $this->maybeAlert($locked, $utilBefore);
        });
    }
```

Note: the old idempotency guard was keyed on `pr_id` only; the new one is keyed on `(pr_id, budget_id)`. For legacy single-budget PRs this is behaviorally identical (one budget), so existing tests stay green.

- [ ] **Step 4: Make `releaseOutstanding()` iterate every budget the PR touched**

Replace the whole `releaseOutstanding()` method with:

```php
    /**
     * Release whatever commitment is still outstanding for this PR, across EVERY
     * budget it committed to (one warehouse group each). Returns total released.
     */
    public function releaseOutstanding(PurchaseRequest $pr, string $note, ?int $userId = null): float
    {
        $budgetIds = BudgetTransaction::where('pr_id', $pr->pr_id)
            ->where('txn_type', BudgetTransaction::TYPE_COMMIT)
            ->distinct()->pluck('budget_id');

        // Legacy PRs may carry a header budget_id even with no commit rows yet.
        if ($budgetIds->isEmpty() && $pr->budget_id) {
            $budgetIds = collect([$pr->budget_id]);
        }

        $total = 0.0;
        foreach ($budgetIds as $budgetId) {
            $total += DB::transaction(function () use ($budgetId, $pr, $note, $userId) {
                $budget = BudgetAllocation::whereKey($budgetId)->lockForUpdate()->first();
                if (!$budget) {
                    return 0.0;
                }
                $outstanding = $this->outstanding($budget->budget_id, $pr->pr_id);
                if ($outstanding < 0) {
                    Log::warning("Budget ledger anomaly: negative outstanding {$outstanding} for PR {$pr->pr_id} on budget {$budget->budget_id}");
                }
                if ($outstanding <= 0) {
                    return 0.0;
                }
                $this->txn($budget, BudgetTransaction::TYPE_RELEASE, $outstanding, $pr->pr_id, null, $userId, $note);
                $newCommitted = round((float) $budget->committed - $outstanding, 2);
                if ($newCommitted < 0) {
                    Log::warning("Budget ledger anomaly: committed clamped from {$newCommitted} to 0 on budget {$budget->budget_id} (run budget:rebuild-totals)");
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

- [ ] **Step 5: Make `spend()` resolve the budget by the PO's warehouse**

Replace the `spend()` method. The key change: instead of `$pr->budget_id`, find the budget the PR committed to whose `location_id === $po->location_id`; fall back to `$pr->budget_id` for legacy. Surplus returns when this PO's ordered quantities cover the PR lines destined for this PO's warehouse.

```php
    public function spend(PurchaseOrder $po, ?int $userId = null): void
    {
        $pr = $po->purchaseRequest;
        if (!$pr) {
            return;
        }

        $budgetId = $this->resolveBudgetForPo($pr, $po);
        if (!$budgetId) {
            return;
        }

        DB::transaction(function () use ($po, $pr, $budgetId, $userId) {
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
            $this->txn($budget, BudgetTransaction::TYPE_SPEND, $amount, $pr->pr_id, $po->po_id, $userId,
                'PO ' . $po->po_number . ' completed');
            $budget->spent = round((float) $budget->spent + $amount, 2);

            $outstanding = $this->outstanding($budget->budget_id, $pr->pr_id);
            if ($outstanding < 0) {
                Log::warning("Budget ledger anomaly: negative outstanding {$outstanding} for PR {$pr->pr_id} on budget {$budget->budget_id}");
            }
            $released = 0.0;

            $consume = round(min($outstanding, $amount), 2);
            if ($consume > 0) {
                $this->txn($budget, BudgetTransaction::TYPE_RELEASE, $consume, $pr->pr_id, $po->po_id, $userId,
                    'Commitment consumed by PO ' . $po->po_number);
                $released += $consume;
            }

            // Return estimate surplus for this warehouse group when the PO's
            // ordered quantities cover the PR lines destined for its warehouse.
            if ($this->poCoversItsGroup($pr, $po)) {
                $surplus = round($outstanding - $consume, 2);
                if ($surplus > 0) {
                    $this->txn($budget, BudgetTransaction::TYPE_RELEASE, $surplus, $pr->pr_id, $po->po_id, $userId,
                        'Estimate surplus returned');
                    $released += $surplus;
                }
            }

            $newCommitted = round((float) $budget->committed - $released, 2);
            if ($newCommitted < 0) {
                Log::warning("Budget ledger anomaly: committed clamped from {$newCommitted} to 0 on budget {$budget->budget_id} (run budget:rebuild-totals)");
                $newCommitted = 0.0;
            }
            $budget->committed = $newCommitted;
            $budget->save();

            $this->maybeAlert($budget, $utilBefore);
        });
    }

    /**
     * The budget this PO settles against: among budgets the PR committed to,
     * the one whose location matches the PO's warehouse. Legacy fallback:
     * the PR's header budget_id.
     */
    private function resolveBudgetForPo(PurchaseRequest $pr, PurchaseOrder $po): ?int
    {
        $committedBudgetIds = BudgetTransaction::where('pr_id', $pr->pr_id)
            ->where('txn_type', BudgetTransaction::TYPE_COMMIT)
            ->distinct()->pluck('budget_id');

        if ($committedBudgetIds->isNotEmpty()) {
            $match = BudgetAllocation::whereIn('budget_id', $committedBudgetIds)
                ->where('location_id', $po->location_id)
                ->value('budget_id');
            if ($match) {
                return (int) $match;
            }
        }
        return $pr->budget_id ? (int) $pr->budget_id : null;
    }

    /**
     * True when this PO's ordered quantities cover every PR line destined for
     * the PO's warehouse (so the group is fully ordered and surplus can return).
     * Legacy PRs (no line locations) compare against ALL lines.
     */
    private function poCoversItsGroup(PurchaseRequest $pr, PurchaseOrder $po): bool
    {
        $prLines = $pr->details()
            ->when($pr->details()->whereNotNull('location_id')->exists(),
                fn ($q) => $q->where('location_id', $po->location_id))
            ->get();
        if ($prLines->isEmpty()) {
            return false;
        }
        $ordered = $po->details()->get()->groupBy('product_id')
            ->map(fn ($rows) => (int) $rows->sum('quantity_ordered'));
        foreach ($prLines as $line) {
            if (($ordered[$line->product_id] ?? 0) < (int) $line->quantity) {
                return false;
            }
        }
        return true;
    }
```

Note: the surplus condition changed from the old `pr->status_id===14 && converted_to_po_id===po_id` to a quantity-coverage check. This is behaviorally equivalent for legacy single-PO PRs (a completed PO that ordered all lines covers the group) and is what multi-PO splits require. The existing spend tests in `BudgetLifecycleTest`/`BudgetServiceTest` assert surplus on fully-ordered single POs, which `poCoversItsGroup` still returns true for.

- [ ] **Step 6: Run new + full budget suite**

```powershell
php artisan test --filter=BudgetGroupTest
php artisan test --filter=Budget
```

Expected: `BudgetGroupTest` 4 pass; the whole Budget suite stays green. If a pre-existing spend/surplus test fails, inspect whether `poCoversItsGroup` matches its scenario — do NOT weaken the new logic; fix by ensuring the test's PO actually orders the full PR quantity (it should). Report any test that needed a fixture nudge.

- [ ] **Step 7: Commit**

```powershell
git add app/Services/BudgetService.php tests/Feature/Budget/BudgetGroupTest.php
git commit -m "feat(procurement): BudgetService per-warehouse commit + ledger-driven release/spend

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 3: PR store — supplier, per-line warehouse, warehouse-only, supplier-product integrity

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseRequestController.php`
- Test: `back-end/tests/Feature/Budget/WarehousePrTest.php` (create)

- [ ] **Step 1: Write failing tests**

`back-end/tests/Feature/Budget/WarehousePrTest.php`:

```php
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
        $this->makeBudget(10000); // warehouse budget for $this->locationId (unused here)
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
        // product mapped ONLY to a different supplier
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
        $pid = $this->product(false); // no supplier mapping at all

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
            ['product_id' => $pA, 'location_id' => $whA, 'quantity' => 2, 'estimated_price' => 100], // 200
            ['product_id' => $pB, 'location_id' => $whB, 'quantity' => 3, 'estimated_price' => 100], // 300
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
        $this->makeBudget(100, ['location_id' => $whB]); // too small for WH-B group
        $pA = $this->product(); $pB = $this->product();

        $res = $this->actingAs($ia, 'sanctum')->postJson('/api/purchase-requests', $this->payload([
            ['product_id' => $pA, 'location_id' => $whA, 'quantity' => 2, 'estimated_price' => 100],
            ['product_id' => $pB, 'location_id' => $whB, 'quantity' => 3, 'estimated_price' => 100], // 300 > 100
        ]));

        $res->assertStatus(422);
        $this->assertStringContainsString('WH B', $res->json('message'));
    }
}
```

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=WarehousePrTest
```

Expected: FAIL — store still expects header `location_id`/`budget_id`, ignores line locations/supplier.

- [ ] **Step 3: Rewrite `store()`**

Replace `PurchaseRequestController::store()` body with the new-shape version. Full method:

```php
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'supplier_id'               => 'required|exists:suppliers,supplier_id',
                'needed_by_date'            => 'required|date|after:today',
                'purpose'                   => 'nullable|string',
                'details'                   => 'required|array|min:1',
                'details.*.product_id'      => 'required|exists:products,product_id',
                'details.*.location_id'     => 'required|exists:locations,location_id',
                'details.*.quantity'        => 'required|integer|min:1',
                'details.*.estimated_price' => 'required|numeric|min:0',
                'details.*.notes'           => 'nullable|string',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        // Every destination must be a warehouse.
        $locationTypes = \App\Models\Location::whereIn('location_id',
            collect($validated['details'])->pluck('location_id')->unique())
            ->pluck('location_type', 'location_id');
        foreach ($validated['details'] as $d) {
            if (($locationTypes[$d['location_id']] ?? null) !== 'warehouse') {
                return $this->error('Purchase orders are received at warehouses only — showrooms are supplied by stock transfer.', 422);
            }
        }

        // Product–supplier integrity: a product mapped to OTHER suppliers (and not
        // this one) is rejected; a product with NO mappings is allowed.
        $supplierId = (int) $validated['supplier_id'];
        foreach (collect($validated['details'])->pluck('product_id')->unique() as $pid) {
            $mappings = \App\Models\SupplierProduct::where('product_id', $pid)->pluck('supplier_id');
            if ($mappings->isNotEmpty() && !$mappings->contains($supplierId)) {
                $name = \App\Models\Product::where('product_id', $pid)->value('product_name');
                return $this->error("Product \"{$name}\" is not supplied by the selected supplier.", 422);
            }
        }

        // Per-warehouse advisory budget check (unlocked; approval re-checks under lock).
        $groups = collect($validated['details'])->groupBy('location_id');
        foreach ($groups as $locId => $lines) {
            $subtotal = collect($lines)->sum(fn ($d) => $d['quantity'] * $d['estimated_price']);
            $budget = $this->budgetService->resolveActiveBudget((int) $locId);
            $whName = \App\Models\Location::where('location_id', $locId)->value('location_name');
            if (!$budget) {
                return $this->error("{$whName} has no verified active budget for today.", 422);
            }
            if (!$this->budgetService->hasAvailable($budget, (float) $subtotal)) {
                return $this->error(sprintf(
                    'Estimated total ₱%s for %s exceeds its available budget ₱%s (%s).',
                    number_format((float) $subtotal, 2), $whName,
                    number_format(max((float) $budget->available, 0), 2), $budget->period_label
                ), 422);
            }
        }

        $estimatedTotal = collect($validated['details'])
            ->sum(fn ($d) => $d['quantity'] * $d['estimated_price']);

        try {
            $pr = DB::transaction(function () use ($validated, $request, $estimatedTotal, $supplierId) {
                $pr = PurchaseRequest::create([
                    'location_id'     => null,
                    'supplier_id'     => $supplierId,
                    'budget_id'       => null,
                    'pr_number'       => 'PR-' . date('Ymd') . '-' . strtoupper(Str::random(4)),
                    'request_date'    => today(),
                    'needed_by_date'  => $validated['needed_by_date'],
                    'purpose'         => $validated['purpose'] ?? null,
                    'estimated_total' => $estimatedTotal,
                    'status_id'       => 13,
                    'requested_by'    => $request->user()->user_id,
                ]);

                foreach ($validated['details'] as $detail) {
                    PurchaseRequestDetail::create([
                        'pr_id'           => $pr->pr_id,
                        'product_id'      => $detail['product_id'],
                        'location_id'     => $detail['location_id'],
                        'quantity'        => $detail['quantity'],
                        'estimated_price' => $detail['estimated_price'],
                        'subtotal'        => $detail['quantity'] * $detail['estimated_price'],
                        'notes'           => $detail['notes'] ?? null,
                    ]);
                }
                return $pr;
            });

            foreach (collect($validated['details'])->pluck('location_id')->unique() as $locId) {
                RealtimeBroadcaster::approvalQueueChanged((int) $locId, 'pr_changed');
            }

            $pr->load(self::WITH_RELATIONS);
            return $this->success($pr, 'Purchase request submitted', 201);
        } catch (\Throwable $e) {
            return $this->error('Failed to create purchase request: ' . $e->getMessage(), 500);
        }
    }
```

Also add `'supplier'` and `'details.location'` to the `self::WITH_RELATIONS` constant (so responses/exports carry them). Find the `WITH_RELATIONS` array near the top of the controller and add `'supplier',` and `'details.location',` entries.

- [ ] **Step 4: Run to verify pass**

```powershell
php artisan test --filter=WarehousePrTest
php artisan test --filter=Budget
```

Expected: `WarehousePrTest` 5 pass; full Budget suite green (existing PR-store tests in `BudgetEnforcementTest` used the OLD header shape — they will now fail because store no longer accepts `location_id`/`budget_id`. Update those specific tests to the new shape: supplier + per-line warehouse `location_id`, dropping header `budget_id`/`location_id`. Do NOT weaken store — port the fixtures. Report which tests you ported.)

- [ ] **Step 5: Commit**

```powershell
git add app/Http/Controllers/Api/PurchaseRequestController.php tests/Feature/Budget/WarehousePrTest.php tests/Feature/Budget/BudgetEnforcementTest.php
git commit -m "feat(procurement): PR store is supplier-scoped with warehouse-only per-line destinations

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 4: PR approve — per-warehouse all-or-nothing commits

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseRequestController.php`
- Test: `back-end/tests/Feature/Budget/WarehousePrApproveTest.php` (create)

- [ ] **Step 1: Write failing tests**

`back-end/tests/Feature/Budget/WarehousePrApproveTest.php`:

```php
<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetTransaction;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WarehousePrApproveTest extends BudgetTestCase
{
    private function warehouse(): int
    {
        return (int) DB::table('locations')->insertGetId([
            'location_code' => 'WH-' . uniqid(), 'location_name' => 'WH',
            'location_type' => 'warehouse', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], 'location_id');
    }

    /** New-shape pending PR spanning two warehouses. Returns [pr, whAId, whBId]. */
    private function twoWarehousePr(int $whAId, int $whBId): PurchaseRequest
    {
        $pr = PurchaseRequest::create([
            'location_id' => null, 'supplier_id' => null, 'budget_id' => null,
            'pr_number' => 'PR-' . strtoupper(Str::random(6)),
            'request_date' => today(), 'needed_by_date' => today()->addDays(7),
            'estimated_total' => 500, 'status_id' => 13,
            'requested_by' => $this->makeUser('inventory_analyst')->user_id,
        ]);
        $pid = (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whAId,'quantity'=>2,'estimated_price'=>100,'subtotal'=>200]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whBId,'quantity'=>3,'estimated_price'=>100,'subtotal'=>300]);
        return $pr;
    }

    public function test_approve_commits_each_warehouse_to_its_budget(): void
    {
        $admin = $this->makeUser('admin');
        $whA = $this->locationId; $whB = $this->warehouse();
        $bA = $this->makeBudget(10000, ['location_id' => $whA]);
        $bB = $this->makeBudget(10000, ['location_id' => $whB]);
        $pr = $this->twoWarehousePr($whA, $whB);

        $this->actingAs($admin, 'sanctum')->patchJson("/api/purchase-requests/{$pr->pr_id}/approve")
            ->assertSuccessful();

        $this->assertSame('200.00', (string) $bA->fresh()->committed);
        $this->assertSame('300.00', (string) $bB->fresh()->committed);
        $this->assertSame(14, (int) $pr->fresh()->status_id);
    }

    public function test_approve_all_or_nothing_when_one_warehouse_unverified(): void
    {
        $admin = $this->makeUser('admin');
        $whA = $this->locationId; $whB = $this->warehouse();
        $bA = $this->makeBudget(10000, ['location_id' => $whA]);
        $bB = $this->makeBudget(10000, ['location_id' => $whB, 'verification_status' => 'pending']);
        $pr = $this->twoWarehousePr($whA, $whB);

        $res = $this->actingAs($admin, 'sanctum')->patchJson("/api/purchase-requests/{$pr->pr_id}/approve");

        $res->assertStatus(422);
        $this->assertSame(13, (int) $pr->fresh()->status_id);           // still pending
        $this->assertSame('0.00', (string) $bA->fresh()->committed);    // WH-A rolled back too
        $this->assertSame(0, BudgetTransaction::where('pr_id', $pr->pr_id)->count());
    }
}
```

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=WarehousePrApproveTest
```

Expected: FAIL — approve still uses the single `budget_id` path.

- [ ] **Step 3: Rewrite `approve()`**

Replace `approve()` so new-shape PRs (details have `location_id`) commit per warehouse group in one transaction; legacy PRs (header `budget_id`, no line locations) keep the old single commit. Full method:

```php
    public function approve(Request $request, $id)
    {
        $pr = PurchaseRequest::with('details')->find($id);
        if (!$pr) {
            return $this->notFound('Purchase request not found');
        }
        if (!$pr->isPending()) {
            return $this->error('Only pending purchase requests can be approved', 422);
        }

        $hasLineWarehouses = $pr->details->whereNotNull('location_id')->isNotEmpty();

        if (!$hasLineWarehouses && !$pr->budget_id) {
            return $this->error(
                'This purchase request has no budget attached (created before budget enforcement). Reject it and ask the requester to submit a new one.',
                422
            );
        }

        try {
            DB::transaction(function () use ($pr, $request, $hasLineWarehouses) {
                $pr->update([
                    'status_id'    => 14,
                    'approved_by'  => $request->user()->user_id,
                    'approval_date'=> today(),
                ]);

                if ($hasLineWarehouses) {
                    $groups = $pr->details->groupBy('location_id');
                    foreach ($groups as $locId => $lines) {
                        $subtotal = (float) $lines->sum('subtotal');
                        $budget = $this->budgetService->resolveActiveBudget((int) $locId);
                        if (!$budget) {
                            $whName = \App\Models\Location::where('location_id', $locId)->value('location_name');
                            throw new \App\Exceptions\UnverifiedBudgetException($whName ?? "warehouse #{$locId}", 'pending');
                        }
                        $this->budgetService->commitGroup($pr, $budget, $subtotal, $request->user()->user_id);
                    }
                } else {
                    $this->budgetService->commit($pr, $request->user()->user_id);
                }
            });
        } catch (UnverifiedBudgetException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (InsufficientBudgetException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('Approval failed: ' . $e->getMessage(), 500);
        }

        foreach ($pr->details->pluck('location_id')->filter()->unique() as $locId) {
            RealtimeBroadcaster::approvalQueueChanged((int) $locId, 'pr_changed');
        }

        $pr->load(self::WITH_RELATIONS);
        return $this->success($pr, 'Purchase request approved');
    }
```

Note: `commitGroup` throws `UnverifiedBudgetException`/`InsufficientBudgetException` inside the loop; because the whole loop is inside one outer `DB::transaction`, the first throw rolls back the status update AND any earlier group's commit — the all-or-nothing behavior the test asserts. (`commitGroup` opens a nested savepoint; the outer rollback still unwinds committed groups.)

- [ ] **Step 4: Run to verify pass**

```powershell
php artisan test --filter=WarehousePrApproveTest
php artisan test --filter=Budget
```

Expected: both green. Existing approve tests in `BudgetEnforcementTest` use legacy-shape PRs via `makePr` (header budget_id) — they still hit the `else` legacy branch and pass. If any were ported to new shape in Task 3, ensure they set line locations.

- [ ] **Step 5: Commit**

```powershell
git add app/Http/Controllers/Api/PurchaseRequestController.php tests/Feature/Budget/WarehousePrApproveTest.php
git commit -m "feat(procurement): PR approval commits each warehouse group to its own budget (all-or-nothing)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 5: PR archive/reject realtime + warehouse-only PO store/update

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseRequestController.php` (destroy/reject broadcasts)
- Modify: `back-end/app/Http/Controllers/Api/PurchaseOrderController.php` (store/update warehouse guard)
- Test: `back-end/tests/Feature/Budget/WarehouserOnlyPoTest.php` (create)

- [ ] **Step 1: Write failing test (warehouse-only PO)**

`back-end/tests/Feature/Budget/WarehouserOnlyPoTest.php`:

```php
<?php

namespace Tests\Feature\Budget;

use Illuminate\Support\Facades\DB;

class WarehouserOnlyPoTest extends BudgetTestCase
{
    public function test_direct_po_create_to_showroom_is_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $showroom = (int) DB::table('locations')->insertGetId([
            'location_code' => 'SR-' . uniqid(), 'location_name' => 'Showroom',
            'location_type' => 'showroom', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], 'location_id');
        $supplier = (int) DB::table('suppliers')->insertGetId([
            'supplier_name' => 'S', 'created_at' => now(), 'updated_at' => now(),
        ], 'supplier_id');
        $pid = (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier, 'location_id' => $showroom,
            'po_number' => 'PO-' . uniqid(), 'order_date' => today()->toDateString(),
            'details' => [['product_id' => $pid, 'quantity_ordered' => 1, 'unit_price' => 100]],
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('warehouse', strtolower($res->json('message')));
    }
}
```

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=WarehouserOnlyPoTest
```

Expected: FAIL — PO store accepts a showroom.

- [ ] **Step 3: Add the warehouse guard to PO store + update**

In `PurchaseOrderController::store()`, after `$validated = $request->validate([...])` and before `DB::beginTransaction()`, add:

```php
            $loc = \App\Models\Location::find($validated['location_id']);
            if (!$loc || $loc->location_type !== 'warehouse') {
                return $this->error('Purchase orders are received at warehouses only — other locations are supplied by stock transfer.', 422);
            }
```

In `PurchaseOrderController::update()`, after its `$validated = $request->validate([...])` and before `DB::beginTransaction()`, add (only checks when location is being set):

```php
            if (array_key_exists('location_id', $validated)) {
                $loc = \App\Models\Location::find($validated['location_id']);
                if (!$loc || $loc->location_type !== 'warehouse') {
                    return $this->error('Purchase orders are received at warehouses only — other locations are supplied by stock transfer.', 422);
                }
            }
```

- [ ] **Step 4: Fix the destroy/reject realtime broadcasts**

In `PurchaseRequestController::destroy()` and `reject()`, the `RealtimeBroadcaster::approvalQueueChanged((int) $pr->location_id, ...)` line uses the now-often-null header `location_id`. Replace each with a per-line-warehouse broadcast (falling back to header for legacy):

```php
        $targets = $pr->details()->whereNotNull('location_id')->distinct()->pluck('location_id');
        if ($targets->isEmpty() && $pr->location_id) { $targets = collect([$pr->location_id]); }
        foreach ($targets as $locId) {
            RealtimeBroadcaster::approvalQueueChanged((int) $locId, 'pr_changed');
        }
```

(In `destroy()` place it after the transaction; in `reject()` after `$pr->update(...)`. Remove the old single-line broadcast in each.)

- [ ] **Step 5: Run to verify pass**

```powershell
php artisan test --filter=WarehouserOnlyPoTest
php artisan test --filter=Budget
```

Expected: green. (The prior budget-layer warehouse restriction was on budgets; this adds it to POs.)

- [ ] **Step 6: Commit**

```powershell
git add app/Http/Controllers/Api/PurchaseOrderController.php app/Http/Controllers/Api/PurchaseRequestController.php tests/Feature/Budget/WarehouserOnlyPoTest.php
git commit -m "feat(procurement): warehouse-only PO create/update + per-warehouse PR realtime signals

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 6: PR convert — auto-split into one PO per warehouse

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseRequestController.php` (`convert` GET + `convertStore` POST)
- Test: `back-end/tests/Feature/Budget/PrConvertSplitTest.php` (create)

- [ ] **Step 1: Write failing tests**

`back-end/tests/Feature/Budget/PrConvertSplitTest.php`:

```php
<?php

namespace Tests\Feature\Budget;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrConvertSplitTest extends BudgetTestCase
{
    private function warehouse(): int
    {
        return (int) DB::table('locations')->insertGetId([
            'location_code' => 'WH-' . uniqid(), 'location_name' => 'WH',
            'location_type' => 'warehouse', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], 'location_id');
    }

    public function test_convert_splits_into_one_po_per_warehouse(): void
    {
        $admin = $this->makeUser('admin');
        $supplier = (int) DB::table('suppliers')->insertGetId(['supplier_name' => 'S', 'created_at' => now(), 'updated_at' => now()], 'supplier_id');
        $whA = $this->locationId; $whB = $this->warehouse();
        $this->makeBudget(10000, ['location_id' => $whA]);
        $this->makeBudget(10000, ['location_id' => $whB]);
        $pid = (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');

        // Approved new-shape PR spanning WH-A and WH-B.
        $pr = PurchaseRequest::create([
            'location_id' => null, 'supplier_id' => $supplier, 'budget_id' => null,
            'pr_number' => 'PR-' . strtoupper(Str::random(6)),
            'request_date' => today(), 'needed_by_date' => today()->addDays(7),
            'estimated_total' => 500, 'status_id' => 14,
            'requested_by' => $this->makeUser('inventory_analyst')->user_id,
            'approved_by' => $admin->user_id, 'approval_date' => today(),
        ]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whA,'quantity'=>2,'estimated_price'=>100,'subtotal'=>200]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whB,'quantity'=>3,'estimated_price'=>100,'subtotal'=>300]);

        $res = $this->actingAs($admin, 'sanctum')->postJson("/api/purchase-requests/{$pr->pr_id}/convert", [
            'details' => [
                ['product_id' => $pid, 'location_id' => $whA, 'quantity_ordered' => 2, 'unit_price' => 100],
                ['product_id' => $pid, 'location_id' => $whB, 'quantity_ordered' => 3, 'unit_price' => 100],
            ],
        ]);

        $res->assertSuccessful();
        $pos = PurchaseOrder::where('pr_id', $pr->pr_id)->get();
        $this->assertCount(2, $pos);
        $this->assertEqualsCanonicalizing([$whA, $whB], $pos->pluck('location_id')->map(fn ($v) => (int) $v)->all());
        foreach ($pos as $po) {
            $this->assertSame($supplier, (int) $po->supplier_id);
            $this->assertSame(7, (int) $po->status_id);
        }
    }

    public function test_reconvert_blocked(): void
    {
        $admin = $this->makeUser('admin');
        $supplier = (int) DB::table('suppliers')->insertGetId(['supplier_name' => 'S', 'created_at' => now(), 'updated_at' => now()], 'supplier_id');
        $wh = $this->locationId;
        $pid = (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
        $pr = PurchaseRequest::create([
            'location_id' => null, 'supplier_id' => $supplier, 'budget_id' => null,
            'pr_number' => 'PR-' . strtoupper(Str::random(6)),
            'request_date' => today(), 'needed_by_date' => today()->addDays(7),
            'estimated_total' => 200, 'status_id' => 14,
            'requested_by' => $this->makeUser('inventory_analyst')->user_id,
            'converted_to_po_id' => null,
        ]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$wh,'quantity'=>2,'estimated_price'=>100,'subtotal'=>200]);

        $body = ['details' => [['product_id' => $pid, 'location_id' => $wh, 'quantity_ordered' => 2, 'unit_price' => 100]]];
        $this->actingAs($admin, 'sanctum')->postJson("/api/purchase-requests/{$pr->pr_id}/convert", $body)->assertSuccessful();
        $this->actingAs($admin, 'sanctum')->postJson("/api/purchase-requests/{$pr->pr_id}/convert", $body)->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=PrConvertSplitTest
```

Expected: FAIL — convertStore currently creates ONE PO and requires a `supplier_id` in the body.

- [ ] **Step 3: Rewrite `convertStore()`**

Replace `convertStore()`. For new-shape PRs (details carry `location_id`), group the posted details by warehouse and create one PO per group, all with the PR's `supplier_id`; keep the legacy single-PO path when the PR has no line warehouses. Full method:

```php
    public function convertStore(Request $request, $id)
    {
        $pr = PurchaseRequest::with('details')->find($id);
        if (!$pr) {
            return $this->notFound('Purchase request not found');
        }
        if (!$pr->isApproved() && (int) $pr->status_id !== 16) {
            return $this->error('Only approved purchase requests can be converted to a PO', 403);
        }
        if ($pr->converted_to_po_id) {
            return $this->error('This purchase request has already been converted to a PO', 422);
        }

        $hasLineWarehouses = $pr->details->whereNotNull('location_id')->isNotEmpty();

        try {
            $rules = [
                'expected_delivery_date'     => 'nullable|date',
                'payment_terms'              => 'nullable|string|max:50',
                'notes'                      => 'nullable|string',
                'references_note'            => 'nullable|string',
                'ship_to_name'               => 'nullable|string|max:255',
                'ship_to_address'            => 'nullable|string',
                'details'                    => 'required|array|min:1',
                'details.*.product_id'       => 'required|exists:products,product_id',
                'details.*.quantity_ordered' => 'required|integer|min:1',
                'details.*.unit_price'       => 'required|numeric|min:0',
            ];
            // New shape requires a per-line warehouse; legacy takes a supplier_id.
            if ($hasLineWarehouses) {
                $rules['details.*.location_id'] = 'required|exists:locations,location_id';
            } else {
                $rules['supplier_id'] = 'required|exists:suppliers,supplier_id';
            }
            $validated = $request->validate($rules);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $firstPo = DB::transaction(function () use ($pr, $validated, $request, $hasLineWarehouses) {
                $mkLine = function ($d) {
                    $d['subtotal'] = $d['quantity_ordered'] * $d['unit_price'];
                    return $d;
                };

                $groups = $hasLineWarehouses
                    ? collect($validated['details'])->map($mkLine)->groupBy('location_id')
                    : collect(['_' => collect($validated['details'])->map($mkLine)]);

                $supplierId = $hasLineWarehouses ? $pr->supplier_id : $validated['supplier_id'];
                $createdPos = [];

                foreach ($groups as $locKey => $lines) {
                    $locationId = $hasLineWarehouses ? (int) $locKey : (int) $pr->location_id;
                    $grand = collect($lines)->sum('subtotal');

                    $po = PurchaseOrder::create([
                        'pr_id'                  => $pr->pr_id,
                        'supplier_id'            => $supplierId,
                        'location_id'            => $locationId,
                        'po_number'              => 'PO-' . date('Ymd') . '-' . strtoupper(Str::random(4)),
                        'order_date'             => today(),
                        'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                        'payment_terms'          => $validated['payment_terms']    ?? null,
                        'notes'                  => $validated['notes']            ?? null,
                        'references_note'        => $validated['references_note']  ?? null,
                        'ship_to_name'           => $validated['ship_to_name']     ?? null,
                        'ship_to_address'        => $validated['ship_to_address']  ?? null,
                        'total_amount'           => $grand,
                        'grand_total'            => $grand,
                        'status_id'              => 7,
                        'created_by'             => $request->user()->user_id,
                    ]);
                    foreach ($lines as $line) {
                        PurchaseOrderDetail::create([
                            'po_id'            => $po->po_id,
                            'product_id'       => $line['product_id'],
                            'quantity_ordered' => $line['quantity_ordered'],
                            'unit_price'       => $line['unit_price'],
                            'subtotal'         => $line['subtotal'],
                        ]);
                    }
                    $createdPos[] = $po;
                }

                // Mark the PR converted. converted_to_po_id points at the first PO
                // (backward-compat); the real link is POs-by-pr_id.
                $pr->update(['converted_to_po_id' => $createdPos[0]->po_id]);

                // Under-ordered vs the PR lines → Partially Converted (16), else 14.
                $orderedByProdLoc = collect($validated['details'])->groupBy(fn ($d) =>
                    ($hasLineWarehouses ? $d['location_id'] : '_') . ':' . $d['product_id'])
                    ->map(fn ($rows) => collect($rows)->sum('quantity_ordered'));
                $isPartial = $pr->details->contains(function ($prDetail) use ($orderedByProdLoc, $hasLineWarehouses) {
                    $key = ($hasLineWarehouses ? $prDetail->location_id : '_') . ':' . $prDetail->product_id;
                    return ($orderedByProdLoc[$key] ?? 0) < (int) $prDetail->quantity;
                });
                $pr->update(['status_id' => $isPartial ? 16 : 14]);

                return $createdPos[0];
            });

            foreach ($pr->details->pluck('location_id')->filter()->unique() as $locId) {
                RealtimeBroadcaster::approvalQueueChanged((int) $locId, 'pr_changed');
                RealtimeBroadcaster::purchaseOrderChanged((int) $locId, 'pr_converted');
            }
            if (!$hasLineWarehouses && $pr->location_id) {
                RealtimeBroadcaster::purchaseOrderChanged((int) $pr->location_id, 'pr_converted');
            }

            $firstPo->load(['supplier', 'location', 'status', 'purchaseRequest',
                'details.product.applianceDetail.brand', 'details.product.consumableDetail.unit']);
            return $this->success($firstPo, 'Purchase request converted to purchase order', 201);
        } catch (\Throwable $e) {
            return $this->error('Conversion failed: ' . $e->getMessage(), 500);
        }
    }
```

- [ ] **Step 4: Update `convert()` (GET prefill) to group by warehouse**

In `convert()`, after building `$prefilledLines`, add each line's `location_id`/warehouse name and the PR's fixed supplier. Change the `$prefilledLines` map to include:

```php
                'location_id'   => $d->location_id,
                'warehouse_name'=> $d->location?->location_name,
```

(eager-load `details.location` — add `'details.location'` to the `with([...])` array in `convert()`), and change the returned payload's `'suppliers'` to also include the PR's own supplier so the frontend can lock it: add `'pr_supplier_id' => $pr->supplier_id` to the `$this->success([...])` data. Keep the existing suppliers list for the legacy path.

- [ ] **Step 5: Run to verify pass**

```powershell
php artisan test --filter=PrConvertSplitTest
php artisan test --filter=Budget
```

Expected: green. Legacy convert tests (if any exist in the suite) still pass via the `else` branch.

- [ ] **Step 6: Commit**

```powershell
git add app/Http/Controllers/Api/PurchaseRequestController.php tests/Feature/Budget/PrConvertSplitTest.php
git commit -m "feat(procurement): PR conversion auto-splits into one PO per destination warehouse

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 7: End-to-end backend settle test + full suite

**Files:**
- Test: `back-end/tests/Feature/Budget/WarehouseFlowE2ETest.php` (create)

- [ ] **Step 1: Write the end-to-end test**

Covers submit → approve (per-warehouse commit) → convert (split) → complete each PO (spend against its warehouse) → archive releases remainder.

`back-end/tests/Feature/Budget/WarehouseFlowE2ETest.php`:

```php
<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use Illuminate\Support\Facades\DB;

class WarehouseFlowE2ETest extends BudgetTestCase
{
    public function test_two_warehouse_pr_settles_against_each_budget(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $admin = $this->makeUser('admin');
        $whA = $this->locationId;
        $whB = (int) DB::table('locations')->insertGetId([
            'location_code' => 'WH-' . uniqid(), 'location_name' => 'WH B',
            'location_type' => 'warehouse', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], 'location_id');
        $bA = $this->makeBudget(10000, ['location_id' => $whA]);
        $bB = $this->makeBudget(10000, ['location_id' => $whB]);
        $supplier = (int) DB::table('suppliers')->insertGetId(['supplier_name' => 'S', 'created_at' => now(), 'updated_at' => now()], 'supplier_id');
        $pid = (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
        DB::table('supplier_product')->insert(['supplier_id' => $supplier, 'product_id' => $pid, 'product_price' => 80, 'created_at' => now(), 'updated_at' => now()]);

        // Submit
        $prId = $this->actingAs($ia, 'sanctum')->postJson('/api/purchase-requests', [
            'supplier_id' => $supplier, 'needed_by_date' => today()->addDays(7)->toDateString(),
            'details' => [
                ['product_id' => $pid, 'location_id' => $whA, 'quantity' => 2, 'estimated_price' => 100], // 200
                ['product_id' => $pid, 'location_id' => $whB, 'quantity' => 3, 'estimated_price' => 100], // 300
            ],
        ])->assertStatus(201)->json('data.pr_id');

        // Approve → commits 200 to A, 300 to B
        $this->actingAs($admin, 'sanctum')->patchJson("/api/purchase-requests/{$prId}/approve")->assertSuccessful();
        $this->assertSame('200.00', (string) $bA->fresh()->committed);
        $this->assertSame('300.00', (string) $bB->fresh()->committed);

        // Convert → two POs
        $this->actingAs($admin, 'sanctum')->postJson("/api/purchase-requests/{$prId}/convert", [
            'details' => [
                ['product_id' => $pid, 'location_id' => $whA, 'quantity_ordered' => 2, 'unit_price' => 100],
                ['product_id' => $pid, 'location_id' => $whB, 'quantity_ordered' => 3, 'unit_price' => 100],
            ],
        ])->assertSuccessful();
        $pos = PurchaseOrder::where('pr_id', $prId)->get();
        $this->assertCount(2, $pos);

        // Complete each PO via PurchaseOrderController::update (status 11) → spend against its warehouse.
        // (POs need verification skipped for this budget-focused test — set verified directly.)
        foreach ($pos as $po) {
            $po->update(['verification_status' => 'verified']);
            $this->actingAs($admin, 'sanctum')->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 11])->assertSuccessful();
        }

        $bA->refresh(); $bB->refresh();
        $this->assertSame('200.00', (string) $bA->spent);
        $this->assertSame('300.00', (string) $bB->spent);
        $this->assertSame('0.00', (string) $bA->committed);
        $this->assertSame('0.00', (string) $bB->committed);
    }
}
```

Adaptation: the `PurchaseOrderController::update()` completion path calls `BudgetService::spend($po)` on transition to 11 (existing budget-layer wiring). If verification gating blocks the status-11 transition, the test sets `verification_status=verified` first, matching the prior BudgetLifecycle tests. If the update path needs the PO's `total_amount` set (it is, from conversion), no extra fixture is needed.

- [ ] **Step 2: Run it + full backend suite**

```powershell
php artisan test --filter=WarehouseFlowE2ETest
php artisan test
```

Expected: the E2E passes; the whole suite is green except the 2 known pre-existing `NavBundleTest` failures. Fix any OTHER failure by porting its fixtures to the new PR shape (never weakening gates). Report each fix.

- [ ] **Step 3: Commit**

```powershell
git add tests/Feature/Budget/WarehouseFlowE2ETest.php
git commit -m "test(procurement): end-to-end two-warehouse PR settles against each budget

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 8: Frontend — PR create form (supplier, per-line warehouse, per-warehouse budgets)

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseRequestPage.jsx`
- Modify: `frontend/src/admin/services/api.js` (if a products-by-supplier client method is missing)

READ `PurchaseRequestPage.jsx` fully first — it's large. The existing create form has: a location selector, budget card(s), a product picker, line items with qty/price, and `handleSubmitPR`. This task restructures it.

- [ ] **Step 1: Ensure an API method for products-by-supplier + warehouses list**

In `frontend/src/admin/services/api.js`, confirm/add on `purchaseOrdersAPI` (or a shared `suppliersAPI`) a method:

```js
  getProductsBySupplier: async (supplierId) =>
    apiRequest(`/purchase-orders/products-by-supplier/${supplierId}`),
```

(If it already exists under another name used by the PO page, reuse that.) The suppliers list and locations list already have API methods (`suppliersAPI.getAll` / `locationsAPI.getAll`) — confirm the exact names in the file.

- [ ] **Step 2: Restructure the create form**

In `PurchaseRequestPage.jsx`:
- Add a **Supplier** `<select>` at the top of the create form (options from suppliers list). Store `selectedSupplierId`. Changing it clears `lineItems` and reloads the product list via `getProductsBySupplier`.
- The product picker now lists only that supplier's products.
- Each line item gains a **Warehouse** `<select>` listing warehouse-type locations only (`locations.filter(l => l.location_type === 'warehouse')`). Store `locationId` per line.
- Remove the single header location selector and the single budget card.
- Add **per-warehouse budget cards**: derive the distinct warehouses used across current lines; for each, fetch its active budget (`budgetAllocationsAPI.getForLocation` then `getAvailable`, reusing the existing pattern) and render a card with the verification badge and available vs. that warehouse's running line-subtotal. Disable submit when any warehouse group is over budget, unverified, or has no budget — mirroring the backend messages.

- [ ] **Step 3: Update `handleSubmitPR`**

Build the payload as:

```js
      const payload = {
        supplier_id: Number(selectedSupplierId),
        needed_by_date: neededByDate,
        purpose: purpose.trim() || null,
        details: validLines.map(li => ({
          product_id: Number(li.productId),
          location_id: Number(li.locationId),
          quantity: Math.max(1, parseInt(li.qty) || 1),
          estimated_price: Number(li.estPrice) || 0,
        })),
      };
```

Add front-of-submit guards: supplier chosen; every line has a warehouse; every warehouse group's budget is verified and sufficient (reuse the per-card computed state). Keep the backend as the source of truth (it re-checks).

- [ ] **Step 4: Verify**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\frontend
npx eslint src/admin/pages/PurchaseRequestPage.jsx src/admin/services/api.js
npm run build
```

Expected: no new lint errors; build succeeds. (The pre-existing App.test.jsx jest failure is unrelated — do not chase it.)

- [ ] **Step 5: Commit**

```powershell
git add src/admin/pages/PurchaseRequestPage.jsx src/admin/services/api.js
git commit -m "feat(procurement): PR create form is supplier-scoped with per-line warehouse + per-warehouse budgets

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 9: Frontend — PR list/detail/convert + CSV export

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseRequestPage.jsx` (detail/list rendering)
- Modify: `frontend/src/admin/pages/PurchaseOrderPage.jsx` (convert screen: lock supplier from PR, group by warehouse)
- Modify: `back-end/app/Http/Controllers/Api/PurchaseRequestController.php` (CSV export columns)

- [ ] **Step 1: PR detail/list — show supplier + per-line warehouse**

In `PurchaseRequestPage.jsx` detail/list rendering, show the PR's `supplier.supplier_name` in the header, and add a **Requested For (Warehouse)** column to the line items table reading `detail.location?.location_name` (backend now eager-loads `details.location` via `WITH_RELATIONS`). Remove header-location displays that assumed one location.

- [ ] **Step 2: Convert screen — supplier locked, lines grouped by warehouse**

In `PurchaseOrderPage.jsx` (the convert-from-PR flow that calls `getConvertForm`/`convertToPO`): use the returned `pr_supplier_id` to lock the supplier (no supplier picker for new-shape PRs), and show the prefilled lines grouped by `warehouse_name`. On submit, send `details` including each line's `location_id` (the backend splits by it). Legacy PRs (no `location_id` on lines) keep the existing single-supplier-picker behavior.

- [ ] **Step 3: PR CSV export columns (backend)**

In `PurchaseRequestController::export()`, replace the header-level 'Budget Period'/'Budget Verification' columns with 'Supplier' and a per-line 'Requested For (Warehouse)'. Eager-load `supplier` and `details.location` in the export query. In both `fputcsv` rows, output `$pr->supplier?->supplier_name ?? ''` and, per detail line, `$d->location?->location_name ?? ''`. Keep column counts aligned between header and both rows (verify by counting).

- [ ] **Step 4: Verify**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\frontend
npx eslint src/admin/pages/PurchaseRequestPage.jsx src/admin/pages/PurchaseOrderPage.jsx
npm run build
cd ..\back-end
php artisan test --filter=Budget
```

Expected: build + budget suite green.

- [ ] **Step 5: Commit**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory
git add frontend/src/admin/pages/PurchaseRequestPage.jsx frontend/src/admin/pages/PurchaseOrderPage.jsx back-end/app/Http/Controllers/Api/PurchaseRequestController.php
git commit -m "feat(procurement): supplier + per-line warehouse in PR detail/convert/export

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 10: Full verification + dev walkthrough

**Files:** none new.

- [ ] **Step 1: Full backend suite**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\back-end
php artisan test
```

Expected: green except the 2 known pre-existing `NavBundleTest` failures. Any other failure = a pre-existing PR/PO test on the old shape; port its fixtures to the new shape (supplier + per-line warehouse). Never weaken a gate. Report each.

- [ ] **Step 2: Frontend build**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\frontend
npm run build
```

Expected: build succeeds.

- [ ] **Step 3: Manual walkthrough (dev)**

Ensure the dev DB has ≥2 warehouses each with a verified active budget, and a supplier with mapped products.
1. IA opens PR create → picks Supplier A → product list filters to A's products. Location dropdowns show warehouses only.
2. Add two lines to two different warehouses; per-warehouse budget cards appear; submit blocked if a warehouse group is unverified/over budget.
3. Submit; admin approves → each warehouse's budget shows its share committed (Budget Management ledger).
4. Convert → two POs (one per warehouse); each goes through auditor verify → approve → receive at its own warehouse.
5. Complete each PO → each warehouse's budget shows its own spend; archive an un-converted PR returns each warehouse's share.
6. Try a showroom on a PR line and on a direct PO → both rejected with the warehouse-only message.

- [ ] **Step 4: Commit any test fixes, then finish**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory
git add -A
git commit -m "test(procurement): align pre-existing PR/PO suites with the warehouse-scoped shape

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

Then use superpowers:finishing-a-development-branch. The per-warehouse **PO Recommendation engine** is Plan B (separate spec section) — brainstorm/plan it next.
