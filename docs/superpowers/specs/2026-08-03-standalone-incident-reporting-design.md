# Standalone Incident Reporting — Design

**Date:** 2026-08-03
**Branch:** backupV3
**Status:** Approved, ready for planning

## Problem

The incident report module is not a reporting feature. It is a FIFO override valve.

The only way an incident can be created today is through `IncidentModal` inside `PickScanner`, and that modal appears only when a scan returns `FIFO_VIOLATION` (`ScanResult::overridable()`). No scan violation means no way to file anything. The API enforces the same limit: `source_type` is validated as `Rule::in(['issuance', 'transfer'])` and `source_id`, `location_id`, `product_id`, `quantity`, and `narrative` are all required, so an incident cannot exist apart from a live pick. `IncidentReportsPage.jsx` is a triage table with filters and a review drawer, and no button to report anything.

Incidents are discovered in ways that have nothing to do with picking:

- Stock is missing, or was stolen yesterday.
- A product was damaged in storage, in handling, or on the way to a customer.
- The wrong item was delivered and the customer wants to return it.
- Something else went wrong that still needs a record and a reviewer.

Neither adjacent module covers these. `WarrantyClaim` / `WarrantyClaimReturn` handles a customer returning a *defective* unit. `PurchaseReturn` sends stock back to a *supplier*. Nothing handles theft, in-transit damage, or a wrong-item return.

## Goals

1. A **Report Incident** button that files an incident with no pick in progress.
2. Four new reason types covering shrinkage, damage, customer returns, and everything else.
3. Customer returns put stock **back**, which no existing resolution does.
4. Evidence photos, which the schema anticipates but nothing writes.
5. Do all of it without disturbing FIFO enforcement, which is safety-critical and works.

## Non-goals

- Re-issuing the correct item after a wrong-item return. Staff run a normal issuance.
- Quarantining or inspecting returned stock. It comes back available; if it arrives damaged, that is a second incident with reason `damaged`.
- Replacing `WarrantyClaim` or `PurchaseReturn`.
- Any change to `FifoPickingService`, `ScanResult`, or scan-time enforcement.

## Decisions

| Decision | Choice |
|---|---|
| Scope | All four categories: shrinkage, damage, customer return, free-form other |
| Return depth | Resolution posts an addition Adjustment; stock returns to its **original batch** |
| Form strictness | Location + narrative always; product + quantity only when the reason affects stock |
| Who files | Add `branch_manager` to `incident_reports.create`; `auditor` stays read-only |
| FIFO effect | Unchanged rule: **any** open incident naming a batch holds that batch out of picking |
| Reason taxonomy | Flat list grows 4 → 8; no category column |
| Photos | Optional upload on every reason; never required |
| Return linkage | Optional searchable issuance picker |
| Architecture | Extend in place, and split resolution into per-reason strategy classes |

### Why strategies rather than a longer switch

`IncidentService` is roughly 260 lines with a `switch` in `resolve()`. Doubling the reason count and adding an outcome that moves stock *in* for the first time is exactly when that switch should become a dispatch table. Each strategy is small enough to read at once and to test on its own, and adding a ninth reason later becomes a new file rather than a longer method.

The extraction is low-risk because it is mechanical — the three existing private methods become three classes with the same bodies — and because `IncidentResolutionTest` already pins the current behaviour. If the refactor breaks anything, those tests say so immediately.

### Why `related_issuance_id` is not `source_id`

`source_type` / `source_id` mean *"this was discovered while picking transaction X."* A return link means *"this incident is about sale Y."* Overloading one column to carry both would make "filed while picking issuance 12" permanently indistinguishable from "filed about issuance 12", to save one column.

## Data model

One migration: `2026_08_03_000001_extend_incident_reports_for_standalone.php`.

| Change | Rationale |
|---|---|
| `source_id` → nullable | A standalone report has no source transaction. Plain polymorphic int, no FK, so `->change()` is clean. |
| `product_id` → nullable | An `other` incident has no product. Has an FK: drop foreign → change → re-add foreign, in that order. |
| **+ `occurred_at`** datetime nullable | `created_at` is when the incident was *filed*. For a theft noticed the next day these differ, and shrinkage reporting needs the real date. |
| **+ `related_issuance_id`** nullable FK → `issuances` | The customer-return link. `nullOnDelete`, matching the table's other optional FKs. |

`source_type` gains a third value, `'standalone'`. `reason` is `string(30)`; all eight values fit.

`quantity` stays `NOT NULL DEFAULT 0` and is **not** made nullable. An `other` incident simply omits it and stores `0`. Making it nullable would buy a distinction between "zero units affected" and "quantity is not meaningful here" that nothing consumes, at the cost of a second nullable-FK-free column change and a null check at every read site. `product_id` genuinely needs null because a foreign key cannot be "not applicable" any other way.

Reasons grow from four to eight:

```
not_found   damaged   wrong_type   zone_mismatch     ← existing, semantics untouched
theft   delivery_damage   customer_return   other    ← new
```

`theft` is deliberately separate from `not_found`: "I could not find it" and "it was stolen" are the distinction that matters for shrinkage reporting, and they should read differently to a reviewer.

**No backfill.** Every existing row keeps its reason, its non-null `product_id`, and its `issuance`/`transfer` source type. `occurred_at` is null on historic rows; the UI falls back to `created_at` when it is.

## Backend architecture

New namespace `App\Services\Incidents\`:

```php
interface ResolvesIncident {
    /** Pre-flight guards that don't touch the DB. Throws InvalidArgumentException. */
    public function validate(IncidentReport $incident, array $options): void;
    /** Post the artifact. Returns ['adjustment_id' => ?int, 'correction_id' => ?int]. */
    public function apply(IncidentReport $incident, array $options, int $reviewedBy): array;
}
```

| Strategy | Reasons | Artifact |
|---|---|---|
| `WriteOffResolution` | `not_found`, `theft`, `damaged`, `delivery_damage` | Deduction `Adjustment`. Body is today's `postDeduction` verbatim, including the clamped `deducted = min(requested, available)` that code review introduced. `adjustment_type` maps `not_found`/`theft` → `loss`, `damaged`/`delivery_damage` → `damage`. |
| `RetypeResolution` | `wrong_type` | `ScanCorrection` + batch re-type. Today's `postCorrection`, and it takes ownership of the two `wrong_type` guards currently at the top of `resolve()`. |
| `RelocateResolution` | `zone_mismatch` | `zone_label` update, no quantity movement. Today's `relocate`. |
| `RestockResolution` | `customer_return` | **New.** Addition `Adjustment`. |
| `AcknowledgeResolution` | `other` | None. Closes the incident as Resolved with no artifact. |

The API surface does not change: the drawer's **Acknowledge** button for `other` sends the existing `action: 'confirm'`, and the label differs only because "Confirm" reads wrong when nothing is being posted. `dismiss` keeps its current meaning for every reason — closed as Dismissed, no artifact, batch returns to FIFO — and still short-circuits before any strategy runs.

`IncidentService::resolve()` shrinks to roughly 40 lines and retains only what must never be duplicated: the `DB::transaction`, the `lockForUpdate` plus re-check-Open (the concurrency fix from code review), the dismiss short-circuit, strategy dispatch via `match` on reason, and the status stamping. Per-reason guards move into `validate()`, so `resolve()` stops knowing that `wrong_type` is special.

### RestockResolution

The first resolution that adds stock. Inside the transaction `IncidentService` already holds:

1. Create an `Adjustment` with `adjustment_type = 'return'`, `total_variance_positive = quantity`, status `20` (Approved), notes citing the incident number; and one detail row with `add_quantity = quantity`.
2. `Inventory::firstOrCreate` for the location/product, `lockForUpdate`, then increment `quantity_on_hand` **and** `available_quantity` by the same amount — mirroring how `postCorrection` already moves stock between products.
3. Batch: if the incident names a `batch_id`, increment that batch's `quantity_remaining` under `lockForUpdate`. Stock returns to the batch it left, preserving its true `received_at` so FIFO order stays honest. If no batch is named, create an `InventoryBatch` with `receiving_id = null` and `received_at = now()` (`receiving_id` is nullable and the table has no cost column, so this is clean).
4. If `serial_id` is named, return the serial to `ItemSerial::IN_STOCK_STATUS_ID` (26) — the value `DispatchFifoEnforcementTest` established as what production serials actually carry.

If the original batch was deleted before resolution, `batch_id`'s `nullOnDelete` FK has already set it to null and step 3 falls through to the new-batch path. No special handling required.

### Filing

`IncidentService::file()` gains one rule, driven by a new `IncidentReport::STOCK_AFFECTING_REASONS` constant (all reasons except `other`): those reasons require `product_id` and `quantity >= 1`; `other` permits both to be null.

`excludedBatchIds()` and `incidentCountFor()` are untouched. The FIFO hold applies to standalone reports exactly as it does to pick-time ones — one rule, no special cases. A return or an `other` incident naturally names no batch, so it has no FIFO effect. The risk that a mistaken filing freezes stock is accepted and mitigated by the review queue and the existing reporter-count flag.

### Controller

`IncidentReportController::store()` validation:

- `source_type` — `Rule::in(['issuance', 'transfer', 'standalone'])`
- `source_id` — `required_unless:source_type,standalone`
- `product_id`, `quantity` — `required_unless:reason,other`
- `occurred_at` — `nullable|date|before_or_equal:now`
- `related_issuance_id` — `nullable|integer|exists:issuances,issuance_id`
- `reason` — `Rule::in(IncidentReport::REASONS)`, now eight values

New endpoint `POST /incident-reports/photo`, gated on `incident_reports.create`, validating `image|max:5120` and storing to the `public` disk exactly as `AccountController` stores avatars. Returns `{ path }`.

**Serializer fix, required.** `index()` currently does `(int) $i->product_id`. Once that column is nullable, `(int) null` yields `0`, so an `other` incident would report product #0 — a real product id — rather than "none". That cast must become null-preserving, following the pattern the same method already uses for `batch_id`. This is harmless today and becomes a live defect the moment the migration runs. `quantity` keeps its `(int)` cast, since the column stays non-null.

No new read endpoints are needed: `GET /issuances?search=` already does paginated, location-scoped issuance-number search, and `GET /inventory-batches` already returns location-scoped in-stock batches with `product_id`, `zone_label`, and `quantity_remaining`.

## Frontend

### Shared reason table

New `frontend/src/admin/utils/incidentReasons.js`, exporting one entry per reason:

```js
{ value, label, help, group, badge, affectsStock, outcome }
```

`outcome` is `'writeoff' | 'retype' | 'relocate' | 'restock' | 'none'`, mirroring the backend strategy map, so the resolve drawer derives its confirmation copy instead of hardcoding it.

This exists because reason metadata is currently duplicated three ways — `IncidentReportsPage` (`REASONS` + `REASON_BADGE`), `IncidentResolveDrawer` (`REASON_LABELS` plus a hardcoded `writesOffStock = not_found || damaged`), and `IncidentModal` (`REASONS` with help text). Growing four reasons to eight across three independent lists is how they drift apart. All three files are refactored to read from the shared table.

### IncidentFileModal (new)

`components/IncidentFileModal.jsx`, opened by a **Report Incident** button in `IncidentReportsPage`'s header, gated on `can('incident_reports', 'create')`.

Kept separate from the picking `IncidentModal` rather than generalising one component. They are genuinely different surfaces: the picking modal is controlled and presentational — the scanner supplies product, batch, zone, and quantity, and the user only chooses a reason — while the standalone modal must let the user *find* all of it. Merging them yields one component that is half form and half display, with every field conditionally rendered on a mode flag. They share the reason table, which is the right amount of sharing.

| Field | When |
|---|---|
| Reason — grouped radio list (Shrinkage / Damage / Wrong item / Return / Other) | always |
| Occurred at — `datetime-local`, defaults to now, `max` = now | always |
| Location — select | always |
| Narrative | always, required |
| Product — filterable select (same pattern as the drawer's `wrong_type` picker) + Quantity | required unless reason is `other` |
| Batch — optional, filtered from `/inventory-batches` by product and location | optional; selecting one shows an explicit "this holds the batch out of picking until reviewed" warning |
| Issuance — searchable via `GET /issuances?search=`, pre-fills location | only when reason is `customer_return` |
| Photo — optional | always |

Submits `source_type: 'standalone'` with no `source_id`.

### Photo upload

`uploadRequest` already exists in `api.js`. Add `incidentReportsAPI.uploadPhoto(file)` posting to `POST /incident-reports/photo` and returning `{ path }`, which both modals place in `photo_path`. `IncidentModal`'s photo input already exists on screen but is explicitly presentational — its comment "no photo key is posted (the photo field is presentational)" is deleted along with the behaviour it describes. `resolveStorageUrl` and the drawer's `<img>` rendering are already built and need no change.

### IncidentReportsPage

- **Report Incident** button in the header, permission-gated.
- Reason filter options and badge classes read from the shared table.
- Date column shows `occurred_at`, falling back to `created_at` for historic rows.
- Source column renders `standalone` as an em dash rather than "standalone #—".

### IncidentResolveDrawer

- Labels and the write-off warning derive from `outcome` instead of hardcoded reason checks.
- `customer_return` gets its own confirmation copy naming the quantity and the destination batch.
- `other` shows Acknowledge / Dismiss with no stock language and no write-off warning. Acknowledge posts `action: 'confirm'`; only the label differs.
- `occurred_at` and a link to `related_issuance_id` appear in the detail grid.

## Permissions

Add `branch_manager` to `incident_reports.create` in `PermissionSeeder`, then re-seed **both** `airkingV5` and `airkingV5_test` — this repo's schema is a SQL dump rather than migrations, so per-database seeding is the established convention.

`resolve` stays `admin` + `inventory_analyst`, preserving maker-checker: filing a write-off and approving it remain different people. `auditor` keeps `read` only — filing rows would break the read-only guarantee that role carries elsewhere. The `/admin/incidents` nav bundle already exists, so `PermissionCoverageTest` needs no change.

## Testing

The 12 tests in `IncidentResolutionTest` are the safety net for the strategy extraction. They must pass **untouched**; if they need editing, the refactor is wrong.

**Schema** — new columns exist and are nullable; `related_issuance_id` FK resolves.

**Filing**
- Standalone with `source_type: 'standalone'` and no `source_id` succeeds.
- `other` with no product succeeds and stores `product_id` as null and `quantity` as `0`.
- `theft` without a product returns 422.
- A future `occurred_at` returns 422.
- A standalone report naming a batch excludes that batch from `excludedBatchIds` — the FIFO decision, pinned by a test.

**Resolution**
- `theft` posts an adjustment of type `loss`; `delivery_damage` posts type `damage`.
- `customer_return` increments the **original** batch's `quantity_remaining` and the location inventory by the same amount.
- `customer_return` with no batch creates one with `receiving_id = null`.
- `customer_return` naming a serial returns it to status 26.
- `other` resolves with no adjustment and no correction.

**Permissions**
- `branch_manager` can file but cannot resolve.
- `auditor` can do neither.

**Frontend** — no test suite exists in this repo. Verified by a manual `npm run demo` pass: file each of the four new reason types, confirm a return puts stock back on the correct batch, and confirm the Report Incident button is absent for the `auditor` role.

## Related

- Supersedes nothing. Extends the incident module from `docs/superpowers/specs/2026-07-24-set-boq-fifo-picking-incident-reports-design.md`.
- Touches `IncidentService`, `IncidentReportController`, `IncidentReport`, `IncidentReportsPage`, `IncidentResolveDrawer`, `components/picking/IncidentModal`, `PermissionSeeder`, and `api.js`.
- Does **not** touch `FifoPickingService`, `ScanResult`, `PickScanner`, or scan-time enforcement.
