<?php

namespace Tests\Feature\Lookups;

/**
 * Locks the permission enforcement on the six item-lookup endpoints and, just
 * as importantly, the deliberate read asymmetry between them:
 *
 *   - capacities / variants / issuable-unit-presets: reads AND writes are
 *     gated behind permission:lookup_management (new, admin-page-only
 *     endpoints).
 *   - units / categories / brands: writes are gated behind the same
 *     permission:lookup_management verbs, but reads are deliberately left
 *     open to any authenticated user, because they feed pickers used
 *     app-wide (ProductRegisterModal, BarcodeScan, OrderBuilder,
 *     InventoryManagement, BudgetManagementPage, SetManagementPage). See the
 *     comment above the unit_lookup routes in routes/api.php.
 *
 * lookup_management matrix (PermissionSeeder):
 *   read    -> admin, inventory_analyst, auditor
 *   create  -> admin, inventory_analyst
 *   update  -> admin, inventory_analyst
 *   archive -> admin
 */
class LookupRouteAccessTest extends LookupTestCase
{
    // ------------------------------------------------------------------
    // Writes — the core matrix, using capacities as the representative
    // gated lookup.
    // ------------------------------------------------------------------

    public function test_admin_can_create_update_and_archive_a_capacity(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/capacities', ['capacity_value' => $this->uniqueValue()])
            ->assertStatus(201)
            ->json('data.capacity_id');

        $this->putJson("/api/capacities/{$id}", ['capacity_value' => $this->uniqueValue()])
            ->assertStatus(200);

        $this->deleteJson("/api/capacities/{$id}")
            ->assertStatus(200);
    }

    public function test_inventory_analyst_can_read_create_and_update_but_not_archive(): void
    {
        $this->actingAsRole('inventory_analyst');

        $this->getJson('/api/capacities')->assertStatus(200);

        $id = $this->postJson('/api/capacities', ['capacity_value' => $this->uniqueValue()])
            ->assertStatus(201)
            ->json('data.capacity_id');

        $this->putJson("/api/capacities/{$id}", ['capacity_value' => $this->uniqueValue()])
            ->assertStatus(200);

        // Archive is admin-only in the matrix.
        $this->deleteJson("/api/capacities/{$id}")
            ->assertStatus(403);
    }

    public function test_auditor_can_read_but_cannot_create_a_capacity(): void
    {
        $this->actingAsRole('auditor');

        $this->getJson('/api/capacities')->assertStatus(200);

        $this->postJson('/api/capacities', ['capacity_value' => $this->uniqueValue()])
            ->assertStatus(403);
    }

    public function test_warehouse_personnel_cannot_read_any_gated_lookup(): void
    {
        $this->actingAsRole('warehouse_personnel');

        $this->getJson('/api/capacities')->assertStatus(403);
        $this->getJson('/api/variants')->assertStatus(403);
        $this->getJson('/api/issuable-unit-presets')->assertStatus(403);
    }

    /**
     * branch_manager is granted none of the four lookup_management verbs in
     * PermissionSeeder::matrix(), so it must behave identically to
     * warehouse_personnel on the gated lookups.
     */
    public function test_branch_manager_cannot_read_any_gated_lookup(): void
    {
        $this->actingAsRole('branch_manager');

        $this->getJson('/api/capacities')->assertStatus(403);
        $this->getJson('/api/variants')->assertStatus(403);
        $this->getJson('/api/issuable-unit-presets')->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // The read asymmetry — pinned in both directions. Units, categories and
    // brands feed pickers used throughout the app (ProductRegisterModal,
    // BarcodeScan, OrderBuilder, InventoryManagement, BudgetManagementPage,
    // SetManagementPage), so their GETs are deliberately left ungated for
    // any authenticated user. If this test starts failing with a 403,
    // someone gated these reads "for consistency" and broke those pickers —
    // do not fix it by adding permission:lookup_management,read to the
    // routes; fix the route instead.
    // ------------------------------------------------------------------

    public function test_warehouse_personnel_can_still_read_units_categories_and_brands(): void
    {
        $this->actingAsRole('warehouse_personnel');

        $this->getJson('/api/units')->assertStatus(200);
        $this->getJson('/api/categories')->assertStatus(200);
        $this->getJson('/api/brands')->assertStatus(200);
    }

    public function test_warehouse_personnel_cannot_write_to_units_categories_or_brands(): void
    {
        $this->actingAsRole('warehouse_personnel');

        $this->postJson('/api/units', ['unit_name' => $this->uniqueValue()])->assertStatus(403);
        $this->postJson('/api/categories', ['category_name' => $this->uniqueValue()])->assertStatus(403);
        $this->postJson('/api/brands', ['brand_name' => $this->uniqueValue()])->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Units specifically — the middleware moved from role-gating to
    // permission-gating; confirm the failure mode is a clean 403, not a 500.
    // ------------------------------------------------------------------

    public function test_auditor_gets_a_clean_403_writing_to_units(): void
    {
        $this->actingAsRole('auditor');

        $response = $this->postJson('/api/units', ['unit_name' => $this->uniqueValue()]);

        $response->assertStatus(403);
        $this->assertNotEquals(500, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Unauthenticated.
    // ------------------------------------------------------------------

    public function test_unauthenticated_request_to_a_gated_lookup_returns_401(): void
    {
        $this->getJson('/api/capacities')->assertStatus(401);
    }
}
