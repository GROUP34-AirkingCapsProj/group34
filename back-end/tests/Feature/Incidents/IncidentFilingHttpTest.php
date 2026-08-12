<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use Tests\Feature\Sets\SetTestCase;

/**
 * Filing over HTTP, not through the service.
 *
 * Every other test in this module calls IncidentService::file() directly, and
 * that blind spot hid a real bug: `items` and the whole client document block
 * were absent from IncidentReportController::store()'s validate() array.
 * validate() returns ONLY the keys it validated, and that return value is what
 * reaches file() — so an unlisted key is not rejected, it is silently dropped.
 * Every guided filing fell back to a single flat item and stored the dealer and
 * customer block as nulls, with a 201 and a response body that looked correct.
 *
 * These tests assert against the DATABASE, never the create response: the
 * response is built from the in-memory model and would echo values that were
 * never persisted.
 */
class IncidentFilingHttpTest extends SetTestCase
{
    public function test_the_endpoint_persists_every_item_it_was_sent(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 9, 'received_at' => now()]);

        $res = $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'batch_id'    => $b->batch_id,
            'reason'      => IncidentReport::REASON_DEFECTIVE,
            'quantity'    => 1,
            'narrative'   => 'Split system arrived cracked.',
            'root_cause'  => 'delivery',
            // A split system: two physical units, one report.
            'items' => [
                ['product_id' => $p->product_id, 'batch_id' => $b->batch_id, 'quantity' => 1,
                 'unit_role' => IncidentItem::ROLE_INDOOR,  'model_code' => 'IN-100',  'serial_number' => 'SN-IN-1'],
                ['product_id' => $p->product_id, 'batch_id' => $b->batch_id, 'quantity' => 1,
                 'unit_role' => IncidentItem::ROLE_OUTDOOR, 'model_code' => 'OUT-100', 'serial_number' => 'SN-OUT-1'],
            ],
        ])->assertCreated();

        $incidentId = $res->json('data.incident_id');
        $items = IncidentItem::where('incident_id', $incidentId)->orderBy('item_id')->get();

        // TWO items, not the one the flat fallback would have produced.
        $this->assertCount(2, $items, 'both halves of the split system must be stored');
        $this->assertSame(IncidentItem::ROLE_INDOOR, $items[0]->unit_role);
        $this->assertSame(IncidentItem::ROLE_OUTDOOR, $items[1]->unit_role);
        $this->assertSame('SN-IN-1', $items[0]->serial_number);
        $this->assertSame('OUT-100', $items[1]->model_code);
        $items->each(fn (IncidentItem $i) =>
            $this->assertSame(IncidentItem::STATUS_QUARANTINED, $i->status));
    }

    public function test_the_endpoint_persists_the_client_document_block(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();

        $res = $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type'      => 'standalone',
            'location_id'      => $loc->location_id,
            'product_id'       => $p->product_id,
            'reason'           => IncidentReport::REASON_RETURN,
            'quantity'         => 1,
            'narrative'        => 'Customer returned the wrong model.',
            'return_direction' => 'from_customer',
            'dealer_name'      => 'LGM Davao',
            'dealer_phone'     => '(082) 000-0000',
            'dealer_email'     => 'dealer@example.com',
            'customer_name'    => 'Juan dela Cruz',
            'customer_contact' => '0917 000 0000',
            'site_address'     => '12 Sampaguita St, Davao City',
            'purchased_at'     => '2026-01-15',
            'received_at_dor'  => '2026-01-20',
        ])->assertCreated();

        // Asserted against the stored row: the create response is serialized
        // from the in-memory model and echoes input whether or not it persisted.
        $this->assertDatabaseHas('incident_reports', [
            'incident_id'      => $res->json('data.incident_id'),
            'dealer_name'      => 'LGM Davao',
            'dealer_email'     => 'dealer@example.com',
            'customer_name'    => 'Juan dela Cruz',
            'site_address'     => '12 Sampaguita St, Davao City',
        ]);
    }

    /** The picking modal sends no `items` at all and must keep working. */
    public function test_a_filing_without_items_still_quarantines_one_unit(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();
        $b   = $this->makeBatch($p, $loc, ['quantity_remaining' => 4, 'received_at' => now()]);

        $res = $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'batch_id'    => $b->batch_id,
            'reason'      => IncidentReport::REASON_MISSING,
            'quantity'    => 2,
            'narrative'   => 'Not on the shelf.',
        ])->assertCreated();

        $items = IncidentItem::where('incident_id', $res->json('data.incident_id'))->get();

        $this->assertCount(1, $items);
        $this->assertSame(2, (int) $items[0]->quantity);
        $this->assertSame(IncidentItem::ROLE_SINGLE, $items[0]->unit_role);
    }

    /** A bad role must 422, not be quietly coerced or dropped. */
    public function test_an_unknown_unit_role_is_rejected(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();

        $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'reason'      => IncidentReport::REASON_DEFECTIVE,
            'quantity'    => 1,
            'narrative'   => 'test',
            'items'       => [['product_id' => $p->product_id, 'quantity' => 1, 'unit_role' => 'sideways']],
        ])->assertStatus(422);
    }
}
