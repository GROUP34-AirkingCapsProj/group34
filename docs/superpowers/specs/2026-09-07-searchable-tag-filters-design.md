# Searchable Tag Filters — Design

**Date:** 2026-09-07
**Status:** approved, not yet implemented
**Follows:** `2026-09-06-barcode-printing-page-design.md`

## Problem

Three filters on the barcode printing page make a clerk type blind.

**Product** and **Receipt or PO** are empty text boxes. Nothing on screen says
which products are in the queue or which delivery numbers exist, so finding a
tag means guessing a substring and reading the result count to find out whether
the guess was right. A clerk holding a carton knows what is written on it; a
clerk asked "which delivery was this?" three hours into a print run does not.

**Location** is a `<select>` fed by `allowedLocations` — every location the user
may transact at, including ones holding no tags at all. Picking one of those
lands on "No tags match these filters", which reads identically to a mistake.

Meanwhile the app already has the control this wants: `ProductSearchSelect`, a
type-to-search picker with a floating menu, used by the issuance order builder,
purchase requests and transfer requests. The printing page should not invent a
second answer to a question already answered here.

## Scope

In: a generic `SearchSelect` extracted from `ProductSearchSelect`; an `options`
block on the queue response; a `product_id` filter; the three filters rewired.

Out: the Product type, Print state and date filters — two or three fixed choices
need no search box. Out: `LocationSelector`, which stays exactly as it is for
every other page; see the decisions below.

## Section 1 — The control

`SearchSelect` takes everything `ProductSearchSelect` does that is not about
products: the input, the query state, the menu rendered through a portal so an
ancestor's `overflow: hidden` cannot clip it, outside-click closing, scroll and
resize re-positioning, substring matching, the 50-result cap, and row rendering.

```jsx
<SearchSelect
  items={[{ id, label, sublabel, extra, searchText }]}
  value={value}
  onChange={(id) => …}
  placeholder="Search product…"
  emptyText="No matching products"
  facets={…}
/>
```

`searchText` is what the query matches against, composed by the caller. This is
the point of the extraction: products match on name, code and brand, receipts
match on one string, and the control does not need to know which it is holding.

`facets` is an optional node rendered above the input, and it is the seam that
keeps the existing callers safe. `ProductSearchSelect` becomes a thin wrapper
that keeps its own category and brand state, pre-filters the products it passes
down, and hands those two `<select>`s in as that node. Its props, its markup and
its behaviour do not change, so its three consumers are untouched.

**Pick-only.** Typing filters the menu; the filter value is set only when a row
is clicked. This is a deliberate change from today's free-text boxes, where an
unpicked `copper` narrows the grid immediately. It matches the control the rest
of the app already uses, and it is what makes an exact `product_id` possible.

## Section 2 — Where the options come from

`BarcodeQueueController::index()` already returns `counts` built from
`filtered($request)`. It gains an `options` block built from that same query:

```php
'options' => [
    'products'  => [['product_id' => 5, 'product_name' => 'Copper Tube', 'product_code' => 'CON-1']],
    'receipts'  => [['value' => 'RCV-000241', 'kind' => 'grn'], ['value' => 'PO-20260901', 'kind' => 'po']],
    'locations' => [['location_id' => 2, 'location_name' => 'Main Warehouse']],
],
```

Three properties follow from sharing one filter set, and all three are the
reason this is bundled into the queue response rather than served by a second
endpoint:

- **Every suggestion returns rows.** The options are distinct values *of the
  tags being listed*, not of the product catalogue. A product with no tags
  cannot be offered, so a pick can never land on an empty grid.
- **The location scope is inherited, not re-applied.** `forUser()` runs once, on
  the query both the rows and the options come from. A branch-scoped clerk's
  menu cannot leak another branch's PO numbers, and no future edit can leave the
  fence on one path and off the other.
- **The lists narrow as you filter.** Choosing a location shrinks the receipt
  list to that location's deliveries.

`kind` distinguishes a GRN number from a PO number so the menu can label the
row. One box still searches both, because a clerk holds whichever number the
paperwork happened to print.

**Capped at 50 per list.** This does not violate the no-silent-truncation rule
that governs select-all: there, a dropped id means a label that never prints and
nobody finds out until the shelf. Here the lists are an aid to picking, the
grid is unaffected, and anything unlisted is reached by narrowing another
filter. The cap is not surfaced in the UI.

Cost: three additional `DISTINCT` queries per page load, over the same filtered
set the existing count already runs against.

## Section 3 — The `product_id` filter

Picking from a list identifies one product exactly, so the Product filter sends
`product_id` rather than the `q` substring. "Copper Tube" stops also matching
"Copper Tube Insulation".

`q` remains supported by the endpoint and keeps its tests. It is no longer
offered in the UI, but a URL someone bookmarked or shared still resolves —
filters live in the query string precisely so those links keep working.

`receipt` and `location_id` are unchanged. `location_id` still travels as an id,
so shared links are unaffected by the control swap.

## Section 4 — Wiring

`TagFilterBar` renders three `SearchSelect`s in place of two text inputs and the
`LocationSelector`, fed from the `options` block the page already receives.
The page keeps owning the filter object and the URL sync; the bar stays fully
controlled and holds no state of its own.

Each list maps onto `items`. Products use `product_id` as the id and match on
name and code; locations use `location_id`. A receipt has no id of its own, so
it uses its own string — picking one sets `receipt` to that exact value, which
the endpoint's existing substring match resolves unchanged.

`BarcodePrintingPage` stores `options` beside `counts` from the same response.
No new request, no new loading state.

## Section 5 — Testing

The order is the safety net, not a preference.

**Before touching anything:** characterisation tests for `ProductSearchSelect`
as it stands — it renders the products given, matches on product code as well as
name, picking a row calls `onChange` with that product's id, and the category
facet narrows the list. Three flows depend on this component and none of them
are covered today. These tests must be green before the extraction and green
after it, unchanged.

**`SearchSelect`:** the query filters on `searchText`; picking reports the id;
`emptyText` shows when nothing matches; the facet node renders when given.

**Backend:** `options` appears on the queue response; a branch-scoped user's
options hold only their own location's products, receipts and locations; a
location filter narrows the receipt list; each list caps at 50; `product_id`
filters to exactly that product and not to a name that contains it.

**Page:** picking a product sets `product_id` and re-queries; picking a location
sets `location_id`.

## Decisions and rejected alternatives

**A `<datalist>` instead of a component.** The codebase already uses
`<input list=…>` for zone labels and capacities, and it would have been less
code. Rejected because a datalist cannot carry a hidden value — the location
filter needs an id while the box shows a name — and because the app already has
a richer picker that users have learned. Two controls answering the same
question is worse than one slightly heavier one.

**A separate `/queue/options` endpoint.** Cheaper per page-turn and cacheable.
Rejected because its lists would be fixed at page load and could therefore offer
a receipt the current filters exclude — the same class of bug as a select-all
that disagrees with the grid, which is why `index()` and `ids()` were built on
one shared filter method in the first place.

**Options derived from the rows on screen.** Free, no backend change. Rejected
as circular: 24 rows can only suggest what is already visible, and page two
would offer different suggestions than page one.

**Changing `LocationSelector` itself.** One control across the whole admin, but
it is used by receiving, transfers, scanning and the IST flows, and its
"degrades to a number input" fallback would need rethinking. It also answers a
different question — *where you may transact*, not *where the tags are*. The
printing page uses `SearchSelect` directly and nothing shared moves.

**Refactoring `ProductSearchSelect` without pinning it first.** Faster. Rejected
because the regression would surface in the issuance order builder, in front of
a user, rather than in a red suite.
