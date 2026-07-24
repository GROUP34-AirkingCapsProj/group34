<?php

namespace Tests\Feature\Permissions;

use Database\Seeders\NavBundleSeeder;
use Database\Seeders\PermissionSeeder;
use Tests\TestCase;

/**
 * Guards the invariant that every permission feature stays reachable from the
 * Navigation Access UI: either it is a nav bundle's required feature, or it is an
 * intentional "surfaced as an extra/orphan" feature listed below (mirrored by the
 * frontend navConfigureMap). A new feature with no home fails here loudly.
 */
class PermissionCoverageTest extends TestCase
{
    /**
     * Features intentionally without their own nav bundle. They are reachable in
     * the UI as EXTRA_FEATURES_BY_ROUTE or ORPHAN_FEATURES entries in
     * frontend/src/admin/utils/navConfigureMap.js — keep the two in sync.
     */
    private const ALLOWED_NON_NAV = [
        'user_account_management',      // extra of System Configuration
        'brand_management',             // extra of Categories and Brands
        'barcode_assignment',           // extra of Item Master
        'item_supplier_mapping',        // extra of Supplier Network
        'purchase_request_approval',    // extra of Purchase Requests
        'po_recommendations',           // extra of Purchase Requests (Stock Parameters tab)
        'purchase_order_verification',  // extra of Purchase Orders
        'delivery_receiving',           // extra of Scan Barcode
        'delivery_receipt_generation',  // extra of Transactions
        'inventory_issuance',           // extra of Scan Barcode
        'inventory_adjustments',        // extra of Scan Barcode
        'data_archiving',               // extra of Archive Management
        'warranty_claims',              // extra of Warranty Records
        'product_set_management',       // admin page lands in Task 6; no nav bundle yet
    ];

    private function bundleRequiredFeatures(): array
    {
        // NavBundleSeeder rows: [name, route, section, icon, feature, action]
        return array_values(array_unique(array_map(
            fn ($row) => $row[4],
            NavBundleSeeder::bundles()
        )));
    }

    public function test_every_permission_feature_is_reachable(): void
    {
        $catalogFeatures = array_keys(PermissionSeeder::matrix());
        $reachable = array_merge($this->bundleRequiredFeatures(), self::ALLOWED_NON_NAV);

        $orphans = array_values(array_diff($catalogFeatures, $reachable));

        $this->assertSame([], $orphans,
            'These features have no nav bundle and are not in ALLOWED_NON_NAV, so they '
            . 'would be unreachable in the permission UI: ' . implode(', ', $orphans));
    }

    public function test_every_bundle_feature_exists_in_the_catalog(): void
    {
        $catalogFeatures = array_keys(PermissionSeeder::matrix());
        $unknown = array_values(array_diff($this->bundleRequiredFeatures(), $catalogFeatures));

        $this->assertSame([], $unknown,
            'These nav bundles reference features absent from PermissionSeeder: '
            . implode(', ', $unknown));
    }

    public function test_allowed_non_nav_features_are_real_catalog_features(): void
    {
        $catalogFeatures = array_keys(PermissionSeeder::matrix());
        $stale = array_values(array_diff(self::ALLOWED_NON_NAV, $catalogFeatures));

        $this->assertSame([], $stale,
            'ALLOWED_NON_NAV lists features not in the catalog (stale): ' . implode(', ', $stale));
    }
}
