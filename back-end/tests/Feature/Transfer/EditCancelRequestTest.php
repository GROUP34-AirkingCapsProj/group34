<?php

namespace Tests\Feature\Transfer;

use App\Models\Transfer;

class EditCancelRequestTest extends TransferTestCase
{
    private function pendingPull($dst, $product, $requester): Transfer
    {
        $t = Transfer::create([
            'transfer_request_id' => null, 'intent' => 'pull',
            'from_location_id' => null, 'to_location_id' => $dst->location_id,
            'transfer_number' => 'TR-P-' . uniqid(), 'transfer_date' => now(),
            'requested_by' => $requester->user_id,
            'status_id' => $this->statusId('Pending'),
        ]);
        $t->details()->create(['product_id' => $product->product_id, 'quantity_requested' => 4]);
        return $t;
    }

    public function test_requester_can_edit_pending_lines_and_notes(): void
    {
        $dst = $this->makeLocation('Dst');
        $p1 = $this->makeInstallationMaterial();
        $p2 = $this->makeInstallationMaterial();
        $wp = $this->makeUserAt($dst, 'warehouse_personnel');
        $t = $this->pendingPull($dst, $p1, $wp);

        $res = $this->actingAs($wp, 'sanctum')->patchJson("/api/transfers/{$t->transfer_id}/request", [
            'notes' => 'updated',
            'products' => [['product_id' => $p2->product_id, 'quantity_requested' => 9]],
        ]);

        $res->assertOk();
        $t->refresh()->load('details');
        $this->assertSame('updated', $t->notes);
        $this->assertCount(1, $t->details);
        $this->assertSame($p2->product_id, (int) $t->details->first()->product_id);
        $this->assertSame(9, (int) $t->details->first()->quantity_requested);
    }

    public function test_non_requester_non_analyst_cannot_edit(): void
    {
        $dst = $this->makeLocation('Dst');
        $p = $this->makeInstallationMaterial();
        $owner = $this->makeUserAt($dst, 'warehouse_personnel');
        $other = $this->makeUserAt($this->makeLocation('Other'), 'warehouse_personnel');
        $t = $this->pendingPull($dst, $p, $owner);

        $this->actingAs($other, 'sanctum')->patchJson("/api/transfers/{$t->transfer_id}/request", [
            'products' => [['product_id' => $p->product_id, 'quantity_requested' => 1]],
        ])->assertStatus(403);
    }

    public function test_cannot_edit_once_approved(): void
    {
        $dst = $this->makeLocation('Dst');
        $p = $this->makeInstallationMaterial();
        $wp = $this->makeUserAt($dst, 'warehouse_personnel');
        $t = $this->pendingPull($dst, $p, $wp);
        $t->status_id = $this->statusId('Approved');
        $t->save();

        $this->actingAs($wp, 'sanctum')->patchJson("/api/transfers/{$t->transfer_id}/request", [
            'products' => [['product_id' => $p->product_id, 'quantity_requested' => 1]],
        ])->assertStatus(422);
    }

    public function test_requester_can_cancel_pending_and_it_remains_an_editable_draft(): void
    {
        $dst = $this->makeLocation('Dst');
        $p = $this->makeInstallationMaterial();
        $wp = $this->makeUserAt($dst, 'warehouse_personnel');
        $t = $this->pendingPull($dst, $p, $wp);

        $this->actingAs($wp, 'sanctum')->patchJson("/api/transfers/{$t->transfer_id}/cancel")->assertOk();
        $this->assertNull($t->fresh()->deleted_at);
        $this->assertSame('Draft', $t->fresh()->status->status_name);
    }
}
