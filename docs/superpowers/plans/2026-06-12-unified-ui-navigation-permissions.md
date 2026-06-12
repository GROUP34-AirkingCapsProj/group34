# Unified UI + Navigation Permissions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Airking admin app fully permission-driven — one shared UI whose sidebar, dashboard, and data are governed by each user's effective permissions and a single location-scope source of truth, plus a Navigation Bundle system that lets an admin grant/revoke a whole page with one toggle.

**Architecture:** Six sequential phases. (1) A canonical location-scope layer on `User` (`isCentralized()`/`scopedLocationId()`) + a reusable Eloquent read scope. (2) Realign existing write enforcement to that same source of truth. (3) Apply read-scoping to list controllers. (4) Nav-bundle config tables + seeder + read API + frontend mirror. (5) "Navigation Access" Simple tab in the SA user editor. (6) Permission-driven sidebar + dashboard, then retire role-keyed menus.

**Tech Stack:** Laravel 10 (PHPUnit Feature tests, `DatabaseTransactions`), React 18 (CRA / react-scripts test / Jest), Sanctum auth, MySQL.

**Spec:** `docs/superpowers/specs/2026-06-12-unified-ui-navigation-permissions-design.md`

**Conventions observed in this codebase:**
- Backend tests live in `back-end/tests/Feature/...`, namespace `Tests\Feature\...`, extend `Tests\TestCase`, use `Illuminate\Foundation\Testing\DatabaseTransactions`.
- Run a single backend test: `cd back-end && php artisan test --filter=ClassName`
- Frontend tests are `*.test.js(x)` next to source. Run non-interactively: `cd frontend && set CI=true && npx react-scripts test --watchAll=false src/path/to/file.test.js` (PowerShell: `$env:CI='true'; npx react-scripts test --watchAll=false src/...`).
- Role ids: admin=1, inventory_analyst=2, branch_manager=3, warehouse_personnel=4, auditor=5.
- Permission map shape from `/api/me/permissions`: `{ "feature.action": true|false }`.

---

## Phase 1 — Canonical location-scope layer (reads)

### Task 1: `User::isCentralized()` and `User::scopedLocationId()`

**Files:**
- Modify: `back-end/app/Models/User.php`
- Test: `back-end/tests/Feature/Permissions/LocationScopeTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Permissions;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LocationScopeTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(int $roleId, ?int $locationId): User
    {
        return User::create([
            'username' => 'ls_' . uniqid(),
            'first_name' => 'L', 'last_name' => 'S',
            'email' => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id' => $roleId,
            'location_id' => $locationId,
        ]);
    }

    public function test_centralized_roles_have_no_scope(): void
    {
        foreach ([1, 2, 5] as $roleId) {
            $u = $this->makeUser($roleId, 7);
            $this->assertTrue($u->isCentralized(), "role $roleId should be centralized");
            $this->assertNull($u->scopedLocationId(), "role $roleId should have null scope");
        }
    }

    public function test_scoped_roles_return_their_location(): void
    {
        foreach ([3, 4] as $roleId) {
            $u = $this->makeUser($roleId, 7);
            $this->assertFalse($u->isCentralized(), "role $roleId should be scoped");
            $this->assertSame(7, $u->scopedLocationId(), "role $roleId should scope to its location");
        }
    }

    public function test_scoped_user_without_location_returns_null(): void
    {
        $u = $this->makeUser(4, null);
        $this->assertFalse($u->isCentralized());
        $this->assertNull($u->scopedLocationId());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=LocationScopeTest`
Expected: FAIL — `Call to undefined method App\Models\User::isCentralized()`.

- [ ] **Step 3: Add the methods to `User`**

Add to `back-end/app/Models/User.php` (after `hasStrictLocation()`):

```php
    /**
     * Centralized roles see all locations' data and may choose a location on writes.
     * CENTRALIZED = admin(1), inventory_analyst(2), auditor(5). Single source of truth
     * for BOTH read scoping and write enforcement (see spec §5).
     */
    public function isCentralized(): bool
    {
        return in_array((int) $this->role_id, [1, 2, 5], true);
    }

    /**
     * The location_id this user's data is restricted to.
     * Returns null for centralized users (no restriction). For location-scoped
     * roles (branch_manager=3, warehouse_personnel=4) returns users.location_id,
     * which may itself be null when unassigned (callers then deny — spec D3).
     */
    public function scopedLocationId(): ?int
    {
        if ($this->isCentralized()) {
            return null;
        }
        return $this->location_id ? (int) $this->location_id : null;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=LocationScopeTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Models/User.php back-end/tests/Feature/Permissions/LocationScopeTest.php
git commit -m "feat(location-scope): add User::isCentralized + scopedLocationId source of truth"
```

---

### Task 2: Reusable `forUser` query scope

**Files:**
- Create: `back-end/app/Models/Concerns/ScopesToUserLocation.php`
- Modify: `back-end/app/Models/Inventory.php` (add `use ScopesToUserLocation;`)
- Test: `back-end/tests/Feature/Permissions/ForUserScopeTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Permissions;

use App\Models\Inventory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ForUserScopeTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(int $roleId, ?int $locationId): User
    {
        return User::create([
            'username' => 'fu_' . uniqid(), 'first_name' => 'F', 'last_name' => 'U',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'),
            'role_id' => $roleId, 'location_id' => $locationId,
        ]);
    }

    public function test_centralized_user_query_is_unfiltered(): void
    {
        $admin = $this->makeUser(1, null);
        $sql = Inventory::query()->forUser($admin)->toSql();
        $this->assertStringNotContainsString('location_id', $sql);
    }

    public function test_scoped_user_query_filters_by_location(): void
    {
        $wp = $this->makeUser(4, 7);
        $sql = strtolower(Inventory::query()->forUser($wp)->toSql());
        $this->assertStringContainsString('location_id', $sql);
    }

    public function test_scoped_user_without_location_returns_empty(): void
    {
        $wp = $this->makeUser(4, null);
        // 1 = 0 guarantees an empty result set (deny — spec D3)
        $sql = Inventory::query()->forUser($wp)->toSql();
        $this->assertStringContainsString('1 = 0', $sql);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=ForUserScopeTest`
Expected: FAIL — `Call to undefined method ...::forUser()`.

- [ ] **Step 3: Create the trait and wire it into `Inventory`**

Create `back-end/app/Models/Concerns/ScopesToUserLocation.php`:

```php
<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Adds a `forUser` local scope to any model that has a `location_id` column.
 * Centralized users: unfiltered. Scoped users: where location_id = their location.
 * Scoped users with no location: an impossible predicate (empty result) — spec D3.
 */
trait ScopesToUserLocation
{
    public function scopeForUser(Builder $query, User $user): Builder
    {
        if ($user->isCentralized()) {
            return $query;
        }

        $loc = $user->scopedLocationId();
        if ($loc === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($this->getTable() . '.location_id', $loc);
    }
}
```

Add to `back-end/app/Models/Inventory.php` — add the import near the top with the other `use` statements:

```php
use App\Models\Concerns\ScopesToUserLocation;
```

and add the trait inside the class body (next to existing `use` traits, e.g. alongside `HasFactory`):

```php
    use ScopesToUserLocation;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=ForUserScopeTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Models/Concerns/ScopesToUserLocation.php back-end/app/Models/Inventory.php back-end/tests/Feature/Permissions/ForUserScopeTest.php
git commit -m "feat(location-scope): add reusable forUser read scope trait"
```

---

## Phase 2 — Realign write enforcement to the same source of truth

> Spec §5.5 (locked): `isCentralized()`/`scopedLocationId()` govern writes too. Scoped roles (3,4) are forced to their `location_id`; centralized roles (1,2,5) choose a valid active location. This changes today's behavior for IA (was strict-single → now centralized-choose) and BM (was multi-choose → now scoped-single).

### Task 3: Redefine `hasStrictLocation()` via the source of truth

**Files:**
- Modify: `back-end/app/Models/User.php:163-166`
- Test: `back-end/tests/Feature/Permissions/LocationScopeTest.php` (extend)

- [ ] **Step 1: Add a failing test**

Append to `LocationScopeTest`:

```php
    public function test_strict_location_matches_scoped_roles(): void
    {
        $this->assertTrue($this->makeUser(3, 7)->hasStrictLocation());  // branch_manager
        $this->assertTrue($this->makeUser(4, 7)->hasStrictLocation());  // warehouse_personnel
        $this->assertFalse($this->makeUser(1, null)->hasStrictLocation()); // admin
        $this->assertFalse($this->makeUser(2, 7)->hasStrictLocation());    // inventory_analyst
        $this->assertFalse($this->makeUser(5, 7)->hasStrictLocation());    // auditor
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=LocationScopeTest`
Expected: FAIL on `test_strict_location_matches_scoped_roles` — IA(2) currently returns true, BM(3) currently returns false.

- [ ] **Step 3: Redefine the method**

Replace the body of `hasStrictLocation()` in `back-end/app/Models/User.php`:

```php
    /**
     * Roles that must transact at a single enforced location with no override.
     * Now derived from the scope source of truth: strict == location-scoped == !centralized.
     * (branch_manager=3, warehouse_personnel=4)
     */
    public function hasStrictLocation(): bool
    {
        return ! $this->isCentralized();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=LocationScopeTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Models/User.php back-end/tests/Feature/Permissions/LocationScopeTest.php
git commit -m "refactor(location-scope): derive hasStrictLocation from isCentralized"
```

---

### Task 4: Realign `ResolvesUserLocation::resolveLocationId()`

**Files:**
- Modify: `back-end/app/Traits/ResolvesUserLocation.php`
- Test: `back-end/tests/Feature/Permissions/ResolveLocationTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Permissions;

use App\Models\Location;
use App\Models\User;
use App\Traits\ResolvesUserLocation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class ResolveLocationTest extends TestCase
{
    use DatabaseTransactions;

    private object $harness;

    protected function setUp(): void
    {
        parent::setUp();
        // Anonymous class exposing the protected trait methods for testing.
        $this->harness = new class {
            use ResolvesUserLocation;
            public function call(Request $r): ?int { return $this->resolveLocationId($r); }
            public function err(): string { return $this->locationError; }
        };
    }

    private function makeUser(int $roleId, ?int $locationId): User
    {
        return User::create([
            'username' => 'rl_' . uniqid(), 'first_name' => 'R', 'last_name' => 'L',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'),
            'role_id' => $roleId, 'location_id' => $locationId,
        ]);
    }

    private function request(User $u, array $input = []): Request
    {
        $r = Request::create('/x', 'POST', $input);
        $r->setUserResolver(fn () => $u);
        return $r;
    }

    public function test_scoped_user_forced_to_own_location_ignoring_request(): void
    {
        $wp = $this->makeUser(4, 7);
        $this->assertSame(7, $this->harness->call($this->request($wp, ['location_id' => 99])));
    }

    public function test_scoped_user_without_location_denied(): void
    {
        $bm = $this->makeUser(3, null);
        $this->assertNull($this->harness->call($this->request($bm, ['location_id' => 5])));
        $this->assertNotEmpty($this->harness->err());
    }

    public function test_centralized_user_must_supply_active_location(): void
    {
        $loc = Location::create(['location_name' => 'T' . uniqid(), 'location_type' => 'branch', 'is_active' => 1]);
        $ia = $this->makeUser(2, null);
        $this->assertSame((int) $loc->location_id, $this->harness->call($this->request($ia, ['location_id' => $loc->location_id])));
    }

    public function test_centralized_user_without_location_denied(): void
    {
        $admin = $this->makeUser(1, null);
        $this->assertNull($this->harness->call($this->request($admin, [])));
    }
}
```

> Note: if `Location` requires additional non-null columns in this schema, add them to the `Location::create([...])` call to match the migration. Check `back-end/database/migrations/*locations*` if the create fails on a NOT NULL column.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=ResolveLocationTest`
Expected: FAIL — current code treats role 3 as a chooser (returns 99 path differs) and role 2 as strict (ignores request location).

- [ ] **Step 3: Rewrite `resolveLocationId()`**

Replace the body of `resolveLocationId()` in `back-end/app/Traits/ResolvesUserLocation.php` (keep the method signature and `locationErrorResponse()` untouched):

```php
    protected function resolveLocationId(Request $request): ?int
    {
        $user = $request->user();

        if (! $user) {
            $this->locationError = 'Unauthenticated.';
            return null;
        }

        // ── LOCATION-SCOPED ROLES (branch_manager=3, warehouse_personnel=4) ──
        // Forced to their assigned location. Any request location_id is ignored.
        if (! $user->isCentralized()) {
            $loc = $user->scopedLocationId();
            if ($loc === null) {
                $this->locationError =
                    'Your account has no assigned location. '
                    . 'Contact your administrator to assign a location '
                    . 'before performing inventory transactions.';
                return null;
            }
            return $loc;
        }

        // ── CENTRALIZED ROLES (admin=1, inventory_analyst=2, auditor=5) ──
        // Must choose a valid, active location for the transaction.
        $requestedId = $request->input('location_id');
        if (! $requestedId) {
            $this->locationError = 'Please select a location to proceed.';
            return null;
        }

        $exists = \App\Models\Location::where('location_id', (int) $requestedId)
            ->where('is_active', 1)
            ->exists();

        if (! $exists) {
            $this->locationError = 'Selected location is not active.';
            return null;
        }

        return (int) $requestedId;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd back-end && php artisan test --filter=ResolveLocationTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Regression — run the existing scan/permission suites**

Run: `cd back-end && php artisan test --filter=ScanSliceTest && php artisan test --filter=PurchaseRequestApprovalSliceTest`
Expected: PASS. If a test encoded the old IA-strict / BM-chooser behavior, update that test to the new grouping (BM scoped-single, IA centralized-choose) and note it in the commit.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Traits/ResolvesUserLocation.php back-end/tests/Feature/Permissions/ResolveLocationTest.php
git commit -m "refactor(location-scope): realign write resolveLocationId to centralized/scoped grouping"
```

---

## Phase 3 — Apply read-scoping to list controllers

> Each controller change: add `use App\Models\Concerns\ScopesToUserLocation;` to the model (one-time per model) and insert `->forUser($request->user())` into the list query. Centralized users keep an optional explicit `location_id` filter (narrowing within their full rights); the explicit filter must apply AFTER `forUser` so it can only narrow, never widen.

### Task 5: Scope `InventoryController::index`

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/InventoryController.php:19-61`
- Test: `back-end/tests/Feature/Permissions/InventoryScopeTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Permissions;

use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryScopeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_scoped_user_only_sees_their_location_inventory(): void
    {
        $locA = Location::create(['location_name' => 'A' . uniqid(), 'location_type' => 'branch', 'is_active' => 1]);
        $locB = Location::create(['location_name' => 'B' . uniqid(), 'location_type' => 'branch', 'is_active' => 1]);

        $product = Product::first();
        $this->assertNotNull($product, 'Seed at least one product before running this test.');

        Inventory::create(['product_id' => $product->product_id, 'location_id' => $locA->location_id, 'quantity_on_hand' => 5]);
        Inventory::create(['product_id' => $product->product_id, 'location_id' => $locB->location_id, 'quantity_on_hand' => 9]);

        $wp = User::create([
            'username' => 'iv_' . uniqid(), 'first_name' => 'I', 'last_name' => 'V',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'),
            'role_id' => 4, 'location_id' => $locA->location_id,
        ]);
        Sanctum::actingAs($wp);

        $res = $this->getJson('/api/inventory');
        $res->assertOk();
        $locationIds = collect($res->json('data'))->pluck('location_id')->unique()->values()->all();
        $this->assertEquals([$locA->location_id], $locationIds);
    }
}
```

> Adjust `Inventory::create([...])`/`Product` columns if the schema requires more non-null fields (check the inventory + products migrations).

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=InventoryScopeTest`
Expected: FAIL — both locations returned (no scoping yet).

- [ ] **Step 3: Add scoping to the query**

In `InventoryController::index`, change the query builder head from:

```php
        $query = Inventory::query()
            ->whereHas('product')
```

to:

```php
        $query = Inventory::query()
            ->forUser($request->user())
            ->whereHas('product')
```

The existing `if ($request->has('location_id'))` block stays as-is (it only narrows further for centralized users; for scoped users `forUser` has already pinned the location, and the extra equality is harmless).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=InventoryScopeTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/InventoryController.php back-end/tests/Feature/Permissions/InventoryScopeTest.php
git commit -m "feat(location-scope): scope inventory list by user location"
```

---

### Task 6: Scope the remaining list controllers

For each controller below: (a) ensure its primary list model `use`s `ScopesToUserLocation` (add the import + `use ...;` line if missing), and (b) insert `->forUser($request->user())` at the head of the index/list query, before other `where`/`whereHas` clauses. Where the controller method has no `$request`, add `Request $request` to the signature.

- [ ] **Step 1: Write one guard test covering the pattern**

Create `back-end/tests/Feature/Permissions/ListScopingSmokeTest.php`:

```php
<?php

namespace Tests\Feature\Permissions;

use ReflectionClass;
use Tests\TestCase;

class ListScopingSmokeTest extends TestCase
{
    /**
     * Guards that every controller we scoped actually references forUser(),
     * so the scoping isn't accidentally dropped in a future edit.
     */
    public function test_scoped_controllers_call_forUser(): void
    {
        $controllers = [
            \App\Http\Controllers\Api\InventoryController::class,
            \App\Http\Controllers\Api\TransferController::class,
            \App\Http\Controllers\Api\TransferRequestController::class,
            \App\Http\Controllers\Api\ISTController::class,
            \App\Http\Controllers\Api\IssuanceController::class,
            \App\Http\Controllers\Api\ReceivingController::class,
            \App\Http\Controllers\Api\AdjustmentController::class,
            \App\Http\Controllers\Api\TransactionController::class,
            \App\Http\Controllers\Api\SaleController::class,
        ];

        foreach ($controllers as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            $src = file_get_contents($file);
            $this->assertStringContainsString('forUser(', $src, "$class must call forUser() for location scoping");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=ListScopingSmokeTest`
Expected: FAIL — only `InventoryController` contains `forUser(`.

- [ ] **Step 3: Add the trait to each model**

Add `use App\Models\Concerns\ScopesToUserLocation;` (import) and `use ScopesToUserLocation;` (in class body) to each model that has a `location_id` column and isn't already wired:

```
back-end/app/Models/Transfer.php
back-end/app/Models/TransferRequest.php
back-end/app/Models/InterBranchTransfer.php   (model backing ISTController — confirm class name)
back-end/app/Models/Issuance.php
back-end/app/Models/Receiving.php
back-end/app/Models/Adjustment.php
back-end/app/Models/Transaction.php
back-end/app/Models/Sale.php
```

> Confirm each model's actual filename/class via `back-end/app/Models/`. If a list is built from a join/raw query rather than a model with `location_id`, scope it with an explicit `where('<table>.location_id', $request->user()->scopedLocationId())` guarded by `if (! $request->user()->isCentralized())` instead of `forUser`.

- [ ] **Step 4: Insert `->forUser($request->user())` into each list query**

For each controller's index/list method, add `->forUser($request->user())` immediately after `Model::query()` (or convert `Model::with(...)` to `Model::query()->forUser($request->user())->with(...)`). Concretely, per controller:

- `TransferController` — list/index method
- `TransferRequestController` — list/index method
- `ISTController` — list/index method (scope by source or destination location as the domain requires; if both matter, use `->where(fn($q) => $q->where('source_location_id', $loc)->orWhere('destination_location_id', $loc))` guarded by `! isCentralized()`)
- `IssuanceController` — index
- `ReceivingController` — index
- `AdjustmentController` — index
- `TransactionController` — index
- `SaleController` — index

> For ISTController, because transfers span two locations, do NOT use the simple `forUser` (single `location_id` column). Use the guarded source/destination predicate above so a scoped user sees transfers they sent OR received.

- [ ] **Step 5: Run the smoke test + targeted scope assertions**

Run: `cd back-end && php artisan test --filter=ListScopingSmokeTest`
Expected: PASS.

Then run the full permissions + rbac suites for regressions:

Run: `cd back-end && php artisan test --filter=Permissions && php artisan test --filter=Rbac`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Models back-end/app/Http/Controllers/Api back-end/tests/Feature/Permissions/ListScopingSmokeTest.php
git commit -m "feat(location-scope): scope transfer/issuance/receiving/adjustment/sales lists by user location"
```

---

### Task 7: Scope `DashboardController` summaries

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/DashboardController.php`
- Test: `back-end/tests/Feature/Permissions/DashboardScopeTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Permissions;

use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardScopeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_scoped_user_dashboard_counts_only_their_location(): void
    {
        $locA = Location::create(['location_name' => 'DA' . uniqid(), 'location_type' => 'branch', 'is_active' => 1]);
        $locB = Location::create(['location_name' => 'DB' . uniqid(), 'location_type' => 'branch', 'is_active' => 1]);
        $product = Product::first();
        $this->assertNotNull($product);

        Inventory::create(['product_id' => $product->product_id, 'location_id' => $locA->location_id, 'quantity_on_hand' => 1]);
        Inventory::create(['product_id' => $product->product_id, 'location_id' => $locB->location_id, 'quantity_on_hand' => 1]);

        $wp = User::create([
            'username' => 'db_' . uniqid(), 'first_name' => 'D', 'last_name' => 'B',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'),
            'role_id' => 4, 'location_id' => $locA->location_id,
        ]);
        Sanctum::actingAs($wp);

        $res = $this->getJson('/api/dashboard/stats');
        $res->assertOk();
        // The scoped user must not see the global (2-location) total. Exact key
        // depends on the payload; assert the total-distinct-inventory count is 1.
        $payload = $res->json('data');
        $this->assertNotNull($payload);
    }
}
```

> The exact assertion key depends on `DashboardController`'s response shape — read the method first and assert on the concrete count field it returns (e.g. `total_inventory_items`). Replace the soft `assertNotNull` with the precise equality once the field name is confirmed.

- [ ] **Step 2: Run test to verify it fails (or is too-loose)**

Run: `cd back-end && php artisan test --filter=DashboardScopeTest`
Expected: read the controller, identify each count query, confirm at least one is currently global.

- [ ] **Step 3: Apply `forUser` (or the guarded predicate) to each location-bearing aggregate in `DashboardController`**

For every query in the dashboard stats/summary methods that counts or sums location-bearing rows (inventory, transfers, issuances, receivings, sales), add `->forUser($request->user())` (model queries) or the `if (! $request->user()->isCentralized()) $q->where('location_id', $loc)` guard (raw/builder queries). Ensure the method has `Request $request`.

- [ ] **Step 4: Tighten and run the test**

Update the assertion to the concrete count field; run:
`cd back-end && php artisan test --filter=DashboardScopeTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/DashboardController.php back-end/tests/Feature/Permissions/DashboardScopeTest.php
git commit -m "feat(location-scope): scope dashboard aggregates by user location"
```

---

## Phase 4 — Navigation bundle config tables, seeder, API, frontend mirror

### Task 8: Migrations for `nav_permission_bundles` + `nav_bundle_permissions`

**Files:**
- Create: `back-end/database/migrations/2026_06_12_000001_create_nav_permission_bundles_table.php`
- Create: `back-end/database/migrations/2026_06_12_000002_create_nav_bundle_permissions_table.php`
- Test: `back-end/tests/Feature/Permissions/NavBundleSchemaTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Permissions;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NavBundleSchemaTest extends TestCase
{
    public function test_nav_bundle_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('nav_permission_bundles'));
        $this->assertTrue(Schema::hasColumns('nav_permission_bundles', [
            'bundle_id', 'name', 'route_path', 'sidebar_section', 'sidebar_icon', 'description', 'sort_order', 'is_active',
        ]));
        $this->assertTrue(Schema::hasTable('nav_bundle_permissions'));
        $this->assertTrue(Schema::hasColumns('nav_bundle_permissions', [
            'bundle_id', 'permission_id', 'is_required',
        ]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=NavBundleSchemaTest`
Expected: FAIL — tables do not exist.

- [ ] **Step 3: Write the migrations**

`2026_06_12_000001_create_nav_permission_bundles_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('nav_permission_bundles', function (Blueprint $table) {
            $table->id('bundle_id');
            $table->string('name');
            $table->string('route_path')->unique();
            $table->string('sidebar_section');
            $table->string('sidebar_icon')->nullable();
            $table->string('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nav_permission_bundles');
    }
};
```

`2026_06_12_000002_create_nav_bundle_permissions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('nav_bundle_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('bundle_id');
            $table->unsignedBigInteger('permission_id');
            $table->boolean('is_required')->default(false);

            $table->primary(['bundle_id', 'permission_id']);
            $table->foreign('bundle_id')->references('bundle_id')->on('nav_permission_bundles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('permission_id')->on('permissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nav_bundle_permissions');
    }
};
```

- [ ] **Step 4: Migrate and run the test**

Run: `cd back-end && php artisan migrate && php artisan test --filter=NavBundleSchemaTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/database/migrations/2026_06_12_000001_create_nav_permission_bundles_table.php back-end/database/migrations/2026_06_12_000002_create_nav_bundle_permissions_table.php back-end/tests/Feature/Permissions/NavBundleSchemaTest.php
git commit -m "feat(nav-bundles): create nav_permission_bundles + nav_bundle_permissions tables"
```

---

### Task 9: Models for the bundle tables

**Files:**
- Create: `back-end/app/Models/NavPermissionBundle.php`
- Create: `back-end/app/Models/NavBundlePermission.php`
- Test: `back-end/tests/Feature/Permissions/NavBundleModelTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Permissions;

use App\Models\NavPermissionBundle;
use App\Models\Permission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NavBundleModelTest extends TestCase
{
    use DatabaseTransactions;

    public function test_bundle_has_permissions_with_required_pivot(): void
    {
        $perm = Permission::firstOrCreate(['feature' => 'item_master_management', 'action' => 'read'], ['description' => 'x']);
        $bundle = NavPermissionBundle::create([
            'name' => 'Item Master', 'route_path' => '/admin/items-' . uniqid(),
            'sidebar_section' => 'PROCUREMENT & PLANNING', 'sidebar_icon' => 'items', 'sort_order' => 1,
        ]);
        $bundle->permissions()->attach($perm->permission_id, ['is_required' => true]);

        $loaded = NavPermissionBundle::with('permissions')->find($bundle->bundle_id);
        $this->assertCount(1, $loaded->permissions);
        $this->assertEquals(1, (int) $loaded->permissions->first()->pivot->is_required);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=NavBundleModelTest`
Expected: FAIL — model class not found.

- [ ] **Step 3: Create the models**

`back-end/app/Models/NavPermissionBundle.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class NavPermissionBundle extends Model
{
    protected $primaryKey = 'bundle_id';

    protected $fillable = [
        'name', 'route_path', 'sidebar_section', 'sidebar_icon', 'description', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'nav_bundle_permissions',
            'bundle_id',
            'permission_id'
        )->withPivot('is_required');
    }
}
```

`back-end/app/Models/NavBundlePermission.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NavBundlePermission extends Model
{
    protected $table = 'nav_bundle_permissions';
    public $incrementing = false;
    public $timestamps = false;
    protected $primaryKey = null;

    protected $fillable = ['bundle_id', 'permission_id', 'is_required'];

    protected $casts = ['is_required' => 'boolean'];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=NavBundleModelTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Models/NavPermissionBundle.php back-end/app/Models/NavBundlePermission.php back-end/tests/Feature/Permissions/NavBundleModelTest.php
git commit -m "feat(nav-bundles): add NavPermissionBundle + NavBundlePermission models"
```

---

### Task 10: Seeder for the bundle catalog (spec §2)

**Files:**
- Create: `back-end/database/seeders/NavBundleSeeder.php`
- Modify: `back-end/database/seeders/DatabaseSeeder.php` (call NavBundleSeeder after PermissionSeeder)
- Test: `back-end/tests/Feature/Permissions/NavBundleSeederTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Permissions;

use App\Models\NavPermissionBundle;
use Database\Seeders\NavBundleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NavBundleSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_seeder_creates_bundles_with_required_permissions(): void
    {
        (new NavBundleSeeder())->run();

        $items = NavPermissionBundle::where('route_path', '/admin/items')->with('permissions')->first();
        $this->assertNotNull($items, 'Item Master bundle should exist');

        $required = $items->permissions->filter(fn ($p) => (int) $p->pivot->is_required === 1);
        $this->assertTrue(
            $required->contains(fn ($p) => $p->feature === 'item_master_management' && $p->action === 'read'),
            'Item Master read gate should be item_master_management.read'
        );
    }

    public function test_seeder_is_idempotent(): void
    {
        (new NavBundleSeeder())->run();
        $count = NavPermissionBundle::count();
        (new NavBundleSeeder())->run();
        $this->assertEquals($count, NavPermissionBundle::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=NavBundleSeederTest`
Expected: FAIL — seeder class not found.

- [ ] **Step 3: Write the seeder**

`back-end/database/seeders/NavBundleSeeder.php` — encode the §2 table. The `bundles()` array maps `route_path => [name, section, icon, sort, required[], actions[]]` where each permission is `'feature.action'`:

```php
<?php

namespace Database\Seeders;

use App\Models\NavPermissionBundle;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class NavBundleSeeder extends Seeder
{
    public function run(): void
    {
        $order = 0;
        foreach ($this->bundles() as $routePath => $def) {
            $order++;
            $bundle = NavPermissionBundle::updateOrCreate(
                ['route_path' => $routePath],
                [
                    'name' => $def['name'],
                    'sidebar_section' => $def['section'],
                    'sidebar_icon' => $def['icon'],
                    'description' => $def['description'] ?? null,
                    'sort_order' => $def['sort'] ?? $order,
                    'is_active' => true,
                ]
            );

            $sync = [];
            foreach (($def['required'] ?? []) as $key) {
                if ($id = $this->permId($key)) $sync[$id] = ['is_required' => true];
            }
            foreach (($def['actions'] ?? []) as $key) {
                if ($id = $this->permId($key)) {
                    // don't downgrade a required perm if it also appears in actions
                    $sync[$id] = $sync[$id] ?? ['is_required' => false];
                }
            }
            $bundle->permissions()->sync($sync);
        }
    }

    private function permId(string $featureDotAction): ?int
    {
        [$feature, $action] = explode('.', $featureDotAction, 2);
        return Permission::where('feature', $feature)->where('action', $action)->value('permission_id');
    }

    /** Spec §2 — sidebar item → bundle. route_path => definition. */
    private function bundles(): array
    {
        return [
            // ADMINISTRATION
            '/admin/dashboard' => ['name' => 'Dashboard', 'section' => 'ADMINISTRATION', 'icon' => 'dashboard',
                'required' => ['role_dashboards.read'], 'actions' => ['summary_cards.read', 'quick_actions.read']],
            '/admin/approval-queue' => ['name' => 'Approval Queue', 'section' => 'ADMINISTRATION', 'icon' => 'approval',
                'required' => ['purchase_order_approval.read'], 'actions' => ['purchase_order_approval.approve', 'restock_approval.approve', 'item_master_management.approve']],
            '/admin/analytics' => ['name' => 'Analytics Dashboard', 'section' => 'ADMINISTRATION', 'icon' => 'analytics',
                'required' => ['stock_level_analysis.read'], 'actions' => ['consumption_analysis.read', 'inventory_turnover_analysis.read']],
            '/admin/users' => ['name' => 'User Management', 'section' => 'ADMINISTRATION', 'icon' => 'users',
                'required' => ['user_account_management.read'], 'actions' => ['user_account_management.create', 'user_account_management.update', 'user_account_management.archive', 'role_assignment.update']],
            '/admin/roles' => ['name' => 'Role & Permissions', 'section' => 'ADMINISTRATION', 'icon' => 'roles',
                'required' => ['rbac_enforcement.read'], 'actions' => ['role_assignment.update', 'rbac_enforcement.update']],
            '/admin/user-permissions' => ['name' => 'User Overrides', 'section' => 'ADMINISTRATION', 'icon' => 'roles',
                'required' => ['rbac_enforcement.read'], 'actions' => ['rbac_enforcement.update']],
            '/admin/permission-audit' => ['name' => 'Permission Audit', 'section' => 'ADMINISTRATION', 'icon' => 'audit',
                'required' => ['audit_trail_viewing.read']],
            '/admin/branches' => ['name' => 'Location Management', 'section' => 'ADMINISTRATION', 'icon' => 'location',
                'required' => ['location_based_inventory.read'], 'actions' => ['location_based_inventory.create', 'location_based_inventory.update', 'location_based_inventory.archive']],
            '/admin/archive' => ['name' => 'Archive Management', 'section' => 'ADMINISTRATION', 'icon' => 'archive',
                'required' => ['archive_viewing.read'], 'actions' => ['data_archiving.archive']],

            // PROCUREMENT & PLANNING
            '/admin/purchase-requests' => ['name' => 'Purchase Requests', 'section' => 'PROCUREMENT & PLANNING', 'icon' => 'approval',
                'required' => ['purchase_request_creation.read'], 'actions' => ['purchase_request_creation.create', 'purchase_request_approval.approve']],
            '/admin/draft-po-creator' => ['name' => 'Purchase Orders', 'section' => 'PROCUREMENT & PLANNING', 'icon' => 'procurement',
                'required' => ['purchase_order_creation.read'], 'actions' => ['purchase_order_creation.create', 'purchase_order_creation.update', 'supplier_management.read']],
            '/admin/po-recommendations' => ['name' => 'PO Recommendation', 'section' => 'PROCUREMENT & PLANNING', 'icon' => 'po',
                'required' => ['restock_recommendation.read'], 'actions' => ['restock_quantity.read', 'restock_approval.approve']],
            '/admin/receivings' => ['name' => 'PO Receiving', 'section' => 'PROCUREMENT & PLANNING', 'icon' => 'receiving',
                'required' => ['delivery_receiving.read'], 'actions' => ['inventory_receiving.create']],
            '/admin/suppliers' => ['name' => 'Supplier Network', 'section' => 'PROCUREMENT & PLANNING', 'icon' => 'supplier',
                'required' => ['supplier_management.read'], 'actions' => ['supplier_management.create', 'supplier_management.update', 'item_supplier_mapping.update']],
            '/admin/items' => ['name' => 'Item Master', 'section' => 'PROCUREMENT & PLANNING', 'icon' => 'items',
                'required' => ['item_master_management.read'], 'actions' => ['item_master_management.create', 'item_master_management.update', 'item_master_management.archive', 'barcode_assignment.create']],
            '/admin/categories' => ['name' => 'Categories and Brands', 'section' => 'PROCUREMENT & PLANNING', 'icon' => 'category',
                'required' => ['category_management.read'], 'actions' => ['category_management.create', 'category_management.update', 'brand_management.read', 'brand_management.create', 'brand_management.update']],

            // INVENTORY INTELLIGENCE
            '/admin/inventory' => ['name' => 'Inventory Monitoring', 'section' => 'INVENTORY INTELLIGENCE', 'icon' => 'inventory',
                'required' => ['centralized_inventory.read'], 'actions' => ['automated_inventory_monitoring.read', 'location_based_inventory.read']],
            '/admin/transfer-requests' => ['name' => 'Transfer Requests', 'section' => 'INVENTORY INTELLIGENCE', 'icon' => 'transfer',
                'required' => ['inventory_transfers.read'], 'actions' => ['inventory_transfers.create']],
            '/admin/transfers' => ['name' => 'Stock Transfers', 'section' => 'INVENTORY INTELLIGENCE', 'icon' => 'transfer',
                'required' => ['inventory_transfers.read'], 'actions' => ['inventory_transfers.create', 'inventory_transfers.approve']],
            '/admin/ist' => ['name' => 'Interbranch Transfers', 'section' => 'INVENTORY INTELLIGENCE', 'icon' => 'transfer',
                'required' => ['inventory_transfers.read'], 'actions' => ['inventory_transfers.create', 'inventory_transfers.update', 'inventory_transfers.approve']],
            '/admin/adjustments' => ['name' => 'Adjustments', 'section' => 'INVENTORY INTELLIGENCE', 'icon' => 'inventoryop',
                'required' => ['inventory_adjustments.read'], 'actions' => ['inventory_adjustments.create', 'inventory_adjustments.approve']],
            '/admin/issuances' => ['name' => 'Issuances', 'section' => 'INVENTORY INTELLIGENCE', 'icon' => 'issuance-scan',
                'required' => ['inventory_issuance.read'], 'actions' => ['inventory_issuance.create']],

            // SALES & LOGISTICS
            '/admin/transactions' => ['name' => 'POS Operations', 'section' => 'SALES & LOGISTICS', 'icon' => 'transactions',
                'required' => ['pos_sales_recording.read'], 'actions' => ['pos_sales_recording.create', 'barcode_scanning_sales.create', 'sales_validation.create']],
            '/admin/purchase-orders' => ['name' => 'Purchase Orders', 'section' => 'SALES & LOGISTICS', 'icon' => 'purchaseorders',
                'required' => ['purchase_order_approval.read'], 'actions' => ['purchase_order_approval.approve']],
            '/admin/customers' => ['name' => 'Customer Registry', 'section' => 'SALES & LOGISTICS', 'icon' => 'customers',
                'required' => ['customer_info_capture.read'], 'actions' => ['customer_info_capture.create', 'customer_info_capture.update']],
            '/admin/inventory-operation' => ['name' => 'Scan Barcode', 'section' => 'SALES & LOGISTICS', 'icon' => 'scan',
                'required' => ['barcode_inventory_processing.create']],
            '/admin/issuance-scan' => ['name' => 'Issuance Scan', 'section' => 'SALES & LOGISTICS', 'icon' => 'issuance-scan',
                'required' => ['inventory_issuance.create']],
            '/admin/delivery-receipts' => ['name' => 'Delivery Receipts', 'section' => 'SALES & LOGISTICS', 'icon' => 'reports',
                'required' => ['delivery_receipt_generation.read'], 'actions' => ['delivery_receipt_generation.create']],

            // COMPLIANCE & SUPPORT
            '/admin/compliance' => ['name' => 'Compliance Review', 'section' => 'COMPLIANCE & SUPPORT', 'icon' => 'compliance',
                'required' => ['exception_detection.read']],
            '/admin/reports' => ['name' => 'System Reports', 'section' => 'COMPLIANCE & SUPPORT', 'icon' => 'reports',
                'required' => ['inventory_reports.read'], 'actions' => ['transaction_reports.read', 'audit_reports.read', 'export_reports.create']],
            '/admin/audit' => ['name' => 'Audit Trail', 'section' => 'COMPLIANCE & SUPPORT', 'icon' => 'audit',
                'required' => ['audit_trail_viewing.read'], 'actions' => ['transaction_logs_audit.read', 'login_logs.read']],
            '/admin/alerts' => ['name' => 'Alerts & Notices', 'section' => 'COMPLIANCE & SUPPORT', 'icon' => 'notifications',
                'required' => ['notification_panel.read']],
        ];
    }
}
```

> Some `feature.action` keys above must exist in the catalog. If `permId()` returns null for a key, that permission isn't seeded — confirm the key against `PermissionSeeder::matrix()` and either fix the key or skip that action. The seeder already skips null ids safely; the seeder test only asserts the Item Master read gate, so a missing optional action won't fail it. **Do** verify the required-gate keys resolve for every bundle (add a temporary assertion if unsure).

- [ ] **Step 4: Register in DatabaseSeeder**

In `back-end/database/seeders/DatabaseSeeder.php`, add a `NavBundleSeeder` call after the `PermissionSeeder` call (permissions must exist first). Example line inside `run()`:

```php
        $this->call(NavBundleSeeder::class);
```

- [ ] **Step 5: Run the seeder tests**

Run: `cd back-end && php artisan test --filter=NavBundleSeederTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add back-end/database/seeders/NavBundleSeeder.php back-end/database/seeders/DatabaseSeeder.php back-end/tests/Feature/Permissions/NavBundleSeederTest.php
git commit -m "feat(nav-bundles): seed navigation bundle catalog from spec section 2"
```

---

### Task 11: `GET /api/nav-bundles` endpoint

**Files:**
- Create: `back-end/app/Http/Controllers/Api/NavBundleController.php`
- Modify: `back-end/routes/api.php` (register route in the authenticated group)
- Test: `back-end/tests/Feature/Permissions/NavBundleApiTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Permissions;

use App\Models\User;
use Database\Seeders\NavBundleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NavBundleApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_authenticated_user_can_list_bundles_with_permissions(): void
    {
        (new NavBundleSeeder())->run();

        $user = User::create([
            'username' => 'nb_' . uniqid(), 'first_name' => 'N', 'last_name' => 'B',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => 1,
        ]);
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/nav-bundles');
        $res->assertOk();

        $items = collect($res->json('data'))->firstWhere('route_path', '/admin/items');
        $this->assertNotNull($items);
        $this->assertArrayHasKey('permissions', $items);
        $this->assertTrue(collect($items['permissions'])->contains(
            fn ($p) => $p['feature'] === 'item_master_management' && $p['action'] === 'read' && $p['is_required'] === true
        ));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/nav-bundles')->assertStatus(401);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=NavBundleApiTest`
Expected: FAIL — route not found (404/500).

- [ ] **Step 3: Create the controller**

`back-end/app/Http/Controllers/Api/NavBundleController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NavPermissionBundle;
use App\Traits\ApiResponse;

class NavBundleController extends Controller
{
    use ApiResponse;

    /** Bundle catalog with nested permissions (feature, action, is_required). */
    public function index()
    {
        $bundles = NavPermissionBundle::where('is_active', true)
            ->with('permissions:permission_id,feature,action')
            ->orderBy('sidebar_section')
            ->orderBy('sort_order')
            ->get()
            ->map(function ($b) {
                return [
                    'bundle_id' => $b->bundle_id,
                    'name' => $b->name,
                    'route_path' => $b->route_path,
                    'sidebar_section' => $b->sidebar_section,
                    'sidebar_icon' => $b->sidebar_icon,
                    'description' => $b->description,
                    'sort_order' => $b->sort_order,
                    'permissions' => $b->permissions->map(fn ($p) => [
                        'permission_id' => $p->permission_id,
                        'feature' => $p->feature,
                        'action' => $p->action,
                        'is_required' => (bool) $p->pivot->is_required,
                    ])->values(),
                ];
            });

        return $this->success($bundles, 'Navigation bundles');
    }
}
```

- [ ] **Step 4: Register the route**

In `back-end/routes/api.php`, inside the authenticated (`auth:sanctum`) group near the `/me/permissions` route, add:

```php
    Route::get('/nav-bundles', [\App\Http\Controllers\Api\NavBundleController::class, 'index']);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=NavBundleApiTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/NavBundleController.php back-end/routes/api.php back-end/tests/Feature/Permissions/NavBundleApiTest.php
git commit -m "feat(nav-bundles): add GET /api/nav-bundles endpoint"
```

---

### Task 12: Frontend nav-bundle mirror + helper

**Files:**
- Create: `frontend/src/admin/utils/navBundles.js`
- Test: `frontend/src/admin/utils/navBundles.test.js`

> This mirror lets the sidebar render instantly from the already-loaded `permissions` map without waiting on `/api/nav-bundles`. Keep it in sync with `NavBundleSeeder`. The Simple-view tab (Task 13) loads live data from the API; the sidebar (Task 14) uses this mirror.

- [ ] **Step 1: Write the failing test**

```js
import { NAV_BUNDLES, bundleVisible, bundleState } from './navBundles';

test('NAV_BUNDLES contains the Item Master bundle with a read gate', () => {
  const items = NAV_BUNDLES.find((b) => b.routePath === '/admin/items');
  expect(items).toBeTruthy();
  expect(items.required).toContain('item_master_management.read');
});

test('bundleVisible true only when all required perms granted', () => {
  const perms = { 'item_master_management.read': true };
  const items = NAV_BUNDLES.find((b) => b.routePath === '/admin/items');
  expect(bundleVisible(items, perms)).toBe(true);
  expect(bundleVisible(items, {})).toBe(false);
});

test('bundleState reports on/partial/off', () => {
  const reqTwo = { routePath: '/x', required: ['a.read', 'b.read'], actions: [] };
  expect(bundleState(reqTwo, { 'a.read': true, 'b.read': true })).toBe('on');
  expect(bundleState(reqTwo, { 'a.read': true })).toBe('partial');
  expect(bundleState(reqTwo, {})).toBe('off');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && $env:CI='true'; npx react-scripts test --watchAll=false src/admin/utils/navBundles.test.js`
Expected: FAIL — module not found.

- [ ] **Step 3: Create the mirror + helpers**

`frontend/src/admin/utils/navBundles.js` — mirror the seeder. Each entry: `{ name, routePath, section, icon, required: ['feature.action'], actions: ['feature.action'] }`.

```js
/**
 * Frontend mirror of NavBundleSeeder (back-end/database/seeders/NavBundleSeeder.php).
 * Keep in sync with that seeder. `required` = read gate; `actions` = bundled action perms.
 */
export const NAV_BUNDLES = [
  // ADMINISTRATION
  { name: 'Dashboard', routePath: '/admin/dashboard', section: 'ADMINISTRATION', icon: 'dashboard',
    required: ['role_dashboards.read'], actions: ['summary_cards.read', 'quick_actions.read'] },
  { name: 'Approval Queue', routePath: '/admin/approval-queue', section: 'ADMINISTRATION', icon: 'approval',
    required: ['purchase_order_approval.read'], actions: ['purchase_order_approval.approve', 'restock_approval.approve', 'item_master_management.approve'] },
  { name: 'Analytics Dashboard', routePath: '/admin/analytics', section: 'ADMINISTRATION', icon: 'analytics',
    required: ['stock_level_analysis.read'], actions: ['consumption_analysis.read', 'inventory_turnover_analysis.read'] },
  { name: 'User Management', routePath: '/admin/users', section: 'ADMINISTRATION', icon: 'users',
    required: ['user_account_management.read'], actions: ['user_account_management.create', 'user_account_management.update', 'user_account_management.archive', 'role_assignment.update'] },
  { name: 'Role & Permissions', routePath: '/admin/roles', section: 'ADMINISTRATION', icon: 'roles',
    required: ['rbac_enforcement.read'], actions: ['role_assignment.update', 'rbac_enforcement.update'] },
  { name: 'User Overrides', routePath: '/admin/user-permissions', section: 'ADMINISTRATION', icon: 'roles',
    required: ['rbac_enforcement.read'], actions: ['rbac_enforcement.update'] },
  { name: 'Permission Audit', routePath: '/admin/permission-audit', section: 'ADMINISTRATION', icon: 'audit',
    required: ['audit_trail_viewing.read'], actions: [] },
  { name: 'Location Management', routePath: '/admin/branches', section: 'ADMINISTRATION', icon: 'location',
    required: ['location_based_inventory.read'], actions: ['location_based_inventory.create', 'location_based_inventory.update', 'location_based_inventory.archive'] },
  { name: 'Archive Management', routePath: '/admin/archive', section: 'ADMINISTRATION', icon: 'archive',
    required: ['archive_viewing.read'], actions: ['data_archiving.archive'] },
  // PROCUREMENT & PLANNING
  { name: 'Purchase Requests', routePath: '/admin/purchase-requests', section: 'PROCUREMENT & PLANNING', icon: 'approval',
    required: ['purchase_request_creation.read'], actions: ['purchase_request_creation.create', 'purchase_request_approval.approve'] },
  { name: 'Purchase Orders', routePath: '/admin/draft-po-creator', section: 'PROCUREMENT & PLANNING', icon: 'procurement',
    required: ['purchase_order_creation.read'], actions: ['purchase_order_creation.create', 'purchase_order_creation.update', 'supplier_management.read'] },
  { name: 'PO Recommendation', routePath: '/admin/po-recommendations', section: 'PROCUREMENT & PLANNING', icon: 'po',
    required: ['restock_recommendation.read'], actions: ['restock_quantity.read', 'restock_approval.approve'] },
  { name: 'PO Receiving', routePath: '/admin/receivings', section: 'PROCUREMENT & PLANNING', icon: 'receiving',
    required: ['delivery_receiving.read'], actions: ['inventory_receiving.create'] },
  { name: 'Supplier Network', routePath: '/admin/suppliers', section: 'PROCUREMENT & PLANNING', icon: 'supplier',
    required: ['supplier_management.read'], actions: ['supplier_management.create', 'supplier_management.update', 'item_supplier_mapping.update'] },
  { name: 'Item Master', routePath: '/admin/items', section: 'PROCUREMENT & PLANNING', icon: 'items',
    required: ['item_master_management.read'], actions: ['item_master_management.create', 'item_master_management.update', 'item_master_management.archive', 'barcode_assignment.create'] },
  { name: 'Categories and Brands', routePath: '/admin/categories', section: 'PROCUREMENT & PLANNING', icon: 'category',
    required: ['category_management.read'], actions: ['category_management.create', 'category_management.update', 'brand_management.read', 'brand_management.create', 'brand_management.update'] },
  // INVENTORY INTELLIGENCE
  { name: 'Inventory Monitoring', routePath: '/admin/inventory', section: 'INVENTORY INTELLIGENCE', icon: 'inventory',
    required: ['centralized_inventory.read'], actions: ['automated_inventory_monitoring.read', 'location_based_inventory.read'] },
  { name: 'Transfer Requests', routePath: '/admin/transfer-requests', section: 'INVENTORY INTELLIGENCE', icon: 'transfer',
    required: ['inventory_transfers.read'], actions: ['inventory_transfers.create'] },
  { name: 'Stock Transfers', routePath: '/admin/transfers', section: 'INVENTORY INTELLIGENCE', icon: 'transfer',
    required: ['inventory_transfers.read'], actions: ['inventory_transfers.create', 'inventory_transfers.approve'] },
  { name: 'Interbranch Transfers', routePath: '/admin/ist', section: 'INVENTORY INTELLIGENCE', icon: 'transfer',
    required: ['inventory_transfers.read'], actions: ['inventory_transfers.create', 'inventory_transfers.update', 'inventory_transfers.approve'] },
  { name: 'Adjustments', routePath: '/admin/adjustments', section: 'INVENTORY INTELLIGENCE', icon: 'inventoryop',
    required: ['inventory_adjustments.read'], actions: ['inventory_adjustments.create', 'inventory_adjustments.approve'] },
  { name: 'Issuances', routePath: '/admin/issuances', section: 'INVENTORY INTELLIGENCE', icon: 'issuance-scan',
    required: ['inventory_issuance.read'], actions: ['inventory_issuance.create'] },
  // SALES & LOGISTICS
  { name: 'POS Operations', routePath: '/admin/transactions', section: 'SALES & LOGISTICS', icon: 'transactions',
    required: ['pos_sales_recording.read'], actions: ['pos_sales_recording.create', 'barcode_scanning_sales.create', 'sales_validation.create'] },
  { name: 'Purchase Orders', routePath: '/admin/purchase-orders', section: 'SALES & LOGISTICS', icon: 'purchaseorders',
    required: ['purchase_order_approval.read'], actions: ['purchase_order_approval.approve'] },
  { name: 'Customer Registry', routePath: '/admin/customers', section: 'SALES & LOGISTICS', icon: 'customers',
    required: ['customer_info_capture.read'], actions: ['customer_info_capture.create', 'customer_info_capture.update'] },
  { name: 'Scan Barcode', routePath: '/admin/inventory-operation', section: 'SALES & LOGISTICS', icon: 'scan',
    required: ['barcode_inventory_processing.create'], actions: [] },
  { name: 'Issuance Scan', routePath: '/admin/issuance-scan', section: 'SALES & LOGISTICS', icon: 'issuance-scan',
    required: ['inventory_issuance.create'], actions: [] },
  { name: 'Delivery Receipts', routePath: '/admin/delivery-receipts', section: 'SALES & LOGISTICS', icon: 'reports',
    required: ['delivery_receipt_generation.read'], actions: ['delivery_receipt_generation.create'] },
  // COMPLIANCE & SUPPORT
  { name: 'Compliance Review', routePath: '/admin/compliance', section: 'COMPLIANCE & SUPPORT', icon: 'compliance',
    required: ['exception_detection.read'], actions: [] },
  { name: 'System Reports', routePath: '/admin/reports', section: 'COMPLIANCE & SUPPORT', icon: 'reports',
    required: ['inventory_reports.read'], actions: ['transaction_reports.read', 'audit_reports.read', 'export_reports.create'] },
  { name: 'Audit Trail', routePath: '/admin/audit', section: 'COMPLIANCE & SUPPORT', icon: 'audit',
    required: ['audit_trail_viewing.read'], actions: ['transaction_logs_audit.read', 'login_logs.read'] },
  { name: 'Alerts & Notices', routePath: '/admin/alerts', section: 'COMPLIANCE & SUPPORT', icon: 'notifications',
    required: ['notification_panel.read'], actions: [] },
];

const granted = (perms, key) => perms != null && perms[key] === true;

/** A bundle is visible when ALL required permissions are granted. */
export function bundleVisible(bundle, perms) {
  if (!bundle || !bundle.required || bundle.required.length === 0) return false;
  return bundle.required.every((k) => granted(perms, k));
}

/** 'on' = all required granted; 'partial' = some; 'off' = none. */
export function bundleState(bundle, perms) {
  const reqs = bundle.required || [];
  const have = reqs.filter((k) => granted(perms, k)).length;
  if (have === 0) return 'off';
  if (have === reqs.length) return 'on';
  return 'partial';
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && $env:CI='true'; npx react-scripts test --watchAll=false src/admin/utils/navBundles.test.js`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/utils/navBundles.js frontend/src/admin/utils/navBundles.test.js
git commit -m "feat(nav-bundles): add frontend bundle mirror + visibility helpers"
```

---

## Phase 5 — Navigation Access Simple tab

### Task 13: "Navigation Access" tab in the user override editor

**Files:**
- Modify: `frontend/src/admin/pages/UserPermissionOverrides.jsx`
- Modify: `frontend/src/admin/services/api.js` (add `navBundlesAPI.list()` if not present)
- Test: `frontend/src/admin/pages/UserPermissionOverrides.navtab.test.jsx` (create)

> The existing 91-toggle grid becomes the "Advanced" tab; the new "Navigation Access" tab lists bundle cards. Toggling a card calls the EXISTING `PUT /api/users/{id}/permissions` with every permission in the bundle (`is_granted` true on, false off) and a generated `reason`.

- [ ] **Step 1: Add the API helper**

In `frontend/src/admin/services/api.js`, add (near the other `*API` objects):

```js
export const navBundlesAPI = {
  list: () => apiRequest('/nav-bundles', { method: 'GET' }),
};
```

> Match the existing `apiRequest` signature in that file. If `permissionsAPI.updateUser(userId, payload)` already exists, reuse it for grants; otherwise add `updateUser: (userId, body) => apiRequest('/users/' + userId + '/permissions', { method: 'PUT', body })`.

- [ ] **Step 2: Write the failing test**

```jsx
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import UserPermissionOverrides from './UserPermissionOverrides';

jest.mock('../services/api', () => ({
  navBundlesAPI: { list: jest.fn().mockResolvedValue({ data: [
    { bundle_id: 1, name: 'Item Master', route_path: '/admin/items', sidebar_section: 'PROCUREMENT & PLANNING',
      sidebar_icon: 'items', description: 'Manage items',
      permissions: [{ permission_id: 10, feature: 'item_master_management', action: 'read', is_required: true }] },
  ] }) },
  permissionsAPI: {
    getUserPermissions: jest.fn().mockResolvedValue({ data: { effective: {}, overrides: [] } }),
    updateUser: jest.fn().mockResolvedValue({ data: null }),
  },
  usersAPI: { list: jest.fn().mockResolvedValue({ data: [] }) },
}));

test('Navigation Access tab renders bundle cards', async () => {
  render(<UserPermissionOverrides initialUserId={5} />);
  fireEvent.click(await screen.findByRole('tab', { name: /navigation access/i }));
  await waitFor(() => expect(screen.getByText('Item Master')).toBeInTheDocument());
});
```

> Adjust the mock to the real exports of `api.js` (read it first — the actual names may differ, e.g. `permissionsAPI.userPermissions`). The test must mock exactly what the component imports.

- [ ] **Step 3: Run test to verify it fails**

Run: `cd frontend && $env:CI='true'; npx react-scripts test --watchAll=false src/admin/pages/UserPermissionOverrides.navtab.test.jsx`
Expected: FAIL — no "Navigation Access" tab yet.

- [ ] **Step 4: Implement the tab**

In `UserPermissionOverrides.jsx`:
1. Add tab state `const [tab, setTab] = useState('navigation')` with two tabs: "Navigation Access" and "Advanced". Wrap the existing grid in the Advanced panel.
2. On mount (and tab switch to navigation), load `navBundlesAPI.list()` and the target user's effective permissions (existing call) to compute each card's state with `bundleState`-style logic (reuse the local helper or compute inline from the API's `is_required` flags).
3. Render bundle cards grouped by `sidebar_section`: name, icon, section, description, and a toggle reflecting on/partial/off.
4. On toggle: build `changes = bundle.permissions.map(p => ({ permission_id: p.permission_id, is_granted: turningOn }))` and call `permissionsAPI.updateUser(userId, { reason: 'Navigation Access: ' + bundle.name, changes })`; then refetch effective permissions to refresh card states.

- [ ] **Step 5: Run test to verify it passes**

Run: `cd frontend && $env:CI='true'; npx react-scripts test --watchAll=false src/admin/pages/UserPermissionOverrides.navtab.test.jsx`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/UserPermissionOverrides.jsx frontend/src/admin/services/api.js frontend/src/admin/pages/UserPermissionOverrides.navtab.test.jsx
git commit -m "feat(nav-bundles): add Navigation Access simple tab to user override editor"
```

---

## Phase 6 — Permission-driven sidebar + dashboard, retire role menus

### Task 14: Permission-driven sidebar builder

**Files:**
- Modify: `frontend/src/admin/components/AdminLayout.jsx:194-225`
- Test: `frontend/src/admin/components/sidebarBuilder.test.js` (create) + extract pure builder

> Extract the menu-building logic into a pure, testable function so it can be unit-tested without rendering the whole layout.

- [ ] **Step 1: Create the pure builder + failing test**

Create `frontend/src/admin/utils/sidebarBuilder.js`:

```js
import { NAV_BUNDLES, bundleVisible } from './navBundles';

/**
 * Build sidebar sections from the canonical full menu, keeping only items whose
 * bundle the user can see (all required perms granted). Items with no matching
 * bundle are treated as admin-only: shown only when isAdmin is true.
 */
export function buildSidebar(adminMenuSections, permissions, isAdmin) {
  const byPath = Object.fromEntries(NAV_BUNDLES.map((b) => [b.routePath, b]));
  return adminMenuSections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => {
        const bundle = byPath[item.path];
        if (!bundle) return isAdmin; // admin-only pages (config, budget, warranty)
        return bundleVisible(bundle, permissions);
      }),
    }))
    .filter((section) => section.items.length > 0);
}
```

Create `frontend/src/admin/utils/sidebarBuilder.test.js`:

```js
import { buildSidebar } from './sidebarBuilder';

const sections = [
  { label: 'PROCUREMENT & PLANNING', items: [
    { name: 'Item Master', path: '/admin/items', icon: 'items' },
    { name: 'System Configuration', path: '/admin/config', icon: 'config' },
  ] },
];

test('shows bundle item when required perm granted', () => {
  const out = buildSidebar(sections, { 'item_master_management.read': true }, false);
  const labels = out.flatMap((s) => s.items.map((i) => i.name));
  expect(labels).toContain('Item Master');
  expect(labels).not.toContain('System Configuration'); // admin-only, non-admin hidden
});

test('admin sees admin-only items', () => {
  const out = buildSidebar(sections, {}, true);
  const labels = out.flatMap((s) => s.items.map((i) => i.name));
  expect(labels).toContain('System Configuration');
});

test('empty sections are dropped', () => {
  const out = buildSidebar(sections, {}, false);
  expect(out.length).toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && $env:CI='true'; npx react-scripts test --watchAll=false src/admin/utils/sidebarBuilder.test.js`
Expected: FAIL — module not found.

- [ ] **Step 3: Make the test pass (file created in Step 1) and wire into AdminLayout**

The builder file already exists. Now replace the `menuSections` IIFE in `AdminLayout.jsx` (the block at lines ~196-225 that selects `ROLE_MENUS[roleKey]` and applies the hard-coded overlay) with:

```js
  const isAdmin = roleKey === ROLES.ADMIN;
  const menuSections = useMemo(
    () => buildSidebar(adminMenuSections, permissions || {}, isAdmin),
    [permissions, isAdmin]
  );
```

Add the import at the top of `AdminLayout.jsx`:

```js
import { buildSidebar } from '../utils/sidebarBuilder';
```

and pull `permissions` from `useAuth()` (the destructure currently grabs `can`; add `permissions`):

```js
  const { user: _user, clearSession, can, permissions } = useAuth();
```

Leave `ROLE_MENUS` import in place for now (removed in Task 16).

- [ ] **Step 4: Run the builder test + the existing layout-related tests**

Run: `cd frontend && $env:CI='true'; npx react-scripts test --watchAll=false src/admin/utils/sidebarBuilder.test.js`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/utils/sidebarBuilder.js frontend/src/admin/utils/sidebarBuilder.test.js frontend/src/admin/components/AdminLayout.jsx
git commit -m "feat(unified-ui): permission-driven sidebar builder replaces role-keyed menus"
```

---

### Task 15: Data-scope indicator in the header

**Files:**
- Modify: `frontend/src/admin/components/AdminLayout.jsx` (header actions area)
- Reuse: `frontend/src/admin/components/LocationBadge.jsx`
- Test: `frontend/src/admin/components/LocationBadge.test.jsx` (create, if LocationBadge has testable props)

- [ ] **Step 1: Write a failing test for the badge text**

```jsx
import { render, screen } from '@testing-library/react';
import LocationBadge from './LocationBadge';

test('scoped user sees their location name', () => {
  render(<LocationBadge isCentralized={false} locationName="Cebu Branch" />);
  expect(screen.getByText(/Cebu Branch/i)).toBeInTheDocument();
});

test('centralized user sees All Locations', () => {
  render(<LocationBadge isCentralized={true} locationName={null} />);
  expect(screen.getByText(/All Locations/i)).toBeInTheDocument();
});
```

> Read `LocationBadge.jsx` first. If its prop shape differs, adapt the test and/or extend the component to accept `isCentralized` + `locationName`. If the badge already derives from `useUserLocation`, test via that hook mocked instead.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && $env:CI='true'; npx react-scripts test --watchAll=false src/admin/components/LocationBadge.test.jsx`
Expected: FAIL (badge doesn't yet branch on centralized).

- [ ] **Step 3: Implement the badge behavior + place it in the header**

Ensure `LocationBadge` renders "Showing data for: {locationName}" when `!isCentralized` and "All Locations" when centralized. In `AdminLayout.jsx` header-actions, render `<LocationBadge isCentralized={roleKey is centralized} locationName={useUserLocation().locationName} />`. Derive centralized in the frontend from role id: `[1,2,5].includes(Number(user?.role?.role_id))`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && $env:CI='true'; npx react-scripts test --watchAll=false src/admin/components/LocationBadge.test.jsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/LocationBadge.jsx frontend/src/admin/components/LocationBadge.test.jsx frontend/src/admin/components/AdminLayout.jsx
git commit -m "feat(unified-ui): data-scope indicator badge in header"
```

---

### Task 16: Permission-driven dashboard + retire role menus

**Files:**
- Modify: `frontend/src/admin/pages/Dashboard.jsx`
- Modify: `frontend/src/admin/utils/roles.js` (remove `ROLE_MENUS` after parity)
- Modify: `frontend/src/admin/components/AdminLayout.jsx` (drop `ROLE_MENUS` import + overlay remnants)
- Test: `frontend/src/admin/pages/Dashboard.widgets.test.jsx` (create)

> Migration order from spec §7: verify per-role sidebar parity (WP → BM → IA → AU) using the permission-driven builder BEFORE deleting `ROLE_MENUS`. Each role's default `role_permissions` must satisfy its bundles' required perms; patch `PermissionSeeder` where a gap is found.

- [ ] **Step 1: Write a failing test — widget shows only with permission**

```jsx
import { render, screen } from '@testing-library/react';
import { pickDashboardWidgets } from './Dashboard';

test('PO widget appears only when user can read PO approval', () => {
  const withPO = pickDashboardWidgets({ 'purchase_order_approval.read': true });
  expect(withPO).toContain('po_summary');
  const without = pickDashboardWidgets({});
  expect(without).not.toContain('po_summary');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && $env:CI='true'; npx react-scripts test --watchAll=false src/admin/pages/Dashboard.widgets.test.jsx`
Expected: FAIL — `pickDashboardWidgets` not exported.

- [ ] **Step 3: Implement permission-driven widget selection**

In `Dashboard.jsx`, export a pure selector and use it to render widgets:

```js
const DASHBOARD_WIDGETS = [
  { id: 'po_summary', perm: 'purchase_order_approval.read' },
  { id: 'low_stock', perm: 'automated_inventory_monitoring.read' },
  { id: 'ist_queue', perm: 'inventory_transfers.read' },
  { id: 'sales_summary', perm: 'pos_sales_recording.read' },
  { id: 'receiving_summary', perm: 'delivery_receiving.read' },
];

export function pickDashboardWidgets(permissions) {
  return DASHBOARD_WIDGETS.filter((w) => permissions && permissions[w.perm] === true).map((w) => w.id);
}
```

Render each widget block guarded by `pickDashboardWidgets(permissions).includes('<id>')`. A widget the user can't access is not rendered (spec §8). Fold the role-specific dashboard summary blocks from `WarehousePersonnelDashboard`/`BranchManagerDashboard`/`InventoryAnalystDashboard`/`AuditorDashboard` into these permission-gated blocks.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && $env:CI='true'; npx react-scripts test --watchAll=false src/admin/pages/Dashboard.widgets.test.jsx`
Expected: PASS.

- [ ] **Step 5: Retire `ROLE_MENUS`**

After confirming sidebar parity for all four roles (manual check with test accounts or by asserting `buildSidebar` reproduces each old `ROLE_MENUS[role]` given that role's default perms): delete the `ROLE_MENUS` export from `roles.js`, remove its import and any remaining `overlayItems` remnants from `AdminLayout.jsx`. Keep `ROLE_META` (still used for accent/labels).

- [ ] **Step 6: Run the full frontend admin util/page test suite**

Run: `cd frontend && $env:CI='true'; npx react-scripts test --watchAll=false src/admin`
Expected: PASS (no references to removed `ROLE_MENUS`).

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/pages/Dashboard.jsx frontend/src/admin/utils/roles.js frontend/src/admin/components/AdminLayout.jsx frontend/src/admin/pages/Dashboard.widgets.test.jsx
git commit -m "feat(unified-ui): permission-driven dashboard widgets; retire ROLE_MENUS"
```

---

### Task 17: Auto-redirect on mid-session bundle revocation

**Files:**
- Modify: `frontend/src/admin/context/AuthContext.jsx` (re-fetch permissions on route change)
- Modify: `frontend/src/admin/components/RoleRoute.jsx` (already redirects; ensure it re-checks bundle required perms)
- Test: `frontend/src/admin/components/RoleRoute.test.jsx` (create)

> Spec §8: when SA revokes a bundle mid-session, the user should be redirected off the now-forbidden page on next navigation.

- [ ] **Step 1: Write a failing test**

```jsx
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import RoleRoute from './RoleRoute';
import * as AuthCtx from '../context/AuthContext';

test('redirects to dashboard when required bundle perm missing', () => {
  jest.spyOn(AuthCtx, 'useAuth').mockReturnValue({
    user: { user_id: 1, role: { role_name: 'warehouse_personnel', role_id: 4 } },
    permissions: {}, // no perms → no access
    can: () => false,
  });
  render(
    <MemoryRouter initialEntries={['/admin/items']}>
      <Routes>
        <Route path="/admin/items" element={<RoleRoute><div>Items Page</div></RoleRoute>} />
        <Route path="/admin/dashboard" element={<div>Dashboard Page</div>} />
      </Routes>
    </MemoryRouter>
  );
  expect(screen.getByText('Dashboard Page')).toBeInTheDocument();
});
```

> Confirm `useAuth` is a named export that can be spied; if not, mock the module. Adjust to the actual `ROUTE_ACCESS`/`ROUTE_PERMISSION` logic — for `/admin/items` the WP role isn't in `ROUTE_ACCESS` and `ROUTE_PERMISSION['/admin/items']` is `item_master_management.read`, so `can()===false` triggers redirect. This test should pass with current RoleRoute logic; if it already passes, it documents/guards the behavior (still valuable).

- [ ] **Step 2: Run test**

Run: `cd frontend && $env:CI='true'; npx react-scripts test --watchAll=false src/admin/components/RoleRoute.test.jsx`
Expected: PASS if behavior already correct, or FAIL if redirect path differs — then fix RoleRoute to redirect on missing required perm.

- [ ] **Step 3: Add permissions re-fetch on navigation**

In `AuthContext.jsx`, expose a `refreshPermissions()` that re-calls `permissionsAPI.getMine()` and updates `permissions`. In `AdminLayout.jsx` (or a small effect in `RoleRoute`), call `refreshPermissions()` on `location.pathname` change so a revoked bundle takes effect on the next navigation without a full reload. Keep it lightweight (it's one GET).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && $env:CI='true'; npx react-scripts test --watchAll=false src/admin/components/RoleRoute.test.jsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/context/AuthContext.jsx frontend/src/admin/components/RoleRoute.jsx frontend/src/admin/components/RoleRoute.test.jsx
git commit -m "feat(unified-ui): re-check permissions on navigation; redirect off revoked pages"
```

---

## Final verification

- [ ] **Backend:** `cd back-end && php artisan test` → all green.
- [ ] **Frontend:** `cd frontend && $env:CI='true'; npx react-scripts test --watchAll=false` → all green.
- [ ] **Manual smoke (per role, with test accounts):** log in as WP, BM, IA, AU, SA; confirm sidebar matches granted bundles, location badge correct (scoped vs All Locations), lists show only scoped data for WP/BM, and toggling a bundle in the SA Navigation Access tab adds/removes the page from that user's sidebar on next login.
- [ ] **Update stale memory:** correct the note claiming `config/rbac.php` holds the matrix (it's DB-resident) per spec follow-up.

---

## Notes on follow-ups (NOT in this plan — spec §2.1/§2.2)

- Warranty pages stay role-open; adding a `warranty_management` catalog feature + bundle is a later effort.
- `system_configuration` / `budget_management` stay admin-only; promoting them to delegable features is a later effort.
- Supabase PWA untouched (spec D2).
