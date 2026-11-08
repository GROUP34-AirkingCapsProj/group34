<?php

namespace Tests\Feature\Permissions;

use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\Receiving;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The branch manager is the admin's deputy: it reads every location's data and
 * still writes only at its own, and it reaches no administration feature.
 *
 * That is three claims pulling in different directions, which is exactly why
 * they are asserted together here. The per-endpoint consequences live in the
 * Scoping suites; this file pins the boundary itself, so a future edit that
 * collapses the read and write scopes back into one method fails here first.
 */
class BranchManagerScopeTest extends TestCase
{
    use DatabaseTransactions;

    private function loc(): Location
    {
        return Location::create([
            'location_code' => 'BMS-' . uniqid(),
            'location_name' => 'BM Scope ' . uniqid(),
            'location_type' => 'warehouse',
        ]);
    }

    private function userAt(int $roleId, ?int $locationId): User
    {
        return User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'T',
            'last_name'     => 'U',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
            'location_id'   => $locationId,
        ]);
    }

    // ---------------------------------------------------------------
    // The two scopes, and the fact that they differ
    // ---------------------------------------------------------------

    public function test_read_scope_covers_every_role_but_warehouse_personnel(): void
    {
        $loc = $this->loc();

        foreach ([1, 2, 3, 5] as $roleId) {
            $this->assertTrue(
                $this->userAt($roleId, $loc->location_id)->readsAllLocations(),
                "role {$roleId} must read every location"
            );
        }

        $this->assertFalse(
            $this->userAt(4, $loc->location_id)->readsAllLocations(),
            'warehouse_personnel is the one role whose reads stay at its own location'
        );
    }

    public function test_write_scope_did_not_widen_with_the_read_scope(): void
    {
        $loc = $this->loc();
        $bm  = $this->userAt(3, $loc->location_id);

        $this->assertTrue($bm->readsAllLocations(), 'the branch manager reads everywhere');
        $this->assertFalse($bm->isCentralized(), 'but it may not choose where it writes');
        $this->assertTrue($bm->hasStrictLocation(), 'so its writes stay pinned');
        $this->assertSame(
            (int) $loc->location_id,
            $bm->scopedLocationId(),
            'and the location they are pinned to is its own'
        );
    }

    /**
     * A scoped role with no location assigned must read nothing rather than
     * everything (spec D3). The branch manager is the exception that proves the
     * rule is about the READ scope: it has no location restriction to fall back
     * from, so an unassigned manager still reads — while writing nowhere, which
     * ResolvesUserLocation refuses outright.
     */
    public function test_an_unassigned_branch_manager_still_reads_but_cannot_write(): void
    {
        $bm = $this->userAt(3, null);

        $this->assertTrue($bm->readsAllLocations());
        $this->assertNull($bm->scopedLocationId(), 'nothing to pin a write to');
    }

    // ---------------------------------------------------------------
    // Writes stay at the manager's own branch
    // ---------------------------------------------------------------

    /**
     * EnsureAssignedLocationAccess overwrites location_id on any write from a
     * strict role, so a manager posting another branch's id gets its own branch
     * instead of a 403 — the request succeeds, at the right location.
     */
    public function test_a_write_naming_another_location_lands_at_the_managers_own(): void
    {
        $mine   = $this->loc();
        $theirs = $this->loc();

        $product = Product::create([
            'product_code' => 'P-' . uniqid(),
            'product_name' => 'P ' . uniqid(),
            'product_type' => 'appliance',
        ]);

        $po = PurchaseOrder::create([
            'po_number'           => 'PO-' . uniqid(),
            'location_id'         => $theirs->location_id,
            'verification_status' => 'verified',
            // Approved/Open: receiving is only permitted from a receivable status.
            'status_id'           => 8,
            'order_date'          => now()->toDateString(),
        ]);

        // The receipt must name an exact PO line, and PoReceivingGuard requires
        // that line's warehouse to be the one actually received into. The write
        // lands at $mine (see below), so the line belongs to $mine even though
        // the PO header points at $theirs.
        $line = PurchaseOrderDetail::create([
            'po_id'            => $po->po_id,
            'location_id'      => $mine->location_id,
            'product_id'       => $product->product_id,
            'quantity_ordered' => 5,
            'unit_price'       => 100,
            'subtotal'         => 500,
        ]);

        $bm = $this->userAt(3, $mine->location_id);

        $response = $this->actingAs($bm)->postJson('/api/receivings', [
            'pc_id'            => $po->po_id,
            // Deliberately the other branch. The manager can SEE that branch's
            // stock now; it still cannot receive into it.
            'location_id'      => $theirs->location_id,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now()->toDateString(),
            'details'          => [[
                'po_detail_id'    => $line->po_detail_id,
                'product_id'      => $product->product_id,
                'quantity_amount' => 1,
                'condition'       => 'Good',
                'scanned_barcode' => 'BC-' . uniqid(),
                'zone_label'      => 'Location 1',
            ]],
        ]);

        $response->assertStatus(201);

        $receiving = Receiving::where('receiving_number', $response->json('data.receiving_number'))->firstOrFail();

        $this->assertSame(
            (int) $mine->location_id,
            (int) $receiving->location_id,
            'A branch manager receiving goods must record them at its own branch, whatever the request said.'
        );
    }

    // ---------------------------------------------------------------
    // Administration stays admin-only
    // ---------------------------------------------------------------

    /**
     * The widening was about data, not authority. The administration reads stay
     * closed: the account register and the permission audit log.
     *
     * GET /roles and GET /locations are not in this list on purpose — both are
     * ungated reference lists every role reads to populate a form, and neither
     * changed here. What makes them administration is writing to them, which the
     * next test covers.
     */
    public function test_administration_reads_remain_closed_to_the_branch_manager(): void
    {
        $bm = $this->userAt(3, $this->loc()->location_id);

        foreach (['/api/users', '/api/users/archived', '/api/permission-audit-logs'] as $path) {
            $this->actingAs($bm)->getJson($path)->assertForbidden();
        }
    }

    /** Creating accounts, roles and locations is the admin's alone. */
    public function test_administration_writes_remain_closed_to_the_branch_manager(): void
    {
        $bm = $this->userAt(3, $this->loc()->location_id);

        $this->actingAs($bm)->postJson('/api/users', [])->assertForbidden();
        $this->actingAs($bm)->postJson('/api/roles', [])->assertForbidden();
        $this->actingAs($bm)->postJson('/api/locations', [])->assertForbidden();
    }

    public function test_the_admin_still_reaches_administration(): void
    {
        $admin = User::where('role_id', 1)->firstOrFail();

        $this->actingAs($admin)->getJson('/api/users')->assertOk();
    }

    // ---------------------------------------------------------------
    // What the frontend is told
    // ---------------------------------------------------------------

    public function test_the_user_payload_reports_both_scopes_separately(): void
    {
        $loc = $this->loc();
        $bm  = $this->userAt(3, $loc->location_id);

        $data = $this->actingAs($bm, 'sanctum')->getJson('/api/user')->assertOk()->json('data');

        $this->assertTrue($data['reads_all_locations'], 'screens must stop filtering to one branch');
        $this->assertTrue($data['has_strict_location'], 'write pickers must stay locked');
        $this->assertSame((int) $loc->location_id, (int) $data['enforced_location_id']);
    }

    public function test_the_payload_separates_the_scopes_for_warehouse_personnel_too(): void
    {
        $wp = $this->userAt(4, $this->loc()->location_id);

        $data = $this->actingAs($wp, 'sanctum')->getJson('/api/user')->assertOk()->json('data');

        $this->assertFalse($data['reads_all_locations']);
        $this->assertTrue($data['has_strict_location']);
    }
}
