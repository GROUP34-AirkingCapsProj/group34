<?php

namespace Database\Seeders;

use App\Models\NavBundlePermission;
use App\Models\NavPermissionBundle;
use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NavBundleSeeder extends Seeder
{
    /**
     * Each entry: [name, route_path, sidebar_section, sidebar_icon, feature, action]
     * sort_order is 0-based index within the section (order of appearance below).
     */
    public static function bundles(): array
    {
        return [
            // ADMINISTRATION
            ['Dashboard',            '/admin/dashboard',            'ADMINISTRATION',          'dashboard',          'dashboard_access',        'read'],
            ['Analytics Dashboard',  '/admin/analytics',            'ADMINISTRATION',          'analytics',          'analytics_dashboard',     'read'],
            ['Role & Permissions',   '/admin/roles',                'ADMINISTRATION',          'roles',              'role_assignment',         'read'],
            ['Permission Audit',     '/admin/permission-audit',     'ADMINISTRATION',          'audit',              'permission_audit',        'read'],
            ['Location Management',  '/admin/branches',             'ADMINISTRATION',          'location',           'location_based_inventory','read'],
            ['System Configuration', '/admin/config',               'ADMINISTRATION',          'config',             'system_configuration',    'read'],
            ['Archive Management',   '/admin/archive',              'ADMINISTRATION',          'archive',            'archive_viewing',         'read'],

            // PROCUREMENT & PLANNING
            ['Purchase Requests',    '/admin/purchase-requests',    'PROCUREMENT & PLANNING',  'approval',           'purchase_request_creation','read'],
            ['Purchase Orders',      '/admin/draft-po-creator',     'PROCUREMENT & PLANNING',  'procurement',        'purchase_order_creation', 'read'],
            ['Supplier Network',     '/admin/suppliers',            'PROCUREMENT & PLANNING',  'supplier',           'supplier_management',     'read'],
            ['Item Master',          '/admin/items',                'PROCUREMENT & PLANNING',  'items',              'item_master_management',  'read'],
            ['Item Lookups',          '/admin/categories',          'PROCUREMENT & PLANNING',  'category',           'lookup_management',       'read'],
            ['Set Management',       '/admin/sets',                 'PROCUREMENT & PLANNING',  'sets',               'product_set_management',  'read'],
            ['Aircon Models',        '/admin/aircon-models',        'PROCUREMENT & PLANNING',  'sets',               'aircon_model_management', 'read'],

            // INVENTORY INTELLIGENCE
            ['Inventory Monitoring', '/admin/inventory',            'INVENTORY INTELLIGENCE',  'inventory',          'inventory_monitoring',    'read'],
            ['Transfer Requests',    '/admin/transfer-requests',    'INVENTORY INTELLIGENCE',  'transfer',           'inventory_transfers',     'read'],
            ['Stock Transfers',      '/admin/transfers',            'INVENTORY INTELLIGENCE',  'transfer',           'stock_transfers',         'read'],
            ['Interbranch Transfers','/admin/ist',                  'INVENTORY INTELLIGENCE',  'transfer',           'inventory_transfers',     'read'],
            ['Incident Reports',     '/admin/incidents',            'INVENTORY INTELLIGENCE',  'incident',           'incident_reports',        'read'],
            // Gated on `read`, not `inspect`: the page shows what is waiting,
            // and the write actions on it check `inspect` for themselves.
            ['Inspections',          '/admin/inspections',          'INVENTORY INTELLIGENCE',  'incident',           'incident_reports',        'read'],
            // Was 'Held Stock' at /admin/held-stock. It is a read-only register
            // now — the inspection took over the Dispose action it existed for.
            ['Incident Items',       '/admin/incident-items',       'INVENTORY INTELLIGENCE',  'incident',           'incident_reports',        'read'],

            // SALES & LOGISTICS
            ['Transactions',         '/admin/transactions',         'SALES & LOGISTICS',       'globaltransactions', 'sales_recording',     'read'],
            ['Customer Registry',    '/admin/customers',            'SALES & LOGISTICS',       'customers',          'customer_management', 'read'],
            ['Scan Barcode',         '/admin/inventory-operation',  'SALES & LOGISTICS',       'scan',               'barcode_inventory_processing', 'create'],

            // COMPLIANCE & SUPPORT
            ['Compliance Review',    '/admin/compliance',           'COMPLIANCE & SUPPORT',    'compliance',         'exception_detection',     'read'],
            ['Warranty Records',     '/admin/warranties',           'COMPLIANCE & SUPPORT',    'warranty',           'warranty_records',        'read'],
            ['System Reports',       '/admin/reports',              'COMPLIANCE & SUPPORT',    'reports',            'report_viewing',          'read'],
            ['Alerts & Notices',     '/admin/alerts',               'COMPLIANCE & SUPPORT',    'notifications',      'notification_panel',      'read'],
            ['Audit Trail',          '/admin/audit',                'COMPLIANCE & SUPPORT',    'audit',              'audit_trail_viewing',     'read'],
            ['Audit & Evidence',     '/admin/audit-evidence',       'COMPLIANCE & SUPPORT',    'audit',              'audit_evidence',          'read'],
        ];
    }

    public function run(): void
    {
        DB::transaction(function () {
            $sectionSortCounters = [];

            foreach (self::bundles() as $row) {
                [$name, $routePath, $section, $icon, $feature, $action] = $row;

                // Compute 0-based sort_order per section
                $sortOrder = $sectionSortCounters[$section] ?? 0;
                $sectionSortCounters[$section] = $sortOrder + 1;

                // Upsert bundle
                $bundle = NavPermissionBundle::updateOrCreate(
                    ['route_path' => $routePath],
                    [
                        'name'            => $name,
                        'sidebar_section' => $section,
                        'sidebar_icon'    => $icon,
                        'sort_order'      => $sortOrder,
                        'is_active'       => true,
                    ]
                );

                // Resolve the required permission
                $permission = Permission::where('feature', $feature)
                    ->where('action', $action)
                    ->firstOrFail();

                // Upsert the bundle→permission link
                NavBundlePermission::updateOrCreate(
                    [
                        'bundle_id'     => $bundle->bundle_id,
                        'permission_id' => $permission->permission_id,
                    ],
                    ['is_required' => true]
                );

                // Prune links to any OTHER permission on this bundle. Without
                // this the seeder only ever adds: changing a bundle's feature
                // (as when /admin/categories moved from category_management to
                // lookup_management) leaves the old link in place, and the
                // bundle then requires BOTH permissions — silently hiding the
                // nav item from users who hold only the new one.
                NavBundlePermission::where('bundle_id', $bundle->bundle_id)
                    ->where('permission_id', '!=', $permission->permission_id)
                    ->delete();
            }
        });

        $this->command->info('NavBundleSeeder: ' . count(self::bundles()) . ' bundles seeded.');
    }
}
