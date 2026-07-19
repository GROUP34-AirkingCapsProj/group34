# PR/PO History Tabs & PO Receiving Receipt Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the Purchase Request and Purchase Order pages a per-record History tab (an expandable lifecycle timeline built from existing audit data), and produce a printable + downloadable Goods Received Note for each PO receiving session.

**Architecture:** A new `RecordHistoryService` folds existing `audit_trail` rows for one record into curated milestones; thin controller methods on the PO/PR controllers add resource-specific milestones (receivings, PR→PO conversion) and expose `/…/{id}/history`. A `ReceivingController::receiptPdf`/`receiptCsv` pair mirrors the existing `ISTController` receipt pattern using DomPDF + a new blade view. Frontend adds a shared `RecordHistoryTimeline` component + History tabs, and a `ReceivingReceipt` modal wired to the download endpoints.

**Tech Stack:** Laravel 11, PHPUnit (DatabaseTransactions against `airkingV5_test`), barryvdh/laravel-dompdf, React (CRA), existing `ak-` design system + `.po-tab-btn` tabs.

**Reference facts (verified against the codebase):**
- Audit rows: `audit_trail` table, model `App\Models\AuditTrail`, casts `old_values`/`new_values` to array, `user()` relation. Columns: `audit_id, user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at`.
- `Auditable` already resolves FK ids to labels inside `old_values`/`new_values` (e.g. `status_id` → status name, `approved_by` → full name). So milestone change rows can use those values directly.
- PO table `purchase_orders`, model `PurchaseOrder` (uses `Auditable`), has `pr_id`/`purchaseRequest` relation and `receivings` HasMany. Existing `show()` guarded by no extra permission (bare group), `index()` applies `isCentralized()` location scoping.
- PR table `purchase_requests`, model `PurchaseRequest` (uses `Auditable`), has `converted_to_po_id` + `convertedPO()` relation, `pr_number`. `show()` guarded by `permission:purchase_request_creation,read`.
- Receiving model `Receiving`: `receiving_number`, `receiving_date`, `total_quantity_received`, `total_quantity_damaged`, relations `purchaseOrder`, `supplier`, `location`, `receivedBy`, `details` (each `ReceivingDetail` has `product_id`, `quantity_amount`, `prod_price`, `condition`, `scanned_barcode`, relation `product`).
- Download route pattern (browser download w/ query token): `Route::middleware(['auth.query.token', 'permission:<perm>,read'])->get(...)` at the bottom of `routes/api.php`. Frontend builds URL as `${getApiBaseUrl()}/…?token=${encodeURIComponent(getToken())}`.
- DomPDF usage: `\Barryvdh\DomPDF\Facade\Pdf::loadView('view', [...])->download('name.pdf')`.
- Receiving permission slug: `delivery_receiving`. PDF blade reference model: `resources/views/ist/receipt.blade.php`.
- Tests: extend `Tests\TestCase`, `use DatabaseTransactions`, seed `PermissionSeeder` + `RolePermissionsSeeder`, create users via `Role`+`User::forceCreate`. Auth in tests via `Sanctum::actingAs($user)` or `$this->actingAs($user, 'sanctum')`.

---

## File Structure

**Backend (create):**
- `app/Services/History/RecordHistoryService.php` — generic audit→milestone folder.
- `resources/views/receiving/receipt.blade.php` — GRN document.
- `tests/Feature/History/RecordHistoryTest.php` — PO/PR history endpoint tests.
- `tests/Feature/History/ReceivingReceiptTest.php` — receipt endpoint tests.

**Backend (modify):**
- `app/Http/Controllers/Api/PurchaseOrderController.php` — add `history()`.
- `app/Http/Controllers/Api/PurchaseRequestController.php` — add `history()`.
- `app/Http/Controllers/Api/ReceivingController.php` — add `receiptPdf()`, `receiptCsv()`.
- `routes/api.php` — add history routes (in existing groups) + receipt download routes (bottom block).

**Frontend (create):**
- `frontend/src/admin/components/RecordHistoryTimeline.jsx` — shared timeline.
- `frontend/src/admin/components/ReceivingReceipt.jsx` — on-screen GRN modal + print/download.
- `frontend/src/admin/styles/record_history.css` — timeline + print styles.

**Frontend (modify):**
- `frontend/src/admin/services/api.js` — `purchaseOrdersAPI.history`, `purchaseRequestsAPI.history`, `receivingsAPI.receiptPdfUrl`, `receivingsAPI.receiptCsvUrl`.
- `frontend/src/admin/pages/PurchaseOrderPage.jsx` — History tab + receipt button on receiving sessions.
- `frontend/src/admin/pages/PurchaseRequestPage.jsx` — History tab.
- `frontend/src/admin/pages/BarcodeScan.jsx` — "View / Print receipt" action after a receiving session saves.

---

## Task 1: RecordHistoryService — fold audit rows into milestones

**Files:**
- Create: `back-end/app/Services/History/RecordHistoryService.php`
- Test: `back-end/tests/Feature/History/RecordHistoryTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/History/RecordHistoryTest.php`:

```php
<?php

namespace Tests\Feature\History;

use App\Models\AuditTrail;
use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\User;
use App\Services\History\RecordHistoryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RecordHistoryTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(): User
    {
        $role = Role::firstOrCreate(['role_name' => 'admin'], ['description' => 'System Administrator']);
        $statusId = StatusLookup::query()->value('status_id')
            ?? StatusLookup::forceCreate(['status_name' => 'Active', 'status_category' => 'user'])->status_id;
        return User::forceCreate([
            'first_name' => 'Aud', 'last_name' => 'Itor',
            'email' => 'hist_' . uniqid() . '@test.local',
            'username' => 'hist_' . uniqid(),
            'password_hash' => Hash::make('password'),
            'role_id' => $role->role_id, 'status_id' => $statusId,
        ]);
    }

    public function test_folds_create_and_status_change_into_ordered_milestones(): void
    {
        $u = $this->makeUser();

        AuditTrail::create([
            'user_id' => $u->user_id, 'action' => 'create',
            'table_name' => 'purchase_orders', 'record_id' => 999,
            'new_values' => ['po_number' => 'PO-TST-1', 'status_id' => 'Pending'],
            'created_at' => now()->subDays(3),
        ]);
        AuditTrail::create([
            'user_id' => $u->user_id, 'action' => 'update',
            'table_name' => 'purchase_orders', 'record_id' => 999,
            'old_values' => ['status_id' => 'Pending'],
            'new_values' => ['status_id' => 'Approved'],
            'created_at' => now()->subDays(1),
        ]);

        $milestones = app(RecordHistoryService::class)->forRecord('purchase_orders', 999);

        $this->assertCount(2, $milestones);
        $this->assertSame('created', $milestones[0]['type']);
        $this->assertSame('status', $milestones[1]['type']);
        $this->assertSame('Approved', $milestones[1]['label']);
        $this->assertSame($u->first_name . ' ' . $u->last_name, $milestones[1]['actor_name']);
        $this->assertSame('status', $milestones[1]['changes'][0]['field']);
        $this->assertSame('Pending', $milestones[1]['changes'][0]['from']);
        $this->assertSame('Approved', $milestones[1]['changes'][0]['to']);
    }

    public function test_verification_status_change_becomes_verified_milestone(): void
    {
        $u = $this->makeUser();
        AuditTrail::create([
            'user_id' => $u->user_id, 'action' => 'update',
            'table_name' => 'purchase_orders', 'record_id' => 998,
            'old_values' => ['verification_status' => 'unverified'],
            'new_values' => ['verification_status' => 'verified'],
            'created_at' => now(),
        ]);

        $milestones = app(RecordHistoryService::class)->forRecord('purchase_orders', 998);

        $this->assertSame('verified', $milestones[0]['type']);
        $this->assertSame('Verified', $milestones[0]['label']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/History/RecordHistoryTest.php`
Expected: FAIL — `Class "App\Services\History\RecordHistoryService" not found`.

- [ ] **Step 3: Write the service**

Create `back-end/app/Services/History/RecordHistoryService.php`:

```php
<?php

namespace App\Services\History;

use App\Models\AuditTrail;

class RecordHistoryService
{
    /** Fields that never generate a milestone or a change row. */
    private const SKIP = ['created_at', 'updated_at', 'deleted_at', 'laravel_through_key'];

    /**
     * Fold this record's audit rows into an ordered list of curated milestones.
     *
     * @return array<int, array{type:string,label:string,actor_name:string,timestamp:?string,changes:array}>
     */
    public function forRecord(string $tableName, int $recordId): array
    {
        $rows = AuditTrail::with('user')
            ->where('table_name', $tableName)
            ->where('record_id', $recordId)
            ->orderBy('created_at', 'asc')
            ->orderBy('audit_id', 'asc')
            ->get();

        $milestones = [];
        foreach ($rows as $row) {
            $milestones[] = $this->rowToMilestone($row);
        }
        return $milestones;
    }

    private function rowToMilestone(AuditTrail $row): array
    {
        $old = $row->old_values ?? [];
        $new = $row->new_values ?? [];
        $changes = $this->buildChanges($old, $new);

        [$type, $label] = $this->classify($row->action, $old, $new);

        return [
            'type'       => $type,
            'label'      => $label,
            'actor_name' => $this->actorName($row),
            'timestamp'  => $row->created_at?->toIso8601String(),
            'changes'    => $changes,
        ];
    }

    /** @return array{0:string,1:string} [type, label] */
    private function classify(string $action, array $old, array $new): array
    {
        if ($action === 'create') {
            return ['created', 'Created'];
        }
        if ($action === 'delete') {
            return ['archived', 'Archived'];
        }

        // update — inspect what meaningfully changed
        if (array_key_exists('status_id', $new)) {
            return ['status', 'Status → ' . $new['status_id']];
        }
        if (array_key_exists('verification_status', $new)) {
            $v = strtolower((string) $new['verification_status']);
            if ($v === 'verified') return ['verified', 'Verified'];
            if ($v === 'rejected') return ['rejected', 'Rejected'];
            return ['status', 'Verification ' . $new['verification_status']];
        }
        return ['edited', 'Edited'];
    }

    private function buildChanges(array $old, array $new): array
    {
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        $changes = [];
        foreach ($keys as $key) {
            if (in_array($key, self::SKIP, true)) {
                continue;
            }
            $changes[] = [
                'field' => str_replace(['_id', '_'], ['', ' '], $key),
                'from'  => $old[$key] ?? null,
                'to'    => $new[$key] ?? null,
            ];
        }
        return $changes;
    }

    private function actorName(AuditTrail $row): string
    {
        $u = $row->user;
        if (! $u) {
            return 'System';
        }
        $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
        return $name !== '' ? $name : ($u->username ?? 'System');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/History/RecordHistoryTest.php`
Expected: PASS (2 tests).

Note: `Status → Approved` — the test asserts `label === 'Approved'`. Fix the classify return for `status` to just the resolved value so the label reads cleanly. Replace the status line with:

```php
        if (array_key_exists('status_id', $new)) {
            return ['status', (string) $new['status_id']];
        }
```

Re-run: `php artisan test tests/Feature/History/RecordHistoryTest.php` → PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/History/RecordHistoryService.php back-end/tests/Feature/History/RecordHistoryTest.php
git commit -m "feat(history): RecordHistoryService folds audit rows into milestones"
```

---

## Task 2: PO & PR history endpoints

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseOrderController.php`
- Modify: `back-end/app/Http/Controllers/Api/PurchaseRequestController.php`
- Modify: `back-end/routes/api.php:463` (PO group) and near `:515` (PR group)
- Test: `back-end/tests/Feature/History/RecordHistoryTest.php` (add endpoint tests)

- [ ] **Step 1: Write the failing endpoint tests**

Append these methods to `RecordHistoryTest.php` (add `use Laravel\Sanctum\Sanctum;` and the model imports `App\Models\PurchaseOrder`, `App\Models\Receiving`, `App\Models\Location`, `App\Models\Supplier` at the top):

```php
    public function test_po_history_endpoint_includes_receiving_milestone(): void
    {
        $u = $this->makeUser();
        Sanctum::actingAs($u);

        $loc = Location::forceCreate([
            'location_code' => 'HWH-' . uniqid(), 'location_name' => 'Hist WH',
            'location_type' => 'warehouse', 'is_active' => 1,
        ]);
        $sup = Supplier::forceCreate(['supplier_name' => 'Hist Supplier ' . uniqid()]);

        $po = PurchaseOrder::create([
            'supplier_id' => $sup->supplier_id, 'location_id' => $loc->location_id,
            'po_number' => 'PO-HST-' . uniqid(), 'order_date' => today()->toDateString(),
            'verification_status' => 'verified', 'total_amount' => 100, 'grand_total' => 100,
            'created_by' => $u->user_id,
        ]);
        Receiving::create([
            'po_id' => $po->po_id, 'supplier_id' => $sup->supplier_id,
            'location_id' => $loc->location_id, 'receiving_number' => 'GRN-' . uniqid(),
            'receiving_date' => today()->toDateString(), 'received_by' => $u->user_id,
            'total_quantity_received' => 5,
        ]);

        $res = $this->getJson("/api/purchase-orders/{$po->po_id}/history");
        $res->assertOk();
        $types = collect($res->json('data'))->pluck('type');
        $this->assertTrue($types->contains('created'));   // PO create audit row
        $this->assertTrue($types->contains('received'));  // merged receiving milestone
    }

    public function test_pr_history_endpoint_ok(): void
    {
        $u = $this->makeUser();
        Sanctum::actingAs($u);
        // PR creation route/model is exercised elsewhere; here we assert the endpoint
        // shape for a non-existent record returns an empty, successful timeline.
        $res = $this->getJson('/api/purchase-requests/123456/history');
        $res->assertOk()->assertJsonPath('success', true);
        $this->assertIsArray($res->json('data'));
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/History/RecordHistoryTest.php --filter=history_endpoint`
Expected: FAIL — 404 (route not defined).

- [ ] **Step 3: Add `history()` to PurchaseOrderController**

In `back-end/app/Http/Controllers/Api/PurchaseOrderController.php`, add `use App\Services\History\RecordHistoryService;` near the other `use` imports, then add this method after `show()`:

```php
    /** Curated lifecycle timeline for one PO: audit milestones + receiving sessions. */
    public function history($id, RecordHistoryService $history)
    {
        $po = PurchaseOrder::with(['receivings.receivedBy'])->find($id);
        if (! $po) {
            return $this->error('Purchase order not found', 404);
        }

        $milestones = $history->forRecord('purchase_orders', (int) $po->po_id);

        foreach ($po->receivings as $r) {
            $receiver = $r->receivedBy
                ? trim(($r->receivedBy->first_name ?? '') . ' ' . ($r->receivedBy->last_name ?? ''))
                : 'System';
            $milestones[] = [
                'type'       => 'received',
                'label'      => 'Received (' . (int) $r->total_quantity_received . ' pcs) · ' . $r->receiving_number,
                'actor_name' => $receiver !== '' ? $receiver : 'System',
                'timestamp'  => ($r->receiving_date ?? $r->created_at)?->toIso8601String(),
                'changes'    => [],
            ];
        }

        usort($milestones, fn ($a, $b) => strcmp((string) $a['timestamp'], (string) $b['timestamp']));

        return $this->success(array_values($milestones), 'Purchase order history retrieved');
    }
```

- [ ] **Step 4: Add `history()` to PurchaseRequestController**

In `back-end/app/Http/Controllers/Api/PurchaseRequestController.php`, add `use App\Services\History\RecordHistoryService;` and `use App\Models\PurchaseRequest;` (if not present), then add:

```php
    /** Curated lifecycle timeline for one PR: audit milestones + PO conversion. */
    public function history($id, RecordHistoryService $history)
    {
        $pr = PurchaseRequest::with('convertedPO')->find($id);

        $milestones = $history->forRecord('purchase_requests', (int) $id);

        if ($pr && $pr->convertedPO) {
            $milestones[] = [
                'type'       => 'converted',
                'label'      => 'Converted to PO ' . $pr->convertedPO->po_number,
                'actor_name' => 'System',
                'timestamp'  => $pr->convertedPO->created_at?->toIso8601String(),
                'changes'    => [],
            ];
        }

        usort($milestones, fn ($a, $b) => strcmp((string) $a['timestamp'], (string) $b['timestamp']));

        return $this->success(array_values($milestones), 'Purchase request history retrieved');
    }
```

- [ ] **Step 5: Register routes**

In `back-end/routes/api.php`, directly after the PO `show` route (line ~463):

```php
    Route::get('/purchase-orders/{id}/history', [PurchaseOrderController::class, 'history']);
```

And inside the PR read group, directly after the PR `show` route (line ~515):

```php
    Route::middleware('permission:purchase_request_creation,read')
        ->get('/purchase-requests/{id}/history', [PurchaseRequestController::class, 'history']);
```

- [ ] **Step 6: Run tests to verify pass**

Run: `php artisan test tests/Feature/History/RecordHistoryTest.php`
Expected: PASS (all tests).

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/PurchaseOrderController.php back-end/app/Http/Controllers/Api/PurchaseRequestController.php back-end/routes/api.php back-end/tests/Feature/History/RecordHistoryTest.php
git commit -m "feat(history): PO/PR history endpoints with receiving + conversion milestones"
```

---

## Task 3: Receiving receipt blade + PDF/CSV endpoints

**Files:**
- Create: `back-end/resources/views/receiving/receipt.blade.php`
- Modify: `back-end/app/Http/Controllers/Api/ReceivingController.php`
- Modify: `back-end/routes/api.php` (bottom download block, after line ~894)
- Test: `back-end/tests/Feature/History/ReceivingReceiptTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/History/ReceivingReceiptTest.php`:

```php
<?php

namespace Tests\Feature\History;

use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Receiving;
use App\Models\ReceivingDetail;
use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReceivingReceiptTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['role_name' => 'admin'], ['description' => 'System Administrator']);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionsSeeder::class);
    }

    private function adminUser(): User
    {
        $role = Role::where('role_name', 'admin')->firstOrFail();
        $statusId = StatusLookup::query()->value('status_id')
            ?? StatusLookup::forceCreate(['status_name' => 'Active', 'status_category' => 'user'])->status_id;
        return User::forceCreate([
            'first_name' => 'Rcpt', 'last_name' => 'Admin',
            'email' => 'rcpt_' . uniqid() . '@test.local',
            'username' => 'rcpt_' . uniqid(),
            'password_hash' => Hash::make('password'),
            'role_id' => $role->role_id, 'status_id' => $statusId,
        ]);
    }

    private function makeReceiving(User $u): Receiving
    {
        $loc = Location::forceCreate([
            'location_code' => 'RWH-' . uniqid(), 'location_name' => 'Rcpt WH',
            'location_type' => 'warehouse', 'is_active' => 1,
        ]);
        $sup = Supplier::forceCreate(['supplier_name' => 'Rcpt Supplier ' . uniqid()]);
        $prod = Product::forceCreate([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'Test Widget',
            'product_type' => 'installation_material',
        ]);
        $po = PurchaseOrder::create([
            'supplier_id' => $sup->supplier_id, 'location_id' => $loc->location_id,
            'po_number' => 'PO-R-' . uniqid(), 'order_date' => today()->toDateString(),
            'verification_status' => 'verified', 'total_amount' => 100, 'grand_total' => 100,
            'created_by' => $u->user_id,
        ]);
        $r = Receiving::create([
            'po_id' => $po->po_id, 'supplier_id' => $sup->supplier_id,
            'location_id' => $loc->location_id, 'receiving_number' => 'GRN-' . uniqid(),
            'receiving_date' => today()->toDateString(), 'received_by' => $u->user_id,
            'total_quantity_received' => 3,
        ]);
        ReceivingDetail::create([
            'receiving_id' => $r->receiving_id, 'product_id' => $prod->product_id,
            'quantity_amount' => 3, 'prod_price' => 10, 'condition' => 'Good',
        ]);
        return $r;
    }

    public function test_receipt_pdf_downloads(): void
    {
        $u = $this->adminUser();
        Sanctum::actingAs($u);
        $r = $this->makeReceiving($u);

        $res = $this->get("/api/receivings/{$r->receiving_id}/receipt/pdf?token=x");
        $res->assertOk();
        $this->assertStringContainsString('application/pdf', $res->headers->get('content-type'));
    }

    public function test_receipt_csv_downloads(): void
    {
        $u = $this->adminUser();
        Sanctum::actingAs($u);
        $r = $this->makeReceiving($u);

        $res = $this->get("/api/receivings/{$r->receiving_id}/receipt/csv?token=x");
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('content-type'));
    }

    public function test_receipt_pdf_unknown_id_404(): void
    {
        $u = $this->adminUser();
        Sanctum::actingAs($u);
        $this->get('/api/receivings/999999/receipt/pdf?token=x')->assertNotFound();
    }
}
```

Note: `Sanctum::actingAs` satisfies the `auth.query.token` guard in tests since the request is already authenticated; the `?token=x` is only needed for real browser downloads.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/History/ReceivingReceiptTest.php`
Expected: FAIL — 404 (routes not defined).

- [ ] **Step 3: Create the blade view**

Create `back-end/resources/views/receiving/receipt.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Goods Received Note – {{ $receiving->receiving_number }}</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: Arial, DejaVu Sans, sans-serif; font-size: 11px; color: #111; background: #fff; }
  .page { width: 100%; max-width: 700px; margin: 0 auto; padding: 28px 32px; }
  .company-header { text-align: center; border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 14px; }
  .company-name { font-size: 22px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.8px; }
  .company-sub  { font-size: 11px; color: #333; margin-top: 2px; }
  .divider-thin { border: none; border-top: 1px solid #bbb; margin: 8px 0; }
  .title-row    { display: table; width: 100%; margin: 10px 0 6px; }
  .title-left   { display: table-cell; font-size: 13px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.8px; }
  .title-right  { display: table-cell; text-align: right; font-size: 11px; }
  .title-right strong { font-size: 13px; }
  .meta-table   { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
  .meta-table td { padding: 2px 0; font-size: 11px; vertical-align: top; }
  .meta-label   { font-weight: bold; width: 90px; }
  .meta-value   { padding-left: 6px; }
  .items-table  { width: 100%; border-collapse: collapse; margin: 10px 0; }
  .items-table th { background: #1e293b; color: #fff; padding: 6px 8px; text-align: left;
    font-size: 10px; letter-spacing: 0.4px; text-transform: uppercase; border: 1px solid #1e293b; }
  .items-table th.center { text-align: center; }
  .items-table td { padding: 5px 8px; border: 1px solid #d1d5db; font-size: 11px; vertical-align: top; }
  .items-table td.center { text-align: center; }
  .items-table tr:nth-child(even) td { background: #f8fafc; }
  .sig-table    { width: 100%; border-collapse: collapse; margin-top: 20px; }
  .sig-table td { padding: 4px 0; font-size: 11px; vertical-align: top; width: 50%; }
  .sig-label    { font-weight: bold; color: #374151; }
  .sig-line     { border-bottom: 1px solid #374151; margin-top: 32px; margin-bottom: 4px; width: 80%; }
  .sig-caption  { font-size: 10px; color: #64748b; }
</style>
</head>
<body>
<div class="page">
  @php $loc = $receiving->location; @endphp
  <div class="company-header">
    <div class="company-name">LGM Global Industries, Inc.</div>
    @if($loc?->location_name)<div class="company-sub">{{ $loc->location_name }}</div>@endif
    @if($loc?->address)<div class="company-sub">{{ $loc->address }}</div>@endif
    <div class="company-sub" style="font-size:10px;color:#666">Vat Reg. TIN#: 771-347-425-000</div>
  </div>

  <div class="title-row">
    <div class="title-left">Goods Received Note</div>
    <div class="title-right">No.&nbsp;<strong>{{ $receiving->receiving_number }}</strong></div>
  </div>

  <table class="meta-table">
    <tr>
      <td class="meta-label">PO #:</td>
      <td class="meta-value">{{ $receiving->purchaseOrder?->po_number ?? '—' }}</td>
      <td style="width:20px"></td>
      <td class="meta-label">RECEIVED:</td>
      <td class="meta-value">{{ $receiving->receiving_date ? \Carbon\Carbon::parse($receiving->receiving_date)->format('m/d/Y') : '—' }}</td>
    </tr>
    <tr>
      <td class="meta-label">SUPPLIER:</td>
      <td class="meta-value">{{ $receiving->supplier?->supplier_name ?? $receiving->purchaseOrder?->supplier?->supplier_name ?? '—' }}</td>
      <td></td>
      <td class="meta-label">WAREHOUSE:</td>
      <td class="meta-value">{{ $loc?->location_name ?? '—' }}</td>
    </tr>
  </table>

  <hr class="divider-thin">

  <table class="items-table">
    <thead>
      <tr>
        <th>PRODUCT</th>
        <th class="center" style="width:70px">ORDERED</th>
        <th class="center" style="width:70px">RECEIVED</th>
        <th class="center" style="width:80px">CONDITION</th>
      </tr>
    </thead>
    <tbody>
      @php
        $orderedByProduct = collect($receiving->purchaseOrder?->details ?? [])
            ->groupBy('product_id')->map(fn($g) => (int) $g->sum('quantity_ordered'));
      @endphp
      @forelse($receiving->details as $d)
        <tr>
          <td>
            {{ $d->product?->product_name ?? "Product #{$d->product_id}" }}
            @if($d->product?->product_code)
              <br><span style="font-size:9px;color:#94a3b8">SKU: {{ $d->product->product_code }}</span>
            @endif
          </td>
          <td class="center">{{ $orderedByProduct[$d->product_id] ?? '—' }}</td>
          <td class="center">{{ (int) $d->quantity_amount }}</td>
          <td class="center">{{ $d->condition ?? 'Good' }}</td>
        </tr>
      @empty
        <tr><td colspan="4" class="center" style="color:#94a3b8;padding:12px">No line items.</td></tr>
      @endforelse
    </tbody>
  </table>

  <div style="text-align:right;font-size:11px;margin-top:4px">
    <strong>Total received:</strong> {{ (int) $receiving->total_quantity_received }} pcs
    @if((int) $receiving->total_quantity_damaged > 0)
      &nbsp;·&nbsp; <strong>Damaged:</strong> {{ (int) $receiving->total_quantity_damaged }}
    @endif
  </div>

  <hr class="divider-thin">

  <table class="sig-table">
    <tr>
      <td>
        <span class="sig-label">Received by:</span>&nbsp;
        {{ trim(($receiving->receivedBy?->first_name ?? '') . ' ' . ($receiving->receivedBy?->last_name ?? '')) ?: '—' }}
      </td>
      <td>
        <span class="sig-label">Checked by:</span>&nbsp; ____________________
      </td>
    </tr>
  </table>

  <div style="margin-top:32px">
    <div class="sig-line"></div>
    <div class="sig-caption">Receiver's Signature over Printed Name</div>
  </div>
</div>
</body>
</html>
```

- [ ] **Step 4: Add controller methods**

In `back-end/app/Http/Controllers/Api/ReceivingController.php`, add these methods to the class:

```php
    /** Stream a PDF Goods Received Note for one receiving session. */
    public function receiptPdf($id)
    {
        $receiving = Receiving::with([
            'purchaseOrder.supplier', 'purchaseOrder.details',
            'supplier', 'location', 'receivedBy', 'details.product',
        ])->find($id);

        if (! $receiving) {
            return $this->error('Receiving not found', 404);
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('receiving.receipt', ['receiving' => $receiving])
            ->setPaper('a4');

        return $pdf->download('GRN-' . $receiving->receiving_number . '.pdf');
    }

    /** Stream a CSV Goods Received Note for one receiving session. */
    public function receiptCsv($id)
    {
        $receiving = Receiving::with([
            'purchaseOrder.details', 'supplier', 'location', 'receivedBy', 'details.product',
        ])->find($id);

        if (! $receiving) {
            return $this->error('Receiving not found', 404);
        }

        $orderedByProduct = collect($receiving->purchaseOrder?->details ?? [])
            ->groupBy('product_id')->map(fn ($g) => (int) $g->sum('quantity_ordered'));

        $filename = 'GRN-' . $receiving->receiving_number . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($receiving, $orderedByProduct) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['GRN Number', $receiving->receiving_number]);
            fputcsv($handle, ['PO Number', $receiving->purchaseOrder?->po_number ?? '']);
            fputcsv($handle, ['Supplier', $receiving->supplier?->supplier_name ?? $receiving->purchaseOrder?->supplier?->supplier_name ?? '']);
            fputcsv($handle, ['Warehouse', $receiving->location?->location_name ?? '']);
            fputcsv($handle, ['Received Date', (string) $receiving->receiving_date]);
            fputcsv($handle, []);
            fputcsv($handle, ['Product Code', 'Product', 'Ordered', 'Received', 'Condition']);
            foreach ($receiving->details as $d) {
                fputcsv($handle, [
                    $d->product?->product_code ?? '',
                    $d->product?->product_name ?? ('Product #' . $d->product_id),
                    $orderedByProduct[$d->product_id] ?? '',
                    (int) $d->quantity_amount,
                    $d->condition ?? 'Good',
                ]);
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
```

- [ ] **Step 5: Register download routes**

In `back-end/routes/api.php`, in the bottom "Download endpoints" block (after line ~894):

```php
Route::middleware(['auth.query.token', 'permission:delivery_receiving,read'])
     ->get('/receivings/{id}/receipt/pdf', [ReceivingController::class, 'receiptPdf']);

Route::middleware(['auth.query.token', 'permission:delivery_receiving,read'])
     ->get('/receivings/{id}/receipt/csv', [ReceivingController::class, 'receiptCsv']);
```

Confirm `use App\Http\Controllers\Api\ReceivingController;` is already imported at the top of `routes/api.php` (it is — the `/receivings` CRUD routes use it).

- [ ] **Step 6: Run tests to verify pass**

Run: `php artisan test tests/Feature/History/ReceivingReceiptTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add back-end/resources/views/receiving/receipt.blade.php back-end/app/Http/Controllers/Api/ReceivingController.php back-end/routes/api.php back-end/tests/Feature/History/ReceivingReceiptTest.php
git commit -m "feat(receiving): Goods Received Note PDF + CSV receipt endpoints"
```

---

## Task 4: Frontend API helpers

**Files:**
- Modify: `frontend/src/admin/services/api.js` (purchaseOrdersAPI ~651, receivingsAPI ~708, purchaseRequestsAPI ~1227)

- [ ] **Step 1: Add history to `purchaseOrdersAPI`**

Inside the `purchaseOrdersAPI` object (before the closing `};` at line ~685), add:

```js
  history: async (id) => apiRequest(`/purchase-orders/${id}/history`),
```

- [ ] **Step 2: Add history to `purchaseRequestsAPI`**

Inside the `purchaseRequestsAPI` object (near its other methods, ~1227+), add:

```js
  history: async (id) => apiRequest(`/purchase-requests/${id}/history`),
```

- [ ] **Step 3: Add receipt URL builders to `receivingsAPI`**

Inside `receivingsAPI` (before its closing `};` at line ~728), add:

```js
  receiptPdfUrl: (id) => {
    const token = getToken();
    return `${getApiBaseUrl()}/receivings/${id}/receipt/pdf${token ? `?token=${encodeURIComponent(token)}` : ''}`;
  },
  receiptCsvUrl: (id) => {
    const token = getToken();
    return `${getApiBaseUrl()}/receivings/${id}/receipt/csv${token ? `?token=${encodeURIComponent(token)}` : ''}`;
  },
```

Confirm `getToken` and `getApiBaseUrl` are already exported in this file (they are — used by `istAPI.receiptPdfUrl`).

- [ ] **Step 4: Verify build compiles**

Run: `cd frontend && npx eslint src/admin/services/api.js`
Expected: no new errors.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -m "feat(api): PO/PR history + receiving receipt URL helpers"
```

---

## Task 5: RecordHistoryTimeline component + styles

**Files:**
- Create: `frontend/src/admin/components/RecordHistoryTimeline.jsx`
- Create: `frontend/src/admin/styles/record_history.css`

- [ ] **Step 1: Create the stylesheet**

Create `frontend/src/admin/styles/record_history.css`:

```css
.rh-timeline { list-style: none; margin: 0; padding: 8px 0; }
.rh-item { position: relative; padding: 0 0 18px 26px; }
.rh-item::before { content: ''; position: absolute; left: 7px; top: 4px; bottom: -4px; width: 2px; background: var(--ak-border, #e2e8f0); }
.rh-item:last-child::before { display: none; }
.rh-dot { position: absolute; left: 0; top: 2px; width: 16px; height: 16px; border-radius: 50%;
  background: var(--ak-primary, #2563eb); border: 3px solid var(--ak-surface, #fff); box-shadow: 0 0 0 1px var(--ak-border, #e2e8f0); }
.rh-dot--received { background: #16a34a; }
.rh-dot--verified { background: #7c3aed; }
.rh-dot--rejected, .rh-dot--archived { background: #dc2626; }
.rh-dot--converted { background: #0891b2; }
.rh-head { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
.rh-label { font-weight: 600; font-size: 13px; }
.rh-meta { font-size: 12px; color: var(--ak-text-muted, #64748b); }
.rh-toggle { background: none; border: none; cursor: pointer; font-size: 12px; color: var(--ak-primary, #2563eb); padding: 0; }
.rh-changes { margin: 6px 0 0; padding: 8px 10px; background: var(--ak-surface-alt, #f8fafc); border-radius: 6px; font-size: 12px; }
.rh-change { color: var(--ak-text, #334155); }
.rh-change .rh-from { color: #94a3b8; }
.rh-empty { color: var(--ak-text-muted, #64748b); font-size: 13px; padding: 16px; text-align: center; }
```

- [ ] **Step 2: Create the component**

Create `frontend/src/admin/components/RecordHistoryTimeline.jsx`:

```jsx
import React, { useState } from 'react';
import '../styles/record_history.css';

function fmt(ts) {
  if (!ts) return '';
  try {
    return new Date(ts).toLocaleString(undefined, {
      year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    });
  } catch { return ts; }
}

function Milestone({ m }) {
  const [open, setOpen] = useState(false);
  const hasChanges = Array.isArray(m.changes) && m.changes.length > 0;
  return (
    <li className="rh-item">
      <span className={`rh-dot rh-dot--${m.type}`} />
      <div className="rh-head">
        <span className="rh-label">{m.label}</span>
        <span className="rh-meta">{fmt(m.timestamp)}{m.actor_name ? ` · ${m.actor_name}` : ''}</span>
        {hasChanges && (
          <button type="button" className="rh-toggle" onClick={() => setOpen((v) => !v)}>
            {open ? '▴ hide' : '▾ details'}
          </button>
        )}
      </div>
      {open && hasChanges && (
        <div className="rh-changes">
          {m.changes.map((c, i) => (
            <div className="rh-change" key={i}>
              <strong>{c.field}:</strong>{' '}
              <span className="rh-from">{c.from == null || c.from === '' ? '—' : String(c.from)}</span>
              {' → '}
              <span>{c.to == null || c.to === '' ? '—' : String(c.to)}</span>
            </div>
          ))}
        </div>
      )}
    </li>
  );
}

export default function RecordHistoryTimeline({ milestones, loading }) {
  if (loading) return <div className="rh-empty">Loading history…</div>;
  if (!milestones || milestones.length === 0) return <div className="rh-empty">No history recorded yet.</div>;
  return (
    <ul className="rh-timeline">
      {milestones.map((m, i) => <Milestone m={m} key={i} />)}
    </ul>
  );
}
```

- [ ] **Step 3: Verify lint**

Run: `cd frontend && npx eslint src/admin/components/RecordHistoryTimeline.jsx`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/components/RecordHistoryTimeline.jsx frontend/src/admin/styles/record_history.css
git commit -m "feat(ui): shared RecordHistoryTimeline component"
```

---

## Task 6: History tab on the PO page

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseOrderPage.jsx` (tab state ~131, tab nav ~1332, tab render ~1351, PO detail modal ~1356)

- [ ] **Step 1: Import the timeline + API**

At the top of `PurchaseOrderPage.jsx`, add:

```jsx
import RecordHistoryTimeline from '../components/RecordHistoryTimeline';
```

Ensure `purchaseOrdersAPI` is already imported (it is).

- [ ] **Step 2: Add history state**

Near the other `useState` hooks (~line 185, after PO Status state), add:

```jsx
  // Per-PO history (History tab / detail modal)
  const [historyMilestones, setHistoryMilestones] = useState([]);
  const [historyLoading, setHistoryLoading] = useState(false);

  const loadPoHistory = async (poId) => {
    if (!poId) return;
    setHistoryLoading(true);
    try {
      const res = await purchaseOrdersAPI.history(poId);
      setHistoryMilestones(Array.isArray(res?.data) ? res.data : []);
    } catch {
      setHistoryMilestones([]);
    } finally {
      setHistoryLoading(false);
    }
  };
```

- [ ] **Step 3: Load history when a PO detail modal opens**

Find where `setSelectedPO(...)` is called to open the detail modal (the PO status rows). Immediately after the selection handler sets the PO, call `loadPoHistory`. The simplest robust approach: add an effect after the state declarations:

```jsx
  useEffect(() => {
    if (selectedPO?.po_id) loadPoHistory(selectedPO.po_id);
    else { setHistoryMilestones([]); }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedPO?.po_id]);
```

- [ ] **Step 4: Render the timeline inside the PO detail modal**

In the PO detail modal body (inside the `Modal` at ~1356, after the existing line-items/receivings section), add a history block:

```jsx
        <div className="po-detail-section" style={{ marginTop: 16 }}>
          <h4 className="po-detail-heading">History</h4>
          <RecordHistoryTimeline milestones={historyMilestones} loading={historyLoading} />
        </div>
```

Place this before the modal's closing tags. Use the existing heading class if `po-detail-heading` is not present — match whatever class the modal already uses for section titles (grep the file for the receivings section heading and reuse it).

- [ ] **Step 5: Manual verification**

Run the app (`cd frontend && npm start` or the demo build), open Purchase Orders → PO Status → click a PO → confirm the History section renders milestones (Created, Status changes, Received) and detail rows expand.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/PurchaseOrderPage.jsx
git commit -m "feat(ui): PO detail history timeline"
```

---

## Task 7: History tab on the PR page

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseRequestPage.jsx` (tab state ~175, tab nav ~1800, tab render ~1850)

- [ ] **Step 1: Import timeline + API**

At the top, add:

```jsx
import RecordHistoryTimeline from '../components/RecordHistoryTimeline';
```

Ensure `purchaseRequestsAPI` is imported (grep; add to the existing api import if missing).

- [ ] **Step 2: Add history state + loader**

Near the other `useState` hooks (~175), add:

```jsx
  const [prHistory, setPrHistory] = useState([]);
  const [prHistoryLoading, setPrHistoryLoading] = useState(false);
  const [historyPrId, setHistoryPrId] = useState(null);

  const openPrHistory = async (prId) => {
    setHistoryPrId(prId);
    setActiveTab('history');
    setPrHistoryLoading(true);
    try {
      const res = await purchaseRequestsAPI.history(prId);
      setPrHistory(Array.isArray(res?.data) ? res.data : []);
    } catch {
      setPrHistory([]);
    } finally {
      setPrHistoryLoading(false);
    }
  };
```

- [ ] **Step 3: Add the History tab button**

In the `.po-tab-nav` block (~1800), add a new tab button after the existing ones:

```jsx
          <button
            type="button"
            className={`po-tab-btn${activeTab === 'history' ? ' po-tab-btn--active' : ''}`}
            onClick={() => setActiveTab('history')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
              <circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>
            </svg>
            History
          </button>
```

- [ ] **Step 4: Render the History tab body**

After the existing `{activeTab === '...' && ...}` render lines (~1850), add:

```jsx
        {activeTab === 'history' && (
          <div className="pr-history-tab">
            {!historyPrId ? (
              <div className="rh-empty">Select a purchase request (from My Requests) to view its history.</div>
            ) : (
              <RecordHistoryTimeline milestones={prHistory} loading={prHistoryLoading} />
            )}
          </div>
        )}
```

- [ ] **Step 5: Wire a "View history" action into the My Requests rows**

In the My Requests tab renderer, add a small button/link per PR row that calls `openPrHistory(pr.pr_id)`. Match the existing row-action button styling used elsewhere in the file (grep for an existing row action button and reuse its className). Example:

```jsx
                <button type="button" className="po-row-action" onClick={() => openPrHistory(pr.pr_id)}>
                  History
                </button>
```

- [ ] **Step 6: Manual verification**

Open Purchase Requests → My Requests → click a PR's History action → confirm the History tab shows the timeline (Created, Approved/Rejected, Converted to PO).

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/pages/PurchaseRequestPage.jsx
git commit -m "feat(ui): PR history tab"
```

---

## Task 8: ReceivingReceipt modal + entry points

**Files:**
- Create: `frontend/src/admin/components/ReceivingReceipt.jsx`
- Modify: `frontend/src/admin/pages/PurchaseOrderPage.jsx` (receipt button on receiving sessions in detail modal)
- Modify: `frontend/src/admin/pages/BarcodeScan.jsx` (post-save receipt action)

- [ ] **Step 1: Create the ReceivingReceipt component**

Create `frontend/src/admin/components/ReceivingReceipt.jsx`:

```jsx
import React from 'react';
import Modal from './Modal';
import { receivingsAPI } from '../services/api';

/**
 * On-screen Goods Received Note for one receiving session, with Print +
 * download actions. `receiving` must include receiving_number, receiving_date,
 * purchaseOrder (po_number, supplier, details), location, receivedBy, details[].
 */
export default function ReceivingReceipt({ receiving, isOpen, onClose }) {
  if (!receiving) return null;

  const po = receiving.purchaseOrder || {};
  const orderedByProduct = {};
  (po.details || []).forEach((d) => {
    orderedByProduct[d.product_id] = (orderedByProduct[d.product_id] || 0) + Number(d.quantity_ordered || 0);
  });

  const printReceipt = () => {
    const node = document.getElementById('grn-print-area');
    if (!node) return;
    const w = window.open('', '_blank', 'width=800,height=900');
    w.document.write(`<html><head><title>GRN-${receiving.receiving_number}</title></head><body>${node.innerHTML}</body></html>`);
    w.document.close();
    w.focus();
    w.print();
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Goods Received Note" maxWidth={640}>
      <div id="grn-print-area" className="grn-doc">
        <h2 style={{ textAlign: 'center', margin: 0 }}>LGM Global Industries, Inc.</h2>
        <p style={{ textAlign: 'center', margin: '2px 0 12px', color: '#555' }}>
          {receiving.location?.location_name || ''}
        </p>
        <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13 }}>
          <div>
            <div><strong>GRN #:</strong> {receiving.receiving_number}</div>
            <div><strong>PO #:</strong> {po.po_number || '—'}</div>
          </div>
          <div>
            <div><strong>Received:</strong> {receiving.receiving_date || '—'}</div>
            <div><strong>Supplier:</strong> {receiving.supplier?.supplier_name || po.supplier?.supplier_name || '—'}</div>
          </div>
        </div>
        <table style={{ width: '100%', borderCollapse: 'collapse', marginTop: 12, fontSize: 13 }}>
          <thead>
            <tr style={{ background: '#1e293b', color: '#fff' }}>
              <th style={{ padding: 6, textAlign: 'left' }}>Product</th>
              <th style={{ padding: 6 }}>Ordered</th>
              <th style={{ padding: 6 }}>Received</th>
              <th style={{ padding: 6 }}>Condition</th>
            </tr>
          </thead>
          <tbody>
            {(receiving.details || []).map((d, i) => (
              <tr key={i} style={{ borderBottom: '1px solid #e2e8f0' }}>
                <td style={{ padding: 6 }}>{d.product?.product_name || `Product #${d.product_id}`}</td>
                <td style={{ padding: 6, textAlign: 'center' }}>{orderedByProduct[d.product_id] ?? '—'}</td>
                <td style={{ padding: 6, textAlign: 'center' }}>{Number(d.quantity_amount || 0)}</td>
                <td style={{ padding: 6, textAlign: 'center' }}>{d.condition || 'Good'}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <p style={{ textAlign: 'right', marginTop: 8, fontSize: 13 }}>
          <strong>Total received:</strong> {Number(receiving.total_quantity_received || 0)} pcs
        </p>
        <p style={{ marginTop: 24, fontSize: 13 }}>
          <strong>Received by:</strong>{' '}
          {receiving.received_by_name
            || `${receiving.receivedBy?.first_name || ''} ${receiving.receivedBy?.last_name || ''}`.trim()
            || '—'}
        </p>
      </div>

      <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
        <button type="button" className="ak-btn ak-btn--ghost" onClick={printReceipt}>Print</button>
        <a className="ak-btn ak-btn--ghost" href={receivingsAPI.receiptCsvUrl(receiving.receiving_id)}>Download CSV</a>
        <a className="ak-btn ak-btn--primary" href={receivingsAPI.receiptPdfUrl(receiving.receiving_id)}>Download PDF</a>
      </div>
    </Modal>
  );
}
```

Note: reuse the app's existing `Modal` component path — grep for `import Modal` in `PurchaseOrderPage.jsx` and match the same relative path. Match `ak-btn` class names to the design system (grep an existing button to confirm the exact classes; adjust if the project uses different modifiers).

- [ ] **Step 2: Wire the receipt button into the PO detail modal**

In `PurchaseOrderPage.jsx`, import the component:

```jsx
import ReceivingReceipt from '../components/ReceivingReceipt';
```

Add state:

```jsx
  const [receiptReceiving, setReceiptReceiving] = useState(null);
```

In the PO detail modal's receivings list (where each receiving session is rendered), add a button per session:

```jsx
                <button type="button" className="po-row-action" onClick={() => setReceiptReceiving(rcv)}>
                  Receipt
                </button>
```

(where `rcv` is the receiving object being mapped — it comes from `selectedPO.receivings`, which `show()` already loads; if a session lacks nested `details`/`purchaseOrder`, fetch full data via `receivingsAPI.getById(rcv.receiving_id)` first and store the result in `receiptReceiving`).

Render the modal once, near the PO detail modal:

```jsx
      <ReceivingReceipt
        receiving={receiptReceiving}
        isOpen={!!receiptReceiving}
        onClose={() => setReceiptReceiving(null)}
      />
```

- [ ] **Step 3: Add a post-save receipt action in BarcodeScan**

In `BarcodeScan.jsx`, import `ReceivingReceipt` and add `receiptReceiving` state. After a receiving session is successfully saved (the `store` success path — grep for the success handler that follows `receivingsAPI` create/save), capture the returned receiving and offer a "View / Print receipt" button that sets `receiptReceiving` to the saved record. Render `<ReceivingReceipt .../>` the same way as Step 2.

- [ ] **Step 4: Manual verification**

- Open a completed PO → detail modal → each receiving session shows a **Receipt** button → opens the GRN modal → Print opens a print window, Download PDF returns `GRN-<n>.pdf`, Download CSV returns `GRN-<n>.csv`.
- Complete a receiving in the barcode scan flow → the post-save receipt action opens the same GRN.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/ReceivingReceipt.jsx frontend/src/admin/pages/PurchaseOrderPage.jsx frontend/src/admin/pages/BarcodeScan.jsx
git commit -m "feat(ui): Goods Received Note modal with print + PDF/CSV download"
```

---

## Task 9: Full verification pass

- [ ] **Step 1: Run the backend history + receipt tests**

Run: `cd back-end && php artisan test tests/Feature/History`
Expected: PASS (all tests across both files).

- [ ] **Step 2: Run the broader PO/receiving suites to check for regressions**

Run: `php artisan test tests/Feature/Budget`
Expected: PASS (no regressions from the new routes/methods).

- [ ] **Step 3: Frontend lint**

Run: `cd frontend && npx eslint src/admin/components/RecordHistoryTimeline.jsx src/admin/components/ReceivingReceipt.jsx src/admin/pages/PurchaseOrderPage.jsx src/admin/pages/PurchaseRequestPage.jsx src/admin/services/api.js`
Expected: no new errors.

- [ ] **Step 4: Manual end-to-end walkthrough**

- PO History timeline (Created → Verified → Approved → Received → Completed) with expandable detail.
- PR History timeline (Created → Approved/Rejected → Converted to PO).
- GRN print view + PDF download + CSV download from both entry points.

- [ ] **Step 5: Final commit (if any fixups)**

```bash
git add -A && git commit -m "chore: verification fixups for history + receipt feature"
```

---

## Self-Review Notes

- **Spec coverage:** History timeline w/ expand (Tasks 1,5,6,7) ✓; PO receiving milestones (Task 2) ✓; PR→PO conversion milestone (Task 2) ✓; GRN print view (Task 8) ✓; GRN PDF (Task 3) ✓; GRN CSV (Task 3) ✓; tests (Tasks 1,2,3,9) ✓; no schema changes ✓; Purchase Returns untouched ✓.
- **Known adaptation points (flagged inline, not placeholders):** exact CSS class names for row-action buttons, section headings, `ak-btn` modifiers, and the `Modal` import path must be matched to each page's existing conventions via grep — the plan tells the engineer exactly what to grep for and reuse.
- **Type consistency:** milestone shape `{type,label,actor_name,timestamp,changes:[{field,from,to}]}` is identical across the service (Task 1), both controllers (Task 2), and the React component (Task 5). Receipt URL builders `receiptPdfUrl`/`receiptCsvUrl` (Task 4) match their consumers (Task 8).
