<?php

namespace Tests\Feature\History;

use App\Models\AuditTrail;
use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\User;
use App\Services\History\RecordHistoryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RecordHistoryTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(): User
    {
        $role = Role::firstOrCreate(['role_name' => 'admin'], ['description' => 'System Administrator']);
        $statusId = StatusLookup::query()->value('status_id')
            ?? StatusLookup::forceCreate(['status_name' => 'Active', 'status_category' => 'user'])->status_id;
        return User::forceCreate([
            'first_name' => 'Aud', 'last_name' => 'Itor',
            'email' => 'hist_' . uniqid() . '@test.local',
            'username' => 'hist_' . uniqid(),
            'password_hash' => Hash::make('password'),
            'role_id' => $role->role_id, 'status_id' => $statusId,
        ]);
    }

    public function test_folds_create_and_status_change_into_ordered_milestones(): void
    {
        $u = $this->makeUser();

        // Insert the later (Approved) event FIRST so it gets the lower audit_id,
        // then force its created_at to the LATER time. The create row is inserted
        // second (higher audit_id) but forced to the EARLIER time — so the
        // assertions only pass if the created_at sort wins over the audit_id tiebreak.
        $approved = AuditTrail::create([
            'user_id' => $u->user_id, 'action' => 'update',
            'table_name' => 'purchase_orders', 'record_id' => 999,
            'old_values' => ['status_id' => 'Pending'],
            'new_values' => ['status_id' => 'Approved'],
        ]);
        $approved->forceFill(['created_at' => now()->subDays(1)])->save();

        $created = AuditTrail::create([
            'user_id' => $u->user_id, 'action' => 'create',
            'table_name' => 'purchase_orders', 'record_id' => 999,
            'new_values' => ['po_number' => 'PO-TST-1', 'status_id' => 'Pending'],
        ]);
        $created->forceFill(['created_at' => now()->subDays(3)])->save();

        $milestones = app(RecordHistoryService::class)->forRecord('purchase_orders', 999);

        $this->assertCount(2, $milestones);
        $this->assertSame('created', $milestones[0]['type']);
        $this->assertSame('status', $milestones[1]['type']);
        $this->assertSame('Approved', $milestones[1]['label']);
        $this->assertSame($u->first_name . ' ' . $u->last_name, $milestones[1]['actor_name']);
        $this->assertSame('status', $milestones[1]['changes'][0]['field']);
        $this->assertSame('Pending', $milestones[1]['changes'][0]['from']);
        $this->assertSame('Approved', $milestones[1]['changes'][0]['to']);
    }

    public function test_verification_status_change_becomes_verified_milestone(): void
    {
        $u = $this->makeUser();
        AuditTrail::create([
            'user_id' => $u->user_id, 'action' => 'update',
            'table_name' => 'purchase_orders', 'record_id' => 998,
            'old_values' => ['verification_status' => 'unverified'],
            'new_values' => ['verification_status' => 'verified'],
            'created_at' => now(),
        ]);

        $milestones = app(RecordHistoryService::class)->forRecord('purchase_orders', 998);

        $this->assertSame('verified', $milestones[0]['type']);
        $this->assertSame('Verified', $milestones[0]['label']);
    }
}
