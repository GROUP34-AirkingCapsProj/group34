<?php

namespace Tests\Feature\Reports;

use App\Reports\ReportDataset;
use App\Reports\Renderers\CsvReportRenderer;
use App\Reports\Renderers\PdfReportRenderer;

class ReportRendererTest extends ReportTestCase
{
    private function dataset(): ReportDataset
    {
        return new ReportDataset(
            columns: ['product' => 'Product', 'qty' => 'Qty'],
            rows: [
                ['product' => 'Split Type 1.5HP', 'qty' => 4],
                ['product' => 'Copper Tube, 1/4"', 'qty' => 12],
            ],
            summary: ['Total units' => '16'],
            notes: ['Occupancy is as of generation time.'],
        );
    }

    public function test_csv_writes_a_header_row_then_the_data(): void
    {
        $csv = app(CsvReportRenderer::class)->render($this->dataset(), 'Inventory Summary', []);
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertSame('Product,Qty', trim($lines[0]));
        $this->assertStringContainsString('Split Type 1.5HP', $lines[1]);
    }

    /** A comma or quote in a product name must not shift every later column. */
    public function test_csv_quotes_values_containing_commas(): void
    {
        $csv = app(CsvReportRenderer::class)->render($this->dataset(), 'Inventory Summary', []);

        $this->assertStringContainsString('"Copper Tube, 1/4"""', $csv);
    }

    /** Title/meta are footer context, not leading rows, so they must not appear before the header. */
    public function test_csv_puts_title_and_meta_after_the_data_not_before_it(): void
    {
        $csv = app(CsvReportRenderer::class)->render($this->dataset(), 'Inventory Summary', [
            'Location' => 'CDO Warehouse A',
        ]);
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertSame('Product,Qty', trim($lines[0]));
        $this->assertStringContainsString('Inventory Summary', $csv);
        $this->assertStringContainsString('CDO Warehouse A', $csv);
    }

    public function test_pdf_renders_a_document_containing_the_rows(): void
    {
        $bytes = app(PdfReportRenderer::class)->render($this->dataset(), 'Inventory Summary', [
            'Location' => 'CDO Warehouse A',
        ]);

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThan(1000, strlen($bytes));
    }

    public function test_pdf_renders_sections_instead_of_the_flat_table_when_present(): void
    {
        $dataset = new ReportDataset(
            columns: ['product' => 'Product', 'qty' => 'Qty'],
            rows: [
                ['product' => 'Split Type 1.5HP', 'qty' => 4],
            ],
            sections: [
                [
                    'title' => 'By Location',
                    'columns' => ['product' => 'Product'],
                    'rows' => [['product' => 'Split Type 1.5HP']],
                ],
            ],
        );

        $bytes = app(PdfReportRenderer::class)->render($dataset, 'Warehouse Utilization', []);

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThan(1000, strlen($bytes));
    }
}
