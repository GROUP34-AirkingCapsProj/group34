<?php

namespace Tests\Feature\Lookups;

/**
 * Locks the permission enforcement on the six item-lookup endpoints and, just
 * as importantly, the deliberate read asymmetry between them:
 *
 *   - capacities / variants / issuable-unit-presets: reads AND writes are
 *     gated behind permission:lookup_management (new, admin-page-only
 *     endpoints).
 *   - units: writes are gated behind the same permission:lookup_management
 *     verbs, but reads are deliberately left open to any authenticated user.
 *   - categories / brands: writes are gated behind their own long-standing
 *     category_management / brand_management keys, NOT lookup_management
 *     directly — deliberately, so nobody's effective access changed when the
 *     unified Item Lookups page shipped. Those two matrices grant the exact
 *     same role sets as lookup_management for create/update/archive, so all
 *     six lookups behave identically to a caller regardless of which key
 *     implements them (see
 *     test_inventory_analyst_gets_the_same_outcome_on_categories_and_brands_as_on_capacities
 *     below). Reads on all three of units/categories/brands are ungated
 *     because they feed pickers used app-wide (ProductRegisterModal,
 *     BarcodeScan, OrderBuilder, InventoryManagement, BudgetManagementPage,
 *     SetManagementPage). See the comment above the unit_lookup routes in
 *     routes/api.php.
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

    /**
     * NOTE: the 403 on create here is produced by the app\Http\Middleware\
     * AuditorReadOnly group middleware, which blanket-denies every non-GET
     * request for the auditor role before permission: middleware ever runs —
     * not by the lookup_management grant (auditor isn't in create's list
     * either, but this test can't tell the two apart). It only proves
     * auditor is read-only here; it does NOT prove lookup_management,create
     * is enforced. See test_warehouse_personnel_cannot_write_a_capacity for
     * the assertion that actually exercises the permission gate.
     */
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
     * warehouse_personnel is not subject to AuditorReadOnly (that middleware
     * only touches the auditor role), so a 403 here can only come from the
     * permission: middleware itself rejecting the lookup_management,create /
     * ,update grant. This is what actually pins the capacities write gate —
     * the auditor-based tests above prove auditor is read-only, not that the
     * permission check runs.
     */
    public function test_warehouse_personnel_cannot_write_a_capacity(): void
    {
        $this->actingAsRole('warehouse_personnel');

        $this->postJson('/api/capacities', ['capacity_value' => $this->uniqueValue()])
            ->assertStatus(403);
        $this->putJson('/api/capacities/1', ['capacity_value' => $this->uniqueValue()])
            ->assertStatus(403);
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

    /**
     * Categories and brands do NOT gate their writes on lookup_management
     * directly — they keep their own long-standing category_management /
     * brand_management keys (see the comment above the unit_lookup routes
     * in routes/api.php). That is deliberate: nobody's effective access was
     * meant to change when the unified Item Lookups page shipped. But
     * PermissionSeeder::matrix() grants category_management and
     * brand_management the exact same role sets as lookup_management for
     * create/update/archive, so from a user's perspective all six lookups
     * should behave identically regardless of which key implements them.
     *
     * This test pins that user-visible equivalence rather than the
     * implementation detail: if someone edits category_management's (or
     * brand_management's) grants so the Item Lookups page starts behaving
     * inconsistently across its own tabs, this must fail even though
     * test_inventory_analyst_can_read_create_and_update_but_not_archive
     * (which only exercises capacities/lookup_management) would not catch
     * it.
     */
    public function test_inventory_analyst_gets_the_same_outcome_on_categories_and_brands_as_on_capacities(): void
    {
        $this->actingAsRole('inventory_analyst');

        $categoryId = $this->postJson('/api/categories', ['category_name' => $this->uniqueValue()])
            ->assertStatus(201)
            ->json('data.category_id');
        $this->putJson("/api/categories/{$categoryId}", ['category_name' => $this->uniqueValue()])
            ->assertStatus(200);
        $this->deleteJson("/api/categories/{$categoryId}")
            ->assertStatus(403);

        $brandId = $this->postJson('/api/brands', ['brand_name' => $this->uniqueValue()])
            ->assertStatus(201)
            ->json('data.brand_id');
        $this->putJson("/api/brands/{$brandId}", ['brand_name' => $this->uniqueValue()])
            ->assertStatus(200);
        $this->deleteJson("/api/brands/{$brandId}")
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // The read asymmetry — pinned in both directions. Units, categories and
    // brands feed pickers used throughout the app (ProductRegisterModal,
    // BarcodeScan, OrderBuilder, InventoryManagement, BudgetManagementPage,
    // SetManagementPage), so their GETs are deliberately left ungated for
    // any authenticated user. If this test starts failing with a 403,
    // someone gated these reads "for consistency" and broke those pickers —
    // do NOT "fix" it by loosening this test to expect 403; revert whatever
    // added permission:lookup_management,read (or similar) to these GET
    // routes instead.
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
    // Units specifically — auditor was previously blocked here by
    // role-based gating; confirm the current permission-based path still
    // fails clean (403) rather than raising a 500 in the retrofit.
    // ------------------------------------------------------------------

    /**
     * NOTE: like test_auditor_can_read_but_cannot_create_a_capacity, this
     * 403 is produced by AuditorReadOnly (which blanket-denies every
     * non-GET for the auditor role) as much as by lookup_management,create.
     * What this test actually pins is that neither the retrofit nor the
     * permission: middleware itself throws — a 403, not a 500.
     */
    public function test_auditor_gets_a_clean_403_writing_to_units(): void
    {
        $this->actingAsRole('auditor');

        $this->postJson('/api/units', ['unit_name' => $this->uniqueValue()])
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Unauthenticated.
    // ------------------------------------------------------------------

    public function test_unauthenticated_request_to_a_gated_lookup_returns_401(): void
    {
        $this->getJson('/api/capacities')->assertStatus(401);
    }
}
