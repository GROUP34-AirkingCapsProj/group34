# Serial lookup: one serial in, the whole story out

Date: 2026-08-14
Status: approved, implementing

## The problem

The Serial Number Lookup on the Warranty page searches `warranty_records` and
nothing else. A warranty record only exists for a unit that has been SOLD, so
every unit still in the warehouse comes back as
`404 — No warranty record found for this serial number`.

Serial 3333 is the example: it exists in `item_serial` as `received` /
Quarantined, is held by an open incident, and carries supplier cover to
2029-08-12 — and the lookup says it does not exist. The panel cannot say where a
unit is, whether it was sold, or that supplier cover applies, because it never
looks anywhere but one table.

## What makes this answerable

Selling a unit DELETES its `item_serial` row (`IssuanceScanController` calls
`$serialRecord->delete()` at scan time, and re-creates it if the unit is removed
from the issuance). The serial then lives on in `issuance_details` and
`warranty_records`.

So presence of a serial row is itself the answer to "is it still in inventory".

## The states

Resolved in this order; the first match wins.

| Order | State | Test | Reports |
|---|---|---|---|
| 1 | `in_stock` | an `item_serial` row exists | location, serial status (In Stock, Quarantined, Under Repair, Awaiting Warranty Claim, Scrapped, Missing…), supplier cover; customer warranty "not sold yet" |
| 2 | `returned` | an inbound incident item carries the serial on an incident that is not dismissed | sale details if known, plus the incident number and what stage it is at |
| 3 | `sold` | a `warranty_records` row | customer, sale date, customer warranty state, supplier cover |
| 4 | `sold_no_warranty` | an `issuance_details` row only | the sale, and plainly that no warranty was created for it |
| 5 | `unknown` | nowhere | says so, as an answer rather than an error |

`returned` outranks `sold` deliberately. A returned unit currently gets no
serial row back, so without that rule the panel would state "Sold to <customer>"
about a unit standing in the warehouse — a confident wrong answer, which is
worse than the "not found" it replaces.

Serial matching uses the existing `NormalizesBarcode` candidates, so the same
formatting tolerance the warranty claim flow already has (case, dashes, spaces)
applies here too.

## Shape

`App\Services\SerialLookupService::find(string $serial): SerialLookup` — a
readonly value object carrying the state and the blocks that apply to it. A
service rather than more controller code: `WarrantyController` already lists,
shows, claims and exports, and this is the logic a dedicated unit-lookup page
would reuse if that is ever wanted.

**One refactor comes with it.** `WarrantyStateService::supplierStateFor()` only
accepts an `IncidentItem`, because incidents were its only caller. Its core
moves to a method taking a product id and a receiving id; the incident version
becomes a thin wrapper that resolves the receiving from the item. Both callers
then share one piece of warranty arithmetic rather than two copies free to
drift.

## The endpoint

`GET /api/warranties/lookup/{serial}` keeps its route and its
`warranty_records,read` permission, and returns **200 in every case**, including
unknown serials, with:

```
{ state, unit, sale, customer_warranty, supplier_warranty, incident }
```

with nulls where a block does not apply. The warranty page is the only caller,
so no other consumer is affected. Unknown stops being a 404 so the panel can
render a plain message instead of an error box.

## The panel

Stays where it is, keeps its scanner-first behaviour (one target, always in the
same place). It gains a state badge, then two warranty lines — customer and
supplier — then whatever context the state carries. The Claim button still
appears only when the customer warranty is genuinely claimable.

## Tests

One per state through the endpoint: in stock; in stock but quarantined; sold and
covered; sold and lapsed; sold with no warranty record; returned; unknown. Plus
that `warranty_records,read` still gates it.

## Deferred

- **A restocked serialized return gets no `item_serial` row back.**
  `IncidentItemService::returnToStock()` restores the quantity and the batch but
  not the serial, so the unit is invisible to serial-based picking. This spec
  detects the situation and reports it honestly; fixing it means changing the
  stock-settlement path and belongs in its own spec.
- A dedicated unit-lookup page, and movement history on the unit.
