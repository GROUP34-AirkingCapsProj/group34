# Warehouse Zone Map: Physical Batch Locations + Visual FIFO Map

**Date:** 2026-07-21
**Branch:** worktree-po-batch-fifo-inventory (follow-up to the PO/location-batched inventory FIFO feature)
**Status:** Approved design

## Problem

The client is reorganizing their physical warehouse: incoming stock will be grouped and
shelved by which PO/batch it came from, each batch given its own physical spot (a
shelf/aisle/zone). They want this reflected in the software two ways:

1. **Recording where a batch physically lives** — analogous to how, in an enrollment
   system, a new batch of students gets its own section created for it. Each PO batch
   received should similarly get its own "spot" recorded, rather than just existing as
   an abstract database row.
2. **Seeing it visually** — a warehouse map view showing, at a glance, which batches
   are where, how much of each is left, and which one staff should pull from first
   (FIFO).

## Relationship to existing work

This builds directly on the just-completed PO/location-batched inventory + FIFO feature
(see `docs/superpowers/specs/2026-07-20-po-location-batched-inventory-fifo-design.md`
and its implementation plan). That work already created `inventory_batches` — one row
per receiving line, per product+location, with FIFO ordering by `received_at`. A batch
already **is** the "section" in the enrollment analogy: it's created once, per incoming
delivery, per product. This feature does not need a new entity — it adds one descriptive
field to the batch, plus a new way to visualize batches.

## Data model

Add one nullable column to the existing `inventory_batches` table:

| Column | Notes |
|---|---|
| `zone_label` | nullable string, e.g. `"Aisle 3, Shelf B"`. Free text, staff-entered. |

No new table. A `warehouse_zones` table was considered and rejected: that would make
sense if zones were reusable containers assigned to batches after the fact, but the
client's own analogy — a new section is created *for* each batch, not chosen from an
existing list — means batch and zone are the same lifecycle event. Adding a second
table would just be an unnecessary join.

## Receiving flow changes

Each product line in the receiving form gets a new optional text input for its
`zone_label` (e.g. "Aisle 3, Shelf B"), entered per line — not once for the whole
receiving — since different products in the same delivery commonly go to different
shelves. This value is saved onto that line's `InventoryBatch` row at creation
alongside the existing `quantity_received`/`received_at` fields. If left blank, the
batch is simply unlabeled (`zone_label = null`) and displays as "Unassigned" wherever
shown, until edited.

## Editing a zone label after the fact

A batch's `zone_label` can be changed later — via a new `PATCH
/api/inventory-batches/{batch}` endpoint accepting `{ zone_label: string|null }` — from
either the new Warehouse Map card or the existing batch drill-down on the Inventory
tab. This covers staff who didn't label a batch at receiving time, or who physically
moved stock afterward. Editing `zone_label` never touches `quantity_remaining` or any
FIFO deduction/restoration logic — it is purely descriptive metadata layered on top of
the existing batch record.

## Warehouse Map UI

A new **"Warehouse Map"** tab on the existing Inventory Management page, alongside the
current "Inventory" and "Activity Log" tabs — not a separate page/nav entry, so it
inherits the page's existing location filter and layout conventions.

The tab shows a card per batch, filtered to `quantity_remaining > 0` (depleted batches
disappear from the map entirely — this is a working-stock view, not a historical
ledger). Each card shows:
- Product name
- PO number (or "Pre-tracking stock" for the legacy catch-all batch, per existing
  convention)
- Zone label (or "Unassigned")
- Quantity remaining
- Received date

For each product, whichever visible batch is oldest (by `received_at`) gets a
**"Pick this first (FIFO)"** badge. This reuses the exact oldest-batch-lookup logic
already built for the issuance-scan FIFO warning (`fifoWarningFor()`'s "find the oldest
batch with quantity_remaining > 0" query) — no new FIFO logic, just a second place that
surfaces the same signal visually instead of as a scan-time warning.

## Testing

- Backend: migration application (dev + test DB), `zone_label` persists through
  receiving, `PATCH` endpoint updates only `zone_label` and leaves quantity fields
  untouched, the batches-listing endpoints include `zone_label` in their response
  shape.
- Frontend: manual verification (per this codebase's established convention for UI
  work) — receiving form's new field, Warehouse Map tab rendering, FIFO badge
  appearing on the correct (oldest) batch per product, "Unassigned" fallback display.

## Out of scope

- A literal visual floor-plan/diagram of the warehouse (card/list view was chosen
  instead — simpler, no need to model real warehouse geometry).
- Predefined/reusable zone entities set up ahead of time by an admin (zones are
  created per batch, matching the enrollment-section analogy).
- Any change to FIFO deduction, restoration, or the legacy-batch fallback — this
  feature only adds descriptive location metadata and a new visualization on top of
  the existing engine.
