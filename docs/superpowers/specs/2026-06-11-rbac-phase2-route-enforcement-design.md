# RBAC Phase 2 — Route & Frontend Enforcement Migration (Design Spec)

**Date:** 2026-06-11
**Branch context:** `backupV3`
**Status:** Spec — pending review (do not execute yet)
**Depends on:** Phase 1 (`2026-06-11-rbac-permission-overrides-design.md`) — tables, `PermissionResolver`, `permission:` middleware, `PermissionSeeder`, `User::can()`, `/me/permissions`, `AuthContext.can()`.

---

## 0. Goal & Decisions

**Goal:** Migrate enforcement from the hardcoded `role:` lists (74 route guards + a hardcoded frontend gating layer) onto the Phase-1 DB permission system, so that admin grants/overrides actually take effect everywhere — completing what the scan-barcode and PR-approval slices proved.

**Locked decisions (from review):**

1. **Preserve current reads.** Migrate only the `role:` guards that already exist. Do **not** add new `permission:…,read` guards to currently-open (unguarded) GET endpoints. Guarded reads (those already wrapped in `role:`) *are* migrated.
2. **Permission overlay for menus.** Keep the per-role `ROLE_MENUS` as the base; tag items with a `requiredPermission` and filter by `can(...)`. Do **not** rewrite menu construction from scratch.
3. **Incremental by module + matrix test.** Migrate backend routes module-by-module, each protected by a data-driven RBAC matrix test (role × endpoint → expected allow/deny against the seeded matrix). Migrate frontend gating page-by-page afterward.

**Already migrated (Phase-1 slices, leave as-is):** `barcode_inventory_processing,create` on the scan routes; `purchase_request_approval,approve` on the PR approve/reject/pending routes.

---

## 1. Core Principle & A Key Consequence

Each `role:` guard is replaced by `permission:feature,action`, where `action ∈ {create, read, update, archive, approve}` is implied by the route (store→create, update→update, destroy/force/restore→archive, approve→approve, guarded GET→read). The allowed roles then come from the **seeded matrix**, not the old hardcoded list.

**Consequence — enforcement shifts to the seeded matrix.** Where Phase-1 reconciliation deliberately set a default that differs from the old `role:` list, migrating the route **changes who can do it**. These are intended (they are the reconciliation taking effect), but must be called out and covered by tests. The full list is in §4.

**`auditor.readonly` stays** as the global outer guard (non-GET blocked for auditor) — unchanged.

---

## 2. Scope: What Migrates vs. What Stays

### Migrates (mapped to a seeded feature)
Operational modules: category, brand, uom, product/items, pending-products, item_serial, supplier + supplier-products, customers, purchase_order, receiving, sales, delivery_receipts, issuance, issuance-scan, IST, transfer-requests, adjustments + barcode-adjustment, compliance logs, audit-log/audit-trail (read), users, roles, and the IST/issuance download routes. See the full map in §3.

### Stays on `role:` (NOT migrated)
- **Admin/system/config** (grant-ability undesirable, or no feature): system session/company settings, password-reset, `status-lookup` writes, `archive-settings`.
- **The permission-management endpoints themselves** (`/permissions`, `/role-permissions`, `/users/{id}/permissions`, `/permission-audit-logs`) — keep hard `role:admin`. Making the permission editor itself grantable is a footgun (an admin could grant away or lock RBAC control). Conceptually `rbac_enforcement` = admin-only and we keep it that way at the route level.
- **Legacy direct transfers** (`/transfers` store/update/receive/tracking/destroy/restore/force) — keep `role:admin` per Phase-1 decision **6i** (the IST + transfer-requests flow is the managed path; the legacy controller stays admin-only).
- **Explicitly unmapped** (no matrix feature; Phase-1 decision): `subcategories`, `models`, `customers/{}/force`, `warranty-claims`, `budget-allocations`, `purchase-returns`, `profit-loss`, and PR `create/store/convert/index/export` (only PR *approval* was mapped).

> Anything in "stays" keeps its current `role:` middleware untouched. The matrix test asserts nothing about these (they are not permission-gated).

---

## 3. Route → Permission Migration Map

Role codes: SA=admin, IA=inventory_analyst, BM=branch_manager, WP=warehouse_personnel, AU=auditor. "Seeded" = the role set the `permission:` guard will enforce (from `PermissionSeeder`). **Δ** marks a behavior change vs. the current `role:` list.

### Module A — Classification lookups
| Route (verb path → method) | New guard | Seeded | Current | Δ |
|---|---|---|---|---|
| POST `/categories` → store | `category_management,create` | SA,IA | SA,IA | = |
| PUT `/categories/{c}` → update | `category_management,update` | SA,IA | SA,IA | = |
| DELETE `/categories/{c}` → destroy | `category_management,archive` | SA | SA,IA | **Δ** (IA loses) |
| POST `/categories/{c}/restore` → restore | `category_management,archive` | SA | SA,IA | **Δ** |
| DELETE `/categories/{c}/force` → forceDestroy | `category_management,archive` | SA | SA,IA | **Δ** |
| POST/PUT/DEL `/brands…` (store/update/destroy/restore/force) | `brand_management,{create,update,archive,archive,archive}` | create/update SA,IA; archive SA | SA,IA all | **Δ** archive |
| POST/PUT/DEL `/units…` | `uom_management,{…}` | create/update SA,IA; archive SA | SA,IA all | **Δ** archive |

### Module B — Item master / products
| Route | New guard | Seeded | Current | Δ |
|---|---|---|---|---|
| POST `/products` → store | `item_master_management,create` | SA,IA | SA,IA | = |
| POST `/products/{id}/issuable-units` → storeIssuableUnit | `item_master_management,create` | SA,IA | SA,IA | = |
| PUT `/products/{p}` → update | `item_master_management,update` | SA,IA | SA,IA | = |
| DELETE `/products/{p}` → destroy | `item_master_management,archive` | SA | SA | = |
| POST `/products/{id}/restore`, DELETE `/products/{id}/force` | `item_master_management,archive` | SA | SA | = |
| POST/PUT/DEL `/items…` (legacy alias) | mirror products | — | — | = |
| POST `/pending-products` → store | `item_master_management,create` | SA,IA | SA,BM,WP,IA | **Δ** (BM,WP lose) |
| GET `/pending-products` → index | `item_master_management,read` | SA,IA,BM,AU | SA,BM,IA,AU | = |
| POST `/pending-products/{p}/approve` → approve | `item_master_management,approve` | SA,IA | SA,BM | **Δ** (BM→IA) |

### Module C — Serials / barcode assignment
| POST `/serials` → store, PUT `/serials/{s}` → update | `barcode_assignment,{create,update}` | SA,BM,WP | SA,BM,WP | = |
| DELETE `/serials/{s}` → destroy | `barcode_assignment,archive` | SA | SA | = |

### Module D — Suppliers
| POST `/suppliers`, PUT `/suppliers/{id}` | `supplier_management,{create,update}` | SA,IA,BM | SA,IA,BM | = |
| POST/PUT/DEL `/suppliers/{id}/products…` (addProduct/updateProduct/removeProduct) | `item_supplier_mapping,{create,update,update}` | SA,IA,BM | SA,IA,BM | = |
| DELETE `/suppliers/{id}` + restore + force | `supplier_management,archive` | SA | SA | = |

### Module E — Purchase Orders
| GET `/purchase-orders/export`, GET `/purchase-orders/products-by-supplier/{s}` → read | `purchase_order_creation,read` | SA,IA,BM,AU | SA,IA,BM | = (AU gains, but these are GET) |
| POST `/purchase-orders` → store | `purchase_order_creation,create` | SA,IA | SA,IA,BM | **Δ** (BM loses) |
| PUT `/purchase-orders/{id}` → update | `purchase_order_creation,update` | SA,IA | SA,BM | **Δ** (BM→IA) |
| DELETE `/purchase-orders/{id}` + restore + force | `purchase_order_creation,archive` | SA | SA | = |

### Module F — Receiving (PO delivery)
| POST `/receivings` → store | `delivery_receiving,create` | SA,BM,WP | SA,WP,BM | = |
| PUT `/receivings/{id}` → update | `delivery_receiving,update` | SA,BM,WP | SA,BM | = (WP gains) |
| DELETE `/receivings/{id}` → destroy | `delivery_receiving,archive` | SA | SA | = |

### Module G — Sales / POS
| POST `/sales` → store | `pos_sales_recording,create` | SA,BM,WP | SA,BM,IA | **Δ** (IA loses, WP gains) |
| PUT `/sales/{id}` → update | `pos_sales_recording,update` | SA,BM,WP | SA,BM | = (WP gains) |
| DELETE `/sales/{id}` → destroy | `pos_sales_recording,archive` | SA | SA | = |
| POST/PUT `/delivery-receipts…` | `delivery_receipt_generation,{create,update}` | SA,BM,WP | SA,BM | = (WP gains) |
| DELETE `/delivery-receipts/{id}` | `delivery_receipt_generation,archive`† | SA | SA | = |

† `delivery_receipt_generation` has no seeded `archive` row (POS archive = SA-only, but this feature's archive wasn't seeded). **Resolution:** seed `delivery_receipt_generation.archive = [SA]` (and `delivery_receiving` already has archive). See §6 (seeder addendum).

### Module H — Issuance
| POST/PUT `/issuances…` (store/update) | `inventory_issuance,{create,update}` | create SA,BM,WP; update SA,WP | SA,WP | = (BM gains create) |
| DELETE `/issuances/{id}` + restore + force | `inventory_issuance,archive` | SA | SA | = |
| `issuance-scan/*` group (init/scan/removeItem/complete) | `inventory_issuance,create` | SA,BM,WP | SA,BM,WP | = |
| GET `/issuance-scan/delivery-receipt/{id}/csv` (download) | `delivery_receipt_generation,read` | SA,BM,WP,AU | SA,BM,WP | = |

### Module I — Transfers (IST / requests)
| `ist` store/scan/removeScan/confirm-dispatch | `inventory_transfers,create` | SA,IA,BM,WP | SA,IA,WP,BM | = |
| `ist` receive/receive-scan/removeReceiveScan | `inventory_transfers,update` | SA,IA,BM,WP | SA,IA,WP,BM | = |
| GET `/ist/{id}/receipt/pdf` (download) | `inventory_transfers,read` | all incl AU | SA,IA,BM,WP | = (AU gains, GET) |
| POST `/transfer-requests` → store | `inventory_transfers,create` | SA,IA,BM,WP | SA,BM,WP,IA | = |
| PATCH `/transfer-requests/{id}/approve`,`/reject` | `inventory_transfers,approve` | SA,IA | SA,IA | = |

### Module J — Adjustments
| barcode group (receiving-history, lookup-barcode, correction-history, corrections/{id} = reads; preview, submit = writes) | reads → `inventory_adjustments,read`; preview/submit → `inventory_adjustments,create` | read all-but…; create SA,BM,WP | SA,WP,BM | reads = (BM/WP already in); create = |
| POST/PUT `/adjustments…` (store/update) | `inventory_adjustments,{create,update}` | create SA,BM,WP; update SA,WP | SA,WP | = (BM gains create) |
| DELETE `/adjustments/{id}` + restore + force | `inventory_adjustments,archive` | SA | SA | = |
| GET `/compliance/logs` → read | `exception_detection,read` | SA,BM,AU | SA,AU,BM | = |

### Module K — Audit reads
| GET `/audit-log`, `/audit-trail`, `/audit-trail/user/{id}` | `audit_trail_viewing,read` | SA,BM,AU | SA,AU | = (BM gains) |
| GET `/audit-trail/v2…` (3 routes) | `audit_trail_viewing,read` | SA,BM,AU | SA,AU,BM | = |
| DELETE `/activity-log/{id}` → destroy | `audit_trail_viewing,archive` | SA | SA | = |

### Module L — Archive management
| GET `/archive/summary`,`/archive/{module}` → read | `archive_viewing,read` | SA,IA,BM,AU | SA,BM | = (IA,AU gain reads) |
| POST `/archive/{module}/{id}/restore` → restore | `data_archiving,update` | SA | SA,BM | **Δ** (BM loses) |
| DELETE `/archive/{module}/{id}/force`, POST `/archive/{module}/restore-all` | `data_archiving,{archive,update}` | SA | SA | = |

### Module M — Users & roles (security; migrate, SA-default)
| `users` apiResource + extras → expand to explicit routes | index/show/by-location/archived → `user_account_management,read` (SA,AU); store → create (SA); update/change-password/status/restore → update (SA); destroy/force → archive (SA) | per matrix | admin all | reads gain AU |
| `roles` store/update/destroy | `role_assignment,{create,update,archive}` | SA | SA | = |

> Users/roles stay SA-by-default; they merely become *grantable* (delegation), and the editor's existing SA-protection (admin role + admin users can't be edited) still applies.

---

## 4. Behavior Changes (the reconciliation taking effect)

Migrating makes these cells match the seeded matrix; flag in release notes and cover by tests:

- **archive tightened to SA-only** for `category/brand/uom_management` (IA could delete before).
- **`pos_sales_recording.create`** → SA,BM,WP (IA loses sales create; WP gains).
- **`purchase_order_creation.create`** → SA,IA (BM loses); **update** → SA,IA (was SA,BM).
- **`item_master_management`**: pending-product `create` → SA,IA (BM,WP lose); `approve` → SA,IA (was SA,BM).
- **`data_archiving` archive-restore** → SA (BM loses).
- **Reads gaining roles** (low-risk, GET): users/PO/audit reads gain AU/BM per matrix.

All other migrated routes are behavior-neutral (seeded == current).

---

## 5. Backend Implementation

**Per-route middleware.** Replace each `role:` group with per-route `permission:feature,action`. Where a group wraps multiple actions (e.g. store + update + destroy), split into per-action middleware. Where `apiResource('users', …)` is used inside a `role:admin` group, **expand it** into explicit `Route::get/post/put/delete` lines so each action gets its correct permission.

**Pattern:**
```php
// before
Route::middleware('role:admin,inventory_analyst')->group(function () {
    Route::post('/categories', [CategoryLookupController::class, 'store']);
    Route::put('/categories/{category}', [CategoryLookupController::class, 'update']);
    Route::delete('/categories/{category}', [CategoryLookupController::class, 'destroy']);
});
// after
Route::middleware('permission:category_management,create')->post('/categories', [CategoryLookupController::class, 'store']);
Route::middleware('permission:category_management,update')->put('/categories/{category}', [CategoryLookupController::class, 'update']);
Route::middleware('permission:category_management,archive')->delete('/categories/{category}', [CategoryLookupController::class, 'destroy']);
```

**Resolver performance.** With many guarded routes, register an optional per-user effective-permission cache (Laravel `Cache`) keyed `perm_user_{id}`, invalidated by `PermissionAdminService` on any role-default/override change (add `clearUserPermissionCache`/`clearRolePermissionCache`). Optional — only if profiling shows the per-request resolver queries matter; default keeps the existing per-request memo.

**Seeder addendum (§6).** Add the one missing seeded action used by a migrated route: `delivery_receipt_generation.archive = [SA]`. Re-seed both DBs.

---

## 6. Seeder Addendum

`delivery-receipts` DELETE migrates to `delivery_receipt_generation,archive`, but that feature has no seeded `archive` row. Add it (SA-only, consistent with global archive policy):

```php
'delivery_receipt_generation' => [
    'create' => [$SA, $BM, $WP], 'read' => [$SA, $BM, $WP, $AU],
    'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $BM],
],
```
(Only `archive => [SA]` is new.) Idempotent re-seed adds the row without disturbing edits.

---

## 7. Frontend Enforcement

The Phase-1 bridge already exists: `/me/permissions`, `AuthContext.permissions` + `can(feature, action)`, the `ROUTE_PERMISSION` fallback in `RoleRoute`, and menu surfacing in `AdminLayout`. Phase 2 generalizes these.

### 7a. Route access (`RoleRoute` + `ROUTE_PERMISSION`)
Expand `ROUTE_PERMISSION` (in `roles.js`) to cover every admin route that maps to a permission, e.g. `/admin/items → item_master_management.read`, `/admin/suppliers → supplier_management.read`, `/admin/transactions → pos_sales_recording.read`, etc. `RoleRoute` already: static `ROUTE_ACCESS` allow → render; else if a `ROUTE_PERMISSION` entry exists, wait for `permissions` to load then allow/deny by `can(...)`. **Keep `ROUTE_ACCESS` as the base** (preserve current page visibility); `ROUTE_PERMISSION` only *widens* access to permission-granted users. (Consistent with "preserve current reads": we don't remove anyone's current page access.)

### 7b. Menu overlay (`ROLE_MENUS` + `AdminLayout`)
Add an optional `requiredPermission: ['feature','action']` to menu items in `ROLE_MENUS`. In `AdminLayout`'s `menuSections` builder: an item shows if it's in the role's static menu **OR** (it has `requiredPermission` and `can(...)`). Items already in a role's menu with no `requiredPermission` behave exactly as today. This generalizes the one-off Inventory Operation injection already in `AdminLayout`.

### 7c. Page button gating (the 11 pages)
Replace scattered `isAdmin` / `getRoleKey` / `canActOnApprovalQueue` checks that gate **action buttons** with `can(feature, action)` from `useAuth()`. Migrate page-by-page (one task per page), mirroring the `PurchaseRequestPage` change already done. Inventory of pages using role gating (from grep, 11 files): `PurchaseRequestPage` (done), `PurchaseOrderPage`, `ApprovalQueue`, `ArchiveManagement`, `BarcodeAdjustmentPage`, `BarcodeScan`, `IssuanceScanPage`, `Dashboard`, plus `roles.js` helpers `canActOnApprovalQueue` / `canApprovePendingRegistrations`. Each page task: identify the gated action → map to feature.action → gate on `can(...)`, keeping `isAdmin` as a fallback where the action has no mapped feature.

> **Frontend is UX only — the backend is the security boundary.** Even if a button shows wrongly, the migrated `permission:` route denies the action. So frontend tasks are lower-risk and can trail the backend.

---

## 8. Testing — the regression contract

**Data-driven `RbacRouteMatrixTest`** (`tests/Feature/Rbac/`). For a curated list of representative endpoints (one per migrated `feature.action`), for each of the 5 roles, assert the response is 403 (deny) or not-403 (allow) matching the seeded matrix. Built from a `[method, uri, feature, action]` table; the expected allow/deny is computed from `PermissionResolver`/seeded rows, so the test and the seed can't silently diverge. This is the authoritative contract — a failure is a real regression.

- Use `DatabaseTransactions`, seed via `PermissionSeeder` in `setUp`, create a user per role, `actingAs(...,'sanctum')`.
- For write routes, a 403 means denied; not-403 (200/422/404) means the guard passed. For guarded reads, assert 200 vs 403.
- Add focused tests for each §4 behavior change (e.g. IA denied `pos_sales_recording.create`, WP allowed).
- Keep the existing `ScanSliceTest` + `PurchaseRequestApprovalSliceTest` as-is.

**Frontend:** keep the `permissions.js` jest test; add a small unit test for the menu-overlay filter helper if one is extracted.

---

## 9. Rollout & Risk

- **Incremental:** one backend module per task (Modules A–M), each re-running the matrix test. Frontend pages migrate after the backend is green.
- **Risk:** the §4 behavior changes are the main functional risk — they're intended but visible to users; surface them in the PR description. The unmapped/admin/legacy routes are untouched, bounding blast radius.
- **Reversibility:** each module task is an isolated commit; a bad module can be reverted without touching others.
- **No DB schema change** beyond the one seeded `archive` row (§6).

---

## 10. Out of Scope

- Enforcing currently-open reads (decision 1).
- Rewriting menu construction (decision 2).
- Assigning features to the unmapped routes (§2 "stays").
- Caching is optional/conditional (§5).
</content>
