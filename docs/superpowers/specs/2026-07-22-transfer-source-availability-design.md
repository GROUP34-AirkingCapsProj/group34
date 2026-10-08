# Transfer Source Availability at Verify

**Date:** 2026-07-22
**Branch:** backupV3
**Status:** Approved design, pending implementation plan
**Related:** `2026-07-22-transfer-module-consolidation-design.md` (the IA verify/assign step this enhances)

## Problem

When the Inventory Analyst verifies a transfer request and assigns a **source warehouse**, they
currently pick the warehouse **blind**. The transfer `show` endpoint computes per-line
`available_at_source`/`shortfall`, but only from the source **already saved** on the transfer:

- For a **pull** request the source is null at open, so every line shows `avail 0` until a source
  is assigned *and the record reloaded*.
- Changing the source dropdown in the Verify panel does **not** update the numbers — the IA finds
  out whether the chosen warehouse had stock only *after* approving.

The IA needs to see, **while choosing the source**, the real on-hand quantities at the candidate
warehouse for each requested product, and be warned when it can't fully fulfill.

## Goal

In the IA Verify panel, as the source warehouse is selected, show each requested product's actual
stock at that warehouse live (no save, no reload) and flag shortfalls — so the analyst assigns a
warehouse that can actually fulfill the request.

## Non-goals

- No multi-warehouse comparison matrix (a separate, larger idea — explicitly deferred).
- No change to the requester-side create flow.
- No hard block on shortfalls — partial dispatch is allowed and the remainder auto-closes, so a
  shortfall is a **warning**, not an error.
- No auto-capping of approved quantities to availability — the analyst decides.

## Decisions (confirmed)

- Headline number is **`available_quantity`** (what dispatch actually checks), not raw
  `quantity_on_hand`. `quantity_on_hand` is returned too, for reference.
- Shortfall is **advisory** (soft warning).
- Source candidates are **warehouses only** (stock originates from warehouses; the Verify source
  select already filters to `location_type = 'warehouse'`).

## Approach

**One transfer-scoped availability endpoint, fetched once when the Verify panel opens.** The panel
caches the map and re-reads it client-side on every source-dropdown change — no per-click requests,
no writes until *Approve & Assign*.

Rejected alternatives:
- Per-selection calls to the existing `GET /inventory/location/{id}` — chatty and returns large
  amounts of unrelated inventory that must be filtered client-side.
- Baking availability into the transfer `show` payload — bloats every open and recomputes even when
  the opener isn't verifying.

## Backend

**New endpoint:** `GET /api/transfers/{id}/source-availability`

- Route gated `permission:inventory_transfers,read`, alongside the existing transfer read routes.
- Controller method `TransferController::sourceAvailability($id)`:
  1. Load the transfer; collect its requested `product_id`s (`transfer.details`).
  2. Query `inventory` joined to `locations` for those products, restricted to
     `locations.location_type = 'warehouse'` (and active locations).
  3. Return a nested map keyed by location then product:
     ```json
     {
       "success": true,
       "data": {
         "3":  { "12": { "available": 8, "on_hand": 10 }, "15": { "available": 0, "on_hand": 0 } },
         "7":  { "12": { "available": 25, "on_hand": 25 } }
       }
     }
     ```
     Outer key = `location_id`, inner key = `product_id`. A product absent at a warehouse simply has
     no inner entry (the frontend treats missing as 0).
- `Inventory` model maps the `inventory` table with `product_id`, `location_id`, `available_quantity`,
  `quantity_on_hand` (already used by `TransferService`).

## Frontend

**File:** `frontend/src/admin/pages/transfers/VerifyPanel.jsx`

- On mount (panel open), call `transfersAPI.sourceAvailability(transfer.transfer_id)` and store the
  map in state (`availByLoc`). Show a small loading state while it resolves; failure degrades
  gracefully to "availability unavailable" and does not block verifying.
- Add `transfersAPI.sourceAvailability(id)` → `GET /transfers/${id}/source-availability` in
  `services/api.js`.
- Per requested line, when a source `fromLocationId` is selected, compute:
  - `available = availByLoc[fromLocationId]?.[product_id]?.available ?? 0`
  - `short = max(0, quantity_approved - available)` (uses the analyst's current approved qty, not
    just the original requested qty, so trimming a line updates the shortfall too).
  - Render `avail N` in green when `available >= approved`, else `short M` in red.
- Under the source dropdown, a one-line summary: *"<Warehouse> can fulfill X of Y products"* and a
  soft warning banner when any surviving line is short (informational; the Approve button stays
  enabled).
- No availability shown until a source is selected (prompt: "Select a source to see stock").

**Data flow:** mount → fetch map once → dropdown `onChange` sets `fromLocationId` → render re-reads
the cached map for that location → per-line avail/short recomputed. No writes until *Approve &
Assign* (unchanged).

## Testing

- **Backend feature test** (`airkingV5_test`, `DatabaseTransactions`, extend `TransferTestCase`):
  - Seed one product with `available_quantity` 8 at warehouse A and 0 at warehouse B; a second
    product with stock only at A. Create a transfer requesting both products.
  - `GET /api/transfers/{id}/source-availability` as an IA returns A's and B's maps with the correct
    `available`/`on_hand`; the zero-stock warehouse reports 0 (or omits the product); a non-warehouse
    location does not appear.
  - Permission: a user without `inventory_transfers.read` is rejected.
- **Frontend:** selecting warehouse A shows `avail 8`; switching to B flips the same line to
  `short` in red; the summary line updates; the Approve button remains enabled through a shortfall.

## Out of scope

- Multi-warehouse comparison view.
- Requester-side availability.
- Reserving/locking stock at verify time (availability is a point-in-time read; the dispatch gate
  remains the source of truth at ship time).
