# Incident Guided Flows — Design

**Date:** 2026-08-10
**Branch:** backupV3
**Status:** Approved, ready for planning
**Builds on:** `2026-08-03-standalone-incident-reporting-design.md`, `2026-08-09-incident-scan-lookup-and-merge-design.md`, `2026-08-09-incident-bulk-quantity-design.md`

## Problem

Two complaints, one root cause.

1. **The Report Incident page leads with the wrong thing.** Opening it from Scan Barcode shows the receiving-correction (Adjust) card first. The incident list — the thing most users came for — is not on that page at all.

2. **Filing is one flat modal for eight different situations.** `IncidentFileModal` is 703 lines that show or hide fields based on the chosen reason. Reporting a theft, re-typing a mislabelled batch and logging a customer return all share one screen, so every field has to be conditionally rendered and the user sees a form that changes shape as they use it. The Adjust flow next door does the opposite and does it well: pick a PO, its lines appear, pick a line, a form opens beneath it. Each step narrows what the next one needs.

## Goals

1. The incident list is the first thing on the page.
2. Filing becomes a guided, stepped flow shaped to what is actually being reported.
3. No duplicated screens, and no regression of the fixes already made in the modal being replaced.

## Non-goals

- Any backend change. No new endpoints, no schema change, no migration. The 75 existing incident tests are a regression check, not a growth target.
- Changing the receiving-correction flow, which stays exactly as it is.
- A computed preview before filing (see Decisions).
- Deep-linkable routes per flow.

## Decisions

| Decision | Choice |
|---|---|
| Number of flows | **Five**, keyed on `outcome` — not eight, one per reason |
| Preview before filing | **No** — the reviewer's confirmation already states the effect |
| Page layout | List first; Report and Correct-a-scan as actions above it |
| Flow surface | **Inline**, replacing the list, with Back to list |
| `IncidentFileModal` | **Deleted**, its parts moved into steps |

### Why five flows, not eight

The eight reasons already group by `outcome`, and the outcome is what actually differs in process:

| Outcome | Reasons | What the flow must ask |
|---|---|---|
| `writeoff` | not_found, theft, damaged, delivery_damage | how many, which batch |
| `retype` | wrong_type | which batch (see below) |
| `relocate` | zone_mismatch | which batch, **and which zone** |
| `restock` | customer_return | how many, **and the original issuance** |
| `none` | other | nothing beyond location and description |

**Where the corrected product is named.** `RetypeResolution::validate()` requires `corrected_product_id`, but that is an option on the **resolve** endpoint — there is no column on `incident_reports` to hold a filer's suggestion, and adding one would mean a migration, a service change, a controller change and a drawer change. So the retype step asks for the batch only, and what the item really is stays the reviewer's call in the resolve drawer, where it already works. The step tells the filer to describe it in the narrative so the reviewer has something to act on. Storing a filer's suggestion is a reasonable follow-up; it is out of scope here.

**Relocate gets a real fix for free.** `actual_zone` *is* accepted by `store()` and read by `RelocateResolution::apply()`, but the current modal never sends it — so a standalone zone-mismatch report resolves to a silent no-op, updating nothing. The relocate step captures it, and fills `expected_zone` from the chosen batch's current `zone_label`, which is what the list's "expected → actual" column has been rendering as "— → —".

Eight flows would mean four identical copies of the write-off one — four places for a fix to be applied and three places to forget. Five also matches the backend exactly: `IncidentService::strategyFor()` already dispatches on outcome, so the UI and the resolution logic stay in step by construction.

### Why no preview at filing

Filing an incident moves no stock. Nothing happens until a reviewer confirms, and that confirmation dialog already spells out the effect (*"writes off 25 pcs · 2 Box"*). A preview at filing would display an effect that may never occur, and would add a step to the fast path a picker uses at the shelf.

## Decomposition

Laying the five flows side by side shows only **one step genuinely differs**:

| Step | writeoff | retype | relocate | restock | none |
|---|---|---|---|---|---|
| 1 What happened | ✓ | ✓ | ✓ | ✓ | ✓ |
| 2 Where & what | ✓ | ✓ | ✓ | ✓ | location only |
| 3 The outcome's own question | ✓ | ✓ | ✓ | ✓ | **skipped** |
| 4 Details | ✓ | ✓ | ✓ | ✓ | ✓ |

So: one wizard owning the shared 80%, with a pluggable step 3.

| File | Responsibility |
|---|---|
| `components/incident/IncidentFlow.jsx` | The step machine — current step, back/next, shared state, submit. Knows nothing about any specific outcome. |
| `components/incident/steps/WhatHappenedStep.jsx` | Grouped reason picker + `occurred_at` |
| `components/incident/steps/WhereAndWhatStep.jsx` | Location + the scan / type / pick item field |
| `components/incident/steps/WriteOffStep.jsx` | Quantity (packs + loose) + batch |
| `components/incident/steps/RetypeStep.jsx` | Batch (required) + a prompt to describe the real item |
| `components/incident/steps/RelocateStep.jsx` | Batch + actual zone |
| `components/incident/steps/RestockStep.jsx` | Quantity + original issuance |
| `components/incident/steps/DetailsStep.jsx` | Narrative + photo + a recap of prior steps |

Step 3 is chosen by `reasonMeta(reason).outcome`; `none` skips it, so `other` is a three-step flow and the rest are four.

**All state lives in `IncidentFlow`** and is passed down. Steps are controlled and hold none of their own, so stepping back and forward loses nothing and the submit payload is assembled in exactly one place.

`IncidentFileModal.jsx` is **deleted**. Both pages render `IncidentFlow` instead.

## Page layout

Opening **Report Incident** from Scan Barcode:

```
Report Incident
[ Report an incident ]  [ Correct a receiving scan ]
─────────────────────────────────────────────────
Open only ▾   All reasons ▾   All locations ▾
┌─────────────────────────────────────────────┐
│ INC-…  Occurred  Reason  Product  Qty  …    │
```

Both buttons are permission-gated — `incident_reports.create` for the first, the existing gate for the second — so an inventory analyst, who cannot use Adjust at all, never sees that button.

Starting a flow **replaces the list inline**, with a persistent "← Back to list". Cancelling or filing returns to the list and refetches it.

The sidebar page (`/admin/incidents`) renders the same list and the same flow, minus the Adjust button. One list component, two entry points.

## Validation

| Step | Required before Next |
|---|---|
| 1 What happened | a reason. `occurred_at` defaults to now, `max` = now |
| 2 Where & what | location; product unless outcome is `none` |
| 3 writeoff | total ≥ 1 piece |
| 3 retype | a batch — `RetypeResolution` refuses to resolve without one |
| 3 relocate | a batch and the actual zone. No quantity: nothing moves |
| 3 restock | total ≥ 1 piece. Issuance optional, searchable |
| 4 Details | narrative. Photo optional |

## Error handling

Errors stay local to the step that caused them. A failed barcode lookup shows on step 2 with the manual picker still usable. A rejected submit shows on step 4 with the entered data intact and the button re-enabled. Nothing bounces the user back to step 1.

Two behaviours carried forward deliberately, both fixes made earlier:

- **Changing location clears a stale scan verdict.** "Matched serial SN-4471 — In Stock" was a claim about the *old* location; leaving it on screen is misinformation on a compliance record.
- **Changing product re-expresses the quantity** in the new product's pack terms, rather than leaving numbers that now mean something different.

## Fixes that MUST survive the deletion

`IncidentFileModal` is being deleted after several rounds of fixes. Each of these must come across, and each is easy to lose silently:

1. **`occurred_at` sent as a UTC instant** (`new Date(local).toISOString()`). Bare local wall-time made the UTC server read a Manila "now" as eight hours in the future, and `before_or_equal:now` rejected **every** filing with a timestamp.
2. **Pack + loose conversion**, with `canSubmit` guarding the **computed total** rather than the raw input — the two differ once pack entry exists.
3. **The scan fallback preferring the lookup response** over the products list, which is capped at 1000. A scanned item outside the cap would otherwise lose its pack size and under-deduct.
4. **Throwing when a photo upload returns no path**, rather than filing without the evidence the user attached while reporting success.
5. **Clearing the scan verdict on location change** (see above).

## Testing

**Backend: unchanged.** No endpoint, schema or service changes. The 75 incident tests must stay green as a regression check — if any moves, this work touched something it should not have.

**Frontend:** no test suite. Verification is `npm run build` plus `npx eslint` on each new file, warning-clean against the known baseline.

**Manual pass — all five flows**, since nothing automated covers them:

1. **Write-off** with pack entry: 1 Box of a 10-per-box consumable → filed quantity is 10 pcs
2. **Re-type**: a batch is required before Next unlocks
3. **Relocate**: no quantity field appears; the filed incident carries `expected_zone` and `actual_zone`, and resolving it actually moves the batch's `zone_label` (today it silently does nothing)
4. **Restock**: issuance search works and is skippable
5. **Other**: step 3 is skipped entirely — three steps, no product required

Plus: Back preserves data on every step; a failed lookup on step 2 leaves the picker usable; the list is the first thing shown on both entry points.

## Related

- Touches `IncidentReportsPage.jsx`, `BarcodeAdjustmentPage.jsx`, creates `components/incident/*`, deletes `IncidentFileModal.jsx`.
- Does **not** touch the backend, `packageFormat.js`, `incidentReasons.js`, `CameraCapture`, `CameraScanner`, `useIncidentPhoto`, `IncidentResolveDrawer`, or `picking/IncidentModal`.

## Environment notes for the implementer

- **No migration.** Schema is a SQL dump, not a migration history — do not run bare `php artisan migrate`.
- Tests use `DatabaseTransactions`, never `RefreshDatabase`. `phpunit.xml` points at `airkingV5_test`.
- The backend suite has **9 known pre-existing `WarrantyClaimTest` failures**, unrelated. A tenth, `SerialRowMovementColumnsTest`, is order-dependent and passes intermittently — treat 9 or 10 as the baseline, but confirm any failure is one of those.
- No frontend test suite; verification is `npm run build` plus `npx eslint`.
- **~20 files are dirty in the working tree** from unrelated earlier work, including `airkingv5.sql`. Stage only what each task names.
- PowerShell here-strings break `git commit -m`; use `git commit -F -`.
- The camera needs a secure context: `http://localhost:3000` on the laptop, `https://<LAN-IP>/` on a phone. Camera permission is per-origin **including the port**.
- Reference: `BarcodeAdjustmentPage.jsx` is the progressive-disclosure pattern being mirrored — context → rows → form, each gated on the previous.
