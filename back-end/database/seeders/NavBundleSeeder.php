<?php

namespace Database\Seeders;

use App\Models\NavPermissionBundle;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class NavBundleSeeder extends Seeder
{
    public function run(): void
    {
        $order = 0;
        foreach ($this->bundles() as $routePath => $def) {
            $order++;
            $bundle = NavPermissionBundle::updateOrCreate(
                ['route_path' => $routePath],
                [
                    'name' => $def['name'],
                    'sidebar_section' => $def['section'],
                    'sidebar_icon' => $def['icon'],
                    'description' => $def['description'] ?? null,
                    'sort_order' => $def['sort'] ?? $order,
                    'is_active' => true,
                ]
            );

            $sync = [];
            foreach (($def['required'] ?? []) as $key) {
                if ($id = $this->permId($key)) $sync[$id] = ['is_required' => true];
            }
            foreach (($def['actions'] ?? []) as $key) {
                if ($id = $this->permId($key)) {
                    $sync[$id] = $sync[$id] ?? ['is_required' => false];
                }
            }
            $bundle->permissions()->sync($sync);
        }
    }

    private function permId(string $featureDotAction): ?int
    {
        [$feature, $action] = explode('.', $featureDotAction, 2);
        return Permission::where('feature', $feature)->where('action', $action)->value('permission_id');
    }

    /** Spec §2 — sidebar item → bundle. route_path => definition. */
    private function bundles(): array
    {
        return [
            // ADMINISTRATION
            '/admin/dashboard' => ['name' => 'Dashboard', 'section' => 'ADMINISTRATION', 'icon' => 'dashboard',
                'required' => ['role_dashboards.read'], 'actions' => ['summary_cards.read', 'quick_actions.create']],
            '/admin/approval-queue' => ['name' => 'Approval Queue', 'section' => 'ADMINISTRATION', 'icon' => 'approval',
                // spec had purchase_order_approval.read — that feature has no .read action; corrected to .approve
                'required' => ['purchase_order_approval.approve'], 'actions' => ['restock_approval.approve', 'item_master_management.approve']],
            '/admin/analytics' => ['name' => 'Analytics Dashboard', 'section' => 'ADMINISTRATION', 'icon' => 'analytics',
                'required' => ['stock_level_analysis.read'], 'actions' => ['consumption_analysis.read', 'inventory_turnover_analysis.read']],
            '/admin/users' => ['name' => 'User Management', 'section' => 'ADMINISTRATION', 'icon' => 'users',
                'required' => ['user_account_management.read'], 'actions' => ['user_account_management.create', 'user_account_management.update', 'user_account_management.archive', 'role_assignment.update']],
            '/admin/roles' => ['name' => 'Role & Permissions', 'section' => 'ADMINISTRATION', 'icon' => 'roles',
                'required' => ['rbac_enforcement.read'], 'actions' => ['role_assignment.update', 'rbac_enforcement.update']],
            '/admin/user-permissions' => ['name' => 'User Overrides', 'section' => 'ADMINISTRATION', 'icon' => 'roles',
                'required' => ['rbac_enforcement.read'], 'actions' => ['rbac_enforcement.update']],
            '/admin/permission-audit' => ['name' => 'Permission Audit', 'section' => 'ADMINISTRATION', 'icon' => 'audit',
                'required' => ['audit_trail_viewing.read']],
            '/admin/branches' => ['name' => 'Location Management', 'section' => 'ADMINISTRATION', 'icon' => 'location',
                'required' => ['location_based_inventory.read'], 'actions' => ['location_based_inventory.create', 'location_based_inventory.update', 'location_based_inventory.archive']],
            '/admin/archive' => ['name' => 'Archive Management', 'section' => 'ADMINISTRATION', 'icon' => 'archive',
                'required' => ['archive_viewing.read'], 'actions' => ['data_archiving.archive']],

            // PROCUREMENT & PLANNING
            '/admin/purchase-requests' => ['name' => 'Purchase Requests', 'section' => 'PROCUREMENT & PLANNING', 'icon' => 'approval',
                'required' => ['purchase_request_creation.read'], 'actions' => ['purchase_request_creation.create', 'purchase_request_approval.approve']],
            '/admin/draft-po-creator' => ['name' => 'Purchase Orders', 'section' => 'PROCUREMENT & PLANNING', 'icon' => 'procurement',
                'required' => ['purchase_order_creation.read'], 'actions' => ['purchase_order_creation.create', 'purchase_order_creation.update', 'supplier_management.read']],
            '/admin/po-recommendations' => ['name' => 'PO Recommendation', 'section' => 'PROCUREMENT & PLANNING', 'icon' => 'po',
                'required' => ['restock_recommendation.read'], 'actions' => ['restock_quantity.read', 'restock_approval.approve']],
            '/admin/receivings' => ['name' => 'PO Receiving', 'section' => 'PROCUREMENT & PLANNING', 'icon' => 'receiving',
                'required' => ['delivery_receiving.read'], 'actions' => ['inventory_receiving.create']],
            '/admin/suppliers' => ['name' => 'Supplier Network', 'section' => 'PROCUREMENT & PLANNING', 'icon' => 'supplier',
                'required' => ['supplier_management.read'], 'actions' => ['supplier_management.create', 'supplier_management.update', 'item_supplier_mapping.update']],
            '/admin/items' => ['name' => 'Item Master', 'section' => 'PROCUREMENT & PLANNING', 'icon' => 'items',
                'required' => ['item_master_management.read'], 'actions' => ['item_master_management.create', 'item_master_management.update', 'item_master_management.archive', 'barcode_assignment.create']],
            '/admin/categories' => ['name' => 'Categories and Brands', 'section' => 'PROCUREMENT & PLANNING', 'icon' => 'category',
                'required' => ['category_management.read'], 'actions' => ['category_management.create', 'category_management.update', 'brand_management.read', 'brand_management.create', 'brand_management.update']],

            // INVENTORY INTELLIGENCE
            '/admin/inventory' => ['name' => 'Inventory Monitoring', 'section' => 'INVENTORY INTELLIGENCE', 'icon' => 'inventory',
                'required' => ['centralized_inventory.read'], 'actions' => ['automated_inventory_monitoring.read', 'location_based_inventory.read']],
            '/admin/transfer-requests' => ['name' => 'Transfer Requests', 'section' => 'INVENTORY INTELLIGENCE', 'icon' => 'transfer',
                'required' => ['inventory_transfers.read'], 'actions' => ['inventory_transfers.create']],
            '/admin/transfers' => ['name' => 'Stock Transfers', 'section' => 'INVENTORY INTELLIGENCE', 'icon' => 'transfer',
                'required' => ['inventory_transfers.read'], 'actions' => ['inventory_transfers.create', 'inventory_transfers.approve']],
            '/admin/ist' => ['name' => 'Interbranch Transfers', 'section' => 'INVENTORY INTELLIGENCE', 'icon' => 'transfer',
                'required' => ['inventory_transfers.read'], 'actions' => ['inventory_transfers.create', 'inventory_transfers.update', 'inventory_transfers.approve']],
            '/admin/adjustments' => ['name' => 'Adjustments', 'section' => 'INVENTORY INTELLIGENCE', 'icon' => 'inventoryop',
                'required' => ['inventory_adjustments.read'], 'actions' => ['inventory_adjustments.create', 'inventory_adjustments.approve']],
            '/admin/issuances' => ['name' => 'Issuances', 'section' => 'INVENTORY INTELLIGENCE', 'icon' => 'issuance-scan',
                'required' => ['inventory_issuance.read'], 'actions' => ['inventory_issuance.create']],

            // SALES & LOGISTICS
            '/admin/transactions' => ['name' => 'POS Operations', 'section' => 'SALES & LOGISTICS', 'icon' => 'transactions',
                'required' => ['pos_sales_recording.read'], 'actions' => ['pos_sales_recording.create', 'barcode_scanning_sales.create', 'sales_validation.create']],
            '/admin/purchase-orders' => ['name' => 'Purchase Orders', 'section' => 'SALES & LOGISTICS', 'icon' => 'purchaseorders',
                'required' => ['po_status_tracking.read'], 'actions' => ['purchase_order_approval.approve']],
            '/admin/customers' => ['name' => 'Customer Registry', 'section' => 'SALES & LOGISTICS', 'icon' => 'customers',
                'required' => ['customer_info_capture.read'], 'actions' => ['customer_info_capture.create', 'customer_info_capture.update']],
            '/admin/inventory-operation' => ['name' => 'Scan Barcode', 'section' => 'SALES & LOGISTICS', 'icon' => 'scan',
                'required' => ['barcode_inventory_processing.create']],
            '/admin/issuance-scan' => ['name' => 'Issuance Scan', 'section' => 'SALES & LOGISTICS', 'icon' => 'issuance-scan',
                'required' => ['inventory_issuance.create']],
            '/admin/delivery-receipts' => ['name' => 'Delivery Receipts', 'section' => 'SALES & LOGISTICS', 'icon' => 'reports',
                'required' => ['delivery_receipt_generation.read'], 'actions' => ['delivery_receipt_generation.create']],

            // COMPLIANCE & SUPPORT
            '/admin/compliance' => ['name' => 'Compliance Review', 'section' => 'COMPLIANCE & SUPPORT', 'icon' => 'compliance',
                'required' => ['exception_detection.read']],
            '/admin/reports' => ['name' => 'System Reports', 'section' => 'COMPLIANCE & SUPPORT', 'icon' => 'reports',
                'required' => ['inventory_reports.read'], 'actions' => ['transaction_reports.read', 'audit_reports.read', 'export_reports.create']],
            '/admin/audit' => ['name' => 'Audit Trail', 'section' => 'COMPLIANCE & SUPPORT', 'icon' => 'audit',
                'required' => ['audit_trail_viewing.read'], 'actions' => ['transaction_logs_audit.read', 'login_logs.read']],
            '/admin/alerts' => ['name' => 'Alerts & Notices', 'section' => 'COMPLIANCE & SUPPORT', 'icon' => 'notifications',
                'required' => ['notification_panel.read']],
        ];
    }
}
