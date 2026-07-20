<?php

namespace Tests\Feature\Transfer;

use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Receiving;
use App\Services\InventoryBatchService;
use App\Services\TransferService;

class TransferBatchFifoTest extends TransferTestCase
{
    private function makeReceiving(int $locationId): Receiving
    {
        return Receiving::create([
            'location_id'      => $locationId,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now(),
        ]);
    }

    public function test_dispatch_consumable_deducts_oldest_batch_first(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 10);

        $svc = new InventoryBatchService();
        $old = $svc->createBatch($prod->product_id, $from->location_id, $this->makeReceiving($from->location_id)->receiving_id, 6, now()->subDays(5));
        $new = $svc->createBatch($prod->product_id, $from->location_id, $this->makeReceiving($from->location_id)->receiving_id, 4, now()->subDay());

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 8, 'quantity_scanned' => 8],
        ]);

        (new TransferService())->dispatch($ist);

        $this->assertSame(0, $old->fresh()->quantity_remaining);
        $this->assertSame(2, $new->fresh()->quantity_remaining);
    }

    public function test_dispatch_serialized_deducts_exact_batch_by_receiving_id(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 1);

        $svc = new InventoryBatchService();
        $rcv   = $this->makeReceiving($from->location_id);
        $batch = $svc->createBatch($prod->product_id, $from->location_id, $rcv->receiving_id, 1, now());

        $sn = 'SN-' . uniqid();
        $serial = ItemSerial::create([
            'product_id'    => $prod->product_id,
            'location_id'   => $from->location_id,
            'receiving_id'  => $rcv->receiving_id,
            'serial_number' => $sn,
            'serial_type'   => 'received',
        ]);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 1, 'quantity_scanned' => 1],
        ]);
        $ist->details->first()->update(['quantity_scanned' => 1]);
        $serial->update(['transfer_id' => $ist->transfer_id]);

        (new TransferService())->dispatch($ist);

        $this->assertSame(0, $batch->fresh()->quantity_remaining);
    }
}
