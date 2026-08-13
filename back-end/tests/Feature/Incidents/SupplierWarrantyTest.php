<?php

namespace Tests\Feature\Incidents;

use App\Models\ApplianceDetail;
use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Receiving;
use App\Models\Supplier;
use App\Services\WarrantyStateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The SECOND warranty clock: the supplier's promise to us, which starts when we
 * received the goods rather than when a customer bought them.
 *
 * A unit sitting in stock has no customer warranty and never will until it is
 * sold — but it is very much still under the supplier's cover, and that is what
 * decides whether a defective one can go back.
 */
class SupplierWarrantyTest extends TestCase
{
    use DatabaseTransactions;

    private function service(): WarrantyStateService
    {
        return app(WarrantyStateService::class);
    }

    /**
     * A serialized appliance received $monthsAgo months ago, with a $termMonths
     * supplier term, sitting on an incident.
     *
     * @return array{item:IncidentItem,supplier:Supplier,receiving:Receiving}
     */
    private function makeUnit(int $monthsAgo, int $termMonths, bool $serialized = true): array
    {
        $location = \App\Models\Location::query()->firstOrFail();
        $user     = \App\Models\User::query()->firstOrFail();

        $supplier = Supplier::create([
            'supplier_name' => 'Sup-' . uniqid(),
            'origin'        => 'Local',
        ]);

        $po = PurchaseOrder::create([
            'po_number'   => 'PO-' . uniqid(),
            'location_id' => $location->location_id,
            'supplier_id' => $supplier->supplier_id,
            'order_date'  => Carbon::today()->subMonths($monthsAgo)->toDateString(),
            'status_id'   => 1,
        ]);

        // supplier_id deliberately LEFT NULL: that is how every receiving in the
        // live database looks, so the supplier has to be found through the PO.
        $receiving = Receiving::create([
            'po_id'            => $po->po_id,
            'location_id'      => $location->location_id,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => Carbon::today()->subMonths($monthsAgo)->toDateString(),
            'received_by'      => $user->getKey(),
        ]);

        $product = Product::create([
            'product_code' => 'APP-' . uniqid(),
            'product_name' => 'Test Appliance',
            'product_type' => 'appliance',
        ]);
        ApplianceDetail::create([
            'product_id'             => $product->product_id,
            'model_code'             => 'MDL-' . uniqid(),
            'warranty_period_months' => $termMonths,
            'is_serialized'          => $serialized,
        ]);

        $incident = IncidentReport::create([
            'incident_number' => 'INC-TEST-' . uniqid(),
            'source_type'     => 'standalone',
            'location_id'     => $location->location_id,
            'product_id'      => $product->product_id,
            'reason'          => IncidentReport::REASON_DEFECTIVE,
            'quantity'        => 1,
            'narrative'       => 'test',
            'reported_by'     => $user->getKey(),
            'status_id'       => IncidentReport::statusId('Open'),
        ]);

        $attributes = [
            'incident_id' => $incident->incident_id,
            'product_id'  => $product->product_id,
            'quantity'    => 1,
            'status'      => IncidentItem::STATUS_QUARANTINED,
        ];

        if ($serialized) {
            $serial = ItemSerial::create([
                'product_id'    => $product->product_id,
                'location_id'   => $location->location_id,
                'receiving_id'  => $receiving->receiving_id,
                'serial_number' => 'SN-' . strtoupper(uniqid()),
                'serial_type'   => 'received',
                'status_id'     => 26,
            ]);
            $attributes['serial_id'] = $serial->serial_id;
        } else {
            $batch = InventoryBatch::create([
                'product_id'         => $product->product_id,
                'location_id'        => $location->location_id,
                'receiving_id'       => $receiving->receiving_id,
                'quantity_received'  => 5,
                'quantity_remaining' => 5,
                'received_at'        => $receiving->receiving_date,
            ]);
            $attributes['batch_id'] = $batch->batch_id;
        }

        $item = IncidentItem::create($attributes);

        return ['item' => $item->fresh(), 'supplier' => $supplier, 'receiving' => $receiving];
    }

    public function test_a_recently_received_unit_is_under_supplier_warranty(): void
    {
        ['item' => $item] = $this->makeUnit(monthsAgo: 2, termMonths: 36);

        $supplier = $this->service()->supplierStateFor($item);

        $this->assertSame(WarrantyStateService::IN_WARRANTY, $supplier->state);
        $this->assertSame(36, $supplier->months);
        $this->assertSame(
            Carbon::today()->subMonths(2)->addMonths(36)->toDateString(),
            $supplier->endDate
        );
    }

    public function test_a_unit_past_its_supplier_term_is_expired(): void
    {
        ['item' => $item] = $this->makeUnit(monthsAgo: 40, termMonths: 36);

        $this->assertSame(
            WarrantyStateService::EXPIRED,
            $this->service()->supplierStateFor($item)->state
        );
    }

    /** Non-serialized goods reach their receiving through the batch instead. */
    public function test_a_batch_only_item_resolves_its_receiving_through_the_batch(): void
    {
        ['item' => $item] = $this->makeUnit(monthsAgo: 1, termMonths: 24, serialized: false);

        $supplier = $this->service()->supplierStateFor($item);

        $this->assertSame(WarrantyStateService::IN_WARRANTY, $supplier->state);
        $this->assertSame(24, $supplier->months);
    }

    /** No term configured is not the same as expired — nothing is known. */
    public function test_a_product_with_no_term_reports_none(): void
    {
        ['item' => $item] = $this->makeUnit(monthsAgo: 1, termMonths: 0);

        $this->assertSame(
            WarrantyStateService::NONE,
            $this->service()->supplierStateFor($item)->state
        );
    }

    /** Every live receiving has a null supplier_id, so the PO is the real source. */
    public function test_the_supplier_is_found_through_the_purchase_order(): void
    {
        ['item' => $item, 'supplier' => $sup, 'receiving' => $rcv] = $this->makeUnit(2, 36);

        $supplier = $this->service()->supplierStateFor($item);

        $this->assertSame((int) $sup->supplier_id, $supplier->supplierId);
        $this->assertSame($sup->supplier_name, $supplier->supplierName);
        $this->assertSame($rcv->receiving_number, $supplier->receivingNumber);
    }

    /** A defective unit whose supplier cover has run out cannot be sent back. */
    public function test_defective_loses_return_to_supplier_when_cover_expired(): void
    {
        ['item' => $item] = $this->makeUnit(monthsAgo: 40, termMonths: 36);

        $this->assertNotContains(
            IncidentItem::OUTCOME_RETURN_TO_SUPPLIER,
            $this->service()->evaluate($item)->outcomes
        );
    }

    public function test_defective_keeps_return_to_supplier_while_covered(): void
    {
        ['item' => $item] = $this->makeUnit(monthsAgo: 2, termMonths: 36);

        $this->assertContains(
            IncidentItem::OUTCOME_RETURN_TO_SUPPLIER,
            $this->service()->evaluate($item)->outcomes
        );
    }

    /**
     * A wrong delivery is the supplier's error whatever its age, so the
     * commercial return stays open even with no cover at all.
     */
    public function test_a_wrong_type_return_is_never_blocked_by_supplier_cover(): void
    {
        ['item' => $item] = $this->makeUnit(monthsAgo: 40, termMonths: 36);

        $item->incident->update(['reason' => IncidentReport::REASON_WRONG_TYPE]);

        $this->assertContains(
            IncidentItem::OUTCOME_RETURN_TO_SUPPLIER,
            $this->service()->evaluate($item->fresh())->outcomes
        );
    }

    /** The endpoint enforces it too — a rule the client merely renders is not a rule. */
    public function test_the_endpoint_refuses_a_return_on_expired_supplier_cover(): void
    {
        ['item' => $item] = $this->makeUnit(monthsAgo: 40, termMonths: 36);

        $admin = \App\Models\User::query()->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/incident-reports/{$item->incident_id}/items/{$item->item_id}/outcome", [
                'outcome' => IncidentItem::OUTCOME_RETURN_TO_SUPPLIER,
            ])
            ->assertStatus(422);
    }

    /** The gate and the payload must be the same list, never two derivations. */
    public function test_evaluate_agrees_with_the_customer_only_helper(): void
    {
        ['item' => $item] = $this->makeUnit(monthsAgo: 2, termMonths: 36);

        $decision = $this->service()->evaluate($item);

        $this->assertSame($this->service()->stateFor($item), $decision->customerState);
        $this->assertSame(
            $this->service()->outcomesForState($decision->customerState),
            $decision->outcomes,
            'a covered defective unit loses nothing, so the two lists match'
        );
    }
}
