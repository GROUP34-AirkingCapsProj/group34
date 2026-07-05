# PO Recommendation Analytics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A backend analytics engine that produces explainable PO restock recommendations from movement history and raises suggest+approve tuning of `products.recommended_stocks` / `safety_stock` / `max_stock_level`, surfaced on a rebuilt PO Recommendation page.

**Architecture:** A pure-computation service (`RestockAnalyticsService`) reads 90 days of issuance+sales demand, PO→receiving lead times, org-wide stock, and open-PO quantities; it persists a batch into the existing (unused) `restock_recommendations` table and upserts pending rows into a new `stock_parameter_suggestions` table. An artisan command (`analytics:restock`, scheduled daily) and a `RestockRecommendationController` (list / recalculate / create-draft-PR / apply / dismiss) sit on top. The frontend page is rebuilt with two tabs on the `ak-` design system.

**Tech Stack:** Laravel 11 (back-end/), MariaDB 10.4, React CRA (frontend/), Sanctum auth + DB-driven `permission:feature,action` middleware, PHPUnit feature tests with `DatabaseTransactions` against the `airkingV5_test` DB.

**Spec:** `docs/superpowers/specs/2026-07-06-po-recommendation-analytics-design.md`

---

## Codebase facts you need (read this first)

- **Schema is a SQL dump, not migrations.** NEVER run blanket `php artisan migrate` (it fails). Apply single migrations with `--path=...`, once against the dev DB (`airkingv5`) and once against the test DB (`airkingV5_test`).
- Tests: `use Illuminate\Foundation\Testing\DatabaseTransactions;` (never RefreshDatabase). `phpunit.xml` points at `airkingV5_test`. Run with `php artisan test --filter=Name` from `back-end/`.
- Primary keys are non-standard: `products.product_id`, `locations.location_id`, `purchase_requests.pr_id`, etc. Models set `$primaryKey` explicitly.
- `App\Traits\ApiResponse` gives controllers `$this->success($data, $msg, $code=200)`, `$this->error($msg, $code)`, `$this->notFound($msg)`, `$this->validationError($errors)`, `$this->paginated(...)`.
- Route gating: `Route::middleware('permission:feature,action')` (see `back-end/routes/api.php`). Permissions live in DB, seeded by `PermissionSeeder` (matrix of feature→action→role slugs) + `RolePermissionsSeeder` (both idempotent).
- Status lookup ids used here: products Inactive=4; PR Pending=13; PO Pending=7, Approved=8, Authorized=10, Completed=11, Partially Received=12; issuance Voided=32.
- `products.recommended_stocks` (note the plural **s**), `safety_stock` (nullable), `max_stock_level` (nullable). All already in `Product::$fillable` and `$casts`.
- Demand sources: `issuance_details` (join `issuances` on `issuance_id`, date = `issuances.issuance_date`; consumables' true outflow is `quantity_in_pieces`, appliances' is `quantity_issued`) and `sales_details` (join `sales`, date = `COALESCE(sale_date, order_date, DATE(created_at))`, qty = `quantity`).
- Existing empty table `restock_recommendations` (PK `recommendation_id`) will be extended; it already has `product_id`, `location_id`, `current_stock`, `reorder_level`, `recommended_quantity`, `reason`, `status_id`, timestamps.
- Frontend: `frontend/src/admin/services/api.js` exports `xxxAPI` objects built on `apiRequest(path, opts)`. Toast: `import { toast } from '../utils/toast'` → `toast.success(title, subtitle)` / `toast.error(...)`. Confirm: `import { confirmAction } from '../utils/confirm'` → `await confirmAction({ title, text, confirmText, tone })` returns boolean.
- The page `frontend/src/admin/pages/PORecommendation.jsx` is routed at `/admin/po-recommendations` (App.jsx, Sidebar.jsx, roles.js already allow ADMIN, INVENTORY_ANALYST, BRANCH_MANAGER, AUDITOR). We replace the file's contents entirely.
- PowerShell is the shell. To run an artisan command against the test DB: `$env:DB_DATABASE='airkingV5_test'; php artisan migrate --path=... --force; Remove-Item Env:DB_DATABASE`.

Engine math and thresholds are fixed by the spec — do not re-derive them:
windows 30/90 days; trend fast ≥1.3, slow ≤0.7; z=1.65; default lead 7 days clamped 1–30; sparse fallback below 5 demand-days; suggestion drift gate >20% and ≥1 unit; NO_MOVEMENT halves current values; HIGH_TURNOVER = ≥1 receiving in window AND 90-day demand ≥ 3× max(1, on-hand).

---

### Task 1: Migrations + models for both tables

**Files:**
- Create: `back-end/database/migrations/2026_07_06_000001_extend_restock_recommendations.php`
- Create: `back-end/database/migrations/2026_07_06_000002_create_stock_parameter_suggestions_table.php`
- Create: `back-end/app/Models/RestockRecommendation.php`
- Create: `back-end/app/Models/StockParameterSuggestion.php`

- [ ] **Step 1: Write the extend-restock_recommendations migration**

`back-end/database/migrations/2026_07_06_000001_extend_restock_recommendations.php`:

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
            $table->string('priority', 10)->default('MEDIUM')->after('reason');
            $table->json('reason_codes')->nullable()->after('priority');
            $table->decimal('avg_daily_demand', 10, 3)->default(0)->after('reason_codes');
            $table->decimal('demand_trend', 6, 3)->nullable()->after('avg_daily_demand');
            $table->decimal('days_of_cover', 8, 1)->nullable()->after('demand_trend');
            $table->decimal('lead_time_days', 6, 1)->default(7)->after('days_of_cover');
            $table->integer('on_order_quantity')->default(0)->after('lead_time_days');
            $table->timestamp('run_at')->nullable()->after('on_order_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('restock_recommendations', function (Blueprint $table) {
            $table->dropColumn([
                'priority', 'reason_codes', 'avg_daily_demand', 'demand_trend',
                'days_of_cover', 'lead_time_days', 'on_order_quantity', 'run_at',
            ]);
        });
    }
};
```

- [ ] **Step 2: Write the stock_parameter_suggestions migration**

`back-end/database/migrations/2026_07_06_000002_create_stock_parameter_suggestions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_parameter_suggestions', function (Blueprint $table) {
            $table->bigIncrements('suggestion_id');
            $table->unsignedBigInteger('product_id');
            $table->integer('current_recommended')->default(0);
            $table->integer('suggested_recommended')->default(0);
            $table->integer('current_safety')->nullable();
            $table->integer('suggested_safety')->nullable();
            $table->integer('current_max')->nullable();
            $table->integer('suggested_max')->nullable();
            $table->json('reason_codes')->nullable();
            $table->string('summary', 255)->nullable();
            $table->string('status', 20)->default('pending'); // pending | applied | dismissed
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('run_at')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('product_id')->on('products')->cascadeOnDelete();
            $table->foreign('reviewed_by')->references('user_id')->on('users')->nullOnDelete();
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_parameter_suggestions');
    }
};
```

- [ ] **Step 3: Apply both migrations to the dev DB and the test DB**

From `back-end/` (PowerShell):

```powershell
php artisan migrate --path=database/migrations/2026_07_06_000001_extend_restock_recommendations.php --force
php artisan migrate --path=database/migrations/2026_07_06_000002_create_stock_parameter_suggestions_table.php --force
$env:DB_DATABASE='airkingV5_test'
php artisan migrate --path=database/migrations/2026_07_06_000001_extend_restock_recommendations.php --force
php artisan migrate --path=database/migrations/2026_07_06_000002_create_stock_parameter_suggestions_table.php --force
Remove-Item Env:DB_DATABASE
```

Expected: each prints `DONE`. If XAMPP MariaDB isn't running, start it first (see memory `project_xampp_db_recovery`).

- [ ] **Step 4: Write the two models**

`back-end/app/Models/RestockRecommendation.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestockRecommendation extends Model
{
    protected $table = 'restock_recommendations';
    protected $primaryKey = 'recommendation_id';
    protected $guarded = [];

    protected $casts = [
        'reason_codes'     => 'array',
        'run_at'           => 'datetime',
        'avg_daily_demand' => 'decimal:3',
        'demand_trend'     => 'decimal:3',
        'days_of_cover'    => 'decimal:1',
        'lead_time_days'   => 'decimal:1',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }
}
```

`back-end/app/Models/StockParameterSuggestion.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockParameterSuggestion extends Model
{
    protected $table = 'stock_parameter_suggestions';
    protected $primaryKey = 'suggestion_id';
    protected $guarded = [];

    protected $casts = [
        'reason_codes' => 'array',
        'run_at'       => 'datetime',
        'reviewed_at'  => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'user_id');
    }
}
```

- [ ] **Step 5: Commit**

```powershell
git add back-end/database/migrations/2026_07_06_000001_extend_restock_recommendations.php back-end/database/migrations/2026_07_06_000002_create_stock_parameter_suggestions_table.php back-end/app/Models/RestockRecommendation.php back-end/app/Models/StockParameterSuggestion.php
git commit -m "feat(analytics): restock recommendation + parameter suggestion schema and models"
```

---

### Task 2: RestockAnalyticsService::analyze() — the engine

**Files:**
- Create: `back-end/app/Services/Analytics/RestockAnalyticsService.php`
- Test: `back-end/tests/Feature/Restock/RestockAnalyticsServiceTest.php`

The test seeds movement history with raw `DB::table(...)` inserts (models have strict fillables and we only need rows). All expected numbers below were hand-computed from the spec formulas — do not "fix" them to match a different implementation; fix the implementation.

- [ ] **Step 1: Write the failing tests**

`back-end/tests/Feature/Restock/RestockAnalyticsServiceTest.php`:

```php
<?php

namespace Tests\Feature\Restock;

use App\Services\Analytics\RestockAnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RestockAnalyticsServiceTest extends TestCase
{
    use DatabaseTransactions;

    private RestockAnalyticsService $service;
    private Carbon $asOf;
    private int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RestockAnalyticsService::class);
        $this->asOf    = Carbon::parse('2026-07-06')->startOfDay();
        $this->warehouseId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Test Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeProduct(array $overrides = []): int
    {
        return (int) DB::table('products')->insertGetId(array_merge([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Test Product',
            'product_type'       => 'consumable',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 0,
            'safety_stock'       => null,
            'max_stock_level'    => null,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], $overrides), 'product_id');
    }

    private function setStock(int $productId, int $qty): void
    {
        DB::table('inventory')->insert([
            'location_id'      => $this->warehouseId,
            'product_id'       => $productId,
            'quantity_on_hand' => $qty,
            'available_quantity' => $qty,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    /** One issuance of $qty pieces per day for $days days ending at asOf. */
    private function issueDaily(int $productId, int $qty, int $days): void
    {
        for ($i = 0; $i < $days; $i++) {
            $date = $this->asOf->copy()->subDays($i)->toDateString();
            $issuanceId = DB::table('issuances')->insertGetId([
                'location_id'   => $this->warehouseId,
                'issuance_date' => $date,
                'issuance_type' => 'Operations',
                'created_at'    => now(),
                'updated_at'    => now(),
            ], 'issuance_id');
            DB::table('issuance_details')->insert([
                'issuance_id'        => $issuanceId,
                'product_id'         => $productId,
                'quantity_issued'    => $qty,
                'quantity_in_pieces' => $qty,
                'unit_price'         => 100,
                'subtotal'           => 100 * $qty,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }
    }

    private function findRec(array $result, int $productId): ?array
    {
        foreach ($result['recommendations'] as $rec) {
            if ((int) $rec['product_id'] === $productId) return $rec;
        }
        return null;
    }

    private function findSug(array $result, int $productId): ?array
    {
        foreach ($result['suggestions'] as $sug) {
            if ((int) $sug['product_id'] === $productId) return $sug;
        }
        return null;
    }

    // ---------------------------------------------------------------
    // (A) Fast mover: velocity, trend, safety stock, priority, reasons
    // ---------------------------------------------------------------
    public function test_fast_mover_gets_critical_recommendation_and_upward_suggestion(): void
    {
        // 2 pieces/day for the last 30 days only: v30=2.0, v90=0.6667, trend=3.0
        // sigma = 0.9428 -> safety = ceil(1.65*0.9428*sqrt(7)) = 5
        // reorder = ceil(2*7)+5 = 19 ; target = ceil(30*2)+5 = 65
        $pid = $this->makeProduct(['recommended_stocks' => 5, 'safety_stock' => 2, 'max_stock_level' => 10]);
        $this->setStock($pid, 4);
        $this->issueDaily($pid, 2, 30);

        $result = $this->service->analyze($this->asOf);

        $rec = $this->findRec($result, $pid);
        $this->assertNotNull($rec, 'fast mover must be recommended');
        $this->assertSame('CRITICAL', $rec['priority']);          // cover 2.0 < lead/2 = 3.5
        $this->assertSame(61, $rec['recommended_quantity']);      // 65 - 4 - 0
        $this->assertSame(19, $rec['reorder_level']);
        $this->assertSame(4, $rec['current_stock']);
        $this->assertEqualsWithDelta(2.0, (float) $rec['avg_daily_demand'], 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $rec['demand_trend'], 0.001);
        $this->assertEqualsWithDelta(2.0, (float) $rec['days_of_cover'], 0.1);
        $this->assertEqualsWithDelta(7.0, (float) $rec['lead_time_days'], 0.001); // no PO history -> default
        foreach (['BELOW_SAFETY_STOCK', 'STOCKOUT_RISK', 'LOW_STOCK', 'FAST_MOVING'] as $code) {
            $this->assertContains($code, $rec['reason_codes']);
        }
        $this->assertNotContains('OUT_OF_STOCK', $rec['reason_codes']);

        $sug = $this->findSug($result, $pid);
        $this->assertNotNull($sug, 'drifted parameters must raise a suggestion');
        $this->assertSame(19, $sug['suggested_recommended']);
        $this->assertSame(5, $sug['suggested_safety']);
        $this->assertSame(65, $sug['suggested_max']);
        $this->assertSame(5, $sug['current_recommended']);
        $this->assertContains('FAST_MOVING', $sug['reason_codes']);
    }

    // ---------------------------------------------------------------
    // (B) Lead time measured from PO -> first receiving
    // ---------------------------------------------------------------
    public function test_lead_time_is_measured_from_po_history(): void
    {
        // Completed PO ordered 10 days ago, first receiving 4 days ago -> lead 6
        // Demand 1/day for 30 days: v30=1, sigma=0.4714 -> safety=ceil(1.65*0.4714*sqrt(6))=2
        // reorder = ceil(1*6)+2 = 8
        $pid = $this->makeProduct();
        $this->setStock($pid, 3);
        $this->issueDaily($pid, 1, 30);

        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number'  => 'PO-TST-' . uniqid(),
            'order_date' => $this->asOf->copy()->subDays(10)->toDateString(),
            'status_id'  => 11, // Completed -> NOT open, so it must not count as on-order
            'created_at' => now(),
            'updated_at' => now(),
        ], 'po_id');
        DB::table('purchase_order_details')->insert([
            'po_id' => $poId, 'product_id' => $pid, 'quantity_ordered' => 5,
            'unit_price' => 80, 'subtotal' => 400, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('receivings')->insert([
            'po_id' => $poId, 'location_id' => $this->warehouseId,
            'receiving_number' => 'RCV-TST-' . uniqid(),
            'receiving_date'   => $this->asOf->copy()->subDays(4)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service->analyze($this->asOf);
        $rec = $this->findRec($result, $pid);

        $this->assertNotNull($rec);
        $this->assertEqualsWithDelta(6.0, (float) $rec['lead_time_days'], 0.001);
        $this->assertSame(8, $rec['reorder_level']);
        $this->assertSame(0, $rec['on_order_quantity']);
    }

    // ---------------------------------------------------------------
    // (C) Open PO quantity is subtracted from the recommendation
    // ---------------------------------------------------------------
    public function test_open_po_quantity_reduces_recommended_quantity(): void
    {
        // Same demand as (B) but no completed PO -> lead defaults to 7
        // safety = ceil(1.65*0.4714*sqrt(7)) = ceil(2.058) = 3
        // reorder = 7+3 = 10 ; target = 30+3 = 33
        // open PO of 10 -> qty = 33 - 3 - 10 = 20
        $pid = $this->makeProduct();
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
            'po_id' => $poId, 'product_id' => $pid, 'quantity_ordered' => 10,
            'unit_price' => 80, 'subtotal' => 800, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service->analyze($this->asOf);
        $rec = $this->findRec($result, $pid);

        $this->assertNotNull($rec);
        $this->assertSame(10, $rec['on_order_quantity']);
        $this->assertSame(20, $rec['recommended_quantity']);
    }

    // ---------------------------------------------------------------
    // (D) Sales count as demand too
    // ---------------------------------------------------------------
    public function test_sales_details_count_toward_demand(): void
    {
        $pid = $this->makeProduct();
        $this->setStock($pid, 0);
        // 3 units sold on each of the last 10 days: total30 = 30 -> v30 = 1.0
        for ($i = 0; $i < 10; $i++) {
            $salesId = DB::table('sales')->insertGetId([
                'invoice_number' => 'INV-TST-' . uniqid(),
                'sale_date'      => $this->asOf->copy()->subDays($i)->toDateString(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ], 'sales_id');
            DB::table('sales_details')->insert([
                'sales_id' => $salesId, 'product_id' => $pid, 'quantity' => 3,
                'unit_price' => 100, 'subtotal' => 300, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $result = $this->service->analyze($this->asOf);
        $rec = $this->findRec($result, $pid);

        $this->assertNotNull($rec);
        $this->assertEqualsWithDelta(1.0, (float) $rec['avg_daily_demand'], 0.001);
        $this->assertContains('OUT_OF_STOCK', $rec['reason_codes']);
        $this->assertSame('CRITICAL', $rec['priority']);
    }

    // ---------------------------------------------------------------
    // (E) No movement: halving suggestion; no-history+no-params skipped
    // ---------------------------------------------------------------
    public function test_no_movement_product_gets_halving_suggestion(): void
    {
        $pid = $this->makeProduct(['recommended_stocks' => 20, 'safety_stock' => 10, 'max_stock_level' => 40]);
        $this->setStock($pid, 50); // plenty of stock, no demand

        $result = $this->service->analyze($this->asOf);

        $this->assertNull($this->findRec($result, $pid), 'well-stocked product must not be recommended');
        $sug = $this->findSug($result, $pid);
        $this->assertNotNull($sug);
        $this->assertSame(['NO_MOVEMENT'], $sug['reason_codes']);
        $this->assertSame(10, $sug['suggested_recommended']);
        $this->assertSame(5, $sug['suggested_safety']);
        $this->assertSame(20, $sug['suggested_max']);
    }

    public function test_product_with_no_history_and_no_params_is_skipped(): void
    {
        $pid = $this->makeProduct(); // params all 0/null, no stock row, no movement
        $result = $this->service->analyze($this->asOf);
        $this->assertNull($this->findRec($result, $pid));
        $this->assertNull($this->findSug($result, $pid));
    }

    public function test_inactive_product_is_skipped(): void
    {
        $pid = $this->makeProduct(['status_id' => 4, 'recommended_stocks' => 10]);
        $this->setStock($pid, 0);
        $result = $this->service->analyze($this->asOf);
        $this->assertNull($this->findRec($result, $pid));
        $this->assertNull($this->findSug($result, $pid));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

From `back-end/`: `php artisan test --filter=RestockAnalyticsServiceTest`

Expected: FAIL — `Class "App\Services\Analytics\RestockAnalyticsService" not found`.

- [ ] **Step 3: Implement the engine**

`back-end/app/Services/Analytics/RestockAnalyticsService.php`:

```php
<?php

namespace App\Services\Analytics;

use App\Models\Location;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rule-based restock analytics (spec: docs/superpowers/specs/
 * 2026-07-06-po-recommendation-analytics-design.md).
 *
 * analyze() is a pure read: it derives demand velocity, lead times and
 * stock-parameter targets, and returns recommendation + suggestion arrays.
 * Persistence lives in run()/persist() (Task 3).
 */
class RestockAnalyticsService
{
    public const WINDOW_LONG_DAYS   = 90;
    public const WINDOW_SHORT_DAYS  = 30;
    public const DEFAULT_LEAD_TIME  = 7.0;
    public const LEAD_TIME_MIN      = 1.0;
    public const LEAD_TIME_MAX      = 30.0;
    public const SERVICE_FACTOR     = 1.65; // ~95% service level
    public const TREND_FAST         = 1.3;
    public const TREND_SLOW         = 0.7;
    public const SUGGEST_MIN_PCT    = 0.20;
    public const SPARSE_DEMAND_DAYS = 5;
    public const LEAD_TIME_SAMPLE   = 5;    // most recent POs per product

    /** status_lookup: PO statuses with deliveries still outstanding. */
    public const OPEN_PO_STATUSES = [7, 8, 10, 12];
    /** status_lookup: voided issuances are not demand. */
    public const ISSUANCE_VOIDED_STATUS = 32;
    /** status_lookup: inactive product. */
    public const PRODUCT_INACTIVE_STATUS = 4;

    public function analyze(?Carbon $asOf = null): array
    {
        $asOf   = ($asOf ?? now())->copy()->startOfDay();
        $from90 = $asOf->copy()->subDays(self::WINDOW_LONG_DAYS - 1);
        $from30 = $asOf->copy()->subDays(self::WINDOW_SHORT_DAYS - 1)->toDateString();

        $daily      = $this->dailyDemand($from90->toDateString(), $asOf->toDateString());
        $stock      = $this->stockOnHand();
        $onOrder    = $this->openPoQuantities();
        $leads      = $this->leadTimes();
        $received90 = $this->receivedQuantities($from90->toDateString());

        $warehouseId = Location::query()
            ->where('location_type', 'warehouse')
            ->orderBy('location_id')
            ->value('location_id');

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

            $onHand = (int) ($stock[$pid] ?? 0);
            $open   = (int) ($onOrder[$pid] ?? 0);
            $cover  = $v30 > 0 ? round($onHand / $v30, 1) : null;

            // Current product values act as a floor so hand-tuned thresholds keep working.
            $effectiveSafety  = max($computedSafety, (int) ($currentSafety ?? 0));
            $effectiveReorder = max($computedReorder, $currentRecommended);
            $effectiveTarget  = max($computedTarget, (int) ($currentMax ?? 0), $currentRecommended);

            // ---- Restock recommendation --------------------------------
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

            if ($triggered && $qty > 0) {
                $priority = 'MEDIUM';
                if ($onHand <= 0 || ($cover !== null && $cover < $lead / 2)) {
                    $priority = 'CRITICAL';
                } elseif (($cover !== null && $cover < $lead)
                    || ($effectiveSafety > 0 && $onHand < $effectiveSafety)) {
                    $priority = 'HIGH';
                }

                $recommendations[] = [
                    'product_id'           => $pid,
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

            // ---- Parameter suggestion -----------------------------------
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
                    'summary'               => $this->summarize($v30, $trend, $cover, $lead, $onHand),
                ];
            }
        }

        return [
            'run_at'                => $asOf,
            'warehouse_location_id' => $warehouseId ? (int) $warehouseId : null,
            'recommendations'       => $recommendations,
            'suggestions'           => $suggestions,
        ];
    }

    // -------------------------------------------------------------------
    // Data gathering
    // -------------------------------------------------------------------

    /** @return array<int, array<string, float>> product_id => [Y-m-d => qty out] */
    private function dailyDemand(string $from, string $to): array
    {
        $daily = [];

        $issuances = DB::table('issuance_details as d')
            ->join('issuances as i', 'i.issuance_id', '=', 'd.issuance_id')
            ->join('products as p', 'p.product_id', '=', 'd.product_id')
            ->whereBetween('i.issuance_date', [$from, $to])
            ->where(fn ($q) => $q->whereNull('i.status_id')
                ->orWhere('i.status_id', '!=', self::ISSUANCE_VOIDED_STATUS))
            ->groupBy('d.product_id', 'i.issuance_date')
            ->selectRaw(
                "d.product_id, i.issuance_date as day,
                 SUM(CASE WHEN p.product_type = 'consumable' AND d.quantity_in_pieces > 0
                          THEN d.quantity_in_pieces ELSE d.quantity_issued END) as qty"
            )
            ->get();

        foreach ($issuances as $row) {
            $day = (string) $row->day;
            $daily[(int) $row->product_id][$day] =
                ($daily[(int) $row->product_id][$day] ?? 0) + (float) $row->qty;
        }

        $sales = DB::table('sales_details as sd')
            ->join('sales as s', 's.sales_id', '=', 'sd.sales_id')
            ->whereRaw('COALESCE(s.sale_date, s.order_date, DATE(s.created_at)) BETWEEN ? AND ?', [$from, $to])
            ->groupBy('sd.product_id', DB::raw('COALESCE(s.sale_date, s.order_date, DATE(s.created_at))'))
            ->selectRaw(
                'sd.product_id,
                 COALESCE(s.sale_date, s.order_date, DATE(s.created_at)) as day,
                 SUM(sd.quantity) as qty'
            )
            ->get();

        foreach ($sales as $row) {
            $day = (string) $row->day;
            $daily[(int) $row->product_id][$day] =
                ($daily[(int) $row->product_id][$day] ?? 0) + (float) $row->qty;
        }

        return $daily;
    }

    /** @return array<int, int> product_id => org-wide quantity_on_hand */
    private function stockOnHand(): array
    {
        return DB::table('inventory')
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity_on_hand) as qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($q) => (int) $q)
            ->all();
    }

    /** @return array<int, int> product_id => open-PO qty not yet received */
    private function openPoQuantities(): array
    {
        $ordered = DB::table('purchase_order_details as pod')
            ->join('purchase_orders as po', 'po.po_id', '=', 'pod.po_id')
            ->whereIn('po.status_id', self::OPEN_PO_STATUSES)
            ->whereNull('po.deleted_at')
            ->groupBy('pod.product_id')
            ->selectRaw('pod.product_id, SUM(pod.quantity_ordered) as qty')
            ->pluck('qty', 'product_id');

        $received = DB::table('receiving_details as rd')
            ->join('receivings as r', 'r.receiving_id', '=', 'rd.receiving_id')
            ->join('purchase_orders as po', 'po.po_id', '=', 'r.po_id')
            ->whereIn('po.status_id', self::OPEN_PO_STATUSES)
            ->whereNull('po.deleted_at')
            ->groupBy('rd.product_id')
            ->selectRaw('rd.product_id, SUM(rd.quantity_amount) as qty')
            ->pluck('qty', 'product_id');

        $open = [];
        foreach ($ordered as $pid => $qty) {
            $open[(int) $pid] = max(0, (int) $qty - (int) ($received[$pid] ?? 0));
        }
        return $open;
    }

    /**
     * @return array{product: array<int,float>, supplier: array<int,float>, productSupplier: array<int,int>}
     */
    private function leadTimes(): array
    {
        $rows = DB::table('purchase_order_details as pod')
            ->join('purchase_orders as po', 'po.po_id', '=', 'pod.po_id')
            ->join('receivings as r', 'r.po_id', '=', 'po.po_id')
            ->whereNotNull('po.order_date')
            ->whereNotNull('r.receiving_date')
            ->groupBy('pod.product_id', 'po.po_id', 'po.supplier_id', 'po.order_date')
            ->selectRaw('pod.product_id, po.po_id, po.supplier_id, po.order_date,
                         MIN(r.receiving_date) as first_received')
            ->orderByDesc('po.order_date')
            ->get();

        $perProduct = [];
        $perSupplier = [];
        $productSupplier = [];

        foreach ($rows as $row) {
            $days = max(0, Carbon::parse($row->order_date)
                ->diffInDays(Carbon::parse($row->first_received), false));
            $pid = (int) $row->product_id;

            if (count($perProduct[$pid] ?? []) < self::LEAD_TIME_SAMPLE) {
                $perProduct[$pid][] = $days;
            }
            if ($row->supplier_id !== null) {
                $perSupplier[(int) $row->supplier_id][] = $days;
                $productSupplier[$pid] = $productSupplier[$pid] ?? (int) $row->supplier_id;
            }
        }

        $avg = fn (array $set) => array_map(
            fn ($vals) => array_sum($vals) / count($vals), $set
        );

        return [
            'product'         => $avg($perProduct),
            'supplier'        => $avg($perSupplier),
            'productSupplier' => $productSupplier,
        ];
    }

    /** @return array<int, float> product_id => qty received in window */
    private function receivedQuantities(string $from): array
    {
        return DB::table('receiving_details as rd')
            ->join('receivings as r', 'r.receiving_id', '=', 'rd.receiving_id')
            ->where('r.receiving_date', '>=', $from)
            ->groupBy('rd.product_id')
            ->selectRaw('rd.product_id, SUM(rd.quantity_amount) as qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($q) => (float) $q)
            ->all();
    }

    // -------------------------------------------------------------------
    // Math helpers
    // -------------------------------------------------------------------

    /** Population std dev of the daily series over $n days (absent days = 0). */
    private function dailyStdDev(array $series, int $n): float
    {
        if ($n <= 0) {
            return 0.0;
        }
        $mean = array_sum($series) / $n;
        $ss   = 0.0;
        foreach ($series as $qty) {
            $ss += ($qty - $mean) ** 2;
        }
        $ss += ($n - count($series)) * $mean ** 2;
        return sqrt($ss / $n);
    }

    /** Spec drift gate: >20% relative difference AND at least 1 unit. */
    private function differs(int $current, int $suggested): bool
    {
        if (abs($suggested - $current) < 1) {
            return false;
        }
        if ($current === 0) {
            return $suggested >= 1;
        }
        return abs($suggested - $current) / $current > self::SUGGEST_MIN_PCT;
    }

    private function summarize(float $v30, ?float $trend, ?float $cover, float $lead, int $onHand): string
    {
        if ($v30 <= 0) {
            return $onHand <= 0
                ? 'Out of stock with no demand in the last 90 days.'
                : 'No demand in the last 90 days.';
        }

        $trendTxt = '';
        if ($trend !== null && $trend >= self::TREND_FAST) {
            $trendTxt = sprintf(' (up %d%% vs the 90-day average)', (int) round(($trend - 1) * 100));
        } elseif ($trend !== null && $trend <= self::TREND_SLOW) {
            $trendTxt = sprintf(' (down %d%% vs the 90-day average)', (int) round((1 - $trend) * 100));
        }

        return sprintf(
            'Demand is %.1f/day%s; stock covers %s day(s), delivery takes about %.0f day(s).',
            $v30,
            $trendTxt,
            $cover !== null ? number_format($cover, 1) : 'unlimited',
            $lead
        );
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

`php artisan test --filter=RestockAnalyticsServiceTest`

Expected: all 7 tests PASS. If a math assertion fails by exactly ±1 on `safety`/`reorder`, check float noise in `ceil()` (e.g. `ceil(1.65 * σ * sqrt(7))`) — the implementation must round the *product* only once, exactly as written above; do not add extra rounding.

- [ ] **Step 5: Commit**

```powershell
git add back-end/app/Services/Analytics/RestockAnalyticsService.php back-end/tests/Feature/Restock/RestockAnalyticsServiceTest.php
git commit -m "feat(analytics): rule-based restock analytics engine (analyze)"
```

---

### Task 3: run()/persist() — store batches, upsert suggestions

**Files:**
- Modify: `back-end/app/Services/Analytics/RestockAnalyticsService.php` (append two methods + two imports)
- Test: `back-end/tests/Feature/Restock/RestockAnalyticsServiceTest.php` (append tests)

- [ ] **Step 1: Append the failing tests**

Append inside `RestockAnalyticsServiceTest` (before the closing brace):

```php
    // ---------------------------------------------------------------
    // (F) Persistence
    // ---------------------------------------------------------------
    public function test_run_persists_recommendations_and_replaces_previous_batch(): void
    {
        $pid = $this->makeProduct(['recommended_stocks' => 5]);
        $this->setStock($pid, 1);
        $this->issueDaily($pid, 2, 30);

        $this->service->run($this->asOf);

        $this->assertDatabaseHas('restock_recommendations', ['product_id' => $pid]);
        $first = DB::table('restock_recommendations')->where('product_id', $pid)->first();
        $this->assertNotNull($first->run_at);
        $this->assertSame($this->warehouseId, (int) $first->location_id);

        // Second run replaces the batch (no duplicate rows)
        $this->service->run($this->asOf);
        $this->assertSame(
            1,
            DB::table('restock_recommendations')->where('product_id', $pid)->count()
        );
    }

    public function test_pending_suggestion_is_updated_not_duplicated(): void
    {
        $pid = $this->makeProduct(['recommended_stocks' => 5, 'safety_stock' => 2, 'max_stock_level' => 10]);
        $this->setStock($pid, 4);
        $this->issueDaily($pid, 2, 30);

        $this->service->run($this->asOf);
        $this->service->run($this->asOf);

        $this->assertSame(
            1,
            DB::table('stock_parameter_suggestions')
                ->where('product_id', $pid)->where('status', 'pending')->count()
        );
    }

    public function test_dismissed_suggestion_with_same_values_is_not_re_raised(): void
    {
        $pid = $this->makeProduct(['recommended_stocks' => 5, 'safety_stock' => 2, 'max_stock_level' => 10]);
        $this->setStock($pid, 4);
        $this->issueDaily($pid, 2, 30);

        $this->service->run($this->asOf);
        DB::table('stock_parameter_suggestions')
            ->where('product_id', $pid)->where('status', 'pending')
            ->update(['status' => 'dismissed', 'reviewed_at' => now()]);

        $this->service->run($this->asOf);

        $this->assertSame(
            0,
            DB::table('stock_parameter_suggestions')
                ->where('product_id', $pid)->where('status', 'pending')->count()
        );
    }

    public function test_stale_pending_suggestions_are_removed_when_no_longer_warranted(): void
    {
        $pid = $this->makeProduct(['recommended_stocks' => 20]);
        DB::table('stock_parameter_suggestions')->insert([
            'product_id'            => $pid,
            'current_recommended'   => 20,
            'suggested_recommended' => 40,
            'status'                => 'pending',
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
        $this->setStock($pid, 100);
        $this->issueDaily($pid, 1, 90); // steady demand -> computed values close to current? No:
        // steady 1/day: reorder = ceil(7)+SS. Whatever the suggestion outcome, the OLD pending
        // row (suggested 40) must be replaced by the engine's own values or deleted -- never
        // left stale. We assert on count + freshness, not on exact values.

        $this->service->run($this->asOf);

        $pending = DB::table('stock_parameter_suggestions')
            ->where('product_id', $pid)->where('status', 'pending')->get();
        $this->assertLessThanOrEqual(1, $pending->count());
        if ($pending->count() === 1) {
            $this->assertNotSame(40, (int) $pending->first()->suggested_recommended);
        }
    }
```

- [ ] **Step 2: Run to verify the new tests fail**

`php artisan test --filter=RestockAnalyticsServiceTest`

Expected: FAIL — `Call to undefined method ...::run()`.

- [ ] **Step 3: Implement run() and persist()**

In `RestockAnalyticsService.php`, add imports at the top:

```php
use App\Models\RestockRecommendation;
use App\Models\StockParameterSuggestion;
```

Append these methods to the class (after `analyze()`):

```php
    /** Compute and persist in one go. Returns the analyze() result. */
    public function run(?Carbon $asOf = null): array
    {
        $result = $this->analyze($asOf);
        $this->persist($result);
        return $result;
    }

    /**
     * Replace the recommendations batch; upsert pending suggestions.
     * Applied/dismissed suggestion rows are never touched (audit trail);
     * a dismissed suggestion is only re-raised when its values change.
     */
    public function persist(array $result): void
    {
        DB::transaction(function () use ($result) {
            DB::table('restock_recommendations')->delete();
            foreach ($result['recommendations'] as $rec) {
                RestockRecommendation::create($rec + [
                    'location_id' => $result['warehouse_location_id'],
                    'run_at'      => $result['run_at'],
                ]);
            }

            $suggestedIds = array_map(fn ($s) => $s['product_id'], $result['suggestions']);
            StockParameterSuggestion::where('status', 'pending')
                ->whereNotIn('product_id', $suggestedIds ?: [0])
                ->delete();

            foreach ($result['suggestions'] as $sug) {
                $pending = StockParameterSuggestion::where('product_id', $sug['product_id'])
                    ->where('status', 'pending')
                    ->first();
                if ($pending) {
                    $pending->update($sug + ['run_at' => $result['run_at']]);
                    continue;
                }

                $lastDismissed = StockParameterSuggestion::where('product_id', $sug['product_id'])
                    ->where('status', 'dismissed')
                    ->orderByDesc('reviewed_at')
                    ->first();
                if ($lastDismissed
                    && (int) $lastDismissed->suggested_recommended === (int) $sug['suggested_recommended']
                    && (int) ($lastDismissed->suggested_safety ?? 0) === (int) ($sug['suggested_safety'] ?? 0)
                    && (int) ($lastDismissed->suggested_max ?? 0) === (int) ($sug['suggested_max'] ?? 0)) {
                    continue;
                }

                StockParameterSuggestion::create($sug + [
                    'status' => 'pending',
                    'run_at' => $result['run_at'],
                ]);
            }
        });
    }
```

- [ ] **Step 4: Run the full test class**

`php artisan test --filter=RestockAnalyticsServiceTest`

Expected: all 11 tests PASS.

- [ ] **Step 5: Commit**

```powershell
git add back-end/app/Services/Analytics/RestockAnalyticsService.php back-end/tests/Feature/Restock/RestockAnalyticsServiceTest.php
git commit -m "feat(analytics): persist restock batches and upsert parameter suggestions"
```

---

### Task 4: `analytics:restock` command + daily schedule

**Files:**
- Modify: `back-end/routes/console.php` (append at end)

- [ ] **Step 1: Register the command and schedule**

Append to `back-end/routes/console.php`:

```php
use App\Services\Analytics\RestockAnalyticsService;
use Illuminate\Support\Facades\Schedule;

Artisan::command('analytics:restock', function (RestockAnalyticsService $service) {
    $result = $service->run();
    $this->info(sprintf(
        'Restock analytics complete: %d recommendation(s), %d parameter suggestion(s).',
        count($result['recommendations']),
        count($result['suggestions'])
    ));
    return 0;
})->purpose('Recompute PO restock recommendations and stock parameter suggestions');

Schedule::command('analytics:restock')->dailyAt('06:00');
```

Note: `use` statements must physically sit with the other imports at the top of the file, not mid-file — move them up beside the existing `use` block.

- [ ] **Step 2: Run it against the dev DB and verify output**

From `back-end/`:

```powershell
php artisan analytics:restock
php artisan schedule:list
```

Expected: `Restock analytics complete: N recommendation(s), M parameter suggestion(s).` with N/M ≥ 0 and no exception; `schedule:list` shows `analytics:restock` at `0 6 * * *`. Spot-check: `php artisan tinker --execute="dump(DB::table('restock_recommendations')->count());"`

- [ ] **Step 3: Commit**

```powershell
git add back-end/routes/console.php
git commit -m "feat(analytics): analytics:restock command with daily schedule"
```

---

### Task 5: Permission + read/recalculate endpoints

**Files:**
- Modify: `back-end/database/seeders/PermissionSeeder.php` (add feature to matrix)
- Create: `back-end/app/Http/Controllers/Api/RestockRecommendationController.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/Restock/RestockEndpointsTest.php`

- [ ] **Step 1: Add `po_recommendations` to the permission matrix**

In `back-end/database/seeders/PermissionSeeder.php`, inside `matrix()`, after the `'purchase_request_approval'` entry, add:

```php
            // PO Recommendation analytics (page is at /admin/po-recommendations).
            // create = recalculate + create draft PR; update = apply/dismiss
            // stock parameter suggestions (edits product master data).
            'po_recommendations' => [
                'read'   => ['admin', 'inventory_analyst', 'branch_manager', 'auditor'],
                'create' => ['admin', 'inventory_analyst'],
                'update' => ['admin', 'inventory_analyst'],
            ],
```

- [ ] **Step 2: Seed the dev DB (tests seed themselves in setUp)**

```powershell
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=RolePermissionsSeeder
```

Expected: `Permissions seeded.` / `Role permissions seeded.` (both idempotent).

- [ ] **Step 3: Write the failing endpoint tests**

`back-end/tests/Feature/Restock/RestockEndpointsTest.php`:

```php
<?php

namespace Tests\Feature\Restock;

use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RestockEndpointsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'admin'               => 'System Administrator',
            'inventory_analyst'   => 'Inventory Analyst',
            'branch_manager'      => 'Branch Manager',
            'warehouse_personnel' => 'Warehouse Personnel',
            'auditor'             => 'Auditor',
        ] as $name => $desc) {
            Role::firstOrCreate(['role_name' => $name], ['description' => $desc]);
        }
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionsSeeder::class);

        DB::table('locations')->insert([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Test Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function makeUser(string $roleSlug): User
    {
        $role = Role::where('role_name', $roleSlug)->firstOrFail();
        $statusId = StatusLookup::query()->value('status_id')
            ?? StatusLookup::forceCreate(['status_name' => 'Active', 'status_category' => 'user'])->status_id;

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

    private function makeProductWithDemand(): int
    {
        $pid = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Endpoint Test Product',
            'product_type'       => 'consumable',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 5,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');

        DB::table('inventory')->insert([
            'location_id'        => (int) DB::table('locations')->where('location_type', 'warehouse')->value('location_id'),
            'product_id'         => $pid,
            'quantity_on_hand'   => 1,
            'available_quantity' => 1,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $issuanceId = DB::table('issuances')->insertGetId([
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'Operations',
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'issuance_id');
        DB::table('issuance_details')->insert([
            'issuance_id'        => $issuanceId,
            'product_id'         => $pid,
            'quantity_issued'    => 10,
            'quantity_in_pieces' => 10,
            'unit_price'         => 100,
            'subtotal'           => 1000,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        return $pid;
    }

    public function test_auditor_can_read_recommendations(): void
    {
        $resp = $this->actingAs($this->makeUser('auditor'), 'sanctum')
            ->getJson('/api/restock/recommendations');
        $resp->assertOk()->assertJsonStructure(['data' => ['run_at', 'recommendations']]);
    }

    public function test_auditor_cannot_recalculate(): void
    {
        $this->actingAs($this->makeUser('auditor'), 'sanctum')
            ->postJson('/api/restock/recalculate')
            ->assertForbidden();
    }

    public function test_warehouse_personnel_cannot_read(): void
    {
        $this->actingAs($this->makeUser('warehouse_personnel'), 'sanctum')
            ->getJson('/api/restock/recommendations')
            ->assertForbidden();
    }

    public function test_inventory_analyst_recalculate_persists_and_returns_rows(): void
    {
        $pid = $this->makeProductWithDemand();

        $resp = $this->actingAs($this->makeUser('inventory_analyst'), 'sanctum')
            ->postJson('/api/restock/recalculate');

        $resp->assertOk();
        $this->assertDatabaseHas('restock_recommendations', ['product_id' => $pid]);
        $ids = array_map(
            fn ($r) => (int) $r['product_id'],
            $resp->json('data.recommendations')
        );
        $this->assertContains($pid, $ids);
    }
}
```

- [ ] **Step 4: Run to verify failure**

`php artisan test --filter=RestockEndpointsTest`

Expected: FAIL with 404s (routes don't exist yet).

- [ ] **Step 5: Write the controller (read + recalculate only for now)**

`back-end/app/Http/Controllers/Api/RestockRecommendationController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RestockRecommendation;
use App\Models\StockParameterSuggestion;
use App\Services\Analytics\RestockAnalyticsService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class RestockRecommendationController extends Controller
{
    use ApiResponse;

    public function __construct(private RestockAnalyticsService $service)
    {
    }

    /** GET /api/restock/recommendations — latest stored batch. */
    public function index(Request $request)
    {
        $query = RestockRecommendation::with('product')
            ->orderByRaw("FIELD(priority, 'CRITICAL', 'HIGH', 'MEDIUM')")
            ->orderByDesc('avg_daily_demand');

        if ($request->filled('priority') && $request->priority !== 'all') {
            $query->where('priority', $request->priority);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->whereHas('product', function ($q) use ($s) {
                $q->where(function ($qq) use ($s) {
                    $qq->where('product_name', 'like', "%{$s}%")
                       ->orWhere('product_code', 'like', "%{$s}%");
                });
            });
        }

        $rows = $query->get();

        return $this->success([
            'run_at'          => optional($rows->first())->run_at,
            'recommendations' => $rows,
        ], 'Restock recommendations retrieved');
    }

    /** POST /api/restock/recalculate — run the engine now, return fresh rows. */
    public function recalculate(Request $request)
    {
        try {
            $this->service->run();
        } catch (\Throwable $e) {
            return $this->error('Analytics run failed: ' . $e->getMessage(), 500);
        }

        return $this->index($request);
    }
}
```

- [ ] **Step 6: Register the routes**

In `back-end/routes/api.php`, import at the top with the other controller imports:

```php
use App\Http\Controllers\Api\RestockRecommendationController;
```

Then inside the authenticated (`auth:sanctum`) group, near the purchase-request routes (after the PR block around line 520), add:

```php
    /*
    |----------------------------------------------------------------------
    | PO Recommendation analytics (restock engine)
    |----------------------------------------------------------------------
    */
    Route::middleware('permission:po_recommendations,read')->group(function () {
        Route::get('/restock/recommendations', [RestockRecommendationController::class, 'index']);
        Route::get('/restock/suggestions', [RestockRecommendationController::class, 'suggestions']);
    });
    Route::middleware('permission:po_recommendations,create')->group(function () {
        Route::post('/restock/recalculate', [RestockRecommendationController::class, 'recalculate']);
        Route::post('/restock/create-pr', [RestockRecommendationController::class, 'createPr']);
    });
    Route::middleware('permission:po_recommendations,update')->group(function () {
        Route::post('/restock/suggestions/{id}/apply', [RestockRecommendationController::class, 'applySuggestion']);
        Route::post('/restock/suggestions/{id}/dismiss', [RestockRecommendationController::class, 'dismissSuggestion']);
    });
```

(`suggestions`, `createPr`, `applySuggestion`, `dismissSuggestion` are added in Tasks 6–7; registering all routes now is fine because the controller methods will exist before the routes are exercised by tests.) If route registration itself errors on missing methods when the route file loads, add empty stubs returning `$this->error('Not implemented', 501)` and replace them in Tasks 6–7.

- [ ] **Step 7: Run to verify the four tests pass**

`php artisan test --filter=RestockEndpointsTest`

Expected: PASS (4 tests). Note: if `suggestions`/`createPr` methods missing cause a route-cache error, add the 501 stubs described above.

- [ ] **Step 8: Commit**

```powershell
git add back-end/database/seeders/PermissionSeeder.php back-end/app/Http/Controllers/Api/RestockRecommendationController.php back-end/routes/api.php back-end/tests/Feature/Restock/RestockEndpointsTest.php
git commit -m "feat(analytics): po_recommendations permission + restock read/recalculate endpoints"
```

---

### Task 6: Create-draft-PR endpoint

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/RestockRecommendationController.php`
- Test: `back-end/tests/Feature/Restock/RestockEndpointsTest.php` (append)

- [ ] **Step 1: Append the failing tests**

Append to `RestockEndpointsTest`:

```php
    public function test_create_pr_builds_draft_purchase_request_with_details(): void
    {
        $pid = $this->makeProductWithDemand(); // cost_price 80
        $ia  = $this->makeUser('inventory_analyst');

        $resp = $this->actingAs($ia, 'sanctum')->postJson('/api/restock/create-pr', [
            'items' => [
                ['product_id' => $pid, 'quantity' => 12],
            ],
        ]);

        $resp->assertCreated();
        $prId = $resp->json('data.pr_id');
        $this->assertNotNull($prId);

        $this->assertDatabaseHas('purchase_requests', [
            'pr_id'        => $prId,
            'status_id'    => 13, // Pending
            'requested_by' => $ia->user_id,
        ]);
        $this->assertDatabaseHas('purchase_request_details', [
            'pr_id'           => $prId,
            'product_id'      => $pid,
            'quantity'        => 12,
            'estimated_price' => 80,
            'subtotal'        => 960,
        ]);
    }

    public function test_create_pr_rejects_empty_items(): void
    {
        $this->actingAs($this->makeUser('inventory_analyst'), 'sanctum')
            ->postJson('/api/restock/create-pr', ['items' => []])
            ->assertStatus(422);
    }

    public function test_auditor_cannot_create_pr(): void
    {
        $this->actingAs($this->makeUser('auditor'), 'sanctum')
            ->postJson('/api/restock/create-pr', ['items' => [['product_id' => 1, 'quantity' => 1]]])
            ->assertForbidden();
    }
```

- [ ] **Step 2: Run to verify failure**

`php artisan test --filter=RestockEndpointsTest`
Expected: the new tests FAIL (404/501).

- [ ] **Step 3: Implement createPr**

Add imports to `RestockRecommendationController.php`:

```php
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestDetail;
use App\Support\RealtimeBroadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
```

Add the method:

```php
    /**
     * POST /api/restock/create-pr
     * Turn selected recommendations into a draft Purchase Request that then
     * follows the normal PR -> approval -> PO flow.
     */
    public function createPr(Request $request)
    {
        try {
            $validated = $request->validate([
                'needed_by_date'     => 'nullable|date|after:today',
                'items'              => 'required|array|min:1',
                'items.*.product_id' => 'required|exists:products,product_id',
                'items.*.quantity'   => 'required|integer|min:1',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $warehouseId = Location::where('location_type', 'warehouse')
            ->orderBy('location_id')
            ->value('location_id');
        if (!$warehouseId) {
            return $this->error('No warehouse location is configured', 422);
        }

        try {
            $pr = DB::transaction(function () use ($validated, $request, $warehouseId) {
                $products = Product::whereIn(
                    'product_id',
                    collect($validated['items'])->pluck('product_id')
                )->get()->keyBy('product_id');

                $lines = collect($validated['items'])->map(function ($item) use ($products) {
                    $cost = (float) ($products[$item['product_id']]->cost_price ?? 0);
                    return [
                        'product_id'      => $item['product_id'],
                        'quantity'        => $item['quantity'],
                        'estimated_price' => $cost,
                        'subtotal'        => $cost * $item['quantity'],
                    ];
                });

                $pr = PurchaseRequest::create([
                    'location_id'     => $warehouseId,
                    'pr_number'       => 'PR-' . date('Ymd') . '-' . strtoupper(Str::random(4)),
                    'request_date'    => today(),
                    'needed_by_date'  => $validated['needed_by_date']
                        ?? today()->addDays(7)->toDateString(),
                    'purpose'         => 'Generated from PO Recommendation analytics',
                    'estimated_total' => $lines->sum('subtotal'),
                    'status_id'       => 13, // Pending
                    'requested_by'    => $request->user()->user_id,
                ]);

                foreach ($lines as $line) {
                    PurchaseRequestDetail::create(['pr_id' => $pr->pr_id] + $line);
                }

                return $pr;
            });
        } catch (\Throwable $e) {
            return $this->error('Failed to create purchase request: ' . $e->getMessage(), 500);
        }

        RealtimeBroadcaster::approvalQueueChanged((int) $pr->location_id, 'pr_changed');

        return $this->success([
            'pr_id'     => $pr->pr_id,
            'pr_number' => $pr->pr_number,
        ], 'Draft purchase request created', 201);
    }
```

- [ ] **Step 4: Run to verify pass**

`php artisan test --filter=RestockEndpointsTest` — expected: all PASS.

- [ ] **Step 5: Commit**

```powershell
git add back-end/app/Http/Controllers/Api/RestockRecommendationController.php back-end/tests/Feature/Restock/RestockEndpointsTest.php
git commit -m "feat(analytics): create draft PR from selected restock recommendations"
```

---

### Task 7: Suggestion list / apply / dismiss endpoints

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/RestockRecommendationController.php`
- Test: `back-end/tests/Feature/Restock/StockParameterSuggestionTest.php`

- [ ] **Step 1: Write the failing tests**

`back-end/tests/Feature/Restock/StockParameterSuggestionTest.php`:

```php
<?php

namespace Tests\Feature\Restock;

use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StockParameterSuggestionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'admin'               => 'System Administrator',
            'inventory_analyst'   => 'Inventory Analyst',
            'branch_manager'      => 'Branch Manager',
            'warehouse_personnel' => 'Warehouse Personnel',
            'auditor'             => 'Auditor',
        ] as $name => $desc) {
            Role::firstOrCreate(['role_name' => $name], ['description' => $desc]);
        }
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionsSeeder::class);
    }

    private function makeUser(string $roleSlug): User
    {
        $role = Role::where('role_name', $roleSlug)->firstOrFail();
        $statusId = StatusLookup::query()->value('status_id')
            ?? StatusLookup::forceCreate(['status_name' => 'Active', 'status_category' => 'user'])->status_id;

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

    /** @return array{0:int,1:int} [product_id, suggestion_id] */
    private function makeSuggestion(): array
    {
        $pid = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Suggestion Test Product',
            'product_type'       => 'consumable',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 5,
            'safety_stock'       => 2,
            'max_stock_level'    => 10,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');

        $sid = (int) DB::table('stock_parameter_suggestions')->insertGetId([
            'product_id'            => $pid,
            'current_recommended'   => 5,
            'suggested_recommended' => 19,
            'current_safety'        => 2,
            'suggested_safety'      => 5,
            'current_max'           => 10,
            'suggested_max'         => 65,
            'reason_codes'          => json_encode(['FAST_MOVING']),
            'status'                => 'pending',
            'created_at'            => now(),
            'updated_at'            => now(),
        ], 'suggestion_id');

        return [$pid, $sid];
    }

    public function test_list_pending_suggestions(): void
    {
        [, $sid] = $this->makeSuggestion();
        $resp = $this->actingAs($this->makeUser('auditor'), 'sanctum')
            ->getJson('/api/restock/suggestions');
        $resp->assertOk();
        $ids = array_map(fn ($s) => (int) $s['suggestion_id'], $resp->json('data'));
        $this->assertContains($sid, $ids);
    }

    public function test_apply_updates_product_and_marks_applied(): void
    {
        [$pid, $sid] = $this->makeSuggestion();
        $ia = $this->makeUser('inventory_analyst');

        $this->actingAs($ia, 'sanctum')
            ->postJson("/api/restock/suggestions/{$sid}/apply")
            ->assertOk();

        $this->assertDatabaseHas('products', [
            'product_id'         => $pid,
            'recommended_stocks' => 19,
            'safety_stock'       => 5,
            'max_stock_level'    => 65,
        ]);
        $this->assertDatabaseHas('stock_parameter_suggestions', [
            'suggestion_id' => $sid,
            'status'        => 'applied',
            'reviewed_by'   => $ia->user_id,
        ]);
    }

    public function test_apply_rejects_stale_suggestion_when_product_changed(): void
    {
        [$pid, $sid] = $this->makeSuggestion();
        DB::table('products')->where('product_id', $pid)->update(['recommended_stocks' => 99]);

        $this->actingAs($this->makeUser('inventory_analyst'), 'sanctum')
            ->postJson("/api/restock/suggestions/{$sid}/apply")
            ->assertStatus(409);

        $this->assertDatabaseHas('stock_parameter_suggestions', [
            'suggestion_id' => $sid,
            'status'        => 'pending',
        ]);
    }

    public function test_apply_rejects_non_pending_suggestion(): void
    {
        [, $sid] = $this->makeSuggestion();
        DB::table('stock_parameter_suggestions')->where('suggestion_id', $sid)
            ->update(['status' => 'dismissed']);

        $this->actingAs($this->makeUser('inventory_analyst'), 'sanctum')
            ->postJson("/api/restock/suggestions/{$sid}/apply")
            ->assertStatus(422);
    }

    public function test_dismiss_marks_dismissed(): void
    {
        [, $sid] = $this->makeSuggestion();
        $ia = $this->makeUser('inventory_analyst');

        $this->actingAs($ia, 'sanctum')
            ->postJson("/api/restock/suggestions/{$sid}/dismiss")
            ->assertOk();

        $this->assertDatabaseHas('stock_parameter_suggestions', [
            'suggestion_id' => $sid,
            'status'        => 'dismissed',
            'reviewed_by'   => $ia->user_id,
        ]);
    }

    public function test_auditor_cannot_apply_or_dismiss(): void
    {
        [, $sid] = $this->makeSuggestion();
        $auditor = $this->makeUser('auditor');

        $this->actingAs($auditor, 'sanctum')
            ->postJson("/api/restock/suggestions/{$sid}/apply")->assertForbidden();
        $this->actingAs($auditor, 'sanctum')
            ->postJson("/api/restock/suggestions/{$sid}/dismiss")->assertForbidden();
    }
}
```

- [ ] **Step 2: Run to verify failure**

`php artisan test --filter=StockParameterSuggestionTest` — expected: FAIL.

- [ ] **Step 3: Implement the three methods**

Add to `RestockRecommendationController.php`:

```php
    /** GET /api/restock/suggestions?status=pending|applied|dismissed|all */
    public function suggestions(Request $request)
    {
        $status = $request->input('status', 'pending');

        $query = StockParameterSuggestion::with('product')
            ->orderByDesc('created_at');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return $this->success($query->get(), 'Stock parameter suggestions retrieved');
    }

    /**
     * POST /api/restock/suggestions/{id}/apply
     * Writes the suggested values to the product; the suggestion row stays
     * as the audit trail. Rejects double-applies and stale snapshots.
     */
    public function applySuggestion(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $s = StockParameterSuggestion::lockForUpdate()->find($id);
            if (!$s) {
                return $this->notFound('Suggestion not found');
            }
            if ($s->status !== 'pending') {
                return $this->error('Suggestion is no longer pending', 422);
            }

            $product = Product::lockForUpdate()->find($s->product_id);
            if (!$product) {
                return $this->error('Product no longer exists', 422);
            }

            $stale = (int) $product->recommended_stocks !== (int) $s->current_recommended
                || (int) ($product->safety_stock ?? 0) !== (int) ($s->current_safety ?? 0)
                || (int) ($product->max_stock_level ?? 0) !== (int) ($s->current_max ?? 0);
            if ($stale) {
                return $this->error(
                    'Product stock parameters changed since this suggestion was computed. Recalculate first.',
                    409
                );
            }

            $product->update([
                'recommended_stocks' => $s->suggested_recommended,
                'safety_stock'       => $s->suggested_safety,
                'max_stock_level'    => $s->suggested_max,
            ]);
            $s->update([
                'status'      => 'applied',
                'reviewed_by' => $request->user()->user_id,
                'reviewed_at' => now(),
            ]);

            return $this->success($s->fresh('product'), 'Suggestion applied');
        });
    }

    /** POST /api/restock/suggestions/{id}/dismiss */
    public function dismissSuggestion(Request $request, $id)
    {
        $s = StockParameterSuggestion::find($id);
        if (!$s) {
            return $this->notFound('Suggestion not found');
        }
        if ($s->status !== 'pending') {
            return $this->error('Suggestion is no longer pending', 422);
        }

        $s->update([
            'status'      => 'dismissed',
            'reviewed_by' => $request->user()->user_id,
            'reviewed_at' => now(),
        ]);

        return $this->success($s->fresh(), 'Suggestion dismissed');
    }
```

- [ ] **Step 4: Run to verify pass, then run all restock + permission suites**

```powershell
php artisan test --filter=StockParameterSuggestionTest
php artisan test tests/Feature/Restock
php artisan test tests/Feature/Permissions tests/Feature/Rbac
```

Expected: all PASS. If `RbacRouteMatrixTest` fails because it enumerates permission-gated routes, add the six `/restock/*` routes to its expected matrix following the existing entries' pattern (feature `po_recommendations`, actions as registered in Task 5).

- [ ] **Step 5: Commit**

```powershell
git add back-end/app/Http/Controllers/Api/RestockRecommendationController.php back-end/tests/Feature/Restock/StockParameterSuggestionTest.php
git commit -m "feat(analytics): stock parameter suggestion apply/dismiss with stale guard"
```

---

### Task 8: Frontend — restockAPI + rebuilt PO Recommendation page

**Files:**
- Modify: `frontend/src/admin/services/api.js` (append `restockAPI`)
- Rewrite: `frontend/src/admin/pages/PORecommendation.jsx` (replace file contents entirely)

- [ ] **Step 1: Add restockAPI to api.js**

Append near the other API objects (e.g. after `purchaseOrdersAPI`), following the file's `apiRequest` idiom:

```js
/* ======================
   RESTOCK ANALYTICS API
====================== */
export const restockAPI = {
  getRecommendations: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/restock/recommendations?${query}` : '/restock/recommendations');
  },
  recalculate: async () =>
    apiRequest('/restock/recalculate', { method: 'POST' }),
  createPR: async (items, neededByDate = null) =>
    apiRequest('/restock/create-pr', {
      method: 'POST',
      body: JSON.stringify({
        items,
        ...(neededByDate ? { needed_by_date: neededByDate } : {}),
      }),
    }),
  getSuggestions: async (status = 'pending') =>
    apiRequest(`/restock/suggestions?status=${status}`),
  applySuggestion: async (id) =>
    apiRequest(`/restock/suggestions/${id}/apply`, { method: 'POST' }),
  dismissSuggestion: async (id) =>
    apiRequest(`/restock/suggestions/${id}/dismiss`, { method: 'POST' }),
};
```

- [ ] **Step 2: Replace PORecommendation.jsx**

Replace the entire contents of `frontend/src/admin/pages/PORecommendation.jsx` with:

```jsx
import React, { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import { restockAPI } from '../services/api';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import '../styles/po_recommendation.css';

const PRIORITY_BADGE = {
  CRITICAL: 'ak-badge ak-badge--red',
  HIGH:     'ak-badge ak-badge--amber',
  MEDIUM:   'ak-badge ak-badge--blue',
};

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

const fmt = (v, digits = 1) =>
  v === null || v === undefined || v === '' ? '—' : Number(v).toFixed(digits);

function ReasonChips({ codes, title }) {
  const list = Array.isArray(codes) ? codes : [];
  return (
    <span className="po-reason-chips" title={title || ''}>
      {list.map((code) => (
        <span key={code} className="ak-pill">{REASON_LABELS[code] || code}</span>
      ))}
    </span>
  );
}

const PORecommendation = () => {
  const navigate = useNavigate();

  const [tab, setTab]                   = useState('recommendations');
  const [runAt, setRunAt]               = useState(null);
  const [rows, setRows]                 = useState([]);
  const [suggestions, setSuggestions]   = useState([]);
  const [loading, setLoading]           = useState(true);
  const [recalculating, setRecalc]      = useState(false);
  const [creating, setCreating]         = useState(false);
  const [search, setSearch]             = useState('');
  const [priorityFilter, setPriority]   = useState('all');
  // product_id -> quantity override (selection)
  const [selected, setSelected]         = useState(() => new Map());
  const [busySuggestion, setBusySug]    = useState(null);

  const loadAll = async () => {
    setLoading(true);
    try {
      const [recRes, sugRes] = await Promise.all([
        restockAPI.getRecommendations(),
        restockAPI.getSuggestions('pending'),
      ]);
      setRunAt(recRes?.data?.run_at || null);
      setRows(Array.isArray(recRes?.data?.recommendations) ? recRes.data.recommendations : []);
      setSuggestions(Array.isArray(sugRes?.data) ? sugRes.data : []);
    } catch (err) {
      console.error('PO Recommendation load failed:', err);
      toast.error('Failed to load recommendations', err?.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { loadAll(); }, []);

  const handleRecalculate = async () => {
    setRecalc(true);
    try {
      const res = await restockAPI.recalculate();
      setRunAt(res?.data?.run_at || null);
      setRows(Array.isArray(res?.data?.recommendations) ? res.data.recommendations : []);
      const sugRes = await restockAPI.getSuggestions('pending');
      setSuggestions(Array.isArray(sugRes?.data) ? sugRes.data : []);
      setSelected(new Map());
      toast.success('Recommendations recalculated');
    } catch (err) {
      toast.error('Recalculation failed', err?.message);
    } finally {
      setRecalc(false);
    }
  };

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return rows.filter((r) => {
      if (priorityFilter !== 'all' && r.priority !== priorityFilter) return false;
      if (!q) return true;
      const name = r.product?.product_name || '';
      const code = r.product?.product_code || '';
      return name.toLowerCase().includes(q) || code.toLowerCase().includes(q);
    });
  }, [rows, search, priorityFilter]);

  const counts = useMemo(() => ({
    critical: rows.filter((r) => r.priority === 'CRITICAL').length,
    high:     rows.filter((r) => r.priority === 'HIGH').length,
    medium:   rows.filter((r) => r.priority === 'MEDIUM').length,
  }), [rows]);

  const toggleRow = (r) => {
    setSelected((prev) => {
      const next = new Map(prev);
      if (next.has(r.product_id)) next.delete(r.product_id);
      else next.set(r.product_id, Number(r.recommended_quantity) || 1);
      return next;
    });
  };

  const setQty = (productId, qty) => {
    setSelected((prev) => {
      const next = new Map(prev);
      next.set(productId, qty);
      return next;
    });
  };

  const handleCreatePR = async () => {
    const items = [...selected.entries()]
      .map(([product_id, quantity]) => ({ product_id, quantity: Number(quantity) }))
      .filter((i) => Number.isFinite(i.quantity) && i.quantity > 0);
    if (items.length === 0) {
      toast.warning('Select at least one product with a quantity above zero');
      return;
    }

    const ok = await confirmAction({
      title: `Create purchase request for ${items.length} product(s)?`,
      text: 'A draft PR will be created and sent for admin approval.',
      confirmText: 'Create PR',
      tone: 'info',
    });
    if (!ok) return;

    setCreating(true);
    try {
      const res = await restockAPI.createPR(items);
      toast.success('Draft purchase request created', res?.data?.pr_number);
      setSelected(new Map());
      navigate('/admin/purchase-requests');
    } catch (err) {
      toast.error('Failed to create purchase request', err?.message);
    } finally {
      setCreating(false);
    }
  };

  const handleApply = async (s) => {
    const ok = await confirmAction({
      title: `Apply new stock parameters to ${s.product?.product_name || 'this product'}?`,
      text:
        `Recommended ${s.current_recommended} → ${s.suggested_recommended}, ` +
        `Safety ${s.current_safety ?? 0} → ${s.suggested_safety ?? 0}, ` +
        `Max ${s.current_max ?? 0} → ${s.suggested_max ?? 0}`,
      confirmText: 'Apply',
      tone: 'info',
    });
    if (!ok) return;

    setBusySug(s.suggestion_id);
    try {
      await restockAPI.applySuggestion(s.suggestion_id);
      setSuggestions((prev) => prev.filter((x) => x.suggestion_id !== s.suggestion_id));
      toast.success('Stock parameters updated');
    } catch (err) {
      toast.error('Could not apply suggestion', err?.message);
    } finally {
      setBusySug(null);
    }
  };

  const handleDismiss = async (s) => {
    setBusySug(s.suggestion_id);
    try {
      await restockAPI.dismissSuggestion(s.suggestion_id);
      setSuggestions((prev) => prev.filter((x) => x.suggestion_id !== s.suggestion_id));
      toast.info('Suggestion dismissed');
    } catch (err) {
      toast.error('Could not dismiss suggestion', err?.message);
    } finally {
      setBusySug(null);
    }
  };

  if (loading) {
    return (
      <AdminLayout>
        <div className="ak-page po-recommendation-page"><Loader /></div>
      </AdminLayout>
    );
  }

  return (
    <AdminLayout>
      <div className="ak-page po-recommendation-page">
        <div className="ak-header">
          <div className="ak-header-row">
            <div>
              <h1>PO Recommendation</h1>
              <p className="ak-muted">
                {runAt
                  ? `Last computed: ${new Date(runAt).toLocaleString()}`
                  : 'No analytics run yet — click Recalculate.'}
              </p>
            </div>
            <div className="ak-header-aside">
              <button
                className="ak-btn ak-btn--primary"
                onClick={handleRecalculate}
                disabled={recalculating}
              >
                {recalculating ? 'Recalculating…' : 'Recalculate now'}
              </button>
            </div>
          </div>
        </div>

        <div className="ak-stat-grid">
          <div className="ak-stat">
            <span className="ak-stat-label">Critical</span>
            <span className="ak-stat-value">{counts.critical}</span>
          </div>
          <div className="ak-stat">
            <span className="ak-stat-label">High priority</span>
            <span className="ak-stat-value">{counts.high}</span>
          </div>
          <div className="ak-stat">
            <span className="ak-stat-label">Medium</span>
            <span className="ak-stat-value">{counts.medium}</span>
          </div>
          <div className="ak-stat">
            <span className="ak-stat-label">Parameter suggestions</span>
            <span className="ak-stat-value">{suggestions.length}</span>
          </div>
        </div>

        <div className="ak-tabs">
          <button
            className={`ak-tab ${tab === 'recommendations' ? 'is-active' : ''}`}
            onClick={() => setTab('recommendations')}
          >
            Restock Recommendations ({rows.length})
          </button>
          <button
            className={`ak-tab ${tab === 'suggestions' ? 'is-active' : ''}`}
            onClick={() => setTab('suggestions')}
          >
            Stock Parameter Suggestions ({suggestions.length})
          </button>
        </div>

        {tab === 'recommendations' && (
          <div className="ak-card">
            <div className="ak-toolbar">
              <input
                className="ak-input ak-search"
                placeholder="Search product name or code…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
              <select
                className="ak-input"
                value={priorityFilter}
                onChange={(e) => setPriority(e.target.value)}
              >
                <option value="all">All priorities</option>
                <option value="CRITICAL">Critical</option>
                <option value="HIGH">High</option>
                <option value="MEDIUM">Medium</option>
              </select>
              <div className="ak-toolbar--push">
                <button
                  className="ak-btn ak-btn--primary"
                  onClick={handleCreatePR}
                  disabled={creating || selected.size === 0}
                >
                  {creating ? 'Creating…' : `Create Purchase Request (${selected.size})`}
                </button>
              </div>
            </div>

            {filtered.length === 0 ? (
              <div className="ak-state">
                <p>No restock recommendations{rows.length > 0 ? ' match the filters' : ''}.</p>
              </div>
            ) : (
              <div className="ak-table-wrap">
                <table className="ak-table">
                  <thead>
                    <tr>
                      <th></th>
                      <th>Product</th>
                      <th>Priority</th>
                      <th>Stock</th>
                      <th>On order</th>
                      <th>Demand/day</th>
                      <th>Cover (days)</th>
                      <th>Lead (days)</th>
                      <th>Order qty</th>
                      <th>Why</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filtered.map((r) => {
                      const checked = selected.has(r.product_id);
                      const trend = r.demand_trend === null || r.demand_trend === undefined
                        ? null : Number(r.demand_trend);
                      return (
                        <tr key={r.recommendation_id}>
                          <td>
                            <input
                              type="checkbox"
                              checked={checked}
                              onChange={() => toggleRow(r)}
                            />
                          </td>
                          <td>
                            <div>{r.product?.product_name || `#${r.product_id}`}</div>
                            <div className="ak-muted ak-mono">{r.product?.product_code}</div>
                          </td>
                          <td>
                            <span className={PRIORITY_BADGE[r.priority] || 'ak-badge ak-badge--gray'}>
                              {r.priority}
                            </span>
                          </td>
                          <td>{r.current_stock}</td>
                          <td>{r.on_order_quantity}</td>
                          <td>
                            {fmt(r.avg_daily_demand, 2)}
                            {trend !== null && trend >= 1.3 && <span title="Accelerating"> ▲</span>}
                            {trend !== null && trend <= 0.7 && <span title="Slowing down"> ▼</span>}
                          </td>
                          <td>{fmt(r.days_of_cover)}</td>
                          <td>{fmt(r.lead_time_days, 0)}</td>
                          <td>
                            {checked ? (
                              <input
                                type="number"
                                min="1"
                                className="ak-input po-qty-input"
                                style={{ width: 84 }}
                                value={selected.get(r.product_id)}
                                onChange={(e) => setQty(r.product_id, e.target.value)}
                              />
                            ) : (
                              r.recommended_quantity
                            )}
                          </td>
                          <td>
                            <ReasonChips codes={r.reason_codes} title={r.reason} />
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}

        {tab === 'suggestions' && (
          <div className="ak-card">
            {suggestions.length === 0 ? (
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
                      <th>Why</th>
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
                        <td><ReasonChips codes={s.reason_codes} title={s.summary} /></td>
                        <td className="ak-actions">
                          <button
                            className="ak-btn ak-btn--sm ak-btn--primary"
                            disabled={busySuggestion === s.suggestion_id}
                            onClick={() => handleApply(s)}
                          >
                            Apply
                          </button>
                          <button
                            className="ak-btn ak-btn--sm ak-btn--ghost"
                            disabled={busySuggestion === s.suggestion_id}
                            onClick={() => handleDismiss(s)}
                          >
                            Dismiss
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}
      </div>
    </AdminLayout>
  );
};

export default PORecommendation;
```

Notes for the implementer:
- Auditors/branch managers get 403 on recalculate/apply/dismiss from the backend; the toast surfaces it. That is acceptable per design (no client-side permission branching needed here).
- `po_recommendation.css` is shared by many pages — do not delete it; the two small hook classes used above (`po-reason-chips`, `po-qty-input`) may not exist yet. Append to `frontend/src/admin/styles/po_recommendation.css`:

```css
/* PO Recommendation (analytics rebuild) */
.po-reason-chips { display: inline-flex; flex-wrap: wrap; gap: 4px; }
.po-qty-input { padding: 4px 8px; }
```

- [ ] **Step 3: Build the frontend to catch syntax/import errors**

From `frontend/`:

```powershell
npm run build
```

Expected: `Compiled successfully` (warnings OK, errors not).

- [ ] **Step 4: Manual smoke test**

Start backend + frontend (e.g. `npm run demo` per repo convention, or XAMPP Apache serving the prod build; backend must be reachable). Log in as an admin, open **PO Recommendation**:
1. Click **Recalculate now** → toast, stat tiles fill, table lists products with priority badges and reason chips.
2. Tick a row, adjust qty, **Create Purchase Request** → confirm dialog → success toast → lands on Purchase Requests page and the new draft PR is listed as Pending.
3. Switch to **Stock Parameter Suggestions**, Apply one → confirm → verify in Item Management that the product's recommended/safety/max changed; Dismiss another → it disappears.

- [ ] **Step 5: Commit**

```powershell
git add frontend/src/admin/services/api.js frontend/src/admin/pages/PORecommendation.jsx frontend/src/admin/styles/po_recommendation.css
git commit -m "feat(analytics): rebuild PO Recommendation page on restock analytics engine"
```

---

### Task 9: Full verification pass

**Files:** none new.

- [ ] **Step 1: Run the whole backend test suite**

From `back-end/`:

```powershell
php artisan test
```

Expected: everything green (pre-existing failures unrelated to this work should be noted, not fixed silently).

- [ ] **Step 2: Frontend tests**

From `frontend/`:

```powershell
npx cross-env CI=true npm test -- --watchAll=false
```

(or `npm test -- --watchAll=false` with `$env:CI='true'`). Expected: existing util tests still pass.

- [ ] **Step 3: End-to-end verify (superpowers:verification-before-completion)**

Re-run `php artisan analytics:restock` on the dev DB and confirm counts match what the page shows after refresh. Confirm `php artisan schedule:list` still shows the daily entry.

- [ ] **Step 4: Commit any stragglers and stop**

The branch is `backupV3` — do not merge or push without the user's say-so.

---

## Self-review checklist (done at plan time)

- **Spec coverage:** engine math (Task 2), persistence semantics incl. dismissed-not-re-raised (Task 3), scheduled + manual runs (Task 4 + recalculate in Task 5), warehouse-centric scope (warehouse `location_id` on rows, org-wide demand/stock), draft-PR output (Task 6), suggest+approve with stale guard + audit trail (Task 7), rebuilt two-tab UI with editable qty + reason chips + toast/confirm conventions (Task 8), RBAC (new `po_recommendations` feature, Task 5; matrix tests Task 7 step 4), error handling (transactions, 409 stale, 422 non-pending, division guards in engine), testing conventions (`DatabaseTransactions`, test DB migration via `--path`).
- **Placeholders:** none — every code step carries the full code.
- **Type consistency:** `analyze()` returns `run_at`/`warehouse_location_id`/`recommendations`/`suggestions`; recommendation keys match `restock_recommendations` columns; suggestion keys match `stock_parameter_suggestions` columns; controller/tests/frontend use those same names throughout.
