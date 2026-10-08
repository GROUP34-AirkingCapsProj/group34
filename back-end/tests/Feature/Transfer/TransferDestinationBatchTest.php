<?php

namespace Tests\Feature\Transfer;

use App\Models\BarcodeScan;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Services\InventoryBatchService;
use App\Services\TransferService;

/**
 * A transfer must carry a batch's lineage to the destination.
 *
 * dispatch() already deducts the source batch. Receipt was the missing half:
 * it credited inventory.quantity_on_hand at the destination but wrote no
 * inventory_batches row there, so the arriving stock had no PO, no receipt
 * date, and no FIFO age at its new home. The next issuance out of the
 * destination then fell through deductFifo() to the legacy catch-all batch
 * and the unit's provenance was gone for good.
 *
 * A batch is not a thing that moves. It is a per-location bucket of one
 * receiving_id, so "the same batch, somewhere else" means a second bucket
 * carrying the same receiving_id - which is what these tests pin down.
 */
class TransferDestinationBatchTest extends TransferTestCase
{
    /** The destination bucket for a receiving, or null if the transfer never made one. */
    private function destinationBatch(int $productId, int $locationId, ?int $receivingId): ?InventoryBatch
    {
        return InventoryBatch::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('receiving_id', $receivingId)
            ->first();
    }

    public function test_receiving_an_installation_material_opens_a_destination_batch_with_the_source_lineage(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('WH-B');
        $prod = $this->makeInstallationMaterial();
        $this->setStock($from->location_id, $prod->product_id, 10);

        $svc      = new InventoryBatchService();
        $rcv      = $this->makeReceiving($from->location_id);
        $received = now()->subDays(30);
        $srcBatch = $svc->createBatch($prod->product_id, $from->location_id, $rcv->receiving_id, 10, $received, 'Aisle 1');

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 4, 'quantity_scanned' => 4],
        ]);

        $service = new TransferService();
        $service->dispatch($ist);
        $this->assertSame(6, $srcBatch->fresh()->quantity_remaining, 'source batch should be drawn down by the shipment');

        $ist->fresh()->details->first()->update(['quantity_received' => 4]);
        $service->receive($ist->fresh(), $this->makeUser()->user_id);

        $dst = $this->destinationBatch($prod->product_id, $to->location_id, $rcv->receiving_id);
        $this->assertNotNull($dst, 'receiving should open a batch at the destination for the same receiving_id');
        $this->assertSame(4, (int) $dst->quantity_remaining);
        $this->assertSame(4, (int) $dst->quantity_received);
        $this->assertSame(
            $received->toDateTimeString(),
            $dst->received_at?->toDateTimeString(),
            'FIFO age must survive the move, or transferred stock jumps the queue at its new home'
        );
    }

    public function test_the_source_batch_keeps_its_own_history_after_the_move(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('WH-B');
        $prod = $this->makeInstallationMaterial();
        $this->setStock($from->location_id, $prod->product_id, 10);

        $svc      = new InventoryBatchService();
        $rcv      = $this->makeReceiving($from->location_id);
        $srcBatch = $svc->createBatch($prod->product_id, $from->location_id, $rcv->receiving_id, 10, now(), null);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 10, 'quantity_scanned' => 10],
        ]);

        $service = new TransferService();
        $service->dispatch($ist);
        $ist->fresh()->details->first()->update(['quantity_received' => 10]);
        $service->receive($ist->fresh(), $this->makeUser()->user_id);

        $srcBatch->refresh();
        $this->assertSame((int) $from->location_id, (int) $srcBatch->location_id, 'the source row must not be relocated');
        $this->assertSame(10, (int) $srcBatch->quantity_received, 'the source keeps what it once received');
        $this->assertSame(0, (int) $srcBatch->quantity_remaining, 'but holds none of it any more');
    }

    public function test_a_second_transfer_of_the_same_receiving_tops_up_one_destination_batch(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('WH-B');
        $prod = $this->makeInstallationMaterial();
        $this->setStock($from->location_id, $prod->product_id, 10);

        $svc = new InventoryBatchService();
        $rcv = $this->makeReceiving($from->location_id);
        $svc->createBatch($prod->product_id, $from->location_id, $rcv->receiving_id, 10, now(), null);

        $service = new TransferService();
        $userId  = $this->makeUser()->user_id;

        foreach ([3, 2] as $qty) {
            $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
                ['product_id' => $prod->product_id, 'quantity_requested' => $qty, 'quantity_scanned' => $qty],
            ]);
            $service->dispatch($ist);
            $ist->fresh()->details->first()->update(['quantity_received' => $qty]);
            $service->receive($ist->fresh(), $userId);
        }

        $buckets = InventoryBatch::where('product_id', $prod->product_id)
            ->where('location_id', $to->location_id)
            ->where('receiving_id', $rcv->receiving_id)
            ->get();

        $this->assertCount(1, $buckets, 'one receiving_id is one bucket per location, however many trips it took');
        $this->assertSame(5, (int) $buckets->first()->quantity_remaining);
        $this->assertSame(5, (int) $buckets->first()->quantity_received);
    }

    public function test_a_short_receipt_credits_only_what_arrived(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('WH-B');
        $prod = $this->makeInstallationMaterial();
        $this->setStock($from->location_id, $prod->product_id, 10);

        $svc = new InventoryBatchService();
        $rcv = $this->makeReceiving($from->location_id);
        $svc->createBatch($prod->product_id, $from->location_id, $rcv->receiving_id, 10, now(), null);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 6, 'quantity_scanned' => 6],
        ]);

        $service = new TransferService();
        $service->dispatch($ist);
        // Six shipped, four turned up: the destination must not be credited for
        // the two that went missing in transit.
        $ist->fresh()->details->first()->update(['quantity_received' => 4]);
        $service->receive($ist->fresh(), $this->makeUser()->user_id);

        $dst = $this->destinationBatch($prod->product_id, $to->location_id, $rcv->receiving_id);
        $this->assertNotNull($dst);
        $this->assertSame(4, (int) $dst->quantity_remaining);
    }

    public function test_receiving_a_serial_repoints_its_batch_id_to_the_destination_bucket(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('WH-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 1);

        $svc      = new InventoryBatchService();
        $rcv      = $this->makeReceiving($from->location_id);
        $srcBatch = $svc->createBatch($prod->product_id, $from->location_id, $rcv->receiving_id, 1, now(), null);

        $sn     = 'SN-' . uniqid();
        $serial = ItemSerial::create([
            'product_id'    => $prod->product_id,
            'location_id'   => $from->location_id,
            'receiving_id'  => $rcv->receiving_id,
            'batch_id'      => $srcBatch->batch_id,
            'serial_number' => $sn,
            'serial_type'   => 'received',
            'status_id'     => ItemSerial::IN_STOCK_STATUS_ID,
        ]);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 1, 'quantity_scanned' => 1],
        ]);
        $serial->update(['transfer_id' => $ist->transfer_id]);

        $service = new TransferService();
        $service->dispatch($ist);

        // receive() only moves the serials the receiving scanner actually saw.
        BarcodeScan::create([
            'user_id'     => $this->makeUser()->user_id,
            'product_id'  => $prod->product_id,
            'transfer_id' => $ist->transfer_id,
            'barcode'     => $sn,
            'scan_mode'   => 'receive',
            'quantity'    => 1,
            'scanned_at'  => now(),
        ]);
        $ist->fresh()->details->first()->update(['quantity_received' => 1]);
        $service->receive($ist->fresh(), $this->makeUser()->user_id);

        $dst = $this->destinationBatch($prod->product_id, $to->location_id, $rcv->receiving_id);
        $this->assertNotNull($dst, 'the serial needs a bucket to belong to at its new location');
        $this->assertSame(1, (int) $dst->quantity_remaining);

        $serial->refresh();
        $this->assertSame((int) $to->location_id, (int) $serial->location_id);
        $this->assertSame(
            (int) $dst->batch_id,
            (int) $serial->batch_id,
            'a serial at B must not still claim membership of a batch sitting at A'
        );
    }

    public function test_reversing_a_received_transfer_takes_the_stock_back_out_of_the_destination_batch(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('WH-B');
        $prod = $this->makeInstallationMaterial();
        $this->setStock($from->location_id, $prod->product_id, 10);

        $svc      = new InventoryBatchService();
        $rcv      = $this->makeReceiving($from->location_id);
        $srcBatch = $svc->createBatch($prod->product_id, $from->location_id, $rcv->receiving_id, 10, now(), null);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 4, 'quantity_scanned' => 4],
        ]);

        $service = new TransferService();
        $service->dispatch($ist);
        $ist->fresh()->details->first()->update(['quantity_received' => 4]);
        $service->receive($ist->fresh(), $this->makeUser()->user_id);

        $dst = $this->destinationBatch($prod->product_id, $to->location_id, $rcv->receiving_id);
        $this->assertSame(4, (int) $dst->quantity_remaining);

        $service->reverse($ist->fresh());

        // Crediting the destination on receipt is only half a ledger. Without the
        // matching debit here the batch would still show four units at B while
        // inventory and the source batch both say they went home.
        $this->assertSame(0, (int) $dst->fresh()->quantity_remaining, 'destination batch must give the stock back');
        $this->assertSame(10, (int) $srcBatch->fresh()->quantity_remaining, 'source batch must be whole again');
    }

    public function test_reversing_a_received_serial_sends_its_batch_id_home_too(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('WH-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 1);

        $svc      = new InventoryBatchService();
        $rcv      = $this->makeReceiving($from->location_id);
        $srcBatch = $svc->createBatch($prod->product_id, $from->location_id, $rcv->receiving_id, 1, now(), null);

        $sn     = 'SN-' . uniqid();
        $serial = ItemSerial::create([
            'product_id'    => $prod->product_id,
            'location_id'   => $from->location_id,
            'receiving_id'  => $rcv->receiving_id,
            'batch_id'      => $srcBatch->batch_id,
            'serial_number' => $sn,
            'serial_type'   => 'received',
            'status_id'     => ItemSerial::IN_STOCK_STATUS_ID,
        ]);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 1, 'quantity_scanned' => 1],
        ]);
        $serial->update(['transfer_id' => $ist->transfer_id]);

        $service = new TransferService();
        $service->dispatch($ist);

        BarcodeScan::create([
            'user_id'     => $this->makeUser()->user_id,
            'product_id'  => $prod->product_id,
            'transfer_id' => $ist->transfer_id,
            'barcode'     => $sn,
            'scan_mode'   => 'receive',
            'quantity'    => 1,
            'scanned_at'  => now(),
        ]);
        $ist->fresh()->details->first()->update(['quantity_received' => 1]);
        $service->receive($ist->fresh(), $this->makeUser()->user_id);

        $service->reverse($ist->fresh());

        $serial->refresh();
        $this->assertSame((int) $from->location_id, (int) $serial->location_id);
        $this->assertSame(
            (int) $srcBatch->batch_id,
            (int) $serial->batch_id,
            'the serial is back at A, so it belongs to A\'s batch again'
        );

        $dst = $this->destinationBatch($prod->product_id, $to->location_id, $rcv->receiving_id);
        $this->assertSame(0, (int) $dst?->quantity_remaining);
    }

    public function test_stock_moved_in_is_issuable_from_its_own_batch_not_the_legacy_catch_all(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('WH-B');
        $prod = $this->makeInstallationMaterial();
        $this->setStock($from->location_id, $prod->product_id, 10);

        $svc = new InventoryBatchService();
        $rcv = $this->makeReceiving($from->location_id);
        $svc->createBatch($prod->product_id, $from->location_id, $rcv->receiving_id, 10, now(), null);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 5, 'quantity_scanned' => 5],
        ]);

        $service = new TransferService();
        $service->dispatch($ist);
        $ist->fresh()->details->first()->update(['quantity_received' => 5]);
        $service->receive($ist->fresh(), $this->makeUser()->user_id);

        // This is the payoff: issuing at the destination must find a traceable
        // batch. Before the fix it spilled into the legacy batch and the PO link
        // was lost the moment the stock was used.
        $touched = $svc->deductFifo($prod->product_id, $to->location_id, 2);

        $dst = $this->destinationBatch($prod->product_id, $to->location_id, $rcv->receiving_id);
        $this->assertNotNull($dst);
        $this->assertSame([$dst->batch_id => 2], $touched, 'the destination batch should absorb the issuance');

        $legacy = InventoryBatch::where('product_id', $prod->product_id)
            ->where('location_id', $to->location_id)
            ->whereNull('receiving_id')
            ->first();
        $this->assertTrue(
            $legacy === null || (int) $legacy->quantity_remaining === 0,
            'nothing should have leaked into the legacy catch-all batch'
        );
    }
}
