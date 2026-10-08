# Per-Row Stock In / Stock Out / Transfer Columns on the Serial Numbers Table

**Date:** 2026-07-21
**Branch:** backupV3
**Status:** Approved design, pending implementation plan

## Problem

The Inventory detail modal's "Serial Numbers" tab lists rows (one per unit for appliances,
one per receiving batch for consumables) but shows no per-row movement. The top summary
gives branch-wide Stock In / Stock Out / Transfers totals; the user wants those three
figures broken down **per row** so they can see, line by line, what came in, what was sold,
and what was transferred.

## Data constraint (drives the design)

Not every movement is attributable to every row from existing data:

| Row type | Stock In | Stock Out (sold) | Transfer |
|----------|----------|------------------|----------|
| Appliance (1 row = 1 unit, `item_serial`) | Yes (row exists) | **No** — the serial row is deleted on sale, so a sold unit has no row | Yes (`serial_type = 'transferred'`) |
| Consumable (1 row = 1 receiving batch) | Yes (`receiving_details.quantity_amount`) | Yes (`issuance_details.receiving_id` links a sale to its batch) | **No** — no transfer table carries a receiving/batch reference |

Unattributable cells render as `—` (em dash) rather than a misleading `0`.

## Per-row values

### Consumable rows (each = one receiving batch)
- **Stock In** = `receiving_details.quantity_amount` (already on the synthetic row's source).
- **Stock Out** = pieces sold from this batch:
  `SUM(COALESCE(issuance_details.quantity_in_pieces, issuance_details.quantity_issued, 0))`
  from `issuance_details` joined to `issuances`, where
  `issuances.location_id = $locationId`, `issuance_details.product_id = $productId`,
  `issuance_details.receiving_id = <this row's receiving_id>`, and the issuance is not
  soft-deleted. Computed once as a grouped map (see below), not per-row queries.
- **Transfer** = `null` → rendered `—`. Transfers are not batch-linked; the branch-wide
  transfer total remains only in the top summary.

### Appliance rows (each = one unit, `item_serial`)
- **Stock In** = `1` (the unit was received).
- **Stock Out** = `null` → rendered `—` (sold units have no serial row).
- **Transfer** = `1` when `item_serial.serial_type = 'transferred'`, else `0`.

## Backend changes

**File:** `back-end/app/Http/Controllers/Api/InventoryController.php`, method `stockHistory`.

1. **Consumable branch** (the `else` that builds synthetic rows from `$receivingDetails`):
   - Before the map, build a grouped lookup of sold-pieces per receiving batch:
     ```php
     $soldByReceiving = DB::table('issuance_details')
         ->join('issuances', 'issuances.issuance_id', '=', 'issuance_details.issuance_id')
         ->where('issuance_details.product_id', $productId)
         ->where('issuances.location_id', $locationId)
         ->whereNull('issuances.deleted_at')
         ->whereNotNull('issuance_details.receiving_id')
         ->groupBy('issuance_details.receiving_id')
         ->selectRaw('issuance_details.receiving_id AS receiving_id, '
             . 'SUM(COALESCE(issuance_details.quantity_in_pieces, issuance_details.quantity_issued, 0)) AS sold')
         ->pluck('sold', 'receiving_id');
     ```
   - In the row map, add three fields to each synthetic row:
     - `row_stock_in`  = `(int) $rd->quantity_amount`
     - `row_stock_out` = `(int) ($soldByReceiving[$rd->receiving_id] ?? 0)`
     - `row_transfer`  = `null`

2. **Appliance branch** (the `if ($isSerialized)` item_serial query): add three computed
   fields to each serial row. Since the query returns `serial_type`, map after fetching:
   - `row_stock_in`  = `1`
   - `row_stock_out` = `null`
   - `row_transfer`  = `$s->serial_type === 'transferred' ? 1 : 0`
   (Add `->map(...)` after `->get()` to attach these, or select them via raw expressions.
   Prefer a post-`get()` `->map()` so the SQL stays readable.)

No change to the existing top-`summary` fields.

## Frontend changes

**File:** `frontend/src/admin/pages/InventoryManagement.jsx`, Serial Numbers table.

- Add three `<th>` headers after the existing "Status" column: `Stock In`, `Stock Out`,
  `Transfer`.
- Add three `<td>` cells per row rendering `s.row_stock_in`, `s.row_stock_out`,
  `s.row_transfer`, each with a helper: show the number when it is a number, and `—` when
  the value is `null`/`undefined`.
- Keep the existing columns and status pill unchanged.

**File:** `frontend/src/admin/styles/inventory_management.css` — a small right-aligned,
mono/number cell style for the three columns if the existing table cell style is not
already suitable; reuse existing `invd-` classes where possible.

## Out of scope

- No new tables, migrations, or columns.
- No change to issuance/transfer/receiving processing.
- No attempt to reconstruct sold appliance units as rows (that was an alternative the user
  did not choose).
- No batch-level attribution of transfers (data does not support it).

## Testing

- **Backend feature test** (`airkingV5_test`, `DatabaseTransactions`), extend or add
  alongside `StockHistoryMovementSummaryTest`:
  - **Consumable case:** one product at a location with two receiving batches (say 12 and 8
    pieces). Create an issuance of 5 pieces whose `issuance_details.receiving_id` points at
    the first batch's receiving. Assert the first batch row has `row_stock_in = 12`,
    `row_stock_out = 5`, `row_transfer = null`; the second batch row has `row_stock_in = 8`,
    `row_stock_out = 0`, `row_transfer = null`.
  - **Appliance case:** a serialized product with two `item_serial` rows, one
    `serial_type = 'received'` and one `serial_type = 'transferred'`. Assert the received
    row has `row_stock_in = 1`, `row_stock_out = null`, `row_transfer = 0`; the transferred
    row has `row_transfer = 1`.
- **Regression:** existing `StockHistoryMovementSummaryTest` still passes; the summary block
  is unchanged.
- **Frontend manual check:** open a consumable and an appliance product; confirm the three
  new columns render, numbers appear where expected, and `—` shows for the unattributable
  cells.
