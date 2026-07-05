# PO Recommendation Analytics — Design

**Date:** 2026-07-06
**Status:** Approved design, pending implementation plan

## Problem

The current PO Recommendation page is purely client-side: it compares stock on hand
against `inventory.reorder_level` / `products.recommended_stocks` and labels rows
CRITICAL/HIGH/MEDIUM. It has no demand analysis, no explanation beyond "stock is low",
cannot create anything, and the stock parameters on `products`
(`recommended_stocks`, `safety_stock`, `max_stock_level`) are static values that
nobody re-tunes as demand changes.

## Goal

A backend analytics engine that:

1. Produces **PO restock recommendations** with explainable reasons (low stock,
   stockout risk, fast moving, high turnover, …) and a concrete recommended quantity.
2. **Learns demand** from movement history and raises **stock parameter suggestions**
   — new values for `recommended_stocks`, `safety_stock`, `max_stock_level` that
   drift up for in-demand products and down for slow movers.
3. Feeds accepted recommendations into the existing **Purchase Request → PO** flow.

## Decisions (settled during brainstorming)

| Decision | Choice |
|---|---|
| Parameter changes | **Suggest + approve** — engine proposes, admin/IA applies or dismisses; never writes `products` automatically |
| Scope | **Warehouse-centric** — demand aggregated across all locations, recommendations target the main warehouse (branches are refilled via IST transfers, not their own POs) |
| Accepting recommendations | Creates a **draft Purchase Request** (existing PR → approval → PO flow; respects budgets) |
| Run timing | **Daily scheduled job + manual "Recalculate now"** button; results stored, page loads read stored rows |
| Demand signal | **`issuance_details` + `sales_details`** (stock leaving the company). Internal transfers excluded — branch issuance already counts that demand |
| Engine type | **Rule-based statistical** (Approach A): rolling velocity windows, measured lead times, derived safety stock. No ML/forecasting — data volume doesn't support it yet |
| UI home | **Rebuild the existing PO Recommendation page** (two tabs: Recommendations, Parameter Suggestions), on the `ak-` design system |

## Architecture

### Components

- **`App\Services\Analytics\RestockAnalyticsService`** — the engine. Pure
  computation, no HTTP concerns; callable from scheduler, artisan, and API.
  Pipeline per run:
  1. Build per-product demand profiles from the last 90 days of
     `issuance_details` + `sales_details` (org-wide).
  2. Measure per-product lead times from PO → receiving history.
  3. Emit restock recommendations + parameter suggestions.
- **`analytics:restock` artisan command** — runs the service and persists results.
  Registered in the Laravel scheduler (daily 06:00). Also invoked by the manual
  recalculate endpoint.
- **`RestockRecommendationController`** — list recommendations, recalculate,
  create draft PR from selection, list/apply/dismiss parameter suggestions.

### Data flow

```
scheduler / Recalculate button
        │
        ▼
RestockAnalyticsService (reads 90d movement, PO/receiving history, inventory, open POs)
        │
        ├─► restock_recommendations   (batch replaced each run)
        └─► stock_parameter_suggestions (pending rows upserted; applied/dismissed untouched)
        │
        ▼
PO Recommendation page (reads stored rows)
        ├─► tick products → POST create-pr → draft Purchase Request → existing PR flow
        └─► review suggestion → Apply (updates products, keeps row as audit) / Dismiss
```

## Engine math

All per product, org-wide demand, computed over a 90-day lookback:

1. **Demand velocity.** Daily units out. Consumables use
   `issuance_details.quantity_in_pieces` (actual pieces deducted); appliances use
   `quantity_issued`; plus `sales_details` quantities.
   `v30` = last-30-day total ÷ 30; `v90` = last-90-day total ÷ 90.
   **Trend** = v30 ÷ v90: ≥ 1.3 → `FAST_MOVING`; ≤ 0.7 → `SLOW_MOVING`.
2. **Lead time.** Mean days from `purchase_orders.order_date` to first receiving,
   over the product's last 5 completed POs; fallback to supplier average, then a
   **7-day default**. Clamped to 1–30 days.
3. **Safety stock (suggested)** = ⌈1.65 × σ(daily demand) × √(lead time)⌉.
   Sparse-history fallback (< 5 demand days in 90): ⌈0.5 × v30 × lead time⌉.
4. **Reorder point** = v30 × lead time + safety stock → suggested
   `recommended_stocks` (the threshold semantics the current UI already uses).
5. **Target level** = 30 × v30 + safety stock → suggested `max_stock_level`.
6. **Recommendation trigger:** current total stock ≤ reorder point, or zero, or
   below safety stock. **Recommended quantity** = target level − current stock −
   open-PO quantity (quantity ordered on POs not yet fully received). If ≤ 0,
   no recommendation (already on order).
7. **Priority:** CRITICAL = out of stock or days-of-cover < lead time ÷ 2;
   HIGH = cover < lead time or stock < safety stock; MEDIUM = stock ≤ reorder point.
8. **Reason codes** (JSON array; each is a rule that fired):
   `OUT_OF_STOCK`, `BELOW_SAFETY_STOCK`, `STOCKOUT_RISK` (cover < lead time),
   `LOW_STOCK` (≤ reorder point), `FAST_MOVING`, `HIGH_TURNOVER` (large received
   *and* issued volumes inside the window), `SLOW_MOVING`, `NO_MOVEMENT`.
   The UI renders chips plus a plain-language summary sentence, e.g.
   *"Demand is 2.1/day (up 60% vs the 90-day average); stock covers 4 days but
   delivery takes 7."*
9. **Parameter suggestions** are raised only when the computed value differs from
   the current product value by **> 20% and ≥ 1 unit**. Downward drift for slow
   movers uses `SLOW_MOVING` / `NO_MOVEMENT` (zero demand in 90 days ⇒ suggest
   lowering recommended/safety stock). Products with no movement history **and**
   no existing parameters are skipped (never suggest "set to 0" noise).
10. **Exclusions:** soft-deleted and inactive products are skipped entirely.

### "Learning" semantics

Every run re-derives the parameters from the most recent 90 days, so values track
demand: a product whose recommended stock is 5 but whose demand now supports 12
gets a pending suggestion of 12; when demand fades, later runs suggest lower
values. Dismissed suggestions are not re-raised until the *computed* value changes
again (compare against the dismissed suggestion's values on upsert).

## Data model

### `restock_recommendations` (existing, currently unused — migration adds columns)

Existing columns kept with these semantics: `product_id`; `location_id` = main
warehouse; `current_stock` = org-wide on hand; `reorder_level` = computed reorder
point; `recommended_quantity`; `reason` = one-line human summary; `status_id`.

New columns:

| Column | Type | Notes |
|---|---|---|
| `priority` | varchar(10) | CRITICAL / HIGH / MEDIUM |
| `reason_codes` | JSON | `["STOCKOUT_RISK","FAST_MOVING"]` |
| `avg_daily_demand` | decimal(10,3) | v30 |
| `demand_trend` | decimal(6,3) nullable | v30 ÷ v90; null when v90 = 0 |
| `days_of_cover` | decimal(8,1) nullable | null when demand is 0 (UI shows ∞) |
| `lead_time_days` | decimal(6,1) | |
| `on_order_quantity` | int | open-PO qty subtracted from recommendation |
| `run_at` | timestamp | batch identifier; page shows "Last computed: …" |

Each run deletes the previous batch and inserts the new one inside a transaction.

### `stock_parameter_suggestions` (new table)

| Column | Type |
|---|---|
| `suggestion_id` | PK |
| `product_id` | FK products |
| `current_recommended`, `suggested_recommended` | int |
| `current_safety`, `suggested_safety` | int nullable |
| `current_max`, `suggested_max` | int nullable |
| `reason_codes` | JSON |
| `summary` | varchar(255) |
| `status` | enum: pending / applied / dismissed |
| `reviewed_by`, `reviewed_at` | FK users, timestamp — set on apply/dismiss |
| `run_at`, `created_at`, `updated_at` | timestamps |

At most one **pending** row per product. Applied/dismissed rows are kept forever
as the audit trail of parameter changes.

## API

All under `auth:sanctum` + permission middleware:

| Endpoint | Behavior |
|---|---|
| `GET /api/restock/recommendations` | Stored rows + `run_at`; filters: priority, category, search |
| `POST /api/restock/recalculate` | Runs the engine synchronously (small catalog, sub-second) and returns fresh results |
| `POST /api/restock/create-pr` | Body `{ items: [{product_id, quantity}] }` → draft PR + PR details via the existing PR creation path; returns `pr_id`; frontend redirects to the PR page for supplier/budget/finalization |
| `GET /api/restock/suggestions` | Pending (default) or historical suggestions |
| `POST /api/restock/suggestions/{id}/apply` | Transaction: re-check pending, update `products`, mark applied |
| `POST /api/restock/suggestions/{id}/dismiss` | Mark dismissed |

**RBAC:** viewing/recalculating/creating PRs uses the existing PO-recommendation
permission already on the page's nav bundle. Applying/dismissing parameter
suggestions gets a **new permission** (granted to admin + inventory analyst)
because it edits product master data.

## UI

Rebuild `frontend/src/admin/pages/PORecommendation.jsx` on the `ak-` design
system (`airking_ds.css`), two tabs:

1. **Restock Recommendations** — stat tiles (critical/high/medium counts, last
   run time), Recalculate button, filter bar (search, priority, category), table:
   product, current stock, on order, daily demand + trend arrow, days of cover,
   lead time, recommended qty (**editable** before creating the PR), priority
   badge, reason chips (tooltip shows the plain-language sentence). Row
   checkboxes → sticky "Create Purchase Request (n)" action.
2. **Stock Parameter Suggestions** — rows showing *current → suggested* for the
   three fields, reason chips, Apply / Dismiss buttons. Apply uses the shared
   confirm-dialog helper; success uses the shared toast helper.

## Error handling

- Engine run is transactional: a failed run leaves the previous batch intact.
- Division guards: zero velocity ⇒ `days_of_cover` null (∞), `demand_trend` null.
- Zero-demand products below threshold still get recommendations (`LOW_STOCK` only).
- `create-pr`: validates quantities > 0 and product ids exist; transactional.
- Apply suggestion: 409-style rejection if no longer pending (double-click, two
  admins). At apply time the current `products` values are re-read; if they no
  longer match the suggestion's `current_*` snapshot, the apply is rejected as
  **stale** with a message to recalculate.

## Testing

Feature tests against `airkingV5_test` with `DatabaseTransactions` (repo
convention — no blanket `migrate`; apply new migrations via `--path`):

- **Engine:** seeded movement histories asserting v30/v90, trend classification,
  lead-time measurement + fallbacks, safety-stock math (incl. sparse fallback),
  reorder point/target, open-PO subtraction, each reason code, priority tiers,
  suggestion threshold (>20% & ≥1 unit), dismissed-not-re-raised, exclusions.
- **Endpoints:** recalculate persists a batch; create-pr creates PR + details
  and returns `pr_id`; apply updates `products` + keeps audit row; dismiss;
  stale + non-pending rejections.
- **RBAC:** matrix additions for the new apply/dismiss permission.

## Out of scope (explicitly)

- Seasonality / forecasting models (revisit with 12+ months of history).
- Per-branch purchase recommendations (warehouse-centric only).
- Automatic writes to `products` without human approval.
- Supplier auto-selection on the generated PR (user picks in the PR flow).
