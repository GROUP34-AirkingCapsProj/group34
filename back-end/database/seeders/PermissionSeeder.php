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
            // Unified Item Lookups page (/admin/categories). Covers all six
            // lookups on the page: categories, brands, variants, capacities,
            // units of measure and issuable unit presets. The older
            // category_management / brand_management keys are retained and
            // still guard their own endpoints, so effective access is unchanged.
            'lookup_management' => [
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
            // Set/BOQ catalog (/admin/sets) — admin maintains, IA and warehouse read
            // so the issuance picker can list sets.
            'product_set_management' => [
                'read'    => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'create'  => ['admin'],
                'update'  => ['admin'],
                'archive' => ['admin'],
            ],
            // Aircon model catalogue (/admin/aircon-models) — admin maintains;
            // everyone who builds an order needs to read it so the picker can
            // list models.
            'aircon_model_management' => [
                'read'    => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'create'  => ['admin'],
                'update'  => ['admin'],
                'archive' => ['admin'],
            ],
            // Incident Reports (/admin/incidents) — pickers file, IA and admin resolve.
            // Incident Reports (/admin/incidents) — pickers and anyone who spots a
            // problem file; IA and admin resolve. `auditor` stays read-only: filing
            // rows would break the read-only guarantee that role carries elsewhere.
            'incident_reports' => [
                'read'    => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'create'  => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel'],
                // Examining the goods, attaching the evidence and recording an
                // outcome per item. Separate from `resolve` on purpose: the
                // client's form carries two signature lines precisely so one
                // person cannot both examine the goods and approve their
                // write-off. `auditor` stays read-only.
                'inspect' => ['admin', 'inventory_analyst', 'branch_manager'],
                // Confirming or rejecting a submitted inspection — the moment
                // stock actually moves. Retired with the stock-holds work:
                // `dispose`, which the inspection replaces.
                'resolve' => ['admin', 'inventory_analyst'],
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
            'purchase_order_verification' => [
                'read'   => ['admin', 'auditor'],
                'verify' => ['auditor'],
            ],
            // Audit & Evidence Explorer (page /admin/audit-evidence). Both lenses
            // (inventory and finance) are visible to anyone holding read — the split
            // is a view concern, not a permission boundary. The page is read-only
            // apart from resolving a drift finding, which is the auditor's own act
            // and the only thing they can write.
            'audit_evidence' => [
                'read'              => ['admin', 'auditor'],
                'resolve_exception' => ['auditor'],
            ],
            // PR Recommendation analytics (inline in Purchase Requests; Stock Parameters tab).
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
            // GET /issuances (list) remains ungated; 'read' here is scoped to the
            // priced quote endpoint, and 'view_margin' further gates cost/margin visibility.
            'inventory_issuance' => [
                'create'      => ['admin', 'branch_manager', 'warehouse_personnel'],
                'update'      => ['admin', 'warehouse_personnel'],
                'archive'     => ['admin'],
                // Accept a FIFO violation at issuance with a written reason.
                // Granted to exactly the roles that hold 'update': an override is
                // a stronger form of committing a pick, so nobody who cannot pick
                // should be able to authorise a deviation. Also added by
                // 2026_08_30_000006 for already-deployed databases.
                'fifo_override' => ['admin', 'warehouse_personnel'],
                'read'        => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
                'view_margin' => ['admin', 'inventory_analyst', 'branch_manager', 'auditor'],
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
            // Stock Transfers page (/admin/transfers). Every role that works
            // stock reaches this page, so it is granted as widely as
            // inventory_monitoring above — which is what the live database
            // already holds, and what the frontend has been enforcing all
            // along. Seeding it admin-only would lock four roles out of a
            // page they use on a fresh install.
            'stock_transfers' => [
                'read' => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
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
                'read'   => ['admin', 'inventory_analyst', 'auditor'],
                // Generating writes a file and a report_runs row, so it is a
                // separate action from viewing the catalog.
                'create' => ['admin', 'inventory_analyst', 'auditor'],
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
