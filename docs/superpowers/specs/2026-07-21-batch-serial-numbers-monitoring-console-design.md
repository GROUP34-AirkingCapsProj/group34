# Serial Numbers Within Batches — Inventory Monitoring Console & Warehouse Map

**Date:** 2026-07-21
**Branch:** backupV3
**Status:** Approved design, pending implementation plan

## Problem

The Inventory Monitoring page shows batches in two places, neither of which reveals which
serial numbers / scanned barcodes belong to each batch:

1. **Inventory Monitoring Console** table (`inventory` tab) — each SKU row has a "Batches"
   expander (`frontend/src/admin/pages/InventoryManagement.jsx:854-873`) showing PO #,
   received date, qty remaining, qty received.
2. **Warehouse Map** tab — batch cards (`InventoryManagement.jsx:914-994`) showing product,
   PO #, received date, a remaining/received bar, and zone label.

The user wants each batch, in both views, to also list its serial numbers.

## Serial ↔ batch linkage

A batch is uniquely keyed by `receiving_id + product_id + location_id` (one receiving can
produce several batches across products/locations — see
`InventoryScanTransactionController.php:401-413`). Serials link to a batch through that key:

- **Appliances:** `item_serial` rows carry `receiving_id`, `product_id`, `location_id`,
  `serial_number`, `serial_type`. The units physically in a batch are the rows with
  `serial_type = 'received'`.
- **Consumables:** `receiving_details.scanned_barcode` carries the scanned barcode, with
  `receiving_id`, `product_id`; the location comes from `receivings.location_id`.
- **Legacy batches** (`receiving_id = null`): no serials.

## Backend

### New shared service: `BatchSerialResolver`

**File:** `back-end/app/Services/BatchSerialResolver.php` (create).

Purpose: given a collection of batches, return a map `batch_id => string[]` of serials,
using two grouped queries (no N+1). Both endpoints use it, keeping the logic DRY.

Contract:

```php
/**
 * @param  \Illuminate\Support\Collection $batches  items exposing
 *         batch_id, product_id, location_id, receiving_id
 * @return array<int|string, string[]>  batch_id => list of serial strings
 */
public function resolve($batches): array
```

Algorithm:
1. Keep only batches with a non-null `receiving_id`. If none, return `[]`.
2. Collect distinct `receiving_id`, `product_id`, `location_id` values.
3. Query appliance serials:
   ```php
   ItemSerial::query()
       ->whereIn('receiving_id', $receivingIds)
       ->whereIn('product_id', $productIds)
       ->whereIn('location_id', $locationIds)
       ->where('serial_type', 'received')
       ->get(['serial_number', 'product_id', 'location_id', 'receiving_id']);
   ```
   Group into `$applianceMap["{product_id}|{location_id}|{receiving_id}"][] = serial_number`.
4. Query consumable barcodes:
   ```php
   DB::table('receiving_details')
       ->join('receivings', 'receivings.receiving_id', '=', 'receiving_details.receiving_id')
       ->whereIn('receiving_details.receiving_id', $receivingIds)
       ->whereIn('receiving_details.product_id', $productIds)
       ->whereIn('receivings.location_id', $locationIds)
       ->whereNotNull('receiving_details.scanned_barcode')
       ->get([
           'receiving_details.scanned_barcode as scanned_barcode',
           'receiving_details.product_id as product_id',
           'receivings.location_id as location_id',
           'receiving_details.receiving_id as receiving_id',
       ]);
   ```
   Group into `$consumableMap["{product_id}|{location_id}|{receiving_id}"][] = scanned_barcode`.
5. For each input batch: `$key = "{product_id}|{location_id}|{receiving_id}"`; serials =
   `$applianceMap[$key]` if set, else `$consumableMap[$key]` if set, else `[]`. (A product
   is one type, so at most one map has entries for a given key.)
6. Return `[batch_id => serials[]]`.

The over-fetch via three independent `whereIn`s is fine: the exact composite-key match at
attach time (step 5) guarantees no batch receives another batch's serials.

### Endpoint changes (both add `serials`)

- `back-end/app/Http/Controllers/Api/InventoryController.php::batches` (`GET /inventory/{id}/batches`).
  The batches come from `InventoryBatchService::batchesForDisplay($productId, $locationId)`
  and are `InventoryBatch` models (carry `product_id`, `location_id`, `receiving_id`,
  `batch_id`). Resolve once, then add `'serials' => $map[$batch->batch_id] ?? []` to each
  mapped row.
- `back-end/app/Http/Controllers/Api/InventoryBatchController.php::index` (`GET /inventory-batches`).
  Same: resolve `$batches`, add `'serials' => $map[$batch->batch_id] ?? []` to each mapped row.

No change to existing fields.

## Frontend

**File:** `frontend/src/admin/pages/InventoryManagement.jsx`.

- **Console Batches expander** (`:854-873`): add a `<th>Serial Numbers</th>` header and a
  `<td>` per batch that renders `b.serials` as wrapped chips (`inv-serial-chips` container,
  `inv-serial-chip` per serial); render `—` when the list is empty.
- **Warehouse Map card** (`:943-950` area, after the stock row): add a serials section —
  a small label "Serial Numbers" and the same chip list; render `—` when empty.

**File:** `frontend/src/admin/styles/inventory_management.css` — add `.inv-serial-chips`
(flex, wrap, gap) and `.inv-serial-chip` (small mono pill) styles consistent with existing
`inv-`/`invd-` styling.

## Out of scope

- No new tables, migrations, or columns.
- No change to receiving/issuance/transfer processing.
- Transferred appliance units (`serial_type = 'transferred'`) are not shown under a batch —
  they are no longer physically in that lot.
- No pagination/virtualization of very long serial lists (chip container wraps; acceptable
  for expected batch sizes).

## Testing

Backend feature tests (`airkingV5_test`, `DatabaseTransactions`), new file
`back-end/tests/Feature/Inventory/BatchSerialNumbersTest.php`:

- **Appliance:** receive a serialized product with a `scanned_barcode` via `/api/receivings`
  (creates a batch + an `item_serial` row with the same `receiving_id`). Call
  `GET /api/inventory/{inventory}/batches` and `GET /api/inventory-batches`; assert the
  matching batch's `serials` array contains that serial number.
- **Consumable:** receive an `installation_material` product with a `scanned_barcode`; assert
  the batch's `serials` contains the scanned barcode.
- **Isolation:** two products received under the same receiving flow do not cross-contaminate
  each other's `serials` (each batch only lists its own).
- **Legacy/empty:** a batch created without a `receiving_id`, or a product with no serials,
  returns `serials: []`.

Frontend manual check: expand a SKU's Batches in the Console and confirm serial chips render;
open the Warehouse Map and confirm each card lists its serials, with `—` where none.
