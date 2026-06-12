<?php

namespace Tests\Feature\Permissions;

use App\Models\User;
use Database\Seeders\NavBundleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NavBundleApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_authenticated_user_can_list_bundles_with_permissions(): void
    {
        (new NavBundleSeeder())->run();

        $user = User::create([
            'username' => 'nb_' . uniqid(), 'first_name' => 'N', 'last_name' => 'B',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => 1,
        ]);
        $this->actingAs($user, 'sanctum');

        $res = $this->getJson('/api/nav-bundles');
        $res->assertOk();

        $items = collect($res->json('data'))->firstWhere('route_path', '/admin/items');
        $this->assertNotNull($items);
        $this->assertArrayHasKey('permissions', $items);
        $this->assertTrue(collect($items['permissions'])->contains(
            fn ($p) => $p['feature'] === 'item_master_management' && $p['action'] === 'read' && $p['is_required'] === true
        ));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/nav-bundles')->assertStatus(401);
    }
}
