# Role Dashboards Redesign — Real, Location-Scoped Data + ak- UI

**Date:** 2026-06-22
**Branch:** backupV3
**Scope:** Rebuild the four non-admin role dashboards so they show correct, real,
location-scoped data and adopt the unified `ak-` design system. The **Admin**
dashboard (`Dashboard.jsx`) is explicitly out of scope — leave it untouched.

## Problem

The role dashboards (`InventoryAnalystDashboard`, `BranchManagerDashboard`,
`WarehousePersonnelDashboard`, `AuditorDashboard`) appear to show no real data.
Root causes:

1. **Broken batch data path.** Each dashboard fetches via
   `batchAPI.get({ include: ['dashboard'] })`. `BatchController::index()` builds
   that key with `app(DashboardController::class)->index()` — calling
   `index(Request $request)` with **no argument**, which throws
   `ArgumentCountError`. The surrounding try/catch swallows it and returns
   `['success' => false, 'message' => ...]`, so `dashData.stats` is undefined and
   every KPI/chart falls back to empty/zero.
2. **Sub-builders are not location-scoped.** Even when reachable, only
   `getStats($user)` scopes by location. `getInventoryByCategory`,
   `getLocationDistribution`, `getStockStatusByLocation`, `getWarehouseCapacity`,
   `getStockTrends`, `getSalesTrends`, and `getRecentActivity` query **all
   locations**, which would leak other branches' data to scoped roles.
3. **Frontend key mismatches & placeholders.** e.g. the analyst reads
   `dashData.branch_distribution` but the API returns `location_distribution`;
   `AuditorDashboard` has a hardcoded empty `auditRows = []`.
4. **Inventory list is not auto-scoped.** `InventoryController::index()` filters
   by location only when `location_id` is explicitly passed, so a scoped role
   calling `inventoryAPI.getAll()` would receive all locations' rows.

## Scoping Rule (authoritative)

- **Centralized (see all locations):** Admin (1), Inventory Analyst (2),
  Auditor (5) — `User::isCentralized() === true`.
- **Scoped (own location only):** Branch Manager (3), Warehouse Personnel (4) —
  scoped via `User::scopedLocationId()`.

Scoping is enforced **server-side** in the dashboard payload so the UI cannot
leak data regardless of frontend code.

## Approach (chosen)

Harden the single existing `DashboardController::index()` into one role-aware,
location-scoped endpoint. Reuse it for all dashboards (each renders the subset it
needs). Rejected: per-role endpoints (more code/drift); frontend assembly from
granular endpoints (inventory list isn't auto-scoped → leak-prone).

## Backend Changes

`back-end/app/Http/Controllers/Api/DashboardController.php`
- `index(Request $request)`: resolve `$user = $request->user()` and pass it to
  **every** sub-builder.
- Add `?User $user = null` to each sub-builder and apply
  `->when($user && !$user->isCentralized(), fn ($q) => $q->where('location_id', $user->scopedLocationId()))`
  (or the table-appropriate column) to its query. For builders that already
  aggregate per-location (`getLocationDistribution`, `getStockStatusByLocation`,
  `getWarehouseCapacity`), restrict the location set to the user's location when
  scoped.
- Keep response keys stable (no new keys). The `branch_distribution` mismatch is
  fixed on the frontend by reading the existing `location_distribution` key.

`back-end/app/Http/Controllers/Api/BatchController.php`
- Pass the current request:
  `app(DashboardController::class)->index(request())`.

**Verification:** a Feature test asserting that a scoped user (BM/WP) receives a
payload whose location-bearing collections contain only their `location_id`, and
a centralized user receives all locations. (Schema is a SQL dump, not
migrations — use `DatabaseTransactions` against the test DB per
`project_db_migration_test_convention`.)

## Frontend Changes

Rebuild each dashboard with the `ak-` design system: `ak-page` wrapper,
`ak-header` command header, `ak-stat-grid`/`ak-stat` KPI row, `ak-card` panels
for charts, `ak-table`/list for actionable items, `ak-state` for empty states.
Charts stay on Chart.js (`react-chartjs-2`), restyled to crimson/gold.

Data source: the scoped `index()` payload (via the existing
`batchAPI.get({ include: ['dashboard'] })`). Any inventory-list call for scoped
roles passes `location_id = user.location.location_id` explicitly. Fix the
`branch_distribution` → `location_distribution` read.

Per-role content:

- **BranchManagerDashboard** (scoped): KPIs — SKUs on hand, low-stock, pending
  approvals (POs+restock), stock units. Chart — stock movement trend (in vs out,
  30d). Lists — low-stock items; pending approvals awaiting authorization.
- **WarehousePersonnelDashboard** (scoped): KPIs — SKUs at warehouse, items
  awaiting receipt, transfers to receive/dispatch, low-stock. Chart — inventory
  by category. Lists — incoming transfers to receive/dispatch; low-stock items.
- **InventoryAnalystDashboard** (centralized): KPIs — total SKUs, low-stock,
  out-of-stock, total stock units. Charts — inventory by category; stock status
  by location (stacked healthy/low); stock movement trend. List — top low-stock
  items across locations.
- **AuditorDashboard** (centralized, read-only): KPIs — transactions (30d),
  recent changes, pending approvals, adjustments/discrepancies. Chart — activity
  trend. List — recent audit-trail feed (real `audit_trail`, action badges).

Empty-but-valid data must render a friendly `ak-state` ("No low-stock items"),
never a broken/blank panel.

## Out of Scope

- Admin `Dashboard.jsx` (unchanged).
- New report types, exports, or date-range pickers (YAGNI).
- Changing the inventory listing endpoint's default scoping (handled by passing
  `location_id` from the dashboards; a broader fix is a separate concern).

## Success Criteria

1. All four dashboards display real numbers/charts from the DB, not zeros/empties.
2. A Branch Manager / Warehouse Personnel sees **only** their location's data;
   an Inventory Analyst / Auditor sees all locations.
3. Each dashboard matches the `ak-` visual language used across the app.
4. Backend scoping covered by a passing Feature test; frontend builds clean
   (`CI=false react-scripts build`).
