# Incident Inspection Workflow — Design

**Date:** 2026-08-12
**Branch:** backupV3
**Status:** Approved, ready for planning
**Supersedes:** `2026-08-11-incident-stock-holds-design.md` — the workflow order and the `stock_holds` table are both replaced. See *What this supersedes*.

## Problem

The stock-holds work shipped a two-stage flow: an admin approves an incident, stock moves into a hold, and an inspection happens afterwards. Testing it showed the order is backwards.

> *"it should go first to inspection before confirming it by the admin to complete the process"*

An admin confirming before anyone has examined the goods is approving on the strength of one reporter's word. The client's own paper form says so: it carries two signature lines — an Authorized Representative and the Branch Manager — and a five-item list of photographic evidence that must be attached. The signatures come *before* the decision is final, not after.

Two further problems surfaced with it:

1. **One report cannot describe two units.** `incident_reports` points at one `product_id` and one `serial_id`. A split-system aircon is an indoor and an outdoor unit with two model codes and two serials on a single report, and the inspector may scrap one and restock the other. There is nowhere to put the second unit, let alone a per-unit outcome.
2. **Every type was forced through the same gate.** Requiring five photos and two physical signatures to record that a box is on the wrong shelf means people stop reporting it — and the client wants everything recorded.

## Goals

1. Nothing leaves active inventory until an authorised person has inspected it and an admin has confirmed.
2. One report can carry many physical items, each with its own outcome.
3. Evidence is captured against the client's checklist, and the report prints as their document for physical signing.
4. Operational exceptions that move no stock stay lightweight.

## Non-goals

- Digital signatures. The hardcopy is printed, signed by hand, and carried to the admin. The system records *that* it was signed, not a cryptographic signature.
- Changing FIFO picking itself, beyond which batches it excludes.
- A supplier-facing returns portal. "Returned to supplier" is a status here, not a workflow.

## Decisions

| Decision | Choice |
|---|---|
| Order | Inspection **before** admin confirmation |
| Authority | Inspector decides the outcome; admin approves or rejects it |
| Stock movement | Once, at admin confirmation |
| Quarantine | Excludes from picking; **quantity unchanged** |
| Items | `incident_items` junction — one row per physical thing |
| Evidence | Per-type checklist, each item skippable **with a written reason** |
| Client-form fields | Filled by the **reporter** at filing |
| Evidence + outcome | Filled by the **inspector** |
| Zone mismatch / Other | Bypass inspection entirely |

## The workflow

```
reported ─────────────▶ Open
                          │  items QUARANTINED — unpickable, still counted
inspector examines,       │
  attaches evidence,      │
  sets an outcome
  PER ITEM  ────────────▶ Awaiting Inspection
                          │
  prints the form,        │
  signs it, branch        │  (physical hardcopy → admin)
  manager counter-signs   │
                          │
admin confirms ─────────▶ Complete   ← stock moves HERE, once, atomically
admin rejects ──────────▶ back to Awaiting Inspection
```

**Stock moves exactly once**, in one transaction at confirmation, applying the outcome the inspector already recorded. Rejecting returns the report to the inspector with the admin's note; it does not reopen filing.

### Routing by type

| Type | On filing | Path |
|---|---|---|
| Defective | items `QUARANTINED` | inspection → admin → Complete |
| Missing | items `QUARANTINED` | inspection → admin → Complete |
| Return | items `QUARANTINED` | inspection → admin → Complete |
| Wrong type | items `QUARANTINED` | inspection → admin → Complete |
| **Zone mismatch** | no items, no quarantine | straight to admin confirmation |
| **Other** | no items, no quarantine | straight to admin confirmation |

Zone mismatch and Other never touch inventory, so demanding photographic evidence and two signatures for them would suppress exactly the reporting the client asked for.

## Schema

Three tables replace the single `incident_reports` + `stock_holds` pair.

### `incident_reports` — one row per report

Keeps everything it has, plus the client-form block, filled by the reporter:

| Field | For |
|---|---|
| `dealer_name`, `dealer_phone`, `dealer_mobile`, `dealer_email`, `branch_address` | the dealer block |
| `customer_name`, `customer_contact`, `site_address` | who and where |
| `purchased_at`, `received_at_dor` | date of purchase, DOR |
| `inspected_by`, `inspected_at`, `inspection_findings` | the inspection |
| `signed_by_representative`, `signed_by_branch_manager`, `signed_at` | names as printed on the signed hardcopy |
| `rejection_note` | why an admin sent it back |

The existing cause fields (`suspected_cause`, `root_cause`, `return_direction`, `return_reason`, `last_known_location`) stay as they are.

### `incident_items` — one row per physical thing

**This replaces `stock_holds`.** They are the same row wearing different hats — a quantity of one product, optionally one serial, attached to one incident, carrying a status — but this version is the unit of the *report*, so a split-system pair is two rows and each can end differently.

| Column | Purpose |
|---|---|
| `incident_id` | the report |
| `product_id` | what |
| `serial_id` | the exact unit; null for non-serialized materials |
| `unit_role` | `indoor` · `outdoor` · `single` — the client form's Model ID / Model OD |
| `model_code`, `serial_number` | as written on the sticker, kept verbatim even if it never matches a catalogue row |
| `batch_id` | which batch it left, so a restock knows where to put it back |
| `quantity` | PIECES; 1 when `serial_id` is set |
| `status` | `quarantined` → `available` · `scrapped` · `returned` · `missing` |
| `outcome` | the inspector's decision, before the admin applies it |
| `outcome_note` | why |
| `created_at`, `updated_at` | |

`model_code` and `serial_number` are stored as text alongside the FKs on purpose: a defective unit's sticker may not match anything in the catalogue, and that mismatch is often the incident. Refusing to record what is physically printed on the box would lose the evidence.

### `incident_evidence` — one row per attachment

The system accepts exactly one photo today. The client's form requires five.

| Column | Purpose |
|---|---|
| `incident_id` | the report |
| `requirement` | which checklist item this satisfies |
| `photo_path` | on the private `local` disk, as evidence photos already are |
| `photo_source` | `captured` · `uploaded` — client-asserted, never trusted |
| `skipped_reason` | set instead of `photo_path` when the requirement cannot be met |
| `uploaded_by`, `created_at` | |

Requirements, from the client's form:

| Key | Requirement |
|---|---|
| `defect_photo` | picture of the defect |
| `model_serial_sticker` | picture of the model / serial sticker |
| `box_two_sides` | two sides of the box including the serial sticker |
| `accessories_warranty` | completed accessories picture including the warranty card |
| `asc_service_report` | service report, if checked by an ASC |

**Per-type checklist, skippable with a reason.** Which requirements are asked for:

| Requirement | Defective | Return | Missing | Wrong type |
|---|---|---|---|---|
| `defect_photo` | ✅ | ✅ | — | — |
| `model_serial_sticker` | ✅ | ✅ | — | ✅ |
| `box_two_sides` | ✅ | ✅ | — | — |
| `accessories_warranty` | ✅ | ✅ | — | — |
| `asc_service_report` | only if *checked by ASC* | only if *checked by ASC* | — | — |

**Missing asks for none of them** — the item is gone, so there is nothing to photograph, and demanding a photo of an absent unit is how a checklist teaches people to lie. A wrong-type report needs the sticker, because the sticker not matching the goods *is* the incident.

Every requirement can be skipped with a written reason ("box was already discarded", "unit was taken so there is nothing to photograph"). Every skip is shown to the admin alongside its reason, so an incomplete report is visibly incomplete rather than silently thin.

## Who does what

Three permissions on the existing `incident_reports` feature:

| Permission | Who | Can |
|---|---|---|
| `incident_reports.create` | admin, inventory_analyst, branch_manager, warehouse_personnel | file a report |
| `incident_reports.inspect` | **new** — admin, inventory_analyst, branch_manager | attach evidence, set per-item outcomes, submit for approval |
| `incident_reports.resolve` | admin, inventory_analyst | confirm or reject a submitted inspection |

`incident_reports.dispose`, added for the stock-holds work, is retired — the inspection replaces it.

**The inspector and the admin must not be the same person on the same report.** The client's form carries two signature lines precisely so that one person cannot both examine the goods and approve their write-off. Confirmation is refused when `inspected_by` equals the confirming user, with one exception: a sole `admin` on a single-user install would otherwise be unable to close any report, so the rule is enforced only where another user holds `incident_reports.resolve` at that location. `auditor` stays read-only throughout.

## Quarantine

**Filing quarantines the items. It does not deduct them.**

`quantity_on_hand` is unchanged: the units are physically in the building and a stock count would find them. They are simply unpickable. Reports can honestly say *"100 on hand, 10 quarantined"*.

Three reasons this is not a deduction:

1. One reporter should not move real inventory with no approval.
2. A deduction at filing makes the system disagree with a physical count for as long as the inspection takes.
3. Dismissing a false report would need a compensating correction, which is a second chance to get the arithmetic wrong.

FIFO exclusion is driven from `incident_items.status = 'quarantined'` rather than from the incident's status. Deriving it from status broke once already — adding `Awaiting Inspection` silently returned stock under investigation to the picking pool, because the exclusion query filtered on `Open`.

### The invariant

```
quantity_on_hand = pickable + quarantined
```

and after confirmation, a quarantined quantity has become exactly one of: back in `pickable` (`available`), or deducted from `quantity_on_hand` and recorded as `scrapped`, `returned` or `missing`. Those three are the only paths that reduce the stock count, and all three run inside the confirmation transaction.

## What the admin sees

The confirmation screen is the digital counterpart of the signed hardcopy: the report, every item with the inspector's chosen outcome, every piece of evidence, and every skipped requirement with its stated reason. Two actions — **Confirm**, which applies every item's outcome in one transaction, and **Reject**, which returns it to the inspector with a note.

The printable document must be available **before** confirmation, because the admin needs the signed hardcopy in hand to approve. It is generated from the report at the end of inspection, printed, signed by the representative and the branch manager, and carried over.

## What this supersedes

From `2026-08-11-incident-stock-holds-design.md`, now built and on the branch:

| Built | Fate |
|---|---|
| `stock_holds` table + model | **Reshaped** into `incident_items` — gains `outcome`, `unit_role`, `model_code`, `serial_number`; `disposition` folds into `outcome` |
| `StockHoldService` (13 tests) | **Kept**, reshaped: `hold()` becomes quarantine-at-filing, `dispose()` becomes apply-outcome-at-confirmation. It stays the sole mutator |
| Held Stock page | **Kept**, becomes a *register* of non-sellable stock rather than a to-do list — the Dispose button is replaced by the inspection |
| Six types + cause fields | **Kept unchanged** |
| Awaiting Inspection status | **Kept**, but now set by the inspector rather than by the admin |
| Both reversals (zone mismatch changes nothing; a return is not sellable on approval) | **Kept** |
| `unsettled()` FIFO scope | **Replaced** by exclusion driven from `incident_items.status` |

This is a reshape, not a teardown. The service, the statuses, the type rework and the guided flow all survive.

## Implementation constraints

Three rules that are not negotiable during build, each because it has already gone wrong here.

### 1. Confirmation is one transaction

The admin's confirm touches `incident_reports.status_id`, every `incident_items.status`, `inventory.quantity_on_hand`, `inventory_batches.quantity_remaining` and `item_serial.status_id`. All of it goes inside a single `DB::transaction`, with the incident row locked (`lockForUpdate`) and its status re-checked **under the lock** — the pattern `IncidentService::resolve` already uses, which exists because two reviewers confirming at once would otherwise both pass the guard and double-book the movement.

A partial confirm is the worst outcome available: stock deducted with the report still unconfirmed, or a report marked Complete with stock untouched. Neither is detectable without a physical count.

### 2. Statuses by string code, never by id

Install-specific ids differ between databases — Missing/Defective/Returned are **50/51/52** in `airkingv5` but **1779/1780/1781** in `airkingV5_test`. A hardcoded literal passes locally and fails under PHPUnit, or worse, silently writes the wrong status.

- `incident_items.status`, `incident_items.outcome` and `incident_evidence.requirement` are **plain string columns** with class constants — no lookup table, no FK, no id resolution. This is the preferred shape for anything new.
- `incident_reports.status_id` stays an FK into `status_lookup`, for consistency with every other module, and is resolved **by name** through `IncidentReport::statusId('…')`. Never by literal.
- The same applies to `item_serial.status_id` via `StockHold::inventoryStatusId('…')`. Note `ItemSerial::IN_STOCK_STATUS_ID = 26` is an existing hardcoded literal in the codebase — it happens to match in both databases today, but do not copy the pattern, and do not rely on it in new code.

### 3. Navigation takes two edits

The sidebar is registered in two unrelated places, and doing only one leaves the page reachable by URL but invisible:

| File | Supplies |
|---|---|
| `NavBundleSeeder.php` + `frontend/src/admin/utils/navBundles.js` | the permission **gate** — `buildSidebar` looks a bundle up by `routePath` to decide whether an item is allowed |
| `adminMenuSections` in `frontend/src/admin/components/Sidebar.jsx` | the menu **item** itself, as a hardcoded list |

This already cost a round trip on the Held Stock page. Verify by loading the app, not by checking the database.

## Testing

| Test | Why |
|---|---|
| `quantity_on_hand = pickable + quarantined` after filing | the core guarantee; filing must not deduct |
| Filing quarantines every item on the report | a two-unit report quarantines both |
| A quarantined batch is excluded from FIFO picking | driven from item status, not incident status — the bug that already happened once |
| An admin cannot confirm a report still Open | inspection is not optional for the four stock types |
| Zone mismatch and Other reach admin with no inspection | and create no items |
| The inspector's per-item outcomes are applied on confirmation | indoor scrapped + outdoor restocked on one report |
| Confirmation is atomic | a failure part-way applies nothing |
| Rejection returns to Awaiting Inspection, not Open | and keeps the evidence |
| A skipped requirement stores its reason and is visible to the admin | incompleteness must be loud |
| Missing asks for no photographic evidence | the item is gone; the checklist must not teach people to fake it |
| The inspector cannot confirm their own report | the two signature lines exist for exactly this reason |
| Evidence paths are constrained to the incidents directory | the existing `photo_path` regex applies per row |

Existing incident tests will need updating where they assert the old order. Rewrite them to the new expectations — never weaken an assertion to make it pass.

## Environment notes for the implementer

- **The schema is a SQL dump, not a migration history.** A bare `php artisan migrate` fails; apply with `--path`.
- **There is no `.env.testing`.** `--env=testing` silently migrates the DEV database. Use `DB_DATABASE=airkingV5_test php artisan <cmd> --force` from `back-end/` in Bash.
- **Never hardcode an id.** Status ids differ between the two databases (Missing/Defective/Returned are 50/51/52 in dev, 1779/1780/1781 in test) and `user_id` 1 does not exist in the test DB.
- **Permissions need two seeders.** `PermissionSeeder` creates the permission; `RolePermissionsSeeder` writes the grants. An admin passes structurally via `PermissionResolver::isAdmin()`, so **test as an inventory_analyst** — an admin masks a missing grant.
- **The sidebar needs two edits.** `navBundles.js` (+ the `NavBundleSeeder` row) supplies the permission *gate*; `adminMenuSections` in `Sidebar.jsx` supplies the *item*. Registering only the bundle leaves the page reachable by URL alone.
- `Sanctum::actingAs()` 500s in this app. Use `$this->actingAs($user, 'sanctum')`.
- Backend baseline: 9 `WarrantyClaimTest` failures plus the order-dependent `SerialRowMovementColumnsTest`.
- Frontend baseline: 3 failing suites — `App.test.jsx`, `navConfigureMap`, `InventoryManagement`.
- Verify frontend with `npm run build` **and** `npx eslint` — the build compiles code ESLint rejects, and an unimported file is never linted.
- **~20 files are dirty** in the working tree, including `airkingv5.sql`. Stage only what each task names.
- Quantities are PIECES everywhere; `packageFormat.js` re-expresses for display only.
