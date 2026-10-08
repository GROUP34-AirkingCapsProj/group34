# Per-Row Stock In / Stock Out / Transfer Columns — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-row Stock In / Stock Out / Transfer columns to the Inventory detail modal's Serial Numbers table, filling every attributable cell and rendering `—` where the data cannot attribute a movement to that row.

**Architecture:** The backend `stockHistory` endpoint attaches three fields (`row_stock_in`, `row_stock_out`, `row_transfer`) to each `serial_records` entry — computed differently for appliance (per-unit) rows and consumable (per-batch) rows. The React table renders three new columns, showing `—` for null cells.

**Tech Stack:** Laravel (PHP) API + React (CRA); PHPUnit feature tests on the `airkingV5_test` DB via `DatabaseTransactions`.

---

## Background / Context

- Endpoint: `GET /api/inventory/{inventory}/history` → `InventoryController@stockHistory`
  (`back-end/routes/api.php:796`).
- `serial_records` is built two ways in `back-end/app/Http/Controllers/Api/InventoryController.php`:
  - **Appliances** (`if ($isSerialized)`, lines 199-221): a query-builder `->get()` of
    `item_serial` rows (each row carries `serial_type`, values `'received'` / `'transferred'`).
  - **Consumables** (`else`, lines 222-238): synthetic rows mapped from `$receivingDetails`,
    one per receiving batch; source `$rd` carries `receiving_detail_id`, `receiving_id`,
    `quantity_amount`.
- Attribution rules (from the approved spec):
  - Consumable row: Stock In = `quantity_amount`; Stock Out = pieces sold whose
    `issuance_details.receiving_id` matches this batch's `receiving_id`; Transfer = `null` (—).
  - Appliance row: Stock In = `1`; Stock Out = `null` (—, sold serials are deleted);
    Transfer = `1` if `serial_type === 'transferred'` else `0`.
- `DB` facade already imported (`InventoryController.php:13`). `$productId` and `$locationId`
  already defined earlier in `stockHistory`.
- Render rule: a number (including `0`) shows as-is; `null`/`undefined` shows `—`.
  `value ?? '—'` satisfies this because `0 ?? '—'` is `0`.

## File Structure

- Modify: `back-end/app/Http/Controllers/Api/InventoryController.php` — enrich both serial
  branches with the three per-row fields (+ one grouped sold-per-batch query).
- Create: `back-end/tests/Feature/Inventory/SerialRowMovementColumnsTest.php` — feature test
  for both product types.
- Modify: `frontend/src/admin/pages/InventoryManagement.jsx` — three new table columns.

---

## Task 1: Backend — attach per-row movement fields to serial_records

**Files:**
- Test: `back-end/tests/Feature/Inventory/SerialRowMovementColumnsTest.php` (create)
- Modify: `back-end/app/Http/Controllers/Api/InventoryController.php` (method `stockHistory`, serial-records block lines 197-239)

- [ ] **Step 1: Write the failing feature test**

Create `back-end/tests/Feature/Inventory/SerialRowMovementColumnsTest.php`:

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
use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\ItemSerial;

class SerialRowMovementColumnsTest extends TestCase
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

    public function test_consumable_rows_carry_per_batch_in_and_out(): void
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
            'quantity_on_hand'   => 20,
            'available_quantity' => 20,
        ]);
        $user = $this->makeUser();
        $po = PurchaseOrder::create([
            'po_number'           => 'PO-' . uniqid(),
            'location_id'         => $loc->location_id,
            'verification_status' => 'verified',
            'status_id'           => 1,
        ]);

        // Two receiving batches, each with a distinct scanned barcode so both become rows.
        $receivingIds = [];
        foreach ([['BC-A', 12], ['BC-B', 8]] as [$bc, $qty]) {
            $this->actingAs($user)->postJson('/api/receivings', [
                'pc_id'            => $po->po_id,
                'location_id'      => $loc->location_id,
                'receiving_number' => 'RCV-' . uniqid(),
                'receiving_date'   => now()->toDateString(),
                'details'          => [[
                    'product_id'      => $product->product_id,
                    'quantity_amount' => $qty,
                    'condition'       => 'Good',
                    'scanned_barcode' => $bc . '-' . uniqid(),
                ]],
            ])->assertStatus(201);
        }
        // Resolve the two receiving_ids for this product at this location, oldest first.
        $rows = DB::table('receiving_details')
            ->join('receivings', 'receivings.receiving_id', '=', 'receiving_details.receiving_id')
            ->where('receiving_details.product_id', $product->product_id)
            ->where('receivings.location_id', $loc->location_id)
            ->orderBy('receiving_details.created_at')
            ->get(['receiving_details.receiving_id']);
        $firstReceivingId = $rows[0]->receiving_id;

        // Sell 5 pieces attributed to the FIRST batch's receiving.
        $issuance = Issuance::create([
            'location_id'   => $loc->location_id,
            'issuance_date' => now()->toDateString(),
            'status_id'     => 1,
        ]);
        IssuanceDetail::create([
            'issuance_id'        => $issuance->issuance_id,
            'product_id'         => $product->product_id,
            'receiving_id'       => $firstReceivingId,
            'quantity_issued'    => 5,
            'quantity_in_pieces' => 5,
        ]);

        $records = $this->actingAs($user)
            ->getJson('/api/inventory/' . $inventory->inventory_id . '/history')
            ->assertStatus(200)
            ->json('data.serial_records');

        $byReceiving = [];
        foreach ($records as $r) {
            // Match rows back to their receiving via row_stock_in (12 vs 8).
            $byReceiving[$r['row_stock_in']] = $r;
        }

        $this->assertSame(12, $byReceiving[12]['row_stock_in']);
        $this->assertSame(5,  $byReceiving[12]['row_stock_out']);
        $this->assertNull($byReceiving[12]['row_transfer']);

        $this->assertSame(8, $byReceiving[8]['row_stock_in']);
        $this->assertSame(0, $byReceiving[8]['row_stock_out']);
        $this->assertNull($byReceiving[8]['row_transfer']);
    }

    public function test_appliance_rows_carry_unit_in_and_transfer_flag(): void
    {
        $loc = Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Loc',
            'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(),
            'product_name' => 'Test Appliance',
            'product_type' => 'appliance',
        ]);
        // Appliances are serialized by default (Product::isSerialized() returns true when
        // no appliance_details row exists), so stockHistory takes the item_serial branch.
        $inventory = Inventory::create([
            'product_id'         => $product->product_id,
            'location_id'        => $loc->location_id,
            'quantity_on_hand'   => 2,
            'available_quantity' => 2,
        ]);
        $user = $this->makeUser();

        ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $loc->location_id,
            'serial_number' => 'SN-IN-' . uniqid(),
            'serial_type'   => 'received',
            'status_id'     => 26,
        ]);
        ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $loc->location_id,
            'serial_number' => 'SN-TR-' . uniqid(),
            'serial_type'   => 'transferred',
            'status_id'     => 26,
        ]);

        $records = $this->actingAs($user)
            ->getJson('/api/inventory/' . $inventory->inventory_id . '/history')
            ->assertStatus(200)
            ->json('data.serial_records');

        $this->assertCount(2, $records);
        foreach ($records as $r) {
            $this->assertSame(1, $r['row_stock_in']);
            $this->assertNull($r['row_stock_out']);
        }
        $transferFlags = array_map(fn($r) => $r['row_transfer'], $records);
        sort($transferFlags);
        $this->assertSame([0, 1], $transferFlags);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=SerialRowMovementColumnsTest`
Expected: FAIL — `row_stock_in` / `row_stock_out` / `row_transfer` keys are absent
("Undefined array key" / null-vs-int assertion failures).

If the appliance test errors because `appliance_details` requires more columns than
`product_id` + `is_serialized`, report NEEDS_CONTEXT with the DB error rather than guessing —
the controller reads `is_serialized` via `Product::isSerialized()`, so the minimal insert
should suffice, but flag it if the schema demands more.

- [ ] **Step 3: Enrich the appliance branch**

In `back-end/app/Http/Controllers/Api/InventoryController.php`, locate the end of the
appliance query (lines 220-221):

```php
                ->orderBy('item_serial.created_at', 'desc')
                ->get();
```

Replace with:

```php
                ->orderBy('item_serial.created_at', 'desc')
                ->get()
                ->map(function ($s) {
                    $s->row_stock_in  = 1;
                    $s->row_stock_out = null; // sold serials are deleted → not attributable per row
                    $s->row_transfer  = $s->serial_type === 'transferred' ? 1 : 0;
                    return $s;
                });
```

- [ ] **Step 4: Enrich the consumable branch**

In the same method, locate the `else` branch (lines 222-238). Replace the whole `else`
block:

```php
        } else {
            $serials = $receivingDetails
                ->filter(fn($rd) => ! empty($rd->scanned_barcode))
                ->map(fn($rd) => (object) [
                    'serial_id'       => $rd->receiving_detail_id,
                    'serial_number'   => $rd->scanned_barcode,
                    'serial_type'     => 'received',
                    'scanned_at'      => $rd->received_at,
                    'scanned_by_name' => $rd->received_by_name,
                    'serial_status'   => 'Received',
                    'receiving_number'=> $rd->receiving_number,
                    'receiving_date'  => $rd->receiving_date,
                    'po_number'       => $rd->po_number,
                    'po_date'         => $rd->po_date,
                    'supplier_name'   => $rd->supplier_name,
                ])
                ->values();
        }
```

with:

```php
        } else {
            // Pieces sold per receiving batch (issuance_details link a sale to its batch).
            $soldByReceiving = DB::table('issuance_details')
                ->join('issuances', 'issuances.issuance_id', '=', 'issuance_details.issuance_id')
                ->where('issuance_details.product_id', $productId)
                ->where('issuances.location_id', $locationId)
                ->whereNull('issuances.deleted_at')
                ->whereNotNull('issuance_details.receiving_id')
                ->groupBy('issuance_details.receiving_id')
                ->selectRaw('issuance_details.receiving_id AS receiving_id, '
                    . 'SUM(COALESCE(issuance_details.quantity_in_pieces, issuance_details.quantity_issued, 0)) AS sold')
                ->pluck('sold', 'receiving_id');

            $serials = $receivingDetails
                ->filter(fn($rd) => ! empty($rd->scanned_barcode))
                ->map(fn($rd) => (object) [
                    'serial_id'       => $rd->receiving_detail_id,
                    'serial_number'   => $rd->scanned_barcode,
                    'serial_type'     => 'received',
                    'scanned_at'      => $rd->received_at,
                    'scanned_by_name' => $rd->received_by_name,
                    'serial_status'   => 'Received',
                    'receiving_number'=> $rd->receiving_number,
                    'receiving_date'  => $rd->receiving_date,
                    'po_number'       => $rd->po_number,
                    'po_date'         => $rd->po_date,
                    'supplier_name'   => $rd->supplier_name,
                    'row_stock_in'    => (int) $rd->quantity_amount,
                    'row_stock_out'   => (int) ($soldByReceiving[$rd->receiving_id] ?? 0),
                    'row_transfer'    => null, // transfers are not batch-linked → not attributable per row
                ])
                ->values();
        }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd back-end && php artisan test --filter=SerialRowMovementColumnsTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Run the existing summary test for regression**

Run: `cd back-end && php artisan test --filter=StockHistoryMovementSummaryTest`
Expected: PASS (1 test) — the summary block is untouched.

- [ ] **Step 7: Lint**

Run: `cd back-end && php -l app/Http/Controllers/Api/InventoryController.php`
Expected: `No syntax errors detected`.

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Http/Controllers/Api/InventoryController.php back-end/tests/Feature/Inventory/SerialRowMovementColumnsTest.php
git commit -m "feat(inventory): attach per-row stock-in/out/transfer to serial_records"
```

---

## Task 2: Frontend — render the three columns

**Files:**
- Modify: `frontend/src/admin/pages/InventoryManagement.jsx` (Serial Numbers table, headers ~line 1449-1457, body ~line 1460-1489)

- [ ] **Step 1: Add the three column headers**

In `frontend/src/admin/pages/InventoryManagement.jsx`, locate the header row:

```jsx
                          <tr>
                            <th>#</th>
                            <th>Serial Number</th>
                            <th>Status</th>
                            <th>Scanned By</th>
                            <th>Date Scanned</th>
                            <th>PO Number</th>
                            <th>Supplier</th>
                          </tr>
```

Replace with (three new headers inserted after Status):

```jsx
                          <tr>
                            <th>#</th>
                            <th>Serial Number</th>
                            <th>Status</th>
                            <th>Stock In</th>
                            <th>Stock Out</th>
                            <th>Transfer</th>
                            <th>Scanned By</th>
                            <th>Date Scanned</th>
                            <th>PO Number</th>
                            <th>Supplier</th>
                          </tr>
```

- [ ] **Step 2: Add the three body cells**

Locate the Status `<td>` closing and the Scanned By `<td>` in the row body:

```jsx
                              <td>
                                <span className={`invd-status-pill${
                                  s.serial_status === 'In Stock' ? ' invd-pill-green'
                                  : s.serial_status === 'Issued' ? ' invd-pill-orange'
                                  : ' invd-pill-gray'
                                }`}>
                                  {s.serial_status ?? 'Active'}
                                </span>
                              </td>
                              <td>{s.scanned_by_name ?? '—'}</td>
```

Replace with (three number cells inserted between Status and Scanned By):

```jsx
                              <td>
                                <span className={`invd-status-pill${
                                  s.serial_status === 'In Stock' ? ' invd-pill-green'
                                  : s.serial_status === 'Issued' ? ' invd-pill-orange'
                                  : ' invd-pill-gray'
                                }`}>
                                  {s.serial_status ?? 'Active'}
                                </span>
                              </td>
                              <td className="invd-mono">{s.row_stock_in ?? '—'}</td>
                              <td className="invd-mono">{s.row_stock_out ?? '—'}</td>
                              <td className="invd-mono">{s.row_transfer ?? '—'}</td>
                              <td>{s.scanned_by_name ?? '—'}</td>
```

(`?? '—'` preserves `0` and renders `—` only for `null`/`undefined`, matching the backend
contract where unattributable cells are `null`.)

- [ ] **Step 3: Lint the file**

Run: `cd frontend && npx eslint src/admin/pages/InventoryManagement.jsx`
Expected: `0 errors`. A pre-existing `no-unused-vars` warning on `warehouses` (line ~104)
is unrelated and acceptable; no new errors/warnings in the edited region.

- [ ] **Step 4: Manual verification**

Open the app → Inventory → open the detail modal → Serial Numbers tab for:
- a **consumable** product: each batch row shows Stock In (received qty), Stock Out (sold
  from that batch), and `—` under Transfer.
- an **appliance** product: each unit row shows Stock In `1`, `—` under Stock Out, and `1`
  or `0` under Transfer depending on whether it was transferred.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/pages/InventoryManagement.jsx
git commit -m "feat(inventory): show per-row stock-in/out/transfer columns on Serial Numbers table"
```

---

## Self-Review Notes

- **Spec coverage:** consumable per-batch in/out/`—`transfer (Task 1 Step 4), appliance
  per-unit in/`—`out/transfer-flag (Task 1 Step 3), grouped sold-per-batch query (Task 1
  Step 4), three frontend columns with `—` rendering (Task 2), both product types tested
  (Task 1 test) — all covered.
- **Field-name consistency:** `row_stock_in` / `row_stock_out` / `row_transfer` identical in
  backend enrichment (Task 1 Steps 3-4), test assertions (Task 1 Step 1), and frontend reads
  (Task 2 Step 2).
- **No placeholders:** all code shown in full.
- **Backward compatibility:** summary block and existing serial_records fields unchanged;
  only additive per-row fields.
