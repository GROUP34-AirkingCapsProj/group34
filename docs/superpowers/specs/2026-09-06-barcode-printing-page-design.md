# Barcode Printing Page

**Date:** 2026-09-06
**Status:** Approved, ready for planning

## Problem

Every item that enters stock already has an identity row in `item_serial`. Only
one of the three kinds reaches a screen where it can be scanned or reprinted.

| Kind | Stored by | Visible today? |
|---|---|---|
| Minted tag (`INT-…`, `supplier_barcode` null) | `MaterialTagService::mintForCount` | Yes — `MaterialTagSheet` |
| Supplier code (`supplier_barcode` set) | `MaterialTagService::mintForScan` | No — filtered out by the sheet's `supplier_barcode == null` gate |
| Manufacturer serial (serialized appliance) | `ReceivingPostingService::mintTags` | No — `AttachesGeneratedTags` rejects serialized products |

The two exclusions are deliberate. Their stated reason is that minting and
printing an `INT-` label for a carton the supplier already labelled puts two
competing identifiers on one box, which the warehouse then has to reconcile.
That reasoning is sound and this design does not overturn it.

What it does overturn is the conclusion drawn from it. "Do not mint a second
identifier" does not require "do not show the identifier that exists". A clerk
holding a carton whose supplier label is smudged currently has no way to pull
that code up on screen, and no way to put it back on the box.

A second gap sits alongside the first: the tag sheet is scoped to one product at
one location, rendered inside the inventory history modal. A clerk working
through a delivery is not looking at one product's history — they are working
through a receipt. There is no view that crosses products.

## Scope

A dedicated Barcode Printing page: filter the stock-in tag population by product
type, date, location and receipt; select across pages; print the selection.
Every identity tier is visible and scannable on screen. Print history is
recorded so a half-finished run can be resumed.

**Out of scope.** `MaterialTagService` and `ReceivingPostingService` are not
modified. No new identifiers are minted anywhere by this work. The per-product
`MaterialTagSheet` keeps its current contract and its current filtering.

## Section 1 — Identity tiers

The page shows all three tiers and never conflates them. Each card is badged
with its provenance.

| Tier | Detection | Badge | Bulk print | Single reprint |
|---|---|---|---|---|
| Minted tag | `supplier_barcode IS NULL` and product not serialized | *Our tag* | Yes | Yes |
| Supplier code | `supplier_barcode IS NOT NULL` | *Supplier code* | No | Yes |
| Manufacturer serial | `product->isSerialized()` | *Manufacturer serial* | No | Yes |

Tier is decided in that precedence order — serialized first, then
`supplier_barcode`, then minted — because a serialized appliance's row also has
a null `supplier_barcode` (`ReceivingPostingService::mintTags` does not set it)
and would otherwise read as a minted tag.

Tier is computed server-side and returned on each row. The frontend must not
re-derive it by matching an `INT-` prefix: that format is ours and could change,
and `supplier_barcode IS NOT NULL` is the honest test — the same argument
`MaterialTagService`'s own docblock makes for populating that column.

### The two gates

`AttachesGeneratedTags` is **unchanged**. Its `reject(isSerialized)` feeds the
per-product history sheet, whose contract is "tags we minted". The new page
queries `item_serial` directly and does not pass through this trait.

`LabelPrintController::printable()` **changes** from one rule to two modes:

- **Bulk mode** keeps today's rule verbatim: product not serialized, and
  `supplier_barcode === null`.
- **Single mode** accepts supplier codes and manufacturer serials.
- **Both modes** refuse `serial_type === 'issued'`. A retired tag points at
  stock that is gone; labelling it is wrong in every mode.

The mode arrives as an explicit request field, not inferred from the id count —
otherwise a client could bulk-print supplier labels by sending them one request
at a time and the rule would be decorative. The server is the enforcement point;
the UI's warnings are a courtesy on top of it.

## Section 2 — Data model

One migration on `item_serial`. All columns nullable or defaulted, so existing
rows read as "never sent".

```
first_printed_at  timestamp      null
last_printed_at   timestamp      null
print_count       unsigned int   default 0
last_printed_by   FK users.user_id  null
```

Add the four to `ItemSerial::$fillable`, and cast the two timestamps.

**Stamping.** Written inside `LabelPrintController::print()`, in the same
transaction as the send, and only after `LabelPrinterService::send()` returns
without throwing. A `LabelPrinterException` (the 503 path) must leave all four
columns untouched — a jammed printer that marked its queue as printed is worse
than one that printed nothing.

`first_printed_at` is set once and never moves. `print_count` increments on
every successful send.

**What the stamp means.** It records *sent to the printer*, not *stuck on a
box*. The UI says "Sent", never "Printed", and the reprint action stays
available whatever the state. The browser-side PDF path (`savePdf` in
`printBarcodeLabels.js`) cannot be observed by the server and does **not**
stamp; the UI states this rather than letting the count quietly under-report.

## Section 3 — Endpoints

### `GET /api/barcode-labels/queue`

Paginated. `item_serial` joined to `products`, `locations`, `receiving`.

| Filter | Notes |
|---|---|
| `product_type` | `installation_material` \| `appliance` |
| `date_from`, `date_to` | against the receipt date |
| `location_id` | |
| `receipt` | one box for "which delivery": substring-matches the GRN `receiving_number` **or** the linked PO's `po_number`. Deliberately not a numeric `receiving_id` — a clerk holds paperwork, not an id, and does not know which of the two numbers is printed on it. |
| `q` | product name / code search |
| `print_state` | `unsent` \| `sent` \| `all` |

Each row returns: `serial_id`, `serial_number`, `supplier_barcode`, `tier`,
`bulk_printable`, `product_id`, `product_name`, `product_type`,
`location_name`, `receiving_number`, `receiving_date`, `print_count`,
`last_printed_at`.

`bulk_printable` is the server's own answer to "would a bulk print include
this", and the UI must use it rather than re-deriving the rule from `tier` —
the point of returning it is that the browser never holds a second opinion
about what will print. `location_name`, `receiving_number` and
`receiving_date` are nullable: a tag with no receipt is legitimate and appears
in unfiltered lists.

The response carries the total matching count and the unsent count, so the
filter bar can show *"340 tags · 112 not yet sent"* without a second request.

### `POST /api/barcode-labels/print`

Extended with `mode` (`bulk` | `single`). The existing
`serial_ids: required|array|min:1|max:200` validation stays. `mode=single`
additionally requires exactly one id — the mode names an intent, and a
200-item "single" reprint is not one.

### Selection across pages

Selection lives in the client as a `Set` of `serial_id` and survives paging.
"Select all matching filters" resolves server-side against the filter set
rather than the visible page, capped at 200 ids.

When the cap truncates, the UI states it explicitly: *"200 of 340 selected —
print these, then select the rest."* A silent truncation here means missing
labels that nobody discovers until the shelf.

## Section 4 — The page

Route `/admin/barcode-printing`, wrapped in `RoleRoute` like every other admin
page, linked from `Sidebar.jsx` under the inventory group.

**Filter bar.** The Section 3 filters, reusing `DateInput` and
`LocationSelector`. Filter state is mirrored in the URL query string: a clerk
who reloads mid-run, or sends the link to a colleague, lands on the same list.
Result count sits beside the controls.

**Selection bar**, sticky once anything is selected. Running count, *Select all
matching filters*, *Clear*, and two actions: **Send to printer**
(`useLabelPrinter`, shown when `printerReady`) and **Save as PDF** (always
available).

Mixed selections are stated before the click, not discovered after it:

> 38 selected · 6 are supplier codes and won't be included in a bulk print.

**Card grid**, paginated with the existing `Pagination` component. Each card
carries a checkbox, the tier badge, the barcode at print size, product name,
receipt date, location, and print state (*Not yet sent* / *Sent 3× · last
3 Sep*). Clicking the barcode opens `BarcodeZoom` at scanning size — unchanged
behaviour. Each card has its own **Reprint this label** action: the single-mode
path, available for every tier.

**Shared card component.** The card body is extracted from `MaterialTagSheet`
into a `TagCard` that both it and the new grid render, so `isTightFit` and
`moduleWidthMm` keep exactly one caller shape. `MaterialTagSheet` keeps its own
`supplier_barcode == null` filtering and its own header; only the card body
moves.

## Section 5 — Testing

**Backend** (`tests/Feature/Inventory`, following the existing pattern):

- `printable()` in both modes: bulk refuses supplier codes and manufacturer
  serials; single accepts both; both refuse `serial_type === 'issued'`.
- `mode=single` rejects a multi-id payload.
- Stamping: `print_count` increments, `first_printed_at` is set once and does
  not move on reprint, `last_printed_by` records the actor.
- A `LabelPrinterException` leaves all four print columns untouched.
- Queue filters: each filter alone; `product_type` and date range together;
  `print_state=unsent` excludes a stamped row.
- Select-all-matching caps at 200 and reports the true total.

**Frontend:**

- `TagCard` renders the correct badge for each tier.
- The mixed-selection warning appears with the right count.
- Selection survives a page change.
- Bulk print sends only eligible ids.

The existing `MaterialTagSheet.test.jsx` must stay green **untouched** — that is
the proof the `TagCard` extraction changed no behaviour.

## Decisions and rejected alternatives

**Render the existing code rather than minting our own for supplier-labelled
stock.** Considered: always minting an `INT-` tag cross-referenced to the
supplier code, for format uniformity. Rejected — it recreates exactly the
two-identifiers-per-carton problem the receiving-side comments describe. One
carton, one identifier.

**Bulk print stays restricted to minted tags.** Considered: making every tier
fully printable for uniformity. Rejected — a bulk print would then paper a whole
receipt in duplicate supplier labels. The single-card reprint covers the case
that motivates printing at all (a damaged sticker) without that risk.

**A dedicated page rather than extending the existing sheet.** Date and
product-type filters are close to meaningless inside a single-product modal.
Printing a batch of labels is its own task with its own workflow.

**Print history tracked with counts, not just a timestamp.** A card reading
"sent 3×" tells a supervisor a label keeps getting damaged, which is a real
signal about that carton or that shelf. The stamping write is the same call
whether it writes one column or four.
