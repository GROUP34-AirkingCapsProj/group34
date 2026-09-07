<?php

namespace Tests\Feature\Transfer;

use App\Services\TransferService;

class ReceiveScanResolveTest extends TransferTestCase
{
    public function test_receive_scan_resolves_installation_material_by_barcode_to_line(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeInstallationMaterial();
        // Give the installation material a scannable barcode the lookup can find.
        // findProductByBarcodeCandidates checks products.barcode OR products.product_code.
        $prod->update(['barcode' => 'CON-BARCODE-1']);
        $this->setStock($from->location_id, $prod->product_id, 5);
        $user = $this->makeUser();
        $svc  = new TransferService();

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 3],
        ]);

        // Dispatch 3 units (installation material scan uses qty), then move to In Transit.
        $svc->recordDispatchScan($ist, 'CON-BARCODE-1', null, $user->user_id, 3);
        $svc->dispatch($ist->fresh('details'), $user->user_id);

        // Receive with NO transfer_detail_id — must resolve the installation material by barcode.
        $res = $svc->recordReceiveScan($ist->fresh('details'), 'CON-BARCODE-1', null, $user->user_id, 1);

        $this->assertSame($ist->details->first()->transfer_detail_id, $res['transfer_detail_id']);
        $this->assertSame(1, $res['quantity_received']);
    }

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
