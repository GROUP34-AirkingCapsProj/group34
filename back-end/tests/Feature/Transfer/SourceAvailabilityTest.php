<?php

namespace Tests\Feature\Transfer;

class SourceAvailabilityTest extends TransferTestCase
{
    public function test_returns_available_and_on_hand_per_warehouse_for_requested_products(): void
    {
        $whA = $this->makeLocation('WH-A');
        $whB = $this->makeLocation('WH-B');
        $dst = $this->makeLocation('Showroom');
        $p1 = $this->makeInstallationMaterial();
        $p2 = $this->makeInstallationMaterial();

        $this->setStock($whA->location_id, $p1->product_id, 8);
        $this->setStock($whA->location_id, $p2->product_id, 4);
        $this->setStock($whB->location_id, $p1->product_id, 0);

        $ist = $this->makeApprovedTransfer($whA, $dst, [[$p1, 5], [$p2, 3]]);
        $ia = $this->makeUserAt($dst, 'inventory_analyst');

        $res = $this->actingAs($ia, 'sanctum')->getJson("/api/transfers/{$ist->transfer_id}/source-availability");
        $res->assertOk();

        $this->assertSame(8, $res->json("data.{$whA->location_id}.{$p1->product_id}.available"));
        $this->assertSame(4, $res->json("data.{$whA->location_id}.{$p2->product_id}.available"));
        $this->assertSame(0, $res->json("data.{$whB->location_id}.{$p1->product_id}.available"));
        $this->assertNull($res->json("data.{$whB->location_id}.{$p2->product_id}"));
        $this->assertNull($res->json("data.{$dst->location_id}"));
    }
}
