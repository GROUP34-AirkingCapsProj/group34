# RBAC Phase 2 — Route & Frontend Enforcement Migration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate the ~73 remaining hardcoded `role:` route guards to `permission:feature,action`, and migrate the frontend gating (route access, menus, action buttons) to the live permission system — so admin grants/overrides take effect everywhere.

**Architecture:** Per-route `permission:` middleware backed by the Phase-1 `PermissionResolver`/seeded matrix; a data-driven RBAC matrix test as the regression contract; a frontend overlay (`ROUTE_PERMISSION` + `requiredPermission` menu tags + `can()` button gating) layered on the existing `ROLE_MENUS`/`ROUTE_ACCESS`.

**Tech Stack:** Laravel 11 (`back-end/`), MySQL test DB `airkingV5_test`, PHPUnit `DatabaseTransactions`. React 18 (`frontend/src/admin/`), `AuthContext.can()`, Jest.

**Spec:** `docs/superpowers/specs/2026-06-11-rbac-phase2-route-enforcement-design.md` (the §3 map is the authoritative route→permission reference; §4 lists intended behavior changes).

---

## Conventions

- Run backend commands from `back-end/` (php fallback `C:\XAMPP1\php\php.exe`). Run frontend from `frontend/`. Branch `backupV3`.
- Role ids: admin=1, inventory_analyst=2, branch_manager=3, warehouse_personnel=4, auditor=5.
- After each backend module: `php artisan test tests/Feature/Rbac` must stay green.
- **Migrate only the routes named per module below.** Do NOT touch routes the spec lists as "stays on `role:`" (system settings, password-reset, status-lookup, archive-settings, permission-management endpoints, legacy `/transfers`, subcategories, models, customers force, warranty-claims, budget-allocations, purchase-returns, profit-loss, PR create/store/convert).
- Each `permission:` middleware takes `feature,action`. Replace group middleware with **per-route** middleware where a group spans multiple actions.
- Commit after every task.

---

## File Structure

**Backend (modify):** `back-end/routes/api.php` (the migrations), `back-end/database/seeders/PermissionSeeder.php` (one added row).
**Backend (create):** `back-end/tests/Feature/Rbac/RbacRouteMatrixTest.php` (+ grows per module).
**Frontend (modify):** `frontend/src/admin/utils/roles.js` (`ROUTE_PERMISSION`, `requiredPermission` tags), `frontend/src/admin/components/AdminLayout.jsx` (menu overlay), and the gated pages (`PurchaseOrderPage.jsx`, `ApprovalQueue.jsx`, `ArchiveManagement.jsx`, `BarcodeAdjustmentPage.jsx`, `BarcodeScan.jsx`, `IssuanceScanPage.jsx`, `Dashboard.jsx`).

---

# PHASE A — Foundation

## Task 1: Seeder addendum + re-seed

**Files:** Modify `back-end/database/seeders/PermissionSeeder.php`; Test `back-end/tests/Feature/Permissions/SeederTest.php`.

- [ ] **Step 1:** In `PermissionSeeder::matrix()`, change the `delivery_receipt_generation` entry to add an `archive` action:

```php
            'delivery_receipt_generation' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $BM],
            ],
```

- [ ] **Step 2:** Add an assertion to `SeederTest::test_seeds_reconciled_matrix`:

```php
        $this->assertTrue($this->granted('delivery_receipt_generation', 'archive', $this->roleId['admin']));
        $this->assertFalse($this->granted('delivery_receipt_generation', 'archive', $this->roleId['branch_manager']));
```

- [ ] **Step 3:** Run `php artisan test tests/Feature/Permissions/SeederTest.php` → PASS.

- [ ] **Step 4:** Re-seed both DBs:
```powershell
php artisan db:seed --class=PermissionSeeder
$env:DB_DATABASE='airkingV5_test'; php artisan db:seed --class=PermissionSeeder; Remove-Item Env:\DB_DATABASE
```

- [ ] **Step 5:** Commit: `git commit -m "feat(rbac): seed delivery_receipt_generation.archive (Phase 2 prep)"`

## Task 2: RBAC route-matrix test harness

**Files:** Create `back-end/tests/Feature/Rbac/RbacRouteMatrixTest.php`.

- [ ] **Step 1:** Create the harness with a reusable assertion and a (initially small) data table. Each later module appends rows to `routeMatrix()`.

```php
<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\User;
use App\Support\PermissionResolver;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RbacRouteMatrixTest extends TestCase
{
    use DatabaseTransactions;

    private array $roleId = [
        'admin' => 1, 'inventory_analyst' => 2, 'branch_manager' => 3,
        'warehouse_personnel' => 4, 'auditor' => 5,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        (new PermissionSeeder())->run();
    }

    private function userFor(int $roleId): User
    {
        return User::create([
            'username' => 'rm_' . uniqid(), 'first_name' => 'R', 'last_name' => 'M',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    /**
     * One representative endpoint per migrated feature.action.
     * [method, uri, feature, action]. Write routes use a dummy id; the permission
     * middleware runs before the controller, so 403 = denied, anything else = allowed.
     */
    private function routeMatrix(): array
    {
        return [
            // Modules append rows here as they migrate, e.g.:
            // ['post', '/api/categories', 'category_management', 'create'],
        ];
    }

    public function test_every_role_matches_the_seeded_matrix(): void
    {
        $resolver = app(PermissionResolver::class);

        foreach ($this->routeMatrix() as [$method, $uri, $feature, $action]) {
            foreach ($this->roleId as $slug => $rid) {
                $user = $this->userFor($rid);
                $expectedAllow = $resolver->canUser($user->user_id, $feature, $action);
                $res = $this->actingAs($user, 'sanctum')->json(strtoupper($method), $uri, []);
                $status = $res->getStatusCode();

                if ($expectedAllow) {
                    $this->assertNotEquals(403, $status, "$slug should be ALLOWED $method $uri ($feature.$action)");
                } else {
                    // auditor non-GET is also blocked globally by auditor.readonly — still 403, consistent.
                    $this->assertEquals(403, $status, "$slug should be DENIED $method $uri ($feature.$action)");
                }
            }
        }
    }
}
```

- [ ] **Step 2:** Run `php artisan test tests/Feature/Rbac/RbacRouteMatrixTest.php` → PASS (empty table = trivially green; rows are added per module).

- [ ] **Step 3:** Commit: `git commit -m "test(rbac): add data-driven route-matrix harness"`

> **NOTE for every module task below:** after editing routes, ADD that module's rows to `routeMatrix()` and re-run `php artisan test tests/Feature/Rbac`. If a row fails, the route middleware or the seeded set is wrong — fix before committing.

---

# PHASE B — Backend module migrations

Each task edits `back-end/routes/api.php` for one module per spec §3, adds matrix rows, runs the test, commits. Below, each task gives the exact after-state.

## Task 3: Module A — Classification lookups (category, brand, uom)

- [ ] **Step 1:** Replace the three `role:admin,inventory_analyst` lookup groups. Categories:
```php
    Route::middleware('permission:category_management,create')->post('/categories', [CategoryLookupController::class, 'store']);
    Route::middleware('permission:category_management,update')->put('/categories/{category}', [CategoryLookupController::class, 'update']);
    Route::middleware('permission:category_management,archive')->delete('/categories/{category}', [CategoryLookupController::class, 'destroy']);
    Route::middleware('permission:category_management,archive')->post('/categories/{category}/restore', [CategoryLookupController::class, 'restore']);
    Route::middleware('permission:category_management,archive')->delete('/categories/{category}/force', [CategoryLookupController::class, 'forceDestroy']);
```
Brands (same shape, feature `brand_management`): store→create, update→update, destroy/restore/force→archive.
Units (feature `uom_management`): store→create, update→update, destroy/restore/force→archive.

- [ ] **Step 2:** Add matrix rows:
```php
            ['post', '/api/categories', 'category_management', 'create'],
            ['delete', '/api/categories/1', 'category_management', 'archive'],
            ['post', '/api/brands', 'brand_management', 'create'],
            ['delete', '/api/brands/1', 'brand_management', 'archive'],
            ['post', '/api/units', 'uom_management', 'create'],
            ['delete', '/api/units/1', 'uom_management', 'archive'],
```

- [ ] **Step 3:** `php artisan test tests/Feature/Rbac` → PASS. (Confirms the §4 change: IA now denied category/brand/uom archive.)

- [ ] **Step 4:** Commit: `git commit -m "feat(rbac): migrate classification lookups to permission middleware"`

## Task 4: Module B — Item master / products / pending-products

- [ ] **Step 1:** Products `role:admin,inventory_analyst` group → per-route `item_master_management`: store→create, `products/{id}/issuable-units` store→create, update→update. The `role:admin` group (destroy/restore/force) → `item_master_management,archive`. Mirror the legacy `/items` aliases the same way.
- [ ] **Step 2:** Pending-products: `POST /pending-products` store → `permission:item_master_management,create`; `GET /pending-products` index → `permission:item_master_management,read`; `POST /pending-products/{p}/approve` → `permission:item_master_management,approve`.
- [ ] **Step 3:** Matrix rows:
```php
            ['post', '/api/products', 'item_master_management', 'create'],
            ['delete', '/api/products/1', 'item_master_management', 'archive'],
            ['post', '/api/pending-products', 'item_master_management', 'create'],
            ['get', '/api/pending-products', 'item_master_management', 'read'],
            ['post', '/api/pending-products/1/approve', 'item_master_management', 'approve'],
```
- [ ] **Step 4:** `php artisan test tests/Feature/Rbac` → PASS (confirms §4: pending create SA,IA only; approve SA,IA).
- [ ] **Step 5:** Commit: `git commit -m "feat(rbac): migrate item master / pending products"`

## Task 5: Module C+D — Serials, suppliers, supplier-products

- [ ] **Step 1:** Serials: `role:admin,branch_manager,warehouse_personnel` store/update → `barcode_assignment,{create,update}`; `role:admin` destroy → `barcode_assignment,archive`.
- [ ] **Step 2:** Suppliers: `role:admin,inventory_analyst,branch_manager` group → `supplier_management,create` (store), `supplier_management,update` (update); the supplier-products sub-routes (addProduct/updateProduct/removeProduct) → `item_supplier_mapping,{create,update,update}`. `role:admin` destroy/restore/force → `supplier_management,archive`.
- [ ] **Step 3:** Matrix rows:
```php
            ['post', '/api/serials', 'barcode_assignment', 'create'],
            ['delete', '/api/serials/1', 'barcode_assignment', 'archive'],
            ['post', '/api/suppliers', 'supplier_management', 'create'],
            ['delete', '/api/suppliers/1', 'supplier_management', 'archive'],
            ['post', '/api/suppliers/1/products', 'item_supplier_mapping', 'create'],
```
- [ ] **Step 4:** Test → PASS. Commit: `git commit -m "feat(rbac): migrate serials + suppliers"`

## Task 6: Module E — Purchase Orders

- [ ] **Step 1:** `GET /purchase-orders/export` + `products-by-supplier` group → `permission:purchase_order_creation,read`. `POST /purchase-orders` → `purchase_order_creation,create`. `PUT /purchase-orders/{id}` → `purchase_order_creation,update`. `role:admin` destroy/restore/force → `purchase_order_creation,archive`.
- [ ] **Step 2:** Matrix rows:
```php
            ['post', '/api/purchase-orders', 'purchase_order_creation', 'create'],
            ['put', '/api/purchase-orders/1', 'purchase_order_creation', 'update'],
            ['delete', '/api/purchase-orders/1', 'purchase_order_creation', 'archive'],
            ['get', '/api/purchase-orders/export', 'purchase_order_creation', 'read'],
```
- [ ] **Step 3:** Test → PASS (confirms §4: BM loses PO create/update). Commit.

## Task 7: Module F+G — Receiving, Sales, Delivery Receipts

- [ ] **Step 1:** Receivings: store → `delivery_receiving,create`; update → `delivery_receiving,update`; destroy → `delivery_receiving,archive`.
- [ ] **Step 2:** Sales: store → `pos_sales_recording,create`; update → `pos_sales_recording,update`; destroy → `pos_sales_recording,archive`.
- [ ] **Step 3:** Delivery-receipts: store/update → `delivery_receipt_generation,{create,update}`; destroy → `delivery_receipt_generation,archive`.
- [ ] **Step 4:** Matrix rows:
```php
            ['post', '/api/receivings', 'delivery_receiving', 'create'],
            ['post', '/api/sales', 'pos_sales_recording', 'create'],
            ['put', '/api/sales/1', 'pos_sales_recording', 'update'],
            ['post', '/api/delivery-receipts', 'delivery_receipt_generation', 'create'],
            ['delete', '/api/delivery-receipts/1', 'delivery_receipt_generation', 'archive'],
```
- [ ] **Step 5:** Test → PASS (confirms §4: sales create SA,BM,WP — IA denied, WP allowed). Commit.

## Task 8: Module H — Issuance + issuance-scan + csv download

- [ ] **Step 1:** Issuances `role:admin,warehouse_personnel` store/update → `inventory_issuance,{create,update}`; `role:admin` destroy/restore/force → `inventory_issuance,archive`.
- [ ] **Step 2:** `issuance-scan` prefix group `role:admin,branch_manager,warehouse_personnel` → `permission:inventory_issuance,create` (whole group is one scan workflow).
- [ ] **Step 3:** The csv download route (`/issuance-scan/delivery-receipt/{id}/csv`) `role:…` → `permission:delivery_receipt_generation,read` (keep the `auth.query.token` middleware alongside).
- [ ] **Step 4:** Matrix rows:
```php
            ['post', '/api/issuances', 'inventory_issuance', 'create'],
            ['delete', '/api/issuances/1', 'inventory_issuance', 'archive'],
            ['post', '/api/issuance-scan/init', 'inventory_issuance', 'create'],
```
- [ ] **Step 5:** Test → PASS. Commit.

## Task 9: Module I — Transfers (IST + requests)

- [ ] **Step 1:** IST dispatch group (store/scan/removeScan/confirm-dispatch) → `permission:inventory_transfers,create`. IST receive group (receive/receive-scan/removeReceiveScan) → `permission:inventory_transfers,update`.
- [ ] **Step 2:** `transfer-requests` store group → `permission:inventory_transfers,create`. The `role:admin,inventory_analyst` approve/reject group → `permission:inventory_transfers,approve`.
- [ ] **Step 3:** IST pdf download (`/ist/{id}/receipt/pdf`) `role:…` → `permission:inventory_transfers,read` (keep `auth.query.token`).
- [ ] **Step 4:** Leave the legacy `/transfers` `role:admin` group UNCHANGED (spec decision 6i).
- [ ] **Step 5:** Matrix rows:
```php
            ['post', '/api/ist', 'inventory_transfers', 'create'],
            ['post', '/api/ist/1/receive', 'inventory_transfers', 'update'],
            ['patch', '/api/transfer-requests/1/approve', 'inventory_transfers', 'approve'],
```
- [ ] **Step 6:** Test → PASS. Commit.

## Task 10: Module J — Adjustments + barcode-adjustment + compliance

- [ ] **Step 1:** Barcode-adjustment group `role:admin,warehouse_personnel,branch_manager`: split — the GET reads (receiving-history, lookup-barcode, correction-history, corrections/{id}) → `permission:inventory_adjustments,read`; the writes (preview, submit) → `permission:inventory_adjustments,create`.
- [ ] **Step 2:** Adjustments `role:admin,warehouse_personnel` store/update → `inventory_adjustments,{create,update}`; `role:admin` destroy/restore/force → `inventory_adjustments,archive`.
- [ ] **Step 3:** `GET /compliance/logs` `role:admin,auditor,branch_manager` → `permission:exception_detection,read`.
- [ ] **Step 4:** Matrix rows:
```php
            ['post', '/api/adjustments', 'inventory_adjustments', 'create'],
            ['delete', '/api/adjustments/1', 'inventory_adjustments', 'archive'],
            ['get', '/api/compliance/logs', 'exception_detection', 'read'],
```
- [ ] **Step 5:** Test → PASS. Commit.

## Task 11: Module K — Audit reads

- [ ] **Step 1:** `role:admin,auditor` audit-log/audit-trail group → `permission:audit_trail_viewing,read` (3 GET routes). `role:admin,auditor,branch_manager` audit-trail/v2 group → `permission:audit_trail_viewing,read`. `role:admin` `DELETE /activity-log/{id}` → `permission:audit_trail_viewing,archive`.
- [ ] **Step 2:** Matrix rows:
```php
            ['get', '/api/audit-trail/v2', 'audit_trail_viewing', 'read'],
            ['delete', '/api/activity-log/1', 'audit_trail_viewing', 'archive'],
```
- [ ] **Step 3:** Test → PASS (confirms BM gains audit-log read). Commit.

## Task 12: Module L — Archive management

- [ ] **Step 1:** Archive prefix group `role:admin,branch_manager`: summary + `{module}` index GET → `permission:archive_viewing,read`; `{module}/{id}/restore` → `permission:data_archiving,update`. The nested `role:admin` force + restore-all → `data_archiving,archive` and `data_archiving,update` respectively.
- [ ] **Step 2:** Matrix rows:
```php
            ['get', '/api/archive/summary', 'archive_viewing', 'read'],
            ['post', '/api/archive/products/1/restore', 'data_archiving', 'update'],
```
- [ ] **Step 3:** Test → PASS (confirms §4: BM loses archive-restore; IA/AU gain archive read). Commit.

## Task 13: Module M — Users & roles

- [ ] **Step 1:** Expand the `users` `role:admin` group into explicit per-action routes. Replace `Route::apiResource('users', UserController::class)` and the sibling routes with:
```php
    Route::middleware('permission:user_account_management,read')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/by-location/{locationId}', [UserController::class, 'byLocation']);
        Route::get('/users/archived', [UserController::class, 'archived']);
        Route::get('/users/{user}', [UserController::class, 'show']);
    });
    Route::middleware('permission:user_account_management,create')->post('/users', [UserController::class, 'store']);
    Route::middleware('permission:user_account_management,update')->group(function () {
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::put('/users/{user}/change-password', [UserController::class, 'changePassword']);
        Route::put('/users/{user}/status', [UserController::class, 'updateStatus']);
        Route::post('/users/{id}/restore', [UserController::class, 'restore']);
    });
    Route::middleware('permission:user_account_management,archive')->group(function () {
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
        Route::delete('/users/{id}/force', [UserController::class, 'forceDestroy']);
    });
```
(Preserve the existing route ORDER caveats: specific paths like `/users/archived` and `/users/by-location/{locationId}` must be declared BEFORE `/users/{user}`.)
- [ ] **Step 2:** Roles `role:admin` group → `role_assignment,{create,update,archive}` per route (store/update/destroy).
- [ ] **Step 3:** Leave the `/permissions`, `/role-permissions`, `/users/{id}/permissions`, `/permission-audit-logs` routes on `role:admin` (spec §2).
- [ ] **Step 4:** Matrix rows:
```php
            ['post', '/api/users', 'user_account_management', 'create'],
            ['get', '/api/users', 'user_account_management', 'read'],
            ['post', '/api/roles', 'role_assignment', 'create'],
```
- [ ] **Step 5:** Test → PASS. Run the FULL backend suite `php artisan test` to confirm no regressions in user-management feature tests. Commit.

## Task 14: Backend sweep verification

- [ ] **Step 1:** `grep -n "role:" back-end/routes/api.php` — confirm only the intended "stays" routes remain (system settings, password-reset, status-lookup, archive-settings, permission-management, legacy `/transfers`, subcategories, models, customers force, warranty-claims, budget-allocations, purchase-returns, profit-loss, PR create/store/convert). List them in the commit message.
- [ ] **Step 2:** `php artisan test` — full suite green.
- [ ] **Step 3:** Commit (docs/notes only if any): `git commit -m "chore(rbac): Phase 2 backend migration complete"`

---

# PHASE C — Frontend overlay

## Task 15: Expand `ROUTE_PERMISSION` + verify `RoleRoute`

**Files:** Modify `frontend/src/admin/utils/roles.js`.

- [ ] **Step 1:** Extend the `ROUTE_PERMISSION` map (added in Phase 1) with the page→permission pairs for routes that should widen by grant. Example entries (read action gates page visibility):
```js
export const ROUTE_PERMISSION = {
  '/admin/inventory-operation': ['barcode_inventory_processing', 'create'],
  '/admin/items': ['item_master_management', 'read'],
  '/admin/suppliers': ['supplier_management', 'read'],
  '/admin/transactions': ['pos_sales_recording', 'read'],
  '/admin/receivings': ['delivery_receiving', 'read'],
  '/admin/issuance-scan': ['inventory_issuance', 'create'],
  '/admin/transfers': ['inventory_transfers', 'read'],
  '/admin/adjustments': ['inventory_adjustments', 'read'],
  // add others as needed; keep ROUTE_ACCESS as the base — these only WIDEN access
};
```
- [ ] **Step 2:** No `RoleRoute` change needed (it already checks `ROUTE_PERMISSION` with a loading guard). Manually verify: a user statically allowed still gets in; a granted-but-not-statically-allowed user gets in after permissions load.
- [ ] **Step 3:** `npx eslint src/admin/utils/roles.js` clean. Commit: `git commit -m "feat(rbac): expand ROUTE_PERMISSION for permission-widened pages"`

## Task 16: Menu overlay (`requiredPermission` tags + AdminLayout filter)

**Files:** Modify `frontend/src/admin/utils/roles.js`, `frontend/src/admin/components/AdminLayout.jsx`.

- [ ] **Step 1:** Generalize the one-off scan injection in `AdminLayout`'s `menuSections` builder into a data-driven overlay. Replace the demo-slice block with:
```js
    // Permission overlay: items tagged with requiredPermission appear when granted,
    // even if the static role menu omits them. (Base ROLE_MENUS behavior is unchanged.)
    const overlayItems = [
      { name: 'Inventory Operation', path: '/admin/inventory-operation', icon: 'barcode',
        section: 'INVENTORY INTELLIGENCE', requiredPermission: ['barcode_inventory_processing', 'create'] },
      // add more overlay items here as features are surfaced
    ];
    overlayItems.forEach((it) => {
      const already = result.some((s) => s.items.some((x) => x.path === it.path));
      if (!already && typeof can === 'function' && can(it.requiredPermission[0], it.requiredPermission[1])) {
        const idx = result.findIndex((s) => s.label === it.section);
        const item = { name: it.name, path: it.path, icon: it.icon };
        if (idx >= 0) result = result.map((s, i) => (i === idx ? { ...s, items: [...s.items, item] } : s));
        else result = [...result, { label: it.section, items: [item] }];
      }
    });
```
- [ ] **Step 2:** `npx eslint src/admin/components/AdminLayout.jsx` — no NEW errors. Commit: `git commit -m "feat(rbac): generalize permission menu overlay"`

## Task 17: Page button gating — PurchaseOrderPage + ApprovalQueue

**Files:** Modify `frontend/src/admin/pages/PurchaseOrderPage.jsx`, `ApprovalQueue.jsx`, and `frontend/src/admin/utils/roles.js` (the `canActOnApprovalQueue` / `canApprovePendingRegistrations` helpers).

- [ ] **Step 1:** In each page, pull `can` from `useAuth()` and gate the action buttons:
  - PO create/edit buttons → `can('purchase_order_creation','create'|'update')` (fall back to `isAdmin` only if no mapping).
  - PO approval action (Approval Queue) → `can('purchase_order_approval','approve')`.
  - Item-registration approve (pending products) → `can('item_master_management','approve')`.
- [ ] **Step 2:** Update `canActOnApprovalQueue(user)` / `canApprovePendingRegistrations(user)` in `roles.js` to ALSO accept a permission check, or deprecate them in favor of inline `can(...)` in the pages. Keep signatures working to avoid breaking other callers (grep first).
- [ ] **Step 3:** `npx eslint` the changed files; `npm test -- permissions.test.js --watchAll=false` still green. Commit.

## Task 18: Page button gating — remaining pages

**Files:** `BarcodeAdjustmentPage.jsx`, `BarcodeScan.jsx`, `IssuanceScanPage.jsx`, `ArchiveManagement.jsx`, `Dashboard.jsx`.

- [ ] **Step 1:** For each page, find the `isAdmin`/`getRoleKey` checks that gate **action** UI and replace with the mapped `can(feature, action)`:
  - BarcodeAdjustment submit → `can('inventory_adjustments','create')`.
  - BarcodeScan / inventory operation → `can('barcode_inventory_processing','create')`.
  - IssuanceScan actions → `can('inventory_issuance','create')`.
  - ArchiveManagement restore/force → `can('data_archiving','update')` / `can('data_archiving','archive')`.
  - Dashboard quick-actions → leave role-based (no specific feature) OR `can('quick_actions','create')` if appropriate.
  Keep pure navigation/visibility gating as-is (handled by ROUTE_ACCESS/overlay); only migrate action gating.
- [ ] **Step 2:** `npx eslint` clean on changed files. Commit.

## Task 19: Final verification

- [ ] **Step 1:** Backend: `php artisan test` — full suite green; `php artisan test tests/Feature/Rbac` — matrix green.
- [ ] **Step 2:** Frontend: `CI=false npx react-scripts build` — compiles (exit 0); `npm test -- permissions.test.js --watchAll=false` green.
- [ ] **Step 3:** Manual smoke (the part only a human can do): as admin, grant IA a sample permission per migrated module (e.g. `pos_sales_recording.create`), then as that IA user (after refresh) confirm the page/button appears and the action succeeds; revoke and confirm it disappears + the API 403s.
- [ ] **Step 4:** Update the Phase-1 spec's §6 "Phase boundary" note and this plan's status to "complete". Commit.

---

## Notes
- The §4 behavior changes are intended (the reconciliation taking effect). Call them out in the PR/release notes: category/brand/uom archive → SA-only; sales create → SA,BM,WP; PO create/update → SA,IA; pending-product create/approve → SA,IA; archive-restore → SA.
- Frontend is UX-only; the backend `permission:` guards are the security boundary, so any missed button gating fails safe (the API denies).
- Optional follow-ups (out of scope): effective-permission caching with invalidation (spec §5); enforcing currently-open reads; assigning features to the unmapped routes.
</content>
