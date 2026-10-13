<?php

namespace Tests\Feature\Scoping;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\PendingProduct;

// NOTE (Part B — Purchase Requests): Investigated PurchaseRequestController.
// The only endpoint that returns OTHER users'/locations' PRs is `pending()`
// (route: GET /purchase-requests/pending/list, gated by
// permission:purchase_request_approval,approve) and the `archived()` /
// `export()` endpoints (gated by permission:purchase_request_creation,read).
// None of these filter by requester or location — they are genuine
// cross-location reads. HOWEVER, per database/seeders/PermissionSeeder.php
// (the authoritative default permission matrix), both
// `purchase_request_creation` (read/create) and `purchase_request_approval`
// (approve) are granted ONLY to ['admin', 'inventory_analyst'] by default —
// both of which are centralized roles under User::isCentralized(). The
// branch manager (role_id 3) has since been granted
// purchase_request_creation.read and purchase_request_approval.approve, and it
// now reads every location by design (User::readsAllLocations) — so the
// cross-location reads below are intended for it rather than a leak. No
// read-scoped role holds either permission out of the box. `PurchaseRequest::index()` (the one endpoint scoped roles CAN
// reach if granted `purchase_request_creation,read`) already filters
// ->where('requested_by', $request->user()->user_id) — own PRs only, no
// cross-location leak.
// CONCLUSION: no leak under the default/authoritative RBAC matrix. No
// PurchaseRequest code change made. Residual risk: an admin could use the
// DB-driven permission-override UI to grant purchase_request_approval,approve
// or purchase_request_creation,read to branch_manager, which would then
// expose system-wide PRs (header `location_id` is largely NULL under the
// current warehouse-only-procurement design, since per-warehouse targeting
// moved to `purchase_request_details.location_id`, so a naive
// ScopesToUserLocation trait on the header column would not correctly scope
// this data anyway — a real fix would need a whereHas('details', ...) scope
// on the detail-level location_id). Flagged as a fast-follow if/when admins
// start granting these permissions to scoped roles.
class PrPendingScopingTest extends TestCase
{
    use DatabaseTransactions;

    protected function loc(string $type = 'warehouse'): \App\Models\Location
    {
        return \App\Models\Location::create([
            'location_code' => 'L-' . uniqid(),
            'location_name' => 'Loc ' . uniqid(),
            'location_type' => $type,
        ]);
    }

    protected function userAt(int $roleId, ?int $locationId): \App\Models\User
    {
        return \App\Models\User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'T',
            'last_name'     => 'U',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
            'location_id'   => $locationId,
        ]);
    }

    private function pending(int $openingLocationId): PendingProduct
    {
        return PendingProduct::create([
            'barcode'              => 'BC-' . uniqid(),
            'generated_name'       => 'P-' . uniqid(),
            'opening_location_id'  => $openingLocationId,
            'status'               => 'pending',
        ]);
    }

    public function test_scoped_user_query_sees_only_their_location_pending(): void
    {
        $a = $this->loc(); $b = $this->loc();
        $this->pending($a->location_id);
        $this->pending($b->location_id);

        $wp = $this->userAt(4, $a->location_id);
        $rows = PendingProduct::forUser($wp)->get();
        $locs = $rows->pluck('opening_location_id')->unique()->all();
        $this->assertContains($a->location_id, $locs);
        $this->assertNotContains($b->location_id, $locs);
    }

    public function test_admin_query_sees_all_pending(): void
    {
        $a = $this->loc(); $b = $this->loc();
        $this->pending($a->location_id);
        $this->pending($b->location_id);

        $admin = $this->userAt(1, null);
        $rows = PendingProduct::forUser($admin)->get();
        $locs = $rows->pluck('opening_location_id')->unique()->all();
        $this->assertContains($a->location_id, $locs);
        $this->assertContains($b->location_id, $locs);
    }

    public function test_branch_manager_query_sees_every_locations_pending(): void
    {
        $a = $this->loc(); $b = $this->loc();
        $this->pending($a->location_id);
        $this->pending($b->location_id);

        $bm = $this->userAt(3, $a->location_id);
        $locs = PendingProduct::forUser($bm)->pluck('opening_location_id')->unique()->all();

        $this->assertContains($a->location_id, $locs);
        $this->assertContains($b->location_id, $locs);
    }
}
