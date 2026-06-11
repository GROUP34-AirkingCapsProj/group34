<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roleIds = DB::table('roles')->pluck('role_id', 'role_name'); // slug => id

        foreach ($this->matrix() as $feature => $actions) {
            foreach ($actions as $action => $grantedRoles) {
                $permission = Permission::firstOrCreate(
                    ['feature' => $feature, 'action' => $action],
                    ['description' => ucfirst($action) . ' access for ' . str_replace('_', ' ', $feature)]
                );

                foreach ($roleIds as $slug => $roleId) {
                    RolePermission::updateOrCreate(
                        ['role_id' => $roleId, 'permission_id' => $permission->permission_id],
                        ['is_granted' => in_array($slug, $grantedRoles, true)]
                    );
                }
            }
        }
    }

    /**
     * Canonical reconciled matrix (spec §8). Role slugs:
     * admin, inventory_analyst, branch_manager, warehouse_personnel, auditor.
     */
    private function matrix(): array
    {
        $SA = 'admin';
        $IA = 'inventory_analyst';
        $BM = 'branch_manager';
        $WP = 'warehouse_personnel';
        $AU = 'auditor';

        return [
            // --- Group I ---
            'centralized_inventory' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'automated_inventory_monitoring' => [
                'create' => [$SA, $IA, $BM, $WP], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA, $IA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $IA, $BM, $WP],
            ],
            'location_based_inventory' => [
                'create' => [$SA, $BM], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA, $BM], 'archive' => [$SA], 'approve' => [$SA, $IA, $BM],
            ],
            'barcode_inventory_processing' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA, $WP], 'archive' => [$SA], 'approve' => [$SA, $WP],
            ],
            'inventory_receiving' => [
                'create' => [$SA, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $WP], 'archive' => [$SA], 'approve' => [$SA, $WP],
            ],
            'inventory_issuance' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $WP], 'archive' => [$SA], 'approve' => [$SA, $WP],
            ],
            'inventory_transfers' => [
                'create' => [$SA, $IA, $BM, $WP], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA, $IA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $IA],
            ],
            'inventory_adjustments' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA, $WP], 'archive' => [$SA], 'approve' => [$SA, $WP],
            ],

            // --- Group II ---
            'item_master_management' => [
                'create' => [$SA, $IA], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA, $IA], 'archive' => [$SA], 'approve' => [$SA, $IA],
            ],
            'category_management' => [
                'create' => [$SA, $IA], 'read' => [$SA, $IA, $AU], 'update' => [$SA, $IA], 'archive' => [$SA], 'approve' => [$SA, $IA],
            ],
            'brand_management' => [
                'create' => [$SA, $IA], 'read' => [$SA, $IA, $AU], 'update' => [$SA, $IA], 'archive' => [$SA], 'approve' => [$SA, $IA],
            ],
            'uom_management' => [
                'create' => [$SA, $IA], 'read' => [$SA, $IA, $AU], 'update' => [$SA, $IA], 'archive' => [$SA], 'approve' => [$SA, $IA],
            ],
            'barcode_assignment' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $WP],
            ],

            // --- Group III ---
            'user_account_management' => [
                'create' => [$SA], 'read' => [$SA, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'role_assignment' => [
                'create' => [$SA], 'read' => [$SA, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'rbac_enforcement' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],

            // --- Group IV ---
            'role_dashboards' => ['read' => [$SA, $IA, $BM, $WP, $AU]],
            'summary_cards' => ['read' => [$SA, $IA, $BM, $WP, $AU]],
            'quick_actions' => ['create' => [$SA, $IA, $BM, $WP], 'update' => [$SA, $IA, $BM, $WP]],
            'notification_panel' => ['read' => [$SA, $IA, $BM, $WP, $AU]],

            // --- Group V ---
            'reorder_monitoring' => ['read' => [$SA, $IA, $BM, $AU]],
            'safety_stock' => ['read' => [$SA, $IA, $BM, $AU]],
            'consumption_rate' => ['read' => [$SA, $IA, $BM, $AU]],
            'low_stock_prediction' => ['read' => [$SA, $IA, $BM, $AU]],
            'restock_recommendation' => ['read' => [$SA, $IA, $BM, $AU]],
            'restock_quantity' => ['read' => [$SA, $IA, $BM, $AU]],
            'restock_approval' => ['approve' => [$SA]],
            'restock_history' => ['read' => [$SA, $IA, $BM, $AU]],

            // --- Group VI ---
            'supplier_management' => [
                'create' => [$SA, $IA, $BM], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA, $IA, $BM], 'archive' => [$SA], 'approve' => [$SA, $IA, $BM],
            ],
            'item_supplier_mapping' => [
                'create' => [$SA, $IA, $BM], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA, $IA, $BM], 'archive' => [$SA], 'approve' => [$SA, $IA, $BM],
            ],
            'purchase_order_creation' => [
                'create' => [$SA, $IA], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA, $IA], 'archive' => [$SA], 'approve' => [$SA, $IA],
            ],
            'purchase_order_approval' => ['approve' => [$SA]],
            'po_status_tracking' => ['read' => [$SA, $IA, $BM, $WP, $AU]],
            'delivery_receiving' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $BM, $WP],
            ],

            // --- Group VII ---
            'pos_sales_recording' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $BM],
            ],
            'barcode_scanning_sales' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $BM],
            ],
            'sales_validation' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $BM],
            ],
            'customer_info_capture' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $BM],
            ],
            'delivery_receipt_generation' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $BM],
            ],
            'pos_transaction_logs' => [
                'create' => [$SA], 'read' => [$SA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],

            // --- Group VIII ---
            'transaction_validation' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $BM],
            ],
            'transaction_approval' => [
                'create' => [$SA], 'read' => [$SA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'transaction_rejection' => [
                'create' => [$SA], 'read' => [$SA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],

            // --- Group IX ---
            'audit_trail_viewing' => [
                'create' => [$SA], 'read' => [$SA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'transaction_logs_audit' => [
                'create' => [$SA], 'read' => [$SA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'login_logs' => [
                'create' => [$SA], 'read' => [$SA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'exception_detection' => [
                'create' => [$SA], 'read' => [$SA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],

            // --- Group X ---
            'stock_level_analysis' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'consumption_analysis' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'inventory_turnover_analysis' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],

            // --- Group XI ---
            'inventory_reports' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'transaction_reports' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'audit_reports' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'export_reports' => [
                'create' => [$SA], 'read' => [$SA, $IA, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],

            // --- Group XII ---
            'document_upload' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $BM],
            ],
            'document_verification' => [
                'create' => [$SA, $BM], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA, $BM], 'archive' => [$SA], 'approve' => [$SA, $BM],
            ],
            'document_viewing' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'data_archiving' => [
                'create' => [$SA], 'read' => [$SA, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'archive_viewing' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
        ];
    }
}
