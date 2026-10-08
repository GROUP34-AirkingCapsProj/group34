<?php

namespace Tests\Feature\Aircon;

use App\Models\AcTypeLookup;
use App\Models\ApplianceDetail;
use App\Models\BrandLookup;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Shared fixtures for the aircon model suite.
 *
 * Mirrors Tests\Feature\Lookups\LookupTestCase rather than extending it: the
 * two suites need different product fixtures (that one builds appliances to
 * exercise lookup cascades, this one builds them to carry a unit role), and
 * coupling them would make either free to break the other.
 */
abstract class AirconTestCase extends TestCase
{
    use DatabaseTransactions;

    protected function actingAsAdmin(): User
    {
        $roleId = Role::where('role_name', 'admin')->value('role_id');
        $this->assertNotNull($roleId, 'role missing - seed the test database: admin');

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

    protected function brand(): BrandLookup
    {
        return BrandLookup::create(['brand_name' => 'B' . substr(uniqid(), -8)]);
    }

    protected function splitType(): AcTypeLookup
    {
        return AcTypeLookup::where('ac_type_name', 'Split Type')->firstOrFail();
    }

    protected function windowType(): AcTypeLookup
    {
        return AcTypeLookup::where('ac_type_name', 'Window Type')->firstOrFail();
    }

    /**
     * An appliance product carrying the given unit role, at the given price.
     *
     * $role is 'indoor', 'outdoor' or 'complete' — appliance_details.unit_role
     * is a plain varchar, so this passes the string straight through.
     */
    protected function unitProduct(string $role, float $price = 10000.00): Product
    {
        $product = Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => ucfirst($role) . ' Unit ' . uniqid(),
            'product_type' => 'appliance',
            'unit_price'   => $price,
        ]);

        ApplianceDetail::create([
            'product_id'    => $product->product_id,
            'unit_role'     => $role,
            'ac_type_id'    => $this->splitType()->ac_type_id,
            'is_serialized' => true,
        ]);

        return $product->fresh();
    }
}
