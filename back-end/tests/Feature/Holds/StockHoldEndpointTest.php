<?php

namespace Tests\Feature\Holds;

use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\StockHold;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Services\StockHoldService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StockHoldEndpointTest extends TestCase
{
    use DatabaseTransactions;

    private \App\Models\Location $location;

    /**
     * An inventory_analyst pinned to the fixture's location.
     *
     * Not `User::query()->firstOrFail()`: that user's role may lack
     * incident_reports.dispose (403) and their location may not be the
     * fixture's, which forUser() scoping would filter the hold out of.
     * Role id is resolved by name — role ids differ between databases.
     */
    private function analyst(): User
    {
        $roleId = (int) DB::table('roles')->where('role_name', 'inventory_analyst')->value('role_id');

        return User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'Hold',
            'last_name'     => 'Inspector',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
            'location_id'   => $this->location->location_id,
        ]);
    }

    private function heldReturn(): StockHold
    {
        $location = $this->location = \App\Models\Location::query()->firstOrFail();
        $product  = \App\Models\Product::query()->firstOrFail();

        Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)->delete();

        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'status_id' => StockHold::inventoryStatusId('In Stock'),
            'quantity_on_hand' => 100, 'available_quantity' => 100, 'reorder_level' => 0,
        ]);

        $batch = InventoryBatch::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_received' => 100, 'quantity_remaining' => 100, 'received_at' => now(),
        ]);

        $incident = IncidentReport::create([
            'incident_number' => 'INC-TEST-' . uniqid(), 'source_type' => 'standalone',
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'batch_id' => $batch->batch_id, 'reason' => 'return', 'quantity' => 10,
            'narrative' => 'test', 'reported_by' => User::query()->firstOrFail()->getKey(),
            'status_id' => IncidentReport::statusId('Open'),
        ]);

        return app(StockHoldService::class)
            ->hold($incident, StockHold::CONDITION_RETURNED, 10);
    }

    public function test_it_lists_open_holds(): void
    {
        $hold = $this->heldReturn();
        $user = $this->analyst();

        $this->actingAs($user, 'sanctum')->getJson('/api/stock-holds')
            ->assertOk()
            ->assertJsonFragment(['hold_id' => (int) $hold->hold_id]);
    }

    public function test_it_disposes_a_returned_hold_as_restocked(): void
    {
        $hold = $this->heldReturn();
        $user = $this->analyst();

        $this->actingAs($user, 'sanctum')->postJson("/api/stock-holds/{$hold->hold_id}/dispose", [
            'disposition' => StockHold::DISPOSITION_RESTOCKED,
            'note'        => 'inspected, sealed, resellable',
        ])->assertOk();

        $this->assertSame(StockHold::STATUS_RELEASED, $hold->refresh()->status);
    }

    public function test_it_rejects_restocking_a_defective_hold(): void
    {
        $hold = $this->heldReturn();
        $hold->update(['condition' => StockHold::CONDITION_DEFECTIVE]);
        $user = $this->analyst();

        $this->actingAs($user, 'sanctum')->postJson("/api/stock-holds/{$hold->hold_id}/dispose", [
            'disposition' => StockHold::DISPOSITION_RESTOCKED,
        ])->assertStatus(422);
    }

    public function test_it_rejects_a_second_disposition(): void
    {
        $hold = $this->heldReturn();
        $user = $this->analyst();

        $this->actingAs($user, 'sanctum')->postJson("/api/stock-holds/{$hold->hold_id}/dispose",
            ['disposition' => StockHold::DISPOSITION_SCRAPPED])->assertOk();

        $this->actingAs($user, 'sanctum')->postJson("/api/stock-holds/{$hold->hold_id}/dispose",
            ['disposition' => StockHold::DISPOSITION_SCRAPPED])->assertStatus(422);
    }

    /** The client must not have to know the rule — the server tells it. */
    public function test_the_list_serves_the_allowed_dispositions(): void
    {
        $this->heldReturn();
        $user = $this->analyst();

        $row = collect($this->actingAs($user, 'sanctum')->getJson('/api/stock-holds')->json('data'))->first();

        $this->assertSame(
            StockHold::ALLOWED_DISPOSITIONS[StockHold::CONDITION_RETURNED],
            $row['allowed_dispositions']
        );
    }
}
