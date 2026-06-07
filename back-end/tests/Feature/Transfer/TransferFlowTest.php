<?php

namespace Tests\Feature\Transfer;

use App\Models\User;

class TransferFlowTest extends TransferTestCase
{
    protected function adminUser(): User
    {
        $roleId = \Illuminate\Support\Facades\DB::table('roles')->where('role_name', 'admin')->value('role_id');
        $u = $this->makeUser();
        if ($roleId) {
            $u->role_id = $roleId;
            $u->save();
        }
        return $u;
    }

    public function test_confirm_dispatch_endpoint_moves_to_in_transit(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 10);
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 5, 'quantity_scanned' => 5],
        ]);

        $resp = $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson("/api/ist/{$ist->transfer_id}/confirm-dispatch");

        $resp->assertOk();
        $ist->refresh();
        $this->assertSame($this->statusId('In Transit'), (int) $ist->status_id);
    }
}
