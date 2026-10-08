<?php

namespace Tests\Feature\Aircon;

use App\Models\AirconModel;
use App\Models\Customer;
use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\Receiving;
use App\Models\ReceivingDetail;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AirconModelComposer;
use App\Support\IssuanceStatus;
use Illuminate\Support\Facades\DB;

/**
 * `aircon_groups` on the three document detail endpoints.
 *
 * The grouping arithmetic itself is covered against the service in
 * AirconLineGrouperTest. What is tested here is the wiring: that each endpoint
 * carries the groups, that it still carries everything it carried before, and
 * that an untagged document — which every document written before Task 12 is —
 * pays nothing for the feature.
 */
class AirconDocumentGroupingTest extends AirconTestCase
{
    private const KIND_PO        = 'purchase order';
    private const KIND_ISSUANCE  = 'issuance';
    private const KIND_RECEIVING = 'receiving';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsAdmin();
    }

    // ------------------------------------------------------------- fixtures

    private function location(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Grouping Test Loc',
            'location_type' => 'warehouse',
        ]);
    }

    /**
     * A real two-piece model, composed through the service so its unit lines —
     * which is what the grouper derives the requirement from — actually exist.
     */
    private function composedModel(Product $indoor, Product $outdoor): AirconModel
    {
        return app(AirconModelComposer::class)->create([
            'model_name'      => 'Model ' . uniqid(),
            'brand_id'        => $this->brand()->brand_id,
            'ac_type_id'      => $this->splitType()->ac_type_id,
            'capacity_rating' => '2HP',
            'variant'         => 'Inverter',
            'is_two_piece'    => true,
        ], [
            ['product_id' => $indoor->product_id, 'quantity' => 1],
            ['product_id' => $outdoor->product_id, 'quantity' => 1],
        ]);
    }

    /**
     * The detail endpoint URL for a document of $kind carrying $lines.
     *
     * $lines entries are ['product' => Product, 'quantity' => int,
     * 'aircon_model_id' => ?int].
     *
     * @param  list<array{product:Product,quantity:int,aircon_model_id:?int}>  $lines
     */
    private function documentUrl(string $kind, array $lines): string
    {
        return match ($kind) {
            self::KIND_PO        => '/api/purchase-orders/' . $this->purchaseOrderWith($lines),
            self::KIND_ISSUANCE  => '/api/issuances/' . $this->issuanceWith($lines),
            self::KIND_RECEIVING => '/api/receivings/' . $this->receivingWith($lines),
        };
    }

    /** @param  list<array{product:Product,quantity:int,aircon_model_id:?int}>  $lines */
    private function purchaseOrderWith(array $lines): int
    {
        $location = $this->location();
        $supplier = Supplier::create(['supplier_name' => 'Grouping Supplier ' . uniqid()]);

        $po = PurchaseOrder::create([
            'supplier_id'         => $supplier->supplier_id,
            'location_id'         => $location->location_id,
            'po_number'           => 'PO-ACG-' . uniqid(),
            'order_date'          => today()->toDateString(),
            'verification_status' => 'verified',
            'total_amount'        => 0,
            'grand_total'         => 0,
            'created_by'          => $this->user->user_id,
        ]);

        foreach ($lines as $line) {
            PurchaseOrderDetail::create([
                'po_id'            => $po->po_id,
                'location_id'      => $location->location_id,
                'product_id'       => $line['product']->product_id,
                'aircon_model_id'  => $line['aircon_model_id'],
                'quantity_ordered' => $line['quantity'],
                'unit_price'       => 10000,
                'subtotal'         => 10000 * $line['quantity'],
            ]);
        }

        return (int) $po->po_id;
    }

    /** @param  list<array{product:Product,quantity:int,aircon_model_id:?int}>  $lines */
    private function issuanceWith(array $lines): int
    {
        $location = $this->location();
        $customer = Customer::create([
            'customer_name' => 'Grouping Customer ' . uniqid(),
            'customer_type' => 'individual',
        ]);

        $issuance = Issuance::create([
            'location_id'      => $location->location_id,
            'customer_id'      => $customer->customer_id,
            'issuance_date'    => now()->toDateString(),
            'issuance_type'    => 'individual',
            'dr_number'        => 'DR-ACG-' . uniqid(),
            'discount_type'    => 'none',
            'discount_percent' => 0,
            'subtotal'         => 0,
            'discount_amount'  => 0,
            'total_amount'     => 0,
            'issued_by'        => $this->user->user_id,
            'status_id'        => IssuanceStatus::draft(),
        ]);

        foreach ($lines as $line) {
            IssuanceDetail::create([
                'issuance_id'          => $issuance->issuance_id,
                'product_id'           => $line['product']->product_id,
                'aircon_model_id'      => $line['aircon_model_id'],
                'unit_price'           => 10000,
                'quantity_issued'      => $line['quantity'],
                'quantity_unit'        => 'unit',
                'quantity_in_pieces'   => $line['quantity'],
                'unit_price_per_piece' => 10000,
                'subtotal'             => 10000 * $line['quantity'],
                'condition_issued'     => 'Good',
            ]);
        }

        return (int) $issuance->issuance_id;
    }

    /** @param  list<array{product:Product,quantity:int,aircon_model_id:?int}>  $lines */
    private function receivingWith(array $lines): int
    {
        $location = $this->location();
        $supplier = Supplier::create(['supplier_name' => 'Grouping Supplier ' . uniqid()]);

        $po = PurchaseOrder::create([
            'supplier_id'         => $supplier->supplier_id,
            'location_id'         => $location->location_id,
            'po_number'           => 'PO-ACG-' . uniqid(),
            'order_date'          => today()->toDateString(),
            'verification_status' => 'verified',
            'total_amount'        => 0,
            'grand_total'         => 0,
            'created_by'          => $this->user->user_id,
        ]);

        $receiving = Receiving::create([
            'po_id'                   => $po->po_id,
            'supplier_id'             => $po->supplier_id,
            'location_id'             => $location->location_id,
            'receiving_number'        => 'GRN-ACG-' . uniqid(),
            'receiving_date'          => today()->toDateString(),
            'received_by'             => $this->user->user_id,
            'total_quantity_received' => array_sum(array_column($lines, 'quantity')),
        ]);

        foreach ($lines as $line) {
            ReceivingDetail::create([
                'receiving_id'    => $receiving->receiving_id,
                'product_id'      => $line['product']->product_id,
                'aircon_model_id' => $line['aircon_model_id'],
                'quantity_amount' => $line['quantity'],
                'prod_price'      => 10000,
                'condition'       => 'Good',
            ]);
        }

        return (int) $receiving->receiving_id;
    }

    // ----------------------------------------------------- shared assertions

    /** A balanced 3-indoor + 3-outdoor split reads as one group of 3 complete. */
    private function assertBalancedGroup(string $kind): void
    {
        $indoor  = $this->unitProduct('indoor');
        $outdoor = $this->unitProduct('outdoor');
        $model   = $this->composedModel($indoor, $outdoor);

        $url = $this->documentUrl($kind, [
            ['product' => $indoor,  'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
            ['product' => $outdoor, 'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
        ]);

        $groups = $this->getJson($url)->assertStatus(200)->json('data.aircon_groups');

        $this->assertCount(1, $groups, $kind);
        $this->assertSame((int) $model->aircon_model_id, $groups[0]['aircon_model_id'], $kind);
        $this->assertSame($model->model_name, $groups[0]['model_name'], $kind);
        $this->assertSame(3, $groups[0]['complete_count'], $kind);
        $this->assertFalse($groups[0]['is_unbalanced'], $kind);
        $this->assertSame([], $groups[0]['unpaired'], $kind);
        $this->assertCount(2, $groups[0]['lines'], $kind);
    }

    /** 3 indoor against 2 outdoor: 2 complete, one indoor stranded. */
    private function assertShortDeliveryGroup(string $kind): void
    {
        $indoor  = $this->unitProduct('indoor');
        $outdoor = $this->unitProduct('outdoor');
        $model   = $this->composedModel($indoor, $outdoor);

        $url = $this->documentUrl($kind, [
            ['product' => $indoor,  'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
            ['product' => $outdoor, 'quantity' => 2, 'aircon_model_id' => $model->aircon_model_id],
        ]);

        $groups = $this->getJson($url)->assertStatus(200)->json('data.aircon_groups');

        $this->assertCount(1, $groups, $kind);
        $this->assertSame(2, $groups[0]['complete_count'], $kind);
        $this->assertTrue($groups[0]['is_unbalanced'], $kind);
        $this->assertSame(['indoor' => 1], $groups[0]['unpaired'], $kind);
    }

    /** The state every pre-existing document is in. */
    private function assertUntaggedDocumentHasNoGroups(string $kind): void
    {
        $url = $this->documentUrl($kind, [
            ['product' => $this->unitProduct('indoor'), 'quantity' => 3, 'aircon_model_id' => null],
        ]);

        $this->getJson($url)
            ->assertStatus(200)
            ->assertJsonPath('data.aircon_groups', []);
    }

    /** A tagged pair plus a loose untagged line: two groups, two shapes. */
    private function assertMixedDocument(string $kind): void
    {
        $indoor  = $this->unitProduct('indoor');
        $outdoor = $this->unitProduct('outdoor');
        $model   = $this->composedModel($indoor, $outdoor);
        $loose   = $this->unitProduct('complete');

        $url = $this->documentUrl($kind, [
            ['product' => $indoor,  'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
            ['product' => $outdoor, 'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
            ['product' => $loose,   'quantity' => 5, 'aircon_model_id' => null],
        ]);

        $groups = $this->getJson($url)->assertStatus(200)->json('data.aircon_groups');

        $this->assertCount(2, $groups, $kind);

        $this->assertSame((int) $model->aircon_model_id, $groups[0]['aircon_model_id'], $kind);
        $this->assertSame(3, $groups[0]['complete_count'], $kind);
        $this->assertCount(2, $groups[0]['lines'], $kind);

        // The loose line is its own single-line group with every derived field
        // emptied — an ungrouped line is not a broken group.
        $this->assertNull($groups[1]['aircon_model_id'], $kind);
        $this->assertNull($groups[1]['model_name'], $kind);
        $this->assertNull($groups[1]['complete_count'], $kind);
        $this->assertFalse($groups[1]['is_unbalanced'], $kind);
        $this->assertSame([], $groups[1]['unpaired'], $kind);
        $this->assertCount(1, $groups[1]['lines'], $kind);
        $this->assertSame((int) $loose->product_id, $groups[1]['lines'][0]['product_id'], $kind);
        $this->assertSame(5, $groups[1]['lines'][0]['quantity'], $kind);
    }

    /** Queries the endpoint runs, isolated from fixture creation. */
    private function queryCountFor(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->getJson($url)->assertStatus(200);

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    // ------------------------------------------------------- purchase orders

    public function test_a_purchase_order_carries_a_balanced_aircon_group(): void
    {
        $this->assertBalancedGroup(self::KIND_PO);
    }

    public function test_a_short_purchase_order_reports_the_stranded_unit(): void
    {
        $this->assertShortDeliveryGroup(self::KIND_PO);
    }

    public function test_an_untagged_purchase_order_has_no_groups(): void
    {
        $this->assertUntaggedDocumentHasNoGroups(self::KIND_PO);
    }

    public function test_a_purchase_order_mixing_tagged_and_loose_lines_returns_both_shapes(): void
    {
        $this->assertMixedDocument(self::KIND_PO);
    }

    public function test_the_purchase_order_response_keeps_every_key_it_had(): void
    {
        $indoor  = $this->unitProduct('indoor');
        $outdoor = $this->unitProduct('outdoor');
        $model   = $this->composedModel($indoor, $outdoor);

        $url = $this->documentUrl(self::KIND_PO, [
            ['product' => $indoor,  'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
            ['product' => $outdoor, 'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
        ]);

        $response = $this->getJson($url)->assertStatus(200);

        // `receivings` and `details` are what PurchaseOrderPage and BarcodeScan
        // read off this response; the rest are the header fields every consumer
        // of a PO shows.
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'po_id',
                'po_number',
                'order_date',
                'grand_total',
                'supplier',
                'location',
                'receivings',
                'purchase_returns',
                'details' => [
                    ['po_detail_id', 'product_id', 'quantity_ordered', 'unit_price', 'subtotal', 'product'],
                ],
                'aircon_groups',
            ],
        ]);

        // transformPoDetail()'s own shape is untouched — the flat legacy fields
        // and the nested product object are both still there.
        $this->assertSame(
            (int) $indoor->product_id,
            (int) $response->json('data.details.0.product.product_id')
        );
    }

    // ------------------------------------------------------------- issuances

    public function test_an_issuance_carries_a_balanced_aircon_group(): void
    {
        $this->assertBalancedGroup(self::KIND_ISSUANCE);
    }

    public function test_a_short_issuance_reports_the_stranded_unit(): void
    {
        $this->assertShortDeliveryGroup(self::KIND_ISSUANCE);
    }

    public function test_an_untagged_issuance_has_no_groups(): void
    {
        $this->assertUntaggedDocumentHasNoGroups(self::KIND_ISSUANCE);
    }

    public function test_an_issuance_mixing_tagged_and_loose_lines_returns_both_shapes(): void
    {
        $this->assertMixedDocument(self::KIND_ISSUANCE);
    }

    public function test_the_issuance_response_keeps_every_key_it_had(): void
    {
        $indoor  = $this->unitProduct('indoor');
        $outdoor = $this->unitProduct('outdoor');
        $model   = $this->composedModel($indoor, $outdoor);

        $url = $this->documentUrl(self::KIND_ISSUANCE, [
            ['product' => $indoor,  'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
            ['product' => $outdoor, 'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
        ]);

        $this->getJson($url)
            ->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'issuance_id',
                    'dr_number',
                    'issuance_date',
                    'issuance_type',
                    'total_amount',
                    'status',
                    'location',
                    'details' => [
                        ['issuance_detail_id', 'product_id', 'quantity_issued', 'subtotal', 'product'],
                    ],
                    'aircon_groups',
                ],
            ]);
    }

    // ------------------------------------------------------------ receivings

    public function test_a_receiving_carries_a_balanced_aircon_group(): void
    {
        $this->assertBalancedGroup(self::KIND_RECEIVING);
    }

    public function test_a_short_receiving_reports_the_stranded_unit(): void
    {
        $this->assertShortDeliveryGroup(self::KIND_RECEIVING);
    }

    public function test_an_untagged_receiving_has_no_groups(): void
    {
        $this->assertUntaggedDocumentHasNoGroups(self::KIND_RECEIVING);
    }

    public function test_a_receiving_mixing_tagged_and_loose_lines_returns_both_shapes(): void
    {
        $this->assertMixedDocument(self::KIND_RECEIVING);
    }

    public function test_the_receiving_response_keeps_every_key_it_had(): void
    {
        $indoor  = $this->unitProduct('indoor');
        $outdoor = $this->unitProduct('outdoor');
        $model   = $this->composedModel($indoor, $outdoor);

        $url = $this->documentUrl(self::KIND_RECEIVING, [
            ['product' => $indoor,  'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
            ['product' => $outdoor, 'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
        ]);

        // ReceivingReceipt.jsx and TransactionManagement.jsx read
        // receiving_number, receiving_date, details, total_quantity_received,
        // the purchase order's supplier and the receiver.
        //
        // The relation keys are snake_case, not the camelCase relation names:
        // Model::$snakeAttributes is on, so relationsToArray() snakes them —
        // receivedBy serialises as received_by (shadowing the int column) and
        // purchaseOrder as purchase_order. That is the shape this endpoint has
        // always had; it is pinned here, not changed.
        $this->getJson($url)
            ->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'receiving_id',
                    'receiving_number',
                    'receiving_date',
                    'total_quantity_received',
                    'location',
                    'received_by',
                    'purchase_order' => ['po_id', 'po_number', 'supplier'],
                    'details' => [
                        ['receiving_detail_id', 'product_id', 'quantity_amount', 'prod_price', 'product'],
                    ],
                    'aircon_groups',
                ],
            ]);
    }

    // ------------------------------------------------------- the N+1 guard

    /** Same lines, every aircon tag stripped: the pre-Task-12 document. */
    private function untag(array $lines): array
    {
        return array_map(fn (array $line) => ['aircon_model_id' => null] + $line, $lines);
    }

    /**
     * Adding aircon_groups must cost a bounded constant, not a per-line price.
     *
     * Measured as a difference of differences — tagged minus untagged at two
     * line counts — rather than as raw totals, because these endpoints already
     * carry a per-line cost that has nothing to do with grouping: Product
     * appends `pieces_per_package`, whose accessor lazy-loads installationMaterialDetail,
     * and only the purchase-order endpoint eager-loads it. That pre-existing
     * N+1 is present in the untagged measurement too, so subtracting cancels it
     * and leaves exactly what grouping added.
     *
     * Both properties are asserted, because either alone can be passed by a
     * broken implementation: the cost is at most the grouper's three queries,
     * and it does not grow with the line count.
     */
    public function test_grouping_costs_a_bounded_constant_not_a_per_line_price(): void
    {
        // The very first authenticated request of a test loads the acting
        // user's role row once and caches it. Counted, that one-off lands on
        // whichever measurement happens to run first and shows up as a phantom
        // fourth grouping query. Burn it here.
        $this->getJson($this->documentUrl(
            self::KIND_PO,
            [['product' => $this->unitProduct('indoor'), 'quantity' => 1, 'aircon_model_id' => null]]
        ))->assertStatus(200);

        foreach ([self::KIND_PO, self::KIND_ISSUANCE, self::KIND_RECEIVING] as $kind) {
            $indoor  = $this->unitProduct('indoor');
            $outdoor = $this->unitProduct('outdoor');
            $model   = $this->composedModel($indoor, $outdoor);

            $twoLines = [
                ['product' => $indoor,  'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
                ['product' => $outdoor, 'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
            ];

            $sixLines = $twoLines;

            for ($i = 0; $i < 2; $i++) {
                $extraIndoor  = $this->unitProduct('indoor');
                $extraOutdoor = $this->unitProduct('outdoor');
                $extraModel   = $this->composedModel($extraIndoor, $extraOutdoor);

                $sixLines[] = ['product' => $extraIndoor,  'quantity' => 2, 'aircon_model_id' => $extraModel->aircon_model_id];
                $sixLines[] = ['product' => $extraOutdoor, 'quantity' => 2, 'aircon_model_id' => $extraModel->aircon_model_id];
            }

            $twoCost = $this->queryCountFor($this->documentUrl($kind, $twoLines))
                - $this->queryCountFor($this->documentUrl($kind, $this->untag($twoLines)));

            $sixCost = $this->queryCountFor($this->documentUrl($kind, $sixLines))
                - $this->queryCountFor($this->documentUrl($kind, $this->untag($sixLines)));

            $this->assertSame(
                $twoCost,
                $sixCost,
                "{$kind}: grouping must cost the same on a six-line document as on a two-line one; "
                . "a per-line grouper call shows up here (two-line +{$twoCost}, six-line +{$sixCost})"
            );

            $this->assertLessThanOrEqual(
                3,
                $twoCost,
                "{$kind}: grouping must add at most the grouper's three queries; it added {$twoCost}"
            );
        }
    }

    /**
     * The line arrays the controllers hand the grouper carry the document's own
     * row id, and the grouper passes unrecognised keys straight through. Task
     * 14b nests the rendered rows under their group by that id.
     */
    public function test_a_group_line_carries_the_document_row_id_it_came_from(): void
    {
        $indoor  = $this->unitProduct('indoor');
        $outdoor = $this->unitProduct('outdoor');
        $model   = $this->composedModel($indoor, $outdoor);

        $lines = [
            ['product' => $indoor,  'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
            ['product' => $outdoor, 'quantity' => 3, 'aircon_model_id' => $model->aircon_model_id],
        ];

        $idKeys = [
            self::KIND_PO        => 'po_detail_id',
            self::KIND_ISSUANCE  => 'issuance_detail_id',
            self::KIND_RECEIVING => 'receiving_detail_id',
        ];

        foreach ($idKeys as $kind => $idKey) {
            $groups = $this->getJson($this->documentUrl($kind, $lines))
                ->assertStatus(200)
                ->json('data.aircon_groups');

            foreach ($groups[0]['lines'] as $line) {
                $this->assertArrayHasKey($idKey, $line, $kind);
                $this->assertGreaterThan(0, $line[$idKey], $kind);
            }
        }
    }
}
