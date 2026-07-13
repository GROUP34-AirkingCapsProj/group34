# Location-scoped PO totals on the receiving screen

**Date:** 2026-07-14
**Branch:** backupV3
**Status:** Approved design

## Problem

A purchase order has exactly one supplier but may carry line items destined for
several warehouses within a single PO document (the one-PO-per-supplier /
per-line-warehouse shape). When a warehouse receiver opens the receiving screen,
each PO card shows the **whole-PO total amount** and the **whole-PO item count** —
figures that include lines destined for *other* warehouses.

A receiver at Warehouse A should see the total and item count for **only the lines
destined for Warehouse A**, not the aggregate across every warehouse on the PO.

## Current state (verified in code)

- `back-end/app/Http/Controllers/Api/PurchaseOrderController.php`
  - `index()` runs `transformPoDetail` on every line of every listed PO, so the PO
    **list** payload already carries per-line `subtotal` and `location_id`. **No
    backend change is required.**
- `frontend/src/admin/pages/BarcodeScan.jsx`
  - `bsrLinesForLocation(po, locationId)` already filters a PO's lines to a given
    warehouse (falls back to header `location_id` for legacy single-warehouse POs;
    returns all lines when `locationId` is null). Already used inside the detail
    modal.
  - `myLocationId` = the receiver's enforced warehouse (`isStrictLocation &&
    enforcedLocationId`), else `null` for centralized/admin users.
  - **Gap:** the PO **list card** (~lines 2805–2831) computes
    `totalAmt = po.total_amount || po.grand_total` and item count via
    `bsrGetItemCount(po)` = full `po.details.length` — both unscoped. This is the
    only money figure a receiver sees; the receiving **detail** view shows scan
    counts only (no money), so there is nothing else to scope on that screen.
- Already per-warehouse (no change needed, verified):
  - PDF export `buildPODocumentHtml` (`poExport.js`) — one page per warehouse; each
    page's "Total Amount →" is that warehouse's `pageTotal`.
  - Excel export `buildPOWorkbook` — one sheet per warehouse; each sheet's
    "TOTAL AMOUNT" is that sheet's `total`.
  - PO detail modal (`PurchaseOrderPage.jsx`) — `groupPoDetailsByLocation` renders a
    per-warehouse `subtotal` per group.

## Design

### 1. New pure helpers in `BarcodeScan.jsx` (beside `bsrLinesForLocation`)

- `bsrLocationAmount(po, locationId)` → sum of line money over
  `bsrLinesForLocation(po, locationId)`, where per-line money is
  `Number(d.subtotal || d.quantity_ordered * d.unit_price || 0)`.
- `bsrLocationItemCount(po, locationId)` → `bsrLinesForLocation(po, locationId).length`.
- `bsrWarehouseBreakdown(po)` → array of `{ locationId, locationName, amount, count }`
  grouped by each line's `location_id` (name via `location_name` / `location`
  fallback, else "Unassigned warehouse"). Used only for the admin view.

All scoping logic continues to route through the single existing
`bsrLinesForLocation` helper.

### 2. PO list card behavior (`BarcodeScan.jsx` ~2805–2831)

- **Warehouse receiver** (`myLocationId` set):
  - **TOTAL AMOUNT** = `bsrLocationAmount(po, myLocationId)` (replaces whole-PO total).
  - **ITEMS** = `bsrLocationItemCount(po, myLocationId)` (replaces full count).
  - A small clarifier line under the amount: `for {enforcedLocationName}`, so it is
    unambiguous the figure is this warehouse's slice, not the whole PO.
- **Admin / centralized** (`myLocationId` null):
  - **TOTAL AMOUNT** and **ITEMS** stay whole-PO.
  - Add a compact per-warehouse breakdown line beneath the meta row, e.g.
    `Warehouse A ₱120,000 · Warehouse B ₱80,000`, from `bsrWarehouseBreakdown(po)`.
  - A PO with a single distinct warehouse renders **no** breakdown line.

### 3. Exports & PO detail modal

No code change — already per-warehouse (see Current state). Documented here so
"everywhere" is explicitly accounted for.

### 4. Tests

- Export the three new helpers and add `BarcodeScan.helpers.test.js` (Jest) covering:
  - receiver total/count = only their warehouse's lines on a mixed multi-warehouse PO;
  - `null` locationId (admin) = whole-PO figures;
  - legacy single-warehouse PO (lines lack `location_id`) falls back to header
    `location_id` correctly;
  - `bsrWarehouseBreakdown` groups and sums per warehouse, single-warehouse PO yields
    one entry.
- Manual verification: a `warehouse_personnel` account whose warehouse holds part of a
  multi-warehouse PO sees only its slice; an admin sees the whole-PO figure plus the
  breakdown.

## Scope guardrails (YAGNI)

- No backend changes.
- No change to how receiving/scanning works — only the displayed totals/counts.
- No change to the whole-PO grand total shown in the PO **management** page
  (`PurchaseOrderPage.jsx`) header — that is the correct full document total for
  IA/admin.
- No new export layout — exports already present per-warehouse totals.

## Files touched

- `frontend/src/admin/pages/BarcodeScan.jsx` — new helpers + card render.
- `frontend/src/admin/pages/BarcodeScan.helpers.test.js` — new test file.
- (CSS) `frontend/src/admin/styles/po_receiving.css` — small styles for the clarifier
  / breakdown line, if needed.
