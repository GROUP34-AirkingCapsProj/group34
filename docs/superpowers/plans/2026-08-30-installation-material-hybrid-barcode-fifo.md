# Hybrid Barcode Tracking and Strict FIFO Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let installation materials be received by counting when they arrive without barcodes, give every received item a system-generated digital tag, and enforce strict FIFO at stock-out.

**Architecture:** A new count-based receiving endpoint sits beside the existing scan endpoint; both post through a shared `ReceivingPostingService` so they cannot drift. Every receipt mints `item_serial` tag rows linked to the `inventory_batches` row they came from — tags identify, batches account. At stock-out a `FifoEnforcementService` resolves the scanned tag to its batch and refuses the pick if an older eligible batch still has stock.

**Tech Stack:** Laravel 11 (PHP 8.2), MySQL/MariaDB, PHPUnit with `DatabaseTransactions`, React 18, `react-barcode`, `html5-qrcode`, `barryvdh/laravel-dompdf`.

**Spec:** `docs/superpowers/specs/2026-08-30-installation-material-hybrid-barcode-fifo-design.md`

---

## Conventions in this codebase (read before Task 1)

- Tests live under `back-end/tests/Feature/<Domain>/`. **Receiving tests go in `tests/Feature/Inventory/`** — that is where `ReceivingCreatesBatchTest`, `ScanTransactionReceivingWithPoTest` and five others already live. The spec proposed a new `Receiving/` directory; ignore that, follow the existing layout.
- Tests use `use DatabaseTransactions;` — **not** `RefreshDatabase`. The suite runs against a real MySQL database (`airkingV5_test`), configured in `phpunit.xml`.
- Models are created inline in tests with `uniqid()` for unique codes. There are no factories for these tables.
- Run a single test with: `cd back-end && php artisan test --filter=TestClassName`
- Data-writing migrations use `DB::table(...)`, never Eloquent models — the `Auditable` trait fires boot hooks that must not run during migration.
- Status IDs are hardcoded integers: 8 = Approved, 10 = Authorized, 11 = Completed, 12 = Partially Received, 26 = the status used for received serials.

## Correction to the spec

The spec flags "verify `syncPoReceivingStatus()` doesn't compare with `==`". **It is verified: line 981 uses `($totalReceived >= $totalOrdered)`, so over-receipt already reaches Completed correctly.** No fix needed. Task 7 adds a regression test to keep it that way.

The spec also proposes a new `tests/Feature/Receiving/` directory. Ignore that — receiving tests already live in `tests/Feature/Inventory/`, and the plan follows the existing layout.

## File structure

**Create:**
- `back-end/app/Services/ReceivingPostingService.php` — the single write path for receiving stock
- `back-end/app/Services/MaterialTagService.php` — mints `INT-…` serials and links them to batches
- `back-end/app/Services/FifoEnforcementService.php` — resolves a tag to a batch and judges FIFO
- `back-end/app/Http/Controllers/Api/ReceiveCountController.php` — the count endpoint
- 6 migrations under `back-end/database/migrations/`
- `frontend/src/admin/components/receiving/CountEntryPanel.jsx`
- `frontend/src/admin/components/tags/MaterialTagSheet.jsx`
- `frontend/src/admin/components/issuance/FifoViolationDialog.jsx`

**Modify:**
- `back-end/app/Models/Inventory.php` — add `setPieces()`
- `back-end/app/Models/ItemSerial.php` — new fillable columns + `batch()` relation
- `back-end/app/Models/IncidentItem.php` — add `quarantinedByBatch()`
- `back-end/app/Http/Controllers/Api/InventoryScanTransactionController.php` — delegate to the posting service
- `back-end/app/Http/Controllers/Api/IssuanceScanController.php` — FIFO check + `setPieces`
- `back-end/app/Services/WarrantyClaimService.php` — `setPieces`
- `back-end/app/Services/TransferService.php` — `setPieces`, `countShip()`, `countReceive()`
- `back-end/app/Http/Controllers/Api/BarcodeAdjustmentController.php` — `setPieces`
- `back-end/routes/api.php` — new routes
- `frontend/src/admin/pages/BarcodeScan.jsx` — the "Enter count instead" button
- `frontend/src/admin/services/api.js` — new API methods

---

## Phase 1 — Stock accounting foundation

This phase fixes the packages/pieces divergence. It ships independently and is valuable on its own: it repairs a live bug where transfers never update `packages_on_hand`.

### Task 1: `Inventory::setPieces()`

**Files:**
- Modify: `back-end/app/Models/Inventory.php`
- Test: `back-end/tests/Feature/Inventory/InventorySetPiecesTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/InventorySetPiecesTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InventorySetPiecesTest extends TestCase
{
    use DatabaseTransactions;

    private function makeInventory(int $qty, int $available): Inventory
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Consumable',
            'product_type' => 'installation_material',
        ]);

        return Inventory::create([
            'location_id'        => $location->location_id,
            'product_id'         => $product->product_id,
            'quantity_on_hand'   => $qty,
            'packages_on_hand'   => 0,
            'available_quantity' => $available,
            'reorder_level'      => 0,
        ]);
    }

    public function test_it_stores_pieces_and_derives_packages_by_floor_division(): void
    {
        $inv = $this->makeInventory(0, 0);

        $inv->setPieces(473, 100);

        $this->assertSame(473, $inv->quantity_on_hand);
        $this->assertSame(4, $inv->packages_on_hand, 'packages must floor, never round up');
    }

    public function test_it_moves_available_quantity_by_the_delta_not_by_assignment(): void
    {
        // 30 pieces reserved elsewhere: available sits below on-hand.
        $inv = $this->makeInventory(100, 70);

        $inv->setPieces(150, 100);

        $this->assertSame(150, $inv->quantity_on_hand);
        $this->assertSame(120, $inv->available_quantity, 'available must rise by the +50 delta, not reset to parity with on-hand');
    }

    public function test_it_never_drives_available_quantity_negative(): void
    {
        $inv = $this->makeInventory(100, 5);

        $inv->setPieces(0, 100);

        $this->assertSame(0, $inv->quantity_on_hand);
        $this->assertSame(0, $inv->available_quantity);
    }

    public function test_it_treats_zero_pieces_per_package_as_no_packages(): void
    {
        $inv = $this->makeInventory(0, 0);

        $inv->setPieces(50, 0);

        $this->assertSame(50, $inv->quantity_on_hand);
        $this->assertSame(0, $inv->packages_on_hand, 'must not divide by zero');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=InventorySetPiecesTest`
Expected: FAIL — `Call to undefined method App\Models\Inventory::setPieces()`

- [ ] **Step 3: Write minimal implementation**

In `back-end/app/Models/Inventory.php`, add after the `$casts` array:

```php
    /**
     * The single writer for stock levels.
     *
     * Pieces are the source of truth; packages_on_hand is derived. available_quantity
     * moves by the DELTA rather than being assigned, because issuance, transfers and
     * warranty claims apply relative decrements clamped at zero — assigning parity
     * would restore phantom availability wherever the two columns had diverged.
     */
    public function setPieces(int $pieces, int $piecesPerPackage): void
    {
        $delta = $pieces - (int) $this->quantity_on_hand;

        $this->quantity_on_hand   = $pieces;
        $this->available_quantity = max(0, (int) $this->available_quantity + $delta);
        $this->packages_on_hand   = $piecesPerPackage > 0
            ? intdiv($pieces, $piecesPerPackage)
            : 0;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=InventorySetPiecesTest`
Expected: PASS — 4 tests, 8 assertions

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Models/Inventory.php back-end/tests/Feature/Inventory/InventorySetPiecesTest.php
git commit -m "feat(inventory): add setPieces as the single writer for stock levels"
```

---

### Task 2: Route receiving through `setPieces()`

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/InventoryScanTransactionController.php:346-358`
- Test: `back-end/tests/Feature/Inventory/ReceivingPackagesDeriveTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/ReceivingPackagesDeriveTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReceivingPackagesDeriveTest extends TestCase
{
    use DatabaseTransactions;

    public function test_receiving_a_package_does_not_reset_available_quantity_to_parity(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 100,
            'package_unit' => 'Box', 'is_serialized' => 0,
        ]);

        // Pre-existing stock with 30 pieces reserved elsewhere.
        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 100, 'packages_on_hand' => 1,
            'available_quantity' => 70, 'reorder_level' => 0,
        ]);

        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 10,
            'unit_price' => 5, 'subtotal' => 50,
        ]);

        $res = $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => 'SUP-' . uniqid(),
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'po_id'            => $po->po_id,
            'po_detail_id'     => $poDetail->po_detail_id,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'applied');

        $inv = Inventory::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)->first();

        $this->assertSame(200, $inv->quantity_on_hand);
        $this->assertSame(2, $inv->packages_on_hand);
        $this->assertSame(170, $inv->available_quantity, 'available must rise by the +100 delta, not be reset to 200');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=ReceivingPackagesDeriveTest`
Expected: FAIL — `Failed asserting that 200 is identical to 170`. The current code at line 356 assigns `available_quantity = quantity_on_hand`.

- [ ] **Step 3: Write minimal implementation**

In `InventoryScanTransactionController::processReceiving()`, replace the whole `if ($isSerialized) { … } else { … }` inventory block (currently lines ~346-358):

```php
            if ($isSerialized) {
                // Serialized: 1 scan = 1 unit; package concept does not apply.
                $inv->setPieces((int) $inv->quantity_on_hand + 1, 0);
            } else {
                // Consumable: 1 scan = 1 physical package.
                $inv->setPieces(
                    (int) $inv->quantity_on_hand + ($packagesReceived * $piecesPerPackage),
                    $piecesPerPackage
                );
            }
            $inv->save();
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd back-end && php artisan test --filter=ReceivingPackagesDeriveTest`
Expected: PASS

Then run the existing receiving suite to prove nothing regressed:

Run: `cd back-end && php artisan test tests/Feature/Inventory`
Expected: PASS — all pre-existing tests still green

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/InventoryScanTransactionController.php back-end/tests/Feature/Inventory/ReceivingPackagesDeriveTest.php
git commit -m "fix(receiving): derive packages via setPieces instead of overwriting available"
```

---

### Task 3: Route the remaining four writers through `setPieces()`

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/IssuanceScanController.php:477-480, 887-892`
- Modify: `back-end/app/Services/WarrantyClaimService.php:248-252, 398-402`
- Modify: `back-end/app/Services/TransferService.php:147-152, 393-398`
- Modify: `back-end/app/Http/Controllers/Api/BarcodeAdjustmentController.php:319-325, 360-366`
- Test: `back-end/tests/Feature/Transfer/TransferMaintainsPackagesTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Transfer/TransferMaintainsPackagesTest.php`:

```php
<?php

namespace Tests\Feature\Transfer;

use App\Models\ConsumableDetail;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TransferMaintainsPackagesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_setPieces_keeps_packages_in_step_when_stock_leaves(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Src', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Tube',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Roll', 'is_serialized' => 0,
        ]);

        $inv = Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 500, 'packages_on_hand' => 10,
            'available_quantity' => 500, 'reorder_level' => 0,
        ]);

        // Ship 120 pieces out.
        $inv->setPieces((int) $inv->quantity_on_hand - 120, 50);
        $inv->save();

        $fresh = $inv->fresh();
        $this->assertSame(380, $fresh->quantity_on_hand);
        $this->assertSame(7, $fresh->packages_on_hand, 'packages must follow stock out, which transfers never did before');
        $this->assertSame(380, $fresh->available_quantity);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=TransferMaintainsPackagesTest`
Expected: PASS already — `setPieces` exists from Task 1. This test is a *characterisation guard* for the behaviour Task 3 propagates into the real call sites; it locks in the contract before the edits below.

- [ ] **Step 3: Convert each call site**

`IssuanceScanController` line ~477 — replace:

```php
            $inv->quantity_on_hand   = max(0, $inv->quantity_on_hand - $quantityInPieces);
            $inv->available_quantity = max(0, $inv->available_quantity - $quantityInPieces);
            $inv->packages_on_hand = (int) floor($inv->quantity_on_hand / $piecesPerPackage);
```

with:

```php
            $inv->setPieces(max(0, (int) $inv->quantity_on_hand - $quantityInPieces), $piecesPerPackage);
```

`IssuanceScanController` line ~887 (the revert path) — replace:

```php
                $inv->quantity_on_hand += $detail->quantity_in_pieces;
                $inv->available_quantity += $detail->quantity_in_pieces;
                if ($pcs > 0) {
                    $inv->packages_on_hand = (int) floor($inv->quantity_on_hand / $pcs);
                }
```

with:

```php
                $inv->setPieces((int) $inv->quantity_on_hand + (int) $detail->quantity_in_pieces, $pcs);
```

`WarrantyClaimService` lines ~250 and ~400 — both follow the shape `$inv->packages_on_hand = (int) floor($inv->quantity_on_hand / $ppp);` immediately after a `quantity_on_hand` change. Replace each pair with a single `$inv->setPieces(<the new piece total>, $ppp);`.

`TransferService` line ~149 — replace:

```php
                $src->quantity_on_hand   = (int) $src->quantity_on_hand - $shipped;
                $src->available_quantity = (int) $src->available_quantity - $shipped;
```

with:

```php
                $ppp = $this->isSerialized($detail) ? 0 : $detail->product->getPiecesPerPackage();
                $src->setPieces((int) $src->quantity_on_hand - $shipped, $ppp);
```

`TransferService` line ~395 — replace:

```php
                    $dst->quantity_on_hand   = (int) $dst->quantity_on_hand + $received;
```

and the sibling `available_quantity` line with:

```php
                    $ppp = $this->isSerialized($detail) ? 0 : $detail->product->getPiecesPerPackage();
                    $dst->setPieces((int) $dst->quantity_on_hand + $received, $ppp);
```

`BarcodeAdjustmentController` lines ~321 and ~362 — replace the paired `quantity_on_hand` / `available_quantity` / `packages_on_hand` writes with one `setPieces()` call each, passing the product's `getPiecesPerPackage()` (or `0` for serialized products).

- [ ] **Step 4: Run the full affected suites**

Run: `cd back-end && php artisan test tests/Feature/Transfer tests/Feature/Issuance tests/Feature/Warranty tests/Feature/Inventory`
Expected: PASS — no regressions. If `TransferBatchFifoTest` or `DispatchFifoEnforcementTest` fail, the `$ppp` resolution above is wrong for serialized lines; check `isSerialized($detail)` returns true for appliances.

- [ ] **Step 5: Commit**

```bash
git add back-end/app back-end/tests/Feature/Transfer/TransferMaintainsPackagesTest.php
git commit -m "refactor(inventory): route all stock writers through setPieces"
```

---

## Phase 2 — Schema

### Task 4: Migrations for `count_unit` and `entry_mode`

**Files:**
- Create: `back-end/database/migrations/2026_08_30_000001_add_count_unit_to_installation_material_details.php`
- Create: `back-end/database/migrations/2026_08_30_000002_add_entry_mode_to_receiving_details.php`
- Modify: `back-end/app/Models/ConsumableDetail.php`
- Test: `back-end/tests/Feature/Inventory/CountUnitColumnTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/CountUnitColumnTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CountUnitColumnTest extends TestCase
{
    use DatabaseTransactions;

    public function test_count_unit_and_entry_mode_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('installation_material_details', 'count_unit'));
        $this->assertTrue(Schema::hasColumn('receiving_details', 'entry_mode'));
    }

    public function test_count_unit_defaults_to_package_and_is_mass_assignable(): void
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Bolt M8',
            'product_type' => 'installation_material',
        ]);

        $detail = ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 100, 'is_serialized' => 0,
        ]);
        $this->assertSame('package', $detail->fresh()->count_unit);

        $detail->update(['count_unit' => 'piece']);
        $this->assertSame('piece', $detail->fresh()->count_unit);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=CountUnitColumnTest`
Expected: FAIL — `Failed asserting that false is true` (column missing)

- [ ] **Step 3: Write the migrations**

`2026_08_30_000001_add_count_unit_to_installation_material_details.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How this product is physically counted when a delivery arrives unbarcoded.
 * Drives two things only: what the receiving screen asks for, and how many
 * digital tags a counted receipt line mints. It never affects storage —
 * pieces are always what gets stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installation_material_details', function (Blueprint $table) {
            $table->enum('count_unit', ['package', 'piece'])
                  ->default('package')
                  ->after('package_unit');
        });
    }

    public function down(): void
    {
        Schema::table('installation_material_details', function (Blueprint $table) {
            $table->dropColumn('count_unit');
        });
    }
};
```

`2026_08_30_000002_add_entry_mode_to_receiving_details.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this line's QUANTITY was verified by scanning supplier barcodes or by a
 * human count. Distinct from whether a digital tag exists — under hybrid tagging a
 * tag always exists. scanned_barcode is already nullable, but "null because counted"
 * must be distinguishable from "null because legacy" for the audit trail.
 *
 * Existing rows default to 'scanned', which is what they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receiving_details', function (Blueprint $table) {
            $table->enum('entry_mode', ['scanned', 'counted'])
                  ->default('scanned')
                  ->after('scanned_barcode');
        });
    }

    public function down(): void
    {
        Schema::table('receiving_details', function (Blueprint $table) {
            $table->dropColumn('entry_mode');
        });
    }
};
```

In `back-end/app/Models/ConsumableDetail.php`, add `'count_unit'` to `$fillable` after `'is_serialized'`.

- [ ] **Step 4: Migrate and run the test**

Run: `cd back-end && php artisan migrate && php artisan test --filter=CountUnitColumnTest`
Expected: PASS — 2 tests

- [ ] **Step 5: Commit**

```bash
git add back-end/database/migrations back-end/app/Models/ConsumableDetail.php back-end/tests/Feature/Inventory/CountUnitColumnTest.php
git commit -m "feat(schema): add count_unit and entry_mode columns"
```

---

### Task 5: Migration for tag columns on `item_serial`

**Files:**
- Create: `back-end/database/migrations/2026_08_30_000003_add_tag_columns_to_item_serial.php`
- Modify: `back-end/app/Models/ItemSerial.php`
- Test: `back-end/tests/Feature/Inventory/ItemSerialTagColumnsTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/ItemSerialTagColumnsTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ItemSerialTagColumnsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_tag_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('item_serial', 'batch_id'));
        $this->assertTrue(Schema::hasColumn('item_serial', 'supplier_barcode'));
    }

    public function test_one_row_carries_both_identifiers_and_resolves_to_its_batch(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        $batch = InventoryBatch::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'quantity_received' => 100, 'quantity_remaining' => 100, 'received_at' => now(),
        ]);

        $tag = ItemSerial::create([
            'product_id'       => $product->product_id,
            'location_id'      => $location->location_id,
            'batch_id'         => $batch->batch_id,
            'serial_number'    => 'INT-TEST-' . uniqid(),
            'supplier_barcode' => 'SUP-' . uniqid(),
            'serial_type'      => 'received',
        ]);

        $this->assertSame($batch->batch_id, $tag->fresh()->batch->batch_id);
        $this->assertNotNull($tag->fresh()->supplier_barcode);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=ItemSerialTagColumnsTest`
Expected: FAIL — column `batch_id` missing

- [ ] **Step 3: Write the migration and model changes**

`2026_08_30_000003_add_tag_columns_to_item_serial.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digital tags for installation materials.
 *
 * batch_id is explicit rather than derived through receiving_id, because
 * transfer-created batches carry a null receiving_id and the derivation would
 * silently fail there. It is the anchor FIFO enforcement reads.
 *
 * supplier_barcode lets one row carry BOTH identifiers for an item that arrived
 * labelled — the generated INT-… serial and the manufacturer's code. Two rows
 * would double-count against the batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_serial', function (Blueprint $table) {
            $table->unsignedBigInteger('batch_id')->nullable()->after('receiving_id');
            $table->string('supplier_barcode', 191)->nullable()->after('serial_number');

            $table->foreign('batch_id')
                  ->references('batch_id')->on('inventory_batches')
                  ->nullOnDelete();
            $table->index('supplier_barcode', 'item_serial_supplier_barcode_idx');
        });
    }

    public function down(): void
    {
        Schema::table('item_serial', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
            $table->dropIndex('item_serial_supplier_barcode_idx');
            $table->dropColumn(['batch_id', 'supplier_barcode']);
        });
    }
};
```

In `back-end/app/Models/ItemSerial.php`, add `'batch_id'` and `'supplier_barcode'` to `$fillable`, and add:

```php
    public function batch()
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id', 'batch_id');
    }
```

- [ ] **Step 4: Migrate and run the test**

Run: `cd back-end && php artisan migrate && php artisan test --filter=ItemSerialTagColumnsTest`
Expected: PASS — 2 tests

- [ ] **Step 5: Commit**

```bash
git add back-end/database/migrations back-end/app/Models/ItemSerial.php back-end/tests/Feature/Inventory/ItemSerialTagColumnsTest.php
git commit -m "feat(schema): add batch_id and supplier_barcode to item_serial"
```

---

## Phase 3 — Tags and the shared posting path

### Task 6: `MaterialTagService`

**Files:**
- Create: `back-end/app/Services/MaterialTagService.php`
- Test: `back-end/tests/Feature/Inventory/MaterialTagServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/MaterialTagServiceTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Services\MaterialTagService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MaterialTagServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function scaffold(string $countUnit, int $ppp = 100): array
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Material',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => $ppp,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => $countUnit,
        ]);
        $batch = InventoryBatch::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'quantity_received' => 600, 'quantity_remaining' => 600, 'received_at' => now(),
        ]);

        return [$location, $product, $batch];
    }

    public function test_package_counted_product_mints_one_tag_per_package(): void
    {
        [$location, $product, $batch] = $this->scaffold('package', 100);

        $tags = app(MaterialTagService::class)->mintForCount(
            $product->fresh(), $batch, $location->location_id, 6, 'PO-2401'
        );

        $this->assertCount(6, $tags);
        $this->assertSame(6, ItemSerial::where('batch_id', $batch->batch_id)->count());
        $this->assertStringStartsWith('INT-PO-2401-' . $product->product_id . '-', $tags[0]->serial_number);
    }

    public function test_piece_counted_product_mints_exactly_one_lot_tag(): void
    {
        [$location, $product, $batch] = $this->scaffold('piece', 1);

        $tags = app(MaterialTagService::class)->mintForCount(
            $product->fresh(), $batch, $location->location_id, 473, 'PO-2401'
        );

        $this->assertCount(1, $tags, '473 bolts must not produce 473 tags');
        $this->assertSame(1, ItemSerial::where('batch_id', $batch->batch_id)->count());
    }

    public function test_scan_path_mints_one_tag_carrying_the_supplier_barcode(): void
    {
        [$location, $product, $batch] = $this->scaffold('piece', 1);
        $supplier = 'SUP-' . uniqid();

        $tag = app(MaterialTagService::class)->mintForScan(
            $product->fresh(), $batch, $location->location_id, $supplier, 'PO-2401'
        );

        $this->assertSame($supplier, $tag->supplier_barcode);
        $this->assertStringStartsWith('INT-PO-2401-', $tag->serial_number);
        $this->assertSame($batch->batch_id, $tag->batch_id);
    }

    public function test_generated_serials_are_globally_unique_across_receipts(): void
    {
        [$location, $product, $batch] = $this->scaffold('package', 100);
        $svc = app(MaterialTagService::class);

        $first  = $svc->mintForCount($product->fresh(), $batch, $location->location_id, 3, 'PO-2401');
        $second = $svc->mintForCount($product->fresh(), $batch, $location->location_id, 3, 'PO-2401');

        $all = array_merge(
            array_map(fn ($t) => $t->serial_number, $first),
            array_map(fn ($t) => $t->serial_number, $second),
        );
        $this->assertCount(6, array_unique($all), 'a second receipt on the same batch must not collide');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=MaterialTagServiceTest`
Expected: FAIL — `Target class [App\Services\MaterialTagService] does not exist.`

- [ ] **Step 3: Write the implementation**

Create `back-end/app/Services/MaterialTagService.php`:

```php
<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Product;
use Illuminate\Database\QueryException;

/**
 * Mints digital tags for installation materials.
 *
 * Tags identify, batches account. A tag names a physical thing and resolves to
 * exactly one batch; it carries no quantity of its own. Quantity lives in
 * inventory_batches.quantity_remaining.
 *
 * Every method here must be called inside the caller's DB transaction, so a
 * rolled-back receipt leaves no orphan tags.
 */
class MaterialTagService
{
    /** Status 26 — the same status receiving already stamps on received serials. */
    private const STATUS_RECEIVED = 26;

    /**
     * Count path: granularity follows the product's count_unit.
     *   package -> one tag per package
     *   piece   -> one tag for the whole lot
     *
     * @return ItemSerial[]
     */
    public function mintForCount(
        Product $product,
        InventoryBatch $batch,
        int $locationId,
        int $quantityInUnits,
        string $referenceLabel,
        ?int $userId = null
    ): array {
        $countUnit = $product->consumableDetail?->count_unit ?? 'package';
        $tagCount  = $countUnit === 'piece' ? 1 : max(1, $quantityInUnits);

        $tags = [];
        for ($i = 0; $i < $tagCount; $i++) {
            $tags[] = $this->create($product, $batch, $locationId, $referenceLabel, null, $userId);
        }

        return $tags;
    }

    /**
     * Scan path: always ONE tag per scan, regardless of count_unit. A scanned item is
     * already individually identified, so it gets its own row paired with the supplier
     * barcode. Applying count_unit here would leave a supplier barcode with no tag.
     */
    public function mintForScan(
        Product $product,
        InventoryBatch $batch,
        int $locationId,
        ?string $supplierBarcode,
        string $referenceLabel,
        ?int $userId = null
    ): ItemSerial {
        return $this->create($product, $batch, $locationId, $referenceLabel, $supplierBarcode, $userId);
    }

    /**
     * The DB unique constraint on item_serial.serial_number is the real guarantee.
     * A PHP-side "does it exist" check would race under concurrent receiving, so we
     * insert and retry on the resulting integrity violation instead.
     */
    private function create(
        Product $product,
        InventoryBatch $batch,
        int $locationId,
        string $referenceLabel,
        ?string $supplierBarcode,
        ?int $userId
    ): ItemSerial {
        $prefix = sprintf('INT-%s-%d-', $referenceLabel, $product->product_id);

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $seq    = ItemSerial::where('serial_number', 'like', $prefix . '%')->count() + 1;
            $serial = $prefix . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);

            try {
                return ItemSerial::create([
                    'product_id'       => $product->product_id,
                    'location_id'      => $locationId,
                    'batch_id'         => $batch->batch_id,
                    'receiving_id'     => $batch->receiving_id,
                    'serial_number'    => $serial,
                    'supplier_barcode' => $supplierBarcode,
                    'serial_type'      => 'received',
                    'scanned_by'       => $userId,
                    'status_id'        => self::STATUS_RECEIVED,
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }
                // Another writer took this sequence number; recount and retry.
            }
        }

        throw new \RuntimeException("Could not mint a unique tag for prefix {$prefix} after 25 attempts.");
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=MaterialTagServiceTest`
Expected: PASS — 4 tests

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/MaterialTagService.php back-end/tests/Feature/Inventory/MaterialTagServiceTest.php
git commit -m "feat(tags): mint digital tags for installation materials"
```

---

### Task 7: Extract `PoReceivingStatusService`

`syncPoReceivingStatus()` is a **private** method on `InventoryScanTransactionController` (line 933). The count endpoint cannot call it. Extract it before Task 8 needs it.

**Files:**
- Create: `back-end/app/Services/PoReceivingStatusService.php`
- Modify: `back-end/app/Http/Controllers/Api/InventoryScanTransactionController.php:933-1002`
- Test: `back-end/tests/Feature/Inventory/PoReceivingStatusServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/PoReceivingStatusServiceTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\Location;
use App\Models\Receiving;
use App\Services\PoReceivingStatusService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PoReceivingStatusServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function scaffold(int $orderedPackages, int $ppp, int $receivedPieces): PurchaseOrder
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => $ppp, 'is_serialized' => 0,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => $orderedPackages,
            'unit_price' => 5, 'subtotal' => 50,
        ]);
        $receiving = Receiving::create([
            'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()->toDateString(),
            'location_id' => $location->location_id, 'po_id' => $po->po_id,
            'total_quantity_received' => $receivedPieces, 'total_quantity_damaged' => 0,
        ]);
        DB::table('receiving_details')->insert([
            'receiving_id' => $receiving->receiving_id, 'po_detail_id' => $poDetail->po_detail_id,
            'product_id' => $product->product_id, 'quantity_amount' => $receivedPieces,
            'prod_price' => 5, 'condition' => 'Good', 'entry_mode' => 'counted',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $po;
    }

    public function test_partial_receipt_sets_partially_received(): void
    {
        $po = $this->scaffold(10, 50, 100);   // 500 ordered, 100 received
        app(PoReceivingStatusService::class)->sync((int) $po->po_id);
        $this->assertSame(12, (int) $po->fresh()->status_id);
    }

    public function test_exact_receipt_sets_completed(): void
    {
        $po = $this->scaffold(2, 50, 100);    // 100 ordered, 100 received
        app(PoReceivingStatusService::class)->sync((int) $po->po_id);
        $this->assertSame(11, (int) $po->fresh()->status_id);
    }

    public function test_over_receipt_still_reaches_completed(): void
    {
        $po = $this->scaffold(2, 50, 130);    // 100 ordered, 130 received
        app(PoReceivingStatusService::class)->sync((int) $po->po_id);
        $this->assertSame(11, (int) $po->fresh()->status_id, 'the >= comparison must not leave an over-received PO open');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=PoReceivingStatusServiceTest`
Expected: FAIL — `Target class [App\Services\PoReceivingStatusService] does not exist.`

- [ ] **Step 3: Extract the method verbatim**

Create `back-end/app/Services/PoReceivingStatusService.php` containing the exact body of
`InventoryScanTransactionController::syncPoReceivingStatus()` (lines 933-1002), renamed to
`public function sync(int $poId, $userId = null): void`, with these imports:

```php
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDetail;
```

**Do not change the logic.** In particular keep line 981's comparison as
`($totalReceived >= $totalOrdered) ? 11 : 12` — it is already correct for over-receipt, which
is what the third test above pins.

Then replace the controller's private method body with a delegation:

```php
    private function syncPoReceivingStatus(int $poId, $userId = null): void
    {
        app(\App\Services\PoReceivingStatusService::class)->sync($poId, $userId);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd back-end && php artisan test --filter=PoReceivingStatusServiceTest`
Expected: PASS — 3 tests

Run: `cd back-end && php artisan test tests/Feature/Inventory`
Expected: PASS — the scan path still syncs PO status through the delegation

- [ ] **Step 5: Commit**

```bash
git add back-end/app back-end/tests/Feature/Inventory/PoReceivingStatusServiceTest.php
git commit -m "refactor(receiving): extract PoReceivingStatusService so both paths can sync"
```

---

### Task 8: `ReceivingPostingService`

**Files:**
- Create: `back-end/app/Services/ReceivingPostingService.php`
- Test: `back-end/tests/Feature/Inventory/ReceivingPostingServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/ReceivingPostingServiceTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\Receiving;
use App\Services\ReceivingPostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReceivingPostingServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function scaffold(int $orderedPackages, int $ppp): array
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => $ppp,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'piece',
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => $orderedPackages,
            'unit_price' => 5, 'subtotal' => 50,
        ]);

        return [$location, $product->fresh(), $poDetail];
    }

    public function test_checkOverage_returns_null_when_the_quantity_fits(): void
    {
        [, $product, $poDetail] = $this->scaffold(1, 50);   // 50 pieces ordered

        $this->assertNull(app(ReceivingPostingService::class)->checkOverage($poDetail, $product, 50));
    }

    public function test_checkOverage_describes_the_overage_when_it_does_not_fit(): void
    {
        [, $product, $poDetail] = $this->scaffold(1, 50);

        $result = app(ReceivingPostingService::class)->checkOverage($poDetail, $product, 55);

        $this->assertSame('over_receipt', $result['action']);
        $this->assertSame(50, $result['ordered_pieces']);
        $this->assertSame(0, $result['already_received']);
        $this->assertSame(50, $result['remaining']);
        $this->assertSame(55, $result['counted']);
        $this->assertSame(5, $result['overage']);
    }

    public function test_post_writes_inventory_batch_detail_and_tags_in_one_go(): void
    {
        [$location, $product, $poDetail] = $this->scaffold(10, 50);

        $result = DB::transaction(fn () => app(ReceivingPostingService::class)->post(
            product: $product,
            locationId: (int) $location->location_id,
            pieces: 473,
            entryMode: 'counted',
            poDetail: $poDetail,
            supplierBarcode: null,
            zoneLabel: 'A-03',
            userId: null,
            unitsEntered: 473,
        ));

        $this->assertSame(473, $result['pieces']);
        $this->assertSame(473, (int) $result['batch']->quantity_remaining);
        $this->assertSame('A-03', $result['batch']->zone_label);
        $this->assertInstanceOf(Receiving::class, $result['receiving']);

        $this->assertSame('counted', DB::table('receiving_details')
            ->where('po_detail_id', $poDetail->po_detail_id)->value('entry_mode'));
    }

    public function test_repeated_posts_accumulate_into_one_batch_per_receiving(): void
    {
        [$location, $product, $poDetail] = $this->scaffold(10, 50);
        $svc = app(ReceivingPostingService::class);

        $first = DB::transaction(fn () => $svc->post(
            product: $product, locationId: (int) $location->location_id, pieces: 100,
            entryMode: 'counted', poDetail: $poDetail, unitsEntered: 100,
        ));
        $second = DB::transaction(fn () => $svc->post(
            product: $product, locationId: (int) $location->location_id, pieces: 50,
            entryMode: 'counted', poDetail: $poDetail, unitsEntered: 50,
        ));

        $this->assertSame($first['batch']->batch_id, $second['batch']->batch_id);
        $this->assertSame(150, (int) $second['batch']->fresh()->quantity_remaining);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=ReceivingPostingServiceTest`
Expected: FAIL — `Target class [App\Services\ReceivingPostingService] does not exist.`

- [ ] **Step 3: Write the implementation**

Create `back-end/app/Services/ReceivingPostingService.php`:

```php
<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Models\PurchaseOrderDetail;
use App\Models\Receiving;
use App\Models\ReceivingDetail;

/**
 * The single write path for receiving stock. Both the barcode scan path and the
 * count path post through here so they cannot drift in how they move stock or
 * mint tags.
 *
 * Callers must open the DB transaction and hold a lockForUpdate() on the
 * Inventory row before calling post().
 */
class ReceivingPostingService
{
    private const STATUS_RECEIVED = 26;

    public function __construct(
        private InventoryBatchService $batches,
        private MaterialTagService $tags,
    ) {
    }

    /**
     * @param  string  $entryMode  'scanned' | 'counted'
     * @return array{receiving: Receiving, batch: InventoryBatch, pieces: int, tags: ItemSerial[]}
     */
    public function post(
        Product $product,
        int $locationId,
        int $pieces,
        string $entryMode,
        ?PurchaseOrderDetail $poDetail = null,
        ?string $supplierBarcode = null,
        ?string $zoneLabel = null,
        ?int $userId = null,
        int $unitsEntered = 1
    ): array {
        $piecesPerPackage = $product->isSerialized() ? 0 : $product->getPiecesPerPackage();

        $inv = Inventory::firstOrCreate(
            ['location_id' => $locationId, 'product_id' => $product->product_id],
            ['quantity_on_hand' => 0, 'packages_on_hand' => 0, 'available_quantity' => 0, 'reorder_level' => 0]
        );
        $inv->setPieces((int) $inv->quantity_on_hand + $pieces, $piecesPerPackage);
        $inv->save();

        $receiving = $this->resolveHeader($poDetail?->po_id, $locationId, $userId);
        $receiving->increment('total_quantity_received', $pieces);

        $batch = $this->accumulateBatch($receiving, $product, $locationId, $pieces, $zoneLabel);

        $receiving->details()->create([
            'product_id'      => $product->product_id,
            'po_detail_id'    => $poDetail?->po_detail_id,
            'quantity_amount' => $pieces,
            'prod_price'      => $product->cost_price ?? 0,
            'condition'       => 'Good',
            'scanned_barcode' => $supplierBarcode ?: null,
            'entry_mode'      => $entryMode,
        ]);

        $reference = $poDetail?->purchaseOrder?->po_number
            ?? 'RCV' . $receiving->receiving_number;

        $tags = $this->mintIdentifiers(
            $product, $batch, $receiving, $locationId, $entryMode,
            $supplierBarcode, $reference, $userId, $unitsEntered
        );

        return [
            'receiving' => $receiving,
            'batch'     => $batch,
            'pieces'    => $pieces,
            'tags'      => $tags,
        ];
    }

    /**
     * Over-receipt check. Returns null when the quantity fits, or a payload describing
     * the overage when it does not. Shared by both entry paths deliberately: two
     * different over-receipt rules depending on how stock was entered would be
     * impossible to explain to an auditor.
     */
    public function checkOverage(PurchaseOrderDetail $poDetail, Product $product, int $pieces): ?array
    {
        $piecesPerPackage = $product->isSerialized() ? 1 : $product->getPiecesPerPackage();
        $orderedPieces    = $product->isSerialized()
            ? (int) $poDetail->quantity_ordered
            : (int) $poDetail->quantity_ordered * $piecesPerPackage;

        $alreadyReceived = (int) ReceivingDetail::where('po_detail_id', $poDetail->po_detail_id)
            ->sum('quantity_amount');

        $remaining = max(0, $orderedPieces - $alreadyReceived);

        if ($pieces <= $remaining) {
            return null;
        }

        return [
            'action'           => 'over_receipt',
            'ordered_pieces'   => $orderedPieces,
            'already_received' => $alreadyReceived,
            'remaining'        => $remaining,
            'counted'          => $pieces,
            'overage'          => $pieces - $remaining,
        ];
    }

    /**
     * Serialized appliances keep their existing per-unit serial row (serial_number IS
     * the scanned serial, no INT- prefix) so warranty lookup and incident reports keep
     * working. Materials get generated tags instead.
     */
    private function mintIdentifiers(
        Product $product,
        InventoryBatch $batch,
        Receiving $receiving,
        int $locationId,
        string $entryMode,
        ?string $supplierBarcode,
        string $reference,
        ?int $userId,
        int $unitsEntered
    ): array {
        if ($product->isSerialized()) {
            if (! $supplierBarcode) {
                return [];
            }

            return [ItemSerial::create([
                'product_id'    => $product->product_id,
                'location_id'   => $locationId,
                'batch_id'      => $batch->batch_id,
                'receiving_id'  => $receiving->receiving_id,
                'scanned_by'    => $userId,
                'serial_number' => $supplierBarcode,
                'serial_type'   => 'received',
                'status_id'     => self::STATUS_RECEIVED,
            ])];
        }

        if ($entryMode === 'counted') {
            return $this->tags->mintForCount(
                $product, $batch, $locationId, $unitsEntered, $reference, $userId
            );
        }

        return [$this->tags->mintForScan(
            $product, $batch, $locationId, $supplierBarcode, $reference, $userId
        )];
    }

    private function resolveHeader(?int $poId, int $locationId, ?int $userId): Receiving
    {
        $receiving = $poId
            ? Receiving::where('po_id', $poId)->where('location_id', $locationId)->first()
            : Receiving::whereNull('po_id')->where('location_id', $locationId)
                ->whereDate('created_at', now()->toDateString())->first();

        if ($receiving) {
            return $receiving;
        }

        $receiving = new Receiving();
        $receiving->receiving_number        = 'PENDING-' . str_replace('.', '', uniqid('', true));
        $receiving->receiving_date          = now()->toDateString();
        $receiving->received_by             = $userId;
        $receiving->total_quantity_received = 0;
        $receiving->total_quantity_damaged  = 0;
        $receiving->location_id             = $locationId;
        if ($poId) {
            $receiving->po_id = $poId;
        }
        $receiving->save();
        $receiving->assignReadableNumber();

        return $receiving;
    }

    private function accumulateBatch(
        Receiving $receiving,
        Product $product,
        int $locationId,
        int $pieces,
        ?string $zoneLabel
    ): InventoryBatch {
        $existing = InventoryBatch::where('receiving_id', $receiving->receiving_id)
            ->where('product_id', $product->product_id)
            ->where('location_id', $locationId)
            ->first();

        if ($existing) {
            $existing->quantity_received  = (int) $existing->quantity_received + $pieces;
            $existing->quantity_remaining = (int) $existing->quantity_remaining + $pieces;
            if ($zoneLabel !== null) {
                $existing->zone_label = $zoneLabel;
            }
            $existing->save();

            return $existing;
        }

        return $this->batches->createBatch(
            $product->product_id,
            $locationId,
            $receiving->receiving_id,
            $pieces,
            $receiving->receiving_date ?? now(),
            $zoneLabel
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=ReceivingPostingServiceTest`
Expected: PASS — 4 tests

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/ReceivingPostingService.php back-end/tests/Feature/Inventory/ReceivingPostingServiceTest.php
git commit -m "feat(receiving): add shared ReceivingPostingService"
```

---

### Task 9: The count endpoint

**Files:**
- Create: `back-end/app/Http/Controllers/Api/ReceiveCountController.php`
- Modify: `back-end/routes/api.php` (below the scan-transaction route, line ~1103)
- Test: `back-end/tests/Feature/Inventory/ReceiveCountTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/ReceiveCountTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\ApplianceDetail;
use App\Models\ConsumableDetail;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReceiveCountTest extends TestCase
{
    use DatabaseTransactions;

    private function user(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    private function poDetailFor(Product $product, Location $location, int $orderedPackages): PurchaseOrderDetail
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);

        return PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => $orderedPackages,
            'unit_price' => 5, 'subtotal' => 50,
        ]);
    }

    private function location(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
    }

    public function test_counting_loose_pieces_stores_pieces_and_floors_packages(): void
    {
        $location = $this->location();
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Bolt M8',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 100,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'piece',
        ]);
        $poDetail = $this->poDetailFor($product, $location, 10);   // 1000 pieces ordered

        $res = $this->actingAs($this->user())->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 473,
            'zone_label'   => 'A-03',
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'applied');
        $res->assertJsonPath('data.quantity_received', 473);

        $inv = Inventory::where('product_id', $product->product_id)->first();
        $this->assertSame(473, $inv->quantity_on_hand);
        $this->assertSame(4, $inv->packages_on_hand);

        $batch = InventoryBatch::where('product_id', $product->product_id)->first();
        $this->assertSame(473, (int) $batch->quantity_remaining);
        $this->assertSame('A-03', $batch->zone_label);

        // count_unit = piece -> exactly one lot tag, not 473.
        $this->assertSame(1, ItemSerial::where('batch_id', $batch->batch_id)->count());

        $this->assertSame('counted', DB::table('receiving_details')
            ->where('po_detail_id', $poDetail->po_detail_id)->value('entry_mode'));
    }

    public function test_counting_packages_multiplies_by_pieces_per_package_and_tags_each(): void
    {
        $location = $this->location();
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);
        $poDetail = $this->poDetailFor($product, $location, 20);

        $res = $this->actingAs($this->user())->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 6,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.quantity_received', 300);

        $batch = InventoryBatch::where('product_id', $product->product_id)->first();
        $this->assertSame(6, ItemSerial::where('batch_id', $batch->batch_id)->count());
    }

    public function test_a_serialized_appliance_can_never_be_count_received(): void
    {
        $location = $this->location();
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Split Type AC',
            'product_type' => 'appliance',
        ]);
        ApplianceDetail::create(['product_id' => $product->product_id, 'is_serialized' => 1]);
        $poDetail = $this->poDetailFor($product, $location, 5);

        $res = $this->actingAs($this->user())->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 5,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'rejected');

        $this->assertNull(
            Inventory::where('product_id', $product->product_id)->first(),
            'an appliance must not gain stock through the count path'
        );
    }

    public function test_counting_more_than_remaining_is_refused_and_posts_nothing(): void
    {
        $location = $this->location();
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'piece',
        ]);
        $poDetail = $this->poDetailFor($product, $location, 1);   // 50 pieces ordered

        $res = $this->actingAs($this->user())->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 55,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'over_receipt');
        $res->assertJsonPath('data.remaining', 50);
        $res->assertJsonPath('data.overage', 5);

        $this->assertNull(Inventory::where('product_id', $product->product_id)->first());
    }

    public function test_accepting_the_overage_posts_the_full_counted_quantity_and_completes_the_po(): void
    {
        $location = $this->location();
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'piece',
        ]);
        $poDetail = $this->poDetailFor($product, $location, 1);

        $res = $this->actingAs($this->user())->postJson('/api/inventory/receive-count', [
            'po_detail_id'   => $poDetail->po_detail_id,
            'location_id'    => $location->location_id,
            'quantity'       => 55,
            'accept_overage' => true,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'applied');

        $inv = Inventory::where('product_id', $product->product_id)->first();
        $this->assertSame(55, $inv->quantity_on_hand, 'the full 55 must post, not a clamped 50');

        $this->assertSame(11, (int) $poDetail->purchaseOrder->fresh()->status_id,
            'an over-received PO must still reach Completed');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=ReceiveCountTest`
Expected: FAIL — 404, the route does not exist

- [ ] **Step 3: Write the controller**

Create `back-end/app/Http/Controllers/Api/ReceiveCountController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\PurchaseOrderDetail;
use App\Services\PoReceivingStatusService;
use App\Services\ReceivingPostingService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Receive installation materials by counting, for deliveries that arrive without
 * usable barcodes. The product is resolved from po_detail_id because there is no
 * barcode to resolve it from.
 *
 * Kept separate from InventoryScanTransactionController::store() on purpose: that
 * method's invariants are all barcode invariants (the global one-time-use lock, the
 * "one scan is one package" rule) and none of them apply here.
 */
class ReceiveCountController extends Controller
{
    use ApiResponse;

    public function __invoke(
        Request $request,
        ReceivingPostingService $posting,
        PoReceivingStatusService $poStatus
    ) {
        $validated = $request->validate([
            'po_detail_id'   => 'required|integer|exists:purchase_order_details,po_detail_id',
            'location_id'    => 'nullable|exists:locations,location_id',
            'quantity'       => 'required|integer|min:1',
            'zone_label'     => 'nullable|string|max:100',
            'accept_overage' => 'nullable|boolean',
        ]);

        $poDetail = PurchaseOrderDetail::with([
            'product.consumableDetail', 'product.applianceDetail', 'purchaseOrder',
        ])->find($validated['po_detail_id']);

        $product = $poDetail?->product;
        if (! $product) {
            return $this->error('Line item has no product.', 422);
        }

        // Serialized items must keep their per-unit serial trail. Losing it would
        // break warranty lookup and incident reports.
        if ($product->isSerialized()) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Serialized items must be scanned individually.',
            ], 'Rejected');
        }

        $po = $poDetail->purchaseOrder;
        if ($po && $po->verification_status !== 'verified') {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'This purchase order must be verified by an auditor before receiving.',
            ], 'Rejected');
        }
        if ($po && ! in_array((int) $po->status_id, [8, 10, 12], true)) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'PO must be Approved, Authorized, or Partially Received.',
            ], 'Rejected');
        }

        $locationId = (int) ($validated['location_id'] ?? $poDetail->location_id);
        if (! $locationId) {
            return $this->error('No location resolved for this receipt.', 422);
        }

        $unitsEntered = (int) $validated['quantity'];
        $countUnit    = $product->consumableDetail?->count_unit ?? 'package';
        $pieces       = $countUnit === 'piece'
            ? $unitsEntered
            : $unitsEntered * $product->getPiecesPerPackage();

        $overage = $posting->checkOverage($poDetail, $product, $pieces);
        if ($overage !== null && ! $request->boolean('accept_overage')) {
            return $this->success($overage, 'Over-receipt requires confirmation');
        }

        DB::beginTransaction();
        try {
            Inventory::where('location_id', $locationId)
                ->where('product_id', $product->product_id)
                ->lockForUpdate()
                ->first();

            $result = $posting->post(
                product: $product,
                locationId: $locationId,
                pieces: $pieces,
                entryMode: 'counted',
                poDetail: $poDetail,
                supplierBarcode: null,
                zoneLabel: $validated['zone_label'] ?? null,
                userId: $request->user()?->user_id,
                unitsEntered: $unitsEntered,
            );

            if ($po) {
                $poStatus->sync((int) $po->po_id, $request->user()?->user_id);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->error('Failed to post count: ' . $e->getMessage(), 500);
        }

        return $this->success([
            'action'            => 'applied',
            'quantity_received' => $result['pieces'],
            'units_entered'     => $unitsEntered,
            'count_unit'        => $countUnit,
            'receiving_number'  => $result['receiving']->receiving_number,
            'batch_id'          => $result['batch']->batch_id,
        ], 'Receiving posted');
    }
}
```

- [ ] **Step 4: Register the route**

In `back-end/routes/api.php`, directly below the existing scan-transaction route (line ~1103):

```php
        Route::post('/inventory/receive-count', \App\Http\Controllers\Api\ReceiveCountController::class);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd back-end && php artisan test --filter=ReceiveCountTest`
Expected: PASS — 5 tests

Run: `cd back-end && php artisan test tests/Feature/Inventory`
Expected: PASS — no regressions

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/ReceiveCountController.php back-end/routes/api.php back-end/tests/Feature/Inventory/ReceiveCountTest.php
git commit -m "feat(receiving): add receive-by-count endpoint"
```

---

### Task 10: Route the scan path through the shared service

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/InventoryScanTransactionController.php:330-470`
- Test: `back-end/tests/Feature/Inventory/ScanPathTagsTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/ScanPathTagsTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScanPathTagsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_scanned_supplier_barcode_gets_one_tag_carrying_both_identifiers(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Bolt M8',
            'product_type' => 'installation_material',
        ]);
        // count_unit = piece, but the SCAN path must still mint one tag per scan.
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 100,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'piece',
        ]);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 10,
            'unit_price' => 5, 'subtotal' => 50,
        ]);

        $supplier = 'SUP-' . uniqid();
        $res = $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => $supplier,
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'po_id'            => $po->po_id,
            'po_detail_id'     => $poDetail->po_detail_id,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'applied');

        $batch = InventoryBatch::where('product_id', $product->product_id)->first();
        $tags  = ItemSerial::where('batch_id', $batch->batch_id)->get();

        $this->assertCount(1, $tags, 'scan path mints one tag per scan regardless of count_unit');
        $this->assertSame($supplier, $tags[0]->supplier_barcode);
        $this->assertStringStartsWith('INT-', $tags[0]->serial_number);

        $this->assertSame('scanned', DB::table('receiving_details')
            ->where('po_detail_id', $poDetail->po_detail_id)->value('entry_mode'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=ScanPathTagsTest`
Expected: FAIL — `Failed asserting that actual size 0 matches expected size 1`; the scan path does not mint tags yet.

- [ ] **Step 3: Replace the scan path's posting block**

In `processReceiving()`, delete the inline inventory write, header resolution, batch
accumulation, `receiving_details` create and `ItemSerial` create (lines ~330-434) and
replace with:

```php
        $overage = $poDetail
            ? app(ReceivingPostingService::class)->checkOverage($poDetail, $product, $quantity)
            : null;
        if ($overage !== null && ! $request->boolean('accept_overage')) {
            return $this->success($overage, 'Over-receipt requires confirmation');
        }

        DB::beginTransaction();
        try {
            Inventory::where('location_id', $locationId)
                ->where('product_id', $product->product_id)
                ->lockForUpdate()
                ->first();

            $result = app(ReceivingPostingService::class)->post(
                product: $product,
                locationId: $locationId,
                pieces: $quantity,
                entryMode: 'scanned',
                poDetail: $poDetail,
                supplierBarcode: $scannedBarcode ?: null,
                zoneLabel: $validated['zone_label'] ?? null,
                userId: $request->user()?->user_id,
                unitsEntered: 1,
            );
            $receiving = $result['receiving'];
            $inv       = Inventory::where('location_id', $locationId)
                ->where('product_id', $product->product_id)
                ->first();
```

Also **delete** the `$quantity = min($quantity, $remaining);` clamp at line ~310 — the
`checkOverage()` call above replaces it. Keep everything below the posting block (PO status
sync, audit trail, response payload) unchanged.

Add to the imports:

```php
use App\Services\ReceivingPostingService;
```

- [ ] **Step 4: Run the affected suites**

Run: `cd back-end && php artisan test --filter=ScanPathTagsTest`
Expected: PASS

Run: `cd back-end && php artisan test tests/Feature/Inventory tests/Feature/Warranty tests/Feature/Aircon`
Expected: PASS — appliance receiving and warranty serial lookup must be untouched. If
`SerialLookupTest` fails, `mintIdentifiers()` is minting an `INT-` tag for an appliance
instead of storing the scanned serial.

- [ ] **Step 5: Commit**

```bash
git add back-end/app back-end/tests/Feature/Inventory/ScanPathTagsTest.php
git commit -m "refactor(receiving): route scan path through ReceivingPostingService and mint tags"
```

---

## Phase 4 — Transfers

### Task 11: Make `barcode_scans.barcode` nullable

**Files:**
- Create: `back-end/database/migrations/2026_08_30_000004_make_barcode_scans_barcode_nullable.php`
- Test: `back-end/tests/Feature/Transfer/CountedScanRowTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Transfer/CountedScanRowTest.php`:

```php
<?php

namespace Tests\Feature\Transfer;

use App\Models\BarcodeScan;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CountedScanRowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_counted_move_can_record_a_scan_row_without_a_barcode(): void
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);

        $scan = BarcodeScan::create([
            'product_id' => $product->product_id,
            'barcode'    => null,
            'scan_mode'  => 'receive',
            'quantity'   => 300,
            'scanned_at' => now(),
        ]);

        $this->assertNull($scan->fresh()->barcode);
        $this->assertSame(300, (int) $scan->fresh()->quantity);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=CountedScanRowTest`
Expected: FAIL — SQL integrity constraint violation, `barcode` cannot be null

- [ ] **Step 3: Write the migration**

Create `2026_08_30_000004_make_barcode_scans_barcode_nullable.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Counted transfer moves have no barcode, but they still need a barcode_scans row:
 * removeShipScan/removeReceiveScan reverse an exact amount by looking that row up.
 * Without one, a counted transfer line would be un-undoable.
 *
 * Raw SQL rather than Schema::table()->change() — doctrine/dbal is not installed and
 * change() on an enum-adjacent table risks rewriting unrelated column definitions.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `barcode_scans` MODIFY `barcode` VARCHAR(191) NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE `barcode_scans` SET `barcode` = '' WHERE `barcode` IS NULL");
        DB::statement('ALTER TABLE `barcode_scans` MODIFY `barcode` VARCHAR(191) NOT NULL');
    }
};
```

Confirm `'barcode'` is in `BarcodeScan::$fillable`; add it if not.

- [ ] **Step 4: Migrate and run the test**

Run: `cd back-end && php artisan migrate && php artisan test --filter=CountedScanRowTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add back-end/database/migrations back-end/app/Models/BarcodeScan.php back-end/tests/Feature/Transfer/CountedScanRowTest.php
git commit -m "feat(schema): allow barcode_scans rows without a barcode"
```

---

### Task 12: `countShip()` and `countReceive()`

**Files:**
- Modify: `back-end/app/Services/TransferService.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/Transfer/CountedTransferFlowTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Transfer/CountedTransferFlowTest.php`:

```php
<?php

namespace Tests\Feature\Transfer;

use App\Models\ConsumableDetail;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Services\TransferService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CountedTransferFlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_an_unbarcoded_line_ships_and_receives_by_count_keeping_packages_correct(): void
    {
        $source = Location::create([
            'location_code' => 'SRC-' . uniqid(), 'location_name' => 'Source', 'location_type' => 'warehouse',
        ]);
        $dest = Location::create([
            'location_code' => 'DST-' . uniqid(), 'location_name' => 'Dest', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);

        Inventory::create([
            'location_id' => $source->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 500, 'packages_on_hand' => 10,
            'available_quantity' => 500, 'reorder_level' => 0,
        ]);

        $ist = Transfer::create([
            'transfer_number' => 'IST-' . uniqid(),
            'from_location_id' => $source->location_id,
            'to_location_id' => $dest->location_id,
            'status_id' => $this->approvedStatusId(),
            'total_quantity_transferred' => 0,
        ]);
        $detail = TransferDetail::create([
            'transfer_id' => $ist->transfer_id, 'product_id' => $product->product_id,
            'quantity_requested' => 200, 'quantity_scanned' => 0,
            'quantity_transferred' => 0, 'quantity_received' => 0,
        ]);

        $svc = app(TransferService::class);

        $svc->countShip($ist, (int) $detail->transfer_detail_id, 200, null);
        $this->assertSame(200, (int) $detail->fresh()->quantity_scanned);

        $svc->dispatch($ist->fresh());

        $src = Inventory::where('location_id', $source->location_id)
            ->where('product_id', $product->product_id)->first();
        $this->assertSame(300, $src->quantity_on_hand);
        $this->assertSame(6, $src->packages_on_hand, 'transfers must now maintain packages_on_hand');

        $svc->countReceive($ist->fresh(), (int) $detail->transfer_detail_id, 200, null);
        $svc->completeReceive($ist->fresh());

        $dst = Inventory::where('location_id', $dest->location_id)
            ->where('product_id', $product->product_id)->first();
        $this->assertSame(200, $dst->quantity_on_hand);
        $this->assertSame(4, $dst->packages_on_hand);
    }

    private function approvedStatusId(): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('status_lookup')
            ->where('status_name', 'Approved')->value('status_id');
    }
}
```

**Note:** if `TransferService`'s receive-completion method is named something other than
`completeReceive`, use the real name — check `TransferService` for the method that
increments destination inventory around line 368. `TransferTestCase.php` in the same
directory shows the established scaffolding if this setup drifts from convention.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=CountedTransferFlowTest`
Expected: FAIL — `Call to undefined method App\Services\TransferService::countShip()`

- [ ] **Step 3: Add the two methods**

In `back-end/app/Services/TransferService.php`, add:

```php
    /**
     * Ship an unbarcoded line by count. Mirrors scanShip()'s guards but resolves the
     * line from its ID rather than a barcode, and writes a barcode_scans row with a
     * null barcode so removeShipScan() can still reverse the exact amount.
     */
    public function countShip(Transfer $ist, int $transferDetailId, int $qty, ?int $userId = null): array
    {
        return DB::transaction(function () use ($ist, $transferDetailId, $qty, $userId) {
            $detail = TransferDetail::where('transfer_detail_id', $transferDetailId)
                ->where('transfer_id', $ist->transfer_id)
                ->with('product')
                ->lockForUpdate()
                ->first();

            if (! $detail) {
                throw new RuntimeException('Line item does not belong to this transfer.');
            }
            if ($this->isSerialized($detail)) {
                throw new RuntimeException('Serialized items must be scanned individually.');
            }

            $target    = (int) $detail->quantity_requested;
            $current   = (int) $detail->quantity_scanned;
            $step      = min(max(1, $qty), $target - $current);

            if ($step < 1) {
                throw new RuntimeException('This transfer line is already fully scanned.');
            }

            $scan = BarcodeScan::create([
                'user_id'     => $userId,
                'product_id'  => $detail->product_id,
                'transfer_id' => $ist->transfer_id,
                'barcode'     => null,
                'scan_mode'   => 'transfer',
                'quantity'    => $step,
                'scanned_at'  => now(),
            ]);

            $detail->quantity_scanned = $current + $step;
            $detail->save();

            return [
                'scan_id'            => $scan->id,
                'transfer_detail_id' => (int) $detail->transfer_detail_id,
                'total_scanned'      => (int) $detail->quantity_scanned,
                'remaining'          => $target - (int) $detail->quantity_scanned,
                'quantity'           => $step,
            ];
        });
    }

    /**
     * Receive an unbarcoded line by count. Reuses scanReceive()'s over-receive guard.
     */
    public function countReceive(Transfer $ist, int $transferDetailId, int $qty, ?int $userId = null): array
    {
        return DB::transaction(function () use ($ist, $transferDetailId, $qty, $userId) {
            $detail = TransferDetail::where('transfer_detail_id', $transferDetailId)
                ->where('transfer_id', $ist->transfer_id)
                ->with('product')
                ->lockForUpdate()
                ->first();

            if (! $detail) {
                throw new RuntimeException('Line item does not belong to this transfer.');
            }
            if ($this->isSerialized($detail)) {
                throw new RuntimeException('Serialized items must be scanned individually.');
            }

            $shipped = (int) $detail->quantity_transferred;
            $step    = min(max(1, $qty), $shipped - (int) $detail->quantity_received);

            if ($step < 1) {
                throw new RuntimeException('This transfer line is already fully received.');
            }

            $scan = BarcodeScan::create([
                'user_id'     => $userId,
                'product_id'  => $detail->product_id,
                'transfer_id' => $ist->transfer_id,
                'barcode'     => null,
                'scan_mode'   => 'receive',
                'quantity'    => $step,
                'scanned_at'  => now(),
            ]);

            $detail->quantity_received = (int) $detail->quantity_received + $step;
            $detail->save();

            return [
                'scan_id'            => $scan->id,
                'transfer_detail_id' => (int) $detail->transfer_detail_id,
                'quantity_received'  => (int) $detail->quantity_received,
                'quantity_shipped'   => $shipped,
                'remaining'          => $shipped - (int) $detail->quantity_received,
                'quantity'           => $step,
            ];
        });
    }
```

Add `use App\Models\BarcodeScan;` if it is not already imported.

**Also:** `removeShipScan()` and `removeReceiveScan()` currently locate the row by
`barcode`. Change both to accept and key on the `barcode_scans.id` instead, so counted rows
(whose barcode is null) can be reversed.

- [ ] **Step 4: Register the routes**

In `back-end/routes/api.php`, beside the existing transfer scan routes:

```php
    Route::post('/transfers/{id}/count-ship',    [TransferController::class, 'countShip']);
    Route::post('/transfers/{id}/count-receive', [TransferController::class, 'countReceive']);
```

Add the matching thin controller methods on `TransferController`, each validating
`transfer_detail_id` (required, integer) and `quantity` (required, integer, min 1), then
delegating to the service and returning `$this->success($result, 'Recorded')`.

- [ ] **Step 5: Run tests**

Run: `cd back-end && php artisan test --filter=CountedTransferFlowTest`
Expected: PASS

Run: `cd back-end && php artisan test tests/Feature/Transfer`
Expected: PASS — no regressions

- [ ] **Step 6: Commit**

```bash
git add back-end/app back-end/routes/api.php back-end/tests/Feature/Transfer/CountedTransferFlowTest.php
git commit -m "feat(transfers): ship and receive unbarcoded lines by count"
```

---

## Phase 5 — Strict FIFO enforcement

### Task 13: `IncidentItem::quarantinedByBatch()`

**Files:**
- Modify: `back-end/app/Models/IncidentItem.php`
- Test: `back-end/tests/Feature/Incidents/QuarantinedByBatchTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Incidents/QuarantinedByBatchTest.php`:

```php
<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentItem;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuarantinedByBatchTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_returns_quarantined_pieces_keyed_by_batch(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        $batch = InventoryBatch::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'quantity_received' => 100, 'quantity_remaining' => 100, 'received_at' => now(),
        ]);

        $incidentId = DB::table('incident_reports')->insertGetId([
            'incident_number' => 'INC-' . uniqid(),
            'location_id'     => $location->location_id,
            'reported_by'     => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        IncidentItem::create([
            'incident_id' => $incidentId,
            'product_id'  => $product->product_id,
            'batch_id'    => $batch->batch_id,
            'quantity'    => 40,
            'status'      => IncidentItem::STATUS_QUARANTINED,
            'is_inbound'  => false,
        ]);

        $result = IncidentItem::quarantinedByBatch((int) $location->location_id);

        $this->assertSame(40, $result[$batch->batch_id] ?? null);
    }
}
```

**Note:** `incident_reports` may require more non-null columns than shown. Check
`tests/Feature/Incidents/` for an existing incident-creation helper and reuse it rather than
inserting raw if one exists.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=QuarantinedByBatchTest`
Expected: FAIL — `Call to undefined method App\Models\IncidentItem::quarantinedByBatch()`

- [ ] **Step 3: Add the method**

In `back-end/app/Models/IncidentItem.php`, directly below `quarantinedQuantities()`:

```php
    /**
     * Quarantined pieces keyed by BATCH, not product.
     *
     * FIFO enforcement needs to know whether a specific batch is quarantined — if the
     * oldest batch is under an unsettled incident it must be skipped, or it blocks every
     * newer batch behind it forever. quarantinedQuantities() aggregates that detail away.
     *
     * @return array<int,int> batch_id => pieces
     */
    public static function quarantinedByBatch(int $locationId): array
    {
        return DB::table('incident_items as ii')
            ->join('incident_reports as ir', 'ir.incident_id', '=', 'ii.incident_id')
            ->where('ir.location_id', $locationId)
            ->where('ii.status', self::STATUS_QUARANTINED)
            ->where('ii.is_inbound', false)
            ->whereNotNull('ii.batch_id')
            ->groupBy('ii.batch_id')
            ->selectRaw('ii.batch_id, SUM(ii.quantity) as pieces')
            ->pluck('pieces', 'batch_id')
            ->map(fn ($pieces) => (int) $pieces)
            ->all();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=QuarantinedByBatchTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Models/IncidentItem.php back-end/tests/Feature/Incidents/QuarantinedByBatchTest.php
git commit -m "feat(incidents): expose quarantined quantities keyed by batch"
```

---

### Task 14: `FifoEnforcementService`

**Files:**
- Create: `back-end/app/Services/FifoEnforcementService.php`
- Test: `back-end/tests/Feature/Issuance/FifoEnforcementServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Issuance/FifoEnforcementServiceTest.php`:

```php
<?php

namespace Tests\Feature\Issuance;

use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use App\Services\FifoEnforcementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FifoEnforcementServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Location $location;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $this->product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
    }

    private function batch(string $receivedAt, int $remaining, ?int $receivingId = 1): InventoryBatch
    {
        return InventoryBatch::create([
            'product_id'         => $this->product->product_id,
            'location_id'        => $this->location->location_id,
            'receiving_id'       => $receivingId,
            'quantity_received'  => $remaining,
            'quantity_remaining' => $remaining,
            'received_at'        => $receivedAt,
            'zone_label'         => 'A-03',
        ]);
    }

    public function test_picking_the_oldest_batch_is_allowed(): void
    {
        $old = $this->batch('2026-03-12', 100);
        $this->batch('2026-04-02', 100);

        $violation = app(FifoEnforcementService::class)->check(
            (int) $this->product->product_id, (int) $this->location->location_id, (int) $old->batch_id
        );

        $this->assertNull($violation);
    }

    public function test_picking_a_newer_batch_is_refused_and_names_the_older_one(): void
    {
        $old = $this->batch('2026-03-12', 100);
        $new = $this->batch('2026-04-02', 100);

        $violation = app(FifoEnforcementService::class)->check(
            (int) $this->product->product_id, (int) $this->location->location_id, (int) $new->batch_id
        );

        $this->assertNotNull($violation);
        $this->assertSame('fifo_violation', $violation['action']);
        $this->assertSame($old->batch_id, $violation['expected_batch']['batch_id']);
        $this->assertSame('A-03', $violation['expected_batch']['zone_label'],
            'the response must tell staff where the right stock is');
    }

    public function test_the_legacy_batch_never_blocks_a_pick(): void
    {
        // receiving_id null and received_at null — MySQL sorts NULL first on ASC.
        InventoryBatch::create([
            'product_id' => $this->product->product_id, 'location_id' => $this->location->location_id,
            'receiving_id' => null, 'quantity_received' => 500, 'quantity_remaining' => 500,
            'received_at' => null,
        ]);
        $real = $this->batch('2026-04-02', 100);

        $violation = app(FifoEnforcementService::class)->check(
            (int) $this->product->product_id, (int) $this->location->location_id, (int) $real->batch_id
        );

        $this->assertNull($violation, 'no tag can point at the legacy batch, so it must be excluded');
    }

    public function test_an_exhausted_older_batch_does_not_block(): void
    {
        $this->batch('2026-03-12', 0);
        $new = $this->batch('2026-04-02', 100);

        $violation = app(FifoEnforcementService::class)->check(
            (int) $this->product->product_id, (int) $this->location->location_id, (int) $new->batch_id
        );

        $this->assertNull($violation);
    }

    public function test_tied_timestamps_resolve_deterministically_by_batch_id(): void
    {
        $first  = $this->batch('2026-03-12 08:00:00', 100);
        $second = $this->batch('2026-03-12 08:00:00', 100);

        $svc = app(FifoEnforcementService::class);

        $this->assertNull($svc->check(
            (int) $this->product->product_id, (int) $this->location->location_id, (int) $first->batch_id
        ));
        $this->assertNotNull($svc->check(
            (int) $this->product->product_id, (int) $this->location->location_id, (int) $second->batch_id
        ));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=FifoEnforcementServiceTest`
Expected: FAIL — `Target class [App\Services\FifoEnforcementService] does not exist.`

- [ ] **Step 3: Write the implementation**

Create `back-end/app/Services/FifoEnforcementService.php`:

```php
<?php

namespace App\Services;

use App\Models\IncidentItem;
use App\Models\InventoryBatch;
use Illuminate\Support\Facades\DB;

/**
 * Refuses a pick from a newer batch while an older eligible batch still has stock.
 *
 * FIFO was previously ledger-deep only: deductFifo() consumes oldest-first, so the
 * books were always FIFO, but nothing checked which physical item was taken. Digital
 * tags make the physical check possible, because every tag resolves to one batch.
 */
class FifoEnforcementService
{
    /**
     * @return array|null  null when the pick is FIFO-correct, otherwise the violation payload
     */
    public function check(int $productId, int $locationId, ?int $scannedBatchId): ?array
    {
        // No batch on the tag means we cannot judge — let it through rather than block
        // a picker over missing data.
        if ($scannedBatchId === null) {
            return null;
        }

        $oldest = $this->oldestEligibleBatch($productId, $locationId);

        if ($oldest === null || (int) $oldest->batch_id === $scannedBatchId) {
            return null;
        }

        $scanned = InventoryBatch::find($scannedBatchId);

        return [
            'action'        => 'fifo_violation',
            'scanned_batch' => [
                'batch_id'    => $scannedBatchId,
                'received_at' => $scanned?->received_at,
            ],
            'expected_batch' => [
                'batch_id'    => (int) $oldest->batch_id,
                'received_at' => $oldest->received_at,
                'po_number'   => $this->poNumberFor($oldest),
                'zone_label'  => $oldest->zone_label,
                'remaining'   => (int) $oldest->quantity_remaining,
            ],
        ];
    }

    /**
     * Eligible means: traceable (not the legacy catch-all batch), still holding stock,
     * and not fully quarantined by an unsettled incident.
     *
     * Excluding receiving_id IS NULL matters more than it looks: the legacy batch has a
     * null received_at, MySQL sorts NULL first on ASC, and no tag can ever point at it —
     * so including it would block every pick of every material forever.
     */
    private function oldestEligibleBatch(int $productId, int $locationId): ?InventoryBatch
    {
        $quarantined = IncidentItem::quarantinedByBatch($locationId);

        $batches = InventoryBatch::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->whereNotNull('receiving_id')
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at', 'asc')
            ->orderBy('batch_id', 'asc')      // deterministic tie-break
            ->get();

        foreach ($batches as $batch) {
            $held = (int) ($quarantined[$batch->batch_id] ?? 0);
            if ((int) $batch->quantity_remaining - $held > 0) {
                return $batch;
            }
        }

        return null;
    }

    private function poNumberFor(InventoryBatch $batch): ?string
    {
        if (! $batch->receiving_id) {
            return null;
        }

        return DB::table('receivings')
            ->join('purchase_orders', 'purchase_orders.po_id', '=', 'receivings.po_id')
            ->where('receivings.receiving_id', $batch->receiving_id)
            ->value('purchase_orders.po_number');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=FifoEnforcementServiceTest`
Expected: PASS — 5 tests

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/FifoEnforcementService.php back-end/tests/Feature/Issuance/FifoEnforcementServiceTest.php
git commit -m "feat(fifo): add batch-level FIFO enforcement service"
```

---

### Task 15: Wire FIFO enforcement into issuance with an override

**Files:**
- Create: `back-end/database/migrations/2026_08_30_000005_add_fifo_override_reason_to_issuance_details.php`
- Create: `back-end/database/migrations/2026_08_30_000006_add_fifo_override_permission.php`
- Modify: `back-end/app/Http/Controllers/Api/IssuanceScanController.php` (barcode resolution + the scan entry point)
- Test: `back-end/tests/Feature/Issuance/FifoOverrideTest.php`

- [ ] **Step 1: Write the migrations**

`2026_08_30_000005_add_fifo_override_reason_to_issuance_details.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A column rather than audit-trail-only: the audit trail is a JSON blob, fine for
 * forensics but painful for "show me every FIFO override last month", which is the
 * report an auditor will ask for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issuance_details', function (Blueprint $table) {
            $table->string('fifo_override_reason', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('issuance_details', function (Blueprint $table) {
            $table->dropColumn('fifo_override_reason');
        });
    }
};
```

`2026_08_30_000006_add_fifo_override_permission.php` — follow the exact pattern in
`2026_08_17_000002_add_report_viewing_create_permission.php`: insert a `permissions` row with
`feature = 'inventory_issuance'`, `action = 'fifo_override'`, then write a `role_permissions`
row for **every** role with `is_granted` true only for the roles that already hold
`inventory_issuance.update`. Use `DB::table(...)`, never Eloquent models.

- [ ] **Step 2: Write the failing test**

Create `back-end/tests/Feature/Issuance/FifoOverrideTest.php`:

```php
<?php

namespace Tests\Feature\Issuance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FifoOverrideTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_override_column_and_permission_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('issuance_details', 'fifo_override_reason'));

        $this->assertDatabaseHas('permissions', [
            'feature' => 'inventory_issuance',
            'action'  => 'fifo_override',
        ]);
    }
}
```

Then add an integration test in the same file that builds two batches, tags one item from
each (reuse the scaffolding from `FifoEnforcementServiceTest` plus a `MaterialTagService`
call to mint the tags), and asserts:

1. Scanning the newer tag returns `data.action = 'fifo_violation'` and issues nothing.
2. Re-posting with `accept_fifo_override = true` and `override_reason = 'Older lot buried behind rack'` **without** the permission is refused.
3. The same request **with** the permission succeeds and persists `fifo_override_reason` on the `issuance_details` row.

- [ ] **Step 3: Run to verify it fails**

Run: `cd back-end && php artisan migrate && php artisan test --filter=FifoOverrideTest`
Expected: FAIL on the integration assertions — enforcement is not wired in yet.

- [ ] **Step 4: Wire it into `IssuanceScanController::scanItem()`**

Extend the validation rules:

```php
            'accept_fifo_override' => 'nullable|boolean',
            'override_reason'      => 'nullable|string|min:10|max:255',
```

Make barcode resolution check both identifier columns. Where the controller resolves the
scanned value to an `ItemSerial`, match on either:

```php
        $tag = ItemSerial::where(function ($q) use ($raw) {
                $q->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower(trim($raw))])
                  ->orWhereRaw('LOWER(TRIM(COALESCE(supplier_barcode, ""))) = ?', [strtolower(trim($raw))]);
            })
            ->where('location_id', $locationId)
            ->first();
```

Then, in the consumable branch immediately before stock is deducted:

```php
        $violation = app(FifoEnforcementService::class)->check(
            (int) $product->product_id,
            (int) $locationId,
            $tag?->batch_id ? (int) $tag->batch_id : null
        );

        if ($violation !== null) {
            if (! $request->boolean('accept_fifo_override')) {
                return response()->json(['success' => false] + $violation);
            }

            $reason = trim((string) $request->input('override_reason'));
            if (strlen($reason) < 10) {
                return response()->json([
                    'success' => false,
                    'action'  => 'rejected',
                    'reason'  => 'A reason of at least 10 characters is required to override FIFO.',
                ]);
            }

            if (! $request->user()?->hasPermission('inventory_issuance', 'fifo_override')) {
                return response()->json([
                    'success' => false,
                    'action'  => 'rejected',
                    'reason'  => 'You do not have permission to override FIFO.',
                ]);
            }

            $fifoOverrideReason = $reason;
        }
```

Pass `$fifoOverrideReason ?? null` into the `IssuanceDetail::create(...)` payload as
`'fifo_override_reason'`, and add that key to `IssuanceDetail::$fillable`.

**Check the permission-check helper's real name** before writing this — search for
`hasPermission` in `app/Models/User.php` or `app/Services/PermissionResolver.php` and use
whatever the codebase actually exposes.

- [ ] **Step 5: Run tests**

Run: `cd back-end && php artisan test --filter=FifoOverrideTest`
Expected: PASS

Run: `cd back-end && php artisan test tests/Feature/Issuance tests/Feature/Permissions tests/Feature/Rbac`
Expected: PASS — no regressions

- [ ] **Step 6: Commit**

```bash
git add back-end/app back-end/database/migrations back-end/tests/Feature/Issuance/FifoOverrideTest.php
git commit -m "feat(fifo): enforce FIFO at issuance with a permissioned override"
```

---

### Task 16: Gate `pickItem` behind the same permission

The spec calls this out explicitly: `pickItem` identifies a product without naming a
physical item, so it cannot be FIFO-checked and is therefore a route around enforcement. It
must not be freely available or the enforcement is decorative.

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/IssuanceScanController.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/Issuance/PickItemGateTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Issuance/PickItemGateTest.php` asserting that a user
**without** `inventory_issuance.fifo_override` receives a 403 from
`POST /api/issuance-scan/pick`, and a user **with** it succeeds. Build the issuance and
product scaffolding the same way `FifoEnforcementServiceTest` does.

- [ ] **Step 2: Run to verify it fails**

Run: `cd back-end && php artisan test --filter=PickItemGateTest`
Expected: FAIL — 404, the route does not exist

- [ ] **Step 3: Add `pickItem`**

Add a `pickItem(Request $request)` method to `IssuanceScanController` that validates
`issuance_id`, `product_id`, `quantity` and `quantity_unit`, then runs the **same**
downstream logic as `scanItem`'s consumable branch — extract that branch into a private
method both call, rather than duplicating it. It performs no FIFO check, because
`deductFifo()` is oldest-first by construction.

Register it behind the permission middleware:

```php
    Route::middleware('permission:inventory_issuance,fifo_override')
         ->post('/issuance-scan/pick', [IssuanceScanController::class, 'pickItem']);
```

- [ ] **Step 4: Run tests**

Run: `cd back-end && php artisan test tests/Feature/Issuance`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add back-end/app back-end/routes/api.php back-end/tests/Feature/Issuance/PickItemGateTest.php
git commit -m "feat(issuance): add permissioned pickItem path for untagged stock"
```

---

## Phase 6 — Frontend

### Task 17: API client methods

**Files:**
- Modify: `frontend/src/admin/services/api.js`

- [ ] **Step 1: Add the three methods**

Find the `inventoryAPI` object (it already exposes `scanTransaction`) and add:

```js
  /**
   * Receive an unbarcoded PO line by counting.
   * Returns { action: 'applied' | 'over_receipt' | 'rejected', ... }.
   * On 'over_receipt' the caller must re-post with acceptOverage: true to proceed.
   */
  receiveCount: (payload) =>
    api.post('/inventory/receive-count', payload).then((r) => r.data),
```

In the transfer API object:

```js
  countShip: (transferId, payload) =>
    api.post(`/transfers/${transferId}/count-ship`, payload).then((r) => r.data),

  countReceive: (transferId, payload) =>
    api.post(`/transfers/${transferId}/count-receive`, payload).then((r) => r.data),
```

In the issuance API object:

```js
  pickItem: (payload) =>
    api.post('/issuance-scan/pick', payload).then((r) => r.data),
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -m "feat(api): add receive-count, count-transfer and pick endpoints"
```

---

### Task 18: Count entry panel

**Files:**
- Create: `frontend/src/admin/components/receiving/CountEntryPanel.jsx`
- Test: `frontend/src/admin/components/receiving/CountEntryPanel.test.jsx`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/components/receiving/CountEntryPanel.test.jsx`:

```jsx
import { render, screen, fireEvent } from '@testing-library/react';
import CountEntryPanel from './CountEntryPanel';

const line = {
  po_detail_id: 42,
  product_name: 'PE Foam 1/2"',
  count_unit: 'package',
  package_unit: 'Box',
  pieces_per_package: 50,
  ordered_pieces: 1000,
  already_received: 300,
};

describe('CountEntryPanel', () => {
  it('asks for packages when count_unit is package and shows the piece conversion', () => {
    render(<CountEntryPanel line={line} onSubmit={jest.fn()} onCancel={jest.fn()} />);

    expect(screen.getByText(/How many Boxes/i)).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/count/i), { target: { value: '6' } });
    expect(screen.getByText(/300 pcs/)).toBeInTheDocument();
  });

  it('asks for pieces when count_unit is piece and does not multiply', () => {
    render(
      <CountEntryPanel line={{ ...line, count_unit: 'piece' }} onSubmit={jest.fn()} onCancel={jest.fn()} />
    );

    expect(screen.getByText(/How many pieces/i)).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/count/i), { target: { value: '473' } });
    expect(screen.getByText(/473 pcs/)).toBeInTheDocument();
  });

  it('shows remaining so a short delivery is visible while counting', () => {
    render(<CountEntryPanel line={line} onSubmit={jest.fn()} onCancel={jest.fn()} />);

    expect(screen.getByText(/700 pcs remaining/i)).toBeInTheDocument();
  });

  it('submits the entered units, not the converted pieces', () => {
    const onSubmit = jest.fn();
    render(<CountEntryPanel line={line} onSubmit={onSubmit} onCancel={jest.fn()} />);

    fireEvent.change(screen.getByLabelText(/count/i), { target: { value: '6' } });
    fireEvent.click(screen.getByRole('button', { name: /post count/i }));

    expect(onSubmit).toHaveBeenCalledWith(6);
  });

  it('refuses to submit a zero or empty count', () => {
    const onSubmit = jest.fn();
    render(<CountEntryPanel line={line} onSubmit={onSubmit} onCancel={jest.fn()} />);

    fireEvent.click(screen.getByRole('button', { name: /post count/i }));
    expect(onSubmit).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx react-scripts test --testPathPattern=CountEntryPanel --watchAll=false`
Expected: FAIL — cannot resolve `./CountEntryPanel`

- [ ] **Step 3: Write the component**

Create `frontend/src/admin/components/receiving/CountEntryPanel.jsx`:

```jsx
import { useState, useMemo } from 'react';

/**
 * Count entry for a delivery that arrived without usable barcodes.
 *
 * The prompt follows the product's count_unit — packages for things counted by the
 * box, pieces for loose goods. Live remaining feedback is the point of the feature:
 * it is what lets a clerk catch a short delivery at the moment of counting.
 */
export default function CountEntryPanel({ line, onSubmit, onCancel, busy = false }) {
  const [value, setValue] = useState('');

  const byPiece = line.count_unit === 'piece';
  const ppp = Number(line.pieces_per_package) || 1;
  const units = Number(value) || 0;

  const pieces = useMemo(() => (byPiece ? units : units * ppp), [byPiece, units, ppp]);

  const remaining = Math.max(
    0,
    (Number(line.ordered_pieces) || 0) - (Number(line.already_received) || 0)
  );

  const unitLabel = byPiece ? 'pieces' : `${line.package_unit || 'Package'}es`;

  const submit = () => {
    if (units > 0 && !busy) onSubmit(units);
  };

  return (
    <div className="count-entry-panel">
      <h4>{line.product_name}</h4>

      <label htmlFor="count-entry-input">
        How many {unitLabel}?
      </label>
      <input
        id="count-entry-input"
        type="number"
        min="1"
        inputMode="numeric"
        value={value}
        onChange={(e) => setValue(e.target.value)}
        onKeyDown={(e) => e.key === 'Enter' && submit()}
        autoFocus
      />

      <p className="count-entry-conversion">
        = {pieces} pcs
        {!byPiece && ppp > 1 ? ` (${ppp} per ${line.package_unit || 'package'})` : ''}
      </p>

      <p className="count-entry-remaining">{remaining} pcs remaining on this line</p>

      {pieces > remaining && remaining > 0 && (
        <p className="count-entry-warning" role="alert">
          That is {pieces - remaining} pcs more than ordered — you will be asked to confirm.
        </p>
      )}

      <div className="count-entry-actions">
        <button type="button" onClick={submit} disabled={busy || units <= 0}>
          Post count
        </button>
        <button type="button" onClick={onCancel} disabled={busy}>
          Cancel
        </button>
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx react-scripts test --testPathPattern=CountEntryPanel --watchAll=false`
Expected: PASS — 5 tests

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/receiving/
git commit -m "feat(receiving): add count entry panel"
```

---

### Task 19: Wire the escape hatch into the receiving screen

**Files:**
- Modify: `frontend/src/admin/pages/BarcodeScan.jsx` (the active-line panel, around the scan controls near line 3360)
- Modify: `frontend/src/admin/styles/po_receiving.css`

- [ ] **Step 1: Add the state and handler**

Near the other `bsr*` state declarations, add:

```jsx
  const [bsrCountMode, setBsrCountMode] = useState(false);
  const [bsrPendingOverage, setBsrPendingOverage] = useState(null);
```

Add the submit handler beside `bsrHandleScan`:

```jsx
  const bsrPostCount = useCallback(async (units, acceptOverage = false) => {
    if (!bsrActiveLine) return;

    try {
      const res = await inventoryAPI.receiveCount({
        po_detail_id:   bsrActiveLine.po_detail_id,
        location_id:    myLocationId,
        quantity:       units,
        zone_label:     bsrZoneLabel || undefined,
        accept_overage: acceptOverage || undefined,
      });

      const data = res?.data ?? res;

      // The backend refuses an over-receipt on first submit and asks for confirmation.
      if (data?.action === 'over_receipt') {
        setBsrPendingOverage({ ...data, units });
        return;
      }

      if (data?.action === 'rejected') {
        setBsrScanError(data.reason || 'Count rejected.');
        return;
      }

      setBsrPendingOverage(null);
      setBsrCountMode(false);
      await refreshPoScanStatus();
    } catch (err) {
      setBsrScanError(err?.response?.data?.message || 'Failed to post count.');
    }
  }, [bsrActiveLine, myLocationId, bsrZoneLabel, refreshPoScanStatus]);
```

Use the real names for `bsrZoneLabel`, `bsrScanError`, `myLocationId` and the PO-status
refresh function — check the surrounding code, these are the shapes the file already uses
around line 2038.

- [ ] **Step 2: Add the button and panel to the active-line JSX**

Beside the existing Scan button:

```jsx
  {!bsrActiveLine?.is_serialized && (
    <button
      type="button"
      className="bsr-count-toggle"
      onClick={() => setBsrCountMode(true)}
    >
      Enter count instead
    </button>
  )}
```

The `is_serialized` guard matters: appliances must never reach the count path, and hiding
the control is clearer than showing it and rejecting the request.

Then, conditionally:

```jsx
  {bsrCountMode && bsrActiveLine && (
    <CountEntryPanel
      line={bsrActiveLine}
      onSubmit={(units) => bsrPostCount(units)}
      onCancel={() => { setBsrCountMode(false); setBsrPendingOverage(null); }}
    />
  )}

  {bsrPendingOverage && (
    <div className="bsr-overage-confirm" role="alertdialog">
      <p>
        You counted {bsrPendingOverage.counted} pcs but only{' '}
        {bsrPendingOverage.remaining} pcs remain on this line
        ({bsrPendingOverage.overage} pcs over).
      </p>
      <button type="button" onClick={() => bsrPostCount(bsrPendingOverage.units, true)}>
        Confirm over-receipt
      </button>
      <button type="button" onClick={() => setBsrPendingOverage(null)}>
        Re-count
      </button>
    </div>
  )}
```

Import `CountEntryPanel` at the top of the file.

- [ ] **Step 3: Handle `over_receipt` on the SCAN path too**

Task 10 made the scan endpoint return `over_receipt` as well. Find the existing scan
response handler (around line 2038, where `data.action` is checked) and add the same
`over_receipt` branch before the success handling — otherwise a surplus scanned box fails
silently:

```jsx
      if (data?.action === 'over_receipt') {
        setBsrPendingOverage({ ...data, units: 1, viaScan: true });
        return;
      }
```

- [ ] **Step 4: Add the styles**

Append to `frontend/src/admin/styles/po_receiving.css`:

```css
.bsr-count-toggle { opacity: 0.85; }

.count-entry-panel { padding: 12px; border: 1px solid var(--border, #ddd); border-radius: 8px; }
.count-entry-panel input { width: 100%; font-size: 1.3rem; text-align: center; padding: 8px; }
.count-entry-conversion { font-weight: 600; margin: 8px 0 2px; }
.count-entry-remaining { opacity: 0.75; font-size: 0.9rem; margin: 0; }
.count-entry-warning { color: #c0392b; font-size: 0.9rem; }
.count-entry-actions { display: flex; gap: 8px; margin-top: 10px; }
.count-entry-actions button { flex: 1; }

.bsr-overage-confirm { margin-top: 10px; padding: 12px; border: 1px solid #c0392b; border-radius: 8px; }
```

- [ ] **Step 5: Verify by hand**

Run: `cd frontend && npm start`

Open a verified PO with an installation-material line, select the line, click **Enter count
instead**, and post a count. Check that:
- the prompt matches the product's `count_unit`
- remaining updates after posting
- counting more than remaining shows the confirm step rather than silently clamping

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/BarcodeScan.jsx frontend/src/admin/styles/po_receiving.css
git commit -m "feat(receiving): add Enter count instead to the receiving screen"
```

---

### Task 20: Tag rendering and printing

**Files:**
- Create: `frontend/src/admin/components/tags/MaterialTagSheet.jsx`
- Test: `frontend/src/admin/components/tags/MaterialTagSheet.test.jsx`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/components/tags/MaterialTagSheet.test.jsx`:

```jsx
import { render, screen } from '@testing-library/react';
import MaterialTagSheet from './MaterialTagSheet';

jest.mock('react-barcode', () => (props) => <div data-testid="barcode">{props.value}</div>);

const tags = [
  { serial_number: 'INT-PO2401-4471-01', supplier_barcode: null },
  { serial_number: 'INT-PO2401-4471-02', supplier_barcode: 'SUP-9911' },
];

describe('MaterialTagSheet', () => {
  it('renders one scannable barcode per tag', () => {
    render(<MaterialTagSheet tags={tags} productName="PE Foam" />);
    expect(screen.getAllByTestId('barcode')).toHaveLength(2);
  });

  it('renders the generated serial as the scannable value, not the supplier barcode', () => {
    render(<MaterialTagSheet tags={tags} productName="PE Foam" />);
    expect(screen.getByText('INT-PO2401-4471-02')).toBeInTheDocument();
  });

  it('shows the supplier barcode as reference text when one exists', () => {
    render(<MaterialTagSheet tags={tags} productName="PE Foam" />);
    expect(screen.getByText(/SUP-9911/)).toBeInTheDocument();
  });

  it('renders nothing when there are no tags', () => {
    const { container } = render(<MaterialTagSheet tags={[]} productName="PE Foam" />);
    expect(container).toBeEmptyDOMElement();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx react-scripts test --testPathPattern=MaterialTagSheet --watchAll=false`
Expected: FAIL — cannot resolve `./MaterialTagSheet`

- [ ] **Step 3: Write the component**

Create `frontend/src/admin/components/tags/MaterialTagSheet.jsx`:

```jsx
import Barcode from 'react-barcode';

/**
 * Renders the digital tags minted for a receipt.
 *
 * Rendering on screen is the point, not just printing: when a physical label is
 * missing, damaged or unreadable, staff scan the barcode straight off the monitor.
 * The scannable value is always the generated INT- serial — the supplier barcode is
 * shown as reference text only, because it is not unique across boxes.
 */
export default function MaterialTagSheet({ tags, productName, onPrint }) {
  if (!tags || tags.length === 0) return null;

  return (
    <div className="material-tag-sheet">
      <div className="material-tag-sheet__header no-print">
        <h4>{tags.length} tag{tags.length === 1 ? '' : 's'} for {productName}</h4>
        <button type="button" onClick={onPrint || (() => window.print())}>
          Print tags
        </button>
      </div>

      <div className="material-tag-sheet__grid">
        {tags.map((tag) => (
          <div className="material-tag" key={tag.serial_number}>
            <div className="material-tag__product">{productName}</div>
            <Barcode
              value={tag.serial_number}
              format="CODE128"
              height={48}
              fontSize={12}
              margin={4}
            />
            {tag.supplier_barcode && (
              <div className="material-tag__supplier">Supplier: {tag.supplier_barcode}</div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Add print styles**

Append to `frontend/src/admin/styles/po_receiving.css`:

```css
.material-tag-sheet__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
.material-tag { border: 1px solid #ccc; border-radius: 6px; padding: 8px; text-align: center; break-inside: avoid; }
.material-tag__product { font-weight: 600; font-size: 0.85rem; margin-bottom: 4px; }
.material-tag__supplier { font-size: 0.75rem; opacity: 0.7; }

@media print {
  .no-print { display: none !important; }
  .material-tag-sheet__grid { grid-template-columns: repeat(3, 1fr); }
}
```

- [ ] **Step 5: Surface it after a successful receipt**

In `BarcodeScan.jsx`, have both `bsrPostCount` and the scan handler store the returned tags
and render `<MaterialTagSheet tags={bsrLastTags} productName={bsrActiveLine?.product_name} />`
in the success panel.

**This requires a backend change:** `ReceiveCountController` and the scan response must
return the minted tags. Add to both response payloads:

```php
            'tags' => collect($result['tags'] ?? [])
                ->map(fn ($t) => [
                    'serial_number'    => $t->serial_number,
                    'supplier_barcode' => $t->supplier_barcode,
                ])->values()->all(),
```

and have `ReceivingPostingService::post()` return the minted tags in its array —
change `mintIdentifiers()` to return `ItemSerial[]` and include them as
`'tags' => $tags` in `post()`'s return value.

- [ ] **Step 6: Run tests**

Run: `cd frontend && npx react-scripts test --testPathPattern=MaterialTagSheet --watchAll=false`
Expected: PASS — 4 tests

Run: `cd back-end && php artisan test tests/Feature/Inventory`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin back-end/app
git commit -m "feat(tags): render and print scannable material tags"
```

---

### Task 21: FIFO violation dialog

**Files:**
- Create: `frontend/src/admin/components/issuance/FifoViolationDialog.jsx`
- Test: `frontend/src/admin/components/issuance/FifoViolationDialog.test.jsx`
- Modify: `frontend/src/admin/pages/IssuanceScanPage.jsx`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/components/issuance/FifoViolationDialog.test.jsx`:

```jsx
import { render, screen, fireEvent } from '@testing-library/react';
import FifoViolationDialog from './FifoViolationDialog';

const violation = {
  expected_batch: {
    batch_id: 640,
    received_at: '2026-03-12',
    po_number: 'PO-2401',
    zone_label: 'A-03',
    remaining: 140,
  },
};

describe('FifoViolationDialog', () => {
  it('tells staff where the correct stock is', () => {
    render(<FifoViolationDialog violation={violation} canOverride onOverride={jest.fn()} onCancel={jest.fn()} />);

    expect(screen.getByText(/A-03/)).toBeInTheDocument();
    expect(screen.getByText(/PO-2401/)).toBeInTheDocument();
    expect(screen.getByText(/140/)).toBeInTheDocument();
  });

  it('hides the override control entirely without the permission', () => {
    render(<FifoViolationDialog violation={violation} canOverride={false} onOverride={jest.fn()} onCancel={jest.fn()} />);

    expect(screen.queryByRole('button', { name: /override/i })).not.toBeInTheDocument();
  });

  it('requires a reason of at least 10 characters before overriding', () => {
    const onOverride = jest.fn();
    render(<FifoViolationDialog violation={violation} canOverride onOverride={onOverride} onCancel={jest.fn()} />);

    fireEvent.click(screen.getByRole('button', { name: /override/i }));
    fireEvent.change(screen.getByLabelText(/reason/i), { target: { value: 'too short' } });
    fireEvent.click(screen.getByRole('button', { name: /confirm override/i }));

    expect(onOverride).not.toHaveBeenCalled();
  });

  it('passes the reason up when it is long enough', () => {
    const onOverride = jest.fn();
    render(<FifoViolationDialog violation={violation} canOverride onOverride={onOverride} onCancel={jest.fn()} />);

    fireEvent.click(screen.getByRole('button', { name: /override/i }));
    fireEvent.change(screen.getByLabelText(/reason/i), {
      target: { value: 'Older lot buried behind rack, supervisor approved' },
    });
    fireEvent.click(screen.getByRole('button', { name: /confirm override/i }));

    expect(onOverride).toHaveBeenCalledWith('Older lot buried behind rack, supervisor approved');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx react-scripts test --testPathPattern=FifoViolationDialog --watchAll=false`
Expected: FAIL — cannot resolve `./FifoViolationDialog`

- [ ] **Step 3: Write the component**

Create `frontend/src/admin/components/issuance/FifoViolationDialog.jsx`:

```jsx
import { useState } from 'react';

/**
 * Shown when a picker scans a newer batch while older stock remains.
 *
 * Telling staff "no" without telling them WHERE the right stock is, is how a FIFO rule
 * gets worked around — so the expected batch's zone, PO and remaining quantity are the
 * primary content, not the refusal.
 *
 * The override control is hidden entirely without the permission rather than shown and
 * rejected, so pickers are not trained to click something that never works.
 */
export default function FifoViolationDialog({ violation, canOverride, onOverride, onCancel }) {
  const [showOverride, setShowOverride] = useState(false);
  const [reason, setReason] = useState('');

  const expected = violation?.expected_batch;
  if (!expected) return null;

  const reasonValid = reason.trim().length >= 10;

  return (
    <div className="fifo-violation" role="alertdialog">
      <h4>Older stock must go first</h4>

      <div className="fifo-violation__expected">
        <strong>Use this instead</strong>
        <div>PO {expected.po_number || '—'} · received {expected.received_at}</div>
        <div>Zone <strong>{expected.zone_label || '—'}</strong> · {expected.remaining} pcs remaining</div>
      </div>

      {!showOverride && (
        <div className="fifo-violation__actions">
          <button type="button" onClick={onCancel}>Scan that one</button>
          {canOverride && (
            <button type="button" onClick={() => setShowOverride(true)}>Override…</button>
          )}
        </div>
      )}

      {showOverride && (
        <div className="fifo-violation__override">
          <label htmlFor="fifo-override-reason">Reason (required, min 10 characters)</label>
          <input
            id="fifo-override-reason"
            type="text"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
          <p className="fifo-violation__note">
            Recorded on the issuance line and in the audit trail with your user ID.
          </p>
          <button
            type="button"
            disabled={!reasonValid}
            onClick={() => reasonValid && onOverride(reason.trim())}
          >
            Confirm override
          </button>
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 4: Wire it into the issuance page**

In `IssuanceScanPage.jsx`, in the scan response handler, add before the success path:

```jsx
      if (data?.action === 'fifo_violation') {
        setFifoViolation(data);
        return;
      }
```

Render the dialog, re-posting the same scan with `accept_fifo_override: true` and
`override_reason` when the user confirms. Source `canOverride` from the same permission
hook the page already uses for other gated controls.

- [ ] **Step 5: Run tests**

Run: `cd frontend && npx react-scripts test --testPathPattern=FifoViolationDialog --watchAll=false`
Expected: PASS — 4 tests

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin
git commit -m "feat(issuance): add FIFO violation dialog with permissioned override"
```

---

## Final verification

- [ ] **Run the full backend suite**

Run: `cd back-end && php artisan test`
Expected: PASS — everything green

- [ ] **Run the full frontend suite**

Run: `cd frontend && npx react-scripts test --watchAll=false`
Expected: PASS

- [ ] **Manual smoke test**

1. Receive an unbarcoded PO line by count → stock rises, tags render on screen, tags print.
2. Receive a barcoded line by scan → one tag per scan, carrying the supplier barcode.
3. Count more than ordered → confirmation step, then the full quantity posts.
4. Issue from the newer of two batches → refused, naming the older batch and its zone.
5. Override with a reason as a permitted user → succeeds, reason persists on the issuance line.
6. Transfer an unbarcoded line by count → ships and receives, `packages_on_hand` correct at both ends.

---

## Deferred, with reasons

- **Retroactive tagging of pre-existing stock.** Existing materials have batches but no
  tags, and remain issuable through the existing product-barcode path. Minting tags for
  stock nobody can physically match would be worse than none, so FIFO enforcement only
  applies where a tag resolves to a batch — `check()` returns null for a null `batch_id`
  rather than blocking a picker over missing data.
- **`FifoPickingService::isSerialized()`** reads `applianceDetail?->is_serialized` even for
  installation materials, where the flag lives on `consumableDetail`. It returns the correct
  `false` by accident (null relation), not by intent. Out of scope; will bite if a material
  is ever marked serialized.
- **FIFO across locations.** The check is per product *and* location, matching `deductFifo()`.
