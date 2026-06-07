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
}
