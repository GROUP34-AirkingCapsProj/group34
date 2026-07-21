# Serial Numbers Within Batches — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show each batch's serial numbers (appliance serials / consumable scanned barcodes) in both the Inventory Monitoring Console Batches expander and the Warehouse Map cards.

**Architecture:** A shared `BatchSerialResolver` service maps `batch_id → serials[]` via two grouped queries. Both batch endpoints call it and add a `serials` array per batch. The React page renders those serials as wrapped chips in the two batch views.

**Tech Stack:** Laravel (PHP) + React (CRA); PHPUnit feature tests on `airkingV5_test` via `DatabaseTransactions`.

---

## Background / Context

- A batch is uniquely keyed by `receiving_id + product_id + location_id` (one receiving can
  produce several batches — `InventoryScanTransactionController.php:401-413`).
- `item_serial` (appliances) has `product_id`, `location_id`, `receiving_id`, `serial_number`,
  `serial_type` (`ItemSerial.php:19-25`). Units currently in a batch have
  `serial_type = 'received'`.
- `receiving_details.scanned_barcode` (consumables) has `product_id`, `receiving_id`;
  location via `receivings.location_id`.
- Endpoints to enrich:
  - `InventoryController::batches` — `GET /inventory/{id}/batches`
    (`InventoryController.php:72-91`). Batches from
    `InventoryBatchService::batchesForDisplay()` are `InventoryBatch` models carrying
    `batch_id`, `product_id`, `location_id`, `receiving_id`
    (`InventoryBatchService.php:144-151`).
  - `InventoryBatchController::index` — `GET /inventory-batches`
    (`InventoryBatchController.php:21-46`). Batches are `InventoryBatch` models with the same
    columns.
- `ItemSerial` and `DB` import paths: `App\Models\ItemSerial`, `Illuminate\Support\Facades\DB`.

## File Structure

- Create: `back-end/app/Services/BatchSerialResolver.php` — the shared resolver.
- Modify: `back-end/app/Http/Controllers/Api/InventoryController.php` — `batches()` adds `serials`.
- Modify: `back-end/app/Http/Controllers/Api/InventoryBatchController.php` — `index()` adds `serials`.
- Create: `back-end/tests/Feature/Inventory/BatchSerialNumbersTest.php` — feature tests.
- Modify: `frontend/src/admin/pages/InventoryManagement.jsx` — chips in both views.
- Modify: `frontend/src/admin/styles/inventory_management.css` — chip styles.

---

## Task 1: Backend — BatchSerialResolver service

**Files:**
- Test: `back-end/tests/Feature/Inventory/BatchSerialNumbersTest.php` (create)
- Create: `back-end/app/Services/BatchSerialResolver.php`
- Modify: `back-end/app/Http/Controllers/Api/InventoryController.php` (`batches`, lines 72-91)
- Modify: `back-end/app/Http/Controllers/Api/InventoryBatchController.php` (`index`, lines 21-46)

- [ ] **Step 1: Write the failing feature test**

Create `back-end/tests/Feature/Inventory/BatchSerialNumbersTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Models\Inventory;
use App\Models\PurchaseOrder;

class BatchSerialNumbersTest extends TestCase
{
    use DatabaseTransactions;

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

    private function receive(User $user, PurchaseOrder $po, int $locationId, int $productId, int $qty, string $barcode): void
    {
        $this->actingAs($user)->postJson('/api/receivings', [
            'pc_id'            => $po->po_id,
            'location_id'      => $locationId,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now()->toDateString(),
            'details'          => [[
                'product_id'      => $productId,
                'quantity_amount' => $qty,
                'condition'       => 'Good',
                'scanned_barcode' => $barcode,
            ]],
        ])->assertStatus(201);
    }

    public function test_appliance_batch_lists_its_serial_numbers_in_both_endpoints(): void
    {
        $loc = Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Loc',
            'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(),
            'product_name' => 'Test Appliance',
            'product_type' => 'appliance', // serialized by default
        ]);
        $inventory = Inventory::create([
            'product_id'         => $product->product_id,
            'location_id'        => $loc->location_id,
            'quantity_on_hand'   => 0,
            'available_quantity' => 0,
        ]);
        $user = $this->makeUser();
        $po = PurchaseOrder::create([
            'po_number'           => 'PO-' . uniqid(),
            'location_id'         => $loc->location_id,
            'verification_status' => 'verified',
            'status_id'           => 1,
        ]);

        $serial = 'SN-' . uniqid();
        $this->receive($user, $po, $loc->location_id, $product->product_id, 1, $serial);

        // Per-inventory batches endpoint (Console expander).
        $batches = $this->actingAs($user)
            ->getJson('/api/inventory/' . $inventory->inventory_id . '/batches')
            ->assertStatus(200)
            ->json('data');
        $this->assertNotEmpty($batches);
        $this->assertContains($serial, $batches[0]['serials']);

        // Warehouse-map endpoint.
        $wm = $this->actingAs($user)
            ->getJson('/api/inventory-batches')
            ->assertStatus(200)
            ->json('data');
        $mine = collect($wm)->firstWhere('product_id', $product->product_id);
        $this->assertNotNull($mine);
        $this->assertContains($serial, $mine['serials']);
    }

    public function test_consumable_batch_lists_scanned_barcode(): void
    {
        $loc = Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Loc',
            'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'Test Consumable',
            'product_type' => 'installation_material',
        ]);
        $inventory = Inventory::create([
            'product_id'         => $product->product_id,
            'location_id'        => $loc->location_id,
            'quantity_on_hand'   => 0,
            'available_quantity' => 0,
        ]);
        $user = $this->makeUser();
        $po = PurchaseOrder::create([
            'po_number'           => 'PO-' . uniqid(),
            'location_id'         => $loc->location_id,
            'verification_status' => 'verified',
            'status_id'           => 1,
        ]);

        $barcode = 'BC-' . uniqid();
        $this->receive($user, $po, $loc->location_id, $product->product_id, 10, $barcode);

        $batches = $this->actingAs($user)
            ->getJson('/api/inventory/' . $inventory->inventory_id . '/batches')
            ->assertStatus(200)
            ->json('data');
        $this->assertNotEmpty($batches);
        $this->assertContains($barcode, $batches[0]['serials']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=BatchSerialNumbersTest`
Expected: FAIL — `serials` key does not exist on batch payloads ("Undefined array key 'serials'").

If it errors for an environmental reason instead (DB missing, receiving endpoint 4xx), report
NEEDS_CONTEXT with the exact error rather than guessing.

- [ ] **Step 3: Create the BatchSerialResolver service**

Create `back-end/app/Services/BatchSerialResolver.php`:

```php
<?php

namespace App\Services;

use App\Models\ItemSerial;
use Illuminate\Support\Facades\DB;

class BatchSerialResolver
{
    /**
     * Map each batch to the serial numbers (appliances) or scanned barcodes
     * (consumables) belonging to it, keyed by batch_id.
     *
     * @param  \Illuminate\Support\Collection $batches items exposing
     *         batch_id, product_id, location_id, receiving_id
     * @return array<int|string, string[]>
     */
    public function resolve($batches): array
    {
        $withReceiving = $batches->filter(fn ($b) => $b->receiving_id !== null);
        if ($withReceiving->isEmpty()) {
            return [];
        }

        $receivingIds = $withReceiving->pluck('receiving_id')->unique()->values()->all();
        $productIds   = $withReceiving->pluck('product_id')->unique()->values()->all();
        $locationIds  = $withReceiving->pluck('location_id')->unique()->values()->all();

        // Appliance serials currently in the batch (serial_type = 'received').
        $applianceMap = [];
        ItemSerial::query()
            ->whereIn('receiving_id', $receivingIds)
            ->whereIn('product_id', $productIds)
            ->whereIn('location_id', $locationIds)
            ->where('serial_type', 'received')
            ->get(['serial_number', 'product_id', 'location_id', 'receiving_id'])
            ->each(function ($s) use (&$applianceMap) {
                $key = $s->product_id . '|' . $s->location_id . '|' . $s->receiving_id;
                $applianceMap[$key][] = $s->serial_number;
            });

        // Consumable scanned barcodes.
        $consumableMap = [];
        DB::table('receiving_details')
            ->join('receivings', 'receivings.receiving_id', '=', 'receiving_details.receiving_id')
            ->whereIn('receiving_details.receiving_id', $receivingIds)
            ->whereIn('receiving_details.product_id', $productIds)
            ->whereIn('receivings.location_id', $locationIds)
            ->whereNotNull('receiving_details.scanned_barcode')
            ->get([
                'receiving_details.scanned_barcode as scanned_barcode',
                'receiving_details.product_id as product_id',
                'receivings.location_id as location_id',
                'receiving_details.receiving_id as receiving_id',
            ])
            ->each(function ($r) use (&$consumableMap) {
                $key = $r->product_id . '|' . $r->location_id . '|' . $r->receiving_id;
                $consumableMap[$key][] = $r->scanned_barcode;
            });

        $result = [];
        foreach ($batches as $batch) {
            if ($batch->receiving_id === null) {
                $result[$batch->batch_id] = [];
                continue;
            }
            $key = $batch->product_id . '|' . $batch->location_id . '|' . $batch->receiving_id;
            $result[$batch->batch_id] = $applianceMap[$key] ?? $consumableMap[$key] ?? [];
        }

        return $result;
    }
}
```

- [ ] **Step 4: Enrich `InventoryController::batches`**

In `back-end/app/Http/Controllers/Api/InventoryController.php`, the `batches` method currently
is (lines 72-91):

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
                'zone_label'         => $batch->zone_label,
                'received_at'        => $batch->received_at?->toDateString(),
                'quantity_received'  => $batch->quantity_received,
                'quantity_remaining' => $batch->quantity_remaining,
                'is_legacy'          => $batch->receiving_id === null,
            ];
        });

        return $this->success($data, 'Inventory batches retrieved successfully');
    }
```

Replace it with:

```php
    public function batches(Inventory $inventory)
    {
        $batches = app(InventoryBatchService::class)
            ->batchesForDisplay($inventory->product_id, $inventory->location_id);

        $serialMap = app(\App\Services\BatchSerialResolver::class)->resolve($batches);

        $data = $batches->map(function ($batch) use ($serialMap) {
            return [
                'batch_id'           => $batch->batch_id,
                'receiving_id'       => $batch->receiving_id,
                'po_number'          => $batch->receiving?->purchaseOrder?->po_number,
                'zone_label'         => $batch->zone_label,
                'received_at'        => $batch->received_at?->toDateString(),
                'quantity_received'  => $batch->quantity_received,
                'quantity_remaining' => $batch->quantity_remaining,
                'is_legacy'          => $batch->receiving_id === null,
                'serials'            => $serialMap[$batch->batch_id] ?? [],
            ];
        });

        return $this->success($data, 'Inventory batches retrieved successfully');
    }
```

- [ ] **Step 5: Enrich `InventoryBatchController::index`**

In `back-end/app/Http/Controllers/Api/InventoryBatchController.php`, the `index` method
currently maps batches (lines 21-46). Replace the body with:

```php
    public function index(Request $request)
    {
        $batches = InventoryBatch::forUser($request->user())
            ->where('quantity_remaining', '>', 0)
            ->with(['product', 'receiving.purchaseOrder'])
            ->orderBy('received_at', 'asc')
            ->get();

        $serialMap = app(\App\Services\BatchSerialResolver::class)->resolve($batches);

        $data = $batches->map(function ($batch) use ($serialMap) {
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
                'serials'            => $serialMap[$batch->batch_id] ?? [],
            ];
        });

        return $this->success($data, 'Warehouse batches retrieved successfully');
    }
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd back-end && php artisan test --filter=BatchSerialNumbersTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Regression + lint**

Run: `cd back-end && php artisan test --filter=Inventory`
Expected: all Inventory tests pass (includes the earlier batch/summary/serial tests).

Run:
```bash
cd back-end && php -l app/Services/BatchSerialResolver.php \
  && php -l app/Http/Controllers/Api/InventoryController.php \
  && php -l app/Http/Controllers/Api/InventoryBatchController.php
```
Expected: `No syntax errors detected` for each.

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Services/BatchSerialResolver.php \
  back-end/app/Http/Controllers/Api/InventoryController.php \
  back-end/app/Http/Controllers/Api/InventoryBatchController.php \
  back-end/tests/Feature/Inventory/BatchSerialNumbersTest.php
git commit -m "feat(inventory): resolve per-batch serial numbers for batch endpoints"
```

---

## Task 2: Frontend — render serial chips in both batch views

**Files:**
- Modify: `frontend/src/admin/pages/InventoryManagement.jsx` (Console expander ~854-873; Warehouse Map card ~943-950)
- Modify: `frontend/src/admin/styles/inventory_management.css` (add chip styles)

- [ ] **Step 1: Add the Serial Numbers column to the Console Batches expander**

In `frontend/src/admin/pages/InventoryManagement.jsx`, locate the batch table header/body
inside the expander:

```jsx
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
```

Replace with (adds a Serial Numbers header and cell):

```jsx
                                  <table className="inv-batch-table">
                                    <thead>
                                      <tr>
                                        <th>PO Number</th>
                                        <th>Received</th>
                                        <th>Qty Remaining</th>
                                        <th>Qty Received</th>
                                        <th>Serial Numbers</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                      {rowBatches.map((b) => (
                                        <tr key={b.batch_id}>
                                          <td>{b.is_legacy ? 'Pre-tracking stock' : (b.po_number || '—')}</td>
                                          <td>{b.received_at || '—'}</td>
                                          <td>{b.quantity_remaining}</td>
                                          <td>{b.quantity_received}</td>
                                          <td>
                                            {b.serials && b.serials.length > 0 ? (
                                              <div className="inv-serial-chips">
                                                {b.serials.map((sn, i) => (
                                                  <span className="inv-serial-chip" key={i}>{sn}</span>
                                                ))}
                                              </div>
                                            ) : '—'}
                                          </td>
                                        </tr>
                                      ))}
                                    </tbody>
                                  </table>
```

- [ ] **Step 2: Add the Serial Numbers section to the Warehouse Map card**

In the same file, locate the Warehouse Map card stock row and the zone block that follows:

```jsx
                      <div className="inv-warehouse-map-stock-row">
                        <div className="inv-warehouse-map-stock-bar">
                          <div className="inv-warehouse-map-stock-bar-fill" style={{ width: `${pctRemaining}%` }} />
                        </div>
                        <span className="inv-warehouse-map-stock-label">
                          {batch.quantity_remaining} / {batch.quantity_received} remaining
                        </span>
                      </div>

                      <div className="inv-warehouse-map-zone">
```

Replace with (inserts a serials block between the stock row and the zone block):

```jsx
                      <div className="inv-warehouse-map-stock-row">
                        <div className="inv-warehouse-map-stock-bar">
                          <div className="inv-warehouse-map-stock-bar-fill" style={{ width: `${pctRemaining}%` }} />
                        </div>
                        <span className="inv-warehouse-map-stock-label">
                          {batch.quantity_remaining} / {batch.quantity_received} remaining
                        </span>
                      </div>

                      <div className="inv-warehouse-map-serials">
                        <span className="inv-warehouse-map-meta-label">Serial Numbers</span>
                        {batch.serials && batch.serials.length > 0 ? (
                          <div className="inv-serial-chips">
                            {batch.serials.map((sn, i) => (
                              <span className="inv-serial-chip" key={i}>{sn}</span>
                            ))}
                          </div>
                        ) : (
                          <span className="inv-warehouse-map-meta-value">—</span>
                        )}
                      </div>

                      <div className="inv-warehouse-map-zone">
```

- [ ] **Step 3: Add chip styles**

In `frontend/src/admin/styles/inventory_management.css`, append at the end of the file:

```css
/* Per-batch serial number chips (Console expander + Warehouse Map) */
.inv-serial-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
.inv-serial-chip {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 12px;
  background: #eef2ff;
  border: 1px solid #c7d2fe;
  color: #3730a3;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
}
.inv-warehouse-map-serials {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-top: 10px;
}
```

- [ ] **Step 4: Lint**

Run: `cd frontend && npx eslint src/admin/pages/InventoryManagement.jsx`
Expected: `0 errors` (the pre-existing `no-unused-vars` warning on `warehouses` ~line 104 is
unrelated and acceptable).

- [ ] **Step 5: Manual verification**

Open the app → Inventory:
- On the **Inventory Monitoring Console** tab, expand a SKU's **Batches** — each batch row
  shows its serial numbers as chips (or `—`).
- On the **Warehouse Map** tab, each batch card shows a **Serial Numbers** section listing
  its chips (or `—`).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/InventoryManagement.jsx frontend/src/admin/styles/inventory_management.css
git commit -m "feat(inventory): show per-batch serial numbers in console + warehouse map"
```

---

## Self-Review Notes

- **Spec coverage:** shared resolver (Task 1 Step 3), both endpoints enriched (Steps 4-5),
  appliance + consumable + composite-key isolation covered by tests (Step 1), Console column
  (Task 2 Step 1), Warehouse Map section (Task 2 Step 2), chip CSS (Step 3) — all covered.
- **Field-name consistency:** endpoints emit `serials`; frontend reads `b.serials` /
  `batch.serials`; resolver returns `batch_id => string[]`.
- **DRY:** one resolver used by both endpoints.
- **No placeholders:** all code shown in full.
- **Backward compatibility:** only an additive `serials` field on batch payloads; existing
  fields unchanged.
