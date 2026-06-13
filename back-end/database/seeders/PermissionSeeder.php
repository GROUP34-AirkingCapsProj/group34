<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Authoritative permission matrix.
     * Shape: [feature => [action => [granted_role_slugs]]]
     */
    public static function matrix(): array
    {
        return [
            'user_account_management' => [
                'read'    => ['admin', 'auditor'],
                'create'  => ['admin'],
                'update'  => ['admin'],
                'archive' => ['admin'],
            ],
            'role_assignment' => [
                'create'  => ['admin'],
                'update'  => ['admin'],
                'archive' => ['admin'],
            ],
            'category_management' => [
                'read'    => ['admin', 'inventory_analyst', 'auditor'],
                'create'  => ['admin', 'inventory_analyst'],
                'update'  => ['admin', 'inventory_analyst'],
                'archive' => ['admin'],
            ],
            'brand_management' => [
                'read'    => ['admin', 'inventory_analyst', 'auditor'],
                'create'  => ['admin', 'inventory_analyst'],
                'update'  => ['admin', 'inventory_analyst'],
                'archive' => ['admin'],
            ],
            'uom_management' => [
                'create'  => ['admin', 'inventory_analyst'],
                'update'  => ['admin', 'inventory_analyst'],
                'archive' => ['admin'],
            ],
            'location_based_inventory' => [
                'read'    => ['admin'],
                'create'  => ['admin'],
                'update'  => ['admin'],
                'archive' => ['admin'],
            ],
            'item_master_management' => [
                'read'    => ['admin', 'inventory_analyst', 'branch_manager', 'auditor'],
                'create'  => ['admin', 'inventory_analyst'],
                'update'  => ['admin', 'inventory_analyst'],
                'archive' => ['admin'],
                'approve' => ['admin', 'inventory_analyst'],
            ],
            'barcode_assignment' => [
                'create'  => ['admin', 'branch_manager', 'warehouse_personnel'],
                'update'  => ['admin', 'branch_manager', 'warehouse_personnel'],
                'archive' => ['admin'],
            ],
            'supplier_management' => [
                'read'    => ['admin', 'inventory_analyst', 'branch_manager', 'auditor'],
                'create'  => ['admin', 'inventory_analyst', 'branch_manager'],
                'update'  => ['admin', 'inventory_analyst', 'branch_manager'],
                'archive' => ['admin'],
            ],
            'item_supplier_mapping' => [
                'create' => ['admin', 'inventory_analyst', 'branch_manager'],
                'update' => ['admin', 'inventory_analyst', 'branch_manager'],
            ],
            'purchase_order_creation' => [
                'read'    => ['admin', 'inventory_analyst', 'branch_manager', 'auditor'],
                'create'  => ['admin', 'inventory_analyst'],
                'update'  => ['admin', 'inventory_analyst'],
                'archive' => ['admin'],
            ],
            'purchase_request_approval' => [
                'approve' => ['admin'],
            ],
            'delivery_receiving' => [
                'read'    => ['admin', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'create'  => ['admin', 'branch_manager', 'warehouse_personnel'],
                'update'  => ['admin', 'branch_manager', 'warehouse_personnel'],
                'archive' => ['admin'],
            ],
            'pos_sales_recording' => [
                'read'    => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'create'  => ['admin', 'branch_manager', 'warehouse_personnel'],
                'update'  => ['admin', 'branch_manager', 'warehouse_personnel'],
                'archive' => ['admin'],
            ],
            'delivery_receipt_generation' => [
                'read'    => ['admin', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'create'  => ['admin', 'branch_manager', 'warehouse_personnel'],
                'update'  => ['admin', 'branch_manager', 'warehouse_personnel'],
                'archive' => ['admin'],
            ],
            'inventory_issuance' => [
                'read'    => ['admin', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'create'  => ['admin', 'branch_manager', 'warehouse_personnel'],
                'update'  => ['admin', 'warehouse_personnel'],
                'archive' => ['admin'],
            ],
            'inventory_transfers' => [
                'read'    => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'create'  => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel'],
                'update'  => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel'],
                'approve' => ['admin', 'inventory_analyst'],
            ],
            'inventory_adjustments' => [
                'read'    => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'create'  => ['admin', 'branch_manager', 'warehouse_personnel'],
                'update'  => ['admin', 'warehouse_personnel'],
                'archive' => ['admin'],
            ],
            'barcode_inventory_processing' => [
                'create' => ['admin', 'branch_manager', 'warehouse_personnel'],
            ],
            'exception_detection' => [
                'read' => ['admin', 'branch_manager', 'auditor'],
            ],
            'audit_trail_viewing' => [
                'read'    => ['admin', 'branch_manager', 'auditor'],
                'archive' => ['admin'],
            ],
            'archive_viewing' => [
                'read' => ['admin', 'inventory_analyst', 'branch_manager', 'auditor'],
            ],
            'data_archiving' => [
                'update'  => ['admin'],
                'archive' => ['admin'],
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::matrix() as $feature => $actions) {
            foreach ($actions as $action => $grantedRoles) {
                Permission::firstOrCreate(
                    ['feature' => $feature, 'action' => $action],
                    ['description' => ucfirst($action) . ' access for ' . str_replace('_', ' ', $feature)]
                );
            }
        }

        $this->command->info('Permissions seeded.');
    }
}
