# Staged PO Receiving Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make PO receiving stage scans and counts into a reviewable server-side draft, so nothing reaches inventory until the clerk presses Submit.

**Architecture:** Two new tables (`receiving_drafts`, `receiving_draft_items`) hold staged rows whose columns mirror `ReceivingPostingService::post()`'s parameters one-for-one. A staging controller owns CRUD and fast-feedback validation but never touches stock. A submit service opens one transaction, locks the inventory rows, and replays each staged row through the existing, unmodified `post()`. Any failure rolls the whole thing back and leaves the draft intact.

**Tech Stack:** Laravel 11 (PHP 8.2), MySQL, PHPUnit with `DatabaseTransactions`; React 18 CRA frontend with react-scripts/Jest.

**Spec:** `docs/superpowers/specs/2026-09-03-staged-po-receiving-design.md`

---

## Conventions you must follow

You are working in an existing codebase with strong local conventions. Read these before Task 1.

**Primary keys are never `id`.** Every table uses `<thing>_id` (`receiving_id`, `product_id`, `po_detail_id`). Models declare `protected $primaryKey` explicitly. Follow this.

**Migrations guard themselves.** Existing receiving migrations begin with an early return when the column or table already exists, because this project's DB is sometimes ahead of its migration history. Use `Schema::hasTable()` / `Schema::hasColumn()` guards.

**Migrations carry a docblock explaining *why*, not what.** Look at `back-end/database/migrations/2026_09_02_000001_add_contents_verified_at_to_receiving_details.php` for the house style — it explains the reasoning behind a nullable column at length. Match that density.

**Controllers use the `ApiResponse` trait.** Available methods:
- `$this->success($data = null, $message = null, $code = 200)`
- `$this->error($message = null, $code = 400, $errors = null)`
- `$this->validationError($errors)`

**Business rejections return `success()` with `action => 'rejected'`, not an HTTP error.** See `ReceiveCountController` — the frontend switches on `data.action`. Only genuine faults use `error()`.

**Tests use `DatabaseTransactions`, not `RefreshDatabase`.** Build fixtures inline with `Model::create()`; there are no factories for these models. Copy the `makeUser()` / `makeLocation()` helpers from `back-end/tests/Feature/Inventory/ReceiveCountTest.php`.

**Test commands:**
- Backend, one test: `cd back-end && php artisan test --filter=test_name`
- Backend, one file: `cd back-end && php artisan test tests/Feature/Inventory/FileName.php`
- Frontend: `cd frontend && npx react-scripts test --watchAll=false --testPathPattern=PathFragment`

**PO status IDs:** 8 = Approved, 10 = Authorized, 12 = Partially Received. These three are receivable.

---

## File Structure

**Create — backend:**

| File | Responsibility |
|---|---|
| `back-end/database/migrations/2026_09_03_000001_create_receiving_drafts_table.php` | The draft header table |
| `back-end/database/migrations/2026_09_03_000002_create_receiving_draft_items_table.php` | The staged item table |
| `back-end/app/Models/ReceivingDraft.php` | Header model + relations + `openFor()` lookup |
| `back-end/app/Models/ReceivingDraftItem.php` | Item model + relations |
| `back-end/app/Services/ReceivingDraftStagingService.php` | Stage-time validation. Answers "may this row be staged?" — no writes to stock, ever |
| `back-end/app/Services/ReceivingDraftSubmitService.php` | The replay. The only new code that moves stock |
| `back-end/app/Http/Controllers/Api/ReceivingDraftController.php` | Draft + item CRUD (open, show, stage, adjust, remove, discard) |
| `back-end/app/Http/Controllers/Api/ReceivingDraftSubmitController.php` | Single-action controller wrapping the submit service |

**Create — tests:**

| File | Covers |
|---|---|
| `back-end/tests/Feature/Inventory/ReceivingDraftStagingTest.php` | Staging moves nothing; open/resume; remove; adjust |
| `back-end/tests/Feature/Inventory/ReceivingDraftValidationTest.php` | Duplicate serials, non-PO product, serialized-without-barcode, overage warns but does not block |
| `back-end/tests/Feature/Inventory/ReceivingDraftSubmitTest.php` | Submit posts identically to a scan; the TCL case; total rollback; duplicate-serial race |

**Modify:**

| File | Change |
|---|---|
| `back-end/routes/api.php` | Seven routes into the `permission:barcode_inventory_processing,create` group |
| `frontend/src/admin/services/api.js` | `receivingDraftsAPI` client |
| `frontend/src/admin/pages/BarcodeScan.jsx` | `receive-po` mode stages instead of posting; staged list with remove/adjust; Submit button; dual progress |

**Deliberately untouched:** `ReceivingPostingService`, `InventoryScanTransactionController`, `ReceiveCountController`, `ReceiveVerifyController`, `ReceiveTopUpController`, `CountEntryPanel.jsx`.

Splitting staging validation from submit into two services is the load-bearing decision here. It is what lets Task 4's tests prove "staging cannot move stock" structurally rather than by inspection.

---

## Task 1: Draft tables

**Files:**
- Create: `back-end/database/migrations/2026_09_03_000001_create_receiving_drafts_table.php`
- Create: `back-end/database/migrations/2026_09_03_000002_create_receiving_draft_items_table.php`

- [ ] **Step 1: Write the header migration**

Create `back-end/database/migrations/2026_09_03_000001_create_receiving_drafts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One delivery being staged before it is posted to inventory.
 *
 * Receiving used to post on every scan, and nothing could undo one: a clerk who
 * scanned a TCL aircon while the wrong PO line was selected had received an
 * aircon as bulk material, permanently. A draft is the review step that was
 * missing — goods are described here first, and only Submit moves stock.
 *
 * The draft lives in the database rather than in browser state on purpose. A
 * half-counted truckload must survive a closed tab, a dead battery, and a shift
 * change, and a second clerk on the same PO must be able to see that a delivery
 * is already being staged rather than starting a rival list.
 *
 * `status` is what makes "one draft per PO+warehouse" true only of OPEN drafts.
 * Submitted and discarded rows are kept as history: a PO accumulates one draft
 * per delivery over its life, and the receipt each produced is reachable through
 * `receiving_id`.
 *
 * `zone_label` sits on the header, not the item, because a delivery is put away
 * in one zone. Every item inherits it when the row is posted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('receiving_drafts')) {
            return;
        }

        Schema::create('receiving_drafts', function (Blueprint $table) {
            $table->bigIncrements('draft_id');
            $table->unsignedBigInteger('po_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('opened_by')->nullable();
            $table->unsignedBigInteger('receiving_id')->nullable();
            $table->enum('status', ['open', 'submitted', 'discarded'])->default('open');
            $table->string('zone_label', 100)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('discarded_at')->nullable();
            $table->timestamps();

            // The hot query is "is there an open draft for this PO at my
            // warehouse?", run every time a clerk enters receive-po mode.
            $table->index(['po_id', 'location_id', 'status'], 'receiving_drafts_open_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receiving_drafts');
    }
};
```

- [ ] **Step 2: Write the items migration**

Create `back-end/database/migrations/2026_09_03_000002_create_receiving_draft_items_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One staged scan or one staged count, waiting to be posted.
 *
 * These columns are a deliberate one-for-one mirror of the parameters
 * ReceivingPostingService::post() already takes. That is the whole design:
 * submitting a draft is a loop that reads a row and spreads it into the
 * existing call, with no translation layer in between to get wrong. Adding a
 * column here that post() does not accept means the mirror has broken and the
 * submit path has grown logic it was never supposed to have.
 *
 * quantity_amount is pieces, matching receiving_details — never packages.
 * packages_received and pieces_expected stay NULL for appliance lines and for
 * piece-counted materials, which have no package to reason about, exactly as
 * they do on receiving_details.
 *
 * contents_verified is a plain boolean here rather than the timestamp used on
 * receiving_details, because a staged row has not been verified AT any moment
 * yet — it only carries the clerk's claim forward. post() converts it to the
 * timestamp when the row is finally posted, so the receipt still records when
 * verification actually took effect.
 *
 * No unique index on scanned_barcode. MySQL has no partial index, so "unique
 * among open drafts only" is not expressible, and a plain unique key would
 * wrongly refuse to re-receive a code whose draft was discarded. Duplicates are
 * caught by ReceivingDraftStagingService at stage time for fast feedback, and
 * the global item_serial.serial_number unique key is the real guarantee at
 * submit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('receiving_draft_items')) {
            return;
        }

        Schema::create('receiving_draft_items', function (Blueprint $table) {
            $table->bigIncrements('draft_item_id');
            $table->unsignedBigInteger('draft_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('po_detail_id')->nullable();
            $table->enum('entry_mode', ['scanned', 'counted'])->default('scanned');
            $table->string('scanned_barcode', 191)->nullable();
            $table->integer('quantity_amount')->default(0);
            $table->integer('packages_received')->nullable();
            $table->integer('pieces_expected')->nullable();
            $table->boolean('contents_verified')->default(false);
            $table->integer('units_entered')->default(1);
            $table->timestamps();

            $table->index('draft_id');
            $table->index('scanned_barcode');

            // A draft's items are meaningless without their header, so they go
            // with it. Discarding a draft is a real delete, not a tombstone —
            // nothing downstream has ever read these rows, because nothing
            // downstream sees a draft until it becomes receiving_details.
            $table->foreign('draft_id')
                  ->references('draft_id')->on('receiving_drafts')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receiving_draft_items');
    }
};
```

- [ ] **Step 3: Run the migrations**

```bash
cd back-end && php artisan migrate
```

Expected: two lines reporting `create_receiving_drafts_table` and `create_receiving_draft_items_table` as `DONE`.

- [ ] **Step 4: Verify the tables exist with the right shape**

```bash
cd back-end && php artisan tinker --execute="print_r(Schema::getColumnListing('receiving_drafts')); print_r(Schema::getColumnListing('receiving_draft_items'));"
```

Expected: the first array contains `draft_id, po_id, location_id, opened_by, receiving_id, status, zone_label, submitted_at, discarded_at, created_at, updated_at`. The second contains `draft_item_id, draft_id, product_id, po_detail_id, entry_mode, scanned_barcode, quantity_amount, packages_received, pieces_expected, contents_verified, units_entered, created_at, updated_at`.

- [ ] **Step 5: Commit**

```bash
git add back-end/database/migrations/2026_09_03_000001_create_receiving_drafts_table.php back-end/database/migrations/2026_09_03_000002_create_receiving_draft_items_table.php
git commit -m "feat(receiving): add tables for a delivery staged before posting"
```

---

## Task 2: Draft models

**Files:**
- Create: `back-end/app/Models/ReceivingDraft.php`
- Create: `back-end/app/Models/ReceivingDraftItem.php`
- Test: `back-end/tests/Feature/Inventory/ReceivingDraftStagingTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/ReceivingDraftStagingTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDraft;
use App\Models\ReceivingDraftItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReceivingDraftStagingTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    private function makeLocation(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
    }

    /** A package-counted consumable: 1 box = 50 pieces. */
    private function makeConsumable(int $piecesPerPackage = 50, string $countUnit = 'package'): Product
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Pipe',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => $piecesPerPackage,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => $countUnit,
        ]);

        return $product->fresh();
    }

    private function makePoLine(Product $product, Location $location, int $quantityOrdered = 10): PurchaseOrderDetail
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);

        return PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => $quantityOrdered,
            'unit_price' => 5, 'subtotal' => 5 * $quantityOrdered,
        ]);
    }

    public function test_open_draft_lookup_finds_only_open_drafts_for_that_po_and_location(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable();
        $poDetail = $this->makePoLine($product, $location);
        $poId     = (int) $poDetail->po_id;

        $open = ReceivingDraft::create([
            'po_id'       => $poId,
            'location_id' => $location->location_id,
            'opened_by'   => $user->user_id,
            'status'      => 'open',
        ]);

        $found = ReceivingDraft::openFor($poId, (int) $location->location_id);
        $this->assertNotNull($found);
        $this->assertSame((int) $open->draft_id, (int) $found->draft_id);

        // A submitted draft must not be resumed — that delivery is done.
        $open->update(['status' => 'submitted']);
        $this->assertNull(ReceivingDraft::openFor($poId, (int) $location->location_id));
    }

    public function test_draft_items_relate_to_their_draft_and_cascade_on_delete(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable();
        $poDetail = $this->makePoLine($product, $location);

        $draft = ReceivingDraft::create([
            'po_id'       => $poDetail->po_id,
            'location_id' => $location->location_id,
            'opened_by'   => $user->user_id,
            'status'      => 'open',
        ]);

        ReceivingDraftItem::create([
            'draft_id'          => $draft->draft_id,
            'product_id'        => $product->product_id,
            'po_detail_id'      => $poDetail->po_detail_id,
            'entry_mode'        => 'scanned',
            'scanned_barcode'   => 'BOX-' . uniqid(),
            'quantity_amount'   => 50,
            'packages_received' => 1,
            'pieces_expected'   => 50,
            'contents_verified' => false,
            'units_entered'     => 1,
        ]);

        $this->assertSame(1, $draft->fresh()->items()->count());
        $this->assertSame(50, (int) $draft->fresh()->stagedPieces());

        $draftId = (int) $draft->draft_id;
        $draft->delete();

        $this->assertSame(0, ReceivingDraftItem::where('draft_id', $draftId)->count());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test tests/Feature/Inventory/ReceivingDraftStagingTest.php
```

Expected: FAIL with `Class "App\Models\ReceivingDraft" not found`.

- [ ] **Step 3: Write the header model**

Create `back-end/app/Models/ReceivingDraft.php`:

```php
<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ReceivingDraft
 * Table: receiving_drafts
 *
 * One delivery being staged. Holds no stock and appears nowhere in inventory
 * until ReceivingDraftSubmitService replays its items through
 * ReceivingPostingService::post().
 */
class ReceivingDraft extends Model
{
    use Auditable;

    protected $table = 'receiving_drafts';
    protected $primaryKey = 'draft_id';

    protected $fillable = [
        'po_id',
        'location_id',
        'opened_by',
        'receiving_id',
        'status',
        'zone_label',
        'submitted_at',
        'discarded_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'discarded_at' => 'datetime',
    ];

    /**
     * The draft a clerk should resume for this PO at this warehouse, or null.
     *
     * Scoped to `open` deliberately. A submitted draft describes a delivery
     * that already became a receipt; resuming it would stage goods against a
     * closed event and post them a second time.
     */
    public static function openFor(int $poId, int $locationId): ?self
    {
        return static::where('po_id', $poId)
            ->where('location_id', $locationId)
            ->where('status', 'open')
            ->with('items.product')
            ->first();
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReceivingDraftItem::class, 'draft_id', 'draft_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by', 'user_id');
    }

    /** The receipt this draft produced, once submitted. Null while open. */
    public function receiving(): BelongsTo
    {
        return $this->belongsTo(Receiving::class, 'receiving_id', 'receiving_id');
    }

    /** Total pieces waiting to be posted. */
    public function stagedPieces(): int
    {
        return (int) $this->items()->sum('quantity_amount');
    }

    /** Pieces staged against one PO line, for the dual progress display. */
    public function stagedPiecesForLine(int $poDetailId): int
    {
        return (int) $this->items()->where('po_detail_id', $poDetailId)->sum('quantity_amount');
    }

    /** Packages staged against one PO line. NULL rows contribute nothing. */
    public function stagedPackagesForLine(int $poDetailId): int
    {
        return (int) $this->items()
            ->where('po_detail_id', $poDetailId)
            ->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(packages_received, 0)'));
    }
}
```

- [ ] **Step 4: Write the item model**

Create `back-end/app/Models/ReceivingDraftItem.php`:

```php
<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ReceivingDraftItem
 * Table: receiving_draft_items
 *
 * One staged scan or count. Its columns mirror the parameters of
 * ReceivingPostingService::post() one-for-one so that submitting is a spread,
 * not a translation.
 */
class ReceivingDraftItem extends Model
{
    use Auditable;

    protected $table = 'receiving_draft_items';
    protected $primaryKey = 'draft_item_id';

    protected $fillable = [
        'draft_id',
        'product_id',
        'po_detail_id',
        'entry_mode',
        'scanned_barcode',
        'quantity_amount',
        'packages_received',
        'pieces_expected',
        'contents_verified',
        'units_entered',
    ];

    protected $casts = [
        'quantity_amount'   => 'integer',
        'packages_received' => 'integer',
        'pieces_expected'   => 'integer',
        'units_entered'     => 'integer',
        'contents_verified' => 'boolean',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ReceivingDraft::class, 'draft_id', 'draft_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function purchaseOrderDetail(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderDetail::class, 'po_detail_id', 'po_detail_id');
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
cd back-end && php artisan test tests/Feature/Inventory/ReceivingDraftStagingTest.php
```

Expected: PASS, 2 tests.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Models/ReceivingDraft.php back-end/app/Models/ReceivingDraftItem.php back-end/tests/Feature/Inventory/ReceivingDraftStagingTest.php
git commit -m "feat(receiving): model a staged delivery and its rows"
```

---

## Task 3: Staging validation service

This service answers one question — may this row be staged? — and it must never write to inventory, batches, `receiving_details`, or `item_serial`.

The overage rule is the subtle one. It **warns and allows**. Blocking an overage at stage time would defeat the whole feature: the clerk has to be able to stage what is physically on the floor, see that it is three boxes over, and then decide. Staging describes reality; submit judges it.

**Files:**
- Create: `back-end/app/Services/ReceivingDraftStagingService.php`
- Test: `back-end/tests/Feature/Inventory/ReceivingDraftValidationTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/ReceivingDraftValidationTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\ApplianceDetail;
use App\Models\ConsumableDetail;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDraft;
use App\Models\ReceivingDraftItem;
use App\Models\User;
use App\Services\ReceivingDraftStagingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReceivingDraftValidationTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    private function makeLocation(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
    }

    private function makeConsumable(int $piecesPerPackage = 50): Product
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Pipe',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => $piecesPerPackage,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);

        return $product->fresh();
    }

    private function makeAppliance(): Product
    {
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'TCL Aircon 1.5HP',
            'product_type' => 'appliance',
        ]);
        ApplianceDetail::create([
            'product_id' => $product->product_id, 'is_serialized' => 1,
        ]);

        return $product->fresh();
    }

    private function makePo(Location $location): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
    }

    private function makeLine(PurchaseOrder $po, Product $product, Location $location, int $ordered): PurchaseOrderDetail
    {
        return PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => $ordered,
            'unit_price' => 5, 'subtotal' => 5 * $ordered,
        ]);
    }

    private function makeDraft(PurchaseOrder $po, Location $location, User $user): ReceivingDraft
    {
        return ReceivingDraft::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'opened_by' => $user->user_id, 'status' => 'open',
        ]);
    }

    public function test_a_barcode_already_staged_in_an_open_draft_is_refused(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable();
        $po       = $this->makePo($location);
        $line     = $this->makeLine($po, $product, $location, 10);
        $draft    = $this->makeDraft($po, $location, $user);

        ReceivingDraftItem::create([
            'draft_id' => $draft->draft_id, 'product_id' => $product->product_id,
            'po_detail_id' => $line->po_detail_id, 'entry_mode' => 'scanned',
            'scanned_barcode' => 'DUPE-001', 'quantity_amount' => 50,
            'packages_received' => 1, 'pieces_expected' => 50,
            'contents_verified' => false, 'units_entered' => 1,
        ]);

        $service = app(ReceivingDraftStagingService::class);
        $result  = $service->check($draft->fresh(), $line, $product, 50, 1, 'DUPE-001');

        $this->assertSame('rejected', $result['action']);
        $this->assertStringContainsString('already', strtolower($result['reason']));
    }

    public function test_a_barcode_already_in_item_serial_is_refused(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $po       = $this->makePo($location);
        $line     = $this->makeLine($po, $product, $location, 5);
        $draft    = $this->makeDraft($po, $location, $user);

        ItemSerial::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'serial_number' => 'TCL-SEEN-001', 'serial_type' => 'received',
            'status_id' => ItemSerial::IN_STOCK_STATUS_ID,
        ]);

        $service = app(ReceivingDraftStagingService::class);
        $result  = $service->check($draft->fresh(), $line, $product, 1, null, 'TCL-SEEN-001');

        $this->assertSame('rejected', $result['action']);
    }

    public function test_a_serialized_product_without_a_barcode_is_refused(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $po       = $this->makePo($location);
        $line     = $this->makeLine($po, $product, $location, 5);
        $draft    = $this->makeDraft($po, $location, $user);

        $service = app(ReceivingDraftStagingService::class);
        $result  = $service->check($draft->fresh(), $line, $product, 1, null, null);

        $this->assertSame('rejected', $result['action']);
        $this->assertStringContainsString('scanned', strtolower($result['reason']));
    }

    public function test_a_line_belonging_to_another_po_is_refused(): void
    {
        $user      = $this->makeUser();
        $location  = $this->makeLocation();
        $product   = $this->makeConsumable();
        $poA       = $this->makePo($location);
        $poB       = $this->makePo($location);
        $lineOnB   = $this->makeLine($poB, $product, $location, 10);
        $draftOnA  = $this->makeDraft($poA, $location, $user);

        $service = app(ReceivingDraftStagingService::class);
        $result  = $service->check($draftOnA, $lineOnB, $product, 50, 1, null);

        $this->assertSame('rejected', $result['action']);
        $this->assertStringContainsString('purchase order', strtolower($result['reason']));
    }

    public function test_an_overage_warns_but_does_not_block_staging(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable(50);
        $po       = $this->makePo($location);
        // Only 1 box ordered; the clerk is staging a second.
        $line     = $this->makeLine($po, $product, $location, 1);
        $draft    = $this->makeDraft($po, $location, $user);

        ReceivingDraftItem::create([
            'draft_id' => $draft->draft_id, 'product_id' => $product->product_id,
            'po_detail_id' => $line->po_detail_id, 'entry_mode' => 'scanned',
            'scanned_barcode' => 'BOX-1', 'quantity_amount' => 50,
            'packages_received' => 1, 'pieces_expected' => 50,
            'contents_verified' => false, 'units_entered' => 1,
        ]);

        $service = app(ReceivingDraftStagingService::class);
        $result  = $service->check($draft->fresh(), $line, $product, 50, 1, 'BOX-2');

        $this->assertSame('allowed', $result['action'], 'Staging must never block an overage — the clerk needs to describe what is on the floor.');
        $this->assertNotNull($result['warning']);
        $this->assertSame('over_receipt', $result['warning']['action']);
    }

    public function test_validation_never_touches_stock(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable();
        $po       = $this->makePo($location);
        $line     = $this->makeLine($po, $product, $location, 10);
        $draft    = $this->makeDraft($po, $location, $user);

        $serialsBefore = ItemSerial::count();
        $batchesBefore = InventoryBatch::count();

        $service = app(ReceivingDraftStagingService::class);
        $service->check($draft, $line, $product, 50, 1, 'BOX-X');

        $this->assertSame($serialsBefore, ItemSerial::count());
        $this->assertSame($batchesBefore, InventoryBatch::count());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test tests/Feature/Inventory/ReceivingDraftValidationTest.php
```

Expected: FAIL with `Target class [App\Services\ReceivingDraftStagingService] does not exist.`

- [ ] **Step 3: Write the staging service**

Create `back-end/app/Services/ReceivingDraftStagingService.php`:

```php
<?php

namespace App\Services;

use App\Models\ItemSerial;
use App\Models\Product;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDraft;
use App\Models\ReceivingDraftItem;

/**
 * Decides whether a row may be staged into a draft.
 *
 * This service exists to give the clerk instant feedback, NOT to protect the
 * data — every rule enforced here is enforced again, authoritatively, at submit.
 * That separation is deliberate: it means this class never needs to touch
 * inventory, batches, receiving_details or item_serial, and so a bug in it
 * cannot corrupt stock. If you find yourself wanting to write from here, the
 * logic belongs in ReceivingDraftSubmitService instead.
 */
class ReceivingDraftStagingService
{
    public function __construct(private ReceivingPostingService $posting)
    {
    }

    /**
     * @param  ?int  $packages  Packages this row claims, or null for an
     *         appliance or a piece-counted line, which have no package concept.
     * @return array{action: 'allowed'|'rejected', reason?: string, warning?: ?array}
     *         'rejected' means the row must not be staged. 'allowed' may still
     *         carry a `warning` the clerk should see but must be free to ignore.
     */
    public function check(
        ReceivingDraft $draft,
        PurchaseOrderDetail $poDetail,
        Product $product,
        int $pieces,
        ?int $packages,
        ?string $barcode
    ): array {
        // The line must belong to the PO this draft is receiving. Without this
        // a mis-wired client could stage another order's goods into this
        // delivery and they would post on submit as if they had been ordered.
        if ((int) $poDetail->po_id !== (int) $draft->po_id) {
            return [
                'action' => 'rejected',
                'reason' => 'That item is not on this purchase order.',
            ];
        }

        if ((int) $poDetail->product_id !== (int) $product->product_id) {
            return [
                'action' => 'rejected',
                'reason' => 'That product does not match the selected line.',
            ];
        }

        // An appliance must never enter stock without a serial. post() throws
        // on this as its last line of defence; catching it here turns a 500
        // into a clerk-readable message before anything is staged.
        if ($product->isSerialized() && ! $barcode) {
            return [
                'action' => 'rejected',
                'reason' => 'Appliances must be scanned individually.',
            ];
        }

        if ($barcode) {
            $duplicate = $this->findDuplicate($barcode);
            if ($duplicate !== null) {
                return ['action' => 'rejected', 'reason' => $duplicate];
            }
        }

        // Overage WARNS, it does not block. The clerk must be able to stage
        // what is physically on the floor and then decide; refusing the row
        // here would leave them with a box they cannot describe.
        //
        // The warning accounts for what is already staged in this draft as well
        // as what is already posted, because checkOverage only knows about
        // posted rows and the draft has not posted anything yet.
        $stagedPieces   = $draft->stagedPiecesForLine((int) $poDetail->po_detail_id);
        $stagedPackages = $draft->stagedPackagesForLine((int) $poDetail->po_detail_id);

        $warning = $this->posting->checkOverage(
            $poDetail,
            $product,
            $pieces + $stagedPieces,
            $packages === null ? null : $packages + $stagedPackages
        );

        return ['action' => 'allowed', 'warning' => $warning];
    }

    /**
     * A human-readable reason this barcode cannot be used again, or null.
     *
     * Every barcode is a one-time-use identifier. Two places can already hold
     * it: a posted receipt (item_serial) or another clerk's open draft.
     */
    private function findDuplicate(string $barcode): ?string
    {
        $normalized = trim($barcode);

        if (ItemSerial::where('serial_number', $normalized)->exists()) {
            return "Barcode \"{$normalized}\" has already been recorded as received. "
                . 'Each barcode is a one-time-use identifier — check the label.';
        }

        $stagedElsewhere = ReceivingDraftItem::where('scanned_barcode', $normalized)
            ->whereHas('draft', fn ($q) => $q->where('status', 'open'))
            ->exists();

        if ($stagedElsewhere) {
            return "Barcode \"{$normalized}\" is already staged in an unsubmitted delivery. "
                . 'Each barcode is a one-time-use identifier — check the label.';
        }

        return null;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd back-end && php artisan test tests/Feature/Inventory/ReceivingDraftValidationTest.php
```

Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/ReceivingDraftStagingService.php back-end/tests/Feature/Inventory/ReceivingDraftValidationTest.php
git commit -m "feat(receiving): validate a staged row without touching stock"
```

---

## Task 4: Submit service — the replay

This is the only new code that moves stock. It opens one transaction, locks the inventory rows, and replays each staged item through the **unmodified** `ReceivingPostingService::post()`.

Two details matter and are easy to get wrong:

1. **Collect every failure before rolling back**, not just the first. A clerk fixing a truckload one submit at a time is exactly the frustration this feature exists to remove.
2. **`checkOverage` sums live `receiving_details`**, so once row 1 posts, row 2's check sees it automatically. Do not add your own running tally — you would double-count.

**Files:**
- Create: `back-end/app/Services/ReceivingDraftSubmitService.php`
- Test: `back-end/tests/Feature/Inventory/ReceivingDraftSubmitTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/ReceivingDraftSubmitTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\ApplianceDetail;
use App\Models\ConsumableDetail;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDetail;
use App\Models\ReceivingDraft;
use App\Models\ReceivingDraftItem;
use App\Models\User;
use App\Services\ReceivingDraftSubmitService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReceivingDraftSubmitTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    private function makeLocation(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
    }

    private function makeConsumable(int $piecesPerPackage = 50): Product
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Pipe',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => $piecesPerPackage,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);

        return $product->fresh();
    }

    private function makeAppliance(): Product
    {
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'TCL Aircon 1.5HP',
            'product_type' => 'appliance',
        ]);
        ApplianceDetail::create(['product_id' => $product->product_id, 'is_serialized' => 1]);

        return $product->fresh();
    }

    private function makePo(Location $location): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
    }

    private function makeLine(PurchaseOrder $po, Product $product, Location $location, int $ordered): PurchaseOrderDetail
    {
        return PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => $ordered,
            'unit_price' => 5, 'subtotal' => 5 * $ordered,
        ]);
    }

    private function makeDraft(PurchaseOrder $po, Location $location, User $user, ?string $zone = null): ReceivingDraft
    {
        return ReceivingDraft::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'opened_by' => $user->user_id, 'status' => 'open', 'zone_label' => $zone,
        ]);
    }

    private function stageBox(ReceivingDraft $draft, Product $product, PurchaseOrderDetail $line, string $barcode, int $pieces = 50): ReceivingDraftItem
    {
        return ReceivingDraftItem::create([
            'draft_id' => $draft->draft_id, 'product_id' => $product->product_id,
            'po_detail_id' => $line->po_detail_id, 'entry_mode' => 'scanned',
            'scanned_barcode' => $barcode, 'quantity_amount' => $pieces,
            'packages_received' => 1, 'pieces_expected' => 50,
            'contents_verified' => false, 'units_entered' => 1,
        ]);
    }

    public function test_staging_alone_moves_no_stock(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable();
        $po       = $this->makePo($location);
        $line     = $this->makeLine($po, $product, $location, 10);
        $draft    = $this->makeDraft($po, $location, $user);

        foreach (['B1', 'B2', 'B3', 'B4', 'B5'] as $code) {
            $this->stageBox($draft, $product, $line, $code);
        }

        // The central claim of this feature, asserted as bluntly as possible.
        $this->assertNull(
            Inventory::where('location_id', $location->location_id)
                ->where('product_id', $product->product_id)->first()
        );
        $this->assertSame(0, InventoryBatch::where('product_id', $product->product_id)->count());
        $this->assertSame(0, ReceivingDetail::where('po_detail_id', $line->po_detail_id)->count());
        $this->assertSame(0, ItemSerial::where('product_id', $product->product_id)->count());
    }

    public function test_submitting_posts_every_staged_row(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable(50);
        $po       = $this->makePo($location);
        $line     = $this->makeLine($po, $product, $location, 10);
        $draft    = $this->makeDraft($po, $location, $user, 'A-03');

        foreach (['B1', 'B2', 'B3'] as $code) {
            $this->stageBox($draft, $product, $line, $code);
        }

        $result = app(ReceivingDraftSubmitService::class)->submit($draft->fresh(), (int) $user->user_id, false);

        $this->assertSame('submitted', $result['action']);

        $inventory = Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)->first();
        $this->assertNotNull($inventory);
        $this->assertSame(150, (int) $inventory->quantity_on_hand);

        $this->assertSame(3, ReceivingDetail::where('po_detail_id', $line->po_detail_id)->count());
        $this->assertSame(
            150,
            (int) ReceivingDetail::where('po_detail_id', $line->po_detail_id)->sum('quantity_amount')
        );

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)->first();
        $this->assertNotNull($batch);
        $this->assertSame('A-03', $batch->zone_label, 'Items must inherit the header zone label.');

        $draft->refresh();
        $this->assertSame('submitted', $draft->status);
        $this->assertNotNull($draft->receiving_id);
        $this->assertNotNull($draft->submitted_at);
    }

    public function test_a_wrong_line_removed_before_submit_never_reaches_inventory(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $bulk     = $this->makeConsumable(50);
        $aircon   = $this->makeAppliance();
        $po       = $this->makePo($location);
        $bulkLine   = $this->makeLine($po, $bulk, $location, 10);
        $airconLine = $this->makeLine($po, $aircon, $location, 5);
        $draft      = $this->makeDraft($po, $location, $user);

        // The mistake: a TCL aircon staged against the bulk material line.
        $wrong = ReceivingDraftItem::create([
            'draft_id' => $draft->draft_id, 'product_id' => $bulk->product_id,
            'po_detail_id' => $bulkLine->po_detail_id, 'entry_mode' => 'scanned',
            'scanned_barcode' => 'TCL-889', 'quantity_amount' => 50,
            'packages_received' => 1, 'pieces_expected' => 50,
            'contents_verified' => false, 'units_entered' => 1,
        ]);

        // The clerk spots it on the review list and removes it.
        $wrong->delete();

        // Re-staged against the right line, as an appliance.
        ReceivingDraftItem::create([
            'draft_id' => $draft->draft_id, 'product_id' => $aircon->product_id,
            'po_detail_id' => $airconLine->po_detail_id, 'entry_mode' => 'scanned',
            'scanned_barcode' => 'TCL-889', 'quantity_amount' => 1,
            'packages_received' => null, 'pieces_expected' => null,
            'contents_verified' => false, 'units_entered' => 1,
        ]);

        $result = app(ReceivingDraftSubmitService::class)->submit($draft->fresh(), (int) $user->user_id, false);
        $this->assertSame('submitted', $result['action']);

        // No bulk stock exists at all.
        $this->assertNull(
            Inventory::where('location_id', $location->location_id)
                ->where('product_id', $bulk->product_id)->first()
        );
        $this->assertSame(0, ReceivingDetail::where('po_detail_id', $bulkLine->po_detail_id)->count());

        // The aircon is in stock, with its own serial kept verbatim.
        $serial = ItemSerial::where('serial_number', 'TCL-889')->first();
        $this->assertNotNull($serial);
        $this->assertSame((int) $aircon->product_id, (int) $serial->product_id);
    }

    public function test_one_bad_row_rolls_the_whole_submit_back_and_keeps_the_draft(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable(50);
        $po       = $this->makePo($location);
        // 5 boxes ordered; the draft stages 6.
        $line     = $this->makeLine($po, $product, $location, 5);
        $draft    = $this->makeDraft($po, $location, $user);

        foreach (['B1', 'B2', 'B3', 'B4', 'B5', 'B6'] as $code) {
            $this->stageBox($draft, $product, $line, $code);
        }

        $result = app(ReceivingDraftSubmitService::class)->submit($draft->fresh(), (int) $user->user_id, false);

        $this->assertSame('failed', $result['action']);
        $this->assertNotEmpty($result['failures']);

        // Nothing posted.
        $this->assertNull(
            Inventory::where('location_id', $location->location_id)
                ->where('product_id', $product->product_id)->first()
        );
        $this->assertSame(0, ReceivingDetail::where('po_detail_id', $line->po_detail_id)->count());

        // The draft survives intact, all six rows still there to be fixed.
        $draft->refresh();
        $this->assertSame('open', $draft->status);
        $this->assertNull($draft->receiving_id);
        $this->assertSame(6, $draft->items()->count());
    }

    public function test_accepting_the_overage_lets_the_same_draft_through(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable(50);
        $po       = $this->makePo($location);
        $line     = $this->makeLine($po, $product, $location, 5);
        $draft    = $this->makeDraft($po, $location, $user);

        foreach (['B1', 'B2', 'B3', 'B4', 'B5', 'B6'] as $code) {
            $this->stageBox($draft, $product, $line, $code);
        }

        $result = app(ReceivingDraftSubmitService::class)->submit($draft->fresh(), (int) $user->user_id, true);

        $this->assertSame('submitted', $result['action']);
        $this->assertSame(
            300,
            (int) ReceivingDetail::where('po_detail_id', $line->po_detail_id)->sum('quantity_amount')
        );
    }

    public function test_a_serial_taken_between_staging_and_submit_fails_the_whole_submit(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $aircon   = $this->makeAppliance();
        $po       = $this->makePo($location);
        $line     = $this->makeLine($po, $aircon, $location, 5);
        $draft    = $this->makeDraft($po, $location, $user);

        ReceivingDraftItem::create([
            'draft_id' => $draft->draft_id, 'product_id' => $aircon->product_id,
            'po_detail_id' => $line->po_detail_id, 'entry_mode' => 'scanned',
            'scanned_barcode' => 'RACE-001', 'quantity_amount' => 1,
            'packages_received' => null, 'pieces_expected' => null,
            'contents_verified' => false, 'units_entered' => 1,
        ]);

        // Another clerk's draft submits first and claims the serial.
        ItemSerial::create([
            'product_id' => $aircon->product_id, 'location_id' => $location->location_id,
            'serial_number' => 'RACE-001', 'serial_type' => 'received',
            'status_id' => ItemSerial::IN_STOCK_STATUS_ID,
        ]);

        $result = app(ReceivingDraftSubmitService::class)->submit($draft->fresh(), (int) $user->user_id, false);

        $this->assertSame('failed', $result['action']);
        $this->assertSame(0, ReceivingDetail::where('po_detail_id', $line->po_detail_id)->count());
        $this->assertSame('open', $draft->fresh()->status);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test tests/Feature/Inventory/ReceivingDraftSubmitTest.php
```

Expected: the first test (`test_staging_alone_moves_no_stock`) PASSES — staging really is inert already — and the remaining five FAIL with `Target class [App\Services\ReceivingDraftSubmitService] does not exist.`

- [ ] **Step 3: Write the submit service**

Create `back-end/app/Services/ReceivingDraftSubmitService.php`:

```php
<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDraft;
use App\Models\ReceivingDraftItem;
use Illuminate\Support\Facades\DB;

/**
 * Posts a staged delivery to inventory.
 *
 * This is the only new code in the staged-receiving feature that moves stock,
 * and it moves it by replaying each staged row through the pre-existing
 * ReceivingPostingService::post() — the same call the scan endpoint has always
 * made. Nothing about how stock is posted changes; only *when* it happens does.
 *
 * ALL-OR-NOTHING. One transaction wraps the whole draft. A single bad row means
 * nothing posts and the draft survives untouched, because a half-posted truck
 * cannot be reconciled against a delivery note.
 */
class ReceivingDraftSubmitService
{
    public function __construct(
        private ReceivingPostingService $posting,
        private PoReceivingStatusService $poStatus
    ) {
    }

    /**
     * @param  bool  $acceptOverage  Receiving more than was ordered is a real
     *         thing that happens; this is how an authorized user accepts it.
     *         Unlike the old per-scan confirm, the clerk sets this on a
     *         deliberate re-submit after seeing the full list of what is over.
     * @return array{action: 'submitted'|'failed', ...}
     */
    public function submit(ReceivingDraft $draft, ?int $userId, bool $acceptOverage = false): array
    {
        if ($draft->status !== 'open') {
            return [
                'action'   => 'failed',
                'failures' => [],
                'message'  => 'This delivery has already been submitted.',
            ];
        }

        $items = $draft->items()->with(['product.applianceDetail', 'product.consumableDetail'])->get();

        if ($items->isEmpty()) {
            return [
                'action'   => 'failed',
                'failures' => [],
                'message'  => 'There is nothing staged to submit.',
            ];
        }

        DB::beginTransaction();
        try {
            // Lock every inventory row this draft will touch, up front, per
            // ReceivingPostingService's documented transaction contract. Locking
            // the distinct set before posting anything also avoids two
            // concurrent submits deadlocking by grabbing the same rows in
            // different orders.
            $productIds = $items->pluck('product_id')->unique()->sort()->values();
            foreach ($productIds as $productId) {
                Inventory::where('location_id', $draft->location_id)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();
            }

            $failures = [];
            $posted   = [];
            $tags     = [];
            $receiving = null;

            foreach ($items as $item) {
                $outcome = $this->postItem($draft, $item, $userId, $acceptOverage);

                if (isset($outcome['failure'])) {
                    $failures[] = $outcome['failure'];
                    // Keep going rather than bailing on the first problem. The
                    // clerk gets one complete list to fix instead of
                    // discovering faults one submit at a time.
                    continue;
                }

                $receiving = $outcome['receiving'];
                $posted[]  = (int) $item->draft_item_id;
                $tags      = array_merge($tags, $outcome['tags']);
            }

            if ($failures !== []) {
                DB::rollBack();

                return [
                    'action'   => 'failed',
                    'failures' => $failures,
                    'message'  => 'Nothing was posted. Fix the flagged rows and submit again.',
                ];
            }

            $draft->update([
                'status'       => 'submitted',
                'receiving_id' => $receiving?->receiving_id,
                'submitted_at' => now(),
            ]);

            $this->poStatus->sync((int) $draft->po_id, $userId);

            DB::commit();

            return [
                'action'           => 'submitted',
                'receiving_id'     => $receiving?->receiving_id,
                'receiving_number' => $receiving?->receiving_number,
                'items_posted'     => count($posted),
                'tags'             => collect($tags)->map(fn ($t) => [
                    'serial_number'    => $t->serial_number,
                    'supplier_barcode' => $t->supplier_barcode,
                ])->values()->all(),
            ];
        } catch (\Throwable $e) {
            DB::rollBack();

            return [
                'action'   => 'failed',
                'failures' => [],
                'message'  => 'Failed to submit the delivery: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Post one staged row, or describe why it cannot be posted.
     *
     * @return array{failure: array}|array{receiving: \App\Models\Receiving, tags: array}
     */
    private function postItem(ReceivingDraft $draft, ReceivingDraftItem $item, ?int $userId, bool $acceptOverage): array
    {
        /** @var ?Product $product */
        $product = $item->product;

        if (! $product) {
            return ['failure' => $this->failure($item, 'This row has no product.')];
        }

        /** @var ?PurchaseOrderDetail $poDetail */
        $poDetail = $item->po_detail_id
            ? PurchaseOrderDetail::with('purchaseOrder')->find($item->po_detail_id)
            : null;

        if ($poDetail) {
            // checkOverage sums live receiving_details, so once the previous
            // row in this loop has posted, this call already accounts for it.
            // Do NOT keep a running tally here — it would double-count.
            $overage = $this->posting->checkOverage(
                $poDetail,
                $product,
                (int) $item->quantity_amount,
                $item->packages_received === null ? null : (int) $item->packages_received
            );

            if ($overage !== null && ! $acceptOverage) {
                return ['failure' => $this->failure($item, $this->explainOverage($overage), $overage)];
            }
        }

        try {
            $result = $this->posting->post(
                product: $product,
                locationId: (int) $draft->location_id,
                pieces: (int) $item->quantity_amount,
                entryMode: $item->entry_mode,
                poDetail: $poDetail,
                supplierBarcode: $item->scanned_barcode ?: null,
                // A delivery is put away in one zone, so it lives on the header
                // and every row inherits it.
                zoneLabel: $draft->zone_label,
                userId: $userId,
                unitsEntered: (int) $item->units_entered,
                packagesReceived: $item->packages_received === null ? null : (int) $item->packages_received,
                piecesExpected: $item->pieces_expected === null ? null : (int) $item->pieces_expected,
                contentsVerified: (bool) $item->contents_verified,
            );
        } catch (\Throwable $e) {
            // A duplicate serial claimed since staging lands here, via the
            // global item_serial.serial_number unique key. Naming the row is
            // what lets the clerk find the one label that is wrong.
            return ['failure' => $this->failure($item, $this->explainThrowable($item, $e))];
        }

        return ['receiving' => $result['receiving'], 'tags' => $result['tags']];
    }

    /** @return array{draft_item_id: int, product_name: ?string, barcode: ?string, reason: string, detail: ?array} */
    private function failure(ReceivingDraftItem $item, string $reason, ?array $detail = null): array
    {
        return [
            'draft_item_id' => (int) $item->draft_item_id,
            'product_name'  => $item->product?->product_name,
            'barcode'       => $item->scanned_barcode,
            'reason'        => $reason,
            'detail'        => $detail,
        ];
    }

    /**
     * Turn checkOverage's machine-readable payload into a sentence a clerk can
     * act on. The package limit is phrased in cartons because that is what is
     * physically on the floor; the piece limit is phrased in pieces.
     */
    private function explainOverage(array $overage): string
    {
        if (($overage['limit'] ?? null) === 'packages') {
            $over      = (int) ($overage['overage_packages'] ?? 0);
            $remaining = (int) ($overage['remaining_packages'] ?? 0);

            return "This line no longer has room — {$over} package(s) over, with {$remaining} still expected.";
        }

        $over      = (int) ($overage['overage'] ?? 0);
        $remaining = (int) ($overage['remaining'] ?? 0);

        return "This line no longer has room — {$over} piece(s) over, with {$remaining} still expected.";
    }

    private function explainThrowable(ReceivingDraftItem $item, \Throwable $e): string
    {
        $message = $e->getMessage();

        if ($item->scanned_barcode && str_contains(strtolower($message), 'duplicate')) {
            return "Barcode \"{$item->scanned_barcode}\" was claimed by another delivery "
                . 'while this one was being staged. Remove this row and check the label.';
        }

        return 'Could not post this row: ' . $message;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd back-end && php artisan test tests/Feature/Inventory/ReceivingDraftSubmitTest.php
```

Expected: PASS, 6 tests.

- [ ] **Step 5: Run the whole inventory suite to check nothing regressed**

```bash
cd back-end && php artisan test tests/Feature/Inventory/
```

Expected: PASS. `ReceiveCountTest`, `ReceiveVerifyTest`, `ReceiveTopUpTest`, `ReceivingPostingServiceTest`, `ScanTransactionReceivingWithPoTest` and the rest must all still pass — nothing in this task modified the code they exercise.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Services/ReceivingDraftSubmitService.php back-end/tests/Feature/Inventory/ReceivingDraftSubmitTest.php
git commit -m "feat(receiving): post a staged delivery all-or-nothing"
```

---

## Task 5: HTTP layer

**Files:**
- Create: `back-end/app/Http/Controllers/Api/ReceivingDraftController.php`
- Create: `back-end/app/Http/Controllers/Api/ReceivingDraftSubmitController.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/Inventory/ReceivingDraftStagingTest.php` (append)

- [ ] **Step 1: Write the failing HTTP tests**

Append these methods to `back-end/tests/Feature/Inventory/ReceivingDraftStagingTest.php`, inside the class, before the closing brace:

```php
    public function test_opening_a_draft_twice_resumes_the_same_one(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable();
        $poDetail = $this->makePoLine($product, $location);

        $payload = ['po_id' => $poDetail->po_id, 'location_id' => $location->location_id];

        $first  = $this->actingAs($user)->postJson('/api/receiving-drafts', $payload);
        $first->assertOk();
        $firstId = $first->json('data.draft_id');

        $second = $this->actingAs($user)->postJson('/api/receiving-drafts', $payload);
        $second->assertOk();

        $this->assertSame($firstId, $second->json('data.draft_id'), 'A second clerk must resume the open draft, not start a rival list.');
    }

    public function test_staging_a_scan_records_a_row_and_moves_no_stock(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable(50);
        $poDetail = $this->makePoLine($product, $location, 10);

        $draft = $this->actingAs($user)->postJson('/api/receiving-drafts', [
            'po_id' => $poDetail->po_id, 'location_id' => $location->location_id,
        ])->json('data.draft_id');

        $res = $this->actingAs($user)->postJson("/api/receiving-drafts/{$draft}/items", [
            'po_detail_id' => $poDetail->po_detail_id,
            'entry_mode'   => 'scanned',
            'barcode'      => 'BOX-A',
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'staged');
        $this->assertSame(50, (int) $res->json('data.item.quantity_amount'));
        $this->assertSame(1, (int) $res->json('data.item.packages_received'));

        $this->assertNull(
            \App\Models\Inventory::where('location_id', $location->location_id)
                ->where('product_id', $product->product_id)->first()
        );
    }

    public function test_staging_a_count_marks_contents_verified(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable(50);
        $poDetail = $this->makePoLine($product, $location, 10);

        $draft = $this->actingAs($user)->postJson('/api/receiving-drafts', [
            'po_id' => $poDetail->po_id, 'location_id' => $location->location_id,
        ])->json('data.draft_id');

        $res = $this->actingAs($user)->postJson("/api/receiving-drafts/{$draft}/items", [
            'po_detail_id'  => $poDetail->po_detail_id,
            'entry_mode'    => 'counted',
            'quantity'      => 2,
            'actual_pieces' => 95,
        ]);

        $res->assertOk();
        // Counting IS the verification — the clerk had the pieces in hand.
        $this->assertTrue((bool) $res->json('data.item.contents_verified'));
        $this->assertSame(95, (int) $res->json('data.item.quantity_amount'));
        $this->assertSame(100, (int) $res->json('data.item.pieces_expected'));
        $this->assertSame(2, (int) $res->json('data.item.packages_received'));
    }

    public function test_a_staged_row_can_be_adjusted_and_removed(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable(50);
        $poDetail = $this->makePoLine($product, $location, 10);

        $draft = $this->actingAs($user)->postJson('/api/receiving-drafts', [
            'po_id' => $poDetail->po_id, 'location_id' => $location->location_id,
        ])->json('data.draft_id');

        $itemId = $this->actingAs($user)->postJson("/api/receiving-drafts/{$draft}/items", [
            'po_detail_id' => $poDetail->po_detail_id,
            'entry_mode'   => 'scanned',
            'barcode'      => 'BOX-B',
        ])->json('data.item.draft_item_id');

        $patch = $this->actingAs($user)->patchJson("/api/receiving-drafts/{$draft}/items/{$itemId}", [
            'actual_pieces' => 47,
        ]);
        $patch->assertOk();
        $this->assertSame(47, (int) $patch->json('data.item.quantity_amount'));
        // The expectation must NOT move — the shortfall is the whole point.
        $this->assertSame(50, (int) $patch->json('data.item.pieces_expected'));

        $delete = $this->actingAs($user)->deleteJson("/api/receiving-drafts/{$draft}/items/{$itemId}");
        $delete->assertOk();
        $this->assertSame(0, ReceivingDraftItem::where('draft_id', $draft)->count());
    }

    public function test_a_duplicate_barcode_is_rejected_at_stage_time(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable(50);
        $poDetail = $this->makePoLine($product, $location, 10);

        $draft = $this->actingAs($user)->postJson('/api/receiving-drafts', [
            'po_id' => $poDetail->po_id, 'location_id' => $location->location_id,
        ])->json('data.draft_id');

        $body = [
            'po_detail_id' => $poDetail->po_detail_id,
            'entry_mode'   => 'scanned',
            'barcode'      => 'BOX-SAME',
        ];

        $this->actingAs($user)->postJson("/api/receiving-drafts/{$draft}/items", $body)->assertOk();
        $second = $this->actingAs($user)->postJson("/api/receiving-drafts/{$draft}/items", $body);

        $second->assertOk();
        $second->assertJsonPath('data.action', 'rejected');
        $this->assertSame(1, ReceivingDraftItem::where('draft_id', $draft)->count());
    }

    public function test_discarding_a_draft_removes_its_rows(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable(50);
        $poDetail = $this->makePoLine($product, $location, 10);

        $draft = $this->actingAs($user)->postJson('/api/receiving-drafts', [
            'po_id' => $poDetail->po_id, 'location_id' => $location->location_id,
        ])->json('data.draft_id');

        $this->actingAs($user)->postJson("/api/receiving-drafts/{$draft}/items", [
            'po_detail_id' => $poDetail->po_detail_id,
            'entry_mode'   => 'scanned',
            'barcode'      => 'BOX-C',
        ])->assertOk();

        $this->actingAs($user)->deleteJson("/api/receiving-drafts/{$draft}")->assertOk();

        $this->assertSame('discarded', ReceivingDraft::find($draft)->status);
        $this->assertSame(0, ReceivingDraftItem::where('draft_id', $draft)->count());
        // The barcode is free again, so the goods can be re-received.
        $this->assertNull(ReceivingDraft::openFor((int) $poDetail->po_id, (int) $location->location_id));
    }
```

Add these imports at the top of the file if not already present:

```php
use App\Models\Inventory;
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd back-end && php artisan test tests/Feature/Inventory/ReceivingDraftStagingTest.php
```

Expected: the two model tests still PASS; the six new HTTP tests FAIL with 404s, because the routes do not exist yet.

- [ ] **Step 3: Write the draft controller**

Create `back-end/app/Http/Controllers/Api/ReceivingDraftController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDraft;
use App\Models\ReceivingDraftItem;
use App\Services\ReceivingDraftStagingService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * CRUD for a delivery being staged before it is posted.
 *
 * Nothing in this controller writes to inventory, inventory_batches,
 * receiving_details or item_serial. That is a structural guarantee, not a
 * convention: a bug here can lose a clerk's typing, but it cannot corrupt
 * stock. Posting lives in ReceivingDraftSubmitController alone.
 */
class ReceivingDraftController extends Controller
{
    use ApiResponse, \App\Traits\NormalizesBarcode;

    public function __construct(private readonly ReceivingDraftStagingService $staging)
    {
    }

    /** The open draft for this PO+warehouse, or null when there is none. */
    public function show(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'po_id'       => 'required|integer|exists:purchase_orders,po_id',
            'location_id' => 'required|integer|exists:locations,location_id',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $draft = ReceivingDraft::openFor((int) $request->po_id, (int) $request->location_id);

        return $this->success(
            $draft ? $this->present($draft) : null,
            $draft ? 'Open delivery found' : 'No open delivery'
        );
    }

    /**
     * Open a draft, or resume the one already open.
     *
     * Resuming rather than creating is deliberate. Two clerks working the same
     * truck must land on one list; a second draft would let the same delivery
     * be staged twice and posted twice.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'po_id'       => 'required|integer|exists:purchase_orders,po_id',
            'location_id' => 'required|integer|exists:locations,location_id',
            'zone_label'  => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $po = PurchaseOrder::find($request->po_id);

        if ($po->verification_status !== 'verified') {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'This purchase order must be verified by an auditor before receiving.',
            ], 'Rejected');
        }

        // 8 = Approved, 10 = Authorized, 12 = Partially Received.
        if (! in_array((int) $po->status_id, [8, 10, 12], true)) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'PO must be Approved, Authorized, or Partially Received.',
            ], 'Rejected');
        }

        $draft = ReceivingDraft::openFor((int) $request->po_id, (int) $request->location_id);

        if (! $draft) {
            $draft = ReceivingDraft::create([
                'po_id'       => (int) $request->po_id,
                'location_id' => (int) $request->location_id,
                'opened_by'   => $request->user()?->user_id,
                'status'      => 'open',
                'zone_label'  => $request->input('zone_label'),
            ]);
        } elseif ($request->filled('zone_label')) {
            $draft->update(['zone_label' => $request->input('zone_label')]);
        }

        return $this->success($this->present($draft->fresh()), 'Delivery ready');
    }

    /**
     * Stage one scan or one hand count.
     *
     * The piece maths mirrors the two live posting endpoints exactly, so a
     * staged row posts to the same numbers a direct scan or count would have:
     *   - a scan is one package (or one appliance unit), never more;
     *   - a count is the clerk's units, converted by pieces_per_package, with
     *     actual_pieces overriding the expectation when a box came up short.
     */
    public function storeItem(Request $request, ReceivingDraft $draft)
    {
        if ($draft->status !== 'open') {
            return $this->error('This delivery has already been submitted.', 422);
        }

        $validator = Validator::make($request->all(), [
            'po_detail_id'  => 'required|integer|exists:purchase_order_details,po_detail_id',
            'entry_mode'    => 'required|string|in:scanned,counted',
            'barcode'       => 'nullable|string|max:191',
            'quantity'      => 'nullable|integer|min:1',
            'actual_pieces' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $poDetail = PurchaseOrderDetail::with(['product.applianceDetail', 'product.consumableDetail'])
            ->find($request->po_detail_id);

        /** @var ?Product $product */
        $product = $poDetail?->product;

        if (! $product) {
            return $this->error('Line item has no product.', 422);
        }

        $barcode = $request->filled('barcode')
            ? ($this->canonicalSerial((string) $request->input('barcode')) ?: null)
            : null;

        $entryMode = $request->input('entry_mode');

        if ($entryMode === 'counted' && $product->isSerialized()) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Serialized items must be scanned individually.',
            ], 'Rejected');
        }

        [$pieces, $packages, $expected, $units] = $entryMode === 'scanned'
            ? $this->scanQuantities($product)
            : $this->countQuantities($product, (int) $request->input('quantity', 1), $request->input('actual_pieces'));

        $check = $this->staging->check($draft, $poDetail, $product, $pieces, $packages, $barcode);

        if ($check['action'] === 'rejected') {
            return $this->success([
                'action' => 'rejected',
                'reason' => $check['reason'],
            ], 'Rejected');
        }

        $item = ReceivingDraftItem::create([
            'draft_id'          => $draft->draft_id,
            'product_id'        => $product->product_id,
            'po_detail_id'      => $poDetail->po_detail_id,
            'entry_mode'        => $entryMode,
            'scanned_barcode'   => $barcode,
            'quantity_amount'   => $pieces,
            'packages_received' => $packages,
            'pieces_expected'   => $expected,
            // Counting IS the verification: the clerk typed the number of
            // pieces they physically had in front of them. A scan proves a
            // carton arrived and says nothing about what was inside it.
            'contents_verified' => $entryMode === 'counted',
            'units_entered'     => $units,
        ]);

        return $this->success([
            'action'  => 'staged',
            'item'    => $this->presentItem($item->fresh('product')),
            'warning' => $check['warning'] ?? null,
            'draft'   => $this->present($draft->fresh()),
        ], 'Staged');
    }

    /**
     * Correct a staged row's quantity.
     *
     * `pieces_expected` is never rewritten here. It records what the packages
     * should have held, and the gap between it and quantity_amount is the
     * shortfall — rewriting it would erase the discrepancy the clerk is
     * reporting.
     */
    public function updateItem(Request $request, ReceivingDraft $draft, ReceivingDraftItem $item)
    {
        if ($draft->status !== 'open') {
            return $this->error('This delivery has already been submitted.', 422);
        }

        if ((int) $item->draft_id !== (int) $draft->draft_id) {
            return $this->error('That row does not belong to this delivery.', 404);
        }

        $validator = Validator::make($request->all(), [
            'actual_pieces' => 'nullable|integer|min:0',
            'packages'      => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        if (! $request->has('actual_pieces') && ! $request->has('packages')) {
            return $this->error('Nothing to change.', 422);
        }

        $changes = [];

        if ($request->has('packages') && $item->packages_received !== null) {
            $packages = (int) $request->input('packages');
            $ppp      = max(1, (int) $item->product?->getPiecesPerPackage());

            $changes['packages_received'] = $packages;
            $changes['units_entered']     = $packages;
            $changes['pieces_expected']   = $packages * $ppp;
            // Re-deriving pieces from the new package count keeps the row
            // coherent; an explicit actual_pieces in the same request wins
            // below, which is what a clerk correcting both at once means.
            $changes['quantity_amount']   = $packages * $ppp;
        }

        if ($request->has('actual_pieces')) {
            $changes['quantity_amount'] = (int) $request->input('actual_pieces');
            // Editing the piece count IS opening the box and counting.
            $changes['contents_verified'] = true;
        }

        $item->update($changes);

        return $this->success([
            'action' => 'updated',
            'item'   => $this->presentItem($item->fresh('product')),
            'draft'  => $this->present($draft->fresh()),
        ], 'Updated');
    }

    /** Remove a wrong row. This is the fix for a mis-selected line. */
    public function destroyItem(ReceivingDraft $draft, ReceivingDraftItem $item)
    {
        if ($draft->status !== 'open') {
            return $this->error('This delivery has already been submitted.', 422);
        }

        if ((int) $item->draft_id !== (int) $draft->draft_id) {
            return $this->error('That row does not belong to this delivery.', 404);
        }

        $item->delete();

        return $this->success([
            'action' => 'removed',
            'draft'  => $this->present($draft->fresh()),
        ], 'Removed');
    }

    /**
     * Abandon the whole delivery.
     *
     * The items are deleted so their barcodes become free again — the goods
     * still have to be received somehow, and a discarded draft must not hold
     * a label hostage. The header is kept, marked `discarded`, so the record
     * that someone started and abandoned a delivery survives.
     */
    public function destroy(ReceivingDraft $draft)
    {
        if ($draft->status !== 'open') {
            return $this->error('This delivery has already been submitted.', 422);
        }

        $draft->items()->delete();
        $draft->update(['status' => 'discarded', 'discarded_at' => now()]);

        return $this->success(['action' => 'discarded'], 'Delivery discarded');
    }

    /**
     * A scan is exactly one package for a consumable, or one unit for an
     * appliance. The client's quantity is deliberately ignored so it can never
     * submit more than one package per scan event.
     *
     * @return array{0: int, 1: ?int, 2: ?int, 3: int} pieces, packages, expected, units
     */
    private function scanQuantities(Product $product): array
    {
        if ($product->isSerialized()) {
            return [1, null, null, 1];
        }

        $ppp = $product->getPiecesPerPackage();

        return [$ppp, 1, $ppp, 1];
    }

    /**
     * @return array{0: int, 1: ?int, 2: ?int, 3: int} pieces, packages, expected, units
     */
    private function countQuantities(Product $product, int $units, $actualPieces): array
    {
        $countUnit = $product->consumableDetail?->count_unit ?? 'package';

        if ($countUnit === 'piece') {
            // The clerk counted pieces directly — expected and actual are the
            // same thing, so there is nothing to verify and nothing to record.
            return [$units, null, null, $units];
        }

        // The expectation comes from the item master, never from the clerk.
        $expected = $units * $product->getPiecesPerPackage();
        $pieces   = $actualPieces !== null ? (int) $actualPieces : $expected;

        return [$pieces, $units, $expected, $units];
    }

    private function present(ReceivingDraft $draft): array
    {
        $items = $draft->items()->with('product')->orderBy('draft_item_id')->get();

        return [
            'draft_id'      => (int) $draft->draft_id,
            'po_id'         => (int) $draft->po_id,
            'location_id'   => (int) $draft->location_id,
            'status'        => $draft->status,
            'zone_label'    => $draft->zone_label,
            'staged_pieces' => (int) $items->sum('quantity_amount'),
            'staged_count'  => $items->count(),
            'items'         => $items->map(fn ($i) => $this->presentItem($i))->values()->all(),
        ];
    }

    private function presentItem(ReceivingDraftItem $item): array
    {
        return [
            'draft_item_id'     => (int) $item->draft_item_id,
            'product_id'        => (int) $item->product_id,
            'product_name'      => $item->product?->product_name,
            'po_detail_id'      => $item->po_detail_id ? (int) $item->po_detail_id : null,
            'entry_mode'        => $item->entry_mode,
            'scanned_barcode'   => $item->scanned_barcode,
            'quantity_amount'   => (int) $item->quantity_amount,
            'packages_received' => $item->packages_received === null ? null : (int) $item->packages_received,
            'pieces_expected'   => $item->pieces_expected === null ? null : (int) $item->pieces_expected,
            'contents_verified' => (bool) $item->contents_verified,
            'units_entered'     => (int) $item->units_entered,
            'staged_at'         => $item->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Write the submit controller**

Create `back-end/app/Http/Controllers/Api/ReceivingDraftSubmitController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReceivingDraft;
use App\Services\ReceivingDraftSubmitService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Posts a staged delivery to inventory. The one place in the staged-receiving
 * feature where stock moves.
 */
class ReceivingDraftSubmitController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request, ReceivingDraft $draft, ReceivingDraftSubmitService $submit)
    {
        $result = $submit->submit(
            $draft,
            $request->user()?->user_id,
            $request->boolean('accept_overage')
        );

        // A failed submit is a normal outcome the clerk acts on, not a fault:
        // it returns 200 with action => 'failed' and the flagged rows, matching
        // how the rest of the receiving endpoints report business rejections.
        return $this->success($result, $result['action'] === 'submitted' ? 'Delivery posted' : 'Submit blocked');
    }
}
```

- [ ] **Step 5: Add the routes**

In `back-end/routes/api.php`, find the `permission:barcode_inventory_processing,create` group (it contains `/inventory/receive-topup`). Add these seven routes immediately after the `Route::post('/inventory/receive-topup', ...)` line:

```php
        // Staged PO receiving: scans and counts land in a draft the clerk can
        // review and correct, and only /submit moves stock.
        Route::get('/receiving-drafts', [\App\Http\Controllers\Api\ReceivingDraftController::class, 'show']);
        Route::post('/receiving-drafts', [\App\Http\Controllers\Api\ReceivingDraftController::class, 'store']);
        Route::post('/receiving-drafts/{draft}/items', [\App\Http\Controllers\Api\ReceivingDraftController::class, 'storeItem']);
        Route::patch('/receiving-drafts/{draft}/items/{item}', [\App\Http\Controllers\Api\ReceivingDraftController::class, 'updateItem']);
        Route::delete('/receiving-drafts/{draft}/items/{item}', [\App\Http\Controllers\Api\ReceivingDraftController::class, 'destroyItem']);
        Route::delete('/receiving-drafts/{draft}', [\App\Http\Controllers\Api\ReceivingDraftController::class, 'destroy']);
        Route::post('/receiving-drafts/{draft}/submit', \App\Http\Controllers\Api\ReceivingDraftSubmitController::class);
```

Route-model binding resolves `{draft}` and `{item}` by primary key, which the models declare as `draft_id` and `draft_item_id`.

- [ ] **Step 6: Verify the routes registered**

```bash
cd back-end && php artisan route:list --path=receiving-drafts
```

Expected: seven rows — `GET`, `POST`, `POST .../items`, `PATCH .../items/{item}`, `DELETE .../items/{item}`, `DELETE .../{draft}`, `POST .../{draft}/submit`.

- [ ] **Step 7: Run the tests to verify they pass**

```bash
cd back-end && php artisan test tests/Feature/Inventory/ReceivingDraftStagingTest.php
```

Expected: PASS, 8 tests.

- [ ] **Step 8: Run the whole inventory suite**

```bash
cd back-end && php artisan test tests/Feature/Inventory/
```

Expected: PASS, no regressions.

- [ ] **Step 9: Commit**

```bash
git add back-end/app/Http/Controllers/Api/ReceivingDraftController.php back-end/app/Http/Controllers/Api/ReceivingDraftSubmitController.php back-end/routes/api.php back-end/tests/Feature/Inventory/ReceivingDraftStagingTest.php
git commit -m "feat(receiving): expose staging and submit over HTTP"
```

---

## Task 6: Frontend API client

**Files:**
- Modify: `frontend/src/admin/services/api.js`

This file does **not** use axios. It uses a local `apiRequest(endpoint, options)`
helper built on `fetch` (defined around `src/admin/services/api.js:214`) which
attaches the bearer token and returns the parsed JSON envelope — so callers read
`res.data` to get at the `ApiResponse` payload. Match that style exactly.

- [ ] **Step 1: Add the client**

Add near the other receiving-related exports in `frontend/src/admin/services/api.js`:

```js
/**
 * Staged PO receiving. Scans and counts land in a server-side draft that the
 * clerk reviews and corrects; only `submit` moves stock.
 */
export const receivingDraftsAPI = {
  /** The open draft for this PO+warehouse, or `data: null` when there is none. */
  getOpen: async (poId, locationId) => {
    const query = new URLSearchParams({ po_id: poId, location_id: locationId }).toString();
    return apiRequest(`/receiving-drafts?${query}`);
  },

  /** Opens a draft, or resumes the one already open for this PO+warehouse. */
  open: async (payload) =>
    apiRequest('/receiving-drafts', {
      method: 'POST',
      body: JSON.stringify(payload),
    }),

  /** payload: { po_detail_id, entry_mode, barcode?, quantity?, actual_pieces? } */
  stageItem: async (draftId, payload) =>
    apiRequest(`/receiving-drafts/${draftId}/items`, {
      method: 'POST',
      body: JSON.stringify(payload),
    }),

  /** payload: { actual_pieces?, packages? } */
  updateItem: async (draftId, itemId, payload) =>
    apiRequest(`/receiving-drafts/${draftId}/items/${itemId}`, {
      method: 'PATCH',
      body: JSON.stringify(payload),
    }),

  removeItem: async (draftId, itemId) =>
    apiRequest(`/receiving-drafts/${draftId}/items/${itemId}`, { method: 'DELETE' }),

  discard: async (draftId) =>
    apiRequest(`/receiving-drafts/${draftId}`, { method: 'DELETE' }),

  /** The only call that posts to inventory. */
  submit: async (draftId, acceptOverage = false) =>
    apiRequest(`/receiving-drafts/${draftId}/submit`, {
      method: 'POST',
      body: JSON.stringify({ accept_overage: acceptOverage }),
    }),
};
```

- [ ] **Step 2: Verify it compiles**

```bash
cd frontend && npx eslint src/admin/services/api.js
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -m "feat(receiving): add the staged-delivery API client"
```

---

## Task 7: Stage instead of post in the scan flow

**Files:**
- Modify: `frontend/src/admin/pages/BarcodeScan.jsx`

This is a large file. Work by locating the named functions rather than by line number, which will have drifted.

- [ ] **Step 1: Add draft state and load-or-resume**

Find `startBsrSession` (the function containing `const receivableLines = bsrLinesForLocation(po, myLocationId);`). Alongside the existing `bsrScannedItems` state declaration, add:

```js
  // The staged delivery. Null until a PO+location is chosen. `items` are staged
  // rows that have NOT moved stock — only Submit does that.
  const [bsrDraft, setBsrDraft] = useState(null);
  const [bsrSubmitting, setBsrSubmitting] = useState(false);
  const [bsrSubmitFailures, setBsrSubmitFailures] = useState([]);
```

Then at the end of `startBsrSession`, after the `poScanStatus` block and before the `setTimeout(() => bsrInputRef.current?.focus(), 100);` line, add:

```js
    // Open or resume the staged delivery for this PO at this warehouse. A
    // second clerk on the same truck lands on the same list rather than
    // starting a rival one.
    if (poId && bsrLocationId) {
      try {
        const draftRes = await receivingDraftsAPI.open({
          po_id: poId,
          location_id: bsrLocationId,
        });
        if (draftRes?.data?.action === 'rejected') {
          setBsrAlertModal({
            type: 'error',
            title: 'Cannot Receive',
            message: draftRes.data.reason,
          });
        } else {
          setBsrDraft(draftRes?.data ?? null);
        }
      } catch {
        setBsrDraft(null);
      }
    }
```

Add `receivingDraftsAPI` to the existing import from `../services/api`.

- [ ] **Step 2: Stage the scan instead of posting it**

In `handleBsrScan`, find the block that calls `inventoryAPI.scanTransaction({ ... transaction_type: 'receiving' ... })`. Replace that call and its result handling with:

```js
      const res = await receivingDraftsAPI.stageItem(bsrDraft.draft_id, {
        po_detail_id: bsrActiveLine.po_detail_id,
        entry_mode: 'scanned',
        barcode: raw.trim(),
      });

      const payload = res?.data;

      if (payload?.action === 'rejected') {
        setBsrAlertModal({
          type: 'error',
          title: 'Cannot Stage This Item',
          message: payload.reason,
        });
        setBsrScanInput('');
        return;
      }

      // An overage is shown, never blocked. The clerk has to be able to stage
      // what is physically on the floor before deciding what to do about it.
      if (payload?.warning) {
        toast.warning(
          payload.warning.limit === 'packages'
            ? `Staged — this is ${payload.warning.overage_packages} package(s) more than ordered.`
            : `Staged — this is ${payload.warning.overage} piece(s) more than ordered.`,
        );
      }

      setBsrDraft(payload.draft);
      setBsrScanInput('');
```

Delete the `setBsrScannedItems(...)` and `setBsrProductProgress(...)` calls that followed the old post — the draft response is now the single source of truth for staged rows, and posted progress no longer changes on a scan.

- [ ] **Step 3: Stage the count instead of posting it**

In the count submit handler (the function that calls `inventoryAPI.receiveCount`), replace that call and its result handling with:

```js
      const res = await receivingDraftsAPI.stageItem(bsrDraft.draft_id, {
        po_detail_id: bsrActiveLine.po_detail_id,
        entry_mode: 'counted',
        quantity: units,
        actual_pieces: actualPieces,
      });

      const payload = res?.data;

      if (payload?.action === 'rejected') {
        setBsrAlertModal({
          type: 'error',
          title: 'Cannot Stage This Count',
          message: payload.reason,
        });
        return;
      }

      if (payload?.warning) {
        toast.warning(
          payload.warning.limit === 'packages'
            ? `Staged — this is ${payload.warning.overage_packages} package(s) more than ordered.`
            : `Staged — this is ${payload.warning.overage} piece(s) more than ordered.`,
        );
      }

      setBsrDraft(payload.draft);
      setBsrCountMode(false);
```

- [ ] **Step 4: Verify the frontend builds**

```bash
cd frontend && npx eslint src/admin/pages/BarcodeScan.jsx
```

Expected: no new errors. Pre-existing warnings in this file are acceptable; new `no-undef` errors mean an import or state declaration was missed.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/pages/BarcodeScan.jsx
git commit -m "feat(receiving): stage scans and counts instead of posting them"
```

---

## Task 8: The review list and Submit

**Files:**
- Modify: `frontend/src/admin/pages/BarcodeScan.jsx`
- Modify: `frontend/src/admin/styles/po_receiving.css`

- [ ] **Step 1: Add the remove, adjust and submit handlers**

Add these near the other `bsr` handlers in `BarcodeScan.jsx`:

```js
  /** Remove a staged row. This is the fix for a mis-selected line. */
  const handleBsrRemoveStaged = useCallback(async (itemId) => {
    if (!bsrDraft) return;
    try {
      const res = await receivingDraftsAPI.removeItem(bsrDraft.draft_id, itemId);
      setBsrDraft(res?.data?.draft ?? null);
      setBsrSubmitFailures((prev) => prev.filter((f) => f.draft_item_id !== itemId));
    } catch {
      toast.error('Could not remove that row');
    }
  }, [bsrDraft]);

  /** Correct the pieces on a staged row after opening a short box. */
  const handleBsrAdjustStaged = useCallback(async (itemId, actualPieces) => {
    if (!bsrDraft) return;
    try {
      const res = await receivingDraftsAPI.updateItem(bsrDraft.draft_id, itemId, {
        actual_pieces: actualPieces,
      });
      setBsrDraft(res?.data?.draft ?? null);
    } catch {
      toast.error('Could not update that row');
    }
  }, [bsrDraft]);

  /**
   * The only action that moves stock. All-or-nothing: a blocked submit leaves
   * the draft exactly as it was, with the offending rows flagged.
   */
  const handleBsrSubmitDraft = useCallback(async (acceptOverage = false) => {
    if (!bsrDraft || bsrSubmitting) return;
    setBsrSubmitting(true);
    setBsrSubmitFailures([]);
    try {
      const res = await receivingDraftsAPI.submit(bsrDraft.draft_id, acceptOverage);
      const payload = res?.data;

      if (payload?.action === 'failed') {
        setBsrSubmitFailures(payload.failures || []);
        setBsrAlertModal({
          type: 'error',
          title: 'Nothing Was Posted',
          message: payload.message || 'Fix the flagged rows and submit again.',
        });
        return;
      }

      setBsrLastTags(payload?.tags || []);
      setBsrDraft(null);
      toast.success(`Posted ${payload.items_posted} item(s) — ${payload.receiving_number}`);

      // Posted progress has moved, so re-read it and open a fresh delivery.
      await startBsrSession(bsrSelectedPo);
    } catch (e) {
      toast.error('Submit failed');
    } finally {
      setBsrSubmitting(false);
    }
  }, [bsrDraft, bsrSubmitting, bsrSelectedPo]);
```

- [ ] **Step 2: Render the staged list**

Replace the JSX block that renders `bsrScannedItems` with a staged-rows list. The key differences from the old log: each row has a Remove button, package rows accept a corrected piece count, and a failed submit flags the offending rows.

```jsx
{bsrDraft && (
  <div className="bsr-staged">
    <div className="bsr-staged-head">
      <strong>Staged for this delivery</strong>
      <span className="bsr-staged-note">
        Nothing below is in inventory yet — check the list, then submit.
      </span>
    </div>

    {(bsrDraft.items || []).length === 0 && (
      <div className="bsr-staged-empty">Scan or count an item to start.</div>
    )}

    {(bsrDraft.items || []).map((item) => {
      const failure = bsrSubmitFailures.find((f) => f.draft_item_id === item.draft_item_id);
      return (
        <div
          key={item.draft_item_id}
          className={`bsr-staged-row${failure ? ' bsr-staged-row-failed' : ''}`}
        >
          <div className="bsr-staged-main">
            <span className="bsr-staged-product">{item.product_name}</span>
            <span className="bsr-staged-qty">
              {item.packages_received != null
                ? `${item.packages_received} pkg · ${item.quantity_amount} pcs`
                : `${item.quantity_amount} pc(s)`}
            </span>
            {item.scanned_barcode && (
              <span className="bsr-staged-code">{item.scanned_barcode}</span>
            )}
          </div>

          {item.pieces_expected != null && item.quantity_amount !== item.pieces_expected && (
            <div className="bsr-staged-short">
              {item.pieces_expected - item.quantity_amount} pcs short of {item.pieces_expected} expected
            </div>
          )}

          {failure && (
            <div className="bsr-staged-failure" role="alert">{failure.reason}</div>
          )}

          <div className="bsr-staged-actions">
            {item.packages_received != null && (
              <input
                type="number"
                min="0"
                step="1"
                className="bsr-staged-pieces"
                defaultValue={item.quantity_amount}
                aria-label={`Actual pieces for ${item.product_name}`}
                onBlur={(e) => {
                  const next = Number(e.target.value);
                  if (Number.isFinite(next) && next !== item.quantity_amount) {
                    handleBsrAdjustStaged(item.draft_item_id, next);
                  }
                }}
              />
            )}
            <button
              type="button"
              className="bsr-btn-remove"
              onClick={() => handleBsrRemoveStaged(item.draft_item_id)}
            >
              Remove
            </button>
          </div>
        </div>
      );
    })}

    <button
      type="button"
      className="bsr-btn-submit-draft"
      disabled={bsrSubmitting || (bsrDraft.items || []).length === 0}
      onClick={() => handleBsrSubmitDraft(false)}
    >
      {bsrSubmitting
        ? 'Submitting…'
        : `Submit ${bsrDraft.staged_count} item(s) to inventory`}
    </button>

    {bsrSubmitFailures.some((f) => f.detail?.action === 'over_receipt') && (
      <button
        type="button"
        className="bsr-btn-accept-overage"
        disabled={bsrSubmitting}
        onClick={() => handleBsrSubmitDraft(true)}
      >
        Accept the overage and submit anyway
      </button>
    )}
  </div>
)}
```

- [ ] **Step 3: Show staged progress beside posted progress**

Find where a line's progress is rendered from `bsrProductProgress[line.po_detail_id]`. Add the staged figure beside it, so committed and pending are never confused:

```jsx
{(() => {
  const posted = bsrProductProgress[line.po_detail_id] || 0;
  const staged = (bsrDraft?.items || [])
    .filter((i) => i.po_detail_id === line.po_detail_id)
    .reduce((sum, i) => sum + i.quantity_amount, 0);
  return (
    <span className="bsr-line-progress">
      <span className="bsr-line-posted">{posted} received</span>
      {staged > 0 && <span className="bsr-line-staged">+{staged} staged</span>}
    </span>
  );
})()}
```

- [ ] **Step 4: Fix the stale copy**

Find the string `Each accepted scan posts receiving on the server. Execute is not required for normal receiving.` and replace it with:

```
Scans and counts are staged for review. Nothing reaches inventory until you press Submit.
```

- [ ] **Step 5: Add the styles**

Append to `frontend/src/admin/styles/po_receiving.css`:

```css
/* Staged delivery — rows that have NOT moved stock yet. The muted treatment
   against the posted-progress figures is doing real work: a clerk must be able
   to tell at a glance what is committed and what is still only a claim. */
.bsr-staged { margin-top: 1rem; border: 1px solid #d8dee6; border-radius: 8px; padding: 0.75rem; }
.bsr-staged-head { display: flex; flex-direction: column; gap: 0.15rem; margin-bottom: 0.6rem; }
.bsr-staged-note { font-size: 0.8rem; color: #6b7280; }
.bsr-staged-empty { font-size: 0.85rem; color: #9ca3af; padding: 0.5rem 0; }
.bsr-staged-row { border-top: 1px solid #eef1f5; padding: 0.5rem 0; }
.bsr-staged-row-failed { background: #fef2f2; border-left: 3px solid #dc2626; padding-left: 0.5rem; }
.bsr-staged-main { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: baseline; }
.bsr-staged-product { font-weight: 600; }
.bsr-staged-qty { font-size: 0.85rem; color: #374151; }
.bsr-staged-code { font-size: 0.75rem; color: #6b7280; font-family: ui-monospace, monospace; }
.bsr-staged-short { font-size: 0.8rem; color: #b45309; margin-top: 0.2rem; }
.bsr-staged-failure { font-size: 0.8rem; color: #b91c1c; margin-top: 0.2rem; }
.bsr-staged-actions { display: flex; gap: 0.5rem; align-items: center; margin-top: 0.35rem; }
.bsr-staged-pieces { width: 6rem; padding: 0.25rem 0.4rem; border: 1px solid #d1d5db; border-radius: 4px; }
.bsr-btn-remove { background: none; border: 1px solid #d1d5db; border-radius: 4px; padding: 0.25rem 0.6rem; cursor: pointer; color: #b91c1c; }
.bsr-btn-submit-draft { width: 100%; margin-top: 0.75rem; padding: 0.6rem; border: none; border-radius: 6px; background: #047857; color: #fff; font-weight: 600; cursor: pointer; }
.bsr-btn-submit-draft:disabled { background: #9ca3af; cursor: not-allowed; }
.bsr-btn-accept-overage { width: 100%; margin-top: 0.4rem; padding: 0.5rem; border: 1px solid #b45309; border-radius: 6px; background: #fff; color: #b45309; cursor: pointer; }
.bsr-line-progress { display: inline-flex; gap: 0.4rem; align-items: baseline; }
.bsr-line-posted { font-weight: 600; }
.bsr-line-staged { font-size: 0.8rem; color: #b45309; font-style: italic; }
```

- [ ] **Step 6: Lint**

```bash
cd frontend && npx eslint src/admin/pages/BarcodeScan.jsx
```

Expected: no new errors.

- [ ] **Step 7: Manually verify the whole flow**

Start the app (`cd frontend && npm run dev`), then in the browser:

1. Open Barcode Scan → Receive PO, select an approved PO and a location.
2. Select a **bulk material** line and scan an appliance barcode. It appears in the staged list.
3. Confirm nothing moved: `cd back-end && php artisan tinker --execute="echo App\Models\ReceivingDetail::count();"` — the count must be unchanged from before the scan.
4. Press **Remove** on that row. Select the **appliance** line, scan the same barcode again. It stages against the right line.
5. Press **Submit**. Confirm the success toast names a GRN, and the appliance is now in stock under the right product.

Expected: step 3 proves the fix — the wrong scan never reached inventory and was correctable.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/admin/pages/BarcodeScan.jsx frontend/src/admin/styles/po_receiving.css
git commit -m "feat(receiving): review staged rows before submitting them"
```

---

## Task 9: The unsubmitted-delivery badge

Goods staged but not submitted are physically on the floor and invisible to inventory. Nothing auto-posts and nothing auto-deletes — but it must be impossible to miss.

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/ReceivingController.php`
- Modify: `frontend/src/admin/pages/BarcodeScan.jsx`
- Test: `back-end/tests/Feature/Inventory/ReceivingDraftStagingTest.php` (append)

- [ ] **Step 1: Write the failing test**

Append to `back-end/tests/Feature/Inventory/ReceivingDraftStagingTest.php`, inside the class:

```php
    public function test_po_scan_status_reports_an_open_draft(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeConsumable(50);
        $poDetail = $this->makePoLine($product, $location, 10);

        $draft = $this->actingAs($user)->postJson('/api/receiving-drafts', [
            'po_id' => $poDetail->po_id, 'location_id' => $location->location_id,
        ])->json('data.draft_id');

        $this->actingAs($user)->postJson("/api/receiving-drafts/{$draft}/items", [
            'po_detail_id' => $poDetail->po_detail_id,
            'entry_mode'   => 'scanned',
            'barcode'      => 'BOX-BADGE',
        ])->assertOk();

        $res = $this->actingAs($user)->getJson("/api/receivings/po-scan-status/{$poDetail->po_id}");

        $res->assertOk();
        $this->assertSame(1, (int) $res->json('data.open_draft.staged_count'));
        $this->assertSame(50, (int) $res->json('data.open_draft.staged_pieces'));
    }
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd back-end && php artisan test --filter=test_po_scan_status_reports_an_open_draft
```

Expected: FAIL — `data.open_draft.staged_count` is null.

- [ ] **Step 3: Report the open draft from `poScanStatus`**

In `back-end/app/Http/Controllers/Api/ReceivingController.php`, find the `poScanStatus` method and add `open_draft` to the array it returns:

```php
        // An open draft means goods are physically on the floor but not in
        // inventory. Surfaced here, not acted on: the receiving screen shows a
        // badge so the clerk resumes rather than starting over, and a human
        // always decides whether it posts or is discarded.
        $openDraft = \App\Models\ReceivingDraft::where('po_id', $poId)
            ->where('status', 'open')
            ->withCount('items')
            ->first();

        $openDraftPayload = $openDraft ? [
            'draft_id'      => (int) $openDraft->draft_id,
            'location_id'   => (int) $openDraft->location_id,
            'staged_count'  => (int) $openDraft->items_count,
            'staged_pieces' => $openDraft->stagedPieces(),
            'opened_at'     => $openDraft->created_at?->toIso8601String(),
        ] : null;
```

Place this immediately before the method's **final** return, then change that return to:

```php
            return $this->success(
                ['lines' => $lines, 'scan_history' => $scanHistory, 'open_draft' => $openDraftPayload],
                'PO scan status retrieved'
            );
```

**Note the method has two returns.** The early one — `return $this->success(['lines' => [], 'scan_history' => []], 'No line items found for this PO');` — fires when the PO has no line items at all. Leave it alone: a draft can only exist where there is a line to stage against, so `open_draft` would always be null there, and the frontend reads it with `?.` anyway.

Add `use App\Models\ReceivingDraft;` to the file's imports and drop the fully-qualified name above if you prefer — match whichever style the surrounding code already uses.

- [ ] **Step 4: Run it to verify it passes**

```bash
cd back-end && php artisan test --filter=test_po_scan_status_reports_an_open_draft
```

Expected: PASS.

- [ ] **Step 5: Show the badge**

In `BarcodeScan.jsx`, in the PO selection list where each PO card is rendered, add the badge. Read the flag from the `poScanStatus` response already loaded in `startBsrSession` — store it in state as `bsrOpenDraftInfo` and render:

```jsx
{bsrOpenDraftInfo && (
  <div className="bsr-draft-badge" role="status">
    Unsubmitted delivery — {bsrOpenDraftInfo.staged_count} item(s) staged,
    {' '}{bsrOpenDraftInfo.staged_pieces} pcs not yet in inventory.
  </div>
)}
```

Add to `frontend/src/admin/styles/po_receiving.css`:

```css
.bsr-draft-badge {
  background: #fffbeb;
  border: 1px solid #f59e0b;
  border-radius: 6px;
  padding: 0.4rem 0.6rem;
  font-size: 0.82rem;
  color: #92400e;
  margin: 0.5rem 0;
}
```

- [ ] **Step 6: Run the full backend suite**

```bash
cd back-end && php artisan test
```

Expected: PASS across the whole suite.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/ReceivingController.php frontend/src/admin/pages/BarcodeScan.jsx frontend/src/admin/styles/po_receiving.css back-end/tests/Feature/Inventory/ReceivingDraftStagingTest.php
git commit -m "feat(receiving): flag a delivery staged but never submitted"
```

---

## Task 10: Final verification

- [ ] **Step 1: Full backend suite**

```bash
cd back-end && php artisan test
```

Expected: PASS. Pay particular attention to `ReceiveCountTest`, `ReceiveVerifyTest`, `ReceiveTopUpTest`, `ReceivingPostingServiceTest`, `ScanTransactionReceivingWithPoTest`, `ReceivingPackagesDeriveTest` — these exercise the code paths this feature deliberately did not modify. Any failure there means something leaked.

- [ ] **Step 2: Frontend tests**

```bash
cd frontend && npx react-scripts test --watchAll=false
```

Expected: PASS. `CountEntryPanel.test.jsx` and `poReceivingTotals.test.js` must be untouched and green.

- [ ] **Step 3: Confirm the untouched files really are untouched**

```bash
git diff main --stat -- back-end/app/Services/ReceivingPostingService.php back-end/app/Http/Controllers/Api/ReceiveCountController.php back-end/app/Http/Controllers/Api/ReceiveVerifyController.php back-end/app/Http/Controllers/Api/ReceiveTopUpController.php back-end/app/Http/Controllers/Api/InventoryScanTransactionController.php frontend/src/admin/components/receiving/CountEntryPanel.jsx
```

Expected: **empty output**. The design's central claim is that the code moving stock did not change. If any of these files appear, the replay approach was abandoned somewhere and the plan needs revisiting before merge.

- [ ] **Step 4: Commit any final fixes**

```bash
git add -A
git commit -m "test(receiving): verify staged receiving leaves the posting path unchanged"
```

---

## Self-review notes

**Spec coverage:** Every spec section maps to a task — data model (1–2), staging validation incl. duplicate-serial strategy (3), submit replay + all-or-nothing + failure collection (4), API surface (5), frontend client (6), stage-instead-of-post (7), review list + Submit + dual progress + stale copy (8), stale-draft badge (9), test list (spread across 2–4 and 10). Out-of-scope PO-less receiving is not touched by any task, as intended.

**Known deviation from the spec, deliberate:** the spec's `contents_verified` is stamped `true` when a clerk edits a staged row's piece count (Task 5, `updateItem`). Editing the piece count *is* opening the box and counting it, which is exactly the condition the flag records. The spec did not say this explicitly; it follows from `ReceiveCountController`'s existing rule that counting is verification.

**Type consistency:** `ReceivingDraftStagingService::check()` returns `action`/`reason`/`warning` and is consumed only in `ReceivingDraftController::storeItem`. `ReceivingDraftSubmitService::submit()` returns `action`/`failures`/`message` on failure and `action`/`receiving_id`/`receiving_number`/`items_posted`/`tags` on success, consumed by `ReceivingDraftSubmitController` and the frontend's `handleBsrSubmitDraft`. `openFor()`, `stagedPieces()`, `stagedPiecesForLine()`, `stagedPackagesForLine()` are defined in Task 2 and used in Tasks 3, 5 and 9 under those exact names.

**Assumptions verified against the codebase before this plan was committed:** `NormalizesBarcode::canonicalSerial()` exists and is `protected`; `PoReceivingStatusService::sync(int $poId, $userId = null)` matches the call in Task 4; `ItemSerial::IN_STOCK_STATUS_ID` is `26`; `item_serial.serial_number` carries `item_serial_serial_number_unique`; `toast.warning()` is already used in `BarcodeScan.jsx`; `ApplianceDetail::create(['product_id' => ..., 'is_serialized' => 1])` matches the fixture style in `ApplianceAlwaysNeedsBarcodeTest`; `Product::isSerialized()` reads `applianceDetail.is_serialized` defaulting true.

**Two corrections made during self-review:** Task 6's client originally assumed axios — `api.js` actually uses a bespoke `apiRequest` fetch helper, and the snippet was rewritten to match. Task 9's instruction to add `open_draft` "alongside `lines` and `scan_history`" was ambiguous because `poScanStatus` has two return points; it now names the final one and explains why the early return is left alone.

**Tasks 6–9 carry a lower confidence than 1–5.** The backend tasks are fully specified and test-driven. The frontend tasks describe edits to a 5,182-line file by locating named functions, and the exact surrounding JSX will need judgement at the keyboard — particularly Step 2 of Task 8, where the staged list replaces the existing `bsrScannedItems` block whose markup I have not reproduced here in full.
