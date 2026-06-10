# Warranty Claim Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a dedicated warranty-claim module (repair or replacement) that links a warranty record, its customer, and inventory part-consumption, replacing the dead/disconnected warranty-claim code.

**Architecture:** A two-step claim wizard (validate warranty → scan/add items → complete) backed by a new `warranty_claims` + `warranty_claim_details` schema, a thin `WarrantyClaimController` delegating to a `WarrantyClaimService`. Repairs consume non-serialized consumable parts; replacements consume one serialized unit and spawn a new warranty record that inherits the original's end date. Both mark the original warranty Claimed. The old `issuance_type = 'warranty'` replacement path is retired.

**Tech Stack:** Laravel 11 (PHP 8.2), MySQL/MariaDB (XAMPP), PHPUnit feature tests against a cloned MySQL test DB with `DatabaseTransactions`, React 18 frontend.

---

## Spec reference

Design spec: `docs/superpowers/specs/2026-06-07-warranty-claim-module-design.md`

## Key codebase facts (read before starting)

- **Status IDs (hardcoded convention, present in live DB only):** serial in-stock `26`, warranty Active `33`, Expired `34`, Claimed `35`. New claim statuses are added by migration and resolved **by name** (`status_category = 'warranty_claim'`), never hardcoded.
- **`ApiResponse` trait** (`app/Traits/ApiResponse.php`) provides `success($data,$msg,$code)`, `error($msg,$code)`, `notFound($msg)`, `validationError($errors)`, `paginated($paginator,$msg)`.
- **`ResolvesUserLocation` trait** provides `resolveLocationId(Request)` → `?int` and `locationErrorResponse()`.
- **`NormalizesBarcode` trait** provides `barcodeLookupCandidates(string): array` and `findProductByBarcodeCandidates(array): ?Product`.
- **`Inventory`** (`table inventory`, PK `inventory_id`): `quantity_on_hand`, `available_quantity`, `packages_on_hand`.
- **`ItemSerial`** (`table item_serial`, PK `serial_id`): `serial_number`, `product_id`, `location_id`, `serial_type` (`'received'`), `status_id` (26 = in stock), `scanned_by`.
- **`WarrantyRecord`** (`table warranty_records`, PK `warranty_id`) fillable includes `serial_number, customer_id, product_id, sale_date, warranty_start_date, warranty_end_date, warranty_period_months, warranty_duration_label, status_id, claim_date, claimed_by, claim_notes`. Has `is_expired` and `days_remaining` accessors.
- **`Product`** helpers used by scan flows: `isSerialized(): bool`, `getPiecesPerPackage(): int`, plus `unit_price`, `cost_price`, relations `applianceDetail.brand`, `consumableDetail.unit`.
- **Reference implementation to mirror:** `app/Http/Controllers/Api/IssuanceScanController.php` (scan/deduct/remove/complete patterns).
- **Routes** live in `back-end/routes/api.php`; the issuance-scan group (≈ line 430) is the template for the new group.
- **Frontend pages** are registered in `frontend/src/App.jsx` (lazy import + `<Route>`) and `frontend/src/admin/components/Sidebar.jsx` (dynamic import map). API calls go through `apiRequest()` in `frontend/src/admin/services/api.js`.

## File structure

**Create (backend):**
- `back-end/database/migrations/2026_06_07_000001_create_warranty_claims_tables.php` — both claim tables
- `back-end/database/migrations/2026_06_07_000002_add_warranty_claim_statuses.php` — status_lookup rows
- `back-end/app/Models/WarrantyClaimDetail.php`
- `back-end/app/Services/WarrantyClaimService.php` — all claim business logic
- `back-end/app/Http/Controllers/Api/WarrantyClaimController.php` — thin controller (rewrite the existing file)
- `back-end/tests/Feature/WarrantyClaimTest.php`
- `back-end/tests/Concerns/CreatesWarrantyTestData.php` — shared test data helper

**Modify (backend):**
- `back-end/app/Models/WarrantyClaim.php` — rebuild fillable/relations
- `back-end/app/Models/WarrantyRecord.php` — add `claims()` relation
- `back-end/app/Models/Customer.php` — add `warrantyClaims()` relation
- `back-end/app/Http/Controllers/Api/CustomerController.php` — include claims in `history()` + count
- `back-end/app/Http/Controllers/Api/IssuanceScanController.php` — retire `'warranty'` issuance type
- `back-end/routes/api.php` — register warranty-claims routes
- `back-end/phpunit.xml` — point feature tests at the MySQL test DB

**Create (frontend):**
- `frontend/src/admin/pages/WarrantyClaimPage.jsx` — the wizard
- `frontend/src/admin/styles/warranty_claim.css`

**Modify (frontend):**
- `frontend/src/admin/services/api.js` — add `warrantyClaimsAPI`
- `frontend/src/App.jsx` — route `/admin/warranty-claims`
- `frontend/src/admin/components/Sidebar.jsx` — nav entry + dynamic import
- `frontend/src/admin/pages/WarrantyManagement.jsx` — "Claim Warranty" entry point

---

## Task 1: Test database + harness

**Files:**
- Modify: `back-end/phpunit.xml`
- Create: `back-end/tests/Concerns/CreatesWarrantyTestData.php`
- Create: `back-end/tests/Feature/WarrantyClaimTest.php` (skeleton)

- [ ] **Step 1: Create the MySQL test DB by cloning the live schema + data**

The warranty status rows (33/34/35) exist only in the live DB, so clone it (XAMPP MySQL must be running). Run from `back-end/`:

```bash
mysqldump -u root --no-tablespaces airkingV5 > /tmp/airking_clone.sql
mysql -u root -e "DROP DATABASE IF EXISTS airkingV5_test; CREATE DATABASE airkingV5_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root airkingV5_test < /tmp/airking_clone.sql
```

Expected: no errors; `mysql -u root -e "SELECT COUNT(*) FROM airkingV5_test.warranty_records;"` runs.

- [ ] **Step 2: Point feature tests at the test DB**

In `back-end/phpunit.xml`, replace the SQLite `<env>` lines with MySQL:

```xml
            <env name="DB_CONNECTION" value="mysql"/>
            <env name="DB_HOST" value="127.0.0.1"/>
            <env name="DB_PORT" value="3306"/>
            <env name="DB_DATABASE" value="airkingV5_test"/>
            <env name="DB_USERNAME" value="root"/>
            <env name="DB_PASSWORD" value=""/>
```

(Delete the old `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` lines.)

- [ ] **Step 3: Apply new migrations to the test DB**

This will be re-run after Tasks 2–3 create migrations. For now confirm the baseline migrates cleanly:

```bash
php artisan migrate --database=mysql --env=testing --force
```

Run with `--env=testing` so it targets `airkingV5_test`. Expected: "Nothing to migrate" or applied migrations, no errors.

- [ ] **Step 4: Create the shared test-data helper**

`back-end/tests/Concerns/CreatesWarrantyTestData.php`:

```php
<?php

namespace Tests\Concerns;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Models\User;
use App\Models\WarrantyRecord;
use Illuminate\Support\Carbon;

trait CreatesWarrantyTestData
{
    protected function actingUser(): User
    {
        $user = User::query()->first();
        $this->assertNotNull($user, 'Test DB must contain at least one user.');
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    protected function makeCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'customer_code'  => 'TST-' . uniqid(),
            'customer_type'  => 'registered',
            'customer_name'  => 'Test Customer',
            'status_id'      => 36,
            'outstanding_balance' => 0,
        ], $overrides));
    }

    /** An appliance product with a serialized unit in stock + an active warranty record. */
    protected function makeAppliance(Customer $customer, array $warrantyOverrides = []): array
    {
        $product = Product::where('product_type', 'appliance')->first();
        $this->assertNotNull($product, 'Test DB must contain at least one appliance product.');

        $location = \App\Models\Location::where('is_active', 1)->first();
        $this->assertNotNull($location, 'Test DB must contain an active location.');

        $serial = 'SN-' . strtoupper(uniqid());
        ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $location->location_id,
            'serial_number' => $serial,
            'serial_type'   => 'received',
            'status_id'     => 26,
        ]);

        Inventory::create([
            'product_id'         => $product->product_id,
            'location_id'        => $location->location_id,
            'quantity_on_hand'   => 5,
            'available_quantity' => 5,
            'packages_on_hand'   => 0,
        ]);

        $warranty = WarrantyRecord::create(array_merge([
            'product_id'             => $product->product_id,
            'serial_number'          => $serial,
            'customer_id'            => $customer->customer_id,
            'sale_date'              => Carbon::today()->subMonths(2)->toDateString(),
            'warranty_start_date'    => Carbon::today()->subMonths(2)->toDateString(),
            'warranty_end_date'      => Carbon::today()->addMonths(10)->toDateString(),
            'warranty_period_months' => 12,
            'warranty_duration_label'=> '1 Year',
            'status_id'              => 33,
        ], $warrantyOverrides));

        return [
            'product'  => $product,
            'location' => $location,
            'serial'   => $serial,
            'warranty' => $warranty,
        ];
    }

    /** A consumable product (spare part) with stock at the given location. */
    protected function makeConsumable(int $locationId, int $qty = 100): Product
    {
        $product = Product::where('product_type', 'consumable')->first();
        $this->assertNotNull($product, 'Test DB must contain at least one consumable product.');

        Inventory::updateOrCreate(
            ['product_id' => $product->product_id, 'location_id' => $locationId],
            ['quantity_on_hand' => $qty, 'available_quantity' => $qty, 'packages_on_hand' => 0],
        );

        return $product;
    }
}
```

- [ ] **Step 5: Create the feature-test skeleton and verify the harness**

`back-end/tests/Feature/WarrantyClaimTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesWarrantyTestData;
use Tests\TestCase;

class WarrantyClaimTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesWarrantyTestData;

    public function test_harness_can_build_warranty_fixture(): void
    {
        $this->actingUser();
        $customer = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);

        $this->assertSame(33, $appliance['warranty']->status_id);
        $this->assertDatabaseHas('item_serial', [
            'serial_number' => $appliance['serial'],
            'status_id'     => 26,
        ]);
    }
}
```

- [ ] **Step 6: Run the harness test**

```bash
php artisan test --filter=WarrantyClaimTest::test_harness_can_build_warranty_fixture
```

Expected: PASS. If a fixture assertion fails ("must contain…"), the cloned DB lacks seed rows — re-check Step 1.

- [ ] **Step 7: Commit**

```bash
git commit -am "test: warranty claim feature-test harness against MySQL test DB"
```

---

## Task 2: Migration — claim tables

**Files:**
- Create: `back-end/database/migrations/2026_06_07_000001_create_warranty_claims_tables.php`

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
        // The legacy warranty_claims model was never backed by a table. If a
        // stray table exists, drop it so we own a clean, FK-linked schema.
        Schema::dropIfExists('warranty_claim_details');
        Schema::dropIfExists('warranty_claims');

        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->bigIncrements('claim_id');
            $table->string('claim_number', 40)->unique();
            $table->unsignedBigInteger('warranty_id');
            $table->foreign('warranty_id')->references('warranty_id')->on('warranty_records')->onDelete('cascade');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreign('customer_id')->references('customer_id')->on('customers')->onDelete('set null');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->foreign('product_id')->references('product_id')->on('products')->onDelete('set null');
            $table->string('serial_number', 100)->nullable();
            $table->enum('claim_type', ['repair', 'replacement']);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->foreign('location_id')->references('location_id')->on('locations')->onDelete('set null');
            $table->unsignedBigInteger('status_id')->nullable();
            $table->foreign('status_id')->references('status_id')->on('status_lookup')->onDelete('set null');
            $table->decimal('parts_cost', 12, 2)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('user_id')->on('users')->onDelete('set null');
            $table->timestamp('completed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index('serial_number');
        });

        Schema::create('warranty_claim_details', function (Blueprint $table) {
            $table->bigIncrements('claim_detail_id');
            $table->unsignedBigInteger('claim_id');
            $table->foreign('claim_id')->references('claim_id')->on('warranty_claims')->onDelete('cascade');
            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')->references('product_id')->on('products')->onDelete('cascade');
            $table->string('serial_number', 100)->nullable();
            $table->integer('quantity_issued')->default(1);
            $table->integer('quantity_in_pieces')->default(1);
            $table->string('quantity_unit', 50)->nullable();
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('line_cost', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claim_details');
        Schema::dropIfExists('warranty_claims');
    }
};
```

- [ ] **Step 2: Apply to the test DB and dev DB**

```bash
php artisan migrate --env=testing --force
php artisan migrate --force
```

Expected: both create `warranty_claims` and `warranty_claim_details` without error.

- [ ] **Step 3: Verify tables exist**

```bash
php artisan tinker --execute="echo Schema::hasTable('warranty_claims') && Schema::hasTable('warranty_claim_details') ? 'OK' : 'MISSING';"
```

Expected: `OK`.

- [ ] **Step 4: Commit**

```bash
git commit -am "feat: warranty_claims and warranty_claim_details tables"
```

---

## Task 3: Migration — claim statuses

**Files:**
- Create: `back-end/database/migrations/2026_06_07_000002_add_warranty_claim_statuses.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['Pending', 'Completed'] as $name) {
            DB::table('status_lookup')->updateOrInsert(
                ['status_category' => 'warranty_claim', 'status_name' => $name],
                ['is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('status_lookup')
            ->where('status_category', 'warranty_claim')
            ->whereIn('status_name', ['Pending', 'Completed'])
            ->delete();
    }
};
```

- [ ] **Step 2: Apply to both DBs**

```bash
php artisan migrate --env=testing --force
php artisan migrate --force
```

Expected: applied, no error.

- [ ] **Step 3: Verify the rows exist**

```bash
php artisan tinker --execute="echo \App\Models\StatusLookup::where('status_category','warranty_claim')->pluck('status_name')->implode(',');"
```

Expected: `Pending,Completed`.

- [ ] **Step 4: Commit**

```bash
git commit -am "feat: warranty_claim Pending/Completed statuses"
```

---

## Task 4: Models

**Files:**
- Modify: `back-end/app/Models/WarrantyClaim.php`
- Create: `back-end/app/Models/WarrantyClaimDetail.php`
- Modify: `back-end/app/Models/WarrantyRecord.php`
- Modify: `back-end/app/Models/Customer.php`
- Test: `back-end/tests/Feature/WarrantyClaimTest.php`

- [ ] **Step 1: Write a failing relations test**

Add to `WarrantyClaimTest`:

```php
    public function test_claim_models_and_relations_resolve(): void
    {
        $this->actingUser();
        $customer = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);

        $claim = \App\Models\WarrantyClaim::create([
            'claim_number' => 'WC-TEST-' . uniqid(),
            'warranty_id'  => $appliance['warranty']->warranty_id,
            'customer_id'  => $customer->customer_id,
            'product_id'   => $appliance['product']->product_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'location_id'  => $appliance['location']->location_id,
            'status_id'    => \App\Models\StatusLookup::where('status_category','warranty_claim')->where('status_name','Pending')->value('status_id'),
            'created_by'   => \Illuminate\Support\Facades\Auth::id(),
        ]);

        $claim->details()->create([
            'product_id'         => $appliance['product']->product_id,
            'quantity_issued'    => 1,
            'quantity_in_pieces' => 1,
            'unit_cost'          => 10,
            'line_cost'          => 10,
        ]);

        $this->assertCount(1, $claim->fresh()->details);
        $this->assertSame($customer->customer_id, $claim->customer->customer_id);
        $this->assertSame($appliance['warranty']->warranty_id, $claim->warranty->warranty_id);
        $this->assertCount(1, $customer->fresh()->warrantyClaims);
    }
```

- [ ] **Step 2: Run it — expect failure**

```bash
php artisan test --filter=WarrantyClaimTest::test_claim_models_and_relations_resolve
```

Expected: FAIL (e.g. "Call to undefined method … details()" or mass-assignment error).

- [ ] **Step 3: Rebuild `WarrantyClaim` model**

Replace `back-end/app/Models/WarrantyClaim.php` with:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;

class WarrantyClaim extends Model
{
    use Auditable, SoftDeletes;

    protected $table = 'warranty_claims';
    protected $primaryKey = 'claim_id';

    protected $fillable = [
        'claim_number', 'warranty_id', 'customer_id', 'product_id',
        'serial_number', 'claim_type', 'notes', 'location_id',
        'status_id', 'parts_cost', 'created_by', 'completed_at',
    ];

    protected $casts = [
        'parts_cost'   => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    public function details()
    {
        return $this->hasMany(WarrantyClaimDetail::class, 'claim_id', 'claim_id');
    }

    public function warranty()
    {
        return $this->belongsTo(WarrantyRecord::class, 'warranty_id', 'warranty_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function status()
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
```

- [ ] **Step 4: Create `WarrantyClaimDetail` model**

`back-end/app/Models/WarrantyClaimDetail.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarrantyClaimDetail extends Model
{
    protected $table = 'warranty_claim_details';
    protected $primaryKey = 'claim_detail_id';

    protected $fillable = [
        'claim_id', 'product_id', 'serial_number',
        'quantity_issued', 'quantity_in_pieces', 'quantity_unit',
        'unit_cost', 'line_cost',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'line_cost' => 'decimal:2',
    ];

    public function claim()
    {
        return $this->belongsTo(WarrantyClaim::class, 'claim_id', 'claim_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
```

- [ ] **Step 5: Add `claims()` to `WarrantyRecord`**

In `back-end/app/Models/WarrantyRecord.php`, after the `claimedByUser()` method, add:

```php
    public function claims()
    {
        return $this->hasMany(WarrantyClaim::class, 'warranty_id', 'warranty_id');
    }
```

- [ ] **Step 6: Add `warrantyClaims()` to `Customer`**

In `back-end/app/Models/Customer.php`, after the `warrantyRecords()` method, add:

```php
    public function warrantyClaims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class, 'customer_id', 'customer_id')
                    ->orderBy('created_at', 'desc');
    }
```

- [ ] **Step 7: Run the test — expect pass**

```bash
php artisan test --filter=WarrantyClaimTest::test_claim_models_and_relations_resolve
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git commit -am "feat: warranty claim models and relations"
```

---

## Task 5: Service — `initClaim` validation

**Files:**
- Create: `back-end/app/Services/WarrantyClaimService.php`
- Test: `back-end/tests/Feature/WarrantyClaimTest.php`

The service owns all logic; the controller (Task 9) only maps HTTP ↔ service. `initClaim` validates the warranty and creates a `pending` claim.

- [ ] **Step 1: Write failing tests for validation rules**

Add to `WarrantyClaimTest`:

```php
    private function service(): \App\Services\WarrantyClaimService
    {
        return app(\App\Services\WarrantyClaimService::class);
    }

    public function test_init_claim_succeeds_for_active_warranty(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);

        $claim = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'notes'        => 'Not cooling',
            'location_id'  => $appliance['location']->location_id,
        ]);

        $this->assertSame('repair', $claim->claim_type);
        $this->assertSame($appliance['warranty']->warranty_id, $claim->warranty_id);
        $this->assertStringStartsWith('WC-', $claim->claim_number);
        $pending = \App\Models\StatusLookup::where('status_category','warranty_claim')->where('status_name','Pending')->value('status_id');
        $this->assertSame((int) $pending, (int) $claim->status_id);
    }

    public function test_init_claim_rejects_expired_warranty(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer, [
            'warranty_end_date' => \Illuminate\Support\Carbon::today()->subDay()->toDateString(),
            'status_id'         => 34,
        ]);

        $this->expectException(\App\Exceptions\WarrantyClaimException::class);
        $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'replacement',
            'location_id'  => $appliance['location']->location_id,
        ]);
    }

    public function test_init_claim_rejects_already_claimed_warranty(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer, ['status_id' => 35]);

        $this->expectException(\App\Exceptions\WarrantyClaimException::class);
        $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'location_id'  => $appliance['location']->location_id,
        ]);
    }

    public function test_init_claim_rejects_customer_mismatch(): void
    {
        $this->actingUser();
        $owner    = $this->makeCustomer();
        $other    = $this->makeCustomer(['customer_name' => 'Someone Else']);
        $appliance = $this->makeAppliance($owner);

        $this->expectException(\App\Exceptions\WarrantyClaimException::class);
        $this->service()->initClaim([
            'customer_id'  => $other->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'location_id'  => $appliance['location']->location_id,
        ]);
    }
```

- [ ] **Step 2: Run — expect failure**

```bash
php artisan test --filter="WarrantyClaimTest::test_init_claim"
```

Expected: FAIL (service/exception classes don't exist).

- [ ] **Step 3: Create the exception class**

`back-end/app/Exceptions/WarrantyClaimException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class WarrantyClaimException extends RuntimeException
{
}
```

- [ ] **Step 4: Create the service with `initClaim`**

`back-end/app/Services/WarrantyClaimService.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\WarrantyClaimException;
use App\Models\StatusLookup;
use App\Models\WarrantyClaim;
use App\Models\WarrantyRecord;
use Illuminate\Support\Facades\Auth;

class WarrantyClaimService
{
    private const STATUS_WARRANTY_ACTIVE  = 33;
    private const STATUS_WARRANTY_CLAIMED = 35;

    public function claimStatusId(string $name): int
    {
        $id = StatusLookup::where('status_category', 'warranty_claim')
            ->where('status_name', $name)
            ->value('status_id');

        if (! $id) {
            throw new WarrantyClaimException("Missing warranty_claim status '{$name}'. Run migrations.");
        }
        return (int) $id;
    }

    /**
     * Validate the warranty for the given serial and create a pending claim.
     *
     * @throws WarrantyClaimException on any validation failure.
     */
    public function initClaim(array $data): WarrantyClaim
    {
        $serial = trim((string) ($data['serial_number'] ?? ''));

        $warranty = WarrantyRecord::whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($serial)])
            ->latest('warranty_id')
            ->first();

        if (! $warranty) {
            throw new WarrantyClaimException('No warranty record found for this serial number.');
        }
        if ($warranty->status_id === self::STATUS_WARRANTY_CLAIMED) {
            throw new WarrantyClaimException('This warranty has already been claimed.');
        }
        if ($warranty->is_expired || $warranty->status_id !== self::STATUS_WARRANTY_ACTIVE) {
            throw new WarrantyClaimException('This warranty is expired and cannot be claimed.');
        }
        if ((int) $warranty->customer_id !== (int) $data['customer_id']) {
            throw new WarrantyClaimException('This warranty belongs to a different customer.');
        }

        return WarrantyClaim::create([
            'claim_number'  => $this->generateClaimNumber(),
            'warranty_id'   => $warranty->warranty_id,
            'customer_id'   => $warranty->customer_id,
            'product_id'    => $warranty->product_id,
            'serial_number' => $warranty->serial_number,
            'claim_type'    => $data['claim_type'],
            'notes'         => $data['notes'] ?? null,
            'location_id'   => $data['location_id'] ?? null,
            'status_id'     => $this->claimStatusId('Pending'),
            'parts_cost'    => 0,
            'created_by'    => Auth::id(),
        ]);
    }

    private function generateClaimNumber(): string
    {
        return 'WC-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
    }
}
```

- [ ] **Step 5: Run — expect pass**

```bash
php artisan test --filter="WarrantyClaimTest::test_init_claim"
```

Expected: 4 passing.

- [ ] **Step 6: Commit**

```bash
git commit -am "feat: WarrantyClaimService.initClaim with warranty validation"
```

---

## Task 6: Service — `addItem` (repair parts + replacement unit)

**Files:**
- Modify: `back-end/app/Services/WarrantyClaimService.php`
- Test: `back-end/tests/Feature/WarrantyClaimTest.php`

- [ ] **Step 1: Write failing tests**

Add to `WarrantyClaimTest`:

```php
    public function test_repair_addItem_deducts_consumable_stock(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $locationId = $appliance['location']->location_id;
        $part = $this->makeConsumable($locationId, 100);

        $claim = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'location_id'  => $locationId,
        ]);

        $detail = $this->service()->addItem($claim, [
            'barcode'  => $part->barcode ?: $part->product_code,
            'quantity' => 3,
        ]);

        $this->assertSame(3, (int) $detail->quantity_in_pieces);
        $this->assertNull($detail->serial_number);
        $this->assertDatabaseHas('inventory', [
            'product_id'       => $part->product_id,
            'location_id'      => $locationId,
            'quantity_on_hand' => 97,
        ]);
    }

    public function test_replacement_addItem_consumes_serial_unit(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);          // claimed item
        $replacement = $this->makeAppliance($customer);        // a second in-stock unit to give out
        $locationId = $appliance['location']->location_id;

        $claim = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'replacement',
            'location_id'  => $locationId,
        ]);

        $detail = $this->service()->addItem($claim, [
            'barcode' => $replacement['serial'],
        ]);

        $this->assertSame($replacement['serial'], $detail->serial_number);
        $this->assertDatabaseMissing('item_serial', [
            'serial_number' => $replacement['serial'],
            'status_id'     => 26,
        ]);
    }

    public function test_repair_rejects_serialized_item(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $replacement = $this->makeAppliance($customer);

        $claim = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'location_id'  => $appliance['location']->location_id,
        ]);

        $this->expectException(\App\Exceptions\WarrantyClaimException::class);
        $this->service()->addItem($claim, ['barcode' => $replacement['serial']]);
    }

    public function test_replacement_allows_only_one_unit(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $r1 = $this->makeAppliance($customer);
        $r2 = $this->makeAppliance($customer);

        $claim = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'replacement',
            'location_id'  => $appliance['location']->location_id,
        ]);

        $this->service()->addItem($claim, ['barcode' => $r1['serial']]);

        $this->expectException(\App\Exceptions\WarrantyClaimException::class);
        $this->service()->addItem($claim->fresh(), ['barcode' => $r2['serial']]);
    }
```

- [ ] **Step 2: Run — expect failure**

```bash
php artisan test --filter="WarrantyClaimTest::test_repair_addItem_deducts_consumable_stock"
```

Expected: FAIL ("Call to undefined method … addItem").

- [ ] **Step 3: Implement `addItem`**

Add to `WarrantyClaimService` (add the trait + imports at the top of the class):

```php
use App\Models\Inventory;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Models\WarrantyClaimDetail;
use Illuminate\Support\Facades\DB;
```

Add the barcode trait to the class:

```php
class WarrantyClaimService
{
    use \App\Traits\NormalizesBarcode;

    private const STATUS_SERIAL_IN_STOCK = 26;
    // ... existing constants
```

Then the method:

```php
    /**
     * Add one item to a pending claim and deduct it from inventory.
     * Repair → a consumable (barcode + quantity). Replacement → one serialized unit.
     *
     * @throws WarrantyClaimException
     */
    public function addItem(WarrantyClaim $claim, array $data): WarrantyClaimDetail
    {
        $this->assertPending($claim);

        $locationId = (int) $claim->location_id;
        $raw        = trim((string) ($data['barcode'] ?? ''));
        if ($raw === '') {
            throw new WarrantyClaimException('Barcode is required.');
        }
        $candidates = $this->barcodeLookupCandidates($raw);

        if ($claim->claim_type === 'replacement') {
            return $this->addReplacementUnit($claim, $locationId, $raw, $candidates);
        }
        return $this->addRepairPart($claim, $locationId, $candidates, (float) ($data['quantity'] ?? 1));
    }

    private function addRepairPart(WarrantyClaim $claim, int $locationId, array $candidates, float $qty): WarrantyClaimDetail
    {
        $product = $this->findProductByBarcodeCandidates($candidates);
        if (! $product) {
            throw new WarrantyClaimException('Part not found. Scan a stocked consumable barcode.');
        }
        $product->load('consumableDetail.unit');
        if ($product->isSerialized()) {
            throw new WarrantyClaimException('Repairs use consumable parts only. This is a serialized appliance.');
        }

        $pieces = (int) max(1, ceil($qty));
        $unitName = $product->consumableDetail?->unit?->unit_name ?? 'piece';

        return DB::transaction(function () use ($claim, $product, $locationId, $pieces, $unitName) {
            $inv = Inventory::where('product_id', $product->product_id)
                ->where('location_id', $locationId)->lockForUpdate()->first();

            if (! $inv || $inv->quantity_on_hand < $pieces) {
                throw new WarrantyClaimException('Insufficient stock for this part.');
            }

            $inv->quantity_on_hand   = max(0, $inv->quantity_on_hand - $pieces);
            $inv->available_quantity = max(0, $inv->available_quantity - $pieces);
            $ppp = $product->getPiecesPerPackage();
            if ($ppp > 0) {
                $inv->packages_on_hand = (int) floor($inv->quantity_on_hand / $ppp);
            }
            $inv->save();

            $unitCost = (float) ($product->cost_price ?? 0);
            $detail = $claim->details()->create([
                'product_id'         => $product->product_id,
                'serial_number'      => null,
                'quantity_issued'    => $pieces,
                'quantity_in_pieces' => $pieces,
                'quantity_unit'      => $unitName,
                'unit_cost'          => $unitCost,
                'line_cost'          => round($unitCost * $pieces, 2),
            ]);

            $this->refreshPartsCost($claim);
            return $detail;
        });
    }

    private function addReplacementUnit(WarrantyClaim $claim, int $locationId, string $raw, array $candidates): WarrantyClaimDetail
    {
        if ($claim->details()->exists()) {
            throw new WarrantyClaimException('A replacement claim can only include one unit.');
        }

        $serial = null;
        foreach ($candidates as $candidate) {
            $serial = ItemSerial::where('location_id', $locationId)
                ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($candidate)])
                ->where('status_id', self::STATUS_SERIAL_IN_STOCK)
                ->first();
            if ($serial) break;
        }
        if (! $serial) {
            throw new WarrantyClaimException('Replacement unit not found in stock at this location. Scan a unit serial.');
        }

        $product = Product::find($serial->product_id);

        return DB::transaction(function () use ($claim, $product, $serial, $locationId, $raw) {
            $inv = Inventory::where('product_id', $product->product_id)
                ->where('location_id', $locationId)->lockForUpdate()->first();
            if (! $inv || $inv->quantity_on_hand < 1) {
                throw new WarrantyClaimException('Insufficient stock for the replacement unit.');
            }
            $inv->quantity_on_hand   = max(0, $inv->quantity_on_hand - 1);
            $inv->available_quantity = max(0, $inv->available_quantity - 1);
            $inv->save();

            $serial->delete();

            $unitCost = (float) ($product->cost_price ?? 0);
            $detail = $claim->details()->create([
                'product_id'         => $product->product_id,
                'serial_number'      => $raw,
                'quantity_issued'    => 1,
                'quantity_in_pieces' => 1,
                'quantity_unit'      => 'unit',
                'unit_cost'          => $unitCost,
                'line_cost'          => $unitCost,
            ]);

            $this->refreshPartsCost($claim);
            return $detail;
        });
    }

    private function assertPending(WarrantyClaim $claim): void
    {
        if ((int) $claim->status_id !== $this->claimStatusId('Pending')) {
            throw new WarrantyClaimException('This claim is no longer editable.');
        }
    }

    private function refreshPartsCost(WarrantyClaim $claim): void
    {
        $claim->update([
            'parts_cost' => (float) $claim->details()->sum('line_cost'),
        ]);
    }
```

- [ ] **Step 4: Run the addItem tests — expect pass**

```bash
php artisan test --filter="WarrantyClaimTest::test_repair_addItem_deducts_consumable_stock"
php artisan test --filter="WarrantyClaimTest::test_replacement_addItem_consumes_serial_unit"
php artisan test --filter="WarrantyClaimTest::test_repair_rejects_serialized_item"
php artisan test --filter="WarrantyClaimTest::test_replacement_allows_only_one_unit"
```

Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git commit -am "feat: WarrantyClaimService.addItem (repair parts + replacement unit) with stock deduction"
```

---

## Task 7: Service — `removeItem`

**Files:**
- Modify: `back-end/app/Services/WarrantyClaimService.php`
- Test: `back-end/tests/Feature/WarrantyClaimTest.php`

- [ ] **Step 1: Write failing tests**

```php
    public function test_remove_repair_part_restores_stock(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $locationId = $appliance['location']->location_id;
        $part = $this->makeConsumable($locationId, 100);

        $claim  = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'location_id'  => $locationId,
        ]);
        $detail = $this->service()->addItem($claim, ['barcode' => $part->barcode ?: $part->product_code, 'quantity' => 4]);

        $this->service()->removeItem($claim->fresh(), $detail->claim_detail_id);

        $this->assertDatabaseHas('inventory', [
            'product_id' => $part->product_id, 'location_id' => $locationId, 'quantity_on_hand' => 100,
        ]);
        $this->assertDatabaseMissing('warranty_claim_details', ['claim_detail_id' => $detail->claim_detail_id]);
    }

    public function test_remove_replacement_unit_restores_serial(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $replacement = $this->makeAppliance($customer);

        $claim  = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'replacement',
            'location_id'  => $appliance['location']->location_id,
        ]);
        $detail = $this->service()->addItem($claim, ['barcode' => $replacement['serial']]);

        $this->service()->removeItem($claim->fresh(), $detail->claim_detail_id);

        $this->assertDatabaseHas('item_serial', [
            'serial_number' => $replacement['serial'], 'status_id' => 26,
        ]);
    }
```

- [ ] **Step 2: Run — expect failure**

```bash
php artisan test --filter="WarrantyClaimTest::test_remove_repair_part_restores_stock"
```

Expected: FAIL ("undefined method … removeItem").

- [ ] **Step 3: Implement `removeItem`**

Add to `WarrantyClaimService`:

```php
    /** @throws WarrantyClaimException */
    public function removeItem(WarrantyClaim $claim, int $detailId): void
    {
        $this->assertPending($claim);

        $detail = $claim->details()->where('claim_detail_id', $detailId)->first();
        if (! $detail) {
            throw new WarrantyClaimException('Item not found on this claim.');
        }
        $product = Product::with(['applianceDetail', 'consumableDetail'])->find($detail->product_id);

        DB::transaction(function () use ($claim, $detail, $product) {
            $inv = Inventory::where('product_id', $detail->product_id)
                ->where('location_id', $claim->location_id)->lockForUpdate()->first();

            if ($inv) {
                $inv->quantity_on_hand   += $detail->quantity_in_pieces;
                $inv->available_quantity += $detail->quantity_in_pieces;
                if ($product && ! $product->isSerialized()) {
                    $ppp = $product->getPiecesPerPackage();
                    if ($ppp > 0) {
                        $inv->packages_on_hand = (int) floor($inv->quantity_on_hand / $ppp);
                    }
                }
                $inv->save();
            }

            if ($product && $product->isSerialized() && $detail->serial_number) {
                ItemSerial::create([
                    'product_id'    => $detail->product_id,
                    'location_id'   => $claim->location_id,
                    'serial_number' => $detail->serial_number,
                    'serial_type'   => 'received',
                    'status_id'     => self::STATUS_SERIAL_IN_STOCK,
                    'scanned_by'    => Auth::id(),
                ]);
            }

            $detail->delete();
            $this->refreshPartsCost($claim);
        });
    }
```

- [ ] **Step 4: Run — expect pass**

```bash
php artisan test --filter="WarrantyClaimTest::test_remove_repair_part_restores_stock"
php artisan test --filter="WarrantyClaimTest::test_remove_replacement_unit_restores_serial"
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git commit -am "feat: WarrantyClaimService.removeItem restores stock and serials"
```

---

## Task 8: Service — `completeClaim`

**Files:**
- Modify: `back-end/app/Services/WarrantyClaimService.php`
- Test: `back-end/tests/Feature/WarrantyClaimTest.php`

Completing a claim: re-validate, mark the original warranty **Claimed**, for replacements create a new warranty record inheriting the original end date, write an audit-trail entry, finalize.

- [ ] **Step 1: Write failing tests**

```php
    public function test_complete_repair_marks_original_claimed_no_new_warranty(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $locationId = $appliance['location']->location_id;
        $part = $this->makeConsumable($locationId, 50);

        $claim = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'location_id'  => $locationId,
        ]);
        $this->service()->addItem($claim, ['barcode' => $part->barcode ?: $part->product_code, 'quantity' => 2]);

        $before = \App\Models\WarrantyRecord::count();
        $done = $this->service()->completeClaim($claim->fresh());

        $this->assertSame($this->service()->claimStatusId('Completed'), (int) $done->status_id);
        $this->assertSame(35, (int) $appliance['warranty']->fresh()->status_id);
        $this->assertSame($before, \App\Models\WarrantyRecord::count(), 'Repair must not create a warranty record.');
    }

    public function test_complete_replacement_inherits_end_date_and_is_claimable(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $replacement = $this->makeAppliance($customer);
        $locationId = $appliance['location']->location_id;

        $originalEnd = $appliance['warranty']->warranty_end_date->toDateString();

        $claim = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'replacement',
            'location_id'  => $locationId,
        ]);
        $this->service()->addItem($claim, ['barcode' => $replacement['serial']]);

        $this->service()->completeClaim($claim->fresh());

        $this->assertSame(35, (int) $appliance['warranty']->fresh()->status_id);

        $new = \App\Models\WarrantyRecord::whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($replacement['serial'])])
            ->where('status_id', 33)->latest('warranty_id')->first();
        $this->assertNotNull($new, 'Replacement must create a new active warranty.');
        $this->assertSame($originalEnd, $new->warranty_end_date->toDateString());
    }

    public function test_complete_rejects_empty_claim(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);

        $claim = $this->service()->initClaim([
            'customer_id'  => $customer->customer_id,
            'serial_number'=> $appliance['serial'],
            'claim_type'   => 'repair',
            'location_id'  => $appliance['location']->location_id,
        ]);

        $this->expectException(\App\Exceptions\WarrantyClaimException::class);
        $this->service()->completeClaim($claim->fresh());
    }
```

- [ ] **Step 2: Run — expect failure**

```bash
php artisan test --filter="WarrantyClaimTest::test_complete_repair_marks_original_claimed_no_new_warranty"
```

Expected: FAIL ("undefined method … completeClaim").

- [ ] **Step 3: Implement `completeClaim`**

Add imports to the service: `use App\Models\AuditTrail;` and `use App\Models\WarrantyRecord;` (already present). Then:

```php
    /** @throws WarrantyClaimException */
    public function completeClaim(WarrantyClaim $claim): WarrantyClaim
    {
        $this->assertPending($claim);

        if (! $claim->details()->exists()) {
            throw new WarrantyClaimException('Add at least one item before completing the claim.');
        }
        if ($claim->claim_type === 'replacement' && $claim->details()->count() !== 1) {
            throw new WarrantyClaimException('A replacement claim must contain exactly one unit.');
        }

        return DB::transaction(function () use ($claim) {
            $warranty = WarrantyRecord::lockForUpdate()->find($claim->warranty_id);

            if (! $warranty || $warranty->status_id !== self::STATUS_WARRANTY_ACTIVE || $warranty->is_expired) {
                throw new WarrantyClaimException('Warranty is no longer claimable.');
            }

            // 1. Mark original warranty Claimed.
            $warranty->update([
                'status_id'   => self::STATUS_WARRANTY_CLAIMED,
                'claim_date'  => now()->toDateString(),
                'claimed_by'  => Auth::id(),
                'claim_notes' => $claim->notes,
            ]);

            // 2. Replacement → new warranty inheriting the original end date.
            if ($claim->claim_type === 'replacement') {
                $unit = $claim->details()->first();
                WarrantyRecord::create([
                    'product_id'              => $unit->product_id,
                    'serial_number'           => $unit->serial_number,
                    'customer_id'             => $claim->customer_id,
                    'sale_date'               => now()->toDateString(),
                    'warranty_start_date'     => now()->toDateString(),
                    'warranty_end_date'       => $warranty->warranty_end_date,
                    'warranty_period_months'  => $warranty->warranty_period_months,
                    'warranty_duration_label' => $warranty->warranty_duration_label,
                    'status_id'               => self::STATUS_WARRANTY_ACTIVE,
                    'notes'                   => 'Replacement under claim ' . $claim->claim_number,
                ]);
            }

            // 3. Finalize claim.
            $claim->update([
                'status_id'    => $this->claimStatusId('Completed'),
                'parts_cost'   => (float) $claim->details()->sum('line_cost'),
                'completed_at' => now(),
            ]);

            // 4. Audit trail (best-effort).
            try {
                AuditTrail::create([
                    'user_id'    => Auth::id(),
                    'action'     => 'warranty_claim_completed',
                    'table_name' => 'warranty_claims',
                    'record_id'  => $claim->claim_id,
                    'old_values' => null,
                    'new_values' => [
                        'claim_number' => $claim->claim_number,
                        'claim_type'   => $claim->claim_type,
                        'warranty_id'  => $claim->warranty_id,
                        'serial'       => $claim->serial_number,
                        'items'        => $claim->details()->count(),
                        'parts_cost'   => $claim->parts_cost,
                    ],
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }

            return $claim->fresh(['details', 'warranty', 'customer']);
        });
    }
```

- [ ] **Step 4: Run — expect pass**

```bash
php artisan test --filter="WarrantyClaimTest::test_complete"
```

Expected: 3 passing.

- [ ] **Step 5: Run the whole claim suite**

```bash
php artisan test --filter=WarrantyClaimTest
```

Expected: all green.

- [ ] **Step 6: Commit**

```bash
git commit -am "feat: WarrantyClaimService.completeClaim (claim original, inherit end date on replacement, audit)"
```

---

## Task 9: Controller + routes

**Files:**
- Modify (rewrite): `back-end/app/Http/Controllers/Api/WarrantyClaimController.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/WarrantyClaimTest.php`

- [ ] **Step 1: Write a failing HTTP end-to-end test**

```php
    public function test_http_full_repair_flow(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $locationId = $appliance['location']->location_id;
        $part = $this->makeConsumable($locationId, 30);

        $init = $this->postJson('/api/warranty-claims/init', [
            'customer_id'   => $customer->customer_id,
            'serial_number' => $appliance['serial'],
            'claim_type'    => 'repair',
            'notes'         => 'Compressor noise',
            'location_id'   => $locationId,
        ])->assertStatus(201)->json();

        $claimId = $init['data']['claim_id'] ?? $init['claim_id'];

        $this->postJson("/api/warranty-claims/{$claimId}/item", [
            'barcode'  => $part->barcode ?: $part->product_code,
            'quantity' => 1,
        ])->assertOk();

        $this->postJson("/api/warranty-claims/{$claimId}/complete")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(35, (int) $appliance['warranty']->fresh()->status_id);
    }

    public function test_http_init_rejects_expired_with_422(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer, [
            'warranty_end_date' => \Illuminate\Support\Carbon::today()->subDay()->toDateString(),
            'status_id'         => 34,
        ]);

        $this->postJson('/api/warranty-claims/init', [
            'customer_id'   => $customer->customer_id,
            'serial_number' => $appliance['serial'],
            'claim_type'    => 'repair',
            'location_id'   => $appliance['location']->location_id,
        ])->assertStatus(422);
    }
```

- [ ] **Step 2: Run — expect failure**

```bash
php artisan test --filter="WarrantyClaimTest::test_http_full_repair_flow"
```

Expected: FAIL (route 404 / method not found).

- [ ] **Step 3: Rewrite the controller**

Replace `back-end/app/Http/Controllers/Api/WarrantyClaimController.php` with:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WarrantyClaimException;
use App\Http\Controllers\Controller;
use App\Models\WarrantyClaim;
use App\Services\WarrantyClaimService;
use App\Traits\ApiResponse;
use App\Traits\ResolvesUserLocation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarrantyClaimController extends Controller
{
    use ApiResponse;
    use ResolvesUserLocation;

    public function __construct(private WarrantyClaimService $service)
    {
    }

    public function index(Request $request)
    {
        $query = WarrantyClaim::with(['customer', 'product', 'status', 'warranty'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('claim_type')) {
            $query->where('claim_type', $request->string('claim_type'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }
        if ($request->filled('search')) {
            $s = $request->string('search');
            $query->where(fn ($q) => $q
                ->where('claim_number', 'like', "%{$s}%")
                ->orWhere('serial_number', 'like', "%{$s}%"));
        }

        return $this->paginated($query->paginate($request->integer('per_page', 15)), 'Warranty claims retrieved');
    }

    public function init(Request $request)
    {
        try {
            $validated = $request->validate([
                'customer_id'   => 'required|integer|exists:customers,customer_id',
                'serial_number' => 'required|string|max:100',
                'claim_type'    => ['required', Rule::in(['repair', 'replacement'])],
                'notes'         => 'nullable|string|max:1000',
                'location_id'   => 'nullable|integer|exists:locations,location_id',
            ]);

            $locationId = $this->resolveLocationId($request);
            if ($locationId === null) {
                return $this->locationErrorResponse();
            }
            $validated['location_id'] = $locationId;

            $claim = $this->service->initClaim($validated);
            return $this->success($this->show($claim->claim_id, true), 'Claim started.', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (WarrantyClaimException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function addItem(Request $request, int $claimId)
    {
        try {
            $validated = $request->validate([
                'barcode'  => 'required|string|max:191',
                'quantity' => 'nullable|numeric|min:0.001',
            ]);
            $claim = WarrantyClaim::findOrFail($claimId);
            $this->service->addItem($claim, $validated);
            return $this->success($this->show($claimId, true), 'Item added.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (WarrantyClaimException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function removeItem(int $claimId, int $detailId)
    {
        try {
            $claim = WarrantyClaim::findOrFail($claimId);
            $this->service->removeItem($claim, $detailId);
            return $this->success($this->show($claimId, true), 'Item removed.');
        } catch (WarrantyClaimException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function complete(int $claimId)
    {
        try {
            $claim = WarrantyClaim::findOrFail($claimId);
            $this->service->completeClaim($claim);
            return $this->success($this->show($claimId, true), 'Warranty claim completed.');
        } catch (WarrantyClaimException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** @return array|\Illuminate\Http\JsonResponse */
    public function show(int $claimId, bool $asArray = false)
    {
        $claim = WarrantyClaim::with([
            'details.product.applianceDetail.brand',
            'details.product.consumableDetail.unit',
            'customer', 'product', 'location', 'status', 'warranty', 'createdByUser',
        ])->find($claimId);

        if (! $claim) {
            return $asArray ? [] : $this->notFound('Claim not found');
        }
        $payload = $this->format($claim);
        return $asArray ? $payload : $this->success($payload, 'Claim retrieved');
    }

    private function format(WarrantyClaim $c): array
    {
        return [
            'claim_id'      => $c->claim_id,
            'claim_number'  => $c->claim_number,
            'claim_type'    => $c->claim_type,
            'status_name'   => $c->status?->status_name,
            'notes'         => $c->notes,
            'serial_number' => $c->serial_number,
            'parts_cost'    => $c->parts_cost,
            'completed_at'  => $c->completed_at?->toDateTimeString(),
            'location_name' => $c->location?->location_name,
            'warranty' => $c->warranty ? [
                'warranty_id'       => $c->warranty->warranty_id,
                'warranty_end_date' => $c->warranty->warranty_end_date?->format('Y-m-d'),
                'status_id'         => $c->warranty->status_id,
            ] : null,
            'customer' => $c->customer ? [
                'customer_id'   => $c->customer->customer_id,
                'customer_name' => $c->customer->customer_name,
                'customer_type' => $c->customer->customer_type,
                'company_name'  => $c->customer->company_name,
                'contact_number'=> $c->customer->contact_number,
            ] : null,
            'items' => $c->details->map(fn ($d) => [
                'detail_id'          => $d->claim_detail_id,
                'product_id'         => $d->product_id,
                'product_name'       => $d->product?->product_name,
                'product_code'       => $d->product?->product_code,
                'serial_number'      => $d->serial_number,
                'quantity_issued'    => $d->quantity_issued,
                'quantity_in_pieces' => $d->quantity_in_pieces,
                'quantity_unit'      => $d->quantity_unit,
                'line_cost'          => $d->line_cost,
            ])->values()->all(),
        ];
    }
}
```

- [ ] **Step 4: Register routes**

In `back-end/routes/api.php`, immediately after the Warranty Management block (after the `markClaimed` line ≈ 427), add:

```php
    /*
    |--------------------------------------------------------------------------
    | Warranty Claims (repair / replacement)
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin,branch_manager,warehouse_personnel')
         ->prefix('warranty-claims')
         ->group(function () {
             Route::get('/',                    [\App\Http\Controllers\Api\WarrantyClaimController::class, 'index']);
             Route::post('/init',               [\App\Http\Controllers\Api\WarrantyClaimController::class, 'init']);
             Route::get('/{claimId}',           [\App\Http\Controllers\Api\WarrantyClaimController::class, 'show']);
             Route::post('/{claimId}/item',     [\App\Http\Controllers\Api\WarrantyClaimController::class, 'addItem']);
             Route::delete('/{claimId}/item/{detailId}', [\App\Http\Controllers\Api\WarrantyClaimController::class, 'removeItem']);
             Route::post('/{claimId}/complete', [\App\Http\Controllers\Api\WarrantyClaimController::class, 'complete']);
         });
```

> Note: place the static `/init` route before `/{claimId}` (as above) so it isn't captured as an id.

- [ ] **Step 5: Run the HTTP tests — expect pass**

```bash
php artisan test --filter="WarrantyClaimTest::test_http_full_repair_flow"
php artisan test --filter="WarrantyClaimTest::test_http_init_rejects_expired_with_422"
```

Expected: PASS. (If auth fails with 401, confirm the test DB has a user and `actingUser()` uses the `sanctum` guard.)

- [ ] **Step 6: Commit**

```bash
git commit -am "feat: WarrantyClaimController + routes (init/addItem/removeItem/complete)"
```

---

## Task 10: Claim slip (CSV) endpoint

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/WarrantyClaimController.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/WarrantyClaimTest.php`

- [ ] **Step 1: Write a failing test**

```php
    public function test_claim_slip_downloads_csv(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $locationId = $appliance['location']->location_id;
        $part = $this->makeConsumable($locationId, 10);

        $init = $this->postJson('/api/warranty-claims/init', [
            'customer_id'   => $customer->customer_id,
            'serial_number' => $appliance['serial'],
            'claim_type'    => 'repair',
            'location_id'   => $locationId,
        ])->json();
        $claimId = $init['data']['claim_id'] ?? $init['claim_id'];
        $this->postJson("/api/warranty-claims/{$claimId}/item", ['barcode' => $part->barcode ?: $part->product_code, 'quantity' => 1]);
        $this->postJson("/api/warranty-claims/{$claimId}/complete");

        $res = $this->get("/api/warranty-claims/{$claimId}/slip");
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
    }
```

- [ ] **Step 2: Run — expect failure**

```bash
php artisan test --filter="WarrantyClaimTest::test_claim_slip_downloads_csv"
```

Expected: FAIL (404).

- [ ] **Step 3: Add the `downloadSlip` method**

Add to `WarrantyClaimController`:

```php
    public function downloadSlip(int $claimId)
    {
        $claim = WarrantyClaim::with([
            'details.product', 'customer', 'location', 'status', 'warranty',
        ])->find($claimId);

        if (! $claim) {
            return $this->notFound('Claim not found');
        }

        $filename = "{$claim->claim_number}.csv";
        $headers  = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($claim) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['WARRANTY CLAIM SLIP']);
            fputcsv($h, ['Claim Number', $claim->claim_number]);
            fputcsv($h, ['Type',         ucfirst($claim->claim_type)]);
            fputcsv($h, ['Status',       $claim->status?->status_name ?? '']);
            fputcsv($h, ['Date',         $claim->completed_at?->toDateString() ?? $claim->created_at?->toDateString()]);
            fputcsv($h, ['Location',     $claim->location?->location_name ?? '']);
            fputcsv($h, []);
            fputcsv($h, ['Customer',      $claim->customer?->customer_name ?? '']);
            fputcsv($h, ['Contact',       $claim->customer?->contact_number ?? '']);
            fputcsv($h, ['Claimed Serial',$claim->serial_number ?? '']);
            fputcsv($h, ['Problem/Notes', $claim->notes ?? '']);
            fputcsv($h, []);
            fputcsv($h, ['#', 'Product Code', 'Product Name', 'Serial', 'Qty', 'Unit', 'Cost']);
            foreach ($claim->details as $i => $d) {
                fputcsv($h, [
                    $i + 1,
                    $d->product?->product_code ?? '',
                    $d->product?->product_name ?? '',
                    $d->serial_number ?? '',
                    $d->quantity_issued,
                    $d->quantity_unit ?? '',
                    number_format($d->line_cost, 2),
                ]);
            }
            fputcsv($h, []);
            fputcsv($h, ['Total Parts Cost (internal)', number_format($claim->parts_cost, 2)]);
            fclose($h);
        };

        return response()->stream($callback, 200, $headers);
    }
```

- [ ] **Step 4: Add the route**

In the `warranty-claims` group, add:

```php
             Route::get('/{claimId}/slip', [\App\Http\Controllers\Api\WarrantyClaimController::class, 'downloadSlip']);
```

- [ ] **Step 5: Run — expect pass**

```bash
php artisan test --filter="WarrantyClaimTest::test_claim_slip_downloads_csv"
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git commit -am "feat: warranty claim slip CSV download"
```

---

## Task 11: Customer history includes claims

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/CustomerController.php`
- Test: `back-end/tests/Feature/WarrantyClaimTest.php`

- [ ] **Step 1: Write a failing test**

```php
    public function test_customer_history_includes_claims(): void
    {
        $this->actingUser();
        $customer  = $this->makeCustomer();
        $appliance = $this->makeAppliance($customer);
        $locationId = $appliance['location']->location_id;
        $part = $this->makeConsumable($locationId, 10);

        $init = $this->postJson('/api/warranty-claims/init', [
            'customer_id'   => $customer->customer_id,
            'serial_number' => $appliance['serial'],
            'claim_type'    => 'repair',
            'location_id'   => $locationId,
        ])->json();
        $claimId = $init['data']['claim_id'] ?? $init['claim_id'];
        $this->postJson("/api/warranty-claims/{$claimId}/item", ['barcode' => $part->barcode ?: $part->product_code, 'quantity' => 1]);
        $this->postJson("/api/warranty-claims/{$claimId}/complete");

        $res = $this->getJson("/api/customers/{$customer->customer_id}/history")->json();
        $data = $res['data'] ?? $res;
        $this->assertArrayHasKey('warranty_claims', $data);
        $this->assertNotEmpty($data['warranty_claims']);
        $this->assertSame('repair', $data['warranty_claims'][0]['claim_type']);
    }
```

- [ ] **Step 2: Run — expect failure**

```bash
php artisan test --filter="WarrantyClaimTest::test_customer_history_includes_claims"
```

Expected: FAIL ("array key 'warranty_claims' not found").

- [ ] **Step 3: Add claims to `CustomerController::history`**

In `back-end/app/Http/Controllers/Api/CustomerController.php`, inside `history()`, after the `$activeWarranties = …->map(...)` block and before the final `return $this->success([...])`, add:

```php
        $claims = \App\Models\WarrantyClaim::with(['details.product', 'status'])
            ->where('customer_id', $id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($c) => [
                'claim_id'      => $c->claim_id,
                'claim_number'  => $c->claim_number,
                'claim_type'    => $c->claim_type,
                'serial_number' => $c->serial_number,
                'status_name'   => $c->status?->status_name,
                'notes'         => $c->notes,
                'parts_cost'    => $c->parts_cost,
                'completed_at'  => $c->completed_at?->toDateString(),
                'items'         => $c->details->map(fn ($d) => [
                    'product_name'   => $d->product?->product_name,
                    'serial_number'  => $d->serial_number,
                    'quantity'       => $d->quantity_issued,
                    'quantity_unit'  => $d->quantity_unit,
                ])->values(),
            ]);
```

Then add `'warranty_claims' => $claims,` and `'total_claims' => $claims->count(),` to the array passed to `$this->success([...])`.

- [ ] **Step 4: Add claim count to `index()` and `show()`**

In `CustomerController::index()` and `show()`, change `->withCount(['issuances', 'warrantyRecords'])` to:

```php
->withCount(['issuances', 'warrantyRecords', 'warrantyClaims'])
```

- [ ] **Step 5: Run — expect pass**

```bash
php artisan test --filter="WarrantyClaimTest::test_customer_history_includes_claims"
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git commit -am "feat: surface warranty claims in customer history and counts"
```

---

## Task 12: Retire `issuance_type = 'warranty'`

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/IssuanceScanController.php`
- Modify: `frontend/src/admin/pages/IssuanceScanPage.jsx`
- Test: `back-end/tests/Feature/WarrantyClaimTest.php`

Replacements now happen only through claims; the issuance scan flow must no longer accept the `warranty` type.

- [ ] **Step 1: Write a failing test**

```php
    public function test_issuance_scan_rejects_warranty_type(): void
    {
        $this->actingUser();
        $customer = $this->makeCustomer();

        $this->postJson('/api/issuance-scan/init', [
            'customer_id'   => $customer->customer_id,
            'issuance_type' => 'warranty',
        ])->assertStatus(422);
    }
```

- [ ] **Step 2: Run — expect failure (warranty currently accepted)**

```bash
php artisan test --filter="WarrantyClaimTest::test_issuance_scan_rejects_warranty_type"
```

Expected: FAIL (returns 201, not 422).

- [ ] **Step 3: Update validation in `IssuanceScanController::initSession`**

Change the `issuance_type` rule from:

```php
                'issuance_type'    => ['required', Rule::in(['business', 'individual', 'warranty'])],
```

to:

```php
                'issuance_type'    => ['required', Rule::in(['business', 'individual'])],
```

Then delete the now-dead `warranty` branch in `initSession` (the `elseif ($validated['issuance_type'] === 'warranty')` block that sets `discountPercent = 100`).

- [ ] **Step 4: Remove the warranty option from the issuance UI**

In `frontend/src/admin/pages/IssuanceScanPage.jsx`:
- Remove the `<option value="warranty">Warranty (free replacement)</option>` line (≈ 1153).
- Remove the `form.issuance_type === 'warranty'` info-banner block (≈ 1200–1204).
- In the `onChange` at ≈ 1149, simplify to `discount_percent: 0` (drop the `e.target.value === 'warranty' ? 100 :` ternary).

- [ ] **Step 5: Run — expect pass**

```bash
php artisan test --filter="WarrantyClaimTest::test_issuance_scan_rejects_warranty_type"
```

Expected: PASS.

- [ ] **Step 6: Run the full backend suite**

```bash
php artisan test
```

Expected: all green (ExampleTest + WarrantyClaimTest).

- [ ] **Step 7: Commit**

```bash
git commit -am "refactor: retire warranty-type issuance; replacements go through claims"
```

---

## Task 13: Frontend API service

**Files:**
- Modify: `frontend/src/admin/services/api.js`

- [ ] **Step 1: Add `warrantyClaimsAPI`**

After the `warrantiesAPI` block (≈ line 807), add:

```js
/* ======================
   WARRANTY CLAIMS API
====================== */
export const warrantyClaimsAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/warranty-claims?${query}` : '/warranty-claims');
  },
  getById: async (id) => apiRequest(`/warranty-claims/${id}`),
  init: async (data) =>
    apiRequest('/warranty-claims/init', { method: 'POST', body: JSON.stringify(data) }),
  addItem: async (id, data) =>
    apiRequest(`/warranty-claims/${id}/item`, { method: 'POST', body: JSON.stringify(data) }),
  removeItem: async (id, detailId) =>
    apiRequest(`/warranty-claims/${id}/item/${detailId}`, { method: 'DELETE' }),
  complete: async (id) =>
    apiRequest(`/warranty-claims/${id}/complete`, { method: 'POST' }),
  slipUrl: (id) => `/warranty-claims/${id}/slip`,
};
```

- [ ] **Step 2: Verify the frontend builds**

```bash
cd frontend && npm run build
```

Expected: build succeeds (no import/syntax errors). Return to repo root afterward.

- [ ] **Step 3: Commit**

```bash
git commit -am "feat: warrantyClaimsAPI client"
```

---

## Task 14: Frontend — claim wizard page

**Files:**
- Create: `frontend/src/admin/pages/WarrantyClaimPage.jsx`
- Create: `frontend/src/admin/styles/warranty_claim.css`
- Modify: `frontend/src/App.jsx`
- Modify: `frontend/src/admin/components/Sidebar.jsx`

This page mirrors the structure of `IssuanceScanPage.jsx` (a two-step wizard) but simpler. Read `IssuanceScanPage.jsx` first for the scan-input and feedback patterns to match styling.

- [ ] **Step 1: Create `warranty_claim.css`**

Reuse existing tokens; minimal new file:

```css
.wc-wrap { padding: 1.25rem; max-width: 920px; margin: 0 auto; }
.wc-steps { display: flex; gap: .5rem; margin-bottom: 1.25rem; }
.wc-step { flex: 1; padding: .5rem .75rem; border-radius: 8px; background: #eef1f5; color: #687; font-weight: 600; text-align: center; }
.wc-step.active { background: #2563eb; color: #fff; }
.wc-step.done { background: #16a34a; color: #fff; }
.wc-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.25rem; margin-bottom: 1rem; }
.wc-form-group { display: flex; flex-direction: column; gap: .35rem; margin-bottom: .9rem; }
.wc-form-group label { font-weight: 600; font-size: .85rem; }
.wc-form-group input, .wc-form-group select, .wc-form-group textarea { padding: .55rem .7rem; border: 1px solid #cbd5e1; border-radius: 8px; }
.wc-row { display: flex; gap: .75rem; }
.wc-row > * { flex: 1; }
.wc-btn-primary { background: #2563eb; color: #fff; border: 0; padding: .6rem 1.1rem; border-radius: 8px; font-weight: 600; cursor: pointer; }
.wc-btn-primary:disabled { opacity: .6; cursor: not-allowed; }
.wc-btn-secondary { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; padding: .6rem 1.1rem; border-radius: 8px; cursor: pointer; }
.wc-item-row { display: flex; justify-content: space-between; align-items: center; padding: .6rem .25rem; border-bottom: 1px solid #f1f5f9; }
.wc-error { color: #b91c1c; font-size: .85rem; }
.wc-banner { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; padding: .6rem .8rem; border-radius: 8px; font-size: .85rem; }
```

- [ ] **Step 2: Create `WarrantyClaimPage.jsx`**

```jsx
import React, { useState, useRef, useEffect } from 'react';
import AdminLayout from '../components/AdminLayout';
import { warrantyClaimsAPI, customersAPI, getApiBaseUrl, getToken } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/warranty_claim.css';

const STEP = { FORM: 1, ITEMS: 2, DONE: 3 };

export default function WarrantyClaimPage() {
  const [step, setStep] = useState(STEP.FORM);
  const [form, setForm] = useState({
    customer_id: '', customer_label: '', serial_number: '',
    claim_type: 'repair', notes: '', location_id: '',
  });
  const [customerResults, setCustomerResults] = useState([]);
  const [claim, setClaim] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [scanValue, setScanValue] = useState('');
  const [qty, setQty] = useState(1);
  const scanRef = useRef(null);

  // Read ?serial= from the URL (deep-link from Warranty Management).
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const s = params.get('serial');
    if (s) setForm((f) => ({ ...f, serial_number: s }));
  }, []);

  const searchCustomers = async (q) => {
    setForm((f) => ({ ...f, customer_label: q, customer_id: '' }));
    if (q.trim().length < 2) { setCustomerResults([]); return; }
    try {
      const res = await customersAPI.search(q);
      setCustomerResults(Array.isArray(res?.data) ? res.data : []);
    } catch { setCustomerResults([]); }
  };

  const pickCustomer = (c) => {
    setForm((f) => ({ ...f, customer_id: c.customer_id, customer_label: c.customer_name }));
    setCustomerResults([]);
  };

  const startClaim = async (e) => {
    e.preventDefault();
    setError('');
    if (!form.customer_id) { setError('Select a customer.'); return; }
    if (!form.serial_number.trim()) { setError('Enter or scan the serial number being claimed.'); return; }
    setBusy(true);
    try {
      const res = await warrantyClaimsAPI.init({
        customer_id: form.customer_id,
        serial_number: form.serial_number.trim(),
        claim_type: form.claim_type,
        notes: form.notes || null,
        location_id: form.location_id || undefined,
      });
      setClaim(res?.data ?? res);
      setStep(STEP.ITEMS);
    } catch (err) {
      setError(err.message || 'Could not start the claim.');
    } finally { setBusy(false); }
  };

  const addItem = async () => {
    if (!scanValue.trim()) return;
    setBusy(true); setError('');
    try {
      const res = await warrantyClaimsAPI.addItem(claim.claim_id, {
        barcode: scanValue.trim(),
        quantity: form.claim_type === 'repair' ? Number(qty) || 1 : undefined,
      });
      setClaim(res?.data ?? res);
      setScanValue(''); setQty(1);
      scanRef.current?.focus();
    } catch (err) {
      setError(err.message || 'Could not add the item.');
    } finally { setBusy(false); }
  };

  const removeItem = async (detailId) => {
    setBusy(true);
    try {
      const res = await warrantyClaimsAPI.removeItem(claim.claim_id, detailId);
      setClaim(res?.data ?? res);
    } catch (err) { toast.error(err.message || 'Could not remove item.'); }
    finally { setBusy(false); }
  };

  const complete = async () => {
    setBusy(true); setError('');
    try {
      const res = await warrantyClaimsAPI.complete(claim.claim_id);
      setClaim(res?.data ?? res);
      setStep(STEP.DONE);
      toast.success('Warranty claim completed.');
    } catch (err) {
      setError(err.message || 'Could not complete the claim.');
    } finally { setBusy(false); }
  };

  const downloadSlip = () => {
    const url = `${getApiBaseUrl()}${warrantyClaimsAPI.slipUrl(claim.claim_id)}`;
    fetch(url, { headers: { Authorization: `Bearer ${getToken()}` } })
      .then((r) => r.blob())
      .then((b) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(b);
        a.download = `${claim.claim_number}.csv`;
        a.click();
      });
  };

  const items = claim?.items ?? [];

  return (
    <AdminLayout>
      <div className="wc-wrap">
        <h1>Warranty Claim</h1>
        <div className="wc-steps">
          <div className={`wc-step ${step === STEP.FORM ? 'active' : 'done'}`}>1. Claim Details</div>
          <div className={`wc-step ${step === STEP.ITEMS ? 'active' : step > STEP.ITEMS ? 'done' : ''}`}>2. Items</div>
          <div className={`wc-step ${step === STEP.DONE ? 'active' : ''}`}>3. Done</div>
        </div>

        {step === STEP.FORM && (
          <form className="wc-card" onSubmit={startClaim}>
            <div className="wc-form-group" style={{ position: 'relative' }}>
              <label>Customer *</label>
              <input value={form.customer_label} onChange={(e) => searchCustomers(e.target.value)} placeholder="Search customer…" />
              {customerResults.length > 0 && (
                <div className="wc-card" style={{ position: 'absolute', top: '100%', zIndex: 5, width: '100%' }}>
                  {customerResults.map((c) => (
                    <div key={c.customer_id} className="wc-item-row" style={{ cursor: 'pointer' }} onClick={() => pickCustomer(c)}>
                      {c.customer_name} <small>{c.customer_code}</small>
                    </div>
                  ))}
                </div>
              )}
            </div>
            <div className="wc-form-group">
              <label>Serial Number (scan or type) *</label>
              <input value={form.serial_number} onChange={(e) => setForm((f) => ({ ...f, serial_number: e.target.value }))} placeholder="Scan or type the claimed unit serial" />
            </div>
            <div className="wc-row">
              <div className="wc-form-group">
                <label>Claim Type *</label>
                <select value={form.claim_type} onChange={(e) => setForm((f) => ({ ...f, claim_type: e.target.value }))}>
                  <option value="repair">Repair (use spare parts)</option>
                  <option value="replacement">Replacement (give a new unit)</option>
                </select>
              </div>
            </div>
            <div className="wc-form-group">
              <label>Notes / Problem</label>
              <textarea rows={2} value={form.notes} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} />
            </div>
            {error && <div className="wc-error">{error}</div>}
            <button className="wc-btn-primary" disabled={busy}>{busy ? 'Validating…' : 'Start Claim'}</button>
          </form>
        )}

        {step === STEP.ITEMS && claim && (
          <>
            <div className="wc-card">
              <div className="wc-banner">
                Claim {claim.claim_number} · {claim.claim_type} · serial {claim.serial_number}
                {claim.claim_type === 'replacement' ? ' · scan ONE replacement unit serial' : ' · scan consumable parts'}
              </div>
              <div className="wc-row" style={{ marginTop: '.8rem' }}>
                <div className="wc-form-group" style={{ flex: 3 }}>
                  <label>{claim.claim_type === 'replacement' ? 'Replacement unit serial' : 'Part barcode'}</label>
                  <input ref={scanRef} value={scanValue} autoFocus
                    onChange={(e) => setScanValue(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addItem(); } }} />
                </div>
                {claim.claim_type === 'repair' && (
                  <div className="wc-form-group">
                    <label>Qty</label>
                    <input type="number" min="1" value={qty} onChange={(e) => setQty(e.target.value)} />
                  </div>
                )}
              </div>
              <button className="wc-btn-secondary" onClick={addItem} disabled={busy || !scanValue.trim()}>Add</button>
              {error && <div className="wc-error" style={{ marginTop: '.5rem' }}>{error}</div>}
            </div>

            <div className="wc-card">
              <h3>Items ({items.length})</h3>
              {items.length === 0 && <p>No items yet.</p>}
              {items.map((it) => (
                <div key={it.detail_id} className="wc-item-row">
                  <span>{it.product_name} {it.serial_number ? `· ${it.serial_number}` : `· ${it.quantity_issued} ${it.quantity_unit || ''}`}</span>
                  <button className="wc-btn-secondary" onClick={() => removeItem(it.detail_id)} disabled={busy}>Remove</button>
                </div>
              ))}
              <div style={{ marginTop: '1rem', display: 'flex', gap: '.5rem' }}>
                <button className="wc-btn-primary" onClick={complete} disabled={busy || items.length === 0}>Complete Claim</button>
              </div>
            </div>
          </>
        )}

        {step === STEP.DONE && claim && (
          <div className="wc-card">
            <h2>Claim {claim.claim_number} completed</h2>
            <p>{claim.claim_type === 'replacement'
              ? 'Original warranty marked Claimed; the replacement carries the original end date.'
              : 'Original warranty marked Claimed; repair parts deducted from inventory.'}</p>
            <button className="wc-btn-primary" onClick={downloadSlip}>Download Claim Slip</button>
            <button className="wc-btn-secondary" style={{ marginLeft: '.5rem' }} onClick={() => window.location.reload()}>New Claim</button>
          </div>
        )}
      </div>
    </AdminLayout>
  );
}
```

- [ ] **Step 3: Register the route in `App.jsx`**

Add the lazy import near the others (≈ line 64):

```jsx
const WarrantyClaimPage = lazy(() => import('./admin/pages/WarrantyClaimPage'));
```

Add the route near the other warranty routes (≈ line 162):

```jsx
          <Route path="/admin/warranty-claims" element={<RoleRoute><WarrantyClaimPage /></RoleRoute>} />
```

- [ ] **Step 4: Add the Sidebar dynamic import**

In `frontend/src/admin/components/Sidebar.jsx`, add to the import map (near line 48):

```js
  '/admin/warranty-claims': () => import('../pages/WarrantyClaimPage'),
```

If the sidebar has a visible nav list, add a "Warranty Claims" entry pointing at `/admin/warranty-claims` next to "Warranties" following the existing nav-item pattern in that file.

- [ ] **Step 5: Build the frontend**

```bash
cd frontend && npm run build
```

Expected: succeeds. Return to repo root.

- [ ] **Step 6: Commit**

```bash
git commit -am "feat: warranty claim wizard page + route"
```

---

## Task 15: Warranty Management entry point

**Files:**
- Modify: `frontend/src/admin/pages/WarrantyManagement.jsx`

The existing simple "Mark Claimed" modal is superseded by the new wizard (which records parts and marks the warranty Claimed). Replace the claim action with navigation to the wizard, pre-filling the serial.

- [ ] **Step 1: Replace the claim action**

In `WarrantyManagement.jsx`, change `openClaimModal` (≈ line 107) to navigate instead of opening the notes modal:

```jsx
  const openClaimModal = (warrantyId, productName, serialNumber) => {
    const serial = encodeURIComponent(serialNumber || '');
    window.location.href = `/admin/warranty-claims${serial ? `?serial=${serial}` : ''}`;
  };
```

Update each call site of `openClaimModal(...)` to pass the row's `serial_number` as the third argument (search the file for `openClaimModal(` and add `, w.serial_number` / the appropriate serial variable in scope).

- [ ] **Step 2: Remove the now-unused notes modal (optional cleanup)**

If the claim-notes modal (`claimTarget`, `submitClaim`, `claimNotes` state and its JSX) is no longer referenced, remove it to avoid dead code. Leave it if other code paths still use `markClaimed`.

- [ ] **Step 3: Build**

```bash
cd frontend && npm run build
```

Expected: succeeds. Return to repo root.

- [ ] **Step 4: Manual verification (running app)**

Start XAMPP MySQL + Apache and the frontend dev server, then:
1. Go to **Warranties**, find an Active warranty, click **Claim** → lands on the wizard with the serial pre-filled.
2. Pick the customer, choose **Repair**, Start Claim → scan a consumable barcode + qty → Complete → download slip.
3. Confirm the warranty now shows **Claimed**, and the consumable's stock dropped.
4. Repeat with **Replacement** on another Active warranty: scan an in-stock unit serial → Complete. Confirm the original is **Claimed** and a new Active warranty exists on the replacement serial with the same end date.
5. Try claiming an **Expired** warranty → blocked with a clear message.

- [ ] **Step 5: Commit**

```bash
git commit -am "feat: launch warranty claim wizard from Warranty Management"
```

---

## Task 16: Final verification

- [ ] **Step 1: Full backend test suite**

```bash
php artisan test
```

Expected: all green.

- [ ] **Step 2: Frontend build**

```bash
cd frontend && npm run build
```

Expected: succeeds.

- [ ] **Step 3: Confirm no stray references to the retired warranty issuance type**

```bash
grep -rn "issuance_type.*warranty\|'warranty'" back-end/app frontend/src/admin/pages/IssuanceScanPage.jsx
```

Expected: no remaining `warranty` issuance-type usages (warranty *records*/*claims* references are fine).

- [ ] **Step 4: Commit any cleanup**

```bash
git commit -am "chore: warranty claim module final verification"
```

---

## Self-review notes (for the implementer)

- **Status IDs:** warranty 33/34/35 and serial 26 are hardcoded to match the live DB convention (see existing controllers). Claim statuses are resolved by name to avoid auto-increment fragility.
- **Test DB caveat:** feature tests require `airkingV5_test` cloned from the live DB (Task 1) because warranty statuses aren't seeded by migrations. Re-clone if the live schema changes.
- **Replacement re-claim rule:** the new warranty on a replacement is Active (claimable again) and inherits the original end date — this is intentional per the client (spec §2).
- **Scope:** the internal-use vs customer-scan issuance overlap is deliberately untouched (issuance is "OK" per the client). Only the `warranty` issuance type is retired here.
