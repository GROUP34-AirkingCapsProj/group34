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
    private static function bundles(): array
    {
        return [
            // ADMINISTRATION (sort 0–3)
            ['Approval Queue',       '/admin/approval-queue',       'ADMINISTRATION',          'approval',           'purchase_order_creation', 'read'],
            ['User Management',      '/admin/users',                'ADMINISTRATION',          'users',              'user_account_management', 'read'],
            ['Location Management',  '/admin/branches',             'ADMINISTRATION',          'location',           'location_based_inventory','read'],
            ['Archive Management',   '/admin/archive',              'ADMINISTRATION',          'archive',            'archive_viewing',         'read'],

            // PROCUREMENT & PLANNING
            ['Purchase Requests',    '/admin/purchase-requests',    'PROCUREMENT & PLANNING',  'approval',           'purchase_request_creation','read'],
            ['Purchase Orders',      '/admin/draft-po-creator',     'PROCUREMENT & PLANNING',  'procurement',        'purchase_order_creation', 'read'],
            ['PO Receiving',         '/admin/receivings',           'PROCUREMENT & PLANNING',  'receiving',          'delivery_receiving',      'read'],
            ['Supplier Network',     '/admin/suppliers',            'PROCUREMENT & PLANNING',  'supplier',           'supplier_management',     'read'],
            ['Item Master',          '/admin/items',                'PROCUREMENT & PLANNING',  'items',              'item_master_management',  'read'],
            ['Categories and Brands','/admin/categories',           'PROCUREMENT & PLANNING',  'category',           'category_management',     'read'],

            // INVENTORY INTELLIGENCE (sort 0–1)
            ['Transfer Requests',    '/admin/transfer-requests',    'INVENTORY INTELLIGENCE',  'transfer',           'inventory_transfers',     'read'],
            ['Interbranch Transfers','/admin/ist',                  'INVENTORY INTELLIGENCE',  'transfer',           'inventory_transfers',     'read'],

            // SALES & LOGISTICS (sort 0–1)
            ['Transactions',         '/admin/transactions',         'SALES & LOGISTICS',       'globaltransactions', 'sales_recording',     'read'],
            ['Scan Barcode',         '/admin/inventory-operation',  'SALES & LOGISTICS',       'scan',               'barcode_inventory_processing', 'create'],

            // COMPLIANCE & SUPPORT
            ['Compliance Review',    '/admin/compliance',           'COMPLIANCE & SUPPORT',    'compliance',         'exception_detection',     'read'],
            ['Warranty Records',     '/admin/warranties',           'COMPLIANCE & SUPPORT',    'warranty',           'warranty_records',        'read'],
            ['Warranty Claims',      '/admin/warranty-claims',      'COMPLIANCE & SUPPORT',    'warranty',           'warranty_claims',         'read'],
            ['System Reports',       '/admin/reports',              'COMPLIANCE & SUPPORT',    'reports',            'report_viewing',          'read'],
            ['Alerts & Notices',     '/admin/alerts',               'COMPLIANCE & SUPPORT',    'notifications',      'notification_panel',      'read'],
            ['Audit Trail',          '/admin/audit',                'COMPLIANCE & SUPPORT',    'audit',              'audit_trail_viewing',     'read'],
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
            }
        });

        $this->command->info('NavBundleSeeder: 20 bundles seeded.');
    }
}
