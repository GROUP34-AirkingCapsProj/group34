<?php

namespace Tests\Feature\Transfer;

use App\Models\ItemSerial;

class SerialIssuableScopeTest extends TransferTestCase
{
    public function test_received_serial_in_stock_is_issuable(): void
    {
        $loc  = $this->makeLocation('SR-A');
        $prod = $this->makeAppliance();
        ItemSerial::create([
            'product_id'    => $prod->product_id,
            'location_id'   => $loc->location_id,
            'serial_number' => 'SN-OK',
            'serial_type'   => 'received',
            'status_id'     => 26, // inventory: In Stock
        ]);

        $found = ItemSerial::issuableAt($loc->location_id, 'SN-OK')->first();
        $this->assertNotNull($found);
    }

    public function test_transferred_serial_is_not_issuable(): void
    {
        $loc  = $this->makeLocation('SR-A');
        $prod = $this->makeAppliance();
        ItemSerial::create([
            'product_id'    => $prod->product_id,
            'location_id'   => $loc->location_id,
            'serial_number' => 'SN-INTRANSIT',
            'serial_type'   => 'transferred', // dispatched, not yet received
            'status_id'     => 26,
        ]);

        $found = ItemSerial::issuableAt($loc->location_id, 'SN-INTRANSIT')->first();
        $this->assertNull($found);
    }
}
