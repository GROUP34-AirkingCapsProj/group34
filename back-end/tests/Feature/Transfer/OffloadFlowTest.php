<?php

namespace Tests\Feature\Transfer;

use App\Models\Transfer;

class OffloadFlowTest extends TransferTestCase
{
    public function test_offload_request_stores_source_with_null_destination_pending(): void
    {
        $src = $this->makeLocation('Src');
        $product = $this->makeInstallationMaterial();
        $wp = $this->makeUserAt($src, 'warehouse_personnel');

        $res = $this->actingAs($wp, 'sanctum')->postJson('/api/transfers', [
            'intent'           => 'offload',
            'from_location_id' => $src->location_id,
            'transfer_date'    => '2026-07-22',
            'products'         => [['product_id' => $product->product_id, 'quantity_requested' => 3]],
        ]);

        $res->assertCreated();
        $this->assertSame($src->location_id, (int) $res->json('data.from_location_id'));
        $this->assertNull($res->json('data.to_location_id'));
        $this->assertSame($this->statusId('Pending'), (int) $res->json('data.status_id'));
    }

    public function test_verify_offload_requires_destination_then_approves(): void
    {
        $src = $this->makeLocation('Src');
        $dst = $this->makeLocation('Dst');
        $product = $this->makeInstallationMaterial();
        $ia = $this->makeUserAt($src, 'inventory_analyst');

        $ist = Transfer::create([
            'transfer_request_id' => null, 'intent' => 'offload',
            'from_location_id' => $src->location_id, 'to_location_id' => null,
            'transfer_number' => 'TR-OFF-' . uniqid(), 'transfer_date' => now(),
            'status_id' => $this->statusId('Pending'),
        ]);
        $line = $ist->details()->create(['product_id' => $product->product_id, 'quantity_requested' => 3]);

        // Without a destination the analyst cannot approve it.
        $this->actingAs($ia, 'sanctum')->postJson("/api/transfers/{$ist->transfer_id}/verify", [
            'lines' => [['transfer_detail_id' => $line->transfer_detail_id, 'quantity_approved' => 3]],
        ])->assertStatus(422);

        // Assigning the destination approves it.
        $this->actingAs($ia, 'sanctum')->postJson("/api/transfers/{$ist->transfer_id}/verify", [
            'to_location_id' => $dst->location_id,
            'lines' => [['transfer_detail_id' => $line->transfer_detail_id, 'quantity_approved' => 3]],
        ])->assertOk();

        $this->assertSame($dst->location_id, (int) $ist->fresh()->to_location_id);
        $this->assertSame($this->statusId('Approved'), (int) $ist->fresh()->status_id);
    }
}
