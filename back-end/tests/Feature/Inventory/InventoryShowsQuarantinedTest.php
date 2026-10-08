<?php

namespace Tests\Feature\Inventory;

use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The inventory list says how much of a row is under an incident.
 *
 * Filing quarantines stock WITHOUT deducting it — the goods are physically
 * present, still counted, simply unpickable. That is the right arithmetic, and
 * the picking engine honours it: FifoPickingService, the issuance pick plan and
 * scan, and transfers all exclude quarantined batches and subtract quarantined
 * pieces.
 *
 * But the inventory screen knew nothing about any of it. A row with stock under
 * investigation read exactly like a row without: same on-hand, same available,
 * same "In Stock". Somebody looking at the list to answer "can I promise this?"
 * had no way to see that part of it was spoken for, and the number they were
 * reading was the one the picker would then be refused.
 */
class InventoryShowsQuarantinedTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username'      => 'invq_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'User',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]);
    }

    /** @return array{0:User,1:int,2:int} user, locationId, productId */
    private function stockedRow(int $onHand = 20): array
    {
        $user       = $this->makeUser();
        $locationId = (int) Location::query()->firstOrFail()->getKey();
        $productId  = (int) Product::query()->firstOrFail()->getKey();

        Inventory::updateOrCreate(
            ['location_id' => $locationId, 'product_id' => $productId],
            ['quantity_on_hand' => $onHand, 'available_quantity' => $onHand, 'reorder_level' => 0],
        );

        return [$user, $locationId, $productId];
    }

    private function rowFor(User $user, int $locationId, int $productId): ?array
    {
        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/inventory?location_id={$locationId}&per_page=200")
            ->assertOk()
            ->json('data');

        foreach ($body as $row) {
            if ((int) $row['product_id'] === $productId) {
                return $row;
            }
        }

        return null;
    }

    public function test_a_row_with_nothing_under_incident_reports_zero(): void
    {
        [$user, $locationId, $productId] = $this->stockedRow();

        $row = $this->rowFor($user, $locationId, $productId);

        $this->assertNotNull($row, 'the seeded inventory row should be in the list');
        $this->assertArrayHasKey('quarantined_quantity', $row);
        $this->assertSame(0, (int) $row['quarantined_quantity']);
    }

    public function test_filing_an_incident_shows_up_on_the_inventory_row(): void
    {
        [$user, $locationId, $productId] = $this->stockedRow(20);

        app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => $locationId,
            'product_id'  => $productId,
            'quantity'    => 3,
            'reason'      => 'defective',
            'narrative'   => 'three units with cracked panels',
        ], (int) $user->getKey());

        $row = $this->rowFor($user, $locationId, $productId);

        $this->assertSame(3, (int) $row['quarantined_quantity']);
        // The stock has NOT moved — it is present and counted, just spoken for.
        // Deducting at filing is the thing this design deliberately does not do.
        $this->assertSame(20, (int) $row['quantity_on_hand']);
    }

    /**
     * The number a reader actually needs: what is left to promise. Without it
     * they must do the subtraction in their head, which is the arithmetic the
     * picking engine is already doing and disagreeing with them about.
     */
    public function test_the_row_states_what_is_left_to_promise(): void
    {
        [$user, $locationId, $productId] = $this->stockedRow(20);

        app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => $locationId,
            'product_id'  => $productId,
            'quantity'    => 3,
            'reason'      => 'defective',
            'narrative'   => 'three units with cracked panels',
        ], (int) $user->getKey());

        $row = $this->rowFor($user, $locationId, $productId);

        $this->assertSame(17, (int) $row['unquarantined_quantity']);
    }

    /**
     * Settling the report gives the stock back with no cleanup step — the whole
     * reason this is derived from item status rather than stored.
     */
    public function test_dismissing_the_report_returns_the_quantity_to_the_row(): void
    {
        [$user, $locationId, $productId] = $this->stockedRow(20);

        $incident = app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => $locationId,
            'product_id'  => $productId,
            'quantity'    => 3,
            'reason'      => 'defective',
            'narrative'   => 'three units with cracked panels',
        ], (int) $user->getKey());

        $this->assertSame(3, (int) $this->rowFor($user, $locationId, $productId)['quarantined_quantity']);

        app(IncidentService::class)->decide(
            $incident,
            'dismiss',
            ['resolution_note' => 'miscounted, the panels are fine'],
            (int) $user->getKey(),
        );

        $row = $this->rowFor($user, $locationId, $productId);
        $this->assertSame(0, (int) $row['quarantined_quantity']);
        $this->assertSame(20, (int) $row['unquarantined_quantity']);
    }
}
