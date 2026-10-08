<?php

namespace Tests\Feature\Sets;

use App\Models\IncidentReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IncidentSchemaTest extends SetTestCase
{
    public function test_incident_table_and_statuses_exist(): void
    {
        $this->assertTrue(Schema::hasTable('incident_reports'));

        foreach (['incident_number', 'source_type', 'reason', 'expected_zone', 'actual_zone', 'adjustment_id', 'correction_id'] as $col) {
            $this->assertTrue(Schema::hasColumn('incident_reports', $col), "incident_reports missing {$col}");
        }

        $this->assertIsInt(IncidentReport::statusId('Open'));
        $this->assertIsInt(IncidentReport::statusId('Resolved'));
        $this->assertIsInt(IncidentReport::statusId('Dismissed'));
    }

    public function test_every_relationship_column_has_a_foreign_key(): void
    {
        // Schema::getForeignKeys() rather than information_schema:
        // REFERENCED_TABLE_NAME is a MySQL extension (the standard splits the
        // information across KEY_COLUMN_USAGE and REFERENTIAL_CONSTRAINTS), and
        // DATABASE() does not exist on PostgreSQL. Laravel's API returns the
        // same facts on both engines.
        $actual = collect(Schema::getForeignKeys('incident_reports'))
            ->mapWithKeys(fn ($fk) => [$fk['columns'][0] => $fk['foreign_table']]);

        $expected = [
            'location_id'   => 'locations',
            'product_id'    => 'products',
            'batch_id'      => 'inventory_batches',
            'serial_id'     => 'item_serial',
            'reported_by'   => 'users',
            'status_id'     => 'status_lookup',
            'reviewed_by'   => 'users',
            'adjustment_id' => 'adjustments',
            'correction_id' => 'scan_corrections',

            // The sale a `return` came from, distinct from source_id.
            'related_issuance_id' => 'issuances',
        ];

        foreach ($expected as $column => $refTable) {
            $this->assertSame($refTable, $actual[$column] ?? null, "incident_reports.{$column} must reference {$refTable}");
        }

        // source_id is polymorphic (issuance|transfer|standalone) and must NOT have a FK.
        $this->assertArrayNotHasKey('source_id', $actual->all());
    }

    public function test_an_incident_can_be_created_and_read_back(): void
    {
        // Proves the model, fillable, and the ScopesToUserLocation trait don't
        // block a plain create/read outside an HTTP request.
        $loc     = $this->makeLocation();
        $product = $this->makeInstallationMaterial();
        $user    = $this->makeUserAt($loc, 'warehouse_personnel');

        $incident = IncidentReport::create([
            'incident_number' => 'INC-TEST-' . uniqid(),
            'source_type'     => 'issuance',
            'source_id'       => 1,
            'location_id'     => $loc->location_id,
            'product_id'      => $product->product_id,
            // Six types replaced eight: not_found is now `missing` carrying the
            // cause as a field rather than baking it into the type.
            'reason'          => IncidentReport::REASON_MISSING,
            'suspected_cause' => 'unknown',
            'quantity'        => 3,
            'narrative'       => 'Shelf empty.',
            'reported_by'     => $user->user_id,
            'status_id'       => IncidentReport::statusId('Open'),
        ]);

        $this->assertNotNull($incident->incident_id);
        $this->assertTrue(IncidentReport::whereKey($incident->incident_id)->open()->exists());
        // The cause that used to be encoded in the reason string must survive
        // the round trip, or collapsing eight reasons to six loses information.
        $this->assertSame('unknown', $incident->fresh()->suspected_cause);
    }

    public function test_standalone_columns_are_nullable_datetimes_and_quantity_stays_required(): void
    {
        foreach (['occurred_at', 'related_issuance_id'] as $col) {
            $this->assertTrue(Schema::hasColumn('incident_reports', $col), "incident_reports missing {$col}");
        }

        // COLUMN_NAME / IS_NULLABLE / DATA_TYPE are ANSI and present on both
        // engines; only the "which schema am I in" function differs. MySQL
        // scopes by database, PostgreSQL by schema.
        $currentSchema = DB::getDriverName() === 'pgsql' ? 'current_schema()' : 'DATABASE()';

        $columns = collect(DB::select(
            "SELECT COLUMN_NAME AS c, IS_NULLABLE AS n, DATA_TYPE AS t
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = {$currentSchema} AND TABLE_NAME = 'incident_reports'"
        ))->keyBy('c');

        $nullable = $columns->map(fn ($r) => $r->n);

        // A standalone report has no source transaction; an `other` incident has no product.
        $this->assertSame('YES', $nullable['source_id'] ?? null, 'source_id must be nullable');
        $this->assertSame('YES', $nullable['product_id'] ?? null, 'product_id must be nullable');
        $this->assertSame('YES', $nullable['occurred_at'] ?? null);
        $this->assertSame('YES', $nullable['related_issuance_id'] ?? null);

        // quantity stays NOT NULL — an `other` incident stores 0, not null.
        $this->assertSame('NO', $nullable['quantity'] ?? null, 'quantity must stay NOT NULL');

        // datetime, not timestamp: occurred_at holds user-entered past dates, so it
        // must not inherit timestamp's 1970-2038 range or auto-init/auto-update.
        //
        // Both hazards are MySQL TIMESTAMP behaviours. PostgreSQL's timestamp has
        // no such range limit and never auto-initialises, so there is no
        // equivalent distinction to assert there.
        $this->assertSame(
            DB::getDriverName() === 'pgsql' ? 'timestamp without time zone' : 'datetime',
            $columns['occurred_at']->t ?? null
        );
    }

    public function test_occurred_at_and_related_issuance_round_trip_through_the_model(): void
    {
        $loc     = $this->makeLocation();
        $product = $this->makeInstallationMaterial();
        $user    = $this->makeUserAt($loc, 'warehouse_personnel');

        $occurredAt = now()->subDay();

        $incident = IncidentReport::create([
            'incident_number' => 'INC-TEST-' . uniqid(),
            'source_type'     => 'standalone',
            'source_id'       => null,
            'location_id'     => $loc->location_id,
            'product_id'      => $product->product_id,
            'reason'          => IncidentReport::REASON_MISSING,
            'suspected_cause' => 'unknown',
            'quantity'        => 3,
            'narrative'       => 'Counted short during a walk-through.',
            'occurred_at'     => $occurredAt,
            'reported_by'     => $user->user_id,
            'status_id'       => IncidentReport::statusId('Open'),
        ]);

        $fresh = $incident->fresh();
        $this->assertNull($fresh->source_id);
        // Cast to a Carbon instance, not left as a string.
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->occurred_at);
        $this->assertSame($occurredAt->copy()->toDateString(), $fresh->occurred_at->toDateString());
    }

    public function test_photo_source_column_exists_and_is_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('incident_reports', 'photo_source'));

        // COLUMN_NAME / IS_NULLABLE / DATA_TYPE are ANSI and present on both
        // engines; only the "which schema am I in" function differs. MySQL
        // scopes by database, PostgreSQL by schema.
        $currentSchema = DB::getDriverName() === 'pgsql' ? 'current_schema()' : 'DATABASE()';

        $col = collect(DB::select(
            "SELECT COLUMN_NAME AS c, IS_NULLABLE AS n, DATA_TYPE AS t
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = {$currentSchema} AND TABLE_NAME = 'incident_reports'
               AND COLUMN_NAME = 'photo_source'"
        ))->first();

        // Nullable and never backfilled: rows predating this feature genuinely
        // do not know how their photo arrived, and null says exactly that.
        $this->assertSame('YES', $col->n);
        // information_schema.DATA_TYPE spells the same type differently per
        // engine: MySQL says 'varchar', PostgreSQL uses the ANSI name.
        $this->assertSame(
            DB::getDriverName() === 'pgsql' ? 'character varying' : 'varchar',
            $col->t
        );
    }
}
