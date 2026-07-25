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
        $actual = collect(DB::select(
            "SELECT COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS rt
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'incident_reports'
               AND REFERENCED_TABLE_NAME IS NOT NULL"
        ))->mapWithKeys(fn ($r) => [$r->c => $r->rt]);

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
        ];

        foreach ($expected as $column => $refTable) {
            $this->assertSame($refTable, $actual[$column] ?? null, "incident_reports.{$column} must reference {$refTable}");
        }

        // source_id is polymorphic (issuance|transfer) and must NOT have a FK.
        $this->assertArrayNotHasKey('source_id', $actual->all());
    }

    public function test_an_incident_can_be_created_and_read_back(): void
    {
        // Proves the model, fillable, and the ScopesToUserLocation trait don't
        // block a plain create/read outside an HTTP request.
        $loc     = $this->makeLocation();
        $product = $this->makeConsumable();
        $user    = $this->makeUserAt($loc, 'warehouse_personnel');

        $incident = IncidentReport::create([
            'incident_number' => 'INC-TEST-' . uniqid(),
            'source_type'     => 'issuance',
            'source_id'       => 1,
            'location_id'     => $loc->location_id,
            'product_id'      => $product->product_id,
            'reason'          => IncidentReport::REASON_NOT_FOUND,
            'quantity'        => 3,
            'narrative'       => 'Shelf empty.',
            'reported_by'     => $user->user_id,
            'status_id'       => IncidentReport::statusId('Open'),
        ]);

        $this->assertNotNull($incident->incident_id);
        $this->assertTrue(IncidentReport::whereKey($incident->incident_id)->open()->exists());
    }
}
