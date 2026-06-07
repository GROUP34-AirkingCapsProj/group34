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

    public function test_receive_scan_then_confirm_via_endpoints(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 10);
        $ist = $this->makeApprovedIst($from->location_id, $to->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 3, 'quantity_scanned' => 3],
        ]);
        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum')->postJson("/api/ist/{$ist->transfer_id}/confirm-dispatch")->assertOk();
        $ist->refresh()->load('details');
        $detailId = (int) $ist->details->first()->transfer_detail_id;

        $scan = $this->actingAs($admin, 'sanctum')->postJson("/api/ist/{$ist->transfer_id}/receive-scan", [
            'barcode' => 'EP-1', 'transfer_detail_id' => $detailId, 'quantity' => 3,
        ]);
        $scan->assertOk();

        $this->actingAs($admin, 'sanctum')->postJson("/api/ist/{$ist->transfer_id}/receive", [
            'received_date' => now()->toDateString(),
        ])->assertOk();

        $ist->refresh();
        $this->assertSame($this->statusId('Received'), (int) $ist->status_id);
        $dst = \App\Models\Inventory::where('location_id', $to->location_id)
            ->where('product_id', $prod->product_id)->first();
        $this->assertSame(3, (int) $dst->quantity_on_hand);
    }

    public function test_request_show_exposes_available_at_source_and_shortfall(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 3);

        $req = \App\Models\Transfer::create([
            'transfer_request_id' => null,
            'from_location_id'    => $from->location_id,
            'to_location_id'      => $to->location_id,
            'transfer_number'     => 'TR-REQ-SHOW-' . uniqid(),
            'transfer_date'       => now(),
            'status_id'           => $this->statusId('Pending'),
        ]);
        $req->details()->create([
            'product_id'           => $prod->product_id,
            'quantity_requested'   => 5,
            'quantity_transferred' => 0,
            'quantity_scanned'     => 0,
            'quantity_received'    => 0,
        ]);

        $resp = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson("/api/transfer-requests/{$req->transfer_id}");

        $resp->assertOk()
            ->assertJsonPath('data.details.0.available_at_source', 3)
            ->assertJsonPath('data.details.0.shortfall', 2);
    }
}
