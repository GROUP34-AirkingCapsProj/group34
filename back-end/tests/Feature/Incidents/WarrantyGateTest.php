<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentItem;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\IncidentService;
use App\Services\WarrantyStateService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * An inspector may only send a unit for a warranty claim if there is a warranty
 * to claim against.
 *
 * Offering the choice on an out-of-warranty unit wastes a warranty officer's
 * time, and it stays invisible until they open the claim and find nothing
 * there. The rule is served per item AND enforced on the endpoint: a rule the
 * client merely renders is not a rule.
 *
 * Exercised as a branch_manager, never an admin — PermissionResolver::isAdmin()
 * passes structurally and would sail straight through a permission bug.
 */
class WarrantyGateTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'admin'               => 'System Administrator',
            'inventory_analyst'   => 'Inventory Analyst',
            'branch_manager'      => 'Branch Manager',
            'warehouse_personnel' => 'Warehouse Personnel',
            'auditor'             => 'Auditor',
        ] as $name => $desc) {
            Role::firstOrCreate(['role_name' => $name], ['description' => $desc]);
        }

        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionsSeeder::class);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * NOT named seed(): that collides with Illuminate's TestCase::seed() and is
     * a hard PHP fatal which aborts the entire suite.
     */
    private function makeUser(string $roleSlug, ?int $locationId = null): User
    {
        $role = Role::where('role_name', $roleSlug)->firstOrFail();

        // Resolved by NAME — status ids differ between the dev and test
        // databases.
        $statusId = DB::table('status_lookup')
            ->where('status_category', 'user')
            ->where('status_name', 'Active')
            ->value('status_id');

        return User::forceCreate([
            'first_name'    => 'Test',
            'last_name'     => ucfirst($roleSlug),
            'email'         => $roleSlug . '_' . uniqid() . '@test.local',
            'username'      => $roleSlug . '_' . uniqid(),
            'password_hash' => Hash::make('password'),
            'role_id'       => $role->role_id,
            'status_id'     => $statusId,
            'location_id'   => $locationId,
        ]);
    }

    /**
     * One incident carrying three units: one still covered, one whose cover ran
     * out, and one that was never registered at all.
     *
     * All three on ONE report so the payload assertions compare them
     * side-by-side — the whole claim of this task being that `expired` and
     * `none` are distinguishable rather than collapsed into "not allowed".
     */
    private function makeScenario(): array
    {
        $location = Location::query()->firstOrFail();
        $product  = Product::query()->firstOrFail();
        $filer    = $this->makeUser('warehouse_personnel', (int) $location->location_id);

        Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)->delete();

        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 100, 'available_quantity' => 100, 'reorder_level' => 0,
        ]);

        $batch = InventoryBatch::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_received' => 100, 'quantity_remaining' => 100, 'received_at' => now(),
        ]);

        $covered = 'SN-COVERED-' . strtoupper(uniqid());
        $lapsed  = 'SN-LAPSED-'  . strtoupper(uniqid());
        $unknown = 'SN-UNKNOWN-' . strtoupper(uniqid());

        $this->registerWarranty($product, $covered, now()->addYear());
        $this->registerWarranty($product, $lapsed,  now()->subDays(30));
        // $unknown is deliberately left unregistered.

        $incident = app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => (int) $location->location_id,
            'product_id'  => (int) $product->product_id,
            'reason'      => 'defective',
            'quantity'    => 3,
            'narrative'   => 'warranty gate test',
            'items'       => array_map(fn (string $sn) => [
                'product_id'    => (int) $product->product_id,
                'batch_id'      => (int) $batch->batch_id,
                'quantity'      => 1,
                'unit_role'     => 'single',
                'serial_number' => $sn,
            ], [$covered, $lapsed, $unknown]),
        ], (int) $filer->getKey());

        return compact('location', 'incident', 'covered', 'lapsed', 'unknown');
    }

    private function registerWarranty(Product $product, string $serial, $endDate): void
    {
        DB::table('warranty_records')->insert([
            'product_id'             => $product->product_id,
            'serial_number'          => $serial,
            'sale_date'              => now()->subYears(2)->toDateString(),
            'warranty_start_date'    => now()->subYears(2)->toDateString(),
            'warranty_end_date'      => $endDate->toDateString(),
            // NOT NULL on this table, so it cannot be left out.
            'warranty_period_months' => 24,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    /** The item rows as the inspection screen actually receives them. */
    private function payloadItems(array $scenario): array
    {
        $inspector = $this->makeUser('branch_manager', (int) $scenario['location']->location_id);

        $response = $this->actingAs($inspector, 'sanctum')
            ->getJson("/api/incident-reports/{$scenario['incident']->incident_id}")
            ->assertOk();

        $items = [];
        foreach ($response->json('data.items') as $row) {
            $items[$row['serial_number']] = $row;
        }

        return $items;
    }

    private function itemIdFor($incident, string $serial): int
    {
        return (int) IncidentItem::where('incident_id', $incident->incident_id)
            ->where('serial_number', $serial)
            ->firstOrFail()->item_id;
    }

    // ------------------------------------------------------------------ tests

    /** A unit still under cover is the only one offered the claim. */
    public function test_an_in_warranty_unit_is_offered_a_warranty_claim(): void
    {
        $scenario = $this->makeScenario();
        $item     = $this->payloadItems($scenario)[$scenario['covered']];

        $this->assertSame(WarrantyStateService::IN_WARRANTY, $item['warranty_state']);
        $this->assertContains(IncidentItem::OUTCOME_CLAIM_WARRANTY, $item['allowed_outcomes']);
    }

    /** Its cover ran out, so there is nothing left to claim against. */
    public function test_an_expired_unit_is_not_offered_a_warranty_claim(): void
    {
        $scenario = $this->makeScenario();
        $item     = $this->payloadItems($scenario)[$scenario['lapsed']];

        $this->assertSame(WarrantyStateService::EXPIRED, $item['warranty_state']);
        $this->assertNotContains(IncidentItem::OUTCOME_CLAIM_WARRANTY, $item['allowed_outcomes']);
    }

    /** Never registered — no issuance ever sold it. */
    public function test_a_unit_with_no_record_is_not_offered_a_warranty_claim(): void
    {
        $scenario = $this->makeScenario();
        $item     = $this->payloadItems($scenario)[$scenario['unknown']];

        $this->assertSame(WarrantyStateService::NONE, $item['warranty_state']);
        $this->assertNotContains(IncidentItem::OUTCOME_CLAIM_WARRANTY, $item['allowed_outcomes']);
    }

    /**
     * The point of three states rather than two.
     *
     * Expired and none offer the same choices, so a boolean would serve the
     * buttons perfectly well and still lose the fact worth asking about: one
     * unit was sold and its cover lapsed, the other was never registered at all.
     */
    public function test_expired_and_none_are_distinguishable_in_the_payload(): void
    {
        $scenario = $this->makeScenario();
        $items    = $this->payloadItems($scenario);

        $this->assertNotSame(
            $items[$scenario['lapsed']]['warranty_state'],
            $items[$scenario['unknown']]['warranty_state'],
            'a lapsed warranty and no warranty at all are different facts'
        );
        $this->assertSame(
            $items[$scenario['lapsed']]['allowed_outcomes'],
            $items[$scenario['unknown']]['allowed_outcomes'],
            'even though they offer the inspector exactly the same choices'
        );
    }

    /**
     * The supplier gate, which is a different clock from the customer one.
     *
     * This incident is `defective`, and the scenario's batch is not traceable to
     * a receiving, so there is no supplier cover to return the unit under. The
     * CUSTOMER's warranty state is irrelevant here — a warranty return is a
     * claim against the supplier, and the supplier is the one who has to still
     * be standing behind it.
     */
    public function test_a_defective_unit_with_no_supplier_cover_cannot_be_returned(): void
    {
        $scenario = $this->makeScenario();
        $item     = $this->payloadItems($scenario)[$scenario['lapsed']];

        $this->assertNotContains(IncidentItem::OUTCOME_RETURN_TO_SUPPLIER, $item['allowed_outcomes']);
        $this->assertContains(IncidentItem::OUTCOME_WRITE_OFF, $item['allowed_outcomes'],
            'a missing unit is written off whatever any warranty says');
    }

    /**
     * …and a COMMERCIAL return is still never gated.
     *
     * A supplier who shipped the wrong item is wrong on day 400 as much as on
     * day 4, so a wrong-type return stays available with no cover at all.
     */
    public function test_a_commercial_return_is_not_gated_by_supplier_cover(): void
    {
        $scenario = $this->makeScenario();
        $scenario['incident']->update(['reason' => \App\Models\IncidentReport::REASON_WRONG_TYPE]);

        $item = $this->payloadItems($scenario)[$scenario['lapsed']];

        $this->assertContains(IncidentItem::OUTCOME_RETURN_TO_SUPPLIER, $item['allowed_outcomes']);
    }

    /**
     * THE enforcement. Hiding the button is not the rule; this is.
     *
     * A client that kept a stale payload, or simply posted the endpoint
     * directly, would otherwise raise a claim against a unit with no cover.
     */
    public function test_claiming_warranty_on_an_expired_unit_is_rejected(): void
    {
        $scenario  = $this->makeScenario();
        $inspector = $this->makeUser('branch_manager', (int) $scenario['location']->location_id);
        $itemId    = $this->itemIdFor($scenario['incident'], $scenario['lapsed']);

        $this->actingAs($inspector, 'sanctum')
            ->postJson("/api/incident-reports/{$scenario['incident']->incident_id}/items/{$itemId}/outcome",
                ['outcome' => IncidentItem::OUTCOME_CLAIM_WARRANTY, 'note' => 'compressor failed'])
            ->assertStatus(422)
            ->assertJsonPath('warranty_state', WarrantyStateService::EXPIRED);

        $this->assertNull(
            IncidentItem::find($itemId)->outcome,
            'a refused outcome must not be recorded'
        );
    }

    /** ...and the same post on a covered unit goes through, so the gate is a gate and not a wall. */
    public function test_claiming_warranty_on_a_covered_unit_is_accepted(): void
    {
        $scenario  = $this->makeScenario();
        $inspector = $this->makeUser('branch_manager', (int) $scenario['location']->location_id);
        $itemId    = $this->itemIdFor($scenario['incident'], $scenario['covered']);

        $this->actingAs($inspector, 'sanctum')
            ->postJson("/api/incident-reports/{$scenario['incident']->incident_id}/items/{$itemId}/outcome",
                ['outcome' => IncidentItem::OUTCOME_CLAIM_WARRANTY, 'note' => 'compressor failed'])
            ->assertOk();

        $this->assertSame(
            IncidentItem::OUTCOME_CLAIM_WARRANTY,
            IncidentItem::find($itemId)->outcome
        );
    }
}
