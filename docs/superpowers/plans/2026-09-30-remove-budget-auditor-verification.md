# Remove Auditor Verification From Budgets — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An admin-created budget funds purchase requests immediately, with no auditor verification step, while purchase order verification stays exactly as it is.

**Architecture:** Five ordered tasks. Task 1 swaps the misleading `UnverifiedBudgetException` for an honest `NoActiveBudgetException` while behaviour is still unchanged. Task 2 removes the gate itself from `BudgetService`. Task 3 removes the endpoints, routes and permission. Task 4 drops the database columns and the model surface. Task 5 removes the UI. This order keeps the suite green at every commit: nothing references a dropped column before Task 4 removes it.

**Tech Stack:** Laravel 11 (PHP 8.2) backend with PHPUnit feature tests; React (Vite) frontend; MySQL/MariaDB. Tests run against the `airkingV5_test` database configured in `back-end/phpunit.xml`.

**Spec:** `docs/superpowers/specs/2026-09-30-remove-budget-auditor-verification-design.md`

---

## Before You Start

Backend paths are relative to `back-end/`; frontend paths to `frontend/`.

The test database is separate from the development one. Migrate both:

```bash
# from back-end/
php artisan migrate --force                                # dev DB (airkingvv6)
DB_DATABASE=airkingV5_test php artisan migrate --force      # test DB
```

`--force` is required because `.env` has `APP_ENV=production`.

Run tests from `back-end/` with `php artisan test`.

### File Structure

**Create:**
- `app/Exceptions/NoActiveBudgetException.php` — the one failure that survives: no budget covers today for this warehouse.
- `database/migrations/2026_09_30_000004_drop_verification_from_budget_allocations.php` — drops four columns and the stale permission row.
- `tests/Feature/Budget/BudgetNoVerificationTest.php` — proves the new behaviour: a fresh budget funds a PR, raising a total doesn't block, the verify route is gone, no auditor alert.

**Delete:**
- `app/Exceptions/UnverifiedBudgetException.php`
- `tests/Feature/Budget/BudgetVerificationTest.php`
- `tests/Feature/Budget/BudgetAlertTest.php`

**Deliberately unchanged:** `tests/Feature/Permissions/BudgetAllocationAccessTest.php` (it exercises the `create` action only), `frontend/src/admin/utils/roles.js` and `navBundles.js` (the auditor keeps read access to the page), and `database/ci-schema.sql` / `database/schema/pgsql-schema.sql` (CI imports the dump and then runs the new migration on top, so the dump needs no regeneration).

**Modify:** `app/Services/BudgetService.php`, `app/Models/BudgetAllocation.php`, `app/Http/Controllers/Api/BudgetAllocationController.php`, `app/Http/Controllers/Api/PurchaseRequestController.php`, `app/Http/Controllers/Api/PurchaseOrderController.php`, `app/Http/Controllers/Api/AlertSettingsController.php`, `app/Http/Middleware/AuditorReadOnly.php`, `app/Support/PermissionResolver.php`, `database/seeders/PermissionSeeder.php`, `routes/api.php`, six test files, three frontend files.

---

## Task 1: Replace `UnverifiedBudgetException` with `NoActiveBudgetException`

Two different failures share one exception today: "no budget exists for this warehouse" and "the budget is not verified". Once verification is gone only the first survives, so the message must stop mentioning auditors. Behaviour is otherwise unchanged here — `resolveActiveBudget` still filters on `verified`, so an unverified budget still reads as "no active budget".

**Files:**
- Create: `app/Exceptions/NoActiveBudgetException.php`
- Delete: `app/Exceptions/UnverifiedBudgetException.php`
- Modify: `app/Services/BudgetService.php:6,49-52,74,86-88`
- Modify: `app/Http/Controllers/Api/PurchaseRequestController.php:6,453,572,581-583,596`
- Modify: `app/Http/Controllers/Api/PurchaseOrderController.php:395`
- Test: `tests/Feature/Budget/PoCommitTest.php`, `tests/Feature/Budget/BudgetServiceTest.php`, `tests/Feature/Budget/BudgetEnforcementTest.php`

- [ ] **Step 1: Point the existing tests at the new exception (they must fail)**

In `tests/Feature/Budget/PoCommitTest.php`, change the import on line 6:

```php
use App\Exceptions\NoActiveBudgetException;
```

and the expectation at line 111:

```php
        $this->expectException(NoActiveBudgetException::class);
```

In `tests/Feature/Budget/BudgetServiceTest.php`, change the import on line 7 the same way and the expectation at line 242:

```php
        $this->expectException(NoActiveBudgetException::class);
```

In `tests/Feature/Budget/BudgetEnforcementTest.php`, three message assertions look for the word "verified". Replace the two store() tests (lines 194-222, including the docblock between them that explains the verified-only filter) with:

```php
    public function test_store_rejects_pr_against_unverified_budget(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $this->makeBudget(10000, ['verification_status' => 'pending']);

        $response = $this->actingAs($ia, 'sanctum')
            ->postJson('/api/purchase-requests', $this->prPayload());

        $response->assertStatus(422);
        $this->assertStringContainsString('no active budget', strtolower($response->json('message')));
    }

    public function test_store_message_reflects_rejected_budget(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $this->makeBudget(10000, ['verification_status' => 'rejected', 'verification_note' => 'bad amount']);

        $response = $this->actingAs($ia, 'sanctum')
            ->postJson('/api/purchase-requests', $this->prPayload());

        $response->assertStatus(422);
        $this->assertStringContainsString('no active budget', strtolower($response->json('message')));
    }
```

and the approve test (lines 224-238) with:

```php
    public function test_approve_blocked_when_budget_unverified_after_submission(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 13);
        $budget->update(['verification_status' => 'pending']); // simulates a post-submission reset

        $response = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/purchase-requests/{$pr->pr_id}/approve");

        $response->assertStatus(422);
        $this->assertStringContainsString('no active budget', strtolower($response->json('message')));
        $this->assertSame(13, $pr->fresh()->status_id); // rolled back, still pending
    }
```

All three of these are deleted in Task 2 — they assert a gate that is about to go. They are updated here only so this commit is green on its own.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test --filter="PoCommitTest|BudgetServiceTest|BudgetEnforcementTest"
```

Expected: FAIL with `Class "App\Exceptions\NoActiveBudgetException" not found`.

- [ ] **Step 3: Create the new exception**

Create `app/Exceptions/NoActiveBudgetException.php`:

```php
<?php

namespace App\Exceptions;

/**
 * No budget covers today for this warehouse. Budgets used to carry an auditor
 * verification state, which collapsed "unverified" and "absent" into one error;
 * verification is gone, so absence is the only remaining failure.
 */
class NoActiveBudgetException extends \RuntimeException
{
    public function __construct(string $context)
    {
        parent::__construct("No active budget for {$context} covering today. Create one before approving.");
    }
}
```

- [ ] **Step 4: Swap the call sites in `BudgetService`**

In `app/Services/BudgetService.php`, change the import on line 6:

```php
use App\Exceptions\NoActiveBudgetException;
```

Replace the throw at line 74:

```php
            throw new NoActiveBudgetException($whName ?? "warehouse #{$locationId}");
```

Delete the verification re-check at lines 86-88 entirely:

```php
            if ($locked->verification_status !== 'verified') {
                throw new UnverifiedBudgetException($locked->period_label, $locked->verification_status);
            }
```

That check only guarded a budget losing verification between resolve and lock — a race that stops existing along with verification.

Update the `commitPo` doc comment (lines 49-52), which still promises a verified budget:

```php
    /**
     * Reserve budget for an approved PO, per destination warehouse. Row-locked,
     * idempotent per (budget_id, po_id); requires an active budget with enough
     * available. Throws NoActiveBudget/Insufficient. PR-agnostic (direct POs too).
     */
```

- [ ] **Step 5: Swap the call sites in the controllers**

In `app/Http/Controllers/Api/PurchaseRequestController.php`, change the import on line 6 to `use App\Exceptions\NoActiveBudgetException;`.

Line 453 — drop "verified" from the store() message:

```php
                return $this->error("{$whName} has no active budget for today.", 422);
```

Line 572:

```php
                            throw new NoActiveBudgetException($whName ?? "warehouse #{$locId}");
```

Lines 581-583 — the legacy direct-`budget_id` branch keeps only the existence check:

```php
                    $budget = \App\Models\BudgetAllocation::find($pr->budget_id);
                    if (!$budget) {
                        $whName = \App\Models\Location::where('location_id', $pr->location_id)->value('location_name');
                        throw new NoActiveBudgetException($whName ?? "warehouse #{$pr->location_id}");
                    }
```

Line 596 — the catch:

```php
        } catch (NoActiveBudgetException $e) {
```

In `app/Http/Controllers/Api/PurchaseOrderController.php`, line 395:

```php
        } catch (\App\Exceptions\NoActiveBudgetException $e) {
```

- [ ] **Step 6: Delete the old exception and confirm nothing references it**

```bash
rm app/Exceptions/UnverifiedBudgetException.php
grep -rn "UnverifiedBudgetException" app routes tests
```

Expected: the grep prints nothing.

- [ ] **Step 7: Run the budget suite to verify it passes**

```bash
php artisan test --filter="Budget"
```

Expected: PASS, all tests.

- [ ] **Step 8: Commit**

```bash
git add app/Exceptions app/Services/BudgetService.php app/Http/Controllers/Api/PurchaseRequestController.php app/Http/Controllers/Api/PurchaseOrderController.php tests/Feature/Budget
git commit -m "refactor(budget): name the surviving failure NoActiveBudgetException"
```

---

## Task 2: Remove the verification gate from `BudgetService`

The behaviour change. After this task an unverified budget funds requests, and editing a budget's total no longer resets anything.

**Files:**
- Modify: `app/Services/BudgetService.php:41,321-330`
- Create: `tests/Feature/Budget/BudgetNoVerificationTest.php`
- Test: `tests/Feature/Budget/BudgetServiceTest.php`, `tests/Feature/Budget/PoCommitTest.php`, `tests/Feature/Budget/BudgetEnforcementTest.php`

- [ ] **Step 1: Write the failing tests for the new behaviour**

Create `tests/Feature/Budget/BudgetNoVerificationTest.php`:

```php
<?php

namespace Tests\Feature\Budget;

/**
 * Budgets no longer pass through an auditor. An admin's allocation funds
 * requests the moment it exists, and editing its total does not take it back
 * out of service.
 */
class BudgetNoVerificationTest extends BudgetTestCase
{
    public function test_a_freshly_created_budget_funds_a_pr_approval(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000, ['verification_status' => 'pending']);
        $pr = $this->makePr($budget, 4000, 13);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/purchase-requests/{$pr->pr_id}/approve")
            ->assertSuccessful();

        $this->assertSame(14, $pr->fresh()->status_id);
    }

    public function test_raising_a_budget_total_does_not_block_the_next_approval(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/budget-allocations/{$budget->budget_id}", ['total_budget' => 15000])
            ->assertSuccessful();

        $pr = $this->makePr($budget->fresh(), 12000, 13);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/purchase-requests/{$pr->pr_id}/approve")
            ->assertSuccessful();
    }

    public function test_resolve_active_budget_returns_a_budget_regardless_of_past_status(): void
    {
        $budget = $this->makeBudget(10000, ['verification_status' => 'rejected']);

        $resolved = app(\App\Services\BudgetService::class)->resolveActiveBudget($this->locationId);

        $this->assertNotNull($resolved);
        $this->assertSame($budget->budget_id, $resolved->budget_id);
    }
}
```

The `verification_status` overrides are deliberate: the column still exists until Task 4, and passing the old blocking values is exactly what proves the gate is gone. Task 4 removes them.

- [ ] **Step 2: Run the new tests to verify they fail**

```bash
php artisan test --filter=BudgetNoVerificationTest
```

Expected: FAIL — `test_a_freshly_created_budget_funds_a_pr_approval` gets 422 "No active budget…", and `test_resolve_active_budget_returns_a_budget_regardless_of_past_status` asserts that null is not null.

- [ ] **Step 3: Drop the `verified` filter from `resolveActiveBudget`**

In `app/Services/BudgetService.php`, delete line 41 so the query reads:

```php
        return BudgetAllocation::where('location_id', $locationId)
            ->where('period_start', '<=', $today)
            ->where('period_end', '>=', $today)
            ->orderByDesc('period_start')
            ->first();
```

- [ ] **Step 4: Drop the verification reset from `adjustTotal`**

In the same file, delete lines 321-330 — the four field assignments and the `verification_pending` broadcast with its comment — so the tail of the closure reads:

```php
            $locked->total_budget = round($newTotal, 2);
            $locked->save();
        });
```

- [ ] **Step 5: Delete the tests that asserted the gate**

From `tests/Feature/Budget/BudgetServiceTest.php`, delete these four methods in full:
- `test_commit_po_throws_when_budget_rejected` (line 234)
- `test_resolve_active_budget_skips_unverified` (line 246)
- `test_adjust_total_change_resets_verification` (line 256)
- `test_adjust_total_noop_does_not_reset_verification` (line 267)

Then drop the `NoActiveBudgetException` import if nothing else in the file uses it:

```bash
grep -n "NoActiveBudgetException" tests/Feature/Budget/BudgetServiceTest.php
```

From `tests/Feature/Budget/PoCommitTest.php`, rewrite `test_commit_po_throws_when_no_verified_budget` (line 104) as an out-of-period test — the failure that still exists:

```php
    public function test_commit_po_throws_when_no_active_budget_covers_today(): void
    {
        $budget = $this->makeBudget(10000, [
            'period_start' => today()->subDays(60)->toDateString(),
            'period_end'   => today()->subDays(30)->toDateString(),
        ]);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, 4000, 7);
        $this->addPoLine($po, 4000);

        $this->expectException(NoActiveBudgetException::class);
        $this->service->commitPo($po->fresh()->load('details'));
    }
```

From `tests/Feature/Budget/BudgetEnforcementTest.php`, delete all three of these in full:
- `test_store_rejects_pr_against_unverified_budget`
- `test_store_message_reflects_rejected_budget`
- `test_approve_blocked_when_budget_unverified_after_submission`

- [ ] **Step 6: Run the budget suite to verify it passes**

```bash
php artisan test --filter="Budget"
```

Expected: PASS. `BudgetNoVerificationTest` runs 3 tests green.

- [ ] **Step 7: Commit**

```bash
git add app/Services/BudgetService.php tests/Feature/Budget
git commit -m "feat(budget): an admin's budget funds requests without auditor verification"
```

---

## Task 3: Remove the verify/reject endpoints, route, permission and alert

**Files:**
- Modify: `app/Http/Controllers/Api/BudgetAllocationController.php:34-36,75-78,110-114,145-146,169-227`
- Modify: `routes/api.php:798-801`
- Modify: `app/Support/PermissionResolver.php:9-20`
- Modify: `database/seeders/PermissionSeeder.php:150-157`
- Modify: `app/Http/Controllers/Api/AlertSettingsController.php:92-104,113,138-145`
- Modify: `app/Http/Middleware/AuditorReadOnly.php:47-51`
- Delete: `tests/Feature/Budget/BudgetVerificationTest.php`, `tests/Feature/Budget/BudgetAlertTest.php`
- Test: `tests/Feature/Budget/BudgetNoVerificationTest.php`, `tests/Feature/Budget/VerificationPermissionTest.php`, `tests/Feature/Budget/VerificationExportTest.php`, `tests/Feature/Budget/BudgetEndpointsTest.php`, `tests/Feature/Permissions/SeederTest.php`

- [ ] **Step 1: Write the failing tests**

Append these two methods inside the class in `tests/Feature/Budget/BudgetNoVerificationTest.php`:

```php
    public function test_the_budget_verify_route_no_longer_exists(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/verify")
            ->assertStatus(404);
    }

    public function test_an_auditor_sees_no_budget_verification_alert(): void
    {
        $auditor = $this->makeUser('auditor');
        $this->makeBudget(1000);

        $res = $this->actingAs($auditor, 'sanctum')->getJson('/api/alerts/settings');
        $res->assertSuccessful();

        $types = collect($res->json('data.alerts'))->pluck('type')->all();
        $this->assertNotContains('budget_verification', $types);
    }
```

- [ ] **Step 2: Run them to verify the first fails**

```bash
php artisan test --filter=BudgetNoVerificationTest
```

Expected: FAIL — `test_the_budget_verify_route_no_longer_exists` gets 200 rather than 404. The alert test may already pass (the fixture budget is verified); it guards against regression once the alert code is gone.

- [ ] **Step 3: Delete the controller methods and their traces**

In `app/Http/Controllers/Api/BudgetAllocationController.php`:

Delete the query filter (lines 34-36):

```php
        if ($request->filled('verification_status')) {
            $query->where('verification_status', $request->input('verification_status'));
        }
```

Delete the broadcast in `store()` (lines 110-114) including its comment:

```php
            // Alert budget verifiers (auditor) that a new budget needs verifying.
            RealtimeBroadcaster::budgetChanged((int) $budget->location_id, 'verification_pending', [
                'budget_id' => $budget->budget_id,
            ]);
```

Delete the two fields from `available()` (lines 145-146):

```php
            'verification_status' => $budget->verification_status,
            'verification_note'   => $budget->verification_note,
```

Delete `verify()` and `reject()` in full (lines 169-227), both doc comments included.

Fix the stale top-up comment in `store()` (lines 75-78), which explains re-opening verification:

```php
            // A budget for the same location + exact period + category is not a
            // new allocation — it tops up the existing one (added to its total,
            // journaled as an adjustment).
```

Then check whether `RealtimeBroadcaster` is still used in the file:

```bash
grep -n "RealtimeBroadcaster" app/Http/Controllers/Api/BudgetAllocationController.php
```

If the only hit left is the `use` statement on line 9, delete that line.

- [ ] **Step 4: Delete the routes**

In `routes/api.php`, delete lines 798-801:

```php
    Route::middleware('permission:budget_verification,verify')->group(function () {
        Route::patch('/budget-allocations/{id}/verify', [BudgetAllocationController::class, 'verify']);
        Route::patch('/budget-allocations/{id}/reject', [BudgetAllocationController::class, 'reject']);
    });
```

- [ ] **Step 5: Remove the permission from the resolver and the seeder**

In `app/Support/PermissionResolver.php`, replace the carve-out block (lines 9-20):

```php
    /**
     * Permissions deliberately carved out of the admin structural bypass:
     * PO verification is auditor-only by separation of duties, so the System
     * Administrator does NOT get it automatically — only via a per-user
     * override (see SeederTest + PoVerificationTest).
     *
     * @var array<int, string>  "feature.action"
     */
    private const ADMIN_BYPASS_EXCLUDED = [
        'purchase_order_verification.verify',
    ];
```

In `database/seeders/PermissionSeeder.php`, drop the `verify` action and fix the comment (lines 150-157):

```php
            // Budget allocations (page /admin/budget-management). read is the view
            // gate; create/update/archive let admin delegate budget CRUD.
            'budget_verification' => [
                'read'    => ['admin', 'auditor'],
                'create'  => ['admin'],
                'update'  => ['admin'],
                'archive' => ['admin'],
            ],
```

The `budget_verification` feature key itself stays — it still gates budget CRUD and page access via the nav bundle.

- [ ] **Step 6: Remove the alert**

In `app/Http/Controllers/Api/AlertSettingsController.php`, replace the comment at lines 92-96 with one that describes PO verification only, and delete the `$pendingBudgetVerifyCount` assignment at lines 100-104:

```php
        // PO verification queue — surfaced ONLY to users who may verify (auditor by
        // default, or anyone the admin grants the permission to), scoped the same
        // way the rest of that user's data is.
        $resolver = app(\App\Support\PermissionResolver::class);
        $uid = (int) $user->user_id;

        $pendingPoVerifyCount = $resolver->canUser($uid, 'purchase_order_verification', 'verify')
```

Remove it from the badge total (line 113):

```php
        $badgeCount = $unreadCount + $lowStockCount + $outOfStockCount + $overduePoCount
            + $pendingPoVerifyCount;
```

Delete the alert entry (lines 138-145):

```php
            $pendingBudgetVerifyCount > 0 ? [
                'type'     => 'budget_verification',
                'label'    => $pendingBudgetVerifyCount . ' budget(s) awaiting your verification',
                'link'     => '/admin/budget-management',
                'count'    => $pendingBudgetVerifyCount,
                'severity' => 'warning',
            ] : null,
```

- [ ] **Step 7: Narrow the auditor write exemption**

In `app/Http/Middleware/AuditorReadOnly.php`, lines 47-51 become:

```php
        // PO verification is the auditor's one write capability — the permission
        // middleware on these routes still enforces who may call them.
        if (preg_match('#^/api/purchase-orders/\d+/(verify|reject)$#', $path)) {
            return $next($request);
        }
```

- [ ] **Step 8: Delete and update the affected tests**

```bash
rm tests/Feature/Budget/BudgetVerificationTest.php
rm tests/Feature/Budget/BudgetAlertTest.php
```

`BudgetAlertTest` goes entirely — all four of its tests are about budget verification, and the replacement alert test now lives in `BudgetNoVerificationTest`.

From `tests/Feature/Budget/VerificationPermissionTest.php`, delete `test_inventory_analyst_cannot_verify_budget` (line 11) and `test_admin_cannot_verify_budget_by_default` (line 21). Keep `test_auditor_cannot_write_non_verification_endpoint` — an auditor still may not delete a budget. Remove any import left unused.

From `tests/Feature/Budget/VerificationExportTest.php`, delete `test_budget_index_filters_by_verification_status` (lines 25-36). The PO export test above it stays.

From `tests/Feature/Budget/BudgetEndpointsTest.php`, delete `test_available_endpoint_exposes_verification_status` (lines 184-194). In `test_store_tops_up_existing_budget_for_same_period`, delete the reset assertion and its comment (around line 220):

```php
        // Top-up voids the prior verification (increased amount needs re-verification).
        $this->assertSame('pending', $existing->verification_status);
```

and reword the fixture comment at line 199 from `// An existing VERIFIED budget for July at this location.` to `// An existing budget for July at this location.`

In `tests/Feature/Permissions/SeederTest.php`, the carve-out assertion counts rows at runtime so it still passes, but the docblock (lines 56-62) names a deleted test and a deleted permission:

```php
    /**
     * (b) Admin must be granted ALL permissions, EXCEPT the verification "verify"
     * action (purchase_order_verification.verify), which is deliberately carved
     * out to auditor-only by role default — admin can only gain it via a
     * per-user permission override. See PoVerificationTest.
     */
```

- [ ] **Step 9: Run the full suite**

```bash
php artisan test
```

Expected: PASS. If `SeederTest::test_admin_is_granted_all_permissions` fails on a count, the test DB still holds the old `budget_verification.verify` row — Task 4's migration deletes it. Note the failure and confirm it clears after Task 4 rather than editing the test.

- [ ] **Step 10: Commit**

```bash
git add app routes database/seeders/PermissionSeeder.php tests
git commit -m "feat(budget): remove the verify/reject endpoints, permission and alert"
```

---

## Task 4: Drop the columns and the model surface

**Files:**
- Create: `database/migrations/2026_09_30_000004_drop_verification_from_budget_allocations.php`
- Modify: `app/Models/BudgetAllocation.php:27-30,41,67-74`
- Modify: `tests/Feature/Budget/BudgetTestCase.php:78`
- Modify: `tests/Feature/Budget/BudgetNoVerificationTest.php`

- [ ] **Step 1: Write the migration**

The per-user override table is `user_permission_overrides` (see `app/Support/PermissionResolver.php:103`). Create `database/migrations/2026_09_30_000004_drop_verification_from_budget_allocations.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Budgets no longer pass through an auditor: an admin's allocation funds
 * requests immediately. Drops the verification columns added by
 * 2026_07_09_000000_add_verification_to_budgets_and_pos, plus the now-unreachable
 * budget_verification.verify permission and its grants.
 *
 * purchase_orders keeps its verification columns — PO verification stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_allocations', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropColumn(['verification_status', 'verified_by', 'verified_at', 'verification_note']);
        });

        // The permission row outlives the seeder change, so without this it keeps
        // appearing in the permission matrix and keeps any existing grant alive.
        $permissionId = DB::table('permissions')
            ->where('feature', 'budget_verification')
            ->where('action', 'verify')
            ->value('permission_id');

        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('user_permission_overrides')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('permission_id', $permissionId)->delete();
        }
    }

    public function down(): void
    {
        Schema::table('budget_allocations', function (Blueprint $table) {
            // 'verified', not 'pending': a rollback must not freeze every budget
            // that has been funding requests since verification was removed.
            $table->string('verification_status', 20)->default('verified')->after('spent');
            $table->unsignedBigInteger('verified_by')->nullable()->after('verification_status');
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->string('verification_note')->nullable()->after('verified_at');
            $table->foreign('verified_by')->references('user_id')->on('users')->nullOnDelete();
        });
    }
};
```

- [ ] **Step 2: Strip the model**

In `app/Models/BudgetAllocation.php`, delete the four fillables (lines 27-30):

```php
        'verification_status',
        'verified_by',
        'verified_at',
        'verification_note',
```

the cast (line 41):

```php
        'verified_at'  => 'datetime',
```

and both `isVerified()` and `verifier()` (lines 67-74):

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

`BelongsTo` is still used by `creator()` and others, so keep the import.

- [ ] **Step 3: Strip the test fixture and the remaining overrides**

In `tests/Feature/Budget/BudgetTestCase.php`, delete line 78 from `makeBudget`:

```php
            'verification_status' => 'verified',
```

In `tests/Feature/Budget/BudgetNoVerificationTest.php`, drop the two overrides that reference the dropped column. In `test_a_freshly_created_budget_funds_a_pr_approval`:

```php
        $budget = $this->makeBudget(10000);
```

and rename the third test, whose premise no longer exists, to:

```php
    public function test_resolve_active_budget_returns_the_current_budget(): void
    {
        $budget = $this->makeBudget(10000);

        $resolved = app(\App\Services\BudgetService::class)->resolveActiveBudget($this->locationId);

        $this->assertNotNull($resolved);
        $this->assertSame($budget->budget_id, $resolved->budget_id);
    }
```

Then sweep for anything missed. Only `purchase_orders` rows may keep the column:

```bash
grep -rn "makeBudget(.*verification" tests
grep -rn "verification_status\|verified_by\|verification_note" tests/Feature/Budget
```

Expected from the first: no output. Expected from the second: only `$po->update([...])` lines and the `PoVerificationTest` / `VerificationExportTest` PO assertions.

- [ ] **Step 4: Migrate both databases**

```bash
php artisan migrate --force
DB_DATABASE=airkingV5_test php artisan migrate --force
```

Expected: `2026_09_30_000004_drop_verification_from_budget_allocations ... DONE` on both.

- [ ] **Step 5: Run the full suite**

```bash
php artisan test
```

Expected: PASS, including `SeederTest::test_admin_is_granted_all_permissions` — the stale permission row is gone now.

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Models/BudgetAllocation.php tests
git commit -m "feat(budget): drop the verification columns and the verify permission"
```

---

## Task 5: Remove the verification UI

No automated frontend tests cover these files, so this task is verified by a build plus a manual pass.

**Files:**
- Modify: `frontend/src/admin/pages/BudgetManagementPage.jsx:124-128,329-353,584-596,706-711,811`
- Modify: `frontend/src/admin/services/api.js:2140-2144`
- Modify: `frontend/src/admin/pages/AuditorDashboard.jsx:8,18,21-28,65`

- [ ] **Step 1: Strip `BudgetManagementPage.jsx`**

Replace lines 124-128 with:

```jsx
  const canReadBudget = typeof can === 'function' && can('budget_verification', 'read');
  // Admin manages budgets (CRUD); a reader (auditor or a granted user) may view
  // them. Both need to reach the page.
  const canView = isAdmin || canReadBudget;
```

Delete lines 329-353 in full — the `// ── Verify / Reject ──` banner comment through the end of `handleRejectBudget`.

Delete the three verification badges (lines 584-596) so only the health and period badges remain:

```jsx
            <span className={`budget-period-badge budget-period-badge--${pStatus.key}`}>
              {pStatus.label}
            </span>
          </div>
```

Delete the buttons (lines 706-711):

```jsx
          {canVerifyBudget && b.verification_status !== 'verified' && (
            <>
              <button type="button" className="budget-edit-btn" onClick={() => handleVerifyBudget(b)}>Verify</button>
              <button type="button" className="budget-edit-btn" onClick={() => handleRejectBudget(b)}>Reject</button>
            </>
          )}
```

Reword the access-denied copy (line 811):

```jsx
            <span>Budget management is restricted to administrators.</span>
```

`confirmWithReason` may now be unused in this file — check and remove its import if so:

```bash
grep -n "confirmWithReason" frontend/src/admin/pages/BudgetManagementPage.jsx
```

- [ ] **Step 2: Strip the API client**

In `frontend/src/admin/services/api.js`, delete the `verify` and `reject` members of `budgetAllocationsAPI` at lines 2140-2144, with their comments:

```js
  verify: async (id, note) =>
    apiRequest(`/budget-allocations/${id}/verify`, { method: 'PATCH', body: JSON.stringify({ note: note ?? null }) }),

  reject: async (id, reason) =>
    apiRequest(`/budget-allocations/${id}/reject`, { method: 'PATCH', body: JSON.stringify({ reason }) }),
```

`purchaseOrdersAPI.verify` / `.reject` (lines 916-920) stay.

- [ ] **Step 3: Strip `AuditorDashboard.jsx`**

Line 8 — drop the unused import:

```jsx
import { purchaseOrdersAPI } from '../services/api';
```

Line 18 — delete:

```jsx
  const [pendingBudgets, setPendingBudgets] = useState(0);
```

Lines 21-28 — delete the budget fetch, leaving:

```jsx
  useEffect(() => {
    purchaseOrdersAPI.getAll({ verification_status: 'pending', per_page: 1 })
      .then((r) => setPendingPos(r?.pagination?.total ?? (Array.isArray(r?.data) ? r.data.length : 0)))
      .catch(() => {});
  }, []);
```

Line 65 — delete the KPI:

```jsx
          <Kpi label="Budgets awaiting verification" value={fmt(pendingBudgets)} tone="crimson" icon={<Clock />} />
```

`Clock` is still used by another KPI, so keep it.

- [ ] **Step 4: Verify nothing dangles**

```bash
grep -rn "budgetAllocationsAPI.verify\|budgetAllocationsAPI.reject\|canVerifyBudget\|handleVerifyBudget\|handleRejectBudget\|pendingBudgets" frontend/src
grep -rn "verification_status\|verification_note\|verifier" frontend/src/admin/pages/BudgetManagementPage.jsx
```

Expected: both print nothing.

- [ ] **Step 5: Build the frontend**

```bash
cd frontend && npm run build
```

Expected: build succeeds with no unresolved-import or undefined-variable errors.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/BudgetManagementPage.jsx frontend/src/admin/services/api.js frontend/src/admin/pages/AuditorDashboard.jsx
git commit -m "feat(budget): remove the verify/reject UI and the auditor budget KPI"
```

---

## Final Verification

- [ ] **Step 1: Full backend suite**

```bash
cd back-end && php artisan test
```

Expected: PASS, zero failures. Record the before/after test counts.

- [ ] **Step 2: Confirm PO verification is untouched**

```bash
php artisan test --filter="PoVerificationTest|VerificationGateTest|VerificationExportTest|PoApproveReserveTest"
```

Expected: PASS. These prove the auditor's PO workflow still gates approval and receiving.

- [ ] **Step 3: Confirm no budget verification references survive**

```bash
grep -rn "budget_verification.verify\|UnverifiedBudgetException" app routes database/seeders tests ../frontend/src
```

Expected: no output. `database/ci-schema.sql` and `database/schema/pgsql-schema.sql` still contain the old columns and permission rows by design — CI imports the dump and then runs the new migration on top.

- [ ] **Step 4: Manual pass**

As admin: create a budget on `/admin/budget-management`, confirm no AWAITING VERIFICATION badge, and confirm it is immediately selectable/fundable on the PR create form. As auditor: confirm the page loads read-only with no Verify/Reject buttons, the dashboard shows no "Budgets awaiting verification" KPI, and PO verification still works.

- [ ] **Step 5: Report**

State what passed, how the test count changed, and anything left undone.
