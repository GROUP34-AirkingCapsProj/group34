<?php

namespace Tests\Feature\Rbac;

use App\Models\User;
use App\Support\PermissionResolver;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RbacRouteMatrixTest extends TestCase
{
    use DatabaseTransactions;

    private array $roleId = [
        'admin' => 1, 'inventory_analyst' => 2, 'branch_manager' => 3,
        'warehouse_personnel' => 4, 'auditor' => 5,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        (new PermissionSeeder())->run();
    }

    private function userFor(int $roleId): User
    {
        return User::create([
            'username' => 'rm_' . uniqid(), 'first_name' => 'R', 'last_name' => 'M',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    /**
     * One representative endpoint per migrated feature.action.
     * [method, uri, feature, action]. Write routes use a dummy id; the permission
     * middleware runs before the controller, so 403 = denied, anything else = allowed.
     * Modules append rows here as they migrate.
     */
    private function routeMatrix(): array
    {
        return [
            // e.g. ['post', '/api/categories', 'category_management', 'create'],
        ];
    }

    public function test_every_role_matches_the_seeded_matrix(): void
    {
        $resolver = app(PermissionResolver::class);

        foreach ($this->routeMatrix() as [$method, $uri, $feature, $action]) {
            foreach ($this->roleId as $slug => $rid) {
                $user = $this->userFor($rid);
                $expectedAllow = $resolver->canUser($user->user_id, $feature, $action);
                $res = $this->actingAs($user, 'sanctum')->json(strtoupper($method), $uri, []);
                $status = $res->getStatusCode();

                if ($expectedAllow) {
                    $this->assertNotEquals(403, $status, "$slug should be ALLOWED $method $uri ($feature.$action)");
                } else {
                    $this->assertEquals(403, $status, "$slug should be DENIED $method $uri ($feature.$action)");
                }
            }
        }
    }
}
