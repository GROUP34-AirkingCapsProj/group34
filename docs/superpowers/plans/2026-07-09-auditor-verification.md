# Auditor Verification Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a blocking maker–checker–approver control on top of the budget layer: an auditor must verify a budget before PRs can draw from it, and must verify a PO before it can be approved or received — with verification rights assigned through the existing permission system.

**Architecture:** Verification columns (`verification_status`/`verified_by`/`verified_at`/`verification_note`) on `budget_allocations` and `purchase_orders`. Two new permission features (`budget_verification`, `purchase_order_verification`) default to the auditor role and gate PATCH verify/reject endpoints. Blocking gates live in `BudgetService` (a new `UnverifiedBudgetException`) and in the PO approval/receiving controllers. The dormant `purchase_orders.verified_by` column is activated and its fake manual-input UI removed.

**Tech Stack:** Laravel 10 API (`back-end/`), React CRA (`frontend/`), MariaDB via XAMPP. Tests: PHPUnit feature tests with `DatabaseTransactions` against `airkingV5_test`.

**Spec:** `docs/superpowers/specs/2026-07-09-auditor-verification-design.md`

**Repo conventions that matter here:**
- Schema is SQL-dump based — NEVER run blanket `php artisan migrate`. Apply single migrations with `--path=` to BOTH `airkingV5` (dev) and `airkingV5_test`.
- Backend commands run from `back-end/`, frontend from `frontend/`. PowerShell 5.1 shell — chain with `;`, not `&&`.
- API responses use the `ApiResponse` trait (`$this->success/error/validationError/notFound/paginated`).
- Permission + role permission seeders are matrix-driven from `PermissionSeeder::matrix()` — adding to the matrix flows to both seeders automatically.
- Frontend permission checks: `const { can } = useAuth(); can('feature','action')` reads from the backend `/permissions/mine` map — new permission keys flow automatically, no frontend mirror file to edit.
- Auditor is globally read-only via `AuditorReadOnly` middleware; write endpoints for auditors need an explicit carve-out there.

**Key IDs / facts (verified against the code):**
- PR statuses: 13 Pending, 14 Approved, 15 Rejected, 16 Partially Converted. PO statuses: 7 Pending, 8 Approved, 9 Rejected, 10 Authorized, 11 Completed, 12 Partially Received.
- The frontend "approve PO" action sets the PO to **Authorized (10)** via `PUT /purchase-orders/{id}` with `status_id`. So the PO approval gate must fire when `status_id` transitions to **8 OR 10**.
- `BudgetAllocation`: PK `budget_id`, has `created_by` + `creator()`, appended `available`, SoftDeletes + Auditable. `BudgetAllocationController` already has `__construct(private BudgetService $budgetService)`.
- `PurchaseOrder`: `verified_by` already fillable, `verifiedBy()` relation exists, `created_by` + `createdBy()` exist, Auditable.
- `BudgetService::commit()` already throws `InsufficientBudgetException` caught by `PurchaseRequestController::approve()`. `resolveActiveBudget()` powers PR submit + restock + the reco budget-fit line.
- Budget test base `back-end/tests/Feature/Budget/BudgetTestCase.php` — `makeBudget()` / `makePr()` / `makePo()` / `makeUser()`.

---

## Task 1: Verification columns + model updates

**Files:**
- Create: `back-end/database/migrations/2026_07_09_000000_add_verification_to_budgets_and_pos.php`
- Modify: `back-end/app/Models/BudgetAllocation.php`
- Modify: `back-end/app/Models/PurchaseOrder.php`

- [ ] **Step 1: Write the migration**

`back-end/database/migrations/2026_07_09_000000_add_verification_to_budgets_and_pos.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_allocations', function (Blueprint $table) {
            $table->string('verification_status', 20)->default('pending')->after('spent');
            $table->unsignedBigInteger('verified_by')->nullable()->after('verification_status');
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->string('verification_note')->nullable()->after('verified_at');
            $table->foreign('verified_by')->references('user_id')->on('users')->nullOnDelete();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('verification_status', 20)->default('pending')->after('verified_by');
            $table->timestamp('verified_at')->nullable()->after('verification_status');
            $table->string('verification_note')->nullable()->after('verified_at');
        });

        // Grandfather POs already past Pending so in-flight receiving is not blocked.
        DB::table('purchase_orders')
            ->whereIn('status_id', [8, 10, 11, 12])
            ->update([
                'verification_status' => 'verified',
                'verification_note'   => 'Grandfathered — predates verification workflow',
            ]);
    }

    public function down(): void
    {
        Schema::table('budget_allocations', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropColumn(['verification_status', 'verified_by', 'verified_at', 'verification_note']);
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['verification_status', 'verified_at', 'verification_note']);
        });
    }
};
```

- [ ] **Step 2: Apply to dev + test DBs**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\back-end
php artisan config:clear
php artisan migrate --path=database/migrations/2026_07_09_000000_add_verification_to_budgets_and_pos.php --force
$env:DB_DATABASE = 'airkingV5_test'
php artisan migrate --path=database/migrations/2026_07_09_000000_add_verification_to_budgets_and_pos.php --force
Remove-Item Env:DB_DATABASE
php artisan config:clear
```

Expected: `DONE` for each. Verify: `C:\XAMPP1\mysql\bin\mysql.exe -u root airkingV5_test -e "DESCRIBE budget_allocations; DESCRIBE purchase_orders;"` shows the new columns.

- [ ] **Step 3: Update BudgetAllocation model**

In `back-end/app/Models/BudgetAllocation.php`, add the four columns to `$fillable` (after `'spent',`):

```php
        'verification_status',
        'verified_by',
        'verified_at',
        'verification_note',
```

Add to `$casts`:

```php
        'verified_at'  => 'datetime',
```

Add an `isVerified()` helper and a `verifier()` relation (place after the existing `creator()` method):

```php
    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by', 'user_id');
    }
```

- [ ] **Step 4: Update PurchaseOrder model**

In `back-end/app/Models/PurchaseOrder.php`, add to `$fillable` (after `'verified_by',`):

```php
        'verification_status',
        'verified_at',
        'verification_note',
```

Add to `$casts`:

```php
        'verified_at'            => 'datetime',
```

Add an `isVerified()` helper (after the `status()` relation):

```php
    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }
```

- [ ] **Step 5: Smoke-check**

```powershell
php artisan tinker --execute="echo App\Models\BudgetAllocation::whereNotNull('verification_status')->count(); echo '|'; echo App\Models\PurchaseOrder::where('verification_status','verified')->count();"
```

Expected: two numbers, no error (second reflects grandfathered POs on dev DB).

- [ ] **Step 6: Commit**

```powershell
git add database/migrations/2026_07_09_000000_add_verification_to_budgets_and_pos.php app/Models/BudgetAllocation.php app/Models/PurchaseOrder.php
git commit -m "feat(verify): verification columns on budgets and POs + model helpers

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 2: BudgetService verification gates + test-base default

**Files:**
- Create: `back-end/app/Exceptions/UnverifiedBudgetException.php`
- Modify: `back-end/app/Services/BudgetService.php`
- Modify: `back-end/tests/Feature/Budget/BudgetTestCase.php`
- Test: `back-end/tests/Feature/Budget/BudgetServiceTest.php`

- [ ] **Step 1: Make the test base default budgets to VERIFIED**

The existing 48 budget tests assume a usable budget. In `BudgetTestCase::makeBudget()`, add `'verification_status' => 'verified'` to the `array_merge` defaults so those tests keep passing; new tests override explicitly. Change:

```php
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
```

to add the one line:

```php
            'committed'    => 0,
            'spent'        => 0,
            'verification_status' => 'verified',
        ], $overrides));
```

- [ ] **Step 2: Write failing tests**

Append to `back-end/tests/Feature/Budget/BudgetServiceTest.php` (add `use App\Exceptions\UnverifiedBudgetException;` to the imports):

```php
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
        // Only an unverified budget exists for the location → nothing resolves.
        $this->makeBudget(10000, ['verification_status' => 'pending']);
        $this->assertNull($this->service->resolveActiveBudget($this->locationId));

        // A verified one resolves.
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
```

- [ ] **Step 3: Run to verify failure**

```powershell
php artisan test --filter=BudgetServiceTest
```

Expected: the 5 new tests fail (`UnverifiedBudgetException` class not found; resolve returns the pending budget; adjustTotal leaves status verified). Pre-existing tests still pass thanks to Step 1.

- [ ] **Step 4: Create the exception**

`back-end/app/Exceptions/UnverifiedBudgetException.php`:

```php
<?php

namespace App\Exceptions;

class UnverifiedBudgetException extends \RuntimeException
{
    public function __construct(string $periodLabel, string $status)
    {
        $reason = $status === 'rejected'
            ? "was rejected by the auditor"
            : "has not been verified by an auditor yet";
        parent::__construct("Budget {$periodLabel} {$reason}.");
    }
}
```

- [ ] **Step 5: Gate commit()**

In `back-end/app/Services/BudgetService.php`, add `use App\Exceptions\UnverifiedBudgetException;` to the imports. In `commit()`, immediately after the idempotency-guard block (the `if (BudgetTransaction::where('pr_id', ...)->exists()) return;`) and before `$utilBefore = ...`, add:

```php
            if ($budget->verification_status !== 'verified') {
                throw new UnverifiedBudgetException($budget->period_label, $budget->verification_status);
            }
```

- [ ] **Step 6: Filter resolveActiveBudget()**

In `resolveActiveBudget()`, add the verified constraint to the query:

```php
        return BudgetAllocation::where('location_id', $locationId)
            ->where('verification_status', 'verified')
            ->where('period_start', '<=', $today)
            ->where('period_end', '>=', $today)
            ->orderByDesc('period_start')
            ->first();
```

- [ ] **Step 7: Reset verification in adjustTotal()**

In `adjustTotal()`, inside the transaction, after `$locked->total_budget = round($newTotal, 2);` and before `$locked->save();`, add:

```php
            $locked->verification_status = 'pending';
            $locked->verified_by = null;
            $locked->verified_at = null;
            $locked->verification_note = 'Auto-reset: total changed, awaiting re-verification';
```

(The no-op early-return above it — `if (abs($newTotal - $old) < 0.005) return;` — already protects the unchanged case.)

- [ ] **Step 8: Run to verify pass**

```powershell
php artisan test --filter=BudgetServiceTest
```

Expected: all pass (26 tests).

- [ ] **Step 9: Commit**

```powershell
git add app/Exceptions/UnverifiedBudgetException.php app/Services/BudgetService.php tests/Feature/Budget/BudgetTestCase.php tests/Feature/Budget/BudgetServiceTest.php
git commit -m "feat(verify): BudgetService blocks unverified budgets; total change resets verification

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 3: Permission features + routes + auditor carve-out

**Files:**
- Modify: `back-end/database/seeders/PermissionSeeder.php`
- Modify: `back-end/app/Http/Middleware/AuditorReadOnly.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/Budget/VerificationPermissionTest.php` (create)

- [ ] **Step 1: Add the two permission features to the matrix**

In `back-end/database/seeders/PermissionSeeder.php`, inside the `matrix()` array (add after the `purchase_request_approval` block):

```php
            'budget_verification' => [
                'read'   => ['admin', 'auditor'],
                'verify' => ['auditor'],
            ],
            'purchase_order_verification' => [
                'read'   => ['admin', 'auditor'],
                'verify' => ['auditor'],
            ],
```

- [ ] **Step 2: Reseed permissions on the TEST DB**

Tests seed `PermissionSeeder` + `RolePermissionsSeeder` in `BudgetTestCase::setUp`, so the new rows appear automatically in test runs. No manual reseed needed for tests. (Dev DB reseed happens in Task 14.)

- [ ] **Step 3: Add the auditor carve-out to AuditorReadOnly**

In `back-end/app/Http/Middleware/AuditorReadOnly.php`, add a regex allowlist for the four verify/reject routes. After the `if (str_starts_with($path, '/api/me/'))` block, add:

```php
        // Verification actions are the auditor's one write capability — the
        // permission middleware on these routes still enforces who may call them.
        if (preg_match('#^/api/(budget-allocations|purchase-orders)/\d+/(verify|reject)$#', $path)) {
            return $next($request);
        }
```

- [ ] **Step 4: Write the permission/carve-out test**

`back-end/tests/Feature/Budget/VerificationPermissionTest.php`:

```php
<?php

namespace Tests\Feature\Budget;

class VerificationPermissionTest extends BudgetTestCase
{
    // These three assert access decisions that resolve in middleware BEFORE the
    // controller method runs, so they pass in this task even though the budget
    // verify/reject endpoints are implemented in Task 4. The success-path
    // permission tests (auditor verifies, admin-with-override) live in Task 4.

    public function test_inventory_analyst_cannot_verify_budget(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $budget = $this->makeBudget(10000, ['verification_status' => 'pending']);

        // 403 from the permission middleware (IA lacks budget_verification.verify).
        $this->actingAs($ia, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/verify")
            ->assertStatus(403);
    }

    public function test_admin_cannot_verify_budget_by_default(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000, ['verification_status' => 'pending']);

        // 403 — admin does not hold verify by default (segregation of duties).
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/verify")
            ->assertStatus(403);
    }

    public function test_auditor_cannot_write_non_verification_endpoint(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000);

        // AuditorReadOnly still blocks all other writes (DELETE budget route exists).
        $this->actingAs($auditor, 'sanctum')
            ->deleteJson("/api/budget-allocations/{$budget->budget_id}")
            ->assertStatus(403);
    }
}
```

- [ ] **Step 5: Register the routes**

In `back-end/routes/api.php`:

(a) Give auditors budget READ. Change the budget read group from `role:admin,inventory_analyst` to include auditor:

```php
    Route::middleware('role:admin,inventory_analyst,auditor')->group(function () {
        Route::get('/budget-allocations', [BudgetAllocationController::class, 'index']);
        Route::get('/budget-allocations/{id}', [BudgetAllocationController::class, 'show']);
        Route::get('/budget-allocations/{id}/available', [BudgetAllocationController::class, 'available']);
        Route::get('/budget-allocations/{id}/transactions', [BudgetAllocationController::class, 'transactions']);
    });
```

(b) Add the budget verify/reject routes (place right after the `role:admin` budget write group):

```php
    Route::middleware('permission:budget_verification,verify')->group(function () {
        Route::patch('/budget-allocations/{id}/verify', [BudgetAllocationController::class, 'verify']);
        Route::patch('/budget-allocations/{id}/reject', [BudgetAllocationController::class, 'reject']);
    });
```

(The PO verify/reject routes are registered in Task 5, alongside their controller methods.)

- [ ] **Step 6: Run the permission tests**

```powershell
php artisan test --filter=VerificationPermissionTest
```

Expected: PASS (3 tests) — all assert middleware-level 403s that resolve before any controller method, so they are green even though the budget verify/reject methods land in Task 4.

- [ ] **Step 7: Commit**

```powershell
git add database/seeders/PermissionSeeder.php app/Http/Middleware/AuditorReadOnly.php routes/api.php tests/Feature/Budget/VerificationPermissionTest.php
git commit -m "feat(verify): verification permission features, auditor carve-out, budget routes

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 4: Budget verify / reject endpoints

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/BudgetAllocationController.php`
- Test: `back-end/tests/Feature/Budget/BudgetVerificationTest.php` (create)

- [ ] **Step 1: Write failing tests**

`back-end/tests/Feature/Budget/BudgetVerificationTest.php`:

```php
<?php

namespace Tests\Feature\Budget;

use App\Events\BudgetChanged;
use Illuminate\Support\Facades\Event;

class BudgetVerificationTest extends BudgetTestCase
{
    public function test_auditor_verifies_budget(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000, ['verification_status' => 'pending']);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/verify", ['note' => 'Checked against Q3 plan'])
            ->assertSuccessful();

        $budget->refresh();
        $this->assertSame('verified', $budget->verification_status);
        $this->assertSame($auditor->user_id, (int) $budget->verified_by);
        $this->assertNotNull($budget->verified_at);
        $this->assertSame('Checked against Q3 plan', $budget->verification_note);
    }

    public function test_auditor_rejects_budget_with_required_reason(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000, ['verification_status' => 'pending']);

        // Missing reason → 422.
        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/reject", [])
            ->assertStatus(422);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/reject", ['reason' => 'Amount exceeds approved plan'])
            ->assertSuccessful();

        $budget->refresh();
        $this->assertSame('rejected', $budget->verification_status);
        $this->assertSame('Amount exceeds approved plan', $budget->verification_note);
    }

    public function test_cannot_verify_budget_you_created(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000, [
            'verification_status' => 'pending',
            'created_by' => $auditor->user_id,
        ]);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/verify")
            ->assertStatus(422);
    }

    public function test_double_verify_is_noop_success(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000); // already verified

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/verify")
            ->assertSuccessful();

        $this->assertSame('verified', $budget->fresh()->verification_status);
    }

    public function test_verify_fires_realtime_signal(): void
    {
        Event::fake([BudgetChanged::class]);
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000, ['verification_status' => 'pending']);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/verify")
            ->assertSuccessful();

        Event::assertDispatched(BudgetChanged::class, fn ($e) =>
            $e->locationId === (int) $budget->location_id && $e->type === 'verification_changed');
    }

    // Success-path permission tests (need the endpoint from this task).
    public function test_admin_with_per_user_override_can_verify_budget(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000, ['verification_status' => 'pending']);

        // Grant budget_verification.verify to this specific admin via override —
        // proves "admin assigns a user through the permission feature".
        $perm = \App\Models\Permission::where('feature', 'budget_verification')
            ->where('action', 'verify')->firstOrFail();
        \App\Models\UserPermissionOverride::create([
            'user_id'       => $admin->user_id,
            'permission_id' => $perm->permission_id,
            'is_granted'    => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/verify")
            ->assertSuccessful();

        $this->assertSame('verified', $budget->fresh()->verification_status);
    }
}
```

Note (verified against the code): `App\Models\UserPermissionOverride` maps to `user_permission_overrides`; `App\Support\PermissionResolver::canUser()` honors an override when its `revoked_at IS NULL` (active), returning `is_granted`. The `create([...])` above leaves `revoked_at` NULL, so the override is active. If `UserPermissionOverride::$fillable` omits any of `user_id`/`permission_id`/`is_granted`, use `forceCreate([...])` instead. Do NOT change the resolver/middleware to make the test pass — the override taking effect IS the requirement being proven.

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=BudgetVerificationTest
```

Expected: FAIL — no `verify`/`reject` methods (405/500).

- [ ] **Step 3: Implement the endpoints**

In `back-end/app/Http/Controllers/Api/BudgetAllocationController.php`, add `use App\Support\RealtimeBroadcaster;` to the imports, and add these two methods (after `transactions()`):

```php
    /** Auditor verifies a budget — required before any PR can draw from it. */
    public function verify(Request $request, $id)
    {
        $budget = BudgetAllocation::find($id);
        if (!$budget) {
            return $this->notFound('Budget allocation not found');
        }
        if ((int) $budget->created_by === (int) $request->user()->user_id) {
            return $this->error('You cannot verify a budget you created', 422);
        }
        if ($budget->verification_status === 'verified') {
            return $this->success($this->withAvailable($budget), 'Budget already verified');
        }

        $budget->update([
            'verification_status' => 'verified',
            'verified_by'         => $request->user()->user_id,
            'verified_at'         => now(),
            'verification_note'   => $request->input('note'),
        ]);

        RealtimeBroadcaster::budgetChanged((int) $budget->location_id, 'verification_changed', [
            'budget_id' => $budget->budget_id,
        ]);

        return $this->success($this->withAvailable($budget->fresh()->load(['location', 'category', 'verifier'])), 'Budget verified');
    }

    /** Auditor rejects a budget with a required reason — stays unusable until re-verified. */
    public function reject(Request $request, $id)
    {
        $budget = BudgetAllocation::find($id);
        if (!$budget) {
            return $this->notFound('Budget allocation not found');
        }
        if ((int) $budget->created_by === (int) $request->user()->user_id) {
            return $this->error('You cannot reject a budget you created', 422);
        }

        try {
            $validated = $request->validate(['reason' => 'required|string|max:500']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $budget->update([
            'verification_status' => 'rejected',
            'verified_by'         => $request->user()->user_id,
            'verified_at'         => now(),
            'verification_note'   => $validated['reason'],
        ]);

        RealtimeBroadcaster::budgetChanged((int) $budget->location_id, 'verification_changed', [
            'budget_id' => $budget->budget_id,
        ]);

        return $this->success($this->withAvailable($budget->fresh()->load(['location', 'category', 'verifier'])), 'Budget rejected');
    }
```

Note: `withAvailable()` is the existing private helper. If it eager-loads specific relations, `verifier` may not be included — that's fine, the fresh load above adds it.

- [ ] **Step 4: Run to verify pass**

```powershell
php artisan test --filter=BudgetVerificationTest
php artisan test --filter=Budget
```

Expected: BudgetVerificationTest 6 pass; whole Budget suite green.

- [ ] **Step 5: Commit**

```powershell
git add app/Http/Controllers/Api/BudgetAllocationController.php tests/Feature/Budget/BudgetVerificationTest.php
git commit -m "feat(verify): budget verify/reject endpoints with self-review + override proof

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 5: PO verify / reject endpoints

**Files:**
- Modify: `back-end/routes/api.php`
- Modify: `back-end/app/Http/Controllers/Api/PurchaseOrderController.php`
- Test: `back-end/tests/Feature/Budget/PoVerificationTest.php` (create)

- [ ] **Step 0: Register the PO verify/reject routes**

In `back-end/routes/api.php`, right after the existing PO routes (the `/purchase-orders/{id}/force` line, before the BUDGET ALLOCATIONS block), add:

```php
    Route::middleware('permission:purchase_order_verification,verify')->group(function () {
        Route::patch('/purchase-orders/{id}/verify', [PurchaseOrderController::class, 'verify']);
        Route::patch('/purchase-orders/{id}/reject', [PurchaseOrderController::class, 'reject']);
    });
```

- [ ] **Step 1: Write failing tests**

`back-end/tests/Feature/Budget/PoVerificationTest.php`:

```php
<?php

namespace Tests\Feature\Budget;

use App\Events\PurchaseOrderChanged;
use Illuminate\Support\Facades\Event;

class PoVerificationTest extends BudgetTestCase
{
    public function test_auditor_verifies_po(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $po = $this->makePo($pr, 4000, 7); // Pending, verification_status defaults to 'pending'

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/purchase-orders/{$po->po_id}/verify", ['note' => 'Matches PR + supplier quote'])
            ->assertSuccessful();

        $po->refresh();
        $this->assertSame('verified', $po->verification_status);
        $this->assertSame($auditor->user_id, (int) $po->verified_by);
        $this->assertNotNull($po->verified_at);
    }

    public function test_auditor_rejects_po_requires_reason(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $po = $this->makePo($pr, 4000, 7);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/purchase-orders/{$po->po_id}/reject", [])
            ->assertStatus(422);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/purchase-orders/{$po->po_id}/reject", ['reason' => 'Supplier not accredited'])
            ->assertSuccessful();

        $po->refresh();
        $this->assertSame('rejected', $po->verification_status);
        $this->assertSame(7, (int) $po->status_id); // remains Pending, flagged for the IA
    }

    public function test_cannot_reject_already_approved_po(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $po = $this->makePo($pr, 4000, 10); // Authorized (already approved)

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/purchase-orders/{$po->po_id}/reject", ['reason' => 'too late'])
            ->assertStatus(422);
    }

    public function test_cannot_verify_po_you_created(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $po = $this->makePo($pr, 4000, 7);
        $po->update(['created_by' => $auditor->user_id]);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/purchase-orders/{$po->po_id}/verify")
            ->assertStatus(422);
    }

    public function test_po_verify_fires_realtime_signal(): void
    {
        Event::fake([PurchaseOrderChanged::class]);
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $po = $this->makePo($pr, 4000, 7);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/purchase-orders/{$po->po_id}/verify")
            ->assertSuccessful();

        Event::assertDispatched(PurchaseOrderChanged::class, fn ($e) => $e->type === 'verification_changed');
    }
}
```

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=PoVerificationTest
```

Expected: FAIL — no `verify`/`reject` methods.

- [ ] **Step 3: Implement**

In `back-end/app/Http/Controllers/Api/PurchaseOrderController.php`, add these methods (after `update()` or near the other actions):

```php
    /** Auditor verifies a PO — required before admin approval and before receiving. */
    public function verify(Request $request, $id)
    {
        $po = PurchaseOrder::find($id);
        if (!$po) {
            return $this->error('Purchase order not found', 404);
        }
        if ((int) $po->created_by === (int) $request->user()->user_id) {
            return $this->error('You cannot verify a purchase order you created', 422);
        }
        if ($po->verification_status === 'verified') {
            return $this->success($po->fresh()->load(self::WITH_RELATIONS), 'Purchase order already verified');
        }

        $po->update([
            'verification_status' => 'verified',
            'verified_by'         => $request->user()->user_id,
            'verified_at'         => now(),
            'verification_note'   => $request->input('note'),
        ]);

        RealtimeBroadcaster::purchaseOrderChanged((int) $po->location_id, 'verification_changed');

        return $this->success($po->fresh()->load(self::WITH_RELATIONS), 'Purchase order verified');
    }

    /** Auditor rejects a PO (before approval) with a required reason; PO stays Pending. */
    public function reject(Request $request, $id)
    {
        $po = PurchaseOrder::find($id);
        if (!$po) {
            return $this->error('Purchase order not found', 404);
        }
        if ((int) $po->created_by === (int) $request->user()->user_id) {
            return $this->error('You cannot reject a purchase order you created', 422);
        }
        if (in_array((int) $po->status_id, [8, 10, 11, 12], true)) {
            return $this->error('This purchase order has already been approved and can no longer be rejected in verification', 422);
        }

        try {
            $validated = $request->validate(['reason' => 'required|string|max:500']);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $po->update([
            'verification_status' => 'rejected',
            'verified_by'         => $request->user()->user_id,
            'verified_at'         => now(),
            'verification_note'   => $validated['reason'],
        ]);

        RealtimeBroadcaster::purchaseOrderChanged((int) $po->location_id, 'verification_changed');

        return $this->success($po->fresh()->load(self::WITH_RELATIONS), 'Purchase order rejected');
    }
```

- [ ] **Step 4: Run to verify pass**

```powershell
php artisan test --filter=PoVerificationTest
```

Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```powershell
git add routes/api.php app/Http/Controllers/Api/PurchaseOrderController.php tests/Feature/Budget/PoVerificationTest.php
git commit -m "feat(verify): PO verify/reject endpoints with self-review + reject-before-approval guards

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 6: PR-side gates (submit + approve)

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseRequestController.php`
- Test: `back-end/tests/Feature/Budget/BudgetEnforcementTest.php` (append)

- [ ] **Step 1: Write failing tests**

Append to `back-end/tests/Feature/Budget/BudgetEnforcementTest.php`:

```php
    public function test_store_rejects_pr_against_unverified_budget(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $budget = $this->makeBudget(10000, ['verification_status' => 'pending']);

        $response = $this->actingAs($ia, 'sanctum')
            ->postJson('/api/purchase-requests', $this->prPayload(['budget_id' => $budget->budget_id]));

        $response->assertStatus(422);
        $this->assertStringContainsString('verified', strtolower($response->json('message')));
    }

    public function test_store_message_reflects_rejected_budget(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $budget = $this->makeBudget(10000, ['verification_status' => 'rejected', 'verification_note' => 'bad amount']);

        $response = $this->actingAs($ia, 'sanctum')
            ->postJson('/api/purchase-requests', $this->prPayload(['budget_id' => $budget->budget_id]));

        $response->assertStatus(422);
        $this->assertStringContainsString('rejected', strtolower($response->json('message')));
    }

    public function test_approve_blocked_when_budget_unverified_after_submission(): void
    {
        // PR was submitted while verified, then the budget's total was changed (reset to pending).
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 13);
        $budget->update(['verification_status' => 'pending']); // simulates a post-submission reset

        $response = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/purchase-requests/{$pr->pr_id}/approve");

        $response->assertStatus(422);
        $this->assertStringContainsString('verified', strtolower($response->json('message')));
        $this->assertSame(13, $pr->fresh()->status_id); // rolled back, still pending
    }
```

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=BudgetEnforcementTest
```

Expected: the 3 new tests fail (store still accepts pending budgets; approve does not yet catch the new exception).

- [ ] **Step 3: Gate store()**

In `PurchaseRequestController::store()`, in the gate-check block (after the active-period check, before the `hasAvailable` check ~line 304), add:

```php
        if ($budget->verification_status !== 'verified') {
            $msg = $budget->verification_status === 'rejected'
                ? "This budget was rejected by the auditor" . ($budget->verification_note ? ": {$budget->verification_note}" : '')
                : "This budget ({$budget->period_label}) has not been verified by an auditor yet";
            return $this->error($msg, 422);
        }
```

- [ ] **Step 4: Catch UnverifiedBudgetException in approve()**

In `PurchaseRequestController::approve()`, add `use App\Exceptions\UnverifiedBudgetException;` to the imports (alongside `InsufficientBudgetException`). In the `try/catch` around the `budgetService->commit()` call, add a catch clause before the `InsufficientBudgetException` catch (order doesn't matter — both map to 422):

```php
        } catch (UnverifiedBudgetException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (InsufficientBudgetException $e) {
            return $this->error($e->getMessage(), 422);
```

- [ ] **Step 5: Run to verify pass**

```powershell
php artisan test --filter=BudgetEnforcementTest
php artisan test --filter=Budget
```

Expected: all pass.

- [ ] **Step 6: Commit**

```powershell
git add app/Http/Controllers/Api/PurchaseRequestController.php tests/Feature/Budget/BudgetEnforcementTest.php
git commit -m "feat(verify): PR submit + approve require a verified budget

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 7: PO approval + receiving gates

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseOrderController.php`
- Modify: `back-end/app/Http/Controllers/Api/ReceivingController.php`
- Modify: `back-end/app/Http/Controllers/Api/InventoryScanTransactionController.php`
- Test: `back-end/tests/Feature/Budget/VerificationGateTest.php` (create)

- [ ] **Step 1: Write failing tests**

`back-end/tests/Feature/Budget/VerificationGateTest.php`:

```php
<?php

namespace Tests\Feature\Budget;

use Illuminate\Support\Facades\DB;

class VerificationGateTest extends BudgetTestCase
{
    public function test_po_cannot_be_authorized_while_unverified(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $po = $this->makePo($pr, 4000, 7); // pending verification

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 10]); // Authorized

        $response->assertStatus(422);
        $this->assertStringContainsString('verified', strtolower($response->json('message')));
        $this->assertSame(7, (int) $po->fresh()->status_id); // unchanged
    }

    public function test_verified_po_can_be_authorized(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $po = $this->makePo($pr, 4000, 7);
        $po->update(['verification_status' => 'verified']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 10])
            ->assertSuccessful();

        $this->assertSame(10, (int) $po->fresh()->status_id);
    }

    public function test_non_approval_update_of_unverified_po_still_allowed(): void
    {
        // Editing notes on a pending, unverified PO must not be blocked.
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $po = $this->makePo($pr, 4000, 7);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/purchase-orders/{$po->po_id}", ['notes' => 'updated note'])
            ->assertSuccessful();
    }

    public function test_receiving_blocked_against_unverified_po(): void
    {
        $wp = $this->makeUser('warehouse_personnel');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $po = $this->makePo($pr, 4000, 10); // authorized but NOT verified (edge: grandfather path not taken)
        $po->update(['verification_status' => 'pending']);

        $productId = $this->makeProduct();
        DB::table('purchase_order_details')->insert([
            'po_id' => $po->po_id, 'product_id' => $productId,
            'quantity_ordered' => 2, 'unit_price' => 2000, 'subtotal' => 4000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($wp, 'sanctum')->postJson('/api/receivings', [
            'pc_id'            => $po->po_id,
            'location_id'      => $this->locationId,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => today()->toDateString(),
            'details'          => [[
                'product_id'        => $productId,
                'quantity_received' => 2,
            ]],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('verified', strtolower($response->json('message')));
    }
}
```

Adaptation note: the receiving payload shape must match `ReceivingController::store` validation — read it first and adjust field names (`pc_id` vs `po_id`, detail keys). If the fixtures for a full receiving are too heavy, assert the gate at the smallest reachable point (the test's goal is proving the 422 gate fires; keep it a real request if feasible). `makeProduct()` here mirrors the helper in `BudgetEnforcementTest` — if it's private there, duplicate a minimal product insert in this file.

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=VerificationGateTest
```

Expected: approval + receiving gate tests fail (no gate yet); the two "allowed" tests may pass already.

- [ ] **Step 3: Gate PO approval in update()**

In `PurchaseOrderController::update()`, after `$validated = $request->validate([...])` and before `DB::beginTransaction();`, add:

```php
            // Approval gate: transitioning to Approved (8) or Authorized (10)
            // requires an auditor's verification first.
            if (isset($validated['status_id'])
                && in_array((int) $validated['status_id'], [8, 10], true)
                && $po->verification_status !== 'verified') {
                return $this->error('This purchase order must be verified by an auditor before approval', 422);
            }
```

- [ ] **Step 4: Gate receiving in ReceivingController**

In `ReceivingController::store()`, read where the PO is resolved from `pc_id`/`po_id` early in the method. Immediately after the PO is loaded (or right after validation, loading it explicitly), add a guard:

```php
            $poForCheck = PurchaseOrder::find($request->input('pc_id') ?? $request->input('po_id'));
            if ($poForCheck && $poForCheck->verification_status !== 'verified') {
                return $this->error('This purchase order must be verified by an auditor before receiving', 422);
            }
```

Place it before any inventory mutation / `DB::beginTransaction()`. `PurchaseOrder` is already imported in this controller.

- [ ] **Step 5: Gate the barcode-scan receiving path**

In `back-end/app/Http/Controllers/Api/InventoryScanTransactionController.php`, find where a receiving scan resolves its PO (search for `PurchaseOrder::` / `po_id` in the receiving/processReceiving path). Before it records received quantities, add the same guard, returning a 422 through whatever response helper the method uses (it uses `ApiResponse` — `$this->error(...)`). If the method signature makes an early return awkward, guard at the entry of `processReceiving`/`store` once the PO id is known:

```php
        $poForCheck = PurchaseOrder::find($poId);
        if ($poForCheck && $poForCheck->verification_status !== 'verified') {
            return $this->error('This purchase order must be verified by an auditor before receiving', 422);
        }
```

Adaptation note: this controller is large — place the guard at the narrowest point where `$poId` is known and a JSON error can still be returned to the client (before inventory rows are written). If the scan flow spans multiple endpoints, gate the one that first commits received stock. Add a covering test only if a reachable endpoint test is feasible; otherwise the ReceivingController test covers the shared rule and a code comment documents the scan guard.

- [ ] **Step 6: Run to verify pass**

```powershell
php artisan test --filter=VerificationGateTest
php artisan test --filter=Budget
```

Expected: all pass.

- [ ] **Step 7: Commit**

```powershell
git add app/Http/Controllers/Api/PurchaseOrderController.php app/Http/Controllers/Api/ReceivingController.php app/Http/Controllers/Api/InventoryScanTransactionController.php tests/Feature/Budget/VerificationGateTest.php
git commit -m "feat(verify): block PO approval + receiving until an auditor verifies the PO

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 8: Remove the fake "Verified By" mass-assignment (backend)

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseOrderController.php`
- Modify: `back-end/app/Http/Controllers/Api/PurchaseRequestController.php`
- Test: `back-end/tests/Feature/Budget/PoVerificationTest.php` (append)

- [ ] **Step 1: Write failing test**

Append to `back-end/tests/Feature/Budget/PoVerificationTest.php`:

```php
    public function test_verified_by_cannot_be_set_via_po_create(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $someone = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $supplierId = (int) \Illuminate\Support\Facades\DB::table('suppliers')
            ->insertGetId(['supplier_name' => 'S' . uniqid(), 'created_at' => now(), 'updated_at' => now()], 'supplier_id');
        $productId = $this->makeProductForPo();

        $response = $this->actingAs($ia, 'sanctum')->postJson('/api/purchase-orders', [
            'supplier_id' => $supplierId,
            'location_id' => $this->locationId,
            'po_number'   => 'PO-' . uniqid(),
            'order_date'  => today()->toDateString(),
            'verified_by' => $someone->user_id, // must be ignored
            'details'     => [[
                'product_id' => $productId, 'quantity_ordered' => 1, 'unit_price' => 100,
            ]],
        ]);

        $response->assertStatus(201);
        $poId = $response->json('data.po_id');
        $this->assertNull(\App\Models\PurchaseOrder::find($poId)->verified_by);
        $this->assertSame('pending', \App\Models\PurchaseOrder::find($poId)->verification_status);
    }

    private function makeProductForPo(): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('products')->insertGetId([
            'product_code' => 'TP-' . uniqid(), 'product_name' => 'PO Product',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
    }
```

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=test_verified_by_cannot_be_set_via_po_create
```

Expected: FAIL — `verified_by` is currently accepted, so the assertion `assertNull` fails.

- [ ] **Step 3: Remove verified_by from PO store + update validation/writes**

In `PurchaseOrderController::store()` and `update()`, delete the line:

```php
                'verified_by'                => 'nullable|exists:users,user_id',
```

from both validators. Because writes use `Arr::except($validated, ['details'])` / `$validated`, dropping it from validation removes it from the written data (it was never explicitly set otherwise).

- [ ] **Step 4: Remove verified_by from PR convertStore**

In `PurchaseRequestController::convertStore()`, remove `'verified_by' => 'nullable|exists:users,user_id',` from the validation and the `'verified_by' => $validated['verified_by'] ?? null,` line from the `PurchaseOrder::create([...])` array (the new PO starts unverified by default).

- [ ] **Step 5: Run to verify pass**

```powershell
php artisan test --filter=PoVerificationTest
php artisan test --filter=Budget
```

Expected: all pass.

- [ ] **Step 6: Commit**

```powershell
git add app/Http/Controllers/Api/PurchaseOrderController.php app/Http/Controllers/Api/PurchaseRequestController.php tests/Feature/Budget/PoVerificationTest.php
git commit -m "fix(verify): verified_by is set only by the auditor action, never by PO/PR input

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 9: Exports + list filters

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseOrderController.php` (export + index filter)
- Modify: `back-end/app/Http/Controllers/Api/PurchaseRequestController.php` (export)
- Modify: `back-end/app/Http/Controllers/Api/BudgetAllocationController.php` (index filter)
- Test: `back-end/tests/Feature/Budget/VerificationExportTest.php` (create)

- [ ] **Step 1: Write failing tests**

`back-end/tests/Feature/Budget/VerificationExportTest.php`:

```php
<?php

namespace Tests\Feature\Budget;

class VerificationExportTest extends BudgetTestCase
{
    public function test_po_export_includes_verification_columns(): void
    {
        $admin = $this->makeUser('admin');
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $po = $this->makePo($pr, 4000, 7);
        $po->update(['verification_status' => 'verified', 'verified_by' => $auditor->user_id, 'verified_at' => now()]);

        $res = $this->actingAs($admin, 'sanctum')->get('/api/purchase-orders/export');
        $res->assertSuccessful();
        $csv = $res->streamedContent();

        $this->assertStringContainsString('Verified By', $csv);
        $this->assertStringContainsString('Verification Status', $csv);
        $this->assertStringContainsString('verified', $csv);
    }

    public function test_budget_index_filters_by_verification_status(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeBudget(10000, ['verification_status' => 'pending']);
        $this->makeBudget(20000); // verified

        $res = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/budget-allocations?verification_status=pending&paginate=false');
        $res->assertSuccessful();

        $statuses = collect($res->json('data'))->pluck('verification_status')->unique()->values()->all();
        $this->assertSame(['pending'], $statuses);
    }
}
```

Adaptation note: confirm the PO export route (`GET /api/purchase-orders/export`) and the budget index `paginate=false` behavior against the controllers before running; adjust the query params/assertions to the real response shape if needed.

- [ ] **Step 2: Run to verify failure**

```powershell
php artisan test --filter=VerificationExportTest
```

Expected: FAIL — export lacks the columns; index ignores the filter.

- [ ] **Step 3: PO export columns**

In `PurchaseOrderController::export()`:
- Add `'verifiedBy'` to the eager-load `with([...])` list (the relation exists).
- Change the header `$csvColumns` so the person columns read as the chain — replace `'Created By', 'Approved By', 'Received By',` with:

```php
            'Created By', 'Verified By', 'Verification Status', 'Verified At', 'Approved By', 'Received By',
```

- In the row callback, compute the verifier name and insert the three new values in the same positions. After the existing `$createdBy`/`$approvedBy`/`$receivedBy` computation add:

```php
                $verifiedBy = $po->verifiedBy
                    ? trim($po->verifiedBy->first_name . ' ' . $po->verifiedBy->last_name) : '';
                $verifiedAt = $po->verified_at ? $po->verified_at->format('Y-m-d') : '';
```

- In BOTH `fputcsv` calls (the empty-details row and the per-detail row), replace the `$createdBy, $approvedBy, $receivedBy,` triple with:

```php
                        $createdBy, $verifiedBy, $po->verification_status, $verifiedAt, $approvedBy, $receivedBy,
```

- [ ] **Step 4: PR export column**

In `PurchaseRequestController::export()`, add `'Budget Verification'` to `$csvColumns` right after `'Budget Period'`. In the row output (both the empty-details and per-detail `fputcsv` calls), right after the `$pr->budget?->period_label ?? ''` value insert:

```php
                        $pr->budget?->verification_status ?? '',
```

(ensure the eager-load already includes `budget` — it does).

- [ ] **Step 5: Index filters**

In `BudgetAllocationController::index()`, add near the other filters:

```php
        if ($request->filled('verification_status')) {
            $query->where('verification_status', $request->input('verification_status'));
        }
```

In `PurchaseOrderController::index()`, add near the other filters:

```php
            if ($request->filled('verification_status')) {
                $query->where('verification_status', $request->input('verification_status'));
            }
```

- [ ] **Step 6: Run to verify pass**

```powershell
php artisan test --filter=VerificationExportTest
php artisan test --filter=Budget
```

Expected: all pass.

- [ ] **Step 7: Commit**

```powershell
git add app/Http/Controllers/Api/PurchaseOrderController.php app/Http/Controllers/Api/PurchaseRequestController.php app/Http/Controllers/Api/BudgetAllocationController.php tests/Feature/Budget/VerificationExportTest.php
git commit -m "feat(verify): verification columns in PO/PR exports + verification_status list filters

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 10: Frontend API methods + PO page (remove fake input, add badges/actions)

**Files:**
- Modify: `frontend/src/admin/services/api.js`
- Modify: `frontend/src/admin/pages/PurchaseOrderPage.jsx`

- [ ] **Step 1: Add API methods**

In `frontend/src/admin/services/api.js`, inside `budgetAllocationsAPI` (after `restore`), add:

```js
  verify: async (id, note) =>
    apiRequest(`/budget-allocations/${id}/verify`, { method: 'PATCH', body: JSON.stringify({ note: note ?? null }) }),

  reject: async (id, reason) =>
    apiRequest(`/budget-allocations/${id}/reject`, { method: 'PATCH', body: JSON.stringify({ reason }) }),
```

Inside `purchaseOrdersAPI`, add:

```js
  verify: async (id, note) =>
    apiRequest(`/purchase-orders/${id}/verify`, { method: 'PATCH', body: JSON.stringify({ note: note ?? null }) }),

  reject: async (id, reason) =>
    apiRequest(`/purchase-orders/${id}/reject`, { method: 'PATCH', body: JSON.stringify({ reason }) }),
```

- [ ] **Step 2: Remove the fake VERIFIED BY dropdown**

In `frontend/src/admin/pages/PurchaseOrderPage.jsx`:
- Remove the `const [verifiedById, setVerifiedById] = useState('');` state (~line 123) and the `setVerifiedById('')` reset (~line 301).
- Remove `verified_by: verifiedById ? Number(verifiedById) : null,` from the create payload (~line 355) and `verifiedById` from that `useCallback` dep array (~line 380).
- Remove the VERIFIED BY `<label>`/`<select>`/hint block (~lines 720-729).
- In the printed-document preview, replace the `verifierUser`/`verifiedByName` derivation (~lines 910-914) so it reads from the SELECTED PO record instead of the removed dropdown state: use `selectedPO?.verifiedBy` (the eager-loaded relation) → `` `${selectedPO.verifiedBy.first_name} ${selectedPO.verifiedBy.last_name}` `` or `''`. Where the doc renders `{verifiedByName}` (~line 1015) keep the variable name but source it from the record.

- [ ] **Step 3: Add verification permission flags**

Near `canApprovePO` (~line 109), add:

```jsx
  const canVerifyPO = typeof can === 'function' && can('purchase_order_verification', 'verify');
```

- [ ] **Step 4: Add a verification badge + verify/reject buttons on each PO card**

Where the PO status note renders (near the approved/rejected notes ~line 558) add a verification badge derived from `po.verification_status`:

```jsx
              {po.verification_status === 'pending' && (
                <div className="po-status-note" style={{ background:'#fffbeb', color:'#92400e' }}>
                  <span>Awaiting auditor verification</span>
                </div>
              )}
              {po.verification_status === 'verified' && (
                <div className="po-status-note po-status-note--approved">
                  <span>Verified by {po.verifiedBy ? `${po.verifiedBy.first_name} ${po.verifiedBy.last_name}` : 'auditor'}{po.verified_at ? ` on ${String(po.verified_at).slice(0,10)}` : ''}</span>
                </div>
              )}
              {po.verification_status === 'rejected' && (
                <div className="po-status-note po-status-note--rejected">
                  <span>Rejected by auditor{po.verification_note ? `: ${po.verification_note}` : ''}</span>
                </div>
              )}
```

For the buttons, next to the existing approve control (~line 532, gated by `canApprovePO`), add a verify/reject pair gated by `canVerifyPO` and shown only while `po.status_id === 7` (Pending) and not yet verified:

```jsx
              {canVerifyPO && po.status_id === 7 && po.verification_status !== 'verified' && (
                <div style={{ display:'flex', gap:8 }}>
                  <button type="button" onClick={() => handleVerifyPO(po)} disabled={poActionLoading === po.po_id}>Verify</button>
                  <button type="button" onClick={() => handleRejectPOVerification(po)} disabled={poActionLoading === po.po_id}>Reject</button>
                </div>
              )}
```

Add the two handlers near `handleApprovePO`:

```jsx
  const handleVerifyPO = useCallback(async (po) => {
    setPoActionLoading(po.po_id);
    try {
      await purchaseOrdersAPI.verify(String(po.po_id));
      await refreshPOStatusList();
      toast.success('Purchase order verified', `${po.po_number} is ready for approval.`);
    } catch (err) {
      toast.error("Couldn't verify PO", err?.message || 'Verification failed.');
    } finally { setPoActionLoading(null); }
  }, [refreshPOStatusList]);

  const handleRejectPOVerification = useCallback(async (po) => {
    const reason = window.prompt('Reason for rejecting this purchase order?');
    if (!reason) return;
    setPoActionLoading(po.po_id);
    try {
      await purchaseOrdersAPI.reject(String(po.po_id), reason);
      await refreshPOStatusList();
      toast.success('Purchase order rejected', `${po.po_number} was sent back.`);
    } catch (err) {
      toast.error("Couldn't reject PO", err?.message || 'Rejection failed.');
    } finally { setPoActionLoading(null); }
  }, [refreshPOStatusList]);
```

(If the page has a nicer reason-capture pattern than `window.prompt` — e.g. `confirmAction` with an input — prefer it. `confirmAction` is already imported.)

- [ ] **Step 5: Verify**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\frontend
npx eslint src/admin/services/api.js src/admin/pages/PurchaseOrderPage.jsx
npm run build
```

Expected: no new lint errors on these files; build succeeds.

- [ ] **Step 6: Commit**

```powershell
git add src/admin/services/api.js src/admin/pages/PurchaseOrderPage.jsx
git commit -m "feat(verify): PO verify/reject UI + badges; remove fake manual Verified-By input

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 11: Budget Management + PR page verification UI

**Files:**
- Modify: `frontend/src/admin/pages/BudgetManagementPage.jsx`
- Modify: `frontend/src/admin/pages/PurchaseRequestPage.jsx`

- [ ] **Step 1: Budget page — permission flag + badge + actions**

In `frontend/src/admin/pages/BudgetManagementPage.jsx`:
- Import/derive `can` from `useAuth` if not already; add `const canVerifyBudget = typeof can === 'function' && can('budget_verification', 'verify');`.
- On each budget card (near the health/period labels ~line 495-560), render a verification badge from `b.verification_status`:

```jsx
              {b.verification_status === 'pending' && (
                <span className="ak-badge" style={{ background:'#fffbeb', color:'#92400e' }}>AWAITING VERIFICATION</span>
              )}
              {b.verification_status === 'verified' && (
                <span className="ak-badge" style={{ background:'#dcfce7', color:'#166534' }}>
                  VERIFIED{b.verifier ? ` — ${b.verifier.first_name} ${b.verifier.last_name}` : ''}
                </span>
              )}
              {b.verification_status === 'rejected' && (
                <span className="ak-badge" style={{ background:'#fee2e2', color:'#991b1b' }}>
                  REJECTED{b.verification_note ? ` — ${b.verification_note}` : ''}
                </span>
              )}
```

- In the `budget-card-actions` area (next to EDIT / View Ledger), add verify/reject buttons gated by `canVerifyBudget` and shown when `b.verification_status !== 'verified'`:

```jsx
              {canVerifyBudget && b.verification_status !== 'verified' && (
                <>
                  <button type="button" className="budget-edit-btn" onClick={() => handleVerifyBudget(b)}>Verify</button>
                  <button type="button" className="budget-edit-btn" onClick={() => handleRejectBudget(b)}>Reject</button>
                </>
              )}
```

- Add the handlers near the other budget handlers:

```jsx
  const handleVerifyBudget = useCallback(async (b) => {
    try {
      await budgetAllocationsAPI.verify(b.budget_id);
      toast.success('Budget verified', `${b.period_label} can now fund requests.`);
      loadBudgets();
    } catch (err) { toast.error('Could not verify budget', err?.message); }
  }, []);

  const handleRejectBudget = useCallback(async (b) => {
    const reason = window.prompt('Reason for rejecting this budget?');
    if (!reason) return;
    try {
      await budgetAllocationsAPI.reject(b.budget_id, reason);
      toast.success('Budget rejected', `${b.period_label} was sent back.`);
      loadBudgets();
    } catch (err) { toast.error('Could not reject budget', err?.message); }
  }, []);
```

(Use the page's real reload function name — it's the one called after edit/create. If it's not `loadBudgets`, match the actual name.)

- [ ] **Step 2: PR page — verification badge + block**

In `frontend/src/admin/pages/PurchaseRequestPage.jsx`, in the budget card render (`renderBudgetCard`, ~line 464-520), when a budget is loaded show its verification state and treat non-verified as blocking. Where `budget` is available:

```jsx
        {budget.verification_status && budget.verification_status !== 'verified' && (
          <div style={{ margin:'0 16px 10px', background:'#fffbeb', border:'1px solid #fbbf24', borderRadius:8, padding:'8px 12px', fontSize:13, color:'#92400e' }}>
            {budget.verification_status === 'rejected'
              ? `This budget was rejected by the auditor${budget.verification_note ? `: ${budget.verification_note}` : ''}.`
              : 'This budget is awaiting auditor verification — you cannot submit a request against it yet.'}
          </div>
        )}
```

Add a computed flag and fold it into the existing submit guards/disable:

```jsx
  const budgetVerified = budget?.verification_status === 'verified';
```

In `handleSubmitPR`, after the existing `overBudget` guard, add:

```jsx
    if (budget && !budgetVerified) {
      toast.error("Couldn't send request", 'This budget has not been verified by an auditor yet.');
      return;
    }
```

and extend the submit button's `disabled` (Task 13 of the budget layer set it to `submitting || loadingBudget || overBudget || Boolean(selectedLocId && !budget)`) to also include `|| (budget && !budgetVerified)`; add `budgetVerified` to the `handleSubmitPR` dep array.

- [ ] **Step 3: Verify**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\frontend
npx eslint src/admin/pages/BudgetManagementPage.jsx src/admin/pages/PurchaseRequestPage.jsx
npm run build
```

Expected: no new lint errors; build succeeds.

- [ ] **Step 4: Commit**

```powershell
git add src/admin/pages/BudgetManagementPage.jsx src/admin/pages/PurchaseRequestPage.jsx
git commit -m "feat(verify): budget verify/reject UI + badges; PR page blocks unverified budgets

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 12: Printed doc verifier + PO detail row + auditor dashboard tiles

**Files:**
- Modify: `frontend/src/admin/utils/poExport.js`
- Modify: `frontend/src/admin/pages/PurchaseOrderPage.jsx` (detail "Verified by" row)
- Modify: `frontend/src/admin/pages/AuditorDashboard.jsx`

- [ ] **Step 1: Printed/Excel doc uses the real verifier**

In `frontend/src/admin/utils/poExport.js`, the payload already carries `verified_by` as a resolved name string (`payload.verified_by`). Ensure the caller (the PO detail export payload builder in `PurchaseOrderPage.jsx` ~line 1306 `verified_by: resolveUserName(d.verified_by)`) resolves from the PO record's `verified_by` id — which is now only set by the auditor. No change needed in poExport.js if the payload is correct; verify the signature line renders blank when unverified (it already does — empty string). If the payload builder referenced the removed dropdown state, point it at the record's `verified_by`.

- [ ] **Step 2: PO detail "Verified by" row**

In the PO detail view of `PurchaseOrderPage.jsx` (where Created By / Approved By are shown), add a row:

```jsx
              <div className="po-detail-meta-row">
                <span className="po-detail-meta-label">Verified By</span>
                <span className="po-detail-meta-value">
                  {selectedPO?.verification_status === 'verified'
                    ? `${selectedPO.verifiedBy ? `${selectedPO.verifiedBy.first_name} ${selectedPO.verifiedBy.last_name}` : 'Auditor'}${selectedPO.verified_at ? ` — ${String(selectedPO.verified_at).slice(0,10)}` : ''}`
                    : selectedPO?.verification_status === 'rejected'
                      ? `Rejected: ${selectedPO.verification_note || ''}`
                      : '—'}
                </span>
              </div>
```

(Match the actual class names used by the surrounding meta rows in this file — mirror the Created By / Approved By markup exactly.)

- [ ] **Step 3: Auditor dashboard queue tiles**

In `frontend/src/admin/pages/AuditorDashboard.jsx`, add two "Awaiting verification" tiles fed by lightweight counts fetched client-side (avoids touching the protected DashboardController). Add state + effect:

```jsx
  const [pendingBudgets, setPendingBudgets] = useState(0);
  const [pendingPos, setPendingPos] = useState(0);

  useEffect(() => {
    budgetAllocationsAPI.getAll({ verification_status: 'pending', per_page: 1 })
      .then((r) => setPendingBudgets(r?.meta?.total ?? (r?.data?.length ?? 0)))
      .catch(() => {});
    purchaseOrdersAPI.getAll({ verification_status: 'pending', per_page: 1 })
      .then((r) => setPendingPos(r?.meta?.total ?? (r?.data?.length ?? 0)))
      .catch(() => {});
  }, []);
```

Add two `<Kpi>` tiles in the `ak-stat-grid` (mirror the existing ones):

```jsx
          <Kpi label="Budgets awaiting verification" value={fmt(pendingBudgets)} tone="amber" icon={<Clock />} />
          <Kpi label="POs awaiting verification" value={fmt(pendingPos)} tone="amber" icon={<Clock />} />
```

Import `budgetAllocationsAPI` and `purchaseOrdersAPI` from `../services/api` at the top. Adaptation: confirm the paginated response exposes a total under `meta.total` (check `ApiResponse::paginated`); if the shape differs, read the total from the correct key. If `tone="amber"` isn't a valid Kpi tone, use an existing one (`crimson`).

- [ ] **Step 4: Verify**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\frontend
npx eslint src/admin/utils/poExport.js src/admin/pages/PurchaseOrderPage.jsx src/admin/pages/AuditorDashboard.jsx
npm run build
```

Expected: no new lint errors; build succeeds.

- [ ] **Step 5: Commit**

```powershell
git add src/admin/utils/poExport.js src/admin/pages/PurchaseOrderPage.jsx src/admin/pages/AuditorDashboard.jsx
git commit -m "feat(verify): real verifier on printed PO + detail row + auditor queue tiles

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Task 13: Full verification + dev DB reseed + walkthrough

**Files:** none new.

- [ ] **Step 1: Full backend suite**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\back-end
php artisan test
```

Expected: green except the 2 known pre-existing `NavBundleTest` failures. Any OTHER failure — especially tests that create budgets/PRs/POs and now hit verification — must be fixed by making the test's fixtures verified (budgets default verified via the test-base change; POs need `verification_status => 'verified'` when the test drives approval/receiving). Never weaken the gates. Investigate each failure before touching anything. Pay special attention to `RealtimeTriggersTest` (it authorizes a PO — that PO now needs to be verified first) and any PO/receiving suite.

- [ ] **Step 2: Frontend build**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\frontend
npm run build
```

Expected: build succeeds (pre-existing `App.test.jsx` jsdom failure aside if you run jest).

- [ ] **Step 3: Reseed permissions on the dev DB**

The two new permission features must exist in the dev DB for the UI/routes to work.

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\back-end
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=RolePermissionsSeeder --force
php artisan config:clear
```

Verify the new rows: `C:\XAMPP1\mysql\bin\mysql.exe -u root airkingV5 -e "SELECT feature, action FROM permissions WHERE feature LIKE '%verification%';"` → shows `budget_verification`/`purchase_order_verification` with read+verify.

- [ ] **Step 4: Manual end-to-end walkthrough**

1. Admin creates a budget → it shows **AWAITING VERIFICATION**; an IA cannot submit a PR against it (blocked).
2. Log in as the auditor → Budget Management shows Verify/Reject; verify it → badge flips to **VERIFIED — {auditor}**.
3. IA submits a PR within the verified budget → succeeds; admin approves → COMMIT in the ledger.
4. Admin edits the budget total → badge returns to **AWAITING VERIFICATION**; new PRs blocked until the auditor re-verifies.
5. IA converts the approved PR to a PO (Pending) → PO shows **Awaiting auditor verification**; admin's approve/authorize is blocked (422).
6. Auditor verifies the PO → admin authorizes → receiving proceeds. (Try receiving an unverified PO → blocked.)
7. Auditor rejects a PO with a reason → PO stays Pending, flagged; admin still can't approve it.
8. Confirm the auditor cannot do any other write (e.g., editing a product 403s), proving the carve-out is narrow.
9. Export a PO CSV → Verified By / Verification Status / Verified At columns are populated; the printed PO document shows the real auditor on the "Verified By" line.
10. Grant an admin the `budget_verification.verify` override in User Permission Overrides → that admin can now verify (proves assign-via-permission).

- [ ] **Step 5: Commit any test fixes**

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory
git add -A
git commit -m "test(verify): align pre-existing suites with PO/budget verification gates

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

Then use superpowers:finishing-a-development-branch.
