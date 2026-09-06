# Label Printer Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every location its own barcode printer, configurable by an admin in the UI instead of by a developer in `.env`, and send each print job to the printer at the location of the tags being printed.

**Architecture:** The printer address moves from `config/labelprinter.php` into a `label_printers` map on `SystemSetting`, keyed by location id — the shape `archive_config` already uses. `LabelPrinterService` becomes location-aware, and `LabelPrintController` resolves which printer to use from the tags it is about to print rather than from the user or from a global setting. `config/labelprinter.php` keeps only the label geometry, which stays in code because the frontend hardcodes the same numbers.

**Tech Stack:** Laravel 11 (PHP 8.2, MySQL), PHPUnit via `php artisan test`. React 18 (CRA), Jest + `@testing-library/react` via `npx react-scripts test`.

**Spec:** `docs/superpowers/specs/2026-09-07-label-printer-settings-design.md`

---

## Before You Start

Read the spec, especially its "Assumption this design rests on" section. Then read:

- `back-end/app/Services/LabelPrinterService.php` — all of it. You are changing where it reads its address and adding a probe.
- `back-end/app/Http/Controllers/Api/LabelPrintController.php` — `print()` and `status()` both change.
- `back-end/app/Models/SystemSetting.php:52-70` — `getArchiveConfig` / `setArchiveConfig`. The printer map copies this shape exactly.

### Running the tests

Backend, from `back-end/`:

```bash
php artisan test --filter=LabelPrinter
```

Frontend, from `frontend/`:

```bash
npx react-scripts test --watchAll=false --testPathPattern=LabelPrinterPanel
```

### Local environment quirks (this repo, this machine)

- **There is no printer, and none of these tests need one.** Reachability is asserted against `127.0.0.1` on a closed port, and the "share" transport writes to a temp file — which is how `BarcodeLabelPrintTest` already fakes a printer today.
- The test database `airkingV5_test` is separate and already populated. No migration is added by this plan, so nothing needs migrating.
- `SystemSetting` rows persist between tests unless the test wraps in a transaction. Both test files you touch already use `DatabaseTransactions`; any **new** test file must too, or a stored printer will leak into the next test.

### The thing that will bite you in Task 2

`BarcodeLabelPrintTest` and `BarcodePrintStampTest` — 23 tests — currently point the printer at a temp file with `config(['labelprinter.transport' => 'share', ...])`. Once the address comes from the database, that stops working and all 23 fail.

Worse, their tag helpers call `Location::create(...)` **per tag**, so a two-tag test puts its tags at two different locations. Under the new resolution rule that is a mixed-location batch and gets refused. Task 2 fixes both, and the two changes must land together with the service change or the suite stays red.

---

## File Structure

### Backend — create

| File | Responsibility |
|---|---|
| `app/Http/Controllers/Api/LabelPrinterSettingsController.php` | Read every location's printer, save one, test one |
| `tests/Feature/Settings/LabelPrinterSettingsTest.php` | Storage, service resolution, endpoints, access |

### Backend — modify

| File | Change |
|---|---|
| `app/Models/SystemSetting.php` | `labelPrinter()`, `setLabelPrinter()`, `labelPrinters()` |
| `app/Services/LabelPrinterService.php` | Location-aware `isConfigured`/`send`, new `probe` |
| `app/Http/Controllers/Api/LabelPrintController.php` | Resolve the printer from the tags; per-location `status` |
| `config/labelprinter.php` | Drop the address keys, keep the geometry |
| `.env.example` | Drop the four `LABEL_PRINTER_*` address keys |
| `routes/api.php` | Three routes beside the other `/system/*` settings |
| `tests/Feature/Inventory/BarcodeLabelPrintTest.php` | One shared location with a printer |
| `tests/Feature/Inventory/BarcodePrintStampTest.php` | Same |

### Frontend — create

| File | Responsibility |
|---|---|
| `src/admin/components/LabelPrinterPanel.jsx` | The `sc-card` listing each location's printer |
| `src/admin/components/LabelPrinterPanel.test.jsx` | Rendering, editing, saving, test results |

### Frontend — modify

| File | Change |
|---|---|
| `src/admin/services/api.js` | `labelPrintersAPI` |
| `src/admin/pages/SystemConfiguration.jsx` | Render the panel |

Not touched: `ZplLabelBuilder`, `TagPrintPolicy`, `barcodeLabel.js`, and the Barcode Printing page. The job is where a print goes, not what a sticker is.

---

## Task 1: A printer per location on SystemSetting

**Files:**
- Modify: `back-end/app/Models/SystemSetting.php`
- Test: `back-end/tests/Feature/Settings/LabelPrinterSettingsTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Settings/LabelPrinterSettingsTest.php`:

```php
<?php

namespace Tests\Feature\Settings;

use App\Models\Location;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A printer per location, set by an admin rather than by a developer.
 *
 * None of this needs a printer to exist. Reachability is asserted against a
 * closed port, which is the shape of the first real attempt anyway.
 */
class LabelPrinterSettingsTest extends TestCase
{
    use DatabaseTransactions;

    private function location(string $name = 'Warehouse A'): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => $name,
            'location_type' => 'warehouse',
        ]);
    }

    public function test_a_location_with_nothing_stored_reads_as_unset(): void
    {
        $location = $this->location();

        $printer = SystemSetting::labelPrinter($location->location_id);

        $this->assertSame('', $printer['transport']);
        $this->assertSame(9100, $printer['port']);
    }

    public function test_it_stores_and_reads_back_one_location(): void
    {
        $location = $this->location();

        SystemSetting::setLabelPrinter($location->location_id, [
            'transport' => 'tcp', 'host' => '192.168.1.50', 'port' => 9100, 'timeout' => 5,
        ]);

        $printer = SystemSetting::labelPrinter($location->location_id);

        $this->assertSame('tcp', $printer['transport']);
        $this->assertSame('192.168.1.50', $printer['host']);
    }

    /* The load-bearing case for the storage shape. Warehouses are configured
       one at a time, months apart, and a save that replaced the map instead of
       merging into it would silently unplug every other warehouse. */
    public function test_saving_one_location_leaves_another_alone(): void
    {
        $a = $this->location('Warehouse A');
        $b = $this->location('Warehouse B');

        SystemSetting::setLabelPrinter($a->location_id, ['transport' => 'tcp', 'host' => '10.0.0.1']);
        SystemSetting::setLabelPrinter($b->location_id, ['transport' => 'tcp', 'host' => '10.0.0.2']);

        $this->assertSame('10.0.0.1', SystemSetting::labelPrinter($a->location_id)['host']);
        $this->assertSame('10.0.0.2', SystemSetting::labelPrinter($b->location_id)['host']);
    }

    /* Empty transport is a saved state, not an absence: it is how an admin
       retires a printer, and it must survive a round trip as "off". */
    public function test_a_location_can_be_saved_as_having_no_printer(): void
    {
        $location = $this->location();

        SystemSetting::setLabelPrinter($location->location_id, ['transport' => 'tcp', 'host' => '10.0.0.1']);
        SystemSetting::setLabelPrinter($location->location_id, ['transport' => '']);

        $this->assertSame('', SystemSetting::labelPrinter($location->location_id)['transport']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd back-end
php artisan test --filter=LabelPrinterSettingsTest
```

Expected: FAIL — `Call to undefined method App\Models\SystemSetting::labelPrinter()`.

- [ ] **Step 3: Add the accessors**

In `back-end/app/Models/SystemSetting.php`, add the key beside the others:

```php
    public const KEY_LABEL_PRINTERS          = 'label_printers';
```

and these methods after `setArchiveConfig`:

```php
    // ── Label printers, one per location ──────────────────────

    /** Every field a connection has, with the values a location starts from. */
    public static function labelPrinterDefaults(): array
    {
        return ['transport' => '', 'host' => '', 'port' => 9100, 'share' => '', 'timeout' => 5];
    }

    /** The whole map, for a settings screen that lists every location. */
    public static function labelPrinters(): array
    {
        $all = static::getValue(self::KEY_LABEL_PRINTERS, []);

        return is_array($all) ? $all : [];
    }

    /**
     * One location's printer, merged over the defaults so a caller never has
     * to check whether a key is present. A location nobody has configured
     * reads as an empty transport, which every caller already treats as
     * "no printer here".
     */
    public static function labelPrinter(int $locationId): array
    {
        $stored = static::labelPrinters()[(string) $locationId] ?? [];

        return array_merge(
            static::labelPrinterDefaults(),
            is_array($stored) ? $stored : [],
        );
    }

    /**
     * Merged into the map, never replacing it: warehouses are configured one
     * at a time, months apart, and a save that replaced the map would unplug
     * every other warehouse silently.
     *
     * Keys are cast to string because the map round-trips through JSON, where
     * object keys are always strings — reading back with an int key would miss
     * everything written with a string one.
     */
    public static function setLabelPrinter(int $locationId, array $printer): void
    {
        $all = static::labelPrinters();

        $all[(string) $locationId] = array_merge(
            static::labelPrinterDefaults(),
            array_intersect_key($printer, static::labelPrinterDefaults()),
        );

        static::setValue(self::KEY_LABEL_PRINTERS, $all);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
php artisan test --filter=LabelPrinterSettingsTest
```

Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Models/SystemSetting.php back-end/tests/Feature/Settings/LabelPrinterSettingsTest.php
git commit -m "feat(printer): store a printer per location"
```

---

## Task 2: Send each job to the printer where the tags are

**Files:**
- Modify: `back-end/app/Services/LabelPrinterService.php`
- Modify: `back-end/app/Http/Controllers/Api/LabelPrintController.php`
- Modify: `back-end/config/labelprinter.php`
- Modify: `back-end/.env.example`
- Modify: `back-end/tests/Feature/Inventory/BarcodeLabelPrintTest.php`
- Modify: `back-end/tests/Feature/Inventory/BarcodePrintStampTest.php`
- Test: `back-end/tests/Feature/Settings/LabelPrinterSettingsTest.php`

This task is large and cannot be split: the service signature, the controller, and the two existing test files all move together or the suite stays red.

- [ ] **Step 1: Write the failing tests**

Add to `back-end/tests/Feature/Settings/LabelPrinterSettingsTest.php`. Add these imports at the top of the file:

```php
use App\Models\ConsumableDetail;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
```

and these helpers plus tests inside the class:

```php
    private function user(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username' => 't_' . uniqid(), 'first_name' => 'T', 'last_name' => 'U',
            'email' => uniqid() . '@e.test', 'password_hash' => bcrypt('x'), 'role_id' => $roleId,
        ]);
    }

    private function tagAt(Location $location): ItemSerial
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Tube',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);

        return ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $location->location_id,
            'serial_number' => 'INT-TEST-' . uniqid(),
            'serial_type'   => 'received',
            'status_id'     => ItemSerial::IN_STOCK_STATUS_ID,
        ]);
    }

    /** A file stands in for a printer share: the transport writes bytes either way. */
    private function spoolFor(Location $location): string
    {
        $file = tempnam(sys_get_temp_dir(), 'zpl');

        SystemSetting::setLabelPrinter($location->location_id, [
            'transport' => 'share', 'share' => $file,
        ]);

        return $file;
    }

    /* THE test for this feature. A resolver that read the user's location, or
       simply took the first configured printer, passes every other case in
       this file and fails only here — with labels coming out in a building
       nobody is standing in. */
    public function test_a_tag_prints_at_its_own_location_not_another(): void
    {
        $a = $this->location('Warehouse A');
        $b = $this->location('Warehouse B');

        $spoolA = $this->spoolFor($a);
        $spoolB = $this->spoolFor($b);

        $tag = $this->tagAt($b);

        $res = $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ]);

        $res->assertOk();

        $this->assertSame('', file_get_contents($spoolA), 'Warehouse A must not have printed');
        $this->assertStringContainsString($tag->serial_number, file_get_contents($spoolB));

        @unlink($spoolA);
        @unlink($spoolB);
    }

    public function test_a_batch_spanning_two_locations_is_refused(): void
    {
        $a = $this->location('Warehouse A');
        $b = $this->location('Warehouse B');

        $spoolA = $this->spoolFor($a);
        $this->spoolFor($b);

        $here  = $this->tagAt($a);
        $there = $this->tagAt($b);

        $res = $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$here->serial_id, $there->serial_id],
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('2 locations', $res->json('message'));

        /* Nothing left for the printer, so nothing may claim it did. */
        $this->assertSame('', file_get_contents($spoolA));
        $this->assertSame(0, (int) $here->fresh()->print_count);
        $this->assertNull($here->fresh()->last_printed_at);

        @unlink($spoolA);
    }

    public function test_a_location_with_no_printer_says_which_one(): void
    {
        $location = $this->location('Warehouse B');
        $tag      = $this->tagAt($location);

        $res = $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ]);

        $res->assertStatus(503);
        $this->assertStringContainsString('Warehouse B', $res->json('message'));
        $this->assertSame(0, (int) $tag->fresh()->print_count);
    }

    /* The location set is computed after the policy filter, not from the ids
       the client sent. A supplier code at another location is dropped by the
       policy before it can make this batch look mixed. */
    public function test_a_tag_the_policy_drops_cannot_make_a_batch_look_mixed(): void
    {
        $a = $this->location('Warehouse A');
        $b = $this->location('Warehouse B');

        $spoolA = $this->spoolFor($a);

        $mine     = $this->tagAt($a);
        $supplier = $this->tagAt($b);
        $supplier->update(['supplier_barcode' => 'SUP-' . uniqid()]);

        $res = $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$mine->serial_id, $supplier->serial_id],
            'mode'       => 'bulk',
        ]);

        $res->assertOk();
        $this->assertStringContainsString($mine->serial_number, file_get_contents($spoolA));

        @unlink($spoolA);
    }

    public function test_configured_is_answered_per_location(): void
    {
        $a = $this->location('Warehouse A');
        $b = $this->location('Warehouse B');

        $spool = $this->spoolFor($a);

        $printer = app(\App\Services\LabelPrinterService::class);

        $this->assertTrue($printer->isConfigured($a->location_id));
        $this->assertFalse($printer->isConfigured($b->location_id));

        @unlink($spool);
    }
```

- [ ] **Step 2: Run them to verify they fail**

```bash
php artisan test --filter=LabelPrinterSettingsTest
```

Expected: FAIL — `isConfigured()` takes no argument, and the print endpoint still reads the config file.

- [ ] **Step 3: Make the service location-aware**

Replace the whole of `back-end/app/Services/LabelPrinterService.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\LabelPrinterException;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Log;

/**
 * Hands a finished ZPL job to the Zebra at one location.
 *
 * Two ways in, because a label printer is wired up one of two ways in
 * practice: on the network with an IP, or shared from the machine it is
 * plugged into. Both are a stream of bytes — the printer parses the ZPL
 * itself, so nothing here needs to understand the job it is carrying.
 *
 * Every method takes a location, because each warehouse has its own printer
 * and a label has to come out of the machine standing beside the stock. There
 * is deliberately no default and no fallback address: a printer that is not
 * the right one is worse than no printer, since nobody is watching the paper
 * come out of it.
 */
class LabelPrinterService
{
    public function isConfigured(int $locationId): bool
    {
        $printer = SystemSetting::labelPrinter($locationId);

        return in_array($printer['transport'], ['tcp', 'share'], true)
            && $this->target($printer) !== null;
    }

    /**
     * @throws LabelPrinterException when the printer is unreachable or unset.
     */
    public function send(string $payload, int $locationId): void
    {
        $printer = SystemSetting::labelPrinter($locationId);

        if (! $this->isConfigured($locationId)) {
            throw new LabelPrinterException(
                'No printer is set up for this location. Save the stickers as a PDF, '
                . 'or ask an admin to add one in System Configuration.'
            );
        }

        match ($printer['transport']) {
            'tcp'   => $this->sendOverTcp($payload, $printer),
            'share' => $this->sendToShare($payload, $printer),
        };
    }

    /**
     * Can this location's printer be reached?
     *
     * Sends nothing: no label is consumed and no stock needs to be loaded,
     * which matters on the day a printer comes out of its box. It therefore
     * cannot tell you the printer is out of paper — only that something is
     * listening where you said it would be.
     *
     * @throws LabelPrinterException with a message naming what to check.
     */
    public function probe(int $locationId): void
    {
        $printer = SystemSetting::labelPrinter($locationId);

        if (! $this->isConfigured($locationId)) {
            throw new LabelPrinterException(
                'No printer is set up for this location. Choose a connection type and save first.'
            );
        }

        if ($printer['transport'] === 'tcp') {
            $host = (string) $printer['host'];
            $port = (int) $printer['port'];

            $errorCode = 0;
            $errorMessage = '';
            $socket = @fsockopen($host, $port, $errorCode, $errorMessage, (float) $printer['timeout']);

            if ($socket === false) {
                throw new LabelPrinterException(
                    "{$host}:{$port} did not answer. Check the printer is switched on and on the same network as the server."
                );
            }

            fclose($socket);

            return;
        }

        /* Checked rather than opened: opening a printer share can start an
           empty job. This proves the path is writable, not that the printer
           is well — which is all a reachability check ever promises. */
        $share = (string) $printer['share'];
        $writable = file_exists($share) ? is_writable($share) : is_writable(dirname($share));

        if (! $writable) {
            throw new LabelPrinterException(
                "{$share} could not be written to. Check the share exists and the server has permission."
            );
        }
    }

    private function sendOverTcp(string $payload, array $printer): void
    {
        $host = (string) $printer['host'];
        $port = (int) $printer['port'];

        $errorCode = 0;
        $errorMessage = '';
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, (float) $printer['timeout']);

        if ($socket === false) {
            Log::warning('Label printer unreachable', [
                'host' => $host, 'port' => $port, 'error' => $errorMessage,
            ]);

            throw new LabelPrinterException(
                "The barcode printer at {$host}:{$port} did not answer. Check that it is on and on the network."
            );
        }

        try {
            /* A short write is not an error on a socket — it just means the
               printer's buffer filled, so the rest has to follow. */
            $remaining = $payload;
            while ($remaining !== '') {
                $written = @fwrite($socket, $remaining);
                if ($written === false || $written === 0) {
                    throw new LabelPrinterException(
                        'The barcode printer stopped accepting the job partway through. Check paper and ribbon.'
                    );
                }
                $remaining = substr($remaining, $written);
            }
        } finally {
            fclose($socket);
        }
    }

    private function sendToShare(string $payload, array $printer): void
    {
        $share = (string) $printer['share'];

        if (@file_put_contents($share, $payload) === false) {
            Log::warning('Label printer share not writable', ['share' => $share]);

            throw new LabelPrinterException(
                "The barcode printer at {$share} could not be written to. Check that the share exists and the server can reach it."
            );
        }
    }

    /** The address for the configured transport, or null when it is missing. */
    private function target(array $printer): ?string
    {
        $target = match ($printer['transport']) {
            'tcp'   => $printer['host'],
            'share' => $printer['share'],
            default => null,
        };

        return $target === null || $target === '' ? null : (string) $target;
    }
}
```

- [ ] **Step 4: Resolve the printer in the controller**

In `back-end/app/Http/Controllers/Api/LabelPrintController.php`, add the import:

```php
use App\Models\Location;
```

Replace `status()`:

```php
    /**
     * Whether the UI should offer a direct-print button at all, and which
     * locations have a printer.
     *
     * Scoped the way everything else here is: a branch user is told about
     * their own location, a centralized one about all of them. `configured`
     * is the summary the button is drawn from — at least one printer among
     * the locations whose tags this user can print.
     */
    public function status(Request $request)
    {
        $user = $request->user();

        $locations = Location::query()
            ->when(! $user->isCentralized(), fn ($q) => $q->where('location_id', $user->scopedLocationId() ?? 0))
            ->orderBy('location_name')
            ->get(['location_id', 'location_name'])
            ->map(fn (Location $location) => [
                'location_id'   => $location->location_id,
                'location_name' => $location->location_name,
                'configured'    => $this->printer->isConfigured($location->location_id),
            ]);

        return $this->success([
            'configured' => $locations->contains('configured', true),
            'locations'  => $locations->values(),
        ]);
    }
```

Then in `print()`, replace the `$builder = new ZplLabelBuilder(` line and everything down to `$this->printer->send($zpl);` with:

```php
        /* Which printer this goes to is decided by where the tags are, not by
           who is printing: a label exists to be stuck on a box, and the box is
           at that location. Computed from $printable — what will actually
           print — so a tag the policy already dropped cannot make an
           otherwise-clean batch look mixed. */
        $locationIds = $printable->pluck('location_id')->unique()->values();

        if ($locationIds->count() > 1) {
            return $this->error(
                "These {$printable->count()} tags are at {$locationIds->count()} locations. "
                . 'Filter to one location and print again.',
                422,
            );
        }

        $locationId = (int) $locationIds->first();

        if (! $this->printer->isConfigured($locationId)) {
            $name = Location::find($locationId)?->location_name ?? 'this location';

            return $this->error(
                "No printer is set up for {$name}. Save the stickers as a PDF, or ask an admin to add one.",
                503,
            );
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
            $this->printer->send($zpl, $locationId);
```

The frontend needs no change for this. `useLabelPrinter` reads
`res?.data?.configured`, which `status()` still returns — it has gained a
`locations` list beside it, not lost the flag the button is drawn from.

- [ ] **Step 5: Drop the address from config and .env.example**

Replace `back-end/config/labelprinter.php` with:

```php
<?php

/*
|--------------------------------------------------------------------------
| Barcode label stock
|--------------------------------------------------------------------------
|
| Only the geometry lives here. Which printer a location uses is stored in
| the database and edited in System Configuration — see
| SystemSetting::labelPrinter(). One address in a file cannot serve several
| warehouses, and a fallback address is worse than none: it would send a
| location's labels to a machine in another building.
|
| These dimensions must match the frontend's LABEL_WIDTH_MM / LABEL_HEIGHT_MM
| in src/admin/utils/barcodeLabel.js, or the PDF and the Zebra disagree about
| what a sticker is. That is why they are here and not in the settings panel:
| an admin who could change one but not the other would break both.
|
*/

return [

    'label' => [
        'width_mm'  => (int) env('LABEL_WIDTH_MM', 100),
        'height_mm' => (int) env('LABEL_HEIGHT_MM', 25),
        /* 203 dpi is the common Zebra head; 300 dpi models exist. Wrong here
           and every measurement above is off by half or a third. */
        'dpi'       => (int) env('LABEL_PRINTER_DPI', 203),
    ],

];
```

In `back-end/.env.example`, delete these four lines and the comment above them that mentions `LABEL_PRINTER_TRANSPORT`:

```
LABEL_PRINTER_TRANSPORT=
LABEL_PRINTER_HOST=
LABEL_PRINTER_PORT=9100
LABEL_PRINTER_TIMEOUT=5
LABEL_PRINTER_SHARE=
```

Keep `LABEL_WIDTH_MM`, `LABEL_HEIGHT_MM` and `LABEL_PRINTER_DPI`. Replace the deleted comment with:

```
# Label stock. Must match LABEL_WIDTH_MM / LABEL_HEIGHT_MM in
# frontend/src/admin/utils/barcodeLabel.js. Which printer each location uses
# is set in the app, under System Configuration — not here.
```

- [ ] **Step 6: Point the existing print tests at a database printer**

In `back-end/tests/Feature/Inventory/BarcodeLabelPrintTest.php`, add the import:

```php
use App\Models\SystemSetting;
```

Add a property beside `$spoolFile`:

```php
    private Location $printerLocation;
```

Replace `setUp()`:

```php
    protected function setUp(): void
    {
        parent::setUp();

        /* A file stands in for the printer share: the service writes the job
           as bytes either way, so this exercises the real transport.

           One location for the whole file, with one printer: a job now goes
           to the printer where its tags are, so tags scattered across a
           location each would be refused as a mixed batch. */
        $this->spoolFile       = tempnam(sys_get_temp_dir(), 'zpl');
        $this->printerLocation = $this->location();

        SystemSetting::setLabelPrinter($this->printerLocation->location_id, [
            'transport' => 'share',
            'share'     => $this->spoolFile,
        ]);
    }
```

In `materialTag()`, replace the location line:

```php
            'location_id'   => $this->printerLocation->location_id,
```

and the same line in `applianceSerial()`.

Apply the identical change to `back-end/tests/Feature/Inventory/BarcodePrintStampTest.php`: add the `SystemSetting` import, add the `$printerLocation` property, set it up the same way, and in `materialTag()` delete the local `$location = Location::create(...)` block, replacing the `'location_id'` line with `$this->printerLocation->location_id`.

Two cases in `BarcodeLabelPrintTest` deliberately use a different location and must keep doing so — `test_it_refuses_a_tag_at_another_users_location` and `test_it_accepts_a_tag_at_the_users_own_location`. Leave their explicit `location()` calls alone; the first is refused by the location scope before any printer is resolved, and for the second, register a printer for the location it creates by calling:

```php
        SystemSetting::setLabelPrinter($location->location_id, [
            'transport' => 'share', 'share' => $this->spoolFile,
        ]);
```

right after that test creates its location.

- [ ] **Step 7: Run the whole printing suite**

```bash
php artisan test --filter="LabelPrinter|BarcodeLabelPrint|BarcodePrintStamp"
```

Expected: PASS. All 23 pre-existing cases green against a database-configured printer, plus the 5 new resolution cases.

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Services/LabelPrinterService.php back-end/app/Http/Controllers/Api/LabelPrintController.php back-end/config/labelprinter.php back-end/.env.example back-end/tests/Feature/Inventory/BarcodeLabelPrintTest.php back-end/tests/Feature/Inventory/BarcodePrintStampTest.php back-end/tests/Feature/Settings/LabelPrinterSettingsTest.php
git commit -m "feat(printer): send each job to the printer where the tags are"
```

---

## Task 3: The settings endpoints

**Files:**
- Create: `back-end/app/Http/Controllers/Api/LabelPrinterSettingsController.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/Settings/LabelPrinterSettingsTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `back-end/tests/Feature/Settings/LabelPrinterSettingsTest.php`:

```php
    private function admin(): User
    {
        $user = $this->user();
        $user->role_id = 1; // admin
        $user->save();

        return $user;
    }

    public function test_it_lists_every_location_with_its_printer(): void
    {
        $a = $this->location('Warehouse A');
        $b = $this->location('Warehouse B');

        SystemSetting::setLabelPrinter($a->location_id, ['transport' => 'tcp', 'host' => '10.0.0.1']);

        $res = $this->actingAs($this->admin())->getJson('/api/system/label-printers');

        $res->assertOk();

        $rows = collect($res->json('data.locations'));

        $this->assertTrue($rows->firstWhere('location_id', $a->location_id)['configured']);
        /* Listed even though nobody has set it up — the panel exists to show
           which warehouses still need one. */
        $this->assertFalse($rows->firstWhere('location_id', $b->location_id)['configured']);
    }

    public function test_it_reports_the_label_stock_it_expects(): void
    {
        $res = $this->actingAs($this->admin())->getJson('/api/system/label-printers');

        $res->assertOk();
        $this->assertSame(100, $res->json('data.label.width_mm'));
        $this->assertSame(25, $res->json('data.label.height_mm'));
    }

    public function test_an_admin_can_save_one_location(): void
    {
        $location = $this->location();

        $res = $this->actingAs($this->admin())
            ->putJson("/api/system/label-printers/{$location->location_id}", [
                'transport' => 'tcp', 'host' => '192.168.1.50', 'port' => 9100, 'timeout' => 5,
            ]);

        $res->assertOk();
        $this->assertSame('192.168.1.50', SystemSetting::labelPrinter($location->location_id)['host']);
    }

    public function test_a_non_admin_cannot_save(): void
    {
        $location = $this->location();

        $res = $this->actingAs($this->user())
            ->putJson("/api/system/label-printers/{$location->location_id}", [
                'transport' => 'tcp', 'host' => '192.168.1.50',
            ]);

        $res->assertForbidden();
        $this->assertSame('', SystemSetting::labelPrinter($location->location_id)['transport']);
    }

    public function test_a_network_printer_needs_an_address(): void
    {
        $location = $this->location();

        $this->actingAs($this->admin())
            ->putJson("/api/system/label-printers/{$location->location_id}", ['transport' => 'tcp'])
            ->assertStatus(422);
    }

    public function test_a_share_printer_needs_a_path(): void
    {
        $location = $this->location();

        $this->actingAs($this->admin())
            ->putJson("/api/system/label-printers/{$location->location_id}", ['transport' => 'share'])
            ->assertStatus(422);
    }

    /* An unknown location means a stale page or a hand-made request. Writing
       it anyway would leave settings under an id nothing lists, which nobody
       could then find or remove. */
    public function test_saving_an_unknown_location_is_a_404(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/api/system/label-printers/99999999', ['transport' => ''])
            ->assertNotFound();
    }
```

- [ ] **Step 2: Run them to verify they fail**

```bash
php artisan test --filter=LabelPrinterSettingsTest
```

Expected: FAIL — 404 on every route; they do not exist yet.

- [ ] **Step 3: Write the controller**

Create `back-end/app/Http/Controllers/Api/LabelPrinterSettingsController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\LabelPrinterException;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\SystemSetting;
use App\Services\LabelPrinterService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Which printer each location uses.
 *
 * This exists so that setting up a printer, or replacing a dead one, is a job
 * for whoever runs the warehouse rather than for whoever has file access to
 * the server. Reading is open to any signed-in user, matching the other
 * settings on the same screen; writing is admin-only.
 */
class LabelPrinterSettingsController extends Controller
{
    use ApiResponse;

    public function __construct(private LabelPrinterService $printer)
    {
    }

    /**
     * Every location, configured or not — the panel's job is to show which
     * warehouses still need a printer, so the ones without are the point.
     */
    public function index()
    {
        $locations = Location::query()
            ->orderBy('location_name')
            ->get(['location_id', 'location_name', 'location_type'])
            ->map(function (Location $location) {
                $printer = SystemSetting::labelPrinter($location->location_id);

                return [
                    'location_id'   => $location->location_id,
                    'location_name' => $location->location_name,
                    'location_type' => $location->location_type,
                    'printer'       => $printer,
                    'configured'    => $this->printer->isConfigured($location->location_id),
                ];
            });

        return $this->success([
            'locations' => $locations,
            /* Read-only, and shown so whoever orders labels knows what stock
               to buy. Changing it is a developer's job: the frontend hardcodes
               the same numbers for the on-screen and PDF labels. */
            'label' => [
                'width_mm'  => (int) config('labelprinter.label.width_mm'),
                'height_mm' => (int) config('labelprinter.label.height_mm'),
                'dpi'       => (int) config('labelprinter.label.dpi'),
            ],
        ]);
    }

    public function update(Request $request, int $location)
    {
        $this->findLocation($location);

        $validated = $request->validate([
            'transport' => 'present|in:tcp,share,',
            'host'      => 'nullable|string|max:255|required_if:transport,tcp',
            'port'      => 'nullable|integer|min:1|max:65535',
            'share'     => 'nullable|string|max:255|required_if:transport,share',
            'timeout'   => 'nullable|numeric|min:1|max:30',
        ]);

        SystemSetting::setLabelPrinter($location, [
            'transport' => (string) ($validated['transport'] ?? ''),
            'host'      => (string) ($validated['host'] ?? ''),
            'port'      => (int) ($validated['port'] ?? 9100),
            'share'     => (string) ($validated['share'] ?? ''),
            'timeout'   => (float) ($validated['timeout'] ?? 5),
        ]);

        return $this->success([
            'printer'    => SystemSetting::labelPrinter($location),
            'configured' => $this->printer->isConfigured($location),
        ], 'Printer settings saved.');
    }

    /**
     * Always 200. An unreachable printer is an answer, not a server error —
     * the panel renders the message as information, and a 5xx would be
     * swallowed as a failed request instead of read.
     */
    public function test(int $location)
    {
        $this->findLocation($location);

        try {
            $this->printer->probe($location);
        } catch (LabelPrinterException $e) {
            return $this->success(['ok' => false, 'message' => $e->getMessage()]);
        }

        return $this->success([
            'ok'      => true,
            'message' => 'The printer answered. Print a tag to check paper and ribbon.',
        ]);
    }

    private function findLocation(int $location): Location
    {
        return Location::findOrFail($location);
    }
}
```

- [ ] **Step 4: Add the routes**

In `back-end/routes/api.php`, beside the other `/system/*` settings around line 143:

```php
    Route::get('/system/label-printers', [LabelPrinterSettingsController::class, 'index']);
    Route::middleware('role:admin')->put('/system/label-printers/{location}', [LabelPrinterSettingsController::class, 'update']);
    Route::middleware('role:admin')->post('/system/label-printers/{location}/test', [LabelPrinterSettingsController::class, 'test']);
```

and the import at the top of the file:

```php
use App\Http\Controllers\Api\LabelPrinterSettingsController;
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
php artisan test --filter=LabelPrinterSettingsTest
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/LabelPrinterSettingsController.php back-end/routes/api.php back-end/tests/Feature/Settings/LabelPrinterSettingsTest.php
git commit -m "feat(printer): let an admin set each location's printer"
```

---

## Task 4: The reachability check

**Files:**
- Test: `back-end/tests/Feature/Settings/LabelPrinterSettingsTest.php`

`probe()` was written in Task 2 and the endpoint in Task 3. This task proves the behaviour that matters on the day a printer arrives: a failure has to arrive as a readable sentence, not as a 500.

- [ ] **Step 1: Write the failing tests**

Add to `back-end/tests/Feature/Settings/LabelPrinterSettingsTest.php`:

```php
    /* The first real attempt will look exactly like this: an address that
       nothing is listening on. It must come back as a sentence, with a 200,
       because the panel renders the message rather than an error state. */
    public function test_an_unreachable_printer_answers_with_a_message(): void
    {
        $location = $this->location('Warehouse A');

        SystemSetting::setLabelPrinter($location->location_id, [
            'transport' => 'tcp',
            'host'      => '127.0.0.1',
            'port'      => 9101, // nothing listens here
            'timeout'   => 1,
        ]);

        $res = $this->actingAs($this->admin())
            ->postJson("/api/system/label-printers/{$location->location_id}/test");

        $res->assertOk();
        $this->assertFalse($res->json('data.ok'));
        $this->assertStringContainsString('127.0.0.1:9101', $res->json('data.message'));
    }

    public function test_testing_a_location_with_no_printer_says_so(): void
    {
        $location = $this->location('Warehouse B');

        $res = $this->actingAs($this->admin())
            ->postJson("/api/system/label-printers/{$location->location_id}/test");

        $res->assertOk();
        $this->assertFalse($res->json('data.ok'));
        $this->assertStringContainsString('No printer is set up', $res->json('data.message'));
    }

    /* A writable path is reachable, which is what the share transport means.
       This is the only success path assertable without hardware. */
    public function test_a_writable_share_reads_as_reachable(): void
    {
        $location = $this->location('Warehouse A');
        $spool    = $this->spoolFor($location);

        $res = $this->actingAs($this->admin())
            ->postJson("/api/system/label-printers/{$location->location_id}/test");

        $res->assertOk();
        $this->assertTrue($res->json('data.ok'));

        @unlink($spool);
    }

    public function test_a_non_admin_cannot_test_a_printer(): void
    {
        $location = $this->location();

        $this->actingAs($this->user())
            ->postJson("/api/system/label-printers/{$location->location_id}/test")
            ->assertForbidden();
    }
```

- [ ] **Step 2: Run them**

```bash
php artisan test --filter=LabelPrinterSettingsTest
```

Expected: PASS — `probe()` and the endpoint already exist, so these confirm the behaviour rather than drive it. If `test_an_unreachable_printer_answers_with_a_message` is slow, the timeout is not being read: check `probe()` passes `$printer['timeout']` to `fsockopen`.

- [ ] **Step 3: Commit**

```bash
git add back-end/tests/Feature/Settings/LabelPrinterSettingsTest.php
git commit -m "test(printer): prove an unreachable printer answers in a sentence"
```

---

## Task 5: The settings panel

**Files:**
- Modify: `frontend/src/admin/services/api.js`
- Create: `frontend/src/admin/components/LabelPrinterPanel.jsx`
- Create: `frontend/src/admin/components/LabelPrinterPanel.test.jsx`

- [ ] **Step 1: Extend the API client**

In `frontend/src/admin/services/api.js`, add beside `systemSettingsAPI`:

```js
/* Which printer each location uses. Reading is open to any signed-in user;
   saving and testing are admin-only on the server. */
export const labelPrintersAPI = {
  getAll: async () => apiRequest('/system/label-printers'),

  update: async (locationId, printer) =>
    apiRequest(`/system/label-printers/${locationId}`, {
      method: 'PUT',
      body: JSON.stringify(printer),
    }),

  test: async (locationId) =>
    apiRequest(`/system/label-printers/${locationId}/test`, { method: 'POST' }),
};
```

- [ ] **Step 2: Write the failing test**

Create `frontend/src/admin/components/LabelPrinterPanel.test.jsx`:

```jsx
import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import LabelPrinterPanel from './LabelPrinterPanel';
import { labelPrintersAPI } from '../services/api';

jest.mock('../services/api', () => ({
  labelPrintersAPI: { getAll: jest.fn(), update: jest.fn(), test: jest.fn() },
}));

jest.mock('../utils/toast', () => ({
  toast: { success: jest.fn(), error: jest.fn(), info: jest.fn(), warning: jest.fn() },
}));

const response = {
  data: {
    locations: [
      {
        location_id: 1, location_name: 'Warehouse A', location_type: 'warehouse',
        printer: { transport: 'tcp', host: '192.168.1.50', port: 9100, share: '', timeout: 5 },
        configured: true,
      },
      {
        location_id: 2, location_name: 'Warehouse B', location_type: 'warehouse',
        printer: { transport: '', host: '', port: 9100, share: '', timeout: 5 },
        configured: false,
      },
    ],
    label: { width_mm: 100, height_mm: 25, dpi: 203 },
  },
};

beforeEach(() => {
  jest.clearAllMocks();
  labelPrintersAPI.getAll.mockResolvedValue(response);
});

test('it lists every location, including the ones with no printer', async () => {
  render(<LabelPrinterPanel />);

  expect(await screen.findByText('Warehouse A')).toBeInTheDocument();
  expect(screen.getByText('Warehouse B')).toBeInTheDocument();
  expect(screen.getByText(/192\.168\.1\.50/)).toBeInTheDocument();
  expect(screen.getByText(/not set up/i)).toBeInTheDocument();
});

/* Whoever orders the labels reads this. It is not editable here on purpose —
   the sticker layout is built around these numbers. */
test('it states the label stock it expects', async () => {
  render(<LabelPrinterPanel />);

  expect(await screen.findByText(/100 × 25 mm/)).toBeInTheDocument();
});

test('saving a location sends only that location', async () => {
  labelPrintersAPI.update.mockResolvedValue({ data: { printer: {}, configured: true } });

  render(<LabelPrinterPanel />);
  fireEvent.click(await screen.findByRole('button', { name: /set up/i }));

  fireEvent.change(screen.getByLabelText(/address/i), { target: { value: '10.0.0.9' } });
  fireEvent.click(screen.getByRole('button', { name: /^save$/i }));

  await waitFor(() => {
    expect(labelPrintersAPI.update).toHaveBeenCalledWith(2, expect.objectContaining({
      transport: 'tcp', host: '10.0.0.9',
    }));
  });
});

/* The message is the whole point of the button — it says what to go and
   check — so it is rendered, not toasted away. */
test('a failed test renders its message', async () => {
  labelPrintersAPI.test.mockResolvedValue({
    data: { ok: false, message: '192.168.1.50:9100 did not answer. Check the printer is switched on.' },
  });

  render(<LabelPrinterPanel />);
  fireEvent.click(await screen.findByRole('button', { name: /test/i }));

  expect(await screen.findByText(/did not answer/i)).toBeInTheDocument();
});
```

- [ ] **Step 3: Run it to verify it fails**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern="LabelPrinterPanel"
```

Expected: FAIL — `Cannot find module './LabelPrinterPanel'`.

- [ ] **Step 4: Write the panel**

Create `frontend/src/admin/components/LabelPrinterPanel.jsx`:

```jsx
import React, { useCallback, useEffect, useState } from 'react';
import { labelPrintersAPI } from '../services/api';
import { toast } from '../utils/toast';

/**
 * Which printer each location uses.
 *
 * Every location is listed, including those with no printer, because the
 * panel's job is to show which warehouses still need one — a printer bought
 * next month is configured here rather than in a file on the server.
 *
 * The label stock is shown but not editable. The sticker layout is built
 * around those numbers in the frontend, so an admin able to change one but
 * not the other would make the PDF and the printer disagree about what a
 * sticker is.
 */

const TRANSPORTS = [
  { value: '', label: 'Not set up' },
  { value: 'tcp', label: 'Network (IP address)' },
  { value: 'share', label: 'Windows share or port' },
];

const describe = (printer) => {
  if (printer.transport === 'tcp') return `Network · ${printer.host}:${printer.port}`;
  if (printer.transport === 'share') return `Share · ${printer.share}`;
  return 'Not set up';
};

export default function LabelPrinterPanel() {
  const [rows, setRows] = useState([]);
  const [label, setLabel] = useState(null);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState(null);   // location_id being edited
  const [form, setForm] = useState(null);
  const [saving, setSaving] = useState(false);
  const [results, setResults] = useState({});     // location_id → { ok, message }

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await labelPrintersAPI.getAll();
      setRows(res?.data?.locations || []);
      setLabel(res?.data?.label || null);
    } catch (e) {
      toast.error(e?.message || 'Could not load the printer settings.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  const startEditing = (row) => {
    setEditing(row.location_id);
    setForm({ ...row.printer });
  };

  const save = async () => {
    setSaving(true);
    try {
      await labelPrintersAPI.update(editing, form);
      toast.success('Printer settings saved.');
      setEditing(null);
      setForm(null);
      await load();
    } catch (e) {
      toast.error(e?.message || 'Could not save the printer settings.');
    } finally {
      setSaving(false);
    }
  };

  const runTest = async (locationId) => {
    try {
      const res = await labelPrintersAPI.test(locationId);
      setResults((current) => ({ ...current, [locationId]: res?.data }));
    } catch (e) {
      setResults((current) => ({
        ...current,
        [locationId]: { ok: false, message: e?.message || 'The test could not be run.' },
      }));
    }
  };

  return (
    <div className="sc-card">
      <div className="sc-card-header">
        <h3>Barcode printers</h3>
      </div>

      <div className="sc-card-body">
        {loading && <p className="lpp-status">Loading printers…</p>}

        {!loading && rows.length === 0 && (
          <p className="lpp-status">No locations exist yet.</p>
        )}

        {rows.map((row) => (
          <div className="lpp-row" key={row.location_id}>
            <div className="lpp-row-head">
              <span className="lpp-location">{row.location_name}</span>
              <span className="lpp-detail">{describe(row.printer)}</span>

              <div className="lpp-actions">
                <button type="button" onClick={() => startEditing(row)}>
                  {row.configured ? 'Edit' : 'Set up'}
                </button>
                {row.configured && (
                  <button type="button" onClick={() => runTest(row.location_id)}>
                    Test
                  </button>
                )}
              </div>
            </div>

            {results[row.location_id] && (
              <p className={`lpp-result${results[row.location_id].ok ? ' lpp-result--ok' : ''}`} role="status">
                {results[row.location_id].message}
              </p>
            )}

            {editing === row.location_id && form && (
              <div className="lpp-form">
                <label htmlFor={`lpp-transport-${row.location_id}`}>Connection</label>
                <select
                  id={`lpp-transport-${row.location_id}`}
                  value={form.transport}
                  onChange={(e) => setForm({ ...form, transport: e.target.value })}
                >
                  {TRANSPORTS.map((t) => (
                    <option key={t.value} value={t.value}>{t.label}</option>
                  ))}
                </select>

                {form.transport === 'tcp' && (
                  <>
                    <label htmlFor={`lpp-host-${row.location_id}`}>Address</label>
                    <input
                      id={`lpp-host-${row.location_id}`}
                      type="text"
                      value={form.host || ''}
                      placeholder="192.168.1.50"
                      onChange={(e) => setForm({ ...form, host: e.target.value })}
                    />

                    <label htmlFor={`lpp-port-${row.location_id}`}>Port</label>
                    <input
                      id={`lpp-port-${row.location_id}`}
                      type="number"
                      value={form.port ?? 9100}
                      onChange={(e) => setForm({ ...form, port: Number(e.target.value) })}
                    />
                  </>
                )}

                {form.transport === 'share' && (
                  <>
                    <label htmlFor={`lpp-share-${row.location_id}`}>Share or port</label>
                    <input
                      id={`lpp-share-${row.location_id}`}
                      type="text"
                      value={form.share || ''}
                      placeholder="\\\\THIS-PC\\Zebra"
                      onChange={(e) => setForm({ ...form, share: e.target.value })}
                    />
                  </>
                )}

                <div className="lpp-form-actions">
                  <button type="button" onClick={() => { setEditing(null); setForm(null); }}>
                    Cancel
                  </button>
                  <button type="button" disabled={saving} onClick={save}>
                    {saving ? 'Saving…' : 'Save'}
                  </button>
                </div>
              </div>
            )}
          </div>
        ))}

        {label && (
          <p className="lpp-stock">
            Label stock <strong>{label.width_mm} × {label.height_mm} mm</strong> at {label.dpi} dpi.
            Order this size — the sticker layout is built around it, so changing it needs a developer.
          </p>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
npx react-scripts test --watchAll=false --testPathPattern="LabelPrinterPanel"
```

Expected: PASS, 4 tests.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/services/api.js frontend/src/admin/components/LabelPrinterPanel.jsx frontend/src/admin/components/LabelPrinterPanel.test.jsx
git commit -m "feat(printer): a settings panel listing every location's printer"
```

---

## Task 6: Mount the panel and verify

**Files:**
- Modify: `frontend/src/admin/pages/SystemConfiguration.jsx`
- Modify: `frontend/src/admin/styles/` — whichever stylesheet `SystemConfiguration.jsx` imports

- [ ] **Step 1: Render the panel**

In `frontend/src/admin/pages/SystemConfiguration.jsx`, add the import beside the other component imports:

```jsx
import LabelPrinterPanel from '../components/LabelPrinterPanel';
```

and render it after the `sc-card` whose header is `Password Reset Configuration` (around line 513–560), as a sibling `<LabelPrinterPanel />` — it renders its own `sc-card`, so it needs no wrapper.

- [ ] **Step 2: Style the panel**

Find which stylesheet `SystemConfiguration.jsx` imports:

```bash
cd frontend
grep -n "styles/" src/admin/pages/SystemConfiguration.jsx
```

Append to that file:

```css
/* Barcode printers: one row per location, expanding into a form. */
.lpp-status { color: #64748b; padding: 12px 0; }

.lpp-row { border-bottom: 1px solid #f1f5f9; padding: 10px 0; }
.lpp-row:last-of-type { border-bottom: none; }

.lpp-row-head { display: flex; align-items: center; gap: 12px; }
.lpp-location { font-weight: 600; min-width: 160px; }
.lpp-detail { color: #64748b; font-size: 0.875rem; flex: 1; }
.lpp-actions { display: flex; gap: 8px; }
.lpp-actions button {
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #fff;
  padding: 4px 12px;
  font-size: 0.8rem;
  cursor: pointer;
}

/* Read, not dismissed: the message names what to go and check. */
.lpp-result { margin: 8px 0 0; font-size: 0.85rem; color: #92400e; }
.lpp-result--ok { color: #15803d; }

.lpp-form {
  display: grid;
  grid-template-columns: 140px 1fr;
  gap: 8px 12px;
  align-items: center;
  padding: 12px 0 4px;
}
.lpp-form label { font-size: 0.8rem; color: #475569; font-weight: 600; }
.lpp-form input, .lpp-form select {
  padding: 6px 10px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
}
.lpp-form-actions {
  grid-column: 1 / -1;
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
.lpp-form-actions button {
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #fff;
  padding: 6px 14px;
  cursor: pointer;
}

.lpp-stock {
  margin: 14px 0 0;
  padding-top: 12px;
  border-top: 1px solid #f1f5f9;
  color: #64748b;
  font-size: 0.85rem;
}
```

- [ ] **Step 3: Run the whole backend suite**

```bash
cd back-end
php artisan test
```

Expected: PASS. Watch `BarcodeLabelPrintTest` and `BarcodePrintStampTest` especially — they were migrated in Task 2 and are the proof that moving the address did not change what printing does.

- [ ] **Step 4: Run the whole frontend suite**

```bash
cd frontend
npx react-scripts test --watchAll=false
```

Expected: PASS.

- [ ] **Step 5: Build**

```bash
npx cross-env CI=false react-scripts build
```

Expected: "Compiled with warnings" is fine — the repo has pre-existing `no-unused-vars` warnings. **No warning may name `LabelPrinterPanel.jsx` or `SystemConfiguration.jsx`.**

- [ ] **Step 6: Confirm no address key is read anywhere**

```bash
cd back-end
grep -rn "labelprinter.transport\|labelprinter.host\|labelprinter.share\|labelprinter.port\|labelprinter.timeout" app/ config/ tests/
```

Expected: **no matches.** A single leftover read means one code path still consults a file that no longer holds an address, which would silently behave as "no printer" forever.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/pages/SystemConfiguration.jsx frontend/src/admin/styles/
git commit -m "feat(printer): show the printer panel in system configuration"
```

---

## Notes for the implementer

**The load-bearing test is `test_a_tag_prints_at_its_own_location_not_another`.** A resolver that read the user's location, or took the first configured printer it found, passes everything else in this plan. It fails only there — and in production it fails as labels appearing in a building nobody is standing in, which nobody notices until the boxes are already wrong.

**Refusals must not stamp.** Both the mixed-location 422 and the no-printer 503 return before `send()`, so `stampPrinted()` never runs. This is the rule the print history already holds to for a dead printer: a job that never left must not leave a trail saying it did. Two tests assert `print_count` is still zero afterwards; if you move the location checks below the send, they will catch it.

**There is no printer, and none of this needs one.** Reachability is proven against `127.0.0.1` on a closed port and against a writable temp file. If you find yourself wanting hardware to finish a task, re-read the task — it is asserting the wrong thing.

**Do not move the label geometry into the panel.** `frontend/src/admin/utils/barcodeLabel.js` hardcodes the same width and height for the on-screen and PDF labels, and every function that uses them is synchronous. Making them server-driven is a change to label rendering, not to this feature, and half-doing it makes the PDF and the Zebra disagree about what a sticker is.

**This design assumes the printers are reachable from the server** — an IP on the same network, or a share the server can write to. A printer paired by Bluetooth or plugged by USB into a clerk's PC in another building cannot be reached by anything in this plan. That is recorded in the spec's "Assumption this design rests on", and it is the one discovery that would mean stopping and re-reading the spec rather than continuing.
