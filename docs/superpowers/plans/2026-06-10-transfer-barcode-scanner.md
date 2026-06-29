# Transfer Barcode Scanner + Serial Integrity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a camera barcode scanner to the IST Dispatch and Receive screens (single global camera that auto-routes each scan to the correct product line) and close the serial-integrity gaps so dispatched units can't be double-dispatched, sold while in transit, or re-transferred after they've moved.

**Architecture:** Backend resolution + integrity gates live in `TransferService` (a new `recordDispatchScan`, mirroring the existing `recordReceiveScan`) and a reusable `ItemSerial::scopeIssuableAt` query scope consumed by issuance. Frontend extracts the existing copy-pasted camera logic into a shared `useBarcodeCamera` hook + `<CameraScanner>` component, used by both transfer pages. The per-line manual inputs stay as fallback.

**Tech Stack:** Laravel (PHP 8, Eloquent, PHPUnit feature tests on cloned `airkingV5_test` MySQL DB with `DatabaseTransactions`), React (Vite), html5-qrcode + @zxing/library.

**Spec:** `docs/superpowers/specs/2026-06-10-transfer-barcode-scanner-design.md`

---

## File Structure

**Backend**
- Modify: `app/Models/ItemSerial.php` — add `scopeIssuableAt` query scope (issuance availability predicate, including `serial_type='received'`).
- Modify: `app/Http/Controllers/Api/IssuanceScanController.php:160-173` — use the scope for the serial (Pass 1) lookup. Closes #3.
- Modify: `app/Services/TransferService.php` — add `recordDispatchScan()` (resolve barcode → line + integrity gates) and `removeDispatchScan()`; `use App\Traits\NormalizesBarcode` for consumable resolution. Closes #2, #5, auto-route.
- Modify: `app/Http/Controllers/Api/ISTController.php:438-565` — `scan()` and `removeScan()` delegate to the service; `transfer_detail_id` becomes optional.
- Modify: `app/Services/TransferService.php::recordReceiveScan` — make `transfer_detail_id` optional and resolve serialized scans by serial → line (receive-side auto-route).
- Modify: `app/Http/Controllers/Api/ISTController.php::receiveScan` — make `transfer_detail_id` optional.
- Test: `tests/Feature/Transfer/SerialIssuableScopeTest.php`, `tests/Feature/Transfer/DispatchScanTest.php`, `tests/Feature/Transfer/ReceiveScanResolveTest.php`.

**Frontend**
- Create: `frontend/src/admin/hooks/useBarcodeCamera.js` — camera lifecycle hook (lifted verbatim from `IssuanceScanPage.jsx`).
- Create: `frontend/src/admin/components/CameraScanner.jsx` — presentational camera box driven by the hook.
- Modify: `frontend/src/admin/pages/ISTDispatchPage.jsx` — mount `<CameraScanner>`, add `handleGlobalScan`.
- Modify: `frontend/src/admin/pages/ISTReceivePage.jsx` — mount `<CameraScanner>`, add `handleGlobalScan`.

---

## PART 1 — BACKEND: Serial integrity

### Task 1: `ItemSerial::scopeIssuableAt` query scope

A serial is sellable only when it is physically at the location, in the
`In Stock` inventory status (26), **and** not currently shipped out
(`serial_type='received'`). The transfer flow already flips `serial_type` to
`transferred` on dispatch and back to `received` on receipt, so this one scope
closes #3 without any schema change.

**Files:**
- Modify: `app/Models/ItemSerial.php`
- Test: `tests/Feature/Transfer/SerialIssuableScopeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Transfer;

use App\Models\ItemSerial;

class SerialIssuableScopeTest extends TransferTestCase
{
    public function test_received_serial_in_stock_is_issuable(): void
    {
        $loc  = $this->makeLocation('SR-A');
        $prod = $this->makeAppliance();
        ItemSerial::create([
            'product_id'    => $prod->product_id,
            'location_id'   => $loc->location_id,
            'serial_number' => 'SN-OK',
            'serial_type'   => 'received',
            'status_id'     => 26, // inventory: In Stock
        ]);

        $found = ItemSerial::issuableAt($loc->location_id, 'SN-OK')->first();
        $this->assertNotNull($found);
    }

    public function test_transferred_serial_is_not_issuable(): void
    {
        $loc  = $this->makeLocation('SR-A');
        $prod = $this->makeAppliance();
        ItemSerial::create([
            'product_id'    => $prod->product_id,
            'location_id'   => $loc->location_id,
            'serial_number' => 'SN-INTRANSIT',
            'serial_type'   => 'transferred', // dispatched, not yet received
            'status_id'     => 26,
        ]);

        $found = ItemSerial::issuableAt($loc->location_id, 'SN-INTRANSIT')->first();
        $this->assertNull($found);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Transfer/SerialIssuableScopeTest.php`
Expected: FAIL — `Call to undefined method ...::issuableAt()` (or scope missing).

- [ ] **Step 3: Add the scope**

In `app/Models/ItemSerial.php`, add inside the class:

```php
/**
 * Serials that may be issued/sold at a location: physically here,
 * In Stock (inventory status 26), and not shipped out on a transfer
 * (serial_type stays 'received' until dispatched; receive() restores it).
 * Case/space-insensitive on the serial number to match scanner input.
 */
public function scopeIssuableAt($query, int $locationId, string $serialNumber)
{
    return $query
        ->where('location_id', $locationId)
        ->where('serial_type', 'received')
        ->where('status_id', 26)
        ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower(trim($serialNumber))]);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Transfer/SerialIssuableScopeTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Models/ItemSerial.php tests/Feature/Transfer/SerialIssuableScopeTest.php
git commit -m "feat: ItemSerial::issuableAt scope — excludes in-transit serials"
```

---

### Task 2: Issuance uses the scope (closes #3)

**Files:**
- Modify: `app/Http/Controllers/Api/IssuanceScanController.php:160-173`

- [ ] **Step 1: Replace the serial Pass 1 lookup**

Find (around line 160):

```php
        foreach ($candidates as $candidate) {
            $serial = ItemSerial::where('location_id', $locationId)
                ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($candidate)])
                ->where('status_id', self::STATUS_SERIAL_IN_STOCK)
                ->first();
            if ($serial) {
```

Replace the query with the scope:

```php
        foreach ($candidates as $candidate) {
            $serial = ItemSerial::issuableAt($locationId, $candidate)->first();
            if ($serial) {
```

(Leave the rest of the loop body — the `Product::with(...)->find(...)` and
`break;` — unchanged.)

- [ ] **Step 2: Run the existing transfer suite to confirm no regression**

Run: `php artisan test tests/Feature/Transfer`
Expected: PASS (existing tests + Task 1's tests).

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/IssuanceScanController.php
git commit -m "fix: block issuance of in-transit serials via issuableAt scope (#3)"
```

---

### Task 3: `TransferService::recordDispatchScan` — resolve + gates (#2, #5)

Moves the dispatch-scan logic out of the controller into a testable service
method, makes `transfer_detail_id` optional (auto-route), and adds the source/
stock/duplicate gates. Mirrors the existing `recordReceiveScan`.

**Files:**
- Modify: `app/Services/TransferService.php`
- Test: `tests/Feature/Transfer/DispatchScanTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace Tests\Feature\Transfer;

use App\Models\ItemSerial;
use App\Models\Inventory;
use App\Services\TransferService;
use RuntimeException;

class DispatchScanTest extends TransferTestCase
{
    private function svc(): TransferService { return new TransferService(); }

    public function test_scans_serial_and_autoroutes_to_line(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 2);
        [$sn] = $this->makeSerials($from->location_id, $prod->product_id, 1);
        $user = $this->makeUser();

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 2],
        ]);

        // No transfer_detail_id supplied — must resolve from the serial.
        $res = $this->svc()->recordDispatchScan($ist, $sn, null, $user->user_id);

        $this->assertSame(
            $ist->details->first()->transfer_detail_id,
            $res['transfer_detail_id']
        );
        $this->assertSame(1, $res['total_scanned']);
        $this->assertSame(1, $res['remaining']);
    }

    public function test_double_scan_same_serial_is_rejected(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 2);
        [$sn] = $this->makeSerials($from->location_id, $prod->product_id, 1);
        $user = $this->makeUser();
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 2],
        ]);

        $this->svc()->recordDispatchScan($ist, $sn, null, $user->user_id);

        $this->expectException(RuntimeException::class);
        $this->svc()->recordDispatchScan($ist->fresh('details'), $sn, null, $user->user_id);
    }

    public function test_serial_at_other_location_is_rejected(): void
    {
        $from  = $this->makeLocation('WH-A');
        $to    = $this->makeLocation('SR-B');
        $other = $this->makeLocation('SR-C');
        $prod  = $this->makeAppliance();
        // Serial physically lives at the OTHER location (already moved away).
        [$sn] = $this->makeSerials($other->location_id, $prod->product_id, 1);
        $user = $this->makeUser();
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 1],
        ]);

        $this->expectException(RuntimeException::class);
        $this->svc()->recordDispatchScan($ist, $sn, null, $user->user_id);
    }

    public function test_serial_for_product_not_on_ist_is_rejected(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $onIst   = $this->makeAppliance();
        $offIst  = $this->makeAppliance();
        $this->setStock($from->location_id, $onIst->product_id, 1);
        [$sn] = $this->makeSerials($from->location_id, $offIst->product_id, 1);
        $user = $this->makeUser();
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $onIst->product_id, 'quantity_requested' => 1],
        ]);

        $this->expectException(RuntimeException::class);
        $this->svc()->recordDispatchScan($ist, $sn, null, $user->user_id);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Transfer/DispatchScanTest.php`
Expected: FAIL — `Call to undefined method ...::recordDispatchScan()`.

- [ ] **Step 3: Add `use` for the trait and the methods**

In `app/Services/TransferService.php`, add the trait to the class:

```php
class TransferService
{
    use \App\Traits\NormalizesBarcode;
```

Then add these methods to the class:

```php
/**
 * Resolve a scanned barcode to a line on this Approved IST and record one
 * dispatch scan, enforcing integrity gates:
 *  - serialized: the serial must be at from_location, in stock
 *    (serial_type='received'), free of another transfer, and belong to a
 *    product on this IST (which also auto-routes it to the line).
 *  - consumable: barcode resolves to a product that is on this IST.
 * Duplicate barcodes on this IST are rejected. Returns the resolved line +
 * running totals. $transferDetailId may be passed by the manual per-line UI;
 * when null it is resolved here.
 */
public function recordDispatchScan(Transfer $ist, string $barcode, ?int $transferDetailId, int $userId, int $qty = 1): array
{
    if ((int) $ist->status_id !== $this->statusId('Approved')) {
        throw new RuntimeException('Only an approved IST can be scanned for dispatch.');
    }

    return DB::transaction(function () use ($ist, $barcode, $transferDetailId, $userId, $qty) {
        $ist->loadMissing('details.product');
        $code = trim($barcode);
        if ($code === '') {
            throw new RuntimeException('Enter a barcode or serial number.');
        }

        // Duplicate guard (same barcode already scanned on this IST).
        $dupe = BarcodeScan::where('transfer_id', $ist->transfer_id)
            ->where('barcode', $code)
            ->exists();
        if ($dupe) {
            throw new RuntimeException("Barcode '{$code}' has already been scanned for this IST.");
        }

        // Resolve which serial (if any) this code is, and which line it belongs to.
        $serial = ItemSerial::whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($code)])->first();

        if ($serial) {
            // Serialized path.
            $detail = $ist->details->firstWhere('product_id', $serial->product_id);
            if (!$detail) {
                throw new RuntimeException('This serial is not part of this transfer.');
            }
            if ((int) $serial->location_id !== (int) $ist->from_location_id) {
                throw new RuntimeException('This serial is not in stock at the source location.');
            }
            if ($serial->serial_type !== 'received') {
                throw new RuntimeException('This serial has already been shipped out.');
            }
            if ($serial->transfer_id !== null && (int) $serial->transfer_id !== (int) $ist->transfer_id) {
                throw new RuntimeException('This serial is committed to another transfer.');
            }
            $step = 1;
        } else {
            // Consumable path — resolve product by barcode candidates.
            $product = $this->findProductByBarcodeCandidates($this->barcodeLookupCandidates($code));
            if (!$product) {
                throw new RuntimeException('Barcode not found.');
            }
            $detail = $ist->details->firstWhere('product_id', $product->product_id);
            if (!$detail) {
                throw new RuntimeException('This product is not part of this transfer.');
            }
            if ($this->isSerialized($detail)) {
                throw new RuntimeException('Scan the individual serial number for this appliance.');
            }
            $step = max(1, (int) $qty);
        }

        // If the caller named a line, it must match the resolved one.
        if ($transferDetailId !== null && (int) $transferDetailId !== (int) $detail->transfer_detail_id) {
            throw new RuntimeException('Scanned item does not match the selected line.');
        }

        $target  = (int) $detail->quantity_requested;
        $current = (int) $detail->quantity_scanned;
        if ($current + $step > $target) {
            $remaining = $target - $current;
            throw new RuntimeException("Only {$remaining} more unit(s) needed for this product.");
        }

        $detail->increment('quantity_scanned', $step);
        $detail->refresh();

        $scan = BarcodeScan::create([
            'user_id'     => $userId,
            'product_id'  => $detail->product_id,
            'transfer_id' => $ist->transfer_id,
            'barcode'     => $code,
            'scan_mode'   => 'transfer',
            'quantity'    => $step,
            'scanned_at'  => now(),
        ]);

        if ($serial) {
            $serial->transfer_id = $ist->transfer_id;
            $serial->save();
        }

        return [
            'scan_id'            => $scan->id,
            'transfer_detail_id' => (int) $detail->transfer_detail_id,
            'product_name'       => $detail->product?->product_name ?? "Product #{$detail->product_id}",
            'total_scanned'      => (int) $detail->quantity_scanned,
            'quantity_requested' => $target,
            'remaining'          => $target - (int) $detail->quantity_scanned,
        ];
    });
}

/** Undo a dispatch scan before confirmation: decrement the line and free the serial. */
public function removeDispatchScan(Transfer $ist, int $scanId): void
{
    if ((int) $ist->status_id !== $this->statusId('Approved')) {
        throw new RuntimeException('Only an approved IST can be edited.');
    }

    DB::transaction(function () use ($ist, $scanId) {
        $scan = BarcodeScan::where('id', $scanId)
            ->where('transfer_id', $ist->transfer_id)
            ->where('scan_mode', 'transfer')
            ->first();
        if (!$scan) {
            throw new RuntimeException('Dispatch scan not found.');
        }

        $step = max(1, (int) ($scan->quantity ?? 1));
        $detail = TransferDetail::where('transfer_id', $ist->transfer_id)
            ->where('product_id', $scan->product_id)
            ->lockForUpdate()
            ->first();
        if ($detail) {
            $detail->quantity_scanned = max(0, (int) $detail->quantity_scanned - $step);
            $detail->save();
        }

        ItemSerial::where('transfer_id', $ist->transfer_id)
            ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($scan->barcode)])
            ->update(['transfer_id' => null]);

        $scan->delete();
    });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Transfer/DispatchScanTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/TransferService.php tests/Feature/Transfer/DispatchScanTest.php
git commit -m "feat: TransferService::recordDispatchScan with source/stock/dup gates (#2,#5)"
```

---

### Task 4: Wire `ISTController::scan` / `removeScan` to the service

**Files:**
- Modify: `app/Http/Controllers/Api/ISTController.php:438-565`

- [ ] **Step 1: Replace the `scan` method body**

Replace `app/Http/Controllers/Api/ISTController.php` `scan()` (lines ~438-527)
with a thin delegate. Note `transfer_detail_id` is now optional:

```php
public function scan(Request $request, $transferId, TransferService $service)
{
    try {
        $validated = $request->validate([
            'barcode'            => 'required|string|max:255',
            'transfer_detail_id' => 'sometimes|nullable|integer',
            'quantity'           => 'sometimes|integer|min:1',
        ]);

        $approvedId = $this->statusId('Approved');
        $ist = Transfer::with(['details.product'])
            ->whereNotNull('transfer_request_id')
            ->where('status_id', $approvedId)
            ->findOrFail($transferId);

        $result = $service->recordDispatchScan(
            $ist,
            $validated['barcode'],
            $validated['transfer_detail_id'] ?? null,
            $request->user()->user_id,
            (int) ($validated['quantity'] ?? 1),
        );

        $ist->load('details');
        $fullyScanned = $ist->details->every(
            fn ($d) => (int) $d->quantity_scanned >= (int) $d->quantity_requested
        );

        return $this->success(array_merge($result, [
            'ist_fully_scanned' => $fullyScanned,
        ]), 'Scan recorded');
    } catch (ValidationException $e) {
        return $this->validationError($e->errors());
    } catch (\RuntimeException $e) {
        return $this->error($e->getMessage(), 422);
    } catch (\Exception $e) {
        return $this->error('Scan failed: ' . $e->getMessage(), 500);
    }
}
```

- [ ] **Step 2: Replace the `removeScan` method body**

Replace `removeScan()` (lines ~532-565):

```php
public function removeScan(Request $request, $transferId, $scanId, TransferService $service)
{
    try {
        $approvedId = $this->statusId('Approved');
        $ist = Transfer::whereNotNull('transfer_request_id')
            ->where('status_id', $approvedId)
            ->findOrFail($transferId);

        $service->removeDispatchScan($ist, (int) $scanId);
        return $this->success(null, 'Scan removed');
    } catch (\RuntimeException $e) {
        return $this->error($e->getMessage(), 422);
    } catch (\Exception $e) {
        return $this->error('Failed to remove scan: ' . $e->getMessage(), 500);
    }
}
```

- [ ] **Step 3: Add an endpoint test**

Create `tests/Feature/Transfer/DispatchScanEndpointTest.php`:

```php
<?php

namespace Tests\Feature\Transfer;

class DispatchScanEndpointTest extends TransferTestCase
{
    public function test_scan_endpoint_autoroutes_without_detail_id(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 1);
        [$sn] = $this->makeSerials($from->location_id, $prod->product_id, 1);
        $user = $this->makeUser();

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 1],
        ]);

        $res = $this->actingAs($user)
            ->postJson("/api/ist/{$ist->transfer_id}/scan", ['barcode' => $sn]);

        $res->assertOk();
        $res->assertJsonPath('data.total_scanned', 1);
        $res->assertJsonPath('data.ist_fully_scanned', true);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/Transfer/DispatchScanEndpointTest.php`
Expected: PASS. (If auth guard differs, model it on an existing endpoint test
in `tests/Feature/Transfer/TransferFlowTest.php`.)

- [ ] **Step 5: Run the whole transfer suite**

Run: `php artisan test tests/Feature/Transfer`
Expected: PASS (all transfer tests green).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/ISTController.php tests/Feature/Transfer/DispatchScanEndpointTest.php
git commit -m "feat: ISTController::scan delegates to service, detail_id optional (auto-route)"
```

---

### Task 5: Receive-side auto-route (`recordReceiveScan` resolves serials)

The receive page also needs the global camera to auto-route. `recordReceiveScan`
currently requires `transfer_detail_id`. Make it optional and resolve serialized
scans by matching the in-transit serial to its line.

**Files:**
- Modify: `app/Services/TransferService.php::recordReceiveScan`
- Modify: `app/Http/Controllers/Api/ISTController.php::receiveScan`
- Test: `tests/Feature/Transfer/ReceiveScanResolveTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Transfer;

use App\Services\TransferService;

class ReceiveScanResolveTest extends TransferTestCase
{
    public function test_receive_scan_resolves_serial_to_line(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 1);
        [$sn] = $this->makeSerials($from->location_id, $prod->product_id, 1);
        $user = $this->makeUser();
        $svc  = new TransferService();

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 1],
        ]);

        // Dispatch the unit so it's In Transit with a 'transferred' serial.
        $svc->recordDispatchScan($ist, $sn, null, $user->user_id);
        $svc->dispatch($ist->fresh('details'), $user->user_id);

        // Receive with NO transfer_detail_id — must resolve from the serial.
        $res = $svc->recordReceiveScan($ist->fresh('details'), $sn, null, $user->user_id);

        $this->assertSame(
            $ist->details->first()->transfer_detail_id,
            $res['transfer_detail_id']
        );
        $this->assertSame(1, $res['quantity_received']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Transfer/ReceiveScanResolveTest.php`
Expected: FAIL — `recordReceiveScan` signature requires int `$transferDetailId`,
or `transfer_detail_id` not returned.

- [ ] **Step 3: Update `recordReceiveScan`**

Change the signature and add resolution. In `app/Services/TransferService.php`,
replace the `recordReceiveScan` signature line:

```php
public function recordReceiveScan(Transfer $ist, string $barcode, ?int $transferDetailId, int $userId, int $qty = 1): array
```

Immediately after the `In Transit` status guard, and **before** the existing
`$detail = TransferDetail::where('transfer_detail_id', ...)` lookup, insert
resolution that fills `$transferDetailId` when it is null:

```php
        if ($transferDetailId === null) {
            $match = ItemSerial::where('transfer_id', $ist->transfer_id)
                ->where('serial_type', 'transferred')
                ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower(trim($barcode))])
                ->first();
            if (!$match) {
                throw new RuntimeException('This serial is not part of this shipment.');
            }
            $line = TransferDetail::where('transfer_id', $ist->transfer_id)
                ->where('product_id', $match->product_id)
                ->first();
            if (!$line) {
                throw new RuntimeException('This serial is not part of this shipment.');
            }
            $transferDetailId = (int) $line->transfer_detail_id;
        }
```

Then add `transfer_detail_id` to the returned array (in the `return [...]` at the
end of the method):

```php
            'transfer_detail_id' => (int) $detail->transfer_detail_id,
```

(The existing serialized in-transit match check below remains and still applies
when a detail id WAS supplied.)

- [ ] **Step 4: Make the controller param optional**

In `app/Http/Controllers/Api/ISTController.php::receiveScan`, change the
validation rule for the line id to optional and pass null through:

```php
            'transfer_detail_id' => 'sometimes|nullable|integer',
```

and where it calls the service, pass `$validated['transfer_detail_id'] ?? null`.

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Feature/Transfer`
Expected: PASS (including the new resolve test and all prior tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/TransferService.php app/Http/Controllers/Api/ISTController.php tests/Feature/Transfer/ReceiveScanResolveTest.php
git commit -m "feat: receive-scan auto-routes serials to line when detail_id omitted"
```

---

### Task 6: Full-lifecycle integrity regression test (#2 #3 #4 #5)

One end-to-end test that ties the requirements together and asserts the
reconciliation invariant.

**Files:**
- Test: `tests/Feature/Transfer/SerialIntegrityFlowTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Transfer;

use App\Models\ItemSerial;
use App\Models\Inventory;
use App\Services\TransferService;

class SerialIntegrityFlowTest extends TransferTestCase
{
    public function test_dispatch_receive_moves_serial_and_blocks_resale_and_redispatch(): void
    {
        $b = $this->makeLocation('WH-B'); // source
        $a = $this->makeLocation('SR-A'); // destination (requester)
        $prod = $this->makeAppliance();
        $this->setStock($b->location_id, $prod->product_id, 1);
        [$sn] = $this->makeSerials($b->location_id, $prod->product_id, 1);
        $user = $this->makeUser();
        $svc  = new TransferService();

        $ist = $this->makeApprovedIst($b->location_id, $a->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 1],
        ]);

        // Dispatch (In Transit): serial flips to 'transferred', stays at source location.
        $svc->recordDispatchScan($ist, $sn, null, $user->user_id);
        $svc->dispatch($ist->fresh('details'), $user->user_id);

        // #3: in transit -> not issuable anywhere.
        $this->assertNull(ItemSerial::issuableAt($b->location_id, $sn)->first());

        // Receive at A.
        $svc->recordReceiveScan($ist->fresh('details'), $sn, null, $user->user_id);
        $svc->receive($ist->fresh('details'), $user->user_id);

        // #4: serial now at destination, gone from source inventory.
        $serial = ItemSerial::whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($sn)])->first();
        $this->assertSame($a->location_id, (int) $serial->location_id);
        $this->assertSame('received', $serial->serial_type);
        $srcInv = Inventory::where('location_id', $b->location_id)->where('product_id', $prod->product_id)->first();
        $this->assertSame(0, (int) $srcInv->quantity_on_hand);

        // #4: now issuable at A, not at B.
        $this->assertNotNull(ItemSerial::issuableAt($a->location_id, $sn)->first());
        $this->assertNull(ItemSerial::issuableAt($b->location_id, $sn)->first());

        // #5: B can no longer dispatch this serial.
        $ist2 = $this->makeApprovedIst($b->location_id, $a->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 1],
        ]);
        $this->expectException(\RuntimeException::class);
        $svc->recordDispatchScan($ist2, $sn, null, $user->user_id);
    }
}
```

- [ ] **Step 2: Run it**

Run: `php artisan test tests/Feature/Transfer/SerialIntegrityFlowTest.php`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Transfer/SerialIntegrityFlowTest.php
git commit -m "test: end-to-end serial integrity across dispatch/receive (#2-#5)"
```

---

## PART 2 — FRONTEND: Shared camera + transfer pages

> No JS test harness exists for these camera-driven pages; verification is
> manual (steps included). Keep the extraction behavior-identical.

### Task 7: Extract `useBarcodeCamera` hook

Lift the camera lifecycle out of `IssuanceScanPage.jsx` verbatim into a hook so
the transfer pages don't become a 3rd/4th copy.

**Files:**
- Create: `frontend/src/admin/hooks/useBarcodeCamera.js`

- [ ] **Step 1: Create the hook**

Move these from `frontend/src/admin/pages/IssuanceScanPage.jsx` into the new file
**unchanged** (cut the module-level helpers and the camera state/refs/functions):
- The module-level helpers/constants: `playScanFeedback`, `HTML5_FORMATS`,
  `getHtml5CameraScanConfig`, `buildBarcodeReaderHints`, `getCropReader`,
  `tryDecodeVideoCenterCropZxing`, `NATIVE_BARCODE_FORMATS`,
  `hasNativeBarcodeDetector`, `isLikelyVirtualCameraLabel`,
  `sortVideoDevicesPhysicalFirst`, `sortPhysicalCamerasDefaultFirst`,
  `assertCameraEnvironment`, `cameraAccessErrorMessage`,
  `enumerateVideoInputsWithLabels`, `pickPreferredCameraDeviceId`
  (`IssuanceScanPage.jsx:52-259`).
- The camera React state, refs, and functions: `cameraActive`, `cameraError`,
  `cameraDevices`, `selectedCameraId`, `html5QrRef`, `lastCodeRef`,
  `lastTimeRef`, `stopReaderAndTracks`, `applyDecodedBarcode`,
  `startHtml5OnDevice`, `startCamera`, `onCameraDeviceChange`, `stopCamera`,
  `manualFrameScan` (`IssuanceScanPage.jsx:362-368, 404-408, 700-854`).

Wrap them as a hook. Replace the page's `CAMERA_STORAGE_KEY`/mount-id constants
with parameters so each page uses its own:

```js
// frontend/src/admin/hooks/useBarcodeCamera.js
import { useState, useRef, useCallback, useEffect } from 'react';
import {
  BinaryBitmap, DecodeHintType, HTMLCanvasElementLuminanceSource,
  HybridBinarizer, MultiFormatReader, NotFoundException,
} from '@zxing/library';
import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';
import { toast } from '../utils/toast';

// ...(paste the module-level helpers listed above, unchanged)...

/**
 * Camera barcode scanning lifecycle, extracted from IssuanceScanPage.
 * @param {(code:string)=>void} onDecode  called with each decoded barcode
 * @param {{ mountId:string, storageKey:string }} opts
 */
export function useBarcodeCamera(onDecode, { mountId, storageKey }) {
  const [cameraActive, setCameraActive]     = useState(false);
  const [cameraError, setCameraError]       = useState('');
  const [cameraDevices, setCameraDevices]   = useState([]);
  const [selectedCameraId, setSelectedCameraId] = useState(() => {
    try { return localStorage.getItem(storageKey) || ''; } catch { return ''; }
  });

  const html5QrRef    = useRef(null);
  const lastCodeRef   = useRef('');
  const lastTimeRef   = useRef(0);
  const onDecodeRef   = useRef(onDecode);
  useEffect(() => { onDecodeRef.current = onDecode; }, [onDecode]);

  // ...(paste stopReaderAndTracks, applyDecodedBarcode [call onDecodeRef.current(code)],
  //     startHtml5OnDevice, startCamera, onCameraDeviceChange, stopCamera,
  //     manualFrameScan — replacing ISP_HTML5_QR_MOUNT_ID with mountId and
  //     CAMERA_STORAGE_KEY with storageKey)...

  useEffect(() => () => {
    const h = html5QrRef.current;
    html5QrRef.current = null;
    if (h) { h.stop().catch(() => {}); }
  }, []);

  const cameraSelectValue = cameraDevices.length > 0
    ? (cameraDevices.some((d) => d.deviceId === selectedCameraId) ? selectedCameraId : cameraDevices[0].deviceId)
    : '';

  return {
    cameraActive, cameraError, cameraDevices, selectedCameraId, cameraSelectValue,
    startCamera, stopCamera, onCameraDeviceChange, manualFrameScan,
    isLikelyVirtualCameraLabel,
  };
}
```

- [ ] **Step 2: Verify the build still compiles**

Run: `cd frontend && npm run build`
Expected: build succeeds (the hook is valid even though no page imports it yet).

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/hooks/useBarcodeCamera.js
git commit -m "refactor: extract useBarcodeCamera hook from IssuanceScanPage"
```

---

### Task 8: `<CameraScanner>` presentational component

**Files:**
- Create: `frontend/src/admin/components/CameraScanner.jsx`

- [ ] **Step 1: Create the component**

Lift the camera JSX block from `IssuanceScanPage.jsx:1259-1322` (the
`bs-camera-box` div, brackets, scan line, shutter, Start/Stop button, and the
camera `<select>` bar) into a component driven by the hook:

```jsx
// frontend/src/admin/components/CameraScanner.jsx
import React from 'react';
import { useBarcodeCamera } from '../hooks/useBarcodeCamera';
import '../styles/barcode_scan.css';

/**
 * Global camera scanner used by the transfer dispatch/receive pages.
 * Calls onDecode(code) for each scanned barcode. busy disables the shutter.
 */
export default function CameraScanner({ onDecode, mountId, storageKey, busy = false, hint }) {
  const {
    cameraActive, cameraError, cameraDevices, cameraSelectValue,
    startCamera, stopCamera, onCameraDeviceChange, manualFrameScan,
    isLikelyVirtualCameraLabel,
  } = useBarcodeCamera(onDecode, { mountId, storageKey });

  return (
    <>
      <div className="bs-camera-box" style={{ marginBottom: 12 }}>
        <div id={mountId} className="bs-html5qrcode-mount" style={{ display: cameraActive ? 'block' : 'none' }} />
        {!cameraActive && (
          <div className="bs-cam-idle">
            <div className="bs-cam-icon-wrap">
              <svg viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.6)" strokeWidth="1.5" width="38" height="38">
                <path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
              </svg>
            </div>
          </div>
        )}
        <div className="bs-bracket bs-tl"/><div className="bs-bracket bs-tr"/>
        <div className="bs-bracket bs-bl"/><div className="bs-bracket bs-br"/>
        {cameraActive && <div className="bs-scan-line"/>}
        {cameraActive && <p className="bs-cam-hint">{hint || 'Position the barcode in the frame and tap Scan.'}</p>}
        {cameraError && <p className="bs-cam-err">{cameraError}</p>}
        {cameraActive && (
          <button type="button" className="bs-cam-shutter" onClick={manualFrameScan} disabled={busy} aria-label="Scan barcode from current camera frame">
            <span className="bs-cam-shutter-inner"/>
            <span className="bs-cam-shutter-label">Scan</span>
          </button>
        )}
        {cameraActive
          ? <button className="bs-cam-btn bs-cam-stop" onClick={stopCamera} type="button">Stop Camera</button>
          : <button className="bs-cam-btn bs-cam-start" onClick={startCamera} type="button" disabled={busy}>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                <path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
              </svg>
              Start Camera
            </button>
        }
      </div>
      {cameraDevices.length > 0 && (
        <div className="bs-cam-select-bar" style={{ marginBottom: 12 }}>
          <label htmlFor={`${mountId}-device`} className="bs-cam-select-bar-label">Camera</label>
          <select id={`${mountId}-device`} className="bs-cam-select-bar-input" value={cameraSelectValue} onChange={onCameraDeviceChange}>
            {cameraDevices.map((d) => (
              <option key={d.deviceId} value={d.deviceId}>
                {d.label}{isLikelyVirtualCameraLabel(d.label) ? ' (virtual)' : ''}
              </option>
            ))}
          </select>
        </div>
      )}
    </>
  );
}
```

- [ ] **Step 2: Verify build**

Run: `cd frontend && npm run build`
Expected: build succeeds.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/components/CameraScanner.jsx
git commit -m "feat: shared CameraScanner component"
```

---

### Task 9: Mount the scanner on `ISTDispatchPage`

**Files:**
- Modify: `frontend/src/admin/pages/ISTDispatchPage.jsx`

- [ ] **Step 1: Import and add a global-scan handler**

At the top of `ISTDispatchPage.jsx`, add:

```jsx
import CameraScanner from '../components/CameraScanner';
import { playScanFeedback } from '../hooks/useBarcodeCamera'; // optional: export it from the hook if a beep is wanted
```

Inside the component, add a handler that records via the existing API without a
line id (auto-route) and updates the matching line's running totals:

```jsx
const handleGlobalScan = useCallback(async (code) => {
  const barcode = String(code || '').trim();
  if (!barcode) return;
  try {
    const result = await istAPI.scan(id, { barcode });
    const data = result?.data ?? result;
    const did  = data.transfer_detail_id;
    setSessionScans((prev) => ({
      ...prev,
      [did]: [...(prev[did] || []), { scan_id: data.scan_id, barcode, quantity: 1 }],
    }));
    setScannedTotals((prev) => ({ ...prev, [did]: data.total_scanned }));
    setDetailUi((prev) => ({
      ...prev,
      [did]: { ...initDetailState(), feedback: data.remaining > 0 ? `Recorded. ${data.remaining} remaining.` : 'All units scanned.' },
    }));
    toast.success(`Scanned ${data.product_name}`);
  } catch (err) {
    const msg = err?.message || 'Scan rejected.';
    toast.error('Scan rejected', msg);
  }
}, [id]);
```

- [ ] **Step 2: Render the scanner above the per-line cards**

Just before the `{(ist.details || []).map((detail) => {` block, add:

```jsx
<div className="po-details-card" style={{ marginBottom: 16 }}>
  <CameraScanner
    onDecode={handleGlobalScan}
    mountId="ist-dispatch-scanner"
    storageKey="bs_inventory_camera_device_id"
    busy={submitting}
    hint="Scan each unit leaving the source. It will be matched to its product automatically."
  />
</div>
```

- [ ] **Step 3: Manual verification**

Run the app (`cd frontend && npm run dev`, backend served via XAMPP). Open an
Approved IST's dispatch page. Verify:
- "Start Camera" shows a live preview; "Scan" records a unit and the matching
  line's Scanned count increments with a toast.
- Scanning a serial not on the IST shows a "not part of this transfer" toast.
- The per-line manual "Add" box still works.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/pages/ISTDispatchPage.jsx
git commit -m "feat: camera scanner on IST dispatch page (auto-route)"
```

---

### Task 10: Mount the scanner on `ISTReceivePage`

**Files:**
- Modify: `frontend/src/admin/pages/ISTReceivePage.jsx`

- [ ] **Step 1: Import and add the handler**

Add `import CameraScanner from '../components/CameraScanner';` and:

```jsx
const handleGlobalScan = useCallback(async (code) => {
  const barcode = String(code || '').trim();
  if (!barcode) return;
  try {
    const result = await istAPI.receiveScan(id, { barcode });
    const data = result?.data ?? result;
    const did  = data.transfer_detail_id;
    setSessionScans((prev) => ({
      ...prev,
      [did]: [...(prev[did] || []), { scan_id: data.scan_id, barcode, quantity: data.quantity_received - (receivedTotals[did] || 0) || 1 }],
    }));
    setReceivedTotals((prev) => ({ ...prev, [did]: data.quantity_received }));
    setDetailUi((prev) => ({
      ...prev,
      [did]: { ...initDetailState(), feedback: data.remaining > 0 ? `Recorded. ${data.remaining} remaining.` : 'All units received.' },
    }));
    toast.success('Unit received');
  } catch (err) {
    toast.error('Scan rejected', err?.message || 'Scan rejected.');
  }
}, [id, receivedTotals]);
```

- [ ] **Step 2: Render the scanner above the per-line cards**

Just before the `{/* ── Per-line scan cards ── */}` map, add:

```jsx
<div className="po-details-card" style={{ marginBottom: 16 }}>
  <CameraScanner
    onDecode={handleGlobalScan}
    mountId="ist-receive-scanner"
    storageKey="bs_inventory_camera_device_id"
    busy={submitting}
    hint="Scan each arriving unit. It will be matched to its product automatically."
  />
</div>
```

- [ ] **Step 3: Manual verification**

Open an In-Transit IST's receive page. Verify camera scan increments the
matching line's Received count; a serial not in the shipment is rejected; manual
"Add" still works; "Confirm Receipt" finalizes.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/pages/ISTReceivePage.jsx
git commit -m "feat: camera scanner on IST receive page (auto-route)"
```

---

## Final verification

- [ ] **Backend suite green:** `php artisan test tests/Feature/Transfer` → all PASS.
- [ ] **Frontend build:** `cd frontend && npm run build` → succeeds.
- [ ] **Manual smoke (full loop):** request → approve → IST → dispatch (camera) →
  receive (camera) → receipt. Confirm source stock dropped, destination stock
  rose, and the receipt lists the serials.
- [ ] **#3 spot check:** dispatch a serialized unit, then try to scan it on an
  Issuance session at the source → rejected ("not found / not stocked here").

---

## Spec coverage check

- Camera scanner on Dispatch + Receive (Choice 1, auto-route) → Tasks 7–10.
- Shared component (D1) → Tasks 7–8; existing pages untouched (non-goal honored).
- Reuse `serial_type` (D2) → Tasks 1–2, no migration.
- #2 double-dispatch → Task 3 (`recordDispatchScan` dup + serial gates), Task 6.
- #3 issue-out in transit → Tasks 1–2, asserted in Task 6.
- #4 ownership moves → existing `receive()`, asserted in Task 6.
- #5 re-transfer gone serial → Task 3 (source-location gate), asserted in Task 6.
