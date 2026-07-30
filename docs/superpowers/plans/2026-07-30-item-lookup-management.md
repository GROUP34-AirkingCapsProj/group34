# Item Lookup Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the two-lookup Categories & Brands page with one two-tab page that manages all six item-master lookups, and constrain the item forms to the managed values.

**Architecture:** Three new `*_lookup`-convention tables (capacity, variant, issuable unit presets) join the three that exist. The eight standard CRUD actions are factored into one `HandlesLookupCrud` trait instead of being copied six times; renames of capacity/variant cascade into the product tables through a `LookupRenameService`. The frontend collapses into a page shell + one generic `LookupPanel` driven by a config file.

**Tech Stack:** Laravel 11 (PHP 8.2), MySQL/MariaDB via XAMPP, PHPUnit with `DatabaseTransactions`, React 18 (CRA), SweetAlert2 confirms, `ak-*` design system.

**Spec:** `docs/superpowers/specs/2026-07-30-item-lookup-management-design.md`

---

## Environment notes (read before Task 1)

This repo has conventions that will waste your time if you discover them the hard way:

- **The schema is a SQL dump, not a migration history.** Running bare `php artisan migrate` fails. Apply each new migration explicitly:
  `php artisan migrate --path=database/migrations/<file>.php`
- **Two databases.** Dev is `airkingV5`, tests run against `airkingV5_test`. Every migration in this plan must be applied to **both**. There is no separate `mysql_test` connection — `phpunit.xml` overrides `DB_DATABASE` on the default `mysql` connection, so target the test DB by prefixing the command: `DB_DATABASE=airkingV5_test php artisan …`. This works from the **Bash** tool only (PowerShell has no inline env-var prefix; there you would need `$env:DB_DATABASE = 'airkingV5_test'` first, and to unset it afterwards).
- **Run tests from `back-end/`:** `php artisan test --filter=<TestName>`
- **Known-failing baseline — read before judging any test run.** Two distinct pre-existing problems, neither caused by this work. Verified by confirming this branch's entire diff against its starting point (`eec69a6`) is purely additive: 737 insertions, **zero deletions, zero modified pre-existing files**.
  1. **9 deterministic failures** in `Tests\Feature\WarrantyClaimTest`, all throwing `WarrantyClaimException` from warranty-service logic.
  2. **1 flaky test**: `Tests\Feature\Inventory\SerialRowMovementColumnsTest::consumable rows carry per batch in and out`. Run three times in isolation it passed once and failed twice — the assertion at line 103 depends on two serial records arriving in a particular order with no deterministic tie-break. Expect it to fail roughly two runs in three, in isolation or in a full run.

  So a full `php artisan test` lands at **9 or 10 failures** before this feature adds anything. Task 21 must not treat either as a regression. A failure outside those two classes IS a regression. Fixing the flaky test is out of scope here, but it is worth a separate ticket — it will keep muddying every future test run.
- **After changing `PermissionSeeder`,** run `php artisan config:clear` — a cached config will serve stale permissions and the failure looks like a broken middleware.
- **The working tree already contains unrelated uncommitted changes** (`back-end/app/Http/Controllers/Api/InventoryScanTransactionController.php`, `frontend/src/admin/pages/BarcodeAdjustmentPage.jsx`, `BarcodeScan.jsx`, `IssuanceScanPage.jsx`, `TransfersPage.jsx`, and two barcode stylesheets). They are **not** part of this work. Never run a bare `git add -A`, `git add .`, or `git add <directory>` — every commit in this plan lists explicit file paths for that reason. Leave those files untouched.
- **Test fixtures inserting into `products` must supply `product_code`** — it is NOT NULL with no default and carries a unique index. Every helper in this plan uses `'PC-' . uniqid()`. `appliance_details` needs only `product_id`.
- **`product_sets` in the live databases is narrower than its migration file suggests.** The migration `2026_07_24_000001_create_product_sets_tables.php` declares `set_code`, `set_name`, `description` and `brand_id`, but it is guarded by `Schema::hasTable` and never ran against these databases — the dump's version won. The real columns are: `set_id`, `capacity_rating`, `variant`, `is_active`, `created_by`, `updated_by`, timestamps, `deleted_at`. Trust `Schema::getColumnListing('product_sets')`, not the migration file. The rename cascade only touches `capacity_rating` and `variant`, both of which do exist.
- **MySQL collation is `utf8mb4_unicode_ci`,** so string comparisons and `unique` validation are already case-insensitive. Do not add manual `LOWER()` wrapping. Where you need case-*sensitivity* (the backfill), you must ask for it explicitly with `BINARY`.

---

## File structure

**Create — backend**

| File | Responsibility |
|---|---|
| `back-end/database/migrations/2026_07_30_000001_create_capacity_lookup_table.php` | `capacity_lookup` schema |
| `back-end/database/migrations/2026_07_30_000002_create_variant_lookup_table.php` | `variant_lookup` schema |
| `back-end/database/migrations/2026_07_30_000003_create_issuable_unit_preset_table.php` | `issuable_unit_preset` schema |
| `back-end/database/migrations/2026_07_30_000004_backfill_lookup_tables.php` | Seeds the three tables from existing free-text data |
| `back-end/app/Models/CapacityLookup.php` | Model |
| `back-end/app/Models/VariantLookup.php` | Model |
| `back-end/app/Models/IssuableUnitPreset.php` | Model |
| `back-end/app/Http/Controllers/Concerns/HandlesLookupCrud.php` | The eight shared CRUD actions + merge |
| `back-end/app/Services/LookupRenameService.php` | Cascades a renamed value into product tables |
| `back-end/app/Http/Controllers/Api/CapacityLookupController.php` | Capacity config + category scoping + cascade |
| `back-end/app/Http/Controllers/Api/VariantLookupController.php` | Variant config + category scoping + cascade |
| `back-end/app/Http/Controllers/Api/IssuableUnitPresetController.php` | Preset config (no scoping, no cascade) |

**Modify — backend**

| File | Change |
|---|---|
| `back-end/app/Http/Controllers/Api/UnitLookupController.php` | Full retrofit onto the trait |
| `back-end/app/Http/Controllers/Api/CategoryLookupController.php` | Drop hand-rolled `archived`/`restore`/`forceDestroy` |
| `back-end/app/Http/Controllers/Api/BrandLookupController.php` | Same |
| `back-end/app/Http/Controllers/Api/BatchController.php` | Add three includes |
| `back-end/routes/api.php` | New route groups; `/units` moved to `permission:` |
| `back-end/database/seeders/PermissionSeeder.php` | Add `lookup_management` |
| `back-end/database/seeders/NavBundleSeeder.php` | Rename nav entry |

**Create — frontend**

| File | Responsibility |
|---|---|
| `frontend/src/admin/config/lookupConfigs.js` | Six config objects + tab grouping |
| `frontend/src/admin/components/LookupPanel.jsx` | Generic list + modal + archive toggle |
| `frontend/src/admin/pages/LookupManagement.jsx` | Page shell, tabs, URL sync |

**Modify — frontend:** `services/api.js`, `App.jsx`, `components/Sidebar.jsx`, `utils/navBundles.js`, `utils/navConfigureMap.js`, `utils/roles.js`, `components/ProductRegisterModal.jsx`, `pages/ItemManagement.jsx`. **Delete:** `pages/CategoryBrandManagement.jsx`.

**Create — tests:** all under `back-end/tests/Feature/Lookups/`.

---

## Task 1: Create the three lookup tables

**Files:**
- Create: `back-end/database/migrations/2026_07_30_000001_create_capacity_lookup_table.php`
- Create: `back-end/database/migrations/2026_07_30_000002_create_variant_lookup_table.php`
- Create: `back-end/database/migrations/2026_07_30_000003_create_issuable_unit_preset_table.php`

- [ ] **Step 1: Write the capacity migration**

`capacity_value` is varchar(20) on purpose — it is copied into `appliance_details.capacity_rating` (varchar 20) by the rename cascade in Task 6. A wider column here would let an admin save a value MySQL silently truncates on the way in.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('capacity_lookup')) {
            return;
        }

        Schema::create('capacity_lookup', function (Blueprint $table) {
            $table->bigIncrements('capacity_id');

            // Width matches appliance_details.capacity_rating exactly. The rename
            // cascade copies this value into that column, so a wider definition
            // here would truncate silently and desync the item from its lookup.
            $table->string('capacity_value', 20);

            $table->unsignedBigInteger('category_id')->nullable()
                ->comment('NULL = shows for every category');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'capacity_value'], 'capacity_lookup_scope_idx');

            $table->foreign('category_id')
                ->references('category_id')->on('category_lookup')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capacity_lookup');
    }
};
```

- [ ] **Step 2: Write the variant migration**

`variant_name` is varchar(100), matching `appliance_details.variant` and `product_sets.variant`.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('variant_lookup')) {
            return;
        }

        Schema::create('variant_lookup', function (Blueprint $table) {
            $table->bigIncrements('variant_id');
            $table->string('variant_name', 100);
            $table->unsignedBigInteger('category_id')->nullable()
                ->comment('NULL = shows for every category');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'variant_name'], 'variant_lookup_scope_idx');

            $table->foreign('category_id')
                ->references('category_id')->on('category_lookup')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_lookup');
    }
};
```

- [ ] **Step 3: Write the preset migration**

Column types mirror `product_issuable_units` so a preset prefills a product row with no conversion.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('issuable_unit_preset')) {
            return;
        }

        Schema::create('issuable_unit_preset', function (Blueprint $table) {
            $table->bigIncrements('preset_id');

            // Types deliberately mirror product_issuable_units so applying a
            // preset to a product needs no conversion.
            $table->string('label', 100);
            $table->unsignedBigInteger('unit_id');
            $table->decimal('default_conversion_factor', 10, 6)->default(1.000000)
                ->comment('Inventory pieces per 1 of this issuable unit');
            $table->boolean('is_whole_package')->default(false);

            $table->text('description')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('label', 'issuable_unit_preset_label_idx');

            $table->foreign('unit_id')
                ->references('unit_id')->on('unit_lookup')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuable_unit_preset');
    }
};
```

- [ ] **Step 4: Apply all three to the dev database**

```bash
cd back-end
php artisan migrate --path=database/migrations/2026_07_30_000001_create_capacity_lookup_table.php
php artisan migrate --path=database/migrations/2026_07_30_000002_create_variant_lookup_table.php
php artisan migrate --path=database/migrations/2026_07_30_000003_create_issuable_unit_preset_table.php
```

Expected: three `INFO  Running migrations.` blocks, each ending `DONE`.

- [ ] **Step 5: Apply all three to the test database**

There is **no** `mysql_test` connection. `phpunit.xml` points the default `mysql` connection at `airkingV5_test` via an env override, so target the test database the same way — from the Bash tool, not PowerShell (Laravel's Dotenv does not override variables already present in the environment, which is what makes this work):

```bash
DB_DATABASE=airkingV5_test php artisan migrate --path=database/migrations/2026_07_30_000001_create_capacity_lookup_table.php
DB_DATABASE=airkingV5_test php artisan migrate --path=database/migrations/2026_07_30_000002_create_variant_lookup_table.php
DB_DATABASE=airkingV5_test php artisan migrate --path=database/migrations/2026_07_30_000003_create_issuable_unit_preset_table.php
```

Verify both databases got the table:

```bash
php artisan tinker --execute="echo DB::connection()->getDatabaseName().': '.implode(',', Schema::getColumnListing('capacity_lookup'));"
DB_DATABASE=airkingV5_test php artisan tinker --execute="echo DB::connection()->getDatabaseName().': '.implode(',', Schema::getColumnListing('capacity_lookup'));"
```

Expected: each prints its database name followed by a list containing `capacity_id,capacity_value,category_id`.

- [ ] **Step 6: Commit**

```bash
git add back-end/database/migrations/2026_07_30_00000*.php
git commit -m "feat(lookups): add capacity, variant and issuable unit preset tables"
```

---

## Task 2: Add the three models

**Files:**
- Create: `back-end/app/Models/CapacityLookup.php`
- Create: `back-end/app/Models/VariantLookup.php`
- Create: `back-end/app/Models/IssuableUnitPreset.php`

- [ ] **Step 1: Write `CapacityLookup`**

Follows `CategoryLookup` exactly — soft deletes, explicit table and primary key.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CapacityLookup extends Model
{
    use SoftDeletes;

    protected $table = 'capacity_lookup';
    protected $primaryKey = 'capacity_id';

    protected $fillable = [
        'capacity_value',
        'category_id',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'sort_order'  => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(CategoryLookup::class, 'category_id', 'category_id');
    }
}
```

- [ ] **Step 2: Write `VariantLookup`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VariantLookup extends Model
{
    use SoftDeletes;

    protected $table = 'variant_lookup';
    protected $primaryKey = 'variant_id';

    protected $fillable = [
        'variant_name',
        'category_id',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'sort_order'  => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(CategoryLookup::class, 'category_id', 'category_id');
    }
}
```

- [ ] **Step 3: Write `IssuableUnitPreset`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class IssuableUnitPreset extends Model
{
    use SoftDeletes;

    protected $table = 'issuable_unit_preset';
    protected $primaryKey = 'preset_id';

    protected $fillable = [
        'label',
        'unit_id',
        'default_conversion_factor',
        'is_whole_package',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'unit_id'                   => 'integer',
        'default_conversion_factor' => 'float',
        'is_whole_package'          => 'boolean',
        'sort_order'                => 'integer',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitLookup::class, 'unit_id', 'unit_id');
    }
}
```

- [ ] **Step 4: Verify the models bind to real tables**

```bash
cd back-end
php artisan tinker --execute="echo App\Models\CapacityLookup::count().'|'.App\Models\VariantLookup::count().'|'.App\Models\IssuableUnitPreset::count();"
```

Expected: `0|0|0` (tables exist and are empty). An exception here means the migration in Task 1 did not apply.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Models/CapacityLookup.php back-end/app/Models/VariantLookup.php back-end/app/Models/IssuableUnitPreset.php
git commit -m "feat(lookups): add capacity, variant and preset models"
```

---

## Task 3: Build the `LookupRenameService`

This is the riskiest logic in the feature, so it gets its own unit and its own tests before any controller uses it.

**Files:**
- Create: `back-end/app/Services/LookupRenameService.php`
- Test: `back-end/tests/Feature/Lookups/LookupRenameServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Lookups;

use App\Services\LookupRenameService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LookupRenameServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function makeApplianceRow(string $capacity): int
    {
        $productId = DB::table('products')->insertGetId([
            'product_name' => 'P '.uniqid(),
            'product_type' => 'appliance',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('appliance_details')->insert([
            'product_id'      => $productId,
            'capacity_rating' => $capacity,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return $productId;
    }

    public function test_cascade_updates_every_matching_row_and_returns_the_count(): void
    {
        $this->makeApplianceRow('9XHP');
        $this->makeApplianceRow('9XHP');
        $this->makeApplianceRow('8XHP');

        $service = app(LookupRenameService::class);

        $updated = $service->cascade(
            ['appliance_details' => 'capacity_rating'],
            '9XHP',
            '9X HP'
        );

        $this->assertSame(2, $updated);
        $this->assertSame(0, DB::table('appliance_details')->where('capacity_rating', '9XHP')->count());
        $this->assertSame(2, DB::table('appliance_details')->where('capacity_rating', '9X HP')->count());
        $this->assertSame(1, DB::table('appliance_details')->where('capacity_rating', '8XHP')->count());
    }

    public function test_cascade_spans_multiple_tables(): void
    {
        $this->makeApplianceRow('7XHP');

        DB::table('product_sets')->insert([
            // Do NOT add set_code/set_name — the live product_sets table has
            // only: set_id, capacity_rating, variant, is_active, created_by,
            // updated_by, timestamps, deleted_at.
            'capacity_rating' => '7XHP',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $updated = app(LookupRenameService::class)->cascade(
            [
                'appliance_details' => 'capacity_rating',
                'product_sets'      => 'capacity_rating',
            ],
            '7XHP',
            '7X HP'
        );

        $this->assertSame(2, $updated, 'One appliance row + one set row');
        $this->assertSame(1, DB::table('product_sets')->where('capacity_rating', '7X HP')->count());
    }

    public function test_cascade_of_an_unused_value_reports_zero(): void
    {
        $updated = app(LookupRenameService::class)->cascade(
            ['appliance_details' => 'capacity_rating'],
            'NOT-IN-USE-'.uniqid(),
            'STILL-NOT-USED'
        );

        $this->assertSame(0, $updated);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd back-end
php artisan test --filter=LookupRenameServiceTest
```

Expected: FAIL — `Target class [App\Services\LookupRenameService] does not exist.`

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Capacity and variant are stored on products as plain strings, not foreign
 * keys (see the design spec for why). That makes renaming a lookup value a
 * data-migration rather than a metadata change: without this cascade, a rename
 * silently orphans every product still holding the old string.
 */
class LookupRenameService
{
    /**
     * Repoint every row holding $old to $new.
     *
     * @param  array<string,string>  $targets  [table => column]
     * @return int  Total rows updated across all targets.
     */
    public function cascade(array $targets, string $old, string $new): int
    {
        if ($old === $new) {
            return 0;
        }

        $total = 0;

        foreach ($targets as $table => $column) {
            // Deliberately a raw query builder update: it ignores soft-delete
            // scopes, so archived sets and products stay consistent too.
            //
            // BINARY, not a plain where: the collation is case-insensitive, but
            // the lookup tables can legitimately hold values differing only in
            // case (the backfill preserves them, since products reference the
            // exact string). A ci match would rewrite the other variant's
            // products too and orphan its lookup row.
            $total += DB::table($table)
                ->whereRaw("BINARY {$column} = ?", [$old])
                ->update([$column => $new]);
        }

        return $total;
    }
}
```

- [ ] **Step 4: Run the tests again**

```bash
php artisan test --filter=LookupRenameServiceTest
```

Expected: PASS — 3 passed.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/LookupRenameService.php back-end/tests/Feature/Lookups/LookupRenameServiceTest.php
git commit -m "feat(lookups): add LookupRenameService for cascading value renames"
```

---

## Task 4: Build the `HandlesLookupCrud` trait

The trait is written to serve every lookup, but is only proven once — Task 5 wires it to the capacity controller, which is the one lookup that exercises every hook. Tests live there.

**Files:**
- Create: `back-end/app/Http/Controllers/Concerns/HandlesLookupCrud.php`

- [ ] **Step 1: Write the trait**

```php
<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The eight CRUD actions every lookup shares, plus merge.
 *
 * Before this existed, CategoryLookupController, BrandLookupController and
 * UnitLookupController were three near-identical copies of the same code. A
 * controller using this trait supplies configuration, not behaviour.
 *
 * Consuming controllers must also use App\Traits\ApiResponse.
 */
trait HandlesLookupCrud
{
    /** Fully-qualified Eloquent model class, e.g. CapacityLookup::class. */
    abstract protected function lookupModel(): string;

    /** The user-visible name column, e.g. 'capacity_value'. */
    abstract protected function lookupNameColumn(): string;

    /** Singular display noun used in response messages, e.g. 'Capacity'. */
    abstract protected function lookupLabel(): string;

    /** Validation rules. $id is null on create, the primary key on update. */
    abstract protected function lookupRules(Request $request, $id = null): array;

    /** Plural display noun. Override when appending 's' is wrong. */
    protected function lookupLabelPlural(): string
    {
        return $this->lookupLabel() . 's';
    }

    /** How many product records reference this value. */
    protected function usageCountFor($row): int
    {
        return 0;
    }

    /**
     * Called inside the update transaction when the name column changed.
     * Return the number of product rows repointed.
     */
    protected function afterRename($row, string $oldName, string $newName): int
    {
        return 0;
    }

    /** Base listing query. Override to add filtering (e.g. by category). */
    protected function lookupIndexQuery(Request $request)
    {
        $model = $this->lookupModel();

        return $model::orderBy('sort_order')->orderBy($this->lookupNameColumn());
    }

    /** Shape of a single row in responses. */
    protected function transformRow($row): array
    {
        return array_merge($row->toArray(), [
            'usage_count' => $this->usageCountFor($row),
        ]);
    }

    public function index(Request $request)
    {
        $rows = $this->lookupIndexQuery($request)->get();

        return $this->success(
            $rows->map(fn ($row) => $this->transformRow($row))->values()->all(),
            $this->lookupLabelPlural() . ' retrieved'
        );
    }

    public function show($id)
    {
        $model = $this->lookupModel();
        $row = $model::find($id);

        if (! $row) {
            return $this->notFound($this->lookupLabel() . ' not found');
        }

        return $this->success($this->transformRow($row));
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->lookupRules($request));
        $model = $this->lookupModel();
        $row = $model::create($validated);

        return $this->success(
            $this->transformRow($row->fresh()),
            $this->lookupLabel() . ' created',
            201
        );
    }

    public function update(Request $request, $id)
    {
        $model = $this->lookupModel();
        $row = $model::find($id);

        if (! $row) {
            return $this->notFound($this->lookupLabel() . ' not found');
        }

        $validated = $request->validate($this->lookupRules($request, $id));

        $column = $this->lookupNameColumn();
        $oldName = (string) $row->{$column};
        $newName = array_key_exists($column, $validated)
            ? (string) $validated[$column]
            : $oldName;

        $cascaded = 0;

        // One transaction: a failed cascade must roll the rename back too,
        // otherwise the lookup and the products it names diverge permanently.
        DB::transaction(function () use ($row, $validated, $oldName, $newName, &$cascaded) {
            $row->update($validated);

            if ($newName !== $oldName) {
                $cascaded = $this->afterRename($row, $oldName, $newName);
            }
        });

        return $this->success(
            $this->transformRow($row->fresh()),
            $cascaded > 0
                ? $this->lookupLabel() . " updated - {$cascaded} item(s) updated"
                : $this->lookupLabel() . ' updated'
        );
    }

    public function destroy($id)
    {
        $model = $this->lookupModel();
        $row = $model::find($id);

        if (! $row) {
            return $this->notFound($this->lookupLabel() . ' not found');
        }

        $usage = $this->usageCountFor($row);
        $row->delete();

        return $this->success(
            ['usage_count' => $usage],
            $usage > 0
                ? $this->lookupLabel() . " archived - still used by {$usage} item(s)"
                : $this->lookupLabel() . ' archived'
        );
    }

    public function archived(Request $request)
    {
        $model = $this->lookupModel();

        $rows = $model::onlyTrashed()->orderBy('deleted_at', 'desc')->get();

        return $this->success(
            $rows->map(fn ($row) => $this->transformRow($row))->values()->all(),
            'Archived ' . strtolower($this->lookupLabelPlural()) . ' retrieved'
        );
    }

    public function restore($id)
    {
        $model = $this->lookupModel();
        $row = $model::onlyTrashed()->find($id);

        if (! $row) {
            return $this->notFound('Archived ' . strtolower($this->lookupLabel()) . ' not found');
        }

        $row->restore();

        return $this->success($this->transformRow($row->fresh()), $this->lookupLabel() . ' restored');
    }

    public function forceDestroy($id)
    {
        $model = $this->lookupModel();
        $row = $model::onlyTrashed()->find($id);

        if (! $row) {
            return $this->notFound('Archived ' . strtolower($this->lookupLabel()) . ' not found');
        }

        $usage = $this->usageCountFor($row);

        if ($usage > 0) {
            return $this->error("Cannot delete - used by {$usage} item(s)", 422);
        }

        try {
            $row->forceDelete();
        } catch (QueryException $e) {
            // A restrict-on-delete foreign key we did not count, e.g. a unit
            // referenced by an issuable unit preset.
            return $this->error('Cannot delete - this record is still referenced.', 422);
        }

        return $this->success(null, $this->lookupLabel() . ' permanently deleted');
    }

    /**
     * Fold one value into another: repoint the source's product rows onto the
     * target's name, then archive the source. Only meaningful for lookups that
     * implement afterRename(); routed for capacity and variant only.
     */
    public function merge(Request $request, $id)
    {
        $model = $this->lookupModel();
        $source = $model::find($id);

        if (! $source) {
            return $this->notFound($this->lookupLabel() . ' not found');
        }

        $validated = $request->validate(['target_id' => 'required|integer']);
        $target = $model::find($validated['target_id']);

        if (! $target) {
            return $this->notFound('Target ' . strtolower($this->lookupLabel()) . ' not found');
        }

        if ((int) $target->getKey() === (int) $source->getKey()) {
            return $this->error('Cannot merge a value into itself', 422);
        }

        $column = $this->lookupNameColumn();
        $moved = 0;

        DB::transaction(function () use ($source, $target, $column, &$moved) {
            $moved = $this->afterRename($source, (string) $source->{$column}, (string) $target->{$column});
            $source->delete();
        });

        return $this->success(
            [
                'moved'  => $moved,
                'target' => $this->transformRow($target->fresh()),
            ],
            "Merged - {$moved} item(s) moved"
        );
    }
}
```

- [ ] **Step 2: Confirm the file parses**

```bash
cd back-end
php -l app/Http/Controllers/Concerns/HandlesLookupCrud.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add back-end/app/Http/Controllers/Concerns/HandlesLookupCrud.php
git commit -m "feat(lookups): add HandlesLookupCrud trait"
```

---

## Task 5: Add the `lookup_management` permission

Do this before the controllers so the routes in Task 6 have a permission to reference.

**Files:**
- Modify: `back-end/database/seeders/PermissionSeeder.php`
- Test: `back-end/tests/Feature/Lookups/LookupPermissionSeedTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Lookups;

use Database\Seeders\PermissionSeeder;
use Tests\TestCase;

class LookupPermissionSeedTest extends TestCase
{
    public function test_lookup_management_is_in_the_matrix_with_all_four_verbs(): void
    {
        $matrix = PermissionSeeder::matrix();

        $this->assertArrayHasKey('lookup_management', $matrix);

        foreach (['read', 'create', 'update', 'archive'] as $verb) {
            $this->assertArrayHasKey($verb, $matrix['lookup_management'], "Missing verb: {$verb}");
        }
    }

    public function test_lookup_management_mirrors_category_management_exactly(): void
    {
        $matrix = PermissionSeeder::matrix();

        // Deliberate: the new page replaces the categories/brands page, so
        // nobody's effective access should change when it ships.
        $this->assertSame(
            $matrix['category_management'],
            $matrix['lookup_management'],
            'lookup_management must grant the same roles as category_management'
        );
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd back-end
php artisan test --filter=LookupPermissionSeedTest
```

Expected: FAIL — `Failed asserting that an array has the key 'lookup_management'`.

- [ ] **Step 3: Add the matrix entry**

In `back-end/database/seeders/PermissionSeeder.php`, insert directly after the closing `],` of the `brand_management` block (around line 61):

```php
            // Unified Item Lookups page (/admin/categories). Covers all six
            // lookups on the page: categories, brands, variants, capacities,
            // units of measure and issuable unit presets. The older
            // category_management / brand_management keys are retained and
            // still guard their own endpoints, so effective access is unchanged.
            'lookup_management' => [
                'read'    => ['admin', 'inventory_analyst', 'auditor'],
                'create'  => ['admin', 'inventory_analyst'],
                'update'  => ['admin', 'inventory_analyst'],
                'archive' => ['admin'],
            ],
```

- [ ] **Step 4: Run the tests again**

```bash
php artisan test --filter=LookupPermissionSeedTest
```

Expected: PASS — 2 passed.

- [ ] **Step 5: Seed both databases and clear config**

```bash
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=RolePermissionsSeeder
php artisan config:clear
```

Expected: `Database seeding completed successfully.` twice. Skipping `config:clear` here produces stale permission failures in Task 6 that look like broken middleware.

- [ ] **Step 6: Confirm the existing seeder suite still passes**

```bash
php artisan test --filter=SeederTest
```

Expected: PASS — the admin-gets-everything assertion now counts four more permissions.

- [ ] **Step 7: Commit**

```bash
git add back-end/database/seeders/PermissionSeeder.php back-end/tests/Feature/Lookups/LookupPermissionSeedTest.php
git commit -m "feat(lookups): add lookup_management permission"
```

---

## Task 6: Capacity lookup endpoint

The first controller on the trait. It is also the only one that uses every hook, so the trait's full behaviour is proven here and the remaining controllers get thin tests.

**Files:**
- Create: `back-end/app/Http/Controllers/Api/CapacityLookupController.php`
- Modify: `back-end/routes/api.php` (insert after the units block, which ends around line 289)
- Test: `back-end/tests/Feature/Lookups/LookupCrudTraitTest.php`
- Test: `back-end/tests/Feature/Lookups/CapacityScopingTest.php`

- [ ] **Step 1: Make sure the test database has the new permission rows**

Task 5 seeded the dev database. The permission middleware reads from whichever database the test suite uses, so seed that one too:

```bash
cd back-end
DB_DATABASE=airkingV5_test php artisan db:seed --class=PermissionSeeder
DB_DATABASE=airkingV5_test php artisan db:seed --class=RolePermissionsSeeder
```

Verify:

```bash
DB_DATABASE=airkingV5_test php artisan tinker --execute="echo App\Models\Permission::where('feature','lookup_management')->count();"
```

Expected: `4`.

- [ ] **Step 2: Write the failing CRUD test**

```php
<?php

namespace Tests\Feature\Lookups;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Exercises HandlesLookupCrud in full through the capacity endpoint, which is
 * the one lookup that uses every hook. The other five controllers get thin
 * tests covering only what is specific to them.
 */
class LookupCrudTraitTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): User
    {
        $roleId = Role::where('role_name', 'admin')->value('role_id');
        $this->assertNotNull($roleId, 'admin role missing - seed the test database');

        $user = User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'Admin',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]);

        $this->actingAs($user);

        return $user;
    }

    private function uniqueValue(): string
    {
        // Must fit capacity_value's varchar(20).
        return 'T' . substr(uniqid(), -8) . 'HP';
    }

    public function test_create_returns_201_and_the_new_row(): void
    {
        $this->actingAsAdmin();
        $value = $this->uniqueValue();

        $response = $this->postJson('/api/capacities', ['capacity_value' => $value])
            ->assertStatus(201);

        $this->assertSame($value, $response->json('data.capacity_value'));
        $this->assertSame(0, $response->json('data.usage_count'));
        $this->assertDatabaseHas('capacity_lookup', ['capacity_value' => $value]);
    }

    public function test_create_rejects_a_duplicate(): void
    {
        $this->actingAsAdmin();
        $value = $this->uniqueValue();

        $this->postJson('/api/capacities', ['capacity_value' => $value])->assertStatus(201);
        $this->postJson('/api/capacities', ['capacity_value' => $value])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_create_rejects_a_duplicate_differing_only_in_case(): void
    {
        $this->actingAsAdmin();
        $value = $this->uniqueValue();

        $this->postJson('/api/capacities', ['capacity_value' => $value])->assertStatus(201);

        // The utf8mb4_unicode_ci collation makes unique validation
        // case-insensitive for free. This test guards that behaviour so a
        // future collation change does not silently allow near-duplicates.
        $this->postJson('/api/capacities', ['capacity_value' => strtolower($value)])
            ->assertStatus(422);
    }

    public function test_update_changes_the_value(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/capacities', ['capacity_value' => $this->uniqueValue()])
            ->json('data.capacity_id');

        $newValue = $this->uniqueValue();

        $this->putJson("/api/capacities/{$id}", ['capacity_value' => $newValue])
            ->assertStatus(200)
            ->assertJsonPath('data.capacity_value', $newValue);
    }

    public function test_archive_then_list_archived_then_restore(): void
    {
        $this->actingAsAdmin();
        $value = $this->uniqueValue();
        $id = $this->postJson('/api/capacities', ['capacity_value' => $value])->json('data.capacity_id');

        $this->deleteJson("/api/capacities/{$id}")->assertStatus(200);

        $archivedIds = collect($this->getJson('/api/capacities/archived')->json('data'))
            ->pluck('capacity_id')->all();
        $this->assertContains($id, $archivedIds);

        $activeIds = collect($this->getJson('/api/capacities')->json('data'))
            ->pluck('capacity_id')->all();
        $this->assertNotContains($id, $activeIds, 'Archived rows must leave the active list');

        $this->postJson("/api/capacities/{$id}/restore")->assertStatus(200);

        $activeIds = collect($this->getJson('/api/capacities')->json('data'))
            ->pluck('capacity_id')->all();
        $this->assertContains($id, $activeIds);
    }

    public function test_force_delete_removes_an_unused_archived_row(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/capacities', ['capacity_value' => $this->uniqueValue()])
            ->json('data.capacity_id');

        $this->deleteJson("/api/capacities/{$id}")->assertStatus(200);
        $this->deleteJson("/api/capacities/{$id}/force")->assertStatus(200);

        $this->assertDatabaseMissing('capacity_lookup', ['capacity_id' => $id]);
    }

    public function test_show_returns_404_for_a_missing_row(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/capacities/99999999')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }
}
```

- [ ] **Step 3: Write the failing scoping test**

```php
<?php

namespace Tests\Feature\Lookups;

use App\Models\CategoryLookup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CapacityScopingTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): void
    {
        $roleId = Role::where('role_name', 'admin')->value('role_id');

        $this->actingAs(User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'Admin',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]));
    }

    private function category(): CategoryLookup
    {
        return CategoryLookup::create([
            'category_name' => 'Cat ' . uniqid(),
            'category_type' => 'product',
        ]);
    }

    private function value(): string
    {
        return 'T' . substr(uniqid(), -8) . 'HP';
    }

    public function test_filtering_by_category_returns_that_category_plus_globals(): void
    {
        $this->actingAsAdmin();

        $aircon = $this->category();
        $fridge = $this->category();

        $scoped = $this->postJson('/api/capacities', [
            'capacity_value' => $this->value(),
            'category_id'    => $aircon->category_id,
        ])->json('data.capacity_id');

        $other = $this->postJson('/api/capacities', [
            'capacity_value' => $this->value(),
            'category_id'    => $fridge->category_id,
        ])->json('data.capacity_id');

        $global = $this->postJson('/api/capacities', [
            'capacity_value' => $this->value(),
        ])->json('data.capacity_id');

        $ids = collect($this->getJson("/api/capacities?category_id={$aircon->category_id}")->json('data'))
            ->pluck('capacity_id')->all();

        $this->assertContains($scoped, $ids, "The category's own values must appear");
        $this->assertContains($global, $ids, 'Uncategorised values must appear for every category');
        $this->assertNotContains($other, $ids, "Another category's values must not appear");
    }

    public function test_the_same_value_may_exist_under_two_categories(): void
    {
        $this->actingAsAdmin();

        $a = $this->category();
        $b = $this->category();
        $value = $this->value();

        $this->postJson('/api/capacities', ['capacity_value' => $value, 'category_id' => $a->category_id])
            ->assertStatus(201);
        $this->postJson('/api/capacities', ['capacity_value' => $value, 'category_id' => $b->category_id])
            ->assertStatus(201);
    }

    public function test_the_same_value_may_not_exist_twice_under_one_category(): void
    {
        $this->actingAsAdmin();

        $a = $this->category();
        $value = $this->value();

        $this->postJson('/api/capacities', ['capacity_value' => $value, 'category_id' => $a->category_id])
            ->assertStatus(201);
        $this->postJson('/api/capacities', ['capacity_value' => $value, 'category_id' => $a->category_id])
            ->assertStatus(422);
    }

    public function test_two_uncategorised_rows_may_not_share_a_value(): void
    {
        $this->actingAsAdmin();
        $value = $this->value();

        // MySQL treats NULLs as distinct in a unique index, so this case is
        // enforced in validation rather than by the schema. Without the
        // whereNull branch in lookupRules() this test fails.
        $this->postJson('/api/capacities', ['capacity_value' => $value])->assertStatus(201);
        $this->postJson('/api/capacities', ['capacity_value' => $value])->assertStatus(422);
    }
}
```

- [ ] **Step 4: Run both to confirm they fail**

```bash
php artisan test --filter="LookupCrudTraitTest|CapacityScopingTest"
```

Expected: FAIL — every test errors with a 404 because `/api/capacities` is not routed yet.

- [ ] **Step 5: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Models\CapacityLookup;
use App\Services\LookupRenameService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CapacityLookupController extends Controller
{
    use ApiResponse;
    use HandlesLookupCrud;

    public function __construct(private LookupRenameService $renamer)
    {
    }

    protected function lookupModel(): string
    {
        return CapacityLookup::class;
    }

    protected function lookupNameColumn(): string
    {
        return 'capacity_value';
    }

    protected function lookupLabel(): string
    {
        return 'Capacity';
    }

    protected function lookupLabelPlural(): string
    {
        return 'Capacities';
    }

    /**
     * A category's own values plus the uncategorised ones, which apply
     * everywhere. Without the category filter an aircon capacity shows up
     * when registering a refrigerator.
     */
    protected function lookupIndexQuery(Request $request)
    {
        $query = CapacityLookup::orderBy('sort_order')->orderBy('capacity_value');

        if ($request->filled('category_id')) {
            $categoryId = (int) $request->input('category_id');

            $query->where(function ($q) use ($categoryId) {
                $q->where('category_id', $categoryId)->orWhereNull('category_id');
            });
        }

        return $query;
    }

    protected function lookupRules(Request $request, $id = null): array
    {
        $categoryId = $request->input('category_id');

        $unique = Rule::unique('capacity_lookup', 'capacity_value')
            ->whereNull('deleted_at')
            ->where(function ($query) use ($categoryId) {
                // MySQL's unique index treats NULLs as distinct, so two global
                // rows could otherwise share a value. Branch explicitly.
                return ($categoryId === null || $categoryId === '')
                    ? $query->whereNull('category_id')
                    : $query->where('category_id', $categoryId);
            });

        if ($id !== null) {
            $unique->ignore($id, 'capacity_id');
        }

        return [
            'capacity_value' => [
                $id === null ? 'required' : 'sometimes',
                'string',
                'max:20',
                $unique,
            ],
            'category_id' => 'nullable|integer|exists:category_lookup,category_id',
            'description' => 'nullable|string',
            'sort_order'  => 'nullable|integer|min:0|max:255',
        ];
    }

    protected function usageCountFor($row): int
    {
        // BINARY, not a plain where: the collation is case-insensitive, and the
        // backfill can legitimately leave '1.5HP' and '1.5hp' as separate rows.
        // A ci match would report each one's products against both.
        return (int) DB::table('appliance_details')
            ->whereRaw('BINARY capacity_rating = ?', [$row->capacity_value])
            ->count();
    }

    protected function supportsMerge(): bool
    {
        return true;
    }

    protected function cascadeValueChange(Model $row, string $from, string $to): int
    {
        return $this->renamer->cascade(
            [
                'appliance_details' => 'capacity_rating',
                'product_sets'      => 'capacity_rating',
            ],
            $from,
            $to
        );
    }
}
```

Add `use Illuminate\Database\Eloquent\Model;` to the imports.

- [ ] **Step 6: Add the routes**

In `back-end/routes/api.php`, add the import alongside the other lookup controller imports at the top of the file:

```php
use App\Http\Controllers\Api\CapacityLookupController;
```

Then insert this block immediately after the units route block (which ends around line 289). `archived` must come before `{id}` or it binds as an id.

```php
    /* ---------------------------------------------------------------
     | Capacities (Item Lookups page - Appliance tab)
     | 'archived' precedes '{id}' so it is not swallowed by the wildcard.
     * ------------------------------------------------------------- */
    Route::middleware('permission:lookup_management,read')->group(function () {
        Route::get('/capacities/archived', [CapacityLookupController::class, 'archived']);
        Route::get('/capacities', [CapacityLookupController::class, 'index']);
        Route::get('/capacities/{id}', [CapacityLookupController::class, 'show']);
    });
    Route::middleware('permission:lookup_management,create')
        ->post('/capacities', [CapacityLookupController::class, 'store']);
    Route::middleware('permission:lookup_management,update')
        ->put('/capacities/{id}', [CapacityLookupController::class, 'update']);
    Route::middleware('permission:lookup_management,update')
        ->post('/capacities/{id}/merge', [CapacityLookupController::class, 'merge']);
    Route::middleware('permission:lookup_management,archive')
        ->delete('/capacities/{id}', [CapacityLookupController::class, 'destroy']);
    Route::middleware('permission:lookup_management,archive')
        ->post('/capacities/{id}/restore', [CapacityLookupController::class, 'restore']);
    Route::middleware('permission:lookup_management,archive')
        ->delete('/capacities/{id}/force', [CapacityLookupController::class, 'forceDestroy']);
```

- [ ] **Step 7: Run the tests again**

```bash
php artisan test --filter="LookupCrudTraitTest|CapacityScopingTest"
```

Expected: PASS — 11 passed.

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Http/Controllers/Api/CapacityLookupController.php back-end/routes/api.php back-end/tests/Feature/Lookups/
git commit -m "feat(lookups): add capacity lookup endpoint on shared CRUD trait"
```

---

## Task 7: Rename cascade and merge for capacity

The trait and service are both in place; this task connects them end to end through HTTP and proves the transaction boundary.

**Files:**
- Test: `back-end/tests/Feature/Lookups/RenameCascadeTest.php`

No production code should be needed — if a test here fails, the bug is in Task 3 or Task 6.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Lookups;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RenameCascadeTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): void
    {
        $roleId = Role::where('role_name', 'admin')->value('role_id');

        $this->actingAs(User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'Admin',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]));
    }

    private function value(): string
    {
        return 'T' . substr(uniqid(), -8) . 'HP';
    }

    private function applianceWithCapacity(string $capacity): void
    {
        $productId = DB::table('products')->insertGetId([
            // product_code is NOT NULL and unique; omitting it fails the insert.
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'P ' . uniqid(),
            'product_type' => 'appliance',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('appliance_details')->insert([
            'product_id'      => $productId,
            'capacity_rating' => $capacity,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public function test_renaming_updates_every_product_and_reports_the_count(): void
    {
        $this->actingAsAdmin();

        $old = $this->value();
        $new = $this->value();

        $id = $this->postJson('/api/capacities', ['capacity_value' => $old])->json('data.capacity_id');

        $this->applianceWithCapacity($old);
        $this->applianceWithCapacity($old);

        $response = $this->putJson("/api/capacities/{$id}", ['capacity_value' => $new])
            ->assertStatus(200);

        $this->assertStringContainsString('2 item(s) updated', $response->json('message'));
        $this->assertSame(0, DB::table('appliance_details')->where('capacity_rating', $old)->count());
        $this->assertSame(2, DB::table('appliance_details')->where('capacity_rating', $new)->count());
    }

    public function test_usage_count_is_reported_on_the_list(): void
    {
        $this->actingAsAdmin();

        $value = $this->value();
        $this->postJson('/api/capacities', ['capacity_value' => $value]);
        $this->applianceWithCapacity($value);

        $row = collect($this->getJson('/api/capacities')->json('data'))
            ->firstWhere('capacity_value', $value);

        $this->assertSame(1, $row['usage_count']);
    }

    public function test_renaming_an_unused_value_reports_no_item_count(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/capacities', ['capacity_value' => $this->value()])
            ->json('data.capacity_id');

        $message = $this->putJson("/api/capacities/{$id}", ['capacity_value' => $this->value()])
            ->assertStatus(200)
            ->json('message');

        $this->assertStringNotContainsString('item(s) updated', $message);
    }

    public function test_renaming_onto_an_existing_value_is_rejected(): void
    {
        $this->actingAsAdmin();

        $taken = $this->value();
        $this->postJson('/api/capacities', ['capacity_value' => $taken]);

        $id = $this->postJson('/api/capacities', ['capacity_value' => $this->value()])
            ->json('data.capacity_id');

        // Rejected rather than silently merged - the client offers merge
        // explicitly after seeing this 422.
        $this->putJson("/api/capacities/{$id}", ['capacity_value' => $taken])
            ->assertStatus(422);
    }

    public function test_merge_moves_products_and_archives_the_source(): void
    {
        $this->actingAsAdmin();

        $sourceValue = $this->value();
        $targetValue = $this->value();

        $sourceId = $this->postJson('/api/capacities', ['capacity_value' => $sourceValue])
            ->json('data.capacity_id');
        $targetId = $this->postJson('/api/capacities', ['capacity_value' => $targetValue])
            ->json('data.capacity_id');

        $this->applianceWithCapacity($sourceValue);
        $this->applianceWithCapacity($sourceValue);
        $this->applianceWithCapacity($targetValue);

        $response = $this->postJson("/api/capacities/{$sourceId}/merge", ['target_id' => $targetId])
            ->assertStatus(200);

        $this->assertSame(2, $response->json('data.moved'));
        $this->assertSame(0, DB::table('appliance_details')->where('capacity_rating', $sourceValue)->count());
        $this->assertSame(3, DB::table('appliance_details')->where('capacity_rating', $targetValue)->count());

        $activeIds = collect($this->getJson('/api/capacities')->json('data'))->pluck('capacity_id')->all();
        $this->assertNotContains($sourceId, $activeIds, 'The merged-away source must be archived');
        $this->assertContains($targetId, $activeIds);
    }

    public function test_merging_a_value_into_itself_is_rejected(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/capacities', ['capacity_value' => $this->value()])
            ->json('data.capacity_id');

        $this->postJson("/api/capacities/{$id}/merge", ['target_id' => $id])
            ->assertStatus(422);
    }

    public function test_archiving_a_used_value_succeeds_and_reports_the_count(): void
    {
        $this->actingAsAdmin();

        $value = $this->value();
        $id = $this->postJson('/api/capacities', ['capacity_value' => $value])->json('data.capacity_id');
        $this->applianceWithCapacity($value);

        // Warn-then-allow: archiving is never blocked, but the count comes back
        // so the UI can say what it costs.
        $response = $this->deleteJson("/api/capacities/{$id}")->assertStatus(200);

        $this->assertSame(1, $response->json('data.usage_count'));
        $this->assertSame(1, DB::table('appliance_details')->where('capacity_rating', $value)->count());
    }

    public function test_force_deleting_a_used_value_is_refused(): void
    {
        $this->actingAsAdmin();

        $value = $this->value();
        $id = $this->postJson('/api/capacities', ['capacity_value' => $value])->json('data.capacity_id');
        $this->applianceWithCapacity($value);

        $this->deleteJson("/api/capacities/{$id}")->assertStatus(200);
        $this->deleteJson("/api/capacities/{$id}/force")->assertStatus(422);

        $this->assertDatabaseHas('capacity_lookup', ['capacity_id' => $id]);
    }

    public function test_an_archived_value_still_on_an_item_survives_a_round_trip(): void
    {
        $this->actingAsAdmin();

        $value = $this->value();
        $id = $this->postJson('/api/capacities', ['capacity_value' => $value])->json('data.capacity_id');
        $this->applianceWithCapacity($value);

        $this->deleteJson("/api/capacities/{$id}")->assertStatus(200);

        // The item keeps its string even though the lookup row is archived.
        // This is the guarantee the "(archived)" option in the item form
        // depends on; if it fails, opening an item silently wipes its capacity.
        $this->assertSame(1, DB::table('appliance_details')->where('capacity_rating', $value)->count());
    }
}
```

- [ ] **Step 2: Run it**

```bash
php artisan test --filter=RenameCascadeTest
```

Expected: PASS — 9 passed. If `test_renaming_updates_every_product_and_reports_the_count` fails with a count of 0, `afterRename` is not wired in `CapacityLookupController`.

- [ ] **Step 3: Commit**

```bash
git add back-end/tests/Feature/Lookups/RenameCascadeTest.php
git commit -m "test(lookups): cover rename cascade, merge and archive semantics"
```

---

## Task 8: Variant lookup endpoint

Same shape as capacity, against `appliance_details.variant`. The trait is already proven, so this task only tests what is specific to variants.

**Files:**
- Create: `back-end/app/Http/Controllers/Api/VariantLookupController.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/Lookups/VariantLookupTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Lookups;

use App\Models\CategoryLookup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VariantLookupTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): void
    {
        $roleId = Role::where('role_name', 'admin')->value('role_id');

        $this->actingAs(User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'Admin',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]));
    }

    private function name(): string
    {
        return 'Variant ' . uniqid();
    }

    private function applianceWithVariant(string $variant): void
    {
        $productId = DB::table('products')->insertGetId([
            // product_code is NOT NULL and unique; omitting it fails the insert.
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'P ' . uniqid(),
            'product_type' => 'appliance',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('appliance_details')->insert([
            'product_id' => $productId,
            'variant'    => $variant,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_create_and_list(): void
    {
        $this->actingAsAdmin();
        $name = $this->name();

        $this->postJson('/api/variants', ['variant_name' => $name])
            ->assertStatus(201)
            ->assertJsonPath('data.variant_name', $name);

        $names = collect($this->getJson('/api/variants')->json('data'))->pluck('variant_name')->all();
        $this->assertContains($name, $names);
    }

    public function test_filtering_by_category_returns_that_category_plus_globals(): void
    {
        $this->actingAsAdmin();

        $aircon = CategoryLookup::create(['category_name' => 'Cat ' . uniqid(), 'category_type' => 'product']);
        $fridge = CategoryLookup::create(['category_name' => 'Cat ' . uniqid(), 'category_type' => 'product']);

        $scoped = $this->postJson('/api/variants', [
            'variant_name' => $this->name(),
            'category_id'  => $aircon->category_id,
        ])->json('data.variant_id');

        $other = $this->postJson('/api/variants', [
            'variant_name' => $this->name(),
            'category_id'  => $fridge->category_id,
        ])->json('data.variant_id');

        $global = $this->postJson('/api/variants', ['variant_name' => $this->name()])->json('data.variant_id');

        $ids = collect($this->getJson("/api/variants?category_id={$aircon->category_id}")->json('data'))
            ->pluck('variant_id')->all();

        $this->assertContains($scoped, $ids);
        $this->assertContains($global, $ids);
        $this->assertNotContains($other, $ids);
    }

    public function test_renaming_cascades_into_appliance_details(): void
    {
        $this->actingAsAdmin();

        $old = $this->name();
        $new = $this->name();

        $id = $this->postJson('/api/variants', ['variant_name' => $old])->json('data.variant_id');
        $this->applianceWithVariant($old);

        $this->putJson("/api/variants/{$id}", ['variant_name' => $new])->assertStatus(200);

        $this->assertSame(0, DB::table('appliance_details')->where('variant', $old)->count());
        $this->assertSame(1, DB::table('appliance_details')->where('variant', $new)->count());
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd back-end
php artisan test --filter=VariantLookupTest
```

Expected: FAIL — 404, `/api/variants` is not routed.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Models\VariantLookup;
use App\Services\LookupRenameService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VariantLookupController extends Controller
{
    use ApiResponse;
    use HandlesLookupCrud;

    public function __construct(private LookupRenameService $renamer)
    {
    }

    protected function lookupModel(): string
    {
        return VariantLookup::class;
    }

    protected function lookupNameColumn(): string
    {
        return 'variant_name';
    }

    protected function lookupLabel(): string
    {
        return 'Variant';
    }

    protected function lookupIndexQuery(Request $request)
    {
        $query = VariantLookup::orderBy('sort_order')->orderBy('variant_name');

        if ($request->filled('category_id')) {
            $categoryId = (int) $request->input('category_id');

            $query->where(function ($q) use ($categoryId) {
                $q->where('category_id', $categoryId)->orWhereNull('category_id');
            });
        }

        return $query;
    }

    protected function lookupRules(Request $request, $id = null): array
    {
        $categoryId = $request->input('category_id');

        $unique = Rule::unique('variant_lookup', 'variant_name')
            ->whereNull('deleted_at')
            ->where(function ($query) use ($categoryId) {
                return ($categoryId === null || $categoryId === '')
                    ? $query->whereNull('category_id')
                    : $query->where('category_id', $categoryId);
            });

        if ($id !== null) {
            $unique->ignore($id, 'variant_id');
        }

        return [
            'variant_name' => [
                $id === null ? 'required' : 'sometimes',
                'string',
                'max:100',
                $unique,
            ],
            'category_id' => 'nullable|integer|exists:category_lookup,category_id',
            'description' => 'nullable|string',
            'sort_order'  => 'nullable|integer|min:0|max:255',
        ];
    }

    protected function usageCountFor($row): int
    {
        // BINARY for the same reason as capacity: the collation is
        // case-insensitive and case variants are legitimately distinct rows.
        return (int) DB::table('appliance_details')
            ->whereRaw('BINARY variant = ?', [$row->variant_name])
            ->count();
    }

    protected function supportsMerge(): bool
    {
        return true;
    }

    protected function cascadeValueChange(Model $row, string $from, string $to): int
    {
        return $this->renamer->cascade(
            [
                'appliance_details' => 'variant',
                'product_sets'      => 'variant',
            ],
            $from,
            $to
        );
    }
}
```

Add `use Illuminate\Database\Eloquent\Model;` to the imports.

- [ ] **Step 4: Add the routes**

Add the import at the top of `back-end/routes/api.php`:

```php
use App\Http\Controllers\Api\VariantLookupController;
```

Then insert directly after the capacities block from Task 6:

```php
    /* ---------------------------------------------------------------
     | Variants (Item Lookups page - Appliance tab)
     * ------------------------------------------------------------- */
    Route::middleware('permission:lookup_management,read')->group(function () {
        Route::get('/variants/archived', [VariantLookupController::class, 'archived']);
        Route::get('/variants', [VariantLookupController::class, 'index']);
        Route::get('/variants/{id}', [VariantLookupController::class, 'show']);
    });
    Route::middleware('permission:lookup_management,create')
        ->post('/variants', [VariantLookupController::class, 'store']);
    Route::middleware('permission:lookup_management,update')
        ->put('/variants/{id}', [VariantLookupController::class, 'update']);
    Route::middleware('permission:lookup_management,update')
        ->post('/variants/{id}/merge', [VariantLookupController::class, 'merge']);
    Route::middleware('permission:lookup_management,archive')
        ->delete('/variants/{id}', [VariantLookupController::class, 'destroy']);
    Route::middleware('permission:lookup_management,archive')
        ->post('/variants/{id}/restore', [VariantLookupController::class, 'restore']);
    Route::middleware('permission:lookup_management,archive')
        ->delete('/variants/{id}/force', [VariantLookupController::class, 'forceDestroy']);
```

- [ ] **Step 5: Run the tests again**

```bash
php artisan test --filter=VariantLookupTest
```

Expected: PASS — 3 passed.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/VariantLookupController.php back-end/routes/api.php back-end/tests/Feature/Lookups/VariantLookupTest.php
git commit -m "feat(lookups): add variant lookup endpoint"
```

---

## Task 9: Issuable unit preset endpoint

No category scoping and no cascade — a preset rename must **not** touch `product_issuable_units.label`, because that label is per-product and independently editable.

**Files:**
- Create: `back-end/app/Http/Controllers/Api/IssuableUnitPresetController.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/Lookups/IssuableUnitPresetTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Lookups;

use App\Models\Role;
use App\Models\UnitLookup;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IssuableUnitPresetTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): void
    {
        $roleId = Role::where('role_name', 'admin')->value('role_id');

        $this->actingAs(User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'Admin',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]));
    }

    private function unit(): UnitLookup
    {
        return UnitLookup::create([
            'unit_name'         => 'Unit ' . uniqid(),
            'unit_abbreviation' => 'u' . substr(uniqid(), -4),
        ]);
    }

    public function test_create_stores_the_conversion_factor(): void
    {
        $this->actingAsAdmin();
        $unit = $this->unit();
        $label = 'Roll ' . uniqid();

        $response = $this->postJson('/api/issuable-unit-presets', [
            'label'                     => $label,
            'unit_id'                   => $unit->unit_id,
            'default_conversion_factor' => 50,
            'is_whole_package'          => true,
        ])->assertStatus(201);

        $this->assertSame($label, $response->json('data.label'));
        $this->assertEquals(50, $response->json('data.default_conversion_factor'));
        $this->assertTrue($response->json('data.is_whole_package'));
    }

    public function test_a_preset_requires_an_existing_unit(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/issuable-unit-presets', [
            'label'   => 'Roll ' . uniqid(),
            'unit_id' => 99999999,
        ])->assertStatus(422);
    }

    public function test_labels_are_unique(): void
    {
        $this->actingAsAdmin();
        $unit = $this->unit();
        $label = 'Roll ' . uniqid();

        $this->postJson('/api/issuable-unit-presets', ['label' => $label, 'unit_id' => $unit->unit_id])
            ->assertStatus(201);
        $this->postJson('/api/issuable-unit-presets', ['label' => $label, 'unit_id' => $unit->unit_id])
            ->assertStatus(422);
    }

    public function test_usage_count_reflects_products_using_the_label(): void
    {
        $this->actingAsAdmin();
        $unit = $this->unit();
        $label = 'Roll ' . uniqid();

        $this->postJson('/api/issuable-unit-presets', ['label' => $label, 'unit_id' => $unit->unit_id]);

        $productId = DB::table('products')->insertGetId([
            // product_code is NOT NULL and unique; omitting it fails the insert.
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'P ' . uniqid(),
            'product_type' => 'consumable',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('product_issuable_units')->insert([
            'product_id'        => $productId,
            'unit_id'           => $unit->unit_id,
            'label'             => $label,
            'conversion_factor' => 50,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $row = collect($this->getJson('/api/issuable-unit-presets')->json('data'))
            ->firstWhere('label', $label);

        $this->assertSame(1, $row['usage_count']);
    }

    public function test_renaming_a_preset_does_not_touch_product_rows(): void
    {
        $this->actingAsAdmin();
        $unit = $this->unit();
        $label = 'Roll ' . uniqid();

        $id = $this->postJson('/api/issuable-unit-presets', ['label' => $label, 'unit_id' => $unit->unit_id])
            ->json('data.preset_id');

        $productId = DB::table('products')->insertGetId([
            // product_code is NOT NULL and unique; omitting it fails the insert.
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'P ' . uniqid(),
            'product_type' => 'consumable',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('product_issuable_units')->insert([
            'product_id'        => $productId,
            'unit_id'           => $unit->unit_id,
            'label'             => $label,
            'conversion_factor' => 50,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $this->putJson("/api/issuable-unit-presets/{$id}", ['label' => 'Renamed ' . uniqid()])
            ->assertStatus(200);

        // Presets are templates, not owners. A product's label is its own and
        // must survive a preset rename untouched.
        $this->assertSame(1, DB::table('product_issuable_units')->where('label', $label)->count());
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
php artisan test --filter=IssuableUnitPresetTest
```

Expected: FAIL — 404 on `/api/issuable-unit-presets`.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Models\IssuableUnitPreset;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IssuableUnitPresetController extends Controller
{
    use ApiResponse;
    use HandlesLookupCrud;

    protected function lookupModel(): string
    {
        return IssuableUnitPreset::class;
    }

    protected function lookupNameColumn(): string
    {
        return 'label';
    }

    protected function lookupLabel(): string
    {
        return 'Issuable unit preset';
    }

    protected function lookupIndexQuery(Request $request)
    {
        return IssuableUnitPreset::with('unit')
            ->orderBy('sort_order')
            ->orderBy('label');
    }

    protected function lookupRules(Request $request, $id = null): array
    {
        $unique = Rule::unique('issuable_unit_preset', 'label')->whereNull('deleted_at');

        if ($id !== null) {
            $unique->ignore($id, 'preset_id');
        }

        return [
            'label' => [
                $id === null ? 'required' : 'sometimes',
                'string',
                'max:100',
                $unique,
            ],
            'unit_id' => [
                $id === null ? 'required' : 'sometimes',
                'integer',
                'exists:unit_lookup,unit_id',
            ],
            'default_conversion_factor' => 'nullable|numeric|min:0.000001',
            'is_whole_package'          => 'nullable|boolean',
            'description'               => 'nullable|string',
            'sort_order'                => 'nullable|integer|min:0|max:255',
        ];
    }

    protected function usageCountFor($row): int
    {
        // BINARY for consistency with the other lookups' usage counts.
        return (int) DB::table('product_issuable_units')
            ->whereRaw('BINARY label = ?', [$row->label])
            ->count();
    }

    // cascadeValueChange is deliberately NOT overridden, and supportsMerge()
    // stays false. A preset is a template: the per-product label is
    // independently editable and must not be rewritten when the preset it was
    // created from is renamed. With no cascade, merging would archive the
    // source and move nothing, so the trait refuses it outright.
}
```

- [ ] **Step 4: Add the routes**

Add the import at the top of `back-end/routes/api.php`:

```php
use App\Http\Controllers\Api\IssuableUnitPresetController;
```

Then insert after the variants block. Note there is no `merge` route — presets do not cascade, so merging them would be a no-op.

```php
    /* ---------------------------------------------------------------
     | Issuable unit presets (Item Lookups page - Installation Materials tab)
     * ------------------------------------------------------------- */
    Route::middleware('permission:lookup_management,read')->group(function () {
        Route::get('/issuable-unit-presets/archived', [IssuableUnitPresetController::class, 'archived']);
        Route::get('/issuable-unit-presets', [IssuableUnitPresetController::class, 'index']);
        Route::get('/issuable-unit-presets/{id}', [IssuableUnitPresetController::class, 'show']);
    });
    Route::middleware('permission:lookup_management,create')
        ->post('/issuable-unit-presets', [IssuableUnitPresetController::class, 'store']);
    Route::middleware('permission:lookup_management,update')
        ->put('/issuable-unit-presets/{id}', [IssuableUnitPresetController::class, 'update']);
    Route::middleware('permission:lookup_management,archive')
        ->delete('/issuable-unit-presets/{id}', [IssuableUnitPresetController::class, 'destroy']);
    Route::middleware('permission:lookup_management,archive')
        ->post('/issuable-unit-presets/{id}/restore', [IssuableUnitPresetController::class, 'restore']);
    Route::middleware('permission:lookup_management,archive')
        ->delete('/issuable-unit-presets/{id}/force', [IssuableUnitPresetController::class, 'forceDestroy']);
```

- [ ] **Step 5: Run the tests again**

```bash
php artisan test --filter=IssuableUnitPresetTest
```

Expected: PASS — 5 passed.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/IssuableUnitPresetController.php back-end/routes/api.php back-end/tests/Feature/Lookups/IssuableUnitPresetTest.php
git commit -m "feat(lookups): add issuable unit preset endpoint"
```

---

## Task 10: Retrofit the three existing lookup controllers

`UnitLookupController` is a pure vanilla case and moves fully onto the trait. `CategoryLookupController` and `BrandLookupController` keep their bespoke aggregate `index`/`performance` but drop their hand-rolled archive trio.

**Trait contract reminder.** After the Task 4 review the trait exposes: `lookupOrderColumns()` (defaults to the name column alone, since only the three new tables have `sort_order` — the existing three would otherwise hit SQL error 1054), `lookupArchivedQuery()` alongside `lookupIndexQuery()` (so the `?search=` filter `unitsAPI.getArchived(params)` already sends keeps working), an **abstract** `usageCountFor()` that every consumer must implement, a `supportsMerge()` opt-in enforced by `merge()` with a 422, and `cascadeValueChange($row, $from, $to)` — named for value changes generally because both rename and merge drive it. All three controllers retrofitted here must therefore implement `usageCountFor()`.

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/UnitLookupController.php`
- Modify: `back-end/app/Http/Controllers/Api/CategoryLookupController.php`
- Modify: `back-end/app/Http/Controllers/Api/BrandLookupController.php`
- Modify: `back-end/routes/api.php` (units middleware)
- Test: `back-end/tests/Feature/Lookups/RetrofitCompatTest.php`

- [ ] **Step 1: Write the failing compatibility test**

The point of this test is that nothing observable changes for existing consumers.

```php
<?php

namespace Tests\Feature\Lookups;

use App\Models\BrandLookup;
use App\Models\CategoryLookup;
use App\Models\Role;
use App\Models\UnitLookup;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RetrofitCompatTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): void
    {
        $roleId = Role::where('role_name', 'admin')->value('role_id');

        $this->actingAs(User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'Admin',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]));
    }

    public function test_units_index_still_returns_unit_name_and_abbreviation(): void
    {
        $this->actingAsAdmin();

        $unit = UnitLookup::create([
            'unit_name'         => 'Unit ' . uniqid(),
            'unit_abbreviation' => 'u' . substr(uniqid(), -4),
        ]);

        $row = collect($this->getJson('/api/units')->json('data'))
            ->firstWhere('unit_id', $unit->unit_id);

        $this->assertNotNull($row, 'The unit must still appear in /api/units');
        $this->assertSame($unit->unit_name, $row['unit_name']);
        $this->assertArrayHasKey('unit_abbreviation', $row);
    }

    public function test_units_gain_a_usage_count(): void
    {
        $this->actingAsAdmin();

        $unit = UnitLookup::create([
            'unit_name'         => 'Unit ' . uniqid(),
            'unit_abbreviation' => 'u' . substr(uniqid(), -4),
        ]);

        $row = collect($this->getJson('/api/units')->json('data'))
            ->firstWhere('unit_id', $unit->unit_id);

        $this->assertSame(0, $row['usage_count']);
    }

    public function test_units_archive_and_restore_still_work(): void
    {
        $this->actingAsAdmin();

        $unit = UnitLookup::create([
            'unit_name'         => 'Unit ' . uniqid(),
            'unit_abbreviation' => 'u' . substr(uniqid(), -4),
        ]);

        $this->deleteJson("/api/units/{$unit->unit_id}")->assertStatus(200);

        $archivedIds = collect($this->getJson('/api/units/archived')->json('data'))
            ->pluck('unit_id')->all();
        $this->assertContains($unit->unit_id, $archivedIds);

        $this->postJson("/api/units/{$unit->unit_id}/restore")->assertStatus(200);
    }

    public function test_categories_index_still_returns_its_aggregates(): void
    {
        $this->actingAsAdmin();

        $category = CategoryLookup::create([
            'category_name' => 'Cat ' . uniqid(),
            'category_type' => 'product',
        ]);

        $row = collect($this->getJson('/api/categories')->json('data'))
            ->firstWhere('category_id', $category->category_id);

        // These four keys are consumed by the Category Performance panel and
        // by the dashboards; the retrofit must not disturb them.
        foreach (['items_count', 'total_units', 'total_value', 'avg_price'] as $key) {
            $this->assertArrayHasKey($key, $row, "Category index lost '{$key}'");
        }
    }

    public function test_category_performance_endpoint_still_works(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/categories/performance')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_categories_archive_and_restore_still_work(): void
    {
        $this->actingAsAdmin();

        $category = CategoryLookup::create([
            'category_name' => 'Cat ' . uniqid(),
            'category_type' => 'product',
        ]);

        $this->deleteJson("/api/categories/{$category->category_id}")->assertStatus(200);

        $archivedIds = collect($this->getJson('/api/categories/archived')->json('data'))
            ->pluck('category_id')->all();
        $this->assertContains($category->category_id, $archivedIds);

        $this->postJson("/api/categories/{$category->category_id}/restore")->assertStatus(200);
    }

    public function test_brands_index_still_returns_its_aggregates(): void
    {
        $this->actingAsAdmin();

        $brand = BrandLookup::create(['brand_name' => 'Brand ' . uniqid()]);

        $row = collect($this->getJson('/api/brands')->json('data'))
            ->firstWhere('brand_id', $brand->brand_id);

        foreach (['items_count', 'total_units', 'total_value'] as $key) {
            $this->assertArrayHasKey($key, $row, "Brand index lost '{$key}'");
        }
    }

    public function test_brands_archive_and_restore_still_work(): void
    {
        $this->actingAsAdmin();

        $brand = BrandLookup::create(['brand_name' => 'Brand ' . uniqid()]);

        $this->deleteJson("/api/brands/{$brand->brand_id}")->assertStatus(200);

        $archivedIds = collect($this->getJson('/api/brands/archived')->json('data'))
            ->pluck('brand_id')->all();
        $this->assertContains($brand->brand_id, $archivedIds);

        $this->postJson("/api/brands/{$brand->brand_id}/restore")->assertStatus(200);
    }

    public function test_force_deleting_a_unit_referenced_by_a_preset_returns_422_not_500(): void
    {
        $this->actingAsAdmin();

        $unit = UnitLookup::create([
            'unit_name'         => 'Unit ' . uniqid(),
            'unit_abbreviation' => 'u' . substr(uniqid(), -4),
        ]);

        IssuableUnitPreset::create([
            'label'   => 'Preset ' . uniqid(),
            'unit_id' => $unit->unit_id,
        ]);

        $this->deleteJson("/api/units/{$unit->unit_id}")->assertStatus(200);

        // usageCountFor counts products, not presets, so this falls through to
        // the restrict-on-delete foreign key. The trait must turn that into a
        // 422 rather than letting a QueryException surface as a 500.
        $this->deleteJson("/api/units/{$unit->unit_id}/force")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
```

Add `use App\Models\IssuableUnitPreset;` to the test's imports.

- [ ] **Step 2: Run it**

```bash
php artisan test --filter=RetrofitCompatTest
```

Expected: FAIL on `test_units_gain_a_usage_count` (`Undefined array key "usage_count"`) and on the force-delete test (the old controller returns 500, not 422). Everything else should already pass — that is the baseline the retrofit must preserve.

- [ ] **Step 3: Replace `UnitLookupController` entirely**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Models\UnitLookup;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UnitLookupController extends Controller
{
    use ApiResponse;
    use HandlesLookupCrud;

    protected function lookupModel(): string
    {
        return UnitLookup::class;
    }

    protected function lookupNameColumn(): string
    {
        return 'unit_name';
    }

    protected function lookupLabel(): string
    {
        return 'Unit';
    }

    /**
     * unit_lookup predates the shared sort_order convention and has no such
     * column, so the trait's default name-only ordering is already correct and
     * lookupOrderColumns() needs no override.
     *
     * The ?search= parameter is retained on BOTH lists because existing callers
     * rely on it — frontend `unitsAPI.getArchived(params)` passes it too.
     */
    protected function lookupIndexQuery(Request $request)
    {
        return $this->applySearch(UnitLookup::orderBy('unit_name'), $request);
    }

    protected function lookupArchivedQuery(Request $request)
    {
        return $this->applySearch(
            UnitLookup::onlyTrashed()->orderBy('deleted_at', 'desc'),
            $request
        );
    }

    private function applySearch($query, Request $request)
    {
        if ($request->filled('search')) {
            $query->where('unit_name', 'like', '%' . $request->input('search') . '%');
        }

        return $query;
    }

    protected function lookupRules(Request $request, $id = null): array
    {
        $unique = Rule::unique('unit_lookup', 'unit_name')->whereNull('deleted_at');

        if ($id !== null) {
            $unique->ignore($id, 'unit_id');
        }

        return [
            'unit_name' => [
                $id === null ? 'required' : 'sometimes',
                'string',
                'max:100',
                $unique,
            ],
            'unit_abbreviation' => 'nullable|string|max:20',
        ];
    }

    protected function usageCountFor($row): int
    {
        return (int) DB::table('products')->where('unit_id', $row->unit_id)->count();
    }
}
```

- [ ] **Step 4: Drop the archive trio from `CategoryLookupController`**

Delete the `archived()`, `restore()` and `forceDestroy()` methods (currently lines 128-148) and add the trait. The class header becomes:

```php
use App\Http\Controllers\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Models\CategoryLookup;
use App\Models\Product;
use App\Models\Inventory;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryLookupController extends Controller
{
    use ApiResponse;
    // index(), performance(), store(), show() and update() below are bespoke:
    // they carry inventory-value aggregates the generic trait knows nothing
    // about. Only the archive/restore/force trio comes from the trait.
    use HandlesLookupCrud {
        index        as protected traitIndex;
        store        as protected traitStore;
        show         as protected traitShow;
        update       as protected traitUpdate;
        destroy      as protected traitDestroy;
    }
```

The aliased methods are unused; aliasing rather than excluding keeps the trait's contract intact while letting this class's own `index`, `store`, `show`, `update` and `destroy` win. Then add the three required hooks at the bottom of the class:

```php
    protected function lookupModel(): string
    {
        return CategoryLookup::class;
    }

    protected function lookupNameColumn(): string
    {
        return 'category_name';
    }

    protected function lookupLabel(): string
    {
        return 'Category';
    }

    protected function lookupLabelPlural(): string
    {
        return 'Categories';
    }

    protected function lookupRules(Request $request, $id = null): array
    {
        $unique = Rule::unique('category_lookup', 'category_name')->whereNull('deleted_at');

        if ($id !== null) {
            $unique->ignore($id, 'category_id');
        }

        return [
            'category_name' => [$id === null ? 'required' : 'sometimes', 'string', 'max:100', $unique],
            'category_type' => 'nullable|string|max:50',
            'description'   => 'nullable|string',
        ];
    }

    protected function usageCountFor($row): int
    {
        return (int) Product::where('category_id', $row->category_id)->count();
    }
```

- [ ] **Step 5: Do the same for `BrandLookupController`**

Delete `archived()`, `restore()` and `forceDestroy()` (lines 88-108) and apply the same pattern:

```php
use App\Http\Controllers\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Models\ApplianceDetail;
use App\Models\BrandLookup;
use App\Models\Inventory;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrandLookupController extends Controller
{
    use ApiResponse;
    use HandlesLookupCrud {
        index   as protected traitIndex;
        store   as protected traitStore;
        show    as protected traitShow;
        update  as protected traitUpdate;
        destroy as protected traitDestroy;
    }
```

with these hooks at the bottom:

```php
    protected function lookupModel(): string
    {
        return BrandLookup::class;
    }

    protected function lookupNameColumn(): string
    {
        return 'brand_name';
    }

    protected function lookupLabel(): string
    {
        return 'Brand';
    }

    protected function lookupRules(Request $request, $id = null): array
    {
        $unique = Rule::unique('brand_lookup', 'brand_name')->whereNull('deleted_at');

        if ($id !== null) {
            $unique->ignore($id, 'brand_id');
        }

        return [
            'brand_name'  => [$id === null ? 'required' : 'sometimes', 'string', 'max:100', $unique],
            'description' => 'nullable|string',
        ];
    }

    protected function usageCountFor($row): int
    {
        return (int) ApplianceDetail::where('brand_id', $row->brand_id)->count();
    }
```

- [ ] **Step 6: Move the units routes onto the permission system**

In `back-end/routes/api.php`, replace the five `role:` middlewares on the units routes (lines 285-289) with:

```php
    Route::middleware('permission:lookup_management,create')
        ->post('/units', [UnitLookupController::class, 'store']);
    Route::middleware('permission:lookup_management,update')
        ->put('/units/{id}', [UnitLookupController::class, 'update']);
    Route::middleware('permission:lookup_management,archive')
        ->delete('/units/{id}', [UnitLookupController::class, 'destroy']);
    Route::middleware('permission:lookup_management,archive')
        ->post('/units/{id}/restore', [UnitLookupController::class, 'restore']);
    Route::middleware('permission:lookup_management,archive')
        ->delete('/units/{id}/force', [UnitLookupController::class, 'forceDestroy']);
```

- [ ] **Step 7: Run the compatibility tests plus the whole lookup suite**

```bash
php artisan test --filter=RetrofitCompatTest
php artisan test --filter=Lookups
```

Expected: PASS — 9 passed for the compat test; the full `Lookups` suite green.

- [ ] **Step 8: Run the broader suite to catch anything downstream**

```bash
php artisan test
```

Expected: no new failures versus the pre-task baseline. `RbacRouteMatrixTest` is the one most likely to notice the units middleware change — if it fails, add `lookup_management` to its expectations rather than reverting the route change.

- [ ] **Step 9: Commit**

```bash
git add back-end/app/Http/Controllers/Api/UnitLookupController.php \
        back-end/app/Http/Controllers/Api/CategoryLookupController.php \
        back-end/app/Http/Controllers/Api/BrandLookupController.php \
        back-end/routes/api.php \
        back-end/tests/Feature/Lookups/RetrofitCompatTest.php
git commit -m "refactor(lookups): move unit/category/brand controllers onto shared trait"
```

---

## Task 11: Permission enforcement

Task 5 proved the matrix entry exists. This task proves the routes actually honour it.

**Files:**
- Test: `back-end/tests/Feature/Lookups/LookupRouteAccessTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Lookups;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LookupRouteAccessTest extends TestCase
{
    use DatabaseTransactions;

    private function userWithRole(string $roleName): User
    {
        $roleId = Role::where('role_name', $roleName)->value('role_id');
        $this->assertNotNull($roleId, "Role {$roleName} missing - seed the test database");

        return User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'User',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]);
    }

    private function value(): string
    {
        return 'T' . substr(uniqid(), -8) . 'HP';
    }

    public function test_admin_can_do_everything(): void
    {
        $this->actingAs($this->userWithRole('admin'));

        $id = $this->postJson('/api/capacities', ['capacity_value' => $this->value()])
            ->assertStatus(201)
            ->json('data.capacity_id');

        $this->putJson("/api/capacities/{$id}", ['capacity_value' => $this->value()])->assertStatus(200);
        $this->deleteJson("/api/capacities/{$id}")->assertStatus(200);
    }

    public function test_inventory_analyst_can_read_create_and_update_but_not_archive(): void
    {
        $this->actingAs($this->userWithRole('inventory_analyst'));

        $this->getJson('/api/capacities')->assertStatus(200);

        $id = $this->postJson('/api/capacities', ['capacity_value' => $this->value()])
            ->assertStatus(201)
            ->json('data.capacity_id');

        $this->putJson("/api/capacities/{$id}", ['capacity_value' => $this->value()])->assertStatus(200);

        // archive is admin-only, mirroring category_management exactly.
        $this->deleteJson("/api/capacities/{$id}")->assertStatus(403);
    }

    public function test_auditor_can_read_but_not_write(): void
    {
        $this->actingAs($this->userWithRole('auditor'));

        $this->getJson('/api/capacities')->assertStatus(200);
        $this->postJson('/api/capacities', ['capacity_value' => $this->value()])->assertStatus(403);
    }

    public function test_warehouse_personnel_cannot_read_the_lookups(): void
    {
        $this->actingAs($this->userWithRole('warehouse_personnel'));

        $this->getJson('/api/capacities')->assertStatus(403);
        $this->getJson('/api/variants')->assertStatus(403);
        $this->getJson('/api/issuable-unit-presets')->assertStatus(403);
    }

    public function test_units_writes_are_permission_gated_not_role_gated(): void
    {
        // Before the retrofit these routes used role:admin,inventory_analyst.
        // Auditor was blocked by role; it must now be blocked by permission,
        // and the failure mode must still be 403 rather than 500.
        $this->actingAs($this->userWithRole('auditor'));

        $this->postJson('/api/units', ['unit_name' => 'Unit ' . uniqid()])->assertStatus(403);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/capacities')->assertStatus(401);
    }
}
```

- [ ] **Step 2: Run it**

```bash
cd back-end
php artisan test --filter=LookupRouteAccessTest
```

Expected: PASS — 6 passed. The routes and permission are already in place from Tasks 5-10, so this test documents and locks the behaviour.

If `test_inventory_analyst_can_read_create_and_update_but_not_archive` fails with 200 instead of 403, the route is missing its `permission:lookup_management,archive` middleware. If everything returns 403 for admin, run `php artisan config:clear` and reseed.

- [ ] **Step 3: Commit**

```bash
git add back-end/tests/Feature/Lookups/LookupRouteAccessTest.php
git commit -m "test(lookups): lock permission enforcement on lookup routes"
```

---

## Task 12: Backfill the lookups from existing data

Strict dropdowns are only safe once every value already in use exists in a lookup. This migration is the piece that keeps the feature from blocking item registration on day one.

**Files:**
- Create: `back-end/database/migrations/2026_07_30_000004_backfill_lookup_tables.php`
- Test: `back-end/tests/Feature/Lookups/BackfillTest.php`

- [ ] **Step 1: Write the failing test**

The migration's logic is extracted into a static helper so it can be tested directly — running a migration inside a `DatabaseTransactions` test is unreliable.

```php
<?php

namespace Tests\Feature\Lookups;

use App\Models\CategoryLookup;
use App\Models\UnitLookup;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillTest extends TestCase
{
    use DatabaseTransactions;

    private function appliance(?string $capacity, ?string $variant, ?int $categoryId): void
    {
        $productId = DB::table('products')->insertGetId([
            // product_code is NOT NULL and unique; omitting it fails the insert.
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'P ' . uniqid(),
            'product_type' => 'appliance',
            'category_id'  => $categoryId,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('appliance_details')->insert([
            'product_id'      => $productId,
            'capacity_rating' => $capacity,
            'variant'         => $variant,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public function test_distinct_values_are_inserted(): void
    {
        $category = CategoryLookup::create(['category_name' => 'Cat ' . uniqid(), 'category_type' => 'product']);
        $value = 'Z' . substr(uniqid(), -8) . 'HP';

        $this->appliance($value, null, $category->category_id);
        $this->appliance($value, null, $category->category_id);

        \Database\Seeders\LookupBackfill::run();

        $rows = DB::table('capacity_lookup')->where('capacity_value', $value)->get();

        $this->assertCount(1, $rows, 'A repeated value must produce exactly one lookup row');
        $this->assertSame($category->category_id, (int) $rows->first()->category_id);
    }

    public function test_a_value_spanning_two_categories_is_left_uncategorised(): void
    {
        $a = CategoryLookup::create(['category_name' => 'Cat ' . uniqid(), 'category_type' => 'product']);
        $b = CategoryLookup::create(['category_name' => 'Cat ' . uniqid(), 'category_type' => 'product']);
        $value = 'Z' . substr(uniqid(), -8) . 'HP';

        $this->appliance($value, null, $a->category_id);
        $this->appliance($value, null, $b->category_id);

        \Database\Seeders\LookupBackfill::run();

        $row = DB::table('capacity_lookup')->where('capacity_value', $value)->first();

        $this->assertNotNull($row);
        $this->assertNull($row->category_id, 'Ambiguous values must stay global rather than guess a category');
    }

    public function test_empty_and_whitespace_values_are_skipped(): void
    {
        $before = DB::table('capacity_lookup')->count();

        $this->appliance(null, null, null);
        $this->appliance('', null, null);
        $this->appliance('   ', null, null);

        \Database\Seeders\LookupBackfill::run();

        $this->assertSame($before, DB::table('capacity_lookup')->count());
    }

    public function test_case_variants_are_preserved_as_separate_rows(): void
    {
        $stem = 'Z' . substr(uniqid(), -8);

        $this->appliance($stem . 'HP', null, null);
        $this->appliance($stem . 'hp', null, null);

        \Database\Seeders\LookupBackfill::run();

        // The collation is case-insensitive, so a naive DISTINCT or existence
        // check collapses these two. Products reference the exact string, so
        // collapsing them here would orphan one set of items. They are kept
        // apart and consolidated later by an admin using merge.
        $count = DB::table('capacity_lookup')
            ->whereRaw('BINARY capacity_value = ?', [$stem . 'HP'])
            ->count()
            + DB::table('capacity_lookup')
                ->whereRaw('BINARY capacity_value = ?', [$stem . 'hp'])
                ->count();

        $this->assertSame(2, $count);
    }

    public function test_variants_are_backfilled_too(): void
    {
        $variant = 'Zv' . uniqid();

        $this->appliance(null, $variant, null);

        \Database\Seeders\LookupBackfill::run();

        $this->assertDatabaseHas('variant_lookup', ['variant_name' => $variant]);
    }

    public function test_presets_are_backfilled_from_product_issuable_units(): void
    {
        $unit = UnitLookup::create([
            'unit_name'         => 'Unit ' . uniqid(),
            'unit_abbreviation' => 'u' . substr(uniqid(), -4),
        ]);
        $label = 'Roll ' . uniqid();

        $productId = DB::table('products')->insertGetId([
            // product_code is NOT NULL and unique; omitting it fails the insert.
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'P ' . uniqid(),
            'product_type' => 'consumable',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('product_issuable_units')->insert([
            'product_id'        => $productId,
            'unit_id'           => $unit->unit_id,
            'label'             => $label,
            'conversion_factor' => 50,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        \Database\Seeders\LookupBackfill::run();

        $preset = DB::table('issuable_unit_preset')->where('label', $label)->first();

        $this->assertNotNull($preset);
        $this->assertEquals(50, $preset->default_conversion_factor);
        $this->assertSame($unit->unit_id, (int) $preset->unit_id);
    }

    public function test_running_twice_inserts_nothing_the_second_time(): void
    {
        $this->appliance('Z' . substr(uniqid(), -8) . 'HP', 'Zv' . uniqid(), null);

        \Database\Seeders\LookupBackfill::run();

        $capacities = DB::table('capacity_lookup')->count();
        $variants   = DB::table('variant_lookup')->count();

        \Database\Seeders\LookupBackfill::run();

        $this->assertSame($capacities, DB::table('capacity_lookup')->count());
        $this->assertSame($variants, DB::table('variant_lookup')->count());
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
php artisan test --filter=BackfillTest
```

Expected: FAIL — `Class "Database\Seeders\LookupBackfill" not found`.

- [ ] **Step 3: Write the backfill class**

Create `back-end/database/seeders/LookupBackfill.php`:

```php
<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\DB;

/**
 * Seeds the new lookup tables from the free-text values already stored on
 * products. Capacity and variant become strict dropdowns, so any value in use
 * that is missing from a lookup would block item registration until an admin
 * added it by hand.
 *
 * Idempotent: safe to run repeatedly.
 */
class LookupBackfill
{
    public static function run(): void
    {
        self::backfillFromAppliances('capacity_rating', 'capacity_lookup', 'capacity_value', 20);
        self::backfillFromAppliances('variant', 'variant_lookup', 'variant_name', 100);
        self::backfillFromProductSets('capacity_rating', 'capacity_lookup', 'capacity_value', 20);
        self::backfillFromProductSets('variant', 'variant_lookup', 'variant_name', 100);
        self::backfillPresets();
    }

    /**
     * Groups values in PHP rather than SQL. PHP array keys are case-sensitive;
     * a SQL GROUP BY under utf8mb4_unicode_ci would fold "1.5HP" and "1.5hp"
     * into one row, orphaning whichever spelling lost.
     */
    private static function backfillFromAppliances(
        string $sourceColumn,
        string $table,
        string $targetColumn,
        int $maxLength
    ): void {
        $rows = DB::table('appliance_details as ad')
            ->leftJoin('products as p', 'p.product_id', '=', 'ad.product_id')
            ->whereNotNull("ad.{$sourceColumn}")
            ->select("ad.{$sourceColumn} as value", 'p.category_id')
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $value = trim((string) $row->value);

            if ($value === '' || mb_strlen($value) > $maxLength) {
                continue;
            }

            if (! isset($grouped[$value])) {
                $grouped[$value] = [];
            }

            if ($row->category_id !== null) {
                $grouped[$value][(int) $row->category_id] = true;
            }
        }

        self::insertMissing($table, $targetColumn, $grouped);
    }

    private static function backfillFromProductSets(
        string $sourceColumn,
        string $table,
        string $targetColumn,
        int $maxLength
    ): void {
        if (! DB::getSchemaBuilder()->hasTable('product_sets')) {
            return;
        }

        $values = DB::table('product_sets')
            ->whereNotNull($sourceColumn)
            ->pluck($sourceColumn);

        $grouped = [];

        foreach ($values as $raw) {
            $value = trim((string) $raw);

            if ($value === '' || mb_strlen($value) > $maxLength) {
                continue;
            }

            // Sets carry no category, so these are always global.
            $grouped[$value] = $grouped[$value] ?? [];
        }

        self::insertMissing($table, $targetColumn, $grouped);
    }

    /**
     * @param  array<string,array<int,bool>>  $grouped  value => set of category ids
     */
    private static function insertMissing(string $table, string $column, array $grouped): void
    {
        foreach ($grouped as $value => $categoryIds) {
            $exists = DB::table($table)
                ->whereRaw("BINARY {$column} = ?", [$value])
                ->exists();

            if ($exists) {
                continue;
            }

            $ids = array_keys($categoryIds);

            DB::table($table)->insert([
                $column       => $value,
                // Only claim a category when every product using this value
                // agrees on one. Anything else stays global.
                'category_id' => count($ids) === 1 ? $ids[0] : null,
                'sort_order'  => 0,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    private static function backfillPresets(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('product_issuable_units')) {
            return;
        }

        $rows = DB::table('product_issuable_units')
            ->select('label', 'unit_id', 'conversion_factor', 'is_whole_package')
            ->get();

        $seen = [];

        foreach ($rows as $row) {
            $label = trim((string) $row->label);

            if ($label === '' || isset($seen[$label])) {
                continue;
            }

            $seen[$label] = true;

            $exists = DB::table('issuable_unit_preset')
                ->whereRaw('BINARY label = ?', [$label])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('issuable_unit_preset')->insert([
                'label'                     => $label,
                'unit_id'                   => $row->unit_id,
                'default_conversion_factor' => $row->conversion_factor,
                'is_whole_package'          => (bool) $row->is_whole_package,
                'sort_order'                => 0,
                'created_at'                => now(),
                'updated_at'                => now(),
            ]);
        }
    }
}
```

- [ ] **Step 4: Write the migration that calls it**

Create `back-end/database/migrations/2026_07_30_000004_backfill_lookup_tables.php`:

```php
<?php

use Database\Seeders\LookupBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        LookupBackfill::run();
    }

    public function down(): void
    {
        // Intentionally empty. Rolling back would delete lookup rows an admin
        // may since have edited, and the tables themselves are dropped by the
        // migrations that created them.
    }
};
```

- [ ] **Step 5: Run the tests**

```bash
php artisan test --filter=BackfillTest
```

Expected: PASS — 7 passed.

- [ ] **Step 6: Apply the backfill to both databases**

```bash
php artisan migrate --path=database/migrations/2026_07_30_000004_backfill_lookup_tables.php
DB_DATABASE=airkingV5_test php artisan migrate --path=database/migrations/2026_07_30_000004_backfill_lookup_tables.php
```

- [ ] **Step 7: Eyeball what it produced on the dev database**

```bash
php artisan tinker --execute="echo 'capacities='.DB::table('capacity_lookup')->count().' variants='.DB::table('variant_lookup')->count().' presets='.DB::table('issuable_unit_preset')->count();"
```

Expected: non-zero counts. Then check nothing in use was missed — this must print nothing:

```bash
php artisan tinker --execute="\$missing = DB::table('appliance_details')->whereNotNull('capacity_rating')->whereRaw(\"TRIM(capacity_rating) <> ''\")->whereNotIn('capacity_rating', DB::table('capacity_lookup')->pluck('capacity_value'))->pluck('capacity_rating')->unique(); echo \$missing->implode(',');"
```

Expected: empty output. Any value listed here would be unselectable in the item form after Task 17 — investigate before continuing.

- [ ] **Step 8: Commit**

```bash
git add back-end/database/seeders/LookupBackfill.php back-end/database/migrations/2026_07_30_000004_backfill_lookup_tables.php back-end/tests/Feature/Lookups/BackfillTest.php
git commit -m "feat(lookups): backfill lookup tables from existing free-text values"
```

---

## Task 13: Add the new lookups to the batch endpoint

Lets the management page and the item form fetch everything in one request instead of six.

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/BatchController.php`
- Test: `back-end/tests/Feature/Lookups/BatchIncludesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Lookups;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BatchIncludesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_batch_returns_the_three_new_lookups(): void
    {
        $roleId = Role::where('role_name', 'admin')->value('role_id');

        $this->actingAs(User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'Admin',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]));

        $response = $this->getJson('/api/batch?include=capacities,variants,issuable_unit_presets')
            ->assertStatus(200);

        foreach (['capacities', 'variants', 'issuable_unit_presets'] as $key) {
            $this->assertTrue($response->json("data.{$key}.success"), "Batch include '{$key}' failed");
            $this->assertIsArray($response->json("data.{$key}.data"));
        }
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
php artisan test --filter=BatchIncludesTest
```

Expected: FAIL — `data.capacities.success` is null because the include is unknown.

- [ ] **Step 3: Add the includes**

In `back-end/app/Http/Controllers/Api/BatchController.php`, add the imports:

```php
use App\Http\Controllers\Api\CapacityLookupController;
use App\Http\Controllers\Api\VariantLookupController;
use App\Http\Controllers\Api\IssuableUnitPresetController;
use Illuminate\Http\Request as HttpRequest;
```

Then insert after the existing `units` block (which ends around line 266), following the same try/catch shape the neighbouring includes use:

```php
        if (in_array('capacities', $parts)) {
            try {
                $data['capacities'] = json_decode(
                    app(CapacityLookupController::class)->index(new HttpRequest())->getContent(), true
                );
            } catch (\Throwable $e) {
                $data['capacities'] = ['success' => false, 'message' => $e->getMessage()];
            }
        }

        if (in_array('variants', $parts)) {
            try {
                $data['variants'] = json_decode(
                    app(VariantLookupController::class)->index(new HttpRequest())->getContent(), true
                );
            } catch (\Throwable $e) {
                $data['variants'] = ['success' => false, 'message' => $e->getMessage()];
            }
        }

        if (in_array('issuable_unit_presets', $parts)) {
            try {
                $data['issuable_unit_presets'] = json_decode(
                    app(IssuableUnitPresetController::class)->index(new HttpRequest())->getContent(), true
                );
            } catch (\Throwable $e) {
                $data['issuable_unit_presets'] = ['success' => false, 'message' => $e->getMessage()];
            }
        }
```

- [ ] **Step 4: Run the tests again**

```bash
php artisan test --filter=BatchIncludesTest
```

Expected: PASS — 1 passed.

- [ ] **Step 5: Run the whole backend suite**

```bash
php artisan test
```

Expected: green. This is the last backend task — everything from here is frontend.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/BatchController.php back-end/tests/Feature/Lookups/BatchIncludesTest.php
git commit -m "feat(lookups): add capacity, variant and preset batch includes"
```

---

## Task 14: Frontend API modules

**Files:**
- Modify: `frontend/src/admin/services/api.js`

- [ ] **Step 1: Add the three new modules and fill the gaps in the existing ones**

Insert after the existing `brandsAPI` block (ends around line 1223). Every lookup module exposes the same seven methods so `LookupPanel` can call them without knowing which lookup it is showing.

```javascript
/* ======================
   CAPACITIES API
====================== */
export const capacitiesAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/capacities?${query}` : '/capacities');
  },
  getArchived: async () => apiRequest('/capacities/archived'),
  create: async (data) =>
    apiRequest('/capacities', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/capacities/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) => apiRequest(`/capacities/${id}`, { method: 'DELETE' }),
  restore: async (id) => apiRequest(`/capacities/${id}/restore`, { method: 'POST' }),
  forceDelete: async (id) => apiRequest(`/capacities/${id}/force`, { method: 'DELETE' }),
  merge: async (id, targetId) =>
    apiRequest(`/capacities/${id}/merge`, {
      method: 'POST',
      body: JSON.stringify({ target_id: targetId }),
    }),
};

/* ======================
   VARIANTS API
====================== */
export const variantsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/variants?${query}` : '/variants');
  },
  getArchived: async () => apiRequest('/variants/archived'),
  create: async (data) =>
    apiRequest('/variants', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/variants/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) => apiRequest(`/variants/${id}`, { method: 'DELETE' }),
  restore: async (id) => apiRequest(`/variants/${id}/restore`, { method: 'POST' }),
  forceDelete: async (id) => apiRequest(`/variants/${id}/force`, { method: 'DELETE' }),
  merge: async (id, targetId) =>
    apiRequest(`/variants/${id}/merge`, {
      method: 'POST',
      body: JSON.stringify({ target_id: targetId }),
    }),
};

/* ======================
   ISSUABLE UNIT PRESETS API
====================== */
export const issuableUnitPresetsAPI = {
  getAll: async () => apiRequest('/issuable-unit-presets'),
  getArchived: async () => apiRequest('/issuable-unit-presets/archived'),
  create: async (data) =>
    apiRequest('/issuable-unit-presets', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/issuable-unit-presets/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) => apiRequest(`/issuable-unit-presets/${id}`, { method: 'DELETE' }),
  restore: async (id) => apiRequest(`/issuable-unit-presets/${id}/restore`, { method: 'POST' }),
  forceDelete: async (id) => apiRequest(`/issuable-unit-presets/${id}/force`, { method: 'DELETE' }),
};
```

- [ ] **Step 2: Add the missing `forceDelete` to the three existing modules**

The backend exposes a force-delete route for all six lookups, and the three new modules above have the method. Add it to the older three so every lookup module has the same surface — `LookupPanel` does not call it today (archive is the reversible path the UI offers), but a module that silently lacks a method its siblings have is a trap for the next person.

In `categoriesAPI`, after the `restore` line:

```javascript
  forceDelete: async (id) => apiRequest(`/categories/${id}/force`, { method: 'DELETE' }),
```

In `brandsAPI`, after its `restore` line:

```javascript
  forceDelete: async (id) => apiRequest(`/brands/${id}/force`, { method: 'DELETE' }),
```

In `unitsAPI`, after its `restore` line:

```javascript
  forceDelete: async (id) => apiRequest(`/units/${id}/force`, { method: 'DELETE' }),
```

- [ ] **Step 3: Verify the bundle still compiles**

```bash
cd frontend
npx eslint src/admin/services/api.js
```

Expected: no output (clean).

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -m "feat(lookups): add capacity, variant and preset API modules"
```

---

## Task 15: The lookup config file

One config object per lookup. This is the file someone edits to add a seventh lookup.

**Files:**
- Create: `frontend/src/admin/config/lookupConfigs.js`

- [ ] **Step 1: Write the config**

```javascript
import {
  categoriesAPI,
  brandsAPI,
  unitsAPI,
  capacitiesAPI,
  variantsAPI,
  issuableUnitPresetsAPI,
} from '../services/api';

/**
 * Every lookup on the Item Lookups page, described as data.
 *
 * LookupPanel renders any of these without knowing which one it is showing, so
 * adding a lookup means adding an entry here plus a route on the backend -- not
 * a new component.
 */
export const LOOKUP_CONFIGS = {
  categories: {
    key: 'categories',
    label: 'Categories',
    singular: 'Category',
    api: categoriesAPI,
    idField: 'category_id',
    nameField: 'category_name',
    categoryScoped: false,
    supportsMerge: false,
    fields: [
      { name: 'category_name', label: 'Category name', type: 'text', required: true, maxLength: 100 },
      {
        name: 'category_type',
        label: 'Type',
        type: 'select',
        defaultValue: 'product',
        options: [
          { value: 'product', label: 'Product' },
          { value: 'part', label: 'Part' },
          { value: 'accessory', label: 'Accessory' },
          { value: 'service', label: 'Service' },
        ],
      },
      { name: 'description', label: 'Description (optional)', type: 'textarea' },
    ],
    columns: [
      { key: 'category_name', label: 'Name' },
      { key: 'category_type', label: 'Type' },
      { key: 'description', label: 'Description' },
    ],
  },

  brands: {
    key: 'brands',
    label: 'Brands',
    singular: 'Brand',
    api: brandsAPI,
    idField: 'brand_id',
    nameField: 'brand_name',
    categoryScoped: false,
    supportsMerge: false,
    fields: [
      { name: 'brand_name', label: 'Brand name', type: 'text', required: true, maxLength: 100 },
      { name: 'description', label: 'Description (optional)', type: 'textarea' },
    ],
    columns: [
      { key: 'brand_name', label: 'Name' },
      { key: 'description', label: 'Description' },
    ],
  },

  variants: {
    key: 'variants',
    label: 'Variants',
    singular: 'Variant',
    api: variantsAPI,
    idField: 'variant_id',
    nameField: 'variant_name',
    categoryScoped: true,
    supportsMerge: true,
    fields: [
      { name: 'variant_name', label: 'Variant name', type: 'text', required: true, maxLength: 100 },
      { name: 'category_id', label: 'Category', type: 'category' },
      { name: 'description', label: 'Description (optional)', type: 'textarea' },
    ],
    columns: [
      { key: 'variant_name', label: 'Name' },
      { key: 'category_name', label: 'Category' },
      { key: 'description', label: 'Description' },
    ],
  },

  capacities: {
    key: 'capacities',
    label: 'Capacities',
    singular: 'Capacity',
    api: capacitiesAPI,
    idField: 'capacity_id',
    nameField: 'capacity_value',
    categoryScoped: true,
    supportsMerge: true,
    fields: [
      {
        name: 'capacity_value',
        label: 'Capacity',
        type: 'text',
        required: true,
        // Matches appliance_details.capacity_rating. A longer value would be
        // truncated on its way onto the product.
        maxLength: 20,
        placeholder: 'e.g. 1.5HP',
      },
      { name: 'category_id', label: 'Category', type: 'category' },
      { name: 'description', label: 'Description (optional)', type: 'textarea' },
    ],
    columns: [
      { key: 'capacity_value', label: 'Value' },
      { key: 'category_name', label: 'Category' },
      { key: 'description', label: 'Description' },
    ],
  },

  units: {
    key: 'units',
    label: 'Units of Measure',
    singular: 'Unit',
    api: unitsAPI,
    idField: 'unit_id',
    nameField: 'unit_name',
    categoryScoped: false,
    supportsMerge: false,
    fields: [
      { name: 'unit_name', label: 'Unit name', type: 'text', required: true, maxLength: 100 },
      { name: 'unit_abbreviation', label: 'Abbreviation', type: 'text', maxLength: 20 },
    ],
    columns: [
      { key: 'unit_name', label: 'Name' },
      { key: 'unit_abbreviation', label: 'Abbreviation' },
    ],
  },

  issuablePresets: {
    key: 'issuablePresets',
    label: 'Issuable Unit Presets',
    singular: 'Issuable unit preset',
    api: issuableUnitPresetsAPI,
    idField: 'preset_id',
    nameField: 'label',
    categoryScoped: false,
    supportsMerge: false,
    fields: [
      { name: 'label', label: 'Label', type: 'text', required: true, maxLength: 100, placeholder: 'e.g. Roll' },
      { name: 'unit_id', label: 'Base unit', type: 'unit', required: true },
      {
        name: 'default_conversion_factor',
        label: 'Default conversion factor',
        type: 'number',
        defaultValue: 1,
        hint: 'Inventory pieces deducted per 1 of this issuable unit.',
      },
      { name: 'is_whole_package', label: 'Represents one full package', type: 'checkbox' },
      { name: 'description', label: 'Description (optional)', type: 'textarea' },
    ],
    columns: [
      { key: 'label', label: 'Label' },
      { key: 'unit_name', label: 'Base unit' },
      { key: 'default_conversion_factor', label: 'Factor' },
    ],
  },
};

/** The two top-level tabs, and which lookups live under each. */
export const LOOKUP_TABS = [
  {
    key: 'appliance',
    label: 'Appliance Lookup',
    lookups: ['categories', 'brands', 'variants', 'capacities'],
  },
  {
    key: 'materials',
    label: 'Installation Materials Lookup',
    lookups: ['units', 'issuablePresets'],
  },
];

/** Resolves a lookup key to the tab that contains it, for URL deep links. */
export const tabForLookup = (lookupKey) =>
  LOOKUP_TABS.find((tab) => tab.lookups.includes(lookupKey)) || LOOKUP_TABS[0];
```

- [ ] **Step 2: Lint it**

```bash
cd frontend
npx eslint src/admin/config/lookupConfigs.js
```

Expected: no output.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/config/lookupConfigs.js
git commit -m "feat(lookups): add lookup config definitions"
```

---

## Task 16: The generic `LookupPanel` component

All CRUD lives here, once, for all six lookups.

**Files:**
- Create: `frontend/src/admin/components/LookupPanel.jsx`

- [ ] **Step 1: Write the component**

```jsx
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import Modal from './Modal';
import Loader from './Loader';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';

/**
 * Renders any lookup described by an entry in lookupConfigs.js.
 *
 * The panel owns the whole lifecycle for one lookup: list, search, add, edit,
 * archive with a usage warning, restore, and (for capacity and variant) merging
 * a duplicate into an existing value.
 */
const buildEmptyForm = (config) => {
  const form = {};
  config.fields.forEach((field) => {
    if (field.type === 'checkbox') {
      form[field.name] = field.defaultValue ?? false;
    } else {
      form[field.name] = field.defaultValue ?? '';
    }
  });
  return form;
};

const LookupPanel = ({ config, categories = [], units = [], permissions = {} }) => {
  const { canCreate = false, canUpdate = false, canArchive = false } = permissions;

  const [rows, setRows] = useState([]);
  const [archivedRows, setArchivedRows] = useState([]);
  const [showArchived, setShowArchived] = useState(false);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(() => buildEmptyForm(config));
  const [submitting, setSubmitting] = useState(false);

  const fetchRows = useCallback(async () => {
    setLoading(true);
    try {
      const [activeRes, archivedRes] = await Promise.all([
        config.api.getAll(),
        config.api.getArchived(),
      ]);
      setRows(Array.isArray(activeRes?.data) ? activeRes.data : []);
      setArchivedRows(Array.isArray(archivedRes?.data) ? archivedRes.data : []);
      setError(null);
    } catch (err) {
      setError(err.message || `Failed to load ${config.label.toLowerCase()}`);
      setRows([]);
      setArchivedRows([]);
    } finally {
      setLoading(false);
    }
  }, [config]);

  useEffect(() => {
    fetchRows();
    // Reset transient UI when switching between lookups.
    setSearch('');
    setShowArchived(false);
  }, [fetchRows]);

  const categoryNameFor = useCallback(
    (categoryId) => {
      if (!categoryId) return 'All categories';
      const match = categories.find((c) => String(c.category_id) === String(categoryId));
      return match ? match.category_name : '—';
    },
    [categories],
  );

  const unitNameFor = useCallback(
    (row) => row.unit?.unit_name || units.find((u) => String(u.unit_id) === String(row.unit_id))?.unit_name || '—',
    [units],
  );

  const cellValue = (row, key) => {
    if (key === 'category_name') return categoryNameFor(row.category_id);
    if (key === 'unit_name') return unitNameFor(row);
    const value = row[key];
    return value === null || value === undefined || value === '' ? '—' : String(value);
  };

  const visibleRows = useMemo(() => {
    const source = showArchived ? archivedRows : rows;
    const term = search.trim().toLowerCase();
    if (!term) return source;
    return source.filter((row) => String(row[config.nameField] ?? '').toLowerCase().includes(term));
  }, [showArchived, archivedRows, rows, search, config.nameField]);

  const openAdd = () => {
    setEditing(null);
    setForm(buildEmptyForm(config));
    setModalOpen(true);
  };

  const openEdit = (row) => {
    const next = {};
    config.fields.forEach((field) => {
      const value = row[field.name];
      if (field.type === 'checkbox') {
        next[field.name] = Boolean(value);
      } else {
        next[field.name] = value === null || value === undefined ? '' : value;
      }
    });
    setEditing(row);
    setForm(next);
    setModalOpen(true);
  };

  /**
   * A 422 naming the value column means the value already exists. Rather than
   * dead-ending the user, offer to fold this row into the existing one -- the
   * backfill produces near-duplicates, so this is the intended cleanup path.
   */
  const offerMerge = async (err) => {
    if (!config.supportsMerge || !editing) return false;

    const fieldErrors = err?.errors?.[config.nameField];
    if (!Array.isArray(fieldErrors) || fieldErrors.length === 0) return false;

    const typedName = String(form[config.nameField] ?? '').trim().toLowerCase();
    const target = rows.find(
      (row) =>
        String(row[config.nameField] ?? '').trim().toLowerCase() === typedName &&
        String(row[config.idField]) !== String(editing[config.idField]),
    );

    if (!target) return false;

    const usage = editing.usage_count ?? 0;
    const confirmed = await confirmAction({
      title: `"${target[config.nameField]}" already exists`,
      text: `Merge ${usage} item(s) from "${editing[config.nameField]}" into it? The old value will be archived.`,
      confirmText: 'Merge',
      tone: 'primary',
    });

    if (!confirmed) return true;

    try {
      const res = await config.api.merge(editing[config.idField], target[config.idField]);
      toast.success(res?.message || 'Merged.');
      setModalOpen(false);
      fetchRows();
    } catch (mergeErr) {
      toast.error(mergeErr.message || 'Failed to merge.');
    }
    return true;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    const nameValue = String(form[config.nameField] ?? '').trim();
    if (!nameValue) return;

    setSubmitting(true);
    try {
      const payload = { ...form };
      // An empty category select means "applies everywhere", which the API
      // expects as null rather than an empty string.
      if (config.categoryScoped && !payload.category_id) payload.category_id = null;

      if (editing) {
        await config.api.update(editing[config.idField], payload);
        toast.success(`${config.singular} updated.`);
      } else {
        await config.api.create(payload);
        toast.success(`${config.singular} added.`);
      }
      setModalOpen(false);
      fetchRows();
    } catch (err) {
      const handled = await offerMerge(err);
      if (!handled) {
        toast.error(err.errors?.[config.nameField]?.[0] || err.message || 'Failed to save.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  const handleArchive = async (row) => {
    const usage = row.usage_count ?? 0;
    const confirmed = await confirmAction({
      title: `Archive "${row[config.nameField]}"?`,
      text: usage > 0
        ? `Used by ${usage} item(s). They keep this value, but it will no longer be selectable for new items.`
        : 'It will no longer be selectable for new items.',
      confirmText: 'Archive',
    });
    if (!confirmed) return;

    try {
      await config.api.delete(row[config.idField]);
      toast.success(`"${row[config.nameField]}" archived.`);
      fetchRows();
    } catch (err) {
      toast.error(err.message || 'Failed to archive.');
    }
  };

  const handleRestore = async (row) => {
    try {
      await config.api.restore(row[config.idField]);
      toast.success(`"${row[config.nameField]}" restored.`);
      fetchRows();
    } catch (err) {
      toast.error(err.message || 'Failed to restore.');
    }
  };

  const renderField = (field) => {
    const value = form[field.name];
    const onChange = (next) => setForm((f) => ({ ...f, [field.name]: next }));

    if (field.type === 'textarea') {
      return (
        <textarea rows={2} value={value} onChange={(e) => onChange(e.target.value)} />
      );
    }
    if (field.type === 'checkbox') {
      return (
        <input type="checkbox" checked={Boolean(value)} onChange={(e) => onChange(e.target.checked)} />
      );
    }
    if (field.type === 'number') {
      return (
        <input type="number" step="any" min="0" value={value} onChange={(e) => onChange(e.target.value)} />
      );
    }
    if (field.type === 'select') {
      return (
        <select value={value} onChange={(e) => onChange(e.target.value)}>
          {field.options.map((opt) => (
            <option key={opt.value} value={opt.value}>{opt.label}</option>
          ))}
        </select>
      );
    }
    if (field.type === 'category') {
      return (
        <select value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
          <option value="">All categories</option>
          {categories.map((c) => (
            <option key={c.category_id} value={c.category_id}>{c.category_name}</option>
          ))}
        </select>
      );
    }
    if (field.type === 'unit') {
      return (
        <select value={value ?? ''} onChange={(e) => onChange(e.target.value)} required={field.required}>
          <option value="">Select unit</option>
          {units.map((u) => (
            <option key={u.unit_id} value={u.unit_id}>{u.unit_name}</option>
          ))}
        </select>
      );
    }
    return (
      <input
        type="text"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        required={field.required}
        maxLength={field.maxLength}
        placeholder={field.placeholder}
      />
    );
  };

  return (
    <section className="ak-card lk-panel">
      <header className="lk-panel-head">
        <div>
          <h2 className="ak-card-title">{config.label}</h2>
          <p className="ak-card-sub">
            {showArchived
              ? `${archivedRows.length} archived`
              : `${rows.length} active`}
          </p>
        </div>
        <div className="lk-panel-actions">
          <input
            type="search"
            className="ak-input lk-search"
            placeholder={`Search ${config.label.toLowerCase()}`}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          <label className="lk-archived-toggle">
            <input
              type="checkbox"
              checked={showArchived}
              onChange={(e) => setShowArchived(e.target.checked)}
            />
            Show archived
          </label>
          {canCreate && !showArchived && (
            <button type="button" className="ak-btn ak-btn-primary" onClick={openAdd}>
              + Add {config.singular}
            </button>
          )}
        </div>
      </header>

      {loading && <Loader />}
      {error && <div className="ak-error">{error}</div>}

      {!loading && !error && (
        <div className="ak-table-wrap">
          <table className="ak-table lk-table">
            <thead>
              <tr>
                {config.columns.map((col) => (
                  <th key={col.key} scope="col">{col.label}</th>
                ))}
                <th scope="col" className="ak-th-num">Used by</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
              {visibleRows.length === 0 ? (
                <tr>
                  <td colSpan={config.columns.length + 2} className="ak-table-empty">
                    {search
                      ? `No ${config.label.toLowerCase()} match "${search}".`
                      : `No ${showArchived ? 'archived ' : ''}${config.label.toLowerCase()}.`}
                  </td>
                </tr>
              ) : (
                visibleRows.map((row) => (
                  <tr key={row[config.idField]}>
                    {config.columns.map((col) => (
                      <td key={col.key}>{cellValue(row, col.key)}</td>
                    ))}
                    <td className="ak-td-num">{row.usage_count ?? 0}</td>
                    <td>
                      {showArchived
                        ? canArchive && (
                            <button type="button" className="ak-btn ak-btn-ghost" onClick={() => handleRestore(row)}>
                              Restore
                            </button>
                          )
                        : (
                          <>
                            {canUpdate && (
                              <button type="button" className="ak-btn ak-btn-ghost" onClick={() => openEdit(row)}>
                                Edit
                              </button>
                            )}
                            {canArchive && (
                              <button type="button" className="ak-btn ak-btn-ghost" onClick={() => handleArchive(row)}>
                                Archive
                              </button>
                            )}
                          </>
                        )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      )}

      <Modal
        isOpen={modalOpen}
        title={`${editing ? 'Edit' : 'Add'} ${config.singular}`}
        onClose={() => setModalOpen(false)}
      >
        <form onSubmit={handleSubmit} className="lk-form">
          {config.fields.map((field) => (
            <div key={field.name} className="lk-form-group">
              <label>{field.label}</label>
              {renderField(field)}
              {field.hint && <p className="lk-form-hint">{field.hint}</p>}
            </div>
          ))}
          <div className="lk-form-actions">
            <button type="button" className="ak-btn ak-btn-secondary" onClick={() => setModalOpen(false)}>
              Cancel
            </button>
            <button type="submit" className="ak-btn ak-btn-primary" disabled={submitting}>
              {submitting ? 'Saving…' : (editing ? 'Update' : `Add ${config.singular}`)}
            </button>
          </div>
        </form>
      </Modal>
    </section>
  );
};

export default LookupPanel;
```

- [ ] **Step 2: Lint it**

```bash
cd frontend
npx eslint src/admin/components/LookupPanel.jsx
```

Expected: no output.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/components/LookupPanel.jsx
git commit -m "feat(lookups): add generic LookupPanel component"
```

---

## Task 17: The Item Lookups page

Replaces `CategoryBrandManagement.jsx` with a shell that renders the right panel for the active tab.

**Files:**
- Create: `frontend/src/admin/pages/LookupManagement.jsx`
- Create: `frontend/src/admin/styles/lookup_management.css`
- Modify: `frontend/src/App.jsx` (lines 18, 117-118)
- Modify: `frontend/src/admin/components/Sidebar.jsx` (lines 22-23, 75)
- Modify: `frontend/src/admin/utils/navBundles.js` (line 15)
- Modify: `frontend/src/admin/utils/navConfigureMap.js` (line 19)
- Modify: `frontend/src/admin/utils/roles.js` (lines 80-81)
- Modify: `back-end/database/seeders/NavBundleSeeder.php` (line 35)
- Delete: `frontend/src/admin/pages/CategoryBrandManagement.jsx`
- Delete: `frontend/src/admin/styles/category_brand_management.css`

- [ ] **Step 1: Write the page**

```jsx
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import LookupPanel from '../components/LookupPanel';
import { LOOKUP_CONFIGS, LOOKUP_TABS, tabForLookup } from '../config/lookupConfigs';
import { batchAPI, categoriesAPI, unitsAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { getRoleKey, ROLES } from '../utils/roles';
import '../styles/dashboard_air.css';
import '../styles/lookup_management.css';

const formatMoney = (n) =>
  '₱' + (Number(n) || 0).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 0 });

const LookupManagement = () => {
  const { user, can } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();

  const roleKey = getRoleKey(user || {});
  const isAdmin = roleKey === ROLES.ADMIN;
  const allow = useCallback(
    (verb) => isAdmin || (typeof can === 'function' && can('lookup_management', verb)),
    [isAdmin, can],
  );

  const permissions = useMemo(
    () => ({
      canCreate: allow('create'),
      canUpdate: allow('update'),
      canArchive: allow('archive'),
    }),
    [allow],
  );

  // /admin/brands is kept as a deep link from the old nav. It opens the Brands
  // panel directly, replacing the scrollIntoView hack the old page used.
  const initialLookup = useMemo(() => {
    const params = new URLSearchParams(location.search);
    const requested = params.get('tab');
    if (requested && LOOKUP_CONFIGS[requested]) return requested;
    if (location.pathname === '/admin/brands') return 'brands';
    return 'categories';
  }, [location.search, location.pathname]);

  const [activeLookup, setActiveLookup] = useState(initialLookup);
  const [activeTab, setActiveTab] = useState(() => tabForLookup(initialLookup).key);

  const [categories, setCategories] = useState([]);
  const [units, setUnits] = useState([]);
  const [performance, setPerformance] = useState([]);
  const [showPerformance, setShowPerformance] = useState(false);
  const [loading, setLoading] = useState(true);

  // Shared reference data the panels need for their category / unit selects.
  useEffect(() => {
    let cancelled = false;

    const load = async () => {
      try {
        const res = await batchAPI.get({ include: ['categories', 'units'] });
        const d = res?.data || {};
        if (cancelled) return;
        setCategories(Array.isArray(d.categories?.data) ? d.categories.data : []);
        setUnits(Array.isArray(d.units?.data) ? d.units.data : []);
      } catch {
        try {
          const [c, u] = await Promise.all([categoriesAPI.getAll(), unitsAPI.getAll()]);
          if (cancelled) return;
          setCategories(Array.isArray(c?.data) ? c.data : []);
          setUnits(Array.isArray(u?.data) ? u.data : []);
        } catch {
          if (!cancelled) {
            setCategories([]);
            setUnits([]);
          }
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    load();
    return () => { cancelled = true; };
  }, []);

  useEffect(() => {
    if (activeLookup !== 'categories' || !showPerformance || performance.length > 0) return;
    categoriesAPI
      .getPerformance()
      .then((res) => setPerformance(Array.isArray(res?.data) ? res.data : []))
      .catch(() => setPerformance([]));
  }, [activeLookup, showPerformance, performance.length]);

  const selectLookup = (lookupKey) => {
    setActiveLookup(lookupKey);
    setActiveTab(tabForLookup(lookupKey).key);
    navigate(`/admin/categories?tab=${lookupKey}`, { replace: true });
  };

  const selectTab = (tabKey) => {
    const tab = LOOKUP_TABS.find((t) => t.key === tabKey);
    if (!tab) return;
    selectLookup(tab.lookups[0]);
  };

  const currentTab = LOOKUP_TABS.find((t) => t.key === activeTab) || LOOKUP_TABS[0];
  const config = LOOKUP_CONFIGS[activeLookup];

  return (
    <AdminLayout>
      <div className="ak-page lk-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Catalog · Classification</p>
              <h1 className="ak-title">Item Lookups</h1>
              <p className="ak-sub">
                Manage the values the item master offers for appliances and installation materials.
              </p>
            </div>
          </div>
        </header>

        <nav className="lk-tabs" aria-label="Lookup groups">
          {LOOKUP_TABS.map((tab) => (
            <button
              key={tab.key}
              type="button"
              className={`lk-tab ${activeTab === tab.key ? 'active' : ''}`}
              onClick={() => selectTab(tab.key)}
              aria-current={activeTab === tab.key ? 'page' : undefined}
            >
              {tab.label}
            </button>
          ))}
        </nav>

        <nav className="lk-subtabs" aria-label={`${currentTab.label} lookups`}>
          {currentTab.lookups.map((lookupKey) => (
            <button
              key={lookupKey}
              type="button"
              className={`lk-subtab ${activeLookup === lookupKey ? 'active' : ''}`}
              onClick={() => selectLookup(lookupKey)}
            >
              {LOOKUP_CONFIGS[lookupKey].label}
            </button>
          ))}
        </nav>

        {loading ? (
          <Loader />
        ) : (
          <>
            <LookupPanel
              key={activeLookup}
              config={config}
              categories={categories}
              units={units}
              permissions={permissions}
            />

            {activeLookup === 'categories' && (
              <section className="ak-card lk-performance">
                <button
                  type="button"
                  className="lk-performance-toggle"
                  onClick={() => setShowPerformance((v) => !v)}
                  aria-expanded={showPerformance}
                >
                  {showPerformance ? '▾' : '▸'} Category performance
                </button>

                {showPerformance && (
                  <div className="ak-table-wrap">
                    <table className="ak-table">
                      <thead>
                        <tr>
                          <th scope="col">Category</th>
                          <th scope="col" className="ak-th-num">Total items</th>
                          <th scope="col" className="ak-th-num">Total units</th>
                          <th scope="col" className="ak-th-num">Total value</th>
                        </tr>
                      </thead>
                      <tbody>
                        {performance.length === 0 ? (
                          <tr>
                            <td colSpan={4} className="ak-table-empty">No performance data yet.</td>
                          </tr>
                        ) : (
                          performance.map((row, i) => (
                            <tr key={row.category || i}>
                              <td>{row.category}</td>
                              <td className="ak-td-num">{row.total_items ?? 0}</td>
                              <td className="ak-td-num">{(row.total_units ?? 0).toLocaleString('en-PH')}</td>
                              <td className="ak-td-num">{formatMoney(row.total_value)}</td>
                            </tr>
                          ))
                        )}
                      </tbody>
                    </table>
                  </div>
                )}
              </section>
            )}
          </>
        )}
      </div>
    </AdminLayout>
  );
};

export default LookupManagement;
```

- [ ] **Step 2: Write the stylesheet**

```css
/* Item Lookups page. Layout only — colours, cards, buttons and tables come
   from the shared ak-* design system in airking_ds.css. */

.lk-tabs {
  display: flex;
  gap: 8px;
  margin: 16px 0 4px;
  border-bottom: 1px solid var(--ak-border, #e2e8f0);
}

.lk-tab {
  padding: 10px 18px;
  border: none;
  background: none;
  font-size: 14px;
  font-weight: 600;
  color: var(--ak-text-muted, #64748b);
  cursor: pointer;
  border-bottom: 2px solid transparent;
}

.lk-tab.active {
  color: var(--ak-accent, #0f766e);
  border-bottom-color: var(--ak-accent, #0f766e);
}

.lk-subtabs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin: 12px 0 16px;
}

.lk-subtab {
  padding: 6px 14px;
  border: 1px solid var(--ak-border, #e2e8f0);
  border-radius: 999px;
  background: var(--ak-surface, #fff);
  font-size: 13px;
  color: var(--ak-text-muted, #64748b);
  cursor: pointer;
}

.lk-subtab.active {
  background: var(--ak-accent, #0f766e);
  border-color: var(--ak-accent, #0f766e);
  color: #fff;
}

.lk-panel-head {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: flex-end;
  justify-content: space-between;
  margin-bottom: 16px;
}

.lk-panel-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}

.lk-search {
  min-width: 200px;
}

.lk-archived-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: var(--ak-text-muted, #64748b);
  white-space: nowrap;
}

.lk-form-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 14px;
}

.lk-form-group label {
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--ak-text-muted, #64748b);
}

.lk-form-hint {
  margin: 0;
  font-size: 12px;
  color: var(--ak-text-muted, #64748b);
}

.lk-form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 20px;
}

.lk-performance {
  margin-top: 20px;
}

.lk-performance-toggle {
  border: none;
  background: none;
  padding: 0;
  font-size: 14px;
  font-weight: 600;
  color: var(--ak-text, #0f172a);
  cursor: pointer;
}
```

- [ ] **Step 3: Repoint the routes**

In `frontend/src/App.jsx`, replace line 18:

```javascript
const LookupManagement = lazy(() => import('./admin/pages/LookupManagement'));
```

and lines 117-118:

```jsx
          <Route path="/admin/categories" element={<RoleRoute><LookupManagement /></RoleRoute>} />
          <Route path="/admin/brands" element={<RoleRoute><LookupManagement /></RoleRoute>} />
```

- [ ] **Step 4: Update the sidebar**

In `frontend/src/admin/components/Sidebar.jsx`, replace lines 22-23:

```javascript
  '/admin/categories': () => import('../pages/LookupManagement'),
  '/admin/brands': () => import('../pages/LookupManagement'),
```

and line 75:

```javascript
      { name: 'Item Lookups', path: '/admin/categories', icon: 'category' },
```

- [ ] **Step 5: Update the nav metadata**

In `frontend/src/admin/utils/navBundles.js`, line 15:

```javascript
  { name: 'Item Lookups', routePath: '/admin/categories',       required: ['lookup_management.read'] },
```

In `frontend/src/admin/utils/navConfigureMap.js`, line 19:

```javascript
  '/admin/categories':          ['lookup_management', 'category_management', 'brand_management'],
```

In `back-end/database/seeders/NavBundleSeeder.php`, line 35:

```php
            ['Item Lookups',         '/admin/categories',           'PROCUREMENT & PLANNING',  'category',           'lookup_management',       'read'],
```

`frontend/src/admin/utils/roles.js` lines 80-81 already list admin, inventory_analyst and auditor for both routes, which matches `lookup_management.read`. Leave them as they are.

- [ ] **Step 5b: Swap the entries in `PermissionCoverageTest::ALLOWED_NON_NAV`**

`back-end/tests/Feature/Permissions/PermissionCoverageTest.php` asserts every permission feature is reachable — either it backs a nav bundle, or it is listed in `ALLOWED_NON_NAV`. Changing the seeder row above moves the bundle's feature from `category_management` to `lookup_management`, which flips both features' status at once:

- **Remove** `'lookup_management'` from the list. Task 5 added it there precisely because it had no bundle yet; now it has one, so the entry is stale. (Leaving it would not fail — the check is a union — but the comment beside it would be a lie.)
- **Add** `'category_management'` to the list, with a comment noting it is now an extra of the Item Lookups page, exactly as `brand_management` already is. Without this the test **will** fail: `category_management` is still a real catalog feature but no longer backs any bundle.

Then confirm:

```bash
cd back-end
php artisan test --filter=PermissionCoverageTest
```

Expected: PASS. A failure naming `category_management` as an orphan means the addition was missed.

- [ ] **Step 6: Delete the old page and its stylesheet**

```bash
cd /c/XAMPP1/htdocs/Airking_Inventory
git rm frontend/src/admin/pages/CategoryBrandManagement.jsx
git rm frontend/src/admin/styles/category_brand_management.css
```

- [ ] **Step 7: Confirm nothing still imports the deleted files**

```bash
grep -rn "CategoryBrandManagement\|category_brand_management" frontend/src || echo "clean"
```

Expected: `clean`.

- [ ] **Step 8: Reseed the nav bundles and build**

```bash
cd back-end
php artisan db:seed --class=NavBundleSeeder
cd ../frontend
npx eslint src/admin/pages/LookupManagement.jsx src/admin/components/LookupPanel.jsx
npm run build
```

Expected: eslint silent; `Compiled successfully.` from the build.

- [ ] **Step 9: Commit**

```bash
cd /c/XAMPP1/htdocs/Airking_Inventory
git add frontend/src/admin/pages/LookupManagement.jsx \
        frontend/src/admin/styles/lookup_management.css \
        frontend/src/App.jsx \
        frontend/src/admin/components/Sidebar.jsx \
        frontend/src/admin/utils/navBundles.js \
        frontend/src/admin/utils/navConfigureMap.js \
        frontend/src/admin/pages/CategoryBrandManagement.jsx \
        frontend/src/admin/styles/category_brand_management.css \
        back-end/database/seeders/NavBundleSeeder.php
git commit -m "feat(lookups): replace categories/brands page with Item Lookups"
```

---

## Task 18: Strict capacity and variant selects in `ProductRegisterModal`

**Files:**
- Modify: `frontend/src/admin/components/ProductRegisterModal.jsx`

- [ ] **Step 1: Delete the hardcoded list and load the real ones**

Remove line 18 entirely:

```javascript
const APPLIANCE_CAPACITY_RATINGS = ['1HP', '1.5HP', '2HP', '2.5HP', '3HP', '5HP'];
```

Add state beside the existing lookup state (after line 98):

```javascript
  const [capacities, setCapacities] = useState([]);
  const [variants, setVariants] = useState([]);
```

Extend the batch call in `loadLookups` (line 109) and its fallback:

```javascript
    batchAPI.get({ include: ['categories', 'brands', 'units', 'capacities', 'variants'] }).then((res) => {
      const d = res?.data || {};
      setCategories(Array.isArray(d.categories?.data) ? d.categories.data : []);
      setBrands(Array.isArray(d.brands?.data) ? d.brands.data : []);
      setUnits(Array.isArray(d.units?.data) ? d.units.data : []);
      setCapacities(Array.isArray(d.capacities?.data) ? d.capacities.data : []);
      setVariants(Array.isArray(d.variants?.data) ? d.variants.data : []);
    }).catch(async () => {
      try {
        const [c, b, u, cap, v] = await Promise.all([
          categoriesAPI.getAll(),
          brandsAPI.getAll(),
          unitsAPI.getAll(),
          capacitiesAPI.getAll(),
          variantsAPI.getAll(),
        ]);
        setCategories(Array.isArray(c?.data) ? c.data : []);
        setBrands(Array.isArray(b?.data) ? b.data : []);
        setUnits(Array.isArray(u?.data) ? u.data : []);
        setCapacities(Array.isArray(cap?.data) ? cap.data : []);
        setVariants(Array.isArray(v?.data) ? v.data : []);
      } catch {
        /* ignore */
      }
    });
```

Add the imports to the existing `services/api` import at the top of the file:

```javascript
  capacitiesAPI,
  variantsAPI,
```

- [ ] **Step 2: Add the category filter and the archived-value guard**

Add after the `packagingUnits` memo (around line 168):

```javascript
  /**
   * A lookup value applies when it belongs to the selected category or to no
   * category at all. An archived value already on the item is appended so
   * opening an existing item never silently blanks its capacity or variant.
   */
  const optionsFor = useCallback((rows, currentValue, valueKey) => {
    const categoryId = app.category_id;
    const applicable = rows.filter(
      (row) => !row.category_id || String(row.category_id) === String(categoryId),
    );
    const values = applicable.map((row) => row[valueKey]);

    if (currentValue && !values.includes(currentValue)) {
      return [...values.map((v) => ({ value: v, label: v })), { value: currentValue, label: `${currentValue} (archived)` }];
    }
    return values.map((v) => ({ value: v, label: v }));
  }, [app.category_id]);

  const capacityOptions = useMemo(
    () => optionsFor(capacities, app.capacity_rating, 'capacity_value'),
    [optionsFor, capacities, app.capacity_rating],
  );

  const variantOptions = useMemo(
    () => optionsFor(variants, app.variant, 'variant_name'),
    [optionsFor, variants, app.variant],
  );

  /** Changing category invalidates values that belonged to the old one. */
  useEffect(() => {
    if (!app.category_id) return;
    setApp((a) => {
      const capacityOk = !a.capacity_rating
        || capacities.some((c) => c[('capacity_value')] === a.capacity_rating
          && (!c.category_id || String(c.category_id) === String(a.category_id)));
      const variantOk = !a.variant
        || variants.some((v) => v.variant_name === a.variant
          && (!v.category_id || String(v.category_id) === String(a.category_id)));

      if (capacityOk && variantOk) return a;

      toast.info('Capacity and variant were cleared because they do not apply to the new category.');
      return {
        ...a,
        capacity_rating: capacityOk ? a.capacity_rating : '',
        variant: variantOk ? a.variant : '',
      };
    });
  }, [app.category_id, capacities, variants]);
```

Make sure `useCallback` and `useMemo` are in the React import at the top of the file.

- [ ] **Step 3: Replace the capacity and variant inputs**

Replace the whole block at lines 459-510 (the `showApplianceModelCode` ternary for capacity, plus the variant field) with one consistent pair of selects:

```jsx
            {showApplianceModelCode && (
              <div className="im-form-field">
                <label>MODEL CODE</label>
                <input
                  type="text"
                  value={app.model_code}
                  onChange={(e) => setApp((a) => ({ ...a, model_code: e.target.value }))}
                  placeholder="e.g. AMNB12AAJLH5"
                />
              </div>
            )}
            <div className="im-form-field">
              <label>CAPACITY</label>
              <select
                value={app.capacity_rating}
                onChange={(e) => setApp((a) => ({ ...a, capacity_rating: e.target.value }))}
              >
                <option value="">Select capacity (optional)</option>
                {capacityOptions.map((opt) => (
                  <option key={opt.value} value={opt.value}>{opt.label}</option>
                ))}
              </select>
            </div>
            <div className="im-form-field">
              <label>VARIANT</label>
              <select
                value={app.variant}
                onChange={(e) => setApp((a) => ({ ...a, variant: e.target.value }))}
              >
                <option value="">Select variant (optional)</option>
                {variantOptions.map((opt) => (
                  <option key={opt.value} value={opt.value}>{opt.label}</option>
                ))}
              </select>
            </div>
```

This also removes the old inconsistency where capacity was a dropdown in one branch and a free-text input in the other.

- [ ] **Step 4: Lint and build**

```bash
cd frontend
npx eslint src/admin/components/ProductRegisterModal.jsx
npm run build
```

Expected: eslint silent; `Compiled successfully.`

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/ProductRegisterModal.jsx
git commit -m "feat(lookups): drive capacity and variant from lookups in register modal"
```

---

## Task 19: Strict capacity and variant selects in `ItemManagement`

The edit form has the same hardcoded capacity list as the register modal, plus a free-text variant input.

**Files:**
- Modify: `frontend/src/admin/pages/ItemManagement.jsx`

- [ ] **Step 1: Load the lookups**

Add to the imports from `../services/api`:

```javascript
  capacitiesAPI,
  variantsAPI,
```

Add state beside `issuableUnits` (line 50):

```javascript
  const [capacities, setCapacities] = useState([]);
  const [variants, setVariants] = useState([]);
```

Add a loader effect near the other top-level fetches:

```javascript
  useEffect(() => {
    Promise.all([capacitiesAPI.getAll(), variantsAPI.getAll()])
      .then(([cap, v]) => {
        setCapacities(Array.isArray(cap?.data) ? cap.data : []);
        setVariants(Array.isArray(v?.data) ? v.data : []);
      })
      .catch(() => {
        setCapacities([]);
        setVariants([]);
      });
  }, []);
```

- [ ] **Step 2: Add the option builders**

Add near the other derived values, above the return:

```javascript
  /**
   * Values for the selected category plus the uncategorised ones. A value the
   * item already holds is appended even when archived — without this, opening
   * an item whose capacity was archived resets the select to blank and saving
   * wipes the value.
   */
  const lookupOptions = useCallback((rows, currentValue, valueKey) => {
    const applicable = rows.filter(
      (row) => !row.category_id || String(row.category_id) === String(formData.category_id),
    );
    const values = applicable.map((row) => row[valueKey]);
    const options = values.map((v) => ({ value: v, label: v }));

    if (currentValue && !values.includes(currentValue)) {
      options.push({ value: currentValue, label: `${currentValue} (archived)` });
    }
    return options;
  }, [formData.category_id]);

  const capacityOptions = useMemo(
    () => lookupOptions(capacities, formData.capacity_rating, 'capacity_value'),
    [lookupOptions, capacities, formData.capacity_rating],
  );

  const variantOptions = useMemo(
    () => lookupOptions(variants, formData.variant, 'variant_name'),
    [lookupOptions, variants, formData.variant],
  );
```

Ensure `useCallback` and `useMemo` are imported from React at the top of the file.

- [ ] **Step 3: Clear values that no longer apply when the category changes**

Same behaviour as the register modal, so the two forms do not disagree. Add after the option builders:

```javascript
  /**
   * Switching category can invalidate the current capacity or variant. Clear
   * them explicitly with a toast rather than leaving a value that the select
   * no longer offers.
   */
  useEffect(() => {
    if (!formData.category_id) return;

    const stillApplies = (rows, value, valueKey) =>
      !value || rows.some(
        (row) => row[valueKey] === value
          && (!row.category_id || String(row.category_id) === String(formData.category_id)),
      );

    const capacityOk = stillApplies(capacities, formData.capacity_rating, 'capacity_value');
    const variantOk = stillApplies(variants, formData.variant, 'variant_name');

    if (capacityOk && variantOk) return;

    toast.info('Capacity and variant were cleared because they do not apply to the new category.');
    setFormData((prev) => ({
      ...prev,
      capacity_rating: capacityOk ? prev.capacity_rating : '',
      variant: variantOk ? prev.variant : '',
    }));
  }, [formData.category_id, formData.capacity_rating, formData.variant, capacities, variants]);
```

- [ ] **Step 4: Replace the hardcoded capacity select**

Replace lines 1107-1121 (the `CAPACITY RATING` field with its six inline `<option>` tags) with:

```jsx
                  <div className="im-form-field">
                    <label>CAPACITY RATING</label>
                    <select
                      value={formData.capacity_rating}
                      onChange={(e) => setFormData({ ...formData, capacity_rating: e.target.value })}
                    >
                      <option value="">Select Capacity (optional)</option>
                      {capacityOptions.map((opt) => (
                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                      ))}
                    </select>
                  </div>
```

- [ ] **Step 5: Replace the free-text variant input**

Replace the variant `<input>` at lines 1186-1190 with:

```jsx
                    <select
                      value={formData.variant}
                      onChange={(e) => setFormData({ ...formData, variant: e.target.value })}
                    >
                      <option value="">Select Variant (optional)</option>
                      {variantOptions.map((opt) => (
                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                      ))}
                    </select>
```

The existing capacity-required validation at line 451 (`if (isAppliance && isCapacityRequired() && !formData.capacity_rating)`) keeps working unchanged — it only checks that a value is present.

- [ ] **Step 6: Lint and build**

```bash
cd frontend
npx eslint src/admin/pages/ItemManagement.jsx
npm run build
```

Expected: eslint silent; `Compiled successfully.`

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/pages/ItemManagement.jsx
git commit -m "feat(lookups): drive capacity and variant from lookups in item edit form"
```

---

## Task 20: Issuable unit preset picker

Adding an issuable unit to a product currently starts from a blank row. It should be able to start from a preset.

**Files:**
- Modify: `frontend/src/admin/pages/ItemManagement.jsx`

- [ ] **Step 1: Load the presets**

Add to the imports from `../services/api`:

```javascript
  issuableUnitPresetsAPI,
```

Add state beside `capacities` and `variants`:

```javascript
  const [issuablePresets, setIssuablePresets] = useState([]);
```

Extend the loader effect from Task 19 Step 1:

```javascript
  useEffect(() => {
    Promise.all([capacitiesAPI.getAll(), variantsAPI.getAll(), issuableUnitPresetsAPI.getAll()])
      .then(([cap, v, presets]) => {
        setCapacities(Array.isArray(cap?.data) ? cap.data : []);
        setVariants(Array.isArray(v?.data) ? v.data : []);
        setIssuablePresets(Array.isArray(presets?.data) ? presets.data : []);
      })
      .catch(() => {
        setCapacities([]);
        setVariants([]);
        setIssuablePresets([]);
      });
  }, []);
```

- [ ] **Step 2: Add a preset-seeded row builder**

Add directly after `handleAddIssuableUnit` (line 411):

```javascript
  /**
   * Seeds a new issuable-unit row from a preset. The values are copied, not
   * linked: the product's own factor stays editable and later preset changes
   * do not reach back into products already configured.
   */
  const handleAddIssuableUnitFromPreset = (presetId) => {
    const preset = issuablePresets.find(p => String(p.preset_id) === String(presetId));
    if (!preset) return;

    setIssuableUnits(prev => [...prev, {
      issuable_unit_id:     null,
      unit_id:              preset.unit_id,
      label:                preset.label,
      conversion_factor:    preset.default_conversion_factor ?? 1,
      is_whole_package:     Boolean(preset.is_whole_package),
      show_as_quick_action: true,
      sort_order:           prev.length,
    }]);
  };
```

- [ ] **Step 3: Add the picker next to the Add button**

In the issuable units header (around line 1463), add a select immediately before the existing `issuable-add-btn` button:

```jsx
                      {issuablePresets.length > 0 && (
                        <select
                          className="issuable-preset-picker"
                          value=""
                          onChange={(e) => {
                            if (e.target.value) handleAddIssuableUnitFromPreset(e.target.value);
                            e.target.value = '';
                          }}
                        >
                          <option value="">+ Add from preset…</option>
                          {issuablePresets.map((p) => (
                            <option key={p.preset_id} value={p.preset_id}>
                              {p.label} (×{p.default_conversion_factor})
                            </option>
                          ))}
                        </select>
                      )}
```

The existing blank-row "Add" button stays — a preset is a shortcut, not the only path.

- [ ] **Step 4: Lint and build**

```bash
cd frontend
npx eslint src/admin/pages/ItemManagement.jsx
npm run build
```

Expected: eslint silent; `Compiled successfully.`

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/pages/ItemManagement.jsx
git commit -m "feat(lookups): add issuable unit preset picker to item form"
```

---

## Task 21: Full verification

No React test setup exists in this repo, so the frontend gets a scripted manual pass. Do not skip it — the archived-value guard and the category-clearing behaviour are the two places where a mistake destroys data.

- [ ] **Step 1: Run the full backend suite**

```bash
cd back-end
php artisan test
```

Expected: the ~40 new tests from this plan all passing, and **only** the pre-existing failures described in the environment notes — the 9 deterministic `WarrantyClaimTest` ones, plus possibly the flaky `SerialRowMovementColumnsTest` case. So 9 or 10 failures total, and the pass count up by roughly the number of tests this plan added.

Any failure outside those two classes is a regression introduced by this work — investigate it rather than shipping. If `SerialRowMovementColumnsTest` fails, re-run it in isolation a couple of times before concluding anything; it passes intermittently.

- [ ] **Step 2: Build the frontend**

```bash
cd ../frontend
npm run build
```

Expected: `Compiled successfully.` Warnings are acceptable; errors are not.

- [ ] **Step 3: Start the app in production mode**

```bash
npm run demo
```

The CRA dev server is materially slower than the production build; use `demo` so the manual pass reflects what a user sees.

- [ ] **Step 4: Walk the page as an admin**

Sign in as an admin and confirm each of these:

1. The sidebar shows **Item Lookups** (not "Categories and Brands") and it opens `/admin/categories`.
2. Two tabs appear: **Appliance Lookup** and **Installation Materials Lookup**.
3. The Appliance tab shows four sub-tabs: Categories, Brands, Variants, Capacities. The Materials tab shows two: Units of Measure, Issuable Unit Presets.
4. Each sub-tab lists rows with a **Used by** count, and switching sub-tabs updates the URL's `?tab=` parameter.
5. Visiting `/admin/brands` directly opens the Brands panel.
6. Add, edit and archive a value in each of the six lookups.
7. Archiving a value that is in use shows the usage count in the confirmation.
8. "Show archived" lists archived rows and Restore brings one back.
9. Category Performance appears as a collapsed panel under the Categories sub-tab and expands with data.

- [ ] **Step 5: Verify the rename cascade in the UI**

1. Note a capacity value that reports a non-zero **Used by** count.
2. Rename it (e.g. `1.5HP` → `1.5 HP`).
3. Confirm the success toast reports the number of items updated.
4. Open Item Master and confirm an affected item now shows the new value.

- [ ] **Step 6: Verify merge**

1. Create a capacity deliberately duplicating an existing one's spelling.
2. Rename it to exactly match the existing value.
3. Confirm the merge prompt appears, accept it, and confirm the source row is gone from the active list while the target's usage count grew.

- [ ] **Step 7: Verify the archived-value guard — the data-loss case**

1. Pick an item with a capacity value, note the value.
2. Archive that capacity on the Item Lookups page.
3. Reopen the item in Item Master.
4. **The capacity select must still show the value, labelled "(archived)".** If it shows blank, stop — saving would wipe the value, and the `lookupOptions` guard from Task 19 is not working.
5. Save the item without touching capacity and confirm the value is unchanged.

- [ ] **Step 8: Verify category-driven filtering**

1. In Item Master, start registering an appliance and pick an Air Conditioning category.
2. Confirm the capacity dropdown offers aircon capacities and uncategorised values, but not another category's.
3. Change the category and confirm capacity and variant clear with a toast explaining why.

- [ ] **Step 9: Verify the preset picker**

1. Create an issuable unit preset (e.g. label `Roll`, base unit `Piece`, factor `50`).
2. Edit a consumable item and use "+ Add from preset…".
3. Confirm the row prefills with the label, unit and factor, and that editing the row's factor does not change the preset.

- [ ] **Step 10: Verify permission degradation**

Sign in as an **auditor** and confirm the Item Lookups page loads read-only: lists visible, no Add / Edit / Archive buttons. Then sign in as **warehouse personnel** and confirm the nav item is absent and `/admin/categories` is not reachable.

- [ ] **Step 11: Final commit**

```bash
cd /c/XAMPP1/htdocs/Airking_Inventory
git status
```

Expected: clean. If the manual pass required fixes, commit them with a descriptive message rather than amending earlier task commits.

---

## Notes for whoever executes this

**Two behaviours that look like bugs but are deliberate:**

1. **The backfill creates case-variant duplicates** (`1.5HP` and `1.5hp` both become rows), while the create form rejects a new value differing only in case. This is intentional: products reference the exact string, so collapsing them during backfill would orphan whichever spelling lost. Merge is the cleanup path. Do not "fix" the backfill to be case-insensitive.

2. **Renaming an issuable unit preset does not change any product.** Presets are templates; `product_issuable_units.label` is owned by the product. Only capacity and variant cascade.

**The single highest-risk change** is the strict dropdown in the two item forms. If Task 12's backfill missed a value in use, item registration breaks for that value. Step 7 of Task 12 is the check that catches it — do not skip it.
