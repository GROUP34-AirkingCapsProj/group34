# One PO per Supplier, Per-Line Warehouse — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert a multi-warehouse purchase request into a single purchase order whose lines each carry their own destination warehouse, with location-scoped receiving, per-warehouse budget settlement, and a grouped-by-warehouse PO document/export.

**Architecture:** Add `location_id` to `purchase_order_details`. `convertStore` stops splitting and emits one PO with per-line locations. `BudgetService::spend` settles per warehouse group (falling back to the existing whole-PO path for single-warehouse/legacy POs). Receiving already routes stock to the location it is given; the budget trigger becomes group-aware. Frontend groups PO line items by warehouse in the detail modal and both exports.

**Tech Stack:** Laravel 10 (PHP 8.2), MariaDB (schema is a SQL dump — migrations applied individually with `--path`), PHPUnit feature tests with `DatabaseTransactions` against `airkingV5_test`, React CRA frontend.

**Spec:** `docs/superpowers/specs/2026-07-12-one-po-per-supplier-per-line-warehouse-design.md`

**Conventions (from project memory):**
- Never run blanket `php artisan migrate` (schema is a SQL dump). Apply one migration with `--path=` and `--force`.
- Apply each migration to BOTH `airkingV5` (dev) and `airkingV5_test`.
- Backend tests run from `back-end/`: `php artisan test --filter=<Name>`.
- Frontend runs from `frontend/`: `npx eslint <file>` and `CI=false npx react-scripts build`.

---

### Task 1: Add `location_id` to `purchase_order_details`

**Files:**
- Create: `back-end/database/migrations/2026_07_12_000001_add_location_id_to_purchase_order_details.php`
- Modify: `back-end/app/Models/PurchaseOrderDetail.php`

- [ ] **Step 1: Write the migration (guarded, matches existing column-add style)**

Create `back-end/database/migrations/2026_07_12_000001_add_location_id_to_purchase_order_details.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('purchase_order_details', 'location_id')) {
            Schema::table('purchase_order_details', function (Blueprint $table) {
                $table->unsignedBigInteger('location_id')->nullable()->after('po_id');
                $table->foreign('location_id')
                    ->references('location_id')->on('locations')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_order_details', 'location_id')) {
            Schema::table('purchase_order_details', function (Blueprint $table) {
                $table->dropForeign(['location_id']);
                $table->dropColumn('location_id');
            });
        }
    }
};
```

- [ ] **Step 2: Apply the migration to BOTH databases**

Run from `back-end/`:

```bash
php artisan migrate --path=database/migrations/2026_07_12_000001_add_location_id_to_purchase_order_details.php --force
php artisan migrate --path=database/migrations/2026_07_12_000001_add_location_id_to_purchase_order_details.php --force --env=testing
```

Expected: `Migrated: 2026_07_12_000001_add_location_id_to_purchase_order_details` on each run. If the `--env=testing` run reports "Nothing to migrate" or errors on connection, instead set the test DB explicitly:
`DB_DATABASE=airkingV5_test php artisan migrate --path=database/migrations/2026_07_12_000001_add_location_id_to_purchase_order_details.php --force`

- [ ] **Step 3: Add `location_id` to the model + a `location()` relation**

In `back-end/app/Models/PurchaseOrderDetail.php`, add `'location_id'` to `$fillable` (after `'po_id'`) and add the relation after `product()`:

```php
    protected $fillable = [
        'po_id',
        'location_id',
        'product_id',
        'quantity_ordered',
        'unit_price',
        'subtotal',
    ];
```

```php
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }
```

Add `use App\Models\Location;` is not needed (same namespace `App\Models`).

- [ ] **Step 4: Verify the column + relation with a quick test**

Create `back-end/tests/Feature/Budget/PoDetailLocationColumnTest.php`:

```php
<?php

namespace Tests\Feature\Budget;

use App\Models\PurchaseOrderDetail;
use Illuminate\Support\Facades\Schema;

class PoDetailLocationColumnTest extends BudgetTestCase
{
    public function test_location_id_column_and_relation_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('purchase_order_details', 'location_id'));
        $this->assertTrue(method_exists(PurchaseOrderDetail::class, 'location'));
    }
}
```

Run: `php artisan test --filter=PoDetailLocationColumnTest`
Expected: PASS (2 assertions).

- [ ] **Step 5: Commit**

```bash
git add back-end/database/migrations/2026_07_12_000001_add_location_id_to_purchase_order_details.php back-end/app/Models/PurchaseOrderDetail.php back-end/tests/Feature/Budget/PoDetailLocationColumnTest.php
git commit -m "feat(po): add per-line location_id to purchase_order_details"
```

---

### Task 2: Conversion emits ONE PO with per-line warehouses

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseRequestController.php:719-778` (the `convertStore` transaction)
- Modify: `back-end/tests/Feature/Budget/PrConvertSplitTest.php:22-61` (the split test → single-PO test)

- [ ] **Step 1: Rewrite the failing test to expect ONE PO with per-line locations**

In `back-end/tests/Feature/Budget/PrConvertSplitTest.php`, replace `test_convert_splits_into_one_po_per_warehouse` (lines 22-61) with:

```php
    public function test_convert_creates_one_po_with_per_line_warehouses(): void
    {
        $admin = $this->makeUser('admin');
        $supplier = (int) DB::table('suppliers')->insertGetId(['supplier_name' => 'S', 'created_at' => now(), 'updated_at' => now()], 'supplier_id');
        $whA = $this->locationId; $whB = $this->warehouse();
        $this->makeBudget(10000, ['location_id' => $whA]);
        $this->makeBudget(10000, ['location_id' => $whB]);
        $pid = (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');

        $pr = PurchaseRequest::create([
            'location_id' => null, 'supplier_id' => $supplier, 'budget_id' => null,
            'pr_number' => 'PR-' . strtoupper(Str::random(6)),
            'request_date' => today(), 'needed_by_date' => today()->addDays(7),
            'estimated_total' => 500, 'status_id' => 14,
            'requested_by' => $this->makeUser('inventory_analyst')->user_id,
            'approved_by' => $admin->user_id, 'approval_date' => today(),
        ]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whA,'quantity'=>2,'estimated_price'=>100,'subtotal'=>200]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whB,'quantity'=>3,'estimated_price'=>100,'subtotal'=>300]);

        $res = $this->actingAs($admin, 'sanctum')->postJson("/api/purchase-requests/{$pr->pr_id}/convert", [
            'details' => [
                ['product_id' => $pid, 'location_id' => $whA, 'quantity_ordered' => 2, 'unit_price' => 100],
                ['product_id' => $pid, 'location_id' => $whB, 'quantity_ordered' => 3, 'unit_price' => 100],
            ],
        ]);

        $res->assertSuccessful();
        $pos = PurchaseOrder::where('pr_id', $pr->pr_id)->get();
        $this->assertCount(1, $pos, 'conversion must create exactly one PO');
        $po = $pos->first();
        $this->assertSame($supplier, (int) $po->supplier_id);
        $this->assertSame(7, (int) $po->status_id);
        $this->assertSame(500.0, (float) $po->total_amount);

        $lineLocs = \App\Models\PurchaseOrderDetail::where('po_id', $po->po_id)
            ->pluck('location_id')->map(fn ($v) => (int) $v)->all();
        $this->assertEqualsCanonicalizing([$whA, $whB], $lineLocs);
        $this->assertContains((int) $po->location_id, [$whA, $whB]); // header = a primary line warehouse
    }
```

- [ ] **Step 2: Run the test to confirm it fails against current split behavior**

Run: `php artisan test --filter=test_convert_creates_one_po_with_per_line_warehouses`
Expected: FAIL — asserts 1 PO but current code creates 2.

- [ ] **Step 3: Rewrite the `convertStore` transaction to emit one PO**

In `back-end/app/Http/Controllers/Api/PurchaseRequestController.php`, replace the transaction body (lines 719-778, from `$firstPo = DB::transaction(...)` through its closing `});`) with:

```php
            $po = DB::transaction(function () use ($pr, $validated, $request, $hasLineWarehouses) {
                $mkLine = function ($d) {
                    $d['subtotal'] = $d['quantity_ordered'] * $d['unit_price'];
                    return $d;
                };
                $lines = collect($validated['details'])->map($mkLine);
                $grand = $lines->sum('subtotal');

                $supplierId = $hasLineWarehouses ? $pr->supplier_id : $validated['supplier_id'];
                // Header location_id is a compatibility "primary": the first line's
                // warehouse for the new shape, or the PR header for legacy.
                $headerLocationId = $hasLineWarehouses
                    ? (int) $lines->first()['location_id']
                    : (int) $pr->location_id;

                $po = PurchaseOrder::create([
                    'pr_id'                  => $pr->pr_id,
                    'supplier_id'            => $supplierId,
                    'location_id'            => $headerLocationId,
                    'po_number'              => 'PO-' . date('Ymd') . '-' . strtoupper(Str::random(4)),
                    'order_date'             => today(),
                    'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                    'payment_terms'          => $validated['payment_terms']    ?? null,
                    'notes'                  => $validated['notes']            ?? null,
                    'references_note'        => $validated['references_note']  ?? null,
                    'ship_to_name'           => $validated['ship_to_name']     ?? null,
                    'ship_to_address'        => $validated['ship_to_address']  ?? null,
                    'total_amount'           => $grand,
                    'grand_total'            => $grand,
                    'status_id'              => 7,
                    'created_by'             => $request->user()->user_id,
                ]);

                foreach ($lines as $line) {
                    PurchaseOrderDetail::create([
                        'po_id'            => $po->po_id,
                        'location_id'      => $hasLineWarehouses ? (int) $line['location_id'] : null,
                        'product_id'       => $line['product_id'],
                        'quantity_ordered' => $line['quantity_ordered'],
                        'unit_price'       => $line['unit_price'],
                        'subtotal'         => $line['subtotal'],
                    ]);
                }

                $pr->update(['converted_to_po_id' => $po->po_id]);

                $orderedByProdLoc = collect($validated['details'])->groupBy(fn ($d) =>
                    ($hasLineWarehouses ? $d['location_id'] : '_') . ':' . $d['product_id'])
                    ->map(fn ($rows) => collect($rows)->sum('quantity_ordered'));
                $isPartial = $pr->details->contains(function ($prDetail) use ($orderedByProdLoc, $hasLineWarehouses) {
                    $key = ($hasLineWarehouses ? $prDetail->location_id : '_') . ':' . $prDetail->product_id;
                    return ($orderedByProdLoc[$key] ?? 0) < (int) $prDetail->quantity;
                });
                $pr->update(['status_id' => $isPartial ? 16 : 14]);

                return $po;
            });
```

Then update the two references after the transaction: change `$firstPo->load(...)` to `$po->load(...)` and `return $this->success($firstPo, ...)` to `return $this->success($po, ...)` (lines ~789-791).

- [ ] **Step 4: Run the conversion tests (all three in the file must pass)**

Run: `php artisan test --filter=PrConvertSplitTest`
Expected: PASS — `test_convert_creates_one_po_with_per_line_warehouses`, `test_reconvert_blocked`, `test_convert_rejects_line_for_warehouse_not_on_pr` all green.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/PurchaseRequestController.php back-end/tests/Feature/Budget/PrConvertSplitTest.php
git commit -m "feat(po): convert multi-warehouse PR into one PO with per-line warehouses"
```

---

### Task 3: Expose per-line warehouse in PO serialization

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseOrderController.php:21-33` (WITH_RELATIONS) and `:539-564` (`transformPoDetail`)

- [ ] **Step 1: Eager-load the line location**

In `WITH_RELATIONS` (the const array starting line 21), add `'details.location'` after `'details.approvedBy',`:

```php
        'details.approvedBy',
        'details.location',
```

- [ ] **Step 2: Add location fields to `transformPoDetail`**

In `transformPoDetail` (line 539), add these keys right after the `'subtotal'` line (line 551):

```php
            'subtotal'           => $detail->subtotal,
            // Destination warehouse for this line (per-line warehouse PO shape)
            'location_id'        => $detail->location_id,
            'location_name'      => $detail->location?->location_name,
```

- [ ] **Step 3: Verify the API returns the field**

Add to `back-end/tests/Feature/Budget/PrConvertSplitTest.php` a new test:

```php
    public function test_po_show_returns_per_line_warehouse(): void
    {
        $admin = $this->makeUser('admin');
        $supplier = (int) DB::table('suppliers')->insertGetId(['supplier_name' => 'S', 'created_at' => now(), 'updated_at' => now()], 'supplier_id');
        $whA = $this->locationId; $whB = $this->warehouse();
        $this->makeBudget(10000, ['location_id' => $whA]);
        $this->makeBudget(10000, ['location_id' => $whB]);
        $pid = (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
        $pr = PurchaseRequest::create([
            'location_id' => null, 'supplier_id' => $supplier, 'budget_id' => null,
            'pr_number' => 'PR-' . strtoupper(Str::random(6)),
            'request_date' => today(), 'needed_by_date' => today()->addDays(7),
            'estimated_total' => 500, 'status_id' => 14,
            'requested_by' => $this->makeUser('inventory_analyst')->user_id,
        ]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whA,'quantity'=>2,'estimated_price'=>100,'subtotal'=>200]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whB,'quantity'=>3,'estimated_price'=>100,'subtotal'=>300]);
        $this->actingAs($admin, 'sanctum')->postJson("/api/purchase-requests/{$pr->pr_id}/convert", [
            'details' => [
                ['product_id' => $pid, 'location_id' => $whA, 'quantity_ordered' => 2, 'unit_price' => 100],
                ['product_id' => $pid, 'location_id' => $whB, 'quantity_ordered' => 3, 'unit_price' => 100],
            ],
        ])->assertSuccessful();
        $poId = (int) PurchaseOrder::where('pr_id', $pr->pr_id)->value('po_id');

        $res = $this->actingAs($admin, 'sanctum')->getJson("/api/purchase-orders/{$poId}");
        $res->assertSuccessful();
        $locs = collect($res->json('data.details'))->pluck('location_id')->map(fn ($v) => (int) $v)->all();
        $this->assertEqualsCanonicalizing([$whA, $whB], $locs);
        $this->assertNotNull($res->json('data.details.0.location_name'));
    }
```

Run: `php artisan test --filter=test_po_show_returns_per_line_warehouse`
Expected: PASS. (If the PO `show` route differs, confirm with `php artisan route:list --path=purchase-orders`; the show endpoint is `GET /api/purchase-orders/{id}`.)

- [ ] **Step 4: Commit**

```bash
git add back-end/app/Http/Controllers/Api/PurchaseOrderController.php back-end/tests/Feature/Budget/PrConvertSplitTest.php
git commit -m "feat(po): expose per-line destination warehouse in PO serialization"
```

---

### Task 4: Per-warehouse budget settlement

**Files:**
- Modify: `back-end/app/Services/BudgetService.php:148-235` (`spend`, `resolveBudgetForPo`, `poCoversItsGroup`)
- Modify: `back-end/app/Http/Controllers/Api/ReceivingController.php:144-150` (spend trigger)
- Create: `back-end/tests/Feature/Budget/PoPerWarehouseSpendTest.php`

- [ ] **Step 1: Write the failing per-warehouse settlement test**

Create `back-end/tests/Feature/Budget/PoPerWarehouseSpendTest.php`:

```php
<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetAllocation;
use App\Models\BudgetTransaction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestDetail;
use App\Models\Receiving;
use App\Models\ReceivingDetail;
use App\Services\BudgetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PoPerWarehouseSpendTest extends BudgetTestCase
{
    private function warehouse(string $name): int
    {
        return (int) DB::table('locations')->insertGetId([
            'location_code' => 'WH-' . uniqid(), 'location_name' => $name,
            'location_type' => 'warehouse', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], 'location_id');
    }

    private function product(): int
    {
        return (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
    }

    /** Receive every ordered unit of one warehouse's lines on the PO. */
    private function receiveWarehouse(PurchaseOrder $po, int $locationId): void
    {
        $lines = PurchaseOrderDetail::where('po_id', $po->po_id)
            ->where('location_id', $locationId)->get();
        $receiving = Receiving::create([
            'po_id' => $po->po_id,
            'location_id' => $locationId,
            'receiving_number' => 'RC-' . strtoupper(Str::random(6)),
            'receiving_date' => today(),
            'received_by' => $po->created_by,
        ]);
        foreach ($lines as $line) {
            ReceivingDetail::create([
                'receiving_id' => $receiving->receiving_id,
                'po_detail_id' => $line->po_detail_id,
                'product_id' => $line->product_id,
                'quantity_amount' => $line->quantity_ordered,
                'prod_price' => $line->unit_price,
                'condition' => 'Good',
            ]);
        }
    }

    public function test_each_warehouse_budget_settles_independently_as_it_is_received(): void
    {
        $whA = $this->warehouse('WH-A');
        $whB = $this->warehouse('WH-B');
        $budgetA = $this->makeBudget(10000, ['location_id' => $whA]);
        $budgetB = $this->makeBudget(10000, ['location_id' => $whB]);
        $pid = $this->product();

        // PR committed per warehouse (mirror what PR-approve writes): commit A=200, B=300.
        $pr = PurchaseRequest::create([
            'location_id' => null, 'budget_id' => null,
            'pr_number' => 'PR-' . strtoupper(Str::random(6)),
            'request_date' => today(), 'needed_by_date' => today()->addDays(7),
            'estimated_total' => 500, 'status_id' => 14,
            'requested_by' => $this->makeUser('inventory_analyst')->user_id,
            'converted_to_po_id' => null,
        ]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whA,'quantity'=>2,'estimated_price'=>100,'subtotal'=>200]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whB,'quantity'=>3,'estimated_price'=>100,'subtotal'=>300]);

        $svc = app(BudgetService::class);
        $svc->commitGroup($pr, $budgetA->fresh(), 200.0, null);
        $svc->commitGroup($pr, $budgetB->fresh(), 300.0, null);

        // One PO spanning both warehouses.
        $po = PurchaseOrder::create([
            'pr_id' => $pr->pr_id, 'supplier_id' => (int) DB::table('suppliers')->insertGetId(['supplier_name'=>'S','created_at'=>now(),'updated_at'=>now()], 'supplier_id'),
            'location_id' => $whA, 'po_number' => 'PO-' . strtoupper(Str::random(6)),
            'order_date' => today(), 'total_amount' => 500, 'grand_total' => 500,
            'status_id' => 7, 'created_by' => $pr->requested_by,
        ]);
        PurchaseOrderDetail::create(['po_id'=>$po->po_id,'location_id'=>$whA,'product_id'=>$pid,'quantity_ordered'=>2,'unit_price'=>100,'subtotal'=>200]);
        PurchaseOrderDetail::create(['po_id'=>$po->po_id,'location_id'=>$whB,'product_id'=>$pid,'quantity_ordered'=>3,'unit_price'=>100,'subtotal'=>300]);

        // Receive warehouse A only, then settle.
        $this->receiveWarehouse($po, $whA);
        $svc->spend($po->fresh(), null);

        $this->assertSame(200.0, (float) $budgetA->fresh()->spent, 'A settled its 200');
        $this->assertSame(0.0,   (float) $budgetB->fresh()->spent, 'B not yet received');
        $this->assertSame(0.0,   (float) $budgetA->fresh()->committed, 'A commitment released');
        $this->assertSame(300.0, (float) $budgetB->fresh()->committed, 'B still committed');

        // Now receive warehouse B and settle again (idempotent for A).
        $this->receiveWarehouse($po, $whB);
        $svc->spend($po->fresh(), null);

        $this->assertSame(200.0, (float) $budgetA->fresh()->spent, 'A not double-spent');
        $this->assertSame(300.0, (float) $budgetB->fresh()->spent, 'B now settled');
        $this->assertSame(0.0,   (float) $budgetB->fresh()->committed, 'B commitment released');

        // Exactly one SPEND txn per budget.
        $this->assertSame(1, BudgetTransaction::where('po_id',$po->po_id)->where('budget_id',$budgetA->budget_id)->where('txn_type',BudgetTransaction::TYPE_SPEND)->count());
        $this->assertSame(1, BudgetTransaction::where('po_id',$po->po_id)->where('budget_id',$budgetB->budget_id)->where('txn_type',BudgetTransaction::TYPE_SPEND)->count());
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test --filter=PoPerWarehouseSpendTest`
Expected: FAIL — current `spend()` settles a single budget for the whole PO by `po.location_id`, so B never settles and A's amount is wrong.

- [ ] **Step 3: Make `spend()` group-aware; keep the whole-PO path for single/legacy**

In `back-end/app/Services/BudgetService.php`, replace the `spend()` method (lines 148-213) with a dispatcher plus the original body renamed to `spendWhole`, and add group helpers. First, replace the `public function spend(...)` signature/body opening so the method becomes:

```php
    public function spend(PurchaseOrder $po, ?int $userId = null): void
    {
        $pr = $po->purchaseRequest;
        if (!$pr) {
            return;
        }

        $po->loadMissing('details');
        $groups = $po->details->groupBy(fn ($d) => (int) ($d->location_id ?? $po->location_id));

        // Single-warehouse / legacy / no-detail PO → settle the whole PO against one
        // budget exactly as before (preserves all existing single-warehouse behavior).
        if ($groups->count() <= 1) {
            $this->spendWhole($po, $pr, $userId);
            return;
        }

        // Multi-warehouse PO → settle each warehouse group as its lines are fully received.
        foreach ($groups as $locationId => $lines) {
            if (!$this->groupFullyReceived($lines)) {
                continue;
            }
            $this->spendGroup($po, $pr, (int) $locationId, $lines, $userId);
        }
    }

    /** Legacy/single-warehouse settlement: one budget resolved from po.location_id. */
    private function spendWhole(PurchaseOrder $po, PurchaseRequest $pr, ?int $userId): void
    {
        $budgetId = $this->resolveBudgetForLocation($pr, (int) $po->location_id);
        if (!$budgetId) {
            return;
        }

        DB::transaction(function () use ($po, $pr, $budgetId, $userId) {
            $budget = BudgetAllocation::whereKey($budgetId)->lockForUpdate()->first();
            if (!$budget) {
                return;
            }
            if (BudgetTransaction::where('po_id', $po->po_id)
                ->where('txn_type', BudgetTransaction::TYPE_SPEND)->exists()) {
                return; // already recorded
            }

            $utilBefore = $this->utilization($budget);

            $amount = (float) $po->total_amount;
            $this->txn($budget, BudgetTransaction::TYPE_SPEND, $amount, $pr->pr_id, $po->po_id, $userId,
                'PO ' . $po->po_number . ' completed');
            $budget->spent = round((float) $budget->spent + $amount, 2);

            $outstanding = $this->outstanding($budget->budget_id, $pr->pr_id);
            if ($outstanding < 0) {
                Log::warning("Budget ledger anomaly: negative outstanding {$outstanding} for PR {$pr->pr_id} on budget {$budget->budget_id}");
            }
            $released = 0.0;

            $consume = round(min($outstanding, $amount), 2);
            if ($consume > 0) {
                $this->txn($budget, BudgetTransaction::TYPE_RELEASE, $consume, $pr->pr_id, $po->po_id, $userId,
                    'Commitment consumed by PO ' . $po->po_number);
                $released += $consume;
            }

            $coversGroup = ((int) $pr->status_id === 14 && (int) $pr->converted_to_po_id === (int) $po->po_id)
                || $this->poCoversLocationGroup($pr, $po, (int) $po->location_id);
            if ($coversGroup) {
                $surplus = round($outstanding - $consume, 2);
                if ($surplus > 0) {
                    $this->txn($budget, BudgetTransaction::TYPE_RELEASE, $surplus, $pr->pr_id, $po->po_id, $userId,
                        'Estimate surplus returned');
                    $released += $surplus;
                }
            }

            $newCommitted = round((float) $budget->committed - $released, 2);
            if ($newCommitted < 0) {
                Log::warning("Budget ledger anomaly: committed clamped from {$newCommitted} to 0 on budget {$budget->budget_id} (run budget:rebuild-totals)");
                $newCommitted = 0.0;
            }
            $budget->committed = $newCommitted;
            $budget->save();

            $this->maybeAlert($budget, $utilBefore);
        });
    }

    /** One warehouse group of a multi-warehouse PO, keyed idempotently per (po, budget). */
    private function spendGroup(PurchaseOrder $po, PurchaseRequest $pr, int $locationId, $lines, ?int $userId): void
    {
        $budgetId = $this->resolveBudgetForLocation($pr, $locationId);
        if (!$budgetId) {
            return;
        }
        $groupAmount = round((float) collect($lines)->sum('subtotal'), 2);

        DB::transaction(function () use ($po, $pr, $budgetId, $locationId, $groupAmount, $userId) {
            $budget = BudgetAllocation::whereKey($budgetId)->lockForUpdate()->first();
            if (!$budget) {
                return;
            }
            if (BudgetTransaction::where('po_id', $po->po_id)
                ->where('budget_id', $budget->budget_id)
                ->where('txn_type', BudgetTransaction::TYPE_SPEND)->exists()) {
                return; // this warehouse group already settled
            }

            $utilBefore = $this->utilization($budget);

            $this->txn($budget, BudgetTransaction::TYPE_SPEND, $groupAmount, $pr->pr_id, $po->po_id, $userId,
                'PO ' . $po->po_number . ' warehouse received');
            $budget->spent = round((float) $budget->spent + $groupAmount, 2);

            $outstanding = $this->outstanding($budget->budget_id, $pr->pr_id);
            $released = 0.0;
            $consume = round(min($outstanding, $groupAmount), 2);
            if ($consume > 0) {
                $this->txn($budget, BudgetTransaction::TYPE_RELEASE, $consume, $pr->pr_id, $po->po_id, $userId,
                    'Commitment consumed by PO ' . $po->po_number);
                $released += $consume;
            }
            if ($this->poCoversLocationGroup($pr, $po, $locationId)) {
                $surplus = round($outstanding - $consume, 2);
                if ($surplus > 0) {
                    $this->txn($budget, BudgetTransaction::TYPE_RELEASE, $surplus, $pr->pr_id, $po->po_id, $userId,
                        'Estimate surplus returned');
                    $released += $surplus;
                }
            }

            $newCommitted = round((float) $budget->committed - $released, 2);
            if ($newCommitted < 0) {
                $newCommitted = 0.0;
            }
            $budget->committed = $newCommitted;
            $budget->save();

            $this->maybeAlert($budget, $utilBefore);
        });
    }

    /** All of a warehouse group's PO lines are fully received. */
    private function groupFullyReceived($lines): bool
    {
        $detailIds = collect($lines)->pluck('po_detail_id');
        $received = \App\Models\ReceivingDetail::whereIn('po_detail_id', $detailIds)
            ->get()->groupBy('po_detail_id')
            ->map(fn ($r) => (int) $r->sum('quantity_amount'));
        foreach ($lines as $line) {
            if (($received[$line->po_detail_id] ?? 0) < (int) $line->quantity_ordered) {
                return false;
            }
        }
        return true;
    }
```

- [ ] **Step 4: Refactor `resolveBudgetForPo` and `poCoversItsGroup` to take an explicit location**

Still in `BudgetService.php`, replace `resolveBudgetForPo` (lines 220-235) and `poCoversItsGroup` (lines 242-...) with location-parameterized versions (keeping thin wrappers is unnecessary — no other caller remains after Step 3):

```php
    /**
     * The budget a given warehouse settles against: among budgets the PR
     * committed to, the one whose location matches $locationId. Legacy
     * fallback: the PR's header budget_id.
     */
    private function resolveBudgetForLocation(PurchaseRequest $pr, int $locationId): ?int
    {
        $committedBudgetIds = BudgetTransaction::where('pr_id', $pr->pr_id)
            ->where('txn_type', BudgetTransaction::TYPE_COMMIT)
            ->distinct()->pluck('budget_id');

        if ($committedBudgetIds->isNotEmpty()) {
            $match = BudgetAllocation::whereIn('budget_id', $committedBudgetIds)
                ->where('location_id', $locationId)
                ->value('budget_id');
            if ($match) {
                return (int) $match;
            }
        }
        return $pr->budget_id ? (int) $pr->budget_id : null;
    }

    /**
     * True when the PO's ordered quantities cover every PR line destined for
     * $locationId (group fully ordered → surplus can return). Legacy PRs
     * (no line locations) compare against ALL lines.
     */
    private function poCoversLocationGroup(PurchaseRequest $pr, PurchaseOrder $po, int $locationId): bool
    {
        $hasLineLocations = $pr->details()->whereNotNull('location_id')->exists();
        $prLines = $pr->details()
            ->when($hasLineLocations, fn ($q) => $q->where('location_id', $locationId))
            ->get();
        if ($prLines->isEmpty()) {
            return false;
        }
        $ordered = $po->details()
            ->when($hasLineLocations, fn ($q) => $q->where('location_id', $locationId))
            ->get()->groupBy('product_id')
            ->map(fn ($rows) => (int) $rows->sum('quantity_ordered'));
        foreach ($prLines as $line) {
            if (($ordered[$line->product_id] ?? 0) < (int) $line->quantity) {
                return false;
            }
        }
        return true;
    }
```

Note the whole-PO surplus check now also scopes the PO side to the location when the PR has line locations — for the legacy single-group case `$hasLineLocations` is false so it compares all lines, exactly as before.

- [ ] **Step 5: Make the receiving trigger group-aware**

In `back-end/app/Http/Controllers/Api/ReceivingController.php`, replace the settlement block (lines 144-150):

```php
            // Finalize budget when PO is fully received (idempotent per PO)
            if ($newPoStatus === 11) {
                $po = PurchaseOrder::with('purchaseRequest')->find($poId);
                if ($po) {
                    app(BudgetService::class)->spend($po, $request->user()?->user_id);
                }
            }
```

with:

```php
            // Settle budget: single-warehouse POs settle at full completion; multi-
            // warehouse POs settle each warehouse group as its lines finish (spend()
            // is group-aware and idempotent per (po, budget)).
            $po = PurchaseOrder::with(['purchaseRequest', 'details'])->find($poId);
            if ($po) {
                $isMultiWarehouse = $po->details
                    ->groupBy(fn ($d) => (int) ($d->location_id ?? $po->location_id))
                    ->count() > 1;
                if ($newPoStatus === 11 || $isMultiWarehouse) {
                    app(BudgetService::class)->spend($po, $request->user()?->user_id);
                }
            }
```

- [ ] **Step 6: Run the new test + the full Budget suite for regressions**

Run: `php artisan test --filter=PoPerWarehouseSpendTest`
Expected: PASS.

Run: `php artisan test tests/Feature/Budget`
Expected: All green except the 2 pre-existing `NavBundleTest` failures and 1 pre-existing skip noted in project memory (those are outside `tests/Feature/Budget`, so this run should be fully green).

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Services/BudgetService.php back-end/app/Http/Controllers/Api/ReceivingController.php back-end/tests/Feature/Budget/PoPerWarehouseSpendTest.php
git commit -m "feat(budget): settle PO budget per destination warehouse group"
```

---

### Task 5: Location-scope the PO list by line warehouse

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseOrderController.php:43-52` (index scoping)
- Add test to: `back-end/tests/Feature/Budget/PrConvertSplitTest.php`

- [ ] **Step 1: Write the failing scoping test**

Add to `back-end/tests/Feature/Budget/PrConvertSplitTest.php`:

```php
    public function test_warehouse_user_sees_po_with_a_line_for_their_warehouse(): void
    {
        $admin = $this->makeUser('admin');
        $supplier = (int) DB::table('suppliers')->insertGetId(['supplier_name' => 'S', 'created_at' => now(), 'updated_at' => now()], 'supplier_id');
        $whA = $this->locationId; $whB = $this->warehouse();
        $this->makeBudget(10000, ['location_id' => $whA]);
        $this->makeBudget(10000, ['location_id' => $whB]);
        $pid = (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
        $pr = PurchaseRequest::create([
            'location_id' => null, 'supplier_id' => $supplier, 'budget_id' => null,
            'pr_number' => 'PR-' . strtoupper(Str::random(6)),
            'request_date' => today(), 'needed_by_date' => today()->addDays(7),
            'estimated_total' => 500, 'status_id' => 14,
            'requested_by' => $this->makeUser('inventory_analyst')->user_id,
        ]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whA,'quantity'=>2,'estimated_price'=>100,'subtotal'=>200]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whB,'quantity'=>3,'estimated_price'=>100,'subtotal'=>300]);
        $this->actingAs($admin, 'sanctum')->postJson("/api/purchase-requests/{$pr->pr_id}/convert", [
            'details' => [
                ['product_id' => $pid, 'location_id' => $whA, 'quantity_ordered' => 2, 'unit_price' => 100],
                ['product_id' => $pid, 'location_id' => $whB, 'quantity_ordered' => 3, 'unit_price' => 100],
            ],
        ])->assertSuccessful();
        $poId = (int) PurchaseOrder::where('pr_id', $pr->pr_id)->value('po_id');
        // PO header location = whA (first line). A whB user must still see it via its whB line.
        $whBUser = $this->makeUser('warehouse_personnel');
        $whBUser->location_id = $whB; $whBUser->save();

        $res = $this->actingAs($whBUser, 'sanctum')->getJson('/api/purchase-orders');
        $res->assertSuccessful();
        $ids = collect($res->json('data'))->pluck('po_id')->map(fn ($v) => (int) $v)->all();
        $this->assertContains($poId, $ids, 'a warehouse-B user must see a PO that has a warehouse-B line');
    }
```

- [ ] **Step 2: Run to confirm failure**

Run: `php artisan test --filter=test_warehouse_user_sees_po_with_a_line_for_their_warehouse`
Expected: FAIL — current scoping only matches `po.location_id` (= whA), so the whB user doesn't see it.

- [ ] **Step 3: Broaden the scoping to line warehouses**

In `PurchaseOrderController::index`, replace the non-admin scoping block (lines 43-52):

```php
            if ($authUser && (int) $authUser->role_id !== 1) {
                $userId     = (int) $authUser->user_id;
                $locationId = $authUser->location_id ? (int) $authUser->location_id : null;
                $query->where(function ($q) use ($userId, $locationId) {
                    $q->where('created_by', $userId);
                    if ($locationId) {
                        $q->orWhere('location_id', $locationId);
                    }
                });
            }
```

with:

```php
            if ($authUser && (int) $authUser->role_id !== 1) {
                $userId     = (int) $authUser->user_id;
                $locationId = $authUser->location_id ? (int) $authUser->location_id : null;
                $query->where(function ($q) use ($userId, $locationId) {
                    $q->where('created_by', $userId);
                    if ($locationId) {
                        // Header match (legacy single-warehouse POs) OR any line
                        // destined for this warehouse (per-line warehouse POs).
                        $q->orWhere('location_id', $locationId)
                          ->orWhereHas('details', fn ($d) => $d->where('location_id', $locationId));
                    }
                });
            }
```

- [ ] **Step 4: Run the test + the earlier PO tests together**

Run: `php artisan test --filter=PrConvertSplitTest`
Expected: PASS (all tests in the file).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/PurchaseOrderController.php back-end/tests/Feature/Budget/PrConvertSplitTest.php
git commit -m "feat(po): scope PO list to users whose warehouse appears on any line"
```

---

### Task 6: PO list CSV export — all destination warehouses

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseOrderController.php:458-511` (CSV headers + rows)

- [ ] **Step 1: List all distinct destination warehouses in the header cell + per-line warehouse column**

In the `export()` method, the "Deliver To (Warehouse)" cell currently uses `$po->location?->location_name`. Change both the empty-details row (line 489) and the per-line row (line 510) to a computed distinct-warehouse summary, and add a per-line warehouse value.

First, eager-load line locations: in the `PurchaseOrder::with([...])` array (lines 444-450), add `'details.location'`:

```php
            'details.product.applianceDetail.brand',
            'details.product.consumableDetail.unit',
            'details.location',
            'supplier', 'location', 'status',
```

Then, inside `foreach ($pos as $po)` (after line 472), compute the summary once:

```php
            foreach ($pos as $po) {
                $deliverTo = $po->details
                    ->map(fn ($d) => $d->location?->location_name ?? $po->location?->location_name)
                    ->filter()->unique()->implode('; ');
                if ($deliverTo === '') {
                    $deliverTo = $po->location?->location_name ?? '';
                }
```

Replace the empty-details row's location cell (line 489) `$po->location?->location_name ?? '',` with `$deliverTo,`.

For the per-line rows, add a per-line warehouse column. Add `'Line Warehouse'` to `$csvColumns` right after `'Line #'` (line 462), and in the per-line `fputcsv` add the value `$d->location?->location_name ?? $po->location?->location_name ?? ''` in the matching position. Replace the per-line header cell (line 510) `$po->location?->location_name ?? '',` with `$deliverTo,`. For the empty-details branch add an empty string in the new Line-Warehouse position to keep column counts aligned.

Concretely, the new `$csvColumns` (lines 458-466) becomes:

```php
        $csvColumns = [
            'PO Number', 'PR Number', 'Order Date', 'Expected Delivery',
            'Deliver To (Warehouse)', 'Supplier', 'Status',
            'Created By', 'Verified By', 'Verification Status', 'Verified At', 'Approved By', 'Received By',
            'Line #', 'Line Warehouse', 'Product Code', 'Product Name', 'Type',
            'Brand / Unit', 'Capacity / Pkg Unit', 'Pieces/Package',
            'Qty Ordered', 'Unit Price', 'Subtotal',
            'Total Amount', 'Grand Total',
        ];
```

In the empty-details `fputcsv` (lines 484-496), the trailing empty-cell run `'', '', '', '', '', '', '', '', '', '',` must gain ONE more empty string (the new Line Warehouse column) → make it eleven empties.

In the per-line `fputcsv` (starting line 505), insert `$d->location?->location_name ?? $po->location?->location_name ?? '',` immediately after the `Line #` value (`$i + 1,`).

- [ ] **Step 2: Verify export runs (smoke) and columns align**

Run: `php artisan test --filter=VerificationExportTest`
Expected: PASS (this suite already exercises the PO export; if it asserts exact columns, update those assertions to include `Deliver To (Warehouse)` and `Line Warehouse`).

If no export assertion exists there, do a manual smoke check: `php artisan route:list --path=purchase-orders/export` to confirm the route, then rely on Task 6 Step 1 correctness (column-count alignment is the only risk — recount: header has 26 columns; both `fputcsv` calls must emit 26 values).

- [ ] **Step 3: Commit**

```bash
git add back-end/app/Http/Controllers/Api/PurchaseOrderController.php
git commit -m "feat(po): CSV export lists all destination warehouses + per-line warehouse"
```

---

### Task 7: Group PO detail modal line items by warehouse

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseOrderPage.jsx` (detail modal line-items table ~1406-1447; ship-to block ~1373-1380; `poLineLabel` helper region)

- [ ] **Step 1: Add a grouping helper near the top of the file**

In `frontend/src/admin/pages/PurchaseOrderPage.jsx`, just after the `getDescriptionForExport` helper (around line 68), add:

```jsx
// Group PO detail rows by destination warehouse so each location's items show
// together under one header. Returns [{ locationName, rows, subtotal }].
const groupPoDetailsByLocation = (details) => {
  const map = new Map();
  (details || []).forEach((d) => {
    const key  = String(d.location_id ?? d.location?.location_id ?? '__none__');
    const name = d.location_name || d.location?.location_name || 'Unassigned warehouse';
    if (!map.has(key)) map.set(key, { locationName: name, rows: [], subtotal: 0 });
    const g = map.get(key);
    g.rows.push(d);
    g.subtotal += Number(d.subtotal) || 0;
  });
  return [...map.values()];
};
```

- [ ] **Step 2: Replace the flat detail table body with grouped rows**

In the detail modal (the `<table className="po-detail-table">` at line 1414), replace the `<thead>`/`<tbody>` (lines 1415-1446) so the header drops the standalone location handling and the body groups by warehouse. New markup:

```jsx
                    <table className="po-detail-table">
                      <thead>
                        <tr><th>#</th><th>PRODUCT</th><th>TYPE</th><th>DETAILS</th><th>QTY</th><th>UNIT PRICE</th><th>SUBTOTAL</th></tr>
                      </thead>
                      <tbody>
                        {(() => {
                          let idx = 0;
                          return groupPoDetailsByLocation(details).map((grp, gi) => (
                            <React.Fragment key={gi}>
                              <tr>
                                <td colSpan={7} style={{ background: '#eef2f7', fontWeight: 700, fontSize: 11, letterSpacing: '.4px', color: '#334155', textTransform: 'uppercase' }}>
                                  {grp.locationName}
                                  <span style={{ float: 'right', fontWeight: 600, color: '#64748b' }}>₱{Number(grp.subtotal).toLocaleString('en-PH')}</span>
                                </td>
                              </tr>
                              {grp.rows.map((item) => {
                                idx += 1;
                                const ab = item.approvedBy || item.approved_by || null;
                                const detailApprovedBy = ab ? `${ab.first_name || ''} ${ab.last_name || ''}`.trim() || ab.username || '—' : '—';
                                const pType = item.product_type || item.product?.product_type || '';
                                return (
                                  <tr key={item.po_detail_id ?? idx}>
                                    <td className="po-detail-table-idx">{idx}</td>
                                    <td>
                                      <div>{item.product_name || item.product?.product_name || `Item ${idx}`}</div>
                                      {item.product?.applianceDetail?.model_code && (
                                        <small style={{ color: '#94a3b8' }}>{item.product.applianceDetail.model_code}</small>
                                      )}
                                      {ab && <span style={{ display: 'flex', alignItems: 'center', gap: 3, fontSize: 11, color: '#15803d' }}><svg viewBox="0 0 24 24" fill="none" stroke="#15803d" strokeWidth="2.5" width="11" height="11"><polyline points="20 6 9 17 4 12"/></svg>Approved by: {detailApprovedBy}</span>}
                                    </td>
                                    <td style={{ fontSize: 11 }}>
                                      <span className={`po-type-badge ${pType === 'installation_material' ? 'po-type-part' : 'po-type-appliance'}`} style={{ fontSize: 10 }}>
                                        {pType === 'installation_material' ? 'MATERIAL' : 'APPLIANCE'}
                                      </span>
                                    </td>
                                    <td style={{ fontSize: 12, color: '#64748b' }}>{poLineLabel(item)}</td>
                                    <td>{item.quantity_ordered}</td>
                                    <td>₱{Number(item.unit_price || 0).toLocaleString('en-PH')}</td>
                                    <td className="po-detail-table-sub">₱{Number(item.subtotal || 0).toLocaleString('en-PH')}</td>
                                  </tr>
                                );
                              })}
                            </React.Fragment>
                          ));
                        })()}
                      </tbody>
                    </table>
```

(This intentionally drops the "IN PO / REMAINING" columns that were never in this PO modal — confirm the exact current columns are `# PRODUCT TYPE DETAILS QTY UNIT PRICE SUBTOTAL`, matching line 1416, and keep them.)

- [ ] **Step 3: Turn the single ship-to line into a distinct-warehouse summary**

Replace the "Deliver To (Warehouse)" row (line 1375) with a summary of all destination warehouses:

```jsx
                    <div className="po-detail-address-block-title">Deliver To (Warehouse)</div>
                    <div className="po-detail-address-row"><span className="po-detail-address-label">Warehouse(s)</span><span className="po-detail-address-value po-detail-address-value--name">{
                      [...new Set((details || []).map(x => x.location_name || x.location?.location_name).filter(Boolean))].join(', ') || d.location?.location_name || '—'
                    }</span></div>
```

- [ ] **Step 4: Lint + build**

Run from `frontend/`:
```bash
npx eslint src/admin/pages/PurchaseOrderPage.jsx
CI=false npx react-scripts build
```
Expected: no new eslint errors (a pre-existing `selectedSupplierName`/`requesterName` unused-var warning is acceptable); build succeeds.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/pages/PurchaseOrderPage.jsx
git commit -m "feat(po): group PO detail modal line items by destination warehouse"
```

---

### Task 8: Group the PO export document + Excel by warehouse

**Files:**
- Modify: `frontend/src/admin/utils/poExport.js` (document rows ~75-95 & ship-to ~198-202; Excel rows ~300-324)
- Modify: `frontend/src/admin/pages/PurchaseOrderPage.jsx` (`poPayload.items` mapping ~1493-1506, add `warehouse_name`)

- [ ] **Step 1: Add `warehouse_name` to each exported item**

In `PurchaseOrderPage.jsx`, in the `poPayload.items` map (line 1493), add a field:

```jsx
                  items: details.map(item => ({
                    product_code:      item.product?.applianceDetail?.model_code || item.model_code || '—',
                    name:              item.product_name || '—',
                    warehouse_name:    item.location_name || item.location?.location_name || null,
                    unit:              item.unit_abbreviation || (item.product_type === 'appliance' ? 'unit' : item.unit_name || 'pc'),
```

(leave the remaining fields unchanged).

- [ ] **Step 2: Group the printed-document rows by warehouse**

In `frontend/src/admin/utils/poExport.js`, replace the `rowsHtml` builder (lines 75-95) with a grouped version that emits a labeled sub-header row before each warehouse's items:

```js
  const groupsMap = new Map();
  items.forEach(item => {
    const key = item.warehouse_name || 'Unassigned warehouse';
    if (!groupsMap.has(key)) groupsMap.set(key, []);
    groupsMap.get(key).push(item);
  });

  const renderItemRow = (item) => {
    const qty    = Number(item.qty ?? item.quantity_ordered ?? 0);
    const price  = Number(item.unit_price || 0);
    const amount = Number(item.subtotal   || qty * price || 0);
    const unit   = item.unit || 'pc';
    const code   = item.product_code || '—';
    const desc   = buildDescription(item);
    const priceStr  = price  === 0 ? 'Free' : price.toLocaleString('en-PH', { minimumFractionDigits: 2 });
    const amountStr = amount === 0 ? '0.00' : amount.toLocaleString('en-PH', { minimumFractionDigits: 2 });
    return `
      <tr>
        <td style="text-align:center">${qty}</td>
        <td style="text-align:center">${unit}</td>
        <td>${code}</td>
        <td>${desc}</td>
        <td style="text-align:right">${priceStr}</td>
        <td style="text-align:right">${amountStr}</td>
      </tr>`;
  };

  const rowsHtml = [...groupsMap.entries()].map(([whName, whItems]) => `
      <tr><td colspan="6" style="background:#f0f0f0;font-weight:700;padding:4px 8px">Deliver To: ${whName}</td></tr>
      ${whItems.map(renderItemRow).join('')}`).join('');
```

- [ ] **Step 3: Make the document ship-to summarize all warehouses**

Replace the ship-to block (lines 198-202, now unconditional after the 2026-07-12 change) so it lists every destination warehouse:

```js
  const deliverWarehouses = [...new Set((items || []).map(i => i.warehouse_name).filter(Boolean))].join(', ');
```
(compute this near the other `const` declarations, e.g. after `const items = ...`), then in the HTML block:

```js
  <div class="ship-to">
    <strong>Deliver To (Warehouse): ${deliverWarehouses || shipToName}</strong>
    ${shipToAddress ? shipToAddress : ''}
  </div>
```

- [ ] **Step 4: Group the Excel rows by warehouse**

In `exportPOToExcel`, replace the line-item loop (lines 311-324) so each warehouse gets a label row before its items:

```js
  // ── Line item rows, grouped by destination warehouse ──
  const xlsGroups = new Map();
  items.forEach(item => {
    const key = item.warehouse_name || 'Unassigned warehouse';
    if (!xlsGroups.has(key)) xlsGroups.set(key, []);
    xlsGroups.get(key).push(item);
  });
  xlsGroups.forEach((whItems, whName) => {
    aoa.push([`Deliver To: ${whName}`]);
    whItems.forEach(item => {
      const qty    = Number(item.qty ?? item.quantity_ordered ?? 0);
      const price  = Number(item.unit_price || 0);
      const amount = Number(item.subtotal   || qty * price || 0);
      aoa.push([
        qty,
        item.unit || 'pc',
        item.product_code || '—',
        buildDescription(item),
        price,
        amount,
      ]);
    });
  });
```

And update the deliver-to summary line near line 300-303 to list all warehouses:

```js
  const deliverWarehouses = [...new Set((items || []).map(i => i.warehouse_name).filter(Boolean))].join(', ');
  aoa.push([`Deliver To (Warehouse): ${deliverWarehouses || shipToName}`, '', '', '', '']);
  if (shipToAddr) aoa.push([shipToAddr, '', '', '', '']);
  aoa.push([]);
```

- [ ] **Step 5: Lint + build**

Run from `frontend/`:
```bash
npx eslint src/admin/utils/poExport.js src/admin/pages/PurchaseOrderPage.jsx
CI=false npx react-scripts build
```
Expected: no new errors; build succeeds.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/utils/poExport.js frontend/src/admin/pages/PurchaseOrderPage.jsx
git commit -m "feat(po): group PO document + Excel export by destination warehouse"
```

---

### Task 9: Location-scope the PO receiving scan surface

**Files:**
- Modify: `frontend/src/admin/pages/BarcodeScan.jsx` (PO line list + the receiving submit `location_id`)

- [ ] **Step 1: Read the receiving page to locate the PO line list and the submit payload**

Open `frontend/src/admin/pages/BarcodeScan.jsx`. Identify:
- where the selected PO's lines are held/rendered (search for `location_id`, `po_detail_id`, `.details`, and the `receivingsAPI`/`ReceivingController::store` payload builder that sends `location_id` + `details`).
- the current user's assigned warehouse (search the codebase for how other scan pages read the logged-in user's `location_id` — e.g. an auth/user context or `me` object; `IssuanceScanPage.jsx` and `ISTReceivePage.jsx` show the pattern).

- [ ] **Step 2: Filter the receivable lines to the user's warehouse and send that warehouse as `location_id`**

Apply two changes:
1. When rendering / iterating the PO's lines to receive, filter to lines whose effective warehouse matches the current user's `location_id`:
   ```js
   const myLocationId = /* current user's location_id, per the pattern found in Step 1 */;
   const receivableLines = (po.details || []).filter(
     d => String(d.location_id ?? po.location_id) === String(myLocationId)
   );
   ```
   Use `receivableLines` wherever the full line list was used for the receiving UI. For a centralized/admin user (no `location_id`), keep all lines (`myLocationId ? filter : all`).
2. In the receiving submit payload, set `location_id` to `myLocationId` (falling back to `po.location_id` for centralized users) instead of always `po.location_id`, and send only the `receivableLines` in `details`.

- [ ] **Step 3: Lint + build**

Run from `frontend/`:
```bash
npx eslint src/admin/pages/BarcodeScan.jsx
CI=false npx react-scripts build
```
Expected: no new errors; build succeeds.

- [ ] **Step 4: Manual verification note**

Because this page has no unit tests, verify manually against the dev DB: as a Warehouse-A user, open a multi-warehouse PO to receive → only the A-lines appear and receiving them lands stock in A and leaves the PO Partially Received; as a Warehouse-B user, the B-lines appear and receiving them completes the PO. (Backend Task 4 already covers the budget settlement path with automated tests.)

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/pages/BarcodeScan.jsx
git commit -m "feat(po): scope PO receiving to the receiver's warehouse lines"
```

---

## Final verification

- [ ] **Backend regression:** from `back-end/`, run `php artisan test tests/Feature/Budget` → all green (the pre-existing NavBundle failures live elsewhere).
- [ ] **Frontend:** from `frontend/`, `CI=false npx react-scripts build` succeeds.
- [ ] **Manual demo path (dev DB `airkingV5`, needs ≥2 warehouses each with a verified active budget):** create a supplier PR with lines for two warehouses → approve → convert → confirm ONE PO with items grouped by warehouse in the detail modal and both exports → receive each warehouse's lines as that warehouse's user → PO completes and each warehouse budget settles independently.

## Notes for the implementer

- The manual/direct PO create path (`PurchaseOrderController::store`) stays single-warehouse (header `location_id`); it writes null line `location_id`, and every consumer added here falls back to the header when a line location is null. Do not change `store()`.
- Do not run blanket `php artisan migrate`. Only the `--path=` migration in Task 1.
- `commitGroup` used in the Task 4 test already exists on `BudgetService` (it is the per-`(budget_id, pr_id)` idempotent commit written at PR approval). If its signature differs, check `BudgetService` and adjust the test's commit calls to match.
