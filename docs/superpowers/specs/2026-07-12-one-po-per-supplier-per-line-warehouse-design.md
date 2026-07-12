# One PO per Supplier, Per-Line Warehouse — Design

**Date:** 2026-07-12
**Branch:** backupV3
**Status:** Approved (brainstorming session)
**Builds on:** warehouse-only procurement (Plan A, `2026-07-10-warehouse-procurement-design.md`), budget control layer, auditor verification.
**Supersedes:** the Plan A decision that PR→PO conversion auto-splits into one PO per warehouse.

## Problem

Converting a purchase request whose lines target more than one warehouse currently creates **one PO per warehouse** (e.g. a 2-warehouse PR → 2 PO ids). The user wants **one PO per supplier**: a single purchase-order document sent to the supplier that lists all products, with each line carrying its own destination warehouse. Goods are then received location-by-location: a user working at Warehouse A receives the PO's Warehouse-A lines into Warehouse A; a Warehouse-B user receives the B-lines into Warehouse B.

## Decisions (user-confirmed)

- **One PO, per-line warehouse.** Conversion produces a single PO for the supplier; each PO line keeps its own destination warehouse.
- **Location-scoped receiving.** Each warehouse's user receives only that warehouse's lines, and the stock lands in that warehouse. The PO is Completed only when every line across every warehouse is fully received; otherwise Partially Received.
- **Per-warehouse budget settlement.** Each warehouse's budget is spent/settled independently as its own lines complete.
- **Grouped presentation.** The PO detail view and the export (document + Excel) group line items by destination warehouse (like the PR view). The single-header "Deliver To (Warehouse)" introduced on 2026-07-12 is reverted to this grouped form.

## 1. Data model

Add a nullable `location_id` (FK → `locations.location_id`, `nullOnDelete`/`restrict` consistent with existing PO detail FKs) to `purchase_order_details`. Guarded migration (`Schema::hasColumn`), applied to both `airkingV5` (dev) and `airkingV5_test`, `--force`.

- Per-line `location_id` is the **source of truth** for receiving, budget settlement, and display.
- The PO header keeps its `location_id`, set at conversion to the **first** line's warehouse ("primary"), because much existing code reads `po.location_id` (realtime broadcasts, direct-create path, some fallbacks). It is a compatibility field, not the routing key.
- **Legacy fallback:** a PO detail with null `location_id` falls back to the PO header `location_id` everywhere the per-line value is consumed. This keeps pre-change POs and the direct-create path working unchanged.

`PurchaseOrderDetail` gets a `location()` BelongsTo relation. `WITH_RELATIONS` on the PO controller eager-loads `details.location`.

## 2. Conversion (PR → PO)

`PurchaseRequestController::convertStore` (new-shape branch, `$hasLineWarehouses === true`):

- Remove the `groupBy('location_id')` / per-group PO creation loop.
- Create **one** `PurchaseOrder` for `$pr->supplier_id`. Header `location_id` = the first validated line's `location_id`. `total_amount` / `grand_total` = sum of all line subtotals.
- Create one `PurchaseOrderDetail` per validated line, each with its own `location_id` (already validated to match a PR `(product_id, location_id)` pair).
- `converted_to_po_id` = that single PO's id.
- Partial-vs-full status detection (status 16 vs 14) unchanged — it already groups by `(location_id, product_id)`.
- Realtime: broadcast `pr_converted` / `purchaseOrderChanged` for each distinct line warehouse (existing loop).

Legacy branch (`$hasLineWarehouses === false`, header supplier picked in the form) is unchanged: one PO on `$pr->location_id`, detail `location_id` left null.

## 3. Receiving (location-scoped)

`ReceivingController` and the PO-receiving scan surface:

- **Scoping:** when a warehouse user opens a PO to receive, only the lines whose effective warehouse (`detail.location_id ?? po.location_id`) equals the user's `location_id` are shown/receivable. Centralized/admin users may receive any warehouse's lines (existing read-scope helper `isCentralized`/`scopedLocationId`).
- **Stock routing:** received quantity for a line lands in **that line's** warehouse (`detail.location_id ?? po.location_id`), replacing the single header `location_id` used today for the inventory write. A receiving record is created per warehouse (the receiver's location).
- **Completion:** the PO is **Completed** (status 11) only when *all* lines across *all* warehouses reach their ordered quantity; otherwise **Partially Received** (status 12). Per-line/`quantity_received` accounting is unchanged; only the "all lines done?" check now spans warehouses.
- Realtime `po_received` / `inventoryChanged` fire for the receiving warehouse.

## 4. Budget settlement (per-warehouse group)

`BudgetService::spend(PurchaseOrder $po)` is rewritten to settle **per warehouse group** instead of once for the whole PO:

- Group the PO's detail lines by effective warehouse (`detail.location_id ?? po.location_id`).
- For each warehouse group whose lines are **now fully received**, resolve that warehouse's committed budget (the existing `resolveBudgetForPo` logic, but matching the *group's* `location_id` instead of `po.location_id`), then within one transaction:
  - record a `SPEND` txn for the **group's** subtotal (not `po.total_amount`),
  - consume the outstanding commitment for that `(budget_id, pr_id)`,
  - return the estimate surplus when the group's PR lines are fully ordered (`poCoversItsGroup`, scoped to the group's warehouse),
  - adjust `spent` / `committed` and fire threshold alerts.
- **Idempotency:** keyed per `(po_id, budget_id, txn_type=SPEND)` so re-invocation (receiving flow + manual status update) never double-spends a warehouse group.
- Reuses the per-`(budget_id, pr_id)` commit rows already written at PR approval (which commits per warehouse), so no commit-side change is needed.

Legacy single-warehouse POs (one group, header fallback) settle exactly as before.

`resolveBudgetForPo` and `poCoversItsGroup` are refactored to take an explicit `$locationId` (the group's warehouse) instead of reading `$po->location_id`.

## 5. PO detail view + export (grouped by warehouse)

Frontend `PurchaseOrderPage.jsx`:

- **Detail modal line items:** group rows under a per-warehouse header with a per-warehouse subtotal, mirroring the PR detail view (`groupDetailsByLocation` pattern). Each line reads `item.location_name ?? item.location?.location_name`.
- Replace the single "Deliver To (Warehouse)" address block with a **summary** listing every distinct destination warehouse on the PO.

`poExport.js`:

- **Document:** render line items grouped by delivery warehouse (a labeled sub-header row per warehouse). The header "Deliver To" summarizes all destination warehouses.
- **Excel:** same grouping — a warehouse label row before each warehouse's item rows; the deliver-to summary lists all warehouses.
- The `poPayload.items` mapping (built in `PurchaseOrderPage.jsx`) adds `warehouse_name` (from `item.location_name ?? item.location?.location_name`) per item so the export can group.

Backend `PurchaseOrderController`:

- `transform`/detail serialization exposes `location_id` + `location_name` per PO detail (as done for PR details).
- CSV `export`: the "Deliver To (Warehouse)" cell lists all distinct warehouses for the PO (`"Warehouse A; Warehouse B"`), and each per-line row shows that line's own warehouse.

## 6. Location scoping of the PO list

`PurchaseOrderController::index` (and any location-scoped PO query): a non-centralized warehouse user sees a PO when **any** of its detail lines has `location_id = mine` (or, for legacy POs with null line locations, the header `po.location_id = mine`). Change the `where('location_id', $mine)` to a `whereHas('details', ...) OR header-match` predicate. Realtime broadcasts already fire per distinct line warehouse.

## Out of scope (YAGNI)

- Direct PO create (`store`) — the manual, non-conversion path stays single-warehouse via the header. (It still writes null line `location_id`; the legacy fallback covers it.)
- Merging POs across suppliers or across PRs.
- Changing serial-scan mechanics.
- Backfilling `location_id` onto historical PO detail rows (legacy fallback handles them).

## Testing

Feature tests (`DatabaseTransactions` vs `airkingV5_test`, `BudgetTestCase` helpers, warehouse-PR fixture):

- **Conversion:** a 2-warehouse approved PR converts to **one** PO with per-line `location_id`; `converted_to_po_id` points at it; line count/subtotals correct.
- **Location-scoped receiving:** a Warehouse-A user receives only the A-lines; stock lands in A; PO stays Partially Received while B-lines remain; a Warehouse-B user receives B-lines → PO Completed.
- **Per-warehouse budget:** receiving A-lines settles the A budget (spend = A subtotal, commitment released, surplus returned) without touching B's budget; receiving B-lines later settles B's; combined ledger integrity (no double spend, committed ≥ 0).
- **Legacy:** a single-warehouse (header) PO settles and receives exactly as before.
- **Regression:** existing Budget suite stays green via the legacy/single-group path.

Frontend: `npx eslint` (no new errors) + `npm run build` (succeeds); manual check that the detail modal and both exports group by warehouse.

## Narrative

"One order per supplier: a purchase request that spans several warehouses becomes a single purchase order the supplier can read at a glance — every item listed under the warehouse it ships to. Each warehouse then receives its own items into its own stock, and each warehouse's budget is settled on its own portion, so one document stays in step with many destinations."
