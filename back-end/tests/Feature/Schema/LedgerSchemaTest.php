<?php

namespace Tests\Feature\Schema;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LedgerSchemaTest extends TestCase
{
    public function test_the_ledger_table_has_every_column_the_poster_writes(): void
    {
        $this->assertTrue(Schema::hasTable('inventory_ledger'));

        foreach ([
            'ledger_id', 'occurred_at', 'posted_at', 'direction', 'movement_type', 'lens',
            'product_id', 'batch_id', 'serial_number', 'quantity', 'unit_cost',
            'extended_value', 'cost_basis', 'from_location_id', 'to_location_id',
            'source_type', 'source_id', 'source_line_id', 'correlation_id',
            'actor_user_id', 'reverses_ledger_id', 'origin', 'posting_key', 'notes',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('inventory_ledger', $column),
                "inventory_ledger is missing {$column}"
            );
        }
    }

    /**
     * Asks information_schema directly rather than using Schema::getForeignKeys(),
     * which reads information_schema.key_column_usage. A full scan of that view has
     * to open every tablespace on the server, and on the local XAMPP MariaDB 10.4
     * one of the legacy databases has corrupt tablespaces, so the scan segfaults
     * mysqld outright -- for any table, including tables with no FKs at all.
     * table_constraints answers the same question without the server-wide scan.
     */
    public function test_the_ledger_declares_no_foreign_keys_so_audit_rows_outlive_their_subjects(): void
    {
        $connection = Schema::getConnection();

        $constraints = $connection->select(
            'select constraint_name from information_schema.table_constraints
              where table_schema = ? and table_name = ? and constraint_type = ?',
            [
                $connection->getDriverName() === 'pgsql' ? 'public' : $connection->getDatabaseName(),
                'inventory_ledger',
                'FOREIGN KEY',
            ]
        );

        $this->assertSame(
            [],
            $constraints,
            'inventory_ledger must have no FK constraints: a cascade delete would erase audit evidence.'
        );
    }

    public function test_drift_findings_and_batch_cost_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('ledger_drift_findings'));

        foreach ([
            'finding_id', 'check_type', 'source_type', 'period_start', 'period_end',
            'scope', 'expected', 'actual', 'variance', 'detected_at',
            'resolved_at', 'resolved_by', 'resolution_note',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('ledger_drift_findings', $column),
                "ledger_drift_findings is missing {$column}"
            );
        }

        $this->assertTrue(Schema::hasColumn('inventory_batches', 'unit_cost'));
        $this->assertTrue(Schema::hasColumn('inventory_batches', 'cost_basis'));
    }
}
