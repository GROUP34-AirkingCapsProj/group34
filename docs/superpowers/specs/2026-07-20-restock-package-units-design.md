# Restock Recommendations: Package Units for Installation Materials

**Date:** 2026-07-20
**Branch:** backupV3
**Status:** Approved design

## Problem

For installation materials (consumables), the restock analytics engine computes
`recommended_quantity` in **pieces** (e.g., 300 meters of tube), but the PR/PO
`quantity_ordered` field means **packages** (receiving multiplies it by
`pieces_per_package`). When a user adds a recommendation to a PR, the pieces
value (300) lands in a package-denominated field — creating a PR for 300
packages (15,000 m) instead of 6 packages (300 m).

A second, hidden bug: the engine's open-PO netting
(`RestockAnalyticsService::openPoByWarehouse()`) sums `quantity_ordered` raw
(packages) and subtracts `receiving_details.quantity_amount` (pieces). For
materials, incoming stock is undercounted by the `pieces_per_package` factor,
so the engine keeps over-recommending while a PO is in flight.

## Unit inventory (current state, verified in code)

| Field | Unit for installation materials |
|---|---|
| `inventory.quantity_on_hand` | pieces |
| `inventory.packages_on_hand` | packages |
| `issuance_details.quantity_in_pieces` (demand) | pieces |
| `receiving_details.quantity_amount` | pieces |
| `purchase_order_details.quantity_ordered`, PR `quantity` | **packages** |
| `restock_recommendations.recommended_quantity` | pieces |
| `products.recommended_stocks` / `safety_stock` / `max_stock_level` | mixed historically; **pieces** going forward (see §Convention) |

## Principle

> **Analyze in pieces, order in packages.**

- All stored analytics values and all engine math stay in **pieces** — demand
  arrives in pieces (a 3 m issuance must not round to 0 or 1 packages), stock
  truth is pieces, and the safety-stock statistics (σ of daily demand) need
  piece granularity.
- **Packages appear only at the ordering boundary** — recommendation cards,
  the "+ Add" prefill, and the per-row "Suggested" hint on the PR page —
  converted via `ceil(pieces ÷ pieces_per_package)`. `ceil` slightly
  over-orders (need 280 m → 6 packages = 300 m), the safe direction.
- No schema change. No data migration.

## Scope

**In scope**

1. Fix open-PO netting unit mismatch in `RestockAnalyticsService`.
2. Serve package fields from `RestockRecommendationController::index`.
3. Convert pieces → packages at all four recommendation surfaces on
   `PurchaseRequestPage.jsx`.
4. Unit labels: PR QTY column hint, Item Management stock-parameter inputs,
   Stock Parameters suggestions tab.

**Out of scope**

- Legacy `AnalyticsDashboard.jsx` client-side restock list (separate
  calculation; separate cleanup).
- Appliance handling (`quantity_ordered` is already units = pieces).
- Bulk migration of mixed historical `recommended_stocks` values (they
  self-correct via applied suggestions; see §Convention).

## Design

### Backend

**B1 — `RestockAnalyticsService::openPoByWarehouse()`** (line ~421)

The "ordered" query joins `products` and `installation_material_details` and
converts to pieces in SQL:

```sql
SUM(CASE WHEN p.product_type = 'installation_material'
     THEN pod.quantity_ordered * GREATEST(COALESCE(imd.pieces_per_package, 1), 1)
     ELSE pod.quantity_ordered END) as qty
```

The received side (`receiving_details.quantity_amount`) is already pieces and
stays untouched. Result: 6 packages × 50 m on order is seen as 300 pieces
incoming, not 6.

**B2 — `RestockRecommendationController::index`**

Eager-load `product.consumableDetail`. For installation-material rows, append
to the JSON response:

- `pieces_per_package` (int)
- `package_unit` (string, e.g., "roll", "box")
- `order_packages` = `(int) ceil(recommended_quantity / pieces_per_package)`;
  `null` for appliances.

Stored `recommended_quantity` remains the true pieces need (e.g., 280);
`order_packages` is computed at read time so it always reflects the current
`pieces_per_package`, even if packaging changes after an analytics run.

**Edge cases:** `pieces_per_package` null, 0, or 1 → treat as 1: no
conversion, `order_packages == recommended_quantity`, no division by zero.

### Frontend (`PurchaseRequestPage.jsx`)

One shared helper:

```js
// pieces → packages for installation materials; passthrough otherwise
const toOrderQty = (pieces, prod) => {
  const ppp = Number(prod?.piecesPerPackage) || 0;
  if (prod?.type === 'installation_material' && ppp > 1) {
    return Math.ceil(pieces / ppp);
  }
  return pieces;
};
```

**F1 — Recommendation card** (line ~1056): for materials show both units,
packages bold: `order **6 rolls** (300 m)`. Prefer API `order_packages` /
`package_unit`; fall back to local `toOrderQty()` conversion.

**F2 — "+ Add" prefill** (`addRecommendedItem`, line ~549): row `qty` becomes
`order_packages` (6), not `recommended_quantity` (300). *This is the primary
bug fix.*

**F3 — "Suggested: N → apply" hint** (lines ~1128–1141): convert with
`toOrderQty()` for both sources — the analytics recommendation and the
`recommendedStocks` fallback (assumed pieces per §Convention). Label the
unit: `Suggested: 6 rolls → apply`.

**F4 — QTY column hint**: for installation-material rows, read-only text
under the input: `× 50 m/roll = 300 m` (package count × pieces-per-package,
using the product's unit abbreviation). No behavior change.

### Convention & existing mixed data

- `products.recommended_stocks`, `safety_stock`, `max_stock_level` are
  **pieces** for installation materials. Documented here; enforced by UI
  labels, not validation.
- **Item Management**: the three stock-parameter inputs get a unit suffix
  when the product is an installation material (e.g., "pieces (m)").
- **Stock Parameters tab** (PR page suggestions table, line ~2016): add a
  "(pieces)" hint so suggested values are not misread as packages.
- Mixed historical values self-correct: suggestions are computed in pieces,
  so applying one normalizes that product's parameters. No bulk migration —
  we cannot reliably tell which values were entered as packages.

## Error handling

- `pieces_per_package` null/0/1 → conversion is identity (behaves like today).
- Missing `consumableDetail` relation on a material → same identity fallback.
- Appliances are never converted (`order_packages` null; helper passthrough).

## Testing

Backend, in `back-end/tests/Feature/Restock/` (existing suite: 27 tests,
DatabaseTransactions against `airkingV5_test`):

1. **Netting fix:** material with an open PO of 6 packages × 50 → engine sees
   300 pieces on order; `recommended_quantity` shrinks accordingly. Appliance
   netting unchanged.
2. **API fields:** `index` returns `order_packages = ceil(280/50) = 6`,
   `pieces_per_package`, `package_unit` for materials; `null` for appliances.
3. **Edges:** ppp null/0/1 → `order_packages == recommended_quantity`; exact
   multiple 300/50 = 6 (no over-round).

Frontend: manual verification on the PR page against the dev DB demo
products (CRA test setup is scaffold-only).

## Decisions log

- Pieces chosen as the internal/DB unit (user confirmed) — packages only at
  the ordering boundary. Option "store packages in DB" rejected: demand
  arrives in pieces; package rounding would corrupt velocity/σ math.
- Frontend-only division rejected: leaves the netting bug and misleading API
  values for future consumers.
- `order_packages` computed at read time (not persisted) so packaging changes
  never leave stale package counts in the batch.
