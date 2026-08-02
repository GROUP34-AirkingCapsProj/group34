<?php

namespace Tests\Feature\Lookups;

use App\Models\ApplianceDetail;
use App\Models\CategoryLookup;
use App\Models\Product;
use App\Models\ProductSet;
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
        return $this->actingAsRole('admin');
    }

    /**
     * Authenticates a user with an arbitrary role (by role_name), for tests
     * that need to walk the permission matrix across multiple roles rather
     * than just the admin bypass covered by actingAsAdmin().
     *
     * $attributes lets a caller override/extend the default User::create
     * payload — e.g. a location-scoped user for routes carrying the
     * location.scope (EnsureAssignedLocationAccess) middleware.
     */
    protected function actingAsRole(string $roleName, array $attributes = []): User
    {
        $roleId = Role::where('role_name', $roleName)->value('role_id');
        $this->assertNotNull($roleId, "role missing - seed the test database: {$roleName}");

        $user = User::create(array_merge([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => ucfirst($roleName),
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ], $attributes));

        $this->actingAs($user);

        return $user;
    }

    protected function category(): CategoryLookup
    {
        return CategoryLookup::create([
            'category_name' => 'Cat ' . uniqid(),
            'category_type' => 'appliance',
        ]);
    }

    /**
     * An 11-character value, safe for any lookup name column of width 11 or
     * greater (capacity_value is the narrowest at varchar(20)).
     */
    protected function uniqueValue(): string
    {
        return 'T' . substr(uniqid(), -8) . 'HP';
    }

    /**
     * Creates an appliance product carrying the given attributes on its
     * appliance_details row (e.g. ['capacity_rating' => $value]). Shared by
     * every lookup whose value cascades onto this table under a different
     * column.
     */
    protected function applianceWith(array $attributes): ApplianceDetail
    {
        $product = Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Test Appliance ' . uniqid(),
            'product_type' => 'appliance',
        ]);

        return ApplianceDetail::create(array_merge([
            'product_id'    => $product->product_id,
            'is_serialized' => true,
        ], $attributes));
    }

    /**
     * Creates a product set carrying the given attributes (e.g.
     * ['capacity_rating' => $value]).
     */
    protected function productSetWith(array $attributes): ProductSet
    {
        return ProductSet::create(array_merge([
            'is_active' => true,
        ], $attributes));
    }
}
