<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentEvidence;
use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\IncidentService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The inspection endpoints, exercised as the roles that actually use them.
 *
 * Never as an `admin`: PermissionResolver::isAdmin() passes structurally, so an
 * admin would sail through a permission bug instead of exposing it. Both the
 * inspector and the approver here are branch_managers, because that role now
 * holds inspect and resolve together - the inventory analyst was pulled out of
 * both when grants were realigned with each role's responsibility.
 *
 * The client's two signature lines are therefore enforced per USER and not per
 * role: IncidentInspectionController refuses a confirmation from the same
 * person who submitted the inspection, which
 * test_the_inspector_cannot_confirm_their_own_report covers. If that control
 * should be a role boundary again, resolve has to leave branch_manager.
 */
class IncidentInspectionEndpointTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'admin'               => 'System Administrator',
            'inventory_analyst'   => 'Inventory Analyst',
            'branch_manager'      => 'Branch Manager',
            'warehouse_personnel' => 'Warehouse Personnel',
            'auditor'             => 'Auditor',
        ] as $name => $desc) {
            Role::firstOrCreate(['role_name' => $name], ['description' => $desc]);
        }

        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionsSeeder::class);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * NOT named seed(): that collides with Illuminate's TestCase::seed() and is
     * a hard PHP fatal which aborts the entire suite.
     */
    private function makeUser(string $roleSlug, ?int $locationId = null): User
    {
        $role = Role::where('role_name', $roleSlug)->firstOrFail();

        // Resolved by NAME — status ids differ between the dev and test
        // databases, and hasAnotherResolver() only counts ACTIVE users, so a
        // user stamped with an arbitrary status would silently not count.
        $statusId = DB::table('status_lookup')
            ->where('status_category', 'user')
            ->where('status_name', 'Active')
            ->value('status_id');

        return User::forceCreate([
            'first_name'    => 'Test',
            'last_name'     => ucfirst($roleSlug),
            'email'         => $roleSlug . '_' . uniqid() . '@test.local',
            'username'      => $roleSlug . '_' . uniqid(),
            'password_hash' => Hash::make('password'),
            'role_id'       => $role->role_id,
            'status_id'     => $statusId,
            'location_id'   => $locationId,
        ]);
    }

    /** A location with stock, and an incident filed against it. */
    private function makeScenario(string $reason = 'defective', int $itemCount = 1, int $onHand = 100): array
    {
        $location = Location::query()->firstOrFail();
        $product  = Product::query()->firstOrFail();
        $filer    = $this->makeUser('warehouse_personnel', (int) $location->location_id);

        Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)->delete();

        $inventory = Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => $onHand, 'available_quantity' => $onHand, 'reorder_level' => 0,
        ]);

        $batch = InventoryBatch::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_received' => $onHand, 'quantity_remaining' => $onHand, 'received_at' => now(),
        ]);

        $data = [
            'source_type' => 'standalone',
            'location_id' => (int) $location->location_id,
            'product_id'  => (int) $product->product_id,
            'reason'      => $reason,
            'quantity'    => 10,
            'narrative'   => 'endpoint test',
        ];

        if (! in_array($reason, IncidentService::INSPECTION_EXEMPT_REASONS, true)) {
            $data['items'] = [];
            for ($n = 0; $n < $itemCount; $n++) {
                $data['items'][] = [
                    'product_id' => (int) $product->product_id,
                    'batch_id'   => (int) $batch->batch_id,
                    'quantity'   => 10,
                    'unit_role'  => $itemCount > 1 ? ($n === 0 ? 'indoor' : 'outdoor') : 'single',
                ];
            }
        }

        $incident = app(IncidentService::class)->file($data, (int) $filer->getKey());

        return compact('location', 'product', 'inventory', 'batch', 'incident');
    }

    private function items(IncidentReport $incident)
    {
        return IncidentItem::where('incident_id', $incident->incident_id)->orderBy('item_id')->get();
    }

    /**
     * A second branch_manager at the scenario's location: holds resolve, and is
     * a different user from the inspector, which is what lets a confirmation
     * through. Scoped to the location because branch_manager is location-strict.
     */
    private function makeApprover(): User
    {
        return $this->makeUser('branch_manager', (int) Location::query()->firstOrFail()->location_id);
    }

    // ------------------------------------------------------------------ tests

    public function test_repair_completion_is_authorized_validated_and_cannot_be_repeated(): void
    {
        ['incident' => $incident, 'location' => $location] = $this->makeScenario();
        $item = $this->items($incident)->first();
        $svc = app(\App\Services\IncidentItemService::class);
        $svc->setOutcome($item, IncidentItem::OUTCOME_REPAIR, 'Repair required');
        $approver = $this->makeApprover();
        $svc->applyOutcomes($incident, (int) $approver->user_id);
        $url = "/api/incident-reports/{$incident->incident_id}/items/{$item->item_id}/complete-repair";
        $this->actingAs($this->makeUser('auditor'), 'sanctum')->postJson($url, ['note' => 'Done'])->assertForbidden();
        $this->actingAs($approver, 'sanctum')->postJson($url, [])->assertUnprocessable();
        $this->postJson($url, ['note' => 'Repaired and tested'])->assertOk()->assertJsonPath('data.status', 'available');
        $this->postJson($url, ['note' => 'Repeat'])->assertUnprocessable();
    }

    /** Inspection is not optional: the whole point of the reordering. */
    public function test_an_admin_cannot_confirm_a_report_that_is_still_open(): void
    {
        ['incident' => $incident] = $this->makeScenario('defective');
        $approver = $this->makeApprover();

        $this->actingAs($approver, 'sanctum')
            ->postJson("/api/incident-reports/{$incident->incident_id}/decide", ['action' => 'confirm'])
            ->assertStatus(422);

        $this->assertSame(
            IncidentReport::statusId('Open'),
            (int) $incident->refresh()->status_id,
            'a refused confirmation must leave the report exactly where it was'
        );
    }

    /**
     * Demanding five photos and two signatures to record that a box is on the
     * wrong shelf is how a process teaches people not to report things.
     */
    public function test_a_zone_mismatch_is_confirmable_straight_from_open(): void
    {
        ['incident' => $incident] = $this->makeScenario('zone_mismatch');
        $approver = $this->makeApprover();

        $this->actingAs($approver, 'sanctum')
            ->postJson("/api/incident-reports/{$incident->incident_id}/decide", ['action' => 'confirm'])
            ->assertOk();

        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $incident->refresh()->status_id);
        $this->assertSame(0, IncidentItem::where('incident_id', $incident->incident_id)->count());
    }

    /** One report, two units, two endings — and stock moves only now. */
    public function test_confirming_applies_every_item_outcome(): void
    {
        ['incident' => $incident, 'inventory' => $inventory, 'location' => $location]
            = $this->makeScenario('defective', itemCount: 2);

        $inspector = $this->makeUser('branch_manager', (int) $location->location_id);
        $approver  = $this->makeApprover();
        $items     = $this->items($incident);

        $this->actingAs($inspector, 'sanctum')
            ->postJson("/api/incident-reports/{$incident->incident_id}/items/{$items[0]->item_id}/outcome",
                ['outcome' => IncidentItem::OUTCOME_SCRAP, 'note' => 'panel cracked'])
            ->assertOk();

        $this->actingAs($inspector, 'sanctum')
            ->postJson("/api/incident-reports/{$incident->incident_id}/items/{$items[1]->item_id}/outcome",
                ['outcome' => IncidentItem::OUTCOME_RESTOCK, 'note' => 'undamaged'])
            ->assertOk();

        $this->actingAs($inspector, 'sanctum')
            ->postJson("/api/incident-reports/{$incident->incident_id}/inspection",
                ['inspection_findings' => 'indoor unit is scrap, outdoor is fine'])
            ->assertOk();

        $this->assertSame(
            IncidentReport::statusId('Awaiting Approval'),
            (int) $incident->refresh()->status_id
        );
        $this->assertSame(100, (int) $inventory->refresh()->quantity_on_hand,
            'submitting an inspection must not move stock');

        $this->actingAs($approver, 'sanctum')
            ->postJson("/api/incident-reports/{$incident->incident_id}/decide", ['action' => 'confirm'])
            ->assertOk();

        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $incident->refresh()->status_id);
        $this->assertSame(IncidentItem::STATUS_SCRAPPED, $items[0]->refresh()->status);
        $this->assertSame(IncidentItem::STATUS_AVAILABLE, $items[1]->refresh()->status);
        // Only the scrapped ten left the building; the restocked ten were never
        // deducted in the first place.
        $this->assertSame(90, (int) $inventory->refresh()->quantity_on_hand);
    }

    /** Rejection goes back to the inspector, not back to filing. */
    public function test_rejecting_returns_the_report_to_awaiting_inspection(): void
    {
        ['incident' => $incident, 'location' => $location] = $this->makeScenario('defective');

        $inspector = $this->makeUser('branch_manager', (int) $location->location_id);
        $approver  = $this->makeApprover();
        $item      = $this->items($incident)->first();

        $this->actingAs($inspector, 'sanctum')
            ->postJson("/api/incident-reports/{$incident->incident_id}/items/{$item->item_id}/outcome",
                ['outcome' => IncidentItem::OUTCOME_WRITE_OFF, 'note' => 'gone'])
            ->assertOk();

        $this->actingAs($inspector, 'sanctum')
            ->postJson("/api/incident-reports/{$incident->incident_id}/inspection", [])
            ->assertOk();

        $this->actingAs($approver, 'sanctum')
            ->postJson("/api/incident-reports/{$incident->incident_id}/decide", [
                'action'         => 'reject',
                'rejection_note' => 'the sticker photo is unreadable',
            ])
            ->assertOk();

        $incident->refresh();

        $this->assertSame(IncidentReport::statusId('Awaiting Inspection'), (int) $incident->status_id);
        $this->assertSame('the sticker photo is unreadable', $incident->rejection_note);
        // The outcome survives the rejection: the inspector edits what the note
        // asked about rather than re-entering everything.
        $this->assertSame(IncidentItem::OUTCOME_WRITE_OFF, $item->refresh()->outcome);
        $this->assertSame(IncidentItem::STATUS_QUARANTINED, $item->status);
    }

    /** The two signature lines exist for exactly this reason. */
    public function test_the_inspector_cannot_confirm_their_own_report(): void
    {
        ['incident' => $incident] = $this->makeScenario('defective');

        // A branch_manager holds BOTH inspect and resolve, so this user could
        // otherwise inspect and approve alone.
        $inspector = $this->makeApprover();
        // …and a second holder of `resolve` exists, which is what arms the rule.
        // With nobody else able to confirm, a single-admin install would be
        // permanently unable to close a report, so the rule stands down.
        $this->makeApprover();

        $item = $this->items($incident)->first();

        $this->actingAs($inspector, 'sanctum')
            ->postJson("/api/incident-reports/{$incident->incident_id}/items/{$item->item_id}/outcome",
                ['outcome' => IncidentItem::OUTCOME_SCRAP, 'note' => 'unsellable'])
            ->assertOk();

        $this->actingAs($inspector, 'sanctum')
            ->postJson("/api/incident-reports/{$incident->incident_id}/inspection", [])
            ->assertOk();

        $this->actingAs($inspector, 'sanctum')
            ->postJson("/api/incident-reports/{$incident->incident_id}/decide", ['action' => 'confirm'])
            ->assertStatus(403);

        $this->assertSame(
            IncidentReport::statusId('Awaiting Approval'),
            (int) $incident->refresh()->status_id
        );
    }

    /** A skip is data: the admin sees WHY the box photo is missing. */
    public function test_evidence_records_a_skip_with_its_reason(): void
    {
        ['incident' => $incident, 'location' => $location] = $this->makeScenario('defective');
        $inspector = $this->makeUser('branch_manager', (int) $location->location_id);

        $this->actingAs($inspector, 'sanctum')
            ->postJson("/api/incident-reports/{$incident->incident_id}/evidence", [
                'requirement'    => IncidentEvidence::REQ_BOX_TWO_SIDES,
                'skipped_reason' => 'the box was already discarded',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.skipped_reason', 'the box was already discarded')
            ->assertJsonPath('data.photo_path', null);

        $this->assertDatabaseHas('incident_evidence', [
            'incident_id'    => $incident->incident_id,
            'requirement'    => IncidentEvidence::REQ_BOX_TWO_SIDES,
            'skipped_reason' => 'the box was already discarded',
        ]);
    }

    /**
     * The read side. Without it the inspection screen cannot show the evidence
     * it already attached — the photo route needs an evidence_id no list
     * endpoint returns — and the admin's decision screen cannot show the
     * findings or the skipped requirements, which is the whole point of a
     * checklist whose incompleteness is supposed to be loud.
     */
    public function test_the_show_endpoint_returns_items_evidence_and_findings(): void
    {
        ['incident' => $incident, 'location' => $location] = $this->makeScenario('defective');

        $inspector = $this->makeUser('branch_manager', (int) $location->location_id);
        $item      = $this->items($incident)->first();

        $this->actingAs($inspector, 'sanctum')
            ->postJson("/api/incident-reports/{$incident->incident_id}/items/{$item->item_id}/outcome",
                ['outcome' => IncidentItem::OUTCOME_SCRAP, 'note' => 'compressor seized'])
            ->assertOk();

        $this->actingAs($inspector, 'sanctum')
            ->postJson("/api/incident-reports/{$incident->incident_id}/evidence", [
                'requirement'    => IncidentEvidence::REQ_BOX_TWO_SIDES,
                'skipped_reason' => 'the box was already discarded',
            ])
            ->assertStatus(201);

        $this->actingAs($inspector, 'sanctum')
            ->postJson("/api/incident-reports/{$incident->incident_id}/inspection",
                ['inspection_findings' => 'unit does not power on'])
            ->assertOk();

        // Read back as a role holding `read` but neither inspect nor resolve —
        // the inventory analyst, since the realignment. Whoever is being asked
        // to look at an inspection must be able to see it without any write
        // permission on the report.
        $this->actingAs($this->makeUser('inventory_analyst'), 'sanctum')
            ->getJson("/api/incident-reports/{$incident->incident_id}")
            ->assertOk()
            ->assertJsonPath('data.inspection_findings', 'unit does not power on')
            ->assertJsonPath('data.inspected_by', (int) $inspector->getKey())
            ->assertJsonPath('data.items.0.outcome', IncidentItem::OUTCOME_SCRAP)
            ->assertJsonPath('data.items.0.outcome_note', 'compressor seized')
            ->assertJsonPath('data.evidence.0.requirement', IncidentEvidence::REQ_BOX_TWO_SIDES)
            ->assertJsonPath('data.evidence.0.skipped_reason', 'the box was already discarded')
            // The header the decision screen renders around all of that.
            ->assertJsonPath('data.narrative', 'endpoint test')
            ->assertJsonPath('data.location_id', (int) $location->location_id);
    }

    /**
     * A report an admin REJECTED back to the inspector must be tellable apart
     * from one that is finished.
     *
     * Both are "not open", and status_id is install-specific — Awaiting
     * Inspection is 53 in the dev database and 1862 in this one — so a client
     * with only `is_open` and a number either drops every rejection out of the
     * inspector's worklist or hardcodes an id that is wrong on the next
     * install. The name is the only thing that travels.
     */
    public function test_a_rejected_report_is_distinguishable_from_a_resolved_one(): void
    {
        $approver = $this->makeApprover();

        ['incident' => $rejected, 'location' => $location] = $this->makeScenario('defective');
        $inspector = $this->makeUser('branch_manager', (int) $location->location_id);
        $item      = $this->items($rejected)->first();

        $this->actingAs($inspector, 'sanctum')
            ->postJson("/api/incident-reports/{$rejected->incident_id}/items/{$item->item_id}/outcome",
                ['outcome' => IncidentItem::OUTCOME_SCRAP])
            ->assertOk();
        $this->actingAs($inspector, 'sanctum')
            ->postJson("/api/incident-reports/{$rejected->incident_id}/inspection", [])
            ->assertOk();
        $this->actingAs($approver, 'sanctum')
            ->postJson("/api/incident-reports/{$rejected->incident_id}/decide", [
                'action'         => 'reject',
                'rejection_note' => 'the sticker photo is unreadable',
            ])
            ->assertOk();

        // A second report, taken all the way to Resolved.
        ['incident' => $resolved] = $this->makeScenario('zone_mismatch');
        $this->actingAs($approver, 'sanctum')
            ->postJson("/api/incident-reports/{$resolved->incident_id}/decide", ['action' => 'confirm'])
            ->assertOk();

        $this->actingAs($approver, 'sanctum')
            ->getJson("/api/incident-reports/{$rejected->incident_id}")
            ->assertOk()
            ->assertJsonPath('data.status_name', 'Awaiting Inspection')
            ->assertJsonPath('data.rejection_note', 'the sticker photo is unreadable');

        $this->actingAs($approver, 'sanctum')
            ->getJson("/api/incident-reports/{$resolved->incident_id}")
            ->assertOk()
            ->assertJsonPath('data.status_name', 'Resolved');

        // …and the list endpoint the worklist is actually built from carries the
        // same name, so the queue can be selected without fetching each report.
        $listed = $this->actingAs($approver, 'sanctum')
            ->getJson('/api/incident-reports')
            ->assertOk()
            ->json('data');

        $byId = collect($listed)->keyBy('incident_id');

        $this->assertSame('Awaiting Inspection', $byId[(int) $rejected->incident_id]['status_name']);
        $this->assertSame('Resolved', $byId[(int) $resolved->incident_id]['status_name']);
    }

    /**
     * An unconstrained path let a filer submit another user's avatar, or an
     * absolute URL the frontend would fetch as-is.
     */
    public function test_a_photo_path_outside_the_incidents_directory_is_rejected(): void
    {
        ['incident' => $incident, 'location' => $location] = $this->makeScenario('defective');
        $inspector = $this->makeUser('branch_manager', (int) $location->location_id);

        $this->actingAs($inspector, 'sanctum')
            ->postJson("/api/incident-reports/{$incident->incident_id}/evidence", [
                'requirement' => IncidentEvidence::REQ_DEFECT_PHOTO,
                'photo_path'  => 'avatars/2026/08/' . str_repeat('a', 40) . '.jpg',
            ])
            ->assertStatus(422);

        $this->assertSame(0, IncidentEvidence::where('incident_id', $incident->incident_id)->count());
    }
}
