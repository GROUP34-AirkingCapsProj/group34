# Analytics Dashboard Real-Data Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Analytics Dashboard's broken client-side computation with a server-side `DashboardAnalyticsService` behind six permission-gated endpoints, and seed the empty dev database so every tab shows real, verifiable data — without changing a single line of UI markup.

**Architecture:** One service class with five read methods plus a filters method, each returning arrays shaped exactly like the page's existing `tabData` sub-objects. A thin controller exposes them under `/api/analytics/*`, gated `permission:analytics_dashboard,read`. The React page drops ~350 lines of computation and becomes a renderer. A dev-only idempotent seeder generates coherent operational history so the result is visually verifiable.

**Tech Stack:** Laravel 11 (PHP 8.2), MariaDB (MySQL driver), PHPUnit with `DatabaseTransactions` against `airkingV5_test`, React 18 + Chart.js 4 (`react-chartjs-2`).

**Spec:** `docs/superpowers/specs/2026-08-02-analytics-dashboard-backend-design.md`

---

## Critical Context For The Implementer

You have not worked in this codebase. Read this before Task 1.

**The schema is a SQL dump, not migrations.** Running `php artisan migrate` blindly will fail. You are not adding any tables in this plan — every table already exists. Do not write migrations.

**Tests run against a separate database, `airkingV5_test`,** configured in `phpunit.xml`. Tests use `Illuminate\Foundation\Testing\DatabaseTransactions` (NOT `RefreshDatabase`, which would wipe the dump). Every test rolls back automatically.

**Tests build fixtures with raw `DB::table()->insertGetId()`,** not factories. There is only one factory (`UserFactory`) in the whole project. Follow the existing pattern in `back-end/tests/Feature/Restock/RestockAnalyticsServiceTest.php`.

**Run tests from the `back-end` directory:**
```bash
cd back-end && php artisan test --filter=SomeTest
```

**Key schema facts you will need constantly:**

| Table | Primary key | Notes |
|---|---|---|
| `inventory` | `inventory_id` | Has `location_id`, NOT `branch_id`. `reorder_level` is `NOT NULL DEFAULT 0`. |
| `products` | `product_id` | Has real `recommended_stocks`, `safety_stock`, `max_stock_level` (all nullable except recommended). |
| `locations` | `location_id` | `location_type` is one of `warehouse`, `showroom`, `branch_office`. |
| `incident_reports` | `incident_id` | `adjustment_id` nullable — this nullability IS the loss rule. |
| `adjustment_details` | `adjustment_detail_id` | `deduct_quantity` is the real write-off. |
| `installation_material_details` | `consumable_detail_id` | Holds `pieces_per_package`. Only for `product_type = 'installation_material'`. |
| `restock_recommendations` | id | Populated by `analytics:restock`; warehouse locations only. |

**Incident status IDs** (from `status_lookup` where `status_category = 'incident'`): Open = **46**, Resolved = **47**, Dismissed = **48**. Never hardcode these in application code — use `IncidentReport::statusId('Open')`. You MAY hardcode them in test fixtures.

**Adjustment "Approved" status ID is 20** (`status_category = 'adjustment'`).

**Per-piece valuation.** `products.unit_price` and `cost_price` are per *package* for installation materials. The per-piece price is `price / GREATEST(COALESCE(imd.pieces_per_package, 1), 1)`. Appliances have no row in `installation_material_details`, so the `LEFT JOIN` yields `NULL` and the divisor is 1. Getting this wrong values a box of 100 screws as if each screw cost the price of the box.

**Do not modify** `RestockAnalyticsService`'s logic. Task 1 changes one method's visibility and nothing else.

---

## File Structure

**Create:**
- `back-end/app/Services/Analytics/DashboardAnalyticsService.php` — all five tab computations plus filters. One responsibility: turn database state into the dashboard's display shapes.
- `back-end/app/Http/Controllers/Api/AnalyticsDashboardController.php` — six thin actions, no logic.
- `back-end/database/seeders/AnalyticsDemoDataSeeder.php` — dev-only operational data.
- `back-end/tests/Feature/Analytics/StockLevelAnalyticsTest.php`
- `back-end/tests/Feature/Analytics/MovementAnalyticsTest.php`
- `back-end/tests/Feature/Analytics/PredictionsAnalyticsTest.php`
- `back-end/tests/Feature/Analytics/ProfitLossAnalyticsTest.php`
- `back-end/tests/Feature/Analytics/LocationPerformanceAnalyticsTest.php`
- `back-end/tests/Feature/Analytics/AnalyticsEndpointsTest.php`

**Modify:**
- `back-end/app/Services/Analytics/RestockAnalyticsService.php:311` — one keyword.
- `back-end/routes/api.php` — add import + route group.
- `frontend/src/admin/services/api.js` — add `analyticsAPI`.
- `frontend/src/admin/pages/AnalyticsDashboard.jsx` — delete computation, keep all markup.

**Test files are split one-per-tab** because each tab's fixture setup differs substantially, and a single 1,200-line test file would be unreadable and slow to iterate on.

---

## Task 1: Expose the demand series

The Movement tab must use the same demand definition as the restock engine, or the two tabs will contradict each other. `dailyDemandByLocation()` is currently `private`.

**Files:**
- Modify: `back-end/app/Services/Analytics/RestockAnalyticsService.php:311`
- Test: `back-end/tests/Feature/Analytics/MovementAnalyticsTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Analytics/MovementAnalyticsTest.php`:

```php
<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\RestockAnalyticsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MovementAnalyticsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_daily_demand_by_location_is_publicly_callable(): void
    {
        $method = new \ReflectionMethod(RestockAnalyticsService::class, 'dailyDemandByLocation');

        $this->assertTrue(
            $method->isPublic(),
            'dailyDemandByLocation must be public so DashboardAnalyticsService can reuse one demand definition.'
        );
    }

    public function test_daily_demand_includes_sales_quantities(): void
    {
        $locationId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Test Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');

        $productId = (int) DB::table('products')->insertGetId([
            'product_code' => 'TST-' . uniqid(),
            'product_name' => 'Demand Probe',
            'product_type' => 'appliance',
            'unit_price'   => 100,
            'cost_price'   => 80,
            'status_id'    => 3,
            'created_at'   => now(),
            'updated_at'   => now(),
        ], 'product_id');

        $salesId = (int) DB::table('sales')->insertGetId([
            'location_id' => $locationId,
            'sale_date'   => '2026-07-10',
            'order_date'  => '2026-07-10',
            'created_at'  => now(),
            'updated_at'  => now(),
        ], 'sales_id');

        DB::table('sales_details')->insert([
            'sales_id'   => $salesId,
            'product_id' => $productId,
            'quantity'   => 7,
            'unit_price' => 100,
            'subtotal'   => 700,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $series = app(RestockAnalyticsService::class)
            ->dailyDemandByLocation('2026-07-01', '2026-07-31');

        $this->assertSame(7.0, $series[$locationId][$productId]['2026-07-10']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=MovementAnalyticsTest
```

Expected: FAIL. The first test fails asserting `isPublic()`; the second fails with `ReflectionException` or a call-to-private-method error.

- [ ] **Step 3: Change the visibility**

In `back-end/app/Services/Analytics/RestockAnalyticsService.php`, line 311, change:

```php
    private function dailyDemandByLocation(string $from, string $to): array
```

to:

```php
    /**
     * Daily demand per location per product: [locationId][productId][Y-m-d] => qty.
     *
     * Public because DashboardAnalyticsService reuses it for the Movement tab —
     * the dashboard and the restock engine must agree on what "demand" means, so
     * there is exactly one definition and one query. Pure read, no side effects.
     */
    public function dailyDemandByLocation(string $from, string $to): array
```

Change nothing else in this file. The method body stays exactly as it is.

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=MovementAnalyticsTest
```

Expected: PASS, 2 tests.

- [ ] **Step 5: Verify the restock engine still passes**

```bash
cd back-end && php artisan test --filter=Restock
```

Expected: PASS. Widening visibility cannot break callers, but this suite is the safety net for the procurement engine and must stay green.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Services/Analytics/RestockAnalyticsService.php back-end/tests/Feature/Analytics/MovementAnalyticsTest.php
git commit -m "refactor(analytics): expose dailyDemandByLocation for dashboard reuse"
```

---

## Task 2: Service skeleton and shared query helpers

Every tab filters inventory the same way and values stock the same way. Build that once.

**Files:**
- Create: `back-end/app/Services/Analytics/DashboardAnalyticsService.php`
- Test: `back-end/tests/Feature/Analytics/StockLevelAnalyticsTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Analytics/StockLevelAnalyticsTest.php`:

```php
<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\DashboardAnalyticsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockLevelAnalyticsTest extends TestCase
{
    use DatabaseTransactions;

    private DashboardAnalyticsService $service;
    private int $locationId;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DashboardAnalyticsService::class);

        $this->locationId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Analytics Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');

        $this->categoryId = (int) DB::table('category_lookup')->insertGetId([
            'category_name' => 'AnalyticsCat-' . uniqid(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'category_id');
    }

    /**
     * @param  array<string,mixed>  $product   overrides for the products row
     * @param  array<string,mixed>  $inventory overrides for the inventory row
     */
    private function stock(array $product, array $inventory): int
    {
        $productId = (int) DB::table('products')->insertGetId(array_merge([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Analytics Product',
            'product_type'       => 'appliance',
            'category_id'        => $this->categoryId,
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 10,
            'safety_stock'       => 5,
            'max_stock_level'    => 100,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], $product), 'product_id');

        DB::table('inventory')->insert(array_merge([
            'location_id'        => $this->locationId,
            'product_id'         => $productId,
            'quantity_on_hand'   => 50,
            'available_quantity' => 50,
            'reorder_level'      => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], $inventory));

        return $productId;
    }

    public function test_classifies_each_stock_bucket(): void
    {
        $this->stock([], ['quantity_on_hand' => 0,   'available_quantity' => 0]);    // critical
        $this->stock([], ['quantity_on_hand' => 8,   'available_quantity' => 8]);    // low (<= recommended 10)
        $this->stock([], ['quantity_on_hand' => 50,  'available_quantity' => 50]);   // optimal
        $this->stock([], ['quantity_on_hand' => 150, 'available_quantity' => 150]);  // overstock (> max 100)

        $result = $this->service->stockLevel($this->locationId, null);

        $this->assertSame(1, $result['stockDistribution']['critical']['count']);
        $this->assertSame(1, $result['stockDistribution']['lowStock']['count']);
        $this->assertSame(1, $result['stockDistribution']['optimal']['count']);
        $this->assertSame(1, $result['stockDistribution']['overstock']['count']);
        $this->assertSame(25, $result['stockDistribution']['critical']['percentage']);
    }

    public function test_location_reorder_level_overrides_product_recommended_stocks(): void
    {
        // Product says low at <=10, but this location says low at <=40.
        $this->stock(['recommended_stocks' => 10], [
            'quantity_on_hand'   => 30,
            'available_quantity' => 30,
            'reorder_level'      => 40,
        ]);

        $result = $this->service->stockLevel($this->locationId, null);

        $this->assertSame(1, $result['stockDistribution']['lowStock']['count']);
        $this->assertSame(0, $result['stockDistribution']['optimal']['count']);
    }

    public function test_null_max_stock_level_is_never_overstock(): void
    {
        $this->stock(['max_stock_level' => null], [
            'quantity_on_hand'   => 99999,
            'available_quantity' => 99999,
        ]);

        $result = $this->service->stockLevel($this->locationId, null);

        $this->assertSame(0, $result['stockDistribution']['overstock']['count']);
        $this->assertSame(1, $result['stockDistribution']['optimal']['count']);
    }

    public function test_installation_material_is_valued_per_piece(): void
    {
        // A box of 50 costing 100 per box is 2.00 per piece. 10 pieces on hand = 20.00.
        $productId = $this->stock(
            ['product_type' => 'installation_material', 'unit_price' => 100],
            ['quantity_on_hand' => 10, 'available_quantity' => 10]
        );

        DB::table('installation_material_details')->insert([
            'product_id'         => $productId,
            'pieces_per_package' => 50,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $result = $this->service->stockLevel($this->locationId, null);

        $this->assertEqualsWithDelta(20.0, $result['statsData']['totalStockValue'], 0.01);
    }

    public function test_category_filter_narrows_results(): void
    {
        $otherCategoryId = (int) DB::table('category_lookup')->insertGetId([
            'category_name' => 'OtherCat-' . uniqid(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'category_id');

        $this->stock([], ['quantity_on_hand' => 10, 'available_quantity' => 10]);
        $this->stock(['category_id' => $otherCategoryId], ['quantity_on_hand' => 10, 'available_quantity' => 10]);

        $all      = $this->service->stockLevel($this->locationId, null);
        $filtered = $this->service->stockLevel($this->locationId, $this->categoryId);

        $this->assertSame(20, $all['statsData']['totalUnits']);
        $this->assertSame(10, $filtered['statsData']['totalUnits']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=StockLevelAnalyticsTest
```

Expected: FAIL with `Target class [App\Services\Analytics\DashboardAnalyticsService] does not exist.`

- [ ] **Step 3: Create the service with shared helpers and `stockLevel()`**

Create `back-end/app/Services/Analytics/DashboardAnalyticsService.php`:

```php
<?php

namespace App\Services\Analytics;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only analytics for the Analytics Dashboard page.
 *
 * Each public method returns arrays shaped exactly like the page's existing
 * tabData sub-objects, so the React page binds to them without transformation.
 * Aggregation happens in SQL, which is why this replaced the old client-side
 * computation: the browser was working from a 500-row page of inventory and
 * silently under-reporting every org-wide total.
 */
class DashboardAnalyticsService
{
    public function __construct(private RestockAnalyticsService $restock)
    {
    }

    /**
     * Per-piece price expression. unit_price/cost_price are per PACKAGE for
     * installation materials; appliances have no details row so the LEFT JOIN
     * yields NULL and the divisor falls back to 1.
     */
    private const PIECE_PRICE = 'p.unit_price / GREATEST(COALESCE(imd.pieces_per_package, 1), 1)';
    private const PIECE_COST  = 'p.cost_price / GREATEST(COALESCE(imd.pieces_per_package, 1), 1)';

    /**
     * The reorder point for a row: the location's own tuning when set, else the
     * product default. inventory.reorder_level is NOT NULL DEFAULT 0, so 0 means
     * "not configured here" and must fall through — hence NULLIF, not COALESCE
     * alone.
     */
    private const REORDER_POINT = 'COALESCE(NULLIF(i.reorder_level, 0), p.recommended_stocks, 0)';

    private function inventoryQuery(?int $locationId, ?int $categoryId): Builder
    {
        return DB::table('inventory as i')
            ->join('products as p', 'p.product_id', '=', 'i.product_id')
            ->join('locations as l', 'l.location_id', '=', 'i.location_id')
            ->leftJoin('installation_material_details as imd', 'imd.product_id', '=', 'p.product_id')
            ->leftJoin('category_lookup as c', 'c.category_id', '=', 'p.category_id')
            ->when($locationId, fn (Builder $q) => $q->where('i.location_id', $locationId))
            ->when($categoryId, fn (Builder $q) => $q->where('p.category_id', $categoryId));
    }

    /** Bucket expression shared by the Stock Level and Location Performance tabs. */
    private function bucketExpression(): string
    {
        $reorder = self::REORDER_POINT;

        return "CASE
            WHEN i.quantity_on_hand <= 0 THEN 'critical'
            WHEN i.quantity_on_hand <= {$reorder} THEN 'low'
            WHEN p.max_stock_level IS NOT NULL AND i.quantity_on_hand > p.max_stock_level THEN 'overstock'
            ELSE 'optimal'
        END";
    }

    public function stockLevel(?int $locationId, ?int $categoryId): array
    {
        $bucket = $this->bucketExpression();
        $price  = self::PIECE_PRICE;

        $rows = $this->inventoryQuery($locationId, $categoryId)
            ->selectRaw("{$bucket} AS bucket, COUNT(*) AS row_count,
                         SUM(i.quantity_on_hand) AS units,
                         SUM(i.quantity_on_hand * ({$price})) AS value")
            ->groupByRaw($bucket)
            ->get()
            ->keyBy('bucket');

        $count = fn (string $b) => (int) ($rows[$b]->row_count ?? 0);

        $critical  = $count('critical');
        $low       = $count('low');
        $optimal   = $count('optimal');
        $overstock = $count('overstock');
        $total     = $critical + $low + $optimal + $overstock;

        $totalUnits = (int) $rows->sum('units');
        $totalValue = (float) $rows->sum('value');

        $pct = static fn (int $n) => $total > 0 ? (int) round(($n / $total) * 100) : 0;

        $belowReorder = $critical + $low;
        $aboveSafety  = $optimal + $overstock;

        return [
            'statsData' => [
                'totalStockValue' => round($totalValue, 2),
                'totalUnits'      => $totalUnits,
                'lowStockItems'   => $low,
                'criticalItems'   => $critical,
            ],
            'stockDistribution' => [
                'optimal'   => ['count' => $optimal,   'percentage' => $pct($optimal)],
                'lowStock'  => ['count' => $low,       'percentage' => $pct($low)],
                'critical'  => ['count' => $critical,  'percentage' => $pct($critical)],
                'overstock' => ['count' => $overstock, 'percentage' => $pct($overstock)],
            ],
            'reorderCompliance' => [
                'rate'               => $total > 0 ? (int) round((($total - $belowReorder) / $total) * 100) : 0,
                'belowReorderPoint'  => $belowReorder,
                'aboveSafetyStock'   => $aboveSafety,
            ],
            'criticalItems'  => $this->attentionItems($locationId, $categoryId),
            'overstockItems' => $this->overstockItems($locationId, $categoryId),
        ];
    }

    /** Critical + low rows for the "needs attention" list, worst first. */
    private function attentionItems(?int $locationId, ?int $categoryId): array
    {
        $reorder = self::REORDER_POINT;

        return $this->inventoryQuery($locationId, $categoryId)
            ->whereRaw("i.quantity_on_hand <= {$reorder}")
            ->selectRaw("p.product_name, l.location_name,
                         i.quantity_on_hand AS current_qty,
                         {$reorder} AS reorder_point")
            ->orderBy('i.quantity_on_hand')
            ->limit(6)
            ->get()
            ->map(static fn ($r) => [
                'name'     => $r->product_name,
                'location' => $r->location_name ?? 'N/A',
                'current'  => (int) $r->current_qty,
                'max'      => (int) $r->reorder_point,
            ])
            ->all();
    }

    private function overstockItems(?int $locationId, ?int $categoryId): array
    {
        return $this->inventoryQuery($locationId, $categoryId)
            ->whereNotNull('p.max_stock_level')
            ->whereRaw('i.quantity_on_hand > p.max_stock_level')
            ->selectRaw('p.product_name, l.location_name,
                         i.quantity_on_hand AS current_qty,
                         p.max_stock_level')
            ->orderByDesc('i.quantity_on_hand')
            ->limit(6)
            ->get()
            ->map(static fn ($r) => [
                'name'     => $r->product_name,
                'location' => $r->location_name ?? 'N/A',
                'current'  => (int) $r->current_qty,
                'optimal'  => (int) $r->max_stock_level,
            ])
            ->all();
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
cd back-end && php artisan test --filter=StockLevelAnalyticsTest
```

Expected: PASS, 5 tests.

If `test_installation_material_is_valued_per_piece` fails with a value of 1000 instead of 20, the `PIECE_PRICE` divisor is not being applied — check that the `LEFT JOIN` on `installation_material_details` is present in `inventoryQuery()`.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/Analytics/DashboardAnalyticsService.php back-end/tests/Feature/Analytics/StockLevelAnalyticsTest.php
git commit -m "feat(analytics): add DashboardAnalyticsService with stock level tab"
```

---

## Task 3: Movement and turnover

**Files:**
- Modify: `back-end/app/Services/Analytics/DashboardAnalyticsService.php`
- Test: `back-end/tests/Feature/Analytics/MovementAnalyticsTest.php` (already exists from Task 1)

- [ ] **Step 1: Write the failing test**

Append these methods inside the existing `MovementAnalyticsTest` class, before the closing brace:

```php
    public function test_consumption_comes_from_real_sales_not_bin_emptiness(): void
    {
        $locationId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Movement Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');

        $productId = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Mover',
            'product_type'       => 'appliance',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 10,
            'max_stock_level'    => 100,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');

        DB::table('inventory')->insert([
            'location_id'        => $locationId,
            'product_id'         => $productId,
            'quantity_on_hand'   => 20,
            'available_quantity' => 20,
            'reorder_level'      => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // 40 units sold across the trailing 30 days.
        $salesId = (int) DB::table('sales')->insertGetId([
            'location_id' => $locationId,
            'sale_date'   => now()->subDays(5)->toDateString(),
            'order_date'  => now()->subDays(5)->toDateString(),
            'created_at'  => now()->subDays(5),
            'updated_at'  => now()->subDays(5),
        ], 'sales_id');

        DB::table('sales_details')->insert([
            'sales_id'   => $salesId,
            'product_id' => $productId,
            'quantity'   => 40,
            'unit_price' => 100,
            'subtotal'   => 4000,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $result = app(\App\Services\Analytics\DashboardAnalyticsService::class)
            ->movement($locationId, null);

        $row = collect($result['consumptionData'])->firstWhere('name', 'Mover');

        $this->assertNotNull($row, 'The moved product must appear in consumption data.');
        // 40 consumed / 20 on hand = 2x turnover. The OLD code would have said
        // (100-20)/100 = 80% purely from bin emptiness, with no sales involved.
        $this->assertSame('2x/mo', $row['turnover']);
        $this->assertSame(20, $row['currentStock']);
    }

    public function test_product_with_no_movement_reports_zero_turnover(): void
    {
        $locationId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Idle Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');

        $productId = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Dead Stock',
            'product_type'       => 'appliance',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 10,
            'max_stock_level'    => 100,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');

        DB::table('inventory')->insert([
            'location_id'        => $locationId,
            'product_id'         => $productId,
            'quantity_on_hand'   => 5,
            'available_quantity' => 5,
            'reorder_level'      => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $result = app(\App\Services\Analytics\DashboardAnalyticsService::class)
            ->movement($locationId, null);

        $this->assertSame(0, $result['movementStats']['avgTurnoverRate']);
        $this->assertSame([0, 0, 0, 0], $result['weeklyMovement']);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=MovementAnalyticsTest
```

Expected: FAIL with `Call to undefined method ...DashboardAnalyticsService::movement()`.

- [ ] **Step 3: Implement `movement()`**

Add these methods to `DashboardAnalyticsService`:

```php
    /** Trailing window, in days, for consumption and turnover. */
    private const MOVEMENT_WINDOW_DAYS = 30;

    public function movement(?int $locationId, ?int $categoryId): array
    {
        $to   = now()->toDateString();
        $from = now()->subDays(self::MOVEMENT_WINDOW_DAYS - 1)->toDateString();

        // One demand definition, shared with the restock engine, so the Movement
        // and Predictions tabs can never tell different stories.
        $demand = $this->restock->dailyDemandByLocation($from, $to);

        // Collapse to [productId => units] for the locations in scope.
        $consumed = [];
        foreach ($demand as $locId => $products) {
            if ($locationId !== null && (int) $locId !== $locationId) {
                continue;
            }
            foreach ($products as $productId => $series) {
                $consumed[(int) $productId] = ($consumed[(int) $productId] ?? 0) + array_sum($series);
            }
        }

        $stockRows = $this->inventoryQuery($locationId, $categoryId)
            ->selectRaw('i.product_id,
                         p.product_name,
                         COALESCE(c.category_name, \'Uncategorized\') AS category_name,
                         SUM(i.quantity_on_hand) AS on_hand')
            ->groupBy('i.product_id', 'p.product_name', 'c.category_name')
            ->get();

        $items = $stockRows->map(function ($r) use ($consumed) {
            $productId = (int) $r->product_id;
            $onHand    = (int) $r->on_hand;
            $units     = (float) ($consumed[$productId] ?? 0);

            return [
                'productId'   => $productId,
                'name'        => $r->product_name,
                'category'    => $r->category_name,
                'onHand'      => $onHand,
                'unitsOut'    => $units,
                // Turnover: units consumed per unit of stock held. With no stock
                // but real consumption the ratio is undefined, so fall back to the
                // raw consumed count rather than dividing by zero.
                'turnover'    => $onHand > 0 ? round($units / $onHand, 1) : $units,
            ];
        })->values();

        // The UI renders consumption on a percentage bar, so scale against the
        // busiest product in scope. An absolute unit count has no natural ceiling.
        $peak = (float) max(1, $items->max('unitsOut') ?? 1);

        $sorted    = $items->sortByDesc('turnover')->values();
        $fast      = $sorted->where('turnover', '>=', 6)->take(5)->values();
        $slow      = $sorted->where('turnover', '>', 0)->where('turnover', '<=', 3)->take(4)->values();
        $moved     = $items->where('unitsOut', '>', 0);
        $avgTurn   = $moved->count() > 0 ? (int) round($moved->avg('turnover')) : 0;

        $optimalCount = (int) $this->inventoryQuery($locationId, $categoryId)
            ->whereRaw($this->bucketExpression() . " = 'optimal'")
            ->count();

        return [
            'movementStats' => [
                'avgTurnoverRate'  => $avgTurn,
                'fastMovingCount'  => $fast->count(),
                'slowMovingCount'  => $slow->count(),
                'optimalStockCount'=> $optimalCount,
            ],
            'fastMovingItems' => $fast->map(static fn ($i) => [
                'name'     => $i['name'],
                'category' => $i['category'],
                'rate'     => (int) round($i['turnover']),
            ])->all(),
            'slowMovingItems' => $slow->map(static fn ($i) => [
                'name'     => $i['name'],
                'category' => $i['category'],
                'rate'     => (int) round($i['turnover']),
            ])->all(),
            'consumptionData' => $items->sortByDesc('unitsOut')->take(10)->values()
                ->map(static function ($i) use ($peak) {
                    $status = 'In Stock';
                    if ($i['onHand'] <= 0) {
                        $status = 'Out of Stock';
                    } elseif ($i['turnover'] >= 6) {
                        $status = 'Low Stock';
                    }

                    return [
                        'name'            => $i['name'],
                        'currentStock'    => $i['onHand'],
                        'consumptionRate' => (int) round(($i['unitsOut'] / $peak) * 100),
                        'turnover'        => ((int) round($i['turnover'])) . 'x/mo',
                        'status'          => $status,
                    ];
                })->all(),
            'weeklyMovement' => $this->weeklyBuckets($demand, $locationId),
        ];
    }

    /**
     * Trailing four weeks of demand, oldest first, matching the chart's
     * "Week 1..Week 4" labels.
     *
     * @param  array<int,array<int,array<string,float>>>  $demand
     * @return array<int,int>
     */
    private function weeklyBuckets(array $demand, ?int $locationId): array
    {
        $buckets = [0, 0, 0, 0];
        $today   = now()->startOfDay();

        foreach ($demand as $locId => $products) {
            if ($locationId !== null && (int) $locId !== $locationId) {
                continue;
            }
            foreach ($products as $series) {
                foreach ($series as $day => $qty) {
                    $daysAgo = $today->diffInDays(\Carbon\Carbon::parse($day)->startOfDay());
                    $index   = 3 - intdiv($daysAgo, 7);
                    if ($index >= 0 && $index <= 3) {
                        $buckets[$index] += (int) $qty;
                    }
                }
            }
        }

        return $buckets;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
cd back-end && php artisan test --filter=MovementAnalyticsTest
```

Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/Analytics/DashboardAnalyticsService.php back-end/tests/Feature/Analytics/MovementAnalyticsTest.php
git commit -m "feat(analytics): compute movement tab from real demand series"
```

---

## Task 4: Predictions and restocking

Reads persisted `restock_recommendations`, falling back to a live `analyze()` when stale — otherwise the tab is blank on any machine without the daily scheduler.

**Files:**
- Modify: `back-end/app/Services/Analytics/DashboardAnalyticsService.php`
- Test: `back-end/tests/Feature/Analytics/PredictionsAnalyticsTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Analytics/PredictionsAnalyticsTest.php`:

```php
<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\DashboardAnalyticsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PredictionsAnalyticsTest extends TestCase
{
    use DatabaseTransactions;

    private DashboardAnalyticsService $service;
    private int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DashboardAnalyticsService::class);

        $this->warehouseId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Prediction Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');
    }

    private function recommendation(array $overrides = []): int
    {
        $productId = (int) DB::table('products')->insertGetId([
            'product_code'    => 'TST-' . uniqid(),
            'product_name'    => 'Predicted Product',
            'product_type'    => 'appliance',
            'unit_price'      => 100,
            'cost_price'      => 80,
            'safety_stock'    => 5,
            'max_stock_level' => 100,
            'status_id'       => 3,
            'created_at'      => now(),
            'updated_at'      => now(),
        ], 'product_id');

        DB::table('restock_recommendations')->insert(array_merge([
            'product_id'           => $productId,
            'location_id'          => $this->warehouseId,
            'current_stock'        => 4,
            'reorder_level'        => 20,
            'recommended_quantity' => 50,
            'priority'             => 'critical',
            'avg_daily_demand'     => 2.0,
            'days_of_cover'        => 2.0,
            'lead_time_days'       => 7.0,
            'on_order_quantity'    => 0,
            'run_at'               => now(),
            'created_at'           => now(),
            'updated_at'           => now(),
        ], $overrides));

        return $productId;
    }

    public function test_reads_persisted_recommendations(): void
    {
        $this->recommendation();

        $result = $this->service->predictions($this->warehouseId, null);

        $this->assertSame(1, $result['predictionsStats']['restockNeeded']);
        $this->assertSame(1, $result['predictionsStats']['criticalPriority']);
        $this->assertSame(1, $result['predictionsStats']['predictedStockouts']);
        // 50 suggested x 80.00 cost = 4000.00
        $this->assertEqualsWithDelta(4000.0, $result['predictionsStats']['estRestockCost'], 0.01);
    }

    public function test_priority_summary_groups_units_and_cost(): void
    {
        $this->recommendation(['priority' => 'critical', 'recommended_quantity' => 10]);
        $this->recommendation(['priority' => 'high',     'recommended_quantity' => 20]);
        $this->recommendation(['priority' => 'high',     'recommended_quantity' => 5]);

        $result = $this->service->predictions($this->warehouseId, null);

        $this->assertSame(1,  $result['prioritySummary']['critical']['count']);
        $this->assertSame(10, $result['prioritySummary']['critical']['units']);
        $this->assertSame(2,  $result['prioritySummary']['high']['count']);
        $this->assertSame(25, $result['prioritySummary']['high']['units']);
        $this->assertEqualsWithDelta(2000.0, $result['prioritySummary']['high']['cost'], 0.01);
    }

    public function test_timeline_only_includes_items_running_out_within_thirty_days(): void
    {
        $this->recommendation(['days_of_cover' => 3,   'priority' => 'critical']);
        $this->recommendation(['days_of_cover' => 400, 'priority' => 'medium']);

        $result = $this->service->predictions($this->warehouseId, null);

        $this->assertCount(1, $result['stockoutTimeline']);
        $this->assertSame(3, $result['stockoutTimeline'][0]['daysLeft']);
        $this->assertSame(1, $result['predictionsStats']['predictedStockouts']);
    }

    public function test_safety_stock_comes_from_the_product(): void
    {
        $this->recommendation();

        $result = $this->service->predictions($this->warehouseId, null);

        $this->assertSame(5, $result['restockRecommendations'][0]['safetyStock']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=PredictionsAnalyticsTest
```

Expected: FAIL with `Call to undefined method ...::predictions()`.

- [ ] **Step 3: Implement `predictions()`**

Add to `DashboardAnalyticsService`:

```php
    /** Recommendations older than this are recomputed on demand. */
    private const RECOMMENDATION_MAX_AGE_HOURS = 24;
    private const STOCKOUT_HORIZON_DAYS        = 30;

    public function predictions(?int $locationId, ?int $categoryId): array
    {
        $this->ensureRecommendationsFresh();

        $cost = self::PIECE_COST;

        $rows = DB::table('restock_recommendations as r')
            ->join('products as p', 'p.product_id', '=', 'r.product_id')
            ->join('locations as l', 'l.location_id', '=', 'r.location_id')
            ->leftJoin('installation_material_details as imd', 'imd.product_id', '=', 'p.product_id')
            ->when($locationId, fn (Builder $q) => $q->where('r.location_id', $locationId))
            ->when($categoryId, fn (Builder $q) => $q->where('p.category_id', $categoryId))
            ->selectRaw("r.product_id, p.product_name, l.location_name,
                         r.current_stock, r.reorder_level, r.recommended_quantity,
                         r.priority, r.days_of_cover, r.avg_daily_demand,
                         COALESCE(p.safety_stock, 0) AS safety_stock,
                         r.recommended_quantity * ({$cost}) AS est_cost")
            ->get();

        $priorityRank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

        $stockouts = $rows->filter(static fn ($r) =>
            $r->days_of_cover !== null && (float) $r->days_of_cover <= self::STOCKOUT_HORIZON_DAYS);

        $summaryFor = static fn (string $priority) => [
            'count' => $rows->where('priority', $priority)->count(),
            'units' => (int) $rows->where('priority', $priority)->sum('recommended_quantity'),
            'cost'  => round((float) $rows->where('priority', $priority)->sum('est_cost'), 2),
        ];

        return [
            'predictionsStats' => [
                'predictedStockouts' => $stockouts->count(),
                'restockNeeded'      => $rows->count(),
                'criticalPriority'   => $rows->where('priority', 'critical')->count(),
                'estRestockCost'     => round((float) $rows->sum('est_cost'), 2),
            ],
            'stockoutTimeline' => $stockouts
                ->sortBy(static fn ($r) => (float) $r->days_of_cover)
                ->take(10)
                ->values()
                ->map(static fn ($r) => [
                    'name'           => $r->product_name,
                    'branch'         => $r->location_name ?? 'Unknown Location',
                    'currentStock'   => (int) $r->current_stock,
                    'consumptionPct' => (int) round((float) $r->avg_daily_demand),
                    'reorderLevel'   => (int) $r->reorder_level,
                    'suggestedQty'   => (int) $r->recommended_quantity,
                    'daysLeft'       => (int) round((float) $r->days_of_cover),
                ])->all(),
            'restockRecommendations' => $rows
                ->sortBy(static fn ($r) => $priorityRank[$r->priority] ?? 9)
                ->take(10)
                ->values()
                ->map(static fn ($r) => [
                    'priority'     => $r->priority,
                    'name'         => $r->product_name,
                    'branch'       => $r->location_name ?? 'Unknown Location',
                    'currentStock' => (int) $r->current_stock,
                    'reorderLevel' => (int) $r->reorder_level,
                    'safetyStock'  => (int) $r->safety_stock,
                    'suggestedQty' => (int) $r->recommended_quantity,
                    'estCost'      => round((float) $r->est_cost, 2),
                ])->all(),
            'prioritySummary' => [
                'critical' => $summaryFor('critical'),
                'high'     => $summaryFor('high'),
                'medium'   => $summaryFor('medium'),
            ],
        ];
    }

    /**
     * The restock engine is driven by a daily scheduled command. On a dev box
     * that scheduler is not running, which would leave this tab permanently
     * blank and indistinguishable from a bug. If the table is empty or stale,
     * compute it now.
     */
    private function ensureRecommendationsFresh(): void
    {
        $newest = DB::table('restock_recommendations')->max('run_at');

        if ($newest !== null
            && \Carbon\Carbon::parse($newest)->gt(now()->subHours(self::RECOMMENDATION_MAX_AGE_HOURS))) {
            return;
        }

        $this->restock->run();
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
cd back-end && php artisan test --filter=PredictionsAnalyticsTest
```

Expected: PASS, 4 tests. The fixtures set `run_at => now()`, so the freshness guard short-circuits and `run()` is never called during these tests.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/Analytics/DashboardAnalyticsService.php back-end/tests/Feature/Analytics/PredictionsAnalyticsTest.php
git commit -m "feat(analytics): drive predictions tab from restock recommendations"
```

---

## Task 5: Profit loss — the incident-to-adjustment chain

**This is the highest-risk task in the plan.** Read the rule before writing code.

A loss exists only when the system actually wrote stock off. `IncidentService::resolve()` calls `postDeduction()` for `damaged` and `not_found` only; `wrong_type` re-types the batch and `zone_mismatch` relocates it, so in both cases the goods still exist and no loss occurred. The test is therefore `incident_reports.adjustment_id IS NOT NULL`, never a list of reasons — that way a future reason is classified correctly without anyone remembering to update this file.

**Files:**
- Modify: `back-end/app/Services/Analytics/DashboardAnalyticsService.php`
- Test: `back-end/tests/Feature/Analytics/ProfitLossAnalyticsTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Analytics/ProfitLossAnalyticsTest.php`:

```php
<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\DashboardAnalyticsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProfitLossAnalyticsTest extends TestCase
{
    use DatabaseTransactions;

    private const STATUS_OPEN      = 46;
    private const STATUS_RESOLVED  = 47;
    private const STATUS_DISMISSED = 48;

    private DashboardAnalyticsService $service;
    private int $locationId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DashboardAnalyticsService::class);

        $this->locationId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Loss Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');

        $this->userId = (int) DB::table('users')->value('user_id');
    }

    private function makeProduct(float $costPrice = 80): int
    {
        return (int) DB::table('products')->insertGetId([
            'product_code' => 'TST-' . uniqid(),
            'product_name' => 'Lossy Product',
            'product_type' => 'appliance',
            'unit_price'   => 100,
            'cost_price'   => $costPrice,
            'status_id'    => 3,
            'created_at'   => now(),
            'updated_at'   => now(),
        ], 'product_id');
    }

    /** Mirrors what IncidentService::postDeduction() writes on confirm. */
    private function makeAdjustment(int $productId, int $deducted, string $type): int
    {
        $adjustmentId = (int) DB::table('adjustments')->insertGetId([
            'location_id'             => $this->locationId,
            'created_by'              => $this->userId,
            'approved_by'             => $this->userId,
            'adjustment_number'       => 'ADJ-INC-' . strtoupper(substr(uniqid(), -6)),
            'adjustment_date'         => now()->toDateString(),
            'adjustment_type'         => $type,
            'total_variance_positive' => 0,
            'total_variance_negative' => $deducted,
            'status_id'               => 20,
            'created_at'              => now(),
            'updated_at'              => now(),
        ], 'adjustment_id');

        DB::table('adjustment_details')->insert([
            'adjustment_id'   => $adjustmentId,
            'product_id'      => $productId,
            'add_quantity'    => 0,
            'deduct_quantity' => $deducted,
            'reason'          => $type === 'damage' ? 'damaged' : 'not_found',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return $adjustmentId;
    }

    private function makeIncident(
        int $productId,
        string $reason,
        int $reportedQty,
        int $statusId,
        ?int $adjustmentId = null,
        ?int $correctionId = null
    ): int {
        return (int) DB::table('incident_reports')->insertGetId([
            'incident_number' => 'INC-' . strtoupper(substr(uniqid(), -8)),
            'source_type'     => 'issuance',
            'source_id'       => 1,
            'location_id'     => $this->locationId,
            'product_id'      => $productId,
            'reason'          => $reason,
            'quantity'        => $reportedQty,
            'narrative'       => 'Test incident',
            'reported_by'     => $this->userId,
            'status_id'       => $statusId,
            'adjustment_id'   => $adjustmentId,
            'correction_id'   => $correctionId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ], 'incident_id');
    }

    public function test_wrong_type_incident_contributes_zero_loss(): void
    {
        $productId = $this->makeProduct();
        // Resolved, but via postCorrection — a correction, never an adjustment.
        $this->makeIncident($productId, 'wrong_type', 10, self::STATUS_RESOLVED, null, 1);

        $result = $this->service->profitLoss(null, null);

        $this->assertEqualsWithDelta(0.0, $result['profitLossStats']['totalProfitLoss'], 0.01);
        $this->assertSame(0, $result['profitLossStats']['damagedUnits']);
        $this->assertSame(0, $result['profitLossStats']['stolenUnits']);
    }

    public function test_zone_mismatch_incident_contributes_zero_loss(): void
    {
        $productId = $this->makeProduct();
        // Resolved via relocate() — no adjustment, no correction.
        $this->makeIncident($productId, 'zone_mismatch', 10, self::STATUS_RESOLVED);

        $result = $this->service->profitLoss(null, null);

        $this->assertEqualsWithDelta(0.0, $result['profitLossStats']['totalProfitLoss'], 0.01);
    }

    public function test_dismissed_damaged_incident_contributes_zero_loss(): void
    {
        $productId = $this->makeProduct();
        $this->makeIncident($productId, 'damaged', 10, self::STATUS_DISMISSED);

        $result = $this->service->profitLoss(null, null);

        $this->assertEqualsWithDelta(0.0, $result['profitLossStats']['totalProfitLoss'], 0.01);
    }

    public function test_confirmed_damaged_incident_is_valued_at_cost(): void
    {
        $productId    = $this->makeProduct(80);
        $adjustmentId = $this->makeAdjustment($productId, 3, 'damage');
        $this->makeIncident($productId, 'damaged', 3, self::STATUS_RESOLVED, $adjustmentId);

        $result = $this->service->profitLoss(null, null);

        $this->assertEqualsWithDelta(240.0, $result['profitLossStats']['totalProfitLoss'], 0.01);
        $this->assertSame(3, $result['profitLossStats']['damagedUnits']);
        $this->assertSame(0, $result['profitLossStats']['stolenUnits']);
    }

    public function test_not_found_incident_counts_as_stolen_units(): void
    {
        $productId    = $this->makeProduct(50);
        $adjustmentId = $this->makeAdjustment($productId, 4, 'loss');
        $this->makeIncident($productId, 'not_found', 4, self::STATUS_RESOLVED, $adjustmentId);

        $result = $this->service->profitLoss(null, null);

        $this->assertSame(4, $result['profitLossStats']['stolenUnits']);
        $this->assertEqualsWithDelta(200.0, $result['profitLossStats']['totalProfitLoss'], 0.01);
    }

    public function test_overreported_loss_is_valued_at_the_clamped_write_off(): void
    {
        $productId = $this->makeProduct(80);
        // Reporter claimed 100 but only 6 existed, so postDeduction wrote off 6.
        $adjustmentId = $this->makeAdjustment($productId, 6, 'loss');
        $this->makeIncident($productId, 'not_found', 100, self::STATUS_RESOLVED, $adjustmentId);

        $result = $this->service->profitLoss(null, null);

        // 6 x 80 = 480, NOT 100 x 80 = 8000.
        $this->assertEqualsWithDelta(480.0, $result['profitLossStats']['totalProfitLoss'], 0.01);
        $this->assertSame(6, $result['profitLossStats']['stolenUnits']);
    }

    public function test_open_incident_is_pending_and_excluded_from_the_total(): void
    {
        $productId = $this->makeProduct();
        $this->makeIncident($productId, 'damaged', 9, self::STATUS_OPEN);

        $result = $this->service->profitLoss(null, null);

        $this->assertSame(1, $result['profitLossStats']['pendingReports']);
        $this->assertEqualsWithDelta(0.0, $result['profitLossStats']['totalProfitLoss'], 0.01);

        $incident = collect($result['recentLossIncidents'])->firstWhere('qty', 9);
        $this->assertNotNull($incident, 'Open incidents still appear in the list.');
        $this->assertSame('pending', $incident['status']);
    }

    public function test_groups_loss_by_location_and_category(): void
    {
        $categoryId = (int) DB::table('category_lookup')->insertGetId([
            'category_name' => 'LossCat-' . uniqid(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'category_id');

        $productId = (int) DB::table('products')->insertGetId([
            'product_code' => 'TST-' . uniqid(),
            'product_name' => 'Categorised Loss',
            'product_type' => 'appliance',
            'category_id'  => $categoryId,
            'unit_price'   => 100,
            'cost_price'   => 25,
            'status_id'    => 3,
            'created_at'   => now(),
            'updated_at'   => now(),
        ], 'product_id');

        $adjustmentId = $this->makeAdjustment($productId, 8, 'damage');
        $this->makeIncident($productId, 'damaged', 8, self::STATUS_RESOLVED, $adjustmentId);

        $result = $this->service->profitLoss(null, null);

        $byLocation = collect($result['lossByBranch'])->firstWhere('name', 'Loss Warehouse');
        $this->assertNotNull($byLocation);
        $this->assertEqualsWithDelta(200.0, $byLocation['amount'], 0.01);

        $byCategory = collect($result['lossByCategory'])->first();
        $this->assertNotNull($byCategory);
        $this->assertSame(100, $byCategory['percentage'], 'Largest category scales to 100%.');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=ProfitLossAnalyticsTest
```

Expected: FAIL with `Call to undefined method ...::profitLoss()`.

- [ ] **Step 3: Implement `profitLoss()`**

Add to `DashboardAnalyticsService`:

```php
    /**
     * Confirmed losses only: an incident counts when it produced an Adjustment,
     * i.e. when IncidentService::postDeduction() actually removed stock.
     *
     * Reasons are NOT whitelisted here on purpose. wrong_type resolves to a
     * ScanCorrection (the batch is re-typed) and zone_mismatch to a relocate —
     * in both cases the goods still exist and nothing was lost. Keying off
     * adjustment_id means this stays correct when new reasons are added.
     */
    private function confirmedLossQuery(?int $locationId, ?int $categoryId): Builder
    {
        return DB::table('incident_reports as ir')
            ->join('adjustments as a', 'a.adjustment_id', '=', 'ir.adjustment_id')
            ->join('adjustment_details as ad', function ($join) {
                $join->on('ad.adjustment_id', '=', 'a.adjustment_id')
                     ->on('ad.product_id', '=', 'ir.product_id');
            })
            ->join('products as p', 'p.product_id', '=', 'ir.product_id')
            ->leftJoin('locations as l', 'l.location_id', '=', 'a.location_id')
            ->leftJoin('installation_material_details as imd', 'imd.product_id', '=', 'p.product_id')
            ->leftJoin('category_lookup as c', 'c.category_id', '=', 'p.category_id')
            ->whereNotNull('ir.adjustment_id')
            ->when($locationId, fn (Builder $q) => $q->where('a.location_id', $locationId))
            ->when($categoryId, fn (Builder $q) => $q->where('p.category_id', $categoryId));
    }

    public function profitLoss(?int $locationId, ?int $categoryId): array
    {
        $cost  = self::PIECE_COST;
        $value = "ad.deduct_quantity * ({$cost})";

        $totals = $this->confirmedLossQuery($locationId, $categoryId)
            ->selectRaw("a.adjustment_type,
                         SUM(ad.deduct_quantity) AS units,
                         SUM({$value}) AS amount")
            ->groupBy('a.adjustment_type')
            ->get()
            ->keyBy('adjustment_type');

        $byLocation = $this->confirmedLossQuery($locationId, $categoryId)
            ->selectRaw("COALESCE(l.location_name, 'Unknown Location') AS name, SUM({$value}) AS amount")
            ->groupBy('l.location_name')
            ->orderByDesc('amount')
            ->get()
            ->map(static fn ($r) => ['name' => $r->name, 'amount' => round((float) $r->amount, 2)])
            ->all();

        $byCategory = $this->confirmedLossQuery($locationId, $categoryId)
            ->selectRaw("COALESCE(c.category_name, 'Uncategorized') AS name, SUM({$value}) AS amount")
            ->groupBy('c.category_name')
            ->orderByDesc('amount')
            ->get();

        $maxCategory = (float) ($byCategory->max('amount') ?: 1);

        $pendingCount = (int) DB::table('incident_reports as ir')
            ->where('ir.status_id', \App\Models\IncidentReport::statusId('Open'))
            ->whereIn('ir.reason', [
                \App\Models\IncidentReport::REASON_DAMAGED,
                \App\Models\IncidentReport::REASON_NOT_FOUND,
            ])
            ->when($locationId, fn (Builder $q) => $q->where('ir.location_id', $locationId))
            ->count();

        $damaged = $totals['damage'] ?? null;
        $stolen  = $totals['loss'] ?? null;

        return [
            'profitLossStats' => [
                'totalProfitLoss' => round((float) $totals->sum('amount'), 2),
                'stolenUnits'     => (int) ($stolen?->units ?? 0),
                'damagedUnits'    => (int) ($damaged?->units ?? 0),
                'pendingReports'  => $pendingCount,
            ],
            'lossByBranch'   => $byLocation,
            'lossByCategory' => $byCategory->map(static fn ($r) => [
                'name'       => $r->name,
                'amount'     => round((float) $r->amount, 2),
                'percentage' => (int) round(((float) $r->amount / $maxCategory) * 100),
            ])->all(),
            'recentLossIncidents' => $this->recentLossIncidents($locationId, $categoryId),
        ];
    }

    /**
     * Confirmed write-offs plus still-open damaged/not_found reports.
     *
     * Open rows show the quantity and value that WOULD be lost if confirmed, so
     * reviewers can see what is at stake, but they are excluded from the header
     * total above — the status badge is what distinguishes them.
     */
    private function recentLossIncidents(?int $locationId, ?int $categoryId): array
    {
        $cost = self::PIECE_COST;

        return DB::table('incident_reports as ir')
            ->leftJoin('adjustments as a', 'a.adjustment_id', '=', 'ir.adjustment_id')
            ->leftJoin('adjustment_details as ad', function ($join) {
                $join->on('ad.adjustment_id', '=', 'ir.adjustment_id')
                     ->on('ad.product_id', '=', 'ir.product_id');
            })
            ->join('products as p', 'p.product_id', '=', 'ir.product_id')
            ->leftJoin('locations as l', 'l.location_id', '=', 'ir.location_id')
            ->leftJoin('installation_material_details as imd', 'imd.product_id', '=', 'p.product_id')
            ->leftJoin('category_lookup as c', 'c.category_id', '=', 'p.category_id')
            ->whereIn('ir.reason', [
                \App\Models\IncidentReport::REASON_DAMAGED,
                \App\Models\IncidentReport::REASON_NOT_FOUND,
            ])
            ->where(function ($q) {
                $q->whereNotNull('ir.adjustment_id')
                  ->orWhere('ir.status_id', \App\Models\IncidentReport::statusId('Open'));
            })
            ->when($locationId, fn (Builder $q) => $q->where('ir.location_id', $locationId))
            ->when($categoryId, fn (Builder $q) => $q->where('p.category_id', $categoryId))
            ->selectRaw("ir.created_at, ir.reason, ir.narrative, ir.adjustment_id,
                         p.product_name,
                         COALESCE(l.location_name, 'Unknown Location') AS location_name,
                         COALESCE(c.category_name, 'Uncategorized') AS category_name,
                         COALESCE(ad.deduct_quantity, ir.quantity) AS qty,
                         COALESCE(ad.deduct_quantity, ir.quantity) * ({$cost}) AS loss_value")
            ->orderByDesc('ir.created_at')
            ->limit(10)
            ->get()
            ->map(static fn ($r) => [
                'date'         => $r->created_at,
                'itemName'     => $r->product_name,
                'branchName'   => $r->location_name,
                'itemCategory' => $r->category_name,
                'lossType'     => $r->reason === \App\Models\IncidentReport::REASON_DAMAGED ? 'damaged' : 'stolen',
                'qty'          => (int) $r->qty,
                'lossValue'    => round((float) $r->loss_value, 2),
                'reason'       => $r->narrative ?? 'Incident report',
                'status'       => $r->adjustment_id !== null ? 'approved' : 'pending',
            ])->all();
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
cd back-end && php artisan test --filter=ProfitLossAnalyticsTest
```

Expected: PASS, 8 tests. If `test_wrong_type_incident_contributes_zero_loss` fails, the query is matching on reason instead of `adjustment_id` — go back and fix the join, do not adjust the test.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/Analytics/DashboardAnalyticsService.php back-end/tests/Feature/Analytics/ProfitLossAnalyticsTest.php
git commit -m "feat(analytics): derive profit loss from confirmed incident write-offs"
```

---

## Task 6: Location performance and filters

**Files:**
- Modify: `back-end/app/Services/Analytics/DashboardAnalyticsService.php`
- Test: `back-end/tests/Feature/Analytics/LocationPerformanceAnalyticsTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Analytics/LocationPerformanceAnalyticsTest.php`:

```php
<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\DashboardAnalyticsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocationPerformanceAnalyticsTest extends TestCase
{
    use DatabaseTransactions;

    private DashboardAnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DashboardAnalyticsService::class);
    }

    private function makeLocation(string $name, string $type = 'warehouse'): int
    {
        return (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-' . uniqid(),
            'location_name' => $name,
            'location_type' => $type,
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');
    }

    private function stock(int $locationId, int $qty, int $recommended = 10, ?int $max = 100): void
    {
        $productId = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Perf Product',
            'product_type'       => 'appliance',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => $recommended,
            'max_stock_level'    => $max,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');

        DB::table('inventory')->insert([
            'location_id'        => $locationId,
            'product_id'         => $productId,
            'quantity_on_hand'   => $qty,
            'available_quantity' => $qty,
            'reorder_level'      => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    public function test_groups_by_real_location_name_not_unknown(): void
    {
        $locationId = $this->makeLocation('Named Warehouse');
        $this->stock($locationId, 50);

        $result = $this->service->locationPerformance(null);

        $row = collect($result['branchPerformanceData'])->firstWhere('name', 'Named Warehouse');
        $this->assertNotNull($row, 'Location must be grouped by its real name.');
        $this->assertSame(1, $row['items']);
    }

    public function test_efficiency_score_reflects_healthy_stock_share(): void
    {
        $locationId = $this->makeLocation('Half Healthy');
        $this->stock($locationId, 50);  // optimal
        $this->stock($locationId, 50);  // optimal
        $this->stock($locationId, 5);   // low
        $this->stock($locationId, 0);   // critical

        $result = $this->service->locationPerformance(null);
        $row    = collect($result['branchPerformanceData'])->firstWhere('name', 'Half Healthy');

        // 2 healthy of 4 rows = 50%.
        $this->assertSame(50, $row['efficiencyScore']);
        $this->assertSame('Needs Improvement', $row['performance']);
    }

    public function test_restock_cost_falls_back_when_max_stock_level_is_null(): void
    {
        $locationId = $this->makeLocation('No Ceiling');
        // qty 2, below recommended 10, no max_stock_level configured.
        $this->stock($locationId, 2, 10, null);

        $result = $this->service->locationPerformance(null);
        $row    = collect($result['branchRestockingNeeds'])->firstWhere('name', 'No Ceiling');

        $this->assertSame(1, $row['restockNeeded']);
        // Target falls back to the reorder point (10): (10-2) x 80 = 640.
        $this->assertEqualsWithDelta(640.0, $row['restockCost'], 0.01);
    }

    public function test_filters_list_all_location_types_and_categories(): void
    {
        $this->makeLocation('A Warehouse', 'warehouse');
        $this->makeLocation('A Showroom', 'showroom');

        DB::table('category_lookup')->insert([
            'category_name' => 'FilterCat-' . uniqid(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $filters = $this->service->filters();

        $names = collect($filters['locations'])->pluck('name');
        $this->assertContains('A Warehouse', $names);
        $this->assertContains('A Showroom', $names, 'Warehouses AND showrooms must be selectable.');
        $this->assertNotEmpty($filters['categories']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=LocationPerformanceAnalyticsTest
```

Expected: FAIL with `Call to undefined method ...::locationPerformance()`.

- [ ] **Step 3: Implement `locationPerformance()` and `filters()`**

Add to `DashboardAnalyticsService`:

```php
    public function locationPerformance(?int $categoryId): array
    {
        $bucket  = $this->bucketExpression();
        $price   = self::PIECE_PRICE;
        $cost    = self::PIECE_COST;
        $reorder = self::REORDER_POINT;

        // Restock target: the configured ceiling, else the reorder point. Without
        // the fallback every product with a null max_stock_level would contribute
        // zero restock cost and understate the location's true need.
        $target = "COALESCE(p.max_stock_level, {$reorder})";

        $rows = $this->inventoryQuery(null, $categoryId)
            ->selectRaw("i.location_id,
                         l.location_name,
                         COUNT(*) AS items,
                         SUM(i.quantity_on_hand * ({$price})) AS total_value,
                         SUM(CASE WHEN {$bucket} IN ('low', 'critical') THEN 1 ELSE 0 END) AS low_stock_count,
                         SUM(CASE WHEN i.quantity_on_hand <= 0 THEN 1 ELSE 0 END) AS critical_count,
                         SUM(CASE WHEN i.quantity_on_hand <= {$reorder} THEN 1 ELSE 0 END) AS restock_needed,
                         SUM(CASE WHEN i.quantity_on_hand <= {$reorder}
                                  THEN GREATEST({$target} - i.quantity_on_hand, 0) * ({$cost})
                                  ELSE 0 END) AS restock_cost")
            ->groupBy('i.location_id', 'l.location_name')
            ->get();

        $to     = now()->toDateString();
        $from   = now()->subDays(self::MOVEMENT_WINDOW_DAYS - 1)->toDateString();
        $demand = $this->restock->dailyDemandByLocation($from, $to);

        $performance = $rows->map(function ($r) use ($demand) {
            $items      = (int) $r->items;
            $lowStock   = (int) $r->low_stock_count;
            $efficiency = $items > 0 ? (int) round((($items - $lowStock) / $items) * 100) : 0;
            $totalValue = (float) $r->total_value;

            $locationDemand = $demand[(int) $r->location_id] ?? [];
            $movedUnits     = array_sum(array_map('array_sum', $locationDemand));
            $avgTurnover    = $totalValue > 0 && count($locationDemand) > 0
                ? (int) round($movedUnits / max(1, count($locationDemand)))
                : 0;

            return [
                'id'                   => (int) $r->location_id,
                'name'                 => $r->location_name ?? 'Unknown Location',
                'items'                => $items,
                'totalValue'           => round($totalValue, 2),
                'lowStockCount'        => $lowStock,
                'criticalPriority'     => (int) $r->critical_count,
                'restockNeeded'        => (int) $r->restock_needed,
                'restockCost'          => round((float) $r->restock_cost, 2),
                'avgTurnover'          => $avgTurnover,
                'efficiencyScore'      => $efficiency,
                'performance'          => $efficiency >= 80 ? 'Excellent' : 'Needs Improvement',
                'stockValueFormatted'  => $totalValue >= 1000000
                    ? '₱' . number_format($totalValue / 1000000, 1) . 'M'
                    : '₱' . number_format($totalValue / 1000) . 'K',
            ];
        })
        ->sortByDesc(static fn ($b) => [$b['efficiencyScore'], $b['totalValue']])
        ->values();

        return [
            'branchPerformanceData' => $performance->all(),
            'branchRestockingNeeds' => $performance->map(static fn ($b) => [
                'name'             => $b['name'],
                'restockNeeded'    => $b['restockNeeded'],
                'criticalPriority' => $b['criticalPriority'],
                'restockCost'      => $b['restockCost'],
            ])->all(),
        ];
    }

    /**
     * Dropdown contents. Locations are NOT restricted to branch_office: the old
     * page filtered on that type, and since this deployment has none, the
     * dropdown rendered empty and every filtered view collapsed to zero.
     */
    public function filters(): array
    {
        return [
            'locations' => DB::table('locations')
                ->orderBy('location_name')
                ->get(['location_id', 'location_name', 'location_type'])
                ->map(static fn ($l) => [
                    'id'   => (int) $l->location_id,
                    'name' => $l->location_name,
                    'type' => $l->location_type,
                ])->all(),
            'categories' => DB::table('category_lookup')
                ->orderBy('category_name')
                ->get(['category_id', 'category_name'])
                ->map(static fn ($c) => [
                    'id'   => (int) $c->category_id,
                    'name' => $c->category_name,
                ])->all(),
        ];
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
cd back-end && php artisan test --filter=LocationPerformanceAnalyticsTest
```

Expected: PASS, 4 tests.

- [ ] **Step 5: Run the whole analytics suite**

```bash
cd back-end && php artisan test --filter=Analytics
```

Expected: PASS, all tests from Tasks 1-6.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Services/Analytics/DashboardAnalyticsService.php back-end/tests/Feature/Analytics/LocationPerformanceAnalyticsTest.php
git commit -m "feat(analytics): add location performance and filter options"
```

---

## Task 7: Controller, routes and permission gating

`/api/batch` has no permission middleware, so this page's data is currently readable by any authenticated user. The new endpoints close that.

**Files:**
- Create: `back-end/app/Http/Controllers/Api/AnalyticsDashboardController.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/Analytics/AnalyticsEndpointsTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Analytics/AnalyticsEndpointsTest.php`:

```php
<?php

namespace Tests\Feature\Analytics;

use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AnalyticsEndpointsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'admin'               => 'System Administrator',
            'inventory_analyst'   => 'Inventory Analyst',
            'auditor'             => 'Auditor',
            'warehouse_personnel' => 'Warehouse Personnel',
        ] as $name => $desc) {
            Role::firstOrCreate(['role_name' => $name], ['description' => $desc]);
        }

        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionsSeeder::class);
    }

    private function makeUser(string $roleSlug): User
    {
        $role     = Role::where('role_name', $roleSlug)->firstOrFail();
        $statusId = StatusLookup::query()->value('status_id');

        return User::forceCreate([
            'first_name'    => 'Test',
            'last_name'     => ucfirst($roleSlug),
            'email'         => $roleSlug . '_' . uniqid() . '@test.local',
            'username'      => $roleSlug . '_' . uniqid(),
            'password_hash' => Hash::make('password'),
            'role_id'       => $role->role_id,
            'status_id'     => $statusId,
        ]);
    }

    public static function endpointProvider(): array
    {
        return [
            'stock level'         => ['/api/analytics/stock-level'],
            'movement'            => ['/api/analytics/movement'],
            'predictions'         => ['/api/analytics/predictions'],
            'profit loss'         => ['/api/analytics/profit-loss'],
            'location performance'=> ['/api/analytics/location-performance'],
            'filters'             => ['/api/analytics/filters'],
        ];
    }

    /** @dataProvider endpointProvider */
    public function test_admin_can_read_every_endpoint(string $uri): void
    {
        $response = $this->actingAs($this->makeUser('admin'), 'sanctum')->getJson($uri);

        $response->assertOk()->assertJsonStructure(['success', 'data']);
    }

    /** @dataProvider endpointProvider */
    public function test_user_without_permission_is_forbidden(string $uri): void
    {
        $response = $this->actingAs($this->makeUser('warehouse_personnel'), 'sanctum')->getJson($uri);

        $this->assertContains(
            $response->status(),
            [403, 401],
            "{$uri} must reject a user lacking analytics_dashboard.read"
        );
    }

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/analytics/stock-level')->assertUnauthorized();
    }

    public function test_stock_level_returns_the_shape_the_ui_binds_to(): void
    {
        $this->actingAs($this->makeUser('admin'), 'sanctum')
            ->getJson('/api/analytics/stock-level')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'statsData'         => ['totalStockValue', 'totalUnits', 'lowStockItems', 'criticalItems'],
                    'stockDistribution' => ['optimal', 'lowStock', 'critical', 'overstock'],
                    'reorderCompliance' => ['rate', 'belowReorderPoint', 'aboveSafetyStock'],
                    'criticalItems',
                    'overstockItems',
                ],
            ]);
    }

    public function test_location_filter_is_accepted(): void
    {
        $this->actingAs($this->makeUser('admin'), 'sanctum')
            ->getJson('/api/analytics/stock-level?location_id=1&category_id=1')
            ->assertOk();
    }
}
```

> **Note on `warehouse_personnel`:** if `RolePermissionsSeeder` happens to grant that role `analytics_dashboard.read`, the forbidden test will fail. If it does, check the seeder and switch the test to a role that genuinely lacks the permission — do NOT weaken the assertion.

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=AnalyticsEndpointsTest
```

Expected: FAIL with 404s — the routes do not exist.

- [ ] **Step 3: Create the controller**

Create `back-end/app/Http/Controllers/Api/AnalyticsDashboardController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\DashboardAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Analytics Dashboard reads. All computation lives in
 * DashboardAnalyticsService; these actions only translate query parameters and
 * wrap the result.
 *
 * Unlike /api/batch (which this page used to call, and which carries no
 * permission middleware at all), every route here is gated on
 * analytics_dashboard.read.
 */
class AnalyticsDashboardController extends Controller
{
    public function __construct(private DashboardAnalyticsService $analytics)
    {
    }

    private function locationId(Request $request): ?int
    {
        $value = $request->query('location_id');

        return ($value === null || $value === '') ? null : (int) $value;
    }

    private function categoryId(Request $request): ?int
    {
        $value = $request->query('category_id');

        return ($value === null || $value === '') ? null : (int) $value;
    }

    private function ok(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function stockLevel(Request $request): JsonResponse
    {
        return $this->ok($this->analytics->stockLevel(
            $this->locationId($request),
            $this->categoryId($request)
        ));
    }

    public function movement(Request $request): JsonResponse
    {
        return $this->ok($this->analytics->movement(
            $this->locationId($request),
            $this->categoryId($request)
        ));
    }

    public function predictions(Request $request): JsonResponse
    {
        return $this->ok($this->analytics->predictions(
            $this->locationId($request),
            $this->categoryId($request)
        ));
    }

    public function profitLoss(Request $request): JsonResponse
    {
        return $this->ok($this->analytics->profitLoss(
            $this->locationId($request),
            $this->categoryId($request)
        ));
    }

    public function locationPerformance(Request $request): JsonResponse
    {
        return $this->ok($this->analytics->locationPerformance(
            $this->categoryId($request)
        ));
    }

    public function filters(): JsonResponse
    {
        return $this->ok($this->analytics->filters());
    }
}
```

- [ ] **Step 4: Register the routes**

In `back-end/routes/api.php`, add this import next to the other `App\Http\Controllers\Api` imports at the top of the file:

```php
use App\Http\Controllers\Api\AnalyticsDashboardController;
```

Then find the restock engine route block (search for `PR Recommendation analytics (restock engine)`) and insert this block immediately after its closing `});`:

```php
    /*
    |----------------------------------------------------------------------
    | Analytics Dashboard (admin / inventory analyst / auditor)
    |----------------------------------------------------------------------
    | Spec: docs/superpowers/specs/2026-08-02-analytics-dashboard-backend-design.md
    */
    Route::middleware('permission:analytics_dashboard,read')->group(function () {
        Route::get('/analytics/stock-level',          [AnalyticsDashboardController::class, 'stockLevel']);
        Route::get('/analytics/movement',             [AnalyticsDashboardController::class, 'movement']);
        Route::get('/analytics/predictions',          [AnalyticsDashboardController::class, 'predictions']);
        Route::get('/analytics/profit-loss',          [AnalyticsDashboardController::class, 'profitLoss']);
        Route::get('/analytics/location-performance', [AnalyticsDashboardController::class, 'locationPerformance']);
        Route::get('/analytics/filters',              [AnalyticsDashboardController::class, 'filters']);
    });
```

- [ ] **Step 5: Clear the route and config cache**

```bash
cd back-end && php artisan route:clear && php artisan config:clear
```

This project caches config and routes; a previous session's cache will make new routes 404 and is a known time-sink here.

- [ ] **Step 6: Run the tests to verify they pass**

```bash
cd back-end && php artisan test --filter=AnalyticsEndpointsTest
```

Expected: PASS, 15 tests (6 + 6 from the data provider, plus 3 standalone).

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/AnalyticsDashboardController.php back-end/routes/api.php back-end/tests/Feature/Analytics/AnalyticsEndpointsTest.php
git commit -m "feat(analytics): expose permission-gated analytics endpoints"
```

---

## Task 8: Demo seed data

Without this the dashboard renders zeros on the dev database and nothing is verifiable. Dev only, idempotent.

**Files:**
- Create: `back-end/database/seeders/AnalyticsDemoDataSeeder.php`

- [ ] **Step 1: Write the seeder**

Create `back-end/database/seeders/AnalyticsDemoDataSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\IncidentReport;
use App\Services\IncidentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Operational demo data for the Analytics Dashboard. DEV ONLY.
 *
 * The dev database ships with master data (products, locations, users) but no
 * operational history at all, so every analytics tab renders zeros — visually
 * indistinguishable from the bug this module was built to fix.
 *
 * Idempotent: each section is skipped when its table already holds rows, so
 * re-running is safe and never doubles anything. Creates no products and no
 * locations; it only builds history on top of what already exists.
 */
class AnalyticsDemoDataSeeder extends Seeder
{
    private const DEMAND_DAYS = 90;

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('AnalyticsDemoDataSeeder is a development seeder. Refusing to run in production.');
            return;
        }

        $locations = DB::table('locations')->where('is_active', 1)->get();
        $products  = DB::table('products')->limit(120)->get();

        if ($locations->isEmpty() || $products->isEmpty()) {
            $this->command->error('Need locations and products first. Run the master-data seeders.');
            return;
        }

        $this->seedInventory($locations, $products);
        $this->seedDemandHistory($locations, $products);
        $this->seedIncidents($locations, $products);

        $this->command->info('Recomputing restock recommendations from the seeded history...');
        $this->command->call('analytics:restock');

        $this->command->info('Analytics demo data ready.');
    }

    /**
     * Stock spread deliberately across all four buckets so the Stock Level tab
     * has something in every slice, including the null max_stock_level path.
     */
    private function seedInventory($locations, $products): void
    {
        if (DB::table('inventory')->exists()) {
            $this->command->warn('inventory already has rows — skipping.');
            return;
        }

        $rows = [];
        $i    = 0;

        foreach ($locations as $location) {
            foreach ($products as $product) {
                $i++;
                $recommended = (int) ($product->recommended_stocks ?: 10);
                $max         = $product->max_stock_level !== null ? (int) $product->max_stock_level : null;

                $qty = match ($i % 10) {
                    0       => 0,                                   // critical
                    1, 2    => max(1, (int) ($recommended * 0.6)),  // low
                    3       => $max !== null ? $max + 25 : 500,     // overstock
                    default => $max !== null
                        ? (int) max($recommended + 1, $max * 0.6)
                        : $recommended * 4,                         // optimal
                };

                $rows[] = [
                    'location_id'        => $location->location_id,
                    'product_id'         => $product->product_id,
                    'quantity_on_hand'   => $qty,
                    'available_quantity' => $qty,
                    'packages_on_hand'   => 0,
                    'reorder_level'      => 0,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('inventory')->insert($chunk);
        }

        $this->command->info('Seeded ' . count($rows) . ' inventory rows.');
    }

    /**
     * 90 days of sales and issuances. The restock engine uses 90/30-day windows,
     * so anything shorter leaves demand velocity, trend and days-of-cover
     * degenerate and the Predictions tab empty.
     */
    private function seedDemandHistory($locations, $products): void
    {
        if (DB::table('sales_details')->exists() || DB::table('issuance_details')->exists()) {
            $this->command->warn('Demand history already exists — skipping.');
            return;
        }

        $customerId = DB::table('customers')->value('customer_id');
        $userId     = DB::table('users')->value('user_id');
        $statusId   = DB::table('status_lookup')
            ->where('status_category', 'issuance')
            ->where('status_name', 'Completed')
            ->value('status_id');

        foreach ($locations as $location) {
            for ($day = self::DEMAND_DAYS; $day >= 1; $day--) {
                $date = now()->subDays($day);

                // Roughly every third day has activity, so the series has texture
                // rather than a flat line that makes every product identical.
                if ($day % 3 !== 0) {
                    continue;
                }

                $salesId = DB::table('sales')->insertGetId([
                    'customer_id'    => $customerId,
                    'location_id'    => $location->location_id,
                    'invoice_number' => 'DEMO-INV-' . $location->location_id . '-' . $day,
                    'order_date'     => $date->toDateString(),
                    'sale_date'      => $date->toDateString(),
                    'total_amount'   => 0,
                    'sold_by'        => $userId,
                    'created_at'     => $date,
                    'updated_at'     => $date,
                ], 'sales_id');

                $total = 0;

                // A varying slice of the catalogue moves each day, and the
                // quantity scales with position, so fast and slow movers are
                // genuinely distinguishable on the Movement tab.
                foreach ($products->take(40) as $index => $product) {
                    if (($index + $day) % 4 !== 0) {
                        continue;
                    }

                    $qty      = max(1, (int) (($index % 7) + 1));
                    $price    = (float) ($product->unit_price ?: 100);
                    $subtotal = $qty * $price;
                    $total   += $subtotal;

                    DB::table('sales_details')->insert([
                        'sales_id'   => $salesId,
                        'product_id' => $product->product_id,
                        'quantity'   => $qty,
                        'unit_price' => $price,
                        'subtotal'   => $subtotal,
                        'created_at' => $date,
                        'updated_at' => $date,
                    ]);
                }

                DB::table('sales')->where('sales_id', $salesId)->update(['total_amount' => $total]);

                // Issuances only from warehouses — that is where picking happens.
                if ($location->location_type !== 'warehouse') {
                    continue;
                }

                $issuanceId = DB::table('issuances')->insertGetId([
                    'location_id'    => $location->location_id,
                    'customer_id'    => $customerId,
                    'dr_number'      => 'DEMO-DR-' . $location->location_id . '-' . $day,
                    'issuance_date'  => $date->toDateString(),
                    'issuance_type'  => 'project',
                    'total_amount'   => 0,
                    'issued_by'      => $userId,
                    'status_id'      => $statusId,
                    'created_at'     => $date,
                    'updated_at'     => $date,
                ], 'issuance_id');

                foreach ($products->take(20) as $index => $product) {
                    if (($index + $day) % 5 !== 0) {
                        continue;
                    }

                    $qty = max(1, (int) (($index % 4) + 1));

                    DB::table('issuance_details')->insert([
                        'issuance_id'      => $issuanceId,
                        'product_id'       => $product->product_id,
                        'quantity_issued'  => $qty,
                        'quantity_in_pieces' => $qty,
                        'unit_price'       => (float) ($product->unit_price ?: 100),
                        'subtotal'         => $qty * (float) ($product->unit_price ?: 100),
                        'created_at'       => $date,
                        'updated_at'       => $date,
                    ]);
                }
            }
        }

        $this->command->info('Seeded ' . self::DEMAND_DAYS . ' days of demand history.');
    }

    /**
     * Incidents covering every reason, filed and resolved through IncidentService
     * so the incident -> adjustment chain is authentic rather than hand-built.
     * This is what makes the Profit Loss rule visible: the wrong_type and
     * zone_mismatch rows MUST contribute nothing to the loss total.
     */
    private function seedIncidents($locations, $products): void
    {
        if (DB::table('incident_reports')->exists()) {
            $this->command->warn('incident_reports already has rows — skipping.');
            return;
        }

        $service    = app(IncidentService::class);
        $userId     = (int) DB::table('users')->value('user_id');
        $warehouse  = $locations->firstWhere('location_type', 'warehouse') ?? $locations->first();
        $candidates = $products->take(6)->values();

        if ($candidates->count() < 6) {
            $this->command->warn('Need at least 6 products to seed the incident matrix — skipping.');
            return;
        }

        // wrong_type needs a batch to re-type, so give every incident one.
        $batchFor = function ($product) use ($warehouse) {
            return (int) DB::table('inventory_batches')->insertGetId([
                'product_id'         => $product->product_id,
                'location_id'        => $warehouse->location_id,
                'quantity_received'  => 100,
                'quantity_remaining' => 100,
                'received_at'        => now()->subDays(30),
                'zone_label'         => 'A-01',
                'created_at'         => now()->subDays(30),
                'updated_at'         => now()->subDays(30),
            ], 'batch_id');
        };

        $file = function (array $product, string $reason, int $qty) use ($service, $userId, $warehouse, $batchFor) {
            return $service->file([
                'source_type'   => 'issuance',
                'source_id'     => 1,
                'location_id'   => (int) $warehouse->location_id,
                'product_id'    => (int) $product['product']->product_id,
                'batch_id'      => $batchFor($product['product']),
                'reason'        => $reason,
                'quantity'      => $qty,
                'narrative'     => $product['narrative'],
                'expected_zone' => 'A-01',
                'actual_zone'   => $reason === IncidentReport::REASON_ZONE_MISMATCH ? 'B-04' : 'A-01',
            ], $userId);
        };

        // 1. Confirmed damage -> real loss.
        $damaged = $file(
            ['product' => $candidates[0], 'narrative' => 'Carton crushed during handling.'],
            IncidentReport::REASON_DAMAGED,
            4
        );
        $service->resolve($damaged, 'confirm', ['resolution_note' => 'Verified damaged, written off.'], $userId);

        // 2. Confirmed missing stock -> real loss.
        $missing = $file(
            ['product' => $candidates[1], 'narrative' => 'Unit not on shelf at pick time.'],
            IncidentReport::REASON_NOT_FOUND,
            2
        );
        $service->resolve($missing, 'confirm', ['resolution_note' => 'Stock count confirms shortage.'], $userId);

        // 3. Wrong product delivered -> re-typed, NOT a loss.
        $wrongType = $file(
            ['product' => $candidates[2], 'narrative' => 'Batch received under the wrong product code.'],
            IncidentReport::REASON_WRONG_TYPE,
            5
        );
        $service->resolve($wrongType, 'confirm', [
            'corrected_product_id' => (int) $candidates[3]->product_id,
            'resolution_note'      => 'Re-typed to the product physically on the shelf.',
        ], $userId);

        // 4. Shelved in the wrong zone -> relocated, NOT a loss.
        $zone = $file(
            ['product' => $candidates[4], 'narrative' => 'Found in zone B-04 instead of A-01.'],
            IncidentReport::REASON_ZONE_MISMATCH,
            3
        );
        $service->resolve($zone, 'confirm', ['resolution_note' => 'Relocated to the correct zone.'], $userId);

        // 5. Dismissed report -> NOT a loss.
        $dismissed = $file(
            ['product' => $candidates[5], 'narrative' => 'Reported missing, later found behind the rack.'],
            IncidentReport::REASON_NOT_FOUND,
            1
        );
        $service->resolve($dismissed, 'dismiss', ['resolution_note' => 'False alarm.'], $userId);

        // 6. Still open -> populates Pending Reports, excluded from the total.
        $file(
            ['product' => $candidates[0], 'narrative' => 'Water damage suspected, awaiting review.'],
            IncidentReport::REASON_DAMAGED,
            2
        );

        $this->command->info('Seeded 6 incidents covering all four reasons plus dismissed and open.');
    }
}
```

- [ ] **Step 2: Run the seeder against the dev database**

```bash
cd back-end && php artisan db:seed --class=AnalyticsDemoDataSeeder
```

Expected output ends with `Analytics demo data ready.`

If it fails on a missing column or status lookup, read the error, correct the insert to match the actual schema (use `SHOW COLUMNS FROM <table>` via `C:/XAMPP1/mysql/bin/mysql.exe -u root airkingV5`), and re-run. The seeder is guarded per-section, so partial progress is preserved.

- [ ] **Step 3: Verify the loss rule on real seeded data**

```bash
cd back-end && php artisan tinker --execute="
\$s = app(App\Services\Analytics\DashboardAnalyticsService::class);
\$r = \$s->profitLoss(null, null);
echo 'total=' . \$r['profitLossStats']['totalProfitLoss'] . PHP_EOL;
echo 'damaged=' . \$r['profitLossStats']['damagedUnits'] . PHP_EOL;
echo 'stolen=' . \$r['profitLossStats']['stolenUnits'] . PHP_EOL;
echo 'pending=' . \$r['profitLossStats']['pendingReports'] . PHP_EOL;
"
```

Expected: `damaged=4`, `stolen=2`, `pending=1`, and a non-zero total. If `damaged` or `stolen` exceeds those numbers, a `wrong_type` or `zone_mismatch` incident is leaking into the loss total — stop and fix `confirmedLossQuery()`.

- [ ] **Step 4: Verify the seeder is idempotent**

```bash
cd back-end && php artisan db:seed --class=AnalyticsDemoDataSeeder
```

Expected: warnings that each table already has rows, and no duplicate data. Confirm counts are unchanged:

```bash
cd back-end && php artisan tinker --execute="
echo 'inventory=' . DB::table('inventory')->count() . PHP_EOL;
echo 'incidents=' . DB::table('incident_reports')->count() . PHP_EOL;
"
```

- [ ] **Step 5: Commit**

```bash
git add back-end/database/seeders/AnalyticsDemoDataSeeder.php
git commit -m "feat(analytics): add dev-only demo data seeder"
```

---

## Task 9: Frontend API client

**Files:**
- Modify: `frontend/src/admin/services/api.js`

- [ ] **Step 1: Add the API object**

In `frontend/src/admin/services/api.js`, find `export const batchAPI = {` and insert this block immediately **before** it:

```js
/* ======================
   ANALYTICS DASHBOARD API
   Server-computed per-tab analytics. Replaces the page's old client-side
   computation over /api/batch, which truncated at 500 inventory rows and
   could not reach incident data at all.
====================== */
export const analyticsAPI = {
  _qs: (params = {}) => {
    const entries = Object.entries(params).filter(([, v]) => v !== '' && v != null);
    const qs = new URLSearchParams(entries).toString();
    return qs ? `?${qs}` : '';
  },
  getStockLevel: async (params = {}) => apiRequest(`/analytics/stock-level${analyticsAPI._qs(params)}`),
  getMovement: async (params = {}) => apiRequest(`/analytics/movement${analyticsAPI._qs(params)}`),
  getPredictions: async (params = {}) => apiRequest(`/analytics/predictions${analyticsAPI._qs(params)}`),
  getProfitLoss: async (params = {}) => apiRequest(`/analytics/profit-loss${analyticsAPI._qs(params)}`),
  getLocationPerformance: async (params = {}) => apiRequest(`/analytics/location-performance${analyticsAPI._qs(params)}`),
  getFilters: async () => apiRequest('/analytics/filters'),
};
```

- [ ] **Step 2: Verify the module still parses**

```bash
cd frontend && npx eslint src/admin/services/api.js
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -m "feat(analytics): add analyticsAPI client"
```

---

## Task 10: Convert the page to a thin renderer

**The single most important constraint in this plan: do not change any JSX.** Every `render*Tab()` function, every `<div>`, every class name and every SVG stays byte-identical. You are deleting computation above them and changing how `tabData` is filled — nothing else.

**Files:**
- Modify: `frontend/src/admin/pages/AnalyticsDashboard.jsx`

- [ ] **Step 1: Delete the computation layer**

Delete these from the top of the file (currently lines 17-135), and nothing else:

- the `TAB_RESOURCES` constant
- `classifyStock()`
- `inventoryPiecePrice()`
- `buildItemTxCounts()`
- `buildItemAnalysis()`
- `applyFilters()`
- `normalizeInventoryRecord()`
- `normalizeTransactionRecord()`

- [ ] **Step 2: Replace the import**

Change:

```js
import { batchAPI } from '../services/api';
```

to:

```js
import { analyticsAPI } from '../services/api';
```

- [ ] **Step 3: Replace the fetch and processing effects**

Delete the `fetchTabData` callback, the `rawCacheRef` / `dataReady` declarations, both `useEffect` hooks (the fetch effect and the ~290-line processing effect ending `}, [dataReady, activeTab, selectedBranch, selectedCategory]);`), and replace all of it with:

```js
  // One request per tab. The server returns exactly the shape each tab renders,
  // so there is no client-side computation left to do.
  const TAB_FETCHERS = useMemo(() => ({
    'stock-level':        analyticsAPI.getStockLevel,
    'movement':           analyticsAPI.getMovement,
    'predictions':        analyticsAPI.getPredictions,
    'profit-loss':        analyticsAPI.getProfitLoss,
    'branch-performance': analyticsAPI.getLocationPerformance,
  }), []);

  // Load the filter dropdowns once.
  useEffect(() => {
    let cancelled = false;

    analyticsAPI.getFilters()
      .then((res) => {
        if (cancelled) return;
        const data = res?.data ?? {};
        setBranches(Array.isArray(data.locations) ? data.locations : []);
        setCategories(Array.isArray(data.categories) ? data.categories : []);
      })
      .catch((err) => {
        console.error('Failed to load analytics filters:', err);
      });

    return () => { cancelled = true; };
  }, []);

  // Refetch whenever the tab or either filter changes.
  useEffect(() => {
    let cancelled = false;
    const fetcher = TAB_FETCHERS[activeTab];
    if (!fetcher) return undefined;

    const isFirstLoad = !hasLoadedOnce.current;
    if (isFirstLoad) setInitialLoading(true);
    else setRefreshing(true);

    fetcher({ location_id: selectedBranch, category_id: selectedCategory })
      .then((res) => {
        if (cancelled) return;
        setTabData((prev) => ({ ...prev, ...(res?.data ?? {}) }));
        hasLoadedOnce.current = true;
        setError(null);
      })
      .catch((err) => {
        if (cancelled) return;
        setError(err.message);
        console.error('Failed to fetch analytics data:', err);
      })
      .finally(() => {
        if (cancelled) return;
        setInitialLoading(false);
        setRefreshing(false);
      });

    return () => { cancelled = true; };
  }, [TAB_FETCHERS, activeTab, selectedBranch, selectedCategory]);
```

- [ ] **Step 4: Update the category dropdown to use IDs**

The categories list is now objects, not strings. In the filter section, change:

```jsx
            {categories.map((cat, idx) => (
              <option key={idx} value={cat}>{cat}</option>
            ))}
```

to:

```jsx
            {categories.map((cat) => (
              <option key={cat.id} value={cat.id}>{cat.name}</option>
            ))}
```

The location dropdown already maps `branch.id` / `branch.name`, which matches the new payload — leave it exactly as it is.

- [ ] **Step 5: Reword the Predictions subtitle**

This is the **only** copy change sanctioned by the spec. The restock engine
generates recommendations for warehouse locations only, so branch and showroom
stock is legitimately absent from this tab. Saying so makes the scope read as
intentional rather than as missing data.

In `renderPredictionsTab()`, find:

```jsx
        <h3>Projected Stock-Out Timeline</h3>
        <p className="card-subtitle">Items predicted to run out within 30 days</p>
```

and change the subtitle to:

```jsx
        <h3>Projected Stock-Out Timeline</h3>
        <p className="card-subtitle">Warehouse replenishment &mdash; items predicted to run out within 30 days</p>
```

Change the text only. Do not touch the `className`, the tag, or anything else.

- [ ] **Step 6: Verify no unused imports remain**

```bash
cd frontend && npx eslint src/admin/pages/AnalyticsDashboard.jsx
```

Expected: no errors. If `useCallback` is now unused, remove it from the React import — but leave `useMemo` (the chart configs and `TAB_FETCHERS` need it) and `useRef` (`hasLoadedOnce` still uses it).

- [ ] **Step 7: Confirm no markup was changed**

```bash
git diff --stat frontend/src/admin/pages/AnalyticsDashboard.jsx
git diff frontend/src/admin/pages/AnalyticsDashboard.jsx | grep '^[-+].*className' | head -20
```

Expected: the second command prints **nothing**. Any `className` in the diff means markup changed — revert that hunk. (The subtitle change in Step 5 edits text inside a `<p>`, so its line does contain `className` — that single line is the one permitted match. Anything else is a regression.)

- [ ] **Step 8: Write the page test**

The spec assumed an existing page test to extend; there is none for this page. Create `frontend/src/admin/pages/AnalyticsDashboard.test.jsx`:

```jsx
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import AnalyticsDashboard from './AnalyticsDashboard';
import { analyticsAPI } from '../services/api';

jest.mock('../services/api');
jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);
jest.mock('react-chartjs-2', () => ({ Bar: () => <div data-testid="bar-chart" /> }));

describe('AnalyticsDashboard', () => {
  beforeEach(() => {
    jest.clearAllMocks();

    analyticsAPI.getFilters.mockResolvedValue({
      data: {
        locations: [{ id: 1, name: 'CDO Warehouse A', type: 'warehouse' }],
        categories: [{ id: 4, name: 'Aircon' }],
      },
    });

    analyticsAPI.getStockLevel.mockResolvedValue({
      data: {
        statsData: {
          totalStockValue: 123456,
          totalUnits: 789,
          lowStockItems: 5,
          criticalItems: 2,
        },
        stockDistribution: {
          optimal:   { count: 10, percentage: 50 },
          lowStock:  { count: 5,  percentage: 25 },
          critical:  { count: 2,  percentage: 10 },
          overstock: { count: 3,  percentage: 15 },
        },
        reorderCompliance: { rate: 65, belowReorderPoint: 7, aboveSafetyStock: 13 },
        criticalItems: [],
        overstockItems: [],
      },
    });
  });

  it('renders server-computed values rather than computing locally', async () => {
    render(<AnalyticsDashboard />);

    await waitFor(() => {
      expect(screen.getByText('₱123,456')).toBeInTheDocument();
    });

    expect(screen.getByText('789')).toBeInTheDocument();
    expect(screen.getByText('65%')).toBeInTheDocument();
    expect(analyticsAPI.getStockLevel).toHaveBeenCalledTimes(1);
  });

  it('populates the location filter from the filters endpoint', async () => {
    render(<AnalyticsDashboard />);

    await waitFor(() => {
      expect(screen.getByText('CDO Warehouse A')).toBeInTheDocument();
    });

    expect(screen.getByText('Aircon')).toBeInTheDocument();
  });
});
```

- [ ] **Step 9: Run the page test**

```bash
cd frontend && npx react-scripts test --watchAll=false --testPathPattern=AnalyticsDashboard
```

Expected: PASS, 2 tests.

- [ ] **Step 10: Commit**

```bash
git add frontend/src/admin/pages/AnalyticsDashboard.jsx frontend/src/admin/pages/AnalyticsDashboard.test.jsx
git commit -m "refactor(analytics): render dashboard from server-computed data"
```

---

## Task 11: Full verification

- [ ] **Step 1: Run the entire backend suite**

```bash
cd back-end && php artisan test
```

Expected: all green. This project had 435+ passing tests before this work; nothing may regress. If the Restock or Sets suites fail, the visibility change in Task 1 or the seeder is at fault — investigate before proceeding.

- [ ] **Step 2: Run the frontend tests**

```bash
cd frontend && npx react-scripts test --watchAll=false
```

Expected: existing tests pass.

- [ ] **Step 3: Start the app and check every tab by hand**

```bash
cd back-end && php artisan serve --port=80
```

In a second terminal:

```bash
cd frontend && npm start
```

Log in as an admin, open **Analytics Dashboard**, and confirm each item:

- [ ] The **location dropdown is populated** (it was empty before this work — there are no `branch_office` rows).
- [ ] The category dropdown is populated.
- [ ] **Stock Level:** all four distribution buckets non-zero; total stock value non-zero.
- [ ] **Movement:** the 4-week chart has bars; the consumption table has rows.
- [ ] **Predictions:** stat tiles non-zero; the restock table has rows with priority badges.
- [ ] **Profit Loss:** total loss non-zero; Damaged Units = 4, Stolen Units = 2, Pending Reports = 1.
- [ ] **Location Performance:** rows show **real location names** (`CDO Warehouse A`, `CDO Main Showroom`, `CDO Warehouse B`) — not `Unknown Location`.
- [ ] Selecting a location **narrows** the numbers instead of zeroing the page.
- [ ] The page looks visually identical to before — same cards, same layout, same styling.

- [ ] **Step 4: Confirm the endpoints are actually gated**

```bash
curl -i http://127.0.0.1/api/analytics/stock-level
```

Expected: `401 Unauthorized`. If it returns data, the middleware is not applied — fix before committing.

- [ ] **Step 5: Commit any fixes**

```bash
git add -A
git commit -m "fix(analytics): verification follow-ups"
```

---

## Task 12: Update the spec status

- [ ] **Step 1: Mark the spec implemented**

In `docs/superpowers/specs/2026-08-02-analytics-dashboard-backend-design.md`, change the `**Status:**` line to:

```markdown
**Status:** Implemented 2026-08-02 on backupV3. Plan: `docs/superpowers/plans/2026-08-02-analytics-dashboard-backend.md`
```

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/specs/2026-08-02-analytics-dashboard-backend-design.md
git commit -m "docs(spec): mark analytics dashboard design implemented"
```

---

## Notes For The Implementer

**If a tab shows zeros after Task 10,** check in this order: (1) is the seeder run? (2) `php artisan route:clear && php artisan config:clear` — this project caches aggressively and it has burned time here before; (3) browser devtools Network tab — a 403 means the permission gate is rejecting your user.

**Never widen the Profit Loss query to match on `reason`.** The whole point of keying on `adjustment_id` is that a reason that does not deduct stock is not a loss. If a future incident reason needs counting, it will already be counted correctly if it deducts stock, and correctly ignored if it does not.

**The seeder must never run in production.** It guards on `app()->environment('production')`, but do not add it to `DatabaseSeeder::run()` — it is invoked explicitly by name only.
