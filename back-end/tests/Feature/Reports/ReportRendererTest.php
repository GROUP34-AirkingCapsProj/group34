<?php

namespace Tests\Feature\Reports;

use App\Reports\ReportDataset;
use App\Reports\Renderers\CsvReportRenderer;
use App\Reports\Renderers\PdfReportRenderer;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

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

    /** A dataset with a declared money column, mirroring InventorySummaryReport's stock_value. */
    private function moneyDataset(): ReportDataset
    {
        return new ReportDataset(
            columns: ['product' => 'Product', 'stock_value' => 'Stock Value'],
            rows: [
                ['product' => 'Split Type 1.5HP', 'stock_value' => 72000.0],
            ],
            columnFormats: ['stock_value' => ReportDataset::FORMAT_MONEY],
        );
    }

    /** pdftotext, used below to prove the PDF actually renders the formatted text. */
    private function pdfText(string $bytes): string
    {
        $binary = (new ExecutableFinder())->find('pdftotext');
        if ($binary === null) {
            $this->markTestSkipped('pdftotext is not installed; cannot inspect rendered PDF text.');
        }

        $path = tempnam(sys_get_temp_dir(), 'report') . '.pdf';
        file_put_contents($path, $bytes);

        $process = new Process([$binary, '-layout', $path, '-']);
        $process->run();
        unlink($path);

        return $process->getOutput();
    }

    /**
     * A cell that starts with =, +, - or @ is a live formula when the file is
     * opened in Excel or Sheets. Report cells are not all trusted: User
     * Activity writes `username_attempt`, which is whatever string was typed
     * at the login form, and product and location names are user-entered too.
     * Neutralised with a leading apostrophe, which spreadsheets strip on
     * display.
     */
    public function test_csv_neutralises_cells_that_would_execute_as_formulas(): void
    {
        $dataset = new ReportDataset(
            columns: ['who' => 'User', 'note' => 'Note'],
            rows: [
                ['who' => '=HYPERLINK("http://evil.test","click")', 'note' => '+1-555-0100'],
                ['who' => '@SUM(A1:A9)', 'note' => "-cmd|' /c calc'!A0"],
            ],
            summary: ['=TOTAL' => '=1+1'],
            notes: ['=NOTE()'],
        );

        $csv = app(CsvReportRenderer::class)->render($dataset, '=Title', ['=Filter' => '=Value']);

        $this->assertStringNotContainsString('"=HYPERLINK', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'@SUM(A1:A9)", $csv);
        $this->assertStringContainsString("'+1-555-0100", $csv);
        $this->assertStringContainsString("'-cmd", $csv);

        // The footer, the summary and the header labels are cells too.
        $this->assertStringContainsString("'=Title", $csv);
        $this->assertStringContainsString("'=Filter", $csv);
        $this->assertStringContainsString("'=Value", $csv);
        $this->assertStringContainsString("'=TOTAL", $csv);
        $this->assertStringContainsString("'=NOTE()", $csv);
    }

    /**
     * The neutralising must not reach the numbers. This file's whole contract
     * is that a numeric column lands unquoted and summable, and a negative
     * figure — a net adjustment, a remaining capacity — starts with '-'.
     */
    public function test_csv_leaves_negative_numbers_summable(): void
    {
        $dataset = new ReportDataset(
            columns: ['location' => 'Location', 'net' => 'Net Adjustments', 'value' => 'Value'],
            rows: [['location' => 'Main', 'net' => -5, 'value' => '-12.50']],
        );

        $csv = app(CsvReportRenderer::class)->render($dataset, 'Location Performance', []);

        $this->assertStringContainsString('-5', $csv);
        $this->assertStringNotContainsString("'-5", $csv);
        $this->assertStringNotContainsString("'-12.50", $csv);
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

    /**
     * The whole point of this change: a numeric column must land in the CSV
     * unquoted and without thousands separators, so Excel/Sheets treat it as
     * a number and pandas.read_csv parses it as one — not as text.
     */
    public function test_csv_writes_the_raw_number_not_the_pdf_display_format(): void
    {
        $csv = app(CsvReportRenderer::class)->render($this->moneyDataset(), 'Inventory Summary', []);
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        // fputcsv quotes "Stock Value" because it contains a space — that is
        // pre-existing, unrelated text-field quoting, not what this test is
        // about. What matters is the number that follows it.
        $this->assertStringContainsString('Stock Value', trim($lines[0]));
        $this->assertStringContainsString(',72000', trim($lines[1]));
        $this->assertStringNotContainsString('72,000', $csv,
            'A thousands separator would make Excel/pandas read this column as text.');
        $this->assertStringNotContainsString('"72000"', $csv,
            'fputcsv only quotes values that need it (commas/quotes) — a bare number must stay unquoted.');
    }

    /** The declared format must still apply for display even though the dataset itself is raw. */
    public function test_pdf_applies_the_declared_money_format_for_display(): void
    {
        $bytes = app(PdfReportRenderer::class)->render($this->moneyDataset(), 'Inventory Summary', []);
        $text  = $this->pdfText($bytes);

        $this->assertStringContainsString('72,000.00', $text);
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

    /**
     * A column's declared format must apply inside sections too, since
     * sections reuse the same row data (the blade renders only the section
     * tables when sections are present, never the flat table alongside them
     * — see document.blade.php's @forelse($sections)/@empty split).
     */
    public function test_pdf_applies_the_declared_format_to_section_rows(): void
    {
        $dataset = new ReportDataset(
            columns: ['product' => 'Product', 'stock_value' => 'Stock Value'],
            rows: [
                ['product' => 'Split Type 1.5HP', 'stock_value' => 72000.0],
            ],
            sections: [
                [
                    'title' => 'By Location',
                    'columns' => ['product' => 'Product', 'stock_value' => 'Stock Value'],
                    'rows' => [['product' => 'Split Type 1.5HP', 'stock_value' => 72000.0]],
                ],
            ],
            columnFormats: ['stock_value' => ReportDataset::FORMAT_MONEY],
        );

        $bytes = app(PdfReportRenderer::class)->render($dataset, 'Inventory Summary', []);
        $text  = $this->pdfText($bytes);

        $this->assertStringContainsString('72,000.00', $text);
    }
}
