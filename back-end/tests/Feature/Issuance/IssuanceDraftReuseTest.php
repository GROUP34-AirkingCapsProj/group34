<?php

namespace Tests\Feature\Issuance;

use App\Support\IssuanceStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class IssuanceDraftReuseTest extends TestCase
{
    use DatabaseTransactions;

    private function makeLocation(): \App\Models\Location
    {
        return \App\Models\Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'WH', 'location_type' => 'warehouse',
        ]);
    }
    private function makeUserAt(\App\Models\Location $loc, string $role = 'warehouse_personnel'): \App\Models\User
    {
        $map = ['admin'=>1,'inventory_analyst'=>2,'branch_manager'=>3,'warehouse_personnel'=>4,'auditor'=>5];
        return \App\Models\User::create([
            'username'=>'u_'.uniqid(),'first_name'=>'T','last_name'=>'U','email'=>uniqid().'@e.test',
            'password_hash'=>bcrypt('secret'),'role_id'=>$map[$role]??4,'location_id'=>$loc->location_id,
        ]);
    }
    private function makeCustomer(): \App\Models\Customer
    {
        return \App\Models\Customer::create(['customer_name'=>'C '.uniqid(),'customer_type'=>'individual']);
    }
    private function payload(int $customerId, int $locationId): array
    {
        return ['customer_id'=>$customerId,'issuance_type'=>'individual','location_id'=>$locationId];
    }

    public function test_second_init_returns_the_same_draft_no_duplicate(): void
    {
        $loc = $this->makeLocation(); $user = $this->makeUserAt($loc); $cust = $this->makeCustomer();

        $first  = $this->actingAs($user,'sanctum')->postJson('/api/issuance-scan/init', $this->payload($cust->customer_id,$loc->location_id));
        $first->assertSuccessful();
        $id1 = $first->json('issuance_id');

        $second = $this->actingAs($user,'sanctum')->postJson('/api/issuance-scan/init', $this->payload($cust->customer_id,$loc->location_id));
        $second->assertSuccessful();
        $id2 = $second->json('issuance_id');

        $this->assertSame((int)$id1, (int)$id2, 'init created a duplicate draft');
        $this->assertSame(1, \App\Models\Issuance::where('issued_by',$user->user_id)->where('status_id',IssuanceStatus::draft())->count());
    }

    public function test_after_the_draft_leaves_draft_a_new_init_creates_a_fresh_one(): void
    {
        $loc = $this->makeLocation(); $user = $this->makeUserAt($loc); $cust = $this->makeCustomer();

        $first = $this->actingAs($user,'sanctum')->postJson('/api/issuance-scan/init', $this->payload($cust->customer_id,$loc->location_id));
        $id1 = (int) $first->json('issuance_id');
        \App\Models\Issuance::where('issuance_id',$id1)->update(['status_id'=>IssuanceStatus::completed()]);

        $second = $this->actingAs($user,'sanctum')->postJson('/api/issuance-scan/init', $this->payload($cust->customer_id,$loc->location_id));
        $id2 = (int) $second->json('issuance_id');
        $this->assertNotSame($id1, $id2);
    }

    public function test_active_draft_returns_the_open_draft_then_null(): void
    {
        $loc = $this->makeLocation(); $user = $this->makeUserAt($loc); $cust = $this->makeCustomer();

        $this->actingAs($user,'sanctum')->getJson('/api/issuance-scan/active-draft')
             ->assertOk()->assertJson(['issuance_id'=>null]);

        $init = $this->actingAs($user,'sanctum')->postJson('/api/issuance-scan/init', $this->payload($cust->customer_id,$loc->location_id));
        $id = (int) $init->json('issuance_id');

        $this->actingAs($user,'sanctum')->getJson('/api/issuance-scan/active-draft')
             ->assertOk()->assertJson(['issuance_id'=>$id]);

        \App\Models\Issuance::where('issuance_id',$id)->update(['status_id'=>IssuanceStatus::completed()]);
        $this->actingAs($user,'sanctum')->getJson('/api/issuance-scan/active-draft')
             ->assertOk()->assertJson(['issuance_id'=>null]);
    }
}
