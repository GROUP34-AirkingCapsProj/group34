<?php

namespace Tests\Feature\Scoping;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\Product;
use App\Models\InventoryScanEvent;

class ScanEventScopingTest extends TestCase
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

    private function event(int $locationId, ?int $toLocationId = null): void
    {
        $p = Product::create(['product_code' => 'P-' . uniqid(), 'product_name' => 'P', 'product_type' => 'installation_material']);
        InventoryScanEvent::create([
            'transaction_type' => 'receiving',
            'action'           => 'applied',
            'location_id'      => $locationId,
            'to_location_id'   => $toLocationId,
            'product_id'       => $p->product_id,
            'barcode'          => 'B-' . uniqid(),
        ]);
    }

    public function test_scoped_user_sees_only_their_location_events(): void
    {
        $a = $this->loc(); $b = $this->loc();
        $this->event($a->location_id);
        $this->event($b->location_id);

        $bm = $this->userAt(3, $a->location_id);
        $data = $this->actingAs($bm)->getJson('/api/scan-events')->assertStatus(200)->json('data');
        $locs = collect($data)->pluck('location_id')->unique()->all();
        $this->assertContains($a->location_id, $locs);
        $this->assertNotContains($b->location_id, $locs);
    }

    public function test_scoped_user_sees_transfer_where_they_are_destination(): void
    {
        $a = $this->loc(); $b = $this->loc();
        $this->event($a->location_id, $b->location_id);

        $bmB = $this->userAt(3, $b->location_id);
        $data = $this->actingAs($bmB)->getJson('/api/scan-events')->assertStatus(200)->json('data');
        $this->assertNotEmpty($data, 'Destination user should see the incoming transfer event.');
    }

    public function test_admin_sees_all_events(): void
    {
        $a = $this->loc(); $b = $this->loc();
        $this->event($a->location_id);
        $this->event($b->location_id);

        $admin = $this->userAt(1, null);
        $data = $this->actingAs($admin)->getJson('/api/scan-events')->assertStatus(200)->json('data');
        $locs = collect($data)->pluck('location_id')->unique()->all();
        $this->assertContains($a->location_id, $locs);
        $this->assertContains($b->location_id, $locs);
    }
}
