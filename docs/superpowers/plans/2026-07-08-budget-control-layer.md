# Budget Control Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the passive budget-allocation module into an enforced financial control layer: immutable `budget_transactions` ledger, `BudgetService` as sole mutator, hard-block enforcement on PRs, leak-free lifecycle, utilization drill-down, threshold alerts, PO-Recommendation tie-in.

**Architecture:** New append-only `budget_transactions` table records every peso movement (`commit`/`release`/`spend`/`adjustment`); each row affects exactly one cached column on `budget_allocations` (commit → committed+, release → committed−, spend → spent+; adjustment is informational). `App\Services\BudgetService` is the only code that writes those columns, with `lockForUpdate` concurrency safety. Controllers (PR, PO, Receiving, Restock, BudgetAllocation) delegate to it. Frontend surfaces enforcement + ledger.

**Tech Stack:** Laravel 10 API (back-end/), React CRA (frontend/), MariaDB via XAMPP. Tests: PHPUnit feature tests with `DatabaseTransactions` against `airkingV5_test`; Jest for frontend utils.

**Spec:** `docs/superpowers/specs/2026-07-08-budget-control-layer-design.md`

**Repo conventions that matter here:**
- Schema is SQL-dump based — NEVER run blanket `php artisan migrate`. Apply single migrations with `--path=`.
- Backend commands run from `back-end/`, frontend from `frontend/`.
- Status IDs: PR 13=Pending, 14=Approved, 15=Rejected, 16=Partially Converted. PO 7=Pending, 11=Completed, 12=Partially Received.
- API responses use the `ApiResponse` trait (`$this->success/error/validationError/notFound/paginated`).
- Peso formatting in messages: `'₱' . number_format($x, 2)`.

**Key semantic decisions (do not deviate):**
- Ledger `release` rows serve two purposes distinguished by note: returning funds to available (PR archived / estimate surplus) AND commitment consumed by actual spend. In both cases `committed` decreases. `outstanding(pr) = Σcommit − Σrelease` for that `pr_id`.
- PO cancellation does NOT release commitment (PR is still approved and re-convertible). Release happens on PR archive or on spend.
- `adjustment` rows are informational (they document `total_budget` changes); the rebuild command only recomputes `committed`/`spent`.
- Legacy PRs (created before enforcement, `budget_id` NULL): approval is refused with a 422 telling the admin to reject and recreate them. Archiving them works (release is a no-op without budget).

---

## Task 1: `budget_transactions` schema + `BudgetTransaction` model

**Files:**
- Create: `back-end/database/migrations/2026_07_08_100000_create_budget_transactions_table.php`
- Create: `back-end/app/Models/BudgetTransaction.php`
- Modify: `back-end/app/Models/BudgetAllocation.php` (add `transactions()` relation)

- [ ] **Step 1: Confirm FK column types**

Run (adjust DB name to `DB_DATABASE` in `back-end/.env` if not `airkingV5`):

```powershell
C:\XAMPP1\mysql\bin\mysql.exe -u root airkingV5 -e "SHOW COLUMNS FROM budget_allocations LIKE 'budget_id'; SHOW COLUMNS FROM purchase_requests LIKE 'pr_id'; SHOW COLUMNS FROM purchase_orders LIKE 'po_id'; SHOW COLUMNS FROM users LIKE 'user_id';"
```

Expected: each PK is `bigint(20) unsigned` (then the migration below is correct as written). If any is plain `int unsigned`, change the matching `unsignedBigInteger` to `unsignedInteger` in the migration.

- [ ] **Step 2: Write the migration**

`back-end/database/migrations/2026_07_08_100000_create_budget_transactions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_transactions', function (Blueprint $table) {
            $table->bigIncrements('txn_id');
            $table->unsignedBigInteger('budget_id');
            $table->string('txn_type', 20); // commit | release | spend | adjustment
            $table->decimal('amount', 12, 2);
            $table->unsignedBigInteger('pr_id')->nullable();
            $table->unsignedBigInteger('po_id')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->string('notes')->nullable();
            $table->timestamp('created_at')->nullable(); // immutable rows — no updated_at

            $table->foreign('budget_id')->references('budget_id')->on('budget_allocations')->cascadeOnDelete();
            $table->foreign('pr_id')->references('pr_id')->on('purchase_requests')->nullOnDelete();
            $table->foreign('po_id')->references('po_id')->on('purchase_orders')->nullOnDelete();
            $table->foreign('performed_by')->references('user_id')->on('users')->nullOnDelete();
            $table->index(['budget_id', 'txn_type']);
            $table->index(['pr_id', 'txn_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_transactions');
    }
};
```

- [ ] **Step 3: Apply to dev DB and test DB**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\back-end
php artisan config:clear
php artisan migrate --path=database/migrations/2026_07_08_100000_create_budget_transactions_table.php
$env:DB_DATABASE = 'airkingV5_test'
php artisan migrate --path=database/migrations/2026_07_08_100000_create_budget_transactions_table.php
Remove-Item Env:DB_DATABASE
php artisan config:clear
```

Expected: `DONE` for each run. Verify: `C:\XAMPP1\mysql\bin\mysql.exe -u root airkingV5_test -e "DESCRIBE budget_transactions;"` lists the columns.

- [ ] **Step 4: Write the model**

`back-end/app/Models/BudgetTransaction.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetTransaction extends Model
{
    protected $table = 'budget_transactions';
    protected $primaryKey = 'txn_id';

    /** Ledger rows are immutable — Eloquent manages created_at only. */
    const UPDATED_AT = null;

    public const TYPE_COMMIT     = 'commit';
    public const TYPE_RELEASE    = 'release';
    public const TYPE_SPEND      = 'spend';
    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'budget_id',
        'txn_type',
        'amount',
        'pr_id',
        'po_id',
        'performed_by',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(BudgetAllocation::class, 'budget_id', 'budget_id');
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'pr_id', 'pr_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by', 'user_id');
    }
}
```

- [ ] **Step 5: Add relation to BudgetAllocation**

In `back-end/app/Models/BudgetAllocation.php`, add `use Illuminate\Database\Eloquent\Relations\HasMany;` to the imports, then add after the `creator()` method (before the closing brace):

```php
    public function transactions(): HasMany
    {
        return $this->hasMany(BudgetTransaction::class, 'budget_id', 'budget_id')
            ->orderByDesc('txn_id');
    }
```

- [ ] **Step 6: Smoke-check the model**

```powershell
php artisan tinker --execute="echo App\Models\BudgetTransaction::count();"
```

Expected: `0` (no error).

- [ ] **Step 7: Commit**

```powershell
git add database/migrations/2026_07_08_100000_create_budget_transactions_table.php app/Models/BudgetTransaction.php app/Models/BudgetAllocation.php
git commit -m "feat(budget): add budget_transactions ledger table and model"
```

---

## Task 2: Test base class + `InsufficientBudgetException` + `BudgetService::commit`

**Files:**
- Create: `back-end/tests/Feature/Budget/BudgetTestCase.php`
- Create: `back-end/tests/Feature/Budget/BudgetServiceTest.php`
- Create: `back-end/app/Exceptions/InsufficientBudgetException.php`
- Create: `back-end/app/Services/BudgetService.php`

- [ ] **Step 1: Write the shared test base**

`back-end/tests/Feature/Budget/BudgetTestCase.php` (helpers mirror `tests/Feature/Restock/RestockEndpointsTest.php` conventions):

```php
<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class BudgetTestCase extends TestCase
{
    use DatabaseTransactions;

    protected int $locationId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'admin'               => 'System Administrator',
            'inventory_analyst'   => 'Inventory Analyst',
            'branch_manager'      => 'Branch Manager',
            'warehouse_personnel' => 'Warehouse Personnel',
            'auditor'             => 'Auditor',
        ] as $name => $desc) {
            Role::firstOrCreate(['role_name' => $name], ['description' => $desc]);
        }
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionsSeeder::class);

        $this->locationId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Budget Test Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');
    }

    protected function makeUser(string $roleSlug): User
    {
        $role = Role::where('role_name', $roleSlug)->firstOrFail();
        $statusId = StatusLookup::query()->value('status_id')
            ?? StatusLookup::forceCreate(['status_name' => 'Active', 'status_category' => 'user'])->status_id;

        return User::forceCreate([
            'first_name'    => 'Test',
            'last_name'     => ucfirst($roleSlug),
            'email'         => $roleSlug . '_' . uniqid() . '@test.local',
            'username'      => $roleSlug . '_' . uniqid(),
            'password_hash' => Hash::make('password'),
            'role_id'       => $role->role_id,
            'status_id'     => $statusId,
        ]);
    }

    protected function makeBudget(float $total, array $overrides = []): BudgetAllocation
    {
        return BudgetAllocation::create(array_merge([
            'location_id'  => $this->locationId,
            'period_label' => 'TEST-' . uniqid(),
            'period_start' => today()->subDays(10)->toDateString(),
            'period_end'   => today()->addDays(10)->toDateString(),
            'total_budget' => $total,
            'committed'    => 0,
            'spent'        => 0,
        ], $overrides));
    }

    protected function makePr(BudgetAllocation $budget, float $estimatedTotal, int $statusId = 13, array $overrides = []): PurchaseRequest
    {
        return PurchaseRequest::create(array_merge([
            'location_id'     => $budget->location_id,
            'budget_id'       => $budget->budget_id,
            'pr_number'       => 'PR-TEST-' . strtoupper(Str::random(6)),
            'request_date'    => today(),
            'needed_by_date'  => today()->addDays(7),
            'estimated_total' => $estimatedTotal,
            'status_id'       => $statusId,
            'requested_by'    => $this->makeUser('inventory_analyst')->user_id,
        ], $overrides));
    }

    protected function makePo(PurchaseRequest $pr, float $totalAmount, int $statusId = 7): PurchaseOrder
    {
        $supplierId = (int) DB::table('suppliers')->insertGetId([
            'supplier_name' => 'Budget Test Supplier ' . uniqid(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'supplier_id');

        return PurchaseOrder::create([
            'pr_id'        => $pr->pr_id,
            'supplier_id'  => $supplierId,
            'location_id'  => $pr->location_id,
            'po_number'    => 'PO-TEST-' . strtoupper(Str::random(6)),
            'order_date'   => today(),
            'total_amount' => $totalAmount,
            'grand_total'  => $totalAmount,
            'status_id'    => $statusId,
            'created_by'   => $pr->requested_by,
        ]);
    }
}
```

Note: if `suppliers` has other NOT NULL columns without defaults, the first test run will fail with a clear SQL error — add those columns to the insert with dummy values.

- [ ] **Step 2: Write failing tests for commit**

`back-end/tests/Feature/Budget/BudgetServiceTest.php`:

```php
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
}
```

- [ ] **Step 3: Run tests to verify they fail**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\back-end
php artisan test --filter=BudgetServiceTest
```

Expected: FAIL — `Class "App\Services\BudgetService" not found`.

- [ ] **Step 4: Write the exception**

`back-end/app/Exceptions/InsufficientBudgetException.php`:

```php
<?php

namespace App\Exceptions;

class InsufficientBudgetException extends \RuntimeException
{
    public function __construct(string $periodLabel, float $requested, float $available)
    {
        parent::__construct(sprintf(
            'Estimated total ₱%s exceeds available budget ₱%s for %s',
            number_format($requested, 2),
            number_format(max($available, 0), 2),
            $periodLabel
        ));
    }
}
```

- [ ] **Step 5: Write the service (first slice)**

`back-end/app/Services/BudgetService.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\InsufficientBudgetException;
use App\Models\BudgetAllocation;
use App\Models\BudgetTransaction;
use App\Models\PurchaseRequest;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY code allowed to mutate budget_allocations.committed / spent.
 * Every mutation is journaled as an immutable budget_transactions row:
 *   commit     → committed += amount   (PR approved)
 *   release    → committed -= amount   (PR archived / commitment consumed by spend / surplus)
 *   spend      → spent     += amount   (PO completed)
 *   adjustment → informational          (total_budget changed by Admin)
 */
class BudgetService
{
    /** Read-only availability check (no lock) — used at PR submission time. */
    public function hasAvailable(BudgetAllocation $budget, float $amount): bool
    {
        return $amount <= (float) $budget->total_budget
            - (float) $budget->committed
            - (float) $budget->spent;
    }

    /** Most recently started budget whose period covers today, for a location. */
    public function resolveActiveBudget(int $locationId): ?BudgetAllocation
    {
        $today = today();

        return BudgetAllocation::where('location_id', $locationId)
            ->where('period_start', '<=', $today)
            ->where('period_end', '>=', $today)
            ->orderByDesc('period_start')
            ->first();
    }

    /**
     * Commit the PR's estimated total against its budget (PR approval).
     * Locks the budget row; throws InsufficientBudgetException when it cannot cover.
     */
    public function commit(PurchaseRequest $pr, ?int $userId = null): void
    {
        DB::transaction(function () use ($pr, $userId) {
            $budget = BudgetAllocation::whereKey($pr->budget_id)->lockForUpdate()->firstOrFail();
            $amount = (float) $pr->estimated_total;
            $available = (float) $budget->total_budget
                - (float) $budget->committed
                - (float) $budget->spent;

            if ($amount > $available) {
                throw new InsufficientBudgetException($budget->period_label, $amount, $available);
            }

            $this->txn($budget, BudgetTransaction::TYPE_COMMIT, $amount, $pr->pr_id, null, $userId,
                'PR ' . $pr->pr_number . ' approved');
            $budget->committed = round((float) $budget->committed + $amount, 2);
            $budget->save();
        });
    }

    private function txn(
        BudgetAllocation $budget,
        string $type,
        float $amount,
        ?int $prId,
        ?int $poId,
        ?int $userId,
        ?string $note
    ): void {
        BudgetTransaction::create([
            'budget_id'    => $budget->budget_id,
            'txn_type'     => $type,
            'amount'       => round($amount, 2),
            'pr_id'        => $prId,
            'po_id'        => $poId,
            'performed_by' => $userId,
            'notes'        => $note,
        ]);
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

```powershell
php artisan test --filter=BudgetServiceTest
```

Expected: PASS (4 tests).

- [ ] **Step 7: Commit**

```powershell
git add tests/Feature/Budget/ app/Exceptions/InsufficientBudgetException.php app/Services/BudgetService.php
git commit -m "feat(budget): BudgetService commit with locked availability check + ledger"
```

---

## Task 3: `BudgetService::releaseOutstanding`

**Files:**
- Modify: `back-end/app/Services/BudgetService.php`
- Test: `back-end/tests/Feature/Budget/BudgetServiceTest.php`

- [ ] **Step 1: Write failing tests**

Append to `BudgetServiceTest.php`:

```php
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
```

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=BudgetServiceTest
```

Expected: FAIL — `Call to undefined method App\Services\BudgetService::releaseOutstanding()`.

- [ ] **Step 3: Implement**

Add to `BudgetService` (after `commit()`):

```php
    /**
     * Release whatever commitment is still outstanding for this PR
     * (Σ its commit rows − Σ its release rows). Returns the released amount.
     * Safe to call repeatedly and on PRs that never committed anything.
     */
    public function releaseOutstanding(PurchaseRequest $pr, string $note, ?int $userId = null): float
    {
        if (!$pr->budget_id) {
            return 0.0;
        }

        return DB::transaction(function () use ($pr, $note, $userId) {
            $budget = BudgetAllocation::whereKey($pr->budget_id)->lockForUpdate()->first();
            if (!$budget) {
                return 0.0;
            }

            $outstanding = $this->outstanding($budget->budget_id, $pr->pr_id);
            if ($outstanding <= 0) {
                return 0.0;
            }

            $this->txn($budget, BudgetTransaction::TYPE_RELEASE, $outstanding, $pr->pr_id, null, $userId, $note);
            $budget->committed = round(max(0, (float) $budget->committed - $outstanding), 2);
            $budget->save();

            return $outstanding;
        });
    }

    /** Net commitment still held by a PR: Σcommit − Σrelease (both keyed to pr_id). */
    private function outstanding(int $budgetId, int $prId): float
    {
        $commits = (float) BudgetTransaction::where('budget_id', $budgetId)
            ->where('pr_id', $prId)->where('txn_type', BudgetTransaction::TYPE_COMMIT)->sum('amount');
        $releases = (float) BudgetTransaction::where('budget_id', $budgetId)
            ->where('pr_id', $prId)->where('txn_type', BudgetTransaction::TYPE_RELEASE)->sum('amount');

        return round($commits - $releases, 2);
    }
```

- [ ] **Step 4: Run to verify pass**

```powershell
php artisan test --filter=BudgetServiceTest
```

Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```powershell
git add app/Services/BudgetService.php tests/Feature/Budget/BudgetServiceTest.php
git commit -m "feat(budget): releaseOutstanding — exact, idempotent commitment release"
```

---

## Task 4: `BudgetService::spend`

**Files:**
- Modify: `back-end/app/Services/BudgetService.php`
- Test: `back-end/tests/Feature/Budget/BudgetServiceTest.php`

- [ ] **Step 1: Write failing tests**

Append to `BudgetServiceTest.php`:

```php
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
```

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=BudgetServiceTest
```

Expected: FAIL — `Call to undefined method App\Services\BudgetService::spend()`.

- [ ] **Step 3: Implement**

Add `use App\Models\PurchaseOrder;` to the imports of `BudgetService.php`, then add after `releaseOutstanding()`:

```php
    /**
     * Record actual spend for a completed PO and consume the PR's commitment.
     * Idempotent per PO (a second call is a no-op). For fully-converted PRs
     * (status 14, converted_to_po_id = this PO) any estimate surplus is
     * auto-returned to available.
     */
    public function spend(PurchaseOrder $po, ?int $userId = null): void
    {
        $pr = $po->purchaseRequest;
        if (!$pr || !$pr->budget_id) {
            return;
        }

        DB::transaction(function () use ($po, $pr, $userId) {
            $budget = BudgetAllocation::whereKey($pr->budget_id)->lockForUpdate()->first();
            if (!$budget) {
                return;
            }
            if (BudgetTransaction::where('po_id', $po->po_id)
                ->where('txn_type', BudgetTransaction::TYPE_SPEND)->exists()) {
                return; // already recorded (e.g. receiving flow + manual status update)
            }

            $amount = (float) $po->total_amount;
            $this->txn($budget, BudgetTransaction::TYPE_SPEND, $amount, $pr->pr_id, $po->po_id, $userId,
                'PO ' . $po->po_number . ' completed');
            $budget->spent = round((float) $budget->spent + $amount, 2);

            $outstanding = $this->outstanding($budget->budget_id, $pr->pr_id);
            $released = 0.0;

            $consume = round(min($outstanding, $amount), 2);
            if ($consume > 0) {
                $this->txn($budget, BudgetTransaction::TYPE_RELEASE, $consume, $pr->pr_id, $po->po_id, $userId,
                    'Commitment consumed by PO ' . $po->po_number);
                $released += $consume;
            }

            // Fully-converted PR: return the estimate surplus to available.
            if ((int) $pr->status_id === 14 && (int) $pr->converted_to_po_id === (int) $po->po_id) {
                $surplus = round($outstanding - $consume, 2);
                if ($surplus > 0) {
                    $this->txn($budget, BudgetTransaction::TYPE_RELEASE, $surplus, $pr->pr_id, $po->po_id, $userId,
                        'Estimate surplus returned');
                    $released += $surplus;
                }
            }

            $budget->committed = round(max(0, (float) $budget->committed - $released), 2);
            $budget->save();
        });
    }
```

- [ ] **Step 4: Run to verify pass**

```powershell
php artisan test --filter=BudgetServiceTest
```

Expected: PASS (12 tests).

- [ ] **Step 5: Commit**

```powershell
git add app/Services/BudgetService.php tests/Feature/Budget/BudgetServiceTest.php
git commit -m "feat(budget): spend — idempotent actual-spend recording with surplus return"
```

---

## Task 5: `BudgetChanged` event + broadcaster method + threshold alerts

**Files:**
- Create: `back-end/app/Events/BudgetChanged.php`
- Modify: `back-end/app/Support/RealtimeBroadcaster.php`
- Modify: `back-end/app/Services/BudgetService.php`
- Test: `back-end/tests/Feature/Budget/BudgetServiceTest.php`

- [ ] **Step 1: Write failing tests**

Append to `BudgetServiceTest.php` (add `use App\Events\BudgetChanged;` and `use Illuminate\Support\Facades\Event;` to the file imports):

```php
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
```

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=BudgetServiceTest
```

Expected: FAIL — `Class "App\Events\BudgetChanged" not found`.

- [ ] **Step 3: Create the event**

`back-end/app/Events/BudgetChanged.php` (mirrors `ApprovalQueueChanged`):

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class BudgetChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $locationId,
        public string $type = 'changed',
        public array $extra = [],
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('org'),
            new PrivateChannel('location.' . $this->locationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'budget.changed';
    }

    public function broadcastWith(): array
    {
        return array_merge(
            ['type' => $this->type, 'location_id' => $this->locationId, 'at' => now()->toIso8601String()],
            $this->extra
        );
    }
}
```

- [ ] **Step 4: Add broadcaster method**

In `back-end/app/Support/RealtimeBroadcaster.php`, add `use App\Events\BudgetChanged;` to the imports and this method after `transferChanged()`:

```php
    public static function budgetChanged(?int $locationId, string $type = 'changed', array $extra = []): void
    {
        self::safe(fn () => $locationId !== null
            ? broadcast(new BudgetChanged($locationId, $type, $extra))
            : null);
    }
```

- [ ] **Step 5: Add threshold detection to the service**

In `BudgetService.php`, add `use App\Support\RealtimeBroadcaster;` to imports and this constant at the top of the class:

```php
    /** Utilization crossings that fire a realtime alert. */
    public const ALERT_THRESHOLDS = [0.80, 1.00];
```

Add these private methods at the bottom of the class:

```php
    /** (committed + spent) / total — 1.0 for zero/negative totals. */
    private function utilization(BudgetAllocation $budget): float
    {
        $total = (float) $budget->total_budget;
        if ($total <= 0) {
            return 1.0;
        }

        return ((float) $budget->committed + (float) $budget->spent) / $total;
    }

    /** Fire one realtime alert per threshold crossed by this mutation. */
    private function maybeAlert(BudgetAllocation $budget, float $utilBefore): void
    {
        $after = $this->utilization($budget);
        foreach (self::ALERT_THRESHOLDS as $threshold) {
            if ($utilBefore < $threshold && $after >= $threshold) {
                RealtimeBroadcaster::budgetChanged((int) $budget->location_id, 'budget_threshold', [
                    'budget_id'       => $budget->budget_id,
                    'period_label'    => $budget->period_label,
                    'utilization_pct' => (int) round($after * 100),
                ]);
            }
        }
    }
```

Then wire both mutators:

In `commit()`, capture `$utilBefore = $this->utilization($budget);` immediately after the `firstOrFail()` lock line, and add `$this->maybeAlert($budget, $utilBefore);` as the last line inside the transaction closure (after `$budget->save();`).

In `spend()`, capture `$utilBefore = $this->utilization($budget);` right after the idempotency check, and add `$this->maybeAlert($budget, $utilBefore);` as the last line inside the transaction closure (after the final `$budget->save();`).

- [ ] **Step 6: Run to verify pass**

```powershell
php artisan test --filter=BudgetServiceTest
```

Expected: PASS (15 tests).

- [ ] **Step 7: Commit**

```powershell
git add app/Events/BudgetChanged.php app/Support/RealtimeBroadcaster.php app/Services/BudgetService.php tests/Feature/Budget/BudgetServiceTest.php
git commit -m "feat(budget): realtime threshold alerts at 80%/100% utilization crossings"
```

---

## Task 6: `BudgetService::adjustTotal`

**Files:**
- Modify: `back-end/app/Services/BudgetService.php`
- Test: `back-end/tests/Feature/Budget/BudgetServiceTest.php`

- [ ] **Step 1: Write failing tests**

Append to `BudgetServiceTest.php`:

```php
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
```

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=BudgetServiceTest
```

Expected: FAIL — `Call to undefined method ... adjustTotal()`.

- [ ] **Step 3: Implement**

Add to `BudgetService` after `spend()`:

```php
    /**
     * Change total_budget with an audit trail. The total can never drop below
     * committed + spent (funds already promised or gone).
     * @throws \InvalidArgumentException
     */
    public function adjustTotal(BudgetAllocation $budget, float $newTotal, ?int $userId = null, ?string $note = null): void
    {
        $floor = (float) $budget->committed + (float) $budget->spent;
        if ($newTotal < $floor) {
            throw new \InvalidArgumentException(sprintf(
                'Total budget cannot be lower than committed + spent (₱%s)',
                number_format($floor, 2)
            ));
        }

        DB::transaction(function () use ($budget, $newTotal, $userId, $note) {
            $old = (float) $budget->total_budget;
            if (abs($newTotal - $old) < 0.005) {
                return;
            }

            $this->txn($budget, BudgetTransaction::TYPE_ADJUSTMENT, abs($newTotal - $old), null, null, $userId,
                $note ?? sprintf(
                    'Total budget %s from ₱%s to ₱%s',
                    $newTotal > $old ? 'increased' : 'decreased',
                    number_format($old, 2),
                    number_format($newTotal, 2)
                ));
            $budget->total_budget = round($newTotal, 2);
            $budget->save();
        });
    }
```

- [ ] **Step 4: Run to verify pass**

```powershell
php artisan test --filter=BudgetServiceTest
```

Expected: PASS (18 tests).

- [ ] **Step 5: Commit**

```powershell
git add app/Services/BudgetService.php tests/Feature/Budget/BudgetServiceTest.php
git commit -m "feat(budget): adjustTotal with floor guard and adjustment journal"
```

---

## Task 7: PR enforcement — required budget + hard block at store/approve

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseRequestController.php`
- Test: `back-end/tests/Feature/Budget/BudgetEnforcementTest.php` (create)

- [ ] **Step 1: Write failing endpoint tests**

`back-end/tests/Feature/Budget/BudgetEnforcementTest.php`:

```php
<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetTransaction;
use App\Services\BudgetService;
use Illuminate\Support\Facades\DB;

class BudgetEnforcementTest extends BudgetTestCase
{
    private function makeProduct(): int
    {
        return (int) DB::table('products')->insertGetId([
            'product_code' => 'TST-' . uniqid(),
            'product_name' => 'Budget Enforcement Product',
            'product_type' => 'installation_material',
            'unit_price'   => 100,
            'cost_price'   => 80,
            'status_id'    => 3,
            'created_at'   => now(),
            'updated_at'   => now(),
        ], 'product_id');
    }

    private function prPayload(array $overrides = []): array
    {
        return array_merge([
            'location_id'    => $this->locationId,
            'needed_by_date' => today()->addDays(7)->toDateString(),
            'details'        => [[
                'product_id'      => $this->makeProduct(),
                'quantity'        => 10,
                'estimated_price' => 500, // estimated_total = 5000
            ]],
        ], $overrides);
    }

    public function test_store_rejects_pr_without_budget(): void
    {
        $ia = $this->makeUser('inventory_analyst');

        $response = $this->actingAs($ia, 'sanctum')
            ->postJson('/api/purchase-requests', $this->prPayload());

        $response->assertStatus(422);
        // ApiResponse::validationError shape — assert the field is named, not the exact envelope.
        $this->assertArrayHasKey('budget_id', $response->json('errors') ?? $response->json('data') ?? []);
    }

    public function test_store_rejects_budget_from_other_location(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $otherLocation = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-BR-' . uniqid(),
            'location_name' => 'Other Branch',
            'location_type' => 'branch',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');
        $budget = $this->makeBudget(10000, ['location_id' => $otherLocation]);

        $this->actingAs($ia, 'sanctum')
            ->postJson('/api/purchase-requests', $this->prPayload(['budget_id' => $budget->budget_id]))
            ->assertStatus(422);
    }

    public function test_store_rejects_inactive_budget_period(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $budget = $this->makeBudget(10000, [
            'period_start' => today()->subDays(60)->toDateString(),
            'period_end'   => today()->subDays(30)->toDateString(),
        ]);

        $this->actingAs($ia, 'sanctum')
            ->postJson('/api/purchase-requests', $this->prPayload(['budget_id' => $budget->budget_id]))
            ->assertStatus(422);
    }

    public function test_store_rejects_over_budget_total(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $budget = $this->makeBudget(4000); // payload estimates 5000

        $response = $this->actingAs($ia, 'sanctum')
            ->postJson('/api/purchase-requests', $this->prPayload(['budget_id' => $budget->budget_id]));

        $response->assertStatus(422);
        $this->assertStringContainsString('exceeds available budget', $response->json('message'));
    }

    public function test_store_accepts_pr_within_budget(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $budget = $this->makeBudget(10000);

        $this->actingAs($ia, 'sanctum')
            ->postJson('/api/purchase-requests', $this->prPayload(['budget_id' => $budget->budget_id]))
            ->assertStatus(201);
    }

    public function test_approve_commits_via_ledger(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 5000, 13);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/purchase-requests/{$pr->pr_id}/approve")
            ->assertSuccessful();

        $budget->refresh();
        $this->assertSame('5000.00', (string) $budget->committed);
        $this->assertSame(1, BudgetTransaction::where('pr_id', $pr->pr_id)->where('txn_type', 'commit')->count());
    }

    public function test_approve_blocks_when_funds_consumed_since_submission(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 6000, 13);

        // Another PR ate the funds between submission and approval.
        $rival = $this->makePr($budget, 7000, 14);
        app(BudgetService::class)->commit($rival);

        $response = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/purchase-requests/{$pr->pr_id}/approve");

        $response->assertStatus(422);
        $this->assertStringContainsString('exceeds available budget', $response->json('message'));
        $this->assertSame(13, $pr->fresh()->status_id); // still pending — approval rolled back
    }

    public function test_approve_blocks_legacy_pr_without_budget(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 5000, 13, ['budget_id' => null]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/purchase-requests/{$pr->pr_id}/approve")
            ->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=BudgetEnforcementTest
```

Expected: FAIL — `test_store_rejects_pr_without_budget` and others fail (store currently accepts null budget; approve uses inline increment).

- [ ] **Step 3: Implement store() enforcement**

In `back-end/app/Http/Controllers/Api/PurchaseRequestController.php`:

Add imports:

```php
use App\Exceptions\InsufficientBudgetException;
use App\Services\BudgetService;
```

Add a constructor right after `use ApiResponse;`:

```php
    public function __construct(private BudgetService $budgetService)
    {
    }
```

In `store()`, change the validation rule for `budget_id` from:

```php
                'budget_id'                 => 'nullable|exists:budget_allocations,budget_id',
```

to:

```php
                'budget_id'                 => 'required|exists:budget_allocations,budget_id',
```

Then insert the gate checks between the `catch (ValidationException ...)` block and the `try { $pr = DB::transaction(...)` block:

```php
        $budget = BudgetAllocation::find($validated['budget_id']);
        if ((int) $budget->location_id !== (int) $validated['location_id']) {
            return $this->error('The selected budget belongs to a different location', 422);
        }
        $today = today();
        if ($budget->period_start->gt($today) || $budget->period_end->lt($today)) {
            return $this->error("The budget period ({$budget->period_label}) is not active today", 422);
        }
        $estimatedTotal = collect($validated['details'])
            ->sum(fn ($d) => $d['quantity'] * $d['estimated_price']);
        if (!$this->budgetService->hasAvailable($budget, (float) $estimatedTotal)) {
            return $this->error(sprintf(
                'Estimated total ₱%s exceeds available budget ₱%s for %s',
                number_format((float) $estimatedTotal, 2),
                number_format(max((float) $budget->available, 0), 2),
                $budget->period_label
            ), 422);
        }
```

Inside the existing transaction closure, the line computing `$estimatedTotal` already exists — remove the duplicate inside the closure and pass the precomputed one in (change the closure signature to `function () use ($validated, $request, $estimatedTotal)` and delete these lines inside it):

```php
                $estimatedTotal = collect($validated['details'])
                    ->sum(fn($d) => $d['quantity'] * $d['estimated_price']);
```

Also change `'budget_id' => $validated['budget_id'] ?? null,` to `'budget_id' => $validated['budget_id'],`.

- [ ] **Step 4: Implement approve() enforcement**

Replace the `approve()` method body's transaction section. Current code:

```php
        try {
            DB::transaction(function () use ($pr, $request) {
                $pr->update([
                    'status_id'    => 14,
                    'approved_by'  => $request->user()->user_id,
                    'approval_date'=> today(),
                ]);

                if ($pr->budget_id) {
                    BudgetAllocation::where('budget_id', $pr->budget_id)
                        ->increment('committed', $pr->estimated_total);
                }
            });
```

New code (note the added pre-check before the try, and the new catch):

```php
        if (!$pr->budget_id) {
            return $this->error(
                'This purchase request has no budget attached (created before budget enforcement). Reject it and ask the requester to submit a new one.',
                422
            );
        }

        try {
            DB::transaction(function () use ($pr, $request) {
                $pr->update([
                    'status_id'    => 14,
                    'approved_by'  => $request->user()->user_id,
                    'approval_date'=> today(),
                ]);

                $this->budgetService->commit($pr, $request->user()->user_id);
            });
        } catch (InsufficientBudgetException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('Approval failed: ' . $e->getMessage(), 500);
        }

        RealtimeBroadcaster::approvalQueueChanged((int) $pr->location_id, 'pr_changed');

        $pr->load(self::WITH_RELATIONS);
        return $this->success($pr, 'Purchase request approved');
```

(Delete the old trailing `catch (\Throwable $e) { return $this->error('Approval failed: ...' }` block and the duplicated success lines so the method reads exactly as above after the guards.)

- [ ] **Step 5: Run to verify pass**

```powershell
php artisan test --filter=BudgetEnforcementTest
php artisan test --filter=BudgetServiceTest
```

Expected: PASS (8 + 18 tests).

- [ ] **Step 6: Commit**

```powershell
git add app/Http/Controllers/Api/PurchaseRequestController.php tests/Feature/Budget/BudgetEnforcementTest.php
git commit -m "feat(budget): hard-block enforcement — budget required, checked at PR submit and approve"
```

---

## Task 8: PR archive (destroy) with commitment release

The frontend already calls `DELETE /purchase-requests/{id}` (`purchaseRequestsAPI.delete`) but **no such route or method exists today** — archiving PRs is silently broken. This task adds both, with budget release.

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseRequestController.php`
- Modify: `back-end/routes/api.php` (line ~518, the `purchase_request_creation,archive` group)
- Test: `back-end/tests/Feature/Budget/BudgetLifecycleTest.php` (create)

- [ ] **Step 1: Write failing tests**

`back-end/tests/Feature/Budget/BudgetLifecycleTest.php`:

```php
<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetTransaction;
use App\Services\BudgetService;

class BudgetLifecycleTest extends BudgetTestCase
{
    public function test_archiving_approved_pr_releases_commitment(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        app(BudgetService::class)->commit($pr);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/purchase-requests/{$pr->pr_id}")
            ->assertSuccessful();

        $budget->refresh();
        $this->assertSame('0.00', (string) $budget->committed);
        $this->assertSoftDeleted('purchase_requests', ['pr_id' => $pr->pr_id]);

        $release = BudgetTransaction::where('pr_id', $pr->pr_id)->where('txn_type', 'release')->first();
        $this->assertNotNull($release);
        $this->assertSame('PR archived', $release->notes);
    }

    public function test_archiving_pending_pr_releases_nothing(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 13);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/purchase-requests/{$pr->pr_id}")
            ->assertSuccessful();

        $this->assertSame(0, BudgetTransaction::where('pr_id', $pr->pr_id)->count());
    }

    public function test_archiving_missing_pr_returns_404(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/purchase-requests/999999')
            ->assertStatus(404);
    }
}
```

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=BudgetLifecycleTest
```

Expected: FAIL — 404/405 (route does not exist).

- [ ] **Step 3: Add the destroy method**

In `PurchaseRequestController.php`, add before `archived()` (imports for `DB` and `RealtimeBroadcaster` already exist):

```php
    /**
     * Archive (soft-delete) a PR. Approved/partially-converted PRs release
     * their outstanding budget commitment back to available.
     */
    public function destroy(Request $request, $id)
    {
        $pr = PurchaseRequest::find($id);
        if (!$pr) {
            return $this->notFound('Purchase request not found');
        }

        DB::transaction(function () use ($pr, $request) {
            $this->budgetService->releaseOutstanding($pr, 'PR archived', $request->user()?->user_id);
            $pr->delete();
        });

        RealtimeBroadcaster::approvalQueueChanged((int) $pr->location_id, 'pr_changed');

        return $this->success(null, 'Purchase request archived');
    }
```

(`releaseOutstanding` is already a safe no-op for pending PRs and PRs without budgets — no status check needed.)

- [ ] **Step 4: Register the route**

In `back-end/routes/api.php`, inside the existing archive group (currently lines 518–521), add the DELETE route first:

```php
    Route::middleware('permission:purchase_request_creation,archive')->group(function () {
        Route::delete('/purchase-requests/{id}', [PurchaseRequestController::class, 'destroy']);
        Route::post('/purchase-requests/{id}/restore', [PurchaseRequestController::class, 'restore']);
        Route::delete('/purchase-requests/{id}/force', [PurchaseRequestController::class, 'forceDestroy']);
    });
```

- [ ] **Step 5: Run to verify pass**

```powershell
php artisan test --filter=BudgetLifecycleTest
```

Expected: PASS (3 tests). If the admin user gets 403, the `purchase_request_creation.archive` permission is not granted to admin by `RolePermissionsSeeder` — check the seeder's matrix and use a role that has it (the tests must use a role that genuinely holds the permission; do not weaken the route).

- [ ] **Step 6: Commit**

```powershell
git add app/Http/Controllers/Api/PurchaseRequestController.php routes/api.php tests/Feature/Budget/BudgetLifecycleTest.php
git commit -m "feat(budget): PR archive endpoint releases outstanding commitment (fixes missing DELETE route)"
```

---

## Task 9: Wire `spend()` into Receiving + PO completion

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/ReceivingController.php` (lines ~139–147)
- Modify: `back-end/app/Http/Controllers/Api/PurchaseOrderController.php` (lines ~234–242)
- Test: `back-end/tests/Feature/Budget/BudgetLifecycleTest.php`

- [ ] **Step 1: Write failing tests**

Append to `BudgetLifecycleTest.php`:

```php
    public function test_po_completion_via_update_records_spend_and_consumes_commitment(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        app(BudgetService::class)->commit($pr);
        $po = $this->makePo($pr, 3800, 7);
        $pr->update(['converted_to_po_id' => $po->po_id]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 11])
            ->assertSuccessful();

        $budget->refresh();
        $this->assertSame('3800.00', (string) $budget->spent);
        $this->assertSame('0.00', (string) $budget->committed); // 3800 consumed + 200 surplus returned
    }

    public function test_repeated_completion_update_does_not_double_spend(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        app(BudgetService::class)->commit($pr);
        $po = $this->makePo($pr, 4000, 7);
        $pr->update(['converted_to_po_id' => $po->po_id]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 11])->assertSuccessful();
        // Simulate the receiving flow (or a re-save) trying to finalize again:
        app(BudgetService::class)->spend($po->fresh()->load('purchaseRequest'));

        $budget->refresh();
        $this->assertSame('4000.00', (string) $budget->spent);
        $this->assertSame(1, BudgetTransaction::where('po_id', $po->po_id)->where('txn_type', 'spend')->count());
    }
```

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=BudgetLifecycleTest
```

Expected: FAIL — first new test's `committed` assertion fails (`200.00` left over) because the current inline code decrements by PO total, not via the ledger (and writes no txn rows, so the second test's txn count is 0).

- [ ] **Step 3: Replace inline budget math in PurchaseOrderController**

In `back-end/app/Http/Controllers/Api/PurchaseOrderController.php`, add import `use App\Services\BudgetService;`. Replace this block inside `update()`:

```php
                if ($wasCompleted && $po->pr_id) {
                    $pr = $po->purchaseRequest;
                    if ($pr && $pr->budget_id) {
                        BudgetAllocation::where('budget_id', $pr->budget_id)
                            ->increment('spent', $po->total_amount);
                        BudgetAllocation::where('budget_id', $pr->budget_id)
                            ->decrement('committed', $po->total_amount);
                    }
                }
```

with:

```php
                if ($wasCompleted && $po->pr_id) {
                    app(BudgetService::class)->spend(
                        $po->fresh()->load('purchaseRequest'),
                        $request->user()?->user_id
                    );
                }
```

If `use App\Models\BudgetAllocation;` is now unused in this file, remove the import.

- [ ] **Step 4: Replace inline budget math in ReceivingController**

In `back-end/app/Http/Controllers/Api/ReceivingController.php`, add import `use App\Services\BudgetService;`. Replace:

```php
            // Finalize budget when PO is fully received
            if ($newPoStatus === 11) {
                $po = PurchaseOrder::with('purchaseRequest')->find($poId);
                if ($po && $po->pr_id && $po->purchaseRequest?->budget_id) {
                    $budgetId = $po->purchaseRequest->budget_id;
                    BudgetAllocation::where('budget_id', $budgetId)->increment('spent', $po->total_amount);
                    BudgetAllocation::where('budget_id', $budgetId)->decrement('committed', $po->total_amount);
                }
            }
```

with:

```php
            // Finalize budget when PO is fully received (idempotent per PO)
            if ($newPoStatus === 11) {
                $po = PurchaseOrder::with('purchaseRequest')->find($poId);
                if ($po) {
                    app(BudgetService::class)->spend($po, $request->user()?->user_id);
                }
            }
```

If `use App\Models\BudgetAllocation;` is now unused in this file, remove the import.

- [ ] **Step 5: Run to verify pass**

```powershell
php artisan test --filter=BudgetLifecycleTest
php artisan test --filter=Budget
```

Expected: PASS (all Budget suites green).

- [ ] **Step 6: Commit**

```powershell
git add app/Http/Controllers/Api/PurchaseOrderController.php app/Http/Controllers/Api/ReceivingController.php tests/Feature/Budget/BudgetLifecycleTest.php
git commit -m "fix(budget): PO completion spends via ledger — kills estimate/actual drift and double-count"
```

---

## Task 10: Restock `create-pr` budget integration + budget summary in recommendations payload

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/RestockRecommendationController.php`
- Test: `back-end/tests/Feature/Budget/BudgetEnforcementTest.php`

- [ ] **Step 1: Write failing tests**

Append to `BudgetEnforcementTest.php`:

```php
    public function test_restock_create_pr_attaches_active_warehouse_budget(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(100000);
        $productId = $this->makeProduct();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/restock/create-pr', [
                'items' => [['product_id' => $productId, 'quantity' => 5]],
            ]);

        $response->assertStatus(201);
        $prId = $response->json('data.pr_id');
        $this->assertSame($budget->budget_id, \App\Models\PurchaseRequest::find($prId)->budget_id);
    }

    public function test_restock_create_pr_blocks_without_active_budget(): void
    {
        $admin = $this->makeUser('admin');
        $productId = $this->makeProduct();
        // No budget created for the warehouse.

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/restock/create-pr', [
                'items' => [['product_id' => $productId, 'quantity' => 5]],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('budget', strtolower($response->json('message')));
    }

    public function test_recommendations_payload_includes_active_budget_summary(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(50000, ['committed' => 10000]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/restock/recommendations');

        $response->assertSuccessful();
        $this->assertEquals($budget->budget_id, $response->json('data.budget.budget_id'));
        $this->assertEquals(40000, $response->json('data.budget.available'));
    }
```

Note: `BudgetTestCase::setUp` inserts a fresh warehouse location per test, but the dev/test DB may already contain other warehouses with lower `location_id` — and `createPr`/`index` pick the FIRST warehouse by id. If these tests fail because an older seeded warehouse wins, create the test budget against that warehouse instead: resolve `$whId = DB::table('locations')->where('location_type','warehouse')->orderBy('location_id')->value('location_id');` and pass `['location_id' => $whId]` as an override to `makeBudget`, and use `$whId` where a location is needed. Apply that pattern to all three tests.

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=BudgetEnforcementTest
```

Expected: FAIL — created PR has `budget_id` NULL; no-budget case returns 201 instead of 422; payload has no `budget` key.

- [ ] **Step 3: Implement**

In `back-end/app/Http/Controllers/Api/RestockRecommendationController.php`:

Add import `use App\Services\BudgetService;`.

In `createPr()`, after the `$warehouseId` guard (`if (!$warehouseId) ...`), insert:

```php
        $budget = app(BudgetService::class)->resolveActiveBudget((int) $warehouseId);
        if (!$budget) {
            return $this->error(
                'No active budget covers today for the warehouse. Create one in Budget Management first.',
                422
            );
        }
```

Change the transaction closure signature to include `$budget`:

```php
            $pr = DB::transaction(function () use ($validated, $request, $warehouseId, $budget) {
```

After `$lines` is built inside the closure, add the availability check and attach the budget (add import `use App\Exceptions\InsufficientBudgetException;` at the top):

```php
                $estimatedTotal = $lines->sum('subtotal');
                if (!app(BudgetService::class)->hasAvailable($budget, (float) $estimatedTotal)) {
                    throw new InsufficientBudgetException(
                        $budget->period_label,
                        (float) $estimatedTotal,
                        (float) $budget->total_budget - (float) $budget->committed - (float) $budget->spent
                    );
                }
```

and in the `PurchaseRequest::create([...])` array add:

```php
                    'budget_id'       => $budget->budget_id,
```

(and change `'estimated_total' => $lines->sum('subtotal'),` to `'estimated_total' => $estimatedTotal,`).

Change the catch around the transaction from a blanket 500 to:

```php
        } catch (InsufficientBudgetException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('Failed to create purchase request: ' . $e->getMessage(), 500);
        }
```

In `index()`, change the return to include the budget summary (add `use App\Models\Location;` if not present — it is already imported for `createPr`):

```php
        $warehouseId = Location::where('location_type', 'warehouse')
            ->orderBy('location_id')
            ->value('location_id');
        $budget = $warehouseId
            ? app(BudgetService::class)->resolveActiveBudget((int) $warehouseId)
            : null;

        return $this->success([
            'run_at'          => optional($rows->first())->run_at
                ?? StockParameterSuggestion::max('run_at'),
            'recommendations' => $rows,
            'budget'          => $budget ? [
                'budget_id'    => $budget->budget_id,
                'period_label' => $budget->period_label,
                'total_budget' => (float) $budget->total_budget,
                'available'    => (float) $budget->total_budget
                    - (float) $budget->committed
                    - (float) $budget->spent,
            ] : null,
        ], 'Restock recommendations retrieved');
```

- [ ] **Step 4: Run to verify pass**

```powershell
php artisan test --filter=BudgetEnforcementTest
php artisan test --filter=Restock
```

Expected: PASS — including the pre-existing Restock suites (the `createPr` tests there may now need an active warehouse budget; if `tests/Feature/Restock/RestockEndpointsTest.php` has a create-pr test that now 422s, add a budget insert to its setup following the same pattern as `makeBudget`).

- [ ] **Step 5: Commit**

```powershell
git add app/Http/Controllers/Api/RestockRecommendationController.php tests/Feature/Budget/BudgetEnforcementTest.php
git commit -m "feat(budget): restock create-pr draws from active warehouse budget; reco payload exposes budget fit"
```

---

## Task 11: Budget CRUD guards + ledger endpoint

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/BudgetAllocationController.php`
- Modify: `back-end/routes/api.php` (budget-allocations block, lines ~473–485)
- Test: `back-end/tests/Feature/Budget/BudgetEndpointsTest.php` (create)

- [ ] **Step 1: Write failing tests**

`back-end/tests/Feature/Budget/BudgetEndpointsTest.php`:

```php
<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetTransaction;
use App\Services\BudgetService;

class BudgetEndpointsTest extends BudgetTestCase
{
    public function test_update_total_routes_through_adjustment_journal(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/budget-allocations/{$budget->budget_id}", ['total_budget' => 15000])
            ->assertSuccessful();

        $budget->refresh();
        $this->assertSame('15000.00', (string) $budget->total_budget);
        $this->assertSame(1, BudgetTransaction::where('budget_id', $budget->budget_id)
            ->where('txn_type', 'adjustment')->count());
    }

    public function test_update_rejects_total_below_committed_plus_spent(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000, ['committed' => 4000, 'spent' => 3000]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/budget-allocations/{$budget->budget_id}", ['total_budget' => 5000])
            ->assertStatus(422);

        $this->assertSame('10000.00', (string) $budget->fresh()->total_budget);
    }

    public function test_destroy_blocked_while_commitments_outstanding(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        app(BudgetService::class)->commit($pr);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/budget-allocations/{$budget->budget_id}")
            ->assertStatus(422);

        $this->assertNull($budget->fresh()->deleted_at);
    }

    public function test_destroy_allowed_when_nothing_committed(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000, ['spent' => 2000]); // spent is fine, committed is not

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/budget-allocations/{$budget->budget_id}")
            ->assertSuccessful();
    }

    public function test_transactions_endpoint_returns_ledger_with_document_refs(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        app(BudgetService::class)->commit($pr, $admin->user_id);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/budget-allocations/{$budget->budget_id}/transactions");

        $response->assertSuccessful();
        $row = $response->json('data.0');
        $this->assertSame('commit', $row['txn_type']);
        $this->assertSame($pr->pr_number, $row['purchase_request']['pr_number']);
        $this->assertSame('Test', $row['performer']['first_name']);
    }

    public function test_transactions_endpoint_readable_by_inventory_analyst(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $budget = $this->makeBudget(10000);

        $this->actingAs($ia, 'sanctum')
            ->getJson("/api/budget-allocations/{$budget->budget_id}/transactions")
            ->assertSuccessful();
    }
}
```

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=BudgetEndpointsTest
```

Expected: FAIL — no adjustment journal, destroy succeeds, transactions route 404s.

- [ ] **Step 3: Implement controller changes**

In `back-end/app/Http/Controllers/Api/BudgetAllocationController.php`:

Add imports:

```php
use App\Models\BudgetTransaction;
use App\Services\BudgetService;
```

Add constructor after `use ApiResponse;`:

```php
    public function __construct(private BudgetService $budgetService)
    {
    }
```

In `update()`, after `$budget->update($validated);` is too late — restructure: replace

```php
            $budget->update($validated);
            $budget->load(['location', 'category']);
```

with

```php
            if (array_key_exists('total_budget', $validated)) {
                $newTotal = (float) $validated['total_budget'];
                unset($validated['total_budget']);
                try {
                    $this->budgetService->adjustTotal($budget, $newTotal, $request->user()?->user_id);
                } catch (\InvalidArgumentException $e) {
                    return $this->error($e->getMessage(), 422);
                }
            }

            $budget->update($validated);
            $budget->load(['location', 'category']);
```

In `destroy()`, after the not-found guard, add:

```php
        if ((float) $budget->committed > 0) {
            return $this->error(sprintf(
                'This budget still has ₱%s committed to open purchase requests. Complete or archive those PRs first.',
                number_format((float) $budget->committed, 2)
            ), 422);
        }
```

Add the transactions endpoint after `available()`:

```php
    /** Immutable ledger for a budget — the "where did the money go?" drill-down. */
    public function transactions(Request $request, $id)
    {
        $budget = BudgetAllocation::withTrashed()->find($id);
        if (!$budget) {
            return $this->notFound('Budget allocation not found');
        }

        $txns = BudgetTransaction::where('budget_id', $id)
            ->with([
                'purchaseRequest:pr_id,pr_number',
                'purchaseOrder:po_id,po_number',
                'performer:user_id,first_name,last_name,username',
            ])
            ->orderByDesc('txn_id')
            ->paginate($request->integer('per_page', 20));

        return $this->paginated($txns, 'Budget transactions retrieved');
    }
```

Note: `purchaseRequest` uses soft deletes — an archived PR would make the relation return null. To keep pr_number visible for archived PRs, use `'purchaseRequest' => fn ($q) => $q->withTrashed()->select('pr_id', 'pr_number'),` instead of the string form.

- [ ] **Step 4: Register the route**

In `back-end/routes/api.php`, inside the read group (admin + inventory_analyst, lines ~474–478), add:

```php
        Route::get('/budget-allocations/{id}/transactions', [BudgetAllocationController::class, 'transactions']);
```

- [ ] **Step 5: Run to verify pass**

```powershell
php artisan test --filter=BudgetEndpointsTest
```

Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```powershell
git add app/Http/Controllers/Api/BudgetAllocationController.php routes/api.php tests/Feature/Budget/BudgetEndpointsTest.php
git commit -m "feat(budget): CRUD guards (floor, archive block) + ledger drill-down endpoint"
```

---

## Task 12: `budget:rebuild-totals` command

**Files:**
- Create: `back-end/app/Console/Commands/RebuildBudgetTotals.php`
- Test: `back-end/tests/Feature/Budget/RebuildBudgetTotalsTest.php` (create)

- [ ] **Step 1: Write failing tests**

`back-end/tests/Feature/Budget/RebuildBudgetTotalsTest.php`:

```php
<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetTransaction;
use App\Services\BudgetService;

class RebuildBudgetTotalsTest extends BudgetTestCase
{
    public function test_rebuild_recomputes_cached_columns_from_ledger(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        app(BudgetService::class)->commit($pr);

        // Corrupt the cached columns to simulate historical drift.
        $budget->forceFill(['committed' => 9999, 'spent' => 123])->save();

        $this->artisan('budget:rebuild-totals')->assertSuccessful();

        $budget->refresh();
        $this->assertSame('4000.00', (string) $budget->committed);
        $this->assertSame('0.00', (string) $budget->spent);
    }

    public function test_backfill_synthesizes_ledger_for_pre_ledger_budgets(): void
    {
        $budget = $this->makeBudget(10000, ['committed' => 500, 'spent' => 700]); // drifted junk
        $pr = $this->makePr($budget, 4000, 14);                                   // approved, outstanding
        $po = $this->makePo($pr, 3500, 11);                                       // completed PO
        $pr->update(['converted_to_po_id' => $po->po_id]);
        $prOpen = $this->makePr($budget, 2000, 14);                               // approved, no PO yet

        $this->artisan('budget:rebuild-totals --backfill')->assertSuccessful();

        $budget->refresh();
        // pr: commit 4000, spend 3500, consume 3500, surplus 500 → outstanding 0
        // prOpen: commit 2000 outstanding
        $this->assertSame('2000.00', (string) $budget->committed);
        $this->assertSame('3500.00', (string) $budget->spent);
        $this->assertGreaterThan(0, BudgetTransaction::where('budget_id', $budget->budget_id)->count());
    }

    public function test_backfill_skips_budgets_that_already_have_ledger_rows(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        app(BudgetService::class)->commit($pr);
        $before = BudgetTransaction::where('budget_id', $budget->budget_id)->count();

        $this->artisan('budget:rebuild-totals --backfill')->assertSuccessful();

        $this->assertSame($before, BudgetTransaction::where('budget_id', $budget->budget_id)->count());
    }
}
```

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=RebuildBudgetTotalsTest
```

Expected: FAIL — `Command "budget:rebuild-totals" is not defined`.

- [ ] **Step 3: Implement the command**

`back-end/app/Console/Commands/RebuildBudgetTotals.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\BudgetAllocation;
use App\Models\BudgetTransaction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use Illuminate\Console\Command;

class RebuildBudgetTotals extends Command
{
    protected $signature = 'budget:rebuild-totals
        {--backfill : Synthesize ledger rows from live PR/PO state for budgets that have no transactions yet}';

    protected $description = 'Recompute budget_allocations.committed/spent from the budget_transactions ledger';

    public function handle(): int
    {
        if ($this->option('backfill')) {
            $this->backfill();
        }

        foreach (BudgetAllocation::withTrashed()->get() as $budget) {
            $sum = fn (string $type): float => (float) BudgetTransaction::where('budget_id', $budget->budget_id)
                ->where('txn_type', $type)->sum('amount');

            $budget->committed = round(max(0, $sum(BudgetTransaction::TYPE_COMMIT) - $sum(BudgetTransaction::TYPE_RELEASE)), 2);
            $budget->spent     = round($sum(BudgetTransaction::TYPE_SPEND), 2);
            $budget->save();

            $this->line(sprintf(
                '%s [%s]: committed=%s spent=%s',
                $budget->period_label,
                $budget->budget_id,
                number_format((float) $budget->committed, 2),
                number_format((float) $budget->spent, 2)
            ));
        }

        $this->info('Budget totals rebuilt from ledger.');

        return self::SUCCESS;
    }

    /**
     * For budgets with no ledger rows yet (created before the ledger existed),
     * synthesize commit/spend/release rows from the live PR/PO state so the
     * rebuild has something truthful to sum.
     */
    private function backfill(): void
    {
        $withLedger = BudgetTransaction::distinct()->pluck('budget_id');
        $virgin = BudgetAllocation::withTrashed()->whereNotIn('budget_id', $withLedger)->get();

        foreach ($virgin as $budget) {
            $prs = PurchaseRequest::withTrashed()
                ->where('budget_id', $budget->budget_id)
                ->whereNull('deleted_at')          // archived PRs hold no commitment
                ->whereIn('status_id', [14, 16])   // approved / partially converted
                ->get();

            foreach ($prs as $pr) {
                BudgetTransaction::create([
                    'budget_id' => $budget->budget_id,
                    'txn_type'  => BudgetTransaction::TYPE_COMMIT,
                    'amount'    => round((float) $pr->estimated_total, 2),
                    'pr_id'     => $pr->pr_id,
                    'notes'     => 'Backfill: PR ' . $pr->pr_number . ' approved',
                ]);

                $remaining = (float) $pr->estimated_total;
                $completedPos = PurchaseOrder::where('pr_id', $pr->pr_id)->where('status_id', 11)->get();

                foreach ($completedPos as $po) {
                    $amount = (float) $po->total_amount;
                    BudgetTransaction::create([
                        'budget_id' => $budget->budget_id,
                        'txn_type'  => BudgetTransaction::TYPE_SPEND,
                        'amount'    => round($amount, 2),
                        'pr_id'     => $pr->pr_id,
                        'po_id'     => $po->po_id,
                        'notes'     => 'Backfill: PO ' . $po->po_number . ' completed',
                    ]);

                    $consume = round(min($remaining, $amount), 2);
                    if ($consume > 0) {
                        BudgetTransaction::create([
                            'budget_id' => $budget->budget_id,
                            'txn_type'  => BudgetTransaction::TYPE_RELEASE,
                            'amount'    => $consume,
                            'pr_id'     => $pr->pr_id,
                            'po_id'     => $po->po_id,
                            'notes'     => 'Backfill: commitment consumed by PO ' . $po->po_number,
                        ]);
                        $remaining = round($remaining - $consume, 2);
                    }

                    // Fully-converted PR whose final PO is done: return estimate surplus.
                    if ((int) $pr->status_id === 14
                        && (int) $pr->converted_to_po_id === (int) $po->po_id
                        && $remaining > 0) {
                        BudgetTransaction::create([
                            'budget_id' => $budget->budget_id,
                            'txn_type'  => BudgetTransaction::TYPE_RELEASE,
                            'amount'    => $remaining,
                            'pr_id'     => $pr->pr_id,
                            'po_id'     => $po->po_id,
                            'notes'     => 'Backfill: estimate surplus returned',
                        ]);
                        $remaining = 0.0;
                    }
                }
            }

            $this->line('Backfilled ledger for budget ' . $budget->budget_id . ' (' . $budget->period_label . ')');
        }
    }
}
```

- [ ] **Step 4: Run to verify pass**

```powershell
php artisan test --filter=RebuildBudgetTotalsTest
```

Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```powershell
git add app/Console/Commands/RebuildBudgetTotals.php tests/Feature/Budget/RebuildBudgetTotalsTest.php
git commit -m "feat(budget): budget:rebuild-totals command with --backfill for pre-ledger data"
```

---

## Task 13: Frontend plumbing — API client, realtime event, PR page hard block

**Files:**
- Modify: `frontend/src/admin/services/api.js` (budgetAllocationsAPI, ~line 1285)
- Modify: `frontend/src/admin/utils/realtimeChannel.js`
- Modify: `frontend/src/admin/utils/realtimeChannel.test.js`
- Modify: `frontend/src/admin/pages/PurchaseRequestPage.jsx`

- [ ] **Step 1: Update realtimeChannel test first (failing)**

In `frontend/src/admin/utils/realtimeChannel.test.js`, find the assertion listing the four event names (line ~43 has `'.approval-queue.changed'`) and add `'.budget.changed'` to the expected array in that test.

Run:

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\frontend
npm test -- --watchAll=false realtimeChannel
```

Expected: FAIL — array mismatch.

- [ ] **Step 2: Add the event name**

In `frontend/src/admin/utils/realtimeChannel.js`:

```js
export const REALTIME_EVENTS = [
  '.approval-queue.changed',
  '.purchase-order.changed',
  '.inventory.changed',
  '.transfer.changed',
  '.budget.changed',
];
```

Run the same test command. Expected: PASS.

- [ ] **Step 3: Add getTransactions to the API client**

In `frontend/src/admin/services/api.js`, inside `budgetAllocationsAPI` (after `getAvailable`), add:

```js
  /**
   * Immutable ledger for a budget — every commit/release/spend/adjustment row
   * with linked PR/PO numbers. Paginated.
   */
  getTransactions: async (budgetId, params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(`/budget-allocations/${budgetId}/transactions${query ? `?${query}` : ''}`);
  },
```

- [ ] **Step 4: Hard-block submission on the PR page**

In `frontend/src/admin/pages/PurchaseRequestPage.jsx`:

(a) In `handleSubmitPR` (line ~373), after the `validLines.length` guard, add:

```js
    if (!budget?.budget_id) {
      toast.error("Couldn't send request", 'No budget found for this location. Ask Admin to create one in Budget Management.');
      return;
    }
    if (overBudget) {
      toast.error(
        "Couldn't send request",
        `Estimated total ${fmtPeso(estimatedTotal)} exceeds the available budget ${fmtPeso(budgetAvailable)} for this location.`
      );
      return;
    }
```

and extend the `useCallback` dependency array from
`[selectedLocId, neededByDate, purpose, lineItems, budget]` to
`[selectedLocId, neededByDate, purpose, lineItems, budget, overBudget, estimatedTotal, budgetAvailable]`.

(b) Update the over-budget warning copy (line ~770–773). Replace:

```jsx
              Estimated total ({fmtPeso(estimatedTotal)}) exceeds available budget ({fmtPeso(budgetAvailable)}) by{' '}
              <strong>{fmtPeso(estimatedTotal - budgetAvailable)}</strong>. You may still submit — Admin will review.
```

with:

```jsx
              Estimated total ({fmtPeso(estimatedTotal)}) exceeds available budget ({fmtPeso(budgetAvailable)}) by{' '}
              <strong>{fmtPeso(estimatedTotal - budgetAvailable)}</strong>. Reduce the request or ask Admin to raise the budget.
```

(c) Disable the submit button (line ~779–784). Replace `disabled={submitting}` with:

```jsx
          disabled={submitting || loadingBudget || overBudget || (selectedLocId && !budget)}
          title={
            overBudget ? 'Estimated total exceeds the available budget'
            : (selectedLocId && !budget) ? 'No budget allocated for this location'
            : undefined
          }
```

- [ ] **Step 5: Verify frontend builds and tests pass**

```powershell
npm test -- --watchAll=false realtimeChannel
npx eslint src/admin/pages/PurchaseRequestPage.jsx src/admin/services/api.js src/admin/utils/realtimeChannel.js
```

Expected: test PASS, no new lint errors.

- [ ] **Step 6: Commit**

```powershell
git add src/admin/services/api.js src/admin/utils/realtimeChannel.js src/admin/utils/realtimeChannel.test.js src/admin/pages/PurchaseRequestPage.jsx
git commit -m "feat(budget): frontend hard block on PR submit + ledger API + budget realtime event"
```

---

## Task 14: Budget Management page — utilization bar, ledger drill-down, close-out variance

**Files:**
- Modify: `frontend/src/admin/pages/BudgetManagementPage.jsx`
- Modify: `frontend/src/admin/styles/po_recommendation.css` (already imported by the page)

The page already computes `total`, `committed`, `spent`, `available`, `health`, `pStatus` per card (around line 495) and renders a `budget-grid` of four figures (lines ~543–559).

- [ ] **Step 1: Add CSS primitives**

Append to `frontend/src/admin/styles/po_recommendation.css`:

```css
/* ── Budget utilization bar ─────────────────────────────────────────────── */
.budget-util { margin: 10px 16px 4px; }
.budget-util-bar {
  display: flex; height: 10px; border-radius: 999px; overflow: hidden;
  background: #e2e8f0;
}
.budget-util-seg--spent     { background: #0f766e; }
.budget-util-seg--committed { background: #f59e0b; }
.budget-util-legend {
  display: flex; gap: 14px; margin-top: 6px; font-size: 11px; color: #64748b;
  flex-wrap: wrap; align-items: center;
}
.budget-util-legend .dot {
  display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px;
}
.budget-util-pct { margin-left: auto; font-weight: 700; color: #1e293b; }

/* ── Ledger drill-down ──────────────────────────────────────────────────── */
.budget-ledger-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.budget-ledger-table th, .budget-ledger-table td {
  padding: 8px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top;
}
.budget-ledger-table th { font-size: 11px; letter-spacing: .04em; color: #64748b; text-transform: uppercase; }
.txn-badge {
  display: inline-block; padding: 2px 8px; border-radius: 999px;
  font-size: 10px; font-weight: 700; letter-spacing: .05em;
}
.txn-badge--commit     { background: #fef3c7; color: #92400e; }
.txn-badge--release    { background: #dcfce7; color: #166534; }
.txn-badge--spend      { background: #ccfbf1; color: #115e59; }
.txn-badge--adjustment { background: #e0e7ff; color: #3730a3; }
.budget-ledger-amount--minus { color: #166534; }
.budget-ledger-amount--plus  { color: #92400e; }

/* ── Period close-out variance strip ────────────────────────────────────── */
.budget-closeout {
  margin: 8px 16px 4px; padding: 8px 12px; border-radius: 8px;
  background: #f8fafc; border: 1px dashed #cbd5e1; font-size: 12px; color: #475569;
  display: flex; gap: 16px; flex-wrap: wrap;
}
.budget-closeout strong { color: #1e293b; }
```

(These hex values match the greens/ambers/slates already used in this page and `po_recommendation.css`. If the page renders in dark mode via `airking_ds.css` variables, prefer the closest `ak-` CSS variables where they exist.)

- [ ] **Step 2: Add utilization bar + close-out strip to the card**

In `BudgetManagementPage.jsx`, inside the card render where `total/committed/spent/available/health/pStatus` are in scope, immediately after the closing `</div>` of `className="budget-grid"`, insert:

```jsx
              {total > 0 && (() => {
                const spentPct     = Math.min(100, (spent / total) * 100);
                const committedPct = Math.min(100 - spentPct, (committed / total) * 100);
                const utilPct      = Math.min(100, Math.round(((spent + committed) / total) * 100));
                return (
                  <div className="budget-util">
                    <div
                      className="budget-util-bar"
                      role="img"
                      aria-label={`${utilPct}% of budget utilized`}
                    >
                      <span className="budget-util-seg--spent" style={{ width: `${spentPct}%` }} />
                      <span className="budget-util-seg--committed" style={{ width: `${committedPct}%` }} />
                    </div>
                    <div className="budget-util-legend">
                      <span><span className="dot" style={{ background: '#0f766e' }} />Spent</span>
                      <span><span className="dot" style={{ background: '#f59e0b' }} />Committed</span>
                      <span><span className="dot" style={{ background: '#e2e8f0' }} />Available</span>
                      <span className="budget-util-pct">{utilPct}% utilized</span>
                    </div>
                  </div>
                );
              })()}

              {pStatus.key === 'expired' && total > 0 && (
                <div className="budget-closeout">
                  <span>Period closed:</span>
                  <span>Allocated <strong>{fmtPeso(total)}</strong></span>
                  <span>Spent <strong>{fmtPeso(spent)}</strong> ({Math.round((spent / total) * 100)}%)</span>
                  <span>Unused <strong>{fmtPeso(Math.max(0, total - spent - committed))}</strong></span>
                  {committed > 0 && <span>Still committed <strong>{fmtPeso(committed)}</strong></span>}
                </div>
              )}
```

- [ ] **Step 3: Add ledger modal state + fetch**

Near the other modal state (line ~138), add:

```jsx
  // Ledger drill-down modal
  const [ledgerBudget,  setLedgerBudget]  = useState(null); // budget object or null
  const [ledgerRows,    setLedgerRows]    = useState([]);
  const [ledgerLoading, setLedgerLoading] = useState(false);
```

Add the open handler next to the other handlers:

```jsx
  const openLedger = useCallback(async (budget) => {
    setLedgerBudget(budget);
    setLedgerLoading(true);
    setLedgerRows([]);
    try {
      const res = await budgetAllocationsAPI.getTransactions(budget.budget_id, { per_page: 50 });
      setLedgerRows(res?.data ?? []);
    } catch (err) {
      toast.error('Could not load budget ledger', err?.message);
    } finally {
      setLedgerLoading(false);
    }
  }, []);
```

- [ ] **Step 4: Add a "View ledger" button on each card**

Next to the card's existing Edit/Archive actions, add:

```jsx
              <button
                type="button"
                className="po-btn"
                onClick={() => openLedger(b)}
                aria-label={`View transaction ledger for ${b.period_label}`}
              >
                View Ledger
              </button>
```

(`b` = the budget object variable used by the card map — match the actual variable name at the insertion point.)

- [ ] **Step 5: Render the modal**

After the existing Edit `<Modal>` (line ~747–777), add:

```jsx
      {/* ── Ledger drill-down Modal ─────────────────────────────────────── */}
      <Modal
        isOpen={!!ledgerBudget}
        onClose={() => setLedgerBudget(null)}
        title={ledgerBudget ? `Budget Ledger — ${ledgerBudget.period_label}` : ''}
      >
        {ledgerLoading ? (
          <Loader variant="inline" label="Loading transactions" />
        ) : ledgerRows.length === 0 ? (
          <p style={{ color: '#64748b', fontSize: 13 }}>
            No transactions yet. Rows appear when purchase requests are approved,
            archived, or received against this budget.
          </p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="budget-ledger-table">
              <thead>
                <tr>
                  <th>When</th><th>Type</th><th>Amount</th><th>Document</th><th>By</th><th>Note</th>
                </tr>
              </thead>
              <tbody>
                {ledgerRows.map((t) => {
                  const isReturn = t.txn_type === 'release';
                  return (
                    <tr key={t.txn_id}>
                      <td>{isoToMDY(t.created_at)}</td>
                      <td><span className={`txn-badge txn-badge--${t.txn_type}`}>{t.txn_type.toUpperCase()}</span></td>
                      <td className={isReturn ? 'budget-ledger-amount--minus' : 'budget-ledger-amount--plus'}>
                        {fmtPeso(t.amount)}
                      </td>
                      <td>
                        {t.purchase_request?.pr_number || t.purchase_order?.po_number || '—'}
                        {t.purchase_request?.pr_number && t.purchase_order?.po_number
                          ? ` / ${t.purchase_order.po_number}` : ''}
                      </td>
                      <td>
                        {t.performer
                          ? `${t.performer.first_name} ${t.performer.last_name}`.trim()
                          : 'System'}
                      </td>
                      <td>{t.notes || ''}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Modal>
```

Note: check the `Modal` component's actual props (`isOpen`/`onClose`/`title`) against the existing Edit modal usage at line ~747 and mirror them exactly.

- [ ] **Step 6: Verify in the browser**

Start backend + frontend (`php artisan serve` in back-end if not already running via XAMPP vhost; `npm start` in frontend). As Admin, open Budget Management:
- Cards show the segmented bar and % utilized.
- "View Ledger" opens the modal; a budget touched by an approved PR shows a COMMIT row with the PR number.
- An expired budget shows the close-out strip.

- [ ] **Step 7: Commit**

```powershell
git add src/admin/pages/BudgetManagementPage.jsx src/admin/styles/po_recommendation.css
git commit -m "feat(budget): utilization bar, ledger drill-down, and close-out variance on Budget Management"
```

---

## Task 15: PO Recommendation page — budget-fit line

**Files:**
- Modify: `frontend/src/admin/pages/PORecommendation.jsx`

Backend already returns `data.budget` (Task 10). Selected items live in a `Map` of `product_id → quantity` (line ~55, ~112–127); row cost basis is `r.product.cost_price`.

- [ ] **Step 1: Add budget state**

Next to `const [runAt, setRunAt] = useState(null);` add:

```jsx
  const [budget, setBudget] = useState(null); // active warehouse budget summary or null
```

In the initial loader (line ~61–65), after `setRunAt(...)`, add:

```jsx
      setBudget(recRes?.data?.budget || null);
```

In the recalculate handler (line ~81–82), after `setRunAt(...)`, add:

```jsx
      setBudget(res?.data?.budget || null);
```

- [ ] **Step 2: Compute selected cost**

Near the `counts` memo (line ~106), add:

```jsx
  const selectedCost = useMemo(() => {
    let sum = 0;
    for (const [productId, qty] of selected.entries()) {
      const row = rows.find((r) => r.product_id === productId);
      const cost = Number(row?.product?.cost_price) || 0;
      sum += cost * (Number(qty) || 0);
    }
    return sum;
  }, [selected, rows]);
```

- [ ] **Step 3: Render the fit line**

Near the Create PR button (the control calling `handleCreatePR`), render above/beside it:

```jsx
      {budget && selected.size > 0 && (
        <div
          role="status"
          style={{
            fontSize: 13,
            padding: '8px 12px',
            borderRadius: 8,
            border: `1px solid ${selectedCost > budget.available ? '#fbbf24' : '#bbf7d0'}`,
            background: selectedCost > budget.available ? '#fffbeb' : '#f0fdf4',
            color: selectedCost > budget.available ? '#92400e' : '#166534',
          }}
        >
          {selectedCost > budget.available ? (
            <>Selected items total <strong>₱{Number(selectedCost).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</strong> — exceeds
              available budget (₱{Number(budget.available).toLocaleString('en-PH', { minimumFractionDigits: 2 })} for {budget.period_label}) by{' '}
              <strong>₱{Number(selectedCost - budget.available).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</strong>. Consider staggering the order.</>
          ) : (
            <>Selected items total <strong>₱{Number(selectedCost).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</strong> — fits within
              available budget (₱{Number(budget.available).toLocaleString('en-PH', { minimumFractionDigits: 2 })} for {budget.period_label}).</>
          )}
        </div>
      )}
      {!budget && selected.size > 0 && (
        <div role="status" style={{ fontSize: 13, padding: '8px 12px', borderRadius: 8, border: '1px solid #fbbf24', background: '#fffbeb', color: '#92400e' }}>
          No active budget covers today for the warehouse — the PR will be rejected until Admin creates one in Budget Management.
        </div>
      )}
```

(If the page already has a helper like `fmtPeso`, use it instead of the inline `toLocaleString` calls.)

- [ ] **Step 4: Verify in the browser**

On the PO Recommendation page, select rows: the fit line appears and flips to the amber "exceeds" state when selected cost passes the available budget. With no active warehouse budget, the warning shows and Create PR returns the backend 422 toast.

- [ ] **Step 5: Commit**

```powershell
git add src/admin/pages/PORecommendation.jsx
git commit -m "feat(budget): budget-fit line connects PO recommendations to remaining budget"
```

---

## Task 16: Full verification + repair drifted demo data

**Files:** none new.

- [ ] **Step 1: Run the entire backend test suite**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\back-end
php artisan test
```

Expected: everything green, including pre-existing suites (Restock, Realtime, Rbac, WarrantyClaim…). Pay special attention to any pre-existing test that creates PRs — `budget_id` is now required; fix those tests by attaching an active budget (pattern in `BudgetTestCase::makeBudget`), never by weakening the enforcement.

- [ ] **Step 2: Run frontend tests + production build**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\frontend
npm test -- --watchAll=false
npm run build
```

Expected: tests pass, build succeeds.

- [ ] **Step 3: Repair live dev data**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\back-end
php artisan budget:rebuild-totals --backfill
```

Expected: per-budget lines showing recomputed committed/spent; existing drifted budgets now match their PR/PO reality. Spot-check one budget in the UI ledger drill-down.

- [ ] **Step 4: End-to-end demo walkthrough (manual)**

1. Admin creates a ₱50,000 budget for a location (active period).
2. IA submits a PR of ₱30,000 → succeeds; budget card shows ₱30,000 committed after Admin approves; bell fires nothing (60%).
3. IA submits a second PR of ₱25,000 → **blocked with the peso-formatted 422 message**.
4. Admin approves a smaller PR pushing utilization past 80% → bell/badge fires.
5. Convert + receive the PO for less than the estimate → ledger shows SPEND + consume RELEASE + "Estimate surplus returned"; available goes back up.
6. Archive an approved PR → RELEASE row appears; available restored.
7. Attempt to lower the budget total below committed+spent → 422. Attempt to archive the budget with commitments → 422.

- [ ] **Step 5: Commit any test fixes and finish**

```powershell
git add -A
git commit -m "test(budget): align pre-existing suites with required budget on PRs"
```

Then use superpowers:finishing-a-development-branch to decide merge/PR.
