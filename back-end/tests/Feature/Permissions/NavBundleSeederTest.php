<?php

namespace Tests\Feature\Permissions;

use App\Models\NavPermissionBundle;
use Database\Seeders\NavBundleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NavBundleSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_seeder_creates_bundles_with_required_permissions(): void
    {
        (new NavBundleSeeder())->run();

        $items = NavPermissionBundle::where('route_path', '/admin/items')->with('permissions')->first();
        $this->assertNotNull($items, 'Item Master bundle should exist');

        $required = $items->permissions->filter(fn ($p) => (int) $p->pivot->is_required === 1);
        $this->assertTrue(
            $required->contains(fn ($p) => $p->feature === 'item_master_management' && $p->action === 'read'),
            'Item Master read gate should be item_master_management.read'
        );
    }

    public function test_seeder_is_idempotent(): void
    {
        (new NavBundleSeeder())->run();
        $count = NavPermissionBundle::count();
        (new NavBundleSeeder())->run();
        $this->assertEquals($count, NavPermissionBundle::count());
    }
}
