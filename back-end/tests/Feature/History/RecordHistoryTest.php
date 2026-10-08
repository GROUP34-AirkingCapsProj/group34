<?php

namespace Tests\Feature\History;

use App\Models\AuditTrail;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\Receiving;
use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\Supplier;
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

    public function test_raw_verified_by_id_is_resolved_to_a_name(): void
    {
        $u = $this->makeUser(); // first + last name "Aud Itor"

        // Simulate a legacy audit row that stored verified_by as a raw id.
        AuditTrail::create([
            'user_id' => $u->user_id, 'action' => 'update',
            'table_name' => 'purchase_orders', 'record_id' => 997,
            'old_values' => ['verified_by' => null],
            'new_values' => ['verified_by' => $u->user_id],
            'created_at' => now(),
        ]);

        $milestones = app(RecordHistoryService::class)->forRecord('purchase_orders', 997);

        $change = collect($milestones[0]['changes'])->firstWhere('field', 'verified by');
        $this->assertNotNull($change);
        $this->assertSame($u->first_name . ' ' . $u->last_name, $change['to']);
    }

    public function test_po_history_endpoint_includes_receiving_milestone(): void
    {
        $u = $this->makeUser();
        // Authenticate before creating the PO so the Auditable trait logs a
        // 'create' audit row (it only records when auth()->user() is set).
        $this->actingAs($u, 'sanctum');

        $loc = Location::forceCreate([
            'location_code' => 'HWH-' . uniqid(), 'location_name' => 'Hist WH',
            'location_type' => 'warehouse', 'is_active' => 1,
        ]);
        $sup = Supplier::forceCreate(['supplier_name' => 'Hist Supplier ' . uniqid()]);

        $po = PurchaseOrder::create([
            'supplier_id' => $sup->supplier_id, 'location_id' => $loc->location_id,
            'po_number' => 'PO-HST-' . uniqid(), 'order_date' => today()->toDateString(),
            'verification_status' => 'verified', 'total_amount' => 100, 'grand_total' => 100,
            'created_by' => $u->user_id,
        ]);
        Receiving::create([
            'po_id' => $po->po_id, 'supplier_id' => $sup->supplier_id,
            'location_id' => $loc->location_id, 'receiving_number' => 'GRN-' . uniqid(),
            'receiving_date' => today()->toDateString(), 'received_by' => $u->user_id,
            'total_quantity_received' => 5,
        ]);

        $res = $this->actingAs($u, 'sanctum')->getJson("/api/purchase-orders/{$po->po_id}/history");
        $res->assertOk();
        $types = collect($res->json('data'))->pluck('type');
        $this->assertTrue($types->contains('created'));   // PO create audit row
        $this->assertTrue($types->contains('received'));  // merged receiving milestone
    }

    public function test_pr_history_endpoint_ok(): void
    {
        $u = $this->makeUser();
        // PR creation route/model is exercised elsewhere; here we assert the endpoint
        // shape for a non-existent record returns an empty, successful timeline.
        $res = $this->actingAs($u, 'sanctum')->getJson('/api/purchase-requests/123456/history');
        $res->assertOk()->assertJsonPath('success', true);
        $this->assertIsArray($res->json('data'));
    }
}
