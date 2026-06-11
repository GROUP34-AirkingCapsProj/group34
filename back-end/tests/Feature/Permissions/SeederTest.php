<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\RolePermission;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use DatabaseTransactions;

    private array $roleId = [
        'admin' => 1, 'inventory_analyst' => 2, 'branch_manager' => 3,
        'warehouse_personnel' => 4, 'auditor' => 5,
    ];

    private function granted(string $feature, string $action, int $roleId): bool
    {
        $permId = Permission::where('feature', $feature)->where('action', $action)->value('permission_id');
        if ($permId === null) {
            return false;
        }
        $rp = RolePermission::where('role_id', $roleId)->where('permission_id', $permId)->first();
        return $rp !== null && $rp->is_granted;
    }

    public function test_seeds_reconciled_matrix(): void
    {
        (new PermissionSeeder())->run();

        // Theme B: archive is SA-only everywhere.
        $this->assertTrue($this->granted('item_master_management', 'archive', $this->roleId['admin']));
        $this->assertFalse($this->granted('item_master_management', 'archive', $this->roleId['inventory_analyst']));

        // Theme C: inventory_transfers.approve = SA, IA only.
        $this->assertTrue($this->granted('inventory_transfers', 'approve', $this->roleId['inventory_analyst']));
        $this->assertFalse($this->granted('inventory_transfers', 'approve', $this->roleId['branch_manager']));
        $this->assertFalse($this->granted('inventory_transfers', 'approve', $this->roleId['warehouse_personnel']));

        // Theme D: pos_sales_recording.create = SA, BM, WP.
        $this->assertTrue($this->granted('pos_sales_recording', 'create', $this->roleId['warehouse_personnel']));
        $this->assertFalse($this->granted('pos_sales_recording', 'create', $this->roleId['inventory_analyst']));

        // Theme E: purchase_order_creation writes = SA, IA only.
        $this->assertTrue($this->granted('purchase_order_creation', 'create', $this->roleId['inventory_analyst']));
        $this->assertFalse($this->granted('purchase_order_creation', 'create', $this->roleId['branch_manager']));

        // Theme A: auditor has no write grants (e.g. supplier create) but keeps read.
        $this->assertFalse($this->granted('supplier_management', 'create', $this->roleId['auditor']));
        $this->assertTrue($this->granted('supplier_management', 'read', $this->roleId['auditor']));

        // Stage-2 Bucket 6 decisions:
        // 6a — barcode_assignment.create keeps BM (SA,BM,WP).
        $this->assertTrue($this->granted('barcode_assignment', 'create', $this->roleId['branch_manager']));
        // 6g/6h — inventory_transfers.create keeps IA (SA,IA,BM,WP).
        $this->assertTrue($this->granted('inventory_transfers', 'create', $this->roleId['inventory_analyst']));
        $this->assertTrue($this->granted('inventory_transfers', 'create', $this->roleId['warehouse_personnel']));
        // 6l — location_based_inventory.create is SA,BM only (IA dropped).
        $this->assertTrue($this->granted('location_based_inventory', 'create', $this->roleId['branch_manager']));
        $this->assertFalse($this->granted('location_based_inventory', 'create', $this->roleId['inventory_analyst']));
        // 6r — purchase_order_creation.read adds BM (SA,IA,BM,AU).
        $this->assertTrue($this->granted('purchase_order_creation', 'read', $this->roleId['branch_manager']));

        // Re-running is idempotent (no duplicate permission rows).
        (new PermissionSeeder())->run();
        $this->assertEquals(1, Permission::where('feature', 'inventory_transfers')->where('action', 'approve')->count());
    }
}
