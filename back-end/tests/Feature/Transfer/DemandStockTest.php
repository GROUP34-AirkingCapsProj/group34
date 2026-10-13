<?php

namespace Tests\Feature\Transfer;

class DemandStockTest extends TransferTestCase
{
    public function test_demand_rejects_overstock_and_accepts_available_quantity(): void
    {
        $from = $this->makeLocation('B');
        $to = $this->makeLocation('A');
        $product = $this->makeAppliance();
        $this->setStock($from->location_id, $product->product_id, 5);
        $user = $this->makeUserAt($to, 'inventory_analyst');
        $permission = \App\Models\Permission::firstOrCreate(['feature' => 'inventory_transfers', 'action' => 'create']);
        \App\Models\RolePermission::updateOrCreate(['role_id' => $user->role_id, 'permission_id' => $permission->permission_id], ['is_granted' => true]);
        $payload = ['intent' => 'demand', 'from_location_id' => $from->location_id,
            'to_location_id' => $to->location_id, 'transfer_date' => now()->toDateString(),
            'products' => [['product_id' => $product->product_id, 'quantity_requested' => 10]]];
        $this->actingAs($user, 'sanctum')->postJson('/api/transfers', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('products.0.quantity_requested');
        $this->postJson('/api/transfers/availability', ['product_ids' => [$product->product_id]])
            ->assertOk()->assertJsonPath("data.{$from->location_id}.{$product->product_id}.available", 5);
        $payload['products'][0]['quantity_requested'] = 5;
        $this->postJson('/api/transfers', $payload)->assertCreated()
            ->assertJsonPath('data.details.0.quantity_requested', 5)
            ->assertJsonPath('data.status.status_name', 'Approved');
        $this->postJson('/api/transfers', $payload)->assertUnprocessable();
    }

    public function test_duplicate_demand_lines_share_stock(): void
    {
        $from = $this->makeLocation('B');
        $to = $this->makeLocation('A');
        $product = $this->makeInstallationMaterial();
        $this->setStock($from->location_id, $product->product_id, 5);
        $this->actingAs($this->makeUserAt($to, 'admin'), 'sanctum')->postJson('/api/transfers', [
            'intent' => 'demand', 'from_location_id' => $from->location_id,
            'to_location_id' => $to->location_id, 'transfer_date' => now()->toDateString(),
            'products' => [
                ['product_id' => $product->product_id, 'quantity_requested' => 3],
                ['product_id' => $product->product_id, 'quantity_requested' => 3],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('products.1.quantity_requested');
    }
}
