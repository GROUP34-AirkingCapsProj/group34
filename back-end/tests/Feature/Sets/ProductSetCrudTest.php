<?php

namespace Tests\Feature\Sets;

use App\Models\ProductSet;

class ProductSetCrudTest extends SetTestCase
{
    public function test_admin_creates_a_set_with_lines(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');
        $a     = $this->makeConsumable(price: 100);
        $b     = $this->makeConsumable(price: 25);

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/product-sets', [
            'set_code'        => 'BOQ-3TR-NONINV',
            'set_name'        => 'Std Installation Materials — 1st 10ft Piping',
            'capacity_rating' => '3TR/4HP',
            'variant'         => 'non-inverter',
            'lines'           => [
                ['product_id' => $a->product_id, 'quantity' => 2, 'spec_note' => '3/8 x 1/2 thck', 'sort_order' => 1],
                ['product_id' => $b->product_id, 'quantity' => 4, 'sort_order' => 2],
            ],
        ]);

        $res->assertCreated();
        $this->assertSame(2, count($res->json('data.lines')));
        $this->assertEqualsWithDelta(300.0, (float) $res->json('data.set_price'), 0.001);
    }

    public function test_warehouse_personnel_cannot_create_a_set(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');

        $this->actingAs($user, 'sanctum')
             ->postJson('/api/product-sets', ['set_code' => 'X-' . uniqid(), 'set_name' => 'Nope', 'lines' => []])
             ->assertForbidden();
    }

    public function test_warehouse_personnel_can_list_sets(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Listable']);

        $this->actingAs($user, 'sanctum')->getJson('/api/product-sets')->assertOk();
    }

    public function test_update_replaces_the_line_list_wholesale(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');
        $a     = $this->makeConsumable();
        $c     = $this->makeConsumable();

        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Editable']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 1]);

        $res = $this->actingAs($admin, 'sanctum')->putJson("/api/product-sets/{$set->set_id}", [
            'set_name' => 'Edited',
            'lines'    => [['product_id' => $c->product_id, 'quantity' => 9, 'sort_order' => 1]],
        ]);

        $res->assertOk();
        $lines = $res->json('data.lines');
        $this->assertCount(1, $lines);
        $this->assertSame($c->product_id, (int) $lines[0]['product_id']);
        $this->assertEqualsWithDelta(9.0, (float) $lines[0]['quantity'], 0.001);
    }

    public function test_duplicate_set_code_is_rejected(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');
        ProductSet::create(['set_code' => 'DUPE-CODE', 'set_name' => 'First']);

        $this->actingAs($admin, 'sanctum')
             ->postJson('/api/product-sets', ['set_code' => 'DUPE-CODE', 'set_name' => 'Second', 'lines' => []])
             ->assertStatus(422);
    }
}
