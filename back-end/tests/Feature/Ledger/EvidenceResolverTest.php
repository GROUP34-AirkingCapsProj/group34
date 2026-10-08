<?php

namespace Tests\Feature\Ledger;

use App\Models\AuditTrail;
use App\Models\InventoryLedgerEntry;
use App\Services\Ledger\EvidenceResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The drill-down that the whole feature exists for: from a figure, to the rows
 * behind it, to the document behind those, to who changed what and when.
 */
class EvidenceResolverTest extends TestCase
{
    use DatabaseTransactions;

    private function entry(array $overrides = []): InventoryLedgerEntry
    {
        return InventoryLedgerEntry::create(array_merge([
            'occurred_at'      => '2026-09-10 09:00:00',
            'posted_at'        => '2026-09-10 09:00:00',
            'direction'        => 'out',
            'movement_type'    => 'issuance',
            'lens'             => 'inventory',
            'product_id'       => 550111,
            'batch_id'         => 660111,
            'quantity'         => 2,
            'unit_cost'        => 50.00,
            'extended_value'   => 100.00,
            'cost_basis'       => 'actual',
            'from_location_id' => 1,
            'source_type'      => 'issuances',
            'source_id'        => 424242,
            'source_line_id'   => 1,
            'correlation_id'   => 'issuances:424242',
            'posting_key'      => 'e:' . uniqid('', true),
        ], $overrides));
    }

    private function svc(): EvidenceResolver
    {
        return app(EvidenceResolver::class);
    }

    public function test_it_returns_every_leg_of_one_event_with_totals(): void
    {
        $this->entry();
        $this->entry(['source_line_id' => 2, 'quantity' => 3, 'extended_value' => 150.00]);

        $bundle = $this->svc()->forCorrelation('issuances:424242');

        $this->assertSame('issuances:424242', $bundle['correlation_id']);
        $this->assertCount(2, $bundle['legs']);
        $this->assertSame(5, array_sum(array_column($bundle['legs'], 'quantity')));
        $this->assertSame(250.0, $bundle['totals']['value_out']);
    }

    public function test_it_attaches_the_change_history_recorded_against_the_source_record(): void
    {
        $this->entry();

        AuditTrail::create([
            'user_id'    => null,
            'action'     => 'update',
            'table_name' => 'issuances',
            'record_id'  => 424242,
            'old_values' => ['status_id' => 'Pending'],
            'new_values' => ['status_id' => 'Completed'],
        ]);

        $bundle = $this->svc()->forCorrelation('issuances:424242');

        $this->assertNotEmpty($bundle['history']);
        $this->assertSame('Completed', $bundle['history'][0]['label']);
    }

    public function test_a_missing_source_document_is_reported_rather_than_hiding_the_movement(): void
    {
        // source_id 424242 does not exist in issuances. The ledger row outlives the
        // document deliberately — the movement still happened.
        $this->entry();

        $bundle = $this->svc()->forCorrelation('issuances:424242');

        $this->assertTrue($bundle['source']['missing']);
        $this->assertSame('issuances', $bundle['source']['type']);
        $this->assertCount(1, $bundle['legs'], 'The evidence survives the document.');
    }

    public function test_an_unknown_correlation_id_returns_an_empty_bundle_rather_than_throwing(): void
    {
        $bundle = $this->svc()->forCorrelation('issuances:999999999');

        $this->assertSame([], $bundle['legs']);
        $this->assertSame([], $bundle['history']);
    }

    public function test_a_serial_lifecycle_lists_every_movement_that_touched_it_oldest_first(): void
    {
        $sn = 'SN-LIFE-' . uniqid();

        $this->entry([
            'direction' => 'in', 'movement_type' => 'receiving', 'serial_number' => $sn,
            'occurred_at' => '2026-01-05 09:00:00', 'to_location_id' => 1, 'from_location_id' => null,
            'source_type' => 'receivings', 'source_id' => 11, 'correlation_id' => 'receivings:11',
        ]);
        $this->entry(['serial_number' => $sn, 'occurred_at' => '2026-06-05 09:00:00']);

        $life = $this->svc()->lifecycleForSerial($sn);

        $this->assertCount(2, $life);
        $this->assertSame('receiving', $life[0]['movement_type']);
        $this->assertSame('issuance', $life[1]['movement_type']);
    }

    public function test_a_batch_lifecycle_lists_its_movements(): void
    {
        $this->entry(['batch_id' => 900111]);

        $life = $this->svc()->lifecycleForBatch(900111);

        $this->assertCount(1, $life);
        $this->assertSame(900111, (int) $life[0]['batch_id']);
    }
}
