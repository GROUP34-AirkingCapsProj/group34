<?php

namespace App\Console\Commands;

use App\Models\LedgerDriftFinding;
use App\Services\Ledger\DriftChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class LedgerDriftCheck extends Command
{
    protected $signature = 'ledger:drift-check
        {--from= : inclusive start date (default 30 days ago)}
        {--to= : inclusive end date (default today)}';

    protected $description = 'Compare the ledger against its sources and raise findings for anything unaccounted for.';

    public function handle(DriftChecker $checker): int
    {
        $from = Carbon::parse($this->option('from') ?: Carbon::now()->subDays(30)->toDateString())->startOfDay();
        $to   = Carbon::parse($this->option('to') ?: Carbon::now()->toDateString())->endOfDay();

        try {
            $findings = $checker->run($from, $to);
        } catch (Throwable $e) {
            // A check that cannot run is itself a finding. Silence must never read
            // as health, so the failure is recorded where the auditor will see it
            // rather than only in a log nobody opens.
            LedgerDriftFinding::create([
                'check_type'   => 'check_failed',
                'source_type'  => null,
                'period_start' => $from->toDateString(),
                'period_end'   => $to->toDateString(),
                'detail'       => 'The drift check failed to complete: ' . $e->getMessage(),
                'detected_at'  => Carbon::now(),
            ]);

            $this->error('Drift check failed and recorded a finding: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($findings === []) {
            $this->info("No drift between {$from->toDateString()} and {$to->toDateString()}.");

            return self::SUCCESS;
        }

        $this->warn(count($findings) . ' finding(s) recorded:');
        $this->table(
            ['Check', 'Source', 'Expected', 'Actual', 'Variance'],
            array_map(
                fn ($f) => [$f->check_type, $f->source_type ?? '—', $f->expected, $f->actual, $f->variance],
                $findings
            )
        );

        return self::FAILURE;
    }
}
