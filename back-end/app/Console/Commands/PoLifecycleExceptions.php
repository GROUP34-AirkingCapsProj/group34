<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PoLifecycleExceptions extends Command
{
    protected $signature = 'po:lifecycle-exceptions {--json : Output the complete report as JSON}';

    protected $description = 'Read-only report of historical PO lifecycle backfill exceptions';

    public function handle(): int
    {
        $rows = DB::table('po_lifecycle_exceptions')->orderBy('exception_id')->get();
        if ($this->option('json')) {
            $this->line($rows->toJson(JSON_PRETTY_PRINT));
        } else {
            $this->table(['ID', 'PO', 'Line', 'Receipt', 'Issue', 'Context'], $rows->map(fn ($row) => [
                $row->exception_id, $row->po_id, $row->po_detail_id, $row->receiving_detail_id, $row->code, $row->context,
            ])->all());
        }

        return self::SUCCESS;
    }
}
