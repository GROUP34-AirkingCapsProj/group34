# PO-Centric Inventory Monitoring Console — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the Inventory "Inventory" tab into two tabs — a new PO+Location-grouped **Inventory Monitoring Console** (Warehouse Map folded in, FIFO order, View → 3-tab modal) and **Stock-Level Monitoring** (the existing per-SKU table).

**Architecture:** A new `PoInventoryController` exposes two derived endpoints (list `(PO, warehouse)` groups; one group's 3-tab detail). The React page gains a PO-console panel + a PO-group View modal, and the old per-SKU panel simply moves to a renamed tab. No schema changes.

**Tech Stack:** Laravel (PHP) + React (CRA); PHPUnit feature tests on `airkingV5_test` via `DatabaseTransactions`.

---

## Background / Context

- Group key = `(po_id, location_id)`. "Location" = batch `zone_label`. FIFO = `received_at`
  ascending. Data path: `inventory_batches.receiving_id` → `receivings.po_id` →
  `purchase_orders` (`po_number`, `order_date`, `supplier`) ; batch `location_id` → warehouse.
- `InventoryBatch` has `forUser` scope (see `InventoryBatchController.php:23`) and relations
  `product`, `location`, `receiving` (`InventoryBatch.php:34-46`); `receiving.purchaseOrder`
  and `purchaseOrder.supplier` exist (`Receiving.php:41`, `PurchaseOrder.php:54`).
- Serial resolution mirrors `BatchSerialResolver` (appliance `item_serial` with
  `serial_type='received'`; consumable `receiving_details.scanned_barcode`).
- Existing routes: `routes/api.php:795-802`. Register new routes at a **distinct path**
  (`/po-inventory-groups`) to avoid the `/inventory/{inventory}` wildcard.
- Frontend anchors (`frontend/src/admin/pages/InventoryManagement.jsx`): tab buttons
  `489-514`; per-SKU panel guard `{activeTab === 'inventory' && (` at `692`; warehouse-map
  panel `{activeTab === 'warehouse-map' && (` at `920`; `fetchWarehouseBatches` `244-255`;
  scan-effect warehouse-map re-fetch `276-278`; zone-edit helpers `startEditingZone` `450`,
  `saveZoneEdit` `455`. API wrappers in `frontend/src/admin/services/api.js:597-611`.

## File Structure

- Create: `back-end/app/Http/Controllers/Api/PoInventoryController.php` (index + show).
- Modify: `back-end/routes/api.php` — two routes.
- Create: `back-end/tests/Feature/Inventory/PoInventoryGroupsTest.php`.
- Modify: `frontend/src/admin/services/api.js` — two wrappers.
- Modify: `frontend/src/admin/pages/InventoryManagement.jsx` — tabs, panels, modal, state.
- Modify: `frontend/src/admin/styles/inventory_management.css` — minor styles (reuse existing).

---

## Task 1: Backend — list PO+warehouse groups

**Files:**
- Test: `back-end/tests/Feature/Inventory/PoInventoryGroupsTest.php` (create)
- Create: `back-end/app/Http/Controllers/Api/PoInventoryController.php`
- Modify: `back-end/routes/api.php`

- [ ] **Step 1: Write the failing test (list)**

Create `back-end/tests/Feature/Inventory/PoInventoryGroupsTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Models\PurchaseOrder;

class PoInventoryGroupsTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeUser(): User
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

    protected function makePo(int $locationId): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_number'           => 'PO-' . uniqid(),
            'location_id'         => $locationId,
            'verification_status' => 'verified',
            'status_id'           => 1,
            'order_date'          => now()->toDateString(),
        ]);
    }

    protected function receive(User $u, PurchaseOrder $po, int $loc, int $prod, int $qty, string $bc, ?string $zone = 'Location 1'): void
    {
        $this->actingAs($u)->postJson('/api/receivings', [
            'pc_id'            => $po->po_id,
            'location_id'      => $loc,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now()->toDateString(),
            'details'          => [[
                'product_id'      => $prod,
                'quantity_amount' => $qty,
                'condition'       => 'Good',
                'scanned_barcode' => $bc,
                'zone_label'      => $zone,
            ]],
        ])->assertStatus(201);
    }

    public function test_lists_one_group_per_po_and_warehouse_with_nested_batches(): void
    {
        $wh = Location::create(['location_code' => 'WH-' . uniqid(), 'location_name' => 'Main WH', 'location_type' => 'warehouse']);
        $p1 = Product::create(['product_code' => 'C1-' . uniqid(), 'product_name' => 'Cons One', 'product_type' => 'installation_material']);
        $p2 = Product::create(['product_code' => 'C2-' . uniqid(), 'product_name' => 'Cons Two', 'product_type' => 'installation_material']);
        $user = $this->makeUser();

        $po = $this->makePo($wh->location_id);
        $this->receive($user, $po, $wh->location_id, $p1->product_id, 12, 'BC-1-' . uniqid());
        $this->receive($user, $po, $wh->location_id, $p2->product_id, 8, 'BC-2-' . uniqid());

        $groups = $this->actingAs($user)
            ->getJson('/api/po-inventory-groups')
            ->assertStatus(200)
            ->json('data');

        $mine = collect($groups)->firstWhere('po_id', $po->po_id);
        $this->assertNotNull($mine);
        $this->assertSame($po->po_number, $mine['po_number']);
        $this->assertSame($wh->location_id, $mine['warehouse_id']);
        $this->assertSame(2, $mine['item_count']);
        $this->assertSame(20, $mine['total_received']);
        $this->assertCount(2, $mine['batches']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd back-end && php artisan test --filter=PoInventoryGroupsTest`
Expected: FAIL — route `/api/po-inventory-groups` not found (404). If it errors for an
environmental reason, report NEEDS_CONTEXT.

- [ ] **Step 3: Create the controller with `index`**

Create `back-end/app/Http/Controllers/Api/PoInventoryController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PoInventoryController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/po-inventory-groups
     * One group per (PO, warehouse), FIFO-ordered, with nested batches.
     */
    public function index(Request $request)
    {
        $batches = InventoryBatch::forUser($request->user())
            ->whereNotNull('receiving_id')
            ->with(['product', 'location', 'receiving.purchaseOrder.supplier'])
            ->orderBy('received_at', 'asc')
            ->get();

        $groups = [];
        foreach ($batches as $b) {
            $po = $b->receiving?->purchaseOrder;
            if (! $po) {
                continue;
            }
            $key = $po->po_id . '-' . $b->location_id;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'group_key'         => $key,
                    'po_id'             => $po->po_id,
                    'po_number'         => $po->po_number,
                    'warehouse_id'      => $b->location_id,
                    'warehouse_name'    => $b->location?->location_name,
                    'supplier_name'     => $po->supplier?->supplier_name,
                    'order_date'        => $po->order_date?->toDateString(),
                    'first_received_at' => $b->received_at?->toDateString(),
                    '_zone_labels'      => [],
                    '_product_ids'      => [],
                    'total_received'    => 0,
                    'total_remaining'   => 0,
                    'batches'           => [],
                ];
            }
            $g = &$groups[$key];
            if ($b->zone_label) {
                $g['_zone_labels'][$b->zone_label] = true;
            }
            $g['_product_ids'][$b->product_id] = true;
            $g['total_received']  += (int) $b->quantity_received;
            $g['total_remaining'] += (int) $b->quantity_remaining;
            $g['batches'][] = [
                'batch_id'           => $b->batch_id,
                'product_id'         => $b->product_id,
                'product_name'       => $b->product?->product_name,
                'zone_label'         => $b->zone_label,
                'quantity_received'  => $b->quantity_received,
                'quantity_remaining' => $b->quantity_remaining,
                'received_at'        => $b->received_at?->toDateString(),
            ];
            unset($g);
        }

        $data = collect($groups)->map(function ($g) {
            $g['location_label'] = empty($g['_zone_labels']) ? null : implode(', ', array_keys($g['_zone_labels']));
            $g['item_count']     = count($g['_product_ids']);
            unset($g['_zone_labels'], $g['_product_ids']);
            return $g;
        })->sortBy('first_received_at')->values();

        return $this->success($data, 'PO inventory groups retrieved successfully');
    }
}
```

- [ ] **Step 4: Register the route**

In `back-end/routes/api.php`, immediately after line 799
(`Route::patch('/inventory-batches/{batch}', ...);`), add:

```php
    Route::get('/po-inventory-groups', [\App\Http\Controllers\Api\PoInventoryController::class, 'index']);
    Route::get('/po-inventory-groups/{po}', [\App\Http\Controllers\Api\PoInventoryController::class, 'show']);
```

(The `show` route is used in Task 2; adding both now avoids a second edit. The `show` method
is added in Task 2 — if the framework validates controller methods at route registration
time it does not, so this is safe.)

- [ ] **Step 5: Run to verify the list test passes**

Run: `cd back-end && php artisan test --filter=PoInventoryGroupsTest::test_lists_one_group_per_po_and_warehouse_with_nested_batches`
Expected: PASS.

- [ ] **Step 6: Lint + commit**

Run: `cd back-end && php -l app/Http/Controllers/Api/PoInventoryController.php`
Expected: `No syntax errors detected`.

```bash
git add back-end/app/Http/Controllers/Api/PoInventoryController.php back-end/routes/api.php back-end/tests/Feature/Inventory/PoInventoryGroupsTest.php
git commit -m "feat(inventory): add PO+warehouse inventory groups list endpoint"
```

---

## Task 2: Backend — group detail (3 tabs)

**Files:**
- Modify: `back-end/tests/Feature/Inventory/PoInventoryGroupsTest.php` (add tests)
- Modify: `back-end/app/Http/Controllers/Api/PoInventoryController.php` (add `show`)

- [ ] **Step 1: Add failing detail tests**

Append these methods inside `PoInventoryGroupsTest` (before the closing brace):

```php
    public function test_group_detail_returns_scoped_serials_and_overview(): void
    {
        $wh = Location::create(['location_code' => 'WH-' . uniqid(), 'location_name' => 'Main WH', 'location_type' => 'warehouse']);
        $appliance = Product::create(['product_code' => 'A-' . uniqid(), 'product_name' => 'Aircon', 'product_type' => 'appliance']);
        $user = $this->makeUser();

        $poA = $this->makePo($wh->location_id);
        $poB = $this->makePo($wh->location_id);
        $snA = 'SNA-' . uniqid();
        $snB = 'SNB-' . uniqid();
        $this->receive($user, $poA, $wh->location_id, $appliance->product_id, 1, $snA);
        $this->receive($user, $poB, $wh->location_id, $appliance->product_id, 1, $snB);

        $detail = $this->actingAs($user)
            ->getJson('/api/po-inventory-groups/' . $poA->po_id . '?location_id=' . $wh->location_id)
            ->assertStatus(200)
            ->json('data');

        $this->assertSame($poA->po_number, $detail['overview']['po_number']);
        $this->assertSame(1, $detail['overview']['totals']['item_count']);

        $allSerials = collect($detail['serials'])->flatMap(fn ($p) => $p['serials'])->all();
        $this->assertContains($snA, $allSerials);
        $this->assertNotContains($snB, $allSerials); // isolation: other PO's serial excluded
    }

    public function test_group_detail_consumable_lists_barcode(): void
    {
        $wh = Location::create(['location_code' => 'WH-' . uniqid(), 'location_name' => 'Main WH', 'location_type' => 'warehouse']);
        $cons = Product::create(['product_code' => 'C-' . uniqid(), 'product_name' => 'Wire', 'product_type' => 'installation_material']);
        $user = $this->makeUser();

        $po = $this->makePo($wh->location_id);
        $bc = 'BC-' . uniqid();
        $this->receive($user, $po, $wh->location_id, $cons->product_id, 10, $bc);

        $detail = $this->actingAs($user)
            ->getJson('/api/po-inventory-groups/' . $po->po_id . '?location_id=' . $wh->location_id)
            ->assertStatus(200)
            ->json('data');

        $allSerials = collect($detail['serials'])->flatMap(fn ($p) => $p['serials'])->all();
        $this->assertContains($bc, $allSerials);
        $this->assertSame(10, $detail['overview']['totals']['total_received']);
    }
```

- [ ] **Step 2: Run to verify the new tests fail**

Run: `cd back-end && php artisan test --filter=PoInventoryGroupsTest`
Expected: the two new tests FAIL (no `show` method → likely 500/`method not found` or missing
`data.overview`). The list test still passes.

- [ ] **Step 3: Add the `show` method**

In `back-end/app/Http/Controllers/Api/PoInventoryController.php`, add this method after
`index`:

```php
    /**
     * GET /api/po-inventory-groups/{po}?location_id={warehouseId}
     * The 3-tab detail for one (PO, warehouse) group.
     */
    public function show(Request $request, $po)
    {
        $poId       = (int) $po;
        $locationId = (int) $request->query('location_id');

        // Group batches (scoped to the user) for this PO + warehouse.
        $batches = InventoryBatch::forUser($request->user())
            ->where('location_id', $locationId)
            ->whereNotNull('receiving_id')
            ->whereHas('receiving', fn ($q) => $q->where('po_id', $poId))
            ->with(['product', 'location', 'receiving.purchaseOrder.supplier'])
            ->orderBy('received_at', 'asc')
            ->get();

        if ($batches->isEmpty()) {
            return $this->notFound('No inventory found for this PO at this warehouse');
        }

        $po0        = $batches->first()->receiving?->purchaseOrder;
        $warehouse  = $batches->first()->location;
        $zoneLabels = $batches->pluck('zone_label')->filter()->unique()->values()->all();

        // Overview: per-product totals (FIFO order preserved by batch order).
        $products = [];
        foreach ($batches as $b) {
            $pid = $b->product_id;
            if (! isset($products[$pid])) {
                $products[$pid] = [
                    'product_id'         => $pid,
                    'product_name'       => $b->product?->product_name,
                    'quantity_received'  => 0,
                    'quantity_remaining' => 0,
                ];
            }
            $products[$pid]['quantity_received']  += (int) $b->quantity_received;
            $products[$pid]['quantity_remaining'] += (int) $b->quantity_remaining;
        }
        $products = array_values($products);

        $overview = [
            'po_number'      => $po0?->po_number,
            'supplier_name'  => $po0?->supplier?->supplier_name,
            'order_date'     => $po0?->order_date?->toDateString(),
            'warehouse_name' => $warehouse?->location_name,
            'location_label' => empty($zoneLabels) ? null : implode(', ', $zoneLabels),
            'totals'         => [
                'item_count'      => count($products),
                'total_received'  => array_sum(array_column($products, 'quantity_received')),
                'total_remaining' => array_sum(array_column($products, 'quantity_remaining')),
            ],
            'products'       => $products,
        ];

        // Receiving events for this PO at this warehouse.
        $receivingHistory = DB::table('receiving_details')
            ->join('receivings', 'receivings.receiving_id', '=', 'receiving_details.receiving_id')
            ->join('products', 'products.product_id', '=', 'receiving_details.product_id')
            ->leftJoin('users', 'users.user_id', '=', 'receivings.received_by')
            ->where('receivings.po_id', $poId)
            ->where('receivings.location_id', $locationId)
            ->orderBy('receiving_details.created_at', 'asc')
            ->get([
                'receivings.receiving_number',
                'receivings.receiving_date as received_at',
                DB::raw("CONCAT(users.first_name, ' ', users.last_name) AS received_by_name"),
                'products.product_name',
                'receiving_details.quantity_amount',
                'receiving_details.scanned_barcode',
            ]);

        // Serials scoped to this PO + warehouse, grouped by product, FIFO order.
        $receivingIds = DB::table('receivings')
            ->where('po_id', $poId)->where('location_id', $locationId)
            ->pluck('receiving_id')->all();

        $serialsByProduct = [];
        if (! empty($receivingIds)) {
            ItemSerial::query()
                ->whereIn('item_serial.receiving_id', $receivingIds)
                ->where('item_serial.location_id', $locationId)
                ->where('item_serial.serial_type', 'received')
                ->join('products', 'products.product_id', '=', 'item_serial.product_id')
                ->orderBy('item_serial.created_at', 'asc')
                ->get(['item_serial.serial_number', 'item_serial.product_id', 'products.product_name'])
                ->each(function ($s) use (&$serialsByProduct) {
                    $serialsByProduct[$s->product_id]['product_id']   = $s->product_id;
                    $serialsByProduct[$s->product_id]['product_name'] = $s->product_name;
                    $serialsByProduct[$s->product_id]['serials'][]     = $s->serial_number;
                });

            DB::table('receiving_details')
                ->join('products', 'products.product_id', '=', 'receiving_details.product_id')
                ->whereIn('receiving_details.receiving_id', $receivingIds)
                ->whereNotNull('receiving_details.scanned_barcode')
                ->orderBy('receiving_details.created_at', 'asc')
                ->get(['receiving_details.scanned_barcode', 'receiving_details.product_id', 'products.product_name'])
                ->each(function ($r) use (&$serialsByProduct) {
                    // Only consumables land here (appliances already covered above); avoid
                    // double-listing a product that produced item_serial rows.
                    if (isset($serialsByProduct[$r->product_id])) {
                        return;
                    }
                    $serialsByProduct[$r->product_id]['product_id']   = $r->product_id;
                    $serialsByProduct[$r->product_id]['product_name'] = $r->product_name;
                    $serialsByProduct[$r->product_id]['serials'][]     = $r->scanned_barcode;
                });
        }

        return $this->success([
            'overview'          => $overview,
            'receiving_history' => $receivingHistory,
            'serials'           => array_values($serialsByProduct),
        ], 'PO inventory group detail retrieved successfully');
    }
```

- [ ] **Step 4: Run to verify all pass**

Run: `cd back-end && php artisan test --filter=PoInventoryGroupsTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Regression + lint + commit**

Run: `cd back-end && php artisan test --filter=Inventory`
Expected: all Inventory tests pass.

Run: `cd back-end && php -l app/Http/Controllers/Api/PoInventoryController.php`
Expected: `No syntax errors detected`.

```bash
git add back-end/app/Http/Controllers/Api/PoInventoryController.php back-end/tests/Feature/Inventory/PoInventoryGroupsTest.php
git commit -m "feat(inventory): add PO+warehouse group detail endpoint (overview, receiving, serials)"
```

---

## Task 3: Frontend — API wrappers, tab restructure, PO console panel

**Files:**
- Modify: `frontend/src/admin/services/api.js`
- Modify: `frontend/src/admin/pages/InventoryManagement.jsx`

- [ ] **Step 1: Add API wrappers**

In `frontend/src/admin/services/api.js`, inside the `inventoryAPI` object, right after the
`updateBatchZone` wrapper (line 611, after its closing `}),`), add:

```js
  /** PO + warehouse inventory groups (Inventory Monitoring Console). */
  getPoGroups: async () => apiRequest('/po-inventory-groups'),
  /** One PO + warehouse group's 3-tab detail. */
  getPoGroupDetail: async (poId, warehouseId) =>
    apiRequest(`/po-inventory-groups/${poId}?location_id=${warehouseId}`),
```

- [ ] **Step 2: Add console state + fetch**

In `frontend/src/admin/pages/InventoryManagement.jsx`, right after the
`fetchWarehouseBatches` useCallback (ends line 255), add:

```jsx
  const [poGroups, setPoGroups] = useState([]);
  const [poGroupsLoading, setPoGroupsLoading] = useState(false);
  const [poGroupsError, setPoGroupsError] = useState(null);
  const [poSearch, setPoSearch] = useState('');
  const [expandedPoKeys, setExpandedPoKeys] = useState(new Set());
  const [poGroupDetail, setPoGroupDetail] = useState(null);
  const [poGroupDetailLoading, setPoGroupDetailLoading] = useState(false);
  const [poDetailTab, setPoDetailTab] = useState('overview');

  const fetchPoGroups = useCallback(async () => {
    setPoGroupsLoading(true);
    setPoGroupsError(null);
    try {
      const res = await inventoryAPI.getPoGroups();
      setPoGroups(res?.data ?? []);
    } catch (err) {
      setPoGroupsError(err?.message || 'Failed to load PO inventory groups.');
    } finally {
      setPoGroupsLoading(false);
    }
  }, []);

  const togglePoGroup = (key) => {
    setExpandedPoKeys((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key); else next.add(key);
      return next;
    });
  };

  const openPoGroupDetail = async (group) => {
    setPoGroupDetail({ loading: true, group });
    setPoDetailTab('overview');
    setPoGroupDetailLoading(true);
    try {
      const res = await inventoryAPI.getPoGroupDetail(group.po_id, group.warehouse_id);
      setPoGroupDetail({ group, data: res?.data ?? null });
    } catch (err) {
      setPoGroupDetail({ group, error: err?.message || 'Failed to load PO details.' });
    } finally {
      setPoGroupDetailLoading(false);
    }
  };
```

- [ ] **Step 3: Fetch groups when the console tab opens**

Find the warehouse-map fetch effect (around line 283-289; the `useEffect` that calls
`fetchWarehouseBatches` when `activeTab === 'warehouse-map'`). Add a sibling effect right
after it:

```jsx
  useEffect(() => {
    if (activeTab !== 'po-console') return;
    fetchPoGroups();
  }, [activeTab, fetchPoGroups]);
```

- [ ] **Step 4: Restructure the tab buttons**

Replace the tab-button block (lines 496-513, the `Inventory` button through the end of the
`Warehouse Map` button) — i.e. replace this:

```jsx
          <button
            className={`ak-tab ${activeTab === 'inventory' ? 'is-active' : ''}`}
            onClick={() => setActiveTab('inventory')}
          >
            Inventory
          </button>
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
```

with:

```jsx
          <button
            className={`ak-tab ${activeTab === 'po-console' ? 'is-active' : ''}`}
            onClick={() => setActiveTab('po-console')}
          >
            Inventory Monitoring Console
          </button>
          <button
            className={`ak-tab ${activeTab === 'stock-level' ? 'is-active' : ''}`}
            onClick={() => setActiveTab('stock-level')}
          >
            Stock-Level Monitoring
          </button>
          <button
            className={`ak-tab ${activeTab === 'activity' ? 'is-active' : ''}`}
            onClick={() => setActiveTab('activity')}
          >
            Activity Log
          </button>
```

- [ ] **Step 5: Move the per-SKU panel to the `stock-level` tab**

Change the per-SKU panel guard (line 692) from:

```jsx
        {activeTab === 'inventory' && (
```

to:

```jsx
        {activeTab === 'stock-level' && (
```

- [ ] **Step 6: Remove the Warehouse Map panel**

Delete the entire Warehouse Map panel block that begins at
`{activeTab === 'warehouse-map' && (` (line 920) and ends at its matching `)}` just before
`{activeTab === 'activity' && (() => {` (line 1038). Read the block first to confirm the
exact closing `)}` and delete precisely that block (the zone-edit helpers `startEditingZone`
/ `saveZoneEdit` and the `editingZone*` state are defined elsewhere and MUST remain — they are
reused in Step 7).

Also, in the scan-applied effect (line 276-278), remove the now-dead warehouse-map re-fetch
so no reference to the removed panel remains:

```jsx
      if (activeTab === 'warehouse-map') {
        fetchWarehouseBatches();
      }
```

Replace those three lines with:

```jsx
      if (activeTab === 'po-console') {
        fetchPoGroups();
      }
```

Update that effect's dependency array: replace `fetchWarehouseBatches` with `fetchPoGroups`.
(If eslint flags `fetchWarehouseBatches`/`warehouseBatches*` as now-unused, leave the state
declarations — they are harmless — or remove them if trivially unreferenced; do not remove
`getWarehouseBatches` from api.js.)

- [ ] **Step 7: Add the PO console panel**

Immediately AFTER the per-SKU panel's closing `)}` (the block whose guard you changed to
`stock-level` in Step 5) and BEFORE the `{activeTab === 'activity'` block, insert:

```jsx
        {activeTab === 'po-console' && (
          <div className="inv-table-card">
            <div className="inv-table-top">
              <div>
                <h3 className="inv-table-title">Inventory Monitoring Console</h3>
                <p className="inv-table-subtitle">Stock grouped by PO number and location (oldest first)</p>
              </div>
              <input
                type="text"
                className="inv-search"
                placeholder="Search PO, supplier, warehouse…"
                value={poSearch}
                onChange={(e) => setPoSearch(e.target.value)}
              />
            </div>

            {poGroupsLoading ? (
              <div className="inv-batch-loading">Loading PO groups…</div>
            ) : poGroupsError ? (
              <div className="inv-batch-empty inv-batch-empty--error">{poGroupsError}</div>
            ) : (
              <div className="inv-table-wrapper">
                <table className="inv-table">
                  <thead>
                    <tr>
                      <th>PO NUMBER</th>
                      <th>WAREHOUSE</th>
                      <th>LOCATION</th>
                      <th>SUPPLIER</th>
                      <th>RECEIVED</th>
                      <th>ITEMS</th>
                      <th>QTY</th>
                      <th>ACTIONS</th>
                    </tr>
                  </thead>
                  <tbody>
                    {poGroups.filter((g) => {
                      const q = poSearch.trim().toLowerCase();
                      if (!q) return true;
                      return [g.po_number, g.supplier_name, g.warehouse_name, g.location_label]
                        .some((v) => (v || '').toLowerCase().includes(q));
                    }).length === 0 ? (
                      <tr><td colSpan="8" style={{ textAlign: 'center', color: '#9ca3af', padding: '40px' }}>No PO groups found</td></tr>
                    ) : (
                      poGroups.filter((g) => {
                        const q = poSearch.trim().toLowerCase();
                        if (!q) return true;
                        return [g.po_number, g.supplier_name, g.warehouse_name, g.location_label]
                          .some((v) => (v || '').toLowerCase().includes(q));
                      }).map((g) => {
                        const isExpanded = expandedPoKeys.has(g.group_key);
                        return (
                          <React.Fragment key={g.group_key}>
                            <tr>
                              <td><strong>{g.po_number || '—'}</strong></td>
                              <td>{g.warehouse_name || '—'}</td>
                              <td>{g.location_label || '—'}</td>
                              <td>{g.supplier_name || '—'}</td>
                              <td>{g.first_received_at || '—'}</td>
                              <td className="inv-cell-num">{g.item_count}</td>
                              <td className="inv-cell-num">{g.total_remaining} / {g.total_received}</td>
                              <td>
                                <div className="inv-cell-actions">
                                  <button className="inv-action-link" onClick={() => togglePoGroup(g.group_key)} aria-expanded={isExpanded}>
                                    {isExpanded ? 'Hide batches' : 'Batches'}
                                  </button>
                                  <button className="inv-action-link" onClick={() => openPoGroupDetail(g)}>View</button>
                                </div>
                              </td>
                            </tr>
                            {isExpanded && (
                              <tr className="inv-batch-row">
                                <td colSpan={8}>
                                  {g.batches.length === 0 ? (
                                    <div className="inv-batch-empty">No batches.</div>
                                  ) : (
                                    <table className="inv-batch-table">
                                      <thead>
                                        <tr>
                                          <th>Product</th>
                                          <th>Location</th>
                                          <th>Received</th>
                                          <th>Qty Remaining</th>
                                          <th>Qty Received</th>
                                        </tr>
                                      </thead>
                                      <tbody>
                                        {g.batches.map((b) => (
                                          <tr key={b.batch_id}>
                                            <td>{b.product_name}</td>
                                            <td>
                                              {editingZoneBatchId === b.batch_id ? (
                                                <span className="inv-warehouse-map-zone-edit">
                                                  <input
                                                    type="text"
                                                    className="inv-warehouse-map-zone-input"
                                                    value={editingZoneValue}
                                                    onChange={(e) => setEditingZoneValue(e.target.value)}
                                                    placeholder="e.g. Location 1"
                                                    autoFocus
                                                  />
                                                  <button type="button" className="inv-warehouse-map-zone-save" onClick={() => saveZoneEdit(b.batch_id)}>Save</button>
                                                  <button type="button" className="inv-warehouse-map-zone-cancel" onClick={() => setEditingZoneBatchId(null)}>Cancel</button>
                                                </span>
                                              ) : (
                                                <span>
                                                  {b.zone_label || 'Unassigned'}
                                                  <button type="button" className="inv-action-link" style={{ marginLeft: 8 }} onClick={() => startEditingZone(b)}>Edit</button>
                                                </span>
                                              )}
                                            </td>
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
                    )}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}
```

Note: after `saveZoneEdit` succeeds, the console list should reflect the new label. If
`saveZoneEdit` (line 455) only updates `warehouseBatches`, extend it to also re-fetch PO
groups: add `fetchPoGroups();` alongside its existing refresh, OR call `fetchPoGroups()` in
its success path. Read `saveZoneEdit` and make the minimal change so an edited Location label
refreshes the console.

- [ ] **Step 8: Lint + commit**

Run: `cd frontend && npx eslint src/admin/pages/InventoryManagement.jsx src/admin/services/api.js`
Expected: 0 errors (the pre-existing `warehouses` warning is acceptable). Fix any new errors
(most likely an unbalanced tag from the Step 6 deletion — re-check the block boundaries).

```bash
git add frontend/src/admin/services/api.js frontend/src/admin/pages/InventoryManagement.jsx
git commit -m "feat(inventory): PO-centric console tab + stock-level tab (warehouse map folded in)"
```

---

## Task 4: Frontend — PO group View modal (3 tabs)

**Files:**
- Modify: `frontend/src/admin/pages/InventoryManagement.jsx`
- Modify: `frontend/src/admin/styles/inventory_management.css`

- [ ] **Step 1: Add the PO group modal**

In `frontend/src/admin/pages/InventoryManagement.jsx`, find the batch-serials modal block
added earlier (`{serialModalBatch && (` … `</Modal>` … `)}`). Immediately AFTER that block's
closing `)}` and before the container `</div>` / `</AdminLayout>`, insert:

```jsx
        {poGroupDetail && (
          <Modal
            isOpen={!!poGroupDetail}
            onClose={() => { setPoGroupDetail(null); setPoDetailTab('overview'); }}
            title={`${poGroupDetail.group?.po_number || 'PO'} — ${poGroupDetail.group?.warehouse_name || ''}`}
            maxWidth={920}
          >
            <div className="inv-view-details">
              <div className="invd-tabs">
                <button className={`invd-tab-btn${poDetailTab === 'overview' ? ' active' : ''}`} onClick={() => setPoDetailTab('overview')}>Overview</button>
                <button className={`invd-tab-btn${poDetailTab === 'history' ? ' active' : ''}`} onClick={() => setPoDetailTab('history')}>Stock-In History</button>
                <button className={`invd-tab-btn${poDetailTab === 'serials' ? ' active' : ''}`} onClick={() => setPoDetailTab('serials')}>Serial Numbers</button>
              </div>

              {poGroupDetailLoading && <Loader label="Loading PO details" />}
              {poGroupDetail.error && <div className="invd-error-msg">{poGroupDetail.error}</div>}

              {!poGroupDetailLoading && poGroupDetail.data && (
                <>
                  {poDetailTab === 'overview' && (
                    <div className="invd-panel">
                      <div className="invd-section">
                        <div className="invd-section-title">PO Information</div>
                        <div className="invd-grid">
                          <div className="invd-field"><span className="invd-label">PO Number</span><span className="invd-value invd-mono">{poGroupDetail.data.overview.po_number || '—'}</span></div>
                          <div className="invd-field"><span className="invd-label">Supplier</span><span className="invd-value">{poGroupDetail.data.overview.supplier_name || '—'}</span></div>
                          <div className="invd-field"><span className="invd-label">Order Date</span><span className="invd-value">{poGroupDetail.data.overview.order_date || '—'}</span></div>
                          <div className="invd-field"><span className="invd-label">Warehouse</span><span className="invd-value">{poGroupDetail.data.overview.warehouse_name || '—'}</span></div>
                          <div className="invd-field"><span className="invd-label">Location</span><span className="invd-value">{poGroupDetail.data.overview.location_label || '—'}</span></div>
                          <div className="invd-field"><span className="invd-label">Items</span><span className="invd-value">{poGroupDetail.data.overview.totals.item_count}</span></div>
                        </div>
                      </div>
                      <div className="invd-section">
                        <div className="invd-section-title">Products ({poGroupDetail.data.overview.totals.total_remaining} / {poGroupDetail.data.overview.totals.total_received} remaining)</div>
                        <div className="invd-table-wrap">
                          <table className="invd-table">
                            <thead><tr><th>Product</th><th>Qty Remaining</th><th>Qty Received</th></tr></thead>
                            <tbody>
                              {poGroupDetail.data.overview.products.map((p) => (
                                <tr key={p.product_id}><td>{p.product_name}</td><td>{p.quantity_remaining}</td><td>{p.quantity_received}</td></tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      </div>
                    </div>
                  )}

                  {poDetailTab === 'history' && (
                    <div className="invd-panel">
                      {poGroupDetail.data.receiving_history.length === 0 ? (
                        <div className="invd-empty">No receiving history.</div>
                      ) : (
                        <div className="invd-table-wrap">
                          <table className="invd-table">
                            <thead><tr><th>Receiving #</th><th>Date</th><th>Received By</th><th>Product</th><th>Qty</th><th>Barcode</th></tr></thead>
                            <tbody>
                              {poGroupDetail.data.receiving_history.map((r, i) => (
                                <tr key={i}>
                                  <td className="invd-mono invd-small">{r.receiving_number || '—'}</td>
                                  <td>{r.received_at ? new Date(r.received_at).toLocaleDateString('en-PH') : '—'}</td>
                                  <td>{r.received_by_name?.trim() || '—'}</td>
                                  <td>{r.product_name}</td>
                                  <td>{r.quantity_amount}</td>
                                  <td className="invd-mono invd-small">{r.scanned_barcode || '—'}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      )}
                    </div>
                  )}

                  {poDetailTab === 'serials' && (
                    <div className="invd-panel">
                      {poGroupDetail.data.serials.length === 0 ? (
                        <div className="invd-empty">No serial numbers recorded for this PO at this location.</div>
                      ) : (
                        poGroupDetail.data.serials.map((p) => (
                          <div className="invd-section" key={p.product_id}>
                            <div className="invd-section-title">{p.product_name} ({p.serials.length})</div>
                            <div className="inv-serial-chips">
                              {p.serials.map((sn, i) => (<span className="inv-serial-chip" key={i}>{sn}</span>))}
                            </div>
                          </div>
                        ))
                      )}
                    </div>
                  )}
                </>
              )}
            </div>
          </Modal>
        )}
```

- [ ] **Step 2: Confirm `Loader` is imported**

Verify `Loader` is already imported in the file (it is used by the existing detail modal at
`historyLoading && <Loader …>`). If not present, do not add a new dependency — replace
`<Loader label="Loading PO details" />` with `<div className="inv-batch-loading">Loading PO details…</div>`.

- [ ] **Step 3: Lint**

Run: `cd frontend && npx eslint src/admin/pages/InventoryManagement.jsx`
Expected: 0 errors (pre-existing `warehouses` warning acceptable).

- [ ] **Step 4: Manual verification**

Open the app → Inventory:
- Tabs read: Stock Movement & Turnover · Inventory Monitoring Console · Stock-Level
  Monitoring · Activity Log.
- **Inventory Monitoring Console:** rows are PO+Location groups, oldest first; expanding shows
  batches (with editable Location); **View** opens the modal.
- Modal: Overview shows PO/supplier/warehouse/Location + products; Stock-In History lists the
  receiving events; Serial Numbers shows serials grouped by product, oldest first, scoped to
  that PO + warehouse.
- **Stock-Level Monitoring:** the original per-SKU table (current/safety/reorder/max) still
  works with its own View modal.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/pages/InventoryManagement.jsx frontend/src/admin/styles/inventory_management.css
git commit -m "feat(inventory): PO group View modal with Overview/Stock-In/Serials tabs"
```

---

## Self-Review Notes

- **Spec coverage:** list endpoint (Task 1), detail endpoint w/ scoped FIFO serials (Task 2),
  tab restructure + Stock-Level tab + Warehouse Map fold-in w/ Location edit (Task 3), PO
  group 3-tab modal (Task 4) — all covered.
- **Field-name consistency:** endpoints emit `group_key`, `po_id`, `warehouse_id`,
  `location_label`, `first_received_at`, `item_count`, `total_received`/`total_remaining`,
  `batches`; detail emits `overview`/`receiving_history`/`serials`; frontend reads exactly
  these.
- **Route safety:** new endpoints under `/po-inventory-groups`, not nested in `/inventory/*`.
- **No schema changes; additive.** Existing per-SKU flow and modal untouched (just moved tab).
- **Intermediate state:** Task 3 both removes the warehouse-map panel and adds the console, so
  no tab points at a missing panel after the task completes.
