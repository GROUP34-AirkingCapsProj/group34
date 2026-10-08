<?php

namespace Tests\Feature\Issuance;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\ItemSerial;
use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\Location;
use App\Models\PickReservation;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use App\Services\InventoryBatchService;
use App\Support\IssuanceStatus;

class RevertToDraftTest extends TestCase
{
    use DatabaseTransactions;

    /** Build a location + user + customer and return [$location, $user, $customer]. */
    private function scaffold(): array
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $customer = Customer::create(['customer_name' => 'Test Customer', 'customer_type' => 'individual']);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);

        return [$location, $user, $customer];
    }

    private function makeIssuance($location, $user, $customer, int $statusId): Issuance
    {
        return Issuance::create([
            'location_id' => $location->location_id, 'customer_id' => $customer->customer_id,
            'issuance_date' => now()->toDateString(), 'issuance_type' => 'individual',
            'dr_number' => 'DR-TEST-' . uniqid(), 'discount_type' => 'none', 'discount_percent' => 0,
            'subtotal' => 0, 'discount_amount' => 0, 'total_amount' => 0,
            'issued_by' => $user->user_id, 'status_id' => $statusId,
        ]);
    }

    public function test_revert_pending_with_no_picks_returns_to_draft_and_releases_reservations(): void
    {
        [$location, $user, $customer] = $this->scaffold();
        $product = Product::create([
            'product_code' => 'MAT-' . uniqid(), 'product_name' => 'Test Material',
            'product_type' => 'installation_material', 'unit_price' => 50,
        ]);
        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 10, 'available_quantity' => 10, 'reorder_level' => 0,
        ]);

        $issuance = $this->makeIssuance($location, $user, $customer, IssuanceStatus::pending());
        $requested = IssuanceDetail::create([
            'issuance_id' => $issuance->issuance_id, 'product_id' => $product->product_id,
            'quantity_requested' => 4, 'quantity_issued' => 0, 'quantity_short' => 1,
            'unit_price' => 50, 'subtotal' => 200,
        ]);
        PickReservation::create([
            'source_type' => 'issuance', 'source_id' => $issuance->issuance_id,
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'quantity' => 4, 'expires_at' => now()->addMinutes(240),
        ]);

        $this->actingAs($user)
            ->postJson("/api/issuance-scan/{$issuance->issuance_id}/revert-to-draft")
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame(IssuanceStatus::draft(), (int) $issuance->fresh()->status_id);
        $this->assertSame(0, (int) $requested->fresh()->quantity_short);
        $this->assertSame(0, PickReservation::active()
            ->forSource('issuance', $issuance->issuance_id)->count());
    }

    public function test_revert_restores_a_scanned_installation_material_pick(): void
    {
        [$location, $user, $customer] = $this->scaffold();
        $product = Product::create([
            'product_code' => 'MAT-' . uniqid(), 'product_name' => 'Test Material',
            'product_type' => 'installation_material', 'unit_price' => 50,
        ]);
        $rcv   = Receiving::create(['location_id' => $location->location_id, 'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()]);
        $batch = (new InventoryBatchService())->createBatch($product->product_id, $location->location_id, $rcv->receiving_id, 10, now());
        $batch->quantity_remaining = 7;
        $batch->save();
        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 7, 'available_quantity' => 7, 'reorder_level' => 0,
        ]);

        $issuance = $this->makeIssuance($location, $user, $customer, IssuanceStatus::pending());
        IssuanceDetail::create([
            'issuance_id' => $issuance->issuance_id, 'product_id' => $product->product_id,
            'receiving_id' => $rcv->receiving_id, 'quantity_issued' => 3, 'quantity_unit' => 'piece',
            'quantity_in_pieces' => 3, 'unit_price' => 50, 'unit_price_per_piece' => 50, 'subtotal' => 150,
            'condition_issued' => 'Good',
        ]);

        $this->actingAs($user)
            ->postJson("/api/issuance-scan/{$issuance->issuance_id}/revert-to-draft")
            ->assertOk();

        $inv = Inventory::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)->first();
        $this->assertSame(10, (int) $inv->quantity_on_hand);
        $this->assertSame(10, (int) $batch->fresh()->quantity_remaining);
        $this->assertSame(0, IssuanceDetail::where('issuance_id', $issuance->issuance_id)
            ->where('quantity_issued', '>', 0)->count());
        $this->assertSame(IssuanceStatus::draft(), (int) $issuance->fresh()->status_id);
        $this->assertSame(0.0, (float) $issuance->fresh()->total_amount);
    }

    public function test_revert_recreates_a_scanned_serialized_appliance(): void
    {
        [$location, $user, $customer] = $this->scaffold();
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Test Appliance',
            'product_type' => 'appliance', 'unit_price' => 100,
        ]);
        $rcv   = Receiving::create(['location_id' => $location->location_id, 'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()]);
        $batch = (new InventoryBatchService())->createBatch($product->product_id, $location->location_id, $rcv->receiving_id, 1, now());
        $batch->quantity_remaining = 0;
        $batch->save();
        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 0, 'available_quantity' => 0, 'reorder_level' => 0,
        ]);

        $issuance = $this->makeIssuance($location, $user, $customer, IssuanceStatus::pending());
        $sn = 'SN-' . uniqid();
        IssuanceDetail::create([
            'issuance_id' => $issuance->issuance_id, 'product_id' => $product->product_id,
            'serial_number' => $sn, 'receiving_id' => $rcv->receiving_id,
            'unit_price' => 100, 'quantity_issued' => 1, 'quantity_unit' => 'unit',
            'quantity_in_pieces' => 1, 'unit_price_per_piece' => 100, 'subtotal' => 100,
            'condition_issued' => 'Good',
        ]);

        $this->actingAs($user)
            ->postJson("/api/issuance-scan/{$issuance->issuance_id}/revert-to-draft")
            ->assertOk();

        $this->assertSame(1, (int) $batch->fresh()->quantity_remaining);
        $this->assertNotNull(ItemSerial::where('serial_number', $sn)->first());
        $this->assertSame(IssuanceStatus::draft(), (int) $issuance->fresh()->status_id);
        $this->assertSame(0.0, (float) $issuance->fresh()->total_amount);
    }

    public function test_revert_is_idempotent_on_a_draft_and_rejects_a_completed_order(): void
    {
        [$location, $user, $customer] = $this->scaffold();

        $draft = $this->makeIssuance($location, $user, $customer, IssuanceStatus::draft());
        $this->actingAs($user)
            ->postJson("/api/issuance-scan/{$draft->issuance_id}/revert-to-draft")
            ->assertOk()->assertJson(['success' => true]);
        $this->assertSame(IssuanceStatus::draft(), (int) $draft->fresh()->status_id);

        $completed = $this->makeIssuance($location, $user, $customer, IssuanceStatus::completed());
        $this->actingAs($user)
            ->postJson("/api/issuance-scan/{$completed->issuance_id}/revert-to-draft")
            ->assertStatus(422);
        $this->assertSame(IssuanceStatus::completed(), (int) $completed->fresh()->status_id);
    }
}
