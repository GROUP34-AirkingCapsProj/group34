<?php

namespace Tests\Feature\Settings;

use App\Models\ConsumableDetail;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A printer per location, set by an admin rather than by a developer.
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

    /* Pins the map-level merge: saving one location must not disturb another. */
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
       retires a printer, and it must survive a round trip as "off". The
       entry is replaced wholesale, so the old host must not survive either -
       a partial save must not be able to resurrect it. */
    public function test_a_location_can_be_saved_as_having_no_printer(): void
    {
        $location = $this->location();

        SystemSetting::setLabelPrinter($location->location_id, ['transport' => 'tcp', 'host' => '10.0.0.1']);
        SystemSetting::setLabelPrinter($location->location_id, ['transport' => '']);

        $printer = SystemSetting::labelPrinter($location->location_id);
        $this->assertSame('', $printer['transport']);
        $this->assertSame('', $printer['host']);
    }

    /* Every other setter in this file normalises on write. A settings form
       posts numbers as strings, and a strict comparison against the int
       default would otherwise fail depending on whether a location was ever
       configured. */
    public function test_a_port_arriving_as_a_string_reads_back_as_an_int(): void
    {
        $location = $this->location();

        SystemSetting::setLabelPrinter($location->location_id, ['transport' => 'tcp', 'port' => '9100']);

        $this->assertSame(9100, SystemSetting::labelPrinter($location->location_id)['port']);
    }

    /* JSON round-trips a whole float back as an int, so a float timeout
       would split int/string depending on whether the value was fractional.
       An int-only field removes the ambiguity at the source. */
    public function test_a_timeout_arriving_as_a_string_reads_back_as_an_int(): void
    {
        $location = $this->location();

        SystemSetting::setLabelPrinter($location->location_id, ['transport' => 'tcp', 'timeout' => '5']);

        $this->assertSame(5, SystemSetting::labelPrinter($location->location_id)['timeout']);
    }

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
}
