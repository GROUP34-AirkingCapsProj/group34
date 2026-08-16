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
}
