# Supplier Barcodes and Per-Package Piece Verification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop generating `INT-` tags for materials that arrived with a supplier barcode, and let a clerk verify the real piece count inside packages so a short-filled box becomes a partial receipt instead of a silent absorption.

**Architecture:** A scanned material keeps an `item_serial` row whose serial *is* the supplier's code, so it still resolves to its batch for FIFO. Two new columns on `receiving_details` record how many packages arrived and how many pieces they should have held, computed from the item master and frozen at receipt. The count path records the real number up front; the scan path books provisionally and reconciles through a new endpoint.

**Tech Stack:** Laravel 11 (PHP 8.2), MySQL/MariaDB, PHPUnit with `DatabaseTransactions`, React 18, `react-barcode`.

**Spec:** `docs/superpowers/specs/2026-08-31-supplier-barcodes-and-piece-verification-design.md`

---

## Conventions — read before Task 1

- Tests live in `back-end/tests/Feature/Inventory/`. Use `use DatabaseTransactions;` — **not** `RefreshDatabase`. Real MySQL test DB (`airkingV5_test`), configured in `phpunit.xml`.
- Models are created inline with `uniqid()`. There are no factories.
- Run one class: `cd back-end && php artisan test tests/Feature/Inventory/ClassName.php`
- **Migrations must be applied to TWO databases:**
  ```bash
  cd back-end && php artisan migrate --force
  cd back-end && DB_DATABASE=airkingV5_test php artisan migrate --force
  ```
  `php artisan migrate` targets the dev DB from `.env`; the suite reads `airkingV5_test` from `phpunit.xml`. Skipping the second gives a migration reporting DONE while its tests fail on a missing column. This has already cost time twice.
- Column migrations use the house guard: `if (Schema::hasColumn(...)) { return; }` at the top of `up()`, plus a real `down()`. See `2026_07_21_000003_add_zone_label_to_inventory_batches_table.php`.
- Data-writing migrations use `DB::table(...)`, never Eloquent — the `Auditable` trait fires boot hooks that must not run during a migration.
- `@testing-library/user-event` is pinned at **13.5.0** — no `.setup()` API. Use `userEvent.click(...)`.
- Branch `backupV4`. Do not branch, do not push.
- Leave the untracked `back-end/database/ci-schema.sql` alone.

## Verified API facts

- `ReceivingPostingService::post(Product $product, int $locationId, int $pieces, string $entryMode, ?PurchaseOrderDetail $poDetail = null, ?string $supplierBarcode = null, ?string $zoneLabel = null, ?int $userId = null, int $unitsEntered = 1): array` — returns `['receiving', 'batch', 'pieces', 'tags']`. Writes `ReceivingDetail::create([...])` at line 65.
- The tag-minting helper is **`mintTags()`** (private, line 208) — not `mintIdentifiers`.
- `MaterialTagService::mintForScan(Product, InventoryBatch, int $locationId, ?string $supplierBarcode, string $referenceLabel, ?int $userId = null): ItemSerial` — currently delegates to `createTag()`, which always generates an `INT-` serial.
- `ReceiveCountController` computes pieces at lines 82-86: `$countUnit === 'piece' ? $unitsEntered : $unitsEntered * $product->getPiecesPerPackage()`.
- `CountEntryPanel({ line, onSubmit, onCancel, busy = false })`.
- `ItemSerial::IN_STOCK_STATUS_ID` is 26. `serial_number` carries a global UNIQUE index.

## Baselines

- Backend full suite: `2 skipped, 1372 passed (5397 assertions)`
- Frontend full suite: `311 passed`

## File structure

**Create:**
- `back-end/database/migrations/2026_08_31_000001_add_package_verification_to_receiving_details.php`
- `back-end/database/migrations/2026_08_31_000002_rewrite_scanned_material_tags.php`
- `back-end/app/Http/Controllers/Api/ReceiveVerifyController.php`
- `back-end/tests/Feature/Inventory/ScannedMaterialKeepsSupplierCodeTest.php`
- `back-end/tests/Feature/Inventory/PieceVerificationTest.php`
- `back-end/tests/Feature/Inventory/ReceiveVerifyTest.php`

**Modify:**
- `back-end/app/Services/MaterialTagService.php` — `mintForScan` stops generating
- `back-end/app/Services/ReceivingPostingService.php` — record the two new columns
- `back-end/app/Http/Controllers/Api/ReceiveCountController.php` — accept actual pieces
- `back-end/app/Models/ReceivingDetail.php` — fillable
- `back-end/routes/api.php` — the verify route
- `frontend/src/admin/components/receiving/CountEntryPanel.jsx` — second field
- `frontend/src/admin/pages/InventoryManagement.jsx` — stock-in sub-line, barcode gating
- `frontend/src/admin/services/api.js` — `receiveVerify`

---

## Task 1: Stop generating tags for scanned materials

**Files:**
- Modify: `back-end/app/Services/MaterialTagService.php`
- Test: `back-end/tests/Feature/Inventory/ScannedMaterialKeepsSupplierCodeTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/ScannedMaterialKeepsSupplierCodeTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A generated barcode exists only because an item arrived without one. If the
 * clerk scanned a supplier code, that code is the identifier and inventing a
 * second one is noise.
 */
class ScannedMaterialKeepsSupplierCodeTest extends TestCase
{
    use DatabaseTransactions;

    private function scaffold(): array
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'L', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 't_' . uniqid(), 'first_name' => 'T', 'last_name' => 'U',
            'email' => uniqid() . '@e.test', 'password_hash' => bcrypt('x'), 'role_id' => $roleId,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 10,
            'unit_price' => 1, 'subtotal' => 10,
        ]);

        return [$location, $product, $user, $po, $poDetail];
    }

    public function test_a_scanned_material_keeps_the_supplier_code_as_its_serial(): void
    {
        [$location, $product, $user, $po, $poDetail] = $this->scaffold();
        $code = 'SUP-' . strtoupper(uniqid());

        $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => $code,
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'po_id'            => $po->po_id,
            'po_detail_id'     => $poDetail->po_detail_id,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $rows = ItemSerial::where('product_id', $product->product_id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame($code, $rows[0]->serial_number,
            'The supplier code is the identifier — no INT- serial should be generated for it.');
        $this->assertSame($code, $rows[0]->supplier_barcode,
            'supplier_barcode stays populated so "came from the supplier" is testable without prefix matching.');
        $this->assertNotNull($rows[0]->batch_id, 'The batch link must survive, or FIFO cannot verify the pick.');
    }

    public function test_a_counted_material_still_gets_generated_tags(): void
    {
        [$location, $product, $user, , $poDetail] = $this->scaffold();

        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 2,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $rows = ItemSerial::where('product_id', $product->product_id)->get();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertStringStartsWith('INT-', $row->serial_number);
            $this->assertNull($row->supplier_barcode);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test tests/Feature/Inventory/ScannedMaterialKeepsSupplierCodeTest.php`
Expected: the first test FAILS — the serial will be an `INT-…` value, not the scanned code. The second test PASSES already.

- [ ] **Step 3: Change `mintForScan`**

In `back-end/app/Services/MaterialTagService.php`, replace the body of `mintForScan()`:

```php
    /**
     * A material that arrived WITH a supplier barcode keeps that code as its
     * identifier. No INT- serial is generated: a generated barcode exists only
     * because an item arrived without one, and minting a second identifier for
     * a labelled box is noise the warehouse has to reconcile.
     *
     * supplier_barcode is populated as well as serial_number, deliberately. It
     * makes `supplier_barcode IS NOT NULL` the honest test for "this identity
     * came from the supplier", instead of string-matching an INT- prefix whose
     * format we control and could change.
     */
    public function mintForScan(
        Product $product,
        InventoryBatch $batch,
        int $locationId,
        ?string $supplierBarcode,
        string $referenceLabel,
        ?int $userId = null
    ): ItemSerial {
        if (! $supplierBarcode) {
            // No code to adopt — fall back to a generated tag so the goods are
            // still identifiable. Reachable only if a caller bypasses the scan
            // endpoint's `required` barcode rule.
            return $this->createTag($product, $batch, $locationId, null, $referenceLabel, $userId);
        }

        return ItemSerial::firstOrCreate(
            [
                'product_id'    => $product->product_id,
                'location_id'   => $locationId,
                'serial_number' => $supplierBarcode,
            ],
            [
                'batch_id'         => $batch->batch_id,
                'receiving_id'     => $batch->receiving_id,
                'supplier_barcode' => $supplierBarcode,
                'serial_type'      => 'received',
                'scanned_by'       => $userId,
                'status_id'        => ItemSerial::IN_STOCK_STATUS_ID,
            ]
        );
    }
```

`firstOrCreate` rather than `create` because `serial_number` is globally unique. In practice the scan path's one-time-use barcode lock already refuses a second scan of the same code, so this is belt-and-braces rather than a live path — but a unique-constraint crash during receiving would be a bad way to discover that.

- [ ] **Step 4: Run tests**

Run: `cd back-end && php artisan test tests/Feature/Inventory/ScannedMaterialKeepsSupplierCodeTest.php`
Expected: PASS — 2 tests

Run: `cd back-end && php artisan test tests/Feature/Inventory`
Expected: some existing tests FAIL — `ScanPathTagsTest` asserts a scanned material's serial starts with `INT-`, which is exactly the behaviour being changed. Update that assertion to expect the supplier code, and note in the test why. Do **not** weaken any other assertion to make it pass; if something else fails, report it.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/MaterialTagService.php back-end/tests/Feature/Inventory/
git commit -m "feat(receiving): keep the supplier code as a scanned material's serial"
```

---

## Task 2: Migrate the existing scanned tags

**Files:**
- Create: `back-end/database/migrations/2026_08_31_000002_rewrite_scanned_material_tags.php`
- Test: `back-end/tests/Feature/Inventory/ScannedTagRewriteTest.php`

There are 5 such rows in the dev database at time of writing: `serial_number LIKE 'INT-%'` with a non-null `supplier_barcode`.

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/ScannedTagRewriteTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScannedTagRewriteTest extends TestCase
{
    use DatabaseTransactions;

    private function makeTag(string $serial, ?string $supplier): ItemSerial
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'L', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        $batch = InventoryBatch::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'quantity_received' => 50, 'quantity_remaining' => 50, 'received_at' => now(),
        ]);

        return ItemSerial::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'batch_id' => $batch->batch_id, 'serial_number' => $serial,
            'supplier_barcode' => $supplier, 'serial_type' => 'received',
            'status_id' => ItemSerial::IN_STOCK_STATUS_ID,
        ]);
    }

    /** The migration's logic, extracted so it can be exercised without re-running migrate. */
    private function runRewrite(): void
    {
        require_once base_path('database/migrations/2026_08_31_000002_rewrite_scanned_material_tags.php');
        $migration = require base_path('database/migrations/2026_08_31_000002_rewrite_scanned_material_tags.php');
        $migration->up();
    }

    public function test_a_scanned_tag_is_rewritten_to_its_supplier_code(): void
    {
        $code = 'SUP-' . strtoupper(uniqid());
        $tag  = $this->makeTag('INT-PO-X-1-01', $code);

        $this->runRewrite();

        $fresh = $tag->fresh();
        $this->assertSame($code, $fresh->serial_number);
        $this->assertSame($code, $fresh->supplier_barcode, 'supplier_barcode stays populated after the rewrite.');
        $this->assertNotNull($fresh->batch_id, 'The batch link must survive the rewrite.');
    }

    public function test_a_counted_tag_is_left_alone(): void
    {
        $tag = $this->makeTag('INT-PO-Y-2-01', null);

        $this->runRewrite();

        $this->assertSame('INT-PO-Y-2-01', $tag->fresh()->serial_number);
    }

    public function test_a_collision_is_skipped_not_fatal(): void
    {
        $code = 'SUP-' . strtoupper(uniqid());
        $this->makeTag($code, null);              // the code already exists as a serial
        $tag = $this->makeTag('INT-PO-Z-3-01', $code);   // this one would collide

        $this->runRewrite();   // must not throw

        $this->assertSame('INT-PO-Z-3-01', $tag->fresh()->serial_number,
            'A colliding row keeps its INT- serial rather than failing the migration halfway.');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test tests/Feature/Inventory/ScannedTagRewriteTest.php`
Expected: FAIL — the migration file does not exist yet.

- [ ] **Step 3: Write the migration**

Create `back-end/database/migrations/2026_08_31_000002_rewrite_scanned_material_tags.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Materials that arrived WITH a supplier barcode were given a generated INT-
 * serial alongside it. A generated barcode exists only because an item arrived
 * without one, so those rows adopt the supplier's code as their serial.
 *
 * supplier_barcode is left populated: after the rewrite both columns hold the
 * same value, which is the shape a scanned material now takes.
 *
 * serial_number is globally unique. Where the supplier's code already exists as
 * a serial elsewhere the rewrite would collide, so those rows are left as they
 * are and logged. A handful of stragglers keeping an INT- serial is a far better
 * outcome than a migration that fails halfway through.
 */
return new class extends Migration
{
    public function up(): void
    {
        $candidates = DB::table('item_serial')
            ->where('serial_number', 'like', 'INT-%')
            ->whereNotNull('supplier_barcode')
            ->where('supplier_barcode', '!=', '')
            ->get(['serial_id', 'serial_number', 'supplier_barcode']);

        foreach ($candidates as $row) {
            $taken = DB::table('item_serial')
                ->where('serial_number', $row->supplier_barcode)
                ->where('serial_id', '!=', $row->serial_id)
                ->exists();

            if ($taken) {
                Log::warning('rewrite_scanned_material_tags: skipped colliding serial', [
                    'serial_id'        => $row->serial_id,
                    'kept'             => $row->serial_number,
                    'supplier_barcode' => $row->supplier_barcode,
                ]);
                continue;
            }

            DB::table('item_serial')
                ->where('serial_id', $row->serial_id)
                ->update(['serial_number' => $row->supplier_barcode, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Irreversible by design: the generated INT- serials are not recorded
        // anywhere else, so they cannot be reconstructed. The rows remain valid
        // and fully linked to their batches either way.
    }
};
```

- [ ] **Step 4: Migrate both databases and run the test**

```bash
cd back-end && php artisan migrate --force
cd back-end && DB_DATABASE=airkingV5_test php artisan migrate --force
cd back-end && php artisan test tests/Feature/Inventory/ScannedTagRewriteTest.php
```
Expected: PASS — 3 tests

- [ ] **Step 5: Commit**

```bash
git add back-end/database/migrations back-end/tests/Feature/Inventory/ScannedTagRewriteTest.php
git commit -m "feat(receiving): rewrite scanned material tags to their supplier code"
```

---

## Task 3: The two verification columns

**Files:**
- Create: `back-end/database/migrations/2026_08_31_000001_add_package_verification_to_receiving_details.php`
- Modify: `back-end/app/Models/ReceivingDetail.php`
- Test: `back-end/tests/Feature/Inventory/PieceVerificationTest.php` (schema test only in this task)

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/PieceVerificationTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PieceVerificationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_verification_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('receiving_details', 'packages_received'));
        $this->assertTrue(Schema::hasColumn('receiving_details', 'pieces_expected'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test tests/Feature/Inventory/PieceVerificationTest.php`
Expected: FAIL — `Failed asserting that false is true`

- [ ] **Step 3: Write the migration**

Create `back-end/database/migrations/2026_08_31_000001_add_package_verification_to_receiving_details.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the clerk actually verified when the boxes were opened.
 *
 * quantity_amount keeps its meaning — pieces actually posted. The shortfall is
 * pieces_expected minus quantity_amount, which is what finally distinguishes
 * "four boxes never arrived" from "six boxes arrived under-filled". The PO alone
 * cannot tell those apart, and they are different conversations with a supplier.
 *
 * pieces_expected is STORED, not recomputed. If a product's pieces_per_package
 * is later edited — a supplier moving from 50 to 40 per box — recomputing would
 * silently rewrite every historical expectation and erase past discrepancies.
 *
 * Both nullable: they only mean something for package-counted materials. A
 * piece-counted line has no package to verify, and appliance lines have none
 * either.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('receiving_details', 'packages_received')) {
            return;
        }

        Schema::table('receiving_details', function (Blueprint $table) {
            $table->integer('packages_received')->nullable()->after('quantity_amount');
            $table->integer('pieces_expected')->nullable()->after('packages_received');
        });
    }

    public function down(): void
    {
        Schema::table('receiving_details', function (Blueprint $table) {
            $table->dropColumn(['packages_received', 'pieces_expected']);
        });
    }
};
```

Add both to `$fillable` in `back-end/app/Models/ReceivingDetail.php`, after `'entry_mode'`.

- [ ] **Step 4: Migrate both databases and run the test**

```bash
cd back-end && php artisan migrate --force
cd back-end && DB_DATABASE=airkingV5_test php artisan migrate --force
cd back-end && php artisan test tests/Feature/Inventory/PieceVerificationTest.php
```
Expected: PASS — 1 test

- [ ] **Step 5: Commit**

```bash
git add back-end/database/migrations back-end/app/Models/ReceivingDetail.php back-end/tests/Feature/Inventory/PieceVerificationTest.php
git commit -m "feat(schema): record packages received and pieces expected"
```

---

## Task 4: Record the expectation when posting

**Files:**
- Modify: `back-end/app/Services/ReceivingPostingService.php:35-75`
- Test: `back-end/tests/Feature/Inventory/PieceVerificationTest.php`

- [ ] **Step 1: Write the failing test**

Append to `PieceVerificationTest`:

```php
    public function test_a_package_counted_receipt_records_packages_and_expectation(): void
    {
        [$location, $product, $user, , $poDetail] = $this->scaffold();   // ppp = 50, count_unit = package

        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $poDetail->po_detail_id,
            'location_id'   => $location->location_id,
            'quantity'      => 6,
            'actual_pieces' => 297,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $row = DB::table('receiving_details')->where('po_detail_id', $poDetail->po_detail_id)->first();

        $this->assertSame(297, (int) $row->quantity_amount, 'the ACTUAL count posts, not the expectation');
        $this->assertSame(6, (int) $row->packages_received);
        $this->assertSame(300, (int) $row->pieces_expected);
    }

    public function test_a_piece_counted_receipt_stores_nulls(): void
    {
        [$location, $product, $user, , $poDetail] = $this->scaffoldPieceCounted();

        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 473,
        ])->assertOk();

        $row = DB::table('receiving_details')->where('po_detail_id', $poDetail->po_detail_id)->first();

        $this->assertSame(473, (int) $row->quantity_amount);
        $this->assertNull($row->packages_received, 'a piece-counted line has no package to verify');
        $this->assertNull($row->pieces_expected);
    }
```

Add a `scaffold()` helper to the class matching the one in `ScannedMaterialKeepsSupplierCodeTest` (ppp 50, `count_unit` `'package'`), and a `scaffoldPieceCounted()` variant with `count_unit` `'piece'` and `pieces_per_package` 1. Copy them rather than importing — the two test classes are independent.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test tests/Feature/Inventory/PieceVerificationTest.php`
Expected: FAIL — `actual_pieces` is not accepted, and the columns are never written.

- [ ] **Step 3: Widen `post()` to carry the expectation**

In `back-end/app/Services/ReceivingPostingService.php`, add two parameters to `post()` after `int $unitsEntered = 1`:

```php
        ?int $packagesReceived = null,
        ?int $piecesExpected = null
```

and write them on the detail row (currently line 65):

```php
        ReceivingDetail::create([
            'receiving_id'      => $receiving->receiving_id,
            'product_id'        => $product->product_id,
            'po_detail_id'      => $poDetail?->po_detail_id,
            'quantity_amount'   => $pieces,
            'packages_received' => $packagesReceived,
            'pieces_expected'   => $piecesExpected,
            'prod_price'        => $product->cost_price ?? 0,
            'condition'         => 'Good',
            'scanned_barcode'   => $supplierBarcode ?: null,
            'entry_mode'        => $entryMode,
        ]);
```

Both default to null, so every existing caller keeps working unchanged.

- [ ] **Step 4: Accept and compute in the count endpoint**

In `back-end/app/Http/Controllers/Api/ReceiveCountController.php`, add to the validation rules:

```php
            'actual_pieces' => 'nullable|integer|min:0',
```

Replace the piece computation at lines 82-86:

```php
        $unitsEntered = (int) $validated['quantity'];
        $countUnit    = $product->consumableDetail?->count_unit ?? 'package';

        if ($countUnit === 'piece') {
            // The clerk counted pieces directly — expected and actual are the
            // same thing, so there is nothing to verify and nothing to record.
            $pieces            = $unitsEntered;
            $packagesReceived  = null;
            $piecesExpected    = null;
        } else {
            // The expectation comes from the item master, never from the clerk.
            $piecesExpected   = $unitsEntered * $product->getPiecesPerPackage();
            $packagesReceived = $unitsEntered;
            $pieces           = array_key_exists('actual_pieces', $validated) && $validated['actual_pieces'] !== null
                ? (int) $validated['actual_pieces']
                : $piecesExpected;
        }
```

Then pass the two new values through to `post(...)` as named arguments `packagesReceived:` and `piecesExpected:`, and add them to the success payload so the UI can echo the discrepancy:

```php
            'packages_received' => $packagesReceived,
            'pieces_expected'   => $piecesExpected,
```

- [ ] **Step 5: Run tests**

Run: `cd back-end && php artisan test tests/Feature/Inventory/PieceVerificationTest.php`
Expected: PASS — 3 tests

Run: `cd back-end && php artisan test`
Expected: `2 skipped, 1372 passed` plus your new tests, no regressions.

- [ ] **Step 6: Commit**

```bash
git add back-end/app back-end/tests/Feature/Inventory/PieceVerificationTest.php
git commit -m "feat(receiving): record the package expectation and post the actual count"
```

---

## Task 5: The reconcile endpoint

**Files:**
- Create: `back-end/app/Http/Controllers/Api/ReceiveVerifyController.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/Inventory/ReceiveVerifyTest.php`

Scanning posts each box immediately, so by the time the contents are counted the goods are booked. Verification on that path is a reconciliation, not a first write.

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/ReceiveVerifyTest.php` covering:

1. **Reconciles down** — scan 2 boxes of 50 (100 posted), then `receive-verify` with `packages: 2, actual_pieces: 97`. Assert inventory `quantity_on_hand` is 97, the batch's `quantity_remaining` is 97, and the line records `packages_received = 2`, `pieces_expected = 100`.
2. **Leaves a matching count alone** — same setup, `actual_pieces: 100`. Assert inventory unchanged at 100 and the columns still recorded.
3. **Refuses an empty line** — `receive-verify` on a PO line with nothing posted returns a rejection and moves no stock.
4. **Refuses a serialized product** — an appliance line is rejected; an appliance is one unit with a serial and has no package contents.
5. **Refuses going below zero** — post 100, issue 60, then try to reconcile to 20. Assert rejection and that stock stays at 40; that needs an adjustment, not a verification.
6. **Over-fill past the PO needs confirmation** — reconciling upward beyond the ordered total returns `action: over_receipt` and posts nothing; re-sent with `accept_overage: true` it applies.

Start from this skeleton — the scaffolding and the first case in full, so the remaining five follow one established shape:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReceiveVerifyTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:Location,1:Product,2:User,3:PurchaseOrder,4:PurchaseOrderDetail} */
    private function scaffold(int $orderedPackages = 10): array
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'L', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Tube',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 't_' . uniqid(), 'first_name' => 'T', 'last_name' => 'U',
            'email' => uniqid() . '@e.test', 'password_hash' => bcrypt('x'), 'role_id' => $roleId,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => $orderedPackages,
            'unit_price' => 1, 'subtotal' => 10,
        ]);

        return [$location, Product::with('consumableDetail')->find($product->product_id), $user, $po, $poDetail];
    }

    /** Scan $boxes boxes onto the line, leaving $boxes * 50 pieces posted. */
    private function scanBoxes(User $user, Location $l, PurchaseOrder $po, PurchaseOrderDetail $d, int $boxes): void
    {
        for ($i = 0; $i < $boxes; $i++) {
            $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
                'barcode'          => 'BOX-' . uniqid(),
                'transaction_type' => 'receiving',
                'location_id'      => $l->location_id,
                'po_id'            => $po->po_id,
                'po_detail_id'     => $d->po_detail_id,
            ])->assertOk();
        }
    }

    public function test_it_reconciles_a_short_filled_line_down(): void
    {
        [$location, $product, $user, $po, $d] = $this->scaffold();
        $this->scanBoxes($user, $location, $po, $d, 2);          // 100 pieces posted

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-verify', [
            'po_detail_id'  => $d->po_detail_id,
            'location_id'   => $location->location_id,
            'packages'      => 2,
            'actual_pieces' => 97,
        ]);

        $res->assertOk()->assertJsonPath('data.action', 'applied');

        $inv = Inventory::where('product_id', $product->product_id)->first();
        $this->assertSame(97, $inv->quantity_on_hand, 'the ledger must follow the goods, not the label');

        $batch = InventoryBatch::where('product_id', $product->product_id)->first();
        $this->assertSame(97, (int) $batch->quantity_remaining);

        $row = DB::table('receiving_details')->where('po_detail_id', $d->po_detail_id)
            ->orderByDesc('receiving_detail_id')->first();
        $this->assertSame(2, (int) $row->packages_received);
        $this->assertSame(100, (int) $row->pieces_expected);
    }
}
```

Then add the remaining five, each with these exact assertions:

2. `test_a_matching_count_leaves_stock_alone` — same setup, `actual_pieces: 100`. Assert `quantity_on_hand === 100` and that `packages_received`/`pieces_expected` are still recorded (2 and 100).
3. `test_it_refuses_a_line_with_nothing_posted` — no `scanBoxes` call. Assert `data.action` is `rejected` and `Inventory::where(...)->first()` is null.
4. `test_it_refuses_a_serialized_product` — build an appliance line (`product_type => 'appliance'` plus `ApplianceDetail::create([... 'is_serialized' => 1])`), scan one unit, then verify. Assert `rejected` and that `quantity_on_hand` is unchanged at 1.
5. `test_it_refuses_reconciling_below_zero` — scan 2 boxes (100), issue 60 through `IssuanceScanController` or by calling `app(InventoryBatchService::class)->deductFifo(...)` plus `setPieces` directly, then verify to 20. Assert `rejected` and `quantity_on_hand === 40`.
6. `test_over_filling_past_the_po_needs_confirmation` — `scaffold(2)` so only 100 pieces are ordered; scan 2 boxes (100 posted); verify with `actual_pieces: 110`. Assert `data.action` is `over_receipt` and stock is still 100. Re-send with `accept_overage: true` and assert `applied` with `quantity_on_hand === 110`.

Write them before the controller and confirm they fail with a 404.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test tests/Feature/Inventory/ReceiveVerifyTest.php`
Expected: FAIL — 404, the route does not exist.

- [ ] **Step 3: Write the controller**

Create `back-end/app/Http/Controllers/Api/ReceiveVerifyController.php`. A single-action controller (`__invoke`) that:

- validates `po_detail_id` (required, exists), `location_id` (nullable, exists), `packages` (required, integer, min 1), `actual_pieces` (required, integer, min 0), `accept_overage` (nullable, boolean);
- loads the line with `product.consumableDetail`, `product.applianceDetail` and `purchaseOrder` eager-loaded;
- rejects `$product->isSerialized()` with the `action: rejected` envelope before touching stock;
- sums `ReceivingDetail::where('po_detail_id', ...)->sum('quantity_amount')` for the current posted total, and rejects when it is zero — there is nothing to reconcile and the clerk wants the count panel;
- computes `$expected = $packages * $product->getPiecesPerPackage()` and `$delta = $actualPieces - $posted`;
- when `$delta > 0`, runs `app(ReceivingPostingService::class)->checkOverage($poDetail, $product, $posted + $delta)` and returns the `over_receipt` payload unless `accept_overage` is set — the same rule and the same payload shape the other paths use, because two different over-receipt rules depending on how stock was entered would be impossible to explain to an auditor;
- opens a transaction, takes `lockForUpdate()` on the inventory row, and refuses if `quantity_on_hand + $delta < 0`;
- applies the delta with `$inventory->setPieces((int) $inventory->quantity_on_hand + $delta, $piecesPerPackage)` and adjusts the batch's `quantity_remaining` by the same delta, floored at zero;
- updates the line's most recent `receiving_details` row with `packages_received` and `pieces_expected`, and adjusts its `quantity_amount` by the delta;
- calls `app(PoReceivingStatusService::class)->sync(...)` when there is a PO;
- returns `action: applied` with `posted_before`, `posted_after`, `pieces_expected`, `packages_received` and `delta`.

Use `App\Traits\ApiResponse` for `success`/`error`, matching `ReceiveCountController`.

- [ ] **Step 4: Register the route**

In `back-end/routes/api.php`, inside the existing `permission:barcode_inventory_processing,create` group that already holds `/inventory/scan-transaction` and `/inventory/receive-count`:

```php
        Route::post('/inventory/receive-verify', \App\Http\Controllers\Api\ReceiveVerifyController::class);
```

- [ ] **Step 5: Run tests**

Run: `cd back-end && php artisan test tests/Feature/Inventory/ReceiveVerifyTest.php`
Expected: PASS — 6 tests

Run: `cd back-end && php artisan test`
Expected: no regressions.

- [ ] **Step 6: Commit**

```bash
git add back-end/app back-end/routes/api.php back-end/tests/Feature/Inventory/ReceiveVerifyTest.php
git commit -m "feat(receiving): reconcile a scanned line to its verified piece count"
```

---

## Task 6: The count panel's second field

**Files:**
- Modify: `frontend/src/admin/components/receiving/CountEntryPanel.jsx`
- Modify: `frontend/src/admin/components/receiving/CountEntryPanel.test.jsx`
- Modify: `frontend/src/admin/services/api.js`

- [ ] **Step 1: Write the failing tests**

Add to `CountEntryPanel.test.jsx`:

1. A package-counted line shows a second field labelled for pieces, pre-filled with packages × pieces_per_package, updating as the package count changes.
2. Editing it below the expectation shows a shortfall message naming the difference (`3 pcs short of 300 expected`).
3. Editing it above shows a surplus message.
4. `onSubmit` receives both the units entered **and** the actual pieces — assert the exact shape, so a caller cannot silently drop the second value.
5. A piece-counted line shows **no** second field, because expected and actual are the same thing.

Use `userEvent.click(...)` / `userEvent.type(...)` — this repo pins `@testing-library/user-event` at 13.5.0 with no `.setup()` API.

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd frontend && npx react-scripts test --testPathPattern=CountEntryPanel --watchAll=false`
Expected: FAIL — there is no second field.

- [ ] **Step 3: Add the field**

In `CountEntryPanel.jsx`:

- Keep the existing units input.
- For `count_unit === 'package'` only, add a second labelled number input, `id="count-entry-pieces"`, whose value follows `units × ppp` until the clerk edits it, and stays edited afterwards.
- Show the difference beneath it whenever actual and expected disagree — `N pcs short of X expected` or `N pcs more than expected`.
- Change the submit callback to `onSubmit({ units, actualPieces })`, where `actualPieces` is `null` for a piece-counted line.
- Keep the existing guards: no submit on zero/empty units, `busy` disables everything.

Update the existing tests that assert `onSubmit` was called with a bare number.

- [ ] **Step 4: Add the API method**

In `frontend/src/admin/services/api.js`, beside `receiveCount`:

```js
  /**
   * Reconcile an already-scanned PO line to the piece count the clerk verified.
   * Returns { action: 'applied' | 'over_receipt' | 'rejected', ... }.
   */
  receiveVerify: (payload) =>
    apiRequest('/inventory/receive-verify', { method: 'POST', body: JSON.stringify(payload ?? {}) }),
```

Match the surrounding style — this file is a hand-rolled `fetch` wrapper, not axios.

- [ ] **Step 5: Run tests**

Run: `cd frontend && npx react-scripts test --testPathPattern=CountEntryPanel --watchAll=false`
Expected: PASS

Run: `cd frontend && npx react-scripts test --watchAll=false`
Expected: 311 passing plus your new tests.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/components/receiving frontend/src/admin/services/api.js
git commit -m "feat(receiving): verify package contents in the count panel"
```

---

## Task 7: Wire verification into the receiving screen

**Files:**
- Modify: `frontend/src/admin/pages/BarcodeScan.jsx`

`BarcodeScan.jsx` is ~4,500 lines. Read the regions you touch and make surgical additions.

- [ ] **Step 1: Pass the actual pieces through the count path**

`bsrPostCount` currently takes `(units, acceptOverage)`. Change it to accept the panel's `{ units, actualPieces }` object and include `actual_pieces` in the `inventoryAPI.receiveCount` payload when it is not null. Update the `CountEntryPanel` `onSubmit` wiring and the overage-confirm retry, which currently re-sends `bsrPendingOverage.units`.

- [ ] **Step 2: Add the per-line verify step for scanning**

After a line has been scanned, offer a **"Verify contents"** action on the active-line panel for non-serialized products. It opens the same two-field shape — packages scanned so far (read-only, derived from `bsrProductProgress[po_detail_id] / pieces_per_package`) and an editable actual-pieces field pre-filled with the expectation — and calls `inventoryAPI.receiveVerify`.

The payload field names must match the controller's validation exactly:

```jsx
      const res = await inventoryAPI.receiveVerify({
        po_detail_id:   bsrActiveLine.po_detail_id,
        location_id:    bsrLocationId,
        packages:       packagesScanned,
        actual_pieces:  actualPieces,
        ...(acceptOverage ? { accept_overage: true } : {}),
      });
```

Handle the three response actions exactly as the count path does: `applied` refreshes progress, `over_receipt` routes into the existing `bsrPendingOverage` confirmation, `rejected` shows the alert modal.

Note the overage-confirm retry has to remember **which** path raised it — a verify overage must re-send to `receiveVerify`, not to `receiveCount`. The existing `bsrPendingOverage` object already carries a `viaScan` flag for this reason; add an equivalent marker rather than routing a verify overage through the count endpoint.

- [ ] **Step 3: Verify by hand**

Run: `cd frontend && npm start`

Open a verified PO with an installation-material line and check: counting 6 boxes pre-fills 300 and posting 297 leaves 3 outstanding; scanning 2 boxes then verifying to 97 reduces the line to 97; a piece-counted product shows no second field.

State plainly in your report what you ran in the browser and what you only inspected.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/pages/BarcodeScan.jsx
git commit -m "feat(receiving): verify package contents on both receiving paths"
```

---

## Task 8: Show the discrepancy on the stock-in row

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/InventoryController.php`
- Modify: `back-end/app/Http/Controllers/Api/PoInventoryController.php`
- Modify: `frontend/src/admin/pages/InventoryManagement.jsx`

- [ ] **Step 1: Expose the columns**

Both controllers build `receiving_history`. Add `packages_received` and `pieces_expected` to the selected columns in each, so the frontend can compare them against `quantity_amount`.

- [ ] **Step 2: Render the sub-line**

In `InventoryManagement.jsx`, in both Stock-In History tables (the item-detail one and the PO-group one), add beneath the quantity — only when `pieces_expected` is present and differs from `quantity_amount`:

```
300 expected · 3 short
```

or `· 5 over` when the actual is higher. Nothing renders when they match, so a clean receipt stays clean and a short one is impossible to miss.

- [ ] **Step 3: Gate the barcode columns**

A scanned material now carries the supplier's code as its serial. In the Serial Numbers tab:

- its **Digital Barcode** cell renders a scannable barcode of that code — it is the identifier, and scanning off the screen is the point when a physical label is damaged;
- its **Printable** cell shows a dash. The supplier already printed it, and reprinting someone else's barcode puts two labels of differing provenance on one box.

Gate printability on `supplier_barcode === null` rather than an `INT-` prefix match. Apply the same gate to `MaterialTagSheet`, so scanned materials are excluded from the bulk sheet.

- [ ] **Step 3b: Re-check `AttachesGeneratedTags`**

`back-end/app/Http/Controllers/Concerns/AttachesGeneratedTags.php` groups tags on `(receiving_id, product_id, supplier_barcode)` and matches stock-in rows on `(receiving_id, product_id, scanned_barcode)`. That grouping still holds after Task 1 — a scanned material's tag still carries `supplier_barcode`, so it still matches its scanned line and still separates from the counted lines' null-barcode tags.

What changes is the *content*: a scanned line's "generated tag" is now the supplier's own code, not an `INT-` serial. Read the trait and confirm two things:

1. Nothing in it filters on an `INT-` prefix. If it does, switch that to the `supplier_barcode` test — a prefix match would now wrongly exclude scanned materials.
2. The label the UI shows for those rows still reads sensibly. A row saying "generated tag: SUP-231341" would be actively misleading, since nothing was generated. If the wording implies generation, change it to something neutral like "tags" or "identifiers".

Add a test asserting a scanned line's stock-in row exposes the supplier code through whatever field the trait populates, so the two halves cannot drift apart later.

- [ ] **Step 4: Add tests**

Backend: a receipt with a shortfall exposes `pieces_expected` and `packages_received` on its `receiving_history` row, in both controllers.

Frontend, in `InventoryManagement.test.jsx`: the sub-line renders on a mismatch and not on a match; a scanned material row is scannable but not printable.

- [ ] **Step 5: Run everything**

```bash
cd back-end && php artisan test
cd frontend && npx react-scripts test --watchAll=false
```

- [ ] **Step 6: Commit**

```bash
git add back-end/app frontend/src/admin back-end/tests frontend/src/admin/pages/InventoryManagement.test.jsx
git commit -m "feat(inventory): surface package shortfalls on stock-in rows"
```

---

## Final verification

- [ ] Full backend suite green
- [ ] Full frontend suite green
- [ ] Manual pass: count 6 boxes → 297 posts, 3 outstanding; scan 2 boxes → verify to 97 reduces the line; scanned material shows its supplier code, no `INT-` tag; counted material still gets generated tags; a piece-counted product shows one field

## Out of scope

- A supplier discrepancy report. The columns make it reportable; the client has not asked for the report and should use this first.
- Per-box attribution. Approach 2 verifies once per line, so three boxes each short by one read as "3 short" without identifying which. Revisiting this means per-box entry.
