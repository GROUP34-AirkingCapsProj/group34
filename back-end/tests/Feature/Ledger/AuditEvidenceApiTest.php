<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryLedgerEntry;
use App\Models\LedgerDriftFinding;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditEvidenceApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // PermissionSeeder defines the feature; RolePermissionsSeeder is what grants
        // it. Seeding only the first leaves it granted to nobody.
        $this->artisan('db:seed', ['--class' => 'PermissionSeeder', '--force' => true]);
        $this->artisan('db:seed', ['--class' => 'RolePermissionsSeeder', '--force' => true]);
    }

    private function userWithRole(string $roleName): User
    {
        $roleId = (int) DB::table('roles')->where('role_name', $roleName)->value('role_id');

        return User::create([
            'username' => $roleName . '_' . uniqid(), 'first_name' => 'A', 'last_name' => 'B',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'),
            'role_id' => $roleId,
        ]);
    }

    private function entry(array $overrides = []): InventoryLedgerEntry
    {
        return InventoryLedgerEntry::create(array_merge([
            'occurred_at' => '2026-09-10 09:00:00', 'posted_at' => '2026-09-10 09:00:00',
            'direction' => 'in', 'movement_type' => 'receiving', 'lens' => 'inventory',
            'product_id' => 330111, 'quantity' => 5, 'unit_cost' => 20.00,
            'extended_value' => 100.00, 'cost_basis' => 'actual', 'to_location_id' => 1,
            'source_type' => 'receivings', 'source_id' => 5010111,
            'correlation_id' => 'receivings:5010111', 'posting_key' => 'api:' . uniqid('', true),
        ], $overrides));
    }

    private function q(array $extra = []): string
    {
        return http_build_query(array_merge(['from' => '2026-09-01', 'to' => '2026-09-30'], $extra));
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/audit-evidence/summary?' . $this->q())->assertStatus(401);
    }

    public function test_the_summary_returns_totals_for_an_auditor(): void
    {
        $this->entry();

        $this->actingAs($this->userWithRole('auditor'))
            ->getJson('/api/audit-evidence/summary?' . $this->q(['product_id' => 330111]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.quantity_in', 5)
            // json_encode renders 100.0 as 100 and assertJsonPath compares
            // strictly, so compare numerically rather than by type.
            ->assertJsonPath('data.value_in', fn ($v) => (float) $v === 100.0);
    }

    public function test_movements_are_paginated(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->entry(['source_id' => 5010000 + $i, 'correlation_id' => 'receivings:' . (5010000 + $i)]);
        }

        $this->actingAs($this->userWithRole('auditor'))
            ->getJson('/api/audit-evidence/movements?' . $this->q(['product_id' => 330111, 'per_page' => 5]))
            ->assertOk()
            ->assertJsonPath('pagination.total', 6)
            ->assertJsonPath('pagination.per_page', 5)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonCount(5, 'data');
    }

    public function test_an_absurdly_small_page_size_is_clamped_rather_than_honoured(): void
    {
        $this->entry();

        // per_page is clamped to [5, 200]. A request for one row per page would
        // make an auditor page through a year of movements one at a time, and a
        // request for everything would pull the whole ledger into the browser —
        // the exact failure this page replaces.
        $this->actingAs($this->userWithRole('auditor'))
            ->getJson('/api/audit-evidence/movements?' . $this->q(['product_id' => 330111, 'per_page' => 1]))
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 5);
    }

    public function test_a_breakdown_requires_a_known_dimension(): void
    {
        $this->actingAs($this->userWithRole('auditor'))
            ->getJson('/api/audit-evidence/breakdown?' . $this->q(['dimension' => 'nonsense']))
            ->assertStatus(422);
    }

    public function test_a_period_wider_than_the_cap_is_refused_with_a_message(): void
    {
        $this->actingAs($this->userWithRole('auditor'))
            ->getJson('/api/audit-evidence/summary?' . http_build_query(['from' => '2024-01-01', 'to' => '2026-09-30']))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_an_event_returns_its_legs(): void
    {
        $this->entry();

        $this->actingAs($this->userWithRole('auditor'))
            ->getJson('/api/audit-evidence/events/receivings:5010111')
            ->assertOk()
            ->assertJsonPath('data.correlation_id', 'receivings:5010111')
            ->assertJsonCount(1, 'data.legs');
    }

    public function test_reconciliation_returns_the_four_terms(): void
    {
        $this->entry();

        $this->actingAs($this->userWithRole('auditor'))
            ->getJson('/api/audit-evidence/reconciliation?' . $this->q(['product_id' => 330111]))
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'opening_quantity', 'quantity_in', 'quantity_out', 'closing_quantity',
                'opening_value', 'value_in', 'value_out', 'closing_value',
                'stock_on_hand', 'matches_stock', 'unvalued_rows',
            ]]);
    }

    public function test_open_exceptions_are_listed_and_can_be_resolved_by_an_auditor(): void
    {
        $finding = LedgerDriftFinding::create([
            'check_type' => 'source_vs_ledger', 'source_type' => 'receivings',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'expected' => 10, 'actual' => 4, 'variance' => 6,
            'detail' => 'unposted receiving', 'detected_at' => '2026-09-30 02:30:00',
        ]);

        $auditor = $this->userWithRole('auditor');

        $this->actingAs($auditor)
            ->getJson('/api/audit-evidence/exceptions')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($auditor)
            ->postJson("/api/audit-evidence/exceptions/{$finding->finding_id}/resolve", [
                'resolution_note' => 'Backfilled and verified against the DR.',
            ])
            ->assertOk();

        $this->assertNotNull($finding->fresh()->resolved_at);
        $this->assertSame((int) $auditor->user_id, (int) $finding->fresh()->resolved_by);
    }

    public function test_resolving_an_exception_requires_a_note(): void
    {
        $finding = LedgerDriftFinding::create([
            'check_type' => 'source_vs_ledger', 'detail' => 'x', 'detected_at' => '2026-09-30 02:30:00',
        ]);

        $this->actingAs($this->userWithRole('auditor'))
            ->postJson("/api/audit-evidence/exceptions/{$finding->finding_id}/resolve", [])
            ->assertStatus(422);
    }

    public function test_an_already_resolved_finding_cannot_be_resolved_twice(): void
    {
        $finding = LedgerDriftFinding::create([
            'check_type' => 'source_vs_ledger', 'detail' => 'x', 'detected_at' => '2026-09-30 02:30:00',
            'resolved_at' => '2026-09-30 09:00:00', 'resolution_note' => 'already handled',
        ]);

        $this->actingAs($this->userWithRole('auditor'))
            ->postJson("/api/audit-evidence/exceptions/{$finding->finding_id}/resolve", [
                'resolution_note' => 'trying again',
            ])
            ->assertStatus(409);
    }
}
