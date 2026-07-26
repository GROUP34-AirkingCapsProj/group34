# Issue-Out Transaction Detail, Draft Actions, and Barcode Order-Building — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make issue-out rows on the Transactions page clickable into a full detail view, let Draft issue-outs be resumed or cancelled (new `Cancelled` status, kept in history), and restore barcode scan-to-add in the issuance OrderBuilder.

**Architecture:** Backend adds a `Cancelled` issuance status (name-resolved, never hardcoded) and a Draft-only `POST /issuances/{id}/cancel` endpoint. Frontend makes issue-out rows in `TransactionManagement.jsx` open a detail modal (via existing `GET /issuances/{id}`) with Continue/Cancel for Drafts, and adds a barcode input to `OrderBuilder.jsx` that resolves a scan to a product and adds/increments a line.

**Tech Stack:** Laravel 11 (PHP 8.2), MySQL/MariaDB via XAMPP, PHPUnit with `DatabaseTransactions` against `airkingV5_test`, React 18 CRA with the `ak-*` design system.

**Spec:** `docs/superpowers/specs/2026-07-26-issueout-transaction-detail-draft-actions-design.md`

---

## Conventions You Must Follow

- Backend root: `C:\XAMPP1\htdocs\Airking_Inventory\back-end` (run backend/git commands there). Frontend root: `C:\XAMPP1\htdocs\Airking_Inventory\frontend`.
- Schema is a SQL dump, not migrations — a bare `php artisan migrate` FAILS. Apply one migration with `--path`. Local `.env` has `APP_ENV=production`, so `--force` is required:
  ```bash
  php artisan migrate --force --path=database/migrations/<file>.php
  DB_DATABASE=airkingV5_test php artisan migrate --force --path=database/migrations/<file>.php
  ```
  Every migration must be idempotent.
- Tests: `phpunit.xml` points at `airkingV5_test`, so `php artisan test` needs no env prefix. Use `Tests\TestCase` + `DatabaseTransactions`, never `RefreshDatabase`. Single test: `php artisan test --filter=method_name`.
- Roles: `admin=1, inventory_analyst=2, branch_manager=3, warehouse_personnel=4, auditor=5`. Authenticate with `$this->actingAs($user, 'sanctum')`.
- Issuance statuses today: **Draft** (auto id, name-resolved), **30 Pending, 31 Completed, 32 Voided**. This plan adds **Cancelled** (also auto id, name-resolved — never hardcode it).
- Permission feature slug is `inventory_issuance`. `inventory_issuance,create` = admin, branch_manager, warehouse_personnel.
- Frontend has NO test suite — verify each frontend task with `npm run build` (must print `Compiled successfully.`, no NEW warnings from changed files) and note a manual `npm run demo` pass is still needed.
- Every task ends with a commit (conventional-commit prefix). Do NOT push. If a here-string/quote breaks `git commit -m`, use `git commit -F <file>`.

---

## File Structure

**New backend files**
| File | Responsibility |
|---|---|
| `database/migrations/2026_07_26_000001_add_issuance_cancelled_status.php` | Idempotently insert the `Cancelled` issuance status row. |

**Modified backend files**
- `app/Support/IssuanceStatus.php` — add `cancelled(): int`.
- `app/Http/Controllers/Api/IssuanceController.php` — add `cancel($id)` (Draft-only, locked, idempotent).
- `routes/api.php` — add `POST /issuances/{id}/cancel`.

**Modified frontend files**
- `src/admin/services/api.js` — `issuancesAPI.cancel(id)`.
- `src/admin/pages/TransactionManagement.jsx` — clickable issue-out rows, detail modal, Draft Continue/Cancel, `Cancelled` chip.
- `src/admin/components/issuance/OrderBuilder.jsx` — barcode scan-to-add input.

**New test files**
- `tests/Feature/Issuance/CancelIssuanceTest.php` — cancel endpoint behavior.
- (Status existence is asserted inside the same test file to avoid a one-off file.)

---

## Task 1: `Cancelled` issuance status + `IssuanceStatus::cancelled()`

**Files:**
- Create: `database/migrations/2026_07_26_000001_add_issuance_cancelled_status.php`
- Modify: `app/Support/IssuanceStatus.php`
- Test: `tests/Feature/Issuance/CancelIssuanceTest.php` (created here, extended in Task 2)

- [ ] **Step 1: Write the migration** (mirrors the Draft insertion in `2026_07_24_000002_add_issuance_order_columns.php`)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('status_lookup')
            ->where('status_category', 'issuance')
            ->where('status_name', 'Cancelled')
            ->exists();

        if (! $exists) {
            DB::table('status_lookup')->insert([
                'status_name'     => 'Cancelled',
                'status_category' => 'issuance',
                'is_active'       => 1,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('status_lookup')
            ->where('status_category', 'issuance')
            ->where('status_name', 'Cancelled')
            ->delete();
    }
};
```

Note: confirm `status_lookup` has an `is_active` column (the Draft insert set it). If the real Draft insert used a different column set, match THAT exactly — read `2026_07_24_000002_add_issuance_order_columns.php` lines ~55–70 first and mirror its column list.

- [ ] **Step 2: Apply to both databases**

```bash
php artisan migrate --force --path=database/migrations/2026_07_26_000001_add_issuance_cancelled_status.php
DB_DATABASE=airkingV5_test php artisan migrate --force --path=database/migrations/2026_07_26_000001_add_issuance_cancelled_status.php
```
Expected: `INFO Running migrations.` then a `DONE` line, twice.

- [ ] **Step 3: Add the `cancelled()` helper to `app/Support/IssuanceStatus.php`**

Next to the existing `voided()`:

```php
    public static function cancelled(): int { return self::id('Cancelled'); }
```

- [ ] **Step 4: Write the status test**

Create `tests/Feature/Issuance/CancelIssuanceTest.php`:

```php
<?php

namespace Tests\Feature\Issuance;

use App\Support\IssuanceStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CancelIssuanceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cancelled_issuance_status_exists_and_resolves(): void
    {
        $id = DB::table('status_lookup')
            ->where('status_category', 'issuance')
            ->where('status_name', 'Cancelled')
            ->value('status_id');

        $this->assertNotNull($id, 'Cancelled issuance status is missing');
        $this->assertSame((int) $id, IssuanceStatus::cancelled());
    }
}
```

- [ ] **Step 5: Run it**

Run: `php artisan test --filter=test_cancelled_issuance_status_exists_and_resolves`
Expected: PASS, 1 test.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_26_000001_add_issuance_cancelled_status.php app/Support/IssuanceStatus.php tests/Feature/Issuance/CancelIssuanceTest.php
git commit -m "feat(issuance): add Cancelled issuance status"
```

---

## Task 2: Draft-only cancel endpoint

**Permission-gap check (do FIRST):** the route is gated `permission:inventory_issuance,create`, held by admin/branch_manager/warehouse_personnel. The tests below act as `warehouse_personnel` (a holder), so there is no gap. If you change the gate, keep the test actor a holder.

**Files:**
- Modify: `app/Http/Controllers/Api/IssuanceController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Issuance/CancelIssuanceTest.php` (extend)

- [ ] **Step 1: Write the failing tests** (append methods to `CancelIssuanceTest`)

You need a helper to make an issuance at a given status. Add these methods to the class. Read `IssuanceController::store` / the `issuances` + `issuance_details` schema first to confirm required columns; the fields below match the store validation (`location_id`, `issuance_date`, `issuance_type`, `status_id`, details). Adapt column names to reality if they differ.

```php
    private function makeLocation(): \App\Models\Location
    {
        return \App\Models\Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'WH',
            'location_type' => 'warehouse',
        ]);
    }

    private function makeUserAt(\App\Models\Location $loc, string $role = 'warehouse_personnel'): \App\Models\User
    {
        $map = ['admin' => 1, 'inventory_analyst' => 2, 'branch_manager' => 3, 'warehouse_personnel' => 4, 'auditor' => 5];
        return \App\Models\User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'T',
            'last_name'     => 'U',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $map[$role] ?? 4,
            'location_id'   => $loc->location_id,
        ]);
    }

    private function makeIssuanceAt(int $statusId, int $locationId): \App\Models\Issuance
    {
        return \App\Models\Issuance::create([
            'location_id'   => $locationId,
            'issuance_date' => now(),
            'issuance_type' => 'sale',
            'status_id'     => $statusId,
        ]);
    }

    public function test_a_draft_issue_out_can_be_cancelled(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $iss  = $this->makeIssuanceAt(IssuanceStatus::draft(), $loc->location_id);

        $res = $this->actingAs($user, 'sanctum')
                    ->postJson("/api/issuances/{$iss->issuance_id}/cancel");

        $res->assertOk();
        $this->assertSame(IssuanceStatus::cancelled(), (int) $iss->fresh()->status_id);
    }

    public function test_a_non_draft_issue_out_cannot_be_cancelled(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $iss  = $this->makeIssuanceAt(IssuanceStatus::pending(), $loc->location_id);

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/issuances/{$iss->issuance_id}/cancel")
             ->assertStatus(422);

        $this->assertSame(IssuanceStatus::pending(), (int) $iss->fresh()->status_id);
    }

    public function test_cancelling_an_already_cancelled_issue_out_is_rejected(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $iss  = $this->makeIssuanceAt(IssuanceStatus::cancelled(), $loc->location_id);

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/issuances/{$iss->issuance_id}/cancel")
             ->assertStatus(422);
    }
```

- [ ] **Step 2: Run them to confirm they fail**

Run: `php artisan test --filter=CancelIssuanceTest`
Expected: the three new tests FAIL (404 — route/method missing).

- [ ] **Step 3: Add `cancel()` to `IssuanceController`**

Read the top of the file for the `use` statements and the `ApiResponse` trait (`$this->success` / `$this->error`). Add:

```php
    public function cancel($id)
    {
        try {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($id) {
                $issuance = \App\Models\Issuance::lockForUpdate()->findOrFail($id);

                if ((int) $issuance->status_id !== \App\Support\IssuanceStatus::draft()) {
                    return $this->error('Only a draft issue-out can be cancelled.', 422);
                }

                $issuance->update(['status_id' => \App\Support\IssuanceStatus::cancelled()]);

                return $this->success($issuance->fresh(['status']), 'Issue-out cancelled.');
            });
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Issuance not found', 404);
        } catch (\Exception $e) {
            return $this->error('Failed to cancel issue-out: ' . $e->getMessage(), 500);
        }
    }
```

- [ ] **Step 4: Register the route in `routes/api.php`**

Next to the other `/issuances/{id}` routes (near line 668–671), inside the authenticated group:

```php
    Route::middleware('permission:inventory_issuance,create')
         ->post('/issuances/{id}/cancel', [IssuanceController::class, 'cancel']);
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=CancelIssuanceTest`
Expected: PASS, 4 tests.

- [ ] **Step 6: Run the whole suite**

Run: `php artisan test`
Expected: green (was 607 passed / 1 skipped; now 607 + the 4 new = 611 passed / 1 skipped, or report the actual line).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/IssuanceController.php routes/api.php tests/Feature/Issuance/CancelIssuanceTest.php
git commit -m "feat(issuance): Draft-only cancel endpoint that marks an issue-out Cancelled"
```

---

## Task 3: Transactions page — clickable issue-out detail + draft Continue/Cancel

**Files:**
- Modify: `frontend/src/admin/services/api.js`
- Modify: `frontend/src/admin/pages/TransactionManagement.jsx`

- [ ] **Step 1: Add `cancel` to `issuancesAPI`**

In `frontend/src/admin/services/api.js`, inside the existing `issuancesAPI` object (which already has `getById`):

```javascript
  cancel: async (id) =>
    apiRequest(`/issuances/${id}/cancel`, { method: 'POST' }),
```

- [ ] **Step 2: Read the conventions**

Read `frontend/src/admin/pages/TransactionManagement.jsx` fully — how it maps rows (`mapIssuance` sets `id`/`issuance_id`, `type`, `status`, etc.), how `getStatusClass` styles chips, and how it renders the table rows. Read the shared modal + confirm-dialog helpers used elsewhere (e.g. `../utils/confirm`, and how `IncidentResolveDrawer`/other pages open a modal) and the toast helper (`../utils/toast`). Match those patterns — do not hand-roll a modal if a shared one exists.

- [ ] **Step 3: Make issue-out rows clickable + add the detail modal**

Behavior to implement:
- An issue-out row (the `type` that `mapIssuance` produces — confirm the exact type label, e.g. `'Issue-out'`/`'Issuance'`) gets a pointer cursor + hover state and an `onClick` that opens a detail modal for that issuance id. Rows of other types are NOT clickable (this scope).
- The modal fetches `issuancesAPI.getById(id)` on open and renders from the response (`data` = the issuance with `details.product`, `status`, `issuedToUser`/customer, `location`, dates, `dr_number`, and line fields). Show: issuance number, status chip, customer, issued-by, date, location, DR number when present; a line table (product name, quantity, unit price, line total); and totals (subtotal / discount / grand total) using the same peso formatting the page already uses. Use the fields that actually exist on the payload — inspect one response shape while implementing; do not invent field names.
- Loading + error states inside the modal (spinner while fetching, toast/message on failure).

- [ ] **Step 4: Add Draft actions to the modal**

- When the fetched issuance's status name is `Draft`, the modal footer shows two buttons:
  - **Continue** → `navigate('/admin/issuance-scan?issuance=' + id)` (use the same router navigation the app uses — `useNavigate` from react-router; confirm the issuance-scan route path). This resumes the wizard via the URL rehydration built in Task 24.
  - **Cancel issue-out** → open the shared confirm dialog ("Cancel this issue-out? It will be kept in history as Cancelled."); on confirm call `issuancesAPI.cancel(id)`, then on success show a toast, close the modal, and refetch the transactions list so the row re-renders as `Cancelled`.
- For any non-Draft status, show no action buttons (read-only).

- [ ] **Step 5: Style the `Cancelled` chip**

In `getStatusClass` (and any issuance status chip mapping), give `Cancelled` a neutral/greyed style (reuse the class used for `Voided`/`Cancelled`-like states so it reads as inactive). Confirm a `Cancelled` status no longer falls through to a blank/default style.

- [ ] **Step 6: Verify the build**

```bash
cd C:/XAMPP1/htdocs/Airking_Inventory/frontend
npm run build
```
Expected: `Compiled successfully.` No NEW warnings from `TransactionManagement.jsx` / `api.js`. Report the tail.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/services/api.js frontend/src/admin/pages/TransactionManagement.jsx
git commit -m "feat(transactions): clickable issue-out detail with Draft continue/cancel"
```

Manual `npm run demo` still needed: open Transactions, click a Draft issue-out → Continue resumes the wizard; Cancel marks it Cancelled and it stays in history; a Completed issue-out shows read-only detail.

---

## Task 4: Restore barcode scan-to-add in the OrderBuilder

**Files:**
- Modify: `frontend/src/admin/components/issuance/OrderBuilder.jsx`

Context: OrderBuilder already imports `productsAPI` and uses `productsAPI.getAll({ search, per_page })`. The products index searches by barcode (`orWhere('barcode','like',...)`), so a scanned barcode can be resolved by searching for it and picking the row whose `barcode` matches exactly. Adding a line uses the existing `issuanceOrderAPI.addCustom(issuanceId, { product_id, quantity })`; incrementing uses `issuanceOrderAPI.updateLine(issuanceId, lineId, { quantity })`. These are only usable once a Draft exists (i.e. in the post-draft phase where lines are built) — place the barcode input in that phase, alongside the existing add-paths.

- [ ] **Step 1: Read the current add-line flow**

Read `OrderBuilder.jsx` around the post-draft line-building UI (the appliance/set/custom add-paths, ~lines 700–760) and how it calls `onQuoteUpdate` after a mutation, and how the current line list identifies a product's existing line (so you can increment). Match those patterns.

- [ ] **Step 2: Add the barcode input + resolver**

Add a barcode text input at the top of the line-building area:
- Auto-focused, submits on Enter, clears after each attempt (mirror how PickScanner handles the barcode field, but this is add-to-order, not FIFO).
- On submit, `const trimmed = value.trim(); if (!trimmed) return;` then:
  ```javascript
  const res = await productsAPI.getAll({ search: trimmed, per_page: 20 });
  const rows = Array.isArray(res?.data) ? res.data : [];
  const product = rows.find((p) => String(p.barcode) === trimmed);
  if (!product) { toast.error('Unknown barcode', `No product matches "${trimmed}".`); return; }
  ```
- If the product is NOT already a line on the order (check the current lines/quote the component holds by `product_id`): `await issuanceOrderAPI.addCustom(issuanceId, { product_id: product.product_id, quantity: 1 })`.
- If it IS already a line: find its line id and current quantity and `await issuanceOrderAPI.updateLine(issuanceId, lineId, { quantity: currentQty + 1 })`.
- After either, push the returned fresh quote via `onQuoteUpdate` (same as the other add-paths), show a brief success toast (e.g. `${product.product_name} added`), and refocus the input.
- Guard against double-submit with the existing `busy`/`run()` mechanism the component already uses.

Determine the exact "existing line" lookup from the quote shape the component holds (`lines[]` with `product_id`, `issuance_detail_id`, `quantity_requested`) — use the real field names, and note that a set-expanded line vs a loose custom line may both match a product_id; prefer incrementing a loose custom line, and if only a set line exists, adding a new custom line (qty 1) is acceptable. Pick the simplest correct behavior and note which you chose.

- [ ] **Step 3: Verify the build**

```bash
cd C:/XAMPP1/htdocs/Airking_Inventory/frontend
npm run build
```
Expected: `Compiled successfully.` No NEW warnings from `OrderBuilder.jsx`. Report the tail.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/components/issuance/OrderBuilder.jsx
git commit -m "feat(issuance): barcode scan-to-add products in the order builder"
```

Manual `npm run demo` still needed: build a draft issue-out, scan a product barcode, confirm the line is added; scan the same barcode again, confirm the quantity increments; scan an unknown barcode, confirm the error toast and no line added.

---

## Done Criteria

- A `Cancelled` issuance status exists in both DBs and resolves via `IssuanceStatus::cancelled()`.
- `POST /issuances/{id}/cancel` cancels only a Draft (422 otherwise), is idempotent, and is permission-gated; full backend suite green.
- On the Transactions page, an issue-out row is clickable into a detail modal; a Draft shows Continue (resumes the wizard) and Cancel (marks it Cancelled, kept in history); other issue-out statuses are read-only. `Cancelled` renders with a proper chip.
- The OrderBuilder has a barcode input that adds/increments a product line by scan; unknown barcodes error cleanly.
- Frontend builds clean; manual `npm run demo` walkthrough noted for each frontend task.
