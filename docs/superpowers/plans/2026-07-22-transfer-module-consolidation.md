# Transfer Module Consolidation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collapse the three transfer surfaces (Transfer Requests, Stock Transfers, Interbranch Transfers) into a single IA-gated "Transfers" module with three request intents and a FIFO-guided pick list, reusing the existing `TransferService` engine.

**Architecture:** One `TransferController` delegates all inventory/serial movement to the existing `App\Services\TransferService`. A transfer is one `transfers` row that flows `Submitted → Approved → In Transit → Received` (or `Rejected`). The IA verifies every request, assigns/confirms locations, and only then notifies the fulfilling warehouse. Dispatch shows a FIFO pick list (serials + zones) derived from `inventory_batches`. The frontend becomes one role-aware worklist page with status tabs.

**Tech Stack:** Laravel 11 (`back-end/`), React 18 (`frontend/src/admin/`), MariaDB via XAMPP. Tests: PHPUnit feature tests on the cloned `airkingV5_test` DB with `DatabaseTransactions` (schema is a SQL dump, so `migrate` blanket-fails — apply single migrations with `--path`). Jest for frontend utils.

**Reference spec:** `docs/superpowers/specs/2026-07-22-transfer-module-consolidation-design.md`

---

## Ground truth (verified 2026-07-22, confirm before relying)

- Engine `back-end/app/Services/TransferService.php` already implements: `dispatch`, `receive`, `reverse`, `recordDispatchScan`, `removeDispatchScan`, `recordReceiveScan`, `removeReceiveScan`, `directOverride` (to be deleted). Statuses resolved by name from `status_lookup` where `status_category='transfer'`.
- Transfer statuses guaranteed by migration `2026_06_07_000001_guarantee_transfer_statuses`: `Pending`, `Approved`, `Rejected`, `In Transit`, `Received`.
- `transfers` fillable already includes `from_location_id`, `to_location_id`, `requested_by`, `approved_by`, `received_by`, `notes`, `status_id`. Model: `back-end/app/Models/Transfer.php`. It has a `forUser($user)` query scope (used in `TransferRequestController::index`).
- `transfer_details` columns used: `quantity_requested`, `quantity_scanned`, `quantity_transferred`, `quantity_received`, `product_id`, `transfer_detail_id`.
- FIFO data: `inventory_batches` (`batch_id`, `product_id`, `location_id`, `receiving_id`, `quantity_received`, `quantity_remaining`, `received_at`, `zone_label`). Model `back-end/app/Models/InventoryBatch.php`. Serials `item_serial` link to a batch via `receiving_id`.
- Permission slug for all transfer routes: `inventory_transfers` (actions `read`, `create`, `approve`, `update`).
- Current routes to retire live in `back-end/routes/api.php` (IST block ~L627-647, transfer-requests ~L655-660, transfers ERD block ~L665-678, plus the query-token receipt route ~L904-905).
- Frontend pages to retire: `frontend/src/admin/pages/TransferRequestPage.jsx`, `StockTransferManagement.jsx`, `ISTManagementPage.jsx`, `ISTCreatePage.jsx`, `ISTDetailPage.jsx`, `ISTDispatchPage.jsx` (dispatch route referenced from ISTDetailPage), and the IST receive flow embedded in `BarcodeScan.jsx`. Sidebar lazy-map + nav entries: `frontend/src/admin/components/Sidebar.jsx:30-32,84-86,473`.
- Frontend API groups in `frontend/src/admin/services/api.js`: `transfersAPI` (L872), `istAPI` (L897), `transferRequestsAPI` (L923). These collapse into one `transfersAPI`.

---

## Phase 0 — Branch & harness

### Task 0: Confirm working branch and test DB

**Files:** none (setup only)

- [ ] **Step 1: Confirm branch**

Run: `cd back-end && git rev-parse --abbrev-ref HEAD`
Expected: `backupV3` (or a feature branch cut from it). If on `main`, create `git checkout -b feature/transfer-consolidation`.

- [ ] **Step 2: Confirm the test DB exists and PHPUnit runs**

Run: `cd back-end && php artisan test --filter=TransferServiceTest`
Expected: existing transfer engine tests PASS. If the suite errors on DB connection, the `airkingV5_test` clone is missing — recreate it (see `project_db_migration_test_convention` memory) before continuing.

---

## Phase 1 — Data model

### Task 1: Migration — nullable source + intent column

**Files:**
- Create: `back-end/database/migrations/2026_07_22_000000_transfer_intent_and_nullable_source.php`
- Test: `back-end/tests/Feature/Transfer/TransferIntentSchemaTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Transfer;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TransferIntentSchemaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_transfers_table_has_intent_and_nullable_source(): void
    {
        $this->assertTrue(Schema::hasColumn('transfers', 'intent'));

        $col = collect(Schema::getColumns('transfers'))
            ->firstWhere('name', 'from_location_id');
        $this->assertTrue((bool) $col['nullable'], 'from_location_id must be nullable');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=TransferIntentSchemaTest`
Expected: FAIL — `intent` column missing.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->string('intent', 16)->default('demand')->after('to_location_id');
        });

        // from_location_id may be constrained NOT NULL in the SQL dump; make it nullable.
        DB::statement('ALTER TABLE transfers MODIFY from_location_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        Schema::table('transfers', fn (Blueprint $t) => $t->dropColumn('intent'));
        DB::statement('ALTER TABLE transfers MODIFY from_location_id BIGINT UNSIGNED NOT NULL');
    }
};
```

> NOTE: confirm the real column type of `from_location_id` first with `cd back-end && php artisan db:table transfers` (or `SHOW COLUMNS FROM transfers`) and match it exactly in the `MODIFY` statements (type + unsigned). Adjust `BIGINT UNSIGNED` if the dump uses `INT`.

- [ ] **Step 4: Apply the migration to dev + test DBs**

Run (dev): `cd back-end && php artisan migrate --path=database/migrations/2026_07_22_000000_transfer_intent_and_nullable_source.php`
Run (test): `cd back-end && php artisan migrate --path=database/migrations/2026_07_22_000000_transfer_intent_and_nullable_source.php --env=testing`
Expected: both report `DONE`.

- [ ] **Step 5: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=TransferIntentSchemaTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add back-end/database/migrations/2026_07_22_000000_transfer_intent_and_nullable_source.php back-end/tests/Feature/Transfer/TransferIntentSchemaTest.php
git commit -m "feat(transfer): add intent column and make from_location_id nullable"
```

### Task 2: Add `intent` to the Transfer model

**Files:**
- Modify: `back-end/app/Models/Transfer.php` (fillable array, ~L22-38)

- [ ] **Step 1: Add `intent` to `$fillable`**

In the `$fillable` array, add `'intent',` immediately after `'to_location_id',`.

- [ ] **Step 2: Verify mass-assignment works**

Run: `cd back-end && php artisan tinker --execute="echo (new App\Models\Transfer(['intent'=>'pull']))->intent;"`
Expected: prints `pull`.

- [ ] **Step 3: Commit**

```bash
git add back-end/app/Models/Transfer.php
git commit -m "feat(transfer): expose intent on Transfer model"
```

---

## Phase 2 — Engine additions (FIFO pick list)

### Task 3: `TransferService::pickList()` — FIFO suggestions with zones

**Files:**
- Modify: `back-end/app/Services/TransferService.php`
- Test: `back-end/tests/Feature/Transfer/PickListTest.php`

Returns, per line, the FIFO-ordered units to pull. Appliances → individual serials tagged with their batch zone; consumables → zone+quantity per batch, oldest `received_at` first, capped at the line's outstanding quantity.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Transfer;

class PickListTest extends TransferTestCase
{
    public function test_consumable_pick_list_is_fifo_by_zone(): void
    {
        // TransferTestCase helpers: makeLocation(), makeConsumable(), makeBatch(), makeApprovedTransfer()
        $src = $this->makeLocation();
        $dst = $this->makeLocation();
        $product = $this->makeConsumable();

        // Two batches at source: older in zone A (qty 3), newer in zone B (qty 5).
        $this->makeBatch($product, $src, ['zone_label' => 'A-01', 'quantity_remaining' => 3, 'received_at' => '2026-01-01']);
        $this->makeBatch($product, $src, ['zone_label' => 'B-02', 'quantity_remaining' => 5, 'received_at' => '2026-02-01']);

        $ist = $this->makeApprovedTransfer($src, $dst, [[$product, 6]]); // need 6

        $service = app(\App\Services\TransferService::class);
        $list = $service->pickList($ist);

        $line = collect($list)->firstWhere('product_id', $product->product_id);
        // FIFO: take all 3 from A-01, then 3 from B-02.
        $this->assertSame('A-01', $line['suggestions'][0]['zone_label']);
        $this->assertSame(3, $line['suggestions'][0]['quantity']);
        $this->assertSame('B-02', $line['suggestions'][1]['zone_label']);
        $this->assertSame(3, $line['suggestions'][1]['quantity']);
    }

    public function test_appliance_pick_list_lists_serials_oldest_first(): void
    {
        $src = $this->makeLocation();
        $dst = $this->makeLocation();
        $product = $this->makeAppliance();

        $b1 = $this->makeBatch($product, $src, ['zone_label' => 'A-01', 'quantity_remaining' => 1, 'received_at' => '2026-01-01']);
        $b2 = $this->makeBatch($product, $src, ['zone_label' => 'B-02', 'quantity_remaining' => 1, 'received_at' => '2026-02-01']);
        $this->makeSerial($product, $src, 'SN-OLD', $b1->receiving_id);
        $this->makeSerial($product, $src, 'SN-NEW', $b2->receiving_id);

        $ist = $this->makeApprovedTransfer($src, $dst, [[$product, 2]]);

        $list = app(\App\Services\TransferService::class)->pickList($ist);
        $line = collect($list)->firstWhere('product_id', $product->product_id);

        $this->assertSame('SN-OLD', $line['suggestions'][0]['serial_number']);
        $this->assertSame('A-01', $line['suggestions'][0]['zone_label']);
        $this->assertSame('SN-NEW', $line['suggestions'][1]['serial_number']);
    }
}
```

> If `TransferTestCase` lacks `makeConsumable/makeAppliance/makeBatch/makeSerial/makeApprovedTransfer` helpers, add them in Task 3a below FIRST, then write this test.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=PickListTest`
Expected: FAIL — `pickList` undefined.

- [ ] **Step 3: Implement `pickList` on `TransferService`**

Add this method to `TransferService` (uses `App\Models\InventoryBatch`, `App\Models\ItemSerial` — `InventoryBatch` already imported? add `use App\Models\InventoryBatch;` at top if missing):

```php
/**
 * FIFO pick suggestions per line for an Approved IST.
 * Appliances: individual serials tagged with their batch zone, oldest batch first.
 * Consumables: zone + quantity per batch, oldest received_at first, capped at outstanding qty.
 *
 * @return array<int, array{product_id:int, product_name:string, needed:int, suggestions:array}>
 */
public function pickList(Transfer $ist): array
{
    $ist->loadMissing('details.product');
    $out = [];

    foreach ($ist->details as $detail) {
        $needed = (int) $detail->quantity_requested - (int) $detail->quantity_scanned;
        $suggestions = [];

        if ($this->isSerialized($detail)) {
            $serials = ItemSerial::where('product_id', $detail->product_id)
                ->where('location_id', $ist->from_location_id)
                ->where('serial_type', 'received')
                ->whereNull('transfer_id')
                ->leftJoin('inventory_batches as b', function ($j) use ($ist) {
                    $j->on('b.receiving_id', '=', 'item_serial.receiving_id')
                      ->where('b.location_id', '=', $ist->from_location_id);
                })
                ->orderByRaw('COALESCE(b.received_at, "1970-01-01") ASC')
                ->limit(max(0, $needed))
                ->get(['item_serial.serial_number', 'item_serial.receiving_id', 'b.zone_label', 'b.received_at', 'b.batch_id']);

            foreach ($serials as $s) {
                $suggestions[] = [
                    'serial_number' => $s->serial_number,
                    'zone_label'    => $s->zone_label,
                    'quantity'      => 1,
                    'batch_id'      => $s->batch_id !== null ? (int) $s->batch_id : null,
                    'received_at'   => $s->received_at,
                ];
            }
        } else {
            $remaining = max(0, $needed);
            $batches = InventoryBatch::where('product_id', $detail->product_id)
                ->where('location_id', $ist->from_location_id)
                ->where('quantity_remaining', '>', 0)
                ->orderBy('received_at', 'asc')
                ->get();

            foreach ($batches as $batch) {
                if ($remaining <= 0) break;
                $take = min($remaining, (int) $batch->quantity_remaining);
                $suggestions[] = [
                    'serial_number' => null,
                    'zone_label'    => $batch->zone_label,
                    'quantity'      => $take,
                    'batch_id'      => (int) $batch->batch_id,
                    'received_at'   => $batch->received_at,
                ];
                $remaining -= $take;
            }
        }

        $out[] = [
            'product_id'   => (int) $detail->product_id,
            'product_name' => $detail->product?->product_name ?? "Product #{$detail->product_id}",
            'needed'       => $needed,
            'suggestions'  => $suggestions,
        ];
    }

    return $out;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=PickListTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/TransferService.php back-end/tests/Feature/Transfer/PickListTest.php
git commit -m "feat(transfer): FIFO pick-list suggestions with serials and zones"
```

### Task 3a (only if missing): Extend `TransferTestCase` with factory helpers

**Files:**
- Modify: `back-end/tests/Feature/Transfer/TransferTestCase.php`

- [ ] **Step 1: Read the existing helpers**

Run: `cd back-end && grep -nE "function make|protected function" tests/Feature/Transfer/TransferTestCase.php`
Expected: shows which helpers already exist. Only add the missing ones below.

- [ ] **Step 2: Add any missing helpers**

Add methods that create rows directly (no model factories needed). Example signatures the tests above expect — implement whichever are absent, matching existing column conventions in the file:

```php
protected function makeBatch($product, $location, array $attrs = []): \App\Models\InventoryBatch
{
    return \App\Models\InventoryBatch::create(array_merge([
        'product_id'         => $product->product_id,
        'location_id'        => $location->location_id,
        'receiving_id'       => null,
        'quantity_received'  => $attrs['quantity_remaining'] ?? 1,
        'quantity_remaining' => $attrs['quantity_remaining'] ?? 1,
        'received_at'        => $attrs['received_at'] ?? now(),
        'zone_label'         => $attrs['zone_label'] ?? null,
    ], $attrs));
}

protected function makeSerial($product, $location, string $sn, $receivingId = null): \App\Models\ItemSerial
{
    return \App\Models\ItemSerial::create([
        'product_id'    => $product->product_id,
        'location_id'   => $location->location_id,
        'receiving_id'  => $receivingId,
        'serial_number' => $sn,
        'serial_type'   => 'received',
        'status_id'     => \App\Models\ItemSerial::IN_STOCK_STATUS_ID,
    ]);
}

protected function makeApprovedTransfer($from, $to, array $lines): \App\Models\Transfer
{
    $svc = app(\App\Services\TransferService::class);
    $t = \App\Models\Transfer::create([
        'from_location_id' => $from->location_id,
        'to_location_id'   => $to->location_id,
        'intent'           => 'demand',
        'transfer_number'  => 'TST-' . uniqid(),
        'transfer_date'    => now(),
        'status_id'        => $svc->statusIdForTest('Approved'),
    ]);
    foreach ($lines as [$product, $qty]) {
        $t->details()->create([
            'product_id'           => $product->product_id,
            'quantity_requested'   => $qty,
            'quantity_scanned'     => 0,
            'quantity_transferred' => 0,
            'quantity_received'    => 0,
        ]);
    }
    return $t->fresh('details.product');
}
```

- [ ] **Step 3: Commit**

```bash
git add back-end/tests/Feature/Transfer/TransferTestCase.php
git commit -m "test(transfer): add batch/serial/approved-transfer helpers to TransferTestCase"
```

---

## Phase 3 — Unified controller

The new `TransferController` becomes the single HTTP surface. Build its methods TDD-first, then swap routes (Task 9), then delete the old controllers (Task 13). Keep method names exactly as defined here.

### Task 4: Intent-aware `store` (create request)

**Files:**
- Rewrite: `back-end/app/Http/Controllers/Api/TransferController.php` — `store()`
- Test: `back-end/tests/Feature/Transfer/CreateRequestTest.php`

Rules: `pull` → `from_location_id` null, status `Pending`. `offload` → `from_location_id` = own location, `to_location_id` = suggested, status `Pending`. `demand` → both set, status `Approved` (IA self-verifies).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Transfer;

class CreateRequestTest extends TransferTestCase
{
    public function test_pull_request_has_null_source_and_pending_status(): void
    {
        $dst = $this->makeLocation();
        $product = $this->makeConsumable();
        $user = $this->makeUserAt($dst, 'inventory_analyst');

        $res = $this->actingAs($user)->postJson('/api/transfers', [
            'intent'         => 'pull',
            'to_location_id' => $dst->location_id,
            'transfer_date'  => '2026-07-22',
            'products'       => [['product_id' => $product->product_id, 'quantity_requested' => 4]],
        ]);

        $res->assertCreated();
        $this->assertNull($res->json('data.from_location_id'));
        $this->assertSame('pull', $res->json('data.intent'));
    }

    public function test_demand_request_lands_approved(): void
    {
        $src = $this->makeLocation();
        $dst = $this->makeLocation();
        $product = $this->makeConsumable();
        $user = $this->makeUserAt($dst, 'inventory_analyst');

        $res = $this->actingAs($user)->postJson('/api/transfers', [
            'intent'           => 'demand',
            'from_location_id' => $src->location_id,
            'to_location_id'   => $dst->location_id,
            'transfer_date'    => '2026-07-22',
            'products'         => [['product_id' => $product->product_id, 'quantity_requested' => 2]],
        ]);

        $res->assertCreated();
        $svc = app(\App\Services\TransferService::class);
        $this->assertSame($svc->statusIdForTest('Approved'), (int) $res->json('data.status_id'));
    }
}
```

> `makeUserAt($location, $role)` — add to `TransferTestCase` if absent, creating a user with a `user_locations` row and the given role (see `project_stack` memory: `user_locations` pivot).

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=CreateRequestTest`
Expected: FAIL (validation requires `from_location_id`, or 404 route not yet swapped — if route still points at old controller, do Task 9 route swap for `/transfers` POST first, or temporarily add the route).

- [ ] **Step 3: Implement `store`**

Replace `TransferController::store` with intent-aware logic:

```php
public function store(Request $request, TransferService $service)
{
    $validated = $request->validate([
        'intent'                         => 'required|in:pull,offload,demand',
        'from_location_id'               => 'nullable|exists:locations,location_id',
        'to_location_id'                 => 'required|exists:locations,location_id|different:from_location_id',
        'transfer_date'                  => 'required|date',
        'notes'                          => 'nullable|string|max:500',
        'products'                       => 'required|array|min:1',
        'products.*.product_id'          => 'required|exists:products,product_id',
        'products.*.quantity_requested'  => 'required|integer|min:1',
    ]);

    // Intent-specific source rules.
    if ($validated['intent'] === 'pull') {
        $validated['from_location_id'] = null;
    } elseif (empty($validated['from_location_id'])) {
        return $this->error('A source location is required for this transfer type.', 422);
    }

    $user = $request->user();

    return DB::transaction(function () use ($validated, $user, $service) {
        $status = $validated['intent'] === 'demand' ? 'Approved' : 'Pending';

        $transfer = Transfer::create([
            'transfer_request_id'        => null,
            'intent'                     => $validated['intent'],
            'from_location_id'           => $validated['from_location_id'],
            'to_location_id'             => $validated['to_location_id'],
            'transfer_number'            => 'TR-PENDING-' . uniqid('', true),
            'transfer_date'              => $validated['transfer_date'],
            'requested_by'               => $user->user_id,
            'approved_by'                => $validated['intent'] === 'demand' ? $user->user_id : null,
            'status_id'                  => $service->statusIdForTest($status),
            'notes'                      => $validated['notes'] ?? null,
            'total_quantity_transferred' => 0,
            'total_quantity_received'    => 0,
        ]);
        $transfer->transfer_number = 'TR-' . date('Ymd') . '-' . str_pad((string) $transfer->transfer_id, 4, '0', STR_PAD_LEFT);
        $transfer->save();

        foreach ($validated['products'] as $row) {
            $transfer->details()->create([
                'product_id'           => $row['product_id'],
                'quantity_requested'   => (int) $row['quantity_requested'],
                'quantity_scanned'     => 0,
                'quantity_transferred' => 0,
                'quantity_received'    => 0,
            ]);
        }

        \App\Models\TblTransferTracking::create([
            'transfer_id' => $transfer->transfer_id,
            'location_id' => $validated['to_location_id'],
            'status_note' => ucfirst($validated['intent']) . ' transfer request submitted',
            'recorded_by' => $user->user_id,
            'recorded_at' => now(),
        ]);

        \App\Support\RealtimeBroadcaster::transferChanged(
            (int) ($transfer->from_location_id ?? 0),
            (int) $transfer->to_location_id,
            'request_changed'
        );

        return $this->success($transfer->load(self::withRelations()), 'Transfer request submitted', 201);
    });
}
```

> Add a `private static function withRelations(): array` returning `['fromLocation','toLocation','requestedBy','approvedBy','status','details.product']` (mirror `TransferRequestController::WITH_RELATIONS`). Note `statusIdForTest` is the public resolver on `TransferService`; consider renaming it to `statusId(string): int` (public) during this task for readability and update its one existing caller in tests.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=CreateRequestTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/TransferController.php back-end/tests/Feature/Transfer/CreateRequestTest.php back-end/tests/Feature/Transfer/TransferTestCase.php
git commit -m "feat(transfer): intent-aware create (pull/offload/demand)"
```

### Task 5: `verify` (IA gate — trim lines, assign source, approve)

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/TransferController.php` — add `verify()` and `reject()`
- Test: `back-end/tests/Feature/Transfer/VerifyAssignTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Transfer;

class VerifyAssignTest extends TransferTestCase
{
    public function test_ia_assigns_source_trims_line_and_approves(): void
    {
        $src = $this->makeLocation();
        $dst = $this->makeLocation();
        $keep = $this->makeConsumable();
        $drop = $this->makeConsumable();
        $ia = $this->makeUserAt($dst, 'inventory_analyst');

        // A pull request (null source) with two lines.
        $ist = \App\Models\Transfer::create([
            'intent' => 'pull', 'from_location_id' => null, 'to_location_id' => $dst->location_id,
            'transfer_number' => 'TR-X', 'transfer_date' => now(),
            'status_id' => app(\App\Services\TransferService::class)->statusIdForTest('Pending'),
        ]);
        $keepLine = $ist->details()->create(['product_id' => $keep->product_id, 'quantity_requested' => 10]);
        $dropLine = $ist->details()->create(['product_id' => $drop->product_id, 'quantity_requested' => 5]);

        $res = $this->actingAs($ia)->postJson("/api/transfers/{$ist->transfer_id}/verify", [
            'from_location_id' => $src->location_id,
            'lines' => [
                ['transfer_detail_id' => $keepLine->transfer_detail_id, 'quantity_approved' => 6],
                ['transfer_detail_id' => $dropLine->transfer_detail_id, 'quantity_approved' => 0], // 0 => remove
            ],
        ]);

        $res->assertOk();
        $svc = app(\App\Services\TransferService::class);
        $ist->refresh();
        $this->assertSame($src->location_id, (int) $ist->from_location_id);
        $this->assertSame($svc->statusIdForTest('Approved'), (int) $ist->status_id);
        $this->assertDatabaseMissing('transfer_details', ['transfer_detail_id' => $dropLine->transfer_detail_id]);
        $this->assertSame(6, (int) $keepLine->fresh()->quantity_requested);
    }

    public function test_verify_requires_source_when_pull(): void
    {
        $dst = $this->makeLocation();
        $ia = $this->makeUserAt($dst, 'inventory_analyst');
        $ist = \App\Models\Transfer::create([
            'intent' => 'pull', 'from_location_id' => null, 'to_location_id' => $dst->location_id,
            'transfer_number' => 'TR-Y', 'transfer_date' => now(),
            'status_id' => app(\App\Services\TransferService::class)->statusIdForTest('Pending'),
        ]);
        $this->actingAs($ia)->postJson("/api/transfers/{$ist->transfer_id}/verify", [])
             ->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test --filter=VerifyAssignTest`
Expected: FAIL — route/method missing.

- [ ] **Step 3: Implement `verify` and `reject`**

```php
public function verify(Request $request, $id, TransferService $service)
{
    $validated = $request->validate([
        'from_location_id'                 => 'nullable|exists:locations,location_id',
        'to_location_id'                   => 'nullable|exists:locations,location_id',
        'lines'                            => 'sometimes|array',
        'lines.*.transfer_detail_id'       => 'required|integer',
        'lines.*.quantity_approved'        => 'required|integer|min:0',
    ]);

    $transfer = Transfer::with('details')->whereNull('transfer_request_id')->findOrFail($id);
    if ((int) $transfer->status_id !== $service->statusIdForTest('Pending')) {
        return $this->error('Only submitted requests can be verified.', 422);
    }

    return DB::transaction(function () use ($request, $validated, $transfer, $service) {
        $user = $request->user();

        if (array_key_exists('to_location_id', $validated) && $validated['to_location_id']) {
            $transfer->to_location_id = $validated['to_location_id'];
        }
        if (array_key_exists('from_location_id', $validated) && $validated['from_location_id']) {
            $transfer->from_location_id = $validated['from_location_id'];
        }
        if ($transfer->from_location_id === null) {
            throw new \Illuminate\Validation\ValidationException(
                validator([], []),
                response()->json(['message' => 'A source location must be assigned.'], 422)
            );
        }
        if ((int) $transfer->from_location_id === (int) $transfer->to_location_id) {
            return $this->error('Source and destination must differ.', 422);
        }

        foreach ($validated['lines'] ?? [] as $line) {
            $detail = TransferDetail::where('transfer_detail_id', $line['transfer_detail_id'])
                ->where('transfer_id', $transfer->transfer_id)->first();
            if (!$detail) continue;
            if ((int) $line['quantity_approved'] === 0) {
                $detail->delete();
            } else {
                $detail->quantity_requested = (int) $line['quantity_approved'];
                $detail->save();
            }
        }

        if ($transfer->details()->count() === 0) {
            return $this->error('At least one line must remain.', 422);
        }

        $transfer->approved_by = $user->user_id;
        $transfer->status_id   = $service->statusIdForTest('Approved');
        $transfer->save();

        \App\Models\TblTransferTracking::create([
            'transfer_id' => $transfer->transfer_id,
            'location_id' => $transfer->from_location_id,
            'status_note' => "Verified & assigned to source by {$user->username}",
            'recorded_by' => $user->user_id,
            'recorded_at' => now(),
        ]);

        \App\Support\RealtimeBroadcaster::transferChanged(
            (int) $transfer->from_location_id, (int) $transfer->to_location_id, 'request_changed'
        );

        return $this->success($transfer->fresh()->load(self::withRelations()), 'Transfer verified and assigned');
    });
}

public function reject(Request $request, $id, TransferService $service)
{
    $validated = $request->validate(['rejection_reason' => 'nullable|string|max:500']);
    $transfer = Transfer::whereNull('transfer_request_id')->findOrFail($id);
    if ((int) $transfer->status_id !== $service->statusIdForTest('Pending')) {
        return $this->error('Only submitted requests can be rejected.', 422);
    }
    $transfer->status_id = $service->statusIdForTest('Rejected');
    if (!empty($validated['rejection_reason'])) {
        $transfer->notes = trim("REJECTED: {$validated['rejection_reason']}\n\n" . ($transfer->notes ?? ''));
    }
    $transfer->save();
    \App\Support\RealtimeBroadcaster::transferChanged(
        (int) ($transfer->from_location_id ?? 0), (int) $transfer->to_location_id, 'request_changed'
    );
    return $this->success($transfer->fresh()->load(self::withRelations()), 'Transfer request rejected');
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test --filter=VerifyAssignTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/TransferController.php back-end/tests/Feature/Transfer/VerifyAssignTest.php
git commit -m "feat(transfer): IA verify/assign gate with line trimming"
```

### Task 6: `pickList` endpoint

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/TransferController.php` — add `pickList()`
- Test: `back-end/tests/Feature/Transfer/PickListEndpointTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Transfer;

class PickListEndpointTest extends TransferTestCase
{
    public function test_pick_list_endpoint_returns_fifo_suggestions(): void
    {
        $src = $this->makeLocation();
        $dst = $this->makeLocation();
        $product = $this->makeConsumable();
        $this->makeBatch($product, $src, ['zone_label' => 'A-01', 'quantity_remaining' => 5, 'received_at' => '2026-01-01']);
        $ist = $this->makeApprovedTransfer($src, $dst, [[$product, 3]]);
        $user = $this->makeUserAt($src, 'warehouse_personnel');

        $res = $this->actingAs($user)->getJson("/api/transfers/{$ist->transfer_id}/pick-list");
        $res->assertOk();
        $res->assertJsonPath('data.0.suggestions.0.zone_label', 'A-01');
        $res->assertJsonPath('data.0.suggestions.0.quantity', 3);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd back-end && php artisan test --filter=PickListEndpointTest`
Expected: FAIL.

- [ ] **Step 3: Implement `pickList` action**

```php
public function pickList($id, TransferService $service)
{
    $transfer = Transfer::with('details.product')->whereNull('transfer_request_id')->findOrFail($id);
    return $this->success($service->pickList($transfer), 'Pick list generated');
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `cd back-end && php artisan test --filter=PickListEndpointTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/TransferController.php back-end/tests/Feature/Transfer/PickListEndpointTest.php
git commit -m "feat(transfer): GET /transfers/{id}/pick-list endpoint"
```

### Task 7: Move dispatch/receive scan actions onto `TransferController`

The scan/dispatch/receive HTTP glue currently lives in `ISTController` (`scan`, `removeScan`, `confirmDispatch`, `scanStatus`, `receiveScan`, `removeReceiveScan`, `receiveStore`, `receipt`, `receiptPdf`). Move these onto `TransferController`, unchanged in behavior (they already call `TransferService`).

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/TransferController.php`
- Read: `back-end/app/Http/Controllers/Api/ISTController.php` (source of the methods)
- Test: `back-end/tests/Feature/Transfer/DispatchReceiveHttpTest.php`

- [ ] **Step 1: Read the ISTController methods to copy**

Run: `cd back-end && sed -n '348,590p' app/Http/Controllers/Api/ISTController.php`
Expected: shows `receiveScan`, `removeReceiveScan`, `scanStatus`, `scan`, `removeScan`, `confirmDispatch`, `receipt`, `receiptPdf` bodies.

- [ ] **Step 2: Write a smoke test covering the moved endpoints under `/transfers`**

```php
<?php

namespace Tests\Feature\Transfer;

class DispatchReceiveHttpTest extends TransferTestCase
{
    public function test_dispatch_scan_then_confirm_moves_to_in_transit(): void
    {
        $src = $this->makeLocation();
        $dst = $this->makeLocation();
        $product = $this->makeConsumable();
        $this->makeBatch($product, $src, ['zone_label' => 'A', 'quantity_remaining' => 5, 'received_at' => '2026-01-01']);
        $this->seedInventory($product, $src, 5); // helper: inventory row qty_on_hand/available = 5
        $ist = $this->makeApprovedTransfer($src, $dst, [[$product, 2]]);
        $user = $this->makeUserAt($src, 'warehouse_personnel');

        $barcode = $product->barcode ?? 'BC-' . $product->product_id;
        $this->actingAs($user)->postJson("/api/transfers/{$ist->transfer_id}/scan", ['barcode' => $barcode, 'quantity' => 2])->assertOk();
        $res = $this->actingAs($user)->postJson("/api/transfers/{$ist->transfer_id}/confirm-dispatch");
        $res->assertOk();

        $svc = app(\App\Services\TransferService::class);
        $this->assertSame($svc->statusIdForTest('In Transit'), (int) $ist->fresh()->status_id);
    }
}
```

> Add `seedInventory($product, $location, $qty)` to `TransferTestCase` if absent (creates an `inventory` row with `quantity_on_hand` and `available_quantity` set). Barcode resolution must match the consumable's `barcode`.

- [ ] **Step 3: Run to verify it fails**

Run: `cd back-end && php artisan test --filter=DispatchReceiveHttpTest`
Expected: FAIL (routes not yet on `/transfers`).

- [ ] **Step 4: Add the methods to `TransferController`**

Copy `scan`, `removeScan`, `confirmDispatch`, `scanStatus`, `receiveScan`, `removeReceiveScan`, `receiveStore`, `receipt`, `receiptPdf`, `receiveForm`, `prepareFromRequest` from `ISTController` verbatim (they already delegate to `TransferService`). Rename none. Ensure `use App\Services\TransferService;` is imported.

- [ ] **Step 5: Add the routes (temporary parallel routes; full swap in Task 9)**

In `back-end/routes/api.php`, inside the authenticated group, add under the transfers block:

```php
Route::get('/transfers/{id}/pick-list', [TransferController::class, 'pickList']);
Route::get('/transfers/{id}/scan-status', [TransferController::class, 'scanStatus']);
Route::middleware('permission:inventory_transfers,create')->group(function () {
    Route::post('/transfers/{id}/scan', [TransferController::class, 'scan']);
    Route::delete('/transfers/{id}/scan/{scanId}', [TransferController::class, 'removeScan']);
    Route::post('/transfers/{id}/confirm-dispatch', [TransferController::class, 'confirmDispatch']);
});
Route::middleware('permission:inventory_transfers,approve')->group(function () {
    Route::post('/transfers/{id}/verify', [TransferController::class, 'verify']);
    Route::patch('/transfers/{id}/reject', [TransferController::class, 'reject']);
});
Route::middleware('permission:inventory_transfers,update')->group(function () {
    Route::post('/transfers/{id}/receive-scan', [TransferController::class, 'receiveScan']);
    Route::delete('/transfers/{id}/receive-scan/{scanId}', [TransferController::class, 'removeReceiveScan']);
    Route::post('/transfers/{id}/receive-confirm', [TransferController::class, 'receiveStore']);
});
Route::get('/transfers/{id}/receipt', [TransferController::class, 'receipt']);
```

> Ensure the static `/transfers/archived` route stays declared BEFORE `/transfers/{id}` so it isn't captured as an id (it already is at L668).

- [ ] **Step 6: Run to verify it passes**

Run: `cd back-end && php artisan test --filter=DispatchReceiveHttpTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/TransferController.php back-end/routes/api.php back-end/tests/Feature/Transfer/DispatchReceiveHttpTest.php back-end/tests/Feature/Transfer/TransferTestCase.php
git commit -m "feat(transfer): move dispatch/receive HTTP actions onto unified controller"
```

### Task 8: Unified `index` with status-tab + scope, and `show` with availability

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/TransferController.php` — `index()`, `show()`
- Test: `back-end/tests/Feature/Transfer/WorklistScopeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Transfer;

class WorklistScopeTest extends TransferTestCase
{
    public function test_warehouse_sees_only_its_transfers_filtered_by_status(): void
    {
        $wh = $this->makeLocation();
        $other = $this->makeLocation();
        $product = $this->makeConsumable();
        $mine = $this->makeApprovedTransfer($wh, $other, [[$product, 1]]);     // wh is source
        $notMine = $this->makeApprovedTransfer($other, $this->makeLocation(), [[$product, 1]]);
        $user = $this->makeUserAt($wh, 'warehouse_personnel');

        $svc = app(\App\Services\TransferService::class);
        $res = $this->actingAs($user)->getJson('/api/transfers?status_id=' . $svc->statusIdForTest('Approved'));
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('transfer_id');
        $this->assertTrue($ids->contains($mine->transfer_id));
        $this->assertFalse($ids->contains($notMine->transfer_id));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd back-end && php artisan test --filter=WorklistScopeTest`
Expected: FAIL if current `index` doesn't scope/paginate as expected.

- [ ] **Step 3: Implement `index` and `show`**

`index`: list ALL transfers (drop the old `whereNull('transfer_request_id')`-only split — everything is one row now), `->forUser($user)` scoping, optional `status_id` filter, optional `search` on `transfer_number`, `->with(self::withRelations())`, `orderBy('created_at','desc')->paginate($perPage)`. `show`: eager-load relations + `transferTracking.recordedBy`, and attach per-line `available_at_source` (from `Inventory` at `from_location_id`) exactly as the old `TransferRequestController::show` did (guard for null source → availability 0). Return via `$this->success(...)`.

- [ ] **Step 4: Run to verify it passes**

Run: `cd back-end && php artisan test --filter=WorklistScopeTest`
Expected: PASS.

- [ ] **Step 5: Run the full transfer suite (regression)**

Run: `cd back-end && php artisan test --filter=Transfer`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/TransferController.php back-end/tests/Feature/Transfer/WorklistScopeTest.php
git commit -m "feat(transfer): unified worklist index + availability-annotated show"
```

---

## Phase 4 — Route cleanup & engine trim

### Task 9: Retire old routes; delete `directOverride`

**Files:**
- Modify: `back-end/routes/api.php` (IST block, transfer-requests block, transfers ERD block, query-token receipt route)
- Modify: `back-end/app/Services/TransferService.php` — delete `directOverride`
- Test: `back-end/tests/Feature/Transfer/RetiredRoutesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Transfer;

class RetiredRoutesTest extends TransferTestCase
{
    public function test_old_ist_and_request_routes_are_gone(): void
    {
        $user = $this->makeUserAt($this->makeLocation(), 'inventory_analyst');
        $this->actingAs($user)->getJson('/api/ist')->assertNotFound();
        $this->actingAs($user)->getJson('/api/transfer-requests')->assertNotFound();
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd back-end && php artisan test --filter=RetiredRoutesTest`
Expected: FAIL (routes still exist).

- [ ] **Step 3: Remove the old route blocks**

Delete from `back-end/routes/api.php`: the entire IST block (`/ist...` routes ~L627-647), the transfer-requests block (~L655-660), and the old mutation routes on `/transfers` that duplicate moved actions — keep `GET /transfers`, `GET /transfers/{id}`, `GET /transfers/archived`, `POST /transfers` (store), and the new action routes from Task 7. Remove `PUT /transfers/{id}` (update), `POST /transfers/{id}/receive` (old), `POST /transfers/{id}/tracking`, and the destroy/restore/force routes ONLY IF unused by the new UI (keep archive/restore if the worklist keeps an archive view — see Task 12; otherwise remove). Update the query-token receipt route (~L904) to point at `TransferController@receiptPdf` with path `/transfers/{id}/receipt/pdf`. Remove the now-unused `use ... ISTController;` and `use ... TransferRequestController;` imports.

- [ ] **Step 4: Delete `directOverride` from `TransferService`**

Remove the `directOverride(...)` method entirely. Run: `cd back-end && grep -rn "directOverride" app tests routes` — expected: no remaining references (delete any test that only covered it, or convert it to a `demand` create test).

- [ ] **Step 5: Run to verify it passes + full suite**

Run: `cd back-end && php artisan test --filter=RetiredRoutesTest && php artisan test --filter=Transfer`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add back-end/routes/api.php back-end/app/Services/TransferService.php back-end/tests
git commit -m "refactor(transfer): retire IST/transfer-requests routes and directOverride"
```

---

## Phase 5 — Frontend: one unified page

The old pages are large. Build the new page fresh, wire it, then delete the old pages/API groups. Prod build (`npm run demo`) is how the client runs; dev server is slow (per `project_perf_dev_vs_prod`).

### Task 10: Collapse the transfer API groups

**Files:**
- Modify: `frontend/src/admin/services/api.js` — replace `transfersAPI` (L872), remove `istAPI` (L897) and `transferRequestsAPI` (L923)

- [ ] **Step 1: Replace with a single `transfersAPI`**

```js
export const transfersAPI = {
  list: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/transfers?${query}` : '/transfers');
  },
  getById:        async (id)          => apiRequest(`/transfers/${id}`),
  create:         async (data)        => apiRequest('/transfers', { method: 'POST', body: JSON.stringify(data) }),
  verify:         async (id, data)    => apiRequest(`/transfers/${id}/verify`, { method: 'POST', body: JSON.stringify(data) }),
  reject:         async (id, data)    => apiRequest(`/transfers/${id}/reject`, { method: 'PATCH', body: JSON.stringify(data) }),
  pickList:       async (id)          => apiRequest(`/transfers/${id}/pick-list`),
  scanStatus:     async (id)          => apiRequest(`/transfers/${id}/scan-status`),
  scan:           async (id, data)    => apiRequest(`/transfers/${id}/scan`, { method: 'POST', body: JSON.stringify(data) }),
  removeScan:     async (id, scanId)  => apiRequest(`/transfers/${id}/scan/${scanId}`, { method: 'DELETE' }),
  confirmDispatch:async (id)          => apiRequest(`/transfers/${id}/confirm-dispatch`, { method: 'POST' }),
  receiveScan:    async (id, data)    => apiRequest(`/transfers/${id}/receive-scan`, { method: 'POST', body: JSON.stringify(data) }),
  removeReceiveScan: async (id, scanId) => apiRequest(`/transfers/${id}/receive-scan/${scanId}`, { method: 'DELETE' }),
  receiveConfirm: async (id, data)    => apiRequest(`/transfers/${id}/receive-confirm`, { method: 'POST', body: JSON.stringify(data ?? {}) }),
  receipt:        async (id)          => apiRequest(`/transfers/${id}/receipt`),
  receiptPdfUrl:  (id, token) => `${getApiBaseUrl()}/transfers/${id}/receipt/pdf${token ? `?token=${encodeURIComponent(token)}` : ''}`,
};
```

Delete the `istAPI` and `transferRequestsAPI` exports. Grep for their usages next.

- [ ] **Step 2: Find all usages that must migrate**

Run: `grep -rn "istAPI\|transferRequestsAPI" frontend/src`
Expected: a list — every hit gets rewired to `transfersAPI` in later tasks (or in the pages being deleted). Note them.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -m "refactor(transfer): unify transfer API into one transfersAPI"
```

### Task 11: Sidebar — one Transfers nav item

**Files:**
- Modify: `frontend/src/admin/components/Sidebar.jsx` (L30-32 lazy map, L84-86 nav items, L473 badge)

- [ ] **Step 1: Collapse the lazy map and nav items**

Replace the three lazy-import entries (L30-32) with one:

```js
'/admin/transfers': () => import('../pages/TransfersPage'),
```

Replace the three nav items (L84-86) with one:

```js
{ name: 'Transfers', path: '/admin/transfers', icon: 'transfer' },
```

Update the badge block at L473 to key off `/admin/transfers` (rename `awaitingIstCount` usage to the unified count; keep the same data source that feeds it).

- [ ] **Step 2: Verify the app compiles**

Run: `cd frontend && npm run build 2>&1 | tail -20`
Expected: build succeeds (it will still fail if `TransfersPage` doesn't exist yet — create a stub in Task 12 first, or do Task 12 before building).

- [ ] **Step 3: Commit** (after Task 12 stub exists)

```bash
git add frontend/src/admin/components/Sidebar.jsx
git commit -m "feat(transfer): single Transfers sidebar entry"
```

### Task 12: `TransfersPage` — role-aware worklist + tabs

**Files:**
- Create: `frontend/src/admin/pages/TransfersPage.jsx`
- Create: `frontend/src/admin/styles/transfers.css` (reuse `airking_ds.css` `ak-` primitives per `project_design_system`)
- Reference: routing registration for `/admin/transfers` (already via Sidebar lazy map; confirm the route element is rendered where other `/admin/*` pages are — check `AdminLayout`/router).

Responsibilities (keep this file focused; extract sub-components if it grows past ~400 lines):
- Header with `+ New Request` button and status tabs: `To Verify` (Pending), `To Send` (Approved), `To Receive`/`In Transit`, `Done` (Received+Rejected).
- Tab visibility by role/permission: hide `To Verify` unless the user has `inventory_transfers,approve`; everyone in scope sees the rest. Use the existing `useAuth()` + permission helper the other pages use (grep an existing page for how it reads permissions).
- Fetch via `transfersAPI.list({ status_id })`; rows show `transfer_number`, `from → to` (or "unassigned" when source null), intent chip, status badge, and a contextual action button that opens the detail drawer/modal.

- [ ] **Step 1: Create a minimal stub so the build passes**

```jsx
import React from 'react';
const TransfersPage = () => <div className="ak-page"><h1>Transfers</h1></div>;
export default TransfersPage;
```

- [ ] **Step 2: Build to confirm wiring**

Run: `cd frontend && npm run build 2>&1 | tail -20`
Expected: build succeeds; `/admin/transfers` renders the stub.

- [ ] **Step 3: Implement the worklist (tabs, fetch, rows)**

Flesh out `TransfersPage.jsx`: tab state, `transfersAPI.list`, role-filtered tabs, row rendering, and a selected-transfer detail panel with the stage-specific action (Verify / Dispatch / Receive / View). Reuse toast + confirm helpers (`project_ui_feedback_conventions`). Keep FIFO/scan sub-flows in their own components (Tasks 13-15).

- [ ] **Step 4: Manual verify in prod build**

Run: `cd frontend && npm run demo` then log in as an IA and a warehouse user; confirm each role sees only its actionable tabs and location-scoped rows.
Expected: IA sees `To Verify`; warehouse sees `To Send`/`To Receive` for its own location only.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/pages/TransfersPage.jsx frontend/src/admin/styles/transfers.css frontend/src/admin/components/Sidebar.jsx
git commit -m "feat(transfer): unified role-aware Transfers worklist page"
```

### Task 13: New Request modal (3 intents)

**Files:**
- Create: `frontend/src/admin/pages/transfers/NewRequestModal.jsx`
- Modify: `frontend/src/admin/pages/TransfersPage.jsx` (mount the modal)

- [ ] **Step 1: Implement the intent selector + product picker**

Intent radio: Pull / Offload / IA-demand (Offload+demand product picker sources from the location's own inventory; Pull sources from the master catalog; demand shows both location selects, visible only to IA/admin). Submit via `transfersAPI.create({ intent, from_location_id, to_location_id, transfer_date, notes, products })`.

- [ ] **Step 2: Manual verify**

Run: `cd frontend && npm run demo`; create one of each intent; confirm pull row appears with source "unassigned" under `To Verify`, and demand appears directly under `To Send`.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/pages/transfers/NewRequestModal.jsx frontend/src/admin/pages/TransfersPage.jsx
git commit -m "feat(transfer): new-request modal with pull/offload/demand intents"
```

### Task 14: IA Verify/Assign panel

**Files:**
- Create: `frontend/src/admin/pages/transfers/VerifyPanel.jsx`

- [ ] **Step 1: Implement**

Shows request lines with editable quantities and a remove (set 0) control, a source-warehouse selector backed by per-product availability (call `transfersAPI.getById` which now returns `available_at_source`; for choosing among warehouses, reuse the inventory availability endpoint the PR/PO forms use — grep `available` in `api.js`). Submit `transfersAPI.verify(id, { from_location_id, to_location_id, lines })`; Reject via `transfersAPI.reject`.

- [ ] **Step 2: Manual verify**

Create a pull request, open Verify as IA, trim a line, assign a source, submit → confirm it moves to `To Send` and the assigned warehouse user now sees it.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/pages/transfers/VerifyPanel.jsx
git commit -m "feat(transfer): IA verify/assign panel"
```

### Task 15: FIFO dispatch (pick list + scan) and Receive panels

**Files:**
- Create: `frontend/src/admin/pages/transfers/DispatchPanel.jsx`
- Create: `frontend/src/admin/pages/transfers/ReceivePanel.jsx`

- [ ] **Step 1: Dispatch panel**

On open, call `transfersAPI.pickList(id)` and render, per line, the FIFO suggestions (serial + zone for appliances; zone + qty for consumables) as guidance. Provide a scan/manual-entry input calling `transfersAPI.scan(id, { barcode, quantity })`, live per-line progress via `transfersAPI.scanStatus(id)`, remove-scan, and a Confirm Dispatch button (`transfersAPI.confirmDispatch(id)`). Reuse the barcode camera hook (`useBarcodeCamera.js`) as the existing IST scan flow did.

- [ ] **Step 2: Receive panel**

Mirror dispatch: `receiveScan`, `removeReceiveScan`, `receiveConfirm`, and a receipt link (`transfersAPI.receiptPdfUrl(id, token)`).

- [ ] **Step 3: Manual verify end-to-end**

Run `npm run demo`. Full flow: pull request → IA verify/assign → warehouse dispatch (pick list shows oldest zone first, scan 2 units, confirm) → destination receive → receipt. Confirm source/destination inventory counts and serial locations are correct.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/pages/transfers/DispatchPanel.jsx frontend/src/admin/pages/transfers/ReceivePanel.jsx frontend/src/admin/pages/TransfersPage.jsx
git commit -m "feat(transfer): FIFO dispatch pick-list + receive panels"
```

### Task 16: Delete old pages + dangling references

**Files:**
- Delete: `frontend/src/admin/pages/TransferRequestPage.jsx`, `StockTransferManagement.jsx`, `ISTManagementPage.jsx`, `ISTCreatePage.jsx`, `ISTDetailPage.jsx`, `ISTDispatchPage.jsx`
- Modify: `frontend/src/admin/pages/BarcodeScan.jsx` (remove the embedded IST receive flow + the `/admin/ist` link at L3725)
- Modify: any router file registering the deleted routes; `frontend/src/admin/utils/alertTarget.js` already points transfers at `/admin/transfers` (good — verify).

- [ ] **Step 1: Grep for every reference to deleted pages/routes**

Run: `grep -rn "TransferRequestPage\|StockTransferManagement\|ISTManagementPage\|ISTCreatePage\|ISTDetailPage\|ISTDispatchPage\|/admin/ist\|/admin/transfer-requests" frontend/src`
Expected: a list. Rewire each to `/admin/transfers` or remove.

- [ ] **Step 2: Delete the page files and remove references**

Delete the six page files; strip the IST block from `BarcodeScan.jsx`; remove old router registrations.

- [ ] **Step 3: Build to confirm no broken imports**

Run: `cd frontend && npm run build 2>&1 | tail -20`
Expected: build succeeds with no unresolved imports.

- [ ] **Step 4: Commit**

```bash
git add -A frontend/src
git commit -m "refactor(transfer): delete legacy transfer/IST pages and references"
```

---

## Phase 6 — Permissions, nav bundles, and final verification

### Task 17: Nav-bundle / permission rows for the single nav

**Files:**
- Modify: the nav-bundle seeder (grep `NavBundleSeeder`) and any `roles.js`/permission mirror that lists `/admin/transfer-requests`, `/admin/transfers`, `/admin/ist`.

- [ ] **Step 1: Find the nav/permission definitions**

Run: `grep -rn "transfer-requests\|/admin/ist\|Interbranch\|Stock Transfers" back-end/database frontend/src/admin`
Expected: seeder + frontend mirror entries.

- [ ] **Step 2: Collapse to one `Transfers` entry**

Replace the three nav paths with the single `/admin/transfers` (permission slug stays `inventory_transfers`). Re-run the seeder on dev: `cd back-end && php artisan db:seed --class=NavBundleSeeder` (per `project_xampp_db_recovery`).

- [ ] **Step 3: Commit**

```bash
git add back-end/database frontend/src/admin
git commit -m "chore(transfer): collapse nav bundles to single Transfers entry"
```

### Task 18: Full regression + spec self-check

- [ ] **Step 1: Backend suite**

Run: `cd back-end && php artisan test`
Expected: green (or only pre-existing unrelated failures — compare against a baseline run on the parent branch).

- [ ] **Step 2: Frontend build + jest**

Run: `cd frontend && npm run build && npm test -- --watchAll=false 2>&1 | tail -20`
Expected: build succeeds; jest green.

- [ ] **Step 3: Manual smoke of all three intents**

Run `npm run demo`; exercise pull, offload, and demand end-to-end; confirm realtime badge fires for IA on new request and for the assigned warehouse on assignment.

- [ ] **Step 4: Update memory**

Update `project_transfer_redesign.md` (or add a new memory) noting the consolidation is implemented on `backupV3`: one nav, `transfers.intent`, nullable source, `pickList`, unified `TransferController`, `directOverride` removed.

- [ ] **Step 5: Final commit**

```bash
git add -A
git commit -m "test(transfer): full regression pass for consolidated module"
```

---

## Self-review notes (author)

- **Spec coverage:** one nav (T11,12,17); three intents (T4,13); IA verify/assign+trim (T5,14); FIFO pick list w/ serials+zones (T3,6,15); suggest-enforce-by-count (reuses existing engine, T7); role-aware worklist+scope (T8,12); notifications (reuse `RealtimeBroadcaster`, T4,5); drop express override (T9); nullable source + intent column (T1,2); retire routes/pages (T9,16). All spec sections map to tasks.
- **Engine reuse:** dispatch/receive/reverse/FIFO accounting untouched — only HTTP glue relocated (T7) and `pickList` added (T3).
- **Consistency:** public status resolver referenced as `statusIdForTest` throughout; T4 suggests renaming to `statusId` — if done, update ALL test references in one step. `withRelations()` helper defined in T4 and reused in T5/T8.
- **Risk flags:** (1) confirm `from_location_id` real column type before the `MODIFY` in T1. (2) The `directOverride` deletion (T9) may have dedicated tests — convert or delete them. (3) Frontend page tasks (T12-15) are structural, not fully-coded, because they rebuild large React views; each has a concrete manual-verification step since these views aren't unit-tested today.
