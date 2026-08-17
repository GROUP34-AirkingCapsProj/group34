<?php

namespace Tests\Feature\Reports;

use App\Models\Adjustment;
use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Transfer;
use App\Reports\Definitions\LocationPerformanceReport;
use App\Reports\ReportDataset;
use App\Reports\ReportParameters;

class LocationPerformanceReportTest extends ReportTestCase
{
    private function params(string $from, string $to, array $extra = []): ReportParameters
    {
        return new ReportParameters(array_merge([
            'date_from' => $from,
            'date_to'   => $to,
        ], $extra));
    }

    public function test_it_declares_date_range_as_required(): void
    {
        $dateRange = collect(app(LocationPerformanceReport::class)->parameters())
            ->firstWhere('key', 'date_range');

        $this->assertNotNull($dateRange, 'Location Performance must declare a date_range parameter.');
        $this->assertTrue($dateRange->required, 'date_range must be required.');
    }

    /**
     * Transfer, Issuance and Adjustment all use SoftDeletes, but this report
     * reaches them through DB::table(), which does not apply Eloquent's global
     * scope. A deleted document left in the totals here makes this report
     * disagree with TransactionReport — which goes through Eloquent — over the
     * very same period.
     */
    public function test_soft_deleted_movements_are_excluded(): void
    {
        $location = $this->makeLocation('SoftDelLoc');
        $other    = $this->makeLocation('SoftDelOther');
        $admin    = $this->makeUserAt($location, 'admin');
        $product  = $this->makeAppliance();

        // Receivings are not soft-deletable, so this one anchors the row and
        // proves the report still counts what is genuinely there.
        $receiving = $this->makeReceiving($location->location_id);
        $receiving->update(['total_quantity_received' => 12, 'receiving_date' => '2026-08-10']);

        Transfer::create([
            'from_location_id'           => $other->location_id,
            'to_location_id'             => $location->location_id,
            'transfer_number'            => 'TRF-DEL-IN-' . uniqid(),
            'transfer_date'              => '2026-08-11',
            'total_quantity_transferred' => 10,
            'total_quantity_received'    => 10,
        ])->delete();

        Transfer::create([
            'from_location_id'           => $location->location_id,
            'to_location_id'             => $other->location_id,
            'transfer_number'            => 'TRF-DEL-OUT-' . uniqid(),
            'transfer_date'              => '2026-08-12',
            'total_quantity_transferred' => 9,
            'total_quantity_received'    => 9,
        ])->delete();

        $issuance = Issuance::create([
            'location_id'   => $location->location_id,
            'issuance_date' => '2026-08-13',
        ]);
        IssuanceDetail::create([
            'issuance_id'     => $issuance->issuance_id,
            'product_id'      => $product->product_id,
            'quantity_issued' => 7,
        ]);
        $issuance->delete();

        Adjustment::create([
            'location_id'             => $location->location_id,
            'adjustment_number'       => 'ADJ-DEL-' . uniqid(),
            'adjustment_date'         => '2026-08-14',
            'total_variance_positive' => 6,
            'total_variance_negative' => 2,
        ])->delete();

        $dataset = app(LocationPerformanceReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $location->location_id]),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('location', 'SoftDelLoc');
        $this->assertNotNull($row);
        $this->assertSame(12, $row['units_in'], 'A deleted transfer must not count as units in.');
        $this->assertSame(0, $row['units_out'], 'A deleted transfer or issuance must not count as units out.');
        $this->assertSame(0, $row['net_adjustments'], 'A deleted adjustment must not move the net.');
    }

    public function test_units_in_and_units_out_are_separated_correctly(): void
    {
        $location = $this->makeLocation('Main');
        $other    = $this->makeLocation('Other');
        $admin    = $this->makeUserAt($location, 'admin');
        $product  = $this->makeAppliance();

        // Inbound: a receiving and a transfer IN.
        $receiving = $this->makeReceiving($location->location_id);
        $receiving->update(['total_quantity_received' => 12, 'receiving_date' => '2026-08-10']);

        $transferIn = Transfer::create([
            'from_location_id'           => $other->location_id,
            'to_location_id'             => $location->location_id,
            'transfer_number'            => 'TRF-IN-' . uniqid(),
            'transfer_date'              => '2026-08-11',
            'total_quantity_transferred' => 10,
            'total_quantity_received'    => 8, // short receipt
        ]);

        // Outbound: an issuance, a sale, and a transfer OUT.
        $issuance = Issuance::create([
            'location_id'   => $location->location_id,
            'issuance_date' => '2026-08-12',
        ]);
        IssuanceDetail::create([
            'issuance_id'     => $issuance->issuance_id,
            'product_id'      => $product->product_id,
            'quantity_issued' => 7,
        ]);

        $sale = Sale::create([
            'location_id'    => $location->location_id,
            'invoice_number' => 'INV-' . uniqid(),
            'sale_date'      => '2026-08-13',
            'total_amount'   => 500.00,
        ]);
        SaleDetail::create([
            'sales_id'   => $sale->sales_id,
            'product_id' => $product->product_id,
            'quantity'   => 3,
            'unit_price' => 166.67,
            'subtotal'   => 500.00,
        ]);

        $transferOut = Transfer::create([
            'from_location_id'           => $location->location_id,
            'to_location_id'             => $other->location_id,
            'transfer_number'            => 'TRF-OUT-' . uniqid(),
            'transfer_date'              => '2026-08-14',
            'total_quantity_transferred' => 9,
            'total_quantity_received'    => 9,
        ]);

        $dataset = app(LocationPerformanceReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $location->location_id]),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('location', 'Main');
        $this->assertNotNull($row);

        // Units in: receiving (12) + transfer IN using total_quantity_received (8, not 10).
        $this->assertSame(20, $row['units_in']);

        // Units out: issuance (7) + sale (3) + transfer OUT using total_quantity_transferred (9).
        $this->assertSame(19, $row['units_out']);
    }

    public function test_net_adjustments_have_their_own_column_and_are_excluded_from_movement(): void
    {
        $location = $this->makeLocation('AdjLoc');
        $admin    = $this->makeUserAt($location, 'admin');

        Adjustment::create([
            'location_id'             => $location->location_id,
            'adjustment_number'       => 'ADJ-' . uniqid(),
            'adjustment_date'         => '2026-08-15',
            'total_variance_positive' => 6,
            'total_variance_negative' => 2,
        ]);

        $dataset = app(LocationPerformanceReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $location->location_id]),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('location', 'AdjLoc');
        $this->assertNotNull($row);

        $this->assertSame(4, $row['net_adjustments'], '6 positive minus 2 negative.');
        $this->assertSame(0, $row['units_in'], 'A count correction must not be counted as goods moving in.');
        $this->assertSame(0, $row['units_out'], 'A count correction must not be counted as goods moving out.');
    }

    public function test_each_row_carries_its_location_type(): void
    {
        $warehouse = $this->makeLocation('WH-Type');
        $admin     = $this->makeUserAt($warehouse, 'admin');

        $dataset = app(LocationPerformanceReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $warehouse->location_id]),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('location', 'WH-Type');
        $this->assertNotNull($row);
        $this->assertSame('warehouse', $row['location_type']);
    }

    public function test_a_location_with_no_movement_still_appears_with_zeros(): void
    {
        $quiet = $this->makeLocation('Quiet Location');
        $admin = $this->makeUserAt($quiet, 'admin');
        $product = $this->makeAppliance();
        $this->setStock($quiet->location_id, $product->product_id, 5);

        $dataset = app(LocationPerformanceReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $quiet->location_id]),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('location', 'Quiet Location');

        $this->assertNotNull($row, 'A location with no movement in the period must still appear.');
        $this->assertSame(0, $row['units_in']);
        $this->assertSame(0, $row['units_out']);
        $this->assertSame(0, $row['net_adjustments']);
        $this->assertSame(5, $row['closing_units'], 'Closing stock is a live snapshot, so current stock must still show.');
    }

    public function test_a_scoped_user_sees_only_their_own_location(): void
    {
        $mine   = $this->makeLocation('Mine');
        $theirs = $this->makeLocation('Theirs');
        $scoped = $this->makeUserAt($mine, 'branch_manager');
        $this->grantPermission($scoped, 'report_viewing', 'read');

        $dataset = app(LocationPerformanceReport::class)->build(
            $this->params('2026-08-01', '2026-08-31'),
            $scoped
        );

        $names = collect($dataset->rows)->pluck('location')->all();

        $this->assertContains('Mine', $names);
        $this->assertNotContains('Theirs', $names);
    }

    public function test_a_scoped_user_with_no_location_gets_zero_rows(): void
    {
        $location = $this->makeLocation();
        $homeless = $this->makeUserAt($location, 'branch_manager');
        $homeless->update(['location_id' => null]);

        $dataset = app(LocationPerformanceReport::class)->build(
            $this->params('2026-08-01', '2026-08-31'),
            $homeless
        );

        $this->assertSame([], $dataset->rows,
            'A scoped user with no assigned location must see nothing — never everything.');
    }

    public function test_movement_outside_the_date_range_is_excluded(): void
    {
        $location = $this->makeLocation('RangeTest');
        $admin    = $this->makeUserAt($location, 'admin');

        $inside  = $this->makeReceiving($location->location_id);
        $inside->update(['receiving_date' => '2026-08-15', 'total_quantity_received' => 5]);

        $outside = $this->makeReceiving($location->location_id);
        $outside->update(['receiving_date' => '2026-07-01', 'total_quantity_received' => 99]);

        $dataset = app(LocationPerformanceReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $location->location_id]),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('location', 'RangeTest');
        $this->assertSame(5, $row['units_in']);
    }

    public function test_columns_declare_the_expected_number_formats(): void
    {
        $location = $this->makeLocation();
        $admin    = $this->makeUserAt($location, 'admin');

        $dataset = app(LocationPerformanceReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $location->location_id]),
            $admin
        );

        $this->assertSame(ReportDataset::FORMAT_INTEGER, $dataset->columnFormats['units_in']);
        $this->assertSame(ReportDataset::FORMAT_INTEGER, $dataset->columnFormats['units_out']);
        $this->assertSame(ReportDataset::FORMAT_INTEGER, $dataset->columnFormats['net_adjustments']);
        $this->assertSame(ReportDataset::FORMAT_MONEY, $dataset->columnFormats['closing_valuation']);
    }
}
