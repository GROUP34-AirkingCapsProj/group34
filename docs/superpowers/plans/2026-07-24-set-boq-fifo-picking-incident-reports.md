# Set/BOQ Selling, FIFO-Enforced Picking, and Incident Reports — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins define reusable Set (BOQ) records, make issuance list-then-price-then-scan, and enforce batch/zone FIFO on both issuance picking and transfer dispatch with Incident Reports as the only override.

**Architecture:** A new stateless `FifoPickingService` owns the FIFO rule and scan verdict for both documents. The existing `NormalizesBarcode` trait gains a `resolveScan()` method so issuance and transfer resolve barcodes through identical passes. Sets, reservations, and incidents are new tables; `issuances` gains a Draft state ahead of the existing Pending(30).

**Tech Stack:** Laravel 11 (PHP 8.2), MySQL/MariaDB via XAMPP, PHPUnit with `DatabaseTransactions`, React 18 CRA frontend with the `ak-*` design system in `airking_ds.css`.

**Spec:** `docs/superpowers/specs/2026-07-24-set-boq-fifo-picking-incident-reports-design.md`

---

## Conventions You Must Follow

Read this section before Task 1. It encodes things about this codebase that are not obvious.

**Paths.** Backend root is `C:\XAMPP1\htdocs\Airking_Inventory\back-end`. Frontend root is `C:\XAMPP1\htdocs\Airking_Inventory\frontend`. All backend commands run from the backend root. All paths in this plan are relative to those roots.

**Migrations.** This repo's schema is a SQL dump, not a full migration history, so a bare `php artisan migrate` **fails**. Apply one migration at a time with `--path`. Local `.env` has `APP_ENV=production`, so `--force` is always required:

```bash
php artisan migrate --force --path=database/migrations/<file>.php
DB_DATABASE=airkingV5_test php artisan migrate --force --path=database/migrations/<file>.php
```

Every migration must be **idempotent** — guard with `Schema::hasTable()` / `Schema::hasColumn()` and return early. Follow the style of `database/migrations/2026_07_21_000003_add_zone_label_to_inventory_batches_table.php`.

**Tests.** `phpunit.xml` already points at `airkingV5_test`, so `php artisan test` needs no env prefix. Tests use `Tests\TestCase` + `Illuminate\Foundation\Testing\DatabaseTransactions` — **never** `RefreshDatabase`, which would wipe the dump-loaded schema. Run a single test with:

```bash
php artisan test --filter=test_method_name
```

**Test data helpers.** `tests/Feature/Transfer/TransferTestCase.php` already has `makeLocation`, `makeUserAt`, `makeConsumable`, `makeAppliance`, `makeBatch`, `makeSerial`, `setStock`, `makeApprovedTransfer`, `statusId`. Task 1 creates a parallel base class for the new tests rather than importing across namespaces.

**Roles.** `admin=1, inventory_analyst=2, branch_manager=3, warehouse_personnel=4, auditor=5`. Admin bypasses `CheckPermission` via `PermissionResolver`. Authenticate in tests with `$this->actingAs($user, 'sanctum')`.

**Permission naming.** The spec used `issuances` as a permission feature; the real feature slug in `PermissionSeeder::matrix()` is **`inventory_issuance`**. This plan uses the real slugs: `inventory_issuance` (gains a `view_margin` action), plus two new features `product_set_management` and `incident_reports`. Middleware form is `permission:<feature>,<action>`.

**Table naming gotcha.** The `ConsumableDetail` model maps to table **`installation_material_details`** (renamed 2026-07-08). Don't assume the table name from the model name.

**Serials have no `batch_id`.** An `item_serial` links to its batch through `receiving_id` → `inventory_batches.receiving_id` scoped by `location_id`. This is exactly how `TransferService::pickList()` does it today; keep that join.

**Commits.** Every task ends with a commit. Use conventional-commit prefixes (`feat:`, `test:`, `refactor:`, `docs:`). Do not push.

---

## File Structure

**New backend files**

| File | Responsibility |
|---|---|
| `app/Services/FifoPickingService.php` | The FIFO rule. `suggest()` + `validateScan()`. No DB writes. |
| `app/Traits/NormalizesBarcode.php` (**modify**, not create) | Gains `resolveScan()` — the 3-pass serial → product-barcode → receiving-barcode sequence, lifted out of `IssuanceScanController`. The trait already owns `barcodeLookupCandidates()` and `findProductByBarcodeCandidates()` and is already used by 5 call sites, so a new resolver service would only duplicate it. |
| `app/Services/IssuanceOrderService.php` | Draft line building: add set, add custom, edit, remove, reprice. |
| `app/Services/ReservationService.php` | Write/release `pick_reservations` and keep `inventory.available_quantity` in step. |
| `app/Services/IncidentService.php` | File an incident, compute FIFO exclusions, resolve into adjustment/correction/zone-update. |
| `app/Models/ProductSet.php`, `ProductSetLine.php`, `IssuanceSet.php`, `PickReservation.php`, `IncidentReport.php` | Eloquent models. |
| `app/Http/Controllers/Api/ProductSetController.php` | Set CRUD + `suggest`. |
| `app/Http/Controllers/Api/IssuanceOrderController.php` | Draft lines, quote, confirm-quote, pick-plan. |
| `app/Http/Controllers/Api/IncidentReportController.php` | File, list, resolve. |
| `app/Support/ScanResult.php` | Value object returned by `validateScan()`. |

**New frontend files**

| File | Responsibility |
|---|---|
| `src/admin/pages/SetManagementPage.jsx` | Set list + builder modal. |
| `src/admin/pages/IncidentReportsPage.jsx` | Triage list + resolve drawer. |
| `src/admin/components/picking/PickScanner.jsx` | Shared guided scanner; renders any `ScanResult`. |
| `src/admin/components/picking/IncidentModal.jsx` | File-incident form, shared. |
| `src/admin/components/issuance/OrderBuilder.jsx` | Step 1 — set/custom line building. |
| `src/admin/components/issuance/QuotePanel.jsx` | Step 2 — priced review + confirm gate. |

**Modified backend files**

- `app/Http/Controllers/Api/IssuanceScanController.php` — delegate barcode resolution to `BarcodeResolver`; replace the advisory `fifoWarningFor()` with an enforcing `validateScan()` call; reject non-Pending orders.
- `app/Services/TransferService.php:58` — `pickList()` becomes a thin delegate to `FifoPickingService::suggest()`.
- `app/Http/Controllers/Api/TransferController.php` — dispatch scan runs `validateScan()`.
- `app/Models/Issuance.php`, `IssuanceDetail.php` — new fillable/casts and relations.
- `routes/api.php`, `database/seeders/PermissionSeeder.php`, `database/seeders/NavBundleSeeder.php`.

**Modified frontend files**

- `src/admin/pages/IssuanceScanPage.jsx` — becomes the 3-step wizard shell.
- `src/admin/pages/transfers/` dispatch panel — swap in `PickScanner`.
- `src/admin/components/Sidebar.jsx`, `src/admin/services/api.js`, `src/admin/utils/roles.js`.

---

## Phase 1 — Sets (Tasks 1–6)

### Task 1: Set tables + shared test base

**Files:**
- Create: `database/migrations/2026_07_24_000001_create_product_sets_tables.php`
- Create: `tests/Feature/Sets/SetTestCase.php`
- Test: `tests/Feature/Sets/SetSchemaTest.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_sets')) {
            Schema::create('product_sets', function (Blueprint $table) {
                $table->id('set_id');
                $table->string('set_code', 60)->unique();
                $table->string('set_name', 191);
                $table->text('description')->nullable();
                $table->unsignedBigInteger('brand_id')->nullable();
                $table->string('capacity_rating', 60)->nullable();
                // 100 to match appliance_details.variant exactly — this column is
                // compared to it for equality, so a narrower width would silently
                // truncate a long variant label and break set matching with no error.
                $table->string('variant', 100)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['brand_id', 'capacity_rating', 'variant'], 'product_sets_match_idx');
                $table->index('is_active');

                $table->foreign('brand_id')->references('brand_id')->on('brand_lookup')->onDelete('set null');
                $table->foreign('created_by')->references('user_id')->on('users')->onDelete('set null');
                $table->foreign('updated_by')->references('user_id')->on('users')->onDelete('set null');
            });
        }

        if (! Schema::hasTable('product_set_lines')) {
            Schema::create('product_set_lines', function (Blueprint $table) {
                $table->id('set_line_id');
                $table->unsignedBigInteger('set_id');
                $table->unsignedBigInteger('product_id');
                $table->decimal('quantity', 10, 3);
                $table->unsignedBigInteger('issuable_unit_id')->nullable();
                $table->string('spec_note', 191)->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->index('set_id');
                $table->index('product_id');

                // Lines are meaningless without their header, so they follow it out.
                $table->foreign('set_id')->references('set_id')->on('product_sets')->cascadeOnDelete();
                // A product in use by a BOQ must not vanish underneath it.
                $table->foreign('product_id')->references('product_id')->on('products')->restrictOnDelete();
                $table->foreign('issuable_unit_id')->references('issuable_unit_id')
                      ->on('product_issuable_units')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_set_lines');
        Schema::dropIfExists('product_sets');
    }
};
```

Every referenced column is `bigint(20) unsigned`, so these constraints apply cleanly. Later tasks that create tables referencing `products`, `users`, `locations`, or `inventory_batches` should declare foreign keys the same way — this repo's migrations do so consistently, and the plan text elsewhere omits them for brevity.

- [ ] **Step 2: Apply to both databases**

```bash
php artisan migrate --force --path=database/migrations/2026_07_24_000001_create_product_sets_tables.php
DB_DATABASE=airkingV5_test php artisan migrate --force --path=database/migrations/2026_07_24_000001_create_product_sets_tables.php
```

Expected: `INFO Running migrations.` then a `DONE` line for the file, twice.

- [ ] **Step 3: Write the shared test base**

Create `tests/Feature/Sets/SetTestCase.php`. Later phases extend this too, so it carries every helper the new tests need.

```php
<?php

namespace Tests\Feature\Sets;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use App\Services\InventoryBatchService;
use Tests\TestCase;

abstract class SetTestCase extends TestCase
{
    use DatabaseTransactions;

    protected function makeLocation(string $name = 'Main WH'): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => $name,
            'location_type' => 'warehouse',
        ]);
    }

    protected function makeUserAt(Location $location, string $role = 'warehouse_personnel'): User
    {
        $map = ['admin' => 1, 'inventory_analyst' => 2, 'branch_manager' => 3, 'warehouse_personnel' => 4, 'auditor' => 5];
        return User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'T',
            'last_name'     => 'U',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $map[$role] ?? 4,
            'location_id'   => $location->location_id,
        ]);
    }

    protected function makeConsumable(string $barcode = null, float $price = 50, float $cost = 30): Product
    {
        return Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'Test Material',
            'product_type' => 'installation_material',
            'barcode'      => $barcode ?? ('BC-' . uniqid()),
            'unit_price'   => $price,
            'cost_price'   => $cost,
        ]);
    }

    protected function makeAppliance(string $barcode = null, float $price = 30000, float $cost = 24000): Product
    {
        return Product::create([
            'product_code' => 'APP-' . uniqid(),
            'product_name' => 'Test Aircon',
            'product_type' => 'appliance',
            'barcode'      => $barcode ?? ('BC-' . uniqid()),
            'unit_price'   => $price,
            'cost_price'   => $cost,
        ]);
    }

    protected function makeReceiving(int $locationId): Receiving
    {
        return Receiving::create([
            'location_id'      => $locationId,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now(),
        ]);
    }

    /** Batch with a real receiving. $attrs: quantity_remaining, received_at, zone_label. */
    protected function makeBatch(Product $product, Location $location, array $attrs = []): InventoryBatch
    {
        $rcv = $this->makeReceiving($location->location_id);
        return app(InventoryBatchService::class)->createBatch(
            $product->product_id,
            $location->location_id,
            $rcv->receiving_id,
            $attrs['quantity_remaining'] ?? 10,
            $attrs['received_at'] ?? now(),
            $attrs['zone_label'] ?? null
        );
    }

    protected function makeSerial(Product $product, Location $location, string $sn, ?int $receivingId = null): ItemSerial
    {
        return ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $location->location_id,
            'receiving_id'  => $receivingId,
            'serial_number' => $sn,
            'serial_type'   => 'received',
        ]);
    }

    protected function setStock(int $locationId, int $productId, int $qty): Inventory
    {
        return Inventory::create([
            'location_id'        => $locationId,
            'product_id'         => $productId,
            'quantity_on_hand'   => $qty,
            'available_quantity' => $qty,
            'reorder_level'      => 0,
        ]);
    }

    protected function makeCustomer(): Customer
    {
        return Customer::create([
            'customer_name' => 'Test Customer ' . uniqid(),
            'customer_type' => 'individual',
        ]);
    }

    protected function issuanceStatusId(string $name): int
    {
        $id = DB::table('status_lookup')
            ->where('status_category', 'issuance')
            ->where('status_name', $name)
            ->value('status_id');
        if ($id === null) {
            throw new \RuntimeException("Unknown issuance status: {$name}");
        }
        return (int) $id;
    }
}
```

- [ ] **Step 4: Write the schema test**

Create `tests/Feature/Sets/SetSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Sets;

use Illuminate\Support\Facades\Schema;

class SetSchemaTest extends SetTestCase
{
    public function test_product_set_tables_exist_with_matching_columns(): void
    {
        $this->assertTrue(Schema::hasTable('product_sets'));
        $this->assertTrue(Schema::hasTable('product_set_lines'));

        foreach (['set_code', 'set_name', 'brand_id', 'capacity_rating', 'variant', 'is_active', 'deleted_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('product_sets', $col), "product_sets missing {$col}");
        }
        foreach (['set_id', 'product_id', 'quantity', 'issuable_unit_id', 'spec_note', 'sort_order'] as $col) {
            $this->assertTrue(Schema::hasColumn('product_set_lines', $col), "product_set_lines missing {$col}");
        }
    }
}
```

- [ ] **Step 5: Run the test**

Run: `php artisan test --filter=test_product_set_tables_exist_with_matching_columns`
Expected: PASS, 1 test.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_24_000001_create_product_sets_tables.php tests/Feature/Sets/
git commit -m "feat(sets): product_sets and product_set_lines tables"
```

---

### Task 2: ProductSet and ProductSetLine models

**Files:**
- Create: `app/Models/ProductSet.php`, `app/Models/ProductSetLine.php`
- Test: `tests/Feature/Sets/ProductSetModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Sets;

use App\Models\ProductSet;

class ProductSetModelTest extends SetTestCase
{
    public function test_set_computes_price_from_its_lines(): void
    {
        $a = $this->makeConsumable(price: 100);
        $b = $this->makeConsumable(price: 25);

        $set = ProductSet::create([
            'set_code' => 'SET-' . uniqid(),
            'set_name' => 'Std Installation Materials',
        ]);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 2, 'sort_order' => 1]);
        $set->lines()->create(['product_id' => $b->product_id, 'quantity' => 4, 'sort_order' => 2]);

        $this->assertSame(2, $set->fresh('lines')->lines->count());
        $this->assertEqualsWithDelta(300.00, $set->fresh('lines')->setPrice(), 0.001);
    }

    public function test_deleting_a_set_removes_its_lines(): void
    {
        $p = $this->makeConsumable();
        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Temp']);
        $set->lines()->create(['product_id' => $p->product_id, 'quantity' => 1]);

        $setId = $set->set_id;
        $set->forceDelete();

        $this->assertSame(0, \App\Models\ProductSetLine::where('set_id', $setId)->count());
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --filter=ProductSetModelTest`
Expected: FAIL — `Class "App\Models\ProductSet" not found`.

- [ ] **Step 3: Write `app/Models/ProductSet.php`**

```php
<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductSet extends Model
{
    use SoftDeletes, Auditable;

    protected $table      = 'product_sets';
    protected $primaryKey = 'set_id';

    protected $fillable = [
        'set_code', 'set_name', 'description',
        'brand_id', 'capacity_rating', 'variant',
        'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Lines are meaningless without their header; cascade on both soft and hard delete.
        static::deleting(function (ProductSet $set) {
            $set->lines()->delete();
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProductSetLine::class, 'set_id', 'set_id')->orderBy('sort_order');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(BrandLookup::class, 'brand_id', 'brand_id');
    }

    /** Sum of line quantity x product unit_price. Requires `lines.product` to be loaded or loadable. */
    public function setPrice(): float
    {
        $this->loadMissing('lines.product');

        return round($this->lines->sum(
            fn (ProductSetLine $line) => (float) $line->quantity * (float) ($line->product?->unit_price ?? 0)
        ), 2);
    }
}
```

- [ ] **Step 4: Write `app/Models/ProductSetLine.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSetLine extends Model
{
    protected $table      = 'product_set_lines';
    protected $primaryKey = 'set_line_id';

    protected $fillable = [
        'set_id', 'product_id', 'quantity',
        'issuable_unit_id', 'spec_note', 'sort_order',
    ];

    protected $casts = [
        'quantity'   => 'float',
        'sort_order' => 'integer',
    ];

    public function set(): BelongsTo
    {
        return $this->belongsTo(ProductSet::class, 'set_id', 'set_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function issuableUnit(): BelongsTo
    {
        return $this->belongsTo(ProductIssuableUnit::class, 'issuable_unit_id', 'issuable_unit_id');
    }

    /**
     * Quantity expressed in base pieces, applying the issuable unit's conversion_factor.
     * A line with no issuable unit is already in pieces.
     */
    public function quantityInPieces(): int
    {
        $factor = (float) ($this->issuableUnit?->conversion_factor ?? 1);
        if ($factor <= 0) {
            $factor = 1;
        }

        return (int) ceil((float) $this->quantity * $factor);
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=ProductSetModelTest`
Expected: PASS, 2 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Models/ProductSet.php app/Models/ProductSetLine.php tests/Feature/Sets/ProductSetModelTest.php
git commit -m "feat(sets): ProductSet and ProductSetLine models with price and piece conversion"
```

---

### Task 3: Unit conversion on set lines

**Files:**
- Test: `tests/Feature/Sets/SetLineConversionTest.php`

This task has no new production code — it locks down `quantityInPieces()` against the real `product_issuable_units` table, which is what makes the BOQ's "2 L" and "20 ft" entries usable for stock math. If it fails, fix `ProductSetLine::quantityInPieces()`.

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Sets;

use App\Models\ProductIssuableUnit;
use App\Models\ProductSet;
use Illuminate\Support\Facades\DB;

class SetLineConversionTest extends SetTestCase
{
    public function test_line_quantity_converts_to_pieces_via_issuable_unit(): void
    {
        $product = $this->makeConsumable();
        $unitId  = (int) DB::table('unit_lookup')->min('unit_id');

        // 1 "Roll" = 50 pieces/metres of base unit.
        $roll = ProductIssuableUnit::create([
            'product_id'        => $product->product_id,
            'unit_id'           => $unitId,
            'label'             => 'Roll',
            'conversion_factor' => 50,
            'is_whole_package'  => true,
            'sort_order'        => 1,
        ]);

        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Piping']);
        $line = $set->lines()->create([
            'product_id'       => $product->product_id,
            'quantity'         => 2,
            'issuable_unit_id' => $roll->issuable_unit_id,
        ]);

        $this->assertSame(100, $line->fresh()->load('issuableUnit')->quantityInPieces());
    }

    public function test_line_without_issuable_unit_is_already_pieces(): void
    {
        $product = $this->makeConsumable();
        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Bolts']);
        $line = $set->lines()->create(['product_id' => $product->product_id, 'quantity' => 7]);

        $this->assertSame(7, $line->fresh()->quantityInPieces());
    }

    public function test_fractional_quantity_rounds_up_to_whole_pieces(): void
    {
        $product = $this->makeConsumable();
        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Angle bar']);
        // BOQ line "Angle bar 1.5L" with no conversion unit.
        $line = $set->lines()->create(['product_id' => $product->product_id, 'quantity' => 1.5]);

        $this->assertSame(2, $line->fresh()->quantityInPieces());
    }
}
```

- [ ] **Step 2: Run it**

Run: `php artisan test --filter=SetLineConversionTest`
Expected: PASS, 3 tests. If `test_fractional_quantity_rounds_up_to_whole_pieces` fails, `quantityInPieces()` is not using `ceil()` — fix it.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Sets/SetLineConversionTest.php
git commit -m "test(sets): lock down issuable-unit piece conversion on set lines"
```

---

### Task 4: Set CRUD endpoints + permissions

**Files:**
- Create: `app/Http/Controllers/Api/ProductSetController.php`
- Modify: `routes/api.php`, `database/seeders/PermissionSeeder.php`
- Test: `tests/Feature/Sets/ProductSetCrudTest.php`

- [ ] **Step 1: Add the permission feature to `PermissionSeeder::matrix()`**

Insert this entry into the array returned by `matrix()`, next to the other inventory features:

```php
            // Set/BOQ catalog (/admin/sets) — admin maintains, IA and warehouse read
            // so the issuance picker can list sets.
            'product_set_management' => [
                'read'    => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'create'  => ['admin'],
                'update'  => ['admin'],
                'archive' => ['admin'],
            ],
```

- [ ] **Step 2: Run the seeder against both databases**

```bash
php artisan db:seed --force --class=PermissionSeeder
php artisan db:seed --force --class=RolePermissionsSeeder
DB_DATABASE=airkingV5_test php artisan db:seed --force --class=PermissionSeeder
DB_DATABASE=airkingV5_test php artisan db:seed --force --class=RolePermissionsSeeder
```

Expected: `INFO Seeding database.` with no errors. If a permission-matrix test exists and now fails, it is asserting the old matrix — update its expectation to include `product_set_management`.

- [ ] **Step 3: Write the failing test**

```php
<?php

namespace Tests\Feature\Sets;

use App\Models\ProductSet;

class ProductSetCrudTest extends SetTestCase
{
    public function test_admin_creates_a_set_with_lines(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');
        $a     = $this->makeConsumable(price: 100);
        $b     = $this->makeConsumable(price: 25);

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/product-sets', [
            'set_code'        => 'BOQ-3TR-NONINV',
            'set_name'        => 'Std Installation Materials — 1st 10ft Piping',
            'capacity_rating' => '3TR/4HP',
            'variant'         => 'non-inverter',
            'lines'           => [
                ['product_id' => $a->product_id, 'quantity' => 2, 'spec_note' => '3/8 x 1/2 thck', 'sort_order' => 1],
                ['product_id' => $b->product_id, 'quantity' => 4, 'sort_order' => 2],
            ],
        ]);

        $res->assertCreated();
        $this->assertSame(2, count($res->json('data.lines')));
        $this->assertEqualsWithDelta(300.0, (float) $res->json('data.set_price'), 0.001);
    }

    public function test_warehouse_personnel_cannot_create_a_set(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');

        $this->actingAs($user, 'sanctum')
             ->postJson('/api/product-sets', ['set_code' => 'X-' . uniqid(), 'set_name' => 'Nope', 'lines' => []])
             ->assertForbidden();
    }

    public function test_warehouse_personnel_can_list_sets(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Listable']);

        $this->actingAs($user, 'sanctum')->getJson('/api/product-sets')->assertOk();
    }

    public function test_update_replaces_the_line_list_wholesale(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');
        $a     = $this->makeConsumable();
        $c     = $this->makeConsumable();

        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Editable']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 1]);

        $res = $this->actingAs($admin, 'sanctum')->putJson("/api/product-sets/{$set->set_id}", [
            'set_name' => 'Edited',
            'lines'    => [['product_id' => $c->product_id, 'quantity' => 9, 'sort_order' => 1]],
        ]);

        $res->assertOk();
        $lines = $res->json('data.lines');
        $this->assertCount(1, $lines);
        $this->assertSame($c->product_id, (int) $lines[0]['product_id']);
        $this->assertEqualsWithDelta(9.0, (float) $lines[0]['quantity'], 0.001);
    }

    public function test_duplicate_set_code_is_rejected(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');
        ProductSet::create(['set_code' => 'DUPE-CODE', 'set_name' => 'First']);

        $this->actingAs($admin, 'sanctum')
             ->postJson('/api/product-sets', ['set_code' => 'DUPE-CODE', 'set_name' => 'Second', 'lines' => []])
             ->assertStatus(422);
    }
}
```

- [ ] **Step 4: Run it to confirm it fails**

Run: `php artisan test --filter=ProductSetCrudTest`
Expected: FAIL — 404s, because the routes do not exist.

- [ ] **Step 5: Write `app/Http/Controllers/Api/ProductSetController.php`**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductSet;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductSetController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = ProductSet::with(['lines.product', 'lines.issuableUnit', 'brand']);

        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('set_name', 'like', "%{$search}%")
                  ->orWhere('set_code', 'like', "%{$search}%");
            });
        }

        $sets = $query->orderBy('set_name')->get();

        return response()->json([
            'success' => true,
            'data'    => $sets->map(fn (ProductSet $s) => $this->present($s))->all(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $set = ProductSet::with(['lines.product', 'lines.issuableUnit', 'brand'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->present($set)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, null);

        $set = DB::transaction(function () use ($validated) {
            $set = ProductSet::create([
                'set_code'        => $validated['set_code'],
                'set_name'        => $validated['set_name'],
                'description'     => $validated['description'] ?? null,
                'brand_id'        => $validated['brand_id'] ?? null,
                'capacity_rating' => $validated['capacity_rating'] ?? null,
                'variant'         => $validated['variant'] ?? null,
                'is_active'       => $validated['is_active'] ?? true,
                'created_by'      => Auth::id(),
                'updated_by'      => Auth::id(),
            ]);

            $this->syncLines($set, $validated['lines'] ?? []);

            return $set;
        });

        return response()->json([
            'success' => true,
            'message' => 'Set created.',
            'data'    => $this->present($set->fresh(['lines.product', 'lines.issuableUnit', 'brand'])),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $set       = ProductSet::findOrFail($id);
        $validated = $this->validatePayload($request, $id);

        DB::transaction(function () use ($set, $validated) {
            $set->update(array_filter([
                'set_code'        => $validated['set_code']        ?? null,
                'set_name'        => $validated['set_name']        ?? null,
                'description'     => $validated['description']     ?? null,
                'brand_id'        => $validated['brand_id']        ?? null,
                'capacity_rating' => $validated['capacity_rating'] ?? null,
                'variant'         => $validated['variant']         ?? null,
                'updated_by'      => Auth::id(),
            ], fn ($v) => $v !== null) + ['is_active' => $validated['is_active'] ?? $set->is_active]);

            // Lines are replaced wholesale — the builder UI always posts the full list.
            if (array_key_exists('lines', $validated)) {
                $set->lines()->forceDelete();
                $this->syncLines($set, $validated['lines']);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Set updated.',
            'data'    => $this->present($set->fresh(['lines.product', 'lines.issuableUnit', 'brand'])),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        ProductSet::findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'Set archived.']);
    }

    private function validatePayload(Request $request, ?int $ignoreId): array
    {
        return $request->validate([
            'set_code'        => [
                $ignoreId === null ? 'required' : 'sometimes',
                'string', 'max:60',
                Rule::unique('product_sets', 'set_code')->ignore($ignoreId, 'set_id')->whereNull('deleted_at'),
            ],
            'set_name'        => [$ignoreId === null ? 'required' : 'sometimes', 'string', 'max:191'],
            'description'     => 'nullable|string|max:2000',
            'brand_id'        => 'nullable|integer|exists:brand_lookup,brand_id',
            'capacity_rating' => 'nullable|string|max:60',
            'variant'         => 'nullable|string|max:60',
            'is_active'       => 'nullable|boolean',
            'lines'                     => 'sometimes|array',
            'lines.*.product_id'        => 'required|integer|exists:products,product_id',
            'lines.*.quantity'          => 'required|numeric|min:0.001',
            'lines.*.issuable_unit_id'  => 'nullable|integer|exists:product_issuable_units,issuable_unit_id',
            'lines.*.spec_note'         => 'nullable|string|max:191',
            'lines.*.sort_order'        => 'nullable|integer',
        ]);
    }

    private function syncLines(ProductSet $set, array $lines): void
    {
        foreach (array_values($lines) as $i => $line) {
            $set->lines()->create([
                'product_id'       => $line['product_id'],
                'quantity'         => $line['quantity'],
                'issuable_unit_id' => $line['issuable_unit_id'] ?? null,
                'spec_note'        => $line['spec_note'] ?? null,
                'sort_order'       => $line['sort_order'] ?? ($i + 1),
            ]);
        }
    }

    private function present(ProductSet $set): array
    {
        return [
            'set_id'          => (int) $set->set_id,
            'set_code'        => $set->set_code,
            'set_name'        => $set->set_name,
            'description'     => $set->description,
            'brand_id'        => $set->brand_id !== null ? (int) $set->brand_id : null,
            'brand_name'      => $set->brand?->brand_name,
            'capacity_rating' => $set->capacity_rating,
            'variant'         => $set->variant,
            'is_active'       => (bool) $set->is_active,
            'set_price'       => $set->setPrice(),
            'lines'           => $set->lines->map(fn ($line) => [
                'set_line_id'        => (int) $line->set_line_id,
                'product_id'         => (int) $line->product_id,
                'product_name'       => $line->product?->product_name,
                'product_type'       => $line->product?->product_type,
                'quantity'           => (float) $line->quantity,
                'issuable_unit_id'   => $line->issuable_unit_id !== null ? (int) $line->issuable_unit_id : null,
                'unit_label'         => $line->issuableUnit?->label,
                'quantity_in_pieces' => $line->quantityInPieces(),
                'spec_note'          => $line->spec_note,
                'sort_order'         => (int) $line->sort_order,
                'unit_price'         => (float) ($line->product?->unit_price ?? 0),
                'line_total'         => round((float) $line->quantity * (float) ($line->product?->unit_price ?? 0), 2),
            ])->all(),
        ];
    }
}
```

- [ ] **Step 6: Register routes in `routes/api.php`**

Add inside the authenticated group, near the issuance routes (around line 611):

```php
    /*
    |--------------------------------------------------------------------------
    | Product Sets (BOQ templates)
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:product_set_management,read')->group(function () {
        Route::get('/product-sets', [\App\Http\Controllers\Api\ProductSetController::class, 'index']);
        Route::get('/product-sets/suggest', [\App\Http\Controllers\Api\ProductSetController::class, 'suggest']);
        Route::get('/product-sets/{id}', [\App\Http\Controllers\Api\ProductSetController::class, 'show']);
    });
    Route::middleware('permission:product_set_management,create')
         ->post('/product-sets', [\App\Http\Controllers\Api\ProductSetController::class, 'store']);
    Route::middleware('permission:product_set_management,update')
         ->put('/product-sets/{id}', [\App\Http\Controllers\Api\ProductSetController::class, 'update']);
    Route::middleware('permission:product_set_management,archive')
         ->delete('/product-sets/{id}', [\App\Http\Controllers\Api\ProductSetController::class, 'destroy']);
```

The literal `/product-sets/suggest` route must be declared **before** `/product-sets/{id}`, or `suggest` is swallowed as an id. Task 5 adds the `suggest` method; until then that route 500s, which no test exercises.

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter=ProductSetCrudTest`
Expected: PASS, 5 tests.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api/ProductSetController.php routes/api.php database/seeders/PermissionSeeder.php tests/Feature/Sets/ProductSetCrudTest.php
git commit -m "feat(sets): product set CRUD endpoints with permission gating"
```

---

### Task 5: Set suggestion by brand + capacity + variant

**Files:**
- Modify: `app/Http/Controllers/Api/ProductSetController.php`
- Test: `tests/Feature/Sets/SetSuggestionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Sets;

use App\Models\ApplianceDetail;
use App\Models\ProductSet;
use Illuminate\Support\Facades\DB;

class SetSuggestionTest extends SetTestCase
{
    private function makeAirconWithDetail(?int $brandId, string $capacity, string $variant)
    {
        $product = $this->makeAppliance();
        ApplianceDetail::create([
            'product_id'      => $product->product_id,
            'brand_id'        => $brandId,
            'capacity_rating' => $capacity,
            'variant'         => $variant,
            'is_serialized'   => true,
        ]);

        return $product;
    }

    public function test_exact_brand_capacity_variant_match_scores_highest(): void
    {
        $loc     = $this->makeLocation();
        $user    = $this->makeUserAt($loc, 'warehouse_personnel');
        $brandId = (int) DB::table('brand_lookup')->min('brand_id');

        $exact = ProductSet::create([
            'set_code' => 'S1-' . uniqid(), 'set_name' => 'Exact',
            'brand_id' => $brandId, 'capacity_rating' => '3TR/4HP', 'variant' => 'non-inverter',
        ]);
        ProductSet::create([
            'set_code' => 'S2-' . uniqid(), 'set_name' => 'Capacity only',
            'capacity_rating' => '3TR/4HP',
        ]);
        ProductSet::create([
            'set_code' => 'S3-' . uniqid(), 'set_name' => 'Wrong capacity',
            'brand_id' => $brandId, 'capacity_rating' => '1TR', 'variant' => 'non-inverter',
        ]);

        $aircon = $this->makeAirconWithDetail($brandId, '3TR/4HP', 'non-inverter');

        $res = $this->actingAs($user, 'sanctum')
                    ->getJson('/api/product-sets/suggest?product_id=' . $aircon->product_id);

        $res->assertOk();
        $data = $res->json('data');
        $this->assertNotEmpty($data);
        $this->assertSame((int) $exact->set_id, (int) $data[0]['set_id']);
        // The mismatched-capacity set must not be offered at all.
        $this->assertNotContains('Wrong capacity', array_column($data, 'set_name'));
    }

    public function test_capacity_match_alone_is_still_suggested(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');

        ProductSet::create([
            'set_code' => 'S4-' . uniqid(), 'set_name' => 'Generic 2TR',
            'capacity_rating' => '2TR',
        ]);
        $aircon = $this->makeAirconWithDetail(null, '2TR', 'inverter');

        $res = $this->actingAs($user, 'sanctum')
                    ->getJson('/api/product-sets/suggest?product_id=' . $aircon->product_id);

        $res->assertOk();
        $this->assertContains('Generic 2TR', array_column($res->json('data'), 'set_name'));
    }

    public function test_capacity_comparison_ignores_case_and_spacing(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');

        ProductSet::create([
            'set_code' => 'S5-' . uniqid(), 'set_name' => 'Spaced',
            'capacity_rating' => '3tr / 4hp',
        ]);
        $aircon = $this->makeAirconWithDetail(null, '3TR/4HP', 'non-inverter');

        $res = $this->actingAs($user, 'sanctum')
                    ->getJson('/api/product-sets/suggest?product_id=' . $aircon->product_id);

        $this->assertContains('Spaced', array_column($res->json('data'), 'set_name'));
    }

    public function test_non_appliance_product_returns_empty_suggestions(): void
    {
        $loc     = $this->makeLocation();
        $user    = $this->makeUserAt($loc, 'warehouse_personnel');
        $material = $this->makeConsumable();

        $res = $this->actingAs($user, 'sanctum')
                    ->getJson('/api/product-sets/suggest?product_id=' . $material->product_id);

        $res->assertOk();
        $this->assertSame([], $res->json('data'));
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --filter=SetSuggestionTest`
Expected: FAIL — `Method ... suggest does not exist`.

- [ ] **Step 3: Add `suggest()` to `ProductSetController`**

Insert before `validatePayload()`:

```php
    /**
     * Suggest sets for a chosen appliance, ranked by how well they match.
     * Capacity is the anchor: a set that declares a capacity different from the
     * appliance's is never offered, because a 1TR BOQ on a 3TR unit is wrong.
     * Brand and variant refine the ranking but a set that leaves them null is
     * treated as "applies to any", which is how generic BOQs are authored.
     */
    public function suggest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,product_id',
        ]);

        $product = \App\Models\Product::with('applianceDetail')->find($validated['product_id']);
        $detail  = $product?->applianceDetail;

        if (! $detail) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $norm = fn (?string $v) => $v === null ? null : strtolower(preg_replace('/\s+/', '', $v));

        $wantBrand    = $detail->brand_id !== null ? (int) $detail->brand_id : null;
        $wantCapacity = $norm($detail->capacity_rating);
        $wantVariant  = $norm($detail->variant);

        $sets = ProductSet::with(['lines.product', 'lines.issuableUnit', 'brand'])
            ->where('is_active', true)
            ->get()
            ->filter(function (ProductSet $set) use ($norm, $wantCapacity) {
                $setCapacity = $norm($set->capacity_rating);
                // Null capacity on the set = applies to any capacity.
                return $setCapacity === null || $wantCapacity === null || $setCapacity === $wantCapacity;
            })
            ->map(function (ProductSet $set) use ($norm, $wantBrand, $wantCapacity, $wantVariant) {
                $score = 0;
                if ($wantCapacity !== null && $norm($set->capacity_rating) === $wantCapacity) {
                    $score += 4;
                }
                if ($wantBrand !== null && $set->brand_id !== null && (int) $set->brand_id === $wantBrand) {
                    $score += 2;
                }
                if ($wantVariant !== null && $norm($set->variant) === $wantVariant) {
                    $score += 1;
                }

                return ['set' => $set, 'score' => $score];
            })
            ->sortByDesc(fn ($row) => $row['score'])
            ->values();

        return response()->json([
            'success' => true,
            'data'    => $sets->map(function ($row) {
                return $this->present($row['set']) + ['match_score' => $row['score']];
            })->all(),
        ]);
    }
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --filter=SetSuggestionTest`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/ProductSetController.php tests/Feature/Sets/SetSuggestionTest.php
git commit -m "feat(sets): suggest matching sets from appliance brand, capacity, variant"
```

---

### Task 6: Set Management page

**Files:**
- Create: `frontend/src/admin/pages/SetManagementPage.jsx`
- Modify: `frontend/src/admin/services/api.js`, `frontend/src/admin/App.jsx` (or wherever routes are declared)

- [ ] **Step 1: Add the API client**

In `frontend/src/admin/services/api.js`, following the shape of the existing exported API objects in that file:

```javascript
export const productSetsAPI = {
  list:    (params = {}) => api.get('/product-sets', { params }),
  get:     (id) => api.get(`/product-sets/${id}`),
  create:  (payload) => api.post('/product-sets', payload),
  update:  (id, payload) => api.put(`/product-sets/${id}`, payload),
  archive: (id) => api.delete(`/product-sets/${id}`),
  suggest: (productId) => api.get('/product-sets/suggest', { params: { product_id: productId } }),
};
```

- [ ] **Step 2: Read an existing page to copy its conventions**

Read `frontend/src/admin/pages/CategoryBrandManagement.jsx` end to end before writing. Match how it imports the `ak-*` classes, how it does loading/error state, how it calls the toast and confirm-dialog helpers, and how it reads permissions. Do not invent new patterns.

- [ ] **Step 3: Write `SetManagementPage.jsx`**

The page has two parts.

*Set list.* A `ak-table` of sets: code, name, brand chip, capacity chip, variant chip, line count, computed `set_price` formatted as pesos, and row actions Edit / Duplicate / Archive. A search box bound to the `search` query param. Actions that mutate are hidden unless the user holds `product_set_management.create` / `.update` / `.archive`, read from the same permission hook the other pages use.

*Builder modal.* Header fields — `set_code`, `set_name`, `description`, brand select (from the existing brands endpoint), `capacity_rating` text, `variant` text, `is_active` toggle. Below it a line editor where each row is: product picker (searchable, same component other pages use for product selection), `quantity` number input, issuable-unit select populated from the chosen product's `product_issuable_units` (empty select means "pieces"), `spec_note` text, and a remove button. An "Add line" button appends an empty row. Rows are reorderable; `sort_order` is written from the final array index on save.

The modal computes and displays a running total from `quantity x product.unit_price` so the admin sees the set price while building. On save it posts the whole `lines` array — the API replaces lines wholesale, so partial line updates are not a thing.

*Duplicate* opens the builder pre-filled from an existing set with `set_code` cleared and `set_name` suffixed " (copy)". This exists because inverter and non-inverter BOQs of the same capacity differ by only a few lines.

- [ ] **Step 4: Register the route**

Add the page at `/admin/sets` alongside the other admin routes, gated on `product_set_management.read`.

- [ ] **Step 5: Verify the build**

```bash
cd C:/XAMPP1/htdocs/Airking_Inventory/frontend
npm run build
```

Expected: `Compiled successfully.` Warnings about unused vars are acceptable; errors are not.

- [ ] **Step 6: Manual check**

Run `npm run demo`, log in as admin, open `/admin/sets`, build a set matching the client's BOQ photo (capacity `3TR/4HP`, variant `non-inverter`, a few material lines), save, reopen it, and confirm the lines and total round-trip.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/pages/SetManagementPage.jsx frontend/src/admin/services/api.js frontend/src/admin/App.jsx
git commit -m "feat(sets): Set Management page with BOQ builder"
```

---

## Phase 2 — Draft, Quote, Reservations (Tasks 7–12)

Verified facts this phase depends on: `status_lookup` has columns `status_id, status_name, status_category, is_active, created_at, updated_at`. Existing issuance statuses are **30 Pending, 31 Completed, 32 Voided**. Draft gets a new auto-increment id, so nothing may hardcode it — resolve it by name.

### Task 7: Draft status, order columns, issuance_sets, pick_reservations

**Files:**
- Create: `database/migrations/2026_07_24_000002_add_issuance_order_columns.php`
- Create: `database/migrations/2026_07_24_000003_create_issuance_sets_and_reservations.php`
- Create: `app/Support/IssuanceStatus.php`
- Test: `tests/Feature/Sets/IssuanceOrderSchemaTest.php`

- [ ] **Step 1: Write the columns + status migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('issuance_details', 'issuance_set_id')) {
            Schema::table('issuance_details', function (Blueprint $table) {
                $table->unsignedBigInteger('issuance_set_id')->nullable()->after('issuance_id');
                $table->index('issuance_set_id');
            });
        }

        Schema::table('issuance_details', function (Blueprint $table) {
            if (! Schema::hasColumn('issuance_details', 'cost_price_at_issue')) {
                $table->decimal('cost_price_at_issue', 12, 2)->nullable()->after('unit_price');
            }
            if (! Schema::hasColumn('issuance_details', 'batch_id')) {
                $table->unsignedBigInteger('batch_id')->nullable()->after('receiving_id');
            }
            if (! Schema::hasColumn('issuance_details', 'zone_label')) {
                $table->string('zone_label', 100)->nullable()->after('batch_id');
            }
            if (! Schema::hasColumn('issuance_details', 'quantity_requested')) {
                $table->integer('quantity_requested')->default(0)->after('quantity_issued');
            }
            if (! Schema::hasColumn('issuance_details', 'quantity_short')) {
                $table->integer('quantity_short')->default(0)->after('quantity_requested');
            }
        });

        $exists = DB::table('status_lookup')
            ->where('status_category', 'issuance')
            ->where('status_name', 'Draft')
            ->exists();

        if (! $exists) {
            DB::table('status_lookup')->insert([
                'status_name'     => 'Draft',
                'status_category' => 'issuance',
                'is_active'       => 1,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('issuance_details', function (Blueprint $table) {
            $table->dropColumn([
                'issuance_set_id', 'cost_price_at_issue', 'batch_id',
                'zone_label', 'quantity_requested', 'quantity_short',
            ]);
        });

        DB::table('status_lookup')
          ->where('status_category', 'issuance')
          ->where('status_name', 'Draft')
          ->delete();
    }
};
```

- [ ] **Step 2: Write the issuance_sets + pick_reservations migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('issuance_sets')) {
            Schema::create('issuance_sets', function (Blueprint $table) {
                $table->id('issuance_set_id');
                $table->unsignedBigInteger('issuance_id');
                $table->unsignedBigInteger('set_id');
                $table->integer('set_quantity')->default(1);
                $table->string('set_name_snapshot', 191);
                $table->boolean('was_modified')->default(false);
                $table->timestamps();

                $table->index('issuance_id');
                $table->index('set_id');
            });
        }

        if (! Schema::hasTable('pick_reservations')) {
            Schema::create('pick_reservations', function (Blueprint $table) {
                $table->id('reservation_id');
                $table->string('source_type', 20);   // issuance | transfer
                $table->unsignedBigInteger('source_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('location_id');
                $table->unsignedBigInteger('batch_id')->nullable();
                $table->unsignedBigInteger('serial_id')->nullable();
                $table->integer('quantity');
                $table->dateTime('expires_at');
                $table->dateTime('released_at')->nullable();
                $table->timestamps();

                $table->index(['source_type', 'source_id'], 'pick_res_source_idx');
                $table->index(['product_id', 'location_id', 'released_at'], 'pick_res_avail_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pick_reservations');
        Schema::dropIfExists('issuance_sets');
    }
};
```

- [ ] **Step 3: Apply both migrations to both databases**

```bash
php artisan migrate --force --path=database/migrations/2026_07_24_000002_add_issuance_order_columns.php
php artisan migrate --force --path=database/migrations/2026_07_24_000003_create_issuance_sets_and_reservations.php
DB_DATABASE=airkingV5_test php artisan migrate --force --path=database/migrations/2026_07_24_000002_add_issuance_order_columns.php
DB_DATABASE=airkingV5_test php artisan migrate --force --path=database/migrations/2026_07_24_000003_create_issuance_sets_and_reservations.php
```

Expected: a `DONE` line per file per database, four total.

- [ ] **Step 4: Write `app/Support/IssuanceStatus.php`**

Draft's id is assigned by the database, so it must never be hardcoded the way 30/31/32 currently are in `IssuanceScanController`.

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class IssuanceStatus
{
    /** @var array<string,int> */
    private static array $cache = [];

    public static function id(string $name): int
    {
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }

        $id = DB::table('status_lookup')
            ->where('status_category', 'issuance')
            ->where('status_name', $name)
            ->value('status_id');

        if ($id === null) {
            throw new \RuntimeException("Unknown issuance status: {$name}");
        }

        return self::$cache[$name] = (int) $id;
    }

    public static function draft(): int     { return self::id('Draft'); }
    public static function pending(): int   { return self::id('Pending'); }
    public static function completed(): int { return self::id('Completed'); }
    public static function voided(): int    { return self::id('Voided'); }

    /** Tests that create statuses inside a transaction need this between cases. */
    public static function flush(): void { self::$cache = []; }
}
```

- [ ] **Step 5: Write the schema test**

Create `tests/Feature/Sets/IssuanceOrderSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Sets;

use App\Support\IssuanceStatus;
use Illuminate\Support\Facades\Schema;

class IssuanceOrderSchemaTest extends SetTestCase
{
    public function test_new_order_columns_and_tables_exist(): void
    {
        foreach (['issuance_set_id', 'cost_price_at_issue', 'batch_id', 'zone_label', 'quantity_requested', 'quantity_short'] as $col) {
            $this->assertTrue(Schema::hasColumn('issuance_details', $col), "issuance_details missing {$col}");
        }

        $this->assertTrue(Schema::hasTable('issuance_sets'));
        $this->assertTrue(Schema::hasTable('pick_reservations'));
    }

    public function test_draft_status_exists_and_is_distinct_from_pending(): void
    {
        $this->assertNotSame(IssuanceStatus::draft(), IssuanceStatus::pending());
        $this->assertSame(30, IssuanceStatus::pending());
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=IssuanceOrderSchemaTest`
Expected: PASS, 2 tests.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_24_000002_add_issuance_order_columns.php database/migrations/2026_07_24_000003_create_issuance_sets_and_reservations.php app/Support/IssuanceStatus.php tests/Feature/Sets/IssuanceOrderSchemaTest.php
git commit -m "feat(issuance): Draft status, order columns, issuance_sets and pick_reservations"
```

---

### Task 8: IssuanceSet and PickReservation models

**Files:**
- Create: `app/Models/IssuanceSet.php`, `app/Models/PickReservation.php`
- Modify: `app/Models/Issuance.php`, `app/Models/IssuanceDetail.php`
- Test: `tests/Feature/Sets/IssuanceSetModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Sets;

use App\Models\Issuance;
use App\Models\ProductSet;
use App\Support\IssuanceStatus;

class IssuanceSetModelTest extends SetTestCase
{
    public function test_issuance_exposes_its_sets_and_details_link_back(): void
    {
        $loc      = $this->makeLocation();
        $customer = $this->makeCustomer();
        $product  = $this->makeConsumable();

        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'BOQ A']);

        $issuance = Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $customer->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::draft(),
        ]);

        $issuanceSet = $issuance->sets()->create([
            'set_id'            => $set->set_id,
            'set_quantity'      => 2,
            'set_name_snapshot' => $set->set_name,
        ]);

        $detail = $issuance->details()->create([
            'issuance_set_id'    => $issuanceSet->issuance_set_id,
            'product_id'         => $product->product_id,
            'quantity_requested' => 4,
            'quantity_issued'    => 0,
            'unit_price'         => 50,
        ]);

        $this->assertSame(1, $issuance->fresh('sets')->sets->count());
        $this->assertSame(
            (int) $issuanceSet->issuance_set_id,
            (int) $detail->fresh('issuanceSet')->issuanceSet->issuance_set_id
        );
        $this->assertFalse((bool) $issuanceSet->was_modified);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --filter=IssuanceSetModelTest`
Expected: FAIL — `Call to undefined method App\Models\Issuance::sets()`.

- [ ] **Step 3: Write `app/Models/IssuanceSet.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IssuanceSet extends Model
{
    protected $table      = 'issuance_sets';
    protected $primaryKey = 'issuance_set_id';

    protected $fillable = [
        'issuance_id', 'set_id', 'set_quantity',
        'set_name_snapshot', 'was_modified',
    ];

    protected $casts = [
        'set_quantity' => 'integer',
        'was_modified' => 'boolean',
    ];

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(Issuance::class, 'issuance_id', 'issuance_id');
    }

    public function productSet(): BelongsTo
    {
        return $this->belongsTo(ProductSet::class, 'set_id', 'set_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(IssuanceDetail::class, 'issuance_set_id', 'issuance_set_id');
    }
}
```

- [ ] **Step 4: Write `app/Models/PickReservation.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickReservation extends Model
{
    protected $table      = 'pick_reservations';
    protected $primaryKey = 'reservation_id';

    protected $fillable = [
        'source_type', 'source_id', 'product_id', 'location_id',
        'batch_id', 'serial_id', 'quantity', 'expires_at', 'released_at',
    ];

    protected $casts = [
        'quantity'    => 'integer',
        'expires_at'  => 'datetime',
        'released_at' => 'datetime',
    ];

    /** Reservations still holding stock: not released and not expired. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at')->where('expires_at', '>', now());
    }

    public function scopeForSource(Builder $query, string $type, int $id): Builder
    {
        return $query->where('source_type', $type)->where('source_id', $id);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id', 'batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
```

- [ ] **Step 5: Add the relations to `Issuance` and `IssuanceDetail`**

In `app/Models/Issuance.php`, add this relation after `details()`:

```php
    public function sets(): HasMany
    {
        return $this->hasMany(IssuanceSet::class, 'issuance_id', 'issuance_id');
    }
```

In `app/Models/IssuanceDetail.php`, extend `$fillable` to:

```php
    protected $fillable = [
        'issuance_id', 'issuance_set_id', 'product_id', 'serial_number', 'receiving_id',
        'batch_id', 'zone_label', 'unit_price', 'cost_price_at_issue',
        'quantity_issued', 'quantity_requested', 'quantity_short', 'quantity_unit',
        'quantity_in_pieces', 'unit_price_per_piece', 'subtotal',
        'quantity_returned', 'condition_issued', 'condition_returned',
    ];
```

extend `$casts` with:

```php
        'quantity_requested'   => 'integer',
        'quantity_short'       => 'integer',
        'cost_price_at_issue'  => 'decimal:2',
```

and add these relations:

```php
    public function issuanceSet(): BelongsTo
    {
        return $this->belongsTo(IssuanceSet::class, 'issuance_set_id', 'issuance_set_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id', 'batch_id');
    }
```

- [ ] **Step 6: Run the test**

Run: `php artisan test --filter=IssuanceSetModelTest`
Expected: PASS, 1 test.

- [ ] **Step 7: Guard against regressions in existing issuance tests**

Run: `php artisan test --filter=Issuance`
Expected: PASS — the two pre-existing tests in `tests/Feature/Issuance/` still pass. If `IssuanceRemoveItemBatchTest` fails, the `$fillable` edit dropped a key that was there before; re-check it against `git diff`.

- [ ] **Step 8: Commit**

```bash
git add app/Models/IssuanceSet.php app/Models/PickReservation.php app/Models/Issuance.php app/Models/IssuanceDetail.php tests/Feature/Sets/IssuanceSetModelTest.php
git commit -m "feat(issuance): IssuanceSet and PickReservation models, order columns on details"
```

---

### Task 9: IssuanceOrderService — build the draft list

**Files:**
- Create: `app/Services/IssuanceOrderService.php`
- Test: `tests/Feature/Sets/IssuanceOrderServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Sets;

use App\Models\Issuance;
use App\Models\ProductSet;
use App\Services\IssuanceOrderService;
use App\Support\IssuanceStatus;

class IssuanceOrderServiceTest extends SetTestCase
{
    private function draft(): Issuance
    {
        $loc      = $this->makeLocation();
        $customer = $this->makeCustomer();

        return Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $customer->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::draft(),
        ]);
    }

    public function test_adding_a_set_expands_lines_multiplied_by_set_quantity(): void
    {
        $issuance = $this->draft();
        $a = $this->makeConsumable(price: 100);
        $b = $this->makeConsumable(price: 25);

        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'BOQ']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 2, 'sort_order' => 1]);
        $set->lines()->create(['product_id' => $b->product_id, 'quantity' => 4, 'sort_order' => 2]);

        app(IssuanceOrderService::class)->addSet($issuance, $set->set_id, 3);

        $details = $issuance->fresh('details')->details;
        $this->assertCount(2, $details);
        $this->assertSame(6, (int) $details->firstWhere('product_id', $a->product_id)->quantity_requested);
        $this->assertSame(12, (int) $details->firstWhere('product_id', $b->product_id)->quantity_requested);
        $this->assertSame('BOQ', $issuance->fresh('sets')->sets->first()->set_name_snapshot);
    }

    public function test_adding_the_same_set_twice_creates_two_independent_groups(): void
    {
        $issuance = $this->draft();
        $a = $this->makeConsumable();

        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'BOQ']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 1]);

        $svc = app(IssuanceOrderService::class);
        $svc->addSet($issuance, $set->set_id, 1);
        $svc->addSet($issuance, $set->set_id, 2);

        $this->assertCount(2, $issuance->fresh('sets')->sets);
        $this->assertCount(2, $issuance->fresh('details')->details);
    }

    public function test_adding_a_custom_line_has_no_set_id(): void
    {
        $issuance = $this->draft();
        $p = $this->makeConsumable(price: 75);

        $detail = app(IssuanceOrderService::class)->addCustomLine($issuance, $p->product_id, 3);

        $this->assertNull($detail->issuance_set_id);
        $this->assertSame(3, (int) $detail->quantity_requested);
        $this->assertEqualsWithDelta(75.0, (float) $detail->unit_price, 0.001);
    }

    public function test_editing_a_set_line_flags_the_group_as_modified(): void
    {
        $issuance = $this->draft();
        $a = $this->makeConsumable();

        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'BOQ']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 5]);

        $svc = app(IssuanceOrderService::class);
        $svc->addSet($issuance, $set->set_id, 1);
        $detail = $issuance->fresh('details')->details->first();

        $svc->updateLine($issuance, (int) $detail->issuance_detail_id, 2);

        $this->assertTrue((bool) $issuance->fresh('sets')->sets->first()->was_modified);
        $this->assertSame(2, (int) $detail->fresh()->quantity_requested);
    }

    public function test_removing_a_set_line_also_flags_modified(): void
    {
        $issuance = $this->draft();
        $a = $this->makeConsumable();
        $b = $this->makeConsumable();

        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'BOQ']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 1]);
        $set->lines()->create(['product_id' => $b->product_id, 'quantity' => 1]);

        $svc = app(IssuanceOrderService::class);
        $svc->addSet($issuance, $set->set_id, 1);
        $detail = $issuance->fresh('details')->details->first();

        $svc->removeLine($issuance, (int) $detail->issuance_detail_id);

        $this->assertTrue((bool) $issuance->fresh('sets')->sets->first()->was_modified);
        $this->assertCount(1, $issuance->fresh('details')->details);
    }

    public function test_lines_cannot_be_changed_once_the_order_leaves_draft(): void
    {
        $issuance = $this->draft();
        $p = $this->makeConsumable();
        $issuance->update(['status_id' => IssuanceStatus::pending()]);

        $this->expectException(\RuntimeException::class);
        app(IssuanceOrderService::class)->addCustomLine($issuance, $p->product_id, 1);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --filter=IssuanceOrderServiceTest`
Expected: FAIL — `Target class [App\Services\IssuanceOrderService] does not exist`.

- [ ] **Step 3: Write `app/Services/IssuanceOrderService.php`**

```php
<?php

namespace App\Services;

use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\Product;
use App\Models\ProductSet;
use App\Support\IssuanceStatus;
use Illuminate\Support\Facades\DB;

/**
 * Builds the line list of a Draft issuance. Every mutation here is illegal once
 * the order leaves Draft — the confirmed quote is what the customer agreed to,
 * so it must not move underneath the picker.
 */
class IssuanceOrderService
{
    /** Expand a set into lines, multiplied by how many sets the customer wants. */
    public function addSet(Issuance $issuance, int $setId, int $setQuantity): void
    {
        $this->assertDraft($issuance);

        if ($setQuantity < 1) {
            throw new \InvalidArgumentException('Set quantity must be at least 1.');
        }

        $set = ProductSet::with(['lines.product', 'lines.issuableUnit'])->findOrFail($setId);

        DB::transaction(function () use ($issuance, $set, $setQuantity) {
            $group = $issuance->sets()->create([
                'set_id'            => $set->set_id,
                'set_quantity'      => $setQuantity,
                'set_name_snapshot' => $set->set_name,
                'was_modified'      => false,
            ]);

            foreach ($set->lines as $line) {
                $pieces = $line->quantityInPieces() * $setQuantity;
                $price  = (float) ($line->product?->unit_price ?? 0);

                $issuance->details()->create([
                    'issuance_set_id'     => $group->issuance_set_id,
                    'product_id'          => $line->product_id,
                    'quantity_requested'  => $pieces,
                    'quantity_issued'     => 0,
                    'quantity_short'      => 0,
                    'unit_price'          => $price,
                    'cost_price_at_issue' => null, // snapshotted at confirm-quote
                    'subtotal'            => round($pieces * $price, 2),
                ]);
            }
        });
    }

    /** Add a loose product that belongs to no set. */
    public function addCustomLine(Issuance $issuance, int $productId, int $quantity): IssuanceDetail
    {
        $this->assertDraft($issuance);

        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }

        $product = Product::findOrFail($productId);

        return $issuance->details()->create([
            'issuance_set_id'     => null,
            'product_id'          => $product->product_id,
            'quantity_requested'  => $quantity,
            'quantity_issued'     => 0,
            'quantity_short'      => 0,
            'unit_price'          => (float) $product->unit_price,
            'cost_price_at_issue' => null,
            'subtotal'            => round($quantity * (float) $product->unit_price, 2),
        ]);
    }

    public function updateLine(Issuance $issuance, int $detailId, int $quantity): IssuanceDetail
    {
        $this->assertDraft($issuance);

        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }

        $detail = $issuance->details()->where('issuance_detail_id', $detailId)->firstOrFail();

        $detail->update([
            'quantity_requested' => $quantity,
            'subtotal'           => round($quantity * (float) $detail->unit_price, 2),
        ]);

        $this->flagModified($detail);

        return $detail->fresh();
    }

    public function removeLine(Issuance $issuance, int $detailId): void
    {
        $this->assertDraft($issuance);

        $detail = $issuance->details()->where('issuance_detail_id', $detailId)->firstOrFail();
        $this->flagModified($detail);
        $detail->delete();
    }

    /** A set group whose lines were touched no longer represents the standard BOQ. */
    private function flagModified(IssuanceDetail $detail): void
    {
        if ($detail->issuance_set_id === null) {
            return;
        }

        $detail->loadMissing('issuanceSet');
        $detail->issuanceSet?->update(['was_modified' => true]);
    }

    private function assertDraft(Issuance $issuance): void
    {
        if ((int) $issuance->status_id !== IssuanceStatus::draft()) {
            throw new \RuntimeException('Only a Draft issuance can have its lines changed.');
        }
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --filter=IssuanceOrderServiceTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/IssuanceOrderService.php tests/Feature/Sets/IssuanceOrderServiceTest.php
git commit -m "feat(issuance): IssuanceOrderService builds draft lines from sets and custom items"
```

---

### Task 10: Quote endpoint — pricing and availability

**Files:**
- Create: `app/Http/Controllers/Api/IssuanceOrderController.php`
- Modify: `routes/api.php`, `database/seeders/PermissionSeeder.php`
- Test: `tests/Feature/Sets/IssuanceQuoteTest.php`

Availability per line is `inventory.available_quantity` at the order's location, minus any quantity held by **active reservations belonging to other orders**. Own-order reservations are excluded so re-opening your own quote does not show your own hold as a shortage.

- [ ] **Step 1: Add the `view_margin` action to `PermissionSeeder::matrix()`**

Find the existing `inventory_issuance` entry and add one action to it, leaving the others untouched:

```php
                'view_margin' => ['admin', 'inventory_analyst', 'branch_manager', 'auditor'],
```

Re-seed both databases:

```bash
php artisan db:seed --force --class=PermissionSeeder
php artisan db:seed --force --class=RolePermissionsSeeder
DB_DATABASE=airkingV5_test php artisan db:seed --force --class=PermissionSeeder
DB_DATABASE=airkingV5_test php artisan db:seed --force --class=RolePermissionsSeeder
```

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\Sets;

use App\Models\Issuance;
use App\Models\PickReservation;
use App\Models\ProductSet;
use App\Support\IssuanceStatus;

class IssuanceQuoteTest extends SetTestCase
{
    private function draftAt($loc): Issuance
    {
        return Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $this->makeCustomer()->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::draft(),
        ]);
    }

    public function test_quote_totals_lines_and_applies_discount(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(price: 100, cost: 60);
        $this->setStock($loc->location_id, $p->product_id, 50);

        $issuance = $this->draftAt($loc);
        $issuance->update(['discount_type' => 'business', 'discount_percent' => 10]);
        $issuance->details()->create([
            'product_id' => $p->product_id, 'quantity_requested' => 4,
            'quantity_issued' => 0, 'unit_price' => 100, 'subtotal' => 400,
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");

        $res->assertOk();
        $this->assertEqualsWithDelta(400.0, (float) $res->json('data.subtotal'), 0.001);
        $this->assertEqualsWithDelta(40.0,  (float) $res->json('data.discount_amount'), 0.001);
        $this->assertEqualsWithDelta(360.0, (float) $res->json('data.total_amount'), 0.001);
    }

    public function test_short_line_is_flagged_with_available_quantity(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(price: 10);
        $this->setStock($loc->location_id, $p->product_id, 3);

        $issuance = $this->draftAt($loc);
        $issuance->details()->create([
            'product_id' => $p->product_id, 'quantity_requested' => 8,
            'quantity_issued' => 0, 'unit_price' => 10, 'subtotal' => 80,
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");

        $line = $res->json('data.lines.0');
        $this->assertSame(3, (int) $line['available']);
        $this->assertSame(5, (int) $line['short_by']);
        $this->assertTrue($line['is_short']);
        $this->assertTrue($res->json('data.has_shortages'));
    }

    public function test_another_orders_active_reservation_reduces_availability(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(price: 10);
        $this->setStock($loc->location_id, $p->product_id, 10);

        PickReservation::create([
            'source_type' => 'issuance', 'source_id' => 999999,
            'product_id'  => $p->product_id, 'location_id' => $loc->location_id,
            'quantity'    => 7, 'expires_at' => now()->addHour(),
        ]);

        $issuance = $this->draftAt($loc);
        $issuance->details()->create([
            'product_id' => $p->product_id, 'quantity_requested' => 5,
            'quantity_issued' => 0, 'unit_price' => 10, 'subtotal' => 50,
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");

        $this->assertSame(3, (int) $res->json('data.lines.0.available'));
        $this->assertSame(2, (int) $res->json('data.lines.0.short_by'));
    }

    public function test_expired_reservation_does_not_reduce_availability(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(price: 10);
        $this->setStock($loc->location_id, $p->product_id, 10);

        PickReservation::create([
            'source_type' => 'issuance', 'source_id' => 999998,
            'product_id'  => $p->product_id, 'location_id' => $loc->location_id,
            'quantity'    => 7, 'expires_at' => now()->subMinute(),
        ]);

        $issuance = $this->draftAt($loc);
        $issuance->details()->create([
            'product_id' => $p->product_id, 'quantity_requested' => 5,
            'quantity_issued' => 0, 'unit_price' => 10, 'subtotal' => 50,
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");

        $this->assertSame(10, (int) $res->json('data.lines.0.available'));
        $this->assertFalse($res->json('data.lines.0.is_short'));
    }

    public function test_margin_is_hidden_from_a_role_without_view_margin(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable(price: 100, cost: 60);
        $this->setStock($loc->location_id, $p->product_id, 10);

        $issuance = $this->draftAt($loc);
        $issuance->details()->create([
            'product_id' => $p->product_id, 'quantity_requested' => 2,
            'quantity_issued' => 0, 'unit_price' => 100, 'subtotal' => 200,
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");

        $res->assertOk();
        $this->assertNull($res->json('data.total_cost'));
        $this->assertNull($res->json('data.lines.0.cost_price'));
    }

    public function test_margin_is_visible_to_an_inventory_analyst(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(price: 100, cost: 60);
        $this->setStock($loc->location_id, $p->product_id, 10);

        $issuance = $this->draftAt($loc);
        $issuance->details()->create([
            'product_id' => $p->product_id, 'quantity_requested' => 2,
            'quantity_issued' => 0, 'unit_price' => 100, 'subtotal' => 200,
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");

        $this->assertEqualsWithDelta(120.0, (float) $res->json('data.total_cost'), 0.001);
        $this->assertEqualsWithDelta(80.0,  (float) $res->json('data.total_margin'), 0.001);
    }

    public function test_quote_groups_lines_under_their_set(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(price: 10);
        $this->setStock($loc->location_id, $p->product_id, 100);

        $set      = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'BOQ']);
        $issuance = $this->draftAt($loc);
        $group    = $issuance->sets()->create([
            'set_id' => $set->set_id, 'set_quantity' => 2, 'set_name_snapshot' => 'BOQ',
        ]);
        $issuance->details()->create([
            'issuance_set_id' => $group->issuance_set_id,
            'product_id' => $p->product_id, 'quantity_requested' => 6,
            'quantity_issued' => 0, 'unit_price' => 10, 'subtotal' => 60,
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/issuances/{$issuance->issuance_id}/quote");

        $groups = $res->json('data.set_groups');
        $this->assertCount(1, $groups);
        $this->assertSame('BOQ', $groups[0]['set_name_snapshot']);
        $this->assertSame(2, (int) $groups[0]['set_quantity']);
        $this->assertEqualsWithDelta(60.0, (float) $groups[0]['group_total'], 0.001);
    }
}
```

- [ ] **Step 3: Run it to confirm it fails**

Run: `php artisan test --filter=IssuanceQuoteTest`
Expected: FAIL — 404 on the quote route.

- [ ] **Step 4: Write `app/Http/Controllers/Api/IssuanceOrderController.php`**

Only `quote()` and its helpers land in this task; `confirmQuote()` and `pickPlan()` arrive in Tasks 11 and 15.

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Issuance;
use App\Models\PickReservation;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IssuanceOrderController extends Controller
{
    use ApiResponse;

    public function quote(int $id): JsonResponse
    {
        $issuance = Issuance::with(['details.product', 'sets'])->findOrFail($id);
        $showCost = $this->canSeeMargin();

        $lines = $issuance->details->map(function ($detail) use ($issuance, $showCost) {
            $requested = (int) $detail->quantity_requested;
            $available = $this->availableFor(
                (int) $issuance->location_id,
                (int) $detail->product_id,
                (int) $issuance->issuance_id
            );

            $unitPrice = (float) $detail->unit_price;
            $costPrice = (float) ($detail->cost_price_at_issue ?? $detail->product?->cost_price ?? 0);
            $shortBy   = max(0, $requested - $available);

            return array_filter([
                'issuance_detail_id' => (int) $detail->issuance_detail_id,
                'issuance_set_id'    => $detail->issuance_set_id !== null ? (int) $detail->issuance_set_id : null,
                'product_id'         => (int) $detail->product_id,
                'product_name'       => $detail->product?->product_name,
                'product_type'       => $detail->product?->product_type,
                'quantity_requested' => $requested,
                'unit_price'         => $unitPrice,
                'line_total'         => round($requested * $unitPrice, 2),
                'available'          => $available,
                'short_by'           => $shortBy,
                'is_short'           => $shortBy > 0,
                'cost_price'         => $showCost ? $costPrice : null,
                'line_cost'          => $showCost ? round($requested * $costPrice, 2) : null,
            ], fn ($v, $k) => ! in_array($k, ['cost_price', 'line_cost'], true) || $v !== null, ARRAY_FILTER_USE_BOTH);
        })->values();

        $subtotal        = round($lines->sum('line_total'), 2);
        $discountPercent = (float) ($issuance->discount_percent ?? 0);
        $discountAmount  = round($subtotal * $discountPercent / 100, 2);
        $total           = round($subtotal - $discountAmount, 2);
        $totalCost       = $showCost ? round($lines->sum('line_cost'), 2) : null;

        $setGroups = $issuance->sets->map(function ($group) use ($lines) {
            $groupLines = $lines->where('issuance_set_id', (int) $group->issuance_set_id);

            return [
                'issuance_set_id'   => (int) $group->issuance_set_id,
                'set_id'            => (int) $group->set_id,
                'set_name_snapshot' => $group->set_name_snapshot,
                'set_quantity'      => (int) $group->set_quantity,
                'was_modified'      => (bool) $group->was_modified,
                'line_count'        => $groupLines->count(),
                'group_total'       => round($groupLines->sum('line_total'), 2),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'issuance_id'      => (int) $issuance->issuance_id,
                'dr_number'        => $issuance->dr_number,
                'status_id'        => (int) $issuance->status_id,
                'location_id'      => (int) $issuance->location_id,
                'customer_id'      => (int) $issuance->customer_id,
                'discount_type'    => $issuance->discount_type,
                'discount_percent' => $discountPercent,
                'subtotal'         => $subtotal,
                'discount_amount'  => $discountAmount,
                'total_amount'     => $total,
                'total_cost'       => $totalCost,
                'total_margin'     => $totalCost === null ? null : round($total - $totalCost, 2),
                'has_shortages'    => $lines->contains('is_short', true),
                'set_groups'       => $setGroups,
                'lines'            => $lines,
            ],
        ]);
    }

    /**
     * On-hand availability minus stock held by other orders' live reservations.
     * Own-order holds are excluded so re-opening your own quote never reads as short.
     */
    protected function availableFor(int $locationId, int $productId, int $ownIssuanceId): int
    {
        $onHand = (int) (Inventory::where('location_id', $locationId)
            ->where('product_id', $productId)
            ->value('available_quantity') ?? 0);

        $heldElsewhere = (int) PickReservation::active()
            ->where('location_id', $locationId)
            ->where('product_id', $productId)
            ->where(function ($q) use ($ownIssuanceId) {
                $q->where('source_type', '!=', 'issuance')
                  ->orWhere('source_id', '!=', $ownIssuanceId);
            })
            ->sum('quantity');

        return max(0, $onHand - $heldElsewhere);
    }

    protected function canSeeMargin(): bool
    {
        $user = Auth::user();
        if ($user === null) {
            return false;
        }

        return app(\App\Support\PermissionResolver::class)
            ->canUser((int) $user->user_id, 'inventory_issuance', 'view_margin');
    }
}
```

`PermissionResolver` lives in `App\Support`, not `App\Services`, and its method is `canUser(int $userId, string $feature, string $action)` — the same call `CheckPermission` middleware makes (`app/Http/Middleware/CheckPermission.php:32`). Admin is bypassed inside the resolver, so admins see margin without an explicit grant.

- [ ] **Step 5: Register the route in `routes/api.php`**

Beside the other issuance routes:

```php
    Route::middleware('permission:inventory_issuance,read')
         ->get('/issuances/{id}/quote', [\App\Http\Controllers\Api\IssuanceOrderController::class, 'quote']);
```

`/issuances/{id}/quote` must be declared **before** the existing `/issuances/{id}` route, or the literal segment is captured as part of the id.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=IssuanceQuoteTest`
Expected: PASS, 7 tests.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/IssuanceOrderController.php routes/api.php database/seeders/PermissionSeeder.php tests/Feature/Sets/IssuanceQuoteTest.php
git commit -m "feat(issuance): priced quote endpoint with availability and permission-gated margin"
```

---

### Task 11: ReservationService and confirm-quote

**Files:**
- Create: `app/Services/ReservationService.php`
- Modify: `app/Http/Controllers/Api/IssuanceOrderController.php`, `routes/api.php`
- Test: `tests/Feature/Sets/ConfirmQuoteTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Sets;

use App\Models\Inventory;
use App\Models\Issuance;
use App\Models\PickReservation;
use App\Support\IssuanceStatus;

class ConfirmQuoteTest extends SetTestCase
{
    private function draftWithLine($loc, $product, int $qty, float $price = 100): Issuance
    {
        $issuance = Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $this->makeCustomer()->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::draft(),
        ]);

        $issuance->details()->create([
            'product_id'         => $product->product_id,
            'quantity_requested' => $qty,
            'quantity_issued'    => 0,
            'unit_price'         => $price,
            'subtotal'           => $qty * $price,
        ]);

        return $issuance;
    }

    public function test_confirming_moves_draft_to_pending_and_writes_totals(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(price: 100, cost: 60);
        $this->setStock($loc->location_id, $p->product_id, 20);

        $issuance = $this->draftWithLine($loc, $p, 4);

        $res = $this->actingAs($user, 'sanctum')
                    ->postJson("/api/issuances/{$issuance->issuance_id}/confirm-quote");

        $res->assertOk();
        $fresh = $issuance->fresh();
        $this->assertSame(IssuanceStatus::pending(), (int) $fresh->status_id);
        $this->assertEqualsWithDelta(400.0, (float) $fresh->subtotal, 0.001);
        $this->assertEqualsWithDelta(400.0, (float) $fresh->total_amount, 0.001);
    }

    public function test_confirming_snapshots_cost_price_on_each_line(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(price: 100, cost: 60);
        $this->setStock($loc->location_id, $p->product_id, 20);

        $issuance = $this->draftWithLine($loc, $p, 2);
        $this->actingAs($user, 'sanctum')->postJson("/api/issuances/{$issuance->issuance_id}/confirm-quote")->assertOk();

        // Later price change must not rewrite what was already snapshotted.
        $p->update(['cost_price' => 999]);

        $detail = $issuance->fresh('details')->details->first();
        $this->assertEqualsWithDelta(60.0, (float) $detail->cost_price_at_issue, 0.001);
    }

    public function test_confirming_reserves_stock_and_decrements_available_quantity(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable();
        $this->setStock($loc->location_id, $p->product_id, 20);

        $issuance = $this->draftWithLine($loc, $p, 5);
        $this->actingAs($user, 'sanctum')->postJson("/api/issuances/{$issuance->issuance_id}/confirm-quote")->assertOk();

        $this->assertSame(5, (int) PickReservation::active()
            ->forSource('issuance', (int) $issuance->issuance_id)->sum('quantity'));

        $inv = Inventory::where('location_id', $loc->location_id)->where('product_id', $p->product_id)->first();
        $this->assertSame(15, (int) $inv->available_quantity);
        $this->assertSame(20, (int) $inv->quantity_on_hand, 'on-hand must not move until a scan commits');
    }

    public function test_short_line_records_the_shortfall_and_reduces_the_request(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(price: 10);
        $this->setStock($loc->location_id, $p->product_id, 3);

        $issuance = $this->draftWithLine($loc, $p, 8, 10);

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/issuances/{$issuance->issuance_id}/confirm-quote", ['accept_shortages' => true])
             ->assertOk();

        $detail = $issuance->fresh('details')->details->first();
        $this->assertSame(3, (int) $detail->quantity_requested);
        $this->assertSame(5, (int) $detail->quantity_short);
        $this->assertEqualsWithDelta(30.0, (float) $issuance->fresh()->total_amount, 0.001);
    }

    public function test_shortage_without_acknowledgement_is_rejected(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable();
        $this->setStock($loc->location_id, $p->product_id, 1);

        $issuance = $this->draftWithLine($loc, $p, 9);

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/issuances/{$issuance->issuance_id}/confirm-quote")
             ->assertStatus(422);

        $this->assertSame(IssuanceStatus::draft(), (int) $issuance->fresh()->status_id);
    }

    public function test_confirming_an_empty_order_is_rejected(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');

        $issuance = Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $this->makeCustomer()->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::draft(),
        ]);

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/issuances/{$issuance->issuance_id}/confirm-quote")
             ->assertStatus(422);
    }

    public function test_confirming_twice_is_rejected(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable();
        $this->setStock($loc->location_id, $p->product_id, 20);

        $issuance = $this->draftWithLine($loc, $p, 2);
        $url = "/api/issuances/{$issuance->issuance_id}/confirm-quote";

        $this->actingAs($user, 'sanctum')->postJson($url)->assertOk();
        $this->actingAs($user, 'sanctum')->postJson($url)->assertStatus(422);

        $this->assertSame(2, (int) PickReservation::active()
            ->forSource('issuance', (int) $issuance->issuance_id)->sum('quantity'));
    }

    public function test_two_orders_cannot_reserve_the_same_stock_twice(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable();
        $this->setStock($loc->location_id, $p->product_id, 6);

        $first  = $this->draftWithLine($loc, $p, 6);
        $second = $this->draftWithLine($loc, $p, 6);

        $this->actingAs($user, 'sanctum')->postJson("/api/issuances/{$first->issuance_id}/confirm-quote")->assertOk();
        $this->actingAs($user, 'sanctum')->postJson("/api/issuances/{$second->issuance_id}/confirm-quote")->assertStatus(422);
    }

    public function test_releasing_reservations_restores_available_quantity(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable();
        $this->setStock($loc->location_id, $p->product_id, 10);

        $issuance = $this->draftWithLine($loc, $p, 4);
        $this->actingAs($user, 'sanctum')->postJson("/api/issuances/{$issuance->issuance_id}/confirm-quote")->assertOk();

        app(\App\Services\ReservationService::class)->releaseFor('issuance', (int) $issuance->issuance_id);

        $inv = Inventory::where('location_id', $loc->location_id)->where('product_id', $p->product_id)->first();
        $this->assertSame(10, (int) $inv->available_quantity);
        $this->assertSame(0, (int) PickReservation::active()
            ->forSource('issuance', (int) $issuance->issuance_id)->sum('quantity'));
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --filter=ConfirmQuoteTest`
Expected: FAIL — 404 on `confirm-quote`.

- [ ] **Step 3: Write `app/Services/ReservationService.php`**

```php
<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\PickReservation;
use Illuminate\Support\Facades\DB;

/**
 * Sole writer of pick_reservations. A reservation is a soft hold: it lowers
 * inventory.available_quantity so a second picker cannot promise the same stock,
 * and leaves quantity_on_hand alone until a scan actually commits the movement.
 */
class ReservationService
{
    /** How long a confirmed quote holds its stock before the hold lapses. */
    public const TTL_MINUTES = 240;

    /**
     * Reserve $quantity pieces of $productId at $locationId for a source document.
     * Throws when the live availability cannot cover the request.
     *
     * @param  array<int,array{product_id:int,quantity:int,batch_id?:int|null,serial_id?:int|null}>  $lines
     */
    public function reserve(string $sourceType, int $sourceId, int $locationId, array $lines): void
    {
        DB::transaction(function () use ($sourceType, $sourceId, $locationId, $lines) {
            foreach ($lines as $line) {
                $productId = (int) $line['product_id'];
                $quantity  = (int) $line['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                $inventory = $this->lockInventory($locationId, $productId);

                if ((int) $inventory->available_quantity < $quantity) {
                    throw new \RuntimeException(
                        "Insufficient available stock for product {$productId}: "
                        . "requested {$quantity}, available {$inventory->available_quantity}."
                    );
                }

                PickReservation::create([
                    'source_type' => $sourceType,
                    'source_id'   => $sourceId,
                    'product_id'  => $productId,
                    'location_id' => $locationId,
                    'batch_id'    => $line['batch_id'] ?? null,
                    'serial_id'   => $line['serial_id'] ?? null,
                    'quantity'    => $quantity,
                    'expires_at'  => now()->addMinutes(self::TTL_MINUTES),
                ]);

                $inventory->decrement('available_quantity', $quantity);
            }
        });
    }

    /** Release every live hold for a source document and give the stock back. */
    public function releaseFor(string $sourceType, int $sourceId): void
    {
        DB::transaction(function () use ($sourceType, $sourceId) {
            $reservations = PickReservation::active()
                ->forSource($sourceType, $sourceId)
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $inventory = $this->lockInventory(
                    (int) $reservation->location_id,
                    (int) $reservation->product_id
                );
                $inventory->increment('available_quantity', (int) $reservation->quantity);
                $reservation->update(['released_at' => now()]);
            }
        });
    }

    /**
     * Close out every hold for a source document WITHOUT returning availability.
     * Used on completion: the committed scans have already moved quantity_on_hand
     * down, and available_quantity fell with the reservation, so handing it back
     * here would credit stock that has physically left the building.
     */
    public function settleFor(string $sourceType, int $sourceId): void
    {
        PickReservation::active()
            ->forSource($sourceType, $sourceId)
            ->update(['released_at' => now()]);
    }

    private function lockInventory(int $locationId, int $productId): Inventory
    {
        Inventory::firstOrCreate(
            ['location_id' => $locationId, 'product_id' => $productId],
            ['quantity_on_hand' => 0, 'available_quantity' => 0, 'reorder_level' => 0]
        );

        return Inventory::where('location_id', $locationId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();
    }
}
```

- [ ] **Step 4: Add `confirmQuote()` to `IssuanceOrderController`**

```php
    public function confirmQuote(Request $request, int $id): JsonResponse
    {
        $request->validate(['accept_shortages' => 'nullable|boolean']);

        $issuance = Issuance::with('details.product')->findOrFail($id);

        if ((int) $issuance->status_id !== \App\Support\IssuanceStatus::draft()) {
            return response()->json([
                'success' => false,
                'message' => 'Only a Draft issuance can be confirmed.',
            ], 422);
        }

        if ($issuance->details->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Add at least one line before confirming.',
            ], 422);
        }

        $locationId = (int) $issuance->location_id;
        $plan       = [];
        $shortages  = [];

        foreach ($issuance->details as $detail) {
            $requested = (int) $detail->quantity_requested;
            $available = $this->availableFor($locationId, (int) $detail->product_id, (int) $issuance->issuance_id);
            $grant     = min($requested, $available);

            if ($grant < $requested) {
                $shortages[] = [
                    'product_id'   => (int) $detail->product_id,
                    'product_name' => $detail->product?->product_name,
                    'requested'    => $requested,
                    'available'    => $available,
                ];
            }

            $plan[] = ['detail' => $detail, 'grant' => $grant, 'requested' => $requested];
        }

        if ($shortages !== [] && ! $request->boolean('accept_shortages')) {
            return response()->json([
                'success'   => false,
                'message'   => 'Some lines cannot be fully served. Re-submit with accept_shortages to proceed short.',
                'shortages' => $shortages,
            ], 422);
        }

        if (collect($plan)->sum('grant') === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No stock is available for any line on this order.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($issuance, $plan, $locationId) {
                $subtotal = 0.0;
                $lines    = [];

                foreach ($plan as $row) {
                    /** @var \App\Models\IssuanceDetail $detail */
                    $detail = $row['detail'];
                    $grant  = (int) $row['grant'];
                    $price  = (float) $detail->unit_price;

                    $detail->update([
                        'quantity_requested'  => $grant,
                        'quantity_short'      => (int) $row['requested'] - $grant,
                        'cost_price_at_issue' => (float) ($detail->product?->cost_price ?? 0),
                        'subtotal'            => round($grant * $price, 2),
                    ]);

                    $subtotal += $grant * $price;

                    if ($grant > 0) {
                        $lines[] = ['product_id' => (int) $detail->product_id, 'quantity' => $grant];
                    }
                }

                app(\App\Services\ReservationService::class)
                    ->reserve('issuance', (int) $issuance->issuance_id, $locationId, $lines);

                $subtotal        = round($subtotal, 2);
                $discountPercent = (float) ($issuance->discount_percent ?? 0);
                $discountAmount  = round($subtotal * $discountPercent / 100, 2);

                $issuance->update([
                    'subtotal'        => $subtotal,
                    'discount_amount' => $discountAmount,
                    'total_amount'    => round($subtotal - $discountAmount, 2),
                    'status_id'       => \App\Support\IssuanceStatus::pending(),
                ]);
            });
        } catch (\RuntimeException $e) {
            // Availability moved between the read and the lock — another order won the race.
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return $this->quote((int) $issuance->issuance_id);
    }
```

Add `use Illuminate\Support\Facades\DB;` to the controller's imports.

- [ ] **Step 5: Register the route**

```php
    Route::middleware('permission:inventory_issuance,create')
         ->post('/issuances/{id}/confirm-quote', [\App\Http\Controllers\Api\IssuanceOrderController::class, 'confirmQuote']);
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=ConfirmQuoteTest`
Expected: PASS, 9 tests.

- [ ] **Step 7: Commit**

```bash
git add app/Services/ReservationService.php app/Http/Controllers/Api/IssuanceOrderController.php routes/api.php tests/Feature/Sets/ConfirmQuoteTest.php
git commit -m "feat(issuance): confirm-quote reserves stock, snapshots cost, records shortfalls"
```

---

### Task 12: Draft line endpoints and the scanner gate

**Files:**
- Modify: `app/Http/Controllers/Api/IssuanceOrderController.php`, `app/Http/Controllers/Api/IssuanceScanController.php`, `routes/api.php`
- Test: `tests/Feature/Sets/DraftLineEndpointsTest.php`

`IssuanceScanController::initSession()` currently creates the issuance at Pending(30) and scanning starts immediately. It changes to create at Draft, and `scanItem()` changes to require Pending. That is the whole gate.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Sets;

use App\Models\Issuance;
use App\Models\ProductSet;
use App\Support\IssuanceStatus;

class DraftLineEndpointsTest extends SetTestCase
{
    public function test_init_session_now_creates_a_draft(): void
    {
        $loc      = $this->makeLocation();
        $user     = $this->makeUserAt($loc, 'inventory_analyst');
        $customer = $this->makeCustomer();

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/issuance-scan/init', [
            'customer_id'   => $customer->customer_id,
            'issuance_type' => 'individual',
            'location_id'   => $loc->location_id,
        ]);

        $res->assertCreated();
        $issuance = Issuance::find($res->json('issuance_id'));
        $this->assertSame(IssuanceStatus::draft(), (int) $issuance->status_id);
    }

    public function test_scanning_a_draft_is_rejected(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(barcode: 'BC-DRAFT-GATE');
        $this->setStock($loc->location_id, $p->product_id, 10);

        $issuance = Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $this->makeCustomer()->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::draft(),
        ]);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode'     => 'BC-DRAFT-GATE',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('confirm', strtolower($res->json('reason') ?? $res->json('message') ?? ''));
    }

    public function test_adding_a_set_through_the_endpoint_expands_lines(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $a    = $this->makeConsumable(price: 100);

        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'BOQ']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 2]);

        $issuance = Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $this->makeCustomer()->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::draft(),
        ]);

        $res = $this->actingAs($user, 'sanctum')->postJson(
            "/api/issuances/{$issuance->issuance_id}/lines/set",
            ['set_id' => $set->set_id, 'set_quantity' => 3]
        );

        $res->assertOk();
        $this->assertSame(6, (int) $res->json('data.lines.0.quantity_requested'));
    }

    public function test_adding_a_custom_line_through_the_endpoint(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(price: 75);

        $issuance = Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $this->makeCustomer()->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::draft(),
        ]);

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/issuances/{$issuance->issuance_id}/lines/custom",
            ['product_id' => $p->product_id, 'quantity' => 3]
        )->assertOk();

        $detail = $issuance->fresh('details')->details->first();
        $this->assertNull($detail->issuance_set_id);
        $this->assertSame(3, (int) $detail->quantity_requested);
    }

    public function test_line_endpoints_refuse_a_confirmed_order(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable();

        $issuance = Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $this->makeCustomer()->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::pending(),
        ]);

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/issuances/{$issuance->issuance_id}/lines/custom",
            ['product_id' => $p->product_id, 'quantity' => 1]
        )->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --filter=DraftLineEndpointsTest`
Expected: FAIL — `test_init_session_now_creates_a_draft` fails on status 30, and the line routes 404.

- [ ] **Step 3: Add line endpoints to `IssuanceOrderController`**

```php
    public function addSetLine(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'set_id'       => 'required|integer|exists:product_sets,set_id',
            'set_quantity' => 'required|integer|min:1',
        ]);

        $issuance = Issuance::findOrFail($id);

        try {
            app(\App\Services\IssuanceOrderService::class)
                ->addSet($issuance, (int) $validated['set_id'], (int) $validated['set_quantity']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return $this->quote($id);
    }

    public function addCustomLine(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,product_id',
            'quantity'   => 'required|integer|min:1',
        ]);

        $issuance = Issuance::findOrFail($id);

        try {
            app(\App\Services\IssuanceOrderService::class)
                ->addCustomLine($issuance, (int) $validated['product_id'], (int) $validated['quantity']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return $this->quote($id);
    }

    public function updateLine(Request $request, int $id, int $lineId): JsonResponse
    {
        $validated = $request->validate(['quantity' => 'required|integer|min:1']);
        $issuance  = Issuance::findOrFail($id);

        try {
            app(\App\Services\IssuanceOrderService::class)
                ->updateLine($issuance, $lineId, (int) $validated['quantity']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return $this->quote($id);
    }

    public function removeLine(int $id, int $lineId): JsonResponse
    {
        $issuance = Issuance::findOrFail($id);

        try {
            app(\App\Services\IssuanceOrderService::class)->removeLine($issuance, $lineId);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return $this->quote($id);
    }
```

- [ ] **Step 4: Register the routes**

```php
    Route::middleware('permission:inventory_issuance,create')->group(function () {
        Route::post('/issuances/{id}/lines/set',    [\App\Http\Controllers\Api\IssuanceOrderController::class, 'addSetLine']);
        Route::post('/issuances/{id}/lines/custom', [\App\Http\Controllers\Api\IssuanceOrderController::class, 'addCustomLine']);
        Route::put('/issuances/{id}/lines/{lineId}',    [\App\Http\Controllers\Api\IssuanceOrderController::class, 'updateLine']);
        Route::delete('/issuances/{id}/lines/{lineId}', [\App\Http\Controllers\Api\IssuanceOrderController::class, 'removeLine']);
    });
```

- [ ] **Step 5: Change `IssuanceScanController` to open at Draft and gate the scanner**

In `initSession()`, replace the status on the `Issuance::create([...])` call:

```php
            'status_id'        => \App\Support\IssuanceStatus::draft(),
```

In `scanItem()`, replace the existing status guard:

```php
        $issuance = Issuance::find($validated['issuance_id']);
        if (! $issuance || $issuance->status_id !== self::STATUS_ISSUANCE_PENDING) {
```

with:

```php
        $issuance = Issuance::find($validated['issuance_id']);

        if (! $issuance) {
            return response()->json([
                'success' => false,
                'action'  => 'rejected',
                'reason'  => 'Issuance session not found.',
            ], 404);
        }

        if ((int) $issuance->status_id === \App\Support\IssuanceStatus::draft()) {
            return response()->json([
                'success' => false,
                'action'  => 'rejected',
                'reason'  => 'This order is still a draft. Confirm the quote with the customer before scanning.',
            ], 422);
        }

        if ((int) $issuance->status_id !== self::STATUS_ISSUANCE_PENDING) {
```

Keep the body of the original guard as the body of this final `if`. Do not delete the `STATUS_ISSUANCE_PENDING` constant — the rest of the controller still uses it, and it still equals 30.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=DraftLineEndpointsTest`
Expected: PASS, 5 tests.

- [ ] **Step 7: Confirm nothing else regressed**

Run: `php artisan test`
Expected: the full suite green. The two pre-existing `tests/Feature/Issuance/` tests create their issuances directly with `status_id`, so they are unaffected; if either fails, it was relying on `initSession()` returning Pending — update it to confirm the quote first.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api/IssuanceOrderController.php app/Http/Controllers/Api/IssuanceScanController.php routes/api.php tests/Feature/Sets/DraftLineEndpointsTest.php
git commit -m "feat(issuance): draft line endpoints and scanner gate behind confirmed quote"
```

---


## Phase 3 — The FIFO Engine (Tasks 13–17)

### Task 13: `resolveScan()` on the NormalizesBarcode trait

**Files:**
- Modify: `app/Traits/NormalizesBarcode.php`
- Test: `tests/Feature/Sets/BarcodeResolveTest.php`

`app/Traits/NormalizesBarcode.php` already owns `barcodeLookupCandidates()` (line 15) and `findProductByBarcodeCandidates()` (line 58), and is used by `IssuanceScanController`, `BarcodeScanController`, `TransferService`, and `WarrantyClaimService`. What is *not* shared is the 3-pass sequence in `IssuanceScanController::scanItem()` at lines 153–199. This task lifts that sequence into the trait so `FifoPickingService` can use it without importing a controller.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Sets;

use App\Traits\NormalizesBarcode;
use Illuminate\Support\Facades\DB;

class BarcodeResolveTest extends SetTestCase
{
    /** Exposes the protected trait method for testing. */
    private function resolver(): object
    {
        return new class {
            use NormalizesBarcode;

            public function call(string $barcode, int $locationId): array
            {
                return $this->resolveScan($barcode, $locationId);
            }
        };
    }

    public function test_resolves_an_appliance_by_serial_number(): void
    {
        $loc     = $this->makeLocation();
        $product = $this->makeAppliance();
        $serial  = $this->makeSerial($product, $loc, 'SN-RESOLVE-1');
        DB::table('item_serial')->where('serial_id', $serial->serial_id)->update(['status_id' => 26]);

        $out = $this->resolver()->call('SN-RESOLVE-1', $loc->location_id);

        $this->assertSame((int) $product->product_id, (int) $out['product']->product_id);
        $this->assertNotNull($out['serial']);
        $this->assertSame('serial', $out['matched_by']);
    }

    public function test_resolves_a_consumable_by_product_barcode(): void
    {
        $loc     = $this->makeLocation();
        $product = $this->makeConsumable(barcode: 'BC-RESOLVE-2');

        $out = $this->resolver()->call('BC-RESOLVE-2', $loc->location_id);

        $this->assertSame((int) $product->product_id, (int) $out['product']->product_id);
        $this->assertNull($out['serial']);
        $this->assertSame('product_barcode', $out['matched_by']);
    }

    public function test_barcode_matching_tolerates_case_and_whitespace(): void
    {
        $loc     = $this->makeLocation();
        $product = $this->makeConsumable(barcode: 'BC-RESOLVE-3');

        $out = $this->resolver()->call('  bc-resolve-3 ', $loc->location_id);

        $this->assertSame((int) $product->product_id, (int) $out['product']->product_id);
    }

    public function test_unknown_barcode_resolves_to_nothing(): void
    {
        $loc = $this->makeLocation();

        $out = $this->resolver()->call('NOPE-' . uniqid(), $loc->location_id);

        $this->assertNull($out['product']);
        $this->assertNull($out['serial']);
        $this->assertNull($out['matched_by']);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --filter=BarcodeResolveTest`
Expected: FAIL — `Call to undefined method ...::resolveScan()`.

- [ ] **Step 3: Add `resolveScan()` to `app/Traits/NormalizesBarcode.php`**

Append this method to the trait. It is the sequence currently inline at `IssuanceScanController.php:153-199`, unchanged in behavior.

```php
    /**
     * Resolve a scanned barcode to a product, and to a specific serial when the
     * barcode is a serial number. Three passes, in priority order:
     *   1. item_serial.serial_number, scoped to the location and in-stock
     *   2. products.barcode / products.product_code
     *   3. receiving_details.scanned_barcode — the label applied at PO receiving,
     *      which is not stored on the product row
     *
     * @return array{product: ?\App\Models\Product, serial: ?\App\Models\ItemSerial, matched_by: ?string}
     */
    protected function resolveScan(string $rawBarcode, int $locationId): array
    {
        $candidates = $this->barcodeLookupCandidates($rawBarcode);

        foreach ($candidates as $candidate) {
            $serial = ItemSerial::issuableAt($locationId, $candidate)->first();
            if ($serial) {
                return [
                    'product'    => Product::with('applianceDetail.brand')->find($serial->product_id),
                    'serial'     => $serial,
                    'matched_by' => 'serial',
                ];
            }
        }

        $product = $this->findProductByBarcodeCandidates($candidates);
        if ($product) {
            $product->load(['consumableDetail.unit']);

            return ['product' => $product, 'serial' => null, 'matched_by' => 'product_barcode'];
        }

        foreach ($candidates as $candidate) {
            $norm = strtolower(trim($candidate));
            if ($norm === '') {
                continue;
            }

            $detail = \Illuminate\Support\Facades\DB::table('receiving_details')
                ->whereRaw('LOWER(TRIM(COALESCE(scanned_barcode,\'\'))) = ?', [$norm])
                ->whereNotNull('product_id')
                ->select('product_id')
                ->first();

            if ($detail) {
                return [
                    'product'    => Product::with(['applianceDetail.brand', 'consumableDetail.unit'])
                                           ->find($detail->product_id),
                    'serial'     => null,
                    'matched_by' => 'receiving_barcode',
                ];
            }
        }

        return ['product' => null, 'serial' => null, 'matched_by' => null];
    }
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --filter=BarcodeResolveTest`
Expected: PASS, 4 tests.

- [ ] **Step 5: Replace the inline sequence in `IssuanceScanController::scanItem()`**

Delete lines 153–199 (the `$candidates = ...` assignment through the end of the Pass 3 block) and substitute:

```php
        $resolved     = $this->resolveScan($raw, $locationId);
        $product      = $resolved['product'];
        $serialRecord = $resolved['serial'];
        $fifoWarning  = $serialRecord !== null ? $this->fifoWarningFor($serialRecord, $locationId) : null;
```

Everything after the `if (! $product)` rejection stays exactly as it is. `$fifoWarning` is still advisory here — Task 16 replaces it with the enforcing check.

- [ ] **Step 6: Confirm the existing issuance tests still pass**

Run: `php artisan test --filter=Issuance`
Expected: PASS. This step is the whole point of extracting behavior rather than rewriting it — if anything fails, the extraction changed semantics and must be corrected, not the test.

- [ ] **Step 7: Commit**

```bash
git add app/Traits/NormalizesBarcode.php app/Http/Controllers/Api/IssuanceScanController.php tests/Feature/Sets/BarcodeResolveTest.php
git commit -m "refactor(scan): lift 3-pass barcode resolution into NormalizesBarcode::resolveScan"
```

---

### Task 14: ScanResult and `FifoPickingService::suggest()`

**Files:**
- Create: `app/Support/ScanResult.php`, `app/Services/FifoPickingService.php`
- Test: `tests/Feature/Sets/FifoSuggestTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Sets;

use App\Services\FifoPickingService;

class FifoSuggestTest extends SetTestCase
{
    public function test_consumable_suggestions_walk_batches_oldest_first(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable();

        $this->makeBatch($p, $loc, ['quantity_remaining' => 4, 'received_at' => now()->subDays(10), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 9, 'received_at' => now()->subDays(2),  'zone_label' => 'Zone 2']);

        $out = app(FifoPickingService::class)->suggest($loc->location_id, [
            ['product_id' => $p->product_id, 'quantity' => 6],
        ]);

        $steps = $out[0]['suggestions'];
        $this->assertCount(2, $steps);
        $this->assertSame('Zone 1', $steps[0]['zone_label']);
        $this->assertSame(4, $steps[0]['quantity']);
        $this->assertSame('Zone 2', $steps[1]['zone_label']);
        $this->assertSame(2, $steps[1]['quantity']);
    }

    public function test_appliance_suggestions_list_serials_from_the_oldest_batch_first(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeAppliance();

        $oldBatch = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now()->subDays(30), 'zone_label' => 'Zone 1']);
        $newBatch = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now()->subDay(),    'zone_label' => 'Zone 2']);

        $this->makeSerial($p, $loc, 'SN-NEW', $newBatch->receiving_id);
        $this->makeSerial($p, $loc, 'SN-OLD', $oldBatch->receiving_id);

        $out = app(FifoPickingService::class)->suggest($loc->location_id, [
            ['product_id' => $p->product_id, 'quantity' => 1],
        ]);

        $steps = $out[0]['suggestions'];
        $this->assertSame('SN-OLD', $steps[0]['serial_number']);
        $this->assertSame('Zone 1', $steps[0]['zone_label']);
    }

    public function test_suggestions_stop_once_the_requested_quantity_is_covered(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable();

        $this->makeBatch($p, $loc, ['quantity_remaining' => 50, 'received_at' => now()->subDays(5), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 50, 'received_at' => now()->subDay(),   'zone_label' => 'Zone 2']);

        $out = app(FifoPickingService::class)->suggest($loc->location_id, [
            ['product_id' => $p->product_id, 'quantity' => 3],
        ]);

        $this->assertCount(1, $out[0]['suggestions']);
        $this->assertSame(3, $out[0]['suggestions'][0]['quantity']);
    }

    public function test_a_product_with_no_stock_yields_no_suggestions(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable();

        $out = app(FifoPickingService::class)->suggest($loc->location_id, [
            ['product_id' => $p->product_id, 'quantity' => 2],
        ]);

        $this->assertSame([], $out[0]['suggestions']);
        $this->assertSame(2, $out[0]['needed']);
    }

    public function test_empty_batches_are_skipped(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable();

        $this->makeBatch($p, $loc, ['quantity_remaining' => 0, 'received_at' => now()->subDays(20), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(2),  'zone_label' => 'Zone 2']);

        $out = app(FifoPickingService::class)->suggest($loc->location_id, [
            ['product_id' => $p->product_id, 'quantity' => 2],
        ]);

        $this->assertCount(1, $out[0]['suggestions']);
        $this->assertSame('Zone 2', $out[0]['suggestions'][0]['zone_label']);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --filter=FifoSuggestTest`
Expected: FAIL — `Target class [App\Services\FifoPickingService] does not exist`.

- [ ] **Step 3: Write `app/Support/ScanResult.php`**

```php
<?php

namespace App\Support;

class ScanResult
{
    public const ACCEPTED        = 'ACCEPTED';
    public const UNKNOWN_BARCODE = 'UNKNOWN_BARCODE';
    public const WRONG_PRODUCT   = 'WRONG_PRODUCT';
    public const OVER_PICK       = 'OVER_PICK';
    public const FIFO_VIOLATION  = 'FIFO_VIOLATION';

    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?int $productId = null,
        public readonly ?string $productName = null,
        public readonly ?int $serialId = null,
        public readonly ?string $serialNumber = null,
        public readonly ?int $batchId = null,
        public readonly ?string $zoneLabel = null,
        public readonly ?int $expectedBatchId = null,
        public readonly ?string $expectedZoneLabel = null,
        public readonly ?string $expectedReceivedAt = null,
    ) {
    }

    public function accepted(): bool
    {
        return $this->status === self::ACCEPTED;
    }

    /** Only a FIFO violation may be overridden, and only by filing an incident. */
    public function overridable(): bool
    {
        return $this->status === self::FIFO_VIOLATION;
    }

    public function toArray(): array
    {
        return [
            'status'               => $this->status,
            'accepted'             => $this->accepted(),
            'overridable'          => $this->overridable(),
            'message'              => $this->message,
            'product_id'           => $this->productId,
            'product_name'         => $this->productName,
            'serial_id'            => $this->serialId,
            'serial_number'        => $this->serialNumber,
            'batch_id'             => $this->batchId,
            'zone_label'           => $this->zoneLabel,
            'expected_batch_id'    => $this->expectedBatchId,
            'expected_zone_label'  => $this->expectedZoneLabel,
            'expected_received_at' => $this->expectedReceivedAt,
        ];
    }
}
```

- [ ] **Step 4: Write `app/Services/FifoPickingService.php` with `suggest()` only**

`validateScan()` arrives in Task 15.

```php
<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Traits\NormalizesBarcode;

/**
 * The single source of truth for FIFO order across every picking flow.
 * Stateless: it reads inventory and returns verdicts, and writes nothing.
 * Issuance picking and transfer dispatch both go through here so the rule
 * cannot drift between them.
 */
class FifoPickingService
{
    use NormalizesBarcode;

    /**
     * Oldest-first pick steps for a set of lines at one location.
     *
     * @param  array<int,array{product_id:int,quantity:int}>  $lines
     * @return array<int,array{product_id:int,product_name:?string,needed:int,suggestions:array}>
     */
    public function suggest(int $locationId, array $lines, array $excludedBatchIds = []): array
    {
        $out = [];

        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            $needed    = max(0, (int) $line['quantity']);
            $product   = Product::find($productId);

            $out[] = [
                'product_id'   => $productId,
                'product_name' => $product?->product_name,
                'needed'       => $needed,
                'suggestions'  => $this->isSerialized($product)
                    ? $this->serialSteps($locationId, $productId, $needed, $excludedBatchIds)
                    : $this->batchSteps($locationId, $productId, $needed, $excludedBatchIds),
            ];
        }

        return $out;
    }

    /** Batches with stock left, oldest first, excluding anything under an open incident. */
    public function orderedBatches(int $locationId, int $productId, array $excludedBatchIds = [])
    {
        return InventoryBatch::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('quantity_remaining', '>', 0)
            ->when($excludedBatchIds !== [], fn ($q) => $q->whereNotIn('batch_id', $excludedBatchIds))
            ->orderBy('received_at', 'asc')
            ->orderBy('batch_id', 'asc')
            ->get();
    }

    protected function isSerialized(?Product $product): bool
    {
        if ($product === null) {
            return false;
        }

        if ($product->product_type === 'appliance') {
            return true;
        }

        return (bool) ($product->applianceDetail?->is_serialized ?? false);
    }

    protected function batchSteps(int $locationId, int $productId, int $needed, array $excluded): array
    {
        $steps     = [];
        $remaining = $needed;

        foreach ($this->orderedBatches($locationId, $productId, $excluded) as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (int) $batch->quantity_remaining);

            $steps[] = [
                'serial_number' => null,
                'serial_id'     => null,
                'batch_id'      => (int) $batch->batch_id,
                'zone_label'    => $batch->zone_label,
                'received_at'   => optional($batch->received_at)->toDateTimeString(),
                'quantity'      => $take,
            ];

            $remaining -= $take;
        }

        return $steps;
    }

    protected function serialSteps(int $locationId, int $productId, int $needed, array $excluded): array
    {
        if ($needed <= 0) {
            return [];
        }

        // item_serial has no batch_id; the batch is reached through receiving_id,
        // scoped to this location — the same join TransferService::pickList() used.
        $rows = ItemSerial::where('item_serial.product_id', $productId)
            ->where('item_serial.location_id', $locationId)
            ->where('item_serial.serial_type', 'received')
            ->whereNull('item_serial.transfer_id')
            ->leftJoin('inventory_batches as b', function ($join) use ($locationId) {
                $join->on('b.receiving_id', '=', 'item_serial.receiving_id')
                     ->where('b.location_id', '=', $locationId);
            })
            ->when($excluded !== [], fn ($q) => $q->where(function ($w) use ($excluded) {
                $w->whereNull('b.batch_id')->orWhereNotIn('b.batch_id', $excluded);
            }))
            ->orderByRaw('b.received_at IS NULL, b.received_at ASC')
            ->orderBy('item_serial.serial_id')
            ->limit($needed)
            ->get([
                'item_serial.serial_id',
                'item_serial.serial_number',
                'b.batch_id',
                'b.zone_label',
                'b.received_at',
            ]);

        return $rows->map(fn ($row) => [
            'serial_number' => $row->serial_number,
            'serial_id'     => (int) $row->serial_id,
            'batch_id'      => $row->batch_id !== null ? (int) $row->batch_id : null,
            'zone_label'    => $row->zone_label,
            'received_at'   => $row->received_at,
            'quantity'      => 1,
        ])->all();
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=FifoSuggestTest`
Expected: PASS, 5 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Support/ScanResult.php app/Services/FifoPickingService.php tests/Feature/Sets/FifoSuggestTest.php
git commit -m "feat(picking): FifoPickingService::suggest and ScanResult value object"
```

---

### Task 15: `FifoPickingService::validateScan()`

**Files:**
- Modify: `app/Services/FifoPickingService.php`
- Test: `tests/Feature/Sets/FifoValidateScanTest.php`

The verdict rule: a scan is a FIFO violation when **any older, non-excluded batch of that same product still holds stock at that location**. It is deliberately *not* a comparison against a previously issued suggestion — stock moves while a picker walks the aisle, and a stale snapshot would fire false violations.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Sets;

use App\Services\FifoPickingService;
use App\Support\ScanResult;
use Illuminate\Support\Facades\DB;

class FifoValidateScanTest extends SetTestCase
{
    public function test_scanning_the_oldest_zone_is_accepted(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable(barcode: 'BC-FIFO-OK');
        $old = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDay(), 'zone_label' => 'Zone 2']);

        $result = app(FifoPickingService::class)->validateScan(
            $loc->location_id,
            [['product_id' => $p->product_id, 'quantity' => 3, 'picked' => 0]],
            'BC-FIFO-OK',
            'Zone 1'
        );

        $this->assertSame(ScanResult::ACCEPTED, $result->status);
        $this->assertSame((int) $old->batch_id, $result->batchId);
    }

    public function test_scanning_a_newer_zone_is_a_fifo_violation(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable(barcode: 'BC-FIFO-BAD');
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDay(), 'zone_label' => 'Zone 2']);

        $result = app(FifoPickingService::class)->validateScan(
            $loc->location_id,
            [['product_id' => $p->product_id, 'quantity' => 3, 'picked' => 0]],
            'BC-FIFO-BAD',
            'Zone 2'
        );

        $this->assertSame(ScanResult::FIFO_VIOLATION, $result->status);
        $this->assertTrue($result->overridable());
        $this->assertSame('Zone 1', $result->expectedZoneLabel);
    }

    public function test_a_product_not_on_the_list_is_a_hard_block(): void
    {
        $loc     = $this->makeLocation();
        $onList  = $this->makeConsumable();
        $offList = $this->makeConsumable(barcode: 'BC-NOT-LISTED');
        $this->makeBatch($offList, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $result = app(FifoPickingService::class)->validateScan(
            $loc->location_id,
            [['product_id' => $onList->product_id, 'quantity' => 1, 'picked' => 0]],
            'BC-NOT-LISTED',
            'Zone 1'
        );

        $this->assertSame(ScanResult::WRONG_PRODUCT, $result->status);
        $this->assertFalse($result->overridable(), 'an incident must never legitimise a wrong product');
    }

    public function test_unknown_barcode_is_a_hard_block(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable();

        $result = app(FifoPickingService::class)->validateScan(
            $loc->location_id,
            [['product_id' => $p->product_id, 'quantity' => 1, 'picked' => 0]],
            'NOTHING-' . uniqid(),
            null
        );

        $this->assertSame(ScanResult::UNKNOWN_BARCODE, $result->status);
        $this->assertFalse($result->overridable());
    }

    public function test_scanning_past_the_line_quantity_is_over_pick(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable(barcode: 'BC-OVER');
        $this->makeBatch($p, $loc, ['quantity_remaining' => 9, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $result = app(FifoPickingService::class)->validateScan(
            $loc->location_id,
            [['product_id' => $p->product_id, 'quantity' => 2, 'picked' => 2]],
            'BC-OVER',
            'Zone 1'
        );

        $this->assertSame(ScanResult::OVER_PICK, $result->status);
        $this->assertFalse($result->overridable());
    }

    public function test_an_excluded_batch_no_longer_blocks_the_next_zone(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable(barcode: 'BC-EXCLUDED');
        $old = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDay(), 'zone_label' => 'Zone 2']);

        $result = app(FifoPickingService::class)->validateScan(
            $loc->location_id,
            [['product_id' => $p->product_id, 'quantity' => 3, 'picked' => 0]],
            'BC-EXCLUDED',
            'Zone 2',
            null,
            [(int) $old->batch_id]
        );

        $this->assertSame(ScanResult::ACCEPTED, $result->status);
    }

    public function test_a_serial_from_the_newer_batch_is_a_violation(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeAppliance();
        $oldBatch = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now()->subDays(30), 'zone_label' => 'Zone 1']);
        $newBatch = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now()->subDay(),    'zone_label' => 'Zone 2']);

        $oldSerial = $this->makeSerial($p, $loc, 'SN-OLD-V', $oldBatch->receiving_id);
        $newSerial = $this->makeSerial($p, $loc, 'SN-NEW-V', $newBatch->receiving_id);
        DB::table('item_serial')->whereIn('serial_id', [$oldSerial->serial_id, $newSerial->serial_id])
          ->update(['status_id' => 26]);

        $svc   = app(FifoPickingService::class);
        $lines = [['product_id' => $p->product_id, 'quantity' => 1, 'picked' => 0]];

        $bad = $svc->validateScan($loc->location_id, $lines, 'SN-NEW-V', null);
        $this->assertSame(ScanResult::FIFO_VIOLATION, $bad->status);
        $this->assertSame('Zone 1', $bad->expectedZoneLabel);

        $good = $svc->validateScan($loc->location_id, $lines, 'SN-OLD-V', null);
        $this->assertSame(ScanResult::ACCEPTED, $good->status);
        $this->assertSame('SN-OLD-V', $good->serialNumber);
    }

    public function test_an_older_batch_appearing_mid_pick_makes_the_next_scan_a_violation(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable(barcode: 'BC-MIDPICK');
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(2), 'zone_label' => 'Zone 2']);

        $svc   = app(FifoPickingService::class);
        $lines = [['product_id' => $p->product_id, 'quantity' => 4, 'picked' => 0]];

        // Zone 2 is the only stock, so it is legitimately the oldest.
        $this->assertSame(ScanResult::ACCEPTED, $svc->validateScan($loc->location_id, $lines, 'BC-MIDPICK', 'Zone 2')->status);

        // A transfer lands older stock in Zone 1 while the picker is still walking.
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(20), 'zone_label' => 'Zone 1']);

        $after = $svc->validateScan($loc->location_id, $lines, 'BC-MIDPICK', 'Zone 2');
        $this->assertSame(ScanResult::FIFO_VIOLATION, $after->status);
        $this->assertSame('Zone 1', $after->expectedZoneLabel);
    }

    public function test_a_consumable_scan_with_no_zone_confirmation_is_accepted_against_the_oldest_batch(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable(barcode: 'BC-NOZONE');
        $old = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(3), 'zone_label' => null]);

        $result = app(FifoPickingService::class)->validateScan(
            $loc->location_id,
            [['product_id' => $p->product_id, 'quantity' => 1, 'picked' => 0]],
            'BC-NOZONE',
            null
        );

        $this->assertSame(ScanResult::ACCEPTED, $result->status);
        $this->assertSame((int) $old->batch_id, $result->batchId);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --filter=FifoValidateScanTest`
Expected: FAIL — `Call to undefined method App\Services\FifoPickingService::validateScan()`.

- [ ] **Step 3: Add `validateScan()` to `FifoPickingService`**

```php
    /**
     * Judge one scan against a document's line list.
     *
     * @param  array<int,array{product_id:int,quantity:int,picked:int}>  $lines
     * @param  string|null  $confirmedZone  zone the picker confirmed (consumables)
     * @param  string|null  $unitHint       reserved for unit-aware picking; unused today
     * @param  array<int,int>  $excludedBatchIds  batches under an open incident
     */
    public function validateScan(
        int $locationId,
        array $lines,
        string $barcode,
        ?string $confirmedZone = null,
        ?string $unitHint = null,
        array $excludedBatchIds = []
    ): ScanResult {
        $resolved = $this->resolveScan($barcode, $locationId);
        $product  = $resolved['product'];
        $serial   = $resolved['serial'];

        if ($product === null) {
            return new ScanResult(
                status:  ScanResult::UNKNOWN_BARCODE,
                message: 'Barcode not recognised. Check the label, or that the item is stocked at this location.'
            );
        }

        $productId = (int) $product->product_id;
        $line      = collect($lines)->firstWhere('product_id', $productId);

        if ($line === null) {
            return new ScanResult(
                status:      ScanResult::WRONG_PRODUCT,
                message:     "{$product->product_name} is not on this order. Remove it and scan a listed item.",
                productId:   $productId,
                productName: $product->product_name
            );
        }

        if ((int) ($line['picked'] ?? 0) >= (int) $line['quantity']) {
            return new ScanResult(
                status:      ScanResult::OVER_PICK,
                message:     "{$product->product_name} is already fully picked for this order.",
                productId:   $productId,
                productName: $product->product_name
            );
        }

        $oldest = $this->orderedBatches($locationId, $productId, $excludedBatchIds)->first();

        // No batch record at all — legacy stock predating inventory_batches.
        // There is nothing to order against, so let it through rather than
        // blocking a picker over missing history.
        if ($oldest === null) {
            return new ScanResult(
                status:       ScanResult::ACCEPTED,
                message:      'Accepted.',
                productId:    $productId,
                productName:  $product->product_name,
                serialId:     $serial !== null ? (int) $serial->serial_id : null,
                serialNumber: $serial?->serial_number
            );
        }

        $scannedBatch = $serial !== null
            ? $this->batchForSerial($locationId, $serial)
            : $this->batchForZone($locationId, $productId, $confirmedZone, $excludedBatchIds);

        $isOldest = $scannedBatch === null
            ? $confirmedZone === null   // consumable scan with no zone confirmation trusts the plan
            : (int) $scannedBatch->batch_id === (int) $oldest->batch_id;

        if (! $isOldest) {
            $expectedZone = $oldest->zone_label ?? 'the oldest batch';

            return new ScanResult(
                status:  ScanResult::FIFO_VIOLATION,
                message: "Oldest stock of {$product->product_name} is in {$expectedZone}"
                         . ($oldest->received_at ? ' (received ' . $oldest->received_at->format('M j, Y') . ')' : '')
                         . '. File an Incident Report to pick from elsewhere.',
                productId:          $productId,
                productName:        $product->product_name,
                serialId:           $serial !== null ? (int) $serial->serial_id : null,
                serialNumber:       $serial?->serial_number,
                batchId:            $scannedBatch !== null ? (int) $scannedBatch->batch_id : null,
                zoneLabel:          $scannedBatch->zone_label ?? $confirmedZone,
                expectedBatchId:    (int) $oldest->batch_id,
                expectedZoneLabel:  $oldest->zone_label,
                expectedReceivedAt: optional($oldest->received_at)->toDateTimeString()
            );
        }

        return new ScanResult(
            status:       ScanResult::ACCEPTED,
            message:      'Accepted.',
            productId:    $productId,
            productName:  $product->product_name,
            serialId:     $serial !== null ? (int) $serial->serial_id : null,
            serialNumber: $serial?->serial_number,
            batchId:      (int) $oldest->batch_id,
            zoneLabel:    $oldest->zone_label
        );
    }

    protected function batchForSerial(int $locationId, ItemSerial $serial): ?InventoryBatch
    {
        if ($serial->receiving_id === null) {
            return null;
        }

        return InventoryBatch::where('location_id', $locationId)
            ->where('receiving_id', $serial->receiving_id)
            ->where('product_id', $serial->product_id)
            ->first();
    }

    protected function batchForZone(int $locationId, int $productId, ?string $zone, array $excluded): ?InventoryBatch
    {
        if ($zone === null) {
            return null;
        }

        return $this->orderedBatches($locationId, $productId, $excluded)
            ->first(fn ($batch) => strcasecmp(trim((string) $batch->zone_label), trim($zone)) === 0);
    }
```

Add `use App\Support\ScanResult;` to the service's imports.

- [ ] **Step 4: Run the tests**

Run: `php artisan test --filter=FifoValidateScanTest`
Expected: PASS, 9 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/FifoPickingService.php tests/Feature/Sets/FifoValidateScanTest.php
git commit -m "feat(picking): enforcing validateScan with wrong-product, over-pick and FIFO verdicts"
```

---

### Task 16: Wire enforcement into the issuance scanner, add the pick-plan endpoint

**Files:**
- Modify: `app/Http/Controllers/Api/IssuanceScanController.php`, `app/Http/Controllers/Api/IssuanceOrderController.php`, `routes/api.php`
- Test: `tests/Feature/Sets/IssuancePickEnforcementTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Sets;

use App\Models\Issuance;
use App\Support\IssuanceStatus;

class IssuancePickEnforcementTest extends SetTestCase
{
    private function confirmedOrder($loc, array $lines): Issuance
    {
        $issuance = Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $this->makeCustomer()->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::pending(),
        ]);

        foreach ($lines as [$product, $qty]) {
            $issuance->details()->create([
                'product_id'         => $product->product_id,
                'quantity_requested' => $qty,
                'quantity_issued'    => 0,
                'unit_price'         => (float) $product->unit_price,
                'subtotal'           => $qty * (float) $product->unit_price,
            ]);
        }

        return $issuance->fresh('details');
    }

    public function test_pick_plan_lists_steps_oldest_first(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable();
        $this->setStock($loc->location_id, $p->product_id, 20);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 4, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 9, 'received_at' => now()->subDay(),  'zone_label' => 'Zone 2']);

        $issuance = $this->confirmedOrder($loc, [[$p, 6]]);

        $res = $this->actingAs($user, 'sanctum')
                    ->getJson("/api/issuances/{$issuance->issuance_id}/pick-plan");

        $res->assertOk();
        $this->assertSame('Zone 1', $res->json('data.0.suggestions.0.zone_label'));
        $this->assertSame(6, (int) $res->json('data.0.needed'));
    }

    public function test_scanning_a_product_not_on_the_order_is_rejected(): void
    {
        $loc     = $this->makeLocation();
        $user    = $this->makeUserAt($loc, 'inventory_analyst');
        $onList  = $this->makeConsumable();
        $offList = $this->makeConsumable(barcode: 'BC-OFFLIST');
        $this->setStock($loc->location_id, $onList->product_id, 10);
        $this->setStock($loc->location_id, $offList->product_id, 10);
        $this->makeBatch($offList, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $issuance = $this->confirmedOrder($loc, [[$onList, 2]]);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode'     => 'BC-OFFLIST',
        ]);

        $res->assertStatus(422);
        $this->assertSame('WRONG_PRODUCT', $res->json('scan_result.status'));
        $this->assertFalse($res->json('scan_result.overridable'));
        $this->assertSame(0, (int) $issuance->fresh('details')->details->sum('quantity_issued'));
    }

    public function test_scanning_out_of_fifo_order_is_rejected_as_overridable(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(barcode: 'BC-ENFORCE');
        $this->setStock($loc->location_id, $p->product_id, 20);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDay(),  'zone_label' => 'Zone 2']);

        $issuance = $this->confirmedOrder($loc, [[$p, 2]]);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/issuance-scan/scan', [
            'issuance_id'    => $issuance->issuance_id,
            'barcode'        => 'BC-ENFORCE',
            'confirmed_zone' => 'Zone 2',
        ]);

        $res->assertStatus(422);
        $this->assertSame('FIFO_VIOLATION', $res->json('scan_result.status'));
        $this->assertTrue($res->json('scan_result.overridable'));
        $this->assertSame('Zone 1', $res->json('scan_result.expected_zone_label'));
    }

    public function test_scanning_the_oldest_zone_still_commits_the_line(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(barcode: 'BC-COMMIT');
        $this->setStock($loc->location_id, $p->product_id, 20);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);

        $issuance = $this->confirmedOrder($loc, [[$p, 2]]);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/issuance-scan/scan', [
            'issuance_id'    => $issuance->issuance_id,
            'barcode'        => 'BC-COMMIT',
            'quantity'       => 1,
            'confirmed_zone' => 'Zone 1',
        ]);

        $res->assertOk();
        $this->assertGreaterThan(0, (int) $issuance->fresh('details')->details->sum('quantity_issued'));
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --filter=IssuancePickEnforcementTest`
Expected: FAIL — no `pick-plan` route, and the scan endpoint returns no `scan_result` key.

- [ ] **Step 3: Add `pickPlan()` to `IssuanceOrderController`**

```php
    public function pickPlan(int $id): JsonResponse
    {
        $issuance = Issuance::with('details.product')->findOrFail($id);

        $lines = $issuance->details->map(fn ($detail) => [
            'product_id' => (int) $detail->product_id,
            'quantity'   => max(0, (int) $detail->quantity_requested - (int) $detail->quantity_issued),
        ])->filter(fn ($line) => $line['quantity'] > 0)->values()->all();

        $excluded = app(\App\Services\IncidentService::class)
            ->excludedBatchIds((int) $issuance->location_id);

        return response()->json([
            'success' => true,
            'data'    => app(\App\Services\FifoPickingService::class)
                ->suggest((int) $issuance->location_id, $lines, $excluded),
        ]);
    }
```

`IncidentService` does not exist until Task 18. Until then, substitute `$excluded = [];` and restore the call in Task 18 — the pick-plan test does not exercise exclusions.

- [ ] **Step 4: Register the route**

```php
    Route::middleware('permission:inventory_issuance,read')
         ->get('/issuances/{id}/pick-plan', [\App\Http\Controllers\Api\IssuanceOrderController::class, 'pickPlan']);
```

Declare it before the existing `/issuances/{id}` route.

- [ ] **Step 5: Enforce in `IssuanceScanController::scanItem()`**

Add `confirmed_zone` to the validation rules:

```php
            'confirmed_zone' => 'nullable|string|max:100',
```

Then, immediately after the `$locationId` assignment and **before** the barcode resolution added in Task 13, insert the verdict:

```php
        $lines = $issuance->details->map(fn ($detail) => [
            'product_id' => (int) $detail->product_id,
            'quantity'   => (int) $detail->quantity_requested,
            'picked'     => (int) $detail->quantity_issued,
        ])->values()->all();

        $verdict = app(\App\Services\FifoPickingService::class)->validateScan(
            $locationId,
            $lines,
            trim($validated['barcode']),
            $validated['confirmed_zone'] ?? null,
            null,
            app(\App\Services\IncidentService::class)->excludedBatchIds($locationId)
        );

        if (! $verdict->accepted()) {
            return response()->json([
                'success'     => false,
                'action'      => 'rejected',
                'reason'      => $verdict->message,
                'scan_result' => $verdict->toArray(),
            ], 422);
        }
```

Load `$issuance` with its details (`Issuance::with('details')->find(...)`) so `$issuance->details` is populated. As in Step 3, pass `[]` for the excluded ids until Task 18 lands `IncidentService`.

Delete the now-dead `$fifoWarning` variable and every reference to it, including the `fifo_warning` key in the success response and the `fifoWarningFor()` method itself. Advisory warning and hard enforcement must not coexist — two FIFO opinions in one endpoint is exactly the drift this design exists to prevent.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=IssuancePickEnforcementTest`
Expected: PASS, 4 tests.

- [ ] **Step 7: Run the whole suite**

Run: `php artisan test`
Expected: green. `tests/Feature/Issuance/IssuanceBatchFifoTest.php` asserts the old advisory `fifo_warning` behavior — rewrite its assertions to expect a `FIFO_VIOLATION` rejection instead of a warning on an accepted scan. That is an intended behavior change, not a regression.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api/IssuanceScanController.php app/Http/Controllers/Api/IssuanceOrderController.php routes/api.php tests/Feature/Sets/IssuancePickEnforcementTest.php tests/Feature/Issuance/IssuanceBatchFifoTest.php
git commit -m "feat(issuance): enforce FIFO on scan and expose the pick plan"
```

---


### Task 16b: Settle and release reservations when an order ends

**Files:**
- Modify: `app/Http/Controllers/Api/IssuanceScanController.php` (`complete`), `app/Http/Controllers/Api/IssuanceController.php`
- Test: `tests/Feature/Sets/ReservationLifecycleTest.php`

Without this, a confirmed quote holds `available_quantity` down forever: completion moves `quantity_on_hand` but never closes the hold, so the stock is silently double-counted as unavailable. This task closes the loop at both exits — completion and abandonment.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Sets;

use App\Models\Inventory;
use App\Models\Issuance;
use App\Models\PickReservation;
use App\Support\IssuanceStatus;

class ReservationLifecycleTest extends SetTestCase
{
    private function confirmedOrder($loc, $product, int $qty): Issuance
    {
        $issuance = Issuance::create([
            'location_id'   => $loc->location_id,
            'customer_id'   => $this->makeCustomer()->customer_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'individual',
            'dr_number'     => 'DR-' . uniqid(),
            'status_id'     => IssuanceStatus::draft(),
        ]);

        $issuance->details()->create([
            'product_id'         => $product->product_id,
            'quantity_requested' => $qty,
            'quantity_issued'    => 0,
            'unit_price'         => (float) $product->unit_price,
            'subtotal'           => $qty * (float) $product->unit_price,
        ]);

        return $issuance;
    }

    public function test_completing_an_order_closes_its_holds_without_crediting_stock_back(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable(barcode: 'BC-SETTLE');
        $this->setStock($loc->location_id, $p->product_id, 10);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 10, 'received_at' => now()->subDays(5), 'zone_label' => 'Zone 1']);

        $issuance = $this->confirmedOrder($loc, $p, 2);
        $this->actingAs($user, 'sanctum')
             ->postJson("/api/issuances/{$issuance->issuance_id}/confirm-quote")->assertOk();

        $this->actingAs($user, 'sanctum')->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id, 'barcode' => 'BC-SETTLE',
            'quantity' => 2, 'confirmed_zone' => 'Zone 1',
        ])->assertOk();

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/issuance-scan/complete/{$issuance->issuance_id}")->assertOk();

        $this->assertSame(0, (int) PickReservation::active()
            ->forSource('issuance', (int) $issuance->issuance_id)->count());

        $inv = Inventory::where('location_id', $loc->location_id)->where('product_id', $p->product_id)->first();
        $this->assertSame(8, (int) $inv->quantity_on_hand);
        $this->assertSame(8, (int) $inv->available_quantity, 'settling must not credit issued stock back');
    }

    public function test_voiding_an_order_returns_its_held_stock(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'inventory_analyst');
        $p    = $this->makeConsumable();
        $this->setStock($loc->location_id, $p->product_id, 10);

        $issuance = $this->confirmedOrder($loc, $p, 4);
        $this->actingAs($user, 'sanctum')
             ->postJson("/api/issuances/{$issuance->issuance_id}/confirm-quote")->assertOk();

        $this->assertSame(6, (int) Inventory::where('location_id', $loc->location_id)
            ->where('product_id', $p->product_id)->value('available_quantity'));

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/issuances/{$issuance->issuance_id}/void")->assertOk();

        $this->assertSame(IssuanceStatus::voided(), (int) $issuance->fresh()->status_id);
        $this->assertSame(10, (int) Inventory::where('location_id', $loc->location_id)
            ->where('product_id', $p->product_id)->value('available_quantity'));
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --filter=ReservationLifecycleTest`
Expected: FAIL — availability stays depressed after completion, and there is no `void` route.

- [ ] **Step 3: Settle on completion**

In `IssuanceScanController`'s complete method, inside the same transaction that sets the status to Completed and after the inventory writes, add:

```php
            app(\App\Services\ReservationService::class)
                ->settleFor('issuance', (int) $issuance->issuance_id);
```

- [ ] **Step 4: Add a void endpoint to `IssuanceController`**

```php
    public function void(int $id): JsonResponse
    {
        $issuance = Issuance::findOrFail($id);

        if ((int) $issuance->status_id === \App\Support\IssuanceStatus::completed()) {
            return response()->json([
                'success' => false,
                'message' => 'A completed issuance cannot be voided.',
            ], 422);
        }

        DB::transaction(function () use ($issuance) {
            app(\App\Services\ReservationService::class)
                ->releaseFor('issuance', (int) $issuance->issuance_id);

            $issuance->update(['status_id' => \App\Support\IssuanceStatus::voided()]);
        });

        return response()->json(['success' => true, 'message' => 'Issuance voided and held stock released.']);
    }
```

Add `use Illuminate\Http\JsonResponse;` and `use Illuminate\Support\Facades\DB;` if the controller lacks them.

Note the asymmetry, which is the whole point of two methods: `settleFor` closes holds and keeps availability where it is, because the goods left. `releaseFor` closes holds and gives availability back, because they did not.

- [ ] **Step 5: Register the route**

```php
    Route::middleware('permission:inventory_issuance,update')
         ->post('/issuances/{id}/void', [IssuanceController::class, 'void']);
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=ReservationLifecycleTest`
Expected: PASS, 2 tests.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/IssuanceScanController.php app/Http/Controllers/Api/IssuanceController.php app/Services/ReservationService.php routes/api.php tests/Feature/Sets/ReservationLifecycleTest.php
git commit -m "feat(issuance): settle holds on completion, release them on void"
```

---

## Phase 4 — Incident Reports (Tasks 17–20)

Verified facts this phase depends on: adjustment statuses are **19 Pending, 20 Approved, 29 Rejected**. `adjustment_details` columns are `adjustment_id, product_id, add_quantity, deduct_quantity, reason`. `scan_corrections` already exists with the columns listed in `app/Models/ScanCorrection.php`.

### Task 17: incident_reports table, statuses, and model

**Files:**
- Create: `database/migrations/2026_07_24_000004_create_incident_reports_table.php`
- Create: `app/Models/IncidentReport.php`
- Test: `tests/Feature/Sets/IncidentSchemaTest.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('incident_reports')) {
            Schema::create('incident_reports', function (Blueprint $table) {
                $table->id('incident_id');
                $table->string('incident_number', 40)->unique();
                $table->string('source_type', 20);            // issuance | transfer
                $table->unsignedBigInteger('source_id');
                $table->unsignedBigInteger('location_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('batch_id')->nullable();
                $table->unsignedBigInteger('serial_id')->nullable();
                $table->string('expected_zone', 100)->nullable();
                $table->string('actual_zone', 100)->nullable();
                $table->string('reason', 30);                 // not_found | damaged | wrong_type | zone_mismatch
                $table->integer('quantity')->default(0);
                $table->text('narrative')->nullable();
                $table->string('photo_path', 255)->nullable();
                $table->unsignedBigInteger('reported_by');
                $table->unsignedBigInteger('status_id');
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->dateTime('reviewed_at')->nullable();
                $table->unsignedBigInteger('adjustment_id')->nullable();
                $table->unsignedBigInteger('correction_id')->nullable();
                $table->text('resolution_note')->nullable();
                $table->timestamps();

                $table->index(['source_type', 'source_id'], 'incident_source_idx');
                $table->index(['location_id', 'status_id'], 'incident_loc_status_idx');
                $table->index(['batch_id', 'status_id'], 'incident_batch_status_idx');
                $table->index('reported_by');
            });
        }

        foreach (['Open', 'Resolved', 'Dismissed'] as $name) {
            $exists = DB::table('status_lookup')
                ->where('status_category', 'incident')
                ->where('status_name', $name)
                ->exists();

            if (! $exists) {
                DB::table('status_lookup')->insert([
                    'status_name'     => $name,
                    'status_category' => 'incident',
                    'is_active'       => 1,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_reports');
        DB::table('status_lookup')->where('status_category', 'incident')->delete();
    }
};
```

- [ ] **Step 2: Apply to both databases**

```bash
php artisan migrate --force --path=database/migrations/2026_07_24_000004_create_incident_reports_table.php
DB_DATABASE=airkingV5_test php artisan migrate --force --path=database/migrations/2026_07_24_000004_create_incident_reports_table.php
```

- [ ] **Step 3: Write `app/Models/IncidentReport.php`**

```php
<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserLocation;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class IncidentReport extends Model
{
    use Auditable, ScopesToUserLocation;

    public const REASON_NOT_FOUND     = 'not_found';
    public const REASON_DAMAGED       = 'damaged';
    public const REASON_WRONG_TYPE    = 'wrong_type';
    public const REASON_ZONE_MISMATCH = 'zone_mismatch';

    public const REASONS = [
        self::REASON_NOT_FOUND,
        self::REASON_DAMAGED,
        self::REASON_WRONG_TYPE,
        self::REASON_ZONE_MISMATCH,
    ];

    protected $table      = 'incident_reports';
    protected $primaryKey = 'incident_id';

    protected $fillable = [
        'incident_number', 'source_type', 'source_id', 'location_id',
        'product_id', 'batch_id', 'serial_id', 'expected_zone', 'actual_zone',
        'reason', 'quantity', 'narrative', 'photo_path', 'reported_by',
        'status_id', 'reviewed_by', 'reviewed_at',
        'adjustment_id', 'correction_id', 'resolution_note',
    ];

    protected $casts = [
        'quantity'    => 'integer',
        'reviewed_at' => 'datetime',
    ];

    /** @var array<string,int> */
    private static array $statusCache = [];

    public static function statusId(string $name): int
    {
        if (isset(self::$statusCache[$name])) {
            return self::$statusCache[$name];
        }

        $id = DB::table('status_lookup')
            ->where('status_category', 'incident')
            ->where('status_name', $name)
            ->value('status_id');

        if ($id === null) {
            throw new \RuntimeException("Unknown incident status: {$name}");
        }

        return self::$statusCache[$name] = (int) $id;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status_id', self::statusId('Open'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id', 'batch_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by', 'user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'user_id');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(Adjustment::class, 'adjustment_id', 'adjustment_id');
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(ScanCorrection::class, 'correction_id', 'correction_id');
    }
}
```

- [ ] **Step 4: Write the schema test**

```php
<?php

namespace Tests\Feature\Sets;

use App\Models\IncidentReport;
use Illuminate\Support\Facades\Schema;

class IncidentSchemaTest extends SetTestCase
{
    public function test_incident_table_and_statuses_exist(): void
    {
        $this->assertTrue(Schema::hasTable('incident_reports'));

        foreach (['incident_number', 'source_type', 'reason', 'expected_zone', 'actual_zone', 'adjustment_id', 'correction_id'] as $col) {
            $this->assertTrue(Schema::hasColumn('incident_reports', $col), "incident_reports missing {$col}");
        }

        $this->assertIsInt(IncidentReport::statusId('Open'));
        $this->assertIsInt(IncidentReport::statusId('Resolved'));
        $this->assertIsInt(IncidentReport::statusId('Dismissed'));
    }
}
```

- [ ] **Step 5: Run the test**

Run: `php artisan test --filter=IncidentSchemaTest`
Expected: PASS, 1 test.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_24_000004_create_incident_reports_table.php app/Models/IncidentReport.php tests/Feature/Sets/IncidentSchemaTest.php
git commit -m "feat(incidents): incident_reports table, statuses and model"
```

---

### Task 18: IncidentService — file an incident and exclude its stock

**Files:**
- Create: `app/Services/IncidentService.php`
- Modify: `app/Http/Controllers/Api/IssuanceOrderController.php`, `app/Http/Controllers/Api/IssuanceScanController.php` (restore the real `excludedBatchIds()` calls deferred in Task 16)
- Test: `tests/Feature/Sets/IncidentFilingTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Sets;

use App\Models\IncidentReport;
use App\Services\FifoPickingService;
use App\Services\IncidentService;
use App\Support\ScanResult;

class IncidentFilingTest extends SetTestCase
{
    public function test_filing_an_incident_opens_it_with_a_number(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable();
        $b    = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);

        $incident = app(IncidentService::class)->file([
            'source_type'   => 'issuance',
            'source_id'     => 1,
            'location_id'   => $loc->location_id,
            'product_id'    => $p->product_id,
            'batch_id'      => $b->batch_id,
            'expected_zone' => 'Zone 1',
            'actual_zone'   => 'Zone 2',
            'reason'        => IncidentReport::REASON_NOT_FOUND,
            'quantity'      => 5,
            'narrative'     => 'Bin empty, nothing on the shelf.',
        ], (int) $user->user_id);

        $this->assertSame(IncidentReport::statusId('Open'), (int) $incident->status_id);
        $this->assertStringStartsWith('INC-', $incident->incident_number);
        $this->assertSame((int) $user->user_id, (int) $incident->reported_by);
    }

    public function test_an_open_incident_excludes_its_batch_from_fifo(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable(barcode: 'BC-EXCL-FLOW');
        $old  = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDay(), 'zone_label' => 'Zone 2']);

        $svc   = app(IncidentService::class);
        $fifo  = app(FifoPickingService::class);
        $lines = [['product_id' => $p->product_id, 'quantity' => 3, 'picked' => 0]];

        // Before: Zone 2 is out of order.
        $this->assertSame(
            ScanResult::FIFO_VIOLATION,
            $fifo->validateScan($loc->location_id, $lines, 'BC-EXCL-FLOW', 'Zone 2', null, $svc->excludedBatchIds($loc->location_id))->status
        );

        $svc->file([
            'source_type' => 'issuance', 'source_id' => 1,
            'location_id' => $loc->location_id, 'product_id' => $p->product_id,
            'batch_id'    => $old->batch_id, 'reason' => IncidentReport::REASON_NOT_FOUND,
            'quantity'    => 5, 'narrative' => 'Not on the shelf.',
        ], (int) $user->user_id);

        // After: Zone 1 is excluded, so Zone 2 is now legitimately the oldest.
        $this->assertSame(
            ScanResult::ACCEPTED,
            $fifo->validateScan($loc->location_id, $lines, 'BC-EXCL-FLOW', 'Zone 2', null, $svc->excludedBatchIds($loc->location_id))->status
        );
    }

    public function test_exclusions_are_scoped_to_their_location(): void
    {
        $a    = $this->makeLocation('A');
        $b    = $this->makeLocation('B');
        $user = $this->makeUserAt($a, 'warehouse_personnel');
        $p    = $this->makeConsumable();
        $batch = $this->makeBatch($p, $a, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        app(IncidentService::class)->file([
            'source_type' => 'issuance', 'source_id' => 1,
            'location_id' => $a->location_id, 'product_id' => $p->product_id,
            'batch_id'    => $batch->batch_id, 'reason' => IncidentReport::REASON_DAMAGED,
            'quantity'    => 1, 'narrative' => 'Crushed.',
        ], (int) $user->user_id);

        $this->assertContains((int) $batch->batch_id, app(IncidentService::class)->excludedBatchIds($a->location_id));
        $this->assertSame([], app(IncidentService::class)->excludedBatchIds($b->location_id));
    }

    public function test_an_unknown_reason_is_rejected(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable();

        $this->expectException(\InvalidArgumentException::class);

        app(IncidentService::class)->file([
            'source_type' => 'issuance', 'source_id' => 1,
            'location_id' => $loc->location_id, 'product_id' => $p->product_id,
            'reason'      => 'because_i_said_so', 'quantity' => 1,
        ], (int) $user->user_id);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --filter=IncidentFilingTest`
Expected: FAIL — `Target class [App\Services\IncidentService] does not exist`.

- [ ] **Step 3: Write `app/Services/IncidentService.php`**

`resolve()` is stubbed here and implemented in Task 19 — the filing tests do not call it.

```php
<?php

namespace App\Services;

use App\Models\IncidentReport;
use Illuminate\Support\Facades\DB;

/**
 * Incident reports are the only legal way to break FIFO order. Filing one
 * excludes the affected batch immediately so picking can continue; the
 * downstream adjustment or correction is posted later, on review.
 *
 * Exclusion is derived from open incidents rather than a flag column on
 * inventory_batches, so resolving or dismissing an incident returns the stock
 * to rotation with no cleanup step.
 */
class IncidentService
{
    public function file(array $data, int $reportedBy): IncidentReport
    {
        if (! in_array($data['reason'] ?? null, IncidentReport::REASONS, true)) {
            throw new \InvalidArgumentException('Unknown incident reason: ' . ($data['reason'] ?? 'null'));
        }

        return DB::transaction(fn () => IncidentReport::create([
            'incident_number' => $this->nextNumber(),
            'source_type'     => $data['source_type'],
            'source_id'       => (int) $data['source_id'],
            'location_id'     => (int) $data['location_id'],
            'product_id'      => (int) $data['product_id'],
            'batch_id'        => $data['batch_id'] ?? null,
            'serial_id'       => $data['serial_id'] ?? null,
            'expected_zone'   => $data['expected_zone'] ?? null,
            'actual_zone'     => $data['actual_zone'] ?? null,
            'reason'          => $data['reason'],
            'quantity'        => (int) ($data['quantity'] ?? 0),
            'narrative'       => $data['narrative'] ?? null,
            'photo_path'      => $data['photo_path'] ?? null,
            'reported_by'     => $reportedBy,
            'status_id'       => IncidentReport::statusId('Open'),
        ]));
    }

    /** Batch ids at a location currently held out of FIFO by an open incident. */
    public function excludedBatchIds(int $locationId): array
    {
        return IncidentReport::open()
            ->where('location_id', $locationId)
            ->whereNotNull('batch_id')
            ->pluck('batch_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** How many incidents a picker has filed in the trailing window — the theft signal. */
    public function incidentCountFor(int $userId, int $days = 30): int
    {
        return IncidentReport::where('reported_by', $userId)
            ->where('created_at', '>=', now()->subDays($days))
            ->count();
    }

    private function nextNumber(): string
    {
        return 'INC-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
    }
}
```

- [ ] **Step 4: Restore the deferred exclusion calls**

In `IssuanceOrderController::pickPlan()` replace `$excluded = [];` with the real call from Task 16 Step 3. In `IssuanceScanController::scanItem()` replace the `[]` argument with `app(\App\Services\IncidentService::class)->excludedBatchIds($locationId)`.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=IncidentFilingTest`
Expected: PASS, 4 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Services/IncidentService.php app/Http/Controllers/Api/IssuanceOrderController.php app/Http/Controllers/Api/IssuanceScanController.php tests/Feature/Sets/IncidentFilingTest.php
git commit -m "feat(incidents): file incidents and derive FIFO exclusions from open ones"
```

---

### Task 19: Incident endpoints and per-reason resolution

**Files:**
- Create: `app/Http/Controllers/Api/IncidentReportController.php`
- Modify: `app/Services/IncidentService.php`, `routes/api.php`, `database/seeders/PermissionSeeder.php`
- Test: `tests/Feature/Sets/IncidentResolutionTest.php`

- [ ] **Step 1: Add the permission feature to `PermissionSeeder::matrix()`**

```php
            // Incident Reports (/admin/incidents) — pickers file, IA and admin resolve.
            'incident_reports' => [
                'read'    => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'create'  => ['admin', 'inventory_analyst', 'warehouse_personnel'],
                'resolve' => ['admin', 'inventory_analyst'],
            ],
```

Re-seed both databases:

```bash
php artisan db:seed --force --class=PermissionSeeder
php artisan db:seed --force --class=RolePermissionsSeeder
DB_DATABASE=airkingV5_test php artisan db:seed --force --class=PermissionSeeder
DB_DATABASE=airkingV5_test php artisan db:seed --force --class=RolePermissionsSeeder
```

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\Sets;

use App\Models\Adjustment;
use App\Models\IncidentReport;
use App\Models\InventoryBatch;
use App\Models\ScanCorrection;
use App\Services\IncidentService;

class IncidentResolutionTest extends SetTestCase
{
    private function openIncident($loc, $product, $batch, string $reason, int $qty = 3, ?int $reportedBy = null): IncidentReport
    {
        $user = $reportedBy ?? (int) $this->makeUserAt($loc, 'warehouse_personnel')->user_id;

        return app(IncidentService::class)->file([
            'source_type'   => 'issuance',
            'source_id'     => 1,
            'location_id'   => $loc->location_id,
            'product_id'    => $product->product_id,
            'batch_id'      => $batch?->batch_id,
            'expected_zone' => 'Zone 1',
            'actual_zone'   => 'Zone 2',
            'reason'        => $reason,
            'quantity'      => $qty,
            'narrative'     => 'Test narrative.',
        ], $user);
    }

    public function test_picker_can_file_through_the_endpoint(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable();
        $b    = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'issuance',
            'source_id'   => 1,
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'batch_id'    => $b->batch_id,
            'reason'      => 'not_found',
            'quantity'    => 2,
            'narrative'   => 'Shelf is empty.',
        ]);

        $res->assertCreated();
        $this->assertStringStartsWith('INC-', $res->json('data.incident_number'));
    }

    public function test_picker_cannot_resolve(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $p    = $this->makeConsumable();
        $b    = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_DAMAGED);

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertForbidden();
    }

    public function test_resolving_not_found_posts_a_deduction_adjustment(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_NOT_FOUND, 3);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", [
                 'action' => 'confirm', 'resolution_note' => 'Searched the aisle, stock is gone.',
             ])->assertOk();

        $fresh = $incident->fresh();
        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $fresh->status_id);
        $this->assertNotNull($fresh->adjustment_id);

        $adjustment = Adjustment::with('details')->find($fresh->adjustment_id);
        $this->assertSame(3, (int) $adjustment->details->first()->deduct_quantity);
        $this->assertSame(2, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
    }

    public function test_dismissing_not_found_returns_the_stock_to_fifo(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_NOT_FOUND, 3);
        $this->assertContains((int) $b->batch_id, app(IncidentService::class)->excludedBatchIds($loc->location_id));

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", [
                 'action' => 'dismiss', 'resolution_note' => 'Found it behind the pallet.',
             ])->assertOk();

        $this->assertSame(IncidentReport::statusId('Dismissed'), (int) $incident->fresh()->status_id);
        $this->assertNull($incident->fresh()->adjustment_id);
        $this->assertSame([], app(IncidentService::class)->excludedBatchIds($loc->location_id));
        $this->assertSame(5, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
    }

    public function test_resolving_damaged_posts_a_deduction_adjustment(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 8, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_DAMAGED, 2);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $this->assertNotNull($incident->fresh()->adjustment_id);
        $this->assertSame(6, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
    }

    public function test_resolving_wrong_type_creates_a_scan_correction(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $wrong   = $this->makeAppliance();
        $correct = $this->makeConsumable();
        $b = $this->makeBatch($wrong, $loc, ['quantity_remaining' => 4, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $wrong, $b, IncidentReport::REASON_WRONG_TYPE, 4);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", [
                 'action'               => 'confirm',
                 'corrected_product_id' => $correct->product_id,
                 'resolution_note'      => 'Labelled as aircon, physically installation material.',
             ])->assertOk();

        $fresh = $incident->fresh();
        $this->assertNotNull($fresh->correction_id);

        $correction = ScanCorrection::find($fresh->correction_id);
        $this->assertSame((int) $wrong->product_id, (int) $correction->original_product_id);
        $this->assertSame((int) $correct->product_id, (int) $correction->corrected_product_id);
        $this->assertSame((int) $correct->product_id, (int) InventoryBatch::find($b->batch_id)->product_id);
    }

    public function test_wrong_type_without_a_corrected_product_is_rejected(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 4, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_WRONG_TYPE);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertStatus(422);
    }

    public function test_resolving_zone_mismatch_only_moves_the_zone_label(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 7, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_ZONE_MISMATCH, 7);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $fresh = $incident->fresh();
        $this->assertNull($fresh->adjustment_id, 'a relocation is bookkeeping, not a loss');
        $this->assertNull($fresh->correction_id);

        $batch = InventoryBatch::find($b->batch_id);
        $this->assertSame('Zone 2', $batch->zone_label);
        $this->assertSame(7, (int) $batch->quantity_remaining);
    }

    public function test_resolving_twice_is_rejected(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openIncident($loc, $p, $b, IncidentReport::REASON_DAMAGED, 1);
        $url = "/api/incident-reports/{$incident->incident_id}/resolve";

        $this->actingAs($ia, 'sanctum')->postJson($url, ['action' => 'confirm'])->assertOk();
        $this->actingAs($ia, 'sanctum')->postJson($url, ['action' => 'confirm'])->assertStatus(422);

        $this->assertSame(4, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
    }

    public function test_listing_shows_the_reporters_incident_count(): void
    {
        $loc    = $this->makeLocation();
        $ia     = $this->makeUserAt($loc, 'inventory_analyst');
        $picker = $this->makeUserAt($loc, 'warehouse_personnel');
        $p      = $this->makeConsumable();
        $b      = $this->makeBatch($p, $loc, ['quantity_remaining' => 9, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $this->openIncident($loc, $p, $b, IncidentReport::REASON_NOT_FOUND, 1, (int) $picker->user_id);
        $this->openIncident($loc, $p, $b, IncidentReport::REASON_NOT_FOUND, 1, (int) $picker->user_id);

        $res = $this->actingAs($ia, 'sanctum')->getJson('/api/incident-reports?status=open');

        $res->assertOk();
        $rows = collect($res->json('data'))->where('reported_by', (int) $picker->user_id);
        $this->assertSame(2, (int) $rows->first()['reporter_incident_count']);
    }
}
```

- [ ] **Step 3: Run it to confirm it fails**

Run: `php artisan test --filter=IncidentResolutionTest`
Expected: FAIL — 404s on `/api/incident-reports`.

- [ ] **Step 4: Add `resolve()` to `IncidentService`**

```php
    /**
     * Resolve an open incident. Each reason produces a different artifact:
     *   not_found / damaged  â†’ deduction Adjustment, stock permanently out
     *   wrong_type           â†’ ScanCorrection, batch re-typed to the real product
     *   zone_mismatch        â†’ zone_label updated, no quantity movement
     * Dismissing produces nothing and returns the stock to FIFO rotation.
     */
    public function resolve(IncidentReport $incident, string $action, array $options, int $reviewedBy): IncidentReport
    {
        if ((int) $incident->status_id !== IncidentReport::statusId('Open')) {
            throw new \RuntimeException('This incident has already been reviewed.');
        }

        if ($action === 'dismiss') {
            $incident->update([
                'status_id'       => IncidentReport::statusId('Dismissed'),
                'reviewed_by'     => $reviewedBy,
                'reviewed_at'     => now(),
                'resolution_note' => $options['resolution_note'] ?? null,
            ]);

            return $incident->fresh();
        }

        if ($incident->reason === IncidentReport::REASON_WRONG_TYPE
            && empty($options['corrected_product_id'])) {
            throw new \InvalidArgumentException('A corrected product is required to resolve a wrong-type incident.');
        }

        return DB::transaction(function () use ($incident, $options, $reviewedBy) {
            $adjustmentId = null;
            $correctionId = null;

            switch ($incident->reason) {
                case IncidentReport::REASON_NOT_FOUND:
                case IncidentReport::REASON_DAMAGED:
                    $adjustmentId = $this->postDeduction($incident, $reviewedBy);
                    break;

                case IncidentReport::REASON_WRONG_TYPE:
                    $correctionId = $this->postCorrection($incident, (int) $options['corrected_product_id'], $reviewedBy);
                    break;

                case IncidentReport::REASON_ZONE_MISMATCH:
                    $this->relocate($incident);
                    break;
            }

            $incident->update([
                'status_id'       => IncidentReport::statusId('Resolved'),
                'reviewed_by'     => $reviewedBy,
                'reviewed_at'     => now(),
                'adjustment_id'   => $adjustmentId,
                'correction_id'   => $correctionId,
                'resolution_note' => $options['resolution_note'] ?? null,
            ]);

            return $incident->fresh();
        });
    }

    /** Write the stock off the batch and the location inventory, under an Adjustment. */
    private function postDeduction(IncidentReport $incident, int $reviewedBy): int
    {
        $quantity = max(0, (int) $incident->quantity);

        $adjustment = \App\Models\Adjustment::create([
            'location_id'             => (int) $incident->location_id,
            'created_by'              => (int) $incident->reported_by,
            'approved_by'             => $reviewedBy,
            'adjustment_number'       => 'ADJ-INC-' . strtoupper(substr(uniqid(), -6)),
            'adjustment_date'         => now()->toDateString(),
            'adjustment_type'         => $incident->reason === IncidentReport::REASON_DAMAGED ? 'damage' : 'loss',
            'total_variance_positive' => 0,
            'total_variance_negative' => $quantity,
            'notes'                   => "Incident {$incident->incident_number}: {$incident->narrative}",
            'status_id'               => 20, // adjustment "Approved" — verified in status_lookup
        ]);

        $adjustment->details()->create([
            'product_id'      => (int) $incident->product_id,
            'add_quantity'    => 0,
            'deduct_quantity' => $quantity,
            'reason'          => $incident->reason,
        ]);

        if ($incident->batch_id !== null) {
            $batch = \App\Models\InventoryBatch::lockForUpdate()->find($incident->batch_id);
            if ($batch !== null) {
                $batch->decrement('quantity_remaining', min($quantity, (int) $batch->quantity_remaining));
            }
        }

        $inventory = \App\Models\Inventory::where('location_id', $incident->location_id)
            ->where('product_id', $incident->product_id)
            ->lockForUpdate()
            ->first();

        if ($inventory !== null) {
            $inventory->decrement('quantity_on_hand', min($quantity, (int) $inventory->quantity_on_hand));
            $inventory->decrement('available_quantity', min($quantity, (int) $inventory->available_quantity));
        }

        return (int) $adjustment->adjustment_id;
    }

    /** Re-type the batch to the product physically on the shelf. */
    private function postCorrection(IncidentReport $incident, int $correctedProductId, int $reviewedBy): int
    {
        $batch = $incident->batch_id !== null
            ? \App\Models\InventoryBatch::find($incident->batch_id)
            : null;

        $correction = \App\Models\ScanCorrection::create([
            'receiving_id'         => $batch?->receiving_id,
            'original_product_id'  => (int) $incident->product_id,
            'corrected_product_id' => $correctedProductId,
            'correction_type'      => 'wrong_product',
            'quantity_before'      => $batch !== null ? (int) $batch->quantity_remaining : (int) $incident->quantity,
            'quantity_after'       => $batch !== null ? (int) $batch->quantity_remaining : (int) $incident->quantity,
            'serial_id'            => $incident->serial_id,
            'reason'               => "Incident {$incident->incident_number}: {$incident->narrative}",
            'corrected_by'         => (int) $incident->reported_by,
            'approved_by'          => $reviewedBy,
        ]);

        if ($batch !== null) {
            $quantity = (int) $batch->quantity_remaining;

            $batch->update(['product_id' => $correctedProductId]);

            $from = \App\Models\Inventory::where('location_id', $incident->location_id)
                ->where('product_id', $incident->product_id)->lockForUpdate()->first();
            if ($from !== null) {
                $from->decrement('quantity_on_hand', min($quantity, (int) $from->quantity_on_hand));
                $from->decrement('available_quantity', min($quantity, (int) $from->available_quantity));
            }

            $to = \App\Models\Inventory::firstOrCreate(
                ['location_id' => (int) $incident->location_id, 'product_id' => $correctedProductId],
                ['quantity_on_hand' => 0, 'available_quantity' => 0, 'reorder_level' => 0]
            );
            $to->increment('quantity_on_hand', $quantity);
            $to->increment('available_quantity', $quantity);
        }

        return (int) $correction->correction_id;
    }

    /** Stock exists, it is simply somewhere else. No quantity moves. */
    private function relocate(IncidentReport $incident): void
    {
        if ($incident->batch_id === null || $incident->actual_zone === null) {
            return;
        }

        \App\Models\InventoryBatch::where('batch_id', $incident->batch_id)
            ->update(['zone_label' => $incident->actual_zone]);
    }
```

- [ ] **Step 5: Write `app/Http/Controllers/Api/IncidentReportController.php`**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Services\IncidentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class IncidentReportController extends Controller
{
    use ApiResponse;
    use \App\Traits\ResolvesUserLocation;

    public function index(Request $request): JsonResponse
    {
        $query = IncidentReport::with(['product', 'batch', 'location', 'reportedBy', 'reviewedBy'])
            ->forUser(Auth::user());   // location scoping, same trait every other list uses

        if ($request->query('status') === 'open') {
            $query->open();
        }
        if ($reason = $request->query('reason')) {
            $query->where('reason', $reason);
        }
        if ($locationId = $request->query('location_id')) {
            $query->where('location_id', (int) $locationId);
        }

        $incidents = $query->orderByDesc('created_at')->limit(500)->get();
        $service   = app(IncidentService::class);

        $counts = $incidents->pluck('reported_by')->unique()
            ->mapWithKeys(fn ($userId) => [(int) $userId => $service->incidentCountFor((int) $userId)]);

        return response()->json([
            'success' => true,
            'data'    => $incidents->map(fn (IncidentReport $i) => [
                'incident_id'             => (int) $i->incident_id,
                'incident_number'         => $i->incident_number,
                'source_type'             => $i->source_type,
                'source_id'               => (int) $i->source_id,
                'location_id'             => (int) $i->location_id,
                'location_name'           => $i->location?->location_name,
                'product_id'              => (int) $i->product_id,
                'product_name'            => $i->product?->product_name,
                'batch_id'                => $i->batch_id !== null ? (int) $i->batch_id : null,
                'expected_zone'           => $i->expected_zone,
                'actual_zone'             => $i->actual_zone,
                'reason'                  => $i->reason,
                'quantity'                => (int) $i->quantity,
                'narrative'               => $i->narrative,
                'photo_path'              => $i->photo_path,
                'reported_by'             => (int) $i->reported_by,
                'reported_by_name'        => trim(($i->reportedBy?->first_name ?? '') . ' ' . ($i->reportedBy?->last_name ?? '')),
                'reporter_incident_count' => (int) ($counts[(int) $i->reported_by] ?? 0),
                'status_id'               => (int) $i->status_id,
                'is_open'                 => (int) $i->status_id === IncidentReport::statusId('Open'),
                'reviewed_by_name'        => $i->reviewedBy !== null
                    ? trim(($i->reviewedBy->first_name ?? '') . ' ' . ($i->reviewedBy->last_name ?? ''))
                    : null,
                'reviewed_at'             => optional($i->reviewed_at)->toDateTimeString(),
                'adjustment_id'           => $i->adjustment_id !== null ? (int) $i->adjustment_id : null,
                'correction_id'           => $i->correction_id !== null ? (int) $i->correction_id : null,
                'resolution_note'         => $i->resolution_note,
                'created_at'              => optional($i->created_at)->toDateTimeString(),
            ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_type'   => ['required', Rule::in(['issuance', 'transfer'])],
            'source_id'     => 'required|integer',
            'location_id'   => 'required|integer|exists:locations,location_id',
            'product_id'    => 'required|integer|exists:products,product_id',
            'batch_id'      => 'nullable|integer|exists:inventory_batches,batch_id',
            'serial_id'     => 'nullable|integer|exists:item_serial,serial_id',
            'expected_zone' => 'nullable|string|max:100',
            'actual_zone'   => 'nullable|string|max:100',
            'reason'        => ['required', Rule::in(IncidentReport::REASONS)],
            'quantity'      => 'required|integer|min:1',
            'narrative'     => 'required|string|max:2000',
            'photo_path'    => 'nullable|string|max:255',
        ]);

        $incident = app(IncidentService::class)->file($validated, (int) Auth::id());

        return response()->json([
            'success' => true,
            'message' => 'Incident filed. Picking may continue with the next batch.',
            'data'    => $incident,
        ], 201);
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'action'               => ['required', Rule::in(['confirm', 'dismiss'])],
            'corrected_product_id' => 'nullable|integer|exists:products,product_id',
            'resolution_note'      => 'nullable|string|max:2000',
        ]);

        $incident = IncidentReport::findOrFail($id);

        try {
            $resolved = app(IncidentService::class)->resolve(
                $incident,
                $validated['action'],
                $validated,
                (int) Auth::id()
            );
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Incident reviewed.',
            'data'    => $resolved,
        ]);
    }
}
```

If `IncidentReport` has no `forUser()` scope from `ScopesToUserLocation`, read `app/Models/Concerns/ScopesToUserLocation.php` and call whatever scope it actually provides — do not invent a name.

- [ ] **Step 6: Register the routes**

```php
    Route::middleware('permission:incident_reports,read')
         ->get('/incident-reports', [\App\Http\Controllers\Api\IncidentReportController::class, 'index']);
    Route::middleware('permission:incident_reports,create')
         ->post('/incident-reports', [\App\Http\Controllers\Api\IncidentReportController::class, 'store']);
    Route::middleware('permission:incident_reports,resolve')
         ->post('/incident-reports/{id}/resolve', [\App\Http\Controllers\Api\IncidentReportController::class, 'resolve']);
```

Add `use Illuminate\Support\Facades\DB;` to `IncidentService` if it is not already imported.

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter=IncidentResolutionTest`
Expected: PASS, 10 tests.

- [ ] **Step 8: Run the whole suite**

Run: `php artisan test`
Expected: green.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Api/IncidentReportController.php app/Services/IncidentService.php routes/api.php database/seeders/PermissionSeeder.php tests/Feature/Sets/IncidentResolutionTest.php
git commit -m "feat(incidents): file/list/resolve endpoints with per-reason downstream artifacts"
```

---

### Task 20: Incident Reports page

**Files:**
- Create: `frontend/src/admin/pages/IncidentReportsPage.jsx`
- Modify: `frontend/src/admin/services/api.js`, route registration

- [ ] **Step 1: Add the API client**

```javascript
export const incidentReportsAPI = {
  list:    (params = {}) => api.get('/incident-reports', { params }),
  file:    (payload) => api.post('/incident-reports', payload),
  resolve: (id, payload) => api.post(`/incident-reports/${id}/resolve`, payload),
};
```

- [ ] **Step 2: Write the page**

Read `frontend/src/admin/pages/AdjustmentManagement.jsx` first and match its table, filter, and drawer conventions.

*Triage list* — one row per incident: incident number, date, reason chip (colour-coded: `not_found` red, `damaged` amber, `wrong_type` blue, `zone_mismatch` grey), product, quantity, expected zone â†’ actual zone, source document link, reporter name, and a **reporter incident count** badge. The badge turns red at 3 or more in 30 days — that count is the theft signal the client cares about and must be visible without opening the row. Filters: status (open/all), reason, location.

*Resolve drawer* — opens on a row, shows the narrative and photo, and offers **Confirm** and **Dismiss**. The Confirm form differs by reason:

- `not_found` / `damaged` — a note field; the copy states plainly that confirming writes the stock off.
- `wrong_type` — a required product picker labelled "What is physically on the shelf?"; submitting without it returns 422, so disable Confirm until one is chosen.
- `zone_mismatch` — read-only display of the zone move; the copy states no quantity changes.

Only render Confirm/Dismiss for users holding `incident_reports.resolve`; everyone else gets a read-only drawer.

- [ ] **Step 3: Register the route** at `/admin/incidents`, gated on `incident_reports.read`.

- [ ] **Step 4: Verify the build**

```bash
cd C:/XAMPP1/htdocs/Airking_Inventory/frontend
npm run build
```

Expected: `Compiled successfully.`

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/pages/IncidentReportsPage.jsx frontend/src/admin/services/api.js frontend/src/admin/App.jsx
git commit -m "feat(incidents): Incident Reports triage page with per-reason resolve drawer"
```

---


## Phase 5 — Transfer Dispatch Cutover (Tasks 21–22)

This is the phase that touches the module consolidated on 2026-07-22, which is why it is last: everything else is already proven by the time it runs. Verified entry points — `TransferController::pickList()` (line 573), `TransferController::scan()` (line 716), routes `GET /transfers/{id}/pick-list` (`routes/api.php:632`) and `POST /transfers/{id}/scan` (line 648). The request â†’ IA verify â†’ approve flow is not touched, and neither is receiving.

### Task 21: `TransferService::pickList()` delegates to the shared engine

**Files:**
- Modify: `app/Services/TransferService.php:58-122`
- Test: `tests/Feature/Transfer/PickListTest.php` (existing — must keep passing unchanged)

- [ ] **Step 1: Establish the baseline**

Run: `php artisan test --filter=PickList`
Expected: PASS. Record the test count. These tests are the contract: the delegation must not change a single assertion. If you find yourself editing `PickListTest.php` or `PickListEndpointTest.php` in this task, the delegation is wrong.

- [ ] **Step 2: Replace the body of `pickList()`**

Substitute the whole method (currently lines 58–122) with:

```php
    /**
     * FIFO pick suggestions for an approved transfer.
     * The ordering rule lives in FifoPickingService so issuance picking and
     * transfer dispatch can never disagree about what "oldest" means.
     */
    public function pickList(Transfer $ist): array
    {
        $ist->loadMissing('details.product.consumableDetail');

        $lines = $ist->details->map(fn ($detail) => [
            'product_id' => (int) $detail->product_id,
            'quantity'   => max(0, (int) $detail->quantity_requested - (int) $detail->quantity_scanned),
        ])->values()->all();

        $excluded = app(\App\Services\IncidentService::class)
            ->excludedBatchIds((int) $ist->from_location_id);

        $suggested = app(\App\Services\FifoPickingService::class)
            ->suggest((int) $ist->from_location_id, $lines, $excluded);

        // Re-attach the packaging fields the transfer UI reads; the shared
        // service is document-agnostic and does not know about them.
        return collect($suggested)->map(function (array $row, int $i) use ($ist) {
            $detail = $ist->details[$i] ?? null;

            return $row + [
                'pieces_per_package' => (int) ($detail?->product?->consumableDetail?->pieces_per_package ?? 0),
                'package_unit'       => $detail?->product?->consumableDetail?->package_unit,
            ];
        })->all();
    }
```

Delete the now-unused private `isSerialized()` from `TransferService` **only if** nothing else in that file calls it — grep first. Leave `ItemSerial` and `InventoryBatch` imports alone unless PHP reports them unused.

- [ ] **Step 3: Run the transfer pick-list tests**

Run: `php artisan test --filter=PickList`
Expected: PASS, same count as Step 1, with no test file edited.

If a suggestion's key set differs (for example `serial_id` is now present where it was not before), that is additive and fine. If ordering or quantities differ, the delegation changed behavior — fix `FifoPickingService`, not the test.

- [ ] **Step 4: Run the full transfer suite**

Run: `php artisan test --filter=Transfer`
Expected: PASS, all of `tests/Feature/Transfer/`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/TransferService.php
git commit -m "refactor(transfer): pickList delegates to the shared FifoPickingService"
```

---

### Task 22: Enforce FIFO on transfer dispatch scans

**Files:**
- Modify: `app/Http/Controllers/Api/TransferController.php:716` (`scan()`)
- Test: `tests/Feature/Transfer/DispatchFifoEnforcementTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Transfer/DispatchFifoEnforcementTest.php`, extending the existing `TransferTestCase` in that namespace:

```php
<?php

namespace Tests\Feature\Transfer;

use App\Models\IncidentReport;
use App\Services\IncidentService;

class DispatchFifoEnforcementTest extends TransferTestCase
{
    public function test_scanning_a_product_not_on_the_transfer_is_blocked(): void
    {
        $src = $this->makeLocation('Src');
        $dst = $this->makeLocation('Dst');
        $onList  = $this->makeConsumable();
        $offList = $this->makeConsumable();
        $offList->update(['barcode' => 'BC-TR-OFFLIST']);

        $this->setStock($src->location_id, $onList->product_id, 10);
        $this->setStock($src->location_id, $offList->product_id, 10);
        $this->makeBatch($offList, $src, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $transfer = $this->makeApprovedTransfer($src, $dst, [[$onList, 2]]);
        $user     = $this->makeUserAt($src, 'warehouse_personnel');

        $res = $this->actingAs($user, 'sanctum')
                    ->postJson("/api/transfers/{$transfer->transfer_id}/scan", ['barcode' => 'BC-TR-OFFLIST']);

        $res->assertStatus(422);
        $this->assertSame('WRONG_PRODUCT', $res->json('scan_result.status'));
        $this->assertFalse($res->json('scan_result.overridable'));
    }

    public function test_scanning_a_newer_zone_is_an_overridable_fifo_violation(): void
    {
        $src = $this->makeLocation('Src');
        $dst = $this->makeLocation('Dst');
        $p   = $this->makeConsumable();
        $p->update(['barcode' => 'BC-TR-FIFO']);
        $this->setStock($src->location_id, $p->product_id, 20);

        $this->makeBatch($p, $src, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $src, ['quantity_remaining' => 5, 'received_at' => now()->subDay(),  'zone_label' => 'Zone 2']);

        $transfer = $this->makeApprovedTransfer($src, $dst, [[$p, 2]]);
        $user     = $this->makeUserAt($src, 'warehouse_personnel');

        $res = $this->actingAs($user, 'sanctum')->postJson(
            "/api/transfers/{$transfer->transfer_id}/scan",
            ['barcode' => 'BC-TR-FIFO', 'confirmed_zone' => 'Zone 2']
        );

        $res->assertStatus(422);
        $this->assertSame('FIFO_VIOLATION', $res->json('scan_result.status'));
        $this->assertTrue($res->json('scan_result.overridable'));
        $this->assertSame('Zone 1', $res->json('scan_result.expected_zone_label'));
    }

    public function test_an_open_incident_unblocks_the_next_zone(): void
    {
        $src = $this->makeLocation('Src');
        $dst = $this->makeLocation('Dst');
        $p   = $this->makeConsumable();
        $p->update(['barcode' => 'BC-TR-INC']);
        $this->setStock($src->location_id, $p->product_id, 20);

        $old = $this->makeBatch($p, $src, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $src, ['quantity_remaining' => 5, 'received_at' => now()->subDay(), 'zone_label' => 'Zone 2']);

        $transfer = $this->makeApprovedTransfer($src, $dst, [[$p, 2]]);
        $user     = $this->makeUserAt($src, 'warehouse_personnel');

        app(IncidentService::class)->file([
            'source_type' => 'transfer', 'source_id' => (int) $transfer->transfer_id,
            'location_id' => $src->location_id, 'product_id' => $p->product_id,
            'batch_id'    => $old->batch_id, 'reason' => IncidentReport::REASON_NOT_FOUND,
            'quantity'    => 5, 'narrative' => 'Zone 1 bin is empty.',
        ], (int) $user->user_id);

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/transfers/{$transfer->transfer_id}/scan",
            ['barcode' => 'BC-TR-INC', 'confirmed_zone' => 'Zone 2']
        )->assertOk();
    }

    public function test_scanning_the_oldest_zone_still_records_the_scan(): void
    {
        $src = $this->makeLocation('Src');
        $dst = $this->makeLocation('Dst');
        $p   = $this->makeConsumable();
        $p->update(['barcode' => 'BC-TR-OK']);
        $this->setStock($src->location_id, $p->product_id, 20);
        $this->makeBatch($p, $src, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);

        $transfer = $this->makeApprovedTransfer($src, $dst, [[$p, 2]]);
        $user     = $this->makeUserAt($src, 'warehouse_personnel');

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/transfers/{$transfer->transfer_id}/scan",
            ['barcode' => 'BC-TR-OK', 'quantity' => 1, 'confirmed_zone' => 'Zone 1']
        )->assertOk();

        $this->assertGreaterThan(0, (int) $transfer->fresh('details')->details->sum('quantity_scanned'));
    }

    /**
     * The reason the engine is shared: identical stock, identical violation,
     * identical verdict — whichever document is doing the picking.
     */
    public function test_transfer_and_issuance_return_the_same_verdict_for_the_same_violation(): void
    {
        $src = $this->makeLocation('Src');
        $dst = $this->makeLocation('Dst');
        $p   = $this->makeConsumable();
        $p->update(['barcode' => 'BC-PARITY']);
        $this->setStock($src->location_id, $p->product_id, 20);

        $this->makeBatch($p, $src, ['quantity_remaining' => 5, 'received_at' => now()->subDays(9), 'zone_label' => 'Zone 1']);
        $this->makeBatch($p, $src, ['quantity_remaining' => 5, 'received_at' => now()->subDay(),  'zone_label' => 'Zone 2']);

        $lines = [['product_id' => (int) $p->product_id, 'quantity' => 2, 'picked' => 0]];
        $fifo  = app(\App\Services\FifoPickingService::class);

        // Same call the transfer dispatch scan makes.
        $transferVerdict = $fifo->validateScan($src->location_id, $lines, 'BC-PARITY', 'Zone 2');
        // Same call the issuance scan makes.
        $issuanceVerdict = $fifo->validateScan($src->location_id, $lines, 'BC-PARITY', 'Zone 2');

        $this->assertSame($transferVerdict->status, $issuanceVerdict->status);
        $this->assertSame($transferVerdict->expectedZoneLabel, $issuanceVerdict->expectedZoneLabel);
        $this->assertSame(\App\Support\ScanResult::FIFO_VIOLATION, $transferVerdict->status);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --filter=DispatchFifoEnforcementTest`
Expected: FAIL — the scan endpoint has no `scan_result` key and does not block.

- [ ] **Step 3: Add the verdict to `TransferController::scan()`**

Read the method at line 716 first to see how it resolves the transfer, checks status, and delegates to `TransferService`. Add `'confirmed_zone' => 'nullable|string|max:100'` to its validation rules, then insert the verdict **immediately after** the transfer and status checks and **before** any call into `TransferService`:

```php
        $transfer->loadMissing('details');

        $lines = $transfer->details->map(fn ($detail) => [
            'product_id' => (int) $detail->product_id,
            'quantity'   => (int) $detail->quantity_requested,
            'picked'     => (int) $detail->quantity_scanned,
        ])->values()->all();

        $locationId = (int) $transfer->from_location_id;

        $verdict = app(\App\Services\FifoPickingService::class)->validateScan(
            $locationId,
            $lines,
            trim($request->input('barcode')),
            $request->input('confirmed_zone'),
            null,
            app(\App\Services\IncidentService::class)->excludedBatchIds($locationId)
        );

        if (! $verdict->accepted()) {
            return response()->json([
                'success'     => false,
                'message'     => $verdict->message,
                'scan_result' => $verdict->toArray(),
            ], 422);
        }
```

Everything after this point stays exactly as it is — `TransferService` still owns the scan recording, counting, and serial handling. This adds a gate; it does not move the engine.

- [ ] **Step 4: Run the new tests**

Run: `php artisan test --filter=DispatchFifoEnforcementTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Run the full transfer suite**

Run: `php artisan test --filter=Transfer`
Expected: PASS. Some existing dispatch-scan tests may now fail because their fixtures create stock without batches, or with newer-batch stock that the old code accepted. Check each failure against the rule before touching it:
- Fixture has **no** `inventory_batches` rows â†’ `validateScan` returns ACCEPTED by design (nothing to order against), so a failure here means something else broke.
- Fixture has multiple batches and scans the newer one â†’ the test encodes the old advisory behavior. Update the fixture to scan the oldest zone, or pass `confirmed_zone`. That is an intended behavior change.

- [ ] **Step 6: Run the whole suite**

Run: `php artisan test`
Expected: green.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/TransferController.php tests/Feature/Transfer/DispatchFifoEnforcementTest.php
git commit -m "feat(transfer): enforce FIFO on dispatch scans through the shared engine"
```

---


## Phase 6 — Frontend Wizard, Shared Scanner, Nav (Tasks 23–26)

The frontend has no automated test signal in this repo. Every task here ends with `npm run build` (must print `Compiled successfully.`) and a `npm run demo` walkthrough. `npm start` is the CRA dev server and is slow enough to look broken — use `npm run demo` for anything you are judging by feel.

### Task 23: Shared PickScanner and IncidentModal components

**Files:**
- Create: `frontend/src/admin/components/picking/PickScanner.jsx`
- Create: `frontend/src/admin/components/picking/IncidentModal.jsx`
- Modify: `frontend/src/admin/services/api.js`

Both the issuance picker and the transfer dispatch panel render the identical `ScanResult` shape, so they get one component. Building this before either consumer is what stops two divergent scanner UIs from appearing.

- [ ] **Step 1: Add the shared API calls**

```javascript
export const pickingAPI = {
  issuancePlan: (id) => api.get(`/issuances/${id}/pick-plan`),
  transferPlan: (id) => api.get(`/transfers/${id}/pick-list`),
};
```

- [ ] **Step 2: Write `PickScanner.jsx`**

Props: `plan` (array of `{product_id, product_name, needed, suggestions[]}`), `onScan(barcode, confirmedZone)` returning the server response, `onIncident(context)`, and `busy`.

Behavior:

- Renders the **current step** large: product name, quantity still needed, and the zone in the biggest type on the screen — the zone is the instruction, everything else is context. Below it, a compact list of remaining steps.
- A barcode input that is always focused, submits on Enter, and clears after every attempt regardless of outcome. A picker with a scanner gun never touches the mouse.
- Consumable steps show a **Confirm zone** control defaulted to the suggested zone; `onScan` passes whatever it holds. Serialized steps omit it — the serial identifies the batch on its own.
- On response, switch on `scan_result.status`:
  - `ACCEPTED` — green flash, advance progress, refetch the plan.
  - `WRONG_PRODUCT`, `UNKNOWN_BARCODE`, `OVER_PICK` — red banner with `message`, **no** incident button. These are hard blocks and the UI must not imply otherwise.
  - `FIFO_VIOLATION` — amber banner with `message`, showing `expected_zone_label` and `expected_received_at`, plus a **File Incident Report** button wired to `onIncident`.
- Derive the incident button's presence from `scan_result.overridable`, never from a status string comparison in the component. The server decides what is overridable; the UI only reflects it.

- [ ] **Step 3: Write `IncidentModal.jsx`**

Props: `context` (`{source_type, source_id, location_id, product_id, batch_id, serial_id, expected_zone, actual_zone, quantity}`), `onSubmit`, `onClose`.

Fields: reason radio group with plain-language labels —

- "I can't find it on the shelf" â†’ `not_found`
- "It's there but damaged" â†’ `damaged`
- "Wrong item — the label doesn't match what's physically here" â†’ `wrong_type`
- "It's here, just in a different zone" â†’ `zone_mismatch`

plus quantity, a required narrative, and an optional photo. Submit posts through `incidentReportsAPI.file`, and on success the parent refetches the pick plan so the excluded batch drops out and the next step appears.

The copy under `not_found` should state that filing opens an investigation record — pickers should know the count is visible, because that is what makes the count a deterrent rather than a surprise.

- [ ] **Step 4: Verify the build**

```bash
cd C:/XAMPP1/htdocs/Airking_Inventory/frontend
npm run build
```

Expected: `Compiled successfully.`

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/picking/ frontend/src/admin/services/api.js
git commit -m "feat(picking): shared PickScanner and IncidentModal components"
```

---

### Task 24: Issuance three-step wizard

**Files:**
- Create: `frontend/src/admin/components/issuance/OrderBuilder.jsx`, `frontend/src/admin/components/issuance/QuotePanel.jsx`
- Modify: `frontend/src/admin/pages/IssuanceScanPage.jsx`, `frontend/src/admin/services/api.js`

- [ ] **Step 1: Add the order API calls**

```javascript
export const issuanceOrderAPI = {
  quote:        (id) => api.get(`/issuances/${id}/quote`),
  addSet:       (id, payload) => api.post(`/issuances/${id}/lines/set`, payload),
  addCustom:    (id, payload) => api.post(`/issuances/${id}/lines/custom`, payload),
  updateLine:   (id, lineId, payload) => api.put(`/issuances/${id}/lines/${lineId}`, payload),
  removeLine:   (id, lineId) => api.delete(`/issuances/${id}/lines/${lineId}`),
  confirmQuote: (id, payload = {}) => api.post(`/issuances/${id}/confirm-quote`, payload),
};
```

- [ ] **Step 2: Write `OrderBuilder.jsx` (step 1)**

Customer, issuance type, and discount at the top — these still post through the existing `/issuance-scan/init`, which now returns a Draft.

Three add-paths into one list:

- **Add appliance** — product picker filtered to appliances. On select, call `productSetsAPI.suggest(productId)`; if anything comes back, show the top match as a one-tap card ("TCL 3TR/4HP non-inverter â†’ Std. Installation Materials, ₱X — Add with this unit"), with the rest behind "other sets". Adding posts `addCustom` for the appliance and `addSet` for the kit.
- **Add set** — plain dropdown of active sets plus a set-quantity stepper.
- **Add custom item** — any product, any quantity.

Lines render grouped: one card per `set_groups` entry showing the set name, `set_quantity`, group total, and a **Modified** badge when `was_modified`; loose custom lines in their own group. Every line has inline quantity edit and remove.

- [ ] **Step 3: Write `QuotePanel.jsx` (step 2)**

A read-mostly review: grouped lines with quantity, unit price, line total, and an availability badge. Short lines show `Only {available} available — short by {short_by}` in red, with quick actions to reduce to available or remove.

Totals block: subtotal, discount, grand total in the largest type on the page — this is the number the customer reads. Cost and margin render only when `total_cost` is non-null; the server omits it for roles without `view_margin`, so the component must branch on the value's presence rather than checking a role itself.

The **Confirm & Start Picking** button posts `confirmQuote`. When `has_shortages` is true it first opens a confirm dialog naming the short lines, and only then re-posts with `accept_shortages: true`. Use the shared confirm-dialog helper the other pages use — do not hand-roll a modal.

- [ ] **Step 4: Rework `IssuanceScanPage.jsx` into the wizard shell**

Three steps: **Order â†’ Quote â†’ Pick**. Step state derives from the issuance's `status_id` returned by the quote endpoint, not from local component state — a refresh mid-pick must land the user back on Pick, not Order.

Step 3 renders `PickScanner` fed by `pickingAPI.issuancePlan`, with `onIncident` opening `IncidentModal` pre-filled with `source_type: 'issuance'` and the current step's batch and zone. Completion still posts to the existing `/issuance-scan/complete/{id}`, which already generates the DR and warranty records.

Steps 2 and 3 must be unreachable by clicking the stepper when the order is not in the right state. The server enforces this anyway; the UI should not offer a door that will slam.

- [ ] **Step 5: Verify the build and walk it through**

```bash
cd C:/XAMPP1/htdocs/Airking_Inventory/frontend
npm run build
npm run demo
```

Walk the whole flow: create an order, add an aircon, accept the suggested set, add a custom item, review the total, confirm, then pick — scanning a wrong product, scanning out of FIFO order, filing an incident, and completing.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/components/issuance/ frontend/src/admin/pages/IssuanceScanPage.jsx frontend/src/admin/services/api.js
git commit -m "feat(issuance): three-step order/quote/pick wizard"
```

---

### Task 25: Transfer dispatch panel uses the shared scanner

**Files:**
- Modify: the dispatch panel under `frontend/src/admin/pages/transfers/`

- [ ] **Step 1: Locate the current dispatch UI**

```bash
grep -rn "pick-list\|pickList\|confirm-dispatch" frontend/src/admin/pages/transfers/ frontend/src/admin/pages/TransfersPage.jsx
```

- [ ] **Step 2: Swap the advisory list for `PickScanner`**

Replace the existing suggestion display and scan input with `PickScanner`, fed by `pickingAPI.transferPlan`. Wire `onIncident` to `IncidentModal` with `source_type: 'transfer'` and `location_id` set to the transfer's **source** location — the incident is raised where the stock is, not where it is going.

Leave dispatch confirmation, the received-side flow, and every other part of the transfer page alone.

- [ ] **Step 3: Verify the build and walk it through**

```bash
npm run build
npm run demo
```

Dispatch an approved transfer: scan a listed item from the oldest zone (accepted), a listed item from a newer zone (blocked, incident offered), and an unlisted item (blocked, no incident offered).

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/pages/transfers/
git commit -m "feat(transfer): dispatch panel uses the shared enforcing PickScanner"
```

---

### Task 26: Navigation, permission mirror, and final verification

**Files:**
- Modify: `database/seeders/NavBundleSeeder.php`, `frontend/src/admin/components/Sidebar.jsx`, `frontend/src/admin/utils/roles.js`

- [ ] **Step 1: Add the nav bundles**

Read `database/seeders/NavBundleSeeder.php` and add two entries matching the existing shape: **Set Management** â†’ `/admin/sets`, requiring `product_set_management.read`; **Incident Reports** â†’ `/admin/incidents`, requiring `incident_reports.read`.

- [ ] **Step 2: Seed both databases**

```bash
php artisan db:seed --force --class=NavBundleSeeder
DB_DATABASE=airkingV5_test php artisan db:seed --force --class=NavBundleSeeder
```

- [ ] **Step 3: Add the sidebar entries**

In `frontend/src/admin/components/Sidebar.jsx`, add both items following the existing permission-gated pattern. Put Set Management beside the other catalog items and Incident Reports beside Adjustments — an incident is an inventory exception, and that is where someone looking for one will go.

- [ ] **Step 4: Mirror the permissions in the frontend**

`frontend/src/admin/utils/roles.js` mirrors backend permission keys. Add `product_set_management` and `incident_reports` with the same actions the seeder grants, and the `view_margin` action on `inventory_issuance`. A mismatch here shows menu items that 403 on click.

- [ ] **Step 5: Clear caches**

```bash
php artisan config:clear
php artisan cache:clear
```

The config cache has bitten this project before — a stale one makes correct permission changes look broken.

- [ ] **Step 6: Full verification**

```bash
php artisan test
cd C:/XAMPP1/htdocs/Airking_Inventory/frontend
npm run build
```

Expected: full backend suite green, frontend `Compiled successfully.`

- [ ] **Step 7: Role walkthrough on `npm run demo`**

- **Admin** — sees both new pages, can build a set, can resolve incidents.
- **Inventory Analyst** — reads sets but cannot create them; resolves incidents; sees margin on the quote.
- **Warehouse Personnel** — reads sets, builds and picks orders, files incidents, **cannot** resolve them, and sees **no** cost or margin anywhere on the quote.
- **Auditor** — reads both pages, mutates nothing.

- [ ] **Step 8: Commit**

```bash
git add database/seeders/NavBundleSeeder.php frontend/src/admin/components/Sidebar.jsx frontend/src/admin/utils/roles.js
git commit -m "feat(nav): permission-gated Set Management and Incident Reports navigation"
```

---

## Done Criteria

- Admin can define BOQ sets on their own page; issuance offers the matching set for a chosen aircon.
- An issuance shows a priced, availability-checked total that the customer confirms before any scanning is possible.
- Scanning an unlisted product is blocked and cannot be overridden.
- Scanning out of FIFO order is blocked and can only proceed after an Incident Report, on both issuance picking and transfer dispatch.
- Each incident reason produces its correct artifact: deduction adjustment, scan correction, zone update, or nothing on dismissal.
- Cost and margin are invisible to roles without `inventory_issuance.view_margin`.
- Full backend suite green; frontend builds clean.

