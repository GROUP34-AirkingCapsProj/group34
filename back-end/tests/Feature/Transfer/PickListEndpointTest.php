<?php

namespace Tests\Feature\Transfer;

class PickListEndpointTest extends TransferTestCase
{
    public function test_pick_list_endpoint_returns_fifo_suggestions(): void
    {
        $src = $this->makeLocation('Src');
        $dst = $this->makeLocation('Dst');
        $product = $this->makeConsumable();
        $this->makeBatch($product, $src, ['zone_label' => 'A-01', 'quantity_remaining' => 5, 'received_at' => '2026-01-01']);
        $ist = $this->makeApprovedTransfer($src, $dst, [[$product, 3]]);
        $user = $this->makeUserAt($src, 'warehouse_personnel');

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/transfers/{$ist->transfer_id}/pick-list");
        $res->assertOk();
        $res->assertJsonPath('data.0.suggestions.0.zone_label', 'A-01');
        $res->assertJsonPath('data.0.suggestions.0.quantity', 3);
    }
}
