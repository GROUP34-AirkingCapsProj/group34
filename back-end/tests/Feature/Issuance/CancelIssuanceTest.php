<?php

namespace Tests\Feature\Issuance;

use App\Support\IssuanceStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CancelIssuanceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cancelled_issuance_status_exists_and_resolves(): void
    {
        $id = DB::table('status_lookup')
            ->where('status_category', 'issuance')
            ->where('status_name', 'Cancelled')
            ->value('status_id');

        $this->assertNotNull($id, 'Cancelled issuance status is missing');
        $this->assertSame((int) $id, IssuanceStatus::cancelled());
    }

    private function makeLocation(): \App\Models\Location
    {
        return \App\Models\Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'WH',
            'location_type' => 'warehouse',
        ]);
    }

    private function makeUserAt(\App\Models\Location $loc, string $role = 'warehouse_personnel'): \App\Models\User
    {
        $map = ['admin' => 1, 'inventory_analyst' => 2, 'branch_manager' => 3, 'warehouse_personnel' => 4, 'auditor' => 5];
        return \App\Models\User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'T',
            'last_name'     => 'U',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $map[$role] ?? 4,
            'location_id'   => $loc->location_id,
        ]);
    }

    private function makeIssuanceAt(int $statusId, int $locationId): \App\Models\Issuance
    {
        return \App\Models\Issuance::create([
            'location_id'   => $locationId,
            'issuance_date' => now(),
            'issuance_type' => 'sale',
            'status_id'     => $statusId,
        ]);
    }

    public function test_a_draft_issue_out_can_be_cancelled(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $iss  = $this->makeIssuanceAt(IssuanceStatus::draft(), $loc->location_id);

        $res = $this->actingAs($user, 'sanctum')
                    ->postJson("/api/issuances/{$iss->issuance_id}/cancel");

        $res->assertOk();
        $this->assertSame(IssuanceStatus::cancelled(), (int) $iss->fresh()->status_id);
    }

    public function test_a_non_draft_issue_out_cannot_be_cancelled(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $iss  = $this->makeIssuanceAt(IssuanceStatus::pending(), $loc->location_id);

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/issuances/{$iss->issuance_id}/cancel")
             ->assertStatus(422);

        $this->assertSame(IssuanceStatus::pending(), (int) $iss->fresh()->status_id);
    }

    public function test_cancelling_an_already_cancelled_issue_out_is_rejected(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $iss  = $this->makeIssuanceAt(IssuanceStatus::cancelled(), $loc->location_id);

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/issuances/{$iss->issuance_id}/cancel")
             ->assertStatus(422);
    }
}
