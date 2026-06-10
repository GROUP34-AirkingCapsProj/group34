<?php

namespace Tests\Feature\Transfer;

use App\Models\ItemSerial;
use App\Models\Inventory;
use App\Services\TransferService;

class SerialIntegrityFlowTest extends TransferTestCase
{
    public function test_dispatch_receive_moves_serial_and_blocks_resale_and_redispatch(): void
    {
        $b = $this->makeLocation('WH-B'); // source
        $a = $this->makeLocation('SR-A'); // destination (requester)
        $prod = $this->makeAppliance();
        $this->setStock($b->location_id, $prod->product_id, 1);
        [$sn] = $this->makeSerials($b->location_id, $prod->product_id, 1);
        ItemSerial::whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($sn)])
            ->update(['status_id' => ItemSerial::IN_STOCK_STATUS_ID]);
        $user = $this->makeUser();
        $svc  = new TransferService();

        // Precondition: issuable at source before any transfer.
        $this->assertNotNull(ItemSerial::issuableAt($b->location_id, $sn)->first());

        $ist = $this->makeApprovedIst($b->location_id, $a->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 1],
        ]);

        // Dispatch (In Transit): serial flips to 'transferred', stays at source location.
        $svc->recordDispatchScan($ist, $sn, null, $user->user_id);
        $svc->dispatch($ist->fresh('details'), $user->user_id);

        // #3: in transit -> not issuable anywhere.
        $this->assertNull(ItemSerial::issuableAt($b->location_id, $sn)->first());

        // Receive at A.
        $svc->recordReceiveScan($ist->fresh('details'), $sn, null, $user->user_id);
        $svc->receive($ist->fresh('details'), $user->user_id);

        // #4: serial now at destination, gone from source inventory.
        $serial = ItemSerial::whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($sn)])->first();
        $this->assertSame($a->location_id, (int) $serial->location_id);
        $this->assertSame('received', $serial->serial_type);
        $srcInv = Inventory::where('location_id', $b->location_id)->where('product_id', $prod->product_id)->first();
        $this->assertSame(0, (int) $srcInv->quantity_on_hand);

        // #4: now issuable at A, not at B.
        $this->assertNotNull(ItemSerial::issuableAt($a->location_id, $sn)->first());
        $this->assertNull(ItemSerial::issuableAt($b->location_id, $sn)->first());

        // #5: B can no longer dispatch this serial.
        $ist2 = $this->makeApprovedIst($b->location_id, $a->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 1],
        ]);
        $this->expectException(\RuntimeException::class);
        $svc->recordDispatchScan($ist2, $sn, null, $user->user_id);
    }
}
