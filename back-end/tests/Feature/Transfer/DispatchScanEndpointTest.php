<?php

namespace Tests\Feature\Transfer;

class DispatchScanEndpointTest extends TransferTestCase
{
    public function test_scan_endpoint_autoroutes_without_detail_id(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeAppliance();
        $this->setStock($from->location_id, $prod->product_id, 1);
        [$sn] = $this->makeSerials($from->location_id, $prod->product_id, 1);
        $user = $this->makeUser();

        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 1],
        ]);

        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/transfers/{$ist->transfer_id}/scan", ['barcode' => $sn]);

        $res->assertOk();
        $res->assertJsonPath('data.total_scanned', 1);
        $res->assertJsonPath('data.ist_fully_scanned', true);
    }
}
