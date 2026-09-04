<?php

namespace Tests\Feature\Inventory;

use App\Models\ApplianceDetail;
use App\Models\ConsumableDetail;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Services\ZplLabelBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sending a barcode sticker to the Zebra.
 *
 * A browser cannot pick a printer or suppress the print dialog, so a sticker
 * that goes straight to the label printer has to leave from the server. What
 * matters here is that the server decides what may be reprinted — the browser
 * only nominates — and that an unreachable printer reads as a printer problem
 * rather than as a broken button.
 */
class BarcodeLabelPrintTest extends TestCase
{
    use DatabaseTransactions;

    private string $spoolFile;

    protected function setUp(): void
    {
        parent::setUp();

        /* A file stands in for the printer share: the service writes the job
           as bytes either way, so this exercises the real transport. */
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

    private function user(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username' => 't_' . uniqid(), 'first_name' => 'T', 'last_name' => 'U',
            'email' => uniqid() . '@e.test', 'password_hash' => bcrypt('x'), 'role_id' => $roleId,
        ]);
    }

    private function location(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'L', 'location_type' => 'warehouse',
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

        return ItemSerial::create(array_merge([
            'product_id'    => $product->product_id,
            'location_id'   => $this->location()->location_id,
            'serial_number' => 'INT-TEST-' . uniqid(),
            'serial_type'   => 'received',
            'status_id'     => ItemSerial::IN_STOCK_STATUS_ID,
        ], $overrides));
    }

    private function applianceSerial(): ItemSerial
    {
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Split Type AC',
            'product_type' => 'appliance',
        ]);
        ApplianceDetail::create(['product_id' => $product->product_id, 'is_serialized' => 1]);

        return ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $this->location()->location_id,
            'serial_number' => 'MFG-' . uniqid(),
            'serial_type'   => 'received',
            'status_id'     => ItemSerial::IN_STOCK_STATUS_ID,
        ]);
    }

    public function test_it_sends_the_tag_to_the_printer_as_zpl(): void
    {
        $tag = $this->materialTag();

        $res = $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.printed', 1);

        $job = file_get_contents($this->spoolFile);
        $this->assertStringContainsString('^XA', $job);
        $this->assertStringContainsString('^BCN', $job, 'the job must draw a CODE128 symbol');
        $this->assertStringContainsString($tag->serial_number, $job);
        $this->assertStringContainsString('Copper Tube', $job, 'the sticker names the product');
    }

    public function test_one_label_per_tag(): void
    {
        $first  = $this->materialTag();
        $second = $this->materialTag();

        $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$first->serial_id, $second->serial_id],
        ])->assertOk();

        $job = file_get_contents($this->spoolFile);
        $this->assertSame(2, substr_count($job, '^XZ'), 'each sticker is its own label format');
    }

    /* The browser nominates; the server decides. Each of the three below is a
       tag that must never be reprinted, and the client asking nicely does not
       change that. */

    public function test_it_refuses_a_manufacturers_serial(): void
    {
        $serial = $this->applianceSerial();

        $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$serial->serial_id],
        ])->assertStatus(422);

        $this->assertSame('', (string) file_get_contents($this->spoolFile));
    }

    public function test_it_refuses_a_tag_the_supplier_printed(): void
    {
        $tag = $this->materialTag(['supplier_barcode' => 'SUP-9911']);

        $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ])->assertStatus(422);
    }

    public function test_it_refuses_a_retired_tag(): void
    {
        $tag = $this->materialTag(['serial_type' => 'issued']);

        $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ])->assertStatus(422);
    }

    public function test_an_unconfigured_printer_reads_as_a_printer_problem(): void
    {
        config(['labelprinter.transport' => null, 'labelprinter.share' => null]);
        $tag = $this->materialTag();

        $res = $this->actingAs($this->user())->postJson('/api/barcode-labels/print', [
            'serial_ids' => [$tag->serial_id],
        ]);

        /* 503, not 500: the request was fine and the PDF path still works, so
           this must not surface as a bug in what the user clicked. */
        $res->assertStatus(503);
        $this->assertStringContainsString('PDF', $res->json('message'));
    }

    public function test_status_tells_the_ui_whether_to_offer_direct_printing(): void
    {
        $this->actingAs($this->user())->getJson('/api/barcode-labels/status')
            ->assertOk()->assertJsonPath('data.configured', true);

        config(['labelprinter.transport' => null]);

        $this->actingAs($this->user())->getJson('/api/barcode-labels/status')
            ->assertOk()->assertJsonPath('data.configured', false);
    }

    public function test_the_symbol_fits_the_stock_at_a_scannable_bar_width(): void
    {
        $builder = new ZplLabelBuilder(widthMm: 100, heightMm: 25, dpi: 203);
        $longestRealCode = 'INT-PO-20260828-ZIW7-237-01';

        /* 2 dots at 203 dpi is 0.25mm — the narrowest bar a handheld reads
           dependably, and the reason the stock is 100mm rather than 50mm. */
        $this->assertGreaterThanOrEqual(2, $builder->moduleDots($longestRealCode));
        $this->assertFalse($builder->overflows($longestRealCode));
    }
}
