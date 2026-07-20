# Warehouse Zone Map Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let staff record which physical shelf/zone a PO batch was placed in at receiving time, edit that label later, and see a card-based "Warehouse Map" showing every batch with remaining stock, with a "Pick this first (FIFO)" badge on the oldest batch per product.

**Architecture:** Add one nullable `zone_label` column to the existing `inventory_batches` table (a batch already is the physical "section" per the client's enrollment analogy — no new table). A new `InventoryBatchController` exposes a location-scoped list endpoint (reusing the existing `ScopesToUserLocation` trait, so it needs no client-supplied `location_id` — same auto-scoping convention as every other inventory endpoint) and a `PATCH` endpoint to edit the label. The existing receiving flow gains one optional field per line. The existing Inventory Management page gains a fourth tab.

**Tech Stack:** Laravel (PHP), MySQL/MariaDB (SQL-dump-based schema, not `migrate`-built), React (CRA), PHPUnit (`DatabaseTransactions` against `airkingV5_test`).

**Spec:** `docs/superpowers/specs/2026-07-21-warehouse-zone-map-design.md`

**Note on this branch:** this plan builds on the already-implemented PO/location-batched-inventory-with-FIFO feature (13 prior tasks, same worktree/branch). All file paths and line references below reflect that feature's current, already-reviewed state.

---

### Task 1: Migration — add `zone_label` to `inventory_batches`

**Files:**
- Create: `back-end/database/migrations/2026_07_21_000003_add_zone_label_to_inventory_batches_table.php`

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
        if (Schema::hasColumn('inventory_batches', 'zone_label')) {
            return;
        }

        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->string('zone_label')->nullable()->after('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->dropColumn('zone_label');
        });
    }
};
```

- [ ] **Step 2: Apply to the dev DB and the test DB**

From `back-end/` (bash):

```bash
php artisan migrate --path=database/migrations/2026_07_21_000003_add_zone_label_to_inventory_batches_table.php
DB_DATABASE=airkingV5_test php artisan migrate --path=database/migrations/2026_07_21_000003_add_zone_label_to_inventory_batches_table.php
```

Expected: both report `Migrated: 2026_07_21_000003_add_zone_label_to_inventory_batches_table` with no errors.

- [ ] **Step 3: Write a test confirming the column exists and round-trips**

**Test:** `back-end/tests/Feature/Inventory/InventoryBatchZoneLabelColumnTest.php`

```php
<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;

class InventoryBatchZoneLabelColumnTest extends TestCase
{
    use DatabaseTransactions;

    public function test_zone_label_column_exists_and_round_trips(): void
    {
        $this->assertTrue(Schema::hasColumn('inventory_batches', 'zone_label'));

        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Consumable', 'product_type' => 'installation_material',
        ]);

        $batch = InventoryBatch::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'receiving_id' => null, 'quantity_received' => 5, 'quantity_remaining' => 5,
            'received_at' => now(), 'zone_label' => 'Aisle 3, Shelf B',
        ]);

        $this->assertSame('Aisle 3, Shelf B', $batch->fresh()->zone_label);
    }
}
```

- [ ] **Step 4: Run the test to verify it fails, then passes**

Run: `cd back-end && php artisan test tests/Feature/Inventory/InventoryBatchZoneLabelColumnTest.php`

Before Step 2/the model change (Task 2) it will fail with a mass-assignment/unknown-column error (`zone_label` isn't in `InventoryBatch::$fillable` yet, and if the migration hasn't been applied, the column itself won't exist). After Step 2 (migration applied) and Task 2 (fillable updated), it should PASS.

> Since this task's migration and Task 2's model change are both needed for the test to pass, run this test again after completing Task 2 to get a green result — don't block Task 1 on it going green in isolation, just confirm the migration itself applied cleanly per Step 2's output.

- [ ] **Step 5: Commit**

```bash
git add back-end/database/migrations/2026_07_21_000003_add_zone_label_to_inventory_batches_table.php back-end/tests/Feature/Inventory/InventoryBatchZoneLabelColumnTest.php
git commit -m "feat(db): add zone_label to inventory_batches"
```

---

### Task 2: `InventoryBatch` model — fillable + location scoping

**Files:**
- Modify: `back-end/app/Models/InventoryBatch.php` (full current file below)

- [ ] **Step 1: Update the model**

Current file:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;

class InventoryBatch extends Model
{
    use Auditable;

    protected $table = 'inventory_batches';

    protected $primaryKey = 'batch_id';

    protected $fillable = [
        'product_id',
        'location_id',
        'receiving_id',
        'quantity_received',
        'quantity_remaining',
        'received_at',
    ];

    protected $casts = [
        'quantity_received'  => 'integer',
        'quantity_remaining' => 'integer',
        'received_at'        => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function receiving(): BelongsTo
    {
        return $this->belongsTo(Receiving::class, 'receiving_id', 'receiving_id');
    }
}
```

Change to:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;
use App\Models\Concerns\ScopesToUserLocation;

class InventoryBatch extends Model
{
    use Auditable, ScopesToUserLocation;

    protected $table = 'inventory_batches';

    protected $primaryKey = 'batch_id';

    protected $fillable = [
        'product_id',
        'location_id',
        'receiving_id',
        'quantity_received',
        'quantity_remaining',
        'received_at',
        'zone_label',
    ];

    protected $casts = [
        'quantity_received'  => 'integer',
        'quantity_remaining' => 'integer',
        'received_at'        => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function receiving(): BelongsTo
    {
        return $this->belongsTo(Receiving::class, 'receiving_id', 'receiving_id');
    }
}
```

(`ScopesToUserLocation` — `back-end/app/Models/Concerns/ScopesToUserLocation.php` — adds a `scopeForUser(Builder $query, User $user)` local scope: centralized users see everything, location-scoped users see only their own `location_id`, scoped users with no assigned location get zero rows. `InventoryBatch` already has a `location_id` column, so this trait works immediately with no further changes. This is the exact same trait already used by `Inventory` and `Receiving`.)

- [ ] **Step 2: Run the Task 1 test again to confirm it now passes**

Run: `cd back-end && php artisan test tests/Feature/Inventory/InventoryBatchZoneLabelColumnTest.php`
Expected: PASS.

- [ ] **Step 3: Run the existing InventoryBatchService test suite to confirm no regressions**

Run: `cd back-end && php artisan test tests/Feature/Inventory tests/Feature/Transfer tests/Feature/Issuance`
Expected: all still passing (this only adds a trait and a fillable field; nothing existing should break).

- [ ] **Step 4: Commit**

```bash
git add back-end/app/Models/InventoryBatch.php
git commit -m "feat(models): add zone_label to InventoryBatch, scope to user location"
```

---

### Task 3: `InventoryBatchService::createBatch()` — accept an optional zone label

**Files:**
- Modify: `back-end/app/Services/InventoryBatchService.php` (the `createBatch()` method only)
- Test: append to `back-end/tests/Feature/Inventory/InventoryBatchServiceTest.php`

- [ ] **Step 1: Write the failing test**

Append to `InventoryBatchServiceTest` (it already has `$this->svc`, `makeLocation()`, `makeProduct()`, `makeReceiving()` helpers):

```php
    public function test_create_batch_stores_optional_zone_label(): void
    {
        $loc  = $this->makeLocation();
        $prod = $this->makeProduct();

        $batch = $this->svc->createBatch(
            $prod->product_id, $loc->location_id,
            $this->makeReceiving($loc->location_id)->receiving_id,
            5, now(), 'Aisle 3, Shelf B'
        );

        $this->assertSame('Aisle 3, Shelf B', $batch->fresh()->zone_label);
    }

    public function test_create_batch_zone_label_defaults_to_null(): void
    {
        $loc  = $this->makeLocation();
        $prod = $this->makeProduct();

        $batch = $this->svc->createBatch(
            $prod->product_id, $loc->location_id,
            $this->makeReceiving($loc->location_id)->receiving_id,
            5, now()
        );

        $this->assertNull($batch->fresh()->zone_label);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test tests/Feature/Inventory/InventoryBatchServiceTest.php --filter=zone_label`
Expected: FAIL — `createBatch()` doesn't accept a 6th argument yet (`Too many arguments to function`).

- [ ] **Step 3: Update `createBatch()`**

Current method:

```php
    public function createBatch(int $productId, int $locationId, ?int $receivingId, int $qty, $receivedAt): InventoryBatch
    {
        return InventoryBatch::create([
            'product_id'         => $productId,
            'location_id'        => $locationId,
            'receiving_id'       => $receivingId,
            'quantity_received'  => $qty,
            'quantity_remaining' => $qty,
            'received_at'        => $receivedAt,
        ]);
    }
```

Change to:

```php
    public function createBatch(int $productId, int $locationId, ?int $receivingId, int $qty, $receivedAt, ?string $zoneLabel = null): InventoryBatch
    {
        return InventoryBatch::create([
            'product_id'         => $productId,
            'location_id'        => $locationId,
            'receiving_id'       => $receivingId,
            'quantity_received'  => $qty,
            'quantity_remaining' => $qty,
            'received_at'        => $receivedAt,
            'zone_label'         => $zoneLabel,
        ]);
    }
```

`createBatch(` has exactly one call site in the whole backend — `back-end/app/Http/Controllers/Api/ReceivingController.php` (Task 4 updates it) — so no other caller needs changes. The new parameter is optional and trailing, so this is fully backward compatible.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test tests/Feature/Inventory/InventoryBatchServiceTest.php`
Expected: PASS (all tests in the file, including the 2 new ones).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/InventoryBatchService.php back-end/tests/Feature/Inventory/InventoryBatchServiceTest.php
git commit -m "feat(inventory): accept an optional zone label in InventoryBatchService::createBatch"
```

---

### Task 4: `ReceivingController::store()` — accept `zone_label` per line

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/ReceivingController.php` (validation rules + the per-line loop)
- Test: `back-end/tests/Feature/Inventory/ReceivingStoresZoneLabelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;

class ReceivingStoresZoneLabelTest extends TestCase
{
    use DatabaseTransactions;

    public function test_store_saves_zone_label_onto_the_created_batch(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Consumable', 'product_type' => 'installation_material',
        ]);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 1,
        ]);

        $this->actingAs($user)->postJson('/api/receivings', [
            'pc_id' => $po->po_id,
            'location_id' => $location->location_id,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date' => now()->toDateString(),
            'details' => [
                ['product_id' => $product->product_id, 'quantity_amount' => 10, 'condition' => 'Good', 'zone_label' => 'Aisle 3, Shelf B'],
            ],
        ])->assertStatus(201);

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->first();

        $this->assertSame('Aisle 3, Shelf B', $batch->zone_label);
    }

    public function test_store_allows_omitting_zone_label(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Consumable', 'product_type' => 'installation_material',
        ]);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 1,
        ]);

        $this->actingAs($user)->postJson('/api/receivings', [
            'pc_id' => $po->po_id,
            'location_id' => $location->location_id,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date' => now()->toDateString(),
            'details' => [
                ['product_id' => $product->product_id, 'quantity_amount' => 10, 'condition' => 'Good'],
            ],
        ])->assertStatus(201);

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->first();

        $this->assertNull($batch->zone_label);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test tests/Feature/Inventory/ReceivingStoresZoneLabelTest.php`
Expected: the first test FAILS (`zone_label` is null on the created batch, since `store()` doesn't read/pass it yet). The second test should already PASS (omitting it is already fine today) — that's expected and fine, it's here to guard the "stays optional" behavior going forward.

- [ ] **Step 3: Add the validation rule and pass the value through**

In `back-end/app/Http/Controllers/Api/ReceivingController.php`, in the `$request->validate([...])` array (current lines 55–66), add a new rule right after `'details.*.scanned_barcode' => 'nullable|string|max:191',`:

```php
            'details.*.zone_label'           => 'nullable|string|max:191',
```

In the per-line loop, current code (lines 94–130):

```php
        foreach ($validated['details'] as $detail) {
            $productId = (int) $detail['product_id'];
            $qty       = (int) ($detail['quantity_amount'] ?? 0);
            $scannedBarcode = $detail['scanned_barcode'] ?? null;
```

change to:

```php
        foreach ($validated['details'] as $detail) {
            $productId = (int) $detail['product_id'];
            $qty       = (int) ($detail['quantity_amount'] ?? 0);
            $scannedBarcode = $detail['scanned_barcode'] ?? null;
            $zoneLabel = $detail['zone_label'] ?? null;
```

Then find the `createBatch()` call further down in the same loop:

```php
            app(InventoryBatchService::class)->createBatch(
                $productId,
                $locationId,
                $receiving->receiving_id,
                $qty,
                $receiving->receiving_date ?? now()
            );
```

change to:

```php
            app(InventoryBatchService::class)->createBatch(
                $productId,
                $locationId,
                $receiving->receiving_id,
                $qty,
                $receiving->receiving_date ?? now(),
                $zoneLabel
            );
```

`ReceivingDetail::create([...])` is NOT changed — per the design spec, `zone_label` lives only on `InventoryBatch`, not on `ReceivingDetail`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test tests/Feature/Inventory/ReceivingStoresZoneLabelTest.php`
Expected: PASS (both tests).

- [ ] **Step 5: Run the existing receiving test to confirm no regressions**

Run: `cd back-end && php artisan test tests/Feature/Inventory/ReceivingCreatesBatchTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/ReceivingController.php back-end/tests/Feature/Inventory/ReceivingStoresZoneLabelTest.php
git commit -m "feat(receiving): accept an optional zone_label per receiving line"
```

---

### Task 5: `InventoryBatchController` — list (Warehouse Map) + update (edit zone label) endpoints

**Files:**
- Create: `back-end/app/Http/Controllers/Api/InventoryBatchController.php`
- Modify: `back-end/routes/api.php` (add two routes)
- Test: `back-end/tests/Feature/Inventory/InventoryBatchControllerTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use App\Services\InventoryBatchService;

class InventoryBatchControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        return User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    public function test_list_returns_only_batches_with_remaining_stock_for_the_users_location(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $otherLocation = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Other Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Consumable', 'product_type' => 'installation_material',
        ]);
        $user = $this->makeUser();

        $svc = new InventoryBatchService();
        $rcv = Receiving::create(['location_id' => $location->location_id, 'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()]);
        $withStock = $svc->createBatch($product->product_id, $location->location_id, $rcv->receiving_id, 5, now(), 'Aisle 1');

        $depletedBatch = $svc->createBatch($product->product_id, $location->location_id, $rcv->receiving_id, 3, now(), 'Aisle 2');
        $depletedBatch->quantity_remaining = 0;
        $depletedBatch->save();

        $svc->createBatch($product->product_id, $otherLocation->location_id, $rcv->receiving_id, 7, now(), 'Aisle 9');

        $res = $this->actingAs($user)->getJson('/api/inventory-batches');

        $res->assertOk();
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($withStock->batch_id, $data[0]['batch_id']);
        $this->assertSame('Aisle 1', $data[0]['zone_label']);
    }

    public function test_update_changes_zone_label_without_touching_quantities(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Consumable', 'product_type' => 'installation_material',
        ]);
        $user = $this->makeUser();

        $svc = new InventoryBatchService();
        $rcv = Receiving::create(['location_id' => $location->location_id, 'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()]);
        $batch = $svc->createBatch($product->product_id, $location->location_id, $rcv->receiving_id, 5, now());

        $res = $this->actingAs($user)->patchJson("/api/inventory-batches/{$batch->batch_id}", [
            'zone_label' => 'Aisle 4, Shelf C',
        ]);

        $res->assertOk();

        $fresh = $batch->fresh();
        $this->assertSame('Aisle 4, Shelf C', $fresh->zone_label);
        $this->assertSame(5, $fresh->quantity_received);
        $this->assertSame(5, $fresh->quantity_remaining);
    }

    public function test_update_allows_clearing_zone_label(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Consumable', 'product_type' => 'installation_material',
        ]);
        $user = $this->makeUser();

        $svc = new InventoryBatchService();
        $rcv = Receiving::create(['location_id' => $location->location_id, 'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()]);
        $batch = $svc->createBatch($product->product_id, $location->location_id, $rcv->receiving_id, 5, now(), 'Aisle 1');

        $this->actingAs($user)->patchJson("/api/inventory-batches/{$batch->batch_id}", [
            'zone_label' => null,
        ])->assertOk();

        $this->assertNull($batch->fresh()->zone_label);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test tests/Feature/Inventory/InventoryBatchControllerTest.php`
Expected: FAIL — 404 on all three (neither route nor controller exists yet).

- [ ] **Step 3: Add the routes**

In `back-end/routes/api.php`, add the import near the top alongside the other controller imports (after `use App\Http\Controllers\Api\InventoryController;`):

```php
use App\Http\Controllers\Api\InventoryBatchController;
```

Then, inside the same route group, right after the existing inventory-batches drill-down route (`Route::get('/inventory/{inventory}/batches', [InventoryController::class, 'batches']);`), add:

```php
    Route::get('/inventory-batches', [InventoryBatchController::class, 'index']);
    Route::patch('/inventory-batches/{batch}', [InventoryBatchController::class, 'update']);
```

(No extra `permission:` middleware — this matches the existing unguarded convention for the sibling `/inventory/{inventory}/batches` route in this same block, inheriting only the outer group's `auth:sanctum`/`location.scope` middleware.)

- [ ] **Step 4: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryBatch;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class InventoryBatchController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/inventory-batches
     *
     * All batches with remaining stock, scoped to the authenticated user's
     * location (centralized users see every location). Powers the
     * Warehouse Map tab on the Inventory Management page.
     */
    public function index(Request $request)
    {
        $batches = InventoryBatch::forUser($request->user())
            ->where('quantity_remaining', '>', 0)
            ->with(['product', 'receiving.purchaseOrder'])
            ->orderBy('received_at', 'asc')
            ->get();

        $data = $batches->map(function ($batch) {
            return [
                'batch_id'           => $batch->batch_id,
                'product_id'         => $batch->product_id,
                'product_name'       => $batch->product?->product_name,
                'location_id'        => $batch->location_id,
                'receiving_id'       => $batch->receiving_id,
                'po_number'          => $batch->receiving?->purchaseOrder?->po_number,
                'zone_label'         => $batch->zone_label,
                'received_at'        => $batch->received_at?->toDateString(),
                'quantity_received'  => $batch->quantity_received,
                'quantity_remaining' => $batch->quantity_remaining,
                'is_legacy'          => $batch->receiving_id === null,
            ];
        });

        return $this->success($data, 'Warehouse batches retrieved successfully');
    }

    /**
     * PATCH /api/inventory-batches/{batch}
     *
     * Edits a batch's physical zone label only. Never touches quantity
     * fields — zone_label is descriptive metadata, not part of FIFO
     * deduction/restoration.
     */
    public function update(Request $request, InventoryBatch $batch)
    {
        $validated = $request->validate([
            'zone_label' => 'nullable|string|max:191',
        ]);

        $batch->zone_label = $validated['zone_label'] ?? null;
        $batch->save();

        return $this->success([
            'batch_id'   => $batch->batch_id,
            'zone_label' => $batch->zone_label,
        ], 'Zone label updated successfully');
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd back-end && php artisan test tests/Feature/Inventory/InventoryBatchControllerTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/InventoryBatchController.php back-end/routes/api.php back-end/tests/Feature/Inventory/InventoryBatchControllerTest.php
git commit -m "feat(api): add GET/PATCH /inventory-batches for the Warehouse Map tab"
```

---

### Task 6: `InventoryController::batches()` — include `zone_label` in the existing drill-down response

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/InventoryController.php` (the `batches()` method)
- Test: append to `back-end/tests/Feature/Inventory/InventoryBatchesEndpointTest.php`

- [ ] **Step 1: Write the failing assertion**

Append to the existing test method in `InventoryBatchesEndpointTest.php` — find `test_batches_endpoint_returns_batches_oldest_first_with_legacy_last`, and right before creating the two batches, pass a zone label to the first one, then assert on it. Change:

```php
        $svc->createBatch($product->product_id, $location->location_id, $rcv1->receiving_id, 5, now()->subDays(5));
        $svc->createBatch($product->product_id, $location->location_id, $rcv2->receiving_id, 10, now());
```

to:

```php
        $svc->createBatch($product->product_id, $location->location_id, $rcv1->receiving_id, 5, now()->subDays(5), 'Aisle 3, Shelf B');
        $svc->createBatch($product->product_id, $location->location_id, $rcv2->receiving_id, 10, now());
```

and add, right after the existing `$this->assertSame($rcv2->receiving_id, $data[1]['receiving_id']);` assertion:

```php
        $this->assertSame('Aisle 3, Shelf B', $data[0]['zone_label']);
        $this->assertNull($data[1]['zone_label']);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test tests/Feature/Inventory/InventoryBatchesEndpointTest.php`
Expected: FAIL — `zone_label` key missing from the response array (`Undefined array key "zone_label"`).

- [ ] **Step 3: Add `zone_label` to the response**

In `back-end/app/Http/Controllers/Api/InventoryController.php`'s `batches()` method, find:

```php
        $data = $batches->map(function ($batch) {
            return [
                'batch_id'           => $batch->batch_id,
                'receiving_id'       => $batch->receiving_id,
                'po_number'          => $batch->receiving?->purchaseOrder?->po_number,
                'received_at'        => $batch->received_at?->toDateString(),
                'quantity_received'  => $batch->quantity_received,
                'quantity_remaining' => $batch->quantity_remaining,
                'is_legacy'          => $batch->receiving_id === null,
            ];
        });
```

and add `'zone_label' => $batch->zone_label,` right after `'po_number'`:

```php
        $data = $batches->map(function ($batch) {
            return [
                'batch_id'           => $batch->batch_id,
                'receiving_id'       => $batch->receiving_id,
                'po_number'          => $batch->receiving?->purchaseOrder?->po_number,
                'zone_label'         => $batch->zone_label,
                'received_at'        => $batch->received_at?->toDateString(),
                'quantity_received'  => $batch->quantity_received,
                'quantity_remaining' => $batch->quantity_remaining,
                'is_legacy'          => $batch->receiving_id === null,
            ];
        });
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test tests/Feature/Inventory/InventoryBatchesEndpointTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/InventoryController.php back-end/tests/Feature/Inventory/InventoryBatchesEndpointTest.php
git commit -m "feat(api): include zone_label in the per-row batch drill-down response"
```

---

### Task 7: Frontend — zone label field in the Receive PO flow

**Files:**
- Modify: `frontend/src/admin/pages/BarcodeScan.jsx` (`txForm` initial state, `receivePoSetupGrid`, the receiving submit call)

- [ ] **Step 1: Add `zone_label` to `txForm`'s initial state**

Find:

```jsx
  const [txForm, setTxForm] = useState({
    quantity: 1,
    condition: 'Good',
    location_id: '',
    to_location_id: '',
    pc_id: '',
    adjustment_direction: 'deduct',
    ship_transfer_first: true,
    pending_transfer_id: '',
  });
```

change to:

```jsx
  const [txForm, setTxForm] = useState({
    quantity: 1,
    condition: 'Good',
    location_id: '',
    to_location_id: '',
    pc_id: '',
    zone_label: '',
    adjustment_direction: 'deduct',
    ship_transfer_first: true,
    pending_transfer_id: '',
  });
```

- [ ] **Step 2: Add the input to `receivePoSetupGrid`**

Find the closing of the `PURCHASE ORDER` column inside `receivePoSetupGrid`:

```jsx
      <div className="bs-filter-col">
        <label>PURCHASE ORDER (OPTIONAL)</label>
        <select
          value={txForm.pc_id}
          onChange={(e) => setTxForm((p) => ({ ...p, pc_id: e.target.value }))}
        >
          <option value="">None — receive without PO</option>
          {purchaseOrders.map((po) => {
            const pid = po.po_id ?? po.id;
            const label = po.po_number || po.pc_number || (pid != null ? `PO #${pid}` : '—');
            return (
              <option key={pid} value={pid}>{label}</option>
            );
          })}
        </select>
      </div>
    </div>
  );
```

Add a new column right before the grid's closing `</div>`:

```jsx
      <div className="bs-filter-col">
        <label>ZONE LABEL (OPTIONAL)</label>
        <input
          type="text"
          placeholder="e.g. Aisle 3, Shelf B"
          value={txForm.zone_label}
          onChange={(e) => setTxForm((p) => ({ ...p, zone_label: e.target.value }))}
          title="Where this batch is physically shelved — shown on the Warehouse Map."
        />
      </div>
    </div>
  );
```

- [ ] **Step 3: Include it in the receiving submit payload**

Find:

```jsx
          const res = await receivingsAPI.create({
            pc_id: txForm.pc_id,
            location_id: locationId,
            receiving_number: `RCV-${Date.now()}`,
            receiving_date: today,
            total_quantity_damaged: 0,
            details: [
              {
                product_id: productId,
                quantity_amount: qtyNum,
                condition: txForm.condition || 'Good',
              },
            ],
          });
```

change to:

```jsx
          const res = await receivingsAPI.create({
            pc_id: txForm.pc_id,
            location_id: locationId,
            receiving_number: `RCV-${Date.now()}`,
            receiving_date: today,
            total_quantity_damaged: 0,
            details: [
              {
                product_id: productId,
                quantity_amount: qtyNum,
                condition: txForm.condition || 'Good',
                zone_label: txForm.zone_label || null,
              },
            ],
          });
```

- [ ] **Step 4: Verify it builds**

Run: `cd frontend && npm run build 2>&1 | tail -40`
Expected: succeeds, no new errors in `BarcodeScan.jsx`.

- [ ] **Step 5: Manual verification**

Since no automated frontend test exists for this page (per this codebase's convention), verify manually: start the dev server, go to the Receive PO scan mode, confirm the new "ZONE LABEL (OPTIONAL)" field appears in the setup grid, type a value, complete a scan, and confirm (via the backend, e.g. `GET /api/inventory-batches` or checking the DB) that the created batch has that `zone_label`.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/BarcodeScan.jsx
git commit -m "feat(ui): add optional zone label field to Receive PO scan setup"
```

---

### Task 8: Frontend — API client methods for the new endpoints

**Files:**
- Modify: `frontend/src/admin/services/api.js` (`inventoryAPI` object)

- [ ] **Step 1: Add two new methods**

Find:

```js
export const inventoryAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/inventory?${query}` : '/inventory');
  },
  getById: async (id) => apiRequest(`/inventory/${id}`),
  getBatches: async (id) => apiRequest(`/inventory/${id}/batches`),
  create: async (data) =>
```

change to:

```js
export const inventoryAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/inventory?${query}` : '/inventory');
  },
  getById: async (id) => apiRequest(`/inventory/${id}`),
  getBatches: async (id) => apiRequest(`/inventory/${id}/batches`),
  /** All batches with remaining stock, scoped server-side to the user's location. Powers the Warehouse Map tab. */
  getWarehouseBatches: async () => apiRequest('/inventory-batches'),
  /** Edit a batch's physical zone label only (never touches quantities). */
  updateBatchZone: async (batchId, zoneLabel) =>
    apiRequest(`/inventory-batches/${batchId}`, {
      method: 'PATCH',
      body: JSON.stringify({ zone_label: zoneLabel }),
    }),
  create: async (data) =>
```

- [ ] **Step 2: Verify it builds**

Run: `cd frontend && npm run build 2>&1 | tail -40`
Expected: succeeds.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -m "feat(ui): add API client methods for the Warehouse Map endpoints"
```

---

### Task 9: Frontend — Warehouse Map tab on the Inventory Management page

**Files:**
- Modify: `frontend/src/admin/pages/InventoryManagement.jsx` (tab state/nav, new tab render block)

- [ ] **Step 1: Add state for the new tab**

Near the other inventory state (after the `expandedBatchIds`/`batchesByInventoryId`/`batchesLoading` state added by the prior feature), add:

```jsx
  const [warehouseBatches, setWarehouseBatches] = useState([]);
  const [warehouseBatchesLoading, setWarehouseBatchesLoading] = useState(false);
  const [warehouseBatchesError, setWarehouseBatchesError] = useState(null);
  const [editingZoneBatchId, setEditingZoneBatchId] = useState(null);
  const [editingZoneValue, setEditingZoneValue] = useState('');
```

- [ ] **Step 2: Add a fetch effect, tied to the tab being active**

Add this `useEffect` alongside the component's other effects (anywhere before the `return` statement):

```jsx
  useEffect(() => {
    if (activeTab !== 'warehouse-map') return;
    let cancelled = false;
    setWarehouseBatchesLoading(true);
    setWarehouseBatchesError(null);
    inventoryAPI.getWarehouseBatches()
      .then((res) => {
        if (!cancelled) setWarehouseBatches(res?.data ?? []);
      })
      .catch((err) => {
        if (!cancelled) setWarehouseBatchesError(err?.message || 'Failed to load warehouse batches.');
      })
      .finally(() => {
        if (!cancelled) setWarehouseBatchesLoading(false);
      });
    return () => { cancelled = true; };
  }, [activeTab]);
```

- [ ] **Step 3: Compute the "oldest batch per product" FIFO badge set**

Add this derived value right before the `return` statement (or alongside other `useMemo`s if the file already uses them — check the file for an existing `useMemo` import; if absent, add `useMemo` to the existing `import React, { ... } from 'react';` line):

```jsx
  const oldestBatchIdByProduct = useMemo(() => {
    const oldest = {};
    for (const b of warehouseBatches) {
      const current = oldest[b.product_id];
      if (!current || (b.received_at && (!current.received_at || b.received_at < current.received_at))) {
        oldest[b.product_id] = b;
      }
    }
    const result = {};
    for (const productId of Object.keys(oldest)) {
      result[productId] = oldest[productId].batch_id;
    }
    return result;
  }, [warehouseBatches]);
```

(`received_at` is an ISO date string like `"2026-07-15"` from the backend, so plain string comparison correctly orders oldest-first — no `Date` parsing needed.)

- [ ] **Step 4: Add zone-label edit handlers**

```jsx
  const startEditingZone = (batch) => {
    setEditingZoneBatchId(batch.batch_id);
    setEditingZoneValue(batch.zone_label || '');
  };

  const saveZoneEdit = async (batchId) => {
    try {
      await inventoryAPI.updateBatchZone(batchId, editingZoneValue || null);
      setWarehouseBatches((prev) =>
        prev.map((b) => (b.batch_id === batchId ? { ...b, zone_label: editingZoneValue || null } : b))
      );
      setEditingZoneBatchId(null);
    } catch (err) {
      toast.error(err?.message || 'Failed to update zone label.');
    }
  };
```

(`toast` is already imported/used elsewhere in this file for error messages — confirm the existing import at the top of the file before adding this; it should already be there since the file already calls `toast.error(...)` in other handlers.)

- [ ] **Step 5: Add the tab button**

Find:

```jsx
          <button
            className={`ak-tab ${activeTab === 'activity' ? 'is-active' : ''}`}
            onClick={() => setActiveTab('activity')}
          >
            Activity Log
          </button>
        </div>
```

change to:

```jsx
          <button
            className={`ak-tab ${activeTab === 'activity' ? 'is-active' : ''}`}
            onClick={() => setActiveTab('activity')}
          >
            Activity Log
          </button>
          <button
            className={`ak-tab ${activeTab === 'warehouse-map' ? 'is-active' : ''}`}
            onClick={() => setActiveTab('warehouse-map')}
          >
            Warehouse Map
          </button>
        </div>
```

- [ ] **Step 6: Add the tab's render block**

Find the point right before the `activity` tab's block begins (`{activeTab === 'activity' && (() => {`) and insert a new block immediately before it:

```jsx
        {activeTab === 'warehouse-map' && (
          <div className="inv-warehouse-map">
            {warehouseBatchesLoading ? (
              <div className="inv-batch-loading">Loading warehouse batches…</div>
            ) : warehouseBatchesError ? (
              <div className="inv-batch-empty">{warehouseBatchesError}</div>
            ) : warehouseBatches.length === 0 ? (
              <div className="inv-batch-empty">No batches with remaining stock.</div>
            ) : (
              <div className="inv-warehouse-map-grid">
                {warehouseBatches.map((batch) => (
                  <div key={batch.batch_id} className="inv-warehouse-map-card">
                    <div className="inv-warehouse-map-card-header">
                      <strong>{batch.product_name}</strong>
                      {oldestBatchIdByProduct[batch.product_id] === batch.batch_id && (
                        <span className="inv-fifo-badge">Pick this first (FIFO)</span>
                      )}
                    </div>
                    <div className="inv-warehouse-map-card-body">
                      <div>PO: {batch.is_legacy ? 'Pre-tracking stock' : (batch.po_number || '—')}</div>
                      <div>Received: {batch.received_at || '—'}</div>
                      <div>Remaining: {batch.quantity_remaining} / {batch.quantity_received}</div>
                      <div className="inv-warehouse-map-zone">
                        {editingZoneBatchId === batch.batch_id ? (
                          <>
                            <input
                              type="text"
                              value={editingZoneValue}
                              onChange={(e) => setEditingZoneValue(e.target.value)}
                              placeholder="e.g. Aisle 3, Shelf B"
                            />
                            <button onClick={() => saveZoneEdit(batch.batch_id)}>Save</button>
                            <button onClick={() => setEditingZoneBatchId(null)}>Cancel</button>
                          </>
                        ) : (
                          <>
                            <span>{batch.zone_label || 'Unassigned'}</span>
                            <button className="inv-action-link" onClick={() => startEditingZone(batch)}>Edit</button>
                          </>
                        )}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {activeTab === 'activity' && (() => {
```

- [ ] **Step 7: Verify it builds**

Run: `cd frontend && npm run build 2>&1 | tail -60`
Expected: succeeds, no new errors in `InventoryManagement.jsx`. (New CSS classes like `inv-warehouse-map`, `inv-warehouse-map-grid`, `inv-warehouse-map-card`, `inv-fifo-badge` are unstyled/default — matches this branch's established convention from the prior feature's Task 12 of leaving new structural classes unstyled unless visual polish is explicitly requested.)

- [ ] **Step 8: Manual verification**

Since no automated frontend test exists for this page: start the dev server, go to Inventory Management, click the new "Warehouse Map" tab, confirm cards render for batches with remaining stock, confirm the "Pick this first (FIFO)" badge appears on the oldest batch per product (create two batches for the same product at different `received_at` values via receiving to verify), and confirm the inline zone-label edit (Edit → type → Save) persists and reflects immediately.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/admin/pages/InventoryManagement.jsx
git commit -m "feat(ui): add Warehouse Map tab with FIFO badges and zone-label editing"
```

---

## Self-Review Notes

- **Spec coverage:** data model (Task 1, 2), receiving entry (Task 4, 7), editing after the fact (Task 5, 9), visualization + FIFO badge (Task 9), API surface for both (Task 5, 6, 8). All spec sections have a corresponding task.
- **Location scoping resolved during research** (not covered by the original spec's text): the Warehouse Map endpoint needs no client-supplied `location_id` — it reuses `ScopesToUserLocation` (Task 2), the same trait already used by `Inventory`/`Receiving`, matching this codebase's existing convention rather than introducing a new location-filter UI control that doesn't otherwise exist on this page.
- **Type/signature consistency:** `InventoryBatchService::createBatch()`'s new 6th parameter (`?string $zoneLabel = null`) is defined once in Task 3 and used identically in Task 4's `ReceivingController` call site — no renamed variants. `inventoryAPI.getWarehouseBatches()`/`updateBatchZone()` (Task 8) are used with matching names in Task 9.
- **Missing spec-referenced file, resolved before this plan was written:** the original FIFO feature's design spec (`docs/superpowers/specs/2026-07-20-po-location-batched-inventory-fifo-design.md`) had been committed to `backupV3` before this worktree/branch was created, so it was absent from this branch's history — brought in as a preceding commit so this branch and its docs are self-contained.
