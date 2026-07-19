# PR Recommendation — Inline, Per-Warehouse

**Date:** 2026-07-19
**Branch:** backupV3
**Status:** Design approved, ready for planning

## Background

The system has a standalone **"PO Recommendation"** page (`/admin/po-recommendations`,
`PORecommendation.jsx`) with its own sidebar item. It has two tabs:

1. **Restock Recommendations** — a checklist of low-stock products with suggested
   order quantities. Selecting rows and clicking "Create Purchase Request" hits
   `POST /api/restock/create-pr`, which builds a draft PR, then navigates to the
   Purchase Request page.
2. **Stock Parameter Suggestions** — an Inventory-Analyst tool that proposes new
   `recommended_stocks` / `safety_stock` / `max_stock_level` values per product and
   lets a user apply or dismiss each one.

The analytics engine (`App\Services\Analytics\RestockAnalyticsService`) is currently
**org-wide**: `stockOnHand()` sums `inventory.quantity_on_hand` across all locations,
demand is computed org-wide, and it writes a **single** recommendation batch tagged
with the lowest-id warehouse as a label — the figures are not that warehouse's.

## Problem

Two pieces of adviser feedback:

1. The feature should be called **"PR Recommendation"**, not "PO Recommendation",
   because it produces a *Purchase Request*, not a Purchase Order.
2. The recommendations should live **inside the Purchase Request module**, so the
   person building a PR can see, right there, which products are low on stock and how
   much to order.

The Create Request form is organized into **per-warehouse blocks** (each with its own
destination warehouse and its own budget). For the recommendations to be useful inline,
each block should show the low-stock items **for that block's warehouse**. That requires
per-warehouse recommendation data, which the org-wide engine does not produce today.

## Goals

- Rename the feature to **PR Recommendation** in all user-facing surfaces.
- Surface low-stock recommendations **inline in the Create Request form**, scoped
  **per warehouse block**, with a one-click "add to this block" action.
- Make the analytics engine **location-aware** for stock (and on-order), so each
  block's list reflects that warehouse's actual on-hand.
- Relocate the **Stock Parameter Suggestions** tool into the Purchase Request page and
  retire the standalone page and its nav item.

## Non-Goals

- Attributing **demand** per warehouse (transfers-out / downstream-sales mapping) and
  per-location stock thresholds. Warehouses hold stock but don't sell, so per-warehouse
  demand is a separate, larger modeling problem. Demand velocity, trend, σ, and lead
  time stay **org-wide as a shared proxy** for every warehouse. (Documented tradeoff.)
- Any change to how Stock Parameter Suggestions are computed — they remain
  product-level and org-wide.
- Changing the PR approval / conversion / budget flows.

## Key Decisions

| Decision | Choice |
|---|---|
| Integration depth | Inline in the **Create Request** form (deepest), not a separate tab. |
| Scoping | **Per warehouse block** — each block shows its warehouse's low-stock items. |
| Backend scope | **Per-warehouse stock + on-order**; demand velocity / lead time stay **org-wide** (shared proxy). |
| Fate of Stock Parameter Suggestions | Move to a **"Stock Parameters" tab** inside the Purchase Request page. |
| Standalone page & nav | **Removed** (route, `PORecommendation.jsx`, Sidebar entry, navBundle entry). |
| `POST /restock/create-pr` endpoint | **Retired** — inline items flow through the normal PR create. |
| Permission key | Keep internal key `po_recommendations`; relabel display only. |

## Feasibility (verified)

- `inventory.location_id`, `issuances.location_id`, `sales.location_id` all exist.
- `purchase_order_details.location_id` exists → per-warehouse ordered qty.
- `receivings.location_id` exists → per-warehouse received qty (join `receiving_details`).
- The Create Request form already caches per-warehouse budgets by location
  (`budgetsByLoc` + `fetchedLocRef`), a pattern the inline recommendation fetch reuses.

## Design

### 1. Naming

- Replace user-facing "PO Recommendation" with **"PR Recommendation"**.
- The PR auto-purpose string `"Generated from PO Recommendation analytics"` becomes
  `"Generated from PR Recommendation"` (or similar) — but note this string lived on the
  retired `create-pr` path; with inline flow the PR carries the user's own purpose, so
  this string is effectively removed.
- Relabel the `po_recommendations` **permission display name** to "PR Recommendation".
  Keep the internal feature key `po_recommendations` to avoid seeder/DB churn.

### 2. Backend — location-aware engine

`RestockAnalyticsService`:

- **Stock (per warehouse):** replace `stockOnHand()` with a per-location variant
  returning `[locationId][productId] => qty`, restricted to `location_type = 'warehouse'`.
- **On-order (per warehouse):** replace `openPoQuantities()` with a per-location variant:
  ordered qty grouped by `(purchase_order_details.location_id, product_id)` for open POs,
  minus received qty grouped by `(receivings.location_id, product_id)`.
- **Demand / trend / σ / lead time (org-wide, shared):** computed **once per product** as
  today, reused for every warehouse.
- **Recommendation loop:** iterate over `warehouse × product`. For each pair, compute
  on-hand (that WH), on-order (that WH), days-of-cover, trigger, priority, reason codes,
  and `recommended_quantity`, using the **shared** demand figures and the product's
  global thresholds (floored as today). Emit a row only when triggered and qty > 0.
- **Output shape:** `analyze()` returns recommendations that each carry a real
  `location_id`. `persist()` writes them as one row per `(product_id, location_id)`.
- **Migration:** adjust `restock_recommendations` so multiple rows per product (one per
  warehouse) are valid — change/introduce the unique key to `(product_id, location_id)`.
  Verify current schema and index before writing the migration.
- **Suggestions:** `analyze()`'s Stock Parameter Suggestion branch is unchanged
  (product-level, org-wide). `persist()`'s suggestion handling is unchanged.

### 3. Backend — API (`RestockRecommendationController`)

- `GET /api/restock/recommendations?location_id=X` — filter the stored batch to warehouse
  `X`; keep the existing `priority` and `search` filters; return warehouse `X`'s budget
  summary (reuse `BudgetService::resolveActiveBudget`). Without `location_id`, behavior
  is unspecified/deprecated (the inline flow always passes one) — decide in planning
  whether to return all rows or require the param.
- `recalculate`, `suggestions`, `applySuggestion`, `dismissSuggestion` — unchanged.
- **Remove** `createPr()` and its route (`POST /restock/create-pr`), plus the
  `restockAPI.createPR` client method.

### 4. Frontend — inline in Create Request (per block)

In `PurchaseRequestPage.jsx`, for each warehouse block whose warehouse is selected:

- Fetch `restockAPI.getRecommendations({ location_id })`, cached per-location with the
  same ref-guard pattern as `budgetsByLoc` (`recsByLoc` + `fetchedRecLocRef`).
- Render a collapsible **"Suggested for {Warehouse}"** panel inside/above the block's
  line-item table. Each item shows: priority badge, product name/code, current stock,
  suggested order qty, reason chips (reuse the existing `REASON_LABELS` map), and a
  **"+ Add"** button.
- **"+ Add"** inserts a prefilled row into that block (`product_id`, `qty =
  recommended_quantity`, `estPrice = cost_price`, subtotal computed). Runs the existing
  `handleProductSelect` logic so the smart-reorder open-lines warning still fires.
- Items already present in the block are hidden from the panel.
- Adding items updates the block subtotal and the existing per-warehouse budget /
  over-budget warnings automatically (no new gating logic).
- Empty/non-blocking: if the warehouse has no low-stock items, show a quiet note; never
  block PR creation on recommendation data.

### 5. Frontend — PR page tabs & cleanup

- Add a **"Stock Parameters"** tab to `PurchaseRequestPage`. Move the Stock Parameter
  Suggestions UI (the apply/dismiss table) out of `PORecommendation.jsx` into this tab,
  including a **"Recalculate now"** button and a **last-computed** timestamp — this tab is
  the new home for the manual analytics run.
- **Delete:** `PORecommendation.jsx`; the route `/admin/po-recommendations` in
  `App.jsx`; the Sidebar entries (`Sidebar.jsx` lazy import + menu item); the
  `navBundles.js` entry for `/admin/po-recommendations`.
- Keep `po_recommendation.css` (still imported by the PR page); prune any rules only used
  by the deleted page if convenient.

### 6. Permissions & visibility

- **Inline suggestions** in Create Request: visible to anyone who can reach the Create tab
  (`purchase_request_creation.create`) — no additional gate. They are a convenience layer
  over the form they're already using.
- **"Stock Parameters" tab:** gated by `po_recommendations.read` (admin / IA / BM /
  auditor per the current matrix). Apply / dismiss / recalculate gated by
  `po_recommendations.create` (admin / IA). No matrix change — only the display label.

### 7. Freshness / recalc

- The daily scheduled `analytics:restock` run is unchanged (now writes per-warehouse rows).
- Manual recalculation lives in the new "Stock Parameters" tab.
- The inline panel reads the latest **stored** batch filtered by `location_id` and shows an
  "as of {run_at}" hint. It does not trigger a live recompute on warehouse selection.

## Testing

- **Engine:** rework the existing restock feature tests (`back-end/tests/Feature/Restock/`)
  for per-warehouse rows — assert that a product low at warehouse A but well-stocked at
  warehouse B produces a row for A and not B; assert shared demand values are identical
  across a product's per-warehouse rows.
- **API:** `GET /restock/recommendations?location_id=X` returns only warehouse X's rows
  and X's budget. Remove the `create-pr` tests.
- **Frontend:** inline "+ Add" inserts a prefilled row into the correct block, hides an
  already-added item, and updates the block subtotal / over-budget warning. The Stock
  Parameters tab applies/dismisses a suggestion.
- Pre-existing unrelated failures noted in prior work (NavBundleTest count drift, CRA
  `App.test.jsx` scaffold) are out of scope.

## Rollout notes

- Run `php artisan analytics:restock` after deploy so `restock_recommendations` is
  repopulated in the new per-warehouse shape (old single-batch rows are replaced).
- Config/route cache: keep uncached while developing; re-cache before client demos
  (known gotcha on this branch).
- Dev DB: a second warehouse + budgets make the per-warehouse behavior demonstrable.

## Open items for planning

- Exact `restock_recommendations` schema/index today, and the precise migration.
- Whether `GET /restock/recommendations` without `location_id` should error or return all.
- Whether the inline panel is collapsed or expanded by default.
