<?php

namespace Tests\Feature\Alerts;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Tests\Feature\Transfer\TransferTestCase;

class WarehouseNotificationsTest extends TransferTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionsSeeder::class);
    }

    private function po($location, int $status = 8): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_number' => 'PO-NOTIFY-' . uniqid(), 'location_id' => $location->location_id,
            'supplier_id' => Supplier::create(['supplier_name' => 'Notification Supplier'])->supplier_id, 'status_id' => $status,
            'order_date' => now(), 'verification_status' => 'verified',
            'created_by' => User::firstOrFail()->user_id,
        ]);
    }

    public function test_po_approval_after_a_user_read_the_old_order_is_unread_and_scoped(): void
    {
        $warehouse = $this->makeLocation('Receiver');
        $other = $this->makeLocation('Other');
        $user = $this->makeUserAt($warehouse, 'warehouse_personnel');
        $po = $this->po($warehouse, 7);
        $po->forceFill(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)])->save();
        $user->forceFill(['alerts_last_seen_at' => now()->subDay()])->save();
        $po->update(['status_id' => 8]);
        $this->po($other);
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/alerts/settings')->assertOk();
        $response->assertJsonPath('data.warehouse_unread_count', 1);
        $response->assertJsonPath('data.warehouse_notifications.0.id', 'po_receive:' . $po->po_id);
        $this->getJson('/api/layout-summary')->assertOk()->assertJsonPath('data.warehouse_unread_ids.0', 'po_receive:' . $po->po_id);
        $this->postJson('/api/alerts/mark-all-read')->assertOk();
        $this->getJson('/api/alerts/settings')->assertJsonPath('data.warehouse_unread_count', 0)
            ->assertJsonCount(1, 'data.warehouse_notifications');
        $po->update(['status_id' => 11]);
        $this->getJson('/api/alerts/settings')->assertJsonCount(0, 'data.warehouse_notifications');
    }

    public function test_direct_transfers_notify_source_for_dispatch_and_destination_after_dispatch(): void
    {
        $source = $this->makeLocation('Source');
        $destination = $this->makeLocation('Destination');
        $unrelated = $this->makeLocation('Unrelated');
        $transfer = $this->makeApprovedTransfer($source, $destination, [[$this->makeInstallationMaterial(), 1]]);
        $sourceUser = $this->makeUserAt($source, 'warehouse_personnel');
        $destinationUser = $this->makeUserAt($destination, 'warehouse_personnel');
        $otherUser = $this->makeUserAt($unrelated, 'warehouse_personnel');
        $this->actingAs($sourceUser, 'sanctum')->getJson('/api/alerts/settings')
            ->assertJsonPath('data.warehouse_notifications.0.type', 'transfer_dispatch');
        $this->getJson('/api/layout-summary')->assertJsonPath('data.awaiting_fulfillment', 1);
        $this->actingAs($destinationUser, 'sanctum')->getJson('/api/alerts/settings')->assertJsonCount(0, 'data.warehouse_notifications');
        $transfer->update(['status_id' => $this->statusId('In Transit')]);
        $this->actingAs($sourceUser, 'sanctum')->getJson('/api/alerts/settings')->assertJsonCount(0, 'data.warehouse_notifications');
        $this->actingAs($destinationUser, 'sanctum')->getJson('/api/alerts/settings')
            ->assertJsonPath('data.warehouse_notifications.0.type', 'transfer_receive');
        $this->getJson('/api/layout-summary')->assertJsonPath('data.awaiting_receipt', 1);
        $this->actingAs($otherUser, 'sanctum')->getJson('/api/alerts/settings')->assertJsonCount(0, 'data.warehouse_notifications');
        $transfer->update(['status_id' => $this->statusId('Received')]);
        $this->actingAs($destinationUser, 'sanctum')->getJson('/api/alerts/settings')->assertJsonCount(0, 'data.warehouse_notifications');
    }

    public function test_warehouse_personnel_without_an_assigned_location_get_no_work_notifications(): void
    {
        $warehouse = $this->makeLocation('Warehouse');
        $this->po($warehouse);
        $user = $this->makeUserAt($warehouse, 'warehouse_personnel');
        $user->update(['location_id' => null]);
        $this->actingAs($user, 'sanctum')->getJson('/api/alerts/settings')->assertJsonCount(0, 'data.warehouse_notifications');
    }

    public function test_editing_notes_does_not_make_a_read_approval_unread_again(): void
    {
        $warehouse = $this->makeLocation('Warehouse');
        $user = $this->makeUserAt($warehouse, 'warehouse_personnel');
        $this->actingAs($user, 'sanctum');
        $po = $this->po($warehouse);
        $this->getJson('/api/alerts/settings')->assertJsonPath('data.warehouse_unread_count', 1);
        $this->postJson('/api/alerts/mark-all-read')->assertOk();
        $this->travel(5)->seconds();
        $po->update(['notes' => 'Delivery instructions updated']);
        $this->getJson('/api/alerts/settings')->assertJsonPath('data.warehouse_unread_count', 0);
        $this->travelBack();
    }
}
