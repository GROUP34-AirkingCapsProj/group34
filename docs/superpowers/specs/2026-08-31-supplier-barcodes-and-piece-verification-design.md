# Supplier Barcodes and Per-Package Piece Verification

**Date:** 2026-08-31
**Status:** Approved for planning
**Builds on:** `2026-08-30-installation-material-hybrid-barcode-fifo-design.md`

## Problem

Two things are wrong with installation-material receiving as shipped.

**1. Generated tags are being minted for goods that already have a barcode.**
`MaterialTagService::mintForScan()` creates an `INT-…` serial for every scanned
material and stores the supplier's code alongside it. The client's rule is simpler:
a generated barcode exists *only* because an item arrived without one. If the user
scanned a supplier barcode, that code is the identifier and a second invented one is
noise.

**2. Package contents are trusted, never verified.**
A package-counted receipt multiplies packages by `pieces_per_package` and posts the
result. A box of copper tube marked 50 that actually contains 47 posts 50, and the
three-piece shortfall vanishes. In the client's words: *"packages are not the only
[thing] to check but also the number inside of that box or package to really avoid
discrepancy."*

The PO cannot currently express the difference either. Ordering 10 boxes (500 pieces)
and receiving 6 boxes holding 297 reads as *297 of 500* — identical to four boxes
never arriving. One is a delivery problem, the other a packing problem, and they are
different conversations with a supplier.

## Decisions taken with the client

- A scanned material keeps an `item_serial` row, with the **scanned code as its
  serial**, so it still resolves to its batch for FIFO. (Not: no row at all.)
- The clerk enters **packages, then total actual pieces**, pre-filled with the
  computed expectation. (Not: a count per box; not: an opt-in toggle.)
- The expectation is **computed from the item master**, never typed.
- The expected figure is **recorded**, so a short-filled box is distinguishable from
  a missing one.
- Verification applies to **both** entry paths — a labelled box can be short-filled
  too.
- Scanning is verified **once per line**, not per scan.

## Section 1 — Data model

### 1.1 Two columns on `receiving_details`

```sql
packages_received  INT NULL   -- packages this line represents
pieces_expected    INT NULL   -- packages_received × pieces_per_package, frozen at receipt
```

`quantity_amount` keeps its meaning: pieces actually posted. The shortfall is
`pieces_expected − quantity_amount`.

Both nullable, because they only mean something for package-counted materials. A
piece-counted line has no package to verify — the clerk counts pieces directly, so
expected and actual are the same by definition. Appliance lines store nulls.

**`pieces_expected` is stored, not recomputed.** If a product's
`pieces_per_package` is later edited — a supplier moving from 50 to 40 per box —
recomputing would silently rewrite every historical expectation and erase past
discrepancies. Storing freezes what was true on the day.

### 1.2 Tag provenance

`mintForScan()` stops generating an `INT-` serial. Rows become:

| Arrived | `serial_number` | `supplier_barcode` |
|---|---|---|
| With a barcode, scanned | the scanned code | the same code |
| Without one, counted | `INT-…` generated | `null` |
| Appliance | the scanned serial | `null` |

Populating `supplier_barcode` on the scanned-material row is deliberate redundancy:
`supplier_barcode IS NOT NULL` becomes the honest test for *"this identity came from
the supplier"*. The alternative — matching an `INT-` prefix — string-matches a format
we control and could change.

**Duplicate supplier codes need no new constraint.** Ten identical boxes usually carry
one printed product code, and `serial_number` is globally unique, so ten rows cannot
exist. The scan path already refuses a second scan of the same barcode (the
one-time-use lock), so identical-code boxes were never receivable by scanning. They go
through the count path and get generated tags — which is exactly the intended rule.

## Section 2 — The verification step

### 2.1 Count path

Two inputs where there was one:

```
How many Boxes?            [ 6 ]
Pieces actually counted    [ 300 ]   ← pre-filled from the item master, editable
                           3 pcs short of 300 expected
```

Posts `quantity_amount = 297`, `packages_received = 6`, `pieces_expected = 300`.

Piece-counted products keep the single field and store nulls in both new columns.

### 2.2 Scan path

Scanning posts each box immediately, so by the time contents are counted the goods are
already booked. Verification here is therefore a **reconciliation**, not a first write.

New endpoint:

```
POST /inventory/receive-verify
  po_detail_id      required|integer
  location_id       nullable
  packages          required|integer|min:1
  actual_pieces     required|integer|min:0
```

It sums what is already posted for that line on this receipt, computes the delta,
applies it through `Inventory::setPieces()` and the batch's `quantity_remaining` — the
same writers every other path uses — and records `packages_received` and
`pieces_expected`.

**This is two mechanisms, not one, and that is accepted deliberately.** The count path
records truth up front; the scan path books provisionally and corrects. They converge
on the same stored result and present the same two-field step to the clerk. The trade
buys an untouched high-volume scanning rhythm, and "book it in, then reconcile" is how
the warehouse already works — `BarcodeAdjustmentController` exists for exactly that
shape of correction.

## Section 3 — Surfacing the discrepancy

**At entry**, live, while the boxes are still open — the panel shows
`3 pcs short of 300 expected` as the clerk types.

**On the stock-in row**, a sub-line under the quantity, shown only on a mismatch:

```
Qty
297
300 expected · 3 short
```

**Over-filled boxes** read `5 pcs more than expected`. Not blocking — the goods are
physically present and refusing them helps nobody — but recorded. This is independent
of the existing PO over-receipt confirmation, which compares against what was
*ordered*; a box can be over-filled while its line is still under the ordered total.

**On the PO**, nothing changes. 297 posted leaves 3 outstanding and the line stays
*Partially Received*. That is the point: the shortfall becomes a real partial instead
of a silent absorption.

**Out of scope: a supplier discrepancy report.** The columns make it reportable, but
the client has not asked for one. Better that they use this and say what they want to
see than that we guess at a dashboard now.

## Section 4 — Migration, failure cases, tests

### 4.1 Migrations

1. `receiving_details.packages_received` and `.pieces_expected`, nullable, with the
   house `Schema::hasColumn` guard and a real `down()`.
2. A data migration rewriting the tags minted for scanned items (5 rows at time of
   writing): `serial_number` becomes the `supplier_barcode` the row already carries,
   and the `INT-` value is discarded. **`supplier_barcode` is left populated** — after
   the rewrite both columns hold the same code, which is the shape §1.2 defines for a
   scanned material. Nothing is deleted; `batch_id` is preserved, so FIFO and the
   receiving trail survive.

**Apply both to both databases** — `php artisan migrate --force`, then
`DB_DATABASE=airkingV5_test php artisan migrate --force`. Skipping the second produces
a migration reporting DONE while its tests fail on a missing column; this has already
cost time twice.

**Collision guard.** `serial_number` is globally unique. If a supplier code already
exists as a serial elsewhere, the rewrite would collide — those rows are **left as
they are and logged**, rather than failing the migration halfway. A few stragglers
keeping an `INT-` serial is a far better outcome than a partially applied migration.

### 4.2 Failure cases for `receive-verify`

- **Nothing posted for the line** → refuse; there is nothing to reconcile and the
  clerk wants the count panel.
- **Reconciliation below zero** → refuse. If stock has already been issued from the
  batch, reducing further would strand negative quantity; that needs an adjustment,
  not a verification.
- **Serialized product** → refuse. An appliance is one unit with a serial and has no
  package contents.
- **Concurrency** → `lockForUpdate()` on the inventory row inside a transaction, the
  same discipline every other write path uses.
- **Reconciling upward past the PO total** → an over-filled box can push a line beyond
  what was ordered. `receive-verify` runs the same `ReceivingPostingService::checkOverage()`
  the other paths use and returns the identical `over_receipt` payload, requiring
  `accept_overage` to proceed. Two different over-receipt rules depending on how stock
  was entered would be impossible to explain to an auditor — the same reasoning that
  put the check in the shared posting service to begin with. The endpoint therefore
  also accepts `accept_overage` (nullable, boolean).

### 4.3 Tests

Backend, `tests/Feature/Inventory/`, house pattern (`DatabaseTransactions`, inline
models with `uniqid()`, no factories):

- a scanned material's serial **is** the supplier code, with no `INT-` tag minted —
  the regression guard for the client's rule
- a counted material still gets its generated tags
- the count path stores `packages_received` and `pieces_expected` and posts the
  **actual**, not the expected
- a short count leaves the PO line *Partially Received* with the right remainder
- an over-filled line records the surplus without blocking
- `receive-verify` reduces inventory **and** the batch by exactly the delta
- `receive-verify` refuses serialized products, empty lines, and below-zero
  reconciliation
- piece-counted products store nulls in both new columns
- the migration rewrites tags and **skips collisions** rather than failing

Frontend: the second field appears for package-counted products and not for
piece-counted ones; it pre-fills with the computed expectation; the short/over message
renders; the stock-in sub-line appears only on a mismatch.

`@testing-library/user-event` is pinned at 13.5.0 — no `.setup()` API.

## Knock-on effects to check during implementation

- `AttachesGeneratedTags` groups stock-in rows on
  `(receiving_id, product_id, supplier_barcode)`. Once scanned materials no longer
  carry an `INT-` serial, that grouping still holds, but the *display* of a scanned
  row changes — it now shows the supplier code as its own serial rather than a
  generated tag. Verify the "shared across N lines" caveat still reads correctly.
- The Serial Numbers tab's Digital Barcode column renders a barcode for
  `is_material_tag` rows. A scanned material is still a material row but no longer
  carries a generated tag. **Decision: it renders a scannable barcode of its supplier
  code, and it is not printable.** Scannable because that code is the identifier and
  scanning off the screen is the whole point when a physical label is damaged; not
  printable because the supplier already printed it, and reprinting someone else's
  barcode invites two labels of differing provenance on one box.
- `MaterialTagSheet` prints generated tags only. Scanned materials are excluded from
  it, consistent with the decision above. Gate on `supplier_barcode IS NULL` rather
  than an `INT-` prefix match.
