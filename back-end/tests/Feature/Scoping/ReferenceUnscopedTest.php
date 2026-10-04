<?php

namespace Tests\Feature\Scoping;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class ReferenceUnscopedTest extends TestCase
{
    use DatabaseTransactions;

    protected function loc(string $type = 'warehouse'): \App\Models\Location {
        return \App\Models\Location::create(['location_code'=>'L-'.uniqid(),'location_name'=>'Loc '.uniqid(),'location_type'=>$type]);
    }
    protected function userAt(int $roleId, ?int $locationId): \App\Models\User {
        return \App\Models\User::create(['username'=>'u_'.uniqid(),'first_name'=>'T','last_name'=>'U','email'=>uniqid().'@example.test','password_hash'=>bcrypt('secret'),'role_id'=>$roleId,'location_id'=>$locationId]);
    }

    public function test_scoped_user_sees_full_locations_list(): void
    {
        // One of each type: branches and warehouses are a location_type column on
        // this one endpoint, not separate resources, so both must come back here.
        $a = $this->loc('branch_office'); $b = $this->loc('warehouse');
        $bm = $this->userAt(3, $a->location_id);
        $data = $this->actingAs($bm)->getJson('/api/locations')->assertStatus(200)->json('data');
        $ids = collect($data)->pluck('location_id')->all();
        // A scoped user must still see locations other than their own (needed for lookups).
        $this->assertContains($a->location_id, $ids);
        $this->assertContains($b->location_id, $ids);
    }

    public function test_scoped_user_sees_all_customers(): void
    {
        $a = $this->loc();
        $bm = $this->userAt(3, $a->location_id);

        $c1 = \App\Models\Customer::create(['customer_code'=>'C-'.uniqid(),'customer_name'=>'Cust '.uniqid()]);
        $c2 = \App\Models\Customer::create(['customer_code'=>'C-'.uniqid(),'customer_name'=>'Cust '.uniqid()]);

        $data = $this->actingAs($bm)->getJson('/api/customers')->assertStatus(200)->json('data');
        $ids = collect($data)->pluck('customer_id')->all();

        // Customers are global (no location column at all) — a scoped user must see all of them.
        $this->assertContains($c1->customer_id, $ids);
        $this->assertContains($c2->customer_id, $ids);
    }

}
