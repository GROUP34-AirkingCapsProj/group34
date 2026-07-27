# Issue-out: auto-revert abandoned picks to Draft + custom items in pieces — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make custom issue-out lines enter/display in plain pieces (only Sets use pack/box), and ensure an issue-out abandoned during picking auto-reverts to a clean Draft instead of stranding as "Pending".

**Architecture:** Part A is frontend-only display/entry (`AddCustomPath`, `LineRow`, `QuotePanel`). Part B factors the existing single-row scan reversal (`removeItem`) into a shared helper and adds a Pending-only `revertToDraft` endpoint that un-picks all issued rows, releases reservations, and sets Draft; the wizard fires it on every catchable exit (New Order, unmount, tab-close via `sendBeacon`).

**Tech Stack:** Laravel 10 (PHP 8) + PHPUnit feature tests (`DatabaseTransactions`, dev DB `airkingV5_test`); React (CRA) frontend, no frontend test suite (verify via `npm run build` + manual `npm run demo`).

**Spec:** `docs/superpowers/specs/2026-07-27-issueout-revert-draft-and-custom-pieces-design.md`

---

## File Structure

**Backend**
- Modify `back-end/app/Http/Controllers/Api/IssuanceScanController.php` — extract `reverseIssuedDetail()` private helper from `removeItem()`; add `revertToDraft()` method.
- Modify `back-end/routes/api.php` — register `POST /issuance-scan/{issuance_id}/revert-to-draft` inside the existing `issuance-scan` group.
- Create `back-end/tests/Feature/Issuance/RevertToDraftTest.php` — the revert behaviour tests.

**Frontend**
- Modify `frontend/src/admin/components/issuance/OrderBuilder.jsx` — `AddCustomPath` pieces-only; `LineRow` breakdown gated to set lines.
- Modify `frontend/src/admin/components/issuance/QuotePanel.jsx` — `renderLine` breakdown gated to set lines.
- Modify `frontend/src/admin/services/api.js` — add `issuanceScanAPI.revertToDraft(id)` and `revertToDraftUrl(id)`.
- Modify `frontend/src/admin/pages/IssuanceScanPage.jsx` — fire revert on New Order / unmount / `beforeunload`.

**Build order:** Backend (Tasks 1–2) first — Part B's frontend (Task 6) depends on the endpoint. Part A (Tasks 3–4) is independent and can be done any time.

---

### Task 1: Extract the single-row reversal into a shared helper

Refactor only — `removeItem` behaviour must not change. This gives `revertToDraft`
(Task 2) one implementation of "reverse one scanned row".

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/IssuanceScanController.php` (method `removeItem` ~496-560; add private helper near the other private helpers ~803+)

- [ ] **Step 1: Run the existing reversal test to establish the green baseline**

Run: `cd back-end && php artisan test --filter=IssuanceRemoveItemBatchTest`
Expected: PASS (1 test) — this is the behaviour the refactor must preserve.

- [ ] **Step 2: Add the `reverseIssuedDetail` private helper**

Add this method to `IssuanceScanController` (place it just above `recalculateTotals()` in the PRIVATE HELPERS section). It is the exact body currently inside `removeItem`'s transaction, minus the response, generalized to take the detail + issuance:

```php
    /**
     * Reverse one issued detail row: return its pieces to inventory + FIFO batch,
     * recreate the serial for a serialized appliance, then delete the row. Caller
     * owns the surrounding transaction and any status change. Shared by removeItem()
     * (undo one scan) and revertToDraft() (un-pick a whole order).
     */
    private function reverseIssuedDetail(IssuanceDetail $detail, Issuance $issuance): void
    {
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

                app(InventoryBatchService::class)->restoreFifo(
                    $detail->product_id,
                    $issuance->location_id,
                    $detail->quantity_in_pieces,
                    $detail->receiving_id
                );
            }

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
    }
```

- [ ] **Step 3: Replace `removeItem`'s transaction body with a call to the helper**

Replace the `return DB::transaction(function () use ($detail, $issuance) { ... });`
block (lines ~511-559) with:

```php
        return DB::transaction(function () use ($detail, $issuance) {
            $this->reverseIssuedDetail($detail, $issuance);
            $this->recalculateTotals($issuance->issuance_id);

            return response()->json([
                'success' => true,
                'message' => 'Item removed from issuance.',
            ]);
        });
```

- [ ] **Step 4: Run the reversal test — behaviour must be unchanged**

Run: `cd back-end && php artisan test --filter=IssuanceRemoveItemBatchTest`
Expected: PASS (1 test) — same as the Step 1 baseline.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/IssuanceScanController.php
git commit -m "refactor(issuance): extract reverseIssuedDetail helper from removeItem

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: `revertToDraft` endpoint

Pending-only. Un-picks every issued row, releases reservations, zeroes shortages on
the surviving requested lines, and sets status Draft. Idempotent on an already-Draft
order; 422 (no mutation) on a completed/terminal one.

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/IssuanceScanController.php` (add `revertToDraft` after `completeIssuance`)
- Modify: `back-end/routes/api.php` (issuance-scan group, ~439-452)
- Test: `back-end/tests/Feature/Issuance/RevertToDraftTest.php`

- [ ] **Step 1: Write the failing tests**

Create `back-end/tests/Feature/Issuance/RevertToDraftTest.php`:

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
use App\Models\PickReservation;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use App\Services\InventoryBatchService;
use App\Support\IssuanceStatus;

class RevertToDraftTest extends TestCase
{
    use DatabaseTransactions;

    private const STATUS_PENDING = 30;

    /** Build a location + user + customer and return [$location, $user, $customer]. */
    private function scaffold(): array
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $customer = Customer::create(['customer_name' => 'Test Customer', 'customer_type' => 'individual']);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);

        return [$location, $user, $customer];
    }

    private function makeIssuance($location, $user, $customer, int $statusId): Issuance
    {
        return Issuance::create([
            'location_id' => $location->location_id, 'customer_id' => $customer->customer_id,
            'issuance_date' => now()->toDateString(), 'issuance_type' => 'individual',
            'dr_number' => 'DR-TEST-' . uniqid(), 'discount_type' => 'none', 'discount_percent' => 0,
            'subtotal' => 0, 'discount_amount' => 0, 'total_amount' => 0,
            'issued_by' => $user->user_id, 'status_id' => $statusId,
        ]);
    }

    public function test_revert_pending_with_no_picks_returns_to_draft_and_releases_reservations(): void
    {
        [$location, $user, $customer] = $this->scaffold();
        $product = Product::create([
            'product_code' => 'MAT-' . uniqid(), 'product_name' => 'Test Material',
            'product_type' => 'installation_material', 'unit_price' => 50,
        ]);
        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 10, 'available_quantity' => 10, 'reorder_level' => 0,
        ]);

        $issuance = $this->makeIssuance($location, $user, $customer, self::STATUS_PENDING);
        // A requested (not-yet-picked) line, with a recorded shortage to prove it zeroes.
        $requested = IssuanceDetail::create([
            'issuance_id' => $issuance->issuance_id, 'product_id' => $product->product_id,
            'quantity_requested' => 4, 'quantity_issued' => 0, 'quantity_short' => 1,
            'unit_price' => 50, 'subtotal' => 200,
        ]);
        PickReservation::create([
            'source_type' => 'issuance', 'source_id' => $issuance->issuance_id,
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'quantity' => 4, 'expires_at' => now()->addMinutes(240),
        ]);

        $this->actingAs($user)
            ->postJson("/api/issuance-scan/{$issuance->issuance_id}/revert-to-draft")
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame(IssuanceStatus::draft(), (int) $issuance->fresh()->status_id);
        $this->assertSame(0, (int) $requested->fresh()->quantity_short);
        $this->assertSame(0, PickReservation::active()
            ->forSource('issuance', $issuance->issuance_id)->count());
    }

    public function test_revert_restores_a_scanned_consumable_pick(): void
    {
        [$location, $user, $customer] = $this->scaffold();
        $product = Product::create([
            'product_code' => 'MAT-' . uniqid(), 'product_name' => 'Test Material',
            'product_type' => 'installation_material', 'unit_price' => 50,
        ]);
        $rcv   = Receiving::create(['location_id' => $location->location_id, 'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()]);
        $batch = (new InventoryBatchService())->createBatch($product->product_id, $location->location_id, $rcv->receiving_id, 10, now());
        $batch->quantity_remaining = 7; // 3 already picked
        $batch->save();
        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 7, 'available_quantity' => 7, 'reorder_level' => 0,
        ]);

        $issuance = $this->makeIssuance($location, $user, $customer, self::STATUS_PENDING);
        // One issued row representing the 3 picked pieces.
        IssuanceDetail::create([
            'issuance_id' => $issuance->issuance_id, 'product_id' => $product->product_id,
            'receiving_id' => $rcv->receiving_id, 'quantity_issued' => 3, 'quantity_unit' => 'piece',
            'quantity_in_pieces' => 3, 'unit_price' => 50, 'unit_price_per_piece' => 50, 'subtotal' => 150,
            'condition_issued' => 'Good',
        ]);

        $this->actingAs($user)
            ->postJson("/api/issuance-scan/{$issuance->issuance_id}/revert-to-draft")
            ->assertOk();

        $inv = Inventory::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)->first();
        $this->assertSame(10, (int) $inv->quantity_on_hand);
        $this->assertSame(10, (int) $batch->fresh()->quantity_remaining);
        $this->assertSame(0, IssuanceDetail::where('issuance_id', $issuance->issuance_id)
            ->where('quantity_issued', '>', 0)->count());
        $this->assertSame(IssuanceStatus::draft(), (int) $issuance->fresh()->status_id);
    }

    public function test_revert_recreates_a_scanned_serialized_appliance(): void
    {
        [$location, $user, $customer] = $this->scaffold();
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Test Appliance',
            'product_type' => 'appliance', 'unit_price' => 100,
        ]);
        $rcv   = Receiving::create(['location_id' => $location->location_id, 'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()]);
        $batch = (new InventoryBatchService())->createBatch($product->product_id, $location->location_id, $rcv->receiving_id, 1, now());
        $batch->quantity_remaining = 0;
        $batch->save();
        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 0, 'available_quantity' => 0, 'reorder_level' => 0,
        ]);

        $issuance = $this->makeIssuance($location, $user, $customer, self::STATUS_PENDING);
        $sn = 'SN-' . uniqid();
        IssuanceDetail::create([
            'issuance_id' => $issuance->issuance_id, 'product_id' => $product->product_id,
            'serial_number' => $sn, 'receiving_id' => $rcv->receiving_id,
            'unit_price' => 100, 'quantity_issued' => 1, 'quantity_unit' => 'unit',
            'quantity_in_pieces' => 1, 'unit_price_per_piece' => 100, 'subtotal' => 100,
            'condition_issued' => 'Good',
        ]);

        $this->actingAs($user)
            ->postJson("/api/issuance-scan/{$issuance->issuance_id}/revert-to-draft")
            ->assertOk();

        $this->assertSame(1, (int) $batch->fresh()->quantity_remaining);
        $this->assertNotNull(ItemSerial::where('serial_number', $sn)->first());
        $this->assertSame(IssuanceStatus::draft(), (int) $issuance->fresh()->status_id);
    }

    public function test_revert_is_idempotent_on_a_draft_and_rejects_a_completed_order(): void
    {
        [$location, $user, $customer] = $this->scaffold();

        $draft = $this->makeIssuance($location, $user, $customer, IssuanceStatus::draft());
        $this->actingAs($user)
            ->postJson("/api/issuance-scan/{$draft->issuance_id}/revert-to-draft")
            ->assertOk()->assertJson(['success' => true]);
        $this->assertSame(IssuanceStatus::draft(), (int) $draft->fresh()->status_id);

        $completed = $this->makeIssuance($location, $user, $customer, 31); // Completed
        $this->actingAs($user)
            ->postJson("/api/issuance-scan/{$completed->issuance_id}/revert-to-draft")
            ->assertStatus(422);
        $this->assertSame(31, (int) $completed->fresh()->status_id);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd back-end && php artisan test --filter=RevertToDraftTest`
Expected: FAIL — route `POST /issuance-scan/{id}/revert-to-draft` returns 404 (not yet registered).

- [ ] **Step 3: Add the `revertToDraft` controller method**

Add this method to `IssuanceScanController`, immediately after `completeIssuance()`
(around line 700). It uses the `reverseIssuedDetail` helper from Task 1:

```php
    // =========================================================
    // POST /api/issuance-scan/{issuance_id}/revert-to-draft
    // Un-pick an abandoned picking-phase order back to a clean Draft:
    // reverse every scanned row, release the reservations, zero the
    // shortages, set Draft. Pending-only; idempotent on a Draft.
    // =========================================================
    public function revertToDraft(int $issuanceId): JsonResponse
    {
        return DB::transaction(function () use ($issuanceId) {
            $issuance = Issuance::lockForUpdate()->find($issuanceId);

            if (! $issuance) {
                return $this->notFound('Issuance not found.');
            }

            // Already a Draft → nothing to undo. Idempotent success so a
            // best-effort client trigger (unmount / beacon) never errors.
            if ((int) $issuance->status_id === \App\Support\IssuanceStatus::draft()) {
                return response()->json(['success' => true, 'message' => 'Already a draft.']);
            }

            if ((int) $issuance->status_id !== self::STATUS_ISSUANCE_PENDING) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only a picking-phase (pending) issue-out can be reverted to draft.',
                ], 422);
            }

            // Reverse every scanned row (return stock, recreate serials, delete row).
            $issued = IssuanceDetail::where('issuance_id', $issuance->issuance_id)
                ->where('quantity_issued', '>', 0)
                ->get();
            foreach ($issued as $detail) {
                $this->reverseIssuedDetail($detail, $issuance);
            }

            // Drop the confirmed-quote holds.
            app(\App\Services\ReservationService::class)
                ->releaseFor('issuance', (int) $issuance->issuance_id);

            // Keep the requested lines so the draft is resumable; clear shortages
            // recorded at confirm-quote so a re-confirm recomputes cleanly.
            IssuanceDetail::where('issuance_id', $issuance->issuance_id)
                ->update(['quantity_short' => 0]);

            $this->recalculateTotals($issuance->issuance_id);
            $issuance->update(['status_id' => \App\Support\IssuanceStatus::draft()]);

            return response()->json([
                'success'     => true,
                'message'     => 'Order reverted to draft.',
                'issuance_id' => (int) $issuance->issuance_id,
            ]);
        });
    }
```

- [ ] **Step 4: Register the route**

In `back-end/routes/api.php`, inside the `issuance-scan` group (after the
`/complete/{issuance_id}` line, ~449), add:

```php
             Route::post('/{issuance_id}/revert-to-draft',
                 [IssuanceScanController::class, 'revertToDraft']);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd back-end && php artisan test --filter=RevertToDraftTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the issuance suite to confirm no regression**

Run: `cd back-end && php artisan test --filter=Issuance`
Expected: PASS (all issuance tests, including IssuanceRemoveItemBatchTest).

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/IssuanceScanController.php back-end/routes/api.php back-end/tests/Feature/Issuance/RevertToDraftTest.php
git commit -m "feat(issuance): revertToDraft un-picks an abandoned pending order back to draft

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Custom-item adder is pieces-only

Remove the forced pack/box entry from the "Add custom item" path. No test suite for
the frontend — verify with a production build.

**Files:**
- Modify: `frontend/src/admin/components/issuance/OrderBuilder.jsx` (`AddCustomPath`, ~410-539)

- [ ] **Step 1: Replace the `AddCustomPath` quantity logic and inputs**

In `AddCustomPath`, delete the `packages`/`pieces` state and the pack/box maths, and
render a single Qty (pieces) field.

Remove these three `useState` lines (~419-421):

```jsx
  const [qty, setQty] = useState(1);         // used when the product has no pack size
  const [packages, setPackages] = useState(0); // used when pieces_per_package > 1
  const [pieces, setPieces] = useState(0);     // loose pieces alongside packages
```

Replace with:

```jsx
  const [qty, setQty] = useState(1); // pieces — custom items are always plain pieces
```

Remove the pack/box derivation block (~458-466):

```jsx
  const ppp = picked && picked.product_type !== 'appliance' ? (Number(picked.pieces_per_package) || 0) : 0;
  const usePack = ppp > 1;
  const totalPieces = usePack
    ? (parseInt(packages, 10) || 0) * ppp + (parseInt(pieces, 10) || 0)
    : (parseInt(qty, 10) || 0);

  const resetQty = () => { setQty(1); setPackages(0); setPieces(0); };
```

Replace with:

```jsx
  const totalPieces = parseInt(qty, 10) || 0;

  const resetQty = () => { setQty(1); };
```

- [ ] **Step 2: Replace the conditional pack/box inputs with a single Qty field**

Replace the `{usePack ? ( ... ) : ( ... )}` block (~513-529) with just the plain Qty
input:

```jsx
        <div className="ak-field" style={{ width: 100 }}>
          <label className="ak-label">Qty</label>
          <input className="ak-input" type="number" min={1} value={qty} onChange={(e) => setQty(e.target.value)} disabled={disabled || !picked} />
        </div>
```

- [ ] **Step 3: Remove the pack/box helper line**

Delete the trailing breakdown hint block (~532-536):

```jsx
      {usePack && totalPieces > 0 && (
        <div className="ak-muted" style={{ fontSize: '0.78rem' }}>
          = {totalPieces} pcs{packageBreakdown(totalPieces, ppp, picked.package_unit) ? ` · ${packageBreakdown(totalPieces, ppp, picked.package_unit)}` : ''}
        </div>
      )}
```

(Leave the closing `</div>` of the wrapping `ak-field`.)

- [ ] **Step 4: Verify the build compiles**

Run: `cd frontend && npm run build`
Expected: "Compiled successfully" (or compiled with only pre-existing warnings) — no
new errors. `packageBreakdown` is still imported and used by `LineRow`, so the import
stays.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/issuance/OrderBuilder.jsx
git commit -m "feat(issuance): custom-item adder is pieces-only (pack/box is Sets only)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: Pack/box breakdown only on Set lines (Order + Quote)

Custom (loose) lines show plain `×N`; Set lines keep the pack/box breakdown.

**Files:**
- Modify: `frontend/src/admin/components/issuance/OrderBuilder.jsx` (`LineRow`, ~582-587)
- Modify: `frontend/src/admin/components/issuance/QuotePanel.jsx` (`renderLine`, ~97-101)

- [ ] **Step 1: Gate the breakdown in `LineRow`**

In `OrderBuilder.jsx`, the read-only quantity span (~583-587) currently reads:

```jsx
          <span className="ak-mono" style={{ minWidth: 32, textAlign: 'right' }}>
            ×{line.quantity_requested}
            {packageBreakdown(line.quantity_requested, line.pieces_per_package, line.package_unit)
              && <span className="ak-muted" style={{ marginLeft: 6, fontWeight: 400 }}>· {packageBreakdown(line.quantity_requested, line.pieces_per_package, line.package_unit)}</span>}
          </span>
```

Replace with (only set lines — `issuance_set_id != null` — get the breakdown):

```jsx
          <span className="ak-mono" style={{ minWidth: 32, textAlign: 'right' }}>
            ×{line.quantity_requested}
            {line.issuance_set_id != null
              && packageBreakdown(line.quantity_requested, line.pieces_per_package, line.package_unit)
              && <span className="ak-muted" style={{ marginLeft: 6, fontWeight: 400 }}>· {packageBreakdown(line.quantity_requested, line.pieces_per_package, line.package_unit)}</span>}
          </span>
```

- [ ] **Step 2: Gate the breakdown in `QuotePanel.renderLine`**

In `QuotePanel.jsx`, the muted quantity line (~97-101) currently reads:

```jsx
        <div className="ak-muted" style={{ fontSize: '0.8rem' }}>
          ×{l.quantity_requested}
          {packageBreakdown(l.quantity_requested, l.pieces_per_package, l.package_unit)
            && ` (${packageBreakdown(l.quantity_requested, l.pieces_per_package, l.package_unit)})`}
          {' · '}{fmtMoney(l.unit_price)} each
        </div>
```

Replace with:

```jsx
        <div className="ak-muted" style={{ fontSize: '0.8rem' }}>
          ×{l.quantity_requested}
          {l.issuance_set_id != null
            && packageBreakdown(l.quantity_requested, l.pieces_per_package, l.package_unit)
            && ` (${packageBreakdown(l.quantity_requested, l.pieces_per_package, l.package_unit)})`}
          {' · '}{fmtMoney(l.unit_price)} each
        </div>
```

- [ ] **Step 3: Verify the build compiles**

Run: `cd frontend && npm run build`
Expected: "Compiled successfully" — no new errors.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/components/issuance/OrderBuilder.jsx frontend/src/admin/components/issuance/QuotePanel.jsx
git commit -m "feat(issuance): pack/box breakdown only on Set lines; custom lines show pieces

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: `revertToDraft` API helpers

**Files:**
- Modify: `frontend/src/admin/services/api.js` (`issuanceScanAPI`, ~1078-1101)

- [ ] **Step 1: Add `revertToDraft` and `revertToDraftUrl`**

In `issuanceScanAPI`, add these two entries (e.g. after `complete`, before
`drCsvUrl`). `revertToDraftUrl` mirrors `drCsvUrl` so a `beforeunload` beacon can
authenticate via the `?token=` query param:

```jsx
  revertToDraft: async (issuanceId) =>
    apiRequest(`/issuance-scan/${issuanceId}/revert-to-draft`, { method: 'POST' }),
  revertToDraftUrl: (issuanceId) => {
    const base = getApiBaseUrl();
    const token = getToken();
    return `${base}/issuance-scan/${issuanceId}/revert-to-draft${token ? '?token=' + token : ''}`;
  },
```

- [ ] **Step 2: Verify the build compiles**

Run: `cd frontend && npm run build`
Expected: "Compiled successfully" — no new errors.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -m "feat(issuance): add revertToDraft API + beacon URL helper

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 6: Fire revert-to-Draft on every catchable wizard exit

An abandoned picking-phase order reverts to Draft on New Order, on unmount/navigation,
and on tab-close. A ref mirrors the live "revertable" state so async cleanups read the
current value, and a one-shot guard prevents a double-fire.

**Files:**
- Modify: `frontend/src/admin/pages/IssuanceScanPage.jsx`

- [ ] **Step 1: Import `useRef`**

The file already imports `useCallback, useEffect, useState` from React (line 1). Add
`useRef`:

```jsx
import React, { useCallback, useEffect, useRef, useState } from 'react';
```

- [ ] **Step 2: Add the revertable-state ref and a one-shot guard**

Immediately after `const isPending = ...` (~line 84) and the `currentStep`
derivation, add:

```jsx
  // Mirror the live "an abandoned pick should revert to Draft" condition into a ref
  // so unmount/beforeunload cleanups (which capture stale state) read the truth.
  const revertableRef = useRef(false);
  const revertFiredRef = useRef(false);
  useEffect(() => {
    revertableRef.current = Boolean(issuanceId) && isPending && !isCompleted && !isTerminal;
  }, [issuanceId, isPending, isCompleted, isTerminal]);
```

- [ ] **Step 3: Add a reusable revert trigger and wire New Order**

Add this callback after `resetAll` (~line 274) — it awaits the revert (best-effort)
before the caller clears state:

```jsx
  // Best-effort revert of an abandoned picking-phase order to Draft. Safe to call
  // when nothing is revertable (no-op) and safe to call twice (server is idempotent).
  const revertIfAbandoned = useCallback(async () => {
    if (!revertableRef.current || revertFiredRef.current || !issuanceId) return;
    revertFiredRef.current = true;
    try {
      await issuanceScanAPI.revertToDraft(issuanceId);
    } catch {
      /* best-effort — the order stays resumable/cancellable either way */
    }
  }, [issuanceId]);
```

Then change the "New Order" header button (~316-318) to revert first:

```jsx
            {issuanceId && (
              <button type="button" className="isp-btn-secondary" onClick={async () => { await revertIfAbandoned(); resetAll(); }}>New Order</button>
            )}
```

Note: `resetAll` sets `revertFiredRef`/state fresh for the next order — add
`revertFiredRef.current = false;` inside `resetAll` (top of its body) so a brand-new
order can revert again later:

```jsx
  const resetAll = useCallback(() => {
    revertFiredRef.current = false;
    setSession(null);
```

- [ ] **Step 4: Add the unmount + beforeunload effect**

Add this effect near the other effects (after the mount rehydrate effect, ~159). It
registers a `beforeunload` beacon and, on unmount, fires the revert for a still-open
abandoned pick:

```jsx
  // Revert an abandoned picking-phase order on hard exits: a tab close/refresh fires
  // a sendBeacon (best-effort), and leaving the page (unmount) fires the endpoint.
  useEffect(() => {
    const onBeforeUnload = () => {
      if (revertableRef.current && !revertFiredRef.current && issuanceId) {
        revertFiredRef.current = true;
        try {
          navigator.sendBeacon(issuanceScanAPI.revertToDraftUrl(issuanceId));
        } catch { /* best-effort */ }
      }
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    return () => {
      window.removeEventListener('beforeunload', onBeforeUnload);
      // Navigating away inside the app: revert the abandoned pick (fire-and-forget).
      if (revertableRef.current && !revertFiredRef.current && issuanceId) {
        revertFiredRef.current = true;
        issuanceScanAPI.revertToDraft(issuanceId).catch(() => {});
      }
    };
  }, [issuanceId]);
```

- [ ] **Step 5: Verify the build compiles**

Run: `cd frontend && npm run build`
Expected: "Compiled successfully" — no new errors.

- [ ] **Step 6: Manual smoke test (npm run demo)**

Run: `cd frontend && npm run demo` (prod build served locally, per project convention).
Walk through:
1. Start an order, add a Set (confirm its lines show pack/box) and a custom install
   material with `pieces_per_package > 1` (confirm the adder shows ONE Qty field and
   the Order + Quote rows show `×N` with NO pack/box).
2. Confirm the quote → Pick step. Scan 1 of several items. Click **New Order** →
   confirm (in Transactions) the order is now a **Draft**, and the scanned unit is
   back in stock.
3. Reopen that Draft via **Continue**, confirm again, then navigate away via the
   sidebar → confirm it reverts to Draft again.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/pages/IssuanceScanPage.jsx
git commit -m "feat(issuance): auto-revert abandoned picks to draft on New Order/unmount/tab-close

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Final verification

- [ ] **Backend suite green:** `cd back-end && php artisan test`
  Expected: all pass (baseline + 4 new RevertToDraft tests).
- [ ] **Frontend build clean:** `cd frontend && npm run build` — "Compiled successfully".

---

## Self-Review notes (author)

- **Spec coverage:** Part A → Tasks 3 (adder) + 4 (Order/Quote display); confirmed
  PickScanner needs no change (suggest() omits packaging). Part B backend → Tasks 1
  (shared helper) + 2 (endpoint + tests: no-pick, consumable, serialized, idempotency/
  reject-completed); Part B frontend → Tasks 5 (api) + 6 (New Order/unmount/beacon).
- **Placeholders:** none — every code + test block is complete.
- **Type/name consistency:** `reverseIssuedDetail(IssuanceDetail, Issuance)` defined in
  Task 1, used in Task 2; `revertToDraft` / `revertToDraftUrl` defined in Task 5, used
  in Task 6; `revertableRef` / `revertFiredRef` / `revertIfAbandoned` consistent within
  Task 6.
```
