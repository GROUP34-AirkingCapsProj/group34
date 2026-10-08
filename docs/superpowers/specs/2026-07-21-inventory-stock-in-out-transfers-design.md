# Inventory Serial Numbers Tab — Stock In / Stock Out / Transfers Summary

**Date:** 2026-07-21
**Branch:** backupV3
**Status:** Approved design, pending implementation plan

## Problem

The Inventory detail modal's "Serial Numbers" tab lists the serial numbers (appliances)
or scanned receiving barcodes (consumables) for a product at a location, but gives no
summary of stock movement. Users cannot tell, at a glance, how many units came in, how
many left because a customer bought them, and how many left because they were transferred
to another branch.

The core confusion to eliminate: **a unit that was transferred to another location must
not look like a unit that was sold to a customer.** These are different events and must be
counted separately.

## Goal

Add a single summary line to the top of the Serial Numbers tab, scoped to the current
product + location, showing three distinct counts (in pieces):

- **Stock In** — total pieces received at this location
- **Stock Out** — total pieces sold to customers (issuance) at this location
- **Transfers** — total pieces sent from this location to other locations

Scope is limited to the inventory Serial Numbers tab. The interbranch transfer page is
**not** changed — the separation guarantee is satisfied structurally (see below), and the
transfer page already displays per-product transferred/received quantities.

## Why the sold-vs-transferred separation is safe

Sales and transfers are recorded in entirely separate tables:

- A customer sale writes rows to `issuance_details` (parent `issuances`).
- An interbranch transfer writes rows to `transfer_details` (parent `transfers`), and sets
  `transfer_details.quantity_transferred` only on dispatch.

Because each bucket is sourced from its own table, a transfer can never be counted as a
sale (and vice versa). No change to the transfer or issuance *processing* logic is required
— the fix is purely additive reporting.

Note the per-unit `item_serial` table is **not** a reliable source for "out" counts: sold
appliance serials are deleted on issuance, and transferred serials toggle their
`serial_type` back to `received` on receipt at the destination. The detail-table sums below
are the durable record and work identically for appliances and consumables.

## Data sources (per product + location, all in pieces)

| Bucket      | Value                                             | Source |
|-------------|---------------------------------------------------|--------|
| Stock In    | `SUM(receiving_details.quantity_amount)`          | Already computed in `stockHistory` as `summary.total_pieces_received` (`$totalPieces`). |
| Stock Out   | `SUM(issuance_details.quantity_in_pieces)`        | `issuance_details` joined to `issuances` where `issuances.location_id = :locationId` and `issuance_details.product_id = :productId`. |
| Transfers   | `SUM(transfer_details.quantity_transferred)`      | `transfer_details` joined to `transfers` where `transfers.from_location_id = :locationId` and `transfer_details.product_id = :productId`. |

Filtering notes:
- Both `Issuance` and `Transfer` models use `SoftDeletes`; querying through Eloquent
  (or with a `whereNull('deleted_at')` guard on query-builder joins) excludes voided
  records.
- `transfer_details.quantity_transferred` is null/0 until dispatch, so draft/requested
  transfers contribute nothing — no status filter is strictly required, but the query
  should coalesce nulls to 0.
- `quantity_in_pieces` is the piece-normalized issued quantity (consumables issue in
  packages but store pieces); using it keeps the unit consistent with Stock In.

## Backend change

**File:** `back-end/app/Http/Controllers/Api/InventoryController.php`, method `stockHistory`.

1. After the existing receiving/serial aggregation, add two aggregate queries:
   - `$soldPieces` = sum of `issuance_details.quantity_in_pieces` for this product via
     `issuances.location_id = $locationId` (soft-deletes excluded).
   - `$transferredPieces` = sum of `transfer_details.quantity_transferred` for this product
     via `transfers.from_location_id = $locationId` (soft-deletes excluded, nulls coalesced
     to 0).
2. Add three fields to the returned `summary` block:
   - `stock_in` = existing `$totalPieces`
   - `stock_out` = `$soldPieces`
   - `transferred` = `$transferredPieces`

`total_pieces_received` and `serial_count` remain unchanged for backward compatibility.

## Frontend change

**File:** `frontend/src/admin/pages/InventoryManagement.jsx`, Serial Numbers tab body
(the `detailTab === 'serials'` panel).

Render a small stat row above the serials table using the three new summary fields, e.g.:

> **Stock In** 40 · **Stock Out** 12 · **Transfers** 8

Use existing inventory-detail styling primitives (e.g. an `invd-` stat/summary row
consistent with the rest of the modal). The row shows whenever `historyData` is loaded, for
both appliance and consumable products. When a value is 0 it still displays (0 is
meaningful here).

## Out of scope

- No changes to issuance or transfer processing logic.
- No changes to the interbranch transfer UI.
- No per-unit status tracking added to `item_serial`.
- No new tables or migrations.

## Testing

- **Backend (feature test on `airkingV5_test`, DatabaseTransactions):** seed a product at a
  location with N received pieces, M issued pieces (via `issuances`/`issuance_details` at
  that location), and T transferred pieces (via `transfers`/`transfer_details` with
  `from_location_id` = that location). Assert the `stockHistory` response `summary` returns
  `stock_in = N`, `stock_out = M`, `transferred = T`. Include a case proving a transfer is
  NOT counted in `stock_out` and an issuance is NOT counted in `transferred`.
- **Regression:** existing `stockHistory` fields (`total_pieces_received`, `serial_count`,
  `serial_records`) unchanged for a serialized product.
- **Frontend:** verify the stat row renders for both an appliance and a consumable product,
  and that transferred units appear under Transfers, not Stock Out.
