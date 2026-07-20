# PO/Location-Batched Inventory with FIFO Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add PO/location-scoped batch tracking to inventory (`inventory_batches` table), so receiving creates batches, transfers/issuances consume them oldest-first (FIFO), and the Inventory page can show a per-batch breakdown for restock monitoring.

**Architecture:** One new table (`inventory_batches`) mirrors the existing `Inventory` aggregate but at PO/receiving granularity. A new `InventoryBatchService` centralizes FIFO deduct/restore logic and is called from the same transactions that already touch `Inventory` in `ReceivingController`, `TransferService`, and `IssuanceScanController` — no new transaction boundaries. Serialized items get exact (not FIFO-cascaded) batch adjustments because each unit's `receiving_id` is already known; bulk items get FIFO-cascaded adjustments across `inventory_batches` ordered by `received_at`. A "legacy" catch-all batch (`receiving_id = null`) absorbs any shortfall from stock that predates this feature, so nothing ever blocks on missing batch data.

**Tech Stack:** Laravel (PHP), MySQL/MariaDB (SQL-dump-based schema, not `migrate`-built), React (frontend), PHPUnit (`DatabaseTransactions` against `airkingV5_test`).

**Spec:** `docs/superpowers/specs/2026-07-20-po-location-batched-inventory-fifo-design.md`

**Two things this plan does beyond the spec, both required for correctness, called out inline:**
1. A new nullable `receiving_id` column on `issuance_details` — without it, `IssuanceScanController::removeItem()` already loses the link between a recreated serial and its original batch (pre-existing bug), which would make FIFO restoration silently wrong for every issuance undo.
2. `TransferService::reverse()` for bulk (non-serialized) products restores quantity into the legacy catch-all batch rather than the exact original batch, since no per-transfer batch trail is stored for bulk lines. This is intentional and matches the spec's "best-effort housekeeping, never a hard blocker" principle — call out if a future spec wants exact reverse tracking.

---

### Task 1: Migration — create `inventory_batches` table

**Files:**
- Create: `back-end/database/migrations/2026_07_21_000001_create_inventory_batches_table.php`

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
        if (Schema::hasTable('inventory_batches')) {
            return;
        }

        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id('batch_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('receiving_id')->nullable();
            $table->integer('quantity_received')->default(0);
            $table->integer('quantity_remaining')->default(0);
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'location_id', 'received_at'], 'inventory_batches_fifo_idx');

            $table->foreign('product_id')->references('product_id')->on('products')->onDelete('cascade');
            $table->foreign('location_id')->references('location_id')->on('locations')->onDelete('cascade');
            $table->foreign('receiving_id')->references('receiving_id')->on('receivings')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_batches');
    }
};
```

- [ ] **Step 2: Apply to the dev DB and the test DB**

Run (from `back-end/`, PowerShell — schema is SQL-dump based, so a blanket `migrate` fails; always use `--path` for a single new migration):

```powershell
php artisan migrate --path=database/migrations/2026_07_21_000001_create_inventory_batches_table.php
$env:DB_DATABASE='airkingV5_test'; php artisan migrate --path=database/migrations/2026_07_21_000001_create_inventory_batches_table.php; Remove-Item Env:\DB_DATABASE
```

Expected: both commands report `Migrated: 2026_07_21_000001_create_inventory_batches_table` with no errors.

- [ ] **Step 3: Commit**

```bash
git add back-end/database/migrations/2026_07_21_000001_create_inventory_batches_table.php
git commit -m "feat(db): add inventory_batches table"
```

---

### Task 2: Migration — add `receiving_id` to `issuance_details`

**Files:**
- Create: `back-end/database/migrations/2026_07_21_000002_add_receiving_id_to_issuance_details_table.php`
- Modify: `back-end/app/Models/IssuanceDetail.php:17-22`

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
        if (Schema::hasColumn('issuance_details', 'receiving_id')) {
            return;
        }

        Schema::table('issuance_details', function (Blueprint $table) {
            $table->unsignedBigInteger('receiving_id')->nullable()->after('serial_number');
            $table->foreign('receiving_id')->references('receiving_id')->on('receivings')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('issuance_details', function (Blueprint $table) {
            $table->dropForeign(['receiving_id']);
            $table->dropColumn('receiving_id');
        });
    }
};
```

- [ ] **Step 2: Apply to the dev DB and the test DB**

```powershell
php artisan migrate --path=database/migrations/2026_07_21_000002_add_receiving_id_to_issuance_details_table.php
$env:DB_DATABASE='airkingV5_test'; php artisan migrate --path=database/migrations/2026_07_21_000002_add_receiving_id_to_issuance_details_table.php; Remove-Item Env:\DB_DATABASE
```

- [ ] **Step 3: Add `receiving_id` to the model's fillable array**

In `back-end/app/Models/IssuanceDetail.php`, change:

```php
    protected $fillable = [
        'issuance_id', 'product_id', 'serial_number',
        'unit_price', 'quantity_issued', 'quantity_unit',
        'quantity_in_pieces', 'unit_price_per_piece', 'subtotal',
        'quantity_returned', 'condition_issued', 'condition_returned',
    ];
```

to:

```php
    protected $fillable = [
        'issuance_id', 'product_id', 'serial_number', 'receiving_id',
        'unit_price', 'quantity_issued', 'quantity_unit',
        'quantity_in_pieces', 'unit_price_per_piece', 'subtotal',
        'quantity_returned', 'condition_issued', 'condition_returned',
    ];
```

- [ ] **Step 4: Commit**

```bash
git add back-end/database/migrations/2026_07_21_000002_add_receiving_id_to_issuance_details_table.php back-end/app/Models/IssuanceDetail.php
git commit -m "feat(db): add receiving_id to issuance_details for batch-aware undo"
```

---

### Task 3: `InventoryBatch` model

**Files:**
- Create: `back-end/app/Models/InventoryBatch.php`

- [ ] **Step 1: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBatch extends Model
{
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

- [ ] **Step 2: Commit**

```bash
git add back-end/app/Models/InventoryBatch.php
git commit -m "feat(models): add InventoryBatch model"
```

---

### Task 4: `InventoryBatchService` — FIFO deduct/restore core logic

This is the shared engine every write path (receiving, transfer, issuance) calls into. Write it test-first against a plain feature test before wiring it into any controller.

**Files:**
- Create: `back-end/app/Services/InventoryBatchService.php`
- Test: `back-end/tests/Feature/Inventory/InventoryBatchServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\InventoryBatch;
use App\Services\InventoryBatchService;

class InventoryBatchServiceTest extends TestCase
{
    use DatabaseTransactions;

    private InventoryBatchService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new InventoryBatchService();
    }

    private function makeLocation(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Test Loc',
            'location_type' => 'warehouse',
        ]);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'product_code' => 'PRD-' . uniqid(),
            'product_name' => 'Test Product',
            'product_type' => 'installation_material',
        ]);
    }

    private function makeReceiving(int $locationId): Receiving
    {
        return Receiving::create([
            'location_id'      => $locationId,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now(),
        ]);
    }

    public function test_deduct_fifo_consumes_oldest_batch_first(): void
    {
        $loc  = $this->makeLocation();
        $prod = $this->makeProduct();

        $old = $this->svc->createBatch($prod->product_id, $loc->location_id, $this->makeReceiving($loc->location_id)->receiving_id, 5, now()->subDays(10));
        $new = $this->svc->createBatch($prod->product_id, $loc->location_id, $this->makeReceiving($loc->location_id)->receiving_id, 5, now()->subDays(1));

        $touched = $this->svc->deductFifo($prod->product_id, $loc->location_id, 7);

        $this->assertSame(0, $old->fresh()->quantity_remaining);
        $this->assertSame(3, $new->fresh()->quantity_remaining);
        $this->assertSame([$old->batch_id => 5, $new->batch_id => 2], $touched);
    }

    public function test_deduct_fifo_spills_into_legacy_batch_when_batches_insufficient(): void
    {
        $loc  = $this->makeLocation();
        $prod = $this->makeProduct();

        $batch = $this->svc->createBatch($prod->product_id, $loc->location_id, $this->makeReceiving($loc->location_id)->receiving_id, 3, now());

        $this->svc->deductFifo($prod->product_id, $loc->location_id, 5);

        $this->assertSame(0, $batch->fresh()->quantity_remaining);

        $legacy = InventoryBatch::where('product_id', $prod->product_id)
            ->where('location_id', $loc->location_id)
            ->whereNull('receiving_id')
            ->first();

        $this->assertNotNull($legacy);
        $this->assertSame(0, $legacy->quantity_remaining); // floored at 0, never negative
    }

    public function test_restore_fifo_returns_quantity_to_preferred_batch_up_to_its_capacity(): void
    {
        $loc  = $this->makeLocation();
        $prod = $this->makeProduct();

        $batch = $this->svc->createBatch($prod->product_id, $loc->location_id, $this->makeReceiving($loc->location_id)->receiving_id, 5, now());
        $batch->quantity_remaining = 2;
        $batch->save();

        $this->svc->restoreFifo($prod->product_id, $loc->location_id, 4, $batch->receiving_id);

        // Batch had room for 3 (5 received - 2 remaining), so 3 goes back to it, 1 spills to legacy.
        $this->assertSame(5, $batch->fresh()->quantity_remaining);

        $legacy = InventoryBatch::where('product_id', $prod->product_id)
            ->where('location_id', $loc->location_id)
            ->whereNull('receiving_id')
            ->first();
        $this->assertSame(1, $legacy->quantity_remaining);
    }

    public function test_deduct_from_receiving_targets_exact_batch(): void
    {
        $loc  = $this->makeLocation();
        $prod = $this->makeProduct();
        $rcv  = $this->makeReceiving($loc->location_id);

        $batch = $this->svc->createBatch($prod->product_id, $loc->location_id, $rcv->receiving_id, 3, now());

        $this->svc->deductFromReceiving($rcv->receiving_id, $prod->product_id, $loc->location_id, 2);

        $this->assertSame(1, $batch->fresh()->quantity_remaining);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test tests/Feature/Inventory/InventoryBatchServiceTest.php`
Expected: FAIL — `Class "App\Services\InventoryBatchService" not found`.

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services;

use App\Models\InventoryBatch;
use Illuminate\Support\Carbon;

class InventoryBatchService
{
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

    /** The single catch-all batch (receiving_id null) for stock that predates batch tracking or has run out of traceable batches. */
    public function legacyBatch(int $productId, int $locationId): InventoryBatch
    {
        return InventoryBatch::firstOrCreate(
            ['product_id' => $productId, 'location_id' => $locationId, 'receiving_id' => null],
            ['quantity_received' => 0, 'quantity_remaining' => 0, 'received_at' => null]
        );
    }

    /**
     * Deduct $qty oldest-batch-first. Spills into the legacy batch (floored at 0,
     * never negative) if traceable batches run out. Returns [batch_id => qty_deducted].
     */
    public function deductFifo(int $productId, int $locationId, int $qty): array
    {
        $remaining = $qty;
        $touched = [];

        $batches = InventoryBatch::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->whereNotNull('receiving_id')
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at', 'asc')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($batch->quantity_remaining, $remaining);
            $batch->quantity_remaining -= $take;
            $batch->save();
            $touched[$batch->batch_id] = $take;
            $remaining -= $take;
        }

        if ($remaining > 0) {
            $legacy = $this->legacyBatch($productId, $locationId);
            $legacy->quantity_remaining = max(0, $legacy->quantity_remaining - $remaining);
            $legacy->save();
            $touched[$legacy->batch_id] = ($touched[$legacy->batch_id] ?? 0) + $remaining;
        }

        return $touched;
    }

    /** Exact-batch deduction for serialized items, where the unit's receiving_id is already known. */
    public function deductFromReceiving(?int $receivingId, int $productId, int $locationId, int $qty): void
    {
        $batch = $receivingId
            ? InventoryBatch::where('receiving_id', $receivingId)
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->first()
            : null;

        if (! $batch) {
            $batch = $this->legacyBatch($productId, $locationId);
        }

        $batch->quantity_remaining = max(0, $batch->quantity_remaining - $qty);
        $batch->save();
    }

    /**
     * Restore $qty to a specific batch (up to its remaining capacity), spilling any
     * excess into the legacy batch. If $receivingId is null, everything goes to legacy.
     */
    public function restoreFifo(int $productId, int $locationId, int $qty, ?int $receivingId = null): void
    {
        $remaining = $qty;

        if ($receivingId) {
            $batch = InventoryBatch::where('receiving_id', $receivingId)
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->first();

            if ($batch) {
                $room = max(0, $batch->quantity_received - $batch->quantity_remaining);
                $restore = min($room, $remaining);
                if ($restore > 0) {
                    $batch->quantity_remaining += $restore;
                    $batch->save();
                    $remaining -= $restore;
                }
            }
        }

        if ($remaining > 0) {
            $legacy = $this->legacyBatch($productId, $locationId);
            $legacy->quantity_remaining += $remaining;
            $legacy->save();
        }
    }

    /** Batches for the monitoring UI drill-down: PO-linked batches oldest-first, legacy batch (if any) last. */
    public function batchesForDisplay(int $productId, int $locationId)
    {
        return InventoryBatch::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->with('receiving.purchaseOrder')
            ->orderByRaw('receiving_id IS NULL, received_at ASC')
            ->get();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test tests/Feature/Inventory/InventoryBatchServiceTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/InventoryBatchService.php back-end/tests/Feature/Inventory/InventoryBatchServiceTest.php
git commit -m "feat(inventory): add InventoryBatchService with FIFO deduct/restore"
```

---

### Task 5: Receiving flow creates a batch per line

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/ReceivingController.php:1-20` (imports), `:184-193` (the loop body)
- Test: `back-end/tests/Feature/Inventory/ReceivingCreatesBatchTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\InventoryBatch;
use Illuminate\Support\Facades\DB;

class ReceivingCreatesBatchTest extends TestCase
{
    use DatabaseTransactions;

    public function test_store_creates_one_inventory_batch_per_receiving_line(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Test Loc',
            'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'Test Consumable',
            'product_type' => 'installation_material',
        ]);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username'      => 'tester_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'User',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]);
        $po = PurchaseOrder::create([
            'po_number'           => 'PO-' . uniqid(),
            'location_id'         => $location->location_id,
            'verification_status' => 'verified',
            'status_id'           => 1,
        ]);

        $this->actingAs($user)->postJson('/api/receivings', [
            'pc_id'            => $po->po_id,
            'location_id'      => $location->location_id,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now()->toDateString(),
            'details'          => [
                ['product_id' => $product->product_id, 'quantity_amount' => 20, 'condition' => 'Good'],
            ],
        ])->assertStatus(201);

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->first();

        $this->assertNotNull($batch);
        $this->assertSame(20, $batch->quantity_received);
        $this->assertSame(20, $batch->quantity_remaining);
        $this->assertNotNull($batch->receiving_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test tests/Feature/Inventory/ReceivingCreatesBatchTest.php`
Expected: FAIL — `$batch` is null (`assertNotNull` fails), since no `InventoryBatch` row is created yet.

> If this fails earlier on PO/route setup (e.g. missing required PurchaseOrder fields), adjust the factory fields to match `purchase_orders` schema — check `airking_cleans.sql` for the `purchase_orders` CREATE TABLE if `po_number`/`status_id` aren't sufficient.

- [ ] **Step 3: Wire in `InventoryBatchService`**

In `back-end/app/Http/Controllers/Api/ReceivingController.php`, add the import alongside the existing ones (after line 13, `use App\Services\BudgetService;`):

```php
use App\Services\InventoryBatchService;
```

Then in the `foreach ($validated['details'] as $detail)` loop, right after the existing `Inventory` block (`back-end/app/Http/Controllers/Api/ReceivingController.php:184-191`):

```php
                // Update inventory at receiving location
                $inventory = Inventory::firstOrCreate(
                    ['location_id' => $locationId, 'product_id' => $productId],
                    ['quantity_on_hand' => 0, 'available_quantity' => 0, 'reorder_level' => 0]
                );
                $inventory->quantity_on_hand   = max(0, (int) $inventory->quantity_on_hand + $qty);
                $inventory->available_quantity = max(0, (int) $inventory->available_quantity + $qty);
                $inventory->save();
```

add:

```php
                app(InventoryBatchService::class)->createBatch(
                    $productId,
                    $locationId,
                    $receiving->receiving_id,
                    $qty,
                    $receiving->receiving_date ?? now()
                );
```

(Placed for both serialized and bulk lines — do not put it inside the `if ($isSerialized && $scannedBarcode)` block above it.)

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test tests/Feature/Inventory/ReceivingCreatesBatchTest.php`
Expected: PASS.

- [ ] **Step 5: Run the full receiving test suite to check for regressions**

Run: `cd back-end && php artisan test tests/Feature/History/ReceivingReceiptTest.php tests/Feature/History/ReceivingPoLogTest.php`
Expected: PASS (no regressions).

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/ReceivingController.php back-end/tests/Feature/Inventory/ReceivingCreatesBatchTest.php
git commit -m "feat(receiving): create an inventory_batches row per receiving line"
```

---

### Task 6: `TransferService::dispatch()` — FIFO/exact batch deduction

**Files:**
- Modify: `back-end/app/Services/TransferService.php:1-16` (imports), `:86-116` (dispatch loop)
- Test: `back-end/tests/Feature/Transfer/TransferBatchFifoTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Transfer;

use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Receiving;
use App\Services\InventoryBatchService;
use App\Services\TransferService;

class TransferBatchFifoTest extends TransferTestCase
{
    private function makeReceiving(int $locationId): Receiving
    {
        return Receiving::create([
            'location_id'      => $locationId,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now(),
        ]);
    }

    public function test_dispatch_consumable_deducts_oldest_batch_first(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 10);

        $svc = new InventoryBatchService();
        $old = $svc->createBatch($prod->product_id, $from->location_id, $this->makeReceiving($from->location_id)->receiving_id, 6, now()->subDays(5));
        $new = $svc->createBatch($prod->product_id, $from->location_id, $this->makeReceiving($from->location_id)->receiving_id, 4, now()->subDay());

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 8, 'quantity_scanned' => 8],
        ]);

        (new TransferService())->dispatch($ist);

        $this->assertSame(0, $old->fresh()->quantity_remaining);
        $this->assertSame(2, $new->fresh()->quantity_remaining);
    }

    public function test_dispatch_serialized_deducts_exact_batch_by_receiving_id(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 1);

        $svc = new InventoryBatchService();
        $rcv   = $this->makeReceiving($from->location_id);
        $batch = $svc->createBatch($prod->product_id, $from->location_id, $rcv->receiving_id, 1, now());

        $sn = 'SN-' . uniqid();
        $serial = ItemSerial::create([
            'product_id'    => $prod->product_id,
            'location_id'   => $from->location_id,
            'receiving_id'  => $rcv->receiving_id,
            'serial_number' => $sn,
            'serial_type'   => 'received',
        ]);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 1, 'quantity_scanned' => 1],
        ]);
        $ist->details->first()->update(['quantity_scanned' => 1]);
        $serial->update(['transfer_id' => $ist->transfer_id]);

        (new TransferService())->dispatch($ist);

        $this->assertSame(0, $batch->fresh()->quantity_remaining);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test tests/Feature/Transfer/TransferBatchFifoTest.php`
Expected: FAIL — both assertions fail because `quantity_remaining` is untouched (still 6/1).

- [ ] **Step 3: Wire FIFO/exact deduction into `dispatch()`**

In `back-end/app/Services/TransferService.php`, add the import after line 9 (`use App\Models\TblTransferTracking;`):

```php
use App\Services\InventoryBatchService;
```

And add a constructor-injected (or lazily resolved) instance. Add right after the class's `use` trait line (`back-end/app/Services/TransferService.php:16`):

```php
    private InventoryBatchService $batches;

    public function __construct(?InventoryBatchService $batches = null)
    {
        $this->batches = $batches ?? new InventoryBatchService();
    }
```

Then in `dispatch()`, replace the block at `back-end/app/Services/TransferService.php:106-111`:

```php
                if ($this->isSerialized($detail)) {
                    ItemSerial::where('transfer_id', $locked->transfer_id)
                        ->where('product_id', $detail->product_id)
                        ->where('serial_type', 'received')
                        ->update(['serial_type' => 'transferred']);
                }
```

with:

```php
                if ($this->isSerialized($detail)) {
                    ItemSerial::where('transfer_id', $locked->transfer_id)
                        ->where('product_id', $detail->product_id)
                        ->where('serial_type', 'received')
                        ->update(['serial_type' => 'transferred']);

                    // Exact per-unit batch deduction: group the just-shipped serials by
                    // their known receiving_id and decrement each linked batch directly.
                    ItemSerial::where('transfer_id', $locked->transfer_id)
                        ->where('product_id', $detail->product_id)
                        ->where('serial_type', 'transferred')
                        ->get()
                        ->groupBy('receiving_id')
                        ->each(function ($group, $receivingId) use ($locked, $detail) {
                            $this->batches->deductFromReceiving(
                                $receivingId,
                                $detail->product_id,
                                $locked->from_location_id,
                                $group->count()
                            );
                        });
                } else {
                    $this->batches->deductFifo($detail->product_id, $locked->from_location_id, $shipped);
                }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test tests/Feature/Transfer/TransferBatchFifoTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the full transfer suite to check for regressions**

Run: `cd back-end && php artisan test tests/Feature/Transfer`
Expected: PASS, no regressions (the constructor change must not break any existing `new TransferService()` call site — confirm by grepping `grep -rn "new TransferService(" back-end/app back-end/tests` if any test fails on construction).

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Services/TransferService.php back-end/tests/Feature/Transfer/TransferBatchFifoTest.php
git commit -m "feat(transfer): deduct inventory_batches on dispatch (FIFO for bulk, exact for serialized)"
```

---

### Task 7: `TransferService::reverse()` — restore batch quantities

**Files:**
- Modify: `back-end/app/Services/TransferService.php:344-377` (reverse loop)
- Test: append to `back-end/tests/Feature/Transfer/TransferBatchFifoTest.php`

- [ ] **Step 1: Write the failing test**

Append to `TransferBatchFifoTest`:

```php
    public function test_reverse_consumable_restores_to_legacy_batch(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 10);

        $svc = new InventoryBatchService();
        $batch = $svc->createBatch($prod->product_id, $from->location_id, $this->makeReceiving($from->location_id)->receiving_id, 10, now());

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 4, 'quantity_scanned' => 4],
        ]);
        $service = new TransferService();
        $service->dispatch($ist);
        $this->assertSame(6, $batch->fresh()->quantity_remaining);

        $service->reverse($ist->fresh());

        $legacy = InventoryBatch::where('product_id', $prod->product_id)
            ->where('location_id', $from->location_id)
            ->whereNull('receiving_id')
            ->first();
        $this->assertSame(4, $legacy->quantity_remaining);
    }

    public function test_reverse_serialized_restores_exact_batch(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 1);

        $svc = new InventoryBatchService();
        $rcv   = $this->makeReceiving($from->location_id);
        $batch = $svc->createBatch($prod->product_id, $from->location_id, $rcv->receiving_id, 1, now());

        $sn = 'SN-' . uniqid();
        $serial = ItemSerial::create([
            'product_id'    => $prod->product_id,
            'location_id'   => $from->location_id,
            'receiving_id'  => $rcv->receiving_id,
            'serial_number' => $sn,
            'serial_type'   => 'received',
        ]);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 1, 'quantity_scanned' => 1],
        ]);
        $serial->update(['transfer_id' => $ist->transfer_id]);

        $service = new TransferService();
        $service->dispatch($ist);
        $this->assertSame(0, $batch->fresh()->quantity_remaining);

        $service->reverse($ist->fresh());

        $this->assertSame(1, $batch->fresh()->quantity_remaining);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test tests/Feature/Transfer/TransferBatchFifoTest.php --filter=test_reverse`
Expected: FAIL — batch/legacy quantities are unchanged (0 and null respectively) since `reverse()` doesn't touch batches yet.

- [ ] **Step 3: Wire restoration into `reverse()`**

In `back-end/app/Services/TransferService.php`, replace the block at `:368-376`:

```php
                if ($this->isSerialized($detail)) {
                    ItemSerial::where('transfer_id', $locked->transfer_id)
                        ->where('product_id', $detail->product_id)
                        ->update([
                            'serial_type' => 'received',
                            'location_id' => $locked->from_location_id,
                            'transfer_id' => null,
                        ]);
                }
```

with:

```php
                if ($this->isSerialized($detail)) {
                    // Read receiving_id groupings BEFORE clearing transfer_id, so the
                    // exact batch each serial came from can still be identified.
                    ItemSerial::where('transfer_id', $locked->transfer_id)
                        ->where('product_id', $detail->product_id)
                        ->get()
                        ->groupBy('receiving_id')
                        ->each(function ($group, $receivingId) use ($locked, $detail) {
                            $this->batches->restoreFifo(
                                $detail->product_id,
                                $locked->from_location_id,
                                $group->count(),
                                $receivingId ?: null
                            );
                        });

                    ItemSerial::where('transfer_id', $locked->transfer_id)
                        ->where('product_id', $detail->product_id)
                        ->update([
                            'serial_type' => 'received',
                            'location_id' => $locked->from_location_id,
                            'transfer_id' => null,
                        ]);
                } else {
                    // Bulk products have no per-transfer batch trail, so restoration is
                    // best-effort: it goes to the legacy catch-all batch rather than the
                    // exact original batch. See plan header for rationale.
                    $this->batches->restoreFifo($detail->product_id, $locked->from_location_id, $shipped, null);
                }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test tests/Feature/Transfer/TransferBatchFifoTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Run the full transfer suite to check for regressions**

Run: `cd back-end && php artisan test tests/Feature/Transfer`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Services/TransferService.php back-end/tests/Feature/Transfer/TransferBatchFifoTest.php
git commit -m "feat(transfer): restore inventory_batches quantities on reverse"
```

---

### Task 8: `IssuanceScanController::scanItem()` appliance branch — exact deduct + FIFO warning + batch linkage

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/IssuanceScanController.php:1-33` (imports/constants), `:212-303` (appliance branch)
- Test: `back-end/tests/Feature/Issuance/IssuanceBatchFifoTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Issuance;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Issuance;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use App\Services\InventoryBatchService;

class IssuanceBatchFifoTest extends TestCase
{
    use DatabaseTransactions;

    private function makeLocation(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Test Loc',
            'location_type' => 'warehouse',
        ]);
    }

    private function makeAppliance(): Product
    {
        return Product::create([
            'product_code' => 'APP-' . uniqid(),
            'product_name' => 'Test Appliance',
            'product_type' => 'appliance',
            'unit_price'   => 100,
        ]);
    }

    private function makeReceiving(int $locationId): Receiving
    {
        return Receiving::create([
            'location_id'      => $locationId,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now(),
        ]);
    }

    private function makeUser(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        return User::create([
            'username'      => 'tester_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'User',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]);
    }

    public function test_scanning_serialized_unit_deducts_its_exact_batch_and_stores_receiving_id(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $customer = Customer::create(['customer_name' => 'Test Customer', 'customer_type' => 'individual']);
        $user     = $this->makeUser();

        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 1, 'available_quantity' => 1, 'reorder_level' => 0,
        ]);

        $rcv   = $this->makeReceiving($location->location_id);
        $batch = (new InventoryBatchService())->createBatch($product->product_id, $location->location_id, $rcv->receiving_id, 1, now());

        $sn = 'SN-' . uniqid();
        ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $location->location_id,
            'receiving_id'  => $rcv->receiving_id,
            'serial_number' => $sn,
            'serial_type'   => 'received',
            'status_id'     => 26,
        ]);

        $issuance = Issuance::create([
            'location_id' => $location->location_id, 'customer_id' => $customer->customer_id,
            'issuance_date' => now()->toDateString(), 'issuance_type' => 'individual',
            'dr_number' => 'DR-TEST-' . uniqid(), 'discount_type' => 'none', 'discount_percent' => 0,
            'subtotal' => 0, 'discount_amount' => 0, 'total_amount' => 0,
            'issued_by' => $user->user_id, 'status_id' => 30,
        ]);

        $res = $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode'     => $sn,
        ]);

        $res->assertJson(['success' => true, 'action' => 'applied']);
        $this->assertSame(0, $batch->fresh()->quantity_remaining);

        $detail = DB::table('issuance_details')->where('issuance_id', $issuance->issuance_id)->first();
        $this->assertSame($rcv->receiving_id, (int) $detail->receiving_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test tests/Feature/Issuance/IssuanceBatchFifoTest.php`
Expected: FAIL — `quantity_remaining` still 1, `receiving_id` column doesn't exist on the response row yet (or is null).

- [ ] **Step 3: Wire deduction, warning, and batch linkage into the appliance branch**

In `back-end/app/Http/Controllers/Api/IssuanceScanController.php`, add the import after line 15 (`use App\Traits\ApiResponse;`):

```php
use App\Services\InventoryBatchService;
```

Before the serial lookup loop at `:157-166`, compute a FIFO warning once a serial is found. Replace the loop:

```php
        foreach ($candidates as $candidate) {
            $serial = ItemSerial::issuableAt($locationId, $candidate)->first();
            if ($serial) {
                $product = Product::with([
                    'applianceDetail.brand',
                ])->find($serial->product_id);
                $serialRecord = $serial;
                break;
            }
        }
```

with:

```php
        $fifoWarning = null;

        foreach ($candidates as $candidate) {
            $serial = ItemSerial::issuableAt($locationId, $candidate)->first();
            if ($serial) {
                $product = Product::with([
                    'applianceDetail.brand',
                ])->find($serial->product_id);
                $serialRecord = $serial;
                $fifoWarning = $this->fifoWarningFor($serial, $locationId);
                break;
            }
        }
```

Add a new private helper method anywhere in the class body (e.g. right after `scanItem()`, before the `// METHOD 3` comment at `:417`):

```php
    /**
     * Non-blocking FIFO guidance: if an older batch of this product is still
     * available at this location while the scanned unit came from a newer one,
     * return a warning string for the UI to display. Returns null otherwise.
     */
    private function fifoWarningFor(ItemSerial $serial, int $locationId): ?string
    {
        $scannedBatch = \App\Models\InventoryBatch::where('receiving_id', $serial->receiving_id)
            ->where('product_id', $serial->product_id)
            ->where('location_id', $locationId)
            ->first();

        $oldestBatch = \App\Models\InventoryBatch::where('product_id', $serial->product_id)
            ->where('location_id', $locationId)
            ->whereNotNull('receiving_id')
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at', 'asc')
            ->first();

        if (! $scannedBatch || ! $oldestBatch || $scannedBatch->batch_id === $oldestBatch->batch_id) {
            return null;
        }
        if (! $oldestBatch->received_at || ! $scannedBatch->received_at
            || $scannedBatch->received_at->lte($oldestBatch->received_at)) {
            return null;
        }

        return 'An older batch of this product (received '
            . $oldestBatch->received_at->format('M j, Y')
            . ') is still available at this location — consider issuing that stock first.';
    }
```

Then in the appliance branch's transaction (`back-end/app/Http/Controllers/Api/IssuanceScanController.php:235-302`), update the `use` clause and body. Change:

```php
            return DB::transaction(function () use (
                $issuance, $product, $serialRecord, $raw,
                $unitPrice, $lineSubtotal, $locationId
            ) {
```

to:

```php
            return DB::transaction(function () use (
                $issuance, $product, $serialRecord, $raw,
                $unitPrice, $lineSubtotal, $locationId, $fifoWarning
            ) {
```

Replace the deduction + delete block (`:251-255`):

```php
                $inv->quantity_on_hand   = max(0, $inv->quantity_on_hand - 1);
                $inv->available_quantity = max(0, $inv->available_quantity - 1);
                $inv->save();

                $serialRecord->delete();
```

with:

```php
                $inv->quantity_on_hand   = max(0, $inv->quantity_on_hand - 1);
                $inv->available_quantity = max(0, $inv->available_quantity - 1);
                $inv->save();

                app(InventoryBatchService::class)->deductFromReceiving(
                    $serialRecord->receiving_id,
                    $product->product_id,
                    $locationId,
                    1
                );

                $serialReceivingId = $serialRecord->receiving_id;
                $serialRecord->delete();
```

Update the `IssuanceDetail::create(...)` call (`:257-268`) to add `'receiving_id' => $serialReceivingId,` alongside the existing `'serial_number' => $raw,` line, and add `'fifo_warning' => $fifoWarning,` to the JSON response array (`:281-301`), e.g. right after `'success' => true,`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test tests/Feature/Issuance/IssuanceBatchFifoTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/IssuanceScanController.php back-end/tests/Feature/Issuance/IssuanceBatchFifoTest.php
git commit -m "feat(issuance): deduct exact batch on serialized scan, add FIFO warning"
```

---

### Task 9: `IssuanceScanController::scanItem()` consumable branch — FIFO cascade deduct

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/IssuanceScanController.php:334-358`
- Test: append to `back-end/tests/Feature/Issuance/IssuanceBatchFifoTest.php`

- [ ] **Step 1: Write the failing test**

Append to `IssuanceBatchFifoTest`:

```php
    public function test_scanning_bulk_item_deducts_oldest_batch_first(): void
    {
        $location = $this->makeLocation();
        $product  = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Consumable',
            'product_type' => 'installation_material', 'unit_price' => 50,
        ]);
        $customer = Customer::create(['customer_name' => 'Test Customer', 'customer_type' => 'individual']);
        $user     = $this->makeUser();

        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 10, 'available_quantity' => 10, 'reorder_level' => 0,
        ]);

        $svc = new InventoryBatchService();
        $old = $svc->createBatch($product->product_id, $location->location_id, $this->makeReceiving($location->location_id)->receiving_id, 6, now()->subDays(3));
        $new = $svc->createBatch($product->product_id, $location->location_id, $this->makeReceiving($location->location_id)->receiving_id, 4, now());

        $issuance = Issuance::create([
            'location_id' => $location->location_id, 'customer_id' => $customer->customer_id,
            'issuance_date' => now()->toDateString(), 'issuance_type' => 'individual',
            'dr_number' => 'DR-TEST-' . uniqid(), 'discount_type' => 'none', 'discount_percent' => 0,
            'subtotal' => 0, 'discount_amount' => 0, 'total_amount' => 0,
            'issued_by' => $user->user_id, 'status_id' => 30,
        ]);

        $res = $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id'   => $issuance->issuance_id,
            'barcode'       => $product->product_code,
            'quantity'      => 8,
            'quantity_unit' => 'unit',
        ]);

        $res->assertJson(['success' => true, 'action' => 'applied']);
        $this->assertSame(0, $old->fresh()->quantity_remaining);
        $this->assertSame(2, $new->fresh()->quantity_remaining);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test tests/Feature/Issuance/IssuanceBatchFifoTest.php --filter=test_scanning_bulk_item_deducts_oldest_batch_first`
Expected: FAIL — batches untouched (6/4 instead of 0/2). If it fails earlier because the barcode lookup doesn't resolve `product_code` as expected, check `findProductByBarcodeCandidates()` — it should already match `product_code` per the controller's Pass 2 lookup described in the research; no change needed there.

- [ ] **Step 3: Wire FIFO cascade into the consumable branch**

Replace the deduction block at `back-end/app/Http/Controllers/Api/IssuanceScanController.php:353-358`:

```php
            $inv->quantity_on_hand   = max(0, $inv->quantity_on_hand - $quantityInPieces);
            $inv->available_quantity = max(0, $inv->available_quantity - $quantityInPieces);
            if ($piecesPerPackage > 0) {
                $inv->packages_on_hand = (int) floor($inv->quantity_on_hand / $piecesPerPackage);
            }
            $inv->save();
```

with:

```php
            $inv->quantity_on_hand   = max(0, $inv->quantity_on_hand - $quantityInPieces);
            $inv->available_quantity = max(0, $inv->available_quantity - $quantityInPieces);
            if ($piecesPerPackage > 0) {
                $inv->packages_on_hand = (int) floor($inv->quantity_on_hand / $piecesPerPackage);
            }
            $inv->save();

            app(InventoryBatchService::class)->deductFifo($product->product_id, $locationId, $quantityInPieces);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test tests/Feature/Issuance/IssuanceBatchFifoTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/IssuanceScanController.php back-end/tests/Feature/Issuance/IssuanceBatchFifoTest.php
git commit -m "feat(issuance): FIFO-cascade deduct inventory_batches on bulk scan"
```

---

### Task 10: `IssuanceScanController::removeItem()` — restore batch quantity, fix serial `receiving_id` loss

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/IssuanceScanController.php:437-467`
- Test: `back-end/tests/Feature/Issuance/IssuanceRemoveItemBatchTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Issuance;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use App\Services\InventoryBatchService;

class IssuanceRemoveItemBatchTest extends TestCase
{
    use DatabaseTransactions;

    public function test_removing_serialized_item_restores_batch_and_recreates_serial_with_receiving_id(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Test Appliance',
            'product_type' => 'appliance', 'unit_price' => 100,
        ]);
        $customer = Customer::create(['customer_name' => 'Test Customer', 'customer_type' => 'individual']);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);

        $rcv   = Receiving::create(['location_id' => $location->location_id, 'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()]);
        $batch = (new InventoryBatchService())->createBatch($product->product_id, $location->location_id, $rcv->receiving_id, 1, now());
        $batch->quantity_remaining = 0;
        $batch->save();

        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 0, 'available_quantity' => 0, 'reorder_level' => 0,
        ]);

        $issuance = Issuance::create([
            'location_id' => $location->location_id, 'customer_id' => $customer->customer_id,
            'issuance_date' => now()->toDateString(), 'issuance_type' => 'individual',
            'dr_number' => 'DR-TEST-' . uniqid(), 'discount_type' => 'none', 'discount_percent' => 0,
            'subtotal' => 100, 'discount_amount' => 0, 'total_amount' => 100,
            'issued_by' => $user->user_id, 'status_id' => 30,
        ]);

        $sn = 'SN-' . uniqid();
        $detail = IssuanceDetail::create([
            'issuance_id' => $issuance->issuance_id, 'product_id' => $product->product_id,
            'serial_number' => $sn, 'receiving_id' => $rcv->receiving_id,
            'unit_price' => 100, 'quantity_issued' => 1, 'quantity_unit' => 'unit',
            'quantity_in_pieces' => 1, 'unit_price_per_piece' => 100, 'subtotal' => 100,
            'condition_issued' => 'Good',
        ]);

        $this->actingAs($user)->deleteJson("/api/issuance-scan/item/{$detail->issuance_detail_id}")
            ->assertJson(['success' => true]);

        $this->assertSame(1, $batch->fresh()->quantity_remaining);

        $serial = ItemSerial::where('serial_number', $sn)->first();
        $this->assertNotNull($serial);
        $this->assertSame($rcv->receiving_id, $serial->receiving_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test tests/Feature/Issuance/IssuanceRemoveItemBatchTest.php`
Expected: FAIL — `$batch->fresh()->quantity_remaining` is still 0, and the recreated `ItemSerial`'s `receiving_id` is null (the pre-existing bug this task fixes).

- [ ] **Step 3: Wire restoration and fix the serial recreation bug**

In `back-end/app/Http/Controllers/Api/IssuanceScanController.php`, replace the block at `:437-468`:

```php
        return DB::transaction(function () use ($detail, $issuance) {
            $product = Product::with(['applianceDetail', 'consumableDetail'])
                       ->find($detail->product_id);

            if ($product) {
                $inv = Inventory::where('product_id', $detail->product_id)
                    ->where('location_id', $issuance->location_id)
                    ->lockForUpdate()->first();

                if ($inv) {
                    $inv->quantity_on_hand   += $detail->quantity_in_pieces;
                    $inv->available_quantity += $detail->quantity_in_pieces;
                    if (! $product->isSerialized()) {
                        $pcs = $product->getPiecesPerPackage();
                        if ($pcs > 0) {
                            $inv->packages_on_hand = (int) floor($inv->quantity_on_hand / $pcs);
                        }
                    }
                    $inv->save();
                }

                if ($product->isSerialized() && $detail->serial_number) {
                    ItemSerial::create([
                        'product_id'    => $detail->product_id,
                        'location_id'   => $issuance->location_id,
                        'serial_number' => $detail->serial_number,
                        'serial_type'   => 'received',
                        'status_id'     => self::STATUS_SERIAL_IN_STOCK,
                        'scanned_by'    => Auth::id(),
                    ]);
                }
            }

            $detail->delete();
            $this->recalculateTotals($issuance->issuance_id);

            return response()->json([
                'success' => true,
                'message' => 'Item removed from issuance.',
            ]);
        });
```

with:

```php
        return DB::transaction(function () use ($detail, $issuance) {
            $product = Product::with(['applianceDetail', 'consumableDetail'])
                       ->find($detail->product_id);

            if ($product) {
                $inv = Inventory::where('product_id', $detail->product_id)
                    ->where('location_id', $issuance->location_id)
                    ->lockForUpdate()->first();

                if ($inv) {
                    $inv->quantity_on_hand   += $detail->quantity_in_pieces;
                    $inv->available_quantity += $detail->quantity_in_pieces;
                    if (! $product->isSerialized()) {
                        $pcs = $product->getPiecesPerPackage();
                        if ($pcs > 0) {
                            $inv->packages_on_hand = (int) floor($inv->quantity_on_hand / $pcs);
                        }
                    }
                    $inv->save();
                }

                app(InventoryBatchService::class)->restoreFifo(
                    $detail->product_id,
                    $issuance->location_id,
                    $detail->quantity_in_pieces,
                    $detail->receiving_id
                );

                if ($product->isSerialized() && $detail->serial_number) {
                    ItemSerial::create([
                        'product_id'    => $detail->product_id,
                        'location_id'   => $issuance->location_id,
                        'receiving_id'  => $detail->receiving_id,
                        'serial_number' => $detail->serial_number,
                        'serial_type'   => 'received',
                        'status_id'     => self::STATUS_SERIAL_IN_STOCK,
                        'scanned_by'    => Auth::id(),
                    ]);
                }
            }

            $detail->delete();
            $this->recalculateTotals($issuance->issuance_id);

            return response()->json([
                'success' => true,
                'message' => 'Item removed from issuance.',
            ]);
        });
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test tests/Feature/Issuance/IssuanceRemoveItemBatchTest.php`
Expected: PASS.

- [ ] **Step 5: Run the full issuance suite to check for regressions**

Run: `cd back-end && php artisan test tests/Feature/Issuance`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/IssuanceScanController.php back-end/tests/Feature/Issuance/IssuanceRemoveItemBatchTest.php
git commit -m "fix(issuance): restore inventory_batches and preserve receiving_id on removeItem"
```

---

### Task 11: `InventoryController::batches()` endpoint for the drill-down UI

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/InventoryController.php:1-20` (imports), add new method
- Modify: `back-end/routes/api.php:795` (add route)
- Test: `back-end/tests/Feature/Inventory/InventoryBatchesEndpointTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use App\Services\InventoryBatchService;

class InventoryBatchesEndpointTest extends TestCase
{
    use DatabaseTransactions;

    public function test_batches_endpoint_returns_batches_oldest_first_with_legacy_last(): void
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

        $inv = Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 15, 'available_quantity' => 15, 'reorder_level' => 0,
        ]);

        $svc = new InventoryBatchService();
        $rcv1 = Receiving::create(['location_id' => $location->location_id, 'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()->subDays(5)]);
        $rcv2 = Receiving::create(['location_id' => $location->location_id, 'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()]);
        $svc->createBatch($product->product_id, $location->location_id, $rcv1->receiving_id, 5, now()->subDays(5));
        $svc->createBatch($product->product_id, $location->location_id, $rcv2->receiving_id, 10, now());

        $res = $this->actingAs($user)->getJson("/api/inventory/{$inv->inventory_id}/batches");

        $res->assertOk();
        $data = $res->json('data');
        $this->assertCount(2, $data);
        $this->assertSame($rcv1->receiving_id, $data[0]['receiving_id']);
        $this->assertSame($rcv2->receiving_id, $data[1]['receiving_id']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test tests/Feature/Inventory/InventoryBatchesEndpointTest.php`
Expected: FAIL — 404, route doesn't exist yet.

- [ ] **Step 3: Add the route**

In `back-end/routes/api.php`, right after the existing line 795 (`Route::get('/inventory/{inventory}/history', [InventoryController::class, 'stockHistory']);`), add:

```php
    Route::get('/inventory/{inventory}/batches', [InventoryController::class, 'batches']);
```

- [ ] **Step 4: Add the controller method**

In `back-end/app/Http/Controllers/Api/InventoryController.php`, add the import alongside existing ones near the top of the file:

```php
use App\Services\InventoryBatchService;
```

Then add a new public method (e.g. right after `index()`, before `stockHistory()`):

```php
    public function batches(Inventory $inventory)
    {
        $batches = app(InventoryBatchService::class)
            ->batchesForDisplay($inventory->product_id, $inventory->location_id);

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

        return $this->success($data, 'Inventory batches retrieved successfully');
    }
```

(`Inventory` is already imported and route-model-bound elsewhere in this controller — confirm the `use App\Models\Inventory;` import exists at the top; add it if missing.)

- [ ] **Step 5: Run test to verify it passes**

Run: `cd back-end && php artisan test tests/Feature/Inventory/InventoryBatchesEndpointTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/InventoryController.php back-end/routes/api.php back-end/tests/Feature/Inventory/InventoryBatchesEndpointTest.php
git commit -m "feat(api): add GET /inventory/{id}/batches for the drill-down UI"
```

---

### Task 12: Frontend — expandable batch drill-down on the Inventory page

**Files:**
- Modify: `frontend/src/admin/services/api.js:597-625` (add `batches` method)
- Modify: `frontend/src/admin/pages/InventoryManagement.jsx:100-123` (state), `:692-726` (row rendering)

- [ ] **Step 1: Add the API client method**

In `frontend/src/admin/services/api.js`, inside `export const inventoryAPI = { ... }` (starting `:597`), add after `getById`:

```js
  getBatches: async (id) => apiRequest(`/inventory/${id}/batches`),
```

- [ ] **Step 2: Add expand state**

In `frontend/src/admin/pages/InventoryManagement.jsx`, near the other inventory state (after `:117`, `const [invPulse, fireInvPulse] = useFocusPulse();`), add:

```jsx
  const [expandedBatchIds, setExpandedBatchIds] = useState(new Set());
  const [batchesByInventoryId, setBatchesByInventoryId] = useState({});
  const [batchesLoading, setBatchesLoading] = useState({});
```

Add a toggle handler near the other handlers in the component (anywhere before the return statement):

```jsx
  const toggleBatches = async (inventoryId) => {
    setExpandedBatchIds((prev) => {
      const next = new Set(prev);
      if (next.has(inventoryId)) {
        next.delete(inventoryId);
      } else {
        next.add(inventoryId);
      }
      return next;
    });

    if (!batchesByInventoryId[inventoryId]) {
      setBatchesLoading((prev) => ({ ...prev, [inventoryId]: true }));
      try {
        const res = await inventoryAPI.getBatches(inventoryId);
        const list = res?.data ?? [];
        setBatchesByInventoryId((prev) => ({ ...prev, [inventoryId]: list }));
      } catch {
        setBatchesByInventoryId((prev) => ({ ...prev, [inventoryId]: [] }));
      } finally {
        setBatchesLoading((prev) => ({ ...prev, [inventoryId]: false }));
      }
    }
  };
```

(`inventoryAPI` is already imported at the top of this file per the existing `inventoryAPI.getAll(...)` usage in the lookups effect — no new import needed.)

- [ ] **Step 3: Render the expand toggle and drill-down row**

In the row-mapping loop (`frontend/src/admin/pages/InventoryManagement.jsx:692-726`), replace:

```jsx
                      inventory.map((item) => {
                        const status = getItemStatus(item);
                        return (
                          <tr key={item.inventory_id}>
```

with:

```jsx
                      inventory.map((item) => {
                        const status = getItemStatus(item);
                        const isExpanded = expandedBatchIds.has(item.inventory_id);
                        const rowBatches = batchesByInventoryId[item.inventory_id] || [];
                        return (
                          <React.Fragment key={item.inventory_id}>
                          <tr>
```

Change the corresponding closing `</tr>` at `:724` and the loop's closing `);` at `:725-726` to:

```jsx
                          </tr>
                          {isExpanded && (
                            <tr className="inv-batch-row">
                              <td colSpan={9}>
                                {batchesLoading[item.inventory_id] ? (
                                  <div className="inv-batch-loading">Loading batches…</div>
                                ) : rowBatches.length === 0 ? (
                                  <div className="inv-batch-empty">No batch history for this item.</div>
                                ) : (
                                  <table className="inv-batch-table">
                                    <thead>
                                      <tr>
                                        <th>PO Number</th>
                                        <th>Received</th>
                                        <th>Qty Remaining</th>
                                        <th>Qty Received</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                      {rowBatches.map((b) => (
                                        <tr key={b.batch_id}>
                                          <td>{b.is_legacy ? 'Pre-tracking stock' : (b.po_number || '—')}</td>
                                          <td>{b.received_at || '—'}</td>
                                          <td>{b.quantity_remaining}</td>
                                          <td>{b.quantity_received}</td>
                                        </tr>
                                      ))}
                                    </tbody>
                                  </table>
                                )}
                              </td>
                            </tr>
                          )}
                          </React.Fragment>
                        );
                      })
```

Add the expand toggle button in the actions cell (`:719-723`), changing:

```jsx
                            <td><div className="inv-cell-actions"><button className="inv-action-link" onClick={() => { setViewingItem(item); ... loadInventoryHistory(item.inventory_id); }}>View</button></div></td>
```

by adding a sibling button before the existing `View` button:

```jsx
                            <td>
                              <div className="inv-cell-actions">
                                <button
                                  className="inv-action-link"
                                  onClick={() => toggleBatches(item.inventory_id)}
                                  aria-expanded={isExpanded}
                                >
                                  {isExpanded ? 'Hide batches' : 'Batches'}
                                </button>
                                <button className="inv-action-link" onClick={() => { setViewingItem(item); /* existing history load call */ }}>View</button>
                              </div>
                            </td>
```

(Keep the existing `View` button's onClick body exactly as it is today — only the surrounding markup changes to add the new `Batches` button alongside it. Verify `React` is imported at the top of the file — `import React, { ... } from 'react';` — since `React.Fragment` is now used; if the file only imports named hooks, add `React` to the import.)

- [ ] **Step 4: Manual verification (per project convention — no RTL component tests in this codebase)**

Run: `cd frontend && npm start` (or `npm run demo` for a prod-like build per project convention), open the Inventory tab, click "Batches" on a row with receiving history, and confirm:
- The row expands showing PO number, received date, remaining/received quantities, oldest batch first.
- Clicking again collapses it.
- A row with no batches shows "No batch history for this item."

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/services/api.js frontend/src/admin/pages/InventoryManagement.jsx
git commit -m "feat(ui): add expandable batch drill-down to Inventory page"
```

---

### Task 13: Frontend — FIFO warning banner on the issuance scan screen

**Files:**
- Modify: `frontend/src/admin/pages/IssuanceScanPage.jsx:588-613` (scan response handling), `:1382-1387` (banner rendering)

- [ ] **Step 1: Capture the warning in scan feedback state**

In `frontend/src/admin/pages/IssuanceScanPage.jsx`, replace the success branch of `handleScanResponse` (`:589-604`):

```jsx
    if (data?.success && data?.action === 'applied') {
      refreshSession();
      playScanFeedback(true);
      if (data.product_type === 'appliance') {
        setScanFeedback({
          type: 'success',
          message: `${data.product_name} — SN: ${data.serial_number}`,
          detail: `${fmt(data.subtotal)}`,
        });
      } else {
        setScanFeedback({
          type: 'success',
          message: `${data.product_name} — ${data.quantity_issued} ${data.quantity_unit}`,
          detail: `${fmt(data.subtotal)} (${'₱'}${Number(data.price_per_piece).toFixed(4)} per ${data.quantity_unit})`,
        });
      }
    } else {
```

with:

```jsx
    if (data?.success && data?.action === 'applied') {
      refreshSession();
      playScanFeedback(true);
      if (data.product_type === 'appliance') {
        setScanFeedback({
          type: 'success',
          message: `${data.product_name} — SN: ${data.serial_number}`,
          detail: `${fmt(data.subtotal)}`,
          warning: data.fifo_warning || null,
        });
      } else {
        setScanFeedback({
          type: 'success',
          message: `${data.product_name} — ${data.quantity_issued} ${data.quantity_unit}`,
          detail: `${fmt(data.subtotal)} (${'₱'}${Number(data.price_per_piece).toFixed(4)} per ${data.quantity_unit})`,
          warning: data.fifo_warning || null,
        });
      }
    } else {
```

- [ ] **Step 2: Render the warning line**

Replace the banner markup at `:1382-1387`:

```jsx
                {scanFeedback.type && (
                  <div className={`scan-feedback scan-feedback--${scanFeedback.type}`}>
                    <span className="scan-feedback-message">{scanFeedback.message}</span>
                    {scanFeedback.detail && (
                      <span className="scan-feedback-detail">{scanFeedback.detail}</span>
```

with (only adding the warning span; keep the rest of the block, including its closing tags, unchanged):

```jsx
                {scanFeedback.type && (
                  <div className={`scan-feedback scan-feedback--${scanFeedback.type}`}>
                    <span className="scan-feedback-message">{scanFeedback.message}</span>
                    {scanFeedback.warning && (
                      <span className="scan-feedback-warning">{scanFeedback.warning}</span>
                    )}
                    {scanFeedback.detail && (
                      <span className="scan-feedback-detail">{scanFeedback.detail}</span>
```

- [ ] **Step 3: Add a style for the warning span**

Find the stylesheet used by this page (grep `scan-feedback-detail` to locate the CSS file) and add a sibling rule, e.g.:

```css
.scan-feedback-warning {
  display: block;
  color: #b45309;
  font-size: 0.85rem;
  margin-top: 2px;
}
```

- [ ] **Step 4: Manual verification**

Run the frontend dev server, start an issuance session, receive a serialized product in two separate receiving batches (older then newer) at the same location so both have available serials, then scan a serial from the newer batch. Confirm the warning banner appears in amber below the success message, and confirm scanning the older batch's serial shows no warning.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/pages/IssuanceScanPage.jsx frontend/src/admin/styles/
git commit -m "feat(ui): show FIFO warning banner on issuance scan"
```

---

## Self-Review Notes

- **Spec coverage:** data model (Task 1, 3), receiving flow (Task 5), bulk FIFO cascade on transfer/issuance (Task 6, 9), serialized exact deduction + advisory warning (Task 6, 8), monitoring UI drill-down (Task 12), legacy-batch error handling (built into `InventoryBatchService`, exercised in Tasks 4–10's tests). All spec sections have a corresponding task.
- **Extensions beyond spec, both justified inline:** the `issuance_details.receiving_id` column (Task 2) and its use in Task 8/10 fix a pre-existing data-loss bug (`removeItem()` recreating serials without `receiving_id`) that batch tracking would otherwise inherit permanently. `TransferService::reverse()`'s legacy-fallback restoration for bulk products (Task 7) is flagged as an accepted simplification, not silently glossed over.
- **Type/signature consistency:** `InventoryBatchService` methods (`createBatch`, `deductFifo`, `deductFromReceiving`, `restoreFifo`, `legacyBatch`, `batchesForDisplay`) are defined once in Task 4 and used with identical signatures in Tasks 5–11 — no renamed variants.
