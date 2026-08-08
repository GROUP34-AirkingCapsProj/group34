# Standalone Incident Reporting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let staff file an incident report at any time — not only as a FIFO override during picking — covering theft, damage, customer returns, and free-form observations, with returns putting stock back.

**Architecture:** Extend the existing `incident_reports` table in place (nullable `source_id`/`product_id`, new `occurred_at` and `related_issuance_id`), grow the reason list from four to eight, and split `IncidentService::resolve()`'s switch into five per-reason strategy classes behind a `ResolvesIncident` interface. The frontend gains a Report Incident button and a standalone file modal, with reason metadata consolidated into one shared table.

**Tech Stack:** Laravel 12 (PHP 8.2+), MySQL/MariaDB via XAMPP, PHPUnit, React 18 (CRA), plain CSS design system (`ak-*` primitives).

**Spec:** `docs/superpowers/specs/2026-08-03-standalone-incident-reporting-design.md`

---

## ✅ COMPLETE — all 15 tasks implemented and verified (2026-08-08)

**Tasks 1–15 done and committed on `backupV3`. NOT pushed.** Automated verification below; the manual `npm run demo` walkthrough in Task 15 is still outstanding.

### Verification results
- **Backend suite:** 775 passed, 1 skipped, **10 failed** — see the baseline note below. All 52 incident tests pass (`--filter=Incident`).
- **Frontend:** `npm run build` compiles; zero warnings in any incident file. All remaining warnings are in pre-existing dirty files (`App.jsx`, `Sidebar.jsx`, `BarcodeScan.jsx`, `BarcodeAdjustmentPage.jsx`, `AdminLayout.jsx`).
- **`airkingv5.sql`** was hand-edited (commit `f46d263`, +6/−2) to sync only the `incident_reports` block, so a fresh import supports standalone filing. Deliberately not regenerated with `mysqldump` — that would have swept up unrelated dev-DB drift and risked the UTF-16 encoding gotcha.

### ⚠️ Suite baseline is now 10 failures, not 9
Nine are the long-standing `WarrantyClaimTest` failures that predate this work (verified against `53f834a`).

**The tenth is `Tests\Feature\Inventory\SerialRowMovementColumnsTest > consumable rows carry per batch in and out`, and it is NOT caused by this feature.** Evidence:
- No file in that endpoint's path (`InventoryController`, `InventoryBatchService`, `Inventory`/`InventoryBatch`/`ReceivingDetail` models) appears in `git diff --name-only 53f834a..HEAD`.
- The endpoint contains no reference to incident code.

**Root cause:** `InventoryController.php:356` sorts with `$receivingDetails->sortBy('received_at')`. The test creates two receivings on the *same date*, so that sort is a tie and the source query has no tiebreaker — output order falls back to physical row order. The test's own later assertions use `firstWhere('row_quantity_received', …)` because order isn't guaranteed; only the two `$records[0]` / `$records[1]` lines assume it. It surfaced now because accumulated churn in `airkingV5_test` flipped the tie.

**Fix (separate change, not this feature):** either add a deterministic tiebreaker to the endpoint (`->sortBy([['received_at','asc'],['receiving_id','asc']])`, which also makes the API's output stable for real clients) or drop the two order-dependent assertions from the test. The endpoint fix is the better one.

### Still open for the user
1. **Manual walkthrough** — `npm run demo`, then file each new reason type, confirm a return puts stock back on its original batch, and check the Report Incident button is absent for `auditor`.
2. **HEIC photos** — iPhones in "High Efficiency" mode shoot HEIC, which neither the `image` rule nor `mimes:jpeg,jpg,png,webp` accepts. If staff photograph damage on iPhones they will hit a 422 on a phone-first workflow. Needs a device check, not a guess.
3. **Integration** — still on `backupV3`, unpushed, alongside a large amount of unrelated earlier work.

---

## ⏸ STATE OF PLAY — read this first (2026-08-04)

**Tasks 1–12 are DONE and committed on `backupV3` (not pushed). Tasks 13, 14, 15 remain.**

Every completed task went through a spec-compliance review and a code-quality review, and several were amended as a result. **The task text below has been edited in place to match what was actually built** — trust the document, not your memory of how it started.

### Where to resume
- **Task 13** — resolve drawer for the new outcomes. Note it now uses an authenticated blob fetch, not `<img src>`; the task text carries the amendment.
- **Task 14** — wire the picking modal's photo input (it has been decorative since the module was built).
- **Task 15** — verification, including a decision to put to the user about `airkingv5.sql`.

### Amendments made mid-flight (all already reflected below)
1. `ResolvesIncident::apply()` returns a `ResolutionArtifacts` value object, not an array.
2. Evidence photos live on the **private** `local` disk, served by `GET /incident-reports/{id}/photo` behind `permission:incident_reports,read`. A plain `<img src>` will 401. `incidentReportsAPI.photoBlob(id)` exists for this.
3. `issuances` has **no `issuance_number` column** — it is `dr_number`. This also broke `IssuanceController::index`'s search, fixed in Task 12's commit.
4. `store()`'s `photo_path` is regex-constrained to `incidents/YYYY/MM/<40 chars>.<ext>` — it previously accepted any string, including an absolute URL to an attacker's host.
5. Task 4 absorbed the `source_id` null handling that was originally written into Task 5.

### Environment facts that will bite an unprepared agent
- Schema is a **SQL dump, not a migration history**. Bare `php artisan migrate` fails. Apply by `--path`, to both the dev DB and `airkingV5_test`.
- Tests use `DatabaseTransactions`, never `RefreshDatabase`.
- **The suite has 9 pre-existing `WarrantyClaimTest` failures** that predate all of this work (verified against `53f834a`). Green means "still exactly those 9".
- **~22 files are dirty in the working tree from unrelated earlier work.** Stage only the files a task names. `airkingv5.sql`, `Sidebar.jsx`, `App.jsx`, `BarcodeScan.jsx` and others are among them, and they are the source of every current build warning.
- There is **no frontend test suite**. Verification is `npm run build`, warning-clean relative to the baseline above.
- PowerShell here-strings break `git commit -m`. Use `git commit -F -` with a heredoc.
- If several agents share this working tree, **only one may touch the git index at a time** — a concurrent `git add`/`commit` will swallow another's staged files.

---


---

## Critical Context For The Implementer

Read this before Task 1. Several things about this repo will bite you otherwise.

**The schema is a SQL dump, not a migration history.** Running a bare `php artisan migrate` fails. Apply exactly one migration at a time by path, to each database separately:

```bash
# dev database (from .env)
php artisan migrate --force --path=database/migrations/<file>.php
# test database
DB_DATABASE=airkingV5_test php artisan migrate --force --path=database/migrations/<file>.php
```

On Windows PowerShell the env prefix does not work; use `$env:DB_DATABASE='airkingV5_test'; php artisan migrate --force --path=...` and then `Remove-Item Env:\DB_DATABASE` afterwards. The Bash tool is available and the `DB_DATABASE=... php artisan` form works there — prefer it.

**Tests.** `phpunit.xml` already points `DB_DATABASE` at `airkingV5_test`, so `php artisan test` needs no prefix. Every test class here uses `Illuminate\Foundation\Testing\DatabaseTransactions` — **never** `RefreshDatabase`, which would wipe the dump-loaded schema. Run one file with:

```bash
php artisan test --filter=IncidentResolutionTest
```

**Seeders run per-database too**, and permission changes need both:

```bash
php artisan db:seed --force --class=PermissionSeeder
php artisan db:seed --force --class=RolePermissionsSeeder
DB_DATABASE=airkingV5_test php artisan db:seed --force --class=PermissionSeeder
DB_DATABASE=airkingV5_test php artisan db:seed --force --class=RolePermissionsSeeder
```

**Test helpers.** All incident tests extend `Tests\Feature\Sets\SetTestCase`, which provides `makeLocation()`, `makeUserAt($location, $role)`, `makeConsumable()`, `makeAppliance()`, `makeBatch($product, $location, ['quantity_remaining' =>, 'received_at' =>, 'zone_label' =>])`, `makeSerial()`, `setStock($locationId, $productId, $qty)`, and `makeCustomer()`. Role names map to ids: `admin`=1, `inventory_analyst`=2, `branch_manager`=3, `warehouse_personnel`=4, `auditor`=5. New incident tests go in `tests/Feature/Sets/` alongside the three existing ones — the directory name is a historical artifact, but splitting incident tests across two directories would be worse.

**The 12 tests in `IncidentResolutionTest` are the safety net for Task 3.** They must pass **unmodified** through the strategy extraction. If you find yourself editing them, the refactor is wrong — revert and try again.

**Git.** PowerShell here-strings containing double quotes break `git commit -m`. Use `git commit -F <file>` or the Bash tool's heredoc form.

**No frontend test suite exists.** Frontend tasks are verified with `npm run build` (must be warning-clean) plus the manual walkthrough in Task 15.

---

## File Structure

**Backend — create:**

| File | Responsibility |
|---|---|
| `back-end/database/migrations/2026_08_03_000001_extend_incident_reports_for_standalone.php` | Nullable `source_id`/`product_id`, add `occurred_at` + `related_issuance_id` |
| `back-end/app/Services/Incidents/ResolvesIncident.php` | The strategy interface |
| `back-end/app/Services/Incidents/WriteOffResolution.php` | `not_found`, `theft`, `damaged`, `delivery_damage` → deduction Adjustment |
| `back-end/app/Services/Incidents/RetypeResolution.php` | `wrong_type` → ScanCorrection + batch re-type |
| `back-end/app/Services/Incidents/RelocateResolution.php` | `zone_mismatch` → `zone_label` update |
| `back-end/app/Services/Incidents/RestockResolution.php` | `customer_return` → addition Adjustment, stock back to original batch |
| `back-end/app/Services/Incidents/AcknowledgeResolution.php` | `other` → no artifact |
| `back-end/tests/Feature/Sets/IncidentStandaloneFilingTest.php` | Standalone filing + new-reason validation |
| `back-end/tests/Feature/Sets/IncidentRestockTest.php` | `customer_return` resolution |
| `back-end/tests/Feature/Sets/IncidentPhotoUploadTest.php` | Photo endpoint |

**Backend — modify:**

| File | Change |
|---|---|
| `back-end/app/Models/IncidentReport.php` | New fillable/casts, `relatedIssuance()`, four new reason constants, `STOCK_AFFECTING_REASONS` |
| `back-end/app/Services/IncidentService.php` | `resolve()` delegates to strategies; `file()` allows null product for `other` |
| `back-end/app/Http/Controllers/Api/IncidentReportController.php` | Relaxed validation, new fields, `photo_url`, null-preserving `product_id`, `uploadPhoto()` |
| `back-end/routes/api.php` | `POST /incident-reports/photo` |
| `back-end/database/seeders/PermissionSeeder.php` | `branch_manager` added to `incident_reports.create` |
| `back-end/tests/Feature/Sets/IncidentSchemaTest.php` | Assert the new columns and FK |

**Frontend — create:**

| File | Responsibility |
|---|---|
| `frontend/src/admin/utils/incidentReasons.js` | Single source of truth for reason label / help / group / badge / outcome |
| `frontend/src/admin/components/IncidentFileModal.jsx` | The standalone filing form |

**Frontend — modify:** `pages/IncidentReportsPage.jsx` (Report Incident button, shared reasons, `occurred_at`), `components/IncidentResolveDrawer.jsx` (outcome-driven copy, new fields), `components/picking/IncidentModal.jsx` (real photo upload, shared reasons), `services/api.js` (`uploadPhoto`).

---

## Phase 1 — Schema and model

### Task 1: Migration

**Files:**
- Create: `back-end/database/migrations/2026_08_03_000001_extend_incident_reports_for_standalone.php`
- Test: `back-end/tests/Feature/Sets/IncidentSchemaTest.php` (modify)

- [ ] **Step 1: Write the failing test**

Add these two methods to `back-end/tests/Feature/Sets/IncidentSchemaTest.php`, inside the existing class:

```php
    public function test_standalone_columns_exist_and_are_nullable(): void
    {
        foreach (['occurred_at', 'related_issuance_id'] as $col) {
            $this->assertTrue(Schema::hasColumn('incident_reports', $col), "incident_reports missing {$col}");
        }

        $nullable = collect(DB::select(
            "SELECT COLUMN_NAME AS c, IS_NULLABLE AS n
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'incident_reports'"
        ))->mapWithKeys(fn ($r) => [$r->c => $r->n]);

        // A standalone report has no source transaction; an `other` incident has no product.
        $this->assertSame('YES', $nullable['source_id'] ?? null, 'source_id must be nullable');
        $this->assertSame('YES', $nullable['product_id'] ?? null, 'product_id must be nullable');
        $this->assertSame('YES', $nullable['occurred_at'] ?? null);
        $this->assertSame('YES', $nullable['related_issuance_id'] ?? null);

        // quantity stays NOT NULL — an `other` incident stores 0, not null.
        $this->assertSame('NO', $nullable['quantity'] ?? null, 'quantity must stay NOT NULL');
    }

    public function test_related_issuance_has_a_foreign_key_but_source_id_still_does_not(): void
    {
        $actual = collect(DB::select(
            "SELECT COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS rt
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'incident_reports'
               AND REFERENCED_TABLE_NAME IS NOT NULL"
        ))->mapWithKeys(fn ($r) => [$r->c => $r->rt]);

        $this->assertSame('issuances', $actual['related_issuance_id'] ?? null);
        // product_id's FK must survive the drop/re-add dance in the migration.
        $this->assertSame('products', $actual['product_id'] ?? null);
        // source_id is polymorphic (issuance|transfer|standalone) and must NOT have a FK.
        $this->assertArrayNotHasKey('source_id', $actual->all());
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=IncidentSchemaTest
```

Expected: FAIL — `incident_reports missing occurred_at`.

- [ ] **Step 3: Write the migration**

Create `back-end/database/migrations/2026_08_03_000001_extend_incident_reports_for_standalone.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opens incident_reports up to standalone reporting.
 *
 * source_id and product_id become nullable because an incident is no longer
 * necessarily discovered during a pick: a standalone report has no source
 * transaction, and an `other` incident ("the cold-room door lock is broken")
 * has no product. quantity deliberately stays NOT NULL DEFAULT 0 — an `other`
 * incident stores 0, and a nullable quantity would buy a distinction nothing
 * consumes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // product_id carries a foreign key, so MySQL needs it dropped before the
        // column's nullability can change, then re-added afterwards.
        Schema::table('incident_reports', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('incident_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('source_id')->nullable()->change();
            $table->unsignedBigInteger('product_id')->nullable()->change();

            if (! Schema::hasColumn('incident_reports', 'occurred_at')) {
                // When it actually happened, as opposed to created_at (when it was
                // filed). A theft noticed the next morning has two different dates.
                $table->dateTime('occurred_at')->nullable()->after('narrative');
            }

            if (! Schema::hasColumn('incident_reports', 'related_issuance_id')) {
                // The sale a customer_return came from. Deliberately NOT source_id:
                // source_type/source_id mean "discovered while picking X", this
                // means "this incident is about sale Y".
                $table->unsignedBigInteger('related_issuance_id')->nullable()->after('source_id');
            }
        });

        Schema::table('incident_reports', function (Blueprint $table) {
            $table->foreign('product_id')->references('product_id')->on('products')->restrictOnDelete();
            $table->foreign('related_issuance_id')->references('issuance_id')->on('issuances')->nullOnDelete();
            $table->index('related_issuance_id', 'incident_related_issuance_idx');
        });
    }

    public function down(): void
    {
        Schema::table('incident_reports', function (Blueprint $table) {
            $table->dropForeign(['related_issuance_id']);
            $table->dropIndex('incident_related_issuance_idx');
            $table->dropColumn(['occurred_at', 'related_issuance_id']);
        });
    }
};
```

- [ ] **Step 4: Apply the migration to both databases**

```bash
cd back-end
php artisan migrate --force --path=database/migrations/2026_08_03_000001_extend_incident_reports_for_standalone.php
DB_DATABASE=airkingV5_test php artisan migrate --force --path=database/migrations/2026_08_03_000001_extend_incident_reports_for_standalone.php
```

Expected: `DONE` twice. If the second reports "Nothing to migrate", the test database already ran it — check `SELECT * FROM airkingV5_test.migrations ORDER BY id DESC LIMIT 3;`.

- [ ] **Step 5: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=IncidentSchemaTest
```

Expected: PASS, 5 tests.

- [ ] **Step 6: Run the whole incident suite to confirm nothing regressed**

```bash
cd back-end && php artisan test --filter=Incident
```

Expected: PASS, 23 tests (5 schema + 6 filing + 12 resolution).

- [ ] **Step 7: Commit**

```bash
git add back-end/database/migrations/2026_08_03_000001_extend_incident_reports_for_standalone.php back-end/tests/Feature/Sets/IncidentSchemaTest.php
git commit -F - <<'EOF'
feat(incidents): make source_id and product_id nullable, add occurred_at

Standalone incident reports have no source transaction, and an `other`
incident has no product. Adds related_issuance_id for customer returns.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

### Task 2: Model support for the new columns

**Files:**
- Modify: `back-end/app/Models/IncidentReport.php`
- Test: `back-end/tests/Feature/Sets/IncidentSchemaTest.php`

This task adds only the column plumbing. The four new reason constants arrive later, each alongside the strategy that resolves it — adding a reason with no strategy would let `resolve()` close an incident with no artifact.

- [ ] **Step 1: Write the failing test**

Add to `back-end/tests/Feature/Sets/IncidentSchemaTest.php`:

```php
    public function test_occurred_at_and_related_issuance_round_trip_through_the_model(): void
    {
        $loc     = $this->makeLocation();
        $product = $this->makeConsumable();
        $user    = $this->makeUserAt($loc, 'warehouse_personnel');

        $incident = IncidentReport::create([
            'incident_number' => 'INC-TEST-' . uniqid(),
            'source_type'     => 'standalone',
            'source_id'       => null,
            'location_id'     => $loc->location_id,
            'product_id'      => $product->product_id,
            'reason'          => IncidentReport::REASON_NOT_FOUND,
            'quantity'        => 3,
            'narrative'       => 'Counted short during a walk-through.',
            'occurred_at'     => now()->subDay(),
            'reported_by'     => $user->user_id,
            'status_id'       => IncidentReport::statusId('Open'),
        ]);

        $fresh = $incident->fresh();
        $this->assertNull($fresh->source_id);
        // Cast to a Carbon instance, not left as a string.
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->occurred_at);
        $this->assertSame(now()->subDay()->toDateString(), $fresh->occurred_at->toDateString());
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=test_occurred_at_and_related_issuance_round_trip_through_the_model
```

Expected: FAIL — `occurred_at` is not fillable, so it is null and `assertInstanceOf` fails.

- [ ] **Step 3: Add the columns to the model**

In `back-end/app/Models/IncidentReport.php`, replace the `$fillable` and `$casts` blocks:

```php
    protected $fillable = [
        'incident_number', 'source_type', 'source_id', 'related_issuance_id', 'location_id',
        'product_id', 'batch_id', 'serial_id', 'expected_zone', 'actual_zone',
        'reason', 'quantity', 'narrative', 'occurred_at', 'photo_path', 'reported_by',
        'status_id', 'reviewed_by', 'reviewed_at',
        'adjustment_id', 'correction_id', 'resolution_note',
    ];

    protected $casts = [
        'quantity'    => 'integer',
        'reviewed_at' => 'datetime',
        'occurred_at' => 'datetime',
    ];
```

Then add this relation after the existing `correction()` method, before the closing brace:

```php
    /** The sale a customer_return came from. Null for every other reason. */
    public function relatedIssuance(): BelongsTo
    {
        return $this->belongsTo(Issuance::class, 'related_issuance_id', 'issuance_id');
    }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=IncidentSchemaTest
```

Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Models/IncidentReport.php back-end/tests/Feature/Sets/IncidentSchemaTest.php
git commit -F - <<'EOF'
feat(incidents): add occurred_at and related_issuance to the model

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Phase 2 — Strategy extraction (pure refactor)

### Task 3: Split resolve() into per-reason strategies

**Files:**
- Create: `back-end/app/Services/Incidents/ResolvesIncident.php`
- Create: `back-end/app/Services/Incidents/WriteOffResolution.php`
- Create: `back-end/app/Services/Incidents/RetypeResolution.php`
- Create: `back-end/app/Services/Incidents/RelocateResolution.php`
- Modify: `back-end/app/Services/IncidentService.php`
- Test: `back-end/tests/Feature/Sets/IncidentResolutionTest.php` — **read only, do not edit**

This is a behaviour-preserving refactor. The existing 12 tests are the specification. Do not add tests here and do not change the existing ones.

- [ ] **Step 1: Confirm the safety net is green before touching anything**

```bash
cd back-end && php artisan test --filter=IncidentResolutionTest
```

Expected: PASS, 12 tests. If this is not green, stop — the baseline is broken and the refactor cannot be validated.

- [ ] **Step 2: Create the interface**

Create `back-end/app/Services/Incidents/ResolvesIncident.php`:

```php
<?php

namespace App\Services\Incidents;

use App\Models\IncidentReport;

/**
 * One resolution outcome for one family of incident reasons.
 *
 * IncidentService owns the transaction, the row lock, the re-check that the
 * incident is still Open, and the status stamping. A strategy owns only the
 * artifact it posts — so adding a reason means adding a class, not lengthening
 * a switch, and each outcome can be read and tested on its own.
 */
interface ResolvesIncident
{
    /**
     * Guards that don't touch the database — cheap to fail fast, before the
     * transaction opens.
     *
     * @throws \InvalidArgumentException when the incident cannot be confirmed
     */
    public function validate(IncidentReport $incident, array $options): void;

    /**
     * Post the artifact. Runs INSIDE IncidentService's transaction, with the
     * incident row already locked and confirmed Open.
     */
    public function apply(IncidentReport $incident, array $options, int $reviewedBy): ResolutionArtifacts;
}
```

**Note (amended after Task 3's code review):** `apply()` returns a `ResolutionArtifacts` value object, not an array. `back-end/app/Services/Incidents/ResolutionArtifacts.php`:

```php
final class ResolutionArtifacts
{
    private function __construct(
        public readonly ?int $adjustmentId = null,
        public readonly ?int $correctionId = null,
    ) {}

    public static function adjustment(int $id): self { return new self(adjustmentId: $id); }
    public static function correction(int $id): self { return new self(correctionId: $id); }
    public static function none(): self { return new self(); }
}
```

An enforced return type beats a documented one, and `ResolutionArtifacts::none()` says what it means where `['adjustment_id' => null, 'correction_id' => null]` did not. `IncidentService` reads `$posted->adjustmentId` / `$posted->correctionId`.

- [ ] **Step 3: Create WriteOffResolution**

Create `back-end/app/Services/Incidents/WriteOffResolution.php`. The body is `IncidentService::postDeduction()` moved verbatim, including the clamped `deducted` that code review introduced:

```php
<?php

namespace App\Services\Incidents;

use App\Models\Adjustment;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;

/**
 * Stock is gone. Write it off the batch and the location inventory under an
 * approved Adjustment.
 */
class WriteOffResolution implements ResolvesIncident
{
    public function validate(IncidentReport $incident, array $options): void
    {
        // Nothing to guard: a write-off is valid with or without a batch.
    }

    public function apply(IncidentReport $incident, array $options, int $reviewedBy): ResolutionArtifacts
    {
        $requested = max(0, (int) $incident->quantity);

        $batch = $incident->batch_id !== null
            ? InventoryBatch::lockForUpdate()->find($incident->batch_id)
            : null;

        $inventory = Inventory::where('location_id', $incident->location_id)
            ->where('product_id', $incident->product_id)
            ->lockForUpdate()
            ->first();

        // The real write-off cannot exceed what the batch actually holds (or, with
        // no batch, what the location holds). ONE deducted amount drives the
        // ledger, the batch, and the location total alike — clamping them
        // independently vanished units that no batch ever lost.
        $available = $batch !== null
            ? (int) $batch->quantity_remaining
            : (int) ($inventory->quantity_on_hand ?? $requested);
        $deducted = min($requested, $available);

        $adjustment = Adjustment::create([
            'location_id'             => (int) $incident->location_id,
            'created_by'              => (int) $incident->reported_by,
            'approved_by'             => $reviewedBy,
            'adjustment_number'       => 'ADJ-INC-' . strtoupper(substr(uniqid(), -6)),
            'adjustment_date'         => now()->toDateString(),
            'adjustment_type'         => $this->adjustmentType($incident->reason),
            'total_variance_positive' => 0,
            'total_variance_negative' => $deducted,
            'notes'                   => "Incident {$incident->incident_number}: {$incident->narrative}",
            'status_id'               => 20, // adjustment "Approved" — verified in status_lookup
        ]);

        $adjustment->details()->create([
            'product_id'      => (int) $incident->product_id,
            'add_quantity'    => 0,
            'deduct_quantity' => $deducted,
            'reason'          => $incident->reason,
        ]);

        if ($batch !== null) {
            $batch->decrement('quantity_remaining', $deducted);
        }

        if ($inventory !== null) {
            $inventory->decrement('quantity_on_hand', min($deducted, (int) $inventory->quantity_on_hand));
            $inventory->decrement('available_quantity', min($deducted, (int) $inventory->available_quantity));
        }

        return ResolutionArtifacts::adjustment((int) $adjustment->adjustment_id);
    }

    private function adjustmentType(string $reason): string
    {
        return $reason === IncidentReport::REASON_DAMAGED ? 'damage' : 'loss';
    }
}
```

- [ ] **Step 4: Create RetypeResolution**

Create `back-end/app/Services/Incidents/RetypeResolution.php`. Body is `postCorrection()` plus the two `wrong_type` guards that currently sit at the top of `resolve()`:

```php
<?php

namespace App\Services\Incidents;

use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ScanCorrection;

/**
 * The batch was received as the wrong product. Re-type it to what is
 * physically on the shelf and move the inventory to match.
 */
class RetypeResolution implements ResolvesIncident
{
    public function validate(IncidentReport $incident, array $options): void
    {
        if (empty($options['corrected_product_id'])) {
            throw new \InvalidArgumentException('A corrected product is required to resolve a wrong-type incident.');
        }

        if ($incident->batch_id === null) {
            // With no batch there is nothing to re-type, so closing this would
            // leave the real stock wrong while the record reads as fixed.
            throw new \InvalidArgumentException('A wrong-type incident must reference a batch to re-type.');
        }
    }

    public function apply(IncidentReport $incident, array $options, int $reviewedBy): ResolutionArtifacts
    {
        $correctedProductId = (int) $options['corrected_product_id'];

        // Locked to match WriteOffResolution's discipline — the re-type and the
        // paired inventory move must see and hold a consistent batch row.
        $batch = $incident->batch_id !== null
            ? InventoryBatch::lockForUpdate()->find($incident->batch_id)
            : null;

        $correction = ScanCorrection::create([
            'receiving_id'         => $batch?->receiving_id,
            'original_product_id'  => (int) $incident->product_id,
            'corrected_product_id' => $correctedProductId,
            'correction_type'      => 'wrong_product',
            'quantity_before'      => $batch !== null ? (int) $batch->quantity_remaining : (int) $incident->quantity,
            'quantity_after'       => $batch !== null ? (int) $batch->quantity_remaining : (int) $incident->quantity,
            'serial_id'            => $incident->serial_id,
            'reason'               => "Incident {$incident->incident_number}: {$incident->narrative}",
            'corrected_by'         => (int) $incident->reported_by,
            'approved_by'          => $reviewedBy,
        ]);

        if ($batch !== null) {
            $quantity = (int) $batch->quantity_remaining;

            $batch->update(['product_id' => $correctedProductId]);

            $from = Inventory::where('location_id', $incident->location_id)
                ->where('product_id', $incident->product_id)->lockForUpdate()->first();
            if ($from !== null) {
                $from->decrement('quantity_on_hand', min($quantity, (int) $from->quantity_on_hand));
                $from->decrement('available_quantity', min($quantity, (int) $from->available_quantity));
            }

            $to = Inventory::firstOrCreate(
                ['location_id' => (int) $incident->location_id, 'product_id' => $correctedProductId],
                ['quantity_on_hand' => 0, 'available_quantity' => 0, 'reorder_level' => 0]
            );
            $to->increment('quantity_on_hand', $quantity);
            $to->increment('available_quantity', $quantity);
        }

        return ResolutionArtifacts::correction((int) $correction->correction_id);
    }
}
```

- [ ] **Step 5: Create RelocateResolution**

Create `back-end/app/Services/Incidents/RelocateResolution.php`:

```php
<?php

namespace App\Services\Incidents;

use App\Models\IncidentReport;
use App\Models\InventoryBatch;

/** Stock exists, it is simply somewhere else. No quantity moves. */
class RelocateResolution implements ResolvesIncident
{
    public function validate(IncidentReport $incident, array $options): void
    {
        // A relocation with no batch or no actual zone is a no-op, not an error —
        // preserving the pre-refactor behaviour.
    }

    public function apply(IncidentReport $incident, array $options, int $reviewedBy): ResolutionArtifacts
    {
        if ($incident->batch_id !== null && $incident->actual_zone !== null) {
            InventoryBatch::where('batch_id', $incident->batch_id)
                ->update(['zone_label' => $incident->actual_zone]);
        }

        return ResolutionArtifacts::none();
    }
}
```

- [ ] **Step 6: Rewrite IncidentService::resolve() to delegate**

In `back-end/app/Services/IncidentService.php`, add these imports below the existing `use` lines:

```php
use App\Services\Incidents\RelocateResolution;
use App\Services\Incidents\ResolvesIncident;
use App\Services\Incidents\RetypeResolution;
use App\Services\Incidents\WriteOffResolution;
```

Replace everything from the `/** Resolve an open incident...` docblock to the end of the class (that is: `resolve()`, `postDeduction()`, `postCorrection()`, and `relocate()`) with:

```php
    /**
     * Resolve an open incident. The artifact depends on the reason and is
     * produced by a strategy; this method owns only what must never be
     * duplicated — the transaction, the row lock, the still-Open re-check, and
     * the status stamping.
     *
     * Dismissing produces nothing and returns the stock to FIFO rotation.
     */
    public function resolve(IncidentReport $incident, string $action, array $options, int $reviewedBy): IncidentReport
    {
        $strategy = $this->strategyFor($incident->reason);

        // Cheap pre-flight, outside the transaction. Re-checked under the lock
        // below where it actually matters.
        if ($action === 'confirm') {
            $strategy->validate($incident, $options);
        }

        return DB::transaction(function () use ($incident, $action, $options, $reviewedBy, $strategy) {
            // Lock the incident row and re-check Open UNDER the lock, so two
            // reviewers resolving at once cannot both pass the guard and
            // double-book the write-off. The plain read outside a transaction
            // would let both through.
            $locked = IncidentReport::whereKey($incident->getKey())->lockForUpdate()->first();

            if ($locked === null || (int) $locked->status_id !== IncidentReport::statusId('Open')) {
                throw new \RuntimeException('This incident has already been reviewed.');
            }

            if ($action === 'dismiss') {
                $locked->update([
                    'status_id'       => IncidentReport::statusId('Dismissed'),
                    'reviewed_by'     => $reviewedBy,
                    'reviewed_at'     => now(),
                    'resolution_note' => $options['resolution_note'] ?? null,
                ]);

                return $locked->fresh();
            }

            $strategy->validate($locked, $options);
            $posted = $strategy->apply($locked, $options, $reviewedBy);

            $locked->update([
                'status_id'       => IncidentReport::statusId('Resolved'),
                'reviewed_by'     => $reviewedBy,
                'reviewed_at'     => now(),
                'adjustment_id'   => $posted->adjustmentId,
                'correction_id'   => $posted->correctionId,
                'resolution_note' => $options['resolution_note'] ?? null,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * No default arm: `match` throws UnhandledMatchError if a reason is added
     * without a strategy, which is exactly the failure we want — loud, at the
     * first resolve, rather than an incident silently closing with no artifact.
     */
    private function strategyFor(string $reason): ResolvesIncident
    {
        return match ($reason) {
            IncidentReport::REASON_NOT_FOUND,
            IncidentReport::REASON_DAMAGED       => new WriteOffResolution(),
            IncidentReport::REASON_WRONG_TYPE    => new RetypeResolution(),
            IncidentReport::REASON_ZONE_MISMATCH => new RelocateResolution(),
        };
    }
}
```

Make sure you leave `file()`, `excludedBatchIds()`, `incidentCountFor()`, and `nextNumber()` untouched, and that the file ends with exactly one closing brace for the class.

- [ ] **Step 7: Run the safety net — it must pass unmodified**

```bash
cd back-end && php artisan test --filter=IncidentResolutionTest
```

Expected: PASS, 12 tests. If any fail, the refactor changed behaviour. Compare against `git show HEAD:back-end/app/Services/IncidentService.php` rather than editing the tests.

- [ ] **Step 8: Run the full suite**

```bash
cd back-end && php artisan test
```

Expected: PASS. Note the total for later comparison.

- [ ] **Step 9: Commit**

```bash
git add back-end/app/Services/Incidents back-end/app/Services/IncidentService.php
git commit -F - <<'EOF'
refactor(incidents): split resolve() into per-reason strategies

Behaviour-preserving. IncidentService keeps the transaction, row lock,
still-Open re-check and status stamping; each artifact moves to its own
class behind ResolvesIncident. The 12 IncidentResolutionTest cases pass
unmodified.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Phase 3 — New reasons

### Task 4: theft and delivery_damage

**Files:**
- Modify: `back-end/app/Models/IncidentReport.php`
- Modify: `back-end/app/Services/Incidents/WriteOffResolution.php`
- Create: `back-end/tests/Feature/Sets/IncidentStandaloneFilingTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Sets/IncidentStandaloneFilingTest.php`:

```php
<?php

namespace Tests\Feature\Sets;

use App\Models\Adjustment;
use App\Models\IncidentReport;
use App\Models\InventoryBatch;
use App\Services\IncidentService;

class IncidentStandaloneFilingTest extends SetTestCase
{
    public function test_theft_writes_off_as_a_loss(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 10, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'batch_id'    => $b->batch_id,
            'reason'      => IncidentReport::REASON_THEFT,
            'quantity'    => 4,
            'narrative'   => 'Four units gone overnight, side door was unlatched.',
            'occurred_at' => now()->subDay(),
        ], (int) $wp->user_id);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $adjustment = Adjustment::find($incident->fresh()->adjustment_id);
        $this->assertSame('loss', $adjustment->adjustment_type);
        $this->assertSame(6, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
    }

    public function test_delivery_damage_writes_off_as_damage(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 10, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'batch_id'    => $b->batch_id,
            'reason'      => IncidentReport::REASON_DELIVERY_DAMAGE,
            'quantity'    => 2,
            'narrative'   => 'Crushed in the van, customer refused it.',
        ], (int) $wp->user_id);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $this->assertSame('damage', Adjustment::find($incident->fresh()->adjustment_id)->adjustment_type);
        $this->assertSame(8, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
    }

    public function test_a_standalone_report_still_holds_its_batch_out_of_fifo(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 10, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        // The FIFO hold is one rule with no special cases: how the incident was
        // discovered does not change whether the batch is trustworthy.
        app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'batch_id'    => $b->batch_id,
            'reason'      => IncidentReport::REASON_THEFT,
            'quantity'    => 1,
            'narrative'   => 'One unit missing from the shelf.',
        ], (int) $wp->user_id);

        $this->assertContains(
            (int) $b->batch_id,
            app(IncidentService::class)->excludedBatchIds($loc->location_id)
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=IncidentStandaloneFilingTest
```

Expected: FAIL — `Undefined constant App\Models\IncidentReport::REASON_THEFT`.

- [ ] **Step 3: Add the two constants**

In `back-end/app/Models/IncidentReport.php`, replace the constants block:

```php
    public const REASON_NOT_FOUND       = 'not_found';
    public const REASON_DAMAGED         = 'damaged';
    public const REASON_WRONG_TYPE      = 'wrong_type';
    public const REASON_ZONE_MISMATCH   = 'zone_mismatch';
    public const REASON_THEFT           = 'theft';
    public const REASON_DELIVERY_DAMAGE = 'delivery_damage';

    public const REASONS = [
        self::REASON_NOT_FOUND,
        self::REASON_DAMAGED,
        self::REASON_WRONG_TYPE,
        self::REASON_ZONE_MISMATCH,
        self::REASON_THEFT,
        self::REASON_DELIVERY_DAMAGE,
    ];
```

- [ ] **Step 3b: Let `file()` accept a standalone report with no source_id**

**Added after plan review.** Task 4's tests file with `source_type: 'standalone'` and no `source_id`, but `file()` still does `(int) $data['source_id']` — a missing key raises an "Undefined array key" warning and stores `0`, not null. The rest of the `file()` relaxation (product and quantity) stays in Task 5; this is only the line the new tests actually need.

In `back-end/app/Services/IncidentService.php`, inside `file()`'s `IncidentReport::create([...])`, replace:

```php
            'source_id'       => (int) $data['source_id'],
```

with:

```php
            // A standalone report has no source transaction to point at.
            'source_id'       => isset($data['source_id']) ? (int) $data['source_id'] : null,
```

- [ ] **Step 4: Map the new reasons in WriteOffResolution and the strategy table**

In `back-end/app/Services/Incidents/WriteOffResolution.php`, replace `adjustmentType()`:

```php
    /**
     * "Loss" means the stock is gone; "damage" means it exists but is unsellable.
     * theft is deliberately its own reason rather than not_found — the
     * distinction is the point for shrinkage reporting — but both post a loss.
     */
    private function adjustmentType(string $reason): string
    {
        return in_array($reason, [IncidentReport::REASON_DAMAGED, IncidentReport::REASON_DELIVERY_DAMAGE], true)
            ? 'damage'
            : 'loss';
    }
```

In `back-end/app/Services/IncidentService.php`, extend `strategyFor()`'s first arm:

```php
        return match ($reason) {
            IncidentReport::REASON_NOT_FOUND,
            IncidentReport::REASON_THEFT,
            IncidentReport::REASON_DAMAGED,
            IncidentReport::REASON_DELIVERY_DAMAGE => new WriteOffResolution(),
            IncidentReport::REASON_WRONG_TYPE      => new RetypeResolution(),
            IncidentReport::REASON_ZONE_MISMATCH   => new RelocateResolution(),
        };
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=IncidentStandaloneFilingTest
```

Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Models/IncidentReport.php back-end/app/Services/Incidents/WriteOffResolution.php back-end/app/Services/IncidentService.php back-end/tests/Feature/Sets/IncidentStandaloneFilingTest.php
git commit -F - <<'EOF'
feat(incidents): add theft and delivery_damage reasons

Both route to WriteOffResolution; theft posts a loss, delivery_damage a
damage. Kept distinct from not_found/damaged because the distinction is
what shrinkage reporting is for.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

### Task 5: The `other` reason and product-less filing

**Files:**
- Modify: `back-end/app/Models/IncidentReport.php`
- Modify: `back-end/app/Services/IncidentService.php`
- Create: `back-end/app/Services/Incidents/AcknowledgeResolution.php`
- Test: `back-end/tests/Feature/Sets/IncidentStandaloneFilingTest.php`

- [ ] **Step 1: Write the failing test**

Add to `back-end/tests/Feature/Sets/IncidentStandaloneFilingTest.php`:

```php
    public function test_an_other_incident_needs_no_product_and_posts_no_artifact(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');

        $incident = app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'reason'      => IncidentReport::REASON_OTHER,
            'narrative'   => 'Cold-room door lock is broken, anyone can walk in.',
        ], (int) $wp->user_id);

        $this->assertNull($incident->product_id);
        // quantity stays NOT NULL — an `other` incident stores 0, never null.
        $this->assertSame(0, (int) $incident->quantity);

        // Acknowledge sends the existing 'confirm' action; only the label differs.
        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", [
                 'action' => 'confirm', 'resolution_note' => 'Maintenance ticket raised.',
             ])->assertOk();

        $fresh = $incident->fresh();
        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $fresh->status_id);
        $this->assertNull($fresh->adjustment_id, 'acknowledging is not a stock movement');
        $this->assertNull($fresh->correction_id);
    }

    public function test_a_stock_affecting_reason_still_requires_a_product(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');

        $this->expectException(\InvalidArgumentException::class);

        app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'reason'      => IncidentReport::REASON_THEFT,
            'quantity'    => 2,
            'narrative'   => 'Something went missing but I did not say what.',
        ], (int) $wp->user_id);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=IncidentStandaloneFilingTest
```

Expected: FAIL — `Undefined constant App\Models\IncidentReport::REASON_OTHER`.

- [ ] **Step 3: Add the constant and the stock-affecting list**

In `back-end/app/Models/IncidentReport.php`, add `REASON_OTHER` to the constants and `REASONS`, then add `STOCK_AFFECTING_REASONS` below `REASONS`:

```php
    public const REASON_OTHER           = 'other';

    public const REASONS = [
        self::REASON_NOT_FOUND,
        self::REASON_DAMAGED,
        self::REASON_WRONG_TYPE,
        self::REASON_ZONE_MISMATCH,
        self::REASON_THEFT,
        self::REASON_DELIVERY_DAMAGE,
        self::REASON_OTHER,
    ];

    /**
     * Reasons whose resolution moves stock, and which therefore cannot be filed
     * without a product and a quantity. Everything except `other`.
     */
    public const STOCK_AFFECTING_REASONS = [
        self::REASON_NOT_FOUND,
        self::REASON_DAMAGED,
        self::REASON_WRONG_TYPE,
        self::REASON_ZONE_MISMATCH,
        self::REASON_THEFT,
        self::REASON_DELIVERY_DAMAGE,
    ];
```

- [ ] **Step 4: Create AcknowledgeResolution**

Create `back-end/app/Services/Incidents/AcknowledgeResolution.php`:

```php
<?php

namespace App\Services\Incidents;

use App\Models\IncidentReport;

/**
 * A free-form observation. Reviewing it closes the record and nothing else —
 * no adjustment, no correction, no stock movement.
 */
class AcknowledgeResolution implements ResolvesIncident
{
    public function validate(IncidentReport $incident, array $options): void
    {
        // Nothing to guard — there is no artifact to get wrong.
    }

    public function apply(IncidentReport $incident, array $options, int $reviewedBy): ResolutionArtifacts
    {
        return ResolutionArtifacts::none();
    }
}
```

- [ ] **Step 5: Relax file() and register the strategy**

In `back-end/app/Services/IncidentService.php`, add the import:

```php
use App\Services\Incidents\AcknowledgeResolution;
```

Replace the `file()` method:

```php
    public function file(array $data, int $reportedBy): IncidentReport
    {
        $reason = $data['reason'] ?? null;

        if (! in_array($reason, IncidentReport::REASONS, true)) {
            throw new \InvalidArgumentException('Unknown incident reason: ' . ($reason ?? 'null'));
        }

        // Everything except `other` resolves into a stock movement, so it cannot
        // be filed without knowing which product and how many. An `other`
        // incident ("the door lock is broken") has neither.
        if (in_array($reason, IncidentReport::STOCK_AFFECTING_REASONS, true)) {
            if (empty($data['product_id'])) {
                throw new \InvalidArgumentException("A product is required for a '{$reason}' incident.");
            }
            if ((int) ($data['quantity'] ?? 0) < 1) {
                throw new \InvalidArgumentException("A quantity of at least 1 is required for a '{$reason}' incident.");
            }
        }

        return DB::transaction(fn () => IncidentReport::create([
            'incident_number'     => $this->nextNumber(),
            'source_type'         => $data['source_type'],
            // (source_id's null handling already landed in Task 4, Step 3b.)
            'source_id'           => isset($data['source_id']) ? (int) $data['source_id'] : null,
            'related_issuance_id' => isset($data['related_issuance_id']) ? (int) $data['related_issuance_id'] : null,
            'location_id'         => (int) $data['location_id'],
            'product_id'          => isset($data['product_id']) ? (int) $data['product_id'] : null,
            'batch_id'            => $data['batch_id'] ?? null,
            'serial_id'           => $data['serial_id'] ?? null,
            'expected_zone'       => $data['expected_zone'] ?? null,
            'actual_zone'         => $data['actual_zone'] ?? null,
            'reason'              => $reason,
            'quantity'            => (int) ($data['quantity'] ?? 0),
            'narrative'           => $data['narrative'] ?? null,
            'occurred_at'         => $data['occurred_at'] ?? null,
            'photo_path'          => $data['photo_path'] ?? null,
            'reported_by'         => $reportedBy,
            'status_id'           => IncidentReport::statusId('Open'),
        ]));
    }
```

Add the `other` arm to `strategyFor()`, after the `REASON_ZONE_MISMATCH` line:

```php
            IncidentReport::REASON_OTHER           => new AcknowledgeResolution(),
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=IncidentStandaloneFilingTest
```

Expected: PASS, 5 tests.

- [ ] **Step 7: Run the whole incident suite**

```bash
cd back-end && php artisan test --filter=Incident
```

Expected: PASS, 29 tests.

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Models/IncidentReport.php back-end/app/Services/IncidentService.php back-end/app/Services/Incidents/AcknowledgeResolution.php back-end/tests/Feature/Sets/IncidentStandaloneFilingTest.php
git commit -F - <<'EOF'
feat(incidents): add the `other` reason and product-less filing

file() now requires product + quantity only for stock-affecting reasons.
`other` resolves through AcknowledgeResolution, which posts no artifact.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

### Task 6: customer_return and RestockResolution

**Files:**
- Modify: `back-end/app/Models/IncidentReport.php`
- Modify: `back-end/app/Services/IncidentService.php`
- Create: `back-end/app/Services/Incidents/RestockResolution.php`
- Create: `back-end/tests/Feature/Sets/IncidentRestockTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Sets/IncidentRestockTest.php`:

```php
<?php

namespace Tests\Feature\Sets;

use App\Models\Adjustment;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Services\IncidentService;

class IncidentRestockTest extends SetTestCase
{
    private function openReturn($loc, $product, $batch, int $qty = 3, ?int $serialId = null): IncidentReport
    {
        $wp = $this->makeUserAt($loc, 'warehouse_personnel');

        return app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $product->product_id,
            'batch_id'    => $batch?->batch_id,
            'serial_id'   => $serialId,
            'reason'      => IncidentReport::REASON_CUSTOMER_RETURN,
            'quantity'    => $qty,
            'narrative'   => 'Wrong model delivered, customer sent it back.',
        ], (int) $wp->user_id);
    }

    public function test_a_return_puts_stock_back_on_its_original_batch(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();

        $this->setStock($loc->location_id, $p->product_id, 20);
        $b = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now()->subDays(30), 'zone_label' => 'Zone 1']);

        $incident = $this->openReturn($loc, $p, $b, 3);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", [
                 'action' => 'confirm', 'resolution_note' => 'Inspected, back on the shelf.',
             ])->assertOk();

        $fresh = $incident->fresh();
        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $fresh->status_id);
        $this->assertNotNull($fresh->adjustment_id);

        // The stock returns to the batch it left, so its true received_at — and
        // therefore its place in FIFO order — is preserved.
        $this->assertSame(8, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
        $this->assertSame(1, (int) InventoryBatch::where('product_id', $p->product_id)->count());

        $inv = Inventory::where('location_id', $loc->location_id)->where('product_id', $p->product_id)->first();
        $this->assertSame(23, (int) $inv->quantity_on_hand);
        $this->assertSame(23, (int) $inv->available_quantity);
    }

    public function test_the_adjustment_records_an_addition(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openReturn($loc, $p, $b, 4);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $adjustment = Adjustment::with('details')->find($incident->fresh()->adjustment_id);
        $this->assertSame('return', $adjustment->adjustment_type);
        $this->assertSame(4, (int) $adjustment->total_variance_positive);
        $this->assertSame(0, (int) $adjustment->total_variance_negative);
        $this->assertSame(4, (int) $adjustment->details->first()->add_quantity);
        $this->assertSame(0, (int) $adjustment->details->first()->deduct_quantity);
    }

    public function test_a_return_with_no_batch_creates_one(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();

        $incident = $this->openReturn($loc, $p, null, 2);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $batch = InventoryBatch::where('product_id', $p->product_id)
            ->where('location_id', $loc->location_id)
            ->first();

        $this->assertNotNull($batch, 'returned stock with no batch must land in a new one');
        $this->assertNull($batch->receiving_id, 'a return did not come from a receiving');
        $this->assertSame(2, (int) $batch->quantity_remaining);
        $this->assertSame(2, (int) $batch->quantity_received);
    }

    public function test_a_returned_serial_goes_back_in_stock(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeAppliance();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 1, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $serial = $this->makeSerial($p, $loc, 'SN-RETURN-001', $b->receiving_id);
        $serial->update(['status_id' => 27]); // anything other than in-stock

        $incident = $this->openReturn($loc, $p, $b, 1, (int) $serial->serial_id);

        $this->actingAs($ia, 'sanctum')
             ->postJson("/api/incident-reports/{$incident->incident_id}/resolve", ['action' => 'confirm'])
             ->assertOk();

        $this->assertSame(
            ItemSerial::IN_STOCK_STATUS_ID,
            (int) ItemSerial::find($serial->serial_id)->status_id
        );
    }

    public function test_resolving_a_return_twice_is_rejected(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 5, 'received_at' => now(), 'zone_label' => 'Zone 1']);

        $incident = $this->openReturn($loc, $p, $b, 3);
        $url = "/api/incident-reports/{$incident->incident_id}/resolve";

        $this->actingAs($ia, 'sanctum')->postJson($url, ['action' => 'confirm'])->assertOk();
        $this->actingAs($ia, 'sanctum')->postJson($url, ['action' => 'confirm'])->assertStatus(422);

        // Stock came back exactly once.
        $this->assertSame(8, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=IncidentRestockTest
```

Expected: FAIL — `Undefined constant App\Models\IncidentReport::REASON_CUSTOMER_RETURN`.

- [ ] **Step 3: Add the constant**

In `back-end/app/Models/IncidentReport.php`, add the constant and append it to **both** `REASONS` and `STOCK_AFFECTING_REASONS` (a return moves stock, so it needs a product and a quantity):

```php
    public const REASON_CUSTOMER_RETURN = 'customer_return';
```

`REASONS` becomes the eight-value list; `STOCK_AFFECTING_REASONS` becomes the same list minus `REASON_OTHER`.

- [ ] **Step 4: Create RestockResolution**

Create `back-end/app/Services/Incidents/RestockResolution.php`:

```php
<?php

namespace App\Services\Incidents;

use App\Models\Adjustment;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;

/**
 * The customer sent it back. This is the only resolution that ADDS stock.
 *
 * Returned units go back onto the batch they left, so the batch keeps its true
 * received_at and its place in FIFO order — a return is not new stock. Only
 * when the incident names no batch (the original was deleted, or the return
 * was filed without one) does a fresh batch get created.
 *
 * Stock comes back available and is not quarantined. If a return arrives
 * damaged, that is a second incident with reason `damaged`.
 */
class RestockResolution implements ResolvesIncident
{
    public function validate(IncidentReport $incident, array $options): void
    {
        if ((int) $incident->quantity < 1) {
            throw new \InvalidArgumentException('A customer return must specify how many units came back.');
        }
    }

    public function apply(IncidentReport $incident, array $options, int $reviewedBy): ResolutionArtifacts
    {
        $quantity = (int) $incident->quantity;

        $adjustment = Adjustment::create([
            'location_id'             => (int) $incident->location_id,
            'created_by'              => (int) $incident->reported_by,
            'approved_by'             => $reviewedBy,
            'adjustment_number'       => 'ADJ-RET-' . strtoupper(substr(uniqid(), -6)),
            'adjustment_date'         => now()->toDateString(),
            'adjustment_type'         => 'return',
            'total_variance_positive' => $quantity,
            'total_variance_negative' => 0,
            'notes'                   => "Incident {$incident->incident_number}: {$incident->narrative}",
            'status_id'               => 20, // adjustment "Approved" — verified in status_lookup
        ]);

        $adjustment->details()->create([
            'product_id'      => (int) $incident->product_id,
            'add_quantity'    => $quantity,
            'deduct_quantity' => 0,
            'reason'          => $incident->reason,
        ]);

        $this->restoreBatch($incident, $quantity);

        $inventory = Inventory::firstOrCreate(
            ['location_id' => (int) $incident->location_id, 'product_id' => (int) $incident->product_id],
            ['quantity_on_hand' => 0, 'available_quantity' => 0, 'reorder_level' => 0]
        );
        $inventory = Inventory::whereKey($inventory->getKey())->lockForUpdate()->first();
        $inventory->increment('quantity_on_hand', $quantity);
        $inventory->increment('available_quantity', $quantity);

        if ($incident->serial_id !== null) {
            ItemSerial::where('serial_id', $incident->serial_id)
                ->update(['status_id' => ItemSerial::IN_STOCK_STATUS_ID]);
        }

        return ResolutionArtifacts::adjustment((int) $adjustment->adjustment_id);
    }

    private function restoreBatch(IncidentReport $incident, int $quantity): void
    {
        $batch = $incident->batch_id !== null
            ? InventoryBatch::lockForUpdate()->find($incident->batch_id)
            : null;

        if ($batch !== null) {
            $batch->increment('quantity_remaining', $quantity);
            return;
        }

        // No batch to return to. receiving_id stays null because a return did not
        // come from a receiving — FifoPickingService already handles batchless
        // stock, and the column is nullable.
        InventoryBatch::create([
            'product_id'         => (int) $incident->product_id,
            'location_id'        => (int) $incident->location_id,
            'receiving_id'       => null,
            'quantity_received'  => $quantity,
            'quantity_remaining' => $quantity,
            'received_at'        => now(),
            'zone_label'         => null,
        ]);
    }
}
```

- [ ] **Step 5: Register the strategy**

In `back-end/app/Services/IncidentService.php`, add the import:

```php
use App\Services\Incidents\RestockResolution;
```

Add the arm to `strategyFor()`:

```php
            IncidentReport::REASON_CUSTOMER_RETURN  => new RestockResolution(),
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=IncidentRestockTest
```

Expected: PASS, 5 tests.

- [ ] **Step 7: Run the whole incident suite**

```bash
cd back-end && php artisan test --filter=Incident
```

Expected: PASS, 34 tests.

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Models/IncidentReport.php back-end/app/Services/IncidentService.php back-end/app/Services/Incidents/RestockResolution.php back-end/tests/Feature/Sets/IncidentRestockTest.php
git commit -F - <<'EOF'
feat(incidents): customer_return puts stock back on its original batch

The first resolution that adds stock. Posts an addition Adjustment,
returns units to the batch they left (preserving received_at and FIFO
order), and puts a named serial back in stock.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Phase 4 — API surface

### Task 7: Controller validation and serialization

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/IncidentReportController.php`
- Test: `back-end/tests/Feature/Sets/IncidentStandaloneFilingTest.php`

- [ ] **Step 1: Write the failing test**

Add to `back-end/tests/Feature/Sets/IncidentStandaloneFilingTest.php`:

```php
    public function test_the_endpoint_accepts_a_standalone_report_with_no_source_id(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();

        $res = $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'reason'      => 'theft',
            'quantity'    => 2,
            'narrative'   => 'Two units gone from the aisle.',
            'occurred_at' => now()->subDay()->format('Y-m-d H:i:s'),
        ]);

        $res->assertCreated();
        $this->assertStringStartsWith('INC-', $res->json('data.incident_number'));
    }

    public function test_the_endpoint_rejects_an_occurrence_in_the_future(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();

        $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'reason'      => 'theft',
            'quantity'    => 1,
            'narrative'   => 'It will be stolen tomorrow.',
            'occurred_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ])->assertStatus(422);
    }

    public function test_a_product_less_other_incident_serializes_product_id_as_null(): void
    {
        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');

        $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'reason'      => 'other',
            'narrative'   => 'Cold-room door lock is broken.',
        ])->assertCreated();

        $res = $this->actingAs($ia, 'sanctum')->getJson('/api/incident-reports?status=open');
        $res->assertOk();

        $row = collect($res->json('data'))->firstWhere('reason', 'other');
        $this->assertNotNull($row);
        // (int) null would report product #0 — a real product id — as if it were
        // the subject of the incident.
        $this->assertNull($row['product_id'], 'a product-less incident must not report product 0');
        $this->assertNull($row['source_id']);
        $this->assertSame(0, $row['quantity']);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=IncidentStandaloneFilingTest
```

Expected: FAIL — the first new test 422s, because `source_type` does not accept `standalone`.

- [ ] **Step 3: Relax the store() validation**

In `back-end/app/Http/Controllers/Api/IncidentReportController.php`, replace the `$validated = $request->validate([...])` block inside `store()`:

```php
        $validated = $request->validate([
            'source_type'         => ['required', Rule::in(['issuance', 'transfer', 'standalone'])],
            // A standalone report has no source transaction to point at.
            'source_id'           => 'required_unless:source_type,standalone|nullable|integer',
            'related_issuance_id' => 'nullable|integer|exists:issuances,issuance_id',
            'location_id'         => 'required|integer|exists:locations,location_id',
            // An `other` incident has no product and no meaningful quantity.
            'product_id'          => 'required_unless:reason,other|nullable|integer|exists:products,product_id',
            'batch_id'            => 'nullable|integer|exists:inventory_batches,batch_id',
            'serial_id'           => 'nullable|integer|exists:item_serial,serial_id',
            'expected_zone'       => 'nullable|string|max:100',
            'actual_zone'         => 'nullable|string|max:100',
            'reason'              => ['required', Rule::in(IncidentReport::REASONS)],
            'quantity'            => 'required_unless:reason,other|nullable|integer|min:1',
            'narrative'           => 'required|string|max:2000',
            // You cannot report something that has not happened yet.
            'occurred_at'         => 'nullable|date|before_or_equal:now',
            'photo_path'          => 'nullable|string|max:255',
        ]);
```

- [ ] **Step 4: Fix the serializer and expose the new fields**

Still in `IncidentReportController::index()`, replace these lines inside the `$incidents->map(...)` closure:

```php
                'source_id'               => (int) $i->source_id,
```

with:

```php
                'source_id'               => $i->source_id !== null ? (int) $i->source_id : null,
                'related_issuance_id'     => $i->related_issuance_id !== null ? (int) $i->related_issuance_id : null,
                // `issuances` has NO issuance_number column - dr_number is the
                // identifier every other serializer uses. JSON key keeps its
                // planned name so the frontend contract is unchanged.
                'related_issuance_number' => $i->relatedIssuance?->dr_number,
```

and replace:

```php
                'product_id'              => (int) $i->product_id,
```

with:

```php
                // (int) null is 0 — a real product id. An `other` incident has no
                // product and must serialize as null, like batch_id does below.
                'product_id'              => $i->product_id !== null ? (int) $i->product_id : null,
```

and replace:

```php
                'photo_path'              => $i->photo_path,
```

with:

```php
                'photo_path'              => $i->photo_path,
                // Web path, built the same way User::avatar_url is.
                'photo_url'               => $i->photo_path ? '/storage/' . ltrim($i->photo_path, '/') : null,
                'occurred_at'             => optional($i->occurred_at)->toDateTimeString(),
```

Finally, add `relatedIssuance` to the eager load at the top of `index()`:

```php
        $query = IncidentReport::with(['product', 'batch', 'location', 'reportedBy', 'reviewedBy', 'relatedIssuance'])
            ->forUser(Auth::user());   // location scoping, same trait every other list uses
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=IncidentStandaloneFilingTest
```

Expected: PASS, 8 tests.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/IncidentReportController.php back-end/tests/Feature/Sets/IncidentStandaloneFilingTest.php
git commit -F - <<'EOF'
feat(incidents): accept standalone reports through the API

Relaxes store() validation per reason, adds occurred_at and
related_issuance_id, and stops serializing a null product_id as 0.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

### Task 8: Photo upload endpoint

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/IncidentReportController.php`
- Modify: `back-end/routes/api.php`
- Create: `back-end/tests/Feature/Sets/IncidentPhotoUploadTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Sets/IncidentPhotoUploadTest.php`:

```php
<?php

namespace Tests\Feature\Sets;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class IncidentPhotoUploadTest extends SetTestCase
{
    public function test_a_filer_can_upload_a_photo_and_get_back_a_path(): void
    {
        Storage::fake('public');

        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');

        $res = $this->actingAs($wp, 'sanctum')->post('/api/incident-reports/photo', [
            'photo' => UploadedFile::fake()->image('damage.jpg'),
        ]);

        $res->assertCreated();
        $path = $res->json('data.path');
        $this->assertStringStartsWith('incidents/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_non_image_is_rejected(): void
    {
        Storage::fake('public');

        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');

        $this->actingAs($wp, 'sanctum')->post('/api/incident-reports/photo', [
            'photo' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_a_read_only_auditor_cannot_upload(): void
    {
        Storage::fake('public');

        $loc = $this->makeLocation();
        $au  = $this->makeUserAt($loc, 'auditor');

        $this->actingAs($au, 'sanctum')->post('/api/incident-reports/photo', [
            'photo' => UploadedFile::fake()->image('anything.jpg'),
        ])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=IncidentPhotoUploadTest
```

Expected: FAIL — 404, the route does not exist.

- [ ] **Step 3: Add the controller method**

In `back-end/app/Http/Controllers/Api/IncidentReportController.php`, add this method after `store()`:

```php
    /**
     * Store an evidence photo and hand back its disk path, which the caller
     * then submits as `photo_path` when filing. Two steps rather than a
     * multipart file on the file endpoint, so the picking modal can upload
     * while the user is still typing the narrative.
     *
     * Mirrors AccountController's avatar upload: `public` disk, path stored
     * disk-relative, and the web URL built by prefixing /storage/.
     */
    public function uploadPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        $path = $request->file('photo')->store('incidents', 'public');

        return response()->json([
            'success' => true,
            'message' => 'Photo uploaded.',
            'data'    => ['path' => $path, 'url' => '/storage/' . $path],
        ], 201);
    }
```

- [ ] **Step 4: Add the route**

In `back-end/routes/api.php`, immediately after the existing `POST /incident-reports` route (around line 569), add:

```php
    Route::middleware('permission:incident_reports,create')
         ->post('/incident-reports/photo', [\App\Http\Controllers\Api\IncidentReportController::class, 'uploadPhoto']);
```

Note it must come **before** the `/incident-reports/{id}/resolve` route only if a conflict existed — it does not here, since the paths differ in depth. Placing it directly after the store route keeps the block readable.

- [ ] **Step 5: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=IncidentPhotoUploadTest
```

Expected: PASS, 3 tests.

- [ ] **Step 6: Verify the storage symlink exists (dev database only)**

```bash
cd back-end && ls -la public/storage
```

Expected: a symlink to `../storage/app/public`. If it is missing, run `php artisan storage:link`.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/IncidentReportController.php back-end/routes/api.php back-end/tests/Feature/Sets/IncidentPhotoUploadTest.php
git commit -F - <<'EOF'
feat(incidents): add the evidence photo upload endpoint

photo_path has existed on the table since the module was built but
nothing ever wrote it. Mirrors AccountController's avatar upload.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

### Task 9: Let branch managers file

**Files:**
- Modify: `back-end/database/seeders/PermissionSeeder.php`
- Test: `back-end/tests/Feature/Sets/IncidentStandaloneFilingTest.php`

- [ ] **Step 1: Write the failing test**

Add to `back-end/tests/Feature/Sets/IncidentStandaloneFilingTest.php`:

```php
    public function test_a_branch_manager_can_file_but_not_resolve(): void
    {
        $loc = $this->makeLocation();
        $bm  = $this->makeUserAt($loc, 'branch_manager');
        $p   = $this->makeConsumable();

        $res = $this->actingAs($bm, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'reason'      => 'theft',
            'quantity'    => 1,
            'narrative'   => 'Noticed a unit missing during the morning walk.',
        ]);
        $res->assertCreated();

        // Maker-checker: filing a write-off and approving it stay different people.
        $this->actingAs($bm, 'sanctum')
             ->postJson("/api/incident-reports/{$res->json('data.incident_id')}/resolve", ['action' => 'confirm'])
             ->assertForbidden();
    }

    public function test_a_read_only_auditor_cannot_file(): void
    {
        $loc = $this->makeLocation();
        $au  = $this->makeUserAt($loc, 'auditor');
        $p   = $this->makeConsumable();

        $this->actingAs($au, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'reason'      => 'theft',
            'quantity'    => 1,
            'narrative'   => 'Writing rows would break the read-only guarantee.',
        ])->assertForbidden();
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=test_a_branch_manager_can_file_but_not_resolve
```

Expected: FAIL — 403, because `branch_manager` is not in `incident_reports.create`.

- [ ] **Step 3: Add branch_manager to the create permission**

In `back-end/database/seeders/PermissionSeeder.php`, replace the `incident_reports` block (around line 82):

```php
            // Incident Reports (/admin/incidents) — pickers and anyone who spots a
            // problem file; IA and admin resolve. `auditor` stays read-only: filing
            // rows would break the read-only guarantee that role carries elsewhere.
            'incident_reports' => [
                'read'    => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'create'  => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel'],
                'resolve' => ['admin', 'inventory_analyst'],
            ],
```

- [ ] **Step 4: Re-seed both databases**

```bash
cd back-end
php artisan db:seed --force --class=PermissionSeeder
php artisan db:seed --force --class=RolePermissionsSeeder
DB_DATABASE=airkingV5_test php artisan db:seed --force --class=PermissionSeeder
DB_DATABASE=airkingV5_test php artisan db:seed --force --class=RolePermissionsSeeder
```

Expected: each reports the seeder ran without error.

- [ ] **Step 5: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=IncidentStandaloneFilingTest
```

Expected: PASS, 10 tests.

- [ ] **Step 6: Run the entire backend suite**

```bash
cd back-end && php artisan test
```

Expected: PASS. `PermissionCoverageTest` must stay green — `incident_reports` already has a nav bundle, so no `ALLOWED_NON_NAV` change is needed.

- [ ] **Step 7: Commit**

```bash
git add back-end/database/seeders/PermissionSeeder.php back-end/tests/Feature/Sets/IncidentStandaloneFilingTest.php
git commit -F - <<'EOF'
feat(incidents): let branch managers file incident reports

Resolve stays admin + inventory_analyst so maker-checker holds; auditor
stays read-only.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Phase 5 — Frontend

### Task 10: Shared reason table

**Files:**
- Create: `frontend/src/admin/utils/incidentReasons.js`
- Modify: `frontend/src/admin/pages/IncidentReportsPage.jsx`
- Modify: `frontend/src/admin/components/IncidentResolveDrawer.jsx`
- Modify: `frontend/src/admin/components/picking/IncidentModal.jsx`

Reason metadata currently lives in three independent lists that will drift apart as soon as there are eight reasons. This task creates one source of truth and points all three files at it. No behaviour change beyond the four new reasons becoming visible in the page filter.

- [ ] **Step 1: Create the shared table**

Create `frontend/src/admin/utils/incidentReasons.js`:

```js
/**
 * Single source of truth for incident reason metadata.
 *
 * Before this existed the labels lived in three places — IncidentReportsPage,
 * IncidentResolveDrawer, and picking/IncidentModal — each with its own list.
 * `outcome` mirrors the backend strategy map (App\Services\Incidents\*), so the
 * resolve drawer can derive its confirmation copy instead of hardcoding which
 * reasons write stock off.
 */

export const INCIDENT_REASONS = [
  {
    value: 'not_found',
    label: 'Not found',
    pickerLabel: "I can't find it on the shelf",
    help: 'Filing this opens an investigation record. The missing count is visible to reviewers — this is a tracked report, not a silent skip.',
    group: 'Missing stock',
    badge: 'ak-badge--red',
    affectsStock: true,
    outcome: 'writeoff',
    duringPick: true,
  },
  {
    value: 'theft',
    label: 'Theft',
    pickerLabel: 'It was taken',
    help: 'Recorded separately from "not found" — this is the number shrinkage reporting works from.',
    group: 'Missing stock',
    badge: 'ak-badge--red',
    affectsStock: true,
    outcome: 'writeoff',
    duringPick: false,
  },
  {
    value: 'damaged',
    label: 'Damaged',
    pickerLabel: "It's there but damaged",
    help: 'The stock exists but cannot be sold.',
    group: 'Damage',
    badge: 'ak-badge--amber',
    affectsStock: true,
    outcome: 'writeoff',
    duringPick: true,
  },
  {
    value: 'delivery_damage',
    label: 'Damaged in delivery',
    pickerLabel: 'Damaged on the way to the customer',
    help: 'Damaged in handling or in transit rather than in storage.',
    group: 'Damage',
    badge: 'ak-badge--amber',
    affectsStock: true,
    outcome: 'writeoff',
    duringPick: false,
  },
  {
    value: 'wrong_type',
    label: 'Wrong type on shelf',
    pickerLabel: "Wrong item — the label doesn't match what's physically here",
    help: 'The batch was received as the wrong product. Resolving re-types it.',
    group: 'Wrong item',
    badge: 'ak-badge--blue',
    affectsStock: true,
    outcome: 'retype',
    duringPick: true,
  },
  {
    value: 'zone_mismatch',
    label: 'Zone mismatch',
    pickerLabel: "It's here, just in a different zone",
    help: 'Nothing is missing — the batch record points at the wrong zone.',
    group: 'Wrong item',
    badge: 'ak-badge--gray',
    affectsStock: true,
    outcome: 'relocate',
    duringPick: true,
  },
  {
    value: 'customer_return',
    label: 'Customer return',
    pickerLabel: 'Customer returned it',
    help: 'The wrong item was delivered and came back. Resolving puts the stock back on its original batch.',
    group: 'Return',
    badge: 'ak-badge--green',
    affectsStock: true,
    outcome: 'restock',
    duringPick: false,
  },
  {
    value: 'other',
    label: 'Other',
    pickerLabel: 'Something else',
    help: 'Anything that needs a record and a reviewer but does not move stock. No product or quantity required.',
    group: 'Other',
    badge: 'ak-badge--gray',
    affectsStock: false,
    outcome: 'none',
    duringPick: false,
  },
];

const BY_VALUE = INCIDENT_REASONS.reduce((acc, r) => { acc[r.value] = r; return acc; }, {});

/** Metadata for a reason, or a safe stand-in for an unrecognised one. */
export const reasonMeta = (value) =>
  BY_VALUE[value] || {
    value,
    label: value,
    pickerLabel: value,
    help: '',
    group: 'Other',
    badge: 'ak-badge--gray',
    affectsStock: false,
    outcome: 'none',
    duringPick: false,
  };

export const reasonLabel = (value) => reasonMeta(value).label;
export const reasonBadge = (value) => reasonMeta(value).badge;

/** The four reasons the barcode scanner can raise mid-pick. */
export const PICKING_REASONS = INCIDENT_REASONS.filter((r) => r.duringPick);

/** Reasons grouped for the standalone file form's radio list. */
export const REASON_GROUPS = INCIDENT_REASONS.reduce((groups, reason) => {
  const found = groups.find((g) => g.name === reason.group);
  if (found) found.reasons.push(reason);
  else groups.push({ name: reason.group, reasons: [reason] });
  return groups;
}, []);
```

- [ ] **Step 2: Point IncidentReportsPage at it**

In `frontend/src/admin/pages/IncidentReportsPage.jsx`, delete the local `REASONS` and `REASON_BADGE` constants (lines 8–20) and add to the imports:

```js
import { INCIDENT_REASONS, reasonBadge, reasonLabel } from '../utils/incidentReasons';
```

Replace the reason filter's options:

```jsx
            {INCIDENT_REASONS.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
```

Replace the reason cell in the table body:

```jsx
                      <td>
                        <span className={`ak-badge ${reasonBadge(i.reason)}`}>
                          {reasonLabel(i.reason)}
                        </span>
                      </td>
```

- [ ] **Step 3: Point IncidentResolveDrawer at it**

In `frontend/src/admin/components/IncidentResolveDrawer.jsx`, delete the local `REASON_LABELS` constant (lines 7–12) and add to the imports:

```js
import { reasonLabel, reasonMeta } from '../utils/incidentReasons';
```

Replace the `writesOffStock` line:

```js
  const outcome = reasonMeta(reason).outcome;
  const writesOffStock = outcome === 'writeoff';
```

Replace the reason display cell:

```jsx
            <div>{reasonLabel(reason)}</div>
```

- [ ] **Step 4: Point the picking IncidentModal at it**

In `frontend/src/admin/components/picking/IncidentModal.jsx`, delete the local `REASONS` constant (lines 34–44) and add to the imports:

```js
import { PICKING_REASONS } from '../../utils/incidentReasons';
```

Replace the two usages — the radio list map:

```jsx
          {PICKING_REASONS.map((r) => (
            <label
              key={r.value}
              style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-start', cursor: 'pointer', padding: '0.25rem 0' }}
            >
              <input
                type="radio"
                name="incident-reason"
                value={r.value}
                checked={reason === r.value}
                onChange={() => setReason(r.value)}
                style={{ marginTop: '0.2rem' }}
              />
              <span>{r.pickerLabel}</span>
            </label>
          ))}
```

and the `selected` lookup near the bottom of the component:

```js
  const selected = PICKING_REASONS.find((r) => r.value === reason);
```

- [ ] **Step 5: Build to verify**

```bash
cd frontend && npm run build
```

Expected: `Compiled successfully.` with no new warnings. If it reports unused variables, you left a deleted constant referenced somewhere.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/utils/incidentReasons.js frontend/src/admin/pages/IncidentReportsPage.jsx frontend/src/admin/components/IncidentResolveDrawer.jsx frontend/src/admin/components/picking/IncidentModal.jsx
git commit -F - <<'EOF'
refactor(incidents): one shared reason table for the frontend

Labels, badges and help text lived in three independent lists. `outcome`
mirrors the backend strategy map so the resolve drawer derives its copy.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

### Task 11: API client

**Files:**
- Modify: `frontend/src/admin/services/api.js`

- [ ] **Step 1: Add the photo upload call and refresh the file() contract**

In `frontend/src/admin/services/api.js`, replace the whole `incidentReportsAPI` object (around line 1443):

```js
export const incidentReportsAPI = {
  list: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/incident-reports?${query}` : '/incident-reports');
  },
  /**
   * File a new incident report (POST /incident-reports). Payload keys the store
   * endpoint validates: source_type ('issuance'|'transfer'|'standalone'),
   * source_id (required unless standalone), related_issuance_id?, location_id,
   * product_id + quantity (required unless reason is 'other'), batch_id?,
   * serial_id?, expected_zone?, actual_zone?, occurred_at?, photo_path?,
   * reason (not_found|theft|damaged|delivery_damage|wrong_type|zone_mismatch|
   * customer_return|other), narrative.
   */
  file: async (payload) =>
    apiRequest('/incident-reports', { method: 'POST', body: JSON.stringify(payload) }),
  /**
   * Upload an evidence photo first, then submit the returned path as
   * `photo_path` when filing. Returns { path, url }.
   */
  uploadPhoto: async (file) => {
    const form = new FormData();
    form.append('photo', file);
    return uploadRequest('/incident-reports/photo', form);
  },
  /**
   * Evidence photo for an incident, as a Blob. Photos are on a private disk
   * behind `permission:incident_reports,read`, so a plain <img src> would 401 —
   * the browser sends no Authorization header. Same approach the GRN PDF
   * downloads use: fetch with the Bearer header, hand the caller a blob.
   */
  photoBlob: async (id) => {
    const token = getToken();
    const base = getApiBaseUrl();
    const res = await fetch(`${base}/incident-reports/${id}/photo`, {
      headers: { ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    });
    if (!res.ok) throw new Error(`Failed to load photo (${res.status})`);
    return res.blob();
  },
  resolve: async (id, payload) =>
    apiRequest(`/incident-reports/${id}/resolve`, { method: 'POST', body: JSON.stringify(payload) }),
};
```

**Amended after Task 8's security review.** Photos moved to a private disk served by an authenticated route, so `photoBlob` replaces what would have been a direct `/storage/` URL. Check the exact names of `getToken` / `getApiBaseUrl` in `api.js` before using them — `fetchBlob` around line 78 already does this and is the pattern to copy.

- [ ] **Step 2: Build to verify**

```bash
cd frontend && npm run build
```

Expected: `Compiled successfully.`

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -F - <<'EOF'
feat(incidents): add incidentReportsAPI.uploadPhoto

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

### Task 12: The standalone file modal

> ⚠️ **Prerequisite bug — fix this first, in the same commit.** `back-end/app/Http/Controllers/Api/IssuanceController.php:41` searches `issuance_number`, **a column that does not exist**. `issuances` has `dr_number` and `external_reference` only. The method's try/catch turns the resulting SQL error into a 500, so `GET /issuances?search=` is broken today — and this modal's issuance picker is the first caller that would hit it. `IssuanceController::archived()` at line 209 already does it correctly; make line 41 match by searching `dr_number`. Pre-existing and unrelated to this feature, but it blocks the picker.
>
> Found during Task 7, which also discovered the same non-existent column in the incident serializer.

**Files:**
- Create: `frontend/src/admin/components/IncidentFileModal.jsx`
- Modify: `frontend/src/admin/pages/IncidentReportsPage.jsx`

- [ ] **Step 1: Create the modal**

Create `frontend/src/admin/components/IncidentFileModal.jsx`:

```jsx
import React, { useEffect, useMemo, useState } from 'react';
import Modal from './Modal';
import {
  incidentReportsAPI,
  inventoryBatchesAPI,
  issuancesAPI,
  locationsAPI,
  productsAPI,
} from '../services/api';
import { toast } from '../utils/toast';
import { REASON_GROUPS, reasonMeta } from '../utils/incidentReasons';

/**
 * File an incident with no pick in progress — the front door the module was
 * missing. Deliberately separate from picking/IncidentModal: that one is
 * controlled and presentational, handed its product, batch, zone and quantity
 * by the scanner, while this one has to let the user find all of it. Merging
 * them would produce one component that is half form and half display, with
 * every field gated on a mode flag. They share the reason table, which is the
 * right amount of sharing.
 *
 * Props:
 *   isOpen  {boolean}
 *   onClose {() => void}
 *   onFiled {() => void}  fires only on success; the caller refetches.
 */
export default function IncidentFileModal({ isOpen, onClose, onFiled }) {
  const [reason, setReason] = useState('theft');
  const [occurredAt, setOccurredAt] = useState('');
  const [locationId, setLocationId] = useState('');
  const [productId, setProductId] = useState('');
  const [productFilter, setProductFilter] = useState('');
  const [quantity, setQuantity] = useState(1);
  const [batchId, setBatchId] = useState('');
  const [issuanceId, setIssuanceId] = useState('');
  const [issuanceSearch, setIssuanceSearch] = useState('');
  const [issuances, setIssuances] = useState([]);
  const [narrative, setNarrative] = useState('');
  const [photoFile, setPhotoFile] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  const [locations, setLocations] = useState([]);
  const [products, setProducts] = useState([]);
  const [batches, setBatches] = useState([]);

  const meta = reasonMeta(reason);
  const needsProduct = meta.affectsStock;
  const isReturn = reason === 'customer_return';

  // `datetime-local` wants YYYY-MM-DDTHH:mm in LOCAL time, so toISOString()
  // (which is UTC) would shift the value by the timezone offset.
  const localNow = () => {
    const d = new Date();
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
  };

  useEffect(() => {
    if (!isOpen) return;
    setReason('theft');
    setOccurredAt(localNow());
    setLocationId('');
    setProductId('');
    setProductFilter('');
    setQuantity(1);
    setBatchId('');
    setIssuanceId('');
    setIssuanceSearch('');
    setIssuances([]);
    setNarrative('');
    setPhotoFile(null);
    setSubmitting(false);
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen) return;
    locationsAPI.getAll({ per_page: 500 })
      .then((r) => setLocations(Array.isArray(r?.data) ? r.data : []))
      .catch(() => setLocations([]));
    productsAPI.getAll({ per_page: 1000 })
      .then((r) => setProducts(Array.isArray(r?.data) ? r.data : []))
      .catch(() => setProducts([]));
    inventoryBatchesAPI.getAll()
      .then((r) => setBatches(Array.isArray(r?.data) ? r.data : []))
      .catch(() => setBatches([]));
  }, [isOpen]);

  const filteredProducts = useMemo(() => {
    const q = productFilter.trim().toLowerCase();
    if (!q) return products;
    return products.filter((p) =>
      String(p.product_name || '').toLowerCase().includes(q) ||
      String(p.product_code || '').toLowerCase().includes(q) ||
      String(p.barcode || '').toLowerCase().includes(q)
    );
  }, [products, productFilter]);

  // Only batches that actually hold this product at this location can be the
  // subject of the incident.
  const matchingBatches = useMemo(() => {
    if (!productId || !locationId) return [];
    return batches.filter(
      (b) => String(b.product_id) === String(productId) && String(b.location_id) === String(locationId)
    );
  }, [batches, productId, locationId]);

  useEffect(() => { setBatchId(''); }, [productId, locationId]);

  const searchIssuances = async () => {
    try {
      const res = await issuancesAPI.getAll({ search: issuanceSearch.trim(), per_page: 20 });
      setIssuances(Array.isArray(res?.data) ? res.data : []);
    } catch (err) {
      toast.error(err?.message || 'Failed to search issuances.');
      setIssuances([]);
    }
  };

  const canSubmit =
    !submitting &&
    locationId !== '' &&
    narrative.trim().length > 0 &&
    (!needsProduct || (productId !== '' && Number(quantity) >= 1));

  const handleSubmit = async () => {
    if (!canSubmit) return;
    setSubmitting(true);
    try {
      let photoPath = null;
      if (photoFile) {
        const up = await incidentReportsAPI.uploadPhoto(photoFile);
        photoPath = up?.data?.path ?? null;
      }

      await incidentReportsAPI.file({
        source_type: 'standalone',
        location_id: Number(locationId),
        product_id: needsProduct ? Number(productId) : null,
        quantity: needsProduct ? Number(quantity) : null,
        batch_id: batchId ? Number(batchId) : null,
        related_issuance_id: isReturn && issuanceId ? Number(issuanceId) : null,
        reason,
        narrative: narrative.trim(),
        occurred_at: occurredAt ? occurredAt.replace('T', ' ') + ':00' : null,
        photo_path: photoPath,
      });

      toast.success('Incident reported. It is now in the review queue.');
      onFiled?.();
    } catch (err) {
      toast.error(err?.message || 'Failed to file the incident.');
    } finally {
      setSubmitting(false);
    }
  };

  if (!isOpen) return null;

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Report an incident" maxWidth={620}>
      <div className="ak-field" style={{ gap: '1rem', paddingTop: '1rem' }}>
        <fieldset className="ak-field" style={{ border: 'none', margin: 0, padding: 0 }}>
          <legend className="ak-label" style={{ marginBottom: '0.4rem' }}>What happened?</legend>
          {REASON_GROUPS.map((group) => (
            <div key={group.name} style={{ marginBottom: '0.5rem' }}>
              <div className="ak-muted" style={{ fontSize: '0.75rem', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {group.name}
              </div>
              {group.reasons.map((r) => (
                <label
                  key={r.value}
                  style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-start', cursor: 'pointer', padding: '0.2rem 0' }}
                >
                  <input
                    type="radio"
                    name="file-incident-reason"
                    value={r.value}
                    checked={reason === r.value}
                    onChange={() => setReason(r.value)}
                    style={{ marginTop: '0.2rem' }}
                  />
                  <span>{r.label}</span>
                </label>
              ))}
            </div>
          ))}
          {meta.help && (
            <p className="ak-muted" style={{ fontSize: '0.8rem', margin: '0.3rem 0 0' }}>{meta.help}</p>
          )}
        </fieldset>

        <div className="ak-field">
          <label className="ak-label" htmlFor="incident-occurred">When did it happen?</label>
          <input
            id="incident-occurred"
            className="ak-input"
            type="datetime-local"
            value={occurredAt}
            max={localNow()}
            onChange={(e) => setOccurredAt(e.target.value)}
            style={{ maxWidth: 260 }}
          />
        </div>

        <div className="ak-field">
          <label className="ak-label" htmlFor="incident-location">Location *</label>
          <select
            id="incident-location"
            className="ak-select"
            value={locationId}
            onChange={(e) => setLocationId(e.target.value)}
          >
            <option value="">Select a location…</option>
            {locations.map((l) => (
              <option key={l.location_id ?? l.id} value={l.location_id ?? l.id}>
                {l.location_name || l.name}
              </option>
            ))}
          </select>
        </div>

        {isReturn && (
          <div className="ak-field">
            <label className="ak-label">Original issuance (optional)</label>
            <div style={{ display: 'flex', gap: '0.4rem' }}>
              <input
                className="ak-input"
                placeholder="Issuance number…"
                value={issuanceSearch}
                onChange={(e) => setIssuanceSearch(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); searchIssuances(); } }}
              />
              <button type="button" className="ak-btn" onClick={searchIssuances}>Search</button>
            </div>
            {issuances.length > 0 && (
              <select
                className="ak-select"
                value={issuanceId}
                onChange={(e) => {
                  setIssuanceId(e.target.value);
                  const picked = issuances.find((i) => String(i.issuance_id) === e.target.value);
                  if (picked?.location_id) setLocationId(String(picked.location_id));
                }}
                style={{ marginTop: '0.4rem' }}
              >
                <option value="">Select the issuance…</option>
                {issuances.map((i) => (
                  <option key={i.issuance_id} value={String(i.issuance_id)}>
                    {i.dr_number || `Issuance #${i.issuance_id}`}{i.external_reference ? ` · ${i.external_reference}` : ''}
                  </option>
                ))}
              </select>
            )}
          </div>
        )}

        {needsProduct && (
          <>
            <div className="ak-field">
              <label className="ak-label">Product *</label>
              <input
                className="ak-input"
                placeholder="Filter products…"
                value={productFilter}
                onChange={(e) => setProductFilter(e.target.value)}
                style={{ marginBottom: '0.4rem' }}
              />
              <select className="ak-select" value={productId} onChange={(e) => setProductId(e.target.value)}>
                <option value="">Select the product…</option>
                {filteredProducts.map((p) => (
                  <option key={p.product_id} value={String(p.product_id)}>
                    {p.product_name}{p.product_code ? ` (${p.product_code})` : ''}
                  </option>
                ))}
              </select>
            </div>

            <div className="ak-field">
              <label className="ak-label" htmlFor="incident-file-qty">Quantity affected *</label>
              <input
                id="incident-file-qty"
                className="ak-input"
                type="number"
                min={1}
                value={quantity}
                onChange={(e) => setQuantity(e.target.value)}
                style={{ maxWidth: 160 }}
              />
            </div>

            <div className="ak-field">
              <label className="ak-label" htmlFor="incident-file-batch">Batch (optional)</label>
              <select
                id="incident-file-batch"
                className="ak-select"
                value={batchId}
                onChange={(e) => setBatchId(e.target.value)}
                disabled={matchingBatches.length === 0}
              >
                <option value="">
                  {matchingBatches.length === 0 ? 'Pick a product and location first' : 'No specific batch'}
                </option>
                {matchingBatches.map((b) => (
                  <option key={b.batch_id} value={String(b.batch_id)}>
                    #{b.batch_id} · {b.zone_label || 'no zone'} · {b.quantity_remaining} left
                    {b.received_at ? ` · received ${b.received_at}` : ''}
                  </option>
                ))}
              </select>
              {batchId && (
                <p style={{ color: '#b45309', fontSize: '0.8rem', margin: '0.3rem 0 0' }}>
                  {isReturn
                    ? 'The returned stock will go back onto this batch when the report is approved.'
                    : 'Naming a batch holds it out of picking until this report is reviewed.'}
                </p>
              )}
            </div>
          </>
        )}

        <div className="ak-field">
          <label className="ak-label" htmlFor="incident-file-narrative">Describe what happened *</label>
          <textarea
            id="incident-file-narrative"
            className="ak-input"
            rows={4}
            maxLength={2000}
            value={narrative}
            onChange={(e) => setNarrative(e.target.value)}
            placeholder="What did you see, and where?"
          />
        </div>

        <div className="ak-field">
          <label className="ak-label" htmlFor="incident-file-photo">Photo (optional)</label>
          <input
            id="incident-file-photo"
            className="ak-input"
            type="file"
            accept="image/*"
            onChange={(e) => setPhotoFile(e.target.files?.[0] || null)}
          />
          {photoFile && <span className="ak-muted" style={{ fontSize: '0.8rem' }}>{photoFile.name}</span>}
        </div>

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.6rem' }}>
          <button type="button" className="ak-btn" onClick={onClose} disabled={submitting}>Cancel</button>
          <button type="button" className="ak-btn ak-btn--primary" onClick={handleSubmit} disabled={!canSubmit}>
            {submitting ? 'Filing…' : 'File incident'}
          </button>
        </div>
      </div>
    </Modal>
  );
}
```

- [ ] **Step 2: Confirm the API clients the modal imports actually exist**

```bash
cd frontend && grep -n "export const inventoryBatchesAPI\|export const issuancesAPI\|export const productsAPI\|export const locationsAPI" src/admin/services/api.js
```

Expected: four matches. If `inventoryBatchesAPI` or `issuancesAPI` is missing or named differently, use the actual exported name and adjust the import — do not invent one. If `inventoryBatchesAPI` genuinely does not exist, add it next to `incidentReportsAPI`:

```js
export const inventoryBatchesAPI = {
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/inventory-batches?${query}` : '/inventory-batches');
  },
};
```

- [ ] **Step 3: Wire the button into the page**

In `frontend/src/admin/pages/IncidentReportsPage.jsx`, add the import:

```js
import IncidentFileModal from '../components/IncidentFileModal';
```

Add the permission check next to the existing `canResolve`:

```js
  const canCreate = typeof can === 'function' && can('incident_reports', 'create');
```

Add the state next to `selected`:

```js
  const [filing, setFiling] = useState(false);
```

Add the button inside `<div className="ak-header-aside">`, before the open-count badge:

```jsx
              {canCreate && (
                <button
                  type="button"
                  className="ak-btn ak-btn--primary"
                  onClick={() => setFiling(true)}
                  style={{ marginRight: '0.6rem' }}
                >
                  Report Incident
                </button>
              )}
```

Add the modal next to the existing drawer at the bottom of the JSX:

```jsx
      <IncidentFileModal
        isOpen={filing}
        onClose={() => setFiling(false)}
        onFiled={() => { setFiling(false); load(); }}
      />
```

- [ ] **Step 4: Update the page's subtitle, which claims incidents only come from the scanner**

Replace the `<p className="ak-sub">` text:

```jsx
              <p className="ak-sub">
                Report and triage stock problems — missing or stolen stock, damage, wrong product on the shelf,
                customer returns, or anything else that needs a record and a reviewer.
              </p>
```

- [ ] **Step 5: Show when the incident happened, not just when it was filed**

Still in `IncidentReportsPage.jsx`, rename the Date column header so it says what it now shows:

```jsx
                  <th>Occurred</th>
```

and replace the date cell in the table body:

```jsx
                      <td>
                        {/* Historic rows predate occurred_at, so fall back to the
                            filing time rather than showing a dash. */}
                        {(i.occurred_at || i.created_at)
                          ? new Date(i.occurred_at || i.created_at).toLocaleString()
                          : '—'}
                      </td>
```

- [ ] **Step 6: Render a standalone source honestly**

Replace the Source cell, which would otherwise print `standalone #` with nothing after it:

```jsx
                      <td>
                        {i.source_type === 'standalone'
                          ? 'Reported directly'
                          : `${i.source_type} #${i.source_id}`}
                      </td>
```

- [ ] **Step 7: Build to verify**

```bash
cd frontend && npm run build
```

Expected: `Compiled successfully.` with no new warnings.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/admin/components/IncidentFileModal.jsx frontend/src/admin/pages/IncidentReportsPage.jsx frontend/src/admin/services/api.js
git commit -F - <<'EOF'
feat(incidents): add the Report Incident button and standalone file modal

The module had no way to file an incident outside a FIFO override during
picking. Reason-driven form: product and quantity only when the reason
moves stock, issuance picker for returns, optional batch with an explicit
FIFO-hold warning.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

### Task 13: Resolve drawer for the new reasons

**Files:**
- Modify: `frontend/src/admin/components/IncidentResolveDrawer.jsx`

- [ ] **Step 1: Derive the confirmation copy from `outcome`**

In `frontend/src/admin/components/IncidentResolveDrawer.jsx`, replace `confirmDisabled` and `handleConfirm` with outcome-driven versions:

```js
  const confirmDisabled =
    submitting !== null ||
    (outcome === 'retype' && !correctedProductId);

  const confirmLabel = outcome === 'none' ? 'Acknowledge' : 'Confirm';

  const handleConfirm = async () => {
    if (confirmDisabled) return;

    if (outcome === 'writeoff') {
      const ok = await confirmAction({
        title: 'Confirm this incident?',
        text: `This writes stock off — it posts a deduction adjustment for ${incident.quantity} unit(s) of "${incident.product_name}". This cannot be undone from here.`,
        confirmText: 'Confirm & write off',
        tone: 'danger',
      });
      if (!ok) return;
    }

    if (outcome === 'restock') {
      const where = incident.batch_id ? `batch #${incident.batch_id}` : 'a new batch';
      const ok = await confirmAction({
        title: 'Put this stock back?',
        text: `This returns ${incident.quantity} unit(s) of "${incident.product_name}" to ${where} and makes them available again.`,
        confirmText: 'Confirm & restock',
      });
      if (!ok) return;
    }

    doResolve('confirm');
  };
```

- [ ] **Step 2: Replace the reason-specific body sections**

Replace the `writesOffStock && (...)`, `reason === 'wrong_type' && (...)`, and `reason === 'zone_mismatch' && (...)` blocks with outcome-driven equivalents. The `wrong_type` product picker keeps its existing markup; only its condition changes:

```jsx
            {outcome === 'writeoff' && (
              <p style={{ color: '#b91c1c', fontSize: '0.85rem', marginTop: 0 }}>
                Confirming this incident writes the stock off — it posts a deduction adjustment. This does not
                affect the batch's FIFO exclusion, which already happened when the incident was filed.
              </p>
            )}

            {outcome === 'restock' && (
              <p style={{ fontSize: '0.85rem', marginTop: 0 }}>
                Confirming puts <strong>{incident.quantity}</strong> unit(s) back into stock
                {incident.batch_id ? <> on <strong>batch #{incident.batch_id}</strong></> : ' on a new batch'}, and
                makes them available for picking again. It does not re-issue the correct item — run a normal
                issuance for that.
              </p>
            )}

            {outcome === 'none' && (
              <p style={{ fontSize: '0.85rem', marginTop: 0 }}>
                Acknowledging closes this report. No stock moves and no adjustment is posted.
              </p>
            )}

            {outcome === 'retype' && (
              <div className="ak-field" style={{ marginBottom: '0.8rem' }}>
                <label className="ak-label">What is physically on the shelf? *</label>
                <input
                  className="ak-input"
                  placeholder="Filter products…"
                  value={productFilter}
                  onChange={(e) => setProductFilter(e.target.value)}
                  style={{ marginBottom: '0.4rem' }}
                />
                <select
                  className="ak-select"
                  value={correctedProductId}
                  onChange={(e) => setCorrectedProductId(e.target.value)}
                  disabled={loadingProducts}
                >
                  <option value="">{loadingProducts ? 'Loading products…' : 'Select the actual product…'}</option>
                  {filteredProducts.map((p) => (
                    <option key={p.product_id} value={String(p.product_id)}>
                      {p.product_name}{p.product_code ? ` (${p.product_code})` : ''}
                    </option>
                  ))}
                </select>
              </div>
            )}

            {outcome === 'relocate' && (
              <p style={{ fontSize: '0.85rem' }}>
                No quantity changes here — confirming just records the re-shelving from
                {' '}<strong>{incident.expected_zone || '—'}</strong> to <strong>{incident.actual_zone || '—'}</strong>.
              </p>
            )}
```

Then change the Confirm button's text to use the new label:

```jsx
                {submitting === 'confirm' ? 'Confirming…' : confirmLabel}
```

- [ ] **Step 3: Fix the product-loading effect, which still keys off the reason string**

Replace the effect's condition so it loads products for the retype outcome:

```js
  useEffect(() => {
    if (!isOpen || reasonMeta(reason).outcome !== 'retype' || !canResolve) return;
    setLoadingProducts(true);
    productsAPI.getAll({ per_page: 1000 })
      .then((r) => setProducts(Array.isArray(r?.data) ? r.data : []))
      .catch(() => setProducts([]))
      .finally(() => setLoadingProducts(false));
  }, [isOpen, reason, canResolve]);
```

- [ ] **Step 4: Show the new fields and use photo_url**

In the detail grid, replace the Source cell and add two more:

```jsx
          <div>
            <div className="ak-label">Source</div>
            <div>{incident.source_type === 'standalone' ? 'Reported directly' : `${incident.source_type} #${incident.source_id}`}</div>
          </div>
          <div>
            <div className="ak-label">Occurred</div>
            <div>{incident.occurred_at ? new Date(incident.occurred_at).toLocaleString() : '—'}</div>
          </div>
          {incident.related_issuance_id && (
            <div>
              <div className="ak-label">Original issuance</div>
              <div>{incident.related_issuance_number || `#${incident.related_issuance_id}`}</div>
            </div>
          )}
```

Replace the Product cell so a product-less incident reads honestly:

```jsx
          <div>
            <div className="ak-label">Product</div>
            <div>{incident.product_name || (incident.product_id ? `#${incident.product_id}` : 'Not product-specific')}</div>
          </div>
```

Replace the photo URL line so it uses the server-built web path:

```js
  // AMENDED after Task 8's security review. Photos live on a PRIVATE disk and
  // are served by an authenticated route (`GET /incident-reports/{id}/photo`,
  // gated on incident_reports.read), so a plain <img src> will 401 — the
  // browser does not send the Bearer token. Fetch it as a blob the way the GRN
  // downloads do, then hand the object URL to <img>. Revoke it on close or the
  // drawer leaks a blob per incident viewed.
  const [photoUrl, setPhotoUrl] = useState(null);

  useEffect(() => {
    if (!incident?.photo_url) { setPhotoUrl(null); return; }
    let revoked = false;
    let objectUrl = null;

    incidentReportsAPI.photoBlob(incident.incident_id)
      .then((blob) => {
        if (revoked) return;
        objectUrl = URL.createObjectURL(blob);
        setPhotoUrl(objectUrl);
      })
      .catch(() => setPhotoUrl(null));

    return () => {
      revoked = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [incident?.incident_id, incident?.photo_url]);
```

- [ ] **Step 5: Build to verify**

```bash
cd frontend && npm run build
```

Expected: `Compiled successfully.` with no new warnings.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/components/IncidentResolveDrawer.jsx
git commit -F - <<'EOF'
feat(incidents): resolve drawer handles restock and acknowledge outcomes

Confirmation copy now derives from the shared reason table's `outcome`
rather than hardcoded reason checks. Shows occurred_at, the related
issuance, and reads product-less incidents honestly.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

### Task 14: Make the picking modal's photo input real

**Files:**
- Modify: `frontend/src/admin/components/picking/IncidentModal.jsx`

- [ ] **Step 1: Replace the presentational file input with a real upload**

In `frontend/src/admin/components/picking/IncidentModal.jsx`, replace the `photoName` state with a file handle:

```js
  const [photoFile, setPhotoFile] = useState(null);
```

Update the reset effect line `setPhotoName('');` to:

```js
    setPhotoFile(null);
```

Replace `handleSubmit` so it uploads first and posts the resulting path:

```js
  const handleSubmit = async () => {
    if (!canSubmit) return;
    setSubmitting(true);
    try {
      // Upload first: the file endpoint takes a path, not a multipart file, so
      // the picker can attach evidence without leaving the scan flow.
      let photoPath = null;
      if (photoFile) {
        const up = await incidentReportsAPI.uploadPhoto(photoFile);
        photoPath = up?.data?.path ?? null;
      }

      await incidentReportsAPI.file({
        source_type: context.source_type,
        source_id: Number(context.source_id),
        location_id: Number(context.location_id),
        product_id: Number(context.product_id),
        batch_id: context.batch_id != null ? Number(context.batch_id) : null,
        serial_id: context.serial_id != null ? Number(context.serial_id) : null,
        expected_zone: context.expected_zone ?? null,
        actual_zone: context.actual_zone ?? null,
        reason,
        quantity: Number(quantity),
        narrative: narrative.trim(),
        photo_path: photoPath,
      });

      toast.success('Incident filed. Picking may continue with the next batch.');
      onFiled?.();
    } catch (err) {
      toast.error(err?.message || 'Failed to file the incident.');
    } finally {
      setSubmitting(false);
    }
  };
```

Replace the photo field's markup:

```jsx
          <input
            id="incident-photo"
            className="ak-input"
            type="file"
            accept="image/*"
            onChange={(e) => setPhotoFile(e.target.files?.[0] || null)}
          />
          {photoFile && <span className="ak-muted" style={{ fontSize: '0.8rem' }}>{photoFile.name}</span>}
```

- [ ] **Step 2: Update the component's docblock, which still says the photo is not posted**

Replace the final paragraph of the top-of-file comment:

```
 * Submits through incidentReportsAPI.file() with exactly the keys the store
 * endpoint validates. A chosen photo is uploaded first via uploadPhoto() and
 * submitted as photo_path.
```

- [ ] **Step 3: Build to verify**

```bash
cd frontend && npm run build
```

Expected: `Compiled successfully.` with no new warnings. A leftover `photoName` reference will show up here.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/components/picking/IncidentModal.jsx
git commit -F - <<'EOF'
feat(incidents): actually upload the picking modal's photo

The file input has been on screen since the module was built but was
explicitly presentational. It now uploads and submits photo_path.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Phase 6 — Verification

### Task 15: Full verification and manual walkthrough

**Files:** none modified unless a defect is found.

- [ ] **Step 1: Run the entire backend suite**

```bash
cd back-end && php artisan test
```

Expected: **9 failed, 1 skipped, rest passed.** Incident coverage should be 42 tests (6 schema + 6 filing + 12 resolution + 10 standalone + 5 restock + 3 photo), all green.

⚠️ **Known pre-existing baseline — do not try to fix these.** The 9 failures are all in `Tests\Feature\WarrantyClaimTest` and predate this entire line of work. This was verified during Task 3's review by checking out `53f834a` (the commit before Task 1) into a separate worktree and running the suite there: identical 9 failures, same names. Neither `WarrantyClaimService.php` nor `WarrantyClaimTest.php` is touched by any commit in this plan or by the 22 unrelated working-tree modifications.

All nine funnel through `back-end/app/Services/WarrantyClaimService.php:341`, where `$claim->details()->where('product_id', …)->whereColumn('quantity_issued', '<', 'quantity_requested')` returns null and `addItem()` throws. The `whereColumn` comparison appears never to be satisfiable, which points at `quantity_requested` drift in `airkingV5_test`'s `warranty_claim_details` table rather than a code bug — consistent with this repo's schema-is-a-dump convention. Worth a separate investigation, but it is out of scope here.

**What matters for this task:** the failure count must still be exactly 9, and they must still be the same 9 WarrantyClaim tests. If the number changes, this plan broke something.

- [ ] **Step 2: Confirm the refactor safety net is still untouched**

```bash
git diff HEAD~14 --stat -- back-end/tests/Feature/Sets/IncidentResolutionTest.php
```

Expected: no output. If this file changed, the Task 3 refactor altered behaviour and needs revisiting.

- [ ] **Step 3: Build the frontend**

```bash
cd frontend && npm run build
```

Expected: `Compiled successfully.` with no new warnings.

- [ ] **Step 4: Start the app for the manual pass**

```bash
cd frontend && npm run demo
```

Use the production build, not the CRA dev server — the dev server is slow enough to be mistaken for an API problem.

- [ ] **Step 5: Walk through the new flows as an admin**

At `/admin/incidents`, confirm each of these:

1. A **Report Incident** button appears in the header.
2. Filing `theft` with a product, quantity, and batch succeeds and appears in the list with the correct badge and the date you entered under **Occurred**, not today's date.
3. Filing `other` with only a location and narrative succeeds — the product, quantity, and batch fields are hidden, and the row shows no product rather than a product named "#0".
4. Filing `customer_return` shows the issuance search; searching by issuance number returns rows, and picking one sets the location.
5. Attaching a photo on any reason and opening the resolve drawer shows the image.
6. Resolving the `theft` report shows the red write-off warning and decrements the batch.
7. Resolving the `customer_return` shows the restock confirmation naming the batch, and the batch's `quantity_remaining` goes **up** by the reported quantity.
8. Resolving the `other` report shows an **Acknowledge** button, closes the report, and posts no adjustment.

- [ ] **Step 6: Walk through the role checks**

Log in as each role and confirm at `/admin/incidents`:

| Role | Report Incident button | Confirm/Dismiss in drawer |
|---|---|---|
| admin | visible | visible |
| inventory_analyst | visible | visible |
| branch_manager | visible | **hidden** |
| warehouse_personnel | visible | **hidden** |
| auditor | **hidden** | **hidden** |

- [ ] **Step 7: Confirm picking still works**

Run one issuance pick that triggers a FIFO violation, file an incident from the scanner with a photo attached, and confirm the batch drops out of the pick plan and the photo appears on the incident in the admin list. This is the path the whole module already depended on — it must be unchanged.

- [ ] **Step 7b: Decide what to do about `airkingv5.sql` — ASK THE USER, do not act unilaterally**

**Found during Task 4's review.** `airkingv5.sql` is this repo's schema of record, and it still declares:

```sql
`source_id` bigint(20) UNSIGNED NOT NULL,
`product_id` bigint(20) UNSIGNED NOT NULL,
```

with no `occurred_at` and no `related_issuance_id`. The Task 1 migration fixes both live databases, so every test and every existing install is correct — but **a database rebuilt from the current dump without running that migration would reject standalone filing outright**, because a standalone report writes `source_id = NULL`.

That `incident_reports` exists in the dump at all proves the convention is to regenerate it after schema changes (the table arrived by migration in July and is in the dump now).

**Do not regenerate it without asking.** `airkingv5.sql` is one of the ~22 files already modified in the working tree by unrelated earlier work, so a `mysqldump` would sweep up whatever else has changed in the dev database since. There is also a known gotcha: **`mysqldump` on Windows PowerShell writes UTF-16 by default**, which silently corrupts the file for later import — redirect through the Bash tool or pass an explicit `--result-file`.

Present the user with the choice: regenerate the dump now, hand-edit just the `incident_reports` block, or leave it and track it separately.

- [ ] **Step 8: Commit any fixes found**

If the walkthrough surfaced defects, fix them, re-run steps 1 and 3, and commit. If it did not, there is nothing to commit and this plan is complete.

---

## Notes For Integration

This work sits on `backupV3`, which carries a large amount of unpushed work already (see the project memory index). Do not merge or push without asking — decide integration separately, using `superpowers:finishing-a-development-branch`.
