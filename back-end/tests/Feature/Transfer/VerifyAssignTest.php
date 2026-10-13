<?php

namespace Tests\Feature\Transfer;

use App\Models\Transfer;

/**
 * The approver is an admin, not an inventory analyst.
 *
 * POST /transfers/{id}/verify is gated on `inventory_transfers,approve`, which
 * the role realignment moved to admin alone — it left the analyst and was not
 * given to the branch manager. Anyone else needs a per-user override. Acting as
 * an analyst here produces a 403 that looks like a controller bug.
 */
class VerifyAssignTest extends TransferTestCase
{
    public function test_an_admin_assigns_source_trims_line_and_approves(): void
    {
        $src = $this->makeLocation('Src');
        $dst = $this->makeLocation('Dst');
        $keep = $this->makeInstallationMaterial();
        $drop = $this->makeInstallationMaterial();
        $approver = $this->makeUserAt($dst, 'admin');
        $this->setStock($src->location_id, $keep->product_id, 6);

        $ist = Transfer::create([
            'transfer_request_id' => null, 'intent' => 'pull',
            'from_location_id' => null, 'to_location_id' => $dst->location_id,
            'transfer_number' => 'TR-X-' . uniqid(), 'transfer_date' => now(),
            'status_id' => $this->statusId('Pending'),
        ]);
        $keepLine = $ist->details()->create(['product_id' => $keep->product_id, 'quantity_requested' => 10]);
        $dropLine = $ist->details()->create(['product_id' => $drop->product_id, 'quantity_requested' => 5]);

        $res = $this->actingAs($approver, 'sanctum')->postJson("/api/transfers/{$ist->transfer_id}/verify", [
            'from_location_id' => $src->location_id,
            'lines' => [
                ['transfer_detail_id' => $keepLine->transfer_detail_id, 'quantity_approved' => 6],
                ['transfer_detail_id' => $dropLine->transfer_detail_id, 'quantity_approved' => 0],
            ],
        ]);

        $res->assertOk();
        $ist->refresh();
        $this->assertSame($src->location_id, (int) $ist->from_location_id);
        $this->assertSame($this->statusId('Approved'), (int) $ist->status_id);
        $this->assertDatabaseMissing('transfer_details', ['transfer_detail_id' => $dropLine->transfer_detail_id]);
        $this->assertSame(6, (int) $keepLine->fresh()->quantity_requested);
    }

    public function test_verify_requires_source_when_pull(): void
    {
        $dst = $this->makeLocation('Dst');
        $approver = $this->makeUserAt($dst, 'admin');
        $ist = Transfer::create([
            'transfer_request_id' => null, 'intent' => 'pull',
            'from_location_id' => null, 'to_location_id' => $dst->location_id,
            'transfer_number' => 'TR-Y-' . uniqid(), 'transfer_date' => now(),
            'status_id' => $this->statusId('Pending'),
        ]);
        $this->actingAs($approver, 'sanctum')->postJson("/api/transfers/{$ist->transfer_id}/verify", [])
             ->assertStatus(422);
    }
}
