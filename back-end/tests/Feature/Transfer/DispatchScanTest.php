<?php

namespace Tests\Feature\Transfer;

use App\Models\ItemSerial;
use App\Models\Inventory;
use App\Services\TransferService;
use RuntimeException;

class DispatchScanTest extends TransferTestCase
{
    private function svc(): TransferService { return new TransferService(); }

    public function test_scans_serial_and_autoroutes_to_line(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 2);
        [$sn] = $this->makeSerials($from->location_id, $prod->product_id, 1);
        $user = $this->makeUser();

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 2],
        ]);

        // No transfer_detail_id supplied — must resolve from the serial.
        $res = $this->svc()->recordDispatchScan($ist, $sn, null, $user->user_id);

        $this->assertSame(
            $ist->details->first()->transfer_detail_id,
            $res['transfer_detail_id']
        );
        $this->assertSame(1, $res['total_scanned']);
        $this->assertSame(1, $res['remaining']);
    }

    public function test_double_scan_same_serial_is_rejected(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 2);
        [$sn] = $this->makeSerials($from->location_id, $prod->product_id, 1);
        $user = $this->makeUser();
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 2],
        ]);

        $this->svc()->recordDispatchScan($ist, $sn, null, $user->user_id);

        $this->expectException(RuntimeException::class);
        $this->svc()->recordDispatchScan($ist->fresh('details'), $sn, null, $user->user_id);
    }

    public function test_serial_at_other_location_is_rejected(): void
    {
        $from  = $this->makeLocation('WH-A');
        $to    = $this->makeLocation('SR-B');
        $other = $this->makeLocation('SR-C');
        $prod  = $this->makeAppliance();
        // Serial physically lives at the OTHER location (already moved away).
        [$sn] = $this->makeSerials($other->location_id, $prod->product_id, 1);
        $user = $this->makeUser();
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 1],
        ]);

        $this->expectException(RuntimeException::class);
        $this->svc()->recordDispatchScan($ist, $sn, null, $user->user_id);
    }

    public function test_serial_for_product_not_on_ist_is_rejected(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $onIst   = $this->makeAppliance();
        $offIst  = $this->makeAppliance();
        $this->setStock($from->location_id, $onIst->product_id, 1);
        [$sn] = $this->makeSerials($from->location_id, $offIst->product_id, 1);
        $user = $this->makeUser();
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $onIst->product_id, 'quantity_requested' => 1],
        ]);

        $this->expectException(RuntimeException::class);
        $this->svc()->recordDispatchScan($ist, $sn, null, $user->user_id);
    }

    public function test_remove_dispatch_scan_reverts_line_and_frees_serial(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 2);
        [$sn] = $this->makeSerials($from->location_id, $prod->product_id, 1);
        $user = $this->makeUser();
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 2],
        ]);

        $res = $this->svc()->recordDispatchScan($ist, $sn, null, $user->user_id);

        $this->svc()->removeDispatchScan($ist->fresh('details'), $res['scan_id']);

        $detail = \App\Models\TransferDetail::where('transfer_detail_id', $res['transfer_detail_id'])->first();
        $this->assertSame(0, (int) $detail->quantity_scanned);

        $serial = ItemSerial::whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($sn)])->first();
        $this->assertNull($serial->transfer_id);
    }
}
