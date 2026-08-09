# Incident Bulk Quantity — Design

**Date:** 2026-08-09
**Branch:** backupV3
**Status:** Approved, ready for planning
**Builds on:** `2026-08-03-standalone-incident-reporting-design.md`, `2026-08-09-incident-scan-lookup-and-merge-design.md`

## Problem

Reporting an incident for a consumable under-deducts stock.

`WriteOffResolution:23` reads `(int) $incident->quantity` and decrements `inventory_batches.quantity_remaining` and `inventory.quantity_on_hand` by it. Both are stored in **pieces**. The report form offers a single "Quantity affected" box with no unit, so a user reporting one damaged box of a 10-per-box consumable types `1` — and the system writes off **1 piece**, leaving inventory 9 pieces above reality.

The error is silent: the incident resolves successfully, the adjustment posts, and the stock figure is simply wrong afterwards. It compounds with every such report.

## Root cause, and what is NOT wrong

`packageFormat.js` states the system-wide invariant in its header:

> *Inventory, transfers, dispatch and receive all work in PIECES; these helpers only re-express a piece total for display/entry — no rounding of the stored value.*

So `incident_reports.quantity` holding **pieces is correct** and consistent with everything else. Nothing in the database or the resolution strategies is wrong. The defect is entirely at **entry**: the form never gave the user a way to say "box", and never showed which unit it wanted.

This is why the fix is small — it is a form and display change, not a schema or accounting change.

## Goals

1. Enter a quantity as packs + loose pieces, exactly as issue-out and transfers already do.
2. Show the breakdown everywhere a quantity is read, especially where a write-off is approved.
3. Change nothing about what `quantity` means or how resolutions deduct.

## Non-goals

- Backfilling or correcting existing incidents (see Decisions).
- Changing `WriteOffResolution`, `RestockResolution`, or any other strategy — they already operate correctly on pieces.
- Adding pack **entry** to the picking modal.
- Any migration. No schema change is required.

## Decisions

| Decision | Choice |
|---|---|
| What `quantity` stores | **Pieces**, unchanged |
| Existing rows | **Left alone** — no backfill |
| Entry | Report form only |
| Display | Report form, incident list, resolve drawer, write-off confirmation, picking modal |
| Picking modal entry | Unchanged — its quantity comes from the FIFO pick plan |

**Why no backfill.** Nobody can know whether a past `1` meant one piece or one box. Reinterpreting history on that guess would silently rewrite stock levels and could be wrong in either direction. A known-imperfect record beats a confidently-wrong one; affected stock is corrected with a normal Adjustment.

**Why not pack entry in the picking modal.** Its quantity arrives from the FIFO pick plan, already in pieces and already correct. Letting a picker retype it in packs introduces a second chance to get it wrong for no gain.

## Entry — `IncidentFileModal`

When the selected product has `pieces_per_package > 1`, the single quantity input becomes two, mirroring `OrderBuilder`:

```
Quantity affected *
[ 2 ] Box   +   [ 5 ] pcs        = 25 pcs
```

The running total is shown beside the inputs so the filer sees exactly what will be reported.

Products **without** a pack size — appliances, and any consumable with `pieces_per_package` of 0 or 1 — keep the current single pieces input, unchanged.

Conversion happens once, at submit, using the formula already established in `OrderBuilder`:

```js
const q = piecesPerPackage > 1
  ? (parseInt(packages, 10) || 0) * piecesPerPackage + (parseInt(pieces, 10) || 0)
  : Math.max(1, parseInt(qty, 10) || 1);
```

The payload still sends `quantity` in pieces. The backend is unchanged.

### Where the pack size comes from

Verified against the live database:

- **`pieces_per_package`** is an appended accessor on `Product` (`getPiecesPerPackageAttribute`, delegating to `installation_material_details`) and is in `$appends`, so it is present on **every** product payload — including the list the modal already loads. Available on all three identification paths (scan, type, pick) with no extra request.
- **`package_unit`** is not appended, but `ProductController::index` eager-loads `consumableDetail` with its unit, so it is reachable in the products list as `consumable_detail.package_unit`. **No model change is required.**
- The **lookup endpoint** hand-builds its `product` object, so it must add both fields explicitly or a scanned product would lose them.

When `package_unit` is absent, the label falls back to `pkg` rather than displaying a wrong unit.

## Display

Using the existing `formatPieces` and `packageBreakdown` helpers, so incidents read the same as transfers:

| Surface | Shows |
|---|---|
| Report form | Running total beside the inputs |
| Incident list, quantity column | `25 pcs · 2 Box + 5 pcs` |
| Resolve drawer, detail grid | same |
| **Write-off confirmation dialog** | *"writes off 25 pcs (2 Box + 5 pcs) of …"* |
| Restock confirmation dialog | same treatment |
| Picking modal | breakdown shown beside its quantity; entry unchanged |

The confirmation dialogs matter most. A reviewer approving a permanent stock write-off must see the pack breakdown — otherwise the exact ambiguity that caused this bug simply moves to the approval step, where it is more expensive.

## Error handling

| Case | Behaviour |
|---|---|
| Both inputs empty or zero | Submit blocked, as today — the existing `quantity >= 1` guard operates on the computed total |
| Loose pieces ≥ pack size (e.g. 15 pcs on a 10-per-box) | Accepted, total computed normally. Not an error: a user may legitimately think in loose pieces, and the total is what matters |
| Product changed after entry | Recompute the total against the new product's pack size; if the new product has no pack size, collapse to the single input carrying the previous total |
| `pieces_per_package` missing or ≤ 1 | Single pieces input, current behaviour |
| `package_unit` missing | Label falls back to `pkg` |

## Testing

**Backend** — the lookup endpoint returns `pieces_per_package` and `package_unit` for a consumable that has them, and sensible values for one that does not.

**Frontend** — no test suite. The decisive manual check, which reproduces the original bug:

> File an incident for a **10-per-box** consumable entering **1 Box**. Resolve it. Confirm inventory drops by **10 pieces**, not 1.

Plus: an appliance still shows a single pieces input; the write-off confirmation names the breakdown; the incident list shows it.

## Related

- Touches `IncidentFileModal.jsx` (entry + display), `IncidentReportsPage.jsx` (list column), `IncidentResolveDrawer.jsx` (detail + confirmations), `picking/IncidentModal.jsx` (display only), `IncidentReportController::lookup` (two fields).
- Does **not** touch `IncidentService`, `App\Services\Incidents\*`, any migration, or `packageFormat.js` itself.

## Environment notes for the implementer

- Schema is a **SQL dump, not a migration history**. This feature needs **no migration**.
- Tests use `DatabaseTransactions`, never `RefreshDatabase`. `phpunit.xml` points at `airkingV5_test`.
- The backend suite has **10 known pre-existing failures** (9 `WarrantyClaimTest`, 1 `SerialRowMovementColumnsTest`), unrelated. Green means still exactly those 10.
- No frontend test suite; verification is `npm run build` plus `npx eslint`, warning-clean relative to the known baseline.
- ~20 files are dirty in the working tree from unrelated earlier work, including `airkingv5.sql`. Stage only what each task names.
- PowerShell here-strings break `git commit -m`; use `git commit -F -`.
- Live reference data: *Copper Tube 1/4 CT (Roll)* is 10 per `Box`.
