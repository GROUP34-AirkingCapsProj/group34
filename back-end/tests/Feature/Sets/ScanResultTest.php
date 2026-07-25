<?php

namespace Tests\Feature\Sets;

use App\Support\ScanResult;
use Tests\TestCase;

class ScanResultTest extends TestCase
{
    public function test_fifo_violation_is_overridable_and_not_accepted(): void
    {
        $result = new ScanResult(ScanResult::FIFO_VIOLATION, 'Wrong batch.');

        $this->assertTrue($result->overridable());
        $this->assertFalse($result->accepted());
    }

    public function test_accepted_is_accepted_and_not_overridable(): void
    {
        $result = new ScanResult(ScanResult::ACCEPTED, 'OK.');

        $this->assertTrue($result->accepted());
        $this->assertFalse($result->overridable());
    }

    public function test_to_array_contains_expected_keys_and_booleans(): void
    {
        $result = new ScanResult(
            ScanResult::FIFO_VIOLATION,
            'Wrong batch.',
            productId: 1,
            productName: 'Test Product',
            serialId: 2,
            serialNumber: 'SN-1',
            batchId: 3,
            zoneLabel: 'Zone 1',
            expectedBatchId: 4,
            expectedZoneLabel: 'Zone 2',
            expectedReceivedAt: '2026-01-01 00:00:00',
        );

        $array = $result->toArray();

        $this->assertSame(ScanResult::FIFO_VIOLATION, $array['status']);
        $this->assertFalse($array['accepted']);
        $this->assertTrue($array['overridable']);
        $this->assertSame('Wrong batch.', $array['message']);
        $this->assertSame(1, $array['product_id']);
        $this->assertSame('Test Product', $array['product_name']);
        $this->assertSame(2, $array['serial_id']);
        $this->assertSame('SN-1', $array['serial_number']);
        $this->assertSame(3, $array['batch_id']);
        $this->assertSame('Zone 1', $array['zone_label']);
        $this->assertSame(4, $array['expected_batch_id']);
        $this->assertSame('Zone 2', $array['expected_zone_label']);
        $this->assertSame('2026-01-01 00:00:00', $array['expected_received_at']);
    }
}
