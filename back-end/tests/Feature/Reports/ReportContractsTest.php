<?php

namespace Tests\Feature\Reports;

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
}
