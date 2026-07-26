# Issuance Dedupe, Scanner Rework, and UI Visibility Fixes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Stop issuance duplicates (one open draft per user), simplify the OrderBuilder, give the Pick step a manual+camera scanner, group the transaction detail by product, and fix hidden UI.

**Architecture:** Backend `initSession` reuses the user's existing open Draft and a new `active-draft` endpoint lets the wizard resume it on mount. Frontend removes redundant OrderBuilder paths, adds a CameraScanner to the pick step (mirroring ReceivePanel), groups the detail modal by product, and fixes contrast in the wizard CSS.

**Tech Stack:** Laravel 11 (PHP 8.2), MySQL via XAMPP, PHPUnit + `DatabaseTransactions` against `airkingV5_test`, React 18 CRA, `ak-*` design system.

**Spec:** `docs/superpowers/specs/2026-07-26-issuance-dedupe-scanner-ui-fixes-design.md`

---

## Conventions
- Backend root `C:\XAMPP1\htdocs\Airking_Inventory\back-end`; frontend root `C:\XAMPP1\htdocs\Airking_Inventory\frontend`.
- `php artisan test` targets `airkingV5_test` (no env prefix); `DatabaseTransactions`, never `RefreshDatabase`.
- Roles: admin=1, inventory_analyst=2, branch_manager=3, warehouse_personnel=4, auditor=5. `actingAs($user,'sanctum')`.
- Issuance statuses: Draft/Pending(30)/Completed(31)/Voided(32)/Cancelled — name-resolved via `App\Support\IssuanceStatus`.
- `inventory_issuance,create` = admin, branch_manager, warehouse_personnel.
- Frontend has NO test suite — verify with `npm run build` (no NEW warnings from changed files); note a manual `npm run demo` pass.
- Commit per task; conventional prefixes; do NOT push.

---

## Task 1: initSession reuses the user's open Draft + `active-draft` endpoint

**Permission-gap check:** `active-draft` is gated `inventory_issuance,create` (admin/branch_manager/warehouse_personnel). Tests act as `warehouse_personnel` (a holder) — no gap.

**Files:**
- Modify: `app/Http/Controllers/Api/IssuanceScanController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Issuance/IssuanceDraftReuseTest.php`

- [ ] **Step 1: Write failing tests** — create `tests/Feature/Issuance/IssuanceDraftReuseTest.php`. Read `IssuanceScanController::initSession` first to confirm the required request fields (`customer_id`, `issuance_type` in ['business','individual'], optional discount/location/notes) and how it resolves location; build a valid payload. Use a real `customers` row (create one) and a warehouse location + user.

```php
<?php

namespace Tests\Feature\Issuance;

use App\Support\IssuanceStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class IssuanceDraftReuseTest extends TestCase
{
    use DatabaseTransactions;

    private function makeLocation(): \App\Models\Location
    {
        return \App\Models\Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'WH', 'location_type' => 'warehouse',
        ]);
    }
    private function makeUserAt(\App\Models\Location $loc, string $role = 'warehouse_personnel'): \App\Models\User
    {
        $map = ['admin'=>1,'inventory_analyst'=>2,'branch_manager'=>3,'warehouse_personnel'=>4,'auditor'=>5];
        return \App\Models\User::create([
            'username'=>'u_'.uniqid(),'first_name'=>'T','last_name'=>'U','email'=>uniqid().'@e.test',
            'password_hash'=>bcrypt('secret'),'role_id'=>$map[$role]??4,'location_id'=>$loc->location_id,
        ]);
    }
    private function makeCustomer(): \App\Models\Customer
    {
        return \App\Models\Customer::create(['customer_name'=>'C '.uniqid(),'customer_type'=>'individual']);
    }
    private function payload(int $customerId, int $locationId): array
    {
        return ['customer_id'=>$customerId,'issuance_type'=>'individual','location_id'=>$locationId];
    }

    public function test_second_init_returns_the_same_draft_no_duplicate(): void
    {
        $loc = $this->makeLocation();
        $user = $this->makeUserAt($loc);
        $cust = $this->makeCustomer();

        $first  = $this->actingAs($user,'sanctum')->postJson('/api/issuance-scan/init', $this->payload($cust->customer_id,$loc->location_id));
        $first->assertSuccessful();
        $id1 = $first->json('issuance_id');

        $second = $this->actingAs($user,'sanctum')->postJson('/api/issuance-scan/init', $this->payload($cust->customer_id,$loc->location_id));
        $second->assertSuccessful();
        $id2 = $second->json('issuance_id');

        $this->assertSame((int)$id1, (int)$id2, 'init created a duplicate draft');
        $this->assertSame(1, \App\Models\Issuance::where('issued_by',$user->user_id)->where('status_id',IssuanceStatus::draft())->count());
    }

    public function test_after_the_draft_leaves_draft_a_new_init_creates_a_fresh_one(): void
    {
        $loc = $this->makeLocation();
        $user = $this->makeUserAt($loc);
        $cust = $this->makeCustomer();

        $first = $this->actingAs($user,'sanctum')->postJson('/api/issuance-scan/init', $this->payload($cust->customer_id,$loc->location_id));
        $id1 = (int) $first->json('issuance_id');
        \App\Models\Issuance::where('issuance_id',$id1)->update(['status_id'=>IssuanceStatus::completed()]);

        $second = $this->actingAs($user,'sanctum')->postJson('/api/issuance-scan/init', $this->payload($cust->customer_id,$loc->location_id));
        $id2 = (int) $second->json('issuance_id');
        $this->assertNotSame($id1, $id2);
    }

    public function test_active_draft_returns_the_open_draft_then_null(): void
    {
        $loc = $this->makeLocation();
        $user = $this->makeUserAt($loc);
        $cust = $this->makeCustomer();

        $this->actingAs($user,'sanctum')->getJson('/api/issuance-scan/active-draft')
             ->assertOk()->assertJson(['issuance_id'=>null]);

        $init = $this->actingAs($user,'sanctum')->postJson('/api/issuance-scan/init', $this->payload($cust->customer_id,$loc->location_id));
        $id = (int) $init->json('issuance_id');

        $this->actingAs($user,'sanctum')->getJson('/api/issuance-scan/active-draft')
             ->assertOk()->assertJson(['issuance_id'=>$id]);

        \App\Models\Issuance::where('issuance_id',$id)->update(['status_id'=>IssuanceStatus::completed()]);
        $this->actingAs($user,'sanctum')->getJson('/api/issuance-scan/active-draft')
             ->assertOk()->assertJson(['issuance_id'=>null]);
    }
}
```
If a required column blocks Issuance/Customer/User creation, adapt the fixture (not the intent). Confirm the init route path is `/api/issuance-scan/init` and the response returns `issuance_id` at the top level (it does).

- [ ] **Step 2: Run — expect failures** (duplicate created; active-draft route 404). `php artisan test --filter=IssuanceDraftReuseTest`

- [ ] **Step 3: Make `initSession` reuse the user's open Draft**

In `IssuanceScanController::initSession`, BEFORE the `Issuance::create([...])`, insert a reuse check that returns the existing draft in the SAME response shape. Refactor the response building so both the reused and newly-created issuance return identically. Concretely, after `$userId = Auth::id();` and after resolving `$resolvedLocationId`, add:

```php
        $existingDraft = \App\Models\Issuance::where('issued_by', $userId)
            ->where('status_id', \App\Support\IssuanceStatus::draft())
            ->orderByDesc('issuance_id')
            ->first();

        if ($existingDraft) {
            return $this->initSessionResponse($existingDraft, 'Resumed your unfinished order.');
        }
```

Then extract the existing JSON response (the `customer` block + fields) into a private helper `initSessionResponse(Issuance $issuance, string $message): JsonResponse` and call it for the newly created issuance too, so the shape is identical. The helper loads `Customer::find($issuance->customer_id)` and returns the same keys the method returns today (`issuance_id, dr_number, issuance_type, discount_type, discount_percent, customer{...}`), with `success` + `message`. Keep the 201 status for a fresh create; a reuse can return 200.

- [ ] **Step 4: Add `activeDraft()` to the controller**

```php
    // GET /api/issuance-scan/active-draft — the caller's current open Draft, or null.
    public function activeDraft(): JsonResponse
    {
        $draft = \App\Models\Issuance::where('issued_by', Auth::id())
            ->where('status_id', \App\Support\IssuanceStatus::draft())
            ->orderByDesc('issuance_id')
            ->first();

        return response()->json([
            'success'     => true,
            'issuance_id' => $draft?->issuance_id !== null ? (int) $draft->issuance_id : null,
        ]);
    }
```

- [ ] **Step 5: Register the route in `routes/api.php`**

Find the `issuance-scan` route group (prefix `issuance-scan`, near line 438). Add inside it (gated the same way `init` is — confirm `init`'s gate and match it; if `init` is `permission:inventory_issuance,create`, use that):

```php
        Route::get('/active-draft', [IssuanceScanController::class, 'activeDraft']);
```
(Match the group's existing route declaration style — the prefix means the path is `/issuance-scan/active-draft`.)

- [ ] **Step 6: Run tests** — `php artisan test --filter=IssuanceDraftReuseTest` → 3 pass. Then `php artisan test` → green (report the line; was 611 passed / 1 skipped).

- [ ] **Step 7: Commit**
```bash
git add app/Http/Controllers/Api/IssuanceScanController.php routes/api.php tests/Feature/Issuance/IssuanceDraftReuseTest.php
git commit -m "fix(issuance): reuse the user's open draft so starting never duplicates"
```

---

## Task 2: Wizard resumes the active draft on mount

**Files:**
- Modify: `frontend/src/admin/services/api.js`
- Modify: `frontend/src/admin/pages/IssuanceScanPage.jsx`

- [ ] **Step 1: Add the API call** to `issuanceScanAPI` in api.js:
```javascript
  activeDraft: async () => apiRequest('/issuance-scan/active-draft'),
```

- [ ] **Step 2: Resume on mount.** In `IssuanceScanPage.jsx`, the mount effect already rehydrates when `?issuance=` is present. Extend the no-param branch: when there is no `issuance` param, call `issuanceScanAPI.activeDraft()`; if it returns a non-null `issuance_id`, set `?issuance=<id>` (replace) and load its quote (reuse the same resume path), so the user lands in their existing draft instead of a blank setup form. If it returns null (or the call fails), fall through to the setup form exactly as today. Keep the `hydrating` guard set while this resolves so the setup form doesn't flash. Read the current mount effect (around lines 122-150) and integrate cleanly; preserve the `cancelled` guard and the 404/403-vs-transient handling.

- [ ] **Step 3: New Order.** Confirm `resetAll` clears `?issuance=` and local state. Because the backend now reuses, a subsequent setup submit returns the same draft — no duplicate. (No further change required; do NOT add a cancel-on-new-order flow — out of scope.)

- [ ] **Step 4: Build** — `cd frontend && npm run build`; `Compiled successfully.`, no new warnings from the two files. Report tail.

- [ ] **Step 5: Commit**
```bash
git add frontend/src/admin/services/api.js frontend/src/admin/pages/IssuanceScanPage.jsx
git commit -m "feat(issuance): resume the user's active draft on mount (no duplicate starts)"
```
Manual demo: start an order, leave, return to the issuance page → you land back in the same draft (one record), not a new one.

---

## Task 3: OrderBuilder — remove Scan barcode + Add appliance

**Files:**
- Modify: `frontend/src/admin/components/issuance/OrderBuilder.jsx`

- [ ] **Step 1: Remove the Scan-barcode path.** Delete the `BarcodeAddPath` component, the `handleBarcodeScan` handler, and the "Scan barcode" block in the add-paths card.

- [ ] **Step 2: Remove the Add-appliance path.** Delete the `AddAppliancePath` component, the `handleAddAppliance` handler, and the "Add appliance" block. Keep **Add set** (`AddSetPath`/`handleAddSet`) and **Add custom** (`AddCustomPath`/`handleAddCustom`).

- [ ] **Step 3: Clean up.** Remove now-unused imports, state, and effects orphaned by the removals (e.g. the appliance set-suggestion `productSetsAPI.suggest` call, any `productsAPI` import used only by the barcode path — verify it's not used elsewhere in the file before removing). No dead code / no new eslint warnings.

- [ ] **Step 4: Build** — `npm run build`; clean, no new warnings from OrderBuilder.jsx. Report tail.

- [ ] **Step 5: Commit**
```bash
git add frontend/src/admin/components/issuance/OrderBuilder.jsx
git commit -m "refactor(issuance): drop redundant Scan-barcode and Add-appliance from OrderBuilder"
```

---

## Task 4: Pick-step scanner — add camera alongside the manual field

**Files:**
- Modify: `frontend/src/admin/components/picking/PickScanner.jsx`
- Possibly: `frontend/src/admin/pages/IssuanceScanPage.jsx` (only if a prop must be passed)

Reference pattern (READ these first): `frontend/src/admin/pages/transfers/ReceivePanel.jsx` (lines ~200-235) shows `<CameraScanner onDecode={handler} mountId=.. storageKey=.. busy=.. hint=../>` beside a manual `<input>`, both calling one scan handler. `frontend/src/admin/components/CameraScanner.jsx` exports `CameraScanner({ onDecode, mountId, storageKey, busy, hint })`.

- [ ] **Step 1: Read** `PickScanner.jsx` fully — its manual barcode `<input>` + `submit` flow that calls `onScan(barcode, confirmedZone)` and renders the verdict. Read `CameraScanner.jsx` for the prop contract.

- [ ] **Step 2: Add an optional CameraScanner.** Add a `camera` (boolean) prop to `PickScanner` (default false so transfer dispatch is unaffected unless it opts in). When `camera` is true, render `<CameraScanner onDecode={(code) => submitScan(code, currentZone)} mountId="pick-scan" storageKey="pick-scan-cam" busy={busy} hint="Scan the item barcode" />` near the manual field. Factor the manual field's submit into a shared `submitScan(barcode, confirmedZone)` so the camera decode and the manual Enter run the SAME verdict path (clear/refocus/flash/refetch). Keep the manual field for typing/scan-gun serials. Do not change how verdicts render.

- [ ] **Step 3: Enable it in the issuance Pick step.** In `IssuanceScanPage.jsx` where `<PickScanner ... />` is rendered (step 3), pass `camera`. (Transfer dispatch keeps the default unless separately opted in — out of scope here.)

- [ ] **Step 4: Build** — `npm run build`; clean, no new warnings. Report tail.

- [ ] **Step 5: Commit**
```bash
git add frontend/src/admin/components/picking/PickScanner.jsx frontend/src/admin/pages/IssuanceScanPage.jsx
git commit -m "feat(picking): camera + manual barcode/serial input on the pick scanner"
```
Manual demo: at the Pick step, both typing a barcode and the camera scan pick an item (FIFO-checked).

---

## Task 5: Transaction detail grouped by product

**Files:**
- Modify: `frontend/src/admin/pages/TransactionManagement.jsx`

- [ ] **Step 1: Group the detail lines.** In the issue-out detail modal, where the fetched line list is rendered, collapse lines sharing a `product_id` into one row: summed `quantity_requested` (or the issued qty field the detail uses), summed `line_total`, and a representative `unit_price` + `product_name`. Read the current modal line rendering (built earlier) and the line fields it uses; group by `product_id` before rendering. Keep the totals block (subtotal/discount/total) unchanged — only the per-line list collapses. If set-group context exists it may remain, but the flat product list must show each product once.

- [ ] **Step 2: Build** — `npm run build`; clean, no new warnings from TransactionManagement.jsx. Report tail.

- [ ] **Step 3: Commit**
```bash
git add frontend/src/admin/pages/TransactionManagement.jsx
git commit -m "fix(transactions): group issue-out detail lines by product"
```

---

## Task 6: UI visibility / hidden buttons (frontend-design)

**Files:**
- Modify: the issuance wizard stylesheet(s) (`isp-*` classes) and/or `TransactionManagement` detail styles as needed.

This task REQUIRES running the app and the **frontend-design** skill.

- [ ] **Step 1: Run the app.** From `frontend`, `npm run demo` (prod build server). Log in as admin. Open the Issuance wizard (Order/Quote/Pick) and a transaction detail modal.

- [ ] **Step 2: Identify invisible controls.** Find buttons/fields with insufficient contrast or missing affordance (the user reports buttons and inputs that "are like hidden"). Inspect the `isp-*` button classes (`isp-btn-secondary`, `isp-btn-ghost`, step dots, add-path buttons) and inputs (`ak-input`/`isp-form` fields) for transparent/near-white backgrounds, white-on-white text, or missing borders. Note each offending selector.

- [ ] **Step 3: Fix with frontend-design.** Using the frontend-design skill and the existing design-system tokens, give every button a visible fill/border and readable label, and every input a visible border/background, in both light and dark. Keep the established look; only fix contrast/visibility. Do not restyle unrelated pages.

- [ ] **Step 4: Verify visually.** Reload `npm run demo`; confirm all wizard + detail buttons and fields are clearly visible and clickable. Also `npm run build` clean.

- [ ] **Step 5: Commit**
```bash
git add frontend/src/admin/...   # the stylesheet(s) touched
git commit -m "fix(issuance): restore visibility/contrast of wizard buttons and inputs"
```

---

## Done Criteria
- Starting/opening an issue-out never creates a duplicate; a user has one open draft, resumed on return; the Transactions list shows one row per issue-out.
- OrderBuilder step 1 has only Add set + Add custom.
- The Pick step supports manual serial/barcode entry AND camera scanning, both FIFO-checked.
- The transaction detail shows each product once with a summed quantity.
- All wizard/detail buttons and inputs are visibly rendered.
- Backend suite green; frontend builds clean; manual demo pass done for the visual items.
