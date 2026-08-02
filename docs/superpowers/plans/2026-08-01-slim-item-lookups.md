# Slim the Item Lookups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix a defect that makes categories invisible in every Item Master dropdown, then strip the lookup tables to only the columns that specify a product.

**Architecture:** Two independent changes. **Change A** (Tasks 1-3) is a correctness fix and ships on its own. **Change B** (Tasks 4-7) is simplification and depends on nothing in A. Within each, consumers come before providers — frontend before API before schema — so nothing ever calls something that no longer exists.

**Tech Stack:** Laravel 11 (PHP 8.2), MySQL/MariaDB via XAMPP, PHPUnit with `DatabaseTransactions`, React 18 (CRA).

**Spec:** `docs/superpowers/specs/2026-08-01-slim-item-lookups-design.md`

---

## Environment notes — read before Task 1

- **The schema is a SQL dump, not a migration history.** Bare `php artisan migrate` fails. Apply each migration explicitly with `--path=`.
- **Two databases.** Dev is `airkingV5`; the test DB is targeted as `DB_DATABASE=airkingV5_test php artisan …` from the **Bash** tool only (PowerShell has no inline env-var prefix). There is no separate connection — `phpunit.xml` overrides `DB_DATABASE` on the default one.
- **Run backend commands from `back-end/`, frontend from `frontend/`.**
- **Known-failing baseline, do NOT chase:** 9 tests in `Tests\Feature\WarrantyClaimTest` fail (`WarrantyClaimException`), plus a flaky `Tests\Feature\Inventory\SerialRowMovementColumnsTest::consumable rows carry per batch in and out` that passes about one run in three. Current: **691 passed**, 9-10 failed, 1 skipped.
- **`ItemManagement.jsx` has exactly two pre-existing eslint warnings** — `react-hooks/exhaustive-deps` on `fetchItems`, and unused `passesCatalogCompleteness`. Do not add a third.
- **The working tree carries 7 unrelated uncommitted files** — `back-end/app/Http/Controllers/Api/InventoryScanTransactionController.php`, four barcode/scan JSX pages, two barcode stylesheets. They are **not** part of this work. **Never run `git add -A`, `git add .`, or `git add <directory>`** — every commit below lists explicit paths.
- **Do not kill `php.exe` processes** — the user may have servers running.

## The acceptance criteria

These four must stay green at **every** task. None of them touch lookups, so a change in any of them means something went wrong:

```bash
php artisan test --filter="IssuanceBatchFifoTest|IssuanceRemoveItemBatchTest|CancelIssuanceTest|StockHistoryMovementSummaryTest"
```

Expected: **14 passed**.

## Files touched

| Change | File | Action |
|---|---|---|
| A | `back-end/database/migrations/2026_08_01_000002_normalise_category_type.php` | create |
| A | `back-end/app/Http/Controllers/Api/CategoryLookupController.php` | default `product` → `appliance` |
| A | `back-end/app/Http/Controllers/Api/ProductController.php` | derive type from product |
| A | `back-end/tests/Feature/Lookups/CategoryTypeTest.php` | create |
| A | `frontend/src/admin/pages/ItemManagement.jsx` | 3 sites |
| A | `frontend/src/admin/config/lookupConfigs.js` | dropdown options |
| A | `airkingv5.sql` | comment + `category_type` values |
| B | `back-end/database/migrations/2026_08_01_000003_archive_descriptor_variants.php` | create |
| B | `frontend/src/admin/config/lookupConfigs.js` | drop fields/columns |
| B | `frontend/src/admin/components/LookupPanel.jsx` | drop `category` type, `category_name` branch, `categories` prop |
| B | `frontend/src/admin/pages/LookupManagement.jsx` | stop passing `categories` |
| B | `frontend/src/admin/components/ProductRegisterModal.jsx` | drop category filtering |
| B | `frontend/src/admin/pages/ItemManagement.jsx` | drop category filtering |
| B | `back-end/app/Http/Controllers/Concerns/ScopesLookupsToCategory.php` | delete |
| B | `back-end/app/Http/Controllers/Api/CapacityLookupController.php` | drop scoping |
| B | `back-end/app/Http/Controllers/Api/VariantLookupController.php` | drop scoping |
| B | `back-end/database/seeders/LookupBackfill.php` | stop assigning `category_id` |
| B | `back-end/tests/Feature/Lookups/CapacityScopingTest.php` | delete |
| B | 5 other test files | amend |
| B | `back-end/database/migrations/2026_08_01_000004_slim_lookup_tables.php` | create |
| B | `airkingv5.sql` | drop `description` from 2 tables |

---

# CHANGE A — fix `category_type`

## Task 1: Backend — migration and the two auto-create paths

**Files:**
- Create: `back-end/database/migrations/2026_08_01_000002_normalise_category_type.php`
- Modify: `back-end/app/Http/Controllers/Api/CategoryLookupController.php:150`
- Modify: `back-end/app/Http/Controllers/Api/ProductController.php:108-113`
- Create: `back-end/tests/Feature/Lookups/CategoryTypeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Lookups;

use App\Models\CategoryLookup;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Lookups\LookupTestCase;

class CategoryTypeTest extends LookupTestCase
{
    /**
     * Categories whose type is neither 'appliance' nor 'installation_material'
     * match neither Item Master dropdown filter and become unselectable. These
     * tests pin the three paths that used to produce such rows.
     */
    public function test_controller_defaults_a_typeless_category_to_appliance(): void
    {
        $this->actingAsAdmin();

        $name = 'Cat ' . uniqid();
        $id = $this->postJson('/api/categories', ['category_name' => $name])
            ->assertStatus(201)
            ->json('data.category_id');

        $this->assertSame('appliance', CategoryLookup::find($id)->category_type);
    }

    public function test_product_import_derives_category_type_from_the_product(): void
    {
        $this->actingAsAdmin();

        $catName = 'Cat ' . uniqid();

        // assertSuccessful, not assertStatus(201): ProductController::store()
        // takes $request->all() with no upfront validate(), so the exact
        // success code is incidental. What this test pins is the category
        // type, and a hard-coded status would make it fail for the wrong
        // reason if store() ever returns 200.
        $this->postJson('/api/products', [
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Material ' . uniqid(),
            'product_type' => 'installation_material',
            'category'     => $catName,
        ])->assertSuccessful();

        $cat = CategoryLookup::where('category_name', $catName)->first();

        $this->assertNotNull($cat, 'the category should have been auto-created');
        $this->assertSame('installation_material', $cat->category_type);
    }

    public function test_no_category_carries_a_type_outside_the_two_valid_values(): void
    {
        // The migration normalises consumable and accessory. Anything else
        // would be invisible in both Item Master dropdowns.
        $stray = DB::table('category_lookup')
            ->whereNotIn('category_type', ['appliance', 'installation_material'])
            ->whereNotNull('category_type')
            ->pluck('category_type')
            ->unique();

        $this->assertEmpty($stray->all(), 'stray category_type values: ' . $stray->implode(', '));
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd back-end
php artisan test --filter=CategoryTypeTest
```

Expected: FAIL. The first test gets `product` instead of `appliance`; the third finds `consumable` and `accessory`.

- [ ] **Step 3: Write the migration**

Create `back-end/database/migrations/2026_08_01_000002_normalise_category_type.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Normalises category_type to exactly two values.
     *
     * ItemManagement.jsx filters categories with `=== 'appliance'` and
     * `=== 'installation_material'`. Anything else matches neither and the
     * category becomes unselectable in both dropdowns. 'consumable' is the
     * old name for installation materials; 'accessory' was never handled by
     * either filter. Using 'installation_material' matches
     * products.product_type, which already uses that word.
     */
    public function up(): void
    {
        DB::table('category_lookup')
            ->whereIn('category_type', ['consumable', 'accessory'])
            ->update(['category_type' => 'installation_material']);
    }

    /**
     * Reverses the rename. This CANNOT distinguish the rows that were
     * 'accessory' before up() ran — they come back as 'consumable'. That is
     * the honest limit of a value migration with no record of the prior value.
     */
    public function down(): void
    {
        DB::table('category_lookup')
            ->where('category_type', 'installation_material')
            ->update(['category_type' => 'consumable']);
    }
};
```

- [ ] **Step 4: Fix the controller default**

In `back-end/app/Http/Controllers/Api/CategoryLookupController.php`, line 150 currently reads:

```php
        $validated['category_type'] = $validated['category_type'] ?? 'product';
```

Replace with:

```php
        // 'appliance', not 'product': ItemManagement.jsx filters on exactly
        // 'appliance' / 'installation_material', so any other value makes the
        // category invisible in both dropdowns.
        $validated['category_type'] = $validated['category_type'] ?? 'appliance';
```

- [ ] **Step 5: Fix the product-import auto-create**

In `back-end/app/Http/Controllers/Api/ProductController.php`, the block around line 108 currently reads:

```php
            if (empty($input['category_id']) && !empty($input['category'])) {
                $cat = CategoryLookup::firstOrCreate(
                    ['category_name' => trim($input['category'])],
                    ['category_type' => 'product']
                );
                $input['category_id'] = $cat->category_id;
            }
```

Replace with:

```php
            if (empty($input['category_id']) && !empty($input['category'])) {
                // Derive the type from the product being imported. Hardcoding
                // 'product' created categories that matched neither Item Master
                // dropdown filter, so the product could never be re-categorised.
                $categoryType = ($input['product_type'] ?? null) === 'installation_material'
                    ? 'installation_material'
                    : 'appliance';

                $cat = CategoryLookup::firstOrCreate(
                    ['category_name' => trim($input['category'])],
                    ['category_type' => $categoryType]
                );
                $input['category_id'] = $cat->category_id;
            }
```

- [ ] **Step 6: Apply the migration to both databases**

```bash
php artisan migrate --path=database/migrations/2026_08_01_000002_normalise_category_type.php
DB_DATABASE=airkingV5_test php artisan migrate --path=database/migrations/2026_08_01_000002_normalise_category_type.php
```

Expected: `DONE` from each. The second must be run from the **Bash** tool.

- [ ] **Step 7: Verify the data**

```bash
php artisan tinker --execute="foreach(DB::table('category_lookup')->select('category_type', DB::raw('count(*) n'))->groupBy('category_type')->get() as \$r) echo \$r->category_type.': '.\$r->n.PHP_EOL;"
```

Expected: `appliance: 6` and `installation_material: 16`, nothing else.

- [ ] **Step 8: Run the tests**

```bash
php artisan test --filter=CategoryTypeTest
php artisan test --filter="IssuanceBatchFifoTest|IssuanceRemoveItemBatchTest|CancelIssuanceTest|StockHistoryMovementSummaryTest"
```

Expected: 3 passed, then 14 passed.

- [ ] **Step 9: Commit**

```bash
git add back-end/database/migrations/2026_08_01_000002_normalise_category_type.php back-end/app/Http/Controllers/Api/CategoryLookupController.php back-end/app/Http/Controllers/Api/ProductController.php back-end/tests/Feature/Lookups/CategoryTypeTest.php
git commit -m "fix(lookups): normalise category_type so categories stay selectable"
```

---

## Task 2: Frontend — the two filters and the dropdown

**Files:**
- Modify: `frontend/src/admin/pages/ItemManagement.jsx` (3 sites)
- Modify: `frontend/src/admin/config/lookupConfigs.js`

- [ ] **Step 1: Fix the inline-create mapping**

`ItemManagement.jsx` around line 320:

```jsx
      const category_type = productType === 'installation_material' ? 'consumable' : 'appliance';
```

becomes:

```jsx
      const category_type = productType === 'installation_material' ? 'installation_material' : 'appliance';
```

- [ ] **Step 2: Fix the installation-material filter**

Around line 1347:

```jsx
                        {categories.filter(c => c.category_type === 'consumable').map((c) => (
```

becomes:

```jsx
                        {categories.filter(c => c.category_type === 'installation_material').map((c) => (
```

- [ ] **Step 3: Leave the appliance filter alone**

Around line 1144 it reads:

```jsx
                        {categories.filter(c => !c.category_type || c.category_type === 'appliance').map((c) => (
```

**Do not change this.** The `!c.category_type ||` fallback is deliberate — a null type still means "show for appliances", and that is why null-typed categories were never part of the bug.

- [ ] **Step 4: Fix the dropdown options**

In `frontend/src/admin/config/lookupConfigs.js`, the `categories` entry's `category_type` field currently offers four options (`product`, `part`, `accessory`, `service`). Replace the whole field definition's `options` and `defaultValue` so it reads:

```js
      {
        name: 'category_type',
        label: 'Type',
        type: 'select',
        defaultValue: 'appliance',
        options: [
          { value: 'appliance', label: 'Appliance' },
          { value: 'installation_material', label: 'Installation Material' },
        ],
      },
```

These are the only two values `ItemManagement.jsx` filters on; any other value makes the category unselectable.

- [ ] **Step 5: Verify nothing still writes the old value**

```bash
cd frontend
grep -rn "'consumable'" src/admin/pages/ItemManagement.jsx src/admin/config/lookupConfigs.js || echo clean
```

Expected: `clean`. Other `consumable` mentions elsewhere in the file refer to product types and table relations — do not touch those.

- [ ] **Step 6: Lint and build**

```bash
npx eslint src/admin/pages/ItemManagement.jsx src/admin/config/lookupConfigs.js
npm run build
```

Expected: `ItemManagement.jsx` at exactly its 2 pre-existing warnings, `lookupConfigs.js` at 0, build exits 0.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/pages/ItemManagement.jsx frontend/src/admin/config/lookupConfigs.js
git commit -m "fix(lookups): point category filters and dropdown at installation_material"
```

---

## Task 3: Change A — schema dump

**Files:**
- Modify: `airkingv5.sql`

- [ ] **Step 1: Back up first**

```bash
cd /c/XAMPP1/htdocs/Airking_Inventory
/c/XAMPP1/mysql/bin/mysqldump.exe -u root airkingV5 category_lookup brand_lookup capacity_lookup variant_lookup > "$TMPDIR/lookups-backup-$(date +%s).sql"
```

Confirm the file is non-empty. Keep it outside the repo; do not commit it. This covers Change B as well, so it only needs doing once.

- [ ] **Step 2: Update the column comment**

In `airkingv5.sql`, the `category_lookup` CREATE statement contains:

```sql
  `category_type` varchar(50) NOT NULL COMMENT 'appliance | consumable | accessory',
```

Change the comment to `'appliance | installation_material'`.

- [ ] **Step 3: Rewrite the values in the INSERT rows**

In the same file, the `INSERT INTO \`category_lookup\`` statement has one row per category, each carrying a `category_type` value. Replace every `'consumable'` and `'accessory'` in **that statement's rows only** with `'installation_material'`.

Do not use a global find-and-replace on the file — `consumable` appears in other tables' data and comments. Work inside the bounds of that one INSERT.

- [ ] **Step 4: Verify the dump has no stale values**

```bash
awk '/INSERT INTO `category_lookup`/,/;$/' airkingv5.sql | grep -c "consumable\|accessory"
```

Expected: `0`.

- [ ] **Step 5: Prove the dump still imports**

This is the only check that catches a malformed edit — a broken dump fails silently on someone else's fresh clone.

```bash
M=/c/XAMPP1/mysql/bin
"$M/mysql.exe" -u root -e "DROP DATABASE IF EXISTS airking_dumptest; CREATE DATABASE airking_dumptest;"
"$M/mysql.exe" -u root airking_dumptest < airkingv5.sql
"$M/mysql.exe" -u root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='airking_dumptest';"
"$M/mysql.exe" -u root -N -e "SELECT category_type, COUNT(*) FROM airking_dumptest.category_lookup GROUP BY category_type;"
"$M/mysql.exe" -u root -e "DROP DATABASE airking_dumptest;"
```

Expected: import succeeds, **72 tables**, and the category types are only `appliance` and `installation_material`.

- [ ] **Step 6: Commit**

```bash
git add airkingv5.sql
git commit -m "fix(lookups): normalise category_type in the schema dump"
```

**Change A is now complete and shippable on its own.**

---

# CHANGE B — slim the tables

## Task 4: Archive the descriptor variants

Independent of everything else and immediately useful — do it first so the variant dropdown is clean before the scoping change makes all rows visible.

**Files:**
- Create: `back-end/database/migrations/2026_08_01_000003_archive_descriptor_variants.php`

- [ ] **Step 1: Check what will be archived**

```bash
cd back-end
php artisan tinker --execute="
echo 'keeping: '.DB::table('variant_lookup')->whereIn('variant_name',['Inverter','Non-inverter'])->count().PHP_EOL;
echo 'archiving: '.DB::table('variant_lookup')->whereNotIn('variant_name',['Inverter','Non-inverter'])->whereNull('deleted_at')->count().PHP_EOL;"
```

Expected: `keeping: 2`, `archiving: 52`. If the keeping count is not 2, **stop and report** — the two real variants may be spelled differently than expected.

- [ ] **Step 2: Write the migration**

Create `back-end/database/migrations/2026_08_01_000003_archive_descriptor_variants.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Archives the 52 free-text descriptors the backfill lifted out of
     * existing products ("Ceiling Cassette Inverter", "Floor Standing
     * Inverter", …), leaving only the real variant vocabulary.
     *
     * Archived rather than deleted: ~200 products still store those strings
     * in appliance_details.variant, and the item forms surface a stored value
     * as "<value> (archived)" so nothing is silently blanked. Reversible by
     * restoring the rows.
     *
     * This matters more once category scoping is dropped — with no
     * category_id every variant appears in every dropdown.
     */
    public function up(): void
    {
        DB::table('variant_lookup')
            ->whereNotIn('variant_name', ['Inverter', 'Non-inverter'])
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);
    }

    /**
     * Restores everything this archived. It cannot distinguish rows archived
     * by up() from rows an admin archived by hand afterwards, so a rollback
     * un-archives both.
     */
    public function down(): void
    {
        DB::table('variant_lookup')
            ->whereNotIn('variant_name', ['Inverter', 'Non-inverter'])
            ->update(['deleted_at' => null]);
    }
};
```

- [ ] **Step 3: Apply to both databases**

```bash
php artisan migrate --path=database/migrations/2026_08_01_000003_archive_descriptor_variants.php
DB_DATABASE=airkingV5_test php artisan migrate --path=database/migrations/2026_08_01_000003_archive_descriptor_variants.php
```

- [ ] **Step 4: Verify**

```bash
php artisan tinker --execute="
echo 'active: '.DB::table('variant_lookup')->whereNull('deleted_at')->count().PHP_EOL;
foreach(DB::table('variant_lookup')->whereNull('deleted_at')->pluck('variant_name') as \$v) echo '  '.\$v.PHP_EOL;"
```

Expected: `active: 2`, listing `Inverter` and `Non-inverter`.

- [ ] **Step 5: Commit**

```bash
git add back-end/database/migrations/2026_08_01_000003_archive_descriptor_variants.php
git commit -m "refactor(lookups): archive backfilled descriptor variants"
```

---

## Task 5: Frontend — remove category scoping

Consumers first: the frontend stops sending and reading the doomed fields while the API still accepts them.

**Files:**
- Modify: `frontend/src/admin/config/lookupConfigs.js`
- Modify: `frontend/src/admin/components/LookupPanel.jsx`
- Modify: `frontend/src/admin/pages/LookupManagement.jsx`
- Modify: `frontend/src/admin/components/ProductRegisterModal.jsx`
- Modify: `frontend/src/admin/pages/ItemManagement.jsx`

- [ ] **Step 1: Strip the config**

In `lookupConfigs.js`:
- From the `capacities` and `variants` entries, remove the `category_id` field (type `category`), the `sort_order` field, and the `description` field. Remove the `category_name` column from both `columns` arrays.
- From the `categories` and `brands` entries, remove the `description` field and its column.
- Set `categoryScoped: false` on capacities and variants — or remove the key entirely if no entry uses it any more. Grep to confirm.
- Update the header comment: remove the `'category'` field type, the `'category_name'` derived column, the `allowEmpty` flag description, the `sort_order` / `omitWhenBlank` paragraph, and the whole `category_id` filtering gotcha block. Those describe machinery that no longer exists.

- [ ] **Step 2: Strip `LookupPanel`**

In `LookupPanel.jsx`:
- Delete the `case 'category':` branch in `renderField`.
- **Delete the `category_name` branch in `renderCell`.** It resolves the value from `row.category_id` against the categories list; for the categories lookup itself that only works by accident, because a category row's `category_id` is its own primary key so the lookup finds itself. It is the same shape as the `unit_name` bug that made every Units row render an em dash. Removing it lets `category_name` fall through to the direct `row['category_name']`, which is correct rather than accidental.
- Remove the `categories` prop, its default, and its mention in the prop docblock.
- Remove `allowEmpty` handling if nothing else uses it.

- [ ] **Step 3: Stop passing `categories` from the page**

In `LookupManagement.jsx`, remove the `categories={categories}` prop from `<LookupPanel …>`. Keep the `categories` state and its fetch **only if** something else on the page uses it — grep first. If nothing does, remove the state and drop `categories` from the `batchAPI.get({ include: [...] })` call, leaving `units`.

- [ ] **Step 4: Remove category filtering from `ProductRegisterModal`**

Delete `rowAppliesToCategory`, the `filteredCapacities` / `filteredVariants` memos' filtering (they become plain lists of all rows plus the archived fallback), and the effect that clears capacity/variant when the category changes, along with its `toast.info`.

**Keep the `(archived)` synthetic option.** If the item holds a value not in the active list, it must still appear marked — that guards real product data and is unrelated to scoping.

`hasFilteredCapacities` / `hasFilteredVariants` and the empty-state hints stay; they now fire only when the lookup is genuinely empty.

- [ ] **Step 5: Do the same in `ItemManagement`**

Identical treatment: remove `rowAppliesToCategory`, the filtering inside the two memos, and the clear-on-category-change effect. Keep the `(archived)` fallback and the empty-state hints.

- [ ] **Step 6: Verify**

```bash
cd frontend
grep -rn "rowAppliesToCategory\|categoryScoped\|category_name" src/admin/components/LookupPanel.jsx src/admin/components/ProductRegisterModal.jsx src/admin/pages/ItemManagement.jsx || echo clean
npx eslint src/admin/config/lookupConfigs.js src/admin/components/LookupPanel.jsx src/admin/pages/LookupManagement.jsx src/admin/components/ProductRegisterModal.jsx src/admin/pages/ItemManagement.jsx
npm run build
```

Expected: `clean` from the grep. `ItemManagement.jsx` at exactly its 2 pre-existing warnings; the other four at 0. Build exits 0.

Note `category_name` legitimately remains in `lookupConfigs.js` as the **categories lookup's own name field** — that is a real column, not the derived one. The grep above deliberately excludes that file.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/config/lookupConfigs.js frontend/src/admin/components/LookupPanel.jsx frontend/src/admin/pages/LookupManagement.jsx frontend/src/admin/components/ProductRegisterModal.jsx frontend/src/admin/pages/ItemManagement.jsx
git commit -m "refactor(lookups): drop category scoping from the panel and item forms"
```

---

## Task 6: Backend — retire the scoping trait

**Files:**
- Delete: `back-end/app/Http/Controllers/Concerns/ScopesLookupsToCategory.php`
- Modify: `back-end/app/Http/Controllers/Api/CapacityLookupController.php`
- Modify: `back-end/app/Http/Controllers/Api/VariantLookupController.php`
- Modify: `back-end/database/seeders/LookupBackfill.php`
- Delete: `back-end/tests/Feature/Lookups/CapacityScopingTest.php`
- Modify: 5 test files

- [ ] **Step 1: Rescue the one test worth keeping**

`CapacityScopingTest` is being deleted, but `test_two_uncategorised_rows_may_not_share_a_value` pins a guarantee that survives — uniqueness simply becomes global rather than per-category. Add this to `back-end/tests/Feature/Lookups/LookupCrudTraitTest.php`:

```php
    public function test_two_capacities_may_not_share_a_value(): void
    {
        $this->actingAsAdmin();
        $value = $this->uniqueValue();

        // Uniqueness used to be scoped per category. With scoping removed it
        // is global — this is the surviving half of the deleted
        // CapacityScopingTest, kept so the guarantee is not silently dropped.
        $this->postJson('/api/capacities', ['capacity_value' => $value])->assertStatus(201);

        $this->postJson('/api/capacities', ['capacity_value' => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['capacity_value' => 'has already been taken']);
    }
```

The other rescued candidate — the regression proving uniqueness resolves the category from the persisted row on update — is **not** carried over. With no `category_id` there is no category to resolve, so the bug it guarded cannot exist.

- [ ] **Step 2: Delete the scoping test file and the trait**

```bash
cd back-end
git rm tests/Feature/Lookups/CapacityScopingTest.php
git rm app/Http/Controllers/Concerns/ScopesLookupsToCategory.php
```

- [ ] **Step 3: Strip both controllers**

In `CapacityLookupController.php` and `VariantLookupController.php`:
- Remove the `use App\Http\Controllers\Concerns\ScopesLookupsToCategory;` import.
- Remove the whole `use ScopesLookupsToCategory { … insteadof HandlesLookupCrud; }` block, leaving a plain `use HandlesLookupCrud;`. With the conflict gone there is nothing to disambiguate, and `lookupUniqueRule` falls back to the trait's default, which derives table, primary key and name column from the model — correct now uniqueness is global.
- Remove `lookupIndexFilters()` if either declares its own.
- From `lookupRules()`, remove the `category_id` and `sort_order` rules. Keep the name-column rule and its `max:` width.
- Remove `lookupOrderColumns()` if it returns `['sort_order', …]` — the trait's default of name-only is now correct.

- [ ] **Step 4: Stop the backfill assigning categories**

In `back-end/database/seeders/LookupBackfill.php`, `insertMissing()` writes `'category_id' => …` and `'sort_order' => 0`. Remove both from the insert payload, and simplify the grouping so it no longer tracks category ids per value. The dedup, case-sensitivity, whitespace-skipping and over-length rules all stay — they are about value handling, not scoping.

- [ ] **Step 5: Amend the five test files**

- `VariantLookupTest` — delete the category-scoping test. Keep the cascade and width tests. Add:
  ```php
      public function test_a_variant_carries_no_category(): void
      {
          $this->actingAsAdmin();

          $row = $this->postJson('/api/variants', ['variant_name' => $this->uniqueValue()])
              ->assertStatus(201)
              ->json('data');

          $this->assertArrayNotHasKey('category_id', $row);
      }
  ```
- `LookupCrudTraitTest` — remove `sort_order` from any payloads, and delete the `sort_order: null` → 422 test; there is no such field now.
- `BatchIncludesTest` — delete the `?category_id=` isolation test. It proves batch ignores a stray category filter; with no scoping there is nothing to ignore.
- `BackfillTest` — delete the "unambiguous category" and "spans two categories → NULL" tests. Keep case-variant preservation, junk-skipping, over-length and idempotency.
- `RetrofitCompatTest` — remove `description` from the category and brand aggregate assertions.

- [ ] **Step 6: Verify and run**

```bash
grep -rn "ScopesLookupsToCategory\|categoryScoped" app/ tests/ || echo clean
php artisan test --filter=Lookups
php artisan test --filter="IssuanceBatchFifoTest|IssuanceRemoveItemBatchTest|CancelIssuanceTest|StockHistoryMovementSummaryTest"
```

Expected: `clean`, the Lookups suite green, and 14 passed.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/CapacityLookupController.php back-end/app/Http/Controllers/Api/VariantLookupController.php back-end/database/seeders/LookupBackfill.php back-end/tests/Feature/Lookups/LookupCrudTraitTest.php back-end/tests/Feature/Lookups/VariantLookupTest.php back-end/tests/Feature/Lookups/BatchIncludesTest.php back-end/tests/Feature/Lookups/BackfillTest.php back-end/tests/Feature/Lookups/RetrofitCompatTest.php
git commit -m "refactor(lookups): retire category scoping from the API"
```

The two `git rm` deletions are already staged.

---

## Task 7: Schema — drop the columns

**Files:**
- Create: `back-end/database/migrations/2026_08_01_000004_slim_lookup_tables.php`
- Modify: `airkingv5.sql`

- [ ] **Step 1: Confirm the backup from Task 3 Step 1 exists**

It covered all four tables. If it is missing, take it now before going further — the description text on 22 categories is unrecoverable once dropped.

- [ ] **Step 2: Write the migration**

Create `back-end/database/migrations/2026_08_01_000004_slim_lookup_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Strips the lookup tables to the columns that specify a product.
     *
     * sort_order was added on the reasoning that alphabetical ordering puts
     * 10HP before 2HP. The problem is real; hand-numbering every row is not
     * the answer, and the data agreed — zero non-default values on either
     * table. category_id scoped capacity and variant per category, which in
     * practice hid values rather than organising them.
     */
    public function up(): void
    {
        Schema::table('capacity_lookup', function (Blueprint $table) {
            if (Schema::hasColumn('capacity_lookup', 'category_id')) {
                $table->dropForeign(['category_id']);
                $table->dropColumn('category_id');
            }
            if (Schema::hasColumn('capacity_lookup', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
            if (Schema::hasColumn('capacity_lookup', 'description')) {
                $table->dropColumn('description');
            }
        });

        Schema::table('variant_lookup', function (Blueprint $table) {
            if (Schema::hasColumn('variant_lookup', 'category_id')) {
                $table->dropForeign(['category_id']);
                $table->dropColumn('category_id');
            }
            if (Schema::hasColumn('variant_lookup', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
            if (Schema::hasColumn('variant_lookup', 'description')) {
                $table->dropColumn('description');
            }
        });

        Schema::table('category_lookup', function (Blueprint $table) {
            if (Schema::hasColumn('category_lookup', 'description')) {
                $table->dropColumn('description');
            }
        });

        Schema::table('brand_lookup', function (Blueprint $table) {
            if (Schema::hasColumn('brand_lookup', 'description')) {
                $table->dropColumn('description');
            }
        });
    }

    /**
     * Restores the columns but NOT their contents — the descriptions and
     * category assignments are gone the moment up() runs. This exists so a
     * rollback does not leave the migrations table claiming a state the
     * database is not in. To recover data, re-import the mysqldump taken
     * before up() was run.
     */
    public function down(): void
    {
        Schema::table('capacity_lookup', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->after('capacity_value');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->foreign('category_id')->references('category_id')->on('category_lookup')->nullOnDelete();
        });

        Schema::table('variant_lookup', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->after('variant_name');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->foreign('category_id')->references('category_id')->on('category_lookup')->nullOnDelete();
        });

        Schema::table('category_lookup', function (Blueprint $table) {
            $table->text('description')->nullable();
        });

        Schema::table('brand_lookup', function (Blueprint $table) {
            $table->text('description')->nullable();
        });
    }
};
```

- [ ] **Step 3: Apply to both databases**

```bash
cd back-end
php artisan migrate --path=database/migrations/2026_08_01_000004_slim_lookup_tables.php
DB_DATABASE=airkingV5_test php artisan migrate --path=database/migrations/2026_08_01_000004_slim_lookup_tables.php
```

- [ ] **Step 4: Verify both databases**

```bash
php artisan tinker --execute="
foreach (['capacity_lookup','variant_lookup','category_lookup','brand_lookup'] as \$t) {
  echo \$t.': '.implode(', ', Schema::getColumnListing(\$t)).PHP_EOL;
}"
```

Expected: no `description`, `sort_order` or `category_id` on any of them. `unit_abbreviation` is untouched — it is read by the scan, issuance and inventory payloads.

- [ ] **Step 5: Edit the dump**

`capacity_lookup` and `variant_lookup` have **zero** references in `airkingv5.sql` — they were created by migrations, so the dump predates them. Nothing to do for those two.

For `category_lookup` and `brand_lookup`:
- Remove the `` `description` text DEFAULT NULL, `` line from each `CREATE TABLE`.
- Remove `` `description` `` from each `INSERT INTO … (columns)` list.
- Remove the corresponding value from **every** row of each INSERT.

**This is the step that breaks fresh installs when done carelessly.** Every row carries a description value; miss one and the column count no longer matches and the import fails — silently, on someone else's clone. If the surgical edit gets messy across 22 category rows and 17 brand rows, regenerate just those two blocks instead:

```bash
/c/XAMPP1/mysql/bin/mysqldump.exe -u root --no-create-info --skip-add-locks airkingV5 category_lookup brand_lookup
```

and splice the output over the existing INSERT statements.

- [ ] **Step 6: Prove the dump imports**

```bash
cd /c/XAMPP1/htdocs/Airking_Inventory
M=/c/XAMPP1/mysql/bin
"$M/mysql.exe" -u root -e "DROP DATABASE IF EXISTS airking_dumptest; CREATE DATABASE airking_dumptest;"
"$M/mysql.exe" -u root airking_dumptest < airkingv5.sql
"$M/mysql.exe" -u root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='airking_dumptest';"
"$M/mysql.exe" -u root -N -e "SHOW COLUMNS FROM airking_dumptest.category_lookup;"
"$M/mysql.exe" -u root -e "DROP DATABASE airking_dumptest;"
```

Expected: import succeeds, **72 tables**, and `category_lookup` has no `description` column.

- [ ] **Step 7: Full suite**

```bash
cd back-end
php artisan test
php artisan test --filter="IssuanceBatchFifoTest|IssuanceRemoveItemBatchTest|CancelIssuanceTest|StockHistoryMovementSummaryTest"
```

Expected: failures exactly **9** (`WarrantyClaimTest`), possibly 10 with the flake. Passing around **683**. Then 14 passed.

- [ ] **Step 8: Commit**

```bash
cd /c/XAMPP1/htdocs/Airking_Inventory
git add back-end/database/migrations/2026_08_01_000004_slim_lookup_tables.php airkingv5.sql
git commit -m "refactor(lookups): drop category scoping, sort_order and description columns"
```

---

## Notes for whoever executes this

**Three things that look like mistakes but are not:**

1. **The appliance filter keeps its `!c.category_type ||` fallback.** A null type still means "show for appliances", which is why null-typed categories were never part of the bug. Only the `'consumable'` comparison changes.
2. **`unit_lookup.unit_abbreviation` stays.** It is not decoration — the barcode scan payload, issuance and inventory read it to render "pc" / "m" / "kg" beside quantities.
3. **`category_name` remains in `lookupConfigs.js`** as the categories lookup's own name field. That is a real column. Only the *derived* `category_name` column on capacity and variant, and the branch in `LookupPanel.renderCell` that resolved it, are removed.

**The riskiest step is Task 7 Step 5**, the dump edit. It is the one thing no test covers, and a mistake surfaces only on a fresh clone. Step 6 exists precisely to catch it — do not skip it.

**Change A is independently shippable.** If Change B stalls for any reason, Tasks 1-3 stand alone and fix a live defect.
