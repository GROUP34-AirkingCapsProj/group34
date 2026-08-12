# Incident Returns and Form-Shaped UI — Design

**Date:** 2026-08-12
**Branch:** backupV3
**Status:** Approved, ready for planning
**Extends:** `2026-08-12-incident-inspection-workflow-design.md`. The inspection order, `incident_items`, `incident_evidence` and the quarantine model all stand. This revises the return type, the outcome list, the evidence checklist and the filing UI.

## Problem

Three things surfaced once the inspection workflow was usable.

**1. The filing UI collects the client's form but does not look like it.** It is a five-step wizard. The client works from a paper INCIDENT REPORT FORM and knows its shape; a reporter holding that paper cannot follow the screen down it.

**2. Return is under-modelled.** `return_direction` has exactly two values — `from_customer`, `to_supplier`. That conflates *who the counterparty was* with *which way the goods travel*, and cannot express an interbranch transfer at all. Nor can it say **what was wrongly delivered and what should have been**, which is the single most common return.

**3. Inbound returns are modelled backwards.** Filing a return quarantines an existing batch. But a customer return was issued out weeks ago and is in no batch — the goods are *arriving*, not sitting in stock. Quarantining a batch for them quarantines stock that is not there.

There is also a boundary error waiting to happen: a returned unit going back to a supplier under warranty is warranty-module work, and the incident module was about to grow a second copy of it.

## Goals

1. The filing screen reads like the client's document.
2. A return records who it came from, why, and — for a wrong delivery — both what came and what should have.
3. Inbound returns register incoming goods rather than deducting stock that isn't there.
4. Defective units route to the existing warranty module instead of a parallel implementation.

## Non-goals

- Automatically raising a replacement issuance, a purchase return, or a warranty claim. Everything here **records**; a human raises the follow-on document.
- Changing the inspection order, the quarantine-does-not-deduct rule, or the two-signature model. Those stand.
- Changing `picking/IncidentModal`. A picker filing mid-pick keeps the short modal.

## Decisions

| Decision | Choice |
|---|---|
| Correct product on a wrong delivery | **Record only** — no replacement raised |
| Inbound returns | Goods are **physically back**; filing registers them as incoming quarantined stock |
| Return reasons | wrong product · wrong quantity · no longer needed |
| Defective | **An inspection outcome, not a return reason** |
| Supplier warranty | **Surface it** — the warranty module lists it; the claim is raised there |
| Warranty state | Three: in warranty · expired · none on record |
| Filing UI | One form-shaped page with a type dropdown |

## The return model

Three independent facts replace one two-valued field. `return_direction` retires.

| Field | Values |
|---|---|
| `return_counterparty` | `customer` · `supplier` · `branch` |
| `return_reason_code` | `wrong_product` · `wrong_quantity` · `no_longer_needed` |
| direction | **derived, never asked** — `customer` and `branch` are inbound, `supplier` is outbound |

Direction is derived on purpose. Asking a reporter which way the goods travel invites the answer that contradicts the counterparty, and there is no case where those two disagree.

### The wrong/correct pair

`incident_items` gains:

| Column | Purpose |
|---|---|
| `expected_product_id` | what should have been delivered — `wrong_product` only |
| `expected_quantity` | what was ordered — `wrong_quantity` only |

Both nullable, both recorded and printed, neither triggering anything. Getting the right unit to the customer is a normal issuance raised separately; a report that silently created one would raise stock movements nobody agreed to.

On `wrong_quantity` the delivered figure is the item's existing `quantity`, so the discrepancy is `quantity - expected_quantity` and is visible rather than implied.

### Inbound returns register stock

This is the correctness fix.

| Counterparty | On filing | On confirmation |
|---|---|---|
| `supplier` (outbound) | quarantine an existing batch — today's behaviour, unchanged | `restock` returns it to pickable; anything else deducts |
| `customer` · `branch` (inbound) | **register** the arriving units as a quarantined item with **no batch deduction** — they were issued out long ago and are in no batch | `restock` **adds** them to `quantity_on_hand` and to the legacy batch; anything else adds nothing |

The invariant still holds, with one clause added:

```
quantity_on_hand = pickable + quarantined(outbound)
inbound quarantined stock is present but NOT yet counted
```

An inbound item is physically in the building and deliberately not in `quantity_on_hand` — it has not been accepted back yet. Counting it before someone has looked at it is how a return that turns out to be scrap inflates the stock figure.

`IncidentItem` carries `is_inbound` (derived from the counterparty at filing and stored, so a later edit to the report cannot silently flip the arithmetic that already ran).

## Outcomes and the warranty gate

A fourth outcome is needed: the current list cannot say *"keep it, we will fix it"*.

| Outcome | Final status | Stock effect on confirmation |
|---|---|---|
| `restock` | `available` | outbound: returns to pickable · inbound: **added** to stock |
| `return_to_supplier` | `returned` | the goods physically leave — outbound: deducted · inbound: nothing to deduct |
| `claim_warranty` | `awaiting_warranty_claim` | none — the unit is held here, the warranty module takes it from there |
| `repair` | `under_repair` | none — held, not sellable |
| `scrap` | `scrapped` | outbound: deducted · inbound: nothing to deduct |
| `write_off` | `missing` | outbound: deducted |

**`return_to_supplier` and `claim_warranty` are different things and both are needed.** Sending excess or wrongly-shipped stock back to a supplier is a commercial return: the goods leave, the unit is `returned`, and no warranty is involved. A warranty claim is the opposite — the unit **stays here** while a claim is raised against it, which is why its status is `awaiting_warranty_claim` and its stock effect is nothing. Collapsing them would have made every warranty case look like the goods had already gone.

Item statuses therefore grow from five to seven: `quarantined` · `available` · `scrapped` · `returned` · `missing` · **`under_repair`** · **`awaiting_warranty_claim`**.

### The gate

The warranty module keys on `warranty_records.serial_number`, with `warranty_end_date` and an Active/Expired/Claimed status. A record exists only for a unit that was sold, via an issuance.

| Warranty state | Outcomes offered |
|---|---|
| **In warranty** — record found, `warranty_end_date` not passed | restock · **claim_warranty** · return_to_supplier · repair · scrap |
| **Expired** — record found, date passed | restock · return_to_supplier · repair · scrap |
| **None on record** — non-serialized, or never sold | restock · return_to_supplier · repair · scrap |

Only `claim_warranty` is gated. `return_to_supplier` is available throughout — sending goods back commercially has nothing to do with whether they are under warranty.

The server decides this and serves it per item, exactly as `allowed_dispositions` did — the client must never re-derive it. Offering a warranty claim on an out-of-warranty unit wastes a warranty officer's time and, worse, is invisible until they open it.

**Three states, not two.** *Expired* and *no record* offer the same choices but mean different things: one unit was sold and its cover ran out, the other was never registered at all. Collapsing them hides the second, which is the one worth investigating.

### The handoff

`claim_warranty` sets the item to `awaiting_warranty_claim`. The warranty module gains a worklist of those items, each carrying its incident number, its evidence and its warranty record. A human raises the claim there through the existing flow.

Nothing is written into `warranty_claims` by the incident module. `warranty_claim_returns` already holds `holding_status`, `received_at`, `released_at` and `release_disposition` — the warranty module can already hold a returned unit, and duplicating that here would give one physical unit two owners.

## Evidence, per reason

The checklist becomes reason-driven rather than type-driven. Four new requirements:

| Requirement | Asked on |
|---|---|
| `wrong_product_photo` | return · `wrong_product` — what actually arrived |
| `correct_product_photo` | return · `wrong_product` — what should have |
| `returned_goods_photo` | return · `wrong_quantity` · `no_longer_needed` |
| `receipt_or_dor` | **every** return — the receipt or DOR proving the delivery |

Full matrix:

| Requirement | Defective | Return<br>wrong product | Return<br>wrong qty | Return<br>not needed | Wrong type | Missing |
|---|---|---|---|---|---|---|
| `defect_photo` | ✅ | — | — | — | — | — |
| `model_serial_sticker` | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| `box_two_sides` | ✅ | — | ✅ | ✅ | — | — |
| `accessories_warranty` | ✅ | — | ✅ | ✅ | — | — |
| `wrong_product_photo` | — | ✅ | — | — | — | — |
| `correct_product_photo` | — | ✅ | — | — | — | — |
| `returned_goods_photo` | — | — | ✅ | ✅ | — | — |
| `receipt_or_dor` | — | ✅ | ✅ | ✅ | — | — |
| `asc_service_report` | if ASC | — | — | — | — | — |

A wrong-delivery return proves *the delivery was wrong* — the pair plus the receipt. A wrong-quantity or unwanted return proves *whether the goods are still sellable* — the goods, the sticker, the box, the accessories. Neither asks for the other's evidence.

**Missing still asks for nothing.** The unit is gone; demanding a photograph of an absent item teaches people to fake evidence.

Every requirement stays skippable with a written reason, shown to the admin beside it.

## The form-shaped filing UI

One page, sectioned in the client's order, with a type dropdown driving what appears:

```
Incident type   [ Defective ▾ ]
─────────────────────────────────────────
Dealer            name · phone · mobile · email · branch address
Units             Model ID / Serial / DOR
                  Model OD / Serial            [+ Add unit]
Unit defect
Problem summary   date of purchase · customer · contact · site address
                  what happened
Attachments       the checklist for this type and reason
```

| Type | Sections shown |
|---|---|
| Defective | all |
| Return | all, plus counterparty, reason, and the wrong/correct pair on `wrong_product` |
| Wrong type | units, problem summary, attachments |
| Missing | units, problem summary |
| Zone mismatch · Other | location, product, what you saw, when — nothing else |

The five-step wizard is replaced. **The trade-off is real:** one question at a time is better on a phone, and this is a longer scroll. It is the right call because the paper is what the client knows and the heavy types are filed at a desk — and `picking/IncidentModal`, the mid-pick path, is untouched, so a picker at the shelf is unaffected.

## Testing

| Test | Why |
|---|---|
| An inbound return adds no quarantine deduction at filing | the correctness fix; today it deducts a batch that never held the goods |
| Confirming `restock` on an inbound return **increases** `quantity_on_hand` | the other half — the goods are accepted back |
| Confirming `scrap` on an inbound return changes no counts | nothing was ever counted |
| An outbound (supplier) return behaves exactly as before | the regression guard on the unchanged path |
| `claim_warranty` is offered only when a warranty record is unexpired | the gate |
| Expired and no-record are distinguishable in the payload | not collapsed |
| A `wrong_product` return stores both product ids | the pair |
| A `wrong_quantity` return stores the ordered quantity | the discrepancy is recorded, not implied |
| The evidence checklist for a wrong-delivery return excludes `box_two_sides` and includes the pair | reason-driven, not type-driven |
| `claim_warranty` writes nothing to `warranty_claims` | the boundary holds |
| `claim_warranty` holds the unit; `return_to_supplier` sends it away | the two are not the same outcome and must not share a status |
| `return_to_supplier` is offered regardless of warranty state | only the warranty claim is gated |

Existing incident tests that assert the two-valued `return_direction` or `return_to_supplier` are rewritten to the new vocabulary — never weakened to pass.

## Environment notes for the implementer

- **The schema is a SQL dump, not a migration history.** A bare `php artisan migrate` fails; apply with `--path`.
- **There is no `.env.testing`.** `--env=testing` silently migrates the DEV database. Use `DB_DATABASE=airkingV5_test php artisan <cmd> --force` from `back-end/` in Bash.
- **Never hardcode a status id.** They differ between the two databases. New columns use plain string codes with class constants.
- **Permissions need two seeders** — `PermissionSeeder` creates, `RolePermissionsSeeder` grants. An admin passes structurally via `isAdmin()`, so **test as an inventory_analyst**.
- **The sidebar takes two edits** — `navBundles.js` + `NavBundleSeeder` for the gate, `adminMenuSections` in `Sidebar.jsx` for the item.
- **Never name a test helper `seed()`** — it collides with `TestCase::seed()` and is a hard fatal that aborts the whole suite.
- **`validate()` returns only validated keys**, and that return value reaches the service. An unlisted key is silently dropped, not rejected — every new field needs a rule in `IncidentReportController::store()` **and** a line in `IncidentService::file()`'s explicit column array.
- `Sanctum::actingAs()` 500s here; use `$this->actingAs($user, 'sanctum')`.
- Backend baseline: 9 `WarrantyClaimTest` failures plus the order-dependent `SerialRowMovementColumnsTest`. **These 9 are in the module this spec now integrates with** — do not assume they are unrelated once you touch the warranty read path.
- Frontend baseline: 3 failing suites — `App.test.jsx`, `navConfigureMap`, `InventoryManagement`.
- Verify frontend with `npm run build` **and** `npx eslint` — the build compiles code ESLint rejects.
- **~19 files are dirty** in the working tree, including `airkingv5.sql`. Stage only what each task names; never `git add -A`.
- Quantities are PIECES; `packageFormat.js` re-expresses for display only.
