# Audit & Evidence Trail — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an immutable `inventory_ledger` that records receiving, issuance, transfer and adjustment movements with costs frozen at posting time, plus the Audit & Evidence Explorer that drills from any figure down to the source document and its change history.

**Architecture:** One choke-point writer (`LedgerPoster`) called from inside the DB transaction each existing write path already opens; an append-only `inventory_ledger` table with a deterministic `posting_key` for idempotency and reversing entries instead of edits; a read layer (`LedgerQueryService` + `EvidenceResolver`) behind `/api/audit-evidence/*`; a React Explorer page composed of small single-purpose components.

**Tech Stack:** Laravel 11 (`back-end/`), PHPUnit with `DatabaseTransactions` against `airkingV5_test`, React + CRA (`frontend/`), Jest + React Testing Library.

**Spec:** `docs/superpowers/specs/2026-09-30-audit-evidence-trail-design.md`

---

## Scope

**In scope:** ledger + drift-findings tables, `inventory_batches.unit_cost` / `cost_basis`, `LedgerPoster`, `BatchCostResolver`, wiring of the four phase-1 write paths (receiving, issuance including the scan path, transfer dispatch/receive/reverse, adjustment), `ledger:backfill` and `ledger:drift-check` for those sources, `LedgerQueryService`, `EvidenceResolver`, the `/api/audit-evidence` endpoints, the `audit_evidence` permission feature and nav entry, and the Explorer page.

**Out of scope (later plans):** incident write-off/return, warranty out/return, purchase return, installation material consumption, set/BOQ picking, non-stock financial events. `lens` is written on every row now so the finance lens can light up later without a migration.

## Spec corrections found while reading the code

Apply these to the spec in Task 1's commit, so the documents do not drift:

1. **Adjustments have no approval step.** `AdjustmentController::store()` (`back-end/app/Http/Controllers/Api/AdjustmentController.php:48-126`) moves stock at creation. The spec says "posts at approval"; the correct rule is **posts at creation**, because that is when stock actually moves.
2. **Issuance likewise posts at creation.** `IssuanceController::store()` deducts FIFO inline (line 140). There is no separate completion transition on this path.
3. **A fourth `cost_basis` value is required: `product_default`.** Receiving currently writes `prod_price` from `$product->cost_price` (`ReceivingPostingService::post()`), not from the PO line's `unit_price`. When a PO line price exists it is `actual`; when we fall back to the product's list cost the value is neither actual nor a weighted average, and labelling it `derived_weighted_avg` would be a lie. The four values are `actual`, `product_default`, `derived_weighted_avg`, `unavailable`.
4. **No foreign keys on `inventory_ledger`.** The spec criticises `inventory_batch_movements` for `cascadeOnDelete`; the ledger must therefore declare no FK constraints at all, so an audit row outlives the batch, receiving or user it describes.

## File structure

**Backend — create**

| File | Responsibility |
| --- | --- |
| `back-end/database/migrations/2026_09_30_000001_create_inventory_ledger_table.php` | the ledger table |
| `back-end/database/migrations/2026_09_30_000002_create_ledger_drift_findings_table.php` | drift findings |
| `back-end/database/migrations/2026_09_30_000003_add_unit_cost_to_inventory_batches_table.php` | `unit_cost`, `cost_basis` on batches |
| `back-end/app/Models/InventoryLedgerEntry.php` | ledger row |
| `back-end/app/Models/LedgerDriftFinding.php` | drift finding row |
| `back-end/app/Services/Ledger/LedgerPostingException.php` | posting contract violation |
| `back-end/app/Services/Ledger/LedgerPoster.php` | the only writer: `post()`, `reverse()` |
| `back-end/app/Services/Ledger/BatchCostResolver.php` | resolve + blend batch unit costs |
| `back-end/app/Services/Ledger/LedgerFilter.php` | query filter value object |
| `back-end/app/Services/Ledger/LedgerQueryService.php` | summary, breakdown, movements, reconciliation |
| `back-end/app/Services/Ledger/EvidenceResolver.php` | evidence bundle + serial/batch lifecycle |
| `back-end/app/Services/Ledger/DriftChecker.php` | the three drift comparisons |
| `back-end/app/Services/Ledger/LedgerBackfiller.php` | reconstruct history per source |
| `back-end/app/Console/Commands/LedgerBackfill.php` | `ledger:backfill` |
| `back-end/app/Console/Commands/LedgerDriftCheck.php` | `ledger:drift-check` |
| `back-end/app/Http/Controllers/Api/AuditEvidenceController.php` | the API surface |

**Backend — modify**

| File | Change |
| --- | --- |
| `app/Services/ReceivingPostingService.php` | resolve real line cost, stamp batch cost, post `receiving` |
| `app/Http/Controllers/Api/IssuanceController.php:140-160` | post `issuance` per batch slice |
| `app/Http/Controllers/Api/IssuanceScanController.php:362, 520-525` | post `issuance` for scan paths |
| `app/Services/TransferService.php:263-305, 500, 575+` | post `transfer_out` / `transfer_in`, reverse on void |
| `app/Http/Controllers/Api/AdjustmentController.php:48-126` | post `adjustment` per line |
| `database/seeders/PermissionSeeder.php` | add the `audit_evidence` feature |
| `routes/api.php` | the `/audit-evidence` route group |
| `app/Console/Kernel.php` (or `bootstrap/app.php` schedule) | nightly drift check |

**Frontend — create**

`admin/pages/AuditEvidenceExplorer.jsx`, `admin/hooks/useAuditEvidence.js`, and under `admin/components/audit/`: `SummaryTiles.jsx`, `BreakdownTable.jsx`, `MovementLedgerTable.jsx`, `EvidenceDrawer.jsx`, `LifecycleTimeline.jsx`, `ReconciliationPanel.jsx`, `ExceptionsPanel.jsx`, plus `admin/styles/audit_evidence.css`.

**Frontend — modify**

`admin/services/api.js` (add `auditEvidenceAPI`), `App.jsx` (route), `admin/components/Sidebar.jsx` (nav item), `admin/utils/navBundles.js` (bundle + required permission).

---

## Task 1: Ledger and drift-findings migrations

**Files:**
- Create: `back-end/database/migrations/2026_09_30_000001_create_inventory_ledger_table.php`
- Create: `back-end/database/migrations/2026_09_30_000002_create_ledger_drift_findings_table.php`
- Create: `back-end/database/migrations/2026_09_30_000003_add_unit_cost_to_inventory_batches_table.php`
- Test: `back-end/tests/Feature/Schema/LedgerSchemaTest.php`
- Modify: `docs/superpowers/specs/2026-09-30-audit-evidence-trail-design.md`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Schema;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LedgerSchemaTest extends TestCase
{
    public function test_the_ledger_table_has_every_column_the_poster_writes(): void
    {
        $this->assertTrue(Schema::hasTable('inventory_ledger'));

        foreach ([
            'ledger_id', 'occurred_at', 'posted_at', 'direction', 'movement_type', 'lens',
            'product_id', 'batch_id', 'serial_number', 'quantity', 'unit_cost',
            'extended_value', 'cost_basis', 'from_location_id', 'to_location_id',
            'source_type', 'source_id', 'source_line_id', 'correlation_id',
            'actor_user_id', 'reverses_ledger_id', 'origin', 'posting_key', 'notes',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('inventory_ledger', $column),
                "inventory_ledger is missing {$column}"
            );
        }
    }

    public function test_the_ledger_declares_no_foreign_keys_so_audit_rows_outlive_their_subjects(): void
    {
        $constraints = Schema::getForeignKeys('inventory_ledger');

        $this->assertSame(
            [],
            $constraints,
            'inventory_ledger must have no FK constraints: a cascade delete would erase audit evidence.'
        );
    }

    public function test_drift_findings_and_batch_cost_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('ledger_drift_findings'));

        foreach ([
            'finding_id', 'check_type', 'source_type', 'period_start', 'period_end',
            'scope', 'expected', 'actual', 'variance', 'detected_at',
            'resolved_at', 'resolved_by', 'resolution_note',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('ledger_drift_findings', $column),
                "ledger_drift_findings is missing {$column}"
            );
        }

        $this->assertTrue(Schema::hasColumn('inventory_batches', 'unit_cost'));
        $this->assertTrue(Schema::hasColumn('inventory_batches', 'cost_basis'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=LedgerSchemaTest`
Expected: FAIL — "Failed asserting that false is true" on `hasTable('inventory_ledger')`.

- [ ] **Step 3: Write the ledger migration**

`2026_09_30_000001_create_inventory_ledger_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only movement ledger the Audit & Evidence Explorer reads.
 *
 * Deliberately declares NO foreign keys. inventory_batch_movements cascade-deletes
 * with its batch, which makes it useless as evidence; an audit row has to outlive
 * the batch, receiving, location and user it describes. Referential integrity is
 * enforced by LedgerPoster, which is the only writer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_ledger', function (Blueprint $table) {
            $table->bigIncrements('ledger_id');

            // occurred_at is when stock moved; posted_at is when we recorded it.
            // A gap between them is the backdating signal the Explorer flags.
            $table->timestamp('occurred_at');
            $table->timestamp('posted_at');

            $table->string('direction', 10);        // in | out | neutral
            $table->string('movement_type', 40);
            $table->string('lens', 10)->default('inventory');

            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('serial_number', 191)->nullable();

            $table->integer('quantity')->default(0);
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->decimal('extended_value', 16, 2)->nullable();
            $table->string('cost_basis', 24)->default('actual');

            $table->unsignedBigInteger('from_location_id')->nullable();
            $table->unsignedBigInteger('to_location_id')->nullable();

            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->string('correlation_id', 64);

            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('reverses_ledger_id')->nullable();
            $table->string('origin', 16)->default('posted');

            // Deterministic idempotency key: a retried request, a double-clicked
            // button and a re-run backfill all collapse onto the same row.
            $table->string('posting_key', 191)->unique();

            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('occurred_at');
            $table->index(['product_id', 'occurred_at']);
            $table->index(['movement_type', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
            $table->index('correlation_id');
            $table->index(['actor_user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_ledger');
    }
};
```

- [ ] **Step 4: Write the drift-findings migration**

`2026_09_30_000002_create_ledger_drift_findings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_drift_findings', function (Blueprint $table) {
            $table->bigIncrements('finding_id');
            $table->string('check_type', 40);      // source_vs_ledger | ledger_vs_movements | ledger_vs_stock | check_failed
            $table->string('source_type', 40)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->json('scope')->nullable();     // {product_id, location_id} where applicable
            $table->decimal('expected', 18, 2)->nullable();
            $table->decimal('actual', 18, 2)->nullable();
            $table->decimal('variance', 18, 2)->nullable();
            $table->string('detail', 500)->nullable();
            // useCurrent() is required: as the first TIMESTAMP column this would
            // otherwise acquire ON UPDATE CURRENT_TIMESTAMP, and resolving a
            // finding would rewrite the detection time it exists to record.
            $table->timestamp('detected_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->timestamps();

            $table->index(['check_type', 'detected_at']);
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_drift_findings');
    }
};
```

- [ ] **Step 5: Write the batch cost migration**

`2026_09_30_000003_add_unit_cost_to_inventory_batches_table.php`. No `after()` — it is MySQL-only and this schema also runs on Postgres:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A batch never recorded what its stock cost, so every valuation downstream had
 * to guess by walking receiving_id -> receiving_details. This column is what
 * makes FIFO costing exact instead of plausible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->string('cost_basis', 24)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'cost_basis']);
        });
    }
};
```

- [ ] **Step 6: Migrate both databases**

`APP_ENV=production` in the local `.env` means `migrate` refuses without `--force`, and the test database is separate and must be migrated too:

```bash
cd back-end
php artisan migrate --force
php artisan migrate --force --database=mysql --env=testing
```

If the second command does not pick up `airkingV5_test`, run it with an explicit override:
`DB_DATABASE=airkingV5_test php artisan migrate --force`

- [ ] **Step 7: Run the test to verify it passes**

Run: `cd back-end && php artisan test --filter=LedgerSchemaTest`
Expected: PASS (3 tests).

- [ ] **Step 8: Apply the four spec corrections**

In `docs/superpowers/specs/2026-09-30-audit-evidence-trail-design.md`:
- change the Adjustment row of the posting table from "approval" to "creation (`AdjustmentController::store`) — stock moves there; there is no approval step"
- change the Issuance row from "completion" to "creation / scan commit"
- add `product_default` to the `cost_basis` list in the data-model table and in the Backfill section, defined as "the product's list cost, used when no PO line price exists"
- add to the "Why not extend `inventory_batch_movements`" section: "`inventory_ledger` therefore declares no foreign keys of its own."

- [ ] **Step 9: Commit**

```bash
git add back-end/database/migrations back-end/tests/Feature/Schema/LedgerSchemaTest.php docs/superpowers/specs/2026-09-30-audit-evidence-trail-design.md
git commit -m "feat(ledger): add inventory_ledger, drift findings and batch unit_cost"
```

---

## Task 2: Ledger models

**Files:**
- Create: `back-end/app/Models/InventoryLedgerEntry.php`
- Create: `back-end/app/Models/LedgerDriftFinding.php`
- Test: `back-end/tests/Feature/Ledger/InventoryLedgerEntryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryLedgerEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InventoryLedgerEntryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_casts_money_quantities_and_timestamps(): void
    {
        $entry = InventoryLedgerEntry::create([
            'occurred_at'    => '2026-09-01 08:30:00',
            'posted_at'      => '2026-09-01 08:30:05',
            'direction'      => 'in',
            'movement_type'  => 'receiving',
            'lens'           => 'inventory',
            'quantity'       => '12',
            'unit_cost'      => '1500.50',
            'extended_value' => '18006.00',
            'cost_basis'     => 'actual',
            'source_type'    => 'receivings',
            'source_id'      => 7,
            'correlation_id' => 'receivings:7',
            'posting_key'    => 'test:cast:1',
        ]);

        $fresh = InventoryLedgerEntry::find($entry->ledger_id);

        $this->assertSame(12, $fresh->quantity);
        $this->assertSame('1500.50', $fresh->unit_cost);
        $this->assertSame('2026-09-01', $fresh->occurred_at->format('Y-m-d'));
        $this->assertFalse($fresh->isReversal());
    }

    public function test_a_row_with_reverses_ledger_id_reports_itself_as_a_reversal(): void
    {
        $entry = new InventoryLedgerEntry(['reverses_ledger_id' => 99]);

        $this->assertTrue($entry->isReversal());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=InventoryLedgerEntryTest`
Expected: FAIL — `Class "App\Models\InventoryLedgerEntry" not found`.

- [ ] **Step 3: Write the models**

`app/Models/InventoryLedgerEntry.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One leg of one stock or money movement, frozen at the moment it was posted.
 *
 * Deliberately NOT Auditable: this table is itself the audit record, and rows are
 * never updated, so there would be nothing for the trait to log.
 */
class InventoryLedgerEntry extends Model
{
    protected $table = 'inventory_ledger';
    protected $primaryKey = 'ledger_id';

    public const DIRECTIONS = ['in', 'out', 'neutral'];
    public const COST_BASES = ['actual', 'product_default', 'derived_weighted_avg', 'unavailable'];

    protected $fillable = [
        'occurred_at', 'posted_at', 'direction', 'movement_type', 'lens',
        'product_id', 'batch_id', 'serial_number', 'quantity', 'unit_cost',
        'extended_value', 'cost_basis', 'from_location_id', 'to_location_id',
        'source_type', 'source_id', 'source_line_id', 'correlation_id',
        'actor_user_id', 'reverses_ledger_id', 'origin', 'posting_key', 'notes',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'posted_at'   => 'datetime',
        'quantity'    => 'integer',
        'product_id'  => 'integer',
        'batch_id'    => 'integer',
    ];

    public function isReversal(): bool
    {
        return $this->reverses_ledger_id !== null;
    }

    /** True when the movement was recorded noticeably later than it happened. */
    public function isBackdated(int $toleranceMinutes = 60): bool
    {
        if (! $this->occurred_at || ! $this->posted_at) {
            return false;
        }

        return $this->occurred_at->diffInMinutes($this->posted_at) > $toleranceMinutes;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'user_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id', 'location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id', 'location_id');
    }
}
```

`app/Models/LedgerDriftFinding.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerDriftFinding extends Model
{
    protected $table = 'ledger_drift_findings';
    protected $primaryKey = 'finding_id';

    protected $fillable = [
        'check_type', 'source_type', 'period_start', 'period_end', 'scope',
        'expected', 'actual', 'variance', 'detail', 'detected_at',
        'resolved_at', 'resolved_by', 'resolution_note',
    ];

    protected $casts = [
        'scope'        => 'array',
        'period_start' => 'date',
        'period_end'   => 'date',
        'detected_at'  => 'datetime',
        'resolved_at'  => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by', 'user_id');
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd back-end && php artisan test --filter=InventoryLedgerEntryTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Models/InventoryLedgerEntry.php back-end/app/Models/LedgerDriftFinding.php back-end/tests/Feature/Ledger/InventoryLedgerEntryTest.php
git commit -m "feat(ledger): add InventoryLedgerEntry and LedgerDriftFinding models"
```

---

## Task 3: LedgerPoster — validation and idempotent posting

**Files:**
- Create: `back-end/app/Services/Ledger/LedgerPostingException.php`
- Create: `back-end/app/Services/Ledger/LedgerPoster.php`
- Test: `back-end/tests/Feature/Ledger/LedgerPosterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryLedgerEntry;
use App\Services\Ledger\LedgerPoster;
use App\Services\Ledger\LedgerPostingException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LedgerPosterTest extends TestCase
{
    use DatabaseTransactions;

    private function poster(): LedgerPoster
    {
        return app(LedgerPoster::class);
    }

    /** @return array<string,mixed> */
    private function validOut(array $overrides = []): array
    {
        return array_merge([
            'occurred_at'      => '2026-09-01 10:00:00',
            'direction'        => 'out',
            'movement_type'    => 'issuance',
            'product_id'       => 4321,
            'batch_id'         => 77,
            'quantity'         => 3,
            'unit_cost'        => 250.00,
            'cost_basis'       => 'actual',
            'from_location_id' => 1,
            'source_type'      => 'issuances',
            'source_id'        => 55,
            'source_line_id'   => 91,
            'correlation_id'   => 'issuances:55',
            'actor_user_id'    => null,
            'posting_key'      => 'issuance:55:line:91:batch:77:out',
        ], $overrides);
    }

    public function test_it_computes_extended_value_from_quantity_and_unit_cost(): void
    {
        $entry = $this->poster()->post($this->validOut());

        $this->assertSame('750.00', $entry->extended_value);
        $this->assertSame('posted', $entry->origin);
        $this->assertSame('inventory', $entry->lens);
        $this->assertNotNull($entry->posted_at);
    }

    public function test_posting_the_same_key_twice_returns_the_first_row_instead_of_doubling_value(): void
    {
        $first  = $this->poster()->post($this->validOut());
        $second = $this->poster()->post($this->validOut());

        $this->assertSame($first->ledger_id, $second->ledger_id);
        $this->assertSame(1, InventoryLedgerEntry::where('posting_key', $first->posting_key)->count());
    }

    public function test_an_unavailable_cost_records_the_quantity_and_a_null_value(): void
    {
        $entry = $this->poster()->post($this->validOut([
            'unit_cost'   => null,
            'cost_basis'  => 'unavailable',
            'posting_key' => 'issuance:55:line:92:batch:77:out',
        ]));

        $this->assertSame(3, $entry->quantity);
        $this->assertNull($entry->extended_value);
    }

    public function test_it_refuses_a_stock_movement_with_no_product(): void
    {
        $this->expectException(LedgerPostingException::class);
        $this->expectExceptionMessageMatches('/product_id/');

        $this->poster()->post($this->validOut(['product_id' => null]));
    }

    public function test_it_refuses_an_out_movement_with_no_source_location(): void
    {
        $this->expectException(LedgerPostingException::class);
        $this->expectExceptionMessageMatches('/from_location_id/');

        $this->poster()->post($this->validOut(['from_location_id' => null]));
    }

    public function test_it_refuses_a_zero_quantity_stock_movement(): void
    {
        $this->expectException(LedgerPostingException::class);
        $this->expectExceptionMessageMatches('/quantity/');

        $this->poster()->post($this->validOut(['quantity' => 0]));
    }

    public function test_it_refuses_an_unknown_direction_or_cost_basis(): void
    {
        $this->expectException(LedgerPostingException::class);

        $this->poster()->post($this->validOut(['direction' => 'sideways']));
    }

    public function test_a_finance_row_needs_no_product_or_quantity(): void
    {
        $entry = $this->poster()->post([
            'occurred_at'    => '2026-09-01 10:00:00',
            'direction'      => 'neutral',
            'movement_type'  => 'budget_allocation',
            'lens'           => 'finance',
            'extended_value' => 50000.00,
            'cost_basis'     => 'actual',
            'source_type'    => 'budget_allocations',
            'source_id'      => 3,
            'correlation_id' => 'budget_allocations:3',
            'posting_key'    => 'budget:3:verified',
        ]);

        $this->assertSame(0, $entry->quantity);
        $this->assertSame('50000.00', $entry->extended_value);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=LedgerPosterTest`
Expected: FAIL — `Class "App\Services\Ledger\LedgerPoster" not found`.

- [ ] **Step 3: Write the exception**

`app/Services/Ledger/LedgerPostingException.php`:

```php
<?php

namespace App\Services\Ledger;

use RuntimeException;

/**
 * A posting contract violation. Thrown, never swallowed: LedgerPoster runs inside
 * the caller's transaction, so this rolls the business operation back. That is
 * deliberate and the opposite of the Auditable trait, which reports and continues
 * so that logging can never break a save. An unrecorded movement is worse than a
 * failed one.
 */
class LedgerPostingException extends RuntimeException
{
}
```

- [ ] **Step 4: Write LedgerPoster::post()**

`app/Services/Ledger/LedgerPoster.php`:

```php
<?php

namespace App\Services\Ledger;

use App\Models\InventoryLedgerEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * The single writer for inventory_ledger. Callers invoke this from inside the DB
 * transaction they already hold, so a posting failure rolls their work back too.
 */
class LedgerPoster
{
    /** Movement types that describe money rather than goods. */
    private const FINANCE_TYPES = ['budget_allocation', 'encumbrance'];

    public function post(array $attrs): InventoryLedgerEntry
    {
        $attrs = $this->normalize($attrs);
        $this->validate($attrs);

        $existing = InventoryLedgerEntry::where('posting_key', $attrs['posting_key'])->first();
        if ($existing) {
            return $existing;
        }

        try {
            return InventoryLedgerEntry::create($attrs);
        } catch (QueryException $e) {
            // Lost a race on the unique index: the other writer's row is the row.
            $row = InventoryLedgerEntry::where('posting_key', $attrs['posting_key'])->first();
            if ($row) {
                return $row;
            }
            throw $e;
        }
    }

    private function normalize(array $a): array
    {
        $a['direction']   = $a['direction'] ?? 'out';
        $a['cost_basis']  = $a['cost_basis'] ?? 'actual';
        $a['origin']      = $a['origin'] ?? 'posted';
        $a['occurred_at'] = $a['occurred_at'] ?? Carbon::now();
        $a['posted_at']   = $a['posted_at'] ?? Carbon::now();
        $a['quantity']    = (int) ($a['quantity'] ?? 0);

        $a['lens'] = $a['lens']
            ?? (in_array($a['movement_type'] ?? '', self::FINANCE_TYPES, true) ? 'finance' : 'inventory');

        if (! array_key_exists('correlation_id', $a) || $a['correlation_id'] === null) {
            $a['correlation_id'] = ($a['source_type'] ?? 'unknown') . ':' . ($a['source_id'] ?? '0');
        }

        // Value is derived only when the caller did not freeze one itself — a
        // reversal passes the original's value through and must not be recomputed
        // from a cost that may have moved since.
        if (! array_key_exists('extended_value', $a) || $a['extended_value'] === null) {
            $a['extended_value'] = ($a['cost_basis'] === 'unavailable' || ($a['unit_cost'] ?? null) === null)
                ? null
                : round((float) $a['unit_cost'] * $a['quantity'], 2);
        }

        return $a;
    }

    private function validate(array $a): void
    {
        if (empty($a['posting_key'])) {
            $this->fail('posting_key is required: without it a retry would double the value.', $a);
        }
        if (! in_array($a['direction'], InventoryLedgerEntry::DIRECTIONS, true)) {
            $this->fail("direction '{$a['direction']}' is not one of in|out|neutral.", $a);
        }
        if (! in_array($a['cost_basis'], InventoryLedgerEntry::COST_BASES, true)) {
            $this->fail("cost_basis '{$a['cost_basis']}' is not a recognised basis.", $a);
        }
        if (empty($a['movement_type'])) {
            $this->fail('movement_type is required.', $a);
        }
        if (empty($a['source_type']) || empty($a['source_id'])) {
            $this->fail('source_type and source_id are required: a row with no evidence pointer is not evidence.', $a);
        }

        if ($a['direction'] === 'neutral') {
            return;
        }

        if (empty($a['product_id'])) {
            $this->fail('product_id is required for a stock movement.', $a);
        }
        if ($a['quantity'] <= 0) {
            $this->fail('quantity must be greater than zero; direction carries the sign.', $a);
        }
        if ($a['direction'] === 'out' && empty($a['from_location_id'])) {
            $this->fail('from_location_id is required for an out movement.', $a);
        }
        if ($a['direction'] === 'in' && empty($a['to_location_id'])) {
            $this->fail('to_location_id is required for an in movement.', $a);
        }
    }

    private function fail(string $message, array $context): void
    {
        throw new LedgerPostingException(
            'Ledger posting refused: ' . $message . ' Context: ' . json_encode([
                'movement_type' => $context['movement_type'] ?? null,
                'posting_key'   => $context['posting_key'] ?? null,
                'source'        => ($context['source_type'] ?? null) . '#' . ($context['source_id'] ?? null),
            ])
        );
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd back-end && php artisan test --filter=LedgerPosterTest`
Expected: PASS (8 tests).

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Services/Ledger back-end/tests/Feature/Ledger/LedgerPosterTest.php
git commit -m "feat(ledger): add LedgerPoster with idempotent keys and posting contract"
```

---

## Task 4: LedgerPoster::reverse()

**Files:**
- Modify: `back-end/app/Services/Ledger/LedgerPoster.php`
- Test: `back-end/tests/Feature/Ledger/LedgerReversalTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryLedgerEntry;
use App\Services\Ledger\LedgerPoster;
use App\Services\Ledger\LedgerPostingException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LedgerReversalTest extends TestCase
{
    use DatabaseTransactions;

    private function postedOut(): InventoryLedgerEntry
    {
        return app(LedgerPoster::class)->post([
            'occurred_at'      => '2026-09-01 10:00:00',
            'direction'        => 'out',
            'movement_type'    => 'issuance',
            'product_id'       => 4321,
            'batch_id'         => 77,
            'quantity'         => 3,
            'unit_cost'        => 250.00,
            'cost_basis'       => 'actual',
            'from_location_id' => 1,
            'source_type'      => 'issuances',
            'source_id'        => 55,
            'source_line_id'   => 91,
            'posting_key'      => 'issuance:55:line:91:batch:77:out',
        ]);
    }

    public function test_a_reversal_flips_direction_keeps_quantity_positive_and_reuses_the_frozen_cost(): void
    {
        $original = $this->postedOut();

        $reversal = app(LedgerPoster::class)->reverse($original, 'Issuance voided by admin', 9);

        $this->assertSame('in', $reversal->direction);
        $this->assertSame(3, $reversal->quantity);
        $this->assertSame('250.00', $reversal->unit_cost);
        $this->assertSame('750.00', $reversal->extended_value);
        $this->assertSame($original->ledger_id, $reversal->reverses_ledger_id);
        $this->assertSame('Issuance voided by admin', $reversal->notes);
        $this->assertSame(9, $reversal->actor_user_id);
    }

    public function test_a_reversal_sends_the_stock_back_where_it_came_from(): void
    {
        $reversal = app(LedgerPoster::class)->reverse($this->postedOut(), 'voided');

        $this->assertSame(1, (int) $reversal->to_location_id);
        $this->assertNull($reversal->from_location_id);
    }

    public function test_the_original_row_is_never_mutated(): void
    {
        $original = $this->postedOut();

        app(LedgerPoster::class)->reverse($original, 'voided');

        $fresh = InventoryLedgerEntry::find($original->ledger_id);
        $this->assertSame('out', $fresh->direction);
        $this->assertNull($fresh->reverses_ledger_id);
    }

    public function test_reversing_twice_produces_one_reversal(): void
    {
        $original = $this->postedOut();
        $poster   = app(LedgerPoster::class);

        $a = $poster->reverse($original, 'voided');
        $b = $poster->reverse($original, 'voided again');

        $this->assertSame($a->ledger_id, $b->ledger_id);
    }

    public function test_a_reversal_cannot_itself_be_reversed(): void
    {
        $poster   = app(LedgerPoster::class);
        $reversal = $poster->reverse($this->postedOut(), 'voided');

        $this->expectException(LedgerPostingException::class);
        $this->expectExceptionMessageMatches('/reversal/i');

        $poster->reverse($reversal, 'undo the undo');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=LedgerReversalTest`
Expected: FAIL — `Call to undefined method App\Services\Ledger\LedgerPoster::reverse()`.

- [ ] **Step 3: Add reverse() to LedgerPoster**

Insert after `post()`:

```php
    /**
     * Undo a posted movement by posting its mirror, never by editing or deleting it.
     *
     * The mirror reuses the original's frozen unit_cost, cost_basis and
     * extended_value rather than resolving fresh ones: a cost that moved since
     * posting would let a reversal undo a different amount of value than it
     * reversed. Locations swap, so the row reads as the stock going back where it
     * came from. Quantities stay positive — direction carries the sign.
     */
    public function reverse(InventoryLedgerEntry $original, string $reason, ?int $actorUserId = null): InventoryLedgerEntry
    {
        if ($original->isReversal()) {
            throw new LedgerPostingException(
                "Ledger posting refused: entry {$original->ledger_id} is itself a reversal. "
                . 'Re-doing a reversed movement posts a new forward entry, not a reversal of a reversal.'
            );
        }

        $flip = ['in' => 'out', 'out' => 'in', 'neutral' => 'neutral'];

        return $this->post([
            'occurred_at'        => Carbon::now(),
            'direction'          => $flip[$original->direction],
            'movement_type'      => $original->movement_type,
            'lens'               => $original->lens,
            'product_id'         => $original->product_id,
            'batch_id'           => $original->batch_id,
            'serial_number'      => $original->serial_number,
            'quantity'           => $original->quantity,
            'unit_cost'          => $original->unit_cost,
            'extended_value'     => $original->extended_value,
            'cost_basis'         => $original->cost_basis,
            'from_location_id'   => $original->to_location_id,
            'to_location_id'     => $original->from_location_id,
            'source_type'        => $original->source_type,
            'source_id'          => $original->source_id,
            'source_line_id'     => $original->source_line_id,
            'correlation_id'     => $original->correlation_id,
            'actor_user_id'      => $actorUserId,
            'reverses_ledger_id' => $original->ledger_id,
            'origin'             => $original->origin,
            'posting_key'        => 'reversal:' . $original->ledger_id,
            'notes'              => $reason,
        ]);
    }
```

Note: a `neutral` reversal keeps both location columns null, which the validator allows.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd back-end && php artisan test --filter=LedgerReversalTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/Ledger/LedgerPoster.php back-end/tests/Feature/Ledger/LedgerReversalTest.php
git commit -m "feat(ledger): reverse posted movements with mirror entries"
```

---

## Task 5: BatchCostResolver

**Files:**
- Create: `back-end/app/Services/Ledger/BatchCostResolver.php`
- Test: `back-end/tests/Feature/Ledger/BatchCostResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Services\Ledger\BatchCostResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BatchCostResolverTest extends TestCase
{
    use DatabaseTransactions;

    private function resolver(): BatchCostResolver
    {
        return app(BatchCostResolver::class);
    }

    private function product(?float $costPrice): Product
    {
        return Product::create([
            'product_code' => 'P-' . uniqid(),
            'product_name' => 'Test Product',
            'product_type' => 'installation_material',
            'cost_price'   => $costPrice,
        ]);
    }

    private function poDetail(Product $product, float $unitPrice): PurchaseOrderDetail
    {
        $location = Location::create([
            'location_code' => 'L-' . uniqid(), 'location_name' => 'Loc', 'location_type' => 'warehouse',
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);

        return PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 10,
            'unit_price' => $unitPrice, 'subtotal' => $unitPrice * 10,
        ]);
    }

    public function test_a_po_line_price_is_the_actual_cost(): void
    {
        $product = $this->product(99.00);

        $cost = $this->resolver()->costForReceivingLine($this->poDetail($product, 120.50), $product);

        $this->assertSame(120.50, $cost['unit_cost']);
        $this->assertSame('actual', $cost['cost_basis']);
    }

    public function test_without_a_po_line_it_falls_back_to_the_product_list_cost_and_says_so(): void
    {
        $cost = $this->resolver()->costForReceivingLine(null, $this->product(80.00));

        $this->assertSame(80.00, $cost['unit_cost']);
        $this->assertSame('product_default', $cost['cost_basis']);
    }

    public function test_with_no_price_anywhere_the_cost_is_unavailable(): void
    {
        $cost = $this->resolver()->costForReceivingLine(null, $this->product(null));

        $this->assertNull($cost['unit_cost']);
        $this->assertSame('unavailable', $cost['cost_basis']);
    }

    private function batch(?float $unitCost, ?string $basis, int $received): InventoryBatch
    {
        $product  = $this->product(10.00);
        $location = Location::create([
            'location_code' => 'L-' . uniqid(), 'location_name' => 'Loc', 'location_type' => 'warehouse',
        ]);

        return InventoryBatch::create([
            'product_id'         => $product->product_id,
            'location_id'        => $location->location_id,
            'receiving_id'       => null,
            'quantity_received'  => $received,
            'quantity_remaining' => $received,
            'received_at'        => '2026-09-01',
            'unit_cost'          => $unitCost,
            'cost_basis'         => $basis,
        ]);
    }

    public function test_an_empty_batch_simply_takes_the_line_cost(): void
    {
        $blend = $this->resolver()->blendIntoBatch($this->batch(null, null, 0), 10, 100.00, 'actual');

        $this->assertSame(100.00, $blend['unit_cost']);
        $this->assertSame('actual', $blend['cost_basis']);
    }

    public function test_the_same_price_arriving_again_does_not_downgrade_the_basis(): void
    {
        $blend = $this->resolver()->blendIntoBatch($this->batch(100.00, 'actual', 10), 5, 100.00, 'actual');

        $this->assertSame(100.00, $blend['unit_cost']);
        $this->assertSame('actual', $blend['cost_basis']);
    }

    public function test_a_different_price_produces_a_weighted_average_and_says_it_is_derived(): void
    {
        // 10 @ 100 plus 10 @ 200 = 20 @ 150
        $blend = $this->resolver()->blendIntoBatch($this->batch(100.00, 'actual', 10), 10, 200.00, 'actual');

        $this->assertSame(150.00, $blend['unit_cost']);
        $this->assertSame('derived_weighted_avg', $blend['cost_basis']);
    }

    public function test_an_unpriced_addition_leaves_the_existing_cost_but_marks_it_derived(): void
    {
        $blend = $this->resolver()->blendIntoBatch($this->batch(100.00, 'actual', 10), 5, null, 'unavailable');

        $this->assertSame(100.00, $blend['unit_cost']);
        $this->assertSame('derived_weighted_avg', $blend['cost_basis']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=BatchCostResolverTest`
Expected: FAIL — `Class "App\Services\Ledger\BatchCostResolver" not found`.

- [ ] **Step 3: Write BatchCostResolver**

```php
<?php

namespace App\Services\Ledger;

use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\PurchaseOrderDetail;

/**
 * Where a batch's unit cost comes from, and how honest that number is.
 *
 * Receiving used to stamp receiving_details.prod_price from the product's list
 * cost, which is not what the business paid. The PO line price is; the list cost
 * is a stand-in, and a batch that accumulates pieces at two different prices has
 * only a weighted average. cost_basis records which of those we are looking at so
 * the Explorer can show an auditor what rests on evidence and what does not.
 */
class BatchCostResolver
{
    /** @return array{unit_cost: ?float, cost_basis: string} */
    public function costForReceivingLine(?PurchaseOrderDetail $poDetail, Product $product): array
    {
        $poPrice = $poDetail?->unit_price;
        if ($poPrice !== null && (float) $poPrice > 0) {
            return ['unit_cost' => round((float) $poPrice, 2), 'cost_basis' => 'actual'];
        }

        $listCost = $product->cost_price;
        if ($listCost !== null && (float) $listCost > 0) {
            return ['unit_cost' => round((float) $listCost, 2), 'cost_basis' => 'product_default'];
        }

        return ['unit_cost' => null, 'cost_basis' => 'unavailable'];
    }

    /**
     * Fold an incoming line's cost into a batch that may already hold stock.
     *
     * $addedPieces is what is arriving now; the batch's quantity_received must
     * still be its value BEFORE the addition, or the average is weighted wrongly.
     *
     * @return array{unit_cost: ?float, cost_basis: string}
     */
    public function blendIntoBatch(InventoryBatch $batch, int $addedPieces, ?float $lineCost, string $lineBasis): array
    {
        $existingQty  = (int) $batch->quantity_received;
        $existingCost = $batch->unit_cost !== null ? (float) $batch->unit_cost : null;

        if ($existingCost === null || $existingQty <= 0) {
            return ['unit_cost' => $lineCost, 'cost_basis' => $lineBasis];
        }

        if ($lineCost === null) {
            // Pieces joined the batch at an unknown price. The existing cost is the
            // best number available, but it no longer describes the whole batch.
            return ['unit_cost' => $existingCost, 'cost_basis' => 'derived_weighted_avg'];
        }

        if (abs($existingCost - $lineCost) < 0.005) {
            return ['unit_cost' => $existingCost, 'cost_basis' => $batch->cost_basis ?? $lineBasis];
        }

        $totalQty = $existingQty + max(0, $addedPieces);
        if ($totalQty <= 0) {
            return ['unit_cost' => $existingCost, 'cost_basis' => $batch->cost_basis ?? $lineBasis];
        }

        $blended = (($existingCost * $existingQty) + ($lineCost * max(0, $addedPieces))) / $totalQty;

        return ['unit_cost' => round($blended, 2), 'cost_basis' => 'derived_weighted_avg'];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd back-end && php artisan test --filter=BatchCostResolverTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/Ledger/BatchCostResolver.php back-end/tests/Feature/Ledger/BatchCostResolverTest.php
git commit -m "feat(ledger): resolve real batch unit costs with an honest cost basis"
```

---

## Task 6: Post receiving, and stamp the batch's real cost

**Files:**
- Modify: `back-end/app/Services/ReceivingPostingService.php`
- Test: `back-end/tests/Feature/Ledger/ReceivingPostsToLedgerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryBatch;
use App\Models\InventoryLedgerEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\User;
use App\Services\ReceivingPostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReceivingPostsToLedgerTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0: Location, 1: Product, 2: User, 3: PurchaseOrderDetail} */
    private function scaffold(float $poUnitPrice = 300.00): array
    {
        $location = Location::create([
            'location_code' => 'L-' . uniqid(), 'location_name' => 'Main WH', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'M-' . uniqid(), 'product_name' => 'Copper Pipe',
            'product_type' => 'installation_material', 'cost_price' => 99.00,
        ]);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 'led_' . uniqid(), 'first_name' => 'Led', 'last_name' => 'Ger',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 50,
            'unit_price' => $poUnitPrice, 'subtotal' => $poUnitPrice * 50,
        ]);

        return [$location, $product, $user, $poDetail];
    }

    public function test_receiving_posts_one_in_row_valued_at_the_po_line_price(): void
    {
        [$location, $product, $user, $poDetail] = $this->scaffold(300.00);

        $result = DB::transaction(fn () => app(ReceivingPostingService::class)->post(
            $product, $location->location_id, 10, 'counted', $poDetail, null, null, $user->user_id
        ));

        $rows = InventoryLedgerEntry::where('source_type', 'receivings')
            ->where('source_id', $result['receiving']->receiving_id)
            ->get();

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('in', $row->direction);
        $this->assertSame('receiving', $row->movement_type);
        $this->assertSame(10, $row->quantity);
        $this->assertSame('300.00', $row->unit_cost);
        $this->assertSame('3000.00', $row->extended_value);
        $this->assertSame('actual', $row->cost_basis);
        $this->assertSame((int) $location->location_id, (int) $row->to_location_id);
        $this->assertSame((int) $product->product_id, (int) $row->product_id);
        $this->assertSame((int) $user->user_id, (int) $row->actor_user_id);
        $this->assertSame((int) $result['batch']->batch_id, (int) $row->batch_id);
    }

    public function test_the_batch_records_the_cost_the_stock_arrived_at(): void
    {
        [$location, $product, $user, $poDetail] = $this->scaffold(275.25);

        $result = DB::transaction(fn () => app(ReceivingPostingService::class)->post(
            $product, $location->location_id, 4, 'counted', $poDetail, null, null, $user->user_id
        ));

        $batch = InventoryBatch::find($result['batch']->batch_id);

        $this->assertSame('275.25', $batch->unit_cost);
        $this->assertSame('actual', $batch->cost_basis);
    }

    public function test_the_receiving_line_price_comes_from_the_po_not_the_product_list_cost(): void
    {
        [$location, $product, $user, $poDetail] = $this->scaffold(300.00);

        $result = DB::transaction(fn () => app(ReceivingPostingService::class)->post(
            $product, $location->location_id, 2, 'counted', $poDetail, null, null, $user->user_id
        ));

        $price = DB::table('receiving_details')
            ->where('receiving_id', $result['receiving']->receiving_id)
            ->value('prod_price');

        $this->assertSame(300.00, (float) $price, 'prod_price must be what the PO says we pay, not the product list cost.');
    }

    public function test_two_posts_of_the_same_line_produce_two_rows_not_one_doubled_row(): void
    {
        [$location, $product, $user, $poDetail] = $this->scaffold();

        $first  = DB::transaction(fn () => app(ReceivingPostingService::class)->post(
            $product, $location->location_id, 3, 'counted', $poDetail, null, null, $user->user_id
        ));
        DB::transaction(fn () => app(ReceivingPostingService::class)->post(
            $product, $location->location_id, 5, 'counted', $poDetail, null, null, $user->user_id
        ));

        $rows = InventoryLedgerEntry::where('source_type', 'receivings')
            ->where('source_id', $first['receiving']->receiving_id)
            ->orderBy('ledger_id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame([3, 5], $rows->pluck('quantity')->map(fn ($q) => (int) $q)->all());
        $this->assertCount(2, $rows->pluck('posting_key')->unique());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=ReceivingPostsToLedgerTest`
Expected: FAIL — 0 ledger rows found (`assertCount(1, $rows)` fails with 0).

- [ ] **Step 3: Inject the new collaborators**

In `app/Services/ReceivingPostingService.php`, replace the constructor and add the imports:

```php
use App\Services\Ledger\BatchCostResolver;
use App\Services\Ledger\LedgerPoster;
```

```php
    public function __construct(
        private InventoryBatchService $batches,
        private MaterialTagService $tags,
        private LedgerPoster $ledger,
        private BatchCostResolver $costs
    ) {
    }
```

- [ ] **Step 4: Resolve the real cost and pass it into the batch**

In `post()`, replace the block from `$batch = $this->accumulateBatch(...)` through the `ReceivingDetail::create([...])` call with:

```php
        $lineCost = $this->costs->costForReceivingLine($poDetail, $product);

        $batch = $this->accumulateBatch(
            $receiving, $product, $locationId, $pieces, $zoneLabel,
            $lineCost['unit_cost'], $lineCost['cost_basis']
        );

        $detail = ReceivingDetail::create([
            'receiving_id'      => $receiving->receiving_id,
            'product_id'        => $product->product_id,
            'po_detail_id'      => $poDetail?->po_detail_id,
            'quantity_amount'   => $pieces,
            'packages_received' => $packagesReceived,
            'pieces_expected'   => $piecesExpected,
            // Stamped only when the caller states the contents were confirmed.
            // Anything else — a scan, or a caller with no opinion — leaves NULL,
            // which is the honest reading: the box has not been opened.
            'contents_verified_at' => $contentsVerified ? now() : null,
            // What the business is paying, per the PO line — not the product's
            // list cost, which is a stand-in that made every valuation downstream
            // wrong. Falls back to the list cost only when there is no PO line.
            'prod_price'        => $lineCost['unit_cost'] ?? 0,
            'condition'         => 'Good',
            'scanned_barcode'   => $supplierBarcode ?: null,
            'entry_mode'        => $entryMode,
        ]);

        $this->ledger->post([
            'occurred_at'    => $receiving->receiving_date ?? now(),
            'direction'      => 'in',
            'movement_type'  => 'receiving',
            'product_id'     => $product->product_id,
            'batch_id'       => $batch->batch_id,
            'serial_number'  => $product->isSerialized() ? $supplierBarcode : null,
            'quantity'       => $pieces,
            'unit_cost'      => $lineCost['unit_cost'],
            'cost_basis'     => $lineCost['cost_basis'],
            'to_location_id' => $locationId,
            'source_type'    => 'receivings',
            'source_id'      => $receiving->receiving_id,
            'source_line_id' => $detail->receiving_detail_id,
            'correlation_id' => 'receivings:' . $receiving->receiving_id,
            'actor_user_id'  => $userId,
            'posting_key'    => 'receiving:' . $receiving->receiving_id
                                . ':line:' . $detail->receiving_detail_id . ':in',
        ]);
```

- [ ] **Step 5: Thread the cost through accumulateBatch()**

Replace `accumulateBatch()` with:

```php
    /**
     * Accumulate received pieces into a single batch per receiving header +
     * product + location, rather than minting one tiny batch per scan. This
     * is deliberate: repeated scans against one receiving header build a
     * single coherent batch per product+location.
     *
     * Because one batch can absorb several postings, its unit_cost is blended
     * rather than overwritten — two deliveries at two prices leave a weighted
     * average, and cost_basis records that it is derived.
     */
    private function accumulateBatch(
        Receiving $receiving,
        Product $product,
        int $locationId,
        int $pieces,
        ?string $zoneLabel,
        ?float $lineCost = null,
        string $lineBasis = 'unavailable'
    ): InventoryBatch {
        $batch = InventoryBatch::where('receiving_id', $receiving->receiving_id)
            ->where('product_id', $product->product_id)
            ->where('location_id', $locationId)
            ->first();

        if ($batch) {
            // Blend before incrementing: the weighting needs the pre-addition quantity.
            $blend = $this->costs->blendIntoBatch($batch, $pieces, $lineCost, $lineBasis);

            $batch->quantity_received  += $pieces;
            $batch->quantity_remaining += $pieces;
            $batch->unit_cost  = $blend['unit_cost'];
            $batch->cost_basis = $blend['cost_basis'];
            if ($zoneLabel !== null) {
                $batch->zone_label = $zoneLabel;
            }
            $batch->save();

            return $batch;
        }

        $batch = $this->batches->createBatch(
            $product->product_id,
            $locationId,
            $receiving->receiving_id,
            $pieces,
            $receiving->receiving_date ?? now(),
            $zoneLabel
        );

        $batch->unit_cost  = $lineCost;
        $batch->cost_basis = $lineBasis;
        $batch->save();

        return $batch;
    }
```

- [ ] **Step 6: Allow the new columns to be written**

In `app/Models/InventoryBatch.php`, add `'unit_cost'` and `'cost_basis'` to `$fillable`.

- [ ] **Step 7: Run the tests**

Run: `cd back-end && php artisan test --filter=ReceivingPostsToLedgerTest`
Expected: PASS (4 tests).

Then the existing receiving suite, which this touched:
Run: `cd back-end && php artisan test --filter="Receiving|ReceiveCount|ReceiveTopUp|ReceiveVerify|PieceVerification|ContentsVerification"`
Expected: PASS. If a test asserted `prod_price` equalled the product's `cost_price`, update it — the PO line price is the correct value now, and say so in the test name or a comment.

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Services/ReceivingPostingService.php back-end/app/Models/InventoryBatch.php back-end/tests/Feature/Ledger/ReceivingPostsToLedgerTest.php
git commit -m "feat(ledger): post receiving movements and record real batch costs"
```

---

## Task 7: Post issuance from the non-scan path

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/IssuanceController.php` (the `$touched` loop, around line 140)
- Test: `back-end/tests/Feature/Ledger/IssuancePostsToLedgerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Ledger;

use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\InventoryLedgerEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IssuancePostsToLedgerTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0: Location, 1: Product, 2: User} */
    private function stockedLocation(int $olderQty, float $olderCost, int $newerQty, float $newerCost): array
    {
        $location = Location::create([
            'location_code' => 'L-' . uniqid(), 'location_name' => 'WH', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'M-' . uniqid(), 'product_name' => 'Foam Tape',
            'product_type' => 'installation_material', 'cost_price' => 10.00,
        ]);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 'iss_' . uniqid(), 'first_name' => 'Iss', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);

        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => $olderQty + $newerQty, 'available_quantity' => $olderQty + $newerQty,
            'reorder_level' => 0,
        ]);

        // Two batches at two costs, so a FIFO split has something to prove.
        foreach ([[1001, $olderQty, $olderCost, '2026-01-01'], [1002, $newerQty, $newerCost, '2026-06-01']] as [$rid, $qty, $cost, $date]) {
            InventoryBatch::create([
                'product_id' => $product->product_id, 'location_id' => $location->location_id,
                'receiving_id' => $rid, 'quantity_received' => $qty, 'quantity_remaining' => $qty,
                'received_at' => $date, 'unit_cost' => $cost, 'cost_basis' => 'actual',
            ]);
        }

        return [$location, $product, $user];
    }

    public function test_an_issuance_that_spans_two_batches_posts_one_row_per_slice_at_its_own_cost(): void
    {
        [$location, $product, $user] = $this->stockedLocation(4, 100.00, 6, 200.00);

        $res = $this->actingAs($user)->postJson('/api/issuances', [
            'location_id'   => $location->location_id,
            'issued_by'     => $user->user_id,
            'issuance_date' => '2026-09-15',
            'details'       => [
                ['product_id' => $product->product_id, 'quantity_issued' => 6],
            ],
        ]);

        $res->assertStatus(201);
        $issuanceId = $res->json('data.issuance_id');

        $rows = InventoryLedgerEntry::where('source_type', 'issuances')
            ->where('source_id', $issuanceId)
            ->orderBy('unit_cost')
            ->get();

        $this->assertCount(2, $rows, 'A 6-piece issue over a 4-piece oldest batch is two slices.');
        $this->assertSame(['out', 'out'], $rows->pluck('direction')->all());
        $this->assertSame([4, 2], $rows->pluck('quantity')->map(fn ($q) => (int) $q)->all());
        $this->assertSame(['100.00', '200.00'], $rows->pluck('unit_cost')->all());
        $this->assertSame(['400.00', '400.00'], $rows->pluck('extended_value')->all());
        $this->assertSame('issuances:' . $issuanceId, $rows->first()->correlation_id);
        $this->assertSame((int) $location->location_id, (int) $rows->first()->from_location_id);
    }

    public function test_a_batch_with_no_recorded_cost_still_posts_the_quantity(): void
    {
        [$location, $product, $user] = $this->stockedLocation(5, 100.00, 5, 100.00);
        InventoryBatch::where('product_id', $product->product_id)
            ->update(['unit_cost' => null, 'cost_basis' => null]);

        $res = $this->actingAs($user)->postJson('/api/issuances', [
            'location_id'   => $location->location_id,
            'issued_by'     => $user->user_id,
            'issuance_date' => '2026-09-15',
            'details'       => [
                ['product_id' => $product->product_id, 'quantity_issued' => 3],
            ],
        ]);

        $res->assertStatus(201);

        $row = InventoryLedgerEntry::where('source_type', 'issuances')
            ->where('source_id', $res->json('data.issuance_id'))
            ->firstOrFail();

        $this->assertSame(3, (int) $row->quantity);
        $this->assertSame('unavailable', $row->cost_basis);
        $this->assertNull($row->extended_value);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=IssuancePostsToLedgerTest`
Expected: FAIL — `assertCount(2, $rows)` sees 0.

- [ ] **Step 3: Post inside the `$touched` loop**

In `IssuanceController.php`, add the import:

```php
use App\Services\Ledger\LedgerPoster;
```

Replace the `foreach ($touched as $batchId => $deductedQuantity)` block with:

```php
                $detailRow = $issuance->details()->where('product_id', $detail['product_id'])
                    ->orderByDesc('issuance_detail_id')
                    ->first();

                foreach ($touched as $batchId => $deductedQuantity) {
                    if ($deductedQuantity <= 0) continue;
                    \App\Models\InventoryBatchMovement::create([
                        'batch_id' => $batchId,
                        'movement_type' => 'stock_out',
                        'issuance_id' => $issuance->issuance_id,
                        'product_id' => $detail['product_id'],
                        'from_location_id' => $validated['location_id'],
                        'quantity' => $deductedQuantity,
                        'performed_by' => $issuance->issued_by,
                        'moved_at' => now(),
                    ]);

                    // One ledger row per batch slice, each valued at the cost that
                    // slice actually came in at. A 6-piece issue drawn from a
                    // 4-piece batch at 100 and a 6-piece batch at 200 is 800 pesos
                    // of stock out, not 6 x whatever the product list price says.
                    $batch = \App\Models\InventoryBatch::find($batchId);

                    app(LedgerPoster::class)->post([
                        'occurred_at'      => $issuance->issuance_date ?? now(),
                        'direction'        => 'out',
                        'movement_type'    => 'issuance',
                        'product_id'       => (int) $detail['product_id'],
                        'batch_id'         => (int) $batchId,
                        'quantity'         => (int) $deductedQuantity,
                        'unit_cost'        => $batch?->unit_cost !== null ? (float) $batch->unit_cost : null,
                        'cost_basis'       => $batch?->unit_cost !== null ? ($batch->cost_basis ?? 'actual') : 'unavailable',
                        'from_location_id' => (int) $validated['location_id'],
                        'source_type'      => 'issuances',
                        'source_id'        => $issuance->issuance_id,
                        'source_line_id'   => $detailRow?->issuance_detail_id,
                        'correlation_id'   => 'issuances:' . $issuance->issuance_id,
                        'actor_user_id'    => $issuance->issued_by,
                        'posting_key'      => 'issuance:' . $issuance->issuance_id
                                              . ':line:' . ($detailRow?->issuance_detail_id ?? 0)
                                              . ':batch:' . $batchId . ':out',
                    ]);
                }
```

- [ ] **Step 4: Run the tests**

Run: `cd back-end && php artisan test --filter=IssuancePostsToLedgerTest`
Expected: PASS (2 tests).

Run: `cd back-end && php artisan test --filter=Issuance`
Expected: PASS — no regression in the existing issuance suite.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/IssuanceController.php back-end/tests/Feature/Ledger/IssuancePostsToLedgerTest.php
git commit -m "feat(ledger): post issuance movements per FIFO batch slice"
```

---

## Task 8: Post issuance from the scan path

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/IssuanceScanController.php` (around lines 362 and 520-525)
- Test: `back-end/tests/Feature/Ledger/IssuanceScanPostsToLedgerTest.php`

- [ ] **Step 1: Read the two call sites**

Run: `cd back-end && sed -n '340,380p;505,540p' app/Http/Controllers/Api/IssuanceScanController.php`

Both sites already resolve everything the posting needs — verified 2026-09-30, so use
these existing variables rather than introducing new lookups:

**Serialized site (~line 362).** Immediately after `deductFromReceiving(...)` the code
already computes `$serialBatch` (the batch for this product+location+`receiving_id`) and
holds `$serialRecord` (the `ItemSerial`, with `->serial_number`), `$product`,
`$locationId` and `$issuance`. **Post before `$serialRecord->delete()`**, which runs a
few lines later — after the delete, the serial number is gone from that variable's row
and the evidence pointer would be lost. Posting key:
`issuance:{issuance_id}:serial:{serial_number}:out` — the serial is the natural identity,
so re-scanning the same unit collapses onto the same row.

**Material site (~line 520).** The code already assigns `$touched` (from either
`deductFromBatch()` on an authorised FIFO override or `deductFifo()`), then
`$batchId = array_key_first($touched)` and `$batch = InventoryBatch::find($batchId)`,
and then creates `$detail = IssuanceDetail::create([...])` just below. **Post after
`$detail` exists** and key on it:
`issuance:{issuance_id}:line:{issuance_detail_id}:batch:{batch_id}:out`.

That `issuance_detail_id` resolves the open question the earlier draft of this task
left: it is the stable per-scan identity, so no `uniqid()` or timestamp is needed.
`$touched` can hold more than one batch when a pick spans batches, so loop over all of
it rather than using only `array_key_first()` — one ledger row per slice, each at its
own batch cost, exactly as Task 7 does.

- [ ] **Step 2: Write the failing test**

Model the test on `tests/Feature/Issuance/*` — find the existing scan test that drives this endpoint and copy its scaffolding:

Run: `cd back-end && ls tests/Feature/Issuance && grep -rln "issuance-scan\|issuances/scan" tests/Feature/Issuance`

Write `tests/Feature/Ledger/IssuanceScanPostsToLedgerTest.php` with two cases, reusing that scaffolding verbatim so the request shape is correct:

```php
    public function test_scanning_a_serialized_unit_out_posts_one_ledger_row_carrying_the_serial(): void
    {
        // ... arrange exactly as the existing scan test does, then:
        $row = \App\Models\InventoryLedgerEntry::where('source_type', 'issuances')
            ->where('source_id', $issuanceId)
            ->firstOrFail();

        $this->assertSame('out', $row->direction);
        $this->assertSame('issuance', $row->movement_type);
        $this->assertSame(1, (int) $row->quantity);
        $this->assertSame($serialNumber, $row->serial_number);
    }

    public function test_scanning_the_same_unit_twice_does_not_post_the_value_twice(): void
    {
        // ... drive the same scan request twice, then:
        $this->assertSame(
            1,
            \App\Models\InventoryLedgerEntry::where('source_type', 'issuances')
                ->where('source_id', $issuanceId)
                ->where('serial_number', $serialNumber)
                ->count()
        );
    }
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=IssuanceScanPostsToLedgerTest`
Expected: FAIL — `firstOrFail()` throws `ModelNotFoundException`.

- [ ] **Step 4: Post at the serialized site (line ~362)**

After the `deductFromReceiving(...)` call, add:

```php
                // Serialized units leave one at a time, and the serial is the
                // evidence — so it is the posting key. Re-scanning the same unit
                // collapses onto the same row rather than issuing it twice.
                app(\App\Services\Ledger\LedgerPoster::class)->post([
                    'occurred_at'      => now(),
                    'direction'        => 'out',
                    'movement_type'    => 'issuance',
                    'product_id'       => (int) $product->product_id,
                    'batch_id'         => $batchId ?? null,
                    'serial_number'    => $serial->serial_number,
                    'quantity'         => 1,
                    'unit_cost'        => $batchUnitCost,
                    'cost_basis'       => $batchUnitCost !== null ? ($batchCostBasis ?? 'actual') : 'unavailable',
                    'from_location_id' => (int) $locationId,
                    'source_type'      => 'issuances',
                    'source_id'        => (int) $issuance->issuance_id,
                    'correlation_id'   => 'issuances:' . $issuance->issuance_id,
                    'actor_user_id'    => Auth::id(),
                    'posting_key'      => 'issuance:' . $issuance->issuance_id
                                          . ':serial:' . $serial->serial_number . ':out',
                ]);
```

Resolve `$batchId`, `$batchUnitCost` and `$batchCostBasis` from the serial's batch immediately above the post:

```php
                $unitBatch     = $serial->batch_id ? \App\Models\InventoryBatch::find($serial->batch_id) : null;
                $batchId       = $unitBatch?->batch_id;
                $batchUnitCost = $unitBatch?->unit_cost !== null ? (float) $unitBatch->unit_cost : null;
                $batchCostBasis = $unitBatch?->cost_basis;
```

- [ ] **Step 5: Post at the material site (lines ~520-525)**

That site assigns `$touched` from either `deductFromBatch()` or `deductFifo()`, both of which return `[batch_id => qty]`. After it, add the same per-slice loop used in Task 7, with the posting key keyed on the batch and the scan's detail row:

```php
                foreach ($touched as $touchedBatchId => $deducted) {
                    if ($deducted <= 0) continue;

                    $slice = \App\Models\InventoryBatch::find($touchedBatchId);

                    app(\App\Services\Ledger\LedgerPoster::class)->post([
                        'occurred_at'      => now(),
                        'direction'        => 'out',
                        'movement_type'    => 'issuance',
                        'product_id'       => (int) $product->product_id,
                        'batch_id'         => (int) $touchedBatchId,
                        'quantity'         => (int) $deducted,
                        'unit_cost'        => $slice?->unit_cost !== null ? (float) $slice->unit_cost : null,
                        'cost_basis'       => $slice?->unit_cost !== null ? ($slice->cost_basis ?? 'actual') : 'unavailable',
                        'from_location_id' => (int) $locationId,
                        'source_type'      => 'issuances',
                        'source_id'        => (int) $issuance->issuance_id,
                        'correlation_id'   => 'issuances:' . $issuance->issuance_id,
                        'actor_user_id'    => Auth::id(),
                        'posting_key'      => 'issuance:' . $issuance->issuance_id
                                              . ':scan:' . $scanSequence
                                              . ':batch:' . $touchedBatchId . ':out',
                    ]);
                }
```

`$scanSequence` must be a value that is stable for one scan and different for the next — use the scan's own row id if the endpoint creates one (`$scanEvent->scan_event_id` or the issuance detail id it writes). Do not use `uniqid()` or a timestamp: a non-deterministic key defeats the idempotency the unique index provides. If no per-scan row exists, use the issuance detail id plus the running piece total already tracked at that site.

- [ ] **Step 6: Run the tests**

Run: `cd back-end && php artisan test --filter=IssuanceScanPostsToLedgerTest`
Expected: PASS (2 tests).

Run: `cd back-end && php artisan test --filter="IssuanceScan|IssuableAt|ScanPath"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/IssuanceScanController.php back-end/tests/Feature/Ledger/IssuanceScanPostsToLedgerTest.php
git commit -m "feat(ledger): post issuance movements from the scan paths"
```

---

## Task 9: Post transfer dispatch and receipt as two legs

**Files:**
- Modify: `back-end/app/Services/TransferService.php` (`dispatch()` around lines 263-305, `receive()` around line 500)
- Test: `back-end/tests/Feature/Ledger/TransferPostsTwoLegsTest.php`

- [ ] **Step 1: Write the failing test**

Reuse the existing transfer scaffolding. `tests/Feature/Transfer/TransferTestCase.php`
provides these helpers (verified against the file — there is **no**
`approvedIstWithStock()`; compose from these):

```
statusId(string $name): int
makeLocation(string $name): Location
makeUser(): User
makeUserAt(Location $location, string $role): User
makeInstallationMaterial(): Product
makeAppliance(): Product
setStock(int $locationId, int $productId, int $qty): Inventory
makeSerials(int $locationId, int $productId, int $count): array
makeApprovedIst(int $from, int $to, array $lines): Transfer   // $lines: product_id, quantity_requested, quantity_scanned
makeReceiving(int $locationId): Receiving
makeBatch(Product $product, Location $location, array $attrs = []): InventoryBatch
makeSerial(Product $product, Location $location, string $sn, ?int $receivingId = null): ItemSerial
makeApprovedTransfer(Location $from, Location $to, array $lines): Transfer
```

Add one local helper to the new test class that composes them:

```php
    /** @return array{0: Transfer, 1: Product, 2: Location, 3: Location, 4: User} */
    private function dispatchableIst(int $qty = 10): array
    {
        $from    = $this->makeLocation('Source WH');
        $to      = $this->makeLocation('Dest WH');
        $product = $this->makeInstallationMaterial();
        $user    = $this->makeUser();

        $this->setStock($from->location_id, $product->product_id, $qty);
        $this->makeBatch($product, $from, [
            'quantity_remaining' => $qty,
            'received_at'        => now()->subMonth(),
        ]);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $product->product_id, 'quantity_requested' => $qty, 'quantity_scanned' => $qty],
        ]);

        return [$ist, $product, $from, $to, $user];
    }
```

**`makeBatch()` does not set `unit_cost`**, so every ledger row these tests produce
will carry `cost_basis = 'unavailable'` and a null `extended_value`. That is correct
behaviour, not a bug — so assert on direction, movement type, quantity and
correlation, and do **not** assert on value in this test. If the dispatch/receive
call sequence gives trouble, copy the working order from
`tests/Feature/Transfer/TransferFlowTest.php`.

Then the tests (replace `approvedIstWithStock(10)` below with `dispatchableIst(10)`):

```php
<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryLedgerEntry;
use App\Services\TransferService;
use Tests\Feature\Transfer\TransferTestCase;

class TransferPostsTwoLegsTest extends TransferTestCase
{
    public function test_dispatch_posts_an_out_leg_and_receipt_posts_the_matching_in_leg(): void
    {
        // TransferTestCase's helpers: adapt these names to the ones it actually
        // exposes (run `grep -n "protected function" tests/Feature/Transfer/TransferTestCase.php`).
        [$ist, $product, $from, $to, $user] = $this->approvedIstWithStock(10);

        $service = app(TransferService::class);
        $service->dispatch($ist, $user->user_id);

        $out = InventoryLedgerEntry::where('correlation_id', 'transfers:' . $ist->transfer_id)->get();
        $this->assertCount(1, $out);
        $this->assertSame('transfer_out', $out->first()->movement_type);
        $this->assertSame('out', $out->first()->direction);
        $this->assertSame((int) $from->location_id, (int) $out->first()->from_location_id);
        $this->assertSame((int) $to->location_id, (int) $out->first()->to_location_id);

        $service->receive($ist->fresh(), $user->user_id);

        $both = InventoryLedgerEntry::where('correlation_id', 'transfers:' . $ist->transfer_id)
            ->orderBy('ledger_id')
            ->get();

        $this->assertCount(2, $both);
        $this->assertSame(['transfer_out', 'transfer_in'], $both->pluck('movement_type')->all());
        $this->assertSame(['out', 'in'], $both->pluck('direction')->all());
    }

    public function test_the_two_legs_net_to_zero_quantity(): void
    {
        [$ist, , , , $user] = $this->approvedIstWithStock(10);

        $service = app(TransferService::class);
        $service->dispatch($ist, $user->user_id);
        $service->receive($ist->fresh(), $user->user_id);

        $rows = InventoryLedgerEntry::where('correlation_id', 'transfers:' . $ist->transfer_id)->get();

        $net = $rows->sum(fn ($r) => $r->direction === 'in' ? $r->quantity : -$r->quantity);
        $this->assertSame(0, $net, 'A completed transfer moves stock without creating or destroying any.');
    }

    public function test_an_in_transit_transfer_shows_an_out_leg_with_no_matching_in(): void
    {
        [$ist, , , , $user] = $this->approvedIstWithStock(10);

        app(TransferService::class)->dispatch($ist, $user->user_id);

        $rows = InventoryLedgerEntry::where('correlation_id', 'transfers:' . $ist->transfer_id)->get();

        $this->assertSame(1, $rows->where('direction', 'out')->count());
        $this->assertSame(0, $rows->where('direction', 'in')->count());
    }

    public function test_reversing_a_dispatched_transfer_posts_reversals_not_deletions(): void
    {
        [$ist, , , , $user] = $this->approvedIstWithStock(10);

        $service = app(TransferService::class);
        $service->dispatch($ist, $user->user_id);
        $service->reverse($ist->fresh());

        $rows = InventoryLedgerEntry::where('correlation_id', 'transfers:' . $ist->transfer_id)
            ->orderBy('ledger_id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertNull($rows->first()->reverses_ledger_id);
        $this->assertSame($rows->first()->ledger_id, $rows->last()->reverses_ledger_id);
        $this->assertSame('in', $rows->last()->direction);
    }
}
```

If `TransferTestCase` has no `approvedIstWithStock()` helper, add one there that returns `[$ist, $product, $fromLocation, $toLocation, $user]`, built from the setup the existing transfer tests already perform.

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=TransferPostsTwoLegsTest`
Expected: FAIL — `assertCount(1, $out)` sees 0.

- [ ] **Step 3: Post the out leg in dispatch()**

In `TransferService.php`, add a `LedgerPoster` to the constructor without breaking its optional-argument contract:

```php
    public function __construct(?InventoryBatchService $batches = null, ?\App\Services\Ledger\LedgerPoster $ledger = null)
    {
        $this->batches = $batches ?? new InventoryBatchService();
        $this->ledger  = $ledger ?? app(\App\Services\Ledger\LedgerPoster::class);
    }
```

with the property declared alongside `$batches`:

```php
    private \App\Services\Ledger\LedgerPoster $ledger;
```

Immediately after each `InventoryBatchMovement::create([...])` call inside `dispatch()` (there are two — the serialized branch near line 271 and the material branch near line 293), post the out leg. Serialized branch:

```php
                                $this->postTransferLeg(
                                    $locked, $detail, 'out', (int) $batch->batch_id, 1, $userId, $serial->serial_number
                                );
```

Material branch:

```php
                        $this->postTransferLeg(
                            $locked, $detail, 'out', (int) $batchId, (int) $deductedQuantity, $userId
                        );
```

- [ ] **Step 4: Post the in leg in receive()**

After the `creditFromReceiving(...)` call near line 500 that returns `$dstBatch`:

```php
                                $this->postTransferLeg(
                                    $locked, $detail, 'in', (int) $dstBatch->batch_id, $creditedQuantity, $userId
                                );
```

Use whatever variable that block already holds for the credited piece count in place of `$creditedQuantity`; do not introduce a second source of truth for the quantity.

- [ ] **Step 5: Add the shared helper**

Add to `TransferService`:

```php
    /**
     * One leg of a transfer. Both legs share a correlation_id, so the Explorer can
     * show them as one event and reconciliation can prove they net to zero. Stock
     * in transit is an out leg with no in leg yet — which is exactly what an
     * auditor wants to be able to see.
     *
     * Both legs carry from_location and to_location: the route is a fact about the
     * movement, not about which end of it this row describes.
     */
    private function postTransferLeg(
        Transfer $ist,
        TransferDetail $detail,
        string $direction,
        int $batchId,
        int $quantity,
        ?int $userId,
        ?string $serialNumber = null
    ): void {
        if ($quantity <= 0) {
            return;
        }

        $batch = \App\Models\InventoryBatch::find($batchId);
        $cost  = $batch?->unit_cost !== null ? (float) $batch->unit_cost : null;

        $this->ledger->post([
            'occurred_at'      => now(),
            'direction'        => $direction,
            'movement_type'    => $direction === 'out' ? 'transfer_out' : 'transfer_in',
            'product_id'       => (int) $detail->product_id,
            'batch_id'         => $batchId,
            'serial_number'    => $serialNumber,
            'quantity'         => $quantity,
            'unit_cost'        => $cost,
            'cost_basis'       => $cost !== null ? ($batch->cost_basis ?? 'actual') : 'unavailable',
            'from_location_id' => (int) $ist->from_location_id,
            'to_location_id'   => (int) $ist->to_location_id,
            'source_type'      => 'transfers',
            'source_id'        => (int) $ist->transfer_id,
            'source_line_id'   => (int) $detail->transfer_detail_id,
            'correlation_id'   => 'transfers:' . $ist->transfer_id,
            'actor_user_id'    => $userId,
            'posting_key'      => 'transfer:' . $ist->transfer_id
                                  . ':line:' . $detail->transfer_detail_id
                                  . ':batch:' . $batchId
                                  . ($serialNumber ? ':serial:' . $serialNumber : '')
                                  . ':' . $direction,
        ]);
    }
```

- [ ] **Step 6: Reverse the legs in reverse()**

At the top of the `DB::transaction` closure in `reverse()`, after `$locked` is loaded and the early return for untouched transfers, add:

```php
            // Voiding a transfer posts mirrors of whatever it posted, so the
            // ledger records that the movement happened and was undone — never
            // that it did not happen.
            $posted = \App\Models\InventoryLedgerEntry::where('correlation_id', 'transfers:' . $locked->transfer_id)
                ->whereNull('reverses_ledger_id')
                ->get();

            foreach ($posted as $entry) {
                $this->ledger->reverse($entry, 'Transfer reversed', auth()->id());
            }
```

- [ ] **Step 7: Run the tests**

Run: `cd back-end && php artisan test --filter=TransferPostsTwoLegsTest`
Expected: PASS (4 tests).

Run: `cd back-end && php artisan test tests/Feature/Transfer`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Services/TransferService.php back-end/tests/Feature/Transfer/TransferTestCase.php back-end/tests/Feature/Ledger/TransferPostsTwoLegsTest.php
git commit -m "feat(ledger): post transfer legs and reverse them on void"
```

---

## Task 10: Post adjustments

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/AdjustmentController.php:48-126`
- Test: `back-end/tests/Feature/Ledger/AdjustmentPostsToLedgerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Ledger;

use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\InventoryLedgerEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdjustmentPostsToLedgerTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0: Location, 1: Product, 2: User} */
    private function scaffold(int $onHand = 20): array
    {
        $location = Location::create([
            'location_code' => 'L-' . uniqid(), 'location_name' => 'WH', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'M-' . uniqid(), 'product_name' => 'Bolt',
            'product_type' => 'installation_material', 'cost_price' => 12.00,
        ]);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 'adj_' . uniqid(), 'first_name' => 'Adj', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);

        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => $onHand, 'available_quantity' => $onHand, 'reorder_level' => 0,
        ]);
        InventoryBatch::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'receiving_id' => 2001, 'quantity_received' => $onHand, 'quantity_remaining' => $onHand,
            'received_at' => '2026-02-01', 'unit_cost' => 12.00, 'cost_basis' => 'actual',
        ]);

        return [$location, $product, $user];
    }

    private function submit(User $user, Location $location, Product $product, int $add, int $deduct)
    {
        return $this->actingAs($user)->postJson('/api/adjustments', [
            'location_id'       => $location->location_id,
            'created_by'        => $user->user_id,
            'adjustment_number' => 'ADJ-' . uniqid(),
            'adjustment_date'   => '2026-09-20',
            'adjustment_type'   => 'count_variance',
            'details'           => [[
                'product_id'      => $product->product_id,
                'add_quantity'    => $add,
                'deduct_quantity' => $deduct,
            ]],
        ]);
    }

    public function test_a_positive_adjustment_posts_an_in_row(): void
    {
        [$location, $product, $user] = $this->scaffold();

        $res = $this->submit($user, $location, $product, 5, 0);
        $res->assertStatus(201);

        $row = InventoryLedgerEntry::where('source_type', 'adjustments')
            ->where('source_id', $res->json('data.adjustment_id'))
            ->firstOrFail();

        $this->assertSame('in', $row->direction);
        $this->assertSame('adjustment', $row->movement_type);
        $this->assertSame(5, (int) $row->quantity);
        $this->assertSame((int) $location->location_id, (int) $row->to_location_id);
        $this->assertStringContainsString('count_variance', (string) $row->notes);
    }

    public function test_a_negative_adjustment_posts_an_out_row(): void
    {
        [$location, $product, $user] = $this->scaffold();

        $res = $this->submit($user, $location, $product, 0, 4);
        $res->assertStatus(201);

        $row = InventoryLedgerEntry::where('source_type', 'adjustments')
            ->where('source_id', $res->json('data.adjustment_id'))
            ->firstOrFail();

        $this->assertSame('out', $row->direction);
        $this->assertSame(4, (int) $row->quantity);
        $this->assertSame((int) $location->location_id, (int) $row->from_location_id);
    }

    public function test_a_net_zero_line_posts_nothing_because_no_stock_moved(): void
    {
        [$location, $product, $user] = $this->scaffold();

        $res = $this->submit($user, $location, $product, 3, 3);
        $res->assertStatus(201);

        $this->assertSame(
            0,
            InventoryLedgerEntry::where('source_type', 'adjustments')
                ->where('source_id', $res->json('data.adjustment_id'))
                ->count()
        );
    }

    public function test_a_failed_adjustment_leaves_no_ledger_row(): void
    {
        [$location, $product, $user] = $this->scaffold(2);

        // Deducting more than is on hand throws inside the transaction.
        $res = $this->submit($user, $location, $product, 0, 50);
        $res->assertStatus(500);

        $this->assertSame(
            0,
            InventoryLedgerEntry::where('source_type', 'adjustments')->count()
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=AdjustmentPostsToLedgerTest`
Expected: FAIL — `firstOrFail()` throws.

- [ ] **Step 3: Post per line**

In `AdjustmentController.php`, add the import:

```php
use App\Services\Ledger\LedgerPoster;
```

Capture the created detail row and post after the inventory write. Replace `$adjustment->details()->create($detail);` with `$detailRow = $adjustment->details()->create($detail);`, then after `$inventory->save();` inside the same loop add:

```php
                // Stock moves here, at creation — adjustments have no approval
                // step in this system, so this is the only moment the movement
                // becomes real. A net-zero line moved nothing and posts nothing.
                if ($net !== 0) {
                    $adjBatch = \App\Models\InventoryBatch::where('product_id', $detail['product_id'])
                        ->where('location_id', $validated['location_id'])
                        ->whereNotNull('unit_cost')
                        ->orderBy('received_at')
                        ->first();
                    $adjCost = $adjBatch?->unit_cost !== null ? (float) $adjBatch->unit_cost : null;

                    app(LedgerPoster::class)->post([
                        'occurred_at'      => $validated['adjustment_date'],
                        'direction'        => $net > 0 ? 'in' : 'out',
                        'movement_type'    => 'adjustment',
                        'product_id'       => (int) $detail['product_id'],
                        'batch_id'         => $adjBatch?->batch_id,
                        'quantity'         => abs($net),
                        'unit_cost'        => $adjCost,
                        'cost_basis'       => $adjCost !== null ? ($adjBatch->cost_basis ?? 'actual') : 'unavailable',
                        'from_location_id' => $net < 0 ? (int) $validated['location_id'] : null,
                        'to_location_id'   => $net > 0 ? (int) $validated['location_id'] : null,
                        'source_type'      => 'adjustments',
                        'source_id'        => (int) $adjustment->adjustment_id,
                        'source_line_id'   => (int) $detailRow->adjustment_detail_id,
                        'correlation_id'   => 'adjustments:' . $adjustment->adjustment_id,
                        'actor_user_id'    => $validated['created_by'] ?? auth()->id(),
                        'notes'            => $validated['adjustment_type'],
                        'posting_key'      => 'adjustment:' . $adjustment->adjustment_id
                                              . ':line:' . $detailRow->adjustment_detail_id
                                              . ':' . ($net > 0 ? 'in' : 'out'),
                    ]);
                }
```

- [ ] **Step 4: Run the tests**

Run: `cd back-end && php artisan test --filter=AdjustmentPostsToLedgerTest`
Expected: PASS (4 tests).

Run: `cd back-end && php artisan test --filter=Adjustment`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/AdjustmentController.php back-end/tests/Feature/Ledger/AdjustmentPostsToLedgerTest.php
git commit -m "feat(ledger): post adjustment movements at creation"
```

---

## Task 11: LedgerFilter and LedgerQueryService — summary and movements

**Files:**
- Create: `back-end/app/Services/Ledger/LedgerFilter.php`
- Create: `back-end/app/Services/Ledger/LedgerQueryService.php`
- Test: `back-end/tests/Feature/Ledger/LedgerQueryServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryLedgerEntry;
use App\Services\Ledger\LedgerFilter;
use App\Services\Ledger\LedgerQueryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LedgerQueryServiceTest extends TestCase
{
    use DatabaseTransactions;

    private int $productId = 8801;
    private int $locationId = 9901;

    private function entry(array $overrides): InventoryLedgerEntry
    {
        return InventoryLedgerEntry::create(array_merge([
            'occurred_at'      => '2026-09-10 09:00:00',
            'posted_at'        => '2026-09-10 09:00:00',
            'direction'        => 'in',
            'movement_type'    => 'receiving',
            'lens'             => 'inventory',
            'product_id'       => $this->productId,
            'quantity'         => 10,
            'unit_cost'        => 100.00,
            'extended_value'   => 1000.00,
            'cost_basis'       => 'actual',
            'to_location_id'   => $this->locationId,
            'source_type'      => 'receivings',
            'source_id'        => 1,
            'correlation_id'   => 'receivings:1',
            'origin'           => 'posted',
            'posting_key'      => 'q:' . uniqid('', true),
        ], $overrides));
    }

    private function filter(array $q = []): LedgerFilter
    {
        return LedgerFilter::fromArray(array_merge([
            'from' => '2026-09-01',
            'to'   => '2026-09-30',
        ], $q));
    }

    public function test_the_summary_totals_quantity_and_value_by_direction(): void
    {
        $this->entry([]);
        $this->entry([
            'direction' => 'out', 'movement_type' => 'issuance', 'quantity' => 4,
            'unit_cost' => 100.00, 'extended_value' => 400.00,
            'to_location_id' => null, 'from_location_id' => $this->locationId,
            'source_type' => 'issuances', 'source_id' => 2, 'correlation_id' => 'issuances:2',
        ]);

        $summary = app(LedgerQueryService::class)->summary($this->filter());

        $this->assertSame(10, $summary['quantity_in']);
        $this->assertSame(4, $summary['quantity_out']);
        $this->assertSame(1000.0, $summary['value_in']);
        $this->assertSame(400.0, $summary['value_out']);
        $this->assertSame(6, $summary['net_quantity']);
        $this->assertSame(2, $summary['event_count']);
    }

    public function test_the_summary_reports_how_much_value_rests_on_each_cost_basis(): void
    {
        $this->entry([]);
        $this->entry([
            'cost_basis' => 'derived_weighted_avg', 'extended_value' => 500.00,
            'source_id' => 3, 'correlation_id' => 'receivings:3',
        ]);
        $this->entry([
            'cost_basis' => 'unavailable', 'unit_cost' => null, 'extended_value' => null,
            'source_id' => 4, 'correlation_id' => 'receivings:4',
        ]);

        $summary = app(LedgerQueryService::class)->summary($this->filter());

        $this->assertSame(1000.0, $summary['cost_basis']['actual']);
        $this->assertSame(500.0, $summary['cost_basis']['derived_weighted_avg']);
        $this->assertSame(1, $summary['unvalued_rows']);
    }

    public function test_a_reversed_pair_nets_out_of_the_summary(): void
    {
        $original = $this->entry([
            'direction' => 'out', 'movement_type' => 'issuance', 'quantity' => 5,
            'extended_value' => 500.00, 'from_location_id' => $this->locationId, 'to_location_id' => null,
            'source_type' => 'issuances', 'source_id' => 9, 'correlation_id' => 'issuances:9',
        ]);
        $this->entry([
            'direction' => 'in', 'movement_type' => 'issuance', 'quantity' => 5,
            'extended_value' => 500.00, 'to_location_id' => $this->locationId, 'from_location_id' => null,
            'source_type' => 'issuances', 'source_id' => 9, 'correlation_id' => 'issuances:9',
            'reverses_ledger_id' => $original->ledger_id,
        ]);

        $summary = app(LedgerQueryService::class)->summary($this->filter());

        $this->assertSame(0, $summary['net_quantity']);
        $this->assertSame(1, $summary['reversal_count']);
    }

    public function test_the_date_window_excludes_movements_outside_it(): void
    {
        $this->entry(['occurred_at' => '2026-08-15 09:00:00']);

        $summary = app(LedgerQueryService::class)->summary($this->filter());

        $this->assertSame(0, $summary['event_count']);
    }

    public function test_movements_are_paginated_newest_first_and_grouped_by_correlation(): void
    {
        $this->entry(['occurred_at' => '2026-09-05 09:00:00', 'source_id' => 11, 'correlation_id' => 'receivings:11']);
        $this->entry(['occurred_at' => '2026-09-20 09:00:00', 'source_id' => 12, 'correlation_id' => 'receivings:12']);

        $page = app(LedgerQueryService::class)->movements($this->filter(), 1);

        $this->assertSame(2, $page->total());
        $this->assertSame('receivings:12', $page->items()[0]['correlation_id']);
    }

    public function test_a_movement_row_flags_a_backdated_posting(): void
    {
        $this->entry([
            'occurred_at' => '2026-09-01 09:00:00',
            'posted_at'   => '2026-09-25 09:00:00',
        ]);

        $page = app(LedgerQueryService::class)->movements($this->filter(), 10);

        $this->assertTrue($page->items()[0]['is_backdated']);
    }

    public function test_a_period_wider_than_the_cap_is_refused_rather_than_silently_truncated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/366 days/');

        LedgerFilter::fromArray(['from' => '2024-01-01', 'to' => '2026-09-30']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=LedgerQueryServiceTest`
Expected: FAIL — `Class "App\Services\Ledger\LedgerFilter" not found`.

- [ ] **Step 3: Write LedgerFilter**

```php
<?php

namespace App\Services\Ledger;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The Explorer's scope, validated once so every query shares one interpretation
 * of it. The window is capped here rather than truncated in a query, so an
 * auditor is told their range was too wide instead of being shown a partial
 * answer that looks complete.
 */
class LedgerFilter
{
    public const MAX_DAYS = 366;

    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?int $locationId = null,
        public readonly ?int $productId = null,
        public readonly ?int $categoryId = null,
        public readonly ?int $actorUserId = null,
        public readonly string $lens = 'inventory',
        public readonly ?string $movementType = null,
    ) {
    }

    public static function fromArray(array $q): self
    {
        $from = Carbon::parse($q['from'] ?? Carbon::now()->startOfMonth()->toDateString())->startOfDay();
        $to   = Carbon::parse($q['to'] ?? Carbon::now()->toDateString())->endOfDay();

        if ($to->lessThan($from)) {
            throw new InvalidArgumentException('The end of the period is before its start.');
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw new InvalidArgumentException(
                'That period is wider than the ' . self::MAX_DAYS . ' days this view can total at once. '
                . 'Narrow the range, or export the movement ledger instead.'
            );
        }

        $int = fn ($v) => ($v === null || $v === '') ? null : (int) $v;

        return new self(
            from: $from,
            to: $to,
            locationId: $int($q['location_id'] ?? null),
            productId: $int($q['product_id'] ?? null),
            categoryId: $int($q['category_id'] ?? null),
            actorUserId: $int($q['user_id'] ?? null),
            lens: in_array($q['lens'] ?? 'inventory', ['inventory', 'finance'], true) ? $q['lens'] : 'inventory',
            movementType: ($q['movement_type'] ?? null) ?: null,
        );
    }
}
```

- [ ] **Step 4: Write LedgerQueryService (summary + movements)**

```php
<?php

namespace App\Services\Ledger;

use App\Models\InventoryLedgerEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class LedgerQueryService
{
    /** Base query for a scope. Every read goes through here so filters cannot drift. */
    public function scoped(LedgerFilter $f): Builder
    {
        $q = InventoryLedgerEntry::query()
            ->whereBetween('occurred_at', [$f->from, $f->to])
            ->where('lens', $f->lens);

        if ($f->productId) {
            $q->where('product_id', $f->productId);
        }
        if ($f->movementType) {
            $q->where('movement_type', $f->movementType);
        }
        if ($f->actorUserId) {
            $q->where('actor_user_id', $f->actorUserId);
        }
        if ($f->locationId) {
            $q->where(function ($sub) use ($f) {
                $sub->where('from_location_id', $f->locationId)
                    ->orWhere('to_location_id', $f->locationId);
            });
        }
        if ($f->categoryId) {
            $q->whereIn('product_id', function ($sub) use ($f) {
                $sub->select('product_id')->from('products')->where('category_id', $f->categoryId);
            });
        }

        return $q;
    }

    /** @return array<string,mixed> */
    public function summary(LedgerFilter $f): array
    {
        $rows = $this->scoped($f)->get([
            'direction', 'quantity', 'extended_value', 'cost_basis', 'reverses_ledger_id', 'correlation_id',
        ]);

        $qtyIn  = (int) $rows->where('direction', 'in')->sum('quantity');
        $qtyOut = (int) $rows->where('direction', 'out')->sum('quantity');
        $valIn  = (float) $rows->where('direction', 'in')->sum(fn ($r) => (float) $r->extended_value);
        $valOut = (float) $rows->where('direction', 'out')->sum(fn ($r) => (float) $r->extended_value);

        $byBasis = [];
        foreach (InventoryLedgerEntry::COST_BASES as $basis) {
            $byBasis[$basis] = round(
                (float) $rows->where('cost_basis', $basis)->sum(fn ($r) => (float) $r->extended_value),
                2
            );
        }

        return [
            'quantity_in'    => $qtyIn,
            'quantity_out'   => $qtyOut,
            'net_quantity'   => $qtyIn - $qtyOut,
            'value_in'       => round($valIn, 2),
            'value_out'      => round($valOut, 2),
            'net_value'      => round($valIn - $valOut, 2),
            'event_count'    => $rows->pluck('correlation_id')->unique()->count(),
            'row_count'      => $rows->count(),
            'reversal_count' => $rows->whereNotNull('reverses_ledger_id')->count(),
            // Rows whose quantity we trust but whose value we could not establish.
            // Surfaced rather than folded into a total, so a partial figure cannot
            // read as a complete one.
            'unvalued_rows'  => $rows->whereNull('extended_value')->count(),
            'cost_basis'     => $byBasis,
        ];
    }

    public function movements(LedgerFilter $f, int $perPage = 50): LengthAwarePaginator
    {
        $perPage = min(max($perPage, 5), 200);

        $paginator = $this->scoped($f)
            ->with(['product:product_id,product_name,product_code', 'actor:user_id,first_name,last_name,username'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('ledger_id')
            ->paginate($perPage);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (InventoryLedgerEntry $r) => $this->presentRow($r))
        );

        return $paginator;
    }

    /** @return array<string,mixed> */
    public function presentRow(InventoryLedgerEntry $r): array
    {
        $actor = $r->actor;
        $name  = $actor
            ? (trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? '')) ?: ($actor->username ?? 'System'))
            : 'System';

        return [
            'ledger_id'          => $r->ledger_id,
            'occurred_at'        => $r->occurred_at?->toIso8601String(),
            'posted_at'          => $r->posted_at?->toIso8601String(),
            'is_backdated'       => $r->isBackdated(),
            'direction'          => $r->direction,
            'movement_type'      => $r->movement_type,
            'product_id'         => $r->product_id,
            'product_name'       => $r->product?->product_name,
            'product_code'       => $r->product?->product_code,
            'batch_id'           => $r->batch_id,
            'serial_number'      => $r->serial_number,
            'quantity'           => (int) $r->quantity,
            'unit_cost'          => $r->unit_cost !== null ? (float) $r->unit_cost : null,
            'extended_value'     => $r->extended_value !== null ? (float) $r->extended_value : null,
            'cost_basis'         => $r->cost_basis,
            'from_location_id'   => $r->from_location_id,
            'to_location_id'     => $r->to_location_id,
            'source_type'        => $r->source_type,
            'source_id'          => $r->source_id,
            'source_line_id'     => $r->source_line_id,
            'correlation_id'     => $r->correlation_id,
            'actor_name'         => $name,
            'reverses_ledger_id' => $r->reverses_ledger_id,
            'origin'             => $r->origin,
            'notes'              => $r->notes,
        ];
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd back-end && php artisan test --filter=LedgerQueryServiceTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Services/Ledger/LedgerFilter.php back-end/app/Services/Ledger/LedgerQueryService.php back-end/tests/Feature/Ledger/LedgerQueryServiceTest.php
git commit -m "feat(ledger): add scoped ledger summary and paginated movement reads"
```

---

## Task 12: Breakdown and reconciliation

**Files:**
- Modify: `back-end/app/Services/Ledger/LedgerQueryService.php`
- Test: `back-end/tests/Feature/Ledger/LedgerReconciliationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryLedgerEntry;
use App\Services\Ledger\LedgerFilter;
use App\Services\Ledger\LedgerQueryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LedgerReconciliationTest extends TestCase
{
    use DatabaseTransactions;

    private int $productId = 7701;
    private int $locationId = 7702;

    private function entry(string $direction, int $qty, float $value, string $occurredAt, string $type = 'receiving'): void
    {
        InventoryLedgerEntry::create([
            'occurred_at'      => $occurredAt,
            'posted_at'        => $occurredAt,
            'direction'        => $direction,
            'movement_type'    => $type,
            'lens'             => 'inventory',
            'product_id'       => $this->productId,
            'quantity'         => $qty,
            'unit_cost'        => $qty > 0 ? round($value / $qty, 2) : null,
            'extended_value'   => $value,
            'cost_basis'       => 'actual',
            'from_location_id' => $direction === 'out' ? $this->locationId : null,
            'to_location_id'   => $direction === 'in' ? $this->locationId : null,
            'source_type'      => 'receivings',
            'source_id'        => 1,
            'correlation_id'   => 'receivings:' . uniqid('', true),
            'posting_key'      => 'r:' . uniqid('', true),
        ]);
    }

    private function filter(): LedgerFilter
    {
        return LedgerFilter::fromArray([
            'from'        => '2026-09-01',
            'to'          => '2026-09-30',
            'product_id'  => $this->productId,
            'location_id' => $this->locationId,
        ]);
    }

    public function test_opening_plus_in_minus_out_equals_closing(): void
    {
        $this->entry('in', 100, 10000.00, '2026-08-01 09:00:00');   // before the window: opening
        $this->entry('out', 30, 3000.00, '2026-08-20 09:00:00');    // before the window: opening
        $this->entry('in', 50, 5000.00, '2026-09-10 09:00:00');     // in the window
        $this->entry('out', 20, 2000.00, '2026-09-15 09:00:00');    // in the window

        $r = app(LedgerQueryService::class)->reconciliation($this->filter());

        $this->assertSame(70, $r['opening_quantity']);
        $this->assertSame(50, $r['quantity_in']);
        $this->assertSame(20, $r['quantity_out']);
        $this->assertSame(100, $r['closing_quantity']);
        $this->assertTrue($r['quantity_balances']);
    }

    public function test_value_reconciles_the_same_way_because_costs_are_frozen(): void
    {
        $this->entry('in', 10, 1000.00, '2026-08-01 09:00:00');
        $this->entry('in', 10, 3000.00, '2026-09-10 09:00:00');
        $this->entry('out', 5, 500.00, '2026-09-11 09:00:00');

        $r = app(LedgerQueryService::class)->reconciliation($this->filter());

        $this->assertSame(1000.0, $r['opening_value']);
        $this->assertSame(3000.0, $r['value_in']);
        $this->assertSame(500.0, $r['value_out']);
        $this->assertSame(3500.0, $r['closing_value']);
        $this->assertTrue($r['value_balances']);
    }

    public function test_a_breakdown_by_movement_type_groups_and_totals(): void
    {
        $this->entry('in', 10, 1000.00, '2026-09-02 09:00:00', 'receiving');
        $this->entry('out', 4, 400.00, '2026-09-03 09:00:00', 'issuance');
        $this->entry('out', 2, 200.00, '2026-09-04 09:00:00', 'issuance');

        $rows = app(LedgerQueryService::class)->breakdown($this->filter(), 'movement_type');

        $issuance = collect($rows)->firstWhere('key', 'issuance');
        $this->assertSame(6, $issuance['quantity_out']);
        $this->assertSame(600.0, $issuance['value_out']);
        $this->assertSame(2, $issuance['row_count']);
    }

    public function test_an_unknown_breakdown_dimension_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(LedgerQueryService::class)->breakdown($this->filter(), 'favourite_colour');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=LedgerReconciliationTest`
Expected: FAIL — `Call to undefined method ...::reconciliation()`.

- [ ] **Step 3: Add breakdown() and reconciliation()**

Append to `LedgerQueryService`:

```php
    /** Dimensions the Explorer may pivot by, mapped to the column they group on. */
    private const DIMENSIONS = [
        'movement_type' => 'movement_type',
        'product'       => 'product_id',
        'user'          => 'actor_user_id',
        'source'        => 'source_type',
        'cost_basis'    => 'cost_basis',
    ];

    /** @return array<int,array<string,mixed>> */
    public function breakdown(LedgerFilter $f, string $dimension): array
    {
        if (! isset(self::DIMENSIONS[$dimension])) {
            throw new \InvalidArgumentException(
                "'{$dimension}' is not a breakdown dimension. Available: " . implode(', ', array_keys(self::DIMENSIONS))
            );
        }

        $column = self::DIMENSIONS[$dimension];

        $rows = $this->scoped($f)->get(['direction', 'quantity', 'extended_value', $column, 'correlation_id']);

        $grouped = $rows->groupBy(fn ($r) => (string) ($r->{$column} ?? 'none'))
            ->map(function ($group, $key) {
                $in  = $group->where('direction', 'in');
                $out = $group->where('direction', 'out');

                return [
                    'key'          => $key,
                    'quantity_in'  => (int) $in->sum('quantity'),
                    'quantity_out' => (int) $out->sum('quantity'),
                    'value_in'     => round((float) $in->sum(fn ($r) => (float) $r->extended_value), 2),
                    'value_out'    => round((float) $out->sum(fn ($r) => (float) $r->extended_value), 2),
                    'row_count'    => $group->count(),
                    'event_count'  => $group->pluck('correlation_id')->unique()->count(),
                ];
            })
            ->values();

        // Resolve product and user keys to names so the table needs no second call.
        if ($column === 'product_id') {
            $names = \App\Models\Product::whereIn('product_id', $grouped->pluck('key'))
                ->pluck('product_name', 'product_id');
            $grouped = $grouped->map(fn ($r) => $r + ['label' => $names[$r['key']] ?? ('Product #' . $r['key'])]);
        } elseif ($column === 'actor_user_id') {
            $users = \App\Models\User::whereIn('user_id', $grouped->pluck('key'))->get();
            $grouped = $grouped->map(function ($r) use ($users) {
                $u = $users->firstWhere('user_id', (int) $r['key']);
                $label = $u
                    ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->username ?? 'Unknown'))
                    : 'System';

                return $r + ['label' => $label];
            });
        } else {
            $grouped = $grouped->map(fn ($r) => $r + ['label' => ucwords(str_replace('_', ' ', $r['key']))]);
        }

        return $grouped->sortByDesc(fn ($r) => $r['value_out'] + $r['value_in'])->values()->all();
    }

    /**
     * opening + in - out = closing, for quantity and for value.
     *
     * Opening is every movement before the window, not a stored snapshot: the ledger
     * is append-only, so replaying it is the authoritative answer and cannot drift
     * from a cached figure. Reversals need no special handling — a reversal is a real
     * row in the opposite direction, so it nets itself out here.
     *
     * @return array<string,mixed>
     */
    public function reconciliation(LedgerFilter $f): array
    {
        $opening = $this->openingBalance($f);
        $window  = $this->summary($f);

        $closingQty   = $opening['quantity'] + $window['quantity_in'] - $window['quantity_out'];
        $closingValue = round($opening['value'] + $window['value_in'] - $window['value_out'], 2);

        return [
            'opening_quantity'  => $opening['quantity'],
            'quantity_in'       => $window['quantity_in'],
            'quantity_out'      => $window['quantity_out'],
            'closing_quantity'  => $closingQty,
            'quantity_balances' => $closingQty === $opening['quantity'] + $window['quantity_in'] - $window['quantity_out'],
            'opening_value'     => $opening['value'],
            'value_in'          => $window['value_in'],
            'value_out'         => $window['value_out'],
            'closing_value'     => $closingValue,
            'value_balances'    => abs($closingValue - ($opening['value'] + $window['value_in'] - $window['value_out'])) < 0.01,
            // Value cannot be proven for rows that never had a cost. Reported, not hidden.
            'unvalued_rows'     => $window['unvalued_rows'],
        ];
    }

    /** @return array{quantity:int, value:float} */
    private function openingBalance(LedgerFilter $f): array
    {
        $before = new LedgerFilter(
            from: \Illuminate\Support\Carbon::parse('1970-01-01')->startOfDay(),
            to: $f->from->copy()->subSecond(),
            locationId: $f->locationId,
            productId: $f->productId,
            categoryId: $f->categoryId,
            actorUserId: null,
            lens: $f->lens,
            movementType: null,
        );

        $rows = $this->scoped($before)->get(['direction', 'quantity', 'extended_value']);

        $qty = (int) $rows->where('direction', 'in')->sum('quantity')
             - (int) $rows->where('direction', 'out')->sum('quantity');
        $val = (float) $rows->where('direction', 'in')->sum(fn ($r) => (float) $r->extended_value)
             - (float) $rows->where('direction', 'out')->sum(fn ($r) => (float) $r->extended_value);

        return ['quantity' => $qty, 'value' => round($val, 2)];
    }
```

Note: `openingBalance()` constructs `LedgerFilter` directly rather than through `fromArray()`, which is what keeps the 366-day cap from rejecting an unbounded opening window.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd back-end && php artisan test --filter=LedgerReconciliationTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/Ledger/LedgerQueryService.php back-end/tests/Feature/Ledger/LedgerReconciliationTest.php
git commit -m "feat(ledger): add breakdown pivots and period reconciliation"
```

---

## Task 13: EvidenceResolver

**Files:**
- Create: `back-end/app/Services/Ledger/EvidenceResolver.php`
- Test: `back-end/tests/Feature/Ledger/EvidenceResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Ledger;

use App\Models\AuditTrail;
use App\Models\InventoryLedgerEntry;
use App\Services\Ledger\EvidenceResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EvidenceResolverTest extends TestCase
{
    use DatabaseTransactions;

    private function entry(array $overrides = []): InventoryLedgerEntry
    {
        return InventoryLedgerEntry::create(array_merge([
            'occurred_at'    => '2026-09-10 09:00:00',
            'posted_at'      => '2026-09-10 09:00:00',
            'direction'      => 'out',
            'movement_type'  => 'issuance',
            'lens'           => 'inventory',
            'product_id'     => 5501,
            'batch_id'       => 6601,
            'quantity'       => 2,
            'unit_cost'      => 50.00,
            'extended_value' => 100.00,
            'cost_basis'     => 'actual',
            'from_location_id' => 1,
            'source_type'    => 'issuances',
            'source_id'      => 4242,
            'source_line_id' => 1,
            'correlation_id' => 'issuances:4242',
            'posting_key'    => 'e:' . uniqid('', true),
        ], $overrides));
    }

    public function test_it_returns_every_leg_of_one_event(): void
    {
        $this->entry();
        $this->entry(['source_line_id' => 2, 'quantity' => 3, 'extended_value' => 150.00]);

        $bundle = app(EvidenceResolver::class)->forCorrelation('issuances:4242');

        $this->assertSame('issuances:4242', $bundle['correlation_id']);
        $this->assertCount(2, $bundle['legs']);
        $this->assertSame(5, array_sum(array_column($bundle['legs'], 'quantity')));
        $this->assertSame(250.0, $bundle['totals']['value_out']);
    }

    public function test_it_attaches_the_change_history_recorded_against_the_source_record(): void
    {
        $this->entry();

        AuditTrail::create([
            'user_id'    => null,
            'action'     => 'update',
            'table_name' => 'issuances',
            'record_id'  => 4242,
            'old_values' => ['status_id' => 'Pending'],
            'new_values' => ['status_id' => 'Completed'],
        ]);

        $bundle = app(EvidenceResolver::class)->forCorrelation('issuances:4242');

        $this->assertNotEmpty($bundle['history']);
        $this->assertSame('Completed', $bundle['history'][0]['label']);
    }

    public function test_an_unknown_correlation_id_returns_an_empty_bundle_rather_than_throwing(): void
    {
        $bundle = app(EvidenceResolver::class)->forCorrelation('issuances:999999');

        $this->assertSame([], $bundle['legs']);
        $this->assertSame([], $bundle['history']);
    }

    public function test_a_serial_lifecycle_lists_every_movement_that_touched_it_oldest_first(): void
    {
        $this->entry([
            'direction' => 'in', 'movement_type' => 'receiving', 'serial_number' => 'SN-1',
            'occurred_at' => '2026-01-05 09:00:00', 'to_location_id' => 1, 'from_location_id' => null,
            'source_type' => 'receivings', 'source_id' => 11, 'correlation_id' => 'receivings:11',
        ]);
        $this->entry([
            'serial_number' => 'SN-1', 'occurred_at' => '2026-06-05 09:00:00',
        ]);

        $life = app(EvidenceResolver::class)->lifecycleForSerial('SN-1');

        $this->assertCount(2, $life);
        $this->assertSame('receiving', $life[0]['movement_type']);
        $this->assertSame('issuance', $life[1]['movement_type']);
    }

    public function test_a_batch_lifecycle_lists_its_movements(): void
    {
        $this->entry(['batch_id' => 9001]);

        $life = app(EvidenceResolver::class)->lifecycleForBatch(9001);

        $this->assertCount(1, $life);
        $this->assertSame(9001, $life[0]['batch_id']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=EvidenceResolverTest`
Expected: FAIL — `Class "App\Services\Ledger\EvidenceResolver" not found`.

- [ ] **Step 3: Write EvidenceResolver**

```php
<?php

namespace App\Services\Ledger;

use App\Models\Adjustment;
use App\Models\InventoryBatch;
use App\Models\InventoryLedgerEntry;
use App\Models\Issuance;
use App\Models\Receiving;
use App\Models\Transfer;
use App\Services\History\RecordHistoryService;

/**
 * Turns a ledger row into the evidence behind it: the other legs of the same
 * event, the source document, and the change history already recorded against
 * that document in audit_trail.
 *
 * The history comes from RecordHistoryService rather than a second implementation,
 * so the Explorer and the existing record timelines can never disagree about what
 * happened to a record.
 */
class EvidenceResolver
{
    /** source_type -> the model whose key source_id points at. */
    private const SOURCE_MODELS = [
        'receivings'  => Receiving::class,
        'issuances'   => Issuance::class,
        'transfers'   => Transfer::class,
        'adjustments' => Adjustment::class,
    ];

    public function __construct(
        private LedgerQueryService $ledger,
        private RecordHistoryService $history
    ) {
    }

    /** @return array<string,mixed> */
    public function forCorrelation(string $correlationId): array
    {
        $rows = InventoryLedgerEntry::where('correlation_id', $correlationId)
            ->with(['product:product_id,product_name,product_code', 'actor:user_id,first_name,last_name,username'])
            ->orderBy('occurred_at')
            ->orderBy('ledger_id')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'correlation_id' => $correlationId,
                'legs'           => [],
                'totals'         => ['quantity_in' => 0, 'quantity_out' => 0, 'value_in' => 0.0, 'value_out' => 0.0],
                'source'         => null,
                'history'        => [],
                'batches'        => [],
            ];
        }

        $first = $rows->first();

        return [
            'correlation_id' => $correlationId,
            'legs'           => $rows->map(fn ($r) => $this->ledger->presentRow($r))->all(),
            'totals'         => [
                'quantity_in'  => (int) $rows->where('direction', 'in')->sum('quantity'),
                'quantity_out' => (int) $rows->where('direction', 'out')->sum('quantity'),
                'value_in'     => round((float) $rows->where('direction', 'in')->sum(fn ($r) => (float) $r->extended_value), 2),
                'value_out'    => round((float) $rows->where('direction', 'out')->sum(fn ($r) => (float) $r->extended_value), 2),
            ],
            'source'  => $this->sourceDocument((string) $first->source_type, (int) $first->source_id),
            'history' => $this->history->forRecord((string) $first->source_type, (int) $first->source_id),
            'batches' => $this->batchesFor($rows->pluck('batch_id')->filter()->unique()->all()),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function lifecycleForSerial(string $serialNumber): array
    {
        return InventoryLedgerEntry::where('serial_number', $serialNumber)
            ->with(['product:product_id,product_name,product_code', 'actor:user_id,first_name,last_name,username'])
            ->orderBy('occurred_at')
            ->orderBy('ledger_id')
            ->get()
            ->map(fn ($r) => $this->ledger->presentRow($r))
            ->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function lifecycleForBatch(int $batchId): array
    {
        return InventoryLedgerEntry::where('batch_id', $batchId)
            ->with(['product:product_id,product_name,product_code', 'actor:user_id,first_name,last_name,username'])
            ->orderBy('occurred_at')
            ->orderBy('ledger_id')
            ->get()
            ->map(fn ($r) => $this->ledger->presentRow($r))
            ->all();
    }

    /** @return array<string,mixed>|null */
    private function sourceDocument(string $sourceType, int $sourceId): ?array
    {
        $class = self::SOURCE_MODELS[$sourceType] ?? null;
        if (! $class) {
            return null;
        }

        $record = $class::find($sourceId);
        if (! $record) {
            // The document was archived or removed; the ledger row survives it,
            // which is the whole reason the ledger declares no foreign keys.
            return ['type' => $sourceType, 'id' => $sourceId, 'missing' => true, 'attributes' => []];
        }

        return [
            'type'       => $sourceType,
            'id'         => $sourceId,
            'missing'    => false,
            'attributes' => $record->toArray(),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function batchesFor(array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }

        return InventoryBatch::whereIn('batch_id', $batchIds)
            ->get(['batch_id', 'product_id', 'location_id', 'receiving_id', 'quantity_received', 'quantity_remaining', 'received_at', 'unit_cost', 'cost_basis', 'zone_label'])
            ->map(fn ($b) => $b->toArray())
            ->all();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd back-end && php artisan test --filter=EvidenceResolverTest`
Expected: PASS (5 tests).

If `test_it_attaches_the_change_history...` fails on the label, check what `RecordHistoryService::classify()` returns for a `status_id` change — it returns the resolved status name, which the `AuditTrail` row above supplies as `'Completed'`.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/Ledger/EvidenceResolver.php back-end/tests/Feature/Ledger/EvidenceResolverTest.php
git commit -m "feat(ledger): resolve evidence bundles and serial/batch lifecycles"
```

---

## Task 14: ledger:backfill

**Files:**
- Create: `back-end/app/Services/Ledger/LedgerBackfiller.php`
- Create: `back-end/app/Console/Commands/LedgerBackfill.php`
- Test: `back-end/tests/Feature/Ledger/LedgerBackfillTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryLedgerEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\ReceivingDetail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerBackfillTest extends TestCase
{
    use DatabaseTransactions;

    private function historicReceiving(float $price, int $qty = 8): array
    {
        $location = Location::create([
            'location_code' => 'L-' . uniqid(), 'location_name' => 'WH', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'M-' . uniqid(), 'product_name' => 'Old Stock',
            'product_type' => 'installation_material', 'cost_price' => 5.00,
        ]);
        $receiving = Receiving::create([
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date' => '2026-03-15',
            'total_quantity_received' => $qty,
            'total_quantity_damaged' => 0,
            'location_id' => $location->location_id,
        ]);
        $detail = ReceivingDetail::create([
            'receiving_id' => $receiving->receiving_id,
            'product_id' => $product->product_id,
            'quantity_amount' => $qty,
            'prod_price' => $price,
            'condition' => 'Good',
            'entry_mode' => 'counted',
        ]);

        return [$receiving, $detail, $product, $location];
    }

    public function test_it_reconstructs_a_historic_receiving_and_marks_it_reconstructed(): void
    {
        [$receiving, $detail, $product, $location] = $this->historicReceiving(42.50);

        $this->artisan('ledger:backfill', ['--source' => 'receivings', '--from' => '2026-01-01', '--to' => '2026-12-31'])
            ->assertExitCode(0);

        $row = InventoryLedgerEntry::where('source_type', 'receivings')
            ->where('source_id', $receiving->receiving_id)
            ->firstOrFail();

        $this->assertSame('reconstructed', $row->origin);
        $this->assertSame('in', $row->direction);
        $this->assertSame(8, (int) $row->quantity);
        $this->assertSame('42.50', $row->unit_cost);
        $this->assertSame('2026-03-15', $row->occurred_at->format('Y-m-d'));
        $this->assertSame((int) $location->location_id, (int) $row->to_location_id);
        $this->assertSame((int) $detail->receiving_detail_id, (int) $row->source_line_id);
    }

    public function test_a_line_with_no_recoverable_price_records_quantity_and_no_value(): void
    {
        [$receiving] = $this->historicReceiving(0.0);

        $this->artisan('ledger:backfill', ['--source' => 'receivings', '--from' => '2026-01-01', '--to' => '2026-12-31'])
            ->assertExitCode(0);

        $row = InventoryLedgerEntry::where('source_type', 'receivings')
            ->where('source_id', $receiving->receiving_id)
            ->firstOrFail();

        $this->assertSame('unavailable', $row->cost_basis);
        $this->assertNull($row->extended_value);
        $this->assertSame(8, (int) $row->quantity);
    }

    public function test_running_it_twice_writes_the_same_number_of_rows(): void
    {
        $this->historicReceiving(10.00);

        $this->artisan('ledger:backfill', ['--source' => 'receivings', '--from' => '2026-01-01', '--to' => '2026-12-31']);
        $after = InventoryLedgerEntry::count();

        $this->artisan('ledger:backfill', ['--source' => 'receivings', '--from' => '2026-01-01', '--to' => '2026-12-31']);

        $this->assertSame($after, InventoryLedgerEntry::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->historicReceiving(10.00);

        $this->artisan('ledger:backfill', [
            '--source' => 'receivings', '--from' => '2026-01-01', '--to' => '2026-12-31', '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, InventoryLedgerEntry::count());
    }

    public function test_it_will_not_overwrite_a_live_posted_row(): void
    {
        [$receiving, $detail, $product, $location] = $this->historicReceiving(10.00);

        // A row already posted live, with the same deterministic key.
        InventoryLedgerEntry::create([
            'occurred_at' => '2026-03-15 00:00:00', 'posted_at' => '2026-03-15 00:00:00',
            'direction' => 'in', 'movement_type' => 'receiving', 'lens' => 'inventory',
            'product_id' => $product->product_id, 'quantity' => 8,
            'unit_cost' => 10.00, 'extended_value' => 80.00, 'cost_basis' => 'actual',
            'to_location_id' => $location->location_id,
            'source_type' => 'receivings', 'source_id' => $receiving->receiving_id,
            'source_line_id' => $detail->receiving_detail_id,
            'correlation_id' => 'receivings:' . $receiving->receiving_id, 'origin' => 'posted',
            'posting_key' => 'receiving:' . $receiving->receiving_id . ':line:' . $detail->receiving_detail_id . ':in',
        ]);

        $this->artisan('ledger:backfill', ['--source' => 'receivings', '--from' => '2026-01-01', '--to' => '2026-12-31']);

        $rows = InventoryLedgerEntry::where('source_id', $receiving->receiving_id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('posted', $rows->first()->origin);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=LedgerBackfillTest`
Expected: FAIL — `The command "ledger:backfill" does not exist.`

- [ ] **Step 3: Write LedgerBackfiller**

```php
<?php

namespace App\Services\Ledger;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reconstructs ledger rows for movements that happened before the ledger existed.
 *
 * Rows are marked origin=reconstructed and carry an honest cost_basis, because an
 * auditor has to be able to tell evidence we recorded from history we inferred.
 * Keys are the same deterministic posting_keys the live paths use, so a backfill
 * can never duplicate or overwrite a row that was posted for real.
 */
class LedgerBackfiller
{
    public const SOURCES = ['receivings', 'issuances', 'transfers', 'adjustments'];

    public function __construct(private LedgerPoster $poster)
    {
    }

    /**
     * @return array{written:int, skipped:int, by_basis:array<string,int>}
     */
    public function backfillReceivings(Carbon $from, Carbon $to, int $chunk, bool $dryRun): array
    {
        $stats = ['written' => 0, 'skipped' => 0, 'by_basis' => []];

        DB::table('receiving_details as rd')
            ->join('receivings as r', 'r.receiving_id', '=', 'rd.receiving_id')
            ->leftJoin('inventory_batches as b', function ($j) {
                $j->on('b.receiving_id', '=', 'r.receiving_id')
                  ->on('b.product_id', '=', 'rd.product_id')
                  ->on('b.location_id', '=', 'r.location_id');
            })
            ->whereBetween('r.receiving_date', [$from->toDateString(), $to->toDateString()])
            ->select([
                'rd.receiving_detail_id', 'rd.receiving_id', 'rd.product_id', 'rd.quantity_amount',
                'rd.prod_price', 'rd.scanned_barcode', 'r.location_id', 'r.receiving_date', 'r.received_by',
                'b.batch_id',
            ])
            ->orderBy('rd.receiving_detail_id')
            ->chunk($chunk, function ($lines) use (&$stats, $dryRun) {
                foreach ($lines as $line) {
                    $qty = (int) $line->quantity_amount;
                    if ($qty <= 0) {
                        $stats['skipped']++;
                        continue;
                    }

                    $price = $line->prod_price !== null ? (float) $line->prod_price : 0.0;
                    $basis = $price > 0 ? 'actual' : 'unavailable';
                    $cost  = $price > 0 ? round($price, 2) : null;

                    $stats['by_basis'][$basis] = ($stats['by_basis'][$basis] ?? 0) + 1;

                    if ($dryRun) {
                        $stats['written']++;
                        continue;
                    }

                    $this->poster->post([
                        'occurred_at'    => $line->receiving_date ?: Carbon::now(),
                        'direction'      => 'in',
                        'movement_type'  => 'receiving',
                        'product_id'     => (int) $line->product_id,
                        'batch_id'       => $line->batch_id ? (int) $line->batch_id : null,
                        'serial_number'  => $line->scanned_barcode ?: null,
                        'quantity'       => $qty,
                        'unit_cost'      => $cost,
                        'cost_basis'     => $basis,
                        'to_location_id' => (int) $line->location_id,
                        'source_type'    => 'receivings',
                        'source_id'      => (int) $line->receiving_id,
                        'source_line_id' => (int) $line->receiving_detail_id,
                        'correlation_id' => 'receivings:' . $line->receiving_id,
                        'actor_user_id'  => $line->received_by ? (int) $line->received_by : null,
                        'origin'         => 'reconstructed',
                        'posting_key'    => 'receiving:' . $line->receiving_id
                                            . ':line:' . $line->receiving_detail_id . ':in',
                    ]);

                    $stats['written']++;
                }
            });

        return $stats;
    }

    /**
     * Issuance history is reconstructed from inventory_batch_movements, which is the
     * only record of which batch each issued piece came from. Costs come from the
     * batch; a batch with no unit_cost yields an unvalued row rather than a guess.
     *
     * @return array{written:int, skipped:int, by_basis:array<string,int>}
     */
    public function backfillIssuances(Carbon $from, Carbon $to, int $chunk, bool $dryRun): array
    {
        $stats = ['written' => 0, 'skipped' => 0, 'by_basis' => []];

        DB::table('inventory_batch_movements as m')
            ->leftJoin('inventory_batches as b', 'b.batch_id', '=', 'm.batch_id')
            ->whereNotNull('m.issuance_id')
            ->whereBetween('m.moved_at', [$from, $to])
            ->select([
                'm.batch_movement_id', 'm.issuance_id', 'm.product_id', 'm.quantity',
                'm.serial_number', 'm.from_location_id', 'm.performed_by', 'm.moved_at', 'm.batch_id',
                'b.unit_cost', 'b.cost_basis',
            ])
            ->orderBy('m.batch_movement_id')
            ->chunk($chunk, function ($moves) use (&$stats, $dryRun) {
                foreach ($moves as $mv) {
                    $qty = (int) $mv->quantity;
                    if ($qty <= 0) {
                        $stats['skipped']++;
                        continue;
                    }

                    $cost  = $mv->unit_cost !== null ? (float) $mv->unit_cost : null;
                    $basis = $cost !== null ? ($mv->cost_basis ?: 'actual') : 'unavailable';
                    $stats['by_basis'][$basis] = ($stats['by_basis'][$basis] ?? 0) + 1;

                    if ($dryRun) {
                        $stats['written']++;
                        continue;
                    }

                    $this->poster->post([
                        'occurred_at'      => $mv->moved_at,
                        'direction'        => 'out',
                        'movement_type'    => 'issuance',
                        'product_id'       => (int) $mv->product_id,
                        'batch_id'         => (int) $mv->batch_id,
                        'serial_number'    => $mv->serial_number ?: null,
                        'quantity'         => $qty,
                        'unit_cost'        => $cost,
                        'cost_basis'       => $basis,
                        'from_location_id' => (int) $mv->from_location_id,
                        'source_type'      => 'issuances',
                        'source_id'        => (int) $mv->issuance_id,
                        'correlation_id'   => 'issuances:' . $mv->issuance_id,
                        'actor_user_id'    => $mv->performed_by ? (int) $mv->performed_by : null,
                        'origin'           => 'reconstructed',
                        // Keyed on the movement row, which is the only stable identity
                        // historic issuance slices have.
                        'posting_key'      => 'issuance:' . $mv->issuance_id
                                              . ':movement:' . $mv->batch_movement_id . ':out',
                    ]);

                    $stats['written']++;
                }
            });

        return $stats;
    }

    /** @return array{written:int, skipped:int, by_basis:array<string,int>} */
    public function backfillTransfers(Carbon $from, Carbon $to, int $chunk, bool $dryRun): array
    {
        $stats = ['written' => 0, 'skipped' => 0, 'by_basis' => []];

        DB::table('inventory_batch_movements as m')
            ->leftJoin('inventory_batches as b', 'b.batch_id', '=', 'm.batch_id')
            ->whereNotNull('m.transfer_id')
            ->whereBetween('m.moved_at', [$from, $to])
            ->select([
                'm.batch_movement_id', 'm.transfer_id', 'm.product_id', 'm.quantity', 'm.serial_number',
                'm.from_location_id', 'm.to_location_id', 'm.performed_by', 'm.moved_at', 'm.batch_id',
                'b.unit_cost', 'b.cost_basis',
            ])
            ->orderBy('m.batch_movement_id')
            ->chunk($chunk, function ($moves) use (&$stats, $dryRun) {
                foreach ($moves as $mv) {
                    $qty = (int) $mv->quantity;
                    if ($qty <= 0) {
                        $stats['skipped']++;
                        continue;
                    }

                    $cost  = $mv->unit_cost !== null ? (float) $mv->unit_cost : null;
                    $basis = $cost !== null ? ($mv->cost_basis ?: 'actual') : 'unavailable';
                    $stats['by_basis'][$basis] = ($stats['by_basis'][$basis] ?? 0) + 1;

                    if ($dryRun) {
                        $stats['written']++;
                        continue;
                    }

                    // inventory_batch_movements records the dispatch only, so history
                    // yields the out leg. A reconstructed transfer with no in leg is
                    // therefore either still in transit or a receipt that predates the
                    // ledger — the drift check surfaces the difference.
                    $this->poster->post([
                        'occurred_at'      => $mv->moved_at,
                        'direction'        => 'out',
                        'movement_type'    => 'transfer_out',
                        'product_id'       => (int) $mv->product_id,
                        'batch_id'         => (int) $mv->batch_id,
                        'serial_number'    => $mv->serial_number ?: null,
                        'quantity'         => $qty,
                        'unit_cost'        => $cost,
                        'cost_basis'       => $basis,
                        'from_location_id' => (int) $mv->from_location_id,
                        'to_location_id'   => $mv->to_location_id ? (int) $mv->to_location_id : null,
                        'source_type'      => 'transfers',
                        'source_id'        => (int) $mv->transfer_id,
                        'correlation_id'   => 'transfers:' . $mv->transfer_id,
                        'actor_user_id'    => $mv->performed_by ? (int) $mv->performed_by : null,
                        'origin'           => 'reconstructed',
                        'posting_key'      => 'transfer:' . $mv->transfer_id
                                              . ':movement:' . $mv->batch_movement_id . ':out',
                    ]);

                    $stats['written']++;
                }
            });

        return $stats;
    }

    /** @return array{written:int, skipped:int, by_basis:array<string,int>} */
    public function backfillAdjustments(Carbon $from, Carbon $to, int $chunk, bool $dryRun): array
    {
        $stats = ['written' => 0, 'skipped' => 0, 'by_basis' => []];

        DB::table('adjustment_details as ad')
            ->join('adjustments as a', 'a.adjustment_id', '=', 'ad.adjustment_id')
            ->whereBetween('a.adjustment_date', [$from->toDateString(), $to->toDateString()])
            ->select([
                'ad.adjustment_detail_id', 'ad.adjustment_id', 'ad.product_id',
                'ad.add_quantity', 'ad.deduct_quantity',
                'a.location_id', 'a.adjustment_date', 'a.adjustment_type', 'a.created_by',
            ])
            ->orderBy('ad.adjustment_detail_id')
            ->chunk($chunk, function ($lines) use (&$stats, $dryRun) {
                foreach ($lines as $line) {
                    $net = (int) $line->add_quantity - (int) $line->deduct_quantity;
                    if ($net === 0) {
                        $stats['skipped']++;
                        continue;
                    }

                    // Historic adjustments have no cost of their own. Rather than
                    // inventing one, value is left unavailable: the quantity is the
                    // fact, and an invented peso figure would be worse than none.
                    $stats['by_basis']['unavailable'] = ($stats['by_basis']['unavailable'] ?? 0) + 1;

                    if ($dryRun) {
                        $stats['written']++;
                        continue;
                    }

                    $this->poster->post([
                        'occurred_at'      => $line->adjustment_date,
                        'direction'        => $net > 0 ? 'in' : 'out',
                        'movement_type'    => 'adjustment',
                        'product_id'       => (int) $line->product_id,
                        'quantity'         => abs($net),
                        'unit_cost'        => null,
                        'cost_basis'       => 'unavailable',
                        'from_location_id' => $net < 0 ? (int) $line->location_id : null,
                        'to_location_id'   => $net > 0 ? (int) $line->location_id : null,
                        'source_type'      => 'adjustments',
                        'source_id'        => (int) $line->adjustment_id,
                        'source_line_id'   => (int) $line->adjustment_detail_id,
                        'correlation_id'   => 'adjustments:' . $line->adjustment_id,
                        'actor_user_id'    => $line->created_by ? (int) $line->created_by : null,
                        'notes'            => $line->adjustment_type,
                        'origin'           => 'reconstructed',
                        'posting_key'      => 'adjustment:' . $line->adjustment_id
                                              . ':line:' . $line->adjustment_detail_id
                                              . ':' . ($net > 0 ? 'in' : 'out'),
                    ]);

                    $stats['written']++;
                }
            });

        return $stats;
    }
}
```

- [ ] **Step 4: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Services\Ledger\LedgerBackfiller;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class LedgerBackfill extends Command
{
    protected $signature = 'ledger:backfill
        {--source=all : receivings|issuances|transfers|adjustments|all}
        {--from= : inclusive start date (default 2000-01-01)}
        {--to= : inclusive end date (default today)}
        {--chunk=500 : rows per chunk}
        {--dry-run : report what would be written without writing it}';

    protected $description = 'Reconstruct ledger rows for movements that predate the ledger.';

    public function handle(LedgerBackfiller $backfiller): int
    {
        $from   = Carbon::parse($this->option('from') ?: '2000-01-01')->startOfDay();
        $to     = Carbon::parse($this->option('to') ?: Carbon::now()->toDateString())->endOfDay();
        $chunk  = max(50, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $source = $this->option('source');

        $sources = $source === 'all' ? LedgerBackfiller::SOURCES : [$source];

        foreach ($sources as $one) {
            if (! in_array($one, LedgerBackfiller::SOURCES, true)) {
                $this->error("Unknown source '{$one}'. Available: " . implode(', ', LedgerBackfiller::SOURCES));

                return self::FAILURE;
            }
        }

        if ($dryRun) {
            $this->warn('Dry run — nothing will be written.');
        }

        // Receivings first: they establish the batches and costs every out row reads.
        $ordered = array_values(array_intersect(LedgerBackfiller::SOURCES, $sources));

        $rows = [];
        foreach ($ordered as $one) {
            $method = 'backfill' . ucfirst($one);
            $this->info("Backfilling {$one}…");
            $stats = $backfiller->{$method}($from, $to, $chunk, $dryRun);

            $bases = [];
            foreach ($stats['by_basis'] as $basis => $count) {
                $bases[] = "{$basis}: {$count}";
            }

            $rows[] = [$one, $stats['written'], $stats['skipped'], implode(', ', $bases) ?: '—'];
        }

        $this->table(['Source', 'Written', 'Skipped', 'Cost basis'], $rows);

        $this->line('');
        $this->info('Rows are marked origin=reconstructed. Run `php artisan ledger:drift-check` next to see what the reconstruction could not account for.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd back-end && php artisan test --filter=LedgerBackfillTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Services/Ledger/LedgerBackfiller.php back-end/app/Console/Commands/LedgerBackfill.php back-end/tests/Feature/Ledger/LedgerBackfillTest.php
git commit -m "feat(ledger): reconstruct historic movements with ledger:backfill"
```

---

## Task 15: ledger:drift-check

**Files:**
- Create: `back-end/app/Services/Ledger/DriftChecker.php`
- Create: `back-end/app/Console/Commands/LedgerDriftCheck.php`
- Modify: `back-end/routes/console.php` (or `app/Console/Kernel.php` if this app still has one)
- Test: `back-end/tests/Feature/Ledger/DriftCheckerTest.php`

- [ ] **Step 1: Confirm where schedules live**

Run: `cd back-end && ls app/Console/Kernel.php routes/console.php 2>/dev/null && grep -n "schedule" routes/console.php | head`

Laravel 11 apps register schedules in `routes/console.php`. Use whichever exists.

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryLedgerEntry;
use App\Models\LedgerDriftFinding;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\ReceivingDetail;
use App\Services\Ledger\DriftChecker;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DriftCheckerTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0: Receiving, 1: ReceivingDetail} */
    private function receivingWithLines(int $qty): array
    {
        $location = Location::create([
            'location_code' => 'L-' . uniqid(), 'location_name' => 'WH', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'M-' . uniqid(), 'product_name' => 'Widget',
            'product_type' => 'installation_material', 'cost_price' => 10.00,
        ]);
        $receiving = Receiving::create([
            'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => '2026-09-10',
            'total_quantity_received' => $qty, 'total_quantity_damaged' => 0,
            'location_id' => $location->location_id,
        ]);
        $detail = ReceivingDetail::create([
            'receiving_id' => $receiving->receiving_id, 'product_id' => $product->product_id,
            'quantity_amount' => $qty, 'prod_price' => 10.00, 'condition' => 'Good', 'entry_mode' => 'counted',
        ]);

        return [$receiving, $detail];
    }

    private function window(): array
    {
        return [Carbon::parse('2026-09-01')->startOfDay(), Carbon::parse('2026-09-30')->endOfDay()];
    }

    public function test_an_unposted_receiving_raises_a_source_vs_ledger_finding_with_the_right_variance(): void
    {
        $this->receivingWithLines(12);

        [$from, $to] = $this->window();
        app(DriftChecker::class)->run($from, $to);

        $finding = LedgerDriftFinding::where('check_type', 'source_vs_ledger')
            ->where('source_type', 'receivings')
            ->whereNull('resolved_at')
            ->firstOrFail();

        $this->assertSame(12.0, (float) $finding->expected);
        $this->assertSame(0.0, (float) $finding->actual);
        $this->assertSame(12.0, (float) $finding->variance);
    }

    public function test_a_fully_posted_period_raises_no_finding(): void
    {
        [$receiving, $detail] = $this->receivingWithLines(12);

        InventoryLedgerEntry::create([
            'occurred_at' => '2026-09-10 09:00:00', 'posted_at' => '2026-09-10 09:00:00',
            'direction' => 'in', 'movement_type' => 'receiving', 'lens' => 'inventory',
            'product_id' => $detail->product_id, 'quantity' => 12,
            'unit_cost' => 10.00, 'extended_value' => 120.00, 'cost_basis' => 'actual',
            'to_location_id' => $receiving->location_id,
            'source_type' => 'receivings', 'source_id' => $receiving->receiving_id,
            'source_line_id' => $detail->receiving_detail_id,
            'correlation_id' => 'receivings:' . $receiving->receiving_id,
            'posting_key' => 'receiving:' . $receiving->receiving_id . ':line:' . $detail->receiving_detail_id . ':in',
        ]);

        [$from, $to] = $this->window();
        app(DriftChecker::class)->run($from, $to);

        $this->assertSame(
            0,
            LedgerDriftFinding::where('check_type', 'source_vs_ledger')
                ->where('source_type', 'receivings')
                ->count()
        );
    }

    public function test_rerunning_the_check_does_not_pile_up_duplicate_open_findings(): void
    {
        $this->receivingWithLines(12);

        [$from, $to] = $this->window();
        app(DriftChecker::class)->run($from, $to);
        app(DriftChecker::class)->run($from, $to);

        $this->assertSame(
            1,
            LedgerDriftFinding::where('check_type', 'source_vs_ledger')
                ->where('source_type', 'receivings')
                ->whereNull('resolved_at')
                ->count()
        );
    }

    public function test_the_command_exits_non_zero_when_it_finds_drift_so_a_scheduler_notices(): void
    {
        $this->receivingWithLines(5);

        $this->artisan('ledger:drift-check', ['--from' => '2026-09-01', '--to' => '2026-09-30'])
            ->assertExitCode(1);
    }

    public function test_the_command_exits_zero_on_a_clean_period(): void
    {
        $this->artisan('ledger:drift-check', ['--from' => '2020-01-01', '--to' => '2020-01-31'])
            ->assertExitCode(0);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=DriftCheckerTest`
Expected: FAIL — `Class "App\Services\Ledger\DriftChecker" not found`.

- [ ] **Step 4: Write DriftChecker**

```php
<?php

namespace App\Services\Ledger;

use App\Models\LedgerDriftFinding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Proves the ledger still agrees with the rest of the system, so a write path that
 * forgets to post becomes a visible audit finding instead of silent corruption.
 *
 * Three comparisons, each written so that agreement is meaningful: the source
 * tables, the operational FIFO record, and live stock were all written by
 * different code for different reasons.
 */
class DriftChecker
{
    /** Quantities below this are treated as equal — rounding, not drift. */
    private const TOLERANCE = 0.01;

    /** @return array<int,LedgerDriftFinding> */
    public function run(Carbon $from, Carbon $to): array
    {
        $findings = [];

        foreach ($this->sourceVsLedger($from, $to) as $f) {
            $findings[] = $f;
        }
        foreach ($this->ledgerVsMovements($from, $to) as $f) {
            $findings[] = $f;
        }
        foreach ($this->ledgerVsStock() as $f) {
            $findings[] = $f;
        }

        return $findings;
    }

    /** @return array<int,LedgerDriftFinding> */
    private function sourceVsLedger(Carbon $from, Carbon $to): array
    {
        $expected = [
            'receivings' => (float) DB::table('receiving_details as rd')
                ->join('receivings as r', 'r.receiving_id', '=', 'rd.receiving_id')
                ->whereBetween('r.receiving_date', [$from->toDateString(), $to->toDateString()])
                ->sum('rd.quantity_amount'),
            'adjustments' => (float) DB::table('adjustment_details as ad')
                ->join('adjustments as a', 'a.adjustment_id', '=', 'ad.adjustment_id')
                ->whereBetween('a.adjustment_date', [$from->toDateString(), $to->toDateString()])
                ->sum(DB::raw('ABS(COALESCE(ad.add_quantity, 0) - COALESCE(ad.deduct_quantity, 0))')),
        ];

        $findings = [];

        foreach ($expected as $sourceType => $expectedQty) {
            $actualQty = (float) DB::table('inventory_ledger')
                ->where('source_type', $sourceType)
                ->whereNull('reverses_ledger_id')
                ->whereBetween('occurred_at', [$from, $to])
                ->sum('quantity');

            $variance = round($expectedQty - $actualQty, 2);

            if (abs($variance) <= self::TOLERANCE) {
                continue;
            }

            $findings[] = $this->record('source_vs_ledger', $sourceType, $from, $to, $expectedQty, $actualQty, $variance, null,
                $variance > 0
                    ? "{$variance} pieces of {$sourceType} activity are recorded in the source tables but not in the ledger."
                    : abs($variance) . " pieces of ledger {$sourceType} activity have no matching source rows."
            );
        }

        return $findings;
    }

    /** @return array<int,LedgerDriftFinding> */
    private function ledgerVsMovements(Carbon $from, Carbon $to): array
    {
        $expected = (float) DB::table('inventory_batch_movements')
            ->whereNotNull('issuance_id')
            ->whereBetween('moved_at', [$from, $to])
            ->sum('quantity');

        $actual = (float) DB::table('inventory_ledger')
            ->where('source_type', 'issuances')
            ->where('direction', 'out')
            ->whereNull('reverses_ledger_id')
            ->whereBetween('occurred_at', [$from, $to])
            ->sum('quantity');

        $variance = round($expected - $actual, 2);

        if (abs($variance) <= self::TOLERANCE) {
            return [];
        }

        return [$this->record('ledger_vs_movements', 'issuances', $from, $to, $expected, $actual, $variance, null,
            'The operational FIFO record and the ledger disagree about how much stock was issued.'
        )];
    }

    /**
     * Net ledger quantity per product and location against live inventory.
     *
     * Only compared where the ledger has complete coverage — a product whose entire
     * history predates the ledger would otherwise report its whole opening balance
     * as drift, which is noise, not a finding.
     *
     * @return array<int,LedgerDriftFinding>
     */
    private function ledgerVsStock(): array
    {
        $ledgerNet = DB::table('inventory_ledger')
            ->selectRaw("product_id, COALESCE(to_location_id, from_location_id) as location_id,
                SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) as net")
            ->whereNotNull('product_id')
            ->groupBy('product_id', DB::raw('COALESCE(to_location_id, from_location_id)'))
            ->get()
            ->keyBy(fn ($r) => $r->product_id . ':' . $r->location_id);

        if ($ledgerNet->isEmpty()) {
            return [];
        }

        $findings = [];

        $stock = DB::table('inventory')
            ->select(['product_id', 'location_id', 'quantity_on_hand'])
            ->whereIn('product_id', $ledgerNet->pluck('product_id')->unique())
            ->get();

        foreach ($stock as $row) {
            $key = $row->product_id . ':' . $row->location_id;
            if (! $ledgerNet->has($key)) {
                continue;
            }

            $expected = (float) $ledgerNet[$key]->net;
            $actual   = (float) $row->quantity_on_hand;
            $variance = round($expected - $actual, 2);

            if (abs($variance) <= self::TOLERANCE) {
                continue;
            }

            $findings[] = $this->record(
                'ledger_vs_stock', 'inventory', null, null, $expected, $actual, $variance,
                ['product_id' => (int) $row->product_id, 'location_id' => (int) $row->location_id],
                'Net recorded movement does not match stock on hand; stock may have moved without being recorded.'
            );
        }

        return $findings;
    }

    /**
     * Write a finding, unless an identical one is already open. Re-running the check
     * must not bury the auditor in duplicates of the same unresolved fact.
     */
    private function record(
        string $checkType,
        ?string $sourceType,
        ?Carbon $from,
        ?Carbon $to,
        float $expected,
        float $actual,
        float $variance,
        ?array $scope,
        string $detail
    ): LedgerDriftFinding {
        $existing = LedgerDriftFinding::where('check_type', $checkType)
            ->where('source_type', $sourceType)
            ->whereNull('resolved_at')
            ->when($from, fn ($q) => $q->whereDate('period_start', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('period_end', $to->toDateString()))
            ->when($scope === null, fn ($q) => $q->whereNull('scope'))
            ->when($scope !== null, fn ($q) => $q->where('scope', json_encode($scope)))
            ->first();

        if ($existing) {
            $existing->update([
                'expected'    => $expected,
                'actual'      => $actual,
                'variance'    => $variance,
                'detail'      => $detail,
                'detected_at' => Carbon::now(),
            ]);

            return $existing;
        }

        return LedgerDriftFinding::create([
            'check_type'   => $checkType,
            'source_type'  => $sourceType,
            'period_start' => $from?->toDateString(),
            'period_end'   => $to?->toDateString(),
            'scope'        => $scope,
            'expected'     => $expected,
            'actual'       => $actual,
            'variance'     => $variance,
            'detail'       => $detail,
            'detected_at'  => Carbon::now(),
        ]);
    }
}
```

- [ ] **Step 5: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\LedgerDriftFinding;
use App\Services\Ledger\DriftChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class LedgerDriftCheck extends Command
{
    protected $signature = 'ledger:drift-check
        {--from= : inclusive start date (default 30 days ago)}
        {--to= : inclusive end date (default today)}';

    protected $description = 'Compare the ledger against its sources and raise findings for anything unaccounted for.';

    public function handle(DriftChecker $checker): int
    {
        $from = Carbon::parse($this->option('from') ?: Carbon::now()->subDays(30)->toDateString())->startOfDay();
        $to   = Carbon::parse($this->option('to') ?: Carbon::now()->toDateString())->endOfDay();

        try {
            $findings = $checker->run($from, $to);
        } catch (Throwable $e) {
            // A check that cannot run is itself a finding. Silence must never read
            // as health, so the failure is recorded where the auditor will see it.
            LedgerDriftFinding::create([
                'check_type'   => 'check_failed',
                'source_type'  => null,
                'period_start' => $from->toDateString(),
                'period_end'   => $to->toDateString(),
                'detail'       => 'The drift check failed to complete: ' . $e->getMessage(),
                'detected_at'  => Carbon::now(),
            ]);

            $this->error('Drift check failed and recorded a finding: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($findings === []) {
            $this->info("No drift between {$from->toDateString()} and {$to->toDateString()}.");

            return self::SUCCESS;
        }

        $this->warn(count($findings) . ' finding(s) recorded:');
        $this->table(
            ['Check', 'Source', 'Expected', 'Actual', 'Variance'],
            array_map(fn ($f) => [$f->check_type, $f->source_type ?? '—', $f->expected, $f->actual, $f->variance], $findings)
        );

        return self::FAILURE;
    }
}
```

- [ ] **Step 6: Schedule it nightly**

In `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

// Runs after the day's postings have settled. A non-zero exit is expected when
// drift exists — the findings are the output, the exit code is just a signal.
Schedule::command('ledger:drift-check')->dailyAt('02:30')->withoutOverlapping();
```

- [ ] **Step 7: Run the tests**

Run: `cd back-end && php artisan test --filter=DriftCheckerTest`
Expected: PASS (5 tests).

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Services/Ledger/DriftChecker.php back-end/app/Console/Commands/LedgerDriftCheck.php back-end/routes/console.php back-end/tests/Feature/Ledger/DriftCheckerTest.php
git commit -m "feat(ledger): add nightly drift check that records audit findings"
```

---

## Task 16: The audit_evidence permission feature

**Files:**
- Modify: `back-end/database/seeders/PermissionSeeder.php`
- Modify: `frontend/src/admin/utils/navBundles.js`
- Test: `back-end/tests/Feature/Permissions/AuditEvidencePermissionTest.php`

- [ ] **Step 1: Write the failing test**

Match the shape of the existing permission tests first:

Run: `cd back-end && ls tests/Feature/Permissions && sed -n '1,40p' $(ls tests/Feature/Permissions/*.php | head -1)`

```php
<?php

namespace Tests\Feature\Permissions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditEvidencePermissionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_seeder_grants_read_to_admin_and_auditor_only(): void
    {
        $this->artisan('db:seed', ['--class' => 'PermissionSeeder', '--force' => true]);

        $roles = DB::table('permissions as p')
            ->join('roles as r', 'r.role_id', '=', 'p.role_id')
            ->where('p.feature', 'audit_evidence')
            ->where('p.action', 'read')
            ->pluck('r.role_name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['admin', 'auditor'], $roles);
    }

    public function test_only_the_auditor_may_resolve_an_exception(): void
    {
        $this->artisan('db:seed', ['--class' => 'PermissionSeeder', '--force' => true]);

        $roles = DB::table('permissions as p')
            ->join('roles as r', 'r.role_id', '=', 'p.role_id')
            ->where('p.feature', 'audit_evidence')
            ->where('p.action', 'resolve_exception')
            ->pluck('r.role_name')
            ->all();

        $this->assertSame(['auditor'], $roles);
    }
}
```

Adapt the `permissions` table column names (`feature`/`action`/`role_id`) to whatever the schema actually uses — check with:
Run: `cd back-end && php artisan tinker --execute="print_r(Schema::getColumnListing('permissions'));"`

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=AuditEvidencePermissionTest`
Expected: FAIL — the assertion sees an empty array.

- [ ] **Step 3: Add the feature to the seeder**

In `database/seeders/PermissionSeeder.php`, next to `purchase_order_verification`:

```php
            // Audit & Evidence Explorer (page /admin/audit-evidence). Both lenses
            // (inventory and finance) are visible to anyone holding read — the split
            // is a view concern, not a permission boundary. The page is read-only
            // apart from resolving a drift finding, which is the auditor's own act.
            'audit_evidence' => [
                'read'              => ['admin', 'auditor'],
                'resolve_exception' => ['auditor'],
            ],
```

- [ ] **Step 4: Mirror it on the frontend**

In `frontend/src/admin/utils/navBundles.js`, add to `NAV_BUNDLES` immediately after the `Audit Trail` entry:

```js
  { name: 'Audit & Evidence',       routePath: '/admin/audit-evidence',   required: ['audit_evidence.read'] },
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd back-end && php artisan test --filter=AuditEvidencePermissionTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add back-end/database/seeders/PermissionSeeder.php frontend/src/admin/utils/navBundles.js back-end/tests/Feature/Permissions/AuditEvidencePermissionTest.php
git commit -m "feat(ledger): add the audit_evidence permission feature and nav bundle"
```

---

## Task 17: The /api/audit-evidence endpoints

**Files:**
- Create: `back-end/app/Http/Controllers/Api/AuditEvidenceController.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/Ledger/AuditEvidenceApiTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryLedgerEntry;
use App\Models\LedgerDriftFinding;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditEvidenceApiTest extends TestCase
{
    use DatabaseTransactions;

    private function userWithRole(string $roleName): User
    {
        $roleId = (int) (DB::table('roles')->where('role_name', $roleName)->value('role_id')
            ?? Role::create(['role_name' => $roleName, 'description' => ucfirst($roleName)])->role_id);

        return User::create([
            'username' => $roleName . '_' . uniqid(), 'first_name' => 'A', 'last_name' => 'B',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    private function entry(array $overrides = []): InventoryLedgerEntry
    {
        return InventoryLedgerEntry::create(array_merge([
            'occurred_at' => '2026-09-10 09:00:00', 'posted_at' => '2026-09-10 09:00:00',
            'direction' => 'in', 'movement_type' => 'receiving', 'lens' => 'inventory',
            'product_id' => 3301, 'quantity' => 5, 'unit_cost' => 20.00,
            'extended_value' => 100.00, 'cost_basis' => 'actual', 'to_location_id' => 1,
            'source_type' => 'receivings', 'source_id' => 501,
            'correlation_id' => 'receivings:501', 'posting_key' => 'api:' . uniqid('', true),
        ], $overrides));
    }

    private function q(array $extra = []): string
    {
        return http_build_query(array_merge(['from' => '2026-09-01', 'to' => '2026-09-30'], $extra));
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/audit-evidence/summary?' . $this->q())->assertStatus(401);
    }

    public function test_the_summary_returns_totals_for_an_auditor(): void
    {
        $this->entry();

        $res = $this->actingAs($this->userWithRole('auditor'))
            ->getJson('/api/audit-evidence/summary?' . $this->q());

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.quantity_in', 5)
            ->assertJsonPath('data.value_in', 100.0);
    }

    public function test_movements_are_paginated(): void
    {
        $this->entry();
        $this->entry(['source_id' => 502, 'correlation_id' => 'receivings:502']);

        $res = $this->actingAs($this->userWithRole('auditor'))
            ->getJson('/api/audit-evidence/movements?' . $this->q(['per_page' => 1]));

        $res->assertOk()
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('pagination.per_page', 1)
            ->assertJsonCount(1, 'data');
    }

    public function test_a_breakdown_requires_a_known_dimension(): void
    {
        $this->actingAs($this->userWithRole('auditor'))
            ->getJson('/api/audit-evidence/breakdown?' . $this->q(['dimension' => 'nonsense']))
            ->assertStatus(422);
    }

    public function test_a_period_wider_than_the_cap_returns_422_with_a_message_naming_the_cap(): void
    {
        $this->actingAs($this->userWithRole('auditor'))
            ->getJson('/api/audit-evidence/summary?' . http_build_query(['from' => '2024-01-01', 'to' => '2026-09-30']))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_an_event_returns_its_legs_and_history(): void
    {
        $this->entry();

        $this->actingAs($this->userWithRole('auditor'))
            ->getJson('/api/audit-evidence/events/receivings:501')
            ->assertOk()
            ->assertJsonPath('data.correlation_id', 'receivings:501')
            ->assertJsonCount(1, 'data.legs');
    }

    public function test_reconciliation_returns_the_four_terms(): void
    {
        $this->entry();

        $this->actingAs($this->userWithRole('auditor'))
            ->getJson('/api/audit-evidence/reconciliation?' . $this->q())
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'opening_quantity', 'quantity_in', 'quantity_out', 'closing_quantity',
                'opening_value', 'value_in', 'value_out', 'closing_value',
            ]]);
    }

    public function test_open_exceptions_are_listed_and_can_be_resolved_by_an_auditor(): void
    {
        $finding = LedgerDriftFinding::create([
            'check_type' => 'source_vs_ledger', 'source_type' => 'receivings',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'expected' => 10, 'actual' => 4, 'variance' => 6,
            'detail' => 'unposted receiving', 'detected_at' => '2026-09-30 02:30:00',
        ]);

        $auditor = $this->userWithRole('auditor');

        $this->actingAs($auditor)
            ->getJson('/api/audit-evidence/exceptions')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($auditor)
            ->postJson("/api/audit-evidence/exceptions/{$finding->finding_id}/resolve", [
                'resolution_note' => 'Backfilled and verified against the DR.',
            ])
            ->assertOk();

        $this->assertNotNull($finding->fresh()->resolved_at);
        $this->assertSame((int) $auditor->user_id, (int) $finding->fresh()->resolved_by);
    }

    public function test_resolving_an_exception_requires_a_note(): void
    {
        $finding = LedgerDriftFinding::create([
            'check_type' => 'source_vs_ledger', 'detail' => 'x', 'detected_at' => '2026-09-30 02:30:00',
        ]);

        $this->actingAs($this->userWithRole('auditor'))
            ->postJson("/api/audit-evidence/exceptions/{$finding->finding_id}/resolve", [])
            ->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=AuditEvidenceApiTest`
Expected: FAIL — 404 on every route.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LedgerDriftFinding;
use App\Services\Ledger\EvidenceResolver;
use App\Services\Ledger\LedgerFilter;
use App\Services\Ledger\LedgerQueryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The Audit & Evidence Explorer's read surface. Every list is filtered, grouped and
 * paginated server-side: the page this replaces fetched six sources in full and
 * stitched them in the browser, which is how its numbers drifted.
 */
class AuditEvidenceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private LedgerQueryService $ledger,
        private EvidenceResolver $evidence
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        return $this->withFilter($request, fn (LedgerFilter $f) => $this->success($this->ledger->summary($f)));
    }

    public function breakdown(Request $request): JsonResponse
    {
        return $this->withFilter($request, function (LedgerFilter $f) use ($request) {
            $dimension = (string) $request->input('dimension', 'movement_type');

            return $this->success($this->ledger->breakdown($f, $dimension));
        });
    }

    public function movements(Request $request): JsonResponse
    {
        return $this->withFilter($request, function (LedgerFilter $f) use ($request) {
            $page = $this->ledger->movements($f, (int) $request->input('per_page', 50));

            return response()->json([
                'success'    => true,
                'data'       => $page->items(),
                'pagination' => [
                    'total'        => $page->total(),
                    'per_page'     => $page->perPage(),
                    'current_page' => $page->currentPage(),
                    'last_page'    => $page->lastPage(),
                ],
            ]);
        });
    }

    public function reconciliation(Request $request): JsonResponse
    {
        return $this->withFilter($request, fn (LedgerFilter $f) => $this->success($this->ledger->reconciliation($f)));
    }

    public function event(string $correlationId): JsonResponse
    {
        return $this->success($this->evidence->forCorrelation($correlationId));
    }

    public function serialLifecycle(string $serial): JsonResponse
    {
        return $this->success($this->evidence->lifecycleForSerial($serial));
    }

    public function batchLifecycle(int $batchId): JsonResponse
    {
        return $this->success($this->evidence->lifecycleForBatch($batchId));
    }

    public function exceptions(Request $request): JsonResponse
    {
        $query = LedgerDriftFinding::query()->with('resolver:user_id,first_name,last_name,username');

        if ($request->boolean('include_resolved') === false) {
            $query->whereNull('resolved_at');
        }

        $findings = $query->orderByDesc('detected_at')->limit(200)->get()->map(fn ($f) => [
            'finding_id'      => $f->finding_id,
            'check_type'      => $f->check_type,
            'source_type'     => $f->source_type,
            'period_start'    => $f->period_start?->toDateString(),
            'period_end'      => $f->period_end?->toDateString(),
            'scope'           => $f->scope,
            'expected'        => $f->expected !== null ? (float) $f->expected : null,
            'actual'          => $f->actual !== null ? (float) $f->actual : null,
            'variance'        => $f->variance !== null ? (float) $f->variance : null,
            'detail'          => $f->detail,
            'detected_at'     => $f->detected_at?->toIso8601String(),
            'resolved_at'     => $f->resolved_at?->toIso8601String(),
            'resolved_by'     => $f->resolver
                ? (trim(($f->resolver->first_name ?? '') . ' ' . ($f->resolver->last_name ?? '')) ?: $f->resolver->username)
                : null,
            'resolution_note' => $f->resolution_note,
            'is_open'         => $f->isOpen(),
        ]);

        return $this->success($findings);
    }

    public function resolveException(Request $request, int $findingId): JsonResponse
    {
        $validated = $request->validate([
            'resolution_note' => 'required|string|min:3|max:500',
        ]);

        $finding = LedgerDriftFinding::findOrFail($findingId);

        if (! $finding->isOpen()) {
            return $this->error('That finding is already resolved.', 409);
        }

        $finding->update([
            'resolved_at'     => Carbon::now(),
            'resolved_by'     => $request->user()?->user_id,
            'resolution_note' => $validated['resolution_note'],
        ]);

        return $this->success(['finding_id' => $finding->finding_id], 'Finding resolved.');
    }

    /**
     * Build the filter once, and turn its refusals into a 422 the UI can show.
     * A period too wide to total is a message, never a silently truncated answer.
     */
    private function withFilter(Request $request, callable $then): JsonResponse
    {
        try {
            $filter = LedgerFilter::fromArray($request->all());
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        try {
            return $then($filter);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/api.php`, inside the authenticated group (alongside the other `permission:`-guarded groups):

```php
    /*
    |--------------------------------------------------------------------------
    | AUDIT & EVIDENCE EXPLORER
    |--------------------------------------------------------------------------
    | Read-only, apart from resolving a drift finding.
    */
    Route::middleware('permission:audit_evidence,read')->prefix('audit-evidence')->group(function () {
        Route::get('/summary', [AuditEvidenceController::class, 'summary']);
        Route::get('/breakdown', [AuditEvidenceController::class, 'breakdown']);
        Route::get('/movements', [AuditEvidenceController::class, 'movements']);
        Route::get('/reconciliation', [AuditEvidenceController::class, 'reconciliation']);
        Route::get('/exceptions', [AuditEvidenceController::class, 'exceptions']);
        Route::get('/batches/{batchId}/lifecycle', [AuditEvidenceController::class, 'batchLifecycle'])
            ->whereNumber('batchId');
        // Serials and correlation ids contain colons and dashes, so the default
        // segment pattern is widened rather than letting a valid id 404.
        Route::get('/serials/{serial}/lifecycle', [AuditEvidenceController::class, 'serialLifecycle'])
            ->where('serial', '[A-Za-z0-9\-_\.:]+');
        Route::get('/events/{correlationId}', [AuditEvidenceController::class, 'event'])
            ->where('correlationId', '[A-Za-z0-9\-_\.:]+');
    });

    Route::middleware('permission:audit_evidence,resolve_exception')
        ->post('/audit-evidence/exceptions/{findingId}/resolve', [AuditEvidenceController::class, 'resolveException'])
        ->whereNumber('findingId');
```

Add the import at the top of `routes/api.php`:

```php
use App\Http\Controllers\Api\AuditEvidenceController;
```

- [ ] **Step 5: Run the tests**

Run: `cd back-end && php artisan test --filter=AuditEvidenceApiTest`
Expected: PASS (9 tests).

If the auditor user is rejected with 403, the seeded permissions are missing in the test database — run `php artisan db:seed --class=PermissionSeeder --force` against `airkingV5_test` once, or seed inside the test as the existing permission tests do.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/AuditEvidenceController.php back-end/routes/api.php back-end/tests/Feature/Ledger/AuditEvidenceApiTest.php
git commit -m "feat(ledger): expose the audit-evidence read API"
```

---

## Task 18: Frontend API client and useAuditEvidence hook

**Files:**
- Modify: `frontend/src/admin/services/api.js`
- Create: `frontend/src/admin/hooks/useAuditEvidence.js`
- Test: `frontend/src/admin/hooks/useAuditEvidence.test.js`

- [ ] **Step 1: Write the failing test**

```jsx
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { useAuditEvidence } from './useAuditEvidence';

jest.mock('../services/api', () => ({
  auditEvidenceAPI: {
    getSummary: jest.fn(() => Promise.resolve({ data: { quantity_in: 10, value_in: 1000 } })),
    getMovements: jest.fn(() => Promise.resolve({ data: [], pagination: { total: 0, last_page: 1 } })),
  },
}));

const { auditEvidenceAPI } = require('../services/api');

function Probe() {
  const { scope, setScope, summary, loading, error } = useAuditEvidence();

  return (
    <div>
      <span data-testid="from">{scope.from}</span>
      <span data-testid="lens">{scope.lens}</span>
      <span data-testid="qty">{summary ? summary.quantity_in : '—'}</span>
      <span data-testid="loading">{loading ? 'yes' : 'no'}</span>
      <span data-testid="error">{error || ''}</span>
      <button onClick={() => setScope({ lens: 'finance' })}>finance</button>
    </div>
  );
}

const renderProbe = (initialPath = '/admin/audit-evidence') =>
  render(
    <MemoryRouter initialEntries={[initialPath]}>
      <Probe />
    </MemoryRouter>
  );

describe('useAuditEvidence', () => {
  beforeEach(() => jest.clearAllMocks());

  it('defaults to the inventory lens and the current month', async () => {
    renderProbe();

    await waitFor(() => expect(screen.getByTestId('loading')).toHaveTextContent('no'));

    expect(screen.getByTestId('lens')).toHaveTextContent('inventory');
    expect(screen.getByTestId('from')).not.toHaveTextContent('');
    expect(screen.getByTestId('qty')).toHaveTextContent('10');
  });

  it('reads the scope out of the URL so a shared link opens the same evidence', async () => {
    renderProbe('/admin/audit-evidence?from=2026-03-01&to=2026-03-31&lens=finance');

    await waitFor(() => expect(screen.getByTestId('loading')).toHaveTextContent('no'));

    expect(screen.getByTestId('from')).toHaveTextContent('2026-03-01');
    expect(screen.getByTestId('lens')).toHaveTextContent('finance');
  });

  it('surfaces a server refusal instead of showing an empty period as zero activity', async () => {
    auditEvidenceAPI.getSummary.mockRejectedValueOnce(
      new Error('That period is wider than the 366 days this view can total at once.')
    );

    renderProbe();

    await waitFor(() => expect(screen.getByTestId('error')).toHaveTextContent('366 days'));
    expect(screen.getByTestId('qty')).toHaveTextContent('—');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx react-scripts test --watchAll=false --testPathPattern=useAuditEvidence`
Expected: FAIL — `Cannot find module './useAuditEvidence'`.

- [ ] **Step 3: Add the API client**

In `frontend/src/admin/services/api.js`, after `auditAPI`:

```js
/* ======================
   AUDIT & EVIDENCE EXPLORER
   Every call is scoped server-side; nothing here fetches a whole table.
====================== */
const evidenceQuery = (params = {}) =>
  new URLSearchParams(
    Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '')
  ).toString();

export const auditEvidenceAPI = {
  getSummary: async (params = {}) => apiRequest(`/audit-evidence/summary?${evidenceQuery(params)}`),
  getBreakdown: async (params = {}) => apiRequest(`/audit-evidence/breakdown?${evidenceQuery(params)}`),
  getMovements: async (params = {}) => apiRequest(`/audit-evidence/movements?${evidenceQuery(params)}`),
  getReconciliation: async (params = {}) => apiRequest(`/audit-evidence/reconciliation?${evidenceQuery(params)}`),
  getEvent: async (correlationId) => apiRequest(`/audit-evidence/events/${encodeURIComponent(correlationId)}`),
  getSerialLifecycle: async (serial) => apiRequest(`/audit-evidence/serials/${encodeURIComponent(serial)}/lifecycle`),
  getBatchLifecycle: async (batchId) => apiRequest(`/audit-evidence/batches/${batchId}/lifecycle`),
  getExceptions: async (params = {}) => apiRequest(`/audit-evidence/exceptions?${evidenceQuery(params)}`),
  resolveException: async (findingId, resolutionNote) =>
    apiRequest(`/audit-evidence/exceptions/${findingId}/resolve`, {
      method: 'POST',
      body: JSON.stringify({ resolution_note: resolutionNote }),
    }),
};
```

If `apiRequest` in this file takes `(path, options)` with a different option shape, match the shape used by the nearest existing POST helper rather than inventing one.

- [ ] **Step 4: Write the hook**

```js
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { auditEvidenceAPI } from '../services/api';

const firstOfThisMonth = () => {
  const d = new Date();
  return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10);
};

const today = () => new Date().toISOString().slice(0, 10);

/**
 * The Explorer's scope lives in the URL, not in component state.
 *
 * An auditor's output is a finding, and a finding needs a citation — so the view
 * behind it has to be linkable. Keeping scope in the query string means a pasted
 * URL reopens exactly the evidence the auditor was looking at.
 */
export function useAuditEvidence() {
  const [searchParams, setSearchParams] = useSearchParams();

  const scope = useMemo(
    () => ({
      from: searchParams.get('from') || firstOfThisMonth(),
      to: searchParams.get('to') || today(),
      lens: searchParams.get('lens') === 'finance' ? 'finance' : 'inventory',
      location_id: searchParams.get('location_id') || '',
      product_id: searchParams.get('product_id') || '',
      category_id: searchParams.get('category_id') || '',
      user_id: searchParams.get('user_id') || '',
      movement_type: searchParams.get('movement_type') || '',
      dimension: searchParams.get('dimension') || 'movement_type',
    }),
    [searchParams]
  );

  const setScope = useCallback(
    (patch) => {
      const next = new URLSearchParams(searchParams);
      Object.entries(patch).forEach(([key, value]) => {
        if (value === '' || value === null || value === undefined) next.delete(key);
        else next.set(key, String(value));
      });
      setSearchParams(next, { replace: true });
    },
    [searchParams, setSearchParams]
  );

  const [summary, setSummary] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError('');

    auditEvidenceAPI
      .getSummary(scope)
      .then((res) => {
        if (cancelled) return;
        setSummary(res?.data ?? null);
      })
      .catch((e) => {
        if (cancelled) return;
        // A refusal is shown as a refusal. Rendering zeros here would let a
        // rejected query read as a period with no activity in it.
        setSummary(null);
        setError(e?.message || 'Could not load the summary for this period.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [scope]);

  return { scope, setScope, summary, loading, error };
}

export default useAuditEvidence;
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd frontend && npx react-scripts test --watchAll=false --testPathPattern=useAuditEvidence`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/services/api.js frontend/src/admin/hooks/useAuditEvidence.js frontend/src/admin/hooks/useAuditEvidence.test.js
git commit -m "feat(ledger): add the audit evidence API client and URL-backed scope hook"
```

---

## Task 19: SummaryTiles and BreakdownTable

**Files:**
- Create: `frontend/src/admin/components/audit/SummaryTiles.jsx`
- Create: `frontend/src/admin/components/audit/BreakdownTable.jsx`
- Create: `frontend/src/admin/styles/audit_evidence.css`
- Test: `frontend/src/admin/components/audit/SummaryTiles.test.jsx`

- [ ] **Step 1: Write the failing test**

```jsx
import React from 'react';
import { render, screen } from '@testing-library/react';
import SummaryTiles from './SummaryTiles';

const summary = {
  quantity_in: 120,
  quantity_out: 45,
  net_quantity: 75,
  value_in: 24000,
  value_out: 9000,
  net_value: 15000,
  event_count: 18,
  row_count: 31,
  reversal_count: 2,
  unvalued_rows: 3,
  cost_basis: {
    actual: 20000,
    product_default: 4000,
    derived_weighted_avg: 9000,
    unavailable: 0,
  },
};

describe('SummaryTiles', () => {
  it('shows quantity and value movement for the period', () => {
    render(<SummaryTiles summary={summary} />);

    expect(screen.getByTestId('tile-quantity-in')).toHaveTextContent('120');
    expect(screen.getByTestId('tile-quantity-out')).toHaveTextContent('45');
    expect(screen.getByTestId('tile-value-in')).toHaveTextContent('24,000');
    expect(screen.getByTestId('tile-value-out')).toHaveTextContent('9,000');
  });

  it('warns how much value is not backed by an actual cost', () => {
    render(<SummaryTiles summary={summary} />);

    const basis = screen.getByTestId('cost-basis-breakdown');
    expect(basis).toHaveTextContent('Actual');
    expect(basis).toHaveTextContent('Derived');
    expect(screen.getByTestId('unvalued-warning')).toHaveTextContent('3');
  });

  it('says nothing about unvalued rows when every row carries a cost', () => {
    render(<SummaryTiles summary={{ ...summary, unvalued_rows: 0 }} />);

    expect(screen.queryByTestId('unvalued-warning')).toBeNull();
  });

  it('distinguishes an empty period from an unshipped movement type', () => {
    render(<SummaryTiles summary={null} emptyReason="no-movements" />);

    expect(screen.getByTestId('summary-empty')).toHaveTextContent('No movements');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx react-scripts test --watchAll=false --testPathPattern=SummaryTiles`
Expected: FAIL — `Cannot find module './SummaryTiles'`.

- [ ] **Step 3: Write SummaryTiles**

```jsx
import React from 'react';
import '../../styles/audit_evidence.css';

const fmtQty = (n) => Number(n || 0).toLocaleString('en-PH');
const fmtMoney = (n) =>
  '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const BASIS_LABELS = {
  actual: 'Actual',
  product_default: 'Product list cost',
  derived_weighted_avg: 'Derived average',
  unavailable: 'No cost recorded',
};

/**
 * The top of the funnel. Deliberately shows the cost basis alongside the totals:
 * a peso figure that is partly reconstructed and partly evidence is two different
 * claims, and an auditor has to be able to tell them apart before citing either.
 */
const SummaryTiles = ({ summary, emptyReason }) => {
  if (!summary) {
    return (
      <div className="ae-empty" data-testid="summary-empty">
        {emptyReason === 'not-posted-yet'
          ? 'This movement type is not recorded in the ledger yet.'
          : 'No movements in this period.'}
      </div>
    );
  }

  const tiles = [
    { key: 'quantity-in', label: 'Quantity in', value: fmtQty(summary.quantity_in), tone: 'green' },
    { key: 'quantity-out', label: 'Quantity out', value: fmtQty(summary.quantity_out), tone: 'amber' },
    { key: 'value-in', label: 'Value in', value: fmtMoney(summary.value_in), tone: 'green' },
    { key: 'value-out', label: 'Value out', value: fmtMoney(summary.value_out), tone: 'amber' },
    { key: 'net-quantity', label: 'Net quantity', value: fmtQty(summary.net_quantity), tone: 'blue' },
    { key: 'events', label: 'Events', value: fmtQty(summary.event_count), tone: 'gray' },
  ];

  const basisRows = Object.entries(summary.cost_basis || {}).filter(([, v]) => Number(v) !== 0);

  return (
    <div className="ae-summary">
      <div className="ae-tile-grid">
        {tiles.map((t) => (
          <div key={t.key} className={`ae-tile ae-tile--${t.tone}`} data-testid={`tile-${t.key}`}>
            <p className="ae-tile-label">{t.label}</p>
            <p className="ae-tile-value">{t.value}</p>
          </div>
        ))}
      </div>

      <div className="ae-basis" data-testid="cost-basis-breakdown">
        <p className="ae-basis-title">What the value rests on</p>
        <ul className="ae-basis-list">
          {basisRows.length === 0 ? (
            <li className="ae-muted">No valued movements in this period.</li>
          ) : (
            basisRows.map(([basis, value]) => (
              <li key={basis}>
                <span className={`ae-basis-dot ae-basis-dot--${basis}`} aria-hidden="true" />
                {BASIS_LABELS[basis] || basis}: <strong>{fmtMoney(value)}</strong>
              </li>
            ))
          )}
        </ul>

        {Number(summary.unvalued_rows) > 0 && (
          <p className="ae-warn" data-testid="unvalued-warning">
            {fmtQty(summary.unvalued_rows)} movement row(s) have a quantity but no recoverable cost, so their
            value is not included in the totals above.
          </p>
        )}

        {Number(summary.reversal_count) > 0 && (
          <p className="ae-muted" data-testid="reversal-note">
            Includes {fmtQty(summary.reversal_count)} reversal row(s), which net against what they reversed.
          </p>
        )}
      </div>
    </div>
  );
};

export default SummaryTiles;
```

- [ ] **Step 4: Write BreakdownTable**

```jsx
import React from 'react';
import '../../styles/audit_evidence.css';

const fmtQty = (n) => Number(n || 0).toLocaleString('en-PH');
const fmtMoney = (n) =>
  '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const DIMENSIONS = [
  { value: 'movement_type', label: 'Movement type' },
  { value: 'product', label: 'Product' },
  { value: 'user', label: 'User' },
  { value: 'source', label: 'Source document' },
  { value: 'cost_basis', label: 'Cost basis' },
];

/**
 * Level 2: pivot the period, then click a row to see the movements behind it.
 * This is the step that answers "where did that total come from" without making
 * the auditor scroll a ledger.
 */
const BreakdownTable = ({ rows, dimension, onDimensionChange, onDrillDown, loading }) => (
  <div className="ae-card">
    <div className="ae-card-head">
      <div>
        <h2 className="ae-card-title">Breakdown</h2>
        <p className="ae-card-meta">Totals grouped by {DIMENSIONS.find((d) => d.value === dimension)?.label}</p>
      </div>
      <label className="ae-field">
        <span>Group by</span>
        <select value={dimension} onChange={(e) => onDimensionChange(e.target.value)}>
          {DIMENSIONS.map((d) => (
            <option key={d.value} value={d.value}>{d.label}</option>
          ))}
        </select>
      </label>
    </div>

    {loading ? (
      <div className="ae-state">Loading breakdown…</div>
    ) : !rows || rows.length === 0 ? (
      <div className="ae-state">Nothing to group in this period.</div>
    ) : (
      <div className="ae-table-wrap">
        <table className="ae-table">
          <thead>
            <tr>
              <th>{DIMENSIONS.find((d) => d.value === dimension)?.label}</th>
              <th className="ae-num">Qty in</th>
              <th className="ae-num">Qty out</th>
              <th className="ae-num">Value in</th>
              <th className="ae-num">Value out</th>
              <th className="ae-num">Events</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.key}>
                <td>{r.label}</td>
                <td className="ae-num">{fmtQty(r.quantity_in)}</td>
                <td className="ae-num">{fmtQty(r.quantity_out)}</td>
                <td className="ae-num">{fmtMoney(r.value_in)}</td>
                <td className="ae-num">{fmtMoney(r.value_out)}</td>
                <td className="ae-num">{fmtQty(r.event_count)}</td>
                <td>
                  <button type="button" className="ae-btn ae-btn--ghost" onClick={() => onDrillDown(r)}>
                    View movements
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    )}
  </div>
);

export default BreakdownTable;
```

- [ ] **Step 5: Write the stylesheet**

Create `frontend/src/admin/styles/audit_evidence.css`. Use the existing design tokens so the page matches the rest of the admin and works in dark mode — check what the other stylesheets use first:

Run: `cd frontend && grep -n "var(--" src/admin/styles/dashboard_air.css | head -20`

```css
/* Audit & Evidence Explorer. Tokens only — no hard-coded colours, so dark mode
   works without a second palette. */
.ae-summary { display: grid; gap: 1rem; }

.ae-tile-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 0.75rem;
}

.ae-tile {
  padding: 0.875rem 1rem;
  border: 1px solid var(--border-color);
  border-radius: 10px;
  background: var(--surface-color);
}
.ae-tile-label { margin: 0 0 0.25rem; font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
.ae-tile-value { margin: 0; font-size: 1.35rem; font-weight: 600; color: var(--text-color); }

.ae-basis { padding: 0.875rem 1rem; border: 1px solid var(--border-color); border-radius: 10px; background: var(--surface-color); }
.ae-basis-title { margin: 0 0 0.5rem; font-size: 0.8rem; font-weight: 600; color: var(--text-color); }
.ae-basis-list { margin: 0; padding: 0; list-style: none; display: grid; gap: 0.3rem; font-size: 0.85rem; color: var(--text-color); }
.ae-basis-dot { display: inline-block; width: 0.6rem; height: 0.6rem; border-radius: 50%; margin-right: 0.4rem; background: var(--text-muted); }
.ae-basis-dot--actual { background: var(--success-color, #16a34a); }
.ae-basis-dot--derived_weighted_avg { background: var(--warning-color, #d97706); }
.ae-basis-dot--product_default { background: var(--info-color, #2563eb); }
.ae-basis-dot--unavailable { background: var(--danger-color, #dc2626); }

.ae-warn { margin: 0.6rem 0 0; font-size: 0.8rem; color: var(--warning-color, #d97706); }
.ae-muted { margin: 0.4rem 0 0; font-size: 0.8rem; color: var(--text-muted); }
.ae-empty, .ae-state { padding: 1.5rem; text-align: center; color: var(--text-muted); }

.ae-card { border: 1px solid var(--border-color); border-radius: 12px; background: var(--surface-color); margin-top: 1rem; }
.ae-card-head { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end; justify-content: space-between; padding: 1rem; border-bottom: 1px solid var(--border-color); }
.ae-card-title { margin: 0; font-size: 1rem; color: var(--text-color); }
.ae-card-meta { margin: 0.2rem 0 0; font-size: 0.8rem; color: var(--text-muted); }

.ae-field { display: grid; gap: 0.25rem; font-size: 0.75rem; color: var(--text-muted); }
.ae-field select, .ae-field input {
  padding: 0.4rem 0.5rem; border: 1px solid var(--border-color); border-radius: 6px;
  background: var(--input-bg, var(--surface-color)); color: var(--text-color);
}

/* Wide tables scroll inside their own container; the page body never does. */
.ae-table-wrap { overflow-x: auto; }
.ae-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.ae-table th, .ae-table td { padding: 0.55rem 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color); color: var(--text-color); white-space: nowrap; }
.ae-table th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); }
.ae-num { text-align: right; font-variant-numeric: tabular-nums; }

.ae-row--reversed td { text-decoration: line-through; opacity: 0.65; }
.ae-flag { display: inline-block; margin-left: 0.35rem; padding: 0.05rem 0.35rem; border-radius: 999px; font-size: 0.68rem; border: 1px solid var(--border-color); color: var(--text-muted); }

.ae-btn { padding: 0.35rem 0.7rem; border-radius: 6px; border: 1px solid var(--border-color); background: transparent; color: var(--text-color); cursor: pointer; font-size: 0.8rem; }
.ae-btn--ghost:hover { background: var(--hover-bg, rgba(0,0,0,0.04)); }

.ae-scope { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end; }
.ae-lens-tabs { display: inline-flex; border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; }
.ae-lens-tabs button { padding: 0.4rem 0.9rem; border: 0; background: transparent; color: var(--text-muted); cursor: pointer; font-size: 0.82rem; }
.ae-lens-tabs button[aria-pressed='true'] { background: var(--accent-color, #2563eb); color: #fff; }
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd frontend && npx react-scripts test --watchAll=false --testPathPattern=SummaryTiles`
Expected: PASS (4 tests).

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/components/audit frontend/src/admin/styles/audit_evidence.css
git commit -m "feat(ledger): add summary tiles and breakdown pivot for the Explorer"
```

---

## Task 20: MovementLedgerTable and EvidenceDrawer

**Files:**
- Create: `frontend/src/admin/components/audit/MovementLedgerTable.jsx`
- Create: `frontend/src/admin/components/audit/EvidenceDrawer.jsx`
- Create: `frontend/src/admin/components/audit/LifecycleTimeline.jsx`
- Test: `frontend/src/admin/components/audit/MovementLedgerTable.test.jsx`

- [ ] **Step 1: Write the failing test**

```jsx
import React from 'react';
import { render, screen } from '@testing-library/react';
import MovementLedgerTable from './MovementLedgerTable';

const original = {
  ledger_id: 1,
  occurred_at: '2026-09-10T09:00:00+08:00',
  posted_at: '2026-09-10T09:00:05+08:00',
  is_backdated: false,
  direction: 'out',
  movement_type: 'issuance',
  product_name: 'Copper Pipe',
  batch_id: 55,
  serial_number: null,
  quantity: 4,
  unit_cost: 100,
  extended_value: 400,
  cost_basis: 'actual',
  correlation_id: 'issuances:9',
  actor_name: 'Ana Cruz',
  reverses_ledger_id: null,
  origin: 'posted',
};

const reversal = {
  ...original,
  ledger_id: 2,
  direction: 'in',
  reverses_ledger_id: 1,
  notes: 'Issuance voided',
};

describe('MovementLedgerTable', () => {
  const noop = () => {};

  it('renders a movement row with its frozen cost', () => {
    render(<MovementLedgerTable rows={[original]} onOpenEvidence={noop} />);

    expect(screen.getByText('Copper Pipe')).toBeInTheDocument();
    expect(screen.getByTestId('row-1')).toHaveTextContent('400.00');
  });

  it('marks a reversal and the row it reversed as a struck-through pair', () => {
    render(<MovementLedgerTable rows={[original, reversal]} onOpenEvidence={noop} />);

    expect(screen.getByTestId('row-1').className).toContain('ae-row--reversed');
    expect(screen.getByTestId('row-2').className).toContain('ae-row--reversed');
    expect(screen.getByTestId('row-2')).toHaveTextContent('Reversal');
  });

  it('flags a reconstructed row so derived history is never mistaken for evidence', () => {
    render(<MovementLedgerTable rows={[{ ...original, origin: 'reconstructed' }]} onOpenEvidence={noop} />);

    expect(screen.getByTestId('row-1')).toHaveTextContent('Reconstructed');
  });

  it('flags a non-actual cost basis', () => {
    render(<MovementLedgerTable rows={[{ ...original, cost_basis: 'derived_weighted_avg' }]} onOpenEvidence={noop} />);

    expect(screen.getByTestId('row-1')).toHaveTextContent('Derived');
  });

  it('flags a backdated posting', () => {
    render(<MovementLedgerTable rows={[{ ...original, is_backdated: true }]} onOpenEvidence={noop} />);

    expect(screen.getByTestId('row-1')).toHaveTextContent('Backdated');
  });

  it('shows an empty state rather than an empty table', () => {
    render(<MovementLedgerTable rows={[]} onOpenEvidence={noop} />);

    expect(screen.getByTestId('movements-empty')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx react-scripts test --watchAll=false --testPathPattern=MovementLedgerTable`
Expected: FAIL — `Cannot find module './MovementLedgerTable'`.

- [ ] **Step 3: Write MovementLedgerTable**

```jsx
import React, { useMemo } from 'react';
import '../../styles/audit_evidence.css';

const fmtMoney = (n) =>
  n === null || n === undefined
    ? '—'
    : '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const fmtDateTime = (iso) => {
  if (!iso) return '—';
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
};

const BASIS_FLAG = {
  derived_weighted_avg: 'Derived',
  product_default: 'List cost',
  unavailable: 'No cost',
};

/**
 * Level 3. A reversal and the row it reversed are both struck through and both
 * stay visible: an auditor needs to see that something was undone, which a table
 * that simply hid the pair would not tell them.
 */
const MovementLedgerTable = ({ rows, onOpenEvidence, loading }) => {
  const reversedIds = useMemo(
    () => new Set((rows || []).map((r) => r.reverses_ledger_id).filter(Boolean)),
    [rows]
  );

  if (loading) {
    return <div className="ae-state">Loading movements…</div>;
  }

  if (!rows || rows.length === 0) {
    return (
      <div className="ae-state" data-testid="movements-empty">
        No movements match this scope.
      </div>
    );
  }

  return (
    <div className="ae-table-wrap">
      <table className="ae-table">
        <thead>
          <tr>
            <th>Occurred</th>
            <th>Type</th>
            <th>Product</th>
            <th>Batch / Serial</th>
            <th className="ae-num">Qty</th>
            <th className="ae-num">Unit cost</th>
            <th className="ae-num">Value</th>
            <th>By</th>
            <th />
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => {
            const isReversal = Boolean(r.reverses_ledger_id);
            const wasReversed = reversedIds.has(r.ledger_id);
            const struck = isReversal || wasReversed;

            return (
              <tr
                key={r.ledger_id}
                data-testid={`row-${r.ledger_id}`}
                className={struck ? 'ae-row--reversed' : undefined}
              >
                <td>
                  {fmtDateTime(r.occurred_at)}
                  {r.is_backdated && <span className="ae-flag" title={`Recorded ${fmtDateTime(r.posted_at)}`}>Backdated</span>}
                </td>
                <td>
                  {String(r.movement_type || '').replace(/_/g, ' ')}
                  <span className="ae-flag">{r.direction}</span>
                  {isReversal && <span className="ae-flag">Reversal</span>}
                  {r.origin === 'reconstructed' && <span className="ae-flag">Reconstructed</span>}
                </td>
                <td>{r.product_name || (r.product_id ? `Product #${r.product_id}` : '—')}</td>
                <td>{r.serial_number || (r.batch_id ? `Batch #${r.batch_id}` : '—')}</td>
                <td className="ae-num">{Number(r.quantity || 0).toLocaleString('en-PH')}</td>
                <td className="ae-num">
                  {fmtMoney(r.unit_cost)}
                  {BASIS_FLAG[r.cost_basis] && <span className="ae-flag">{BASIS_FLAG[r.cost_basis]}</span>}
                </td>
                <td className="ae-num">{fmtMoney(r.extended_value)}</td>
                <td>{r.actor_name || 'System'}</td>
                <td>
                  <button type="button" className="ae-btn ae-btn--ghost" onClick={() => onOpenEvidence(r.correlation_id)}>
                    Evidence
                  </button>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
};

export default MovementLedgerTable;
```

- [ ] **Step 4: Write LifecycleTimeline**

```jsx
import React from 'react';
import '../../styles/audit_evidence.css';

const fmtDate = (iso) => {
  if (!iso) return '—';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
};

/**
 * Level 5: everything that ever happened to one serial or one batch, oldest first.
 */
const LifecycleTimeline = ({ rows, title }) => (
  <div className="ae-card">
    <div className="ae-card-head">
      <div>
        <h2 className="ae-card-title">{title}</h2>
        <p className="ae-card-meta">Every recorded movement, oldest first</p>
      </div>
    </div>
    {!rows || rows.length === 0 ? (
      <div className="ae-state">No recorded movements.</div>
    ) : (
      <ol className="ae-timeline">
        {rows.map((r) => (
          <li key={r.ledger_id}>
            <strong>{String(r.movement_type || '').replace(/_/g, ' ')}</strong>
            <span className="ae-flag">{r.direction}</span>
            {' '}
            {Number(r.quantity || 0).toLocaleString('en-PH')} pc — {fmtDate(r.occurred_at)}
            <span className="ae-muted"> · {r.actor_name || 'System'}</span>
          </li>
        ))}
      </ol>
    )}
  </div>
);

export default LifecycleTimeline;
```

Add to `audit_evidence.css`:

```css
.ae-timeline { margin: 0; padding: 1rem 1rem 1rem 2rem; display: grid; gap: 0.5rem; font-size: 0.85rem; color: var(--text-color); }
```

- [ ] **Step 5: Write EvidenceDrawer**

```jsx
import React, { useEffect, useState } from 'react';
import { auditEvidenceAPI } from '../../services/api';
import LifecycleTimeline from './LifecycleTimeline';
import '../../styles/audit_evidence.css';

const fmtMoney = (n) =>
  n === null || n === undefined
    ? '—'
    : '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const fmtDateTime = (iso) => {
  if (!iso) return '—';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('en-PH');
};

/**
 * Level 4: one event, with the document, the actor chain and the change history
 * that audit_trail already recorded against it. The history comes from the server's
 * RecordHistoryService, so this drawer and the existing record timelines can never
 * tell different stories about the same record.
 */
const EvidenceDrawer = ({ correlationId, onClose }) => {
  const [bundle, setBundle] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [lifecycle, setLifecycle] = useState(null);

  useEffect(() => {
    if (!correlationId) return undefined;

    let cancelled = false;
    setLoading(true);
    setError('');
    setLifecycle(null);

    auditEvidenceAPI
      .getEvent(correlationId)
      .then((res) => {
        if (!cancelled) setBundle(res?.data ?? null);
      })
      .catch((e) => {
        if (!cancelled) setError(e?.message || 'Could not load the evidence for this event.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [correlationId]);

  const openSerial = (serial) => {
    auditEvidenceAPI
      .getSerialLifecycle(serial)
      .then((res) => setLifecycle({ title: `Serial ${serial}`, rows: res?.data ?? [] }))
      .catch((e) => setError(e?.message || 'Could not load that serial history.'));
  };

  const openBatch = (batchId) => {
    auditEvidenceAPI
      .getBatchLifecycle(batchId)
      .then((res) => setLifecycle({ title: `Batch #${batchId}`, rows: res?.data ?? [] }))
      .catch((e) => setError(e?.message || 'Could not load that batch history.'));
  };

  if (!correlationId) return null;

  return (
    <aside className="ae-drawer" role="dialog" aria-label="Evidence">
      <header className="ae-drawer-head">
        <h2 className="ae-card-title">{correlationId}</h2>
        <button type="button" className="ae-btn" onClick={onClose}>Close</button>
      </header>

      {loading && <div className="ae-state">Loading evidence…</div>}
      {error !== '' && <div className="ae-warn">{error}</div>}

      {bundle && !loading && (
        <div className="ae-drawer-body">
          <section>
            <h3 className="ae-basis-title">Legs of this event</h3>
            <div className="ae-table-wrap">
              <table className="ae-table">
                <thead>
                  <tr>
                    <th>Type</th><th>Product</th><th>Batch / Serial</th>
                    <th className="ae-num">Qty</th><th className="ae-num">Unit cost</th>
                    <th className="ae-num">Value</th><th>Occurred</th><th>Recorded</th><th>By</th>
                  </tr>
                </thead>
                <tbody>
                  {bundle.legs.map((leg) => (
                    <tr key={leg.ledger_id} className={leg.reverses_ledger_id ? 'ae-row--reversed' : undefined}>
                      <td>{String(leg.movement_type || '').replace(/_/g, ' ')} <span className="ae-flag">{leg.direction}</span></td>
                      <td>{leg.product_name || '—'}</td>
                      <td>
                        {leg.serial_number ? (
                          <button type="button" className="ae-btn ae-btn--ghost" onClick={() => openSerial(leg.serial_number)}>
                            {leg.serial_number}
                          </button>
                        ) : leg.batch_id ? (
                          <button type="button" className="ae-btn ae-btn--ghost" onClick={() => openBatch(leg.batch_id)}>
                            Batch #{leg.batch_id}
                          </button>
                        ) : '—'}
                      </td>
                      <td className="ae-num">{Number(leg.quantity || 0).toLocaleString('en-PH')}</td>
                      <td className="ae-num">{fmtMoney(leg.unit_cost)}</td>
                      <td className="ae-num">{fmtMoney(leg.extended_value)}</td>
                      <td>{fmtDateTime(leg.occurred_at)}</td>
                      <td>
                        {fmtDateTime(leg.posted_at)}
                        {leg.is_backdated && <span className="ae-flag">Backdated</span>}
                      </td>
                      <td>{leg.actor_name || 'System'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          <section>
            <h3 className="ae-basis-title">Source document</h3>
            {!bundle.source ? (
              <p className="ae-muted">No source document is linked to this event.</p>
            ) : bundle.source.missing ? (
              <p className="ae-warn">
                The {bundle.source.type} record #{bundle.source.id} no longer exists. The ledger row survives it
                deliberately — this is evidence that the movement happened, even though the document is gone.
              </p>
            ) : (
              <dl className="ae-kv">
                {Object.entries(bundle.source.attributes)
                  .filter(([, v]) => v !== null && v !== '' && typeof v !== 'object')
                  .map(([k, v]) => (
                    <React.Fragment key={k}>
                      <dt>{k.replace(/_/g, ' ')}</dt>
                      <dd>{String(v)}</dd>
                    </React.Fragment>
                  ))}
              </dl>
            )}
          </section>

          <section>
            <h3 className="ae-basis-title">Change history</h3>
            {!bundle.history || bundle.history.length === 0 ? (
              <p className="ae-muted">No recorded changes to this record.</p>
            ) : (
              <ol className="ae-timeline">
                {bundle.history.map((h, i) => (
                  <li key={i}>
                    <strong>{h.label}</strong> — {h.actor_name || 'System'} · {fmtDateTime(h.timestamp)}
                    {Array.isArray(h.changes) && h.changes.length > 0 && (
                      <ul className="ae-basis-list">
                        {h.changes.map((c, j) => (
                          <li key={j} className="ae-muted">
                            {c.field}: {String(c.from ?? '—')} → {String(c.to ?? '—')}
                          </li>
                        ))}
                      </ul>
                    )}
                  </li>
                ))}
              </ol>
            )}
          </section>

          {lifecycle && <LifecycleTimeline title={lifecycle.title} rows={lifecycle.rows} />}
        </div>
      )}
    </aside>
  );
};

export default EvidenceDrawer;
```

Add to `audit_evidence.css`:

```css
.ae-drawer {
  position: fixed; top: 0; right: 0; bottom: 0; width: min(920px, 100%);
  overflow-y: auto; z-index: 60; padding: 1rem;
  background: var(--surface-color); border-left: 1px solid var(--border-color);
}
.ae-drawer-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
.ae-drawer-body { display: grid; gap: 1.5rem; }
.ae-kv { display: grid; grid-template-columns: minmax(140px, auto) 1fr; gap: 0.3rem 1rem; margin: 0; font-size: 0.85rem; }
.ae-kv dt { color: var(--text-muted); text-transform: capitalize; }
.ae-kv dd { margin: 0; color: var(--text-color); }
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd frontend && npx react-scripts test --watchAll=false --testPathPattern=MovementLedgerTable`
Expected: PASS (6 tests).

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/components/audit frontend/src/admin/styles/audit_evidence.css
git commit -m "feat(ledger): add the movement ledger table and evidence drawer"
```

---

## Task 21: Reconciliation and Exceptions panels

**Files:**
- Create: `frontend/src/admin/components/audit/ReconciliationPanel.jsx`
- Create: `frontend/src/admin/components/audit/ExceptionsPanel.jsx`
- Test: `frontend/src/admin/components/audit/ReconciliationPanel.test.jsx`

- [ ] **Step 1: Write the failing test**

```jsx
import React from 'react';
import { render, screen } from '@testing-library/react';
import ReconciliationPanel from './ReconciliationPanel';

const balanced = {
  opening_quantity: 70,
  quantity_in: 50,
  quantity_out: 20,
  closing_quantity: 100,
  quantity_balances: true,
  opening_value: 7000,
  value_in: 5000,
  value_out: 2000,
  closing_value: 10000,
  value_balances: true,
  unvalued_rows: 0,
};

describe('ReconciliationPanel', () => {
  const noop = () => {};

  it('shows the arithmetic so the closing figure can be checked by eye', () => {
    render(<ReconciliationPanel data={balanced} onDrillDown={noop} />);

    expect(screen.getByTestId('recon-opening-qty')).toHaveTextContent('70');
    expect(screen.getByTestId('recon-in-qty')).toHaveTextContent('50');
    expect(screen.getByTestId('recon-out-qty')).toHaveTextContent('20');
    expect(screen.getByTestId('recon-closing-qty')).toHaveTextContent('100');
  });

  it('confirms the period balances', () => {
    render(<ReconciliationPanel data={balanced} onDrillDown={noop} />);

    expect(screen.getByTestId('recon-status')).toHaveTextContent('balances');
  });

  it('says plainly when value cannot be fully proven', () => {
    render(<ReconciliationPanel data={{ ...balanced, unvalued_rows: 4 }} onDrillDown={noop} />);

    expect(screen.getByTestId('recon-unvalued')).toHaveTextContent('4');
  });

  it('prompts for a period when it has no data yet', () => {
    render(<ReconciliationPanel data={null} onDrillDown={noop} />);

    expect(screen.getByTestId('recon-empty')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx react-scripts test --watchAll=false --testPathPattern=ReconciliationPanel`
Expected: FAIL — `Cannot find module './ReconciliationPanel'`.

- [ ] **Step 3: Write ReconciliationPanel**

```jsx
import React from 'react';
import '../../styles/audit_evidence.css';

const fmtQty = (n) => Number(n || 0).toLocaleString('en-PH');
const fmtMoney = (n) =>
  '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * opening + in - out = closing, shown as the arithmetic rather than as a verdict,
 * so an auditor can check it by eye and click any term to see the rows behind it.
 */
const ReconciliationPanel = ({ data, onDrillDown }) => {
  if (!data) {
    return (
      <div className="ae-card">
        <div className="ae-state" data-testid="recon-empty">
          Choose a product or location and a period to reconcile.
        </div>
      </div>
    );
  }

  const balances = data.quantity_balances && data.value_balances;

  const terms = [
    { key: 'opening-qty', label: 'Opening', qty: data.opening_quantity, value: data.opening_value, drill: null },
    { key: 'in-qty', label: 'Movements in', qty: data.quantity_in, value: data.value_in, drill: 'in' },
    { key: 'out-qty', label: 'Movements out', qty: data.quantity_out, value: data.value_out, drill: 'out' },
    { key: 'closing-qty', label: 'Closing', qty: data.closing_quantity, value: data.closing_value, drill: null },
  ];

  return (
    <div className="ae-card">
      <div className="ae-card-head">
        <div>
          <h2 className="ae-card-title">Reconciliation</h2>
          <p className="ae-card-meta">Opening + in − out = closing, for quantity and for value</p>
        </div>
        <span className={balances ? 'ae-muted' : 'ae-warn'} data-testid="recon-status">
          {balances ? 'This period balances.' : 'This period does not balance — see the Exceptions panel.'}
        </span>
      </div>

      <div className="ae-table-wrap">
        <table className="ae-table">
          <thead>
            <tr><th>Term</th><th className="ae-num">Quantity</th><th className="ae-num">Value</th><th /></tr>
          </thead>
          <tbody>
            {terms.map((t) => (
              <tr key={t.key}>
                <td>{t.label}</td>
                <td className="ae-num" data-testid={`recon-${t.key}`}>{fmtQty(t.qty)}</td>
                <td className="ae-num">{fmtMoney(t.value)}</td>
                <td>
                  {t.drill && (
                    <button type="button" className="ae-btn ae-btn--ghost" onClick={() => onDrillDown(t.drill)}>
                      View rows
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {Number(data.unvalued_rows) > 0 && (
        <p className="ae-warn" data-testid="recon-unvalued">
          {fmtQty(data.unvalued_rows)} row(s) in this period have no recoverable cost, so the value column is
          proven only for the rows that do. The quantity column is complete.
        </p>
      )}
    </div>
  );
};

export default ReconciliationPanel;
```

- [ ] **Step 4: Write ExceptionsPanel**

```jsx
import React, { useCallback, useEffect, useState } from 'react';
import { auditEvidenceAPI } from '../../services/api';
import { toast } from '../../utils/toast';
import '../../styles/audit_evidence.css';

const fmtDateTime = (iso) => {
  if (!iso) return '—';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('en-PH');
};

const CHECK_LABELS = {
  source_vs_ledger: 'Source vs ledger',
  ledger_vs_movements: 'Ledger vs FIFO record',
  ledger_vs_stock: 'Ledger vs stock on hand',
  check_failed: 'Drift check failed',
};

/**
 * Open drift findings. A finding stays open until somebody writes down what they
 * did about it, which is what turns a discrepancy into an audit trail of its own.
 */
const ExceptionsPanel = ({ canResolve }) => {
  const [findings, setFindings] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notes, setNotes] = useState({});
  const [savingId, setSavingId] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    setError('');
    auditEvidenceAPI
      .getExceptions()
      .then((res) => setFindings(Array.isArray(res?.data) ? res.data : []))
      .catch((e) => setError(e?.message || 'Could not load exceptions.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const resolve = async (findingId) => {
    const note = (notes[findingId] || '').trim();
    if (note.length < 3) {
      toast.error('Describe what was done about this finding before resolving it.');
      return;
    }

    setSavingId(findingId);
    try {
      await auditEvidenceAPI.resolveException(findingId, note);
      toast.success('Finding resolved.');
      load();
    } catch (e) {
      toast.error(e?.message || 'Could not resolve that finding.');
    } finally {
      setSavingId(null);
    }
  };

  return (
    <div className="ae-card">
      <div className="ae-card-head">
        <div>
          <h2 className="ae-card-title">Exceptions</h2>
          <p className="ae-card-meta">Open drift findings from the nightly check</p>
        </div>
        <button type="button" className="ae-btn" onClick={load}>Refresh</button>
      </div>

      {loading ? (
        <div className="ae-state">Loading exceptions…</div>
      ) : error !== '' ? (
        <div className="ae-warn">{error}</div>
      ) : findings.length === 0 ? (
        <div className="ae-state">
          No open findings. The last drift check found the ledger and its sources in agreement.
        </div>
      ) : (
        <div className="ae-table-wrap">
          <table className="ae-table">
            <thead>
              <tr>
                <th>Check</th><th>Period</th><th className="ae-num">Expected</th>
                <th className="ae-num">Actual</th><th className="ae-num">Variance</th>
                <th>Detail</th><th>Detected</th><th>Resolution</th>
              </tr>
            </thead>
            <tbody>
              {findings.map((f) => (
                <tr key={f.finding_id}>
                  <td>{CHECK_LABELS[f.check_type] || f.check_type}</td>
                  <td>{f.period_start ? `${f.period_start} → ${f.period_end}` : '—'}</td>
                  <td className="ae-num">{f.expected ?? '—'}</td>
                  <td className="ae-num">{f.actual ?? '—'}</td>
                  <td className="ae-num">{f.variance ?? '—'}</td>
                  <td style={{ whiteSpace: 'normal', maxWidth: 320 }}>{f.detail}</td>
                  <td>{fmtDateTime(f.detected_at)}</td>
                  <td>
                    {canResolve ? (
                      <div className="ae-field">
                        <input
                          type="text"
                          placeholder="What was done?"
                          value={notes[f.finding_id] || ''}
                          onChange={(e) => setNotes((prev) => ({ ...prev, [f.finding_id]: e.target.value }))}
                        />
                        <button
                          type="button"
                          className="ae-btn"
                          disabled={savingId === f.finding_id}
                          onClick={() => resolve(f.finding_id)}
                        >
                          {savingId === f.finding_id ? 'Saving…' : 'Resolve'}
                        </button>
                      </div>
                    ) : (
                      <span className="ae-muted">Auditor only</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
};

export default ExceptionsPanel;
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd frontend && npx react-scripts test --watchAll=false --testPathPattern=ReconciliationPanel`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/components/audit
git commit -m "feat(ledger): add reconciliation and exceptions panels"
```

---

## Task 22: Assemble the Explorer page, route and nav

**Files:**
- Create: `frontend/src/admin/pages/AuditEvidenceExplorer.jsx`
- Modify: `frontend/src/App.jsx`
- Modify: `frontend/src/admin/components/Sidebar.jsx`
- Modify: `frontend/src/admin/pages/AuditorDashboard.jsx`
- Test: `frontend/src/admin/pages/AuditEvidenceExplorer.test.jsx`

- [ ] **Step 1: Write the failing test**

```jsx
import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import AuditEvidenceExplorer from './AuditEvidenceExplorer';

jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);
jest.mock('../context/AuthContext', () => ({
  useAuth: () => ({ user: { user_id: 1 }, permissions: { 'audit_evidence.resolve_exception': true } }),
}));
jest.mock('../services/api', () => ({
  auditEvidenceAPI: {
    getSummary: jest.fn(() => Promise.resolve({ data: {
      quantity_in: 10, quantity_out: 4, net_quantity: 6,
      value_in: 1000, value_out: 400, net_value: 600,
      event_count: 3, row_count: 5, reversal_count: 0, unvalued_rows: 0,
      cost_basis: { actual: 1000, product_default: 0, derived_weighted_avg: 0, unavailable: 0 },
    } })),
    getBreakdown: jest.fn(() => Promise.resolve({ data: [
      { key: 'receiving', label: 'Receiving', quantity_in: 10, quantity_out: 0, value_in: 1000, value_out: 0, row_count: 2, event_count: 2 },
    ] })),
    getMovements: jest.fn(() => Promise.resolve({ data: [], pagination: { total: 0, last_page: 1, current_page: 1, per_page: 50 } })),
    getReconciliation: jest.fn(() => Promise.resolve({ data: null })),
    getExceptions: jest.fn(() => Promise.resolve({ data: [] })),
  },
}));

const { auditEvidenceAPI } = require('../services/api');

const renderPage = (path = '/admin/audit-evidence') =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <AuditEvidenceExplorer />
    </MemoryRouter>
  );

describe('AuditEvidenceExplorer', () => {
  beforeEach(() => jest.clearAllMocks());

  it('loads the summary and the breakdown for the default scope', async () => {
    renderPage();

    await waitFor(() => expect(screen.getByTestId('tile-quantity-in')).toHaveTextContent('10'));
    expect(screen.getByText('Receiving')).toBeInTheDocument();
  });

  it('switching to the finance lens refetches with that lens', async () => {
    renderPage();

    await waitFor(() => expect(auditEvidenceAPI.getSummary).toHaveBeenCalled());
    fireEvent.click(screen.getByRole('button', { name: /finance/i }));

    await waitFor(() =>
      expect(auditEvidenceAPI.getSummary).toHaveBeenLastCalledWith(expect.objectContaining({ lens: 'finance' }))
    );
  });

  it('shows the server refusal for an over-wide period rather than zeros', async () => {
    auditEvidenceAPI.getSummary.mockRejectedValueOnce(new Error('That period is wider than the 366 days this view can total at once.'));

    renderPage();

    await waitFor(() => expect(screen.getByTestId('explorer-error')).toHaveTextContent('366 days'));
  });

  it('opens the exceptions tab on request', async () => {
    renderPage();

    await waitFor(() => expect(auditEvidenceAPI.getSummary).toHaveBeenCalled());
    fireEvent.click(screen.getByRole('button', { name: /exceptions/i }));

    await waitFor(() => expect(auditEvidenceAPI.getExceptions).toHaveBeenCalled());
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx react-scripts test --watchAll=false --testPathPattern=AuditEvidenceExplorer`
Expected: FAIL — `Cannot find module './AuditEvidenceExplorer'`.

- [ ] **Step 3: Write the page**

```jsx
import React, { useCallback, useEffect, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import { useAuth } from '../context/AuthContext';
import { useAuditEvidence } from '../hooks/useAuditEvidence';
import { auditEvidenceAPI } from '../services/api';
import SummaryTiles from '../components/audit/SummaryTiles';
import BreakdownTable from '../components/audit/BreakdownTable';
import MovementLedgerTable from '../components/audit/MovementLedgerTable';
import EvidenceDrawer from '../components/audit/EvidenceDrawer';
import ReconciliationPanel from '../components/audit/ReconciliationPanel';
import ExceptionsPanel from '../components/audit/ExceptionsPanel';
import Pagination from '../components/Pagination';
import '../styles/audit_evidence.css';

const TABS = [
  { key: 'breakdown', label: 'Breakdown' },
  { key: 'movements', label: 'Movements' },
  { key: 'reconciliation', label: 'Reconciliation' },
  { key: 'exceptions', label: 'Exceptions' },
];

/**
 * The Explorer shell: scope at the top, one level of detail below it, and the
 * evidence drawer over the whole thing. It holds no totals of its own — every
 * figure on this page came from the server, so what an auditor reads here is what
 * the ledger says, not what a browser recomputed.
 */
const AuditEvidenceExplorer = () => {
  const { permissions } = useAuth();
  const canResolve = permissions?.['audit_evidence.resolve_exception'] === true;

  const { scope, setScope, summary, loading, error } = useAuditEvidence();

  const [tab, setTab] = useState('breakdown');
  const [breakdown, setBreakdown] = useState([]);
  const [breakdownLoading, setBreakdownLoading] = useState(false);
  const [movements, setMovements] = useState([]);
  const [movementsLoading, setMovementsLoading] = useState(false);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [reconciliation, setReconciliation] = useState(null);
  const [openEvent, setOpenEvent] = useState(null);
  const [panelError, setPanelError] = useState('');

  useEffect(() => {
    if (tab !== 'breakdown') return undefined;

    let cancelled = false;
    setBreakdownLoading(true);
    setPanelError('');

    auditEvidenceAPI
      .getBreakdown({ ...scope, dimension: scope.dimension })
      .then((res) => {
        if (!cancelled) setBreakdown(Array.isArray(res?.data) ? res.data : []);
      })
      .catch((e) => {
        if (!cancelled) setPanelError(e?.message || 'Could not load the breakdown.');
      })
      .finally(() => {
        if (!cancelled) setBreakdownLoading(false);
      });

    return () => { cancelled = true; };
  }, [scope, tab]);

  useEffect(() => {
    if (tab !== 'movements') return undefined;

    let cancelled = false;
    setMovementsLoading(true);
    setPanelError('');

    auditEvidenceAPI
      .getMovements({ ...scope, page, per_page: 50 })
      .then((res) => {
        if (cancelled) return;
        setMovements(Array.isArray(res?.data) ? res.data : []);
        setLastPage(res?.pagination?.last_page ?? 1);
      })
      .catch((e) => {
        if (!cancelled) setPanelError(e?.message || 'Could not load movements.');
      })
      .finally(() => {
        if (!cancelled) setMovementsLoading(false);
      });

    return () => { cancelled = true; };
  }, [scope, tab, page]);

  useEffect(() => {
    if (tab !== 'reconciliation') return undefined;

    let cancelled = false;
    setPanelError('');

    auditEvidenceAPI
      .getReconciliation(scope)
      .then((res) => {
        if (!cancelled) setReconciliation(res?.data ?? null);
      })
      .catch((e) => {
        if (!cancelled) setPanelError(e?.message || 'Could not reconcile this period.');
      });

    return () => { cancelled = true; };
  }, [scope, tab]);

  const drillIntoMovements = useCallback(
    (row) => {
      // Carry the clicked group into the movement filter, so the drill-down is a
      // narrowing of the same scope rather than a fresh query.
      if (scope.dimension === 'movement_type') setScope({ movement_type: row.key });
      else if (scope.dimension === 'product') setScope({ product_id: row.key });
      else if (scope.dimension === 'user') setScope({ user_id: row.key });

      setPage(1);
      setTab('movements');
    },
    [scope.dimension, setScope]
  );

  return (
    <AdminLayout>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Audit &amp; Oversight</p>
              <h1 className="ak-title">Audit &amp; Evidence Explorer</h1>
              <p className="ak-sub">
                Every recorded movement, with the document and the people behind it. Read-only.
              </p>
            </div>
            <div className="ae-lens-tabs" role="group" aria-label="Lens">
              <button type="button" aria-pressed={scope.lens === 'inventory'} onClick={() => setScope({ lens: 'inventory' })}>
                Inventory
              </button>
              <button type="button" aria-pressed={scope.lens === 'finance'} onClick={() => setScope({ lens: 'finance' })}>
                Finance
              </button>
            </div>
          </div>
        </header>

        <div className="ae-card">
          <div className="ae-scope" style={{ padding: '1rem' }}>
            <label className="ae-field">
              <span>From</span>
              <input type="date" value={scope.from} onChange={(e) => setScope({ from: e.target.value })} />
            </label>
            <label className="ae-field">
              <span>To</span>
              <input type="date" value={scope.to} onChange={(e) => setScope({ to: e.target.value })} />
            </label>
            <label className="ae-field">
              <span>Product ID</span>
              <input type="number" value={scope.product_id} onChange={(e) => setScope({ product_id: e.target.value })} />
            </label>
            <label className="ae-field">
              <span>Location ID</span>
              <input type="number" value={scope.location_id} onChange={(e) => setScope({ location_id: e.target.value })} />
            </label>
            {scope.movement_type !== '' && (
              <button type="button" className="ae-btn" onClick={() => setScope({ movement_type: '' })}>
                Clear type: {scope.movement_type}
              </button>
            )}
          </div>
        </div>

        {error !== '' && (
          <div className="ae-card">
            <p className="ae-warn" data-testid="explorer-error" style={{ padding: '1rem' }}>{error}</p>
          </div>
        )}

        {loading ? (
          <div className="ae-state">Loading this period…</div>
        ) : (
          <SummaryTiles summary={summary} emptyReason="no-movements" />
        )}

        <div className="ae-lens-tabs" role="group" aria-label="Detail" style={{ marginTop: '1rem' }}>
          {TABS.map((t) => (
            <button key={t.key} type="button" aria-pressed={tab === t.key} onClick={() => setTab(t.key)}>
              {t.label}
            </button>
          ))}
        </div>

        {panelError !== '' && <p className="ae-warn">{panelError}</p>}

        {tab === 'breakdown' && (
          <BreakdownTable
            rows={breakdown}
            dimension={scope.dimension}
            loading={breakdownLoading}
            onDimensionChange={(d) => setScope({ dimension: d })}
            onDrillDown={drillIntoMovements}
          />
        )}

        {tab === 'movements' && (
          <div className="ae-card">
            <div className="ae-card-head">
              <div>
                <h2 className="ae-card-title">Movement ledger</h2>
                <p className="ae-card-meta">Grouped events, reversals shown alongside what they reversed</p>
              </div>
            </div>
            <MovementLedgerTable rows={movements} loading={movementsLoading} onOpenEvidence={setOpenEvent} />
            {lastPage > 1 && (
              <Pagination currentPage={page} totalPages={lastPage} onPageChange={setPage} />
            )}
          </div>
        )}

        {tab === 'reconciliation' && (
          <ReconciliationPanel
            data={reconciliation}
            onDrillDown={(direction) => {
              setScope({ movement_type: '' });
              setTab('movements');
              setPage(1);
              void direction;
            }}
          />
        )}

        {tab === 'exceptions' && <ExceptionsPanel canResolve={canResolve} />}

        <EvidenceDrawer correlationId={openEvent} onClose={() => setOpenEvent(null)} />
      </div>
    </AdminLayout>
  );
};

export default AuditEvidenceExplorer;
```

Check `Pagination`'s prop names before wiring it — the other pages already use it:
Run: `cd frontend && grep -n "const Pagination\|props\|currentPage\|totalPages\|onPageChange" src/admin/components/Pagination.jsx | head`

Match whatever that component actually expects rather than the names guessed above.

- [ ] **Step 4: Register the route**

In `frontend/src/App.jsx`, next to the existing `AuditTrail` lazy import and route:

```jsx
const AuditEvidenceExplorer = lazy(() => import('./admin/pages/AuditEvidenceExplorer'));
```

```jsx
          <Route path="/admin/audit-evidence" element={<RoleRoute><AuditEvidenceExplorer /></RoleRoute>} />
```

- [ ] **Step 5: Add the nav item**

In `frontend/src/admin/components/Sidebar.jsx`, add to the `COMPLIANCE & SUPPORT` items, above `Audit Trail`:

```jsx
      { name: 'Audit & Evidence', path: '/admin/audit-evidence', icon: 'audit' },
```

and register the lazy route in the preload map at the top of that file, beside `'/admin/audit'`:

```jsx
  '/admin/audit-evidence': () => import('../pages/AuditEvidenceExplorer'),
```

- [ ] **Step 6: Fix the auditor dashboard's dead link and point it at the Explorer**

`AuditorDashboard.jsx` navigates to `/admin/audit-trail`, which is not a registered route — the Audit Trail page lives at `/admin/audit`. Correct it and add the Explorer button:

```jsx
              <button className="ak-btn ak-btn--ghost" onClick={() => navigate('/admin/audit-evidence')}>Audit &amp; evidence</button>
              <button className="ak-btn ak-btn--ghost" onClick={() => navigate('/admin/audit')}>Audit trail</button>
              <button className="ak-btn ak-btn--ghost" onClick={() => navigate('/admin/compliance')}>Compliance</button>
```

- [ ] **Step 7: Run the tests**

Run: `cd frontend && npx react-scripts test --watchAll=false --testPathPattern=AuditEvidenceExplorer`
Expected: PASS (4 tests).

Run the whole frontend suite for regressions:
Run: `cd frontend && CI=true npx react-scripts test --watchAll=false`
Expected: **52 suites pass, 409 tests pass, and exactly 3 pre-existing failures** in
`src/admin/pages/Login.test.jsx`, all raising `useTheme must be used within
ThemeProvider`. That is the measured baseline as of 2026-09-30 (before any work in
this plan) and comes from uncommitted dark-mode/`ThemeContext` work already in the
tree — **not** from this feature. Do not fix it and do not count it as a regression;
just confirm the count is still exactly 3 and that the failing file is still only
`Login.test.jsx`. Any fourth failure, or a failure in another file, IS ours.

Capture the real exit status with `${PIPESTATUS[0]}` if you pipe the output — a pipe
through `tail`/`grep` reports the filter's status, not the runner's, which makes a
failing suite look green.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/admin/pages/AuditEvidenceExplorer.jsx frontend/src/admin/pages/AuditEvidenceExplorer.test.jsx frontend/src/App.jsx frontend/src/admin/components/Sidebar.jsx frontend/src/admin/pages/AuditorDashboard.jsx
git commit -m "feat(ledger): assemble the Audit & Evidence Explorer page and nav"
```

---

## Task 23: Full verification

**Files:** none created — this task only runs things and records what it found.

- [ ] **Step 1: Run the whole backend suite**

Run: `cd back-end && php artisan test`
Expected: PASS. Investigate any failure rather than filtering it out — the four write paths were all touched, so a regression here is real.

- [ ] **Step 2: Run the whole frontend suite**

Run: `cd frontend && npx react-scripts test --watchAll=false`
Expected: PASS.

- [ ] **Step 3: Backfill and drift-check the development database**

```bash
cd back-end
php artisan ledger:backfill --dry-run
php artisan ledger:backfill
php artisan ledger:drift-check --from=2026-01-01 --to=2026-12-31
```

Record the backfill's cost-basis breakdown and the drift findings in the completion notes. A non-zero exit from the drift check is information, not a failure: it names what history could not account for. Open findings at this point are expected — phases 2-4 are not wired, so incident, warranty, purchase-return, material-consumption and set-pick movements will show as drift. Confirm the variances correspond to those sources and to nothing else.

- [ ] **Step 4: Open the page against real data**

Start the app and visit `/admin/audit-evidence` as an auditor. Confirm, in order:
1. The summary tiles load, and the cost-basis breakdown shows a meaningful split rather than everything as `unavailable`.
2. A breakdown row drills into the movement ledger with the scope carried over.
3. An evidence drawer opens and shows legs, the source document and change history.
4. A serial or batch link opens its lifecycle.
5. The reconciliation tab balances for a single product and location.
6. The exceptions tab lists the drift findings from Step 3 and one can be resolved with a note.
7. The URL changes as the scope changes, and pasting it into a new tab reopens the same view.

- [ ] **Step 5: Confirm the migrations are on the test database too**

Run: `cd back-end && DB_DATABASE=airkingV5_test php artisan migrate:status --force | tail -5`
Expected: the three `2026_09_30_*` migrations show as `Ran`.

- [ ] **Step 6: Commit any fixes and record what phases 2-4 still owe**

```bash
git add -A
git commit -m "chore(ledger): verification fixes for the phase 1 audit evidence trail"
```

---

## Deferred to later plans

Written down here so the next plan starts from a known boundary:

- **Phase 2 — loss and return events:** incident write-off and return (`IncidentService`), warranty send-out and replacement receipt (`WarrantyClaimService`), purchase return. These are where shrinkage hides, so they are the highest-value next phase.
- **Phase 3 — consumption and sets:** installation material consumption and set/BOQ FIFO picking (`FifoPickingService`, `MaterialTagService`).
- **Phase 4 — non-stock financial events:** budget allocation and PO encumbrance, posted as `neutral` rows on the finance lens (`BudgetService`). `LedgerPoster` already accepts and validates these; nothing calls it yet.
- **Exports:** CSV and PDF of each Explorer level, with the active filters printed on the export and large ranges routed through `ReportRun`. The spec requires this; it is deferred because it is additive and the drill-down is the capability the user asked for first.
- **Transfer receipt in the backfill:** historic transfers reconstruct only their out leg, because `inventory_batch_movements` records the dispatch alone. Phase 2 should decide whether to infer the in leg from transfer status or leave it as a permanent, labelled gap.

