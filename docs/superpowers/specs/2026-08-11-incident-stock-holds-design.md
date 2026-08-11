# Incident Stock Holds — Design

**Date:** 2026-08-11
**Branch:** backupV3
**Status:** Approved, ready for planning
**Supersedes behaviour in:** `2026-08-10-incident-guided-flows-design.md` (two resolution behaviours are reversed — see Reversals)

## Problem

The client handed over an INCIDENT REPORT FORM (LGM Global Industries) built around a defective unit needing management approval. Reviewing it against the system surfaced a deeper mismatch than a form layout: **the system has no concept of stock that exists but is not sellable.**

Today, resolving a write-off *deletes* the quantity — `WriteOffResolution` decrements the batch and the location and posts an Adjustment. The units are gone from the database. But in the warehouse they are sitting in a corner: a returned aircon waiting for someone to look at it, five defective lengths of pipe waiting to go back to the supplier, a unit recorded as missing that might still turn up.

The client wants every incident recorded and every outcome tracked. That needs stock to **move into a condition** rather than vanish, and it needs a second decision — an inspection — that says where it goes next.

A zone mismatch, by contrast, must change nothing at all.

## Goals

1. Held stock is real, countable, and traceable to the incident and the people who reported and approved it.
2. Each incident type drives the right process, decided by the type the reporter chooses.
3. A second, explicit disposition step for stock that can come back.
4. Correct the two resolution behaviours that are currently wrong in production.

## Non-goals — deferred to later pieces

- **Piece B — the defect document fields.** The client form's dealer name/phone/mobile/email, branch address, customer name and contact, site address, date of purchase, DOR, the indoor/outdoor model+serial pair, and the five-item attachment checklist. This piece delivers the defect *type* and its root-cause attribution; B dresses it in the client's document.
- **Piece C — the printable report.** Letterhead, the client's layout, the two signature lines, PDF/print export.
- Any change to how `inventory.status_id` works.

## Reversals — behaviours currently wrong on `origin/backupV3`

Both shipped on 2026-08-10. Both are corrected here.

### 1. Zone mismatch must change nothing

`RelocateResolution` currently rewrites the batch's `zone_label` from `actual_zone`. This was changed yesterday and written up as fixing a "silent no-op".

That was wrong. The client's case: a reporter sees serial 1234 physically in zone 2, while the system says zone 1 — and **the system is correct**. Someone misplaced the box. The fix is to walk over and move it back, not to overwrite a correct record with a wrong physical location.

The original no-op was correct behaviour. `RelocateResolution` reverts to recording only.

### 2. A customer return is not immediately sellable

`RestockResolution` currently increments the batch and the location on approval, putting the unit straight back into pickable stock with nobody having inspected it.

Returns now land in a `returned` hold. The increment moves to the disposition step, so a unit becomes sellable only when a person says it is.

## The model

### `stock_holds`

One row = a quantity of one product, at one location, in one condition, traceable to the incident that put it there.

| Column | Purpose |
|---|---|
| `hold_id` | pk |
| `incident_id` | which report put it here — the audit trail |
| `product_id`, `location_id` | what and where |
| `batch_id` | which batch it left, so a restock knows where to put it back (nullable) |
| `serial_id` | the exact unit, for appliances; null for materials |
| `condition` | `missing` · `defective` · `returned` |
| `quantity` | PIECES — always 1 when `serial_id` is set |
| `status` | `held` · `released` |
| `disposition` | `restocked` · `defective` · `returned_to_supplier` · `scrapped` (null while held) |
| `disposed_by`, `disposed_at`, `disposition_note` | who decided, when, and why |
| `created_at`, `updated_at` | |

**Why a table and not status flags.** 208 products are serialized appliances and 128 are installation materials with no serials at all. "This unit is defective" is a per-serial fact for the first and a per-quantity fact for the second. A single ledger carries both — `serial_id` is set when known, `quantity` is always meaningful. Status flags would need two mechanisms kept in sync, and would leave non-serialized holds with no per-incident trace and nowhere to record a disposition.

### The invariant

```
physical stock = active stock + held stock
```

Approval deducts from `inventory` and the batch exactly as write-off does today, **and** writes the hold row. Nothing vanishes; it moves. A hold row is the only thing that can put stock back.

### New statuses

Missing, Defective and Returned are added to `status_lookup` under the **`inventory`** category — where `In Stock` (26) already lives and what `item_serial.status_id` already points at. A serial lookup then tells the truth about a held unit without consulting the ledger.

`inventory.status_id` is untouched: In Stock / Low Stock / Out of Stock stay quantity-derived. Holds are not overloaded onto that column.

## The lifecycle

```
filed (Open)  — no stock change, as today
   │
   └─ manager approves ──▶ held: MISSING · DEFECTIVE · RETURNED
                              │
                              └─ inspected ──▶ restocked  (back to active stock)
                                           ──▶ defective  (new defective hold)
                                           ──▶ returned to supplier
                                           ──▶ scrapped
```

### On approval, per type

| Type | Hold row | Active stock |
|---|---|---|
| Missing | `missing` — terminal, no disposition offered | ↓ |
| Defective | `defective` — awaits disposition | ↓ |
| Return (from customer) | `returned` — awaits disposition | ↓ |
| Wrong item | none — re-types the batch, as today | moves between products |
| Zone mismatch | **none** | **unchanged** |
| Other | none | unchanged |

Missing writes a row even though it is terminal: that is what makes shrinkage countable per quarter, per location and per reporter. A missing item that later turns up is restored by filing a separate report, not by disposing of the hold.

### Disposition

Available on `defective` and `returned` holds only, and **which dispositions are offered depends on the condition** — a defective hold cannot be disposed as defective (it already is), and a unit known to be defective is not put back on the shelf as sellable.

| From `returned` | Effect |
|---|---|
| `restocked` | quantity returns to the batch and the location; serial back to In Stock |
| `defective` | closes the hold, opens a `defective` hold for the same quantity |
| `returned_to_supplier` | closes the hold; stock leaves the building |
| `scrapped` | closes the hold; stock is destroyed |

| From `defective` | Effect |
|---|---|
| `returned_to_supplier` | closes the hold; stock goes back for credit or warranty |
| `scrapped` | closes the hold; stock is destroyed |

So the only route from defective back to sellable stock is a deliberate new report — there is no one-click path that puts a known-defective unit back into picking. A hold can be disposed exactly once; the endpoint rejects a second attempt.

## The types

The eight reasons collapse to six, with cause moved out of the reason and into reportable fields.

| Today | Becomes |
|---|---|
| `not_found`, `theft` | **Missing** + suspected cause |
| `damaged`, `delivery_damage` | **Defective** + root cause |
| `customer_return` | **Return** + direction and reason |
| `wrong_type` | **Wrong item** (unchanged) |
| `zone_mismatch` | **Zone mismatch** (unchanged) |
| `other` | **Other** (unchanged) |

Migration is trivial: the database holds exactly **3 incidents**, all test rows. `theft` maps to Missing with suspected cause `theft`; `not_found` to Missing with `unknown`; `damaged` to Defective with root cause `internal`; `delivery_damage` to Defective with `delivery`.

### Fields added per type

| Type | Fields |
|---|---|
| **Missing** | `last_known_location` (free text) · `suspected_cause` — *theft · misplaced · unknown* |
| **Defective** | `root_cause` — *supplier · internal · delivery · other* |
| **Return** | `return_direction` — *from_customer · to_supplier* · `return_reason` · the issuance or transfer it came from (`related_issuance_id`, new `related_transfer_id`) |
| **Zone mismatch** | system zone vs actual physical zone — **already built and already correct** |

The zone-mismatch form already captures both zones in `expected_zone` / `actual_zone` and auto-fills the system one from the batch. Only the resolution changes.

**Return direction** is the second meaning of "return" the system has never had: stock coming back **from a customer**, versus stock going back **to a supplier**. A `to_supplier` return is also reachable as a disposition on a defective hold.

## What changes in code

### Backend

| Change | Detail |
|---|---|
| Migration | `stock_holds`; incident columns `suspected_cause`, `root_cause`, `return_direction`, `return_reason`, `last_known_location`, `related_transfer_id` |
| Seeder | Missing · Defective · Returned into `status_lookup` (`inventory` category) |
| `StockHoldService` | **Sole mutator** for `stock_holds` — same discipline as `BudgetService`. Nothing else writes the table |
| Strategies | Missing and Defective deduct active stock *and* write a hold; Return writes a `returned` hold and no longer increments; Zone mismatch records only; Wrong item unchanged; Acknowledge unchanged |
| New endpoint | `POST /stock-holds/{id}/dispose` — atomic, permission-gated, rejects a second disposition |
| Serials | `item_serial.status_id` follows the hold, and returns to In Stock on a `restocked` disposition |

The Adjustment ledger is still written on approval, so existing loss and damage reporting keeps working.

### Frontend

- The six types with their new fields in the guided flow (`components/incident/steps/`).
- A **Held Stock** worklist page: open holds, filterable by condition and location, with the Dispose action.
- Resolve drawer copy changes from "writes off 25 pcs" to "moves 25 pcs to Defective".

## Testing

The invariant is the centre of the suite.

| Test | Why |
|---|---|
| `physical = active + held` after every approval | the core guarantee |
| **Zone mismatch changes nothing** | the regression test that would have caught the bug being reverted |
| Return does not increment on approval | reversal 2 |
| Return *does* increment on a `restocked` disposition | the increment moved, it did not disappear |
| A hold cannot be disposed twice | the endpoint is the only mutator and must be idempotent-safe |
| A `defective` hold rejects `restocked` and `defective` | no one-click path puts a known-defective unit back into picking |
| Serial status follows the hold, and comes back on restock | a scan must not lie |
| Missing writes a terminal hold with no disposition offered | shrinkage stays countable |
| Non-serialized materials hold by quantity | the 128 products with no serials |

Several of the existing **75 incident tests** will fail *correctly* because the behaviour genuinely changed. Those get rewritten to the new expectations — not deleted, and not weakened to pass.

## Scope

Larger than the guided-flows work: two migrations, a seeder, a new service, four rewritten strategies, a new endpoint, a new page, six reworked flows. Roughly 12–14 tasks. One coherent subsystem, so it does not need splitting further — but it is not a quick revision, and pieces B and C sit behind it.

## Environment notes for the implementer

- **The schema is a SQL dump, not a migration history.** A bare `php artisan migrate` fails. Apply a single migration with `--path`, to **both** the dev DB and `airkingV5_test`.
- Tests use `DatabaseTransactions`, never `RefreshDatabase`. `phpunit.xml` points at `airkingV5_test`.
- Known pre-existing backend failures: **9 `WarrantyClaimTest`**, plus the order-dependent `SerialRowMovementColumnsTest`. Treat 9 or 10 as the baseline and confirm any failure is one of those.
- Frontend: `npm test -- --watchAll=false`. **3 suites are already red** and unrelated — `App.test.jsx` (CRA boilerplate), `navConfigureMap` (`stock_transfers` nav reachability), `InventoryManagement` (Warehouse Map tab). Baseline is 111 passing / 5 failing.
- Verify frontend work with `npm run build` **and** `npx eslint` on each changed file — the build compiles code ESLint rejects, and an unimported file is never linted.
- **~20 files are dirty in the working tree** from unrelated work, including `airkingv5.sql`. Stage only what each task names; never `git add -A`.
- PowerShell here-strings break `git commit -m`; use `git commit -F -`.
- Quantities are PIECES everywhere. `packageFormat.js` re-expresses for display and entry only.
