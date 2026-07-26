# Set (BOQ) Redesign — Multi-Brand, Pack/Box Quantities, No Name/Code — Design

**Date:** 2026-07-27
**Branch:** `backupV3`
**Status:** Approved (brainstorming), pending plan.

## Goal

Rework the Set (BOQ) feature so it matches how the business actually authors aircon installation-material bills of quantity. A Set is no longer a named/coded catalog row; it is the installation-materials BOQ for **one capacity + one type (inverter/non-inverter) across one or more aircon brands**. Staff pick a Set manually during an issue-out so they don't retype the materials an install needs. Prices are always calculated even though the install itself is "free."

## Confirmed decisions
- **Remove `set_code` and `set_name`** entirely (columns, UI, validation, list columns).
- A Set's identity = **many brands** + **one capacity** + **one type** (inverter/non-inverter). Displayed as a derived label, e.g. "TCL, Carrier · 2HP · Non-inverter". No extra name/note needed.
- **Multiple brands per Set** (multi-select); **one capacity** per Set; **one type** per Set.
- **No auto-suggest.** Staff add a Set manually (existing "Add set"); appliance-only purchases use "Add custom". Remove the `suggest()` endpoint + matching logic + tests.
- Material line **quantities use the Transfer pack/box model**: entered as packages + loose pieces from the product's `pieces_per_package` / `package_unit`, stored in pieces. Retire the per-line `issuable_unit_id` mechanism.
- **Dropdown width fix** in both the issue-out "Add custom item" (Category/Brand/Product) and the Set Builder line rows so they stop hiding other columns.

## Data model changes

**`product_sets`**
- Drop `set_code` (and its unique index), `set_name`, `description`.
- Drop the single `brand_id` column and the `product_sets_match_idx` index that references it.
- Keep `capacity_rating`, `variant` (the type), `is_active`, audit + soft-delete columns.
- New table **`product_set_brands`** (`id`, `set_id` FK cascade, `brand_id` FK → `brand_lookup` cascade, unique `(set_id, brand_id)`).

**`product_set_lines`**
- Keep `product_id`, `quantity` (now interpreted as **pieces**), `spec_note`, `sort_order`.
- Drop `issuable_unit_id` (and its FK) — quantity is pack/box-derived pieces now.

**Migration** must be idempotent and migrate any existing single `brand_id` into `product_set_brands` before dropping the column. (Dev/test data is minimal; still handle it.)

## Backend

**`ProductSet` model:** remove `set_code`/`set_name`/`description`/`brand_id` from fillable; add `brands()` belongsToMany via `product_set_brands`; keep `lines()`, `totalPrice()`. Add a derived `label()` (brands joined + capacity + variant).

**`ProductSetLine` model:** drop the `issuableUnit()` relation and `quantityInPieces()`'s conversion-factor logic; `quantity` is already pieces. (Keep a `quantityInPieces()` returning the integer quantity for callers that expand a set into issuance lines.)

**`ProductSetController`:**
- `store`/`update`: validation drops `set_code`/`set_name`/`description`/`brand_id`; adds `brand_ids` (array of `exists:brand_lookup,brand_id`, min 1), keeps `capacity_rating`, `variant`; lines validate `product_id`, `quantity` (integer ≥1, pieces), `spec_note`, `sort_order` (drop `issuable_unit_id`). Sync brands into the pivot.
- `present()`: returns `set_id`, `brands` (array of `{brand_id, brand_name}`), `brand_names` (joined), `capacity_rating`, `variant`, `label`, `is_active`, `set_price`, and `lines` (each with `product_id`, `product_name`, `quantity` (pieces), `pieces_per_package`, `package_unit`, `spec_note`, `unit_price`, `line_total`).
- **Remove `suggest()`** and its route.
- `index`/`archived`/`show`/`destroy`/`restore` stay, adjusted for the new shape.

**Issuance expansion:** wherever a Set is expanded into issuance lines (`IssuanceOrderService::addSet`), it already uses the set line's piece quantity × set_quantity — confirm it reads the pieces quantity (no issuable-unit conversion). Quote/detail already carry pieces; pack/box is display-only.

**Tests:** update every Sets fixture that sets `set_code`/`set_name` to the new shape (brands[]/capacity/variant). Delete `SetSuggestionTest`. Update `ProductSetModelTest`, `ProductSetCrudTest`, `SetLineConversionTest` (issuable-unit → pieces), `SetSchemaTest` (columns), and any issuance tests that build a set. Keep the suite green.

## Frontend

**`SetManagementPage.jsx`:** table columns → Brands, Capacity, Type, Lines, Set price, Status (drop Code/Name). Search stays (by brand/capacity). Duplicate/edit/archive/restore keep working against the new shape.

**`SetBuilderModal.jsx`:** replace `set_code`/`set_name` inputs with:
- **Brands** multi-select (checkbox list or multi-select) of aircon brands.
- **Type** dropdown (Inverter / Non-inverter).
- **Capacity** dropdown (distinct aircon capacities, from a lookup or distinct `appliance_details.capacity_rating`; fall back to a text entry if none).
- Each material line: product picker + **packages / loose-pieces** inputs (transfer-style, showing "3 Box + 5 pcs" via `packageBreakdown`) + spec note. Remove the issuable-unit dropdown. Set price still computed and shown live.
- Compact dropdown widths so line columns stay visible.

**Issue-out (`OrderBuilder.jsx`):**
- "Add set" (manual pick) stays; "Add custom" stays (with the compact Category/Brand/Product dropdowns).
- Material quantities from a Set display in the pack/box + pieces form consistent with Transfers.
- No auto-suggest UI.

## Non-goals
- No change to FIFO picking, reservations, or the scan engine.
- Not adding brand data to non-appliance products (brand stays appliance-only; brands here are the aircon brands a Set covers).
- Not migrating the old `product_issuable_units` table away globally — only Sets stop using it.

## Testing
- Backend: update/relocate Sets tests to the new shape; add tests for multi-brand store/update (pivot), pack/box piece quantity, and label derivation; delete suggest tests. Full suite green.
- Frontend: `npm run build` clean per changed file; manual `npm run demo` pass — build a multi-brand Set with pack/box quantities, see the derived label + calculated price, then add it on an issue-out.
