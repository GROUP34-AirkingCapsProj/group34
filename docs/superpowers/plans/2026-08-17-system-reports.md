# System Reports Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the entirely-mock System Reports page with six working reports that query real data, render to PDF or CSV, store the generated file, and list genuine past runs.

**Architecture:** A report registry — one class per report implementing `ReportDefinition`, declaring its own parameters and formats and building its own dataset. A shared `ReportRunner` handles all common plumbing: validation, rendering, file storage, and the `report_runs` audit row. The frontend fetches the catalog from the API and renders cards and filter dialogs from those declarations, so a card can never advertise a filter or format the backend does not implement.

**Tech Stack:** Laravel 12, `barryvdh/laravel-dompdf` (already installed), MySQL, React 18 with `react-scripts` / jest, testing-library.

**Spec:** `docs/superpowers/specs/2026-08-17-system-reports-design.md`

---

## Orientation for the implementing engineer

Read this section before Task 1. It is the domain knowledge the tasks assume.

**This is an air-conditioning inventory system.** Products are appliances and installation
materials. They live at `locations`, move between them, and get received from suppliers
against purchase orders.

**There are no branches or warehouses as tables.** The migration
`2025_01_15_000000_update_locations_unified` folded both into one `locations` table with a
`location_type` column of `warehouse`, `showroom`, or `branch_office`. You will still see
legacy column names like `transactions.branch_id` — that column holds a `location_id` and
`Transaction::branch()` resolves it to `Location`. **Do not rename it.** Only report-facing
labels say "location".

**Location scoping** is done through the existing trait
`app/Models/Concerns/ScopesToUserLocation.php`, which adds a `forUser($user)` query scope.
Centralized users (role ids 1 admin, 2 inventory_analyst, 5 auditor) see everything;
scoped users (3 branch_manager, 4 warehouse_personnel) see only their `location_id`; a
scoped user with no location gets an empty result via `whereRaw('1 = 0')`. Use this scope —
never hand-roll the check.

**A wrinkle you must understand for the tests.** The `report_viewing` permission is seeded
to admin, inventory_analyst, and auditor — which are exactly the three centralized roles.
So in the default seeded data, every user who can open Reports is unscoped, and the scoping
code never fires. It is still correct to write it: permissions are editable at runtime
through `UserPermissionOverride`, so a `branch_manager` can be granted `report_viewing.read`
and immediately becomes a scoped report consumer. **The scoping test in Task 20 must
therefore grant the permission via an override**, not by changing the user's role — changing
the role would make them centralized and prove nothing.

**Movement types** are seeded in `transaction_type_lookup` with fixed ids:

| id | name | meaning |
|----|------|---------|
| 1 | Stock In | receiving inventory from a supplier |
| 2 | Stock Out | issuing inventory for operations |
| 3 | Transfer | moving stock between locations |
| 4 | Adjustment | manual count correction |
| 5 | Return to Vendor | returning goods to a supplier |
| 6 | Sales | customer purchase |

Tests resolve these **by name**, not by hardcoded id — see the existing pattern in
`tests/Feature/Sets/IncidentRestockTest.php::inventoryStatusId()`. Production code may use
the ids directly since they are seeded constants.

**API response shape** is standardized by the `App\Traits\ApiResponse` trait:
`success($data, $message)`, `error($message, $code)`, `validationError($errors)`. Every
response has a `success` boolean. Use the trait; do not hand-build `response()->json`.

**Tests run against a real MySQL database** named `airkingV5_test` (see `phpunit.xml`), not
SQLite, and use `DatabaseTransactions` rather than `RefreshDatabase` — the schema and
lookup tables are already there and must not be wiped. Run a single test with:

```bash
cd back-end && php artisan test --filter=TestClassName
```

**Frontend tests** use jest via `react-scripts`:

```bash
cd frontend && npx react-scripts test --watchAll=false --testPathPattern=SystemReports
```

---

## File Structure

**Backend — new**

| File | Responsibility |
|---|---|
| `back-end/database/migrations/2026_08_17_000001_create_report_runs_table.php` | The `report_runs` table |
| `back-end/database/migrations/2026_08_17_000002_add_report_viewing_create_permission.php` | Adds `report_viewing.create` to existing databases |
| `back-end/app/Models/ReportRun.php` | Eloquent model for a generated report |
| `back-end/app/Reports/ReportParameter.php` | One declared filter (key, type, label, required) |
| `back-end/app/Reports/ReportParameters.php` | Validated parameter values handed to `build()` |
| `back-end/app/Reports/ReportDataset.php` | Columns, rows, summary, optional PDF sections |
| `back-end/app/Reports/ReportDefinition.php` | The interface every report implements |
| `back-end/app/Reports/ReportRegistry.php` | Holds the six definitions, keyed by slug |
| `back-end/app/Reports/ReportRunner.php` | Validate → build → render → store → record |
| `back-end/app/Reports/EmptyReportException.php` | Thrown when a dataset has no rows |
| `back-end/app/Reports/RowCapExceededException.php` | Thrown when a PDF exceeds its row cap |
| `back-end/app/Reports/Renderers/CsvReportRenderer.php` | Streams flat rows to CSV |
| `back-end/app/Reports/Renderers/PdfReportRenderer.php` | Renders via blade + dompdf |
| `back-end/app/Reports/Definitions/InventorySummaryReport.php` | Report 1 |
| `back-end/app/Reports/Definitions/TransactionReport.php` | Report 2 |
| `back-end/app/Reports/Definitions/LocationPerformanceReport.php` | Report 3 |
| `back-end/app/Reports/Definitions/LowStockReport.php` | Report 4 |
| `back-end/app/Reports/Definitions/WarehouseUtilizationReport.php` | Report 5 |
| `back-end/app/Reports/Definitions/UserActivityReport.php` | Report 6 |
| `back-end/app/Services/ReportRetentionService.php` | 90-day pruning, shared by command and endpoint |
| `back-end/app/Console/Commands/PruneReportRuns.php` | `php artisan reports:prune` |
| `back-end/app/Http/Controllers/Api/ReportController.php` | catalog, generate, runs, download |
| `back-end/resources/views/reports/document.blade.php` | Generic report PDF layout |

**Backend — modified**

| File | Change |
|---|---|
| `back-end/routes/api.php:1028-1090` | Delete the seven dead closures; add the four new routes |
| `back-end/database/seeders/PermissionSeeder.php:245-247` | Add the `create` action |

**Frontend — new**

| File | Responsibility |
|---|---|
| `frontend/src/admin/components/reports/GenerateReportDialog.jsx` | Builds inputs from declared parameters |
| `frontend/src/admin/components/reports/RecentReportsList.jsx` | Past runs, download / view / re-run |
| `frontend/src/admin/pages/SystemReports.test.jsx` | Page tests |
| `frontend/src/admin/components/reports/GenerateReportDialog.test.jsx` | Dialog tests |

**Frontend — modified**

| File | Change |
|---|---|
| `frontend/src/admin/pages/SystemReports.jsx` | Delete both hardcoded arrays; render from catalog |
| `frontend/src/admin/services/api.js` | Add `reportsAPI` |
| `frontend/src/admin/styles/system_reports.css` | Dialog, expired-row, skeleton, empty states |

The three report-specific frontend files live together under `components/reports/` because
they change together — the dialog's inputs and the page's cards are both driven by the same
catalog payload.

---

## Task 1: The `report_runs` table

**Files:**
- Create: `back-end/database/migrations/2026_08_17_000001_create_report_runs_table.php`
- Create: `back-end/app/Models/ReportRun.php`
- Test: `back-end/tests/Feature/Reports/ReportRunModelTest.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per generated report.
     *
     * The file itself is the record — a report is a point-in-time snapshot, so
     * `Recent Reports` serves the exact bytes that were produced, never a fresh
     * query wearing an old timestamp. `parameters` is kept so an expired run can
     * offer an explicit re-run rather than silently rebuilding.
     */
    public function up(): void
    {
        Schema::create('report_runs', function (Blueprint $table) {
            $table->bigIncrements('report_run_id');
            $table->string('report_key', 60);
            $table->string('title_snapshot', 150);
            $table->string('format', 10);
            $table->json('parameters');
            $table->string('file_path', 255)->nullable();
            $table->string('file_name', 255);
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedBigInteger('generated_by');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('file_pruned_at')->nullable();

            $table->index('report_key');
            $table->index('created_at');
            $table->index('expires_at');
            $table->foreign('generated_by')
                  ->references('user_id')->on('users')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated report. `updated_at` is absent by design — a run is immutable
 * except for pruning, which stamps `file_pruned_at`.
 */
class ReportRun extends Model
{
    protected $table = 'report_runs';
    protected $primaryKey = 'report_run_id';
    public const UPDATED_AT = null;

    protected $fillable = [
        'report_key',
        'title_snapshot',
        'format',
        'parameters',
        'file_path',
        'file_name',
        'file_size_bytes',
        'row_count',
        'generated_by',
        'expires_at',
        'file_pruned_at',
    ];

    protected $casts = [
        'parameters'      => 'array',
        'file_size_bytes' => 'integer',
        'row_count'       => 'integer',
        'created_at'      => 'datetime',
        'expires_at'      => 'datetime',
        'file_pruned_at'  => 'datetime',
    ];

    protected $appends = ['is_expired'];

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by', 'user_id');
    }

    /** The file is gone; the row remains so the run can be re-run. */
    public function getIsExpiredAttribute(): bool
    {
        return $this->file_pruned_at !== null;
    }
}
```

- [ ] **Step 3: Write the failing test**

```php
<?php

namespace Tests\Feature\Reports;

use App\Models\ReportRun;

class ReportRunModelTest extends ReportTestCase
{
    public function test_it_stores_parameters_as_an_array_and_reports_expiry(): void
    {
        $user = $this->makeUserAt($this->makeLocation(), 'admin');

        $run = ReportRun::create([
            'report_key'      => 'inventory-summary',
            'title_snapshot'  => 'Inventory Summary Report',
            'format'          => 'pdf',
            'parameters'      => ['location_id' => 7],
            'file_path'       => 'reports/x.pdf',
            'file_name'       => 'x.pdf',
            'file_size_bytes' => 1234,
            'row_count'       => 9,
            'generated_by'    => $user->user_id,
            'expires_at'      => now()->addDays(90),
        ]);

        $fresh = ReportRun::find($run->report_run_id);

        $this->assertSame(['location_id' => 7], $fresh->parameters);
        $this->assertFalse($fresh->is_expired);

        $fresh->update(['file_pruned_at' => now(), 'file_path' => null]);

        $this->assertTrue($fresh->fresh()->is_expired);
    }
}
```

- [ ] **Step 4: Write the shared test base class**

Create `back-end/tests/Feature/Reports/ReportTestCase.php`. It extends the existing
`SetTestCase` to reuse its `makeLocation`, `makeUserAt`, `makeAppliance`, `setStock`, and
`makeReceiving` helpers rather than duplicating a hundred lines of fixtures.

```php
<?php

namespace Tests\Feature\Reports;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sets\SetTestCase;

/**
 * Fixtures for the report suites.
 *
 * Extends SetTestCase for its location/user/product/stock builders. The extra
 * helpers here are the two things reports need that no other suite does:
 * granting report permissions to a LOCATION-SCOPED user (roles that hold
 * report_viewing are all centralized, so scoping is only reachable via an
 * override), and resolving movement type ids by name.
 */
abstract class ReportTestCase extends SetTestCase
{
    /** Movement types are seeded constants; resolve by name so ids may shift. */
    protected function movementTypeId(string $name): int
    {
        return (int) DB::table('transaction_type_lookup')
            ->where('transaction_name', $name)
            ->value('transaction_type_id');
    }

    /**
     * Grant a permission to one user without touching their role.
     *
     * This is the only way to produce a location-scoped user who can run
     * reports: report_viewing is seeded to admin/inventory_analyst/auditor,
     * all three of which are centralized. Promoting the user's role to get
     * the permission would also make them centralized and test nothing.
     */
    protected function grantPermission(User $user, string $feature, string $action): void
    {
        $permission = Permission::firstOrCreate(
            ['feature' => $feature, 'action' => $action],
            ['description' => "{$action} access for {$feature}"]
        );

        UserPermissionOverride::updateOrCreate(
            ['user_id' => $user->user_id, 'permission_id' => $permission->permission_id],
            ['granted' => true]
        );
    }
}
```

- [ ] **Step 5: Run the migration and the test**

```bash
cd back-end && php artisan migrate && php artisan test --filter=ReportRunModelTest
```

Expected: migration creates `report_runs`, test PASSES.

If `UserPermissionOverride`'s column names differ from `granted`, read
`app/Models/UserPermissionOverride.php` and adjust `grantPermission` — the override table is
the mechanism, the column name is whatever that model declares.

- [ ] **Step 6: Commit**

```bash
git add back-end/database/migrations/2026_08_17_000001_create_report_runs_table.php \
        back-end/app/Models/ReportRun.php \
        back-end/tests/Feature/Reports/
git commit -m "feat(reports): record every generated report as an immutable run"
```

---

## Task 2: The `report_viewing.create` permission

Generating a report writes a file and a row. `read` alone should not authorize that.

**Files:**
- Modify: `back-end/database/seeders/PermissionSeeder.php:245-247`
- Create: `back-end/database/migrations/2026_08_17_000002_add_report_viewing_create_permission.php`
- Test: `back-end/tests/Feature/Reports/ReportPermissionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Reports;

use App\Models\Permission;
use App\Support\PermissionResolver;

class ReportPermissionTest extends ReportTestCase
{
    public function test_report_viewing_create_exists_and_is_held_by_analysts(): void
    {
        $this->assertNotNull(
            Permission::where('feature', 'report_viewing')->where('action', 'create')->first(),
            'report_viewing.create must exist — generating a report is a write.'
        );

        $analyst = $this->makeUserAt($this->makeLocation(), 'inventory_analyst');

        $this->assertTrue(
            app(PermissionResolver::class)->canUser((int) $analyst->user_id, 'report_viewing', 'create')
        );
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd back-end && php artisan test --filter=ReportPermissionTest
```

Expected: FAIL — "report_viewing.create must exist".

- [ ] **Step 3: Add the action to the seeder**

In `back-end/database/seeders/PermissionSeeder.php`, replace:

```php
            'report_viewing' => [
                'read' => ['admin', 'inventory_analyst', 'auditor'],
            ],
```

with:

```php
            'report_viewing' => [
                'read'   => ['admin', 'inventory_analyst', 'auditor'],
                // Generating writes a file and a report_runs row, so it is a
                // separate action from viewing the catalog.
                'create' => ['admin', 'inventory_analyst', 'auditor'],
            ],
```

- [ ] **Step 4: Write the migration for already-deployed databases**

```php
<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The seeder only runs on a fresh database. This grants the new action on
     * existing ones, to the same three roles that already hold report_viewing.read.
     */
    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['feature' => 'report_viewing', 'action' => 'create'],
            ['description' => 'Create access for report viewing']
        );

        $roleIds = Role::whereIn('role_name', ['admin', 'inventory_analyst', 'auditor'])
            ->pluck('role_id');

        foreach ($roleIds as $roleId) {
            RolePermission::firstOrCreate([
                'role_id'       => $roleId,
                'permission_id' => $permission->permission_id,
            ]);
        }
    }

    public function down(): void
    {
        $permission = Permission::where('feature', 'report_viewing')
            ->where('action', 'create')->first();

        if ($permission) {
            RolePermission::where('permission_id', $permission->permission_id)->delete();
            $permission->delete();
        }
    }
};
```

- [ ] **Step 5: Migrate and run the test**

```bash
cd back-end && php artisan migrate && php artisan test --filter=ReportPermissionTest
```

Expected: PASS.

If `Role` has no `role_name` column, check `app/Models/Role.php` for the actual column and
adjust the `whereIn`.

- [ ] **Step 6: Commit**

```bash
git add back-end/database/seeders/PermissionSeeder.php \
        back-end/database/migrations/2026_08_17_000002_add_report_viewing_create_permission.php \
        back-end/tests/Feature/Reports/ReportPermissionTest.php
git commit -m "feat(reports): generating a report needs its own permission"
```

---

## Task 3: Core value objects and the definition contract

**Files:**
- Create: `back-end/app/Reports/ReportParameter.php`
- Create: `back-end/app/Reports/ReportParameters.php`
- Create: `back-end/app/Reports/ReportDataset.php`
- Create: `back-end/app/Reports/ReportDefinition.php`
- Test: `back-end/tests/Feature/Reports/ReportContractsTest.php`

- [ ] **Step 1: Write `ReportParameter`**

```php
<?php

namespace App\Reports;

/**
 * One filter a report declares it honors.
 *
 * The frontend builds its dialog inputs from these, and the runner builds its
 * validation rules from them, so a report cannot be offered a filter it does
 * not implement — nor quietly ignore one that was sent.
 */
class ReportParameter
{
    public const TYPE_DATE_RANGE    = 'date_range';
    public const TYPE_LOCATION      = 'location';
    public const TYPE_LOCATION_TYPE = 'location_type';
    public const TYPE_CATEGORY      = 'category';
    public const TYPE_USER          = 'user';
    public const TYPE_SELECT        = 'select';

    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $label,
        public readonly bool $required = false,
        public readonly array $options = [],
        public readonly mixed $default = null,
    ) {
    }

    /**
     * The request keys this parameter consumes. A date range is two fields on
     * the wire (date_from / date_to) but one control in the dialog.
     */
    public function requestKeys(): array
    {
        return $this->type === self::TYPE_DATE_RANGE
            ? ['date_from', 'date_to']
            : [$this->key];
    }

    /** Laravel validation rules, keyed by request field. */
    public function rules(): array
    {
        $required = $this->required ? 'required' : 'nullable';

        return match ($this->type) {
            self::TYPE_DATE_RANGE => [
                'date_from' => "{$required}|date",
                'date_to'   => "{$required}|date|after_or_equal:date_from",
            ],
            self::TYPE_LOCATION => [
                $this->key => "{$required}|integer|exists:locations,location_id",
            ],
            self::TYPE_LOCATION_TYPE => [
                $this->key => "{$required}|string|in:warehouse,showroom,branch_office",
            ],
            self::TYPE_CATEGORY => [
                $this->key => "{$required}|integer|exists:category_lookup,category_id",
            ],
            self::TYPE_USER => [
                $this->key => "{$required}|integer|exists:users,user_id",
            ],
            self::TYPE_SELECT => [
                $this->key => "{$required}|string|in:" . implode(',', array_keys($this->options)),
            ],
        };
    }

    public function toArray(): array
    {
        return [
            'key'      => $this->key,
            'type'     => $this->type,
            'label'    => $this->label,
            'required' => $this->required,
            'options'  => $this->options,
            'default'  => $this->default,
        ];
    }
}
```

- [ ] **Step 2: Write `ReportParameters`**

```php
<?php

namespace App\Reports;

use Carbon\CarbonImmutable;

/** Validated parameter values, handed to a definition's build(). */
class ReportParameters
{
    public function __construct(private readonly array $values)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]) && $this->values[$key] !== '';
    }

    public function int(string $key): ?int
    {
        return $this->has($key) ? (int) $this->values[$key] : null;
    }

    /** Start of the from-day, so a same-day range includes that whole day. */
    public function dateFrom(): ?CarbonImmutable
    {
        return $this->has('date_from')
            ? CarbonImmutable::parse($this->values['date_from'])->startOfDay()
            : null;
    }

    /** End of the to-day — a range of 1st..1st must not be an empty window. */
    public function dateTo(): ?CarbonImmutable
    {
        return $this->has('date_to')
            ? CarbonImmutable::parse($this->values['date_to'])->endOfDay()
            : null;
    }

    public function all(): array
    {
        return $this->values;
    }
}
```

- [ ] **Step 3: Write `ReportDataset`**

```php
<?php

namespace App\Reports;

/**
 * What a report produced.
 *
 * `columns` + `rows` are the flat shape, always present, and are what CSV
 * writes. `sections` is optional and used only by the PDF renderer, for
 * reports that read better as several tables than as one wide one — a
 * spreadsheet cannot filter stacked sections, so CSV never sees them.
 */
class ReportDataset
{
    /**
     * @param array<string,string> $columns  request key => display label
     * @param array<int,array>     $rows     each keyed by the column keys
     * @param array<string,string> $summary  label => formatted total
     * @param array<int,array>     $sections ['title' =>, 'columns' =>, 'rows' =>]
     * @param array<int,string>    $notes    caveats printed under the tables
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $summary = [],
        public readonly array $sections = [],
        public readonly array $notes = [],
    ) {
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
```

- [ ] **Step 4: Write the `ReportDefinition` interface**

```php
<?php

namespace App\Reports;

use App\Models\User;

interface ReportDefinition
{
    /** Stable slug used in URLs and filenames, e.g. "inventory-summary". */
    public function key(): string;

    public function title(): string;

    public function description(): string;

    /** @return ReportParameter[] */
    public function parameters(): array;

    /** @return string[] subset of ['pdf', 'csv'] */
    public function formats(): array;

    /** dompdf dies on very large tables; above this, PDF is refused. */
    public function pdfRowCap(): int;

    /** Whether this user may run it at all — drives catalog visibility. */
    public function availableTo(User $user): bool;

    public function build(ReportParameters $params, User $user): ReportDataset;
}
```

- [ ] **Step 5: Write the test**

```php
<?php

namespace Tests\Feature\Reports;

use App\Reports\ReportParameter;
use App\Reports\ReportParameters;

class ReportContractsTest extends ReportTestCase
{
    public function test_a_date_range_consumes_two_request_fields(): void
    {
        $param = new ReportParameter('date_range', ReportParameter::TYPE_DATE_RANGE, 'Period', true);

        $this->assertSame(['date_from', 'date_to'], $param->requestKeys());
        $this->assertArrayHasKey('date_to', $param->rules());
        $this->assertStringContainsString('required', $param->rules()['date_from']);
    }

    public function test_a_single_day_range_covers_that_whole_day(): void
    {
        $params = new ReportParameters(['date_from' => '2026-08-17', 'date_to' => '2026-08-17']);

        $this->assertSame('2026-08-17 00:00:00', $params->dateFrom()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-17 23:59:59', $params->dateTo()->format('Y-m-d H:i:s'));
    }

    public function test_optional_parameters_are_nullable(): void
    {
        $param = new ReportParameter('location_id', ReportParameter::TYPE_LOCATION, 'Location');

        $this->assertStringContainsString('nullable', $param->rules()['location_id']);
    }
}
```

- [ ] **Step 6: Run the test**

```bash
cd back-end && php artisan test --filter=ReportContractsTest
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Reports/ back-end/tests/Feature/Reports/ReportContractsTest.php
git commit -m "feat(reports): a report declares its own filters, formats and shape"
```

---

## Task 4: The registry

**Files:**
- Create: `back-end/app/Reports/ReportRegistry.php`
- Test: `back-end/tests/Feature/Reports/ReportRegistryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Reports;

use App\Reports\ReportRegistry;

class ReportRegistryTest extends ReportTestCase
{
    public function test_it_resolves_a_definition_by_key(): void
    {
        $registry = app(ReportRegistry::class);

        $this->assertTrue($registry->has('inventory-summary'));
        $this->assertSame('inventory-summary', $registry->get('inventory-summary')->key());
    }

    public function test_an_unknown_key_is_not_found(): void
    {
        $this->assertFalse(app(ReportRegistry::class)->has('does-not-exist'));
        $this->assertNull(app(ReportRegistry::class)->get('does-not-exist'));
    }

    public function test_the_catalog_hides_reports_a_user_cannot_run(): void
    {
        $scoped = $this->makeUserAt($this->makeLocation(), 'branch_manager');
        $keys = collect(app(ReportRegistry::class)->availableTo($scoped))
            ->map(fn ($d) => $d->key())->all();

        $this->assertNotContains('user-activity', $keys,
            'User Activity has no location column, so a scoped user must not be offered it.');
        $this->assertContains('inventory-summary', $keys);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd back-end && php artisan test --filter=ReportRegistryTest
```

Expected: FAIL — class `App\Reports\ReportRegistry` not found.

- [ ] **Step 3: Write the registry**

The definition classes do not exist yet — Tasks 6-11 create them. Write the registry with
the full list now and expect the test to stay red until Task 6; that is fine and expected,
and Step 5 says so explicitly.

```php
<?php

namespace App\Reports;

use App\Models\User;
use App\Reports\Definitions\InventorySummaryReport;
use App\Reports\Definitions\LocationPerformanceReport;
use App\Reports\Definitions\LowStockReport;
use App\Reports\Definitions\TransactionReport;
use App\Reports\Definitions\UserActivityReport;
use App\Reports\Definitions\WarehouseUtilizationReport;

/**
 * The one place that knows which reports exist.
 *
 * Adding a report is a class plus a line here — no controller, route, or
 * frontend change, because the page renders whatever the catalog returns.
 */
class ReportRegistry
{
    private const DEFINITIONS = [
        InventorySummaryReport::class,
        TransactionReport::class,
        LocationPerformanceReport::class,
        LowStockReport::class,
        WarehouseUtilizationReport::class,
        UserActivityReport::class,
    ];

    /** @var array<string,ReportDefinition>|null */
    private ?array $resolved = null;

    /** @return array<string,ReportDefinition> */
    public function all(): array
    {
        if ($this->resolved === null) {
            $this->resolved = [];
            foreach (self::DEFINITIONS as $class) {
                $definition = app($class);
                $this->resolved[$definition->key()] = $definition;
            }
        }

        return $this->resolved;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): ?ReportDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /** @return ReportDefinition[] the reports this user may actually run */
    public function availableTo(User $user): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (ReportDefinition $d) => $d->availableTo($user)
        ));
    }
}
```

- [ ] **Step 4: Commit the registry**

```bash
git add back-end/app/Reports/ReportRegistry.php back-end/tests/Feature/Reports/ReportRegistryTest.php
git commit -m "feat(reports): one registry decides which reports exist"
```

- [ ] **Step 5: Note on test status**

`ReportRegistryTest` will fail with "Class InventorySummaryReport not found" until Task 6
lands the first definition, and will not fully pass until Task 11 lands the last. Do not
try to make it green now by trimming the list — the list is the design. Re-run it at the
end of Task 11.

---

## Task 5: The renderers

**Files:**
- Create: `back-end/app/Reports/Renderers/CsvReportRenderer.php`
- Create: `back-end/app/Reports/Renderers/PdfReportRenderer.php`
- Create: `back-end/resources/views/reports/document.blade.php`
- Test: `back-end/tests/Feature/Reports/ReportRendererTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Reports;

use App\Reports\ReportDataset;
use App\Reports\Renderers\CsvReportRenderer;
use App\Reports\Renderers\PdfReportRenderer;

class ReportRendererTest extends ReportTestCase
{
    private function dataset(): ReportDataset
    {
        return new ReportDataset(
            columns: ['product' => 'Product', 'qty' => 'Qty'],
            rows: [
                ['product' => 'Split Type 1.5HP', 'qty' => 4],
                ['product' => 'Copper Tube, 1/4"', 'qty' => 12],
            ],
            summary: ['Total units' => '16'],
            notes: ['Occupancy is as of generation time.'],
        );
    }

    public function test_csv_writes_a_header_row_then_the_data(): void
    {
        $csv = app(CsvReportRenderer::class)->render($this->dataset(), 'Inventory Summary', []);
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertSame('Product,Qty', trim($lines[0]));
        $this->assertStringContainsString('Split Type 1.5HP', $lines[1]);
    }

    /** A comma or quote in a product name must not shift every later column. */
    public function test_csv_quotes_values_containing_commas(): void
    {
        $csv = app(CsvReportRenderer::class)->render($this->dataset(), 'Inventory Summary', []);

        $this->assertStringContainsString('"Copper Tube, 1/4"""', $csv);
    }

    public function test_pdf_renders_a_document_containing_the_rows(): void
    {
        $bytes = app(PdfReportRenderer::class)->render($this->dataset(), 'Inventory Summary', [
            'Location' => 'CDO Warehouse A',
        ]);

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThan(1000, strlen($bytes));
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd back-end && php artisan test --filter=ReportRendererTest
```

Expected: FAIL — renderer classes not found.

- [ ] **Step 3: Write the CSV renderer**

```php
<?php

namespace App\Reports\Renderers;

use App\Reports\ReportDataset;

/**
 * Flat CSV: one header row, then one row per data row.
 *
 * Sections are deliberately ignored — a spreadsheet cannot filter stacked
 * tables, so a multi-section report flattens to one row per subject with every
 * metric as its own column. Written through fputcsv so a product name
 * containing a comma or a quote cannot shift the remaining columns.
 */
class CsvReportRenderer
{
    /** @param array<string,string> $meta filter summary, prepended as comments */
    public function render(ReportDataset $dataset, string $title, array $meta): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [$title]);
        foreach ($meta as $label => $value) {
            fputcsv($handle, [$label, $value]);
        }
        if ($meta !== []) {
            fputcsv($handle, []);
        }

        fputcsv($handle, array_values($dataset->columns));

        foreach ($dataset->rows as $row) {
            $line = [];
            foreach (array_keys($dataset->columns) as $key) {
                $line[] = $row[$key] ?? '';
            }
            fputcsv($handle, $line);
        }

        if ($dataset->summary !== []) {
            fputcsv($handle, []);
            foreach ($dataset->summary as $label => $value) {
                fputcsv($handle, [$label, $value]);
            }
        }

        foreach ($dataset->notes as $note) {
            fputcsv($handle, [$note]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
```

- [ ] **Step 4: Write the PDF blade view**

Create `back-end/resources/views/reports/document.blade.php`. The inline-style,
`@page{margin:0}` approach matches the existing documents (see
`resources/views/adjustments/document.blade.php`) — dompdf supports no external stylesheets.

```blade
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<title>{{ $title }}</title>
<style>
@page{margin:0}*{margin:0;padding:0;box-sizing:border-box}
body{padding:32pt;font-family:Arial,DejaVu Sans,sans-serif;color:#000;font-size:9px}
.company{text-align:center;border-bottom:2px solid #000;padding-bottom:7px;margin-bottom:9px}
.company h1{font-family:"Times New Roman",DejaVu Serif,serif;font-size:20px;text-transform:uppercase}
.company div{font-size:8px;margin-top:2px}
.title{margin-bottom:8px}
.title h2{font-family:"Times New Roman",DejaVu Serif,serif;font-size:15px;text-transform:uppercase}
.meta{width:100%;border-collapse:collapse;margin-bottom:10px}
.meta td{padding:2px 4px;vertical-align:top}
.meta .label{font-weight:bold;width:110px;white-space:nowrap;text-transform:uppercase;font-size:8px}
.section-title{font-weight:bold;text-transform:uppercase;font-size:9px;margin:12px 0 4px}
table.data{width:100%;border-collapse:collapse;page-break-inside:auto}
table.data th,table.data td{border:1px solid #000;padding:3px 4px;vertical-align:top}
table.data th{text-align:left;text-transform:uppercase;font-size:8px;background:#eee}
table.data tr{page-break-inside:avoid}
.summary{margin-top:10px;border-top:1px solid #000;padding-top:5px}
.summary td{padding:1px 4px;font-size:9px}
.summary .label{font-weight:bold}
.notes{margin-top:10px;font-size:8px;font-style:italic}
.footer{margin-top:16px;font-size:7px;text-align:center;color:#444}
</style></head><body>

<div class="company"><h1>{{ $company['name'] }}</h1>
@if($company['address'])<div>{{ $company['address'] }}</div>@endif
@if($company['email'] || $company['phone'])<div>{{ collect([$company['email'],$company['phone']])->filter()->implode(' · ') }}</div>@endif</div>

<div class="title"><h2>{{ $title }}</h2></div>

<table class="meta">
@foreach($meta as $label => $value)
<tr><td class="label">{{ $label }}:</td><td>{{ $value }}</td></tr>
@endforeach
<tr><td class="label">Generated:</td><td>{{ $generated_at }}</td></tr>
</table>

@forelse($sections as $section)
<div class="section-title">{{ $section['title'] }}</div>
<table class="data"><thead><tr>
@foreach($section['columns'] as $label)<th>{{ $label }}</th>@endforeach
</tr></thead><tbody>
@forelse($section['rows'] as $row)
<tr>@foreach(array_keys($section['columns']) as $key)<td>{{ $row[$key] ?? '—' }}</td>@endforeach</tr>
@empty
<tr><td colspan="{{ count($section['columns']) }}">No data.</td></tr>
@endforelse
</tbody></table>
@empty
<table class="data"><thead><tr>
@foreach($columns as $label)<th>{{ $label }}</th>@endforeach
</tr></thead><tbody>
@forelse($rows as $row)
<tr>@foreach(array_keys($columns) as $key)<td>{{ $row[$key] ?? '—' }}</td>@endforeach</tr>
@empty
<tr><td colspan="{{ count($columns) }}">No data.</td></tr>
@endforelse
</tbody></table>
@endforelse

@if(!empty($summary))
<table class="summary">
@foreach($summary as $label => $value)
<tr><td class="label">{{ $label }}:</td><td>{{ $value }}</td></tr>
@endforeach
</table>
@endif

@foreach($notes as $note)<div class="notes">{{ $note }}</div>@endforeach

<div class="footer">{{ $company['name'] }} · {{ $title }} · generated {{ $generated_at }}</div>
</body></html>
```

- [ ] **Step 5: Write the PDF renderer**

```php
<?php

namespace App\Reports\Renderers;

use App\Models\SystemSetting;
use App\Reports\ReportDataset;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders through the shared reports/document blade, matching the paper-size
 * and company-header conventions of the existing documents (see
 * AdjustmentController::pdf).
 */
class PdfReportRenderer
{
    private const PAPER_WIDTHS = ['a4' => 595.28, 'letter' => 612, 'legal' => 612, 'a5' => 419.53];

    /** @param array<string,string> $meta filter summary shown under the title */
    public function render(ReportDataset $dataset, string $title, array $meta): string
    {
        $paperSize = SystemSetting::pdfPaperSize();
        if (! isset(self::PAPER_WIDTHS[$paperSize])) {
            $paperSize = 'a4';
        }

        return Pdf::loadView('reports.document', [
            'title'        => $title,
            'meta'         => $meta,
            'columns'      => $dataset->columns,
            'rows'         => $dataset->rows,
            'sections'     => $dataset->sections,
            'summary'      => $dataset->summary,
            'notes'        => $dataset->notes,
            'generated_at' => now()->format('Y-m-d H:i'),
            'company'      => [
                'name'    => SystemSetting::companyName(),
                'address' => SystemSetting::companyAddress(),
                'email'   => SystemSetting::companyEmail(),
                'phone'   => SystemSetting::companyPhone(),
            ],
        ])->setPaper($paperSize)->output();
    }
}
```

- [ ] **Step 6: Run the tests**

```bash
cd back-end && php artisan test --filter=ReportRendererTest
```

Expected: PASS, all three.

If the PDF test fails on memory, that is the exact failure mode the row cap in Task 12
exists to prevent — but with two rows it indicates a dompdf config problem, not a cap issue.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Reports/Renderers/ back-end/resources/views/reports/ \
        back-end/tests/Feature/Reports/ReportRendererTest.php
git commit -m "feat(reports): render a dataset to PDF or CSV"
```

---

## Task 6: Inventory Summary report

**Files:**
- Create: `back-end/app/Reports/Definitions/InventorySummaryReport.php`
- Test: `back-end/tests/Feature/Reports/InventorySummaryReportTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Reports;

use App\Reports\Definitions\InventorySummaryReport;
use App\Reports\ReportParameters;

class InventorySummaryReportTest extends ReportTestCase
{
    public function test_it_values_stock_at_cost_price(): void
    {
        $location = $this->makeLocation('CDO Warehouse A');
        $product  = $this->makeAppliance(cost: 24000);
        $this->setStock($location->location_id, $product->product_id, 3);

        $admin = $this->makeUserAt($location, 'admin');

        $dataset = app(InventorySummaryReport::class)
            ->build(new ReportParameters(['location_id' => $location->location_id]), $admin);

        $row = collect($dataset->rows)->firstWhere('product', $product->product_name);

        $this->assertNotNull($row, 'The stocked product must appear.');
        $this->assertSame(3, $row['quantity_on_hand']);
        $this->assertSame('72,000.00', $row['stock_value']);
    }

    public function test_it_declares_no_date_range(): void
    {
        $types = collect(app(InventorySummaryReport::class)->parameters())->pluck('type')->all();

        $this->assertNotContains('date_range', $types,
            'Stock is a live snapshot — there is no history table to reconstruct an as-of date from.');
    }

    public function test_a_scoped_user_sees_only_their_own_location(): void
    {
        $mine   = $this->makeLocation('Mine');
        $theirs = $this->makeLocation('Theirs');
        $product = $this->makeAppliance();

        $this->setStock($mine->location_id, $product->product_id, 5);
        $this->setStock($theirs->location_id, $product->product_id, 9);

        $scoped = $this->makeUserAt($mine, 'branch_manager');

        $dataset = app(InventorySummaryReport::class)->build(new ReportParameters([]), $scoped);

        $this->assertSame(['Mine'], collect($dataset->rows)->pluck('location')->unique()->values()->all());
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd back-end && php artisan test --filter=InventorySummaryReportTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write the definition**

```php
<?php

namespace App\Reports\Definitions;

use App\Models\Inventory;
use App\Models\User;
use App\Reports\ReportDataset;
use App\Reports\ReportDefinition;
use App\Reports\ReportParameter;
use App\Reports\ReportParameters;

/**
 * Current stock and what it is worth, per product per location.
 *
 * Deliberately has no date range. There is no historical stock table, so an
 * "as of" filter could only be honoured by pretending — the document header
 * states plainly that it is a live snapshot.
 */
class InventorySummaryReport implements ReportDefinition
{
    public function key(): string
    {
        return 'inventory-summary';
    }

    public function title(): string
    {
        return 'Inventory Summary Report';
    }

    public function description(): string
    {
        return 'Current stock and valuation across all locations';
    }

    public function parameters(): array
    {
        return [
            new ReportParameter('location_id', ReportParameter::TYPE_LOCATION, 'Location'),
            new ReportParameter('category_id', ReportParameter::TYPE_CATEGORY, 'Category'),
        ];
    }

    public function formats(): array
    {
        return ['pdf', 'csv'];
    }

    public function pdfRowCap(): int
    {
        return 5000;
    }

    public function availableTo(User $user): bool
    {
        return true;
    }

    public function build(ReportParameters $params, User $user): ReportDataset
    {
        $query = Inventory::query()
            ->forUser($user)
            ->with(['location', 'product.category'])
            ->where('quantity_on_hand', '>', 0);

        if ($params->has('location_id')) {
            $query->where('location_id', $params->int('location_id'));
        }

        if ($params->has('category_id')) {
            $categoryId = $params->int('category_id');
            $query->whereHas('product', fn ($q) => $q->where('category_id', $categoryId));
        }

        $rows = [];
        $totalUnits = 0;
        $totalValue = 0.0;

        foreach ($query->get() as $inventory) {
            $product = $inventory->product;
            $cost    = (float) ($product->cost_price ?? 0);
            $qty     = (int) $inventory->quantity_on_hand;
            $value   = $cost * $qty;

            $totalUnits += $qty;
            $totalValue += $value;

            $rows[] = [
                'product'            => $product?->product_name ?? '—',
                'product_code'       => $product?->product_code ?? '—',
                'category'           => $product?->category?->category_name ?? '—',
                'location'           => $inventory->location?->location_name ?? '—',
                'quantity_on_hand'   => $qty,
                'available_quantity' => (int) $inventory->available_quantity,
                'reorder_level'      => (int) $inventory->reorder_level,
                'cost_price'         => number_format($cost, 2),
                'stock_value'        => number_format($value, 2),
            ];
        }

        usort($rows, fn ($a, $b) => [$a['location'], $a['product']] <=> [$b['location'], $b['product']]);

        return new ReportDataset(
            columns: [
                'product'            => 'Product',
                'product_code'       => 'Code',
                'category'           => 'Category',
                'location'           => 'Location',
                'quantity_on_hand'   => 'On Hand',
                'available_quantity' => 'Available',
                'reorder_level'      => 'Reorder Level',
                'cost_price'         => 'Unit Cost',
                'stock_value'        => 'Stock Value',
            ],
            rows: $rows,
            summary: [
                'Stock lines'     => number_format(count($rows)),
                'Total units'     => number_format($totalUnits),
                'Total valuation' => number_format($totalValue, 2),
            ],
            notes: ['Stock levels are current as of the generation time; this report has no historical range.'],
        );
    }
}
```

- [ ] **Step 4: Run the test**

```bash
cd back-end && php artisan test --filter=InventorySummaryReportTest
```

Expected: PASS, all three.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Reports/Definitions/InventorySummaryReport.php \
        back-end/tests/Feature/Reports/InventorySummaryReportTest.php
git commit -m "feat(reports): inventory summary values live stock at cost"
```

---

## Task 7: Low Stock report

Done before the movement reports because it is self-contained and fixes a live bug.

**Files:**
- Create: `back-end/app/Reports/Definitions/LowStockReport.php`
- Test: `back-end/tests/Feature/Reports/LowStockReportTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Reports;

use App\Models\Inventory;
use App\Reports\Definitions\LowStockReport;
use App\Reports\ReportParameters;

class LowStockReportTest extends ReportTestCase
{
    public function test_it_uses_the_per_location_reorder_level(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $admin    = $this->makeUserAt($location, 'admin');

        $inventory = $this->setStock($location->location_id, $product->product_id, 2);
        $inventory->update(['reorder_level' => 5]);

        $dataset = app(LowStockReport::class)->build(new ReportParameters([]), $admin);
        $row = collect($dataset->rows)->firstWhere('product_code', $product->product_code);

        $this->assertNotNull($row, 'Stock of 2 against a reorder level of 5 is low.');
        $this->assertSame(5, $row['threshold']);
        $this->assertSame(3, $row['shortfall']);
        $this->assertSame('Location reorder level', $row['threshold_source']);
    }

    /**
     * The bug in the closure this replaces: it compared against the global
     * products.recommended_stocks and ignored inventory.reorder_level entirely,
     * so per-location shortfalls were invisible.
     */
    public function test_it_falls_back_to_the_product_default_when_no_location_level_is_set(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $product->update(['recommended_stocks' => 8]);
        $admin = $this->makeUserAt($location, 'admin');

        $inventory = $this->setStock($location->location_id, $product->product_id, 1);
        $inventory->update(['reorder_level' => 0]);

        $dataset = app(LowStockReport::class)->build(new ReportParameters([]), $admin);
        $row = collect($dataset->rows)->firstWhere('product_code', $product->product_code);

        $this->assertNotNull($row);
        $this->assertSame(8, $row['threshold']);
        $this->assertSame('Product default', $row['threshold_source']);
    }

    public function test_well_stocked_items_are_absent(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $admin    = $this->makeUserAt($location, 'admin');

        $inventory = $this->setStock($location->location_id, $product->product_id, 50);
        $inventory->update(['reorder_level' => 5]);

        $dataset = app(LowStockReport::class)->build(new ReportParameters([]), $admin);

        $this->assertNull(collect($dataset->rows)->firstWhere('product_code', $product->product_code));
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd back-end && php artisan test --filter=LowStockReportTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write the definition**

```php
<?php

namespace App\Reports\Definitions;

use App\Models\Inventory;
use App\Models\User;
use App\Reports\ReportDataset;
use App\Reports\ReportDefinition;
use App\Reports\ReportParameter;
use App\Reports\ReportParameters;

/**
 * Stock at or below its reorder threshold.
 *
 * The threshold is the per-location inventory.reorder_level, falling back to
 * the global products.recommended_stocks when the location has not set one.
 * The old reports/low-stock-report closure compared against the global value
 * ONLY, which made per-location shortfalls invisible. The row names which
 * threshold applied so a flagged line is never mysterious.
 */
class LowStockReport implements ReportDefinition
{
    private const SOURCE_LOCATION = 'Location reorder level';
    private const SOURCE_PRODUCT  = 'Product default';

    public function key(): string
    {
        return 'low-stock';
    }

    public function title(): string
    {
        return 'Low Stock Report';
    }

    public function description(): string
    {
        return 'Items at or below their reorder level';
    }

    public function parameters(): array
    {
        return [
            new ReportParameter('location_id', ReportParameter::TYPE_LOCATION, 'Location'),
            new ReportParameter('category_id', ReportParameter::TYPE_CATEGORY, 'Category'),
        ];
    }

    public function formats(): array
    {
        return ['pdf', 'csv'];
    }

    public function pdfRowCap(): int
    {
        return 5000;
    }

    public function availableTo(User $user): bool
    {
        return true;
    }

    public function build(ReportParameters $params, User $user): ReportDataset
    {
        $query = Inventory::query()
            ->forUser($user)
            ->with(['location', 'product.category']);

        if ($params->has('location_id')) {
            $query->where('location_id', $params->int('location_id'));
        }

        if ($params->has('category_id')) {
            $categoryId = $params->int('category_id');
            $query->whereHas('product', fn ($q) => $q->where('category_id', $categoryId));
        }

        $rows = [];

        foreach ($query->get() as $inventory) {
            $product = $inventory->product;
            if (! $product) {
                continue;
            }

            $locationLevel = (int) $inventory->reorder_level;
            $productLevel  = (int) ($product->recommended_stocks ?? 0);

            // A location level of 0 means "not set here", not "never reorder".
            $threshold = $locationLevel > 0 ? $locationLevel : $productLevel;
            $source    = $locationLevel > 0 ? self::SOURCE_LOCATION : self::SOURCE_PRODUCT;

            if ($threshold <= 0) {
                continue;
            }

            $onHand = (int) $inventory->quantity_on_hand;
            if ($onHand > $threshold) {
                continue;
            }

            $rows[] = [
                'product'          => $product->product_name,
                'product_code'     => $product->product_code,
                'category'         => $product->category?->category_name ?? '—',
                'location'         => $inventory->location?->location_name ?? '—',
                'quantity_on_hand' => $onHand,
                'threshold'        => $threshold,
                'threshold_source' => $source,
                'shortfall'        => max(0, $threshold - $onHand),
            ];
        }

        usort($rows, fn ($a, $b) => $b['shortfall'] <=> $a['shortfall']);

        return new ReportDataset(
            columns: [
                'product'          => 'Product',
                'product_code'     => 'Code',
                'category'         => 'Category',
                'location'         => 'Location',
                'quantity_on_hand' => 'On Hand',
                'threshold'        => 'Threshold',
                'threshold_source' => 'Threshold From',
                'shortfall'        => 'Shortfall',
            ],
            rows: $rows,
            summary: [
                'Items below threshold' => number_format(count($rows)),
                'Total shortfall units' => number_format(array_sum(array_column($rows, 'shortfall'))),
            ],
            notes: ['Threshold is the location reorder level where set, otherwise the product default.'],
        );
    }
}
```

- [ ] **Step 4: Run the test**

```bash
cd back-end && php artisan test --filter=LowStockReportTest
```

Expected: PASS, all three.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Reports/Definitions/LowStockReport.php \
        back-end/tests/Feature/Reports/LowStockReportTest.php
git commit -m "feat(reports): low stock honours the per-location reorder level"
```

---

## Task 8: Transaction report

**Files:**
- Create: `back-end/app/Reports/Definitions/TransactionReport.php`
- Test: `back-end/tests/Feature/Reports/TransactionReportTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Reports;

use App\Models\Transaction;
use App\Reports\Definitions\TransactionReport;
use App\Reports\ReportParameters;

class TransactionReportTest extends ReportTestCase
{
    public function test_it_returns_only_transactions_inside_the_range(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $admin    = $this->makeUserAt($location, 'admin');

        Transaction::create([
            'transaction_code' => 'TX-IN-' . uniqid(),
            'branch_id'   => $location->location_id,
            'product_id'  => $product->product_id,
            'user_id'     => $admin->user_id,
            'type'        => 'sale',
            'quantity'    => 2,
            'unit_price'  => 30000,
            'total_amount' => 60000,
            'date'        => '2026-08-10',
        ]);

        Transaction::create([
            'transaction_code' => 'TX-OUT-' . uniqid(),
            'branch_id'   => $location->location_id,
            'product_id'  => $product->product_id,
            'user_id'     => $admin->user_id,
            'type'        => 'sale',
            'quantity'    => 1,
            'unit_price'  => 30000,
            'total_amount' => 30000,
            'date'        => '2026-07-01',
        ]);

        $dataset = app(TransactionReport::class)->build(
            new ReportParameters(['date_from' => '2026-08-01', 'date_to' => '2026-08-31']),
            $admin
        );

        $this->assertSame(1, $dataset->rowCount(), 'Only the August transaction is in range.');
        $this->assertStringContainsString('TX-IN', $dataset->rows[0]['transaction_code']);
    }

    /** A same-day range must include that day, not resolve to an empty window. */
    public function test_a_single_day_range_includes_that_day(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $admin    = $this->makeUserAt($location, 'admin');

        Transaction::create([
            'transaction_code' => 'TX-' . uniqid(),
            'branch_id'   => $location->location_id,
            'product_id'  => $product->product_id,
            'user_id'     => $admin->user_id,
            'type'        => 'sale',
            'quantity'    => 1,
            'unit_price'  => 100,
            'total_amount' => 100,
            'date'        => '2026-08-17',
        ]);

        $dataset = app(TransactionReport::class)->build(
            new ReportParameters(['date_from' => '2026-08-17', 'date_to' => '2026-08-17']),
            $admin
        );

        $this->assertSame(1, $dataset->rowCount());
    }

    public function test_it_requires_a_date_range(): void
    {
        $range = collect(app(TransactionReport::class)->parameters())
            ->firstWhere('type', 'date_range');

        $this->assertNotNull($range);
        $this->assertTrue($range->required, 'An unbounded transaction export would be enormous.');
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd back-end && php artisan test --filter=TransactionReportTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write the definition**

```php
<?php

namespace App\Reports\Definitions;

use App\Models\Transaction;
use App\Models\User;
use App\Reports\ReportDataset;
use App\Reports\ReportDefinition;
use App\Reports\ReportParameter;
use App\Reports\ReportParameters;

/**
 * Every recorded transaction in a period.
 *
 * NOTE: transactions.branch_id is a legacy column name holding a location_id —
 * Transaction::branch() resolves it to Location. The column is left alone;
 * only the report labels say "location".
 *
 * This is the report most likely to be large, hence the required date range
 * and the CSV path.
 */
class TransactionReport implements ReportDefinition
{
    public function key(): string
    {
        return 'transaction';
    }

    public function title(): string
    {
        return 'Transaction Report';
    }

    public function description(): string
    {
        return 'All recorded inventory transactions for a period';
    }

    public function parameters(): array
    {
        return [
            new ReportParameter('date_range', ReportParameter::TYPE_DATE_RANGE, 'Period', required: true),
            new ReportParameter('location_id', ReportParameter::TYPE_LOCATION, 'Location'),
        ];
    }

    public function formats(): array
    {
        return ['pdf', 'csv'];
    }

    public function pdfRowCap(): int
    {
        return 5000;
    }

    public function availableTo(User $user): bool
    {
        return true;
    }

    public function build(ReportParameters $params, User $user): ReportDataset
    {
        $query = Transaction::query()
            ->with(['branch', 'product', 'user'])
            ->whereBetween('date', [$params->dateFrom(), $params->dateTo()]);

        // Transaction has no location_id column, so the ScopesToUserLocation
        // trait does not apply — the equivalent column is branch_id.
        if (! $user->isCentralized()) {
            $scoped = $user->scopedLocationId();
            $scoped === null
                ? $query->whereRaw('1 = 0')
                : $query->where('branch_id', $scoped);
        }

        if ($params->has('location_id')) {
            $query->where('branch_id', $params->int('location_id'));
        }

        $rows = [];
        $totalAmount = 0.0;
        $totalQty = 0;
        $byType = [];

        foreach ($query->orderBy('date')->get() as $transaction) {
            $amount = (float) ($transaction->total_amount ?? 0);
            $qty    = (int) $transaction->quantity;

            $totalAmount += $amount;
            $totalQty    += $qty;
            $type = $transaction->type ?? '—';
            $byType[$type] = ($byType[$type] ?? 0) + 1;

            $rows[] = [
                'date'             => $transaction->date ? $transaction->date->format('Y-m-d') : '—',
                'transaction_code' => $transaction->transaction_code ?? '—',
                'type'             => $type,
                'location'         => $transaction->branch?->location_name ?? '—',
                'product'          => $transaction->product?->product_name ?? $transaction->item_name ?? '—',
                'quantity'         => $qty,
                'unit_price'       => number_format((float) ($transaction->unit_price ?? 0), 2),
                'total_amount'     => number_format($amount, 2),
                'recorded_by'      => trim(($transaction->user?->first_name ?? '') . ' ' . ($transaction->user?->last_name ?? '')) ?: '—',
            ];
        }

        $summary = [
            'Transactions' => number_format(count($rows)),
            'Total units'  => number_format($totalQty),
            'Total value'  => number_format($totalAmount, 2),
        ];

        foreach ($byType as $type => $count) {
            $summary["Count · {$type}"] = number_format($count);
        }

        return new ReportDataset(
            columns: [
                'date'             => 'Date',
                'transaction_code' => 'Reference',
                'type'             => 'Type',
                'location'         => 'Location',
                'product'          => 'Product',
                'quantity'         => 'Qty',
                'unit_price'       => 'Unit Price',
                'total_amount'     => 'Total',
                'recorded_by'      => 'Recorded By',
            ],
            rows: $rows,
            summary: $summary,
        );
    }
}
```

- [ ] **Step 4: Run the test**

```bash
cd back-end && php artisan test --filter=TransactionReportTest
```

Expected: PASS, all three.

If `Transaction` has no `$casts` entry making `date` a Carbon instance, the
`$transaction->date->format()` call will fail. Read `app/Models/Transaction.php` — if `date`
is a plain string, use `substr((string) $transaction->date, 0, 10)` instead.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Reports/Definitions/TransactionReport.php \
        back-end/tests/Feature/Reports/TransactionReportTest.php
git commit -m "feat(reports): transaction report over a bounded period"
```

---

## Task 9: Location Performance report

**Files:**
- Create: `back-end/app/Reports/Definitions/LocationPerformanceReport.php`
- Test: `back-end/tests/Feature/Reports/LocationPerformanceReportTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Reports;

use App\Models\MaterialMovementLedger;
use App\Reports\Definitions\LocationPerformanceReport;
use App\Reports\ReportParameters;

class LocationPerformanceReportTest extends ReportTestCase
{
    public function test_it_separates_units_in_from_units_out(): void
    {
        $here  = $this->makeLocation('Here');
        $there = $this->makeLocation('There');
        $product = $this->makeAppliance();
        $admin = $this->makeUserAt($here, 'admin');

        $transfer = $this->movementTypeId('Transfer');

        MaterialMovementLedger::create([
            'transaction_type_id' => $transfer,
            'product_id'          => $product->product_id,
            'from_location_id'    => $there->location_id,
            'to_location_id'      => $here->location_id,
            'quantity'            => 7,
            'performed_by'        => $admin->user_id,
        ]);

        MaterialMovementLedger::create([
            'transaction_type_id' => $transfer,
            'product_id'          => $product->product_id,
            'from_location_id'    => $here->location_id,
            'to_location_id'      => $there->location_id,
            'quantity'            => 2,
            'performed_by'        => $admin->user_id,
        ]);

        $dataset = app(LocationPerformanceReport::class)->build(
            new ReportParameters(['date_from' => now()->subDay()->toDateString(), 'date_to' => now()->toDateString()]),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('location', 'Here');

        $this->assertNotNull($row);
        $this->assertSame(7, $row['units_in']);
        $this->assertSame(2, $row['units_out']);
    }

    public function test_each_row_carries_the_location_type(): void
    {
        $location = $this->makeLocation('Typed');
        $admin = $this->makeUserAt($location, 'admin');

        $dataset = app(LocationPerformanceReport::class)->build(
            new ReportParameters(['date_from' => now()->subDay()->toDateString(), 'date_to' => now()->toDateString()]),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('location', 'Typed');

        $this->assertNotNull($row, 'A location with no movement still reports its closing stock.');
        $this->assertSame('warehouse', $row['location_type'],
            'Warehouses and showrooms must be distinguishable — they should not be read as comparable.');
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd back-end && php artisan test --filter=LocationPerformanceReportTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write the definition**

```php
<?php

namespace App\Reports\Definitions;

use App\Models\Location;
use App\Models\User;
use App\Reports\ReportDataset;
use App\Reports\ReportDefinition;
use App\Reports\ReportParameter;
use App\Reports\ReportParameters;
use Illuminate\Support\Facades\DB;

/**
 * Movement and closing valuation per location.
 *
 * Named for locations, not branches: the 2025_01_15 unification folded
 * branches and warehouses into one `locations` table, so "Branch Performance"
 * described a table that no longer exists. Each row carries its location_type
 * so a showroom and a warehouse are never silently compared.
 *
 * No revenue section — sales are recorded through Transactions rather than the
 * `sales` table, so a revenue column here would report zeros.
 */
class LocationPerformanceReport implements ReportDefinition
{
    private const TYPE_ADJUSTMENT = 4;

    public function key(): string
    {
        return 'location-performance';
    }

    public function title(): string
    {
        return 'Location Performance';
    }

    public function description(): string
    {
        return 'Location-wise inventory movement and valuation';
    }

    public function parameters(): array
    {
        return [
            new ReportParameter('date_range', ReportParameter::TYPE_DATE_RANGE, 'Period', required: true),
            new ReportParameter('location_id', ReportParameter::TYPE_LOCATION, 'Location'),
            new ReportParameter('location_type', ReportParameter::TYPE_LOCATION_TYPE, 'Location Type'),
        ];
    }

    public function formats(): array
    {
        return ['pdf', 'csv'];
    }

    public function pdfRowCap(): int
    {
        return 2000;
    }

    public function availableTo(User $user): bool
    {
        return true;
    }

    public function build(ReportParameters $params, User $user): ReportDataset
    {
        $from = $params->dateFrom();
        $to   = $params->dateTo();

        $locations = Location::query()->orderBy('location_name');

        if (! $user->isCentralized()) {
            $scoped = $user->scopedLocationId();
            $scoped === null
                ? $locations->whereRaw('1 = 0')
                : $locations->where('location_id', $scoped);
        }

        if ($params->has('location_id')) {
            $locations->where('location_id', $params->int('location_id'));
        }

        if ($params->has('location_type')) {
            $locations->where('location_type', $params->get('location_type'));
        }

        $locations = $locations->get();
        $locationIds = $locations->pluck('location_id')->all();

        if ($locationIds === []) {
            return new ReportDataset(columns: $this->columns(), rows: []);
        }

        $inbound = DB::table('material_movement_ledger')
            ->selectRaw('to_location_id AS location_id, SUM(quantity) AS units')
            ->whereIn('to_location_id', $locationIds)
            ->whereBetween('created_at', [$from, $to])
            ->where('transaction_type_id', '!=', self::TYPE_ADJUSTMENT)
            ->groupBy('to_location_id')
            ->pluck('units', 'location_id');

        $outbound = DB::table('material_movement_ledger')
            ->selectRaw('from_location_id AS location_id, SUM(quantity) AS units')
            ->whereIn('from_location_id', $locationIds)
            ->whereBetween('created_at', [$from, $to])
            ->where('transaction_type_id', '!=', self::TYPE_ADJUSTMENT)
            ->groupBy('from_location_id')
            ->pluck('units', 'location_id');

        $adjustments = DB::table('material_movement_ledger')
            ->selectRaw('COALESCE(to_location_id, from_location_id) AS location_id, SUM(quantity) AS units')
            ->where('transaction_type_id', self::TYPE_ADJUSTMENT)
            ->whereBetween('created_at', [$from, $to])
            ->groupBy(DB::raw('COALESCE(to_location_id, from_location_id)'))
            ->pluck('units', 'location_id');

        $closing = DB::table('inventory')
            ->join('products', 'products.product_id', '=', 'inventory.product_id')
            ->selectRaw('inventory.location_id, SUM(inventory.quantity_on_hand) AS units,
                         SUM(inventory.quantity_on_hand * COALESCE(products.cost_price, 0)) AS value')
            ->whereIn('inventory.location_id', $locationIds)
            ->groupBy('inventory.location_id')
            ->get()
            ->keyBy('location_id');

        $rows = [];
        $totalIn = 0;
        $totalOut = 0;
        $totalValue = 0.0;

        foreach ($locations as $location) {
            $id = $location->location_id;
            $in  = (int) ($inbound[$id] ?? 0);
            $out = (int) ($outbound[$id] ?? 0);
            $units = (int) ($closing[$id]->units ?? 0);
            $value = (float) ($closing[$id]->value ?? 0);

            $totalIn += $in;
            $totalOut += $out;
            $totalValue += $value;

            $rows[] = [
                'location'          => $location->location_name,
                'location_type'     => $location->location_type,
                'units_in'          => $in,
                'units_out'         => $out,
                'net_adjustments'   => (int) ($adjustments[$id] ?? 0),
                'closing_units'     => $units,
                'closing_valuation' => number_format($value, 2),
            ];
        }

        return new ReportDataset(
            columns: $this->columns(),
            rows: $rows,
            summary: [
                'Locations'         => number_format(count($rows)),
                'Total units in'    => number_format($totalIn),
                'Total units out'   => number_format($totalOut),
                'Closing valuation' => number_format($totalValue, 2),
            ],
            notes: [
                'Movement covers the selected period; closing stock and valuation are current as of generation.',
                'Adjustments are count corrections and are reported separately from movement.',
            ],
        );
    }

    private function columns(): array
    {
        return [
            'location'          => 'Location',
            'location_type'     => 'Type',
            'units_in'          => 'Units In',
            'units_out'         => 'Units Out',
            'net_adjustments'   => 'Net Adjustments',
            'closing_units'     => 'Closing Units',
            'closing_valuation' => 'Closing Valuation',
        ];
    }
}
```

- [ ] **Step 4: Run the test**

```bash
cd back-end && php artisan test --filter=LocationPerformanceReportTest
```

Expected: PASS, both.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Reports/Definitions/LocationPerformanceReport.php \
        back-end/tests/Feature/Reports/LocationPerformanceReportTest.php
git commit -m "feat(reports): performance is measured per location, not per branch"
```

---

## Task 10: Warehouse Utilization report

The largest report: a point-in-time occupancy section plus three period throughput sections.

**Files:**
- Create: `back-end/app/Reports/Definitions/WarehouseUtilizationReport.php`
- Test: `back-end/tests/Feature/Reports/WarehouseUtilizationReportTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Reports;

use App\Models\MaterialMovementLedger;
use App\Models\PurchaseOrder;
use App\Models\Receiving;
use App\Reports\Definitions\WarehouseUtilizationReport;
use App\Reports\ReportParameters;

class WarehouseUtilizationReportTest extends ReportTestCase
{
    private function range(): ReportParameters
    {
        return new ReportParameters([
            'date_from' => now()->subDays(7)->toDateString(),
            'date_to'   => now()->toDateString(),
        ]);
    }

    public function test_it_reports_occupancy_against_capacity(): void
    {
        $location = $this->makeLocation('Cap WH');
        $location->update(['capacity' => 100]);
        $product = $this->makeAppliance();
        $this->setStock($location->location_id, $product->product_id, 25);

        $admin = $this->makeUserAt($location, 'admin');

        $dataset = app(WarehouseUtilizationReport::class)->build($this->range(), $admin);
        $row = collect($dataset->rows)->firstWhere('location', 'Cap WH');

        $this->assertNotNull($row);
        $this->assertSame(25, $row['occupied_units']);
        $this->assertSame('25.0%', $row['utilization']);
    }

    /** A capacity nobody has entered must never render as 0% or divide by zero. */
    public function test_a_location_without_capacity_reads_not_set(): void
    {
        $location = $this->makeLocation('No Cap WH');
        $location->update(['capacity' => null]);
        $admin = $this->makeUserAt($location, 'admin');

        $dataset = app(WarehouseUtilizationReport::class)->build($this->range(), $admin);
        $row = collect($dataset->rows)->firstWhere('location', 'No Cap WH');

        $this->assertNotNull($row);
        $this->assertSame('Not set', $row['utilization']);
        $this->assertSame('Not set', $row['throughput_ratio']);
    }

    public function test_it_counts_units_received_against_purchase_orders(): void
    {
        $location = $this->makeLocation('Recv WH');
        $location->update(['capacity' => 100]);
        $admin = $this->makeUserAt($location, 'admin');

        $po = PurchaseOrder::create([
            'po_number'              => 'PO-' . uniqid(),
            'location_id'            => $location->location_id,
            'order_date'             => now()->subDays(5),
            'expected_delivery_date' => now()->subDays(2),
        ]);

        Receiving::create([
            'po_id'                   => $po->po_id,
            'location_id'             => $location->location_id,
            'receiving_number'        => 'GRN-' . uniqid(),
            'receiving_date'          => now()->subDays(3),
            'total_quantity_received' => 40,
            'total_quantity_damaged'  => 4,
        ]);

        $dataset = app(WarehouseUtilizationReport::class)->build($this->range(), $admin);
        $row = collect($dataset->rows)->firstWhere('location', 'Recv WH');

        $this->assertSame(40, $row['units_received']);
        $this->assertSame(4, $row['units_damaged']);
        $this->assertSame(1, $row['receipts_on_time'], 'Received before the expected date is on time.');
        $this->assertSame(0, $row['receipts_late']);
    }

    /** A PO with no promised date cannot be judged; say so rather than guess. */
    public function test_receipts_without_an_expected_date_are_excluded_from_on_time(): void
    {
        $location = $this->makeLocation('Undated WH');
        $admin = $this->makeUserAt($location, 'admin');

        $po = PurchaseOrder::create([
            'po_number'              => 'PO-' . uniqid(),
            'location_id'            => $location->location_id,
            'order_date'             => now()->subDays(5),
            'expected_delivery_date' => null,
        ]);

        Receiving::create([
            'po_id'                   => $po->po_id,
            'location_id'             => $location->location_id,
            'receiving_number'        => 'GRN-' . uniqid(),
            'receiving_date'          => now()->subDays(3),
            'total_quantity_received' => 10,
            'total_quantity_damaged'  => 0,
        ]);

        $dataset = app(WarehouseUtilizationReport::class)->build($this->range(), $admin);
        $row = collect($dataset->rows)->firstWhere('location', 'Undated WH');

        $this->assertSame(0, $row['receipts_on_time']);
        $this->assertSame(0, $row['receipts_late']);
        $this->assertSame(1, $row['receipts_unrated']);
    }

    /**
     * Receiving is read from `receivings`; transfers and dispatch from the
     * ledger. If Stock In rows were also counted, every received unit would
     * land in the throughput total twice.
     */
    public function test_a_received_unit_is_counted_once_in_throughput(): void
    {
        $location = $this->makeLocation('Double WH');
        $location->update(['capacity' => 100]);
        $product = $this->makeAppliance();
        $admin = $this->makeUserAt($location, 'admin');

        $po = PurchaseOrder::create([
            'po_number'   => 'PO-' . uniqid(),
            'location_id' => $location->location_id,
            'order_date'  => now()->subDays(5),
        ]);

        Receiving::create([
            'po_id'                   => $po->po_id,
            'location_id'             => $location->location_id,
            'receiving_number'        => 'GRN-' . uniqid(),
            'receiving_date'          => now()->subDays(3),
            'total_quantity_received' => 30,
            'total_quantity_damaged'  => 0,
        ]);

        // The same receipt, also written to the ledger as Stock In.
        MaterialMovementLedger::create([
            'transaction_type_id' => $this->movementTypeId('Stock In'),
            'product_id'          => $product->product_id,
            'to_location_id'      => $location->location_id,
            'quantity'            => 30,
            'performed_by'        => $admin->user_id,
        ]);

        $dataset = app(WarehouseUtilizationReport::class)->build($this->range(), $admin);
        $row = collect($dataset->rows)->firstWhere('location', 'Double WH');

        $this->assertSame(30, $row['units_handled'],
            'Stock In must be excluded from the ledger side; receivings already counts it.');
    }

    public function test_return_to_vendor_is_reported_apart_from_dispatch(): void
    {
        $location = $this->makeLocation('RTV WH');
        $product = $this->makeAppliance();
        $admin = $this->makeUserAt($location, 'admin');

        MaterialMovementLedger::create([
            'transaction_type_id' => $this->movementTypeId('Return to Vendor'),
            'product_id'          => $product->product_id,
            'from_location_id'    => $location->location_id,
            'quantity'            => 5,
            'performed_by'        => $admin->user_id,
        ]);

        $dataset = app(WarehouseUtilizationReport::class)->build($this->range(), $admin);
        $row = collect($dataset->rows)->firstWhere('location', 'RTV WH');

        $this->assertSame(0, $row['units_dispatched'], 'A vendor return reverses an inbound; it is not fulfilment.');
        $this->assertSame(5, $row['units_returned_to_vendor']);
    }

    public function test_pdf_gets_sections_while_csv_gets_one_flat_row(): void
    {
        $location = $this->makeLocation('Shape WH');
        $location->update(['capacity' => 50]);
        $admin = $this->makeUserAt($location, 'admin');

        $dataset = app(WarehouseUtilizationReport::class)->build($this->range(), $admin);

        $this->assertNotEmpty($dataset->sections, 'PDF reads better as four tables.');
        $this->assertNotEmpty($dataset->columns, 'CSV needs one flat row per location.');
        $this->assertSame(
            count($dataset->rows),
            count(collect($dataset->rows)->pluck('location')->unique()),
            'One flat row per location.'
        );
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd back-end && php artisan test --filter=WarehouseUtilizationReportTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write the definition**

```php
<?php

namespace App\Reports\Definitions;

use App\Models\Location;
use App\Models\User;
use App\Reports\ReportDataset;
use App\Reports\ReportDefinition;
use App\Reports\ReportParameter;
use App\Reports\ReportParameters;
use Illuminate\Support\Facades\DB;

/**
 * How much of its space a warehouse is using, and how hard it worked that
 * space over a period.
 *
 * Occupancy is a snapshot; receiving, transfers and dispatch are period
 * figures. The two framings are labelled separately in the document so a
 * 62% occupancy is never read as a monthly average.
 *
 * DOUBLE COUNTING: units received come from `receivings` (which carries the PO
 * link and the damaged count the ledger lacks). The ledger-derived figures
 * therefore EXCLUDE Stock In (type 1), or every received unit would be counted
 * twice in units_handled.
 */
class WarehouseUtilizationReport implements ReportDefinition
{
    private const TYPE_STOCK_IN         = 1;
    private const TYPE_STOCK_OUT        = 2;
    private const TYPE_TRANSFER         = 3;
    private const TYPE_RETURN_TO_VENDOR = 5;
    private const TYPE_SALES            = 6;

    public function key(): string
    {
        return 'warehouse-utilization';
    }

    public function title(): string
    {
        return 'Warehouse Utilization';
    }

    public function description(): string
    {
        return 'Capacity, occupancy, and goods throughput';
    }

    public function parameters(): array
    {
        return [
            new ReportParameter('date_range', ReportParameter::TYPE_DATE_RANGE, 'Period', required: true),
            new ReportParameter('location_id', ReportParameter::TYPE_LOCATION, 'Location'),
            new ReportParameter(
                'location_type',
                ReportParameter::TYPE_LOCATION_TYPE,
                'Location Type',
                default: 'warehouse',
            ),
        ];
    }

    public function formats(): array
    {
        return ['pdf', 'csv'];
    }

    public function pdfRowCap(): int
    {
        return 500;
    }

    public function availableTo(User $user): bool
    {
        return true;
    }

    public function build(ReportParameters $params, User $user): ReportDataset
    {
        $from = $params->dateFrom();
        $to   = $params->dateTo();

        $query = Location::query()->orderBy('location_name');

        if (! $user->isCentralized()) {
            $scoped = $user->scopedLocationId();
            $scoped === null
                ? $query->whereRaw('1 = 0')
                : $query->where('location_id', $scoped);
        }

        if ($params->has('location_id')) {
            $query->where('location_id', $params->int('location_id'));
        }

        if ($params->has('location_type')) {
            $query->where('location_type', $params->get('location_type'));
        }

        $locations   = $query->get();
        $locationIds = $locations->pluck('location_id')->all();

        if ($locationIds === []) {
            return new ReportDataset(columns: $this->flatColumns(), rows: []);
        }

        $occupancy = DB::table('inventory')
            ->selectRaw('location_id, SUM(quantity_on_hand) AS units')
            ->whereIn('location_id', $locationIds)
            ->groupBy('location_id')
            ->pluck('units', 'location_id');

        $receiving = DB::table('receivings')
            ->leftJoin('purchase_orders', 'purchase_orders.po_id', '=', 'receivings.po_id')
            ->selectRaw('receivings.location_id,
                COUNT(*) AS grn_count,
                COUNT(DISTINCT receivings.po_id) AS po_count,
                COALESCE(SUM(receivings.total_quantity_received), 0) AS units_received,
                COALESCE(SUM(receivings.total_quantity_damaged), 0) AS units_damaged,
                SUM(CASE WHEN purchase_orders.expected_delivery_date IS NOT NULL
                          AND receivings.receiving_date <= purchase_orders.expected_delivery_date
                         THEN 1 ELSE 0 END) AS on_time,
                SUM(CASE WHEN purchase_orders.expected_delivery_date IS NOT NULL
                          AND receivings.receiving_date > purchase_orders.expected_delivery_date
                         THEN 1 ELSE 0 END) AS late,
                SUM(CASE WHEN purchase_orders.expected_delivery_date IS NULL THEN 1 ELSE 0 END) AS unrated')
            ->whereIn('receivings.location_id', $locationIds)
            ->whereBetween('receivings.receiving_date', [$from, $to])
            ->groupBy('receivings.location_id')
            ->get()
            ->keyBy('location_id');

        $ledgerIn = $this->ledgerSum('to_location_id', [self::TYPE_TRANSFER], $locationIds, $from, $to);
        $ledgerOut = $this->ledgerSum('from_location_id', [self::TYPE_TRANSFER], $locationIds, $from, $to);
        $dispatched = $this->ledgerSum('from_location_id', [self::TYPE_STOCK_OUT, self::TYPE_SALES], $locationIds, $from, $to);
        $returned = $this->ledgerSum('from_location_id', [self::TYPE_RETURN_TO_VENDOR], $locationIds, $from, $to);

        $rows = [];
        $totalCapacity = 0;
        $totalOccupied = 0;
        $totalHandled = 0;
        $anyUnrated = false;

        foreach ($locations as $location) {
            $id       = $location->location_id;
            $capacity = $location->capacity !== null ? (int) $location->capacity : null;
            $occupied = (int) ($occupancy[$id] ?? 0);
            $r        = $receiving[$id] ?? null;

            $received   = (int) ($r->units_received ?? 0);
            $damaged    = (int) ($r->units_damaged ?? 0);
            $onTime     = (int) ($r->on_time ?? 0);
            $late       = (int) ($r->late ?? 0);
            $unrated    = (int) ($r->unrated ?? 0);
            $transferIn  = (int) ($ledgerIn[$id] ?? 0);
            $transferOut = (int) ($ledgerOut[$id] ?? 0);
            $dispatch    = (int) ($dispatched[$id] ?? 0);
            $rtv         = (int) ($returned[$id] ?? 0);

            $handled = $received + $transferIn + $transferOut + $dispatch;
            $totalHandled += $handled;
            $totalOccupied += $occupied;
            $anyUnrated = $anyUnrated || $unrated > 0;

            if ($capacity !== null && $capacity > 0) {
                $totalCapacity += $capacity;
            }

            $rated = $onTime + $late;

            $rows[] = [
                'location'                 => $location->location_name,
                'location_type'            => $location->location_type,
                'capacity'                 => $capacity !== null ? number_format($capacity) : 'Not set',
                'occupied_units'           => $occupied,
                'utilization'              => $this->percent($occupied, $capacity),
                'remaining'                => $capacity !== null ? number_format(max(0, $capacity - $occupied)) : 'Not set',
                'grn_count'                => (int) ($r->grn_count ?? 0),
                'po_count'                 => (int) ($r->po_count ?? 0),
                'units_received'           => $received,
                'units_damaged'            => $damaged,
                'damage_rate'              => $received > 0 ? number_format($damaged / $received * 100, 1) . '%' : '—',
                'receipts_on_time'         => $onTime,
                'receipts_late'            => $late,
                'receipts_unrated'         => $unrated,
                'on_time_rate'             => $rated > 0 ? number_format($onTime / $rated * 100, 1) . '%' : '—',
                'units_transferred_in'     => $transferIn,
                'units_transferred_out'    => $transferOut,
                'units_dispatched'         => $dispatch,
                'units_returned_to_vendor' => $rtv,
                'units_handled'            => $handled,
                'throughput_ratio'         => $this->ratio($handled, $capacity),
            ];
        }

        $notes = [
            'Occupancy, capacity and remaining space are as of the generation time.',
            'Receiving, transfer and dispatch figures cover the selected period only.',
            'Units handled = received + transferred in + transferred out + dispatched. Vendor returns and count adjustments are excluded.',
        ];

        if ($anyUnrated) {
            $notes[] = 'Receipts against a purchase order with no expected delivery date cannot be rated and are excluded from the on-time percentage.';
        }

        return new ReportDataset(
            columns: $this->flatColumns(),
            rows: $rows,
            summary: [
                'Locations'           => number_format(count($rows)),
                'Total capacity'      => $totalCapacity > 0 ? number_format($totalCapacity) : 'Not set',
                'Total occupied'      => number_format($totalOccupied),
                'Overall utilization' => $this->percent($totalOccupied, $totalCapacity > 0 ? $totalCapacity : null),
                'Total units handled' => number_format($totalHandled),
            ],
            sections: $this->sections($rows),
            notes: $notes,
        );
    }

    /** @param int[] $types */
    private function ledgerSum(string $column, array $types, array $locationIds, $from, $to)
    {
        return DB::table('material_movement_ledger')
            ->selectRaw("{$column} AS location_id, SUM(quantity) AS units")
            ->whereIn($column, $locationIds)
            ->whereIn('transaction_type_id', $types)
            ->whereBetween('created_at', [$from, $to])
            ->groupBy($column)
            ->pluck('units', 'location_id');
    }

    private function percent(int $part, ?int $whole): string
    {
        if ($whole === null || $whole <= 0) {
            return 'Not set';
        }

        return number_format($part / $whole * 100, 1) . '%';
    }

    private function ratio(int $handled, ?int $capacity): string
    {
        if ($capacity === null || $capacity <= 0) {
            return 'Not set';
        }

        return number_format($handled / $capacity, 2) . '×';
    }

    /** PDF reads better as four focused tables than one 20-column sprawl. */
    private function sections(array $rows): array
    {
        return [
            [
                'title'   => 'A · Space utilization (as of generation)',
                'columns' => [
                    'location' => 'Location', 'location_type' => 'Type', 'capacity' => 'Capacity',
                    'occupied_units' => 'Occupied', 'utilization' => 'Utilization', 'remaining' => 'Remaining',
                ],
                'rows'    => $rows,
            ],
            [
                'title'   => 'B · Goods received against purchase orders (period)',
                'columns' => [
                    'location' => 'Location', 'po_count' => 'POs', 'grn_count' => 'Receipts',
                    'units_received' => 'Units Received', 'units_damaged' => 'Damaged', 'damage_rate' => 'Damage Rate',
                    'receipts_on_time' => 'On Time', 'receipts_late' => 'Late',
                    'receipts_unrated' => 'Unrated', 'on_time_rate' => 'On-Time Rate',
                ],
                'rows'    => $rows,
            ],
            [
                'title'   => 'C · Transfers (period)',
                'columns' => [
                    'location' => 'Location',
                    'units_transferred_in' => 'Units In', 'units_transferred_out' => 'Units Out',
                ],
                'rows'    => $rows,
            ],
            [
                'title'   => 'D · Goods out (period)',
                'columns' => [
                    'location' => 'Location', 'units_dispatched' => 'Dispatched',
                    'units_returned_to_vendor' => 'Returned to Vendor',
                    'units_handled' => 'Units Handled', 'throughput_ratio' => 'Handled ÷ Capacity',
                ],
                'rows'    => $rows,
            ],
        ];
    }

    private function flatColumns(): array
    {
        return [
            'location'                 => 'Location',
            'location_type'            => 'Type',
            'capacity'                 => 'Capacity',
            'occupied_units'           => 'Occupied Units',
            'utilization'              => 'Utilization',
            'remaining'                => 'Remaining',
            'po_count'                 => 'POs Received Against',
            'grn_count'                => 'Receipts',
            'units_received'           => 'Units Received',
            'units_damaged'            => 'Units Damaged',
            'damage_rate'              => 'Damage Rate',
            'receipts_on_time'         => 'Receipts On Time',
            'receipts_late'            => 'Receipts Late',
            'receipts_unrated'         => 'Receipts Unrated',
            'on_time_rate'             => 'On-Time Rate',
            'units_transferred_in'     => 'Units Transferred In',
            'units_transferred_out'    => 'Units Transferred Out',
            'units_dispatched'         => 'Units Dispatched',
            'units_returned_to_vendor' => 'Units Returned to Vendor',
            'units_handled'            => 'Units Handled',
            'throughput_ratio'         => 'Handled ÷ Capacity',
        ];
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
cd back-end && php artisan test --filter=WarehouseUtilizationReportTest
```

Expected: PASS, all seven.

If a test fails because `makeLocation` produced a location the range query excludes, check
that `material_movement_ledger.created_at` is populated — the helper writes `now()`, which
must fall inside the seven-day window the test uses.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Reports/Definitions/WarehouseUtilizationReport.php \
        back-end/tests/Feature/Reports/WarehouseUtilizationReportTest.php
git commit -m "feat(reports): warehouse utilization covers throughput, not just occupied space"
```

---

## Task 11: User Activity report

**Files:**
- Create: `back-end/app/Reports/Definitions/UserActivityReport.php`
- Test: `back-end/tests/Feature/Reports/UserActivityReportTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Reports;

use App\Models\LoginActivity;
use App\Reports\Definitions\UserActivityReport;
use App\Reports\ReportParameters;

class UserActivityReportTest extends ReportTestCase
{
    private function range(): ReportParameters
    {
        return new ReportParameters([
            'date_from' => now()->subDays(2)->toDateString(),
            'date_to'   => now()->toDateString(),
        ]);
    }

    public function test_it_reports_successful_and_failed_logins(): void
    {
        $location = $this->makeLocation();
        $admin = $this->makeUserAt($location, 'admin');

        LoginActivity::create([
            'user_id' => $admin->user_id, 'username_attempt' => $admin->username,
            'success' => true, 'ip_address' => '10.0.0.4', 'created_at' => now()->subDay(),
        ]);
        LoginActivity::create([
            'user_id' => $admin->user_id, 'username_attempt' => $admin->username,
            'success' => false, 'failure_reason' => 'bad password',
            'ip_address' => '10.0.0.4', 'created_at' => now()->subDay(),
        ]);

        $dataset = app(UserActivityReport::class)->build($this->range(), $admin);

        $this->assertSame(2, $dataset->rowCount());
        $this->assertSame('1', $dataset->summary['Failed attempts']);
    }

    /**
     * login_activities has no location_id, so this report cannot be scoped.
     * It must be hidden rather than 403 on generate.
     */
    public function test_it_is_unavailable_to_location_scoped_users(): void
    {
        $scoped = $this->makeUserAt($this->makeLocation(), 'branch_manager');
        $central = $this->makeUserAt($this->makeLocation(), 'auditor');

        $this->assertFalse(app(UserActivityReport::class)->availableTo($scoped));
        $this->assertTrue(app(UserActivityReport::class)->availableTo($central));
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd back-end && php artisan test --filter=UserActivityReportTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write the definition**

```php
<?php

namespace App\Reports\Definitions;

use App\Models\LoginActivity;
use App\Models\User;
use App\Reports\ReportDataset;
use App\Reports\ReportDefinition;
use App\Reports\ReportParameter;
use App\Reports\ReportParameters;

/**
 * Login and session activity.
 *
 * The old card promised "system usage and login statistics", but there is no
 * source for the usage half: ActivityLog points at an `activity_log` table
 * that has no migration and is absent from the schema dump. The description
 * therefore claims only what login_activities can actually deliver.
 *
 * login_activities has no location_id, so this report cannot be location
 * scoped and is offered to centralized users only.
 */
class UserActivityReport implements ReportDefinition
{
    public function key(): string
    {
        return 'user-activity';
    }

    public function title(): string
    {
        return 'User Activity Report';
    }

    public function description(): string
    {
        return 'Login and session activity';
    }

    public function parameters(): array
    {
        return [
            new ReportParameter('date_range', ReportParameter::TYPE_DATE_RANGE, 'Period', required: true),
            new ReportParameter('user_id', ReportParameter::TYPE_USER, 'User'),
            new ReportParameter('outcome', ReportParameter::TYPE_SELECT, 'Outcome', options: [
                'success' => 'Successful only',
                'failure' => 'Failed only',
            ]),
        ];
    }

    public function formats(): array
    {
        return ['pdf', 'csv'];
    }

    public function pdfRowCap(): int
    {
        return 5000;
    }

    public function availableTo(User $user): bool
    {
        return $user->isCentralized();
    }

    public function build(ReportParameters $params, User $user): ReportDataset
    {
        $query = LoginActivity::query()
            ->with('user')
            ->whereBetween('created_at', [$params->dateFrom(), $params->dateTo()]);

        if ($params->has('user_id')) {
            $query->where('user_id', $params->int('user_id'));
        }

        if ($params->has('outcome')) {
            $query->where('success', $params->get('outcome') === 'success');
        }

        $rows = [];
        $failed = 0;
        $userIds = [];

        foreach ($query->orderByDesc('created_at')->get() as $activity) {
            if (! $activity->success) {
                $failed++;
            }
            if ($activity->user_id) {
                $userIds[$activity->user_id] = true;
            }

            $rows[] = [
                'occurred_at'    => $activity->created_at?->format('Y-m-d H:i:s') ?? '—',
                'user'           => trim(($activity->user?->first_name ?? '') . ' ' . ($activity->user?->last_name ?? '')) ?: '—',
                'username'       => $activity->username_attempt ?? '—',
                'outcome'        => $activity->success ? 'Success' : 'Failed',
                'failure_reason' => $activity->failure_reason ?? '—',
                'ip_address'     => $activity->ip_address ?? '—',
            ];
        }

        return new ReportDataset(
            columns: [
                'occurred_at'    => 'When',
                'user'           => 'User',
                'username'       => 'Username Used',
                'outcome'        => 'Outcome',
                'failure_reason' => 'Failure Reason',
                'ip_address'     => 'IP Address',
            ],
            rows: $rows,
            summary: [
                'Login attempts'  => number_format(count($rows)),
                'Distinct users'  => number_format(count($userIds)),
                'Failed attempts' => number_format($failed),
            ],
            notes: ['Covers authentication events only; the system does not record per-module usage.'],
        );
    }
}
```

- [ ] **Step 4: Run this test and the registry test**

```bash
cd back-end && php artisan test --filter=UserActivityReportTest
cd back-end && php artisan test --filter=ReportRegistryTest
```

Expected: both PASS. `ReportRegistryTest` has been red since Task 4 and turns green here,
because all six definitions now exist.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Reports/Definitions/UserActivityReport.php \
        back-end/tests/Feature/Reports/UserActivityReportTest.php
git commit -m "feat(reports): user activity reports logins, and only claims logins"
```

---

## Task 12: The runner

**Files:**
- Create: `back-end/app/Reports/EmptyReportException.php`
- Create: `back-end/app/Reports/RowCapExceededException.php`
- Create: `back-end/app/Reports/ReportRunner.php`
- Test: `back-end/tests/Feature/Reports/ReportRunnerTest.php`

- [ ] **Step 1: Write the two exceptions**

```php
<?php

namespace App\Reports;

use RuntimeException;

/** No rows matched. Writing an empty file would be junk in Recent Reports. */
class EmptyReportException extends RuntimeException
{
}
```

```php
<?php

namespace App\Reports;

use RuntimeException;

/** dompdf would exhaust memory on this many rows; fail clearly instead. */
class RowCapExceededException extends RuntimeException
{
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\Reports;

use App\Models\ReportRun;
use App\Reports\EmptyReportException;
use App\Reports\ReportRunner;
use Illuminate\Support\Facades\Storage;

class ReportRunnerTest extends ReportTestCase
{
    public function test_it_stores_the_file_and_records_the_run(): void
    {
        $location = $this->makeLocation('Runner WH');
        $product  = $this->makeAppliance(cost: 1000);
        $this->setStock($location->location_id, $product->product_id, 4);
        $admin = $this->makeUserAt($location, 'admin');

        $run = app(ReportRunner::class)->run('inventory-summary', 'csv', [], $admin);

        $this->assertInstanceOf(ReportRun::class, $run);
        $this->assertSame('inventory-summary', $run->report_key);
        $this->assertGreaterThan(0, $run->file_size_bytes, 'The recorded size must be the real file size.');
        $this->assertTrue(Storage::disk('local')->exists($run->file_path));
        $this->assertSame(
            Storage::disk('local')->size($run->file_path),
            $run->file_size_bytes
        );
    }

    public function test_it_refuses_a_report_with_no_rows_and_writes_nothing(): void
    {
        $location = $this->makeLocation('Empty WH');
        $admin = $this->makeUserAt($location, 'admin');

        $before = ReportRun::count();

        $this->expectException(EmptyReportException::class);

        try {
            app(ReportRunner::class)->run('low-stock', 'csv', ['location_id' => $location->location_id], $admin);
        } finally {
            $this->assertSame($before, ReportRun::count(),
                'An empty report must not leave a row behind.');
        }
    }

    public function test_it_rejects_an_unsupported_parameter(): void
    {
        $admin = $this->makeUserAt($this->makeLocation(), 'admin');

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        // Low Stock declares no date range; sending one is a caller bug, not
        // something to silently drop.
        app(ReportRunner::class)->run('low-stock', 'csv', ['date_from' => '2026-01-01'], $admin);
    }

    public function test_the_filename_carries_the_report_and_a_timestamp(): void
    {
        $location = $this->makeLocation('Named WH');
        $product  = $this->makeAppliance();
        $this->setStock($location->location_id, $product->product_id, 2);
        $admin = $this->makeUserAt($location, 'admin');

        $run = app(ReportRunner::class)->run('inventory-summary', 'pdf', [], $admin);

        $this->assertStringStartsWith('Inventory_Summary_Report_', $run->file_name);
        $this->assertStringEndsWith('.pdf', $run->file_name);
    }
}
```

- [ ] **Step 3: Run it to confirm it fails**

```bash
cd back-end && php artisan test --filter=ReportRunnerTest
```

Expected: FAIL — `ReportRunner` not found.

- [ ] **Step 4: Write the runner**

```php
<?php

namespace App\Reports;

use App\Models\ReportRun;
use App\Models\User;
use App\Reports\Renderers\CsvReportRenderer;
use App\Reports\Renderers\PdfReportRenderer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Everything a report run has in common: validate, build, render, store,
 * record. Definitions only know how to query.
 */
class ReportRunner
{
    public const RETENTION_DAYS = 90;

    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly PdfReportRenderer $pdf,
        private readonly CsvReportRenderer $csv,
    ) {
    }

    public function run(string $reportKey, string $format, array $parameters, User $user): ReportRun
    {
        $definition = $this->registry->get($reportKey);

        if ($definition === null || ! $definition->availableTo($user)) {
            throw new InvalidArgumentException("Unknown report: {$reportKey}");
        }

        if (! in_array($format, $definition->formats(), true)) {
            throw ValidationException::withMessages([
                'format' => "This report cannot be generated as {$format}.",
            ]);
        }

        $validated = $this->validate($definition, $parameters);
        $params    = new ReportParameters($validated);
        $dataset   = $definition->build($params, $user);

        if ($dataset->isEmpty()) {
            throw new EmptyReportException('No data matched these filters.');
        }

        if ($format === 'pdf' && $dataset->rowCount() > $definition->pdfRowCap()) {
            throw new RowCapExceededException(sprintf(
                'This report has %s rows, above the %s-row PDF limit. Choose CSV or narrow the filters.',
                number_format($dataset->rowCount()),
                number_format($definition->pdfRowCap())
            ));
        }

        $meta     = $this->meta($definition, $params);
        $contents = $format === 'pdf'
            ? $this->pdf->render($dataset, $definition->title(), $meta)
            : $this->csv->render($dataset, $definition->title(), $meta);

        $fileName = $this->fileName($definition->title(), $format);
        $filePath = 'reports/' . $fileName;

        Storage::disk('local')->put($filePath, $contents);

        // The file exists now; if the row insert fails, delete it rather than
        // leave an orphan nothing points at.
        try {
            return ReportRun::create([
                'report_key'      => $definition->key(),
                'title_snapshot'  => $definition->title(),
                'format'          => $format,
                'parameters'      => $validated,
                'file_path'       => $filePath,
                'file_name'       => $fileName,
                'file_size_bytes' => Storage::disk('local')->size($filePath),
                'row_count'       => $dataset->rowCount(),
                'generated_by'    => $user->user_id,
                'expires_at'      => now()->addDays(self::RETENTION_DAYS),
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($filePath);
            throw $e;
        }
    }

    /**
     * Only declared parameters are accepted. An unknown key is a caller bug —
     * silently dropping it is how a filter comes to look applied when it is not.
     */
    private function validate(ReportDefinition $definition, array $parameters): array
    {
        $rules   = [];
        $allowed = [];

        foreach ($definition->parameters() as $parameter) {
            $rules = array_merge($rules, $parameter->rules());
            $allowed = array_merge($allowed, $parameter->requestKeys());
        }

        $unknown = array_diff(array_keys(array_filter($parameters, fn ($v) => $v !== null && $v !== '')), $allowed);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'parameters' => 'This report does not accept: ' . implode(', ', $unknown),
            ]);
        }

        $validated = Validator::make($parameters, $rules)->validate();

        // Apply declared defaults for anything the caller left out.
        foreach ($definition->parameters() as $parameter) {
            if ($parameter->default !== null && ! isset($validated[$parameter->key])) {
                $validated[$parameter->key] = $parameter->default;
            }
        }

        return $validated;
    }

    /** The filter summary printed under the document title. */
    private function meta(ReportDefinition $definition, ReportParameters $params): array
    {
        $meta = [];

        if ($params->has('date_from') && $params->has('date_to')) {
            $meta['Period'] = $params->dateFrom()->format('Y-m-d') . ' to ' . $params->dateTo()->format('Y-m-d');
        }

        if ($params->has('location_id')) {
            $meta['Location'] = \App\Models\Location::find($params->int('location_id'))?->location_name ?? '—';
        }

        if ($params->has('location_type')) {
            $meta['Location Type'] = ucfirst(str_replace('_', ' ', (string) $params->get('location_type')));
        }

        if ($params->has('category_id')) {
            $meta['Category'] = \App\Models\CategoryLookup::find($params->int('category_id'))?->category_name ?? '—';
        }

        if ($meta === []) {
            $meta['Filters'] = 'None — all records';
        }

        return $meta;
    }

    private function fileName(string $title, string $format): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '_', $title);
        $slug = trim($slug, '_');

        return sprintf('%s_%s.%s', $slug, now()->format('Y-m-d_His'), $format);
    }
}
```

- [ ] **Step 5: Run the test**

```bash
cd back-end && php artisan test --filter=ReportRunnerTest
```

Expected: PASS, all four.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Reports/ReportRunner.php back-end/app/Reports/EmptyReportException.php \
        back-end/app/Reports/RowCapExceededException.php \
        back-end/tests/Feature/Reports/ReportRunnerTest.php
git commit -m "feat(reports): one runner validates, renders, stores and records a run"
```

---

## Task 13: Retention

**Files:**
- Create: `back-end/app/Services/ReportRetentionService.php`
- Create: `back-end/app/Console/Commands/PruneReportRuns.php`
- Test: `back-end/tests/Feature/Reports/ReportRetentionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Reports;

use App\Models\ReportRun;
use App\Services\ReportRetentionService;
use Illuminate\Support\Facades\Storage;

class ReportRetentionTest extends ReportTestCase
{
    private function expiredRun(): ReportRun
    {
        $admin = $this->makeUserAt($this->makeLocation(), 'admin');
        Storage::disk('local')->put('reports/old.csv', 'a,b');

        return ReportRun::create([
            'report_key'      => 'low-stock',
            'title_snapshot'  => 'Low Stock Report',
            'format'          => 'csv',
            'parameters'      => [],
            'file_path'       => 'reports/old.csv',
            'file_name'       => 'old.csv',
            'file_size_bytes' => 3,
            'row_count'       => 1,
            'generated_by'    => $admin->user_id,
            'expires_at'      => now()->subDay(),
        ]);
    }

    public function test_pruning_deletes_the_file_but_keeps_the_row(): void
    {
        $run = $this->expiredRun();

        $pruned = app(ReportRetentionService::class)->prune();

        $this->assertGreaterThanOrEqual(1, $pruned);
        $this->assertFalse(Storage::disk('local')->exists('reports/old.csv'));

        $run->refresh();
        $this->assertNotNull($run->file_pruned_at);
        $this->assertNull($run->file_path);
        $this->assertTrue($run->is_expired, 'The row survives so the run can be re-run.');
    }

    public function test_a_current_run_is_left_alone(): void
    {
        $admin = $this->makeUserAt($this->makeLocation(), 'admin');
        Storage::disk('local')->put('reports/new.csv', 'a,b');

        $run = ReportRun::create([
            'report_key'      => 'low-stock',
            'title_snapshot'  => 'Low Stock Report',
            'format'          => 'csv',
            'parameters'      => [],
            'file_path'       => 'reports/new.csv',
            'file_name'       => 'new.csv',
            'file_size_bytes' => 3,
            'row_count'       => 1,
            'generated_by'    => $admin->user_id,
            'expires_at'      => now()->addDays(90),
        ]);

        app(ReportRetentionService::class)->prune();

        $this->assertTrue(Storage::disk('local')->exists('reports/new.csv'));
        $this->assertNull($run->fresh()->file_pruned_at);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd back-end && php artisan test --filter=ReportRetentionTest
```

Expected: FAIL — service not found.

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services;

use App\Models\ReportRun;
use Illuminate\Support\Facades\Storage;

/**
 * 90-day retention.
 *
 * bootstrap/app.php configures no scheduler and this runs on XAMPP, so a
 * cron-only prune would silently never fire. The artisan command exists for
 * when a scheduler is wired; the opportunistic call from the runs endpoint is
 * what actually enforces retention in practice.
 *
 * The row is never deleted — only the file. An expired run still knows its
 * parameters, so it can offer an explicit re-run.
 */
class ReportRetentionService
{
    public function prune(): int
    {
        $pruned = 0;

        ReportRun::query()
            ->whereNull('file_pruned_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($runs) use (&$pruned) {
                foreach ($runs as $run) {
                    if ($run->file_path && Storage::disk('local')->exists($run->file_path)) {
                        Storage::disk('local')->delete($run->file_path);
                    }

                    $run->update(['file_pruned_at' => now(), 'file_path' => null]);
                    $pruned++;
                }
            }, 'report_run_id');

        return $pruned;
    }
}
```

- [ ] **Step 4: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Services\ReportRetentionService;
use Illuminate\Console\Command;

class PruneReportRuns extends Command
{
    protected $signature = 'reports:prune';

    protected $description = 'Delete generated report files past their 90-day retention, keeping the run records';

    public function handle(ReportRetentionService $retention): int
    {
        $pruned = $retention->prune();

        $this->info("Pruned {$pruned} expired report file(s). Run records were kept.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run the test and the command**

```bash
cd back-end && php artisan test --filter=ReportRetentionTest
cd back-end && php artisan reports:prune
```

Expected: test PASSES; command prints a count.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Services/ReportRetentionService.php \
        back-end/app/Console/Commands/PruneReportRuns.php \
        back-end/tests/Feature/Reports/ReportRetentionTest.php
git commit -m "feat(reports): expire report files after 90 days, keep the record"
```

---

## Task 14: The API

**Files:**
- Create: `back-end/app/Http/Controllers/Api/ReportController.php`
- Modify: `back-end/routes/api.php:1028-1090`
- Test: `back-end/tests/Feature/Reports/ReportApiTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Reports;

use App\Models\ReportRun;
use Laravel\Sanctum\Sanctum;

class ReportApiTest extends ReportTestCase
{
    public function test_the_catalog_lists_reports_with_their_declared_parameters(): void
    {
        $admin = $this->makeUserAt($this->makeLocation(), 'admin');
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/reports/catalog')->assertOk();

        $reports = collect($response->json('data'));
        $lowStock = $reports->firstWhere('key', 'low-stock');

        $this->assertNotNull($lowStock);
        $this->assertSame(['pdf', 'csv'], $lowStock['formats']);
        $this->assertNotContains('date_range', collect($lowStock['parameters'])->pluck('type')->all(),
            'The dialog must not offer a date range the report ignores.');
    }

    public function test_generating_returns_the_run_record(): void
    {
        $location = $this->makeLocation();
        $product = $this->makeAppliance();
        $this->setStock($location->location_id, $product->product_id, 5);
        $admin = $this->makeUserAt($location, 'admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/reports/generate', [
            'report_key' => 'inventory-summary',
            'format'     => 'csv',
            'parameters' => [],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.report_key', 'inventory-summary');
    }

    public function test_an_empty_result_is_a_422_and_leaves_no_run(): void
    {
        $location = $this->makeLocation();
        $admin = $this->makeUserAt($location, 'admin');
        Sanctum::actingAs($admin);

        $before = ReportRun::count();

        $this->postJson('/api/reports/generate', [
            'report_key' => 'low-stock',
            'format'     => 'csv',
            'parameters' => ['location_id' => $location->location_id],
        ])->assertStatus(422);

        $this->assertSame($before, ReportRun::count());
    }

    public function test_generating_requires_the_create_permission(): void
    {
        $scoped = $this->makeUserAt($this->makeLocation(), 'warehouse_personnel');
        Sanctum::actingAs($scoped);

        $this->postJson('/api/reports/generate', [
            'report_key' => 'inventory-summary',
            'format'     => 'csv',
            'parameters' => [],
        ])->assertStatus(403);
    }

    public function test_a_location_scoped_user_with_an_override_sees_only_their_location(): void
    {
        $mine = $this->makeLocation('Mine');
        $theirs = $this->makeLocation('Theirs');
        $product = $this->makeAppliance();
        $this->setStock($mine->location_id, $product->product_id, 3);
        $this->setStock($theirs->location_id, $product->product_id, 3);

        $manager = $this->makeUserAt($mine, 'branch_manager');
        $this->grantPermission($manager, 'report_viewing', 'read');
        Sanctum::actingAs($manager);

        $keys = collect($this->getJson('/api/reports/catalog')->json('data'))->pluck('key');

        $this->assertNotContains('user-activity', $keys,
            'User Activity cannot be location scoped, so a scoped user must not see it.');
        $this->assertContains('inventory-summary', $keys);
    }

    public function test_downloading_an_expired_run_explains_why(): void
    {
        $admin = $this->makeUserAt($this->makeLocation(), 'admin');
        Sanctum::actingAs($admin);

        $run = ReportRun::create([
            'report_key'      => 'low-stock',
            'title_snapshot'  => 'Low Stock Report',
            'format'          => 'csv',
            'parameters'      => [],
            'file_path'       => null,
            'file_name'       => 'gone.csv',
            'file_size_bytes' => 0,
            'row_count'       => 0,
            'generated_by'    => $admin->user_id,
            'expires_at'      => now()->subDay(),
            'file_pruned_at'  => now()->subDay(),
        ]);

        $this->getJson("/api/reports/runs/{$run->report_run_id}/download")
            ->assertStatus(410);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd back-end && php artisan test --filter=ReportApiTest
```

Expected: FAIL — routes do not exist (404).

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReportRun;
use App\Reports\EmptyReportException;
use App\Reports\ReportRegistry;
use App\Reports\ReportRunner;
use App\Reports\RowCapExceededException;
use App\Services\ReportRetentionService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportRunner $runner,
        private readonly ReportRetentionService $retention,
    ) {
    }

    /**
     * What this user may run. The page renders its cards and its filter dialog
     * from this, so a card can never advertise something the backend lacks.
     */
    public function catalog(Request $request)
    {
        $catalog = array_map(fn ($definition) => [
            'key'         => $definition->key(),
            'title'       => $definition->title(),
            'description' => $definition->description(),
            'formats'     => $definition->formats(),
            'parameters'  => array_map(fn ($p) => $p->toArray(), $definition->parameters()),
        ], $this->registry->availableTo($request->user()));

        return $this->success($catalog);
    }

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'report_key' => 'required|string',
            'format'     => 'required|string|in:pdf,csv',
            'parameters' => 'array',
        ]);

        try {
            $run = $this->runner->run(
                $validated['report_key'],
                $validated['format'],
                $validated['parameters'] ?? [],
                $request->user(),
            );
        } catch (EmptyReportException $e) {
            return $this->error('No data matched these filters.', 422);
        } catch (RowCapExceededException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (InvalidArgumentException $e) {
            return $this->error('Unknown report.', 404);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        return $this->success($run->load('generatedBy'), 'Report generated');
    }

    public function runs(Request $request)
    {
        // No scheduler is configured, so this is what actually enforces the
        // 90-day retention. Cheap: it only touches rows already past expiry.
        $this->retention->prune();

        $runs = ReportRun::query()
            ->with('generatedBy')
            ->orderByDesc('created_at')
            ->paginate(20);

        return $this->success($runs);
    }

    public function download(Request $request, int $id)
    {
        $run = ReportRun::find($id);

        if (! $run) {
            return $this->error('Report run not found.', 404);
        }

        if ($run->is_expired || ! $run->file_path) {
            return $this->error(
                'This report has passed its 90-day retention and its file was removed. Re-run it to get current figures.',
                410
            );
        }

        if (! Storage::disk('local')->exists($run->file_path)) {
            return $this->error('The stored file is missing.', 404);
        }

        $headers = [
            'Content-Type' => $run->format === 'pdf' ? 'application/pdf' : 'text/csv',
        ];

        return $request->boolean('inline') && $run->format === 'pdf'
            ? Storage::disk('local')->response($run->file_path, $run->file_name, $headers)
            : Storage::disk('local')->download($run->file_path, $run->file_name, $headers);
    }
}
```

- [ ] **Step 4: Replace the dead closures with the real routes**

In `back-end/routes/api.php`, delete the entire block from
`Route::prefix('reports')->group(function () {` through its closing `});` — all seven
closures (`inventory-valuation`, `sales-summary`, `movement-history`, `low-stock-report`,
`adjustments-summary`, `receivings-summary`, `profit-loss-summary`). Nothing in the frontend
calls them, and `low-stock-report` is actively wrong. Replace with:

```php
    /*
    |--------------------------------------------------------------------------
    | REPORTS
    |--------------------------------------------------------------------------
    | Replaced seven unused closures that returned raw paginated JSON. The page
    | never called them, and low-stock-report compared against the global
    | products.recommended_stocks while ignoring inventory.reorder_level, so it
    | could not report a per-location shortfall at all. That logic now lives in
    | App\Reports\Definitions\LowStockReport with a test.
    */
    Route::prefix('reports')->group(function () {
        Route::middleware('permission:report_viewing,read')
             ->get('/catalog', [\App\Http\Controllers\Api\ReportController::class, 'catalog']);

        // Generating writes a file and a report_runs row, so it needs `create`.
        Route::middleware('permission:report_viewing,create')
             ->post('/generate', [\App\Http\Controllers\Api\ReportController::class, 'generate']);

        Route::middleware('permission:report_viewing,read')
             ->get('/runs', [\App\Http\Controllers\Api\ReportController::class, 'runs']);

        Route::middleware('permission:report_viewing,read')
             ->get('/runs/{id}/download', [\App\Http\Controllers\Api\ReportController::class, 'download']);
    });
```

Then, alongside the other `auth.query.token` download routes at the bottom of the file (near
`/transfers/{id}/receipt/pdf`), add the browser-navigation variant:

```php
// Browser download: a top-level navigation cannot set an Authorization header,
// so the token rides as ?token= exactly like the other document downloads.
Route::middleware(['auth.query.token', 'permission:report_viewing,read'])
     ->get('/reports/runs/{id}/file', [\App\Http\Controllers\Api\ReportController::class, 'download']);
```

- [ ] **Step 5: Run the test**

```bash
cd back-end && php artisan test --filter=ReportApiTest
```

Expected: PASS, all six.

- [ ] **Step 6: Run the whole reports suite**

```bash
cd back-end && php artisan test --filter=Reports
```

Expected: every reports test passes.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/ReportController.php back-end/routes/api.php \
        back-end/tests/Feature/Reports/ReportApiTest.php
git commit -m "feat(reports): serve the catalog, generate, and list real past runs"
```

---

## Task 15: Frontend API layer

**Files:**
- Modify: `frontend/src/admin/services/api.js` (append at end of file)

- [ ] **Step 1: Append `reportsAPI`**

Add at the end of `frontend/src/admin/services/api.js`, following the shape of the existing
`barcodeAdjustmentAPI` block:

```javascript
/* ======================
   SYSTEM REPORTS API
====================== */
export const reportsAPI = {
  /** GET /reports/catalog — the reports this user may run, with declared params */
  getCatalog: async () => apiRequest('/reports/catalog'),

  /** POST /reports/generate */
  generate: async ({ reportKey, format, parameters }) =>
    apiRequest('/reports/generate', {
      method: 'POST',
      body: JSON.stringify({ report_key: reportKey, format, parameters: parameters || {} }),
    }),

  /** GET /reports/runs — paginated past runs */
  getRuns: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/reports/runs?${query}` : '/reports/runs');
  },

  /**
   * A browser download URL. Top-level navigation cannot send an Authorization
   * header, so the token rides as a query param exactly like the other
   * document downloads in this app.
   */
  fileUrl: (runId, { inline = false } = {}) => {
    const params = new URLSearchParams({ token: getToken() || '' });
    if (inline) params.set('inline', '1');
    return `${getApiBaseUrl()}/reports/runs/${runId}/file?${params.toString()}`;
  },
};
```

- [ ] **Step 2: Verify it compiles**

```bash
cd frontend && npx eslint src/admin/services/api.js
```

Expected: no errors. If `getToken` or `getApiBaseUrl` are reported as undefined, they are
defined earlier in the same file (lines ~29 and ~204) — no import is needed.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -m "feat(reports): frontend api for catalog, generate and downloads"
```

---

## Task 16: The generate dialog

**Files:**
- Create: `frontend/src/admin/components/reports/GenerateReportDialog.jsx`
- Test: `frontend/src/admin/components/reports/GenerateReportDialog.test.jsx`

- [ ] **Step 1: Write the failing test**

```javascript
import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import GenerateReportDialog from './GenerateReportDialog';

/**
 * The dialog's inputs come from the report's own declaration, never from a
 * hardcoded per-report switch. That is the whole point of the catalog: a
 * control on screen means the backend honors it.
 */
const lowStock = {
  key: 'low-stock',
  title: 'Low Stock Report',
  formats: ['pdf', 'csv'],
  parameters: [
    { key: 'location_id', type: 'location', label: 'Location', required: false, options: {}, default: null },
  ],
};

const transaction = {
  key: 'transaction',
  title: 'Transaction Report',
  formats: ['pdf', 'csv'],
  parameters: [
    { key: 'date_range', type: 'date_range', label: 'Period', required: true, options: {}, default: null },
  ],
};

const noop = () => {};

describe('GenerateReportDialog', () => {
  test('shows no date inputs for a report that declares none', () => {
    render(
      <GenerateReportDialog report={lowStock} locations={[]} categories={[]} users={[]}
        onGenerate={noop} onClose={noop} />
    );

    expect(screen.queryByLabelText(/from/i)).not.toBeInTheDocument();
    expect(screen.getByLabelText(/location/i)).toBeInTheDocument();
  });

  test('shows date inputs for a report that declares a period', () => {
    render(
      <GenerateReportDialog report={transaction} locations={[]} categories={[]} users={[]}
        onGenerate={noop} onClose={noop} />
    );

    expect(screen.getByLabelText(/from/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/to/i)).toBeInTheDocument();
  });

  test('will not submit a required period that is empty', () => {
    const onGenerate = jest.fn();
    render(
      <GenerateReportDialog report={transaction} locations={[]} categories={[]} users={[]}
        onGenerate={onGenerate} onClose={noop} />
    );

    fireEvent.click(screen.getByRole('button', { name: /generate/i }));

    expect(onGenerate).not.toHaveBeenCalled();
    expect(screen.getByText(/period is required/i)).toBeInTheDocument();
  });

  test('submits the chosen format and parameters', () => {
    const onGenerate = jest.fn();
    render(
      <GenerateReportDialog report={transaction} locations={[]} categories={[]} users={[]}
        onGenerate={onGenerate} onClose={noop} />
    );

    fireEvent.change(screen.getByLabelText(/from/i), { target: { value: '2026-08-01' } });
    fireEvent.change(screen.getByLabelText(/to/i), { target: { value: '2026-08-17' } });
    fireEvent.click(screen.getByRole('radio', { name: /csv/i }));
    fireEvent.click(screen.getByRole('button', { name: /generate/i }));

    expect(onGenerate).toHaveBeenCalledWith({
      reportKey: 'transaction',
      format: 'csv',
      parameters: { date_from: '2026-08-01', date_to: '2026-08-17' },
    });
  });

  test('only offers the formats the report declares', () => {
    render(
      <GenerateReportDialog report={{ ...lowStock, formats: ['csv'] }} locations={[]} categories={[]} users={[]}
        onGenerate={noop} onClose={noop} />
    );

    expect(screen.getByRole('radio', { name: /csv/i })).toBeInTheDocument();
    expect(screen.queryByRole('radio', { name: /pdf/i })).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd frontend && npx react-scripts test --watchAll=false --testPathPattern=GenerateReportDialog
```

Expected: FAIL — module not found.

- [ ] **Step 3: Write the dialog**

```jsx
import React, { useMemo, useState } from 'react';

/**
 * Inputs are built from the report's declared parameters, never from a
 * per-report switch. If a control is on screen, the backend honors it — that
 * is what stops the page advertising filters nothing implements.
 */
const FORMAT_LABELS = { pdf: 'PDF', csv: 'CSV' };

const blankValues = (report) => {
  const values = {};
  (report.parameters || []).forEach((param) => {
    if (param.type === 'date_range') {
      values.date_from = '';
      values.date_to = '';
    } else {
      values[param.key] = param.default ?? '';
    }
  });
  return values;
};

const GenerateReportDialog = ({
  report,
  locations = [],
  categories = [],
  users = [],
  // Prefilled from a stored run's parameters when re-running an expired report.
  initialValues = null,
  generating = false,
  onGenerate,
  onClose,
}) => {
  const [values, setValues] = useState(() => ({
    ...blankValues(report),
    ...(initialValues || {}),
  }));
  const [error, setError] = useState('');
  const [format, setFormat] = useState(report.formats?.[0] || 'pdf');

  const setValue = (key, value) => setValues((prev) => ({ ...prev, [key]: value }));

  const requiredRange = useMemo(
    () => (report.parameters || []).find((p) => p.type === 'date_range' && p.required),
    [report.parameters],
  );

  const handleSubmit = (event) => {
    event.preventDefault();

    if (requiredRange && (!values.date_from || !values.date_to)) {
      setError('Period is required for this report.');
      return;
    }

    if (values.date_from && values.date_to && values.date_from > values.date_to) {
      setError('The start date must not be after the end date.');
      return;
    }

    const parameters = {};
    Object.entries(values).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) parameters[key] = value;
    });

    setError('');
    onGenerate({ reportKey: report.key, format, parameters });
  };

  const renderParameter = (param) => {
    switch (param.type) {
      case 'date_range':
        return (
          <div className="sr-field-row" key={param.key}>
            <div className="sr-field">
              <label htmlFor="sr-date-from">{param.label} — from</label>
              <input id="sr-date-from" type="date" value={values.date_from || ''}
                onChange={(e) => setValue('date_from', e.target.value)} />
            </div>
            <div className="sr-field">
              <label htmlFor="sr-date-to">{param.label} — to</label>
              <input id="sr-date-to" type="date" value={values.date_to || ''}
                onChange={(e) => setValue('date_to', e.target.value)} />
            </div>
          </div>
        );

      case 'location':
        return (
          <div className="sr-field" key={param.key}>
            <label htmlFor={`sr-${param.key}`}>{param.label}</label>
            <select id={`sr-${param.key}`} value={values[param.key] || ''}
              onChange={(e) => setValue(param.key, e.target.value)}>
              <option value="">All locations</option>
              {locations.map((l) => (
                <option key={l.location_id} value={l.location_id}>{l.location_name}</option>
              ))}
            </select>
          </div>
        );

      case 'location_type':
        return (
          <div className="sr-field" key={param.key}>
            <label htmlFor={`sr-${param.key}`}>{param.label}</label>
            <select id={`sr-${param.key}`} value={values[param.key] || ''}
              onChange={(e) => setValue(param.key, e.target.value)}>
              <option value="">All types</option>
              <option value="warehouse">Warehouse</option>
              <option value="showroom">Showroom</option>
              <option value="branch_office">Branch office</option>
            </select>
          </div>
        );

      case 'category':
        return (
          <div className="sr-field" key={param.key}>
            <label htmlFor={`sr-${param.key}`}>{param.label}</label>
            <select id={`sr-${param.key}`} value={values[param.key] || ''}
              onChange={(e) => setValue(param.key, e.target.value)}>
              <option value="">All categories</option>
              {categories.map((c) => (
                <option key={c.category_id} value={c.category_id}>{c.category_name}</option>
              ))}
            </select>
          </div>
        );

      case 'user':
        return (
          <div className="sr-field" key={param.key}>
            <label htmlFor={`sr-${param.key}`}>{param.label}</label>
            <select id={`sr-${param.key}`} value={values[param.key] || ''}
              onChange={(e) => setValue(param.key, e.target.value)}>
              <option value="">All users</option>
              {users.map((u) => (
                <option key={u.user_id} value={u.user_id}>
                  {`${u.first_name || ''} ${u.last_name || ''}`.trim() || u.username}
                </option>
              ))}
            </select>
          </div>
        );

      case 'select':
        return (
          <div className="sr-field" key={param.key}>
            <label htmlFor={`sr-${param.key}`}>{param.label}</label>
            <select id={`sr-${param.key}`} value={values[param.key] || ''}
              onChange={(e) => setValue(param.key, e.target.value)}>
              <option value="">All</option>
              {Object.entries(param.options || {}).map(([value, label]) => (
                <option key={value} value={value}>{label}</option>
              ))}
            </select>
          </div>
        );

      default:
        return null;
    }
  };

  return (
    <div className="sr-dialog-backdrop" role="presentation" onClick={onClose}>
      <div
        className="sr-dialog"
        role="dialog"
        aria-modal="true"
        aria-label={`Generate ${report.title}`}
        onClick={(e) => e.stopPropagation()}
      >
        <h2 className="sr-dialog-title">{report.title}</h2>

        <form onSubmit={handleSubmit}>
          {(report.parameters || []).length === 0 && (
            <p className="sr-dialog-hint">This report takes no filters.</p>
          )}

          {(report.parameters || []).map(renderParameter)}

          <fieldset className="sr-field sr-format-field">
            <legend>Format</legend>
            {(report.formats || []).map((f) => (
              <label key={f} className="sr-format-option">
                <input
                  type="radio"
                  name="sr-format"
                  value={f}
                  checked={format === f}
                  onChange={() => setFormat(f)}
                />
                <span>{FORMAT_LABELS[f] || f.toUpperCase()}</span>
              </label>
            ))}
          </fieldset>

          {error && <p className="sr-dialog-error" role="alert">{error}</p>}

          <div className="sr-dialog-actions">
            <button type="button" className="sr-btn-cancel" onClick={onClose}>Cancel</button>
            <button type="submit" className="sr-btn-generate" disabled={generating}>
              {generating ? 'Generating…' : 'Generate Report'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};

export default GenerateReportDialog;
```

- [ ] **Step 4: Run the tests**

```bash
cd frontend && npx react-scripts test --watchAll=false --testPathPattern=GenerateReportDialog
```

Expected: PASS, all five.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/reports/GenerateReportDialog.jsx \
        frontend/src/admin/components/reports/GenerateReportDialog.test.jsx
git commit -m "feat(reports): the filter dialog is built from what the report declares"
```

---

## Task 17: Recent reports list

**Files:**
- Create: `frontend/src/admin/components/reports/RecentReportsList.jsx`

- [ ] **Step 1: Write the component**

There is no separate test file — this component is covered by the page tests in Task 18,
which is where its behaviour actually matters (an expired row must offer Re-run, not
Download).

```jsx
import React from 'react';
import { reportsAPI } from '../../services/api';

/** Real byte sizes from the server, formatted — never invented. */
const formatSize = (bytes) => {
  const size = Number(bytes) || 0;
  if (size < 1024) return `${size} B`;
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(0)} KB`;
  return `${(size / (1024 * 1024)).toFixed(1)} MB`;
};

const formatWhen = (value) => {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  return date.toLocaleString(undefined, {
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit',
  });
};

const personName = (user) => {
  if (!user) return 'Unknown';
  return `${user.first_name || ''} ${user.last_name || ''}`.trim() || user.username || 'Unknown';
};

const FileIcon = () => (
  <div className="sr-file-icon">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
      <polyline points="14 2 14 8 20 8" />
      <line x1="16" y1="13" x2="8" y2="13" />
      <line x1="16" y1="17" x2="8" y2="17" />
    </svg>
  </div>
);

const RecentReportsList = ({ runs, loading, onRerun }) => {
  if (loading) {
    return (
      <div className="sr-recent-list" aria-busy="true">
        {[0, 1, 2].map((i) => (
          <div className="sr-recent-item sr-recent-item--skeleton" key={i}>
            <span className="sr-skel-line" style={{ width: '40%' }} />
            <span className="sr-skel-line" style={{ width: '15%' }} />
          </div>
        ))}
      </div>
    );
  }

  if (!runs.length) {
    return (
      <p className="sr-recent-empty">
        No reports have been generated yet. Generate one above and it will appear here.
      </p>
    );
  }

  return (
    <div className="sr-recent-list">
      {runs.map((run) => {
        const expired = Boolean(run.is_expired);

        return (
          <div
            className={`sr-recent-item${expired ? ' sr-recent-item--expired' : ''}`}
            key={run.report_run_id}
          >
            <div className="sr-recent-item-left">
              <FileIcon />
              <div className="sr-recent-info">
                <span className="sr-recent-name">{run.file_name}</span>
                <span className="sr-recent-meta">
                  {run.title_snapshot} • {formatWhen(run.created_at)} • {personName(run.generated_by)}
                  {run.row_count ? ` • ${run.row_count} rows` : ''}
                </span>
              </div>
            </div>

            <div className="sr-recent-item-right">
              {expired ? (
                <>
                  <span className="sr-recent-expired-tag">File expired</span>
                  <button type="button" className="sr-btn-view" onClick={() => onRerun(run)}>
                    Re-run
                  </button>
                </>
              ) : (
                <>
                  <span className="sr-recent-size">{formatSize(run.file_size_bytes)}</span>
                  <a
                    className="sr-btn-download"
                    href={reportsAPI.fileUrl(run.report_run_id)}
                  >
                    Download
                  </a>
                  {run.format === 'pdf' && (
                    <a
                      className="sr-btn-view"
                      href={reportsAPI.fileUrl(run.report_run_id, { inline: true })}
                      target="_blank"
                      rel="noreferrer"
                    >
                      View
                    </a>
                  )}
                </>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
};

export default RecentReportsList;
```

- [ ] **Step 2: Verify it lints**

```bash
cd frontend && npx eslint src/admin/components/reports/RecentReportsList.jsx
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/components/reports/RecentReportsList.jsx
git commit -m "feat(reports): recent reports shows real sizes, times and owners"
```

---

## Task 18: The page

**Files:**
- Modify: `frontend/src/admin/pages/SystemReports.jsx` (full rewrite)
- Test: `frontend/src/admin/pages/SystemReports.test.jsx`

- [ ] **Step 1: Write the failing test**

```javascript
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import SystemReports from './SystemReports';
import { reportsAPI, locationAPI } from '../services/api';

/**
 * The page must render what the backend declared, not a hardcoded list. The
 * version this replaced shipped six invented cards and four invented
 * filenames with invented byte sizes — every one of them a claim the system
 * could not back.
 */
jest.mock('../services/api', () => ({
  reportsAPI: {
    getCatalog: jest.fn(),
    getRuns: jest.fn(),
    generate: jest.fn(),
    fileUrl: jest.fn(() => 'http://api.test/file'),
  },
  locationAPI: { getAll: jest.fn() },
  categoryLookupAPI: { getAll: jest.fn() },
  userAPI: { getAll: jest.fn() },
}));

jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);

const catalog = [
  {
    key: 'low-stock',
    title: 'Low Stock Report',
    description: 'Items at or below their reorder level',
    formats: ['pdf', 'csv'],
    parameters: [],
  },
];

beforeEach(() => {
  jest.clearAllMocks();
  reportsAPI.getCatalog.mockResolvedValue({ success: true, data: catalog });
  reportsAPI.getRuns.mockResolvedValue({ success: true, data: { data: [] } });
  locationAPI.getAll.mockResolvedValue({ success: true, data: [] });
});

describe('SystemReports', () => {
  test('renders cards from the catalog, not a hardcoded list', async () => {
    render(<SystemReports />);

    expect(await screen.findByText('Low Stock Report')).toBeInTheDocument();
    expect(screen.getByText(/at or below their reorder level/i)).toBeInTheDocument();

    // The mock page's invented cards must be gone.
    expect(screen.queryByText('Branch Performance')).not.toBeInTheDocument();
  });

  test('advertises only the formats the backend declared', async () => {
    reportsAPI.getCatalog.mockResolvedValue({
      success: true,
      data: [{ ...catalog[0], formats: ['csv'] }],
    });

    render(<SystemReports />);

    expect(await screen.findByText(/^CSV$/)).toBeInTheDocument();
    expect(screen.queryByText(/PDF \/ Excel/)).not.toBeInTheDocument();
  });

  test('shows an empty state instead of invented filenames', async () => {
    render(<SystemReports />);

    expect(await screen.findByText(/no reports have been generated yet/i)).toBeInTheDocument();
    expect(screen.queryByText(/Inventory_Summary_2025-01-19\.pdf/)).not.toBeInTheDocument();
  });

  test('an expired run offers Re-run rather than Download', async () => {
    reportsAPI.getRuns.mockResolvedValue({
      success: true,
      data: {
        data: [{
          report_run_id: 3,
          report_key: 'low-stock',
          title_snapshot: 'Low Stock Report',
          file_name: 'Low_Stock_Report_2026-01-01_100000.csv',
          file_size_bytes: 0,
          row_count: 12,
          format: 'csv',
          created_at: '2026-01-01T10:00:00Z',
          is_expired: true,
          parameters: {},
          generated_by: { first_name: 'Abegail', last_name: 'M' },
        }],
      },
    });

    render(<SystemReports />);

    expect(await screen.findByRole('button', { name: /re-run/i })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /download/i })).not.toBeInTheDocument();
    expect(screen.getByText(/file expired/i)).toBeInTheDocument();
  });

  test('generating refreshes the run list', async () => {
    reportsAPI.generate.mockResolvedValue({ success: true, data: { report_run_id: 9 } });
    render(<SystemReports />);

    await userEvent.click(await screen.findByRole('button', { name: /generate report/i }));
    await userEvent.click(await screen.findByRole('button', { name: /^generate report$/i, hidden: false }));

    await waitFor(() => expect(reportsAPI.generate).toHaveBeenCalled());
    await waitFor(() => expect(reportsAPI.getRuns).toHaveBeenCalledTimes(2));
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd frontend && npx react-scripts test --watchAll=false --testPathPattern=SystemReports
```

Expected: FAIL — the page still renders hardcoded cards including "Branch Performance".

- [ ] **Step 3: Rewrite the page**

Replace the entire contents of `frontend/src/admin/pages/SystemReports.jsx`:

```jsx
import React, { useCallback, useEffect, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import GenerateReportDialog from '../components/reports/GenerateReportDialog';
import RecentReportsList from '../components/reports/RecentReportsList';
import { reportsAPI, locationAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/dashboard_air.css';
import '../styles/system_reports.css';

/**
 * Everything on this page comes from the backend.
 *
 * The version this replaced hardcoded six report cards and four "recent"
 * filenames with invented dates and byte sizes, and its Generate button only
 * raised a toast. Cards now come from /reports/catalog, so a card cannot
 * advertise a filter or format the backend does not implement, and the recent
 * list is the real report_runs table.
 */
const FORMAT_LABELS = { pdf: 'PDF', csv: 'CSV' };

const ReportCardIcon = () => (
  <div className="sr-report-icon sr-report-icon-inventory">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
      <polyline points="14 2 14 8 20 8" />
      <line x1="16" y1="13" x2="8" y2="13" />
      <line x1="16" y1="17" x2="8" y2="17" />
    </svg>
  </div>
);

const CardSkeleton = () => (
  <div className="sr-card sr-card--skeleton" aria-hidden="true">
    <span className="sr-skel-line" style={{ width: '60%' }} />
    <span className="sr-skel-line" style={{ width: '85%' }} />
    <span className="sr-skel-line" style={{ width: '40%' }} />
  </div>
);

const SystemReports = () => {
  const [catalog, setCatalog] = useState([]);
  const [catalogLoading, setCatalogLoading] = useState(true);
  const [catalogError, setCatalogError] = useState('');

  const [runs, setRuns] = useState([]);
  const [runsLoading, setRunsLoading] = useState(true);

  const [locations, setLocations] = useState([]);
  const [activeReport, setActiveReport] = useState(null);
  const [prefill, setPrefill] = useState(null);
  const [generating, setGenerating] = useState(false);

  const loadRuns = useCallback(async () => {
    setRunsLoading(true);
    try {
      const response = await reportsAPI.getRuns();
      const payload = response?.data;
      setRuns(Array.isArray(payload) ? payload : (payload?.data || []));
    } catch (error) {
      toast.error('Could not load recent reports.');
    } finally {
      setRunsLoading(false);
    }
  }, []);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const response = await reportsAPI.getCatalog();
        if (!cancelled) setCatalog(response?.data || []);
      } catch (error) {
        if (!cancelled) setCatalogError('Could not load the report catalog.');
      } finally {
        if (!cancelled) setCatalogLoading(false);
      }
    })();

    (async () => {
      try {
        const response = await locationAPI.getAll();
        if (!cancelled) setLocations(response?.data || []);
      } catch (error) {
        // A missing location list only degrades the filter dropdown; the
        // reports still run unfiltered, so this is not worth an error toast.
      }
    })();

    loadRuns();

    return () => { cancelled = true; };
  }, [loadRuns]);

  const handleGenerate = async ({ reportKey, format, parameters }) => {
    setGenerating(true);
    try {
      const response = await reportsAPI.generate({ reportKey, format, parameters });
      if (response?.success) {
        toast.success('Report generated.');
        setActiveReport(null);
        setPrefill(null);
        await loadRuns();
      } else {
        toast.error(response?.message || 'Could not generate the report.');
      }
    } catch (error) {
      toast.error(error?.message || 'Could not generate the report.');
    } finally {
      setGenerating(false);
    }
  };

  const handleRerun = (run) => {
    const report = catalog.find((r) => r.key === run.report_key);
    if (!report) {
      toast.error('That report is no longer available.');
      return;
    }
    setPrefill(run.parameters || {});
    setActiveReport(report);
  };

  return (
    <AdminLayout>
      <div className="ak-page sr-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Insights · Reports</p>
              <h1 className="ak-title">Reports</h1>
              <p className="ak-sub">Generate and download system-wide inventory and transaction reports.</p>
            </div>
          </div>
        </header>

        {catalogError && <p className="sr-page-error" role="alert">{catalogError}</p>}

        <div className="sr-cards-grid">
          {catalogLoading
            ? [0, 1, 2, 3, 4, 5].map((i) => <CardSkeleton key={i} />)
            : catalog.map((report) => (
              <div className="sr-card" key={report.key}>
                <h3 className="sr-card-title">{report.title}</h3>
                <p className="sr-card-desc">{report.description}</p>
                <div className="sr-card-content">
                  <ReportCardIcon />
                  <div className="sr-card-format-block">
                    <span className="sr-format-label">Available Formats</span>
                    <span className="sr-format-types">
                      {report.formats.map((f) => FORMAT_LABELS[f] || f.toUpperCase()).join(' / ')}
                    </span>
                  </div>
                </div>
                <div className="sr-card-actions">
                  <button
                    type="button"
                    className="sr-btn-generate"
                    onClick={() => { setPrefill(null); setActiveReport(report); }}
                  >
                    Generate Report
                  </button>
                </div>
              </div>
            ))}
        </div>

        <div className="sr-recent-card">
          <h3 className="sr-recent-title">Recent Reports</h3>
          <p className="sr-recent-subtitle">Previously generated reports, kept for 90 days</p>
          <RecentReportsList runs={runs} loading={runsLoading} onRerun={handleRerun} />
        </div>

        {activeReport && (
          <GenerateReportDialog
            key={`${activeReport.key}-${prefill ? 'rerun' : 'new'}`}
            report={activeReport}
            locations={locations}
            categories={[]}
            users={[]}
            initialValues={prefill}
            generating={generating}
            onGenerate={handleGenerate}
            onClose={() => { setActiveReport(null); setPrefill(null); }}
          />
        )}
      </div>
    </AdminLayout>
  );
};

export default SystemReports;
```

- [ ] **Step 4: Confirm the dialog already accepts `initialValues`**

No change needed — Task 16 built the dialog with an `initialValues` prop merged over
`blankValues(report)`, which is exactly what the page passes when re-running an expired run.
Verify the prop is present before moving on:

```bash
cd frontend && grep -n "initialValues" src/admin/components/reports/GenerateReportDialog.jsx
```

Expected: two hits — the prop in the signature and the spread in the state initializer.

- [ ] **Step 5: Run both frontend suites**

```bash
cd frontend && npx react-scripts test --watchAll=false --testPathPattern="SystemReports|GenerateReportDialog"
```

Expected: PASS.

If `locationAPI` is not the export name in `api.js`, grep for the locations export
(`grep -n "export const location" src/admin/services/api.js`) and use the real name in both
the page and the test mock.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/SystemReports.jsx \
        frontend/src/admin/pages/SystemReports.test.jsx \
        frontend/src/admin/components/reports/GenerateReportDialog.jsx
git commit -m "feat(reports): the reports page renders what the backend actually offers"
```

---

## Task 19: Styles

**Files:**
- Modify: `frontend/src/admin/styles/system_reports.css` (append)

- [ ] **Step 1: Append the new states**

The existing card and list styles are kept as-is. These cover only what the rewrite added:
the dialog, the expired row, skeletons, and the empty state.

```css
/* ---------- Generate dialog ---------- */
.sr-dialog-backdrop {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(15, 23, 42, 0.45);
  display: flex; align-items: center; justify-content: center; padding: 16px;
}
.sr-dialog {
  background: #fff; border-radius: 10px; padding: 22px;
  width: 100%; max-width: 480px; max-height: 90vh; overflow-y: auto;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.25);
}
.sr-dialog-title { font-size: 17px; font-weight: 600; margin-bottom: 14px; color: #0f172a; }
.sr-dialog-hint { font-size: 13px; color: #64748b; margin-bottom: 14px; }
.sr-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 13px; }
.sr-field label, .sr-field legend { font-size: 12px; font-weight: 600; color: #334155; }
.sr-field input, .sr-field select {
  border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 13px;
}
.sr-field-row { display: flex; gap: 12px; }
.sr-field-row .sr-field { flex: 1; }
.sr-format-field { border: 0; padding: 0; }
.sr-format-option { display: inline-flex; align-items: center; gap: 6px; margin-right: 16px; font-size: 13px; }
.sr-dialog-error { color: #b91c1c; font-size: 12px; margin-bottom: 10px; }
.sr-dialog-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 6px; }
.sr-btn-cancel {
  background: #f1f5f9; color: #334155; border: 0; border-radius: 6px;
  padding: 9px 16px; font-size: 13px; cursor: pointer;
}
.sr-page-error { color: #b91c1c; font-size: 13px; margin-bottom: 14px; }

/* ---------- Expired run ---------- */
.sr-recent-item--expired { opacity: 0.62; }
.sr-recent-expired-tag {
  font-size: 11px; font-weight: 600; color: #92400e;
  background: #fef3c7; border-radius: 4px; padding: 3px 8px;
}

/* ---------- Empty + loading ---------- */
.sr-recent-empty { font-size: 13px; color: #64748b; padding: 20px 4px; }
.sr-skel-line {
  display: block; height: 11px; border-radius: 4px; margin-bottom: 9px;
  background: linear-gradient(90deg, #eef2f7 25%, #e2e8f0 37%, #eef2f7 63%);
  background-size: 400% 100%; animation: sr-shimmer 1.4s ease infinite;
}
.sr-card--skeleton { pointer-events: none; }
.sr-recent-item--skeleton { display: block; }
@keyframes sr-shimmer {
  0% { background-position: 100% 50%; }
  100% { background-position: 0 50%; }
}
```

- [ ] **Step 2: Check the page in a browser**

```bash
cd frontend && npm start
```

Navigate to `/admin/reports` as an admin. Confirm: six cards render, clicking Generate opens
a dialog whose inputs differ per report (Low Stock has no dates, Transaction does), and a
generated report appears in Recent Reports with a real size and downloads.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/styles/system_reports.css
git commit -m "style(reports): dialog, expired rows, skeletons and empty state"
```

---

## Task 20: Full verification

- [ ] **Step 1: Run the entire backend reports suite**

```bash
cd back-end && php artisan test --filter=Reports
```

Expected: all pass. Record the actual count in the commit message rather than assuming it.

- [ ] **Step 2: Run the full backend suite for regressions**

```bash
cd back-end && php artisan test
```

Expected: no new failures. The route deletion in Task 14 is the risk — if anything fails,
check whether a test called one of the seven removed closures. If one did, that test was
covering dead code and should be deleted along with a note in the commit message; do not
resurrect the closure.

- [ ] **Step 3: Run the frontend suite**

```bash
cd frontend && npx react-scripts test --watchAll=false
```

Expected: no new failures.

- [ ] **Step 4: Verify the retention command**

```bash
cd back-end && php artisan reports:prune
```

Expected: prints a pruned count without error.

- [ ] **Step 5: Commit any fixes**

```bash
git add -A
git commit -m "test(reports): full suite green after wiring the reports page"
```

---

## Self-review notes

Checked against `docs/superpowers/specs/2026-08-17-system-reports-design.md`:

- **Every spec section maps to a task.** Registry → 3, 4. Runner → 12. Scoping → in each
  definition, tested in 6 and 14. `report_runs` → 1. API → 14. Cleanup of the dead closures
  and the `report_viewing.create` action → 14 and 2. Six reports → 6-11. Frontend → 15-19.
  Failure handling → 12 (empty, row cap, partial write) and 14 (missing file, expired).
  Retention → 13.
- **One spec item is deliberately dropped:** the spec's "View opens the PDF inline" is
  implemented as a `?inline=1` query param on the same download route rather than a separate
  endpoint, since it differs only by `Content-Disposition`.
- **Naming is consistent across tasks:** the dialog helper is `blankValues` and the prop is
  `initialValues`, distinct from the outset so Task 18 needs no rename; `report_run_id`
  everywhere; `units_handled` in both the Task 10 test and the definition; `fileUrl` in both
  `api.js` and `RecentReportsList`.
- **Known-red window:** `ReportRegistryTest` (Task 4) fails until Task 11 completes. This is
  called out in Task 4 Step 5 and re-run in Task 11 Step 4.
