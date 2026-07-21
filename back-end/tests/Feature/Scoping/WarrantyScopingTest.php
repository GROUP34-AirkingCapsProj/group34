<?php

namespace Tests\Feature\Scoping;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\Product;
use App\Models\WarrantyClaim;
use App\Models\WarrantyRecord;

class WarrantyScopingTest extends TestCase
{
    use DatabaseTransactions;

    protected function loc(string $type = 'warehouse'): \App\Models\Location
    {
        return \App\Models\Location::create([
            'location_code' => 'L-' . uniqid(),
            'location_name' => 'Loc ' . uniqid(),
            'location_type' => $type,
        ]);
    }

    protected function userAt(int $roleId, ?int $locationId): \App\Models\User
    {
        return \App\Models\User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'T',
            'last_name'     => 'U',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
            'location_id'   => $locationId,
        ]);
    }

    private function claim(int $locationId): WarrantyClaim
    {
        $p = Product::create(['product_code' => 'P-' . uniqid(), 'product_name' => 'P', 'product_type' => 'appliance']);
        $warranty = WarrantyRecord::create([
            'product_id'    => $p->product_id,
            'serial_number' => 'SN-' . uniqid(),
        ]);
        return WarrantyClaim::create([
            'claim_number'  => 'WC-' . uniqid(),
            'warranty_id'   => $warranty->warranty_id,
            'product_id'    => $p->product_id,
            'location_id'   => $locationId,
            'serial_number' => 'SN-' . uniqid(),
            'claim_type'    => 'repair',
            'status_id'     => 1,
        ]);
    }

    public function test_scoped_user_sees_only_their_location_claims(): void
    {
        $a = $this->loc(); $b = $this->loc();
        $this->claim($a->location_id);
        $this->claim($b->location_id);

        $bm = $this->userAt(3, $a->location_id);
        $data = $this->actingAs($bm)->getJson('/api/warranty-claims')->assertStatus(200)->json('data');
        $locs = collect($data)->pluck('location_id')->unique()->all();
        $this->assertContains($a->location_id, $locs);
        $this->assertNotContains($b->location_id, $locs);
    }

    public function test_admin_sees_all_claims(): void
    {
        $a = $this->loc(); $b = $this->loc();
        $this->claim($a->location_id);
        $this->claim($b->location_id);

        $admin = $this->userAt(1, null);
        $data = $this->actingAs($admin)->getJson('/api/warranty-claims')->assertStatus(200)->json('data');
        $locs = collect($data)->pluck('location_id')->unique()->all();
        $this->assertContains($a->location_id, $locs);
        $this->assertContains($b->location_id, $locs);
    }
}
