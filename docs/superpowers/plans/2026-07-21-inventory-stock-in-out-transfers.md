# Inventory Stock In / Stock Out / Transfers Summary — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a per-product, per-location "Stock In / Stock Out / Transfers" summary line to the Inventory detail modal's Serial Numbers tab, so a transferred unit is never mistaken for a customer sale.

**Architecture:** Extend the existing `InventoryController::stockHistory` JSON `summary` block with three piece-count fields sourced from separate tables (receiving for in, `issuance_details` for sold, `transfer_details` for transferred). The React Serial Numbers tab renders a small stat row from those fields. Sales and transfers stay distinct because each is summed from its own table.

**Tech Stack:** Laravel (PHP) API + React (CRA) frontend; PHPUnit feature tests against the `airkingV5_test` DB using `DatabaseTransactions`.

---

## Background / Context

- Endpoint: `GET /api/inventory/{inventory}/history` → `InventoryController@stockHistory`
  (route registered in `back-end/routes/api.php:796`).
- The method already loads the `Inventory` row (with `product_id`, `location_id`), builds
  `$receivingDetails`, and computes `$totalPieces = $receivingDetails->sum('quantity_amount')`
  (`back-end/app/Http/Controllers/Api/InventoryController.php:245`).
- The response `summary` block is at
  `back-end/app/Http/Controllers/Api/InventoryController.php:290-299`.
- `DB` facade is already imported (`InventoryController.php:13`).
- Data sources (all in pieces, same shape for appliances and consumables):
  - **Stock In** = `$totalPieces` (already computed).
  - **Stock Out** (sold) = `SUM(issuance_details.quantity_in_pieces)` joined to `issuances`
    where `issuances.location_id = $locationId` and `issuance_details.product_id = $productId`,
    excluding soft-deleted issuances.
  - **Transfers** = `SUM(transfer_details.quantity_transferred)` joined to `transfers`
    where `transfers.from_location_id = $locationId` and `transfer_details.product_id = $productId`,
    excluding soft-deleted transfers.
- `issuances` and `transfers` both use `SoftDeletes` (guard with `whereNull(...deleted_at)`).
- Both issuance paths populate `quantity_in_pieces` (appliance = 1 per unit,
  `IssuanceScanController.php:275`; consumable = piece count, `:381`). We still
  `COALESCE(quantity_in_pieces, quantity_issued, 0)` to protect against any legacy row that
  left `quantity_in_pieces` null.
- `transfer_details.quantity_transferred` is null/0 until dispatch, so requested/draft
  transfers naturally contribute nothing; `COALESCE(..., 0)` keeps the SUM null-safe.

## File Structure

- Modify: `back-end/app/Http/Controllers/Api/InventoryController.php` — add two aggregate
  queries + three `summary` fields in `stockHistory`.
- Create: `back-end/tests/Feature/Inventory/StockHistoryMovementSummaryTest.php` — feature
  test proving the three counts and the sold-vs-transferred separation.
- Modify: `frontend/src/admin/pages/InventoryManagement.jsx` — render the stat row in the
  Serial Numbers tab body.
- Modify: `frontend/src/admin/styles/inventory_management.css` — styling for the stat row.

---

## Task 1: Backend — add Stock In / Stock Out / Transfers to stockHistory summary

**Files:**
- Test: `back-end/tests/Feature/Inventory/StockHistoryMovementSummaryTest.php` (create)
- Modify: `back-end/app/Http/Controllers/Api/InventoryController.php` (method `stockHistory`, summary block ~lines 250-299)

- [ ] **Step 1: Write the failing feature test**

Create `back-end/tests/Feature/Inventory/StockHistoryMovementSummaryTest.php`:

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
use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\Transfer;
use App\Models\TransferDetail;

class StockHistoryMovementSummaryTest extends TestCase
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

    public function test_summary_splits_sold_and_transferred_and_reports_stock_in(): void
    {
        $source = Location::create([
            'location_code' => 'SRC-' . uniqid(),
            'location_name' => 'Source Loc',
            'location_type' => 'warehouse',
        ]);
        $dest = Location::create([
            'location_code' => 'DST-' . uniqid(),
            'location_name' => 'Dest Loc',
            'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'Test Consumable',
            'product_type' => 'installation_material',
        ]);

        $inventory = Inventory::create([
            'product_id'         => $product->product_id,
            'location_id'        => $source->location_id,
            'quantity_on_hand'   => 30,
            'available_quantity' => 30,
        ]);

        // Stock In: two receiving events (12 + 8 = 20 pieces) at the source location.
        $user = $this->makeUser();
        $po = \App\Models\PurchaseOrder::create([
            'po_number'           => 'PO-' . uniqid(),
            'location_id'         => $source->location_id,
            'verification_status' => 'verified',
            'status_id'           => 1,
        ]);
        foreach ([12, 8] as $qty) {
            $this->actingAs($user)->postJson('/api/receivings', [
                'pc_id'            => $po->po_id,
                'location_id'      => $source->location_id,
                'receiving_number' => 'RCV-' . uniqid(),
                'receiving_date'   => now()->toDateString(),
                'details'          => [
                    ['product_id' => $product->product_id, 'quantity_amount' => $qty, 'condition' => 'Good'],
                ],
            ])->assertStatus(201);
        }

        // Stock Out (sold): one issuance of 5 pieces at the source location.
        $issuance = Issuance::create([
            'location_id'   => $source->location_id,
            'issuance_date' => now()->toDateString(),
            'status_id'     => 1,
        ]);
        IssuanceDetail::create([
            'issuance_id'        => $issuance->issuance_id,
            'product_id'         => $product->product_id,
            'quantity_issued'    => 5,
            'quantity_in_pieces' => 5,
        ]);

        // Transfers: one dispatched transfer of 7 pieces OUT of the source location.
        $transfer = Transfer::create([
            'from_location_id'          => $source->location_id,
            'to_location_id'            => $dest->location_id,
            'transfer_number'           => 'TRF-' . uniqid(),
            'transfer_date'             => now(),
            'total_quantity_transferred'=> 7,
            'status_id'                 => 1,
        ]);
        TransferDetail::create([
            'transfer_id'          => $transfer->transfer_id,
            'product_id'           => $product->product_id,
            'quantity_requested'   => 7,
            'quantity_transferred' => 7,
        ]);

        $resp = $this->actingAs($user)
            ->getJson('/api/inventory/' . $inventory->inventory_id . '/history')
            ->assertStatus(200);

        $summary = $resp->json('data.summary');

        $this->assertSame(20, $summary['stock_in'], 'stock_in should equal total received pieces');
        $this->assertSame(5, $summary['stock_out'], 'stock_out should count sold pieces only, not transfers');
        $this->assertSame(7, $summary['transferred'], 'transferred should count transferred pieces only, not sales');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:
```bash
cd back-end && php artisan test --filter=StockHistoryMovementSummaryTest
```
Expected: FAIL — assertions on `$summary['stock_in'|'stock_out'|'transferred']` fail with
"Undefined array key" (the summary block does not yet contain these keys).

- [ ] **Step 3: Add the two aggregate queries**

In `back-end/app/Http/Controllers/Api/InventoryController.php`, in `stockHistory`, locate:

```php
        $latestReceiving = $receivingDetails->first();
```

Insert immediately AFTER that line:

```php

        // ── Movement summary: distinguish customer sales from transfers ────
        // Sold and transferred pieces come from separate tables, so a transfer
        // can never be counted as a sale (and vice versa). Both work the same
        // for appliances and consumables — they are recorded as quantities.
        $soldPieces = (int) DB::table('issuance_details')
            ->join('issuances', 'issuances.issuance_id', '=', 'issuance_details.issuance_id')
            ->where('issuance_details.product_id', $productId)
            ->where('issuances.location_id', $locationId)
            ->whereNull('issuances.deleted_at')
            ->sum(DB::raw('COALESCE(issuance_details.quantity_in_pieces, issuance_details.quantity_issued, 0)'));

        $transferredPieces = (int) DB::table('transfer_details')
            ->join('transfers', 'transfers.transfer_id', '=', 'transfer_details.transfer_id')
            ->where('transfer_details.product_id', $productId)
            ->where('transfers.from_location_id', $locationId)
            ->whereNull('transfers.deleted_at')
            ->sum(DB::raw('COALESCE(transfer_details.quantity_transferred, 0)'));
```

- [ ] **Step 4: Add the three fields to the summary block**

In the same method, locate the `summary` array ending:

```php
                'latest_supplier'       => $latestReceiving?->supplier_name ?? null,
                'serial_count'          => $serials->count(),
            ],
```

Replace it with:

```php
                'latest_supplier'       => $latestReceiving?->supplier_name ?? null,
                'serial_count'          => $serials->count(),
                'stock_in'              => (int) $totalPieces,
                'stock_out'             => $soldPieces,
                'transferred'           => $transferredPieces,
            ],
```

- [ ] **Step 5: Run the test to verify it passes**

Run:
```bash
cd back-end && php artisan test --filter=StockHistoryMovementSummaryTest
```
Expected: PASS (1 test, 3 assertions).

- [ ] **Step 6: Lint the controller**

Run:
```bash
cd back-end && php -l app/Http/Controllers/Api/InventoryController.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/InventoryController.php back-end/tests/Feature/Inventory/StockHistoryMovementSummaryTest.php
git commit -m "feat(inventory): add stock-in/out/transfers to stockHistory summary"
```

---

## Task 2: Frontend — render the stat row on the Serial Numbers tab

**Files:**
- Modify: `frontend/src/admin/pages/InventoryManagement.jsx` (Serial Numbers tab body, panel opens at line 1428)
- Modify: `frontend/src/admin/styles/inventory_management.css` (add stat-row styles near the serial-status pill block ~line 606)

- [ ] **Step 1: Add the stat row to the Serial Numbers panel**

In `frontend/src/admin/pages/InventoryManagement.jsx`, locate the Serial Numbers panel open:

```jsx
              {detailTab === 'serials' && !historyLoading && historyData && (
                <div className="invd-panel">
                  {!historyData.serial_records || historyData.serial_records.length === 0 ? (
```

Insert the stat row so it appears ABOVE the empty-state / table branch. Replace the two
lines above with:

```jsx
              {detailTab === 'serials' && !historyLoading && historyData && (
                <div className="invd-panel">
                  <div className="invd-move-stats">
                    <div className="invd-move-stat invd-move-in">
                      <span className="invd-move-label">Stock In</span>
                      <span className="invd-move-value">{historyData.summary.stock_in ?? 0}</span>
                    </div>
                    <div className="invd-move-stat invd-move-out">
                      <span className="invd-move-label">Stock Out</span>
                      <span className="invd-move-value">{historyData.summary.stock_out ?? 0}</span>
                    </div>
                    <div className="invd-move-stat invd-move-transfer">
                      <span className="invd-move-label">Transfers</span>
                      <span className="invd-move-value">{historyData.summary.transferred ?? 0}</span>
                    </div>
                  </div>
                  {!historyData.serial_records || historyData.serial_records.length === 0 ? (
```

(The closing `</div>` for `invd-panel` at the end of this block is unchanged.)

- [ ] **Step 2: Add the stat-row styles**

In `frontend/src/admin/styles/inventory_management.css`, locate:

```css
.invd-pill-green  { background: #dcfce7; color: #15803d; }
.invd-pill-orange { background: #ffedd5; color: #c2410c; }
.invd-pill-gray   { background: #f3f4f6; color: #6b7280; }
```

Insert AFTER that block:

```css
/* Movement stat row (Serial Numbers tab) */
.invd-move-stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  margin-bottom: 16px;
}
.invd-move-stat {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 10px 14px;
  border-radius: 10px;
  border: 1px solid #f3f4f6;
  background: #fafafa;
}
.invd-move-label {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: #6b7280;
}
.invd-move-value {
  font-size: 20px;
  font-weight: 800;
  color: #111827;
}
.invd-move-in       { border-color: #bbf7d0; background: #f0fdf4; }
.invd-move-in    .invd-move-value    { color: #15803d; }
.invd-move-out      { border-color: #fed7aa; background: #fff7ed; }
.invd-move-out   .invd-move-value    { color: #c2410c; }
.invd-move-transfer { border-color: #bfdbfe; background: #eff6ff; }
.invd-move-transfer .invd-move-value { color: #1d4ed8; }
```

- [ ] **Step 3: Build the frontend to verify it compiles**

Run:
```bash
cd frontend && npx eslint src/admin/pages/InventoryManagement.jsx
```
Expected: no new errors for the edited region (pre-existing warnings unrelated to this change are acceptable).

- [ ] **Step 4: Manual verification**

Open the app, go to Inventory, open the detail modal for (a) an appliance product and
(b) a consumable product, click the Serial Numbers tab. Expected: a three-cell stat row
shows Stock In / Stock Out / Transfers above the serials table. Confirm that a product
which was transferred (not sold) shows its units under **Transfers**, and a product sold to
a customer shows under **Stock Out** — the two do not overlap.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/pages/InventoryManagement.jsx frontend/src/admin/styles/inventory_management.css
git commit -m "feat(inventory): show stock-in/out/transfers stat row on Serial Numbers tab"
```

---

## Self-Review Notes

- **Spec coverage:** Stock In (Task 1 Step 4), Stock Out sourced from `issuance_details`
  (Task 1 Step 3), Transfers sourced from `transfer_details` (Task 1 Step 3), sold-vs-
  transferred separation proven (Task 1 test), frontend stat row (Task 2), inventory-tab-
  only scope (no transfer-page changes) — all covered.
- **Backward compatibility:** existing `summary` keys (`total_pieces_received`,
  `serial_count`, `serial_records`) are untouched; only additive fields.
- **Consistency:** field names `stock_in` / `stock_out` / `transferred` are identical in the
  backend response (Task 1 Step 4) and the frontend reads (Task 2 Step 1).
