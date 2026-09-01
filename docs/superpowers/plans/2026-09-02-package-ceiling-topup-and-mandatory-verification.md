# Package Ceiling, Piece Top-Up, and Mandatory Verification

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Stop a 3-package order accepting a 4th package, give the clerk somewhere to record pieces that arrive later, show packages and pieces together, and make contents verification mandatory and discoverable.

**Architecture:** Over-receipt gains a second ceiling on packages, since short-filled boxes decoupled the piece limit from the package limit. A top-up posts pieces with `packages_received = 0`, so loose stock never claims a carton. A line carries a verified-at stamp so completion can require it.

**Tech Stack:** Laravel 11 (PHP 8.2), MySQL, PHPUnit with `DatabaseTransactions`, React 18.

---

## Why these four are one change

The user ordered 3 packages of an 82-piece material. Two were counted short (150 pieces of an expected 164) and one was scanned (82). Their report: *"i try to add another count and it adds when there should only 3 orders, now it become 4"* and *"i dont know where to input to complete the missing quantity that arrived."*

`ReceivingPostingService::checkOverage()` bounds receiving in **pieces only**:

```php
$orderedPieces   = quantity_ordered * pieces_per_package;   // 3 × 82 = 246
$alreadyReceived = SUM(quantity_amount);                     // 232
$remaining       = $orderedPieces - $alreadyReceived;        // 14
```

That was safe while every package posted exactly `ppp` pieces — the piece ceiling implied a package ceiling. Piece verification decoupled them: three half-full boxes consume 123 pieces, leaving room for a fourth and fifth box inside the same budget.

The flip side is the missing-quantity problem. Those 14 pieces will arrive as loose stock or a replacement, not as a fourth carton, and the only way to record them today is to claim a package.

## Conventions — read before Task 1

- Tests in `back-end/tests/Feature/Inventory/`, `use DatabaseTransactions;` — **not** `RefreshDatabase`. Real MySQL test DB `airkingV5_test`.
- Models created inline with `uniqid()`; no factories.
- **Migrations must be applied to BOTH databases:**
  ```bash
  cd back-end && php artisan migrate --force
  cd back-end && DB_DATABASE=airkingV5_test php artisan migrate --force
  ```
- Column migrations use the house guard `if (Schema::hasColumn(...)) { return; }` plus a real `down()`.
- `@testing-library/user-event` is pinned at **13.5.0** — no `.setup()` API.
- Branch `backupV4`. Do not branch or push.
- Leave the untracked `back-end/database/ci-schema.sql` alone.

## Baselines

Backend `2 skipped, 1398 passed (5557 assertions)`. Frontend `322 passed`.

## Verified facts

- `checkOverage(PurchaseOrderDetail, Product, int $pieces): ?array` returns null or `['action' => 'over_receipt', 'ordered_pieces', 'already_received', 'remaining', 'counted', 'overage']`.
- `ReceivingPostingService::post(...)` takes `?int $packagesReceived = null, ?int $piecesExpected = null` as its last two parameters.
- The scan path passes `packagesReceived: 1, piecesExpected: $piecesPerPackage` for non-serialized products.
- `ReceivingController::poScanStatus()` returns `already_received` and `already_received_packages` per line.
- `receiving_details` has `packages_received` and `pieces_expected`, both nullable. **Legacy rows predating them hold NULL**, so any `SUM` must `COALESCE`.
- Frontend progress: `bsrProductProgress` (pieces) and `bsrPackageProgress` (packages), both keyed by `po_detail_id`.

---

## Task 1: Bound over-receipt on packages

**Files:**
- Modify: `back-end/app/Services/ReceivingPostingService.php` — `checkOverage()`
- Modify: `back-end/app/Http/Controllers/Api/ReceiveCountController.php` — pass the package count
- Test: `back-end/tests/Feature/Inventory/PackageCeilingTest.php`

- [ ] **Step 1: Write the failing test**

Cover:
1. **A 4th package on a 3-package order is refused even when pieces fit.** Order 3 packages at ppp 82 (246 pieces). Count 2 packages with `actual_pieces: 150`, scan 1 package (82) — 232 pieces, 3 packages. Then count 1 more package with `actual_pieces: 10`, which fits the 14-piece remainder. Assert `data.action` is `over_receipt`, that the reason names the **package** limit, and that no new `receiving_details` row was created. This is the user's exact report.
2. **Confirming still works** — the same request with `accept_overage: true` posts.
3. **The piece ceiling still fires independently** — a 1-package order, scan it, then count 1 more with a large `actual_pieces`; assert `over_receipt` for pieces.
4. **A top-up does not consume the package budget** — deferred to Task 2; note it here so the two tests stay consistent.

- [ ] **Step 2: Run, confirm it fails**

`cd back-end && php artisan test tests/Feature/Inventory/PackageCeilingTest.php` — test 1 fails because the 4th package posts.

- [ ] **Step 3: Add the package ceiling**

Widen `checkOverage()` to take the packages being added:

```php
    public function checkOverage(
        PurchaseOrderDetail $poDetail,
        Product $product,
        int $pieces,
        ?int $packages = null
    ): ?array
```

Keep the existing piece check exactly as it is. Then, for non-serialized products when `$packages !== null`:

```php
        // Short-filled boxes decoupled the two ceilings. Before piece
        // verification, every package posted exactly ppp pieces, so bounding
        // pieces implicitly bounded packages. Now three half-full boxes consume
        // half the piece budget and leave room for a fourth carton the order
        // never asked for.
        $orderedPackages  = (int) $poDetail->quantity_ordered;
        $receivedPackages = (int) ReceivingDetail::where('po_detail_id', $poDetail->po_detail_id)
            ->sum(DB::raw('COALESCE(packages_received, 0)'));

        if ($receivedPackages + $packages > $orderedPackages) {
            return [
                'action'             => 'over_receipt',
                'limit'              => 'packages',
                'ordered_packages'   => $orderedPackages,
                'received_packages'  => $receivedPackages,
                'remaining_packages' => max(0, $orderedPackages - $receivedPackages),
                'counted_packages'   => $packages,
                'overage_packages'   => ($receivedPackages + $packages) - $orderedPackages,
            ];
        }
```

Add `'limit' => 'pieces'` to the existing piece payload so callers can tell the two apart. Keep every existing key — the frontend and three test files read them.

**`COALESCE` matters:** legacy rows hold NULL and would poison a bare `SUM`.

Pass the package count from `ReceiveCountController` — `$packagesReceived` for a package-counted line, `null` for a piece-counted one, which has no package concept.

- [ ] **Step 4: Run tests, then the full suite**

Baseline `2 skipped, 1398 passed`. Existing over-receipt tests read the piece payload; adding a key must not break them — if any does, report rather than loosening it.

- [ ] **Step 5: Commit**

```bash
git commit -m "fix(receiving): bound over-receipt on packages as well as pieces"
```

---

## Task 2: The pieces-only top-up

**Files:**
- Create: `back-end/app/Http/Controllers/Api/ReceiveTopUpController.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/Inventory/ReceiveTopUpTest.php`

When boxes arrive short, the missing pieces come later as loose stock. Recording them must not claim a carton.

- [ ] **Step 1: Write the failing test**

Cover:
1. **A top-up adds pieces without a package** — 3 packages received holding 232 of 246. Top up 14 pieces. Assert inventory rises to 246, a `receiving_details` row exists with `quantity_amount = 14`, `packages_received = 0`, and `pieces_expected` NULL, and the PO line reaches Completed.
2. **A top-up does not consume the package budget** — after it, `SUM(COALESCE(packages_received,0))` is still 3, and a further package attempt is still refused by Task 1's ceiling.
3. **Over-receipt in pieces still applies** — topping up more than the piece remainder returns `over_receipt` and posts nothing; with `accept_overage` it posts.
4. **Serialized products are refused** — an appliance has no package contents to top up.
5. **A line with nothing received is refused** — there is no shortfall to complete; the clerk wants the count panel.

- [ ] **Step 2: Run, confirm 404**

- [ ] **Step 3: Write the controller**

`POST /inventory/receive-topup`, single-action, mirroring `ReceiveCountController`'s shape and envelope.

```php
            'po_detail_id'   => 'required|integer|exists:purchase_order_details,po_detail_id',
            'location_id'    => 'nullable|exists:locations,location_id',
            'pieces'         => 'required|integer|min:1',
            'accept_overage' => 'nullable|boolean',
```

Guard order: no product → 422; serialized → `rejected`; PO not verified or wrong status → `rejected`; nothing received on the line yet → `rejected` with a reason pointing at the count panel.

Then `checkOverage($poDetail, $product, $pieces, packages: 0)` — zero packages, so only the piece ceiling can fire. Transaction, `lockForUpdate()`, then `post(...)` with `entryMode: 'counted'`, `packagesReceived: 0`, `piecesExpected: null`.

**`packages_received = 0`, not null.** Null means "this row predates the column"; zero means "no carton arrived, only loose pieces". The distinction is what keeps Task 1's ceiling honest.

Sync PO status, commit, return `applied` with the new totals.

- [ ] **Step 4: Register the route**

Inside the existing `permission:barcode_inventory_processing,create` group beside `/inventory/receive-count`.

- [ ] **Step 5: Run, then commit**

```bash
git commit -m "feat(receiving): record pieces that arrive after a short delivery"
```

---

## Task 3: Mandatory, discoverable contents verification

**Files:**
- Create: `back-end/database/migrations/2026_09_02_000001_add_contents_verified_at_to_receiving_details.php`
- Modify: `back-end/app/Http/Controllers/Api/ReceiveVerifyController.php`
- Modify: `back-end/app/Http/Controllers/Api/ReceiveCountController.php`
- Modify: `back-end/app/Http/Controllers/Api/ReceivingController.php` — `poScanStatus()`
- Test: `back-end/tests/Feature/Inventory/ContentsVerificationTest.php`

A scanned package's contents are trusted unless someone checks. The check exists but is optional and hidden behind a button.

- [ ] **Step 1: The migration**

```php
$table->timestamp('contents_verified_at')->nullable()->after('pieces_expected');
```

House guard, real `down()`, applied to **both** databases.

Docblock: a scanned row is unverified until a human confirms what was inside. A counted row is verified at entry, because counting *is* the verification.

Add to `ReceivingDetail::$fillable`.

- [ ] **Step 2: Write the failing test**

Cover:
1. **A counted row is verified at entry** — `contents_verified_at` is set by `receive-count`, because the clerk counted the pieces to enter them.
2. **A scanned row starts unverified** — NULL after a scan.
3. **Verification stamps every row of the line** — after `receive-verify`, no row on the line has NULL.
4. **`poScanStatus` reports it** — a new `contents_verified` boolean per line, false while any material row is unstamped.
5. **A top-up inherits the line's state** — it does not reset verification, since loose pieces are counted as they are recorded.

- [ ] **Step 3: Implement**

`ReceiveCountController` → `post(...)` sets `contents_verified_at = now()` for counted rows. Add a parameter to `post()` rather than writing the column from the controller, so the single write path stays single.

`ReceiveVerifyController` → stamps `contents_verified_at` on every row of the line, in the pass that already sets `packages_received` and `pieces_expected`.

`poScanStatus()` → per line, `contents_verified` is true when no material row has a NULL `contents_verified_at`. Legacy rows predating the column are NULL and would read as unverified forever; treat a line whose rows **all** predate the column as verified, or the screen will nag about history nobody can fix. Decide and comment.

- [ ] **Step 4: Run, then commit**

```bash
git commit -m "feat(receiving): record whether a line's package contents were verified"
```

---

## Task 4: The receiving screen

**Files:**
- Modify: `frontend/src/admin/pages/BarcodeScan.jsx`
- Modify: `frontend/src/admin/styles/po_receiving.css`
- Modify: `frontend/src/admin/services/api.js`

`BarcodeScan.jsx` is ~4,500 lines. Read the regions you touch; make surgical additions.

- [ ] **Step 1: Add the API method**

```js
  receiveTopUp: (data) =>
    apiRequest('/inventory/receive-topup', { method: 'POST', body: JSON.stringify(data ?? {}) }),
```

Match the surrounding hand-rolled `fetch` style.

- [ ] **Step 2: Packages and quantities together**

Line ~3533 currently renders `${receivedPkgs} / ${ordered} pkg`. Show both counters:

```
2 / 3 pkg  ·  150 / 246 pcs
```

Appliance lines keep showing units — check the existing branch before changing it.

- [ ] **Step 3: The shortfall as a call to action**

Beneath the counters, when the received packages hold fewer pieces than they should (`packagesReceived × ppp > piecesReceived`):

```
14 pcs short — add missing pieces
```

The text is the control that opens the top-up field. This is the answer to *"i dont know where to input to complete the missing quantity"* — the shortfall itself is the entry point, so nothing has to be hunted for.

The top-up panel is one field (pieces) plus confirm/cancel, calling `inventoryAPI.receiveTopUp`. Handle `applied` / `over_receipt` / `rejected` exactly as `bsrPostCount` does, and route a top-up overage back to `receiveTopUp` on confirm — not to `receiveCount`, which would claim a package.

- [ ] **Step 4: Make verification mandatory and visible**

A line whose `contents_verified` is false shows an unmissable state on the line — not a button the clerk must know to press:

```
3 / 3 pkg  ·  246 / 246 pcs
Contents not verified — confirm what was inside
```

That text opens the existing verify panel. The line must not read as complete while unverified, even when the piece count is full: `done` requires `contents_verified` as well as the quantities.

Keep the existing "Verify contents" button; this makes the same step discoverable rather than replacing it.

- [ ] **Step 5: Handle the package-limit over-receipt**

Task 1's payload carries `limit: 'packages'` with package-specific keys. The existing overage confirmation reads piece keys. Render whichever the payload describes, so a package refusal reads *"you have received all 3 packages"* rather than showing blank piece figures.

- [ ] **Step 6: Verify in a browser**

`cd frontend && npm start`. Against PO detail 108 (3 packages of 82, currently 232 pieces / 2 recorded packages):

1. The line shows both counters and a shortfall.
2. Clicking the shortfall opens the top-up; 14 pieces completes the line.
3. A 4th package is refused, naming the package limit.
4. An unverified line does not read as complete.

**Report what you actually ran and saw.** If you cannot log in, say so — do not present inspection as testing.

- [ ] **Step 7: Run suites, commit**

---

## Out of scope

- Per-carton attribution. Verification stays once per line; three boxes each short by one read as "3 short" without identifying which. Changing that means per-package entry, which was considered and rejected.
- A supplier discrepancy report. The columns support it; nobody has asked for the report.
