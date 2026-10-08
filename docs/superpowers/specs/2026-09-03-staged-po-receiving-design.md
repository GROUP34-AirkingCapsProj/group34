# Staged PO Receiving — Design

**Date:** 2026-09-03
**Status:** Approved, ready for planning

## Problem

Every scan and every count in the PO receiving module posts to inventory
immediately. `inventoryAPI.scanTransaction` and `inventoryAPI.receiveCount` both
land in `ReceivingPostingService::post()`, which in one transaction writes the
inventory row, accumulates the batch, creates a `receiving_details` row, and
mints the serial or lot tags.

There is no undo. No void, no reverse, no delete on a receiving detail exists
anywhere in the codebase.

The mistake this enables is concrete. In `BarcodeScan.jsx`, the product being
received is resolved from `bsrActiveLine` — the PO line selected in the left
panel. Select the wrong line, scan a TCL aircon, and it posts against a bulk
material line: wrong product, wrong unit maths, wrong tag type, and permanent.
The clerk has no way to notice before it is committed and no way to fix it
after.

## Solution

Scanning and counting stage rows into a server-side draft. Nothing touches
inventory until the clerk reviews the list and presses **Submit to inventory**.
Before that point the clerk can remove a wrong row or correct a quantity.

The write path that moves stock does not change. Submit replays each staged row
through the existing `ReceivingPostingService::post()`.

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Draft storage | Server-side tables | Survives a crash or closed tab; a half-counted truckload is not lost. Visible to other clerks on the same PO. |
| Submit scope | One delivery, all lines | One Submit posts the whole truck. A later delivery for the same PO opens a fresh draft. Matches how goods arrive. |
| Edit powers | Remove a row; adjust its quantity | Covers both real failure modes — wrong line, and mistyped count — without letting a row's product change underneath its piece maths. |
| Verify / top-up endpoints | Keep, unchanged | Inside a draft, packages and actual pieces are editable fields, so no correction endpoint is needed. `receiveVerify` / `receiveTopUp` remain for receipts already submitted. Nothing regresses. |
| Stale drafts | Visible, never auto-acted-on | A badge on the PO and a resume prompt on the receiving screen. Never auto-posted, never auto-deleted; a human always decides. |
| Submit failure | All-or-nothing | Nothing posts, the draft survives with offending rows flagged. No half-posted truck to reconcile against the delivery note. |

## Scope

**In scope:** PO receiving (`receive-po` mode in `BarcodeScan.jsx`), both entry
paths — barcode scan and hand count.

**Out of scope:** PO-less receiving. The `whereNull('po_id')` daily-header path
in `ReceivingPostingService::resolveReceiving` keeps posting immediately. The
request was the PO receiving module, and including ad-hoc receiving would double
the surface area for no stated need.

**Not changed:** `ReceivingPostingService`, `ReceiveVerifyController`,
`ReceiveTopUpController`, `CountEntryPanel` (presentational), the receipt and
history views.

## Data model

### `receiving_drafts`

One open draft per PO + warehouse, representing one delivery. The "one" is over
`status = open` only — submitted and discarded drafts persist as history, and a
PO accumulates one per delivery over its life.

| Column | Type | Notes |
|---|---|---|
| `draft_id` | PK | |
| `po_id` | FK purchase_orders, required | scope is PO receiving |
| `location_id` | FK locations | the receiving warehouse |
| `opened_by` | FK users, nullable | who started staging |
| `status` | enum `open`/`submitted`/`discarded`, default `open` | |
| `receiving_id` | FK receivings, nullable | set on submit — links a draft to the receipt it produced |
| `zone_label` | string, nullable | put-away zone for the whole drop |
| `submitted_at` | timestamp, nullable | |
| `discarded_at` | timestamp, nullable | |
| timestamps | | |

### `receiving_draft_items`

One row per scan or per count entry.

| Column | Type | Notes |
|---|---|---|
| `draft_item_id` | PK | |
| `draft_id` | FK, cascade delete | |
| `product_id` | FK products | |
| `po_detail_id` | FK purchase_order_details, nullable | the line being received against |
| `entry_mode` | enum `scanned`/`counted` | |
| `scanned_barcode` | string, nullable | |
| `packages_received` | int, nullable | package-counted materials only |
| `pieces_expected` | int, nullable | package-counted materials only |
| `quantity_amount` | int | pieces this row will post |
| `contents_verified` | boolean | |
| `units_entered` | int, default 1 | |
| timestamps | | |

The item columns are a deliberate 1:1 mirror of `ReceivingPostingService::post()`'s
parameters. Submit is then a loop that reads a row and spreads it into the call,
with no translation layer to get wrong. The one exception is `zone_label`, which
lives on the header because a delivery is put away in one zone; every item in a
draft inherits the header's value when `post()` is called.

Migrations follow the existing receiving convention: a guarded `up()` that
returns early when the column or table already exists.

### Duplicate serials

Two clerks in two drafts could each stage serial `TCL-889`. A unique index does
not solve this — MySQL has no partial index, so "unique among open drafts only"
is not expressible, and a plain unique on `scanned_barcode` would wrongly reject
re-receiving a code after its draft was discarded.

- **At stage time:** check the code against `item_serial` and against items in
  open drafts, and reject the row with the same modal shown today. This catches
  effectively every real case and gives instant feedback.
- **At submit:** the existing global `item_serial_serial_number_unique` key is
  the actual guarantee. A genuine race throws inside the transaction, the whole
  submit rolls back, and the clerk is told which row collided. Nothing
  half-posts.

## API

All routes join the existing `permission:barcode_inventory_processing,create`
group in `routes/api.php` — staging is the same privilege as receiving.

```
GET    /api/receiving-drafts?po_id=&location_id=   the open draft + its items, or none
POST   /api/receiving-drafts                       open a draft for this PO+warehouse
POST   /api/receiving-drafts/{draft}/items         stage one scan or one count
PATCH  /api/receiving-drafts/{draft}/items/{item}  adjust packages / actual pieces
DELETE /api/receiving-drafts/{draft}/items/{item}  remove a wrong row
DELETE /api/receiving-drafts/{draft}               discard the whole draft
POST   /api/receiving-drafts/{draft}/submit        post everything to inventory
```

## Components

**Models:** `ReceivingDraft`, `ReceivingDraftItem`.

**`ReceivingDraftController`** — the staging CRUD. Owns stage-time validation.
It never touches inventory, which is what makes a bug in this layer incapable of
corrupting stock.

**`ReceivingDraftSubmitService`** — owns the replay, and is the only new code
that can move stock:

1. Open one transaction.
2. `lockForUpdate()` the `inventory` rows for every distinct product+location in
   the draft, satisfying `ReceivingPostingService`'s documented transaction
   contract.
3. Loop the items, calling `checkOverage()` then `post()` per row, exactly as
   the scan endpoint does today. Because `checkOverage` sums live
   `receiving_details`, row 2 automatically accounts for what row 1 just posted;
   accumulation works without extra bookkeeping.
4. Mark the draft `submitted`, stamp `receiving_id`, commit.
5. Return the receipt and every minted tag.

## Frontend

All changes are in `BarcodeScan.jsx`'s `receive-po` mode.

- `handleBsrScan` and `handleBsrCount` call the stage endpoint instead of
  `scanTransaction` / `receiveCount`. Their existing pre-checks stay where they
  are.
- `bsrScannedItems` stops being a display log and becomes the draft's item list,
  loaded from the server, with a Remove action per row and an editable
  package/piece count.
- A **"Submit N items to inventory"** button is the only control that moves
  stock.
- Line progress shows two figures — pieces already posted (from `poScanStatus`,
  unchanged) and pieces staged in this draft — visually distinct, so committed
  and pending are never confused.
- Entering `receive-po` for a PO with an open draft resumes it and shows an
  "Unsubmitted delivery — N items staged" badge.
- The copy at `BarcodeScan.jsx:4892` — *"Each accepted scan posts receiving on
  the server"* — becomes false and must be rewritten.

### Tag printing moves

Today a scan mints its tag immediately and `bsrLastTags` offers it for printing.
Tags cannot exist before submit, so printing happens once after submit, for the
whole delivery — one sheet per truck instead of a label per scan. This is a
deliberate workflow change, recorded here rather than left as a surprise.

## Validation

Two tiers. Stage-time checks exist for fast feedback; submit-time checks exist
for correctness. Every rule that protects data is enforced at submit regardless
of whether it also warns earlier.

| Check | Stage time | Submit time |
|---|---|---|
| Barcode already in `item_serial` | warn + reject row | UNIQUE key throws |
| Barcode already staged in an open draft | warn + reject row | n/a — a row cannot collide with itself |
| Product not on this PO | reject row | rejected |
| Package / piece ceiling (`checkOverage`) | **warn only, never block** | authoritative — blocks |
| Serialized product with no barcode | reject row | `post()` throws |
| Line already fully received | warn | blocked by `checkOverage` |

The overage row is the important one. Blocking an overage at stage time would
defeat the feature: the clerk must be able to stage what is physically on the
floor, see that it is three boxes over, and then decide. Staging is where you
describe reality; submit is where the system judges it.

## Error handling

Submit is all-or-nothing. The service collects failures for **every** offending
row before rolling back — not just the first — so the clerk receives one
complete list to fix rather than discovering problems one submit at a time.

The response identifies rows by `draft_item_id` with a plain-language reason
(for example, `"line no longer has room — 3 boxes over"`). The draft is returned
untouched with those rows flagged in the UI.

Receiving a genuine overage must remain possible — today's `accept_overage` flag
is how a clerk accepts more than was ordered, and dropping it would make legitimate
over-deliveries unreceivable. It is preserved as a flag on the submit request.
This is not the inline "confirm and push through" dialog: the failed submit still
returns to the draft, and the clerk re-submits deliberately with the flag set,
after seeing the full list of what is over.

## Testing

Following the existing `back-end/tests/Feature/Inventory/` pattern.

- **Staging moves nothing.** Stage a full truckload; assert `inventory`,
  `inventory_batches`, `receiving_details` and `item_serial` are all completely
  unchanged. This is the feature's central claim and deserves the bluntest
  possible test.
- **Submit posts identically to today.** Stage N items and submit; assert the
  resulting inventory, batch, detail rows and tags match what the same N scans
  produce through `scanTransaction`. This is the regression guard for the
  design's core premise.
- **The TCL case.** Stage an aircon against a bulk line, remove the row,
  re-stage against the appliance line, submit; assert only the appliance posted
  and no bulk stock exists.
- **Rollback is total.** Stage 5 valid rows plus 1 that overruns the line;
  submit; assert nothing posted, the draft is still `open`, and all 6 items
  survive.
- **Duplicate serial race.** Two drafts stage the same serial and both submit;
  assert the second fails cleanly and posts nothing.
- **Resume.** Open a draft, stage, reload; assert items and progress return.
- **Frontend.** `CountEntryPanel` tests still hold — it is presentational and
  unchanged. New tests cover the staged-list remove and edit actions.
