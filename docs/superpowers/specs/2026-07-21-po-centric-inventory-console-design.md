# PO-Centric Inventory Monitoring Console

**Date:** 2026-07-21
**Branch:** backupV3
**Status:** Approved design, pending implementation plan

## Problem

The Inventory page's "Inventory" tab lists one row per SKU (product × location) with stock
levels (current / safety / reorder / maximum), and a separate "Warehouse Map" tab shows
batch cards. The client organizes their stock by **PO Number + the Location they placed it
in a warehouse**, and consumes/transfers oldest-first (FIFO). They want the console to
reflect that: list PO + Location groups, fold the Warehouse Map into it, and — via a View
button — show a group's details and its serial numbers (scoped to that PO + warehouse +
location, FIFO-ordered). The per-SKU stock-level view must remain, in its own tab.

## Terminology

- **Warehouse** = a `locations` row (the receiving location). Referred to by `location_id`.
- **Location** = where the PO's goods were placed inside the warehouse — the batch's
  `zone_label` (the client labels these "Location 1", "Location 2", …). All of a PO's goods
  at a warehouse share one Location.
- **Group** = one `(PO, warehouse)` pair. Its Location label is the `zone_label` of that
  group's batches.
- **FIFO** = oldest `received_at` first, everywhere batches/serials are listed. This matches
  the existing FIFO deduction (`InventoryBatchService::deductFifo`) — no deduction logic
  changes, only display order.

## Tab restructure (Inventory page)

Current top tabs: `Stock Movement & Turnover` · `Inventory` · `Activity Log` ·
`Warehouse Map`.

New top tabs: `Stock Movement & Turnover` · **`Inventory Monitoring Console`** (new,
PO-centric) · **`Stock-Level Monitoring`** (the current per-SKU table, unchanged) ·
`Activity Log`.

- The `warehouse-map` tab and its panel are **removed**; its batch display (and Location-label
  editing) fold into the new console's row expander.
- The existing per-SKU table (`activeTab === 'inventory'` block) moves under a new
  `stock-level` tab id, otherwise unchanged (still shows current/safety/reorder/maximum, its
  own View → the existing product-scoped 3-tab modal).
- The new console uses tab id `po-console`.
- Default tab stays `stock-movement`.

## Backend

New controller `back-end/app/Http/Controllers/Api/PoInventoryController.php`, two endpoints,
registered in `routes/api.php` near the other inventory routes.

### Endpoint 1 — list PO + warehouse groups

`GET /api/po-inventory-groups`

- Query `InventoryBatch::forUser($request->user())` (location scoping), `receiving_id` not
  null, joined to `receivings` → `purchase_orders` → `suppliers`, and `locations` for the
  warehouse name.
- Group in PHP by `(po_id, location_id)`.
- Per group, return:
  - `group_key`         = `"{po_id}-{location_id}"`
  - `po_id`, `po_number`
  - `warehouse_id` (= `location_id`), `warehouse_name`
  - `location_label`    = distinct non-null `zone_label`s of the group's batches, comma-joined
    (or `null` → UI shows `—`)
  - `supplier_name`
  - `order_date`        = PO `order_date`
  - `first_received_at` = min `received_at` of the group's batches (FIFO anchor)
  - `item_count`        = distinct `product_id` count
  - `total_received`, `total_remaining`
  - `batches`: array (FIFO order, `received_at` asc) of
    `{ batch_id, product_id, product_name, zone_label, quantity_received, quantity_remaining, received_at }`
- Groups ordered by `first_received_at` ascending (FIFO).
- One query fetches batches with joins; grouping/nesting done in PHP.

### Endpoint 2 — group detail (the 3 tabs)

`GET /api/po-inventory-groups/{po}?location_id={warehouseId}`

`{po}` is `po_id`; `location_id` is required (identifies the warehouse). Returns:

- `overview`:
  - `po_number`, `supplier_name`, `order_date`, `warehouse_name`, `location_label`
  - `totals`: `{ item_count, total_received, total_remaining }`
  - `products`: `[{ product_id, product_name, quantity_received, quantity_remaining }]`
    (from the group's batches, FIFO order)
- `receiving_history`: receiving events for this PO at this warehouse — join
  `receivings` (where `po_id` and `location_id` match) → `receiving_details` → `products`,
  returning `{ receiving_number, received_at, received_by_name, product_name, quantity_amount, scanned_barcode }`, ordered `received_at` asc.
- `serials`: grouped by product, each serial FIFO-ordered (oldest `created_at`/`received_at`
  first). Resolution scoped to this PO + warehouse:
  1. `receivingIds` = `receivings` where `po_id = {po}` and `location_id = {warehouseId}`.
  2. Appliance serials: `item_serial` where `receiving_id IN receivingIds`,
     `location_id = {warehouseId}`, `serial_type = 'received'`, ordered `created_at` asc.
  3. Consumable barcodes: `receiving_details.scanned_barcode` where
     `receiving_id IN receivingIds`, `scanned_barcode` not null, ordered `created_at` asc.
  4. Merge per product → `[{ product_id, product_name, serials: [string, …] }]`.

Both endpoints must respect location scoping so a warehouse user only sees their warehouse's
groups (reuse `forUser` / the same scoping the existing batch endpoints use).

## Frontend

**File:** `frontend/src/admin/pages/InventoryManagement.jsx` (+ `api.js` wrappers,
`inventory_management.css`).

- **Tabs:** replace the `inventory` tab button with two buttons (`po-console`,
  `stock-level`); remove the `warehouse-map` button. Move the existing per-SKU table panel to
  render under `activeTab === 'stock-level'`. Remove the `warehouse-map` panel.
- **Console panel (`po-console`):**
  - On tab activation, fetch `GET /api/po-inventory-groups` into `poGroups` state
    (loading/error states like the existing warehouse-map fetch).
  - Render a table: columns PO Number, Warehouse, Location, Supplier, Received, Items, Qty,
    Actions. One row per group, FIFO order. A search box filters by PO number / supplier /
    warehouse (client-side, mirroring the existing `searchTerm` pattern).
  - Each row: an expander (reusing `inv-batch-*` styles) listing the group's `batches`
    (product, Location, qty received / remaining, received) FIFO-ordered; and a **View**
    button.
  - Preserve Location-label editing: each batch in the expander keeps the existing inline
    zone-edit control (reuse `PATCH /api/inventory-batches/{batch}` and the existing
    `editingZoneBatchId` / `saveZoneEdit` logic) so the folded-in Warehouse Map keeps its
    edit capability.
- **View modal (PO group):** clicking View fetches
  `GET /api/po-inventory-groups/{po_id}?location_id={warehouse_id}` into `poGroupDetail`, and
  opens a modal with the 3 tabs:
  - **Overview** — PO/supplier/date/warehouse/Location + products table + totals.
  - **Stock-In History** — the `receiving_history` rows.
  - **Serial Numbers** — the `serials` grouped by product; each product shows its serials as
    chips (reuse `inv-serial-chips`), FIFO order. The long-list concern is naturally handled
    because this is already a modal.

  This is a **separate modal** from the existing per-SKU detail modal (which stays on the
  Stock-Level Monitoring tab). Reuse the shared `Modal` component and the `invd-*` tab styles.

## Out of scope

- No change to receiving, issuance, transfer, or FIFO deduction logic.
- No change to the per-SKU Stock-Level Monitoring table beyond moving it to its own tab.
- No new persisted columns/tables — groups are derived at query time.
- The existing product-scoped detail modal (Overview / Stock-In History / Serial Numbers for
  a single SKU) stays as-is for the Stock-Level tab.

## Testing

Backend feature tests (`airkingV5_test`, `DatabaseTransactions`), new file
`back-end/tests/Feature/Inventory/PoInventoryGroupsTest.php`:

- **List groups:** receive two POs into one warehouse (via `/api/receivings`, distinct
  scanned barcodes). `GET /api/po-inventory-groups` returns one group per PO with correct
  `po_number`, `warehouse_id`, `item_count`, `total_received`, and batches nested FIFO-ordered.
- **FIFO order:** two batches received on different dates appear oldest-first in a group's
  `batches`, and groups are ordered by `first_received_at` ascending.
- **Group detail — serials scoped:** for an appliance PO group,
  `GET /api/po-inventory-groups/{po}?location_id={wh}` returns `serials` containing that PO's
  serial numbers and **not** serials from a different PO at the same warehouse (isolation).
- **Group detail — consumable:** a consumable PO group's `serials` contains its scanned
  barcode(s); `overview.products` and `totals` are correct.
- **Location scoping:** a user scoped to warehouse A does not see warehouse B's groups.

Frontend manual check: the two new tabs render; the console lists PO+Location groups FIFO;
expanding shows batches; View opens the 3-tab modal with serials filtered to that PO +
warehouse + location; Stock-Level Monitoring tab still shows the SKU table.
