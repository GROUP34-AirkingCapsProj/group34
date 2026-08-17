<?php

namespace Tests\Feature\Reports;

use App\Reports\ReportDataset;
use App\Reports\ReportParameter;
use App\Reports\ReportParameters;

class ReportContractsTest extends ReportTestCase
{
    public function test_a_date_range_consumes_two_request_fields(): void
    {
        $param = new ReportParameter('date_range', ReportParameter::TYPE_DATE_RANGE, 'Period', true);

        $this->assertSame(['date_from', 'date_to'], $param->requestKeys());
        $this->assertArrayHasKey('date_to', $param->rules());
        $this->assertStringContainsString('required', $param->rules()['date_from']);
    }

    public function test_a_single_day_range_covers_that_whole_day(): void
    {
        $params = new ReportParameters(['date_from' => '2026-08-17', 'date_to' => '2026-08-17']);

        $this->assertSame('2026-08-17 00:00:00', $params->dateFrom()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-17 23:59:59', $params->dateTo()->format('Y-m-d H:i:s'));
    }

    public function test_optional_parameters_are_nullable(): void
    {
        $param = new ReportParameter('location_id', ReportParameter::TYPE_LOCATION, 'Location');

        $this->assertStringContainsString('nullable', $param->rules()['location_id']);
    }

    public function test_an_unknown_parameter_type_is_a_programming_error(): void
    {
        $param = new ReportParameter('bogus', 'not-a-real-type', 'Bogus');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown report parameter type: not-a-real-type');

        $param->rules();
    }

    public function test_a_select_with_no_options_does_not_reject_every_value(): void
    {
        $param = new ReportParameter('status', ReportParameter::TYPE_SELECT, 'Status');

        $validator = \Illuminate\Support\Facades\Validator::make(['status' => 'anything'], $param->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_sections_cannot_stand_in_for_empty_rows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sections are a presentation view of rows');

        new ReportDataset(
            columns: ['location' => 'Location'],
            rows: [],
            sections: [['title' => 'By Location', 'columns' => ['location' => 'Location'], 'rows' => []]],
        );
    }

    public function test_a_dataset_with_no_rows_is_empty(): void
    {
        $dataset = new ReportDataset(columns: ['location' => 'Location'], rows: []);

        $this->assertTrue($dataset->isEmpty());
    }

    public function test_column_formats_default_to_empty_so_unformatted_columns_keep_working(): void
    {
        $dataset = new ReportDataset(columns: ['location' => 'Location'], rows: [['location' => 'CDO']]);

        $this->assertSame([], $dataset->columnFormats);
    }

    public function test_money_format_adds_thousands_separator_and_two_decimals(): void
    {
        $this->assertSame('72,000.00', ReportDataset::formatValue(72000, ReportDataset::FORMAT_MONEY));
        $this->assertSame('72,000.00', ReportDataset::formatValue(72000.0, ReportDataset::FORMAT_MONEY));
    }

    public function test_integer_format_adds_thousands_separator_with_no_decimals(): void
    {
        $this->assertSame('1,234', ReportDataset::formatValue(1234, ReportDataset::FORMAT_INTEGER));
    }

    /**
     * Added for Task 10's Warehouse Utilization report: utilization %,
     * damage rate, and on-time % need a display format that appends '%'
     * without losing the raw number CSV needs to sort/pivot on.
     */
    public function test_percent_format_adds_one_decimal_and_a_trailing_percent_sign(): void
    {
        $this->assertSame('25.0%', ReportDataset::formatValue(25, ReportDataset::FORMAT_PERCENT));
        $this->assertSame('25.0%', ReportDataset::formatValue(25.0, ReportDataset::FORMAT_PERCENT));
        $this->assertSame('13.3%', ReportDataset::formatValue(13.333, ReportDataset::FORMAT_PERCENT));
    }

    /**
     * Added alongside FORMAT_PERCENT for the throughput ratio (units handled
     * per unit of capacity). Two decimal places, thousands separator, no
     * unit suffix — a generic reusable "decimal" shape rather than a
     * one-off "ratio with a x suffix" format, per the closed-set rule.
     */
    public function test_decimal_format_adds_two_decimals_and_a_thousands_separator(): void
    {
        $this->assertSame('0.50', ReportDataset::formatValue(0.5, ReportDataset::FORMAT_DECIMAL));
        $this->assertSame('1,234.50', ReportDataset::formatValue(1234.5, ReportDataset::FORMAT_DECIMAL));
    }

    /** The 'Not set' sentinel must survive the two new formats exactly as it does money/integer. */
    public function test_a_non_numeric_sentinel_survives_percent_and_decimal_formatting(): void
    {
        $this->assertSame('Not set', ReportDataset::formatValue('Not set', ReportDataset::FORMAT_PERCENT));
        $this->assertSame('Not set', ReportDataset::formatValue('Not set', ReportDataset::FORMAT_DECIMAL));
    }

    /**
     * Warehouse Utilization puts the literal 'Not set' in its capacity and
     * utilization columns for a location nobody has configured, precisely so
     * it never reads as a misleading 0 or 0%. A (float) coercion would undo
     * that and reintroduce the bug the sentinel exists to prevent.
     */
    public function test_a_non_numeric_sentinel_survives_formatting(): void
    {
        $this->assertSame('Not set', ReportDataset::formatValue('Not set', ReportDataset::FORMAT_INTEGER));
        $this->assertSame('Not set', ReportDataset::formatValue('Not set', ReportDataset::FORMAT_MONEY));
        $this->assertSame('—', ReportDataset::formatValue('—', ReportDataset::FORMAT_MONEY));
    }

    public function test_a_null_renders_as_a_dash_rather_than_zero(): void
    {
        $this->assertSame('—', ReportDataset::formatValue(null, ReportDataset::FORMAT_MONEY));
        $this->assertSame('—', ReportDataset::formatValue(null, ReportDataset::FORMAT_INTEGER));
    }

    /** Numeric strings are still numbers — a decimal:2 cast returns one. */
    public function test_a_numeric_string_still_formats(): void
    {
        $this->assertSame('24,000.00', ReportDataset::formatValue('24000.00', ReportDataset::FORMAT_MONEY));
    }
}
