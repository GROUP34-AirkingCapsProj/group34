# PO/Location-Batched Inventory Monitoring with FIFO

**Date:** 2026-07-20
**Branch:** backupV3
**Status:** Approved design

## Problem

Inventory is currently tracked as a single running aggregate per
product+location (`inventory.quantity_on_hand`, `available_quantity`,
`packages_on_hand`). There is no notion of "batch" or "lot" tied to a specific
PO/receiving event on the inventory side. The only existing PO-linkage is
`item_serial.receiving_id` (`receivings.po_id` gives the PO), and it's used
purely as a record, never as an ordering signal — nothing in `TransferService`
or `IssuanceScanController` selects stock oldest-first.

The client wants:
1. An inventory monitoring UI that groups/shows stock by PO batch and
   location, so staff can see at a glance how much of each batch is left and
   whether a product needs restocking.
2. Transfers and issuances to move stock in FIFO (first-in-first-out) order —
   the oldest received stock should be consumed before newer stock.

This applies to **both** serialized items (appliances tracked via
`item_serial`) and bulk/consumable items (tracked only as an aggregate
quantity today, with zero per-unit or per-batch trace).

## Current state (verified in code)

- `inventory` table: one row per `product_id`+`location_id`. No batch/PO
  column. Written via `Inventory::firstOrCreate(['product_id','location_id'])`
  then incremented/decremented in `ReceivingController`, `TransferService`,
  `IssuanceScanController`.
- `item_serial` table: one row per serialized unit, with `product_id`,
  `location_id`, `receiving_id`, `serial_number`, `serial_type`, `status_id`,
  `created_at`. Only created for serialized items.
- `purchase_orders` → `purchase_order_details` → `receivings` →
  `receiving_details` form the document-level PO/receiving chain, but nothing
  on the inventory side rolls this up per batch.
- No FIFO ordering exists anywhere — `ItemSerial::issuableAt()` filters by
  location/status/serial_number only; bulk deductions just decrement the
  aggregate.
- Existing `Inventory.reorder_level` / `products.recommended_stocks` already
  drive a low-stock indicator on the current Inventory page
  (`InventoryController::index`) — this stays as-is.

## Data model

New table `inventory_batches`:

| Column | Notes |
|---|---|
| `id` | PK |
| `product_id` | FK → products |
| `location_id` | FK → locations |
| `receiving_id` | FK → receivings (gives PO via `receivings.po_id`) |
| `quantity_received` | immutable, set at creation |
| `quantity_remaining` | mutable running balance, decremented on transfer/issuance |
| `received_at` | copied from the receiving event; drives FIFO ordering |
| `created_at`, `updated_at` | standard |

One `inventory_batches` row is created per receiving line, for **both**
serialized and bulk products — this keeps the monitoring UI's query path
uniform regardless of item type, even though serialized items also have
per-unit truth in `item_serial`.

No changes to `purchase_orders`, `purchase_order_details`, `receivings`, or
`receiving_details` — batches simply reference the existing `receiving_id`.

## Receiving flow changes

In `ReceivingController`, alongside the existing per-line `ItemSerial`
creation and `Inventory` aggregate increment, create one `InventoryBatch` row
per receiving line (`quantity_remaining = quantity_received`), in the same DB
transaction as the existing writes.

## Transfer & Issuance FIFO logic

**Bulk items:** When `TransferService` or `IssuanceScanController` deducts a
quantity from `Inventory.quantity_on_hand`/`available_quantity`, it also
cascades the deduction across that product+location's `inventory_batches`,
ordered `received_at ASC`, consuming each batch's `quantity_remaining` until
the requested amount is covered (spilling into the next-oldest batch as
needed). This is fully automatic — no batch picker UI, since bulk
transfer/issuance is quantity-entry only, not scan-based.

**Serialized items:** No new consumption writes to `inventory_batches` are
needed from the serial's own record — instead, the serial's batch is derived
via `item_serial.receiving_id → inventory_batches.receiving_id`, and that
batch's `quantity_remaining` is decremented when the serial's status changes
to issued/transferred out of the location (kept in sync in the same
transaction as today's `ItemSerial` status update). The serial-selection UI
(scan list, `issuableAt()` results) is sorted oldest-batch-first
(`received_at ASC`), and shows a non-blocking warning banner if staff
scan/select a unit from a newer batch while an older batch of the same
product is still available at that location. This is advisory, not
enforced — staff can proceed anyway.

## Monitoring UI

Extend the existing Inventory page (`InventoryController::index` +
its React page) rather than building a new screen. Each product+location row
gets an expandable drill-down listing that row's `inventory_batches`
(PO number via `receiving.po_id`, receiving date, `quantity_remaining`),
sorted oldest-first. The existing low-stock/`reorder_level` indicator and
threshold logic are unchanged — batching is additive detail, not a
replacement for the restock signal.

## Error handling / legacy data

Stock that existed before this feature ships (or any future case where an
`inventory_batches` row is missing/corrupt relative to the `Inventory`
aggregate) is handled via a fallback: if a cascading bulk deduction can't
find enough batched quantity to cover the requested amount, the shortfall is
drawn from a synthetic "unbatched/legacy" catch-all batch (no `receiving_id`,
`received_at` = null, sorts last) rather than blocking the transaction. This
keeps the `Inventory` aggregate as the ultimate source of truth for "can this
transfer/issuance proceed at all" — batch-level FIFO is best-effort
housekeeping on top of it, never a hard blocker for stock that predates
batch tracking.

## Testing

- Backend feature tests: batch creation on receive (serialized + bulk),
  FIFO cascade on transfer and on issuance (including multi-batch spillover
  and the legacy-fallback case), batch `quantity_remaining` sync when a
  serial's status changes.
- Frontend test: Inventory page drill-down renders batches in oldest-first
  order with correct PO/date/remaining-qty display.

## Out of scope

- FIFO cost-of-goods / COGS tracking (batches don't carry unit cost; this is
  physical stock rotation only, not accounting valuation).
- Hard-blocking FIFO enforcement (staff can override on serialized items;
  there's no manual override surface for bulk items in the first place).
- Changes to PO Recommendation analytics or the existing low-stock/reorder
  threshold logic.
