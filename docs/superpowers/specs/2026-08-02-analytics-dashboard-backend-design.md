# Analytics Dashboard — Real Data Backend

**Date:** 2026-08-02
**Branch:** backupV3
**Status:** Design approved, ready for implementation planning

## Problem

`frontend/src/admin/pages/AnalyticsDashboard.jsx` renders five tabs of inventory
analytics but shows empty or meaningless numbers. It is not a UI mock — it does
call `GET /api/batch` and compute every metric client-side. It fails because the
computation reads fields that do not exist and invents the ones it cannot find.

### Confirmed defects

1. **Location filter zeroes the page.** The filter is populated from `branches`
   (`Branch` is a proxy over `locations` where `location_type = 'branch_office'`,
   so warehouses are excluded entirely). `normalizeInventoryRecord` then derives
   `branch_id` from `inv.branch_id ?? loc.branch_id ?? branch?.id` — but
   `Inventory` has only `location_id` (`app/Models/Inventory.php:17`). `branch_id`
   is always `undefined`, so selecting any location filters every row out and the
   whole page reads zero.

2. **Location Performance collapses to one bucket.** It groups by that same
   missing `branch_id`, so every inventory row lands under `"Unknown Location"`.

3. **Profit Loss can never show data.** It classifies losses by string-matching
   transaction `notes` for `damaged` / `stolen` / `theft`. `BatchController` only
   emits sales and issuances, whose notes are always `"Invoice …"` or
   `"Issue Out …"`, and whose `type` is `sale` / `issuance`, never `adjustment`.
   The real loss data in `incident_reports`, `adjustments` and `profit_loss` is
   never touched.

4. **Stock thresholds are fabricated.** `max_quantity` defaults to
   `max(reorder * 5, 50)` and `reorder_level` to `10`, while `products` actually
   carries real `recommended_stocks`, `safety_stock` and `max_stock_level`
   columns. Optimal / Overstock / "consumption rate" are therefore fiction.

5. **Predictions reinvents a shipped engine.** `RestockAnalyticsService` already
   derives demand velocity, lead times, safety stock and days of cover, persists
   to `restock_recommendations`, and is exposed at `/api/restock/recommendations`.
   The tab ignores all of it and estimates daily demand as `totalQty / 30`.

6. **Totals silently truncate.** `/api/batch` paginates `inventory` and `items` at
   500 rows and caps transactions at 4,000, so org-wide totals are wrong whenever
   the data exceeds those caps, with no indication in the UI.

7. **The endpoint is ungated.** `/api/batch` carries no `permission:` middleware
   and calls controller `index()` methods directly in PHP, bypassing route-level
   gates by design (documented in `BatchController`'s class docblock).

### Database state (verified 2026-08-02, `airkingV5`)

The dev database holds **master data only**. There is no operational history at
all:

| Populated | Empty |
|---|---|
| `products` 336, `locations` 3, `users` 12 | `inventory` **0** |
| `stock_parameter_suggestions` 336 | `sales_details` **0**, `issuance_details` **0** |
| lookups, permissions, nav bundles | `adjustments` **0**, `adjustment_details` **0** |
| `suppliers` 2, `customers` 1 | `incident_reports` **0**, `profit_loss` **0** |
| | `restock_recommendations` **0** |

None of the nine seeders in `database/seeders/` produce operational data.

This reframes the problem. The seven defects above are real and were each
verified by reading code, and they would still break the page once data exists —
but the *dominant immediate reason* the dashboard is blank is that there is
nothing to display. Fixing the code alone would render zeros in every tile, which
is visually indistinguishable from the current bug.

The `locations` table also confirms defect 1 by direct observation rather than
inference:

| location_id | name | type |
|---|---|---|
| 1 | CDO Warehouse A | `warehouse` |
| 2 | CDO Main Showroom | `showroom` |
| 3 | CDO Warehouse B | `warehouse` |

There are **zero** `location_type = 'branch_office'` rows, and the filter is
populated from `Branch`, which queries exactly that type. The "All Location"
dropdown is therefore empty on screen today. It also means the two warehouses
(1 and 3) give the warehouse-scoped Predictions tab real coverage, while the
showroom carries the sales activity.

## Constraints

- **No UI markup is removed, added or restyled.** Every tile, list, table, bar and
  chart present today stays exactly where it is. Only the source of each number
  changes. The single copy change is the Predictions subtitle (see below).
- **Accuracy bar: reconcile with the rest of the system.** Every number must agree
  with what Inventory, Incident Reports, Adjustments and the restock engine
  already show. No invented thresholds, no string-matched loss data.
- **Audience:** admin, Inventory Analyst and Auditor only. All three are
  centralized roles, so no per-user location scoping is required on this page.

## Architecture

### Backend

`app/Services/Analytics/DashboardAnalyticsService.php` — one service, five public
read methods. Each accepts an optional `location_id` and `category_id` filter and
returns arrays shaped exactly like the UI's existing `tabData` sub-objects, so the
JSX binds to them without edits.

| Method | Feeds tab |
|---|---|
| `stockLevel(?int $locationId, ?int $categoryId)` | Stock Level Analysis |
| `movement(?int $locationId, ?int $categoryId)` | Movement & Turnover |
| `predictions(?int $locationId, ?int $categoryId)` | Predictions & Restocking |
| `profitLoss(?int $locationId, ?int $categoryId)` | Profit Loss Analysis |
| `locationPerformance(?int $categoryId)` | Location Performance |

`app/Http/Controllers/Api/AnalyticsDashboardController.php` — five thin actions
plus `filters()`. Routes, all gated `permission:analytics_dashboard,read` (the
slug already exists — see `frontend/src/admin/utils/navBundles.js:4`):

```
GET /api/analytics/stock-level
GET /api/analytics/movement
GET /api/analytics/predictions
GET /api/analytics/profit-loss
GET /api/analytics/location-performance
GET /api/analytics/filters
```

Gating these closes defect 7: the page's data is currently reachable by any
authenticated user regardless of role.

Aggregation happens in SQL, so the pagination caps of defect 6 disappear.

### Frontend

Add `analyticsAPI` to `frontend/src/admin/services/api.js`. The page fetches only
the active tab's endpoint and assigns the response into `tabData`.

Deleted from `AnalyticsDashboard.jsx` (~350 lines, all computation, no markup):
`classifyStock`, `inventoryPiecePrice`, `buildItemTxCounts`, `buildItemAnalysis`,
`applyFilters`, `normalizeInventoryRecord`, `normalizeTransactionRecord`, the
`TAB_RESOURCES` map, and the five `switch (activeTab)` branches in the processing
effect.

Preserved: tab state, the two filter selects, per-tab fetch caching, the
`initialLoading` / `refreshing` / `error` states, `Loader`, both memoized
Chart.js configs, and all JSX.

## Data contracts

### Stock Level Analysis

Classification, per inventory row, using real columns:

| Bucket | Rule |
|---|---|
| Critical | `quantity_on_hand = 0` |
| Low Stock | `0 < qty <= COALESCE(inventory.reorder_level, products.recommended_stocks)` |
| Overstock | `qty > products.max_stock_level` (rows with a null `max_stock_level` are never overstock) |
| Optimal | everything else |

Per-location `inventory.reorder_level` wins where set, so existing per-location
tuning is honored; `products.recommended_stocks` is the fallback.

Stock value = `qty × unit_price`, honoring the existing per-piece divisor for
`product_type = 'installation_material'` (via the `pieces_per_package` accessor)
so consumables are not valued at package price per piece.

Reorder compliance rate = `(total - belowReorderPoint) / total`, where
`belowReorderPoint` = Critical + Low. Preserves today's displayed semantics.

`criticalItems` and `overstockItems` keep their existing shapes, with `location`
now resolved from the real `locations` row rather than a missing `branch`
relation.

### Movement & Turnover

Demand comes from `RestockAnalyticsService::dailyDemandByLocation()` — sales plus
non-voided issuance details, per location per product per day, with the
installation-material piece conversion already applied. Reusing it guarantees the
Movement and Predictions tabs tell the same story from one demand definition.

**Required change:** promote `dailyDemandByLocation()` from `private` to `public`.
It is a pure read with no side effects; no call site changes, no behavior change,
no risk to the existing procurement tests.

- `consumptionRate` — real units consumed over the trailing 30 days, rendered on
  the existing percentage bar as a share of the top mover in the result set.
- `turnoverRate` — 30-day consumption ÷ current stock on hand, retaining the
  existing `Nx/mo` display.
- Fast-moving `>6x/month`, slow-moving `<3x/month` — thresholds unchanged.
- `weeklyMovement` — the same demand series bucketed into the trailing 4 weeks.

### Predictions & Restocking

Reads persisted `restock_recommendations`, whose columns map 1:1 onto the tab:

| Column | UI slot |
|---|---|
| `current_stock` | Current |
| `reorder_level` | Reorder Point |
| `recommended_quantity` | Suggested Qty |
| `priority` | Priority badge, priority summary cards |
| `days_of_cover` | "days left" on the stock-out timeline |
| `avg_daily_demand` | consumption figure on the timeline |

Derived: Predicted Stockouts = rows with `days_of_cover <= 30`; Restock Needed =
row count; Critical Priority = `priority = 'critical'`; Est. Restock Cost =
`SUM(recommended_quantity × products.cost_price)`. Safety Stock in the table comes
from `products.safety_stock`.

**Scope:** `RestockAnalyticsService::analyze()` loops `foreach ($warehouseIds as
$whId)` and generates recommendations for warehouse locations only, by design,
because procurement is warehouse-only. This tab therefore covers warehouse
replenishment. The card subtitle is reworded to say so, so the absence of branch
rows reads as intentional rather than broken. The procurement engine is not
modified.

**Freshness:** the table is refreshed by `analytics:restock`, scheduled daily at
06:00 — which will not be running on a XAMPP dev box. If `MAX(run_at)` is older
than 24 hours or the table is empty, the service calls `analyze()` live and caches
the result for 15 minutes under a dedicated cache key. The tab is therefore never
blank on a machine without cron, and stays cheap in production where the schedule
does run.

### Profit Loss Analysis

**Loss is defined as an incident that actually wrote stock off**, i.e.
`incident_reports.adjustment_id IS NOT NULL`, joined to its `adjustments` row and
`adjustment_details`.

This is not an invented heuristic — it is exactly the set `IncidentService::resolve()`
deducted stock for:

| Reason | On confirm | Counts as loss |
|---|---|---|
| `damaged` | `postDeduction()` → Adjustment `type: damage` | **Yes** |
| `not_found` | `postDeduction()` → Adjustment `type: loss` | **Yes** |
| `wrong_type` | `postCorrection()` → batch re-typed | No — goods exist, relabeled |
| `zone_mismatch` | `relocate()` → batch moved | No — goods exist, moved |
| *any* + `dismiss` | status → Dismissed, no stock effect | No — false alarm |

The rule self-maintains: a future reason that deducts stock is included
automatically, and one that does not is excluded automatically.

**Quantity** is `adjustment_details.deduct_quantity`, not `incident.quantity`.
`postDeduction` clamps the write-off to what the batch or location actually held
(`$deducted = min($requested, $available)`), so the reported quantity can
overstate the real loss. Using the adjustment guarantees the dashboard can never
disagree with Inventory.

**Value** is `deduct_quantity × products.cost_price` — what the company paid for
the lost goods. Standard shrinkage accounting; selling price would double-count
unrealized margin.

Field mapping:

- Total Profit Loss — sum of confirmed loss value.
- Stolen Units — `SUM(deduct_quantity)` where `adjustment_type = 'loss'`.
- Damaged Units — `SUM(deduct_quantity)` where `adjustment_type = 'damage'`.
- Pending Reports — count of **open** incidents with reason `damaged` or
  `not_found`. These appear in the incident list flagged `pending` but are
  **excluded** from the confirmed peso total, matching the tab's existing
  `pending` / `approved` status badge.
- Loss by Location — grouped by `adjustments.location_id` → `locations.location_name`.
- Loss by Category — grouped by `products.category_id`, with the existing
  percentage bar scaled against the largest category.
- Recent Loss Incidents — the 10 most recent, with date, item, location, type
  badge, quantity, value, `narrative` as reason, and status.

### Location Performance

Grouping moves from the non-existent `branch_id` to `inventory.location_id`,
joined to `locations`, fixing defect 2. Metrics keep their current definitions:

- `efficiencyScore` = `(items - lowStockCount) / items`, where `lowStockCount`
  uses the Stock Level thresholds above.
- `avgTurnover` = mean turnover across the location's stocked products, from the
  Movement demand series.
- `restockNeeded` / `criticalPriority` / `restockCost` — items at or below reorder
  point, count at zero stock, and `SUM(max(target - qty, 0) × cost_price)` where
  `target = COALESCE(products.max_stock_level, reorder_level)`. The fallback
  matters because `max_stock_level` is nullable: without it, every product with no
  configured ceiling would contribute zero restock cost and understate the total.
- Performance badge: `Excellent` at `efficiencyScore >= 80`, else
  `Needs Improvement`. Unchanged.

### Filters

`GET /api/analytics/filters` returns:

- **Locations** — all rows in `locations` (warehouses *and* branch offices), since
  inventory lives in both and all three viewing roles are centralized. This
  replaces the `branch_office`-only list that caused defect 1.
- **Categories** — from `category_lookup`, replacing the current derivation from a
  500-row page of products.

The two `<select>` elements and their `All Location` / `All Category` options are
unchanged; only their contents become complete.

## Demo seed data

`database/seeders/AnalyticsDemoDataSeeder.php`, following the idempotent pattern
already established by `DemoProductDataSeeder`: it only fills operational tables
that are empty, is safe to re-run, and is never run in production. Dev database
(`airkingV5`) only.

Without it the dashboard renders zeros regardless of backend correctness, and
neither the implementation nor the fix can be visually verified.

It generates a coherent history across the 3 existing locations and 336 existing
products — it does not create products or locations:

- **Inventory** — rows across both warehouses and the showroom, with stock levels
  deliberately spread so every Stock Level bucket is non-empty: some at zero
  (Critical), some at or below reorder point (Low), some above `max_stock_level`
  (Overstock), the rest between (Optimal). Also exercises the null
  `max_stock_level` path.
- **~90 days of sales and issuances** — enough history to satisfy
  `RestockAnalyticsService`'s 90/30-day windows, so demand velocity, trend, days
  of cover and turnover are all real rather than degenerate. Volumes vary per
  product so fast-moving and slow-moving tiers are genuinely distinguishable, and
  vary per location so Location Performance ranks meaningfully.
- **Incidents covering all four reasons** — resolved `damaged` and `not_found`
  (each producing a real Adjustment through the normal service path, so the
  incident→adjustment chain is authentic), resolved `wrong_type` and
  `zone_mismatch` (which must contribute zero loss), one dismissed incident, and
  one still open (to populate Pending Reports). This makes the Profit Loss rule
  provably correct on screen, not just in tests.
- **Restock recommendations** — produced by running `analytics:restock` against
  the seeded history rather than being written directly, so the Predictions tab
  shows genuine engine output.

Seeded quantities are written through the existing services where a service owns
the write (incidents in particular), so the demo data obeys the same invariants as
real usage and cannot drift from what the app would have produced.

## Testing

PHPUnit feature tests per service method against `airkingV5_test` using
`DatabaseTransactions`, per the project's existing convention (schema is a SQL
dump, not migrations — blanket `migrate` fails).

Priority cases:

1. A `wrong_type` incident contributes **zero** loss value and zero units.
2. A `zone_mismatch` incident contributes **zero** loss value and zero units.
3. A **dismissed** `damaged` incident contributes zero, and does not appear in the
   confirmed total.
4. An **open** `damaged` incident increments Pending Reports, appears in the
   incident list as `pending`, and is excluded from the peso total.
5. A `not_found` incident reporting more than the batch held is valued at the
   clamped `deduct_quantity`, not the reported quantity.
6. Stock Level totals include rows beyond the old 500-row page.
7. Selecting a location narrows results rather than zeroing them, and warehouses
   are selectable.
8. Overstock excludes products with a null `max_stock_level`.
9. Predictions falls back to a live `analyze()` when `restock_recommendations` is
   empty, and serves persisted rows when they are fresh.
10. All six endpoints reject a user lacking `analytics_dashboard.read`.

Frontend: extend the existing page test to assert each tab renders values from a
mocked endpoint response, and that no tab computes locally.

Manual verification, only possible once the seeder has run: every tab shows
non-zero values; the location dropdown lists all three locations (it is empty
today); selecting a location narrows the numbers instead of zeroing them; and the
Profit Loss total, checked by hand, equals the sum of only the `damaged` and
`not_found` write-offs — with the seeded `wrong_type` and `zone_mismatch`
incidents visibly contributing nothing.

## Out of scope

- Any change to `RestockAnalyticsService`'s recommendation logic or warehouse
  scoping, beyond widening one private method to public.
- Adding branch-office coverage to the procurement recommendation engine.
- Counting manual (non-incident) adjustments or `profit_loss` rows as loss. The
  incident→adjustment chain is the agreed single source. Both are cheap to add
  later, since the service already queries the `adjustments` table.
- Date-range controls, export, or drill-through — all would add UI, which the
  constraint forbids.
- Retiring `/api/batch`, which other pages still use.
- Seeding anything into production, or creating new products or locations. The
  demo seeder is dev-only and works with the 336 products and 3 locations that
  already exist.
