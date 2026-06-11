<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Demo end-to-end slice: the barcode-scan routes are gated by
 * permission:barcode_inventory_processing,create instead of a hardcoded role list.
 * Granting that permission (here via a user override) lets a role through with no
 * code change.
 */
class ScanSliceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        (new PermissionSeeder())->run();
    }

    private function user(int $roleId): User
    {
        return User::create([
            'username' => 's_' . uniqid(), 'first_name' => 'S', 'last_name' => 'L',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    private function scanPermId(): int
    {
        return (int) Permission::where('feature', 'barcode_inventory_processing')
            ->where('action', 'create')->value('permission_id');
    }

    private function grant(User $u): void
    {
        UserPermissionOverride::create([
            'user_id' => $u->user_id,
            'permission_id' => $this->scanPermId(),
            'is_granted' => true,
            'granted_by' => $u->user_id,
            'granted_at' => now(),
        ]);
    }

    public function test_inventory_analyst_is_blocked_by_default(): void
    {
        $ia = $this->user(2); // inventory_analyst — not granted by default
        $this->actingAs($ia, 'sanctum')
            ->postJson('/api/inventory/scan-barcode', [])
            ->assertStatus(403);
    }

    public function test_inventory_analyst_passes_the_gate_after_grant(): void
    {
        $ia = $this->user(2);
        $this->grant($ia);

        // After the grant the request clears the permission middleware. It may still
        // fail validation downstream, but it must NOT be a 403 from the gate.
        $res = $this->actingAs($ia, 'sanctum')->postJson('/api/inventory/scan-barcode', []);
        $this->assertNotEquals(403, $res->getStatusCode(), 'IA should pass the scan gate after being granted.');
    }

    public function test_admin_passes_the_gate_by_default(): void
    {
        $admin = $this->user(1);
        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/inventory/scan-barcode', []);
        $this->assertNotEquals(403, $res->getStatusCode());
    }

    public function test_me_permissions_reflects_the_grant(): void
    {
        $ia = $this->user(2);

        $before = $this->actingAs($ia, 'sanctum')->getJson('/api/me/permissions')
            ->assertOk()->json('data');
        $this->assertArrayHasKey('barcode_inventory_processing.create', $before);
        $this->assertFalse($before['barcode_inventory_processing.create']);

        $this->grant($ia);

        $after = $this->actingAs($ia, 'sanctum')->getJson('/api/me/permissions')
            ->assertOk()->json('data');
        $this->assertTrue($after['barcode_inventory_processing.create']);
    }
}
