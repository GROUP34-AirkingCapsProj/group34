# PR Recommendation — Inline, Per-Warehouse Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename "PO Recommendation" → "PR Recommendation", make the restock analytics engine compute per-warehouse stock/on-order, surface low-stock suggestions inline inside each warehouse block of the Create Request form, and move the Stock Parameter Suggestions tool into a Purchase Request page tab while deleting the standalone page.

**Architecture:** The engine keeps demand velocity / trend / σ / lead time **org-wide (shared per product)** but derives `current_stock` and `on_order` **per warehouse** from `inventory.location_id`, `purchase_order_details.location_id`, and `receivings.location_id`, emitting one `restock_recommendations` row per `(product_id, location_id)`. The `GET /api/restock/recommendations?location_id=X` endpoint filters to one warehouse. The React Create Request form fetches a warehouse's recommendations (cached per location, same pattern as its budget cache) and renders a collapsible "Suggested for {Warehouse}" panel with one-click add-to-block. The standalone `PORecommendation.jsx` is deleted; its suggestions tool moves into a new "Stock Parameters" tab.

**Tech Stack:** Laravel 11 (PHP 8), PHPUnit feature tests (`DatabaseTransactions` against `airkingV5_test`), React 18 (CRA), MariaDB/XAMPP. Spec: `docs/superpowers/specs/2026-07-19-pr-recommendation-inline-design.md`.

---

## Reference facts (read before starting)

- Schema is a **SQL dump** (`airking_cleans.sql`), not full migrations. A blanket `php artisan migrate` fails; apply a single migration with `--path=` (see [[project-db-migration-test-convention]]). Tests use `DatabaseTransactions` against the pre-existing `airkingV5_test` DB, so **any new migration must be applied to both the dev DB and `airkingV5_test`**.
- `restock_recommendations` columns today: `recommendation_id` (PK), `product_id`, `location_id`, `current_stock`, `reorder_level`, `recommended_quantity`, `reason`, `priority`, `reason_codes` (json), `avg_daily_demand`, `demand_trend`, `days_of_cover`, `lead_time_days`, `on_order_quantity`, `status_id`, `run_at`, timestamps. No unique index on `(product_id, location_id)` yet.
- Permission `po_recommendations`: `read` = admin/IA/BM/auditor; `create` = admin/IA (recalculate); `update` = admin/IA (apply/dismiss). Keep the key; only relabel comments/display text.
- `persist()` deletes the whole `restock_recommendations` batch then re-inserts, so no upsert logic depends on a unique key.
- Config/route cache gotcha on this branch: run `php artisan config:clear` if env/DB overrides misbehave; keep uncached while testing.

---

## Task 1: Migration — unique index `(product_id, location_id)` on `restock_recommendations`

**Files:**
- Create: `back-end/database/migrations/2026_07_19_000001_restock_recommendations_per_warehouse_unique.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restock_recommendations', function (Blueprint $table) {
            // One row per product per warehouse. persist() replaces the whole
            // batch each run, so existing single-warehouse rows never collide.
            $table->unique(['product_id', 'location_id'], 'restock_reco_product_location_unique');
        });
    }

    public function down(): void
    {
        Schema::table('restock_recommendations', function (Blueprint $table) {
            $table->dropUnique('restock_reco_product_location_unique');
        });
    }
};
```

- [ ] **Step 2: Apply to the dev DB**

Run: `cd back-end && php artisan migrate --path=database/migrations/2026_07_19_000001_restock_recommendations_per_warehouse_unique.php`
Expected: `Migrated: 2026_07_19_000001_restock_recommendations_per_warehouse_unique`.
If it errors on duplicate rows, first clear stale data: `php artisan tinker --execute="DB::table('restock_recommendations')->delete();"` then re-run.

- [ ] **Step 3: Apply to the test DB**

Run: `cd back-end && php artisan migrate --path=database/migrations/2026_07_19_000001_restock_recommendations_per_warehouse_unique.php --database=mysql --env=testing`
(If `--env=testing` is not wired, temporarily set `DB_DATABASE=airkingV5_test` in the shell and run the same `--path` command. Verify the index exists: `php artisan tinker --execute="dump(DB::selectOne('SHOW INDEX FROM restock_recommendations WHERE Key_name = \'restock_reco_product_location_unique\''));"`)
Expected: a non-null index row.

- [ ] **Step 4: Commit**

```bash
git add back-end/database/migrations/2026_07_19_000001_restock_recommendations_per_warehouse_unique.php
git commit -m "feat(restock): unique index (product_id, location_id) on recommendations"
```

---

## Task 2: Engine — per-warehouse stock & on-order (shared demand)

**Files:**
- Modify: `back-end/app/Services/Analytics/RestockAnalyticsService.php`
- Test: `back-end/tests/Feature/Restock/RestockAnalyticsServiceTest.php`

The engine keeps all demand/lead-time math per product, but loops warehouses for the stock-dependent parts and emits one recommendation per `(product, warehouse)`.

- [ ] **Step 1: Write failing tests for per-warehouse behavior**

Add these tests to `RestockAnalyticsServiceTest.php`. Add a helper first (place near `setStock`):

```php
    private function makeWarehouse(): int
    {
        return (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Extra Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');
    }

    private function setStockAt(int $locationId, int $productId, int $qty): void
    {
        DB::table('inventory')->insert([
            'location_id'        => $locationId,
            'product_id'         => $productId,
            'quantity_on_hand'   => $qty,
            'available_quantity' => $qty,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    private function findRecAt(array $result, int $productId, int $locationId): ?array
    {
        foreach ($result['recommendations'] as $rec) {
            if ((int) $rec['product_id'] === $productId
                && (int) $rec['location_id'] === $locationId) {
                return $rec;
            }
        }
        return null;
    }
```

```php
    public function test_low_at_one_warehouse_only_recommends_that_warehouse(): void
    {
        $wh2 = $this->makeWarehouse();
        // Shared demand 1/day for 30 days -> lead 7, reorder 10, target 33.
        $pid = $this->makeProduct();
        $this->issueDaily($pid, 1, 30);
        $this->setStockAt($this->warehouseId, $pid, 3);   // low -> triggers
        $this->setStockAt($wh2, $pid, 500);               // well stocked -> no row

        $result = $this->service->analyze($this->asOf);

        $recLow  = $this->findRecAt($result, $pid, $this->warehouseId);
        $recHigh = $this->findRecAt($result, $pid, $wh2);
        $this->assertNotNull($recLow, 'low warehouse must be recommended');
        $this->assertNull($recHigh, 'well-stocked warehouse must not be recommended');
        $this->assertSame(3, $recLow['current_stock']);
    }

    public function test_shared_demand_is_identical_across_a_products_warehouse_rows(): void
    {
        $wh2 = $this->makeWarehouse();
        $pid = $this->makeProduct();
        $this->issueDaily($pid, 2, 30);                   // v30 = 2.0 org-wide
        $this->setStockAt($this->warehouseId, $pid, 1);   // low
        $this->setStockAt($wh2, $pid, 2);                 // also low

        $result = $this->service->analyze($this->asOf);
        $a = $this->findRecAt($result, $pid, $this->warehouseId);
        $b = $this->findRecAt($result, $pid, $wh2);

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertEqualsWithDelta((float) $a['avg_daily_demand'], (float) $b['avg_daily_demand'], 0.0001);
        $this->assertEqualsWithDelta((float) $a['lead_time_days'], (float) $b['lead_time_days'], 0.0001);
    }

    public function test_open_po_is_scoped_to_its_destination_warehouse(): void
    {
        $wh2 = $this->makeWarehouse();
        $pid = $this->makeProduct();
        $this->issueDaily($pid, 1, 30);                   // reorder 10, target 33
        $this->setStockAt($this->warehouseId, $pid, 3);
        $this->setStockAt($wh2, $pid, 3);

        // Open PO delivering 10 units to warehouseId ONLY.
        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number'  => 'PO-TST-' . uniqid(),
            'order_date' => $this->asOf->copy()->subDays(2)->toDateString(),
            'status_id'  => 7, // Pending -> open
            'created_at' => now(), 'updated_at' => now(),
        ], 'po_id');
        DB::table('purchase_order_details')->insert([
            'po_id' => $poId, 'product_id' => $pid, 'location_id' => $this->warehouseId,
            'quantity_ordered' => 10, 'unit_price' => 80, 'subtotal' => 800,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service->analyze($this->asOf);
        $a = $this->findRecAt($result, $pid, $this->warehouseId);
        $b = $this->findRecAt($result, $pid, $wh2);

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertSame(10, $a['on_order_quantity']);   // PO counted here
        $this->assertSame(0,  $b['on_order_quantity']);    // not at the other warehouse
        $this->assertSame(20, $a['recommended_quantity']); // 33 - 3 - 10
        $this->assertSame(30, $b['recommended_quantity']); // 33 - 3 - 0
    }
```

Also update the existing single-warehouse tests to assert location: in `test_fast_mover_...`, `test_lead_time_...`, `test_supplier_average_...`, `test_open_po_quantity_reduces_...`, `test_sales_details_...`, replace each `$this->findRec($result, $pid)` with `$this->findRecAt($result, $pid, $this->warehouseId)` (those helpers set stock at `$this->warehouseId`). Delete the now-unused `findRec()` helper.

Update the persistence test `test_run_persists_recommendations_and_replaces_previous_batch`: replace the `warehouse_location_id` assertions with a per-warehouse location check:

```php
        $this->assertSame($this->warehouseId, (int) $first->location_id);
```
(delete the two lines asserting `$result['warehouse_location_id']`).

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd back-end && php artisan test --filter=RestockAnalyticsServiceTest`
Expected: FAIL — new tests can't find `location_id` on recommendation rows / duplicate rows per product; several existing tests fail on the renamed helper.

- [ ] **Step 3: Rewrite `analyze()` to be location-aware**

In `RestockAnalyticsService.php`, replace the `stockOnHand()` method and `openPoQuantities()` method with per-location variants, and rewrite the `analyze()` loop. Full replacements:

Replace `private function stockOnHand(): array { ... }` with:

```php
    /** @return array<int, array<int,int>> locationId => [productId => qty], warehouses only */
    private function stockByWarehouse(): array
    {
        $rows = DB::table('inventory as inv')
            ->join('locations as l', 'l.location_id', '=', 'inv.location_id')
            ->where('l.location_type', 'warehouse')
            ->groupBy('inv.location_id', 'inv.product_id')
            ->selectRaw('inv.location_id, inv.product_id, SUM(inv.quantity_on_hand) as qty')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->location_id][(int) $r->product_id] = (int) $r->qty;
        }
        return $out;
    }

    /** @return array<int,int> productId => org-wide quantity_on_hand (all locations) */
    private function stockOnHandTotal(): array
    {
        return DB::table('inventory')
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity_on_hand) as qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($q) => (int) $q)
            ->all();
    }
```

Replace `private function openPoQuantities(): array { ... }` with:

```php
    /** @return array<int, array<int,int>> locationId => [productId => open-PO qty not yet received] */
    private function openPoByWarehouse(): array
    {
        $ordered = DB::table('purchase_order_details as pod')
            ->join('purchase_orders as po', 'po.po_id', '=', 'pod.po_id')
            ->whereIn('po.status_id', self::OPEN_PO_STATUSES)
            ->whereNull('po.deleted_at')
            ->whereNotNull('pod.location_id')
            ->groupBy('pod.location_id', 'pod.product_id')
            ->selectRaw('pod.location_id, pod.product_id, SUM(pod.quantity_ordered) as qty')
            ->get();

        $received = DB::table('receiving_details as rd')
            ->join('receivings as r', 'r.receiving_id', '=', 'rd.receiving_id')
            ->join('purchase_orders as po', 'po.po_id', '=', 'r.po_id')
            ->whereIn('po.status_id', self::OPEN_PO_STATUSES)
            ->whereNull('po.deleted_at')
            ->whereNotNull('r.location_id')
            ->groupBy('r.location_id', 'rd.product_id')
            ->selectRaw('r.location_id, rd.product_id, SUM(rd.quantity_amount) as qty')
            ->get();

        $recvMap = [];
        foreach ($received as $r) {
            $recvMap[(int) $r->location_id][(int) $r->product_id] = (int) $r->qty;
        }

        $out = [];
        foreach ($ordered as $o) {
            $loc = (int) $o->location_id;
            $pid = (int) $o->product_id;
            $out[$loc][$pid] = max(0, (int) $o->qty - (int) ($recvMap[$loc][$pid] ?? 0));
        }
        return $out;
    }
```

Now rewrite `analyze()`. Replace the whole method body between `public function analyze(...): array {` and its closing `}` with:

```php
        $asOf   = ($asOf ?? now())->copy()->startOfDay();
        $from90 = $asOf->copy()->subDays(self::WINDOW_LONG_DAYS - 1);
        $from30 = $asOf->copy()->subDays(self::WINDOW_SHORT_DAYS - 1)->toDateString();

        $daily      = $this->dailyDemand($from90->toDateString(), $asOf->toDateString());
        $stockByWh  = $this->stockByWarehouse();
        $stockTotal = $this->stockOnHandTotal();
        $openByWh   = $this->openPoByWarehouse();
        $leads      = $this->leadTimes();
        $received90 = $this->receivedQuantities($from90->toDateString());

        $warehouseIds = Location::query()
            ->where('location_type', 'warehouse')
            ->orderBy('location_id')
            ->pluck('location_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $products = Product::query()
            ->where(fn ($q) => $q->whereNull('status_id')
                ->orWhere('status_id', '!=', self::PRODUCT_INACTIVE_STATUS))
            ->get();

        $recommendations = [];
        $suggestions     = [];

        foreach ($products as $p) {
            $pid    = (int) $p->product_id;
            $series = $daily[$pid] ?? [];

            $total90 = array_sum($series);
            $total30 = 0;
            foreach ($series as $day => $qty) {
                if ($day >= $from30) {
                    $total30 += $qty;
                }
            }
            $v30        = $total30 / self::WINDOW_SHORT_DAYS;
            $v90        = $total90 / self::WINDOW_LONG_DAYS;
            $trend      = $v90 > 0 ? $v30 / $v90 : null;
            $demandDays = count(array_filter($series, fn ($q) => $q > 0));
            $sigma      = $this->dailyStdDev($series, self::WINDOW_LONG_DAYS);

            $lead = $leads['product'][$pid]
                ?? $leads['supplier'][$leads['productSupplier'][$pid] ?? -1]
                ?? self::DEFAULT_LEAD_TIME;
            $lead = max(self::LEAD_TIME_MIN, min(self::LEAD_TIME_MAX, $lead));

            $computedSafety = $demandDays >= self::SPARSE_DEMAND_DAYS
                ? (int) ceil(self::SERVICE_FACTOR * $sigma * sqrt($lead))
                : (int) ceil(0.5 * $v30 * $lead);
            $computedReorder = (int) ceil($v30 * $lead) + $computedSafety;
            $computedTarget  = (int) ceil(self::WINDOW_SHORT_DAYS * $v30) + $computedSafety;

            $currentRecommended = (int) $p->recommended_stocks;
            $currentSafety      = $p->safety_stock !== null ? (int) $p->safety_stock : null;
            $currentMax         = $p->max_stock_level !== null ? (int) $p->max_stock_level : null;

            // Current product values act as a floor so hand-tuned thresholds keep working.
            $effectiveSafety  = max($computedSafety, (int) ($currentSafety ?? 0));
            $effectiveReorder = max($computedReorder, $currentRecommended);
            $effectiveTarget  = max($computedTarget, (int) ($currentMax ?? 0), $currentRecommended);

            // ---- Restock recommendation, PER WAREHOUSE ------------------
            foreach ($warehouseIds as $whId) {
                $onHand = (int) ($stockByWh[$whId][$pid] ?? 0);
                $open   = (int) ($openByWh[$whId][$pid] ?? 0);
                $cover  = $v30 > 0 ? round($onHand / $v30, 1) : null;

                $reasons = [];
                if ($onHand <= 0) {
                    $reasons[] = 'OUT_OF_STOCK';
                }
                if ($onHand > 0 && $effectiveSafety > 0 && $onHand < $effectiveSafety) {
                    $reasons[] = 'BELOW_SAFETY_STOCK';
                }
                if ($onHand > 0 && $cover !== null && $cover < $lead) {
                    $reasons[] = 'STOCKOUT_RISK';
                }
                if ($effectiveReorder > 0 && $onHand <= $effectiveReorder) {
                    $reasons[] = 'LOW_STOCK';
                }
                if ($trend !== null && $trend >= self::TREND_FAST) {
                    $reasons[] = 'FAST_MOVING';
                }
                if (($received90[$pid] ?? 0) >= 1 && $total90 >= 3 * max(1, $onHand)) {
                    $reasons[] = 'HIGH_TURNOVER';
                }

                $triggered = $onHand <= 0
                    || ($effectiveSafety > 0 && $onHand < $effectiveSafety)
                    || ($effectiveReorder > 0 && $onHand <= $effectiveReorder);

                $qty = $effectiveTarget - $onHand - $open;
                if (!$triggered || $qty <= 0) {
                    continue;
                }

                $priority = 'MEDIUM';
                if ($onHand <= 0 || ($cover !== null && $cover < $lead / 2)) {
                    $priority = 'CRITICAL';
                } elseif (($cover !== null && $cover < $lead)
                    || ($effectiveSafety > 0 && $onHand < $effectiveSafety)) {
                    $priority = 'HIGH';
                }

                $recommendations[] = [
                    'product_id'           => $pid,
                    'location_id'          => $whId,
                    'current_stock'        => $onHand,
                    'reorder_level'        => $effectiveReorder,
                    'recommended_quantity' => $qty,
                    'reason'               => $this->summarize($v30, $trend, $cover, $lead, $onHand),
                    'priority'             => $priority,
                    'reason_codes'         => $reasons,
                    'avg_daily_demand'     => round($v30, 3),
                    'demand_trend'         => $trend !== null ? round($trend, 3) : null,
                    'days_of_cover'        => $cover,
                    'lead_time_days'       => round($lead, 1),
                    'on_order_quantity'    => $open,
                ];
            }

            // ---- Parameter suggestion (PRODUCT-LEVEL, org-wide) ---------
            $onHandTotal = (int) ($stockTotal[$pid] ?? 0);
            $coverTotal  = $v30 > 0 ? round($onHandTotal / $v30, 1) : null;

            $hasParams = $currentRecommended > 0
                || ($currentSafety ?? 0) > 0
                || ($currentMax ?? 0) > 0;

            if ($total90 <= 0 && !$hasParams) {
                continue; // nothing learned, nothing to shrink
            }

            if ($total90 <= 0) {
                $sReasons  = ['NO_MOVEMENT'];
                $suggested = [
                    'recommended' => (int) floor($currentRecommended / 2),
                    'safety'      => (int) floor(($currentSafety ?? 0) / 2),
                    'max'         => (int) floor(($currentMax ?? 0) / 2),
                ];
            } else {
                $sReasons = [];
                if ($trend !== null && $trend >= self::TREND_FAST) {
                    $sReasons[] = 'FAST_MOVING';
                }
                if ($trend !== null && $trend <= self::TREND_SLOW) {
                    $sReasons[] = 'SLOW_MOVING';
                }
                if ($sReasons === []) {
                    $sReasons[] = 'DEMAND_SHIFT';
                }
                $suggested = [
                    'recommended' => $computedReorder,
                    'safety'      => $computedSafety,
                    'max'         => $computedTarget,
                ];
            }

            $drifted = $this->differs($currentRecommended, $suggested['recommended'])
                || $this->differs((int) ($currentSafety ?? 0), $suggested['safety'])
                || $this->differs((int) ($currentMax ?? 0), $suggested['max']);

            if ($drifted) {
                $suggestions[] = [
                    'product_id'            => $pid,
                    'current_recommended'   => $currentRecommended,
                    'suggested_recommended' => $suggested['recommended'],
                    'current_safety'        => $currentSafety,
                    'suggested_safety'      => $suggested['safety'],
                    'current_max'           => $currentMax,
                    'suggested_max'         => $suggested['max'],
                    'reason_codes'          => $sReasons,
                    'summary'               => $this->summarize($v30, $trend, $coverTotal, $lead, $onHandTotal),
                ];
            }
        }

        return [
            'run_at'          => $asOf,
            'recommendations' => $recommendations,
            'suggestions'     => $suggestions,
        ];
```

- [ ] **Step 4: Update `persist()` to write each row's own location_id**

In `persist()`, replace the recommendations insert loop:

```php
            DB::table('restock_recommendations')->delete();
            foreach ($result['recommendations'] as $rec) {
                RestockRecommendation::create($rec + [
                    'location_id' => $result['warehouse_location_id'],
                    'run_at'      => $result['run_at'],
                ]);
            }
```

with (each `$rec` already carries `location_id`):

```php
            DB::table('restock_recommendations')->delete();
            foreach ($result['recommendations'] as $rec) {
                RestockRecommendation::create($rec + ['run_at' => $result['run_at']]);
            }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd back-end && php artisan test --filter=RestockAnalyticsServiceTest`
Expected: PASS (all cases, including the 3 new per-warehouse tests).

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Services/Analytics/RestockAnalyticsService.php back-end/tests/Feature/Restock/RestockAnalyticsServiceTest.php
git commit -m "feat(restock): per-warehouse stock/on-order recommendations; shared demand"
```

---

## Task 3: API — location filter on index, retire create-pr

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/RestockRecommendationController.php`
- Modify: `back-end/routes/api.php:545-556`
- Test: `back-end/tests/Feature/Restock/RestockEndpointsTest.php`

- [ ] **Step 1: Write/adjust failing tests**

In `RestockEndpointsTest.php`:

Delete the three create-pr tests (`test_create_pr_builds_draft_purchase_request_with_details`, `test_create_pr_rejects_empty_items`, `test_auditor_cannot_create_pr`) and the budget-seeding block in `setUp()` that exists only for create-pr (lines seeding `budget_allocations` with the "create-pr now requires an active budget" comment — remove that whole `DB::table('budget_allocations')->insert([...])` block and the `$firstWarehouseId` lookup above it).

Add a location-filter test:

```php
    public function test_recommendations_can_be_filtered_by_location(): void
    {
        $pid = $this->makeProductWithDemand(); // stock 1 at first warehouse -> low
        $ia  = $this->makeUser('inventory_analyst');

        // Populate the batch.
        $this->actingAs($ia, 'sanctum')->postJson('/api/restock/recalculate')->assertOk();

        $whId = (int) DB::table('locations')->where('location_type', 'warehouse')
            ->orderBy('location_id')->value('location_id');

        $resp = $this->actingAs($ia, 'sanctum')
            ->getJson('/api/restock/recommendations?location_id=' . $whId);

        $resp->assertOk();
        foreach ($resp->json('data.recommendations') as $row) {
            $this->assertSame($whId, (int) $row['location_id']);
        }
        $ids = array_map(fn ($r) => (int) $r['product_id'], $resp->json('data.recommendations'));
        $this->assertContains($pid, $ids);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd back-end && php artisan test --filter=RestockEndpointsTest`
Expected: FAIL — `create-pr` route still exists (tests deleted, fine) and the location filter is not yet applied (row from another warehouse could appear) OR route 404 not the issue; the new test may pass accidentally if only one warehouse — ensure it fails meaningfully by first implementing filter. If it passes already because there's a single warehouse, that's acceptable; proceed.

- [ ] **Step 3: Add the location filter to `index()`**

In `RestockRecommendationController::index()`, after the existing `search` filter block and before `$rows = $query->get();`, add:

```php
        if ($request->filled('location_id')) {
            $query->where('location_id', (int) $request->location_id);
        }
```

Then change the budget resolution to honor the requested warehouse. Replace:

```php
        $warehouseId = Location::where('location_type', 'warehouse')
            ->orderBy('location_id')
            ->value('location_id');
```

with:

```php
        $warehouseId = $request->filled('location_id')
            ? (int) $request->location_id
            : Location::where('location_type', 'warehouse')
                ->orderBy('location_id')
                ->value('location_id');
```

- [ ] **Step 4: Remove `createPr()` and its route**

Delete the entire `public function createPr(Request $request) { ... }` method from the controller (lines ~88-178). Remove the now-unused imports if they are used nowhere else in the file: `InsufficientBudgetException`, `PurchaseRequest`, `PurchaseRequestDetail`, `BudgetService`, `Str`, `RealtimeBroadcaster` (verify with a search before removing each — `BudgetService` is still used by `index()`, so keep it; `Product` is still used elsewhere? it is only used by createPr — verify and remove if unused).

In `routes/api.php`, remove line 551:

```php
        Route::post('/restock/create-pr', [RestockRecommendationController::class, 'createPr']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd back-end && php artisan test --filter=RestockEndpointsTest`
Expected: PASS.

- [ ] **Step 6: Run the whole restock suite**

Run: `cd back-end && php artisan test --filter=Restock`
Expected: PASS (Analytics + Endpoints + StockParameterSuggestion).

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/RestockRecommendationController.php back-end/routes/api.php back-end/tests/Feature/Restock/RestockEndpointsTest.php
git commit -m "feat(restock): location_id filter on recommendations; retire create-pr endpoint"
```

---

## Task 4: Frontend API client — drop createPR

**Files:**
- Modify: `frontend/src/admin/services/api.js:697-704` (the `restockAPI.createPR` method)

`getRecommendations(params)` already forwards query params (line 691-694), so no change is needed there — the frontend will call `restockAPI.getRecommendations({ location_id })`.

- [ ] **Step 1: Remove the `createPR` method**

Delete this block from the `restockAPI` object:

```javascript
  createPR: async (items, neededByDate = null) =>
    apiRequest('/restock/create-pr', {
      method: 'POST',
      body: JSON.stringify({
        items,
        ...(neededByDate ? { needed_by_date: neededByDate } : {}),
      }),
    }),
```

(Read the exact lines first with the Read tool to capture the precise closing syntax, then Edit them out. Leave the trailing comma of the preceding `recalculate` entry valid.)

- [ ] **Step 2: Verify nothing else imports createPR**

Run: `cd frontend && grep -rn "restockAPI.createPR\|\.createPR(" src/`
Expected: no matches (the only caller, `PORecommendation.jsx`, is deleted in Task 7).
If matches remain outside `PORecommendation.jsx`, stop and reconcile.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -m "refactor(restock): drop restockAPI.createPR client method"
```

---

## Task 5: Create Request — inline per-warehouse "Suggested" panel

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseRequestPage.jsx`
- Modify: `frontend/src/admin/styles/po_recommendation.css` (add panel styles)

This adds a per-block recommendation fetch (cached by location like `budgetsByLoc`) and a collapsible panel that adds prefilled rows to the block.

- [ ] **Step 1: Add the reason-label map and a per-location recommendation cache**

Near the top of `PurchaseRequestPage.jsx` (after the existing `fmtPeso`/helpers, before the component), add:

```javascript
const REASON_LABELS = {
  OUT_OF_STOCK:       'Out of stock',
  BELOW_SAFETY_STOCK: 'Below safety stock',
  STOCKOUT_RISK:      'Stockout risk',
  LOW_STOCK:          'Low stock',
  FAST_MOVING:        'Fast moving',
  HIGH_TURNOVER:      'High turnover',
  SLOW_MOVING:        'Slow moving',
  NO_MOVEMENT:        'No movement',
  DEMAND_SHIFT:       'Demand shift',
};

const RECO_PRIORITY_BADGE = {
  CRITICAL: 'ak-badge ak-badge--red',
  HIGH:     'ak-badge ak-badge--amber',
  MEDIUM:   'ak-badge ak-badge--blue',
};
```

Add `restockAPI` to the imports from `../services/api`:

```javascript
import {
  itemsAPI,
  locationsAPI,
  suppliersAPI,
  purchaseRequestsAPI,
  budgetAllocationsAPI,
  restockAPI,
} from '../services/api';
```

Inside the component, next to the `budgetsByLoc` state (around line 160), add a recommendations cache mirroring the budget one:

```javascript
  // Per-warehouse recommendation cache: { [locationId]: { loading, recs: [] } }
  const [recsByLoc, setRecsByLoc] = useState({});
  const fetchedRecLocRef = useRef(new Set());
  // Which blocks have the suggestions panel expanded (by block id).
  const [openRecPanels, setOpenRecPanels] = useState(new Set());
```

- [ ] **Step 2: Fetch recommendations per distinct warehouse (mirror the budget effect)**

After the existing `useEffect` that fetches budgets for `distinctBlockLocIds` (around line 264-293), add a sibling effect:

```javascript
  // Fetch (and cache) low-stock recommendations for each distinct warehouse.
  useEffect(() => {
    let ok = true;
    distinctBlockLocIds.forEach(locId => {
      if (fetchedRecLocRef.current.has(locId)) return;
      fetchedRecLocRef.current.add(locId);
      setRecsByLoc(prev => ({ ...prev, [locId]: { loading: true, recs: [] } }));
      restockAPI.getRecommendations({ location_id: locId })
        .then(res => {
          if (!ok) return;
          const recs = Array.isArray(res?.data?.recommendations) ? res.data.recommendations : [];
          setRecsByLoc(prev => ({ ...prev, [locId]: { loading: false, recs } }));
        })
        .catch(() => {
          if (!ok) return;
          setRecsByLoc(prev => ({ ...prev, [locId]: { loading: false, recs: [] } }));
        });
    });
    return () => { ok = false; };
  }, [distinctBlockLocIds]);
```

- [ ] **Step 3: Add the "add recommended item" handler**

Near `handleProductSelect` (around line 367), add a handler that appends a prefilled row to a block. It reuses the `products` lookup so the row matches what `handleProductSelect` would build, and runs the same open-lines reorder check:

```javascript
  const addRecommendedItem = useCallback(async (blockId, rec) => {
    const pid  = String(rec.product_id);
    const prod = products.find(p => p.id === pid);
    if (!prod) { toast.error('Product not available', 'Reload the product list and try again.'); return; }

    const qty     = Math.max(1, Number(rec.recommended_quantity) || 1);
    const newId   = `row-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
    const rowData = {
      id:                  newId,
      productId:           prod.id,
      productName:         prod.name,
      productType:         prod.type,
      unitId:              prod.unitId,
      unitName:            prod.unitName,
      brandName:           prod.brandName,
      capacityRating:      prod.capacityRating,
      variant:             prod.variant,
      piecesPerPackage:    prod.piecesPerPackage,
      packageUnit:         prod.packageUnit,
      qty,
      estPrice:            prod.costPrice,
      subtotal:            qty * prod.costPrice,
      reorderWarning:      null,
      warningAcknowledged: false,
    };

    setWarehouseBlocks(p => p.map(b => {
      if (b.id !== blockId) return b;
      // If the first row is still empty, fill it instead of appending.
      const firstEmpty = b.rows.length === 1 && !b.rows[0].productId;
      return { ...b, rows: firstEmpty ? [rowData] : [...b.rows, rowData] };
    }));

    // Non-blocking smart-reorder check, same as handleProductSelect.
    try {
      const res       = await purchaseRequestsAPI.getOpenLinesForProduct(pid);
      const openLines = Array.isArray(res?.data) ? res.data : [];
      if (openLines.length > 0) {
        setWarehouseBlocks(p => p.map(b => (b.id !== blockId ? b : {
          ...b,
          rows: b.rows.map(r => (r.id === newId ? { ...r, reorderWarning: openLines, warningAcknowledged: false } : r)),
        })));
      }
    } catch (_) { /* non-fatal */ }
  }, [products]);
```

- [ ] **Step 4: Render the panel inside each warehouse block**

In `renderCreateTab`, inside the `warehouseBlocks.map(...)` render, the block already conditionally renders the budget card when `block.warehouseId` is set (around line 771-775: `{block.warehouseId && (<div ...>{renderBudgetCard(group)}</div>)}`). Immediately after that block, add a suggestions panel. First compute, near the top of the map callback (where `group` and `blockSubtotal` are computed, ~line 733):

```javascript
        const recEntry = recsByLoc[String(block.warehouseId)] || { loading: false, recs: [] };
        const usedProductIds = new Set(block.rows.map(r => String(r.productId)).filter(Boolean));
        const suggestible = (recEntry.recs || []).filter(r => !usedProductIds.has(String(r.product_id)));
        const panelOpen = openRecPanels.has(block.id);
```

Then, right after the budget-card conditional, insert:

```javascript
            {block.warehouseId && (
              <div className="pr-reco-panel">
                <button
                  type="button"
                  className="pr-reco-toggle"
                  onClick={() => setOpenRecPanels(prev => {
                    const next = new Set(prev);
                    next.has(block.id) ? next.delete(block.id) : next.add(block.id);
                    return next;
                  })}
                  aria-expanded={panelOpen}
                >
                  <span className="pr-reco-toggle-caret">{panelOpen ? '▾' : '▸'}</span>
                  Suggested low-stock items for this warehouse
                  <span className="pr-reco-count">
                    {recEntry.loading ? '…' : suggestible.length}
                  </span>
                </button>

                {panelOpen && !recEntry.loading && suggestible.length === 0 && (
                  <div className="pr-reco-empty">No low-stock items for this warehouse right now.</div>
                )}

                {panelOpen && suggestible.length > 0 && (
                  <div className="pr-reco-list">
                    {suggestible.map(r => (
                      <div key={r.recommendation_id} className="pr-reco-item">
                        <span className={RECO_PRIORITY_BADGE[r.priority] || 'ak-badge ak-badge--gray'}>
                          {r.priority}
                        </span>
                        <span className="pr-reco-item-name">
                          {r.product?.product_name || `#${r.product_id}`}
                          <span className="pr-reco-item-code">{r.product?.product_code}</span>
                        </span>
                        <span className="pr-reco-item-stat">stock {r.current_stock}</span>
                        <span className="pr-reco-item-stat">order <strong>{r.recommended_quantity}</strong></span>
                        <span className="pr-reco-item-reasons">
                          {(r.reason_codes || []).map(c => (
                            <span key={c} className="ak-pill">{REASON_LABELS[c] || c}</span>
                          ))}
                        </span>
                        <button
                          type="button"
                          className="po-btn po-btn-add-product pr-reco-add"
                          onClick={() => addRecommendedItem(block.id, r)}
                        >
                          + Add
                        </button>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}
```

- [ ] **Step 5: Add panel styles**

Append to `frontend/src/admin/styles/po_recommendation.css`:

```css
/* Inline PR recommendation panel (Create Request) */
.pr-reco-panel { margin: 0 16px 12px; border: 1px solid #e2e8f0; border-radius: 8px; }
.pr-reco-toggle {
  display: flex; align-items: center; gap: 8px; width: 100%;
  background: #f8fafc; border: 0; padding: 10px 12px; cursor: pointer;
  font-size: 13px; font-weight: 600; color: #334155; border-radius: 8px;
}
.pr-reco-toggle-caret { color: #64748b; }
.pr-reco-count {
  margin-left: auto; background: #e0f2fe; color: #075985;
  border-radius: 999px; padding: 1px 9px; font-size: 12px; font-weight: 700;
}
.pr-reco-empty { padding: 10px 14px; font-size: 12px; color: #64748b; }
.pr-reco-list { display: flex; flex-direction: column; }
.pr-reco-item {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 12px; border-top: 1px solid #f1f5f9; font-size: 13px;
}
.pr-reco-item-name { display: flex; flex-direction: column; min-width: 160px; }
.pr-reco-item-code { font-size: 11px; color: #94a3b8; font-family: monospace; }
.pr-reco-item-stat { color: #475569; white-space: nowrap; }
.pr-reco-item-reasons { display: flex; flex-wrap: wrap; gap: 4px; margin-left: auto; }
.pr-reco-add { flex-shrink: 0; }
```

- [ ] **Step 6: Verify it builds and renders**

Run: `cd frontend && npx eslint src/admin/pages/PurchaseRequestPage.jsx`
Expected: no errors (warnings about existing code are acceptable).
Then manually: start the app (`cd frontend && npm start` or the prod `npm run demo`), open **Purchase Requests → Create Request**, pick a warehouse in a block, expand "Suggested low-stock items", confirm items load and "+ Add" inserts a prefilled row that updates the block subtotal and disappears from the panel.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/pages/PurchaseRequestPage.jsx frontend/src/admin/styles/po_recommendation.css
git commit -m "feat(pr): inline per-warehouse low-stock suggestions in Create Request"
```

---

## Task 6: Purchase Request — "Stock Parameters" tab (migrate suggestions tool)

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseRequestPage.jsx`

Move the apply/dismiss suggestions tool (currently the second tab of `PORecommendation.jsx`) into a new PR-page tab, gated by `po_recommendations.read`, with recalculate (`create`) and apply/dismiss (`update`).

- [ ] **Step 1: Add permission flags and tab state**

Near the existing permission flags (around line 145-148), add:

```javascript
  const canReadReco   = isAdmin || (typeof can === 'function' && can('po_recommendations', 'read'));
  const canRecalcReco = isAdmin || (typeof can === 'function' && can('po_recommendations', 'create'));
  const canApplyReco  = isAdmin || (typeof can === 'function' && can('po_recommendations', 'update'));
```

Add suggestions state near the other tab state:

```javascript
  const [suggestions,     setSuggestions]     = useState([]);
  const [loadingSug,      setLoadingSug]      = useState(false);
  const [recoRunAt,       setRecoRunAt]       = useState(null);
  const [recalculating,   setRecalculating]   = useState(false);
  const [busySuggestion,  setBusySuggestion]  = useState(null);
```

- [ ] **Step 2: Load suggestions when the tab is active**

In the `useEffect` that loads tab data (around line 296-311), add a branch:

```javascript
    if (activeTab === 'stock-parameters' && canReadReco) {
      setLoadingSug(true);
      Promise.all([
        restockAPI.getSuggestions('pending'),
        restockAPI.getRecommendations({}),
      ])
        .then(([sugRes, recRes]) => {
          setSuggestions(Array.isArray(sugRes?.data) ? sugRes.data : []);
          setRecoRunAt(recRes?.data?.run_at || null);
        })
        .catch(console.error)
        .finally(() => setLoadingSug(false));
    }
```

Add `canReadReco` to that effect's dependency array.

- [ ] **Step 3: Add the recalculate / apply / dismiss handlers**

Near the approve/reject handlers, add:

```javascript
  const handleRecalcReco = useCallback(async () => {
    setRecalculating(true);
    try {
      const res = await restockAPI.recalculate();
      setRecoRunAt(res?.data?.run_at || null);
      const sugRes = await restockAPI.getSuggestions('pending');
      setSuggestions(Array.isArray(sugRes?.data) ? sugRes.data : []);
      // Invalidate inline caches so Create Request reflects the fresh batch.
      fetchedRecLocRef.current = new Set();
      setRecsByLoc({});
      toast.success('Recommendations recalculated');
    } catch (err) {
      toast.error('Recalculation failed', err?.message);
    } finally {
      setRecalculating(false);
    }
  }, []);

  const handleApplySuggestion = useCallback(async (s) => {
    const ok = await confirmAction({
      title: `Apply new stock parameters to ${s.product?.product_name || 'this product'}?`,
      text:
        `Recommended ${s.current_recommended} → ${s.suggested_recommended}, ` +
        `Safety ${s.current_safety ?? 0} → ${s.suggested_safety ?? 0}, ` +
        `Max ${s.current_max ?? 0} → ${s.suggested_max ?? 0}`,
      confirmText: 'Apply',
      tone: 'primary',
    });
    if (!ok) return;
    setBusySuggestion(s.suggestion_id);
    try {
      await restockAPI.applySuggestion(s.suggestion_id);
      setSuggestions(prev => prev.filter(x => x.suggestion_id !== s.suggestion_id));
      toast.success('Stock parameters updated');
    } catch (err) {
      toast.error('Could not apply suggestion', err?.message);
    } finally {
      setBusySuggestion(null);
    }
  }, []);

  const handleDismissSuggestion = useCallback(async (s) => {
    setBusySuggestion(s.suggestion_id);
    try {
      await restockAPI.dismissSuggestion(s.suggestion_id);
      setSuggestions(prev => prev.filter(x => x.suggestion_id !== s.suggestion_id));
      toast.info('Suggestion dismissed');
    } catch (err) {
      toast.error('Could not dismiss suggestion', err?.message);
    } finally {
      setBusySuggestion(null);
    }
  }, []);
```

- [ ] **Step 4: Add the tab button and panel**

Find where the tab buttons are rendered (the tab bar with 'create' / 'my-requests' / 'approvals'). Add a "Stock Parameters" tab button, gated by `canReadReco`:

```javascript
        {canReadReco && (
          <button
            className={`... /* match sibling tab button classes */ ${activeTab === 'stock-parameters' ? 'is-active' : ''}`}
            onClick={() => setActiveTab('stock-parameters')}
          >
            Stock Parameters
          </button>
        )}
```

(Read the existing tab buttons first and copy their exact className/markup so the new button matches.)

Then add the tab body near the other `{activeTab === '...' && (...)}` blocks:

```javascript
        {activeTab === 'stock-parameters' && canReadReco && (
          <div className="ak-card">
            <div className="ak-toolbar">
              <div className="ak-muted" style={{ fontSize: 13 }}>
                {recoRunAt ? `Last computed: ${new Date(recoRunAt).toLocaleString()}` : 'No analytics run yet.'}
              </div>
              {canRecalcReco && (
                <div className="ak-toolbar--push">
                  <button className="ak-btn ak-btn--primary" onClick={handleRecalcReco} disabled={recalculating}>
                    {recalculating ? 'Recalculating…' : 'Recalculate now'}
                  </button>
                </div>
              )}
            </div>

            {loadingSug ? (
              <Loader label="Loading suggestions" />
            ) : suggestions.length === 0 ? (
              <div className="ak-state">
                <p>No pending stock parameter suggestions. The analytics run raises one when a
                product's demand drifts away from its configured levels.</p>
              </div>
            ) : (
              <div className="ak-table-wrap">
                <table className="ak-table">
                  <thead>
                    <tr>
                      <th>Product</th>
                      <th>Recommended stock</th>
                      <th>Safety stock</th>
                      <th>Max level</th>
                      <th className="ak-actions">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {suggestions.map((s) => (
                      <tr key={s.suggestion_id}>
                        <td>
                          <div>{s.product?.product_name || `#${s.product_id}`}</div>
                          <div className="ak-muted ak-mono">{s.product?.product_code}</div>
                        </td>
                        <td>{s.current_recommended} → <strong>{s.suggested_recommended}</strong></td>
                        <td>{s.current_safety ?? 0} → <strong>{s.suggested_safety ?? 0}</strong></td>
                        <td>{s.current_max ?? 0} → <strong>{s.suggested_max ?? 0}</strong></td>
                        <td className="ak-actions">
                          {canApplyReco ? (
                            <>
                              <button
                                className="ak-btn ak-btn--sm ak-btn--primary"
                                disabled={busySuggestion === s.suggestion_id}
                                onClick={() => handleApplySuggestion(s)}
                              >
                                Apply
                              </button>
                              <button
                                className="ak-btn ak-btn--sm ak-btn--ghost"
                                disabled={busySuggestion === s.suggestion_id}
                                onClick={() => handleDismissSuggestion(s)}
                              >
                                Dismiss
                              </button>
                            </>
                          ) : <span className="ak-muted">View only</span>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}
```

Confirm `'../styles/po_recommendation.css'` is imported in this file (it already is, line 24) so `ak-*`/`ak-pill` styles resolve.

- [ ] **Step 5: Verify**

Run: `cd frontend && npx eslint src/admin/pages/PurchaseRequestPage.jsx`
Expected: no errors.
Manually: as admin/IA open **Purchase Requests → Stock Parameters**, confirm the suggestions list, Recalculate, Apply, and Dismiss work; as a role with only `po_recommendations.read` (e.g. auditor), confirm the tab shows but Apply/Dismiss are hidden ("View only").

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/PurchaseRequestPage.jsx
git commit -m "feat(pr): Stock Parameters tab (apply/dismiss + recalculate) in PR page"
```

---

## Task 7: Delete standalone page, rewire nav, relabel to "PR Recommendation"

**Files:**
- Delete: `frontend/src/admin/pages/PORecommendation.jsx`
- Modify: `frontend/src/App.jsx` (lines 57, 161)
- Modify: `frontend/src/admin/components/Sidebar.jsx` (lines 28, 76)
- Modify: `frontend/src/admin/utils/navBundles.js` (line 13)
- Modify: `back-end/database/seeders/NavBundleSeeder.php` (line 33)
- Modify: `back-end/database/seeders/PermissionSeeder.php` (comment at line 117)
- Modify: `back-end/routes/api.php` (comment at line 542)

- [ ] **Step 1: Delete the standalone page**

Run: `git rm frontend/src/admin/pages/PORecommendation.jsx`

- [ ] **Step 2: Remove the route in `App.jsx`**

Delete the lazy import (line 57):

```javascript
const PORecommendation = lazy(() => import('./admin/pages/PORecommendation'));
```

and the route (line 161):

```javascript
          <Route path="/admin/po-recommendations" element={<RoleRoute><PORecommendation /></RoleRoute>} />
```

- [ ] **Step 3: Remove the Sidebar entries**

In `Sidebar.jsx`, delete the prefetch map line (28):

```javascript
  '/admin/po-recommendations': () => import('../pages/PORecommendation'),
```

and the menu item (76):

```javascript
      { name: 'PO Recommendation', path: '/admin/po-recommendations', icon: 'po' },
```

- [ ] **Step 4: Remove the frontend nav bundle**

In `navBundles.js`, delete line 13:

```javascript
  { name: 'PO Recommendation',     routePath: '/admin/po-recommendations', required: ['po_recommendations.read'] },
```

- [ ] **Step 5: Remove the backend nav bundle**

In `NavBundleSeeder.php`, delete the bundle row (line 33):

```php
            ['PO Recommendation',    '/admin/po-recommendations',   'PROCUREMENT & PLANNING',  'po',                 'po_recommendations',      'read'],
```

- [ ] **Step 6: Relabel comments (keep the permission KEY `po_recommendations`)**

- `routes/api.php` line 542: change comment `| PO Recommendation analytics (restock engine)` → `| PR Recommendation analytics (restock engine)`.
- `PermissionSeeder.php` line 117: change comment `// PO Recommendation analytics (page is at /admin/po-recommendations).` → `// PR Recommendation analytics (inline in Purchase Requests; Stock Parameters tab).` Leave the `'po_recommendations' => [...]` key and role arrays unchanged.

- [ ] **Step 7: Re-seed nav bundles on the dev DB**

Run: `cd back-end && php artisan db:seed --class=NavBundleSeeder`
Expected: seeder runs clean. (This upserts the current bundle set; the removed row simply won't be re-added. If the seeder only inserts and does not prune, manually remove the stale DB row: `php artisan tinker --execute="App\Models\NavPermissionBundle::where('route_path','/admin/po-recommendations')->delete();"`)

- [ ] **Step 8: Run the nav-bundle test**

Run: `cd back-end && php artisan test --filter=NavBundleTest`
Expected: PASS. `NavBundleTest` derives expected routes from `NavBundleSeeder::bundles()` (dynamic), so removing the row keeps it consistent. (A pre-existing count drift unrelated to this change may already be red per prior notes — confirm the failure, if any, is not about `po-recommendations`.)

- [ ] **Step 9: Verify no dangling references**

Run: `cd frontend && grep -rn "PORecommendation\|po-recommendations" src/`
Expected: no matches.
Run: `cd back-end && grep -rn "po-recommendations\|create-pr" app/ routes/ database/seeders/`
Expected: no matches (the permission key `po_recommendations` — with underscore — legitimately remains).

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "refactor(pr): delete standalone PO Recommendation page; relabel to PR Recommendation"
```

---

## Task 8: Full verification & data refresh

- [ ] **Step 1: Backend suite**

Run: `cd back-end && php artisan test --filter=Restock && php artisan test --filter=NavBundleTest`
Expected: all restock + nav tests green.

- [ ] **Step 2: Repopulate recommendations in the new shape**

Run: `cd back-end && php artisan analytics:restock`
Expected: command completes; `restock_recommendations` now holds per-warehouse rows. Spot check: `php artisan tinker --execute="dump(DB::table('restock_recommendations')->select('product_id','location_id')->limit(5)->get());"` shows varied `location_id`s.

- [ ] **Step 3: Frontend lint + manual smoke**

Run: `cd frontend && npx eslint src/admin/pages/PurchaseRequestPage.jsx src/admin/utils/navBundles.js`
Expected: no errors.
Manual: Create Request inline panel (Task 5 step 6) + Stock Parameters tab (Task 6 step 5) + confirm the old "PO Recommendation" sidebar item is gone.

- [ ] **Step 4: Re-cache config/routes for demo (optional)**

Run: `cd back-end && php artisan config:cache && php artisan route:cache`
(Only before a client demo; keep uncached while developing.)

- [ ] **Step 5: Final commit if any fixups were needed**

```bash
git add -A
git commit -m "chore(pr): verification fixups for PR Recommendation inline"
```

---

## Self-review notes (coverage map)

- Spec §1 Naming → Task 7 steps 6 (comments/labels); nav item removed (steps 3-5). Internal key kept.
- Spec §2 Location-aware engine → Task 1 (index) + Task 2 (stock/on-order per warehouse, shared demand, per-row location_id, persist).
- Spec §3 API (location filter, retire create-pr) → Task 3 + Task 4.
- Spec §4 Inline per-block panel → Task 5.
- Spec §5 Stock Parameters tab + delete standalone/nav → Task 6 + Task 7.
- Spec §6 Permissions → Task 6 (read gates tab, create gates recalc, update gates apply/dismiss) — matches the real matrix (spec text said "create" for apply/dismiss; the code and this plan use `update`).
- Spec §7 Tests → Tasks 2, 3 (backend) + manual smoke (frontend).
- Spec open items resolved: `GET` without `location_id` returns all rows (Task 3, optional filter); inline panel starts **collapsed** with a count badge (Task 5).
