<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Services\IncidentItemService;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The ORDER of the workflow, at the service layer.
 *
 * Was Tests\Feature\Holds\IncidentHoldResolutionTest, which pinned
 * approve-then-inspect: resolving deducted the stock into a `stock_holds` row
 * that somebody inspected afterwards. The order is now reversed — the inspector
 * examines first and the admin's confirmation is the single moment stock moves —
 * so every assertion here has been inverted to the new order rather than
 * dropped. This is the reversal coverage.
 */
class IncidentDecisionOrderTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * A real user id. reviewed_by is a foreign key to users and the seeded ids
     * differ between the dev and test databases, so this cannot be a literal.
     */
    private function reviewerId(): int
    {
        return (int) \App\Models\User::query()->firstOrFail()->getKey();
    }

    /** Resolved by NAME — inventory status ids differ between the two databases. */
    private function inventoryStatusId(string $name): int
    {
        return (int) DB::table('status_lookup')
            ->where('status_category', 'inventory')
            ->where('status_name', $name)
            ->value('status_id');
    }

    /**
     * File through IncidentService so the report gets its items.
     *
     * The old helper built the IncidentReport row by hand, because under the
     * old order nothing existed until approval created the hold. Filing is now
     * what quarantines, so the report has to be made the way production makes
     * it or there is nothing to inspect.
     */
    private function fileIncident(string $reason, int $onHand = 100, int $qty = 10): array
    {
        $location = \App\Models\Location::query()->firstOrFail();
        $product  = \App\Models\Product::query()->firstOrFail();

        Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)->delete();

        $inventory = Inventory::create([
            'location_id'        => $location->location_id,
            'product_id'         => $product->product_id,
            'status_id'          => $this->inventoryStatusId('In Stock'),
            'quantity_on_hand'   => $onHand,
            'available_quantity' => $onHand,
            'reorder_level'      => 0,
        ]);

        $batch = InventoryBatch::create([
            'location_id'        => $location->location_id,
            'product_id'         => $product->product_id,
            'quantity_received'  => $onHand,
            'quantity_remaining' => $onHand,
            'received_at'        => now(),
            'zone_label'         => 'ZONE-1',
        ]);

        $incident = app(IncidentService::class)->file([
            'source_type'   => 'standalone',
            'location_id'   => (int) $location->location_id,
            'product_id'    => (int) $product->product_id,
            'batch_id'      => (int) $batch->batch_id,
            'reason'        => $reason,
            'quantity'      => $qty,
            'narrative'     => 'test',
            'actual_zone'   => 'ZONE-9',
            'expected_zone' => 'ZONE-1',
        ], $this->reviewerId());

        return compact('inventory', 'batch', 'incident');
    }

    private function onlyItem(IncidentReport $incident): IncidentItem
    {
        return IncidentItem::where('incident_id', $incident->incident_id)->firstOrFail();
    }

    /** Walk a report all the way through: outcome -> inspection -> confirmation. */
    private function settle(IncidentReport $incident, string $outcome): void
    {
        app(IncidentItemService::class)->setOutcome($this->onlyItem($incident), $outcome, null);
        app(IncidentService::class)->submitInspection($incident, [], $this->reviewerId());
        app(IncidentService::class)->decide($incident->fresh(), 'confirm', [], $this->reviewerId());
    }

    /**
     * Was `test_missing_deducts_stock_and_opens_a_terminal_hold`.
     *
     * Inverted: filing no longer deducts and opens no hold. It quarantines, and
     * the deduction happens once, at confirmation. `missing` is the terminal
     * ending the old CONDITION_MISSING hold stood for — a written-off unit that
     * can go nowhere else — and it is now an item STATUS, reached by the
     * `write_off` outcome.
     */
    public function test_a_missing_report_deducts_only_at_confirmation(): void
    {
        ['inventory' => $inv, 'incident' => $incident] = $this->fileIncident('missing');

        // Inverted: this was 90 immediately after approval.
        $this->assertSame(100, (int) $inv->refresh()->quantity_on_hand, 'filing must not deduct');
        $this->assertSame(IncidentItem::STATUS_QUARANTINED, $this->onlyItem($incident)->status);

        $this->settle($incident, IncidentItem::OUTCOME_WRITE_OFF);

        $this->assertSame(90, (int) $inv->refresh()->quantity_on_hand);
        // Inverted: was StockHold::CONDITION_MISSING on a stock_holds row.
        $this->assertSame(IncidentItem::STATUS_MISSING, $this->onlyItem($incident)->status);
    }

    /**
     * Was `test_defective_deducts_stock_and_opens_a_defective_hold`.
     *
     * Inverted the same way. The old CONDITION_DEFECTIVE hold said "damaged,
     * still in the building, somebody decides later"; that middle state is now
     * `quarantined`, and the inspector's `scrap` outcome is the decision.
     */
    public function test_a_defective_report_holds_the_goods_until_the_scrap_is_confirmed(): void
    {
        ['inventory' => $inv, 'incident' => $incident] = $this->fileIncident('defective');

        $this->assertSame(100, (int) $inv->refresh()->quantity_on_hand, 'filing must not deduct');
        $this->assertSame(IncidentItem::STATUS_QUARANTINED, $this->onlyItem($incident)->status);

        $this->settle($incident, IncidentItem::OUTCOME_SCRAP);

        $this->assertSame(90, (int) $inv->refresh()->quantity_on_hand);
        $this->assertSame(IncidentItem::STATUS_SCRAPPED, $this->onlyItem($incident)->status);
    }

    /**
     * REVERSAL, kept and re-aimed: a customer return must not be sellable
     * before somebody has looked at it.
     *
     * The old code incremented on approval; the intermediate design deducted it
     * into a `returned` hold. Both moved stock too early. Filing now moves
     * nothing at all and merely makes the units unpickable, so the count is
     * untouched throughout and only the batch's availability changes.
     */
    public function test_a_return_is_not_sellable_until_it_has_been_inspected(): void
    {
        ['inventory' => $inv, 'batch' => $batch, 'incident' => $incident] = $this->fileIncident('return');

        $this->assertSame(100, (int) $inv->refresh()->quantity_on_hand, 'a return must not be sellable on filing');
        $this->assertSame(100, (int) $batch->refresh()->quantity_remaining);
        // Inverted: was a stock_holds row with CONDITION_RETURNED. Unpickability
        // is now carried by the item's own status, which is what FIFO reads.
        $this->assertContains(
            (int) $batch->batch_id,
            app(IncidentService::class)->excludedBatchIds((int) $incident->location_id)
        );

        $this->settle($incident, IncidentItem::OUTCOME_RESTOCK);

        // Restocking never deducted, so the numbers do not move — the unit just
        // becomes pickable again.
        $this->assertSame(100, (int) $inv->refresh()->quantity_on_hand);
        $this->assertSame(100, (int) $batch->refresh()->quantity_remaining);
        $this->assertSame(IncidentItem::STATUS_AVAILABLE, $this->onlyItem($incident)->status);
        $this->assertNotContains(
            (int) $batch->batch_id,
            app(IncidentService::class)->excludedBatchIds((int) $incident->location_id)
        );
    }

    /**
     * REVERSAL, unchanged in meaning: RelocateResolution rewrote the batch's
     * zone_label from actual_zone. The system record is CORRECT and the shelf is
     * wrong — the fix is physical. Deciding must change nothing.
     */
    public function test_zone_mismatch_changes_absolutely_nothing(): void
    {
        ['inventory' => $inv, 'batch' => $batch, 'incident' => $incident] = $this->fileIncident('zone_mismatch');

        app(IncidentService::class)->decide($incident, 'confirm', [], $this->reviewerId());

        $this->assertSame('ZONE-1', $batch->refresh()->zone_label, 'the system zone must not be overwritten');
        $this->assertSame(100, (int) $inv->refresh()->quantity_on_hand);
        $this->assertSame(100, (int) $batch->quantity_remaining);
        // Inverted: was `0 stock_holds rows`. A zone mismatch moves nothing, so
        // it quarantines nothing either — there is no item to inspect.
        $this->assertSame(0, IncidentItem::where('incident_id', $incident->incident_id)->count());
        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $incident->refresh()->status_id);
    }

    /**
     * Was `test_a_defective_incident_awaits_inspection_rather_than_resolving`,
     * which asserted that APPROVING a defective report parked it at Awaiting
     * Inspection.
     *
     * Inverted: approval no longer comes first, so there is nothing to park.
     * Awaiting Approval is now reached by the inspector SUBMITTING, and a
     * confirmation attempted before that is refused outright.
     */
    public function test_a_defective_report_cannot_be_confirmed_before_it_is_inspected(): void
    {
        ['incident' => $incident] = $this->fileIncident('defective');

        try {
            app(IncidentService::class)->decide($incident, 'confirm', [], $this->reviewerId());
            $this->fail('confirming an uninspected report must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not been inspected', $e->getMessage());
        }

        // A refused confirmation leaves the report exactly where it was.
        $this->assertSame(IncidentReport::statusId('Open'), (int) $incident->refresh()->status_id);

        app(IncidentService::class)->submitInspection($incident, [], $this->reviewerId());

        $this->assertSame(
            IncidentReport::statusId('Awaiting Approval'),
            (int) $incident->refresh()->status_id
        );
    }

    /**
     * Was `test_a_missing_incident_resolves_immediately` — "a loss has nothing
     * to inspect, so it finishes on approval".
     *
     * INVERTED. A loss is precisely what needs a second pair of eyes: it is the
     * write-off with no goods left to re-count afterwards. `missing` now takes
     * the same inspect-then-confirm route as every other stock-affecting reason,
     * and only `zone_mismatch` and `other` skip it.
     */
    public function test_a_missing_incident_no_longer_resolves_immediately(): void
    {
        ['incident' => $incident] = $this->fileIncident('missing');

        $this->expectException(\RuntimeException::class);
        app(IncidentService::class)->decide($incident, 'confirm', [], $this->reviewerId());
    }

    /**
     * Was `test_disposing_the_last_hold_resolves_the_incident`.
     *
     * Inverted: disposing a hold used to be the last step and the thing that
     * carried the report to Resolved. There is no disposal any more — the
     * admin's confirmation applies every recorded outcome and closes the report
     * in the same transaction, so the two events cannot come apart.
     */
    public function test_confirming_applies_the_outcome_and_resolves_in_one_step(): void
    {
        ['incident' => $incident] = $this->fileIncident('return');

        $this->settle($incident, IncidentItem::OUTCOME_SCRAP);

        $this->assertSame(IncidentReport::statusId('Resolved'), (int) $incident->refresh()->status_id);
        $this->assertSame(IncidentItem::STATUS_SCRAPPED, $this->onlyItem($incident)->status);
    }

    /** Zone mismatch quarantines nothing, so it is confirmable straight from Open. */
    public function test_zone_mismatch_resolves_immediately(): void
    {
        ['incident' => $incident] = $this->fileIncident('zone_mismatch');

        app(IncidentService::class)->decide($incident, 'confirm', [], $this->reviewerId());

        $this->assertSame(
            IncidentReport::statusId('Resolved'),
            (int) $incident->refresh()->status_id
        );
    }
}
