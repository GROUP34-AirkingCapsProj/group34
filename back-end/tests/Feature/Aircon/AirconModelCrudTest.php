<?php

namespace Tests\Feature\Aircon;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The aircon model endpoints.
 *
 * The piece-count rule itself is covered against the service in
 * AirconModelComposerTest; what is tested here is that the HTTP layer reaches
 * it, that the permission gate holds, and that a listing does not go quadratic
 * on the unit roles.
 */
class AirconModelCrudTest extends AirconTestCase
{
    /** @return array<string,mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'model_name'      => 'Model ' . uniqid(),
            'brand_id'        => $this->brand()->brand_id,
            'ac_type_id'      => $this->splitType()->ac_type_id,
            'capacity_rating' => '2HP',
            'variant'         => 'Inverter',
            'is_two_piece'    => true,
        ], $overrides);
    }

    /** A user holding the given role, acting. */
    private function actingAsRole(string $roleName): User
    {
        $roleId = Role::where('role_name', $roleName)->value('role_id');
        $this->assertNotNull($roleId, "role missing - seed the test database: {$roleName}");

        $user = User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => ucfirst($roleName),
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]);

        $this->actingAs($user);

        return $user;
    }

    // ---------------------------------------------------------------- create

    public function test_creating_a_model_returns_it_with_its_lines_and_total(): void
    {
        $this->actingAsAdmin();

        $indoor  = $this->unitProduct('indoor', 12000.00);
        $outdoor = $this->unitProduct('outdoor', 18000.00);

        $response = $this->postJson('/api/aircon-models', $this->payload([
            'lines' => [
                ['product_id' => $indoor->product_id, 'quantity' => 2],
                ['product_id' => $outdoor->product_id, 'quantity' => 1],
            ],
        ]));

        $response->assertStatus(201);

        $data = $response->json('data');

        $this->assertCount(2, $data['units']);
        // 2 x 12000 + 1 x 18000
        $this->assertSame(42000.0, (float) $data['total_price']);
        $this->assertSame(['indoor', 'outdoor'], array_column($data['units'], 'unit_role'));
        $this->assertSame($indoor->product_name, $data['units'][0]['product_name']);
        $this->assertSame($indoor->product_code, $data['units'][0]['product_code']);
        $this->assertFalse($data['units'][0]['is_archived']);
        $this->assertStringContainsString('2HP', $data['label']);
        $this->assertSame('Split Type', $data['ac_type_name']);
    }

    public function test_a_two_piece_model_missing_a_side_is_rejected(): void
    {
        $this->actingAsAdmin();

        $indoor = $this->unitProduct('indoor');

        $response = $this->postJson('/api/aircon-models', $this->payload([
            'lines' => [['product_id' => $indoor->product_id, 'quantity' => 1]],
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('lines');
        $this->assertStringContainsString('outdoor', $response->json('errors.lines.0'));
    }

    /**
     * The composer rejects duplicates too, but the request rule is what keeps
     * this a clean 422 rather than a 500 out of the unique index.
     *
     * Asserted against validation's key specifically, not "any key starting
     * lines": `distinct` was a mandated constraint, and a looser assertion
     * stays green if someone deletes the rule and lets the composer catch it
     * instead. The composer-layer case is AirconModelComposerTest's to own.
     */
    public function test_a_duplicate_product_id_in_the_lines_is_rejected(): void
    {
        $this->actingAsAdmin();

        $indoor = $this->unitProduct('indoor');

        $response = $this->postJson('/api/aircon-models', $this->payload([
            'lines' => [
                ['product_id' => $indoor->product_id, 'quantity' => 1],
                ['product_id' => $indoor->product_id, 'quantity' => 1],
            ],
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('lines.0.product_id');

        // And the message is the composer's advice, not Laravel's default
        // "field has a duplicate value" — the builder UI shows this verbatim.
        $this->assertStringContainsString(
            'raise its quantity',
            $response->json('errors')['lines.0.product_id'][0]
        );
    }

    // ----------------------------------------------------------------- index

    public function test_listing_returns_active_models_and_archiving_removes_one(): void
    {
        $this->actingAsAdmin();

        $id = $this->createModel();

        $this->getJson('/api/aircon-models')
             ->assertStatus(200)
             ->assertJsonPath('success', true);

        $this->assertContains($id, $this->listedIds());

        $this->deleteJson("/api/aircon-models/{$id}")->assertStatus(200);

        $this->assertNotContains($id, $this->listedIds());

        // And an archived model is unreachable through the bound routes.
        $this->getJson("/api/aircon-models/{$id}")->assertStatus(404);

        $this->postJson("/api/aircon-models/{$id}/restore")->assertStatus(200);
        $this->assertContains($id, $this->listedIds());
    }

    /**
     * The archived view is a distinct endpoint, not a flag on index(): the
     * SoftDeletes global scope hides trashed rows whatever `active_only` says,
     * so without this route the archived models — and the ids restore() needs —
     * are unreachable through the API.
     *
     * Also pins the route ordering. /aircon-models/archived must be registered
     * before /aircon-models/{airconModel}, or the implicit binding takes
     * "archived" as a model key and 404s.
     */
    public function test_archived_models_are_listed_by_their_own_endpoint(): void
    {
        $this->actingAsAdmin();

        $id = $this->createModel();

        // Live: in index, absent from archived.
        $this->assertContains($id, $this->listedIds());
        $this->assertNotContains($id, $this->archivedIds());

        $this->deleteJson("/api/aircon-models/{$id}")->assertStatus(200);

        // Archived: the reverse, and the row still carries its lines and total
        // so the archived view renders the same card the active one does.
        $this->assertNotContains($id, $this->listedIds());

        $archived = collect($this->getJson('/api/aircon-models/archived')->json('data'))
            ->firstWhere('aircon_model_id', $id);

        $this->assertNotNull($archived, '/aircon-models/archived did not return the archived model');
        $this->assertCount(2, $archived['units']);
        $this->assertNotNull($archived['deleted_at']);
        $this->assertSame(20000.0, (float) $archived['total_price']);

        $this->postJson("/api/aircon-models/{$id}/restore")->assertStatus(200);

        $this->assertContains($id, $this->listedIds());
        $this->assertNotContains($id, $this->archivedIds());
    }

    public function test_search_matches_on_model_name(): void
    {
        $this->actingAsAdmin();

        $needle = 'Zephyr' . substr(uniqid(), -6);

        $wanted = $this->createModel(['model_name' => $needle . ' Split']);
        $other  = $this->createModel(['model_name' => 'Unrelated ' . uniqid()]);

        $ids = $this->listedIds(['search' => $needle]);

        $this->assertContains($wanted, $ids);
        $this->assertNotContains($other, $ids);
    }

    // ------------------------------------------------------------ permission

    public function test_warehouse_personnel_can_read_but_not_create(): void
    {
        $this->actingAsAdmin();
        $id = $this->createModel();

        $indoor  = $this->unitProduct('indoor');
        $outdoor = $this->unitProduct('outdoor');

        $this->actingAsRole('warehouse_personnel');

        $this->getJson('/api/aircon-models')->assertStatus(200);
        $this->getJson("/api/aircon-models/{$id}")->assertStatus(200);

        $this->postJson('/api/aircon-models', $this->payload([
            'lines' => [
                ['product_id' => $indoor->product_id],
                ['product_id' => $outdoor->product_id],
            ],
        ]))->assertStatus(403);
    }

    // ------------------------------------------------------------- N+1 guard

    /**
     * unitRole() reads product->applianceDetail, and both relations are lazy.
     * Eager-loading only `units.product` still costs one appliance_details
     * query per unit, so this asserts the roles arrive in one batched query
     * however many models and units the listing carries.
     */
    public function test_listing_batches_the_unit_roles(): void
    {
        $this->actingAsAdmin();

        for ($i = 0; $i < 4; $i++) {
            $this->createModel();
        }

        DB::enableQueryLog();
        $this->getJson('/api/aircon-models')->assertStatus(200);
        $detailQueries = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'appliance_details'))
            ->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            1,
            $detailQueries,
            'expected appliance_details to be fetched in a single batched query - '
            . 'the eager-load must reach units.product.applianceDetail, not stop at units.product (N+1)'
        );
    }

    // ----------------------------------------------------------------- helpers

    /** Creates a two-piece model through the endpoint and returns its id. */
    private function createModel(array $overrides = []): int
    {
        $indoor  = $this->unitProduct('indoor');
        $outdoor = $this->unitProduct('outdoor');

        $response = $this->postJson('/api/aircon-models', $this->payload($overrides + [
            'lines' => [
                ['product_id' => $indoor->product_id, 'quantity' => 1],
                ['product_id' => $outdoor->product_id, 'quantity' => 1],
            ],
        ]));

        $response->assertStatus(201);

        return (int) $response->json('data.aircon_model_id');
    }

    /** @return list<int> */
    private function listedIds(array $query = []): array
    {
        $response = $this->getJson('/api/aircon-models?' . http_build_query($query));
        $response->assertStatus(200);

        return array_map('intval', array_column($response->json('data'), 'aircon_model_id'));
    }

    /** @return list<int> */
    private function archivedIds(array $query = []): array
    {
        $response = $this->getJson('/api/aircon-models/archived?' . http_build_query($query));
        $response->assertStatus(200);

        return array_map('intval', array_column($response->json('data'), 'aircon_model_id'));
    }
}
