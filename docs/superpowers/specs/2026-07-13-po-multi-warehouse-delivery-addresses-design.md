# PO Multi-Warehouse Delivery Addresses — Design

**Date:** 2026-07-13
**Scope:** Purchase Orders only (detail modal, document preview, Excel/printed export)
**Branch:** backupV3

## Problem

A single PO now carries **per-line destination warehouses** (one-PO-per-supplier shape). The
detail modal and document preview already list all destination warehouse *names* (joined with
commas), but only **one address** is ever displayed — the PO header's single `location`.

Root cause: `PurchaseOrderController::transformPoDetail()` flattens each line to `location_id`
+ `location_name` only. It **drops** the location's address/city/province/region/phone/email/
manager fields. So the frontend has no data to render a second warehouse's address, even though
`details.location` is eager-loaded.

Additionally, the manual **Ship To** fields are currently rendered *as* the "Deliver To
(Warehouse)" block, conflating two distinct concepts.

## Goals

1. Show **every destination warehouse's full address**, not just one.
2. In the **preview and export**, split into **one self-contained page per warehouse** when a
   PO spans 2+ warehouses.
3. Separate the **manual Ship-To** (header info) from the **per-warehouse Deliver-To** (real
   delivery destination).

## Key concepts (clarified with user)

- **Manual "Ship To — Company/Name" + "Ship To — Address"**: header-level information the user
  types on the create form. It is *header display only* — it does **not** change which
  warehouses the PO delivers to, and is **not** a delivery destination. Single value, repeated
  in the header of every page.
- **Per-warehouse "Deliver To" address**: the real address of each chosen destination warehouse,
  pulled from the location record. Shown once per warehouse (a block in the modal; a page in the
  preview/export).

## Design

### 1. Backend — enable per-line addresses

`PurchaseOrderController::transformPoDetail()` — add a nested `location` object to each
transformed detail:

```php
'location' => $detail->location ? [
    'location_id'    => $detail->location->location_id,
    'location_name'  => $detail->location->location_name,
    'address'        => $detail->location->address,
    'city'           => $detail->location->city,
    'province'       => $detail->location->province,
    'region'         => $detail->location->region,
    'contact_number' => $detail->location->contact_number,
    'email'          => $detail->location->email,
    'manager_name'   => $detail->location->manager_name,
] : null,
```

- `details.location` is already eager-loaded (`self::WITH_RELATIONS`), so no new query.
- Keep existing flat `location_id` / `location_name` for backward compatibility.

### 2. PO detail modal (`PurchaseOrderPage.jsx` ~1411–1418)

Replace the single "Deliver To (Warehouse)" block with **one address block per distinct
destination warehouse** (deduped by `location_id` from `details`), each rendering its own full
address / phone / email / manager. Layout: one titled block per warehouse (user-approved).

- **Fallback:** if details carry no per-line location (legacy single-warehouse PO), render the
  header `d.location` block exactly as today.
- The modal is a single scrollable view — **no page splitting here**, just stacked blocks.

### 3. PO document preview (create/convert, ~1161–1189) and 4. Export (`poExport.js`)

Both the on-screen preview and the printed/Excel export switch to **one self-contained page per
warehouse** when the PO has 2+ distinct destination warehouses.

**Self-contained page** = for each warehouse, repeat:
- Company header (name / address / email)
- Manual **Ship To** header block (single value, same on every page) — company name + address
  the user entered. If both manual fields are blank, omit this block.
- Supplier + PO reference block
- **Deliver To (Warehouse)** block: *this page's* warehouse name + its real location address /
  phone / email / manager
- Line items for **this warehouse only**
- Subtotal / total for **this warehouse only**

Pages are separated by a CSS page break (`page-break-after: always`) so each warehouse prints on
its own physical page. On-screen preview renders each page as a visually separated "page" card.

**Single-warehouse PO** (1 distinct destination): render exactly one page — behaviour unchanged
from today apart from the Ship-To / Deliver-To separation described below.

#### Ship-To vs Deliver-To separation (all surfaces)

- The **manual Ship-To** is no longer used as the Deliver-To block. It becomes a header-level
  "Ship To:" block (company name + address) shown once per page.
- The **Deliver-To (Warehouse)** block always shows the real destination warehouse address.

#### Payload changes (`poPayload` builder ~1520 + `poExport.js`)

Add a deduped array to the export payload:

```js
ship_to_locations: [
  { name, address, contact_number, email, manager_name }, // one per distinct warehouse
]
```

Built from the distinct detail locations (using the newly-enriched `detail.location`). Update
`exportPOToDocument` and `exportPOToExcel`:

- `exportPOToDocument`: emit one self-contained page section per entry in `ship_to_locations`,
  each with only that warehouse's items; join with page breaks.
- `exportPOToExcel`: emit **one worksheet per warehouse** (the Excel analog of a page), each
  with the full header + that warehouse's items + its total.
- Keep the existing `ship_to` (single) field for the manual header Ship-To block.
- If `ship_to_locations` has one entry, output a single page/sheet (current behaviour).

### Preview address resolution

At **preview time** (PO not yet created), per-line location objects come from line items that
carry `location_id` + `warehouse_name`. Resolve each full address from the already-loaded
`locations` state by `location_id`.

At **detail/export time** (existing PO), addresses come from the enriched `detail.location`.

## Out of scope

- **Purchase Request** screens (user excluded them).
- No schema/migration changes — purely enriching an existing API response + rendering.
- The stored `location_id` / delivery destinations of a PO do **not** change.

## Testing

- **Backend:** feature test asserting `transformPoDetail` output includes nested
  `location.address` (and other fields) for a multi-warehouse PO.
- **Frontend / manual:**
  - 2-warehouse PO → detail modal shows two Deliver-To blocks with distinct addresses.
  - 2-warehouse PO → preview and exported doc produce **two pages**, each self-contained with
    its own warehouse address and items; Excel produces two sheets.
  - Manual Ship-To company/name + address appear in the header of every page and are independent
    of the warehouse Deliver-To addresses.
  - 1-warehouse PO → single page, layout unchanged apart from Ship-To/Deliver-To separation.
