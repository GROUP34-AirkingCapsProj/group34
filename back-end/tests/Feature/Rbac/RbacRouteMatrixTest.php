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
            ['post', '/api/categories', 'category_management', 'create'],
            ['delete', '/api/categories/1', 'category_management', 'archive'],
            ['post', '/api/brands', 'brand_management', 'create'],
            ['delete', '/api/brands/1', 'brand_management', 'archive'],
            ['post', '/api/units', 'uom_management', 'create'],
            ['delete', '/api/units/1', 'uom_management', 'archive'],
            ['post', '/api/products', 'item_master_management', 'create'],
            ['delete', '/api/products/1', 'item_master_management', 'archive'],
            ['post', '/api/pending-products', 'item_master_management', 'create'],
            ['get', '/api/pending-products', 'item_master_management', 'read'],
            ['post', '/api/pending-products/1/approve', 'item_master_management', 'approve'],
            ['post', '/api/serials', 'barcode_assignment', 'create'],
            ['delete', '/api/serials/1', 'barcode_assignment', 'archive'],
            ['post', '/api/suppliers', 'supplier_management', 'create'],
            ['delete', '/api/suppliers/1', 'supplier_management', 'archive'],
            ['post', '/api/suppliers/1/products', 'item_supplier_mapping', 'create'],
            ['post', '/api/purchase-orders', 'purchase_order_creation', 'create'],
            ['put', '/api/purchase-orders/1', 'purchase_order_creation', 'update'],
            ['delete', '/api/purchase-orders/1', 'purchase_order_creation', 'archive'],
            ['get', '/api/purchase-orders/export', 'purchase_order_creation', 'read'],
            ['post', '/api/receivings', 'delivery_receiving', 'create'],
            ['post', '/api/sales', 'pos_sales_recording', 'create'],
            ['put', '/api/sales/1', 'pos_sales_recording', 'update'],
            ['post', '/api/delivery-receipts', 'delivery_receipt_generation', 'create'],
            ['delete', '/api/delivery-receipts/1', 'delivery_receipt_generation', 'archive'],
            ['post', '/api/issuances', 'inventory_issuance', 'create'],
            ['delete', '/api/issuances/1', 'inventory_issuance', 'archive'],
            ['post', '/api/issuance-scan/init', 'inventory_issuance', 'create'],
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
