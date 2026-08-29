# Count-Based Receiving for Installation Materials

**Date:** 2026-08-30
**Status:** Approved for planning

## Problem

Installation materials can only enter or leave stock through a barcode scan. Client
feedback from the warehouse is that deliveries frequently arrive without barcodes —
unpredictably, on both purchase orders and interbranch transfers. Staff already count
these deliveries by hand to verify the quantity against the order, but the system gives
them nowhere to record that count.

Three walls in the current code:

1. `InventoryScanTransactionController::store()` requires `barcode`, and the product is
   resolved through it. No barcode means no receiving.
2. Scanned barcodes are globally one-time-use. Where a manufacturer prints the same
   product code on every box, scan two onward is rejected.
3. `processReceiving()` hard-codes one scan to one package
   (`$quantity = $isSerialized ? 1 : $piecesPerPackage`) and deliberately ignores the
   request's quantity field. A counted number has nowhere to go.

Transfers are gated at both ends: `dispatch()` refuses when `quantity_scanned` is zero,
and `scanReceive()` needs a barcode to resolve the line.

## Constraints established with the client

- **Barcode presence varies by delivery, not by product.** The same item arrives labelled
  from one supplier and bare from another. It cannot be a product-level flag.
- **Counting unit varies by product.** Copper tubing is counted by the roll, bolts by the
  piece. This is stable per product.
- Over-receipt is permitted with explicit confirmation, not hard-rejected.

## Approach

Add a non-scan "receive by count" path alongside the existing scan path, and make pieces
the single source of truth for stock levels. Rejected alternatives: generating and printing
internal barcode labels (requires a label printer, and is absurd for a sack of 500 bolts),
and optional per-lot labels (no evidence the client needs it; additive later).

## Section 1 — Data model

### 1.1 Per-product counting unit

```sql
ALTER TABLE installation_material_details
  ADD COLUMN count_unit ENUM('package','piece') NOT NULL DEFAULT 'package' AFTER package_unit;
```

Set on the product form. Used in exactly one place: converting the clerk's entered number
to pieces. It never affects storage.

### 1.2 Entry mode on the receipt line

```sql
ALTER TABLE receiving_details
  ADD COLUMN entry_mode ENUM('scanned','counted') NOT NULL DEFAULT 'scanned' AFTER scanned_barcode;
```

`scanned_barcode` is already nullable, but "NULL because counted" must be distinguishable
from "NULL because legacy" so the audit trail can answer whether a quantity was verified by
scan or by a human count. Existing rows backfill to `scanned`.

### 1.3 No flag for barcode presence

Deliberately omitted. Barcode presence varies per delivery, so it cannot live on the
product. It is expressed by which endpoint the clerk hits and recorded as `entry_mode`.

### 1.4 Pieces become the single source of truth

Four subsystems currently disagree about which inventory column is authoritative:

| Subsystem | Treats as truth | Derives the other as |
|---|---|---|
| Receiving (`InventoryScanTransactionController:354`) | `packages_on_hand` | `quantity = packages × ppp` |
| Issuance (`IssuanceScanController:479, 891`) | `quantity_on_hand` | `packages = floor(qty / ppp)` |
| Warranty claims (`WarrantyClaimService:250, 400`) | `quantity_on_hand` | `packages = floor(qty / ppp)` |
| Transfers (`TransferService:149, 395`) | `quantity_on_hand` | never updates packages at all |

This is harmless today only because every scan is exactly one whole package, so the columns
cannot drift. A typed count breaks that: counting 473 pieces where `ppp = 100` sets
`packages = 4`, and the next receiving scan recomputes `quantity = 4 × 100 = 400`, silently
destroying 73 pieces.

Add one writer on `Inventory`:

```php
public function setPieces(int $pieces, int $piecesPerPackage): void
{
    $delta = $pieces - (int) $this->quantity_on_hand;

    $this->quantity_on_hand   = $pieces;
    $this->available_quantity = max(0, (int) $this->available_quantity + $delta);
    $this->packages_on_hand   = $piecesPerPackage > 0
        ? intdiv($pieces, $piecesPerPackage)
        : 0;
}
```

Every writer calls it; nobody computes either column by hand. Receiving stops multiplying
packages up. Issuance, warranty claims, and transfers stop doing their own `floor()`.
Transfers begin maintaining `packages_on_hand`, which they have never done.

**`available_quantity` moves by the delta, not by assignment.** Receiving currently sets
`available_quantity = quantity_on_hand` outright (line 356), but issuance, transfers, and
warranty claims all apply *relative* decrements clamped with `max(0, ...)`. Overwriting
would restore phantom availability anywhere the two columns had legitimately diverged.
Adjustments move both columns by the same `$net` and reservations are subtracted at read
time rather than deducted from the column — so divergence is rare, but the `max(0, ...)`
clamps make it reachable. Applying the delta preserves existing semantics at every call
site.

`packages_on_hand` stays in the schema — many read paths and reports use it — but becomes
derived, and correct for the first time.

**Call sites to convert:** `InventoryScanTransactionController` (~line 354),
`IssuanceScanController` (479, 891), `WarrantyClaimService` (250, 400),
`TransferService` (149, 395), `BarcodeAdjustmentController` (323, 364).

## Section 2 — Receiving flow

### 2.1 New endpoint

```
POST /inventory/receive-count
  po_detail_id   required|exists:purchase_order_details,po_detail_id
  location_id    nullable|exists:locations,location_id
  quantity       required|integer|min:1
  zone_label     nullable|string|max:100
  accept_overage nullable|boolean
```

Separate from `store()` rather than a mode flag on it. `store()`'s invariants are all
barcode invariants — the global one-time-use lock, the "quantity is ignored, one scan is
one package" rule — and none apply when counting. Threading a mode flag through
`processReceiving()` would mean guarding roughly a dozen branches in a method already
~250 lines long.

The product is resolved from `po_detail_id`. A precedent exists: `store()` already resolves
the product from `po_detail_id` when present, treating the scanned value as a serial rather
than a product lookup.

### 2.2 Shared posting service

Extract from `processReceiving()` into `ReceivingPostingService::post()`, called by both
paths:

- resolve or create the `receivings` header
- over-receipt check against the PO line
- `Inventory::setPieces()`
- accumulate the `inventory_batches` row
- write `receiving_details`
- `syncPoReceivingStatus()`
- audit trail entry

This is a refactor of working code, justified because it is the only way to guarantee the
two paths cannot drift in how they move stock.

### 2.3 Serialized guard

Before any stock moves:

```php
if ($product->isSerialized()) {
    return rejected('Serialized items must be scanned individually.');
}
```

Without it an appliance could be count-received and lose its serial trail, breaking warranty
lookup and incident reports. This is the one defect here that would be expensive to find late.

### 2.4 Quantity conversion

| `count_unit` | Clerk is asked | Pieces posted |
|---|---|---|
| `package` | "How many boxes?" → 6 | `6 × pieces_per_package` |
| `piece` | "How many pieces?" → 473 | `473` |

Pieces are stored either way; `packages_on_hand` falls out of `setPieces()`.

## Section 3 — Discrepancy handling

Remove the silent clamp at `InventoryScanTransactionController:310`:

```php
$quantity = min($quantity, $remaining);   // discards the excess with no record
```

**This applies to both entry paths.** The check lives in `ReceivingPostingService::post()`,
so scanning an extra package raises the same `over_receipt` response and requires the same
`accept_overage` acknowledgement. Scanning is affected far less often — one scan is one
package, so a clerk must physically scan a surplus box to trigger it — but the behaviour is
deliberately identical, because two different over-receipt rules depending on how stock was
entered would be impossible to explain to an auditor. The frontend must handle the
`over_receipt` response on the scan path as well as the count path.

**Short count** — allowed with no friction. Posts what was counted; the PO stays
*Partially Received* with the remainder open. Already correct; unchanged.

**Over count** — rejected on first submit:

```json
{
  "action": "over_receipt",
  "ordered_pieces": 500,
  "already_received": 450,
  "remaining": 50,
  "counted": 55,
  "overage": 5
}
```

Resubmitting with `accept_overage: true` posts the full 55. This mirrors
`IssuanceOrderController::confirmQuote()`, which rejects with a `shortages` array and
requires `accept_shortages` — reusing an idiom the client's staff already know.

**Verify during implementation:** `syncPoReceivingStatus()` has never seen
`received > ordered`. If it compares with `==`, an over-received PO would never reach
*Completed*.

No column for the overage — it is derivable from `SUM(quantity_amount)` against
`quantity_ordered`, and the audit trail records the `accept_overage` acknowledgement and
the user who gave it.

## Section 4 — Transfers and issuance

### 4.1 Transfers

Add `countShip()` and `countReceive()` to `TransferService`, taking `transfer_detail_id`
plus quantity instead of a barcode. Existing guards are reused unchanged: the
`available_quantity` check on ship, the `quantity_received + step > shipped` check on
receive.

`barcode_scans.barcode` is `NOT NULL` and must become nullable. Counted moves still need a
row there because `removeShipScan`/`removeReceiveScan` reverse an exact amount by looking
it up; without a row, counted lines would be un-undoable. The removal queries key on `id`
rather than `barcode`. Counted rows bypass the duplicate-barcode rejection in
`scanReceive()`, since there is no barcode to collide.

Both `dispatch()` (line 149) and the receive side (line 395) adopt `setPieces()`.

### 4.2 Issuance

`scanItem` already handles quantity and units for consumables, including roll fractions, and
needs the barcode only to identify the product. Add a `pickItem` path taking `product_id`
directly and running identical downstream logic.
`IssuanceOrderController::addCustomLine` already establishes a non-scan route into an
issuance; `pickItem` follows it.

### 4.3 FIFO

`FifoPickingService` picks non-serialized stock from `inventory_batches` by `received_at`
with no reference to serials or barcodes. Count-received stock creates ordinary batch rows,
so FIFO works on it unchanged. Batch-level provenance survives; unit-level does not, which
is the accepted trade of this approach.

**Known issue, out of scope:** `FifoPickingService::isSerialized()` reads
`applianceDetail?->is_serialized` even for installation materials, where the flag lives on
`consumableDetail`. It returns the correct `false` for materials by accident (null relation)
rather than by intent. Left alone deliberately; noted because it will bite if a material is
ever marked serialized.

## Section 5 — UX

Option A, escape hatch. The line opens in scan mode as today; a secondary
**"Enter count instead"** button sits beside Scan on the active-line panel.

The receiving screen already makes clerks select a PO line (`bsrActiveLine` /
`po_detail_id`) before scanning, so the count path slots into an existing step rather than
adding one.

Rejected: an upfront per-line "has barcodes / no barcodes" choice. It taxes every line,
including the barcoded majority, to make the rare case explicit — and since `entry_mode`
records the decision either way, its auditability advantage is small.

Count entry prompts according to the product's `count_unit` and shows live remaining
feedback, so a short delivery is caught while counting. That feedback is the point of the
feature.

## Error handling

Count posts run inside the existing `DB::transaction` with `lockForUpdate()` on the
inventory row, identical to scans, so concurrent clerks on the same line serialize rather
than double-post. Rejections return the existing `action: rejected` envelope, so current
frontend error rendering works unchanged.

## Testing

New directory `back-end/tests/Feature/Receiving/`, following the existing domain layout
(`Aircon/`, `Warranty/`, `Incidents/`, `Transfer/`). `TransferServiceTest.php` already
creates products with `is_serialized => false` and provides a fixture pattern to follow.

- **Packages/pieces regression** — count-receive 473 pieces with `ppp = 100`; assert
  `quantity_on_hand = 473` and `packages_on_hand = 4`. Post a second receipt; assert the
  total is 573, not 500. This test fails against current code, which is why it is written
  first.
- **Serialized guard** — count-receive against an appliance PO line is rejected and moves
  no stock.
- **Over-receipt** — 55 counted against 50 remaining returns `action: over_receipt` and
  posts nothing; resubmission with `accept_overage` posts 55. Assert
  `syncPoReceivingStatus()` still reaches *Completed* when received exceeds ordered.
- **Mixed mode on one line** — scan 3 packages, count 2 more; assert totals agree and both
  `entry_mode` values are present.
- **Transfers** — count-ship then count-receive an unbarcoded line end to end; assert
  source and destination `packages_on_hand` are both correct. Fails today.
- **Undo** — remove a counted transfer scan; assert the exact quantity reverses, covering
  the nullable-`barcode` change.

## Migrations

1. `installation_material_details.count_unit`
2. `receiving_details.entry_mode` (backfill existing rows to `scanned`)
3. `barcode_scans.barcode` → nullable

## Out of scope

- Internal barcode label generation and printing (Approach 3). Additive later; nothing here
  forecloses it.
- The `FifoPickingService::isSerialized()` inconsistency in 4.3.
- Any change to appliance receiving, which stays scan-only by design.
