# Inventory Batch Console — Refinements

**Date:** 2026-07-22
**Branch:** backupV3
**Status:** Approved design, pending implementation plan

## Problem

The PO-centric console ("Inventory Monitoring Console") needs several refinements: clearer
naming, a real search field, grouping by warehouse/showroom for users who see more than one,
and a bug where a Zone entered at receiving shows as unslotted in the console.

## Scope (5 items)

### A. Rename the console to "Inventory Batch"
- The **sub-tab label** and the **panel heading** change from "Inventory Monitoring Console"
  to **"Inventory Batch"**. No id/route changes (`po-console` stays the internal id).

### B. Rename "Location" → "Zone" in the console UI
The batch `zone_label` is surfaced to users as **Zone** (not Location), everywhere in the PO
card and PO View modal:
- Card bin-tag eyebrow: "Location" → **"Zone"**.
- Empty state: "Unslotted" → **"Unzoned"**.
- Inline edit control (tooltip/placeholder) reads Zone (e.g. placeholder "e.g. Zone A").
- PO View modal Overview field label: "Location" → **"Zone"**.
- "Warehouse" stays "Warehouse" (that is the `location`/`location_id`, a distinct concept).
- UI text only — the DB column stays `zone_label`, endpoints unchanged.

### C. Redesign the search bar
Replace the plain `inv-search` input with the app's established search-field pattern used on
the Stock Movement / Activity tabs — the `sm-search` component: a rounded field with an inline
magnifier icon. Same behavior (client-side filter on PO number / supplier / warehouse / zone),
better affordance and consistency.

### D. Group cards by warehouse / showroom
- The console groups its slot cards under a **location section header**, one section per
  warehouse or showroom, cards beneath.
- Header shows: the **location name**, a **type badge** (Warehouse / Showroom), and **running
  totals** for that location — number of deliveries (groups), total items, and total
  `remaining / received` quantity.
- Sections ordered by location name (A→Z).
- The **"Pick first"** FIFO flag becomes **per-location** (the oldest group within each
  location), since picking happens within a warehouse.
- **Scoping is unchanged** — `InventoryBatch::forUser()` already restricts a scoped
  (non-centralized) user to their own `location_id`, so such a user sees a single section;
  centralized/admin users see one section per location. This item is purely the visual
  grouping.
- **Backend:** the list endpoint (`GET /api/po-inventory-groups`) adds `warehouse_type`
  (from the batch's `location.location_type`) to each group, so the frontend can render the
  type badge. Grouping/sectioning is done client-side from the returned groups.

### E. Fix the Zone-at-receiving bug
- **Symptom:** a Zone entered on the barcode-scan "Receive PO" screen shows as "Unslotted"
  (soon "Unzoned") in the console.
- **Findings so far:** the full backend chain is correctly wired — the receive screen sends
  `zone_label` via `scanTransaction`, `InventoryScanTransactionController::store` validates it,
  and `InventoryBatchService::createBatch()` (and the accumulation branch) persist it to
  `inventory_batches.zone_label` (which is `fillable`). So the defect is not visible by static
  inspection; it must be reproduced live.
- **Approach:**
  1. Add a backend feature test that drives the PO receiving path used by the scan screen
     (`POST /api/inventory/scan-transaction` with `po_id`, `po_detail_id`, `zone_label`) and
     asserts the resulting `inventory_batches` row carries the Zone and that
     `GET /api/po-inventory-groups` surfaces it in `location_label`.
  2. If that test **fails**, fix the backend so the Zone persists (the test locks it).
  3. If it **passes**, the defect is in the receive UI/flow — reproduce in the browser
     (using systematic-debugging), find where the entered Zone is dropped (e.g. state not set
     at scan time, wrong receive mode, or the field not bound on the path actually used), fix
     it, and confirm the Zone reaches the console.

## Out of scope

- No change to receiving/issuance/transfer business logic beyond fixing the Zone persistence
  if the bug is server-side.
- No change to location scoping (already enforced).
- No change to the Stock-Level Monitoring tab.

## Testing

- **Backend:** the new receiving-zone test (item E). Extend `PoInventoryGroupsTest` (or a new
  test file) to also assert `warehouse_type` is present on listed groups (item D).
- **Frontend:** eslint clean; manual check — console reads "Inventory Batch"; cards say Zone /
  Unzoned; the search field shows the icon and filters; cards are grouped under warehouse /
  showroom headers with correct totals and a per-section "Pick first" flag; a freshly received
  PO with a Zone shows that Zone (not Unzoned).
