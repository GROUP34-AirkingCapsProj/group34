# Airking IMS — Unified UI + Navigation Permissions

**Date:** 2026-06-12
**Status:** Approved spec (Stage 1 — Brainstorm complete)
**Builds on:** RBAC Phase 1 (`2026-06-11-rbac-permission-overrides-design.md`) + Phase 2 (`2026-06-11-rbac-phase2-route-enforcement-design.md`), both complete.

---

## 0. Goal & Scope

Unify the Airking frontend into **one React app that every role shares**. The existing Laravel-backed admin app (`frontend/src/admin/`) is the base. What a user sees is driven by **their effective permissions** and **their location scope**, not by a hard-coded per-role menu.

Two new capabilities are layered onto the existing RBAC:

1. **Navigation Bundles** — a sidebar item maps to a set of permissions. A new "Navigation Access" Simple view in the SA permission editor lets an admin grant/revoke a whole page (and its actions) with one toggle, instead of hunting through 91 granular permissions.
2. **Location Scope (reads)** — a reusable backend layer that filters list/read queries by the user's location for location-scoped roles. Write enforcement already exists; this closes the read gap.

**In scope:** the Laravel admin app (all 5 roles), the navigation-bundle system, the read-scoping layer, deprecation of role-keyed menus.

**Out of scope (decided):** the separate Supabase PWA (`frontend/src/pwa/`, `SupabaseAuthContext`, `/pwa/*`). It runs on a different auth + data stack and is left untouched.

### Decisions locked for this spec

| # | Decision | Choice |
|---|----------|--------|
| D1 | Location scope grouping | **Command grouping.** CENTRALIZED = admin(1), inventory_analyst(2), auditor(5); LOCATION_SCOPED = branch_manager(3), warehouse_personnel(4). *Diverges from current write enforcement — see §5.5.* |
| D2 | Supabase PWA | Out of scope; untouched. |
| D3 | Scoped user with `location_id = NULL` | **Deny** data access; show "no location assigned" notice. |
| D4 | Partial bundle grant | **Show the page** (read gate met), **gate actions** by individual permission (consistent with Phase 2 button gating). |

---

## 1. Current State Audit

### 1.1 There is already one shared admin app (not 4–5 separate role UIs)

The command's premise ("admin UI, WP UI, BM UI, IA UI, AU UI") does **not** match the codebase. Reality:

- **One** admin app at `frontend/src/admin/`, entered by all five roles through `/admin/login`.
- Role differentiation today comes from **two role-keyed mechanisms**, not separate apps:
  - `ROLE_MENUS` in `frontend/src/admin/utils/roles.js` — a per-role sidebar definition (admin = `null` = full menu; IA/BM/WP/AU each get a hand-curated subset).
  - Role-specific dashboard **pages**: `WarehousePersonnelDashboard.jsx`, `BranchManagerDashboard.jsx`, `InventoryAnalystDashboard.jsx`, `AuditorDashboard.jsx`, plus the generic `Dashboard.jsx`.
- A **separate** second app exists: the Supabase PWA at `frontend/src/pwa/` (`PwaLayout`, `PwaDashboard`, `PwaScan`, `PwaProducts`, `SupabaseAuthContext`). Different backend (Supabase, not Laravel). **Out of scope (D2).**

**So "unification" = replace the role-keyed `ROLE_MENUS` with a permission-driven sidebar.** The app is already single-entry; the menu is the part still keyed by role.

### 1.2 Routing today

Single React Router tree in `frontend/src/App.jsx`. Every authenticated page is `/admin/*` wrapped in `<RoleRoute>`. There are **no** `/warehouse`, `/branch-manager`, etc. prefixes. The PWA has its own `/pwa/*` subtree under `PwaProtectedRoute`. (Point C of the command — "everyone goes to /admin/*" — is already true.)

### 1.3 Sidebar / menu system

- `frontend/src/admin/components/Sidebar.jsx` exports `adminMenuSections` — the canonical **full** menu (5 sections: ADMINISTRATION, PROCUREMENT & PLANNING, INVENTORY INTELLIGENCE, SALES & LOGISTICS, COMPLIANCE & SUPPORT). Items carry `{ name, path, icon, adminOnly? }`.
- `frontend/src/admin/components/AdminLayout.jsx` builds the live sidebar:
  1. `ROLE_MENUS[roleKey] || adminMenuSections` (role menu, or full menu for admin).
  2. For non-admin roles, filters out `adminOnly` items.
  3. A small **permission overlay**: a hard-coded `overlayItems` list (currently just "Inventory Operation") that appends an item if `can(feature, action)` is true even when the role menu omits it.
- This overlay is the seed of the bundle idea, but it is hard-coded and one-off.

### 1.4 AuthContext (`frontend/src/admin/context/AuthContext.jsx`)

Exposes: `user`, `updateUser`, `refreshUser`, `clearSession`, `permissions` (the `{ "feature.action": bool }` map from `/me/permissions`, `null` until loaded), `can(feature, action)`.
Plus `useUserLocation()` hook returning `{ locationId, locationName, locationType, isStrictLocation, allowedLocations, canChooseLocation }` — already wired to the user's enforced-location fields after `refreshUser()`.

### 1.5 RBAC backend (Phase 1 + 2 — done)

- Matrix lives in the **DB**, not config: tables `permissions` (feature, action), `role_permissions` (role default grants), `user_permission_overrides` (per-user grant/revoke with reason + audit). *(The memory note referencing `config/rbac.php` is stale — no such file exists.)*
- `App\Support\PermissionResolver`: `canUser($userId, $feature, $action)` (override → role default → deny) and `effectivePermissionsFor($userId)` → `{ "feature.action": bool }`.
- `App\Http\Middleware\CheckPermission` → `permission:feature,action` route middleware.
- `GET /api/me/permissions` → `PermissionController::mine` → the effective map.
- `User::can('feature','action')` overridden to hit the resolver (two-string form only).
- 91 granular permissions across 12 feature groups (catalog mirrored in `frontend/src/admin/utils/permissions.js` → `FEATURE_GROUPS`).
- SA editor pages: `RolePermissions.jsx` (role matrix), `UserPermissionOverrides.jsx` (per-user overrides), `PermissionAuditLog.jsx`.

### 1.6 Location scope today — writes enforced, reads NOT

**Exists (write side):**
- `User::getEnforcedLocationId()`, `getAllowedLocationIds()`, `canTransactAt()`, `hasStrictLocation()` (currently `[2,4]`).
- `App\Traits\ResolvesUserLocation::resolveLocationId()` — used by scan/transaction controllers to force the correct `location_id` on writes (strict roles ignore request input; admin/BM may choose within allowed).
- `App\Http\Middleware\EnsureAssignedLocationAccess` — pre-injects `location_id` on non-GET requests for strict roles.
- `App\Http\Middleware\CheckBranch`, `user_locations` pivot.

**Missing (read side):** List endpoints return **all data** unless the caller passes `location_id` explicitly. Example: `InventoryController::index` only adds `where('location_id', …)` when `$request->has('location_id')`. There is **no** `isCentralized()`/`scopedLocationId()` helper and **no** read-scoping anywhere (`grep` for `centralized|scopeToUser|scopedLocationId` → 0 hits). This is the core gap §5 fills.

### 1.7 What works vs. what's missing

| Works today | Missing / to build |
|-------------|--------------------|
| Single admin app, single `/admin/*` route tree | Permission-driven sidebar (replace `ROLE_MENUS`) |
| `permissions` map + `can()` in frontend | `nav_permission_bundles` config + Simple "Navigation Access" tab |
| Route guard `RoleRoute` + `ROUTE_PERMISSION` overlay | Bundle-aware sidebar builder + bundle grant/revoke API |
| Per-user overrides + audit (Phase 1) | Read-side location scoping (`isCentralized`/`scopedLocationId`/query scope) |
| Button-level gating (Phase 2) | Data-scope UI indicator (location badge / switcher) |
| Write-side location enforcement | Permission-driven (not role-driven) dashboard widgets |

---

## 2. Navigation Bundle Table

One bundle = one sidebar item. **Required** permission(s) gate page visibility (read gate). **Action** permissions are granted together with the bundle and gate buttons inside the page (D4: partial grant still shows the page).

Source: `adminMenuSections` (the canonical superset) cross-referenced with `ROUTE_PERMISSION` (`roles.js`) and the permission catalog (`permissions.js` → `FEATURE_GROUPS`).

> **Notation:** `feature.action`. Required column = read gate. Actions = included in the bundle.

### Section: ADMINISTRATION

| Bundle | Path | Required (read gate) | Action permissions in bundle |
|--------|------|----------------------|------------------------------|
| Dashboard | `/admin/dashboard` | `role_dashboards.read` | `summary_cards.read`, `quick_actions.read` |
| Approval Queue | `/admin/approval-queue` | `purchase_order_approval.read` | `purchase_order_approval.approve`, `restock_approval.approve`, `item_master_management.approve` |
| Analytics Dashboard | `/admin/analytics` | `stock_level_analysis.read` | `consumption_analysis.read`, `inventory_turnover_analysis.read` |
| User Management | `/admin/users` | `user_account_management.read` | `…create/update/archive`, `role_assignment.update` |
| Role & Permissions | `/admin/roles` | `rbac_enforcement.read` | `role_assignment.update`, `rbac_enforcement.update` |
| User Overrides | `/admin/user-permissions` | `rbac_enforcement.read` | `rbac_enforcement.update` |
| Permission Audit | `/admin/permission-audit` | `audit_trail_viewing.read` | — |
| Location Management | `/admin/branches` | `location_based_inventory.read` | `…create/update/archive` |
| Archive Management | `/admin/archive` | `archive_viewing.read` | `data_archiving.archive` |
| System Configuration | `/admin/config` | *(admin-only — see §2.1)* | — |

### Section: PROCUREMENT & PLANNING

| Bundle | Path | Required | Actions |
|--------|------|----------|---------|
| Budget Management | `/admin/budget-management` | *(admin-only — §2.1)* | — |
| Purchase Requests | `/admin/purchase-requests` | `purchase_request_creation.read` | `purchase_request_creation.create`, `purchase_request_approval.approve` |
| Purchase Orders | `/admin/draft-po-creator` | `purchase_order_creation.read` | `purchase_order_creation.create`, `…update`, `supplier_management.read` |
| PO Recommendation | `/admin/po-recommendations` | `restock_recommendation.read` | `restock_quantity.read`, `restock_approval.approve` |
| PO Receiving | `/admin/receivings` | `delivery_receiving.read` | `inventory_receiving.create` |
| Supplier Network | `/admin/suppliers` | `supplier_management.read` | `…create/update`, `item_supplier_mapping.update` |
| Item Master | `/admin/items` | `item_master_management.read` | `…create/update/archive`, `barcode_assignment.create` |
| Categories and Brands | `/admin/categories` | `category_management.read` | `…create/update`, `brand_management.read/create/update` |

### Section: INVENTORY INTELLIGENCE

| Bundle | Path | Required | Actions |
|--------|------|----------|---------|
| Inventory Monitoring | `/admin/inventory` | `centralized_inventory.read` | `automated_inventory_monitoring.read`, `location_based_inventory.read` |
| Transfer Requests | `/admin/transfer-requests` | `inventory_transfers.read` | `inventory_transfers.create` |
| Stock Transfers | `/admin/transfers` | `inventory_transfers.read` | `…create/approve` *(adminOnly today)* |
| Interbranch Transfers | `/admin/ist` | `inventory_transfers.read` | `…create/update/approve` |
| Adjustments | `/admin/adjustments` | `inventory_adjustments.read` | `…create/approve` |
| Issuances | `/admin/issuances` | `inventory_issuance.read` | `inventory_issuance.create` |

### Section: SALES & LOGISTICS

| Bundle | Path | Required | Actions |
|--------|------|----------|---------|
| POS Operations | `/admin/transactions` | `pos_sales_recording.read` | `pos_sales_recording.create`, `barcode_scanning_sales.create`, `sales_validation.create` |
| Transactions | `/admin/transactions` | `pos_transaction_logs.read` | `transaction_validation.read` |
| Purchase Orders (PO tab) | `/admin/purchase-orders` | `purchase_order_approval.read` | `purchase_order_approval.approve` |
| Customer Registry | `/admin/customers` | `customer_info_capture.read` | `…create/update` |
| Scan Barcode / Inventory Operation | `/admin/inventory-operation` | `barcode_inventory_processing.create` | — |
| Issuance Scan | `/admin/issuance-scan` | `inventory_issuance.create` | — |
| Delivery Receipts | `/admin/delivery-receipts` | `delivery_receipt_generation.read` | `delivery_receipt_generation.create` |

### Section: COMPLIANCE & SUPPORT

| Bundle | Path | Required | Actions |
|--------|------|----------|---------|
| Compliance Review | `/admin/compliance` | `exception_detection.read` | — |
| System Reports | `/admin/reports` | `inventory_reports.read` | `transaction_reports.read`, `audit_reports.read`, `export_reports.create` |
| Audit Trail | `/admin/audit` | `audit_trail_viewing.read` | `transaction_logs_audit.read`, `login_logs.read` |
| Alerts & Notices | `/admin/alerts` | `notification_panel.read` | — |
| Warranty Records / Hub / Claims | `/admin/warranties`, `/admin/warranty`, `/admin/warranty-claims` | *(no catalog feature — see §2.2)* | — |

### 2.1 Admin-only pages with no catalog feature

`System Configuration` (`/admin/config`) and `Budget Management` (`/admin/budget-management`) have no permission feature in the catalog and are admin-only in `ROUTE_ACCESS`. **Confirmed (locked):** keep them **admin-only** (not bundle-driven) for this phase. Adding catalog features (`system_configuration.*`, `budget_management.*`) is a later follow-up.

### 2.2 Warranty pages — catalog gap

Warranty pages (`/admin/warranties`, `/admin/warranty`, `/admin/warranty-claims`) are not represented in the 12 feature groups; today `ROUTE_ACCESS` opens warranties to all roles. **Confirmed (locked):** warranty stays **role-open** for this phase. Adding a `warranty_management` feature (create/read/update/approve) so warranty joins the bundle system is a later follow-up — not silently mapped now.

---

## 3. New Database Tables

Config tables, seeded once, **not** user-editable through the UI (only via migration/seeder). User grants stay in the existing `user_permission_overrides` — the bundle layer is a *convenience that writes overrides*, it does not introduce a new grant store.

### `nav_permission_bundles`

| Column | Type | Notes |
|--------|------|-------|
| `bundle_id` | PK, unsigned int | |
| `name` | string | Matches sidebar label (e.g. "Purchase Orders") |
| `route_path` | string | e.g. `/admin/draft-po-creator` |
| `sidebar_section` | string | e.g. `PROCUREMENT & PLANNING` |
| `sidebar_icon` | string | icon key understood by `Sidebar.jsx` `renderIcon` |
| `description` | string, nullable | short blurb for the Simple-view card |
| `sort_order` | int | ordering within section |
| `is_active` | bool, default 1 | |

### `nav_bundle_permissions`

| Column | Type | Notes |
|--------|------|-------|
| `bundle_id` | FK → `nav_permission_bundles` | |
| `permission_id` | FK → `permissions` | |
| `is_required` | bool | `true` = read gate (must have to see page); `false` = action permission included in the bundle |

Composite PK `(bundle_id, permission_id)`.

**Seeder** translates §2 into rows. Re-runnable (upsert on `route_path` + `permission_id`).

---

## 4. SA Permission Editor — Simple View

New tab **"Navigation Access"** added to the existing per-user editor (`UserPermissionOverrides.jsx`). The current 91-toggle granular grid becomes the **"Advanced"** tab (unchanged from Phase 1/2).

### Behaviour

- Lists all active bundles as **toggle cards**, grouped by `sidebar_section`. Each card shows: page name, icon, section, description, and **current state**.
- **Current state** of a bundle for the target user is derived from their effective permissions (`GET /api/users/{id}/permissions` → `effective`):
  - **On** — all `is_required` permissions are effectively granted.
  - **Partial** — some but not all required granted (badge "Partial"); toggle still rendered as off-leaning with a hint.
  - **Off** — no required permissions granted.
- **Toggle ON** → grants every permission in the bundle (required + action) for this user, by writing `user_permission_overrides` (`is_granted = true`) via the existing `PUT /api/users/{id}/permissions` endpoint (a `reason` is required — UI supplies "Granted via Navigation Access: {bundle name}").
- **Toggle OFF** → revokes (sets override `is_granted = false`) every permission in the bundle for this user.

### API additions

- `GET /api/nav-bundles` → bundle catalog with nested permissions (id, feature, action, is_required). Any authenticated SA.
- The grant/revoke itself **reuses** `PUT /api/users/{id}/permissions` (no new write endpoint) — the frontend expands a bundle into its permission-change list. This keeps a single audited write path (Phase 1 audit log captures each cell).

> **Why overrides, not a separate "bundle grant" record:** effective-permission resolution already overlays overrides on role defaults. Expanding bundles into overrides means the sidebar, route guard, and button gating all keep working with zero new resolution logic.

---

## 5. Location Scope Filtering System (reads)

A reusable layer so list/read endpoints return only data the user is allowed to see.

### 5.1 Scope definition (D1 — command grouping)

```
CENTRALIZED   = role_id ∈ {1 admin, 2 inventory_analyst, 5 auditor}  → no location filter
LOCATION_SCOPED = role_id ∈ {3 branch_manager, 4 warehouse_personnel} → filter by users.location_id
```

### 5.2 A. User helpers (new, on `App\Models\User`)

```php
public function isCentralized(): bool      // role_id ∈ {1,2,5}
public function scopedLocationId(): ?int   // null if centralized; else location_id (may be null → caller denies, D3)
```

A `LocationScope` concern (trait) may hold these to keep `User` lean; either is acceptable. These two methods are the **single source of truth** for read scope.

### 5.3 B. Reusable query scope

A local Eloquent scope usable on any model with a `location_id` column:

```php
// Trait App\Models\Concerns\ScopesToUserLocation, or a global macro
public function scopeForUser($query, User $user) {
    if ($user->isCentralized()) return $query;          // see all
    $loc = $user->scopedLocationId();
    if ($loc === null) return $query->whereRaw('1 = 0'); // D3: deny — empty result
    return $query->where($this->getTable().'.location_id', $loc);
}
```

Usage: `Inventory::query()->forUser($request->user())->…`

### 5.4 C. Controller helper

A base-controller method (or trait method) controllers call to get the scope decision:

```php
protected function locationScope(User $user): ?int  // null = unfiltered (centralized); int = filter value
// and a guard that returns the D3 "no location assigned" 422 when scoped + null
```

Controllers that read list data apply `->forUser($user)` (preferred) or branch on `locationScope()`.

### 5.5 D. Controllers needing read-scoping

From `grep location_id` across `app/Http/Controllers` (24 files, 187 hits). The following return location-bearing list/read data and must apply the scope:

| Controller | Endpoint(s) | Notes |
|------------|-------------|-------|
| `InventoryController` | `index`, `byLocation`, `stockHistory` | `index` currently unfiltered — main offender |
| `TransferController` / `TransferRequestController` / `ISTController` | list/index | source/destination location filtering |
| `IssuanceController` / `IssuanceScanController` | index | |
| `ReceivingController` | index | |
| `AdjustmentController` / `BarcodeAdjustmentController` | index | |
| `TransactionController` / `SaleController` | index | POS/sales data |
| `PurchaseOrderController` / `PurchaseRequestController` | index | scope POs by originating location where applicable |
| `DashboardController` | stats/summaries | widgets must respect scope (§6.D) |
| `InventoryScanTransactionController` / `InventoryScanEventController` | index/history | |
| `PendingProductController` | index | |
| `WarrantyClaimController` | index | if location-bearing |

**Reconciliation note (important):** D1 changes role classification vs. today's write enforcement:
- `inventory_analyst(2)` is currently `hasStrictLocation` (strict-single) but D1 makes it **centralized**.
- `branch_manager(3)` is currently a multi-location chooser but D1 makes it **location-scoped to a single `location_id`**.

To avoid a system where reads and writes disagree, the plan must **realign the write side** to the same grouping: update `hasStrictLocation()` / `ResolvesUserLocation::resolveLocationId()` so IA writes are centralized-choose and BM writes are single-location-scoped — OR consciously accept reads/writes using different groupings (not recommended). **Confirmed (locked): write enforcement WILL be realigned to match reads.** `isCentralized()` and `scopedLocationId()` are the single source of truth for **both** reads and writes. `hasStrictLocation()` / `ResolvesUserLocation::resolveLocationId()` are refactored to derive from these. This is the highest-risk part of the work and gets its own dedicated plan phase.

---

## 6. Unified Frontend Architecture

### A. Single entry + routing — already done

`/admin/*` for everyone, `RoleRoute` + `ROUTE_PERMISSION` already gate pages (Phase 2). No new routes. The work is the sidebar + dashboard, not routing.

### B. Permission-driven sidebar (replaces `ROLE_MENUS`)

`AdminLayout` builds the sidebar from `adminMenuSections` (the canonical superset) filtered by **bundle access**, instead of selecting a hand-curated `ROLE_MENUS[role]`:

1. Fetch bundles once (`GET /api/nav-bundles`) — or embed the bundle→required-permission map in the frontend mirror (like `ROUTE_PERMISSION`) to avoid a round trip. **Recommendation:** ship a generated `navBundles.js` mirror (kept in sync with the seeder, same pattern as `roles.js` mirroring backend) so the sidebar renders instantly from the already-loaded `permissions` map.
2. For each `adminMenuSections` item, find its bundle; show the item iff the user satisfies the bundle's **required** permissions (`can(feature, action)` for each required).
3. Admin (whose `permissions` map grants everything) sees the full menu naturally — no `roleKey === ADMIN` special-case needed.
4. Retire `ROLE_MENUS` and the hard-coded `overlayItems` block once parity is verified.

This makes "what's in the sidebar" a pure function of effective permissions — exactly matching route guard and button gating.

### C. Data-scope indicator

- **Scoped users (BM, WP):** a badge near the header — "Showing data for: {Location Name}" — from `useUserLocation().locationName`. Existing components `LocationBadge.jsx` / `LocationSelector.jsx` can be reused.
- **Centralized users (admin, IA, auditor):** "All Locations", with an optional location filter/switcher that sets the `location_id` query param on list calls (read-only narrowing; does not change their centralized rights).

### D. Permission-driven dashboard

Dashboard widgets render based on **permissions, not role**. Each widget (PO summary, low-stock, IST queue, sales, etc.) declares the permission it needs; the dashboard shows a widget iff `can(...)`. A WP granted PO access sees PO cards. The role-specific dashboard pages (`WarehousePersonnelDashboard`, etc.) collapse into one permission-aware `Dashboard.jsx` (their widgets become permission-gated blocks). **Hidden, not zeroed** (§8): a widget the user can't access is not rendered.

---

## 7. Migration Plan — retire role-keyed menus (one role at a time)

Pilot with **WP** (smallest `ROLE_MENUS`), then BM → IA → AU. Admin already uses the full menu.

For each role:
1. **Audit** the role's current `ROLE_MENUS[role]` items.
2. **Map** each to a bundle and confirm the role's default `role_permissions` satisfy the bundle's required perms (so the permission-driven sidebar reproduces today's menu). Patch role defaults where there's a gap.
3. **Verify read-scoping** behaves for that role (scoped vs centralized per D1).
4. **Flip** that role to the permission-driven sidebar path (feature flag per role, or remove its `ROLE_MENUS` entry so it falls through to the bundle-filtered `adminMenuSections`).
5. **Test** with a real account for that role: sidebar parity, route access, button gating, location scope.
6. **Remove** the role's `ROLE_MENUS` entry.

When all four entries are gone, delete `ROLE_MENUS` and the `overlayItems` block; fold role dashboard pages into the unified `Dashboard.jsx`.

> No separate-app deletion step (the command's "remove old role UI files") applies — there are no separate role apps. The deletions are `ROLE_MENUS` entries and the redundant role-dashboard pages.

---

## 8. Risks & Edge Cases

| Case | Decision / handling |
|------|---------------------|
| SA revokes a bundle mid-session | `permissions` refetches on next load; `RoleRoute` already redirects to `/admin/dashboard` when access is lost. **Add:** a lightweight permissions re-poll (or refetch on route change) so a revoked page auto-redirects without a manual reload. |
| Scoped user, `location_id = NULL` | **D3 — deny.** `scopeForUser` returns an empty set; UI shows "No location assigned — contact your administrator" (mirrors the existing write-side `LOCATION_NOT_ASSIGNED` 422). |
| Partial bundle grant | **D4 — show the page** (read gate met), **gate each action button** by its own permission (Phase 2 pattern). Simple-view card shows a "Partial" badge. |
| Dashboard widget for inaccessible data | **Hide** the widget (do not render). No zeroed cards, no error tiles. |
| Reads vs writes scope mismatch (IA/BM reclassified) | Highest risk. Realign write enforcement to the same `isCentralized()`/`scopedLocationId()` source of truth (§5.5). Dedicated plan phase + regression test for write location enforcement. |
| Admin special-cases scattered in UI | Removing `roleKey === ADMIN` branches could hide things if admin's `role_permissions` aren't actually complete. Verify admin role defaults grant all required perms before deleting special-cases. |
| Catalog gaps (warranty, config, budget) | Stay admin-only / role-open until catalog features are added (§2.1, §2.2). Don't silently fabricate permission mappings. |

---

## Open follow-ups (not blocking)

1. Add `warranty_management` catalog feature so warranty joins the bundle system (§2.2).
2. Decide whether `system_configuration` / `budget_management` become delegable features or stay admin-only (§2.1).
3. Update the stale memory note about `config/rbac.php` (matrix is DB-resident).
