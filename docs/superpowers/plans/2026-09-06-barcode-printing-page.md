# Barcode Printing Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a dedicated page that shows every stock-in identity — our minted tags, supplier codes and manufacturer serials — as scannable barcodes, filterable by product type / date / location / receipt, selectable across pages, printable in bulk or one at a time, with print history recorded.

**Architecture:** Nothing new is minted. `MaterialTagService` and `ReceivingPostingService` are untouched; this work only changes who may *look at* and *reprint* rows that already exist in `item_serial`. One new service, `TagPrintPolicy`, becomes the single home for two rules — which tier an identity belongs to, and whether it may be printed in a given mode — and both the new queue endpoint and the existing print endpoint ask it. Four nullable columns on `item_serial` record print history.

**Tech Stack:** Laravel 11 (PHP 8.2, MySQL), PHPUnit via `php artisan test`. React 18 (CRA), Jest + `@testing-library/react` via `npm test`. Barcodes render with `react-barcode` on screen and `ZplLabelBuilder` on the Zebra.

**Spec:** `docs/superpowers/specs/2026-09-06-barcode-printing-page-design.md`

---

## Before You Start

Read the spec linked above. Then read these three files — the plan assumes you know what they currently do:

- `back-end/app/Http/Controllers/Api/LabelPrintController.php` — the existing print endpoint and its `printable()` gate.
- `back-end/app/Services/MaterialTagService.php` — the docblocks explain *why* supplier-coded materials keep no `INT-` serial. You are not changing this file; you are changing what the UI is allowed to show.
- `frontend/src/admin/components/tags/MaterialTagSheet.jsx` — the card markup you will extract in Task 9.

### Running the tests

Backend, from `back-end/`:

```bash
php artisan test --filter=BarcodeQueueTest
```

Frontend, from `frontend/`:

```bash
npx react-scripts test --watchAll=false --testPathPattern=TagCard
```

### Local environment quirks (this repo, this machine)

- Migrations: if `APP_ENV=production` in your `.env`, `php artisan migrate` refuses to run without `--force`.
- **The test suite runs against a separate database, `airkingV5_test`.** A migration that is not applied there makes every new test fail with an SQL "unknown column" error that looks nothing like a missing migration. After Task 1, migrate both:

```bash
php artisan migrate --force
DB_DATABASE=airkingV5_test php artisan migrate --force
```

`--env=testing` does **not** work here: there is no `.env.testing`, so the flag falls back to `.env` and silently migrates the dev database again, reporting "Nothing to migrate". The test database name comes from `phpunit.xml`, so it has to be passed explicitly as above.

---

## File Structure

### Backend — create

| File | Responsibility |
|---|---|
| `database/migrations/2026_09_06_000001_add_print_tracking_to_item_serial.php` | Four print-history columns |
| `app/Services/TagPrintPolicy.php` | Tier classification and the two-mode printable rule. The only place either rule is written down. |
| `app/Http/Controllers/Api/BarcodeQueueController.php` | The filtered, paginated tag list and the select-all-ids resolver |
| `tests/Feature/Inventory/TagPrintPolicyTest.php` | Tier and mode rules in isolation |
| `tests/Feature/Inventory/BarcodePrintStampTest.php` | Print-history stamping |
| `tests/Feature/Inventory/BarcodeQueueTest.php` | Queue listing, filters, counts, id cap |

### Backend — modify

| File | Change |
|---|---|
| `app/Models/ItemSerial.php` | Add the four columns to `$fillable` and `$casts` |
| `app/Http/Controllers/Api/LabelPrintController.php` | Delegate to `TagPrintPolicy`, accept `mode`, stamp after a successful send |
| `routes/api.php:492` | Two new routes beside the existing barcode-label pair |
| `tests/Feature/Inventory/BarcodeLabelPrintTest.php` | Add single-mode cases; existing cases stay green unchanged |

### Frontend — create

| File | Responsibility |
|---|---|
| `src/admin/components/tags/TagCard.jsx` | One barcode card. Rendered by both the old sheet and the new grid. |
| `src/admin/components/tags/TagFilterBar.jsx` | The filter controls, URL-synced by its parent |
| `src/admin/components/tags/TagSelectionBar.jsx` | Selection count, select-all/clear, print actions, mixed-selection warning |
| `src/admin/hooks/useTagSelection.js` | Selection state that survives paging |
| `src/admin/pages/BarcodePrintingPage.jsx` | Assembles the three above around the queue API |
| `src/admin/styles/barcode_printing.css` | Page layout |
| `src/admin/components/tags/TagCard.test.jsx` | Tier badges, print state, zoom |
| `src/admin/components/tags/TagSelectionBar.test.jsx` | Selection count, mixed-selection warning, print actions |
| `src/admin/hooks/useTagSelection.test.js` | Selection across pages |
| `src/admin/pages/BarcodePrintingPage.test.jsx` | Filtering, mixed-selection warning, print dispatch |

### Frontend — modify

| File | Change |
|---|---|
| `src/admin/components/tags/MaterialTagSheet.jsx` | Render `TagCard` instead of inline card markup. Behaviour unchanged. |
| `src/admin/services/api.js:1551-1558` | `queue`, `queueIds`, and a `mode` argument on `print` |
| `src/admin/hooks/useLabelPrinter.js` | Pass `mode` through to the API |
| `src/admin/utils/printBarcodeLabels.js` | Fall back to each tag's own product name |
| `src/App.jsx` | Route `/admin/barcode-printing` |
| `src/admin/components/Sidebar.jsx` | Nav entry + lazy-import entry |

---

## Task 1: Print-history columns

**Files:**
- Create: `back-end/database/migrations/2026_09_06_000001_add_print_tracking_to_item_serial.php`
- Modify: `back-end/app/Models/ItemSerial.php:19-30`
- Test: `back-end/tests/Feature/Inventory/BarcodePrintStampTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/BarcodePrintStampTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Print history on a tag.
 *
 * The stamp records "sent to the printer", never "stuck on a box" — a jam
 * still counts as sent. What must hold is that a failed send counts as
 * nothing at all, and that the first send keeps its date forever.
 */
class BarcodePrintStampTest extends TestCase
{
    use DatabaseTransactions;

    private function materialTag(array $overrides = []): ItemSerial
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Tube',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'L',
            'location_type' => 'warehouse',
        ]);

        return ItemSerial::create(array_merge([
            'product_id'    => $product->product_id,
            'location_id'   => $location->location_id,
            'serial_number' => 'INT-TEST-' . uniqid(),
            'serial_type'   => 'received',
            'status_id'     => ItemSerial::IN_STOCK_STATUS_ID,
        ], $overrides));
    }

    public function test_a_new_tag_reads_as_never_sent(): void
    {
        $tag = $this->materialTag()->fresh();

        $this->assertSame(0, (int) $tag->print_count);
        $this->assertNull($tag->first_printed_at);
        $this->assertNull($tag->last_printed_at);
        $this->assertNull($tag->last_printed_by);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

From `back-end/`:

```bash
php artisan test --filter=BarcodePrintStampTest
```

Expected: FAIL — `Undefined property` / SQL error naming `print_count`, because the column does not exist.

- [ ] **Step 3: Write the migration**

Create `back-end/database/migrations/2026_09_06_000001_add_print_tracking_to_item_serial.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a tag was last sent to a label printer, and how often.
 *
 * All four are nullable or defaulted so every row that already exists reads
 * as "never sent" without a backfill — which is the truth, since nothing
 * recorded printing before this.
 *
 * These record a job leaving for the printer, not a sticker reaching a box.
 * A jammed printer still stamps. The UI says "sent" for that reason, and the
 * reprint action stays available whatever these columns say.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('item_serial') || Schema::hasColumn('item_serial', 'print_count')) {
            return;
        }

        Schema::table('item_serial', function (Blueprint $table) {
            $table->timestamp('first_printed_at')->nullable()->after('status_id');
            $table->timestamp('last_printed_at')->nullable()->after('first_printed_at');
            $table->unsignedInteger('print_count')->default(0)->after('last_printed_at');
            $table->unsignedBigInteger('last_printed_by')->nullable()->after('print_count');

            /* The queue's "not yet sent" filter is the page's default view,
               so it runs on every load. */
            $table->index('last_printed_at', 'item_serial_last_printed_at_index');

            /* Same shape as scanned_by and receiving_id already use on this
               table: removing a user blanks the attribution rather than
               blocking the delete or orphaning the row. */
            $table->foreign('last_printed_by')
                ->references('user_id')->on('users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('item_serial', 'print_count')) {
            return;
        }

        Schema::table('item_serial', function (Blueprint $table) {
            $table->dropForeign(['last_printed_by']);
            $table->dropIndex('item_serial_last_printed_at_index');
            $table->dropColumn([
                'first_printed_at', 'last_printed_at', 'print_count', 'last_printed_by',
            ]);
        });
    }
};
```

On the foreign key: `scanned_by` and `receiving_id` are already declared this way on this table (`2026_04_27_000000_add_scan_fields_to_item_serial.php:17-23`), so `last_printed_by` follows suit. `onDelete('set null')` gives the property that matters — removing a user costs the print history its attribution but never blocks the delete or orphans the row — without trading away referential integrity.

- [ ] **Step 4: Add the columns to the model**

In `back-end/app/Models/ItemSerial.php`, extend `$fillable` (it currently ends with `'status_id',`) and add a `$casts` property directly below it — the model has none today:

```php
    protected $fillable = [
        'product_id',
        'location_id',
        'receiving_id',
        'batch_id',
        'scanned_by',
        'serial_number',
        'supplier_barcode',
        'serial_type',
        'transfer_id',
        'status_id',
        'first_printed_at',
        'last_printed_at',
        'print_count',
        'last_printed_by',
    ];

    protected $casts = [
        'first_printed_at' => 'datetime',
        'last_printed_at'  => 'datetime',
        'print_count'      => 'integer',
    ];
```

- [ ] **Step 5: Migrate both databases and run the test**

```bash
php artisan migrate --force
DB_DATABASE=airkingV5_test php artisan migrate --force
php artisan test --filter=BarcodePrintStampTest
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add back-end/database/migrations/2026_09_06_000001_add_print_tracking_to_item_serial.php back-end/app/Models/ItemSerial.php back-end/tests/Feature/Inventory/BarcodePrintStampTest.php
git commit -m "feat(labels): record when a tag was sent to the printer"
```

---

## Task 2: TagPrintPolicy — tiers and the two-mode rule

**Files:**
- Create: `back-end/app/Services/TagPrintPolicy.php`
- Test: `back-end/tests/Feature/Inventory/TagPrintPolicyTest.php`

Both rules live here so the queue endpoint and the print endpoint cannot drift apart. `LabelPrintController::printable()` is deleted in Task 3 and replaced by a call into this.

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/TagPrintPolicyTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\ApplianceDetail;
use App\Models\ConsumableDetail;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Services\TagPrintPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Which identity a tag carries, and who is allowed to reprint it.
 *
 * The three tiers are not cosmetic. A minted tag is ours and may be run off
 * by the roll. A supplier code and a manufacturer serial belong to someone
 * else: reprinting one is legitimate when a sticker is damaged, but a bulk
 * run would paper a whole delivery in duplicate labels, so bulk refuses them.
 */
class TagPrintPolicyTest extends TestCase
{
    use DatabaseTransactions;

    private TagPrintPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new TagPrintPolicy();
    }

    private function location(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'L',
            'location_type' => 'warehouse',
        ]);
    }

    private function materialTag(array $overrides = []): ItemSerial
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Tube',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);

        $serial = ItemSerial::create(array_merge([
            'product_id'    => $product->product_id,
            'location_id'   => $this->location()->location_id,
            'serial_number' => 'INT-TEST-' . uniqid(),
            'serial_type'   => 'received',
            'status_id'     => ItemSerial::IN_STOCK_STATUS_ID,
        ], $overrides));

        return $serial->load('product.consumableDetail');
    }

    private function applianceSerial(): ItemSerial
    {
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Split Type AC',
            'product_type' => 'appliance',
        ]);
        ApplianceDetail::create(['product_id' => $product->product_id, 'is_serialized' => 1]);

        $serial = ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $this->location()->location_id,
            'serial_number' => 'MFG-' . uniqid(),
            'serial_type'   => 'received',
            'status_id'     => ItemSerial::IN_STOCK_STATUS_ID,
        ]);

        return $serial->load('product.applianceDetail');
    }

    public function test_it_names_each_tier(): void
    {
        $this->assertSame('minted', $this->policy->tier($this->materialTag()));
        $this->assertSame('supplier', $this->policy->tier($this->materialTag(['supplier_barcode' => 'SUP-9911'])));
        $this->assertSame('manufacturer', $this->policy->tier($this->applianceSerial()));
    }

    /**
     * A serialized appliance also has a NULL supplier_barcode, because
     * ReceivingPostingService::mintTags never sets one. Checking the barcode
     * before the product would therefore read an appliance serial as one of
     * our own tags and let a bulk run reprint it.
     */
    public function test_serialized_wins_over_a_null_supplier_barcode(): void
    {
        $serial = $this->applianceSerial();

        $this->assertNull($serial->supplier_barcode);
        $this->assertSame('manufacturer', $this->policy->tier($serial));
    }

    public function test_bulk_prints_only_what_we_minted(): void
    {
        $this->assertTrue($this->policy->printable($this->materialTag(), 'bulk'));
        $this->assertFalse($this->policy->printable($this->materialTag(['supplier_barcode' => 'SUP-9911']), 'bulk'));
        $this->assertFalse($this->policy->printable($this->applianceSerial(), 'bulk'));
    }

    public function test_a_single_reprint_accepts_every_tier(): void
    {
        $this->assertTrue($this->policy->printable($this->materialTag(), 'single'));
        $this->assertTrue($this->policy->printable($this->materialTag(['supplier_barcode' => 'SUP-9911']), 'single'));
        $this->assertTrue($this->policy->printable($this->applianceSerial(), 'single'));
    }

    /* A retired tag points at stock that is gone. Labelling it is wrong no
       matter who is asking or how few they asked for. */
    public function test_no_mode_reprints_a_retired_tag(): void
    {
        $retired = $this->materialTag(['serial_type' => 'issued']);

        $this->assertFalse($this->policy->printable($retired, 'bulk'));
        $this->assertFalse($this->policy->printable($retired, 'single'));
    }

    public function test_a_tag_with_no_product_is_never_printable(): void
    {
        $orphan = $this->materialTag();
        $orphan->setRelation('product', null);

        $this->assertFalse($this->policy->printable($orphan, 'single'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test --filter=TagPrintPolicyTest
```

Expected: FAIL — `Class "App\Services\TagPrintPolicy" not found`.

- [ ] **Step 3: Write the service**

Create `back-end/app/Services/TagPrintPolicy.php`:

```php
<?php

namespace App\Services;

use App\Models\ItemSerial;

/**
 * Which identity a tag carries, and who may reprint it.
 *
 * Both questions live here rather than in a controller because two callers
 * ask them — the printing queue, to badge and gate each card, and the print
 * endpoint, to decide what actually reaches the Zebra. Two copies of this
 * rule would drift, and the drift would show up as a label on the wrong box.
 *
 * NOTHING HERE MINTS ANYTHING. A supplier-coded carton keeps exactly one
 * identifier — the supplier's. See MaterialTagService::mintForScan for why.
 */
class TagPrintPolicy
{
    public const TIER_MINTED       = 'minted';
    public const TIER_SUPPLIER     = 'supplier';
    public const TIER_MANUFACTURER = 'manufacturer';

    /**
     * Order matters. A serialized appliance's row carries a NULL
     * supplier_barcode — ReceivingPostingService::mintTags never sets one —
     * so testing the barcode first would classify a manufacturer's serial as
     * one of our own tags, and bulk printing would then run off duplicates of
     * a serial we did not issue and cannot reissue.
     */
    public function tier(ItemSerial $serial): string
    {
        if ($serial->product?->isSerialized()) {
            return self::TIER_MANUFACTURER;
        }

        if ($serial->supplier_barcode !== null && $serial->supplier_barcode !== '') {
            return self::TIER_SUPPLIER;
        }

        return self::TIER_MINTED;
    }

    /**
     * @param  string  $mode  'bulk' — a selection sent by the roll — or
     *                        'single', one deliberate reprint of one label.
     */
    public function printable(ItemSerial $serial, string $mode): bool
    {
        if ($serial->product === null) {
            return false;
        }

        /* True in every mode: the tag names stock that has left. */
        if ($serial->serial_type === 'issued') {
            return false;
        }

        if ($mode === 'single') {
            return true;
        }

        /* Bulk keeps the original rule verbatim: only what we minted. A bulk
           run that included supplier codes would put a second sticker on
           every already-labelled carton in a delivery. */
        return $this->tier($serial) === self::TIER_MINTED;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php artisan test --filter=TagPrintPolicyTest
```

Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/TagPrintPolicy.php back-end/tests/Feature/Inventory/TagPrintPolicyTest.php
git commit -m "feat(labels): name the three tag tiers and gate reprinting by mode"
```

---

## Task 3: Teach the print endpoint about modes

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/LabelPrintController.php`
- Test: `back-end/tests/Feature/Inventory/BarcodeLabelPrintTest.php`

The existing tests in that file describe bulk behaviour and must keep passing **unchanged** — `mode` defaults to `bulk` precisely so they do.

- [ ] **Step 1: Write the failing tests**

Append these four methods to `BarcodeLabelPrintTest`, before the closing brace. They reuse the file's existing `materialTag()`, `applianceSerial()`, `user()` and `$spoolFile` helpers:

```php
    public function test_a_single_reprint_accepts_a_supplier_code(): void
    {
        $tag = $this->materialTag(['supplier_barcode' => 'SUP-9911']);

        $res = $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
            'mode'       => 'single',
        ]);

        $res->assertOk();
        $this->assertStringContainsString($tag->serial_number, file_get_contents($this->spoolFile));
    }

    public function test_a_single_reprint_accepts_a_manufacturers_serial(): void
    {
        $serial = $this->applianceSerial();

        $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$serial->serial_id],
            'mode'       => 'single',
        ])->assertOk();
    }

    /* "Single" names an intent, not a batch size. A 200-item single reprint
       would be a bulk run wearing the label that skips the bulk rule. */
    public function test_single_mode_refuses_more_than_one_label(): void
    {
        $first  = $this->materialTag(['supplier_barcode' => 'SUP-1']);
        $second = $this->materialTag(['supplier_barcode' => 'SUP-2']);

        $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$first->serial_id, $second->serial_id],
            'mode'       => 'single',
        ])->assertStatus(422);

        $this->assertSame('', (string) file_get_contents($this->spoolFile));
    }

    public function test_a_retired_tag_is_refused_even_as_a_single_reprint(): void
    {
        $tag = $this->materialTag(['serial_type' => 'issued']);

        $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
            'mode'       => 'single',
        ])->assertStatus(422);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test --filter=BarcodeLabelPrintTest
```

Expected: the four new tests FAIL (422 where 200 was expected, and 200 where 422 was expected on the multi-id case). The eight existing tests PASS.

- [ ] **Step 2b: Make `ItemSerial` location-scopable**

`ItemSerial` has a `location_id` but is the one such model without the `ScopesToUserLocation` trait — `Inventory`, `InventoryBatch`, `Receiving`, `Issuance`, `Adjustment` and others all use it. Existing `ItemSerial` callers scope by passing a location explicitly (see `scopeIssuableAt`), which is why the gap never surfaced; a new endpoint that lists across locations is the first caller that needs the user-based scope.

Add the trait to `back-end/app/Models/ItemSerial.php`:

```php
use App\Models\Concerns\ScopesToUserLocation;
```
```php
    use Auditable, ScopesToUserLocation;
```

This is additive and changes no existing behaviour: `forUser()` is a local scope that only applies where a caller invokes it. Per `ScopesToUserLocation`, a centralized user (roles 1, 2, 5) is unfiltered, a scoped user sees only their location, and a scoped user with no location gets an empty result rather than everything.

Add this test to `BarcodeLabelPrintTest`:

```php
    /* The client nominates ids. Without a location scope a branch-scoped user
       could reprint another branch's labels by guessing them — the route
       guard only says they may print something, not whose stock. */
    public function test_it_refuses_a_tag_at_another_users_location(): void
    {
        $tag = $this->materialTag();

        $scopedUser = $this->user();
        $scopedUser->role_id     = $this->nonCentralizedRoleId();
        $scopedUser->location_id = $this->location()->location_id;
        $scopedUser->save();

        $this->actingAs($scopedUser)->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ])->assertStatus(422);

        $this->assertSame('', (string) file_get_contents($this->spoolFile));
    }
```

`nonCentralizedRoleId()` is a helper you add: any `role_id` not in `[1, 2, 5]`, read from the `roles` table so it stays true if roles change. Check how `User::scopedLocationId()` resolves a user's location before writing this — if it reads something other than a `location_id` column, adjust the fixture to match rather than adding a column.

- [ ] **Step 3: Rewrite the controller**

In `back-end/app/Http/Controllers/Api/LabelPrintController.php`, set the imports to:

```php
use App\Exceptions\LabelPrinterException;
use App\Http\Controllers\Controller;
use App\Models\ItemSerial;
use App\Services\LabelPrinterService;
use App\Services\TagPrintPolicy;
use App\Services\ZplLabelBuilder;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
```

Keep the existing file docblock, and replace the class body with:

```php
class LabelPrintController extends Controller
{
    use ApiResponse;

    public function __construct(
        private LabelPrinterService $printer,
        private TagPrintPolicy $policy,
    ) {
    }

    /** Whether the UI should offer a direct-print button at all. */
    public function status()
    {
        return $this->success(['configured' => $this->printer->isConfigured()]);
    }

    public function print(Request $request)
    {
        $request->validate([
            'serial_ids'   => 'required|array|min:1|max:200',
            'serial_ids.*' => 'integer',
            'mode'         => 'nullable|in:bulk,single',
        ]);

        /* Defaulting to bulk keeps every existing caller — the per-product tag
           sheet — on exactly the rule it had before modes existed. */
        $mode = (string) $request->input('mode', 'bulk');
        $ids  = $request->input('serial_ids');

        if ($mode === 'single' && count($ids) !== 1) {
            return $this->error('A single reprint sends exactly one label.', 422);
        }

        /* forUser() is the same location scope Inventory, Receiving and
           Issuance already apply. The client sends serial ids, so without it
           a branch-scoped user could reprint labels for another branch's
           stock simply by guessing ids — the route guard only establishes
           that they may print SOMETHING. Centralized roles are unaffected. */
        $serials = ItemSerial::with(['product.applianceDetail', 'product.consumableDetail'])
            ->forUser($request->user())
            ->whereIn('serial_id', $ids)
            ->get();

        /* The client says which tags to print; it does not get to say what is
           printable. TagPrintPolicy owns that rule and it is enforced here,
           not in the browser. */
        $printable = $serials
            ->filter(fn (ItemSerial $serial) => $this->policy->printable($serial, $mode))
            ->values();

        if ($printable->isEmpty()) {
            return $this->error('None of those tags can be reprinted.', 422);
        }

        $builder = new ZplLabelBuilder(
            widthMm: (int) config('labelprinter.label.width_mm'),
            heightMm: (int) config('labelprinter.label.height_mm'),
            dpi: (int) config('labelprinter.label.dpi'),
        );

        $zpl = $builder->build($printable->map(fn (ItemSerial $serial) => [
            'value' => $serial->serial_number,
            'name'  => $serial->product?->product_name,
        ])->all());

        try {
            $this->printer->send($zpl);
        } catch (LabelPrinterException $e) {
            /* 503: the request was fine, the printer is not. The UI tells the
               user to use the PDF instead, so this must not read as a bug in
               what they clicked. Nothing is stamped — a job that never left
               must not leave a trail saying it did. */
            return $this->error($e->getMessage(), 503);
        }

        $this->stampPrinted($printable->pluck('serial_id')->all(), $request->user()?->user_id);

        return $this->success(
            ['printed' => $printable->count()],
            $printable->count() === 1 ? 'Sticker sent to the printer.' : "{$printable->count()} stickers sent to the printer.",
        );
    }

    /**
     * Record that these tags went to the printer.
     *
     * Called only after send() returns without throwing, so a dead printer
     * leaves no trace. first_printed_at is written in its own statement
     * guarded by whereNull: the first send owns that date permanently, and a
     * reprint must not overwrite the history it is evidence of.
     */
    private function stampPrinted(array $serialIds, ?int $userId): void
    {
        /* A query-builder update, not a loop of model saves. ItemSerial uses
           the Auditable trait, so stamping 200 tags through Eloquent would
           write 200 audit_trail rows for one press of one button. This form
           bypasses model events deliberately — the print history columns are
           themselves the record of what happened, and they are on the row
           being written. */
        $now = now();

        DB::transaction(function () use ($serialIds, $userId, $now) {
            ItemSerial::whereIn('serial_id', $serialIds)->update([
                'last_printed_at' => $now,
                'last_printed_by' => $userId,
                'print_count'     => DB::raw('print_count + 1'),
            ]);

            ItemSerial::whereIn('serial_id', $serialIds)
                ->whereNull('first_printed_at')
                ->update(['first_printed_at' => $now]);
        });
    }
}
```

The old `private function printable()` is gone — `TagPrintPolicy` replaced it.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
php artisan test --filter=BarcodeLabelPrintTest
```

Expected: PASS, 12 tests. If any of the original eight now fail, the default mode is wrong — they must pass untouched.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/LabelPrintController.php back-end/tests/Feature/Inventory/BarcodeLabelPrintTest.php
git commit -m "feat(labels): allow a deliberate single reprint of a supplier or maker code"
```

---

## Task 4: Prove the print stamp

**Files:**
- Test: `back-end/tests/Feature/Inventory/BarcodePrintStampTest.php`

The implementation landed in Task 3 (`stampPrinted`). This task proves it — including the failure case, which is the half that matters.

- [ ] **Step 1: Write the failing tests**

Add these members to `BarcodePrintStampTest` (it currently has no `setUp`, so add one):

```php
    private string $spoolFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spoolFile = tempnam(sys_get_temp_dir(), 'zpl');
        config([
            'labelprinter.transport' => 'share',
            'labelprinter.share'     => $this->spoolFile,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->spoolFile);
        parent::tearDown();
    }

    private function user(): \App\Models\User
    {
        $roleId = (int) (\Illuminate\Support\Facades\DB::table('roles')->min('role_id') ?? 1);

        return \App\Models\User::create([
            'username' => 't_' . uniqid(), 'first_name' => 'T', 'last_name' => 'U',
            'email' => uniqid() . '@e.test', 'password_hash' => bcrypt('x'), 'role_id' => $roleId,
        ]);
    }

    public function test_a_successful_send_stamps_the_tag(): void
    {
        $tag  = $this->materialTag();
        $user = $this->user();

        $this->actingAs($user)->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ])->assertOk();

        $tag->refresh();

        $this->assertSame(1, (int) $tag->print_count);
        $this->assertNotNull($tag->first_printed_at);
        $this->assertNotNull($tag->last_printed_at);
        $this->assertSame($user->user_id, (int) $tag->last_printed_by);
    }

    /* first_printed_at is evidence of when a label first existed. A reprint
       is a new event, not a correction of that one. */
    public function test_a_reprint_counts_up_but_never_moves_the_first_date(): void
    {
        $tag  = $this->materialTag();
        $user = $this->user();

        $this->actingAs($user)->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ])->assertOk();

        $firstDate = $tag->refresh()->first_printed_at;

        $this->travel(2)->minutes();

        $this->actingAs($user)->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ])->assertOk();

        $tag->refresh();

        $this->assertSame(2, (int) $tag->print_count);
        $this->assertEquals($firstDate, $tag->first_printed_at, 'the first send owns that date');
        $this->assertTrue($tag->last_printed_at->greaterThan($firstDate));
    }

    /* The stamp means "the job left for the printer". A job that never left
       must leave no trace, or the queue's "not yet sent" filter starts hiding
       labels nobody ever printed. */
    public function test_a_dead_printer_stamps_nothing(): void
    {
        config(['labelprinter.transport' => null, 'labelprinter.share' => null]);
        $tag = $this->materialTag();

        $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ])->assertStatus(503);

        $tag->refresh();

        $this->assertSame(0, (int) $tag->print_count);
        $this->assertNull($tag->first_printed_at);
        $this->assertNull($tag->last_printed_at);
    }

    /* A refused tag never reached the builder, so it must not be counted
       among what was sent. */
    public function test_a_refused_tag_is_not_stamped(): void
    {
        $tag = $this->materialTag(['supplier_barcode' => 'SUP-9911']);

        $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ])->assertStatus(422);

        $this->assertSame(0, (int) $tag->refresh()->print_count);
    }
```

- [ ] **Step 2: Run the tests**

```bash
php artisan test --filter=BarcodePrintStampTest
```

Expected: PASS, 5 tests. `stampPrinted` from Task 3 already satisfies them. If `test_a_dead_printer_stamps_nothing` fails, the stamp is running inside or before the `try` — it must come after the `catch`.

- [ ] **Step 3: Commit**

```bash
git add back-end/tests/Feature/Inventory/BarcodePrintStampTest.php
git commit -m "test(labels): pin the print stamp to sends that actually left"
```

---

## Task 5: The queue endpoint — list and tier

**Files:**
- Create: `back-end/app/Http/Controllers/Api/BarcodeQueueController.php`
- Modify: `back-end/routes/api.php:492`
- Test: `back-end/tests/Feature/Inventory/BarcodeQueueTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Inventory/BarcodeQueueTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\ApplianceDetail;
use App\Models\ConsumableDetail;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The tag population a clerk prints from.
 *
 * This is a reading endpoint over item_serial. It mints nothing and changes
 * nothing — its whole job is to let someone find the labels they need among
 * everything that has ever been received.
 */
class BarcodeQueueTest extends TestCase
{
    use DatabaseTransactions;

    private function user(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username' => 't_' . uniqid(), 'first_name' => 'T', 'last_name' => 'U',
            'email' => uniqid() . '@e.test', 'password_hash' => bcrypt('x'), 'role_id' => $roleId,
        ]);
    }

    private function location(string $name = 'Main'): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => $name,
            'location_type' => 'warehouse',
        ]);
    }

    private function material(): Product
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Tube',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);

        return $product;
    }

    private function appliance(): Product
    {
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Split Type AC',
            'product_type' => 'appliance',
        ]);
        ApplianceDetail::create(['product_id' => $product->product_id, 'is_serialized' => 1]);

        return $product;
    }

    private function receiptOn(string $date, Location $location): Receiving
    {
        return Receiving::create([
            'location_id'      => $location->location_id,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => $date,
        ]);
    }

    private function tag(Product $product, array $overrides = []): ItemSerial
    {
        return ItemSerial::create(array_merge([
            'product_id'    => $product->product_id,
            'location_id'   => $this->location()->location_id,
            'serial_number' => 'TAG-' . uniqid(),
            'serial_type'   => 'received',
            'status_id'     => ItemSerial::IN_STOCK_STATUS_ID,
        ], $overrides));
    }

    public function test_it_lists_a_tag_with_its_tier_and_print_state(): void
    {
        $tag = $this->tag($this->material());

        $res = $this->actingAs($this->user())->getJson('/api/barcode-labels/queue');

        $res->assertOk();

        $row = collect($res->json('data'))->firstWhere('serial_id', $tag->serial_id);

        $this->assertNotNull($row, 'the tag must appear in the queue');
        $this->assertSame('minted', $row['tier']);
        $this->assertSame($tag->serial_number, $row['serial_number']);
        $this->assertSame('Copper Tube', $row['product_name']);
        $this->assertSame(0, $row['print_count']);
        $this->assertNull($row['last_printed_at']);
    }

    /* Every identity a receipt produced belongs here. Leaving the supplier
       and manufacturer tiers out is exactly the gap this page exists to
       close: a clerk holding a smudged supplier label could not look it up. */
    public function test_it_lists_supplier_codes_and_manufacturer_serials_too(): void
    {
        $supplier = $this->tag($this->material(), ['supplier_barcode' => 'SUP-9911']);
        $maker    = $this->tag($this->appliance());

        $rows = collect($this->actingAs($this->user())->getJson('/api/barcode-labels/queue')->json('data'))
            ->keyBy('serial_id');

        $this->assertSame('supplier', $rows[$supplier->serial_id]['tier']);
        $this->assertSame('manufacturer', $rows[$maker->serial_id]['tier']);
    }

    /* A retired tag names stock that has left. No mode can print one, so
       offering it in a printing queue would be an invitation to a dead end. */
    public function test_it_omits_retired_tags(): void
    {
        $retired = $this->tag($this->material(), ['serial_type' => 'issued']);

        $ids = collect($this->actingAs($this->user())->getJson('/api/barcode-labels/queue')->json('data'))
            ->pluck('serial_id');

        $this->assertNotContains($retired->serial_id, $ids);
    }

    public function test_it_requires_a_signed_in_user(): void
    {
        $this->getJson('/api/barcode-labels/queue')->assertStatus(401);
    }

    /* A branch-scoped user prints labels for their own stock. Listing another
       branch's tags would let them select-all and run off labels for cartons
       they will never see — and the "select all matching" endpoint resolves
       ids server-side, so the scope has to hold there too, not just here. */
    public function test_a_scoped_user_sees_only_their_own_location(): void
    {
        $mine   = $this->location('Mine');
        $theirs = $this->location('Theirs');

        $ours   = $this->tag($this->material(), ['location_id' => $mine->location_id]);
        $others = $this->tag($this->material(), ['location_id' => $theirs->location_id]);

        $scoped = $this->scopedUserAt($mine);

        $ids = collect($this->actingAs($scoped)->getJson('/api/barcode-labels/queue')->json('data'))
            ->pluck('serial_id');

        $this->assertContains($ours->serial_id, $ids);
        $this->assertNotContains($others->serial_id, $ids);

        $selectAll = $this->actingAs($scoped)->getJson('/api/barcode-labels/queue/ids')->json('data.serial_ids');

        $this->assertContains($ours->serial_id, $selectAll);
        $this->assertNotContains($others->serial_id, $selectAll);
    }

    /* A centralized role is deliberately unscoped — head office prints for
       every branch. Without this, "scope it" would quietly become "scope it
       for everyone" and nobody would notice until head office could not
       print. */
    public function test_a_centralized_user_sees_every_location(): void
    {
        $here  = $this->location('Here');
        $there = $this->location('There');

        $a = $this->tag($this->material(), ['location_id' => $here->location_id]);
        $b = $this->tag($this->material(), ['location_id' => $there->location_id]);

        $ids = collect($this->actingAs($this->user())->getJson('/api/barcode-labels/queue')->json('data'))
            ->pluck('serial_id');

        $this->assertContains($a->serial_id, $ids);
        $this->assertContains($b->serial_id, $ids);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test --filter=BarcodeQueueTest
```

Expected: FAIL — 404, the route does not exist.

- [ ] **Step 3: Write the controller**

Create `back-end/app/Http/Controllers/Api/BarcodeQueueController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ItemSerial;
use App\Services\TagPrintPolicy;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The tag population a clerk prints from.
 *
 * Reading only: this endpoint mints nothing and stamps nothing. It exists
 * because the per-product tag sheet is scoped to one product at one location,
 * while someone printing labels is working through a delivery — several
 * products, one receipt, one afternoon.
 *
 * Retired tags (serial_type 'issued') are excluded at the source. No print
 * mode will accept one, so listing them would offer a button that cannot
 * work.
 */
class BarcodeQueueController extends Controller
{
    use ApiResponse;

    /** Above this a "select all" stops being a selection and becomes a spool flood. */
    private const MAX_SELECTABLE = 200;

    public function __construct(private TagPrintPolicy $policy)
    {
    }

    public function index(Request $request)
    {
        $perPage = min(100, max(10, (int) $request->input('per_page', 24)));

        $page = $this->filtered($request)
            ->orderByDesc('serial_id')
            ->paginate($perPage);

        $page->getCollection()->transform(fn (ItemSerial $serial) => $this->row($serial));

        return $this->paginated($page);
    }

    /**
     * Every id matching the current filters, for "select all matching".
     *
     * Capped, and the cap is reported rather than hidden: a clerk who thinks
     * they selected 340 labels and got 200 finds out at the shelf.
     */
    public function ids(Request $request)
    {
        $total = $this->filtered($request)->count();

        $ids = $this->filtered($request)
            ->orderByDesc('serial_id')
            ->limit(self::MAX_SELECTABLE)
            ->pluck('serial_id');

        return $this->success([
            'serial_ids' => $ids,
            'total'      => $total,
            'truncated'  => $total > self::MAX_SELECTABLE,
            'max'        => self::MAX_SELECTABLE,
        ]);
    }

    private function row(ItemSerial $serial): array
    {
        return [
            'serial_id'        => $serial->serial_id,
            'serial_number'    => $serial->serial_number,
            'supplier_barcode' => $serial->supplier_barcode,
            'tier'             => $this->policy->tier($serial),
            'bulk_printable'   => $this->policy->printable($serial, 'bulk'),
            'product_id'       => $serial->product_id,
            'product_name'     => $serial->product?->product_name,
            'product_type'     => $serial->product?->product_type,
            'location_name'    => $serial->location?->location_name,
            'receiving_number' => $serial->receiving?->receiving_number,
            'receiving_date'   => $serial->receiving?->receiving_date?->toDateString(),
            'print_count'      => (int) $serial->print_count,
            'last_printed_at'  => $serial->last_printed_at?->toIso8601String(),
        ];
    }

    private function filtered(Request $request): Builder
    {
        return ItemSerial::query()
            ->with([
                'product.applianceDetail', 'product.consumableDetail',
                'location', 'receiving',
            ])
            ->where('serial_type', '!=', 'issued');
    }
}
```

Filters arrive in Task 6 — `filtered()` is deliberately bare here so this task's tests describe only listing.

- [ ] **Step 4: Add the routes**

In `back-end/routes/api.php`, beside the existing barcode-label pair at line 492:

```php
    /* The printing queue: everything received that still carries a scannable
       identity, filtered down to what a clerk is about to label. */
    Route::get('/barcode-labels/queue', [BarcodeQueueController::class, 'index']);
    Route::get('/barcode-labels/queue/ids', [BarcodeQueueController::class, 'ids']);
```

Add the import beside the other `Api\` controller imports at the top of the file:

```php
use App\Http\Controllers\Api\BarcodeQueueController;
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
php artisan test --filter=BarcodeQueueTest
```

Expected: PASS, 4 tests.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/BarcodeQueueController.php back-end/routes/api.php back-end/tests/Feature/Inventory/BarcodeQueueTest.php
git commit -m "feat(labels): list every scannable stock-in identity as a print queue"
```

---

## Task 6: Queue filters

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/BarcodeQueueController.php` (`filtered()`)
- Test: `back-end/tests/Feature/Inventory/BarcodeQueueTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `BarcodeQueueTest`:

```php
    private function ids(array $query): array
    {
        $res = $this->actingAs($this->user())
            ->getJson('/api/barcode-labels/queue?' . http_build_query($query));

        $res->assertOk();

        return collect($res->json('data'))->pluck('serial_id')->all();
    }

    public function test_it_filters_by_product_type(): void
    {
        $material  = $this->tag($this->material());
        $appliance = $this->tag($this->appliance());

        $ids = $this->ids(['product_type' => 'installation_material']);

        $this->assertContains($material->serial_id, $ids);
        $this->assertNotContains($appliance->serial_id, $ids);
    }

    public function test_it_filters_by_the_date_the_stock_came_in(): void
    {
        $location = $this->location();
        $march    = $this->receiptOn('2026-03-10', $location);
        $august   = $this->receiptOn('2026-08-20', $location);

        $old   = $this->tag($this->material(), ['receiving_id' => $march->receiving_id]);
        $fresh = $this->tag($this->material(), ['receiving_id' => $august->receiving_id]);

        $ids = $this->ids(['date_from' => '2026-08-01', 'date_to' => '2026-08-31']);

        $this->assertContains($fresh->serial_id, $ids);
        $this->assertNotContains($old->serial_id, $ids);
    }

    /* A date filter asks "what came in that week". A tag with no receipt
       cannot answer, and guessing either way would be a lie — so it drops
       out of a date-filtered list and stays in an unfiltered one. */
    public function test_a_tag_with_no_receipt_drops_out_of_a_date_filter(): void
    {
        $orphan = $this->tag($this->material(), ['receiving_id' => null]);

        $this->assertContains($orphan->serial_id, $this->ids([]));
        $this->assertNotContains($orphan->serial_id, $this->ids(['date_from' => '2026-01-01']));
    }

    public function test_it_filters_by_location(): void
    {
        $here      = $this->location('Cebu');
        $elsewhere = $this->location('Davao');

        $mine   = $this->tag($this->material(), ['location_id' => $here->location_id]);
        $theirs = $this->tag($this->material(), ['location_id' => $elsewhere->location_id]);

        $ids = $this->ids(['location_id' => $here->location_id]);

        $this->assertContains($mine->serial_id, $ids);
        $this->assertNotContains($theirs->serial_id, $ids);
    }

    public function test_it_filters_by_receipt_number(): void
    {
        $location = $this->location();
        $wanted   = $this->receiptOn('2026-09-01', $location);
        $other    = $this->receiptOn('2026-09-01', $location);

        $mine   = $this->tag($this->material(), ['receiving_id' => $wanted->receiving_id]);
        $theirs = $this->tag($this->material(), ['receiving_id' => $other->receiving_id]);

        $ids = $this->ids(['receipt' => $wanted->receiving_number]);

        $this->assertContains($mine->serial_id, $ids);
        $this->assertNotContains($theirs->serial_id, $ids);
    }

    public function test_it_searches_by_product_name_and_code(): void
    {
        $copper = $this->tag($this->material());
        $ac     = $this->tag($this->appliance());

        $ids = $this->ids(['q' => 'Copper']);

        $this->assertContains($copper->serial_id, $ids);
        $this->assertNotContains($ac->serial_id, $ids);
    }

    public function test_it_filters_by_whether_the_tag_was_sent_to_a_printer(): void
    {
        $unsent = $this->tag($this->material());
        $sent   = $this->tag($this->material(), [
            'print_count'      => 1,
            'first_printed_at' => now()->subDay(),
            'last_printed_at'  => now()->subDay(),
        ]);

        $unsentIds = $this->ids(['print_state' => 'unsent']);
        $this->assertContains($unsent->serial_id, $unsentIds);
        $this->assertNotContains($sent->serial_id, $unsentIds);

        $sentIds = $this->ids(['print_state' => 'sent']);
        $this->assertContains($sent->serial_id, $sentIds);
        $this->assertNotContains($unsent->serial_id, $sentIds);
    }

    /* Filters narrow together, not in competition: "appliances received in
       August" must not return August materials. */
    public function test_filters_combine(): void
    {
        $location = $this->location();
        $august   = $this->receiptOn('2026-08-20', $location);
        $march    = $this->receiptOn('2026-03-20', $location);

        $wanted       = $this->tag($this->appliance(), ['receiving_id' => $august->receiving_id]);
        $wrongType    = $this->tag($this->material(), ['receiving_id' => $august->receiving_id]);
        $wrongMonth   = $this->tag($this->appliance(), ['receiving_id' => $march->receiving_id]);

        $ids = $this->ids([
            'product_type' => 'appliance',
            'date_from'    => '2026-08-01',
            'date_to'      => '2026-08-31',
        ]);

        $this->assertContains($wanted->serial_id, $ids);
        $this->assertNotContains($wrongType->serial_id, $ids);
        $this->assertNotContains($wrongMonth->serial_id, $ids);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test --filter=BarcodeQueueTest
```

Expected: FAIL — the filters are ignored, so each `assertNotContains` finds the row it should have excluded.

- [ ] **Step 3: Implement the filters**

Replace `filtered()` in `BarcodeQueueController`:

```php
    /**
     * The filter set behind both index() and ids(), so a "select all
     * matching" can never mean something different from what is on screen.
     *
     * Date and receipt filters reach through the receiving relation. A tag
     * with no receiving_id therefore drops out of those two — which is
     * correct: it has no answer to "what came in that week", and including it
     * would claim one.
     */
    private function filtered(Request $request): Builder
    {
        $query = ItemSerial::query()
            ->with([
                'product.applianceDetail', 'product.consumableDetail',
                'location', 'receiving',
            ])
            /* The same location scope Inventory and Receiving apply. A
               branch-scoped user must not be able to list — or select-all and
               print — another branch's labels. Applied before any request
               filter so no filter combination can widen it. */
            ->forUser($request->user())
            ->where('serial_type', '!=', 'issued');

        $query->when($request->filled('product_type'), fn (Builder $q) => $q
            ->whereHas('product', fn (Builder $p) => $p
                ->where('product_type', $request->input('product_type'))));

        $query->when($request->filled('location_id'), fn (Builder $q) => $q
            ->where('location_id', (int) $request->input('location_id')));

        $query->when($request->filled('date_from'), fn (Builder $q) => $q
            ->whereHas('receiving', fn (Builder $r) => $r
                ->whereDate('receiving_date', '>=', $request->input('date_from'))));

        $query->when($request->filled('date_to'), fn (Builder $q) => $q
            ->whereHas('receiving', fn (Builder $r) => $r
                ->whereDate('receiving_date', '<=', $request->input('date_to'))));

        /* One box for "which delivery": a GRN number or the PO it came from,
           because a clerk holding paperwork has whichever one is printed on
           it and should not have to know which field it is. */
        $query->when($request->filled('receipt'), function (Builder $q) use ($request) {
            $needle = trim((string) $request->input('receipt'));

            $q->whereHas('receiving', fn (Builder $r) => $r
                ->where('receiving_number', 'like', "%{$needle}%")
                ->orWhereHas('purchaseOrder', fn (Builder $po) => $po
                    ->where('po_number', 'like', "%{$needle}%")));
        });

        $query->when($request->filled('q'), function (Builder $q) use ($request) {
            $needle = trim((string) $request->input('q'));

            $q->whereHas('product', fn (Builder $p) => $p
                ->where('product_name', 'like', "%{$needle}%")
                ->orWhere('product_code', 'like', "%{$needle}%"));
        });

        /* `last_printed_at IS NULL` is THE definition of "unsent" — not
           `print_count = 0`. The two encode the same fact and nothing in the
           schema keeps them in step, so if stamping ever sets one without the
           other, a queue whose filter used one and whose badge used the other
           would disagree and nobody would notice. One authoritative column,
           used everywhere; it is also the indexed one. */
        $state = $request->input('print_state', 'all');

        if ($state === 'unsent') {
            $query->whereNull('last_printed_at');
        } elseif ($state === 'sent') {
            $query->whereNotNull('last_printed_at');
        }

        return $query;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
php artisan test --filter=BarcodeQueueTest
```

Expected: PASS, 12 tests.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/BarcodeQueueController.php back-end/tests/Feature/Inventory/BarcodeQueueTest.php
git commit -m "feat(labels): filter the print queue by type, date, place and receipt"
```

---

## Task 7: Counts and the capped id resolver

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/BarcodeQueueController.php` (`index()`, `ids()`)
- Test: `back-end/tests/Feature/Inventory/BarcodeQueueTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `BarcodeQueueTest`:

```php
    /* The filter bar reads "340 tags · 112 not yet sent" on every load.
       Two requests for one sentence would be a wasted round trip. */
    public function test_it_reports_the_matching_and_unsent_totals(): void
    {
        $location = $this->location('CountingHouse');
        $product  = $this->material();

        $this->tag($product, ['location_id' => $location->location_id]);
        $this->tag($product, ['location_id' => $location->location_id]);
        $this->tag($product, [
            'location_id'     => $location->location_id,
            'print_count'     => 1,
            'last_printed_at' => now(),
        ]);

        $res = $this->actingAs($this->user())
            ->getJson('/api/barcode-labels/queue?location_id=' . $location->location_id);

        $res->assertOk();
        $res->assertJsonPath('counts.total', 3);
        $res->assertJsonPath('counts.unsent', 2);
    }

    public function test_select_all_returns_the_ids_matching_the_filters(): void
    {
        $here  = $this->location('Here');
        $there = $this->location('There');

        $mine   = $this->tag($this->material(), ['location_id' => $here->location_id]);
        $theirs = $this->tag($this->material(), ['location_id' => $there->location_id]);

        $res = $this->actingAs($this->user())
            ->getJson('/api/barcode-labels/queue/ids?location_id=' . $here->location_id);

        $res->assertOk();

        $ids = $res->json('data.serial_ids');

        $this->assertContains($mine->serial_id, $ids);
        $this->assertNotContains($theirs->serial_id, $ids);
        $this->assertFalse($res->json('data.truncated'));
    }

    /* A silent truncation reads as "everything is selected". The clerk finds
       out otherwise at the shelf, with 140 unlabelled cartons. */
    public function test_select_all_caps_at_two_hundred_and_says_so(): void
    {
        $location = $this->location('BigDelivery');
        $product  = $this->material();

        foreach (range(1, 205) as $ignored) {
            $this->tag($product, ['location_id' => $location->location_id]);
        }

        $res = $this->actingAs($this->user())
            ->getJson('/api/barcode-labels/queue/ids?location_id=' . $location->location_id);

        $res->assertOk();

        $this->assertCount(200, $res->json('data.serial_ids'));
        $this->assertSame(205, $res->json('data.total'));
        $this->assertTrue($res->json('data.truncated'));
        $this->assertSame(200, $res->json('data.max'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test --filter=BarcodeQueueTest
```

Expected: the counts test FAILS (no `counts` key in the response). The two `ids` tests PASS — Task 5 already built that endpoint, and this test locks its behaviour in against the filters added in Task 6.

- [ ] **Step 3: Add the counts to index()**

`ApiResponse::paginated()` returns a fixed envelope, so the counts go in beside it. Replace `index()`:

```php
    public function index(Request $request)
    {
        $perPage = min(100, max(10, (int) $request->input('per_page', 24)));

        $page = $this->filtered($request)
            ->orderByDesc('serial_id')
            ->paginate($perPage);

        $page->getCollection()->transform(fn (ItemSerial $serial) => $this->row($serial));

        /* Counted over the same filter set the page was built from, so the
           header cannot disagree with the grid under it. The unsent count is
           a second query rather than a derived number: the page holds 24 rows
           and the answer is about all of them. */
        $response = $this->paginated($page);

        return $response->setData(array_merge($response->getData(true), [
            'counts' => [
                'total'  => $page->total(),
                'unsent' => (clone $this->filtered($request))->whereNull('last_printed_at')->count(),
            ],
        ]));
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
php artisan test --filter=BarcodeQueueTest
```

Expected: PASS, 15 tests.

- [ ] **Step 4b: Check the index actually earns its place**

Task 1 added a single-column index on `last_printed_at` on the reasoning that "not yet sent" is the default view. Now that a real query exists, test that reasoning instead of inheriting it:

```bash
DB_DATABASE=airkingV5_test php artisan tinker --execute="print_r(DB::select('EXPLAIN SELECT * FROM item_serial WHERE last_printed_at IS NULL AND location_id = 1'));"
```

On a freshly migrated table almost every row is unsent, so that predicate has near-zero selectivity and the optimizer will likely prefer a full scan — while `product_type`, `location_id` and the date range are all more selective. If `EXPLAIN` shows the index unused, say so in your report and propose a composite (e.g. `(location_id, last_printed_at)`) rather than silently keeping a dead index. Do not change it without reporting the EXPLAIN output.

- [ ] **Step 5: Run the whole backend suite**

```bash
php artisan test
```

Expected: PASS. If `MaterialTagsInStockHistoryTest` or `ScannedMaterialKeepsSupplierCodeTest` fail, something in this work reached `AttachesGeneratedTags` or the receiving path — both are meant to be untouched.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/BarcodeQueueController.php back-end/tests/Feature/Inventory/BarcodeQueueTest.php
git commit -m "feat(labels): report queue totals and cap select-all out loud"
```

---

## Task 8: API client and printer hook

**Files:**
- Modify: `frontend/src/admin/services/api.js:1551-1558`
- Modify: `frontend/src/admin/hooks/useLabelPrinter.js`

The API additions are thin wrappers over `fetch` and are exercised through the components that call them in Tasks 12-13; adding them now keeps those tasks from stubbing an API that does not exist yet. The PDF change in steps 3-6 does get its own test, because it changes what ends up on a sticker.

- [ ] **Step 1: Extend the API client**

Replace the `barcodeLabelsAPI` block in `frontend/src/admin/services/api.js`:

```js
export const barcodeLabelsAPI = {
  status: async () => apiRequest('/barcode-labels/status'),

  /* The tag population to print from. `filters` maps straight onto the query
     string the queue endpoint reads — empty values are dropped so a cleared
     filter does not arrive as `?q=`. */
  queue: async (filters = {}, page = 1, perPage = 24) => {
    const params = new URLSearchParams({ page: String(page), per_page: String(perPage) });

    Object.entries(filters).forEach(([key, value]) => {
      if (value !== '' && value != null) params.append(key, String(value));
    });

    return apiRequest(`/barcode-labels/queue?${params.toString()}`);
  },

  /* Every id matching those same filters, for "select all matching". Capped
     server-side; the response says whether it truncated. */
  queueIds: async (filters = {}) => {
    const params = new URLSearchParams();

    Object.entries(filters).forEach(([key, value]) => {
      if (value !== '' && value != null) params.append(key, String(value));
    });

    return apiRequest(`/barcode-labels/queue/ids?${params.toString()}`);
  },

  /* mode 'bulk' (default) prints only tags we minted; 'single' is one
     deliberate reprint and accepts a supplier or manufacturer code too. The
     server enforces both — this argument only states the intent. */
  print: async (serialIds, mode = 'bulk') =>
    apiRequest('/barcode-labels/print', {
      method: 'POST',
      body: JSON.stringify({ serial_ids: serialIds, mode }),
    }),
};
```

- [ ] **Step 2: Pass the mode through the hook**

In `frontend/src/admin/hooks/useLabelPrinter.js`, change `sendToPrinter` to take a mode:

```js
  const sendToPrinter = useCallback(async (tags, mode = 'bulk') => {
    /* The printer is told which tags by id, so a payload that carries only
       serial numbers cannot reach it. Silence here would look like a dead
       button; the PDF still works and says so. */
    const ids = (tags || []).map((tag) => tag?.serial_id).filter((id) => id != null);
    if (ids.length === 0) {
      toast.error('These tags cannot be sent to the printer. Save them as a PDF instead.');
      return;
    }

    setSending(true);
    try {
      const res = await barcodeLabelsAPI.print(ids, mode);
      toast.success(res?.message || 'Sent to the printer.');
    } catch (e) {
      /* The printer being off is not a broken button. Say what happened and
         point at the way that still works. */
      toast.error(e?.message || 'The printer could not be reached. Save the stickers as a PDF instead.');
    } finally {
      setSending(false);
    }
  }, []);
```

The default keeps `MaterialTagSheet`'s existing call — `sendToPrinter(printableTags)` — on bulk, unchanged.

- [ ] **Step 3: Let a label carry its own product name**

`buildLabelDocument(tags, productName)` takes one name for the whole batch, which was right while every caller printed one product's tags. The printing page prints a delivery — several products in one run — so one name would be wrong for most labels, and passing none would print a grid of anonymous barcodes.

Add to `frontend/src/admin/utils/printBarcodeLabels.test.js` (the file already imports `buildLabelDocument`):

```js
import { jsPDF } from 'jspdf';

test('a label falls back to its own product name when the batch has no single one', () => {
  const textSpy = jest.spyOn(jsPDF.prototype, 'text');

  buildLabelDocument(
    [
      { serial_number: 'INT-1', product_name: 'Copper Tube' },
      { serial_number: 'INT-2', product_name: 'Split Type AC' },
    ],
    undefined,
  );

  const drawn = textSpy.mock.calls.map((call) => call[0]);

  expect(drawn).toContain('Copper Tube');
  expect(drawn).toContain('Split Type AC');

  textSpy.mockRestore();
});

/* An explicit batch name still wins — the per-product sheet passes one, and
   every tag in that batch really is that product. */
test('an explicit product name still applies to the whole batch', () => {
  const textSpy = jest.spyOn(jsPDF.prototype, 'text');

  buildLabelDocument([{ serial_number: 'INT-1', product_name: 'Ignored' }], 'PE Foam');

  expect(textSpy.mock.calls.map((call) => call[0])).toContain('PE Foam');

  textSpy.mockRestore();
});
```

- [ ] **Step 4: Run the tests to verify they fail**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern="printBarcodeLabels"
```

Expected: FAIL — the first test finds neither product name drawn, because `productName` is undefined and nothing falls back.

- [ ] **Step 5: Make the fallback**

In `frontend/src/admin/utils/printBarcodeLabels.js`, inside the `tags.forEach` loop, replace the `if (productName) { ... }` block with:

```js
    /* A batch name wins when the caller has one — the per-product sheet
       prints one product, and every tag in it really is that product. The
       printing page prints a whole delivery, so there each label falls back
       to its own. */
    const label = productName || tag.product_name;

    if (label) {
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(NAME_PT);
      doc.text(truncateToWidth(doc, label, LABEL_WIDTH_MM - 4), LABEL_WIDTH_MM / 2, NAME_BASELINE_MM, { align: 'center' });
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
npx react-scripts test --watchAll=false --testPathPattern="printBarcodeLabels|MaterialTagSheet"
```

Expected: PASS, including the file's existing tests and the per-product sheet's.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/services/api.js frontend/src/admin/hooks/useLabelPrinter.js frontend/src/admin/utils/printBarcodeLabels.js frontend/src/admin/utils/printBarcodeLabels.test.js
git commit -m "feat(labels): add the queue endpoints, a print mode, and per-label product names"
```

---

## Task 9: Extract TagCard

**Files:**
- Create: `frontend/src/admin/components/tags/TagCard.jsx`
- Create: `frontend/src/admin/components/tags/TagCard.test.jsx`
- Modify: `frontend/src/admin/components/tags/MaterialTagSheet.jsx:98-121`

One card, two hosts. The existing `MaterialTagSheet.test.jsx` is the proof this extraction changed nothing — **do not edit it.**

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/components/tags/TagCard.test.jsx`:

```jsx
import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import TagCard from './TagCard';

const minted = {
  serial_id: 1,
  serial_number: 'INT-PO-2026-237-01',
  tier: 'minted',
  product_name: 'Copper Tube',
  print_count: 0,
  last_printed_at: null,
};

test('it shows the code and the product', () => {
  render(<TagCard tag={minted} />);

  expect(screen.getByText('Copper Tube')).toBeInTheDocument();
});

/* The badge is the whole point of showing three tiers in one grid: a clerk
   must be able to tell whose identifier is on the box before reprinting it. */
test('it names which identity the code is', () => {
  const { rerender } = render(<TagCard tag={minted} />);
  expect(screen.getByText(/our tag/i)).toBeInTheDocument();

  rerender(<TagCard tag={{ ...minted, tier: 'supplier' }} />);
  expect(screen.getByText(/supplier code/i)).toBeInTheDocument();

  rerender(<TagCard tag={{ ...minted, tier: 'manufacturer' }} />);
  expect(screen.getByText(/manufacturer serial/i)).toBeInTheDocument();
});

test('it says whether the label has been sent to a printer', () => {
  const { rerender } = render(<TagCard tag={minted} />);
  expect(screen.getByText(/not yet sent/i)).toBeInTheDocument();

  rerender(<TagCard tag={{ ...minted, print_count: 3, last_printed_at: '2026-09-03T10:00:00+08:00' }} />);
  expect(screen.getByText(/sent 3/i)).toBeInTheDocument();
});

test('clicking the barcode asks to enlarge it', () => {
  const onZoom = jest.fn();
  render(<TagCard tag={minted} onZoom={onZoom} />);

  fireEvent.click(screen.getByRole('button', { name: /enlarge/i }));

  expect(onZoom).toHaveBeenCalledWith(minted);
});

/* Selection and reprint belong to the printing page. The per-product sheet
   renders the same card without them, so neither may be assumed. */
test('it renders without a checkbox or reprint action when none are offered', () => {
  render(<TagCard tag={minted} />);

  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /reprint/i })).not.toBeInTheDocument();
});

test('it offers selection and reprint when the host wants them', () => {
  const onToggle = jest.fn();
  const onReprint = jest.fn();

  render(<TagCard tag={minted} selected={false} onToggle={onToggle} onReprint={onReprint} />);

  fireEvent.click(screen.getByRole('checkbox'));
  expect(onToggle).toHaveBeenCalledWith(minted.serial_id);

  fireEvent.click(screen.getByRole('button', { name: /reprint/i }));
  expect(onReprint).toHaveBeenCalledWith(minted);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern="TagCard"
```

Expected: FAIL — `Cannot find module './TagCard'`.

- [ ] **Step 3: Write the component**

Create `frontend/src/admin/components/tags/TagCard.jsx`:

```jsx
import React from 'react';
import Barcode from 'react-barcode';

/**
 * One barcode card: the code, whose identity it is, and what has happened to
 * it.
 *
 * Rendered by two hosts with different needs — the per-product tag sheet,
 * which shows only tags we minted and offers no selection, and the printing
 * page, which shows all three tiers with checkboxes and a reprint action.
 * Every interactive part is therefore opt-in: pass no `onToggle` and there is
 * no checkbox. The card never decides on its own what may be printed; that
 * rule lives on the server in TagPrintPolicy.
 *
 * The card is sized for print, which is too small for a handheld to read off
 * a monitor. `onZoom` opens it at scanning size — see BarcodeZoom.
 */

const TIER_LABELS = {
  minted: 'Our tag',
  supplier: 'Supplier code',
  manufacturer: 'Manufacturer serial',
};

/* "Sent", never "printed". The server stamps when a job leaves for the
   printer; whether a sticker reached a carton is not something it can know,
   and a jam would make the stronger word a lie. */
function printState(tag) {
  const count = Number(tag?.print_count || 0);

  if (!count || !tag?.last_printed_at) return 'Not yet sent';

  const when = new Date(tag.last_printed_at).toLocaleDateString(undefined, {
    day: 'numeric', month: 'short',
  });

  return `Sent ${count}× · last ${when}`;
}

export default function TagCard({
  tag,
  productName,
  selected,
  onToggle,
  onZoom,
  onReprint,
}) {
  if (!tag) return null;

  const name = productName || tag.product_name;
  const tier = tag.tier || 'minted';

  return (
    <div className={`bsr-tag-card${selected ? ' bsr-tag-card--selected' : ''}`}>
      {onToggle && (
        <label className="bsr-tag-card-select no-print">
          <input
            type="checkbox"
            checked={Boolean(selected)}
            onChange={() => onToggle(tag.serial_id)}
            aria-label={`Select ${tag.serial_number}`}
          />
        </label>
      )}

      {name && <div className="bsr-tag-card-product">{name}</div>}

      {tag.tier && (
        <span className={`bsr-tag-card-tier bsr-tag-card-tier--${tier} no-print`}>
          {TIER_LABELS[tier] || TIER_LABELS.minted}
        </span>
      )}

      <button
        type="button"
        className="bsr-tag-card-zoom"
        onClick={() => onZoom?.(tag)}
        aria-label={`Enlarge tag ${tag.serial_number} for scanning`}
      >
        <Barcode
          value={tag.serial_number}
          format="CODE128"
          height={48}
          fontSize={12}
          displayValue
        />
        <span className="bsr-tag-card-enlarge no-print">Tap to enlarge</span>
      </button>

      {tag.tier && (
        <div className="bsr-tag-card-state no-print">{printState(tag)}</div>
      )}

      {onReprint && (
        <button
          type="button"
          className="bsr-tag-card-reprint no-print"
          onClick={() => onReprint(tag)}
        >
          Reprint this label
        </button>
      )}
    </div>
  );
}
```

The tier badge and print state render only when the row carries a `tier` — queue rows do, and the per-product sheet's tags do not, so the old sheet keeps its exact appearance.

- [ ] **Step 4: Run the test to verify it passes**

```bash
npx react-scripts test --watchAll=false --testPathPattern="TagCard"
```

Expected: PASS, 6 tests.

- [ ] **Step 5: Make MaterialTagSheet render it**

In `frontend/src/admin/components/tags/MaterialTagSheet.jsx`, add the import:

```jsx
import TagCard from './TagCard';
```

and replace the grid block (the `.bsr-tag-sheet-grid` div and everything inside it) with:

```jsx
      <div className="bsr-tag-sheet-grid">
        {printableTags.map((tag) => (
          <TagCard
            key={tag.serial_number}
            tag={tag}
            productName={productName}
            onZoom={setZoomedTag}
          />
        ))}
      </div>
```

The `Barcode` import at the top of `MaterialTagSheet.jsx` is now unused — remove it, or CRA's lint will warn on build.

- [ ] **Step 6: Prove the extraction changed nothing**

```bash
npx react-scripts test --watchAll=false --testPathPattern="MaterialTagSheet|TagCard"
```

Expected: PASS. `MaterialTagSheet.test.jsx` must be green **without having been edited** — that is the whole point of this step.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/components/tags/TagCard.jsx frontend/src/admin/components/tags/TagCard.test.jsx frontend/src/admin/components/tags/MaterialTagSheet.jsx
git commit -m "refactor(labels): one card component for both tag grids"
```

---

## Task 10: Selection that survives paging

**Files:**
- Create: `frontend/src/admin/hooks/useTagSelection.js`
- Create: `frontend/src/admin/hooks/useTagSelection.test.js`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/hooks/useTagSelection.test.js`:

```js
import { renderHook, act } from '@testing-library/react';
import { useTagSelection } from './useTagSelection';

test('it toggles ids on and off', () => {
  const { result } = renderHook(() => useTagSelection());

  act(() => result.current.toggle(7));
  expect(result.current.isSelected(7)).toBe(true);
  expect(result.current.count).toBe(1);

  act(() => result.current.toggle(7));
  expect(result.current.isSelected(7)).toBe(false);
  expect(result.current.count).toBe(0);
});

/* The grid pages; the selection does not. A clerk who ticks four cartons on
   page one and three on page two is printing seven labels. */
test('a selection survives a page change', () => {
  const { result } = renderHook(() => useTagSelection());

  act(() => result.current.toggle(1));
  act(() => result.current.toggle(2));

  // page 2 renders entirely different rows; nothing tells the hook
  act(() => result.current.toggle(90));

  expect(result.current.count).toBe(3);
  expect(result.current.selectedIds).toEqual([1, 2, 90]);
});

test('selectAll replaces the selection with the given ids', () => {
  const { result } = renderHook(() => useTagSelection());

  act(() => result.current.toggle(1));
  act(() => result.current.selectAll([5, 6, 7]));

  expect(result.current.selectedIds).toEqual([5, 6, 7]);
});

test('clear empties it', () => {
  const { result } = renderHook(() => useTagSelection());

  act(() => result.current.selectAll([1, 2, 3]));
  act(() => result.current.clear());

  expect(result.current.count).toBe(0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern="useTagSelection"
```

Expected: FAIL — `Cannot find module './useTagSelection'`.

- [ ] **Step 3: Write the hook**

Create `frontend/src/admin/hooks/useTagSelection.js`:

```js
import { useCallback, useMemo, useState } from 'react';

/**
 * Which tags are ticked, held as ids rather than rows.
 *
 * Ids, because the grid pages: the row objects for page one are gone by the
 * time page two renders, and a selection built from them would empty itself
 * every time the clerk turned a page. A Set of ids outlives the rows it came
 * from, so ticking four cartons on one page and three on the next prints
 * seven labels.
 */
export function useTagSelection() {
  const [selected, setSelected] = useState(() => new Set());

  const toggle = useCallback((serialId) => {
    setSelected((current) => {
      const next = new Set(current);

      if (next.has(serialId)) {
        next.delete(serialId);
      } else {
        next.add(serialId);
      }

      return next;
    });
  }, []);

  /* Replaces rather than merges: this backs "select all matching filters",
     which is a statement about the whole filtered set, not an addition to
     whatever was ticked before it. */
  const selectAll = useCallback((serialIds) => {
    setSelected(new Set(serialIds));
  }, []);

  const clear = useCallback(() => setSelected(new Set()), []);

  const isSelected = useCallback((serialId) => selected.has(serialId), [selected]);

  const selectedIds = useMemo(() => Array.from(selected), [selected]);

  return {
    selectedIds,
    count: selected.size,
    isSelected,
    toggle,
    selectAll,
    clear,
  };
}

export default useTagSelection;
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
npx react-scripts test --watchAll=false --testPathPattern="useTagSelection"
```

Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/hooks/useTagSelection.js frontend/src/admin/hooks/useTagSelection.test.js
git commit -m "feat(labels): keep a tag selection across pages"
```

---

## Task 11: The filter bar

**Files:**
- Create: `frontend/src/admin/components/tags/TagFilterBar.jsx`

Presentational and fully controlled — the page owns the filter state and the URL sync. It is covered by the page tests in Task 13 rather than getting its own file, because every one of its behaviours is "the parent was told".

- [ ] **Step 1: Write the component**

Create `frontend/src/admin/components/tags/TagFilterBar.jsx`:

```jsx
import React from 'react';
import DateInput from '../DateInput';
import LocationSelector from '../LocationSelector';

/**
 * What to narrow the print queue down to.
 *
 * Fully controlled: every change is handed straight up to the page, which
 * owns the filter object, mirrors it into the URL and re-queries. Keeping no
 * state here is what lets a reload or a shared link land on the same list.
 */
export default function TagFilterBar({ filters, onChange, counts, allowedLocations = [] }) {
  const set = (key) => (value) => onChange({ ...filters, [key]: value });

  return (
    <div className="bpp-filters no-print">
      <div className="bpp-filter">
        <label htmlFor="bpp-type">Product type</label>
        <select
          id="bpp-type"
          value={filters.product_type || ''}
          onChange={(e) => set('product_type')(e.target.value)}
        >
          <option value="">All types</option>
          <option value="installation_material">Installation materials</option>
          <option value="appliance">Appliances</option>
        </select>
      </div>

      {/* DateInput hands back the raw change EVENT, not a value — it wires
          `onChange={onChange}` straight onto a native date input. Reading
          e.target.value here is required; passing the event through would
          store an Event object in the filter and send [object Object] to the
          API. */}
      <div className="bpp-filter">
        <label htmlFor="bpp-from">Received from</label>
        <DateInput
          id="bpp-from"
          value={filters.date_from || ''}
          onChange={(e) => set('date_from')(e.target.value)}
        />
      </div>

      <div className="bpp-filter">
        <label htmlFor="bpp-to">Received to</label>
        <DateInput
          id="bpp-to"
          value={filters.date_to || ''}
          onChange={(e) => set('date_to')(e.target.value)}
        />
      </div>

      {/* LocationSelector renders its own <label>Location</label> and its own
          fixed id, and takes no `id` prop — so it gets neither here, or the
          page would show the word twice. Unlike DateInput it hands back a
          VALUE (Number or null), not an event.

          It also does not fetch anything: `allowedLocations` must be supplied,
          and it degrades to a plain text input when that list is empty. The
          page sources it from useUserLocation() and passes it down. */}
      <div className="bpp-filter">
        <LocationSelector
          value={filters.location_id ? Number(filters.location_id) : null}
          onChange={(value) => set('location_id')(value != null ? String(value) : '')}
          allowedLocations={allowedLocations}
        />
      </div>

      <div className="bpp-filter">
        <label htmlFor="bpp-receipt">Receipt or PO</label>
        <input
          id="bpp-receipt"
          type="text"
          value={filters.receipt || ''}
          placeholder="RCV-… or PO-…"
          onChange={(e) => set('receipt')(e.target.value)}
        />
      </div>

      <div className="bpp-filter">
        <label htmlFor="bpp-q">Product</label>
        <input
          id="bpp-q"
          type="text"
          value={filters.q || ''}
          placeholder="Name or code"
          onChange={(e) => set('q')(e.target.value)}
        />
      </div>

      <div className="bpp-filter">
        <label htmlFor="bpp-state">Print state</label>
        <select
          id="bpp-state"
          value={filters.print_state || 'all'}
          onChange={(e) => set('print_state')(e.target.value)}
        >
          <option value="all">All</option>
          <option value="unsent">Not yet sent</option>
          <option value="sent">Sent to printer</option>
        </select>
      </div>

      {counts && (
        <p className="bpp-filter-counts" role="status">
          {counts.total} tag{counts.total === 1 ? '' : 's'} · {counts.unsent} not yet sent
        </p>
      )}
    </div>
  );
}
```

Both shared components were read before this was written, and the wiring above reflects what they actually do — the two differ, which is the trap:

| | `DateInput` | `LocationSelector` |
|---|---|---|
| `onChange` receives | the change **event** (`onChange={onChange}` on a native `<input type="date">`) | a **value**: `Number` or `null` |
| accepts `id`? | yes | no — it hardcodes `id="location-selector-input"` |
| renders its own label? | no | yes, the word "Location" |
| needs data passed in? | no | yes — `allowedLocations`, or it degrades to a text input |

Do not change either shared component to make them consistent; other pages depend on their current behaviour.

`allowedLocations` comes from the `useUserLocation()` hook, which `BarcodePrintingPage` calls in Task 13 and passes down. Follow the fallback shape used at `frontend/src/admin/pages/BarcodeScan.jsx:3179` when the hook's list is empty.

- [ ] **Step 2: Commit**

```bash
git add frontend/src/admin/components/tags/TagFilterBar.jsx
git commit -m "feat(labels): filter controls for the printing queue"
```

---

## Task 12: The selection bar

**Files:**
- Create: `frontend/src/admin/components/tags/TagSelectionBar.jsx`
- Test: `frontend/src/admin/components/tags/TagSelectionBar.test.jsx`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/components/tags/TagSelectionBar.test.jsx`:

```jsx
import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import TagSelectionBar from './TagSelectionBar';

const props = {
  count: 4,
  ineligibleCount: 0,
  printerReady: true,
  sending: false,
  onSelectAll: jest.fn(),
  onClear: jest.fn(),
  onSend: jest.fn(),
  onSavePdf: jest.fn(),
};

test('it renders nothing until something is selected', () => {
  const { container } = render(<TagSelectionBar {...props} count={0} />);

  expect(container.firstChild).toBeNull();
});

test('it counts the selection', () => {
  render(<TagSelectionBar {...props} />);

  expect(screen.getByText(/4 selected/i)).toBeInTheDocument();
});

/* Bulk printing refuses supplier and manufacturer codes. Saying so after the
   click means a clerk walks to the printer expecting 38 labels and finds 32. */
test('it warns before the click when part of the selection cannot be bulk printed', () => {
  render(<TagSelectionBar {...props} count={38} ineligibleCount={6} />);

  expect(screen.getByText(/6 are supplier or manufacturer codes/i)).toBeInTheDocument();
  expect(screen.getByText(/won't be included/i)).toBeInTheDocument();
});

test('it stays quiet when every selected tag is ours', () => {
  render(<TagSelectionBar {...props} count={38} ineligibleCount={0} />);

  expect(screen.queryByText(/won't be included/i)).not.toBeInTheDocument();
});

test('it sends and saves', () => {
  render(<TagSelectionBar {...props} />);

  fireEvent.click(screen.getByRole('button', { name: /send to printer/i }));
  expect(props.onSend).toHaveBeenCalled();

  fireEvent.click(screen.getByRole('button', { name: /save as pdf/i }));
  expect(props.onSavePdf).toHaveBeenCalled();
});

/* No printer configured means the direct-send button would always error. */
test('it offers only the PDF when no printer is configured', () => {
  render(<TagSelectionBar {...props} printerReady={false} />);

  expect(screen.queryByRole('button', { name: /send to printer/i })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: /save as pdf/i })).toBeInTheDocument();
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npx react-scripts test --watchAll=false --testPathPattern="TagSelectionBar"
```

Expected: FAIL — module not found.

- [ ] **Step 3: Write the component**

Create `frontend/src/admin/components/tags/TagSelectionBar.jsx`:

```jsx
import React from 'react';

/**
 * What is ticked, and what will happen to it.
 *
 * The bar states the shape of the selection before the click, not after.
 * Bulk printing accepts only tags we minted, so a selection holding supplier
 * codes will print fewer labels than it holds — and a clerk who learns that
 * at the printer has already walked there. The server enforces the rule; this
 * only makes it visible in time to matter.
 */
export default function TagSelectionBar({
  count,
  ineligibleCount,
  truncatedTotal,
  printerReady,
  sending,
  onSelectAll,
  onClear,
  onSend,
  onSavePdf,
}) {
  if (!count) return null;

  return (
    <div className="bpp-selection no-print" role="region" aria-label="Selected tags">
      <span className="bpp-selection-count">{count} selected</span>

      <button type="button" className="bpp-selection-link" onClick={onSelectAll}>
        Select all matching filters
      </button>
      <button type="button" className="bpp-selection-link" onClick={onClear}>
        Clear
      </button>

      <div className="bpp-selection-actions">
        {printerReady && (
          <button
            type="button"
            className="bpp-selection-print"
            disabled={sending}
            onClick={onSend}
          >
            {sending ? 'Sending…' : 'Send to printer'}
          </button>
        )}
        <button type="button" className="bpp-selection-pdf" onClick={onSavePdf}>
          Save as PDF
        </button>
      </div>

      {ineligibleCount > 0 && (
        <p className="bpp-selection-warning" role="status">
          {ineligibleCount} are supplier or manufacturer codes and won't be included in a
          bulk print. Use “Reprint this label” on the card to print one of those.
        </p>
      )}

      {/* The cap is stated, never silent: a clerk who believes 340 are
          selected and gets 200 discovers the other 140 at the shelf. */}
      {truncatedTotal > 0 && (
        <p className="bpp-selection-warning" role="status">
          {count} of {truncatedTotal} selected — print these, then select the rest.
        </p>
      )}
    </div>
  );
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
npx react-scripts test --watchAll=false --testPathPattern="TagSelectionBar"
```

Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/tags/TagSelectionBar.jsx frontend/src/admin/components/tags/TagSelectionBar.test.jsx
git commit -m "feat(labels): say what a bulk print will leave out before the click"
```

---

## Task 13: The page

**Files:**
- Create: `frontend/src/admin/pages/BarcodePrintingPage.jsx`
- Create: `frontend/src/admin/pages/BarcodePrintingPage.test.jsx`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/pages/BarcodePrintingPage.test.jsx`:

```jsx
import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import BarcodePrintingPage from './BarcodePrintingPage';
import { barcodeLabelsAPI } from '../services/api';

jest.mock('../services/api', () => ({
  barcodeLabelsAPI: {
    status: jest.fn(),
    queue: jest.fn(),
    queueIds: jest.fn(),
    print: jest.fn(),
  },
}));

const row = (overrides = {}) => ({
  serial_id: 1,
  serial_number: 'INT-PO-2026-237-01',
  supplier_barcode: null,
  tier: 'minted',
  bulk_printable: true,
  product_id: 5,
  product_name: 'Copper Tube',
  product_type: 'installation_material',
  location_name: 'Main',
  receiving_number: 'RCV-1',
  receiving_date: '2026-09-01',
  print_count: 0,
  last_printed_at: null,
  ...overrides,
});

const queueResponse = (rows) => ({
  data: rows,
  pagination: { current_page: 1, last_page: 1, per_page: 24, total: rows.length },
  counts: { total: rows.length, unsent: rows.length },
});

const renderPage = () => render(
  <MemoryRouter>
    <BarcodePrintingPage />
  </MemoryRouter>
);

beforeEach(() => {
  jest.clearAllMocks();
  barcodeLabelsAPI.status.mockResolvedValue({ data: { configured: true } });
  barcodeLabelsAPI.queue.mockResolvedValue(queueResponse([row()]));
});

test('it loads the queue and shows the tags', async () => {
  renderPage();

  expect(await screen.findByText('Copper Tube')).toBeInTheDocument();
  expect(screen.getByText(/our tag/i)).toBeInTheDocument();
});

test('changing a filter re-queries', async () => {
  renderPage();
  await screen.findByText('Copper Tube');

  fireEvent.change(screen.getByLabelText(/product type/i), {
    target: { value: 'appliance' },
  });

  await waitFor(() => {
    expect(barcodeLabelsAPI.queue).toHaveBeenLastCalledWith(
      expect.objectContaining({ product_type: 'appliance' }),
      1,
      expect.any(Number),
    );
  });
});

/* The count the bar shows must be the count the server will actually print,
   which is why bulk_printable comes from the server rather than being
   guessed from the tier string in the browser. */
test('it warns when the selection holds codes bulk cannot print', async () => {
  barcodeLabelsAPI.queue.mockResolvedValue(queueResponse([
    row(),
    row({ serial_id: 2, serial_number: 'SUP-9911', tier: 'supplier', bulk_printable: false }),
  ]));

  renderPage();
  await screen.findAllByText('Copper Tube');

  fireEvent.click(screen.getByLabelText('Select INT-PO-2026-237-01'));
  fireEvent.click(screen.getByLabelText('Select SUP-9911'));

  expect(await screen.findByText(/1 are supplier or manufacturer codes/i)).toBeInTheDocument();
});

test('sending a selection prints in bulk mode', async () => {
  barcodeLabelsAPI.print.mockResolvedValue({ message: 'Sent.' });

  renderPage();
  await screen.findByText('Copper Tube');

  fireEvent.click(screen.getByLabelText('Select INT-PO-2026-237-01'));
  fireEvent.click(screen.getByRole('button', { name: /send to printer/i }));

  await waitFor(() => {
    expect(barcodeLabelsAPI.print).toHaveBeenCalledWith([1], 'bulk');
  });
});

/* The whole reason single mode exists: one damaged supplier label, replaced
   deliberately, without a bulk run putting a second sticker on every carton
   in the delivery. */
test('reprinting one card uses single mode', async () => {
  barcodeLabelsAPI.queue.mockResolvedValue(queueResponse([
    row({ serial_id: 2, serial_number: 'SUP-9911', tier: 'supplier', bulk_printable: false }),
  ]));
  barcodeLabelsAPI.print.mockResolvedValue({ message: 'Sent.' });

  renderPage();
  await screen.findByText(/supplier code/i);

  fireEvent.click(screen.getByRole('button', { name: /reprint this label/i }));

  await waitFor(() => {
    expect(barcodeLabelsAPI.print).toHaveBeenCalledWith([2], 'single');
  });
});

test('select all matching asks the server for the ids', async () => {
  barcodeLabelsAPI.queueIds.mockResolvedValue({
    data: { serial_ids: [1, 2, 3], total: 3, truncated: false, max: 200 },
  });

  renderPage();
  await screen.findByText('Copper Tube');

  fireEvent.click(screen.getByLabelText('Select INT-PO-2026-237-01'));
  fireEvent.click(screen.getByRole('button', { name: /select all matching/i }));

  expect(await screen.findByText(/3 selected/i)).toBeInTheDocument();
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npx react-scripts test --watchAll=false --testPathPattern="BarcodePrintingPage"
```

Expected: FAIL — module not found.

- [ ] **Step 3: Write the page**

Create `frontend/src/admin/pages/BarcodePrintingPage.jsx`:

```jsx
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Pagination from '../components/Pagination';
import TagCard from '../components/tags/TagCard';
import TagFilterBar from '../components/tags/TagFilterBar';
import TagSelectionBar from '../components/tags/TagSelectionBar';
import BarcodeZoom from '../components/tags/BarcodeZoom';
import { useLabelPrinter } from '../hooks/useLabelPrinter';
import { useTagSelection } from '../hooks/useTagSelection';
import { barcodeLabelsAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/barcode_printing.css';

/**
 * Print labels for stock that has been received.
 *
 * Every identity a receipt produced shows up here — the tags we minted, the
 * supplier's own codes, and appliance serials from the manufacturer — because
 * a clerk holding an unreadable label needs to find it whoever printed it
 * first. Nothing on this page mints an identifier; see TagPrintPolicy on the
 * server for what may be reprinted and why the two modes differ.
 *
 * Filters live in the URL. A print run is long enough that someone will
 * reload, hand the screen over, or send the link to whoever is at the
 * printer, and all three should land on the same list.
 */

const PER_PAGE = 24;

const FILTER_KEYS = [
  'product_type', 'date_from', 'date_to',
  'location_id', 'receipt', 'q', 'print_state',
];

export default function BarcodePrintingPage() {
  const [searchParams, setSearchParams] = useSearchParams();

  const filters = useMemo(() => {
    const current = {};
    FILTER_KEYS.forEach((key) => {
      const value = searchParams.get(key);
      if (value) current[key] = value;
    });
    return current;
  }, [searchParams]);

  const page = Number(searchParams.get('page') || 1);

  const [rows, setRows] = useState([]);
  const [pagination, setPagination] = useState(null);
  const [counts, setCounts] = useState(null);
  const [loading, setLoading] = useState(true);
  const [zoomedTag, setZoomedTag] = useState(null);
  const [truncatedTotal, setTruncatedTotal] = useState(0);

  const { printerReady, sending, sendToPrinter, savePdf } = useLabelPrinter();
  const selection = useTagSelection();

  useEffect(() => {
    let active = true;
    setLoading(true);

    barcodeLabelsAPI.queue(filters, page, PER_PAGE)
      .then((res) => {
        if (!active) return;
        setRows(res?.data || []);
        setPagination(res?.pagination || null);
        setCounts(res?.counts || null);
      })
      .catch((e) => {
        if (active) toast.error(e?.message || 'Could not load the tags.');
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => { active = false; };
  }, [filters, page]);

  /* Filters and the page number share the query string, so a filter change
     must reset the page — page 7 of a narrower list is usually empty. */
  const applyFilters = useCallback((next) => {
    const params = new URLSearchParams();

    FILTER_KEYS.forEach((key) => {
      if (next[key]) params.set(key, next[key]);
    });

    setSearchParams(params);
  }, [setSearchParams]);

  const goToPage = useCallback((nextPage) => {
    const params = new URLSearchParams(searchParams);
    params.set('page', String(nextPage));
    setSearchParams(params);
  }, [searchParams, setSearchParams]);

  /* Counted from what the server said about each row, not from the tier
     string: bulk_printable IS the server's answer, and a second opinion
     computed here could disagree with what actually prints. Only rows on
     screen can be counted — ids picked up by "select all" are not held as
     rows — so a select-all selection reports what this page can see. */
  const ineligibleCount = useMemo(() => rows.filter(
    (row) => selection.isSelected(row.serial_id) && !row.bulk_printable,
  ).length, [rows, selection]);

  const selectedTags = useMemo(
    () => selection.selectedIds.map((id) => ({ serial_id: id })),
    [selection.selectedIds],
  );

  const handleSelectAll = useCallback(async () => {
    try {
      const res = await barcodeLabelsAPI.queueIds(filters);
      const ids = res?.data?.serial_ids || [];

      selection.selectAll(ids);
      setTruncatedTotal(res?.data?.truncated ? res.data.total : 0);
    } catch (e) {
      toast.error(e?.message || 'Could not select the matching tags.');
    }
  }, [filters, selection]);

  const handleClear = useCallback(() => {
    selection.clear();
    setTruncatedTotal(0);
  }, [selection]);

  const handleReprint = useCallback(async (tag) => {
    try {
      const res = await barcodeLabelsAPI.print([tag.serial_id], 'single');
      toast.success(res?.message || 'Sent to the printer.');
    } catch (e) {
      toast.error(e?.message || 'The printer could not be reached. Save it as a PDF instead.');
    }
  }, []);

  return (
    <AdminLayout>
      <div className="bpp-page">
        <header className="bpp-header no-print">
          <h1>Barcode Printing</h1>
          <p className="bpp-subtitle">
            Every code recorded when stock came in. Scan one off the screen, or print a
            replacement when the label on the box is damaged.
          </p>
        </header>

        <TagFilterBar filters={filters} onChange={applyFilters} counts={counts} />

        <TagSelectionBar
          count={selection.count}
          ineligibleCount={ineligibleCount}
          truncatedTotal={truncatedTotal}
          printerReady={printerReady}
          sending={sending}
          onSelectAll={handleSelectAll}
          onClear={handleClear}
          onSend={() => sendToPrinter(selectedTags, 'bulk')}
          onSavePdf={() => savePdf(rows.filter((row) => selection.isSelected(row.serial_id)))}
        />

        {loading && <p className="bpp-status">Loading tags…</p>}

        {!loading && rows.length === 0 && (
          <p className="bpp-status">No tags match these filters.</p>
        )}

        <div className="bpp-grid">
          {rows.map((row) => (
            <TagCard
              key={row.serial_id}
              tag={row}
              selected={selection.isSelected(row.serial_id)}
              onToggle={selection.toggle}
              onZoom={setZoomedTag}
              onReprint={handleReprint}
            />
          ))}
        </div>

        {pagination && (
          <Pagination
            currentPage={pagination.current_page}
            totalPages={pagination.last_page}
            totalItems={pagination.total}
            pageSize={pagination.per_page}
            onPageChange={goToPage}
            label="tags"
          />
        )}

        {zoomedTag && (
          <BarcodeZoom
            value={zoomedTag.serial_number}
            label={zoomedTag.product_name}
            onClose={() => setZoomedTag(null)}
          />
        )}
      </div>
    </AdminLayout>
  );
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
npx react-scripts test --watchAll=false --testPathPattern="BarcodePrintingPage"
```

Expected: PASS, 6 tests. If the filter test fails on the third argument, check that `PER_PAGE` is what the page passes.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/pages/BarcodePrintingPage.jsx frontend/src/admin/pages/BarcodePrintingPage.test.jsx
git commit -m "feat(labels): a page for finding and printing stock-in barcodes"
```

---

## Task 14: Route, navigation and styles

**Files:**
- Create: `frontend/src/admin/styles/barcode_printing.css`
- Modify: `frontend/src/App.jsx`
- Modify: `frontend/src/admin/components/Sidebar.jsx`

- [ ] **Step 1: Write the stylesheet**

Create `frontend/src/admin/styles/barcode_printing.css`:

```css
/* The printing page. Card styling itself lives in inventory_management.css
   under .bsr-tag-card — shared with the per-product sheet, so a card looks
   the same wherever it is rendered. Only what is new to this page is here. */

.bpp-page { padding: 24px; }

.bpp-header h1 { margin: 0 0 4px; font-size: 1.5rem; }
.bpp-subtitle { margin: 0 0 20px; color: #64748b; max-width: 60ch; }

.bpp-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 12px 16px;
  align-items: flex-end;
  padding: 16px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  margin-bottom: 16px;
}

.bpp-filter { display: flex; flex-direction: column; gap: 4px; }
.bpp-filter label { font-size: 0.75rem; color: #475569; font-weight: 600; }
.bpp-filter input, .bpp-filter select {
  padding: 6px 10px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  min-width: 160px;
}

.bpp-filter-counts {
  margin: 0;
  flex-basis: 100%;
  color: #475569;
  font-size: 0.875rem;
}

/* Sticky: on a 200-carton delivery the grid is long, and the print action
   must stay reachable without scrolling back to the top. */
.bpp-selection {
  position: sticky;
  top: 0;
  z-index: 5;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  padding: 12px 16px;
  margin-bottom: 16px;
  background: #f1f5f9;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
}

.bpp-selection-count { font-weight: 700; }
.bpp-selection-link {
  background: none;
  border: none;
  color: #2563eb;
  cursor: pointer;
  text-decoration: underline;
  padding: 0;
}
.bpp-selection-actions { margin-left: auto; display: flex; gap: 8px; }
.bpp-selection-print, .bpp-selection-pdf {
  padding: 8px 14px;
  border-radius: 6px;
  border: 1px solid #2563eb;
  cursor: pointer;
}
.bpp-selection-print { background: #2563eb; color: #fff; }
.bpp-selection-pdf { background: #fff; color: #2563eb; }

.bpp-selection-warning {
  flex-basis: 100%;
  margin: 0;
  color: #92400e;
  font-size: 0.875rem;
}

.bpp-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 16px;
}

.bpp-status { padding: 32px; text-align: center; color: #64748b; }

/* Tier badge and print state, on the shared card. */
.bsr-tag-card-tier {
  display: inline-block;
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  padding: 2px 8px;
  border-radius: 999px;
  margin-bottom: 6px;
}
.bsr-tag-card-tier--minted { background: #dbeafe; color: #1e40af; }
.bsr-tag-card-tier--supplier { background: #fef3c7; color: #92400e; }
.bsr-tag-card-tier--manufacturer { background: #ede9fe; color: #5b21b6; }

.bsr-tag-card-state { font-size: 0.75rem; color: #64748b; margin-top: 6px; }
.bsr-tag-card--selected { outline: 2px solid #2563eb; }
.bsr-tag-card-select { position: absolute; top: 8px; left: 8px; }
.bsr-tag-card-reprint {
  margin-top: 8px;
  background: none;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  padding: 4px 10px;
  cursor: pointer;
  font-size: 0.8rem;
}

@media print {
  .no-print { display: none !important; }
}
```

`.bsr-tag-card` needs `position: relative` for the checkbox to sit on it. Check `inventory_management.css` — if it is not already relative, add `.bsr-tag-card { position: relative; }` to this file rather than editing the shared rule.

- [ ] **Step 2: Add the route**

In `frontend/src/App.jsx`, beside the other inventory routes (near line 145):

```jsx
          <Route path="/admin/barcode-printing" element={<RoleRoute><BarcodePrintingPage /></RoleRoute>} />
```

with the matching import beside the other page imports:

```jsx
import BarcodePrintingPage from './admin/pages/BarcodePrintingPage';
```

Follow whatever import style the neighbouring pages use — if they are lazy-loaded, match that.

- [ ] **Step 3: Add the navigation entry**

In `frontend/src/admin/components/Sidebar.jsx`, add to the prefetch map beside `'/admin/inventory'`:

```js
  '/admin/barcode-printing': () => import('../pages/BarcodePrintingPage'),
```

and to the `INVENTORY INTELLIGENCE` group's items, after `Inventory Monitoring`:

```js
      { name: 'Barcode Printing', path: '/admin/barcode-printing', icon: 'scan' },
```

- [ ] **Step 4: Verify the page loads in the real app**

```bash
cd frontend
npm start
```

Sign in, click **Barcode Printing** in the sidebar, and confirm:
- tags load and barcodes render
- changing a filter narrows the grid and the URL updates
- ticking cards updates the count; the count survives a page change
- a supplier-code card shows the amber badge and offers *Reprint this label*
- selecting a mix shows the "won't be included" warning

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/styles/barcode_printing.css frontend/src/App.jsx frontend/src/admin/components/Sidebar.jsx
git commit -m "feat(labels): route and navigation for the barcode printing page"
```

---

## Task 15: Full verification

- [ ] **Step 1: Run the whole backend suite**

```bash
cd back-end
php artisan test
```

Expected: PASS. Pay attention to `MaterialTagServiceTest`, `ScannedMaterialKeepsSupplierCodeTest`, `MaterialTagsInStockHistoryTest` and `StockInHistoryShowsTagsTest` — all four describe behaviour this work was not supposed to touch. A failure there means minting or `AttachesGeneratedTags` was changed.

- [ ] **Step 2: Run the whole frontend suite**

```bash
cd frontend
npx react-scripts test --watchAll=false
```

Expected: PASS, with `MaterialTagSheet.test.jsx` green and unedited.

- [ ] **Step 3: Confirm the spec is covered**

Walk the spec's five sections against what exists:

- Section 1 (tiers, two gates) — `TagPrintPolicy`, Tasks 2-3
- Section 2 (four columns, stamping rules, PDF does not stamp) — Tasks 1, 3, 4
- Section 3 (queue + ids endpoints, filters, 200 cap) — Tasks 5-7
- Section 4 (route, filter bar, selection bar, grid, shared card) — Tasks 9-14
- Section 5 (tests) — every task above

- [ ] **Step 4: Commit anything outstanding**

```bash
git status
```

Expected: clean, apart from the two unrelated files that were already modified before this work started (`frontend/src/admin/PurchaseOrderPage.jsx`, `frontend/src/admin/styles/po_recommendation.css`). Leave those alone.

---

## Notes for the implementer

**What this work must not change.** `MaterialTagService`, `ReceivingPostingService` and `AttachesGeneratedTags` are all deliberately untouched. Their docblocks argue at length that a supplier-labelled carton must not receive a second identifier, and that argument still holds — this feature shows and reprints identities that already exist, and mints none. If a task seems to want a change in any of those three files, re-read the spec's "Decisions and rejected alternatives" before making it.

**The stamp is not proof of a label.** `print_count` means "a job left for the printer". It cannot know whether a sticker reached a carton, and the PDF path is not observed at all. Every user-facing string says "sent" for that reason. If you find yourself writing "printed" in the UI, the word is claiming more than the data supports.

**The server owns the print rule.** `bulk_printable` on a queue row and the warning in the selection bar are conveniences. `TagPrintPolicy::printable()` is the enforcement, and it runs on every request regardless of what the browser believed.
