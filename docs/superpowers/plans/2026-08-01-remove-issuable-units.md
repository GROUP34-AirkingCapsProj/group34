# Remove Issuable Units Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Delete the issuable-units feature — two tables, their models, controller, routes, the Item Master editor, and the Item Lookups sub-tab — without changing issue-out, inventory, or receiving behaviour.

**Architecture:** A staged removal, consumers before providers: frontend → API surface → model layer → schema. At no point is anything calling something that no longer exists, so each stage is independently verifiable and a red suite identifies its own stage.

**Tech Stack:** Laravel 11 (PHP 8.2), MySQL/MariaDB via XAMPP, PHPUnit with `DatabaseTransactions`, React 18 (CRA).

**Spec:** `docs/superpowers/specs/2026-08-01-remove-issuable-units-design.md`

---

## Environment notes — read before Task 1

- **The schema is a SQL dump, not a migration history.** Bare `php artisan migrate` fails. Apply migrations explicitly with `--path=`.
- **Two databases.** Dev is `airkingV5`; the test DB is targeted as `DB_DATABASE=airkingV5_test php artisan …` from the **Bash** tool only (PowerShell has no inline env-var prefix). There is no separate connection — `phpunit.xml` overrides `DB_DATABASE` on the default one.
- **Run backend commands from `back-end/`, frontend from `frontend/`.**
- **Known-failing baseline, do NOT chase:** 9 tests in `Tests\Feature\WarrantyClaimTest` fail (`WarrantyClaimException`), plus a flaky `Tests\Feature\Inventory\SerialRowMovementColumnsTest::consumable rows carry per batch in and out` that passes about one run in three. Current: **706 passed**, 9-10 failed, 1 skipped.
- **The working tree carries 7 unrelated uncommitted files** — `back-end/app/Http/Controllers/Api/InventoryScanTransactionController.php`, `frontend/src/admin/pages/BarcodeAdjustmentPage.jsx`, `BarcodeScan.jsx`, `IssuanceScanPage.jsx`, `TransfersPage.jsx`, and two barcode stylesheets. They are **not** part of this work. **Never run `git add -A`, `git add .`, or `git add <directory>`** — every commit below lists explicit paths.
- **Do not kill `php.exe` processes** — the user may have servers running.

## The real acceptance criteria

The claim being tested is that issue-out and inventory are unaffected. That is **not** proven by the tests this plan deletes. It is proven by these staying green at **every** stage:

- `Tests\Feature\Issuance\IssuanceBatchFifoTest` — FIFO deduction
- `Tests\Feature\Issuance\IssuanceRemoveItemBatchTest` and `CancelIssuanceTest` — restore on cancel
- `Tests\Feature\Inventory\StockHistoryMovementSummaryTest` — the ledger

None reference issuable units. If any changes behaviour, stop and report — the removal broke something.

## Files touched

| Stage | File | Action |
|---|---|---|
| 1 | `frontend/src/admin/pages/ItemManagement.jsx` | remove issuable UI + handlers + state |
| 1 | `frontend/src/admin/config/lookupConfigs.js` | remove `presets` entry + tab membership |
| 1 | `frontend/src/admin/services/api.js` | remove `issuableUnitPresetsAPI` + 4 `productsAPI` methods |
| 1 | `frontend/src/admin/styles/item_management.css` | remove the Issuable Units CSS block |
| 2 | `back-end/routes/api.php` | remove 12 routes + 2 imports |
| 2 | `back-end/app/Http/Controllers/Api/IssuableUnitPresetController.php` | delete |
| 2 | `back-end/app/Http/Controllers/Api/ProductController.php` | remove 4 methods |
| 2 | `back-end/app/Http/Controllers/Api/BatchController.php` | remove the include |
| 2 | `back-end/app/Http/Controllers/Api/BarcodeScanController.php` | remove 2 payload fields |
| 2 | `back-end/app/Http/Controllers/Api/IssuanceScanController.php` | remove 2 payload fields |
| 2 | `back-end/tests/Feature/Lookups/IssuableUnitPresetTest.php` | delete |
| 2 | `back-end/tests/Feature/Lookups/LookupRouteAccessTest.php` | amend |
| 2 | `back-end/tests/Feature/Lookups/BatchIncludesTest.php` | amend |
| 2 | `back-end/tests/Feature/Lookups/RetrofitCompatTest.php` | re-anchor one case |
| 3 | `back-end/app/Models/Product.php` | remove relation + method |
| 3 | `back-end/app/Models/ProductIssuableUnit.php` | delete |
| 3 | `back-end/app/Models/IssuableUnitPreset.php` | delete |
| 3 | `back-end/database/seeders/LookupBackfill.php` | remove `backfillPresets` |
| 3 | `back-end/tests/Feature/Lookups/BackfillTest.php` | amend |
| 4 | `back-end/database/migrations/2026_08_01_000001_drop_issuable_unit_tables.php` | create |
| 4 | `airkingv5.sql` | remove 5 blocks |

---

## Task 1: Frontend removal

The backend is untouched, so its endpoints simply stop being called.

**Files:**
- Modify: `frontend/src/admin/pages/ItemManagement.jsx`
- Modify: `frontend/src/admin/config/lookupConfigs.js`
- Modify: `frontend/src/admin/services/api.js`
- Modify: `frontend/src/admin/styles/item_management.css`

- [ ] **Step 1: Record the baseline eslint state**

`ItemManagement.jsx` has exactly **two pre-existing warnings** you must not add to:

```bash
cd frontend
npx eslint src/admin/pages/ItemManagement.jsx
```

Expected: 2 warnings — `react-hooks/exhaustive-deps` on `fetchItems`, and unused `passesCatalogCompleteness`. Note the count; it must be 2 again at the end.

- [ ] **Step 2: Remove the Issuable Units JSX section**

In `ItemManagement.jsx`, delete the block from `<div className="issuable-units-section">` (~line 1576) through its closing `</div>` (~line 1740), immediately before the following `<div className="im-form-row">`. This is the whole section: header, preset picker, add button, empty state and table.

- [ ] **Step 3: Remove the handlers and state**

Delete these from `ItemManagement.jsx`:
- `issuableUnitPresets` and `issuableUnits` state (~lines 73-74)
- the preset fetch block inside the lookups loader (~lines 145-151)
- the load-on-edit effect (~lines 159-166)
- `handleAddIssuableUnit` (~439), `handleAddIssuableUnitFromPreset` (~459), `updateIssuableUnit` (~473), `removeIssuableUnit` (~479), `saveIssuableUnits` (~483)
- the `await saveIssuableUnits(savedProductId);` call in `handleSubmit` (~line 560)
- the `issuableUnitPresetsAPI` import (~line 16)

- [ ] **Step 4: Fix the shared add-unit handler — do not skip this**

`addUnitTargetIdx` is shared between the consumable **Unit of Measure** field and issuable-unit rows. Around line 400 the new-unit handler branches on it:

```jsx
if (addUnitTargetIdx === null) {
  setFormData((prev) => ({ ...prev, unit_id: created.unit_id ?? prev.unit_id }));
} else if (created.unit_id != null) {
  updateIssuableUnit(addUnitTargetIdx, 'unit_id', created.unit_id);   // ← now dead
}
```

Replace the whole branch with just the `setFormData` assignment, since only the Unit of Measure field remains:

```jsx
if (created.unit_id != null) {
  setFormData((prev) => ({ ...prev, unit_id: created.unit_id }));
}
```

Then remove the now-unused `addUnitTargetIdx` / `setAddUnitTargetIdx` state (~line 79) **only if** nothing else references it — grep first. If the Unit of Measure field's `+` button still sets it, keep the state and drop only the `else if` branch.

- [ ] **Step 5: Remove the presets lookup config**

In `frontend/src/admin/config/lookupConfigs.js`, delete the entire `presets` entry from `LOOKUP_CONFIGS`, remove `'presets'` from the Installation Materials tab's `lookups` array in `LOOKUP_TABS`, and remove the `issuableUnitPresetsAPI` import.

`LookupManagement.jsx` needs **no** change — it renders from config, so the sub-tab disappears on its own.

- [ ] **Step 6: Remove the API modules**

In `frontend/src/admin/services/api.js`, delete the whole `issuableUnitPresetsAPI` block, and the four issuable-unit methods on `productsAPI`: `getIssuableUnits`, `storeIssuableUnit`, `updateIssuableUnit`, `destroyIssuableUnit`.

- [ ] **Step 7: Remove the CSS**

In `frontend/src/admin/styles/item_management.css`, delete the block from the `/* ===== Issuable Units Section (Item Master) ===== */` comment header (~line 888) through the closing brace of the trailing `@media (max-width: 640px)` rule that styles `.issuable-units-table` (~line 1097). That is 27 `issuable-*` rules in one contiguous region.

- [ ] **Step 8: Verify nothing still references it**

```bash
cd frontend
grep -rn "issuable\|Issuable" src/ || echo clean
```

Expected: `clean`. Any hit is something missed.

- [ ] **Step 9: Lint and build**

```bash
npx eslint src/admin/pages/ItemManagement.jsx src/admin/config/lookupConfigs.js src/admin/services/api.js
npm run build
```

Expected: `ItemManagement.jsx` back to exactly **2** warnings, the other two files clean, build exits 0.

- [ ] **Step 10: Commit**

```bash
git add frontend/src/admin/pages/ItemManagement.jsx frontend/src/admin/config/lookupConfigs.js frontend/src/admin/services/api.js frontend/src/admin/styles/item_management.css
git commit -m "refactor(issuable-units): remove the Item Master editor and Lookups sub-tab"
```

---

## Task 2: API surface removal

**Files:**
- Modify: `back-end/routes/api.php`
- Delete: `back-end/app/Http/Controllers/Api/IssuableUnitPresetController.php`
- Modify: `back-end/app/Http/Controllers/Api/ProductController.php`
- Modify: `back-end/app/Http/Controllers/Api/BatchController.php`
- Modify: `back-end/app/Http/Controllers/Api/BarcodeScanController.php`
- Modify: `back-end/app/Http/Controllers/Api/IssuanceScanController.php`
- Delete: `back-end/tests/Feature/Lookups/IssuableUnitPresetTest.php`
- Modify: `back-end/tests/Feature/Lookups/LookupRouteAccessTest.php`
- Modify: `back-end/tests/Feature/Lookups/BatchIncludesTest.php`
- Modify: `back-end/tests/Feature/Lookups/RetrofitCompatTest.php`

- [ ] **Step 1: Remove the routes**

In `back-end/routes/api.php`, delete:
- the 8 preset routes (`/issuable-unit-presets`, `…/archived`, `…/{id}`, POST, PUT, DELETE, `…/{id}/restore`, `…/{id}/force`) — around lines 381-393, including their comment block
- the 4 product routes: `GET /products/{id}/issuable-units`, `PUT /products/issuable-units/{issuable_unit_id}`, `DELETE /products/issuable-units/{issuable_unit_id}`, and `POST /products/{id}/issuable-units` — around lines 432-437
- the `use App\Http\Controllers\Api\IssuableUnitPresetController;` import

Also update the comment near line 284 that lists the lookups whose GETs are ungated — it currently names issuable-unit-presets. It should now list five, not six.

- [ ] **Step 2: Delete the preset controller and its test**

```bash
cd back-end
git rm app/Http/Controllers/Api/IssuableUnitPresetController.php
git rm tests/Feature/Lookups/IssuableUnitPresetTest.php
```

- [ ] **Step 3: Remove the ProductController methods**

In `back-end/app/Http/Controllers/Api/ProductController.php`, delete the contiguous block from the `// GET /api/products/{id}/issuable-units` comment (~line 337) through the closing brace of `destroyIssuableUnit` (~line 409), immediately before the `// EXPORT` section header. That removes all four methods. Also remove the `ProductIssuableUnit` import if present.

- [ ] **Step 4: Remove the batch include**

In `back-end/app/Http/Controllers/Api/BatchController.php`, delete the `if (in_array('issuable_unit_presets', $parts))` block (~lines 316-323) and the `IssuableUnitPresetController` import. Update the class docblock (~lines 28 and 35) which lists `issuable_unit_presets` among the available includes.

- [ ] **Step 5: Remove the payload fields**

In `BarcodeScanController.php` (~lines 119-121) and `IssuanceScanController.php` (~lines 557-559), delete the `'quick_actions' => …` and `'has_issuable_units' => …` entries from their response arrays. In `IssuanceScanController`, also remove the `issuableUnits` eager-loads around lines 411 and 416-418.

- [ ] **Step 6: Amend `LookupRouteAccessTest`**

The two "can read all six lookups" tests hit `/api/issuable-unit-presets`. Remove that endpoint from both, and rename them to say **five**. Update the class docblock accordingly.

**Do not touch any write-denial test** — especially `test_warehouse_personnel_cannot_write_a_capacity`, which is the only one proving the permission gate rather than the blanket `auditor.readonly` middleware.

- [ ] **Step 7: Amend `BatchIncludesTest`**

Remove `issuable_unit_presets` from the include list in the success-shape test and from the created-rows test. Delete the nested-`unit` test entirely — it exists only for presets.

- [ ] **Step 8: Re-anchor the force-delete guard in `RetrofitCompatTest`**

Replace `test_force_deleting_a_unit_referenced_by_a_preset_returns_422_not_500` with a version anchored to a foreign key that still exists. Dropping both issuable tables removes the last `RESTRICT` referents from `unit_lookup`, so this now pins the **app-level** guard:

```php
    public function test_force_deleting_a_unit_in_use_is_refused(): void
    {
        $this->actingAsAdmin();

        $unit = UnitLookup::create([
            'unit_name'         => 'Unit ' . uniqid(),
            'unit_abbreviation' => 'u' . substr(uniqid(), -4),
        ]);

        // Do NOT use LookupTestCase::applianceWith() here — it returns an
        // ApplianceDetail (not a Product) and hardcodes product_type as
        // 'appliance', merging your attributes into the detail row. We need a
        // real installation_material product, so create it directly.
        $productId = DB::table('products')->insertGetId([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Material ' . uniqid(),
            'product_type' => 'installation_material',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('installation_material_details')->insert([
            'product_id'         => $productId,
            'unit_id'            => $unit->unit_id,
            'pieces_per_package' => 10,
            'package_unit'       => 'Box',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $this->deleteJson("/api/units/{$unit->unit_id}")->assertStatus(200);

        // usageCountFor counts installation_material_details, so the app guard
        // refuses before the DB is asked. This matters more than it used to:
        // the RESTRICT foreign keys that used to back it up lived on the
        // issuable-unit tables, and those are gone.
        $this->deleteJson("/api/units/{$unit->unit_id}/force")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('unit_lookup', ['unit_id' => $unit->unit_id]);
    }
```

Remove the `IssuableUnitPreset` import from that file. Ensure `DB` and `UnitLookup` are imported.

- [ ] **Step 9: Run the lookup suite**

```bash
cd back-end
php artisan test --filter=Lookups
```

Expected: all pass. If `test_force_deleting_a_unit_in_use_is_refused` fails with 200, `usageCountFor` is not counting `installation_material_details` — report rather than weakening the test.

- [ ] **Step 10: Confirm the routes are gone**

```bash
php artisan route:list --path=issuable
```

Expected: no routes returned.

- [ ] **Step 11: Run the acceptance-criteria tests**

```bash
php artisan test --filter="IssuanceBatchFifoTest|IssuanceRemoveItemBatchTest|CancelIssuanceTest|StockHistoryMovementSummaryTest"
```

Expected: all pass. These are the proof that issue-out is unaffected.

- [ ] **Step 12: Commit**

```bash
git add back-end/routes/api.php back-end/app/Http/Controllers/Api/ProductController.php back-end/app/Http/Controllers/Api/BatchController.php back-end/app/Http/Controllers/Api/BarcodeScanController.php back-end/app/Http/Controllers/Api/IssuanceScanController.php back-end/tests/Feature/Lookups/LookupRouteAccessTest.php back-end/tests/Feature/Lookups/BatchIncludesTest.php back-end/tests/Feature/Lookups/RetrofitCompatTest.php
git commit -m "refactor(issuable-units): remove routes, controller and scan payload fields"
```

The two `git rm` deletions from Step 2 are already staged.

---

## Task 3: Model layer removal

**Files:**
- Modify: `back-end/app/Models/Product.php`
- Delete: `back-end/app/Models/ProductIssuableUnit.php`
- Delete: `back-end/app/Models/IssuableUnitPreset.php`
- Modify: `back-end/database/seeders/LookupBackfill.php`
- Modify: `back-end/tests/Feature/Lookups/BackfillTest.php`

- [ ] **Step 1: Remove the relation and quick-actions builder**

In `back-end/app/Models/Product.php`, delete the `issuableUnits(): HasMany` relation (~line 176) and `buildConsumableQuickActions(): array` (~line 198) in full, including the `pieces_per_package` fallback branch inside it. Remove the `ProductIssuableUnit` import and any now-unused imports (`Str` may only be used by the fallback — check before removing).

- [ ] **Step 2: Delete the two models**

```bash
cd back-end
git rm app/Models/ProductIssuableUnit.php
git rm app/Models/IssuableUnitPreset.php
```

- [ ] **Step 3: Remove the preset backfill**

In `back-end/database/seeders/LookupBackfill.php`, delete the `backfillPresets()` method (~line 129) and its call from `run()` (~line 39). Remove the now-stale mention of presets from the class docblock.

- [ ] **Step 4: Amend `BackfillTest`**

Delete `test_presets_are_backfilled_from_product_issuable_units`. Remove the `UnitLookup` import if nothing else in the file uses it, and remove any `issuable_unit_preset` assertions from the idempotency test.

- [ ] **Step 5: Verify nothing still references the removed symbols**

```bash
grep -rn "ProductIssuableUnit\|IssuableUnitPreset\|buildConsumableQuickActions\|issuableUnits" app/ database/ tests/ || echo clean
```

Expected: `clean`. Note `ItemSerial::issuableAt()` is a **different** concept and will not match this grep.

- [ ] **Step 6: Run the full suite**

```bash
php artisan test
```

Expected: failures still exactly **9** (`WarrantyClaimTest`), possibly 10 with the flaky `SerialRowMovementColumnsTest`. Passing should be around **692** (down ~14 from 706). Any other failure is this task breaking something.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Models/Product.php back-end/database/seeders/LookupBackfill.php back-end/tests/Feature/Lookups/BackfillTest.php
git commit -m "refactor(issuable-units): remove models, relation and quick-actions builder"
```

---

## Task 4: Schema removal

This task is irreversible. Do the backup step first.

**Files:**
- Create: `back-end/database/migrations/2026_08_01_000001_drop_issuable_unit_tables.php`
- Modify: `airkingv5.sql`

- [ ] **Step 1: Back up the data — do not skip**

```bash
cd /c/XAMPP1/htdocs/Airking_Inventory
mysqldump -u root airkingV5 product_issuable_units issuable_unit_preset > "$TMPDIR/issuable-units-backup.sql"
```

If `$TMPDIR` is unset, write it anywhere **outside the repo**. Do not commit it. Confirm the file is non-empty before continuing — it is the only thing standing between a reversal and re-entering 167 rows by hand.

- [ ] **Step 2: Write the drop migration**

Create `back-end/database/migrations/2026_08_01_000001_drop_issuable_unit_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drops the issuable-units feature.
     *
     * Order is irrelevant: neither table references the other. Both reference
     * products and unit_lookup, and DROP TABLE removes their foreign keys
     * automatically — including the last two RESTRICT referents on
     * unit_lookup. Deleting a unit that is still in use is from here on
     * guarded only by UnitLookupController::forceDestroy's usage check.
     */
    public function up(): void
    {
        Schema::dropIfExists('issuable_unit_preset');
        Schema::dropIfExists('product_issuable_units');
    }

    /**
     * Recreates the schema but NOT the data — the rows are gone the moment
     * up() runs. This exists so a rollback does not leave the migrations
     * table claiming a state the database is not in; it is not a restore.
     * To recover data, re-import the mysqldump taken before up() was run.
     */
    public function down(): void
    {
        if (! Schema::hasTable('product_issuable_units')) {
            Schema::create('product_issuable_units', function (Blueprint $t) {
                $t->bigIncrements('issuable_unit_id');
                $t->unsignedBigInteger('product_id');
                $t->unsignedBigInteger('unit_id');
                $t->string('label', 100);
                $t->decimal('conversion_factor', 10, 6)->default(1.000000);
                $t->boolean('is_whole_package')->default(false);
                $t->unsignedTinyInteger('sort_order')->default(0);
                $t->boolean('show_as_quick_action')->default(true);
                $t->timestamps();

                $t->unique(['product_id', 'label'], 'unique_product_label');
                $t->foreign('product_id')->references('product_id')->on('products')->onDelete('cascade');
                $t->foreign('unit_id')->references('unit_id')->on('unit_lookup')->onDelete('restrict');
            });
        }

        if (! Schema::hasTable('issuable_unit_preset')) {
            Schema::create('issuable_unit_preset', function (Blueprint $t) {
                $t->bigIncrements('preset_id');
                $t->string('label', 100);
                $t->unsignedBigInteger('unit_id');
                $t->decimal('default_conversion_factor', 10, 6)->default(1.000000);
                $t->boolean('is_whole_package')->default(false);
                $t->text('description')->nullable();
                $t->unsignedTinyInteger('sort_order')->default(0);
                $t->timestamps();
                $t->softDeletes();

                $t->index('label', 'issuable_unit_preset_label_idx');
                $t->foreign('unit_id')->references('unit_id')->on('unit_lookup')->onDelete('restrict');
            });
        }
    }
};
```

- [ ] **Step 3: Apply to the dev database**

```bash
cd back-end
php artisan migrate --path=database/migrations/2026_08_01_000001_drop_issuable_unit_tables.php
```

Expected: `DONE`.

- [ ] **Step 4: Apply to the test database**

From the **Bash** tool (the env prefix does not work in PowerShell):

```bash
DB_DATABASE=airkingV5_test php artisan migrate --path=database/migrations/2026_08_01_000001_drop_issuable_unit_tables.php
```

Expected: `DONE`.

- [ ] **Step 5: Verify both databases**

```bash
php artisan tinker --execute="echo 'dev: piu='.(int) Schema::hasTable('product_issuable_units').' preset='.(int) Schema::hasTable('issuable_unit_preset');"
DB_DATABASE=airkingV5_test php artisan tinker --execute="echo 'test: piu='.(int) Schema::hasTable('product_issuable_units').' preset='.(int) Schema::hasTable('issuable_unit_preset');"
```

Expected: `piu=0 preset=0` for both.

- [ ] **Step 6: Edit the SQL dump**

`airkingv5.sql` is the real source of truth — a fresh install re-imports it, so a `CREATE TABLE` left behind resurrects the table on every new machine.

Remove all five `product_issuable_units` blocks:

| Approx. line | Block |
|---|---|
| 2625 | `-- Table structure for table` + `CREATE TABLE` |
| 2642 | `-- Dumping data for table` + `INSERT INTO` (167 rows) |
| 4675 | `-- Indexes for table` + `ALTER TABLE … ADD PRIMARY KEY/ADD KEY` |
| 5227 | `-- AUTO_INCREMENT for table` + `ALTER TABLE … MODIFY` |
| 5666 | `-- Constraints for table` + two `ADD CONSTRAINT` |

Remove each block including its `--` comment header and trailing blank line. **`issuable_unit_preset` appears nowhere in the dump** — it was created by a migration during this session, so the dump predates it. Nothing to remove for that table.

- [ ] **Step 7: Verify the dump is clean**

```bash
cd /c/XAMPP1/htdocs/Airking_Inventory
grep -c "product_issuable_units\|issuable_unit_preset" airkingv5.sql
```

Expected: `0`.

- [ ] **Step 8: Full suite**

```bash
cd back-end
php artisan test
```

Expected: failures exactly **9** (`WarrantyClaimTest`), possibly 10 with the flake. Passing around **692**.

- [ ] **Step 9: Re-run the acceptance-criteria tests explicitly**

```bash
php artisan test --filter="IssuanceBatchFifoTest|IssuanceRemoveItemBatchTest|CancelIssuanceTest|StockHistoryMovementSummaryTest"
```

Expected: all pass. This is the final proof that issue-out and inventory are unaffected.

- [ ] **Step 10: Commit**

```bash
cd /c/XAMPP1/htdocs/Airking_Inventory
git add back-end/database/migrations/2026_08_01_000001_drop_issuable_unit_tables.php airkingv5.sql
git commit -m "refactor(issuable-units): drop both tables and remove them from the schema dump"
```

---

## Notes for whoever executes this

**Two things that look like mistakes but are not:**

1. **`ItemSerial::issuableAt()` stays.** It is a query scope meaning "this serial is available to issue at this location" — an unrelated sense of the word. `SerialIssuableScopeTest` and `SerialIntegrityFlowTest` depend on it and must keep passing.
2. **`SetSchemaTest`'s assertion that `product_set_lines` has no `issuable_unit_id` stays.** It asserts *absence*, so it remains true — and it is evidence a previous cleanup already retreated from this feature once.

**What must not change:** `installation_material_details.pieces_per_package` is load-bearing for receiving (`1 scan = 1 package = N pieces`), for per-piece costing (`unit_price / pieces_per_package`, used by the category and brand value aggregates), and for `packages_on_hand`. Nothing in this plan touches it.

**The highest-risk step** is Task 1 Step 4 — the shared `addUnitTargetIdx` handler. Removing the issuable branch without checking what still uses that state is how you silently break the "+ add unit" button next to the consumable Unit of Measure field. Grep before deleting.
