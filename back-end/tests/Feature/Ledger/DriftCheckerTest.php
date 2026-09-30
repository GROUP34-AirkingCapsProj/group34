<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryLedgerEntry;
use App\Models\LedgerDriftFinding;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\ReceivingDetail;
use App\Services\Ledger\DriftChecker;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The drift check is what stops a forgotten write path becoming silent corruption.
 * Its whole value is that a gap becomes a finding with an owner, so these tests care
 * most that a gap is noticed and that repeated runs do not bury it in duplicates.
 */
class DriftCheckerTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0: Receiving, 1: ReceivingDetail} */
    private function receivingWithLines(int $qty): array
    {
        $location = Location::create([
            'location_code' => 'L-' . uniqid(), 'location_name' => 'WH', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'M-' . uniqid(), 'product_name' => 'Widget',
            'product_type' => 'installation_material', 'cost_price' => 10.00,
        ]);
        $receiving = Receiving::create([
            'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => '2026-09-10',
            'total_quantity_received' => $qty, 'total_quantity_damaged' => 0,
            'location_id' => $location->location_id,
        ]);
        $detail = ReceivingDetail::create([
            'receiving_id' => $receiving->receiving_id, 'product_id' => $product->product_id,
            'quantity_amount' => $qty, 'prod_price' => 10.00, 'condition' => 'Good',
            'entry_mode' => 'counted',
        ]);

        return [$receiving, $detail];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function window(): array
    {
        return [Carbon::parse('2026-09-01')->startOfDay(), Carbon::parse('2026-09-30')->endOfDay()];
    }

    private function openReceivingFindings()
    {
        return LedgerDriftFinding::where('check_type', 'source_vs_ledger')
            ->where('source_type', 'receivings')
            ->whereNull('resolved_at')
            ->get();
    }

    public function test_an_unposted_receiving_raises_a_finding_with_the_right_variance(): void
    {
        $before = $this->openReceivingFindings()->count();

        $this->receivingWithLines(12);

        [$from, $to] = $this->window();
        app(DriftChecker::class)->run($from, $to);

        $findings = $this->openReceivingFindings();
        $this->assertGreaterThan($before, $findings->count());

        $finding = $findings->sortByDesc('detected_at')->first();
        $this->assertGreaterThanOrEqual(12.0, (float) $finding->variance);
        $this->assertStringContainsString('not in the ledger', (string) $finding->detail);
    }

    /**
     * Current receiving variance, or 0.0 when the check raised nothing.
     *
     * Measured rather than assumed: the test database carries real data, so the
     * window may already hold unposted history. Only the *change* a test causes is
     * a sound assertion.
     */
    private function receivingVariance(): float
    {
        [$from, $to] = $this->window();
        app(DriftChecker::class)->run($from, $to);

        $finding = $this->openReceivingFindings()->sortByDesc('detected_at')->first();

        return $finding ? (float) $finding->variance : 0.0;
    }

    public function test_a_receiving_posted_to_the_ledger_adds_nothing_to_the_variance(): void
    {
        $before = $this->receivingVariance();

        [$receiving, $detail] = $this->receivingWithLines(12);

        InventoryLedgerEntry::create([
            'occurred_at' => '2026-09-10 09:00:00', 'posted_at' => '2026-09-10 09:00:00',
            'direction' => 'in', 'movement_type' => 'receiving', 'lens' => 'inventory',
            'product_id' => $detail->product_id, 'quantity' => 12,
            'unit_cost' => 10.00, 'extended_value' => 120.00, 'cost_basis' => 'actual',
            'to_location_id' => $receiving->location_id,
            'source_type' => 'receivings', 'source_id' => $receiving->receiving_id,
            'source_line_id' => $detail->receiving_detail_id,
            'correlation_id' => 'receivings:' . $receiving->receiving_id,
            'posting_key' => 'receiving:' . $receiving->receiving_id
                             . ':line:' . $detail->receiving_detail_id . ':in',
        ]);

        // Source +12 and ledger +12 cancel: a correctly posted movement is invisible
        // to the drift check, which is exactly what makes an unposted one visible.
        $this->assertEqualsWithDelta($before, $this->receivingVariance(), 0.01);
    }

    public function test_an_unposted_receiving_widens_the_variance_by_its_own_quantity(): void
    {
        $before = $this->receivingVariance();

        $this->receivingWithLines(7);

        $this->assertEqualsWithDelta($before + 7, $this->receivingVariance(), 0.01,
            'Stock recorded in the source but never posted is precisely the gap this check exists to expose.');
    }

    public function test_rerunning_the_check_does_not_pile_up_duplicate_open_findings(): void
    {
        $this->receivingWithLines(12);

        [$from, $to] = $this->window();
        app(DriftChecker::class)->run($from, $to);
        $after = $this->openReceivingFindings()->count();

        app(DriftChecker::class)->run($from, $to);

        $this->assertSame($after, $this->openReceivingFindings()->count(),
            'One unresolved fact must stay one finding, however often the check runs.');
    }

    public function test_the_command_exits_non_zero_when_it_finds_drift_so_a_scheduler_notices(): void
    {
        $this->receivingWithLines(5);

        $this->artisan('ledger:drift-check', ['--from' => '2026-09-01', '--to' => '2026-09-30'])
            ->assertExitCode(1);
    }

    public function test_the_command_exits_zero_on_a_period_with_nothing_in_it(): void
    {
        $this->artisan('ledger:drift-check', ['--from' => '2019-01-01', '--to' => '2019-01-31'])
            ->assertExitCode(0);
    }

    public function test_a_resolved_finding_stops_counting_as_open(): void
    {
        $finding = LedgerDriftFinding::create([
            'check_type' => 'source_vs_ledger', 'source_type' => 'receivings',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'expected' => 10, 'actual' => 4, 'variance' => 6,
            'detail' => 'unposted receiving', 'detected_at' => '2026-09-30 02:30:00',
        ]);

        $this->assertTrue($finding->isOpen());

        $finding->update([
            'resolved_at' => Carbon::now(),
            'resolution_note' => 'Backfilled and verified against the DR.',
        ]);

        $this->assertFalse($finding->fresh()->isOpen());
    }
}
