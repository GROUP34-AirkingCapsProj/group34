<?php

namespace Tests\Feature\Transfer;

class WorklistScopeTest extends TransferTestCase
{
    public function test_warehouse_sees_only_its_transfers_filtered_by_status(): void
    {
        $wh = $this->makeLocation('WH');
        $other = $this->makeLocation('Other');
        $third = $this->makeLocation('Third');
        $product = $this->makeConsumable();

        $mine = $this->makeApprovedTransfer($wh, $other, [[$product, 1]]);       // wh is source
        $notMine = $this->makeApprovedTransfer($other, $third, [[$product, 1]]); // wh not involved
        $user = $this->makeUserAt($wh, 'warehouse_personnel');

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/transfers?status_id=' . $this->statusId('Approved'));
        $res->assertOk();

        $ids = collect($res->json('data'))->pluck('transfer_id');
        $this->assertTrue($ids->contains($mine->transfer_id));
        $this->assertFalse($ids->contains($notMine->transfer_id));
    }
}
