# Set/BOQ Selling, FIFO-Enforced Picking, and Incident Reports

**Date:** 2026-07-24
**Branch:** backupV3
**Status:** Design approved, ready for planning

## Problem

Three gaps in the current sell-out and transfer flows:

1. **No set concept.** The client sells aircons either as a *set* (unit plus a standard bill of quantity of installation materials) or as a *custom* order. Today a user must list all 18-odd material lines by hand on every sale. The client's BOQ sheet ("Standard Installation Materials for 1st 10ft Piping, Non-Inverter Type, 3TR/4HP, TCL") is a fixed, reusable list that the system does not model.

2. **Scan-first with no list.** `IssuanceScanController::initSession()` opens a session and items land on the delivery receipt as they are scanned. The customer never sees a total before picking begins, nothing constrains *what* may be scanned, and margin cannot be computed because cost is never snapshotted.

3. **FIFO is advisory only.** `TransferService::pickList()` already suggests serials and zones oldest-first, but the engine enforces quantity, not order. A picker can take newer stock from Zone 2 while older stock sits in Zone 1, and nothing records why. When stock genuinely is not on the shelf, there is no path that distinguishes theft, damage, a mis-typed receiving scan, or a simple relocation.

## Goals

- Admin-maintained **Set (BOQ) records** selectable on an issuance, matched to the aircon by brand, capacity, and variant.
- **List first, then scan.** The customer sees a priced total and confirms it before any picking starts.
- **FIFO enforced, not suggested,** at batch/zone granularity, shared identically by Issuance and Transfer dispatch.
- **Incident Reports** as the only legal way to break FIFO, routing to the existing adjustment and scan-correction machinery.
- Real margin: snapshot cost price at issue time.

## Non-Goals

- No change to the transfer request → IA verify → approve flow (consolidated 2026-07-22; see `2026-07-22-transfer-module-consolidation-design.md`).
- No FIFO enforcement on transfer *receiving*.
- No approval gate on issuance. It is a counter sale, permission-gated only.
- No change to warranty record creation, which already fires on issuance completion.
- No batch-level barcode labels on consumable bins.

## Approach

A shared **stateless `FifoPickingService`** owns the FIFO rule and the scan verdict. It holds no tables. Issuance keeps `issuances`/`issuance_details`; Transfer keeps `transfers`/`transfer_details` and its existing dispatch flow. `TransferService::pickList()` is reduced to a call into the new service.

Rejected alternatives:

- **Polymorphic pick sessions** (`pick_sessions`/`pick_lines`/`pick_scans` keyed by source document). Better end-state architecture and the right move if a third picking flow appears later, but it requires rewriting transfer dispatch — a module stabilized days ago at 435 passing tests — and abandoning issuance's write-as-you-scan model. Risk outweighs the gain now, and the chosen approach does not block a later move to it.
- **Enforcement inline in each controller.** Fastest to write and the reliable way to end up with two subtly different zone rules within a year.

## Data Model

### New tables

**`product_sets`** — BOQ header.

| Column | Type | Notes |
|---|---|---|
| `set_id` | PK | |
| `set_code` | string, unique | |
| `set_name` | string | "Std. Installation Materials — 1st 10ft Piping" |
| `description` | text, nullable | |
| `brand_id` | FK → `brand_lookup`, nullable | matching |
| `capacity_rating` | string, nullable | "3TR/4HP" |
| `variant` | string, nullable | "non-inverter" |
| `is_active` | bool, default true | |
| `created_by`, `updated_by` | FK → `users` | |
| timestamps, soft deletes | | |

All three matching fields are nullable. A set with none is selectable only from the dropdown.

**`product_set_lines`**

| Column | Type | Notes |
|---|---|---|
| `set_line_id` | PK | |
| `set_id` | FK → `product_sets`, cascade delete | |
| `product_id` | FK → `products` | |
| `quantity` | decimal(10,3) | |
| `issuable_unit_id` | FK → `product_issuable_units`, nullable | resolves "2 L", "1 roll", "20 ft" to pieces via existing `conversion_factor` |
| `spec_note` | string, nullable | "3/8 x 1/2 thck" |
| `sort_order` | int | |

The BOQ's "Tox & Metal Screw #6 — 5 pcs each" is two lines, not one.

**`issuance_sets`** — one row per set instance on an order.

| Column | Type | Notes |
|---|---|---|
| `issuance_set_id` | PK | |
| `issuance_id` | FK → `issuances`, cascade delete | |
| `set_id` | FK → `product_sets` | |
| `set_quantity` | int | how many sets |
| `set_name_snapshot` | string | history survives master-set edits |
| `was_modified` | bool, default false | set true when a line is edited or removed |
| timestamps | | |

**`pick_reservations`** — soft allocation.

| Column | Type | Notes |
|---|---|---|
| `reservation_id` | PK | |
| `source_type` | enum `issuance`/`transfer` | |
| `source_id` | int | |
| `product_id` | FK | |
| `location_id` | FK | |
| `batch_id` | FK → `inventory_batches`, nullable | |
| `serial_id` | FK → `item_serial`, nullable | |
| `quantity` | int | pieces |
| `expires_at` | datetime | |
| `released_at` | datetime, nullable | |
| timestamps | | |

Index on (`source_type`, `source_id`) and (`product_id`, `location_id`, `released_at`). Reservations decrement `inventory.available_quantity` only; `quantity_on_hand` moves when a scan commits.

**`incident_reports`**

| Column | Type | Notes |
|---|---|---|
| `incident_id` | PK | |
| `incident_number` | string, unique | `INC-YYYYMMDD-XXXXX` |
| `source_type` | enum `issuance`/`transfer` | |
| `source_id` | int | |
| `location_id` | FK | |
| `product_id` | FK | |
| `batch_id` | FK, nullable | |
| `serial_id` | FK, nullable | |
| `expected_zone` | string, nullable | what FIFO said |
| `actual_zone` | string, nullable | what the picker used |
| `reason` | enum | `not_found`, `damaged`, `wrong_type`, `zone_mismatch` |
| `quantity` | int | pieces affected |
| `narrative` | text | |
| `photo_path` | string, nullable | |
| `reported_by` | FK → `users` | |
| `status_id` | FK → `status_lookup` | open / resolved / dismissed |
| `reviewed_by` | FK → `users`, nullable | |
| `reviewed_at` | datetime, nullable | |
| `adjustment_id` | FK → `adjustments`, nullable | filled on resolution |
| `correction_id` | FK → `scan_corrections`, nullable | filled on resolution |
| timestamps | | |

### Changes to existing tables

`issuance_details` gains:

- `issuance_set_id` (FK, nullable) — null means a custom line.
- `cost_price_at_issue` decimal(12,2) — today only `unit_price` is stored, so margin is not computable after the fact.
- `batch_id` (FK, nullable) and `zone_label` (string, nullable) — every issued piece traceable to its batch.
- `quantity_requested` int and `quantity_short` int, default 0 — the quantity the customer asked for versus the shortfall accepted at quote time. `quantity_issued` remains what actually goes out. This is where the short-pick signal lives and what restock recommendations read.

`issuances` gains a **Draft** state ahead of the existing Pending (`status_id` 30) — one new `status_lookup` row. `initSession()` currently creates at Pending and scanning begins immediately; Draft becomes where the list is built and priced, and confirming the quote flips it to Pending, which is what unlocks the scanner.

No changes to `inventory_batches` or `item_serial`. `zone_label` and the `item_serial.receiving_id → inventory_batches.receiving_id` join already exist and are what `pickList()` uses today.

## Flows

### Issuance: Draft → Quote → Pick → Complete

**1. Draft.** Customer, issuance type, discount. Order created at Draft. Nothing reserved.

**2. Build the list.** Three add-paths into one list:

- *Add appliance* — pick the aircon; the system reads its `appliance_details.brand_id`, `capacity_rating`, `variant` and offers matching sets as a one-tap suggestion.
- *Add set directly* — dropdown of all active sets.
- *Add custom item* — any product, any issuable unit.

Set lines expand inline, grouped under the set with its `set_quantity`. Editing or removing a line inside a set sets `was_modified` on that `issuance_sets` row; the order still records what the standard BOQ should have contained. One order may carry several sets plus loose custom lines.

**3. Quote.** Each line shows quantity, unit price, subtotal, and an availability badge computed against `inventory.available_quantity` at that location. Short lines show a red badge with the available number; the user may reduce or drop the line, and the shortfall is recorded on the order for restock signal. Totals show subtotal, discount, grand total. Cost and margin render only for holders of the `issuances` `view_margin` permission, so counter staff do not display cost while the customer reads the screen.

**4. Confirm Quote.** Server re-validates prices and availability — client-supplied totals are never trusted — writes `pick_reservations`, snapshots `cost_price_at_issue`, moves the order to Pending, and freezes the line list.

**5. Pick.** Guided, one step at a time: *"Line 3 of 18 — Copper Tube SD 3/8, need 10ft. Take from Zone 1, batch received Mar 3."* Scan → `validateScan()` → commit or block. Consumables require the picker to confirm the zone shown; confirming a different zone is what raises an incident.

**6. Complete.** Unchanged: DR generated, `WarrantyRecord` rows created for warranty-bearing lines (`IssuanceScanController.php:602`), inventory committed, reservations released.

### Transfer

Request → IA verify → approve are untouched. At **dispatch**, `TransferService::pickList()` becomes a thin call into `FifoPickingService::suggest()`, and the dispatch scan endpoint runs the same `validateScan()` with the same block rules and the same incident path. Receiving is untouched.

### Endpoints

```
GET/POST/PUT/DELETE /api/product-sets            set CRUD (admin)
GET  /api/product-sets/suggest?product_id=       brand+capacity+variant match
POST /api/issuances/{id}/lines/set               add a set × qty
POST /api/issuances/{id}/lines/custom            add a loose product
PUT/DELETE /api/issuances/{id}/lines/{lineId}
GET  /api/issuances/{id}/quote                   priced list + availability
POST /api/issuances/{id}/confirm-quote           reserve + Draft→Pending
GET  /api/issuances/{id}/pick-plan               FIFO steps (shared service)
POST /api/issuance-scan/scan                     existing, now validated
GET  /api/transfers/{id}/pick-list               existing, now from shared service
POST /api/incident-reports                       file (picker)
GET  /api/incident-reports                       list/triage
POST /api/incident-reports/{id}/resolve          IA/Admin → adjustment or correction
```

## The Enforcement Engine

`App\Services\FifoPickingService`, stateless, two public methods.

**`suggest(int $locationId, array $lines): array`** — per line, ordered steps of shape `{product_id, quantity, batch_id, zone_label, received_at, serials[]}`. Appliances resolve serials through the `item_serial.receiving_id → inventory_batches` join ordered by `received_at ASC`; consumables walk batches by `received_at ASC` taking from `quantity_remaining`. This is `pickList()`'s logic lifted out and made document-agnostic.

**`validateScan(int $locationId, array $lines, string $barcode, ?string $confirmedZone, ?string $serial): ScanResult`**

| Result | Cause | Behavior |
|---|---|---|
| `ACCEPTED` | correct product, oldest available batch/zone | commit the piece |
| `UNKNOWN_BARCODE` | resolves to no product | hard block |
| `WRONG_PRODUCT` | product is not on this order's list | hard block, **no override** — an incident cannot make a wrong item correct |
| `OVER_PICK` | line already fully picked | hard block |
| `FIFO_VIOLATION` | correct product, but older non-excluded stock exists | soft block — offer "File Incident Report" to proceed |

The violation test is *"does any older, non-excluded batch of this product still hold stock at this location"* — **not** a comparison against a previously issued suggestion. Stock moves while a picker walks the aisle, and a stale snapshot would fire false violations. The rule is recomputed on every scan.

Granularity is **batch/zone**, not exact serial: any serial belonging to the correct oldest batch is accepted.

## Incident Reports

**Filing.** Picker supplies reason, quantity, narrative, optional photo. On file, the affected batch or serial is excluded from FIFO, the plan recomputes to the next-oldest batch, and picking continues immediately. No approval wait — the customer is at the counter.

**Exclusion is derived** from open incident reports rather than a flag column on `inventory_batches`, so resolving or dismissing an incident returns the stock to rotation with no cleanup step.

**Resolution** — Incident Reports page, `incident_reports.resolve` permission (IA/Admin):

| Reason | Resolution effect |
|---|---|
| `not_found` | After physical search: post a negative-variance `Adjustment` (write-off), or dismiss, which re-includes the stock. |
| `damaged` | `Adjustment` moving the quantity out of sellable stock; exclusion becomes permanent. |
| `wrong_type` | Create a `ScanCorrection` against the original receiving, re-typing the batch to the product actually on the shelf. Reuses `scan_corrections` exactly as it works today for receiving mis-scans. |
| `zone_mismatch` | Update `inventory_batches.zone_label`. No quantity change, no adjustment. |

The triage list shows an **incidents-per-picker count** beside each report. One picker filing `not_found` repeatedly on high-value stock is the signal the client is actually worried about, and it is invisible unless the system counts it.

Issuance scan and transfer dispatch scan call the same `validateScan()` and post to the same `/api/incident-reports`. One FIFO rule in the codebase, not two.

## UI

- **`SetManagementPage.jsx`** (new, admin) — set list with brand/capacity/variant chips and computed set price. Builder modal: header fields plus a line editor of product + quantity + issuable unit + spec note, drag to reorder. Duplicate-set action, since inverter and non-inverter variants of the same capacity differ by a few lines. Built on `ak-*` primitives from `airking_ds.css`.
- **`IssuanceScanPage.jsx`** (rework) — three-step wizard: **Order** (customer + build list) → **Quote** (priced review, availability badges, confirm gate) → **Pick** (guided scanner, large zone callout, progress bar). Step 3 is unreachable until the quote is confirmed, enforced server-side, not by hiding a tab.
- **`IncidentReportsPage.jsx`** (new) — triage list filtered by reason/status/location, per-picker incident count, resolve drawer whose actions vary by reason.
- **Transfer dispatch panel** (`admin/pages/transfers/`) — existing pick list gains the same block states and the same "File Incident Report" button, via a React component shared with the issuance picker; both render the identical `ScanResult` shape.

## Permissions

New keys seeded through the existing nav-bundle mechanism:

- `product_sets` — create / read / update / delete
- `incident_reports` — create (pickers), read, resolve (IA/Admin)
- `issuances` gains a `view_margin` action — hides cost and margin from counter staff

Keys follow the existing module-plus-action form used by `permission:inventory_transfers,create`.

Admin bypasses via `PermissionResolver` as usual. Two new permission-gated sidebar entries, Set Management and Incident Reports, added to `NavBundleSeeder`.

## Testing

PHPUnit feature tests against `airkingV5_test` with `DatabaseTransactions`, matching existing convention.

- `FifoPickingService` unit tests per `ScanResult` branch, including "an older batch appeared mid-pick".
- `WRONG_PRODUCT` is blocked and cannot be overridden by an incident.
- Scanner rejects a Draft order — the confirm-quote gate is real.
- Each of the four incident reasons produces its correct downstream artifact: adjustment, correction, zone update, or nothing.
- Reservations decrement `available_quantity`, release on cancel and on expiry, and two concurrent orders cannot reserve the same batch quantity.
- Transfer dispatch and issuance pick return the same verdict for the same violation.
- Set expansion: a set × 3 produces the right piece quantities through `issuable_unit_id` conversion.
- Margin: `cost_price_at_issue` is snapshotted at confirm and survives a later change to `products.cost_price`.

Frontend has no automated signal in this repo; verify with `npm run build` plus an `npm run demo` walkthrough.

## Migrations

One migration per new table, plus one for the `issuance_details`/`issuances` column additions, plus the `status_lookup` row for Draft and the incident statuses. Applied with `--path` and `--force` (local `APP_ENV=production`) to dev `airkingV5` and test `airkingV5_test`. Schema in this repo is a SQL dump rather than a full migration history, so a blanket `php artisan migrate` fails; single-path application is the established convention.

## Phasing

1. Sets CRUD, model, and Set Management page.
2. Draft state, quote endpoint, reservations, confirm gate.
3. `FifoPickingService` plus issuance scan enforcement.
4. Incident reports end-to-end, including resolution artifacts.
5. Transfer dispatch cutover onto the shared service.
6. Permissions, nav bundles, polish.

Each phase leaves the system in a working state.

## Related

- `docs/superpowers/specs/2026-07-22-transfer-module-consolidation-design.md`
- `docs/superpowers/specs/2026-07-20-po-location-batched-inventory-fifo-design.md`
- `docs/superpowers/specs/2026-07-21-warehouse-zone-map-design.md`
