# Aircon AC Type and Indoor/Outdoor Pairing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the catalogue record what kind of air conditioner a product is, and model a split aircon as an indoor product plus an outdoor product grouped into a named aircon model.

**Architecture:** A new `ac_type_lookup` and two new columns on `appliance_details` describe a single unit. A new `aircon_models` / `aircon_model_units` pair groups ordinary `products` rows into a named model, so each side keeps its own price, serials, and stock count. Purchase order, issuance, and receiving detail rows carry a nullable `aircon_model_id` and render as a group whose header quantity is derived from the lines, never stored.

**Tech Stack:** Laravel 11 (PHP 8.2), MySQL, PHPUnit with `DatabaseTransactions`, React 18 with Create React App, `react-scripts test` (Jest + React Testing Library).

**Spec:** `docs/superpowers/specs/2026-08-25-aircon-product-details-design.md`

---

## Before you start

**Run the backend test suite once and confirm it is green.** If it is already red, stop and report — you cannot tell your own breakage from pre-existing breakage otherwise.

```bash
cd back-end && php artisan test
```

The test database is `airkingV5_test` (see `back-end/phpunit.xml`). It must be migrated and seeded — `LookupTestCase::actingAsRole()` asserts the roles exist and fails with "role missing - seed the test database" if not.

**Never run `php artisan migrate --env=testing`.** There is no `.env.testing`
file in this project. Laravel falls back to `.env`, whose `DB_DATABASE` is
`airkingV5` — the **live** database with real serials, stock, issuances and
purchase orders in it. `--env=testing` would migrate production.

`phpunit.xml` points the *test process* at `airkingV5_test` through its own
`<env>` block, which is why `php artisan test` targets the right database while
a bare `php artisan migrate` does not. To migrate or seed the test database,
override the variable on the command itself:

```bash
DB_DATABASE=airkingV5_test php artisan migrate
```

Every migrate and seed step in this plan is already written that way. Do not
"simplify" one back to `--env=testing`.

**Verify the live data before Task 5.** The spec flags this: the dump read during design had the 208 products but no rows in `item_serial`, `inventory`, `issuance_details`, or `purchase_order_details`. Task 5 backfills 208 product rows. Run this first and record the answer in the commit message:

```bash
cd back-end && php artisan tinker --execute="echo json_encode(['serials'=>DB::table('item_serial')->count(),'inventory'=>DB::table('inventory')->count(),'issuance'=>DB::table('issuance_details')->count(),'po'=>DB::table('purchase_order_details')->count()]);"
```

---

## File Structure

**Phase 1 — one unit's identity** (ships and is useful alone: aircons become filterable by kind)

| File | Responsibility |
| --- | --- |
| `back-end/database/migrations/2026_08_25_000001_create_ac_type_lookup_table.php` | Create + seed the six AC types |
| `back-end/app/Models/AcTypeLookup.php` | The lookup model |
| `back-end/app/Http/Controllers/Api/AcTypeLookupController.php` | CRUD via `HandlesLookupCrud` |
| `back-end/database/migrations/2026_08_25_000002_add_ac_fields_to_appliance_details.php` | `ac_type_id` + `unit_role` |
| `back-end/database/migrations/2026_08_25_000003_backfill_ac_types_from_descriptions.php` | Data backfill |
| `frontend/src/admin/config/lookupConfigs.js` | New `acTypes` panel entry |
| `frontend/src/admin/components/ProductRegisterModal.jsx` | AC Type + Unit Role fields |

**Phase 2 — the model that groups units** (depends on Phase 1)

| File | Responsibility |
| --- | --- |
| `back-end/database/migrations/2026_08_25_000004_create_aircon_models_tables.php` | Both new tables |
| `back-end/app/Models/AirconModel.php`, `AirconModelUnit.php` | The entity and its lines |
| `back-end/app/Services/AirconModelComposer.php` | Composition rules — the `is_two_piece` invariant, line syncing |
| `back-end/app/Http/Controllers/Api/AirconModelController.php` | CRUD, thin over the composer |
| `frontend/src/admin/pages/AirconModelsPage.jsx`, `components/AirconModelBuilderModal.jsx` | Management screen |

**Phase 3 — models on documents** (depends on Phase 2)

| File | Responsibility |
| --- | --- |
| `back-end/database/migrations/2026_08_25_000005_add_aircon_model_id_to_document_details.php` | Three nullable FK columns |
| `back-end/app/Services/AirconLineGrouper.php` | Derived header quantity + imbalance — the one place that logic lives |
| `back-end/app/Http/Controllers/Api/AirconConversionController.php` | Convert a legacy single-row aircon into a pair |

`AirconLineGrouper` is deliberately a service, not a method on three controllers. The derived-quantity and imbalance rules are the subtlest part of this feature and must have exactly one implementation.

---

# Phase 1 — One unit's identity

## Task 1: The `ac_type_lookup` table and model

**Files:**
- Create: `back-end/database/migrations/2026_08_25_000001_create_ac_type_lookup_table.php`
- Create: `back-end/app/Models/AcTypeLookup.php`
- Test: `back-end/tests/Feature/Lookups/AcTypeLookupTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Lookups/AcTypeLookupTest.php`:

```php
<?php

namespace Tests\Feature\Lookups;

use App\Models\AcTypeLookup;

/**
 * AC-type-specific coverage. HandlesLookupCrud itself is already proven by
 * LookupCrudTraitTest against capacity; this covers only what differs —
 * the is_two_piece flag and the fact that this lookup is a real foreign key
 * and therefore has no rename cascade.
 */
class AcTypeLookupTest extends LookupTestCase
{
    public function test_the_six_standard_types_are_seeded(): void
    {
        $names = AcTypeLookup::pluck('ac_type_name')->all();

        foreach (['Split Type', 'Window Type', 'Floor Standing', 'Cassette', 'Ducted', 'Portable'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function test_split_type_defaults_to_two_piece_and_window_type_does_not(): void
    {
        $this->assertTrue((bool) AcTypeLookup::where('ac_type_name', 'Split Type')->value('is_two_piece'));
        $this->assertFalse((bool) AcTypeLookup::where('ac_type_name', 'Window Type')->value('is_two_piece'));
    }

    public function test_is_two_piece_is_cast_to_a_boolean(): void
    {
        $row = AcTypeLookup::create([
            'ac_type_name' => 'T' . substr(uniqid(), -8),
            'is_two_piece' => 1,
        ]);

        $this->assertIsBool($row->fresh()->is_two_piece);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=AcTypeLookupTest
```

Expected: FAIL — `Class "App\Models\AcTypeLookup" not found`.

- [ ] **Step 3: Write the migration**

Create `back-end/database/migrations/2026_08_25_000001_create_ac_type_lookup_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The kinds of air conditioner, e.g. Split Type or Window Type.
     *
     * is_two_piece is the DEFAULT piece count for the type, not a rule — a
     * product registered under a two-piece type may still be recorded as a
     * complete unit, and aircon_models carries its own override. See the
     * design spec, decision 6.
     */
    public function up(): void
    {
        Schema::create('ac_type_lookup', function (Blueprint $table) {
            $table->bigIncrements('ac_type_id');
            $table->string('ac_type_name', 60);
            $table->boolean('is_two_piece')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Matches lookupUniqueRule(), which scopes uniqueness to
            // non-archived rows; a plain unique index would block re-using
            // the name of an archived type.
            $table->index('ac_type_name');
        });

        $now = now();

        DB::table('ac_type_lookup')->insert([
            ['ac_type_name' => 'Split Type',     'is_two_piece' => true,  'created_at' => $now, 'updated_at' => $now],
            ['ac_type_name' => 'Window Type',    'is_two_piece' => false, 'created_at' => $now, 'updated_at' => $now],
            ['ac_type_name' => 'Floor Standing', 'is_two_piece' => true,  'created_at' => $now, 'updated_at' => $now],
            ['ac_type_name' => 'Cassette',       'is_two_piece' => true,  'created_at' => $now, 'updated_at' => $now],
            ['ac_type_name' => 'Ducted',         'is_two_piece' => true,  'created_at' => $now, 'updated_at' => $now],
            ['ac_type_name' => 'Portable',       'is_two_piece' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ac_type_lookup');
    }
};
```

- [ ] **Step 4: Write the model**

Create `back-end/app/Models/AcTypeLookup.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcTypeLookup extends Model
{
    use SoftDeletes;

    protected $table = 'ac_type_lookup';
    protected $primaryKey = 'ac_type_id';

    protected $fillable = [
        'ac_type_name',
        'is_two_piece',
    ];

    protected $casts = [
        'is_two_piece' => 'boolean',
    ];
}
```

- [ ] **Step 5: Migrate the test database and run the test**

```bash
cd back-end && DB_DATABASE=airkingV5_test php artisan migrate && php artisan test --filter=AcTypeLookupTest
```

Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```bash
git add back-end/database/migrations/2026_08_25_000001_create_ac_type_lookup_table.php back-end/app/Models/AcTypeLookup.php back-end/tests/Feature/Lookups/AcTypeLookupTest.php
git commit -m "feat(catalogue): add the AC type lookup"
```

---

## Task 2: The AC type CRUD endpoints

**Files:**
- Create: `back-end/app/Http/Controllers/Api/AcTypeLookupController.php`
- Modify: `back-end/routes/api.php` (after the variants block, around line 390)
- Test: `back-end/tests/Feature/Lookups/AcTypeLookupTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append these methods to `back-end/tests/Feature/Lookups/AcTypeLookupTest.php`:

```php
    public function test_create_and_list_an_ac_type(): void
    {
        $this->actingAsAdmin();

        $name = 'T' . substr(uniqid(), -8);

        $this->postJson('/api/ac-types', ['ac_type_name' => $name, 'is_two_piece' => true])
            ->assertStatus(201)
            ->assertJsonPath('data.ac_type_name', $name);

        $list = $this->getJson('/api/ac-types')->json('data');

        $this->assertNotNull(collect($list)->firstWhere('ac_type_name', $name));
    }

    public function test_usage_count_reports_products_carrying_the_type(): void
    {
        $this->actingAsAdmin();

        $type = AcTypeLookup::create(['ac_type_name' => 'T' . substr(uniqid(), -8), 'is_two_piece' => true]);

        $this->applianceWith(['ac_type_id' => $type->ac_type_id]);
        $this->applianceWith(['ac_type_id' => $type->ac_type_id]);

        $row = $this->getJson("/api/ac-types/{$type->ac_type_id}")->json('data');

        $this->assertSame(2, $row['usage_count']);
    }

    public function test_a_type_in_use_cannot_be_force_deleted(): void
    {
        $this->actingAsAdmin();

        $type = AcTypeLookup::create(['ac_type_name' => 'T' . substr(uniqid(), -8), 'is_two_piece' => true]);
        $this->applianceWith(['ac_type_id' => $type->ac_type_id]);

        $this->deleteJson("/api/ac-types/{$type->ac_type_id}")->assertStatus(200);

        $this->deleteJson("/api/ac-types/{$type->ac_type_id}/force")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete: used by 1 items');
    }

    public function test_merging_is_not_supported(): void
    {
        $this->actingAsAdmin();

        $a = AcTypeLookup::create(['ac_type_name' => 'T' . substr(uniqid(), -8), 'is_two_piece' => true]);
        $b = AcTypeLookup::create(['ac_type_name' => 'T' . substr(uniqid(), -8), 'is_two_piece' => true]);

        $this->postJson("/api/ac-types/{$a->ac_type_id}/merge", ['target_id' => $b->ac_type_id])
            ->assertStatus(404);
    }
```

> `test_merging_is_not_supported` expects **404**, not 422: no merge route is registered for this lookup at all, because merge exists only for the string-valued lookups that need `cascadeValueChange()`. A foreign key needs no merge — repointing is a single UPDATE the caller can do directly.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd back-end && php artisan test --filter=AcTypeLookupTest
```

Expected: FAIL — the three endpoint tests 404 because no route exists (and `applianceWith(['ac_type_id' => ...])` fails until Task 3 adds the column). Task 3 is what makes them green; write the controller now and expect these four to stay red until then.

- [ ] **Step 3: Write the controller**

Create `back-end/app/Http/Controllers/Api/AcTypeLookupController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Models\AcTypeLookup;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Unlike capacity and variant, an AC type is referenced by foreign key rather
 * than copied onto appliance_details as a string. That removes the whole
 * rename-cascade problem: renaming a type is a single-row update and cannot
 * drift from the products naming it. Hence no cascadeTargets(),
 * no cascadeValueChange(), and supportsMerge() left at its false default —
 * merging exists to fold two spellings of the same string together, which
 * cannot happen here.
 */
class AcTypeLookupController extends Controller
{
    use ApiResponse;
    use HandlesLookupCrud;

    protected function lookupModel(): string
    {
        return AcTypeLookup::class;
    }

    protected function lookupNameColumn(): string
    {
        return 'ac_type_name';
    }

    protected function lookupLabel(): string
    {
        return 'AC Type';
    }

    protected function lookupRules(Request $request, $id = null): array
    {
        return [
            // max:60 matches ac_type_lookup.ac_type_name's column width.
            'ac_type_name' => [
                $id === null ? 'required' : 'sometimes',
                'string',
                'max:60',
                $this->lookupUniqueRule($request, $id),
            ],
            'is_two_piece' => 'sometimes|boolean',
        ];
    }

    protected function usageCountFor(Model $row): int
    {
        return DB::table('appliance_details')
            ->where('ac_type_id', $row->ac_type_id)
            ->count();
    }
}
```

- [ ] **Step 4: Register the routes**

In `back-end/routes/api.php`, immediately after the `/variants/{id}/force` route (the end of the variants block, around line 390), add:

```php
    /* ---------------------------------------------------------------
     | AC Types (Item Lookups page - Appliance tab)
     | 'archived' precedes '{id}' so it is not swallowed by the wildcard.
     | Reads are deliberately ungated — see the comment above the
     | unit_lookup routes for why (ProductRegisterModal / BarcodeScan role
     | mismatch). ProductRegisterModal reads this dropdown, so gating it
     | would empty the picker for warehouse and branch users.
     |
     | No /merge route: merging folds two spellings of a string-valued
     | lookup together, and ac_type_id is a foreign key.
     * ------------------------------------------------------------- */
    Route::get('/ac-types/archived', [AcTypeLookupController::class, 'archived']);
    Route::get('/ac-types', [AcTypeLookupController::class, 'index']);
    Route::get('/ac-types/{id}', [AcTypeLookupController::class, 'show']);
    Route::middleware('permission:lookup_management,create')
        ->post('/ac-types', [AcTypeLookupController::class, 'store']);
    Route::middleware('permission:lookup_management,update')
        ->put('/ac-types/{id}', [AcTypeLookupController::class, 'update']);
    Route::middleware('permission:lookup_management,archive')
        ->delete('/ac-types/{id}', [AcTypeLookupController::class, 'destroy']);
    Route::middleware('permission:lookup_management,archive')
        ->post('/ac-types/{id}/restore', [AcTypeLookupController::class, 'restore']);
    Route::middleware('permission:lookup_management,archive')
        ->delete('/ac-types/{id}/force', [AcTypeLookupController::class, 'forceDestroy']);
```

Add the import at the top of `back-end/routes/api.php`, alongside the other lookup controller imports:

```php
use App\Http\Controllers\Api\AcTypeLookupController;
```

- [ ] **Step 5: Commit (tests still partly red — that is expected)**

```bash
git add back-end/app/Http/Controllers/Api/AcTypeLookupController.php back-end/routes/api.php back-end/tests/Feature/Lookups/AcTypeLookupTest.php
git commit -m "feat(catalogue): add AC type CRUD endpoints"
```

---

## Task 3: The `appliance_details` columns

**Files:**
- Create: `back-end/database/migrations/2026_08_25_000002_add_ac_fields_to_appliance_details.php`
- Modify: `back-end/app/Models/ApplianceDetail.php` (the `$fillable` array, line 17)
- Test: `back-end/tests/Feature/Lookups/AcTypeLookupTest.php` (already written in Task 2)

- [ ] **Step 1: Write the migration**

Create `back-end/database/migrations/2026_08_25_000002_add_ac_fields_to_appliance_details.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Both columns are nullable because appliance_details also holds
     * televisions, refrigerators, washing machines and water dispensers,
     * none of which have an AC type or an indoor/outdoor side.
     *
     * unit_role lives here and ONLY here — never on aircon_model_units.
     * A unit line points at a product and the product already knows which
     * side it is; storing the role in both places would give two sources of
     * truth that can disagree. It also means a lone outdoor unit sitting in
     * inventory with no model still reads correctly.
     */
    public function up(): void
    {
        Schema::table('appliance_details', function (Blueprint $table) {
            $table->unsignedBigInteger('ac_type_id')->nullable()->after('variant');
            $table->enum('unit_role', ['indoor', 'outdoor', 'complete'])->nullable()->after('ac_type_id');

            $table->foreign('ac_type_id')
                ->references('ac_type_id')
                ->on('ac_type_lookup')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appliance_details', function (Blueprint $table) {
            $table->dropForeign(['ac_type_id']);
            $table->dropColumn(['ac_type_id', 'unit_role']);
        });
    }
};
```

- [ ] **Step 2: Add the columns to the model**

In `back-end/app/Models/ApplianceDetail.php`, change the `$fillable` array so that `'variant',` is followed by the two new keys:

```php
    protected $fillable = [
        'product_id',
        'brand_id',
        'model_code',
        'capacity_rating',
        'variant',
        'ac_type_id',
        'unit_role',
        'color',
        'warranty_period_months',
        'warranty_value',
        'warranty_unit',
        'is_serialized',
    ];
```

Then add the relation, after the existing `brand()` method at the end of the class:

```php
    public function acType(): BelongsTo
    {
        return $this->belongsTo(AcTypeLookup::class, 'ac_type_id', 'ac_type_id');
    }
```

- [ ] **Step 3: Migrate and run the full AC type test**

```bash
cd back-end && DB_DATABASE=airkingV5_test php artisan migrate && php artisan test --filter=AcTypeLookupTest
```

Expected: PASS, 7 tests — the four endpoint tests from Task 2 now go green.

- [ ] **Step 4: Run the whole lookup suite to check nothing regressed**

```bash
cd back-end && php artisan test --filter=Lookups
```

Expected: PASS. Pay attention to `BatchIncludesTest` — if it asserts an exact set of batch includes, Task 6 will need to add `acTypes` to it.

- [ ] **Step 5: Commit**

```bash
git add back-end/database/migrations/2026_08_25_000002_add_ac_fields_to_appliance_details.php back-end/app/Models/ApplianceDetail.php
git commit -m "feat(catalogue): record AC type and unit role on appliance details"
```

---

## Task 4: Product create/update/read carry the new fields

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/ProductController.php` (store ~line 136-166, update ~line 221-247, the flattener ~line 415-422, index eager loads ~line 29)
- Test: `back-end/tests/Feature/Lookups/AcTypeProductFieldsTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Lookups/AcTypeProductFieldsTest.php`:

```php
<?php

namespace Tests\Feature\Lookups;

use App\Models\AcTypeLookup;
use App\Models\Product;

/**
 * ProductController must accept, persist and return the two new
 * appliance_details fields. Without the flattener change the fields save but
 * never come back, which reads to the frontend as "the save silently failed".
 */
class AcTypeProductFieldsTest extends LookupTestCase
{
    public function test_registering_an_appliance_stores_ac_type_and_unit_role(): void
    {
        $this->actingAsAdmin();

        $type = AcTypeLookup::where('ac_type_name', 'Split Type')->firstOrFail();
        $category = $this->category();

        $response = $this->postJson('/api/products', [
            'product_name' => 'Test Split ' . uniqid(),
            'product_code' => 'PC-' . uniqid(),
            'product_type' => 'appliance',
            'category_id'  => $category->category_id,
            'ac_type_id'   => $type->ac_type_id,
            'unit_role'    => 'indoor',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.ac_type_id', $type->ac_type_id);
        $response->assertJsonPath('data.unit_role', 'indoor');
    }

    public function test_updating_an_appliance_changes_the_unit_role(): void
    {
        $this->actingAsAdmin();

        $detail = $this->applianceWith(['unit_role' => 'indoor']);

        $response = $this->putJson("/api/products/{$detail->product_id}", [
            'unit_role' => 'outdoor',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.unit_role', 'outdoor');
    }

    public function test_an_invalid_unit_role_is_rejected(): void
    {
        $this->actingAsAdmin();

        $category = $this->category();

        $this->postJson('/api/products', [
            'product_name' => 'Bad Role ' . uniqid(),
            'product_code' => 'PC-' . uniqid(),
            'product_type' => 'appliance',
            'category_id'  => $category->category_id,
            'unit_role'    => 'sideways',
        ])->assertStatus(422);
    }

    public function test_a_non_appliance_ignores_the_ac_fields(): void
    {
        $this->actingAsAdmin();

        $type = AcTypeLookup::where('ac_type_name', 'Split Type')->firstOrFail();

        $response = $this->postJson('/api/products', [
            'product_name'  => 'Test Material ' . uniqid(),
            'product_code'  => 'PC-' . uniqid(),
            'product_type'  => 'installation_material',
            'ac_type_id'    => $type->ac_type_id,
            'unit_role'     => 'indoor',
        ]);

        $response->assertStatus(201);

        $product = Product::find($response->json('data.product_id'));

        $this->assertNull($product->applianceDetail);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=AcTypeProductFieldsTest
```

Expected: FAIL — `data.ac_type_id` is missing from the response.

- [ ] **Step 3: Accept the fields on create**

In `back-end/app/Http/Controllers/Api/ProductController.php`, in `store()`, add two rules to the validation array that currently contains `'category_id' => 'nullable|exists:category_lookup,category_id',` (around line 139):

```php
                'ac_type_id'         => 'nullable|exists:ac_type_lookup,ac_type_id',
                'unit_role'          => 'nullable|in:indoor,outdoor,complete',
```

Then in the same method's `if ($type === 'appliance')` block, alongside `'capacity_rating'` and `'variant'` (around line 165), add:

```php
                        'ac_type_id'             => $request->input('ac_type_id') ?: null,
                        'unit_role'              => $request->input('unit_role') ?: null,
```

- [ ] **Step 4: Accept the fields on update**

In `update()`, add the same two validation rules alongside `'category_id'` (around line 221):

```php
                'ac_type_id'         => 'nullable|exists:ac_type_lookup,ac_type_id',
                'unit_role'          => 'nullable|in:indoor,outdoor,complete',
```

and in the `if ($product->product_type === 'appliance')` block's `updateOrCreate` payload, alongside `'capacity_rating'` and `'variant'` (around line 246):

```php
                            'ac_type_id'             => $request->input('ac_type_id') ?: null,
                            'unit_role'              => $request->input('unit_role') ?: null,
```

- [ ] **Step 5: Return the fields**

In the private flattener that copies appliance detail fields onto the product (around line 415, the block containing `$p->setAttribute('capacity_rating', $ad->capacity_rating);`), add:

```php
            $p->setAttribute('ac_type_id',             $ad->ac_type_id);
            $p->setAttribute('unit_role',              $ad->unit_role);
            $p->setAttribute('ac_type_name',           $ad->acType?->ac_type_name);
```

Then extend the appliance eager-load so `acType` is present wherever `brand` is. Change every occurrence of:

```php
->with(['applianceDetail' => fn($q) => $q->with(['brand'])])
```

to:

```php
->with(['applianceDetail' => fn($q) => $q->with(['brand', 'acType'])])
```

There are four such occurrences (`index`, `show`, `store`, `update` — around lines 29, 77, 193, 276). Also update the two `applianceDetail.brand` eager-load strings (around lines 303 and 348) to add `'applianceDetail.acType'` as a sibling entry.

> Loading `acType` is not optional polish. `$ad->acType?->ac_type_name` on an unloaded relation fires one query per product, turning the product index into an N+1 across 208 rows.

- [ ] **Step 6: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=AcTypeProductFieldsTest
```

Expected: PASS, 4 tests.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/ProductController.php back-end/tests/Feature/Lookups/AcTypeProductFieldsTest.php
git commit -m "feat(catalogue): carry AC type and unit role through the product API"
```

---

## Task 5: Backfill the existing 208 appliances

**Files:**
- Create: `back-end/database/migrations/2026_08_25_000003_backfill_ac_types_from_descriptions.php`
- Test: `back-end/tests/Feature/Lookups/AcTypeBackfillTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Lookups/AcTypeBackfillTest.php`:

```php
<?php

namespace Tests\Feature\Lookups;

use App\Models\AcTypeLookup;
use App\Models\ApplianceDetail;
use App\Models\Product;
use App\Support\AcTypeBackfill;

/**
 * The backfill reads products.description, where the aircon kind has lived as
 * prose since the catalogue was seeded ("...split-type air conditioner.").
 *
 * A description matching no known type is left NULL on purpose. Guessing a
 * type for it would produce a confident wrong answer that nobody would ever
 * think to re-check, which is worse than an obviously empty field.
 */
class AcTypeBackfillTest extends LookupTestCase
{
    private function applianceWithDescription(?string $description): ApplianceDetail
    {
        $product = Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Backfill Test ' . uniqid(),
            'product_type' => 'appliance',
            'description'  => $description,
        ]);

        return ApplianceDetail::create([
            'product_id'    => $product->product_id,
            'is_serialized' => true,
        ]);
    }

    public function test_a_split_type_description_maps_to_split_type(): void
    {
        $detail = $this->applianceWithDescription('CHiQ Morandi Inverter 1HP split-type air conditioner. 3-year warranty.');

        AcTypeBackfill::run();

        $expected = AcTypeLookup::where('ac_type_name', 'Split Type')->value('ac_type_id');

        $this->assertSame($expected, $detail->fresh()->ac_type_id);
    }

    public function test_a_window_type_description_maps_to_window_type(): void
    {
        $detail = $this->applianceWithDescription('TCL Window Non-Inverter 0.6HP window-type air conditioner. 3-year warranty.');

        AcTypeBackfill::run();

        $expected = AcTypeLookup::where('ac_type_name', 'Window Type')->value('ac_type_id');

        $this->assertSame($expected, $detail->fresh()->ac_type_id);
    }

    public function test_an_unrecognised_description_is_left_null(): void
    {
        $detail = $this->applianceWithDescription('55-inch 4K smart television.');

        AcTypeBackfill::run();

        $this->assertNull($detail->fresh()->ac_type_id);
    }

    public function test_a_null_description_is_left_null(): void
    {
        $detail = $this->applianceWithDescription(null);

        AcTypeBackfill::run();

        $this->assertNull($detail->fresh()->ac_type_id);
    }

    public function test_every_appliance_gets_unit_role_complete(): void
    {
        $split = $this->applianceWithDescription('a split-type air conditioner');
        $tv    = $this->applianceWithDescription('55-inch 4K smart television.');

        AcTypeBackfill::run();

        $this->assertSame('complete', $split->fresh()->unit_role);
        $this->assertSame('complete', $tv->fresh()->unit_role);
    }

    public function test_an_already_set_unit_role_is_not_overwritten(): void
    {
        $detail = $this->applianceWithDescription('a split-type air conditioner');
        $detail->update(['unit_role' => 'indoor']);

        AcTypeBackfill::run();

        $this->assertSame('indoor', $detail->fresh()->unit_role);
    }

    public function test_running_twice_changes_nothing_the_second_time(): void
    {
        $detail = $this->applianceWithDescription('a split-type air conditioner');

        AcTypeBackfill::run();
        $first = $detail->fresh()->ac_type_id;

        AcTypeBackfill::run();

        $this->assertSame($first, $detail->fresh()->ac_type_id);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=AcTypeBackfillTest
```

Expected: FAIL — `Class "App\Support\AcTypeBackfill" not found`.

- [ ] **Step 3: Write the backfill class**

The logic lives in a class rather than inline in the migration so the test can call it directly — the same shape `LookupBackfill` already uses (see `back-end/tests/Feature/Lookups/BackfillTest.php`).

Create `back-end/app/Support/AcTypeBackfill.php`:

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Populates appliance_details.ac_type_id and .unit_role for rows that predate
 * those columns, reading products.description where the aircon kind has lived
 * as prose since the catalogue was seeded.
 *
 * Idempotent: only ever writes to rows where the target column is still NULL,
 * so a second run is a no-op and a hand-corrected row is never clobbered.
 */
class AcTypeBackfill
{
    /**
     * Description substring => ac_type_lookup name.
     *
     * Ordered longest-phrase-first so "floor standing" cannot be matched by a
     * shorter pattern first. Matching is case-insensitive.
     */
    private const PATTERNS = [
        'floor standing' => 'Floor Standing',
        'floor-standing' => 'Floor Standing',
        'split-type'     => 'Split Type',
        'split type'     => 'Split Type',
        'window-type'    => 'Window Type',
        'window type'    => 'Window Type',
        'cassette'       => 'Cassette',
        'ducted'         => 'Ducted',
        'portable'       => 'Portable',
    ];

    public static function run(): void
    {
        $typeIds = DB::table('ac_type_lookup')
            ->pluck('ac_type_id', 'ac_type_name')
            ->all();

        foreach (self::PATTERNS as $needle => $typeName) {
            if (! isset($typeIds[$typeName])) {
                continue;
            }

            DB::table('appliance_details')
                ->join('products', 'products.product_id', '=', 'appliance_details.product_id')
                ->whereNull('appliance_details.ac_type_id')
                ->whereRaw('LOWER(products.description) LIKE ?', ['%' . $needle . '%'])
                ->update(['appliance_details.ac_type_id' => $typeIds[$typeName]]);
        }

        // Every pre-existing appliance row describes a whole product, because
        // until now there was no way to record half of one.
        DB::table('appliance_details')
            ->whereNull('unit_role')
            ->update(['unit_role' => 'complete']);
    }
}
```

- [ ] **Step 4: Write the migration that calls it**

Create `back-end/database/migrations/2026_08_25_000003_backfill_ac_types_from_descriptions.php`:

```php
<?php

use App\Support\AcTypeBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        AcTypeBackfill::run();
    }

    /**
     * Deliberately does nothing.
     *
     * This migration cannot distinguish an ac_type_id it inferred from a
     * description from one a user has since set by hand, nor a unit_role of
     * 'complete' it wrote from one a user chose. Blanking both columns on
     * rollback would therefore discard real work to undo a guess.
     *
     * The columns themselves are dropped by
     * 2026_08_25_000002_add_ac_fields_to_appliance_details's down(), which is
     * the honest way to reverse this change.
     */
    public function down(): void
    {
    }
};
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
cd back-end && DB_DATABASE=airkingV5_test php artisan migrate && php artisan test --filter=AcTypeBackfillTest
```

Expected: PASS, 7 tests.

- [ ] **Step 6: Check what the backfill actually did to the real catalogue**

```bash
cd back-end && php artisan tinker --execute="DB::table('appliance_details')->leftJoin('ac_type_lookup','ac_type_lookup.ac_type_id','=','appliance_details.ac_type_id')->selectRaw('COALESCE(ac_type_name, \"(none)\") as t, COUNT(*) as n')->groupBy('t')->orderByDesc('n')->get()->each(fn(\$r) => print(\"{\$r->t}: {\$r->n}\n\"));"
```

Expected, from the design-time counts: roughly `Split Type: 117`, `Window Type: 23`, and the balance under `(none)` — televisions, refrigerators, washing machines, water dispensers, and any aircon whose description does not name its kind. **If `Split Type` and `Window Type` come back at 0, stop** — the description wording differs from what the patterns expect, and the patterns need widening before this ships.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Support/AcTypeBackfill.php back-end/database/migrations/2026_08_25_000003_backfill_ac_types_from_descriptions.php back-end/tests/Feature/Lookups/AcTypeBackfillTest.php
git commit -m "feat(catalogue): backfill AC types from product descriptions"
```

---

## Task 6: The AC Types panel on the Item Lookups page

**Files:**
- Modify: `frontend/src/admin/services/api.js` (after `variantsAPI`, ~line 1355)
- Modify: `frontend/src/admin/config/lookupConfigs.js` (import block ~line 48, configs object)

- [ ] **Step 1: Add the API client**

In `frontend/src/admin/services/api.js`, immediately after the `variantsAPI` export block, add:

```js
/* ======================
   AC TYPES API
====================== */
export const acTypesAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/ac-types?${query}` : '/ac-types');
  },
  getArchived: async () => apiRequest('/ac-types/archived'),
  create: async (data) =>
    apiRequest('/ac-types', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/ac-types/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) => apiRequest(`/ac-types/${id}`, { method: 'DELETE' }),
  restore: async (id) => apiRequest(`/ac-types/${id}/restore`, { method: 'POST' }),
  forceDelete: async (id) => apiRequest(`/ac-types/${id}/force`, { method: 'DELETE' }),
};
```

There is no `merge` method — the backend registers no merge route for this lookup.

- [ ] **Step 2: Add the lookup config**

In `frontend/src/admin/config/lookupConfigs.js`, add `acTypesAPI` to the import block at line 48:

```js
import {
  categoriesAPI,
  brandsAPI,
  unitsAPI,
  capacitiesAPI,
  variantsAPI,
  acTypesAPI,
} from '../services/api';
```

Then add this entry to `LOOKUP_CONFIGS`, after the `variants` entry:

```js
  acTypes: {
    label: 'AC Types',
    singular: 'AC Type',
    api: acTypesAPI,
    idField: 'ac_type_id',
    nameField: 'ac_type_name',
    supportsMerge: false,
    fields: [
      {
        name: 'ac_type_name',
        label: 'AC type name',
        type: 'text',
        required: true,
        maxLength: 60,
      },
      {
        // The first use of LookupPanel's 'checkbox' field type. It is the
        // default piece count for the type, not a rule — a product under a
        // two-piece type may still be registered as a complete unit.
        name: 'is_two_piece',
        label: 'Two-piece (indoor + outdoor)',
        type: 'checkbox',
        default: true,
      },
    ],
    columns: [
      { key: 'ac_type_name', label: 'Name' },
      { key: 'is_two_piece', label: 'Two-piece', format: 'bool' },
    ],
  },
```

- [ ] **Step 3: Add it to the Appliance tab**

Open `frontend/src/admin/config/lookupConfigs.js` and find `LOOKUP_TABS` (exported from this same file, below `LOOKUP_CONFIGS`). Add `'acTypes'` to the same tab that lists `'capacities'` and `'variants'` — the Appliance tab. Match the existing shape of that array exactly; do not restructure it.

- [ ] **Step 4: Verify in the browser**

```bash
cd frontend && npm start
```

Navigate to `/admin/categories` as an admin, open the Appliance tab, and confirm:
- an "AC Types" sub-tab lists the six seeded types
- the "Two-piece" column reads Yes/No, not blank (a boolean column without `format: 'bool'` renders as nothing)
- creating a type with the checkbox ticked persists `is_two_piece: true`
- no Merge button appears

- [ ] **Step 5: Check `BatchIncludesTest`**

Task 7 asks the batch endpoint for `acTypes`. Check whether the backend batch endpoint whitelists its includes:

```bash
cd back-end && grep -rn "capacities" app/Http/Controllers/Api/BatchController.php
```

If there is a whitelist array containing `'capacities'` and `'variants'`, add `'acTypes'` to it now, mapping to `AcTypeLookup::all()` in the same style as its neighbours. Then run:

```bash
cd back-end && php artisan test --filter=BatchIncludesTest
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/services/api.js frontend/src/admin/config/lookupConfigs.js back-end/app/Http/Controllers/Api/BatchController.php
git commit -m "feat(catalogue): add the AC Types panel to Item Lookups"
```

---

## Task 7: AC Type and Unit Role on the product registration form

**Files:**
- Modify: `frontend/src/admin/components/ProductRegisterModal.jsx` (initial appliance state ~line 60, batch fetch ~line 110, submit payload ~line 272, the variant `<select>` ~line 541)
- Test: `frontend/src/admin/components/ProductRegisterModal.test.jsx`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/components/ProductRegisterModal.test.jsx`:

```jsx
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import ProductRegisterModal from './ProductRegisterModal';

jest.mock('../services/api', () => ({
  batchAPI: {
    get: jest.fn().mockResolvedValue({
      data: {
        categories: { data: [{ category_id: 1, category_name: 'Air Conditioning', category_type: 'appliance' }] },
        brands: { data: [{ brand_id: 1, brand_name: 'TCL' }] },
        units: { data: [{ unit_id: 1, unit_name: 'Unit' }] },
        capacities: { data: [{ capacity_id: 1, capacity_value: '2HP' }] },
        variants: { data: [{ variant_id: 1, variant_name: 'Inverter' }] },
        acTypes: {
          data: [
            { ac_type_id: 1, ac_type_name: 'Split Type', is_two_piece: true },
            { ac_type_id: 2, ac_type_name: 'Window Type', is_two_piece: false },
          ],
        },
      },
    }),
  },
  productsAPI: { create: jest.fn() },
  categoriesAPI: { getAll: jest.fn() },
  brandsAPI: { getAll: jest.fn() },
  unitsAPI: { getAll: jest.fn() },
  capacitiesAPI: { getAll: jest.fn() },
  variantsAPI: { getAll: jest.fn() },
  acTypesAPI: { getAll: jest.fn() },
  locationsAPI: { getAll: jest.fn().mockResolvedValue({ data: [] }) },
}));

describe('ProductRegisterModal AC fields', () => {
  it('preselects Complete when a one-piece AC type is chosen', async () => {
    render(<ProductRegisterModal open onClose={() => {}} />);

    const acType = await screen.findByLabelText(/AC type/i);
    fireEvent.change(acType, { target: { value: '2' } }); // Window Type

    await waitFor(() => {
      expect(screen.getByLabelText(/Unit role/i)).toHaveValue('complete');
    });
  });

  it('leaves the side unchosen when a two-piece AC type is chosen', async () => {
    render(<ProductRegisterModal open onClose={() => {}} />);

    const acType = await screen.findByLabelText(/AC type/i);
    fireEvent.change(acType, { target: { value: '1' } }); // Split Type

    await waitFor(() => {
      expect(screen.getByLabelText(/Unit role/i)).toHaveValue('');
    });
  });

  it('discards a manual unit role when the AC type changes', async () => {
    render(<ProductRegisterModal open onClose={() => {}} />);

    const acType = await screen.findByLabelText(/AC type/i);
    fireEvent.change(acType, { target: { value: '1' } }); // Split Type

    const unitRole = screen.getByLabelText(/Unit role/i);
    fireEvent.change(unitRole, { target: { value: 'indoor' } });
    expect(unitRole).toHaveValue('indoor');

    fireEvent.change(acType, { target: { value: '2' } }); // Window Type

    await waitFor(() => {
      expect(screen.getByLabelText(/Unit role/i)).toHaveValue('complete');
    });
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd frontend && npx react-scripts test --testPathPattern=ProductRegisterModal --watchAll=false
```

Expected: FAIL — `Unable to find a label with the text of: /AC type/i`.

- [ ] **Step 3: Add the fields to state and the fetch**

In `frontend/src/admin/components/ProductRegisterModal.jsx`, add to the appliance initial-state object (the one containing `capacity_rating: ''` and `variant: ''` at line 60):

```js
  ac_type_id: '',
  unit_role: '',
```

Add an `acTypes` state alongside `variants` (line 100):

```js
  const [acTypes, setAcTypes] = useState([]);
```

Add `'acTypes'` to the batch include array at line 110:

```js
    batchAPI.get({ include: ['categories', 'brands', 'units', 'capacities', 'variants', 'acTypes'] }).then((res) => {
```

and set it alongside the other setters at line 116:

```js
      setAcTypes(Array.isArray(d.acTypes?.data) ? d.acTypes.data : []);
```

- [ ] **Step 4: Add the defaulting handler**

Add this above the JSX return, next to the other memos around line 215:

```js
  /**
   * Changing the AC type re-applies that type's default unit role and
   * DISCARDS any manual choice made before the change. Preserving the manual
   * choice would silently leave a product marked 'complete' under a type that
   * means two-piece — the exact wrong state. The user can re-pick the side
   * immediately; the field stays editable.
   */
  const handleAcTypeChange = (nextId) => {
    const chosen = acTypes.find((t) => String(t.ac_type_id) === String(nextId));

    setApp((a) => ({
      ...a,
      ac_type_id: nextId,
      unit_role: chosen && !chosen.is_two_piece ? 'complete' : '',
    }));
  };
```

- [ ] **Step 5: Add the two fields to the JSX**

Immediately after the variant `<select>` block (which ends with the "No variants defined yet" hint, around line 552), add:

```jsx
              <label className="im-field-label" htmlFor="app-ac-type">AC type</label>
              <select
                id="app-ac-type"
                value={app.ac_type_id}
                onChange={(e) => handleAcTypeChange(e.target.value)}
              >
                <option value="">Select AC type (optional)</option>
                {acTypes.map((t) => (
                  <option key={t.ac_type_id} value={t.ac_type_id}>
                    {t.ac_type_name}
                  </option>
                ))}
              </select>

              <label className="im-field-label" htmlFor="app-unit-role">Unit role</label>
              <select
                id="app-unit-role"
                value={app.unit_role}
                onChange={(e) => setApp((a) => ({ ...a, unit_role: e.target.value }))}
              >
                <option value="">Select unit role</option>
                <option value="indoor">Indoor unit</option>
                <option value="outdoor">Outdoor unit</option>
                <option value="complete">Complete unit</option>
              </select>
```

- [ ] **Step 6: Send the fields on submit**

In the appliance submit payload, alongside `capacity_rating` and `variant` (around line 272), add:

```js
            ac_type_id: app.ac_type_id || null,
            unit_role: app.unit_role || null,
```

- [ ] **Step 7: Run the test to verify it passes**

```bash
cd frontend && npx react-scripts test --testPathPattern=ProductRegisterModal --watchAll=false
```

Expected: PASS, 3 tests.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/admin/components/ProductRegisterModal.jsx frontend/src/admin/components/ProductRegisterModal.test.jsx
git commit -m "feat(catalogue): pick AC type and unit role when registering an appliance"
```

**Phase 1 is now shippable on its own.** Aircons carry their kind and their side, the Item Lookups page maintains the type list, and the existing 208 products are backfilled. Nothing yet groups two units into a model.

---

# Phase 2 — The model that groups units

## Task 8: The `aircon_models` and `aircon_model_units` tables

**Files:**
- Create: `back-end/database/migrations/2026_08_25_000004_create_aircon_models_tables.php`
- Create: `back-end/app/Models/AirconModel.php`, `back-end/app/Models/AirconModelUnit.php`
- Test: `back-end/tests/Feature/Aircon/AirconModelSchemaTest.php`, `back-end/tests/Feature/Aircon/AirconTestCase.php`

- [ ] **Step 1: Write the shared test case**

Create `back-end/tests/Feature/Aircon/AirconTestCase.php`:

```php
<?php

namespace Tests\Feature\Aircon;

use App\Models\AcTypeLookup;
use App\Models\ApplianceDetail;
use App\Models\BrandLookup;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Shared fixtures for the aircon model suite: an authenticated admin, a brand,
 * and a helper that builds an appliance product already carrying a unit role.
 */
abstract class AirconTestCase extends TestCase
{
    use DatabaseTransactions;

    protected function actingAsAdmin(): User
    {
        $roleId = Role::where('role_name', 'admin')->value('role_id');
        $this->assertNotNull($roleId, 'role missing - seed the test database: admin');

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

    protected function brand(): BrandLookup
    {
        return BrandLookup::create(['brand_name' => 'B' . substr(uniqid(), -8)]);
    }

    protected function splitType(): AcTypeLookup
    {
        return AcTypeLookup::where('ac_type_name', 'Split Type')->firstOrFail();
    }

    protected function windowType(): AcTypeLookup
    {
        return AcTypeLookup::where('ac_type_name', 'Window Type')->firstOrFail();
    }

    /** An appliance product carrying the given unit role, at the given price. */
    protected function unitProduct(string $role, float $price = 10000.00): Product
    {
        $product = Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => ucfirst($role) . ' Unit ' . uniqid(),
            'product_type' => 'appliance',
            'unit_price'   => $price,
        ]);

        ApplianceDetail::create([
            'product_id'    => $product->product_id,
            'unit_role'     => $role,
            'ac_type_id'    => $this->splitType()->ac_type_id,
            'is_serialized' => true,
        ]);

        return $product->fresh();
    }
}
```

- [ ] **Step 2: Write the failing schema test**

Create `back-end/tests/Feature/Aircon/AirconModelSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Aircon;

use App\Models\AirconModel;
use App\Models\AirconModelUnit;
use Illuminate\Database\QueryException;

class AirconModelSchemaTest extends AirconTestCase
{
    public function test_a_model_holds_indoor_and_outdoor_unit_lines(): void
    {
        $model = AirconModel::create([
            'model_name'      => 'TCL 2HP Split Inverter ' . uniqid(),
            'brand_id'        => $this->brand()->brand_id,
            'ac_type_id'      => $this->splitType()->ac_type_id,
            'capacity_rating' => '2HP',
            'variant'         => 'Inverter',
            'is_two_piece'    => true,
        ]);

        AirconModelUnit::create([
            'aircon_model_id' => $model->aircon_model_id,
            'product_id'      => $this->unitProduct('indoor')->product_id,
            'quantity'        => 1,
            'sort_order'      => 0,
        ]);
        AirconModelUnit::create([
            'aircon_model_id' => $model->aircon_model_id,
            'product_id'      => $this->unitProduct('outdoor')->product_id,
            'quantity'        => 1,
            'sort_order'      => 1,
        ]);

        $this->assertCount(2, $model->fresh()->units);
    }

    public function test_the_same_product_cannot_be_added_to_one_model_twice(): void
    {
        $model = AirconModel::create([
            'model_name'   => 'Dup Test ' . uniqid(),
            'brand_id'     => $this->brand()->brand_id,
            'ac_type_id'   => $this->splitType()->ac_type_id,
            'is_two_piece' => true,
        ]);

        $product = $this->unitProduct('indoor');

        AirconModelUnit::create([
            'aircon_model_id' => $model->aircon_model_id,
            'product_id'      => $product->product_id,
            'quantity'        => 1,
        ]);

        $this->expectException(QueryException::class);

        AirconModelUnit::create([
            'aircon_model_id' => $model->aircon_model_id,
            'product_id'      => $product->product_id,
            'quantity'        => 1,
        ]);
    }

    public function test_deleting_a_model_removes_its_unit_lines(): void
    {
        $model = AirconModel::create([
            'model_name'   => 'Cascade Test ' . uniqid(),
            'brand_id'     => $this->brand()->brand_id,
            'ac_type_id'   => $this->splitType()->ac_type_id,
            'is_two_piece' => true,
        ]);

        AirconModelUnit::create([
            'aircon_model_id' => $model->aircon_model_id,
            'product_id'      => $this->unitProduct('indoor')->product_id,
            'quantity'        => 1,
        ]);

        $modelId = $model->aircon_model_id;
        $model->forceDelete();

        $this->assertSame(0, AirconModelUnit::where('aircon_model_id', $modelId)->count());
    }

    public function test_an_archived_member_product_still_resolves(): void
    {
        $model = AirconModel::create([
            'model_name'   => 'Archived Member ' . uniqid(),
            'brand_id'     => $this->brand()->brand_id,
            'ac_type_id'   => $this->splitType()->ac_type_id,
            'is_two_piece' => true,
        ]);

        $product = $this->unitProduct('indoor', 12345.00);

        $line = AirconModelUnit::create([
            'aircon_model_id' => $model->aircon_model_id,
            'product_id'      => $product->product_id,
            'quantity'        => 1,
        ]);

        $product->delete(); // soft delete

        $this->assertNotNull($line->fresh()->product);
        $this->assertSame('12345.00', (string) $line->fresh()->product->unit_price);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=AirconModelSchemaTest
```

Expected: FAIL — `Class "App\Models\AirconModel" not found`.

- [ ] **Step 4: Write the migration**

Create `back-end/database/migrations/2026_08_25_000004_create_aircon_models_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An aircon model names a combination of product rows — typically one
     * indoor and one outdoor unit, but a multi-split may be three indoor and
     * one outdoor. The member products stay ordinary products with their own
     * price, stock and serials; the model only groups them.
     *
     * This is deliberately NOT product_sets. That table was made
     * brand-agnostic and unnamed by
     * 2026_07_27_000001_redesign_product_sets_multibrand so it could serve as
     * a job BOQ template matched on capacity and variant. A manufacturer's
     * product identity is a different thing and gets its own table.
     */
    public function up(): void
    {
        Schema::create('aircon_models', function (Blueprint $table) {
            $table->bigIncrements('aircon_model_id');
            $table->string('model_name', 191);
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('ac_type_id')->nullable();
            $table->string('capacity_rating', 20)->nullable();
            $table->string('variant', 100)->nullable();

            // Overrides the AC type's default. Read when saving a model:
            // true requires at least one indoor and one outdoor unit line,
            // false requires exactly one complete line.
            $table->boolean('is_two_piece')->default(true);

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('model_name');

            $table->foreign('brand_id')->references('brand_id')->on('brand_lookup')->nullOnDelete();
            $table->foreign('ac_type_id')->references('ac_type_id')->on('ac_type_lookup')->nullOnDelete();
        });

        Schema::create('aircon_model_units', function (Blueprint $table) {
            $table->bigIncrements('aircon_model_unit_id');
            $table->unsignedBigInteger('aircon_model_id');
            $table->unsignedBigInteger('product_id');

            // 3 for the indoor line of a 3:1 multi-split.
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['aircon_model_id', 'product_id']);

            $table->foreign('aircon_model_id')
                ->references('aircon_model_id')
                ->on('aircon_models')
                ->cascadeOnDelete();

            $table->foreign('product_id')
                ->references('product_id')
                ->on('products')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aircon_model_units');
        Schema::dropIfExists('aircon_models');
    }
};
```

> No `unit_role` column on `aircon_model_units` — see the spec. The line points at a product; the product's `appliance_details.unit_role` is the single source of truth for which side it is.

- [ ] **Step 5: Write the models**

Create `back-end/app/Models/AirconModel.php`:

```php
<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AirconModel extends Model
{
    use SoftDeletes, Auditable;

    protected $table      = 'aircon_models';
    protected $primaryKey = 'aircon_model_id';

    protected $fillable = [
        'model_name', 'brand_id', 'ac_type_id',
        'capacity_rating', 'variant',
        'is_two_piece', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_two_piece' => 'boolean',
        'is_active'    => 'boolean',
    ];

    public function units(): HasMany
    {
        return $this->hasMany(AirconModelUnit::class, 'aircon_model_id', 'aircon_model_id')
            ->orderBy('sort_order');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(BrandLookup::class, 'brand_id', 'brand_id');
    }

    public function acType(): BelongsTo
    {
        return $this->belongsTo(AcTypeLookup::class, 'ac_type_id', 'ac_type_id');
    }

    /**
     * Sum of each unit line's quantity x its product's price.
     *
     * Callers listing many models should eager-load `units.product` on the
     * whole collection first; the loadMissing() here is a safety net for a
     * single instance, not a substitute for batching.
     */
    public function totalPrice(): float
    {
        $this->loadMissing('units.product');

        return round($this->units->sum(
            fn (AirconModelUnit $unit) => $unit->quantity * (float) ($unit->product?->unit_price ?? 0)
        ), 2);
    }
}
```

Create `back-end/app/Models/AirconModelUnit.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AirconModelUnit extends Model
{
    protected $table      = 'aircon_model_units';
    protected $primaryKey = 'aircon_model_unit_id';

    protected $fillable = [
        'aircon_model_id', 'product_id', 'quantity', 'sort_order',
    ];

    protected $casts = [
        'quantity'   => 'integer',
        'sort_order' => 'integer',
    ];

    public function airconModel(): BelongsTo
    {
        return $this->belongsTo(AirconModel::class, 'aircon_model_id', 'aircon_model_id');
    }

    /**
     * Includes archived products on purpose, for the same reason
     * ProductSetLine::product() does: a model line still refers to the
     * product it was authored with even after that product is soft-deleted.
     * Without withTrashed() the line renders nameless and prices as zero,
     * silently understating the model total.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id')->withTrashed();
    }

    /** Which side this line is — read from the product, never stored here. */
    public function unitRole(): ?string
    {
        return $this->product?->applianceDetail?->unit_role;
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
cd back-end && DB_DATABASE=airkingV5_test php artisan migrate && php artisan test --filter=AirconModelSchemaTest
```

Expected: PASS, 4 tests.

- [ ] **Step 7: Commit**

```bash
git add back-end/database/migrations/2026_08_25_000004_create_aircon_models_tables.php back-end/app/Models/AirconModel.php back-end/app/Models/AirconModelUnit.php back-end/tests/Feature/Aircon/
git commit -m "feat(aircon): add the aircon model and its unit lines"
```

---

## Task 9: The composition rules

**Files:**
- Create: `back-end/app/Services/AirconModelComposer.php`
- Test: `back-end/tests/Feature/Aircon/AirconModelComposerTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Aircon/AirconModelComposerTest.php`:

```php
<?php

namespace Tests\Feature\Aircon;

use App\Models\AirconModel;
use App\Services\AirconModelComposer;
use Illuminate\Validation\ValidationException;

/**
 * The is_two_piece invariant lives here rather than in the controller so the
 * conversion tool in Phase 3 enforces exactly the same rule as the builder UI.
 */
class AirconModelComposerTest extends AirconTestCase
{
    private function composer(): AirconModelComposer
    {
        return app(AirconModelComposer::class);
    }

    private function attributes(array $overrides = []): array
    {
        return array_merge([
            'model_name'   => 'Composer Test ' . uniqid(),
            'brand_id'     => $this->brand()->brand_id,
            'ac_type_id'   => $this->splitType()->ac_type_id,
            'is_two_piece' => true,
        ], $overrides);
    }

    public function test_a_standard_split_composes(): void
    {
        $model = $this->composer()->create($this->attributes(), [
            ['product_id' => $this->unitProduct('indoor')->product_id,  'quantity' => 1],
            ['product_id' => $this->unitProduct('outdoor')->product_id, 'quantity' => 1],
        ]);

        $this->assertCount(2, $model->units);
    }

    public function test_a_multi_split_composes_with_quantities(): void
    {
        $model = $this->composer()->create($this->attributes(), [
            ['product_id' => $this->unitProduct('indoor')->product_id,  'quantity' => 3],
            ['product_id' => $this->unitProduct('outdoor')->product_id, 'quantity' => 1],
        ]);

        $quantities = $model->units->pluck('quantity')->sort()->values()->all();

        $this->assertSame([1, 3], $quantities);
    }

    public function test_a_two_piece_model_without_an_outdoor_line_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->composer()->create($this->attributes(), [
            ['product_id' => $this->unitProduct('indoor')->product_id, 'quantity' => 1],
        ]);
    }

    public function test_a_two_piece_model_without_an_indoor_line_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->composer()->create($this->attributes(), [
            ['product_id' => $this->unitProduct('outdoor')->product_id, 'quantity' => 1],
        ]);
    }

    public function test_a_one_piece_model_takes_exactly_one_complete_line(): void
    {
        $model = $this->composer()->create(
            $this->attributes(['is_two_piece' => false, 'ac_type_id' => $this->windowType()->ac_type_id]),
            [['product_id' => $this->unitProduct('complete')->product_id, 'quantity' => 1]]
        );

        $this->assertCount(1, $model->units);
    }

    public function test_a_one_piece_model_carrying_an_indoor_line_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->composer()->create(
            $this->attributes(['is_two_piece' => false, 'ac_type_id' => $this->windowType()->ac_type_id]),
            [['product_id' => $this->unitProduct('indoor')->product_id, 'quantity' => 1]]
        );
    }

    public function test_a_rejected_composition_leaves_no_model_behind(): void
    {
        $before = AirconModel::withTrashed()->count();

        try {
            $this->composer()->create($this->attributes(), [
                ['product_id' => $this->unitProduct('indoor')->product_id, 'quantity' => 1],
            ]);
        } catch (ValidationException $e) {
            // expected
        }

        $this->assertSame($before, AirconModel::withTrashed()->count());
    }

    public function test_total_price_sums_quantity_times_unit_price(): void
    {
        $model = $this->composer()->create($this->attributes(), [
            ['product_id' => $this->unitProduct('indoor', 5000.00)->product_id,  'quantity' => 3],
            ['product_id' => $this->unitProduct('outdoor', 20000.00)->product_id, 'quantity' => 1],
        ]);

        $this->assertSame(35000.00, $model->totalPrice());
    }

    public function test_updating_replaces_the_unit_lines_wholesale(): void
    {
        $model = $this->composer()->create($this->attributes(), [
            ['product_id' => $this->unitProduct('indoor')->product_id,  'quantity' => 1],
            ['product_id' => $this->unitProduct('outdoor')->product_id, 'quantity' => 1],
        ]);

        $newIndoor  = $this->unitProduct('indoor');
        $newOutdoor = $this->unitProduct('outdoor');

        $updated = $this->composer()->update($model, [], [
            ['product_id' => $newIndoor->product_id,  'quantity' => 2],
            ['product_id' => $newOutdoor->product_id, 'quantity' => 1],
        ]);

        $productIds = $updated->units->pluck('product_id')->sort()->values()->all();

        $this->assertSame(
            collect([$newIndoor->product_id, $newOutdoor->product_id])->sort()->values()->all(),
            $productIds
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=AirconModelComposerTest
```

Expected: FAIL — `Class "App\Services\AirconModelComposer" not found`.

- [ ] **Step 3: Write the composer**

Create `back-end/app/Services/AirconModelComposer.php`:

```php
<?php

namespace App\Services;

use App\Models\AirconModel;
use App\Models\ApplianceDetail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds and rewrites aircon models, enforcing the one rule that makes a model
 * meaningful: its unit lines must match its piece count.
 *
 * This lives in a service rather than the controller because the conversion
 * tool (Phase 3) composes models too, and a rule enforced in only one of the
 * two entry points is a rule that does not hold.
 */
class AirconModelComposer
{
    /**
     * @param  array<string,mixed>  $attributes
     * @param  array<int,array{product_id:int,quantity?:int}>  $lines
     */
    public function create(array $attributes, array $lines): AirconModel
    {
        $this->assertLinesMatchPieceCount($attributes['is_two_piece'] ?? true, $lines);

        return DB::transaction(function () use ($attributes, $lines) {
            $model = AirconModel::create(array_merge($attributes, [
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]));

            $this->syncLines($model, $lines);

            return $model->fresh(['units.product', 'brand', 'acType']);
        });
    }

    /**
     * @param  array<string,mixed>  $attributes  Only the keys present are changed.
     * @param  array<int,array{product_id:int,quantity?:int}>|null  $lines  Null leaves lines untouched.
     */
    public function update(AirconModel $model, array $attributes, ?array $lines = null): AirconModel
    {
        $isTwoPiece = array_key_exists('is_two_piece', $attributes)
            ? (bool) $attributes['is_two_piece']
            : $model->is_two_piece;

        // Validate against whichever line set will be in force after the
        // update — the incoming one if given, otherwise what is already
        // stored, since is_two_piece may itself be changing.
        $effectiveLines = $lines ?? $model->units->map(fn ($u) => [
            'product_id' => $u->product_id,
            'quantity'   => $u->quantity,
        ])->all();

        $this->assertLinesMatchPieceCount($isTwoPiece, $effectiveLines);

        return DB::transaction(function () use ($model, $attributes, $lines) {
            $model->update(array_merge($attributes, ['updated_by' => Auth::id()]));

            if ($lines !== null) {
                $this->syncLines($model, $lines);
            }

            return $model->fresh(['units.product', 'brand', 'acType']);
        });
    }

    /** Replaces the model's lines wholesale, in the order given. */
    private function syncLines(AirconModel $model, array $lines): void
    {
        $model->units()->delete();

        foreach (array_values($lines) as $index => $line) {
            $model->units()->create([
                'product_id' => $line['product_id'],
                'quantity'   => max(1, (int) ($line['quantity'] ?? 1)),
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * A two-piece model needs at least one indoor and at least one outdoor
     * line; a one-piece model needs exactly one line and that line must be a
     * complete unit. Roles are read from each product's appliance_details,
     * which is the only place a unit's side is recorded.
     *
     * @param  array<int,array{product_id:int,quantity?:int}>  $lines
     */
    private function assertLinesMatchPieceCount(bool $isTwoPiece, array $lines): void
    {
        $productIds = array_column($lines, 'product_id');

        $roles = ApplianceDetail::whereIn('product_id', $productIds)
            ->pluck('unit_role', 'product_id');

        $present = collect($productIds)
            ->map(fn ($id) => $roles[$id] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($isTwoPiece) {
            $missing = collect(['indoor', 'outdoor'])
                ->reject(fn ($role) => $present->contains($role));

            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => 'A two-piece aircon model needs at least one '
                        . $missing->implode(' and one ') . ' unit.',
                ]);
            }

            return;
        }

        if (count($lines) !== 1) {
            throw ValidationException::withMessages([
                'lines' => 'A one-piece aircon model takes exactly one unit line.',
            ]);
        }

        if (! $present->contains('complete')) {
            throw ValidationException::withMessages([
                'lines' => 'A one-piece aircon model\'s unit must be a complete unit, not an indoor or outdoor one.',
            ]);
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=AirconModelComposerTest
```

Expected: PASS, 9 tests.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/AirconModelComposer.php back-end/tests/Feature/Aircon/AirconModelComposerTest.php
git commit -m "feat(aircon): enforce the piece-count rule when composing a model"
```

---

## Task 10: The aircon model endpoints, permission and nav entry

**Files:**
- Create: `back-end/app/Http/Controllers/Api/AirconModelController.php`
- Modify: `back-end/routes/api.php` (after the product-sets block, ~line 581)
- Modify: `back-end/database/seeders/PermissionSeeder.php` (after `product_set_management`, ~line 80)
- Modify: `back-end/database/seeders/NavBundleSeeder.php` (after the Set Management row, ~line 36)
- Test: `back-end/tests/Feature/Aircon/AirconModelCrudTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Aircon/AirconModelCrudTest.php`:

```php
<?php

namespace Tests\Feature\Aircon;

class AirconModelCrudTest extends AirconTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'model_name'      => 'TCL 2HP Split ' . uniqid(),
            'brand_id'        => $this->brand()->brand_id,
            'ac_type_id'      => $this->splitType()->ac_type_id,
            'capacity_rating' => '2HP',
            'variant'         => 'Inverter',
            'is_two_piece'    => true,
            'lines'           => [
                ['product_id' => $this->unitProduct('indoor', 8000.00)->product_id,  'quantity' => 1],
                ['product_id' => $this->unitProduct('outdoor', 12000.00)->product_id, 'quantity' => 1],
            ],
        ], $overrides);
    }

    public function test_creating_a_model_returns_it_with_its_lines_and_total(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/aircon-models', $this->payload());

        $response->assertStatus(201);
        $response->assertJsonCount(2, 'data.units');
        $response->assertJsonPath('data.total_price', 20000);
    }

    public function test_a_two_piece_model_missing_a_side_is_rejected(): void
    {
        $this->actingAsAdmin();

        $payload = $this->payload();
        array_pop($payload['lines']);

        $this->postJson('/api/aircon-models', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');
    }

    public function test_listing_returns_active_models(): void
    {
        $this->actingAsAdmin();

        $name = 'Listed ' . uniqid();
        $this->postJson('/api/aircon-models', $this->payload(['model_name' => $name]))->assertStatus(201);

        $list = $this->getJson('/api/aircon-models')->json('data');

        $this->assertNotNull(collect($list)->firstWhere('model_name', $name));
    }

    public function test_archiving_a_model_removes_it_from_the_active_list(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/aircon-models', $this->payload())->json('data.aircon_model_id');

        $this->deleteJson("/api/aircon-models/{$id}")->assertStatus(200);

        $list = $this->getJson('/api/aircon-models')->json('data');

        $this->assertNull(collect($list)->firstWhere('aircon_model_id', $id));
    }

    public function test_a_warehouse_user_can_read_but_not_create(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/aircon-models', $this->payload())->assertStatus(201);

        $roleId = \App\Models\Role::where('role_name', 'warehouse_personnel')->value('role_id');
        $this->assertNotNull($roleId, 'role missing - seed the test database: warehouse_personnel');

        $user = \App\Models\User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'Ware',
            'last_name'     => 'House',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]);
        $this->actingAs($user);

        $this->getJson('/api/aircon-models')->assertStatus(200);
        $this->postJson('/api/aircon-models', $this->payload())->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=AirconModelCrudTest
```

Expected: FAIL — 404, no route registered.

- [ ] **Step 3: Write the controller**

Create `back-end/app/Http/Controllers/Api/AirconModelController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AirconModel;
use App\Services\AirconModelComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin over AirconModelComposer — the piece-count rule lives there so the
 * conversion tool enforces the same one.
 */
class AirconModelController extends Controller
{
    public function __construct(private AirconModelComposer $composer)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = AirconModel::with(['units.product.applianceDetail', 'brand', 'acType']);

        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('model_name', 'like', "%{$search}%")
                  ->orWhere('capacity_rating', 'like', "%{$search}%")
                  ->orWhere('variant', 'like', "%{$search}%");
            });
        }

        $models = $query->orderBy('model_name')->get();

        return response()->json([
            'success' => true,
            'data'    => $models->map(fn (AirconModel $m) => $this->present($m))->all(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $model = AirconModel::with(['units.product.applianceDetail', 'brand', 'acType'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->present($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $model = $this->composer->create(
            collect($validated)->except('lines')->all(),
            $validated['lines']
        );

        return response()->json([
            'success' => true,
            'message' => 'Aircon model created.',
            'data'    => $this->present($model),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $model     = AirconModel::with('units')->findOrFail($id);
        $validated = $this->validatePayload($request, $id);

        $updated = $this->composer->update(
            $model,
            collect($validated)->except('lines')->all(),
            $validated['lines'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Aircon model updated.',
            'data'    => $this->present($updated),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        AirconModel::findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'Aircon model archived.']);
    }

    public function restore(int $id): JsonResponse
    {
        AirconModel::onlyTrashed()->findOrFail($id)->restore();

        return response()->json(['success' => true, 'message' => 'Aircon model restored.']);
    }

    private function validatePayload(Request $request, ?int $id = null): array
    {
        $required = $id === null ? 'required' : 'sometimes';

        return $request->validate([
            'model_name'         => [$required, 'string', 'max:191'],
            'brand_id'           => 'nullable|exists:brand_lookup,brand_id',
            'ac_type_id'         => 'nullable|exists:ac_type_lookup,ac_type_id',
            'capacity_rating'    => 'nullable|string|max:20',
            'variant'            => 'nullable|string|max:100',
            'is_two_piece'       => 'sometimes|boolean',
            'is_active'          => 'sometimes|boolean',
            'lines'              => [$required, 'array', 'min:1'],
            'lines.*.product_id' => 'required|exists:products,product_id',
            'lines.*.quantity'   => 'nullable|integer|min:1|max:65535',
        ]);
    }

    private function present(AirconModel $model): array
    {
        return array_merge($model->toArray(), [
            'brand_name'  => $model->brand?->brand_name,
            'ac_type_name'=> $model->acType?->ac_type_name,
            'total_price' => $model->totalPrice(),
            'units'       => $model->units->map(fn ($unit) => [
                'aircon_model_unit_id' => $unit->aircon_model_unit_id,
                'product_id'           => $unit->product_id,
                'product_name'         => $unit->product?->product_name,
                'product_code'         => $unit->product?->product_code,
                'unit_price'           => (float) ($unit->product?->unit_price ?? 0),
                'unit_role'            => $unit->unitRole(),
                'quantity'             => $unit->quantity,
                'sort_order'           => $unit->sort_order,
                'is_archived'          => $unit->product?->trashed() ?? false,
            ])->all(),
        ]);
    }
}
```

- [ ] **Step 4: Register the routes**

In `back-end/routes/api.php`, after the `product-sets/{id}/restore` route (~line 581), add:

```php
    /* ---------------------------------------------------------------
     | Aircon Models (/admin/aircon-models)
     |
     | Deliberately separate from product-sets: a set is a job BOQ matched on
     | capacity and variant, an aircon model is manufacturer product identity.
     * ------------------------------------------------------------- */
    Route::middleware('permission:aircon_model_management,read')
        ->get('/aircon-models', [\App\Http\Controllers\Api\AirconModelController::class, 'index']);
    Route::middleware('permission:aircon_model_management,read')
        ->get('/aircon-models/{id}', [\App\Http\Controllers\Api\AirconModelController::class, 'show']);
    Route::middleware('permission:aircon_model_management,create')
        ->post('/aircon-models', [\App\Http\Controllers\Api\AirconModelController::class, 'store']);
    Route::middleware('permission:aircon_model_management,update')
        ->put('/aircon-models/{id}', [\App\Http\Controllers\Api\AirconModelController::class, 'update']);
    Route::middleware('permission:aircon_model_management,archive')
        ->delete('/aircon-models/{id}', [\App\Http\Controllers\Api\AirconModelController::class, 'destroy']);
    Route::middleware('permission:aircon_model_management,archive')
        ->post('/aircon-models/{id}/restore', [\App\Http\Controllers\Api\AirconModelController::class, 'restore']);
```

- [ ] **Step 5: Seed the permission**

In `back-end/database/seeders/PermissionSeeder.php`, immediately after the `product_set_management` block (~line 80), add:

```php
            // Aircon model catalogue (/admin/aircon-models) — same role shape as
            // product_set_management: admin maintains, everyone who builds an
            // order needs to read it so the picker can list models.
            'aircon_model_management' => [
                'read'    => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'create'  => ['admin'],
                'update'  => ['admin'],
                'archive' => ['admin'],
            ],
```

- [ ] **Step 6: Seed the nav entry**

In `back-end/database/seeders/NavBundleSeeder.php`, immediately after the `Set Management` row (~line 36), add:

```php
            ['Aircon Models',        '/admin/aircon-models',        'PROCUREMENT & PLANNING',  'sets',               'aircon_model_management', 'read'],
```

- [ ] **Step 7: Re-seed and run the tests**

```bash
cd back-end && DB_DATABASE=airkingV5_test php artisan db:seed --class=PermissionSeeder && DB_DATABASE=airkingV5_test php artisan db:seed --class=NavBundleSeeder && php artisan test --filter=AirconModelCrudTest
```

Expected: PASS, 5 tests.

Then run the permission suite, which asserts every nav bundle maps to a seeded permission:

```bash
cd back-end && php artisan test --filter=Permissions
```

Expected: PASS. If a test asserts an exact permission count, update that count.

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Http/Controllers/Api/AirconModelController.php back-end/routes/api.php back-end/database/seeders/PermissionSeeder.php back-end/database/seeders/NavBundleSeeder.php back-end/tests/Feature/Aircon/AirconModelCrudTest.php
git commit -m "feat(aircon): add the aircon model endpoints, permission and nav entry"
```

---

## Task 11: The Aircon Models page

**Files:**
- Create: `frontend/src/admin/pages/AirconModelsPage.jsx`
- Create: `frontend/src/admin/components/AirconModelBuilderModal.jsx`
- Modify: `frontend/src/admin/services/api.js` (after `acTypesAPI`)
- Modify: `frontend/src/App.jsx` (lazy import ~line 24, route ~line 129)

- [ ] **Step 1: Add the API client**

In `frontend/src/admin/services/api.js`, after the `acTypesAPI` block, add:

```js
/* ======================
   AIRCON MODELS API
====================== */
export const airconModelsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/aircon-models?${query}` : '/aircon-models');
  },
  getById: async (id) => apiRequest(`/aircon-models/${id}`),
  create: async (data) =>
    apiRequest('/aircon-models', { method: 'POST', body: JSON.stringify(data) }),
  update: async (id, data) =>
    apiRequest(`/aircon-models/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  delete: async (id) => apiRequest(`/aircon-models/${id}`, { method: 'DELETE' }),
  restore: async (id) => apiRequest(`/aircon-models/${id}/restore`, { method: 'POST' }),
};
```

- [ ] **Step 2: Read the page you are copying**

```bash
cd frontend && cat src/admin/pages/SetManagementPage.jsx && cat src/admin/components/SetBuilderModal.jsx
```

`AirconModelsPage` must mirror `SetManagementPage`'s structure exactly: the same `can(...)` permission gates (against `aircon_model_management` instead of `product_set_management`), the same `active` / `archived` view toggle, the same search input, the same table shell and CSS class names, and the same builder-modal open/close handling. Reuse its stylesheet rather than writing new CSS.

- [ ] **Step 3: Write the page**

Create `frontend/src/admin/pages/AirconModelsPage.jsx` following that structure, with these columns:

| Column | Source |
| --- | --- |
| Model name | `model_name` |
| Brand | `brand_name` |
| AC type | `ac_type_name` |
| Capacity | `capacity_rating` |
| Variant | `variant` |
| Pieces | `is_two_piece ? 'Two-piece' : 'One-piece'` |
| Units | `units.length` |
| Total price | `total_price`, peso-formatted like `SetManagementPage` formats its set totals |

Row actions: Edit (gated on `aircon_model_management.update`), Archive (gated on `archive`). In the archived view, Restore replaces both.

- [ ] **Step 4: Write the builder modal**

Create `frontend/src/admin/components/AirconModelBuilderModal.jsx` following `SetBuilderModal`'s structure. Header fields: model name (text, required), brand (select from `brandsAPI`), AC type (select from `acTypesAPI`), capacity (select from `capacitiesAPI`), variant (select from `variantsAPI`), and a two-piece checkbox.

Selecting an AC type sets the two-piece checkbox from that type's `is_two_piece`, and — exactly as in `ProductRegisterModal` — discards any manual toggle made before the change. Add this comment above the handler so the next reader does not "fix" it:

```js
  // Same rule as ProductRegisterModal: changing the AC type re-applies its
  // default and discards a manual override. A model left marked one-piece
  // under Split Type would fail the composer's check on save anyway; better
  // to correct it at the moment the type changes than to reject the save.
```

The unit lines section lists rows of `[product picker] [quantity] [remove]`. The product picker must filter to appliance products (`product_type === 'appliance'`) and show each option's `unit_role` beside its name, so the user can see which side they are adding. Show a running total beneath the lines: `sum(quantity × unit_price)`.

Client-side guard before submit, mirroring the server rule so the user gets the message without a round trip — the server still enforces it:

```js
  const roles = lines.map((l) => productById[l.product_id]?.unit_role).filter(Boolean);

  if (isTwoPiece && !(roles.includes('indoor') && roles.includes('outdoor'))) {
    toast.error('A two-piece aircon model needs at least one indoor and one outdoor unit.');
    return;
  }

  if (!isTwoPiece && (lines.length !== 1 || !roles.includes('complete'))) {
    toast.error('A one-piece aircon model takes exactly one complete unit.');
    return;
  }
```

- [ ] **Step 5: Register the route**

In `frontend/src/App.jsx`, add the lazy import beside the `SetManagementPage` one (~line 24):

```js
const AirconModelsPage = lazy(() => import('./admin/pages/AirconModelsPage'));
```

and the route beside `/admin/sets` (~line 129):

```jsx
          <Route path="/admin/aircon-models" element={<RoleRoute><AirconModelsPage /></RoleRoute>} />
```

- [ ] **Step 6: Verify in the browser**

```bash
cd frontend && npm start
```

As an admin, go to `/admin/aircon-models` and confirm: the nav entry appears under PROCUREMENT & PLANNING; creating a split model with one indoor and one outdoor line succeeds and shows the summed total; saving a two-piece model with only an indoor line shows the error and does not create anything; archiving removes it from the active list.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/pages/AirconModelsPage.jsx frontend/src/admin/components/AirconModelBuilderModal.jsx frontend/src/admin/services/api.js frontend/src/App.jsx
git commit -m "feat(aircon): add the Aircon Models management page"
```

**Phase 2 is now shippable.** Indoor and outdoor products can be grouped into named models. Documents do not yet know about models.

---

# Phase 3 — Models on documents

## Task 12: The `aircon_model_id` columns

**Files:**
- Create: `back-end/database/migrations/2026_08_25_000005_add_aircon_model_id_to_document_details.php`
- Modify: `back-end/app/Models/PurchaseOrderDetail.php`, `IssuanceDetail.php`, `ReceivingDetail.php` (each `$fillable`)

- [ ] **Step 1: Write the migration**

Create `back-end/database/migrations/2026_08_25_000005_add_aircon_model_id_to_document_details.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Detail rows sharing an aircon_model_id within one document render as a
     * group: one header showing the model name and the aircon count, with the
     * indoor and outdoor product lines nested beneath.
     *
     * There is deliberately no parent table with a stored quantity. The header
     * count is derived from the lines by AirconLineGrouper, because indoor and
     * outdoor quantities legitimately diverge — a short delivery receives 2
     * outdoor against 3 indoor, and a stored parent quantity would have to
     * either misreport that or block it.
     *
     * receiving_details gets its own column rather than reading through
     * po_detail_id: scan-driven receiving creates rows with no
     * purchase-order line behind them, and those still need to group.
     */
    public function up(): void
    {
        foreach (['purchase_order_details', 'issuance_details', 'receiving_details'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('aircon_model_id')->nullable()->after('product_id');

                $t->foreign('aircon_model_id')
                    ->references('aircon_model_id')
                    ->on('aircon_models')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['purchase_order_details', 'issuance_details', 'receiving_details'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['aircon_model_id']);
                $t->dropColumn('aircon_model_id');
            });
        }
    }
};
```

- [ ] **Step 2: Add the column to each model's `$fillable`**

Add `'aircon_model_id',` to the `$fillable` array in each of:
- `back-end/app/Models/PurchaseOrderDetail.php`
- `back-end/app/Models/IssuanceDetail.php`
- `back-end/app/Models/ReceivingDetail.php`

- [ ] **Step 3: Migrate and confirm nothing regressed**

```bash
cd back-end && DB_DATABASE=airkingV5_test php artisan migrate && php artisan test
```

Expected: PASS. A nullable column with no writer cannot change behaviour; if anything fails here it is a pre-existing problem surfacing, not this change.

- [ ] **Step 4: Commit**

```bash
git add back-end/database/migrations/2026_08_25_000005_add_aircon_model_id_to_document_details.php back-end/app/Models/PurchaseOrderDetail.php back-end/app/Models/IssuanceDetail.php back-end/app/Models/ReceivingDetail.php
git commit -m "feat(aircon): let document detail lines reference an aircon model"
```

---

## Task 13: The line grouper

**Files:**
- Create: `back-end/app/Services/AirconLineGrouper.php`
- Test: `back-end/tests/Feature/Aircon/AirconLineGrouperTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Aircon/AirconLineGrouperTest.php`:

```php
<?php

namespace Tests\Feature\Aircon;

use App\Services\AirconLineGrouper;
use App\Services\AirconModelComposer;

/**
 * The derived header quantity and the imbalance report. This is the subtlest
 * logic in the feature and the reason it lives in one service rather than in
 * three controllers.
 */
class AirconLineGrouperTest extends AirconTestCase
{
    private function model(int $indoorQty = 1, int $outdoorQty = 1): array
    {
        $indoor  = $this->unitProduct('indoor');
        $outdoor = $this->unitProduct('outdoor');

        $model = app(AirconModelComposer::class)->create([
            'model_name'   => 'Grouper ' . uniqid(),
            'brand_id'     => $this->brand()->brand_id,
            'ac_type_id'   => $this->splitType()->ac_type_id,
            'is_two_piece' => true,
        ], [
            ['product_id' => $indoor->product_id,  'quantity' => $indoorQty],
            ['product_id' => $outdoor->product_id, 'quantity' => $outdoorQty],
        ]);

        return [$model, $indoor, $outdoor];
    }

    private function grouper(): AirconLineGrouper
    {
        return app(AirconLineGrouper::class);
    }

    public function test_balanced_lines_report_the_aircon_count(): void
    {
        [$model, $indoor, $outdoor] = $this->model();

        $groups = $this->grouper()->group([
            ['product_id' => $indoor->product_id,  'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
            ['product_id' => $outdoor->product_id, 'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame(3, $groups[0]['complete_count']);
        $this->assertFalse($groups[0]['is_unbalanced']);
    }

    public function test_a_short_outdoor_line_reports_the_unpaired_remainder(): void
    {
        [$model, $indoor, $outdoor] = $this->model();

        $groups = $this->grouper()->group([
            ['product_id' => $indoor->product_id,  'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
            ['product_id' => $outdoor->product_id, 'quantity' => 2, 'aircon_model_id' => $model->aircon_model_id],
        ]);

        $this->assertSame(2, $groups[0]['complete_count']);
        $this->assertTrue($groups[0]['is_unbalanced']);
        $this->assertSame(['indoor' => 1], $groups[0]['unpaired']);
    }

    public function test_a_multi_split_ratio_divides_correctly(): void
    {
        [$model, $indoor, $outdoor] = $this->model(3, 1);

        $groups = $this->grouper()->group([
            ['product_id' => $indoor->product_id,  'quantity' => 6, 'aircon_model_id' => $model->aircon_model_id],
            ['product_id' => $outdoor->product_id, 'quantity' => 2, 'aircon_model_id' => $model->aircon_model_id],
        ]);

        $this->assertSame(2, $groups[0]['complete_count']);
        $this->assertFalse($groups[0]['is_unbalanced']);
    }

    public function test_a_multi_split_short_by_one_indoor_is_unbalanced(): void
    {
        [$model, $indoor, $outdoor] = $this->model(3, 1);

        $groups = $this->grouper()->group([
            ['product_id' => $indoor->product_id,  'quantity' => 5, 'aircon_model_id' => $model->aircon_model_id],
            ['product_id' => $outdoor->product_id, 'quantity' => 2, 'aircon_model_id' => $model->aircon_model_id],
        ]);

        $this->assertSame(1, $groups[0]['complete_count']);
        $this->assertTrue($groups[0]['is_unbalanced']);
    }

    public function test_lines_with_no_model_are_returned_ungrouped(): void
    {
        $loose = $this->unitProduct('outdoor');

        $groups = $this->grouper()->group([
            ['product_id' => $loose->product_id, 'quantity' => 1, 'aircon_model_id' => null],
        ]);

        $this->assertCount(1, $groups);
        $this->assertNull($groups[0]['aircon_model_id']);
        $this->assertNull($groups[0]['complete_count']);
    }

    public function test_two_models_in_one_document_produce_two_groups(): void
    {
        [$modelA, $indoorA, $outdoorA] = $this->model();
        [$modelB, $indoorB, $outdoorB] = $this->model();

        $groups = $this->grouper()->group([
            ['product_id' => $indoorA->product_id,  'quantity' => 1, 'aircon_model_id' => $modelA->aircon_model_id],
            ['product_id' => $outdoorA->product_id, 'quantity' => 1, 'aircon_model_id' => $modelA->aircon_model_id],
            ['product_id' => $indoorB->product_id,  'quantity' => 2, 'aircon_model_id' => $modelB->aircon_model_id],
            ['product_id' => $outdoorB->product_id, 'quantity' => 2, 'aircon_model_id' => $modelB->aircon_model_id],
        ]);

        $this->assertCount(2, $groups);
    }

    public function test_a_group_missing_a_side_entirely_reports_zero_complete(): void
    {
        [$model, $indoor] = $this->model();

        $groups = $this->grouper()->group([
            ['product_id' => $indoor->product_id, 'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
        ]);

        $this->assertSame(0, $groups[0]['complete_count']);
        $this->assertTrue($groups[0]['is_unbalanced']);
        $this->assertSame(['indoor' => 3], $groups[0]['unpaired']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=AirconLineGrouperTest
```

Expected: FAIL — `Class "App\Services\AirconLineGrouper" not found`.

- [ ] **Step 3: Write the grouper**

Create `back-end/app/Services/AirconLineGrouper.php`:

```php
<?php

namespace App\Services;

use App\Models\AirconModel;
use App\Models\ApplianceDetail;

/**
 * Turns a flat list of document detail lines into aircon groups plus loose
 * lines, deriving each group's header quantity from its lines.
 *
 * Nothing stores the header count. A purchase order for three aircons is an
 * indoor line of 3 and an outdoor line of 3; the header reads 3 because
 * 3 / 1-indoor-per-model = 3. When a delivery arrives short and the outdoor
 * line is received at 2, the group is not invalid — it reports 2 complete and
 * 1 unpaired indoor. A stored parent quantity would have to either misreport
 * that delivery or refuse it, and short deliveries are normal here.
 */
class AirconLineGrouper
{
    /**
     * @param  array<int,array{product_id:int,quantity:int,aircon_model_id:?int}>  $lines
     * @return array<int,array<string,mixed>>
     */
    public function group(array $lines): array
    {
        $modelIds = collect($lines)->pluck('aircon_model_id')->filter()->unique()->all();

        $models = AirconModel::with('units')
            ->whereIn('aircon_model_id', $modelIds)
            ->get()
            ->keyBy('aircon_model_id');

        $roles = ApplianceDetail::whereIn('product_id', collect($lines)->pluck('product_id')->all())
            ->pluck('unit_role', 'product_id');

        $groups = [];

        // Loose lines first, each its own single-line group, so a replacement
        // outdoor unit ordered on its own renders as an ordinary product line.
        foreach ($lines as $line) {
            if (empty($line['aircon_model_id'])) {
                $groups[] = [
                    'aircon_model_id' => null,
                    'model_name'      => null,
                    'complete_count'  => null,
                    'is_unbalanced'   => false,
                    'unpaired'        => [],
                    'lines'           => [$line],
                ];
            }
        }

        foreach ($modelIds as $modelId) {
            $model = $models->get($modelId);

            $groupLines = array_values(array_filter(
                $lines,
                fn ($l) => (int) ($l['aircon_model_id'] ?? 0) === (int) $modelId
            ));

            // How many of each role one complete aircon needs, from the model.
            $required = [];

            foreach ($model?->units ?? [] as $unit) {
                $role = $roles[$unit->product_id] ?? null;

                if ($role !== null) {
                    $required[$role] = ($required[$role] ?? 0) + $unit->quantity;
                }
            }

            // How many of each role this document actually carries.
            $present = [];

            foreach ($groupLines as $line) {
                $role = $roles[$line['product_id']] ?? null;

                if ($role !== null) {
                    $present[$role] = ($present[$role] ?? 0) + (int) $line['quantity'];
                }
            }

            // The header count is the most complete aircons the present lines
            // can make: the smallest whole multiple across every required role.
            // A role absent from $present yields 0, which is the honest answer.
            $completeCount = 0;

            if ($required !== []) {
                $completeCount = (int) collect($required)
                    ->map(fn ($needed, $role) => intdiv($present[$role] ?? 0, $needed))
                    ->min();
            }

            // Whatever is left over once those complete aircons are accounted for.
            $unpaired = [];

            foreach ($required as $role => $needed) {
                $leftover = ($present[$role] ?? 0) - ($completeCount * $needed);

                if ($leftover > 0) {
                    $unpaired[$role] = $leftover;
                }
            }

            $groups[] = [
                'aircon_model_id' => (int) $modelId,
                'model_name'      => $model?->model_name,
                'complete_count'  => $completeCount,
                'is_unbalanced'   => $unpaired !== [],
                'unpaired'        => $unpaired,
                'lines'           => $groupLines,
            ];
        }

        return $groups;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=AirconLineGrouperTest
```

Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/AirconLineGrouper.php back-end/tests/Feature/Aircon/AirconLineGrouperTest.php
git commit -m "feat(aircon): derive the aircon count and imbalance from document lines"
```

---

## Task 14: Render the groups on purchase orders, issuances and receiving

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseOrderController.php`, `IssuanceOrderController.php`, `ReceivingController.php` (each detail-returning response)
- Modify: `frontend/src/admin/pages/PurchaseOrderPage.jsx`, `IssuanceManagement.jsx`, and the receiving detail view
- Test: `back-end/tests/Feature/Aircon/AirconDocumentGroupingTest.php`

- [ ] **Step 1: Find the response shapes you must extend**

```bash
cd back-end && grep -n "details" app/Http/Controllers/Api/PurchaseOrderController.php | head -30
```

Identify the method that returns a purchase order with its detail lines. Do the same for `IssuanceOrderController` and `ReceivingController`. Do not restructure those responses — add one sibling key.

- [ ] **Step 2: Write the failing test**

Create `back-end/tests/Feature/Aircon/AirconDocumentGroupingTest.php`. Build a purchase order carrying an indoor line of 3 and an outdoor line of 2, both referencing one model, then assert the response's `aircon_groups` key:

```php
<?php

namespace Tests\Feature\Aircon;

use App\Services\AirconModelComposer;

class AirconDocumentGroupingTest extends AirconTestCase
{
    public function test_a_purchase_order_returns_aircon_groups(): void
    {
        $this->actingAsAdmin();

        $indoor  = $this->unitProduct('indoor');
        $outdoor = $this->unitProduct('outdoor');

        $model = app(AirconModelComposer::class)->create([
            'model_name'   => 'PO Group ' . uniqid(),
            'brand_id'     => $this->brand()->brand_id,
            'ac_type_id'   => $this->splitType()->ac_type_id,
            'is_two_piece' => true,
        ], [
            ['product_id' => $indoor->product_id,  'quantity' => 1],
            ['product_id' => $outdoor->product_id, 'quantity' => 1],
        ]);

        // Build the purchase order using whatever factory or endpoint the
        // existing PO tests use — copy that setup verbatim from
        // back-end/tests/Feature/ (grep for 'purchase_order_details') rather
        // than inventing a new one, so this test breaks for the same reasons
        // the existing PO tests do.
        $poId = $this->createPurchaseOrderWithLines([
            ['product_id' => $indoor->product_id,  'quantity_ordered' => 3, 'aircon_model_id' => $model->aircon_model_id],
            ['product_id' => $outdoor->product_id, 'quantity_ordered' => 2, 'aircon_model_id' => $model->aircon_model_id],
        ]);

        $groups = $this->getJson("/api/purchase-orders/{$poId}")->json('data.aircon_groups');

        $this->assertCount(1, $groups);
        $this->assertSame(2, $groups[0]['complete_count']);
        $this->assertTrue($groups[0]['is_unbalanced']);
        $this->assertSame(['indoor' => 1], $groups[0]['unpaired']);
    }
}
```

> `createPurchaseOrderWithLines()` is a helper you must write on `AirconTestCase`, copying the PO setup the existing purchase-order tests use. Find it with `grep -rln "purchase_order_details" back-end/tests/`.

- [ ] **Step 3: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=AirconDocumentGroupingTest
```

Expected: FAIL — `aircon_groups` is null.

- [ ] **Step 4: Add the key to each controller**

In each of the three controllers, inject `AirconLineGrouper` and add one key to the detail response. For `PurchaseOrderController`:

```php
    public function __construct(private AirconLineGrouper $grouper)
    {
    }
```

and where the response is assembled:

```php
            'aircon_groups' => $this->grouper->group(
                $po->details->map(fn ($d) => [
                    'product_id'      => $d->product_id,
                    'quantity'        => (int) $d->quantity_ordered,
                    'aircon_model_id' => $d->aircon_model_id,
                ])->all()
            ),
```

For `IssuanceOrderController` the quantity field is `quantity_issued`; for `ReceivingController` it is `quantity_amount`. Everything else is identical.

- [ ] **Step 5: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=AirconDocumentGroupingTest
```

Expected: PASS.

- [ ] **Step 6: Render the groups in the frontend**

In each of the three pages, when `aircon_groups` contains an entry with a non-null `aircon_model_id`, render that group as a header row followed by its nested lines:

```jsx
{group.aircon_model_id && (
  <tr className="ak-group-header">
    <td colSpan={columnCount}>
      <strong>{group.model_name}</strong>
      <span> · {group.complete_count} complete</span>
      {group.is_unbalanced && (
        <span className="ak-warning">
          {' · '}
          {Object.entries(group.unpaired)
            .map(([role, n]) => `${n} ${role} unpaired`)
            .join(', ')}
        </span>
      )}
    </td>
  </tr>
)}
```

Groups with a null `aircon_model_id` render their single line exactly as today — no header, no indentation.

- [ ] **Step 7: Run the whole backend suite**

```bash
cd back-end && php artisan test
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Http/Controllers/Api/PurchaseOrderController.php back-end/app/Http/Controllers/Api/IssuanceOrderController.php back-end/app/Http/Controllers/Api/ReceivingController.php back-end/tests/Feature/Aircon/ frontend/src/admin/pages/
git commit -m "feat(aircon): group aircon lines on purchase orders, issuances and receiving"
```

---

## Task 15: Convert a legacy single-row aircon into a pair

**Files:**
- Create: `back-end/app/Http/Controllers/Api/AirconConversionController.php`
- Modify: `back-end/routes/api.php`
- Modify: `frontend/src/admin/pages/ItemManagement.jsx` (row action)
- Test: `back-end/tests/Feature/Aircon/AirconConversionTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Aircon/AirconConversionTest.php`:

```php
<?php

namespace Tests\Feature\Aircon;

use App\Models\ApplianceDetail;
use App\Models\Product;

/**
 * The original product row KEEPS its product_id and becomes one of the two
 * sides. Every serial, inventory row, purchase order line, issuance line and
 * warranty record already pointing at it therefore stays valid — which is the
 * whole reason conversion is offered instead of an automatic split of all 117
 * legacy rows.
 */
class AirconConversionTest extends AirconTestCase
{
    private function legacyAircon(float $price = 30000.00): Product
    {
        $product = Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Legacy Split ' . uniqid(),
            'product_type' => 'appliance',
            'description'  => 'a split-type air conditioner',
            'unit_price'   => $price,
        ]);

        ApplianceDetail::create([
            'product_id'    => $product->product_id,
            'unit_role'     => 'complete',
            'ac_type_id'    => $this->splitType()->ac_type_id,
            'is_serialized' => true,
        ]);

        return $product->fresh();
    }

    public function test_conversion_preserves_the_original_product_id(): void
    {
        $this->actingAsAdmin();

        $legacy = $this->legacyAircon();

        $response = $this->postJson("/api/products/{$legacy->product_id}/convert-to-two-piece", [
            'indoor'  => ['model_code' => 'IN-001',  'unit_price' => 12000.00],
            'outdoor' => ['model_code' => 'OUT-001', 'unit_price' => 18000.00],
            'keep_as' => 'indoor',
        ]);

        $response->assertStatus(201);

        $this->assertNotNull(Product::find($legacy->product_id));
        $this->assertSame('indoor', ApplianceDetail::where('product_id', $legacy->product_id)->value('unit_role'));
    }

    public function test_conversion_creates_the_partner_product(): void
    {
        $this->actingAsAdmin();

        $legacy = $this->legacyAircon();

        $data = $this->postJson("/api/products/{$legacy->product_id}/convert-to-two-piece", [
            'indoor'  => ['model_code' => 'IN-002',  'unit_price' => 12000.00],
            'outdoor' => ['model_code' => 'OUT-002', 'unit_price' => 18000.00],
            'keep_as' => 'indoor',
        ])->json('data');

        $this->assertCount(2, $data['units']);

        $partnerId = collect($data['units'])->firstWhere('unit_role', 'outdoor')['product_id'];

        $this->assertNotSame($legacy->product_id, $partnerId);
        $this->assertSame(18000.0, (float) Product::find($partnerId)->unit_price);
    }

    public function test_the_split_prices_must_sum_to_the_original(): void
    {
        $this->actingAsAdmin();

        $legacy = $this->legacyAircon(30000.00);

        $this->postJson("/api/products/{$legacy->product_id}/convert-to-two-piece", [
            'indoor'  => ['model_code' => 'IN-003',  'unit_price' => 10000.00],
            'outdoor' => ['model_code' => 'OUT-003', 'unit_price' => 10000.00],
            'keep_as' => 'indoor',
        ])->assertStatus(422)->assertJsonValidationErrors('indoor.unit_price');
    }

    public function test_a_product_already_converted_cannot_be_converted_again(): void
    {
        $this->actingAsAdmin();

        $legacy = $this->legacyAircon();

        $payload = [
            'indoor'  => ['model_code' => 'IN-004',  'unit_price' => 12000.00],
            'outdoor' => ['model_code' => 'OUT-004', 'unit_price' => 18000.00],
            'keep_as' => 'indoor',
        ];

        $this->postJson("/api/products/{$legacy->product_id}/convert-to-two-piece", $payload)->assertStatus(201);
        $this->postJson("/api/products/{$legacy->product_id}/convert-to-two-piece", $payload)->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=AirconConversionTest
```

Expected: FAIL — 404, no route.

- [ ] **Step 3: Write the controller**

Create `back-end/app/Http/Controllers/Api/AirconConversionController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApplianceDetail;
use App\Models\Product;
use App\Services\AirconModelComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Converts a legacy single-row aircon into an indoor/outdoor pair plus the
 * model that groups them.
 *
 * The original row keeps its product_id and becomes whichever side the caller
 * names in keep_as. Creating two fresh products and archiving the original
 * would orphan every serial, inventory row and document line pointing at it.
 */
class AirconConversionController extends Controller
{
    public function __construct(private AirconModelComposer $composer)
    {
    }

    public function convert(Request $request, int $productId): JsonResponse
    {
        $product = Product::with('applianceDetail')->findOrFail($productId);

        $detail = $product->applianceDetail;

        if ($detail === null) {
            throw ValidationException::withMessages([
                'product' => 'Only appliances can be converted.',
            ]);
        }

        if ($detail->unit_role !== 'complete') {
            throw ValidationException::withMessages([
                'product' => 'This product is already an indoor or outdoor unit.',
            ]);
        }

        $validated = $request->validate([
            'indoor.model_code'  => 'required|string|max:100',
            'indoor.unit_price'  => 'required|numeric|min:0',
            'outdoor.model_code' => 'required|string|max:100',
            'outdoor.unit_price' => 'required|numeric|min:0',
            'keep_as'            => 'required|in:indoor,outdoor',
            'model_name'         => 'nullable|string|max:191',
        ]);

        // The split has to account for the whole price. Anything else silently
        // changes what the catalogue says this aircon costs.
        $sum = round((float) $validated['indoor']['unit_price'] + (float) $validated['outdoor']['unit_price'], 2);
        $original = round((float) $product->unit_price, 2);

        if ($sum !== $original) {
            throw ValidationException::withMessages([
                'indoor.unit_price' => "The two prices must sum to the original {$original}; they sum to {$sum}.",
            ]);
        }

        $keep    = $validated['keep_as'];
        $partner = $keep === 'indoor' ? 'outdoor' : 'indoor';

        $model = DB::transaction(function () use ($product, $detail, $validated, $keep, $partner) {
            $product->update(['unit_price' => $validated[$keep]['unit_price']]);
            $detail->update([
                'unit_role'  => $keep,
                'model_code' => $validated[$keep]['model_code'],
            ]);

            $partnerProduct = Product::create([
                'category_id'        => $product->category_id,
                'product_code'       => $product->product_code . '-' . strtoupper(substr($partner, 0, 3)),
                'product_name'       => $product->product_name . ' (' . ucfirst($partner) . ' Unit)',
                'product_type'       => 'appliance',
                'description'        => $product->description,
                'unit_price'         => $validated[$partner]['unit_price'],
                'cost_price'         => 0,
                'status_id'          => $product->status_id,
                'recommended_stocks' => $product->recommended_stocks,
                'safety_stock'       => $product->safety_stock,
                'max_stock_level'    => $product->max_stock_level,
            ]);

            ApplianceDetail::create([
                'product_id'             => $partnerProduct->product_id,
                'brand_id'               => $detail->brand_id,
                'model_code'             => $validated[$partner]['model_code'],
                'capacity_rating'        => $detail->capacity_rating,
                'variant'                => $detail->variant,
                'ac_type_id'             => $detail->ac_type_id,
                'unit_role'              => $partner,
                'color'                  => $detail->color,
                'warranty_period_months' => $detail->warranty_period_months,
                'warranty_value'         => $detail->warranty_value,
                'warranty_unit'          => $detail->warranty_unit,
                'is_serialized'          => $detail->is_serialized,
            ]);

            return $this->composer->create([
                'model_name'      => $validated['model_name'] ?? $product->product_name,
                'brand_id'        => $detail->brand_id,
                'ac_type_id'      => $detail->ac_type_id,
                'capacity_rating' => $detail->capacity_rating,
                'variant'         => $detail->variant,
                'is_two_piece'    => true,
            ], [
                ['product_id' => $product->product_id,        'quantity' => 1],
                ['product_id' => $partnerProduct->product_id, 'quantity' => 1],
            ]);
        });

        // Reuse AirconModelController::show() rather than duplicating its
        // presenter, so the shape the conversion returns can never drift from
        // the shape the models page already renders.
        return response()->json([
            'success' => true,
            'message' => 'Converted to a two-piece aircon.',
            'data'    => app(AirconModelController::class)
                ->show($model->aircon_model_id)
                ->getData(true)['data'],
        ], 201);
    }
}
```

- [ ] **Step 4: Register the route**

In `back-end/routes/api.php`, beside the aircon-models block, add:

```php
    Route::middleware('permission:aircon_model_management,create')
        ->post('/products/{id}/convert-to-two-piece', [\App\Http\Controllers\Api\AirconConversionController::class, 'convert']);
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=AirconConversionTest
```

Expected: PASS, 4 tests.

- [ ] **Step 6: Add the row action**

In `frontend/src/admin/pages/ItemManagement.jsx`, add a "Convert to two-piece" action to the row menu, shown only when the product is an appliance whose `unit_role` is `complete` and whose `ac_type_id` names a two-piece type. Add a matching `airconModelsAPI.convert` client method:

```js
  convert: async (productId, data) =>
    apiRequest(`/products/${productId}/convert-to-two-piece`, {
      method: 'POST',
      body: JSON.stringify(data),
    }),
```

The modal collects: which side the existing row becomes (`keep_as`), the two model codes, and the two prices. Show the original price and a live sum with a warning when the two do not match, so the user is not told only on submit.

- [ ] **Step 7: Run the whole suite and commit**

```bash
cd back-end && php artisan test
```

```bash
git add back-end/app/Http/Controllers/Api/AirconConversionController.php back-end/routes/api.php back-end/tests/Feature/Aircon/AirconConversionTest.php frontend/src/admin/pages/ItemManagement.jsx frontend/src/admin/services/api.js
git commit -m "feat(aircon): convert a legacy single-row aircon into an indoor/outdoor pair"
```

---

## Final verification

- [ ] **Run everything**

```bash
cd back-end && php artisan test
```

```bash
cd frontend && npx react-scripts test --watchAll=false
```

Both must pass. Report the actual output — do not summarise a run you did not do.

- [ ] **Confirm the spec's testing list is covered**

Walk the "Testing" section of `docs/superpowers/specs/2026-08-25-aircon-product-details-design.md` and confirm each bullet maps to a test written above. The mapping:

| Spec bullet | Test |
| --- | --- |
| `ac_type_lookup` CRUD | `AcTypeLookupTest` |
| Unit role defaulting | `ProductRegisterModal.test.jsx` |
| Model composition | `AirconModelComposerTest`, `AirconModelSchemaTest` |
| Derived header quantity | `AirconLineGrouperTest` |
| Single-side transactions | `AirconLineGrouperTest::test_lines_with_no_model_are_returned_ungrouped` |
| Backfill migration | `AcTypeBackfillTest` |
| Conversion tool | `AirconConversionTest` |

---

## Known follow-up, not in this plan

**Warranty serial pairing.** `warranty_records` still cannot link an indoor serial to its partner outdoor serial, which servicing an installed pair requires. The spec calls this out as its own design with its own open questions — which side carries the warranty term, what happens when one side is replaced under warranty, how a claim against a pair is recorded. Do not improvise it while implementing this plan.
