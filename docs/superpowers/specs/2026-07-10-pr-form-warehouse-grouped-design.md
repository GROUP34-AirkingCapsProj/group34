# PR Create Form — Warehouse-Grouped Layout — Design

**Date:** 2026-07-10
**Branch:** backupV3
**Status:** Approved (brainstorming session)
**Scope:** Frontend-only rework of the PR create tab. Backend `POST /api/purchase-requests` (flat `details[]` with per-line `location_id`) is unchanged.
**Fixes:** Plan A's PR form (2026-07-10-warehouse-procurement-core) shipped a per-line warehouse column + supplier-filtered product picker that (a) shows no products because `supplier_product` is empty and the picker uses a field-shape it doesn't receive, and (b) is tedious (a warehouse dropdown on every product row).

## Problem

1. **No products visible.** The create form filters products to the selected supplier via `GET /purchase-orders/products-by-supplier/{id}`, but `supplier_product` has 0 rows, so the list is always empty. The form also reads product fields (`p.id`, `p.categoryId`, `p.categoryName`, `p.brandId`) that the endpoint does not return (it returns `product_id`, `product_code`, `product_name`, `brand_name`, …) — so even with mappings, selection would break.
2. **Tedious location entry.** Each product row has its own warehouse dropdown; choosing a warehouse per product is slow. The user wants to pick a warehouse once, then add multiple products under it.

## Decisions (user-confirmed)

- **Show all active products.** The picker lists all active products (not supplier-filtered). The supplier is a recorded header field on the PR. The backend already allows this — its product–supplier integrity check only rejects a product mapped to a *different* supplier; unmapped products (all of them today) pass.
- **Warehouse-grouped layout.** The form is a list of warehouse blocks: each block picks one warehouse, then holds its own product rows. "Add another warehouse" adds a block. A warehouse may appear in at most one block.

## Layout

Header: **Supplier** select (one per PR, recorded — does NOT filter products), **Needed-by date**, **Purpose**.

Body: repeatable **warehouse blocks**. Each block:
- A **Warehouse** `<select>` listing warehouse-type locations only, excluding warehouses already chosen by other blocks.
- One or more **product rows**: product `<select>` (all active products), quantity, estimated price, notes, remove-row.
- **"+ Add product"** appends a row to this block.
- A **budget card** in the block header: that warehouse's active budget — verification badge (VERIFIED / AWAITING VERIFICATION / REJECTED), available vs. this block's running subtotal (Σ of the block's rows), with a warning when the subtotal exceeds available or the budget is unverified/missing.

Footer: **"+ Add another warehouse"** (disabled when all warehouses are already used); **Submit**.

## Data flow

- **Products:** load all active products once on mount via `itemsAPI.getAll({ per_page: 1000 })` and normalize with the SAME mapping the pre-Plan-A form used (which the row-selection code still expects): `{ id: String(p.product_id ?? p.id), productName, productType, categoryId, categoryName, brandId, brandName, unitId, unitName, estPrice: cost_price/unit_price, ... }`. This simultaneously fixes the empty list AND the `p.id`/`p.categoryId` field-shape mismatch (the rows key off `p.id`, not `product_id`). Remove the `getProductsBySupplier` call and the supplier-change→reload-products effect; the category/brand filter bar works again because the normalized products carry `categoryId`/`brandId`.
- **Suppliers / locations / budgets:** unchanged from Plan A (suppliers list for the header select; `locations.filter(location_type === 'warehouse')` for block dropdowns; `budgetAllocationsAPI.getForLocation` → `getAvailable` per block warehouse, cached by locationId so typing quantities does not refetch).
- **Submit payload (unchanged contract):**
  ```
  {
    supplier_id, needed_by_date, purpose,
    details: [ for each block, for each row →
      { product_id, location_id: block.warehouseId, quantity, estimated_price } ]
  }
  ```
  Flatten blocks → rows into one `details` array. Same shape the backend `store()` already validates.

## State shape

Replace the flat `lineItems` (each with its own `locationId`) with `warehouseBlocks`:
```
warehouseBlocks: [
  { id, warehouseId, rows: [ { id, productId, qty, estPrice, notes } ] }
]
```
Distinct block warehouses drive the per-block budget fetch (reuse Plan A's `fetchedLocRef` cache keyed by locationId).

## Validation / guards (submit)

- Supplier chosen.
- At least one block with at least one valid row (product + qty ≥ 1).
- Every block has a warehouse selected.
- Every block's budget is verified and its subtotal ≤ available (from the block's budget card state).
Submit button disabled and a matching toast fires when any guard fails. Backend re-checks everything (source of truth).

## Out of scope (YAGNI)

- Backend changes (none). Supplier→product mapping data (not needed — all products shown). The PR list/detail/convert/export views (Plan A already renders supplier + per-line warehouse; the flattened `details` still carry `location_id`, so those views are unaffected). Merging duplicate warehouses (prevented instead, by excluding chosen warehouses from other blocks' dropdowns).

## Testing

Frontend has no unit tests for this page (pre-existing). Verify by: `npx eslint` on the page (no new errors), `npm run build` (succeeds), and a manual check — select a supplier, see all products, add a warehouse block, add products under it, add a second warehouse block, confirm per-block budget cards and that submit posts the flattened `details[]` (the backend E2E test already covers the multi-warehouse settle path, so a correctly-shaped payload flows through unchanged).

## Narrative

"Ordering is organized the way buyers think: pick the supplier, then for each destination warehouse add the products it needs — choosing the warehouse once, not on every line. Each warehouse block shows its own budget in place, so the buyer sees exactly what each warehouse can still afford before submitting."
