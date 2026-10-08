<?php

namespace Tests\Feature\Scoping;

use App\Models\Inventory;
use App\Models\Issuance;
use App\Support\IssuanceStatus;
use Tests\Feature\Sets\SetTestCase;

/**
 * Which issuances a location-scoped user may act on.
 *
 * Creating one is already safe without a controller check:
 * EnsureAssignedLocationAccess ('location.scope') runs on every non-GET route
 * in this group and overwrites location_id with the user's own for any
 * location-strict role, so a posted location_id can never name another branch.
 *
 * What that middleware does NOT do is say which EXISTING record you may touch.
 * It rewrites request input; it does not look at the row being addressed. So
 * update() and cancel(), which take an id straight from the URL, had nothing
 * stopping a user at one branch from acting on another branch's issuance.
 */
class IssuanceLocationScopeTest extends SetTestCase
{
    private function issuanceAt(int $locationId): Issuance
    {
        return Issuance::create([
            'location_id'   => $locationId,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'internal',
            'status_id'     => IssuanceStatus::draft(),
        ]);
    }

    public function test_the_middleware_pins_a_scoped_user_to_their_own_location_on_create(): void
    {
        $mine    = $this->makeLocation('MyBranch');
        $product = $this->makeAppliance();
        $this->setStock($mine->location_id, $product->product_id, 10);

        $picker = $this->makeUserAt($mine, 'warehouse_personnel');
        $this->actingAs($picker, 'sanctum');

        $this->postJson('/api/issuances', [
            'location_id'   => $mine->location_id,
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'internal',
            'details'       => [['product_id' => $product->product_id, 'quantity_issued' => 4]],
        ])->assertCreated();

        $inventory = Inventory::where('location_id', $mine->location_id)
            ->where('product_id', $product->product_id)
            ->first();

        $this->assertSame(6, (int) $inventory->quantity_on_hand);
    }

    public function test_a_scoped_user_cannot_update_another_locations_issuance(): void
    {
        $mine   = $this->makeLocation('MyBranch');
        $theirs = $this->makeLocation('TheirBranch');

        $picker = $this->makeUserAt($mine, 'warehouse_personnel');
        $issuance = $this->issuanceAt($theirs->location_id);

        $this->actingAs($picker, 'sanctum');

        $this->putJson("/api/issuances/{$issuance->issuance_id}", [
            'purpose' => 'edited from another branch',
        ])->assertForbidden();

        $this->assertNull($issuance->fresh()->purpose,
            'A refused update must not touch the other branch\'s issuance.');
    }

    public function test_a_scoped_user_cannot_cancel_another_locations_issuance(): void
    {
        $mine   = $this->makeLocation('MyBranch');
        $theirs = $this->makeLocation('TheirBranch');

        $picker = $this->makeUserAt($mine, 'warehouse_personnel');
        $issuance = $this->issuanceAt($theirs->location_id);

        $this->actingAs($picker, 'sanctum');

        $this->postJson("/api/issuances/{$issuance->issuance_id}/cancel")->assertForbidden();

        $this->assertSame(
            IssuanceStatus::draft(),
            (int) $issuance->fresh()->status_id,
            'A refused cancel must leave the issuance as it was.'
        );
    }

    public function test_a_scoped_user_can_still_update_their_own_issuance(): void
    {
        $mine = $this->makeLocation('MyBranch');

        $picker = $this->makeUserAt($mine, 'warehouse_personnel');
        $issuance = $this->issuanceAt($mine->location_id);

        $this->actingAs($picker, 'sanctum');

        $this->putJson("/api/issuances/{$issuance->issuance_id}", [
            'purpose' => 'edited at my own branch',
        ])->assertOk();

        $this->assertSame('edited at my own branch', $issuance->fresh()->purpose);
    }

    /** Centralized roles work across locations by design. */
    public function test_a_centralized_user_may_update_any_locations_issuance(): void
    {
        $here  = $this->makeLocation('HeadOffice');
        $there = $this->makeLocation('FarBranch');

        $admin = $this->makeUserAt($here, 'admin');
        $issuance = $this->issuanceAt($there->location_id);

        $this->actingAs($admin, 'sanctum');

        $this->putJson("/api/issuances/{$issuance->issuance_id}", [
            'purpose' => 'head office edit',
        ])->assertOk();
    }
}
