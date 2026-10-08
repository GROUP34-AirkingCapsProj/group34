# Item Lookup Management — Design

**Date:** 2026-07-30
**Branch:** backupV3
**Status:** Approved for planning

## Problem

The admin can manage only two of the item master's lookups — Categories and Brands. Four others that the item forms depend on are unmanageable:

| Lookup | Storage today | API | UI |
|---|---|---|---|
| Categories | `category_lookup` | full CRUD + archive/restore | managed |
| Brands | `brand_lookup` | full CRUD + archive/restore | managed |
| Unit of Measure | `unit_lookup` | full CRUD already exists (`/api/units`) | **no page** |
| Capacity | free-text `appliance_details.capacity_rating` | none | hardcoded JS array at `ProductRegisterModal.jsx:18` |
| Variant | free-text `appliance_details.variant` | none | plain text input |
| Issuable Unit | `product_issuable_units` — per-product rows | per-product endpoints only | edited inside the item |

Capacity and Variant being free text means the same value drifts across spellings ("1.5HP", "1.5 HP", "1.5hp"). Unit of Measure has a complete API with nothing to drive it. Issuable units have no shared vocabulary at all.

## Goal

One page managing all six lookups, split into two tabs by product type, with the item forms constrained to the managed values.

- **Appliance Lookup** tab — Categories, Brands, Variants, Capacities
- **Installation Materials Lookup** tab — Units of Measure, Issuable Unit Presets

## Decisions

Settled during brainstorming; recorded so the rationale survives.

1. **Issuable Unit Presets are reusable templates** — a global list of label + base unit + default conversion factor (e.g. "Roll → Piece, ×50"). Picking a preset prefills a product's issuable unit; the product keeps its own factor. Stops label drift without forcing one conversion on every product.
2. **Capacity and Variant become strict dropdowns** — the item form offers only lookup values. New values are added on the management page first. Matches how Category and Brand already behave; it is the only option that actually keeps the list clean.
3. **Capacity and Variant are scoped to Category** — optionally. A row may name a category, and the item form filters by the category already selected. Rows with no category show for all categories, so nothing has to be classified. Without this, someone registering a refrigerator sees "1.5HP".
4. **One `lookup_management` permission** covers the page and all six lookups. Existing `category_management` / `brand_management` keys are kept and still guard their own endpoints, so no effective access changes.
5. **Archiving a value in use warns, then allows** — the confirmation shows the usage count. Archived values stay readable on existing items and leave the dropdown for new ones.
6. **Layout: two top tabs, sub-tabs inside each** — one list on screen at a time, each with its own search, Add button, and inline archived toggle.
7. **Approach: shared base, per-lookup tables** — three new `*_lookup`-convention tables, with the eight standard CRUD actions factored into one trait rather than copied six times.

### Stated assumption

**Merge is in scope.** The backfill will produce near-duplicates from dirty free-text data, and blocking rename-onto-existing would leave no way to consolidate them. Renaming onto an existing value offers a merge instead of erroring. Strike this and the cleanup becomes manual.

## Architecture

### Why a shared base

`CategoryLookupController`, `BrandLookupController`, and `UnitLookupController` are already three near-identical copies of the same eight actions. Adding three more by copy-paste locks in ~450 lines of duplicated CRUD, and any future change to archive semantics has to be made in six places. The frontend has the same problem: `CategoryBrandManagement.jsx` is 631 lines for two lookups.

A single generic `lookup_values` table with a type discriminator was considered and rejected — `products.category_id`, `appliance_details.brand_id`, and `product_issuable_units.unit_id` are real foreign keys into the existing lookup tables. Migrating them is risky and touches the whole app; not migrating them leaves a split world.

### Data model

Three new tables, following the existing `*_lookup` convention (soft deletes, `<name>_id` primary key):

**`capacity_lookup`** — `capacity_id` PK, `capacity_value` **varchar(20)**, `category_id` nullable FK → `category_lookup`, `description` nullable, `sort_order` tinyint, timestamps, soft deletes.

> Width is deliberate. `appliance_details.capacity_rating` is varchar(20) and `product_sets.capacity_rating` is varchar(60). Since the lookup value is copied into both by the rename cascade, it must fit the narrowest — a wider lookup column would let an admin save a 40-character capacity that MySQL silently truncates on the way into `appliance_details`, permanently desynchronising the item from its lookup row. `variant_name` varchar(100) matches both destinations exactly and needs no such clamp.

**`variant_lookup`** — `variant_id` PK, `variant_name` varchar(100), `category_id` nullable FK → `category_lookup`, `description` nullable, `sort_order` tinyint, timestamps, soft deletes.

**`issuable_unit_preset`** — `preset_id` PK, `label` varchar(100) unique, `unit_id` FK → `unit_lookup` (on delete restrict), `default_conversion_factor` decimal(10,6) default 1, `is_whole_package` boolean default false, `description` nullable, `sort_order`, timestamps, soft deletes. Column types match `product_issuable_units` so a preset prefills a product row without conversion.

`unit_lookup`, `category_lookup`, and `brand_lookup` are unchanged.

#### Capacity and Variant stay as text on the product

`appliance_details.capacity_rating` and `.variant` remain text columns. No `capacity_id` / `variant_id` foreign keys are added. The lookup tables drive the dropdown; the product stores the string.

Those values are read as strings throughout the app (`DemoProductDataSeeder`, `poExport.js`, the dashboards, `SetBuilderModal`), and `product_sets` mirrors them with its own `capacity_rating` varchar(60) / `variant` varchar(100) columns. Converting to foreign keys means touching all of it, and it would make archiving a value break existing items rather than just hiding it from the dropdown — contradicting decision 5.

The cost is no referential integrity, and one obligation:

**Renaming a lookup value cascades.** Editing "1.5HP" → "1.5 HP" updates `appliance_details.capacity_rating` and `product_sets.capacity_rating` for every matching row, in one transaction. Without the cascade, a rename silently orphans every product using the old string. This is the main piece of non-obvious logic in the feature.

#### Uniqueness

MySQL treats NULLs as distinct in unique indexes, so a DB-level `unique(capacity_value, category_id)` will not stop two global (category-less) rows named "1.5HP". Uniqueness is enforced in request validation instead — case-insensitive, scoped per category.

#### Backfill

A migration seeds the new tables from existing data:

- `capacity_lookup` and `variant_lookup` from `SELECT DISTINCT` of non-empty `appliance_details` values. Each row's `category_id` is set from the category of the products using it when unambiguous, left NULL when the same string spans multiple categories.
- `issuable_unit_preset` from distinct `(label, unit_id, conversion_factor)` combinations in `product_issuable_units`.

Insert-if-not-exists, so it is rerunnable.

### API layer

**`app/Http/Controllers/Concerns/HandlesLookupCrud.php`** implements `index`, `store`, `show`, `update`, `destroy`, `archived`, `restore`, `forceDestroy` against hooks each controller supplies:

- `lookupModel()` — the Eloquent class
- `lookupNameColumn()` — `'capacity_value'`, `'variant_name'`, `'label'`, …
- `lookupRules(?int $id)` — validation, including case-insensitive uniqueness scoped per category
- `usageCountFor($row): int` — how many records reference this value
- `afterRename($row, $oldName, $newName)` — no-op by default

The trait uses the `ApiResponse` trait exactly as the existing controllers do, so response envelopes do not change.

**New controllers**, each roughly 40 lines of config plus its specifics:

- **`CapacityLookupController`** — `index` accepts `?category_id=` and returns that category's rows plus globals (`category_id IS NULL`). `usageCountFor` counts `appliance_details.capacity_rating = value`. `afterRename` runs the cascade.
- **`VariantLookupController`** — same shape against `variant`.
- **`IssuableUnitPresetController`** — no category scoping. `usageCountFor` counts `product_issuable_units` rows matching the label. Rename does **not** cascade: `product_issuable_units.label` is per-product and independently editable, so a preset rename only affects what future picks prefill.

**Retrofits:**

- `UnitLookupController` is a pure vanilla case and moves fully onto the trait; its 122 lines collapse to a config block and it gains the usage count.
- `CategoryLookupController` and `BrandLookupController` keep their bespoke `index`/`performance` (the inventory-value aggregates are real logic) but drop their hand-rolled `archived`/`restore`/`forceDestroy` for the trait's. Their `index` already returns `items_count`, so the usage-count contract is consistent across all six.

**Routes** — `/api/capacities`, `/api/variants`, `/api/issuable-unit-presets`, each with the same eight-route shape categories use minus the category-specific `performance`: `index`, `show`, `archived`, `store`, `update`, `destroy`, `restore`, `force`. `/api/units` keeps

> **Correction made during implementation: reads on all six lookups are ungated.**
>
> This spec originally gated reads on the three new lookups behind `permission:lookup_management,read`, matching the admin page's own gate. That was wrong, and it would have shipped a broken item master.
>
> `ProductRegisterModal` — which registers a product and, after this feature, uses strict capacity and variant dropdowns — is rendered from `BarcodeScan.jsx`, gated on `barcode_inventory_processing.create`: **admin, branch_manager, warehouse_personnel**. `lookup_management.read` grants **admin, inventory_analyst, auditor**. The two overlap only on admin, so a warehouse or branch user opening that modal would have seen empty capacity and variant dropdowns and been unable to register an appliance.
>
> Reads on capacities, variants and presets are therefore ungated, exactly as units, categories and brands already are and for exactly the same reason: these are classification vocabularies that feed pickers across the app, not sensitive records. The admin *page* stays gated on `lookup_management.read`, and every **write** stays gated on the appropriate key. This also removes the question of `/api/batch` bypassing the read gates — there is no read gate to bypass. its paths but its `role:admin,inventory_analyst` / `role:admin` middleware is replaced with `permission:lookup_management,{verb}`.

**Permission** — one new feature key `lookup_management` in `PermissionSeeder`:

| Verb | Roles |
|---|---|
| read | admin, inventory_analyst, auditor |
| create | admin, inventory_analyst |
| update | admin, inventory_analyst |
| archive | admin |

Mirrors `category_management` exactly, so nobody's effective access changes. The new page gates on `lookup_management.read`.

**Batch** — `BatchController` gains `capacities`, `variants`, and `issuable_unit_presets` includes alongside `categories` / `brands` / `units`, so the management page and `ProductRegisterModal` fetch in one request instead of six.

### Frontend

Three files replace `CategoryBrandManagement.jsx`:

- **`pages/LookupManagement.jsx`** — the shell. Header, two top tabs, sub-tab strip, active panel. Owns tab state and URL sync only; no CRUD logic.
- **`components/LookupPanel.jsx`** — one generic list, used six times. Search, Add button, rows with usage count and Edit / Archive actions, a "Show archived" toggle that swaps in archived rows with Restore, and the add/edit modal built from the config's field list. All CRUD lives here, once.
- **`config/lookupConfigs.js`** — six config objects: display label, API module, name field, extra form fields, category-scoped flag, archive-warning copy. A seventh lookup is an entry here.

`services/api.js` gains `capacitiesAPI`, `variantsAPI`, `issuableUnitPresetsAPI` following the existing module shape.

**Routing and nav.** `/admin/categories` and `/admin/brands` both keep working and now render `LookupManagement`, with `/admin/brands` opening on the Appliance tab, Brands sub-tab — replacing the `scrollIntoView` hack at `CategoryBrandManagement.jsx:113`. Sub-tab selection reflects in a `?tab=` query param so a specific lookup is linkable.

Files touched: `App.jsx:117-118`, `Sidebar.jsx:22-23,75`, `navBundles.js:15`, `navConfigureMap.js:19`, `roles.js:80-81`, `NavBundleSeeder.php:35`.

Nav label changes from "Categories and Brands" to **"Item Lookups"**.

**Category Performance** moves into a collapsible panel under the Categories sub-tab, collapsed by default. Nothing is lost, but a reporting table stops being the first thing on a config page.

**Archive** is per-panel via the "Show archived" toggle. The global Archive section with its own inner tabs is removed — it does not scale to six lookups. The archive confirmation shows the usage count.

**Item forms** — where the feature pays off:

- `ProductRegisterModal.jsx` — the hardcoded `APPLIANCE_CAPACITY_RATINGS` array (line 18) is deleted. Capacity and Variant become strict selects fed from the lookups, filtered by the selected Category. The inconsistency where capacity is a dropdown in one branch and a text input in the other (lines 459-502) is resolved to one select.
- `ItemManagement.jsx` — the same two free-text inputs (lines 1110, 1188) get the same treatment. Existing capacity-required validation (line 451) is unchanged.
- Adding an issuable unit to a product gains a preset picker that prefills label, unit, and conversion factor, all still editable per product.

**Styling** follows the `ak-*` design system already used across the admin, reusing `airking_ds.css` primitives rather than growing `category_brand_management.css`.

**Accepted consequence:** after this, an item's capacity can only be a value in the lookup. If the backfill misses something, whoever registers items is blocked until an admin adds it. The backfill covering every distinct existing value is what keeps this from biting on day one.

## Error handling and edge cases

**Archived value still on an item.** If "3.5HP" is archived while 12 products store it, a strict select with no matching option resets the value to blank and saving wipes it. Both item forms inject the item's stored value as an option labelled "3.5HP (archived)" when it is not in the active list — selectable but marked, so opening the form destroys nothing.

**Rename cascade failure.** The rename and the `appliance_details` / `product_sets` updates run in one transaction; any failure rolls back the lookup row too. The response reports how many product rows were updated, surfaced as "Renamed — 47 items updated".

**Rename onto an existing value.** Offers a merge: "'1.5HP' already exists — merge these 12 items into it?" Merging repoints the source's product rows onto the target and archives the source, in the same transaction using the same cascade machinery.

**Backfill and dirty data.** The migration skips null/empty/whitespace values and preserves case variants as separate rows rather than guessing they are the same — products reference the exact string, and collapsing them would need the cascade, which a migration should not run quietly. Expect junk on first load; cleaning it up with merge is the intended first-day task.

**Category reassignment on an item.** Changing an item's category clears capacity and variant if the selected values do not belong to the new category, with a toast rather than a silent reset.

**FK restrict on units.** `issuable_unit_preset.unit_id` and `product_issuable_units.unit_id` both restrict, so permanently deleting a referenced unit throws. Caught and returned as "Cannot delete — used by 3 issuable unit presets" instead of a 500.

**Permanent delete.** Admin only, refused when usage count is above zero. Archive is the reversible path; force-delete is for genuine mistakes.

**Permission-degraded view.** A user with `lookup_management.read` but no create/update gets the lists with Add/Edit/Archive hidden, reusing the existing `isViewOnly` pattern.

**Batch fetch failure.** Keeps the existing fallback: batch call fails → individual requests per lookup → error state.

**Duplicate validation.** Case-insensitive, scoped per category, enforced in request rules since MySQL's NULL handling makes a DB unique index unreliable here. Errors surface in the modal through the existing toast helper.

## Testing

PHPUnit feature tests using `DatabaseTransactions` against `airkingV5_test`, per project convention (the schema is a SQL dump, not migrations, so a blanket `migrate` fails).

**Trait behaviour, tested once.** `HandlesLookupCrud` is exercised in full against `CapacityLookupController` (it uses every hook): create, duplicate rejection, case-insensitive duplicate rejection, update, archive, archived listing, restore, force delete. The other five controllers get a thin test covering only their specifics.

**Per-lookup specifics:**

- Capacity/Variant — `?category_id=X` returns that category's rows plus globals and excludes others'; uniqueness is per-category, so "1.5HP" can exist under two categories but not twice under one.
- Issuable unit presets — a preset prefills a product's issuable unit correctly; renaming a preset does not touch `product_issuable_units.label`.
- Units — the retrofit does not change response shape; existing `/api/units` consumers still work.
- Categories/Brands — `index`/`performance` aggregates unchanged after the archive/restore retrofit.

**Cascade and merge** — the only genuinely stateful logic, tested hardest:

- Rename updates every matching `appliance_details` and `product_sets` row and reports the count.
- Rename failure mid-transaction rolls back the lookup row, leaving no partial state.
- Merge repoints the source's product rows onto the target and archives the source; counts reconcile.
- Rename of a value used by zero products succeeds and reports zero.

**Permissions** — the existing permission-matrix test style, asserting each role against `lookup_management` verbs, that `/api/units` is permission-gated rather than role-gated, and that admin still bypasses.

**Backfill migration** — run against a fixture with dirty data (nulls, empty strings, whitespace, case variants, a value spanning two categories) and assert: junk skipped, case variants preserved separately, the multi-category value left with `category_id` NULL, and a second run inserting nothing.

**Archived-value-on-item guard** — asserted at the API level (an item retaining an archived capacity round-trips through update unchanged), since that is where the damage would be permanent. The form-level "(archived)" option is verified manually.

**Not automated:** frontend component tests. This repo has no React test setup and adding one is a separate project. Tab and panel behaviour gets a manual check against the running app.
