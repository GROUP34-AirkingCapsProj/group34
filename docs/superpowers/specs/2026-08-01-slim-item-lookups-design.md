# Slim the Item Lookups — Design

**Date:** 2026-08-01
**Branch:** backupV3
**Status:** Approved for planning

Two independent changes, specced together but shipped separately.

- **Change A** fixes a live defect: categories can be created that are invisible in every Item Master dropdown.
- **Change B** strips the lookup tables to only the columns that specify a product.

Change A stands alone and is worth shipping first. Change B is simplification and depends on nothing in A.

---

# Change A — fix `category_type`

## The defect

Two hardcoded filters in `frontend/src/admin/pages/ItemManagement.jsx` decide which categories appear in which dropdown:

```jsx
categories.filter(c => !c.category_type || c.category_type === 'appliance')   // line 1144
categories.filter(c => c.category_type === 'consumable')                       // line 1347
```

A category whose type is neither `appliance` nor `consumable`, and is not null, matches **neither filter** — it disappears from both dropdowns and no product can be assigned to it. Three paths produce exactly that today:

| Path | Value written |
|---|---|
| The Item Lookups page (added in the lookups feature) | `product` / `part` / `accessory` / `service` |
| `CategoryLookupController:150` — default when type is omitted | `product` |
| `ProductController:111` — auto-creates a category from a name string | `product` |

The Item Lookups dropdown options were copied verbatim from the old Categories & Brands page rather than checked against the data. That page was already wrong; the error was inherited.

## Data migration

All 22 categories end up as one of two values:

| From | Count | To |
|---|---|---|
| `appliance` | 6 | `appliance` |
| `consumable` | 14 | `installation_material` |
| `accessory` | 2 | `installation_material` |

Final: 6 appliance, 16 installation_material.

`installation_material` is chosen over keeping `consumable` so the value matches `products.product_type`, which already uses `installation_material`. Two different words for the same concept in two adjacent tables is what made this area easy to get wrong.

## Code sites

Five, all switching `'consumable'` to `'installation_material'`:

1. `ItemManagement.jsx:1347` — the installation-material filter.
2. `ItemManagement.jsx:320` — inline category create; already derives from `product_type`, needs the new value.
3. `ItemManagement.jsx:1144` — the appliance filter keeps its `!c.category_type ||` fallback; null still means "show for appliances".
4. `CategoryLookupController:150` — default becomes `'appliance'` instead of `'product'`, so a type-less create lands somewhere visible.
5. `ProductController:111` — derive from the product rather than hardcoding: `installation_material` when `$input['product_type']` says so, otherwise `appliance`.

The dropdown in `frontend/src/admin/config/lookupConfigs.js` becomes two options — **Appliance** → `appliance`, **Installation Material** → `installation_material` — defaulting to `appliance`.

## Explicitly not renamed

The wider "consumable" vocabulary stays: `consumableDetail`, `installation_material_details.consumable_detail_id`, and the `consumable` references in `ArchiveController`, `InventoryController` and the scan controllers. Those describe **product types and table relations**, not category types. Renaming them is a far larger change with no bearing on this defect, and conflating the two is how the inconsistency arose.

---

# Change B — slim the tables

## Columns dropped

| Table | Columns | Data present |
|---|---|---|
| `capacity_lookup` | `category_id`, `sort_order`, `description` | 11 rows, all one category; 0 sort orders; 0 descriptions |
| `variant_lookup` | `category_id`, `sort_order`, `description` | 54 rows across 5 categories; 0 sort orders; 2 descriptions |
| `category_lookup` | `description` | 22 of 22 populated |
| `brand_lookup` | `description` | 1 of 17 populated |

`sort_order` was added during the lookups build on the reasoning that alphabetical sorting puts `10HP` before `2HP`. That problem is real; hand-numbering every row is a bad solution, and the data confirms nobody used it — **zero** non-default values on either table.

## What stays

`unit_lookup.unit_abbreviation` is **load-bearing**, not decoration: the barcode scan payload, issuance and inventory all read it to render "pc" / "m" / "kg" beside quantities.

## The 52 descriptor variants

The variant lookup holds 54 rows. Two — `Inverter` and `Non-inverter` — are the real vocabulary and are the only globals. The other 52 are historical free-text descriptors the backfill lifted out of existing products:

```
Ceiling Cassette Inverter (15)   Floor Standing Inverter (15)
Window Smart Inverter (WiFi) (10)   2 Door Top Mount Defrost Non-Inverter (6)
```

They are **archived** (soft-deleted), not deleted. Products keep their stored strings and show them marked `(archived)` when opened — visible, not re-selectable. Deleting outright was considered and declined as unnecessarily irreversible; rewriting the ~200 products' `variant` values to `Inverter`/`Non-inverter` was declined because descriptors like "Ceiling Cassette" describe real physical differences.

Category scoping currently hides most of these from any given dropdown. Once `category_id` is dropped they would all appear everywhere, so archiving is required, not optional.

## The cascade

Capacity and variant are the only consumers of category scoping, so removing it retires a whole layer:

- **`app/Http/Controllers/Concerns/ScopesLookupsToCategory.php` is deleted.** Both controllers drop their `use` and their `insteadof` disambiguation, falling back to `HandlesLookupCrud::lookupUniqueRule()`, which derives table, primary key and name column from the model — correct once uniqueness is global rather than per-category.
- `lookupIndexFilters` / `lookupArchivedFilters` overrides go, along with `?category_id=` handling on both endpoints.
- `categoryScoped` leaves the config vocabulary, and with it the `category` field type and the `categories` prop plumbing through `LookupManagement` → `LookupPanel`.
- `rowAppliesToCategory`, the filtered memos, and the clear-on-category-change effect are removed from both `ProductRegisterModal.jsx` and `ItemManagement.jsx`. Capacity and variant dropdowns become plain lists of all active values.
- The `(archived)` fallback in both item forms **stays** — it guards real product data.

## A bug fixed in passing

`LookupPanel.renderCell` has a `category_name` branch resolving the value from `row.category_id` against the categories list. For the categories lookup itself this works only **by accident**: a category row's `category_id` is its own primary key, so the lookup finds itself and returns its own name. It is the same shape as the `unit_name` bug that made every Units row render an em dash.

With capacity and variant no longer scoped, the branch has no legitimate consumer. **Delete it** and let `category_name` fall through to the direct `row['category_name']` — correct rather than accidental.

## A side effect worth naming

Dropping `category_id` resolves the empty-dropdown problem recorded in the lookups plan: all 11 capacities were scoped to Air Conditioning, so every other category showed an empty capacity list. Unscoped, all 11 appear everywhere, and the empty-state hint added for that problem becomes unreachable for capacity — it stays in place for the genuinely-empty case.

---

# Migrations and data handling

## Change A

One migration updating values, guarded so it is re-runnable:

```sql
UPDATE category_lookup SET category_type = 'installation_material'
WHERE category_type IN ('consumable', 'accessory')
```

`down()` reverses `installation_material` → `consumable`. It **cannot** distinguish the 2 rows that were `accessory`; they return as `consumable`. The migration says so in a comment — that is the honest limit of a value migration, not something to paper over.

## Change B

One migration dropping columns, each guarded with `Schema::hasColumn`, plus one data statement:

```sql
UPDATE variant_lookup SET deleted_at = NOW()
WHERE variant_name NOT IN ('Inverter', 'Non-inverter') AND deleted_at IS NULL
```

## The schema dump

`airkingv5.sql` is the source of truth for fresh installs. Reference counts:

| Table | References in dump |
|---|---|
| `category_lookup` | 11 |
| `brand_lookup` | 11 |
| `unit_lookup` | 9 |
| `capacity_lookup` | **0** |
| `variant_lookup` | **0** |

`capacity_lookup` and `variant_lookup` were created by migrations during the lookups build, so the dump predates them entirely — **no dump edits for those two.**

For `category_lookup` and `brand_lookup` the dump needs:

- The `category_type` column `COMMENT`, which currently reads `'appliance | consumable | accessory'`, updated to `'appliance | installation_material'`.
- The `category_type` values inside the `category_lookup` INSERT rows rewritten.
- `description` removed from both `CREATE TABLE` statements **and** from every INSERT row, including from the `INSERT INTO … (columns)` list.

**The INSERT edit is the risky step.** Every row in a multi-row `VALUES` list carries a description value; miss one and the column count no longer matches and the dump fails to import — silently, on someone else's fresh clone. Two viable methods:

1. **Surgical hand-edit** — small diff, fiddly across 22 category rows and 17 brand rows.
2. **Regenerate those two table blocks** with `mysqldump` after the migrations run and splice them in — robust, but churns row ordering and `AUTO_INCREMENT` values.

Try 1, fall back to 2 if it gets messy. **Either way the acceptance test is the same and non-negotiable: import the edited dump into a scratch database and confirm it succeeds with the expected table count.** That is how the issuable-units dump edit was verified, and it is the only check that catches this class of error.

## Both databases

Every migration applies to `airkingV5` and `airkingV5_test`. There is no separate test connection — `phpunit.xml` overrides `DB_DATABASE` on the default one, so target the test database from the **Bash** tool as `DB_DATABASE=airkingV5_test php artisan migrate --path=…`. The env-prefix form does not work in PowerShell.

## Backup

A `mysqldump` of `category_lookup`, `brand_lookup`, `capacity_lookup` and `variant_lookup` is taken **before either migration runs**, stored outside the repo and not committed. The description text on 22 categories and the full 54-row variant table are real data; once the columns are dropped that text is unrecoverable.

---

# Testing

The test surface is larger than the code surface — **46 references across 7 files** — because several suites were built specifically to prove category scoping works.

## Deleted

`tests/Feature/Lookups/CapacityScopingTest.php` (6 tests). Every one asserts scoping: filtering by category, per-category uniqueness, the `IS NULL` globals arm. With scoping gone the file has no subject.

## Rescued from it

Two of its tests pinned a real data-integrity bug found during the original build:

- `test_two_uncategorised_rows_may_not_share_a_value` — uniqueness among unscoped rows.
- The regression proving uniqueness resolves the category from the **persisted row** on update rather than from the request, which is what stopped two identical values slipping into one category.

Their fates differ. The second becomes meaningless — with no `category_id` there is no category to resolve, so the bug it guards cannot exist. It goes.

The first survives, because uniqueness does not disappear, it becomes **global**: two capacities cannot share a value, full stop. It moves to `LookupCrudTraitTest`, where the generic CRUD coverage lives. Letting it vanish with the file would silently drop a guarantee that still holds.

## Amended

- `VariantLookupTest` — drop the category-scoping test; keep the cascade and width tests. Add one asserting a variant carries no `category_id`.
- `LookupCrudTraitTest` — drop `sort_order` from payloads, including the `sort_order: null` → 422 test, which no longer has a field to test.
- `BatchIncludesTest` — delete the `?category_id=` isolation test. It proves batch ignores stray category filters; with no scoping there is no filter to ignore.
- `BackfillTest` — the backfill no longer assigns `category_id`, so the "unambiguous category" and "spans two categories → NULL" tests go. The case-variant, junk-skipping, over-length and idempotency tests stay: those cover value handling, not scoping.
- `LookupRouteAccessTest` — remove `category_id` from capacity payloads. The permission matrix is unaffected.
- `RetrofitCompatTest` — categories and brands lose `description` from their aggregate assertions.

## New

1. The `category_type` migration maps `consumable` and `accessory` to `installation_material`, leaves `appliance` untouched, and is re-runnable.
2. `ProductController` auto-creating a category from a name string derives the type from the product: `installation_material` in, `installation_material` category out. This is the bug path that produced invisible categories.
3. The dropped columns are genuinely gone — creating a capacity with `category_id` or `sort_order` in the payload succeeds and ignores them rather than erroring.

## Unchanged and load-bearing

The acceptance criteria remain what they have been throughout: `IssuanceBatchFifoTest`, `IssuanceRemoveItemBatchTest`, `CancelIssuanceTest`, `StockHistoryMovementSummaryTest`. None touch lookups, so any change in them means something went wrong.

## Frontend

There is no React test harness. The `LookupPanel` `category_name` fix, the removed filtering in both item forms, and the corrected dropdown are verified by `npm run build` plus a manual pass.

Given the Units em-dash bug hid in exactly this gap for the whole lookups build, the manual pass must specifically open **Item Lookups → each of the five tabs** and confirm every Name column renders real values rather than em dashes.

## Expected end state

Failures stay at 9 (`WarrantyClaimTest`), plus the flaky `SerialRowMovementColumnsTest` roughly one run in three.

Passing drops by roughly **8** net:

| | Tests |
|---|---|
| `CapacityScopingTest` deleted | −6 |
| Amendments (variant scoping, `sort_order: null`, batch `category_id`, two backfill category tests) | −5 |
| New coverage | +3 |
| **Net** | **−8** |

Against the current 691 passing, expect roughly **683**.
