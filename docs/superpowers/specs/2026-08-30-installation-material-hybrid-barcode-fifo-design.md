# Hybrid Barcode Tracking and Strict FIFO for Installation Materials

**Date:** 2026-08-30
**Status:** Approved for planning
**Supersedes:** the count-only draft committed earlier the same day (`6632afd`)

## Problem

Installation materials can only enter or leave stock through a barcode scan. Client
feedback from the warehouse is that deliveries frequently arrive without barcodes —
unpredictably, on both purchase orders and interbranch transfers. Staff already count
these deliveries by hand to verify quantity against the order, but the system gives them
nowhere to record that count.

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

Separately, **FIFO is only ledger-deep.** `InventoryBatchService::deductFifo()` consumes
batches oldest-first, so the books are always FIFO, but nothing checks which physical item
the picker actually took. There is no way to enforce it, because there is no way to tell
which batch an untagged item came from.

## Constraints established with the client

- **Barcode presence varies by delivery, not by product.** The same item arrives labelled
  from one supplier and bare from another. It cannot be a product-level flag.
- **Counting unit varies by product.** Copper tubing is counted by the roll, bolts by the
  piece. This is stable per product.
- Over-receipt is permitted with explicit confirmation, not hard-rejected.
- **Every received item gets a system-generated digital tag**, whether or not it arrived
  with a supplier barcode, so staff can always scan something — from the screen if the
  physical label is missing, damaged, or unreadable.
- **Stock-out enforces strict FIFO by PO receiving order**, blocking issues from a newer
  batch while an older batch of the same material remains available.

## Approach

Add a non-scan "receive by count" path alongside the existing scan path; generate a digital
tag for everything received; make pieces the single source of truth for stock levels; and
enforce FIFO at stock-out using the batch each tag resolves to.

Rejected: tagging every individual piece (473 tags for a bag of bolts), and leaving FIFO
ledger-only (the client explicitly wants physical enforcement).

### Organising principle

**Tags identify, batches account.** A tag names a physical thing and resolves to exactly one
batch. It carries no quantity of its own; quantity lives in
`inventory_batches.quantity_remaining`. If tags also held counts there would be two
competing truths again — the precise bug class Section 1.4 removes.

## Section 1 — Data model

### 1.1 Per-product counting unit

```sql
ALTER TABLE installation_material_details
  ADD COLUMN count_unit ENUM('package','piece') NOT NULL DEFAULT 'package' AFTER package_unit;
```

Set on the product form. Drives two things and nothing else: what the receiving screen asks
for, and how many tags a receipt line generates (§1.5). It never affects storage.

### 1.2 Entry mode on the receipt line

```sql
ALTER TABLE receiving_details
  ADD COLUMN entry_mode ENUM('scanned','counted') NOT NULL DEFAULT 'scanned' AFTER scanned_barcode;
```

Records whether the *quantity* was verified by scanning supplier codes or by a human count.
This stays a distinct question from whether a digital tag exists, since under this design a
tag always exists. Existing rows backfill to `scanned`.

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

Harmless today only because every scan is exactly one whole package, so the columns cannot
drift. A typed count breaks that: counting 473 pieces where `ppp = 100` sets `packages = 4`,
and the next receiving scan recomputes `quantity = 4 × 100 = 400`, destroying 73 pieces.

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

**`available_quantity` moves by the delta, not by assignment.** Receiving currently sets
`available_quantity = quantity_on_hand` outright (line 356), but issuance, transfers, and
warranty claims all apply *relative* decrements clamped with `max(0, ...)`. Overwriting
would restore phantom availability anywhere the two columns had legitimately diverged.
Adjustments move both columns by the same `$net`, and reservations and quarantine are
subtracted at read time rather than deducted from the column — so divergence is rare, but
the `max(0, ...)` clamps make it reachable. Applying the delta preserves existing semantics
at every call site.

`packages_on_hand` stays in the schema — many read paths and reports use it — but becomes
derived, and correct for the first time.

**Call sites to convert:** `InventoryScanTransactionController` (~354),
`IssuanceScanController` (479, 891), `WarrantyClaimService` (250, 400),
`TransferService` (149, 395), `BarcodeAdjustmentController` (323, 364).

### 1.5 Digital tags

Tags reuse `item_serial`, which already has a global `UNIQUE` on `serial_number`, already
holds consumable rows, and already links `receiving_id`, `transfer_id`, and `location_id`.

```sql
ALTER TABLE item_serial
  ADD COLUMN batch_id         BIGINT UNSIGNED NULL AFTER receiving_id,
  ADD COLUMN supplier_barcode VARCHAR(191)    NULL AFTER serial_number,
  ADD CONSTRAINT item_serial_batch_id_foreign
      FOREIGN KEY (batch_id) REFERENCES inventory_batches(batch_id) ON DELETE SET NULL,
  ADD KEY item_serial_supplier_barcode_idx (supplier_barcode);
```

`batch_id` is explicit rather than derived through `receiving_id`, because transfer-created
batches have a null `receiving_id` and the derivation would silently fail there.

**Hybrid identity.** An item arriving *with* a supplier barcode gets **one row carrying both
identifiers** — the generated `INT-…` serial and the supplier's code in `supplier_barcode`.
Scanning either resolves to the same item. Two rows would double-count against the batch.

**Granularity depends on the entry path:**

*Count path* — follows `count_unit`:

| `count_unit` | Tags per receipt line | Example |
|---|---|---|
| `package` | one per package | 6 boxes → `INT-PO2401-4471-01`…`-06` |
| `piece` | one per lot | 473 bolts → `INT-PO2401-4472-01` |

*Scan path* — **always one tag per scan, regardless of `count_unit`.** A scanned physical
item is already individually identified by its supplier barcode, so it gets its own tag
paired with that barcode in the same row. `count_unit` governs only how the count path
groups untagged goods; it must not be applied to the scan path, or a supplier barcode would
have no tag of its own to pair with.

This means a single PO line received partly by scan and partly by count produces tags of
both granularities. That is correct and intended — each tag still resolves to exactly one
batch, which is all FIFO enforcement requires.

**Serial format**, covering the non-PO cases (receiving supports `po_id = 0`; transfers have
no PO):

```
PO receiving      INT-{po_number}-{product_id}-{seq}
Non-PO receiving  INT-RCV{receiving_number}-{product_id}-{seq}
Transfer receipt  INT-IST{transfer_number}-{product_id}-{seq}
```

Generated inside the posting transaction, with the DB `UNIQUE` constraint as the real
guarantee and a retry on collision — not a PHP-side uniqueness check, which races.

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
barcode invariants — the global one-time-use lock, the "quantity is ignored, one scan is one
package" rule — and none apply when counting. Threading a mode flag through
`processReceiving()` would mean guarding roughly a dozen branches in a method already ~250
lines long.

The product resolves from `po_detail_id`. Precedent exists: `store()` already resolves the
product from `po_detail_id` when present, treating the scanned value as a serial rather than
a product lookup.

### 2.2 Shared posting service

Extract from `processReceiving()` into `ReceivingPostingService::post()`, called by both
paths:

- resolve or create the `receivings` header
- over-receipt check against the PO line
- `Inventory::setPieces()`
- accumulate the `inventory_batches` row
- **generate digital tags per §1.5 and link them to the batch**
- write `receiving_details`
- `syncPoReceivingStatus()`
- audit trail entry

A refactor of working code, justified because it is the only way to guarantee the two paths
cannot drift in how they move stock or issue tags.

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
so scanning an extra package raises the same response and requires the same acknowledgement.
Scanning triggers it far less often — one scan is one package, so a clerk must physically
scan a surplus box — but the behaviour is deliberately identical, because two different
over-receipt rules depending on how stock was entered would be impossible to explain to an
auditor. The frontend must handle `over_receipt` on the scan path as well as the count path.

**Short count** — allowed with no friction. Posts what was counted; the PO stays *Partially
Received* with the remainder open. Already correct; unchanged.

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

No column for the overage — derivable from `SUM(quantity_amount)` against
`quantity_ordered`, and the audit trail records the acknowledgement and the user who gave it.

## Section 4 — Transfers and issuance

### 4.1 Transfers

Add `countShip()` and `countReceive()` to `TransferService`, taking `transfer_detail_id`
plus quantity instead of a barcode. Existing guards are reused unchanged: the
`available_quantity` check on ship, the `quantity_received + step > shipped` check on receive.

`barcode_scans.barcode` is `NOT NULL` and must become nullable. Counted moves still need a
row there because `removeShipScan`/`removeReceiveScan` reverse an exact amount by looking it
up; without a row, counted lines would be un-undoable. The removal queries key on `id`
rather than `barcode`. Counted rows bypass the duplicate-barcode rejection in
`scanReceive()`, since there is no barcode to collide.

Both `dispatch()` (line 149) and the receive side (line 395) adopt `setPieces()`.

Transfer receipts generate tags per §1.5 using the `INT-IST…` format, linked to the
destination batch.

### 4.2 Issuance

`scanItem` already handles quantity and units for consumables, including roll fractions, and
needs the barcode only to identify the product. Add a `pickItem` path taking `product_id`
directly and running identical downstream logic.
`IssuanceOrderController::addCustomLine` already establishes a non-scan route into an
issuance; `pickItem` follows it.

Barcode resolution at the top of `scanItem` must check `item_serial.serial_number` **and**
`item_serial.supplier_barcode`.

### 4.3 FIFO picking

`FifoPickingService` picks non-serialized stock from `inventory_batches` by `received_at`
with no reference to serials or barcodes, and continues to work unchanged. Section 5 adds
enforcement on top of it, not in place of it.

**Known issue, out of scope:** `FifoPickingService::isSerialized()` reads
`applianceDetail?->is_serialized` even for installation materials, where the flag lives on
`consumableDetail`. It returns the correct `false` for materials by accident (null relation)
rather than by intent. Left alone deliberately; noted because it will bite if a material is
ever marked serialized.

## Section 5 — Strict FIFO enforcement

New `FifoEnforcementService::check(productId, locationId, scannedBatchId)`:

1. Resolve the tag or supplier barcode to `item_serial` → `batch_id`.
2. Find the oldest **eligible** batch for that product and location.
3. If it is not the scanned batch, reject.

**Enforcement applies to the scan path only.** The `pickItem` path (§4.2) identifies a
product without naming a physical item, so there is no scanned batch to compare — it
delegates to `deductFifo()`, which already consumes oldest-first and therefore cannot
violate FIFO by construction. Calling `check()` there would have nothing to check.

This is a deliberate asymmetry worth stating plainly: `pickItem` is a way to issue stock
without proving which physical item was taken. It is the correct fallback for goods whose
tag is lost or unreadable, but it is also a route around physical FIFO enforcement. It
should carry the same permission gate as the override (§5.3) rather than being freely
available, or the enforcement is decorative.

`inventory_batches` already carries a `(product_id, location_id, received_at)` index, so
this is one indexed query.

### 5.1 Eligibility — three deadlock traps

| Trap | What goes wrong | Handling |
|---|---|---|
| **Legacy batch** | `legacyBatch()` is a catch-all with `receiving_id NULL` and `received_at NULL`. MySQL sorts NULL first on ASC, so it is always "oldest" — and no tag can point at it, so no scan satisfies the rule. Every issuance of every material blocks forever. | Exclude `receiving_id IS NULL`, exactly as `deductFifo()` already does. |
| **Quarantined oldest** | Stock under an unsettled incident stays physically present and counted but must not be issued. As the oldest batch it blocks everything behind it. | New `IncidentItem::quarantinedByBatch(locationId)` returning `batch_id => pieces`. The data already exists via `incident_items.batch_id` with a `(status, batch_id)` index; today it is aggregated away by product in `quarantinedQuantities()`. Eligible means `quantity_remaining − quarantined > 0`. |
| **Tied timestamps** | Two batches received in the same second order non-deterministically, so "the oldest" flips between queries and the clerk gets a violation no scan can satisfy. | Tie-break on `batch_id ASC`. |

### 5.2 Violation response

```json
{
  "action": "fifo_violation",
  "scanned_batch": { "batch_id": 812, "received_at": "2026-04-02" },
  "expected_batch": {
    "batch_id": 640,
    "received_at": "2026-03-12",
    "po_number": "PO-2401",
    "zone_label": "A-03",
    "remaining": 140
  }
}
```

**`zone_label` is required in the response, not optional.** Refusing a pick without telling
staff where the correct stock is, is how a FIFO rule gets worked around — someone marks it
damaged, or overrides every time. `inventory_batches.zone_label` already exists.

### 5.3 Override

Resubmit with `accept_fifo_override: true` and `override_reason` (min 10 chars), gated on a
new `inventory_issuance.fifo_override` permission so ordinary pickers cannot self-approve.
The existing `permissions` / `role_permissions` / `user_permission_overrides` tables support
this without change.

```sql
ALTER TABLE issuance_details
  ADD COLUMN fifo_override_reason VARCHAR(255) NULL;
```

A column rather than audit-trail-only: the audit trail is a JSON blob, fine for forensics
but painful for "show me every FIFO override last month", which is the report an auditor
will ask for.

### 5.4 Accepted consequence

Strict FIFO means a partly-consumed older batch must be exhausted before any newer one
opens. If the client ever splits a material across branches or deliberately holds reserve
stock, this will fight them. The override is the pressure valve. **If staff are seen
overriding routinely, the rule is wrong rather than the staff** — that signal should be
reviewed rather than ignored.

## Section 6 — UX

### 6.1 Receiving — escape hatch (Option A)

The line opens in scan mode as today; a secondary **"Enter count instead"** button sits
beside Scan on the active-line panel. The receiving screen already makes clerks select a PO
line (`bsrActiveLine` / `po_detail_id`) before scanning, so the count path slots into an
existing step rather than adding one.

Rejected: an upfront per-line "has barcodes / no barcodes" choice. It taxes every line,
including the barcoded majority, to make the rare case explicit — and since `entry_mode`
records the decision either way, its auditability advantage is small.

Count entry prompts according to `count_unit` and shows live remaining feedback, so a short
delivery is caught while counting. That feedback is the point of the feature.

### 6.2 Tag rendering and printing

`react-barcode` is already a frontend dependency and `html5-qrcode` already handles
scanning, so no new library is needed. After posting, generated tags render on-screen as
scannable barcodes — which is what lets staff scan from the screen when a physical label is
missing or damaged — with a print action producing a tag sheet via the existing
`barryvdh/laravel-dompdf` setup.

### 6.3 FIFO violation

Shows the expected batch's PO number, receiving date, zone, and remaining quantity, with a
"Scan that one" primary action and a secondary "Override…" that reveals the reason field.
The override control is hidden entirely for users without the permission, rather than shown
and rejected.

## Error handling

All posts run inside the existing `DB::transaction` with `lockForUpdate()` on the inventory
row, identical to scans, so concurrent clerks on the same line serialize rather than
double-post. Tag generation happens inside that same transaction, so a rolled-back receipt
leaves no orphan tags. Rejections return the existing `action: rejected` envelope, so
current frontend error rendering works unchanged; `over_receipt` and `fifo_violation` are
new actions the frontend must handle explicitly.

## Testing

New directory `back-end/tests/Feature/Receiving/`, following the existing domain layout
(`Aircon/`, `Warranty/`, `Incidents/`, `Transfer/`). `TransferServiceTest.php` already
creates products with `is_serialized => false` and provides a fixture pattern to follow.

**Stock accounting**

- **Packages/pieces regression** — count-receive 473 pieces with `ppp = 100`; assert
  `quantity_on_hand = 473` and `packages_on_hand = 4`. Post a second receipt; assert 573,
  not 500. Fails against current code, which is why it is written first.
- **`setPieces` preserves divergence** — with `available_quantity` below `quantity_on_hand`,
  assert a receipt raises available by the delta rather than resetting it to parity.
- **Serialized guard** — count-receive against an appliance PO line is rejected and moves no
  stock.
- **Over-receipt** — 55 counted against 50 remaining returns `action: over_receipt` and
  posts nothing; resubmission with `accept_overage` posts 55. Assert
  `syncPoReceivingStatus()` still reaches *Completed* when received exceeds ordered.
- **Mixed mode on one line** — scan 3 packages, count 2 more; assert totals agree and both
  `entry_mode` values are present.

**Tags**

- **Granularity** — a `package` product receiving 6 boxes creates 6 tags; a `piece` product
  receiving 473 creates 1.
- **Hybrid identity** — an item received with a supplier barcode has one `item_serial` row
  carrying both identifiers, and scanning either resolves to the same batch.
- **No type confusion** — a material tag can never resolve into the appliance serial branch
  of `IssuanceScanController`.
- **Rollback** — a receipt that fails after tag generation leaves no `item_serial` rows.

**FIFO**

- **Blocks newer batch** — two batches, scan the newer, assert `fifo_violation` naming the
  older with its `zone_label`.
- **Legacy batch never blocks** — with a legacy batch present (`receiving_id NULL`), a
  normal pick succeeds.
- **Quarantined oldest is skipped** — quarantine the oldest batch; assert the next batch
  becomes pickable without an override.
- **Tie-break** — two batches with identical `received_at` resolve deterministically by
  `batch_id`.
- **Override** — without the permission it is refused; with it and a reason, the issue posts
  and `fifo_override_reason` is persisted.

**Transfers**

- Count-ship then count-receive an unbarcoded line end to end; assert source and destination
  `packages_on_hand` are both correct. Fails today.
- Remove a counted transfer scan; assert the exact quantity reverses, covering the
  nullable-`barcode` change.

## Migrations

1. `installation_material_details.count_unit`
2. `receiving_details.entry_mode` (backfill existing rows to `scanned`)
3. `barcode_scans.barcode` → nullable
4. `item_serial.batch_id` + `item_serial.supplier_barcode`
5. `issuance_details.fifo_override_reason`
6. Seed the `inventory_issuance.fifo_override` permission

**Backfill note:** existing installation-material stock has batches but no tags. Those items
remain issuable through the existing product-barcode path; tags exist only for stock
received after this change. A retroactive tagging pass is deliberately not attempted —
tags for stock nobody can physically match would be worse than none.

## Out of scope

- Retroactive tag generation for pre-existing stock (see backfill note).
- The `FifoPickingService::isSerialized()` inconsistency in §4.3.
- Any change to appliance receiving, which stays scan-only by design.
- FIFO enforcement across locations — the check is per product *and* location, matching how
  `deductFifo()` already works.
