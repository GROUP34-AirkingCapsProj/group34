<?php

namespace Tests\Feature\Permissions;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NavBundleSchemaTest extends TestCase
{
    public function test_nav_bundle_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('nav_permission_bundles'));
        $this->assertTrue(Schema::hasColumns('nav_permission_bundles', [
            'bundle_id', 'name', 'route_path', 'sidebar_section', 'sidebar_icon', 'description', 'sort_order', 'is_active',
        ]));
        $this->assertTrue(Schema::hasTable('nav_bundle_permissions'));
        $this->assertTrue(Schema::hasColumns('nav_bundle_permissions', [
            'bundle_id', 'permission_id', 'is_required',
        ]));
    }
}
