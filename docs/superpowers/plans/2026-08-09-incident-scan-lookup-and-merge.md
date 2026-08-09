# Incident Scan Lookup + Report Incident Merge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user scan, type, or pick to identify the exact product or serial an incident is about, and bring incident reporting to the Scan Barcode page under one name alongside receiving corrections.

**Architecture:** A new `resolveScanAnyState()` trait method wraps the existing strict resolver and adds an unfiltered serial fallback, leaving the pickers' behaviour untouched. A lookup endpoint exposes it. `IncidentFileModal` gains a scan/type/pick field. The Adjust tile is renamed and its page gains two entry cards.

**Tech Stack:** Laravel 12 (PHP 8.2+), MySQL/MariaDB via XAMPP, PHPUnit, React 18 (CRA), plain CSS `ak-*` design system. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-08-09-incident-scan-lookup-and-merge-design.md`

---

## Critical Context For The Implementer

Read this before Task 1.

**⚠️ The single biggest hazard in this plan.** `resolveScan()` in `back-end/app/Traits/NormalizesBarcode.php` is the shared barcode resolver for `IssuanceScanController` and `FifoPickingService`. It matches serials through `ItemSerial::issuableAt()`, which requires the unit to be **in stock** — that strictness is what makes FIFO enforcement work. **Do not loosen it.** Task 1 adds a *separate* method that falls back only after the strict one misses. If you find yourself editing `resolveScan()`'s body, stop.

**Schema is a SQL dump, not a migration history.** This feature needs **no migration**. Do not run bare `php artisan migrate`.

**Tests.** `phpunit.xml` points `DB_DATABASE` at `airkingV5_test`; `php artisan test` needs no prefix. Tests use `Illuminate\Foundation\Testing\DatabaseTransactions` — **never** `RefreshDatabase`.

**The suite has 10 known pre-existing failures** — 9 `WarrantyClaimTest`, 1 `SerialRowMovementColumnsTest` — unrelated to incidents. "Green" means still exactly those 10. Prefer `--filter=Incident`.

**No frontend test suite.** Verification is `cd frontend && npm run build`, warning-clean *relative to* the existing warnings in `App.jsx`, `AdminLayout.jsx`, `Sidebar.jsx`, `BarcodeScan.jsx`, `BarcodeAdjustmentPage.jsx`. Anything naming a file you touched is yours.

**~22 files are dirty in the working tree** from unrelated earlier work, including `airkingv5.sql`. Stage only what each task names. Never `git add -A`.

**Git.** PowerShell here-strings break `git commit -m`. Use the Bash tool's heredoc (`git commit -F - <<'EOF'`).

**Facts verified while planning, so you don't have to:**

- `resolveScan()` returns `['product' => ?Product, 'serial' => ?ItemSerial, 'matched_by' => ?string]`. `matched_by` is one of `'serial'`, `'product_barcode'`, `'receiving_barcode'`, or `null` on a miss.
- `IncidentReportController` does **not** currently `use NormalizesBarcode`. It needs the trait added. `resolveScan()` is `protected`, so a controller using the trait can call it.
- `item_serial.status_id` FKs into `status_lookup` category `inventory`: **26 In Stock**, 27 Low Stock, 28 Out of Stock. `ItemSerial::IN_STOCK_STATUS_ID` is 26. The column is nullable, so a serial may have no status at all.
- The Adjust tile is `BarcodeScan.jsx`'s mode tile keyed `adjust` (label `Adjust`), which calls `navigate('/admin/barcode-adjustment')` in `selectMode`.
- `IncidentFileModal.jsx`'s product field is at roughly lines 325–345: a text filter input plus a `<select>` of `filteredProducts`.

---

## File Structure

**Modify (backend):**

| File | Change |
|---|---|
| `back-end/app/Traits/NormalizesBarcode.php` | Add `resolveScanAnyState()`. `resolveScan()` untouched. |
| `back-end/app/Http/Controllers/Api/IncidentReportController.php` | `use NormalizesBarcode`; add `lookup()` |
| `back-end/routes/api.php` | `GET /incident-reports/lookup` |
| `back-end/tests/Feature/Sets/IncidentScanLookupTest.php` | **Create** — lookup coverage |

**Modify (frontend):**

| File | Change |
|---|---|
| `frontend/src/admin/services/api.js` | `incidentReportsAPI.lookup()` |
| `frontend/src/admin/components/IncidentFileModal.jsx` | Scan/type/pick product field |
| `frontend/src/admin/pages/BarcodeScan.jsx` | Rename the `adjust` tile label |
| `frontend/src/admin/pages/BarcodeAdjustmentPage.jsx` | Header + two entry cards |

---

## Task 1: `resolveScanAnyState()`

**Files:**
- Modify: `back-end/app/Traits/NormalizesBarcode.php`
- Test: `back-end/tests/Feature/Sets/IncidentScanLookupTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Sets/IncidentScanLookupTest.php`:

```php
<?php

namespace Tests\Feature\Sets;

use App\Models\ItemSerial;

/**
 * The incident lookup must find a serial in ANY state. A filer reporting theft
 * or damage is usually reporting a unit that is precisely NOT in stock, so the
 * strict issuableAt() matching used by the pickers would report "not found"
 * for the exact cases incidents exist to capture.
 */
class IncidentScanLookupTest extends SetTestCase
{
    /** Exposes the protected trait method for testing. */
    private function resolver(): object
    {
        return new class {
            use \App\Traits\NormalizesBarcode;

            public function callAnyState(string $barcode, int $locationId): array
            {
                return $this->resolveScanAnyState($barcode, $locationId);
            }

            public function callStrict(string $barcode, int $locationId): array
            {
                return $this->resolveScan($barcode, $locationId);
            }
        };
    }

    public function test_it_finds_a_serial_that_is_not_in_stock(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeAppliance();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now()]);

        $serial = $this->makeSerial($p, $loc, 'SN-GONE-001', $b->receiving_id);
        // 28 = "Out of Stock" (status_lookup, category inventory) — a unit that
        // issuableAt() will refuse to match.
        $serial->update(['status_id' => 28]);

        $r = $this->resolver();

        // The strict resolver misses it — that is correct and must stay so.
        $this->assertNull($r->callStrict('SN-GONE-001', (int) $loc->location_id)['serial']);

        // The incident resolver finds it.
        $hit = $r->callAnyState('SN-GONE-001', (int) $loc->location_id);
        $this->assertNotNull($hit['serial']);
        $this->assertSame('SN-GONE-001', $hit['serial']->serial_number);
        $this->assertSame((int) $p->product_id, (int) $hit['product']->product_id);
        $this->assertSame('serial', $hit['matched_by']);
    }

    public function test_an_in_stock_serial_still_resolves_through_the_strict_path(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeAppliance();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now()]);

        $serial = $this->makeSerial($p, $loc, 'SN-HERE-001', $b->receiving_id);
        $serial->update(['status_id' => ItemSerial::IN_STOCK_STATUS_ID]);

        $hit = $this->resolver()->callAnyState('SN-HERE-001', (int) $loc->location_id);
        $this->assertSame('serial', $hit['matched_by']);
        $this->assertSame('SN-HERE-001', $hit['serial']->serial_number);
    }

    public function test_a_product_barcode_still_resolves(): void
    {
        $loc = $this->makeLocation();
        $p   = $this->makeConsumable(barcode: 'BC-LOOKUP-01');

        $hit = $this->resolver()->callAnyState('BC-LOOKUP-01', (int) $loc->location_id);
        $this->assertSame('product_barcode', $hit['matched_by']);
        $this->assertSame((int) $p->product_id, (int) $hit['product']->product_id);
        $this->assertNull($hit['serial']);
    }

    public function test_an_unknown_barcode_returns_a_clean_miss(): void
    {
        $loc = $this->makeLocation();

        $hit = $this->resolver()->callAnyState('NOPE-NOTHING', (int) $loc->location_id);
        $this->assertNull($hit['product']);
        $this->assertNull($hit['serial']);
        $this->assertNull($hit['matched_by']);
    }

    public function test_a_serial_at_another_location_does_not_resolve(): void
    {
        $a = $this->makeLocation('A');
        $b = $this->makeLocation('B');
        $p = $this->makeAppliance();
        $batch = $this->makeBatch($p, $a, ['quantity_remaining' => 1, 'received_at' => now()]);

        $this->makeSerial($p, $a, 'SN-ELSEWHERE', $batch->receiving_id);

        // Location scoping must survive the loosened status matching.
        $hit = $this->resolver()->callAnyState('SN-ELSEWHERE', (int) $b->location_id);
        $this->assertNull($hit['serial']);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd back-end && php artisan test --filter=IncidentScanLookupTest
```

Expected: FAIL — `Call to undefined method ...::resolveScanAnyState()`.

- [ ] **Step 3: Add the method**

In `back-end/app/Traits/NormalizesBarcode.php`, add this **after** `resolveScan()`, inside the trait. **Do not modify `resolveScan()`.**

```php
    /**
     * Same as resolveScan(), but finds a serial in ANY state.
     *
     * resolveScan() matches serials via ItemSerial::issuableAt(), which requires
     * the unit to be in stock. That strictness is load-bearing for the pickers —
     * it is what stops FIFO enforcement accepting a unit that is not actually
     * issuable — so it is NOT relaxed here. This wrapper tries the strict path
     * first and only then falls back.
     *
     * Incidents need the looser match because a filer reporting theft, damage or
     * a return is usually reporting a unit that is precisely NOT in stock.
     *
     * @return array{product: ?\App\Models\Product, serial: ?\App\Models\ItemSerial, matched_by: ?string}
     */
    protected function resolveScanAnyState(string $rawBarcode, int $locationId): array
    {
        $strict = $this->resolveScan($rawBarcode, $locationId);
        if ($strict['product'] !== null) {
            return $strict;
        }

        foreach ($this->barcodeLookupCandidates($rawBarcode) as $candidate) {
            $norm = trim($candidate);
            if ($norm === '') {
                continue;
            }

            // Location-scoped, but with no status or serial_type filter.
            $serial = ItemSerial::where('location_id', $locationId)
                ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($norm)])
                ->first();

            if ($serial) {
                return [
                    'product'    => Product::with('applianceDetail.brand')->find($serial->product_id),
                    'serial'     => $serial,
                    'matched_by' => 'serial',
                ];
            }
        }

        return $strict;   // a clean miss, in the shape callers already expect
    }
```

- [ ] **Step 4: Run to confirm it passes**

```bash
cd back-end && php artisan test --filter=IncidentScanLookupTest
```

Expected: PASS, 5 tests.

- [ ] **Step 5: Prove the pickers are unaffected**

The whole point of the separate method is that FIFO enforcement is untouched:

```bash
cd back-end && php artisan test --filter=Incident
cd back-end && php artisan test --filter=Fifo
cd back-end && php artisan test --filter=Issuance
```

Expected: all green. If any FIFO or issuance test moved, you edited `resolveScan()` — revert and re-read Step 3.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Traits/NormalizesBarcode.php back-end/tests/Feature/Sets/IncidentScanLookupTest.php
git commit -F - <<'EOF'
feat(incidents): add resolveScanAnyState for incident lookups

Incidents are usually about units that are NOT in stock, so the strict
issuableAt() matching the pickers rely on reports "not found" for exactly
the cases incidents exist to capture. This wraps the strict resolver and
falls back only after it misses, leaving FIFO enforcement untouched.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 2: The lookup endpoint

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/IncidentReportController.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/Sets/IncidentScanLookupTest.php`

- [ ] **Step 1: Write the failing tests**

Append to the class in `back-end/tests/Feature/Sets/IncidentScanLookupTest.php`:

```php
    public function test_the_endpoint_resolves_a_product_barcode(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable(barcode: 'BC-EP-001');
        $this->setStock($loc->location_id, $p->product_id, 8);
        $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $res = $this->actingAs($wp, 'sanctum')
            ->getJson("/api/incident-reports/lookup?barcode=BC-EP-001&location_id={$loc->location_id}");

        $res->assertOk()
            ->assertJsonPath('data.matched_by', 'product_barcode')
            ->assertJsonPath('data.product.product_id', (int) $p->product_id)
            ->assertJsonPath('data.serial', null)
            ->assertJsonPath('data.location_quantity', 8);

        $this->assertNotEmpty($res->json('data.batches'));
    }

    public function test_the_endpoint_reports_a_serials_state(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeAppliance();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now()]);

        $serial = $this->makeSerial($p, $loc, 'SN-EP-STATE', $b->receiving_id);
        $serial->update(['status_id' => 28]);   // Out of Stock

        $this->actingAs($wp, 'sanctum')
            ->getJson("/api/incident-reports/lookup?barcode=SN-EP-STATE&location_id={$loc->location_id}")
            ->assertOk()
            ->assertJsonPath('data.matched_by', 'serial')
            ->assertJsonPath('data.serial.serial_number', 'SN-EP-STATE')
            ->assertJsonPath('data.serial.status_label', 'Out of Stock')
            ->assertJsonPath('data.serial.in_stock', false);
    }

    public function test_an_unknown_barcode_404s_with_a_usable_message(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');

        $this->actingAs($wp, 'sanctum')
            ->getJson("/api/incident-reports/lookup?barcode=NOTHING-HERE&location_id={$loc->location_id}")
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_the_lookup_requires_the_create_permission(): void
    {
        $loc = $this->makeLocation();
        $au  = $this->makeUserAt($loc, 'auditor');   // read-only role
        $p   = $this->makeConsumable(barcode: 'BC-EP-DENY');

        $this->actingAs($au, 'sanctum')
            ->getJson("/api/incident-reports/lookup?barcode=BC-EP-DENY&location_id={$loc->location_id}")
            ->assertForbidden();
    }

    public function test_the_lookup_requires_a_barcode_and_location(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');

        $this->actingAs($wp, 'sanctum')
            ->getJson('/api/incident-reports/lookup')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['barcode', 'location_id']);
    }
```

- [ ] **Step 2: Run to confirm they fail**

```bash
cd back-end && php artisan test --filter=IncidentScanLookupTest
```

Expected: the five new tests fail with 404 (route missing).

- [ ] **Step 3: Add the controller method**

In `back-end/app/Http/Controllers/Api/IncidentReportController.php`:

Add the trait import beside the existing `use` statements at the top of the file:

```php
use App\Traits\NormalizesBarcode;
```

Add it to the class body, as the first line inside the class:

```php
    use NormalizesBarcode;
```

Then add this method after `index()`:

```php
    /**
     * Resolve a scanned or typed barcode to the product (and serial) an incident
     * is about.
     *
     * Uses resolveScanAnyState, NOT resolveScan: a filer reporting theft or
     * damage is usually reporting a unit that is no longer in stock, and the
     * strict resolver would report "not found" for exactly those cases.
     */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'barcode'     => 'required|string|max:255',
            'location_id' => 'required|integer|exists:locations,location_id',
        ]);

        $locationId = (int) $validated['location_id'];

        // Location scoping: never resolve a barcode at a location the caller
        // cannot see. Location does NOT use ScopesToUserLocation (that trait
        // filters a location_id COLUMN, which the locations table does not
        // have), so the check goes through the user, exactly as the trait does
        // internally: centralized users are unrestricted, scoped users are
        // pinned to their one location, and a scoped user with none sees
        // nothing.
        $user = Auth::user();
        if (! $user->isCentralized() && $user->scopedLocationId() !== $locationId) {
            return response()->json([
                'success' => false,
                'message' => 'That location is outside your access.',
            ], 403);
        }

        $hit = $this->resolveScanAnyState($validated['barcode'], $locationId);

        if ($hit['product'] === null) {
            return response()->json([
                'success' => false,
                'message' => 'No product matches that barcode at this location.',
            ], 404);
        }

        $product = $hit['product'];
        $serial  = $hit['serial'];

        $inventory = \App\Models\Inventory::where('location_id', $locationId)
            ->where('product_id', $product->product_id)
            ->first();

        $batches = \App\Models\InventoryBatch::where('location_id', $locationId)
            ->where('product_id', $product->product_id)
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at')
            ->get(['batch_id', 'zone_label', 'quantity_remaining', 'received_at']);

        return response()->json([
            'success' => true,
            'data'    => [
                'matched_by' => $hit['matched_by'],
                'product'    => [
                    'product_id'   => (int) $product->product_id,
                    'product_name' => $product->product_name,
                    'product_code' => $product->product_code,
                    'barcode'      => $product->barcode,
                ],
                'serial' => $serial === null ? null : [
                    'serial_id'     => (int) $serial->serial_id,
                    'serial_number' => $serial->serial_number,
                    // Nullable column: a serial may carry no status at all.
                    'status_label'  => $serial->status_id
                        ? (\App\Models\StatusLookup::find($serial->status_id)?->status_name ?? 'Unknown')
                        : 'Unknown',
                    'in_stock'      => (int) $serial->status_id === \App\Models\ItemSerial::IN_STOCK_STATUS_ID,
                ],
                'location_quantity' => (int) ($inventory->quantity_on_hand ?? 0),
                'batches' => $batches->map(fn ($b) => [
                    'batch_id'           => (int) $b->batch_id,
                    'zone_label'         => $b->zone_label,
                    'quantity_remaining' => (int) $b->quantity_remaining,
                    'received_at'        => optional($b->received_at)->toDateString(),
                ])->all(),
            ],
        ]);
    }
```

**Two things to confirm as you go:**

1. `User::isCentralized()` and `User::scopedLocationId()` exist — they are what `ScopesToUserLocation` itself calls (`app/Models/Concerns/ScopesToUserLocation.php:17-21`). `scopedLocationId()` returns null for a scoped user with no location, and `null !== $locationId` is true, so that user is correctly refused.
2. `StatusLookup` is referenced fully-qualified above, so no import is needed. Add one if you prefer, matching the file's existing style.

- [ ] **Step 4: Add the route**

In `back-end/routes/api.php`, immediately after the existing `GET /incident-reports` route:

```php
    Route::middleware('permission:incident_reports,create')
         ->get('/incident-reports/lookup', [\App\Http\Controllers\Api\IncidentReportController::class, 'lookup']);
```

**Route-order hazard:** this must be declared **before** `GET /incident-reports/{id}/photo`, or `lookup` could be captured as an `{id}`. Verify with:

```bash
cd back-end && php artisan route:list --path=incident-reports
```

Expected: `lookup` resolves to `IncidentReportController@lookup`, and all other incident routes still appear.

- [ ] **Step 5: Verify**

```bash
cd back-end && php artisan test --filter=IncidentScanLookupTest
cd back-end && php artisan test --filter=Incident
```

Expected: 10 lookup tests pass; `--filter=Incident` green.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/IncidentReportController.php back-end/routes/api.php back-end/tests/Feature/Sets/IncidentScanLookupTest.php
git commit -F - <<'EOF'
feat(incidents): add the barcode lookup endpoint

Resolves a scanned or typed barcode to the product, the serial and its
current state, plus location stock and batches so the batch field can
narrow to a short list. Location-scoped, and gated on
incident_reports.create.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 3: API client

**Files:**
- Modify: `frontend/src/admin/services/api.js`

- [ ] **Step 1: Add the call**

In `incidentReportsAPI`, after `list`:

```js
  /**
   * Resolve a scanned or typed barcode to a product/serial for an incident.
   * Returns { data: { matched_by, product, serial, location_quantity, batches } }.
   * Throws on 404 (no match) — the caller keeps the manual picker visible.
   */
  lookup: async ({ barcode, locationId }) => {
    const q = new URLSearchParams({ barcode, location_id: String(locationId) }).toString();
    return apiRequest(`/incident-reports/lookup?${q}`);
  },
```

- [ ] **Step 2: Verify**

```bash
cd frontend && npx eslint src/admin/services/api.js
cd frontend && npm run build
```

Expected: eslint exit 0; `Compiled`.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -F - <<'EOF'
feat(incidents): add incidentReportsAPI.lookup

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 4: Scan/type/pick in the incident form

**Files:**
- Modify: `frontend/src/admin/components/IncidentFileModal.jsx`

- [ ] **Step 1: Add state and the lookup handler**

Add beside the existing state declarations:

```jsx
  const [scanInput, setScanInput] = useState('');
  const [scanning, setScanning] = useState(false);
  const [scanError, setScanError] = useState(null);
  const [scanHit, setScanHit] = useState(null);   // { matched_by, serial, batches }
```

Add this handler beside the other callbacks:

```jsx
  const runLookup = useCallback(async () => {
    const code = scanInput.trim();
    if (!code) return;
    if (!locationId) { setScanError('Choose a location first.'); return; }

    setScanning(true);
    setScanError(null);
    try {
      const res = await incidentReportsAPI.lookup({ barcode: code, locationId });
      const d = res?.data;
      if (!d?.product?.product_id) throw new Error('No product matches that barcode at this location.');

      // Scanning SETS the product; it does not lock it. A mislabelled barcode
      // is itself a reportable incident (wrong_type), so the picker stays live.
      setProductId(String(d.product.product_id));
      setScanHit({ matched_by: d.matched_by, serial: d.serial, batches: d.batches || [] });
      setScanError(null);
    } catch (err) {
      setScanHit(null);
      setScanError(err?.message || 'No product matches that barcode at this location.');
    } finally {
      setScanning(false);
    }
  }, [scanInput, locationId]);
```

Reset the new state in the reset-on-open effect, beside the existing resets:

```jsx
    setScanInput('');
    setScanError(null);
    setScanHit(null);
    setScanning(false);
```

- [ ] **Step 2: Render the field**

Replace the `Product *` label and its filter input (roughly lines 325–333, the `<label htmlFor="file-incident-product">` and the text input immediately after it) with:

```jsx
              <label className="ak-label" htmlFor="file-incident-scan">Product *</label>

              <div style={{ display: 'flex', gap: '0.4rem', flexWrap: 'wrap', alignItems: 'center' }}>
                <input
                  id="file-incident-scan"
                  className="ak-input"
                  type="text"
                  value={scanInput}
                  onChange={(e) => setScanInput(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); runLookup(); } }}
                  placeholder="Scan or type a barcode / serial…"
                  style={{ flex: '1 1 220px' }}
                  disabled={submitting}
                />
                <button
                  type="button"
                  className="ak-btn"
                  onClick={runLookup}
                  disabled={submitting || scanning || !scanInput.trim()}
                >
                  {scanning ? 'Finding…' : 'Find'}
                </button>
              </div>

              {scanError && (
                <div className="ak-state ak-state--error" style={{ fontSize: '0.82rem', marginTop: '0.35rem' }}>
                  {scanError} You can still choose the product below.
                </div>
              )}

              {scanHit?.serial && (
                <div className="ak-muted" style={{ fontSize: '0.82rem', marginTop: '0.35rem' }}>
                  Matched serial <strong>{scanHit.serial.serial_number}</strong>
                  {' — '}{scanHit.serial.status_label}
                  {scanHit.serial.in_stock ? '' : ' (not in stock)'}
                </div>
              )}

              {/* Always present: theft and damage are often WHY there is no
                  barcode left to scan, so a report must never be blocked by a
                  missing label. */}
              <input
                className="ak-input"
                type="text"
                value={productFilter}
                onChange={(e) => setProductFilter(e.target.value)}
                placeholder="…or filter the list by name, code or barcode"
                style={{ marginTop: '0.45rem' }}
              />
```

Leave the `<select>` of `filteredProducts` immediately below exactly as it is.

- [ ] **Step 3: Verify**

```bash
cd frontend && npx eslint src/admin/components/IncidentFileModal.jsx
cd frontend && npm run build
```

Expected: eslint exit 0; `Compiled` with no warning naming `IncidentFileModal`.

If `react-hooks/exhaustive-deps` complains about `runLookup`'s array, add the missing identifier rather than suppressing the rule. Destructure hook values into plain identifiers if the rule cannot follow a member expression.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/components/IncidentFileModal.jsx
git commit -F - <<'EOF'
feat(incidents): scan or type a barcode to identify the product

Three ways to name the same thing: scan/type a barcode or serial, or pick
from the list. The picker is always present because theft and damage are
often why no barcode remains. A match sets the product but does not lock
it — a mislabelled barcode is itself a reportable incident.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 5: Rename the tile and add the entry cards

**Files:**
- Modify: `frontend/src/admin/pages/BarcodeScan.jsx`
- Modify: `frontend/src/admin/pages/BarcodeAdjustmentPage.jsx`

- [ ] **Step 1: Rename the tile**

In `frontend/src/admin/pages/BarcodeScan.jsx`, in the mode tile keyed `adjust`, change only the label:

```jsx
    key: 'adjust',
    label: 'Report Incident',
```

Leave `key`, `apiLabel`, `color`, `bg` and the icon alone — the key is used by `selectMode`, and the red warning triangle already reads as "something is wrong".

- [ ] **Step 2: Retitle the page and add the cards**

In `frontend/src/admin/pages/BarcodeAdjustmentPage.jsx`:

Add the imports:

```jsx
import IncidentFileModal from '../components/IncidentFileModal';
import { useAuth } from '../context/AuthContext';
```

Add inside the component, beside the existing state:

```jsx
  const { can } = useAuth();
  const canFileIncident = typeof can === 'function' && can('incident_reports', 'create');
  const [incidentOpen, setIncidentOpen] = useState(false);
```

Change the page's header text from the existing *"Inventory Adjustment"* wording to **Report Incident**. Find the header block (search for `Inventory · Adjustment` or the `<h1>`) and set the title to `Report Incident` and the subtitle to:

```
Report something wrong with stock, or correct a scan made during receiving.
```

Then, immediately after the header and **before** the existing receiving-correction UI, add:

```jsx
        {canFileIncident && (
          <div className="ak-card" style={{ marginBottom: '1rem', padding: '1rem' }}>
            <h2 className="ak-label" style={{ marginTop: 0 }}>Something's wrong with stock</h2>
            <p className="ak-muted" style={{ fontSize: '0.85rem', marginTop: '0.2rem' }}>
              Missing or stolen stock, damage, a wrong item on the shelf, or a customer return.
              Scan the item to identify it.
            </p>
            <button type="button" className="ak-btn ak-btn--primary" onClick={() => setIncidentOpen(true)}>
              Report a stock problem
            </button>
          </div>
        )}

        <div className="ak-card" style={{ marginBottom: '1rem', padding: '1rem' }}>
          <h2 className="ak-label" style={{ marginTop: 0 }}>Correct a receiving scan</h2>
          <p className="ak-muted" style={{ fontSize: '0.85rem', marginTop: '0.2rem' }}>
            Fix a wrong product, quantity, serial or duplicate scan recorded against a purchase order.
          </p>
        </div>
```

And render the modal near the end of the component's JSX, beside whatever else is rendered at that level:

```jsx
      <IncidentFileModal
        isOpen={incidentOpen}
        onClose={() => setIncidentOpen(false)}
        onFiled={() => setIncidentOpen(false)}
      />
```

**Check `ak-card` exists** in `frontend/src/admin/styles/airking_ds.css`. If it does not, use the page's own existing card/panel class — grep the file for what it already uses — and say which you used in your report. Do not invent a new class.

- [ ] **Step 3: Verify**

```bash
cd frontend && npx eslint src/admin/pages/BarcodeScan.jsx src/admin/pages/BarcodeAdjustmentPage.jsx
cd frontend && npm run build
```

Expected: eslint exit 0. `BarcodeScan.jsx` and `BarcodeAdjustmentPage.jsx` have **pre-existing** unused-variable warnings — compare against the baseline and only treat *new* ones as yours.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/pages/BarcodeScan.jsx frontend/src/admin/pages/BarcodeAdjustmentPage.jsx
git commit -F - <<'EOF'
feat(incidents): rename Adjust to Report Incident and add entry cards

The tile now leads to a page offering both jobs: report something found
wrong with stock, or correct a receiving scan. The receiving-correction
flow is untouched. The incident card is gated on incident_reports.create,
so a user without it sees only the correction path.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 6: Verification

- [ ] **Step 1: Backend**

```bash
cd back-end && php artisan test --filter=Incident
cd back-end && php artisan test --filter=Fifo
cd back-end && php artisan test --filter=Issuance
cd back-end && php artisan test
```

Expected: incident suites green including the 10 new lookup tests. FIFO and Issuance green — **these are the proof that `resolveScan()` was not loosened.** Full suite: **exactly 10 failures**, the known `WarrantyClaimTest` (9) and `SerialRowMovementColumnsTest` (1). Any other number means this plan broke something.

- [ ] **Step 2: Frontend**

```bash
cd frontend && npm run build
cd frontend && npx eslint src/admin/components/IncidentFileModal.jsx src/admin/services/api.js src/admin/pages/BarcodeScan.jsx src/admin/pages/BarcodeAdjustmentPage.jsx
```

Expected: `Compiled`; eslint exit 0 with no suppressions added.

- [ ] **Step 3: Manual pass**

Serve at `http://localhost:3000` (the dev server). Camera permission is **per-origin including the port** — a grant on another port does not carry.

**From the sidebar (as an inventory analyst or admin):**
1. Incident Reports → Report Incident → the product field shows a scan box, a Find button, and the filter+list below it.
2. Type a known product barcode → Find → the product fills in.
3. Type a serial for a unit that is **not** in stock → Find → it resolves and shows its state.
4. Type nonsense → Find → an error appears **and the picker is still usable**.
5. File with no barcode at all, choosing from the list → succeeds.

**From Scan Barcode (as a warehouse user):**
6. The tile reads **Report Incident**, not Adjust.
7. It opens a page titled Report Incident with two cards.
8. "Report a stock problem" opens the same modal, with the same scan field.
9. "Correct a receiving scan" still works exactly as before — pick a PO, a line, a correction type, submit.

**Role check:**
10. As an inventory analyst, confirm Scan Barcode is still inaccessible **and** the sidebar route to incidents still works. This is the case that motivated keeping the sidebar entry.

- [ ] **Step 4: Commit any fixes**

If the manual pass found defects, fix them, re-run Steps 1 and 2, and commit. If it found none, there is nothing to commit and this plan is complete.

---

## Notes For Integration

This sits on `backupV3`, which carries a large amount of unpushed work. Do not merge or push without asking — decide integration separately using `superpowers:finishing-a-development-branch`.
