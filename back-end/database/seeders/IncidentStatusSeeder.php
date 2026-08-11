<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Every inventory status an incident can leave a unit in.
 *
 * Category `inventory` is where `In Stock` already lives and what
 * item_serial.status_id already points at, so a serial lookup can tell the
 * truth about a quarantined unit without consulting the incident.
 *
 * All five names IncidentItemService::SERIAL_STATUS_NAME can write must exist
 * here: an unseeded name throws mid-confirmation, after the stock has already
 * been deducted inside the transaction.
 *
 * Idempotent: updateOrInsert on the natural key, so re-running is safe.
 */
class IncidentStatusSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Missing', 'Defective', 'Returned', 'Quarantined', 'Scrapped'] as $name) {
            DB::table('status_lookup')->updateOrInsert(
                ['status_category' => 'inventory', 'status_name' => $name],
                ['is_active' => 1, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        // An incident whose stock is quarantined pending an inspection is NOT
        // resolved — nobody has decided anything yet. Reading "Resolved" on the
        // triage list while the units sit un-inspected in a corner overstates
        // what filing actually did.
        DB::table('status_lookup')->updateOrInsert(
            ['status_category' => 'incident', 'status_name' => 'Awaiting Inspection'],
            ['is_active' => 1, 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
