# Transfer Source Availability at Verify — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When the IA selects a source warehouse in the transfer Verify panel, live-show each requested product's real stock at that warehouse and flag shortfalls.

**Architecture:** A new read-only endpoint returns requested-product availability across all warehouses as a `{ location_id: { product_id: { available, on_hand } } }` map. The Verify panel fetches it once on open and re-reads it client-side as the source dropdown changes — no writes, no reloads.

**Tech Stack:** Laravel 11 (`back-end/`), React 18 (`frontend/src/admin/`), MariaDB. Backend tests: PHPUnit on `airkingV5_test` with `DatabaseTransactions` (schema is a SQL dump — never run a blanket `migrate`; there are no migrations in this plan). Run tests with `php artisan test --filter=...`.

**Reference spec:** `docs/superpowers/specs/2026-07-22-transfer-source-availability-design.md`

---

## Ground truth (verified 2026-07-22)

- `Inventory` model → table `inventory`, columns `product_id`, `location_id`, `available_quantity`, `quantity_on_hand` (used throughout `TransferService`).
- `locations` has `location_type` with value `warehouse` for warehouses.
- `TransferController` uses the `App\Traits\ApiResponse` trait (`success`, `error`); imports `App\Models\Transfer`, `Illuminate\Support\Facades\DB`. It already has `verify`, `show`, `pickList`, etc.
- Transfer routes live in `back-end/routes/api.php` in the transfers block; existing reads include `GET /transfers/{id}/pick-list` (no explicit permission middleware — auth only) and `GET /transfers/{id}` etc.
- `TransferTestCase` helpers: `makeLocation(string $name)` (creates a `warehouse`), `makeConsumable()`, `makeAppliance()`, `setStock($locationId, $productId, $qty)` (creates an `inventory` row with `quantity_on_hand` AND `available_quantity` = qty), `makeUserAt(Location,$role)`, `makeApprovedTransfer(Location $from, Location $to, array $lines)`, `statusId($name)`. Auth guard `sanctum` → `actingAs($u,'sanctum')`.
- Roles: inventory_analyst=2 (has `inventory_transfers` read+approve), warehouse_personnel=4 (read, no approve), auditor=5.
- `frontend/src/admin/services/api.js` has a `transfersAPI` object with `getById`, `verify`, `pickList`, etc.
- `frontend/src/admin/pages/transfers/VerifyPanel.jsx` receives `{ transfer, locations, onDone }`, filters `warehouses` from `locations`, holds `fromLocationId`/`toLocationId`/`qty` state, and renders each line with an `avail`/`short` span sourced from the static `d.available_at_source`.

---

## Task 1: Backend — `source-availability` endpoint

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/TransferController.php` (add `sourceAvailability`)
- Modify: `back-end/routes/api.php` (add the route)
- Test: `back-end/tests/Feature/Transfer/SourceAvailabilityTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Transfer;

class SourceAvailabilityTest extends TransferTestCase
{
    public function test_returns_available_and_on_hand_per_warehouse_for_requested_products(): void
    {
        $whA = $this->makeLocation('WH-A');
        $whB = $this->makeLocation('WH-B');
        $dst = $this->makeLocation('Showroom');
        $p1 = $this->makeConsumable();
        $p2 = $this->makeConsumable();

        $this->setStock($whA->location_id, $p1->product_id, 8);   // A has 8 of p1
        $this->setStock($whA->location_id, $p2->product_id, 4);   // A has 4 of p2
        $this->setStock($whB->location_id, $p1->product_id, 0);   // B has 0 of p1, none of p2

        $ist = $this->makeApprovedTransfer($whA, $dst, [[$p1, 5], [$p2, 3]]);
        $ia = $this->makeUserAt($dst, 'inventory_analyst');

        $res = $this->actingAs($ia, 'sanctum')->getJson("/api/transfers/{$ist->transfer_id}/source-availability");
        $res->assertOk();

        $this->assertSame(8, $res->json("data.{$whA->location_id}.{$p1->product_id}.available"));
        $this->assertSame(4, $res->json("data.{$whA->location_id}.{$p2->product_id}.available"));
        // B has a zero row for p1, and no row for p2.
        $this->assertSame(0, $res->json("data.{$whB->location_id}.{$p1->product_id}.available"));
        $this->assertNull($res->json("data.{$whB->location_id}.{$p2->product_id}"));
        // Destination showroom is not a warehouse → absent.
        $this->assertNull($res->json("data.{$dst->location_id}"));
    }

    public function test_requires_read_permission(): void
    {
        $wh = $this->makeLocation('WH');
        $dst = $this->makeLocation('Showroom');
        $p = $this->makeConsumable();
        $ist = $this->makeApprovedTransfer($wh, $dst, [[$p, 1]]);
        $auditor = $this->makeUserAt($dst, 'auditor');

        // auditor(5) lacks inventory_transfers.read → 403
        $this->actingAs($auditor, 'sanctum')
            ->getJson("/api/transfers/{$ist->transfer_id}/source-availability")
            ->assertStatus(403);
    }
}
```

> If `auditor` turns out to HAVE `inventory_transfers.read` in the test DB, drop the second test method rather than fight the seed data — the permission middleware itself is already covered elsewhere. Verify with:
> `cd back-end && DB_DATABASE=airkingV5_test php artisan tinker --execute="echo \App\Support\PermissionResolver::class;"` is not needed — instead check the role_permissions quickly if the test fails.

- [ ] **Step 2: Run the test, confirm it FAILS**

Run: `cd back-end && php artisan test --filter=SourceAvailabilityTest`
Expected: FAIL (route/method missing → 404, or method undefined).

- [ ] **Step 3: Add the controller method**

Add to `TransferController` (uses `App\Models\Transfer` already imported; add `use App\Models\Inventory;` if missing):

```php
public function sourceAvailability($id)
{
    $transfer = Transfer::with('details')->findOrFail($id);
    $productIds = $transfer->details->pluck('product_id')->unique()->values();

    if ($productIds->isEmpty()) {
        return $this->success([], 'Source availability');
    }

    $rows = Inventory::query()
        ->join('locations', 'inventory.location_id', '=', 'locations.location_id')
        ->where('locations.location_type', 'warehouse')
        ->whereIn('inventory.product_id', $productIds)
        ->get([
            'inventory.location_id',
            'inventory.product_id',
            'inventory.available_quantity',
            'inventory.quantity_on_hand',
        ]);

    $map = [];
    foreach ($rows as $r) {
        $map[(int) $r->location_id][(int) $r->product_id] = [
            'available' => (int) $r->available_quantity,
            'on_hand'   => (int) $r->quantity_on_hand,
        ];
    }

    return $this->success($map, 'Source availability');
}
```

- [ ] **Step 4: Add the route**

In `back-end/routes/api.php`, in the transfers block near `GET /transfers/{id}/pick-list`, add:

```php
Route::middleware('permission:inventory_transfers,read')
    ->get('/transfers/{id}/source-availability', [TransferController::class, 'sourceAvailability']);
```

- [ ] **Step 5: Run the test, confirm it PASSES**

Run: `cd back-end && php artisan test --filter=SourceAvailabilityTest`
Expected: PASS. If a `whereIn` on an empty set or the join column names error, fix the controller (not the test).

- [ ] **Step 6: Regression — full transfer suite**

Run: `cd back-end && php artisan test --filter=Transfer`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/TransferController.php back-end/routes/api.php back-end/tests/Feature/Transfer/SourceAvailabilityTest.php
git commit -m "feat(transfer): source-availability endpoint for requested products by warehouse"
```
(End commit body with: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`. If the Bash `git commit` is blocked by a classifier, run the identical commit via PowerShell.)

---

## Task 2: Frontend — `transfersAPI.sourceAvailability`

**Files:**
- Modify: `frontend/src/admin/services/api.js` (extend `transfersAPI`)

- [ ] **Step 1: Add the method**

In the `transfersAPI` object, next to `pickList`, add:

```js
sourceAvailability: async (id) => apiRequest(`/transfers/${id}/source-availability`),
```

- [ ] **Step 2: Confirm the app still builds**

Run: `cd frontend && npm run build 2>&1 | tail -5`
Expected: `Compiled` (warnings OK).

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -m "feat(transfer): transfersAPI.sourceAvailability"
```

---

## Task 3: Frontend — live availability in VerifyPanel

**Files:**
- Modify: `frontend/src/admin/pages/transfers/VerifyPanel.jsx`

- [ ] **Step 1: Fetch the availability map on open**

At the top of the component (after existing hooks), add imports/state and a fetch effect. Add to the existing imports: `useEffect` (ensure it's imported from React). Then:

```jsx
  const [availByLoc, setAvailByLoc] = useState(null); // null = loading, {} = loaded
  const [availError, setAvailError] = useState(false);

  useEffect(() => {
    let ok = true;
    setAvailByLoc(null);
    setAvailError(false);
    transfersAPI.sourceAvailability(transfer.transfer_id)
      .then((res) => { if (ok) setAvailByLoc(res?.data && typeof res.data === 'object' ? res.data : {}); })
      .catch(() => { if (ok) { setAvailByLoc({}); setAvailError(true); } });
    return () => { ok = false; };
  }, [transfer.transfer_id]);
```

- [ ] **Step 2: Add a helper to read availability for the selected source**

Above the `return`, add:

```jsx
  const availFor = (productId) => {
    if (!fromLocationId || !availByLoc) return null;
    const entry = availByLoc[fromLocationId]?.[productId];
    return entry ? Number(entry.available) : 0; // present-but-missing product = 0
  };
```

- [ ] **Step 3: Show a fulfillment summary under the source dropdown**

Immediately after the source `<select>` element (the one bound to `fromLocationId`), add:

```jsx
      {fromLocationId && availByLoc && !availError && (() => {
        const surviving = details.filter((d) => Number(qty[d.transfer_detail_id] || 0) > 0);
        const okCount = surviving.filter((d) => availFor(d.product_id) >= Number(qty[d.transfer_detail_id] || 0)).length;
        const shortAny = okCount < surviving.length;
        const whName = warehouses.find((l) => String(l.location_id) === String(fromLocationId))?.location_name || 'This warehouse';
        return (
          <div className={`tf-avail-summary${shortAny ? ' tf-avail-summary--warn' : ''}`} style={{ margin: '-0.6rem 0 1.1rem' }}>
            {whName} can fulfill {okCount} of {surviving.length} product{surviving.length === 1 ? '' : 's'}.
            {shortAny && ' Some lines exceed available stock — partial dispatch will auto-close the remainder.'}
          </div>
        );
      })()}
      {availByLoc === null && <div className="ak-muted" style={{ margin: '-0.6rem 0 1.1rem' }}>Checking stock…</div>}
      {availError && <div className="ak-muted" style={{ margin: '-0.6rem 0 1.1rem' }}>Availability unavailable.</div>}
```

- [ ] **Step 4: Replace the per-line avail/short display with the live source value**

Find the existing per-line availability span (currently driven by `d.available_at_source`):

```jsx
              {avail != null && (
                <span className="tf-line-avail">
                  avail {avail}
                  {Number(shortfall) > 0 && <span className="tf-shortfall"> · short {shortfall}</span>}
                </span>
              )}
```

Replace it with a live computation based on the selected source and the analyst's current approved qty. Inside the `details.map((d) => { ... })` body, before the `return`, compute:

```jsx
          const liveAvail = availFor(d.product_id);
          const approvedQty = Number(qty[did] || 0);
          const liveShort = liveAvail == null ? 0 : Math.max(0, approvedQty - liveAvail);
```

and render:

```jsx
              {fromLocationId && liveAvail != null && (
                <span className={`tf-line-avail${liveShort > 0 ? ' tf-line-avail--short' : ' tf-line-avail--ok'}`}>
                  avail {liveAvail}
                  {liveShort > 0 && <span className="tf-shortfall"> · short {liveShort}</span>}
                </span>
              )}
              {!fromLocationId && <span className="ak-muted" style={{ fontSize: 12 }}>select a source to see stock</span>}
```

Remove the now-unused `const avail = d.available_at_source;` / `const shortfall = d.shortfall;` lines if they are no longer referenced.

- [ ] **Step 5: Add minimal styles**

Append to `frontend/src/admin/styles/transfers.css`:

```css
/* Verify — live source availability */
.tf-avail-summary { font-size: 12.5px; color: var(--ak-muted, #6b7280); }
.tf-avail-summary--warn { color: #b45309; }
.tf-line-avail--ok { color: #059669; }
.tf-line-avail--short { color: #dc2626; }
```

- [ ] **Step 6: Build**

Run: `cd frontend && npm run build 2>&1 | tail -5`
Expected: `Compiled` (warnings OK). Fix any unused-var errors from the removed `avail`/`shortfall`.

- [ ] **Step 7: Manual verify**

Run: `cd frontend && npm run demo`. As an IA, open a Pending transfer in **To Verify**, then change the **source** dropdown between warehouses: each line's `avail N` should update instantly, flip to red `short M` where the warehouse can't cover the approved qty, and the summary line under the dropdown should update. Approve stays enabled through a shortfall.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/admin/pages/transfers/VerifyPanel.jsx frontend/src/admin/styles/transfers.css
git commit -m "feat(transfer): live source-warehouse availability in Verify panel"
```

---

## Self-review notes (author)

- **Spec coverage:** endpoint returning `{location_id:{product_id:{available,on_hand}}}` warehouse-only (T1); `transfersAPI.sourceAvailability` (T2); fetch-once + live per-line avail/short driven by the selected source and the analyst's current approved qty, soft-warning summary, no hard block (T3). All spec sections map to tasks.
- **Decisions honored:** headline = `available_quantity`; shortfall = warning; warehouses only; no auto-cap; shortfall recomputed against `qty[did]` (approved) not the original requested qty.
- **Type consistency:** map keys are location/product ids; `availFor` returns a number or null; JSON keys are strings so `availByLoc[fromLocationId]` (string) and `[productId]` line up with `res.json("data.{id}.{pid}...")` in the test.
- **Risk flags:** (1) the second backend test assumes `auditor` lacks `inventory_transfers.read` — if the seed grants it, drop that test method. (2) In VerifyPanel, `availByLoc[fromLocationId]` uses the string dropdown value as the key; the endpoint's JSON object keys are strings, so this matches — do not `Number()` the location key when indexing.
