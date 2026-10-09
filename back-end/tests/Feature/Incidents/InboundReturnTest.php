<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Services\IncidentItemService;
use App\Services\IncidentService;
use Tests\Feature\Sets\SetTestCase;

/**
 * A customer return was issued out weeks ago and is in NO batch. Filing one
 * used to quarantine an existing batch — quarantining stock that is not there.
 *
 * Inbound goods are physically present and deliberately NOT in
 * quantity_on_hand: counting a return before anyone has looked at it is how a
 * return that turns out to be scrap inflates the stock figure.
 */
class InboundReturnTest extends SetTestCase
{
    private function fileReturn(string $counterparty, int $onHand = 20, int $qty = 3): array
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeInstallationMaterial();

        $this->setStock($loc->location_id, $p->product_id, $onHand);
        $b = $this->makeBatch($p, $loc, ['quantity_remaining' => $onHand, 'received_at' => now()]);

        $incident = app(IncidentService::class)->file([
            'source_type'         => 'standalone',
            'location_id'         => $loc->location_id,
            'product_id'          => $p->product_id,
            'batch_id'            => $b->batch_id,
            'reason'              => IncidentReport::REASON_RETURN,
            'return_counterparty' => $counterparty,
            'return_reason_code'  => 'no_longer_needed',
            'quantity'            => $qty,
            'narrative'           => 'test',
        ], (int) $wp->user_id);

        return compact('loc', 'p', 'b', 'incident');
    }

    private function itemFor(IncidentReport $i): IncidentItem
    {
        return IncidentItem::where('incident_id', $i->incident_id)->firstOrFail();
    }

    public function test_completed_inbound_repair_adds_stock_and_fifo_batch_exactly_once(): void
    {
        ['loc' => $loc, 'p' => $p, 'incident' => $incident] = $this->fileReturn('customer', 20, 3);
        $svc = app(IncidentItemService::class);
        $item = $this->itemFor($incident);
        $actor = (int) $this->makeUserAt($loc, 'inventory_analyst')->user_id;
        $svc->setOutcome($item, IncidentItem::OUTCOME_REPAIR, 'Needs repair');
        $svc->applyOutcomes($incident, $actor);
        $svc->completeRepair($item, 'Repaired and tested', $actor);
        $this->assertSame(23, (int) Inventory::where('location_id', $loc->location_id)
            ->where('product_id', $p->product_id)->value('quantity_on_hand'));
        $this->assertSame(3, (int) InventoryBatch::where('location_id', $loc->location_id)
            ->where('product_id', $p->product_id)->whereNull('received_at')->value('quantity_remaining'));
        try {
            $svc->completeRepair($item, 'Repeat', $actor);
            $this->fail('A second completion must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertSame(23, (int) Inventory::where('location_id', $loc->location_id)
                ->where('product_id', $p->product_id)->value('quantity_on_hand'));
        }
    }

    public function test_a_customer_return_is_marked_inbound(): void
    {
        ['incident' => $incident] = $this->fileReturn('customer');

        $this->assertTrue((bool) $this->itemFor($incident)->is_inbound);
    }

    public function test_a_supplier_return_is_not_inbound(): void
    {
        ['incident' => $incident] = $this->fileReturn('supplier');

        $this->assertFalse((bool) $this->itemFor($incident)->is_inbound);
    }

    /**
     * THE fix: an inbound item must not carry a batch_id at all.
     *
     * Quarantine already deducts nothing, so a batch assertion alone would pass
     * on the broken code too. What is actually wrong today is that the item is
     * BOUND to a batch the returned units are not in — and confirmation would
     * then restock them onto it, inflating a traceable batch with goods that
     * never came from it.
     */
    public function test_an_inbound_item_is_bound_to_no_batch(): void
    {
        ['b' => $b, 'incident' => $incident] = $this->fileReturn('customer', 20, 3);

        $item = $this->itemFor($incident);
        $this->assertNull($item->batch_id,
            'the returned units were issued out long ago and belong to no batch');
        $this->assertSame(20, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
        $this->assertSame(IncidentItem::STATUS_QUARANTINED, $item->status);
    }

    /** …and the goods are present but deliberately uncounted. */
    public function test_an_inbound_return_is_not_in_quantity_on_hand(): void
    {
        ['loc' => $loc, 'p' => $p, 'incident' => $incident] = $this->fileReturn('customer', 20, 3);

        $inv = Inventory::where('location_id', $loc->location_id)
            ->where('product_id', $p->product_id)->firstOrFail();

        $this->assertSame(20, (int) $inv->quantity_on_hand, 'not accepted back yet');
    }

    /** Accepting it back is what finally counts it. */
    public function test_restocking_an_inbound_return_adds_to_stock(): void
    {
        ['loc' => $loc, 'p' => $p, 'b' => $b, 'incident' => $incident] = $this->fileReturn('customer', 20, 3);
        $svc = app(IncidentItemService::class);

        $svc->setOutcome($this->itemFor($incident), IncidentItem::OUTCOME_RESTOCK, 'unopened');
        $svc->applyOutcomes($incident, (int) $this->makeUserAt($loc, 'inventory_analyst')->user_id);

        $inv = Inventory::where('location_id', $loc->location_id)
            ->where('product_id', $p->product_id)->firstOrFail();

        $this->assertSame(23, (int) $inv->quantity_on_hand, 'the three units are accepted back');
        $this->assertSame(23, (int) InventoryBatch::where('product_id', $p->product_id)
            ->where('location_id', $loc->location_id)->sum('quantity_remaining'),
            'and they land in a batch, or FIFO can never reach them');
    }

    /** Scrapping one changes nothing — it was never counted. */
    public function test_scrapping_an_inbound_return_changes_no_counts(): void
    {
        ['loc' => $loc, 'p' => $p, 'incident' => $incident] = $this->fileReturn('customer', 20, 3);
        $svc = app(IncidentItemService::class);

        $svc->setOutcome($this->itemFor($incident), IncidentItem::OUTCOME_SCRAP, 'water damaged');
        $svc->applyOutcomes($incident, (int) $this->makeUserAt($loc, 'inventory_analyst')->user_id);

        $inv = Inventory::where('location_id', $loc->location_id)
            ->where('product_id', $p->product_id)->firstOrFail();

        $this->assertSame(20, (int) $inv->quantity_on_hand);
    }

    /** The unchanged path: a supplier return still behaves exactly as before. */
    public function test_an_outbound_return_still_quarantines_and_deducts(): void
    {
        ['loc' => $loc, 'p' => $p, 'b' => $b, 'incident' => $incident] = $this->fileReturn('supplier', 20, 3);
        $svc = app(IncidentItemService::class);

        // Quarantine deducts NOTHING, inbound or outbound — the goods are
        // present and merely unpickable. Confirmation is the only deduction in
        // the whole lifecycle.
        $this->assertSame(20, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);

        $svc->setOutcome($this->itemFor($incident), IncidentItem::OUTCOME_RETURN_TO_SUPPLIER, 'over-shipped');
        $svc->applyOutcomes($incident, (int) $this->makeUserAt($loc, 'inventory_analyst')->user_id);

        $inv = Inventory::where('location_id', $loc->location_id)
            ->where('product_id', $p->product_id)->firstOrFail();
        $this->assertSame(17, (int) $inv->quantity_on_hand, 'the goods left, once, at confirmation');
        $this->assertSame(17, (int) InventoryBatch::find($b->batch_id)->quantity_remaining);
    }

    /** A warranty claim keeps the unit here — nothing may be deducted. */
    public function test_a_warranty_claim_deducts_nothing(): void
    {
        ['loc' => $loc, 'p' => $p, 'incident' => $incident] = $this->fileReturn('supplier', 20, 3);
        $svc = app(IncidentItemService::class);

        $svc->setOutcome($this->itemFor($incident), IncidentItem::OUTCOME_CLAIM_WARRANTY, 'panel cracked');
        $svc->applyOutcomes($incident, (int) $this->makeUserAt($loc, 'inventory_analyst')->user_id);

        $this->assertSame(IncidentItem::STATUS_AWAITING_WARRANTY_CLAIM,
            $this->itemFor($incident)->status);
        // The unit is on the bench awaiting a claim, not gone. Under the old
        // OUTCOME_RESTOCKS check — "anything that isn't restock is a departure"
        // — this would have deleted three units that are physically here.
        $inv = Inventory::where('location_id', $loc->location_id)
            ->where('product_id', $p->product_id)->firstOrFail();
        $this->assertSame(20, (int) $inv->quantity_on_hand);
    }
}
