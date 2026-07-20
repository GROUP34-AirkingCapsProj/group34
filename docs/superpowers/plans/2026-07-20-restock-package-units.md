# Restock Package Units for Installation Materials — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restock recommendations for installation materials convert pieces → packages at the ordering boundary (PR page), and the analytics engine's open-PO netting compares like units.

**Architecture:** "Analyze in pieces, order in packages." All stored analytics values stay in pieces (no schema change). The engine's `openPoByWarehouse()` converts ordered packages → pieces in SQL. The recommendations API appends read-time package fields (`order_packages`, `pieces_per_package`, `package_unit`). `PurchaseRequestPage.jsx` shows/applies package counts at its four recommendation surfaces; Item Management and the suggestions tab get "pieces" unit labels.

**Tech Stack:** Laravel 11 (back-end), PHPUnit feature tests (`DatabaseTransactions` against `airkingV5_test`), React 18 (frontend/src/admin), MariaDB.

**Spec:** `docs/superpowers/specs/2026-07-20-restock-package-units-design.md`

**Key domain facts (verified in code):**
- `installation_material_details` (model `ConsumableDetail`, relation `Product::consumableDetail()`) holds `pieces_per_package` (nullable int) and `package_unit` (nullable varchar). All columns except `product_id` are nullable.
- `purchase_order_details.quantity_ordered` = **packages** for `product_type = 'installation_material'`, units for appliances.
- `receiving_details.quantity_amount` = always **pieces**.
- `restock_recommendations.recommended_quantity` = **pieces** (decimal(10,2) in DB, engine writes ints).
- Backend tests run from `back-end/`: `php artisan test tests/Feature/Restock` (27 existing tests must stay green). Do NOT run blanket `php artisan migrate` (schema is SQL-dump based).

---

### Task 1: Engine — convert ordered packages → pieces in `openPoByWarehouse()`

**Files:**
- Modify: `back-end/app/Services/Analytics/RestockAnalyticsService.php:420-456` (`openPoByWarehouse()`)
- Test: `back-end/tests/Feature/Restock/RestockAnalyticsServiceTest.php`

- [ ] **Step 1: Write two failing tests**

Add to `RestockAnalyticsServiceTest.php`, after `test_open_po_is_scoped_to_its_destination_warehouse()` (~line 428). These reuse the file's existing helpers (`makeProduct`, `setStock`, `issueDaily`, `findRecAt`) and mirror the math of test (C) `test_open_po_quantity_reduces_recommended_quantity` (demand 1/day × 30, stock 3, default lead 7 → safety 3, reorder 10, target 33):

```php
    // ---------------------------------------------------------------
    // (C3) Open-PO netting converts material packages -> pieces
    // ---------------------------------------------------------------

    /** Insert an installation_material_details row so the product is packaged. */
    private function makeMaterialDetail(int $productId, int $piecesPerPackage, string $packageUnit = 'box'): void
    {
        DB::table('installation_material_details')->insert([
            'product_id'         => $productId,
            'pieces_per_package' => $piecesPerPackage,
            'package_unit'       => $packageUnit,
            'is_serialized'      => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    public function test_open_po_packages_convert_to_pieces_for_materials(): void
    {
        // Material packaged 5 pieces/package. Same demand math as test (C):
        // demand 1/day for 30 days, stock 3, lead 7 -> safety 3, reorder 10, target 33.
        // Open PO of 2 PACKAGES = 10 pieces -> qty = 33 - 3 - 10 = 20.
        // (Before the fix the engine saw 2, recommending 28.)
        $pid = $this->makeProduct();
        $this->makeMaterialDetail($pid, 5);
        $this->setStock($pid, 3);
        $this->issueDaily($pid, 1, 30);

        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number'  => 'PO-TST-' . uniqid(),
            'order_date' => $this->asOf->copy()->subDays(2)->toDateString(),
            'status_id'  => 7, // Pending -> open
            'created_at' => now(),
            'updated_at' => now(),
        ], 'po_id');
        DB::table('purchase_order_details')->insert([
            'po_id' => $poId, 'product_id' => $pid, 'location_id' => $this->warehouseId,
            'quantity_ordered' => 2, // PACKAGES
            'unit_price' => 80, 'subtotal' => 160, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service->analyze($this->asOf);
        $rec = $this->findRecAt($result, $pid, $this->warehouseId);

        $this->assertNotNull($rec);
        $this->assertSame(10, $rec['on_order_quantity']);   // 2 packages x 5 pieces
        $this->assertSame(20, $rec['recommended_quantity']); // 33 - 3 - 10
    }

    public function test_partially_received_material_po_nets_in_pieces(): void
    {
        // Ordered 2 packages x 5 = 10 pieces; 4 pieces already received.
        // Open = 10 - 4 = 6 pieces -> qty = 33 - 3 - 6 = 24.
        $pid = $this->makeProduct();
        $this->makeMaterialDetail($pid, 5);
        $this->setStock($pid, 3);
        $this->issueDaily($pid, 1, 30);

        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number'  => 'PO-TST-' . uniqid(),
            'order_date' => $this->asOf->copy()->subDays(2)->toDateString(),
            'status_id'  => 7, // Pending -> open
            'created_at' => now(),
            'updated_at' => now(),
        ], 'po_id');
        DB::table('purchase_order_details')->insert([
            'po_id' => $poId, 'product_id' => $pid, 'location_id' => $this->warehouseId,
            'quantity_ordered' => 2, // PACKAGES
            'unit_price' => 80, 'subtotal' => 160, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $receivingId = DB::table('receivings')->insertGetId([
            'po_id' => $poId, 'location_id' => $this->warehouseId,
            'receiving_number' => 'RCV-TST-' . uniqid(),
            'receiving_date'   => $this->asOf->copy()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ], 'receiving_id');
        DB::table('receiving_details')->insert([
            'receiving_id' => $receivingId, 'product_id' => $pid,
            'quantity_amount' => 4, // PIECES (always)
            'prod_price' => 80, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service->analyze($this->asOf);
        $rec = $this->findRecAt($result, $pid, $this->warehouseId);

        $this->assertNotNull($rec);
        $this->assertSame(6, $rec['on_order_quantity']);     // 10 pieces ordered - 4 received
        $this->assertSame(24, $rec['recommended_quantity']); // 33 - 3 - 6
    }
```

Note: existing tests (B)/(C)/(C2) create `installation_material` products **without** an `installation_material_details` row → `COALESCE(imd.pieces_per_package, 1)` = 1 → identity conversion. They must keep passing unchanged; they double as the ppp-null edge-case tests.

- [ ] **Step 2: Run the new tests to verify they fail**

```powershell
cd back-end; php artisan test tests/Feature/Restock/RestockAnalyticsServiceTest.php --filter=test_open_po_packages_convert_to_pieces_for_materials
```

Expected: FAIL — `on_order_quantity` is 2 (raw packages), `recommended_quantity` is 28.

- [ ] **Step 3: Implement the conversion in `openPoByWarehouse()`**

In `back-end/app/Services/Analytics/RestockAnalyticsService.php`, replace the `$ordered` query (currently lines 423-430) with:

```php
        // quantity_ordered means PACKAGES for installation materials and units
        // (pieces) for appliances. Received quantities are always pieces, so
        // convert ordered -> pieces before netting.
        $ordered = DB::table('purchase_order_details as pod')
            ->join('purchase_orders as po', 'po.po_id', '=', 'pod.po_id')
            ->join('products as p', 'p.product_id', '=', 'pod.product_id')
            ->leftJoin('installation_material_details as imd', 'imd.product_id', '=', 'pod.product_id')
            ->whereIn('po.status_id', self::OPEN_PO_STATUSES)
            ->whereNull('po.deleted_at')
            ->whereNotNull('pod.location_id')
            ->groupBy('pod.location_id', 'pod.product_id')
            ->selectRaw(
                "pod.location_id, pod.product_id,
                 SUM(CASE WHEN p.product_type = 'installation_material'
                          THEN pod.quantity_ordered * GREATEST(COALESCE(imd.pieces_per_package, 1), 1)
                          ELSE pod.quantity_ordered END) as qty"
            )
            ->get();
```

The `$received` query and the netting loop below it stay unchanged (already pieces).

- [ ] **Step 4: Run the full Restock service test file**

```powershell
cd back-end; php artisan test tests/Feature/Restock/RestockAnalyticsServiceTest.php
```

Expected: PASS — all pre-existing tests plus the 2 new ones.

- [ ] **Step 5: Commit**

```powershell
git add back-end/app/Services/Analytics/RestockAnalyticsService.php back-end/tests/Feature/Restock/RestockAnalyticsServiceTest.php
git commit -m "fix(analytics): net open-PO material packages as pieces in restock engine"
```

---

### Task 2: API — package fields on `GET /api/restock/recommendations`

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/RestockRecommendationController.php:25-73` (`index()`)
- Test: `back-end/tests/Feature/Restock/RestockEndpointsTest.php`

- [ ] **Step 1: Write two failing tests**

Add to `RestockEndpointsTest.php` (uses the file's existing `makeUser()` helper; `restock_recommendations` nullable columns verified — the insert below covers every NOT NULL column):

```php
    /** Insert a stored recommendation row directly (read-time fields are computed by the API). */
    private function insertRecommendation(int $productId, int $locationId, int $qty): void
    {
        DB::table('restock_recommendations')->insert([
            'product_id'           => $productId,
            'location_id'          => $locationId,
            'current_stock'        => 20,
            'reorder_level'        => 100,
            'recommended_quantity' => $qty,
            'reason'               => 'test row',
            'priority'             => 'HIGH',
            'reason_codes'         => json_encode(['LOW_STOCK']),
            'avg_daily_demand'     => 9.3,
            'lead_time_days'       => 7.0,
            'on_order_quantity'    => 0,
            'run_at'               => now(),
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }

    public function test_recommendations_include_package_fields_for_materials(): void
    {
        // Material packaged 50 pieces/roll, stored need 280 pieces
        // -> order_packages = ceil(280/50) = 6.
        $pid = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Packaged Tube',
            'product_type'       => 'installation_material',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 0,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');
        DB::table('installation_material_details')->insert([
            'product_id'         => $pid,
            'pieces_per_package' => 50,
            'package_unit'       => 'roll',
            'is_serialized'      => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
        $this->insertRecommendation($pid, $this->secondWarehouseId, 280);

        $resp = $this->actingAs($this->makeUser('admin'), 'sanctum')
            ->getJson('/api/restock/recommendations');
        $resp->assertOk();

        $row = collect($resp->json('data.recommendations'))->firstWhere('product_id', $pid);
        $this->assertNotNull($row, 'stored recommendation must be returned');
        $this->assertSame(50, $row['pieces_per_package']);
        $this->assertSame('roll', $row['package_unit']);
        $this->assertSame(6, $row['order_packages']); // ceil(280/50), not 5.6 rounded down
    }

    public function test_package_fields_are_identity_or_null_for_edge_cases(): void
    {
        // (a) Material WITHOUT a details row (ppp null) -> order_packages == recommended_quantity.
        $bare = (int) DB::table('products')->insertGetId([
            'product_code' => 'TST-' . uniqid(),
            'product_name' => 'Bare Material',
            'product_type' => 'installation_material',
            'unit_price'   => 100, 'cost_price' => 80,
            'recommended_stocks' => 0, 'status_id' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
        $this->insertRecommendation($bare, $this->secondWarehouseId, 300);

        // (b) Appliance -> all three package fields null.
        $appliance = (int) DB::table('products')->insertGetId([
            'product_code' => 'TST-' . uniqid(),
            'product_name' => 'Test Aircon',
            'product_type' => 'appliance',
            'unit_price'   => 100, 'cost_price' => 80,
            'recommended_stocks' => 0, 'status_id' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
        $this->insertRecommendation($appliance, $this->secondWarehouseId, 4);

        $resp = $this->actingAs($this->makeUser('admin'), 'sanctum')
            ->getJson('/api/restock/recommendations');
        $resp->assertOk();
        $rows = collect($resp->json('data.recommendations'));

        $bareRow = $rows->firstWhere('product_id', $bare);
        $this->assertNotNull($bareRow);
        $this->assertSame(1, $bareRow['pieces_per_package']);
        $this->assertSame(300, $bareRow['order_packages']); // identity: 300/1

        $appRow = $rows->firstWhere('product_id', $appliance);
        $this->assertNotNull($appRow);
        $this->assertNull($appRow['pieces_per_package']);
        $this->assertNull($appRow['package_unit']);
        $this->assertNull($appRow['order_packages']);
    }
```

- [ ] **Step 2: Run the new tests to verify they fail**

```powershell
cd back-end; php artisan test tests/Feature/Restock/RestockEndpointsTest.php --filter=package_fields
```

Expected: FAIL — `Undefined array key "pieces_per_package"` (fields not in response yet).

- [ ] **Step 3: Implement in `RestockRecommendationController::index()`**

Two edits. First, eager-load the packaging detail — change line 27 from:

```php
        $query = RestockRecommendation::with('product')
```

to:

```php
        $query = RestockRecommendation::with('product.consumableDetail')
```

Second, replace `$rows = $query->get();` (line 49) with:

```php
        // Package fields are computed at read time so they always reflect the
        // CURRENT pieces_per_package, even if packaging changed after the run.
        // recommended_quantity stays the true pieces need (spec: analyze in
        // pieces, order in packages).
        $rows = $query->get()->map(function ($r) {
            $arr        = $r->toArray();
            $isMaterial = $r->product?->product_type === 'installation_material';
            $cd         = $r->product?->consumableDetail;
            $ppp        = max(1, (int) ($cd?->pieces_per_package ?? 1));

            $arr['pieces_per_package'] = $isMaterial ? $ppp : null;
            $arr['package_unit']       = $isMaterial ? ($cd?->package_unit ?: null) : null;
            $arr['order_packages']     = $isMaterial
                ? (int) ceil((float) $r->recommended_quantity / $ppp)
                : null;
            return $arr;
        });
```

Note: `optional($rows->first())->run_at` on line 61 keeps working because array access on a plain array via `optional()` fails — check: `$rows->first()` is now an **array**, so change line 61-62 from:

```php
            'run_at'          => optional($rows->first())->run_at
                ?? StockParameterSuggestion::max('run_at'),
```

to:

```php
            'run_at'          => ($rows->first()['run_at'] ?? null)
                ?? StockParameterSuggestion::max('run_at'),
```

- [ ] **Step 4: Run the endpoint test file**

```powershell
cd back-end; php artisan test tests/Feature/Restock/RestockEndpointsTest.php
```

Expected: PASS — all pre-existing endpoint tests plus the 2 new ones.

- [ ] **Step 5: Commit**

```powershell
git add back-end/app/Http/Controllers/Api/RestockRecommendationController.php back-end/tests/Feature/Restock/RestockEndpointsTest.php
git commit -m "feat(api): read-time package fields on restock recommendations"
```

---

### Task 3: PR page — `toOrderQty` helper, "+ Add" prefill fix, dual-unit card

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseRequestPage.jsx` (module scope ~line 30; `addRecommendedItem` ~line 544-568; reco card ~line 1056)

No JS test runner (CRA scaffold only) — each step is edit + visual sanity check; end-to-end verification is Task 6.

- [ ] **Step 1: Add the module-scope helper**

In `PurchaseRequestPage.jsx`, directly after the imports block (before the component and other module-level consts like `RECO_PRIORITY_BADGE`), add:

```js
// pieces → packages for installation materials; passthrough otherwise.
// PR/PO quantity means PACKAGES for materials, so recommendation piece counts
// must be converted before landing in a qty field (ceil = safe over-order).
const toOrderQty = (pieces, prod) => {
  const ppp = Number(prod?.piecesPerPackage) || 0;
  if (prod?.type === 'installation_material' && ppp > 1) {
    return Math.ceil(pieces / ppp);
  }
  return pieces;
};
```

- [ ] **Step 2: Fix the "+ Add" prefill (primary bug fix)**

In `addRecommendedItem` (~line 549), replace:

```js
    const qty     = Math.max(1, Number(rec.recommended_quantity) || 1);
```

with:

```js
    // rec.recommended_quantity is PIECES; the PR qty field means PACKAGES for
    // materials. Prefer the API's order_packages; fall back to local conversion.
    const pieces  = Math.max(1, Number(rec.recommended_quantity) || 1);
    const qty     = Math.max(1, Number(rec.order_packages) || toOrderQty(pieces, prod));
```

(`Number(null)` is 0, so appliances — where `order_packages` is null — fall through to `toOrderQty`, which passes pieces through unchanged.)

- [ ] **Step 3: Dual-unit recommendation card**

At ~line 1056, replace:

```jsx
                        <span className="pr-reco-item-stat">order <strong>{r.recommended_quantity}</strong></span>
```

with:

```jsx
                        <span className="pr-reco-item-stat">
                          {(() => {
                            const ppp = Number(r.pieces_per_package) || 0;
                            if (r.order_packages != null && ppp > 1) {
                              const pkgLabel  = r.package_unit || 'package';
                              const pieceAbbr = productById.get(String(r.product_id))?.unitAbbr || 'pcs';
                              return (
                                <>order <strong>{r.order_packages} {pkgLabel}{Number(r.order_packages) === 1 ? '' : 's'}</strong>
                                  {' '}({r.recommended_quantity} {pieceAbbr})</>
                              );
                            }
                            return <>order <strong>{r.recommended_quantity}</strong></>;
                          })()}
                        </span>
```

(`productById` is already in scope — defined at ~line 325. Materials with ppp ≤ 1 and appliances keep the single-number display, per spec.)

- [ ] **Step 4: Commit**

```powershell
git add frontend/src/admin/pages/PurchaseRequestPage.jsx
git commit -m "fix(pr): apply restock recommendations as packages, dual-unit reco cards"
```

---

### Task 4: PR page — "Suggested" hint conversion + QTY column pieces hint

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseRequestPage.jsx` (suggested-qty hint ~line 1119-1144; QTY cell ~line 1112-1145)

- [ ] **Step 1: Convert the per-row "Suggested: N → apply" hint**

In the QTY-cell IIFE (~lines 1126-1143), replace:

```js
                            const rec  = recByProduct.get(String(r.productId));
                            const prod = productById.get(String(r.productId));
                            const suggestedQty = rec
                              ? Number(rec.recommended_quantity) || 0
                              : Number(prod?.recommendedStocks) || 0;
```

with:

```js
                            const rec  = recByProduct.get(String(r.productId));
                            const prod = productById.get(String(r.productId));
                            // Both sources are PIECES (recommended_stocks is pieces by
                            // convention); the qty field means PACKAGES for materials.
                            const suggestedQty = rec
                              ? (Number(rec.order_packages)
                                  || toOrderQty(Number(rec.recommended_quantity) || 0, prod))
                              : toOrderQty(Number(prod?.recommendedStocks) || 0, prod);
                            const suggestedUnit = prod?.type === 'installation_material'
                              && (Number(prod?.piecesPerPackage) || 0) > 1
                              ? ` ${prod.packageUnit || 'package'}${suggestedQty === 1 ? '' : 's'}`
                              : '';
```

and update the button label (~line 1141) from:

```jsx
                                Suggested: {suggestedQty} → apply
```

to:

```jsx
                                Suggested: {suggestedQty}{suggestedUnit} → apply
```

- [ ] **Step 2: Add the read-only pieces hint under the QTY input**

Directly after the `<input ... className="po-qty-input" ... />` for qty (~line 1113-1118, before the suggested-hint IIFE), add:

```jsx
                          {r.productType === 'installation_material' && Number(r.piecesPerPackage) > 1 && (
                            <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 2 }}>
                              × {r.piecesPerPackage} {r.unitName || 'pcs'}/{r.packageUnit || 'package'}
                              {' '}= {(Number(r.qty) || 0) * Number(r.piecesPerPackage)} {r.unitName || 'pcs'}
                            </div>
                          )}
```

(Row fields `piecesPerPackage`, `packageUnit`, `unitName` are already populated by `handleProductSelect` and `addRecommendedItem`.)

- [ ] **Step 3: Commit**

```powershell
git add frontend/src/admin/pages/PurchaseRequestPage.jsx
git commit -m "feat(pr): package-unit suggested hints and pieces conversion under qty input"
```

---

### Task 5: Unit labels — suggestions tab "(pieces)" + Item Management input hints

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseRequestPage.jsx` (suggestions table headers ~line 1996-2001)
- Modify: `frontend/src/admin/pages/ItemManagement.jsx` (stock-level labels ~line 1638-1668)

- [ ] **Step 1: Suggestions-tab headers**

In `PurchaseRequestPage.jsx` (~lines 1998-2000), replace:

```jsx
                      <th>Recommended stock</th>
                      <th>Safety stock</th>
                      <th>Max level</th>
```

with:

```jsx
                      <th>Recommended stock <span className="ak-muted">(pieces)</span></th>
                      <th>Safety stock <span className="ak-muted">(pieces)</span></th>
                      <th>Max level <span className="ak-muted">(pieces)</span></th>
```

- [ ] **Step 2: Item Management unit hints**

In `ItemManagement.jsx`, immediately before the component's main `return (` statement, add:

```js
  // Stock parameters for installation materials are entered in PIECES
  // (convention: docs/superpowers/specs/2026-07-20-restock-package-units-design.md).
  const stockParamUnitHint = (() => {
    if (productType !== 'installation_material') return null;
    const u = units.find(un => String(un.unit_id) === String(formData.unit_id));
    return `in pieces${u?.unit_abbreviation ? ` (${u.unit_abbreviation})` : ''}`;
  })();
```

Then update the three labels in the "Stock Levels" block (~lines 1640, 1650, 1660). For each, e.g.:

```jsx
                <label>RECOMMENDED STOCKS</label>
```

becomes:

```jsx
                <label>
                  RECOMMENDED STOCKS
                  {stockParamUnitHint && (
                    <span style={{ textTransform: 'none', fontWeight: 400, color: '#94a3b8' }}> — {stockParamUnitHint}</span>
                  )}
                </label>
```

Apply the identical pattern to `SAFETY STOCK` and `MAX STOCK LEVEL`.

- [ ] **Step 3: Commit**

```powershell
git add frontend/src/admin/pages/PurchaseRequestPage.jsx frontend/src/admin/pages/ItemManagement.jsx
git commit -m "feat(ui): label stock parameters as pieces for installation materials"
```

---

### Task 6: Full verification

- [ ] **Step 1: Run the whole Restock backend suite**

```powershell
cd back-end; php artisan test tests/Feature/Restock
```

Expected: PASS — 27 pre-existing + 4 new = 31 tests. (Known unrelated failures elsewhere on branch: NavBundleTest, frontend App.test.jsx.)

- [ ] **Step 2: Frontend build check**

```powershell
cd frontend; npm run build
```

Expected: CRA production build completes ("Compiled successfully" or warnings only — no errors).

- [ ] **Step 3: Manual end-to-end verification (dev DB)**

1. `cd back-end; php artisan analytics:restock` to refresh recommendations.
2. Open the PR page as admin, pick a warehouse block, open the low-stock panel: a packaged material (e.g., a tube with `pieces_per_package = 50`) must show `order N <unit>s (M pcs)` where `N = ceil(M/50)`.
3. Click **+ Add**: the row's QTY must be `N` (packages), the sub-hint must read `× 50 …/roll = N×50 …`, and the subtotal must be `N × cost`.
4. The `Suggested: … → apply` hint on a manually selected material row must show a package count with unit.
5. Stock Parameters tab: headers show "(pieces)".
6. Item Management → edit an installation material: the three stock-level labels show "— in pieces (…)".

- [ ] **Step 4: Final commit if any fixups were needed**

```powershell
git add -A
git commit -m "test(restock): verify package-unit conversion end to end"
```

(Skip if the working tree is clean.)
