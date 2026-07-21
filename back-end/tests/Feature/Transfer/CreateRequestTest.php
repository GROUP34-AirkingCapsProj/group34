<?php

namespace Tests\Feature\Transfer;

class CreateRequestTest extends TransferTestCase
{
    public function test_pull_request_has_null_source_and_pending_status(): void
    {
        $dst = $this->makeLocation('Dst');
        $product = $this->makeConsumable();
        $user = $this->makeUserAt($dst, 'inventory_analyst');

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/transfers', [
            'intent'         => 'pull',
            'to_location_id' => $dst->location_id,
            'transfer_date'  => '2026-07-22',
            'products'       => [['product_id' => $product->product_id, 'quantity_requested' => 4]],
        ]);

        $res->assertCreated();
        $this->assertNull($res->json('data.from_location_id'));
        $this->assertSame('pull', $res->json('data.intent'));
    }

    public function test_demand_request_lands_approved(): void
    {
        $src = $this->makeLocation('Src');
        $dst = $this->makeLocation('Dst');
        $product = $this->makeConsumable();
        $user = $this->makeUserAt($dst, 'inventory_analyst');

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/transfers', [
            'intent'           => 'demand',
            'from_location_id' => $src->location_id,
            'to_location_id'   => $dst->location_id,
            'transfer_date'    => '2026-07-22',
            'products'         => [['product_id' => $product->product_id, 'quantity_requested' => 2]],
        ]);

        $res->assertCreated();
        $this->assertSame($this->statusId('Approved'), (int) $res->json('data.status_id'));
    }
}
