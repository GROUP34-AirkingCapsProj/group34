<?php

namespace Tests\Feature\Lookups;

use App\Models\CategoryLookup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Shared fixtures for the lookup-endpoint test suite. Every lookup controller
 * built on HandlesLookupCrud needs an authenticated admin, and capacity (plus
 * any other category-scoped lookup) needs a throwaway category and a value
 * that fits its column width — pulled out here so five sibling controllers'
 * tests do not re-paste this verbatim.
 */
abstract class LookupTestCase extends TestCase
{
    use DatabaseTransactions;

    protected function actingAsAdmin(): User
    {
        $roleId = Role::where('role_name', 'admin')->value('role_id');
        $this->assertNotNull($roleId, 'admin role missing - seed the test database');

        $user = User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'Admin',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]);

        $this->actingAs($user);

        return $user;
    }

    protected function category(): CategoryLookup
    {
        return CategoryLookup::create([
            'category_name' => 'Cat ' . uniqid(),
            'category_type' => 'product',
        ]);
    }

    /** Must fit capacity_value's varchar(20). */
    protected function uniqueValue(): string
    {
        return 'T' . substr(uniqid(), -8) . 'HP';
    }
}
