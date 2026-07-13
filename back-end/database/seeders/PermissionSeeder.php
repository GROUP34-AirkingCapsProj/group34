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
            // Admin-only: User Management now lives inside the admin-only System
            // Configuration page, so the auditor no longer has read access here.
            'user_account_management' => [
                'read'    => ['admin'],
                'create'  => ['admin'],
                'update'  => ['admin'],
                'archive' => ['admin'],
            ],
            'role_assignment' => [
                'read'    => ['admin'],
                'create'  => ['admin'],
                'update'  => ['admin'],
                'archive' => ['admin'],
            ],
            // Permission audit log viewer (/admin/permission-audit) — admin only.
            'permission_audit' => [
                'read' => ['admin'],
            ],
            // System Configuration page (/admin/config) — admin only. Hosts user
            // management + system settings.
            'system_configuration' => [
                'read'   => ['admin'],
                'update' => ['admin'],
            ],
            // Main dashboard (/admin/dashboard) — every role has a landing dashboard.
            'dashboard_access' => [
                'read' => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
            ],
            // Analytics dashboard (/admin/analytics).
            'analytics_dashboard' => [
                'read' => ['admin', 'inventory_analyst', 'branch_manager', 'auditor'],
            ],
            'category_management' => [
                'read'    => ['admin', 'inventory_analyst', 'auditor'],
                'create'  => ['admin', 'inventory_analyst'],
                'update'  => ['admin', 'inventory_analyst'],
                'archive' => ['admin'],
            ],
            // Brands are managed inside the "Categories & Brands" page (gated by
            // category_management.read), so brand_management has no standalone read.
            'brand_management' => [
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
            'purchase_request_creation' => [
                'read'    => ['admin', 'inventory_analyst'],
                'create'  => ['admin', 'inventory_analyst'],
                'archive' => ['admin'],
            ],
            'purchase_request_approval' => [
                'approve' => ['admin'],
            ],
            // Budget allocations (page /admin/budget-management). read+verify are the
            // audit gates; create/update/archive let admin delegate budget CRUD.
            'budget_verification' => [
                'read'    => ['admin', 'auditor'],
                'create'  => ['admin'],
                'update'  => ['admin'],
                'archive' => ['admin'],
                'verify'  => ['auditor'],
            ],
            'purchase_order_verification' => [
                'read'   => ['admin', 'auditor'],
                'verify' => ['auditor'],
            ],
            // PO Recommendation analytics (page is at /admin/po-recommendations).
            // create = recalculate + create draft PR; update = apply/dismiss
            // stock parameter suggestions (edits product master data).
            'po_recommendations' => [
                'read'   => ['admin', 'inventory_analyst', 'branch_manager', 'auditor'],
                'create' => ['admin', 'inventory_analyst'],
                'update' => ['admin', 'inventory_analyst'],
            ],
            'delivery_receiving' => [
                'read'    => ['admin', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'create'  => ['admin', 'branch_manager', 'warehouse_personnel'],
                'update'  => ['admin', 'branch_manager', 'warehouse_personnel'],
                'archive' => ['admin'],
            ],
            'sales_recording' => [
                'read'    => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'create'  => ['admin', 'branch_manager', 'warehouse_personnel'],
                'update'  => ['admin', 'branch_manager', 'warehouse_personnel'],
                'archive' => ['admin'],
            ],
            // Customer Registry (/admin/customers).
            'customer_management' => [
                'read'    => ['admin', 'branch_manager', 'warehouse_personnel', 'auditor'],
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
            // Issuances have no dedicated sidebar/list gate (GET /issuances is open),
            // so inventory_issuance has no standalone read — only the write actions.
            'inventory_issuance' => [
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
            // Inventory Monitoring read-only view (/admin/inventory).
            'inventory_monitoring' => [
                'read' => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
            ],
            // Stock Transfers legacy admin page (/admin/transfers).
            'stock_transfers' => [
                'read' => ['admin'],
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
            // Warranty records (currently open to all authenticated users — reproduced here).
            'warranty_records' => [
                'read'   => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'update' => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
            ],
            // Warranty claim workflow (currently role:admin,branch_manager,warehouse_personnel).
            'warranty_claims' => [
                'read'    => ['admin', 'branch_manager', 'warehouse_personnel'],
                'create'  => ['admin', 'branch_manager', 'warehouse_personnel'],
                'update'  => ['admin', 'branch_manager', 'warehouse_personnel'],
                'approve' => ['admin', 'branch_manager', 'warehouse_personnel'],
            ],
            'report_viewing' => [
                'read' => ['admin', 'inventory_analyst', 'auditor'],
            ],
            'notification_panel' => [
                'read' => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
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
