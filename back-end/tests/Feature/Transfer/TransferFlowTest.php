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

    public function test_request_numbers_are_unique_under_repeated_create(): void
    {
        $from = $this->makeLocation('WH-A');
        $to   = $this->makeLocation('SR-B');
        $prod = $this->makeConsumable();
        $this->setStock($from->location_id, $prod->product_id, 50);
        $admin = $this->adminUser();

        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $resp = $this->actingAs($admin, 'sanctum')->postJson('/api/transfer-requests', [
                'from_location_id' => $from->location_id,
                'to_location_id'   => $to->location_id,
                'transfer_date'    => now()->toDateString(),
                'products'         => [['product_id' => $prod->product_id, 'quantity_requested' => 1]],
            ]);
            $resp->assertCreated();
            $numbers[] = $resp->json('data.transfer_number');
        }

        $this->assertSame(count($numbers), count(array_unique($numbers)));
    }

    public function test_dashboard_stats_include_awaiting_counts(): void
    {
        $resp = $this->actingAs($this->adminUser(), 'sanctum')->getJson('/api/dashboard/stats');
        $resp->assertOk()
            ->assertJsonStructure(['data' => ['awaiting_fulfillment', 'awaiting_receipt']]);
    }

    public function test_awaiting_counts_are_location_scoped(): void
    {
        $whA  = $this->makeLocation('WH-A');
        $whB  = $this->makeLocation('WH-B');
        $dest = $this->makeLocation('SR-DEST');
        $prod = $this->makeConsumable();
        $this->setStock($whA->location_id, $prod->product_id, 10);
        $this->setStock($whB->location_id, $prod->product_id, 10);

        // Approved IST sourced from WH-A (to fulfill at WH-A)
        $this->makeApprovedIst($whA->location_id, $dest->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 2, 'quantity_scanned' => 0],
        ]);
        // Approved IST sourced from WH-B (should NOT count for a WH-A user)
        $this->makeApprovedIst($whB->location_id, $dest->location_id, [
            ['product_id' => $prod->product_id, 'quantity_requested' => 2, 'quantity_scanned' => 0],
        ]);

        // A non-admin warehouse user assigned to WH-A
        $user     = $this->makeUser();
        $whRoleId = \Illuminate\Support\Facades\DB::table('roles')->where('role_name', 'warehouse_personnel')->value('role_id');
        if ($whRoleId) {
            $user->role_id = $whRoleId;
        }
        $user->location_id = $whA->location_id;
        $user->save();

        $resp = $this->actingAs($user, 'sanctum')->getJson('/api/dashboard/stats');
        $resp->assertOk();
        $this->assertSame(1, (int) $resp->json('data.awaiting_fulfillment'));
    }

}
