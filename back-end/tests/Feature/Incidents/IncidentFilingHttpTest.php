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

    /**
     * A wrong-product return names TWO products: what arrived and what should
     * have. Both are validated keys or neither survives validate(), and the
     * response echoes them from the in-memory model either way — so this asserts
     * against the stored item row.
     */
    public function test_a_wrong_product_return_stores_both_product_ids(): void
    {
        $loc      = $this->makeLocation();
        $wp       = $this->makeUserAt($loc, 'warehouse_personnel');
        $arrived  = $this->makeConsumable('WRONG-ONE');
        $expected = $this->makeConsumable('RIGHT-ONE');

        $res = $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type'         => 'standalone',
            'location_id'         => $loc->location_id,
            'product_id'          => $arrived->product_id,
            'reason'              => IncidentReport::REASON_RETURN,
            'return_counterparty' => 'customer',
            'return_reason_code'  => 'wrong_product',
            'quantity'            => 1,
            'narrative'           => 'The customer was delivered the wrong model.',
            'items' => [[
                'product_id'          => $arrived->product_id,
                'expected_product_id' => $expected->product_id,
                'quantity'            => 1,
                'unit_role'           => IncidentItem::ROLE_SINGLE,
            ]],
        ])->assertCreated();

        $incidentId = (int) $res->json('data.incident_id');

        // The report's own two facts, which replaced return_direction.
        $this->assertDatabaseHas('incident_reports', [
            'incident_id'         => $incidentId,
            'return_counterparty' => 'customer',
            'return_reason_code'  => 'wrong_product',
        ]);

        $item = IncidentItem::where('incident_id', $incidentId)->firstOrFail();

        $this->assertSame((int) $arrived->product_id, (int) $item->product_id,
            'what actually arrived');
        $this->assertSame((int) $expected->product_id, (int) $item->expected_product_id,
            'and what should have — the pair is the whole point of a wrong-product return');
    }

    /**
     * A wrong-quantity return records what was ORDERED beside what arrived.
     * `quantity` alone is one number with no way to see it is the wrong one.
     */
    public function test_a_wrong_quantity_return_stores_the_ordered_quantity(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();

        $res = $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type'         => 'standalone',
            'location_id'         => $loc->location_id,
            'product_id'          => $p->product_id,
            'reason'              => IncidentReport::REASON_RETURN,
            'return_counterparty' => 'customer',
            'return_reason_code'  => 'wrong_quantity',
            'quantity'            => 7,
            'narrative'           => 'Ten were ordered, seven came back short-shipped.',
            'items' => [[
                'product_id'        => $p->product_id,
                'quantity'          => 7,
                'expected_quantity' => 10,
                'unit_role'         => IncidentItem::ROLE_SINGLE,
            ]],
        ])->assertCreated();

        $item = IncidentItem::where('incident_id', (int) $res->json('data.incident_id'))->firstOrFail();

        $this->assertSame(7, (int) $item->quantity, 'what was delivered');
        $this->assertSame(10, (int) $item->expected_quantity, 'what was ordered');
    }

    /**
     * The flat (picking-modal) shape sends no `items`, and must carry the pair
     * too — itemRowsFrom's fallback builds that row from the top-level keys, so
     * they need their own rules as well as the items.* ones.
     */
    public function test_the_flat_shape_carries_the_pair_as_well(): void
    {
        $loc      = $this->makeLocation();
        $wp       = $this->makeUserAt($loc, 'warehouse_personnel');
        $arrived  = $this->makeConsumable('FLAT-WRONG');
        $expected = $this->makeConsumable('FLAT-RIGHT');

        $res = $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type'         => 'standalone',
            'location_id'         => $loc->location_id,
            'product_id'          => $arrived->product_id,
            'reason'              => IncidentReport::REASON_RETURN,
            'return_counterparty' => 'supplier',
            'return_reason_code'  => 'wrong_product',
            'quantity'            => 2,
            'expected_product_id' => $expected->product_id,
            'expected_quantity'   => 2,
            'narrative'           => 'Supplier shipped the wrong model; sending it back.',
        ])->assertCreated();

        $item = IncidentItem::where('incident_id', (int) $res->json('data.incident_id'))->firstOrFail();

        $this->assertSame((int) $expected->product_id, (int) $item->expected_product_id);
        $this->assertSame(2, (int) $item->expected_quantity);
    }

    /** A counterparty or reason code outside the vocabulary must 422, not store a typo. */
    public function test_an_unknown_return_counterparty_or_reason_code_is_rejected(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();

        $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type'         => 'standalone',
            'location_id'         => $loc->location_id,
            'product_id'          => $p->product_id,
            'reason'              => IncidentReport::REASON_RETURN,
            'quantity'            => 1,
            'narrative'           => 'test',
            'return_counterparty' => 'warehouse',      // not one of RETURN_COUNTERPARTIES
            'return_reason_code'  => 'changed_my_mind', // not one of RETURN_REASON_CODES
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['return_counterparty', 'return_reason_code']);
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
