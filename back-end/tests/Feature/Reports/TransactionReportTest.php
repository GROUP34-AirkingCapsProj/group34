<?php

namespace Tests\Feature\Reports;

use App\Models\Adjustment;
use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Transfer;
use App\Reports\Definitions\TransactionReport;
use App\Reports\ReportParameter;
use App\Reports\ReportParameters;

class TransactionReportTest extends ReportTestCase
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
        $dateRange = collect(app(TransactionReport::class)->parameters())
            ->firstWhere('type', ReportParameter::TYPE_DATE_RANGE);

        $this->assertNotNull($dateRange, 'Transaction Report must declare a date_range parameter.');
        $this->assertTrue($dateRange->required, 'date_range must be required.');
    }

    public function test_each_of_the_five_sources_appears_with_correct_units(): void
    {
        $location = $this->makeLocation('Main');
        $other    = $this->makeLocation('Other');
        $admin    = $this->makeUserAt($location, 'admin');
        $product  = $this->makeAppliance();

        // Receiving
        $receiving = $this->makeReceiving($location->location_id);
        $receiving->update(['total_quantity_received' => 12, 'receiving_date' => '2026-08-10']);

        // Issuance
        $issuance = Issuance::create([
            'location_id'   => $location->location_id,
            'issuance_date' => '2026-08-11',
        ]);
        IssuanceDetail::create([
            'issuance_id'     => $issuance->issuance_id,
            'product_id'      => $product->product_id,
            'quantity_issued' => 7,
        ]);

        // Transfer
        $transfer = Transfer::create([
            'from_location_id'           => $location->location_id,
            'to_location_id'             => $other->location_id,
            'transfer_number'            => 'TRF-' . uniqid(),
            'transfer_date'              => '2026-08-12',
            'total_quantity_transferred' => 9,
        ]);

        // Adjustment
        $adjustment = Adjustment::create([
            'location_id'              => $location->location_id,
            'adjustment_number'        => 'ADJ-' . uniqid(),
            'adjustment_date'          => '2026-08-13',
            'total_variance_positive'  => 4,
            'total_variance_negative'  => 1,
        ]);

        // Sale
        $sale = Sale::create([
            'location_id'    => $location->location_id,
            'invoice_number' => 'INV-' . uniqid(),
            'sale_date'      => '2026-08-14',
            'total_amount'   => 500.00,
        ]);
        SaleDetail::create([
            'sales_id'   => $sale->sales_id,
            'product_id' => $product->product_id,
            'quantity'   => 3,
            'unit_price' => 166.67,
            'subtotal'   => 500.00,
        ]);

        // Restricted to $location's id (not just the date range): the shared
        // test database carries pre-loaded seed data (other locations, other
        // documents) that would otherwise leak into these assertions. The
        // admin is centralized so this location_id is an ordinary optional
        // filter here, not a scoping mechanism.
        $dataset = app(TransactionReport::class)->build(
            $this->params('2026-08-01', '2026-08-31', ['location_id' => $location->location_id]),
            $admin
        );

        $byType = collect($dataset->rows)->keyBy('type');

        $this->assertSame(12, $byType['Receiving']['units'] ?? null);
        $this->assertSame($receiving->receiving_number, $byType['Receiving']['reference'] ?? null);

        $this->assertSame(7, $byType['Issuance']['units'] ?? null);
        $this->assertSame('ISS-' . $issuance->issuance_id, $byType['Issuance']['reference'] ?? null);

        $this->assertSame(9, $byType['Transfer']['units'] ?? null);
        $this->assertSame('Main → Other', $byType['Transfer']['location'] ?? null);

        $this->assertSame(3, $byType['Adjustment']['units'] ?? null, '4 positive minus 1 negative.');

        $this->assertSame(3, $byType['Sale']['units'] ?? null);
        $this->assertSame(500.0, $byType['Sale']['value'] ?? null);

        $this->assertCount(5, $dataset->rows);
    }

    public function test_only_documents_inside_the_date_range_are_returned(): void
    {
        $location = $this->makeLocation();
        $admin    = $this->makeUserAt($location, 'admin');

        $inside  = $this->makeReceiving($location->location_id);
        $inside->update(['receiving_date' => '2026-08-15']);

        $outside = $this->makeReceiving($location->location_id);
        $outside->update(['receiving_date' => '2026-07-01']);

        $dataset = app(TransactionReport::class)->build(
            $this->params('2026-08-01', '2026-08-31'),
            $admin
        );

        $refs = collect($dataset->rows)->pluck('reference')->all();

        $this->assertContains($inside->receiving_number, $refs);
        $this->assertNotContains($outside->receiving_number, $refs);
    }

    public function test_a_single_day_range_includes_that_day(): void
    {
        $location = $this->makeLocation();
        $admin    = $this->makeUserAt($location, 'admin');

        $receiving = $this->makeReceiving($location->location_id);
        $receiving->update(['receiving_date' => '2026-08-15']);

        $dataset = app(TransactionReport::class)->build(
            $this->params('2026-08-15', '2026-08-15'),
            $admin
        );

        $refs = collect($dataset->rows)->pluck('reference')->all();
        $this->assertContains($receiving->receiving_number, $refs,
            'A same-day range must include documents dated on that day, even though the underlying column is a DATE, not a datetime.');
    }

    public function test_a_scoped_user_sees_only_their_own_location(): void
    {
        $mine   = $this->makeLocation('Mine');
        $theirs = $this->makeLocation('Theirs');
        $scoped = $this->makeUserAt($mine, 'warehouse_personnel');
        $this->grantPermission($scoped, 'report_viewing', 'read');

        $mineReceiving = $this->makeReceiving($mine->location_id);
        $mineReceiving->update(['receiving_date' => '2026-08-10']);

        $theirsReceiving = $this->makeReceiving($theirs->location_id);
        $theirsReceiving->update(['receiving_date' => '2026-08-10']);

        $dataset = app(TransactionReport::class)->build(
            $this->params('2026-08-01', '2026-08-31'),
            $scoped
        );

        $refs = collect($dataset->rows)->pluck('reference')->all();

        $this->assertContains($mineReceiving->receiving_number, $refs);
        $this->assertNotContains($theirsReceiving->receiving_number, $refs);
    }

    public function test_a_scoped_user_sees_a_transfer_where_either_end_is_their_location(): void
    {
        $mine   = $this->makeLocation('Mine');
        $theirs = $this->makeLocation('Theirs');
        $elsewhere = $this->makeLocation('Elsewhere');
        $scoped = $this->makeUserAt($mine, 'warehouse_personnel');

        $inbound = Transfer::create([
            'from_location_id'           => $theirs->location_id,
            'to_location_id'             => $mine->location_id,
            'transfer_number'            => 'TRF-IN-' . uniqid(),
            'transfer_date'              => '2026-08-10',
            'total_quantity_transferred' => 5,
        ]);

        $outbound = Transfer::create([
            'from_location_id'           => $mine->location_id,
            'to_location_id'             => $theirs->location_id,
            'transfer_number'            => 'TRF-OUT-' . uniqid(),
            'transfer_date'              => '2026-08-11',
            'total_quantity_transferred' => 6,
        ]);

        $unrelated = Transfer::create([
            'from_location_id'           => $theirs->location_id,
            'to_location_id'             => $elsewhere->location_id,
            'transfer_number'            => 'TRF-NA-' . uniqid(),
            'transfer_date'              => '2026-08-12',
            'total_quantity_transferred' => 7,
        ]);

        $dataset = app(TransactionReport::class)->build(
            $this->params('2026-08-01', '2026-08-31'),
            $scoped
        );

        $refs = collect($dataset->rows)->pluck('reference')->all();

        $this->assertContains($inbound->transfer_number, $refs, 'A transfer INTO the user\'s location must be visible.');
        $this->assertContains($outbound->transfer_number, $refs, 'A transfer OUT OF the user\'s location must be visible.');
        $this->assertNotContains($unrelated->transfer_number, $refs, 'A transfer touching neither end of the user\'s location must not be visible.');
    }

    /**
     * Seeds one document of EACH of the five sources so this asserts the
     * guarantee for all of them, not just Receiving with the rest inferred
     * by code-tracing. If a future refactor of one source's query drops its
     * forUser() call, this must fail — not just the code review that caught
     * it this time.
     */
    public function test_a_scoped_user_with_no_location_gets_zero_rows(): void
    {
        $location = $this->makeLocation();
        $other    = $this->makeLocation();
        $product  = $this->makeAppliance();

        $receiving = $this->makeReceiving($location->location_id);
        $receiving->update(['receiving_date' => '2026-08-10']);

        $issuance = Issuance::create([
            'location_id'   => $location->location_id,
            'issuance_date' => '2026-08-11',
        ]);
        IssuanceDetail::create([
            'issuance_id'     => $issuance->issuance_id,
            'product_id'      => $product->product_id,
            'quantity_issued' => 5,
        ]);

        Transfer::create([
            'from_location_id'           => $location->location_id,
            'to_location_id'             => $other->location_id,
            'transfer_number'            => 'TRF-' . uniqid(),
            'transfer_date'              => '2026-08-12',
            'total_quantity_transferred' => 4,
        ]);

        Adjustment::create([
            'location_id'             => $location->location_id,
            'adjustment_number'       => 'ADJ-' . uniqid(),
            'adjustment_date'         => '2026-08-13',
            'total_variance_positive' => 2,
            'total_variance_negative' => 0,
        ]);

        $sale = Sale::create([
            'location_id'    => $location->location_id,
            'invoice_number' => 'INV-' . uniqid(),
            'sale_date'      => '2026-08-14',
            'total_amount'   => 100.00,
        ]);
        SaleDetail::create([
            'sales_id'   => $sale->sales_id,
            'product_id' => $product->product_id,
            'quantity'   => 1,
            'unit_price' => 100.00,
            'subtotal'   => 100.00,
        ]);

        $homeless = $this->makeUserAt($location, 'warehouse_personnel');
        $homeless->update(['location_id' => null]);

        $dataset = app(TransactionReport::class)->build(
            $this->params('2026-08-01', '2026-08-31'),
            $homeless
        );

        $this->assertSame([], $dataset->rows,
            'A scoped user with no assigned location must see nothing — never everything — across all five sources.');
    }
}
