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
}
