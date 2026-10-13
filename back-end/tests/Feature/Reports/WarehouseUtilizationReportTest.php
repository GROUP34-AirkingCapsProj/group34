<?php

namespace Tests\Feature\Reports;

use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\PurchaseOrder;
use App\Models\Receiving;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Transfer;
use App\Reports\Definitions\WarehouseUtilizationReport;
use App\Reports\ReportDataset;
use App\Reports\ReportParameter;
use App\Reports\ReportParameters;

class WarehouseUtilizationReportTest extends ReportTestCase
{
    private function params(string $from, string $to, array $extra = []): ReportParameters
    {
        return new ReportParameters(array_merge([
            'date_from' => $from,
            'date_to'   => $to,
        ], $extra));
    }

    /** A receiving with a PO attached, so on-time/late/unrated can be exercised. */
    private function makeReceivingWithPo(
        int $locationId,
        ?string $expectedDeliveryDate,
        string $receivingDate,
        int $received = 10,
        int $damaged = 0
    ): Receiving {
        $po = PurchaseOrder::create([
            'location_id'             => $locationId,
            'po_number'                => 'PO-' . uniqid(),
            'order_date'               => $receivingDate,
            'expected_delivery_date'   => $expectedDeliveryDate,
        ]);

        return Receiving::create([
            'location_id'             => $locationId,
            'po_id'                   => $po->po_id,
            'receiving_number'        => 'RCV-' . uniqid(),
            'receiving_date'          => $receivingDate,
            'total_quantity_received' => $received,
            'total_quantity_damaged'  => $damaged,
        ]);
    }

    /**
     * Transfer, Issuance and PurchaseOrder all use SoftDeletes, and this
     * report reads them through DB::table(), which does not apply the global
     * scope. Deleted documents inflate throughput; a deleted PO also keeps
     * supplying an expected delivery date, so a receipt that can no longer be
     * judged still lands in the on-time percentage.
     */
    public function test_soft_deleted_transfers_issuances_and_purchase_orders_are_excluded(): void
    {
        $location = $this->makeLocation('SoftDelWH');
        $other    = $this->makeLocation('SoftDelWHOther');
        $admin    = $this->makeUserAt($location, 'admin');
        $product  = $this->makeAppliance();

        Transfer::create([
            'from_location_id'           => $other->location_id,
            'to_location_id'             => $location->location_id,
            'transfer_number'            => 'TRF-WU-IN-' . uniqid(),
            'transfer_date'              => '2026-08-11',
            'total_quantity_transferred' => 12,
            'total_quantity_received'    => 12,
        ])->delete();

        Transfer::create([
            'from_location_id'           => $location->location_id,
            'to_location_id'             => $other->location_id,
            'transfer_number'            => 'TRF-WU-OUT-' . uniqid(),
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
            'quantity_issued' => 6,
        ]);
        $issuance->delete();

        // The receiving survives — only its PO is archived, so the receipt is
        // still counted but can no longer be rated on-time or late.
        $receiving = $this->makeReceivingWithPo($location->location_id, '2026-08-20', '2026-08-15', received: 10);
        PurchaseOrder::whereKey($receiving->po_id)->delete();

        $dataset = app(WarehouseUtilizationReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $location->location_id]),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('location', 'SoftDelWH');
        $this->assertNotNull($row);
        $this->assertSame(0, $row['transfers_in'], 'A deleted transfer must not count as transfers in.');
        $this->assertSame(0, $row['transfers_out'], 'A deleted transfer must not count as transfers out.');
        $this->assertSame(0, $row['goods_out_issuance'], 'A deleted issuance must not count as goods out.');
        $this->assertSame(10, $row['units_received'], 'The surviving receipt still counts.');
        $this->assertSame(10, $row['units_handled'], 'Throughput counts the receipt and nothing deleted.');

        $this->assertSame(0, $row['on_time_count'], 'A deleted PO cannot rate a receipt as on time.');
        $this->assertSame(0, $row['late_count']);
        $this->assertSame(1, $row['unrated_count'], 'With its PO archived the receipt is unrated.');
    }

    public function test_it_declares_date_range_as_required_and_defaults_location_type_to_warehouse(): void
    {
        $definition = app(WarehouseUtilizationReport::class);
        $params = collect($definition->parameters());

        $dateRange = $params->firstWhere('key', 'date_range');
        $this->assertNotNull($dateRange, 'Warehouse Utilization must declare a date_range parameter.');
        $this->assertTrue($dateRange->required, 'date_range must be required.');

        $locationType = $params->firstWhere('key', 'location_type');
        $this->assertNotNull($locationType);
        $this->assertFalse($locationType->required);
        $this->assertSame('warehouse', $locationType->default);
    }

    public function test_occupancy_is_computed_against_capacity(): void
    {
        $location = $this->makeLocation('Cap100');
        $location->update(['capacity' => 100]);
        $admin = $this->makeUserAt($location, 'admin');
        $product = $this->makeAppliance();
        $this->setStock($location->location_id, $product->product_id, 25);

        $dataset = app(WarehouseUtilizationReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $location->location_id]),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('location', 'Cap100');
        $this->assertNotNull($row);
        $this->assertSame(100, $row['capacity']);
        $this->assertSame(25, $row['occupied_units']);
        // Raw number in the dataset (25.0, not "25.0%") — CSV must be able
        // to sort/pivot on it. The PDF renderer is what adds the '%'.
        $this->assertSame(25.0, $row['utilization_pct']);
        $this->assertSame(75, $row['remaining_capacity']);
        $this->assertSame(ReportDataset::FORMAT_PERCENT, $dataset->columnFormats['utilization_pct']);
        $this->assertSame('25.0%', ReportDataset::formatValue($row['utilization_pct'], ReportDataset::FORMAT_PERCENT));
    }

    public function test_null_capacity_renders_not_set_and_is_excluded_from_the_aggregate(): void
    {
        // Uses location_type=branch_office rather than the default warehouse
        // filter to keep this test's aggregate isolated from the pre-seeded
        // warehouse/showroom fixtures that carry their own capacity/stock —
        // see ReportTestCase's note on the shared test DB.
        $noCapacity = \App\Models\Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'NoCapLoc',
            'location_type' => 'branch_office',
        ]);
        $this->assertNull($noCapacity->capacity, 'Fixture must start with a null capacity for this test to mean anything.');
        $withCapacity = \App\Models\Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'WithCapLoc',
            'location_type' => 'branch_office',
            'capacity'      => 100,
        ]);
        $admin = $this->makeUserAt($noCapacity, 'admin');

        $product = $this->makeAppliance();
        $this->setStock($noCapacity->location_id, $product->product_id, 40);
        $this->setStock($withCapacity->location_id, $product->product_id, 30);

        // A receiving with damage, so throughput_ratio has a non-trivial units_handled too.
        $this->makeReceivingWithPo($noCapacity->location_id, '2026-08-20', '2026-08-10', received: 20, damaged: 2);

        $dataset = app(WarehouseUtilizationReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_type' => 'branch_office']),
            $admin
        );

        $noCapRow = collect($dataset->rows)->firstWhere('location', 'NoCapLoc');
        $this->assertNotNull($noCapRow);
        $this->assertSame('Not set', $noCapRow['capacity']);
        $this->assertSame('Not set', $noCapRow['utilization_pct']);
        $this->assertSame('Not set', $noCapRow['remaining_capacity']);
        $this->assertSame('Not set', $noCapRow['throughput_ratio']);
        // Raw counts are still reported even without a capacity to compare against.
        $this->assertSame(40, $noCapRow['occupied_units']);
        $this->assertSame(20, $noCapRow['units_handled']);

        $withCapRow = collect($dataset->rows)->firstWhere('location', 'WithCapLoc');
        $this->assertNotNull($withCapRow);
        $this->assertSame(100, $withCapRow['capacity']);
        $this->assertSame(30.0, $withCapRow['utilization_pct']);

        // Aggregate utilization excludes NoCapLoc entirely: only WithCapLoc's
        // 30/100 counts, so the aggregate must equal WithCapLoc's own figure,
        // not something diluted or inflated by the unmeasurable location.
        $this->assertSame('30.0%', $dataset->summary['Aggregate utilization %']);
        $this->assertSame('1', $dataset->summary['Locations without a capacity']);
    }

    public function test_units_received_and_damaged_and_on_time_vs_late(): void
    {
        $location = $this->makeLocation('ReceivingLoc');
        $admin = $this->makeUserAt($location, 'admin');

        // On time: received on or before expected.
        $this->makeReceivingWithPo($location->location_id, '2026-08-15', '2026-08-10', received: 10, damaged: 1);
        // Late: received after expected.
        $this->makeReceivingWithPo($location->location_id, '2026-08-05', '2026-08-10', received: 20, damaged: 3);

        $dataset = app(WarehouseUtilizationReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $location->location_id]),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('location', 'ReceivingLoc');
        $this->assertNotNull($row);
        $this->assertSame(2, $row['receiving_count']);
        $this->assertSame(2, $row['po_count']);
        $this->assertSame(30, $row['units_received']);
        $this->assertSame(4, $row['units_damaged']);
        $this->assertSame(1, $row['on_time_count']);
        $this->assertSame(1, $row['late_count']);
        $this->assertSame(0, $row['unrated_count']);
        $this->assertSame(50.0, $row['on_time_pct']);
        $this->assertSame(13.3, $row['damage_rate'], '4 damaged / 30 received, rounded to one decimal.');
        $this->assertSame(ReportDataset::FORMAT_PERCENT, $dataset->columnFormats['damage_rate']);
        $this->assertSame(ReportDataset::FORMAT_PERCENT, $dataset->columnFormats['on_time_pct']);
    }

    public function test_a_receipt_with_no_expected_delivery_date_is_excluded_from_on_time_percentage(): void
    {
        $location = $this->makeLocation('UnratedLoc');
        $admin = $this->makeUserAt($location, 'admin');

        // Rated: on time.
        $this->makeReceivingWithPo($location->location_id, '2026-08-15', '2026-08-10', received: 5);
        // Unrated: PO has no expected delivery date.
        $this->makeReceivingWithPo($location->location_id, null, '2026-08-12', received: 5);

        $dataset = app(WarehouseUtilizationReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $location->location_id]),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('location', 'UnratedLoc');
        $this->assertNotNull($row);
        $this->assertSame(1, $row['on_time_count']);
        $this->assertSame(0, $row['late_count']);
        $this->assertSame(1, $row['unrated_count']);
        // Only the rated receipt counts toward the percentage: 1/1 = 100%.
        $this->assertSame(100.0, $row['on_time_pct']);

        $this->assertStringContainsString('could not be judged on-time', implode(' ', $dataset->notes));
    }

    public function test_transfers_in_and_out_are_separated(): void
    {
        $location = $this->makeLocation('TransferLoc');
        $other = $this->makeLocation('OtherLoc');
        $admin = $this->makeUserAt($location, 'admin');

        Transfer::create([
            'from_location_id'           => $other->location_id,
            'to_location_id'             => $location->location_id,
            'transfer_number'            => 'TRF-IN-' . uniqid(),
            'transfer_date'              => '2026-08-11',
            'total_quantity_transferred' => 15,
            'total_quantity_received'    => 12,
        ]);

        Transfer::create([
            'from_location_id'           => $location->location_id,
            'to_location_id'             => $other->location_id,
            'transfer_number'            => 'TRF-OUT-' . uniqid(),
            'transfer_date'              => '2026-08-12',
            'total_quantity_transferred' => 9,
            'total_quantity_received'    => 9,
        ]);

        $dataset = app(WarehouseUtilizationReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $location->location_id]),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('location', 'TransferLoc');
        $this->assertNotNull($row);
        // In uses total_quantity_received (12, not 15 — see LocationPerformanceReport doc-comment).
        $this->assertSame(12, $row['transfers_in']);
        $this->assertSame(9, $row['transfers_out']);
    }

    public function test_goods_out_combines_issuances_and_sales(): void
    {
        $location = $this->makeLocation('DispatchLoc');
        $admin = $this->makeUserAt($location, 'admin');
        $product = $this->makeAppliance();

        $issuance = Issuance::create([
            'location_id'   => $location->location_id,
            'issuance_date' => '2026-08-11',
        ]);
        IssuanceDetail::create([
            'issuance_id'     => $issuance->issuance_id,
            'product_id'      => $product->product_id,
            'quantity_issued' => 6,
        ]);

        $sale = Sale::create([
            'location_id'    => $location->location_id,
            'invoice_number' => 'INV-' . uniqid(),
            'sale_date'      => '2026-08-12',
            'total_amount'   => 400.00,
        ]);
        SaleDetail::create([
            'sales_id'   => $sale->sales_id,
            'product_id' => $product->product_id,
            'quantity'   => 4,
            'unit_price' => 100.00,
            'subtotal'   => 400.00,
        ]);

        $dataset = app(WarehouseUtilizationReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $location->location_id]),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('location', 'DispatchLoc');
        $this->assertNotNull($row);
        $this->assertSame(6, $row['goods_out_issuance']);
        $this->assertSame(4, $row['goods_out_sales']);
        $this->assertSame(10, $row['goods_out_total']);
    }

    public function test_throughput_ratio_is_units_handled_divided_by_capacity(): void
    {
        $location = $this->makeLocation('ThroughputLoc');
        $location->update(['capacity' => 50]);
        $admin = $this->makeUserAt($location, 'admin');
        $other = $this->makeLocation('ThroughputOther');
        $product = $this->makeAppliance();

        // Received: 10.
        $this->makeReceivingWithPo($location->location_id, '2026-08-20', '2026-08-10', received: 10);

        // Transfer in: received 5, transfer out: transferred 5.
        Transfer::create([
            'from_location_id'           => $other->location_id,
            'to_location_id'             => $location->location_id,
            'transfer_number'            => 'TRF-' . uniqid(),
            'transfer_date'              => '2026-08-13',
            'total_quantity_transferred' => 5,
            'total_quantity_received'    => 5,
        ]);
        Transfer::create([
            'from_location_id'           => $location->location_id,
            'to_location_id'             => $other->location_id,
            'transfer_number'            => 'TRF-' . uniqid(),
            'transfer_date'              => '2026-08-14',
            'total_quantity_transferred' => 5,
            'total_quantity_received'    => 5,
        ]);

        // Issuance: 5.
        $issuance = Issuance::create([
            'location_id'   => $location->location_id,
            'issuance_date' => '2026-08-15',
        ]);
        IssuanceDetail::create([
            'issuance_id'     => $issuance->issuance_id,
            'product_id'      => $product->product_id,
            'quantity_issued' => 5,
        ]);

        // Units handled = 10 (received) + 5 (in) + 5 (out) + 5 (issued) = 25.
        // Ratio = 25 / 50 = 0.50.
        $dataset = app(WarehouseUtilizationReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $location->location_id]),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('location', 'ThroughputLoc');
        $this->assertNotNull($row);
        $this->assertSame(25, $row['units_handled']);
        // Raw number in the dataset — the PDF renderer adds display formatting.
        $this->assertSame(0.5, $row['throughput_ratio']);
        $this->assertSame(ReportDataset::FORMAT_DECIMAL, $dataset->columnFormats['throughput_ratio']);
        $this->assertSame('0.50', ReportDataset::formatValue($row['throughput_ratio'], ReportDataset::FORMAT_DECIMAL));
    }

    public function test_count_adjustments_are_excluded_from_throughput(): void
    {
        // Adjustments have no place in this report's throughput at all —
        // there is no adjustments-derived column on the row, and a location
        // with only an adjustment (no receiving/transfer/dispatch) must
        // report zero units handled.
        $location = $this->makeLocation('AdjOnlyLoc');
        $location->update(['capacity' => 100]);
        $admin = $this->makeUserAt($location, 'admin');

        \App\Models\Adjustment::create([
            'location_id'             => $location->location_id,
            'adjustment_number'       => 'ADJ-' . uniqid(),
            'adjustment_date'         => '2026-08-15',
            'total_variance_positive' => 50,
            'total_variance_negative' => 0,
        ]);

        $dataset = app(WarehouseUtilizationReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $location->location_id]),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('location', 'AdjOnlyLoc');
        $this->assertNotNull($row);
        $this->assertSame(0, $row['units_handled'], 'A count correction is not goods moving and must not inflate throughput.');
    }

    public function test_sections_are_populated_and_rows_stay_one_flat_row_per_location(): void
    {
        $location = $this->makeLocation('ShapeLoc');
        $location->update(['capacity' => 100]);
        $admin = $this->makeUserAt($location, 'admin');
        $product = $this->makeAppliance();
        $this->setStock($location->location_id, $product->product_id, 10);

        $dataset = app(WarehouseUtilizationReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $location->location_id]),
            $admin
        );

        $this->assertNotEmpty($dataset->rows, 'rows is the canonical CSV shape and must be populated.');
        foreach ($dataset->rows as $row) {
            $this->assertArrayHasKey('capacity', $row);
            $this->assertArrayHasKey('units_handled', $row);
        }

        $this->assertNotEmpty($dataset->sections, 'sections is the PDF-only regrouping and must be populated.');
        $this->assertCount(5, $dataset->sections);
        $titles = collect($dataset->sections)->pluck('title')->all();
        $this->assertContains('Space Utilization (as of report generation)', $titles);
        $this->assertContains('Goods Received Against Purchase Orders (period)', $titles);
        $this->assertContains('Transfers (period)', $titles);
        $this->assertContains('Goods Out (period)', $titles);
        $this->assertContains('Throughput (period vs. capacity)', $titles);

        $spaceSection = collect($dataset->sections)->firstWhere('title', 'Space Utilization (as of report generation)');
        $this->assertCount(1, $spaceSection['rows']);
        $this->assertArrayHasKey('capacity', $spaceSection['columns']);
    }

    public function test_notes_state_both_the_snapshot_and_period_framings(): void
    {
        $location = $this->makeLocation();
        $admin = $this->makeUserAt($location, 'admin');

        $dataset = app(WarehouseUtilizationReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $location->location_id]),
            $admin
        );

        $joined = implode(' ', $dataset->notes);
        $this->assertStringContainsString('generation time', $joined);
        $this->assertStringContainsString('selected period', $joined);
    }

    public function test_a_scoped_user_sees_only_their_own_location(): void
    {
        $mine = $this->makeLocation('MineWH');
        $theirs = $this->makeLocation('TheirsWH');
        $scoped = $this->makeUserAt($mine, 'warehouse_personnel');
        $this->grantPermission($scoped, 'report_viewing', 'read');

        $dataset = app(WarehouseUtilizationReport::class)->build(
            $this->params('2026-08-01', '2026-08-31'),
            $scoped
        );

        $names = collect($dataset->rows)->pluck('location')->all();
        $this->assertContains('MineWH', $names);
        $this->assertNotContains('TheirsWH', $names);
    }

    public function test_a_scoped_user_with_no_location_gets_zero_rows(): void
    {
        $location = $this->makeLocation();
        $homeless = $this->makeUserAt($location, 'warehouse_personnel');
        $homeless->update(['location_id' => null]);

        $dataset = app(WarehouseUtilizationReport::class)->build(
            $this->params('2026-08-01', '2026-08-31'),
            $homeless
        );

        $this->assertSame([], $dataset->rows,
            'A scoped user with no assigned location must see nothing — never everything.');
        $this->assertSame([], $dataset->sections,
            'sections cannot stand in for empty rows.');
    }

    public function test_percent_and_decimal_columns_are_raw_in_the_dataset_and_formatted_in_pdf_and_csv(): void
    {
        $location = $this->makeLocation('FormatCheckLoc');
        $location->update(['capacity' => 200]);
        $admin = $this->makeUserAt($location, 'admin');
        $product = $this->makeAppliance();
        $this->setStock($location->location_id, $product->product_id, 50);

        $dataset = app(WarehouseUtilizationReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $location->location_id]),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('location', 'FormatCheckLoc');
        $this->assertNotNull($row);

        // Dataset shape: raw numbers, not pre-formatted strings.
        $this->assertIsFloat($row['utilization_pct']);
        $this->assertSame(25.0, $row['utilization_pct']);

        // CSV shape: the raw number survives untouched (CsvReportRenderer
        // ignores columnFormats by design), so a spreadsheet can sort/pivot
        // on utilization and throughput — parsed back out rather than
        // substring-matched, so this can't be fooled by a coincidental
        // adjacent digit sequence.
        $csv = app(\App\Reports\Renderers\CsvReportRenderer::class)
            ->render($dataset, 'Warehouse Utilization', []);
        $lines = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $header = $lines[0];
        $dataLine = $lines[1];
        $utilizationIndex = array_search('Utilization %', $header, true);
        $this->assertNotFalse($utilizationIndex);
        $this->assertSame('25', $dataLine[$utilizationIndex],
            'CSV must write the raw percent number, not "25.0%".');

        // PDF shape: the same raw number is display-formatted with FORMAT_PERCENT/FORMAT_DECIMAL.
        $pdf = app(\App\Reports\Renderers\PdfReportRenderer::class)
            ->render($dataset, 'Warehouse Utilization', []);
        $this->assertIsString($pdf);
        $this->assertNotEmpty($pdf);
    }

    public function test_not_set_sentinel_survives_the_new_percent_and_decimal_formats(): void
    {
        // Direct regression guard on ReportDataset::formatValue() for the
        // exact formats this report declares for utilization/damage
        // rate/on-time/throughput — a (float) coercion here would silently
        // turn 'Not set' into "0.0%"/"0.00", exactly the bug this report
        // exists to prevent.
        $this->assertSame('Not set', ReportDataset::formatValue('Not set', ReportDataset::FORMAT_PERCENT));
        $this->assertSame('Not set', ReportDataset::formatValue('Not set', ReportDataset::FORMAT_DECIMAL));
    }

    public function test_not_set_survives_end_to_end_through_the_real_pdf_renderer(): void
    {
        $noCapacity = \App\Models\Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'PdfNotSetLoc',
            'location_type' => 'branch_office',
        ]);
        $admin = $this->makeUserAt($noCapacity, 'admin');

        $dataset = app(WarehouseUtilizationReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', [
                'location_id'   => $noCapacity->location_id,
                'location_type' => 'branch_office',
            ]),
            $admin
        );

        $row = collect($dataset->rows)->firstWhere('location', 'PdfNotSetLoc');
        $this->assertSame('Not set', $row['utilization_pct']);
        $this->assertSame('Not set', $row['throughput_ratio']);

        $pdfPath = sys_get_temp_dir() . '/wu_not_set_check_' . uniqid() . '.pdf';
        file_put_contents(
            $pdfPath,
            app(\App\Reports\Renderers\PdfReportRenderer::class)->render($dataset, 'Warehouse Utilization', [])
        );

        // A regression that turns 'Not set' into "0.0%" would still leave the
        // PDF non-empty, so this needs the literal text to appear.
        $text = $this->pdfText($pdfPath);

        $this->assertStringContainsString('Not set', $text);
        @unlink($pdfPath);
    }

    /**
     * The text of a rendered PDF.
     *
     * Uses pdftotext when it is on PATH, and otherwise reads the page content
     * stream directly rather than skipping — this assertion is the only thing
     * standing between 'Not set' and a silent "0.0%", and it should hold on a
     * machine without poppler installed just as well as on one with it.
     *
     * A raw-byte scan cannot do that job: dompdf flate-compresses the content
     * stream, and even inflated it writes strings as UTF-16BE, so an ASCII
     * needle appears nowhere in the file at either level.
     */
    private function pdfText(string $pdfPath): string
    {
        $pdftotext = trim((string) shell_exec('where pdftotext 2>NUL || which pdftotext 2>/dev/null'));
        if ($pdftotext !== '') {
            $txtPath = $pdfPath . '.txt';
            shell_exec('pdftotext ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($txtPath));
            if (file_exists($txtPath)) {
                $text = (string) file_get_contents($txtPath);
                @unlink($txtPath);

                return $text;
            }
        }

        $pdf  = (string) file_get_contents($pdfPath);
        $text = '';

        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

        foreach ($streams[1] as $stream) {
            $content = @gzuncompress($stream);

            // Embedded font programs inflate too; only the page content stream
            // carries the show-text operators.
            if ($content === false || ! str_contains($content, 'TJ')) {
                continue;
            }

            // Each drawn string is a parenthesised literal, with any literal
            // parenthesis or backslash inside it backslash-escaped.
            preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/s', $content, $literals);

            foreach ($literals[1] as $literal) {
                $unescaped = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $literal);
                $text .= mb_convert_encoding($unescaped, 'UTF-8', 'UTF-16BE') . "\n";
            }
        }

        return $text;
    }

    public function test_location_type_defaults_to_warehouse(): void
    {
        $warehouse = $this->makeLocation('DefaultTypeWH');
        $admin = $this->makeUserAt($warehouse, 'admin');

        $showroom = \App\Models\Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'AShowroom',
            'location_type' => 'showroom',
        ]);

        $dataset = app(WarehouseUtilizationReport::class)->build(
            $this->params('2026-08-01', '2026-08-31'),
            $admin
        );

        $names = collect($dataset->rows)->pluck('location')->all();
        $this->assertContains('DefaultTypeWH', $names);
        $this->assertNotContains('AShowroom', $names, 'location_type must default to warehouse when not supplied.');
    }
}
