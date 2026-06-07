# Transfer Module Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consolidate the two divergent transfer systems into one correct request→approve→IST→dispatch→receive flow, with all inventory and serial movement owned by a single shared `TransferService`, plus scan-verified receiving, a printable receipt, approval-time stock visibility, location worklists/alerts, and an admin-only direct override.

**Architecture:** A new `App\Services\TransferService` owns every inventory and serial mutation (`dispatch`, `recordReceiveScan`, `removeReceiveScan`, `receive`, `reverse`), each wrapped in a DB transaction with `lockForUpdate()` on the touched `inventory` rows. `ISTController`, `TransferController` (admin override), and `TransferRequestController` become thin callers. Statuses are resolved by name (category `transfer`); a migration guarantees `In Transit` and `Received` exist. Tests run against the cloned MySQL `airkingV5_test` DB with `DatabaseTransactions` (migrations are MySQL-only, so SQLite cannot be used).

**Tech Stack:** Laravel 11 (PHP 8), MariaDB/MySQL via XAMPP, React 18 frontend, PHPUnit, DomPDF (`barryvdh/laravel-dompdf`) for receipts.

**Spec:** `docs/superpowers/specs/2026-06-07-transfer-module-redesign-design.md`

---

## Conventions used throughout this plan

- **Inventory table** `inventory` (model `App\Models\Inventory`): columns `quantity_on_hand`, `available_quantity` (both `integer`). Both move in lockstep, always.
- **Serial table** `item_serial` (model `App\Models\ItemSerial`, PK `serial_id`): relevant columns `product_id`, `location_id`, `serial_number`, `serial_type`, `transfer_id`.
  - `serial_type = 'received'` → unit available at its `location_id`.
  - `serial_type = 'transferred'` → unit in transit (dispatched, not yet received).
- **Serialized?** A product is serialized when `products.product_type === 'appliance'`.
- **Statuses** live in `status_lookup` with `status_category = 'transfer'`: `Pending`, `Approved`, `Rejected`, `In Transit`, `Received`.
- **Request vs IST:** a `transfers` row with `transfer_request_id IS NULL` is a *request*; `IS NOT NULL` is an *IST* linked to its request.

---

## File Structure

**Backend — create:**
- `back-end/app/Services/TransferService.php` — all inventory/serial movement.
- `back-end/database/migrations/2026_06_07_000001_guarantee_transfer_statuses.php` — idempotent seed of `In Transit` + `Received`.
- `back-end/tests/Feature/Transfer/TransferTestCase.php` — base test case + fixture helpers.
- `back-end/tests/Feature/Transfer/TransferServiceTest.php` — engine unit/feature tests.
- `back-end/tests/Feature/Transfer/TransferFlowTest.php` — controller/auth/flow tests.

**Backend — modify:**
- `back-end/phpunit.xml` — stop forcing SQLite; let `.env.testing` (MySQL) drive.
- `back-end/app/Http/Controllers/Api/ISTController.php` — delegate dispatch/receive to service; add receive-scan endpoints; fix premature serial moves; standardize serial_type.
- `back-end/app/Http/Controllers/Api/TransferRequestController.php` — `available_at_source`/`shortfall` on `show`; race-safe number.
- `back-end/app/Http/Controllers/Api/TransferController.php` — rewrite to admin-only direct override via service.
- `back-end/app/Http/Controllers/Api/DashboardController.php` — `awaiting_fulfillment` / `awaiting_receipt` stats + alert entries.
- `back-end/routes/api.php` — new receive-scan routes; tighten `/transfers` to admin-only.
- `back-end/resources/views/ist/receipt.blade.php` — ensure receipt shows requested/shipped/received, serials, who/when, discrepancies.

**Frontend — modify:**
- `frontend/src/admin/services/api.js` — `istAPI.receiveScan` / `removeReceiveScan` / `receiveScanStatus`; admin-only `transfersAPI` notes.
- `frontend/src/admin/pages/ISTReceivePage.jsx` — scan/manual receive loop + confirm + receipt link.
- `frontend/src/admin/pages/ISTManagementPage.jsx` — source "to fulfill" and dest "to receive" worklist filters.
- `frontend/src/admin/pages/TransferRequestPage.jsx` (and/or `ApprovalQueue.jsx`) — render available-at-source + shortfall.
- `frontend/src/admin/components/Sidebar.jsx` — badges from dashboard stats; gate `StockTransferManagement` to admin.

---

## Task 1: Point the test harness at the MySQL test DB

**Files:**
- Modify: `back-end/phpunit.xml`
- Create: `back-end/tests/Feature/Transfer/TransferTestCase.php`

- [ ] **Step 1: Remove the SQLite override in `phpunit.xml`**

In `back-end/phpunit.xml`, inside `<php>`, delete these two lines so the MySQL config from `.env.testing` (`APP_ENV=testing`) takes effect:

```xml
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
```

Leave the rest of the `<php>` block unchanged.

- [ ] **Step 2: Create the base test case with fixture helpers**

Create `back-end/tests/Feature/Transfer/TransferTestCase.php`:

```php
<?php

namespace Tests\Feature\Transfer;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Location;
use App\Models\Product;
use App\Models\Inventory;
use App\Models\ItemSerial;
use App\Models\Transfer;
use App\Models\User;

abstract class TransferTestCase extends TestCase
{
    use DatabaseTransactions;

    protected function statusId(string $name): int
    {
        return (int) DB::table('status_lookup')
            ->where('status_category', 'transfer')
            ->where('status_name', $name)
            ->value('status_id');
    }

    protected function makeLocation(string $name): Location
    {
        return Location::create([
            'location_name' => $name,
            'location_type' => 'warehouse',
        ]);
    }

    protected function makeUser(): User
    {
        return User::create([
            'username'   => 'tester_' . uniqid(),
            'first_name' => 'Test',
            'last_name'  => 'User',
            'email'      => uniqid() . '@example.test',
            'password'   => bcrypt('secret'),
        ]);
    }

    protected function makeConsumable(): Product
    {
        return Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'Test Consumable',
            'product_type' => 'consumable',
        ]);
    }

    protected function makeAppliance(): Product
    {
        return Product::create([
            'product_code' => 'APP-' . uniqid(),
            'product_name' => 'Test Appliance',
            'product_type' => 'appliance',
        ]);
    }

    protected function setStock(int $locationId, int $productId, int $qty): Inventory
    {
        return Inventory::create([
            'location_id'        => $locationId,
            'product_id'         => $productId,
            'quantity_on_hand'   => $qty,
            'available_quantity' => $qty,
            'reorder_level'      => 0,
        ]);
    }

    /** Create $count 'received' serials for an appliance at a location. Returns serial_number strings. */
    protected function makeSerials(int $locationId, int $productId, int $count): array
    {
        $numbers = [];
        for ($i = 0; $i < $count; $i++) {
            $sn = 'SN-' . uniqid();
            ItemSerial::create([
                'product_id'    => $productId,
                'location_id'   => $locationId,
                'serial_number' => $sn,
                'serial_type'   => 'received',
            ]);
            $numbers[] = $sn;
        }
        return $numbers;
    }

    /** Create an IST (transfer_request_id set) at status Approved with given lines. */
    protected function makeApprovedIst(int $from, int $to, array $lines): Transfer
    {
        $req = Transfer::create([
            'transfer_request_id' => null,
            'from_location_id'    => $from,
            'to_location_id'      => $to,
            'transfer_number'     => 'TR-REQ-TEST-' . uniqid(),
            'transfer_date'       => now(),
            'status_id'           => $this->statusId('Approved'),
        ]);

        $ist = Transfer::create([
            'transfer_request_id' => $req->transfer_id,
            'from_location_id'    => $from,
            'to_location_id'      => $to,
            'transfer_number'     => 'IST-TEST-' . uniqid(),
            'transfer_date'       => now(),
            'status_id'           => $this->statusId('Approved'),
        ]);

        foreach ($lines as $line) {
            $ist->details()->create([
                'product_id'           => $line['product_id'],
                'quantity_requested'   => $line['quantity_requested'],
                'quantity_scanned'     => $line['quantity_scanned'] ?? 0,
                'quantity_transferred' => 0,
                'quantity_received'    => 0,
            ]);
        }

        return $ist->fresh('details');
    }
}
```

> Note: if any `create()` above fails because a column is `NOT NULL` without a default in `airkingV5_test`, add the missing column with a sensible value to that helper — do not switch to SQLite.

- [ ] **Step 3: Add a connectivity smoke test**

Append to `back-end/tests/Feature/Transfer/TransferTestCase.php` a temporary sibling test file `back-end/tests/Feature/Transfer/HarnessSmokeTest.php`:

```php
<?php

namespace Tests\Feature\Transfer;

class HarnessSmokeTest extends TransferTestCase
{
    public function test_connects_to_mysql_test_db_and_resolves_a_status(): void
    {
        $this->assertSame('mysql', \DB::connection()->getDriverName());
    }
}
```

- [ ] **Step 4: Run the smoke test**

Run: `cd back-end && php artisan test --filter=HarnessSmokeTest`
Expected: PASS, driver is `mysql`. If it fails with "Unknown database 'airkingV5_test'", create it first:
`/c/XAMPP1/mysql/bin/mysql.exe -u root -h 127.0.0.1 -P 3306 -e "CREATE DATABASE IF NOT EXISTS airkingV5_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"` then load a dump/clone of `airkingV5` into it and re-run.

- [ ] **Step 5: Delete the smoke test and commit**

```bash
rm back-end/tests/Feature/Transfer/HarnessSmokeTest.php
git add back-end/phpunit.xml back-end/tests/Feature/Transfer/TransferTestCase.php
git commit -m "test: point transfer test harness at airkingV5_test MySQL DB"
```

---

## Task 2: Guarantee `In Transit` and `Received` transfer statuses

**Files:**
- Create: `back-end/database/migrations/2026_06_07_000001_guarantee_transfer_statuses.php`
- Test: `back-end/tests/Feature/Transfer/TransferServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Transfer/TransferServiceTest.php`:

```php
<?php

namespace Tests\Feature\Transfer;

use Illuminate\Support\Facades\DB;

class TransferServiceTest extends TransferTestCase
{
    public function test_in_transit_and_received_statuses_exist(): void
    {
        foreach (['In Transit', 'Received'] as $name) {
            $id = DB::table('status_lookup')
                ->where('status_category', 'transfer')
                ->where('status_name', $name)
                ->value('status_id');
            $this->assertNotNull($id, "Status '{$name}' must exist in category 'transfer'");
        }
    }
}
```

- [ ] **Step 2: Run to verify it fails (if statuses absent)**

Run: `cd back-end && php artisan test --filter=test_in_transit_and_received_statuses_exist`
Expected: FAIL if the test DB lacks the rows (the bug we're fixing). If it already passes because the clone has them, still add the migration below so a fresh DB is reproducible.

- [ ] **Step 3: Create the migration**

Create `back-end/database/migrations/2026_06_07_000001_guarantee_transfer_statuses.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['In Transit', 'Received'] as $name) {
            $exists = DB::table('status_lookup')
                ->where('status_name', $name)
                ->where('status_category', 'transfer')
                ->exists();
            if (!$exists) {
                DB::table('status_lookup')->insert([
                    'status_name'     => $name,
                    'status_category' => 'transfer',
                    'is_active'       => 1,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('status_lookup')
            ->where('status_category', 'transfer')
            ->whereIn('status_name', ['In Transit', 'Received'])
            ->delete();
    }
};
```

- [ ] **Step 4: Run the migration against the test DB, then re-run the test**

Run: `cd back-end && php artisan migrate --env=testing --path=database/migrations/2026_06_07_000001_guarantee_transfer_statuses.php`
Then: `php artisan test --filter=test_in_transit_and_received_statuses_exist`
Expected: PASS.

> Also run `php artisan migrate` (default env) so the live/dev DB gets the rows too.

- [ ] **Step 5: Commit**

```bash
git add back-end/database/migrations/2026_06_07_000001_guarantee_transfer_statuses.php back-end/tests/Feature/Transfer/TransferServiceTest.php
git commit -m "feat: migration guaranteeing In Transit and Received transfer statuses"
```

---

## Task 3: `TransferService` skeleton + status resolver

**Files:**
- Create: `back-end/app/Services/TransferService.php`
- Test: `back-end/tests/Feature/Transfer/TransferServiceTest.php`

- [ ] **Step 1: Write the failing test** (append to `TransferServiceTest`)

```php
    public function test_resolves_transfer_status_by_name(): void
    {
        $svc = new \App\Services\TransferService();
        $this->assertSame(
            $this->statusId('In Transit'),
            $svc->statusIdForTest('In Transit')
        );
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd back-end && php artisan test --filter=test_resolves_transfer_status_by_name`
Expected: FAIL with "Class App\Services\TransferService not found".

- [ ] **Step 3: Create the service skeleton**

Create `back-end/app/Services/TransferService.php`:

```php
<?php

namespace App\Services;

use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\Inventory;
use App\Models\ItemSerial;
use App\Models\BarcodeScan;
use App\Models\TblTransferTracking;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransferService
{
    private function statusId(string $name): int
    {
        $id = DB::table('status_lookup')
            ->where('status_category', 'transfer')
            ->where('status_name', $name)
            ->value('status_id');

        if ($id === null) {
            throw new RuntimeException("Transfer status '{$name}' is missing from status_lookup.");
        }
        return (int) $id;
    }

    /** Test seam — exposes the private resolver. */
    public function statusIdForTest(string $name): int
    {
        return $this->statusId($name);
    }

    private function isSerialized(?TransferDetail $detail): bool
    {
        return ($detail?->product?->product_type) === 'appliance';
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `cd back-end && php artisan test --filter=test_resolves_transfer_status_by_name`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/TransferService.php back-end/tests/Feature/Transfer/TransferServiceTest.php
git commit -m "feat: TransferService skeleton with status resolver"
```

---

## Task 4: `dispatch()` — consumables (non-serialized)

**Files:**
- Modify: `back-end/app/Services/TransferService.php`
- Test: `back-end/tests/Feature/Transfer/TransferServiceTest.php`

- [ ] **Step 1: Write the failing test** (append to `TransferServiceTest`)

```php
    public function test_dispatch_consumable_decrements_source_both_columns(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 10);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 5, 'quantity_scanned' => 5],
        ]);

        (new \App\Services\TransferService())->dispatch($ist);

        $src = \App\Models\Inventory::where('location_id', $from->location_id)
            ->where('product_id', $prod->product_id)->first();

        $this->assertSame(5, $src->quantity_on_hand);
        $this->assertSame(5, $src->available_quantity);

        $ist->refresh()->load('details');
        $this->assertSame($this->statusId('In Transit'), (int) $ist->status_id);
        $this->assertSame(5, (int) $ist->details->first()->quantity_transferred);
        $this->assertSame(5, (int) $ist->total_quantity_transferred);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd back-end && php artisan test --filter=test_dispatch_consumable_decrements_source_both_columns`
Expected: FAIL with "Call to undefined method ...::dispatch()".

- [ ] **Step 3: Implement `dispatch()`** (add to `TransferService`)

```php
    /**
     * Approved IST -> In Transit. shipped = quantity_scanned (auto-close remainder).
     * Deducts source quantity_on_hand AND available_quantity for every product type.
     * Serialized scanned serials become 'transferred' (location stays at source).
     */
    public function dispatch(Transfer $ist): Transfer
    {
        if ((int) $ist->status_id !== $this->statusId('Approved')) {
            throw new RuntimeException('Only an approved IST can be dispatched.');
        }

        $ist->load('details.product');
        $totalScanned = (int) $ist->details->sum('quantity_scanned');
        if ($totalScanned < 1) {
            throw new RuntimeException('Scan at least one item before dispatch.');
        }

        return DB::transaction(function () use ($ist) {
            $totalShipped = 0;

            foreach ($ist->details as $detail) {
                $shipped = (int) $detail->quantity_scanned;
                if ($shipped < 1) {
                    continue;
                }

                $src = Inventory::where('location_id', $ist->from_location_id)
                    ->where('product_id', $detail->product_id)
                    ->lockForUpdate()
                    ->first();

                if (!$src || (int) $src->available_quantity < $shipped) {
                    $have = $src ? (int) $src->available_quantity : 0;
                    throw new RuntimeException("Only {$have} available at source for product {$detail->product_id}.");
                }

                $src->quantity_on_hand   = (int) $src->quantity_on_hand - $shipped;
                $src->available_quantity = (int) $src->available_quantity - $shipped;
                $src->save();

                if ($this->isSerialized($detail)) {
                    ItemSerial::where('transfer_id', $ist->transfer_id)
                        ->where('product_id', $detail->product_id)
                        ->update(['serial_type' => 'transferred']);
                }

                $detail->quantity_transferred = $shipped;
                $detail->save();
                $totalShipped += $shipped;
            }

            $ist->status_id = $this->statusId('In Transit');
            $ist->total_quantity_transferred = $totalShipped;
            $ist->save();

            return $ist->fresh('details');
        });
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `cd back-end && php artisan test --filter=test_dispatch_consumable_decrements_source_both_columns`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/TransferService.php back-end/tests/Feature/Transfer/TransferServiceTest.php
git commit -m "feat: TransferService::dispatch for consumables"
```

---

## Task 5: `dispatch()` — serialized appliances

**Files:**
- Test: `back-end/tests/Feature/Transfer/TransferServiceTest.php`

(Implementation already handles serialized lines; this task proves it and locks the behavior.)

- [ ] **Step 1: Write the failing test** (append)

```php
    public function test_dispatch_appliance_flips_serials_without_moving_location(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 3);
        $serials = $this->makeSerials($from->location_id, $prod->product_id, 3);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 3, 'quantity_scanned' => 3],
        ]);

        // Simulate the source scan loop having linked the 3 serials to this IST.
        \App\Models\ItemSerial::whereIn('serial_number', $serials)
            ->update(['transfer_id' => $ist->transfer_id]);

        (new \App\Services\TransferService())->dispatch($ist);

        $rows = \App\Models\ItemSerial::whereIn('serial_number', $serials)->get();
        foreach ($rows as $row) {
            $this->assertSame('transferred', $row->serial_type);
            $this->assertSame($from->location_id, (int) $row->location_id, 'serial must NOT move to destination at dispatch');
        }

        $src = \App\Models\Inventory::where('location_id', $from->location_id)
            ->where('product_id', $prod->product_id)->first();
        $this->assertSame(0, $src->quantity_on_hand);
        $this->assertSame(0, $src->available_quantity);
    }
```

- [ ] **Step 2: Run to verify behavior**

Run: `cd back-end && php artisan test --filter=test_dispatch_appliance_flips_serials_without_moving_location`
Expected: PASS (implementation from Task 4 already covers it). If it fails, fix `dispatch()` per the assertions before continuing.

- [ ] **Step 3: Commit**

```bash
git add back-end/tests/Feature/Transfer/TransferServiceTest.php
git commit -m "test: dispatch flips appliance serials in place"
```

---

## Task 6: `dispatch()` — partial auto-close + guards

**Files:**
- Test: `back-end/tests/Feature/Transfer/TransferServiceTest.php`

- [ ] **Step 1: Write the failing tests** (append)

```php
    public function test_dispatch_partial_ships_only_scanned_and_auto_closes(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 10);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 5, 'quantity_scanned' => 3],
        ]);

        (new \App\Services\TransferService())->dispatch($ist);

        $ist->refresh()->load('details');
        $this->assertSame(3, (int) $ist->details->first()->quantity_transferred);
        $this->assertSame(3, (int) $ist->total_quantity_transferred);
        $this->assertSame($this->statusId('In Transit'), (int) $ist->status_id);

        $src = \App\Models\Inventory::where('location_id', $from->location_id)
            ->where('product_id', $prod->product_id)->first();
        $this->assertSame(7, $src->quantity_on_hand);
    }

    public function test_dispatch_rejects_when_nothing_scanned(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 10);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 5, 'quantity_scanned' => 0],
        ]);

        $this->expectException(\RuntimeException::class);
        (new \App\Services\TransferService())->dispatch($ist);
    }
```

- [ ] **Step 2: Run to verify they pass**

Run: `cd back-end && php artisan test --filter=test_dispatch_partial_ships_only_scanned_and_auto_closes`
Then: `php artisan test --filter=test_dispatch_rejects_when_nothing_scanned`
Expected: both PASS (covered by Task 4 implementation). Fix `dispatch()` if not.

- [ ] **Step 3: Commit**

```bash
git add back-end/tests/Feature/Transfer/TransferServiceTest.php
git commit -m "test: dispatch partial auto-close and guards"
```

---

## Task 7: `recordReceiveScan()` / `removeReceiveScan()`

**Files:**
- Modify: `back-end/app/Services/TransferService.php`
- Test: `back-end/tests/Feature/Transfer/TransferServiceTest.php`

- [ ] **Step 1: Write the failing tests** (append)

```php
    /** Helper: build an In-Transit IST with one dispatched consumable line. */
    private function inTransitConsumable(int $qty = 3): array
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 10);
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => $qty, 'quantity_scanned' => $qty],
        ]);
        (new \App\Services\TransferService())->dispatch($ist);
        return [$from, $to, $prod, $ist->fresh('details')];
    }

    public function test_receive_scan_increments_received_and_logs_scan(): void
    {
        [$from, $to, $prod, $ist] = $this->inTransitConsumable(3);
        $detail = $ist->details->first();
        $user = $this->makeUser();

        $svc = new \App\Services\TransferService();
        $svc->recordReceiveScan($ist, 'BC-1', (int) $detail->transfer_detail_id, $user->user_id, 1);

        $detail->refresh();
        $this->assertSame(1, (int) $detail->quantity_received);

        $this->assertSame(1, \App\Models\BarcodeScan::where('transfer_id', $ist->transfer_id)
            ->where('scan_mode', 'receive')->count());
    }

    public function test_receive_scan_rejects_serial_not_on_shipment(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 2);
        $serials = $this->makeSerials($from->location_id, $prod->product_id, 2);
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 2, 'quantity_scanned' => 2],
        ]);
        \App\Models\ItemSerial::whereIn('serial_number', $serials)
            ->update(['transfer_id' => $ist->transfer_id]);
        $svc = new \App\Services\TransferService();
        $svc->dispatch($ist);
        $ist->refresh()->load('details');
        $detail = $ist->details->first();
        $user = $this->makeUser();

        $this->expectException(\RuntimeException::class);
        $svc->recordReceiveScan($ist, 'SN-NOT-REAL', (int) $detail->transfer_detail_id, $user->user_id, 1);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `cd back-end && php artisan test --filter=test_receive_scan_increments_received_and_logs_scan`
Expected: FAIL with "undefined method ...::recordReceiveScan()".

- [ ] **Step 3: Implement the receive-scan loop** (add to `TransferService`)

```php
    /**
     * Record one received unit (scan or manual). Increments the line's
     * quantity_received and writes a barcode_scans row (scan_mode 'receive').
     * Serialized scans must match an in-transit serial of THIS transfer.
     */
    public function recordReceiveScan(Transfer $ist, string $barcode, int $transferDetailId, int $userId, int $qty = 1): array
    {
        if ((int) $ist->status_id !== $this->statusId('In Transit')) {
            throw new RuntimeException('Only an In Transit IST can be received.');
        }

        $detail = TransferDetail::where('transfer_detail_id', $transferDetailId)
            ->where('transfer_id', $ist->transfer_id)
            ->with('product')
            ->first();
        if (!$detail) {
            throw new RuntimeException('Line item does not belong to this transfer.');
        }

        $serialized = $this->isSerialized($detail);
        $step = $serialized ? 1 : max(1, $qty);

        return DB::transaction(function () use ($ist, $detail, $barcode, $userId, $step, $serialized) {
            $alreadyScanned = BarcodeScan::where('transfer_id', $ist->transfer_id)
                ->where('scan_mode', 'receive')
                ->where('barcode', $barcode)
                ->exists();
            if ($alreadyScanned) {
                throw new RuntimeException("Barcode '{$barcode}' was already received on this transfer.");
            }

            if ($serialized) {
                $match = ItemSerial::where('transfer_id', $ist->transfer_id)
                    ->where('product_id', $detail->product_id)
                    ->where('serial_number', $barcode)
                    ->where('serial_type', 'transferred')
                    ->exists();
                if (!$match) {
                    throw new RuntimeException('This serial is not part of this shipment.');
                }
            }

            $shipped = (int) $detail->quantity_transferred;
            if ((int) $detail->quantity_received + $step > $shipped) {
                $remaining = $shipped - (int) $detail->quantity_received;
                throw new RuntimeException("Only {$remaining} more unit(s) can be received on this line.");
            }

            $scan = BarcodeScan::create([
                'user_id'     => $userId,
                'product_id'  => $detail->product_id,
                'transfer_id' => $ist->transfer_id,
                'barcode'     => $barcode,
                'scan_mode'   => 'receive',
                'scanned_at'  => now(),
            ]);

            $detail->quantity_received = (int) $detail->quantity_received + $step;
            $detail->save();

            return [
                'scan_id'           => $scan->id,
                'quantity_received' => (int) $detail->quantity_received,
                'quantity_shipped'  => $shipped,
                'remaining'         => $shipped - (int) $detail->quantity_received,
            ];
        });
    }

    /** Undo a received-scan before confirmation. */
    public function removeReceiveScan(Transfer $ist, int $scanId): void
    {
        if ((int) $ist->status_id !== $this->statusId('In Transit')) {
            throw new RuntimeException('Only an In Transit IST can be edited.');
        }

        DB::transaction(function () use ($ist, $scanId) {
            $scan = BarcodeScan::where('id', $scanId)
                ->where('transfer_id', $ist->transfer_id)
                ->where('scan_mode', 'receive')
                ->first();
            if (!$scan) {
                throw new RuntimeException('Receive scan not found.');
            }

            $detail = TransferDetail::where('transfer_id', $ist->transfer_id)
                ->where('product_id', $scan->product_id)
                ->first();
            if ($detail && (int) $detail->quantity_received > 0) {
                $detail->quantity_received = (int) $detail->quantity_received - 1;
                $detail->save();
            }

            $scan->delete();
        });
    }
```

- [ ] **Step 4: Run to verify they pass**

Run: `cd back-end && php artisan test --filter=test_receive_scan_increments_received_and_logs_scan`
Then: `php artisan test --filter=test_receive_scan_rejects_serial_not_on_shipment`
Expected: both PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/TransferService.php back-end/tests/Feature/Transfer/TransferServiceTest.php
git commit -m "feat: TransferService receive-scan record/remove"
```

---

## Task 8: `receive()` — confirm, increment destination, activate serials, discrepancy

**Files:**
- Modify: `back-end/app/Services/TransferService.php`
- Test: `back-end/tests/Feature/Transfer/TransferServiceTest.php`

- [ ] **Step 1: Write the failing tests** (append)

```php
    public function test_receive_confirm_increments_destination_and_closes(): void
    {
        [$from, $to, $prod, $ist] = $this->inTransitConsumable(3);
        $detail = $ist->details->first();
        $user = $this->makeUser();
        $svc = new \App\Services\TransferService();

        $svc->recordReceiveScan($ist, 'BC-1', (int) $detail->transfer_detail_id, $user->user_id, 3);
        $svc->receive($ist->fresh('details'), $user->user_id);

        $dst = \App\Models\Inventory::where('location_id', $to->location_id)
            ->where('product_id', $prod->product_id)->first();
        $this->assertSame(3, $dst->quantity_on_hand);
        $this->assertSame(3, $dst->available_quantity);

        $ist->refresh();
        $this->assertSame($this->statusId('Received'), (int) $ist->status_id);
        $this->assertSame(3, (int) $ist->total_quantity_received);
    }

    public function test_receive_appliance_activates_serials_at_destination(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 2);
        $serials = $this->makeSerials($from->location_id, $prod->product_id, 2);
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 2, 'quantity_scanned' => 2],
        ]);
        \App\Models\ItemSerial::whereIn('serial_number', $serials)
            ->update(['transfer_id' => $ist->transfer_id]);
        $user = $this->makeUser();
        $svc = new \App\Services\TransferService();
        $svc->dispatch($ist);
        $ist->refresh()->load('details');
        $detail = $ist->details->first();

        foreach ($serials as $sn) {
            $svc->recordReceiveScan($ist, $sn, (int) $detail->transfer_detail_id, $user->user_id, 1);
        }
        $svc->receive($ist->fresh('details'), $user->user_id);

        $rows = \App\Models\ItemSerial::whereIn('serial_number', $serials)->get();
        foreach ($rows as $row) {
            $this->assertSame('received', $row->serial_type);
            $this->assertSame($to->location_id, (int) $row->location_id);
        }
        $dst = \App\Models\Inventory::where('location_id', $to->location_id)
            ->where('product_id', $prod->product_id)->first();
        $this->assertSame(2, $dst->quantity_on_hand);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `cd back-end && php artisan test --filter=test_receive_confirm_increments_destination_and_closes`
Expected: FAIL with "undefined method ...::receive()".

- [ ] **Step 3: Implement `receive()`** (add to `TransferService`)

```php
    /**
     * In Transit -> Received. Finalizes whatever was recorded via recordReceiveScan.
     * Increments destination inventory (both columns), activates matched serials
     * at the destination, logs any shipped != received discrepancy.
     */
    public function receive(Transfer $ist, int $userId): Transfer
    {
        if ((int) $ist->status_id !== $this->statusId('In Transit')) {
            throw new RuntimeException('Only an In Transit IST can be received.');
        }

        $ist->load('details.product');

        return DB::transaction(function () use ($ist, $userId) {
            $totalReceived = 0;
            $discrepancies = [];

            foreach ($ist->details as $detail) {
                $received = (int) $detail->quantity_received;
                $shipped  = (int) $detail->quantity_transferred;

                if ($received > 0) {
                    $dst = Inventory::lockForUpdate()->firstOrNew([
                        'location_id' => $ist->to_location_id,
                        'product_id'  => $detail->product_id,
                    ]);
                    $dst->quantity_on_hand   = (int) ($dst->quantity_on_hand ?? 0) + $received;
                    $dst->available_quantity = (int) ($dst->available_quantity ?? 0) + $received;
                    $dst->reorder_level      = (int) ($dst->reorder_level ?? 0);
                    $dst->save();

                    if ($this->isSerialized($detail)) {
                        $scanned = BarcodeScan::where('transfer_id', $ist->transfer_id)
                            ->where('scan_mode', 'receive')
                            ->where('product_id', $detail->product_id)
                            ->pluck('barcode');
                        ItemSerial::where('transfer_id', $ist->transfer_id)
                            ->where('product_id', $detail->product_id)
                            ->whereIn('serial_number', $scanned)
                            ->update([
                                'serial_type' => 'received',
                                'location_id' => $ist->to_location_id,
                            ]);
                    }
                }

                if ($received !== $shipped) {
                    $discrepancies[] = "product {$detail->product_id}: shipped {$shipped}, received {$received}";
                }

                $totalReceived += $received;
            }

            $ist->status_id = $this->statusId('Received');
            $ist->received_by = $userId;
            $ist->total_quantity_received = $totalReceived;
            $ist->save();

            $note = "Items received — {$totalReceived} unit(s)";
            if (!empty($discrepancies)) {
                $note .= '. Discrepancies: ' . implode('; ', $discrepancies);
            }
            TblTransferTracking::create([
                'transfer_id' => $ist->transfer_id,
                'location_id' => $ist->to_location_id,
                'status_note' => $note,
                'recorded_by' => $userId,
                'recorded_at' => now(),
            ]);

            return $ist->fresh('details');
        });
    }
```

- [ ] **Step 4: Run to verify they pass**

Run: `cd back-end && php artisan test --filter=test_receive_confirm_increments_destination_and_closes`
Then: `php artisan test --filter=test_receive_appliance_activates_serials_at_destination`
Expected: both PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/TransferService.php back-end/tests/Feature/Transfer/TransferServiceTest.php
git commit -m "feat: TransferService::receive with serial activation and discrepancy logging"
```

---

## Task 9: `reverse()` — state-aware undo + round-trip invariant

**Files:**
- Modify: `back-end/app/Services/TransferService.php`
- Test: `back-end/tests/Feature/Transfer/TransferServiceTest.php`

- [ ] **Step 1: Write the failing tests** (append)

```php
    public function test_reverse_from_in_transit_restores_source(): void
    {
        [$from, $to, $prod, $ist] = $this->inTransitConsumable(3);
        (new \App\Services\TransferService())->reverse($ist->fresh('details'));

        $src = \App\Models\Inventory::where('location_id', $from->location_id)
            ->where('product_id', $prod->product_id)->first();
        $this->assertSame(10, $src->quantity_on_hand);
        $this->assertSame(10, $src->available_quantity);
    }

    public function test_round_trip_conserves_total_stock(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 10);
        $this->setStock($to->location_id, $prod->product_id, 0);
        $user = $this->makeUser();
        $svc = new \App\Services\TransferService();

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 4, 'quantity_scanned' => 4],
        ]);
        $svc->dispatch($ist);
        $ist->refresh()->load('details');
        $svc->recordReceiveScan($ist, 'BC-RT', (int) $ist->details->first()->transfer_detail_id, $user->user_id, 4);
        $svc->receive($ist->fresh('details'), $user->user_id);

        $srcQty = (int) \App\Models\Inventory::where('location_id', $from->location_id)
            ->where('product_id', $prod->product_id)->value('quantity_on_hand');
        $dstQty = (int) \App\Models\Inventory::where('location_id', $to->location_id)
            ->where('product_id', $prod->product_id)->value('quantity_on_hand');

        $this->assertSame(6, $srcQty);
        $this->assertSame(4, $dstQty);
        $this->assertSame(10, $srcQty + $dstQty);
    }
```

- [ ] **Step 2: Run to verify the reverse test fails**

Run: `cd back-end && php artisan test --filter=test_reverse_from_in_transit_restores_source`
Expected: FAIL with "undefined method ...::reverse()".

- [ ] **Step 3: Implement `reverse()`** (add to `TransferService`)

```php
    /**
     * State-aware undo of an IST's inventory/serial movement.
     * - In Transit: restore source, reactivate serials at source, clear transfer link.
     * - Received: also pull received qty out of destination and move serials back.
     */
    public function reverse(Transfer $ist): void
    {
        $ist->load('details.product');
        $inTransit = $this->statusId('In Transit');
        $received  = $this->statusId('Received');
        $state = (int) $ist->status_id;

        if (!in_array($state, [$inTransit, $received], true)) {
            return; // nothing moved yet (Pending/Approved/Rejected)
        }

        DB::transaction(function () use ($ist, $state, $received) {
            foreach ($ist->details as $detail) {
                $shipped  = (int) $detail->quantity_transferred;
                $recv     = (int) $detail->quantity_received;
                if ($shipped < 1) {
                    continue;
                }

                // Pull back out of destination if it was received.
                if ($state === $received && $recv > 0) {
                    $dst = Inventory::where('location_id', $ist->to_location_id)
                        ->where('product_id', $detail->product_id)
                        ->lockForUpdate()->first();
                    if ($dst) {
                        $dst->quantity_on_hand   = max(0, (int) $dst->quantity_on_hand - $recv);
                        $dst->available_quantity = max(0, (int) $dst->available_quantity - $recv);
                        $dst->save();
                    }
                }

                // Restore source by what shipped.
                $src = Inventory::lockForUpdate()->firstOrNew([
                    'location_id' => $ist->from_location_id,
                    'product_id'  => $detail->product_id,
                ]);
                $src->quantity_on_hand   = (int) ($src->quantity_on_hand ?? 0) + $shipped;
                $src->available_quantity = (int) ($src->available_quantity ?? 0) + $shipped;
                $src->reorder_level      = (int) ($src->reorder_level ?? 0);
                $src->save();

                if ($this->isSerialized($detail)) {
                    ItemSerial::where('transfer_id', $ist->transfer_id)
                        ->where('product_id', $detail->product_id)
                        ->update([
                            'serial_type' => 'received',
                            'location_id' => $ist->from_location_id,
                            'transfer_id' => null,
                        ]);
                }
            }
        });
    }
```

- [ ] **Step 4: Run to verify both pass**

Run: `cd back-end && php artisan test --filter=test_reverse_from_in_transit_restores_source`
Then: `php artisan test --filter=test_round_trip_conserves_total_stock`
Expected: both PASS.

- [ ] **Step 5: Run the whole service suite + commit**

Run: `cd back-end && php artisan test --filter=TransferServiceTest`
Expected: all green.

```bash
git add back-end/app/Services/TransferService.php back-end/tests/Feature/Transfer/TransferServiceTest.php
git commit -m "feat: TransferService::reverse and round-trip conservation test"
```

---

## Task 10: Wire `ISTController` to the service + add receive-scan endpoints

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/ISTController.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/Transfer/TransferFlowTest.php`

- [ ] **Step 1: Write the failing flow test**

Create `back-end/tests/Feature/Transfer/TransferFlowTest.php`:

```php
<?php

namespace Tests\Feature\Transfer;

use App\Models\Role;
use App\Models\User;

class TransferFlowTest extends TransferTestCase
{
    private function adminUser(): User
    {
        $roleId = \DB::table('roles')->where('role_name', 'admin')->value('role_id');
        $u = $this->makeUser();
        $u->role_id = $roleId;
        $u->save();
        return $u;
    }

    public function test_confirm_dispatch_endpoint_moves_to_in_transit(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 10);
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 5, 'quantity_scanned' => 5],
        ]);

        $resp = $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson("/api/ist/{$ist->transfer_id}/confirm-dispatch");

        $resp->assertOk();
        $ist->refresh();
        $this->assertSame($this->statusId('In Transit'), (int) $ist->status_id);
    }
}
```

- [ ] **Step 2: Run to verify it fails or errors**

Run: `cd back-end && php artisan test --filter=test_confirm_dispatch_endpoint_moves_to_in_transit`
Expected: FAIL (current `confirmDispatch` uses old inline logic / wrong serial handling, or assertion mismatch). Confirm the failure, then refactor.

- [ ] **Step 3: Refactor `confirmDispatch` to use the service**

In `back-end/app/Http/Controllers/Api/ISTController.php`, replace the body of `confirmDispatch` with a thin delegate. Add `use App\Services\TransferService;` at the top, then:

```php
    public function confirmDispatch(Request $request, $transferId, TransferService $service)
    {
        try {
            $ist = Transfer::with(['details.product'])
                ->whereNotNull('transfer_request_id')
                ->findOrFail($transferId);

            $result = $service->dispatch($ist);

            return $this->success([
                'transfer_number'  => $result->transfer_number,
                'status'           => 'In Transit',
                'total_dispatched' => (int) $result->total_quantity_transferred,
            ], 'IST dispatched successfully');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('Failed to confirm dispatch: ' . $e->getMessage(), 500);
        }
    }
```

- [ ] **Step 4: Refactor `receiveStore` to use the service and add receive-scan actions**

In the same controller, replace the body of `receiveStore` with:

```php
    public function receiveStore(Request $request, $id, TransferService $service)
    {
        try {
            $ist = Transfer::with(['details.product'])
                ->whereNotNull('transfer_request_id')
                ->findOrFail($id);

            $user      = $request->user();
            $role      = $user->role?->role_name ?? '';
            $isAdmin   = in_array($role, ['admin', 'inventory_analyst'], true);
            $userLocId = $user->enforced_location_id ?? $user->location_id;
            if (!$isAdmin && $userLocId && (int) $userLocId !== (int) $ist->to_location_id) {
                return $this->error('You are not authorized to receive items at this destination.', 403);
            }

            $result = $service->receive($ist, $user->user_id);

            return $this->success([
                'transfer_number'         => $result->transfer_number,
                'status'                  => 'Received',
                'total_quantity_received' => (int) $result->total_quantity_received,
            ], 'Transfer received successfully');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('Failed to confirm receipt: ' . $e->getMessage(), 500);
        }
    }

    public function receiveScan(Request $request, $transferId, TransferService $service)
    {
        try {
            $validated = $request->validate([
                'barcode'            => 'required|string|max:255',
                'transfer_detail_id' => 'required|integer',
                'quantity'           => 'sometimes|integer|min:1',
            ]);

            $ist = Transfer::whereNotNull('transfer_request_id')->findOrFail($transferId);

            $user      = $request->user();
            $role      = $user->role?->role_name ?? '';
            $isAdmin   = in_array($role, ['admin', 'inventory_analyst'], true);
            $userLocId = $user->enforced_location_id ?? $user->location_id;
            if (!$isAdmin && $userLocId && (int) $userLocId !== (int) $ist->to_location_id) {
                return $this->error('You are not authorized to receive items at this destination.', 403);
            }

            $result = $service->recordReceiveScan(
                $ist,
                $validated['barcode'],
                (int) $validated['transfer_detail_id'],
                $user->user_id,
                (int) ($validated['quantity'] ?? 1)
            );

            return $this->success($result, 'Receive scan recorded');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('Receive scan failed: ' . $e->getMessage(), 500);
        }
    }

    public function removeReceiveScan(Request $request, $transferId, $scanId, TransferService $service)
    {
        try {
            $ist = Transfer::whereNotNull('transfer_request_id')->findOrFail($transferId);
            $service->removeReceiveScan($ist, (int) $scanId);
            return $this->success(null, 'Receive scan removed');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('Failed to remove receive scan: ' . $e->getMessage(), 500);
        }
    }
```

- [ ] **Step 5: Fix the source-scan to stop premature serial moves**

In `ISTController::scan`, the serialized branch currently sets `serial_type => 'transferred'`. Change it to only **link** the serial to the transfer (the authoritative `transferred` flip now happens in `dispatch()`):

```php
            if ($isSerialized) {
                \App\Models\ItemSerial::where('serial_number', $validated['barcode'])
                    ->update(['transfer_id' => (int) $transferId]);
            }
```

And in `ISTController::removeScan`, change the serial reset to clear the link without assuming a prior `active` value:

```php
            \App\Models\ItemSerial::where('transfer_id', $transferId)
                ->where('serial_number', $scan->barcode)
                ->update(['transfer_id' => null]);
```

- [ ] **Step 6: Add the receive-scan routes**

In `back-end/routes/api.php`, inside the existing IST `role:admin,warehouse_personnel,inventory_analyst` group (the one containing `confirm-dispatch`), add:

```php
        Route::post('/ist/{transferId}/receive-scan', [ISTController::class, 'receiveScan']);
        Route::delete('/ist/{transferId}/receive-scan/{scanId}', [ISTController::class, 'removeReceiveScan']);
```

Then widen the `receiveStore`/`receiveScan` roles to include `branch_manager` (per spec). Locate `Route::post('/ist/{id}/receive', [ISTController::class, 'receiveStore']);` and move the three receive routes into a group:

```php
    Route::middleware('role:admin,inventory_analyst,branch_manager,warehouse_personnel')->group(function () {
        Route::post('/ist/{id}/receive', [ISTController::class, 'receiveStore']);
        Route::post('/ist/{transferId}/receive-scan', [ISTController::class, 'receiveScan']);
        Route::delete('/ist/{transferId}/receive-scan/{scanId}', [ISTController::class, 'removeReceiveScan']);
    });
```

(Keep `scan`, `removeScan`, `confirm-dispatch`, `store` in their existing `admin,warehouse_personnel,inventory_analyst` group, and add `branch_manager` there too so source dispatch matches the spec roles.)

- [ ] **Step 7: Run the flow test**

Run: `cd back-end && php artisan test --filter=test_confirm_dispatch_endpoint_moves_to_in_transit`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Http/Controllers/Api/ISTController.php back-end/routes/api.php back-end/tests/Feature/Transfer/TransferFlowTest.php
git commit -m "refactor: ISTController delegates to TransferService; add receive-scan endpoints"
```

---

## Task 11: Approval stock visibility (`available_at_source` + `shortfall`)

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/TransferRequestController.php`
- Modify: `frontend/src/admin/pages/TransferRequestPage.jsx`
- Test: `back-end/tests/Feature/Transfer/TransferFlowTest.php`

- [ ] **Step 1: Write the failing test** (append to `TransferFlowTest`)

```php
    public function test_request_show_exposes_available_at_source_and_shortfall(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 3);

        $req = \App\Models\Transfer::create([
            'transfer_request_id' => null,
            'from_location_id'    => $from->location_id,
            'to_location_id'      => $to->location_id,
            'transfer_number'     => 'TR-REQ-SHOW-' . uniqid(),
            'transfer_date'       => now(),
            'status_id'           => $this->statusId('Pending'),
        ]);
        $req->details()->create([
            'product_id'         => $prod->product_id,
            'quantity_requested' => 5,
            'quantity_transferred' => 0,
            'quantity_scanned'   => 0,
            'quantity_received'  => 0,
        ]);

        $resp = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson("/api/transfer-requests/{$req->transfer_id}");

        $resp->assertOk()
            ->assertJsonPath('data.details.0.available_at_source', 3)
            ->assertJsonPath('data.details.0.shortfall', 2);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd back-end && php artisan test --filter=test_request_show_exposes_available_at_source_and_shortfall`
Expected: FAIL (`available_at_source`/`shortfall` not present).

- [ ] **Step 3: Enrich `TransferRequestController::show`**

In `back-end/app/Http/Controllers/Api/TransferRequestController.php`, replace the per-line stock loop inside `show` with:

```php
            $fromLocId = $transfer->from_location_id;
            foreach ($transfer->details as $detail) {
                $available = (int) (Inventory::where('location_id', $fromLocId)
                    ->where('product_id', $detail->product_id)
                    ->value('available_quantity') ?? 0);
                $detail->available_at_source = $available;
                $detail->stock_at_source     = $available; // backward-compat alias
                $detail->shortfall           = max(0, (int) $detail->quantity_requested - $available);
            }
```

- [ ] **Step 4: Run to verify it passes**

Run: `cd back-end && php artisan test --filter=test_request_show_exposes_available_at_source_and_shortfall`
Expected: PASS.

- [ ] **Step 5: Render it on the approval screen**

In `frontend/src/admin/pages/TransferRequestPage.jsx`, in the request-detail line rendering (where each `detail`/line is shown for an approver), display the new fields. Add, per line:

```jsx
<span className="line-stock">
  requested: {line.quantity_requested}
  {' · '}available at source: {line.available_at_source ?? line.stock_at_source ?? 0}
  {Number(line.shortfall) > 0 && (
    <span className="line-shortfall" style={{ color: '#b45309', fontWeight: 600 }}>
      {' '}⚠ short {line.shortfall}
    </span>
  )}
</span>
```

- [ ] **Step 6: Manually verify in the browser**

Run the app (`cd back-end && php artisan serve` and `cd frontend && npm start`), open a pending request as admin, confirm each line shows requested vs available and an amber "short N" when available < requested.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/TransferRequestController.php frontend/src/admin/pages/TransferRequestPage.jsx back-end/tests/Feature/Transfer/TransferFlowTest.php
git commit -m "feat: show available-at-source and shortfall on transfer approval"
```

---

## Task 12: Race-safe document numbers

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/TransferRequestController.php`
- Modify: `back-end/app/Http/Controllers/Api/ISTController.php`
- Test: `back-end/tests/Feature/Transfer/TransferFlowTest.php`

- [ ] **Step 1: Write the failing test** (append to `TransferFlowTest`)

```php
    public function test_request_numbers_are_unique_under_repeated_create(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 50);
        $admin = $this->adminUser();

        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $resp = $this->actingAs($admin, 'sanctum')->postJson('/api/transfer-requests', [
                'from_location_id' => $from->location_id,
                'to_location_id'   => $to->location_id,
                'transfer_date'    => now()->toDateString(),
                'products'         => [['product_id' => $prod->product_id, 'quantity_requested' => 1]],
            ]);
            $resp->assertCreated();
            $numbers[] = $resp->json('data.transfer_number');
        }

        $this->assertSame(count($numbers), count(array_unique($numbers)));
    }
```

- [ ] **Step 2: Run to verify it passes or fails**

Run: `cd back-end && php artisan test --filter=test_request_numbers_are_unique_under_repeated_create`
Expected: likely PASS sequentially but the generator is unsafe; proceed to harden it regardless (the `transfer_number` column is `unique`, so a collision throws — the goal is to derive from the real id).

- [ ] **Step 3: Derive the number from the inserted row id (TransferRequestController)**

In `TransferRequestController::store`, remove the `$nextId`/`$transferNumber` precomputation and instead create the row first with a placeholder, then set the number from the real id inside the existing transaction:

```php
            $transfer = Transfer::create([
                'transfer_request_id'       => null,
                'from_location_id'          => $validated['from_location_id'],
                'to_location_id'            => $validated['to_location_id'],
                'transfer_number'           => 'TR-REQ-PENDING-' . uniqid(),
                'transfer_date'             => $validated['transfer_date'],
                'requested_by'              => $user->user_id,
                'status_id'                 => $pendingId,
                'notes'                     => $validated['notes'] ?? null,
                'total_quantity_transferred' => 0,
                'total_quantity_received'    => 0,
            ]);
            $transfer->transfer_number = 'TR-REQ-' . date('Ymd') . '-' . str_pad((string) $transfer->transfer_id, 4, '0', STR_PAD_LEFT);
            $transfer->save();
```

- [ ] **Step 4: Do the same for the IST number (ISTController::store)**

In `ISTController::store`, remove `$nextId`/`$istNumber` precomputation; create the IST with a placeholder number, then set it from the real id:

```php
            $ist = Transfer::create([
                'transfer_request_id'        => $req->transfer_id,
                'from_location_id'           => $req->from_location_id,
                'to_location_id'             => $req->to_location_id,
                'transfer_number'            => 'IST-PENDING-' . uniqid(),
                'transfer_date'              => $validated['transfer_date'],
                'prepared_by'                => $user->user_id,
                'approved_by'                => $req->approved_by,
                'status_id'                  => $approvedId,
                'total_quantity_transferred' => 0,
                'total_quantity_received'    => 0,
            ]);
            $istNumber = 'IST-' . date('Ymd') . '-' . str_pad((string) $ist->transfer_id, 4, '0', STR_PAD_LEFT);
            $ist->transfer_number = $istNumber;
            $ist->save();
```

(The later `$istNumber` references in tracking/audit still work because the variable is defined before they run.)

- [ ] **Step 5: Run to verify it passes**

Run: `cd back-end && php artisan test --filter=test_request_numbers_are_unique_under_repeated_create`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/TransferRequestController.php back-end/app/Http/Controllers/Api/ISTController.php back-end/tests/Feature/Transfer/TransferFlowTest.php
git commit -m "fix: derive transfer/IST numbers from real row id (race-safe)"
```

---

## Task 13: Location worklist notifications (dashboard stats + alerts)

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/DashboardController.php`
- Test: `back-end/tests/Feature/Transfer/TransferFlowTest.php`

- [ ] **Step 1: Write the failing test** (append to `TransferFlowTest`)

```php
    public function test_dashboard_stats_include_awaiting_counts(): void
    {
        $resp = $this->actingAs($this->adminUser(), 'sanctum')->getJson('/api/dashboard/stats');
        $resp->assertOk()
            ->assertJsonStructure(['data' => ['awaiting_fulfillment', 'awaiting_receipt']]);
    }
```

> If the dashboard stats route differs, check `routes/api.php` for the `DashboardController@stats` path and adjust the URL. Use that exact path.

- [ ] **Step 2: Run to verify it fails**

Run: `cd back-end && php artisan test --filter=test_dashboard_stats_include_awaiting_counts`
Expected: FAIL (keys absent).

- [ ] **Step 3: Add the counts to `getStats()`**

In `back-end/app/Http/Controllers/Api/DashboardController.php`, inside `getStats()`, compute location-scoped counts. Add near the other `$pending*` lines (use the authenticated user's location; admin/IA see all):

```php
        $user    = request()->user();
        $role    = $user?->role?->role_name ?? '';
        $isAdmin = in_array($role, ['admin', 'inventory_analyst'], true);
        $locId   = $user?->enforced_location_id ?? $user?->location_id;

        $awaitingFulfillment = Transfer::whereNotNull('transfer_request_id')
            ->whereHas('status', fn ($q) => $q->where('status_name', 'Approved')->where('status_category', 'transfer'))
            ->when(!$isAdmin && $locId, fn ($q) => $q->where('from_location_id', $locId))
            ->count();

        $awaitingReceipt = Transfer::whereNotNull('transfer_request_id')
            ->whereHas('status', fn ($q) => $q->where('status_name', 'In Transit')->where('status_category', 'transfer'))
            ->when(!$isAdmin && $locId, fn ($q) => $q->where('to_location_id', $locId))
            ->count();
```

Then add to the returned array:

```php
            'awaiting_fulfillment' => $awaitingFulfillment,
            'awaiting_receipt'     => $awaitingReceipt,
```

Ensure `use App\Models\Transfer;` is present at the top of the controller (add it if missing).

- [ ] **Step 4: Add alert-feed entries**

In `DashboardController::pendingApprovals()` (the alerts/notices feed), append two derived alerts after the existing pending blocks, reusing the same location scoping computed above (extract the scoping into local vars there too). Add to the response `data` array:

```php
            'awaiting_fulfillment_count' => $awaitingFulfillment,
            'awaiting_receipt_count'     => $awaitingReceipt,
```

and include human-readable entries in whatever list the feed renders (mirror the structure of `pending_pos`), e.g. a `transfers_to_fulfill` / `transfers_to_receive` array of `{ id, transfer_number, from_location, to_location }`. Follow the exact shape already used by `pending_pos` so the frontend feed renders them uniformly.

- [ ] **Step 5: Run to verify it passes**

Run: `cd back-end && php artisan test --filter=test_dashboard_stats_include_awaiting_counts`
Expected: PASS.

- [ ] **Step 6: Surface badges in the sidebar (frontend)**

In `frontend/src/admin/components/Sidebar.jsx`, read `awaiting_fulfillment` and `awaiting_receipt` from the dashboard stats the sidebar already loads, and render a count badge next to the IST/Transfers nav item (sum the two, or show separately on the "To Fulfill"/"To Receive" sub-items). Follow the existing badge pattern used for other pending counts in that file.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/DashboardController.php frontend/src/admin/components/Sidebar.jsx back-end/tests/Feature/Transfer/TransferFlowTest.php
git commit -m "feat: location-scoped awaiting-fulfillment/receipt counts, badges, and alerts"
```

---

## Task 14: Worklist filters for source/destination

**Files:**
- Modify: `frontend/src/admin/pages/ISTManagementPage.jsx`

(`ISTController::index` already filters by `status_id` and scopes non-admins to their location across both `from`/`to`. The frontend just needs the two preset views.)

- [ ] **Step 1: Add "To Fulfill" and "To Receive" filter presets**

In `frontend/src/admin/pages/ISTManagementPage.jsx`, add two quick-filter buttons/tabs:
- **To Fulfill** → fetch `istAPI.getAll({ status_id: <Approved id> })`. The backend already restricts a non-admin to ISTs touching their location; rows where `from_location_id` is the user's location are the ones to fulfill.
- **To Receive** → fetch `istAPI.getAll({ status_id: <In Transit id> })`; rows where `to_location_id` is the user's location are the ones to receive.

Resolve the status ids via the existing `statusAPI`/status list already used on the page (look up by `status_name` within category `transfer`). Each row's primary action links to the scan workflow (fulfill) or the receive page (receive).

- [ ] **Step 2: Manually verify**

As a warehouse user at the source location, confirm an approved IST appears under "To Fulfill"; after dispatch, confirm it appears under "To Receive" for a destination user.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/pages/ISTManagementPage.jsx
git commit -m "feat: To Fulfill / To Receive worklist presets on IST management"
```

---

## Task 15: Receive scan/manual UI + receipt

**Files:**
- Modify: `frontend/src/admin/services/api.js`
- Modify: `frontend/src/admin/pages/ISTReceivePage.jsx`
- Modify: `back-end/resources/views/ist/receipt.blade.php`

- [ ] **Step 1: Add the API client methods**

In `frontend/src/admin/services/api.js`, inside the `istAPI` object, add:

```js
  receiveScan:       async (id, data)   => apiRequest(`/ist/${id}/receive-scan`, { method: 'POST', body: JSON.stringify(data) }),
  removeReceiveScan: async (id, scanId) => apiRequest(`/ist/${id}/receive-scan/${scanId}`, { method: 'DELETE' }),
```

- [ ] **Step 2: Build the scan/manual receive loop**

In `frontend/src/admin/pages/ISTReceivePage.jsx`, mirror the dispatch scan UX: a per-line list with a barcode input (and a "manual add" button for consumables) that calls `istAPI.receiveScan(id, { barcode, transfer_detail_id, quantity })`, shows running `received / shipped` per line, supports removing a mistaken entry via `istAPI.removeReceiveScan(id, scanId)`, and a **Confirm Receipt** button calling `istAPI.receive(id, { received_date: today, products: [...] })` (or the no-body confirm if the backend ignores `products` now — pass `{ received_date }`). On success, show a link to the receipt: `istAPI.receipt(id)` for on-screen and `istAPI.receiptPdfUrl(id)` for the PDF download.

- [ ] **Step 3: Ensure the receipt view shows the required fields**

Open `back-end/resources/views/ist/receipt.blade.php`. Confirm it renders, per the spec: transfer/IST number, source + destination, received date/time and dispatch date/time, received-by and prepared/dispatched-by users, and per product the **requested / shipped / received** quantities plus received serial numbers, and a discrepancies section. Add any missing field using the `$ist` relations already eager-loaded by `ISTController::receipt` (`fromLocation`, `toLocation`, `receivedBy`, `preparedBy`, `details`, and each detail's `item_serials`). For requested vs shipped vs received, use `quantity_requested`, `quantity_transferred`, `quantity_received` on each detail.

- [ ] **Step 4: Manually verify the full receive + receipt**

Run the app. Take an In-Transit IST, scan/enter each unit at the destination, confirm receipt, and verify: destination stock increased, status is Received, and the receipt (screen + PDF) shows who/when/what with serials and any discrepancy.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/services/api.js frontend/src/admin/pages/ISTReceivePage.jsx back-end/resources/views/ist/receipt.blade.php
git commit -m "feat: scan/manual receive UI and complete receipt"
```

---

## Task 16: Admin-only direct override via the service

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/TransferController.php`
- Modify: `back-end/routes/api.php`
- Modify: `frontend/src/admin/components/Sidebar.jsx`
- Test: `back-end/tests/Feature/Transfer/TransferFlowTest.php`

- [ ] **Step 1: Write the failing tests** (append to `TransferFlowTest`)

```php
    public function test_admin_direct_transfer_moves_stock_via_service(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 10);
        $this->setStock($to->location_id, $prod->product_id, 0);

        $resp = $this->actingAs($this->adminUser(), 'sanctum')->postJson('/api/transfers', [
            'from_location_id' => $from->location_id,
            'to_location_id'   => $to->location_id,
            'transfer_date'    => now()->toDateString(),
            'details'          => [['product_id' => $prod->product_id, 'quantity_transferred' => 4]],
        ]);
        $resp->assertCreated();

        $src = \App\Models\Inventory::where('location_id', $from->location_id)->where('product_id', $prod->product_id)->first();
        $dst = \App\Models\Inventory::where('location_id', $to->location_id)->where('product_id', $prod->product_id)->first();
        $this->assertSame(6, $src->quantity_on_hand);
        $this->assertSame(6, $src->available_quantity);
        $this->assertSame(4, $dst->quantity_on_hand);
        $this->assertSame(4, $dst->available_quantity);
    }

    public function test_admin_direct_transfer_rejects_serialized_without_serials(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 3);

        $resp = $this->actingAs($this->adminUser(), 'sanctum')->postJson('/api/transfers', [
            'from_location_id' => $from->location_id,
            'to_location_id'   => $to->location_id,
            'transfer_date'    => now()->toDateString(),
            'details'          => [['product_id' => $prod->product_id, 'quantity_transferred' => 2]],
        ]);
        $resp->assertStatus(422);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `cd back-end && php artisan test --filter=test_admin_direct_transfer_moves_stock_via_service`
Expected: FAIL (current `store` does its own inventory math / different shape).

- [ ] **Step 3: Add an express-override method to `TransferService`**

In `back-end/app/Services/TransferService.php`, add a method that creates an override IST-like transfer and runs the pipeline:

```php
    /**
     * Admin-only express transfer: no request/approval, no manual scans.
     * Builds an Approved transfer, sets scanned/received to chosen qty, then
     * runs dispatch()+receive(). Serialized lines require explicit serials.
     *
     * @param array $lines each: ['product_id'=>int, 'quantity'=>int, 'serials'=>string[] (required for appliances)]
     */
    public function directOverride(int $fromId, int $toId, array $lines, int $userId, string $date): Transfer
    {
        return DB::transaction(function () use ($fromId, $toId, $lines, $userId, $date) {
            $transfer = Transfer::create([
                'transfer_request_id'        => null,
                'from_location_id'           => $fromId,
                'to_location_id'             => $toId,
                'transfer_number'            => 'OVR-PENDING-' . uniqid(),
                'transfer_date'              => $date,
                'requested_by'               => $userId,
                'approved_by'                => $userId,
                'status_id'                  => $this->statusId('Approved'),
                'total_quantity_transferred' => 0,
                'total_quantity_received'    => 0,
            ]);
            $transfer->transfer_number = 'OVR-' . date('Ymd') . '-' . str_pad((string) $transfer->transfer_id, 4, '0', STR_PAD_LEFT);
            $transfer->save();

            foreach ($lines as $line) {
                $product = \App\Models\Product::find($line['product_id']);
                $qty = (int) $line['quantity'];
                $isAppliance = ($product?->product_type === 'appliance');

                if ($isAppliance) {
                    $serials = $line['serials'] ?? [];
                    if (count($serials) !== $qty) {
                        throw new RuntimeException("Serialized product {$line['product_id']} requires {$qty} serial number(s).");
                    }
                    ItemSerial::whereIn('serial_number', $serials)
                        ->where('product_id', $line['product_id'])
                        ->where('location_id', $fromId)
                        ->update(['transfer_id' => $transfer->transfer_id]);
                }

                $transfer->details()->create([
                    'product_id'           => $line['product_id'],
                    'quantity_requested'   => $qty,
                    'quantity_scanned'     => $qty,
                    'quantity_transferred' => 0,
                    'quantity_received'    => $qty,
                ]);
            }

            // Pipeline: dispatch then receive (received already set per line).
            $transfer = $this->dispatch($transfer->fresh('details'));
            return $this->receive($transfer->fresh('details'), $userId);
        });
    }
```

> Note: `receive()` reads `quantity_received` already set above. Because the override sets it before dispatch, ensure `dispatch()` does not zero it — it does not (it only writes `quantity_transferred`).

- [ ] **Step 4: Rewrite `TransferController::store` as the thin override caller**

Replace the body of `store` in `back-end/app/Http/Controllers/Api/TransferController.php`. Add `use App\Services\TransferService;`, then:

```php
    public function store(Request $request, TransferService $service)
    {
        try {
            $validated = $request->validate([
                'from_location_id'           => 'required|exists:locations,location_id',
                'to_location_id'             => 'required|different:from_location_id|exists:locations,location_id',
                'transfer_date'              => 'required|date',
                'details'                    => 'required|array|min:1',
                'details.*.product_id'       => 'required|exists:products,product_id',
                'details.*.quantity_transferred' => 'required|integer|min:1',
                'details.*.serials'          => 'sometimes|array',
                'details.*.serials.*'        => 'string',
            ]);

            $lines = array_map(fn ($d) => [
                'product_id' => (int) $d['product_id'],
                'quantity'   => (int) $d['quantity_transferred'],
                'serials'    => $d['serials'] ?? [],
            ], $validated['details']);

            $transfer = $service->directOverride(
                (int) $validated['from_location_id'],
                (int) $validated['to_location_id'],
                $lines,
                $request->user()->user_id,
                $validated['transfer_date']
            );

            \App\Models\AuditTrail::create([
                'user_id'    => $request->user()?->user_id,
                'action'     => 'admin_direct_transfer',
                'table_name' => 'transfers',
                'record_id'  => $transfer->transfer_id,
                'old_values' => null,
                'new_values' => [
                    'transfer_number'  => $transfer->transfer_number,
                    'from_location_id' => $validated['from_location_id'],
                    'to_location_id'   => $validated['to_location_id'],
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return $this->success(
                $transfer->load(['fromLocation', 'toLocation', 'details.product']),
                'Direct transfer completed',
                201
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('Failed to complete direct transfer: ' . $e->getMessage(), 500);
        }
    }
```

Also change `TransferController::destroy` to use the service for reversal: before `$transfer->details()->delete(); $transfer->delete();`, replace the inline reversal loop with `app(TransferService::class)->reverse($transfer);`.

- [ ] **Step 5: Tighten the `/transfers` write routes to admin-only**

In `back-end/routes/api.php`, change the group guarding `POST /transfers`, `PUT /transfers/{id}`, `POST /transfers/{id}/receive`, `POST /transfers/{id}/tracking` from `role:admin,branch_manager,warehouse_personnel` to `role:admin`. Leave the read routes (`index`, `show`, `archived`) as-is.

- [ ] **Step 6: Run the override tests**

Run: `cd back-end && php artisan test --filter=test_admin_direct_transfer_moves_stock_via_service`
Then: `php artisan test --filter=test_admin_direct_transfer_rejects_serialized_without_serials`
Expected: both PASS.

- [ ] **Step 7: Gate the direct-transfer page to admins (frontend)**

In `frontend/src/admin/components/Sidebar.jsx`, wrap the `StockTransferManagement` nav link so it only renders for `admin` (use the existing role check / `getRoleKey` pattern). Other roles see only the request → IST navigation.

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Services/TransferService.php back-end/app/Http/Controllers/Api/TransferController.php back-end/routes/api.php frontend/src/admin/components/Sidebar.jsx back-end/tests/Feature/Transfer/TransferFlowTest.php
git commit -m "feat: admin-only direct transfer override via TransferService"
```

---

## Task 17: Retire the old direct-receive scan path + full suite green

**Files:**
- Modify: `frontend/src/admin/pages/TransferScanWorkflow.jsx`
- Modify: `back-end/routes/console.php`

- [ ] **Step 1: Repoint the old scan workflow**

`frontend/src/admin/pages/TransferScanWorkflow.jsx` previously called `transfersAPI.receive` (the retired direct path). Repoint its receive action to the IST receive flow (`istAPI.receive` / the receive page), or remove the page from routing if `ISTReceivePage` fully replaces it. Confirm no remaining frontend caller uses `transfersAPI.receive` for normal users (search the frontend for `transfersAPI.receive`).

- [ ] **Step 2: Update the console self-check**

In `back-end/routes/console.php`, the transfer self-check posts directly to `/api/transfers` with `quantity_transferred`. Update it to exercise the new pipeline: create request → approve → create IST → scan → confirm-dispatch → receive (or call `TransferService` methods directly), and keep the inventory delta assertions (`$expectedDest = $beforeDest + $stepTransfer`).

- [ ] **Step 3: Run the entire transfer test suite**

Run: `cd back-end && php artisan test --filter=Transfer`
Expected: all `TransferServiceTest` and `TransferFlowTest` tests PASS.

- [ ] **Step 4: Run the console self-check**

Run: `cd back-end && php artisan <the self-check command name from console.php>`
Expected: passes with the corrected pipeline.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/pages/TransferScanWorkflow.jsx back-end/routes/console.php
git commit -m "refactor: retire direct-receive scan path; update transfer self-check"
```

---

## Self-Review (completed during authoring)

- **Spec coverage:**
  - §1 flow/statuses/roles → Tasks 2 (statuses), 10 & 16 (role middleware), 14 (worklists).
  - §2 engine (dispatch/receive/reverse, both columns, serials, partial auto-close, race-safe numbers, one inventory model) → Tasks 3–9, 12. (Single inventory model: the service uses only `App\Models\Inventory`; `ProductInventory` is left in place but unused by transfers — note below.)
  - §2 receive scan loop → Task 7; receipt → Task 15.
  - §3 approval stock visibility → Task 11.
  - §4 notifications (badge + alert, both directions) → Tasks 13, 14.
  - §5 admin override → Task 16.
  - §6 error handling (422 vs 500, locks, discrepancy) → baked into Tasks 4–9; testing harness → Task 1; reconciliation/round-trip → Task 9.
- **Type/name consistency:** service methods `dispatch`, `recordReceiveScan`, `removeReceiveScan`, `receive`, `reverse`, `directOverride`, `statusIdForTest` are used consistently across controller Tasks 10/16 and tests. Serial values `'received'`/`'transferred'` used uniformly.
- **Known deferment:** the spec's "remove/alias `ProductInventory`" is intentionally *not* done here to avoid touching unrelated callers; transfers no longer depend on it. If you want it removed, do it as a separate cleanup after grepping its other usages — flagged so it isn't silently dropped.
- **Placeholders:** none — every code step contains full code; the only `<...>` is the self-check command name in Task 17 Step 4, which depends on the existing `->purpose(...)` registration in `console.php`.
