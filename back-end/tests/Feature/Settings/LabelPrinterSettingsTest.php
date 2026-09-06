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
