# Incident Inspection Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move inspection *before* admin confirmation, let one report carry many physical items each with its own outcome, and capture the client's five-item evidence checklist — so nothing leaves inventory until an authorised person has examined it and an admin has signed off.

**Architecture:** `incident_items` replaces `stock_holds` as the unit of a report — one row per physical thing, carrying its own status and the inspector's outcome. Filing quarantines those rows (unpickable, **not** deducted); the inspector attaches evidence and sets outcomes; the admin's confirmation applies every outcome in one transaction. `StockHoldService` is reshaped into the sole mutator of item status and keeps that discipline.

**Tech Stack:** Laravel 12 (PHP 8.2), MariaDB, PHPUnit with `DatabaseTransactions`; React 18 (CRA), Jest + `@testing-library/react`.

**Spec:** `docs/superpowers/specs/2026-08-12-incident-inspection-workflow-design.md`

---

## Before you start — read all of it

Every item below has already gone wrong in this module.

- **The schema is a SQL dump, not a migration history.** A bare `php artisan migrate` fails. Apply one migration with `--path`.
- **There is no `.env.testing`.** `--env=testing` silently migrates the **dev** database while appearing to succeed. From `back-end/` in the **Bash** tool:
  ```bash
  php artisan migrate --path=database/migrations/<file>.php --force                    # dev
  DB_DATABASE=airkingV5_test php artisan migrate --path=database/migrations/<file>.php --force   # test
  DB_DATABASE=airkingV5_test php artisan db:seed --class=<Seeder> --force
  ```
  Confirm with `SHOW TABLES` / `SHOW COLUMNS` against **both** database names before moving on.
- **Never hardcode a status id.** Missing/Defective/Returned are 50/51/52 in `airkingv5` but 1779/1780/1781 in `airkingV5_test`. `user_id` 1 does not exist in the test DB either. New columns use **plain string codes with class constants** — no lookup table, no id to resolve.
- **Permissions need two seeders.** `PermissionSeeder` creates the permission; `RolePermissionsSeeder` writes the grants. `RolePermissionsSeeder` rebuilds *every* grant from its matrix, so check nobody has tuned grants via the RBAC console first.
- **An admin masks permission bugs** — `PermissionResolver::isAdmin()` passes structurally. Test as an `inventory_analyst`.
- **`Sanctum::actingAs()` 500s here.** Use `$this->actingAs($user, 'sanctum')`.
- **The sidebar takes two edits** — `navBundles.js` + `NavBundleSeeder` supply the gate; `adminMenuSections` in `Sidebar.jsx` supplies the item.
- Backend baseline: **9 `WarrantyClaimTest`** failures plus the order-dependent `SerialRowMovementColumnsTest`.
- Frontend baseline: **3 failing suites** — `App.test.jsx`, `navConfigureMap`, `InventoryManagement`.
- Verify frontend with `npm run build` **and** `npx eslint` on each changed file.
- **~20 files are dirty** in the working tree, including `airkingv5.sql`. Stage only what each task names. Never `git add -A`.
- **Never name a test helper `seed()`.** It collides with `Illuminate\Foundation\Testing\TestCase::seed()` and is a hard PHP fatal that aborts the *entire* suite before one test runs.
- **`incident_items` has no `location_id`** — location lives on the parent report. `ScopesToUserLocation` filters a `location_id` column and therefore cannot be used on it. Anything needing location scoping joins `incident_reports` and filters `ir.location_id`, as `IncidentItem::quarantinedBatchIds()` does. This matters in Task 8, where the old `StockHoldController` relied on `StockHold::forUser()`.
- PowerShell here-strings break `git commit -m`; use Bash `git commit -F - <<'EOF' … EOF`.
- Do **not** push.

## Naming — fixed across every task

| Thing | Name |
|---|---|
| Item table / model | `incident_items` / `App\Models\IncidentItem`, PK `item_id` |
| Evidence table / model | `incident_evidence` / `App\Models\IncidentEvidence`, PK `evidence_id` |
| Service | `App\Services\IncidentItemService` (reshaped from `StockHoldService`) |
| Item status | `quarantined` · `available` · `scrapped` · `returned` · `missing` |
| Item outcome | `restock` · `scrap` · `return_to_supplier` · `write_off` |
| Unit role | `single` · `indoor` · `outdoor` |
| Evidence keys | `defect_photo` · `model_serial_sticker` · `box_two_sides` · `accessories_warranty` · `asc_service_report` |
| Incident statuses | `Open` · `Awaiting Inspection` · `Resolved` · `Dismissed` |
| Permission | `incident_reports.inspect` (new); `incident_reports.dispose` retired |

## File structure

| File | Status |
|---|---|
| `database/migrations/2026_08_12_000001_create_incident_items.php` | create |
| `database/migrations/2026_08_12_000002_create_incident_evidence.php` | create |
| `database/migrations/2026_08_12_000003_add_inspection_fields_to_incident_reports.php` | create |
| `database/migrations/2026_08_12_000004_migrate_stock_holds_to_incident_items.php` | create |
| `app/Models/IncidentItem.php`, `IncidentEvidence.php` | create |
| `app/Models/StockHold.php` | **delete** (after Task 4) |
| `app/Services/IncidentItemService.php` | create (from `StockHoldService`) |
| `app/Services/StockHoldService.php` | **delete** |
| `app/Services/Incidents/*` | rewritten — outcomes replace strategies |
| `app/Http/Controllers/Api/IncidentInspectionController.php` | create |
| `app/Http/Controllers/Api/StockHoldController.php` | reshaped → item register |
| `frontend/src/admin/pages/IncidentInspectionPage.jsx` | create |
| `frontend/src/admin/pages/StockHoldsPage.jsx` | reshaped → register |
| `frontend/src/admin/components/incident/EvidenceChecklist.jsx` | create |
| `frontend/src/admin/components/incident/IncidentItemsStep.jsx` | create |

---

### Task 1: `incident_items`

**Files:** Create `back-end/database/migrations/2026_08_12_000001_create_incident_items.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per PHYSICAL THING on an incident report.
 *
 * Replaces stock_holds. Same idea — a quantity of one product, optionally one
 * serial, carrying a status — but this is the unit of the REPORT, so a
 * split-system aircon is two rows (indoor + outdoor) on one report and the
 * inspector can scrap one while restocking the other. stock_holds pointed at a
 * single product and could not express that.
 *
 * status and outcome are plain string codes, not FKs into status_lookup: the
 * lookup table's ids differ between the dev and test databases, and there is no
 * reason to inherit that hazard for a new column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_items', function (Blueprint $table) {
            $table->bigIncrements('item_id');

            $table->unsignedBigInteger('incident_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('serial_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();

            // The client's form has Model ID and Model OD — an indoor and an
            // outdoor unit on one report.
            $table->string('unit_role', 10)->default('single');   // single|indoor|outdoor

            // Kept verbatim from the sticker, ALONGSIDE the FKs. A defective
            // unit's label may match nothing in the catalogue, and that
            // mismatch is often the incident itself — refusing to record what
            // is physically printed on the box would lose the evidence.
            $table->string('model_code', 100)->nullable();
            $table->string('serial_number', 100)->nullable();

            $table->integer('quantity');                          // PIECES; 1 when serial_id is set
            $table->string('status', 20)->default('quarantined'); // quarantined|available|scrapped|returned|missing
            $table->string('outcome', 30)->nullable();            // the inspector's decision, applied on confirmation
            $table->text('outcome_note')->nullable();

            $table->timestamps();

            // "Which batches are quarantined at this location" runs on every
            // FIFO pick, so it must not table-scan.
            $table->index(['status', 'batch_id'], 'incident_items_quarantine_idx');
            $table->index('incident_id');
            $table->index('product_id');

            $table->foreign('incident_id')->references('incident_id')->on('incident_reports');
            $table->foreign('product_id')->references('product_id')->on('products');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_items');
    }
};
```

- [ ] **Step 2: Apply to both databases and verify**

```bash
cd back-end
php artisan migrate --path=database/migrations/2026_08_12_000001_create_incident_items.php --force
DB_DATABASE=airkingV5_test php artisan migrate --path=database/migrations/2026_08_12_000001_create_incident_items.php --force
php -r '
foreach (["airkingv5","airkingV5_test"] as $db) {
  $c = new mysqli("127.0.0.1","root","",$db);
  echo str_pad($db,20) . ($c->query("SHOW TABLES LIKE \"incident_items\"")->num_rows ? "OK" : "MISSING") . "\n";
}'
```

Expected: `OK` twice. If either is MISSING, stop — everything downstream depends on it.

- [ ] **Step 3: Commit**

```bash
git add back-end/database/migrations/2026_08_12_000001_create_incident_items.php
git commit -F - <<'EOF'
feat(incidents): incident_items, one row per physical thing

Replaces stock_holds as the unit of a report so a split-system indoor and
outdoor pair is two rows with two outcomes, which the single-product shape
could not express.
EOF
```

---

### Task 2: `incident_evidence`

**Files:** Create `back-end/database/migrations/2026_08_12_000002_create_incident_evidence.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's five-item attachment checklist.
 *
 * incident_reports carries exactly ONE photo_path today; their form requires
 * five distinct pictures. Each row is one requirement, either satisfied by a
 * photo or explicitly skipped with a written reason — a skip is data, not an
 * absence, so the admin can see that the box photo is missing BECAUSE the box
 * was already discarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_evidence', function (Blueprint $table) {
            $table->bigIncrements('evidence_id');

            $table->unsignedBigInteger('incident_id');
            $table->string('requirement', 40);   // defect_photo|model_serial_sticker|box_two_sides|accessories_warranty|asc_service_report

            // Private `local` disk, like the existing evidence photo — these may
            // show named staff, stock layout, or the subject of a live loss
            // investigation, so they are served through an authenticated route.
            $table->string('photo_path', 255)->nullable();
            // Client-asserted: a camera capture and a gallery pick arrive as
            // identical uploads, so this is recorded, never trusted.
            $table->string('photo_source', 20)->nullable();

            $table->text('skipped_reason')->nullable();

            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();

            $table->index(['incident_id', 'requirement']);
            $table->foreign('incident_id')->references('incident_id')->on('incident_reports');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_evidence');
    }
};
```

Note: **no unique constraint** on `(incident_id, requirement)`. `box_two_sides` is literally two photos, and a re-inspection after a rejection may add more. Uniqueness is enforced in the service where the rule can be expressed properly, not by a constraint that would reject legitimate second photos.

- [ ] **Step 2: Apply to both databases and verify**

```bash
cd back-end
php artisan migrate --path=database/migrations/2026_08_12_000002_create_incident_evidence.php --force
DB_DATABASE=airkingV5_test php artisan migrate --path=database/migrations/2026_08_12_000002_create_incident_evidence.php --force
php -r '
foreach (["airkingv5","airkingV5_test"] as $db) {
  $c = new mysqli("127.0.0.1","root","",$db);
  echo str_pad($db,20) . ($c->query("SHOW TABLES LIKE \"incident_evidence\"")->num_rows ? "OK" : "MISSING") . "\n";
}'
```

- [ ] **Step 3: Commit**

```bash
git add back-end/database/migrations/2026_08_12_000002_create_incident_evidence.php
git commit -F - <<'EOF'
feat(incidents): incident_evidence for the five-item checklist

One row per requirement, satisfied by a photo or explicitly skipped with a
reason — a skip is data, so a missing box photo can say the box was already
discarded rather than just being absent.
EOF
```

---

### Task 3: Inspection fields on `incident_reports`

**Files:** Create `back-end/database/migrations/2026_08_12_000003_add_inspection_fields_to_incident_reports.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's document block, plus the inspection record.
 *
 * The dealer/customer/site fields are filled by the REPORTER at filing — they
 * know who the unit went to. The inspection fields are filled by the INSPECTOR.
 * The signature columns hold names as printed on the hardcopy that gets signed
 * by hand and carried to the admin; this is a record that signing happened, not
 * a digital signature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_reports', function (Blueprint $table) {
            // Dealer block
            $table->string('dealer_name', 150)->nullable()->after('return_reason');
            $table->string('dealer_phone', 40)->nullable()->after('dealer_name');
            $table->string('dealer_mobile', 40)->nullable()->after('dealer_phone');
            $table->string('dealer_email', 150)->nullable()->after('dealer_mobile');
            $table->string('branch_address', 255)->nullable()->after('dealer_email');

            // Customer block
            $table->string('customer_name', 150)->nullable()->after('branch_address');
            $table->string('customer_contact', 40)->nullable()->after('customer_name');
            $table->string('site_address', 255)->nullable()->after('customer_contact');
            $table->date('purchased_at')->nullable()->after('site_address');
            $table->date('received_at_dor')->nullable()->after('purchased_at');

            // Inspection
            $table->unsignedBigInteger('inspected_by')->nullable()->after('received_at_dor');
            $table->dateTime('inspected_at')->nullable()->after('inspected_by');
            $table->text('inspection_findings')->nullable()->after('inspected_at');
            $table->boolean('checked_by_asc')->default(false)->after('inspection_findings');

            // Signatures — names as printed on the signed hardcopy
            $table->string('signed_by_representative', 150)->nullable()->after('checked_by_asc');
            $table->string('signed_by_branch_manager', 150)->nullable()->after('signed_by_representative');
            $table->date('signed_at')->nullable()->after('signed_by_branch_manager');

            $table->text('rejection_note')->nullable()->after('signed_at');
        });
    }

    public function down(): void
    {
        Schema::table('incident_reports', function (Blueprint $table) {
            $table->dropColumn([
                'dealer_name', 'dealer_phone', 'dealer_mobile', 'dealer_email', 'branch_address',
                'customer_name', 'customer_contact', 'site_address', 'purchased_at', 'received_at_dor',
                'inspected_by', 'inspected_at', 'inspection_findings', 'checked_by_asc',
                'signed_by_representative', 'signed_by_branch_manager', 'signed_at', 'rejection_note',
            ]);
        });
    }
};
```

- [ ] **Step 2: Apply to both databases and verify all 18 columns exist**

```bash
cd back-end
php artisan migrate --path=database/migrations/2026_08_12_000003_add_inspection_fields_to_incident_reports.php --force
DB_DATABASE=airkingV5_test php artisan migrate --path=database/migrations/2026_08_12_000003_add_inspection_fields_to_incident_reports.php --force
php -r '
$want = ["dealer_name","dealer_phone","dealer_mobile","dealer_email","branch_address","customer_name",
"customer_contact","site_address","purchased_at","received_at_dor","inspected_by","inspected_at",
"inspection_findings","checked_by_asc","signed_by_representative","signed_by_branch_manager","signed_at","rejection_note"];
foreach (["airkingv5","airkingV5_test"] as $db) {
  $c = new mysqli("127.0.0.1","root","",$db);
  $r = $c->query("SHOW COLUMNS FROM incident_reports"); $have = [];
  while ($x = $r->fetch_assoc()) $have[] = $x["Field"];
  $missing = array_diff($want, $have);
  echo str_pad($db,20) . (empty($missing) ? "all 18 OK" : "MISSING: " . implode(",", $missing)) . "\n";
}'
```

- [ ] **Step 3: Commit**

```bash
git add back-end/database/migrations/2026_08_12_000003_add_inspection_fields_to_incident_reports.php
git commit -F - <<'EOF'
feat(incidents): the client's document block and the inspection record

Dealer, customer, site and purchase dates are filled by the reporter; the
inspection fields and the signature names by the inspector. The signature
columns record that a hardcopy was signed by hand, not a digital signature.
EOF
```

---

### Task 4: Migrate `stock_holds` into `incident_items`, then drop it

**Files:**
- Create: `back-end/database/migrations/2026_08_12_000004_migrate_stock_holds_to_incident_items.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * stock_holds becomes incident_items.
 *
 * Condition maps onto the new status vocabulary; a hold that was still `held`
 * has not been inspected yet, so it becomes `quarantined` under the new order.
 * A released hold already had its decision, which becomes the item's final
 * status.
 *
 * The table is dropped rather than left behind: two tables meaning the same
 * thing, one of them stale, is how a stock count starts disagreeing with the
 * system.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_holds')) {
            return;
        }

        foreach (DB::table('stock_holds')->get() as $hold) {
            DB::table('incident_items')->insert([
                'incident_id'   => $hold->incident_id,
                'product_id'    => $hold->product_id,
                'serial_id'     => $hold->serial_id,
                'batch_id'      => $hold->batch_id,
                'unit_role'     => 'single',
                'quantity'      => $hold->quantity,
                'status'        => $this->statusFor($hold),
                'outcome'       => $this->outcomeFor($hold),
                'outcome_note'  => $hold->disposition_note,
                'created_at'    => $hold->created_at,
                'updated_at'    => $hold->updated_at,
            ]);
        }

        Schema::dropIfExists('stock_holds');
    }

    private function statusFor(object $hold): string
    {
        if ($hold->status === 'held') {
            // Never inspected under the old order either — it was waiting for a
            // disposition, which is what quarantined now means.
            return 'quarantined';
        }

        return match ($hold->disposition) {
            'restocked'            => 'available',
            'scrapped'             => 'scrapped',
            'returned_to_supplier' => 'returned',
            default                => $hold->condition === 'missing' ? 'missing' : 'quarantined',
        };
    }

    private function outcomeFor(object $hold): ?string
    {
        return match ($hold->disposition) {
            'restocked'            => 'restock',
            'scrapped'             => 'scrap',
            'returned_to_supplier' => 'return_to_supplier',
            'defective'            => null,
            default                => $hold->condition === 'missing' ? 'write_off' : null,
        };
    }

    public function down(): void
    {
        // Deliberately irreversible. Rebuilding stock_holds from incident_items
        // would invent disposition values the new vocabulary does not carry, and
        // a wrong reversal here silently corrupts stock counts. Restore from a
        // backup instead.
    }
};
```

- [ ] **Step 2: Record the row count first, then migrate**

```bash
cd back-end
php -r '$c=new mysqli("127.0.0.1","root","","airkingv5"); echo "stock_holds rows before: ".$c->query("SELECT COUNT(*) n FROM stock_holds")->fetch_assoc()["n"]."\n";'
php artisan migrate --path=database/migrations/2026_08_12_000004_migrate_stock_holds_to_incident_items.php --force
DB_DATABASE=airkingV5_test php artisan migrate --path=database/migrations/2026_08_12_000004_migrate_stock_holds_to_incident_items.php --force
php -r '$c=new mysqli("127.0.0.1","root","","airkingv5"); echo "incident_items rows after: ".$c->query("SELECT COUNT(*) n FROM incident_items")->fetch_assoc()["n"]."\n"; echo "stock_holds gone: ".($c->query("SHOW TABLES LIKE \"stock_holds\"")->num_rows ? "NO" : "yes")."\n";'
```

Expected: the counts match, and `stock_holds` is gone from both databases.

- [ ] **Step 3: Commit**

```bash
git add back-end/database/migrations/2026_08_12_000004_migrate_stock_holds_to_incident_items.php
git commit -F - <<'EOF'
refactor(incidents): migrate stock_holds into incident_items and drop it

A held hold was never inspected, so it becomes quarantined under the new
order; a released one already had its decision, which becomes the item's
final status. Irreversible on purpose — reversing would invent disposition
values the new vocabulary does not carry.
EOF
```

---

### Task 5: Models

**Files:**
- Create: `back-end/app/Models/IncidentItem.php`
- Create: `back-end/app/Models/IncidentEvidence.php`
- Delete: `back-end/app/Models/StockHold.php`

- [ ] **Step 1: `IncidentItem`**

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
 * One physical thing on an incident report.
 *
 * Never write status or outcome directly — IncidentItemService is the sole
 * mutator, because changing an item's status and moving the matching stock have
 * to happen together or `quantity_on_hand = pickable + quarantined` breaks
 * silently, and nobody notices until a physical count.
 */
class IncidentItem extends Model
{
    use Auditable;

    public const STATUS_QUARANTINED = 'quarantined';
    public const STATUS_AVAILABLE   = 'available';
    public const STATUS_SCRAPPED    = 'scrapped';
    public const STATUS_RETURNED    = 'returned';
    public const STATUS_MISSING     = 'missing';

    public const STATUSES = [
        self::STATUS_QUARANTINED, self::STATUS_AVAILABLE,
        self::STATUS_SCRAPPED, self::STATUS_RETURNED, self::STATUS_MISSING,
    ];

    public const OUTCOME_RESTOCK            = 'restock';
    public const OUTCOME_SCRAP              = 'scrap';
    public const OUTCOME_RETURN_TO_SUPPLIER = 'return_to_supplier';
    public const OUTCOME_WRITE_OFF          = 'write_off';

    public const OUTCOMES = [
        self::OUTCOME_RESTOCK, self::OUTCOME_SCRAP,
        self::OUTCOME_RETURN_TO_SUPPLIER, self::OUTCOME_WRITE_OFF,
    ];

    /** What each outcome leaves the item as, once the admin confirms. */
    public const OUTCOME_STATUS = [
        self::OUTCOME_RESTOCK            => self::STATUS_AVAILABLE,
        self::OUTCOME_SCRAP              => self::STATUS_SCRAPPED,
        self::OUTCOME_RETURN_TO_SUPPLIER => self::STATUS_RETURNED,
        self::OUTCOME_WRITE_OFF          => self::STATUS_MISSING,
    ];

    /**
     * The only outcome that returns quantity to pickable stock. Everything else
     * deducts it — the goods left the building or were destroyed.
     */
    public const OUTCOME_RESTOCKS = [self::OUTCOME_RESTOCK];

    public const ROLE_SINGLE  = 'single';
    public const ROLE_INDOOR  = 'indoor';
    public const ROLE_OUTDOOR = 'outdoor';
    public const ROLES = [self::ROLE_SINGLE, self::ROLE_INDOOR, self::ROLE_OUTDOOR];

    protected $table      = 'incident_items';
    protected $primaryKey = 'item_id';

    protected $fillable = [
        'incident_id', 'product_id', 'serial_id', 'batch_id', 'unit_role',
        'model_code', 'serial_number', 'quantity', 'status', 'outcome', 'outcome_note',
    ];

    protected $casts = ['quantity' => 'integer'];

    public function scopeQuarantined(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_QUARANTINED);
    }

    /**
     * Batch ids currently unpickable because an item on an unfinished report
     * names them.
     *
     * Driven from ITEM status, not incident status. Deriving it from the
     * incident's status broke once already: adding "Awaiting Inspection"
     * silently returned stock under investigation to the picking pool, because
     * the query filtered on "Open".
     */
    public static function quarantinedBatchIds(int $locationId): array
    {
        return DB::table('incident_items as ii')
            ->join('incident_reports as ir', 'ir.incident_id', '=', 'ii.incident_id')
            ->where('ir.location_id', $locationId)
            ->where('ii.status', self::STATUS_QUARANTINED)
            ->whereNotNull('ii.batch_id')
            ->distinct()
            ->pluck('ii.batch_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(IncidentReport::class, 'incident_id', 'incident_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
```

- [ ] **Step 2: `IncidentEvidence`**

```php
<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attachment against the client's checklist — a photo, or an explicit skip
 * with the reason it could not be provided.
 */
class IncidentEvidence extends Model
{
    use Auditable;

    public const REQ_DEFECT_PHOTO         = 'defect_photo';
    public const REQ_MODEL_SERIAL_STICKER = 'model_serial_sticker';
    public const REQ_BOX_TWO_SIDES        = 'box_two_sides';
    public const REQ_ACCESSORIES_WARRANTY = 'accessories_warranty';
    public const REQ_ASC_SERVICE_REPORT   = 'asc_service_report';

    public const REQUIREMENTS = [
        self::REQ_DEFECT_PHOTO, self::REQ_MODEL_SERIAL_STICKER,
        self::REQ_BOX_TWO_SIDES, self::REQ_ACCESSORIES_WARRANTY,
        self::REQ_ASC_SERVICE_REPORT,
    ];

    /**
     * What each incident type is asked for.
     *
     * `missing` asks for NOTHING: the item is gone, so there is nothing to
     * photograph, and demanding a picture of an absent unit is how a checklist
     * teaches people to fake evidence. `wrong_type` needs the sticker, because
     * the sticker not matching the goods IS the incident.
     *
     * asc_service_report is added at runtime when checked_by_asc is set — it is
     * conditional on a fact, not on the type.
     */
    public const REQUIRED_BY_REASON = [
        'defective'     => [self::REQ_DEFECT_PHOTO, self::REQ_MODEL_SERIAL_STICKER,
                            self::REQ_BOX_TWO_SIDES, self::REQ_ACCESSORIES_WARRANTY],
        'return'        => [self::REQ_DEFECT_PHOTO, self::REQ_MODEL_SERIAL_STICKER,
                            self::REQ_BOX_TWO_SIDES, self::REQ_ACCESSORIES_WARRANTY],
        'wrong_type'    => [self::REQ_MODEL_SERIAL_STICKER],
        'missing'       => [],
        'zone_mismatch' => [],
        'other'         => [],
    ];

    protected $table      = 'incident_evidence';
    protected $primaryKey = 'evidence_id';

    protected $fillable = [
        'incident_id', 'requirement', 'photo_path', 'photo_source',
        'skipped_reason', 'uploaded_by',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(IncidentReport::class, 'incident_id', 'incident_id');
    }
}
```

- [ ] **Step 3: Verify both load**

```bash
cd back-end && php artisan tinker --execute="
  echo count(App\Models\IncidentItem::STATUSES), ' statuses, ',
       count(App\Models\IncidentEvidence::REQUIREMENTS), ' requirements', PHP_EOL;"
```

Expected: `5 statuses, 5 requirements`.

- [ ] **Step 4: Commit**

```bash
git add back-end/app/Models/IncidentItem.php back-end/app/Models/IncidentEvidence.php
git commit -F - <<'EOF'
feat(incidents): IncidentItem and IncidentEvidence models

Item status and outcome are string codes with class constants — no lookup
table, so there is no install-specific id to resolve and no way for dev and
test to disagree.

quarantinedBatchIds derives FIFO exclusion from ITEM status rather than
incident status, which is the bug that already shipped once.
EOF
```

---

### Task 6: `IncidentItemService` — the sole mutator

**Files:**
- Create: `back-end/app/Services/IncidentItemService.php`
- Delete: `back-end/app/Services/StockHoldService.php`, `back-end/app/Models/StockHold.php`
- Test: `back-end/tests/Feature/Incidents/IncidentItemServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Services\IncidentItemService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class IncidentItemServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function actorId(): int
    {
        return (int) \App\Models\User::query()->firstOrFail()->getKey();
    }

    /**
     * NOT named seed(): that collides with Illuminate's TestCase::seed() and is
     * a hard PHP fatal ("Access level must be public") which aborts the ENTIRE
     * suite before a single test runs — not just this file.
     */
    private function makeScenario(int $onHand = 100): array
    {
        $location = \App\Models\Location::query()->firstOrFail();
        $product  = \App\Models\Product::query()->firstOrFail();

        Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)->delete();

        $inventory = Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => $onHand, 'available_quantity' => $onHand, 'reorder_level' => 0,
        ]);

        $batch = InventoryBatch::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_received' => $onHand, 'quantity_remaining' => $onHand, 'received_at' => now(),
        ]);

        $incident = IncidentReport::create([
            'incident_number' => 'INC-TEST-' . uniqid(), 'source_type' => 'standalone',
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'batch_id' => $batch->batch_id, 'reason' => 'defective', 'quantity' => 10,
            'narrative' => 'test', 'reported_by' => $this->actorId(),
            'status_id' => IncidentReport::statusId('Open'),
        ]);

        return compact('inventory', 'batch', 'incident', 'product');
    }

    /** THE core guarantee: filing quarantines but does not deduct. */
    public function test_quarantine_does_not_change_the_stock_count(): void
    {
        ['inventory' => $inv, 'batch' => $batch, 'incident' => $incident, 'product' => $p] = $this->makeScenario(100);

        app(IncidentItemService::class)->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => $batch->batch_id, 'quantity' => 10,
        ]]);

        $this->assertSame(100, (int) $inv->refresh()->quantity_on_hand, 'filing must not deduct');
        $this->assertSame(100, (int) $batch->refresh()->quantity_remaining);
        $this->assertSame(IncidentItem::STATUS_QUARANTINED,
            IncidentItem::where('incident_id', $incident->incident_id)->firstOrFail()->status);
    }

    /** …but it IS unpickable. */
    public function test_a_quarantined_batch_is_excluded_from_picking(): void
    {
        ['batch' => $batch, 'incident' => $incident, 'product' => $p] = $this->makeScenario(100);

        app(IncidentItemService::class)->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => $batch->batch_id, 'quantity' => 10,
        ]]);

        $this->assertContains(
            (int) $batch->batch_id,
            IncidentItem::quarantinedBatchIds((int) $incident->location_id)
        );
    }

    /** One report, two units, two different endings. */
    public function test_each_item_gets_its_own_outcome(): void
    {
        ['batch' => $batch, 'incident' => $incident, 'product' => $p] = $this->makeScenario(100);
        $svc = app(IncidentItemService::class);

        $svc->quarantine($incident, [
            ['product_id' => $p->product_id, 'batch_id' => $batch->batch_id, 'quantity' => 1, 'unit_role' => 'indoor'],
            ['product_id' => $p->product_id, 'batch_id' => $batch->batch_id, 'quantity' => 1, 'unit_role' => 'outdoor'],
        ]);

        $items = IncidentItem::where('incident_id', $incident->incident_id)->orderBy('item_id')->get();
        $svc->setOutcome($items[0], IncidentItem::OUTCOME_SCRAP, 'panel cracked');
        $svc->setOutcome($items[1], IncidentItem::OUTCOME_RESTOCK, 'undamaged');

        $svc->applyOutcomes($incident, $this->actorId());

        $this->assertSame(IncidentItem::STATUS_SCRAPPED, $items[0]->refresh()->status);
        $this->assertSame(IncidentItem::STATUS_AVAILABLE, $items[1]->refresh()->status);
    }

    /** Confirmation is where stock finally moves — one restock, one scrap. */
    public function test_applying_outcomes_moves_stock_exactly_once(): void
    {
        ['inventory' => $inv, 'batch' => $batch, 'incident' => $incident, 'product' => $p] = $this->makeScenario(100);
        $svc = app(IncidentItemService::class);

        $svc->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => $batch->batch_id, 'quantity' => 10,
        ]]);
        $item = IncidentItem::where('incident_id', $incident->incident_id)->firstOrFail();
        $svc->setOutcome($item, IncidentItem::OUTCOME_SCRAP, 'unsellable');

        $svc->applyOutcomes($incident, $this->actorId());

        // Scrapped stock leaves the building.
        $this->assertSame(90, (int) $inv->refresh()->quantity_on_hand);
        $this->assertSame(90, (int) $batch->refresh()->quantity_remaining);
    }

    public function test_a_restock_outcome_leaves_the_count_untouched(): void
    {
        ['inventory' => $inv, 'batch' => $batch, 'incident' => $incident, 'product' => $p] = $this->makeScenario(100);
        $svc = app(IncidentItemService::class);

        $svc->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => $batch->batch_id, 'quantity' => 10,
        ]]);
        $item = IncidentItem::where('incident_id', $incident->incident_id)->firstOrFail();
        $svc->setOutcome($item, IncidentItem::OUTCOME_RESTOCK, 'fine');

        $svc->applyOutcomes($incident, $this->actorId());

        // It was never deducted, so restocking it is a no-op on the numbers —
        // only the item's status changes, and the batch becomes pickable again.
        $this->assertSame(100, (int) $inv->refresh()->quantity_on_hand);
        $this->assertSame(100, (int) $batch->refresh()->quantity_remaining);
        $this->assertNotContains(
            (int) $batch->batch_id,
            IncidentItem::quarantinedBatchIds((int) $incident->location_id)
        );
    }

    public function test_outcomes_cannot_be_applied_twice(): void
    {
        ['batch' => $batch, 'incident' => $incident, 'product' => $p] = $this->makeScenario(100);
        $svc = app(IncidentItemService::class);

        $svc->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => $batch->batch_id, 'quantity' => 10,
        ]]);
        $item = IncidentItem::where('incident_id', $incident->incident_id)->firstOrFail();
        $svc->setOutcome($item, IncidentItem::OUTCOME_SCRAP, null);
        $svc->applyOutcomes($incident, $this->actorId());

        $this->expectException(\RuntimeException::class);
        $svc->applyOutcomes($incident, $this->actorId());
    }

    public function test_an_item_without_an_outcome_blocks_confirmation(): void
    {
        ['batch' => $batch, 'incident' => $incident, 'product' => $p] = $this->makeScenario(100);
        $svc = app(IncidentItemService::class);

        $svc->quarantine($incident, [[
            'product_id' => $p->product_id, 'batch_id' => $batch->batch_id, 'quantity' => 10,
        ]]);

        $this->expectException(\InvalidArgumentException::class);
        $svc->applyOutcomes($incident, $this->actorId());
    }
}
```

- [ ] **Step 2: Run it, watch it fail**

```bash
cd back-end && php artisan test --filter=IncidentItemServiceTest
```

Expected: FAIL — `Target class [App\Services\IncidentItemService] does not exist`.

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use Illuminate\Support\Facades\DB;

/**
 * The SOLE mutator of incident_items.
 *
 * Quarantine does NOT deduct: the goods are physically present and a stock
 * count would find them, so `quantity_on_hand = pickable + quarantined` holds
 * from filing until confirmation. Stock moves once, in applyOutcomes(), inside
 * one transaction — a partial confirm (stock gone, report unconfirmed, or the
 * reverse) is undetectable without a physical count.
 */
class IncidentItemService
{
    /**
     * The serial status each item status maps to.
     *
     * Written out rather than derived with ucfirst(): that would produce
     * "Scrapped" and "Available", and only the names in this map are actually
     * seeded into status_lookup. A derived name that happens not to exist
     * throws at the worst possible moment — mid-confirmation, after the stock
     * has already been deducted inside the transaction.
     */
    private const SERIAL_STATUS_NAME = [
        IncidentItem::STATUS_QUARANTINED => 'Quarantined',
        IncidentItem::STATUS_AVAILABLE   => 'In Stock',
        IncidentItem::STATUS_SCRAPPED    => 'Scrapped',
        IncidentItem::STATUS_RETURNED    => 'Returned',
        IncidentItem::STATUS_MISSING     => 'Missing',
    ];

    /**
     * Attach the physical things a report is about and make them unpickable.
     *
     * @param array<int,array{product_id:int,batch_id?:int|null,serial_id?:int|null,quantity:int,unit_role?:string,model_code?:string|null,serial_number?:string|null}> $items
     * @return array<int,IncidentItem>
     */
    public function quarantine(IncidentReport $incident, array $items): array
    {
        if ($items === []) {
            throw new \InvalidArgumentException('An incident that moves stock must name at least one item.');
        }

        return DB::transaction(function () use ($incident, $items) {
            $created = [];

            foreach ($items as $row) {
                $created[] = IncidentItem::create([
                    'incident_id'   => (int) $incident->incident_id,
                    'product_id'    => (int) $row['product_id'],
                    'serial_id'     => $row['serial_id'] ?? null,
                    'batch_id'      => $row['batch_id'] ?? null,
                    'unit_role'     => $row['unit_role'] ?? IncidentItem::ROLE_SINGLE,
                    'model_code'    => $row['model_code'] ?? null,
                    'serial_number' => $row['serial_number'] ?? null,
                    'quantity'      => max(1, (int) $row['quantity']),
                    'status'        => IncidentItem::STATUS_QUARANTINED,
                ]);

                // A scan must say "quarantined", not "in stock", the moment a
                // report names the unit.
                $this->stampSerial($row['serial_id'] ?? null, 'Quarantined');
            }

            return $created;
        });
    }

    /** The inspector's decision. Recorded now, applied only when the admin confirms. */
    public function setOutcome(IncidentItem $item, string $outcome, ?string $note): IncidentItem
    {
        if (! in_array($outcome, IncidentItem::OUTCOMES, true)) {
            throw new \InvalidArgumentException("Unknown outcome: {$outcome}");
        }

        if ($item->status !== IncidentItem::STATUS_QUARANTINED) {
            throw new \RuntimeException('This item has already been settled.');
        }

        $item->update(['outcome' => $outcome, 'outcome_note' => $note]);

        return $item->fresh();
    }

    /**
     * Apply every item's outcome. This is the admin's confirmation, and the one
     * moment stock actually moves.
     */
    public function applyOutcomes(IncidentReport $incident, int $actorId): void
    {
        DB::transaction(function () use ($incident, $actorId) {
            $items = IncidentItem::where('incident_id', $incident->incident_id)
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw new \RuntimeException('This incident has no items to settle.');
            }

            // Re-checked UNDER the lock: two admins confirming at once must not
            // both pass the guard and deduct the quantity twice.
            if ($items->every(fn (IncidentItem $i) => $i->status !== IncidentItem::STATUS_QUARANTINED)) {
                throw new \RuntimeException('This incident has already been confirmed.');
            }

            $undecided = $items->firstWhere(fn (IncidentItem $i) =>
                $i->status === IncidentItem::STATUS_QUARANTINED && $i->outcome === null);

            if ($undecided !== null) {
                throw new \InvalidArgumentException(
                    'Every item needs an inspection outcome before this can be confirmed.'
                );
            }

            foreach ($items as $item) {
                if ($item->status !== IncidentItem::STATUS_QUARANTINED) {
                    continue;
                }

                // Restock is the only outcome where the goods stay. Everything
                // else means they left or were destroyed, so the count drops —
                // and because quarantine never deducted, this is the single
                // deduction for the whole lifecycle.
                if (! in_array($item->outcome, IncidentItem::OUTCOME_RESTOCKS, true)) {
                    $this->deduct($item);
                }

                $status = IncidentItem::OUTCOME_STATUS[$item->outcome];

                $item->update(['status' => $status]);

                $this->stampSerial(
                    $item->serial_id,
                    self::SERIAL_STATUS_NAME[$status],
                    makeReceivable: $status === IncidentItem::STATUS_AVAILABLE,
                );
            }
        });
    }

    private function deduct(IncidentItem $item): void
    {
        $quantity = (int) $item->quantity;

        if ($item->batch_id !== null) {
            $batch = InventoryBatch::lockForUpdate()->find($item->batch_id);
            if ($batch !== null) {
                $batch->decrement('quantity_remaining', min($quantity, (int) $batch->quantity_remaining));
            }
        }

        $inventory = Inventory::where('location_id', $item->incident->location_id)
            ->where('product_id', $item->product_id)
            ->lockForUpdate()
            ->first();

        if ($inventory !== null) {
            $inventory->decrement('quantity_on_hand', min($quantity, (int) $inventory->quantity_on_hand));
            $inventory->decrement('available_quantity', min($quantity, (int) $inventory->available_quantity));
        }
    }

    /**
     * Keep the serial's own status honest.
     *
     * `$makeReceivable` also resets serial_type to 'received'. That pair moves
     * together: scopeIssuableAt and FifoPickingService::serialSteps both require
     * serial_type 'received', so a serial flipped back to In Stock while still
     * typed 'issued' reads as in stock everywhere and is pickable nowhere.
     */
    private function stampSerial(?int $serialId, string $statusName, bool $makeReceivable = false): void
    {
        if ($serialId === null) {
            return;
        }

        $columns = ['status_id' => $this->inventoryStatusId($statusName)];

        if ($makeReceivable) {
            $columns['serial_type'] = 'received';
        }

        ItemSerial::where('serial_id', $serialId)->update($columns);
    }

    /** @var array<string,int> */
    private static array $statusCache = [];

    /** Resolved by NAME — these ids differ between the dev and test databases. */
    private function inventoryStatusId(string $name): int
    {
        if (isset(self::$statusCache[$name])) {
            return self::$statusCache[$name];
        }

        $id = DB::table('status_lookup')
            ->where('status_category', 'inventory')
            ->where('status_name', $name)
            ->value('status_id');

        if ($id === null) {
            throw new \RuntimeException("Unknown inventory status: {$name}. Run IncidentStatusSeeder.");
        }

        return self::$statusCache[$name] = (int) $id;
    }
}
```

**Do this before running the tests.** Rename `database/seeders/StockHoldStatusSeeder.php` to `IncidentStatusSeeder.php` and seed **five** inventory statuses, not three — `SERIAL_STATUS_NAME` above names every one it can write, and a name that is not seeded throws mid-confirmation *after* stock has already been deducted inside the transaction:

```php
        foreach (['Missing', 'Defective', 'Returned', 'Quarantined', 'Scrapped'] as $name) {
            DB::table('status_lookup')->updateOrInsert(
                ['status_category' => 'inventory', 'status_name' => $name],
                ['is_active' => 1, 'updated_at' => now(), 'created_at' => now()],
            );
        }
```

Keep the `Awaiting Inspection` incident-category row the old seeder already writes. Then run it on both databases and verify all five exist:

```bash
cd back-end
php artisan db:seed --class=IncidentStatusSeeder --force
DB_DATABASE=airkingV5_test php artisan db:seed --class=IncidentStatusSeeder --force
php -r '
foreach (["airkingv5","airkingV5_test"] as $db) {
  $c = new mysqli("127.0.0.1","root","",$db);
  $r = $c->query("SELECT status_name FROM status_lookup WHERE status_category=\"inventory\" ORDER BY status_name");
  $n = []; while ($x = $r->fetch_assoc()) $n[] = $x["status_name"];
  echo str_pad($db,20) . implode(", ", $n) . "\n";
}'
```

Expected in both: Available-equivalent `In Stock`, plus Defective, Low Stock, Missing, Out of Stock, Quarantined, Returned, Scrapped.

- [ ] **Step 4: Run until green**

```bash
cd back-end && php artisan test --filter=IncidentItemServiceTest
```

Expected: **7 passed.**

- [ ] **Step 5: Delete the superseded service and model**

```bash
cd back-end
git rm app/Services/StockHoldService.php app/Models/StockHold.php
grep -rn -e "StockHold" app/ || echo "clean"
```

Hits in `app/` must be fixed now (the strategies and `StockHoldController` reference them — those are Tasks 7 and 8, so expect hits and resolve them there). Hits in `tests/` are Task 9.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Services/IncidentItemService.php back-end/app/Services/StockHoldService.php back-end/app/Models/StockHold.php back-end/database/seeders/ back-end/tests/Feature/Incidents/IncidentItemServiceTest.php
git commit -F - <<'EOF'
feat(incidents): IncidentItemService as the sole mutator

Quarantine makes stock unpickable without deducting it, so the system keeps
agreeing with a physical count for as long as the inspection takes. Stock
moves once, in applyOutcomes(), inside one transaction with the items locked
and their state re-checked under the lock.

Every item must carry an inspector outcome before confirmation is allowed.
EOF
```

---

### Task 7: Filing quarantines; FIFO reads item status

**Files:**
- Modify: `back-end/app/Services/IncidentService.php`
- Delete: `back-end/app/Services/Incidents/{Missing,Defective,Return,Relocate,Retype,Acknowledge}Resolution.php` and `ResolvesIncident.php`, `ResolutionArtifacts.php`
- Test: `back-end/tests/Feature/Incidents/IncidentFilingQuarantineTest.php`

The strategy pattern goes away. Under the new order there is no per-reason resolution — the inspector's per-item outcomes carry all the variation, and `applyOutcomes()` applies them uniformly.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class IncidentFilingQuarantineTest extends TestCase
{
    use DatabaseTransactions;

    public function test_filing_a_defective_report_quarantines_its_items(): void
    {
        $incident = $this->fileWithItems('defective');

        $this->assertSame(
            IncidentItem::STATUS_QUARANTINED,
            IncidentItem::where('incident_id', $incident->incident_id)->firstOrFail()->status
        );
        $this->assertSame(IncidentReport::statusId('Open'), (int) $incident->status_id);
    }

    /** Zone mismatch moves no stock, so it creates no items and quarantines nothing. */
    public function test_a_zone_mismatch_creates_no_items(): void
    {
        $incident = $this->fileWithItems('zone_mismatch');

        $this->assertSame(0, IncidentItem::where('incident_id', $incident->incident_id)->count());
    }

    public function test_an_other_report_creates_no_items(): void
    {
        $incident = $this->fileWithItems('other');

        $this->assertSame(0, IncidentItem::where('incident_id', $incident->incident_id)->count());
    }

    private function fileWithItems(string $reason): IncidentReport
    {
        $location = \App\Models\Location::query()->firstOrFail();
        $product  = \App\Models\Product::query()->firstOrFail();
        $userId   = (int) \App\Models\User::query()->firstOrFail()->getKey();

        $data = [
            'source_type' => 'standalone',
            'location_id' => $location->location_id,
            'reason'      => $reason,
            'narrative'   => 'test',
        ];

        if (! in_array($reason, ['zone_mismatch', 'other'], true)) {
            $data['product_id'] = $product->product_id;
            $data['quantity']   = 5;
            $data['items']      = [['product_id' => $product->product_id, 'quantity' => 5]];
        } else {
            $data['product_id'] = $product->product_id;
            $data['quantity']   = 1;
        }

        return app(IncidentService::class)->file($data, $userId);
    }
}
```

- [ ] **Step 2: Rewrite `IncidentService`**

`file()` gains: after creating the report, when the reason is not `zone_mismatch` or `other`, call `IncidentItemService::quarantine()` with `$data['items']` — falling back to a single item built from `product_id`/`batch_id`/`serial_id`/`quantity` so existing single-product callers (the picking modal) keep working.

`resolve()` is replaced by two methods:

```php
    /**
     * The inspector submits their findings. Moves Open -> Awaiting Inspection.
     * Does NOT touch stock.
     */
    public function submitInspection(IncidentReport $incident, array $data, int $inspectorId): IncidentReport

    /**
     * The admin's decision on a submitted inspection.
     *
     * 'confirm' applies every item outcome and closes the report; 'reject'
     * returns it to the inspector with a note; 'dismiss' closes it as raised in
     * error, releasing the quarantine without moving stock.
     */
    public function decide(IncidentReport $incident, string $action, array $options, int $adminId): IncidentReport
```

`decide('confirm')` locks the incident, re-checks its status under the lock (the existing pattern), calls `IncidentItemService::applyOutcomes()`, then stamps `Resolved`. It refuses when the incident is not `Awaiting Inspection`, **except** for `zone_mismatch` and `other`, which are confirmable straight from `Open`.

`decide('dismiss')` releases the quarantine — set each item's status to `available` **without** deducting, since nothing was ever deducted.

- [ ] **Step 3: Point FIFO exclusion at item status**

In `IncidentService`, replace `excludedBatchIds`'s body with `return IncidentItem::quarantinedBatchIds($locationId);` and delete the now-unused `unsettled()` scope on `IncidentReport`. Confirm the three call sites still work:

```bash
grep -rn "excludedBatchIds" back-end/app/
```

Expected: `IssuanceOrderController`, `IssuanceScanController`, `TransferController` — unchanged signatures.

- [ ] **Step 4: Delete the strategies**

```bash
cd back-end && git rm -r app/Services/Incidents/
grep -rn -e "Resolution" -e "ResolutionArtifacts" app/ || echo "clean"
```

- [ ] **Step 5: Run**

```bash
cd back-end && php artisan test --filter="IncidentFilingQuarantine|IncidentItemService"
```

Expected: **10 passed.**

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Services/ back-end/app/Models/IncidentReport.php back-end/tests/Feature/Incidents/IncidentFilingQuarantineTest.php
git commit -F - <<'EOF'
feat(incidents): filing quarantines, and FIFO reads item status

The per-reason strategy pattern is gone: the inspector's per-item outcomes
carry all the variation now, so applyOutcomes handles every type uniformly.

resolve() splits into submitInspection() and decide(), which is what puts
the admin's confirmation last. FIFO exclusion reads incident_items.status
rather than the incident's own status — the bug that already shipped once.
EOF
```

---

### Task 8: The inspection and decision endpoints

**Files:**
- Create: `back-end/app/Http/Controllers/Api/IncidentInspectionController.php`
- Modify: `back-end/app/Http/Controllers/Api/IncidentReportController.php`, `StockHoldController.php`, `routes/api.php`, `database/seeders/PermissionSeeder.php`
- Test: `back-end/tests/Feature/Incidents/IncidentInspectionEndpointTest.php`

Endpoints:

| Route | Permission | Does |
|---|---|---|
| `POST /incident-reports/{id}/evidence` | `incident_reports.inspect` | upload one photo, or record a skip with a reason |
| `DELETE /incident-reports/{id}/evidence/{evidenceId}` | `incident_reports.inspect` | remove an attachment before submitting |
| `POST /incident-reports/{id}/items/{itemId}/outcome` | `incident_reports.inspect` | set one item's outcome + note |
| `POST /incident-reports/{id}/inspection` | `incident_reports.inspect` | submit — Open → Awaiting Inspection |
| `POST /incident-reports/{id}/decide` | `incident_reports.resolve` | `confirm` · `reject` · `dismiss` |
| `GET /incident-items` | `incident_reports.read` | the register (replaces `/stock-holds`) |

Tests that must exist:

```php
public function test_an_admin_cannot_confirm_a_report_that_is_still_open(): void        // 422
public function test_a_zone_mismatch_is_confirmable_straight_from_open(): void          // 200
public function test_confirming_applies_every_item_outcome(): void
public function test_rejecting_returns_the_report_to_awaiting_inspection(): void
public function test_the_inspector_cannot_confirm_their_own_report(): void              // 403
public function test_evidence_records_a_skip_with_its_reason(): void
public function test_a_photo_path_outside_the_incidents_directory_is_rejected(): void   // 422
```

The `photo_path` regex already used by `IncidentReportController::store()` — `/^incidents\/\d{4}\/\d{2}\/[A-Za-z0-9]{40}\.(jpg|jpeg|png|webp)$/` — applies per evidence row. It exists because an unconstrained path let a filer submit another user's avatar or an absolute URL the frontend would fetch.

**Permissions** — in `PermissionSeeder`, replace `dispose` with `inspect`:

```php
            'incident_reports' => [
                'read'    => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'create'  => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel'],
                // Examining the goods and recording an outcome. Separate from
                // `resolve` on purpose: the client's form has two signature
                // lines so one person cannot both inspect and approve.
                'inspect' => ['admin', 'inventory_analyst', 'branch_manager'],
                'resolve' => ['admin', 'inventory_analyst'],
            ],
```

Then **both** seeders, on **both** databases:

```bash
cd back-end
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=RolePermissionsSeeder --force
DB_DATABASE=airkingV5_test php artisan db:seed --class=PermissionSeeder --force
DB_DATABASE=airkingV5_test php artisan db:seed --class=RolePermissionsSeeder --force
php artisan cache:clear && php artisan config:clear
```

`RolePermissionsSeeder` rebuilds every grant from its matrix — confirm nobody has tuned grants via the RBAC console first.

- [ ] **Step 1–5:** write the failing tests, implement the controller, add routes, seed permissions, run.

```bash
cd back-end && php artisan test --filter=IncidentInspectionEndpointTest
```

Expected: **7 passed.**

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/ back-end/routes/api.php back-end/database/seeders/PermissionSeeder.php back-end/tests/Feature/Incidents/IncidentInspectionEndpointTest.php
git commit -F - <<'EOF'
feat(incidents): inspection and decision endpoints

incident_reports.inspect is separate from resolve so one person cannot both
examine the goods and approve their write-off — the reason the client's form
carries two signature lines. `dispose` is retired.
EOF
```

---

### Task 9: Rewrite the tests that assert the old order

Roughly 40 existing incident tests assert approve-then-inspect, `stock_holds`, or the strategy classes. They fail **correctly**.

- [ ] **Step 1: See the damage**

```bash
cd back-end && php artisan test 2>&1 | grep -E "FAIL|Tests:"
```

| Old expectation | New expectation |
|---|---|
| approving deducts stock | approving **quarantines**; the count is unchanged until confirmation |
| `stock_holds` row exists | `incident_items` row exists with `status = quarantined` |
| resolve → Resolved | submitInspection → Awaiting Inspection; decide('confirm') → Resolved |
| a hold is disposed | an item's outcome is set, then applied on confirmation |
| `StockHold::CONDITION_*` | `IncidentItem::STATUS_*` |

**Never weaken an assertion to make it pass.** Every changed assertion carries a one-line comment saying why it changed.

- [ ] **Step 2: Full suite back to baseline**

```bash
cd back-end && php artisan test 2>&1 | grep -E "FAIL|Tests:"
```

Expected: **only** 9 `WarrantyClaimTest` + possibly `SerialRowMovementColumnsTest`.

- [ ] **Step 3: Commit**

```bash
git add back-end/tests/
git commit -F - <<'EOF'
test(incidents): update expectations for inspect-before-confirm

Filing quarantines without deducting, so the stock count is unchanged until
the admin confirms; holds became items; the strategy classes are gone. Each
changed assertion says why.
EOF
```

---

### Task 10: Frontend — items on the filing flow

**Files:**
- Create: `frontend/src/admin/components/incident/IncidentItemsStep.jsx`
- Modify: `frontend/src/admin/components/incident/IncidentFlow.jsx`, `steps/*.jsx`, `services/api.js`

The filing flow gains two things: the **items** (one row per physical unit, with an Add-unit button for indoor/outdoor pairs) and the **client-form block** (dealer, customer, site, purchase date, DOR) for `defective` and `return` only.

`IncidentItemsStep` renders a row per item: product (scan/type/pick, reusing `WhereAndWhatStep`'s field), unit role, model code, serial number, quantity (`QuantityInPieces`), batch (`BatchSelect`). "Add another unit" appends a row; the first defaults to `single` and switches to `indoor` once a second is added.

The payload gains `items: [...]`; the flat `product_id`/`quantity` stay for backward compatibility with the picking modal.

- [ ] Verify: `npx eslint` on each changed file, `npm run build`, `npm test -- --watchAll=false`.
- [ ] Commit.

---

### Task 11: Frontend — the inspection page

**Files:**
- Create: `frontend/src/admin/pages/IncidentInspectionPage.jsx`, `components/incident/EvidenceChecklist.jsx`
- Modify: `App.jsx`, `navBundles.js`, `Sidebar.jsx`, `NavBundleSeeder.php`

The inspector's screen: the report header, the items with an outcome selector each, the evidence checklist, findings, and Submit.

`EvidenceChecklist` renders one block per requirement from `REQUIRED_BY_REASON[reason]`, plus `asc_service_report` when *checked by ASC* is ticked. Each block offers **Take photo** (reuse `CameraCapture`), **Upload**, or **Skip with a reason**. Submit is blocked until every requirement is either satisfied or skipped-with-a-reason.

**Navigation takes two edits** — the bundle row *and* `adminMenuSections`. Registering only the bundle leaves the page reachable by URL and invisible in the sidebar, which has already cost a round trip on this feature.

- [ ] Verify: eslint, build, and **load the app** to confirm the sidebar entry renders.
- [ ] Commit.

---

### Task 12: Frontend — the admin decision screen, and Held Stock becomes a register

**Files:** Modify `IncidentResolveDrawer.jsx`, `StockHoldsPage.jsx` → `IncidentItemsRegisterPage.jsx`

The drawer becomes the decision screen: the inspection findings, every item with its chosen outcome, every piece of evidence, every skip with its reason, and two actions — **Confirm** and **Reject with a note**. It must state plainly that confirming is what moves the stock.

Held Stock becomes a **register**: what is quarantined, scrapped, returned or missing, filterable, read-only. The Dispose button goes — the inspection replaced it.

- [ ] Verify: eslint, build.
- [ ] Commit.

---

### Task 13: The printable incident report

**Files:** Create `frontend/src/admin/components/incident/IncidentReportPrint.jsx`

The client's document, printable **before** the admin confirms — the inspector prints it, signs, the branch manager counter-signs, and the hardcopy goes to the admin.

Layout follows their form: letterhead, the note, dealer block, Model ID / Model OD rows from `incident_items` (`unit_role` drives which is which), unit defect, problem summary with the customer block and the narrative, the Related Docs checklist showing which requirements were satisfied and which were skipped with what reason, and the two signature lines.

Follow `poExport.js` for how this codebase already generates printable documents.

- [ ] Verify: eslint, build, and print-preview one report of each type.
- [ ] Commit.

---

### Task 14: Verification

- [ ] Backend: `php artisan test` → baseline only (9 `WarrantyClaimTest` + possibly `SerialRowMovementColumnsTest`)
- [ ] Frontend: `npm test -- --watchAll=false` → 3 pre-existing suites only
- [ ] `npx eslint` on every changed file; `npm run build` clean
- [ ] **Invariant check:**

```bash
php -r '
$c = new mysqli("127.0.0.1","root","","airkingv5");
$r = $c->query("SELECT i.product_id p, i.location_id l, i.quantity_on_hand a,
  COALESCE((SELECT SUM(ii.quantity) FROM incident_items ii JOIN incident_reports ir ON ir.incident_id=ii.incident_id
   WHERE ii.product_id=i.product_id AND ir.location_id=i.location_id AND ii.status=\"quarantined\"),0) q
  FROM inventory i");
while ($x=$r->fetch_assoc()) {
  $flag = ($x["q"] > $x["a"]) ? "  <-- quarantined exceeds on-hand" : "";
  echo "  product ".$x["p"]." @ ".$x["l"].": on-hand ".$x["a"].", quarantined ".$x["q"].$flag."\n";
}'
```

Quarantined must never exceed on-hand — quarantine does not deduct, so it is a subset.

- [ ] **Manual pass**, signed in as an **inventory_analyst**:
  1. File a **defective** split-system with an indoor and an outdoor unit → both quarantined, stock count **unchanged**, both batches unpickable in a new issuance
  2. Inspect: photos on four requirements, skip one with a reason, scrap the indoor, restock the outdoor → Awaiting Inspection
  3. Print the form → the client's layout, both units listed, skip reason shown, two signature lines
  4. As an **admin**: confirm → indoor deducted, outdoor pickable again, report Complete
  5. **Reject** a different report → back to Awaiting Inspection with the note, evidence intact
  6. **Zone mismatch** → no items, no quarantine, confirmable straight from Open
  7. The **inspector cannot confirm their own report** — 403
- [ ] Report what passed, what failed, and anything skipped.

---

## Notes

- The branch is shared and already pushed. **Do not push** without asking.
- Pieces of the stock-holds work are being reshaped, not deleted — the service discipline, the six types, the cause fields and both reversals all survive.
- `ItemSerial::IN_STOCK_STATUS_ID = 26` is an existing hardcoded literal. It matches in both databases today. Do not copy the pattern; do not rely on it in new code.
