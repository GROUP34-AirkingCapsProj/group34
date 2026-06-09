<?php

namespace Tests\Feature\Transfer;

use App\Services\TransferService;

class ReceiveScanResolveTest extends TransferTestCase
{
    public function test_receive_scan_resolves_serial_to_line(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 1);
        [$sn] = $this->makeSerials($from->location_id, $prod->product_id, 1);
        $user = $this->makeUser();
        $svc  = new TransferService();

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 1],
        ]);

        // Dispatch the unit so it's In Transit with a 'transferred' serial.
        $svc->recordDispatchScan($ist, $sn, null, $user->user_id);
        $svc->dispatch($ist->fresh('details'), $user->user_id);

        // Receive with NO transfer_detail_id — must resolve from the serial.
        $res = $svc->recordReceiveScan($ist->fresh('details'), $sn, null, $user->user_id);

        $this->assertSame(
            $ist->details->first()->transfer_detail_id,
            $res['transfer_detail_id']
        );
        $this->assertSame(1, $res['quantity_received']);
    }
}
