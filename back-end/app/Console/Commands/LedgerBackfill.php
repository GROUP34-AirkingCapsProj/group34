<?php

namespace App\Console\Commands;

use App\Services\Ledger\LedgerBackfiller;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class LedgerBackfill extends Command
{
    protected $signature = 'ledger:backfill
        {--source=all : receivings|issuances|transfers|adjustments|all}
        {--from= : inclusive start date (default 2000-01-01)}
        {--to= : inclusive end date (default today)}
        {--chunk=500 : rows per chunk}
        {--dry-run : report what would be written without writing it}';

    protected $description = 'Reconstruct ledger rows for movements that predate the ledger.';

    public function handle(LedgerBackfiller $backfiller): int
    {
        $from   = Carbon::parse($this->option('from') ?: '2000-01-01')->startOfDay();
        $to     = Carbon::parse($this->option('to') ?: Carbon::now()->toDateString())->endOfDay();
        $chunk  = max(50, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $source = $this->option('source');

        $sources = $source === 'all' ? LedgerBackfiller::SOURCES : [$source];

        foreach ($sources as $one) {
            if (! in_array($one, LedgerBackfiller::SOURCES, true)) {
                $this->error("Unknown source '{$one}'. Available: " . implode(', ', LedgerBackfiller::SOURCES));

                return self::FAILURE;
            }
        }

        if ($dryRun) {
            $this->warn('Dry run — nothing will be written.');
        }

        // Receivings first: they establish the batches and costs every out row reads.
        $ordered = array_values(array_intersect(LedgerBackfiller::SOURCES, $sources));

        $rows = [];
        foreach ($ordered as $one) {
            $method = 'backfill' . ucfirst($one);
            $this->info("Backfilling {$one}…");
            $stats = $backfiller->{$method}($from, $to, $chunk, $dryRun);

            $bases = [];
            foreach ($stats['by_basis'] as $basis => $count) {
                $bases[] = "{$basis}: {$count}";
            }

            $rows[] = [$one, $stats['written'], $stats['skipped'], implode(', ', $bases) ?: '—'];
        }

        $this->table(['Source', 'Written', 'Skipped', 'Cost basis'], $rows);

        $this->line('');
        $this->info('Rows are marked origin=reconstructed. Run `php artisan ledger:drift-check` next '
            . 'to see what the reconstruction could not account for.');

        return self::SUCCESS;
    }
}
