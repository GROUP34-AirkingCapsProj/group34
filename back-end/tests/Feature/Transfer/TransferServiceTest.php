<?php

namespace Tests\Feature\Transfer;

use Illuminate\Support\Facades\DB;

class TransferServiceTest extends TransferTestCase
{
    public function test_in_transit_and_received_statuses_exist(): void
    {
        foreach (['In Transit', 'Received'] as $name) {
            $id = DB::table('status_lookup')
                ->where('status_category', 'transfer')
                ->where('status_name', $name)
                ->value('status_id');
            $this->assertNotNull($id, "Status '{$name}' must exist in category 'transfer'");
        }
    }

    public function test_resolves_transfer_status_by_name(): void
    {
        $svc = new \App\Services\TransferService();
        $this->assertSame(
            $this->statusId('In Transit'),
            $svc->statusIdForTest('In Transit')
        );
    }

    public function test_dispatch_consumable_decrements_source_both_columns(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 10);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 5, 'quantity_scanned' => 5],
        ]);

        (new \App\Services\TransferService())->dispatch($ist);

        $src = \App\Models\Inventory::where('location_id', $from->location_id)
            ->where('product_id', $prod->product_id)->first();

        $this->assertSame(5, $src->quantity_on_hand);
        $this->assertSame(5, $src->available_quantity);

        $ist->refresh()->load('details');
        $this->assertSame($this->statusId('In Transit'), (int) $ist->status_id);
        $this->assertSame(5, (int) $ist->details->first()->quantity_transferred);
        $this->assertSame(5, (int) $ist->total_quantity_transferred);
    }

    public function test_dispatch_appliance_flips_serials_without_moving_location(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 3);
        $serials = $this->makeSerials($from->location_id, $prod->product_id, 3);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 3, 'quantity_scanned' => 3],
        ]);

        // Simulate the source scan loop having linked the 3 serials to this IST.
        \App\Models\ItemSerial::whereIn('serial_number', $serials)
            ->update(['transfer_id' => $ist->transfer_id]);

        (new \App\Services\TransferService())->dispatch($ist);

        $rows = \App\Models\ItemSerial::whereIn('serial_number', $serials)->get();
        foreach ($rows as $row) {
            $this->assertSame('transferred', $row->serial_type);
            $this->assertSame($from->location_id, (int) $row->location_id, 'serial must NOT move to destination at dispatch');
        }

        $src = \App\Models\Inventory::where('location_id', $from->location_id)
            ->where('product_id', $prod->product_id)->first();
        $this->assertSame(0, $src->quantity_on_hand);
        $this->assertSame(0, $src->available_quantity);
    }

    public function test_dispatch_partial_ships_only_scanned_and_auto_closes(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 10);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 5, 'quantity_scanned' => 3],
        ]);

        (new \App\Services\TransferService())->dispatch($ist);

        $ist->refresh()->load('details');
        $this->assertSame(3, (int) $ist->details->first()->quantity_transferred);
        $this->assertSame(3, (int) $ist->total_quantity_transferred);
        $this->assertSame($this->statusId('In Transit'), (int) $ist->status_id);

        $src = \App\Models\Inventory::where('location_id', $from->location_id)
            ->where('product_id', $prod->product_id)->first();
        $this->assertSame(7, $src->quantity_on_hand);
    }

    public function test_dispatch_rejects_when_nothing_scanned(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 10);

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 5, 'quantity_scanned' => 0],
        ]);

        $this->expectException(\RuntimeException::class);
        (new \App\Services\TransferService())->dispatch($ist);
    }

    /** Helper: build an In-Transit IST with one dispatched consumable line. */
    private function inTransitConsumable(int $qty = 3): array
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 10);
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => $qty, 'quantity_scanned' => $qty],
        ]);
        (new \App\Services\TransferService())->dispatch($ist);
        return [$from, $to, $prod, $ist->fresh('details')];
    }

    public function test_receive_scan_increments_received_and_logs_scan(): void
    {
        [$from, $to, $prod, $ist] = $this->inTransitConsumable(3);
        $detail = $ist->details->first();
        $user = $this->makeUser();

        $svc = new \App\Services\TransferService();
        $svc->recordReceiveScan($ist, 'BC-1', (int) $detail->transfer_detail_id, $user->user_id, 1);

        $detail->refresh();
        $this->assertSame(1, (int) $detail->quantity_received);

        $this->assertSame(1, \App\Models\BarcodeScan::where('transfer_id', $ist->transfer_id)
            ->where('scan_mode', 'receive')->count());
    }

    public function test_receive_scan_rejects_serial_not_on_shipment(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 2);
        $serials = $this->makeSerials($from->location_id, $prod->product_id, 2);
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 2, 'quantity_scanned' => 2],
        ]);
        \App\Models\ItemSerial::whereIn('serial_number', $serials)
            ->update(['transfer_id' => $ist->transfer_id]);
        $svc = new \App\Services\TransferService();
        $svc->dispatch($ist);
        $ist->refresh()->load('details');
        $detail = $ist->details->first();
        $user = $this->makeUser();

        $this->expectException(\RuntimeException::class);
        $svc->recordReceiveScan($ist, 'SN-NOT-REAL', (int) $detail->transfer_detail_id, $user->user_id, 1);
    }

    public function test_remove_receive_scan_undoes_bulk_quantity(): void
    {
        [$from, $to, $prod, $ist] = $this->inTransitConsumable(3);
        $detail = $ist->details->first();
        $user = $this->makeUser();
        $svc = new \App\Services\TransferService();

        $res = $svc->recordReceiveScan($ist, 'BULK-1', (int) $detail->transfer_detail_id, $user->user_id, 3);
        $detail->refresh();
        $this->assertSame(3, (int) $detail->quantity_received);

        $svc->removeReceiveScan($ist, (int) $res['scan_id']);
        $detail->refresh();
        $this->assertSame(0, (int) $detail->quantity_received);
        $this->assertSame(0, \App\Models\BarcodeScan::where('transfer_id', $ist->transfer_id)
            ->where('scan_mode', 'receive')->count());
    }

    public function test_receive_scan_cannot_exceed_shipped(): void
    {
        [$from, $to, $prod, $ist] = $this->inTransitConsumable(2);
        $detail = $ist->details->first();
        $user = $this->makeUser();
        $svc = new \App\Services\TransferService();

        $this->expectException(\RuntimeException::class);
        $svc->recordReceiveScan($ist, 'OVER-1', (int) $detail->transfer_detail_id, $user->user_id, 3);
    }

    public function test_receive_scan_rejects_duplicate_barcode(): void
    {
        [$from, $to, $prod, $ist] = $this->inTransitConsumable(3);
        $detail = $ist->details->first();
        $user = $this->makeUser();
        $svc = new \App\Services\TransferService();

        $svc->recordReceiveScan($ist, 'DUP-1', (int) $detail->transfer_detail_id, $user->user_id, 1);
        $this->expectException(\RuntimeException::class);
        $svc->recordReceiveScan($ist, 'DUP-1', (int) $detail->transfer_detail_id, $user->user_id, 1);
    }

    public function test_receive_confirm_increments_destination_and_closes(): void
    {
        [$from, $to, $prod, $ist] = $this->inTransitConsumable(3);
        $detail = $ist->details->first();
        $user = $this->makeUser();
        $svc = new \App\Services\TransferService();

        $svc->recordReceiveScan($ist, 'BC-1', (int) $detail->transfer_detail_id, $user->user_id, 3);
        $svc->receive($ist->fresh('details'), $user->user_id);

        $dst = \App\Models\Inventory::where('location_id', $to->location_id)
            ->where('product_id', $prod->product_id)->first();
        $this->assertSame(3, $dst->quantity_on_hand);
        $this->assertSame(3, $dst->available_quantity);

        $ist->refresh();
        $this->assertSame($this->statusId('Received'), (int) $ist->status_id);
        $this->assertSame(3, (int) $ist->total_quantity_received);
    }

    public function test_receive_appliance_activates_serials_at_destination(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 2);
        $serials = $this->makeSerials($from->location_id, $prod->product_id, 2);
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 2, 'quantity_scanned' => 2],
        ]);
        \App\Models\ItemSerial::whereIn('serial_number', $serials)
            ->update(['transfer_id' => $ist->transfer_id]);
        $user = $this->makeUser();
        $svc = new \App\Services\TransferService();
        $svc->dispatch($ist);
        $ist->refresh()->load('details');
        $detail = $ist->details->first();

        foreach ($serials as $sn) {
            $svc->recordReceiveScan($ist, $sn, (int) $detail->transfer_detail_id, $user->user_id, 1);
        }
        $svc->receive($ist->fresh('details'), $user->user_id);

        $rows = \App\Models\ItemSerial::whereIn('serial_number', $serials)->get();
        foreach ($rows as $row) {
            $this->assertSame('received', $row->serial_type);
            $this->assertSame($to->location_id, (int) $row->location_id);
        }
        $dst = \App\Models\Inventory::where('location_id', $to->location_id)
            ->where('product_id', $prod->product_id)->first();
        $this->assertSame(2, $dst->quantity_on_hand);
    }

    public function test_reverse_from_in_transit_restores_source(): void
    {
        [$from, $to, $prod, $ist] = $this->inTransitConsumable(3);
        (new \App\Services\TransferService())->reverse($ist->fresh('details'));

        $src = \App\Models\Inventory::where('location_id', $from->location_id)
            ->where('product_id', $prod->product_id)->first();
        $this->assertSame(10, $src->quantity_on_hand);
        $this->assertSame(10, $src->available_quantity);
    }

    public function test_round_trip_conserves_total_stock(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 10);
        $this->setStock($to->location_id, $prod->product_id, 0);
        $user = $this->makeUser();
        $svc = new \App\Services\TransferService();

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 4, 'quantity_scanned' => 4],
        ]);
        $svc->dispatch($ist);
        $ist->refresh()->load('details');
        $svc->recordReceiveScan($ist, 'BC-RT', (int) $ist->details->first()->transfer_detail_id, $user->user_id, 4);
        $svc->receive($ist->fresh('details'), $user->user_id);

        $srcQty = (int) \App\Models\Inventory::where('location_id', $from->location_id)
            ->where('product_id', $prod->product_id)->value('quantity_on_hand');
        $dstQty = (int) \App\Models\Inventory::where('location_id', $to->location_id)
            ->where('product_id', $prod->product_id)->value('quantity_on_hand');

        $this->assertSame(6, $srcQty);
        $this->assertSame(4, $dstQty);
        $this->assertSame(10, $srcQty + $dstQty);
    }

    public function test_reverse_from_received_pulls_destination_and_restores_source(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 10);
        $this->setStock($to->location_id, $prod->product_id, 0);
        $user = $this->makeUser();
        $svc = new \App\Services\TransferService();

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 4, 'quantity_scanned' => 4],
        ]);
        $svc->dispatch($ist);
        $ist->refresh()->load('details');
        $svc->recordReceiveScan($ist, 'RCV-1', (int) $ist->details->first()->transfer_detail_id, $user->user_id, 4);
        $svc->receive($ist->fresh('details'), $user->user_id);

        // After receipt: source 6, destination 4.
        $svc->reverse($ist->fresh('details'));

        $src = \App\Models\Inventory::where('location_id', $from->location_id)
            ->where('product_id', $prod->product_id)->first();
        $dst = \App\Models\Inventory::where('location_id', $to->location_id)
            ->where('product_id', $prod->product_id)->first();
        $this->assertSame(10, (int) $src->quantity_on_hand);
        $this->assertSame(10, (int) $src->available_quantity);
        $this->assertSame(0, (int) $dst->quantity_on_hand);
        $this->assertSame(0, (int) $dst->available_quantity);
    }

    public function test_reverse_serialized_in_transit_returns_serials_to_source(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 2);
        $serials = $this->makeSerials($from->location_id, $prod->product_id, 2);
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 2, 'quantity_scanned' => 2],
        ]);
        \App\Models\ItemSerial::whereIn('serial_number', $serials)
            ->update(['transfer_id' => $ist->transfer_id]);
        $svc = new \App\Services\TransferService();
        $svc->dispatch($ist);

        $svc->reverse($ist->fresh('details'));

        $rows = \App\Models\ItemSerial::whereIn('serial_number', $serials)->get();
        foreach ($rows as $row) {
            $this->assertSame('received', $row->serial_type);
            $this->assertSame($from->location_id, (int) $row->location_id);
            $this->assertNull($row->transfer_id);
        }
        $src = \App\Models\Inventory::where('location_id', $from->location_id)
            ->where('product_id', $prod->product_id)->first();
        $this->assertSame(2, (int) $src->quantity_on_hand);
    }
}
