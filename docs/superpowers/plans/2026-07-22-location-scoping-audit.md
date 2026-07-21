# Location-Scoping Audit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure every location-tied read endpoint returns only the caller's location for scoped users (branch_manager, warehouse_personnel), while admin/IA/AU see all — closing confirmed gaps and verifying the rest with tests.

**Architecture:** Reuse the existing `ScopesToUserLocation` trait (`->forUser($user)`) for single-location models; add custom `forUser` scopes for dual-location (`InventoryScanEvent`) and relation-scoped (`WarrantyRecord`, `PendingProduct`) models; call `->forUser($request->user())` on each read query. Reference/catalog endpoints are explicitly left unscoped. TDD: a leak test per endpoint drives each change.

**Tech Stack:** Laravel (PHP), PHPUnit feature tests on `airkingV5_test` via `DatabaseTransactions`.

---

## Background / Context

- Policy source of truth: `User::isCentralized()` (`app/Models/User.php:198`) → centralized =
  role_id ∈ {1 admin, 2 inventory_analyst, 5 auditor}; scoped = {3 branch_manager,
  4 warehouse_personnel} restricted to `users.location_id` (`scopedLocationId()`).
- Single-location scope trait: `app/Models/Concerns/ScopesToUserLocation.php` — filters
  `<table>.location_id = scopedLocationId()`; centralized passthrough; null-location → deny.
- Dual-location reference pattern: `Transfer::scopeForUser` (`app/Models/Transfer.php:112`) —
  `from_location_id OR to_location_id`.
- Location columns confirmed: `WarrantyClaim.location_id`, `BudgetAllocation.location_id`,
  `RestockRecommendation.location_id`, `PurchaseRequest.location_id` (direct);
  `InventoryScanEvent.location_id` + `to_location_id` (dual);
  `PendingProduct.opening_location_id`; `WarrantyRecord` → via `issuance_id`
  (`issuance.location_id`).
- Reference endpoints to leave UNSCOPED (hard exclusion): Customers, Locations, Branches,
  Users, Batch (bundled reference), AuditTrail (rows have no location), catalog
  (products/categories/suppliers).

## File Structure

- Modify models (add trait or custom `forUser`): `InventoryScanEvent`, `WarrantyClaim`,
  `WarrantyRecord`, `BudgetAllocation`, `RestockRecommendation`, `PendingProduct`,
  `PurchaseRequest`.
- Modify controllers (add `->forUser(...)` to read queries): the matching controllers.
- Create tests under `back-end/tests/Feature/Scoping/`.

## Shared test helpers (used by every task)

Every test file in this plan uses these helper methods (paste into each test class):

```php
    protected function loc(string $type = 'warehouse'): \App\Models\Location
    {
        return \App\Models\Location::create([
            'location_code' => 'L-' . uniqid(),
            'location_name' => 'Loc ' . uniqid(),
            'location_type' => $type,
        ]);
    }

    protected function userAt(int $roleId, ?int $locationId): \App\Models\User
    {
        return \App\Models\User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'T',
            'last_name'     => 'U',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
            'location_id'   => $locationId,
        ]);
    }
    // role 3 = branch_manager (scoped), role 1 = admin (centralized).
```

---

## Task 1: Activity Log (scan events) — the confirmed leak

**Files:**
- Modify: `back-end/app/Models/InventoryScanEvent.php`
- Modify: `back-end/app/Http/Controllers/Api/InventoryScanEventController.php` (`index`)
- Test: `back-end/tests/Feature/Scoping/ScanEventScopingTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Scoping/ScanEventScopingTest.php`:

```php
<?php

namespace Tests\Feature\Scoping;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\Product;
use App\Models\InventoryScanEvent;

class ScanEventScopingTest extends TestCase
{
    use DatabaseTransactions;

    // + paste shared helpers loc() and userAt() here

    private function event(int $locationId, ?int $toLocationId = null): void
    {
        $p = Product::create(['product_code' => 'P-' . uniqid(), 'product_name' => 'P', 'product_type' => 'installation_material']);
        InventoryScanEvent::create([
            'transaction_type' => 'receiving',
            'action'           => 'applied',
            'location_id'      => $locationId,
            'to_location_id'   => $toLocationId,
            'product_id'       => $p->product_id,
            'barcode'          => 'B-' . uniqid(),
        ]);
    }

    public function test_scoped_user_sees_only_their_location_events(): void
    {
        $a = $this->loc(); $b = $this->loc();
        $this->event($a->location_id);
        $this->event($b->location_id);

        $bm = $this->userAt(3, $a->location_id);
        $data = $this->actingAs($bm)->getJson('/api/scan-events')->assertStatus(200)->json('data');
        $locs = collect($data)->pluck('location_id')->unique()->all();
        $this->assertContains($a->location_id, $locs);
        $this->assertNotContains($b->location_id, $locs);
    }

    public function test_scoped_user_sees_transfer_where_they_are_destination(): void
    {
        $a = $this->loc(); $b = $this->loc();
        $this->event($a->location_id, $b->location_id); // A -> B transfer event

        $bmB = $this->userAt(3, $b->location_id);
        $data = $this->actingAs($bmB)->getJson('/api/scan-events')->assertStatus(200)->json('data');
        $this->assertNotEmpty($data, 'Destination user should see the incoming transfer event.');
    }

    public function test_admin_sees_all_events(): void
    {
        $a = $this->loc(); $b = $this->loc();
        $this->event($a->location_id);
        $this->event($b->location_id);

        $admin = $this->userAt(1, null);
        $data = $this->actingAs($admin)->getJson('/api/scan-events')->assertStatus(200)->json('data');
        $locs = collect($data)->pluck('location_id')->unique()->all();
        $this->assertContains($a->location_id, $locs);
        $this->assertContains($b->location_id, $locs);
    }
}
```

Note: confirm the route path for scan events (grep `scan-events` in `back-end/routes/api.php`);
adjust the URL in the test if it differs. Also confirm `InventoryScanEvent::create` accepts
these fillable keys — if `barcode`/`action` differ, align with the model's `$fillable`.

- [ ] **Step 2: Run to verify it fails**

Run: `cd back-end && php artisan test --filter=ScanEventScopingTest`
Expected: `test_scoped_user_sees_only_their_location_events` FAILS (B's location present).

- [ ] **Step 3: Add a dual-location `forUser` scope to the model**

In `back-end/app/Models/InventoryScanEvent.php`, add these imports near the top (after the
namespace `use` lines) if not present:

```php
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
```

Add this method inside the class:

```php
    /**
     * Location read-scope (dual-location: source or destination).
     * Centralized users unfiltered; scoped users see events at their location as
     * origin OR destination; scoped users with no location see nothing.
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        if ($user->isCentralized()) {
            return $query;
        }
        $loc = $user->scopedLocationId();
        if ($loc === null) {
            return $query->whereRaw('1 = 0');
        }
        return $query->where(function ($q) use ($loc) {
            $q->where('location_id', $loc)->orWhere('to_location_id', $loc);
        });
    }
```

- [ ] **Step 4: Apply it in the controller**

In `back-end/app/Http/Controllers/Api/InventoryScanEventController.php`, `index`, find:

```php
            $query = InventoryScanEvent::with([
                'product',
                'user',
                'location',
                'toLocation',
            ])->where('action', 'applied');
```

Change to append `->forUser($request->user())`:

```php
            $query = InventoryScanEvent::with([
                'product',
                'user',
                'location',
                'toLocation',
            ])->where('action', 'applied')
              ->forUser($request->user());
```

- [ ] **Step 5: Run to verify pass**

Run: `cd back-end && php artisan test --filter=ScanEventScopingTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Models/InventoryScanEvent.php back-end/app/Http/Controllers/Api/InventoryScanEventController.php back-end/tests/Feature/Scoping/ScanEventScopingTest.php
git commit -m "fix(security): scope Activity Log scan events to the user's location"
```

---

## Task 2: Warranty claims + warranty records

**Files:**
- Modify: `back-end/app/Models/WarrantyClaim.php`, `back-end/app/Models/WarrantyRecord.php`
- Modify: `back-end/app/Http/Controllers/Api/WarrantyClaimController.php` (`index`),
  `back-end/app/Http/Controllers/Api/WarrantyController.php` (`index`)
- Test: `back-end/tests/Feature/Scoping/WarrantyScopingTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Scoping/WarrantyScopingTest.php`:

```php
<?php

namespace Tests\Feature\Scoping;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\Product;
use App\Models\WarrantyClaim;

class WarrantyScopingTest extends TestCase
{
    use DatabaseTransactions;

    // + paste shared helpers loc() and userAt() here

    private function claim(int $locationId): WarrantyClaim
    {
        $p = Product::create(['product_code' => 'P-' . uniqid(), 'product_name' => 'P', 'product_type' => 'appliance']);
        return WarrantyClaim::create([
            'claim_number' => 'WC-' . uniqid(),
            'product_id'   => $p->product_id,
            'location_id'  => $locationId,
            'serial_number'=> 'SN-' . uniqid(),
            'claim_type'   => 'repair',
            'status_id'    => 1,
        ]);
    }

    public function test_scoped_user_sees_only_their_location_claims(): void
    {
        $a = $this->loc(); $b = $this->loc();
        $this->claim($a->location_id);
        $this->claim($b->location_id);

        $bm = $this->userAt(3, $a->location_id);
        $data = $this->actingAs($bm)->getJson('/api/warranty-claims')->assertStatus(200)->json('data');
        $locs = collect($data)->pluck('location_id')->unique()->all();
        $this->assertContains($a->location_id, $locs);
        $this->assertNotContains($b->location_id, $locs);
    }

    public function test_admin_sees_all_claims(): void
    {
        $a = $this->loc(); $b = $this->loc();
        $this->claim($a->location_id);
        $this->claim($b->location_id);

        $admin = $this->userAt(1, null);
        $data = $this->actingAs($admin)->getJson('/api/warranty-claims')->assertStatus(200)->json('data');
        $locs = collect($data)->pluck('location_id')->unique()->all();
        $this->assertContains($a->location_id, $locs);
        $this->assertContains($b->location_id, $locs);
    }
}
```

Confirm route paths (`grep -E "warranty-claims|warranties" back-end/routes/api.php`) and the
`WarrantyClaim` fillable keys; align the create array / URLs if they differ. If `data` is
paginated, use `->json('data.data')` or the wrapper's shape — check one existing warranty
test or the response.

- [ ] **Step 2: Run to verify it fails**

Run: `cd back-end && php artisan test --filter=WarrantyScopingTest`
Expected: the scoped test FAILS (B present).

- [ ] **Step 3: Scope WarrantyClaim (direct location_id)**

In `back-end/app/Models/WarrantyClaim.php`, add the trait. Near the top `use` statements add:

```php
use App\Models\Concerns\ScopesToUserLocation;
```

and inside the class body, add it to the `use` traits line (create one if absent):

```php
    use ScopesToUserLocation;
```

- [ ] **Step 4: Apply in WarrantyClaimController**

In `back-end/app/Http/Controllers/Api/WarrantyClaimController.php`, `index`, find:

```php
        $query = WarrantyClaim::with(['customer', 'product', 'status', 'warranty'])
            ->orderBy('created_at', 'desc');
```

Change to:

```php
        $query = WarrantyClaim::with(['customer', 'product', 'status', 'warranty'])
            ->forUser($request->user())
            ->orderBy('created_at', 'desc');
```

- [ ] **Step 5: Scope WarrantyRecord (via issuance relation)**

`WarrantyRecord` has no `location_id` — it relates through `issuance`. In
`back-end/app/Models/WarrantyRecord.php`, add imports:

```php
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
```

and add a custom scope inside the class:

```php
    /**
     * Location read-scope via the originating issuance's location.
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        if ($user->isCentralized()) {
            return $query;
        }
        $loc = $user->scopedLocationId();
        if ($loc === null) {
            return $query->whereRaw('1 = 0');
        }
        return $query->whereHas('issuance', fn ($q) => $q->where('location_id', $loc));
    }
```

Then in `back-end/app/Http/Controllers/Api/WarrantyController.php`, `index`, find:

```php
        $query = WarrantyRecord::with([
            'product.applianceDetail.brand',
            'customer',
            'status',
            'issuance.location',
        ])->orderBy('created_at', 'desc');
```

Change to:

```php
        $query = WarrantyRecord::with([
            'product.applianceDetail.brand',
            'customer',
            'status',
            'issuance.location',
        ])->forUser($request->user())
          ->orderBy('created_at', 'desc');
```

- [ ] **Step 6: Run to verify pass + commit**

Run: `cd back-end && php artisan test --filter=WarrantyScopingTest`
Expected: PASS.

```bash
git add back-end/app/Models/WarrantyClaim.php back-end/app/Models/WarrantyRecord.php back-end/app/Http/Controllers/Api/WarrantyClaimController.php back-end/app/Http/Controllers/Api/WarrantyController.php back-end/tests/Feature/Scoping/WarrantyScopingTest.php
git commit -m "fix(security): scope warranty claims and records to the user's location"
```

---

## Task 3: Budget allocations + restock recommendations

**Files:**
- Modify: `back-end/app/Models/BudgetAllocation.php`, `back-end/app/Models/RestockRecommendation.php`
- Modify: `back-end/app/Http/Controllers/Api/BudgetAllocationController.php` (`index`),
  `back-end/app/Http/Controllers/Api/RestockRecommendationController.php` (`index`)
- Test: `back-end/tests/Feature/Scoping/BudgetRestockScopingTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Scoping/BudgetRestockScopingTest.php`:

```php
<?php

namespace Tests\Feature\Scoping;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\Product;
use App\Models\BudgetAllocation;
use App\Models\RestockRecommendation;

class BudgetRestockScopingTest extends TestCase
{
    use DatabaseTransactions;

    // + paste shared helpers loc() and userAt() here

    public function test_scoped_user_sees_only_their_location_budgets(): void
    {
        $a = $this->loc(); $b = $this->loc();
        BudgetAllocation::create(['location_id' => $a->location_id, 'amount' => 100, 'period_start' => now()->toDateString(), 'period_end' => now()->addMonth()->toDateString()]);
        BudgetAllocation::create(['location_id' => $b->location_id, 'amount' => 200, 'period_start' => now()->toDateString(), 'period_end' => now()->addMonth()->toDateString()]);

        $bm = $this->userAt(3, $a->location_id);
        $data = $this->actingAs($bm)->getJson('/api/budget-allocations')->assertStatus(200)->json('data');
        $locs = collect($data)->pluck('location_id')->unique()->all();
        $this->assertContains($a->location_id, $locs);
        $this->assertNotContains($b->location_id, $locs);
    }

    public function test_scoped_user_sees_only_their_location_restock(): void
    {
        $a = $this->loc(); $b = $this->loc();
        foreach ([$a, $b] as $l) {
            $p = Product::create(['product_code' => 'P-' . uniqid(), 'product_name' => 'P', 'product_type' => 'installation_material']);
            RestockRecommendation::create([
                'product_id' => $p->product_id, 'location_id' => $l->location_id,
                'current_stock' => 1, 'reorder_level' => 5, 'recommended_quantity' => 10,
                'priority' => 'HIGH', 'avg_daily_demand' => 1,
            ]);
        }

        $bm = $this->userAt(3, $a->location_id);
        $data = $this->actingAs($bm)->getJson('/api/restock-recommendations')->assertStatus(200)->json('data');
        $locs = collect($data)->pluck('location_id')->unique()->all();
        $this->assertContains($a->location_id, $locs);
        $this->assertNotContains($b->location_id, $locs);
    }
}
```

Confirm both route paths (`grep -E "budget-allocations|restock-recommendations" back-end/routes/api.php`)
and each model's required NOT-NULL columns; adjust the create arrays minimally to satisfy the
schema (e.g. a required `status_id`, `run_at`, or amount column name).

- [ ] **Step 2: Run to verify it fails**

Run: `cd back-end && php artisan test --filter=BudgetRestockScopingTest`
Expected: both scoped tests FAIL.

- [ ] **Step 3: Add the trait to both models**

In `back-end/app/Models/BudgetAllocation.php` and
`back-end/app/Models/RestockRecommendation.php`, add:

```php
use App\Models\Concerns\ScopesToUserLocation;
```

near the top `use` lines, and inside each class body:

```php
    use ScopesToUserLocation;
```

- [ ] **Step 4: Apply in both controllers**

In `back-end/app/Http/Controllers/Api/BudgetAllocationController.php`, `index`, find:

```php
        $query = BudgetAllocation::with(['location', 'category', 'creator'])
            ->orderBy('period_start', 'desc');
```

Change to:

```php
        $query = BudgetAllocation::with(['location', 'category', 'creator'])
            ->forUser($request->user())
            ->orderBy('period_start', 'desc');
```

In `back-end/app/Http/Controllers/Api/RestockRecommendationController.php`, `index`, find:

```php
        $query = RestockRecommendation::with('product.consumableDetail')
            ->orderByRaw("FIELD(priority, 'CRITICAL', 'HIGH', 'MEDIUM')")
            ->orderByDesc('avg_daily_demand');
```

Change to:

```php
        $query = RestockRecommendation::with('product.consumableDetail')
            ->forUser($request->user())
            ->orderByRaw("FIELD(priority, 'CRITICAL', 'HIGH', 'MEDIUM')")
            ->orderByDesc('avg_daily_demand');
```

- [ ] **Step 5: Run to verify pass + commit**

Run: `cd back-end && php artisan test --filter=BudgetRestockScopingTest`
Expected: PASS.

```bash
git add back-end/app/Models/BudgetAllocation.php back-end/app/Models/RestockRecommendation.php back-end/app/Http/Controllers/Api/BudgetAllocationController.php back-end/app/Http/Controllers/Api/RestockRecommendationController.php back-end/tests/Feature/Scoping/BudgetRestockScopingTest.php
git commit -m "fix(security): scope budget allocations and restock recommendations to the user's location"
```

---

## Task 4: Purchase requests + pending products (investigate, then scope)

**Files:**
- Modify: `back-end/app/Models/PurchaseRequest.php`, `back-end/app/Models/PendingProduct.php`
- Modify: `back-end/app/Http/Controllers/Api/PurchaseRequestController.php`,
  `back-end/app/Http/Controllers/Api/PendingProductController.php`
- Test: `back-end/tests/Feature/Scoping/PrPendingScopingTest.php` (create)

- [ ] **Step 1: Investigate the PR list semantics**

Read `PurchaseRequestController::index`. It currently filters
`->where('requested_by', $request->user()->user_id)` — so users already see only their own
PRs (no cross-location leak for the personal list). Check whether there is a SECOND read
endpoint (approval queue / all-PRs) in this controller or a related one that returns other
users' PRs. Record which endpoint(s), if any, expose cross-location PRs. If the only PR list
is the personal (own) one, PRs are already safe and this half of the task is a no-op beyond a
confirming test.

- [ ] **Step 2: Write tests**

Create `back-end/tests/Feature/Scoping/PrPendingScopingTest.php` with:
- A test that a branch_manager at A does NOT see another location/user's PR via any PR list
  endpoint found in Step 1 (if such an endpoint exists). If the only list is the personal
  one, write a test asserting a BM sees only their OWN PRs and an admin can see all PRs
  through the appropriate all-PRs endpoint (adjust to the real endpoint).
- A test that a branch_manager at A sees only pending-product registrations opened at A
  (`opening_location_id = A`) and admin sees all.

Use the shared helpers. Seed `PendingProduct` rows at two locations (set `opening_location_id`
and the other required NOT-NULL columns — read the model/table first). Confirm the pending
route (`grep -E "pending-product" back-end/routes/api.php`).

- [ ] **Step 3: Run to verify it fails (pending at least)**

Run: `cd back-end && php artisan test --filter=PrPendingScopingTest`
Expected: the pending-products scoped test FAILS.

- [ ] **Step 4: Scope PendingProduct via `opening_location_id`**

In `back-end/app/Models/PendingProduct.php`, add imports:

```php
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
```

and a custom scope:

```php
    /**
     * Location read-scope by the opening location a registration was created at.
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        if ($user->isCentralized()) {
            return $query;
        }
        $loc = $user->scopedLocationId();
        if ($loc === null) {
            return $query->whereRaw('1 = 0');
        }
        return $query->where('opening_location_id', $loc);
    }
```

In `back-end/app/Http/Controllers/Api/PendingProductController.php`, `index`, find:

```php
        $query = PendingProduct::query()
            ->with(['category', 'brand', 'creator', 'openingLocation'])
            ->where('status', 'pending')
            ->orderByDesc('created_at');
```

Change to add `->forUser($request->user())`:

```php
        $query = PendingProduct::query()
            ->with(['category', 'brand', 'creator', 'openingLocation'])
            ->where('status', 'pending')
            ->forUser($request->user())
            ->orderByDesc('created_at');
```

- [ ] **Step 5: Scope PurchaseRequest ONLY IF Step 1 found a cross-location list**

If Step 1 found a PR list endpoint that exposes other locations' PRs to scoped users, add the
`ScopesToUserLocation` trait to `PurchaseRequest` (it has `location_id`) and apply
`->forUser($request->user())` on that endpoint's query, guarded so it does not double-restrict
the personal "own PRs" list. If no such endpoint exists, make no PurchaseRequest change and
note that in the commit message. Do not remove the existing `requested_by` personal filter.

- [ ] **Step 6: Run to verify pass + commit**

Run: `cd back-end && php artisan test --filter=PrPendingScopingTest`
Expected: PASS.

```bash
git add -A
git commit -m "fix(security): scope pending-product registrations to the user's location (+ PR list if leaking)"
```

---

## Task 5: Verify the already-scoped domains (tests; fix any red)

**Files:**
- Test: `back-end/tests/Feature/Scoping/CoreDomainScopingTest.php` (create)
- Modify (only if a test goes red): the offending controller's read query to add
  `->forUser($request->user())`.

- [ ] **Step 1: Write verification tests for each already-scoped list endpoint**

Create `back-end/tests/Feature/Scoping/CoreDomainScopingTest.php`. For each of these
endpoints, write one test: seed a record at location A and at location B (reuse the receiving
helper pattern from `PoInventoryGroupsTest`/`ReceivingCreatesBatchTest` to create real
inventory/batches, and direct model `create()` for the simpler ones), then assert a
branch_manager at A sees A and not B, and an admin sees both:

- `GET /api/inventory` (InventoryController@index) — seed two `Inventory` rows.
- `GET /api/inventory-batches` (InventoryBatchController@index) — via receiving at A and B.
- `GET /api/po-inventory-groups` (PoInventoryController@index) — already has a scoping-capable
  query; assert BM-at-A sees only A's groups. (This one is expected to already pass.)
- `GET /api/receivings` (ReceivingController@index).
- `GET /api/issuances` (IssuanceController@index).
- `GET /api/sales` (SaleController@index).
- `GET /api/adjustments` (AdjustmentController@index).
- `GET /api/transfers` (TransferController@index) — dual-location: seed an A→B transfer,
  assert BOTH a BM-at-A and a BM-at-B see it; a BM at an unrelated location C does not.
- `GET /api/purchase-orders` (PurchaseOrderController@index).

Confirm each route path in `back-end/routes/api.php` and each response shape (paginated vs
flat) before asserting. Keep each endpoint's test small and independent.

- [ ] **Step 2: Run the tests**

Run: `cd back-end && php artisan test --filter=CoreDomainScopingTest`
Expected: most pass. For any that FAIL (a real leak), note the endpoint.

- [ ] **Step 3: Fix any red endpoint**

For each failing endpoint, add `->forUser($request->user())` to its read query (the model
already uses `ScopesToUserLocation`; controllers listed in the spec's section A already import
it). Re-run until green. If a failure is only a test-shape mismatch (pagination), fix the test,
not the controller.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "test(security): verify location scoping across core inventory/movement endpoints (+ fixes)"
```

---

## Task 6: Guard the reference endpoints (must stay unscoped)

**Files:**
- Test: `back-end/tests/Feature/Scoping/ReferenceUnscopedTest.php` (create)

- [ ] **Step 1: Write guard tests**

Create `back-end/tests/Feature/Scoping/ReferenceUnscopedTest.php`. Seed reference rows tied to
two different locations (or none) and assert a **branch_manager at A still sees all** of:

- `GET /api/customers` — customers are global; a BM sees customers regardless of location.
- `GET /api/locations` — a BM can still see the full locations list (needed for lookups).
- `GET /api/branches` — same.

This locks in the exclusion list so a future change does not accidentally over-scope shared
reference data. Confirm route paths and shapes first.

- [ ] **Step 2: Run + commit**

Run: `cd back-end && php artisan test --filter=ReferenceUnscopedTest`
Expected: PASS (reference data visible to scoped users).

```bash
git add back-end/tests/Feature/Scoping/ReferenceUnscopedTest.php
git commit -m "test(security): lock reference endpoints as intentionally unscoped"
```

---

## Task 7: Full regression

- [ ] **Step 1: Run the whole backend suite**

Run: `cd back-end && php artisan test`
Expected: green. Investigate and fix any regression introduced by the new scopes (most likely
an existing test that logged in as a scoped user and expected cross-location rows — update the
test's user to admin or its expectation to match the new, correct scoping).

- [ ] **Step 2: Commit any regression fixes**

```bash
git add -A
git commit -m "test(security): reconcile suite with location scoping"
```

---

## Self-Review Notes

- **Spec coverage:** Activity Log (Task 1), warranty claims + records (Task 2), budgets +
  restock (Task 3), pending products + PR investigation (Task 4), verify already-scoped core
  domains (Task 5), reference-unscoped guard (Task 6), regression (Task 7) — all spec sections
  covered.
- **Pattern consistency:** single-location models use the `ScopesToUserLocation` trait +
  `->forUser($request->user())`; dual-location (`InventoryScanEvent`) and relation-scoped
  (`WarrantyRecord`, `PendingProduct`) use a custom `scopeForUser` mirroring `Transfer`. Same
  method name `forUser` everywhere.
- **No over-scoping:** Task 6 hard-locks reference endpoints; Task 4 Step 5 avoids breaking the
  PR "own requests" list.
- **Reads only:** no write-path validation changed.
- **Verification-first:** Tasks 5-6 assert behavior before touching code, so already-correct
  endpoints aren't needlessly edited.
