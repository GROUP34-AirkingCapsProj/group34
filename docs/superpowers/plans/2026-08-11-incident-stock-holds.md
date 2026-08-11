# Incident Stock Holds Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make stock that exists but is not sellable a real, countable thing — a `stock_holds` ledger with condition, quantity and disposition — and correct the two incident resolutions that are currently wrong in production.

**Architecture:** Approving an incident no longer deletes the quantity. It deducts from active stock *and* writes a hold row, preserving the invariant `physical = active + held`. A second, explicit disposition step decides where held stock goes. `StockHoldService` is the sole mutator of the table, mirroring the `BudgetService` discipline already in this codebase. The eight incident reasons collapse to six, with cause moved out of the reason and into reportable fields.

**Tech Stack:** Laravel 12 (PHP 8.2), MariaDB, PHPUnit with `DatabaseTransactions`; React 18 (CRA) frontend with Jest + `@testing-library/react`.

**Spec:** `docs/superpowers/specs/2026-08-11-incident-stock-holds-design.md`

---

## Before you start — read this

Several of these have already cost a full debugging cycle on this feature.

- **The schema is a SQL dump, not a migration history.** A bare `php artisan migrate` fails. Apply a single migration with `--path`, and apply it to **both** databases.

  **Verified during Task 1 — use exactly this.** There is no `.env.testing`, so `--env=testing` silently falls back to `.env` and migrates the **dev** database while appearing to succeed. `--database=` does not help either: there is one `mysql` connection and its name comes from `env()`. The test database is configured only by `<env>` entries in `phpunit.xml`, which artisan never reads. An OS environment variable overrides phpdotenv's non-overriding load, so from `back-end/` in the **Bash** tool:
  ```bash
  # dev
  php artisan migrate --path=database/migrations/<file>.php --force
  # test
  DB_DATABASE=airkingV5_test php artisan migrate --path=database/migrations/<file>.php --force
  DB_DATABASE=airkingV5_test php artisan db:seed --class=<Seeder> --force
  ```
  Always confirm with a `SHOW TABLES` / `SHOW COLUMNS` check against **both** database names before moving on.

- **Install-specific ids differ between the two databases.** The statuses seeded in Task 3 came out as Missing/Defective/Returned = **50/51/52** in `airkingv5` but **1779/1780/1781** in `airkingV5_test`, because the test database's auto-increment has been pushed up by factory churn. Resolve every status by name through `StockHold::inventoryStatusId()` — a hardcoded literal will pass locally and fail under PHPUnit.
- Tests use `DatabaseTransactions`, **never** `RefreshDatabase`. `phpunit.xml` points at `airkingV5_test`.
- **Known pre-existing backend failures:** 9 `WarrantyClaimTest`, plus the order-dependent `SerialRowMovementColumnsTest`. Treat 9 or 10 as the baseline and confirm any failure is one of those.
- **Known pre-existing frontend failures:** 3 suites — `App.test.jsx` (CRA boilerplate), `navConfigureMap` (`stock_transfers` nav reachability), `InventoryManagement` (Warehouse Map tab). Baseline is **111 passing / 5 failing**.
- Verify frontend work with `npm run build` **and** `npx eslint` on each changed file. The build compiles code ESLint rejects, and a file nothing imports is never linted at all.
- **~20 files are dirty in the working tree** from unrelated work, including `airkingv5.sql`. Stage only the files each task names. Never `git add -A` or `git add .`.
- PowerShell here-strings break `git commit -m`. Use the Bash tool with `git commit -F - <<'EOF' … EOF`.
- Quantities are **PIECES** everywhere. `packageFormat.js` re-expresses for display and entry only.
- Do not push. The branch is shared.

## Naming — fixed across every task

Use these exact names. A rename halfway through is a bug.

| Thing | Name |
|---|---|
| Table / PK | `stock_holds` / `hold_id` |
| Model | `App\Models\StockHold` |
| Service | `App\Services\StockHoldService`, methods `hold()` and `dispose()` |
| Conditions | `missing` · `defective` · `returned` |
| Hold status | `held` · `released` |
| Dispositions | `restocked` · `defective` · `returned_to_supplier` · `scrapped` |
| Incident reasons | `missing` · `defective` · `return` · `wrong_type` · `zone_mismatch` · `other` |
| Strategies | `MissingResolution`, `DefectiveResolution`, `ReturnResolution`, `RetypeResolution`, `RelocateResolution`, `AcknowledgeResolution` |

## File structure

| File | Status | Responsibility |
|---|---|---|
| `database/migrations/2026_08_11_000001_create_stock_holds.php` | create | the ledger table |
| `database/migrations/2026_08_11_000002_add_cause_fields_to_incident_reports.php` | create | per-type cause fields |
| `database/migrations/2026_08_11_000003_migrate_incident_reasons.php` | create | 8 reasons → 6, data + column |
| `database/seeders/StockHoldStatusSeeder.php` | create | Missing/Defective/Returned statuses |
| `app/Models/StockHold.php` | create | constants, relations, scopes |
| `app/Services/StockHoldService.php` | create | **sole mutator** — `hold()`, `dispose()` |
| `app/Services/Incidents/MissingResolution.php` | create | deduct + `missing` hold |
| `app/Services/Incidents/DefectiveResolution.php` | create | deduct + `defective` hold |
| `app/Services/Incidents/ReturnResolution.php` | create | deduct + `returned` hold (replaces `RestockResolution`) |
| `app/Services/Incidents/WriteOffResolution.php` | **delete** | split into Missing + Defective |
| `app/Services/Incidents/RestockResolution.php` | **delete** | replaced by `ReturnResolution` |
| `app/Services/Incidents/RelocateResolution.php` | modify | **revert** to record-only |
| `app/Services/IncidentService.php` | modify | new `strategyFor` map |
| `app/Models/IncidentReport.php` | modify | six reasons, new fillable |
| `app/Http/Controllers/Api/StockHoldController.php` | create | `index`, `dispose` |
| `routes/api.php` | modify | two routes |
| `database/seeders/PermissionSeeder.php` | modify | `incident_reports.dispose` |
| `frontend/src/admin/utils/incidentReasons.js` | modify | six types + cause options |
| `frontend/src/admin/components/incident/steps/*.jsx` | modify | per-type cause fields |
| `frontend/src/admin/pages/StockHoldsPage.jsx` | create | held-stock worklist + Dispose |
| `frontend/src/admin/components/IncidentResolveDrawer.jsx` | modify | copy: "moves N pcs to Defective" |

---

### Task 1: The `stock_holds` table

**Files:**
- Create: `back-end/database/migrations/2026_08_11_000001_create_stock_holds.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock that exists but is not sellable.
 *
 * Before this table, resolving a write-off deleted the quantity outright: the
 * batch and the location were decremented and the units were simply gone from
 * the database, while physically sitting in a corner of the warehouse. One row
 * here is a quantity of one product, at one location, in one condition,
 * traceable to the incident that put it there.
 *
 * Holds both serialized appliances (serial_id set, quantity 1) and
 * non-serialized installation materials (serial_id null, quantity > 1) — which
 * is why this is a table rather than a status flag on item_serial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_holds', function (Blueprint $table) {
            $table->bigIncrements('hold_id');

            $table->unsignedBigInteger('incident_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            // Which batch it left, so a `restocked` disposition knows where to
            // put it back. Null when the incident named no batch.
            $table->unsignedBigInteger('batch_id')->nullable();
            // The exact unit, for appliances. Null for materials.
            $table->unsignedBigInteger('serial_id')->nullable();

            $table->string('condition', 20);        // missing | defective | returned
            $table->integer('quantity');            // PIECES; always 1 when serial_id is set
            $table->string('status', 20)->default('held');   // held | released

            $table->string('disposition', 30)->nullable();   // null while held
            $table->text('disposition_note')->nullable();
            $table->unsignedBigInteger('disposed_by')->nullable();
            $table->dateTime('disposed_at')->nullable();

            $table->timestamps();

            // "What is held at this location right now" is the query the
            // worklist page runs on every load.
            $table->index(['location_id', 'status', 'condition'], 'stock_holds_worklist_idx');
            $table->index('incident_id');
            $table->index('product_id');

            $table->foreign('incident_id')->references('incident_id')->on('incident_reports');
            $table->foreign('product_id')->references('product_id')->on('products');
            $table->foreign('location_id')->references('location_id')->on('locations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_holds');
    }
};
```

- [ ] **Step 2: Apply to both databases**

```bash
cd back-end
php artisan migrate --path=database/migrations/2026_08_11_000001_create_stock_holds.php
```

Then the test database. Check `config/database.php` for the test connection name first; if `phpunit.xml` sets `DB_DATABASE=airkingV5_test` via env, use:

```bash
php artisan migrate --path=database/migrations/2026_08_11_000001_create_stock_holds.php --env=testing
```

Expected: `INFO  Running migrations.` then the migration name with `DONE`, twice.

- [ ] **Step 3: Verify the table exists in both**

```bash
php -r '
foreach (["airkingv5","airkingV5_test"] as $db) {
  $c = new mysqli("127.0.0.1","root","",$db);
  $r = $c->query("SHOW TABLES LIKE \"stock_holds\"");
  echo str_pad($db,20) . ($r->num_rows ? "OK" : "MISSING") . "\n";
}'
```

Expected: `OK` for both. If either says MISSING, stop — every later task depends on this.

- [ ] **Step 4: Commit**

```bash
git add back-end/database/migrations/2026_08_11_000001_create_stock_holds.php
git commit -F - <<'EOF'
feat(holds): stock_holds ledger table

One row is a quantity of one product, at one location, in one condition,
traceable to the incident that put it there. A table rather than a status
flag because 128 of the 336 products carry no serials at all.
EOF
```

---

### Task 2: Cause fields on `incident_reports`

**Files:**
- Create: `back-end/database/migrations/2026_08_11_000002_add_cause_fields_to_incident_reports.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cause moves out of the reason and into reportable fields.
 *
 * `theft` and `not_found` used to be separate reasons; they are now one
 * Missing type with a suspected cause, so the cause can be reported on
 * without the reason list growing a row per nuance.
 *
 * All nullable: every existing incident predates these columns, and only one
 * type populates each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_reports', function (Blueprint $table) {
            // Missing
            $table->string('last_known_location', 150)->nullable()->after('actual_zone');
            $table->string('suspected_cause', 20)->nullable()->after('last_known_location');
            // Defective
            $table->string('root_cause', 20)->nullable()->after('suspected_cause');
            // Return
            $table->string('return_direction', 20)->nullable()->after('root_cause');
            $table->string('return_reason', 150)->nullable()->after('return_direction');
            $table->unsignedBigInteger('related_transfer_id')->nullable()->after('related_issuance_id');
        });
    }

    public function down(): void
    {
        Schema::table('incident_reports', function (Blueprint $table) {
            $table->dropColumn([
                'last_known_location', 'suspected_cause', 'root_cause',
                'return_direction', 'return_reason', 'related_transfer_id',
            ]);
        });
    }
};
```

No foreign key on `related_transfer_id`: the transfers table is being consolidated under separate work, and a FK here would couple this migration to that one.

- [ ] **Step 2: Apply to both databases**

```bash
cd back-end
php artisan migrate --path=database/migrations/2026_08_11_000002_add_cause_fields_to_incident_reports.php
php artisan migrate --path=database/migrations/2026_08_11_000002_add_cause_fields_to_incident_reports.php --env=testing
```

- [ ] **Step 3: Verify**

```bash
php -r '
$c = new mysqli("127.0.0.1","root","","airkingV5_test");
$r = $c->query("SHOW COLUMNS FROM incident_reports");
$cols = [];
while ($x = $r->fetch_assoc()) $cols[] = $x["Field"];
foreach (["last_known_location","suspected_cause","root_cause","return_direction","return_reason","related_transfer_id"] as $w)
  echo str_pad($w,24) . (in_array($w,$cols) ? "OK" : "MISSING") . "\n";'
```

Expected: six `OK` lines.

- [ ] **Step 4: Commit**

```bash
git add back-end/database/migrations/2026_08_11_000002_add_cause_fields_to_incident_reports.php
git commit -F - <<'EOF'
feat(incidents): per-type cause fields

Cause moves out of the reason and into fields that can be reported on,
so Missing carries its suspected cause rather than needing a reason per
nuance.
EOF
```

---

### Task 3: The three new statuses

**Files:**
- Create: `back-end/database/seeders/StockHoldStatusSeeder.php`

- [ ] **Step 1: Write the seeder**

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Missing, Defective and Returned as inventory statuses.
 *
 * Category `inventory` is where `In Stock` (26) already lives and what
 * item_serial.status_id already points at, so a serial lookup can tell the
 * truth about a held unit without consulting stock_holds.
 *
 * Idempotent: updateOrInsert on the natural key, so re-running is safe.
 */
class StockHoldStatusSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Missing', 'Defective', 'Returned'] as $name) {
            DB::table('status_lookup')->updateOrInsert(
                ['status_category' => 'inventory', 'status_name' => $name],
                ['is_active' => 1, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }
}
```

- [ ] **Step 2: Run it against both databases**

```bash
cd back-end
php artisan db:seed --class=StockHoldStatusSeeder
php artisan db:seed --class=StockHoldStatusSeeder --env=testing
```

Expected: `INFO  Seeding database.` with no errors.

- [ ] **Step 3: Verify**

```bash
php -r '
foreach (["airkingv5","airkingV5_test"] as $db) {
  $c = new mysqli("127.0.0.1","root","",$db);
  $r = $c->query("SELECT status_id, status_name FROM status_lookup WHERE status_category=\"inventory\" ORDER BY status_name");
  echo "$db:\n";
  while ($x = $r->fetch_assoc()) echo "  {$x[status_id]} {$x[status_name]}\n";
}'
```

Expected: each database lists Defective, In Stock, Low Stock, Missing, Out of Stock, Returned. Note the new ids — later tasks read them by name, never by literal.

- [ ] **Step 4: Commit**

```bash
git add back-end/database/seeders/StockHoldStatusSeeder.php
git commit -F - <<'EOF'
feat(holds): Missing, Defective and Returned inventory statuses

Category `inventory` because that is where In Stock lives and what
item_serial.status_id already points at.
EOF
```

---

### Task 4: `StockHold` model

**Files:**
- Create: `back-end/app/Models/StockHold.php`

- [ ] **Step 1: Write the model**

```php
<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserLocation;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A quantity of stock that exists but is not in active inventory.
 *
 * Never write to this table directly — StockHoldService is the sole mutator,
 * because creating a hold and deducting active stock have to happen together
 * or the invariant `physical = active + held` silently breaks.
 */
class StockHold extends Model
{
    use Auditable, ScopesToUserLocation;

    public const CONDITION_MISSING   = 'missing';
    public const CONDITION_DEFECTIVE = 'defective';
    public const CONDITION_RETURNED  = 'returned';

    public const CONDITIONS = [
        self::CONDITION_MISSING,
        self::CONDITION_DEFECTIVE,
        self::CONDITION_RETURNED,
    ];

    public const STATUS_HELD     = 'held';
    public const STATUS_RELEASED = 'released';

    public const DISPOSITION_RESTOCKED            = 'restocked';
    public const DISPOSITION_DEFECTIVE            = 'defective';
    public const DISPOSITION_RETURNED_TO_SUPPLIER = 'returned_to_supplier';
    public const DISPOSITION_SCRAPPED             = 'scrapped';

    /**
     * Which dispositions each condition allows.
     *
     * `missing` is absent entirely: it is terminal on approval. An item that
     * turns up later comes back through a new report, deliberately, rather
     * than by quietly reversing a loss record.
     *
     * `defective` cannot be disposed as `defective` (it already is) and cannot
     * be `restocked` — there must be no one-click path that puts a known-bad
     * unit back into picking.
     */
    public const ALLOWED_DISPOSITIONS = [
        self::CONDITION_RETURNED => [
            self::DISPOSITION_RESTOCKED,
            self::DISPOSITION_DEFECTIVE,
            self::DISPOSITION_RETURNED_TO_SUPPLIER,
            self::DISPOSITION_SCRAPPED,
        ],
        self::CONDITION_DEFECTIVE => [
            self::DISPOSITION_RETURNED_TO_SUPPLIER,
            self::DISPOSITION_SCRAPPED,
        ],
        self::CONDITION_MISSING => [],
    ];

    /** The serial status a held unit carries, by condition. */
    public const SERIAL_STATUS_NAME = [
        self::CONDITION_MISSING   => 'Missing',
        self::CONDITION_DEFECTIVE => 'Defective',
        self::CONDITION_RETURNED  => 'Returned',
    ];

    protected $table      = 'stock_holds';
    protected $primaryKey = 'hold_id';

    protected $fillable = [
        'incident_id', 'product_id', 'location_id', 'batch_id', 'serial_id',
        'condition', 'quantity', 'status',
        'disposition', 'disposition_note', 'disposed_by', 'disposed_at',
    ];

    protected $casts = [
        'quantity'    => 'integer',
        'disposed_at' => 'datetime',
    ];

    /** @var array<string,int> */
    private static array $statusCache = [];

    /**
     * Resolve an inventory status id by name. Mirrors IncidentReport::statusId
     * — install-specific ids are never hardcoded.
     */
    public static function inventoryStatusId(string $name): int
    {
        if (isset(self::$statusCache[$name])) {
            return self::$statusCache[$name];
        }

        $id = DB::table('status_lookup')
            ->where('status_category', 'inventory')
            ->where('status_name', $name)
            ->value('status_id');

        if ($id === null) {
            throw new \RuntimeException("Unknown inventory status: {$name}. Run StockHoldStatusSeeder.");
        }

        return self::$statusCache[$name] = (int) $id;
    }

    public function scopeHeld(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_HELD);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(IncidentReport::class, 'incident_id', 'incident_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function disposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disposed_by', 'user_id');
    }
}
```

- [ ] **Step 2: Confirm it loads and the status lookup resolves**

```bash
cd back-end && php artisan tinker --execute="echo App\Models\StockHold::inventoryStatusId('Defective');"
```

Expected: a number. A `RuntimeException` means Task 3's seeder did not run.

Check `User`'s primary key before relying on `disposedBy` — if the users table uses `id` rather than `user_id`, fix the relation now:

```bash
php -r '$c=new mysqli("127.0.0.1","root","","airkingv5");$r=$c->query("SHOW KEYS FROM users WHERE Key_name=\"PRIMARY\"");print_r($r->fetch_assoc()["Column_name"]);'
```

- [ ] **Step 3: Commit**

```bash
git add back-end/app/Models/StockHold.php
git commit -F - <<'EOF'
feat(holds): StockHold model

ALLOWED_DISPOSITIONS encodes the rule that a defective hold can never be
restocked and missing is terminal — the only route from defective back to
sellable stock is a deliberate new report.
EOF
```

---

### Task 5: `StockHoldService` — the sole mutator

The heart of the feature. Everything else calls this.

**Files:**
- Create: `back-end/app/Services/StockHoldService.php`
- Test: `back-end/tests/Feature/Holds/StockHoldServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Holds;

use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\StockHold;
use App\Services\StockHoldService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StockHoldServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function seedStock(int $onHand = 100): array
    {
        $location = \App\Models\Location::query()->firstOrFail();
        $product  = \App\Models\Product::query()->firstOrFail();

        Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)->delete();

        $inventory = Inventory::create([
            'location_id'        => $location->location_id,
            'product_id'         => $product->product_id,
            'status_id'          => StockHold::inventoryStatusId('In Stock'),
            'quantity_on_hand'   => $onHand,
            'available_quantity' => $onHand,
            'reorder_level'      => 0,
        ]);

        $batch = InventoryBatch::create([
            'location_id'        => $location->location_id,
            'product_id'         => $product->product_id,
            'quantity_received'  => $onHand,
            'quantity_remaining' => $onHand,
            'received_at'        => now(),
        ]);

        $incident = \App\Models\IncidentReport::create([
            'incident_number' => 'INC-TEST-' . uniqid(),
            'source_type'     => 'standalone',
            'location_id'     => $location->location_id,
            'product_id'      => $product->product_id,
            'batch_id'        => $batch->batch_id,
            'reason'          => 'defective',
            'quantity'        => 10,
            'narrative'       => 'test',
            'reported_by'     => \App\Models\User::query()->firstOrFail()->getKey(),
            'status_id'       => \App\Models\IncidentReport::statusId('Open'),
        ]);

        return compact('inventory', 'batch', 'incident');
    }

    /** The core guarantee: nothing vanishes, it moves. */
    public function test_holding_stock_preserves_physical_total(): void
    {
        ['inventory' => $inv, 'batch' => $batch, 'incident' => $incident] = $this->seedStock(100);

        app(StockHoldService::class)->hold($incident, StockHold::CONDITION_DEFECTIVE, 10);

        $inv->refresh();
        $batch->refresh();
        $held = StockHold::where('incident_id', $incident->incident_id)->sum('quantity');

        $this->assertSame(90, (int) $inv->quantity_on_hand);
        $this->assertSame(90, (int) $batch->quantity_remaining);
        $this->assertSame(10, (int) $held);
        // physical = active + held
        $this->assertSame(100, (int) $inv->quantity_on_hand + (int) $held);
    }

    public function test_hold_records_condition_batch_and_incident(): void
    {
        ['batch' => $batch, 'incident' => $incident] = $this->seedStock(50);

        $hold = app(StockHoldService::class)->hold($incident, StockHold::CONDITION_RETURNED, 5);

        $this->assertSame(StockHold::CONDITION_RETURNED, $hold->condition);
        $this->assertSame(StockHold::STATUS_HELD, $hold->status);
        $this->assertSame((int) $batch->batch_id, (int) $hold->batch_id);
        $this->assertSame((int) $incident->incident_id, (int) $hold->incident_id);
        $this->assertNull($hold->disposition);
    }

    /** A hold can never exceed what the batch actually holds. */
    public function test_hold_is_clamped_to_available_stock(): void
    {
        ['batch' => $batch, 'incident' => $incident] = $this->seedStock(3);

        $hold = app(StockHoldService::class)->hold($incident, StockHold::CONDITION_MISSING, 999);

        $this->assertSame(3, (int) $hold->quantity);
        $this->assertSame(0, (int) $batch->refresh()->quantity_remaining);
    }

    public function test_restocked_disposition_returns_the_quantity(): void
    {
        ['inventory' => $inv, 'batch' => $batch, 'incident' => $incident] = $this->seedStock(100);
        $svc  = app(StockHoldService::class);
        $hold = $svc->hold($incident, StockHold::CONDITION_RETURNED, 10);

        $svc->dispose($hold, StockHold::DISPOSITION_RESTOCKED, null, 1);

        $this->assertSame(100, (int) $inv->refresh()->quantity_on_hand);
        $this->assertSame(100, (int) $batch->refresh()->quantity_remaining);
        $this->assertSame(StockHold::STATUS_RELEASED, $hold->refresh()->status);
    }

    public function test_scrapped_disposition_does_not_return_the_quantity(): void
    {
        ['inventory' => $inv, 'incident' => $incident] = $this->seedStock(100);
        $svc  = app(StockHoldService::class);
        $hold = $svc->hold($incident, StockHold::CONDITION_DEFECTIVE, 10);

        $svc->dispose($hold, StockHold::DISPOSITION_SCRAPPED, null, 1);

        $this->assertSame(90, (int) $inv->refresh()->quantity_on_hand);
        $this->assertSame(StockHold::STATUS_RELEASED, $hold->refresh()->status);
    }

    /** Disposing as `defective` opens a fresh defective hold for the same quantity. */
    public function test_returned_disposed_as_defective_opens_a_defective_hold(): void
    {
        ['incident' => $incident] = $this->seedStock(100);
        $svc  = app(StockHoldService::class);
        $hold = $svc->hold($incident, StockHold::CONDITION_RETURNED, 10);

        $svc->dispose($hold, StockHold::DISPOSITION_DEFECTIVE, null, 1);

        $this->assertSame(StockHold::STATUS_RELEASED, $hold->refresh()->status);
        $this->assertDatabaseHas('stock_holds', [
            'incident_id' => $incident->incident_id,
            'condition'   => StockHold::CONDITION_DEFECTIVE,
            'status'      => StockHold::STATUS_HELD,
            'quantity'    => 10,
        ]);
    }

    public function test_a_hold_cannot_be_disposed_twice(): void
    {
        ['incident' => $incident] = $this->seedStock(100);
        $svc  = app(StockHoldService::class);
        $hold = $svc->hold($incident, StockHold::CONDITION_RETURNED, 10);
        $svc->dispose($hold, StockHold::DISPOSITION_SCRAPPED, null, 1);

        $this->expectException(\RuntimeException::class);
        $svc->dispose($hold->refresh(), StockHold::DISPOSITION_SCRAPPED, null, 1);
    }

    /** No one-click path puts a known-defective unit back into picking. */
    public function test_a_defective_hold_rejects_restocked(): void
    {
        ['incident' => $incident] = $this->seedStock(100);
        $svc  = app(StockHoldService::class);
        $hold = $svc->hold($incident, StockHold::CONDITION_DEFECTIVE, 10);

        $this->expectException(\InvalidArgumentException::class);
        $svc->dispose($hold, StockHold::DISPOSITION_RESTOCKED, null, 1);
    }

    public function test_a_missing_hold_allows_no_disposition_at_all(): void
    {
        ['incident' => $incident] = $this->seedStock(100);
        $svc  = app(StockHoldService::class);
        $hold = $svc->hold($incident, StockHold::CONDITION_MISSING, 10);

        $this->expectException(\InvalidArgumentException::class);
        $svc->dispose($hold, StockHold::DISPOSITION_RESTOCKED, null, 1);
    }

    /**
     * A scan must never say "In Stock" about a unit sitting in the defective
     * pile. The serial's own status has to follow the hold, and come back.
     */
    public function test_serial_status_follows_the_hold_and_returns_on_restock(): void
    {
        ['incident' => $incident] = $this->seedStock(100);

        $serial = \App\Models\ItemSerial::create([
            'product_id'    => $incident->product_id,
            'location_id'   => $incident->location_id,
            'serial_number' => 'SN-TEST-' . uniqid(),
            'status_id'     => StockHold::inventoryStatusId('In Stock'),
        ]);
        $incident->update(['serial_id' => $serial->serial_id, 'quantity' => 1]);

        $svc  = app(StockHoldService::class);
        $hold = $svc->hold($incident, StockHold::CONDITION_RETURNED, 1);

        $this->assertSame(
            StockHold::inventoryStatusId('Returned'),
            (int) $serial->refresh()->status_id,
            'a held serial must not still read In Stock'
        );

        $svc->dispose($hold, StockHold::DISPOSITION_RESTOCKED, null, 1);

        $this->assertSame(
            StockHold::inventoryStatusId('In Stock'),
            (int) $serial->refresh()->status_id
        );
    }

    /**
     * The 128 installation materials carry no serials at all, so a hold on
     * them is purely a quantity — serial_id stays null and nothing tries to
     * stamp a status.
     */
    public function test_non_serialized_stock_holds_by_quantity_alone(): void
    {
        ['incident' => $incident] = $this->seedStock(100);
        $incident->update(['serial_id' => null]);

        $hold = app(StockHoldService::class)
            ->hold($incident, StockHold::CONDITION_DEFECTIVE, 7);

        $this->assertNull($hold->serial_id);
        $this->assertSame(7, (int) $hold->quantity);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
cd back-end && php artisan test --filter=StockHoldServiceTest
```

Expected: FAIL — `Class "App\Services\StockHoldService" does not exist`.

If instead it fails on `Inventory::create` or `InventoryBatch::create` complaining about a column, read the real table with `SHOW COLUMNS` and fix the fixture — do **not** change the assertions.

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services;

use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\StockHold;
use Illuminate\Support\Facades\DB;

/**
 * The SOLE mutator of stock_holds.
 *
 * Creating a hold and deducting active stock must happen together or the
 * invariant `physical = active + held` breaks silently — a discrepancy nobody
 * notices until a stock count months later. Same discipline as BudgetService:
 * one class owns the table, everything else goes through it.
 */
class StockHoldService
{
    /**
     * Move quantity out of active stock and into a held condition.
     *
     * Clamped to what the batch (or the location, with no batch) actually
     * holds — holding more than exists would manufacture units that no batch
     * ever lost, which is the bug the write-off path had to fix twice.
     */
    public function hold(IncidentReport $incident, string $condition, int $quantity): StockHold
    {
        if (! in_array($condition, StockHold::CONDITIONS, true)) {
            throw new \InvalidArgumentException("Unknown hold condition: {$condition}");
        }

        return DB::transaction(function () use ($incident, $condition, $quantity, $actorId) {
            $batch = $incident->batch_id !== null
                ? InventoryBatch::lockForUpdate()->find($incident->batch_id)
                : null;

            $inventory = Inventory::where('location_id', $incident->location_id)
                ->where('product_id', $incident->product_id)
                ->lockForUpdate()
                ->first();

            $available = $batch !== null
                ? (int) $batch->quantity_remaining
                : (int) ($inventory->quantity_on_hand ?? $quantity);

            $moved = max(0, min($quantity, $available));

            if ($batch !== null) {
                $batch->decrement('quantity_remaining', $moved);
            }

            if ($inventory !== null) {
                $inventory->decrement('quantity_on_hand', min($moved, (int) $inventory->quantity_on_hand));
                $inventory->decrement('available_quantity', min($moved, (int) $inventory->available_quantity));
            }

            $this->stampSerial($incident->serial_id, StockHold::SERIAL_STATUS_NAME[$condition]);

            return StockHold::create([
                'incident_id' => (int) $incident->incident_id,
                'product_id'  => (int) $incident->product_id,
                'location_id' => (int) $incident->location_id,
                'batch_id'    => $incident->batch_id,
                'serial_id'   => $incident->serial_id,
                'condition'   => $condition,
                'quantity'    => $moved,
                'status'      => StockHold::STATUS_HELD,
            ]);
        });
    }

    /**
     * Close a hold and decide where the stock went.
     *
     * Only `restocked` returns quantity to active stock. Everything else ends
     * the hold — the units left the building, or were destroyed, or moved to a
     * fresh defective hold.
     */
    public function dispose(StockHold $hold, string $disposition, ?string $note, int $actorId): StockHold
    {
        $allowed = StockHold::ALLOWED_DISPOSITIONS[$hold->condition] ?? [];

        if (! in_array($disposition, $allowed, true)) {
            throw new \InvalidArgumentException(
                "A '{$hold->condition}' hold cannot be disposed as '{$disposition}'."
            );
        }

        return DB::transaction(function () use ($hold, $disposition, $note, $actorId) {
            // Re-read under the lock: two inspectors disposing at once must not
            // both pass the status guard and restock the quantity twice.
            $locked = StockHold::whereKey($hold->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== StockHold::STATUS_HELD) {
                throw new \RuntimeException('This hold has already been disposed.');
            }

            if ($disposition === StockHold::DISPOSITION_RESTOCKED) {
                $this->returnToStock($locked);
                $this->stampSerial($locked->serial_id, 'In Stock');
            } elseif ($disposition === StockHold::DISPOSITION_DEFECTIVE) {
                $this->stampSerial($locked->serial_id, 'Defective');
            }

            $locked->update([
                'status'           => StockHold::STATUS_RELEASED,
                'disposition'      => $disposition,
                'disposition_note' => $note,
                'disposed_by'      => $actorId,
                'disposed_at'      => now(),
            ]);

            // A returned unit found to be defective stays held — it is still in
            // the building and still not sellable, just for a different reason.
            if ($disposition === StockHold::DISPOSITION_DEFECTIVE) {
                StockHold::create([
                    'incident_id' => (int) $locked->incident_id,
                    'product_id'  => (int) $locked->product_id,
                    'location_id' => (int) $locked->location_id,
                    'batch_id'    => $locked->batch_id,
                    'serial_id'   => $locked->serial_id,
                    'condition'   => StockHold::CONDITION_DEFECTIVE,
                    'quantity'    => (int) $locked->quantity,
                    'status'      => StockHold::STATUS_HELD,
                ]);
            }

            return $locked->fresh();
        });
    }

    private function returnToStock(StockHold $hold): void
    {
        $quantity = (int) $hold->quantity;

        if ($hold->batch_id !== null) {
            $batch = InventoryBatch::lockForUpdate()->find($hold->batch_id);
            $batch?->increment('quantity_remaining', $quantity);
        }

        $inventory = Inventory::firstOrCreate(
            ['location_id' => (int) $hold->location_id, 'product_id' => (int) $hold->product_id],
            [
                'quantity_on_hand'   => 0,
                'available_quantity' => 0,
                'reorder_level'      => 0,
                'status_id'          => StockHold::inventoryStatusId('In Stock'),
            ]
        );

        $inventory->increment('quantity_on_hand', $quantity);
        $inventory->increment('available_quantity', $quantity);
    }

    /** Keep the serial's own status honest, so a scan never lies about a held unit. */
    private function stampSerial(?int $serialId, string $statusName): void
    {
        if ($serialId === null) {
            return;
        }

        ItemSerial::where('serial_id', $serialId)
            ->update(['status_id' => StockHold::inventoryStatusId($statusName)]);
    }
}
```

- [ ] **Step 4: Run the tests until green**

```bash
cd back-end && php artisan test --filter=StockHoldServiceTest
```

Expected: **11 passed**.

If `ItemSerial::create` rejects a column in the serial test, read the real table with `SHOW COLUMNS FROM item_serial` and fix the fixture — the columns are `serial_id, product_id, location_id, receiving_id, transfer_id, scanned_by, serial_number, serial_type, status_id`.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/StockHoldService.php back-end/tests/Feature/Holds/StockHoldServiceTest.php
git commit -F - <<'EOF'
feat(holds): StockHoldService as the sole mutator

hold() moves quantity out of active stock and into a condition in one
transaction, so `physical = active + held` cannot drift. dispose() closes a
hold under a row lock, and only `restocked` puts quantity back.
EOF
```

---

### Task 6: Six reasons instead of eight

**Files:**
- Create: `back-end/database/migrations/2026_08_11_000003_migrate_incident_reasons.php`
- Modify: `back-end/app/Models/IncidentReport.php`

- [ ] **Step 1: Write the data migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Eight reasons collapse to six; the nuance moves into the cause fields added
 * by 2026_08_11_000002.
 *
 *   theft, not_found          -> missing   (+ suspected_cause)
 *   damaged, delivery_damage  -> defective (+ root_cause)
 *   customer_return           -> return    (+ return_direction)
 *
 * Only 3 incident rows exist, all test data, so this is a small update rather
 * than a batched backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('incident_reports')->where('reason', 'theft')
            ->update(['reason' => 'missing', 'suspected_cause' => 'theft']);

        DB::table('incident_reports')->where('reason', 'not_found')
            ->update(['reason' => 'missing', 'suspected_cause' => 'unknown']);

        DB::table('incident_reports')->where('reason', 'damaged')
            ->update(['reason' => 'defective', 'root_cause' => 'internal']);

        DB::table('incident_reports')->where('reason', 'delivery_damage')
            ->update(['reason' => 'defective', 'root_cause' => 'delivery']);

        DB::table('incident_reports')->where('reason', 'customer_return')
            ->update(['reason' => 'return', 'return_direction' => 'from_customer']);
    }

    public function down(): void
    {
        DB::table('incident_reports')->where('reason', 'missing')
            ->update(['reason' => DB::raw("CASE WHEN suspected_cause = 'theft' THEN 'theft' ELSE 'not_found' END")]);

        DB::table('incident_reports')->where('reason', 'defective')
            ->update(['reason' => DB::raw("CASE WHEN root_cause = 'delivery' THEN 'delivery_damage' ELSE 'damaged' END")]);

        DB::table('incident_reports')->where('reason', 'return')->update(['reason' => 'customer_return']);
    }
};
```

- [ ] **Step 2: Rewrite the model's constants**

In `back-end/app/Models/IncidentReport.php`, replace the eight `REASON_*` constants and `STOCK_AFFECTING_REASONS` with:

```php
    public const REASON_MISSING       = 'missing';
    public const REASON_DEFECTIVE     = 'defective';
    public const REASON_RETURN        = 'return';
    public const REASON_WRONG_TYPE    = 'wrong_type';
    public const REASON_ZONE_MISMATCH = 'zone_mismatch';
    public const REASON_OTHER         = 'other';

    /** Missing: why the reporter thinks it is gone. */
    public const SUSPECTED_CAUSES = ['theft', 'misplaced', 'unknown'];

    /** Defective: where the fault came from. */
    public const ROOT_CAUSES = ['supplier', 'internal', 'delivery', 'other'];

    /**
     * The second meaning of "return" the system never had: stock coming back
     * FROM a customer, versus stock going back TO a supplier.
     */
    public const RETURN_DIRECTIONS = ['from_customer', 'to_supplier'];

    /**
     * Reasons whose resolution moves stock, and which therefore cannot be
     * filed without a product and a quantity.
     *
     * zone_mismatch is deliberately still here: it needs a product to name
     * WHICH item is in the wrong place, even though resolving it moves
     * nothing. wrong_type re-types a batch, which is a movement between
     * products.
     */
    public const STOCK_AFFECTING_REASONS = [
        self::REASON_MISSING,
        self::REASON_DEFECTIVE,
        self::REASON_RETURN,
        self::REASON_WRONG_TYPE,
        self::REASON_ZONE_MISMATCH,
    ];
```

`REASONS` is derived and needs no change. Add the six new columns to `$fillable`:

```php
        'last_known_location', 'suspected_cause', 'root_cause',
        'return_direction', 'return_reason', 'related_transfer_id',
```

- [ ] **Step 3: Apply the migration to both databases**

```bash
cd back-end
php artisan migrate --path=database/migrations/2026_08_11_000003_migrate_incident_reasons.php
php artisan migrate --path=database/migrations/2026_08_11_000003_migrate_incident_reasons.php --env=testing
```

- [ ] **Step 4: Verify the mapping**

```bash
php -r '
$c = new mysqli("127.0.0.1","root","","airkingv5");
$r = $c->query("SELECT reason, suspected_cause, root_cause, COUNT(*) n FROM incident_reports GROUP BY 1,2,3");
while ($x = $r->fetch_assoc()) echo "  {$x[reason]} / ".($x[suspected_cause] ?? "-")." / ".($x[root_cause] ?? "-")." : {$x[n]}\n";'
```

Expected: `missing / theft / -`, `missing / unknown / -`, `defective / - / internal` — one row each. No row should still read `theft`, `not_found`, `damaged`, `delivery_damage` or `customer_return`.

- [ ] **Step 5: Commit**

```bash
git add back-end/database/migrations/2026_08_11_000003_migrate_incident_reasons.php back-end/app/Models/IncidentReport.php
git commit -F - <<'EOF'
feat(incidents): six types, with cause as a field

theft/not_found become Missing + suspected cause; damaged/delivery_damage
become Defective + root cause; customer_return becomes Return + direction.
Cause is now reportable instead of being encoded in the reason.
EOF
```

At this point `IncidentService::strategyFor` references constants that no longer exist and the suite will not run. Task 7 fixes it — do them back to back.

---

### Task 7: The strategies

Three new, two deleted, one reverted, one rewired.

**Files:**
- Create: `back-end/app/Services/Incidents/MissingResolution.php`
- Create: `back-end/app/Services/Incidents/DefectiveResolution.php`
- Create: `back-end/app/Services/Incidents/ReturnResolution.php`
- Delete: `back-end/app/Services/Incidents/WriteOffResolution.php`
- Delete: `back-end/app/Services/Incidents/RestockResolution.php`
- Modify: `back-end/app/Services/Incidents/RelocateResolution.php`
- Modify: `back-end/app/Services/IncidentService.php`
- Test: `back-end/tests/Feature/Holds/IncidentHoldResolutionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Holds;

use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\StockHold;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class IncidentHoldResolutionTest extends TestCase
{
    use DatabaseTransactions;

    private function fileIncident(string $reason, int $onHand = 100, int $qty = 10): array
    {
        $location = \App\Models\Location::query()->firstOrFail();
        $product  = \App\Models\Product::query()->firstOrFail();

        Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)->delete();

        $inventory = Inventory::create([
            'location_id'        => $location->location_id,
            'product_id'         => $product->product_id,
            'status_id'          => StockHold::inventoryStatusId('In Stock'),
            'quantity_on_hand'   => $onHand,
            'available_quantity' => $onHand,
            'reorder_level'      => 0,
        ]);

        $batch = InventoryBatch::create([
            'location_id'        => $location->location_id,
            'product_id'         => $product->product_id,
            'quantity_received'  => $onHand,
            'quantity_remaining' => $onHand,
            'received_at'        => now(),
            'zone_label'         => 'ZONE-1',
        ]);

        $incident = IncidentReport::create([
            'incident_number' => 'INC-TEST-' . uniqid(),
            'source_type'     => 'standalone',
            'location_id'     => $location->location_id,
            'product_id'      => $product->product_id,
            'batch_id'        => $batch->batch_id,
            'reason'          => $reason,
            'quantity'        => $qty,
            'narrative'       => 'test',
            'actual_zone'     => 'ZONE-9',
            'expected_zone'   => 'ZONE-1',
            'reported_by'     => \App\Models\User::query()->firstOrFail()->getKey(),
            'status_id'       => IncidentReport::statusId('Open'),
        ]);

        return compact('inventory', 'batch', 'incident');
    }

    public function test_missing_deducts_stock_and_opens_a_terminal_hold(): void
    {
        ['inventory' => $inv, 'incident' => $incident] = $this->fileIncident('missing');

        app(IncidentService::class)->resolve($incident, 'confirm', [], 1);

        $this->assertSame(90, (int) $inv->refresh()->quantity_on_hand);
        $hold = StockHold::where('incident_id', $incident->incident_id)->firstOrFail();
        $this->assertSame(StockHold::CONDITION_MISSING, $hold->condition);
        $this->assertSame([], StockHold::ALLOWED_DISPOSITIONS[$hold->condition]);
    }

    public function test_defective_deducts_stock_and_opens_a_defective_hold(): void
    {
        ['inventory' => $inv, 'incident' => $incident] = $this->fileIncident('defective');

        app(IncidentService::class)->resolve($incident, 'confirm', [], 1);

        $this->assertSame(90, (int) $inv->refresh()->quantity_on_hand);
        $this->assertDatabaseHas('stock_holds', [
            'incident_id' => $incident->incident_id,
            'condition'   => StockHold::CONDITION_DEFECTIVE,
            'status'      => StockHold::STATUS_HELD,
        ]);
    }

    /**
     * REVERSAL: RestockResolution used to increment on approval, putting a
     * customer return straight back into pickable stock with nobody having
     * looked at it. It now holds.
     */
    public function test_a_return_does_not_become_sellable_on_approval(): void
    {
        ['inventory' => $inv, 'batch' => $batch, 'incident' => $incident] = $this->fileIncident('return');

        app(IncidentService::class)->resolve($incident, 'confirm', [], 1);

        $this->assertSame(90, (int) $inv->refresh()->quantity_on_hand, 'a return must not be sellable on approval');
        $this->assertSame(90, (int) $batch->refresh()->quantity_remaining);
        $this->assertDatabaseHas('stock_holds', [
            'incident_id' => $incident->incident_id,
            'condition'   => StockHold::CONDITION_RETURNED,
        ]);
    }

    /**
     * REVERSAL: RelocateResolution rewrote the batch's zone_label from
     * actual_zone. The system record is CORRECT and the shelf is wrong — the
     * fix is physical. Resolving must change nothing.
     */
    public function test_zone_mismatch_changes_absolutely_nothing(): void
    {
        ['inventory' => $inv, 'batch' => $batch, 'incident' => $incident] = $this->fileIncident('zone_mismatch');

        app(IncidentService::class)->resolve($incident, 'confirm', [], 1);

        $this->assertSame('ZONE-1', $batch->refresh()->zone_label, 'the system zone must not be overwritten');
        $this->assertSame(100, (int) $inv->refresh()->quantity_on_hand);
        $this->assertSame(100, (int) $batch->quantity_remaining);
        $this->assertSame(0, StockHold::where('incident_id', $incident->incident_id)->count());
        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $incident->refresh()->status_id);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
cd back-end && php artisan test --filter=IncidentHoldResolutionTest
```

Expected: FAIL — `UnhandledMatchError` from `strategyFor`, because Task 6 renamed the reasons.

- [ ] **Step 3: Write `MissingResolution`**

```php
<?php

namespace App\Services\Incidents;

use App\Models\Adjustment;
use App\Models\IncidentReport;
use App\Models\StockHold;
use App\Services\StockHoldService;

/**
 * The stock is gone. Deduct it from active inventory and open a `missing`
 * hold, which is terminal — an item that turns up later comes back through a
 * new report, deliberately, rather than by quietly reversing a loss record.
 *
 * The Adjustment is still posted so existing shrinkage reporting keeps working.
 */
class MissingResolution implements ResolvesIncident
{
    public function validate(IncidentReport $incident, array $options): void
    {
        // Nothing to guard: a loss is valid with or without a batch.
    }

    public function apply(IncidentReport $incident, array $options, int $reviewedBy): ResolutionArtifacts
    {
        $hold = app(StockHoldService::class)
            ->hold($incident, StockHold::CONDITION_MISSING, (int) $incident->quantity);

        $adjustment = Adjustment::create([
            'location_id'             => (int) $incident->location_id,
            'created_by'              => (int) $incident->reported_by,
            'approved_by'             => $reviewedBy,
            'adjustment_number'       => 'ADJ-INC-' . strtoupper(substr(uniqid(), -6)),
            'adjustment_date'         => now()->toDateString(),
            'adjustment_type'         => 'loss',
            'total_variance_positive' => 0,
            'total_variance_negative' => (int) $hold->quantity,
            'notes'                   => "Incident {$incident->incident_number}: {$incident->narrative}",
            'status_id'               => 20, // adjustment "Approved" — verified in status_lookup
        ]);

        $adjustment->details()->create([
            'product_id'      => (int) $incident->product_id,
            'add_quantity'    => 0,
            'deduct_quantity' => (int) $hold->quantity,
            'reason'          => $incident->reason,
        ]);

        return ResolutionArtifacts::adjustment((int) $adjustment->adjustment_id);
    }
}
```

- [ ] **Step 4: Write `DefectiveResolution`**

Identical to `MissingResolution` except the condition and the adjustment type. Repeated in full rather than shared, because the two will diverge — a defective hold is disposable and a missing one is not.

```php
<?php

namespace App\Services\Incidents;

use App\Models\Adjustment;
use App\Models\IncidentReport;
use App\Models\StockHold;
use App\Services\StockHoldService;

/**
 * The stock exists but cannot be sold. Deduct it from active inventory and
 * open a `defective` hold, which awaits an inspection deciding whether it goes
 * back to the supplier or is scrapped.
 *
 * `root_cause` (supplier / internal / delivery / other) is carried on the
 * incident, not here — this strategy behaves the same whoever caused it.
 */
class DefectiveResolution implements ResolvesIncident
{
    public function validate(IncidentReport $incident, array $options): void
    {
        // Nothing to guard: damage is valid with or without a batch.
    }

    public function apply(IncidentReport $incident, array $options, int $reviewedBy): ResolutionArtifacts
    {
        $hold = app(StockHoldService::class)
            ->hold($incident, StockHold::CONDITION_DEFECTIVE, (int) $incident->quantity);

        $adjustment = Adjustment::create([
            'location_id'             => (int) $incident->location_id,
            'created_by'              => (int) $incident->reported_by,
            'approved_by'             => $reviewedBy,
            'adjustment_number'       => 'ADJ-INC-' . strtoupper(substr(uniqid(), -6)),
            'adjustment_date'         => now()->toDateString(),
            'adjustment_type'         => 'damage',
            'total_variance_positive' => 0,
            'total_variance_negative' => (int) $hold->quantity,
            'notes'                   => "Incident {$incident->incident_number}: {$incident->narrative}",
            'status_id'               => 20,
        ]);

        $adjustment->details()->create([
            'product_id'      => (int) $incident->product_id,
            'add_quantity'    => 0,
            'deduct_quantity' => (int) $hold->quantity,
            'reason'          => $incident->reason,
        ]);

        return ResolutionArtifacts::adjustment((int) $adjustment->adjustment_id);
    }
}
```

- [ ] **Step 5: Write `ReturnResolution`**

```php
<?php

namespace App\Services\Incidents;

use App\Models\IncidentReport;
use App\Models\StockHold;
use App\Services\StockHoldService;

/**
 * Stock coming back — from a customer, or on its way back to a supplier.
 *
 * REVERSAL: RestockResolution used to increment the batch and the location on
 * approval, making a customer return immediately pickable with nobody having
 * inspected it. It now opens a `returned` hold instead; the increment happens
 * on a `restocked` disposition, when a person says the unit is sellable.
 *
 * No Adjustment is posted: the stock has not been lost or damaged, it is
 * simply not sellable yet. The hold row is the record.
 */
class ReturnResolution implements ResolvesIncident
{
    public function validate(IncidentReport $incident, array $options): void
    {
        // Nothing to guard: a return is valid with or without a batch, and the
        // batch only matters if it is later restocked.
    }

    public function apply(IncidentReport $incident, array $options, int $reviewedBy): ResolutionArtifacts
    {
        app(StockHoldService::class)
            ->hold($incident, StockHold::CONDITION_RETURNED, (int) $incident->quantity);

        return ResolutionArtifacts::none();
    }
}
```

- [ ] **Step 6: Revert `RelocateResolution`**

Replace the whole file:

```php
<?php

namespace App\Services\Incidents;

use App\Models\IncidentReport;

/**
 * A zone mismatch is a REPORT, not a correction.
 *
 * The reporter sees serial 1234 physically in zone 2 while the system says
 * zone 1 — and the system is right. Someone misplaced the box. The fix is to
 * walk over and move it back, so resolving must change nothing at all: not the
 * batch's zone_label, not the quantity, not a hold.
 *
 * An earlier version of this class rewrote zone_label from actual_zone, which
 * overwrote a correct record with the wrong physical location. The incident's
 * expected_zone and actual_zone columns are the whole artifact.
 */
class RelocateResolution implements ResolvesIncident
{
    public function validate(IncidentReport $incident, array $options): void
    {
        // Nothing to guard: recording an observation cannot fail.
    }

    public function apply(IncidentReport $incident, array $options, int $reviewedBy): ResolutionArtifacts
    {
        return ResolutionArtifacts::none();
    }
}
```

- [ ] **Step 7: Rewire `IncidentService`**

In `back-end/app/Services/IncidentService.php`, replace the `WriteOffResolution` and `RestockResolution` imports with `MissingResolution`, `DefectiveResolution` and `ReturnResolution`, and replace `strategyFor`'s body:

```php
        return match ($reason) {
            IncidentReport::REASON_MISSING       => new MissingResolution(),
            IncidentReport::REASON_DEFECTIVE     => new DefectiveResolution(),
            IncidentReport::REASON_RETURN        => new ReturnResolution(),
            IncidentReport::REASON_WRONG_TYPE    => new RetypeResolution(),
            IncidentReport::REASON_ZONE_MISMATCH => new RelocateResolution(),
            IncidentReport::REASON_OTHER         => new AcknowledgeResolution(),
        };
```

Keep the "no default arm" comment above it — that behaviour is deliberate.

- [ ] **Step 8: Delete the two dead strategies**

```bash
cd back-end
git rm app/Services/Incidents/WriteOffResolution.php app/Services/Incidents/RestockResolution.php
grep -rn "WriteOffResolution\|RestockResolution" app tests || echo "clean"
```

Expected: `clean`. If a test still references them, that test is rewritten in Task 8 — note it and continue.

- [ ] **Step 9: Run the new tests**

```bash
cd back-end && php artisan test --filter=IncidentHoldResolutionTest
```

Expected: **4 passed**.

- [ ] **Step 10: Commit**

```bash
git add back-end/app/Services/Incidents/ back-end/app/Services/IncidentService.php back-end/tests/Feature/Holds/IncidentHoldResolutionTest.php
git commit -F - <<'EOF'
feat(holds): hold-based resolutions, and two reversals

Missing and Defective deduct active stock and open a hold instead of
deleting the quantity outright.

REVERSAL 1 — a zone mismatch now changes nothing. The system record is
correct and the shelf is wrong, so rewriting zone_label from actual_zone
was overwriting a correct value with the wrong physical location.

REVERSAL 2 — a customer return is no longer sellable on approval. It opens
a `returned` hold; the increment moves to the disposition step.
EOF
```

---

### Task 8: Rewrite the incident tests that assert the old behaviour

Several of the 75 existing incident tests encode behaviour this plan deliberately changed. They fail **correctly**. Rewrite them to the new expectations — do not delete them, and do not weaken an assertion to make it pass.

**Files:**
- Modify: whichever files the run below names

- [ ] **Step 1: See what actually broke**

```bash
cd back-end && php artisan test --filter=Incident 2>&1 | tail -40
```

Expected failures, and what each should become:

| Old expectation | New expectation |
|---|---|
| a write-off decrements inventory and nothing else | it decrements **and** a `missing`/`defective` hold exists for the same quantity |
| resolving `customer_return` increments the batch | resolving `return` leaves stock unchanged and opens a `returned` hold |
| resolving `zone_mismatch` updates `zone_label` | `zone_label` is **unchanged** |
| reason strings `theft`, `not_found`, `damaged`, `delivery_damage`, `customer_return` | `missing`, `defective`, `return` (+ the cause field where the old reason carried it) |

- [ ] **Step 2: Update each failing test**

Work file by file. For every test that changes, add a one-line comment saying *why* the expectation changed, so the next reader does not think it was weakened:

```php
// Reversal: a return is held for inspection now, not restocked on approval.
```

- [ ] **Step 3: Run the whole incident suite**

```bash
cd back-end && php artisan test --filter=Incident
```

Expected: all green.

- [ ] **Step 4: Run the full backend suite**

```bash
cd back-end && php artisan test 2>&1 | grep -E "FAIL|Tests:"
```

Expected: only the known baseline — 9 `WarrantyClaimTest`, possibly `SerialRowMovementColumnsTest`. **Any other failure is a regression from this work.**

- [ ] **Step 5: Commit**

```bash
git add back-end/tests/
git commit -F - <<'EOF'
test(incidents): update expectations for hold-based resolution

Returns are held rather than restocked, zone mismatches change nothing, and
write-offs now leave a hold row behind. Each changed assertion carries a
comment saying why.
EOF
```

---

### Task 9: The disposition endpoint

**Files:**
- Create: `back-end/app/Http/Controllers/Api/StockHoldController.php`
- Modify: `back-end/routes/api.php`
- Modify: `back-end/database/seeders/PermissionSeeder.php`
- Test: `back-end/tests/Feature/Holds/StockHoldEndpointTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Holds;

use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\StockHold;
use App\Models\User;
use App\Services\StockHoldService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockHoldEndpointTest extends TestCase
{
    use DatabaseTransactions;

    private function heldReturn(): StockHold
    {
        $location = \App\Models\Location::query()->firstOrFail();
        $product  = \App\Models\Product::query()->firstOrFail();

        Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)->delete();

        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'status_id' => StockHold::inventoryStatusId('In Stock'),
            'quantity_on_hand' => 100, 'available_quantity' => 100, 'reorder_level' => 0,
        ]);

        $batch = InventoryBatch::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_received' => 100, 'quantity_remaining' => 100, 'received_at' => now(),
        ]);

        $incident = IncidentReport::create([
            'incident_number' => 'INC-TEST-' . uniqid(), 'source_type' => 'standalone',
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'batch_id' => $batch->batch_id, 'reason' => 'return', 'quantity' => 10,
            'narrative' => 'test', 'reported_by' => User::query()->firstOrFail()->getKey(),
            'status_id' => IncidentReport::statusId('Open'),
        ]);

        return app(StockHoldService::class)
            ->hold($incident, StockHold::CONDITION_RETURNED, 10);
    }

    public function test_it_lists_open_holds(): void
    {
        $hold = $this->heldReturn();
        Sanctum::actingAs(User::query()->firstOrFail());

        $this->getJson('/api/stock-holds')
            ->assertOk()
            ->assertJsonFragment(['hold_id' => (int) $hold->hold_id]);
    }

    public function test_it_disposes_a_returned_hold_as_restocked(): void
    {
        $hold = $this->heldReturn();
        Sanctum::actingAs(User::query()->firstOrFail());

        $this->postJson("/api/stock-holds/{$hold->hold_id}/dispose", [
            'disposition' => StockHold::DISPOSITION_RESTOCKED,
            'note'        => 'inspected, sealed, resellable',
        ])->assertOk();

        $this->assertSame(StockHold::STATUS_RELEASED, $hold->refresh()->status);
    }

    public function test_it_rejects_restocking_a_defective_hold(): void
    {
        $hold = $this->heldReturn();
        $hold->update(['condition' => StockHold::CONDITION_DEFECTIVE]);
        Sanctum::actingAs(User::query()->firstOrFail());

        $this->postJson("/api/stock-holds/{$hold->hold_id}/dispose", [
            'disposition' => StockHold::DISPOSITION_RESTOCKED,
        ])->assertStatus(422);
    }

    public function test_it_rejects_a_second_disposition(): void
    {
        $hold = $this->heldReturn();
        Sanctum::actingAs(User::query()->firstOrFail());

        $this->postJson("/api/stock-holds/{$hold->hold_id}/dispose",
            ['disposition' => StockHold::DISPOSITION_SCRAPPED])->assertOk();

        $this->postJson("/api/stock-holds/{$hold->hold_id}/dispose",
            ['disposition' => StockHold::DISPOSITION_SCRAPPED])->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
cd back-end && php artisan test --filter=StockHoldEndpointTest
```

Expected: FAIL — 404, the routes do not exist.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockHold;
use App\Services\StockHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The held-stock worklist and the inspection decision.
 *
 * Everything that mutates goes through StockHoldService — this controller
 * validates, authorizes and serializes, and owns none of the stock arithmetic.
 */
class StockHoldController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockHold::with(['product', 'location', 'incident', 'disposedBy'])
            ->forUser(Auth::user());   // location scoping, same trait every other list uses

        if ($request->query('status', 'held') !== 'all') {
            $query->where('status', $request->query('status', 'held'));
        }
        if ($condition = $request->query('condition')) {
            $query->where('condition', $condition);
        }
        if ($locationId = $request->query('location_id')) {
            $query->where('location_id', (int) $locationId);
        }

        $holds = $query->orderByDesc('created_at')->limit(500)->get();

        return response()->json([
            'success' => true,
            'data'    => $holds->map(fn (StockHold $h) => [
                'hold_id'          => (int) $h->hold_id,
                'incident_id'      => (int) $h->incident_id,
                'incident_number'  => $h->incident?->incident_number,
                'product_id'       => (int) $h->product_id,
                'product_name'     => $h->product?->product_name,
                // Packaging so the worklist can show "25 pcs · 2 Box + 5 pcs",
                // exactly as the incident list does.
                'pieces_per_package' => (int) ($h->product?->pieces_per_package ?? 1),
                'package_unit'     => $h->product?->consumableDetail?->package_unit,
                'location_id'      => (int) $h->location_id,
                'location_name'    => $h->location?->location_name,
                'batch_id'         => $h->batch_id !== null ? (int) $h->batch_id : null,
                'serial_id'        => $h->serial_id !== null ? (int) $h->serial_id : null,
                'condition'        => $h->condition,
                'quantity'         => (int) $h->quantity,
                'status'           => $h->status,
                'disposition'      => $h->disposition,
                'disposition_note' => $h->disposition_note,
                'disposed_by_name' => $h->disposedBy !== null
                    ? trim(($h->disposedBy->first_name ?? '') . ' ' . ($h->disposedBy->last_name ?? ''))
                    : null,
                'disposed_at'      => optional($h->disposed_at)->toIso8601String(),
                // The client decides which buttons to show from this, so the
                // rule lives in ONE place — the model — not in the UI too.
                'allowed_dispositions' => $h->status === StockHold::STATUS_HELD
                    ? (StockHold::ALLOWED_DISPOSITIONS[$h->condition] ?? [])
                    : [],
                'created_at'       => optional($h->created_at)->toIso8601String(),
            ])->all(),
        ]);
    }

    public function dispose(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'disposition' => ['required', Rule::in([
                StockHold::DISPOSITION_RESTOCKED,
                StockHold::DISPOSITION_DEFECTIVE,
                StockHold::DISPOSITION_RETURNED_TO_SUPPLIER,
                StockHold::DISPOSITION_SCRAPPED,
            ])],
            'note' => 'nullable|string|max:2000',
        ]);

        $hold = StockHold::forUser(Auth::user())->find($id);

        if ($hold === null) {
            return response()->json(['success' => false, 'message' => 'Hold not found.'], 404);
        }

        try {
            $disposed = app(StockHoldService::class)->dispose(
                $hold,
                $validated['disposition'],
                $validated['note'] ?? null,
                (int) Auth::id(),
            );
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            // 422, not 500: "already disposed" and "not allowed for this
            // condition" are both the caller asking for something invalid.
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Hold disposed.',
            'data'    => $disposed,
        ]);
    }
}
```

- [ ] **Step 4: Add the routes**

In `back-end/routes/api.php`, beside the incident routes near line 566:

```php
    Route::middleware('permission:incident_reports,read')
         ->get('/stock-holds', [\App\Http\Controllers\Api\StockHoldController::class, 'index']);
    Route::middleware('permission:incident_reports,dispose')
         ->post('/stock-holds/{id}/dispose', [\App\Http\Controllers\Api\StockHoldController::class, 'dispose']);
```

- [ ] **Step 5: Grant the permission**

In `back-end/database/seeders/PermissionSeeder.php` at line 85, the `incident_reports` block currently reads:

```php
            'incident_reports' => [
                'read'    => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'create'  => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel'],
                'resolve' => ['admin', 'inventory_analyst'],
            ],
```

Add `dispose` with the same roles as `resolve` — deciding where held stock goes is the same level of authority as approving the incident that held it:

```php
            'incident_reports' => [
                'read'    => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'create'  => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel'],
                'resolve' => ['admin', 'inventory_analyst'],
                // Deciding where held stock goes — restock it, send it back to the
                // supplier, scrap it — is the same authority as approving the
                // incident that held it. `auditor` stays read-only.
                'dispose' => ['admin', 'inventory_analyst'],
            ],
```

Then:

```bash
cd back-end
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=PermissionSeeder --env=testing
```

If the app caches permissions (check for a `permissionCache` or `config:cache` step in the README), clear it:

```bash
php artisan cache:clear && php artisan config:clear
```

- [ ] **Step 6: Run the endpoint tests**

```bash
cd back-end && php artisan test --filter=StockHoldEndpointTest
```

Expected: **4 passed**. A 403 means the permission seeder did not grant `dispose` to the test user's role — fix the seeder, not the test.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/StockHoldController.php back-end/routes/api.php back-end/database/seeders/PermissionSeeder.php back-end/tests/Feature/Holds/StockHoldEndpointTest.php
git commit -F - <<'EOF'
feat(holds): stock-holds worklist and disposition endpoint

allowed_dispositions is served from the model so the rule that a defective
hold can never be restocked lives in one place, not in the UI as well.
EOF
```

---

### Task 10: Frontend — six types and their cause fields

**Files:**
- Modify: `frontend/src/admin/utils/incidentReasons.js`
- Modify: `frontend/src/admin/utils/incidentFlow.js`
- Modify: `frontend/src/admin/components/incident/steps/WriteOffStep.jsx` → rename to `MissingStep.jsx` and `DefectiveStep.jsx`
- Modify: `frontend/src/admin/components/incident/steps/RestockStep.jsx` → `ReturnStep.jsx`
- Modify: `frontend/src/admin/components/incident/IncidentFlow.jsx`
- Test: `frontend/src/admin/utils/incidentFlow.test.js`

- [ ] **Step 1: Update the reason catalogue**

Rewrite `INCIDENT_REASONS` in `frontend/src/admin/utils/incidentReasons.js` to the six types. Keep the existing object shape (`value`, `label`, `pickerLabel`, `help`, `group`, `badge`, `affectsStock`, `outcome`, `duringPick`) — `reasonMeta`, `reasonLabel`, `reasonBadge`, `PICKING_REASONS` and `REASON_GROUPS` all derive from it and need no change.

```js
  {
    value: 'missing',
    label: 'Missing',
    pickerLabel: "I can't find it on the shelf",
    help: 'Filing this opens an investigation record. Approving it moves the stock out of active inventory and into a Missing hold — it stays countable for shrinkage reporting.',
    group: 'Missing stock',
    badge: 'ak-badge--red',
    affectsStock: true,
    outcome: 'missing',
    duringPick: true,
  },
  {
    value: 'defective',
    label: 'Defective',
    pickerLabel: "It's there but it's damaged or faulty",
    help: 'The stock exists but cannot be sold. Approving it moves the stock into a Defective hold, awaiting a decision to return it to the supplier or scrap it.',
    group: 'Damage',
    badge: 'ak-badge--amber',
    affectsStock: true,
    outcome: 'defective',
    duringPick: true,
  },
  {
    value: 'return',
    label: 'Return',
    pickerLabel: 'Something is being returned',
    help: 'Stock coming back from a customer, or going back to a supplier. It is held for inspection first — nothing becomes sellable again until someone confirms it.',
    group: 'Return',
    badge: 'ak-badge--green',
    affectsStock: true,
    outcome: 'return',
    duringPick: false,
  },
```

Keep `wrong_type`, `zone_mismatch` and `other` exactly as they are, but change `zone_mismatch`'s `help` to state the new truth:

```js
    help: 'Nothing is missing and nothing moves in the system — the record is right and the item is physically in the wrong place. Reporting it tells someone to go move it back.',
```

Add the cause option lists:

```js
/** Missing: why the reporter thinks it is gone. Mirrors IncidentReport::SUSPECTED_CAUSES. */
export const SUSPECTED_CAUSES = [
  { value: 'theft', label: 'Taken — suspected theft' },
  { value: 'misplaced', label: 'Misplaced somewhere in the building' },
  { value: 'unknown', label: "Don't know" },
];

/** Defective: where the fault came from. Mirrors IncidentReport::ROOT_CAUSES. */
export const ROOT_CAUSES = [
  { value: 'supplier', label: 'Arrived faulty from the supplier' },
  { value: 'internal', label: 'Damaged here — handling or storage' },
  { value: 'delivery', label: 'Damaged on the way to the customer' },
  { value: 'other', label: 'Something else' },
];

/** The two directions a return can travel. Mirrors IncidentReport::RETURN_DIRECTIONS. */
export const RETURN_DIRECTIONS = [
  { value: 'from_customer', label: 'Coming back from a customer' },
  { value: 'to_supplier', label: 'Going back to the supplier' },
];
```

- [ ] **Step 2: Update the step-sequencing test**

In `frontend/src/admin/utils/incidentFlow.test.js`, change the two reason strings — `'theft'` becomes `'missing'`. `'other'` is unchanged. Add:

```js
  it('gives each of the five stock-affecting types an outcome step', () => {
    ['missing', 'defective', 'return', 'wrong_type', 'zone_mismatch']
      .forEach((r) => expect(stepsFor(r)).toHaveLength(4));
  });
```

- [ ] **Step 3: Run it**

```bash
cd frontend && npm test -- --watchAll=false --testPathPattern=incidentFlow
```

Expected: PASS, 10 tests.

- [ ] **Step 4: Split and rename the outcome steps**

`WriteOffStep.jsx` becomes two files. Both keep the existing quantity control (`QuantityInPieces`) and batch select (`BatchSelect`) exactly as they are; only the cause field and the warning copy differ.

`MissingStep.jsx` adds:

```jsx
      <div className="ak-field">
        <label className="ak-label" htmlFor="incident-flow-last-known">Where did you last see it?</label>
        <input
          id="incident-flow-last-known"
          className="ak-input"
          type="text"
          maxLength={150}
          value={lastKnownLocation}
          onChange={(e) => setLastKnownLocation(e.target.value)}
          placeholder="e.g. Zone A-12, or the receiving bay"
          disabled={disabled}
        />
      </div>

      <fieldset className="ak-field" style={{ border: 'none', margin: 0, padding: 0 }}>
        <legend className="ak-label" style={{ marginBottom: '0.4rem' }}>Why do you think it's gone? *</legend>
        {SUSPECTED_CAUSES.map((c) => (
          <label key={c.value} style={{ display: 'flex', gap: '0.5rem', alignItems: 'center', padding: '0.2rem 0', cursor: 'pointer' }}>
            <input
              type="radio"
              name="incident-flow-suspected-cause"
              value={c.value}
              checked={suspectedCause === c.value}
              onChange={() => setSuspectedCause(c.value)}
              disabled={disabled}
            />
            <span>{c.label}</span>
          </label>
        ))}
      </fieldset>
```

`DefectiveStep.jsx` is the same shape with `ROOT_CAUSES` and `rootCause`/`setRootCause`, and no last-known-location field.

`ReturnStep.jsx` is the current `RestockStep.jsx` plus a `RETURN_DIRECTIONS` radio group and a free-text `return_reason` input, and its batch-warning copy changes from *"puts the returned stock back ONTO batch #N"* to:

> Resolving this holds the stock for inspection. If it's later found sellable, it goes back onto batch #N.

- [ ] **Step 5: Wire the new state into `IncidentFlow`**

Add `lastKnownLocation`, `suspectedCause`, `rootCause`, `returnDirection`, `returnReason` to the state block, add them to the payload in `handleSubmit`, and update `canAdvance`'s OUTCOME branch:

```jsx
    if (step === STEP_KEYS.OUTCOME) {
      if (outcome === 'missing')   return totalPieces >= 1 && !!suspectedCause;
      if (outcome === 'defective') return totalPieces >= 1 && !!rootCause;
      if (outcome === 'return')    return totalPieces >= 1 && !!returnDirection;
      // RetypeResolution refuses to resolve an incident with no batch.
      if (outcome === 'retype')    return !!batchId;
      if (outcome === 'relocate')  return !!batchId && !!actualZone.trim();
      return true;
    }
```

Payload additions:

```jsx
        last_known_location: outcome === 'missing' ? (lastKnownLocation.trim() || null) : null,
        suspected_cause: outcome === 'missing' ? suspectedCause : null,
        root_cause: outcome === 'defective' ? rootCause : null,
        return_direction: outcome === 'return' ? returnDirection : null,
        return_reason: outcome === 'return' ? (returnReason.trim() || null) : null,
```

Update the `handleSubmit` dependency array to include all five.

- [ ] **Step 6: Accept the new fields server-side**

In `back-end/app/Http/Controllers/Api/IncidentReportController.php`'s `store()` validation, add:

```php
            'last_known_location' => 'nullable|string|max:150',
            'suspected_cause'     => ['nullable', Rule::in(IncidentReport::SUSPECTED_CAUSES)],
            'root_cause'          => ['nullable', Rule::in(IncidentReport::ROOT_CAUSES)],
            'return_direction'    => ['nullable', Rule::in(IncidentReport::RETURN_DIRECTIONS)],
            'return_reason'       => 'nullable|string|max:150',
            'related_transfer_id' => 'nullable|integer',
```

and persist them in `IncidentService::file()`'s create array — it builds an **explicit column list**, so adding them to `$fillable` alone silently drops the values.

- [ ] **Step 7: Verify**

```bash
cd frontend && npx eslint src/admin/utils/incidentReasons.js src/admin/utils/incidentFlow.js src/admin/components/incident/IncidentFlow.jsx src/admin/components/incident/steps/*.jsx && npm run build
cd ../back-end && php artisan test --filter=Incident
```

Expected: eslint silent, build compiles, backend incident tests green.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/admin/utils/incidentReasons.js frontend/src/admin/utils/incidentFlow.js frontend/src/admin/utils/incidentFlow.test.js frontend/src/admin/components/incident/ back-end/app/Http/Controllers/Api/IncidentReportController.php back-end/app/Services/IncidentService.php
git commit -F - <<'EOF'
feat(incidents): six types with cause fields in the guided flow

Missing carries a suspected cause and last known location, Defective a root
cause, Return a direction and reason. IncidentService::file() builds an
explicit column list, so the new fields are added there as well as fillable.
EOF
```

---

### Task 11: Frontend — the Held Stock worklist

**Files:**
- Create: `frontend/src/admin/pages/StockHoldsPage.jsx`
- Create: `frontend/src/admin/components/incident/DisposeHoldModal.jsx`
- Modify: `frontend/src/admin/services/api.js`
- Modify: the router and the nav config

- [ ] **Step 1: Add the API client**

In `frontend/src/admin/services/api.js`, beside `incidentReportsAPI`:

```js
export const stockHoldsAPI = {
  list: (params) => apiRequest(`/stock-holds${toQuery(params)}`),
  dispose: (id, body) => apiRequest(`/stock-holds/${id}/dispose`, { method: 'POST', body: JSON.stringify(body) }),
};
```

Match the surrounding style — read how `incidentReportsAPI` builds its query string and reuse the same helper rather than inventing `toQuery` if one already exists.

- [ ] **Step 2: Build the page**

`StockHoldsPage.jsx` renders inside `AdminLayout`, using the `ak-*` classes and the same table shape as `components/incident/IncidentList.jsx` — read that file first and follow it.

Columns: Condition · Product · Qty (with pack breakdown via `packageBreakdown`, as the incident list does) · Location · Batch/Serial · From incident · Held since · action.

Filters: condition, location, and status (`held` / `all`).

The action button is enabled only when `allowed_dispositions.length > 0` — that array comes from the server, so the rule that a defective hold cannot be restocked is never duplicated in the UI:

```jsx
{hold.allowed_dispositions.length > 0 ? (
  <button type="button" className="ak-btn ak-btn--sm ak-btn--primary" onClick={() => setDisposing(hold)}>
    Dispose
  </button>
) : (
  <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
    {hold.status === 'released' ? hold.disposition?.replace(/_/g, ' ') : 'No action — recorded as a loss'}
  </span>
)}
```

- [ ] **Step 3: Build the dispose modal**

`DisposeHoldModal.jsx` uses the shared `Modal` component. It renders one radio per entry in `hold.allowed_dispositions`, an optional note textarea, and a confirm button. Labels:

```js
const DISPOSITION_LABELS = {
  restocked: 'Inspected and sellable — put it back into stock',
  defective: 'Found defective — hold it as defective instead',
  returned_to_supplier: 'Send it back to the supplier',
  scrapped: 'Scrap it — it is destroyed',
};
```

Because `restocked` puts stock back into picking, confirm it through the shared `confirmAction` helper (`frontend/src/admin/utils/confirm.js`), the same way `BarcodeAdjustmentPage` confirms an inventory-modifying submit. On success, `toast.success` and refetch.

- [ ] **Step 4: Route and nav**

Add the route beside the incidents route in the admin router, gated on `incident_reports.read`. Add the nav entry — this codebase drives the sidebar from permission-based nav bundles, so read how the Incident Reports entry is registered and follow it exactly rather than hardcoding a menu item.

- [ ] **Step 5: Verify**

```bash
cd frontend && npx eslint src/admin/pages/StockHoldsPage.jsx src/admin/components/incident/DisposeHoldModal.jsx src/admin/services/api.js && npm run build
```

Expected: eslint silent, build compiles.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/StockHoldsPage.jsx frontend/src/admin/components/incident/DisposeHoldModal.jsx frontend/src/admin/services/api.js
git commit -F - <<'EOF'
feat(holds): Held Stock worklist with the disposition action

Which dispositions are offered comes from the server's allowed_dispositions,
so the rule that a defective hold can never be restocked is not duplicated
client-side.
EOF
```

Stage the router and nav files too — name them explicitly in the `git add`, whatever they turned out to be.

---

### Task 12: Frontend — resolve drawer copy

The drawer still tells reviewers a confirmation is going to "write off" stock. It now moves it.

**Files:**
- Modify: `frontend/src/admin/components/IncidentResolveDrawer.jsx`

- [ ] **Step 1: Update the confirmation copy**

The drawer derives its wording from `reasonMeta(reason).outcome`. Map the new outcomes:

| Outcome | Confirmation reads |
|---|---|
| `missing` | *Moves 25 pcs · 2 Box out of stock and records them as Missing. This cannot be undone from here.* |
| `defective` | *Moves 25 pcs · 2 Box into Defective. It will appear in Held Stock for inspection.* |
| `return` | *Holds 25 pcs · 2 Box as Returned. It does not become sellable until someone inspects it.* |
| `retype` | unchanged |
| `relocate` | *Records the mismatch. **Nothing in the system changes** — someone needs to physically move the item back to {expected_zone}.* |
| `none` | unchanged |

Keep the existing `packageBreakdown` call that renders "25 pcs · 2 Box + 5 pcs" — a reviewer approving a write-off needs the pack breakdown, and that was added deliberately.

- [ ] **Step 2: Verify**

```bash
cd frontend && npx eslint src/admin/components/IncidentResolveDrawer.jsx && npm run build
```

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/components/IncidentResolveDrawer.jsx
git commit -F - <<'EOF'
fix(incidents): reviewer copy matches what approval now does

"Writes off 25 pcs" became untrue when stock started moving into holds
instead of vanishing. Zone mismatch says plainly that nothing changes.
EOF
```

---

### Task 13: Verification

No code changes unless something below fails.

- [ ] **Step 1: Backend — the invariant and the reversals**

```bash
cd back-end && php artisan test --filter="StockHold|IncidentHold|Incident"
```

Expected: all green, including `test_zone_mismatch_changes_absolutely_nothing` and `test_a_return_does_not_become_sellable_on_approval`.

- [ ] **Step 2: Backend — full suite**

```bash
cd back-end && php artisan test 2>&1 | grep -E "FAIL|Tests:"
```

Expected: the known baseline only — 9 `WarrantyClaimTest`, possibly `SerialRowMovementColumnsTest`. Any other failure is a regression.

- [ ] **Step 3: Frontend — tests, lint, build**

```bash
cd frontend
npm test -- --watchAll=false 2>&1 | tail -6
npx eslint src/admin/utils/incidentReasons.js src/admin/utils/incidentFlow.js \
  src/admin/components/incident/IncidentFlow.jsx src/admin/components/incident/steps/*.jsx \
  src/admin/components/incident/DisposeHoldModal.jsx src/admin/pages/StockHoldsPage.jsx \
  src/admin/components/IncidentResolveDrawer.jsx
npm run build
```

Expected: **3 pre-existing suite failures and no more** (`App.test.jsx`, `navConfigureMap`, `InventoryManagement`); eslint silent; build compiles.

- [ ] **Step 4: Prove the invariant by hand**

```bash
php -r '
$c = new mysqli("127.0.0.1","root","","airkingv5");
$r = $c->query("
  SELECT i.product_id, i.location_id, i.quantity_on_hand active,
         COALESCE((SELECT SUM(quantity) FROM stock_holds h
                   WHERE h.product_id=i.product_id AND h.location_id=i.location_id
                     AND h.status=\"held\"),0) held
  FROM inventory i");
while ($x = $r->fetch_assoc())
  echo "product {$x[product_id]} @ {$x[location_id]}: active {$x[active]} + held {$x[held]} = " . ($x[active]+$x[held]) . "\n";'
```

Every row should account for its stock. A negative `active` means the clamp in `StockHoldService::hold` was bypassed — stop and investigate.

- [ ] **Step 5: Manual pass — all six types**

`npm run demo`, sign in, and walk each:

1. **Missing** — file with suspected cause *theft*, approve, confirm active stock drops and a Missing hold appears in Held Stock with **no Dispose button**
2. **Defective** — file with root cause *supplier*, approve, then dispose as *returned to supplier*; the hold closes and does not return to stock
3. **Return** — file *from a customer*, approve, confirm stock did **not** go back up, then dispose as *restocked* and confirm it does
4. **Return → defective** — dispose a returned hold as *defective*; the old hold closes and a new Defective hold opens for the same quantity
5. **Zone mismatch** — approve, then confirm the batch's zone in Item Management is **unchanged** and no hold was created
6. **Other** — three steps, files, no hold

Plus: a serialized appliance's serial reads *Defective* after approval and *In Stock* again after a restock disposition.

- [ ] **Step 6: Report**

State plainly what passed, what failed, and anything left undone. Do not report completion if any step above did not run.

---

## Notes for whoever picks this up

- The branch is shared and already pushed. **Do not push** without asking — the two reversals change behaviour other people may be testing against.
- `airkingv5.sql` carries unrelated dev-data drift. Leave it alone.
- Pieces **B** (the client's dealer/customer/site fields, the indoor/outdoor model+serial pair, the five-item attachment checklist) and **C** (the printable letterheaded form with two signature lines) are separate specs and are **not** in this plan.
- If a step's markup drifts from what this plan shows, prefer what the existing `components/incident/steps/` files do — they were tested against a real device and a real barcode scanner.
